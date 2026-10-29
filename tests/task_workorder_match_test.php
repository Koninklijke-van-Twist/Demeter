<?php
/**
 * KvT/70 (29-10-2026): posten zonder LVS_Work_Order_No (inkoop-/verkoopfacturen) tellen bij de werkorder
 * als Job_Task_No = Werkorders.No van hetzelfde project. LVS gevuld = nooit ook op de taak; ander project
 * of onbekende taak = alleen projecttotaal. IMPORT SAP met taak = werkorder telt bij die werkorder (nooit ook als pseudo-rij).
 * Run: php tests/task_workorder_match_test.php
 */
$GLOBALS['baseUrl'] = 'https://bc.example:7148/';
$GLOBALS['environment'] = 'Production';
$GLOBALS['auth'] = ['mode' => 'basic', 'user' => 'x', 'pass' => 'x'];
function odata_mimir_enabled(): bool { return false; }
function odata_get_all(string $url, array $auth, $ttl = 300): array { return []; }
require_once __DIR__ . '/../web/bc_enum.php';
require_once __DIR__ . '/../web/project_finance.php';
require_once __DIR__ . '/../web/bc_fetch/workorder_state_cache.php';
require_once __DIR__ . '/../web/bc_fetch/cost_center.php';
require_once __DIR__ . '/../web/bc_fetch/odata_select.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$p = static function (string $job, string $task, string $lvs, string $type, float $amount, string $desc = 'x'): array {
    return ['Job_No' => $job, 'Job_Task_No' => $task, 'LVS_Work_Order_No' => $lvs, 'Entry_Type' => $type, 'Posting_Date' => '2026-05-01',
        'Type' => 'Item', 'No' => 'I', 'Description' => $desc,
        'Total_Cost' => $type === 'Usage' ? $amount : 0.0, 'Line_Amount_LCY' => $type === 'Sale' ? -$amount : 0.0];
};
$workorders = [
    ['No' => 'WO1', 'Job_No' => 'PRJ1', 'Job_Task_No' => 'T1'],
    ['No' => 'WO2', 'Job_No' => 'PRJ1', 'Job_Task_No' => 'T1'],
];
$posten = [
    $p('PRJ1', 'T1', 'WO1', 'Usage', 10),          // LVS
    $p('PRJ1', 'WO1', '', 'Usage', 100),           // inkoopfactuur: taak = WO1
    $p('PRJ1', 'WO1', '', 'Sale', 50),             // verkoopfactuur: taak = WO1
    $p('PRJ1', 'WO1', 'WO2', 'Usage', 7),          // LVS gevuld: nooit op de taak
    $p('PRJ2', 'WO1', '', 'Usage', 1000),          // ander project: niet koppelen
    $p('PRJ1', 'T1', '', 'Usage', 3),              // taak = gedeelde Job_Task_No: niet koppelen
    $p('PRJ1', 'WO1', '', 'Usage', 20, 'IMPORT SAP ARBEID JAAR 2025'), // IMPORT SAP met taak = WO1
    $p('PRJ1', '', '', 'Usage', 5, 'IMPORT SAP MAT JAAR 2025'),        // IMPORT SAP zonder taak: pseudo-rij
];
$svc = new ProjectFinanceService('Testbedrijf');
$keys = bc_fetch_task_workorder_keys($workorders);
$filtered = bc_fetch_filter_projectposten_rows_for_workorders($posten, $workorders);
$r = $svc->aggregateProjectAndWorkorderFinanceFromProjectPostenRows($filtered, $keys);
$t = $r['workorder_totals_by_project_and_number'];
check(abs(($t['prj1|wo1']['costs'] ?? 0) - 130.0) < 0.001, 'WO1 kosten = LVS 10 + factuur 100 + IMPORT SAP 20: ' . ($t['prj1|wo1']['costs'] ?? 0));
check(abs(($t['prj1|wo1']['revenue'] ?? 0) - 50.0) < 0.001, 'WO1 opbrengst = verkoopfactuur 50: ' . ($t['prj1|wo1']['revenue'] ?? 0));
check(abs(($t['prj1|wo2']['costs'] ?? 0) - 7.0) < 0.001, 'LVS gevuld telt alleen bij LVS-werkorder (WO2 = 7)');
check(!isset($t['prj2|wo1']), 'taak van ander project niet gekoppeld');
check(!isset($t['prj1|t1']), 'gedeelde Job_Task_No niet gekoppeld');
check(count($r['import_sap_workorder_rows']) === 1, 'alleen IMPORT SAP zonder werkorder blijft pseudo-rij: ' . count($r['import_sap_workorder_rows']));
$old = $svc->aggregateProjectAndWorkorderFinanceFromProjectPostenRows($filtered);
check(abs(($old['workorder_totals_by_project_and_number']['prj1|wo1']['costs'] ?? 0) - 10.0) < 0.001, 'zonder sleutels: oud gedrag (alleen LVS)');
check(strpos(bc_fetch_werkorders_list_select(), 'Memo_Internal_Use_Only') !== false && strpos(bc_fetch_werkorders_list_select(), 'Memo_Invoice') !== false, "memo-velden zitten in de werkorder-query");
check(demeter_status_bc_dutch_label('Closed') === 'Afgesloten' && demeter_status_bc_dutch_label('Invoiced') === 'Gefactureerd' && demeter_status_bc_dutch_label('In Progress') === 'Onderhanden' && demeter_status_bc_dutch_label('Gepland') === 'Gepland', 'status EN -> NL zoals BC');
check(demeter_status_bc_dutch_label('Geen werkorder') === 'Geen werkorder', 'onbekende status ongewijzigd');

exit($failures === 0 ? 0 : 1);
