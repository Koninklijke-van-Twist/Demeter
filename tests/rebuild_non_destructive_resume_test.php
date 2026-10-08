<?php
/**
 * Volledige herbouw is niet-destructief en hervatbaar (regressie KvT/70, 8 okt 2026: na een
 * onderbroken herbouw toonde de pagina 355 i.p.v. 9675 werkorders):
 * - begin_rebuild bewaart de huidige display-rijen (ook een oude cacheversie) als 'vorige rijen';
 * - zolang de nieuwe cache niet compleet is, toont de pagina de vorige rijen (pas wisselen bij klaar);
 * - een herstart van een onderbroken herbouw overschrijft de oudere complete rijen niet met de halve;
 * - een onderbroken herbouw wordt hervat (needs_resume) en slaat al gelezen weken over zonder BC-calls;
 * - bij een complete historie worden de vorige rijen opgeruimd;
 * - week-merge+opslaan blijft goedkoop bij ~9.700 werkorders (geen O(n²) / time-out in 'samenvoegen en opslaan');
 * - load_month meldt een fatal als JSON-fout en zet ruime limieten; de browser meldt een opgegeven
 *   verversing aan de server.
 * Run: php tests/rebuild_non_destructive_resume_test.php
 */

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x']];

require_once __DIR__ . '/../web/odata.php';
require_once __DIR__ . '/../web/bc_fetch/month_loader.php';
require_once __DIR__ . '/../web/bc_fetch/reference_cache.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function make_rows(int $count, string $costCenter, string $prefix = 'WO'): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $key = 'PRJ' . ($i % 900) . '|' . $prefix . $i;
        $rows[$key] = [
            'Row_Key' => $key,
            'No' => $prefix . $i,
            'Job_No' => 'PRJ' . ($i % 900),
            'Job_Task_No' => 'T' . $i,
            'Cost_Center' => $costCenter,
            'Status' => $i % 13 === 0 ? 'Open' : 'Closed',
            'Description' => str_repeat('x', 60),
            'Actual_Costs' => 1.5 * $i,
            'Total_Revenue' => 2.0 * $i,
            'Invoice_Ids' => [],
        ];
    }

    return $rows;
}

$company = 'Testbedrijf herbouw';
$costCenter = '97';
demeter_workorder_state_cache_purge($company, $costCenter);
$statePath = demeter_workorder_state_cache_path($company, $costCenter);
$displayPath = demeter_workorder_state_cache_display_rows_path($company, $costCenter);
$prevPath = demeter_workorder_state_cache_previous_display_rows_path($company, $costCenter);
@mkdir(dirname($statePath), 0775, true);

// 1) Oude complete cache (v12, oud display-formaat zonder versie) met 9.675 rijen.
$oldRows = make_rows(9675, $costCenter);
$oldScan = demeter_workorder_month_scan_defaults();
$oldScan['stop_before_month'] = '2017-W01';
file_put_contents($statePath, json_encode([
    'version' => DEMETER_WORKORDER_STATE_CACHE_VERSION - 1,
    'company' => $company,
    'cost_center' => $costCenter,
    'updated_at' => gmdate('c'),
    'workorders' => [],
    'month_scan' => $oldScan,
]));
file_put_contents($displayPath, json_encode($oldRows));
check(demeter_workorder_state_cache_is_stale_version($company, $costCenter), 'oude cache is stale');

demeter_workorder_state_cache_begin_rebuild($company, $costCenter);
clearstatcache();
check(!is_file($statePath) && !is_file($displayPath), 'herbouw start met lege state en lege nieuwe display-rijen');
check(is_file($prevPath), 'oude display-rijen bewaard als vorige rijen');
check(demeter_workorder_state_cache_rebuild_pending($company, $costCenter), 'herbouw staat open');
$page = demeter_workorder_state_cache_display_rows_for_page($company, $costCenter);
check($page['previous'] === true && count($page['rows']) === 9675, 'pagina toont tijdens de herbouw de vorige 9675 rijen (niet 0)');

