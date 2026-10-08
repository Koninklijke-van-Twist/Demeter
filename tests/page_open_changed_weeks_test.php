<?php
/**
 * Page-open BC-delta voor de schermcache (gaten 'achteraf gedateerd' en 'status oude week'):
 * - WO2610905: vandaag aangemaakt met startdatum 02-10 (een al gesloten week) -> die week wordt 'opnieuw laden'
 *   en de hervat-logica leest hem opnieuw (werkorder daarna in beeld);
 * - een post met een boekdatum in het verleden -> de week van die post wordt 'opnieuw laden';
 * - een statuswijziging van een werkorder in een oude week -> direct in de display-rij, zonder weekherlading;
 * - eerste keer alleen checkpoint; < 180 s later niets (geen polling); timeout/409 -> niets gewijzigd, checkpoint
 *   blijft staan; onvolledige cache (herbouw) -> niets.
 * Run: php tests/page_open_changed_weeks_test.php
 */

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x'];
$auth_list = ['Production' => $auth];

$GLOBALS['FAKE_WO'] = [];
$GLOBALS['ODATA_CALLS'] = 0;
$GLOBALS['DEMETER_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl): array {
    $GLOBALS['ODATA_CALLS']++;
    $decoded = rawurldecode($url);
    if (strpos($decoded, '/Werkorders?') !== false && strpos($decoded, 'Start_Date ge') !== false) {
        return $GLOBALS['FAKE_WO'];
    }

    return [];
};

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

