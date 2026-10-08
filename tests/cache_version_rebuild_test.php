<?php
/**
 * Een cacheversie-wissel dwingt echt een volledige herbouw af:
 * - display-cache (first paint, 'Uit cache geladen') heeft een versie; oude/versieloze rijen worden niet getoond;
 * - een state van een oude versie telt als 'stale' (goedkoop uit de kop van het bestand gelezen);
 * - een incrementele week/catch-up op een stale cache faalt vóór er iets wordt opgehaald of weggeschreven
 *   (anders schrijft de huidige week een verse state met alleen die week en is de versiewissel 'verbruikt');
 * - nightly slaat een stale kostenplaats niet over als 'lege cache' en ververst hem volledig;
 * - de pagina start bij een stale cache zelf een volledige verversing.
 * Run: php tests/cache_version_rebuild_test.php
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

$company = 'Testbedrijf cacheversie';
$costCenter = '98';
demeter_workorder_state_cache_purge($company, $costCenter);
$statePath = demeter_workorder_state_cache_path($company, $costCenter);
$displayPath = demeter_workorder_state_cache_display_rows_path($company, $costCenter);
@mkdir(dirname($statePath), 0775, true);

check(DEMETER_WORKORDER_STATE_CACHE_VERSION >= 13, 'cacheversie opgehoogd (v12 is op prod al verbruikt)');

// 1) Geen cache: niet stale.
check(demeter_workorder_state_cache_stored_version($company, $costCenter) === null, 'geen bestand: geen versie');
check(!demeter_workorder_state_cache_is_stale_version($company, $costCenter), 'geen bestand: niet stale');

// 2) Oude state (v12, zoals PR #19 hem achterliet) + versieloze display-cache (oud formaat).
$oldRows = ['k1' => ['Row_Key' => 'k1', 'No' => 'WO1', 'Job_No' => 'PRJ1', 'Cost_Center' => '98']];
file_put_contents($statePath, json_encode([
    'version' => DEMETER_WORKORDER_STATE_CACHE_VERSION - 1,
    'company' => $company,
    'cost_center' => $costCenter,
    'updated_at' => gmdate('c'),
    'workorders' => ['PRJ1|T1' => ['workorder_no' => 'WO1', 'row' => ['No' => 'WO1']]],
    'month_scan' => demeter_workorder_month_scan_defaults(),
]));
file_put_contents($displayPath, json_encode($oldRows));

check(demeter_workorder_state_cache_stored_version($company, $costCenter) === DEMETER_WORKORDER_STATE_CACHE_VERSION - 1, 'oude versie uit de kop gelezen');
check(demeter_workorder_state_cache_is_stale_version($company, $costCenter), 'oude state = stale');
check(demeter_workorder_state_cache_load($company, $costCenter) === null, 'oude state wordt niet gelezen');
check(demeter_workorder_state_cache_load_display_rows($company, $costCenter) === [], 'versieloze display-cache wordt niet getoond (geen "Uit cache geladen" met oude rijen)');
check(demeter_workorder_cost_center_cache_is_populated($company, $costCenter), 'nightly: stale kostenplaats telt als gevuld (niet overslaan)');

// 3) Incrementele week / catch-up op stale cache: exception vóór enige BC-call of schrijfactie.
$mtimeBefore = filemtime($statePath);
$threw = false;
try {
    bc_fetch_load_workorder_week_chunk($company, demeter_current_iso_year_week(), [], 60, null, [
        'cost_center' => $costCenter,
        'partial_to_today' => true,
    ]);
} catch (DemeterStaleCacheVersionException $error) {
    $threw = true;
}
check($threw, 'catch-up (huidige week, incrementeel) weigert stale cache');
$threw = false;
try {
    bc_fetch_load_workorder_week_chunk($company, demeter_previous_iso_year_week(demeter_current_iso_year_week()), [], 60, null, [
        'cost_center' => $costCenter,
    ]);
} catch (DemeterStaleCacheVersionException $error) {
    $threw = true;
}
check($threw, 'oudere week (incrementeel) weigert stale cache');
clearstatcache();
check(demeter_workorder_state_cache_stored_version($company, $costCenter) === DEMETER_WORKORDER_STATE_CACHE_VERSION - 1
    && filemtime($statePath) === $mtimeBefore, 'stale state niet overschreven met een state van alleen de huidige week');

// 4) Display-cache met oude versie in het nieuwe formaat: ook niet tonen.
file_put_contents($displayPath, json_encode(['version' => DEMETER_WORKORDER_STATE_CACHE_VERSION - 1, 'rows' => $oldRows]));
check(demeter_workorder_state_cache_load_display_rows($company, $costCenter) === [], 'display-cache van oude versie wordt niet getoond');

// 5) Na volledige herbouw (huidige versie): alles weer leesbaar.
check(demeter_workorder_state_cache_save($company, $costCenter, ['PRJ1|T1' => ['workorder_no' => 'WO1']], demeter_workorder_month_scan_defaults()), 'nieuwe state opgeslagen');
check(demeter_workorder_state_cache_save_display_rows($company, $costCenter, $oldRows), 'nieuwe display-rijen opgeslagen');
$storedDisplay = json_decode((string) file_get_contents($displayPath), true);
check((int) ($storedDisplay['version'] ?? 0) === DEMETER_WORKORDER_STATE_CACHE_VERSION && isset($storedDisplay['rows']['k1']), 'display-cache bevat versie + rijen');
check(!demeter_workorder_state_cache_is_stale_version($company, $costCenter), 'huidige versie: niet stale');
check(count(demeter_workorder_state_cache_load_display_rows($company, $costCenter)) === 1, 'display-rijen van huidige versie terug te lezen');
check(is_array(demeter_workorder_state_cache_load($company, $costCenter)), 'state van huidige versie terug te lezen');

// 6) Bedrading: nightly forceert volledig, pagina start zelf een volledige verversing, catch-up herlaadt.
$runner = (string) file_get_contents(__DIR__ . '/../web/bc_fetch/nightly_runner.php');
check(preg_match('/!\$forceFull && demeter_workorder_state_cache_is_stale_version\(\$company, \$costCenter\)\)\s*\{\s*\$forceFull = true;/', $runner) === 1, 'nightly: stale cache => force_full');
$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
check(preg_match('/demeter_workorder_state_cache_is_stale_version\(\$selectedCompany, \$selectedCostCenter\)\s*\)\s*\{\s*\$refreshNowRequested = true;\s*\$forceFullReload = true;/', $index) === 1, 'pagina: stale cache => volledige verversing (zoals Ververs Nu)');
check(strpos($index, "'cache_version_stale' => true") !== false, 'load_month catch-up meldt cache_version_stale');

demeter_workorder_state_cache_purge($company, $costCenter);

exit($failures === 0 ? 0 : 1);
