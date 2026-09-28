<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/demeter-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['DEMETER_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';
require dirname(__DIR__) . '/web/bc_fetch/helpers.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Demeter] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$syntheticCompanyUrl = company_entity_url_with_query(
    'https://bc.example:7148/',
    'Production',
    'KVT Gas',
    'AppWerkorders',
    ['$select' => 'No']
);
if (strpos($syntheticCompanyUrl, 'https://mimir.invalid/Production/ODataV4/Company(') !== 0) {
    fail('met Mímir aan moet de company-URL synthetisch zijn, kreeg: ' . $syntheticCompanyUrl);
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$directCompanyUrl = company_entity_url_with_query(
    'https://mimir.invalid/',
    'mimir',
    'KVT Gas',
    'AppWerkorders',
    ['$select' => 'No']
);
if (strpos($directCompanyUrl, 'https://bc.example:7148/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?') !== 0) {
    fail('na de circuit-open moet company_entity_url_with_query de oude BC-URL bouwen, kreeg: ' . $directCompanyUrl);
}
if (strpos($directCompanyUrl, 'mimir.invalid') !== false) {
    fail('synthetische host bleef staan na fallback');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout wordt gelogd, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Demeter] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeDiscover = count($calls);
$discovery = auth_discover_companies_across_active_environments(30);
$discovered = is_array($discovery['companies'] ?? null) ? $discovery['companies'] : [];
if ($discovered !== $expectedNames) {
    fail('company-discovery (nightly/UI) viel niet terug: ' . json_encode($discovered));
}
if (count($calls) !== $beforeDiscover + 1) {
    fail('discovery-fallback deed niet precies één directe BC-companyfetch: ' . json_encode($calls));
}
if (!odata_mimir_circuit_open()) {
    fail('discovery moet het circuit openen');
}

$sandboxAuth = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
    'Sandbox' => $sandboxAuth,
];
$auth = $auth_list['Production'];
$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Sandbox',
    'KVT Gas' => 'Production',
];
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeSandbox = count($calls);
$sandboxRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
if (($sandboxRows[0]['No'] ?? '') !== 'WO-1') {
    fail('query voor een bedrijf in een tweede environment viel niet terug');
}
$sandboxCall = $calls[$beforeSandbox] ?? null;
if (!is_array($sandboxCall) || strpos($sandboxCall['url'], "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?") !== 0) {
    fail('tweede environment bouwde niet de Sandbox-URL: ' . json_encode($sandboxCall));
}
if (($sandboxCall['user'] ?? '') !== 'sandbox-user') {
    fail('tweede environment gebruikte niet $auth_list[Sandbox]: ' . json_encode($sandboxCall));
}

$beforeSandboxGet = count($calls);
$sandboxGetRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
$sandboxGet = $calls[$beforeSandboxGet] ?? null;
$expectedSandboxUrl = "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No";
if (($sandboxGetRows[0]['No'] ?? '') !== 'WO-1' || !is_array($sandboxGet) || $sandboxGet['url'] !== $expectedSandboxUrl || $sandboxGet['user'] !== 'sandbox-user') {
    fail('doorgegeven primaire auth won van de environment in de URL: ' . json_encode($sandboxGet));
}

$beforeMappedGet = count($calls);
odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
$mappedGet = $calls[$beforeMappedGet] ?? null;
if (!is_array($mappedGet) || $mappedGet['url'] !== $expectedSandboxUrl || $mappedGet['user'] !== 'sandbox-user') {
    fail('company→environment-map werd niet gebruikt bij een mimir-segment: ' . json_encode($mappedGet));
}

$encodedEnvUrl = odata_bc_url_from_odata_url("https://mimir.invalid/My%20Env/ODataV4/Company('X')/Projecten?\$select=No");
if ($encodedEnvUrl !== "https://bc.example:7148/My%20Env/ODataV4/Company('X')/Projecten?\$select=No") {
    fail('environment-segment werd niet precies één keer geëncodeerd: ' . $encodedEnvUrl);
}

$sandboxCacheKey = build_cache_key($expectedSandboxUrl, $sandboxAuth);
if (substr($sandboxCacheKey, -strlen('|sandbox-user|Sandbox')) !== '|sandbox-user|Sandbox' || strpos($sandboxCacheKey, 'mimir') !== false) {
    fail('cache-key moet de echte BC-environment gebruiken: ' . $sandboxCacheKey);
}
$placeholderCacheKey = build_cache_key('https://mimir.invalid/mimir/ODataV4/Company', ['user' => 'bcuser']);
$placeholderParts = explode('|', $placeholderCacheKey);
$placeholderSegment = (string) ($placeholderParts[count($placeholderParts) - 1] ?? '');
if (strcasecmp($placeholderSegment, 'mimir') === 0 || stripos($placeholderSegment, 'mimir.invalid') !== false || $placeholderSegment === '') {
    fail('cache-key mag geen mimir-placeholder bevatten: ' . $placeholderCacheKey);
}