function wo_row(string $no, string $start, string $cc, string $status): array
{
    return [
        'No' => $no, 'Task_Code' => 'SM01', 'Task_Description' => 'Taak', 'Status' => $status,
        'KVT_Document_Status' => '10-OPEN', 'Job_No' => 'PRJ' . $no, 'Job_Task_No' => $no, 'Contract_No' => '',
        'Start_Date' => $start, 'End_Date' => $start, 'Sub_Entity_Description' => '', 'Component_No' => '',
        'Bill_to_Customer_No' => 'C1', 'Bill_to_Name' => 'Klant', 'Sell_to_Customer_No' => 'C1', 'Sell_to_Name' => 'Klant',
        'Job_Dimension_1_Value' => $cc, 'Created_Date_Time' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
}

// ---- 1) Pure toepassing met een vaste datum (scenario van 08-10-2026) ----
$today = new DateTimeImmutable('2026-10-08');
$scan = demeter_workorder_month_scan_defaults();
foreach (['2026-W36', '2026-W38', '2026-W40', '2026-W41'] as $w) {
    $scan['months'][$w] = ['scanned_at' => '2026-10-08T05:00:00+00:00', 'empty' => false, 'row_keys' => []];
}
$display = [
    'PRJWOSTATUS|WOSTATUS' => ['Row_Key' => 'PRJWOSTATUS|WOSTATUS', 'Bc_No' => 'WOSTATUS', 'No' => 'WOSTATUS', 'Job_No' => 'PRJWOSTATUS', 'Start_Date' => '2026-09-02', 'Status' => 'Checked', 'Document_Status' => '10-OPEN'],
    'PRJWOPOST|WOPOST' => ['Row_Key' => 'PRJWOPOST|WOPOST', 'Bc_No' => 'WOPOST', 'No' => 'WOPOST', 'Job_No' => 'PRJWOPOST', 'Start_Date' => '2026-09-14', 'Status' => 'Open', 'Document_Status' => '10-OPEN'],
];
$fetched = [
    'init' => false,
    'postings' => [
        ['Entry_No' => 1001, 'Posting_Date' => '2026-09-16', 'LVS_Work_Order_No' => 'WOPOST', 'Job_No' => 'PRJWOPOST', 'Global_Dimension_1_Code' => '', 'LVS_Global_Dimension_1_Code' => ''],
        ['Entry_No' => 1002, 'Posting_Date' => '2026-10-08', 'LVS_Work_Order_No' => 'WOPOST', 'Job_No' => 'PRJWOPOST', 'Global_Dimension_1_Code' => '97'],
        ['Entry_No' => 1003, 'Posting_Date' => '2026-09-02', 'LVS_Work_Order_No' => 'WOANDER', 'Job_No' => 'PRJX', 'Global_Dimension_1_Code' => '15'],
    ],
    'changes' => ['WOSTATUS', 'WO2610905'],
    'workorders' => [
        wo_row('WO2610905', '2026-10-02', '97', 'Open'),
        wo_row('WOSTATUS', '2026-09-02', '97', 'Closed'),
        wo_row('WOAFD15', '2026-09-30', '15', 'Open'),
    ],
];
$applied = demeter_workorder_delta_apply($fetched, $display, $scan, '97', $today);
check(($applied['dirty']['2026-W40'] ?? '') === 'werkorder', 'WO2610905 (vandaag aangemaakt, start 02-10) markeert gesloten week 2026-W40');
check(($applied['dirty']['2026-W38'] ?? '') === 'post', 'post met boekdatum 16-09 markeert week 2026-W38');
check(!isset($applied['dirty']['2026-W36']), 'statuswijziging in oude week markeert geen week (direct bijgewerkt); post van andere afdeling ook niet');
check(!isset($applied['dirty']['2026-W41']), 'post van vandaag: geen herlading (catch-up leest vandaag)');
check(($applied['display']['PRJWOSTATUS|WOSTATUS']['Status'] ?? '') === 'Closed' && $applied['status_updates'] === 1, 'status van WOSTATUS direct bijgewerkt naar Closed');
check(count($applied['dirty']) === 2, 'werkorder van een andere afdeling markeert niets (' . implode(',', array_keys($applied['dirty'])) . ')');

// ---- 2) End-to-end page-open met fake transport en echte cachebestanden ----
$company = 'Testbedrijf delta ' . bin2hex(random_bytes(3));
$cc = '97';
$now = time();
$todayReal = new DateTimeImmutable('today');
$closedDay = $todayReal->modify('-7 days')->format('Y-m-d');
$closedWeek = demeter_iso_year_week_from_date(new DateTimeImmutable($closedDay));
$oldDay = $todayReal->modify('-35 days')->format('Y-m-d');
$oldWeek = demeter_iso_year_week_from_date(new DateTimeImmutable($oldDay));
$postDay = $todayReal->modify('-21 days')->format('Y-m-d');
$postWeek = demeter_iso_year_week_from_date(new DateTimeImmutable($postDay));

$scan = demeter_workorder_month_scan_defaults();
foreach ([$closedWeek, $oldWeek, $postWeek] as $w) {
    $scan['months'][$w] = ['scanned_at' => gmdate('c', $now - 7200), 'has_projectposten' => true, 'empty' => false, 'only_closed_cached' => false, 'row_keys' => []];
}
$scan['months'][$oldWeek]['row_keys'] = ['PRJWOSTATUS|WOSTATUS'];
$scan['consecutive_empty'] = 52;
$scan['stop_before_month'] = '2024-W01';
demeter_workorder_state_cache_save($company, $cc, [], $scan, demeter_workorder_load_session_defaults());
demeter_workorder_state_cache_save_display_rows($company, $cc, [
    'PRJWOSTATUS|WOSTATUS' => ['Row_Key' => 'PRJWOSTATUS|WOSTATUS', 'Bc_No' => 'WOSTATUS', 'No' => 'WOSTATUS', 'Job_No' => 'PRJWOSTATUS', 'Start_Date' => $oldDay, 'Status' => 'Checked', 'Document_Status' => '10-OPEN', 'Cost_Center' => $cc],
]);

$calls = [];
$mode = 'ok';
$transport = static function () use (&$calls, &$mode, $closedDay, $oldDay, $postDay, $cc): array {
    return ['fetch' => static function (string $entity, array $query) use (&$calls, &$mode, $closedDay, $oldDay, $postDay, $cc): array {
        $calls[] = $entity . ' ' . ($query['$filter'] ?? '') . ' ' . ($query['$top'] ?? '');
        if ($mode === 'abort') {
            throw new DemeterStoreSyncAbort('HTTP 409 van BC');
        }
        $filter = (string) ($query['$filter'] ?? '');
        if (($query['$top'] ?? '') === '1') {
            return $entity === 'ProjectPosten' ? [['Entry_No' => 1000]] : [['Entry_No' => 500]];
        }
        if ($entity === 'ProjectPosten') {
            return [['Entry_No' => 1001, 'Posting_Date' => $postDay, 'LVS_Work_Order_No' => 'WOSTATUS', 'Job_No' => 'PRJWOSTATUS', 'Global_Dimension_1_Code' => $cc]];
        }
        if ($entity === 'ChangeLogEntries') {
            return [
                ['Entry_No' => 501, 'Primary_Key_Field_1_Value' => 'WOSTATUS', 'Field_Caption' => 'Status', 'Type_of_Change' => 'Modification'],
                ['Entry_No' => 502, 'Primary_Key_Field_1_Value' => 'WO2610905', 'Field_Caption' => 'Status', 'Type_of_Change' => 'Insertion'],
                ['Entry_No' => 503, 'Primary_Key_Field_1_Value' => 'WOSTATUS', 'Field_Caption' => 'Resource No.', 'Type_of_Change' => 'Modification'],
            ];
        }
        if ($entity === 'Werkorders' && strpos($filter, 'Created_Date_Time gt') !== false) {
            return [wo_row('WO2610905', $closedDay, $cc, 'Open')];
        }
        if ($entity === 'Werkorders' && strpos($filter, "No eq 'WOSTATUS'") !== false) {
            return [wo_row('WOSTATUS', $oldDay, $cc, 'Closed')];
        }

        return [];
    }];
};

$r = demeter_workorder_delta_page_open($company, $cc, $transport, $now);
check($r['status'] === 'initialized' && $r['dirty_weeks'] === [], 'eerste keer: alleen checkpoint (' . $r['status'] . ')');
$cp = demeter_workorder_delta_read($company, $cc);
check($cp['posten_entry_no'] === 1000 && $cp['changelog_entry_no'] === 500, 'checkpoint gezet op hoogste Entry_No (posten 1000, logboek 500)');

$calls = [];
$r = demeter_workorder_delta_page_open($company, $cc, $transport, $now + 60);
check($r['status'] === 'fresh' && $calls === [], 'binnen 180 s: geen BC-call (geen polling)');

$calls = [];
$mode = 'abort';
$r = demeter_workorder_delta_page_open($company, $cc, $transport, $now + 400);
$cp2 = demeter_workorder_delta_read($company, $cc);
check($r['status'] === 'aborted' && $cp2['posten_entry_no'] === 1000 && (int) $cp2['synced_at'] === $now, '409/timeout: niets gewijzigd, checkpoint blijft, volgende open opnieuw');

$mode = 'ok';
$calls = [];
$r = demeter_workorder_delta_page_open($company, $cc, $transport, $now + 400);
check($r['status'] === 'synced', 'delta-sync geslaagd (' . $r['status'] . ')');
check(in_array($closedWeek, $r['dirty_weeks'], true), 'WO2610905-scenario: gesloten week ' . $closedWeek . ' staat op opnieuw laden');
check(in_array($postWeek, $r['dirty_weeks'], true), 'post met datum in het verleden: week ' . $postWeek . ' staat op opnieuw laden');
check(!in_array($oldWeek, $r['dirty_weeks'], true), 'statuswijziging oude week: geen weekherlading');
$display = demeter_workorder_state_cache_load_display_rows($company, $cc);
check(($display['PRJWOSTATUS|WOSTATUS']['Status'] ?? '') === 'Closed' && $r['status_updates'] === 1 && $r['rows_changed'] === true, 'statuswijziging oude week direct in de display-rij (Checked -> Closed)');
$cp3 = demeter_workorder_delta_read($company, $cc);
check($cp3['posten_entry_no'] === 1001 && $cp3['changelog_entry_no'] === 503, 'checkpoint vooruit (posten 1001, logboek 503)');
check(count($calls) <= 5, 'licht: ' . count($calls) . ' BC-calls');

// ---- 3) De hervat-logica leest alleen de gemarkeerde weken opnieuw ----
$GLOBALS['ODATA_CALLS'] = 0;
$skip = bc_fetch_load_workorder_week_chunk($company, $oldWeek, $auth, 0, null, ['cost_center' => $cc, 'force_full' => true, 'resume_skip_scanned' => true]);
check(!empty($skip['skipped']) && $GLOBALS['ODATA_CALLS'] === 0, 'niet-gemarkeerde week: overgeslagen zonder BC-call');
$GLOBALS['FAKE_WO'] = [wo_row('WO2610905', $closedDay, $cc, 'Open')];
$chunk = bc_fetch_load_workorder_week_chunk($company, $closedWeek, $auth, 0, null, ['cost_center' => $cc, 'force_full' => true, 'resume_skip_scanned' => true]);
check(empty($chunk['skipped']), 'gemarkeerde week ' . $closedWeek . ' wordt opnieuw uit BC gelezen');
$nos = array_map(static function ($row) { return $row['Bc_No'] ?? ''; }, array_values(demeter_workorder_state_cache_load_display_rows($company, $cc)));
check(in_array('WO2610905', $nos, true), 'WO2610905 staat daarna in beeld');
check(!demeter_workorder_delta_week_is_dirty($company, $cc, $closedWeek), 'na een geslaagde herlading is de week niet meer gemarkeerd');
check(demeter_workorder_delta_week_is_dirty($company, $cc, $postWeek), 'andere gemarkeerde week blijft staan tot hij gelezen is');

// ---- 4) Onvolledige cache (herbouw loopt): niets doen ----
$company2 = $company . ' herbouw';
$scan2 = demeter_workorder_month_scan_defaults();
$scan2['months'][$closedWeek] = ['scanned_at' => gmdate('c'), 'empty' => false, 'row_keys' => []];
demeter_workorder_state_cache_save($company2, $cc, [], $scan2, demeter_workorder_load_session_defaults());
$calls = [];
$r = demeter_workorder_delta_page_open($company2, $cc, $transport, $now);
check($r['status'] === 'skipped' && $calls === [], 'onvolledige cache (herbouw): geen delta');

foreach ([$company, $company2] as $c) {
    demeter_workorder_state_cache_purge($c, $cc);
    @unlink(demeter_workorder_delta_path($c, $cc));
    @unlink(demeter_workorder_delta_path($c, $cc) . '.lock');
    @unlink(demeter_invoice_cache_path($c));
}
exit($failures > 0 ? 1 : 0);
