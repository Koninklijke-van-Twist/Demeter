<?php
/**
 * Fase 1 (SHADOW): server-side werkorder-store per bedrijf.
 *
 * - Eén JSON-bestand per bedrijf (werkorders op `No` + kosten-aggregaten per werkorder + sync-state),
 *   atomisch geschreven (temp + rename) onder een bedrijfslock (flock). Lezen kan altijd zonder lock.
 * - nightly: volledige snapshot, pas ingewisseld als de BC-counts (afdeling × status) kloppen.
 * - hourly: alle niet-afgesloten werkorders opnieuw.
 * - pagina-open (>180 s oud): count-reconciliatie per afdeling × status, inzoomen op Start_Date,
 *   nieuwe werkorders (Created_Date_Time) en nieuwe ProjectPosten (Entry_No > max) live.
 * - Het scherm leest hier in fase 1 NIET uit.
 *
 * BC-toegang loopt via een "transport" (array met closures), zodat tests BC kunnen simuleren:
 *   'count' => fn(string $entity, string $filter): int
 *   'fetch' => fn(string $entity, array $query): list<array>
 */

require_once __DIR__ . '/../bc_enum.php';

if (!defined('DEMETER_STORE_VERSION')) {
    define('DEMETER_STORE_VERSION', 1);
}
if (!defined('DEMETER_STORE_MAX_AGE_SECONDS')) {
    define('DEMETER_STORE_MAX_AGE_SECONDS', 180);
}
if (!defined('DEMETER_STORE_BUCKET_FETCH_MAX')) {
    define('DEMETER_STORE_BUCKET_FETCH_MAX', 40);
}
if (!defined('DEMETER_STORE_SYNC_TIME_BUDGET_SECONDS')) {
    define('DEMETER_STORE_SYNC_TIME_BUDGET_SECONDS', 25);
}

/** Velden van een werkorderrij in de store. */
function demeter_store_workorder_fields(): array
{
    return [
        'No', 'Task_Code', 'Task_Description', 'Status', 'KVT_Document_Status', 'Job_No', 'Job_Task_No',
        'Contract_No', 'Start_Date', 'End_Date', 'Sub_Entity_Description', 'Component_No',
        'Bill_to_Customer_No', 'Bill_to_Name', 'Sell_to_Customer_No', 'Sell_to_Name',
        'Job_Dimension_1_Value', 'Created_Date_Time',
    ];
}

function demeter_store_posting_fields(): array
{
    return ['Entry_No', 'Job_No', 'Job_Task_No', 'LVS_Work_Order_No', 'Entry_Type', 'Total_Cost', 'Line_Amount_LCY'];
}

/** Canonieke (Engelse) status; Mímir levert NL-captions, directe BC Engels. */
function demeter_store_status_canonical(string $status): string
{
    $n = demeter_enum_normalize($status);
    $map = [
        'open' => 'Open', 'planned' => 'Planned', 'gepland' => 'Planned',
        'in progress' => 'In Progress', 'in behandeling' => 'In Progress', 'onderhanden' => 'In Progress',
        'checked' => 'Checked', 'gecontroleerd' => 'Checked', 'signed' => 'Signed', 'getekend' => 'Signed', 'ondertekend' => 'Signed',
        'closed' => 'Closed', 'afgesloten' => 'Closed', 'completed' => 'Completed', 'uitgevoerd' => 'Completed', 'gereed' => 'Completed',
        'cancelled' => 'Cancelled', 'canceled' => 'Cancelled', 'geannuleerd' => 'Cancelled', 'gecancelled' => 'Cancelled',
        'invoiced' => 'Invoiced', 'gefactureerd' => 'Invoiced',
    ];

    return $map[$n] ?? trim($status);
}

/** Closed, Completed, Cancelled, Invoiced = afgesloten; Checked/Signed tellen als open. */
function demeter_store_status_is_closed(string $status): bool
{
    return in_array(demeter_store_status_canonical($status), ['Closed', 'Completed', 'Cancelled', 'Invoiced'], true)
        || demeter_status_is_closed($status);
}

function demeter_store_closed_statuses(): array
{
    return ['Closed', 'Completed', 'Cancelled', 'Invoiced'];
}

// ---------------------------------------------------------------------------------------------
// Opslag: pad, lezen, atomisch schrijven, lock
// ---------------------------------------------------------------------------------------------

function demeter_store_base_dir(): string
{
    if (isset($GLOBALS['DEMETER_STORE_BASE_DIR']) && is_string($GLOBALS['DEMETER_STORE_BASE_DIR'])) {
        return rtrim($GLOBALS['DEMETER_STORE_BASE_DIR'], '/');
    }

    return dirname(__DIR__) . '/cache/workorder_store';
}

