<?php
/**
 * Geboekte creditnota's tellen negatief in Invoiced_Total en verschijnen in de Factuur ID-lijst.
 * Run: php tests/credit_memo_invoice_test.php
 */
$GLOBALS['baseUrl'] = 'https://bc.example:7148/';
$GLOBALS['environment'] = 'Production';
$GLOBALS['auth'] = ['mode' => 'basic', 'user' => 'x', 'pass' => 'x'];
function odata_mimir_enabled(): bool { return false; }
function odata_get_all(string $url, array $auth, $ttl = 300): array
{
    if (strpos($url, '/SalesInvoiceLines') !== false) {
        return [['Document_No' => 'S526003816', 'Amount' => 1711, 'Amount_Including_VAT' => 2070.31, 'Job_No' => 'PRJ5262143']];
    }
    if (strpos($url, '/GeboekteVerkoopCreditnotaRegels') !== false) {
        return [['Document_No' => 'C526000162', 'Amount' => 1711, 'Amount_Including_VAT' => 2070.31, 'Job_No' => 'PRJ5262143']];
    }
    return [];
}
require __DIR__ . '/../web/project_finance.php';
$r = (new ProjectFinanceService('Hunter van Twist'))->collectProjectInvoicesForProjects(['PRJ5262143'], 0);
$total = $r['project_invoiced_total_by_job']['prj5262143'] ?? null;
$ids = $r['project_invoice_ids_by_job']['prj5262143'] ?? [];
if (abs((float) $total) > 0.0001 || $ids !== ['C526000162', 'S526003816']) {
    fwrite(STDERR, 'FAIL ' . json_encode([$total, $ids]) . PHP_EOL);
    exit(1);
}
echo 'ok PRJ5262143 nets to 0' . PHP_EOL;
