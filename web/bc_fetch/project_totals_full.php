<?php
/**
 * Volledige projecttotalen per bedrijf: kosten en opbrengst per Job_No over ALLE ProjectPosten uit BC.
 *
 * Waarom: de schermcache telde projecttotalen per geladen week op, en per week alleen de posten van projecten
 * waarvan in die week een werkorder geladen werd. Een post (bv. een verkoopfactuur S126…, creditnota C126…
 * of inkoopfactuur PI… zonder werkordernummer) in een week zonder werkorder van dat project viel daardoor
 * nergens in de som (hertest 8 okt: KvT/50 83 verschillen, PRJ2602195 opbrengst 0 i.p.v. 222.990).
 *
 * Werking (los van de weekcache, dus geen herbouw nodig):
 * - één bestand per bedrijf in cache/workorder_state/project_totals_<slug>.json met totalen per job en het
 *   hoogste verwerkte Entry_No;
 * - eerste keer: volledige opbouw in Entry_No-blokken (KvT ~55k posten, ~30-60 s), na het versturen van de
 *   pagina (fastcgi_finish_request) of in de nightly; nooit in de request van Linda;
 * - daarna: delta Entry_No > max bij het openen van de pagina (max één keer per 180 s, harde timeout, één
 *   schrijver onder lock). Posten zijn in BC onveranderlijk (correcties zijn nieuwe tegenboekingen), dus
 *   optellen op Entry_No is exact;
 * - dezelfde rekenregels als de rest van Demeter (ProjectFinanceService: kosten = Total_Cost van Gebruik,
 *   opbrengst = -Line_Amount_LCY van Verkoop).
 * Uitzetten: define('DEMETER_PROJECT_TOTALS_FULL_ENABLED', false).
 */

require_once __DIR__ . '/../project_finance.php';
require_once __DIR__ . '/odata_select.php';

if (!defined('DEMETER_PROJECT_TOTALS_FULL_VERSION')) {
    define('DEMETER_PROJECT_TOTALS_FULL_VERSION', 1);
}
if (!defined('DEMETER_PROJECT_TOTALS_FULL_MAX_AGE_SECONDS')) {
    define('DEMETER_PROJECT_TOTALS_FULL_MAX_AGE_SECONDS', 180);
}
if (!defined('DEMETER_PROJECT_TOTALS_FULL_BLOCK')) {
    define('DEMETER_PROJECT_TOTALS_FULL_BLOCK', 15000);
}

function demeter_project_totals_full_enabled(): bool
{
    return !defined('DEMETER_PROJECT_TOTALS_FULL_ENABLED') || DEMETER_PROJECT_TOTALS_FULL_ENABLED;
}

function demeter_project_totals_full_dir(): string
{
    if (isset($GLOBALS['demeter_project_totals_full_dir']) && is_string($GLOBALS['demeter_project_totals_full_dir'])) {
        return $GLOBALS['demeter_project_totals_full_dir'];
    }
    if (function_exists('demeter_workorder_state_cache_directory')) {
        return demeter_workorder_state_cache_directory();
    }

    return __DIR__ . '/../cache/workorder_state';
}

function demeter_project_totals_full_path(string $company): string
{
    $slug = preg_replace('/[^a-z0-9]+/i', '_', trim($company)) . '_' . substr(sha1(trim($company)), 0, 10);

    return demeter_project_totals_full_dir() . DIRECTORY_SEPARATOR . 'project_totals_' . $slug . '.json';
}

/** @return array{version:int, company:string, max_entry_no:int, built_at:int, synced_at:int, totals:array<string, array{costs: float, revenue: float}>}|null */
function demeter_project_totals_full_read(string $company): ?array
{
    $path = demeter_project_totals_full_path($company);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded) || (int) ($decoded['version'] ?? 0) !== DEMETER_PROJECT_TOTALS_FULL_VERSION
        || (int) ($decoded['built_at'] ?? 0) <= 0 || !is_array($decoded['totals'] ?? null)
    ) {
        return null;
    }

    return $decoded;
}