function demeter_store_company_slug(string $company): string
{
    $clean = preg_replace('/[^A-Za-z0-9]+/', '_', $company);

    return trim((string) $clean, '_') . '_' . substr(sha1($company), 0, 10);
}

function demeter_store_path(string $company): string
{
    return demeter_store_base_dir() . '/' . demeter_store_company_slug($company) . '.json';
}

function demeter_store_lock_path(string $company): string
{
    return demeter_store_path($company) . '.lock';
}

function demeter_store_empty(string $company): array
{
    return [
        'version' => DEMETER_STORE_VERSION,
        'company' => $company,
        'shadow' => true,
        'workorders' => [],
        'finance' => [],
        'finance_jobtask' => [],
        'max_entry_no' => 0,
        'max_created_at' => '',
        'full_at' => 0,
        'synced_at' => 0,
        'hourly_at' => 0,
        'last_sync' => null,
        'last_verification' => null,
        'last_reconciliation' => null,
    ];
}

/** Leest de store zonder lock (atomische rename garandeert een complete versie). Null als hij er niet is. */
function demeter_store_read(string $company): ?array
{
    $path = demeter_store_path($company);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || (int) ($data['version'] ?? 0) !== DEMETER_STORE_VERSION || !is_array($data['workorders'] ?? null)) {
        return null;
    }

    return $data;
}

/** Goedkope check voor de pagina: bestaat er een store en is die ouder dan 180 s (mtime, zonder JSON te parsen)? */
function demeter_store_read_meta_is_stale(string $company): bool
{
    $path = demeter_store_path($company);
    clearstatcache(true, $path);

    return is_file($path) && time() - (int) @filemtime($path) > DEMETER_STORE_MAX_AGE_SECONDS;
}

/** Atomisch schrijven: temp in dezelfde map + rename. De oude versie blijft staan tot de nieuwe compleet is. */
function demeter_store_write(string $company, array $store): void
{
    $path = demeter_store_path($company);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Store-map kan niet worden aangemaakt.');
    }
    $store['version'] = DEMETER_STORE_VERSION;
    $store['company'] = $company;
    $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($json)) {
        throw new RuntimeException('Store kan niet naar JSON.');
    }
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json) !== strlen($json)) {
        @unlink($tmp);
        throw new RuntimeException('Store-tempbestand schrijven mislukt.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Store-rename mislukt.');
    }
}

/**
 * Voert $fn uit onder de exclusieve bedrijfslock. $waitSeconds = 0: niet wachten (null als bezet).
 *
 * @return mixed|null null wanneer de lock niet binnen $waitSeconds vrijkwam
 */
function demeter_store_with_lock(string $company, callable $fn, float $waitSeconds = 0.0, ?bool &$acquired = null)
{
    $acquired = false;
    $lockPath = demeter_store_lock_path($company);
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $handle = @fopen($lockPath, 'c');
    if ($handle === false) {
        throw new RuntimeException('Store-lock kan niet worden geopend.');
    }
    $deadline = microtime(true) + max(0.0, $waitSeconds);
    try {
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(200000);
        }
        $acquired = true;

        return $fn();
    } finally {
        if ($acquired) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}

// ---------------------------------------------------------------------------------------------
// Rijen en kosten
// ---------------------------------------------------------------------------------------------

function demeter_store_normalize_row(array $row): array
{
    $out = [];
    foreach (demeter_store_workorder_fields() as $field) {
        $value = $row[$field] ?? '';
        $out[$field] = is_scalar($value) ? (string) $value : '';
    }
    $out['Status'] = demeter_store_status_canonical($out['Status']);

    return $out;
}

function demeter_store_upsert(array &$store, array $row): void
{
    $norm = demeter_store_normalize_row($row);
    $no = $norm['No'];
    if ($no === '') {
        return;
    }
    $store['workorders'][$no] = $norm;
    if ($norm['Created_Date_Time'] !== '' && strcmp($norm['Created_Date_Time'], (string) ($store['max_created_at'] ?? '')) > 0) {
        $store['max_created_at'] = $norm['Created_Date_Time'];
    }
}

function demeter_store_empty_finance(): array
{
    return ['cost' => 0.0, 'usage_amount' => 0.0, 'sale_amount' => 0.0, 'entries' => 0];
}

