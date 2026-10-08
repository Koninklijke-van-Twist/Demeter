<?php
/**
 * Factuurcache: een project-entry ouder dan de max-age wordt opnieuw uit BC gehaald (nieuwe factuur zichtbaar);
 * een verse entry niet. Run: php tests/invoice_cache_max_age_test.php
 */
$GLOBALS['baseUrl'] = 'https://bc.example:7148/';
$GLOBALS['environment'] = 'Production';
$GLOBALS['auth'] = ['mode' => 'basic', 'user' => 'x', 'pass' => 'x'];
$GLOBALS['TEST_CALLS'] = 0;
define('DEMETER_INVOICE_CACHE_MAX_AGE_SECONDS', 180);
function odata_mimir_enabled(): bool { return false; }
function odata_get_all(string $url, array $auth, $ttl = 300): array
{
    $GLOBALS['TEST_CALLS']++;
    if (strpos($url, '/SalesInvoiceLines') !== false) {
        return [['Document_No' => 'VFNIEUW', 'Line_No' => 10000, 'Amount' => 250, 'Amount_Including_VAT' => 302.5, 'Job_No' => 'PRJOUD']];
    }
    return [];
}
require __DIR__ . '/../web/project_finance.php';
require_once __DIR__ . '/../web/bc_fetch/invoice_cache.php';
$company = 'Testbedrijf ' . bin2hex(random_bytes(3));
$cache = demeter_invoice_cache_defaults($company);
$cache['projects']['prjoud'] = ['invoice_ids' => [], 'invoiced_total' => 0.0, 'cached_at' => gmdate(DateTimeInterface::ATOM, time() - 3600)];
$cache['projects']['prjvers'] = ['invoice_ids' => ['VFX'], 'invoiced_total' => 10.0, 'cached_at' => gmdate(DateTimeInterface::ATOM)];
demeter_invoice_cache_save($company, $cache);
$r = bc_fetch_resolve_invoices_for_projects($company, ['PRJOUD', 'PRJVERS'], [], 0, false);
@unlink(demeter_invoice_cache_path($company));
if (($r['project_invoiced_total_by_job']['prjoud'] ?? null) !== 250.0 || ($r['project_invoice_ids_by_job']['prjoud'] ?? []) !== ['VFNIEUW']) {
    fwrite(STDERR, 'FAIL verlopen entry niet ververst ' . json_encode($r['project_invoiced_total_by_job']) . PHP_EOL);
    exit(1);
}
if (($r['project_invoiced_total_by_job']['prjvers'] ?? null) !== 10.0 || ($r['load_meta']['fetched_count'] ?? 0) !== 1) {
    fwrite(STDERR, 'FAIL verse entry had uit de cache moeten komen ' . json_encode($r['load_meta']) . PHP_EOL);
    exit(1);
}
echo 'ok verlopen factuur-entry ververst, verse uit cache' . PHP_EOL;
