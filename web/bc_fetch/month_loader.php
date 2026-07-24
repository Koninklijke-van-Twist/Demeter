<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/invoice_cache.php';
require_once __DIR__ . '/cost_center.php';
require_once __DIR__ . '/workorder_state_cache.php';
require_once __DIR__ . '/projectposten_workorders.php';
require_once __DIR__ . '/../project_finance.php';
require_once __DIR__ . '/../workorder_rows.php';

/**
 * Functies
 */
/**
 * Bouwt een datumrange voor één ISO-week (maandag t/m zondag).
 *
 * @return array{from: DateTimeImmutable, to: DateTimeImmutable, year_week: string}
 */
function bc_fetch_week_date_range(string $yearWeek, bool $partialToToday = false): array
{
    return demeter_week_date_range($yearWeek, $partialToToday);
}

/**
 * @deprecated Gebruik bc_fetch_week_date_range.
 * @return array{from: DateTimeImmutable, to: DateTimeImmutable, year_month: string}
 */
function bc_fetch_month_date_range(string $yearMonth, bool $partialToToday = false): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m', trim($yearMonth));
    if (!$from instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Ongeldige maand: ' . $yearMonth);
    }

    $to = $from->modify('+1 month');
    if ($partialToToday) {
        $today = new DateTimeImmutable('today');
        if ($from->format('Y-m') === $today->format('Y-m')) {
            $to = $today->modify('+1 day');
        }
    }

    return [
        'from' => $from,
        'to' => $to,
        'year_month' => $from->format('Y-m'),
    ];
}

/**
 * Laadt één week-chunk voor het werkorderoverzicht.
 *
 * @param array{cost_center?: string, force_full?: bool, partial_to_today?: bool, skip_if_cached?: bool, load_session_id?: string, progress_week_index?: int, progress_week_total?: int, current_week_skip_max_age_hours?: float, _skip_day_path?: bool, _consolidating?: bool} $options
 */
