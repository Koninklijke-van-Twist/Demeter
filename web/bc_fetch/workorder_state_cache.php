<?php

/**
 * Persistente cache voor incrementeel laden van werkorders per bedrijf/kostenplaats.
 *
 * Geen TTL: open rijen blijven bewaard tot ze afgesloten zijn en daarna permanent in de cache.
 */

require_once __DIR__ . '/cost_center.php';

const DEMETER_WORKORDER_STATE_CACHE_VERSION = 6;
/** Aantal opeenvolgende lege weken voordat historisch laden stopt (~12 maanden). */
const DEMETER_MONTH_SCAN_EMPTY_STOP_COUNT = 52;
/** Open stale werkorders volledig verversen na dit aantal dagen (niet in huidige ProjectPosten). */
const DEMETER_WORKORDER_OPEN_FULL_REFRESH_MAX_AGE_DAYS = 14;
/** Huidige ISO-week overslaan bij herladen als scan jonger is dan dit aantal uren. */
const DEMETER_WORKORDER_CURRENT_WEEK_SKIP_MAX_AGE_HOURS = 2;
/** Per Job_No: bij hoogstens dit aantal paren eerst gerichte AND-filter i.p.v. volledige job-batch. */
const DEMETER_WORKORDER_PAIR_FIRST_MAX_PAIRS_PER_JOB = 3;
/** Aantal Job_No filters per OData-call bij batch ophalen. */
const DEMETER_WORKORDER_JOB_NO_BATCH_SIZE = 15;

/**
 * Standaard load_session metadata (status-check dedup per Toon-sessie).
 */
function demeter_workorder_load_session_defaults(): array
{
    return [
        'session_id' => '',
        'status_checked_pair_keys' => [],
    ];
}

/**
 * Normaliseert load_session uit de werkorder-state cache.
 */
function demeter_workorder_state_normalize_load_session(?array $loadSession): array
{
    $normalized = demeter_workorder_load_session_defaults();
    if (!is_array($loadSession)) {
        return $normalized;
    }

    $normalized['session_id'] = trim((string) ($loadSession['session_id'] ?? ''));
    $pairKeys = $loadSession['status_checked_pair_keys'] ?? null;
    if (is_array($pairKeys)) {
        foreach ($pairKeys as $pairKey => $checked) {
            if (!is_string($pairKey) || trim($pairKey) === '') {
                continue;
            }

            if ($checked) {
                $normalized['status_checked_pair_keys'][trim($pairKey)] = true;
            }
        }
    }

    return $normalized;
}

/**
 * Bepaalt of een pair al een lichte status-check kreeg in deze load-sessie.
 */
function demeter_workorder_state_is_pair_status_checked_this_session(?array $cachedState, string $loadSessionId, string $pairKey): bool
{
    $loadSessionId = trim($loadSessionId);
    $pairKey = trim($pairKey);
    if ($loadSessionId === '' || $pairKey === '') {
        return false;
    }

    $loadSession = is_array($cachedState)
        ? demeter_workorder_state_normalize_load_session($cachedState['load_session'] ?? null)
        : demeter_workorder_load_session_defaults();

    if ($loadSession['session_id'] !== $loadSessionId) {
        return false;
    }

    return !empty($loadSession['status_checked_pair_keys'][$pairKey]);
}

/**
 * Registreert pair-keys waarvoor een status-check is uitgevoerd in deze sessie.
 *
 * @param list<string> $pairKeys
 */
function demeter_workorder_state_record_status_checked_pairs(array $loadSession, string $loadSessionId, array $pairKeys): array
{
    $loadSessionId = trim($loadSessionId);
    if ($loadSessionId === '' || $pairKeys === []) {
        return demeter_workorder_state_normalize_load_session($loadSession);
    }

    $normalized = demeter_workorder_state_normalize_load_session($loadSession);
    if ($normalized['session_id'] !== $loadSessionId) {
        $normalized = demeter_workorder_load_session_defaults();
        $normalized['session_id'] = $loadSessionId;
    }

    foreach ($pairKeys as $pairKey) {
        if (!is_string($pairKey)) {
            continue;
        }

        $trimmed = trim($pairKey);
        if ($trimmed !== '') {
            $normalized['status_checked_pair_keys'][$trimmed] = true;
        }
    }

    return $normalized;
}

