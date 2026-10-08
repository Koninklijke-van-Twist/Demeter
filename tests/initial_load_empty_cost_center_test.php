<?php
/**
 * Pagina-open voor een kostenplaats zonder cache start zelf de eerste volledige load (i.p.v. alleen
 * 'Geen cachegegevens'); niet voor onbekende kostenplaatsen en niet opnieuw zodra er een state-bestand is
 * (ook niet als die kostenplaats 0 werkorders heeft). Run: php tests/initial_load_empty_cost_center_test.php
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
$company = 'Testbedrijf initial load';
$cc = '70';
$options = [['code' => '70', 'name' => 'Service'], ['code' => '15', 'name' => 'X']];
demeter_workorder_state_cache_purge($company, $cc);
check(demeter_cost_center_needs_initial_load($company, $cc, $options), 'geen cache + bekende kostenplaats → zelf laden');
check(!demeter_cost_center_needs_initial_load($company, '99', $options), 'onbekende kostenplaats → niet laden');
check(!demeter_cost_center_needs_initial_load($company, '', $options), 'geen kostenplaats → niet laden');
check(!demeter_cost_center_needs_initial_load('', $cc, $options), 'geen bedrijf → niet laden');
demeter_workorder_state_cache_save($company, $cc, [], demeter_workorder_month_scan_defaults());
check(!demeter_cost_center_needs_initial_load($company, $cc, $options), 'state-bestand (0 werkorders) → niet bij elke page-open opnieuw');
demeter_workorder_state_cache_purge($company, $cc);
$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
check(strpos($index, 'demeter_cost_center_needs_initial_load($selectedCompany, $selectedCostCenter, $departmentCostCenterOptions)') !== false, 'index.php gebruikt de check bij page-open');
exit($failures === 0 ? 0 : 1);
