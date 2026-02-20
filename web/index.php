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

$companies = [
    "Koninklijke van Twist",
    "Hunter van Twist",
    "KVT Gas",
];

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
}

$showInvoiced = strtolower(trim((string) ($_GET['gefactureerd'] ?? 'false'))) === 'true';

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

$toggleQuery = [
    'company' => $selectedCompany,
    'from_month' => $fromMonthValue,
    'to_month' => $toMonthValue,
];
if (!$showInvoiced) {
    $toggleQuery['gefactureerd'] = 'true';
}
$toggleUrl = '?' . http_build_query($toggleQuery, '', '&', PHP_QUERY_RFC3986);
$toggleLabel = $showInvoiced ? 'Toon niet-gefactureerd' : 'Toon gefactureerd';

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

        $workorderUrl = company_entity_url_with_query($baseUrl, $environment, $selectedCompany, 'AppWerkorders', [
            '$select' => 'No,Task_Description,Status,Resource_Name,Main_Entity_Description,Sub_Entity_Description,Job_No,Job_Task_No,External_Document_No,Start_Date,End_Date',
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

    $jobNos = [];
    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $jobNo = trim((string) ($workorder['Job_No'] ?? ''));
        if ($jobNo === '') {
            continue;
        }

        $jobNos[$jobNo] = true;
    }

    $jobNoList = array_keys($jobNos);
    sort($jobNoList, SORT_NATURAL | SORT_FLAG_CASE);

    $projectDescriptions = [];
    $projectBatches = array_chunk($jobNoList, 20);
    foreach ($projectBatches as $batch) {
        $filterParts = [];
        foreach ($batch as $projectNo) {
            $filterParts[] = "No eq '" . escape_odata_string((string) $projectNo) . "'";
        }

        if ($filterParts === []) {
            continue;
        }

        $projectUrl = company_entity_url_with_query($baseUrl, $environment, $selectedCompany, 'AppProjecten', [
            '$select' => 'No,Description',
            '$filter' => implode(' or ', $filterParts),
        ]);

        $batchProjects = odata_get_all($projectUrl, $auth, 18000);
        foreach ($batchProjects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $projectNo = trim((string) ($project['No'] ?? ''));
            if ($projectNo === '') {
                continue;
            }

            $projectDescriptions[$projectNo] = (string) ($project['Description'] ?? '');
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
            'type' => 'project',
        ];

        if ($invoiceJobTaskNo !== '') {
            $invoiceKeys[workorder_invoice_key($invoiceJobNo, $invoiceJobTaskNo)] = [
                'id' => $invoiceDocumentNo,
                'type' => 'project',
            ];
        }

        if ($invoiceDocumentNo !== '') {
            $invoiceReferences[normalize_match_value($invoiceDocumentNo)] = [
                'id' => $invoiceDocumentNo,
                'type' => 'project',
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
                            'type' => $entity === 'SalesInvoices' ? 'sales' : 'service',
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
        $matchedInvoiceType = '';
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
                    $matchedInvoiceType = (string) (($invoiceKeys[$candidateKey]['type'] ?? '') ?: '');
                    break;
                }
            }

            if (!$isInvoiced && isset($invoiceJobOnly[$normalizedJobNo])) {
                $isInvoiced = true;
                $matchedInvoiceId = (string) (($invoiceJobOnly[$normalizedJobNo]['id'] ?? '') ?: '');
                $matchedInvoiceType = (string) (($invoiceJobOnly[$normalizedJobNo]['type'] ?? '') ?: '');
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
                        $matchedInvoiceType = (string) (($invoiceReferences[$referenceCandidate]['type'] ?? '') ?: '');
                        break;
                    }
                }
            }
        }

        if ($showInvoiced && !$isInvoiced) {
            continue;
        }

        if (!$showInvoiced && $isInvoiced) {
            continue;
        }

        $rows[] = [
            'No' => (string) ($workorder['No'] ?? ''),
            'Task_Description' => (string) ($workorder['Task_Description'] ?? ''),
            'Status' => (string) ($workorder['Status'] ?? ''),
            'Resource_Name' => (string) ($workorder['Resource_Name'] ?? ''),
            'Main_Entity_Description' => (string) ($workorder['Main_Entity_Description'] ?? ''),
            'Sub_Entity_Description' => (string) ($workorder['Sub_Entity_Description'] ?? ''),
            'Job_No' => $jobNo,
            'Project_Description' => (string) ($projectDescriptions[$jobNo] ?? ''),
            'Invoice_Id' => $matchedInvoiceId,
            'Invoice_Type' => $matchedInvoiceType,
            'Start_Date' => (string) ($workorder['Start_Date'] ?? ''),
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

        .toggle-mode-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font: inherit;
            border: 1px solid #0f766e;
            border-radius: 8px;
            padding: 7px 10px;
            background: #0f766e;
            color: #fff;
            font-weight: 700;
        }

        .toggle-mode-btn:hover {
            background: #0d625c;
        }

        .summary {
            margin-bottom: 12px;
            font-weight: 600;
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

        .status-filter-btn.status-open {
            background: #fff9db;
        }

        .status-filter-btn.status-signed {
            background: #e9f9ee;
        }

        .status-filter-btn.status-completed {
            background: #e9f2ff;
        }

        .status-filter-btn.status-checked {
            background: #fff1dd;
        }

        .status-filter-btn.status-cancelled {
            background: #c9a7a7;
        }

        .status-filter-btn.status-closed {
            background: #c5c5c5;
        }

        .status-filter-btn.status-planned {
            background: #f5ddff;
        }

        .status-filter-btn.status-in-progress {
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
            overflow: hidden;
            border-radius: 10px;
        }

        th,
        td {
            border-bottom: 1px solid #e7edf5;
            padding: 10px 12px;
            text-align: left;
            min-width: 100px;
            vertical-align: top;
        }

        th {
            background: #f1f5fb;
            color: #203a63;
            font-weight: 700;
            white-space: nowrap;
        }

        th[role="button"] {
            cursor: pointer;
            user-select: none;
        }

        th[role="button"]:hover {
            background: #e4edf9;
        }

        tbody tr:hover {
            filter: brightness(0.98);
        }

        tbody tr.status-open {
            background: #fff9db;
        }

        tbody tr.status-signed {
            background: #e9f9ee;
        }

        tbody tr.status-completed {
            background: #e9f2ff;
        }

        tbody tr.status-checked {
            background: #fff1dd;
        }

        tbody tr.status-cancelled {
            background: #c9a7a7;
        }

        tbody tr.status-closed {
            background: #c5c5c5;
        }

        tbody tr.status-planned {
            background: #f5ddff;
        }

        tbody tr.status-in-progress {
            background: #ffe9e9;
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
        <?php if ($showInvoiced): ?>
            <input type="hidden" name="gefactureerd" value="true">
        <?php endif; ?>
        <button type="submit">Toon</button>
        <a href="<?= htmlspecialchars($toggleUrl) ?>" class="toggle-mode-btn"><?= htmlspecialchars($toggleLabel) ?></a>
        <noscript><button type="submit">Toon</button></noscript>
    </form>

    <div id="app"></div>

    <script>
        window.workorderOverviewData = <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="index.js"></script>
</body>

</html>