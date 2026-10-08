<?php
/**
 * Page-open sync: harde per-request timeout / 409 → netjes afbreken. Afgeronde stappen blijven, het
 * afgebroken deel blijft ongewijzigd, synced_at gaat niet vooruit (volgende page-open probeert opnieuw),
 * en de afbreking staat in de syncstatus.
 * Run: php tests/store_sync_abort_test.php
 */

require_once __DIR__ . '/../web/bc_fetch/store.php';
require_once __DIR__ . '/../web/bc_fetch/store_transport.php';
require_once __DIR__ . '/store_fake_bc.php';

$GLOBALS['DEMETER_STORE_BASE_DIR'] = sys_get_temp_dir() . '/demeter_store_abort_' . getmypid();

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$company = 'Testbedrijf abort';
$bc = new DemeterFakeBc();
for ($i = 1; $i <= 120; $i++) {
    $bc->addWo('WO' . $i, '70', $i % 3 === 0 ? 'Open' : 'Closed', date('Y-m-d', strtotime('2025-01-01 +' . $i . ' day')),
        ['Created_Date_Time' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2025-01-01 00:00:00 UTC') + $i * 3600)]);
}
$bc->addPosting('WO3', 'Usage', 10.0, 12.0);
demeter_store_nightly_snapshot($company, $bc->transport());
$before = demeter_store_read($company);
$s = $before;
$s['synced_at'] = time() - 600;
demeter_store_write($company, $s);
$staleSyncedAt = $s['synced_at'];

// Wijzigingen in BC: nieuwe kostenpost + statuswijziging (vereist bucket-fetch, die we laten afbreken).
$bc->addPosting('WO3', 'Usage', 5.0, 6.0);
$bc->workorders['WO6']['Status'] = 'Invoiced';
$t = $bc->transport();
$failingTransport = $t;
$failingTransport['fetch'] = static function (string $entity, array $query) use ($t): array {
    if ($entity === 'Werkorders' && strpos((string) ($query['$filter'] ?? ''), 'Status eq') !== false) {
        throw new DemeterStoreSyncAbort('timeout na 20.0 s (limiet 20 s)');
    }

    return $t['fetch']($entity, $query);
};
$res = demeter_store_page_open_sync($company, '70', $failingTransport);
$after = demeter_store_read($company);
check($res['status'] === 'aborted', 'status aborted bij timeout in de bucket-fetch');
check(($after['last_sync']['aborted']['step'] ?? '') === 'counts' && strpos($after['last_sync']['aborted']['reason'], 'timeout') !== false, 'afbreking (stap + reden) in de syncstatus');
check((int) $after['synced_at'] === $staleSyncedAt, 'synced_at niet vooruit: volgende page-open probeert opnieuw');
check(demeter_store_finance_for($after, 'WO3')['cost'] === 15.0, 'afgeronde stap (nieuwe kostenpost) wel bewaard');
check($after['workorders'] === $before['workorders'], 'afgebroken deel (werkorders) ongewijzigd, niets half geschreven');
check(glob($GLOBALS['DEMETER_STORE_BASE_DIR'] . '/*.tmp.*') === [], 'geen temp-restanten');

// 409 al in de eerste stap (posten): store helemaal ongewijzigd behalve de syncstatus.
$bc->addPosting('WO9', 'Usage', 1.0, 1.0);
$t409 = $t;
$t409['fetch'] = static function (string $entity, array $query): array {
    throw new DemeterStoreSyncAbort('HTTP 409 van BC');
};
$beforeSecond = demeter_store_read($company);
$res = demeter_store_page_open_sync($company, '70', $t409);
$after = demeter_store_read($company);
check($res['status'] === 'aborted' && ($after['last_sync']['aborted']['step'] ?? '') === 'postings', '409 in de postenstap → aborted (postings)');
check((int) $after['max_entry_no'] === (int) $beforeSecond['max_entry_no'] && $after['finance'] === $beforeSecond['finance'], 'posten/max_entry_no ongewijzigd na 409');

// Volgende page-open (BC weer in orde): alles bijgewerkt.
$res = demeter_store_page_open_sync($company, '70', $bc->transport());
$after = demeter_store_read($company);
check($res['status'] === 'synced' && $after['workorders']['WO6']['Status'] === 'Invoiced' && demeter_store_finance_for($after, 'WO9')['cost'] === 1.0, 'volgende page-open haalt alles in');
check($after['last_sync']['aborted'] === null && $after['synced_at'] >= time() - 5, 'syncstatus weer schoon, synced_at vooruit');

// Echte HTTP: harde timeout en geen retries op 409 (lokale nep-BC).
$dir = $GLOBALS['DEMETER_STORE_BASE_DIR'];
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
file_put_contents($dir . '/router.php', '<?php
file_put_contents(__DIR__ . "/hits", $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND);
if (strpos($_SERVER["REQUEST_URI"], "slow") !== false) { sleep(4); }
if (strpos($_SERVER["REQUEST_URI"], "conflict") !== false) { http_response_code(409); echo "{}"; return; }
header("Content-Type: application/json"); echo json_encode(["value" => [["No" => "WO1"]], "@odata.count" => 1]);
');
// 3 workers: de trage request mag de 409-test niet blokkeren (php -S is anders single-threaded).
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '3']));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$base = 'http://127.0.0.1:' . $port . '/';
check((demeter_store_http_get_json($base . 'ok', [], 2)['@odata.count'] ?? null) === 1, 'strikte GET: normaal antwoord');
$t0 = microtime(true);
$msg = '';
try {
    demeter_store_http_get_json($base . 'slow', [], 1);
} catch (DemeterStoreSyncAbort $e) {
    $msg = $e->getMessage();
}
check(strpos($msg, 'timeout') !== false && microtime(true) - $t0 < 2.5, 'harde timeout (1 s) breekt af: "' . $msg . '"');
$msg = '';
try {
    demeter_store_http_get_json($base . 'conflict', [], 2);
} catch (DemeterStoreSyncAbort $e) {
    $msg = $e->getMessage();
}
$conflictHits = count(array_filter(file($dir . '/hits') ?: [], static function (string $l): bool {
    return strpos($l, 'conflict') !== false;
}));
check($msg === 'HTTP 409 van BC' && $conflictHits === 1, '409 → direct afbreken, geen retries (' . $conflictHits . ' request)');
proc_terminate($server);
proc_close($server);

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
exit($failures === 0 ? 0 : 1);
