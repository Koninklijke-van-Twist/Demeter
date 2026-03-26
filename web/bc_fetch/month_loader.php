<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/registry.php';

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
    $projectNumbers = [];
    $seenProjectNumbers = [];
    $workorderNumbers = [];
    $seenWorkorderNumbers = [];
    $projectTotalsByJob = [];
    $workorderTotalsByNumber = [];
    $invoiceDetailsById = [];
    $projectInvoiceIdsByJob = [];
    $projectInvoicedTotalByJob = [];
    $totalMonths = count($ranges);
    $totalProgressSteps = $totalMonths;
    $monthIndex = 0;

    foreach ($ranges as $range) {
        $rangeFrom = $range['from'] ?? null;
        if (!$rangeFrom instanceof DateTimeImmutable) {
            continue;
        }

        $monthIndex++;
        $yearMonth = $rangeFrom->format('Y-m');
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
            $projectNumbers[] = $projectNumberText;
            $newProjectNumbers[] = $projectNumberText;
        }

        $workorderData = bc_fetch_run_column('workorders', $company, $yearMonth, $projectNumbersForMonth, $auth, $ttl);
        $workorderRows = is_array($workorderData['all_rows'] ?? null) ? $workorderData['all_rows'] : [];
        $newWorkorderNumbers = [];
        foreach ($workorderRows as $workorderRow) {
            if (!is_array($workorderRow)) {
                continue;
            }

            $workorderNumber = trim((string) ($workorderRow['Job_Task_No'] ?? ''));
            if ($workorderNumber !== '' && !isset($seenWorkorderNumbers[$workorderNumber])) {
                $seenWorkorderNumbers[$workorderNumber] = true;
                $workorderNumbers[] = $workorderNumber;
                $newWorkorderNumbers[] = $workorderNumber;
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
            $projectFinanceData = bc_fetch_run_column('project_finance', $company, $yearMonth, $newProjectNumbers, $auth, $ttl);
            $projectFinanceByProject = is_array($projectFinanceData['by_project'] ?? null)
                ? $projectFinanceData['by_project']
                : [];

            foreach ($projectFinanceByProject as $normalizedProjectNo => $values) {
                if (!is_array($values)) {
                    continue;
                }

                $totals = is_array($values['totals'] ?? null) ? $values['totals'] : [];
                $costs = finance_to_float($totals['costs'] ?? 0.0);
                $revenue = finance_to_float($totals['revenue'] ?? 0.0);
                $projectTotalsByJob[$normalizedProjectNo] = [
                    'costs' => $costs,
                    'revenue' => $revenue,
                    'resultaat' => finance_calculate_result($revenue, $costs),
                ];
            }

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

        if ($newWorkorderNumbers !== []) {
            $workorderFinanceData = bc_fetch_run_column('workorder_finance', $company, $yearMonth, $newWorkorderNumbers, $auth, $ttl);
            $workorderTotals = is_array($workorderFinanceData['by_workorder'] ?? null)
                ? $workorderFinanceData['by_workorder']
                : [];
            foreach ($workorderTotals as $normalizedWorkorderNo => $totals) {
                if (!is_string($normalizedWorkorderNo) || $normalizedWorkorderNo === '' || !is_array($totals)) {
                    continue;
                }

                $workorderTotalsByNumber[$normalizedWorkorderNo] = [
                    'costs' => finance_to_float($totals['costs'] ?? 0.0),
                    'revenue' => finance_to_float($totals['revenue'] ?? 0.0),
                    'resultaat' => finance_to_float($totals['resultaat'] ?? 0.0),
                ];
            }
        }
    }

    return [
        'workorders' => $workorders,
        'project_totals_by_job' => $projectTotalsByJob,
        'workorder_totals_by_number' => $workorderTotalsByNumber,
        'invoice_details_by_id' => $invoiceDetailsById,
        'project_invoice_ids_by_job' => $projectInvoiceIdsByJob,
        'project_invoiced_total_by_job' => $projectInvoicedTotalByJob,
    ];
}
