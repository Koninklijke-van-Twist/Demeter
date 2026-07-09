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
 * Bouwt een datumrange voor één kalendermaand.
 *
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
 * Laadt één maand-chunk voor het werkorderoverzicht.
 *
 * @param array{cost_center?: string, force_full?: bool, partial_to_today?: bool, skip_if_cached?: bool} $options
 */
function bc_fetch_load_workorder_month_chunk(
    string $company,
    string $yearMonth,
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

    $monthRange = bc_fetch_month_date_range($yearMonth, $partialToToday);
    $rangeStart = $monthRange['from'];
    $rangeEndExclusive = $monthRange['to'];
    $normalizedYearMonth = $monthRange['year_month'];
    $currentCalendarMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m');
    if ($normalizedYearMonth === $currentCalendarMonth) {
        $skipIfCached = false;
    }

    $cachedState = $forceFull ? null : demeter_workorder_state_cache_load($company, $costCenter);
    $monthScan = is_array($cachedState['month_scan'] ?? null) ? $cachedState['month_scan'] : demeter_workorder_month_scan_defaults();

    if ($skipIfCached && !$forceFull && demeter_month_scan_can_skip($normalizedYearMonth, $monthScan)) {
        $monthMeta = is_array($monthScan['months'][$normalizedYearMonth] ?? null) ? $monthScan['months'][$normalizedYearMonth] : [];
        $nextMonth = demeter_previous_year_month($normalizedYearMonth);

        return [
            'skipped' => true,
            'year_month' => $normalizedYearMonth,
            'has_projectposten' => !empty($monthMeta['has_projectposten']),
            'empty' => !empty($monthMeta['empty']),
            'only_closed_cached' => !empty($monthMeta['only_closed_cached']),
            'row_keys' => demeter_month_scan_expected_row_keys($monthScan, $normalizedYearMonth),
            'month_scan' => $monthScan,
            'next_month' => $nextMonth,
            'should_continue' => demeter_month_scan_should_continue($monthScan, $nextMonth),
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
                'year_month' => $normalizedYearMonth,
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

    $isIncrementalRun = $cachedState !== null;
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
    $extractedKeys = bc_fetch_extract_workorder_keys_from_projectposten_rows($allProjectPostenRows);
    $pairs = $extractedKeys['pairs'];
    $financeKeyByPair = $extractedKeys['finance_key_by_pair'];
    $pairKeysInPosten = $extractedKeys['pair_keys_in_posten'];

    $fetchPlan = bc_fetch_resolve_workorder_fetch_plan($pairs, $pairKeysInPosten, $cachedState, $forceFull);
    $fetchPairs = array_merge($fetchPlan['fetch_pairs'], $fetchPlan['stale_pairs']);

    $advanceProgress($isIncrementalRun ? 'Werkorders (open)' : 'Werkorders');
    $fetchedWorkorders = bc_fetch_workorders_by_job_task_pairs($company, $fetchPairs, $auth, $ttl);
    $cachedWorkorderRows = $fetchPlan['use_cached_rows'];
    $workorders = bc_fetch_merge_workorder_rows($cachedWorkorderRows, $fetchedWorkorders);

    if ($costCenter !== '') {
        $workorders = bc_fetch_filter_workorders_for_cost_center($workorders, $allProjectPostenRows, $costCenter);
        $allowedPairKeys = bc_fetch_pair_keys_from_workorders($workorders);
        $allowedPairKeys = array_merge(
            $allowedPairKeys,
            bc_fetch_pair_keys_from_projectposten_rows($allProjectPostenRows, $costCenter)
        );
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

    $advanceProgress($fetchPlan['stale_pairs'] !== [] ? 'Afgesloten werkorders controleren' : 'Werkorders samenvoegen');

    $projectNumbers = is_array($rangeFinance['project_numbers'] ?? null) ? $rangeFinance['project_numbers'] : [];

    $invoiceDetailsById = [];
    $projectInvoiceIdsByJob = [];
    $projectInvoicedTotalByJob = [];

    if ($projectNumbers !== []) {
        $invoiceData = bc_fetch_run_column('invoices', $company, $normalizedYearMonth, $projectNumbers, $auth, $ttl);
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
    $pairsForMonthScan = is_array($extractedKeys['pairs'] ?? null) ? $extractedKeys['pairs'] : [];
    if ($rowKeys === [] && $pairsForMonthScan !== []) {
        $rowKeys = demeter_row_keys_from_pairs($pairsForMonthScan, $financeKeyByPair);
    }

    $monthScan = demeter_month_scan_update_after_load(
        $normalizedYearMonth,
        $builtRows['rows'] !== [],
        $onlyClosedCached,
        $rowKeys,
        $monthScan
    );

    $displayRowsByKey = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
    $displayRowsByKey = demeter_merge_display_rows_for_month_chunk(
        $displayRowsByKey,
        $builtRows['rows'],
        $normalizedYearMonth === $currentCalendarMonth,
        is_array($rangeFinance['project_totals_by_job'] ?? null) ? $rangeFinance['project_totals_by_job'] : []
    );

    demeter_workorder_state_cache_save($company, $costCenter, $cacheState, $monthScan);
    demeter_workorder_state_cache_save_display_rows($company, $costCenter, $displayRowsByKey);

    $nextMonth = demeter_previous_year_month($normalizedYearMonth);

    return [
        'skipped' => false,
        'year_month' => $normalizedYearMonth,
        'has_projectposten' => $hasProjectPostenForCostCenter,
        'empty' => $builtRows['rows'] === [],
        'only_closed_cached' => $onlyClosedCached,
        'row_keys' => $rowKeys,
        'rows' => $builtRows['rows'],
        'month_scan' => $monthScan,
        'next_month' => $nextMonth,
        'should_continue' => demeter_month_scan_should_continue($monthScan, $nextMonth),
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
            'year_month' => $normalizedYearMonth,
            'incremental' => $isIncrementalRun,
            'from_cache_count' => count($fetchPlan['use_cached_rows']),
            'updated_from_bc_count' => count($fetchedWorkorders),
            'skipped_cached' => false,
        ],
    ];
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

    $todayMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m');
    $loadMeta = [];

    foreach ($ranges as $range) {
        $rangeFrom = $range['from'] ?? null;
        if (!$rangeFrom instanceof DateTimeImmutable) {
            continue;
        }

        $yearMonth = $rangeFrom->format('Y-m');
        $chunkOptions = $options;
        $chunkOptions['partial_to_today'] = $yearMonth === $todayMonth;
        $chunkOptions['skip_if_cached'] = false;

        $chunk = bc_fetch_load_workorder_month_chunk($company, $yearMonth, $auth, $ttl, $progressToken, $chunkOptions);
        if (!empty($chunk['skipped'])) {
            continue;
        }

        $aggregate = demeter_merge_overview_chunks($aggregate, $chunk);
        $loadMeta = is_array($chunk['load_meta'] ?? null) ? $chunk['load_meta'] : $loadMeta;
    }

    $aggregate['load_meta'] = $loadMeta;

    return $aggregate;
}