$sandboxLog = fallback_log();
if (strpos($sandboxLog, 'sandbox-secret') !== false || strpos($sandboxLog, 'bc-secret') !== false) {
    fail('log bevat een geheim van een tweede environment');
}

unset($GLOBALS['demeter_company_environment_map']);

odata_mimir_circuit_reset();
$callsBeforeTranslation = count($calls);
$loggedBeforeTranslation = fallback_count();
$translationError = null;
try {
    odata_mimir_fetch_all('https://example.com/nope', 5);
    fail('een onvertaalbare URL moet een exception geven');
} catch (Throwable $exception) {
    $translationError = $exception;
}
if (!$translationError instanceof Throwable || strpos($translationError->getMessage(), 'kon niet worden vertaald') === false) {
    $detail = $translationError instanceof Throwable ? $translationError->getMessage() : 'geen exception';
    fail('vertaalfout kwam niet terug: ' . $detail);
}
if (odata_mimir_circuit_open()) {
    fail('een fout van de caller mag het circuit niet openen');
}
if (count($calls) !== $callsBeforeTranslation || fallback_count() !== $loggedBeforeTranslation) {
    fail('een fout van de caller mag geen fallback starten');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable || strpos($rethrown->getMessage(), 'Mímir') === false) {
    $detail = $rethrown instanceof Throwable ? $rethrown->getMessage() : 'geen exception';
    fail('hergooide fout is niet de Mímir-fout: ' . $detail);
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$authFile = tempnam(sys_get_temp_dir(), 'demeter-auth-');
if (!is_string($authFile) || $authFile === '') {
    fail('tijdelijk auth.php-bestand kon niet worden aangemaakt');
}
$authFileLater = tempnam(sys_get_temp_dir(), 'demeter-auth-later-');
if (!is_string($authFileLater) || $authFileLater === '') {
    @unlink($authFile);
    fail('tweede tijdelijk auth.php-bestand kon niet worden aangemaakt');
}
file_put_contents($authFile, <<<'PHP'
<?php
$baseUrl = 'https://loaded-bc.example:7148/';
$environment = 'LoadedEnv';
$auth_list = ['LoadedEnv' => ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret']];
$auth = $auth_list['LoadedEnv'];
PHP
);
file_put_contents($authFileLater, <<<'PHP'
<?php
$baseUrl = 'https://other.example:7148/';
$base = 'https://other.example:7148/';
$environment = 'OtherEnv';
$auth_list = ['OtherEnv' => ['mode' => 'basic', 'user' => 'other-user', 'pass' => 'other-secret']];
$auth = $auth_list['OtherEnv'];
PHP
);
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$GLOBALS['DEMETER_AUTH_PHP_PATH'] = $authFile;
odata_ensure_bc_config_loaded();
if (odata_bc_base_url() !== 'https://loaded-bc.example:7148/') {
    fail('lazy auth.php zette baseUrl niet in $GLOBALS, kreeg: ' . (string) odata_bc_base_url());
}
if (($GLOBALS['auth_list']['LoadedEnv']['user'] ?? '') !== 'loaded-user' || ($GLOBALS['auth']['user'] ?? '') !== 'loaded-user') {
    fail('lazy auth.php zette auth/auth_list niet in $GLOBALS');
}
if (odata_bc_environment() !== 'LoadedEnv') {
    fail('lazy auth.php zette environment niet in $GLOBALS, kreeg: ' . (string) odata_bc_environment());
}
require_once $authFile;
if (odata_bc_base_url() !== 'https://loaded-bc.example:7148/' || odata_bc_environment() !== 'LoadedEnv') {
    fail('require_once van hetzelfde auth.php mocht de gekopieerde globals niet wijzigen');
}
$GLOBALS['DEMETER_AUTH_PHP_PATH'] = $authFileLater;
odata_ensure_bc_config_loaded();
if (odata_bc_base_url() !== 'https://loaded-bc.example:7148/' || odata_bc_environment() !== 'LoadedEnv') {
    fail('reeds gezette BC-globals werden overschreven');
}
if (($GLOBALS['auth']['user'] ?? '') !== 'loaded-user') {
    fail('reeds gezette auth werd overschreven');
}
unset($GLOBALS['DEMETER_AUTH_PHP_PATH']);
@unlink($authFile);
@unlink($authFileLater);

echo "OK\n";
