<?php
/**
 * Regressie KvT/70 (8 okt 2026): na een herbouw stonden alleen week 31-41 in de schermcache; van week 1-30
 * ontbrak het grootste deel van de open werkorders (1614, vooral Gecontroleerd).
 * Oorzaak: de huidige week gaat altijd via het dagpad. Bij force_full (herbouw, hervatten, client-retry van
 * de huidige week) begon dat pad met een lege month_scan en lege display-rijen en schreef die weg: alle
 * eerder door de herbouw geladen weken verdwenen uit de cache.
 * Daarnaast: een week die half geladen is (projectkaart- of factuurbron faalt, bv. BC 409) mag niet als
 * 'gescand' worden opgeslagen; de load faalt en wordt opnieuw geprobeerd.
 * Run: php tests/rebuild_current_week_keeps_weeks_test.php
 */

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x'];
$auth_list = ['Production' => $auth];

$GLOBALS['FAKE_FAIL'] = [];
$GLOBALS['FAKE_WO'] = [];
$GLOBALS['DEMETER_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl): array {
    $decoded = rawurldecode($url);
    foreach ($GLOBALS['FAKE_FAIL'] as $entity) {
        if (strpos($decoded, '/' . $entity . '?') !== false) {
            throw new RuntimeException('HTTP 409 from OData (fake): ' . $entity);
        }
    }
    if (strpos($decoded, '/Werkorders?') !== false && strpos($decoded, 'Start_Date ge') !== false) {
        return $GLOBALS['FAKE_WO'];
    }

    return [];
};

require_once __DIR__ . '/../web/odata.php';
require_once __DIR__ . '/../web/bc_enum.php';
require_once __DIR__ . '/../web/project_finance.php';
require_once __DIR__ . '/../web/workorder_rows.php';
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

function fake_wo(string $no, string $start, string $costCenter, string $status = 'Checked'): array
{
    return [
        'No' => $no, 'Task_Code' => 'SM01', 'Task_Description' => 'Taak', 'Status' => $status,
        'KVT_Document_Status' => '10-OPEN', 'Job_No' => 'PRJ' . $no, 'Job_Task_No' => $no, 'Contract_No' => '',
        'Start_Date' => $start, 'End_Date' => $start, 'Sub_Entity_Description' => '', 'Component_No' => '',
        'Bill_to_Customer_No' => 'C1', 'Bill_to_Name' => 'Klant', 'Sell_to_Customer_No' => 'C1', 'Sell_to_Name' => 'Klant',
        'Job_Dimension_1_Value' => $costCenter, 'Created_Date_Time' => '2026-01-01T08:00:00Z',
    ];
}

$company = 'Testbedrijf dagpad ' . bin2hex(random_bytes(3));
$costCenter = '97';
$currentWeek = demeter_current_iso_year_week();
$oldWeek = '2026-W14';
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

// 1) Een lopende herbouw heeft al een oudere week opgeslagen (huidige cacheversie).
$oldKey = 'PRJWOOUD|WOOUD';
$scan = demeter_workorder_month_scan_defaults();
$scan['months'][$oldWeek] = ['scanned_at' => gmdate('c'), 'has_projectposten' => true, 'empty' => false, 'only_closed_cached' => false, 'row_keys' => [$oldKey]];
demeter_workorder_state_cache_save($company, $costCenter, ['woOud' => ['No' => 'WOOUD']], $scan, demeter_workorder_load_session_defaults());
demeter_workorder_state_cache_save_display_rows($company, $costCenter, [
    $oldKey => ['Row_Key' => $oldKey, 'No' => 'WOOUD', 'Job_No' => 'PRJWOOUD', 'Cost_Center' => $costCenter, 'Status' => 'Checked'],
]);

