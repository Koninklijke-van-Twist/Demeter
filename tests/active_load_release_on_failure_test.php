<?php
// Hotfix 8 okt 2026: na een mislukte verversing (report_load_failure) bleef de claim 3-5 minuten 'running',
// waardoor elke herlaad 'Er loopt al een verversing' gaf en op de dode load meeliftte.
// Run: php tests/active_load_release_on_failure_test.php

$tmp = sys_get_temp_dir() . '/demeter_active_load_release_' . getmypid();
@mkdir($tmp, 0777, true);
function load_progress_base_dir(): string { return $GLOBALS['tmp']; }

require_once __DIR__ . '/../web/bc_fetch/active_load.php';

$fail = 0;
function check(bool $ok, string $label): void { global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }

$company = 'Koninklijke van Twist';
$tokenA = str_repeat('a', 32);
$tokenB = str_repeat('b', 32);
$tokenC = str_repeat('c', 32);

$claim = demeter_active_load_claim($company, '15', $tokenA, 'refresh');
check($claim['adopted'] === false, 'eerste claim is van tab A');
check(demeter_active_load_is_fresh_running(demeter_active_load_get($company, '15')), 'claim A loopt');
$adopt = demeter_active_load_claim($company, '15', $tokenB, 'refresh');
check($adopt['adopted'] === true, 'zonder vrijgave lift tab B mee op de (dode) claim A');

check(demeter_active_load_release_by_token($tokenC) === false, 'vreemd token geeft niets vrij');
check(demeter_active_load_is_fresh_running(demeter_active_load_get($company, '15')), 'claim A loopt nog na vreemd token');

check(demeter_active_load_release_by_token($tokenA) === true, 'report_load_failure geeft claim A vrij');
check(demeter_active_load_get($company, '15') === null, 'geen actieve load meer na vrijgave');
$claim2 = demeter_active_load_claim($company, '15', $tokenB, 'refresh');
check($claim2['adopted'] === false && $claim2['entry']['token'] === $tokenB, 'herlaad/Ververs Nu kan direct een nieuwe load starten');

// Andere kostenplaats onaangetast.
demeter_active_load_claim($company, '70', $tokenC, 'refresh');
demeter_active_load_release_by_token($tokenB);
check(demeter_active_load_is_fresh_running(demeter_active_load_get($company, '70')), 'vrijgave van 15 raakt 70 niet');

$src = file_get_contents(__DIR__ . '/../web/index.php');
$pos = strpos($src, "=== 'report_load_failure'");
$end = strpos($src, "demeter_send_json_response(['ok' => true]);", (int) $pos);
check($pos !== false && $end !== false && strpos(substr($src, $pos, $end - $pos), 'demeter_active_load_release_by_token($failToken)') !== false,
    'report_load_failure-endpoint roept de vrijgave aan');

array_map('unlink', glob($tmp . '/active/*') ?: []);
@rmdir($tmp . '/active');
@rmdir($tmp);
exit($fail > 0 ? 1 : 0);