// 2) Herbouw onderbroken na 4 weken (355 nieuwe rijen, historie niet compleet).
$current = demeter_current_iso_year_week();
$partialScan = demeter_workorder_month_scan_defaults();
$week = $current;
$scannedWeeks = [];
for ($i = 0; $i < 4; $i++) {
    $partialScan = demeter_month_scan_update_after_load($week, true, false, ['PRJ1|NEW' . $i], $partialScan);
    $scannedWeeks[] = $week;
    $week = demeter_previous_iso_year_week($week);
}
$newRows = make_rows(355, $costCenter, 'NEW');
demeter_workorder_state_cache_save($company, $costCenter, [], $partialScan);
demeter_workorder_state_cache_save_display_rows($company, $costCenter, $newRows);
$page = demeter_workorder_state_cache_display_rows_for_page($company, $costCenter);
check($page['previous'] === true && count($page['rows']) === 9675, 'onderbroken herbouw: nog steeds de vorige 9675 rijen, niet de 355 nieuwe');
check(demeter_workorder_state_cache_needs_resume($company, $costCenter), 'onderbroken herbouw moet hervat worden');

// 3) Herstart (bv. Ververs Nu) terwijl de herbouw onderbroken is: vorige complete rijen blijven.
demeter_workorder_state_cache_begin_rebuild($company, $costCenter);
$page = demeter_workorder_state_cache_display_rows_for_page($company, $costCenter);
check(count($page['rows']) === 9675, 'herstart overschrijft de complete vorige rijen niet met de halve herbouw');
demeter_workorder_state_cache_save($company, $costCenter, [], $partialScan);
demeter_workorder_state_cache_save_display_rows($company, $costCenter, $newRows);

// 4) Hervatten: al gelezen (oudere) week wordt overgeslagen zonder BC-call (lege auth zou falen).
$skippedWeek = $scannedWeeks[2];
$chunk = bc_fetch_load_workorder_week_chunk($company, $skippedWeek, [], 60, null, [
    'cost_center' => $costCenter,
    'force_full' => true,
    'resume_skip_scanned' => true,
]);
check(!empty($chunk['skipped']) && !empty($chunk['load_meta']['resume_skipped']), 'hervatten: al gelezen week overgeslagen');
check(($chunk['next_week'] ?? '') === demeter_previous_iso_year_week($skippedWeek) && !empty($chunk['should_continue']), 'hervatten: gaat door naar de volgende oudere week');
check(($chunk['row_keys'] ?? []) === ['PRJ1|NEW2'], 'hervatten: row_keys van de overgeslagen week');
check(count(demeter_workorder_state_cache_load_display_rows($company, $costCenter)) === 355, 'overslaan wijzigt de cache niet');

// 5) Herbouw compleet: wisselen naar de nieuwe rijen en vorige rijen opruimen.
$completeScan = $partialScan;
$completeScan['stop_before_month'] = '2017-W01';
demeter_workorder_state_cache_save($company, $costCenter, [], $completeScan);
check(!demeter_workorder_state_cache_needs_resume($company, $costCenter), 'complete historie: niet hervatten');
$page = demeter_workorder_state_cache_display_rows_for_page($company, $costCenter);
check($page['previous'] === false && count($page['rows']) === 355, 'complete herbouw: nieuwe rijen');
clearstatcache();
check(!is_file($prevPath), 'vorige rijen opgeruimd na complete herbouw');

// 6) Een complete cache die opnieuw wordt herbouwd: huidige rijen worden de vorige rijen.
demeter_workorder_state_cache_begin_rebuild($company, $costCenter);
$page = demeter_workorder_state_cache_display_rows_for_page($company, $costCenter);
check($page['previous'] === true && count($page['rows']) === 355, 'Ververs Nu op complete cache: huidige rijen blijven zichtbaar');
demeter_workorder_state_cache_purge($company, $costCenter);
clearstatcache();
check(!is_file($prevPath), 'purge (cache vergeten) ruimt ook de vorige rijen op');

