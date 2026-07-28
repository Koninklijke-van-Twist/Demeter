<?php

/**
 * Cross-session active load registry (Ververs Nu / catch-up) per company+cost_center.
 */

require_once __DIR__ . '/cost_center.php';
require_once __DIR__ . '/reference_cache.php';
require_once __DIR__ . '/workorder_state_cache.php';

/** Claim is stale zonder heartbeat na dit aantal seconden. */
const DEMETER_ACTIVE_LOAD_STALE_SECONDS = 180;
/** Completed entry blijft zichtbaar zodat andere tabs soft-refresh kunnen doen. */
const DEMETER_ACTIVE_LOAD_COMPLETED_LINGER_SECONDS = 120;

function demeter_active_load_base_dir(): string
{
    if (function_exists('load_progress_base_dir')) {
        $dir = load_progress_base_dir() . DIRECTORY_SEPARATOR . 'active';
    } else {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'load_progress' . DIRECTORY_SEPARATOR . 'active';
    }

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return $dir;
}

function demeter_active_load_key(string $company, string $costCenter): string
{
    $parts = [
        trim($company),
        bc_fetch_normalize_cost_center($costCenter),
    ];

    return hash('sha256', implode("\0", $parts));
}

function demeter_active_load_path(string $company, string $costCenter): string
{
    return demeter_active_load_base_dir() . DIRECTORY_SEPARATOR . demeter_active_load_key($company, $costCenter) . '.json';
}

/**
 * @return array<string, mixed>|null
 */
function demeter_active_load_read_file(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $payload
 */
function demeter_active_load_write_file(string $path, array $payload): bool
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }

    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * @return array{
 *   token: string,
 *   company: string,
 *   cost_center: string,
 *   kind: string,
 *   status: string,
 *   started_at: int,
 *   updated_at: int,
 *   completed_at: int
 * }|null
 */
function demeter_active_load_normalize(?array $payload): ?array
{
    if (!is_array($payload)) {
        return null;
    }

    $token = trim((string) ($payload['token'] ?? ''));
    $status = trim((string) ($payload['status'] ?? ''));
    $tokenValid = function_exists('odata_load_progress_is_valid_token')
        ? odata_load_progress_is_valid_token($token)
        : (preg_match('/^[a-f0-9]{32}$/', $token) === 1);
    if ($token === '' || !$tokenValid) {
        return null;
    }
    if (!in_array($status, ['running', 'completed', 'error'], true)) {
        return null;
    }

    return [
        'token' => $token,
        'company' => trim((string) ($payload['company'] ?? '')),
        'cost_center' => trim((string) ($payload['cost_center'] ?? '')),
        'kind' => trim((string) ($payload['kind'] ?? 'refresh')),
        'status' => $status,
        'started_at' => max(0, (int) ($payload['started_at'] ?? 0)),
        'updated_at' => max(0, (int) ($payload['updated_at'] ?? 0)),
        'completed_at' => max(0, (int) ($payload['completed_at'] ?? 0)),
    ];
}

function demeter_active_load_is_fresh_running(?array $normalized): bool
{
    if ($normalized === null || ($normalized['status'] ?? '') !== 'running') {
        return false;
    }

    $updatedAt = (int) ($normalized['updated_at'] ?? 0);
    if ($updatedAt <= 0) {
        return false;
    }

    return (time() - $updatedAt) <= DEMETER_ACTIVE_LOAD_STALE_SECONDS;
}

function demeter_active_load_is_recently_completed(?array $normalized): bool
{
    if ($normalized === null || ($normalized['status'] ?? '') !== 'completed') {
        return false;
    }

    $completedAt = (int) ($normalized['completed_at'] ?? 0);
    if ($completedAt <= 0) {
        $completedAt = (int) ($normalized['updated_at'] ?? 0);
    }
    if ($completedAt <= 0) {
        return false;
    }

    return (time() - $completedAt) <= DEMETER_ACTIVE_LOAD_COMPLETED_LINGER_SECONDS;
}

