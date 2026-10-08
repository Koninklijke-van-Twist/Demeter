<?php
/**
 * Fase 1 store (shadow): snapshot + count-verificatie, pagina-open delta-sync, hourly, reconciliatie,
 * met gesimuleerde wijzigingen in BC.
 * Run: php tests/store_sync_test.php
 */

require_once __DIR__ . '/../web/bc_fetch/store.php';
require_once __DIR__ . '/../web/bc_fetch/store_reconcile.php';
require_once __DIR__ . '/store_fake_bc.php';

$GLOBALS['DEMETER_STORE_BASE_DIR'] = sys_get_temp_dir() . '/demeter_store_test_' . getmypid();

if (($argv[1] ?? '') === '--hold-lock') {
    // Andere schrijver: houdt de lock 1,5 s vast en schrijft dan een verse store.
    $GLOBALS['DEMETER_STORE_BASE_DIR'] = $argv[3];
    demeter_store_with_lock($argv[2], static function () use ($argv): void {
        file_put_contents($argv[3] . '/holding', '1');
        usleep(1500000);
        $s = demeter_store_read($argv[2]);
        $s['synced_at'] = time();
        $s['marker'] = 'other-writer';
        demeter_store_write($argv[2], $s);
    }, 5.0);
    exit(0);
}

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

function make_stale(string $company): void
{
    $s = demeter_store_read($company);
    $s['synced_at'] = time() - 600;
    demeter_store_write($company, $s);
}

$company = 'Testbedrijf store';
$bc = new DemeterFakeBc();
$statuses = ['Open', 'Planned', 'In Progress', 'Checked', 'Signed', 'Closed', 'Completed', 'Cancelled', 'Invoiced'];
for ($i = 1; $i <= 400; $i++) {
    $afd = $i % 10 === 0 ? '' : ($i % 7 === 0 ? '50' : '70');
    $date = $i % 25 === 0 ? '0001-01-01' : date('Y-m-d', strtotime('2024-01-01 +' . ($i * 2) . ' day'));
    $bc->addWo('WO' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), $afd, $statuses[$i % 9], $date);
}
foreach (array_slice(array_keys($bc->workorders), 0, 120) as $no) {
    $bc->addPosting($no, 'Usage', 100.0, 150.0);
    $bc->addPosting($no, 'Sale', 0.0, -150.0);
}
$bc->addPosting('', 'Usage', 10.0, 12.0, 'PRJX', '000-010');

// 1. Nightly snapshot
$r = demeter_store_nightly_snapshot($company, $bc->transport());
$store = demeter_store_read($company);
check($r['status'] === 'ok' && count($store['workorders']) === 400, 'snapshot: 400 werkorders, counts kloppen, ingewisseld');
check(demeter_store_finance_for($store, 'WO0001')['cost'] === 100.0 && demeter_store_finance_for($store, 'WO0001')['sale_amount'] === -150.0, 'snapshot: kosten/opbrengst per werkorder');
check((int) $store['max_entry_no'] === max(array_column($bc->postings, 'Entry_No')), 'snapshot: max Entry_No');
check(count(array_filter($store['workorders'], static function ($w) {
    return $w['Start_Date'] === '0001-01-01';
})) === 16, 'snapshot: werkorders met 0001-01-01 aanwezig');

// 2. Verse store: geen BC-calls bij pagina-open
$bc->calls = ['count' => 0, 'fetch' => 0, 'rows' => 0];
$res = demeter_store_page_open_sync($company, '70', $bc->transport());
check($res['status'] === 'fresh' && $bc->calls['count'] + $bc->calls['fetch'] === 0, 'store < 180 s: geen BC-calls');

