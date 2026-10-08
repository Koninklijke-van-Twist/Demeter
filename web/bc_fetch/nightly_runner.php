<?php

/**
 * Gedeelde refresh-logica voor nightly.php en handmatige verversing.
 */

require_once __DIR__ . '/reference_cache.php';
require_once __DIR__ . '/cost_centers.php';
require_once __DIR__ . '/month_loader.php';
require_once __DIR__ . '/active_load.php';
require_once __DIR__ . '/../workorder_rows.php';

/**
 * Haalt bedrijven op uit BC en schrijft naar referentie-cache.
 *
 * @return array{companies: list<string>, map: array<string, string>}
 */
function demeter_discover_and_cache_companies(int $ttl): array
{
    $discovery = auth_discover_companies_across_active_environments($ttl);
    $companies = is_array($discovery['companies'] ?? null) ? $discovery['companies'] : [];
    $map = is_array($discovery['map'] ?? null) ? $discovery['map'] : [];
    demeter_companies_cache_save($companies, $map);

    return [
        'companies' => $companies,
        'map' => $map,
    ];
}

/**
 * @return list<array{code: string, name: string, label: string}>
 */
function demeter_fetch_and_cache_cost_center_options(string $company, array $auth, int $ttl): array
{
    $dimensionCode = bc_fetch_global_dimension_1_code($company, $auth, $ttl);
    $options = bc_fetch_department_cost_center_options($company, $auth, $ttl, $dimensionCode);
    demeter_cost_center_options_cache_save($company, $options, $dimensionCode);

    return $options;
}

/**
 * Ververs alle ISO-weken voor een kostenplaats (zoals de browser-load_month keten).
 * Per week: Werkorders op Start_Date + ProjectPosten op boekdatum, daarna koppelen.
 *
 * @param array{force_full?: bool, load_session_id?: string, progress_token?: string|null} $options
 * @return array{weeks_processed: int, last_month_scan: array}
 */
function demeter_refresh_cost_center_weeks(
    string $company,
    string $costCenter,
    array $auth,
    int $ttl,
    array $options = []
): array {
    $forceFull = !empty($options['force_full']);
    // Cacheversie gewijzigd (bv. andere kostenplaats-logica): alle weken volledig opnieuw lezen.
    // Incrementeel kan niet: de werkorders die het oude filter uitsloot staan niet in de cache.
    if (!$forceFull && demeter_workorder_state_cache_is_stale_version($company, $costCenter)) {
        $forceFull = true;
    }
    $loadSessionId = trim((string) ($options['load_session_id'] ?? 'refresh'));
    $progressToken = array_key_exists('progress_token', $options) ? $options['progress_token'] : null;
    $currentWeek = demeter_current_iso_year_week();
    $yearWeek = $currentWeek;
    $monthScan = demeter_workorder_month_scan_defaults();
    $weeksProcessed = 0;

    if ($forceFull) {
        // Niet-destructief: de vorige rijen blijven zichtbaar tot de herbouw compleet is.
        demeter_workorder_state_cache_begin_rebuild($company, $costCenter);
    } else {
        $cachedState = demeter_workorder_state_cache_load($company, $costCenter);
        if (is_array($cachedState) && is_array($cachedState['month_scan'] ?? null)) {
            $monthScan = $cachedState['month_scan'];
        }
    }

    while (is_string($yearWeek) && $yearWeek !== '' && demeter_month_scan_should_continue($monthScan, demeter_previous_iso_year_week($yearWeek))) {
        $chunk = bc_fetch_load_workorder_week_chunk($company, $yearWeek, $auth, $ttl, $progressToken, [
            'cost_center' => $costCenter,
            'force_full' => $forceFull,
            'skip_if_cached' => !$forceFull,
            'partial_to_today' => $yearWeek === $currentWeek,
            'load_session_id' => $loadSessionId,
        ]);

        if (is_array($chunk['month_scan'] ?? null)) {
            $monthScan = $chunk['month_scan'];
        }

        $weeksProcessed++;

        if (empty($chunk['should_continue'])) {
            break;
        }

        $nextWeek = is_string($chunk['next_week'] ?? null)
            ? $chunk['next_week']
            : demeter_previous_iso_year_week($yearWeek);
        if (!is_string($nextWeek) || $nextWeek === '') {
            break;
        }

        $yearWeek = $nextWeek;
    }

    return [
        'weeks_processed' => $weeksProcessed,
        'last_month_scan' => $monthScan,
    ];
}

