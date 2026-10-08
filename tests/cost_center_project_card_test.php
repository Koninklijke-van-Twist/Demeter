<?php
/**
 * Werkorder met lege kop en zonder kostenplaats uit eigen posten valt terug op de projectkaart
 * (Projecten.LVS_Global_Dimension_1_Code).
 * Fixture (BC, 08-10-2026): WO2605966 / PRJ2605222 en WO2610899 / PRJ2608515: kop leeg, geen
 * ProjectPosten, projectkaart kostenplaats 70.
 * Run: php tests/cost_center_project_card_test.php
 */

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['DEMETER_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = ['url' => rawurldecode($url), 'ttl' => $ttl];
    if (strpos($url, '/Projecten') === false) {
        return [];
    }
    $cards = [
        'PRJ2605222' => '70',
        'PRJ2608515' => '070',
        'PRJ15' => '15 - Service',
        'PRJLEEG' => '',
        'PRJ77' => '70 - Naam',
    ];
    $rows = [];
    foreach ($cards as $no => $code) {
        if (strpos(rawurldecode($url), "No eq '" . $no . "'") !== false) {
            $rows[] = ['No' => $no, 'LVS_Global_Dimension_1_Code' => $code];
        }
    }

    return $rows;
};

require dirname(__DIR__) . '/web/odata.php';
require_once dirname(__DIR__) . '/web/bc_enum.php';
require_once dirname(__DIR__) . '/web/project_finance.php';
require_once dirname(__DIR__) . '/web/bc_fetch/workorder_state_cache.php';
require_once dirname(__DIR__) . '/web/bc_fetch/projectposten_workorders.php';

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
    // WOEIGEN: eigen post zonder kostenplaats -> projectkaart (PRJ77 = 70) telt.
    ['Job_No' => 'PRJ77', 'Job_Task_No' => 'T1', 'LVS_Work_Order_No' => 'WOEIGEN', 'Global_Dimension_1_Code' => ''],
    // WOEIGEN15: eigen post met kostenplaats 15 -> eigen post blijft bepalend, kaart niet nodig.
    ['Job_No' => 'PRJ77', 'Job_Task_No' => 'T2', 'LVS_Work_Order_No' => 'WOEIGEN15', 'Global_Dimension_1_Code' => '15'],
    // PRJLEEG: kaart leeg, andere werkorder van het project heeft posten op 70 -> projectposten-terugval.
    ['Job_No' => 'PRJLEEG', 'Job_Task_No' => 'T9', 'LVS_Work_Order_No' => 'WOANDER', 'Global_Dimension_1_Code' => '70'],
    // PRJ15: kaart 15, andere werkorder heeft posten op 70 -> kaart wint.
    ['Job_No' => 'PRJ15', 'Job_Task_No' => 'T9', 'LVS_Work_Order_No' => 'WOANDER15', 'Global_Dimension_1_Code' => '70'],
];
$workorders = [
    ['No' => 'WO2605966', 'Job_No' => 'PRJ2605222', 'Job_Task_No' => 'WO2605966', 'Job_Dimension_1_Value' => ''],
    ['No' => 'WO2610899', 'Job_No' => 'PRJ2608515', 'Job_Task_No' => 'WO2610899', 'Job_Dimension_1_Value' => ''],
    ['No' => 'WOEIGEN', 'Job_No' => 'PRJ77', 'Job_Task_No' => 'T1', 'Job_Dimension_1_Value' => ''],
    ['No' => 'WOEIGEN15', 'Job_No' => 'PRJ77', 'Job_Task_No' => 'T2', 'Job_Dimension_1_Value' => ''],
    ['No' => 'WOPRJPOST', 'Job_No' => 'PRJLEEG', 'Job_Task_No' => 'T3', 'Job_Dimension_1_Value' => ''],
    ['No' => 'WOKAART15', 'Job_No' => 'PRJ15', 'Job_Task_No' => 'T3', 'Job_Dimension_1_Value' => ''],
    ['No' => 'WOKOP15', 'Job_No' => 'PRJ2605222', 'Job_Task_No' => 'T4', 'Job_Dimension_1_Value' => '15'],
];

$neededJobs = bc_fetch_job_nos_needing_project_card_cost_center($workorders, $posten);
sort($neededJobs);
check($neededJobs === ['PRJ15', 'PRJ2605222', 'PRJ2608515', 'PRJ77', 'PRJLEEG'], 'alleen projecten van lege koppen zonder eigen kostenplaats: ' . implode(',', $neededJobs));

$cards = bc_fetch_project_card_cost_centers_for_workorders('Koninklijke van Twist', $workorders, $posten, $auth, 14400, '70');
check(($cards['prj2605222'] ?? null) === '70' && ($cards['prj2608515'] ?? null) === '070', 'kaartcodes per Job_No opgehaald');
check(count($calls) === 1 && strpos($calls[0]['url'], '$select=No,LVS_Global_Dimension_1_Code') !== false, 'één gebatchte Projecten-call met alleen No/LVS_Global_Dimension_1_Code');
check(($calls[0]['ttl'] ?? 0) === 14400, 'TTL/max_age van de load wordt doorgegeven');

bc_fetch_project_card_cost_centers('Koninklijke van Twist', ['PRJ2605222'], $auth, 14400);
check(count($calls) === 1, 'tweede vraag naar hetzelfde project komt uit het procesgeheugen');
$noneCards = bc_fetch_project_card_cost_centers_for_workorders('Koninklijke van Twist', $workorders, $posten, $auth, 14400, bc_fetch_cost_center_none_value());
check($noneCards === [], "filter 'geen kostenplaats' haalt geen projectkaarten op");

$under70 = bc_fetch_filter_workorders_for_cost_center($workorders, $posten, '70', false, $cards);
$byNo = array_column($under70, null, 'No');
check(isset($byNo['WO2605966']) && $byNo['WO2605966']['Cost_Center_Source'] === 'project' && $byNo['WO2605966']['Job_Dimension_1_Value'] === '70', 'WO2605966 via projectkaart onder 70 (bron project)');
check(isset($byNo['WO2610899']), "WO2610899 via projectkaart '070' onder 70");
check(isset($byNo['WOEIGEN']) && $byNo['WOEIGEN']['Cost_Center_Source'] === 'project', "eigen posten zonder code: projectkaart '70 - Naam' telt");
check(!isset($byNo['WOEIGEN15']), 'eigen post met kostenplaats 15 blijft bepalend boven de projectkaart');
check(isset($byNo['WOPRJPOST']) && $byNo['WOPRJPOST']['Cost_Center_Source'] === 'projectposten', 'lege projectkaart: terugval op projectposten blijft');
check(!isset($byNo['WOKAART15']), 'projectkaart 15 gaat voor projectposten op 70');
check(!isset($byNo['WOKOP15']), 'gevulde kop blijft strikt');

$under15 = array_column(bc_fetch_filter_workorders_for_cost_center($workorders, $posten, '15', false, $cards), 'No');
sort($under15);
check($under15 === ['WOEIGEN15', 'WOKAART15', 'WOKOP15'], 'filter 15: ' . implode(',', $under15));

$withoutCards = array_column(bc_fetch_filter_workorders_for_cost_center($workorders, $posten, '70'), 'No');
check(!in_array('WO2605966', $withoutCards, true), 'zonder kaartdata (oude gedrag) ontbreekt WO2605966');

exit($failures > 0 ? 1 : 0);
