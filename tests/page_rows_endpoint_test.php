<?php
/**
 * action=page_rows (Tim 9 okt: tabel bijwerken i.p.v. herladen) moet exact dezelfde rijen geven als de
 * pagina-render: display-cache voor de pagina, volledige project- en werkordertotalen, alle factuurfilters.
 * Run: php tests/page_rows_endpoint_test.php
 */
$fail = 0;
function check(bool $ok, string $label): void
{
    global $fail;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $fail++; }
}
$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
$fnPos = strpos($index, 'function demeter_page_rows_payload(');
$fn = $fnPos !== false ? substr($index, $fnPos, 1600) : '';
check($fn !== '', 'demeter_page_rows_payload bestaat');
check(strpos($fn, 'demeter_workorder_state_cache_display_rows_for_page($company, $costCenter)') !== false, 'zelfde bron als de render (display_rows_for_page, incl. vorige rijen tijdens herbouw)');
check(strpos($fn, 'demeter_page_apply_full_project_totals($company, $displayRowsByKey)') !== false, 'zelfde volledige project- en werkordertotalen als de render');
check(strpos($fn, "demeter_filter_display_rows_by_invoice(\$displayRowsByKey, 'both')") !== false, 'alle factuurfilters (client-side gefilterd), zoals de render');
check(strpos($fn, "'previous' =>") !== false, 'meldt of het de vorige rijen van een herbouw zijn (browser herlaadt dan zoals vroeger)');
$renderPos = strpos($index, '$pageDisplay = demeter_workorder_state_cache_display_rows_for_page($selectedCompany, $selectedCostCenter);');
$render = $renderPos !== false ? substr($index, $renderPos, 900) : '';
check(strpos($render, 'demeter_page_apply_full_project_totals($selectedCompany, $displayRowsByKey)') !== false
    && strpos($render, "demeter_filter_display_rows_by_invoice(\$displayRowsByKey, 'both')") !== false, 'render gebruikt dezelfde stappen');
$ep = strpos($index, "=== 'page_rows'");
$endpoint = $ep !== false ? substr($index, $ep, 1400) : '';
check(strpos($endpoint, 'demeter_release_session_lock_if_active()') !== false && strpos($endpoint, 'auth_set_current_company_context(') !== false
    && strpos($endpoint, 'demeter_page_rows_payload(') !== false, 'endpoint: sessie-lock vrij, bedrijfscontext, payload');
exit($fail > 0 ? 1 : 0);
