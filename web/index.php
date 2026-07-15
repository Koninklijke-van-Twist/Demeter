<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined('DEMETER_ODATA_MAX_EXECUTION_SECONDS')) {
    define('DEMETER_ODATA_MAX_EXECUTION_SECONDS', 600);
}

@ini_set('max_execution_time', (string) DEMETER_ODATA_MAX_EXECUTION_SECONDS);
if (function_exists('set_time_limit')) {
    @set_time_limit(DEMETER_ODATA_MAX_EXECUTION_SECONDS);
}

$second = 1;
$minute = $second * 60;
$hour = $minute * 60;
$day = $hour * 24;

$ttl = $hour * 12;

function demeter_render_progress_screen_html(string $title, string $statusUrl, string $message, string $callPrefix, ?string $redirectUrl = null, int $redirectDelayMs = 0, string $note = ''): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeNote = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');

    $statusUrlJson = json_encode($statusUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $callPrefixJson = json_encode($callPrefix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $redirectUrlJson = json_encode($redirectUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $redirectDelayJson = json_encode(max(0, $redirectDelayMs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $safeTitle . '</title>'
        . '<style>'
        . 'body{font-family:Verdana,Geneva,Tahoma,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f7fb;color:#1f2937}'
        . '.card{background:#fff;border:1px solid #dbe3ee;border-radius:12px;padding:24px;box-shadow:0 2px 10px rgba(15,23,42,.06);max-width:560px;width:calc(100% - 32px)}'
        . '.spinner{width:38px;height:38px;border:4px solid #c8d3e1;border-top-color:#1f4ea6;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 14px}'
        . '.pct{font-size:34px;font-weight:700;color:#1f4ea6;margin:0 0 6px;transition:color .25s ease}'
        . '.txt{font-size:15px;color:#334155;margin:0 0 8px}'
        . '.bar{height:12px;background:#dbe3ee;border-radius:999px;overflow:hidden;margin:8px 0 10px}'
        . '.barFill{height:100%;width:0;background:#1f4ea6;border-radius:999px;transition:width .5s ease,background-color .35s ease}'
        . '.call{font-size:13px;color:#64748b;min-height:18px;margin:0}'
        . 'small{display:block;color:#64748b;margin-top:10px}'
        . '@keyframes spin{to{transform:rotate(360deg)}}'
        . '</style></head><body>'
        . '<div class="card" id="progressCard">'
        . '<div class="spinner" aria-hidden="true"></div>'
        . '<div class="pct" id="progressPct">0%</div>'
        . '<p class="txt" id="progressText"></p>'
        . '<div class="bar"><div class="barFill" id="progressBarFill"></div></div>'
        . '<p class="call" id="progressCall"></p>'
        . ($safeNote !== '' ? '<small>' . $safeNote . '</small>' : '')
        . '</div>'
        . '<script>(function(){'
        . 'var statusUrl=' . $statusUrlJson . ';'
        . 'var defaultMessage=' . $messageJson . ';'
        . 'var callPrefix=' . $callPrefixJson . ';'
        . 'var redirectUrl=' . $redirectUrlJson . ';'
        . 'var redirectDelay=' . $redirectDelayJson . ';'
        . 'var spinnerEl=document.querySelector(".spinner");'
        . 'var pctEl=document.getElementById("progressPct");'
        . 'var textEl=document.getElementById("progressText");'
        . 'var callEl=document.getElementById("progressCall");'
        . 'var barFillEl=document.getElementById("progressBarFill");'
        . 'var cardEl=document.getElementById("progressCard");'
        . 'var noteEl=cardEl?cardEl.querySelector("small"):null;'
        . 'var wobbleTargets=[pctEl,textEl,barFillEl,callEl,noteEl].filter(function(el){return !!el;});'
        . 'var shakeTimer=0;'
        . 'var wobbleTimer=0;'
        . 'function clamp(v,min,max){return Math.max(min,Math.min(max,v));}'
        . 'function colorForPercent(percent){var p=clamp(percent,0,100);if(p<80){return "hsl(215, 78%, 42%)";}var t=(p-80)/20;var hue=215*(1-t);return "hsl("+hue+", 78%, 46%)";}'
        . 'function shakeIntensity(percent){var p=clamp(percent,0,100);if(p<80){return 0;}return (p-80)/20;}'
        . 'function elementShakeIntensity(percent){var p=clamp(percent,0,100);if(p<95){return 0;}return (p-95)/5;}'
        . 'function resetElementWobble(){wobbleTargets.forEach(function(el){el.style.transform="translate3d(0,0,0) rotate(0deg)";});}'
        . 'function spinnerDurationForPercent(percent){var ix=elementShakeIntensity(percent);var base=0.8;var speedFactor=1+(ix*9);return (base/speedFactor).toFixed(3)+"s";}'
        . 'function startShake(intensity){if(!cardEl){return;}if(intensity<=0){if(shakeTimer){window.clearInterval(shakeTimer);shakeTimer=0;}cardEl.style.transform="translate3d(0,0,0) rotate(0deg)";return;}if(shakeTimer){return;}shakeTimer=window.setInterval(function(){var ix=shakeIntensity(window.__demeterProgressPercent||0);if(ix<=0){cardEl.style.transform="translate3d(0,0,0) rotate(0deg)";return;}var amp=0.35+(ix*8);var rot=(Math.random()*2-1)*(0.2+ix*1.8);var x=(Math.random()*2-1)*amp;var y=(Math.random()*2-1)*(amp*0.6);cardEl.style.transform="translate3d("+x+"px,"+y+"px,0) rotate("+rot.toFixed(3)+"deg)";},45);}'
        . 'function startElementWobble(){if(wobbleTargets.length===0){return;}if(wobbleTimer){return;}wobbleTimer=window.setInterval(function(){var ix=elementShakeIntensity(window.__demeterProgressPercent||0);if(ix<=0){resetElementWobble();return;}wobbleTargets.forEach(function(el){var amp=0.2+(ix*3.8);var rot=(Math.random()*2-1)*(0.15+ix*2.2);var x=(Math.random()*2-1)*amp;var y=(Math.random()*2-1)*(amp*0.7);el.style.transform="translate3d("+x.toFixed(2)+"px,"+y.toFixed(2)+"px,0) rotate("+rot.toFixed(3)+"deg)";});},38);}'
        . 'function stopElementWobble(){if(wobbleTimer){window.clearInterval(wobbleTimer);wobbleTimer=0;}resetElementWobble();}'
        . 'function render(p){if(!p||typeof p!=="object"){return;}var total=Number(p.total_months||0);var current=Number(p.current_month_index||0);var percent=total>0?Math.round((current/total)*100):0;percent=clamp(percent,0,100);window.__demeterProgressPercent=percent;var color=colorForPercent(percent);if(spinnerEl){spinnerEl.style.animationDuration=spinnerDurationForPercent(percent);}if(pctEl){pctEl.textContent=percent+"%";pctEl.style.color=color;}if(barFillEl){barFillEl.style.width=percent+"%";barFillEl.style.backgroundColor=color;}var msg=String(p.message||"").trim();if(msg===""){msg=defaultMessage;}if(textEl){textEl.textContent=msg;}if(callEl){var call=String(p.current_call_label||"").trim();callEl.textContent=call!==""?callPrefix+call:"";}startShake(shakeIntensity(percent));if(elementShakeIntensity(percent)>0){startElementWobble();}else{stopElementWobble();}}'
        . 'async function poll(){try{var separator=statusUrl.indexOf("?")===-1?"?":"&";var r=await fetch(statusUrl+separator+"_t="+Date.now(),{headers:{Accept:"application/json"},credentials:"same-origin",cache:"no-store"});if(!r.ok){return;}var p=await r.json();render(p);}catch(e){}}'
        . 'if(textEl){textEl.textContent=defaultMessage;}'
        . 'poll();window.setInterval(poll,700);'
        . 'if(redirectUrl&&typeof redirectUrl==="string"&&redirectUrl!==""&&redirectDelay>0){window.setTimeout(function(){window.location.replace(redirectUrl);},redirectDelay);}'
        . '})();</script></body></html>';
}

ob_start();
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || ($error['type'] ?? 0) !== E_ERROR) {
        return;
    }

    $message = (string) ($error['message'] ?? '');
    $isTimeout = stripos($message, 'Maximum execution time') !== false
        && stripos($message, 'second') !== false;

    if (!$isTimeout) {
        return;
    }

    $timedOutToken = trim((string) ($GLOBALS['demeter_active_load_progress_token'] ?? ''));
    $timeoutReloadUri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $timeoutBasePath = $timeoutReloadUri !== '' ? $timeoutReloadUri : 'index.php';
    $timeoutQuery = [];
    parse_str((string) parse_url($timeoutBasePath, PHP_URL_QUERY), $timeoutQuery);
    if (!is_array($timeoutQuery)) {
        $timeoutQuery = [];
    }

    if ($timedOutToken !== '') {
        $timeoutQuery['load_token'] = $timedOutToken;
    }
    $timeoutQuery['boot'] = '1';
    $timeoutReloadPath = (string) parse_url($timeoutBasePath, PHP_URL_PATH);
    if ($timeoutReloadPath === '') {
        $timeoutReloadPath = 'index.php';
    }
    $timeoutReloadPath .= '?' . http_build_query($timeoutQuery, '', '&', PHP_QUERY_RFC3986);
    $timeoutStatusUrl = 'odata.php?action=load_progress&token=' . rawurlencode($timedOutToken);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $refreshUrl = htmlspecialchars($timeoutReloadPath, ENT_QUOTES, 'UTF-8');
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 5');

    echo demeter_render_progress_screen_html(
        'Even geduld',
        $timeoutStatusUrl,
        'Gegevens laden...',
        'Huidige OData-call: ',
        $timeoutReloadPath,
        5000,
        'Er is meer tijd nodig om gegevens te laden. De pagina wordt automatisch vernieuwd...'
    );
});

require __DIR__ . "/auth.php";
require_once __DIR__ . "/auth_helper.php";
require_once __DIR__ . "/logincheck.php";
demeter_release_session_lock_if_active();
require_once __DIR__ . "/odata.php";
require_once __DIR__ . "/project_finance.php";
require_once __DIR__ . "/bc_fetch/month_loader.php";
require_once __DIR__ . "/bc_fetch/cost_centers.php";
require_once __DIR__ . "/bc_fetch/reference_cache.php";
require_once __DIR__ . "/bc_fetch/nightly_runner.php";
require_once __DIR__ . "/workorder_rows.php";

function current_user_email_or_fallback(): string
{
    if (isset($_SESSION) && is_array($_SESSION)) {
        $sessionUser = $_SESSION['user'] ?? null;
        if (is_array($sessionUser)) {
            $email = trim((string) ($sessionUser['email'] ?? ''));
            if ($email !== '') {
                return $email;
            }
        }
    }

    return 'ict@kvt.nl';
}

function demeter_release_session_lock_if_active(): void
{
    if (!function_exists('session_status') || !function_exists('session_write_close')) {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}

function demeter_store_selected_company_context(string $company, string $environment): void
{
    if (!function_exists('session_status') || !function_exists('session_start') || !function_exists('session_write_close')) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['demeter_selected_company'] = $company;
    $_SESSION['demeter_selected_environment'] = $environment;
    @session_write_close();
}

function memo_setting_keys(): array
{
    return [
        'Memo_KVT_Memo',
        'Memo_KVT_Memo_Internal_Use_Only',
        'Memo_KVT_Memo_Invoice',
        'Memo_KVT_Memo_Billing_Details',
        'Memo_KVT_Remarks_Invoicing',
    ];
}

function normalize_layout_style(mixed $value): string
{
    $normalized = strtolower(trim((string) $value));
    if ($normalized === 'projectgroups') {
        return 'projectgroups';
    }

    return 'table';
}

function default_layout_style(): string
{
    return 'table';
}

function normalize_keep_project_workorders_together(mixed $value): bool
{
    if ($value === null) {
        return true;
    }

    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['0', 'false', 'nee', 'no', 'off'], true)) {
        return false;
    }

    return true;
}

