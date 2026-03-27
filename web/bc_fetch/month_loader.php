<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/../project_finance.php';

/**
 * Functies
 */
/**
 * Laadt alle BC-bronnen die het werkorderoverzicht nodig heeft over meerdere maanden.
 */
function bc_fetch_load_workorder_overview_data(string $company, array $ranges, array $auth, int $ttl, ?string $progressToken = null): array
{
    $workorders = [];
    $seenWorkorderRows = [];
    $seenProjectNumbers = [];
    $seenWorkorderNumbers = [];
    $projectTotalsByJob = [];
    $workorderTotalsByNumber = [];
    $workorderTotalsByProjectAndNumber = [];
    $invoiceDetailsById = [];
    $projectInvoiceIdsByJob = [];
    $projectInvoicedTotalByJob = [];
    $totalMonths = count($ranges);
    $totalProgressSteps = $totalMonths;
    $monthIndex = 0;
    $rangeStart = null;
    $rangeEndExclusive = null;

    foreach ($ranges as $range) {
        $rangeFrom = $range['from'] ?? null;
        if (!$rangeFrom instanceof DateTimeImmutable) {
            continue;
        }

        $monthIndex++;
        $yearMonth = $rangeFrom->format('Y-m');
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
        if (is_string($progressToken) && $progressToken !== '' && function_exists('odata_load_progress_advance_month')) {
            odata_load_progress_advance_month($progressToken, $monthIndex, $totalProgressSteps, $yearMonth);
        }

        $projectNumbersForMonth = bc_fetch_project_numbers_for_month($company, $yearMonth, $auth, $ttl);
        $newProjectNumbers = [];
        foreach ($projectNumbersForMonth as $projectNumber) {
            $projectNumberText = trim((string) $projectNumber);
            if ($projectNumberText === '' || isset($seenProjectNumbers[$projectNumberText])) {
                continue;
            }

            $seenProjectNumbers[$projectNumberText] = true;
            $newProjectNumbers[] = $projectNumberText;
        }

        $workorderData = bc_fetch_run_column('workorders', $company, $yearMonth, $projectNumbersForMonth, $auth, $ttl);
        $workorderRows = is_array($workorderData['all_rows'] ?? null) ? $workorderData['all_rows'] : [];
        foreach ($workorderRows as $workorderRow) {
            if (!is_array($workorderRow)) {
                continue;
            }

            $workorderNumber = trim((string) ($workorderRow['Job_Task_No'] ?? ''));
            if ($workorderNumber !== '' && !isset($seenWorkorderNumbers[$workorderNumber])) {
                $seenWorkorderNumbers[$workorderNumber] = true;
            }

            $rowKey = implode('|', [
                (string) ($workorderRow['No'] ?? ''),
                (string) ($workorderRow['Job_No'] ?? ''),
                (string) ($workorderRow['Job_Task_No'] ?? ''),
                (string) ($workorderRow['Start_Date'] ?? ''),
            ]);

            if (isset($seenWorkorderRows[$rowKey])) {
                continue;
            }

            $seenWorkorderRows[$rowKey] = true;
            $workorders[] = $workorderRow;
        }

        if ($newProjectNumbers !== []) {
            $invoiceData = bc_fetch_run_column('invoices', $company, $yearMonth, $newProjectNumbers, $auth, $ttl);
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

    }

    if ($rangeStart instanceof DateTimeImmutable && $rangeEndExclusive instanceof DateTimeImmutable) {
        $financeService = new ProjectFinanceService($company);
        $rangeFinance = $financeService->collectProjectAndWorkorderFinanceFromProjectPostenRange(
            $rangeStart->format('Y-m-d'),
            $rangeEndExclusive->format('Y-m-d'),
            $ttl
        );

        $projectTotalsByJob = is_array($rangeFinance['project_totals_by_job'] ?? null)
            ? $rangeFinance['project_totals_by_job']
            : [];
        $workorderTotalsByNumber = is_array($rangeFinance['workorder_totals_by_number'] ?? null)
            ? $rangeFinance['workorder_totals_by_number']
            : [];
        $workorderTotalsByProjectAndNumber = is_array($rangeFinance['workorder_totals_by_project_and_number'] ?? null)
            ? $rangeFinance['workorder_totals_by_project_and_number']
            : [];
    }

    return [
        'workorders' => $workorders,
        'project_totals_by_job' => $projectTotalsByJob,
        'workorder_totals_by_number' => $workorderTotalsByNumber,
        'workorder_totals_by_project_and_number' => $workorderTotalsByProjectAndNumber,
        'invoice_details_by_id' => $invoiceDetailsById,
        'project_invoice_ids_by_job' => $projectInvoiceIdsByJob,
        'project_invoiced_total_by_job' => $projectInvoicedTotalByJob,
    ];
}
