<?php
/**
 * Projecttotalen moeten ALLE ProjectPosten van het project meetellen (ook zonder werkordernummer),
 * werkordertotalen niet veranderen. Fixture: BC ProjectPosten van project 4087459 (KvT, t/m 2026-09-30).
 * BC-projecttotaal: kosten 48657.47, opbrengst 35765.60, resultaat -12891.87.
 * Run: php tests/project_totals_all_postings_test.php
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

$rows = json_decode((string) file_get_contents(__DIR__ . '/fixtures_project_4087459_posten.json'), true);
// Mímir levert NL-captions; beide moeten werken.
$nlRows = array_map(static function (array $row): array {
    $row['Entry_Type'] = $row['Entry_Type'] === 'Usage' ? 'Gebruik' : ($row['Entry_Type'] === 'Sale' ? 'Verkoop' : $row['Entry_Type']);
    return $row;
}, $rows);

$workorders = [
    ['No' => '4087459', 'Job_No' => '4087459', 'Job_Task_No' => '4087459'],
    ['No' => 'WO2610329', 'Job_No' => '4087459', 'Job_Task_No' => '4087459'],
    ['No' => 'WO2610333', 'Job_No' => '4087459', 'Job_Task_No' => 'PLAATSING 100KVA KVT'],
    ['No' => 'WO2610334', 'Job_No' => '4087459', 'Job_Task_No' => 'PLAATSING 400KVA KVT'],
];

$service = (new ReflectionClass(ProjectFinanceService::class))->newInstanceWithoutConstructor();

foreach (['EN' => $rows, 'NL' => $nlRows] as $lang => $input) {
    $woRows = bc_fetch_filter_projectposten_rows_for_workorders($input, $workorders);
    $projectRows = bc_fetch_filter_projectposten_rows_for_projects($input, $workorders);
    check(count($projectRows) === count($input), "$lang: project filter keeps all " . count($input) . ' posten');
    check(count($woRows) < count($projectRows), "$lang: werkorderfilter laat posten zonder (unieke) werkorder weg");

    $woFinance = $service->aggregateProjectAndWorkorderFinanceFromProjectPostenRows($woRows);
    $projectFinance = $service->aggregateProjectAndWorkorderFinanceFromProjectPostenRows($projectRows);

    $old = $woFinance['project_totals_by_job']['4087459'] ?? [];
    $new = $projectFinance['project_totals_by_job']['4087459'] ?? [];
    check(abs(($old['costs'] ?? 0) - 24449.07) < 0.005, "$lang: oude projectkosten = 24449.07 (Ariadne)");
    check(abs(($new['costs'] ?? 0) - 48657.47) < 0.005, "$lang: projectkosten = BC 48657.47 (" . ($new['costs'] ?? 0) . ')');
    check(abs(($new['revenue'] ?? 0) - 35765.60) < 0.005, "$lang: projectopbrengst = BC 35765.60");
    check(abs(finance_calculate_result($new['revenue'] ?? 0, $new['costs'] ?? 0) + 12891.87) < 0.005, "$lang: projectresultaat = BC -12891.87");

    $woTotals = $woFinance['workorder_totals_by_project_and_number']['4087459|wo2610333'] ?? [];
    check(abs(($woTotals['costs'] ?? 0) - 14822.46) < 0.005, "$lang: WO2610333 kosten ongewijzigd 14822.46");
    check(count($projectFinance['projectposten_rows_by_project']['4087459'] ?? []) === count($input), "$lang: projectposten-modal toont alle posten");
}

check(bc_fetch_filter_projectposten_rows_for_projects($rows, []) === [], 'geen werkorders → geen posten');
check(bc_fetch_filter_projectposten_rows_for_projects([['Job_No' => 'X1']], $workorders) === [], 'ander project valt weg');

exit($failures > 0 ? 1 : 0);
