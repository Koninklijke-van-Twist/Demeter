<?php

/**
 * Nachtelijke cache-verversing (cron om 02:00).
 * Geen UI — alleen stdout/logging (CLI of HTTP via cron).
 * Mímir max_age op nightly-fetches: DEMETER_NIGHTLY_MAX_AGE (14400).
 */

if (!defined('DEMETER_ODATA_MAX_EXECUTION_SECONDS')) {
    define('DEMETER_ODATA_MAX_EXECUTION_SECONDS', 0);
}

@ini_set('display_errors', '1');
@ini_set('max_execution_time', '0');
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

function demeter_nightly_log(string $message, bool $isError = false): void
{
    if (PHP_SAPI === 'cli') {
        $stream = $isError
            ? (defined('STDERR') ? STDERR : fopen('php://stderr', 'w'))
            : (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
        if (is_resource($stream)) {
            fwrite($stream, $message);
        }

        return;
    }

    if ($isError) {
        error_log(rtrim($message));
    }

    echo $message;
    if (function_exists('flush')) {
        @flush();
    }
}

function demeter_nightly_exit(int $code): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code === 0 ? 200 : 500);
    }

    exit($code);
}

define('DEMETER_SKIP_LOGINCHECK_AUTO', true);

// auth.php levert $mimirApi én de BC-credentials ($baseUrl, $auth, $auth_list, $environment).
// Die BC-gegevens blijven nodig: nightly/CLI valt daarop terug als Mímir uitvalt.
require __DIR__ . '/auth.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    require_once __DIR__ . '/logincheck.php';
    if (!is_trusted_requester()) {
        demeter_require_web_login_unless_trusted();
    }
}

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/project_finance.php';
require_once __DIR__ . '/workorder_rows.php';
require_once __DIR__ . '/bc_fetch/nightly_runner.php';

$second = 1;
$minute = $second * 60;
$hour = $minute * 60;
// Nightly Mímir max_age / legacy filecache TTL: 4h (UI keeps 12h in index.php).
$ttl = defined('DEMETER_NIGHTLY_MAX_AGE') ? DEMETER_NIGHTLY_MAX_AGE : 14400;
// Werkorders (weken, status, memo's): max 180 s oud, ook 's nachts (Mímir max_age / eigen cache-TTL).
$workorderTtl = defined('DEMETER_WORKORDER_MAX_AGE_SECONDS') ? DEMETER_WORKORDER_MAX_AGE_SECONDS : 180;

$lockPath = __DIR__ . '/cache/reference/nightly.lock';
if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0775, true) && !is_dir(dirname($lockPath))) {
    demeter_nightly_log("Kan lock-directory niet aanmaken.\n", true);
    demeter_nightly_exit(1);
}

$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false) {
    demeter_nightly_log("Kan lockbestand niet openen.\n", true);
    demeter_nightly_exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    demeter_nightly_log("Nightly draait al — overslaan.\n");
    demeter_nightly_exit(0);
}

$stats = demeter_nightly_stats_defaults();
$stats['last_run_started_at'] = gmdate('c');
$stats['companies'] = [];
demeter_nightly_stats_save($stats);

demeter_nightly_log('[' . gmdate('Y-m-d H:i:s') . "] Nightly gestart\n");

$purgedStaleCaches = demeter_purge_stale_cost_center_caches();
if ($purgedStaleCaches !== []) {
    $stats['purged_stale_caches'] = $purgedStaleCaches;
    demeter_nightly_stats_save($stats);
    demeter_nightly_log(sprintf("Verouderde caches verwijderd: %d kostenplaats(en)\n", count($purgedStaleCaches)));
    foreach ($purgedStaleCaches as $purgedEntry) {
        $purgedCompany = trim((string) ($purgedEntry['company'] ?? ''));
        $purgedCostCenter = trim((string) ($purgedEntry['cost_center'] ?? ''));
        $purgedLastViewed = trim((string) ($purgedEntry['last_viewed_at'] ?? ''));
        demeter_nightly_log(sprintf(
            "  %s / %s (laatst bekeken: %s)\n",
            $purgedCompany,
            $purgedCostCenter,
            $purgedLastViewed !== '' ? $purgedLastViewed : 'onbekend'
        ));
    }
}

