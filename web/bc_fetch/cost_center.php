<?php

/**
 * Helpers voor kostenplaats/afdeling filtering.
 */

/**
 * Waarde voor "geen kostenplaats" in de UI.
 */
function bc_fetch_cost_center_none_value(): string
{
    return '__none__';
}

/**
 * Normaliseert een kostenplaatswaarde voor vergelijking en opslag.
 */
function bc_fetch_normalize_cost_center(string $costCenter): string
{
    return trim($costCenter);
}

/**
 * Normaliseert een kostenplaats voor vergelijking (o.a. "05" = "5").
 */
function bc_fetch_normalize_cost_center_for_match(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (ctype_digit($trimmed)) {
        $withoutLeadingZeros = ltrim($trimmed, '0');

        return $withoutLeadingZeros === '' ? '0' : $withoutLeadingZeros;
    }

    return $trimmed;
}

/**
 * Haalt de numerieke kostenplaatscode uit een BC-dimensiewaarde.
 *
 * Ondersteunt "50", "050" en "50 - Afdelingsnaam".
 */
function bc_fetch_extract_cost_center_code(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^(\d+)/', $trimmed, $matches) === 1) {
        return bc_fetch_normalize_cost_center_for_match($matches[1]);
    }

    return bc_fetch_normalize_cost_center_for_match($trimmed);
}

/**
 * Vergelijkt twee kostenplaatswaarden met numerieke normalisatie.
 */
function bc_fetch_cost_centers_match(string $left, string $right): bool
{
    return bc_fetch_extract_cost_center_code($left) === bc_fetch_extract_cost_center_code($right);
}

/**
 * Controleert of een ProjectPosten-rij bij de gekozen kostenplaats hoort.
 *
 * ProjectPosten heeft vaak geen of onvolledige dimensievelden; lege velden worden
 * niet uitgesloten zodat filtering later op werkorder-niveau kan plaatsvinden.
 */
function bc_fetch_row_matches_cost_center(array $row, string $costCenter): bool
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '' || $normalized === bc_fetch_cost_center_none_value()) {
        return true;
    }

    $candidates = [
        trim((string) ($row['Global_Dimension_1_Code'] ?? '')),
        trim((string) ($row['LVS_Global_Dimension_1_Code'] ?? '')),
    ];

    $hasPopulatedDimension = false;
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $hasPopulatedDimension = true;
        if (bc_fetch_cost_centers_match($candidate, $normalized)) {
            return true;
        }
    }

    // Lege dimensies: meenemen voor latere filtering via werkorder.
    return !$hasPopulatedDimension;
}

/**
 * Controleert of een werkorder bij de gekozen kostenplaats hoort (Job_Dimension_1_Value).
 */
function bc_fetch_workorder_matches_cost_center(array $workorder, string $costCenter): bool
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        return true;
    }

    $workorderCostCenter = trim((string) ($workorder['Job_Dimension_1_Value'] ?? ''));
    if ($normalized === bc_fetch_cost_center_none_value()) {
        return $workorderCostCenter === '';
    }

    return bc_fetch_cost_centers_match($workorderCostCenter, $normalized);
}

/**
 * @param list<array> $workorders
 * @return list<array>
 */
function bc_fetch_filter_workorders_by_cost_center(array $workorders, string $costCenter): array
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        return $workorders;
    }

    return array_values(array_filter($workorders, static function ($workorder) use ($normalized): bool {
        return is_array($workorder) && bc_fetch_workorder_matches_cost_center($workorder, $normalized);
    }));
}

/**
 * @param list<array> $rows
 * @return array<string, bool>
 */
function bc_fetch_pair_keys_from_projectposten_rows(array $rows, string $costCenter): array
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    $pairKeys = [];

    if (!function_exists('demeter_workorder_pair_key')) {
        require_once __DIR__ . '/workorder_state_cache.php';
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ($normalized !== '' && !bc_fetch_row_matches_cost_center($row, $normalized)) {
            continue;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $pairKeys[demeter_workorder_pair_key($jobNo, $jobTaskNo)] = true;
    }

    return $pairKeys;
}

