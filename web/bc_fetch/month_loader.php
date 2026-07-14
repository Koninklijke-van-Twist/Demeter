<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/registry.php';
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
 * @param array{cost_center?: string, force_full?: bool, partial_to_today?: bool, skip_if_cached?: bool} $options
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

    $weekRange = bc_fetch_week_date_range($yearWeek, $partialToToday);
    $rangeStart = $weekRange['from'];
    $rangeEndExclusive = $weekRange['to'];
    $normalizedYearWeek = $weekRange['year_week'];
    $currentCalendarWeek = demeter_current_iso_year_week();
    if ($normalizedYearWeek === $currentCalendarWeek) {
        $skipIfCached = false;
    }

    $cachedState = $forceFull ? null : demeter_workorder_state_cache_load($company, $costCenter);
    $monthScan = is_array($cachedState['month_scan'] ?? null) ? $cachedState['month_scan'] : demeter_workorder_month_scan_defaults();
    $isIncrementalRun = $cachedState !== null;

    if ($skipIfCached && !$forceFull && demeter_month_scan_can_skip($normalizedYearWeek, $monthScan)) {
        $monthMeta = is_array($monthScan['months'][$normalizedYearWeek] ?? null) ? $monthScan['months'][$normalizedYearWeek] : [];
        $nextWeek = demeter_previous_iso_year_week($normalizedYearWeek);

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
            ],
        ];
    }

    $totalProgressSteps = 4;
    $progressStep = 0;
    $advanceProgress = static function (string $label) use (&$progressStep, $progressToken, $totalProgressSteps): void {
        $progressStep++;
        if (is_string($progressToken) && $progressToken !== '' && function_exists('odata_load_progress_advance_month')) {
            odata_load_progress_advance_month($progressToken, $progressStep, $totalProgressSteps, $label);
        }
    };

    $cachedWorkorders = is_array($cachedState['workorders'] ?? null) ? $cachedState['workorders'] : [];

    $advanceProgress('ProjectPosten');
    $financeService = new ProjectFinanceService($company);
    $rangeFinance = $financeService->collectProjectAndWorkorderFinanceFromProjectPostenRange(
        $rangeStart->format('Y-m-d'),
        $rangeEndExclusive->format('Y-m-d'),
        $ttl,
        null
    );

    $allProjectPostenRows = is_array($rangeFinance['projectposten_rows'] ?? null) ? $rangeFinance['projectposten_rows'] : [];
    $hasAnyProjectPostenInWeek = $allProjectPostenRows !== [];
    $extractedKeys = bc_fetch_extract_workorder_keys_from_projectposten_rows($allProjectPostenRows);
    $pairs = $extractedKeys['pairs'];
    $financeKeyByPair = $extractedKeys['finance_key_by_pair'];
    $pairKeysInPosten = $extractedKeys['pair_keys_in_posten'];

    if (!$hasAnyProjectPostenInWeek) {
        $workorders = [];
        $projectPostenRows = [];
        $hasProjectPostenForCostCenter = false;
        $onlyClosedCached = false;
        $builtRows = ['rows' => [], 'row_keys' => []];
        $rowKeys = [];
        $rangeFinance = [
            'project_totals_by_job' => [],
            'workorder_totals_by_number' => [],
            'workorder_totals_by_project_and_number' => [],
            'projectposten_rows_by_project' => [],
            'projectposten_rows_by_project_and_workorder' => [],
            'import_sap_workorder_rows' => [],
            'projectposten_rows' => [],
            'project_numbers' => [],
        ];
        $invoiceDetailsById = [];
        $projectInvoiceIdsByJob = [];
        $projectInvoicedTotalByJob = [];
        $cacheState = bc_fetch_build_workorder_state_cache(
            $workorders,
            $financeKeyByPair,
            $pairKeysInPosten,
            $cachedState
        );

        $monthScan = demeter_month_scan_update_after_load(
            $normalizedYearWeek,
            false,
            false,
            $rowKeys,
            $monthScan
        );

        $displayRowsByKey = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
        $displayRowsByKey = demeter_merge_display_rows_for_month_chunk(
            $displayRowsByKey,
            [],
            $normalizedYearWeek === $currentCalendarWeek,
            []
        );

        demeter_workorder_state_cache_save($company, $costCenter, $cacheState, $monthScan);
        demeter_workorder_state_cache_save_display_rows($company, $costCenter, $displayRowsByKey);

        $nextWeek = demeter_previous_iso_year_week($normalizedYearWeek);

        return [
            'skipped' => false,
            'year_week' => $normalizedYearWeek,
            'year_month' => $normalizedYearWeek,
            'has_projectposten' => false,
            'empty' => true,
            'only_closed_cached' => false,
            'row_keys' => $rowKeys,
            'rows' => [],
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
            'finance_key_by_pair' => $financeKeyByPair,
            'load_meta' => [
                'cost_center' => $costCenter,
                'year_week' => $normalizedYearWeek,
                'year_month' => $normalizedYearWeek,
                'incremental' => $isIncrementalRun,
                'from_cache_count' => 0,
                'updated_from_bc_count' => 0,
                'skipped_cached' => false,
            ],
        ];
    }

    $fetchPlan = bc_fetch_resolve_workorder_fetch_plan($pairs, $pairKeysInPosten, $cachedState, $forceFull);
    $fetchPairs = $fetchPlan['fetch_pairs'];
    $cachedWorkorderRows = $fetchPlan['use_cached_rows'];
    $statusCheckCount = 0;
    $statusClosedCount = 0;

    if (!$forceFull && $fetchPlan['status_check_pairs'] !== []) {
        $advanceProgress('Open werkorders controleren');
        $staleResult = bc_fetch_process_stale_workorders_via_status_check(
            $company,
            $fetchPlan['status_check_pairs'],
            $cachedWorkorders,
            $auth,
            $ttl
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

    $advanceProgress($isIncrementalRun ? 'Werkorders (open)' : 'Werkorders');
    $fetchedWorkorders = bc_fetch_workorders_by_job_task_pairs($company, $fetchPairs, $auth, $ttl);
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

    if ($projectNumbers !== []) {
        $invoiceData = bc_fetch_run_column('invoices', $company, $normalizedYearWeek, $projectNumbers, $auth, $ttl);
        $invoiceDetails = is_array($invoiceData['invoice_details_by_id'] ?? null)
            ? $invoiceData['invoice_details_by_id']
            : [];
        foreach ($invoiceDetails as $invoiceId => $details) {
            if (!is_string($invoiceId) || $invoiceId === '' || !is_array($details)) {
                continue;
            }

            if (!isset($invoiceDetailsById[$invoiceId])) {
                $invoiceDetailsById[$invoiceId] = $details;
            }
        }

        $invoiceProjects = is_array($invoiceData['by_project'] ?? null) ? $invoiceData['by_project'] : [];
        foreach ($invoiceProjects as $normalizedProjectNo => $values) {
            if (!is_array($values)) {
                continue;
            }

            $invoiceIds = is_array($values['invoice_ids'] ?? null) ? $values['invoice_ids'] : [];
            $projectInvoiceIdsByJob[$normalizedProjectNo] = array_values(array_unique(array_filter(array_map('strval', $invoiceIds), static function (string $invoiceId): bool {
                return trim($invoiceId) !== '';
            })));
            $projectInvoicedTotalByJob[$normalizedProjectNo] = finance_to_float($values['invoiced_total'] ?? 0.0);
        }
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

    $rowKeys = $builtRows['row_keys'];
    $hasWeekRows = $builtRows['rows'] !== [];

    $monthScan = demeter_month_scan_update_after_load(
        $normalizedYearWeek,
        $hasWeekRows,
        $onlyClosedCached,
        $rowKeys,
        $monthScan
    );

    $displayRowsByKey = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
    $displayRowsByKey = demeter_merge_display_rows_for_month_chunk(
        $displayRowsByKey,
        $builtRows['rows'],
        $normalizedYearWeek === $currentCalendarWeek,
        is_array($rangeFinance['project_totals_by_job'] ?? null) ? $rangeFinance['project_totals_by_job'] : []
    );

    demeter_workorder_state_cache_save($company, $costCenter, $cacheState, $monthScan);
    demeter_workorder_state_cache_save_display_rows($company, $costCenter, $displayRowsByKey);

    $nextWeek = demeter_previous_iso_year_week($normalizedYearWeek);

    return [
        'skipped' => false,
        'year_week' => $normalizedYearWeek,
        'year_month' => $normalizedYearWeek,
        'has_projectposten' => $hasProjectPostenForCostCenter,
        'empty' => !$hasWeekRows,
        'only_closed_cached' => $onlyClosedCached,
        'row_keys' => $rowKeys,
        'rows' => $builtRows['rows'],
        'month_scan' => $monthScan,
        'next_week' => $nextWeek,
        'next_month' => $nextWeek,
        'should_continue' => demeter_month_scan_should_continue($monthScan, $nextWeek),
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
        'load_meta' => [
            'cost_center' => $costCenter,
            'year_week' => $normalizedYearWeek,
            'year_month' => $normalizedYearWeek,
            'incremental' => $isIncrementalRun,
            'from_cache_count' => count($cachedWorkorderRows) - $statusClosedCount,
            'updated_from_bc_count' => count($fetchedWorkorders),
            'status_check_count' => $statusCheckCount,
            'status_closed_via_check_count' => $statusClosedCount,
            'skipped_cached' => false,
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
