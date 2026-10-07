<?php
/**
 * Cache-bestanden worden atomair geschreven en de samenvoeg-lock is exclusief.
 * Run: php tests/cache_atomic_write_test.php
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

$company = 'Testbedrijf atomair';
$costCenter = '99';
demeter_workorder_state_cache_purge($company, $costCenter);

$rows = ['k1' => ['Row_Key' => 'k1', 'No' => 'WO1', 'Job_No' => 'PRJ1', 'Cost_Center' => '99']];
check(demeter_workorder_state_cache_save_display_rows($company, $costCenter, $rows), 'display-rijen opgeslagen');
check(demeter_workorder_state_cache_save($company, $costCenter, ['a' => ['No' => 'WO1']], demeter_workorder_month_scan_defaults()), 'state opgeslagen');
check(count(demeter_workorder_state_cache_load_display_rows($company, $costCenter)) === 1, 'display-rijen terug te lezen');
check(is_array(demeter_workorder_state_cache_load($company, $costCenter)), 'state terug te lezen');

$base = demeter_workorder_state_cache_path($company, $costCenter);
check(glob($base . '*.tmp.*') === [], 'geen achtergebleven tijdelijke bestanden');

$lock = demeter_workorder_state_cache_lock($company, $costCenter);
check(is_resource($lock), 'lock verkregen');
$other = fopen($base . '.lock', 'c');
check(!flock($other, LOCK_EX | LOCK_NB), 'tweede lock geblokkeerd zolang de eerste loopt');
demeter_workorder_state_cache_unlock($lock);
check(flock($other, LOCK_EX | LOCK_NB), 'lock vrij na unlock');
flock($other, LOCK_UN);
fclose($other);

demeter_workorder_state_cache_purge($company, $costCenter);
@unlink($base . '.lock');

exit($failures === 0 ? 0 : 1);
