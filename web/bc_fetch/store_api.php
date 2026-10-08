<?php
/**
 * Read-only API op de werkorder-store (fase 1: shadow). Pure functies (auth, filters, response) zodat ze
 * testbaar zijn; het endpoint staat in web/api/workorders.php.
 */

require_once __DIR__ . '/store.php';

if (!defined('DEMETER_API_DEFAULT_LIMIT')) {
    define('DEMETER_API_DEFAULT_LIMIT', 500);
}
if (!defined('DEMETER_API_MAX_LIMIT')) {
    define('DEMETER_API_MAX_LIMIT', 5000);
}

/** Login-key van sleutels.kvt.nl (login/session_user.php): sha256(oid|d-m-Y, UTC). */
function demeter_api_login_key_for_date(string $oid, string $dateKey): string
{
    $oid = strtolower(trim($oid));

    return $oid === '' || $dateKey === '' ? '' : hash('sha256', $oid . '|' . $dateKey);
}

/**
 * Valideert de persoonlijke login-API-key zoals Asclepius dat doet (resolveLoginRotatingApiClient):
 * key = sha256(oid|vandaag) of sha256(oid|gisteren) (UTC), plus oid en e-mail; daarnaast moet het
 * e-mailadres in Demeters $allowedUsers staan (zelfde toegang als de webpagina).
 *
 * @return array{email:string,oid:string,kind:string}|null
 */
function demeter_api_resolve_login_key(string $providedKey, string $oid, string $email, array $allowedUsers, ?int $now = null): ?array
{
    $key = strtolower(trim($providedKey));
    $oid = strtolower(trim($oid));
    $email = strtolower(trim($email));
    if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1 || preg_match('/^[a-z0-9-]{8,128}$/', $oid) !== 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $now = $now ?? time();
    $today = demeter_api_login_key_for_date($oid, gmdate('d-m-Y', $now));
    $yesterday = demeter_api_login_key_for_date($oid, gmdate('d-m-Y', $now - 86400));
    if (!hash_equals($today, $key) && !hash_equals($yesterday, $key)) {
        return null;
    }
    if (!demeter_api_email_allowed($email, $allowedUsers)) {
        return null;
    }

    return ['email' => $email, 'oid' => $oid, 'kind' => 'login_key'];
}

function demeter_api_email_allowed(string $email, array $allowedUsers): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }
    foreach ($allowedUsers as $allowed) {
        if (is_string($allowed) && hash_equals(strtolower(trim($allowed)), $email)) {
            return true;
        }
    }

    return false;
}

/**
 * Bepaalt de aanvrager: login-key (header X-API-Key of api_key + X-User-Oid/oid + X-User-Email/user_email),
 * anders een ingelogde sessie (toegestane gebruiker), anders een vertrouwde aanvrager (localhost/server).
 *
 * @param array<string,mixed> $server $_SERVER
 * @param array<string,mixed> $query $_GET
 * @param array<string,mixed>|null $session $_SESSION['user'] of null
 * @return array{email:string,oid:string,kind:string}|null
 */
function demeter_api_authenticate(array $server, array $query, ?array $session, array $allowedUsers, ?int $now = null): ?array
{
    $key = trim((string) ($server['HTTP_X_API_KEY'] ?? ($query['api_key'] ?? '')));
    if ($key !== '') {
        $oid = (string) ($server['HTTP_X_USER_OID'] ?? ($query['oid'] ?? ''));
        $email = (string) ($server['HTTP_X_USER_EMAIL'] ?? ($query['user_email'] ?? ''));

        return demeter_api_resolve_login_key($key, $oid, $email, $allowedUsers, $now);
    }
    if (is_array($session)) {
        $email = strtolower(trim((string) ($session['email'] ?? '')));
        if (demeter_api_email_allowed($email, $allowedUsers)) {
            return ['email' => $email, 'oid' => strtolower(trim((string) ($session['oid'] ?? ''))), 'kind' => 'session'];
        }
    }
    $remote = (string) ($server['REMOTE_ADDR'] ?? '');
    $serverAddr = (string) ($server['SERVER_ADDR'] ?? '');
    if (($remote !== '' && $remote === $serverAddr) || in_array($remote, ['127.0.0.1', '::1'], true)) {
        return ['email' => '', 'oid' => '', 'kind' => 'trusted'];
    }

    return null;
}

