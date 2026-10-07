<?php
/**
 * Vastgelopen loads moeten zichtbaar falen i.p.v. eeuwig 'running' te blijven.
 * Run: php tests/load_stall_detection_test.php
 */

define('DEMETER_ODATA_CONNECTION_RETRY_MAX_SECONDS_CLI', 2);
define('DEMETER_ODATA_CONNECTION_RETRY_DELAY_MS', 200);
ini_set('error_log', sys_get_temp_dir() . '/demeter-load-stall-test.log');

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x'];
$auth_list = ['Production' => $auth];

require_once __DIR__ . '/../web/odata.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

// 1) Stale-detectie: running zonder update > drempel → error met Nederlandse tijd, geen ISO.
$now = 1791400000; // vaste tijd
$running = [
    'status' => 'running',
    'message' => 'Stap 156 van 208: maand 2026-W03: Facturen laden',
    'updated_at' => $now - DEMETER_LOAD_PROGRESS_STALE_SECONDS - 5,
];
$stale = odata_load_progress_mark_stale($running, $now);
check($stale['status'] === 'error' && $stale['stale'] === true, 'running zonder update → error/stale');
check(strpos($stale['message'], 'Ververs Nu') !== false && strpos($stale['message'], 'Facturen laden') !== false, 'melding noemt stap en herstart');
check(preg_match('/\d{4}-\d{2}-\d{2}T/', $stale['message']) !== 1, 'geen ISO-datum in melding');

// 1b) Vrijgeven alleen als het voortgangsbestand onder lock nog steeds stale is.
$releaseToken = str_repeat('ab', 16);
$releasePath = odata_load_progress_path_for_token($releaseToken);
file_put_contents($releasePath, json_encode(['status' => 'running', 'updated_at' => time() - DEMETER_LOAD_PROGRESS_STALE_SECONDS - 5]));
check(odata_load_progress_release_if_stale($releaseToken) === true, 'stale voortgang → active-load vrijgegeven');
file_put_contents($releasePath, json_encode(['status' => 'running', 'updated_at' => time()]));
check(odata_load_progress_release_if_stale($releaseToken) === false, 'net bijgewerkte voortgang → niet vrijgegeven');
@unlink($releasePath);
check(odata_load_progress_release_if_stale($releaseToken) === false, 'geen voortgangsbestand → niet vrijgegeven');

$fresh = odata_load_progress_mark_stale(array_merge($running, ['updated_at' => $now - 10]), $now);
check($fresh['status'] === 'running' && $fresh['stale'] === false, 'verse voortgang blijft running');
$done = odata_load_progress_mark_stale(array_merge($running, ['status' => 'completed']), $now);
check($done['status'] === 'completed', 'afgeronde load wordt niet stale');

// 2) Nederlandse tijd in Europe/Amsterdam (CEST = UTC+2).
check(odata_format_dutch_datetime(gmmktime(19, 25, 0, 10, 7, 2026)) === '7 oktober 21:25', 'Dutch datetime Europe/Amsterdam');

// 3) Verbindingsfouten worden niet eindeloos herhaald.
$started = microtime(true);
$threw = false;
$message = '';
try {
    odata_get_json('http://127.0.0.1:9/ODataV4/Company(\'X\')/SalesInvoiceLines', $auth);
} catch (Throwable $error) {
    $threw = true;
    $message = $error->getMessage();
}
$elapsed = microtime(true) - $started;
check($threw && strpos($message, 'blijft mislukken') !== false, 'connection retries stoppen met duidelijke fout');
check($elapsed < 30, 'stopt binnen begrensde tijd (' . round($elapsed, 1) . ' s)');

// 4) Cap per SAPI: web korter dan CLI.
check(odata_connection_retry_max_seconds_for_sapi('fpm-fcgi') === 300, 'web cap 300 s');

exit($failures > 0 ? 1 : 0);
