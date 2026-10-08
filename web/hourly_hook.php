<?php
/**
 * Demeter-hook voor de bestaande hourly.php op de server (die ook andere rapporten bedient).
 * Fase 1 (SHADOW): ververst per bedrijf met een werkorder-store alle niet-afgesloten werkorders
 * + nieuwe ProjectPosten (Entry_No). Het scherm leest hier nog niet uit.
 *
 * In hourly.php:   require_once '/pad/naar/demeter/web/hourly_hook.php';  demeter_hourly_run();
 * Of los (CLI):    php web/hourly_hook.php
 *
 * Doet niets voor bedrijven zonder store (die bouwt nightly). Fouten worden gelogd, nooit gegooid,
 * zodat de andere rapporten in hourly.php gewoon doorlopen.
 */

if (!function_exists('demeter_hourly_run')) {
    function demeter_hourly_run(?callable $log = null): array
    {
        $log = $log ?? static function (string $m): void {
            if (PHP_SAPI === 'cli') {
                fwrite(STDOUT, $m);
            } else {
                error_log(rtrim($m));
            }
        };
        $results = [];
        try {
            $webDir = __DIR__;
            if (!defined('DEMETER_SKIP_LOGINCHECK_AUTO')) {
                define('DEMETER_SKIP_LOGINCHECK_AUTO', true);
            }
            if (!defined('DEMETER_ODATA_MAX_EXECUTION_SECONDS')) {
                define('DEMETER_ODATA_MAX_EXECUTION_SECONDS', 0);
            }
            @ini_set('memory_limit', '1024M');
            if (!function_exists('odata_get_json')) {
                ob_start();
                require $webDir . '/auth.php';
                ob_end_clean();
                // auth.php definieert variabelen in deze functiescope; odata.php leest ze als globals.
                foreach (get_defined_vars() as $name => $value) {
                    if (!in_array($name, ['log', 'results', 'webDir'], true) && !array_key_exists($name, $GLOBALS)) {
                        $GLOBALS[$name] = $value;
                    }
                }
                require_once $webDir . '/odata.php';
            }
            require_once $webDir . '/bc_fetch/store_transport.php';
            foreach (glob(demeter_store_base_dir() . '/*.json') ?: [] as $path) {
                $raw = json_decode((string) @file_get_contents($path), true);
                $company = is_array($raw) ? (string) ($raw['company'] ?? '') : '';
                if ($company === '') {
                    continue;
                }
                $started = microtime(true);
                try {
                    $r = demeter_store_hourly_refresh($company, demeter_store_live_transport($company));
                    $results[$company] = $r;
                    $log(sprintf("[Demeter hourly] %s: %s (%d rijen, %d verwijderd, %d posten, %.1fs)\n", $company, $r['status'],
                        (int) ($r['stats']['rows_fetched'] ?? 0), (int) ($r['stats']['deleted'] ?? 0), (int) ($r['stats']['postings'] ?? 0), microtime(true) - $started));
                } catch (Throwable $e) {
                    $results[$company] = ['status' => 'error'];
                    $log('[Demeter hourly] ' . $company . ': fout: ' . substr($e->getMessage(), 0, 300) . "\n");
                }
            }
        } catch (Throwable $e) {
            $log('[Demeter hourly] fout: ' . substr($e->getMessage(), 0, 300) . "\n");
        }

        return $results;
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    demeter_hourly_run();
}