/**
 * Bepaalt of een werkorderstatus als afgesloten telt.
 */
function demeter_workorder_status_is_closed(string $status): bool
{
    $normalized = strtolower(trim($status));
    $aliases = [
        'afgesloten' => 'closed',
        'geannuleerd' => 'cancelled',
        'uitgevoerd' => 'completed',
    ];

    if (isset($aliases[$normalized])) {
        $normalized = $aliases[$normalized];
    }

    return in_array($normalized, ['closed', 'cancelled', 'completed'], true);
}

/**
 * Berekent de leeftijd van een cache-entry in dagen (op basis van updated_at).
 */
function demeter_workorder_cache_entry_age_days(array $entry): ?float
{
    $updatedAt = trim((string) ($entry['updated_at'] ?? ''));
    if ($updatedAt === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $updatedAt);
    if (!$parsed instanceof DateTimeImmutable) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $updatedAt);
    }
    if (!$parsed instanceof DateTimeImmutable) {
        return null;
    }

    $seconds = time() - $parsed->getTimestamp();

    return max(0.0, $seconds / 86400);
}

/**
 * Bepaalt of een open cache-entry een volledige BC-refresh nodig heeft op basis van leeftijd.
 */
function demeter_workorder_cache_entry_needs_full_refresh_by_age(array $entry): bool
{
    if (!empty($entry['is_closed'])) {
        return false;
    }

    $ageDays = demeter_workorder_cache_entry_age_days($entry);
    if ($ageDays === null) {
        return true;
    }

    return $ageDays >= (float) DEMETER_WORKORDER_OPEN_FULL_REFRESH_MAX_AGE_DAYS;
}

/**
 * Bouwt een stabiele pair-key voor job + taak.
 */
function demeter_workorder_pair_key(string $jobNo, string $jobTaskNo): string
{
    return strtolower(trim($jobNo)) . '|' . strtolower(trim($jobTaskNo));
}

/**
 * Bepaalt een stabiele rij-key voor de frontend.
 */
function demeter_workorder_row_key(string $jobNo, string $workorderSourceKey): string
{
    return strtolower(trim($jobNo)) . '|' . strtolower(trim($workorderSourceKey));
}

/**
 * Pad naar de cache-directory.
 */
function demeter_workorder_state_cache_directory(): string
{
    return __DIR__ . '/../cache/workorder_state';
}

/**
 * Bepaalt het cachebestand voor bedrijf + kostenplaats.
 */
function demeter_workorder_state_cache_path(string $company, string $costCenter): string
{
    $parts = [
        trim($company),
        bc_fetch_normalize_cost_center($costCenter),
    ];
    $hash = hash('sha256', implode("\0", $parts));

    return demeter_workorder_state_cache_directory() . '/' . $hash . '.json';
}

/**
 * Normaliseert month_scan metadata (period keys zijn ISO-weken: YYYY-Www).
 */
function demeter_workorder_month_scan_defaults(): array
{
    return [
        'consecutive_empty' => 0,
        'stop_before_month' => null,
        'chunk_unit' => 'week',
        'months' => [],
    ];
}

/**
 * Valideert een ISO-weekkey (YYYY-Www).
 */
function demeter_is_valid_iso_year_week(string $yearWeek): bool
{
    return demeter_parse_iso_year_week($yearWeek) !== null;
}

/**
 * @return array{year: int, week: int}|null
 */
function demeter_parse_iso_year_week(string $yearWeek): ?array
{
    if (!preg_match('/^(\d{4})-W(\d{2})$/', trim($yearWeek), $matches)) {
        return null;
    }

    $year = (int) $matches[1];
    $week = (int) $matches[2];
    if ($week < 1 || $week > 53) {
        return null;
    }

    return ['year' => $year, 'week' => $week];
}

