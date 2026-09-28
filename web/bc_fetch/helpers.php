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

    $circuitOpen = function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open();
    $mimirHealthy = function_exists('odata_mimir_enabled') && odata_mimir_enabled() && !$circuitOpen;

    // Zolang Mímir gezond is, geen company-discovery (die zou Mímir al aanroepen).
    // Na een storing: sentinel 'mimir' vervangen door de BC-environment uit auth.php.
    if (!$mimirHealthy && ($resolvedEnvironment === '' || strcasecmp($resolvedEnvironment, 'mimir') === 0)) {
        $bcEnv = function_exists('odata_bc_environment') ? odata_bc_environment() : null;
        if (is_string($bcEnv) && $bcEnv !== '') {
            $resolvedEnvironment = $bcEnv;
        } elseif (function_exists('auth_get_environment_for_company')) {
            try {
                $fromCompany = auth_get_environment_for_company($company);
                if (is_string($fromCompany) && trim($fromCompany) !== '' && strcasecmp($fromCompany, 'mimir') !== 0) {
                    $resolvedEnvironment = $fromCompany;
                }
            } catch (Throwable $error) {
                // Laat bestaande environment-parameter staan als fallback.
            }
        }
    }

    $resolvedBaseUrl = trim($baseUrl);
    if ($mimirHealthy) {
        if ($resolvedEnvironment === '') {
            $resolvedEnvironment = 'mimir';
        }
        $resolvedBaseUrl = 'https://mimir.invalid/';
    } else {
        if ($resolvedBaseUrl === '' || stripos($resolvedBaseUrl, 'mimir.invalid') !== false) {
            $bcBase = function_exists('odata_bc_base_url') ? odata_bc_base_url() : null;
            $resolvedBaseUrl = is_string($bcBase) ? $bcBase : '';
        }
        if ($resolvedEnvironment === '' || strcasecmp($resolvedEnvironment, 'mimir') === 0) {
            $bcEnv = function_exists('odata_bc_environment') ? odata_bc_environment() : null;
            if (is_string($bcEnv) && $bcEnv !== '') {
                $resolvedEnvironment = $bcEnv;
            }
        }
        if ($resolvedEnvironment === '' || $resolvedBaseUrl === '') {
            $mimirWasOn = function_exists('odata_mimir_enabled') && odata_mimir_enabled();
            $bcReady = function_exists('odata_bc_credentials_configured') && odata_bc_credentials_configured();
            if ($mimirWasOn && !$bcReady && function_exists('odata_mimir_last_error')) {
                $previous = odata_mimir_last_error();
                if ($previous instanceof Throwable) {
                    throw $previous;
                }
            }
            if ($resolvedEnvironment === '') {
                throw new RuntimeException('Geen environment beschikbaar voor company_entity_url_with_query.');
            }
            throw new RuntimeException('baseUrl ontbreekt voor company_entity_url_with_query.');
        }
    }

    $safeCompany = str_replace("'", "''", trim($company));
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($resolvedBaseUrl, '/') . '/' . rawurlencode($resolvedEnvironment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

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