function bc_fetch_load_workorder_week_chunk(
    string $company,
    string $yearWeek,
    array $auth,
    int $ttl,
    ?string $progressToken = null,
    array $options = []
): array {
    $costCenter = bc_fetch_normalize_cost_center((string) ($options['cost_center'] ?? ''));
    if ($costCenter === '') {
        throw new InvalidArgumentException('Kies eerst een kostenplaats voordat gegevens worden opgehaald.');
    }

    $forceFull = !empty($options['force_full']);
    $partialToToday = !empty($options['partial_to_today']);
    $skipIfCached = array_key_exists('skip_if_cached', $options) ? (bool) $options['skip_if_cached'] : true;
    $loadSessionId = trim((string) ($options['load_session_id'] ?? ''));
    $currentWeekSkipMaxAgeHours = array_key_exists('current_week_skip_max_age_hours', $options)
        ? (float) $options['current_week_skip_max_age_hours']
        : null;

    $parsedWeek = demeter_parse_iso_year_week($yearWeek);
    if ($parsedWeek === null) {
        throw new InvalidArgumentException('Ongeldige week: ' . $yearWeek);
    }
    $normalizedYearWeek = demeter_format_iso_year_week($parsedWeek['year'], $parsedWeek['week']);
    $currentCalendarWeek = demeter_current_iso_year_week();

    // Huidige week: per kalenderdag laden (tenzij expliciete consolidatie/closed path).
    if ($normalizedYearWeek === $currentCalendarWeek && empty($options['_skip_day_path'])) {
        return bc_fetch_load_current_week_by_days(
            $company,
            $normalizedYearWeek,
            $auth,
            $ttl,
            $progressToken,
            $options
        );
    }

    $weekRange = bc_fetch_week_date_range($normalizedYearWeek, $partialToToday && $normalizedYearWeek === $currentCalendarWeek);
    $rangeStart = $weekRange['from'];
    $rangeEndExclusive = $weekRange['to'];

    $cachedState = $forceFull ? null : demeter_workorder_state_cache_load($company, $costCenter);
    $monthScan = is_array($cachedState['month_scan'] ?? null) ? $cachedState['month_scan'] : demeter_workorder_month_scan_defaults();
    $isIncrementalRun = $cachedState !== null;
    $displayRowsByKey = $isIncrementalRun
        ? demeter_workorder_state_cache_load_display_rows($company, $costCenter)
        : [];

    $needsConsolidation = demeter_month_scan_week_needs_consolidation(
        $monthScan,
        $normalizedYearWeek,
        $currentCalendarWeek
    );
    if ($needsConsolidation) {
        $forceFull = true;
        $skipIfCached = false;
        $partialToToday = false;
        $weekRange = bc_fetch_week_date_range($normalizedYearWeek, false);
        $rangeStart = $weekRange['from'];
        $rangeEndExclusive = $weekRange['to'];
    }

    $previousWeekProjectTotals = demeter_month_scan_week_project_totals($monthScan, $normalizedYearWeek);
    $previousWeekWoTotals = demeter_month_scan_week_workorder_totals($monthScan, $normalizedYearWeek);

    if ($skipIfCached && !$forceFull && demeter_month_scan_can_skip_reload(
        $normalizedYearWeek,
        $monthScan,
        $currentCalendarWeek,
        false,
        $displayRowsByKey,
        $currentWeekSkipMaxAgeHours
    )) {
        $monthMeta = is_array($monthScan['months'][$normalizedYearWeek] ?? null) ? $monthScan['months'][$normalizedYearWeek] : [];
        $nextWeek = demeter_previous_iso_year_week($normalizedYearWeek);

        $progressWeekIndex = max(0, (int) ($options['progress_week_index'] ?? 0));
        $progressWeekTotal = max(0, (int) ($options['progress_week_total'] ?? 0));
        if (
            is_string($progressToken) && $progressToken !== ''
            && $progressWeekIndex > 0 && $progressWeekTotal > 0
            && function_exists('odata_load_progress_advance_month')
        ) {
            odata_load_progress_advance_month(
                $progressToken,
                min($progressWeekTotal * 4, $progressWeekIndex * 4),
                $progressWeekTotal * 4,
                $normalizedYearWeek . ' (cache)'
            );
        }

        return [
            'skipped' => true,
            'year_week' => $normalizedYearWeek,
            'year_month' => $normalizedYearWeek,
            'has_projectposten' => !empty($monthMeta['has_projectposten']),
            'empty' => !empty($monthMeta['empty']),
            'only_closed_cached' => !empty($monthMeta['only_closed_cached']),
            'row_keys' => demeter_month_scan_expected_row_keys($monthScan, $normalizedYearWeek),
            'month_scan' => $monthScan,
            'next_week' => $nextWeek,
            'next_month' => $nextWeek,
            'should_continue' => demeter_month_scan_should_continue($monthScan, $nextWeek),
            'workorders' => [],
            'project_totals_by_job' => [],
            'workorder_totals_by_number' => [],
            'workorder_totals_by_project_and_number' => [],
            'projectposten_rows_by_project' => [],
            'projectposten_rows_by_project_and_workorder' => [],
            'invoice_details_by_id' => [],
            'project_invoice_ids_by_job' => [],
            'project_invoiced_total_by_job' => [],
            'finance_key_by_pair' => [],
            'load_meta' => [
                'cost_center' => $costCenter,
                'year_week' => $normalizedYearWeek,
                'year_month' => $normalizedYearWeek,
                'incremental' => $cachedState !== null,
                'skipped_cached' => true,
                'week_load_mode' => 'skip',
            ],
        ];
    }

    $loaded = bc_fetch_execute_workorder_date_range_load(
        $company,
        $costCenter,
        $rangeStart,
        $rangeEndExclusive,
        $normalizedYearWeek,
        $auth,
        $ttl,
        $progressToken,
        $options,
        $cachedState,
        $forceFull,
        $normalizedYearWeek
    );

    $monthScan = is_array($loaded['month_scan_base'] ?? null) ? $loaded['month_scan_base'] : $monthScan;
    $workorderTotals = is_array($loaded['workorder_totals_by_project_and_number'] ?? null)
        ? $loaded['workorder_totals_by_project_and_number']
        : [];
    $weekProjectTotals = is_array($loaded['project_totals_by_job'] ?? null) ? $loaded['project_totals_by_job'] : [];
    $builtRows = is_array($loaded['built_rows'] ?? null) ? $loaded['built_rows'] : ['rows' => [], 'row_keys' => []];
    $rowKeys = is_array($builtRows['row_keys'] ?? null) ? $builtRows['row_keys'] : [];
    $hasWeekRows = is_array($builtRows['rows'] ?? null) && $builtRows['rows'] !== [];

    $monthScan = demeter_month_scan_update_after_load(
        $normalizedYearWeek,
        $hasWeekRows,
        !empty($loaded['only_closed_cached']),
        $rowKeys,
        $monthScan
    );
    $monthScan = demeter_month_scan_store_week_project_totals($monthScan, $normalizedYearWeek, $weekProjectTotals);
    if (!isset($monthScan['months'][$normalizedYearWeek]) || !is_array($monthScan['months'][$normalizedYearWeek])) {
        $monthScan['months'][$normalizedYearWeek] = [];
    }
    $monthScan['months'][$normalizedYearWeek]['workorder_totals_by_project_and_number'] = $workorderTotals;
    $monthScan = demeter_month_scan_close_week($monthScan, $normalizedYearWeek);

    $displayRowsByKey = is_array($loaded['display_rows_by_key'] ?? null) ? $loaded['display_rows_by_key'] : $displayRowsByKey;
    if ($needsConsolidation) {
        $displayRowsByKey = demeter_merge_display_rows_for_open_week_finance_delta(
            $displayRowsByKey,
            is_array($builtRows['rows'] ?? null) ? $builtRows['rows'] : [],
            $previousWeekWoTotals,
            $workorderTotals
        );
    } else {
        $displayRowsByKey = demeter_merge_display_rows_for_month_chunk(
            $displayRowsByKey,
            is_array($builtRows['rows'] ?? null) ? $builtRows['rows'] : [],
            false,
            []
        );
    }

    if (demeter_month_scan_has_complete_project_totals($monthScan)) {
        $displayRowsByKey = demeter_apply_project_totals_to_display_rows(
            $displayRowsByKey,
            demeter_month_scan_cumulative_project_totals($monthScan)
        );
    } else {
        $displayRowsByKey = demeter_adjust_project_totals_on_display_rows_by_week_delta(
            $displayRowsByKey,
            $previousWeekProjectTotals,
            $weekProjectTotals
        );
    }

    demeter_workorder_state_cache_save(
        $company,
        $costCenter,
        is_array($loaded['cache_state'] ?? null) ? $loaded['cache_state'] : [],
        $monthScan,
        is_array($loaded['load_session'] ?? null) ? $loaded['load_session'] : demeter_workorder_load_session_defaults()
    );
    demeter_workorder_state_cache_save_display_rows($company, $costCenter, $displayRowsByKey);

    $nextWeek = demeter_previous_iso_year_week($normalizedYearWeek);
    $loadMeta = is_array($loaded['load_meta'] ?? null) ? $loaded['load_meta'] : [];
    if ($needsConsolidation) {
        $loadMeta['week_consolidated'] = true;
    }

    return [
        'skipped' => false,
        'year_week' => $normalizedYearWeek,
        'year_month' => $normalizedYearWeek,
        'has_projectposten' => !empty($loaded['has_projectposten']),
        'empty' => !$hasWeekRows,
        'only_closed_cached' => !empty($loaded['only_closed_cached']),
        'row_keys' => $rowKeys,
        'rows' => is_array($builtRows['rows'] ?? null) ? $builtRows['rows'] : [],
        'month_scan' => $monthScan,
        'next_week' => $nextWeek,
        'next_month' => $nextWeek,
        'should_continue' => demeter_month_scan_should_continue($monthScan, $nextWeek),
        'workorders' => is_array($loaded['workorders'] ?? null) ? $loaded['workorders'] : [],
        'project_totals_by_job' => $weekProjectTotals,
        'workorder_totals_by_number' => is_array($loaded['workorder_totals_by_number'] ?? null) ? $loaded['workorder_totals_by_number'] : [],
        'workorder_totals_by_project_and_number' => $workorderTotals,
        'projectposten_rows_by_project' => is_array($loaded['projectposten_rows_by_project'] ?? null) ? $loaded['projectposten_rows_by_project'] : [],
        'projectposten_rows_by_project_and_workorder' => is_array($loaded['projectposten_rows_by_project_and_workorder'] ?? null) ? $loaded['projectposten_rows_by_project_and_workorder'] : [],
        'invoice_details_by_id' => is_array($loaded['invoice_details_by_id'] ?? null) ? $loaded['invoice_details_by_id'] : [],
        'project_invoice_ids_by_job' => is_array($loaded['project_invoice_ids_by_job'] ?? null) ? $loaded['project_invoice_ids_by_job'] : [],
        'project_invoiced_total_by_job' => is_array($loaded['project_invoiced_total_by_job'] ?? null) ? $loaded['project_invoiced_total_by_job'] : [],
        'finance_key_by_pair' => is_array($loaded['finance_key_by_pair'] ?? null) ? $loaded['finance_key_by_pair'] : [],
        'load_meta' => $loadMeta,
    ];
}