function default_keep_project_workorders_together(): bool
{
    return true;
}

function usersettings_file_path_for_email(string $email): string
{
    $safeEmail = preg_replace('/[^a-z0-9@._-]/i', '_', strtolower(trim($email)));
    if (!is_string($safeEmail) || trim($safeEmail) === '') {
        $safeEmail = 'ict@kvt.nl';
    }

    return __DIR__ . '/cache/usersettings/' . $safeEmail . '.txt';
}

function default_memo_column_settings(): array
{
    $settings = [];
    foreach (memo_setting_keys() as $key) {
        $settings[$key] = true;
    }

    return $settings;
}

function load_user_settings_payload(string $email): array
{
    $path = usersettings_file_path_for_email($email);
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return [];
    }

    return $parsed;
}

function load_memo_column_settings(string $email): array
{
    $defaults = default_memo_column_settings();
    $parsed = load_user_settings_payload($email);
    if ($parsed === []) {
        return $defaults;
    }

    $configured = $parsed['memo_columns'] ?? null;
    if (!is_array($configured)) {
        return $defaults;
    }

    $merged = $defaults;
    foreach ($merged as $key => $value) {
        if (array_key_exists($key, $configured)) {
            $merged[$key] = (bool) $configured[$key];
        }
    }

    return $merged;
}

function load_layout_style_setting(string $email): string
{
    $parsed = load_user_settings_payload($email);
    if ($parsed === []) {
        return default_layout_style();
    }

    return normalize_layout_style($parsed['layout_style'] ?? null);
}

function load_keep_project_workorders_together_setting(string $email): bool
{
    $parsed = load_user_settings_payload($email);
    if ($parsed === []) {
        return default_keep_project_workorders_together();
    }

    return normalize_keep_project_workorders_together($parsed['keep_project_workorders_together'] ?? null);
}

function normalize_memo_column_settings(array $input): array
{
    $normalized = default_memo_column_settings();
    foreach ($normalized as $key => $value) {
        if (array_key_exists($key, $input)) {
            $normalized[$key] = (bool) $input[$key];
        }
    }

    return $normalized;
}

