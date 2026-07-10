<?php

/**
 * Persistente cache voor incrementeel laden van werkorders per bedrijf/kostenplaats.
 *
 * Geen TTL: open rijen blijven bewaard tot ze afgesloten zijn en daarna permanent in de cache.
 */

require_once __DIR__ . '/cost_center.php';

const DEMETER_WORKORDER_STATE_CACHE_VERSION = 5;
const DEMETER_MONTH_SCAN_EMPTY_STOP_COUNT = 12;

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
 * Normaliseert month_scan metadata.
 */
function demeter_workorder_month_scan_defaults(): array
{
    return [
        'consecutive_empty' => 0,
        'stop_before_month' => null,
        'months' => [],
    ];
}

/**
 * Leest de werkorder-state cache.
 */
function demeter_workorder_state_cache_load(string $company, string $costCenter): ?array
{
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

    return $decoded;
}

/**
 * Slaat de werkorder-state cache op.
 */
function demeter_workorder_state_cache_save(
    string $company,
    string $costCenter,
    array $workordersByPairKey,
    array $monthScan
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
 * Bepaalt of async laden verder moet gaan.
 */
function demeter_month_scan_should_continue(array $monthScan, ?string $nextMonth): bool
{
    if (!is_string($nextMonth) || !preg_match('/^\d{4}-\d{2}$/', $nextMonth)) {
        return false;
    }

    $stopBefore = trim((string) ($monthScan['stop_before_month'] ?? ''));
    if ($stopBefore !== '' && $nextMonth < $stopBefore) {
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