/**
 * Laadt de huidige ISO-week per kalenderdag (alleen ontbrekende dagen + vandaag).
 *
 * @param array<string, mixed> $options
 */
function bc_fetch_load_current_week_by_days(
    string $company,
    string $yearWeek,
    array $auth,
    int $ttl,
    ?string $progressToken = null,
    array $options = []
): array {
    $costCenter = bc_fetch_normalize_cost_center((string) ($options['cost_center'] ?? ''));
    $forceFull = !empty($options['force_full']);
    $skipIfCached = array_key_exists('skip_if_cached', $options) ? (bool) $options['skip_if_cached'] : true;
    $currentWeekSkipMaxAgeHours = array_key_exists('current_week_skip_max_age_hours', $options)
        ? (float) $options['current_week_skip_max_age_hours']
        : null;

    $cachedState = $forceFull ? null : demeter_workorder_state_cache_load($company, $costCenter);
    $monthScan = is_array($cachedState['month_scan'] ?? null) ? $cachedState['month_scan'] : demeter_workorder_month_scan_defaults();
    $displayRowsByKey = $cachedState !== null
        ? demeter_workorder_state_cache_load_display_rows($company, $costCenter)
        : [];

    // Legacy huidige week (wel scanned_at, geen days): haal week-bijdrage van display af vóór day-rebuild.
    $currentMeta = is_array($monthScan['months'][$yearWeek] ?? null) ? $monthScan['months'][$yearWeek] : [];
    $hasLegacyWeekWithoutDays = trim((string) ($currentMeta['scanned_at'] ?? '')) !== ''
        && !array_key_exists('days', $currentMeta)
        && empty($currentMeta['open']);
    if ($hasLegacyWeekWithoutDays && !$forceFull) {
        $legacyProjectTotals = demeter_month_scan_week_project_totals($monthScan, $yearWeek);
        $legacyWoTotals = demeter_month_scan_week_workorder_totals($monthScan, $yearWeek);
        $displayRowsByKey = demeter_adjust_project_totals_on_display_rows_by_week_delta(
            $displayRowsByKey,
            $legacyProjectTotals,
            []
        );
        $displayRowsByKey = demeter_adjust_workorder_totals_on_display_rows_by_delta(
            $displayRowsByKey,
            $legacyWoTotals,
            []
        );
        unset($monthScan['months'][$yearWeek]);
        demeter_workorder_state_cache_save(
            $company,
            $costCenter,
            is_array($cachedState) ? $cachedState : [],
            $monthScan,
            is_array($cachedState)
                ? demeter_workorder_state_normalize_load_session($cachedState['load_session'] ?? null)
                : demeter_workorder_load_session_defaults()
        );
        demeter_workorder_state_cache_save_display_rows($company, $costCenter, $displayRowsByKey);
        if (is_array($cachedState)) {
            $cachedState['month_scan'] = $monthScan;
        }
    }

    // Consolideer vorige open week eerst (volle BC ma–zo).
    $previousWeek = demeter_previous_iso_year_week($yearWeek);
    if (is_string($previousWeek) && demeter_month_scan_week_needs_consolidation($monthScan, $previousWeek, $yearWeek)) {
        $consolidateOptions = $options;
        // Geen cache-purge: day-totalen moeten beschikbaar blijven voor finance-delta.
        $consolidateOptions['force_full'] = false;
        $consolidateOptions['skip_if_cached'] = false;
        $consolidateOptions['partial_to_today'] = false;
        $consolidateOptions['_skip_day_path'] = true;
        $consolidateOptions['_consolidating'] = true;
        bc_fetch_load_workorder_week_chunk($company, $previousWeek, $auth, $ttl, $progressToken, $consolidateOptions);

        $cachedState = demeter_workorder_state_cache_load($company, $costCenter);
        $monthScan = is_array($cachedState['month_scan'] ?? null) ? $cachedState['month_scan'] : demeter_workorder_month_scan_defaults();
        $displayRowsByKey = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
    }

    $daysToLoad = demeter_month_scan_open_week_days_to_load(
        $yearWeek,
        $monthScan,
        $forceFull,
        $currentWeekSkipMaxAgeHours
    );

    if ($skipIfCached && !$forceFull && $daysToLoad === []) {
        $monthMeta = is_array($monthScan['months'][$yearWeek] ?? null) ? $monthScan['months'][$yearWeek] : [];
        $nextWeek = demeter_previous_iso_year_week($yearWeek);

        return [
            'skipped' => true,
            'year_week' => $yearWeek,
            'year_month' => $yearWeek,
            'has_projectposten' => !empty($monthMeta['has_projectposten']),
            'empty' => !empty($monthMeta['empty']),
            'only_closed_cached' => !empty($monthMeta['only_closed_cached']),
            'row_keys' => demeter_month_scan_expected_row_keys($monthScan, $yearWeek),
            'month_scan' => $monthScan,
            'next_week' => $nextWeek,
            'next_month' => $nextWeek,
            'should_continue' => demeter_month_scan_should_continue($monthScan, $nextWeek),
            'workorders' => [],
            'project_totals_by_job' => demeter_month_scan_week_project_totals($monthScan, $yearWeek),
            'workorder_totals_by_number' => [],
            'workorder_totals_by_project_and_number' => demeter_month_scan_week_workorder_totals($monthScan, $yearWeek),
            'projectposten_rows_by_project' => [],
            'projectposten_rows_by_project_and_workorder' => [],
            'invoice_details_by_id' => [],
            'project_invoice_ids_by_job' => [],
            'project_invoiced_total_by_job' => [],
            'finance_key_by_pair' => [],
            'load_meta' => [
                'cost_center' => $costCenter,
                'year_week' => $yearWeek,
                'year_month' => $yearWeek,
                'incremental' => $cachedState !== null,
                'skipped_cached' => true,
                'week_load_mode' => 'skip',
                'day_load' => true,
            ],
        ];
    }

    $aggregatedRows = [];
    $aggregatedRowKeys = [];
    $aggregatedWorkorders = [];
    $aggregatedProjectPostenByProject = [];
    $aggregatedProjectPostenByWo = [];
    $aggregatedInvoiceDetails = [];
    $aggregatedProjectInvoiceIds = [];
    $aggregatedProjectInvoicedTotal = [];
    $aggregatedFinanceKeyByPair = [];
    $lastCacheState = is_array($cachedState) ? $cachedState : [];
    $lastLoadSession = is_array($cachedState)
        ? demeter_workorder_state_normalize_load_session($cachedState['load_session'] ?? null)
        : demeter_workorder_load_session_defaults();
    $hasProjectPosten = false;
    $loadMeta = [
        'cost_center' => $costCenter,
        'year_week' => $yearWeek,
        'year_month' => $yearWeek,
        'incremental' => $cachedState !== null,
        'skipped_cached' => false,
        'week_load_mode' => 'day',
        'day_load' => true,
        'days_loaded' => [],
        'from_cache_count' => 0,
        'updated_from_bc_count' => 0,
    ];

    foreach ($daysToLoad as $dayYmd) {
        $previousWeekProjectTotals = demeter_month_scan_week_project_totals($monthScan, $yearWeek);
        $previousDayWoTotals = demeter_month_scan_day_workorder_totals($monthScan, $yearWeek, $dayYmd);

        $dayRange = demeter_day_date_range($dayYmd);
        $loaded = bc_fetch_execute_workorder_date_range_load(
            $company,
            $costCenter,
            $dayRange['from'],
            $dayRange['to'],
            $yearWeek . '/' . $dayYmd,
            $auth,
            $ttl,
            $progressToken,
            $options,
            $cachedState,
            $forceFull,
            $yearWeek
        );

        $builtRows = is_array($loaded['built_rows'] ?? null) ? $loaded['built_rows'] : ['rows' => [], 'row_keys' => []];
        $dayRows = is_array($builtRows['rows'] ?? null) ? $builtRows['rows'] : [];
        $dayRowKeys = is_array($builtRows['row_keys'] ?? null) ? $builtRows['row_keys'] : [];
        $dayProjectTotals = is_array($loaded['project_totals_by_job'] ?? null) ? $loaded['project_totals_by_job'] : [];
        $dayWoTotals = is_array($loaded['workorder_totals_by_project_and_number'] ?? null)
            ? $loaded['workorder_totals_by_project_and_number']
            : [];

        $monthScan = demeter_month_scan_store_day_after_load(
            $monthScan,
            $yearWeek,
            $dayYmd,
            $dayRows !== [],
            $dayRowKeys,
            $dayProjectTotals,
            $dayWoTotals
        );

        $displayRowsByKey = is_array($loaded['display_rows_by_key'] ?? null) && $loaded['display_rows_by_key'] !== []
            ? $loaded['display_rows_by_key']
            : $displayRowsByKey;
        $displayRowsByKey = demeter_merge_display_rows_for_open_week_finance_delta(
            $displayRowsByKey,
            $dayRows,
            $previousDayWoTotals,
            $dayWoTotals
        );

        $newWeekProjectTotals = demeter_month_scan_week_project_totals($monthScan, $yearWeek);
        if (demeter_month_scan_has_complete_project_totals($monthScan)) {
            $displayRowsByKey = demeter_apply_project_totals_to_display_rows(
                $displayRowsByKey,
                demeter_month_scan_cumulative_project_totals($monthScan)
            );
        } else {
            $displayRowsByKey = demeter_adjust_project_totals_on_display_rows_by_week_delta(
                $displayRowsByKey,
                $previousWeekProjectTotals,
                $newWeekProjectTotals
            );
        }

        $lastCacheState = is_array($loaded['cache_state'] ?? null) ? $loaded['cache_state'] : $lastCacheState;
        $lastLoadSession = is_array($loaded['load_session'] ?? null) ? $loaded['load_session'] : $lastLoadSession;
        $cachedState = array_merge(is_array($cachedState) ? $cachedState : [], [
            'workorders' => is_array($lastCacheState['workorders'] ?? null) ? $lastCacheState['workorders'] : [],
            'load_session' => $lastLoadSession,
        ]);

        demeter_workorder_state_cache_save($company, $costCenter, $lastCacheState, $monthScan, $lastLoadSession);
        demeter_workorder_state_cache_save_display_rows($company, $costCenter, $displayRowsByKey);

        foreach ($dayRows as $dayRow) {
            if (!is_array($dayRow)) {
                continue;
            }
            $rowKey = trim((string) ($dayRow['Row_Key'] ?? ''));
            if ($rowKey === '') {
                continue;
            }
            $aggregatedRows[$rowKey] = $dayRow;
            $aggregatedRowKeys[$rowKey] = true;
        }
        foreach (is_array($loaded['workorders'] ?? null) ? $loaded['workorders'] : [] as $wo) {
            $aggregatedWorkorders[] = $wo;
        }
        foreach (is_array($loaded['projectposten_rows_by_project'] ?? null) ? $loaded['projectposten_rows_by_project'] : [] as $job => $rows) {
            if (!isset($aggregatedProjectPostenByProject[$job])) {
                $aggregatedProjectPostenByProject[$job] = [];
            }
            if (is_array($rows)) {
                $aggregatedProjectPostenByProject[$job] = array_merge($aggregatedProjectPostenByProject[$job], $rows);
            }
        }
        foreach (is_array($loaded['projectposten_rows_by_project_and_workorder'] ?? null) ? $loaded['projectposten_rows_by_project_and_workorder'] : [] as $key => $rows) {
            if (!isset($aggregatedProjectPostenByWo[$key])) {
                $aggregatedProjectPostenByWo[$key] = [];
            }
            if (is_array($rows)) {
                $aggregatedProjectPostenByWo[$key] = array_merge($aggregatedProjectPostenByWo[$key], $rows);
            }
        }
        if (is_array($loaded['invoice_details_by_id'] ?? null)) {
            $aggregatedInvoiceDetails = array_merge($aggregatedInvoiceDetails, $loaded['invoice_details_by_id']);
        }
        if (is_array($loaded['project_invoice_ids_by_job'] ?? null)) {
            $aggregatedProjectInvoiceIds = array_merge($aggregatedProjectInvoiceIds, $loaded['project_invoice_ids_by_job']);
        }
        if (is_array($loaded['project_invoiced_total_by_job'] ?? null)) {
            $aggregatedProjectInvoicedTotal = array_merge($aggregatedProjectInvoicedTotal, $loaded['project_invoiced_total_by_job']);
        }
        if (is_array($loaded['finance_key_by_pair'] ?? null)) {
            $aggregatedFinanceKeyByPair = array_merge($aggregatedFinanceKeyByPair, $loaded['finance_key_by_pair']);
        }
        if (!empty($loaded['has_projectposten'])) {
            $hasProjectPosten = true;
        }

        $dayMeta = is_array($loaded['load_meta'] ?? null) ? $loaded['load_meta'] : [];
        $loadMeta['days_loaded'][] = $dayYmd;
        $loadMeta['from_cache_count'] += (int) ($dayMeta['from_cache_count'] ?? 0);
        $loadMeta['updated_from_bc_count'] += (int) ($dayMeta['updated_from_bc_count'] ?? 0);
    }

    $nextWeek = demeter_previous_iso_year_week($yearWeek);
    $weekProjectTotals = demeter_month_scan_week_project_totals($monthScan, $yearWeek);
    $weekWoTotals = demeter_month_scan_week_workorder_totals($monthScan, $yearWeek);
    $weekRowKeys = demeter_month_scan_expected_row_keys($monthScan, $yearWeek);
    $monthMeta = is_array($monthScan['months'][$yearWeek] ?? null) ? $monthScan['months'][$yearWeek] : [];

    // Client-merge verwacht rijen met geaccumuleerde WO-kosten (niet alleen de laatst geladen dag).
    $responseRows = [];
    $responseKeys = $weekRowKeys !== [] ? $weekRowKeys : array_keys($aggregatedRowKeys);
    foreach ($responseKeys as $rowKey) {
        if (isset($displayRowsByKey[$rowKey]) && is_array($displayRowsByKey[$rowKey])) {
            $responseRows[] = $displayRowsByKey[$rowKey];
            continue;
        }
        if (isset($aggregatedRows[$rowKey]) && is_array($aggregatedRows[$rowKey])) {
            $responseRows[] = $aggregatedRows[$rowKey];
        }
    }

    return [
        'skipped' => false,
        'year_week' => $yearWeek,
        'year_month' => $yearWeek,
        'has_projectposten' => $hasProjectPosten || !empty($monthMeta['has_projectposten']),
        'empty' => !empty($monthMeta['empty']),
        'only_closed_cached' => false,
        'row_keys' => $responseKeys,
        'rows' => $responseRows,
        'month_scan' => $monthScan,
        'next_week' => $nextWeek,
        'next_month' => $nextWeek,
        'should_continue' => demeter_month_scan_should_continue($monthScan, $nextWeek),
        'workorders' => $aggregatedWorkorders,
        'project_totals_by_job' => $weekProjectTotals,
        'workorder_totals_by_number' => [],
        'workorder_totals_by_project_and_number' => $weekWoTotals,
        'projectposten_rows_by_project' => $aggregatedProjectPostenByProject,
        'projectposten_rows_by_project_and_workorder' => $aggregatedProjectPostenByWo,
        'invoice_details_by_id' => $aggregatedInvoiceDetails,
        'project_invoice_ids_by_job' => $aggregatedProjectInvoiceIds,
        'project_invoiced_total_by_job' => $aggregatedProjectInvoicedTotal,
        'finance_key_by_pair' => $aggregatedFinanceKeyByPair,
        'load_meta' => $loadMeta,
    ];
}

