<?php

/**
 * Nachtelijke cache-verversing (cron om 02:00).
 * Geen UI — alleen stdout/logging (CLI of HTTP via cron).
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

require __DIR__ . '/auth.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    require_once __DIR__ . '/logincheck.php';
    if (!is_trusted_requester()) {
        demeter_nightly_log("Toegang geweigerd: nightly mag alleen via CLI of localhost worden aangeroepen.\n", true);
        demeter_nightly_exit(403);
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
$ttl = $hour * 12;

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
