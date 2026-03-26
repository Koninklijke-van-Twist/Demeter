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
 * Haalt projecttotalen op via de centrale finance-authoriteit.
 */
function bc_fetch_column_project_finance(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $dictionary = bc_fetch_seed_project_dictionary($projectNumbers);
    if ($projectNumbers === []) {
        return [
            'column' => 'project_finance',
            'by_project' => $dictionary,
            'warning' => null,
        ];
    }

    try {
        $financeService = new ProjectFinanceService($company);
        $financeData = $financeService->collectProjectFinanceForProjects($projectNumbers, $ttl);
    } catch (Throwable $e) {
        return [
            'column' => 'project_finance',
            'by_project' => $dictionary,
            'warning' => $e->getMessage(),
        ];
    }

    $projectTotals = is_array($financeData['project_totals_by_job'] ?? null)
        ? $financeData['project_totals_by_job']
        : [];

    foreach ($projectTotals as $normalizedProjectNo => $totals) {
        if (!is_array($totals)) {
            continue;
        }

        if (!isset($dictionary[$normalizedProjectNo])) {
            $dictionary[$normalizedProjectNo] = [];
        }

        $dictionary[$normalizedProjectNo]['totals'] = [
            'costs' => finance_to_float($totals['costs'] ?? 0.0),
            'revenue' => finance_to_float($totals['revenue'] ?? 0.0),
            'resultaat' => finance_to_float($totals['resultaat'] ?? 0.0),
        ];
    }

    return [
        'column' => 'project_finance',
        'by_project' => $dictionary,
        'warning' => null,
    ];
}