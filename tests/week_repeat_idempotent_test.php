<?php
/**
 * Een herhaalde week (client-retry) is idempotent: geen dubbele rijen, geen extra lege week.
 * Run: php tests/week_repeat_idempotent_test.php
 */

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x']];

require_once __DIR__ . '/../web/odata.php';
require_once __DIR__ . '/../web/bc_fetch/month_loader.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$scan = demeter_workorder_month_scan_defaults();
$scan = demeter_month_scan_update_after_load('2026-W10', false, false, [], $scan);
check((int) $scan['consecutive_empty'] === 1, 'eerste lege week telt');
$scan = demeter_month_scan_update_after_load('2026-W10', false, false, [], $scan);
check((int) $scan['consecutive_empty'] === 1, 'zelfde lege week opnieuw (retry) telt niet nog eens');
$scan = demeter_month_scan_update_after_load('2026-W09', false, false, [], $scan);
check((int) $scan['consecutive_empty'] === 2, 'volgende lege week telt wel');

$old = $scan;
$old['months']['2026-W09']['scanned_at'] = gmdate('c', time() - DEMETER_MONTH_SCAN_REPEAT_WINDOW_SECONDS - 60);
$old = demeter_month_scan_update_after_load('2026-W09', false, false, [], $old);
check((int) $old['consecutive_empty'] === 3, 'lang geleden gescande week telt als nieuwe scan (oud gedrag)');

$scan = demeter_month_scan_update_after_load('2026-W08', true, false, ['r1'], $scan);
check((int) $scan['consecutive_empty'] === 0, 'week met rijen zet teller op 0');
$scan = demeter_month_scan_update_after_load('2026-W08', true, false, ['r1'], $scan);
check((int) $scan['consecutive_empty'] === 0 && $scan['months']['2026-W08']['row_keys'] === ['r1'], 'herhaalde week met rijen: zelfde resultaat');

$scan = demeter_month_scan_store_week_project_totals($scan, '2026-W08', ['P1' => ['costs' => 100, 'revenue' => 40]]);
$scan = demeter_month_scan_store_week_project_totals($scan, '2026-W08', ['P1' => ['costs' => 100, 'revenue' => 40]]);
$cumulative = demeter_month_scan_cumulative_project_totals($scan);
$p1 = $cumulative['p1'] ?? ($cumulative['P1'] ?? []);
check(abs((float) ($p1['costs'] ?? 0) - 100) < 0.001 && abs((float) ($p1['revenue'] ?? 0) - 40) < 0.001, 'projecttotalen van een herhaalde week niet dubbel');

$weekRows = [
    ['Row_Key' => 'k1', 'No' => 'WO1', 'Job_No' => 'P1', 'Actual_Costs' => 10, 'Total_Revenue' => 0],
    ['Row_Key' => 'k2', 'No' => 'WO2', 'Job_No' => 'P1', 'Actual_Costs' => 5, 'Total_Revenue' => 7],
];
$display = demeter_merge_display_rows_for_month_chunk([], $weekRows, false, []);
// Gewone week-merge telt bedragen per week op (een werkorder heeft posten in meerdere weken);
// daarom gebruikt een herhaalde week (al in month_scan) de delta-merge: nieuw - oud = 0.
$weekTotals = ['p1|wo1' => ['costs' => 10, 'revenue' => 0], 'p1|wo2' => ['costs' => 5, 'revenue' => 7]];
$again = demeter_merge_display_rows_for_open_week_finance_delta($display, $weekRows, $weekTotals, $weekTotals);
check(count($again) === 2, 'display-rijen van een herhaalde week niet dubbel');
check((float) ($again['k1']['Actual_Costs'] ?? 0) === 10.0 && (float) ($again['k2']['Total_Revenue'] ?? 0) === 7.0, 'bedragen van een herhaalde week niet opgeteld');

$loaderSource = (string) file_get_contents(__DIR__ . '/../web/bc_fetch/month_loader.php');
check(strpos($loaderSource, '$needsConsolidation || $isRepeatOfSavedWeek') !== false, 'week-loader kiest de delta-merge voor een herhaalde week');

// Historie compleet = scan heeft zijn einde bereikt; een afgebroken verversing niet.
$broken = demeter_workorder_month_scan_defaults();
$broken = demeter_month_scan_update_after_load('2026-W41', true, false, ['r1'], $broken);
check(demeter_month_scan_history_complete($broken) === false, 'afgebroken verversing: historie niet compleet');
$done = $broken;
$done['stop_before_month'] = '2017-W15';
check(demeter_month_scan_history_complete($done) === true, 'scan tot het einde: historie compleet');
$done2 = $broken;
$done2['consecutive_empty'] = DEMETER_MONTH_SCAN_EMPTY_STOP_COUNT;
check(demeter_month_scan_history_complete($done2) === true, '52 lege weken op rij: historie compleet');

exit($failures === 0 ? 0 : 1);
