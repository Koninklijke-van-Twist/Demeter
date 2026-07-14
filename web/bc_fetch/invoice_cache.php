<?php

/**
 * Permanente factuurcache per bedrijf (geen TTL).
 *
 * Factuurdata is project-lifetime; wordt één keer opgehaald en hergebruikt
 * over alle week-chunks heen.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../project_finance.php';

const DEMETER_INVOICE_CACHE_VERSION = 1;

/**
 * Pad naar de factuurcache-directory.
 */
function demeter_invoice_cache_directory(): string
{
    return __DIR__ . '/../cache/invoice_state';
}

/**
 * Bepaalt het cachebestand voor een bedrijf.
 */
function demeter_invoice_cache_path(string $company): string
{
    return demeter_invoice_cache_directory() . '/' . hash('sha256', trim($company)) . '.json';
}

/**
 * @return array{
 *   version: int,
 *   company: string,
 *   updated_at: string,
 *   projects: array<string, array{invoice_ids: list<string>, invoiced_total: float, cached_at: string}>,
 *   invoice_details_by_id: array<string, array>
 * }
 */
function demeter_invoice_cache_defaults(string $company): array
{
    return [
        'version' => DEMETER_INVOICE_CACHE_VERSION,
        'company' => trim($company),
        'updated_at' => gmdate(DateTimeInterface::ATOM),
        'projects' => [],
        'invoice_details_by_id' => [],
    ];
}

/**
 * @return array{
 *   version: int,
 *   company: string,
 *   updated_at: string,
 *   projects: array<string, array{invoice_ids: list<string>, invoiced_total: float, cached_at: string}>,
 *   invoice_details_by_id: array<string, array>
 * }
 */
function demeter_invoice_cache_load(string $company): array
{
    $path = demeter_invoice_cache_path($company);
    if (!is_file($path)) {
        return demeter_invoice_cache_defaults($company);
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return demeter_invoice_cache_defaults($company);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return demeter_invoice_cache_defaults($company);
    }

    if ((int) ($decoded['version'] ?? 0) !== DEMETER_INVOICE_CACHE_VERSION) {
        return demeter_invoice_cache_defaults($company);
    }

    $decoded['company'] = trim($company);
    $decoded['projects'] = is_array($decoded['projects'] ?? null) ? $decoded['projects'] : [];
    $decoded['invoice_details_by_id'] = is_array($decoded['invoice_details_by_id'] ?? null)
        ? $decoded['invoice_details_by_id']
        : [];

    return $decoded;
}

/**
 * @param array{
 *   version?: int,
 *   company?: string,
 *   updated_at?: string,
 *   projects?: array<string, array>,
 *   invoice_details_by_id?: array<string, array>
 * } $cache
 */
function demeter_invoice_cache_save(string $company, array $cache): void
{
    $directory = demeter_invoice_cache_directory();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Factuurcache-map kan niet worden aangemaakt: ' . $directory);
    }

    $cache['version'] = DEMETER_INVOICE_CACHE_VERSION;
    $cache['company'] = trim($company);
    $cache['updated_at'] = gmdate(DateTimeInterface::ATOM);

    $encoded = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Factuurcache kan niet worden geserialiseerd.');
    }

    $path = demeter_invoice_cache_path($company);
    $tempPath = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tempPath, $encoded) === false) {
        throw new RuntimeException('Factuurcache kan niet worden weggeschreven.');
    }

    if (!rename($tempPath, $path)) {
        @unlink($tempPath);
        throw new RuntimeException('Factuurcache kan niet atomisch worden opgeslagen.');
    }
}

/**
 * Voegt nieuw opgehaalde factuurdata toe aan de permanente cache.
 *
 * @param array{
 *   invoice_details_by_id?: array<string, array>,
 *   project_invoice_ids_by_job?: array<string, list<string>>,
 *   project_invoiced_total_by_job?: array<string, float>
 * } $fetched
 * @param list<string> $fetchedProjectKeys
 */
function demeter_invoice_cache_merge_fetched(array &$cache, array $fetched, array $fetchedProjectKeys): void
{
    $invoiceDetailsById = is_array($cache['invoice_details_by_id'] ?? null) ? $cache['invoice_details_by_id'] : [];
    $fetchedDetails = is_array($fetched['invoice_details_by_id'] ?? null) ? $fetched['invoice_details_by_id'] : [];

    foreach ($fetchedDetails as $invoiceId => $details) {
        if (!is_string($invoiceId) || $invoiceId === '' || !is_array($details)) {
            continue;
        }

        if (!isset($invoiceDetailsById[$invoiceId])) {
            $invoiceDetailsById[$invoiceId] = $details;
            continue;
        }

        $existingLines = is_array($invoiceDetailsById[$invoiceId]['Lines'] ?? null)
            ? $invoiceDetailsById[$invoiceId]['Lines']
            : [];
        $newLines = is_array($details['Lines'] ?? null) ? $details['Lines'] : [];
        $invoiceDetailsById[$invoiceId]['Lines'] = array_values(array_merge($existingLines, $newLines));

        $existingSources = is_array($invoiceDetailsById[$invoiceId]['Source_Entities'] ?? null)
            ? $invoiceDetailsById[$invoiceId]['Source_Entities']
            : [];
        $newSources = is_array($details['Source_Entities'] ?? null) ? $details['Source_Entities'] : [];
        if ($existingSources === [] && isset($invoiceDetailsById[$invoiceId]['Source_Entity'])) {
            $existingSources = [(string) $invoiceDetailsById[$invoiceId]['Source_Entity']];
        }
        $invoiceDetailsById[$invoiceId]['Source_Entities'] = array_values(array_unique(array_merge(
            $existingSources,
            $newSources
        )));
        if ($invoiceDetailsById[$invoiceId]['Source_Entities'] !== []) {
            $invoiceDetailsById[$invoiceId]['Source_Entity'] = $invoiceDetailsById[$invoiceId]['Source_Entities'][0];
        }
    }

    $cache['invoice_details_by_id'] = $invoiceDetailsById;

    $projects = is_array($cache['projects'] ?? null) ? $cache['projects'] : [];
    $fetchedIdsByJob = is_array($fetched['project_invoice_ids_by_job'] ?? null) ? $fetched['project_invoice_ids_by_job'] : [];
    $fetchedTotalsByJob = is_array($fetched['project_invoiced_total_by_job'] ?? null) ? $fetched['project_invoiced_total_by_job'] : [];
    $cachedAt = gmdate(DateTimeInterface::ATOM);

    foreach ($fetchedProjectKeys as $projectKey) {
        if (!is_string($projectKey) || $projectKey === '') {
            continue;
        }

        $invoiceIds = is_array($fetchedIdsByJob[$projectKey] ?? null) ? $fetchedIdsByJob[$projectKey] : [];
        $projects[$projectKey] = [
            'invoice_ids' => array_values(array_unique(array_filter(array_map('strval', $invoiceIds), static function (string $invoiceId): bool {
                return trim($invoiceId) !== '';
            }))),
            'invoiced_total' => finance_to_float($fetchedTotalsByJob[$projectKey] ?? 0.0),
            'cached_at' => $cachedAt,
        ];
    }

    $cache['projects'] = $projects;
}

