<?php
/**
 * Page-open BC-delta voor de schermcache (week-architectuur): haalt op wat sinds de laatste sync in BC is
 * veranderd en markeert de geraakte weken (ook al gesloten weken) als 'opnieuw laden'. De bestaande
 * hervat-logica (force_full + resume) leest die weken daarna opnieuw; statuswijzigingen van werkorders die
 * al in beeld staan worden direct in de display-rijen bijgewerkt.
 *
 * Bronnen (alleen lezen, een paar gefilterde calls, harde timeout per request, geen retries):
 * - ProjectPosten met Entry_No > laatst gezien (nieuwe/achteraf gedateerde posten);
 * - ChangeLogEntries van tabel 11332939 'Work Order' met Entry_No > laatst gezien (status, startdatum,
 *   documentstatus, nieuwe werkorders). Werkorders heeft geen SystemModifiedAt in OData;
 * - Werkorders met Created_Date_Time > laatste sync (vangnet voor nieuwe werkorders).
 * Gaat er iets mis (timeout, 409, netwerk): niets wijzigen, checkpoint niet verzetten, volgende page-open
 * opnieuw (zelfde patroon als demeter_store_delta_sync).
 *
 * Geen polling: alleen vanuit de pagina na het openen, en alleen als de laatste sync > 180 s oud is.
 */

require_once __DIR__ . '/workorder_delta_state.php';
require_once __DIR__ . '/store.php';

if (!defined('DEMETER_WORKORDER_DELTA_MAX_AGE_SECONDS')) {
    define('DEMETER_WORKORDER_DELTA_MAX_AGE_SECONDS', defined('DEMETER_WORKORDER_MAX_AGE_SECONDS') ? (int) DEMETER_WORKORDER_MAX_AGE_SECONDS : 180);
}
if (!defined('DEMETER_WORKORDER_DELTA_REQUEST_TIMEOUT')) {
    define('DEMETER_WORKORDER_DELTA_REQUEST_TIMEOUT', 15);
}
if (!defined('DEMETER_WORKORDER_DELTA_MAX_CHANGED_WORKORDERS')) {
    // Per sync hooguit zoveel gewijzigde werkorders nalopen (40 per call); de rest volgt bij de volgende open.
    define('DEMETER_WORKORDER_DELTA_MAX_CHANGED_WORKORDERS', 400);
}
if (!defined('DEMETER_WORKORDER_DELTA_NO_CHUNK')) {
    // Werkorders per No-call ('No eq .. or ..'); de latency per BC-call domineert, niet de lengte.
    define('DEMETER_WORKORDER_DELTA_NO_CHUNK', 40);
}
if (!defined('DEMETER_WORKORDER_DELTA_MAX_ROWS')) {
    define('DEMETER_WORKORDER_DELTA_MAX_ROWS', 5000);
}
if (!defined('DEMETER_WORKORDER_DELTA_BACKFILL_MAX_SECONDS')) {
    // Terugkijken bij (her)initialisatie: vanaf de oudste weekscan, maar nooit verder terug dan dit.
    define('DEMETER_WORKORDER_DELTA_BACKFILL_MAX_SECONDS', 48 * 3600);
}
if (!defined('DEMETER_WORKORDER_DELTA_BACKFILL_VERSION')) {
    // Ophogen dwingt bestaande checkpoints één keer opnieuw terug te kijken (logboek + nieuwe werkorders).
    define('DEMETER_WORKORDER_DELTA_BACKFILL_VERSION', 1);
}
if (!defined('DEMETER_BC_WORK_ORDER_TABLE_NO')) {
    define('DEMETER_BC_WORK_ORDER_TABLE_NO', 11332939);
}

/**
 * Velden in het wijzigingslogboek die de schermrij of de week van een werkorder raken.
 */
function demeter_workorder_delta_relevant_change(array $entry): bool
{
    if (strcasecmp(trim((string) ($entry['Type_of_Change'] ?? '')), 'Insertion') === 0) {
        return true;
    }
    $field = strtolower(trim((string) ($entry['Field_Caption'] ?? '')));

    return in_array($field, ['status', 'start date', 'document status', 'startdatum', 'documentstatus'], true);
}

