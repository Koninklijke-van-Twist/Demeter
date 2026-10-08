<?php
/**
 * Page-open-delta na een herbouw (KvT/70, 08-10-2026): statussen/documentstatus die in BC wijzigden NA de
 * weekscan maar VOOR de eerste delta-sync, en werkorders die in die periode zijn aangemaakt (WO2610905),
 * moeten alsnog binnenkomen. Het oude gedrag zette bij de eerste sync het checkpoint op 'nu' en miste ze.
 * - eerste sync: logboek vanaf de oudste weekscan (5 min marge), Created_Date_Time ook vanaf daar;
 * - bestaand checkpoint (al op 'nu' gezet, zonder backfill_version): één keer opnieuw terugkijken;
 * - terugkijken begrensd op 48 uur; daarna gewoon Entry_No > checkpoint.
 * Run: php tests/delta_backfill_since_scan_test.php
 */

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x'];
$auth_list = ['Production' => $auth];
$GLOBALS['DEMETER_ODATA_BC_FETCH'] = static function (): array { return []; };

require_once __DIR__ . '/../web/odata.php';
require_once __DIR__ . '/../web/bc_enum.php';
require_once __DIR__ . '/../web/project_finance.php';
require_once __DIR__ . '/../web/workorder_rows.php';
require_once __DIR__ . '/../web/bc_fetch/month_loader.php';
require_once __DIR__ . '/../web/bc_fetch/workorder_delta.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$cc = '70';
$now = time();
$iso = static function (int $ts): string { return gmdate('Y-m-d\TH:i:s\Z', $ts); };
$today = new DateTimeImmutable('today');
$statusDay = $today->modify('-21 days')->format('Y-m-d');
$statusWeek = demeter_iso_year_week_from_date(new DateTimeImmutable($statusDay));
$newDay = $today->modify('-6 days')->format('Y-m-d');
$newWeek = demeter_iso_year_week_from_date(new DateTimeImmutable($newDay));

function wo(string $no, string $start, string $cc, string $status, string $doc, int $created): array
{
    return [
        'No' => $no, 'Task_Code' => 'SM01', 'Task_Description' => 'Taak', 'Status' => $status,
        'KVT_Document_Status' => $doc, 'Job_No' => 'PRJ' . $no, 'Job_Task_No' => $no, 'Contract_No' => '',
        'Start_Date' => $start, 'End_Date' => $start, 'Sub_Entity_Description' => '', 'Component_No' => '',
        'Bill_to_Customer_No' => 'C1', 'Bill_to_Name' => 'Klant', 'Sell_to_Customer_No' => 'C1', 'Sell_to_Name' => 'Klant',
        'Job_Dimension_1_Value' => $cc, 'Created_Date_Time' => gmdate('Y-m-d\TH:i:s\Z', $created),
    ];
}

// BC: logboek van tabel 11332939 met tijden; werkorders met aanmaaktijd.
$changelog = [
    ['Entry_No' => 7142000, 'ts' => $now - 6 * 3600, 'Primary_Key_Field_1_Value' => 'WOVOORSCAN', 'Field_Caption' => 'Status', 'Type_of_Change' => 'Modification'],
    ['Entry_No' => 7154569, 'ts' => $now - 2 * 3600, 'Primary_Key_Field_1_Value' => 'WO2609608', 'Field_Caption' => 'Status', 'Type_of_Change' => 'Modification'],
    ['Entry_No' => 7154600, 'ts' => $now - 2 * 3600 + 60, 'Primary_Key_Field_1_Value' => 'WO2609700', 'Field_Caption' => 'Document Status', 'Type_of_Change' => 'Modification'],
    ['Entry_No' => 7155000, 'ts' => $now - 3600, 'Primary_Key_Field_1_Value' => 'WOANDERAFD', 'Field_Caption' => 'Status', 'Type_of_Change' => 'Modification'],
    ['Entry_No' => 7158700, 'ts' => $now - 1800, 'Primary_Key_Field_1_Value' => 'WO2610905', 'Field_Caption' => 'No.', 'Type_of_Change' => 'Insertion'],
];
$workorders = [
    'WOVOORSCAN' => wo('WOVOORSCAN', $statusDay, $cc, 'Closed', '10-OPEN', $now - 90 * 86400),
    'WO2609608' => wo('WO2609608', $statusDay, $cc, 'Completed', '10-OPEN', $now - 40 * 86400),
    'WO2609700' => wo('WO2609700', $statusDay, $cc, 'Open', '30-GEFACTUREERD', $now - 40 * 86400),
    'WO2610905' => wo('WO2610905', $newDay, $cc, 'Open', '10-OPEN', $now - 1800),
];