/**
 * Haalt memo's op voor open/niet-afgesloten rijen in de display-cache en slaat ze op.
 * Afgesloten/geannuleerde rijen die al memo's hebben worden overgeslagen (minder BC-calls).
 */
function demeter_refresh_all_memos_for_cost_center(string $company, string $costCenter, array $auth, int $ttl): int
{
    $displayRowsByKey = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
    if ($displayRowsByKey === []) {
        return 0;
    }

    $rowRefs = [];
    foreach ($displayRowsByKey as $rowKey => $row) {
        if (!is_string($rowKey) || $rowKey === '' || !is_array($row)) {
            continue;
        }

        $status = trim((string) ($row['Status'] ?? ''));
        $memosLoaded = !empty($row['Memos_Loaded']);
        if (demeter_workorder_status_is_closed($status) && $memosLoaded) {
            continue;
        }

        $rowRefs[] = [
            'row_key' => $rowKey,
            'no' => (string) ($row['No'] ?? ''),
            'job_no' => (string) ($row['Job_No'] ?? ''),
            'job_task_no' => (string) ($row['Job_Task_No'] ?? ''),
            'start_date' => (string) ($row['Start_Date'] ?? ''),
        ];
    }

    if ($rowRefs === []) {
        return 0;
    }

    $memosByRowKey = demeter_fetch_workorder_memos_for_row_refs($company, $rowRefs, $auth, $ttl);
    demeter_persist_workorder_memos_to_display_cache($company, $costCenter, $memosByRowKey);

    return count($memosByRowKey);
}

/**
 * Fase 0: wacht beleefd tot een lopende browser-load/herbouw (active_load of cache-lock) klaar is.
 * Geeft true als de kostenplaats vrij is, false als hij na $maxWaitSeconds nog bezet is (nightly slaat over).
 */
function demeter_nightly_wait_until_cost_center_free(string $company, string $costCenter, int $maxWaitSeconds = 600, int $pollSeconds = 15, ?callable $sleep = null): bool
{
    $sleep = $sleep ?? static function (int $s): void {
        sleep($s);
    };
    $waited = 0;
    while (true) {
        $active = function_exists('demeter_active_load_get') ? demeter_active_load_get($company, $costCenter) : null;
        $browserBusy = is_array($active) && function_exists('demeter_active_load_is_fresh_running') && demeter_active_load_is_fresh_running($active);
        $lockBusy = demeter_workorder_state_cache_lock_is_held_elsewhere($company, $costCenter);
        if (!$browserBusy && !$lockBusy) {
            return true;
        }
        if ($waited >= $maxWaitSeconds) {
            return false;
        }
        $sleep($pollSeconds);
        $waited += $pollSeconds;
    }
}

/**
 * Fase 0: status van ALLE open werkorders in de cache verversen (ook in oude weken, die het
 * incrementele pad overslaat). Eén query op de afdeling (+ lege kop) voor alle niet-afgesloten
 * werkorders; open cacherijen die daar niet in zitten (inmiddels afgesloten, verplaatst of verwijderd)
 * worden op nummer nagekeken. Schrijven onder de cache-lock (re-read, toepassen, opslaan).
 *
 * @return array{open_in_cache: int, updated: int, not_found: int}
 */
