<?php
// Duur van de laatste 10 voltooide Ververs Nu-runs (per bedrijf + kostenplaats) en het gemiddelde voor de modal.
// Run: php tests/refresh_durations_test.php
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'x']];
require_once __DIR__ . '/../web/odata.php';
require_once __DIR__ . '/../web/bc_fetch/active_load.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}
function tok(): string { return bin2hex(random_bytes(16)); }
function fake_start(string $company, string $cc, string $token, string $kind, int $ago): void
{
    demeter_active_load_claim($company, $cc, $token, $kind);
    $path = demeter_active_load_path($company, $cc);
    $e = json_decode((string) file_get_contents($path), true);
    $e['started_at'] = time() - $ago;
    file_put_contents($path, json_encode($e));
}

$company = 'DurTest' . getmypid();
$cc = '70';
check(demeter_refresh_durations_average_minutes($company, $cc) === null, 'nog geen metingen: null');

$t = tok();
fake_start($company, $cc, $t, 'refresh', 180);
demeter_active_load_complete_by_token($t);
demeter_active_load_complete_by_token($t); // meelifter/dubbele melding: niet nog eens tellen
check(demeter_refresh_durations_read($company, $cc) === [180] || abs(demeter_refresh_durations_read($company, $cc)[0] - 180) <= 1, 'voltooide run gemeten (180 s), één keer');

$t = tok();
fake_start($company, $cc, $t, 'catch_up', 999);
demeter_active_load_complete_by_token($t);
$t = tok();
fake_start($company, $cc, $t, 'refresh', 999);
demeter_active_load_error_by_token($t);
check(count(demeter_refresh_durations_read($company, $cc)) === 1, 'catch-up en afgebroken run tellen niet mee');

for ($i = 0; $i < 12; $i++) {
    demeter_refresh_durations_record($company, $cc, 300);
}
check(count(demeter_refresh_durations_read($company, $cc)) === 10, 'alleen de laatste 10');
check(demeter_refresh_durations_average_minutes($company, $cc) === 5, 'gemiddelde 5 minuten');
check(demeter_refresh_durations_average_minutes($company, '71') === null, 'per kostenplaats apart');

$p = demeter_refresh_durations_path($company, $cc);
@unlink($p);
@unlink($p . '.lock');
@unlink(demeter_active_load_path($company, $cc));
exit($failures > 0 ? 1 : 0);