/** @return list<string> */
function demeter_api_list_param($value): array
{
    if (is_array($value)) {
        $items = $value;
    } else {
        $items = explode(',', (string) $value);
    }
    $out = [];
    foreach ($items as $item) {
        $item = trim((string) $item);
        if ($item !== '') {
            $out[] = $item;
        }
    }

    return array_values(array_unique($out));
}

function demeter_api_valid_date(string $date): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $dt !== false && $dt->format('Y-m-d') === $date;
}

/**
 * Normaliseert en valideert queryparameters. Gooit InvalidArgumentException met een nette melding.
 *
 * @return array<string,mixed>
 */
function demeter_api_parse_filters(array $query): array
{
    $f = [
        'company' => trim((string) ($query['company'] ?? '')),
        'afdeling' => demeter_api_list_param($query['afdeling'] ?? ($query['cost_center'] ?? '')),
        'status' => array_map('demeter_store_status_canonical', demeter_api_list_param($query['status'] ?? '')),
        'open' => in_array((string) ($query['open'] ?? ''), ['1', 'true', 'ja'], true),
        'no' => demeter_api_list_param($query['no'] ?? ''),
        'job' => demeter_api_list_param($query['job'] ?? ''),
        'customer' => trim((string) ($query['customer'] ?? '')),
        'start_from' => trim((string) ($query['start_from'] ?? '')),
        'start_to' => trim((string) ($query['start_to'] ?? '')),
        'end_from' => trim((string) ($query['end_from'] ?? '')),
        'end_to' => trim((string) ($query['end_to'] ?? '')),
        'fresh' => (string) ($query['fresh'] ?? '') === '1',
    ];
    if ($f['company'] === '') {
        throw new InvalidArgumentException('Parameter company is verplicht.');
    }
    foreach (['start_from', 'start_to', 'end_from', 'end_to'] as $k) {
        if ($f[$k] !== '' && !demeter_api_valid_date($f[$k])) {
            throw new InvalidArgumentException('Parameter ' . $k . ' moet JJJJ-MM-DD zijn.');
        }
    }
    if (isset($query['afdeling']) && is_string($query['afdeling']) && trim($query['afdeling']) === '-') {
        $f['afdeling'] = [''];  // '-' = lege kop-afdeling
    }
    $limit = isset($query['limit']) && $query['limit'] !== '' ? $query['limit'] : DEMETER_API_DEFAULT_LIMIT;
    $offset = $query['offset'] ?? 0;
    if (!is_numeric($limit) || (int) $limit < 1 || !is_numeric($offset) || (int) $offset < 0) {
        throw new InvalidArgumentException('limit moet ≥ 1 zijn en offset ≥ 0.');
    }
    $f['limit'] = min((int) $limit, DEMETER_API_MAX_LIMIT);
    $f['offset'] = (int) $offset;
    if (count($f['no']) > 1000) {
        throw new InvalidArgumentException('Maximaal 1000 werkordernummers per aanvraag.');
    }

    return $f;
}

