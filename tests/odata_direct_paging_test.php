<?php
/**
 * Directe BC-fetch pagineert volledig via @odata.nextLink (Prefer: odata.maxpagesize), zonder $top-cap.
 * Vroeger zette odata_ensure_preferred_page_size `$top=5000` en kapte BC daarmee het totaal af.
 * Run: php tests/odata_direct_paging_test.php
 */

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x']];
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

$dir = sys_get_temp_dir() . '/demeter_paging_' . getmypid();
@mkdir($dir);
$log = $dir . '/requests.log';
$port = 0;
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
file_put_contents($dir . '/router.php', '<?php
$total = 12001; $page = 5000;
$prefer = $_SERVER["HTTP_PREFER"] ?? "";
file_put_contents(' . var_export($log, true) . ', $_SERVER["REQUEST_URI"] . " | " . $prefer . "\n", FILE_APPEND);
parse_str((string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_QUERY), $q);
// Gedraagt zich als BC: $top begrenst het totaal (geen nextLink), anders pagineren.
$limit = isset($q["\$top"]) ? min($total, (int) $q["\$top"]) : $total;
$size = preg_match("/maxpagesize=(\d+)/", $prefer, $m) ? (int) $m[1] : 20000;
$skip = (int) ($q["skip"] ?? 0);
$rows = [];
for ($i = $skip; $i < min($limit, $skip + $size); $i++) { $rows[] = ["No" => "WO" . $i]; }
$out = ["value" => $rows];
if ($skip + $size < $limit) { $out["@odata.nextLink"] = "http://127.0.0.1:' . $port . '/Werkorders?skip=" . ($skip + $size); }
header("Content-Type: application/json"); echo json_encode($out);
');
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $port);
    if ($c) {
        fclose($c);
        break;
    }
    usleep(100000);
}

check(odata_ensure_preferred_page_size('http://x/Werkorders?$select=No') === 'http://x/Werkorders?$select=No', 'geen $top meer op de URL');
$url = 'http://127.0.0.1:' . $port . '/Werkorders?%24select=No&t=' . getmypid();
$rows = odata_get_all_direct($url, ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'], 1);
$requests = file($log, FILE_IGNORE_NEW_LINES) ?: [];
check(count($rows) === 12001, 'alle 12.001 rijen opgehaald (was 5000 met $top-cap): ' . count($rows));
check(count($requests) === 3, '3 pagina\'s via @odata.nextLink: ' . count($requests));
check(strpos($requests[0] ?? '', 'top') === false, 'eerste request zonder $top');
check(strpos($requests[0] ?? '', 'odata.maxpagesize=5000') !== false, 'Prefer: odata.maxpagesize=5000 meegestuurd');
check(count(array_unique(array_column($rows, 'No'))) === 12001, 'geen dubbele rijen');

proc_terminate($server);
proc_close($server);
@unlink(cache_path_for_key(build_cache_key(odata_ensure_preferred_page_size($url), ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'])));
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
exit($failures === 0 ? 0 : 1);
