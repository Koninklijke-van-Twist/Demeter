<?php

/**
 * Nachtelijke cache-verversing (cron om 02:00).
 * Geen UI — alleen stdout/logging.
 */

if (!defined('DEMETER_ODATA_MAX_EXECUTION_SECONDS')) {
    define('DEMETER_ODATA_MAX_EXECUTION_SECONDS', 0);
}

@ini_set('display_errors', '1');
@ini_set('max_execution_time', '0');
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

require __DIR__ . '/auth.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/project_finance.php';
require_once __DIR__ . '/workorder_rows.php';
require_once __DIR__ . '/bc_fetch/nightly_runner.php';

$second = 1;
$minute = $second * 60;
$hour = $minute * 60;
$ttl = $hour * 12;

$lockPath = __DIR__ . '/cache/reference/nightly.lock';
if (!is_dir(dirname($lockPath)) && !mkdir(dirname($lockPath), 0775, true) && !is_dir(dirname($lockPath))) {
    fwrite(STDERR, "Kan lock-directory niet aanmaken.\n");
    exit(1);
}

$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false) {
    fwrite(STDERR, "Kan lockbestand niet openen.\n");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Nightly draait al — overslaan.\n");
    exit(0);
}

$stats = demeter_nightly_stats_defaults();
$stats['last_run_started_at'] = gmdate('c');
$stats['companies'] = [];
demeter_nightly_stats_save($stats);

fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "] Nightly gestart\n");

try {
    $discovery = demeter_discover_and_cache_companies($ttl);
    $companies = is_array($discovery['companies'] ?? null) ? $discovery['companies'] : [];

    foreach ($companies as $company) {
        if (!is_string($company) || trim($company) === '') {
            continue;
        }

        $company = trim($company);
        fwrite(STDOUT, "Bedrijf: {$company}\n");

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
            fwrite(STDERR, "  Fout bij bedrijf {$company}: " . $companyError->getMessage() . "\n");
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
                fwrite(STDOUT, "  Kostenplaats {$costCenter}: overgeslagen (lege cache)\n");
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
                $refreshResult = demeter_refresh_cost_center_weeks($company, $costCenter, $auth, $ttl, [
                    'force_full' => false,
                    'load_session_id' => 'nightly-' . gmdate('Ymd'),
                ]);
                $entry['weeks_processed'] = (int) ($refreshResult['weeks_processed'] ?? 0);
                $entry['memos_refreshed'] = demeter_refresh_all_memos_for_cost_center($company, $costCenter, $auth, $ttl);
            } catch (Throwable $costCenterError) {
                $entry['status'] = 'error';
                $entry['error'] = $costCenterError->getMessage();
                fwrite(STDERR, "  Fout bij kostenplaats {$costCenter}: " . $costCenterError->getMessage() . "\n");
            }

            $entry['duration_seconds'] = round(microtime(true) - $startedAt, 2);
            $entry['finished_at'] = gmdate('c');
            $stats['companies'][$company]['cost_centers'][$costCenter] = $entry;
            demeter_nightly_stats_save($stats);

            fwrite(STDOUT, sprintf(
                "  Kostenplaats %s: %s (%.1fs, %d weken, %d memo's)\n",
                $costCenter,
                $entry['status'],
                $entry['duration_seconds'],
                $entry['weeks_processed'],
                $entry['memos_refreshed']
            ));
        }
    }

    $stats['last_run_finished_at'] = gmdate('c');
    demeter_nightly_stats_save($stats);
    fwrite(STDOUT, '[' . gmdate('Y-m-d H:i:s') . "] Nightly voltooid\n");
} catch (Throwable $fatalError) {
    $stats['last_run_finished_at'] = gmdate('c');
    $stats['fatal_error'] = $fatalError->getMessage();
    demeter_nightly_stats_save($stats);
    fwrite(STDERR, 'Nightly mislukt: ' . $fatalError->getMessage() . "\n");
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);