function save_user_settings(string $email, ?array $memoColumns, ?string $layoutStyle, mixed $keepProjectWorkordersTogether, ?string $costCenter = null): bool
{
    $directory = __DIR__ . '/cache/usersettings';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $existing = load_user_settings_payload($email);
    $normalizedMemoColumns = normalize_memo_column_settings(is_array($existing['memo_columns'] ?? null) ? $existing['memo_columns'] : []);
    if (is_array($memoColumns)) {
        $normalizedMemoColumns = normalize_memo_column_settings($memoColumns);
    }

    $normalizedLayoutStyle = normalize_layout_style($existing['layout_style'] ?? default_layout_style());
    if ($layoutStyle !== null) {
        $normalizedLayoutStyle = normalize_layout_style($layoutStyle);
    }

    $normalizedKeepProjectWorkordersTogether = normalize_keep_project_workorders_together(
        $existing['keep_project_workorders_together'] ?? default_keep_project_workorders_together()
    );
    if ($keepProjectWorkordersTogether !== null) {
        $normalizedKeepProjectWorkordersTogether = normalize_keep_project_workorders_together($keepProjectWorkordersTogether);
    }

    $normalizedCostCenter = trim((string) ($existing['cost_center'] ?? ''));
    if ($costCenter !== null) {
        $normalizedCostCenter = trim($costCenter);
    }

    $knownCostCenters = is_array($existing['known_cost_centers'] ?? null) ? $existing['known_cost_centers'] : [];
    $knownCostCenters = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $knownCostCenters), static function (string $value): bool {
        return $value !== '';
    })));
    if ($normalizedCostCenter !== '' && !in_array($normalizedCostCenter, $knownCostCenters, true)) {
        array_unshift($knownCostCenters, $normalizedCostCenter);
        $knownCostCenters = array_slice($knownCostCenters, 0, 25);
    }

    $path = usersettings_file_path_for_email($email);
    $payload = [
        'memo_columns' => $normalizedMemoColumns,
        'layout_style' => $normalizedLayoutStyle,
        'keep_project_workorders_together' => $normalizedKeepProjectWorkordersTogether,
        'cost_center' => $normalizedCostCenter,
        'known_cost_centers' => $knownCostCenters,
        'updated_at' => gmdate('c'),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

$currentUserEmail = current_user_email_or_fallback();

if (($_GET['action'] ?? '') === 'save_user_settings') {
    header('Content-Type: application/json; charset=utf-8');

    $rawInput = file_get_contents('php://input');
    $decoded = json_decode(is_string($rawInput) ? $rawInput : '', true);
    $memoColumns = is_array($decoded) ? ($decoded['memo_columns'] ?? null) : null;
    $layoutStyle = is_array($decoded) ? ($decoded['layout_style'] ?? null) : null;
    $keepProjectWorkordersTogether = is_array($decoded)
        ? ($decoded['keep_project_workorders_together'] ?? null)
        : null;
    $costCenter = is_array($decoded) ? ($decoded['cost_center'] ?? null) : null;

    if (!is_array($memoColumns) && $layoutStyle === null && $keepProjectWorkordersTogether === null && $costCenter === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige instellingen'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $saved = save_user_settings(
        $currentUserEmail,
        is_array($memoColumns) ? $memoColumns : null,
        $layoutStyle === null ? null : normalize_layout_style($layoutStyle),
        $keepProjectWorkordersTogether,
        is_string($costCenter) ? trim($costCenter) : null
    );
    if (!$saved) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Instellingen opslaan mislukt'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function demeter_send_json_response(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    $json = demeter_workorder_state_cache_json_encode($payload);
    if (!is_string($json)) {
        http_response_code(500);
        $json = demeter_workorder_state_cache_json_encode([
            'ok' => false,
            'error' => 'JSON encode mislukt: ' . json_last_error_msg(),
        ]);
    }

    if (!is_string($json)) {
        $json = '{"ok":false,"error":"JSON encode mislukt"}';
    }

    echo $json;
    exit;
}

if (($_GET['action'] ?? '') === 'load_month') {
    ini_set('display_errors', '0');
    demeter_release_session_lock_if_active();

    try {
        $company = trim((string) ($_GET['company'] ?? ''));
        $costCenter = trim((string) ($_GET['cost_center'] ?? ''));
        $yearWeek = trim((string) ($_GET['year_week'] ?? $_GET['year_month'] ?? ''));
        $invoiceFilter = strtolower(trim((string) ($_GET['invoice_filter'] ?? 'both')));
        $forceFull = strtolower(trim((string) ($_GET['force_full'] ?? ''))) === '1';

        if (!in_array($invoiceFilter, ['both', 'uninvoiced', 'invoiced'], true)) {
            $invoiceFilter = 'both';
        }

        if ($company === '' || $costCenter === '' || !demeter_is_valid_iso_year_week($yearWeek)) {
            throw new InvalidArgumentException('Ongeldige parameters voor week-laden.');
        }

        $loadProgressTokenRaw = trim((string) ($_GET['load_token'] ?? ''));
        $progressWeekIndex = max(0, (int) ($_GET['week_progress_index'] ?? 0));
        $progressWeekTotal = max(0, (int) ($_GET['week_progress_total'] ?? 0));
        $chunkProgressToken = null;
        if ($loadProgressTokenRaw !== '' && odata_load_progress_is_valid_token($loadProgressTokenRaw)) {
            odata_set_active_load_progress_token($loadProgressTokenRaw);
            $chunkProgressToken = $loadProgressTokenRaw;
        }

        auth_set_current_company_context($company, 300);
        $auth = auth_get_auth_for_company($company, 300);
        $perfLogSession = trim((string) ($_GET['call_time_log_session'] ?? ''));
        if ($perfLogSession !== '') {
            odata_call_time_log_activate_session($perfLogSession);
        }
        $chunk = bc_fetch_load_workorder_week_chunk($company, $yearWeek, $auth, $ttl, $chunkProgressToken, [
            'cost_center' => $costCenter,
            'force_full' => $forceFull,
            'skip_if_cached' => true,
            'partial_to_today' => $yearWeek === demeter_current_iso_year_week(),
            'load_session_id' => $perfLogSession,
            'progress_week_index' => $progressWeekIndex,
            'progress_week_total' => $progressWeekTotal,
        ]);

        $built = [
            'rows' => [],
            'row_keys' => [],
        ];
        if (empty($chunk['skipped'])) {
            $built = demeter_build_workorder_rows_from_overview([
                'workorders' => is_array($chunk['workorders'] ?? null) ? $chunk['workorders'] : [],
                'project_totals_by_job' => is_array($chunk['project_totals_by_job'] ?? null) ? $chunk['project_totals_by_job'] : [],
                'project_invoice_ids_by_job' => is_array($chunk['project_invoice_ids_by_job'] ?? null) ? $chunk['project_invoice_ids_by_job'] : [],
                'project_invoiced_total_by_job' => is_array($chunk['project_invoiced_total_by_job'] ?? null) ? $chunk['project_invoiced_total_by_job'] : [],
                'workorder_totals_by_project_and_number' => is_array($chunk['workorder_totals_by_project_and_number'] ?? null) ? $chunk['workorder_totals_by_project_and_number'] : [],
                'finance_key_by_pair' => is_array($chunk['finance_key_by_pair'] ?? null) ? $chunk['finance_key_by_pair'] : [],
            ], $invoiceFilter);
        }

        $monthScan = is_array($chunk['month_scan'] ?? null) ? $chunk['month_scan'] : demeter_workorder_month_scan_defaults();
        $nextWeek = is_string($chunk['next_week'] ?? null)
            ? $chunk['next_week']
            : demeter_previous_iso_year_week($yearWeek);

        demeter_send_json_response([
            'ok' => true,
            'week' => $yearWeek,
            'month' => $yearWeek,
            'skipped' => !empty($chunk['skipped']),
            'rows' => $built['rows'],
            'row_keys' => !empty($chunk['skipped'])
                ? demeter_month_scan_expected_row_keys($monthScan, $yearWeek)
                : $built['row_keys'],
            'has_projectposten' => !empty($chunk['has_projectposten']),
            'empty' => !empty($chunk['empty']),
            'month_scan' => $monthScan,
            'next_week' => $nextWeek,
            'next_month' => $nextWeek,
            'should_continue' => !empty($chunk['should_continue']),
            'project_totals_by_job' => is_array($chunk['project_totals_by_job'] ?? null) ? $chunk['project_totals_by_job'] : [],
            'projectposten_rows_by_project' => is_array($chunk['projectposten_rows_by_project'] ?? null) ? $chunk['projectposten_rows_by_project'] : [],
            'projectposten_rows_by_project_and_workorder' => is_array($chunk['projectposten_rows_by_project_and_workorder'] ?? null) ? $chunk['projectposten_rows_by_project_and_workorder'] : [],
            'invoice_details_by_id' => is_array($chunk['invoice_details_by_id'] ?? null) ? $chunk['invoice_details_by_id'] : [],
            'load_meta' => is_array($chunk['load_meta'] ?? null) ? $chunk['load_meta'] : [],
        ]);
    } catch (Throwable $error) {
        demeter_send_json_response(odata_append_debug_to_payload([
            'ok' => false,
            'error' => $error->getMessage(),
        ]), 500);
    }
}

if (($_GET['action'] ?? '') === 'load_workorder_memos') {
    ini_set('display_errors', '0');
    demeter_release_session_lock_if_active();

    try {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode(is_string($rawBody) ? $rawBody : '', true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Ongeldige JSON-body voor memo-laden.');
        }

        $company = trim((string) ($decoded['company'] ?? ''));
        $costCenter = trim((string) ($decoded['cost_center'] ?? ''));
        $rowRefs = is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];

        if ($company === '' || $rowRefs === []) {
            throw new InvalidArgumentException('Ongeldige parameters voor memo-laden.');
        }

        auth_set_current_company_context($company, 300);
        $auth = auth_get_auth_for_company($company, 300);
        $memosByRowKey = demeter_fetch_workorder_memos_for_row_refs($company, $rowRefs, $auth, $ttl);

        if ($costCenter !== '') {
            demeter_persist_workorder_memos_to_display_cache($company, $costCenter, $memosByRowKey);
        }

        demeter_send_json_response([
            'ok' => true,
            'memos_by_row_key' => $memosByRowKey,
        ]);
    } catch (Throwable $error) {
        demeter_send_json_response(odata_append_debug_to_payload([
            'ok' => false,
            'error' => $error->getMessage(),
        ]), 500);
    }
}