function demeter_format_iso_year_week(int $year, int $week): string
{
    return sprintf('%04d-W%02d', $year, $week);
}

function demeter_iso_year_week_from_date(DateTimeImmutable $date): string
{
    return demeter_format_iso_year_week((int) $date->format('o'), (int) $date->format('W'));
}

function demeter_current_iso_year_week(): string
{
    return demeter_iso_year_week_from_date(new DateTimeImmutable('today'));
}

/**
 * @return array{from: DateTimeImmutable, to: DateTimeImmutable, year_week: string}
 */
function demeter_week_date_range(string $yearWeek, bool $partialToToday = false): array
{
    $parsed = demeter_parse_iso_year_week($yearWeek);
    if ($parsed === null) {
        throw new InvalidArgumentException('Ongeldige week: ' . $yearWeek);
    }

    $from = (new DateTimeImmutable())->setISODate($parsed['year'], $parsed['week'], 1)->setTime(0, 0, 0);
    $to = $from->modify('+7 days');
    $normalizedYearWeek = demeter_format_iso_year_week($parsed['year'], $parsed['week']);

    if ($partialToToday && $normalizedYearWeek === demeter_current_iso_year_week()) {
        $to = (new DateTimeImmutable('today'))->modify('+1 day');
    }

    return [
        'from' => $from,
        'to' => $to,
        'year_week' => $normalizedYearWeek,
    ];
}

/**
 * Berekent de vorige ISO-week (YYYY-Www).
 */
function demeter_previous_iso_year_week(string $yearWeek): ?string
{
    $parsed = demeter_parse_iso_year_week($yearWeek);
    if ($parsed === null) {
        return null;
    }

    $from = (new DateTimeImmutable())->setISODate($parsed['year'], $parsed['week'], 1)->setTime(0, 0, 0);

    return demeter_iso_year_week_from_date($from->modify('-7 days'));
}

/**
 * Herken oude maand-keys (YYYY-MM) t.o.v. ISO-weekkeys (YYYY-Www).
 */
function demeter_is_legacy_calendar_month_key(string $periodKey): bool
{
    $trimmed = trim($periodKey);
    if ($trimmed === '' || demeter_is_valid_iso_year_week($trimmed)) {
        return false;
    }

    return preg_match('/^\d{4}-\d{2}$/', $trimmed) === 1;
}

/**
 * Controleert of scan-metadata nog op maand-chunks is gebaseerd.
 */
function demeter_workorder_month_scan_uses_legacy_month_keys(array $monthScan): bool
{
    $chunkUnit = trim((string) ($monthScan['chunk_unit'] ?? ''));
    $periods = is_array($monthScan['months'] ?? null) ? $monthScan['months'] : [];

    if ($chunkUnit !== 'week' && $periods !== []) {
        return true;
    }

    $stopBefore = trim((string) ($monthScan['stop_before_month'] ?? ''));
    if ($stopBefore !== '' && demeter_is_legacy_calendar_month_key($stopBefore)) {
        return true;
    }

    foreach (array_keys($periods) as $periodKey) {
        if (demeter_is_legacy_calendar_month_key((string) $periodKey)) {
            return true;
        }
    }

    return false;
}

/**
 * Verwijdert werkorder-state en display-cache voor bedrijf/kostenplaats.
 */
