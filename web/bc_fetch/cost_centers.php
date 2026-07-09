<?php

/**
 * Afdelingen/kostenplaatsen ophalen uit DimensionValueList.
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
 * Haalt afdelingen/kostenplaatsen op uit DimensionValueList.
 *
 * Alleen rijen die in de tabel voorkomen met een puur numerieke code < 100
 * en een niet-lege tekstbeschrijving. Er worden geen ontbrekende nummers aangevuld.
 *
 * @return list<array{code: string, name: string, label: string}>
 */
function bc_fetch_department_cost_center_options(string $company, array $auth, int $ttl): array
{
    $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'DimensionValueList', [
        '$select' => 'Dimension_Code,Code,Name,Blocked',
    ]);
    $rows = odata_get_all($url, $auth, $ttl);

    $options = [];
    $seenCodes = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
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
        $label = $code . ' - ' . $name;

        $options[] = [
            'code' => $code,
            'name' => $name,
            'label' => $label,
        ];
    }

    usort($options, static function (array $left, array $right): int {
        return (int) $left['code'] <=> (int) $right['code'];
    });

    return $options;
}
