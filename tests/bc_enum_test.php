<?php
/**
 * NL (Mímir) + EN (directe BC) enumwaarden en company-environment.
 * Run: php tests/bc_enum_test.php
 */

require_once __DIR__ . '/../web/bc_enum.php';
require_once __DIR__ . '/../web/project_finance.php';
require_once __DIR__ . '/../web/bc_fetch/helpers.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

check(demeter_enum_values_equal('Usage', 'Gebruik'), 'Usage == Gebruik');
check(demeter_enum_values_equal('Sale', 'Verkoop'), 'Sale == Verkoop');
check(!demeter_enum_values_equal('Sale', 'Gebruik'), 'Sale != Gebruik');
foreach (['Closed', 'Afgesloten', 'Completed', 'Uitgevoerd', 'Invoiced', 'Gefactureerd', 'Cancelled', 'Geannuleerd', 'Gereed'] as $s) {
    check(demeter_status_is_closed($s), "closed: $s");
}
foreach (['Open', 'In Progress', 'Onderhanden', 'Planned', 'Gepland', ''] as $s) {
    check(!demeter_status_is_closed($s), "open: '$s'");
}
check(finance_is_closed_project_status('Closed') && finance_is_closed_project_status('afgesloten'), 'finance helper shared');
check(demeter_sales_document_type_is_credit('Credit Memo') && demeter_sales_document_type_is_credit('Credit_x0020_Memo') && demeter_sales_document_type_is_credit('Creditnota'), 'credit memo NL/EN');
check(!demeter_sales_document_type_is_credit('Order') && !demeter_sales_document_type_is_credit(''), 'order not credit');

$m = new ReflectionMethod(ProjectFinanceService::class, 'rowMatchesSourceFilter');
$m->setAccessible(true);
check($m->invoke(null, ['Entry_Type' => 'Usage'], "Entry_Type eq 'Gebruik'"), 'filter Gebruik matches Usage');
check($m->invoke(null, ['Entry_Type' => 'Gebruik'], "Entry_Type eq 'Gebruik'"), 'filter Gebruik matches Gebruik');
check(!$m->invoke(null, ['Entry_Type' => 'Sale'], "Entry_Type eq 'Gebruik'"), 'filter Gebruik rejects Sale');

// Company-environment: mapping wint, niet de volgorde van $auth_list.
$GLOBALS['demeter_company_environment_map'] = ['KVT Germany' => 'kvtgermanylive_aad', 'Koninklijke van Twist' => 'kvtmdlive_aad'];
if (!function_exists('odata_bc_mapped_environment')) {
    require_once __DIR__ . '/../web/odata.php';
}
check(bc_fetch_company_environment('KVT Germany') === 'kvtgermanylive_aad', 'KVT Germany → kvtgermanylive_aad');
check(bc_fetch_company_environment('koninklijke van twist') === 'kvtmdlive_aad', 'KvT → kvtmdlive_aad');

exit($failures === 0 ? 0 : 1);
