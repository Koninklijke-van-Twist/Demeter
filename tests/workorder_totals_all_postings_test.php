<?php
/**
 * Regressie hertest 8 okt 2026 (ui4, KvT/70 en KvT/15): werkorderkosten/-opbrengst misten posten.
 * - WO2606204 / WO2606460 / WO2606823: de inkoopfactuur met kostenplaats 20 op een werkorder van 70;
 * - WO2609914: PI12608901 (22,30 + 114,28, boekdatum 01-09, Entry_No 55118/55119) achteraf geboekt in een al
 *   gelezen week: 621,45 i.p.v. 758,03;
 * - KvT/15 WO2606479: S126002855 met kostenplaats 99 (opbrengst 0 i.p.v. 673,68).
 * Fix: werkordertotalen over alle ProjectPosten in hetzelfde bestand als de projecttotalen (volledige opbouw +
 * delta op Entry_No), op de pagina en in de catch-up toegepast; wat al klopt blijft ongewijzigd.
 * Run: php tests/workorder_totals_all_postings_test.php
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

$tmpDir = sys_get_temp_dir() . '/demeter_wo_full_' . getmypid();
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

function post(int $entry, string $date, string $job, string $wo, string $type, float $cost, float $amount, string $doc, string $dim): array
{
    return ['Entry_No' => $entry, 'Posting_Date' => $date, 'Job_No' => $job, 'Job_Task_No' => $wo, 'LVS_Work_Order_No' => $wo,
        'Entry_Type' => $type, 'Type' => 'Item', 'No' => 'X', 'Description' => $doc, 'Total_Cost' => $cost,
        'Line_Amount_LCY' => $amount, 'Global_Dimension_1_Code' => $dim];
}

$posten = [
    post(1, '2026-04-29', 'PRJ2605385', 'WO2606204', 'Usage', 105.2, 0, 'TS', '70'),
    post(2, '2026-05-04', 'PRJ2605385', 'WO2606204', 'Usage', 4605, 0, 'PI12603414', '20'),
    post(3, '2026-05-06', 'PRJ2605385', 'WO2606204', 'Sale', 0, -8478.2, 'S1260', '70'),
    post(10, '2026-09-01', 'PRJ2607917', 'WO2609914', 'Usage', 621.45, 0, 'TS', '70'),
    post(20, '2026-06-22', 'PRJ2605551', 'WO2606479', 'Usage', 569.43, 0, 'TS', '15'),
    post(21, '2026-06-22', 'PRJ2605551', 'WO2606479', 'Sale', 0, -673.68, 'S126002855', '99'),
    post(30, '2026-09-10', 'PRJ1', 'WOKLOPT', 'Usage', 100, 0, 'TS', '70'),
    post(31, '2026-09-10', 'PRJ1', 'WOKLOPT', 'Sale', 0, -250, 'S1', '70'),
];
// Achteraf gedateerde inkoopfactuur PI12608901 op WO2609914: nieuwe Entry_No's, boekdatum 01-09.
$backdated = [
    post(55118, '2026-09-01', 'PRJ2607917', 'WO2609914', 'Usage', 22.30, 0, 'PI12608901', '70'),
    post(55119, '2026-09-01', 'PRJ2607917', 'WO2609914', 'Usage', 114.28, 0, 'PI12608901', '70'),
];

$bc = ['rows' => $posten];
$transport = ['fetch' => static function (string $entity, array $query) use (&$bc): array {
    $rows = $bc['rows'];
    $filter = (string) ($query['$filter'] ?? '');
    if (($query['$top'] ?? '') === '1') {
        $max = 0;
        foreach ($rows as $r) { $max = max($max, (int) $r['Entry_No']); }
        return [['Entry_No' => $max]];
    }
    if (preg_match('/Entry_No gt (\d+)(?: and Entry_No le (\d+))?/', $filter, $m)) {
        $gt = (int) $m[1];
        $le = isset($m[2]) ? (int) $m[2] : PHP_INT_MAX;
        $rows = array_values(array_filter($rows, static fn ($r) => $r['Entry_No'] > $gt && $r['Entry_No'] <= $le));
    }
    return $rows;
}];

// Schermrijen zoals de weekcache ze nu heeft (kostenplaats-/weekgebonden sommen).
$row = static function (string $job, string $wo, float $costs, float $revenue, string $sourceKey = ''): array {
    return ['Row_Key' => $job . '|' . $wo, 'No' => $wo, 'Bc_No' => $wo, 'Job_No' => $job, 'Job_Task_No' => $wo, 'Workorder_Source_Key' => $sourceKey !== '' ? $sourceKey : $wo,
        'Actual_Costs' => $costs, 'Total_Revenue' => $revenue, 'Actual_Total' => $revenue - $costs, 'Untouched_Marker' => 'x'];
};
$display = [
    'PRJ2605385|WO2606204' => $row('PRJ2605385', 'WO2606204', 105.2, 8478.2),
    'PRJ2607917|WO2609914' => $row('PRJ2607917', 'WO2609914', 621.45, 0.0),
    'PRJ2605551|WO2606479' => $row('PRJ2605551', 'WO2606479', 569.43, 0.0),
    'PRJ1|WOKLOPT' => $row('PRJ1', 'WOKLOPT', 100.0, 250.0),
    'PRJ9|WOZONDERPOSTEN' => $row('PRJ9', 'WOZONDERPOSTEN', 0.0, 0.0),
];

$apply = static function (string $company, array $rows): array {
    return function_exists('demeter_project_totals_full_apply_wo_to_rows') ? demeter_project_totals_full_apply_wo_to_rows($company, $rows) : $rows;
};

$company = 'Testbedrijf wo ' . getmypid();
$r = demeter_project_totals_full_sync($company, $transport, true, true);
check(($r['status'] ?? '') === 'built', 'volledige opbouw (' . ($r['status'] ?? '') . ')');
$state = demeter_project_totals_full_read($company);
check(isset($state['wo_totals']['prj2605385|wo2606204']), 'bestand bevat werkordertotalen per job|werkorder');

$out = $apply($company, $display);
check(abs($out['PRJ2605385|WO2606204']['Actual_Costs'] - 4710.2) < 0.001, 'WO2606204: kosten 4.710,20 incl. inkoopfactuur met kostenplaats 20 (was 105,20)');
check(abs($out['PRJ2605385|WO2606204']['Actual_Total'] - (8478.2 - 4710.2)) < 0.001, 'WO2606204: resultaat werkorder mee bijgewerkt');
check(abs($out['PRJ2605551|WO2606479']['Total_Revenue'] - 673.68) < 0.001, 'KvT/15 WO2606479: opbrengst 673,68 incl. S126002855 met kostenplaats 99 (was 0)');
check($out['PRJ1|WOKLOPT'] === $display['PRJ1|WOKLOPT'], 'werkorder die al klopt: rij ongewijzigd');
check($out['PRJ9|WOZONDERPOSTEN'] === $display['PRJ9|WOZONDERPOSTEN'], 'werkorder zonder posten: rij ongewijzigd');

// Achteraf gedateerde post: delta op Entry_No telt hem op, ongeacht de boekdatum.
$bc['rows'] = array_merge($posten, $backdated);
$r = demeter_project_totals_full_sync($company, $transport, false, true);
check(($r['status'] ?? '') === 'synced' && (int) ($r['rows'] ?? 0) === 2, 'delta: 2 nieuwe posten (Entry_No 55118/55119)');
$out = $apply($company, $display);
check(abs($out['PRJ2607917|WO2609914']['Actual_Costs'] - 758.03) < 0.001, 'WO2609914: 758,03 incl. achteraf gedateerde PI12608901 (was 621,45)');

// Een bestand van versie 1 (alleen projecttotalen) wordt opnieuw opgebouwd.
$v1 = $state;
$v1['version'] = 1;
unset($v1['wo_totals']);
demeter_project_totals_full_write($company, $v1);
$readV1 = demeter_project_totals_full_read($company);
check($readV1 !== null && !demeter_project_totals_full_is_current($readV1), 'bestand van versie 1: projecttotalen blijven bruikbaar, maar niet actueel');
check(demeter_project_totals_full_for_jobs($company, ['prj2605385']) !== [], 'versie 1: projecttotalen worden tijdens de nieuwe opbouw nog getoond');
check($apply($company, $display) === $display, 'versie 1: geen werkordertotalen toegepast (die ontbreken in het oude bestand)');
$e = demeter_project_totals_full_ensure($company, $transport, 1.0);
check(!empty($e['available']) && demeter_project_totals_full_is_current(demeter_project_totals_full_read($company)), 'ensure bouwt een bestand van versie 1 opnieuw op (' . ($e['status'] ?? '') . ')');
$out = $apply($company, $display);
check(abs($out['PRJ2607917|WO2609914']['Actual_Costs'] - 758.03) < 0.001, 'na de nieuwe opbouw: werkordertotalen weer toegepast');
$r = demeter_project_totals_full_sync($company, $transport, false, true);
check(($r['status'] ?? '') === 'synced', 'daarna gewone delta (geen nieuwe volledige opbouw)');

// Bedrading: pagina-render en catch-up passen de werkordertotalen toe, gewone weekrijen niet (dubbel tellen).
$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
check(preg_match('/return demeter_project_totals_full_apply_wo_to_rows\(\$company, demeter_project_totals_full_apply_to_rows\(\$company, \$displayRowsByKey\)\)/', $index) === 1, 'pagina-render past werkordertotalen toe');
check(preg_match('/\$catchUp\s*\?\s*demeter_project_totals_full_apply_wo_to_rows/', $index) === 1, 'load_month: alleen bij de catch-up');

foreach (glob($tmpDir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmpDir);
exit($fail > 0 ? 1 : 0);
