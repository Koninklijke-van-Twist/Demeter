<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../project_finance.php';

/**
 * Functies
 */
/**
 * Haalt werkordertotalen op via de centrale finance-authoriteit.
 */
function bc_fetch_column_workorder_finance(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $workorderNumbers = [];
    foreach ($projectNumbers as $workorderNumber) {
        $workorderNumberText = trim((string) $workorderNumber);
        if ($workorderNumberText === '') {
            continue;
        }

        $workorderNumbers[] = $workorderNumberText;
    }

    if ($workorderNumbers === []) {
        return [
            'column' => 'workorder_finance',
            'by_workorder' => [],
            'warning' => null,
        ];
    }

    try {
        $financeService = new ProjectFinanceService($company);
        $financeData = $financeService->collectWorkorderFinanceForWorkorders($workorderNumbers, $ttl);
    } catch (Throwable $e) {
        return [
            'column' => 'workorder_finance',
            'by_workorder' => [],
            'warning' => $e->getMessage(),
        ];
    }

    $totalsByWorkorder = is_array($financeData['workorder_totals_by_number'] ?? null)
        ? $financeData['workorder_totals_by_number']
        : [];

    return [
        'column' => 'workorder_finance',
        'by_workorder' => $totalsByWorkorder,
        'warning' => null,
    ];
}