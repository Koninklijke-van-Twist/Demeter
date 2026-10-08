<?php
/**
 * Trage maar levende stap (bv. 'Facturen' bij oude weken) mag niet als 'vastgelopen' gelden;
 * een echt dode load (geen worker meer, geen activiteit) wel.
 * Run: php tests/load_progress_slow_step_test.php
 */

ini_set('error_log', sys_get_temp_dir() . '/demeter-load-slow-step-test.log');

$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x'];
$auth_list = ['Production' => $auth];

require_once __DIR__ . '/../web/odata.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$now = gmmktime(9, 5, 0, 10, 8, 2026); // 8 oktober 2026, 11:05 (CEST)
$lastStepAt = $now - 9 * 60;              // 10:56
$base = [
    'status' => 'running',
    'message' => 'Stap 208 van 220: week 2023-W05 laden: Facturen laden',
    'updated_at' => $lastStepAt,
    'progress_at' => $lastStepAt,
];

// 1) Scenario van Ariadne: 9 minuten geen stap, maar de week-request loopt nog (worker met recente beat).
$alive = $base + ['workers' => ['w1' => ['label' => 'week 2023-W05', 'started_at' => $lastStepAt, 'beat_at' => $now - 20]]];
$result = odata_load_progress_mark_stale($alive, $now);
check($result['status'] === 'running' && $result['stale'] === false, 'levende worker met recente heartbeat → niet vastgelopen');
check($result['in_flight'] === 1, 'in_flight telt de lopende request');
check($result['slow'] === false, 'heartbeat 20 s geleden → niet traag');

// 2) Worker leeft (binnen max. request-duur) maar meldt al minuten niets (zware PHP-stap): traag, niet vastgelopen.
$silent = $base + ['workers' => ['w1' => ['label' => 'week 2023-W05', 'started_at' => $lastStepAt, 'beat_at' => $lastStepAt]]];
$result = odata_load_progress_mark_stale($silent, $now);
check($result['status'] === 'running' && $result['stale'] === false, 'stille maar levende worker (9 min) → niet vastgelopen');
check($result['slow'] === true, 'stille worker → slow-vlag');
check($result['last_activity_text'] === '8 oktober 2026, 10:56', 'laatste activiteit in Nederlands formaat: ' . $result['last_activity_text']);

// 3) Worker is weg zonder af te melden (langer stil dan de maximale request-duur) → vastgelopen.
$deadAt = $now - DEMETER_LOAD_PROGRESS_WORKER_DEAD_SECONDS - 5;
$dead = array_merge($base, ['updated_at' => $deadAt, 'workers' => ['w1' => ['label' => 'x', 'started_at' => $deadAt, 'beat_at' => $deadAt]]]);
$result = odata_load_progress_mark_stale($dead, $now);
check($result['status'] === 'error' && $result['stale'] === true, 'dode worker → vastgelopen');
check(strpos($result['message'], 'Ververs Nu') !== false, 'melding noemt Ververs Nu');
check(preg_match('/\d{4}-\d{2}-\d{2}T/', $result['message']) !== 1, 'geen ISO-tijd in melding');
check(preg_match('/sinds \d{1,2} (januari|februari|maart|april|mei|juni|juli|augustus|september|oktober|november|december) \d{4}, \d{2}:\d{2}/', $result['message']) === 1, 'tijd als "8 oktober 2026, 10:5x": ' . $result['message']);

// 4) Geen worker (browser dicht tussen twee weken) en > drempel geen activiteit → vastgelopen; binnen drempel niet.
$noWorker = array_merge($base, ['updated_at' => $now - DEMETER_LOAD_PROGRESS_STALE_SECONDS - 5, 'workers' => []]);
check(odata_load_progress_mark_stale($noWorker, $now)['stale'] === true, 'geen worker + geen activiteit → vastgelopen');
$noWorkerFresh = array_merge($base, ['updated_at' => $now - 30, 'workers' => []]);
check(odata_load_progress_mark_stale($noWorkerFresh, $now)['stale'] === false, 'geen worker maar recente activiteit → niet vastgelopen');

// 5) Echte bestanden: worker aanmelden/afmelden en heartbeat; release_if_stale geeft een levende load niet vrij.
$token = str_repeat('cd', 16);
@unlink(odata_load_progress_path_for_token($token));
odata_load_progress_begin($token, 208);
odata_set_active_load_progress_token($token);
$workerId = odata_load_progress_worker_begin($token, 'week 2023-W05');
$payload = odata_load_progress_payload($token);
check($workerId !== '' && isset($payload['workers'][$workerId]), 'worker staat geregistreerd in de voortgang');

// Simuleer: laatste stap 9 minuten geleden, worker-beat ook oud maar binnen de request-duur.
$raw = json_decode((string) file_get_contents(odata_load_progress_path_for_token($token)), true);
$raw['updated_at'] = time() - 540;
$raw['workers'][$workerId]['beat_at'] = time() - 540;
file_put_contents(odata_load_progress_path_for_token($token), json_encode($raw));
check(odata_load_progress_payload($token)['stale'] === false, 'stille maar aangemelde worker → niet stale via bestand');
check(odata_load_progress_release_if_stale($token) === false, 'release_if_stale geeft levende load niet vrij');

odata_load_progress_heartbeat_throttled(true);
$payload = odata_load_progress_payload($token);
check(time() - (int) $payload['workers'][$workerId]['beat_at'] <= 2 && time() - (int) $payload['updated_at'] <= 2, 'heartbeat ververst worker-beat en updated_at');
check($payload['message'] === 'Voorbereiden...', 'heartbeat laat de staptekst ongemoeid');

odata_load_progress_worker_end();
$payload = odata_load_progress_payload($token);
check(!isset($payload['workers'][$workerId]), 'worker afgemeld na de request');

// Na afmelden + lange stilte: echt vastgelopen en vrijgegeven.
$raw = json_decode((string) file_get_contents(odata_load_progress_path_for_token($token)), true);
$raw['updated_at'] = time() - DEMETER_LOAD_PROGRESS_STALE_SECONDS - 5;
file_put_contents(odata_load_progress_path_for_token($token), json_encode($raw));
check(odata_load_progress_payload($token)['stale'] === true, 'na afmelden en stilte → stale');
check(odata_load_progress_release_if_stale($token) === true, 'stale load wordt vrijgegeven');

// 6) Twee parallelle workers: afmelden van de één laat de ander staan.
$GLOBALS['demeter_load_progress_worker_id'] = '';
odata_load_progress_begin($token, 8);
$first = odata_load_progress_worker_begin($token, 'week A');
$GLOBALS['demeter_load_progress_worker_id'] = '';
$second = odata_load_progress_worker_begin($token, 'week B');
$payload = odata_load_progress_payload($token);
check(isset($payload['workers'][$first], $payload['workers'][$second]), 'twee workers tegelijk geregistreerd');
odata_load_progress_worker_end();
$payload = odata_load_progress_payload($token);
check(isset($payload['workers'][$first]) && !isset($payload['workers'][$second]), 'afmelden van week B laat week A staan');

// 7) Stapbericht noemt een week 'week', geen 'maand'.
odata_load_progress_advance_month($token, 209, 220, '2023-W05: Facturen');
$payload = odata_load_progress_payload($token);
check($payload['message'] === 'Stap 209 van 220: week 2023-W05: Facturen laden', 'stapbericht: ' . $payload['message']);

@unlink(odata_load_progress_path_for_token($token));
@unlink(odata_load_progress_lock_path_for_token($token));

exit($failures > 0 ? 1 : 0);