// 7) Week samenvoegen + opslaan bij ~9.700 werkorders blijft goedkoop (lock, laden, merge, opslaan).
$bigRows = make_rows(9700, $costCenter);
$bigState = [];
foreach ($bigRows as $key => $row) {
    $bigState[$key] = ['workorder_no' => $row['No'], 'row' => $row];
}
demeter_workorder_state_cache_save($company, $costCenter, $bigState, $completeScan);
demeter_workorder_state_cache_save_display_rows($company, $costCenter, $bigRows);
$started = microtime(true);
$lock = demeter_workorder_state_cache_lock($company, $costCenter);
$fresh = demeter_workorder_state_cache_load($company, $costCenter);
$display = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
$display = demeter_merge_display_rows_for_month_chunk($display, array_values(array_slice($bigRows, 0, 400, true)), false, []);
demeter_workorder_state_cache_save($company, $costCenter, $fresh['workorders'], $fresh['month_scan'], $fresh['load_session']);
demeter_workorder_state_cache_save_display_rows($company, $costCenter, $display);
demeter_workorder_state_cache_unlock($lock);
$elapsed = microtime(true) - $started;
check($elapsed < 5.0, sprintf('merge+opslaan van een week bij 9.700 werkorders: %.2fs (< 5s)', $elapsed));
demeter_workorder_state_cache_purge($company, $costCenter);

// 8) Bedrading.
$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
$loader = (string) file_get_contents(__DIR__ . '/../web/bc_fetch/month_loader.php');
$runner = (string) file_get_contents(__DIR__ . '/../web/bc_fetch/nightly_runner.php');
check(strpos($index, 'demeter_workorder_state_cache_purge($selectedCompany, $selectedCostCenter)') === false
    && strpos($index, 'demeter_workorder_state_cache_begin_rebuild($selectedCompany, $selectedCostCenter)') !== false, 'pagina: force_full wist niets meer (begin_rebuild)');
check(strpos($runner, 'demeter_workorder_state_cache_begin_rebuild($company, $costCenter)') !== false, 'nightly: force_full via begin_rebuild');
check(preg_match('/demeter_month_scan_history_complete\(\$monthScan\)\)\s*\{[^}]*demeter_workorder_state_cache_finish_rebuild/', $loader) === 1, 'week-opslag ruimt vorige rijen op zodra de historie compleet is');
check(strpos($index, 'demeter_workorder_state_cache_needs_resume($selectedCompany, $selectedCostCenter)') !== false, 'pagina hervat een onderbroken verversing');
check(strpos($index, "'resume_skip_scanned' => \$resumeRebuild && \$forceFull && !\$catchUp") !== false, 'load_month geeft resume door');
check(strpos($index, 'demeter_workorder_state_cache_display_rows_for_page(') !== false, 'eerste paint via display_rows_for_page (vorige rijen tijdens herbouw)');
check(strpos($index, "'keep_display_rows' => \$asyncLoadEnabled && \$cacheUsedForFirstPaint") !== false, 'tabel blijft staan tijdens herbouw');
check(strpos($index, '@ignore_user_abort(true)') !== false && strpos($index, 'demeter_raise_memory_limit_for_week_load()') !== false, 'load_month: ignore_user_abort + ruimer geheugen');
check(strpos($index, 'demeter_emit_fatal_json_response($fatalMessage)') !== false, 'load_month: fatal => JSON-fout naar de browser');
check(preg_match("/if \\(!empty\\(\\\$GLOBALS\\['demeter_json_action'\\]\\)\\)\\s*\\{\\s*return;/", $index) === 1, 'globale time-out-handler stuurt geen HTML-wachtscherm bij load_month');
check(strpos($index, "=== 'report_load_failure'") !== false, 'endpoint om een opgegeven verversing te melden');

exit($failures === 0 ? 0 : 1);
