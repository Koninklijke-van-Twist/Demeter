<?php
/**
 * Werkorder-API (read-only op de store): auth (login-key/sessie/vertrouwd/401) en filters.
 * Run: php tests/store_api_test.php
 */

require_once __DIR__ . '/../web/bc_fetch/store_api.php';

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

$allowed = ['linda@kvt.nl', 'Tim@KVT.nl'];
$oid = '0f1e2d3c-aaaa-bbbb-cccc-1234567890ab';
$now = strtotime('2026-10-08 12:00:00 UTC');
$keyToday = hash('sha256', $oid . '|08-10-2026');
$keyYesterday = hash('sha256', $oid . '|07-10-2026');
$keyOld = hash('sha256', $oid . '|05-10-2026');
$remote = ['REMOTE_ADDR' => '10.1.2.3', 'SERVER_ADDR' => '10.9.9.9'];

// Auth
$c = demeter_api_authenticate($remote + ['HTTP_X_API_KEY' => $keyToday, 'HTTP_X_USER_OID' => $oid, 'HTTP_X_USER_EMAIL' => 'linda@kvt.nl'], [], null, $allowed, $now);
check(is_array($c) && $c['kind'] === 'login_key' && $c['email'] === 'linda@kvt.nl', 'login-key van vandaag + oid + e-mail (headers) geaccepteerd');
check(is_array(demeter_api_authenticate($remote, ['api_key' => $keyYesterday, 'oid' => $oid, 'user_email' => 'tim@kvt.nl'], null, $allowed, $now)), 'key van gisteren via query geaccepteerd; e-mail case-insensitive');
check(demeter_api_authenticate($remote + ['HTTP_X_API_KEY' => $keyOld, 'HTTP_X_USER_OID' => $oid, 'HTTP_X_USER_EMAIL' => 'linda@kvt.nl'], [], null, $allowed, $now) === null, 'verlopen key (3 dagen oud) → 401');
check(demeter_api_authenticate($remote + ['HTTP_X_API_KEY' => $keyToday, 'HTTP_X_USER_OID' => $oid, 'HTTP_X_USER_EMAIL' => 'vreemde@kvt.nl'], [], null, $allowed, $now) === null, 'geldige key maar gebruiker niet in allowedUsers → 401');
check(demeter_api_authenticate($remote + ['HTTP_X_API_KEY' => $keyToday, 'HTTP_X_USER_OID' => 'ander-oid-1234', 'HTTP_X_USER_EMAIL' => 'linda@kvt.nl'], [], null, $allowed, $now) === null, 'key hoort niet bij dit oid → 401');
check(demeter_api_authenticate($remote + ['HTTP_X_API_KEY' => 'abc'], [], null, $allowed, $now) === null, 'onzin-key → 401');
check(demeter_api_authenticate($remote + ['HTTP_X_API_KEY' => $keyToday, 'HTTP_X_USER_OID' => $oid, 'HTTP_X_USER_EMAIL' => 'linda@kvt.nl'], [], ['email' => 'tim@kvt.nl'], $allowed, $now + 5 * 86400) === null, 'ongeldige key wint niet van sessie (expliciete key moet kloppen)');
check(demeter_api_authenticate($remote, [], null, $allowed, $now) === null, 'geen key, geen sessie, niet lokaal → 401');
$s = demeter_api_authenticate($remote, [], ['email' => 'linda@kvt.nl', 'oid' => $oid], $allowed, $now);
check(is_array($s) && $s['kind'] === 'session', 'ingelogde sessie (toegestane gebruiker) geaccepteerd');
check(demeter_api_authenticate($remote, [], ['email' => 'iemand@kvt.nl'], $allowed, $now) === null, 'sessie van niet-toegestane gebruiker → 401');
check((demeter_api_authenticate(['REMOTE_ADDR' => '127.0.0.1'], [], null, $allowed, $now)['kind'] ?? '') === 'trusted', 'localhost (server zelf) = vertrouwd');