/** Telt één ProjectPost op bij de werkorder (LVS_Work_Order_No) of bij job|taak. Tegenboekingen zijn negatief en tellen dus vanzelf af. */
function demeter_store_apply_posting(array &$store, array $posting): void
{
    $type = demeter_entry_type_canonical((string) ($posting['Entry_Type'] ?? ''));
    $cost = (float) ($posting['Total_Cost'] ?? 0);
    $amount = (float) ($posting['Line_Amount_LCY'] ?? 0);
    $wo = trim((string) ($posting['LVS_Work_Order_No'] ?? ''));
    if ($wo !== '') {
        $bucket = &$store['finance'][$wo];
    } else {
        $key = trim((string) ($posting['Job_No'] ?? '')) . '|' . trim((string) ($posting['Job_Task_No'] ?? ''));
        $bucket = &$store['finance_jobtask'][$key];
    }
    if (!is_array($bucket)) {
        $bucket = demeter_store_empty_finance();
    }
    if ($type === 'usage') {
        $bucket['cost'] = round($bucket['cost'] + $cost, 2);
        $bucket['usage_amount'] = round($bucket['usage_amount'] + $amount, 2);
    } elseif ($type === 'sale') {
        $bucket['sale_amount'] = round($bucket['sale_amount'] + $amount, 2);
    }
    $bucket['entries']++;
    unset($bucket);
    $entryNo = (int) ($posting['Entry_No'] ?? 0);
    if ($entryNo > (int) ($store['max_entry_no'] ?? 0)) {
        $store['max_entry_no'] = $entryNo;
    }
}

/** Kosten van een werkorder: geboekt op het werkordernummer. */
function demeter_store_finance_for(array $store, string $no): array
{
    $fin = $store['finance'][$no] ?? null;

    return is_array($fin) ? $fin : demeter_store_empty_finance();
}

// ---------------------------------------------------------------------------------------------
// OData-filterhulpjes
// ---------------------------------------------------------------------------------------------

function demeter_store_q(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function demeter_store_group_filter(?string $afdeling, ?string $status, ?string $from = null, ?string $to = null): string
{
    $parts = [];
    if ($afdeling !== null) {
        $parts[] = 'Job_Dimension_1_Value eq ' . demeter_store_q($afdeling);
    }
    if ($status !== null) {
        $parts[] = 'Status eq ' . demeter_store_q($status);
    }
    if ($from !== null) {
        $parts[] = 'Start_Date ge ' . $from;
    }
    if ($to !== null) {
        $parts[] = 'Start_Date le ' . $to;
    }

    return implode(' and ', $parts);
}

function demeter_store_row_in_group(array $row, ?string $afdeling, ?string $status, ?string $from = null, ?string $to = null): bool
{
    if ($afdeling !== null && (string) $row['Job_Dimension_1_Value'] !== $afdeling) {
        return false;
    }
    if ($status !== null && demeter_store_status_canonical((string) $row['Status']) !== demeter_store_status_canonical($status)) {
        return false;
    }
    $date = (string) $row['Start_Date'];
    if ($from !== null && strcmp($date, $from) < 0) {
        return false;
    }
    if ($to !== null && strcmp($date, $to) > 0) {
        return false;
    }

    return true;
}

/** @return list<string> */
function demeter_store_nos_in_group(array $store, ?string $afdeling, ?string $status, ?string $from = null, ?string $to = null): array
{
    $nos = [];
    foreach ($store['workorders'] as $no => $row) {
        if (demeter_store_row_in_group($row, $afdeling, $status, $from, $to)) {
            $nos[] = (string) $no;
        }
    }

    return $nos;
}

/** Werkorders op nummer ophalen (batches van 20). @return array<string, array> No => rij */
function demeter_store_fetch_by_nos(array $transport, array $nos): array
{
    $found = [];
    $nos = array_values(array_unique(array_filter(array_map('strval', $nos), 'strlen')));
    foreach (array_chunk($nos, 20) as $chunk) {
        $filter = implode(' or ', array_map(static function (string $no): string {
            return 'No eq ' . demeter_store_q($no);
        }, $chunk));
        $rows = $transport['fetch']('Werkorders', ['$select' => implode(',', demeter_store_workorder_fields()), '$filter' => $filter]);
        foreach ($rows as $row) {
            $found[(string) ($row['No'] ?? '')] = $row;
        }
    }

    return $found;
}

/** Dagen tussen twee ISO-datums verschuiven. */
function demeter_store_date_add(string $date, int $days): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

    return $dt->modify(($days >= 0 ? '+' : '') . $days . ' day')->format('Y-m-d');
}

/**
 * Alle werkorders ophalen in Start_Date-blokken (BC kapt grote responses af op de operation-timeout en
 * `$top` begrenst het TOTAAL, dus geen $top-paginering). $extraFilter bv. een afdelingsfilter.
 *
 * @return list<array>
 */
