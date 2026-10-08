<?php
/**
 * Live BC-transport voor de werkorder-store: altijd DIRECT naar BC (geen Mímir: $count en delta-filters
 * wisselen per aanroep, Mímir zou alleen wachtrij toevoegen), met de bestaande retry-logica van odata_get_json.
 * Vereist dat auth.php/odata.php geladen zijn.
 */

require_once __DIR__ . '/store.php';

function demeter_store_live_transport(string $company): array
{
    odata_ensure_bc_config_loaded();
    $env = odata_bc_environment_for_company($company);
    $base = odata_bc_base_url_from_globals();
    $auth = odata_bc_auth_for_company_env($env, [], $company);
    if ($env === null || $base === null || $auth === null) {
        throw new RuntimeException('Geen directe BC-configuratie voor bedrijf ' . $company . '.');
    }
    $root = rtrim($base, '/') . '/' . rawurlencode($env) . "/ODataV4/Company('" . rawurlencode($company) . "')/";
    $build = static function (string $entity, array $params) use ($root): string {
        $params = array_filter($params, static function ($v): bool {
            return $v !== null && $v !== '';
        });

        return $root . $entity . ($params !== [] ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
    };
    $calls = ['count' => 0, 'fetch' => 0, 'rows' => 0, 'ms' => 0];
    $GLOBALS['DEMETER_STORE_TRANSPORT_CALLS'] = &$calls;

    $count = static function (string $entity, string $filter) use ($build, $auth, &$calls): int {
        $t = microtime(true);
        $resp = odata_get_json($build($entity, ['$filter' => $filter, '$top' => '0', '$count' => 'true']), $auth);
        $calls['count']++;
        $calls['ms'] += (int) round((microtime(true) - $t) * 1000);
        if (getenv('DEMETER_STORE_DEBUG') === '1') {
            fwrite(STDERR, sprintf("  [count %.2fs = %d] %s\n", microtime(true) - $t, (int) ($resp['@odata.count'] ?? -1), substr($filter, 0, 160)));
        }
        if (!isset($resp['@odata.count'])) {
            throw new RuntimeException('BC gaf geen @odata.count terug.');
        }

        return (int) $resp['@odata.count'];
    };

    return [
        'count' => $count,
        // Counts 3 tegelijk (curl_multi; BC-concurrency per environment ≈ 5, 409-risico laag houden).
        // Mislukte of onleesbare antwoorden gaan sequentieel opnieuw via $count (met retries).
        'count_many' => static function (string $entity, array $filters) use ($count, $build, $auth, &$calls): array {
            $out = [];
            $queue = $filters;
            $t = microtime(true);
            while ($queue !== []) {
                $batch = array_slice($queue, 0, 3, true);
                $queue = array_slice($queue, 3, null, true);
                $mh = curl_multi_init();
                $handles = [];
                foreach ($batch as $i => $filter) {
                    $ch = curl_init($build($entity, ['$filter' => $filter, '$top' => '0', '$count' => 'true']));
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_TIMEOUT => 60,
                        CURLOPT_NOSIGNAL => true,
                        CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    ]);
                    if (in_array($auth['mode'] ?? '', ['basic', 'ntlm'], true)) {
                        curl_setopt($ch, CURLOPT_HTTPAUTH, ($auth['mode'] === 'ntlm') ? CURLAUTH_NTLM : CURLAUTH_BASIC);
                        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ':' . $auth['pass']);
                    }
                    curl_multi_add_handle($mh, $ch);
                    $handles[$i] = $ch;
                }
                do {
                    $status = curl_multi_exec($mh, $running);
                    if ($running) {
                        curl_multi_select($mh, 1.0);
                    }
                } while ($running && $status === CURLM_OK);
                foreach ($handles as $i => $ch) {
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $json = json_decode((string) curl_multi_getcontent($ch), true);
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    if ($code === 200 && is_array($json) && isset($json['@odata.count'])) {
                        $out[$i] = (int) $json['@odata.count'];
                        $calls['count']++;
                    } else {
                        $out[$i] = $count($entity, $batch[$i]);
                    }
                }
                curl_multi_close($mh);
            }
            $calls['ms'] += (int) round((microtime(true) - $t) * 1000);
            ksort($out);

            return $out;
        },
        'fetch' => static function (string $entity, array $query) use ($build, $auth, &$calls): array {
            $t = microtime(true);
            // Bewust GEEN $top: in OData begrenst $top het totaal (geen nextLink). BC pagineert zelf.
            $next = $build($entity, $query);
            $all = [];
            while ($next) {
                $resp = odata_get_json($next, $auth);
                if (!isset($resp['value']) || !is_array($resp['value'])) {
                    throw new RuntimeException("OData-antwoord zonder 'value'.");
                }
                foreach ($resp['value'] as $row) {
                    unset($row['@odata.etag']);
                    $all[] = $row;
                }
                $next = $resp['@odata.nextLink'] ?? null;
            }
            $calls['fetch']++;
            $calls['rows'] += count($all);
            if (getenv('DEMETER_STORE_DEBUG') === '1') {
                fwrite(STDERR, sprintf("  [fetch %s %.2fs %d rijen] %s\n", $entity, microtime(true) - $t, count($all), substr((string) ($query['$filter'] ?? ''), 0, 160)));
            }
            $calls['ms'] += (int) round((microtime(true) - $t) * 1000);

            return $all;
        },
    ];
}