/**
 * @return array<string, mixed>|null
 */
function demeter_active_load_get(string $company, string $costCenter): ?array
{
    $company = trim($company);
    $costCenter = bc_fetch_normalize_cost_center($costCenter);
    if ($company === '' || $costCenter === '') {
        return null;
    }

    $path = demeter_active_load_path($company, $costCenter);
    $normalized = demeter_active_load_normalize(demeter_active_load_read_file($path));
    if ($normalized === null) {
        return null;
    }

    if ($normalized['status'] === 'running' && !demeter_active_load_is_fresh_running($normalized)) {
        @unlink($path);

        return null;
    }

    if ($normalized['status'] === 'completed' && !demeter_active_load_is_recently_completed($normalized)) {
        @unlink($path);

        return null;
    }

    if ($normalized['status'] === 'error') {
        @unlink($path);

        return null;
    }

    return $normalized;
}

/**
 * Claim of adopteer een actieve load.
 *
 * @return array{adopted: bool, entry: array<string, mixed>}
 */
function demeter_active_load_claim(string $company, string $costCenter, string $token, string $kind): array
{
    $company = trim($company);
    $costCenter = bc_fetch_normalize_cost_center($costCenter);
    $token = trim($token);
    $kind = trim($kind) !== '' ? trim($kind) : 'refresh';

    $tokenValid = function_exists('odata_load_progress_is_valid_token')
        ? odata_load_progress_is_valid_token($token)
        : (preg_match('/^[a-f0-9]{32}$/', $token) === 1);
    if ($company === '' || $costCenter === '' || !$tokenValid) {
        throw new InvalidArgumentException('Ongeldige active-load claim parameters.');
    }

    $path = demeter_active_load_path($company, $costCenter);
    $existing = demeter_active_load_normalize(demeter_active_load_read_file($path));

    if (demeter_active_load_is_fresh_running($existing)) {
        return [
            'adopted' => true,
            'entry' => $existing,
        ];
    }

    $now = time();
    $entry = [
        'token' => $token,
        'company' => $company,
        'cost_center' => $costCenter,
        'kind' => $kind === 'catch_up' ? 'catch_up' : 'refresh',
        'status' => 'running',
        'started_at' => $now,
        'updated_at' => $now,
        'completed_at' => 0,
    ];
    demeter_active_load_write_file($path, $entry);

    return [
        'adopted' => false,
        'entry' => $entry,
    ];
}

function demeter_active_load_heartbeat(string $company, string $costCenter, string $token): void
{
    $path = demeter_active_load_path($company, $costCenter);
    $existing = demeter_active_load_normalize(demeter_active_load_read_file($path));
    if ($existing === null || ($existing['status'] ?? '') !== 'running') {
        return;
    }
    if (trim((string) ($existing['token'] ?? '')) !== trim($token)) {
        return;
    }

    $existing['updated_at'] = time();
    demeter_active_load_write_file($path, $existing);
}

function demeter_active_load_heartbeat_by_token(string $token): void
{
    $token = trim($token);
    $tokenValid = function_exists('odata_load_progress_is_valid_token')
        ? odata_load_progress_is_valid_token($token)
        : (preg_match('/^[a-f0-9]{32}$/', $token) === 1);
    if ($token === '' || !$tokenValid) {
        return;
    }

    $dir = demeter_active_load_base_dir();
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION) !== 'json') {
            continue;
        }

        $existing = demeter_active_load_normalize(demeter_active_load_read_file($fileInfo->getPathname()));
        if ($existing === null || ($existing['token'] ?? '') !== $token) {
            continue;
        }
        if (($existing['status'] ?? '') !== 'running') {
            continue;
        }

        $existing['updated_at'] = time();
        demeter_active_load_write_file($fileInfo->getPathname(), $existing);
        return;
    }
}