/**
 * Voert de BC-fetch uit voor een datumrange zonder month_scan te finaliseren.
 *
 * @param array<string, mixed>|null $cachedState
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function bc_fetch_execute_workorder_date_range_load(
    string $company,
    string $costCenter,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEndExclusive,
    string $progressLabel,
    array $auth,
    int $ttl,
    ?string $progressToken,
    array $options,
    ?array $cachedState,
    bool $forceFull,
    string $yearWeekForMode = ''
): array {
    $loadSessionId = trim((string) ($options['load_session_id'] ?? ''));
    $currentCalendarWeek = demeter_current_iso_year_week();
    $scanWeek = $yearWeekForMode !== '' ? $yearWeekForMode : $currentCalendarWeek;
    $monthScan = is_array($cachedState['month_scan'] ?? null) ? $cachedState['month_scan'] : demeter_workorder_month_scan_defaults();
    $isIncrementalRun = $cachedState !== null;
    $displayRowsByKey = $isIncrementalRun
        ? demeter_workorder_state_cache_load_display_rows($company, $costCenter)
        : [];

    $weekLoadMode = $forceFull
        ? 'full'
        : (demeter_month_scan_should_use_lightweight_refresh($scanWeek, $monthScan, $currentCalendarWeek, $forceFull)
            ? 'lightweight'
            : 'full');

    $totalProgressSteps = 4;
    $progressStep = 0;
    $progressWeekIndex = max(0, (int) ($options['progress_week_index'] ?? 0));
    $progressWeekTotal = max(0, (int) ($options['progress_week_total'] ?? 0));
    if ($progressWeekTotal > 0 && $progressWeekIndex > 0) {
        $totalProgressSteps = $progressWeekTotal * 4;
    }

    $advanceProgress = static function (string $label) use (
        &$progressStep,
        $progressToken,
        &$totalProgressSteps,
        $progressWeekIndex,
        $progressWeekTotal,
        $progressLabel
    ): void {
        $progressStep++;
        if (!is_string($progressToken) || $progressToken === '' || !function_exists('odata_load_progress_advance_month')) {
            return;
        }

        if ($progressWeekTotal > 0 && $progressWeekIndex > 0) {
            $overallStep = min($totalProgressSteps, (($progressWeekIndex - 1) * 4) + $progressStep);
            odata_load_progress_advance_month(
                $progressToken,
                $overallStep,
                $totalProgressSteps,
                $progressLabel . ': ' . $label
            );

            return;
        }

        odata_load_progress_advance_month($progressToken, $progressStep, 4, $progressLabel . ': ' . $label);
    };

    $cachedWorkorders = is_array($cachedState['workorders'] ?? null) ? $cachedState['workorders'] : [];
    $loadSession = is_array($cachedState)
        ? demeter_workorder_state_normalize_load_session($cachedState['load_session'] ?? null)
        : demeter_workorder_load_session_defaults();

    $advanceProgress('ProjectPosten');
    $financeService = new ProjectFinanceService($company);
    $rangeFinance = $financeService->collectProjectAndWorkorderFinanceFromProjectPostenRange(
        $rangeStart->format('Y-m-d'),
        $rangeEndExclusive->format('Y-m-d'),
        $ttl,
        null
    );

    $allProjectPostenRows = is_array($rangeFinance['projectposten_rows'] ?? null) ? $rangeFinance['projectposten_rows'] : [];
    $extractedKeys = bc_fetch_extract_workorder_keys_from_projectposten_rows($allProjectPostenRows);
    $pairs = $extractedKeys['pairs'];
    $financeKeyByPair = $extractedKeys['finance_key_by_pair'];
    $pairKeysInPosten = $extractedKeys['pair_keys_in_posten'];

    if ($allProjectPostenRows === []) {
        $cacheState = bc_fetch_build_workorder_state_cache([], $financeKeyByPair, $pairKeysInPosten, $cachedState);

        return [
            'month_scan_base' => $monthScan,
            'cache_state' => $cacheState,
            'load_session' => $loadSession,
            'display_rows_by_key' => $displayRowsByKey,
            'built_rows' => ['rows' => [], 'row_keys' => []],
            'workorders' => [],
            'project_totals_by_job' => [],
            'workorder_totals_by_number' => [],
            'workorder_totals_by_project_and_number' => [],
            'projectposten_rows_by_project' => [],
            'projectposten_rows_by_project_and_workorder' => [],
            'invoice_details_by_id' => [],
            'project_invoice_ids_by_job' => [],
            'project_invoiced_total_by_job' => [],
            'finance_key_by_pair' => $financeKeyByPair,
            'has_projectposten' => false,
            'only_closed_cached' => false,
            'load_meta' => [
                'cost_center' => $costCenter,
                'incremental' => $isIncrementalRun,
                'from_cache_count' => 0,
                'updated_from_bc_count' => 0,
                'skipped_cached' => false,
                'week_load_mode' => 'lightweight',
            ],
        ];
    }

    $fetchPlan = bc_fetch_resolve_workorder_fetch_plan($pairs, $pairKeysInPosten, $cachedState, $forceFull, $loadSessionId);
    $fetchPairs = $fetchPlan['fetch_pairs'];
    $cachedWorkorderRows = $fetchPlan['use_cached_rows'];
    $statusCheckCount = 0;
    $statusClosedCount = 0;
    $statusRefreshCount = 0;

    if (!$forceFull && $fetchPlan['status_check_pairs'] !== []) {
        $advanceProgress('Open werkorders controleren');
        $staleResult = bc_fetch_process_stale_workorders_via_status_check(
            $company,
            $fetchPlan['status_check_pairs'],
            $cachedWorkorders,
            $auth,
            $ttl
        );
        $statusCheckedPairKeys = [];
        foreach ($fetchPlan['status_check_pairs'] as $statusCheckPair) {
            if (!is_array($statusCheckPair)) {
                continue;
            }
            $jobNo = trim((string) ($statusCheckPair['job_no'] ?? ''));
            $jobTaskNo = trim((string) ($statusCheckPair['job_task_no'] ?? ''));
            if ($jobNo === '' || $jobTaskNo === '') {
                continue;
            }
            $statusCheckedPairKeys[] = demeter_workorder_pair_key($jobNo, $jobTaskNo);
        }
        $loadSession = demeter_workorder_state_record_status_checked_pairs(
            $loadSession,
            $loadSessionId,
            $statusCheckedPairKeys
        );
        $fetchPairs = array_merge($fetchPairs, $staleResult['fetch_pairs']);
        $cachedWorkorderRows = array_merge(
            $cachedWorkorderRows,
            $staleResult['use_cached_rows'],
            $staleResult['status_updated_rows']
        );
        $statusCheckCount = count($fetchPlan['status_check_pairs']);
        $statusClosedCount = count($staleResult['status_updated_rows']);
    }

    $statusRefreshPairs = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }
        $pairKey = demeter_workorder_pair_key($jobNo, $jobTaskNo);
        if (!isset($pairKeysInPosten[$pairKey])) {
            continue;
        }
        $cachedEntry = $cachedWorkorders[$pairKey] ?? null;
        if (!is_array($cachedEntry) || !empty($cachedEntry['is_closed']) || !is_array($cachedEntry['row'] ?? null)) {
            continue;
        }
        $statusRefreshPairs[] = [
            'job_no' => $jobNo,
            'job_task_no' => $jobTaskNo,
        ];
    }
    if ($statusRefreshPairs !== []) {
        $statusSnapshots = bc_fetch_fetch_workorder_status_by_pairs($company, $statusRefreshPairs, $auth, $ttl);
        $cachedWorkorderRows = bc_fetch_apply_status_snapshots_to_workorder_rows($cachedWorkorderRows, $statusSnapshots);
        $statusRefreshCount = count($statusRefreshPairs);
        $statusCheckCount += $statusRefreshCount;
    }

    if ($fetchPairs === [] && !$forceFull && $cachedWorkorderRows !== []) {
        $weekLoadMode = 'lightweight';
    }

    $advanceProgress($weekLoadMode === 'lightweight' ? 'Werkorders (cache)' : ($isIncrementalRun ? 'Werkorders (open)' : 'Werkorders'));
    $fetchedWorkorders = $fetchPairs !== []
        ? bc_fetch_workorders_by_job_task_pairs($company, $fetchPairs, $auth, $ttl)
        : [];
    $workorders = bc_fetch_merge_workorder_rows($cachedWorkorderRows, $fetchedWorkorders);

    if ($costCenter !== '') {
        $workorders = bc_fetch_filter_workorders_for_cost_center($workorders, $allProjectPostenRows, $costCenter);
        $allowedPairKeys = bc_fetch_pair_keys_from_workorders($workorders);
        $filteredProjectPostenRows = bc_fetch_filter_projectposten_rows_by_pair_keys($allProjectPostenRows, $allowedPairKeys);
        $rangeFinance = $financeService->aggregateProjectAndWorkorderFinanceFromProjectPostenRows($filteredProjectPostenRows);
        $extractedKeys = bc_fetch_extract_workorder_keys_from_projectposten_rows($filteredProjectPostenRows);
        $financeKeyByPair = $extractedKeys['finance_key_by_pair'];
        $pairKeysInPosten = $extractedKeys['pair_keys_in_posten'];
    }

    $projectPostenRows = is_array($rangeFinance['projectposten_rows'] ?? null) ? $rangeFinance['projectposten_rows'] : [];
    $hasProjectPostenForCostCenter = $projectPostenRows !== [];
    $onlyClosedCached = $hasProjectPostenForCostCenter
        && $fetchPairs === []
        && $fetchPlan['use_cached_rows'] !== [];

    $advanceProgress('Werkorders samenvoegen');

    $projectNumbers = is_array($rangeFinance['project_numbers'] ?? null) ? $rangeFinance['project_numbers'] : [];
    $invoiceDetailsById = [];
    $projectInvoiceIdsByJob = [];
    $projectInvoicedTotalByJob = [];
    $invoiceLoadMeta = [
        'from_cache_count' => 0,
        'fetched_count' => 0,
    ];

    if ($projectNumbers !== []) {
        $invoiceData = bc_fetch_resolve_invoices_for_projects(
            $company,
            $projectNumbers,
            $auth,
            $ttl,
            false
        );
        $invoiceDetailsById = is_array($invoiceData['invoice_details_by_id'] ?? null)
            ? $invoiceData['invoice_details_by_id']
            : [];
        $projectInvoiceIdsByJob = is_array($invoiceData['project_invoice_ids_by_job'] ?? null)
            ? $invoiceData['project_invoice_ids_by_job']
            : [];
        $projectInvoicedTotalByJob = is_array($invoiceData['project_invoiced_total_by_job'] ?? null)
            ? $invoiceData['project_invoiced_total_by_job']
            : [];
        $invoiceLoadMeta = is_array($invoiceData['load_meta'] ?? null) ? $invoiceData['load_meta'] : $invoiceLoadMeta;
    }

    $advanceProgress('Facturen');

    $importSapWorkorderRows = is_array($rangeFinance['import_sap_workorder_rows'] ?? null)
        ? $rangeFinance['import_sap_workorder_rows']
        : [];
    if ($costCenter !== '') {
        $importSapWorkorderRows = bc_fetch_filter_workorders_for_cost_center($importSapWorkorderRows, $allProjectPostenRows, $costCenter);
    }
    $workorders = bc_fetch_merge_workorder_rows($workorders, $importSapWorkorderRows);

    $cacheState = bc_fetch_build_workorder_state_cache(
        $workorders,
        $financeKeyByPair,
        $pairKeysInPosten,
        $cachedState
    );

    $builtRows = demeter_build_workorder_rows_from_overview([
        'workorders' => $workorders,
        'project_totals_by_job' => is_array($rangeFinance['project_totals_by_job'] ?? null) ? $rangeFinance['project_totals_by_job'] : [],
        'project_invoice_ids_by_job' => $projectInvoiceIdsByJob,
        'project_invoiced_total_by_job' => $projectInvoicedTotalByJob,
        'workorder_totals_by_project_and_number' => is_array($rangeFinance['workorder_totals_by_project_and_number'] ?? null)
            ? $rangeFinance['workorder_totals_by_project_and_number']
            : [],
        'finance_key_by_pair' => $financeKeyByPair,
    ], 'both');

    return [
        'month_scan_base' => $monthScan,
        'cache_state' => $cacheState,
        'load_session' => $loadSession,
        'display_rows_by_key' => $displayRowsByKey,
        'built_rows' => $builtRows,
        'workorders' => $workorders,
        'project_totals_by_job' => is_array($rangeFinance['project_totals_by_job'] ?? null) ? $rangeFinance['project_totals_by_job'] : [],
        'workorder_totals_by_number' => is_array($rangeFinance['workorder_totals_by_number'] ?? null) ? $rangeFinance['workorder_totals_by_number'] : [],
        'workorder_totals_by_project_and_number' => is_array($rangeFinance['workorder_totals_by_project_and_number'] ?? null) ? $rangeFinance['workorder_totals_by_project_and_number'] : [],
        'projectposten_rows_by_project' => is_array($rangeFinance['projectposten_rows_by_project'] ?? null) ? $rangeFinance['projectposten_rows_by_project'] : [],
        'projectposten_rows_by_project_and_workorder' => is_array($rangeFinance['projectposten_rows_by_project_and_workorder'] ?? null) ? $rangeFinance['projectposten_rows_by_project_and_workorder'] : [],
        'invoice_details_by_id' => $invoiceDetailsById,
        'project_invoice_ids_by_job' => $projectInvoiceIdsByJob,
        'project_invoiced_total_by_job' => $projectInvoicedTotalByJob,
        'finance_key_by_pair' => $financeKeyByPair,
        'has_projectposten' => $hasProjectPostenForCostCenter,
        'only_closed_cached' => $onlyClosedCached,
        'load_meta' => [
            'cost_center' => $costCenter,
            'incremental' => $isIncrementalRun,
            'from_cache_count' => count($cachedWorkorderRows) - $statusClosedCount,
            'updated_from_bc_count' => count($fetchedWorkorders),
            'status_check_count' => $statusCheckCount,
            'status_closed_via_check_count' => $statusClosedCount,
            'invoice_from_cache_count' => (int) ($invoiceLoadMeta['from_cache_count'] ?? 0),
            'invoice_fetched_count' => (int) ($invoiceLoadMeta['fetched_count'] ?? 0),
            'skipped_cached' => false,
            'week_load_mode' => $weekLoadMode,
            'status_refresh_count' => $statusRefreshCount,
        ],
    ];
}

/**
 * @deprecated Gebruik bc_fetch_load_workorder_week_chunk.
 */
