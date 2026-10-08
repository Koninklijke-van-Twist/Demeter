<?php
/**
 * KvT kostenplaats 70 (8 okt 2026): Demeter toonde 11534 werkorders, BC heeft er 8990 (hele bedrijf 11457).
 * Oorzaak (grootste deel): ~1900 'Import SAP'-projectposten (geboekt 31-01-2026, week 2026-W05) zonder
 * werkorder werden pseudo-rijen met status 'Open' en telden als werkorder. Deze test reproduceert dat:
 * pseudo-rijen krijgen status 'Geen werkorder' + Is_Posting_Only, en een herhaalde week geeft geen
 * extra rij (zelfde Row_Key). Plus: klantnaam valt terug op Sell-to als Bill-to leeg is.
 * Run: php tests/import_sap_posting_rows_test.php
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
require_once __DIR__ . '/../web/workorder_rows.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$posten = [];
foreach (['PRJ0000001', 'PRJ0000002', 'PRJ0000003'] as $job) {
    foreach (['IMPORT SAP ARBEID JAAR 2025', 'IMPORT SAP MAT_EXTERN JAAR 2025'] as $desc) {
        $posten[] = ['Job_No' => $job, 'Job_Task_No' => '', 'LVS_Work_Order_No' => '', 'Posting_Date' => '2026-01-31', 'Entry_Type' => 'Usage', 'Type' => 'Resource', 'No' => 'X', 'Description' => $desc, 'Total_Cost' => 100.0, 'Line_Amount_LCY' => 0, 'Global_Dimension_1_Code' => '70'];
    }
}
// Eén echte werkorder op hetzelfde project.
$posten[] = ['Job_No' => 'PRJ0000001', 'Job_Task_No' => 'WO0000001', 'LVS_Work_Order_No' => 'WO0000001', 'Posting_Date' => '2026-01-30', 'Entry_Type' => 'Usage', 'Type' => 'Resource', 'No' => 'X', 'Description' => 'Arbeid', 'Total_Cost' => 50.0, 'Line_Amount_LCY' => 0, 'Global_Dimension_1_Code' => '70'];

$service = new ProjectFinanceService('Testbedrijf');
$finance = $service->aggregateProjectAndWorkorderFinanceFromProjectPostenRows($posten);
$importRows = is_array($finance['import_sap_workorder_rows'] ?? null) ? $finance['import_sap_workorder_rows'] : [];
check(count($importRows) === 6, '6 Import SAP-pseudo-werkorders (3 projecten x 2 omschrijvingen): ' . count($importRows));
check(($importRows[0]['Status'] ?? '') === 'Geen werkorder', "pseudo-werkorder heeft status 'Geen werkorder' i.p.v. 'Open'");

$realWorkorder = ['No' => 'WO0000001', 'Job_No' => 'PRJ0000001', 'Job_Task_No' => 'WO0000001', 'Status' => 'Closed', 'Start_Date' => '2026-01-30', 'Task_Code' => 'SM03', 'Task_Description' => 'Onderhoud', 'Job_Dimension_1_Value' => '70', 'Bill_to_Customer_No' => '', 'Bill_to_Name' => '', 'Sell_to_Customer_No' => 'C0001', 'Sell_to_Name' => 'Testklant B.V.'];

$overview = [
    'workorders' => array_merge([$realWorkorder], $importRows),
    'project_totals_by_job' => $finance['project_totals_by_job'] ?? [],
    'workorder_totals_by_project_and_number' => $finance['workorder_totals_by_project_and_number'] ?? [],
    'finance_key_by_pair' => [],
];
$built = demeter_build_workorder_rows_from_overview($overview, 'both');
$rows = $built['rows'];
$postingOnly = array_values(array_filter($rows, static function (array $r): bool { return !empty($r['Is_Posting_Only']); }));
$realRows = array_values(array_filter($rows, static function (array $r): bool { return empty($r['Is_Posting_Only']); }));
check(count($rows) === 7, '7 rijen totaal: ' . count($rows));
check(count($realRows) === 1, 'maar 1 echte werkorder (vroeger telden alle 7 als werkorder): ' . count($realRows));
$openCount = count(array_filter($rows, static function (array $r): bool { return strtolower((string) $r['Status']) === 'open'; }));
check($openCount === 0, "geen pseudo-rij telt als 'Open' (was 6): " . $openCount);
check(($postingOnly[0]['Status'] ?? '') === DEMETER_POSTING_ONLY_ROW_STATUS, "pseudo-rij Status = 'Geen werkorder'");
check(($postingOnly[0]['No'] ?? '') === 'Import SAP', "pseudo-rij toont nog steeds 'Import SAP'");

// Klant: Bill-to leeg -> Sell-to.
check(($realRows[0]['Customer_Name'] ?? '') === 'Testklant B.V.', 'klantnaam valt terug op Sell_to_Name: ' . ($realRows[0]['Customer_Name'] ?? ''));
check(($realRows[0]['Customer_Id'] ?? '') === 'C0001', 'klantnummer valt terug op Sell_to_Customer_No');
$withBill = demeter_build_workorder_rows_from_overview(['workorders' => [array_merge($realWorkorder, ['Bill_to_Customer_No' => 'B0002', 'Bill_to_Name' => 'Factuurklant'])]], 'both')['rows'][0];
check($withBill['Customer_Name'] === 'Factuurklant' && $withBill['Customer_Id'] === 'B0002', 'Bill-to blijft voorrang houden');
// Bill-to-nummer zonder Bill-to-naam: nummer en naam samen van Sell-to (nooit Bill-to-nr + Sell-to-naam).
$billNoName = demeter_build_workorder_rows_from_overview(['workorders' => [array_merge($realWorkorder, ['Bill_to_Customer_No' => 'B0002', 'Bill_to_Name' => ''])]], 'both')['rows'][0];
check($billNoName['Customer_Id'] === 'C0001' && $billNoName['Customer_Name'] === 'Testklant B.V.', 'Bill-to zonder naam -> Sell-to-paar: ' . $billNoName['Customer_Id'] . ' ' . $billNoName['Customer_Name']);
// Zoals WO2610333: Bill-to leeg, Sell-to 8262 SPIE BUILDING SOLUTIONS B.V.
$spie = demeter_build_workorder_rows_from_overview(['workorders' => [array_merge($realWorkorder, ['Sell_to_Customer_No' => '8262', 'Sell_to_Name' => 'SPIE BUILDING SOLUTIONS B.V.'])]], 'both')['rows'][0];
check($spie['Customer_Id'] === '8262' && $spie['Customer_Name'] === 'SPIE BUILDING SOLUTIONS B.V.', 'Sell-to-paar 8262 SPIE: ' . $spie['Customer_Id'] . ' ' . $spie['Customer_Name']);
// Bill-to-nummer zonder naam en geen Sell-to: nummer blijft, naam leeg.
$billOnly = demeter_build_workorder_rows_from_overview(['workorders' => [array_merge($realWorkorder, ['Bill_to_Customer_No' => 'B0002', 'Bill_to_Name' => '', 'Sell_to_Customer_No' => '', 'Sell_to_Name' => ''])]], 'both')['rows'][0];
check($billOnly['Customer_Id'] === 'B0002' && $billOnly['Customer_Name'] === '', 'Bill-to-nummer zonder naam en zonder Sell-to: naam blijft leeg');

// Dezelfde week twee keer samenvoegen: dezelfde Row_Keys, dus geen extra rijen.
$display = demeter_merge_display_rows_for_month_chunk([], $rows, false, []);
$display = demeter_merge_display_rows_for_month_chunk($display, $rows, false, []);
check(count($display) === 7, 'herhaalde week geeft geen extra rijen: ' . count($display));
$flagged = count(array_filter($display, static function (array $r): bool { return !empty($r['Is_Posting_Only']); }));
check($flagged === 6, 'Is_Posting_Only blijft behouden na merge: ' . $flagged);

if ($failures > 0) {
    exit(1);
}
echo 'alle checks ok' . PHP_EOL;