$calls = [];
$transport = static function () use (&$calls, $changelog, $workorders, $iso): array {
    return ['fetch' => static function (string $entity, array $query) use (&$calls, $changelog, $workorders, $iso): array {
        $filter = (string) ($query['$filter'] ?? '');
        $calls[] = $entity . ' ' . $filter . ' ' . ($query['$orderby'] ?? '') . ' ' . ($query['$top'] ?? '');
        if ($entity === 'ProjectPosten') {
            return ($query['$top'] ?? '') === '1' ? [['Entry_No' => 55000]] : [];
        }
        if ($entity === 'ChangeLogEntries') {
            $rows = $changelog;
            if (preg_match('/Date_and_Time ge (\S+)/', $filter, $m)) {
                $ge = strtotime($m[1]);
                $rows = array_values(array_filter($rows, static function ($r) use ($ge) { return $r['ts'] >= $ge; }));
            }
            if (preg_match('/Entry_No gt (\d+)/', $filter, $m)) {
                $gt = (int) $m[1];
                $rows = array_values(array_filter($rows, static function ($r) use ($gt) { return $r['Entry_No'] > $gt; }));
            }
            if (($query['$orderby'] ?? '') === 'Entry_No desc') {
                $rows = array_reverse($rows);
            }
            if (($query['$top'] ?? '') === '1') {
                $rows = array_slice($rows, 0, 1);
            }
            return array_map(static function ($r) use ($iso) { $r['Date_and_Time'] = $iso($r['ts']); unset($r['ts']); return $r; }, $rows);
        }
        if ($entity === 'Werkorders') {
            if (preg_match('/Created_Date_Time gt (\S+)/', $filter, $m)) {
                $gt = strtotime($m[1]);
                return array_values(array_filter($workorders, static function ($w) use ($gt) { return strtotime($w['Created_Date_Time']) > $gt; }));
            }
            preg_match_all("/No eq '([^']+)'/", $filter, $m);
            return array_values(array_intersect_key($workorders, array_flip($m[1])));
        }
        return [];
    }];
};

function setup_cache(string $company, string $cc, array $weeks, array $display): void
{
    $scan = demeter_workorder_month_scan_defaults();
    foreach ($weeks as $w => $scannedAt) {
        $scan['months'][$w] = ['scanned_at' => $scannedAt, 'has_projectposten' => true, 'empty' => false, 'only_closed_cached' => false, 'row_keys' => []];
    }
    $scan['consecutive_empty'] = 52;
    $scan['stop_before_month'] = '2024-W01';
    demeter_workorder_state_cache_save($company, $cc, [], $scan, demeter_workorder_load_session_defaults());
    demeter_workorder_state_cache_save_display_rows($company, $cc, $display);
}

$displayRows = static function () use ($statusDay, $cc): array {
    return [
        'PRJWO2609608|WO2609608' => ['Row_Key' => 'PRJWO2609608|WO2609608', 'Bc_No' => 'WO2609608', 'No' => 'WO2609608', 'Job_No' => 'PRJWO2609608', 'Start_Date' => $statusDay, 'Status' => 'Checked', 'Document_Status' => '10-OPEN', 'Cost_Center' => $cc],
        'PRJWO2609700|WO2609700' => ['Row_Key' => 'PRJWO2609700|WO2609700', 'Bc_No' => 'WO2609700', 'No' => 'WO2609700', 'Job_No' => 'PRJWO2609700', 'Start_Date' => $statusDay, 'Status' => 'Open', 'Document_Status' => '10-OPEN', 'Cost_Center' => $cc],
        'PRJWOVOORSCAN|WOVOORSCAN' => ['Row_Key' => 'PRJWOVOORSCAN|WOVOORSCAN', 'Bc_No' => 'WOVOORSCAN', 'No' => 'WOVOORSCAN', 'Job_No' => 'PRJWOVOORSCAN', 'Start_Date' => $statusDay, 'Status' => 'Closed', 'Document_Status' => '10-OPEN', 'Cost_Center' => $cc],
    ];
};

$weeks = [$statusWeek => $iso($now - 3 * 3600), $newWeek => $iso($now - 2400)];

