<?php
/**
 * Fase 0: locks op alle cache-schrijvers (re-entrant, gelijktijdige schrijvers), nightly wacht/slaat over
 * bij een lopende herbouw, 'vandaag'-query met afdeling + einddatum, 0001-01-01 apart, nightly-statusverversing.
 * Run: php tests/phase0_locks_and_queries_test.php
 */

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x']];

require_once __DIR__ . '/../web/odata.php';
require_once __DIR__ . '/../web/bc_fetch/nightly_runner.php';

if (($argv[1] ?? '') === '--child') {
    // Gelijktijdige schrijver: 25× lezen-wijzigen-schrijven onder de lock.
    [$company, $cc] = [$argv[2], $argv[3]];
    for ($i = 0; $i < 25; $i++) {
        demeter_workorder_state_cache_with_lock($company, $cc, static function () use ($company, $cc): void {
            $rows = demeter_workorder_state_cache_load_display_rows($company, $cc);
            $n = (int) ($rows['teller']['Count'] ?? 0);
            usleep(2000);
            $rows['teller'] = ['Row_Key' => 'teller', 'No' => 'T', 'Cost_Center' => $cc, 'Count' => $n + 1];
            demeter_workorder_state_cache_save_display_rows($company, $cc, $rows);
        });
    }
    exit(0);
}

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$company = 'Testbedrijf fase0';
$cc = '98';
demeter_workorder_state_cache_purge($company, $cc);
$lockPath = demeter_workorder_state_cache_path($company, $cc) . '.lock';

// 1. Re-entrant lock
$outer = demeter_workorder_state_cache_lock($company, $cc);
$t = microtime(true);
$inner = demeter_workorder_state_cache_lock($company, $cc, 2);
check(is_resource($inner) && microtime(true) - $t < 1.0, 'geneste lock in hetzelfde proces wacht niet op zichzelf');
demeter_workorder_state_cache_unlock($inner);
$other = fopen($lockPath, 'c');
check(!flock($other, LOCK_EX | LOCK_NB), 'na binnenste unlock blijft de buitenste lock vast');
demeter_workorder_state_cache_unlock($outer);
check(flock($other, LOCK_EX | LOCK_NB), 'na buitenste unlock is de lock vrij');
check(demeter_workorder_state_cache_lock_is_held_elsewhere($company, $cc), 'lock_is_held_elsewhere ziet een andere houder');
flock($other, LOCK_UN);
check(!demeter_workorder_state_cache_lock_is_held_elsewhere($company, $cc), 'lock_is_held_elsewhere false als vrij');

// 2. Nightly wacht beleefd en slaat over zolang een ander de lock heeft
flock($other, LOCK_EX);
$sleeps = 0;
$free = demeter_nightly_wait_until_cost_center_free($company, $cc, 30, 15, static function (int $s) use (&$sleeps): void {
    $sleeps++;
});
check($free === false && $sleeps === 2, 'nightly slaat kostenplaats over als herbouw de lock houdt (na wachten)');
flock($other, LOCK_UN);
check(demeter_nightly_wait_until_cost_center_free($company, $cc, 30, 15, static function (int $s): void {
}) === true, 'nightly gaat door als de lock vrij is');
fclose($other);

// 3. Lock-time-out geeft een nette fout i.p.v. ongelockt schrijven
$blocker = fopen($lockPath, 'c');
flock($blocker, LOCK_EX);
$threw = false;
try {
    demeter_workorder_state_cache_with_lock($company, $cc, static function (): void {
    }, 1);
} catch (RuntimeException $e) {
    $threw = true;
}
check($threw, 'with_lock gooit als de lock bezet blijft (geen ongelockte write)');
flock($blocker, LOCK_UN);
fclose($blocker);

// 4. Gelijktijdige schrijvers: 2 processen × 25 increments → 50 (geen lost update)
$procs = [];
for ($p = 0; $p < 2; $p++) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, '--child', $company, $cc], [], $pipes);
}
foreach ($procs as $proc) {
    proc_close($proc);
}
$rows = demeter_workorder_state_cache_load_display_rows($company, $cc);
check((int) ($rows['teller']['Count'] ?? 0) === 50, 'twee gelijktijdige schrijvers: 50 van 50 updates bewaard (' . (int) ($rows['teller']['Count'] ?? 0) . ')');

// 5. Memo-opslag onder lock werkt nog
demeter_workorder_state_cache_save_display_rows($company, $cc, ['k1' => ['Row_Key' => 'k1', 'No' => 'WO1', 'Cost_Center' => $cc]]);
check(demeter_persist_workorder_memos_to_display_cache($company, $cc, ['k1' => ['notes' => [['t' => 'x']], 'notes_search' => 'x']]), 'memo-opslag (onder lock) schrijft');
check(!empty(demeter_workorder_state_cache_load_display_rows($company, $cc)['k1']['Memos_Loaded']), 'memo staat in display-cache');

