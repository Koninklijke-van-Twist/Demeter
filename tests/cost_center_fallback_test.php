<?php
/**
 * Werkorders met lege kop-kostenplaats vallen terug op de kostenplaats van hun ProjectPosten.
 * Fixture: Hunter van Twist WO52602422 / PRJ5262143 (BC, 24-09-2026): factuur -1711 en creditnota +1711, beide op kostenplaats 70.
 * Run: php tests/cost_center_fallback_test.php
 */

require_once __DIR__ . '/../web/bc_enum.php';
require_once __DIR__ . '/../web/project_finance.php';
require_once __DIR__ . '/../web/bc_fetch/workorder_state_cache.php';
require_once __DIR__ . '/../web/bc_fetch/cost_center.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$posten = [
    ['Job_No' => 'PRJ5262143', 'Job_Task_No' => 'WO52602422', 'LVS_Work_Order_No' => 'WO52602422', 'Posting_Date' => '2026-09-24', 'Entry_Type' => 'Sale', 'Type' => 'Item', 'No' => 'X', 'Description' => 'Creditnota', 'Total_Cost' => 0, 'Line_Amount_LCY' => 1711, 'Global_Dimension_1_Code' => '70'],
    ['Job_No' => 'PRJ5262143', 'Job_Task_No' => 'WO52602422', 'LVS_Work_Order_No' => 'WO52602422', 'Posting_Date' => '2026-09-24', 'Entry_Type' => 'Sale', 'Type' => 'Item', 'No' => 'X', 'Description' => 'Factuur', 'Total_Cost' => 0, 'Line_Amount_LCY' => -1711, 'Global_Dimension_1_Code' => '70'],
    ['Job_No' => 'PRJ9', 'Job_Task_No' => 'T1', 'LVS_Work_Order_No' => 'WO9', 'Posting_Date' => '2026-09-24', 'Entry_Type' => 'Usage', 'Total_Cost' => 10, 'Global_Dimension_1_Code' => '15'],
];
$workorders = [
    ['No' => 'WO52602422', 'Job_No' => 'PRJ5262143', 'Job_Task_No' => 'WO52602422', 'Job_Dimension_1_Value' => ''],
    ['No' => 'WO9', 'Job_No' => 'PRJ9', 'Job_Task_No' => 'T1', 'Job_Dimension_1_Value' => ''],
    ['No' => 'WO70', 'Job_No' => 'PRJ70', 'Job_Task_No' => 'T', 'Job_Dimension_1_Value' => '70'],
    ['No' => 'WOKOP15', 'Job_No' => 'PRJ5262143', 'Job_Task_No' => 'T2', 'Job_Dimension_1_Value' => '15'],
    ['No' => 'WOLEEG', 'Job_No' => 'PRJ0', 'Job_Task_No' => 'T', 'Job_Dimension_1_Value' => ''],
];

$under70 = bc_fetch_filter_workorders_for_cost_center($workorders, $posten, '70');
$nos = array_column($under70, 'No');
check(in_array('WO52602422', $nos, true), 'WO52602422 (lege kop, posten 70) verschijnt onder 70');
check(in_array('WO70', $nos, true), 'kop 70 blijft onder 70');
check(!in_array('WOKOP15', $nos, true), 'kop 15 blijft strikt buiten 70, ook met posten op 70');
check(!in_array('WO9', $nos, true) && !in_array('WOLEEG', $nos, true), 'lege kop zonder posten op 70 valt weg');
$hunter = $under70[array_search('WO52602422', $nos, true)];
check($hunter['Job_Dimension_1_Value'] === '70' && $hunter['Cost_Center_Source'] === 'projectposten', 'kostenplaats 70 overgenomen uit posten');

$under15 = array_column(bc_fetch_filter_workorders_for_cost_center($workorders, $posten, '15'), 'No');
check(in_array('WO9', $under15, true) && in_array('WOKOP15', $under15, true) && !in_array('WO52602422', $under15, true), 'filter 15 correct');

$deferred = array_column(bc_fetch_filter_workorders_for_cost_center($workorders, [], '70', true), 'No');
check(in_array('WOLEEG', $deferred, true) && !in_array('WOKOP15', $deferred, true), 'uitgesteld: lege kop blijft, andere kop valt weg');

$none = array_column(bc_fetch_filter_workorders_for_cost_center($workorders, $posten, bc_fetch_cost_center_none_value()), 'No');
check(in_array('WO52602422', $none, true) && !in_array('WO70', $none, true), "filter 'geen kostenplaats' blijft op de kop");

// Opbrengst nett 0 (factuur -1711 + creditnota +1711).
$service = (new ReflectionClass(ProjectFinanceService::class))->newInstanceWithoutConstructor();
$finance = $service->aggregateProjectAndWorkorderFinanceFromProjectPostenRows(array_slice($posten, 0, 2));
$wo = $finance['workorder_totals_by_project_and_number']['prj5262143|wo52602422'] ?? null;
check(is_array($wo) && abs((float) $wo['revenue']) < 0.005 && abs((float) $wo['costs']) < 0.005, 'WO52602422 opbrengst/kosten netto 0');

exit($failures > 0 ? 1 : 0);
