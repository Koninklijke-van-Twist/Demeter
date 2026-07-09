<?php

/**
 * Helpers voor kostenplaats/afdeling filtering.
 */

/**
 * Normaliseert een kostenplaatswaarde voor vergelijking en opslag.
 */
function bc_fetch_normalize_cost_center(string $costCenter): string
{
    return trim($costCenter);
}

/**
 * Controleert of een ProjectPosten-rij bij de gekozen kostenplaats hoort.
 */
function bc_fetch_row_matches_cost_center(array $row, string $costCenter): bool
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        return true;
    }

    $candidates = [
        trim((string) ($row['Global_Dimension_1_Code'] ?? '')),
        trim((string) ($row['LVS_Global_Dimension_1_Code'] ?? '')),
    ];

    return in_array($normalized, $candidates, true);
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
