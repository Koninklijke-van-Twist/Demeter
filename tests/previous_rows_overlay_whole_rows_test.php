<?php
// Overlay van verse rijen over vorige rijen (#41-vervolg): hele rijen per rijsleutel of project|werkorder,
// nooit velden/bedragen van een andere rij mengen. Run: php tests/previous_rows_overlay_whole_rows_test.php
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
function wo(string $key, string $job, string $no, string $status, float $costs): array
{
    return ['Row_Key' => $key, 'Job_No' => $job, 'No' => $no, 'Bc_No' => $no, 'Status' => $status, 'Actual_Costs' => $costs, 'Description' => 'D-' . $no, 'Cost_Center' => '70'];
}

$previous = [
    'PRJ2600496|WO2600494' => wo('PRJ2600496|WO2600494', 'PRJ2600496', 'WO2600494', 'Gecontroleerd', 100.0),
    'PRJ2600494|WO2600492' => wo('PRJ2600494|WO2600492', 'PRJ2600494', 'WO2600492', 'Gecontroleerd', 50.0),
    // zelfde werkorder onder een oude rijsleutel (ander formaat)
    'old-key-494' => wo('old-key-494', 'PRJ2600496', 'WO2600494', 'Gecontroleerd', 100.0),
];
$fresh = [
    'PRJ2600496|WO2600494' => ['Row_Key' => 'PRJ2600496|WO2600494', 'Job_No' => 'PRJ2600496', 'No' => 'WO2600494', 'Bc_No' => 'WO2600494', 'Status' => 'Afgesloten', 'Actual_Costs' => 120.0],
];
$out = demeter_workorder_state_cache_overlay_fresh_rows($previous, $fresh);

check($out['PRJ2600496|WO2600494'] === $fresh['PRJ2600496|WO2600494'], 'WO2600494: hele verse rij (geen velden uit de vorige rij, geen Description meegenomen)');
check(!isset($out['old-key-494']), 'oude rij met zelfde project|werkorder onder andere sleutel verdwijnt (geen dubbele rij)');
check($out['PRJ2600494|WO2600492'] === $previous['PRJ2600494|WO2600492'], 'WO2600492/PRJ2600494 ongewijzigd, niet gemengd met WO2600494');
check(count($out) === 2, 'geen dubbele of samengevoegde rijen');
check(abs((float) $out['PRJ2600496|WO2600494']['Actual_Costs'] - 120.0) < 0.001, 'bedragen niet opgeteld (120, niet 220/320)');
check($out['PRJ2600496|WO2600494']['Job_No'] === 'PRJ2600496', 'project blijft PRJ2600496');

// Meerdere regels per werkorder in de verse cache: allemaal behouden zoals ze zijn.
$freshMulti = [
    'a' => wo('a', 'PRJ2600496', 'WO2600494', 'Afgesloten', 1.0),
    'b' => wo('b', 'PRJ9', 'WO2600494', 'Afgesloten', 2.0),
];
$out2 = demeter_workorder_state_cache_overlay_fresh_rows($previous, $freshMulti);
check($out2['a'] === $freshMulti['a'] && $out2['b'] === $freshMulti['b'], 'verse rijen ongewijzigd overgenomen');
check(!isset($out2['PRJ2600496|WO2600494']) && isset($out2['PRJ2600494|WO2600492']), 'alleen vorige rijen van dezelfde project|werkorder vervangen');

check(demeter_workorder_state_cache_overlay_fresh_rows($previous, []) === $previous, 'lege verse cache: vorige rijen');

// Fail safe: corrupte nieuwe display-cache => vorige rijen, geen exception.
$company = 'OverlayTest' . getmypid();
$cc = '70';
$prevPath = demeter_workorder_state_cache_previous_display_rows_path($company, $cc);
$dispPath = demeter_workorder_state_cache_display_rows_path($company, $cc);
@mkdir(dirname($prevPath), 0775, true);
file_put_contents($prevPath, json_encode($previous));
file_put_contents($dispPath, '{"version": 13, "rows": {"PRJ');
$threw = false;
try {
    $page = demeter_workorder_state_cache_display_rows_for_page($company, $cc);
} catch (Throwable $e) {
    $threw = true;
}
check(!$threw && ($page['previous'] ?? false) === true && count($page['rows']) >= 2, 'half geschreven nieuwe cache: geen fout, vorige rijen getoond');
@unlink($prevPath);
@unlink($dispPath);

exit($failures > 0 ? 1 : 0);
