<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/../finance_calculations.php';
require_once __DIR__ . '/../auth_helper.php';

/**
 * Functies
 */
/**
 * Normaliseert een projectnummer voor dictionary-keys.
 */
function bc_fetch_normalize_project_no(string $projectNo): string
{
    return strtolower(trim($projectNo));
}

/**
 * Leest een numerieke waarde veilig uit een rij.
 */
function bc_fetch_float_value(array $row, string $field): float
{
    return finance_to_float($row[$field] ?? 0.0);
}

/**
 * Voegt bedragen op veilige wijze op.
 */
function bc_fetch_add(float $left, float $right): float
{
    return finance_add_amount($left, $right);
}

/**
 * Bouwt een OData entity URL met query parameters voor het opgegeven bedrijf.
 */
function company_entity_url_with_query(string $baseUrl, mixed $environment, string $company, string $entitySet, array $query): string
{
    $resolvedEnvironment = '';

    if (is_array($environment)) {
        $normalizedEnvironments = auth_normalize_environment_list($environment);
        $resolvedEnvironment = (string) ($normalizedEnvironments[0] ?? '');
    } else {
        $resolvedEnvironment = trim((string) $environment);
    }

    if (function_exists('auth_get_environment_for_company')) {
        try {
            $resolvedEnvironment = auth_get_environment_for_company($company);
        } catch (Throwable $error) {
            // Laat bestaande environment-parameter staan als fallback.
        }
    }

    if ($resolvedEnvironment === '') {
        throw new RuntimeException('Geen environment beschikbaar voor company_entity_url_with_query.');
    }

    $safeCompany = str_replace("'", "''", trim($company));
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($resolvedEnvironment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

/**
 * Bouwt een dictionary met lege projectkeys voor alle aangeleverde projectnummers.
 */
function bc_fetch_seed_project_dictionary(array $projectNumbers): array
{
    $result = [];
    foreach ($projectNumbers as $projectNo) {
        $projectNoText = trim((string) $projectNo);
        if ($projectNoText === '') {
            continue;
        }

        $result[bc_fetch_normalize_project_no($projectNoText)] = [];
    }

    return $result;
}

/**
 * Normaliseert een werkordernummer voor dictionary-keys.
 */
function bc_fetch_normalize_workorder_no(string $workorderNo): string
{
    return strtolower(trim($workorderNo));
}
