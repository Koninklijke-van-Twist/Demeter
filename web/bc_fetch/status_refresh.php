<?php
/**
 * Ververs Nu: statussen (Status, KVT_Document_Status) van alle niet-afgesloten werkorders in de cache
 * van één bedrijf + kostenplaats live uit BC (max_age 0) opnieuw lezen, in korte stappen.
 *
 * - Eén job per bedrijf + kostenplaats (statusbestand in de cache). Start terwijl er al een job loopt =
 *   meeliften op die job (zelfde voortgang voor iedereen), geen tweede job.
 * - De browser roept 'step' herhaaldelijk aan; elke stap werkt max. ~20 s (geen lange HTTP-request).
 *   Een step-lock (flock, non-blocking) zorgt dat er per job maar één stap tegelijk BC bevraagt; andere
 *   tabs krijgen dan alleen de voortgang terug. Dus 1 Mímir-request tegelijk voor deze job.
 * - Na elke chunk worden gewijzigde statussen direct in de cache gezet (display, vorige rijen, state).
 * - Voortgang = chunks klaar / chunks totaal (totaal = aantal open werkorders in de cache / chunkgrootte).
 */

require_once __DIR__ . '/nightly_runner.php';

if (!defined('DEMETER_STATUS_REFRESH_CHUNK_SIZE')) {
    define('DEMETER_STATUS_REFRESH_CHUNK_SIZE', 40);
}
if (!defined('DEMETER_STATUS_REFRESH_STEP_SECONDS')) {
    define('DEMETER_STATUS_REFRESH_STEP_SECONDS', 20);
}
/** Een 'running' job die zo lang niet is bijgewerkt (alle tabs dicht) mag door een nieuwe start vervangen worden. */
if (!defined('DEMETER_STATUS_REFRESH_STALE_SECONDS')) {
    define('DEMETER_STATUS_REFRESH_STALE_SECONDS', 600);
}

function demeter_status_refresh_path(string $company, string $costCenter): string
{
    return demeter_workorder_state_cache_path($company, $costCenter) . '.status_refresh.json';
}

function demeter_status_refresh_read(string $company, string $costCenter): ?array
{
    $path = demeter_status_refresh_path($company, $costCenter);
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) @file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