/**
 * Filtert werkorders op kostenplaats via ProjectPosten-dimensie en/of Job_Dimension_1_Value.
 *
 * @param list<array> $workorders
 * @param list<array> $allPostenRows
 * @return list<array>
 */
function bc_fetch_filter_workorders_for_cost_center(array $workorders, array $allPostenRows, string $costCenter): array
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        return $workorders;
    }

    $pairKeysFromPosten = bc_fetch_pair_keys_from_projectposten_rows($allPostenRows, $normalized);

    return array_values(array_filter($workorders, static function ($workorder) use ($normalized, $pairKeysFromPosten): bool {
        if (!is_array($workorder)) {
            return false;
        }

        $jobNo = trim((string) ($workorder['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($workorder['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            return false;
        }

        if (!function_exists('demeter_workorder_pair_key')) {
            require_once __DIR__ . '/workorder_state_cache.php';
        }

        $pairKey = demeter_workorder_pair_key($jobNo, $jobTaskNo);
        if (isset($pairKeysFromPosten[$pairKey])) {
            return true;
        }

        return bc_fetch_workorder_matches_cost_center($workorder, $normalized);
    }));
}

/**
 * @param list<array> $workorders
 * @return array<string, bool>
 */
function bc_fetch_pair_keys_from_workorders(array $workorders): array
{
    $pairKeys = [];

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $jobNo = trim((string) ($workorder['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($workorder['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        if (!function_exists('demeter_workorder_pair_key')) {
            require_once __DIR__ . '/workorder_state_cache.php';
        }

        $pairKeys[demeter_workorder_pair_key($jobNo, $jobTaskNo)] = true;
    }

    return $pairKeys;
}

/**
 * Filtert ProjectPosten-rijen op job/task-paren die bij de opgehaalde werkorders horen.
 *
 * @param list<array> $rows
 * @param array<string, bool> $allowedPairKeys
 * @return list<array>
 */
function bc_fetch_filter_projectposten_rows_by_pair_keys(array $rows, array $allowedPairKeys): array
{
    if ($allowedPairKeys === []) {
        return [];
    }

    if (!function_exists('demeter_workorder_pair_key')) {
        require_once __DIR__ . '/workorder_state_cache.php';
    }

    return array_values(array_filter($rows, static function ($row) use ($allowedPairKeys): bool {
        if (!is_array($row)) {
            return false;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            return false;
        }

        return isset($allowedPairKeys[demeter_workorder_pair_key($jobNo, $jobTaskNo)]);
    }));
}

/**
 * Filtert ProjectPosten-rijen op kostenplaats in PHP (BC OData ondersteunt complexe filters niet altijd).
 *
 * @param list<array> $rows
 * @return list<array>
 */
function bc_fetch_filter_rows_by_cost_center(array $rows, string $costCenter): array
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        return $rows;
    }

    return array_values(array_filter($rows, static function ($row) use ($normalized): bool {
        return is_array($row) && bc_fetch_row_matches_cost_center($row, $normalized);
    }));
}

/**
 * @deprecated BC OData ondersteunt deze OR-filter vaak niet; gebruik PHP-filter na ophalen.
 */
function bc_fetch_cost_center_odata_filter(string $costCenter): string
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        throw new InvalidArgumentException('Kostenplaats is verplicht.');
    }

    $escaped = str_replace("'", "''", $normalized);

    return "Global_Dimension_1_Code eq '" . $escaped . "'";
}

/**
 * Voegt een OData-filter toe aan een bestaand OData-filter.
 */
function bc_fetch_append_odata_filter(string $baseFilter, string $additionalFilter): string
{
    $base = trim($baseFilter);
    $additional = trim($additionalFilter);
    if ($additional === '') {
        return $base;
    }

    if ($base === '') {
        return $additional;
    }

    return $base . ' and ' . $additional;
}