function demeter_store_fetch_all_workorders(array $transport, string $extraFilter = ''): array
{
    $ranges = [['0001-01-01', '0001-01-01'], ['0001-01-02', '2019-12-31']];
    $year = 2020;
    $lastYear = (int) gmdate('Y') + 2;
    for (; $year <= $lastYear; $year++) {
        $ranges[] = [$year . '-01-01', $year . '-06-30'];
        $ranges[] = [$year . '-07-01', $year . '-12-31'];
    }
    $ranges[] = [($lastYear + 1) . '-01-01', '9999-12-31'];
    $all = [];
    foreach ($ranges as [$from, $to]) {
        $filter = 'Start_Date ge ' . $from . ' and Start_Date le ' . $to . ($extraFilter !== '' ? ' and ' . $extraFilter : '');
        foreach ($transport['fetch']('Werkorders', ['$select' => implode(',', demeter_store_workorder_fields()), '$filter' => $filter]) as $row) {
            $all[] = $row;
        }
    }

    return $all;
}

/** Alle ProjectPosten in Entry_No-blokken van 15.000. */
function demeter_store_fetch_all_postings(array $transport): array
{
    $all = [];
    $from = 0;
    $emptyBlocks = 0;
    while ($emptyBlocks < 2) {
        $rows = $transport['fetch']('ProjectPosten', [
            '$select' => implode(',', demeter_store_posting_fields()),
            '$filter' => 'Entry_No gt ' . $from . ' and Entry_No le ' . ($from + 15000),
        ]);
        $emptyBlocks = $rows === [] ? $emptyBlocks + 1 : 0;
        foreach ($rows as $row) {
            $all[] = $row;
        }
        $from += 15000;
    }

    return $all;
}

// ---------------------------------------------------------------------------------------------
// Count-reconciliatie + inzoomen op Start_Date
// ---------------------------------------------------------------------------------------------

/**
 * Brengt één groep (afdeling × status) in lijn met BC. Bisect op Start_Date (mediaan van de store-rijen)
 * tot een bucket met ≤ DEMETER_STORE_BUCKET_FETCH_MAX rijen; die bucket wordt opgehaald en vergeleken.
 * Store-rijen in de bucket die BC niet meer teruggeeft worden op nummer nagekeken (status/afdeling/datum
 * gewijzigd → upsert; niet gevonden → verwijderd).
 */
function demeter_store_reconcile_group(array &$store, array $transport, ?string $afdeling, ?string $status, array &$stats, float $deadline): bool
{
    $stack = [['0001-01-01', '0001-01-01', null], ['0001-01-02', '9999-12-31', null]];
    while ($stack !== []) {
        if (microtime(true) > $deadline) {
            $stats['budget_exceeded'] = true;

            return false;
        }
        [$from, $to, $bcCount] = array_pop($stack);
        if ($bcCount === null) {
            $bcCount = (int) $transport['count']('Werkorders', demeter_store_group_filter($afdeling, $status, $from, $to));
            $stats['count_calls']++;
        }
        $storeNos = demeter_store_nos_in_group($store, $afdeling, $status, $from, $to);
        if ($bcCount === count($storeNos)) {
            continue;
        }
        $dates = [];
        foreach ($storeNos as $no) {
            $dates[] = (string) $store['workorders'][$no]['Start_Date'];
        }
        sort($dates);
        $canSplit = $from !== $to && $dates !== [] && $dates[0] !== end($dates);
        if ($bcCount <= DEMETER_STORE_BUCKET_FETCH_MAX || !$canSplit) {
            if ($bcCount > DEMETER_STORE_BUCKET_FETCH_MAX * 10) {
                // Groep zonder bruikbare store-verdeling (bijv. lege store): halveer de datumrange.
                if ($from !== $to) {
                    $mid = demeter_store_midpoint_date($from, $to);
                    if ($mid !== null) {
                        $stack[] = [$from, $mid, null];
                        $stack[] = [demeter_store_date_add($mid, 1), $to, null];
                        continue;
                    }
                }
            }
            demeter_store_refetch_bucket($store, $transport, $afdeling, $status, $from, $to, $storeNos, $stats);
            continue;
        }
        $mid = $dates[intdiv(count($dates) - 1, 2)];
        if ($mid >= $to) {
            $mid = demeter_store_date_add($to, -1);
        }
        if ($mid < $from) {
            $mid = $from;
        }
        $leftCount = (int) $transport['count']('Werkorders', demeter_store_group_filter($afdeling, $status, $from, $mid));
        $stats['count_calls']++;
        $stack[] = [$from, $mid, $leftCount];
        $stack[] = [demeter_store_date_add($mid, 1), $to, $bcCount - $leftCount];
    }

    return true;
}