// Filters
$store = demeter_store_empty('KvT');
$mk = static function (string $no, string $afd, string $status, string $start, string $end, string $cust, string $job) use (&$store): void {
    demeter_store_upsert($store, ['No' => $no, 'Job_Dimension_1_Value' => $afd, 'Status' => $status, 'Start_Date' => $start, 'End_Date' => $end,
        'Sell_to_Customer_No' => $cust, 'Sell_to_Name' => 'Klant ' . $cust . ' BV', 'Bill_to_Customer_No' => $cust, 'Bill_to_Name' => '', 'Job_No' => $job]);
};
$mk('WO1', '70', 'Open', '2026-10-01', '2026-10-02', '30051', 'PRJ1');
$mk('WO2', '70', 'Checked', '2026-09-01', '2026-09-05', '30052', 'PRJ1');
$mk('WO3', '70', 'Invoiced', '2026-08-01', '2026-08-01', '30051', 'PRJ2');
$mk('WO4', '50', 'Gefactureerd', '2026-10-03', '2026-10-03', '30053', 'PRJ3');
$mk('WO5', '', 'Signed', '0001-01-01', '0001-01-01', '30051', 'PRJ4');
demeter_store_apply_posting($store, ['Entry_No' => 1, 'LVS_Work_Order_No' => 'WO1', 'Entry_Type' => 'Usage', 'Total_Cost' => 12.5, 'Line_Amount_LCY' => 20]);
$store['synced_at'] = time() - 30;
$store['last_reconciliation'] = ['at' => time(), 'total_diffs' => 0, 'per_afdeling' => ['70' => ['bc' => 3, 'store' => 3, 'missing' => 0, 'extra' => 0, 'changed' => 0, 'finance' => 0]]];

$nos = static function (array $q) use ($store): array {
    return array_column(demeter_api_build_response($store, demeter_api_parse_filters($q + ['company' => 'KvT']))['rows'], 'No');
};
check($nos([]) === ['WO1', 'WO2', 'WO3', 'WO4', 'WO5'], 'zonder filters: alle werkorders, natuurlijk gesorteerd');
check($nos(['afdeling' => '70']) === ['WO1', 'WO2', 'WO3'], 'afdeling=70');
check($nos(['afdeling' => '-']) === ['WO5'], "afdeling=- (lege kop)");
check($nos(['afdeling' => '70,50']) === ['WO1', 'WO2', 'WO3', 'WO4'], 'meerdere afdelingen');
check($nos(['status' => 'Invoiced']) === ['WO3', 'WO4'], 'status=Invoiced matcht ook NL-caption Gefactureerd');
check($nos(['status' => 'open,checked']) === ['WO1', 'WO2'], 'meerdere statussen, case-insensitive');
check($nos(['open' => '1']) === ['WO1', 'WO2', 'WO5'], 'open=1: alles behalve Closed/Completed/Cancelled/Invoiced (Checked/Signed open)');
check($nos(['start_from' => '2026-09-01', 'start_to' => '2026-10-01']) === ['WO1', 'WO2'], 'startdatum-bereik');
check($nos(['end_to' => '2026-08-31']) === ['WO3', 'WO5'], 'einddatum t/m');
check($nos(['no' => 'WO4,WO1']) === ['WO1', 'WO4'], 'werkordernummer-lijst');
check($nos(['customer' => '30051']) === ['WO1', 'WO3', 'WO5'], 'klantnummer');
check($nos(['customer' => 'klant 30053']) === ['WO4'], 'klantnaam (deel, case-insensitive)');
check($nos(['job' => 'PRJ1']) === ['WO1', 'WO2'], 'project');
check($nos(['limit' => '2', 'offset' => '1']) === ['WO2', 'WO3'], 'paginering limit/offset');
$resp = demeter_api_build_response($store, demeter_api_parse_filters(['company' => 'KvT', 'no' => 'WO1', 'limit' => '999999']));
check($resp['limit'] === DEMETER_API_MAX_LIMIT, 'limit begrensd op maximum');
check($resp['rows'][0]['Costs']['cost'] === 12.5 && $resp['rows'][0]['Is_Closed'] === false, 'rij bevat kosten en Is_Closed');
check($resp['shadow'] === true && $resp['last_reconciliation']['per_afdeling']['70']['bc'] === 3 && is_string($resp['synced_at']) && $resp['total'] === 1, 'metadata: shadow, synced_at, reconciliatie BC/store, total');
$bad = 0;
foreach ([[], ['company' => 'KvT', 'start_from' => '08-10-2026'], ['company' => 'KvT', 'limit' => '0'], ['company' => 'KvT', 'offset' => '-1']] as $q) {
    try {
        demeter_api_parse_filters($q);
    } catch (InvalidArgumentException $e) {
        $bad++;
    }
}
check($bad === 4, 'ongeldige parameters → nette 400-melding');
check(strpos(json_encode($resp), 'pass') === false, 'response bevat geen credentials');

exit($failures === 0 ? 0 : 1);