// 2) Hervatten/herhalen: de huidige week opnieuw met force_full (dagpad).
$GLOBALS['FAKE_WO'] = [fake_wo('WONU', $today, $costCenter, 'Open')];
$chunk = bc_fetch_load_workorder_week_chunk($company, $currentWeek, $auth, 0, null, [
    'cost_center' => $costCenter, 'force_full' => true, 'partial_to_today' => true, 'skip_if_cached' => true,
]);
$state = demeter_workorder_state_cache_load($company, $costCenter);
$months = is_array($state['month_scan']['months'] ?? null) ? $state['month_scan']['months'] : [];
$display = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
$displayNos = array_map(static function ($r) { return $r['No'] ?? ''; }, array_values($display));
check(isset($months[$currentWeek]), 'huidige week staat in month_scan');
check(trim((string) ($months[$oldWeek]['scanned_at'] ?? '')) !== '', 'eerder geladen week ' . $oldWeek . ' blijft in month_scan na force_full-dagload (weken: ' . implode(',', array_keys($months)) . ')');
check(in_array('WOOUD', $displayNos, true), 'rij uit de eerder geladen week blijft in de display-cache (rijen: ' . implode(',', $displayNos) . ')');
check(in_array('WONU', $displayNos, true), 'werkorder van vandaag staat in de display-cache');
check(isset($state['workorders']['woOud']) || isset($state['workorders']['WOOUD']) || count($state['workorders'] ?? []) >= 2, 'werkordermap van eerdere weken blijft behouden');

// 3) Half geladen week: factuurbron faalt (409) -> exception, week NIET als gescand opgeslagen.
$failWeek = '2026-W13';
$monday = (new DateTimeImmutable())->setISODate(2026, 13)->format('Y-m-d');
$GLOBALS['FAKE_WO'] = [fake_wo('WOFACT', $monday, $costCenter)];
$GLOBALS['FAKE_FAIL'] = ['SalesInvoiceLines'];
$threw = false;
try {
    bc_fetch_load_workorder_week_chunk($company, $failWeek, $auth, 0, null, ['cost_center' => $costCenter, 'force_full' => true]);
} catch (Throwable $e) {
    $threw = true;
}
$state = demeter_workorder_state_cache_load($company, $costCenter);
check($threw, 'falende factuurbron laat de week-load falen (niet stil leeg)');
check(!isset($state['month_scan']['months'][$failWeek]), 'half geladen week (facturen) is niet als gescand opgeslagen');

// 4) Half geladen week: projectkaart (kostenplaats bij lege kop) faalt -> exception, niet opgeslagen.
$failWeek2 = '2026-W12';
$monday2 = (new DateTimeImmutable())->setISODate(2026, 12)->format('Y-m-d');
$GLOBALS['FAKE_WO'] = [fake_wo('WOLEEG', $monday2, '')];
$GLOBALS['FAKE_FAIL'] = ['Projecten'];
$threw = false;
try {
    bc_fetch_load_workorder_week_chunk($company, $failWeek2, $auth, 0, null, ['cost_center' => $costCenter, 'force_full' => true]);
} catch (Throwable $e) {
    $threw = true;
}
$state = demeter_workorder_state_cache_load($company, $costCenter);
check($threw, 'falende projectkaart-fetch laat de week-load falen');
check(!isset($state['month_scan']['months'][$failWeek2]), 'half geladen week (projectkaart) is niet als gescand opgeslagen');

// 5) Zonder fouten wordt dezelfde week wel opgeslagen.
$GLOBALS['FAKE_FAIL'] = [];
$GLOBALS['FAKE_WO'] = [fake_wo('WOFACT', $monday, $costCenter)];
bc_fetch_load_workorder_week_chunk($company, $failWeek, $auth, 0, null, ['cost_center' => $costCenter, 'force_full' => true]);
$state = demeter_workorder_state_cache_load($company, $costCenter);
check(trim((string) ($state['month_scan']['months'][$failWeek]['scanned_at'] ?? '')) !== '', 'zonder fout wordt de week wel opgeslagen');
check(trim((string) ($state['month_scan']['months'][$oldWeek]['scanned_at'] ?? '')) !== '', 'oudere week staat er nog steeds');

demeter_workorder_state_cache_purge($company, $costCenter);
@unlink(demeter_invoice_cache_path($company));
exit($failures > 0 ? 1 : 0);
