<?php
/**
 * Regressie 8 okt 2026 (hertest na herbouw, ui2/RESULT.md): projecttotalen misten posten van het project
 * waarvan de boekweek geen werkorder van dat project laadde.
 * - KvT/50 PRJ2602195: opbrengst 0 i.p.v. 222.990 (S126000126 17-02, S126000652 10-03), kosten 12.936 i.p.v.
 *   36.093,50; de werkorders van het project starten pas in aug/sep of hebben startdatum 0001-01-01.
 * - KvT/70 WO2606204 (lege kop-kostenplaats): PI12603414 (4.605, 04-05) heeft kostenplaats 20, dus in die
 *   week viel de werkorder buiten 70 en telde de post nergens mee.
 * - KvT/15 WO2606479: S126002855 (22-06, kostenplaats 99) idem.
 *
 * Deel 1 reproduceert de oude rekenwijze (som over weken; per week alleen posten van projecten met een in die
 * week geladen werkorder) en laat zien dat die de posten mist. Deel 2 test de fix: totalen over alle
 * ProjectPosten (volledige opbouw + delta op Entry_No), en dat de pagina en load_month die gebruiken.
 *
 * Run: php tests/project_totals_all_postings_test.php
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

$tmpDir = sys_get_temp_dir() . '/demeter_pt_full_' . getmypid();
@mkdir($tmpDir, 0777, true);
$GLOBALS['demeter_project_totals_full_dir'] = $tmpDir;
require_once __DIR__ . '/../web/bc_fetch/project_totals_full.php';

$fail = 0;
function check(bool $ok, string $label): void
{
    global $fail;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $fail++; }
}

function post(int $entry, string $date, string $job, string $wo, string $type, float $cost, float $amount, string $doc, string $dim = '50'): array
{
    return ['Entry_No' => $entry, 'Posting_Date' => $date, 'Job_No' => $job, 'Job_Task_No' => $wo, 'LVS_Work_Order_No' => $wo,
        'Entry_Type' => $type, 'Type' => 'Resource', 'No' => 'X', 'Description' => $doc, 'Total_Cost' => $cost,
        'Line_Amount_LCY' => $amount, 'Global_Dimension_1_Code' => $dim];
}

$posten = [
    // PRJ2602195: verkoopfacturen zonder werkordernummer in week 8 en 11; uren op de werkorder in week 37.
    post(1, '2026-02-17', 'PRJ2602195', '', 'Sale', 0, -205500, 'S126000126'),
    post(2, '2026-03-10', 'PRJ2602195', '', 'Sale', 0, -17490, 'S126000652'),
    post(3, '2026-03-10', 'PRJ2602195', '', 'Usage', 23157.5, 0, 'PI-projectniveau'),
    post(4, '2026-09-08', 'PRJ2602195', 'WO2607664', 'Usage', 12936, 0, 'TS2601617'),
    // WO2606204 (lege kop): eigen posten met kostenplaats 70, maar de inkoopfactuur met kostenplaats 20.
    post(5, '2026-04-29', 'PRJ2605385', 'WO2606204', 'Usage', 105.2, 0, 'TS', '70'),
    post(6, '2026-05-04', 'PRJ2605385', 'WO2606204', 'Usage', 4605, 0, 'PI12603414', '20'),
    // Creditnota telt af.
    post(7, '2026-03-02', '156293', '', 'Sale', 0, -28300, 'S126001908'),
    post(8, '2026-06-01', '156293', '', 'Sale', 0, 14150, 'C126000107'),
    post(9, '2026-09-01', '156293', '', 'Sale', 0, -14150, 'S126004015'),
];
$expected = [
    'prj2602195' => ['costs' => 36093.5, 'revenue' => 222990.0],
    'prj2605385' => ['costs' => 4710.2, 'revenue' => 0.0],
    '156293' => ['costs' => 0.0, 'revenue' => 28300.0],
];

// ---- Deel 1: oude rekenwijze (zoals bc_fetch_load_workorder_week_chunk + cumulatieve month_scan) ----
$workorders = [
    ['No' => 'WO2607664', 'Job_No' => 'PRJ2602195', 'Job_Task_No' => 'WO2607664', 'Start_Date' => '2026-09-07', 'Job_Dimension_1_Value' => '50'],
    ['No' => 'WO2606204', 'Job_No' => 'PRJ2605385', 'Job_Task_No' => 'WO2606204', 'Start_Date' => '2026-04-29', 'Job_Dimension_1_Value' => ''],
    ['No' => '4071630', 'Job_No' => '156293', 'Job_Task_No' => '156293', 'Start_Date' => '2026-01-31', 'Job_Dimension_1_Value' => '50'],
];
$service = new ProjectFinanceService('Koninklijke van Twist');
$oldCumulative = static function (string $costCenter) use ($posten, $workorders, $service): array {
    $weeks = [];
    foreach ($posten as $p) {
        $weeks[(new DateTimeImmutable($p['Posting_Date']))->format('o-\WW')][] = $p;
    }
    foreach ($workorders as $w) {
        $weeks[(new DateTimeImmutable($w['Start_Date']))->format('o-\WW')] ??= [];
    }
    $monthScan = ['months' => []];
    foreach ($weeks as $yearWeek => $weekPosten) {
        // Werkorders van de week: startweek, of genoemd in een post van de week (LVS_Work_Order_No).
        $weekWos = array_values(array_filter($workorders, static function ($w) use ($yearWeek, $weekPosten): bool {
            if ((new DateTimeImmutable($w['Start_Date']))->format('o-\WW') === $yearWeek) {
                return true;
            }
            foreach ($weekPosten as $p) {
                if ($p['LVS_Work_Order_No'] !== '' && $p['LVS_Work_Order_No'] === $w['No']) {
                    return true;
                }
            }
            return false;
        }));
        $weekWos = bc_fetch_filter_workorders_for_cost_center($weekWos, $weekPosten, $costCenter, false, []);
        $projectScope = $service->aggregateProjectAndWorkorderFinanceFromProjectPostenRows(bc_fetch_filter_projectposten_rows_for_projects($weekPosten, $weekWos));
        $monthScan = demeter_month_scan_store_week_project_totals($monthScan, $yearWeek, $projectScope['project_totals_by_job'] ?? []);
    }
    return demeter_month_scan_cumulative_project_totals($monthScan);
};
$old = $oldCumulative('50') + $oldCumulative('70');
check(round((float) ($old['prj2602195']['revenue'] ?? 0), 2) === 0.0, 'oud: PRJ2602195 opbrengst 0 (facturen in weken zonder werkorder van het project vallen weg)');
check(round((float) ($old['prj2602195']['costs'] ?? 0), 2) === 12936.0, 'oud: PRJ2602195 kosten alleen 12.936');
check(round((float) ($old['prj2605385']['costs'] ?? 0), 2) === 105.2, 'oud: PRJ2605385 mist PI12603414 (week waarin de werkorder buiten 70 viel)');

// ---- Deel 2: fix ----
$calls = [];
$bc = $posten;
$transport = ['fetch' => static function (string $entity, array $query) use (&$calls, &$bc): array {
    $calls[] = $query['$filter'] ?? ($query['$orderby'] ?? '');
    if (($query['$top'] ?? '') === '1') {
        $max = 0;
        foreach ($bc as $p) { $max = max($max, $p['Entry_No']); }
        return [['Entry_No' => $max]];
    }
    if (!preg_match('/Entry_No gt (\d+)(?: and Entry_No le (\d+))?/', (string) ($query['$filter'] ?? ''), $m)) {
        throw new RuntimeException('onverwachte query');
    }
    $from = (int) $m[1];
    $to = isset($m[2]) ? (int) $m[2] : PHP_INT_MAX;
    return array_values(array_filter($bc, static fn ($p) => $p['Entry_No'] > $from && $p['Entry_No'] <= $to));
}];

$company = 'Koninklijke van Twist';
check(demeter_project_totals_full_sync($company, $transport, false)['status'] === 'needs_full', 'zonder bestand en zonder allowFull: niets (pagina wacht nooit op de volledige opbouw)');
$r = demeter_project_totals_full_sync($company, $transport, true);
check($r['status'] === 'built' && $r['max_entry_no'] === 9, 'volledige opbouw: ' . json_encode($r));
$full = demeter_project_totals_full_for_jobs($company, null);
foreach ($expected as $job => $want) {
    check(abs((float) ($full[$job]['costs'] ?? -1) - $want['costs']) < 0.005 && abs((float) ($full[$job]['revenue'] ?? -1) - $want['revenue']) < 0.005,
        "nieuw: {$job} kosten/opbrengst " . json_encode($full[$job] ?? null) . ' = ' . json_encode($want));
}

$calls = [];
check(demeter_project_totals_full_sync($company, $transport, false)['status'] === 'fresh' && $calls === [], 'binnen 180 s: geen BC-call');

// Nieuwe post (ook achteraf gedateerd) komt via de delta binnen; oude posten niet dubbel.
$bc[] = post(10, '2026-02-20', 'PRJ2602195', '', 'Sale', 0, -1000, 'S126009999');
$r = demeter_project_totals_full_sync($company, $transport, false, true);
$full = demeter_project_totals_full_for_jobs($company, ['PRJ2602195']);
check($r['status'] === 'synced' && abs($full['prj2602195']['revenue'] - 223990.0) < 0.005, 'delta: achteraf gedateerde factuur telt mee, rest niet dubbel (' . json_encode($full['prj2602195']) . ')');

// Fout tijdens de delta: bestand ongewijzigd.
$broken = ['fetch' => static function (): array { throw new RuntimeException('HTTP 409'); }];
$r = demeter_project_totals_full_sync($company, $broken, false, true);
$full2 = demeter_project_totals_full_for_jobs($company, ['PRJ2602195']);
check($r['status'] === 'error' && $full2 === $full, 'fout (409) laat het bestand ongewijzigd');

// Weergave: rijen krijgen de volledige totalen; onbekende projecten blijven ongemoeid.
$rows = [
    'k1' => ['Row_Key' => 'k1', 'Job_No' => 'PRJ2602195', 'Project_Actual_Costs' => 12936.0, 'Project_Total_Revenue' => 0.0],
    'k2' => ['Row_Key' => 'k2', 'Job_No' => 'prj2602195 ', 'Project_Actual_Costs' => 12936.0, 'Project_Total_Revenue' => 0.0],
    'k3' => ['Row_Key' => 'k3', 'Job_No' => 'PRJ0000001', 'Project_Actual_Costs' => 10.0, 'Project_Total_Revenue' => 20.0],
];
$applied = demeter_project_totals_full_apply_to_rows($company, $rows);
check($applied['k1']['Project_Total_Revenue'] === 223990.0 && $applied['k2']['Project_Total_Revenue'] === 223990.0, 'alle rijen van het project krijgen de volledige opbrengst');
check($applied['k1']['Project_Actual_Costs'] === 36093.5, 'kosten: volledige som');
check($applied['k3']['Project_Actual_Costs'] === 10.0 && $applied['k3']['Project_Total_Revenue'] === 20.0, 'project zonder posten in het bestand blijft ongemoeid');
$map = demeter_project_totals_full_overlay_map($company, ['prj2602195' => ['costs' => 1.0, 'revenue' => 2.0], 'prj0000001' => ['costs' => 3.0, 'revenue' => 4.0]], ['156293']);
check($map['prj2602195']['revenue'] === 223990.0 && $map['prj0000001']['costs'] === 3.0 && $map['156293']['revenue'] === 28300.0, 'load_month: cumulatieve map overschreven met volledige totalen (+ jobs van de rijen)');

// Bedrading: pagina-render en load_month gebruiken de volledige totalen; nightly werkt het bestand bij.
$index = file_get_contents(__DIR__ . '/../web/index.php');
check(strpos($index, '$displayRowsByKey = demeter_page_apply_full_project_totals($selectedCompany, $displayRowsByKey);') !== false, 'pagina-render zet de volledige projecttotalen');
check(strpos($index, "'project_totals_cumulative_by_job' => demeter_load_month_full_project_totals(") !== false, 'load_month stuurt de volledige totalen als cumulatief');
check(strpos(file_get_contents(__DIR__ . '/../web/nightly.php'), 'demeter_project_totals_full_sync(') !== false, 'nightly werkt de volledige projecttotalen bij');

array_map('unlink', glob($tmpDir . '/*') ?: []);
@rmdir($tmpDir);
exit($fail > 0 ? 1 : 0);