if (($_GET['action'] ?? '') === 'refresh_all_memos') {
    ini_set('display_errors', '0');
    demeter_release_session_lock_if_active();

    try {
        $company = trim((string) ($_GET['company'] ?? ''));
        $costCenter = trim((string) ($_GET['cost_center'] ?? ''));

        if ($company === '' || $costCenter === '') {
            throw new InvalidArgumentException('Ongeldige parameters voor memo-verversing.');
        }

        auth_set_current_company_context($company, 300);
        $auth = auth_get_auth_for_company($company, 300);
        $memoCount = demeter_refresh_all_memos_for_cost_center($company, $costCenter, $auth, $ttl);
        demeter_workorder_state_cache_touch_updated_at($company, $costCenter);

        $loadProgressToken = trim((string) ($_GET['load_token'] ?? ''));
        $progressTotalSteps = max(0, (int) ($_GET['progress_total_steps'] ?? 0));
        if ($loadProgressToken !== '' && odata_load_progress_is_valid_token($loadProgressToken)) {
            odata_load_progress_complete($loadProgressToken, $progressTotalSteps > 0 ? $progressTotalSteps : 1);
        }

        demeter_send_json_response([
            'ok' => true,
            'memos_refreshed' => $memoCount,
        ]);
    } catch (Throwable $error) {
        demeter_send_json_response(odata_append_debug_to_payload([
            'ok' => false,
            'error' => $error->getMessage(),
        ]), 500);
    }
}

if (($_GET['action'] ?? '') === 'forget_cost_center_cache') {
    ini_set('display_errors', '0');
    demeter_release_session_lock_if_active();

    try {
        $company = trim((string) ($_GET['company'] ?? ''));
        $costCenter = trim((string) ($_GET['cost_center'] ?? ''));

        if ($company === '' || $costCenter === '') {
            throw new InvalidArgumentException('Ongeldige parameters voor cache wissen.');
        }

        demeter_workorder_cost_center_cache_forget($company, $costCenter);

        demeter_send_json_response([
            'ok' => true,
            'company' => $company,
            'cost_center' => $costCenter,
        ]);
    } catch (Throwable $error) {
        demeter_send_json_response(odata_append_debug_to_payload([
            'ok' => false,
            'error' => $error->getMessage(),
        ]), 500);
    }
}

function load_cost_center_setting(string $email): string
{
    $parsed = load_user_settings_payload($email);
    return trim((string) ($parsed['cost_center'] ?? ''));
}

function load_known_cost_centers_setting(string $email): array
{
    $parsed = load_user_settings_payload($email);
    $known = $parsed['known_cost_centers'] ?? null;
    if (!is_array($known)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $known), static function (string $value): bool {
        return $value !== '';
    })));
}

$memoColumnSettings = load_memo_column_settings($currentUserEmail);
$layoutStyleSetting = load_layout_style_setting($currentUserEmail);
$keepProjectWorkordersTogetherSetting = load_keep_project_workorders_together_setting($currentUserEmail);
$defaultCostCenterSetting = load_cost_center_setting($currentUserEmail);
$knownCostCentersSetting = load_known_cost_centers_setting($currentUserEmail);

demeter_release_session_lock_if_active();

$companies = [];
$companyEnvironmentMap = [];
$companyDiscoveryErrorMessage = null;
$companiesCacheUpdatedAt = null;

$companiesCache = demeter_companies_cache_load();
$companies = is_array($companiesCache['companies'] ?? null) ? $companiesCache['companies'] : [];
$companyEnvironmentMap = is_array($companiesCache['map'] ?? null) ? $companiesCache['map'] : [];
$companiesCacheUpdatedAt = is_string($companiesCache['updated_at'] ?? null) ? $companiesCache['updated_at'] : null;
$GLOBALS['demeter_company_environment_map'] = $companyEnvironmentMap;

if ($companies === []) {
    try {
        $discovery = demeter_discover_and_cache_companies($ttl);
        $companies = is_array($discovery['companies'] ?? null) ? $discovery['companies'] : [];
        $companyEnvironmentMap = is_array($discovery['map'] ?? null) ? $discovery['map'] : [];
        $GLOBALS['demeter_company_environment_map'] = $companyEnvironmentMap;
        $companiesCacheUpdatedAt = gmdate('c');
    } catch (Throwable $discoveryError) {
        $companyDiscoveryErrorMessage = $discoveryError->getMessage();
    }
}

$selectedCompany = trim((string) ($_GET['company'] ?? ''));
if ($selectedCompany !== '' && !in_array($selectedCompany, $companies, true)) {
    $selectedCompany = '';
}

$selectedEnvironment = '';
if ($selectedCompany !== '') {
    try {
        $selectedEnvironment = auth_get_environment_for_company($selectedCompany, 300);
        auth_set_current_company_context($selectedCompany, 300);
        demeter_store_selected_company_context($selectedCompany, $selectedEnvironment);
    } catch (Throwable $error) {
        $companyDiscoveryErrorMessage = $error->getMessage();
    }
}

$invoiceFilter = strtolower(trim((string) ($_GET['invoice_filter'] ?? '')));
if ($invoiceFilter === '' && array_key_exists('gefactureerd', $_GET)) {
    $legacyShowInvoiced = strtolower(trim((string) ($_GET['gefactureerd'] ?? 'false'))) === 'true';
    $invoiceFilter = $legacyShowInvoiced ? 'invoiced' : 'uninvoiced';
}

if (!in_array($invoiceFilter, ['both', 'uninvoiced', 'invoiced'], true)) {
    $invoiceFilter = 'both';
}

$showInvoiced = $invoiceFilter === 'both' || $invoiceFilter === 'invoiced';

$selectedCostCenter = trim((string) ($_GET['cost_center'] ?? ''));
if ($selectedCostCenter === '' && $defaultCostCenterSetting !== '') {
    $selectedCostCenter = $defaultCostCenterSetting;
}

$refreshNowRequested = strtolower(trim((string) ($_GET['refresh_now'] ?? ''))) === '1';
$forceFullReload = $refreshNowRequested;