function demeter_store_midpoint_date(string $from, string $to): ?string
{
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$a || !$b || $a >= $b) {
        return null;
    }
    $days = (int) $a->diff($b)->days;

    return $a->modify('+' . intdiv($days, 2) . ' day')->format('Y-m-d');
}

function demeter_store_refetch_bucket(array &$store, array $transport, ?string $afdeling, ?string $status, string $from, string $to, array $storeNos, array &$stats): void
{
    $rows = $transport['fetch']('Werkorders', [
        '$select' => implode(',', demeter_store_workorder_fields()),
        '$filter' => demeter_store_group_filter($afdeling, $status, $from, $to),
    ]);
    $stats['bucket_fetches']++;
    $stats['rows_fetched'] += count($rows);
    $seen = [];
    foreach ($rows as $row) {
        $no = (string) ($row['No'] ?? '');
        if ($no === '') {
            continue;
        }
        $seen[$no] = true;
        if (!isset($store['workorders'][$no]) || $store['workorders'][$no] !== demeter_store_normalize_row($row)) {
            $stats['upserted']++;
        }
        demeter_store_upsert($store, $row);
    }
    $gone = array_values(array_filter($storeNos, static function (string $no) use ($seen): bool {
        return !isset($seen[$no]);
    }));
    if ($gone === []) {
        return;
    }
    $found = demeter_store_fetch_by_nos($transport, $gone);
    $stats['rows_fetched'] += count($found);
    foreach ($gone as $no) {
        if (isset($found[$no])) {
            demeter_store_upsert($store, $found[$no]);
            $stats['upserted']++;
        } else {
            unset($store['workorders'][$no]);
            $stats['deleted']++;
        }
    }
}

/**
 * Groepen (afdeling × status) om te controleren. Statussen uit store én de standaardlijst, zodat een
 * status die in de store (nog) niet voorkomt ook geteld wordt.
 *
 * @return list<array{0:?string,1:?string}>
 */
function demeter_store_groups_for(array $store, array $afdelingen): array
{
    $statuses = ['Open', 'Planned', 'In Progress', 'Checked', 'Signed', 'Closed', 'Completed', 'Cancelled', 'Invoiced'];
    foreach ($store['workorders'] as $row) {
        $s = demeter_store_status_canonical((string) $row['Status']);
        if (!in_array($s, $statuses, true)) {
            $statuses[] = $s;
        }
    }
    $groups = [];
    foreach ($afdelingen as $afdeling) {
        foreach ($statuses as $status) {
            $groups[] = [(string) $afdeling, $status];
        }
    }

    return $groups;
}

/**
 * Count per groep (BC) vs store; mismatches inzoomen. Daarna de afdelingstotalen (vangt onbekende statussen).
 *
 * @return array{groups:int,mismatched:list<string>,verified:bool}
 */
function demeter_store_reconcile_counts(array &$store, array $transport, array $afdelingen, array &$stats, float $deadline): array
{
    $mismatched = [];
    $groups = demeter_store_groups_for($store, $afdelingen);
    foreach ($afdelingen as $afdeling) {
        $groups[] = [(string) $afdeling, null];   // afdelingstotaal: vangt statussen buiten de lijst
    }
    $filters = array_map(static function (array $g): string {
        return demeter_store_group_filter($g[0], $g[1]);
    }, $groups);
    if (isset($transport['count_many'])) {
        $bcCounts = $transport['count_many']('Werkorders', $filters);
    } else {
        $bcCounts = [];
        foreach ($filters as $i => $filter) {
            $bcCounts[$i] = (int) $transport['count']('Werkorders', $filter);
        }
    }
    $stats['count_calls'] += count($filters);
    foreach ($groups as $i => [$afdeling, $status]) {
        $bc = (int) $bcCounts[$i];
        $local = count(demeter_store_nos_in_group($store, $afdeling, $status));
        if ($bc !== $local) {
            $mismatched[] = ($afdeling === '' ? '(leeg)' : $afdeling) . '/' . ($status ?? '*') . ' (BC ' . $bc . ', store ' . $local . ')';
            demeter_store_reconcile_group($store, $transport, $afdeling, $status, $stats, $deadline);
        }
    }
    // Na het inzoomen opnieuw vergelijken met de (gecachete) BC-counts van deze ronde.
    $verified = empty($stats['budget_exceeded']);

    return ['groups' => count($groups), 'mismatched' => $mismatched, 'verified' => $verified];
}

