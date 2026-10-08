<?php
/**
 * GET /api/workorders.php — read-only werkorders uit de Demeter-store (fase 1: shadow).
 * Leest alleen de store; pollt BC NOOIT, behalve met fresh=1 (begrensde delta-sync onder lock, alleen als
 * de store ouder is dan 180 s). Zie README "Werkorder-API".
 */

define('DEMETER_SKIP_LOGINCHECK_AUTO', true);
if (!defined('DEMETER_ODATA_MAX_EXECUTION_SECONDS')) {
    define('DEMETER_ODATA_MAX_EXECUTION_SECONDS', 60);
}
@ini_set('display_errors', '0');

$webDir = dirname(__DIR__);
require_once $webDir . '/bc_fetch/store_api.php';

function demeter_api_send(int $code, array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('Demeter API: ' . $e->getMessage());
    demeter_api_send(500, ['ok' => false, 'error' => 'Interne fout']);
});

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    demeter_api_send(405, ['ok' => false, 'error' => 'Alleen GET']);
}

// auth.php levert $allowedUsers (en BC-config voor fresh=1). Uitvoer ervan nooit doorgeven.
ob_start();
require $webDir . '/auth.php';
ob_end_clean();
$allowedUsersList = isset($allowedUsers) && is_array($allowedUsers) ? $allowedUsers : [];

// Sessie (ingelogd op sleutels.kvt.nl) alleen lezen als er een sessiecookie is.
$sessionUser = null;
foreach ([$webDir . '/../../login/session_config.php', $webDir . '/../login/session_config.php'] as $sessionConfig) {
    if (is_file($sessionConfig)) {
        require_once $sessionConfig;
        if (function_exists('configure_app_session')) {
            configure_app_session();
        }
        break;
    }
}
if (session_status() !== PHP_SESSION_ACTIVE && session_name() !== '' && !empty($_COOKIE[session_name()])) {
    @session_start(['read_and_close' => true]);
}
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $sessionUser = $_SESSION['user'];
}

$client = demeter_api_authenticate($_SERVER, $_GET, $sessionUser, $allowedUsersList);
if ($client === null) {
    demeter_api_send(401, ['ok' => false, 'error' => 'Niet geautoriseerd: geldige login-API-key (X-API-Key + X-User-Oid + X-User-Email) of sessie vereist']);
}

try {
    $filters = demeter_api_parse_filters($_GET);
} catch (InvalidArgumentException $e) {
    demeter_api_send(400, ['ok' => false, 'error' => $e->getMessage()]);
}

$store = demeter_store_read($filters['company']);
if ($store === null) {
    demeter_api_send(404, ['ok' => false, 'error' => 'Nog geen werkorder-store voor dit bedrijf (wordt door nightly gebouwd)']);
}

$syncInfo = null;
if ($filters['fresh'] && time() - (int) ($store['synced_at'] ?? 0) > DEMETER_STORE_MAX_AGE_SECONDS) {
    try {
        require_once $webDir . '/odata.php';
        require_once $webDir . '/bc_fetch/store_transport.php';
        $afdeling = $filters['afdeling'][0] ?? '';
        $result = demeter_store_page_open_sync($filters['company'], $afdeling, demeter_store_live_transport($filters['company']), 10.0);
        if (is_array($result['store'] ?? null)) {
            $store = $result['store'];
        }
        $syncInfo = ['status' => $result['status'], 'duration_ms' => $result['stats']['duration_ms'] ?? null];
    } catch (Throwable $e) {
        error_log('Demeter API fresh-sync: ' . $e->getMessage());
        $syncInfo = ['status' => 'error', 'error' => 'Verversen mislukt; store-data geserveerd'];
    }
}

demeter_api_send(200, demeter_api_build_response($store, $filters, $syncInfo));