// 3. Gesimuleerde wijzigingen
$bc->workorders['WO0010']['Status'] = 'Invoiced';                 // statuswijziging (lege kop, oude datum)
$bc->workorders['WO0002']['Status'] = 'Closed';                   // statuswijziging afdeling 70
$bc->workorders['WO0004']['Job_Dimension_1_Value'] = '50';         // afdeling 70 → 50
unset($bc->workorders['WO0008']);                                 // verwijderd
$bc->addPosting('WO0003', 'Usage', 42.5, 60.0);                   // kostenpost zonder statuswijziging
$e = $bc->addPosting('WO0001', 'Usage', 100.0, 150.0);            // ...
$bc->addPosting('WO0001', 'Usage', -100.0, -150.0);               // tegenboeking
$bc->addWo('WO9001', '70', 'Open', '2026-10-08', ['Created_Date_Time' => '2026-10-08T10:00:00Z']); // nieuw
$bc->addWo('WO9002', '70', 'Open', '0001-01-01', ['Created_Date_Time' => '2026-10-08T10:01:00Z']); // nieuw, geen datum
$bc->addWo('WO9003', '70', 'Planned', '2025-03-03');               // 'oud' aangemaakt maar nooit in store (bv. teruggezet)
$bc->addPosting('WO9001', 'Usage', 7.0, 9.0);                     // post op nieuwe werkorder
make_stale($company);
$bc->calls = ['count' => 0, 'fetch' => 0, 'rows' => 0];
$res = demeter_store_page_open_sync($company, '70', $bc->transport());
$store = demeter_store_read($company);
check($res['status'] === 'synced', 'pagina-open > 180 s: sync uitgevoerd');
check($store['workorders']['WO0002']['Status'] === 'Closed', 'statuswijziging Open/... → Closed gevonden via counts + inzoomen');
check($store['workorders']['WO0010']['Status'] === 'Invoiced', 'statuswijziging bij lege kop-afdeling gevonden');
check($store['workorders']['WO0004']['Job_Dimension_1_Value'] === '50', 'afdeling 70 → 50 verplaatst');
check(!isset($store['workorders']['WO0008']), 'verwijderde werkorder verwijderd');
check(demeter_store_finance_for($store, 'WO0003')['cost'] === 142.5, 'nieuwe kostenpost zonder statuswijziging live verwerkt (100 + 42,50)');
check(demeter_store_finance_for($store, 'WO0001')['cost'] === 100.0 && demeter_store_finance_for($store, 'WO0001')['entries'] === 4, 'post + tegenboeking: netto ongewijzigd, 4 posten');
check(isset($store['workorders']['WO9001']) && isset($store['workorders']['WO9002']), 'nieuwe werkorders (ook 0001-01-01) toegevoegd');
check(isset($store['workorders']['WO9003']), 'ontbrekende oude werkorder gevonden via counts');
check(demeter_store_finance_for($store, 'WO9001')['cost'] === 7.0, 'kosten op nieuwe werkorder');
check($bc->calls['rows'] < 200, 'delta haalt minder dan de helft van de rijen op (' . $bc->calls['rows'] . ' rijen, ' . $bc->calls['count'] . ' counts)');

// 4. Idempotent: zelfde posten niet dubbel
make_stale($company);
demeter_store_page_open_sync($company, '70', $bc->transport());
$store = demeter_store_read($company);
check(demeter_store_finance_for($store, 'WO0003')['cost'] === 142.5, 'tweede sync telt posten niet dubbel');

// 5. Reconciliatie: 0 verschillen voor 70 en lege kop
$rec = demeter_store_reconcile($store, $bc->transport(), ['70', '']);
check($rec['total_diffs'] === 0, 'reconciliatie 70 + leeg: 0 verschillen (' . $rec['total_diffs'] . ')');

