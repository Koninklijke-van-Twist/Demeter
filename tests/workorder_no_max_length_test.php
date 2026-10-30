<?php
/**
 * Regressie 30 okt 2026: taaknummers als 'IMPORT SAP ARBEID JAAR 2024' (>20 tekens) kwamen als kandidaat-werkorder
 * in het OR-filter op Werkorders.No (Code[20]); BC wees dan het hele filter af (Application_FilterErrorException).
 * Run: php tests/workorder_no_max_length_test.php
 */

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x'];
$auth_list = ['Production' => $auth];
$GLOBALS['FAKE_URLS'] = [];
$GLOBALS['DEMETER_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl): array {
    $GLOBALS['FAKE_URLS'][] = rawurldecode($url);

    return [];
};

require_once __DIR__ . '/../web/odata.php';
require_once __DIR__ . '/../web/bc_enum.php';
require_once __DIR__ . '/../web/project_finance.php';
require_once __DIR__ . '/../web/workorder_rows.php';
require_once __DIR__ . '/../web/bc_fetch/month_loader.php';

bc_fetch_workorders_by_numbers('Koninklijke van Twist', ['4087839', 'IMPORT SAP ARBEID JAAR 2024', '12345678901234567890'], $auth, 0);
$all = implode("\n", $GLOBALS['FAKE_URLS']);
$ok = count($GLOBALS['FAKE_URLS']) === 1
    && strpos($all, "'4087839'") !== false
    && strpos($all, "'12345678901234567890'") !== false
    && strpos($all, 'IMPORT SAP') === false;
$GLOBALS['FAKE_URLS'] = [];
bc_fetch_workorders_by_numbers('Koninklijke van Twist', ['IMPORT SAP INHUUR JAAR 2025'], $auth, 0);
$ok = $ok && $GLOBALS['FAKE_URLS'] === [];
echo $ok ? "OK\n" : "FAIL\n" . $all . "\n";
exit($ok ? 0 : 1);
