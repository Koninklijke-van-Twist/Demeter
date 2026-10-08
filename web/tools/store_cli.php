<?php
/**
 * CLI voor de werkorder-store (fase 1, shadow). Alleen lezen uit BC.
 *
 *   php web/tools/store_cli.php snapshot  "<bedrijf>"
 *   php web/tools/store_cli.php sync      "<bedrijf>" <afdeling>     (pagina-open delta, negeert de 180 s-check met --force)
 *   php web/tools/store_cli.php hourly    "<bedrijf>"
 *   php web/tools/store_cli.php reconcile "<bedrijf>" [afdeling,...]  (leeg = alle afdelingen)
 *   php web/tools/store_cli.php status    "<bedrijf>"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('DEMETER_SKIP_LOGINCHECK_AUTO', true);
if (!defined('DEMETER_ODATA_MAX_EXECUTION_SECONDS')) {
    define('DEMETER_ODATA_MAX_EXECUTION_SECONDS', 0);
}
@ini_set('memory_limit', '1024M');
$webDir = dirname(__DIR__);
ob_start();
require $webDir . '/auth.php';
require_once $webDir . '/odata.php';
ob_end_clean();
require_once $webDir . '/bc_fetch/store_transport.php';
require_once $webDir . '/bc_fetch/store_reconcile.php';

$cmd = $argv[1] ?? '';
$company = $argv[2] ?? '';
$force = in_array('--force', $argv, true);
if ($cmd === '' || $company === '') {
    fwrite(STDERR, "Gebruik: php store_cli.php snapshot|sync|hourly|reconcile|status \"<bedrijf>\" [afdeling]\n");
    exit(2);
}
$log = static function (string $m): void {
    fwrite(STDOUT, $m);
};
$t0 = microtime(true);
try {
    $transport = $cmd === 'status' ? null : demeter_store_live_transport($company);
    switch ($cmd) {
        case 'snapshot':
            $r = demeter_store_nightly_snapshot($company, $transport, $log);
            $log(json_encode(['status' => $r['status'], 'workorders' => $r['workorders'] ?? null, 'diffs' => $r['verification']['diffs'] ?? []]) . "\n");
            break;
        case 'sync':
            $afdeling = $argv[3] ?? '';
            if ($force) {
                demeter_store_with_lock($company, static function () use ($company) {
                    $s = demeter_store_read($company);
                    if ($s !== null) {
                        $s['synced_at'] = 0;
                        demeter_store_write($company, $s);
                    }
                }, 30.0);
            }
            $r = demeter_store_page_open_sync($company, $afdeling, $transport);
            $log(json_encode(['status' => $r['status'], 'stats' => $r['stats']], JSON_UNESCAPED_UNICODE) . "\n");
            break;
        case 'hourly':
            $log(json_encode(demeter_store_hourly_refresh($company, $transport), JSON_UNESCAPED_UNICODE) . "\n");
            break;
        case 'reconcile':
            $store = demeter_store_read($company);
            if ($store === null) {
                throw new RuntimeException('Nog geen store voor dit bedrijf (eerst snapshot).');
            }
            $afd = isset($argv[3]) && $argv[3] !== '--force' ? explode(',', $argv[3]) : null;
            $r = demeter_store_reconcile($store, $transport, $afd);
            demeter_store_record_reconciliation($company, $r);
            $log(demeter_store_reconcile_summary($r));
            foreach ($r['per_afdeling'] as $a => $p) {
                foreach ($p['examples'] as $ex) {
                    $log('    ' . json_encode($ex, JSON_UNESCAPED_UNICODE) . "\n");
                }
            }
            break;
        case 'status':
            $s = demeter_store_read($company);
            $log(json_encode($s === null ? null : array_diff_key($s, ['workorders' => 1, 'finance' => 1, 'finance_jobtask' => 1]) + ['workorder_count' => count($s['workorders'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            break;
        default:
            throw new RuntimeException('Onbekend commando.');
    }
    if (isset($GLOBALS['DEMETER_STORE_TRANSPORT_CALLS'])) {
        $log('BC-calls: ' . json_encode($GLOBALS['DEMETER_STORE_TRANSPORT_CALLS']) . sprintf(", totaal %.1fs\n", microtime(true) - $t0));
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FOUT: ' . substr($e->getMessage(), 0, 300) . "\n");
    exit(1);
}