// 6. Startdatumwijziging zonder statuswijziging: counts zien het niet → hourly pakt het op (open werkorder)
$openNo = null;
foreach ($bc->workorders as $no => $w) {
    if ($w['Status'] === 'Open' && $w['Job_Dimension_1_Value'] === '70' && $w['Start_Date'] !== '0001-01-01') {
        $openNo = $no;
        break;
    }
}
$bc->workorders[$openNo]['Start_Date'] = '0001-01-01';
$bc->workorders[$openNo]['Task_Description'] = 'Gewijzigde omschrijving';
$recBefore = demeter_store_reconcile(demeter_store_read($company), $bc->transport(), ['70']);
check($recBefore['total_diffs'] === 1, 'reconciliatie ziet de startdatum/omschrijving-wijziging (1 verschil)');
$h = demeter_store_hourly_refresh($company, $bc->transport());
$store = demeter_store_read($company);
check($h['status'] === 'ok' && $store['workorders'][$openNo]['Start_Date'] === '0001-01-01' && $store['workorders'][$openNo]['Task_Description'] === 'Gewijzigde omschrijving', 'hourly: startdatum naar 0001-01-01 + omschrijving bijgewerkt');
// open → afgesloten + verwijderd via hourly
$bc->workorders['WO9001']['Status'] = 'Completed';
unset($bc->workorders['WO9002']);
demeter_store_hourly_refresh($company, $bc->transport());
$store = demeter_store_read($company);
check($store['workorders']['WO9001']['Status'] === 'Completed' && !isset($store['workorders']['WO9002']), 'hourly: open → Completed en verwijderd verwerkt');
$rec = demeter_store_reconcile($store, $bc->transport(), null);
check($rec['total_diffs'] === 0, 'reconciliatie hele bedrijf na hourly: 0 verschillen (' . $rec['total_diffs'] . ')');

// 8. Nederlandse captions (Mímir) worden gelijkgetrokken
check(demeter_store_status_canonical('Gefactureerd') === 'Invoiced' && demeter_store_status_is_closed('Gefactureerd') && !demeter_store_status_is_closed('Checked') && !demeter_store_status_is_closed('Signed'), 'NL/EN-statussen: Gefactureerd = Invoiced (afgesloten); Checked/Signed open');

// 9. Nightly: counts kloppen niet → oude store blijft staan
$before = file_get_contents(demeter_store_path($company));
$bc->countTamper = static function (string $filter, int $n): int {
    return strpos($filter, "Status eq 'Checked'") !== false ? $n + 1 : $n;
};
$r = demeter_store_nightly_snapshot($company, $bc->transport());
check($r['status'] === 'verify_failed' && file_get_contents(demeter_store_path($company)) === $before, 'nightly met afwijkende counts: niet ingewisseld, oude store intact');
$bc->countTamper = null;

// 10. Gelijktijdige schrijvers: de ander houdt de lock; wij wachten en krijgen zijn verse versie zonder eigen BC-ronde
make_stale($company);
$dir = $GLOBALS['DEMETER_STORE_BASE_DIR'];
@unlink($dir . '/holding');
$proc = proc_open([PHP_BINARY, __FILE__, '--hold-lock', $company, $dir], [], $pipes);
for ($i = 0; $i < 50 && !is_file($dir . '/holding'); $i++) {
    usleep(50000);
}
$bc->calls = ['count' => 0, 'fetch' => 0, 'rows' => 0];
$res = demeter_store_page_open_sync($company, '70', $bc->transport(), 5.0);
proc_close($proc);
check($res['status'] === 'fresh_after_wait' && ($res['store']['marker'] ?? '') === 'other-writer' && $bc->calls['count'] + $bc->calls['fetch'] === 0, 'gelijktijdig: tweede wacht op de lock en leest de verse versie (0 BC-calls)');
// Busy: korte wachttijd → oude (complete) versie, geen BC
make_stale($company);
@unlink($dir . '/holding');
$proc = proc_open([PHP_BINARY, __FILE__, '--hold-lock', $company, $dir], [], $pipes);
for ($i = 0; $i < 50 && !is_file($dir . '/holding'); $i++) {
    usleep(50000);
}
$res = demeter_store_page_open_sync($company, '70', $bc->transport(), 0.2);
proc_close($proc);
check($res['status'] === 'busy' && is_array($res['store']) && count($res['store']['workorders']) > 0, 'bezet: complete (iets oudere) store terug, geen half bestand');

// 11. Atomisch: geen temp-bestanden achter
check(glob($dir . '/*.tmp.*') === [], 'geen achtergebleven temp-bestanden');

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
exit($failures === 0 ? 0 : 1);