/**
 * Haalt factuurdata op voor projecten, met permanente cache (alleen missende projecten uit BC).
 *
 * @param list<string> $projectNumbers
 * @return array{
 *   invoice_details_by_id: array<string, array>,
 *   project_invoice_ids_by_job: array<string, list<string>>,
 *   project_invoiced_total_by_job: array<string, float>,
 *   load_meta: array{from_cache_count: int, fetched_count: int}
 * }
 */
function bc_fetch_resolve_invoices_for_projects(
    string $company,
    array $projectNumbers,
    array $auth,
    int $ttl,
    bool $forceRefresh = false
): array {
    $normalizedProjects = [];
    foreach ($projectNumbers as $projectNo) {
        $projectNoText = trim((string) $projectNo);
        if ($projectNoText === '') {
            continue;
        }

        $normalizedProjects[bc_fetch_normalize_project_no($projectNoText)] = $projectNoText;
    }

    if ($normalizedProjects === []) {
        return [
            'invoice_details_by_id' => [],
            'project_invoice_ids_by_job' => [],
            'project_invoiced_total_by_job' => [],
            'load_meta' => [
                'from_cache_count' => 0,
                'fetched_count' => 0,
            ],
        ];
    }

    $cache = demeter_invoice_cache_load($company);
    $cachedProjects = is_array($cache['projects'] ?? null) ? $cache['projects'] : [];
    $missingProjectNumbers = [];
    $fromCacheCount = 0;

    foreach ($normalizedProjects as $normalizedKey => $originalProjectNo) {
        if ($forceRefresh || !isset($cachedProjects[$normalizedKey])) {
            $missingProjectNumbers[] = $originalProjectNo;
            continue;
        }

        $fromCacheCount++;
    }

    if ($missingProjectNumbers !== []) {
        $financeService = new ProjectFinanceService($company);
        $fetched = $financeService->collectProjectInvoicesForProjects($missingProjectNumbers, $ttl);

        $fetchedKeys = [];
        foreach ($missingProjectNumbers as $projectNo) {
            $fetchedKeys[] = bc_fetch_normalize_project_no($projectNo);
        }

        demeter_invoice_cache_merge_fetched($cache, $fetched, $fetchedKeys);
        demeter_invoice_cache_save($company, $cache);
    }

    $cachedProjects = is_array($cache['projects'] ?? null) ? $cache['projects'] : [];
    $invoiceDetailsById = [];
    $projectInvoiceIdsByJob = [];
    $projectInvoicedTotalByJob = [];
    $cachedDetails = is_array($cache['invoice_details_by_id'] ?? null) ? $cache['invoice_details_by_id'] : [];

    foreach ($normalizedProjects as $normalizedKey => $originalProjectNo) {
        $projectEntry = is_array($cachedProjects[$normalizedKey] ?? null) ? $cachedProjects[$normalizedKey] : [
            'invoice_ids' => [],
            'invoiced_total' => 0.0,
        ];

        $invoiceIds = is_array($projectEntry['invoice_ids'] ?? null) ? $projectEntry['invoice_ids'] : [];
        $projectInvoiceIdsByJob[$normalizedKey] = array_values(array_unique(array_filter(array_map('strval', $invoiceIds), static function (string $invoiceId): bool {
            return trim($invoiceId) !== '';
        })));
        $projectInvoicedTotalByJob[$normalizedKey] = finance_to_float($projectEntry['invoiced_total'] ?? 0.0);

        foreach ($projectInvoiceIdsByJob[$normalizedKey] as $invoiceId) {
            if (isset($cachedDetails[$invoiceId]) && is_array($cachedDetails[$invoiceId])) {
                $invoiceDetailsById[$invoiceId] = $cachedDetails[$invoiceId];
            }
        }
    }

    return [
        'invoice_details_by_id' => $invoiceDetailsById,
        'project_invoice_ids_by_job' => $projectInvoiceIdsByJob,
        'project_invoiced_total_by_job' => $projectInvoicedTotalByJob,
        'load_meta' => [
            'from_cache_count' => $fromCacheCount,
            'fetched_count' => count($missingProjectNumbers),
        ],
    ];
}