function demeter_workorder_state_cache_purge(string $company, string $costCenter): void
{
    $paths = [
        demeter_workorder_state_cache_path($company, $costCenter),
        demeter_workorder_state_cache_display_rows_path($company, $costCenter),
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * Leest de werkorder-state cache.
 *
 * @param bool|null $purgedLegacy Wordt true als oude maand-keys zijn gedetecteerd en cache is gewist.
 */
function demeter_workorder_state_cache_load(string $company, string $costCenter, ?bool &$purgedLegacy = null): ?array
{
    if ($purgedLegacy !== null) {
        $purgedLegacy = false;
    }
    $path = demeter_workorder_state_cache_path($company, $costCenter);
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if ((int) ($decoded['version'] ?? 0) !== DEMETER_WORKORDER_STATE_CACHE_VERSION) {
        return null;
    }

    if (trim((string) ($decoded['company'] ?? '')) !== trim($company)) {
        return null;
    }

    if (bc_fetch_normalize_cost_center((string) ($decoded['cost_center'] ?? '')) !== bc_fetch_normalize_cost_center($costCenter)) {
        return null;
    }

    if (!is_array($decoded['workorders'] ?? null)) {
        $decoded['workorders'] = [];
    }

    $monthScan = $decoded['month_scan'] ?? null;
    if (!is_array($monthScan)) {
        $monthScan = demeter_workorder_month_scan_defaults();
    }
    if (!is_array($monthScan['months'] ?? null)) {
        $monthScan['months'] = [];
    }
    $decoded['month_scan'] = $monthScan;

    if (!is_array($decoded['display_rows'] ?? null)) {
        $decoded['display_rows'] = [];
    }

    $decoded['load_session'] = demeter_workorder_state_normalize_load_session($decoded['load_session'] ?? null);

    if (demeter_workorder_month_scan_uses_legacy_month_keys($monthScan)) {
        demeter_workorder_state_cache_purge($company, $costCenter);
        if ($purgedLegacy !== null) {
            $purgedLegacy = true;
        }

        return null;
    }

    return $decoded;
}

/**
 * Slaat de werkorder-state cache op.
 */
function demeter_workorder_state_cache_save(
    string $company,
    string $costCenter,
    array $workordersByPairKey,
    array $monthScan,
    ?array $loadSession = null
): bool {
    $directory = demeter_workorder_state_cache_directory();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $payload = [
        'version' => DEMETER_WORKORDER_STATE_CACHE_VERSION,
        'company' => trim($company),
        'cost_center' => bc_fetch_normalize_cost_center($costCenter),
        'updated_at' => gmdate('c'),
        'workorders' => $workordersByPairKey,
        'month_scan' => $monthScan,
    ];

    if (is_array($loadSession)) {
        $payload['load_session'] = demeter_workorder_state_normalize_load_session($loadSession);
    }

    $json = demeter_workorder_state_cache_json_encode($payload);
    if (!is_string($json)) {
        return false;
    }

    $path = demeter_workorder_state_cache_path($company, $costCenter);

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * JSON-encode met UTF-8 fallback voor cache en API-responses.
 *
 * @return string|false
 */
function demeter_workorder_state_cache_json_encode(array $payload)
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    return json_encode($payload, $flags);
}

/**
 * Pad naar het aparte display_rows cachebestand.
 */
function demeter_workorder_state_cache_display_rows_path(string $company, string $costCenter): string
{
    return demeter_workorder_state_cache_path($company, $costCenter) . '.display.json';
}

/**
 * Leest opgeslagen UI-rijen voor snelle eerste paint.
 *
 * @return array<string, array>
 */
function demeter_workorder_state_cache_load_display_rows(string $company, string $costCenter): array
{
    $path = demeter_workorder_state_cache_display_rows_path($company, $costCenter);
    if (is_file($path) && is_readable($path)) {
        $raw = file_get_contents($path);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $cachedState = demeter_workorder_state_cache_load($company, $costCenter);
    if (is_array($cachedState) && is_array($cachedState['display_rows'] ?? null) && $cachedState['display_rows'] !== []) {
        $legacyRows = $cachedState['display_rows'];
        demeter_workorder_state_cache_save_display_rows($company, $costCenter, $legacyRows);

        return $legacyRows;
    }

    return [];
}

/**
 * Slaat UI-rijen op in een apart bestand (los van werkorder-state).
 *
 * @param array<string, array> $displayRowsByKey
 */
function demeter_workorder_state_cache_save_display_rows(string $company, string $costCenter, array $displayRowsByKey): bool
{
    $directory = demeter_workorder_state_cache_directory();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $json = demeter_workorder_state_cache_json_encode($displayRowsByKey);
    if (!is_string($json)) {
        return false;
    }

    $path = demeter_workorder_state_cache_display_rows_path($company, $costCenter);

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Werkt cache-entry bij vanuit een opgehaalde werkorderrij.
 */
function demeter_workorder_state_cache_entry_from_row(array $workorderRow, string $financeKey, bool $seenInProjectPosten): array
{
    $jobNo = trim((string) ($workorderRow['Job_No'] ?? ''));
    $jobTaskNo = trim((string) ($workorderRow['Job_Task_No'] ?? ''));
    $status = trim((string) ($workorderRow['Status'] ?? ''));

    return [
        'job_no' => $jobNo,
        'job_task_no' => $jobTaskNo,
        'finance_key' => strtolower(trim($financeKey)),
        'is_closed' => demeter_workorder_status_is_closed($status),
        'status' => $status,
        'row' => $workorderRow,
        'last_seen_in_posten' => $seenInProjectPosten ? gmdate('Y-m-d') : null,
        'updated_at' => gmdate('c'),
    ];
}

/**
 * Berekent de vorige kalendermaand (Y-m).
 */
function demeter_previous_year_month(string $yearMonth): ?string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m', trim($yearMonth));
    if (!$parsed instanceof DateTimeImmutable) {
        return null;
    }

    return $parsed->modify('-1 month')->format('Y-m');
}

/**
 * Bepaalt of een maand overgeslagen kan worden op basis van scan-cache.
 *
 * Lege maanden worden niet permanent overgeslagen: alleen maanden vóór stop_before_month
 * (na opeenvolgende lege maanden, zie DEMETER_MONTH_SCAN_EMPTY_STOP_COUNT) en maanden met uitsluitend gesloten cache-rijen.
 */
function demeter_month_scan_can_skip(string $yearMonth, array $monthScan): bool
{
    $stopBefore = trim((string) ($monthScan['stop_before_month'] ?? ''));
    if ($stopBefore !== '' && $yearMonth < $stopBefore) {
        return true;
    }

    $monthMeta = $monthScan['months'][$yearMonth] ?? null;
    if (!is_array($monthMeta)) {
        return false;
    }

    return !empty($monthMeta['only_closed_cached']) && !empty($monthMeta['scanned_at']);
}

/**
 * Bepaalt of een week-scan recent genoeg is (op basis van scanned_at).
 */
function demeter_month_scan_week_scanned_within_hours(string $yearWeek, array $monthScan, int $maxAgeHours): bool
{
    if ($maxAgeHours <= 0) {
        return false;
    }

    $monthMeta = $monthScan['months'][$yearWeek] ?? null;
    if (!is_array($monthMeta)) {
        return false;
    }

    $scannedAt = trim((string) ($monthMeta['scanned_at'] ?? ''));
    if ($scannedAt === '') {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $scannedAt);
    if (!$parsed instanceof DateTimeImmutable) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $scannedAt);
    }
    if (!$parsed instanceof DateTimeImmutable) {
        return false;
    }

    $ageSeconds = time() - $parsed->getTimestamp();

    return $ageSeconds >= 0 && $ageSeconds <= ($maxAgeHours * 3600);
}

/**
 * Slaat een eerder gescande week over bij herladen (display-cache + scan-metadata).
 *
 * Open werkorders blijven in cache; kosten/status/facturen voor de huidige week worden via
 * lightweight refresh of verse scan bijgewerkt.
 *
 * @param array<string, array> $displayRowsByKey
 */
function demeter_month_scan_can_skip_reload(
    string $yearWeek,
    array $monthScan,
    string $currentWeek,
    bool $forceFull,
    array $displayRowsByKey = []
): bool {
    if ($forceFull) {
        return false;
    }

    if (demeter_month_scan_can_skip($yearWeek, $monthScan)) {
        return true;
    }

    $monthMeta = $monthScan['months'][$yearWeek] ?? null;
    if (!is_array($monthMeta) || trim((string) ($monthMeta['scanned_at'] ?? '')) === '') {
        return false;
    }

    if ($yearWeek === $currentWeek) {
        return demeter_month_scan_week_scanned_within_hours(
            $yearWeek,
            $monthScan,
            DEMETER_WORKORDER_CURRENT_WEEK_SKIP_MAX_AGE_HOURS
        );
    }

    if (!empty($monthMeta['empty'])) {
        return true;
    }

    $rowKeys = demeter_month_scan_expected_row_keys($monthScan, $yearWeek);
    if ($rowKeys === []) {
        return false;
    }

    foreach ($rowKeys as $rowKey) {
        if (!isset($displayRowsByKey[$rowKey])) {
            return false;
        }
    }

    return true;
}

/**
 * Bepaalt of een week een lichte refresh krijgt (ProjectPosten + status/facturen, gecachte WO-metadata).
 */
function demeter_month_scan_should_use_lightweight_refresh(
    string $yearWeek,
    array $monthScan,
    string $currentWeek,
    bool $forceFull
): bool {
    if ($forceFull) {
        return false;
    }

    $monthMeta = $monthScan['months'][$yearWeek] ?? null;
    if (!is_array($monthMeta) || trim((string) ($monthMeta['scanned_at'] ?? '')) === '') {
        return $yearWeek === $currentWeek;
    }

    return true;
}

/**
 * Telt ISO-weken vanaf startWeek terug tot (exclusief) stopBeforeWeek.
 */
function demeter_count_iso_weeks_in_load_range(string $startWeek, ?string $stopBeforeWeek): int
{
    if (!demeter_is_valid_iso_year_week($startWeek)) {
        return 0;
    }

    $count = 0;
    $week = $startWeek;
    $safety = 0;

    while ($week !== null && $safety < 400) {
        if ($stopBeforeWeek !== null && $stopBeforeWeek !== '' && $week < $stopBeforeWeek) {
            break;
        }

        $count++;
        $week = demeter_previous_iso_year_week($week);
        $safety++;
    }

    return $count;
}

/**
 * Berekent het totaal aantal te laden weken wanneer stop_before_month bekend is.
 */
function demeter_history_weeks_total_for_scan(array $monthScan, string $currentWeek): ?int
{
    $stopBefore = trim((string) ($monthScan['stop_before_month'] ?? ''));
    if ($stopBefore === '') {
        return null;
    }

    $total = demeter_count_iso_weeks_in_load_range($currentWeek, $stopBefore);

    return $total > 0 ? $total : null;
}

/**
 * Bepaalt of async laden verder moet gaan.
 */
function demeter_month_scan_should_continue(array $monthScan, ?string $nextPeriod): bool
{
    if (!is_string($nextPeriod) || !demeter_is_valid_iso_year_week($nextPeriod)) {
        return false;
    }

    $stopBefore = trim((string) ($monthScan['stop_before_month'] ?? ''));
    if ($stopBefore !== '' && $nextPeriod < $stopBefore) {
        return false;
    }

    if ((int) ($monthScan['consecutive_empty'] ?? 0) >= DEMETER_MONTH_SCAN_EMPTY_STOP_COUNT) {
        return false;
    }

    return true;
}

/**
 * Werkt month_scan bij na het laden van een maand.
 *
 * @param list<string> $rowKeys
 * @param bool $hasMonthRows Of deze maand rijen opleverde voor de gekozen kostenplaats
 */
function demeter_month_scan_update_after_load(string $yearMonth, bool $hasMonthRows, bool $onlyClosedCached, array $rowKeys, array $monthScan): array
{
    if (!is_array($monthScan['months'] ?? null)) {
        $monthScan['months'] = [];
    }

    $empty = !$hasMonthRows;
    if ($empty) {
        $monthScan['consecutive_empty'] = (int) ($monthScan['consecutive_empty'] ?? 0) + 1;
    } else {
        $monthScan['consecutive_empty'] = 0;
    }

    if ((int) ($monthScan['consecutive_empty'] ?? 0) >= DEMETER_MONTH_SCAN_EMPTY_STOP_COUNT) {
        $monthScan['stop_before_month'] = $yearMonth;
    }

    $monthScan['months'][$yearMonth] = [
        'scanned_at' => gmdate('c'),
        'has_projectposten' => $hasMonthRows,
        'empty' => $empty,
        'only_closed_cached' => $onlyClosedCached,
        'row_keys' => array_values(array_unique(array_filter($rowKeys, static function (string $key): bool {
            return trim($key) !== '';
        }))),
    ];

    return $monthScan;
}

/**
 * Bouwt row_keys vanuit finance pair keys.
 *
 * @param array<string, string> $financeKeyByPair
 * @param list<array{job_no: string, job_task_no: string}> $pairs
 * @return list<string>
 */
function demeter_row_keys_from_pairs(array $pairs, array $financeKeyByPair): array
{
    $rowKeys = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $pairKey = demeter_workorder_pair_key($jobNo, $jobTaskNo);
        $financeKey = $financeKeyByPair[$pairKey] ?? strtolower($jobTaskNo);
        $rowKeys[] = demeter_workorder_row_key($jobNo, $financeKey);
    }

    return array_values(array_unique($rowKeys));
}

