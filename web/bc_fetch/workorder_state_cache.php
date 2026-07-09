<?php

/**
 * Persistente cache voor incrementeel laden van werkorders per bedrijf/kostenplaats/periode.
 */

require_once __DIR__ . '/cost_center.php';

const DEMETER_WORKORDER_STATE_CACHE_VERSION = 1;

/**
 * Bepaalt of een werkorderstatus als afgesloten telt.
 */
function demeter_workorder_status_is_closed(string $status): bool
{
    $normalized = strtolower(trim($status));
    $aliases = [
        'afgesloten' => 'closed',
        'geannuleerd' => 'cancelled',
        'uitgevoerd' => 'completed',
    ];

    if (isset($aliases[$normalized])) {
        $normalized = $aliases[$normalized];
    }

    return in_array($normalized, ['closed', 'cancelled', 'completed'], true);
}

/**
 * Bouwt een stabiele pair-key voor job + taak.
 */
function demeter_workorder_pair_key(string $jobNo, string $jobTaskNo): string
{
    return strtolower(trim($jobNo)) . '|' . strtolower(trim($jobTaskNo));
}

/**
 * Pad naar de cache-directory.
 */
function demeter_workorder_state_cache_directory(): string
{
    return __DIR__ . '/../cache/workorder_state';
}

/**
 * Bepaalt het cachebestand voor een load-context.
 */
function demeter_workorder_state_cache_path(string $company, string $costCenter, string $fromMonth, string $toMonth): string
{
    $parts = [
        trim($company),
        bc_fetch_normalize_cost_center($costCenter),
        trim($fromMonth),
        trim($toMonth),
    ];
    $hash = hash('sha256', implode("\0", $parts));

    return demeter_workorder_state_cache_directory() . '/' . $hash . '.json';
}

/**
 * Leest de werkorder-state cache.
 */
function demeter_workorder_state_cache_load(string $company, string $costCenter, string $fromMonth, string $toMonth): ?array
{
    $path = demeter_workorder_state_cache_path($company, $costCenter, $fromMonth, $toMonth);
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if ((int) ($decoded['version'] ?? 0) !== DEMETER_WORKORDER_STATE_CACHE_VERSION) {
        return null;
    }

    if (trim((string) ($decoded['company'] ?? '')) !== trim($company)) {
        return null;
    }

    if (bc_fetch_normalize_cost_center((string) ($decoded['cost_center'] ?? '')) !== bc_fetch_normalize_cost_center($costCenter)) {
        return null;
    }

    if (trim((string) ($decoded['from_month'] ?? '')) !== trim($fromMonth)) {
        return null;
    }

    if (trim((string) ($decoded['to_month'] ?? '')) !== trim($toMonth)) {
        return null;
    }

    if (!is_array($decoded['workorders'] ?? null)) {
        $decoded['workorders'] = [];
    }

    return $decoded;
}

/**
 * Slaat de werkorder-state cache op.
 */
function demeter_workorder_state_cache_save(string $company, string $costCenter, string $fromMonth, string $toMonth, array $workordersByPairKey): bool
{
    $directory = demeter_workorder_state_cache_directory();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $payload = [
        'version' => DEMETER_WORKORDER_STATE_CACHE_VERSION,
        'company' => trim($company),
        'cost_center' => bc_fetch_normalize_cost_center($costCenter),
        'from_month' => trim($fromMonth),
        'to_month' => trim($toMonth),
        'updated_at' => gmdate('c'),
        'workorders' => $workordersByPairKey,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    $path = demeter_workorder_state_cache_path($company, $costCenter, $fromMonth, $toMonth);

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Werkt cache-entry bij vanuit een opgehaalde werkorderrij.
 */
function demeter_workorder_state_cache_entry_from_row(array $workorderRow, string $financeKey, bool $seenInProjectPosten): array
{
    $jobNo = trim((string) ($workorderRow['Job_No'] ?? ''));
    $jobTaskNo = trim((string) ($workorderRow['Job_Task_No'] ?? ''));
    $status = trim((string) ($workorderRow['Status'] ?? ''));

    return [
        'job_no' => $jobNo,
        'job_task_no' => $jobTaskNo,
        'finance_key' => strtolower(trim($financeKey)),
        'is_closed' => demeter_workorder_status_is_closed($status),
        'status' => $status,
        'row' => $workorderRow,
        'last_seen_in_posten' => $seenInProjectPosten ? gmdate('Y-m-d') : null,
        'updated_at' => gmdate('c'),
    ];
}
