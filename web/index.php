<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || ($error['type'] ?? 0) !== E_ERROR) {
        return;
    }

    $message = (string) ($error['message'] ?? '');
    $isTimeout = stripos($message, 'Maximum execution time') !== false
        && stripos($message, '120') !== false
        && stripos($message, 'second') !== false;

    if (!$isTimeout) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $refreshUrl = htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? 'overzicht.php'), ENT_QUOTES, 'UTF-8');
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 5');

    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta http-equiv="refresh" content="5;url=' . $refreshUrl . '">';
    echo '<title>Even geduld</title></head><body style="font-family:Verdana,Geneva,Tahoma,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">';
    echo '<div style="text-align:center;padding:24px">Er is meer tijd nodig om gegevens te laden.<br>De pagina wordt automatisch vernieuwd...</div>';
    echo '<script>setTimeout(function(){location.reload();},5000);</script>';
    echo '</body></html>';
});

require __DIR__ . "/auth.php";
require_once __DIR__ . "/logincheck.php";
require_once __DIR__ . "/odata.php";

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

function load_memo_column_settings(string $email): array
{
    $defaults = default_memo_column_settings();
    $path = usersettings_file_path_for_email($email);
    if (!is_file($path) || !is_readable($path)) {
        return $defaults;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
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

function save_memo_column_settings(string $email, array $input): bool
{
    $normalized = default_memo_column_settings();
    foreach ($normalized as $key => $value) {
        if (array_key_exists($key, $input)) {
            $normalized[$key] = (bool) $input[$key];
        }
    }

    $directory = __DIR__ . '/cache/usersettings';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $path = usersettings_file_path_for_email($email);
    $payload = [
        'memo_columns' => $normalized,
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
    if (!is_array($memoColumns)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Ongeldige instellingen'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $saved = save_memo_column_settings($currentUserEmail, $memoColumns);
    if (!$saved) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Instellingen opslaan mislukt'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$memoColumnSettings = load_memo_column_settings($currentUserEmail);

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

function company_entity_url(string $baseUrl, string $environment, string $company, string $entitySet, array $selectFields = []): string
{
    $safeCompany = str_replace("'", "''", trim($company));
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($selectFields !== []) {
        $url .= '?' . http_build_query(['$select' => implode(',', $selectFields)], '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function company_entity_url_with_query(string $baseUrl, string $environment, string $company, string $entitySet, array $query): string
{
    $safeCompany = str_replace("'", "''", trim($company));
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function workorder_invoice_key(string $jobNo, string $jobTaskNo): string
{
    return strtolower(trim($jobNo)) . '|' . strtolower(trim($jobTaskNo));
}

function escape_odata_string(string $value): string
{
    return str_replace("'", "''", $value);
}

function normalize_match_value(string $value): string
{
    return strtolower(trim($value));
}

function invoice_detail_has_value(mixed $value): bool
{
    if ($value === null) {
        return false;
    }

    if (is_string($value)) {
        return trim($value) !== '';
    }

    if (is_array($value)) {
        return $value !== [];
    }

    return true;
}

function merge_non_empty_invoice_fields(array &$target, array $source): void
{
    foreach ($source as $field => $value) {
        if (!is_string($field) || trim($field) === '') {
            continue;
        }

        if (!invoice_detail_has_value($value)) {
            continue;
        }

        $target[$field] = $value;
    }
}

function first_numeric_invoice_field(array $details, array $fields): ?array
{
    foreach ($fields as $field) {
        if (!is_string($field) || $field === '' || !array_key_exists($field, $details)) {
            continue;
        }

        $raw = $details[$field];
        if (!is_numeric($raw)) {
            continue;
        }

        return [
            'field' => $field,
            'value' => abs((float) $raw),
        ];
    }

    return null;
}

function normalize_invoice_source(string $source): string
{
    $normalized = strtolower(trim($source));
    if ($normalized === 'appprojectinvoices' || $normalized === 'projectinvoices') {
        return 'app_project';
    }

    if ($normalized === 'salesinvoice' || $normalized === 'salesinvoices') {
        return 'sales';
    }

    if ($normalized === 'serviceinvoice' || $normalized === 'serviceinvoices') {
        return 'service';
    }

    return 'unknown';
}

function chunk_values(array $values, int $size): array
{
    $clean = [];
    foreach ($values as $value) {
        $text = trim((string) $value);
        if ($text === '') {
            continue;
        }

        $clean[] = $text;
    }

    if ($clean === []) {
        return [];
    }

    return array_chunk(array_values(array_unique($clean)), max(1, $size));
}

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
$defaultFromMonth = $defaultToMonth->modify('-11 months');

$fromMonth = parse_month_or_default($_GET['from_month'] ?? null, $defaultFromMonth);
$toMonth = parse_month_or_default($_GET['to_month'] ?? null, $defaultToMonth);

if ($fromMonth > $toMonth) {
    [$fromMonth, $toMonth] = [$toMonth, $fromMonth];
}

$fromMonthValue = $fromMonth->format('Y-m');
$toMonthValue = $toMonth->format('Y-m');

$rows = [];
$errorMessage = null;

try {
    $workorders = [];
    $seenWorkorders = [];
    $ranges = month_ranges($fromMonth, $toMonth);

    foreach ($ranges as $range) {
        $rangeFrom = $range['from'];
        $rangeTo = $range['to'];
        if (!$rangeFrom instanceof DateTimeImmutable || !$rangeTo instanceof DateTimeImmutable) {
            continue;
        }

        $workorderUrl = company_entity_url_with_query($baseUrl, $environment, $selectedCompany, 'Werkorders', [
            '$select' => 'No,Task_Code,Task_Description,Status,Job_No,Job_Task_No,External_Document_No,Start_Date,End_Date,Sub_Entity,Sub_Entity_Description,Component_No,Serial_No,Bill_to_Customer_No,Bill_to_Name,KVT_Sum_Work_Order_Cost_Items,KVT_Sum_Work_Order_Cost_Other,KVT_Sum_Work_Order_Revenue,Job_Dimension_1_Value,Memo,Memo_Internal_Use_Only,Memo_Invoice,KVT_Memo_Invoice_Details,KVT_Remarks_Invoicing',
            '$filter' => 'Start_Date ge ' . $rangeFrom->format('Y-m-d') . ' and Start_Date lt ' . $rangeTo->format('Y-m-d'),
        ]);

        $batchWorkorders = odata_get_all($workorderUrl, $auth, 18000);
        foreach ($batchWorkorders as $workorder) {
            if (!is_array($workorder)) {
                continue;
            }

            $rowKey = implode('|', [
                (string) ($workorder['No'] ?? ''),
                (string) ($workorder['Job_No'] ?? ''),
                (string) ($workorder['Job_Task_No'] ?? ''),
                (string) ($workorder['Start_Date'] ?? ''),
            ]);

            if (isset($seenWorkorders[$rowKey])) {
                continue;
            }

            $seenWorkorders[$rowKey] = true;
            $workorders[] = $workorder;
        }
    }

    $invoices = [];
    $seenInvoices = [];
    $invoiceDetailsById = [];
    $invoiceCursor = $fromMonth;
    $invoiceEndExclusive = (new DateTimeImmutable('today'))->modify('+1 day');

    while ($invoiceCursor < $invoiceEndExclusive) {
        $invoiceRangeTo = $invoiceCursor->modify('+1 month');
        if ($invoiceRangeTo > $invoiceEndExclusive) {
            $invoiceRangeTo = $invoiceEndExclusive;
        }

        foreach (['Invoiced_Date', 'Transferred_Date'] as $dateField) {
            $periodFilter = $dateField . ' ge ' . $invoiceCursor->format('Y-m-d') . ' and ' . $dateField . ' lt ' . $invoiceRangeTo->format('Y-m-d');

            $invoiceUrl = company_entity_url_with_query($baseUrl, $environment, $selectedCompany, 'AppProjectInvoices', [
                '$select' => 'Job_No,Job_Task_No,Document_No,Line_No,Invoiced_Date,Transferred_Date,Invoiced_Amount_LCY,Invoiced_Cost_Amount_LCY',
                '$filter' => $periodFilter,
            ]);

            $batchInvoices = odata_get_all($invoiceUrl, $auth, 18000);
            foreach ($batchInvoices as $invoice) {
                if (!is_array($invoice)) {
                    continue;
                }

                $invoiceDocumentNo = trim((string) ($invoice['Document_No'] ?? ''));
                $invoiceJobNo = trim((string) ($invoice['Job_No'] ?? ''));
                $invoiceJobTaskNo = trim((string) ($invoice['Job_Task_No'] ?? ''));
                $invoiceInvoicedDate = trim((string) ($invoice['Invoiced_Date'] ?? ''));
                $invoiceTransferredDate = trim((string) ($invoice['Transferred_Date'] ?? ''));
                $invoiceLineNo = (string) ($invoice['Line_No'] ?? '');
                $invoiceAmountLcy = abs((float) ($invoice['Invoiced_Amount_LCY'] ?? 0));
                $invoiceCostAmountLcy = abs((float) ($invoice['Invoiced_Cost_Amount_LCY'] ?? 0));

                if ($invoiceDocumentNo !== '') {
                    if (!isset($invoiceDetailsById[$invoiceDocumentNo])) {
                        $invoiceDetailsById[$invoiceDocumentNo] = [
                            'Source' => 'AppProjectInvoices',
                            'Invoice_Id' => $invoiceDocumentNo,
                            'Job_Nos' => [],
                            'Job_Task_Nos' => [],
                            'Line_Count' => 0,
                            'Invoiced_Amount_LCY_Total' => 0.0,
                            'Invoiced_Cost_Amount_LCY_Total' => 0.0,
                            'Invoiced_Date_First' => '',
                            'Invoiced_Date_Last' => '',
                            'Transferred_Date_First' => '',
                            'Transferred_Date_Last' => '',
                        ];
                    }

                    merge_non_empty_invoice_fields($invoiceDetailsById[$invoiceDocumentNo], $invoice);

                    $invoiceDetailsById[$invoiceDocumentNo]['Line_Count']++;
                    $invoiceDetailsById[$invoiceDocumentNo]['Invoiced_Amount_LCY_Total'] += $invoiceAmountLcy;
                    $invoiceDetailsById[$invoiceDocumentNo]['Invoiced_Cost_Amount_LCY_Total'] += $invoiceCostAmountLcy;

                    if ($invoiceJobNo !== '' && !in_array($invoiceJobNo, $invoiceDetailsById[$invoiceDocumentNo]['Job_Nos'], true)) {
                        $invoiceDetailsById[$invoiceDocumentNo]['Job_Nos'][] = $invoiceJobNo;
                    }

                    if ($invoiceJobTaskNo !== '' && !in_array($invoiceJobTaskNo, $invoiceDetailsById[$invoiceDocumentNo]['Job_Task_Nos'], true)) {
                        $invoiceDetailsById[$invoiceDocumentNo]['Job_Task_Nos'][] = $invoiceJobTaskNo;
                    }

                    if ($invoiceInvoicedDate !== '') {
                        $firstInvoicedDate = (string) ($invoiceDetailsById[$invoiceDocumentNo]['Invoiced_Date_First'] ?? '');
                        $lastInvoicedDate = (string) ($invoiceDetailsById[$invoiceDocumentNo]['Invoiced_Date_Last'] ?? '');
                        if ($firstInvoicedDate === '' || $invoiceInvoicedDate < $firstInvoicedDate) {
                            $invoiceDetailsById[$invoiceDocumentNo]['Invoiced_Date_First'] = $invoiceInvoicedDate;
                        }
                        if ($lastInvoicedDate === '' || $invoiceInvoicedDate > $lastInvoicedDate) {
                            $invoiceDetailsById[$invoiceDocumentNo]['Invoiced_Date_Last'] = $invoiceInvoicedDate;
                        }
                    }

                    if ($invoiceTransferredDate !== '') {
                        $firstTransferredDate = (string) ($invoiceDetailsById[$invoiceDocumentNo]['Transferred_Date_First'] ?? '');
                        $lastTransferredDate = (string) ($invoiceDetailsById[$invoiceDocumentNo]['Transferred_Date_Last'] ?? '');
                        if ($firstTransferredDate === '' || $invoiceTransferredDate < $firstTransferredDate) {
                            $invoiceDetailsById[$invoiceDocumentNo]['Transferred_Date_First'] = $invoiceTransferredDate;
                        }
                        if ($lastTransferredDate === '' || $invoiceTransferredDate > $lastTransferredDate) {
                            $invoiceDetailsById[$invoiceDocumentNo]['Transferred_Date_Last'] = $invoiceTransferredDate;
                        }
                    }

                    if ($invoiceLineNo !== '') {
                        $invoiceDetailsById[$invoiceDocumentNo]['Laatste_Line_No'] = $invoiceLineNo;
                    }
                }

                $invoiceKey = implode('|', [
                    $invoiceDocumentNo,
                    $invoiceLineNo,
                    $invoiceJobNo,
                    $invoiceJobTaskNo,
                    $invoiceInvoicedDate,
                    $invoiceTransferredDate,
                ]);

                if ($invoiceKey !== '' && isset($seenInvoices[$invoiceKey])) {
                    continue;
                }

                if ($invoiceKey !== '') {
                    $seenInvoices[$invoiceKey] = true;
                }

                $invoices[] = $invoice;
            }
        }

        $invoiceCursor = $invoiceRangeTo;
    }

    $invoiceKeys = [];
    $invoiceJobOnly = [];
    $invoiceReferences = [];
    $invoiceProjectDimension2 = [];
    $invoiceFinancialByJobTask = [];
    $invoiceFinancialByJob = [];
    $invoiceFinancialByDocument = [];
    foreach ($invoices as $invoice) {
        if (!is_array($invoice)) {
            continue;
        }

        $invoiceJobNo = trim((string) ($invoice['Job_No'] ?? ''));
        $invoiceJobTaskNo = trim((string) ($invoice['Job_Task_No'] ?? ''));
        $invoiceDocumentNo = trim((string) ($invoice['Document_No'] ?? ''));
        $invoiceRevenue = abs((float) ($invoice['Invoiced_Amount_LCY'] ?? 0));
        $invoiceCosts = abs((float) ($invoice['Invoiced_Cost_Amount_LCY'] ?? 0));
        if ($invoiceDocumentNo !== '') {
            if (!isset($invoiceFinancialByDocument[$invoiceDocumentNo])) {
                $invoiceFinancialByDocument[$invoiceDocumentNo] = [
                    'costs' => 0.0,
                    'revenue' => 0.0,
                ];
            }

            $invoiceFinancialByDocument[$invoiceDocumentNo]['costs'] += $invoiceCosts;
            $invoiceFinancialByDocument[$invoiceDocumentNo]['revenue'] += $invoiceRevenue;
        }

        if ($invoiceJobNo === '') {
            continue;
        }

        $normalizedInvoiceJobNo = normalize_match_value($invoiceJobNo);

        $invoiceJobOnly[normalize_match_value($invoiceJobNo)] = [
            'id' => $invoiceDocumentNo,
            'source' => 'AppProjectInvoices',
        ];

        if (!isset($invoiceFinancialByJob[$normalizedInvoiceJobNo])) {
            $invoiceFinancialByJob[$normalizedInvoiceJobNo] = [
                'costs' => 0.0,
                'revenue' => 0.0,
                'id' => '',
            ];
        }
        $invoiceFinancialByJob[$normalizedInvoiceJobNo]['costs'] += $invoiceCosts;
        $invoiceFinancialByJob[$normalizedInvoiceJobNo]['revenue'] += $invoiceRevenue;
        if ($invoiceDocumentNo !== '') {
            $invoiceFinancialByJob[$normalizedInvoiceJobNo]['id'] = $invoiceDocumentNo;
        }

        if ($invoiceJobTaskNo !== '') {
            $invoiceKeys[workorder_invoice_key($invoiceJobNo, $invoiceJobTaskNo)] = [
                'id' => $invoiceDocumentNo,
                'source' => 'AppProjectInvoices',
            ];

            $invoiceJobTaskKey = workorder_invoice_key($invoiceJobNo, $invoiceJobTaskNo);
            if (!isset($invoiceFinancialByJobTask[$invoiceJobTaskKey])) {
                $invoiceFinancialByJobTask[$invoiceJobTaskKey] = [
                    'costs' => 0.0,
                    'revenue' => 0.0,
                    'id' => '',
                ];
            }

            $invoiceFinancialByJobTask[$invoiceJobTaskKey]['costs'] += $invoiceCosts;
            $invoiceFinancialByJobTask[$invoiceJobTaskKey]['revenue'] += $invoiceRevenue;
            if ($invoiceDocumentNo !== '') {
                $invoiceFinancialByJobTask[$invoiceJobTaskKey]['id'] = $invoiceDocumentNo;
            }
        }

        if ($invoiceDocumentNo !== '') {
            $invoiceReferences[normalize_match_value($invoiceDocumentNo)] = [
                'id' => $invoiceDocumentNo,
                'source' => 'AppProjectInvoices',
            ];
        }
    }

    $additionalInvoiceSources = [
        [
            'entity' => 'SalesInvoices',
            'date_fields' => ['Posting_Date', 'Document_Date'],
            'select_candidates' => [
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date,Amount,Shortcut_Dimension_2_Code',
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date,Shortcut_Dimension_2_Code',
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date,Amount',
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date',
            ],
        ],
        [
            'entity' => 'ServiceInvoices',
            'date_fields' => ['Document_Date', 'Order_Date'],
            'select_candidates' => [
                'No,External_Document_No,Your_Reference,Document_Date,Order_Date,Shortcut_Dimension_2_Code',
                'No,External_Document_No,Your_Reference,Document_Date,Order_Date',
            ],
        ],
        [
            'entity' => 'SalesInvoice',
            'date_fields' => ['Posting_Date', 'Document_Date'],
            'select_candidates' => [
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date,Amount,Total_Amount_Excl_VAT,Total_VAT_Amount,Total_Amount_Incl_VAT,Invoice_Discount_Amount,Shortcut_Dimension_2_Code',
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date,Amount,Shortcut_Dimension_2_Code',
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date,Shortcut_Dimension_2_Code',
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date,Amount',
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date',
            ],
        ],
        [
            'entity' => 'ServiceInvoice',
            'date_fields' => ['Posting_Date', 'Document_Date'],
            'select_candidates' => [
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date,Total_Amount_Excl_VAT,Total_VAT_Amount,Total_Amount_Incl_VAT,Invoice_Discount_Amount,Shortcut_Dimension_2_Code',
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date,Shortcut_Dimension_2_Code',
                'No,External_Document_No,Your_Reference,Posting_Date,Document_Date',
            ],
        ],
    ];

    foreach ($additionalInvoiceSources as $source) {
        $entity = (string) ($source['entity'] ?? '');
        $dateFields = $source['date_fields'] ?? [];
        $selectCandidates = $source['select_candidates'] ?? [];

        if ($entity === '' || !is_array($dateFields) || !is_array($selectCandidates) || $selectCandidates === []) {
            continue;
        }

        $sourceSeen = [];
        foreach ($dateFields as $dateField) {
            if (!is_string($dateField) || trim($dateField) === '') {
                continue;
            }

            $invoiceCursor = $fromMonth;
            $invoiceEndExclusive = (new DateTimeImmutable('today'))->modify('+1 day');

            while ($invoiceCursor < $invoiceEndExclusive) {
                $invoiceRangeTo = $invoiceCursor->modify('+1 month');
                if ($invoiceRangeTo > $invoiceEndExclusive) {
                    $invoiceRangeTo = $invoiceEndExclusive;
                }

                $periodFilter = $dateField . ' ge ' . $invoiceCursor->format('Y-m-d') . ' and ' . $dateField . ' lt ' . $invoiceRangeTo->format('Y-m-d');

                $batchInvoices = [];
                $loaded = false;
                foreach ($selectCandidates as $selectCandidate) {
                    if (!is_string($selectCandidate) || trim($selectCandidate) === '') {
                        continue;
                    }

                    try {
                        $invoiceUrl = company_entity_url_with_query($baseUrl, $environment, $selectedCompany, $entity, [
                            '$select' => $selectCandidate,
                            '$filter' => $periodFilter,
                        ]);

                        $batchInvoices = odata_get_all($invoiceUrl, $auth, 18000);
                        $loaded = true;
                        break;
                    } catch (Throwable $ignoredSelectError) {
                        continue;
                    }
                }

                if (!$loaded) {
                    $invoiceCursor = $invoiceRangeTo;
                    continue;
                }

                foreach ($batchInvoices as $invoice) {
                    if (!is_array($invoice)) {
                        continue;
                    }

                    $sourceKey = implode('|', [
                        trim((string) ($invoice['No'] ?? '')),
                        trim((string) ($invoice['External_Document_No'] ?? '')),
                        trim((string) ($invoice['Your_Reference'] ?? '')),
                        trim((string) ($invoice['Posting_Date'] ?? '')),
                        trim((string) ($invoice['Document_Date'] ?? '')),
                        trim((string) ($invoice['Order_Date'] ?? '')),
                    ]);

                    if ($sourceKey !== '' && isset($sourceSeen[$sourceKey])) {
                        continue;
                    }

                    if ($sourceKey !== '') {
                        $sourceSeen[$sourceKey] = true;
                    }

                    $sourceInvoiceNo = trim((string) ($invoice['No'] ?? ''));
                    if ($sourceInvoiceNo !== '') {
                        if (!isset($invoiceDetailsById[$sourceInvoiceNo])) {
                            $invoiceDetailsById[$sourceInvoiceNo] = [
                                'Source' => $entity,
                                'Invoice_Id' => $sourceInvoiceNo,
                            ];
                        }

                        merge_non_empty_invoice_fields($invoiceDetailsById[$sourceInvoiceNo], $invoice);
                        $invoiceDetailsById[$sourceInvoiceNo]['Source'] = $entity;
                        $invoiceDetailsById[$sourceInvoiceNo]['Invoice_Id'] = $sourceInvoiceNo;
                    }

                    $referenceCandidates = [
                        trim((string) ($invoice['External_Document_No'] ?? '')),
                        trim((string) ($invoice['Your_Reference'] ?? '')),
                    ];

                    foreach ($referenceCandidates as $referenceValue) {
                        if ($referenceValue === '') {
                            continue;
                        }

                        $normalizedReferenceValue = normalize_match_value($referenceValue);
                        if (!isset($invoiceReferences[$normalizedReferenceValue])) {
                            $invoiceReferences[$normalizedReferenceValue] = [
                                'id' => $sourceInvoiceNo,
                                'source' => $entity,
                            ];
                        }
                    }

                    $projectNumberRaw = trim((string) ($invoice['Shortcut_Dimension_2_Code'] ?? ''));
                    if ($projectNumberRaw !== '' && $sourceInvoiceNo !== '') {
                        $normalizedProjectNumber = normalize_match_value($projectNumberRaw);
                        if (!isset($invoiceProjectDimension2[$normalizedProjectNumber])) {
                            $invoiceProjectDimension2[$normalizedProjectNumber] = [
                                'id' => $sourceInvoiceNo,
                                'source' => $entity,
                            ];
                        }
                    }
                }

                $invoiceCursor = $invoiceRangeTo;
            }
        }
    }

    $invoiceIdsByType = [
        'sales' => [],
        'service' => [],
    ];

    foreach ($invoiceDetailsById as $invoiceId => $invoiceDetails) {
        if (!is_string($invoiceId) || trim($invoiceId) === '') {
            continue;
        }

        if (!is_array($invoiceDetails)) {
            continue;
        }

        $detailsSource = normalize_invoice_source((string) ($invoiceDetails['Source'] ?? ''));
        if ($detailsSource === 'sales' || $detailsSource === 'service') {
            $invoiceIdsByType[$detailsSource][] = trim($invoiceId);
        }
    }

    $lineSources = [
        'sales' => [
            'entity' => 'SalesInvoiceSalesLines',
            'select_candidates' => [
                'Document_No,Line_Amount,KVT_Total_Costs_Line_LCY,Unit_Cost_LCY,Quantity',
                'Document_No,Line_Amount,Unit_Cost_LCY,Quantity',
                'Document_No,Line_Amount',
            ],
        ],
        'service' => [
            'entity' => 'ServiceInvoiceServLines',
            'select_candidates' => [
                'Document_No,Line_Amount,Unit_Cost_LCY,Quantity',
                'Document_No,Line_Amount,Unit_Cost_LCY',
                'Document_No,Line_Amount',
            ],
        ],
    ];

    $invoiceLineFinancialByType = [
        'sales' => [],
        'service' => [],
    ];

    foreach ($lineSources as $typeKey => $lineSourceConfig) {
        $typeInvoiceIds = $invoiceIdsByType[$typeKey] ?? [];
        if (!is_array($typeInvoiceIds) || $typeInvoiceIds === []) {
            continue;
        }

        $entity = (string) ($lineSourceConfig['entity'] ?? '');
        $selectCandidates = $lineSourceConfig['select_candidates'] ?? [];
        if ($entity === '' || !is_array($selectCandidates) || $selectCandidates === []) {
            continue;
        }

        $chunks = chunk_values($typeInvoiceIds, 25);
        foreach ($chunks as $chunkIds) {
            if (!is_array($chunkIds) || $chunkIds === []) {
                continue;
            }

            $filterParts = [];
            foreach ($chunkIds as $chunkId) {
                $filterParts[] = "Document_No eq '" . escape_odata_string($chunkId) . "'";
            }

            if ($filterParts === []) {
                continue;
            }

            $filter = implode(' or ', $filterParts);
            $batchLines = [];
            $loaded = false;

            foreach ($selectCandidates as $selectCandidate) {
                if (!is_string($selectCandidate) || trim($selectCandidate) === '') {
                    continue;
                }

                try {
                    $lineUrl = company_entity_url_with_query($baseUrl, $environment, $selectedCompany, $entity, [
                        '$select' => $selectCandidate,
                        '$filter' => $filter,
                    ]);

                    $batchLines = odata_get_all($lineUrl, $auth, 18000);
                    $loaded = true;
                    break;
                } catch (Throwable $ignoredLineSelectError) {
                    continue;
                }
            }

            if (!$loaded) {
                continue;
            }

            foreach ($batchLines as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $documentNo = trim((string) ($line['Document_No'] ?? ''));
                if ($documentNo === '') {
                    continue;
                }

                if (!isset($invoiceLineFinancialByType[$typeKey][$documentNo])) {
                    $invoiceLineFinancialByType[$typeKey][$documentNo] = [
                        'revenue' => 0.0,
                        'costs' => 0.0,
                        'line_count' => 0,
                    ];
                }

                $invoiceLineFinancialByType[$typeKey][$documentNo]['line_count']++;
                $lineRevenue = abs((float) ($line['Line_Amount'] ?? 0));
                $invoiceLineFinancialByType[$typeKey][$documentNo]['revenue'] += $lineRevenue;

                if ($typeKey === 'sales') {
                    $lineCosts = abs((float) ($line['KVT_Total_Costs_Line_LCY'] ?? 0));
                    if ($lineCosts <= 0 && isset($line['Unit_Cost_LCY'], $line['Quantity']) && is_numeric($line['Unit_Cost_LCY']) && is_numeric($line['Quantity'])) {
                        $lineCosts = abs((float) $line['Unit_Cost_LCY'] * (float) $line['Quantity']);
                    }

                    $invoiceLineFinancialByType[$typeKey][$documentNo]['costs'] += $lineCosts;
                } else {
                    $lineCosts = 0.0;
                    if (isset($line['Unit_Cost_LCY'], $line['Quantity']) && is_numeric($line['Unit_Cost_LCY']) && is_numeric($line['Quantity'])) {
                        $lineCosts = abs((float) $line['Unit_Cost_LCY'] * (float) $line['Quantity']);
                    }

                    $invoiceLineFinancialByType[$typeKey][$documentNo]['costs'] += $lineCosts;
                }
            }
        }
    }

    foreach ($invoiceLineFinancialByType as $typeKey => $typeFinancials) {
        foreach ($typeFinancials as $invoiceId => $financials) {
            if (!isset($invoiceDetailsById[$invoiceId]) || !is_array($invoiceDetailsById[$invoiceId])) {
                $invoiceDetailsById[$invoiceId] = [];
            }

            $invoiceDetailsById[$invoiceId]['Line_Source_Entity'] = $typeKey === 'sales' ? 'SalesInvoiceSalesLines' : 'ServiceInvoiceServLines';
            $invoiceDetailsById[$invoiceId]['Line_Revenue_Total'] = (float) ($financials['revenue'] ?? 0);
            $invoiceDetailsById[$invoiceId]['Line_Cost_Total'] = (float) ($financials['costs'] ?? 0);
            $invoiceDetailsById[$invoiceId]['Line_Count'] = (int) ($financials['line_count'] ?? 0);
        }
    }

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $jobNo = trim((string) ($workorder['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($workorder['Job_Task_No'] ?? ''));
        $workorderNo = trim((string) ($workorder['No'] ?? ''));
        $workorderExternalDocumentNo = trim((string) ($workorder['External_Document_No'] ?? ''));
        $normalizedJobNo = normalize_match_value($jobNo);

        $isInvoiced = false;
        $matchedInvoiceId = '';
        $matchedInvoiceSource = '';
        $invoiceMatchPath = 'none';
        if ($jobNo !== '') {
            $candidateKeys = [];
            if ($jobTaskNo !== '') {
                $candidateKeys[] = workorder_invoice_key($jobNo, $jobTaskNo);
            }
            if ($workorderNo !== '') {
                $candidateKeys[] = workorder_invoice_key($jobNo, $workorderNo);
            }

            foreach ($candidateKeys as $candidateKey) {
                if (isset($invoiceKeys[$candidateKey])) {
                    $isInvoiced = true;
                    $matchedInvoiceId = (string) (($invoiceKeys[$candidateKey]['id'] ?? '') ?: '');
                    $matchedInvoiceSource = (string) (($invoiceKeys[$candidateKey]['source'] ?? '') ?: '');
                    $invoiceMatchPath = 'job_task';
                    break;
                }
            }

            if (!$isInvoiced && isset($invoiceJobOnly[$normalizedJobNo])) {
                $isInvoiced = true;
                $matchedInvoiceId = (string) (($invoiceJobOnly[$normalizedJobNo]['id'] ?? '') ?: '');
                $matchedInvoiceSource = (string) (($invoiceJobOnly[$normalizedJobNo]['source'] ?? '') ?: '');
                $invoiceMatchPath = 'job';
            }

            if (!$isInvoiced) {
                $referenceCandidates = [
                    normalize_match_value($workorderNo),
                    normalize_match_value($workorderExternalDocumentNo),
                ];

                foreach ($referenceCandidates as $referenceCandidate) {
                    if ($referenceCandidate === '') {
                        continue;
                    }

                    if (isset($invoiceReferences[$referenceCandidate])) {
                        $isInvoiced = true;
                        $matchedInvoiceId = (string) (($invoiceReferences[$referenceCandidate]['id'] ?? '') ?: '');
                        $matchedInvoiceSource = (string) (($invoiceReferences[$referenceCandidate]['source'] ?? '') ?: '');
                        $invoiceMatchPath = 'reference';
                        break;
                    }
                }
            }

            if (!$isInvoiced && $normalizedJobNo !== '' && isset($invoiceProjectDimension2[$normalizedJobNo])) {
                $isInvoiced = true;
                $matchedInvoiceId = (string) (($invoiceProjectDimension2[$normalizedJobNo]['id'] ?? '') ?: '');
                $matchedInvoiceSource = (string) (($invoiceProjectDimension2[$normalizedJobNo]['source'] ?? '') ?: '');
                $invoiceMatchPath = 'project_dimension_2';
            }
        }

        if ($invoiceFilter === 'invoiced' && !$isInvoiced) {
            continue;
        }

        if ($invoiceFilter === 'uninvoiced' && $isInvoiced) {
            continue;
        }

        $costItems = (float) ($workorder['KVT_Sum_Work_Order_Cost_Items'] ?? 0);
        $costOther = (float) ($workorder['KVT_Sum_Work_Order_Cost_Other'] ?? 0);
        $workorderActualCosts = $costItems + $costOther;
        $workorderTotalRevenue = abs((float) ($workorder['KVT_Sum_Work_Order_Revenue'] ?? 0));
        $actualCosts = $workorderActualCosts;
        $totalRevenue = $workorderTotalRevenue;
        $amountSource = 'workorder';
        $amountSourceReason = 'Geen factuurmatch gevonden; bedragen uit werkorder gebruikt.';
        $actualCostsSource = 'workorder';
        $actualCostsSourceReason = 'Kosten uit werkordervelden gelezen.';
        $totalRevenueSource = 'workorder';
        $totalRevenueSourceReason = 'Opbrengst uit werkorderveld gelezen.';

        $resolvedInvoiceSource = normalize_invoice_source($matchedInvoiceSource);
        if ($resolvedInvoiceSource === 'unknown' && $matchedInvoiceId !== '' && isset($invoiceDetailsById[$matchedInvoiceId]) && is_array($invoiceDetailsById[$matchedInvoiceId])) {
            $resolvedInvoiceSource = normalize_invoice_source((string) ($invoiceDetailsById[$matchedInvoiceId]['Source'] ?? ''));
        }

        if ($resolvedInvoiceSource === 'app_project') {
            $financialCandidates = [];
            if ($jobNo !== '' && $jobTaskNo !== '') {
                $financialCandidates[] = workorder_invoice_key($jobNo, $jobTaskNo);
            }
            if ($jobNo !== '' && $workorderNo !== '') {
                $financialCandidates[] = workorder_invoice_key($jobNo, $workorderNo);
            }

            foreach ($financialCandidates as $financialCandidateKey) {
                if (!isset($invoiceFinancialByJobTask[$financialCandidateKey])) {
                    continue;
                }

                $financials = $invoiceFinancialByJobTask[$financialCandidateKey];
                $actualCosts = (float) ($financials['costs'] ?? 0);
                $totalRevenue = (float) ($financials['revenue'] ?? 0);
                $amountSource = 'invoice';
                $amountSourceReason = 'Factuurbedragen gevonden via AppProjectInvoices job+taak-koppeling.';
                $matchedInvoiceSource = 'AppProjectInvoices';
                $actualCostsSource = 'invoice';
                $actualCostsSourceReason = 'Kosten uit AppProjectInvoices via job+taak-koppeling.';
                $totalRevenueSource = 'invoice';
                $totalRevenueSourceReason = 'Opbrengst uit AppProjectInvoices via job+taak-koppeling.';

                $financialInvoiceId = trim((string) ($financials['id'] ?? ''));
                if ($financialInvoiceId !== '') {
                    $matchedInvoiceId = $financialInvoiceId;
                }
                break;
            }

            if ($amountSource === 'workorder' && $normalizedJobNo !== '' && isset($invoiceFinancialByJob[$normalizedJobNo])) {
                $financials = $invoiceFinancialByJob[$normalizedJobNo];
                $actualCosts = (float) ($financials['costs'] ?? 0);
                $totalRevenue = (float) ($financials['revenue'] ?? 0);
                $amountSource = 'invoice';
                $amountSourceReason = 'Factuurbedragen gevonden via AppProjectInvoices job-koppeling.';
                $matchedInvoiceSource = 'AppProjectInvoices';
                $actualCostsSource = 'invoice';
                $actualCostsSourceReason = 'Kosten uit AppProjectInvoices via job-koppeling.';
                $totalRevenueSource = 'invoice';
                $totalRevenueSourceReason = 'Opbrengst uit AppProjectInvoices via job-koppeling.';

                $financialInvoiceId = trim((string) ($financials['id'] ?? ''));
                if ($financialInvoiceId !== '') {
                    $matchedInvoiceId = $financialInvoiceId;
                }
            }

            if ($matchedInvoiceId !== '' && isset($invoiceFinancialByDocument[$matchedInvoiceId])) {
                $financialsByDocument = $invoiceFinancialByDocument[$matchedInvoiceId];
                $actualCosts = (float) ($financialsByDocument['costs'] ?? 0);
                $totalRevenue = (float) ($financialsByDocument['revenue'] ?? 0);
                $amountSource = 'invoice';
                $amountSourceReason = 'Factuurbedragen gevonden via AppProjectInvoices documentnummer.';
                $actualCostsSource = 'invoice';
                $actualCostsSourceReason = 'Kosten uit AppProjectInvoices via documentnummer van Factuur ID.';
                $totalRevenueSource = 'invoice';
                $totalRevenueSourceReason = 'Opbrengst uit AppProjectInvoices via documentnummer van Factuur ID.';
            }
        }

        if (($resolvedInvoiceSource === 'sales' || $resolvedInvoiceSource === 'service') && $matchedInvoiceId !== '') {
            $lineFinancials = $invoiceLineFinancialByType[$resolvedInvoiceSource][$matchedInvoiceId] ?? null;
            if (is_array($lineFinancials)) {
                $lineRevenue = (float) ($lineFinancials['revenue'] ?? 0);
                $lineCosts = (float) ($lineFinancials['costs'] ?? 0);
                $lineEntity = $resolvedInvoiceSource === 'sales' ? 'SalesInvoiceSalesLines' : 'ServiceInvoiceServLines';

                if ($lineCosts > 0) {
                    $actualCosts = $lineCosts;
                    $actualCostsSource = 'invoice';
                    $actualCostsSourceReason = 'Kosten uit ' . $lineEntity . '.';
                }

                if ($lineRevenue > 0) {
                    $totalRevenue = $lineRevenue;
                    $totalRevenueSource = 'invoice';
                    $totalRevenueSourceReason = 'Opbrengst uit ' . $lineEntity . '.';
                    $amountSource = 'invoice';
                    $amountSourceReason = 'Opbrengst uit hetzelfde factuurtype (' . $lineEntity . ') gelezen.';
                }
            }

            if (isset($invoiceDetailsById[$matchedInvoiceId]) && is_array($invoiceDetailsById[$matchedInvoiceId])) {
                $invoiceDetails = $invoiceDetailsById[$matchedInvoiceId];
                $revenueField = first_numeric_invoice_field($invoiceDetails, [
                    'Total_Amount_Excl_VAT',
                    'Subtotal_Excl_VAT',
                    'Amount',
                    'Total_Amount_Incl_VAT',
                ]);

                if ($totalRevenueSource !== 'invoice' && $revenueField !== null) {
                    $totalRevenue = (float) ($revenueField['value'] ?? 0);
                    $totalRevenueSource = 'invoice';
                    $totalRevenueSourceReason = 'Opbrengst uit ' . ($matchedInvoiceSource !== '' ? $matchedInvoiceSource : 'factuurheader') . ' (' . (string) ($revenueField['field'] ?? 'onbekend') . ').';
                    $amountSource = 'invoice';
                    $amountSourceReason = 'Opbrengst uit hetzelfde factuurtype (header) gelezen.';
                }
            }

            if ($totalRevenueSource !== 'invoice') {
                $amountSourceReason = 'Factuur ID gevonden voor type ' . ($matchedInvoiceSource !== '' ? $matchedInvoiceSource : 'onbekend') . ', maar geen bruikbare opbrengst; fallback naar werkorder.';
                $totalRevenueSourceReason = 'Geen bruikbare opbrengst gevonden in hetzelfde factuurtype; opbrengst uit werkorder.';
            }
        }

        if ($matchedInvoiceId !== '') {
            $preferredTypes = [];
            if ($resolvedInvoiceSource === 'sales' || $resolvedInvoiceSource === 'service') {
                $preferredTypes[] = $resolvedInvoiceSource;
            }

            foreach (['sales', 'service'] as $candidateType) {
                if (!in_array($candidateType, $preferredTypes, true)) {
                    $preferredTypes[] = $candidateType;
                }
            }

            foreach ($preferredTypes as $candidateType) {
                $candidateFinancials = $invoiceLineFinancialByType[$candidateType][$matchedInvoiceId] ?? null;
                if (!is_array($candidateFinancials)) {
                    continue;
                }

                $candidateRevenue = (float) ($candidateFinancials['revenue'] ?? 0);
                $candidateCosts = (float) ($candidateFinancials['costs'] ?? 0);
                $candidateEntity = $candidateType === 'sales' ? 'SalesInvoiceSalesLines' : 'ServiceInvoiceServLines';

                if ($candidateCosts > 0 && $actualCostsSource !== 'invoice') {
                    $actualCosts = $candidateCosts;
                    $actualCostsSource = 'invoice';
                    $actualCostsSourceReason = 'Kosten uit ' . $candidateEntity . ' (afgeleid op Factuur ID).';
                }

                if ($candidateRevenue > 0 && $totalRevenueSource !== 'invoice') {
                    $totalRevenue = $candidateRevenue;
                    $totalRevenueSource = 'invoice';
                    $totalRevenueSourceReason = 'Opbrengst uit ' . $candidateEntity . ' (afgeleid op Factuur ID).';
                    $amountSource = 'invoice';
                    $amountSourceReason = 'Opbrengst uit ' . $candidateEntity . ' gevonden op basis van Factuur ID.';
                    $resolvedInvoiceSource = $candidateType;
                }

                if ($actualCostsSource === 'invoice' && $totalRevenueSource === 'invoice') {
                    break;
                }
            }
        }

        if ($resolvedInvoiceSource === 'unknown' && $matchedInvoiceId !== '' && $totalRevenueSource !== 'invoice') {
            $amountSourceReason = 'Factuur ID gevonden, maar factuurtype onbekend; fallback naar werkorder.';
            $totalRevenueSourceReason = 'Factuurtype onbekend; opbrengst uit werkorder.';
        }

        if ($totalRevenueSource === 'invoice') {
            $amountSource = 'invoice';
            $amountSourceReason = 'Opbrengst komt uit hetzelfde factuurtype als de gevonden Factuur ID; rij behandeld als factuurgestuurd.';
        }

        $actualTotalSource = 'mixed';
        $actualTotalSourceReason = 'Totaal bestaat uit combinatie van kosten en opbrengstbronnen.';
        if ($actualCostsSource === 'invoice' && $totalRevenueSource === 'invoice') {
            $actualTotalSource = 'invoice';
            $actualTotalSourceReason = 'Totaal berekend op basis van factuurkosten en factuuropbrengst.';
        } elseif ($actualCostsSource === 'workorder' && $totalRevenueSource === 'workorder') {
            $actualTotalSource = 'workorder';
            $actualTotalSourceReason = 'Totaal berekend op basis van werkorderkosten en werkorderopbrengst.';
        } elseif ($totalRevenueSource === 'invoice' && $actualCostsSource === 'workorder') {
            $actualTotalSourceReason = 'Totaal berekend met factuuropbrengst en werkorderkosten.';
        } elseif ($totalRevenueSource === 'workorder' && $actualCostsSource === 'invoice') {
            $actualTotalSourceReason = 'Totaal berekend met werkorderopbrengst en factuurkosten.';
        }

        $actualTotal = $totalRevenue - $actualCosts;

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

        $rows[] = [
            'No' => (string) ($workorder['No'] ?? ''),
            'Order_Type' => (string) ($workorder['Task_Code'] ?? ''),
            'Customer_Id' => (string) ($workorder['Bill_to_Customer_No'] ?? ''),
            'Start_Date' => (string) ($workorder['Start_Date'] ?? ''),
            'Equipment_Number' => $equipmentNumber,
            'Equipment_Name' => (string) ($workorder['Sub_Entity_Description'] ?? ''),
            'Description' => (string) ($workorder['Task_Description'] ?? ''),
            'Customer_Name' => (string) ($workorder['Bill_to_Name'] ?? ''),
            'Actual_Costs' => $actualCosts,
            'Total_Revenue' => $totalRevenue,
            'Actual_Total' => $actualTotal,
            'Cost_Center' => (string) ($workorder['Job_Dimension_1_Value'] ?? ''),
            'Status' => (string) ($workorder['Status'] ?? ''),
            'Notes' => $notesParts,
            'Notes_Search' => implode("\n", $notesSearchParts),
            'Invoice_Id' => $matchedInvoiceId,
            'Amount_Source' => $amountSource,
            'Amount_Source_Reason' => $amountSourceReason,
            'Actual_Costs_Source' => $actualCostsSource,
            'Actual_Costs_Source_Reason' => $actualCostsSourceReason,
            'Total_Revenue_Source' => $totalRevenueSource,
            'Total_Revenue_Source_Reason' => $totalRevenueSourceReason,
            'Actual_Total_Source' => $actualTotalSource,
            'Actual_Total_Source_Reason' => $actualTotalSourceReason,
            'Invoice_Match_Path' => $invoiceMatchPath,
            'Invoice_Match_Source' => $matchedInvoiceSource,
            'Job_No' => $jobNo,
            'End_Date' => (string) ($workorder['End_Date'] ?? ''),
        ];
    }

    usort($rows, function (array $a, array $b): int {
        return strnatcasecmp((string) ($a['No'] ?? ''), (string) ($b['No'] ?? ''));
    });
} catch (Throwable $error) {
    $errorMessage = $error->getMessage();
}

$initialData = [
    'company' => $selectedCompany,
    'from_month' => $fromMonthValue,
    'to_month' => $toMonthValue,
    'invoice_filter' => $invoiceFilter,
    'memo_column_settings' => $memoColumnSettings,
    'save_user_settings_url' => 'index.php?action=save_user_settings',
    'gefactureerd' => $showInvoiced,
    'rows' => $rows,
    'invoice_details_by_id' => $invoiceDetailsById,
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
            width: min(860px, 95vw);
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
        }

        .table-scroll-wrap {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
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
            border-collapse: collapse;
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
            vertical-align: top;
        }

        th {
            background: #f1f5fb;
            color: #203a63;
            font-weight: 700;
            white-space: normal;
            position: sticky;
            top: 0;
            z-index: 5;
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

        th.col-compact,
        td.col-compact {
            width: 88px;
            min-width: 88px;
            max-width: 88px;
            padding: 8px 8px;
        }

        td.col-compact {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        td.invoice-id-clickable {
            cursor: pointer;
            text-decoration: underline;
            text-decoration-style: dotted;
        }

        td.invoice-id-clickable:hover {
            background: #f8fafc;
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
            font-size: 7pt;
        }

        th.col-memo-remarks,
        td.col-memo-remarks {
            width: 120px;
            min-width: 120px;
            max-width: 120px;
        }

        td.col-memo-remarks {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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
            <div id="pageLoaderText">Gegevens laden...</div>
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
        <button type="submit">Toon</button>
        <div class="memo-menu-wrap" id="memoMenuWrap">
            <button type="button" class="memo-menu-trigger" id="memoMenuTrigger">Memo kolommen</button>
            <div class="memo-menu-panel" id="memoMenuPanel">
                <div class="memo-menu-title">Toon als eigen kolom</div>
                <div class="memo-menu-actions">
                    <button type="button" class="memo-menu-action-btn" id="memoMenuAll">Alles</button>
                    <button type="button" class="memo-menu-action-btn" id="memoMenuNone">Niets</button>
                </div>
                <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Memo">Memo</label>
                <label class="memo-menu-option"><input type="checkbox"
                        data-memo-key="Memo_KVT_Memo_Internal_Use_Only">Memo Intern Gebruik</label>
                <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Memo_Invoice">Memo
                    Factuur</label>
                <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Memo_Billing_Details">
                    Memo Bijzonderheden Facturatie</label>
                <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Remarks_Invoicing">
                    Bijzonderheden Facturatie</label>
            </div>
        </div>
        <noscript><button type="submit">Toon</button></noscript>
    </form>

    <div id="app"></div>

    <script>
        window.workorderOverviewData = <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="index.js"></script>
</body>

</html>