// 6. 'Vandaag'-query: afdeling + einddatum, 0001-01-01 apart
$urls = [];
$GLOBALS['DEMETER_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$urls): array {
    $urls[] = rawurldecode($url);
    if (strpos(rawurldecode($url), 'Start_Date eq 0001-01-01') !== false) {
        return [['No' => 'WO-UNDATED', 'Start_Date' => '0001-01-01', 'Job_Dimension_1_Value' => '70', 'Status' => 'Open']];
    }

    return [['No' => 'WO-TODAY', 'Start_Date' => date('Y-m-d'), 'Job_Dimension_1_Value' => '70', 'Status' => 'Open']];
};
$today = new DateTimeImmutable('today');
$rowsToday = bc_fetch_workorders_by_start_date_range('KvT', $today, $today->modify('+1 day'), [], 180, true, '70');
check(count($urls) === 2, 'vandaag: twee queries (bereik + 0001-01-01)');
check(strpos($urls[0], 'Start_Date lt ' . $today->modify('+1 year')->format('Y-m-d')) !== false, 'vandaag-query heeft een einddatum (+1 jaar)');
check(strpos($urls[0], "Job_Dimension_1_Value eq '70' or Job_Dimension_1_Value eq ''") !== false, 'vandaag-query alleen eigen afdeling (+ lege kop)');
check(strpos($urls[1] ?? '', "Start_Date eq 0001-01-01 and (Job_Dimension_1_Value eq '70'") !== false, '0001-01-01 apart opgehaald voor de afdeling');
check(in_array('WO-UNDATED', array_column($rowsToday, 'No'), true), 'werkorder met 0001-01-01 zit in het resultaat');
$urls = [];
bc_fetch_workorders_by_start_date_range('KvT', $today->modify('-14 day'), $today->modify('-7 day'), [], 180, false, '70');
check(count($urls) === 1 && strpos($urls[0], '0001-01-01') === false, 'verleden week: geen extra 0001-01-01-query');

// 7. Nightly: status van ALLE open werkorders in de cache verversen
demeter_workorder_state_cache_save_display_rows($company, $cc, [
    'a' => ['Row_Key' => 'a', 'Bc_No' => 'WOA', 'Job_No' => 'P1', 'Job_Task_No' => 'WOA', 'Status' => 'Open', 'Cost_Center' => $cc],
    'b' => ['Row_Key' => 'b', 'Bc_No' => 'WOB', 'Job_No' => 'P1', 'Job_Task_No' => 'WOB', 'Status' => 'Open', 'Cost_Center' => $cc],
    'c' => ['Row_Key' => 'c', 'Bc_No' => 'WOC', 'Job_No' => 'P2', 'Job_Task_No' => 'WOC', 'Status' => 'Planned', 'Cost_Center' => $cc],
    'd' => ['Row_Key' => 'd', 'Bc_No' => 'WOD', 'Job_No' => 'P2', 'Job_Task_No' => 'WOD', 'Status' => 'Closed', 'Cost_Center' => $cc],
]);
$GLOBALS['DEMETER_ODATA_BC_FETCH'] = static function (string $url): array {
    $u = rawurldecode($url);
    if (strpos($u, "Status ne 'Closed'") !== false) {
        return [['No' => 'WOA', 'Job_No' => 'P1', 'Job_Task_No' => 'WOA', 'Status' => 'In Progress', 'KVT_Document_Status' => '20']];
    }
    if (strpos($u, "No eq 'WOB'") !== false) {
        return [['No' => 'WOB', 'Job_No' => 'P1', 'Job_Task_No' => 'WOB', 'Status' => 'Invoiced', 'KVT_Document_Status' => '90']];
    }

    return [];
};
$res = demeter_nightly_refresh_open_workorder_statuses($company, $cc, [], 180);
$after = demeter_workorder_state_cache_load_display_rows($company, $cc);
check($after['a']['Status'] === 'In Progress', 'open → In Progress bijgewerkt (oude week)');
check($after['b']['Status'] === 'Invoiced', 'open → Invoiced gevonden via nummer-check');
check($res['not_found'] === 1 && $res['open_in_cache'] === 3, 'verwijderde werkorder gemeld (not_found=1), 3 open in cache');
check($after['d']['Status'] === 'Closed', 'afgesloten rij ongemoeid');
unset($GLOBALS['DEMETER_ODATA_BC_FETCH']);

demeter_workorder_state_cache_purge($company, $cc);
@unlink($lockPath);
exit($failures === 0 ? 0 : 1);
