<?php

/**
 * Gedeelde refresh-logica voor nightly.php en handmatige verversing.
 */

require_once __DIR__ . '/reference_cache.php';
require_once __DIR__ . '/cost_centers.php';
require_once __DIR__ . '/month_loader.php';
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
    $options = bc_fetch_department_cost_center_options($company, $auth, $ttl);
    demeter_cost_center_options_cache_save($company, $options);

    return $options;
}

/**
 * Ververs alle ISO-weken voor een kostenplaats (zoals de browser-load_month keten).
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
    $loadSessionId = trim((string) ($options['load_session_id'] ?? 'refresh'));
    $progressToken = array_key_exists('progress_token', $options) ? $options['progress_token'] : null;
    $currentWeek = demeter_current_iso_year_week();
    $yearWeek = $currentWeek;
    $monthScan = demeter_workorder_month_scan_defaults();
    $weeksProcessed = 0;

    if ($forceFull) {
        demeter_workorder_state_cache_purge($company, $costCenter);
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
 * Haalt memo's op voor alle rijen in de display-cache en slaat ze op.
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