function demeter_project_totals_full_write(string $company, array $state): bool
{
    $dir = demeter_project_totals_full_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $path = demeter_project_totals_full_path($company);
    $tmp = $path . '.tmp' . getmypid();
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($json) || @file_put_contents($tmp, $json) === false) {
        return false;
    }

    return @rename($tmp, $path);
}

/**
 * Telt ProjectPosten op bij de totalen (zelfde regels als ProjectFinanceService) en houdt max Entry_No bij.
 *
 * @param list<array> $postenRows
 */
function demeter_project_totals_full_add_rows(array $state, array $postenRows, string $company): array
{
    if (!is_array($state['totals'] ?? null)) {
        $state['totals'] = [];
    }
    if ($postenRows === []) {
        return $state;
    }
    $service = new ProjectFinanceService($company);
    $finance = $service->aggregateProjectAndWorkorderFinanceFromProjectPostenRows($postenRows);
    foreach (is_array($finance['project_totals_by_job'] ?? null) ? $finance['project_totals_by_job'] : [] as $job => $values) {
        $key = strtolower(trim((string) $job));
        if ($key === '' || !is_array($values)) {
            continue;
        }
        if (!isset($state['totals'][$key])) {
            $state['totals'][$key] = ['costs' => 0.0, 'revenue' => 0.0];
        }
        $state['totals'][$key]['costs'] = round((float) $state['totals'][$key]['costs'] + (float) ($values['costs'] ?? 0.0), 2);
        $state['totals'][$key]['revenue'] = round((float) $state['totals'][$key]['revenue'] + (float) ($values['revenue'] ?? 0.0), 2);
    }
    foreach ($postenRows as $row) {
        $entryNo = is_array($row) ? (int) ($row['Entry_No'] ?? 0) : 0;
        if ($entryNo > (int) ($state['max_entry_no'] ?? 0)) {
            $state['max_entry_no'] = $entryNo;
        }
    }

    return $state;
}

function demeter_project_totals_full_select(): string
{
    $fields = bc_fetch_projectposten_finance_select_fields();
    array_unshift($fields, 'Entry_No');

    return implode(',', array_values(array_unique($fields)));
}

/**
 * Bouwt (allowFull) of werkt bij (delta op Entry_No). Eén schrijver onder een niet-blokkerende lock;
 * een fout/timeout laat het bestand ongewijzigd (volgende keer opnieuw).
 *
 * @param array{fetch: callable} $transport fetch(string $entity, array $query): list<array>
 * @return array{status: string, rows?: int, max_entry_no?: int, error?: string}
 */