$callTimeLogSession = trim((string) ($_GET['call_time_log_session'] ?? ''));
if ($refreshNowRequested && $callTimeLogSession === '' && odata_call_time_log_is_localhost()) {
    $callTimeLogSession = odata_call_time_log_create_session_id();
}
if ($callTimeLogSession !== '') {
    odata_call_time_log_activate_session($callTimeLogSession);
}

$activeLoadProgressTokenRaw = trim((string) ($_GET['load_token'] ?? ''));
$activeLoadProgressToken = odata_load_progress_is_valid_token($activeLoadProgressTokenRaw)
    ? $activeLoadProgressTokenRaw
    : odata_load_progress_create_token();
$nextLoadProgressToken = odata_load_progress_create_token();
odata_set_active_load_progress_token($activeLoadProgressToken);

$syncLoadWeek = demeter_current_iso_year_week();
$totalProgressSteps = 4;

$departmentCostCenterOptions = [];
if ($selectedCompany !== '') {
    $departmentCostCenterOptions = demeter_cost_center_options_cache_load($selectedCompany);
    if ($departmentCostCenterOptions === []) {
        try {
            $costCenterCompanyAuth = auth_get_auth_for_company($selectedCompany, 300);
            $departmentCostCenterOptions = demeter_fetch_and_cache_cost_center_options($selectedCompany, $costCenterCompanyAuth, $ttl);
        } catch (Throwable $costCenterOptionsError) {
            if ($companyDiscoveryErrorMessage === null) {
                $companyDiscoveryErrorMessage = $costCenterOptionsError->getMessage();
            }
        }
    }
}

$shouldReadCacheData = $selectedCompany !== '' && $selectedCostCenter !== '';

$rows = [];
$invoiceDetailsById = [];
$projectpostenRowsByProject = [];
$projectpostenRowsByProjectAndWorkorder = [];
$loadMeta = [];
$monthScan = demeter_workorder_month_scan_defaults();
$asyncLoadEnabled = false;
$pendingRowKeys = [];
$cacheUsedForFirstPaint = false;
$bootMonthPreloaded = false;
$refreshProgressTotalSteps = 0;
$clientLoadProgressToken = $nextLoadProgressToken;
$errorMessage = $companyDiscoveryErrorMessage;

try {
    if ($shouldReadCacheData && $selectedCompany === '') {
        throw new RuntimeException('Geen bedrijven beschikbaar voor de geselecteerde actieve environments.');
    }

    if ($shouldReadCacheData) {
        $asyncLoadEnabled = $refreshNowRequested;
        $cachedState = null;

        if ($asyncLoadEnabled) {
            if ($forceFullReload) {
                demeter_workorder_state_cache_purge($selectedCompany, $selectedCostCenter);
            }

            $estimatedWeeks = demeter_history_weeks_total_for_scan($monthScan, $syncLoadWeek);
            if ($estimatedWeeks === null || $estimatedWeeks <= 0) {
                $estimatedWeeks = DEMETER_MONTH_SCAN_EMPTY_STOP_COUNT;
            }
            $refreshProgressTotalSteps = $estimatedWeeks * 4;
            $clientLoadProgressToken = $activeLoadProgressToken;
            odata_load_progress_begin($activeLoadProgressToken, $refreshProgressTotalSteps);

            $purgedLegacyCache = false;
            $cachedState = $forceFullReload
                ? null
                : demeter_workorder_state_cache_load($selectedCompany, $selectedCostCenter, $purgedLegacyCache);
            if ($purgedLegacyCache) {
                $forceFullReload = true;
            }

            if (is_array($cachedState)) {
                $monthScan = is_array($cachedState['month_scan'] ?? null)
                    ? $cachedState['month_scan']
                    : demeter_workorder_month_scan_defaults();
            }
        } else {
            $purgedLegacyCache = false;
            $cachedState = demeter_workorder_state_cache_load($selectedCompany, $selectedCostCenter, $purgedLegacyCache);
            if (is_array($cachedState)) {
                $monthScan = is_array($cachedState['month_scan'] ?? null)
                    ? $cachedState['month_scan']
                    : demeter_workorder_month_scan_defaults();
            }
        }

        $builtRows = ['rows' => [], 'row_keys' => []];
        if ($asyncLoadEnabled) {
            $rows = [];
        } else {
            $displayRowsByKey = demeter_workorder_state_cache_load_display_rows($selectedCompany, $selectedCostCenter);
            if ($displayRowsByKey !== []) {
                $cacheUsedForFirstPaint = true;
                $rows = demeter_filter_display_rows_by_invoice($displayRowsByKey, $invoiceFilter);
            } else {
                $rows = [];
            }
        }

        save_user_settings($currentUserEmail, null, null, null, $selectedCostCenter);
        demeter_cost_center_activity_record_view($selectedCompany, $selectedCostCenter, $currentUserEmail);
    }
} catch (Throwable $error) {
    $errorMessage = $error->getMessage();
    $progressPayload = odata_load_progress_payload($activeLoadProgressToken);
    odata_load_progress_error(
        $activeLoadProgressToken,
        $totalProgressSteps,
        (int) ($progressPayload['current_month_index'] ?? 0),
        $errorMessage
    );
}

$cacheUpdatedAt = ($selectedCompany !== '' && $selectedCostCenter !== '')
    ? demeter_workorder_cost_center_cache_updated_at($selectedCompany, $selectedCostCenter)
    : null;
$cacheAgeHours = demeter_cache_age_hours($cacheUpdatedAt);
$nightlyStats = demeter_nightly_stats_load();