// ---------------------------------------------------------------------------------------------
// Delta's: ProjectPosten op Entry_No, nieuwe werkorders op Created_Date_Time
// ---------------------------------------------------------------------------------------------

function demeter_store_apply_new_postings(array &$store, array $transport, array &$stats): void
{
    $max = (int) ($store['max_entry_no'] ?? 0);
    $rows = $transport['fetch']('ProjectPosten', [
        '$select' => implode(',', demeter_store_posting_fields()),
        '$filter' => 'Entry_No gt ' . $max,
        '$orderby' => 'Entry_No asc',
    ]);
    usort($rows, static function (array $a, array $b): int {
        return (int) ($a['Entry_No'] ?? 0) <=> (int) ($b['Entry_No'] ?? 0);
    });
    $unknownWo = [];
    foreach ($rows as $posting) {
        if ((int) ($posting['Entry_No'] ?? 0) <= (int) $store['max_entry_no']) {
            continue; // idempotent: nooit dubbel optellen
        }
        demeter_store_apply_posting($store, $posting);
        $stats['postings']++;
        $wo = trim((string) ($posting['LVS_Work_Order_No'] ?? ''));
        if ($wo !== '' && !isset($store['workorders'][$wo])) {
            $unknownWo[$wo] = true;
        }
    }
    if ($unknownWo !== []) {
        foreach (demeter_store_fetch_by_nos($transport, array_keys($unknownWo)) as $row) {
            demeter_store_upsert($store, $row);
            $stats['upserted']++;
        }
    }
}

function demeter_store_apply_new_workorders(array &$store, array $transport, array &$stats): void
{
    $since = (string) ($store['max_created_at'] ?? '');
    if ($since === '') {
        return;
    }
    $ts = strtotime($since);
    if ($ts === false) {
        return;
    }
    $rows = $transport['fetch']('Werkorders', [
        '$select' => implode(',', demeter_store_workorder_fields()),
        '$filter' => 'Created_Date_Time gt ' . gmdate('Y-m-d\TH:i:s\Z', $ts - 300),
    ]);
    $stats['rows_fetched'] += count($rows);
    foreach ($rows as $row) {
        $no = (string) ($row['No'] ?? '');
        if ($no !== '' && (!isset($store['workorders'][$no]) || $store['workorders'][$no] !== demeter_store_normalize_row($row))) {
            $stats['upserted']++;
        }
        demeter_store_upsert($store, $row);
    }
}

function demeter_store_new_stats(): array
{
    return ['count_calls' => 0, 'bucket_fetches' => 0, 'rows_fetched' => 0, 'upserted' => 0, 'deleted' => 0, 'postings' => 0, 'budget_exceeded' => false];
}

// ---------------------------------------------------------------------------------------------
// Pagina-open sync (≤ 3 min oud), hourly, nightly snapshot
// ---------------------------------------------------------------------------------------------

/**
 * Delta-sync op een store-array (zonder I/O): postings → nieuwe werkorders → counts per afdeling × status.
 */