function demeter_status_refresh_write(string $company, string $costCenter, array $job): void
{
    $job['updated_at'] = time();
    $dir = dirname(demeter_status_refresh_path($company, $costCenter));
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    demeter_workorder_state_cache_write_atomic(demeter_status_refresh_path($company, $costCenter), (string) json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Publieke voortgang (zonder de nummerlijst). */
function demeter_status_refresh_public(?array $job): array
{
    if (!is_array($job)) {
        return ['status' => 'none', 'chunks_done' => 0, 'chunks_total' => 0, 'updated' => 0, 'percent' => 0];
    }
    $total = (int) ($job['chunks_total'] ?? 0);
    $done = (int) ($job['chunks_done'] ?? 0);

    return [
        'status' => (string) ($job['status'] ?? 'none'),
        'chunks_done' => $done,
        'chunks_total' => $total,
        'workorders_total' => (int) ($job['workorders_total'] ?? 0),
        'updated' => (int) ($job['updated'] ?? 0),
        'percent' => $total > 0 ? (int) floor(100 * $done / $total) : 100,
        'started_at' => (int) ($job['started_at'] ?? 0),
        'error' => (string) ($job['error'] ?? ''),
    ];
}

/**
 * Werkordernummers (open in de cache) om na te kijken: display-rijen plus vorige rijen (herbouw).
 *
 * @return list<string>
 */
function demeter_status_refresh_open_numbers(string $company, string $costCenter): array
{
    $rows = demeter_workorder_state_cache_display_rows_for_page($company, $costCenter)['rows'];
    $nos = [];
    foreach ($rows as $row) {
        if (!is_array($row) || demeter_status_is_closed((string) ($row['Status'] ?? ''))) {
            continue;
        }
        $no = trim((string) ($row['Bc_No'] ?? ($row['No'] ?? '')));
        if ($no !== '' && trim((string) ($row['Job_No'] ?? '')) !== '') {
            $nos[$no] = true;
        }
    }
    $list = array_map('strval', array_keys($nos));
    sort($list, SORT_STRING);

    return $list;
}

/**
 * Start een statusverversing, of lift mee op de lopende (zelfde bedrijf + kostenplaats).
 *
 * @return array{job: array, joined: bool}
 */
function demeter_status_refresh_start(string $company, string $costCenter): array
{
    $path = demeter_status_refresh_path($company, $costCenter);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $lock = fopen($path . '.start.lock', 'c');
    if ($lock !== false) {
        flock($lock, LOCK_EX);
    }
    try {
        $existing = demeter_status_refresh_read($company, $costCenter);
        if (is_array($existing) && ($existing['status'] ?? '') === 'running'
            && time() - (int) ($existing['updated_at'] ?? 0) < DEMETER_STATUS_REFRESH_STALE_SECONDS
        ) {
            return ['job' => $existing, 'joined' => true];
        }
        $nos = demeter_status_refresh_open_numbers($company, $costCenter);
        $chunks = array_chunk($nos, max(1, (int) DEMETER_STATUS_REFRESH_CHUNK_SIZE));
        $job = [
            'company' => $company,
            'cost_center' => $costCenter,
            'status' => $chunks === [] ? 'completed' : 'running',
            'started_at' => time(),
            'chunks' => $chunks,
            'chunks_total' => count($chunks),
            'chunks_done' => 0,
            'workorders_total' => count($nos),
            'updated' => 0,
            'error' => '',
        ];
        demeter_status_refresh_write($company, $costCenter, $job);

        return ['job' => $job, 'joined' => false];
    } finally {
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Eén stap: chunks verwerken tot het tijdbudget op is. Loopt er al een stap (andere tab), dan alleen
 * de voortgang teruggeven.
 */
function demeter_status_refresh_step(string $company, string $costCenter, array $auth, ?int $budgetSeconds = null): array
{
    $budget = $budgetSeconds ?? (int) DEMETER_STATUS_REFRESH_STEP_SECONDS;
    $path = demeter_status_refresh_path($company, $costCenter);
    $lock = @fopen($path . '.step.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock !== false) {
            fclose($lock);
        }

        return demeter_status_refresh_public(demeter_status_refresh_read($company, $costCenter));
    }
    try {
        $job = demeter_status_refresh_read($company, $costCenter);
        if (!is_array($job) || ($job['status'] ?? '') !== 'running') {
            return demeter_status_refresh_public($job);
        }
        $deadline = microtime(true) + max(0, $budget);
        $first = true;
        $chunks = is_array($job['chunks'] ?? null) ? $job['chunks'] : [];
        try {
            while ((int) $job['chunks_done'] < count($chunks) && ($first || microtime(true) < $deadline)) {
                $first = false;
                $chunk = (array) $chunks[(int) $job['chunks_done']];
                $filter = implode(' or ', array_map(static function ($no): string {
                    return "No eq '" . str_replace("'", "''", (string) $no) . "'";
                }, $chunk));
                $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
                    '$select' => 'No,Job_No,Job_Task_No,Status,KVT_Document_Status',
                    '$filter' => $filter,
                ]);
                $statusByPair = [];
                // ttl 0 = Mímir max_age 0: echt live.
                foreach (odata_get_all($url, $auth, 0) as $row) {
                    if (is_array($row)) {
                        $statusByPair[demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''))] = $row;
                    }
                }
                $job['updated'] = (int) ($job['updated'] ?? 0) + demeter_workorder_apply_status_snapshots($company, $costCenter, $statusByPair);
                $job['chunks_done'] = (int) $job['chunks_done'] + 1;
                $job['failures'] = 0;
                if ((int) $job['chunks_done'] >= count($chunks)) {
                    $job['status'] = 'completed';
                }
                demeter_status_refresh_write($company, $costCenter, $job);
            }
        } catch (Throwable $e) {
            // Niet afbreken: de volgende stap probeert dezelfde chunk opnieuw; na 3 fouten op rij opgeven.
            $job['failures'] = (int) ($job['failures'] ?? 0) + 1;
            $job['error'] = $e->getMessage();
            if ($job['failures'] >= 3) {
                $job['status'] = 'error';
            }
            demeter_status_refresh_write($company, $costCenter, $job);
            error_log('Demeter status_refresh ' . $company . ' / ' . $costCenter . ': ' . $e->getMessage());
        }

        return demeter_status_refresh_public($job);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
