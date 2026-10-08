<?php
/**
 * Factuurtotalen: identieke regels (zelfde tekst/bedrag) zijn aparte BC-regels (Line_No) en tellen allebei;
 * dezelfde regeltekst voor verschillende projecten op één factuur telt voor elk project;
 * een mislukte factuurbron wordt gemeld en niet als "0 gefactureerd" gecachet; geen SalesLines-query meer.
 * Run: php tests/invoice_line_dedup_test.php
 */
$GLOBALS['baseUrl'] = 'https://bc.example:7148/';
$GLOBALS['environment'] = 'Production';
$GLOBALS['auth'] = ['mode' => 'basic', 'user' => 'x', 'pass' => 'x'];
$GLOBALS['TEST_URLS'] = [];
$GLOBALS['TEST_FAIL_CREDIT'] = false;
function odata_mimir_enabled(): bool { return false; }
function odata_get_all(string $url, array $auth, $ttl = 300): array
{
    $GLOBALS['TEST_URLS'][] = $url;
    if (strpos($url, '/SalesInvoiceLines') !== false) {
        return [
            // Twee identieke regels op één factuur, zelfde project (bv. 2x "Uren monteur" 500).
            ['Document_No' => 'VF1', 'Line_No' => 10000, 'Description' => 'Uren monteur', 'Amount' => 500, 'Amount_Including_VAT' => 605, 'Job_No' => 'PRJA'],
            ['Document_No' => 'VF1', 'Line_No' => 20000, 'Description' => 'Uren monteur', 'Amount' => 500, 'Amount_Including_VAT' => 605, 'Job_No' => 'PRJA'],
            // Zelfde regeltekst en bedrag voor een ander project op dezelfde factuur.
            ['Document_No' => 'VF1', 'Line_No' => 30000, 'Description' => 'Uren monteur', 'Amount' => 500, 'Amount_Including_VAT' => 605, 'Job_No' => 'PRJB'],
        ];
    }
    if (strpos($url, '/GeboekteVerkoopCreditnotaRegels') !== false) {
        if ($GLOBALS['TEST_FAIL_CREDIT']) {
            throw new RuntimeException('HTTP 500');
        }
        return [['Document_No' => 'CN1', 'Line_No' => 10000, 'Description' => 'Uren monteur', 'Amount' => 500, 'Amount_Including_VAT' => 605, 'Job_No' => 'PRJA']];
    }
    if (strpos($url, '/SalesLines') !== false) {
        throw new RuntimeException('SalesLines mag niet meer opgevraagd worden');
    }
    return [];
}
require __DIR__ . '/../web/project_finance.php';
require_once __DIR__ . '/../web/bc_fetch/invoice_cache.php';
$fail = static function (string $msg, $data = null): void {
    fwrite(STDERR, 'FAIL ' . $msg . ' ' . json_encode($data) . PHP_EOL);
    exit(1);
};

$svc = new ProjectFinanceService('Koninklijke van Twist');
$r = $svc->collectProjectInvoicesForProjects(['PRJA', 'PRJB'], 0);
$totals = $r['project_invoiced_total_by_job'];
if (abs(($totals['prja'] ?? -1) - 500.0) > 0.0001) { // 500 + 500 - 500
    $fail('PRJA moet 500 zijn', $totals);
}
if (abs(($totals['prjb'] ?? -1) - 500.0) > 0.0001) {
    $fail('PRJB moet 500 zijn', $totals);
}
if (count($r['invoice_details_by_id']['VF1']['Lines'] ?? []) !== 3) {
    $fail('VF1 moet 3 regels hebben', $r['invoice_details_by_id']['VF1'] ?? null);
}
if (($r['failed_project_keys'] ?? null) !== []) {
    $fail('geen mislukte projecten verwacht', $r['failed_project_keys'] ?? null);
}
foreach ($GLOBALS['TEST_URLS'] as $u) {
    if (strpos($u, '/SalesLines') !== false) {
        $fail('SalesLines opgevraagd', $u);
    }
    if (strpos(urldecode($u), 'Line_No') === false) {
        $fail('Line_No ontbreekt in select', $u);
    }
}

// Mislukte creditnotabron: gemeld, en de cache-merge slaat die projecten over.
$GLOBALS['TEST_FAIL_CREDIT'] = true;
$r2 = @$svc->collectProjectInvoicesForProjects(['PRJA', 'PRJB'], 0);
sort($r2['failed_project_keys']);
if ($r2['failed_project_keys'] !== ['prja', 'prjb']) {
    $fail('mislukte projecten niet gemeld', $r2['failed_project_keys']);
}
$cache = ['projects' => ['prja' => ['invoice_ids' => ['OUD'], 'invoiced_total' => 123.0, 'cached_at' => 'x']], 'invoice_details_by_id' => []];
demeter_invoice_cache_merge_fetched($cache, $r2, ['prja', 'prjb']);
if (($cache['projects']['prja']['invoiced_total'] ?? null) !== 123.0 || isset($cache['projects']['prjb'])) {
    $fail('mislukte fetch mag cache niet overschrijven', $cache['projects']);
}

// Herhaald samenvoegen van dezelfde factuur verdubbelt de regels niet.
$cache = ['projects' => [], 'invoice_details_by_id' => []];
demeter_invoice_cache_merge_fetched($cache, $r, ['prja', 'prjb']);
demeter_invoice_cache_merge_fetched($cache, $r, ['prja', 'prjb']);
if (count($cache['invoice_details_by_id']['VF1']['Lines'] ?? []) !== 3) {
    $fail('regels verdubbeld bij herhaalde merge', count($cache['invoice_details_by_id']['VF1']['Lines'] ?? []));
}
if (DEMETER_INVOICE_CACHE_VERSION < 4) {
    $fail('cacheversie moet omhoog (oude totalen zijn fout)');
}
echo 'ok invoice line dedup / failed source / merge' . PHP_EOL;