function demeter_api_row_matches(array $row, array $f): bool
{
    if ($f['afdeling'] !== [] && !in_array((string) $row['Job_Dimension_1_Value'], $f['afdeling'], true)) {
        return false;
    }
    $status = demeter_store_status_canonical((string) $row['Status']);
    if ($f['status'] !== [] && !in_array($status, $f['status'], true)) {
        return false;
    }
    if ($f['open'] && demeter_store_status_is_closed($status)) {
        return false;
    }
    if ($f['no'] !== [] && !in_array((string) $row['No'], $f['no'], true)) {
        return false;
    }
    if ($f['job'] !== [] && !in_array((string) $row['Job_No'], $f['job'], true)) {
        return false;
    }
    if ($f['customer'] !== '') {
        $needle = mb_strtolower($f['customer']);
        $hay = [(string) $row['Sell_to_Customer_No'], (string) $row['Bill_to_Customer_No'], (string) $row['Sell_to_Name'], (string) $row['Bill_to_Name']];
        $hit = false;
        foreach ($hay as $i => $h) {
            $h = mb_strtolower($h);
            if (($i < 2 && $h === $needle) || ($i >= 2 && $needle !== '' && mb_strpos($h, $needle) !== false)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return false;
        }
    }
    $sd = (string) $row['Start_Date'];
    if ($f['start_from'] !== '' && strcmp($sd, $f['start_from']) < 0) {
        return false;
    }
    if ($f['start_to'] !== '' && strcmp($sd, $f['start_to']) > 0) {
        return false;
    }
    $ed = (string) $row['End_Date'];
    if ($f['end_from'] !== '' && strcmp($ed, $f['end_from']) < 0) {
        return false;
    }
    if ($f['end_to'] !== '' && strcmp($ed, $f['end_to']) > 0) {
        return false;
    }

    return true;
}

function demeter_api_iso(int $ts): ?string
{
    return $ts > 0 ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
}

/** Bouwt de JSON-response (zonder I/O) uit een store en filters. */
function demeter_api_build_response(array $store, array $f, ?array $syncInfo = null): array
{
    $matches = [];
    foreach ($store['workorders'] as $no => $row) {
        if (demeter_api_row_matches($row, $f)) {
            $matches[] = (string) $no;
        }
    }
    sort($matches, SORT_NATURAL);
    $page = array_slice($matches, $f['offset'], $f['limit']);
    $rows = [];
    foreach ($page as $no) {
        $row = $store['workorders'][$no];
        $row['Is_Closed'] = demeter_store_status_is_closed((string) $row['Status']);
        $row['Costs'] = demeter_store_finance_for($store, $no);
        $rows[] = $row;
    }
    $rec = is_array($store['last_reconciliation'] ?? null) ? $store['last_reconciliation'] : null;
    $recSummary = null;
    if ($rec !== null) {
        $per = [];
        foreach ((array) ($rec['per_afdeling'] ?? []) as $afd => $p) {
            $per[(string) $afd] = ['bc' => (int) ($p['bc'] ?? 0), 'store' => (int) ($p['store'] ?? 0),
                'diffs' => (int) ($p['missing'] ?? 0) + (int) ($p['extra'] ?? 0) + (int) ($p['changed'] ?? 0) + (int) ($p['finance'] ?? 0)];
        }
        $recSummary = ['at' => demeter_api_iso((int) ($rec['at'] ?? 0)), 'total_diffs' => (int) ($rec['total_diffs'] ?? 0), 'per_afdeling' => $per];
    }
    $ver = is_array($store['last_verification'] ?? null) ? $store['last_verification'] : null;

    return [
        'ok' => true,
        'company' => (string) ($store['company'] ?? $f['company']),
        'shadow' => !empty($store['shadow']) || !isset($store['shadow']),
        'synced_at' => demeter_api_iso((int) ($store['synced_at'] ?? 0)),
        'full_snapshot_at' => demeter_api_iso((int) ($store['full_at'] ?? 0)),
        'age_seconds' => max(0, time() - (int) ($store['synced_at'] ?? 0)),
        'sync' => $syncInfo,
        'last_reconciliation' => $recSummary,
        'last_verification' => $ver === null ? null : ['ok' => !empty($ver['ok']), 'bc_total' => (int) ($ver['bc_total'] ?? 0), 'store_total' => (int) ($ver['store_total'] ?? 0)],
        'total' => count($matches),
        'limit' => $f['limit'],
        'offset' => $f['offset'],
        'rows' => $rows,
    ];
}
