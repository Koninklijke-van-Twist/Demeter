<?php
// Ververs Nu-statusverversing: chunks, voortgang, meeliften, step-lock, max_age 0, vorige rijen bijgewerkt.
// Run: php tests/status_refresh_test.php
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x']];
define('DEMETER_STATUS_REFRESH_CHUNK_SIZE', 2);
require_once __DIR__ . '/../web/odata.php';
require_once __DIR__ . '/../web/bc_fetch/status_refresh.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}
function r(string $no, string $job, string $status): array
{
    return ['Row_Key' => $job . '|' . $no, 'Bc_No' => $no, 'No' => $no, 'Job_No' => $job, 'Job_Task_No' => $no, 'Status' => $status, 'KVT_Document_Status' => '30', 'Cost_Center' => '70'];
}

$company = 'StatusRefreshTest' . getmypid();
$cc = '70';
demeter_workorder_state_cache_save_display_rows($company, $cc, [
    'PRJ2600496|WO2600494' => r('WO2600494', 'PRJ2600496', 'Gecontroleerd'),
    'PRJ2600494|WO2600492' => r('WO2600492', 'PRJ2600494', 'Open'),
    'P|WO3' => r('WO3', 'P', 'Open'),
    'P|WO4' => r('WO4', 'P', 'Afgesloten'),
]);
// vorige rijen (tijdens herbouw zichtbaar), met version/rows-wrapper
$prevPath = demeter_workorder_state_cache_previous_display_rows_path($company, $cc);
file_put_contents($prevPath, json_encode(['version' => 13, 'rows' => ['PRJ2600496|WO2600494' => r('WO2600494', 'PRJ2600496', 'Gecontroleerd')]]));

$ttls = [];
$GLOBALS['DEMETER_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$ttls): array {
    $ttls[] = $ttl;
    $u = rawurldecode($url);
    $out = [];
    if (strpos($u, "No eq 'WO2600494'") !== false) {
        $out[] = ['No' => 'WO2600494', 'Job_No' => 'PRJ2600496', 'Job_Task_No' => 'WO2600494', 'Status' => 'Afgesloten', 'KVT_Document_Status' => '60'];
    }
    if (strpos($u, "No eq 'WO2600492'") !== false) {
        $out[] = ['No' => 'WO2600492', 'Job_No' => 'PRJ2600494', 'Job_Task_No' => 'WO2600492', 'Status' => 'Open', 'KVT_Document_Status' => '30'];
    }

    return $out;
};

$start = demeter_status_refresh_start($company, $cc);
check(!$start['joined'] && $start['job']['workorders_total'] === 3 && $start['job']['chunks_total'] === 2, '3 open werkorders (afgesloten overgeslagen) in 2 chunks');
$again = demeter_status_refresh_start($company, $cc);
check($again['joined'] && $again['job']['started_at'] === $start['job']['started_at'], 'tweede start lift mee op lopende job');

// Andere tab houdt de step-lock: alleen voortgang, geen BC-request.
$lock = fopen(demeter_status_refresh_path($company, $cc) . '.step.lock', 'c');
flock($lock, LOCK_EX);
$child = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    '$baseUrl="x";$environment="P";require "' . __DIR__ . '/../web/odata.php";require "' . __DIR__ . '/../web/bc_fetch/status_refresh.php";'
    . '$GLOBALS["DEMETER_ODATA_BC_FETCH"]=function(){echo "FETCH";return [];};'
    . 'echo json_encode(demeter_status_refresh_step(' . var_export($company, true) . ',"70",[]));'
));
flock($lock, LOCK_UN);
fclose($lock);
check(strpos((string) $child, 'FETCH') === false && strpos((string) $child, '"chunks_done":0') !== false, 'step terwijl andere tab stapt: alleen voortgang (0/2), geen request');

// Budget 0 => max. 1 chunk per stap (na de eerste chunk is de deadline voorbij).
$p1 = demeter_status_refresh_step($company, $cc, [], 0);
check($p1['status'] === 'running' && $p1['chunks_done'] === 1 && $p1['percent'] === 50, 'voortgang 1/2 = 50%');
$rows = demeter_workorder_state_cache_load_display_rows($company, $cc);
check($rows['PRJ2600496|WO2600494']['Status'] === 'Afgesloten' && $rows['PRJ2600496|WO2600494']['KVT_Document_Status'] === '60', 'WO2600494 direct Afgesloten in cache (na eerste chunk)');
$prev = json_decode((string) file_get_contents($prevPath), true);
check(($prev['version'] ?? null) === 13 && $prev['rows']['PRJ2600496|WO2600494']['Status'] === 'Afgesloten', 'vorige rijen ook bijgewerkt, wrapper behouden');
$p2 = demeter_status_refresh_step($company, $cc, [], 20);
check($p2['status'] === 'completed' && $p2['chunks_done'] === 2 && $p2['updated'] >= 1, 'klaar: 2/2');
// odata_get_all geeft ttl 0 als max_age 0 door aan Mímir; de directe-BC-testhook klemt op 1 (directe BC is altijd live).
check($ttls !== [] && max($ttls) <= 1, 'alle requests live (ttl 0)');
$rows = demeter_workorder_state_cache_load_display_rows($company, $cc);
check($rows['P|WO4']['Status'] === 'Afgesloten' && $rows['PRJ2600494|WO2600492']['Status'] === 'Open', 'andere rijen niet gemengd');
$new = demeter_status_refresh_start($company, $cc);
check(!$new['joined'], 'na voltooiing start Ververs Nu een nieuwe job');

unset($GLOBALS['DEMETER_ODATA_BC_FETCH']);
demeter_workorder_state_cache_purge($company, $cc);
@unlink($prevPath);
$base = demeter_status_refresh_path($company, $cc);
foreach ([$base, $base . '.step.lock', $base . '.start.lock'] as $f) {
    @unlink($f);
}
exit($failures > 0 ? 1 : 0);
