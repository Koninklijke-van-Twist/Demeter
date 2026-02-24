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
                '$select' => 'Job_No,Job_Task_No,Document_No,Line_No,Invoiced_Date,Transferred_Date',
                '$filter' => $periodFilter,
            ]);

            $batchInvoices = odata_get_all($invoiceUrl, $auth, 18000);
            foreach ($batchInvoices as $invoice) {
                if (!is_array($invoice)) {
                    continue;
                }

                $invoiceKey = implode('|', [
                    trim((string) ($invoice['Document_No'] ?? '')),
                    (string) ($invoice['Line_No'] ?? ''),
                    trim((string) ($invoice['Job_No'] ?? '')),
                    trim((string) ($invoice['Job_Task_No'] ?? '')),
                    trim((string) ($invoice['Invoiced_Date'] ?? '')),
                    trim((string) ($invoice['Transferred_Date'] ?? '')),
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
    foreach ($invoices as $invoice) {
        if (!is_array($invoice)) {
            continue;
        }

        $invoiceJobNo = trim((string) ($invoice['Job_No'] ?? ''));
        $invoiceJobTaskNo = trim((string) ($invoice['Job_Task_No'] ?? ''));
        $invoiceDocumentNo = trim((string) ($invoice['Document_No'] ?? ''));
        if ($invoiceJobNo === '') {
            continue;
        }

        $invoiceJobOnly[normalize_match_value($invoiceJobNo)] = [
            'id' => $invoiceDocumentNo,
        ];

        if ($invoiceJobTaskNo !== '') {
            $invoiceKeys[workorder_invoice_key($invoiceJobNo, $invoiceJobTaskNo)] = [
                'id' => $invoiceDocumentNo,
            ];
        }

        if ($invoiceDocumentNo !== '') {
            $invoiceReferences[normalize_match_value($invoiceDocumentNo)] = [
                'id' => $invoiceDocumentNo,
            ];
        }
    }

    $additionalInvoiceSources = [
        [
            'entity' => 'SalesInvoices',
            'date_fields' => ['Posting_Date', 'Document_Date'],
            'select' => 'No,External_Document_No,Your_Reference,Posting_Date,Document_Date',
        ],
        [
            'entity' => 'ServiceInvoices',
            'date_fields' => ['Document_Date', 'Order_Date'],
            'select' => 'No,External_Document_No,Your_Reference,Document_Date,Order_Date',
        ],
    ];

    foreach ($additionalInvoiceSources as $source) {
        $entity = (string) ($source['entity'] ?? '');
        $dateFields = $source['date_fields'] ?? [];
        $select = (string) ($source['select'] ?? '');

        if ($entity === '' || !is_array($dateFields) || $select === '') {
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

                $invoiceUrl = company_entity_url_with_query($baseUrl, $environment, $selectedCompany, $entity, [
                    '$select' => $select,
                    '$filter' => $periodFilter,
                ]);

                $batchInvoices = odata_get_all($invoiceUrl, $auth, 18000);
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

                    $referenceCandidates = [
                        trim((string) ($invoice['External_Document_No'] ?? '')),
                        trim((string) ($invoice['Your_Reference'] ?? '')),
                    ];

                    foreach ($referenceCandidates as $referenceValue) {
                        if ($referenceValue === '') {
                            continue;
                        }

                        $sourceInvoiceNo = trim((string) ($invoice['No'] ?? ''));
                        $invoiceReferences[normalize_match_value($referenceValue)] = [
                            'id' => $sourceInvoiceNo,
                        ];
                    }
                }

                $invoiceCursor = $invoiceRangeTo;
            }
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
                    break;
                }
            }

            if (!$isInvoiced && isset($invoiceJobOnly[$normalizedJobNo])) {
                $isInvoiced = true;
                $matchedInvoiceId = (string) (($invoiceJobOnly[$normalizedJobNo]['id'] ?? '') ?: '');
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
                        break;
                    }
                }
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
        $actualCosts = $costItems + $costOther;
        $totalRevenue = abs((float) ($workorder['KVT_Sum_Work_Order_Revenue'] ?? 0));
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

        table {
            width: 100%;
            border-collapse: collapse;
            overflow: visible;
            border-radius: 10px;
            table-layout: fixed;
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
                <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Memo">KVT Memo</label>
                <label class="memo-menu-option"><input type="checkbox"
                        data-memo-key="Memo_KVT_Memo_Internal_Use_Only">KVT Memo Internal Use Only</label>
                <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Memo_Invoice">KVT Memo
                    Invoice</label>
                <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Memo_Billing_Details">KVT
                    Memo Billing Details</label>
                <label class="memo-menu-option"><input type="checkbox" data-memo-key="Memo_KVT_Remarks_Invoicing">KVT
                    Remarks Invoicing</label>
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