function bc_fetch_load_workorder_month_chunk(
    string $company,
    string $yearWeek,
    array $auth,
    int $ttl,
    ?string $progressToken = null,
    array $options = []
): array {
    return bc_fetch_load_workorder_week_chunk($company, $yearWeek, $auth, $ttl, $progressToken, $options);
}

/**
 * Laadt meerdere maanden (legacy helper).
 *
 * @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable}> $ranges
 * @param array{cost_center?: string, from_month?: string, to_month?: string, force_full?: bool} $options
 */
function bc_fetch_load_workorder_overview_data(
    string $company,
    array $ranges,
    array $auth,
    int $ttl,
    ?string $progressToken = null,
    array $options = []
): array {
    $aggregate = [
        'workorders' => [],
        'project_totals_by_job' => [],
        'workorder_totals_by_number' => [],
        'workorder_totals_by_project_and_number' => [],
        'projectposten_rows_by_project' => [],
        'projectposten_rows_by_project_and_workorder' => [],
        'invoice_details_by_id' => [],
        'project_invoice_ids_by_job' => [],
        'project_invoiced_total_by_job' => [],
        'finance_key_by_pair' => [],
        'load_meta' => [],
    ];

    $todayWeek = demeter_current_iso_year_week();
    $loadMeta = [];

    foreach ($ranges as $range) {
        $rangeFrom = $range['from'] ?? null;
        if (!$rangeFrom instanceof DateTimeImmutable) {
            continue;
        }

        $yearWeek = demeter_iso_year_week_from_date($rangeFrom);
        $chunkOptions = $options;
        $chunkOptions['partial_to_today'] = $yearWeek === $todayWeek;
        $chunkOptions['skip_if_cached'] = false;

        $chunk = bc_fetch_load_workorder_week_chunk($company, $yearWeek, $auth, $ttl, $progressToken, $chunkOptions);
        if (!empty($chunk['skipped'])) {
            continue;
        }

        $aggregate = demeter_merge_overview_chunks($aggregate, $chunk);
        $loadMeta = is_array($chunk['load_meta'] ?? null) ? $chunk['load_meta'] : $loadMeta;
    }

    $aggregate['load_meta'] = $loadMeta;

    return $aggregate;
}
