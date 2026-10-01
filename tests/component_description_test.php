<?php
/**
 * Component Description mag de equipmentsoort (Sub_Entity_Description) niet tonen.
 * Run: php tests/component_description_test.php
 */

require dirname(__DIR__) . '/web/workorder_rows.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

$equipmentKind = 'Schroefcompressor';
$componentDescription = 'Oliefilter element';

$copied = bc_fetch_resolve_component_description([
    'No' => 'WO-1',
    'Job_No' => 'P-1',
    'Job_Task_No' => 'T-1',
    'Start_Date' => '2026-03-02',
    'Sub_Entity_Description' => $equipmentKind,
    'Component_Description' => $equipmentKind,
], '');

if (($copied['Component_Description'] ?? null) !== '') {
    fail('gekopieerde equipmentsoort moet uit Component_Description');
}
if (($copied['Sub_Entity_Description'] ?? '') !== $equipmentKind) {
    fail('equipmentsoort moet op Sub_Entity_Description blijven');
}

$fromApp = bc_fetch_resolve_component_description($copied, $componentDescription);
if (($fromApp['Component_Description'] ?? '') !== $componentDescription) {
    fail('AppWerkorders Component_Description moet de kolom vullen');
}
if (($fromApp['Sub_Entity_Description'] ?? '') !== $equipmentKind) {
    fail('equipmentsoort mag niet verdwijnen als de component-Description gezet wordt');
}

$kept = bc_fetch_resolve_component_description([
    'Component_Description' => $componentDescription,
    'Sub_Entity_Description' => $equipmentKind,
], '');
if (($kept['Component_Description'] ?? '') !== $componentDescription) {
    fail('een echte component-Description mag niet gewist worden als AppWerkorders leeg is');
}

$rowKey = bc_fetch_werkorder_row_key([
    'No' => 'WO-1',
    'Job_No' => 'P-1',
    'Job_Task_No' => 'T-1',
    'Start_Date' => '2026-03-02',
]);
$applied = bc_fetch_apply_component_descriptions([
    [
        'No' => 'WO-1',
        'Job_No' => 'P-1',
        'Job_Task_No' => 'T-1',
        'Start_Date' => '2026-03-02',
        'Sub_Entity_Description' => $equipmentKind,
        'Component_Description' => $equipmentKind,
    ],
    [
        'No' => 'WO-2',
        'Job_No' => 'P-1',
        'Job_Task_No' => 'T-9',
        'Start_Date' => '2026-03-03',
        'Sub_Entity_Description' => 'Ketel',
    ],
], [
    $rowKey => $componentDescription,
], [
    demeter_workorder_pair_key('P-1', 'T-9') => 'Branderdek',
]);

if (($applied[0]['Component_Description'] ?? '') !== $componentDescription) {
    fail('rij-key moet de component-Description van AppWerkorders gebruiken');
}
if (($applied[0]['Sub_Entity_Description'] ?? '') !== $equipmentKind) {
    fail('rij-key match mag Sub_Entity_Description niet overschrijven');
}
if (($applied[1]['Component_Description'] ?? '') !== 'Branderdek') {
    fail('job+taak-fallback moet de component-Description gebruiken');
}
if (($applied[1]['Sub_Entity_Description'] ?? '') !== 'Ketel') {
    fail('task-fallback mag de equipmentsoort niet vervangen');
}

$ui = demeter_build_single_workorder_row([
    'No' => 'WO-1',
    'Job_No' => 'P-1',
    'Job_Task_No' => 'T-1',
    'Component_No' => 'C-100',
    'Component_Description' => $componentDescription,
    'Sub_Entity_Description' => $equipmentKind,
    'Task_Description' => 'Onderhoud',
    'Start_Date' => '2026-03-02',
], 'both', [], [], [], [], []);

if (!is_array($ui)) {
    fail('UI-rij ontbreekt');
}
if (($ui['Component_Description'] ?? '') !== $componentDescription) {
    fail('UI Component Description toont niet de component-Description');
}
if (($ui['Equipment_Name'] ?? '') !== $equipmentKind) {
    fail('UI Equipment_Name moet de equipmentsoort houden');
}

$uiFromKindOnly = demeter_build_single_workorder_row([
    'No' => 'WO-3',
    'Job_No' => 'P-1',
    'Job_Task_No' => 'T-3',
    'Component_No' => 'C-300',
    'Sub_Entity_Description' => $equipmentKind,
    'Start_Date' => '2026-03-04',
], 'both', [], [], [], [], []);

if (!is_array($uiFromKindOnly)) {
    fail('UI-rij zonder component-Description ontbreekt');
}
if (($uiFromKindOnly['Component_Description'] ?? null) !== '') {
    fail('UI mag Sub_Entity_Description niet als Component Description tonen');
}
if (($uiFromKindOnly['Equipment_Name'] ?? '') !== $equipmentKind) {
    fail('equipmentsoort moet beschikbaar blijven als Equipment_Name');
}

$select = bc_fetch_app_werkorders_select();
if (strpos($select, 'Component_Description') === false) {
    fail('AppWerkorders-select moet Component_Description bevatten');
}

$GLOBALS['baseUrl'] = 'https://bc.example:7148/';
$GLOBALS['environment'] = 'Production';

function odata_get_all(string $url, array $auth, $ttlSeconds = 300): array
{
    if (strpos($url, 'AppWerkorders') === false) {
        fail('onverwachte OData-call: ' . $url);
    }

    return [
        [
            'No' => 'APP-A',
            'Job_No' => 'JOB-A',
            'Job_Task_No' => '100',
            'Start_Date' => '2026-01-01',
            'Component_Description' => 'Desc A',
        ],
        [
            'No' => 'APP-B',
            'Job_No' => 'JOB-B',
            'Job_Task_No' => '100',
            'Start_Date' => '2026-01-02',
            'Component_Description' => 'Desc B',
        ],
    ];
}

$sharedTaskRows = bc_fetch_enrich_workorder_rows_with_component_descriptions('KVT Gas', [
    [
        'No' => 'WO-A',
        'Job_No' => 'JOB-A',
        'Job_Task_No' => '100',
        'Start_Date' => '2026-02-01',
        'Sub_Entity_Description' => 'Soort A',
    ],
    [
        'No' => 'WO-B',
        'Job_No' => 'JOB-B',
        'Job_Task_No' => '100',
        'Start_Date' => '2026-02-02',
        'Sub_Entity_Description' => 'Soort B',
    ],
], ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);

if (($sharedTaskRows[0]['Component_Description'] ?? '') !== 'Desc A') {
    fail('job A mag niet de component-Description van job B krijgen bij gedeeld taaknummer');
}
if (($sharedTaskRows[1]['Component_Description'] ?? '') !== 'Desc B') {
    fail('job B mag niet de component-Description van job A krijgen bij gedeeld taaknummer');
}
if (($sharedTaskRows[0]['Sub_Entity_Description'] ?? '') !== 'Soort A' || ($sharedTaskRows[1]['Sub_Entity_Description'] ?? '') !== 'Soort B') {
    fail('equipmentsoort moet per job blijven staan');
}

fwrite(STDOUT, "OK\n");