// ---- 1) Eerste sync na een herbouw ----
$company = 'Testbedrijf backfill ' . bin2hex(random_bytes(3));
setup_cache($company, $cc, $weeks, $displayRows());
$r = demeter_workorder_delta_page_open($company, $cc, $transport, $now);
$display = demeter_workorder_state_cache_load_display_rows($company, $cc);
check(($display['PRJWO2609608|WO2609608']['Status'] ?? '') === 'Completed', 'status gewijzigd na de weekscan (WO2609608) komt binnen bij de eerste sync');
check(($display['PRJWO2609700|WO2609700']['Document_Status'] ?? '') === '30-GEFACTUREERD', 'documentstatus gewijzigd na de weekscan (WO2609700) komt binnen');
check(in_array($newWeek, $r['dirty_weeks'], true), 'WO2610905 (aangemaakt na de weekscan, start ' . $newDay . ') zet week ' . $newWeek . ' op opnieuw laden');
check(strpos(implode("\n", $calls), "'WOVOORSCAN'") === false, 'wijziging van vóór de oudste weekscan wordt niet opgehaald');
check(strpos(implode("\n", $calls), "'WOANDERAFD'") === false, 'statuswijziging van een werkorder die niet in beeld staat (andere afdeling): geen call');
$cp = demeter_workorder_delta_read($company, $cc);
check((int) $cp['changelog_entry_no'] === 7158700 && (int) $cp['posten_entry_no'] === 55000 && (int) ($cp['backfill_version'] ?? 0) === DEMETER_WORKORDER_DELTA_BACKFILL_VERSION, 'checkpoint na terugkijken: logboek 7158700, posten 55000, backfill_version gezet');

$calls = [];
$r2 = demeter_workorder_delta_page_open($company, $cc, $transport, $now + 400);
$joined = implode("\n", $calls);
check($r2['status'] === 'synced' && strpos($joined, 'Date_and_Time ge') === false && strpos($joined, 'Entry_No gt 7158700') !== false, 'volgende sync: gewoon Entry_No > checkpoint, geen terugkijken');

// ---- 2) Bestaand checkpoint uit productie (eerste sync zette het op 'nu', zonder backfill_version) ----
$company2 = $company . ' bestaand';
setup_cache($company2, $cc, $weeks, $displayRows());
demeter_workorder_delta_write($company2, $cc, array_replace(demeter_workorder_delta_defaults(), [
    'posten_entry_no' => 55000, 'changelog_entry_no' => 7158700, 'created_since' => $iso($now - 600), 'synced_at' => $now - 600,
]));
$calls = [];
$r = demeter_workorder_delta_page_open($company2, $cc, $transport, $now);
$display = demeter_workorder_state_cache_load_display_rows($company2, $cc);
check(($display['PRJWO2609608|WO2609608']['Status'] ?? '') === 'Completed' && ($display['PRJWO2609700|WO2609700']['Document_Status'] ?? '') === '30-GEFACTUREERD', 'bestaand checkpoint: één keer terugkijken herstelt status en documentstatus');
check(in_array($newWeek, $r['dirty_weeks'], true), 'bestaand checkpoint: WO2610905 alsnog gevonden');
$cp = demeter_workorder_delta_read($company2, $cc);
check((int) $cp['changelog_entry_no'] === 7158700 && (int) ($cp['backfill_version'] ?? 0) === DEMETER_WORKORDER_DELTA_BACKFILL_VERSION, 'bestaand checkpoint: daarna weer op 7158700 met backfill_version');

// ---- 3) Begrenzing op 48 uur ----
$scan = ['months' => ['2026-W01' => ['scanned_at' => $iso($now - 10 * 86400)], '2026-W02' => ['scanned_at' => $iso($now - 3600)]]];
check(demeter_workorder_delta_backfill_since($scan, $now) === $iso($now - 48 * 3600), 'terugkijken begrensd op 48 uur');
check(demeter_workorder_delta_backfill_since(['months' => ['2026-W02' => ['scanned_at' => $iso($now - 3600)]]], $now) === $iso($now - 3600 - 300), 'oudste weekscan min 5 minuten marge');
check(demeter_workorder_delta_backfill_since(['months' => []], $now) === null, 'zonder weekscans: geen terugkijken');

foreach ([$company, $company2] as $c) {
    demeter_workorder_state_cache_purge($c, $cc);
    @unlink(demeter_workorder_delta_path($c, $cc));
    @unlink(demeter_workorder_delta_path($c, $cc) . '.lock');
    @unlink(demeter_invoice_cache_path($c));
}
exit($failures > 0 ? 1 : 0);