$initialData = [
    'company' => $selectedCompany,
    'sync_load_week' => $syncLoadWeek,
    'sync_load_month' => $syncLoadWeek,
    'cost_center' => $selectedCostCenter,
    'load_meta' => $loadMeta ?? [],
    'cache_meta' => [
        'updated_at' => $cacheUpdatedAt,
        'age_hours' => $cacheAgeHours,
        'has_data' => $cacheUsedForFirstPaint,
        'is_refreshing' => $refreshNowRequested,
    ],
    'nightly_stats' => $nightlyStats,
    'initial_load_stats' => [
        'from_cache_count' => $cacheUsedForFirstPaint ? count($rows) : 0,
        'updated_from_bc_count' => $refreshNowRequested ? 0 : 0,
    ],
    'async_load' => [
        'enabled' => $asyncLoadEnabled,
        'force_full' => $forceFullReload,
        'chunk_unit' => 'week',
        'current_week' => $syncLoadWeek,
        'current_month' => $syncLoadWeek,
        'next_week' => demeter_previous_iso_year_week($syncLoadWeek),
        'next_month' => demeter_previous_iso_year_week($syncLoadWeek),
        'boot_week_preloaded' => false,
        'boot_month_preloaded' => false,
        'month_scan' => $monthScan,
        'should_continue' => $asyncLoadEnabled && demeter_month_scan_should_continue($monthScan, demeter_previous_iso_year_week($syncLoadWeek)),
        'empty_stop_count' => DEMETER_MONTH_SCAN_EMPTY_STOP_COUNT,
        'history_weeks_total' => demeter_history_weeks_total_for_scan($monthScan, $syncLoadWeek),
        'parallel_week_loads' => 2,
        'progress_total_steps' => $refreshProgressTotalSteps,
        'load_progress_token' => $clientLoadProgressToken,
        'load_month_url' => 'index.php?action=load_month',
        'refresh_all_memos_url' => 'index.php?action=refresh_all_memos',
        'load_workorder_memos_url' => 'index.php?action=load_workorder_memos',
    ],
    'invoice_filter' => $invoiceFilter,
    'memo_column_settings' => $memoColumnSettings,
    'layout_style' => $layoutStyleSetting,
    'keep_project_workorders_together' => $keepProjectWorkordersTogetherSetting,
    'save_user_settings_url' => 'index.php?action=save_user_settings',
    'forget_cost_center_cache_url' => 'index.php?action=forget_cost_center_cache',
    'load_progress_status_url' => 'odata.php?action=load_progress',
    'gefactureerd' => $showInvoiced,
    'rows' => $rows,
    'pending_row_keys' => [],
    'invoice_details_by_id' => $invoiceDetailsById,
    'projectposten_rows_by_project' => $projectpostenRowsByProject,
    'projectposten_rows_by_project_and_workorder' => $projectpostenRowsByProjectAndWorkorder,
    'error' => $errorMessage,
    'call_time_log_session' => $callTimeLogSession,
];
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <title>Werkorderlijst</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f4f7fb;
            color: #1f2937;
        }

        h1 {
            margin: 0 0 14px 0;
            font-size: 26px;
        }

        .page-loader {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(244, 247, 251, 0.92);
        }

        .page-loader.is-visible {
            display: flex;
        }

        .page-loader-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            color: #203a63;
            font-weight: 600;
            width: min(520px, calc(100% - 40px));
            transition: transform 120ms linear;
        }

        .page-loader-spinner {
            width: 34px;
            height: 34px;
            border: 3px solid #c8d3e1;
            border-top-color: #1f4ea6;
            border-radius: 50%;
            animation: page-loader-spin 0.8s linear infinite;
        }

        @keyframes page-loader-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .page-loader-percent {
            font-size: 28px;
            font-weight: 700;
            color: #1f4ea6;
            transition: color .25s ease;
        }

        .page-loader-bar {
            width: 100%;
            height: 12px;
            border-radius: 999px;
            background: #dbe3ee;
            overflow: hidden;
        }

        .page-loader-bar-fill {
            width: 0;
            height: 100%;
            border-radius: 999px;
            background: #1f4ea6;
            transition: width .5s ease, background-color .35s ease;
        }

        .page-loader-call {
            min-height: 18px;
            font-size: 13px;
            color: #64748b;
            text-align: center;
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
            padding: 14px;
            background: #ffffff;
            border: 1px solid #dbe3ee;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
        }

        .controls label {
            font-weight: 600;
            color: #334155;
        }

        .controls select,
        .controls input,
        .controls button {
            font: inherit;
            border: 1px solid #c8d3e1;
            border-radius: 8px;
            padding: 7px 10px;
            background: #fff;
        }

        .controls button {
            background: #1f4ea6;
            border-color: #1f4ea6;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }

        .controls button:hover {
            background: #1a438e;
        }

        .memo-menu-wrap {
            position: relative;
            margin-left: auto;
        }

        .memo-menu-trigger {
            display: inline-flex;
            align-items: center;
            border: 1px solid #334155;
            background: #334155;
            color: #fff;
            border-radius: 8px;
            padding: 7px 10px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .memo-menu-trigger:hover {
            background: #1f2937;
        }

        .memo-menu-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 320px;
            background: #fff;
            border: 1px solid #dbe3ee;
            border-radius: 10px;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.16);
            padding: 10px;
            z-index: 30;
            display: none;
        }

        .memo-menu-panel.is-open {
            display: block;
        }

        .memo-menu-title {
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .memo-menu-section {
            padding: 8px 2px;
        }

        .memo-menu-section+.memo-menu-section {
            border-top: 1px solid #e7edf5;
            margin-top: 6px;
            padding-top: 10px;
        }

        .memo-menu-actions {
            display: flex;
            gap: 6px;
            margin-bottom: 8px;
        }

        .memo-menu-action-btn {
            border: 1px solid #c8d3e1;
            background: #f8fafc;
            color: #1f2937;
            border-radius: 6px;
            padding: 4px 8px;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .memo-menu-action-btn:hover {
            background: #eef2f7;
        }

        .memo-menu-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
        }

        .memo-menu-option input {
            margin: 0;
        }

        .memo-menu-option-inline {
            font-weight: 600;
            color: #334155;
            padding-bottom: 2px;
        }

        .memo-menu-select {
            width: 100%;
            font: inherit;
            border: 1px solid #c8d3e1;
            border-radius: 6px;
            padding: 6px 8px;
            background: #fff;
        }

        .summary {
            font-weight: 600;
        }

        .summary-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 16px;
            margin-bottom: 12px;
        }

        .cache-age-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            padding: 10px 14px;
            border: 1px solid #c8d3e1;
            border-radius: 10px;
            background: #f8fbff;
            color: #334155;
            font-size: 14px;
        }

        .cache-age-banner button {
            border: none;
            background: transparent;
            color: #1f4ea6;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
            text-align: left;
            padding: 0;
        }

        .cache-age-banner button:hover {
            text-decoration: underline;
        }

        .nightly-stats-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.45);
            padding: 20px;
        }

        .nightly-stats-modal-backdrop.is-open {
            display: flex;
        }

        .nightly-stats-modal {
            width: min(920px, 100%);
            max-height: min(80vh, 720px);
            overflow: auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.18);
            padding: 18px 20px;
        }

        .nightly-stats-modal h2 {
            margin: 0 0 12px 0;
            font-size: 20px;
        }

        .nightly-stats-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .nightly-stats-table th,
        .nightly-stats-table td {
            border-bottom: 1px solid #e5ebf3;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }

        .nightly-stats-table th {
            background: #f1f5fb;
            color: #203a63;
        }

        .nightly-stats-close {
            float: right;
            border: 1px solid #c8d3e1;
            background: #fff;
            border-radius: 8px;
            padding: 6px 10px;
            cursor: pointer;
        }

        .nightly-stats-forget-btn {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #475569;
            border-radius: 6px;
            padding: 4px 8px;
            font: inherit;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
        }

        .nightly-stats-forget-btn:hover:not(:disabled) {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        .nightly-stats-forget-btn:disabled {
            opacity: 0.55;
            cursor: default;
        }

        .summary-note {
            font-size: 13px;
            color: #475569;
        }

        .secondary-button {
            border: 1px solid #94a3b8;
            background: #fff;
            color: #334155;
            border-radius: 8px;
            padding: 6px 12px;
            font: inherit;
            cursor: pointer;
        }

        .secondary-button:hover {
            background: #f8fafc;
        }

        .workorder-data-row.is-row-loading {
            position: relative;
            z-index: 4;
        }

        .workorder-data-row.is-row-loading td {
            overflow: visible;
        }

        .workorder-data-row td.col-workorder {
            position: relative;
            overflow: visible;
            z-index: 1;
        }

        .workorder-data-row.is-row-loading td.col-workorder {
            z-index: 5;
        }

        @keyframes row-load-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .row-load-spinner {
            position: absolute;
            left: -14px;
            top: 50%;
            width: 12px;
            height: 12px;
            margin-top: -6px;
            border: 2px solid #cbd5e1;
            border-top-color: #1f4ea6;
            border-radius: 50%;
            animation: row-load-spin 0.7s linear infinite;
            pointer-events: none;
            z-index: 30;
        }

        .workorder-data-row.is-row-loading.is-visible-for-animation td.cell-pulse-target {
            animation: row-cell-pulse 1.1s ease-in-out infinite;
        }

        .workorder-data-row.is-row-complete-flash.is-visible-for-animation td.cell-pulse-target {
            animation: row-cell-complete 0.55s ease 1;
        }

        @keyframes row-cell-pulse {
            0%, 100% {
                color: inherit;
                background-color: inherit;
            }

            50% {
                color: #9ca3af;
                background-color: #e5e7eb;
            }
        }

        @keyframes row-cell-complete {
            0% {
                background-color: inherit;
            }

            40% {
                background-color: #ffffff;
            }

            100% {
                background-color: inherit;
            }
        }

        .workorders-table {
            font-size: 9pt;
        }

        .export-btn {
            border: 1px solid #0f766e;
            background: #0f766e;
            color: #fff;
            border-radius: 8px;
            padding: 6px 12px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            margin-left: auto;
        }

        .export-btn:hover {
            background: #0d625c;
        }

        .status-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
            align-items: center;
        }

        .status-filter-title {
            font-weight: 700;
            color: #334155;
            margin-right: 6px;
        }

        .status-filter-hint {
            padding: 7px 10px;
            border: 1px solid #dbe3ee;
            border-radius: 8px;
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-2px);
            transition: opacity 220ms ease, transform 220ms ease, visibility 220ms ease;
            pointer-events: none;
            white-space: nowrap;
        }

        .status-filter-hint.is-visible {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .status-toggle-all-btn {
            border: 1px solid #334155;
            background: #334155;
            color: #fff;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .status-toggle-all-btn:hover {
            background: #1f2937;
        }

        .status-search-form {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        .status-search-form input {
            border: 1px solid #c8d3e1;
            border-radius: 8px;
            padding: 6px 10px;
            min-width: 220px;
            font: inherit;
        }

        .status-search-form button {
            border: 1px solid #1f4ea6;
            background: #1f4ea6;
            color: #fff;
            border-radius: 8px;
            padding: 6px 12px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .status-search-form button:hover {
            background: #1a438e;
        }

        .notes-btn {
            border: 1px solid #1f4ea6;
            background: #1f4ea6;
            color: #fff;
            border-radius: 8px;
            padding: 5px 10px;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .notes-btn:hover {
            background: #1a438e;
        }

        .notes-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .notes-modal {
            width: min(1360px, 95vw);
            max-height: 85vh;
            overflow: auto;
            background: #fff;
            border-radius: 12px;
            border: 1px solid #dbe3ee;
            box-shadow: 0 20px 30px rgba(15, 23, 42, 0.25);
            padding: 14px;
        }

        .notes-modal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .notes-close {
            border: 1px solid #c8d3e1;
            background: #fff;
            border-radius: 8px;
            padding: 5px 10px;
            font: inherit;
            cursor: pointer;
        }

        .notes-section {
            border: 1px solid #e7edf5;
            border-radius: 8px;
            margin-bottom: 10px;
            overflow: hidden;
        }

        .notes-section-title {
            background: #f1f5fb;
            color: #203a63;
            font-weight: 700;
            padding: 8px 10px;
        }

        .notes-section-text {
            margin: 0;
            padding: 10px;
            white-space: pre-wrap;
            font-family: inherit;
            line-height: 1.4;
        }

        .amount-positive {
            color: #0b6b2f;
            font-weight: 700;
        }

        .amount-negative {
            color: #b42318;
            font-weight: 700;
        }

        .amount-underline-workorder {
            text-decoration-line: underline;
            text-decoration-color: #f59e0b;
            text-decoration-thickness: 2px;
            text-underline-offset: 3px;
            text-decoration-skip-ink: none;
        }

        .amount-underline-project {
            text-decoration-line: underline;
            text-decoration-color: #60a5fa88;
            text-decoration-thickness: 1px;
            text-underline-offset: 3px;
            text-decoration-skip-ink: none;
        }

        @keyframes invoice-cell-blink {
            0% {
                background-color: transparent;
            }

            50% {
                background-color: #fecaca;
            }

            100% {
                background-color: transparent;
            }
        }

        .invoice-cell-blink {
            animation: invoice-cell-blink 0.9s ease-in-out infinite;
        }

        .status-filter-btn {
            border: 1px solid #c8d3e1;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 13px;
            font-weight: 600;
            color: #1f2937;
            cursor: pointer;
        }

        .status-filter-btn.is-off {
            opacity: 0.45;
            text-decoration: line-through;
        }

        .status-open {
            background: #ffffff;
        }

        .status-signed {
            background: #f6f9e9;
        }

        .status-completed {
            background: #e9f9ee;
        }

        .status-checked {
            background: #fff1dd;
        }

        .status-cancelled {
            background: #ffa7a7;
        }

        .status-closed {
            background: #c5c5c5;
        }

        .status-planned {
            background: #ddefff;
        }

        .status-in-progress {
            background: #ffe9e9;
        }

        .error {
            color: #b00020;
            margin-bottom: 12px;
            background: #ffe9ed;
            border: 1px solid #f4b5c0;
            border-radius: 8px;
            padding: 10px 12px;
        }

        #app {
            background: #fff;
            border: 1px solid #dbe3ee;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
            padding: 14px;
            overflow: visible;
        }

        .table-scroll-wrap {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            overflow-x: auto;
            overflow-y: auto;
            max-height: 65vh;
            -webkit-overflow-scrolling: touch;
            cursor: grab;
            padding-left: 18px;
        }

        .table-scroll-wrap.is-dragging-scroll {
            cursor: grabbing;
        }

        body.dragging-table-scroll,
        body.dragging-table-scroll * {
            user-select: none !important;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            overflow: visible;
            border-radius: 10px;
            table-layout: fixed;
        }

        .workorders-table tbody,
        .workorders-table tr {
            overflow: visible;
        }

        .table-scroll-wrap .workorders-table {
            width: max-content;
            min-width: 100%;
        }

        th,
        td {
            border-bottom: 1px solid #e7edf5;
            padding: 10px 12px;
            text-align: left;
            min-width: 0;
            max-width: 220px;
            vertical-align: top;
        }

        td {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        th {
            background: #f1f5fb;
            color: #203a63;
            font-weight: 700;
            white-space: normal;
            position: -webkit-sticky;
            position: sticky;
            top: 0;
            z-index: 15;
        }

        th[role="button"] {
            cursor: pointer;
            user-select: none;
        }

        .column-header-label {
            display: block;
            white-space: normal;
            line-height: 1.15;
            word-break: normal;
            overflow-wrap: normal;
            hyphens: auto;
        }

        .column-header-total {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            line-height: 1.15;
        }

        th.col-compact,
        td.col-compact {
            width: 88px;
            min-width: 88px;
            max-width: 88px;
            padding: 8px 8px;
        }

        td.col-compact {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
        }

        th.col-status,
        td.col-status {
            width: 96px;
            min-width: 96px;
            max-width: 96px;
        }

        th.col-workorder,
        td.col-workorder {
            width: 78px;
            min-width: 78px;
            max-width: 78px;
        }

        th.col-ordertype,
        td.col-ordertype {
            width: 72px;
            min-width: 72px;
            max-width: 72px;
        }

        th.col-project-no,
        td.col-project-no {
            width: 86px;
            min-width: 86px;
            max-width: 86px;
        }

        th.col-customer-id,
        td.col-customer-id {
            width: 66px;
            min-width: 66px;
            max-width: 66px;
        }

        th.col-start-date,
        td.col-start-date {
            width: 78px;
            min-width: 78px;
            max-width: 78px;
        }

        th.col-equipment-number,
        td.col-equipment-number {
            width: 92px;
            min-width: 92px;
            max-width: 92px;
        }

        td.col-workorder,
        td.col-ordertype,
        td.col-project-no,
        td.col-customer-id,
        td.col-start-date,
        td.col-equipment-number {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
        }

        td.invoice-id-clickable {
            cursor: pointer;
            text-decoration: underline;
            text-decoration-style: dotted;
        }

        td.invoice-id-clickable:hover {
            background: #f8fafc;
        }

        .project-posten-link {
            color: #1f4ea6;
            text-decoration: underline;
            text-decoration-style: dotted;
            cursor: pointer;
        }

        .project-posten-link:hover {
            color: #1a438e;
        }

        td.amount-info-clickable {
            cursor: pointer;
        }

        td.amount-info-clickable:hover {
            filter: brightness(0.96);
        }

        th.col-compact-cost-center,
        td.col-compact-cost-center {
            width: 62px;
            min-width: 62px;
            max-width: 62px;
        }

        th.col-notes,
        td.col-notes {
            width: 76px;
            min-width: 76px;
            max-width: 76px;
            text-align: center;
            padding: 8px 6px;
        }

        th.cost-center-th {
            white-space: normal;
            min-width: 62px;
        }

        .cost-center-filter-wrap {
            margin-top: 6px;
        }

        .cost-center-filter {
            width: 100%;
            box-sizing: border-box;
            font: inherit;
            border: 1px solid #c8d3e1;
            border-radius: 6px;
            padding: 3px 4px;
            background: #fff;
        }

        .memo-cell-full {
            white-space: pre-wrap;
            min-width: 180px;
            max-width: 220px;
            font-size: 7pt;
        }

        th.col-memo-remarks,
        td.col-memo-remarks {
            width: 120px;
            min-width: 120px;
            max-width: 120px;
        }

        td.col-memo-remarks {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
        }

        .memo-cell-clickable {
            cursor: pointer;
        }

        .memo-cell-clickable:hover {
            background: #f8fafc;
        }

        th[role="button"]:hover {
            background: #e4edf9;
        }

        tbody tr:hover {
            filter: brightness(0.98);
        }

        .project-group-summary-cell {
            background: #eef4ff;
            color: #1f355a;
            font-size: 12px;
            font-weight: 700;
            border-top: 2px solid #94a3b8;
            border-left: 2px solid #94a3b8;
            border-bottom: 1px solid #d8e1ef;
            padding: 0;
            position: relative;
        }

        .project-group-summary-content {
            position: sticky;
            left: 0;
            z-index: 11;
            display: inline-block;
            min-width: max-content;
            padding: 10px 12px;
            background: #eef4ff;
            white-space: nowrap;
        }

        .project-group-summary-sep {
            color: #64748b;
            padding: 0 6px;
        }

        .project-group-row td {
            border-left: 2px solid #94a3b8;
        }

        .project-group-last-row td {
            border-bottom: 2px solid #94a3b8;
        }

        .project-break-row td {
            border-top: 3px solid #64748b;
        }

        .workorders-table td.project-finance-merged {
            vertical-align: middle;
        }

        tbody tr.status-hidden-by-filter {
            display: none;
        }

        .empty {
            margin-top: 12px;
            color: #475569;
        }
    </style>
</head>

<body>
    <script src="demeter-modal.js?v=<?= urlencode((string) @filemtime(__DIR__ . '/demeter-modal.js')) ?>"></script>
    <div id="pageLoader" class="page-loader" aria-live="polite" aria-label="Laden">
        <div class="page-loader-content">
            <div class="page-loader-spinner" aria-hidden="true"></div>
            <div id="pageLoaderPercent" class="page-loader-percent">0%</div>
            <div id="pageLoaderText">Gegevens laden...</div>
            <div class="page-loader-bar" aria-hidden="true">
                <div id="pageLoaderBarFill" class="page-loader-bar-fill"></div>
            </div>
            <div id="pageLoaderCall" class="page-loader-call"></div>
        </div>
    </div>

    <?= injectTimerHtml([
        'statusUrl' => 'odata.php?action=cache_status',
        'title' => 'Cachebestanden',
        'label' => 'Cache',
    ]) ?>
    <h1>Werkorderlijst</h1>

    <form class="controls" method="get">
        <label for="companySelect">Bedrijf</label>
        <select id="companySelect" name="company" onchange="this.form.submit()">
            <option value="" <?= $selectedCompany === '' ? 'selected' : '' ?>>
                Kies een bedrijf...
            </option>
            <?php foreach ($companies as $company): ?>
                <option value="<?= htmlspecialchars($company) ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                    <?= htmlspecialchars($company) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="costCenterSelect">Kostenplaats</label>
        <select id="costCenterSelect" name="cost_center" <?= $selectedCompany === '' ? 'disabled' : '' ?> onchange="this.form.submit()">
            <option value="" <?= $selectedCostCenter === '' ? 'selected' : '' ?>>
                <?= $selectedCompany === '' ? 'Kies eerst een bedrijf...' : 'Kies een kostenplaats...' ?>
            </option>
            <?php foreach ($departmentCostCenterOptions as $departmentOption): ?>
                <?php
                $optionCode = (string) ($departmentOption['code'] ?? '');
                $optionLabel = (string) ($departmentOption['label'] ?? $optionCode);
                if ($optionCode === '') {
                    continue;
                }
                ?>
                <option value="<?= htmlspecialchars($optionCode) ?>" <?= $selectedCostCenter === $optionCode ? 'selected' : '' ?>>
                    <?= htmlspecialchars($optionLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="invoiceFilter">Factuurfilter</label>
        <select id="invoiceFilter" name="invoice_filter">
            <option value="both" <?= $invoiceFilter === 'both' ? 'selected' : '' ?>>Beide</option>
            <option value="uninvoiced" <?= $invoiceFilter === 'uninvoiced' ? 'selected' : '' ?>>Ongefactureerd</option>
            <option value="invoiced" <?= $invoiceFilter === 'invoiced' ? 'selected' : '' ?>>Gefactureerd</option>
        </select>
        <input type="hidden" name="load_token" id="loadProgressToken"
            value="<?= htmlspecialchars($clientLoadProgressToken) ?>">
        <button type="submit" name="refresh_now" value="1" class="secondary-button" id="refreshNowButton"
            <?= ($selectedCompany === '' || $selectedCostCenter === '') ? 'disabled' : '' ?>>Ververs Nu</button>
        <div class="memo-menu-wrap" id="memoMenuWrap">
            <button type="button" class="memo-menu-trigger" id="memoMenuTrigger">Voorkeuren</button>
            <div class="memo-menu-panel" id="memoMenuPanel">
                <div class="memo-menu-section">
                    <div class="memo-menu-title">Memovoorkeuren</div>
                    <div class="memo-menu-actions">
                        <button type="button" class="memo-menu-action-btn" id="memoMenuAll">Alles</button>
                        <button type="button" class="memo-menu-action-btn" id="memoMenuNone">Niets</button>
                    </div>
                    <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Memo">Memo</label>
                    <label class="memo-menu-option"><input type="checkbox"
                            data-memo-key="Memo_KVT_Memo_Internal_Use_Only">Memo Intern Gebruik</label>
                    <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Memo_Invoice">Memo
                        Factuur</label>
                    <label class="memo-menu-option"><input type="checkbox"
                            data-memo-key="Memo_KVT_Memo_Billing_Details">
                        Memo Bijzonderheden Facturatie</label>
                    <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Remarks_Invoicing">
                        Bijzonderheden Facturatie</label>
                </div>
                <div class="memo-menu-section">
                    <div class="memo-menu-title">Layout</div>
                    <label class="memo-menu-option memo-menu-option-inline" for="layoutStyleSelect">Stijl</label>
                    <select id="layoutStyleSelect" class="memo-menu-select">
                        <option value="projectgroups">Projectgroepen</option>
                        <option value="table">Tabel</option>
                    </select>
                    <label class="memo-menu-option"><input type="checkbox" id="keepProjectWorkordersTogether">Werkorders
                        van project bij elkaar houden</label>
                </div>
            </div>
        </div>
        <noscript><button type="submit" name="refresh_now" value="1">Ververs Nu</button></noscript>
    </form>

    <div id="app"></div>

    <script>
        window.workorderOverviewData = <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
    <script src="index.js?v=<?= urlencode((string) @filemtime(__DIR__ . '/index.js')) ?>"></script>
</body>

</html>