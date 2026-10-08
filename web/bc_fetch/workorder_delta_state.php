<?php
/**
 * Checkpoint + 'opnieuw laden'-weken van de page-open BC-delta per bedrijf/kostenplaats (schermcache).
 * Apart bestand naast de werkorder-state (<state>.delta.json): een (her)bouw wist het niet, de nightly
 * leest het niet, en month_scan blijft ongewijzigd.
 *
 * Inhoud: posten_entry_no / changelog_entry_no (hoogste gezien Entry_No), created_since (ISO, nieuwe
 * werkorders), synced_at (unix, laatste geslaagde delta), dirty_weeks (week => reden), last (statistiek).
 */

require_once __DIR__ . '/workorder_state_cache.php';

function demeter_workorder_delta_path(string $company, string $costCenter): string
{
    return demeter_workorder_state_cache_path($company, $costCenter) . '.delta.json';
}

/**
 * @return array<string, mixed>
 */
function demeter_workorder_delta_defaults(): array
{
    return [
        'posten_entry_no' => null,
        'changelog_entry_no' => null,
        'created_since' => null,
        'synced_at' => 0,
        'dirty_weeks' => [],
        'last' => null,
    ];
}

/**
 * @return array<string, mixed>
 */
function demeter_workorder_delta_read(string $company, string $costCenter): array
{
    $path = demeter_workorder_delta_path($company, $costCenter);
    if (!is_file($path)) {
        return demeter_workorder_delta_defaults();
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded)) {
        return demeter_workorder_delta_defaults();
    }
    $state = array_replace(demeter_workorder_delta_defaults(), $decoded);
    $state['dirty_weeks'] = is_array($state['dirty_weeks']) ? $state['dirty_weeks'] : [];

    return $state;
}

function demeter_workorder_delta_write(string $company, string $costCenter, array $state): bool
{
    $path = demeter_workorder_delta_path($company, $costCenter);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }

    return demeter_workorder_state_cache_write_atomic($path, $json);
}

/**
 * Weken die door een BC-wijziging opnieuw gelezen moeten worden (nieuwste eerst).
 *
 * @return list<string>
 */
function demeter_workorder_delta_dirty_weeks(string $company, string $costCenter): array
{
    $weeks = array_keys(demeter_workorder_delta_read($company, $costCenter)['dirty_weeks']);
    $weeks = array_values(array_filter(array_map('strval', $weeks), 'demeter_is_valid_iso_year_week'));
    rsort($weeks);

    return $weeks;
}

function demeter_workorder_delta_week_is_dirty(string $company, string $costCenter, string $yearWeek): bool
{
    $path = demeter_workorder_delta_path($company, $costCenter);
    if (!is_file($path)) {
        return false;
    }

    return isset(demeter_workorder_delta_read($company, $costCenter)['dirty_weeks'][$yearWeek]);
}

/**
 * Na een geslaagde (her)lading van een week: niet meer 'opnieuw laden'. Mislukt de lading, dan blijft de
 * week gemarkeerd en probeert de volgende page-open het opnieuw.
 */
function demeter_workorder_delta_clear_dirty_week(string $company, string $costCenter, string $yearWeek): void
{
    $path = demeter_workorder_delta_path($company, $costCenter);
    if (!is_file($path)) {
        return;
    }
    $state = demeter_workorder_delta_read($company, $costCenter);
    if (!isset($state['dirty_weeks'][$yearWeek])) {
        return;
    }
    unset($state['dirty_weeks'][$yearWeek]);
    demeter_workorder_delta_write($company, $costCenter, $state);
}
