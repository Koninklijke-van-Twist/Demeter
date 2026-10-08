<?php

/**
 * Factuurcache per bedrijf; project-entries verlopen na demeter_invoice_cache_max_age_seconds().
 *
 * Factuurdata is project-lifetime; wordt één keer opgehaald en hergebruikt
 * over alle week-chunks heen.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../project_finance.php';

/**
 * v3: geboekte creditnota's (GeboekteVerkoopCreditnotaRegels) als negatieve bron.
 * v4: dedup op Line_No i.p.v. regeltekst (identieke regels werden weggegooid → te lage totalen);
 *     mislukte factuurbronnen worden niet meer als "0 gefactureerd" gecachet. Oude totalen zijn fout → herbouw.
 */
const DEMETER_INVOICE_CACHE_VERSION = 4;

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
        $invoiceDetailsById[$invoiceId]['Lines'] = demeter_invoice_cache_merge_lines($existingLines, $newLines);

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

    $failedKeys = array_fill_keys(array_map('strval', is_array($fetched['failed_project_keys'] ?? null) ? $fetched['failed_project_keys'] : []), true);

    foreach ($fetchedProjectKeys as $projectKey) {
        if (!is_string($projectKey) || $projectKey === '') {
            continue;
        }
        if (isset($failedKeys[$projectKey])) {
            // Onvolledig opgehaald: niet (over)schrijven; een eerdere goede entry blijft, anders volgende keer opnieuw.
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
 * Maximale leeftijd van een project-entry in de factuurcache (seconden). 0 = permanent (oud gedrag).
 * Standaard gelijk aan de werkorder-TTL (180 s): de data voor de gebruiker is hooguit 3 minuten oud.
 */
function demeter_invoice_cache_max_age_seconds(): int
{
    if (defined('DEMETER_INVOICE_CACHE_MAX_AGE_SECONDS')) {
        return max(0, (int) DEMETER_INVOICE_CACHE_MAX_AGE_SECONDS);
    }
    if (defined('DEMETER_WORKORDER_MAX_AGE_SECONDS')) {
        return max(0, (int) DEMETER_WORKORDER_MAX_AGE_SECONDS);
    }

    return 180;
}

/**
 * Voegt factuurregels samen zonder dezelfde BC-regel (bron + Line_No) twee keer op te nemen.
 * Regels zonder Line_No (oude cache) worden vervangen zodra er regels mét Line_No binnenkomen.
 *
 * @param list<array> $existingLines
 * @param list<array> $newLines
 * @return list<array>
 */
function demeter_invoice_cache_merge_lines(array $existingLines, array $newLines): array
{
    $byKey = [];
    $newHasLineNo = false;
    foreach ($newLines as $line) {
        if (is_array($line) && trim((string) ($line['Line_No'] ?? '')) !== '') {
            $newHasLineNo = true;
            break;
        }
    }
    foreach ([$existingLines, $newLines] as $set) {
        foreach ($set as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineNo = trim((string) ($line['Line_No'] ?? ''));
            if ($lineNo === '') {
                if ($newHasLineNo) {
                    continue;
                }
                $byKey[] = $line;
                continue;
            }
            $byKey[(string) ($line['Source_Entity'] ?? '') . '|' . $lineNo] = $line;
        }
    }

    return array_values($byKey);
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

    $maxAge = demeter_invoice_cache_max_age_seconds();
    $now = time();
    foreach ($normalizedProjects as $normalizedKey => $originalProjectNo) {
        if ($forceRefresh || !isset($cachedProjects[$normalizedKey])) {
            $missingProjectNumbers[] = $originalProjectNo;
            continue;
        }
        if ($maxAge > 0) {
            $cachedAt = strtotime((string) ($cachedProjects[$normalizedKey]['cached_at'] ?? ''));
            if ($cachedAt === false || ($now - $cachedAt) > $maxAge) {
                // Nieuwe facturen/creditnota's komen er anders nooit in (cache was permanent).
                $missingProjectNumbers[] = $originalProjectNo;
                continue;
            }
        }

        $fromCacheCount++;
    }

    $failedProjectKeys = [];
    if ($missingProjectNumbers !== []) {
        $financeService = new ProjectFinanceService($company);
        $fetched = $financeService->collectProjectInvoicesForProjects($missingProjectNumbers, $ttl);

        $fetchedKeys = [];
        foreach ($missingProjectNumbers as $projectNo) {
            $fetchedKeys[] = bc_fetch_normalize_project_no($projectNo);
        }

        demeter_invoice_cache_merge_fetched($cache, $fetched, $fetchedKeys);
        foreach (is_array($fetched['failed_project_keys'] ?? null) ? $fetched['failed_project_keys'] : [] as $failedKey) {
            $failedProjectKeys[(string) $failedKey] = true;
        }
        if (function_exists('odata_load_progress_heartbeat_throttled')) {
            odata_load_progress_heartbeat_throttled(true);
        }
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

    // Projecten waarvan een factuurbron faalde (bv. 409) en waarvoor geen eerdere goede cache-entry is:
    // de aanroeper mag hun (lege) factuurgegevens niet als compleet opslaan.
    $unresolvedFailedKeys = [];
    foreach (array_keys($failedProjectKeys) as $failedKey) {
        if (!is_array($cachedProjects[$failedKey] ?? null)) {
            $unresolvedFailedKeys[] = (string) $failedKey;
        }
    }

    return [
        'invoice_details_by_id' => $invoiceDetailsById,
        'project_invoice_ids_by_job' => $projectInvoiceIdsByJob,
        'project_invoiced_total_by_job' => $projectInvoicedTotalByJob,
        'failed_project_keys' => $unresolvedFailedKeys,
        'load_meta' => [
            'from_cache_count' => $fromCacheCount,
            'fetched_count' => count($missingProjectNumbers),
        ],
    ];
}
