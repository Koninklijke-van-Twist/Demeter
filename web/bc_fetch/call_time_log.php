<?php

/**
 * Tijdelijke OData call-time logging naar CSV (alleen localhost).
 */

const DEMETER_CALL_TIME_LOG_MIN_MS = 100;
const DEMETER_CALL_TIME_LOG_RETENTION_DAYS = 7;

function odata_call_time_log_is_localhost(): bool
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return false;
    }

    $host = preg_replace('/:\d+$/', '', $host);

    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

function odata_call_time_log_is_enabled(): bool
{
    return odata_call_time_log_is_localhost()
        && trim((string) ($GLOBALS['demeter_call_time_log_session'] ?? '')) !== '';
}

function odata_call_time_log_directory(): string
{
    return __DIR__ . '/../cache/call_time_logs';
}

function odata_call_time_log_create_session_id(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d_H-i-s');
}

function odata_call_time_log_is_valid_session_id(string $sessionId): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', trim($sessionId)) === 1;
}

function odata_call_time_log_path_for_session(string $sessionId): string
{
    return odata_call_time_log_directory() . '/' . $sessionId . '_call_time_log.csv';
}

function odata_call_time_log_cleanup_old_files(): void
{
    static $cleaned = false;
    if ($cleaned || !odata_call_time_log_is_localhost()) {
        return;
    }

    $cleaned = true;
    $directory = odata_call_time_log_directory();
    if (!is_dir($directory)) {
        return;
    }

    $cutoff = time() - (DEMETER_CALL_TIME_LOG_RETENTION_DAYS * 86400);
    foreach (glob($directory . '/*_call_time_log.csv') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }

        $mtime = filemtime($path);
        if ($mtime !== false && $mtime < $cutoff) {
            @unlink($path);
        }
    }
}

function odata_call_time_log_activate_session(string $sessionId): void
{
    $sessionId = trim($sessionId);
    if (!odata_call_time_log_is_localhost() || !odata_call_time_log_is_valid_session_id($sessionId)) {
        $GLOBALS['demeter_call_time_log_session'] = '';

        return;
    }

    odata_call_time_log_cleanup_old_files();
    $GLOBALS['demeter_call_time_log_session'] = $sessionId;
}

function odata_call_time_log_entity_from_url(string $url): string
{
    if (preg_match('#/ODataV4/Company\([^)]+\)/([^/?]+)#', $url, $matches) === 1) {
        return rawurldecode($matches[1]);
    }

    return 'unknown';
}

function odata_call_time_log_summarize_url(string $url): string
{
    if (preg_match('#/ODataV4/Company\([^)]+\)/([^?]+)\?(.+)$#', $url, $matches) === 1) {
        return rawurldecode($matches[1]) . '?' . $matches[2];
    }

    if (preg_match('#/ODataV4/Company\([^)]+\)/([^/?]+)#', $url, $matches) === 1) {
        return rawurldecode($matches[1]);
    }

    return strlen($url) > 500 ? substr($url, 0, 500) . '...' : $url;
}

function odata_call_time_log_csv_field(string $value): string
{
    $normalized = str_replace(["\r", "\n"], ' ', $value);
    if (strpbrk($normalized, ",\"\n\r") !== false) {
        return '"' . str_replace('"', '""', $normalized) . '"';
    }

    return $normalized;
}

function odata_call_time_log_record(string $url, int $durationMs, bool $fromCache, int $rowCount = 0): void
{
    if (!odata_call_time_log_is_enabled() || $durationMs < DEMETER_CALL_TIME_LOG_MIN_MS) {
        return;
    }

    $sessionId = trim((string) ($GLOBALS['demeter_call_time_log_session'] ?? ''));
    if ($sessionId === '') {
        return;
    }

    $directory = odata_call_time_log_directory();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }

    $path = odata_call_time_log_path_for_session($sessionId);
    $isNewFile = !is_file($path);
    $handle = @fopen($path, 'ab');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            return;
        }

        if ($isNewFile) {
            fwrite($handle, "logged_at,entity,duration_ms,cached,rows,url\n");
        }

        $line = implode(',', [
            odata_call_time_log_csv_field((new DateTimeImmutable('now'))->format('Y-m-d H:i:s')),
            odata_call_time_log_csv_field(odata_call_time_log_entity_from_url($url)),
            (string) max(0, $durationMs),
            $fromCache ? '1' : '0',
            (string) max(0, $rowCount),
            odata_call_time_log_csv_field(odata_call_time_log_summarize_url($url)),
        ]) . "\n";

        fwrite($handle, $line);
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}