function demeter_workorder_delta_week_of(string $ymd): ?string
{
    $ymd = substr(trim($ymd), 0, 10);
    if ($ymd === '' || strpos($ymd, '0001-') === 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) !== 1) {
        return null;
    }
    try {
        return demeter_iso_year_week_from_date(new DateTimeImmutable($ymd));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Haalt de wijzigingen uit BC (zonder iets op te slaan). Gooit DemeterStoreSyncAbort bij timeout/409.
 *
 * @param array{fetch: callable} $transport
 * @return array<string, mixed>
 */
function demeter_workorder_delta_fetch(array $transport, string $costCenter, array $checkpoint): array
{
    $fetch = $transport['fetch'];
    $out = ['init' => false, 'backfill' => false, 'posten_init' => false, 'postings' => [], 'changes' => [], 'workorders' => [], 'posten_entry_no' => null, 'changelog_entry_no' => null];

    $postenFrom = $checkpoint['posten_entry_no'];
    $changelogFrom = $checkpoint['changelog_entry_no'];
    $createdSince = trim((string) ($checkpoint['created_since'] ?? ''));
    $backfillSince = trim((string) ($checkpoint['backfill_since'] ?? ''));
    $backfillTs = $backfillSince !== '' ? strtotime($backfillSince) : false;
    $needsBackfill = (int) ($checkpoint['backfill_version'] ?? 0) < DEMETER_WORKORDER_DELTA_BACKFILL_VERSION;

    if ($changelogFrom === null || $needsBackfill) {
        // (Her)initialisatie: NIET vanaf 'nu' beginnen. Wat in BC veranderde tussen de weekscan van een week
        // en de eerste delta-sync (statussen, documentstatus, nieuwe werkorders zoals WO2610905) moet ook
        // binnenkomen. Daarom terugkijken vanaf de oudste weekscan (backfill_since, door de aanroeper begrensd).
        $out['backfill'] = true;
        $from = null;
        if ($backfillTs !== false) {
            $first = $fetch('ChangeLogEntries', [
                '$select' => 'Entry_No',
                '$filter' => 'Table_No eq ' . DEMETER_BC_WORK_ORDER_TABLE_NO . ' and Date_and_Time ge ' . gmdate('Y-m-d\TH:i:s\Z', $backfillTs),
                '$orderby' => 'Entry_No asc',
                '$top' => '1',
            ]);
            if (isset($first[0]['Entry_No'])) {
                $from = max(0, (int) $first[0]['Entry_No'] - 1);
            }
        }
        if ($from === null) {
            $lastChange = $fetch('ChangeLogEntries', [
                '$select' => 'Entry_No',
                '$filter' => 'Table_No eq ' . DEMETER_BC_WORK_ORDER_TABLE_NO,
                '$orderby' => 'Entry_No desc',
                '$top' => '1',
            ]);
            $from = (int) ($lastChange[0]['Entry_No'] ?? 0);
        }
        $changelogFrom = $changelogFrom === null ? $from : min((int) $changelogFrom, $from);
        if ($backfillTs !== false) {
            $createdTs0 = $createdSince !== '' ? strtotime($createdSince) : false;
            if ($createdTs0 === false || $backfillTs < $createdTs0) {
                $createdSince = gmdate('Y-m-d\TH:i:s\Z', $backfillTs);
            }
        }
    }
    if ($postenFrom === null) {
        // Posten hebben geen tijdstempel in OData: checkpoint op de hoogste Entry_No. Bedragen van achteraf
        // gedateerde posten komen via de volledige totalen (project_totals_full), niet via weekherlading.
        $out['posten_init'] = true;
        $last = $fetch('ProjectPosten', ['$select' => 'Entry_No', '$orderby' => 'Entry_No desc', '$top' => '1']);
        $out['posten_entry_no'] = (int) ($last[0]['Entry_No'] ?? 0);
    }
    if ($postenFrom === null && $backfillTs === false && $checkpoint['changelog_entry_no'] === null) {
        // Geen weekscan-tijd bekend: alleen het checkpoint zetten (oud gedrag).
        $out['init'] = true;
        $out['changelog_entry_no'] = (int) $changelogFrom;

        return $out;
    }

    if ($postenFrom !== null) {
        $out['postings'] = $fetch('ProjectPosten', [
            '$select' => 'Entry_No,Posting_Date,LVS_Work_Order_No,Job_No,Global_Dimension_1_Code,LVS_Global_Dimension_1_Code',
            '$filter' => 'Entry_No gt ' . (int) $postenFrom,
            '$orderby' => 'Entry_No asc',
            // Begrensd: na lange tijd niet geopend volgt de rest bij de volgende page-open (checkpoint = laatst verwerkt).
            '$top' => (string) DEMETER_WORKORDER_DELTA_MAX_ROWS,
        ]);
        $out['posten_entry_no'] = (int) $postenFrom;
        foreach ($out['postings'] as $posting) {
            $out['posten_entry_no'] = max($out['posten_entry_no'], (int) ($posting['Entry_No'] ?? 0));
        }
    }

    $changes = $fetch('ChangeLogEntries', [
        '$select' => 'Entry_No,Primary_Key_Field_1_Value,Field_Caption,Type_of_Change',
        '$filter' => 'Table_No eq ' . DEMETER_BC_WORK_ORDER_TABLE_NO . ' and Entry_No gt ' . (int) $changelogFrom,
        '$orderby' => 'Entry_No asc',
        '$top' => (string) DEMETER_WORKORDER_DELTA_MAX_ROWS,
    ]);
    usort($changes, static function (array $a, array $b): int {
        return (int) ($a['Entry_No'] ?? 0) <=> (int) ($b['Entry_No'] ?? 0);
    });
    // Begrensd en hervatbaar: het checkpoint gaat alleen tot de laatst verwerkte logregel.
    $changedNos = [];
    $out['changelog_entry_no'] = (int) $changelogFrom;
    // Het logboek is bedrijfsbreed: alleen werkorders nalopen die in beeld staan, plus nieuwe werkorders en
    // verzette startdatums (die kunnen in beeld komen). Scheelt calls en de begrenzing raakt minder snel vol.
    $knownNos = is_array($checkpoint['known_nos'] ?? null) ? $checkpoint['known_nos'] : null;
    foreach ($changes as $entry) {
        $no = trim((string) ($entry['Primary_Key_Field_1_Value'] ?? ''));
        if ($no !== '' && $knownNos !== null && !isset($knownNos[strtolower($no)])
            && strcasecmp(trim((string) ($entry['Type_of_Change'] ?? '')), 'Insertion') !== 0
            && !in_array(strtolower(trim((string) ($entry['Field_Caption'] ?? ''))), ['start date', 'startdatum'], true)
        ) {
            $out['changelog_entry_no'] = max($out['changelog_entry_no'], (int) ($entry['Entry_No'] ?? 0));
            continue;
        }
        if ($no !== '' && demeter_workorder_delta_relevant_change($entry) && !isset($changedNos[strtolower($no)])) {
            if (count($changedNos) >= DEMETER_WORKORDER_DELTA_MAX_CHANGED_WORKORDERS) {
                break;
            }
            $changedNos[strtolower($no)] = $no;
        }
        $out['changelog_entry_no'] = max($out['changelog_entry_no'], (int) ($entry['Entry_No'] ?? 0));
    }
    $out['changes'] = array_values($changedNos);

    $select = function_exists('bc_fetch_werkorders_list_select')
        ? bc_fetch_werkorders_list_select()
        : 'No,Status,KVT_Document_Status,Job_No,Job_Task_No,Start_Date,Job_Dimension_1_Value,Created_Date_Time';
    $byNo = [];
    $out['created_since_used'] = $createdSince;
    $createdTs = $createdSince !== '' ? strtotime($createdSince) : false;
    if ($createdTs !== false) {
        $filter = 'Created_Date_Time gt ' . gmdate('Y-m-d\TH:i:s\Z', $createdTs - 300);
        $ccFilter = bc_fetch_workorder_cost_center_odata_filter($costCenter);
        if ($ccFilter !== '') {
            $filter .= ' and ' . $ccFilter;
        }
        foreach ($fetch('Werkorders', ['$select' => $select, '$filter' => $filter]) as $row) {
            if (is_array($row) && trim((string) ($row['No'] ?? '')) !== '') {
                $byNo[strtolower(trim((string) $row['No']))] = $row;
            }
        }
    }
    $missing = array_values(array_diff_key($changedNos, $byNo));
    foreach (array_chunk($missing, DEMETER_WORKORDER_DELTA_NO_CHUNK) as $chunk) {
        $parts = array_map(static function (string $no): string {
            return "No eq '" . str_replace("'", "''", $no) . "'";
        }, $chunk);
        foreach ($fetch('Werkorders', ['$select' => $select, '$filter' => implode(' or ', $parts)]) as $row) {
            if (is_array($row) && trim((string) ($row['No'] ?? '')) !== '') {
                $byNo[strtolower(trim((string) $row['No']))] = $row;
            }
        }
    }
    $out['workorders'] = array_values($byNo);

    return $out;
}

/**
 * Past opgehaalde wijzigingen toe op de schermcache (onder de cache-lock van de aanroeper).
 *
 * @return array{dirty: array<string,string>, status_updates: int, rows_changed: bool, display: array, invalidate: list<string>}
 */
function demeter_workorder_delta_apply(array $fetched, array $displayRowsByKey, array $monthScan, string $costCenter, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $todayYmd = $today->format('Y-m-d');
    $ccMatch = bc_fetch_normalize_cost_center_for_match($costCenter);
    $months = is_array($monthScan['months'] ?? null) ? $monthScan['months'] : [];
    $dirty = [];
    $markDirty = static function (?string $week, string $reason) use (&$dirty, $months): void {
        // Alleen weken die al gelezen zijn; een nog niet gelezen week leest de (her)bouw sowieso.
        if ($week === null || trim((string) ($months[$week]['scanned_at'] ?? '')) === '') {
            return;
        }
        $dirty[$week] = isset($dirty[$week]) ? $dirty[$week] : $reason;
    };

    $rowKeysByBcNo = [];
    $jobsInView = [];
    foreach ($displayRowsByKey as $rowKey => $row) {
        if (!is_array($row) || !empty($row['Is_Posting_Only'])) {
            continue;
        }
        $bcNo = strtolower(trim((string) ($row['Bc_No'] ?? $row['No'] ?? '')));
        if ($bcNo !== '') {
            $rowKeysByBcNo[$bcNo][] = (string) $rowKey;
        }
        $job = strtolower(trim((string) ($row['Job_No'] ?? '')));
        if ($job !== '') {
            $jobsInView[$job] = true;
        }
    }

    $statusUpdates = 0;
    $rowsChanged = false;
    $invalidate = [];
    $relevantWo = [];
    foreach ($fetched['workorders'] as $wo) {
        $no = strtolower(trim((string) ($wo['No'] ?? '')));
        $header = bc_fetch_normalize_cost_center_for_match((string) ($wo['Job_Dimension_1_Value'] ?? ''));
        $start = substr((string) ($wo['Start_Date'] ?? ''), 0, 10);
        if (isset($rowKeysByBcNo[$no])) {
            $relevantWo[$no] = true;
            $invalidate[] = demeter_workorder_identity_key((string) ($wo['Job_No'] ?? ''), (string) ($wo['No'] ?? ''));
            $startMoved = false;
            foreach ($rowKeysByBcNo[$no] as $rowKey) {
                $row = $displayRowsByKey[$rowKey];
                $newStatus = (string) ($wo['Status'] ?? $row['Status'] ?? '');
                $newDoc = (string) ($wo['KVT_Document_Status'] ?? $row['Document_Status'] ?? '');
                $oldStart = substr((string) ($row['Start_Date'] ?? ''), 0, 10);
                if ($newStatus !== (string) ($row['Status'] ?? '') || $newDoc !== (string) ($row['Document_Status'] ?? '') || ($start !== '' && $start !== $oldStart)) {
                    if ($newStatus !== (string) ($row['Status'] ?? '')) {
                        $statusUpdates++;
                    }
                    $row['Status'] = $newStatus;
                    $row['Document_Status'] = $newDoc;
                    if ($start !== '' && $start !== $oldStart) {
                        $row['Start_Date'] = $start;
                        $startMoved = true;
                    }
                    $displayRowsByKey[$rowKey] = $row;
                    $rowsChanged = true;
                }
            }
            if ($startMoved && $start < $todayYmd) {
                // Startdatum verzet naar een (gesloten) week: die week opnieuw lezen (posten/totalen van die week).
                $markDirty(demeter_workorder_delta_week_of($start), 'startdatum');
            }
            continue;
        }
        if ($ccMatch !== '' && $header !== $ccMatch) {
            continue; // andere afdeling (lege kop: alleen via posten/projectkaart bij de weeklading)
        }
        $relevantWo[$no] = true;
        // Nieuw of (her)geopend en nog niet in beeld: week van de startdatum opnieuw lezen. Startdatum vandaag
        // of later en 0001-01-01 vallen in de 'vandaag'-load van de huidige week (catch-up).
        if ($start !== '' && $start < $todayYmd) {
            $markDirty(demeter_workorder_delta_week_of($start), 'werkorder');
        }
    }

    foreach ($fetched['postings'] as $posting) {
        $postingDate = substr((string) ($posting['Posting_Date'] ?? ''), 0, 10);
        if ($postingDate === '' || $postingDate >= $todayYmd) {
            continue; // posten van vandaag leest de catch-up van de huidige week
        }
        $wo = strtolower(trim((string) ($posting['LVS_Work_Order_No'] ?? '')));
        $job = strtolower(trim((string) ($posting['Job_No'] ?? '')));
        $dims = [
            bc_fetch_normalize_cost_center_for_match((string) ($posting['Global_Dimension_1_Code'] ?? '')),
            bc_fetch_normalize_cost_center_for_match((string) ($posting['LVS_Global_Dimension_1_Code'] ?? '')),
        ];
        $relevant = ($ccMatch !== '' && in_array($ccMatch, $dims, true))
            || ($wo !== '' && (isset($rowKeysByBcNo[$wo]) || isset($relevantWo[$wo])))
            || ($wo === '' && $job !== '' && isset($jobsInView[$job]));
        if ($relevant) {
            $markDirty(demeter_workorder_delta_week_of($postingDate), 'post');
        }
    }

    return [
        'dirty' => $dirty,
        'status_updates' => $statusUpdates,
        'rows_changed' => $rowsChanged,
        'display' => $displayRowsByKey,
        'invalidate' => array_values(array_unique(array_filter($invalidate))),
    ];
}

/**
 * Vanaf wanneer terugkijken bij (her)initialisatie: de oudste weekscan (wat daarna in BC veranderde kan in
 * een al gelezen week ontbreken), begrensd op DEMETER_WORKORDER_DELTA_BACKFILL_MAX_SECONDS. Null zonder scans.
 */
function demeter_workorder_delta_backfill_since(array $monthScan, int $now): ?string
{
    $oldest = null;
    foreach ((is_array($monthScan['months'] ?? null) ? $monthScan['months'] : []) as $entry) {
        $ts = is_array($entry) ? strtotime((string) ($entry['scanned_at'] ?? '')) : false;
        if ($ts !== false && $ts > 0 && ($oldest === null || $ts < $oldest)) {
            $oldest = $ts;
        }
    }
    if ($oldest === null) {
        return null;
    }
    // Marge voor klokverschil en voor een week-load die tijdens de scan liep.
    $since = max($oldest - 300, $now - DEMETER_WORKORDER_DELTA_BACKFILL_MAX_SECONDS);

    return gmdate('Y-m-d\TH:i:s\Z', $since);
}

/**
 * Page-open: alleen voor een complete cache van de huidige versie zonder lopende (her)bouw, en alleen als
 * de laatste geslaagde delta ouder is dan DEMETER_WORKORDER_DELTA_MAX_AGE_SECONDS.
 *
 * @param callable(): array $transportFactory levert ['fetch' => callable(string $entity, array $query): list<array>]
 * @return array<string, mixed>
 */
function demeter_workorder_delta_page_open(string $company, string $costCenter, callable $transportFactory, ?int $now = null): array
{
    $now = $now ?? time();
    $result = ['status' => 'skipped', 'dirty_weeks' => [], 'status_updates' => 0, 'rows_changed' => false];

    if (demeter_workorder_state_cache_is_stale_version($company, $costCenter)) {
        return ['reason' => 'cacheversie'] + $result;
    }
    $state = demeter_workorder_state_cache_load($company, $costCenter);
    $monthScan = is_array($state['month_scan'] ?? null) ? $state['month_scan'] : null;
    if (!is_array($state) || $monthScan === null || !demeter_month_scan_history_complete($monthScan)) {
        return ['reason' => 'cache niet compleet (herbouw loopt of nodig)'] + $result;
    }
    if (function_exists('demeter_active_load_get') && function_exists('demeter_active_load_is_fresh_running')
        && demeter_active_load_is_fresh_running(demeter_active_load_get($company, $costCenter))
    ) {
        return ['reason' => 'er loopt een load'] + $result;
    }

    $checkpoint = demeter_workorder_delta_read($company, $costCenter);
    if ($now - (int) ($checkpoint['synced_at'] ?? 0) <= DEMETER_WORKORDER_DELTA_MAX_AGE_SECONDS) {
        return ['status' => 'fresh', 'dirty_weeks' => demeter_workorder_delta_dirty_weeks($company, $costCenter)] + $result;
    }

    // Eén sync tegelijk per kostenplaats (niet-blokkerend): een tweede tab wacht niet en doet niets dubbel.
    $lockPath = demeter_workorder_delta_path($company, $costCenter) . '.lock';
    $lockHandle = @fopen($lockPath, 'c');
    if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
        if (is_resource($lockHandle)) {
            fclose($lockHandle);
        }

        return ['status' => 'busy', 'dirty_weeks' => demeter_workorder_delta_dirty_weeks($company, $costCenter)] + $result;
    }

    $checkpoint['backfill_since'] = demeter_workorder_delta_backfill_since($monthScan, $now);
    $checkpoint['known_nos'] = [];
    foreach (demeter_workorder_state_cache_load_display_rows($company, $costCenter) as $row) {
        $bcNo = is_array($row) ? strtolower(trim((string) ($row['Bc_No'] ?? $row['No'] ?? ''))) : '';
        if ($bcNo !== '') {
            $checkpoint['known_nos'][$bcNo] = true;
        }
    }

    try {
        $startedIso = gmdate('Y-m-d\TH:i:s\Z', $now);
        try {
            $fetched = demeter_workorder_delta_fetch($transportFactory(), $costCenter, $checkpoint);
        } catch (Throwable $abort) {
            // Timeout/409/netwerk: bestaande data en checkpoint blijven staan; volgende page-open opnieuw.
            error_log('Demeter delta ' . $company . '/' . $costCenter . ' afgebroken: ' . $abort->getMessage());
            $checkpoint['last'] = ['at' => $now, 'aborted' => substr($abort->getMessage(), 0, 200)];
            demeter_workorder_delta_write($company, $costCenter, $checkpoint);

            return ['status' => 'aborted', 'reason' => substr($abort->getMessage(), 0, 200), 'dirty_weeks' => demeter_workorder_delta_dirty_weeks($company, $costCenter)] + $result;
        }

        try {
            return demeter_workorder_delta_apply_locked($company, $costCenter, $fetched, $now, $startedIso, $result);
        } catch (Throwable $lockError) {
            // Cache-lock bezet (een week-load schrijft): niets wijzigen, volgende page-open opnieuw.
            return ['status' => 'busy', 'reason' => substr($lockError->getMessage(), 0, 200), 'dirty_weeks' => demeter_workorder_delta_dirty_weeks($company, $costCenter)] + $result;
        }
    } finally {
        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function demeter_workorder_delta_apply_locked(string $company, string $costCenter, array $fetched, int $now, string $startedIso, array $result): array
{
        return demeter_workorder_state_cache_with_lock($company, $costCenter, static function () use ($company, $costCenter, $fetched, $now, $startedIso, $result): array {
            $checkpoint = demeter_workorder_delta_read($company, $costCenter);
            $applied = ['dirty' => [], 'status_updates' => 0, 'rows_changed' => false, 'invalidate' => []];
            if (!$fetched['init']) {
                $state = demeter_workorder_state_cache_load($company, $costCenter);
                $monthScan = is_array($state['month_scan'] ?? null) ? $state['month_scan'] : [];
                $display = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
                $applied = demeter_workorder_delta_apply($fetched, $display, $monthScan, $costCenter);
                if ($applied['rows_changed']) {
                    demeter_workorder_state_cache_save_display_rows($company, $costCenter, $applied['display']);
                }
                if ($applied['invalidate'] !== [] && is_array($state)) {
                    // Gecachte werkorder-metadata van gewijzigde werkorders vergeten: een volgende incrementele
                    // load haalt ze dan vers op (ook een als 'gesloten' gecachte werkorder die weer open is).
                    $map = is_array($state['workorders'] ?? null) ? $state['workorders'] : [];
                    $before = count($map);
                    foreach ($applied['invalidate'] as $identity) {
                        unset($map[$identity]);
                    }
                    if (count($map) !== $before) {
                        demeter_workorder_state_cache_save(
                            $company,
                            $costCenter,
                            $map,
                            $monthScan,
                            demeter_workorder_state_normalize_load_session($state['load_session'] ?? null)
                        );
                    }
                }
            }
            $checkpoint['posten_entry_no'] = max((int) ($checkpoint['posten_entry_no'] ?? 0), (int) $fetched['posten_entry_no']);
            if (!empty($fetched['backfill'])) {
                // Terugkijken: checkpoint = laatst verwerkte logregel (kan lager zijn dan het oude checkpoint;
                // bij de begrenzing volgt de rest bij de volgende page-open).
                $checkpoint['changelog_entry_no'] = (int) $fetched['changelog_entry_no'];
                $checkpoint['backfill_version'] = DEMETER_WORKORDER_DELTA_BACKFILL_VERSION;
            } else {
                $checkpoint['changelog_entry_no'] = max((int) ($checkpoint['changelog_entry_no'] ?? 0), (int) $fetched['changelog_entry_no']);
            }
            $checkpoint['created_since'] = $startedIso;
            $checkpoint['synced_at'] = $now;
            $checkpoint['dirty_weeks'] = array_replace(is_array($checkpoint['dirty_weeks']) ? $checkpoint['dirty_weeks'] : [], $applied['dirty']);
            $checkpoint['last'] = [
                'at' => $now,
                'init' => (bool) $fetched['init'],
                'backfill' => !empty($fetched['backfill']),
                'created_since_used' => (string) ($fetched['created_since_used'] ?? ''),
                'postings' => count($fetched['postings']),
                'changed_workorders' => count($fetched['changes']),
                'workorders' => count($fetched['workorders']),
                'new_dirty_weeks' => array_keys($applied['dirty']),
                'status_updates' => $applied['status_updates'],
            ];
            demeter_workorder_delta_write($company, $costCenter, $checkpoint);

            return [
                'status' => $fetched['init'] ? 'initialized' : 'synced',
                'dirty_weeks' => demeter_workorder_delta_dirty_weeks($company, $costCenter),
                'status_updates' => $applied['status_updates'],
                'rows_changed' => $applied['rows_changed'],
            ] + $result;
        }, 15);
}