function demeter_active_load_complete(string $company, string $costCenter, string $token): void
{
    $path = demeter_active_load_path($company, $costCenter);
    $existing = demeter_active_load_normalize(demeter_active_load_read_file($path));
    if ($existing === null) {
        return;
    }
    if (trim((string) ($existing['token'] ?? '')) !== trim($token)) {
        return;
    }

    $now = time();
    $existing['status'] = 'completed';
    $existing['updated_at'] = $now;
    $existing['completed_at'] = $now;
    demeter_active_load_write_file($path, $existing);
}

function demeter_active_load_complete_by_token(string $token): void
{
    $token = trim($token);
    $tokenValid = function_exists('odata_load_progress_is_valid_token')
        ? odata_load_progress_is_valid_token($token)
        : (preg_match('/^[a-f0-9]{32}$/', $token) === 1);
    if ($token === '' || !$tokenValid) {
        return;
    }

    $dir = demeter_active_load_base_dir();
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION) !== 'json') {
            continue;
        }

        $existing = demeter_active_load_normalize(demeter_active_load_read_file($fileInfo->getPathname()));
        if ($existing === null || ($existing['token'] ?? '') !== $token) {
            continue;
        }

        $now = time();
        $existing['status'] = 'completed';
        $existing['updated_at'] = $now;
        $existing['completed_at'] = $now;
        demeter_active_load_write_file($fileInfo->getPathname(), $existing);
        return;
    }
}

function demeter_active_load_error(string $company, string $costCenter, string $token): void
{
    $path = demeter_active_load_path($company, $costCenter);
    $existing = demeter_active_load_normalize(demeter_active_load_read_file($path));
    if ($existing === null) {
        return;
    }
    if (trim((string) ($existing['token'] ?? '')) !== trim($token)) {
        return;
    }

    @unlink($path);
}

function demeter_active_load_error_by_token(string $token): void
{
    $token = trim($token);
    $tokenValid = function_exists('odata_load_progress_is_valid_token')
        ? odata_load_progress_is_valid_token($token)
        : (preg_match('/^[a-f0-9]{32}$/', $token) === 1);
    if ($token === '' || !$tokenValid) {
        return;
    }

    $dir = demeter_active_load_base_dir();
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION) !== 'json') {
            continue;
        }

        $existing = demeter_active_load_normalize(demeter_active_load_read_file($fileInfo->getPathname()));
        if ($existing === null || ($existing['token'] ?? '') !== $token) {
            continue;
        }

        @unlink($fileInfo->getPathname());
        return;
    }
}

/**
 * Payload voor client poll (inclusief cache-leeftijd).
 *
 * @return array<string, mixed>
 */
function demeter_active_load_status_payload(string $company, string $costCenter): array
{
    $company = trim($company);
    $costCenter = bc_fetch_normalize_cost_center($costCenter);
    $entry = ($company !== '' && $costCenter !== '') ? demeter_active_load_get($company, $costCenter) : null;
    $cacheUpdatedAt = ($company !== '' && $costCenter !== '')
        ? demeter_workorder_cost_center_cache_updated_at($company, $costCenter)
        : null;
    $ageHours = demeter_cache_age_hours($cacheUpdatedAt);
    $ageSeconds = null;
    if (is_string($cacheUpdatedAt) && $cacheUpdatedAt !== '') {
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $cacheUpdatedAt);
        if (!$parsed instanceof DateTimeImmutable) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $cacheUpdatedAt);
        }
        if ($parsed instanceof DateTimeImmutable) {
            $ageSeconds = max(0, time() - $parsed->getTimestamp());
        }
    }

    $running = demeter_active_load_is_fresh_running($entry);
    $recentlyCompleted = demeter_active_load_is_recently_completed($entry);

    return [
        'ok' => true,
        'company' => $company,
        'cost_center' => $costCenter,
        'running' => $running,
        'recently_completed' => $recentlyCompleted,
        'refresh_blocked' => $running,
        'active_load' => $entry,
        'cache_updated_at' => $cacheUpdatedAt,
        'cache_age_hours' => $ageHours,
        'cache_age_seconds' => $ageSeconds,
    ];
}
