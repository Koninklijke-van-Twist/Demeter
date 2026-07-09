<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/cost_center.php';
require_once __DIR__ . '/workorder_state_cache.php';
require_once __DIR__ . '/projectposten_workorders.php';
require_once __DIR__ . '/../project_finance.php';

/**
 * Functies
 */
/**
 * Laadt BC-bronnen voor het werkorderoverzicht op basis van ProjectPosten (gefilterd op kostenplaats).
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
    $costCenter = bc_fetch_normalize_cost_center((string) ($options['cost_center'] ?? ''));
    if ($costCenter === '') {
        throw new InvalidArgumentException('Kies eerst een kostenplaats voordat gegevens worden opgehaald.');
    }

    $fromMonth = trim((string) ($options['from_month'] ?? ''));
    $toMonth = trim((string) ($options['to_month'] ?? ''));
    $forceFull = !empty($options['force_full']);

    $rangeStart = null;
    $rangeEndExclusive = null;
    foreach ($ranges as $range) {
        $rangeFrom = $range['from'] ?? null;
        if (!$rangeFrom instanceof DateTimeImmutable) {
            continue;
        }

        $rangeTo = $range['to'] ?? null;
        if (!$rangeTo instanceof DateTimeImmutable) {
            $rangeTo = $rangeFrom->modify('+1 month');
        }

        if (!$rangeStart instanceof DateTimeImmutable || $rangeFrom < $rangeStart) {
            $rangeStart = $rangeFrom;
        }
        if (!$rangeEndExclusive instanceof DateTimeImmutable || $rangeTo > $rangeEndExclusive) {
            $rangeEndExclusive = $rangeTo;
        }
    }

    if (!$rangeStart instanceof DateTimeImmutable || !$rangeEndExclusive instanceof DateTimeImmutable) {
        throw new RuntimeException('Geen geldige maandrange beschikbaar.');
    }

    if ($fromMonth === '') {
        $fromMonth = $rangeStart->format('Y-m');
    }
    if ($toMonth === '') {
        $toMonth = $rangeEndExclusive->modify('-1 day')->format('Y-m');
    }

    $totalProgressSteps = 4;
    $progressStep = 0;
    $advanceProgress = static function (string $label) use (&$progressStep, $progressToken, $totalProgressSteps): void {
        $progressStep++;
        if (is_string($progressToken) && $progressToken !== '' && function_exists('odata_load_progress_advance_month')) {
            odata_load_progress_advance_month($progressToken, $progressStep, $totalProgressSteps, $label);
        }
    };

    $cachedState = $forceFull ? null : demeter_workorder_state_cache_load($company, $costCenter, $fromMonth, $toMonth);
    $isIncrementalRun = $cachedState !== null;

    $advanceProgress('ProjectPosten');
    $financeService = new ProjectFinanceService($company);
    $rangeFinance = $financeService->collectProjectAndWorkorderFinanceFromProjectPostenRange(
        $rangeStart->format('Y-m-d'),
        $rangeEndExclusive->format('Y-m-d'),
        $ttl,
        $costCenter
    );

    $projectPostenRows = is_array($rangeFinance['projectposten_rows'] ?? null) ? $rangeFinance['projectposten_rows'] : [];
    $extractedKeys = bc_fetch_extract_workorder_keys_from_projectposten_rows($projectPostenRows);
    $pairs = $extractedKeys['pairs'];
    $financeKeyByPair = $extractedKeys['finance_key_by_pair'];
    $pairKeysInPosten = $extractedKeys['pair_keys_in_posten'];

    $fetchPlan = bc_fetch_resolve_workorder_fetch_plan($pairs, $pairKeysInPosten, $cachedState, $forceFull);
    $fetchPairs = array_merge($fetchPlan['fetch_pairs'], $fetchPlan['stale_pairs']);

    $advanceProgress($isIncrementalRun ? 'Werkorders (open)' : 'Werkorders');
    $fetchedWorkorders = bc_fetch_workorders_by_job_task_pairs($company, $fetchPairs, $auth, $ttl);
    $workorders = bc_fetch_merge_workorder_rows($fetchPlan['use_cached_closed_rows'], $fetchedWorkorders);

    $advanceProgress($fetchPlan['stale_pairs'] !== [] ? 'Afgesloten werkorders controleren' : 'Werkorders samenvoegen');

    $seenProjectNumbers = [];
    $projectNumbers = is_array($rangeFinance['project_numbers'] ?? null) ? $rangeFinance['project_numbers'] : [];
    foreach ($projectNumbers as $projectNumber) {
        $projectNumberText = trim((string) $projectNumber);
        if ($projectNumberText !== '') {
            $seenProjectNumbers[$projectNumberText] = true;
        }
    }

    $invoiceDetailsById = [];
    $projectInvoiceIdsByJob = [];
    $projectInvoicedTotalByJob = [];

    if ($projectNumbers !== []) {
        $invoiceData = bc_fetch_run_column('invoices', $company, $fromMonth, $projectNumbers, $auth, $ttl);
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
    $workorders = bc_fetch_merge_workorder_rows($workorders, $importSapWorkorderRows);

    $cacheState = bc_fetch_build_workorder_state_cache(
        $workorders,
        $financeKeyByPair,
        $pairKeysInPosten,
        $cachedState
    );
    demeter_workorder_state_cache_save($company, $costCenter, $fromMonth, $toMonth, $cacheState);

    return [
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
            'incremental' => $isIncrementalRun,
            'fetched_workorder_count' => count($fetchedWorkorders),
            'cached_closed_count' => count($fetchPlan['use_cached_closed_rows']),
            'stale_checked_count' => count($fetchPlan['stale_pairs']),
        ],
    ];
}