function demeter_nightly_refresh_open_workorder_statuses(string $company, string $costCenter, array $auth, int $ttl): array
{
    $closedFilter = implode(' and ', array_map(static function (string $status): string {
        return "Status ne '" . $status . "'";
    }, ['Closed', 'Completed', 'Cancelled', 'Invoiced']));
    $ccFilter = bc_fetch_workorder_cost_center_odata_filter($costCenter);
    $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
        '$select' => 'No,Job_No,Job_Task_No,Status,KVT_Document_Status',
        '$filter' => $closedFilter . ($ccFilter !== '' ? ' and ' . $ccFilter : ''),
    ]);
    $statusByPair = [];
    foreach (odata_get_all($url, $auth, $ttl) as $row) {
        if (is_array($row)) {
            $statusByPair[demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''))] = $row;
        }
    }

    // Open rijen in de display-cache die BC niet meer als open teruggeeft: op nummer nakijken.
    $displayRows = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
    $openInCache = 0;
    $recheckNos = [];
    foreach ($displayRows as $row) {
        if (!is_array($row) || demeter_status_is_closed((string) ($row['Status'] ?? ''))) {
            continue;
        }
        $no = trim((string) ($row['Bc_No'] ?? ($row['No'] ?? '')));
        $pairKey = demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''));
        if ($no === '' || $pairKey === demeter_workorder_pair_key('', '')) {
            continue;
        }
        $openInCache++;
        if (!isset($statusByPair[$pairKey])) {
            $recheckNos[$no] = $pairKey;
        }
    }
    $notFound = 0;
    foreach (array_chunk(array_keys($recheckNos), 20, false) as $chunk) {
        $filter = implode(' or ', array_map(static function ($no): string {
            return "No eq '" . str_replace("'", "''", (string) $no) . "'";
        }, $chunk));
        $chunkUrl = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
            '$select' => 'No,Job_No,Job_Task_No,Status,KVT_Document_Status',
            '$filter' => $filter,
        ]);
        $found = [];
        foreach (odata_get_all($chunkUrl, $auth, $ttl) as $row) {
            if (is_array($row)) {
                $statusByPair[demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''))] = $row;
                $found[(string) ($row['No'] ?? '')] = true;
            }
        }
        foreach ($chunk as $no) {
            if (!isset($found[(string) $no])) {
                $notFound++;
            }
        }
    }

    $updated = demeter_workorder_state_cache_with_lock($company, $costCenter, static function () use ($company, $costCenter, $statusByPair): int {
        $changed = 0;
        $display = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
        foreach ($display as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $snap = $statusByPair[demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''))] ?? null;
            if (!is_array($snap)) {
                continue;
            }
            $status = trim((string) ($snap['Status'] ?? ''));
            $doc = trim((string) ($snap['KVT_Document_Status'] ?? ''));
            if (($status !== '' && $status !== (string) ($row['Status'] ?? '')) || ($doc !== '' && $doc !== (string) ($row['KVT_Document_Status'] ?? ''))) {
                if ($status !== '') {
                    $display[$key]['Status'] = $status;
                }
                if ($doc !== '') {
                    $display[$key]['KVT_Document_Status'] = $doc;
                }
                $changed++;
            }
        }
        if ($changed > 0) {
            demeter_workorder_state_cache_save_display_rows($company, $costCenter, $display);
        }
        $state = demeter_workorder_state_cache_load($company, $costCenter);
        if (is_array($state) && is_array($state['workorders'] ?? null)) {
            $stateChanged = false;
            foreach ($state['workorders'] as $pairKey => $entry) {
                $snap = $statusByPair[(string) $pairKey] ?? null;
                if (!is_array($snap) || !is_array($entry['row'] ?? null)) {
                    continue;
                }
                $status = trim((string) ($snap['Status'] ?? ''));
                if ($status !== '' && $status !== (string) ($entry['row']['Status'] ?? '')) {
                    $state['workorders'][$pairKey]['row']['Status'] = $status;
                    $state['workorders'][$pairKey]['row']['KVT_Document_Status'] = (string) ($snap['KVT_Document_Status'] ?? '');
                    $state['workorders'][$pairKey]['is_closed'] = demeter_status_is_closed($status);
                    $stateChanged = true;
                }
            }
            if ($stateChanged) {
                demeter_workorder_state_cache_save(
                    $company,
                    $costCenter,
                    $state['workorders'],
                    is_array($state['month_scan'] ?? null) ? $state['month_scan'] : demeter_workorder_month_scan_defaults(),
                    is_array($state['load_session'] ?? null) ? $state['load_session'] : null
                );
            }
        }

        return $changed;
    });

    return ['open_in_cache' => $openInCache, 'updated' => (int) $updated, 'not_found' => $notFound];
}
