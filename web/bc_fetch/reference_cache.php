<?php

/**
 * Persistente referentie-cache (bedrijven, kostenplaatsen) en nightly-statistieken.
 */

require_once __DIR__ . '/workorder_state_cache.php';

function demeter_reference_cache_directory(): string
{
    return __DIR__ . '/../cache/reference';
}

function demeter_companies_cache_path(): string
{
    return demeter_reference_cache_directory() . '/companies.json';
}

function demeter_cost_center_options_cache_path(string $company): string
{
    $hash = hash('sha256', trim($company));

    return demeter_reference_cache_directory() . '/cost_centers_' . $hash . '.json';
}

function demeter_nightly_stats_path(): string
{
    return demeter_reference_cache_directory() . '/nightly_stats.json';
}

function demeter_reference_cache_ensure_directory(): bool
{
    $directory = demeter_reference_cache_directory();
    if (is_dir($directory)) {
        return true;
    }

    return mkdir($directory, 0775, true) || is_dir($directory);
}

/**
 * @return array{companies: list<string>, map: array<string, string>, updated_at: string|null}
 */
function demeter_companies_cache_defaults(): array
{
    return [
        'companies' => [],
        'map' => [],
        'updated_at' => null,
    ];
}

/**
 * @return array{companies: list<string>, map: array<string, string>, updated_at: string|null}
 */
function demeter_companies_cache_load(): array
{
    $path = demeter_companies_cache_path();
    if (!is_file($path) || !is_readable($path)) {
        return demeter_companies_cache_defaults();
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return demeter_companies_cache_defaults();
    }

    $companies = is_array($decoded['companies'] ?? null) ? $decoded['companies'] : [];
    $map = is_array($decoded['map'] ?? null) ? $decoded['map'] : [];

    return [
        'companies' => array_values(array_filter(array_map('strval', $companies), static function (string $value): bool {
            return trim($value) !== '';
        })),
        'map' => $map,
        'updated_at' => is_string($decoded['updated_at'] ?? null) ? $decoded['updated_at'] : null,
    ];
}

/**
 * @param list<string> $companies
 * @param array<string, string> $map
 */
function demeter_companies_cache_save(array $companies, array $map): bool
{
    if (!demeter_reference_cache_ensure_directory()) {
        return false;
    }

    $payload = [
        'companies' => array_values($companies),
        'map' => $map,
        'updated_at' => gmdate('c'),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json)
        && file_put_contents(demeter_companies_cache_path(), $json, LOCK_EX) !== false;
}

/**
 * @return list<array{code: string, name: string, label: string}>
 */
function demeter_cost_center_options_cache_load(string $company): array
{
    $path = demeter_cost_center_options_cache_path($company);
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !is_array($decoded['options'] ?? null)) {
        return [];
    }

    $options = [];
    foreach ($decoded['options'] as $option) {
        if (!is_array($option)) {
            continue;
        }

        $code = trim((string) ($option['code'] ?? ''));
        if ($code === '') {
            continue;
        }

        $options[] = [
            'code' => $code,
            'name' => trim((string) ($option['name'] ?? '')),
            'label' => trim((string) ($option['label'] ?? $code)),
        ];
    }

    return $options;
}

/**
 * @param list<array{code: string, name: string, label: string}> $options
 */
function demeter_cost_center_options_cache_save(string $company, array $options): bool
{
    if (!demeter_reference_cache_ensure_directory()) {
        return false;
    }

    $payload = [
        'company' => trim($company),
        'options' => $options,
        'updated_at' => gmdate('c'),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json)
        && file_put_contents(demeter_cost_center_options_cache_path($company), $json, LOCK_EX) !== false;
}

/**
 * @return array<string, mixed>
 */
function demeter_nightly_stats_defaults(): array
{
    return [
        'last_run_started_at' => null,
        'last_run_finished_at' => null,
        'companies' => [],
    ];
}

/**
 * @return array<string, mixed>
 */
function demeter_nightly_stats_load(): array
{
    $path = demeter_nightly_stats_path();
    if (!is_file($path) || !is_readable($path)) {
        return demeter_nightly_stats_defaults();
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : demeter_nightly_stats_defaults();
}

/**
 * @param array<string, mixed> $stats
 */
function demeter_nightly_stats_save(array $stats): bool
{
    if (!demeter_reference_cache_ensure_directory()) {
        return false;
    }

    $json = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json)
        && file_put_contents(demeter_nightly_stats_path(), $json, LOCK_EX) !== false;
}

function demeter_cache_age_hours(?string $updatedAt): ?float
{
    $updatedAt = trim((string) $updatedAt);
    if ($updatedAt === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $updatedAt);
    if (!$parsed instanceof DateTimeImmutable) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $updatedAt);
    }
    if (!$parsed instanceof DateTimeImmutable) {
        return null;
    }

    $seconds = time() - $parsed->getTimestamp();

    return max(0.0, $seconds / 3600);
}

function demeter_workorder_cost_center_cache_updated_at(string $company, string $costCenter): ?string
{
    $cachedState = demeter_workorder_state_cache_load($company, $costCenter);
    if (!is_array($cachedState)) {
        return null;
    }

    $updatedAt = trim((string) ($cachedState['updated_at'] ?? ''));

    return $updatedAt !== '' ? $updatedAt : null;
}

function demeter_workorder_cost_center_cache_is_populated(string $company, string $costCenter): bool
{
    $displayRows = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
    if ($displayRows !== []) {
        return true;
    }

    $cachedState = demeter_workorder_state_cache_load($company, $costCenter);
    if (!is_array($cachedState)) {
        return false;
    }

    $workorders = $cachedState['workorders'] ?? null;

    return is_array($workorders) && $workorders !== [];
}