function demeter_store_delta_sync(array &$store, array $transport, array $afdelingen, ?float $budgetSeconds = null): array
{
    $started = microtime(true);
    $deadline = $started + ($budgetSeconds ?? DEMETER_STORE_SYNC_TIME_BUDGET_SECONDS);
    $stats = demeter_store_new_stats();
    demeter_store_apply_new_postings($store, $transport, $stats);
    demeter_store_apply_new_workorders($store, $transport, $stats);
    $counts = demeter_store_reconcile_counts($store, $transport, $afdelingen, $stats, $deadline);
    $stats['mismatched'] = $counts['mismatched'];
    $stats['verified'] = $counts['verified'];
    $stats['afdelingen'] = array_values(array_map('strval', $afdelingen));
    $stats['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
    $stats['at'] = time();
    // Niet volledig geverifieerd (tijdsbudget op): over ~1 min opnieuw proberen i.p.v. pas na 3 min.
    $store['synced_at'] = $stats['verified'] ? time() : time() - max(0, DEMETER_STORE_MAX_AGE_SECONDS - 60);
    if ($stats['verified']) {
        $store['verified_at'] = time();
    }
    $store['last_sync'] = $stats;

    return $stats;
}

/**
 * Pagina-open: alleen als de store ouder is dan 180 s. Eén schrijver onder lock; anderen wachten kort
 * (max $waitSeconds) en lezen daarna de verse versie. Bestaat er nog geen store, dan gebeurt er niets
 * (nightly bouwt hem).
 *
 * @return array{status:string,store:?array,stats:?array}
 */
function demeter_store_page_open_sync(string $company, string $afdeling, array $transport, float $waitSeconds = 10.0): array
{
    $store = demeter_store_read($company);
    if ($store === null) {
        return ['status' => 'no_store', 'store' => null, 'stats' => null];
    }
    if (time() - (int) ($store['synced_at'] ?? 0) <= DEMETER_STORE_MAX_AGE_SECONDS) {
        return ['status' => 'fresh', 'store' => $store, 'stats' => null];
    }
    $afdelingen = array_values(array_unique([$afdeling, '']));
    $result = demeter_store_with_lock($company, static function () use ($company, $transport, $afdelingen) {
        $current = demeter_store_read($company);
        if ($current === null) {
            return ['status' => 'no_store', 'store' => null, 'stats' => null];
        }
        if (time() - (int) ($current['synced_at'] ?? 0) <= DEMETER_STORE_MAX_AGE_SECONDS) {
            return ['status' => 'fresh_after_wait', 'store' => $current, 'stats' => null];
        }
        $stats = demeter_store_delta_sync($current, $transport, $afdelingen);
        demeter_store_write($company, $current);

        return ['status' => 'synced', 'store' => $current, 'stats' => $stats];
    }, $waitSeconds, $acquired);
    if ($result === null) {
        return ['status' => 'busy', 'store' => demeter_store_read($company) ?? $store, 'stats' => null];
    }

    return $result;
}

/** Hourly: alle niet-afgesloten werkorders opnieuw + Entry_No-delta. */
function demeter_store_hourly_refresh(string $company, array $transport, float $waitSeconds = 120.0): array
{
    $result = demeter_store_with_lock($company, static function () use ($company, $transport) {
        $store = demeter_store_read($company);
        if ($store === null) {
            return ['status' => 'no_store'];
        }
        $started = microtime(true);
        $stats = demeter_store_new_stats();
        demeter_store_apply_new_postings($store, $transport, $stats);
        $filter = implode(' and ', array_map(static function (string $s): string {
            return 'Status ne ' . demeter_store_q($s);
        }, demeter_store_closed_statuses()));
        $rows = $transport['fetch']('Werkorders', ['$select' => implode(',', demeter_store_workorder_fields()), '$filter' => $filter]);
        $stats['rows_fetched'] += count($rows);
        $seen = [];
        foreach ($rows as $row) {
            $seen[(string) ($row['No'] ?? '')] = true;
            demeter_store_upsert($store, $row);
        }
        $vanished = [];
        foreach ($store['workorders'] as $no => $row) {
            if (!demeter_store_status_is_closed((string) $row['Status']) && !isset($seen[(string) $no])) {
                $vanished[] = (string) $no;
            }
        }
        $found = demeter_store_fetch_by_nos($transport, $vanished);
        foreach ($vanished as $no) {
            if (isset($found[$no])) {
                demeter_store_upsert($store, $found[$no]);
            } else {
                unset($store['workorders'][$no]);
                $stats['deleted']++;
            }
        }
        $stats['upserted'] = count($rows) + count($found);
        $stats['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        $store['hourly_at'] = time();
        $store['synced_at'] = time();
        $store['last_hourly'] = $stats;
        demeter_store_write($company, $store);

        return ['status' => 'ok', 'stats' => $stats];
    }, $waitSeconds);

    return $result ?? ['status' => 'busy'];
}

/**
 * Nightly: volledige snapshot (alle werkorders + alle ProjectPosten), daarna verificatie met BC-counts per
 * afdeling × status. Mismatch (wijziging tijdens het ophalen) → één zoom-ronde, opnieuw tellen. Alleen bij
 * gelijke counts wordt de nieuwe versie geschreven; anders blijft de oude store staan.
 */
function demeter_store_nightly_snapshot(string $company, array $transport, ?callable $log = null, float $waitSeconds = 600.0): array
{
    $log = $log ?? static function (string $m): void {
    };
    $result = demeter_store_with_lock($company, static function () use ($company, $transport, $log) {
        $started = microtime(true);
        $new = demeter_store_empty($company);
        $old = demeter_store_read($company);
        if (is_array($old)) {
            $new['last_reconciliation'] = $old['last_reconciliation'] ?? null;
        }
        $rows = demeter_store_fetch_all_workorders($transport);
        foreach ($rows as $row) {
            demeter_store_upsert($new, $row);
        }
        $log(sprintf("  snapshot %s: %d werkorders (%.1fs)\n", $company, count($new['workorders']), microtime(true) - $started));
        $postings = demeter_store_fetch_all_postings($transport);
        usort($postings, static function (array $a, array $b): int {
            return (int) ($a['Entry_No'] ?? 0) <=> (int) ($b['Entry_No'] ?? 0);
        });
        foreach ($postings as $p) {
            demeter_store_apply_posting($new, $p);
        }
        $log(sprintf("  snapshot %s: %d projectposten, max Entry_No %d (%.1fs)\n", $company, count($postings), $new['max_entry_no'], microtime(true) - $started));
        $verification = demeter_store_verify_counts($new, $transport);
        if (!$verification['ok']) {
            $log("  counts wijken af, zoom-ronde: " . implode('; ', array_slice($verification['diffs'], 0, 10)) . "\n");
            $afdelingen = array_keys($verification['afdelingen']);
            demeter_store_delta_sync($new, $transport, $afdelingen, 300.0);
            $verification = demeter_store_verify_counts($new, $transport);
        }
        $new['last_verification'] = $verification;
        if (!$verification['ok']) {
            $log("  NIET ingewisseld: counts kloppen niet (" . implode('; ', array_slice($verification['diffs'], 0, 10)) . ")\n");

            return ['status' => 'verify_failed', 'verification' => $verification];
        }
        // Nauwkeurigheidsbewijs: de store zoals de delta/hourly hem bijhield (na een laatste delta-sync)
        // vergelijken met deze volledige, geverifieerde snapshot. Geen extra BC-fetch nodig.
        if (is_array($old) && function_exists('demeter_store_reconcile_compare')) {
            $oldSynced = $old;
            $afdelingenAll = array_keys($verification['afdelingen']);
            demeter_store_delta_sync($oldSynced, $transport, $afdelingenAll, 180.0);
            $rec = demeter_store_reconcile_compare($oldSynced, array_values($new['workorders']), $postings, null);
            $rec['kind'] = 'nightly_vs_previous_store';
            $rec['previous_store_synced_at'] = (int) ($old['synced_at'] ?? 0);
            foreach ($rec['per_afdeling'] as &$perAfd) {
                $perAfd['examples'] = array_slice($perAfd['examples'], 0, 5);
            }
            unset($perAfd);
            $new['last_reconciliation'] = $rec;
            $log('  ' . str_replace("\n  ", "\n    ", demeter_store_reconcile_summary($rec)));
        }
        $new['full_at'] = time();
        $new['synced_at'] = time();
        $new['hourly_at'] = time();
        $new['snapshot_ms'] = (int) round((microtime(true) - $started) * 1000);
        demeter_store_write($company, $new);
        $log(sprintf("  ingewisseld: %d werkorders, %d count-groepen gelijk (%.1fs)\n", count($new['workorders']), $verification['groups'], microtime(true) - $started));

        return ['status' => 'ok', 'verification' => $verification, 'workorders' => count($new['workorders'])];
    }, $waitSeconds);

    return $result ?? ['status' => 'busy'];
}

/**
 * Verificatie: BC-count per afdeling × status (afdelingen/statussen uit de store + standaardstatussen),
 * plus het bedrijfstotaal.
 */
function demeter_store_verify_counts(array $store, array $transport): array
{
    $afdelingen = [];
    foreach ($store['workorders'] as $row) {
        $afdelingen[(string) $row['Job_Dimension_1_Value']] = true;
    }
    $groups = demeter_store_groups_for($store, array_keys($afdelingen));
    $filters = array_map(static function (array $g): string {
        return demeter_store_group_filter($g[0], $g[1]);
    }, $groups);
    $bcCounts = isset($transport['count_many'])
        ? $transport['count_many']('Werkorders', $filters)
        : array_map(static function (string $f) use ($transport): int {
            return (int) $transport['count']('Werkorders', $f);
        }, $filters);
    $diffs = [];
    foreach ($groups as $i => [$afdeling, $status]) {
        $local = count(demeter_store_nos_in_group($store, $afdeling, $status));
        if ((int) $bcCounts[$i] !== $local) {
            $diffs[] = sprintf("%s/%s BC %d store %d", $afdeling === '' ? '(leeg)' : $afdeling, $status, $bcCounts[$i], $local);
        }
    }
    $total = (int) $transport['count']('Werkorders', '');
    if ($total !== count($store['workorders'])) {
        $diffs[] = sprintf('totaal BC %d store %d', $total, count($store['workorders']));
    }

    return ['ok' => $diffs === [], 'diffs' => $diffs, 'groups' => count($groups), 'bc_total' => $total, 'store_total' => count($store['workorders']), 'afdelingen' => $afdelingen, 'at' => time()];
}