/**
 * Bepaalt row_keys voor een maand uit scan-cache.
 *
 * @return list<string>
 */
function demeter_month_scan_expected_row_keys(array $monthScan, string $yearMonth): array
{
    $monthMeta = $monthScan['months'][$yearMonth] ?? null;
    if (!is_array($monthMeta)) {
        return [];
    }

    $rowKeys = $monthMeta['row_keys'] ?? null;
    if (!is_array($rowKeys)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map('strval', $rowKeys), static function (string $key): bool {
        return trim($key) !== '';
    })));
}

/**
 * Bouwt overview-data voor de eerste paint vanuit de werkorder-state cache.
 */
function demeter_build_overview_from_workorder_cache(array $cachedState): array
{
    $workorders = [];
    $financeKeyByPair = [];

    foreach ($cachedState['workorders'] as $pairKey => $entry) {
        if (!is_string($pairKey) || !is_array($entry) || !is_array($entry['row'] ?? null)) {
            continue;
        }

        $workorders[] = $entry['row'];
        $financeKey = trim((string) ($entry['finance_key'] ?? ''));
        if ($financeKey !== '') {
            $financeKeyByPair[$pairKey] = $financeKey;
        }
    }

    return [
        'workorders' => $workorders,
        'project_totals_by_job' => [],
        'invoice_details_by_id' => [],
        'project_invoice_ids_by_job' => [],
        'project_invoiced_total_by_job' => [],
        'workorder_totals_by_number' => [],
        'workorder_totals_by_project_and_number' => [],
        'projectposten_rows_by_project' => [],
        'projectposten_rows_by_project_and_workorder' => [],
        'finance_key_by_pair' => $financeKeyByPair,
        'load_meta' => [],
    ];
}

/**
 * Bepaalt welke rijen nog ververst moeten worden tijdens async laden.
 *
 * Alleen open, uit cache geladen rijen wachten op een AJAX update-stap.
 *
 * @return list<string>
 */
function demeter_pending_refresh_row_keys_from_cache(?array $cachedState, bool $forceFull): array
{
    if ($cachedState === null || $forceFull) {
        return [];
    }

    $keys = [];

    foreach ($cachedState['workorders'] as $entry) {
        if (!is_array($entry) || !empty($entry['is_closed'])) {
            continue;
        }

        $jobNo = trim((string) ($entry['job_no'] ?? ''));
        if ($jobNo === '') {
            continue;
        }

        $financeKey = trim((string) ($entry['finance_key'] ?? ''));
        $jobTaskNo = trim((string) ($entry['job_task_no'] ?? ''));
        $keys[] = demeter_workorder_row_key($jobNo, $financeKey !== '' ? $financeKey : $jobTaskNo);
    }

    return array_values(array_unique(array_filter(array_map('strval', $keys), static function (string $key): bool {
        return trim($key) !== '';
    })));
}