function demeter_project_totals_full_sync(string $company, array $transport, bool $allowFull, bool $force = false): array
{
    if (!demeter_project_totals_full_enabled()) {
        return ['status' => 'disabled'];
    }
    $existing = demeter_project_totals_full_read($company);
    if ($existing === null && !$allowFull) {
        return ['status' => 'needs_full'];
    }
    if ($existing !== null && !$force && (time() - (int) ($existing['synced_at'] ?? 0)) < DEMETER_PROJECT_TOTALS_FULL_MAX_AGE_SECONDS) {
        return ['status' => 'fresh'];
    }

    $dir = demeter_project_totals_full_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $lock = @fopen(demeter_project_totals_full_path($company) . '.lock', 'c');
    if ($lock === false) {
        return ['status' => 'error', 'error' => 'lock niet te openen'];
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);

        return ['status' => 'busy'];
    }

    try {
        // Opnieuw lezen onder de lock (een andere schrijver kan net klaar zijn).
        $existing = demeter_project_totals_full_read($company);
        $fetch = $transport['fetch'];
        $select = demeter_project_totals_full_select();
        $rowsRead = 0;

        if ($existing === null) {
            if (!$allowFull) {
                return ['status' => 'needs_full'];
            }
            $last = $fetch('ProjectPosten', ['$select' => 'Entry_No', '$orderby' => 'Entry_No desc', '$top' => '1']);
            $maxInBc = (int) ($last[0]['Entry_No'] ?? 0);
            $state = ['version' => DEMETER_PROJECT_TOTALS_FULL_VERSION, 'company' => $company, 'max_entry_no' => 0, 'built_at' => 0, 'synced_at' => 0, 'totals' => []];
            for ($from = 0; $from < $maxInBc; $from += DEMETER_PROJECT_TOTALS_FULL_BLOCK) {
                $rows = $fetch('ProjectPosten', [
                    '$select' => $select,
                    '$filter' => 'Entry_No gt ' . $from . ' and Entry_No le ' . ($from + DEMETER_PROJECT_TOTALS_FULL_BLOCK),
                ]);
                $rowsRead += count($rows);
                $state = demeter_project_totals_full_add_rows($state, $rows, $company);
            }
            $state['built_at'] = time();
        } else {
            $state = $existing;
        }

        // Delta (ook direct na een volledige opbouw: posten die tijdens het lezen bijkwamen).
        $rows = $fetch('ProjectPosten', [
            '$select' => $select,
            '$filter' => 'Entry_No gt ' . (int) ($state['max_entry_no'] ?? 0),
            '$orderby' => 'Entry_No asc',
        ]);
        $max = (int) ($state['max_entry_no'] ?? 0);
        $rows = array_values(array_filter($rows, static fn ($r): bool => is_array($r) && (int) ($r['Entry_No'] ?? 0) > $max));
        $rowsRead += count($rows);
        $state = demeter_project_totals_full_add_rows($state, $rows, $company);
        $state['synced_at'] = time();
        if (!demeter_project_totals_full_write($company, $state)) {
            return ['status' => 'error', 'error' => 'schrijven mislukt'];
        }

        return ['status' => $existing === null ? 'built' : 'synced', 'rows' => $rowsRead, 'max_entry_no' => (int) $state['max_entry_no']];
    } catch (Throwable $error) {
        return ['status' => 'error', 'error' => $error->getMessage()];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Totalen voor de opgegeven jobs (lowercase sleutels) uit het bestand; leeg als het (nog) niet bestaat.
 *
 * @param list<string>|null $jobs null = alle
 * @return array<string, array{costs: float, revenue: float}>
 */
function demeter_project_totals_full_for_jobs(string $company, ?array $jobs = null): array
{
    if (!demeter_project_totals_full_enabled()) {
        return [];
    }
    $state = demeter_project_totals_full_read($company);
    if ($state === null) {
        return [];
    }
    $totals = $state['totals'];
    if ($jobs === null) {
        return $totals;
    }
    $out = [];
    foreach ($jobs as $job) {
        $key = strtolower(trim((string) $job));
        if ($key !== '' && isset($totals[$key])) {
            $out[$key] = $totals[$key];
        }
    }

    return $out;
}

/**
 * Overschrijft de projecttotalen in een totals-map (bv. de cumulatieve weeksom) met de volledige totalen.
 * Jobs die wel in de weeksom maar (nog) niet in het bestand staan, blijven staan.
 */
function demeter_project_totals_full_overlay_map(string $company, array $totalsByJob, array $extraJobs = []): array
{
    $jobs = array_merge(array_keys($totalsByJob), $extraJobs);
    $full = demeter_project_totals_full_for_jobs($company, $jobs);
    foreach ($full as $key => $values) {
        $totalsByJob[$key] = ['costs' => (float) $values['costs'], 'revenue' => (float) $values['revenue']];
    }

    return $totalsByJob;
}

/** Zet de volledige projecttotalen op display-rijen (alleen jobs die in het bestand staan). */
function demeter_project_totals_full_apply_to_rows(string $company, array $rowsByKey): array
{
    $jobs = [];
    foreach ($rowsByKey as $row) {
        if (is_array($row) && trim((string) ($row['Job_No'] ?? '')) !== '') {
            $jobs[strtolower(trim((string) $row['Job_No']))] = true;
        }
    }
    $full = demeter_project_totals_full_for_jobs($company, array_keys($jobs));
    if ($full === []) {
        return $rowsByKey;
    }
    if (!function_exists('demeter_apply_project_totals_to_display_rows')) {
        require_once __DIR__ . '/../workorder_rows.php';
    }

    return demeter_apply_project_totals_to_display_rows($rowsByKey, $full);
}
