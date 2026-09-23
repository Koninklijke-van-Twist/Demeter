<?php

/**
 * Afdelingen/kostenplaatsen ophalen uit DimensionValueList,
 * beperkt tot Global Dimension 1 (GeneralLedgerSetup).
 */

require_once __DIR__ . '/helpers.php';

/**
 * Controleert of een dimensiewaarde een echte tekstbeschrijving heeft (geen leeg label of puur getal).
 */
function bc_fetch_dimension_value_has_text_label(string $name): bool
{
    $normalized = trim($name);
    if ($normalized === '') {
        return false;
    }

    return (bool) preg_match('/\p{L}/u', $normalized);
}

/**
 * Escapet een OData-stringliteral (enkele quotes verdubbelen).
 */
function bc_fetch_odata_quote(string $value): string
{
    return str_replace("'", "''", $value);
}

/**
 * Global Dimension 1 uit GeneralLedgerSetup (bijv. SALES_DEPARTMENT).
 */
function bc_fetch_global_dimension_1_code(string $company, array $auth, int $ttl): string
{
    $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'GeneralLedgerSetup', [
        '$select' => 'Global_Dimension_1_Code',
        '$top' => '1',
    ]);
    $rows = odata_get_all($url, $auth, $ttl);
    $row = $rows[0] ?? null;
    $code = is_array($row) ? trim((string) ($row['Global_Dimension_1_Code'] ?? '')) : '';
    if ($code === '') {
        throw new RuntimeException('GeneralLedgerSetup heeft geen Global_Dimension_1_Code.');
    }

    return $code;
}

/**
 * Houdt ongeblokkeerde Global Dimension 1-waarden met een numerieke code < 100 en een tekstnaam.
 *
 * @param list<mixed> $rows
 * @return list<array{code: string, name: string, label: string}>
 */
function bc_fetch_department_cost_center_options_from_rows(array $rows, string $dimensionCode): array
{
    $dimensionCode = trim($dimensionCode);
    $options = [];
    $seenCodes = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowDimension = trim((string) ($row['Dimension_Code'] ?? ''));
        if ($dimensionCode !== '' && $rowDimension !== '' && $rowDimension !== $dimensionCode) {
            continue;
        }

        if (!empty($row['Blocked'])) {
            continue;
        }

        $code = trim((string) ($row['Code'] ?? ''));
        if ($code === '' || !ctype_digit($code)) {
            continue;
        }

        if ((int) $code >= 100) {
            continue;
        }

        $name = trim((string) ($row['Name'] ?? ''));
        if (!bc_fetch_dimension_value_has_text_label($name)) {
            continue;
        }

        if (isset($seenCodes[$code])) {
            continue;
        }

        $seenCodes[$code] = true;
        $options[] = [
            'code' => $code,
            'name' => $name,
            'label' => $code . ' - ' . $name,
        ];
    }

    usort($options, static function (array $left, array $right): int {
        return (int) $left['code'] <=> (int) $right['code'];
    });

    return $options;
}

/**
 * Haalt afdelingen/kostenplaatsen op uit DimensionValueList voor Global Dimension 1.
 *
 * Alleen ongeblokkeerde rijen met een puur numerieke code < 100 en een tekstbeschrijving.
 * Er worden geen ontbrekende nummers aangevuld.
 *
 * @return list<array{code: string, name: string, label: string}>
 */
function bc_fetch_department_cost_center_options(string $company, array $auth, int $ttl): array
{
    $dimensionCode = bc_fetch_global_dimension_1_code($company, $auth, $ttl);
    $filter = "Dimension_Code eq '" . bc_fetch_odata_quote($dimensionCode) . "' and Blocked eq false";
    $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'DimensionValueList', [
        '$select' => 'Dimension_Code,Code,Name,Blocked',
        '$filter' => $filter,
    ]);
    $rows = odata_get_all($url, $auth, $ttl);

    return bc_fetch_department_cost_center_options_from_rows($rows, $dimensionCode);
}
