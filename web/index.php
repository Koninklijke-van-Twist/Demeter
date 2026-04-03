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
require_once __DIR__ . "/logincheck.php";
require_once __DIR__ . "/odata.php";
require_once __DIR__ . "/project_finance.php";
require_once __DIR__ . "/bc_fetch/month_loader.php";

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

function save_user_settings(string $email, ?array $memoColumns, ?string $layoutStyle, mixed $keepProjectWorkordersTogether): bool
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

    $path = usersettings_file_path_for_email($email);
    $payload = [
        'memo_columns' => $normalizedMemoColumns,
        'layout_style' => $normalizedLayoutStyle,
        'keep_project_workorders_together' => $normalizedKeepProjectWorkordersTogether,
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

    if (!is_array($memoColumns) && $layoutStyle === null && $keepProjectWorkordersTogether === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige instellingen'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $saved = save_user_settings(
        $currentUserEmail,
        is_array($memoColumns) ? $memoColumns : null,
        $layoutStyle === null ? null : normalize_layout_style($layoutStyle),
        $keepProjectWorkordersTogether
    );
    if (!$saved) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Instellingen opslaan mislukt'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$memoColumnSettings = load_memo_column_settings($currentUserEmail);
$layoutStyleSetting = load_layout_style_setting($currentUserEmail);
$keepProjectWorkordersTogetherSetting = load_keep_project_workorders_together_setting($currentUserEmail);

demeter_release_session_lock_if_active();

$companies = [
    "Koninklijke van Twist",
    "Hunter van Twist",
    "KVT Gas",
];

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
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

$activeLoadProgressTokenRaw = trim((string) ($_GET['load_token'] ?? ''));
$activeLoadProgressToken = odata_load_progress_is_valid_token($activeLoadProgressTokenRaw)
    ? $activeLoadProgressTokenRaw
    : odata_load_progress_create_token();
$nextLoadProgressToken = odata_load_progress_create_token();
odata_set_active_load_progress_token($activeLoadProgressToken);

function parse_month_or_default(?string $value, DateTimeImmutable $fallback): DateTimeImmutable
{
    $text = trim((string) $value);
    if ($text === '' || !preg_match('/^\d{4}-\d{2}$/', $text)) {
        return $fallback;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m', $text);
    if (!$parsed instanceof DateTimeImmutable) {
        return $fallback;
    }

    return $parsed;
}

function month_ranges(DateTimeImmutable $fromMonth, DateTimeImmutable $toMonth): array
{
    $ranges = [];
    $cursor = $fromMonth;

    while ($cursor <= $toMonth) {
        $next = $cursor->modify('+1 month');
        $ranges[] = [
            'from' => $cursor,
            'to' => $next,
        ];
        $cursor = $next;
    }

    return $ranges;
}

$defaultToMonth = new DateTimeImmutable('first day of this month');
$defaultFromMonth = $defaultToMonth->modify('-3 years');

$fromMonth = parse_month_or_default($_GET['from_month'] ?? null, $defaultFromMonth);
$toMonth = parse_month_or_default($_GET['to_month'] ?? null, $defaultToMonth);

if ($fromMonth > $toMonth) {
    [$fromMonth, $toMonth] = [$toMonth, $fromMonth];
}

$fromMonthValue = $fromMonth->format('Y-m');
$toMonthValue = $toMonth->format('Y-m');
$ranges = month_ranges($fromMonth, $toMonth);
$totalMonths = count($ranges);
$totalProgressSteps = $totalMonths;

$isBootRequest = trim((string) ($_GET['boot'] ?? '')) === '1';
if (!$isBootRequest) {
    odata_load_progress_begin($activeLoadProgressToken, $totalProgressSteps);

    $bootQuery = $_GET;
    if (!is_array($bootQuery)) {
        $bootQuery = [];
    }
    $bootQuery['boot'] = '1';
    $bootQuery['load_token'] = $activeLoadProgressToken;
    $bootUrl = 'index.php?' . http_build_query($bootQuery, '', '&', PHP_QUERY_RFC3986);
    $bootStatusUrl = 'odata.php?action=load_progress&token=' . rawurlencode($activeLoadProgressToken);

    header('Content-Type: text/html; charset=utf-8');
    echo demeter_render_progress_screen_html(
        'Gegevens laden',
        $bootStatusUrl,
        'Gegevens laden...',
        'Huidige OData-call: ',
        $bootUrl,
        10,
        ''
    );
    exit;
}

$rows = [];
$errorMessage = null;

try {
    $overviewData = bc_fetch_load_workorder_overview_data($selectedCompany, $ranges, $auth, $ttl, $activeLoadProgressToken);
    $workorders = is_array($overviewData['workorders'] ?? null) ? $overviewData['workorders'] : [];
    $projectTotalsByJob = is_array($overviewData['project_totals_by_job'] ?? null) ? $overviewData['project_totals_by_job'] : [];
    $invoiceDetailsById = is_array($overviewData['invoice_details_by_id'] ?? null) ? $overviewData['invoice_details_by_id'] : [];
    $projectInvoiceIdsByJob = is_array($overviewData['project_invoice_ids_by_job'] ?? null) ? $overviewData['project_invoice_ids_by_job'] : [];
    $projectInvoicedTotalByJob = is_array($overviewData['project_invoiced_total_by_job'] ?? null) ? $overviewData['project_invoiced_total_by_job'] : [];
    $workorderTotalsByNumber = is_array($overviewData['workorder_totals_by_number'] ?? null) ? $overviewData['workorder_totals_by_number'] : [];
    $workorderTotalsByProjectAndNumber = is_array($overviewData['workorder_totals_by_project_and_number'] ?? null) ? $overviewData['workorder_totals_by_project_and_number'] : [];
    $projectpostenRowsByProject = is_array($overviewData['projectposten_rows_by_project'] ?? null) ? $overviewData['projectposten_rows_by_project'] : [];
    $projectpostenRowsByProjectAndWorkorder = is_array($overviewData['projectposten_rows_by_project_and_workorder'] ?? null) ? $overviewData['projectposten_rows_by_project_and_workorder'] : [];

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $jobNo = trim((string) ($workorder['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($workorder['Job_Task_No'] ?? ''));
        $normalizedJobNo = strtolower(trim($jobNo));

        $invoiceIdsForProject = [];
        if ($normalizedJobNo !== '' && isset($projectInvoiceIdsByJob[$normalizedJobNo]) && is_array($projectInvoiceIdsByJob[$normalizedJobNo])) {
            $invoiceIdsForProject = $projectInvoiceIdsByJob[$normalizedJobNo];
        }

        $isInvoiced = $invoiceIdsForProject !== [];
        if ($invoiceFilter === 'invoiced' && !$isInvoiced) {
            continue;
        }

        if ($invoiceFilter === 'uninvoiced' && $isInvoiced) {
            continue;
        }

        $normalizedWorkorderNo = strtolower(trim((string) ($workorder['No'] ?? '')));
        $normalizedWorkorderSourceKey = $jobTaskNo !== ''
            ? strtolower($jobTaskNo)
            : $normalizedWorkorderNo;
        $workorderProjectCompositeKey = $normalizedJobNo . '|' . $normalizedWorkorderSourceKey;
        $workorderTotals = $workorderProjectCompositeKey !== '|' && isset($workorderTotalsByProjectAndNumber[$workorderProjectCompositeKey])
            ? $workorderTotalsByProjectAndNumber[$workorderProjectCompositeKey]
            : null;
        $actualCosts = is_array($workorderTotals) ? (float) ($workorderTotals['costs'] ?? 0.0) : 0.0;
        $totalRevenue = is_array($workorderTotals) ? (float) ($workorderTotals['revenue'] ?? 0.0) : 0.0;
        $actualTotal = finance_calculate_result($totalRevenue, $actualCosts);

        $projectInvoicedTotal = 0.0;
        if ($normalizedJobNo !== '' && isset($projectInvoicedTotalByJob[$normalizedJobNo])) {
            $projectInvoicedTotal = (float) $projectInvoicedTotalByJob[$normalizedJobNo];
        }

        $invoiceIdText = implode(', ', $invoiceIdsForProject);

        $equipmentNumber = trim((string) ($workorder['Component_No'] ?? ''));

        $notesParts = [
            ['label' => 'KVT_Memo', 'value' => trim((string) ($workorder['Memo'] ?? ''))],
            ['label' => 'KVT_Memo_Internal_Use_Only', 'value' => trim((string) ($workorder['Memo_Internal_Use_Only'] ?? ''))],
            ['label' => 'KVT_Memo_Invoice', 'value' => trim((string) ($workorder['Memo_Invoice'] ?? ''))],
            ['label' => 'KVT_Memo_Billing_Details', 'value' => trim((string) ($workorder['KVT_Memo_Invoice_Details'] ?? ''))],
            ['label' => 'KVT_Remarks_Invoicing', 'value' => trim((string) ($workorder['KVT_Remarks_Invoicing'] ?? ''))],
        ];

        $notesSearchParts = [];
        foreach ($notesParts as $notePart) {
            $noteValue = trim((string) ($notePart['value'] ?? ''));
            if ($noteValue !== '') {
                $notesSearchParts[] = $noteValue;
            }
        }

        $projectTotals = $projectTotalsByJob[$normalizedJobNo] ?? null;
        $projectActualCosts = is_array($projectTotals) ? (float) ($projectTotals['costs'] ?? 0) : 0.0;
        $projectTotalRevenue = is_array($projectTotals) ? (float) ($projectTotals['revenue'] ?? 0) : 0.0;

        $isImportSapPseudoRow = $jobTaskNo === ''
            && strtolower(trim((string) ($workorder['Task_Code'] ?? ''))) === 'import sap';

        $displayWorkorderNo = (string) ($workorder['No'] ?? '');
        $displayOrderType = (string) ($workorder['Task_Code'] ?? '');

        if ($isImportSapPseudoRow) {
            $displayWorkorderNo = 'Import SAP';
            $orderTypeFromDescription = (string) ($workorder['Task_Description'] ?? '');
            $orderTypeFromDescription = preg_replace('/\bJAAR\s+\d{4}\b/i', '', $orderTypeFromDescription);
            if (!is_string($orderTypeFromDescription)) {
                $orderTypeFromDescription = '';
            }

            $orderTypeFromDescription = str_ireplace('IMPORT SAP', '', $orderTypeFromDescription);
            $orderTypeFromDescription = str_replace('_', ' ', $orderTypeFromDescription);
            $orderTypeFromDescription = preg_replace('/\b\d{4}\b/', '', $orderTypeFromDescription);
            $orderTypeFromDescription = preg_replace('/\s+/', ' ', trim($orderTypeFromDescription));
            if (!is_string($orderTypeFromDescription)) {
                $orderTypeFromDescription = '';
            }

            $orderTypeFromDescription = strtolower($orderTypeFromDescription);
            if ($orderTypeFromDescription !== '') {
                $orderTypeFromDescription = strtoupper(substr($orderTypeFromDescription, 0, 1)) . substr($orderTypeFromDescription, 1);
            }

            $displayOrderType = $orderTypeFromDescription;
        }

        $normalizedPopupWorkorderSourceKey = $isImportSapPseudoRow
            ? $normalizedWorkorderNo
            : strtolower($jobTaskNo);

        $rows[] = [
            'No' => $displayWorkorderNo,
            'Order_Type' => $displayOrderType,
            'Contract_No' => (string) ($workorder['Contract_No'] ?? ''),
            'Customer_Id' => (string) ($workorder['Bill_to_Customer_No'] ?? ''),
            'Start_Date' => (string) ($workorder['Start_Date'] ?? ''),
            'Component_No' => $equipmentNumber,
            'Component_Description' => (string) ($workorder['Sub_Entity_Description'] ?? ''),
            'Equipment_Number' => $equipmentNumber,
            'Equipment_Name' => (string) ($workorder['Sub_Entity_Description'] ?? ''),
            'Description' => (string) ($workorder['Task_Description'] ?? ''),
            'Customer_Name' => (string) ($workorder['Bill_to_Name'] ?? ''),
            'Actual_Costs' => $actualCosts,
            'Total_Revenue' => $totalRevenue,
            'Invoice_Costs' => null,
            'Invoice_Revenue' => null,
            'Project_Actual_Costs' => $projectActualCosts,
            'Project_Total_Revenue' => $projectTotalRevenue,
            'Invoiced_Total' => $projectInvoicedTotal,
            'Actual_Total' => $actualTotal,
            'Cost_Center' => (string) ($workorder['Job_Dimension_1_Value'] ?? ''),
            'Status' => (string) ($workorder['Status'] ?? ''),
            'Document_Status' => (string) ($workorder['KVT_Document_Status'] ?? ''),
            'Notes' => $notesParts,
            'Notes_Search' => implode("\n", $notesSearchParts),
            'Invoice_Id' => $invoiceIdText,
            'Invoice_Ids' => $invoiceIdsForProject,
            'Job_No' => $jobNo,
            'Job_Task_No' => $jobTaskNo,
            'Workorder_Source_Key' => $normalizedPopupWorkorderSourceKey,
            'End_Date' => (string) ($workorder['End_Date'] ?? ''),
        ];
    }

    usort($rows, function (array $a, array $b): int {
        $projectCompare = strnatcasecmp((string) ($a['Job_No'] ?? ''), (string) ($b['Job_No'] ?? ''));
        if ($projectCompare !== 0) {
            return $projectCompare;
        }

        return strnatcasecmp((string) ($a['No'] ?? ''), (string) ($b['No'] ?? ''));
    });
    odata_load_progress_complete($activeLoadProgressToken, $totalProgressSteps);
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

$initialData = [
    'company' => $selectedCompany,
    'from_month' => $fromMonthValue,
    'to_month' => $toMonthValue,
    'invoice_filter' => $invoiceFilter,
    'memo_column_settings' => $memoColumnSettings,
    'layout_style' => $layoutStyleSetting,
    'keep_project_workorders_together' => $keepProjectWorkordersTogetherSetting,
    'save_user_settings_url' => 'index.php?action=save_user_settings',
    'load_progress_status_url' => 'odata.php?action=load_progress',
    'gefactureerd' => $showInvoiced,
    'rows' => $rows,
    'invoice_details_by_id' => $invoiceDetailsById,
    'projectposten_rows_by_project' => $projectpostenRowsByProject,
    'projectposten_rows_by_project_and_workorder' => $projectpostenRowsByProjectAndWorkorder,
    'error' => $errorMessage,
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
    <title>Onderhanden werklijst</title>
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
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            margin-bottom: 12px;
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
            overflow: hidden;
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
    <div id="pageLoader" class="page-loader is-visible" aria-live="polite" aria-label="Laden">
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
    <h1>Onderhanden werklijst</h1>

    <form class="controls" method="get">
        <label for="companySelect">Bedrijf</label>
        <select id="companySelect" name="company" onchange="this.form.submit()">
            <?php foreach ($companies as $company): ?>
                <option value="<?= htmlspecialchars($company) ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                    <?= htmlspecialchars($company) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="fromMonth">Van</label>
        <input id="fromMonth" type="month" name="from_month" value="<?= htmlspecialchars($fromMonthValue) ?>">
        <label for="toMonth">Tot</label>
        <input id="toMonth" type="month" name="to_month" value="<?= htmlspecialchars($toMonthValue) ?>">
        <label for="invoiceFilter">Factuurfilter</label>
        <select id="invoiceFilter" name="invoice_filter">
            <option value="both" <?= $invoiceFilter === 'both' ? 'selected' : '' ?>>Beide</option>
            <option value="uninvoiced" <?= $invoiceFilter === 'uninvoiced' ? 'selected' : '' ?>>Ongefactureerd</option>
            <option value="invoiced" <?= $invoiceFilter === 'invoiced' ? 'selected' : '' ?>>Gefactureerd</option>
        </select>
        <input type="hidden" name="load_token" id="loadProgressToken"
            value="<?= htmlspecialchars($nextLoadProgressToken) ?>">
        <button type="submit">Toon</button>
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
        <noscript><button type="submit">Toon</button></noscript>
    </form>

    <div id="app"></div>

    <script>
        window.workorderOverviewData = <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="index.js?v=<?= urlencode((string) @filemtime(__DIR__ . '/index.js')) ?>"></script>
</body>

</html>