try {
    $discovery = demeter_discover_and_cache_companies($ttl);
    $companies = is_array($discovery['companies'] ?? null) ? $discovery['companies'] : [];

    foreach ($companies as $company) {
        if (!is_string($company) || trim($company) === '') {
            continue;
        }

        $company = trim($company);
        demeter_nightly_log("Bedrijf: {$company}\n");

        try {
            auth_set_current_company_context($company, 300);
            $auth = auth_get_auth_for_company($company, 300);
            $costCenterOptions = demeter_fetch_and_cache_cost_center_options($company, $auth, $ttl);
        } catch (Throwable $companyError) {
            $stats['companies'][$company] = [
                'error' => $companyError->getMessage(),
                'cost_centers' => [],
            ];
            demeter_nightly_stats_save($stats);
            demeter_nightly_log("  Fout bij bedrijf {$company}: " . $companyError->getMessage() . "\n", true);
            continue;
        }

        $stats['companies'][$company] = [
            'cost_centers' => [],
        ];

        foreach ($costCenterOptions as $option) {
            if (!is_array($option)) {
                continue;
            }

            $costCenter = trim((string) ($option['code'] ?? ''));
            if ($costCenter === '') {
                continue;
            }

            if (!demeter_workorder_cost_center_cache_is_populated($company, $costCenter)) {
                $stats['companies'][$company]['cost_centers'][$costCenter] = [
                    'status' => 'skipped_empty_cache',
                    'duration_seconds' => 0.0,
                    'finished_at' => gmdate('c'),
                    'weeks_processed' => 0,
                    'memos_refreshed' => 0,
                ];
                demeter_nightly_stats_save($stats);
                demeter_nightly_log("  Kostenplaats {$costCenter}: overgeslagen (lege cache)\n");
                continue;
            }

            // Fase 0: niet over een lopende browser-herbouw/catch-up heen schrijven. Max 10 min wachten,
            // daarna deze kostenplaats beleefd overslaan (de volgende nacht/catch-up pakt hem op).
            if (!demeter_nightly_wait_until_cost_center_free($company, $costCenter, 600)) {
                $stats['companies'][$company]['cost_centers'][$costCenter] = [
                    'status' => 'skipped_busy',
                    'duration_seconds' => 0.0,
                    'finished_at' => gmdate('c'),
                    'weeks_processed' => 0,
                    'memos_refreshed' => 0,
                ];
                demeter_nightly_stats_save($stats);
                demeter_nightly_log("  Kostenplaats {$costCenter}: overgeslagen (browser-load/herbouw bezig, 10 min gewacht)\n");
                continue;
            }

            $startedAt = microtime(true);
            $entry = [
                'status' => 'ok',
                'duration_seconds' => 0.0,
                'finished_at' => null,
                'weeks_processed' => 0,
                'memos_refreshed' => 0,
                'error' => null,
            ];

            try {
                $connectionAttempt = 0;
                while (true) {
                    $connectionAttempt++;
                    try {
                        $refreshResult = demeter_refresh_cost_center_weeks($company, $costCenter, $auth, $workorderTtl, [
                            'force_full' => false,
                            'load_session_id' => 'nightly-' . gmdate('Ymd'),
                        ]);
                        $entry['weeks_processed'] = (int) ($refreshResult['weeks_processed'] ?? 0);
                        $statusRefresh = demeter_nightly_refresh_open_workorder_statuses($company, $costCenter, $auth, $workorderTtl);
                        $entry['open_status_refresh'] = $statusRefresh;
                        demeter_nightly_log(sprintf(
                            "  Kostenplaats %s: status open werkorders ververst (%d open in cache, %d gewijzigd, %d niet meer in BC)\n",
                            $costCenter,
                            $statusRefresh['open_in_cache'],
                            $statusRefresh['updated'],
                            $statusRefresh['not_found']
                        ));
                        $entry['memos_refreshed'] = demeter_refresh_all_memos_for_cost_center($company, $costCenter, $auth, $workorderTtl);
                        demeter_workorder_state_cache_touch_updated_at($company, $costCenter);
                        break;
                    } catch (Throwable $retryError) {
                        if (!function_exists('odata_exception_is_connection_retryable')
                            || !odata_exception_is_connection_retryable($retryError)
                        ) {
                            throw $retryError;
                        }

                        demeter_nightly_log(sprintf(
                            "  Kostenplaats %s: verbindingfout (#%d), opnieuw over 10s: %s\n",
                            $costCenter,
                            $connectionAttempt,
                            $retryError->getMessage()
                        ), true);
                        sleep(10);
                    }
                }
            } catch (Throwable $costCenterError) {
                $entry['status'] = 'error';
                $entry['error'] = $costCenterError->getMessage();
                demeter_nightly_log("  Fout bij kostenplaats {$costCenter}: " . $costCenterError->getMessage() . "\n", true);
            }

            $entry['duration_seconds'] = round(microtime(true) - $startedAt, 2);
            $entry['finished_at'] = gmdate('c');
            $stats['companies'][$company]['cost_centers'][$costCenter] = $entry;
            demeter_nightly_stats_save($stats);

            demeter_nightly_log(sprintf(
                "  Kostenplaats %s: %s (%.1fs, %d weken, %d memo's)\n",
                $costCenter,
                $entry['status'],
                $entry['duration_seconds'],
                $entry['weeks_processed'],
                $entry['memos_refreshed']
            ));
        }
    }

    // Fase 1 (SHADOW): werkorder-store per bedrijf. Volledige snapshot, alleen ingewisseld als de BC-counts
    // (afdeling × status) kloppen, plus reconciliatie van de vorige store tegen deze snapshot. Het scherm
    // leest hier nog niet uit. Uitzetten: define('DEMETER_STORE_NIGHTLY', false) in auth.php.
    if (!defined('DEMETER_STORE_NIGHTLY') || DEMETER_STORE_NIGHTLY) {
        require_once __DIR__ . '/bc_fetch/store_transport.php';
        require_once __DIR__ . '/bc_fetch/store_reconcile.php';
        foreach ($companies as $company) {
            if (!is_string($company) || trim($company) === '') {
                continue;
            }
            $company = trim($company);
            $storeStartedAt = microtime(true);
            demeter_nightly_log("Werkorder-store (shadow): {$company}\n");
            try {
                $storeResult = demeter_store_nightly_snapshot($company, demeter_store_live_transport($company), static function (string $m): void {
                    demeter_nightly_log($m);
                });
                $stats['companies'][$company]['store'] = [
                    'status' => $storeResult['status'],
                    'workorders' => $storeResult['workorders'] ?? null,
                    'count_diffs' => array_slice($storeResult['verification']['diffs'] ?? [], 0, 20),
                    'duration_seconds' => round(microtime(true) - $storeStartedAt, 1),
                ];
            } catch (Throwable $storeError) {
                $stats['companies'][$company]['store'] = ['status' => 'error', 'error' => substr($storeError->getMessage(), 0, 300)];
                demeter_nightly_log("  Werkorder-store {$company}: fout: " . substr($storeError->getMessage(), 0, 300) . "\n", true);
            }
            demeter_nightly_stats_save($stats);
        }
    }

    $stats['last_run_finished_at'] = gmdate('c');
    demeter_nightly_stats_save($stats);
    demeter_nightly_log('[' . gmdate('Y-m-d H:i:s') . "] Nightly voltooid\n");
} catch (Throwable $fatalError) {
    $stats['last_run_finished_at'] = gmdate('c');
    $stats['fatal_error'] = $fatalError->getMessage();
    demeter_nightly_stats_save($stats);
    demeter_nightly_log('Nightly mislukt: ' . $fatalError->getMessage() . "\n", true);
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    demeter_nightly_exit(1);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
demeter_nightly_exit(0);
