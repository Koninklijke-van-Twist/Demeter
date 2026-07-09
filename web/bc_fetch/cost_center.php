<?php

/**
 * Helpers voor kostenplaats/afdeling filtering op ProjectPosten.
 */

/**
 * Normaliseert een kostenplaatswaarde voor vergelijking en opslag.
 */
function bc_fetch_normalize_cost_center(string $costCenter): string
{
    return trim($costCenter);
}

/**
 * Bouwt een OData-filter voor kostenplaats op ProjectPosten.
 */
function bc_fetch_cost_center_odata_filter(string $costCenter): string
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        throw new InvalidArgumentException('Kostenplaats is verplicht.');
    }

    $escaped = str_replace("'", "''", $normalized);

    return "(LVS_Global_Dimension_1_Code eq '" . $escaped . "' or Global_Dimension_1_Code eq '" . $escaped . "')";
}

/**
 * Voegt een kostenplaatsfilter toe aan een bestaand OData-filter.
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

    return '(' . $base . ') and (' . $additional . ')';
}
