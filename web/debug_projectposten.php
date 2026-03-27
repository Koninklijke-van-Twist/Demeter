<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/project_finance.php';
require_once __DIR__ . '/bc_fetch/helpers.php';

$companies = [
    'Koninklijke van Twist',
    'Hunter van Twist',
    'KVT Gas',
];

$selectedCompany = trim((string) ($_GET['company'] ?? $companies[0]));
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
}

$projects = [
    '4083836',
    'PRJ2605156',
    '4087272',
    'PRJ2600600',
    'PRJ2600158',
];

$defaultToMonth = new DateTimeImmutable('first day of this month');
$defaultFromMonth = $defaultToMonth->modify('-3 years');

$fromMonthText = trim((string) ($_GET['from_month'] ?? $defaultFromMonth->format('Y-m')));
$toMonthText = trim((string) ($_GET['to_month'] ?? $defaultToMonth->format('Y-m')));

$fromMonth = DateTimeImmutable::createFromFormat('!Y-m', $fromMonthText);
$toMonth = DateTimeImmutable::createFromFormat('!Y-m', $toMonthText);
if (!$fromMonth instanceof DateTimeImmutable) {
    $fromMonth = $defaultFromMonth;
}
if (!$toMonth instanceof DateTimeImmutable) {
    $toMonth = $defaultToMonth;
}
if ($fromMonth > $toMonth) {
    [$fromMonth, $toMonth] = [$toMonth, $fromMonth];
}

$fromDate = $fromMonth->format('Y-m-d');
$toDateExclusive = $toMonth->modify('+1 month')->format('Y-m-d');

$ttl = 3600;
$errorMessage = '';
$debugByProject = [];

function debug_escape_odata(string $value): string
{
    return str_replace("'", "''", $value);
}

function debug_norm(string $value): string
{
    return strtolower(trim($value));
}

function debug_float(mixed $value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

try {
    $financeService = new ProjectFinanceService($selectedCompany);
    $rangeData = $financeService->collectProjectAndWorkorderFinanceFromProjectPostenRange($fromDate, $toDateExclusive, $ttl);
    $projectTotalsByJob = is_array($rangeData['project_totals_by_job'] ?? null)
        ? $rangeData['project_totals_by_job']
        : [];

    foreach ($projects as $projectNo) {
        $projectNoText = trim($projectNo);
        if ($projectNoText === '') {
            continue;
        }

        $projectFilter = "Job_No eq '" . debug_escape_odata($projectNoText) . "'";
        $postenUrl = company_entity_url_with_query($baseUrl, $environment, $selectedCompany, 'ProjectPosten', [
            '$select' => 'Job_No,Job_Task_No,Posting_Date,Entry_Type,Total_Cost,Line_Amount,Description,No,Type',
            '$filter' => "Posting_Date ge $fromDate and Posting_Date lt $toDateExclusive and ($projectFilter)",
        ]);
        $postenRows = odata_get_all($postenUrl, $auth, $ttl);

        $rawCostSum = 0.0;
        $rawRevenueSum = 0.0;
        $rawRevenueInvSum = 0.0;
        $rawRevenueSumVerkoop = 0.0;
        $rawRevenueInvSumVerkoop = 0.0;
        $workorderSetFromPosten = [];
        $postenByWorkorder = [];

        foreach ($postenRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cost = debug_float($row['Total_Cost'] ?? 0.0);
            $lineAmount = debug_float($row['Line_Amount'] ?? 0.0);
            $entryType = trim((string) ($row['Entry_Type'] ?? ''));

            $rawCostSum += $cost;
            $rawRevenueSum += $lineAmount;
            $rawRevenueInvSum += (-1 * $lineAmount);

            // Revenue only from 'Verkoop' entries
            if ($entryType === 'Verkoop') {
                $rawRevenueSumVerkoop += $lineAmount;
                $rawRevenueInvSumVerkoop += (-1 * $lineAmount);
            }

            $taskNo = trim((string) ($row['Job_Task_No'] ?? ''));
            if ($taskNo !== '') {
                $workorderSetFromPosten[$taskNo] = true;
                if (!isset($postenByWorkorder[$taskNo])) {
                    $postenByWorkorder[$taskNo] = [
                        'rows' => 0,
                        'cost' => 0.0,
                        'line_amount' => 0.0,
                        'line_amount_inverted' => 0.0,
                    ];
                }
                $postenByWorkorder[$taskNo]['rows']++;
                $postenByWorkorder[$taskNo]['cost'] += $cost;
                $postenByWorkorder[$taskNo]['line_amount'] += $lineAmount;
                $postenByWorkorder[$taskNo]['line_amount_inverted'] += (-1 * $lineAmount);
            }
        }

        $workorderUrl = company_entity_url_with_query($baseUrl, $environment, $selectedCompany, 'Werkorders', [
            '$select' => 'No,Job_No,Job_Task_No,Start_Date,End_Date,Task_Description',
            '$filter' => "Start_Date ge $fromDate and Start_Date lt $toDateExclusive and ($projectFilter)",
        ]);
        $workorderRows = odata_get_all($workorderUrl, $auth, $ttl);

        $workorderTaskSet = [];
        foreach ($workorderRows as $woRow) {
            if (!is_array($woRow)) {
                continue;
            }
            $taskNo = trim((string) ($woRow['Job_Task_No'] ?? ''));
            if ($taskNo !== '') {
                $workorderTaskSet[$taskNo] = true;
            }
        }

        $normalizedProject = debug_norm($projectNoText);
        $serviceTotals = is_array($projectTotalsByJob[$normalizedProject] ?? null)
            ? $projectTotalsByJob[$normalizedProject]
            : ['costs' => 0.0, 'revenue' => 0.0, 'resultaat' => 0.0];

        ksort($postenByWorkorder, SORT_NATURAL | SORT_FLAG_CASE);

        $debugByProject[$projectNoText] = [
            'project_no' => $projectNoText,
            'service_costs' => debug_float($serviceTotals['costs'] ?? 0.0),
            'service_revenue' => debug_float($serviceTotals['revenue'] ?? 0.0),
            'service_result' => debug_float($serviceTotals['resultaat'] ?? 0.0),
            'raw_posten_cost_sum' => $rawCostSum,
            'raw_posten_line_amount_sum' => $rawRevenueSum,
            'raw_posten_line_amount_inverted_sum' => $rawRevenueInvSum,
            'raw_posten_line_amount_verkoop_sum' => $rawRevenueSumVerkoop,
            'raw_posten_line_amount_verkoop_inverted_sum' => $rawRevenueInvSumVerkoop,
            'posten_row_count' => count($postenRows),
            'posten_workorder_count' => count($workorderSetFromPosten),
            'workorders_row_count' => count($workorderRows),
            'workorders_distinct_task_count' => count($workorderTaskSet),
            'posten_by_workorder' => $postenByWorkorder,
            'posten_rows' => $postenRows,
            'workorder_rows' => $workorderRows,
        ];
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function f(float $value): string
{
    return number_format($value, 2, ',', '.');
}
?><!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Debug ProjectPosten</title>
    <style>
        body {
            margin: 0;
            padding: 16px;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #1e293b;
        }

        .financial-highlight {
            background: #fef08a;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 4px;
        }

        h1,
        h2,
        h3 {
            margin: 0 0 10px;
        }

        .card {
            background: #fff;
            border: 1px solid #dbe4f0;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 14px;
        }

        .meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 13px;
        }

        th,
        td {
            border: 1px solid #dbe4f0;
            padding: 6px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #eef3fb;
            position: sticky;
            top: 0;
        }

        .error {
            border: 1px solid #f0b0b0;
            background: #fff2f2;
            color: #8a1f1f;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 12px;
        }

        .ok {
            color: #0f5132;
            font-weight: 700;
        }

        .warn {
            color: #8a3f00;
            font-weight: 700;
        }

        form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: end;
        }

        label {
            font-size: 13px;
            font-weight: 700;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        input,
        select,
        button {
            font: inherit;
            padding: 6px 8px;
        }

        .small {
            color: #475569;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <div class="card">
        <h1>Debug ProjectPosten vs Projecttotalen</h1>
        <p class="small">Deze pagina toont alleen bronregels uit ProjectPosten en de service-totalen die daaruit komen.
            Facturen worden hier niet gebruikt voor kosten/opbrengst.</p>
        <form method="get" action="debug_projectposten.php">
            <label>Bedrijf
                <select name="company">
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= h($company) ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                            <?= h($company) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Vanaf maand
                <input type="month" name="from_month" value="<?= h($fromMonth->format('Y-m')) ?>">
            </label>
            <label>Tot en met maand
                <input type="month" name="to_month" value="<?= h($toMonth->format('Y-m')) ?>">
            </label>
            <button type="submit">Vernieuwen</button>
        </form>
        <p class="small">Range: <?= h($fromDate) ?> t/m <?= h($toDateExclusive) ?> (exclusive)</p>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="error">Fout: <?= h($errorMessage) ?></div>
    <?php endif; ?>

    <?php foreach ($debugByProject as $projectNo => $data): ?>
        <div class="card">
            <h2>Project <?= h($projectNo) ?></h2>
            <?php
            $deltaCost = $data['service_costs'] - $data['raw_posten_cost_sum'];
            $deltaRev = $data['service_revenue'] - $data['raw_posten_line_amount_inverted_sum'];
            $matches = abs($deltaCost) < 0.0001 && abs($deltaRev) < 0.0001;
            ?>
            <p class="<?= $matches ? 'ok' : 'warn' ?>">
                <?= $matches ? 'OK: service-totalen matchen met ProjectPosten-som.' : 'LET OP: service-totalen wijken af van ProjectPosten-som.' ?>
            </p>
            <div class="meta">
                <div>Service costs: <strong class="financial-highlight"><?= f($data['service_costs']) ?></strong></div>
                <div>Service revenue (inverted): <strong
                        class="financial-highlight"><?= f($data['service_revenue']) ?></strong></div>
                <div>Service result: <strong><?= f($data['service_result']) ?></strong></div>
                <div>ProjectPosten cost sum: <strong
                        class="financial-highlight"><?= f($data['raw_posten_cost_sum']) ?></strong></div>
                <div>ProjectPosten line_amount sum raw: <strong><?= f($data['raw_posten_line_amount_sum']) ?></strong></div>
                <div>ProjectPosten line_amount sum inverted:
                    <strong><?= f($data['raw_posten_line_amount_inverted_sum']) ?></strong>
                </div>
                <div>ProjectPosten line_amount (Verkoop only) sum raw: <strong
                        class="financial-highlight"><?= f($data['raw_posten_line_amount_verkoop_sum']) ?></strong></div>
                <div>ProjectPosten line_amount (Verkoop only) sum inverted:
                    <strong
                        class="financial-highlight"><?= f($data['raw_posten_line_amount_verkoop_inverted_sum']) ?></strong>
                </div>
                <div>Delta costs (service - posten): <strong><?= f($deltaCost) ?></strong></div>
                <div>Delta revenue (service - inverted posten): <strong><?= f($deltaRev) ?></strong></div>
                <div>ProjectPosten rows: <strong><?= (int) $data['posten_row_count'] ?></strong></div>
                <div>ProjectPosten distinct Job_Task_No: <strong><?= (int) $data['posten_workorder_count'] ?></strong></div>
                <div>Werkorders rows: <strong><?= (int) $data['workorders_row_count'] ?></strong></div>
                <div>Werkorders distinct Job_Task_No: <strong><?= (int) $data['workorders_distinct_task_count'] ?></strong>
                </div>
            </div>

            <h3>ProjectPosten per Job_Task_No</h3>
            <table>
                <thead>
                    <tr>
                        <th>Job_Task_No</th>
                        <th>Aantal regels</th>
                        <th>Som Total_Cost</th>
                        <th>Som Line_Amount raw</th>
                        <th>Som Line_Amount inverted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['posten_by_workorder'] as $taskNo => $agg): ?>
                        <tr>
                            <td><?= h((string) $taskNo) ?></td>
                            <td><?= (int) ($agg['rows'] ?? 0) ?></td>
                            <td><?= f((float) ($agg['cost'] ?? 0.0)) ?></td>
                            <td><?= f((float) ($agg['line_amount'] ?? 0.0)) ?></td>
                            <td><?= f((float) ($agg['line_amount_inverted'] ?? 0.0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3>ProjectPosten regels</h3>
            <table>
                <thead>
                    <tr>
                        <th>Posting_Date</th>
                        <th>Job_No</th>
                        <th>Job_Task_No</th>
                        <th>Entry_Type</th>
                        <th>Type</th>
                        <th>No</th>
                        <th>Description</th>
                        <th>Total_Cost</th>
                        <th>Line_Amount</th>
                        <th>Line_Amount inverted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['posten_rows'] as $row): ?>
                        <?php if (!is_array($row)) {
                            continue;
                        } ?>
                        <tr>
                            <td><?= h((string) ($row['Posting_Date'] ?? '')) ?></td>
                            <td><?= h((string) ($row['Job_No'] ?? '')) ?></td>
                            <td><?= h((string) ($row['Job_Task_No'] ?? '')) ?></td>
                            <td><?= h((string) ($row['Entry_Type'] ?? '')) ?></td>
                            <td><?= h((string) ($row['Type'] ?? '')) ?></td>
                            <td><?= h((string) ($row['No'] ?? '')) ?></td>
                            <td><?= h((string) ($row['Description'] ?? '')) ?></td>
                            <td><?= f(debug_float($row['Total_Cost'] ?? 0.0)) ?></td>
                            <td><?= f(debug_float($row['Line_Amount'] ?? 0.0)) ?></td>
                            <td><?= f(-1 * debug_float($row['Line_Amount'] ?? 0.0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Werkorders regels (ter vergelijking, niet gebruikt voor projecttotalen)</h3>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Job_No</th>
                        <th>Job_Task_No</th>
                        <th>Start_Date</th>
                        <th>End_Date</th>
                        <th>Task_Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data['workorder_rows'] as $row): ?>
                        <?php if (!is_array($row)) {
                            continue;
                        } ?>
                        <tr>
                            <td><?= h((string) ($row['No'] ?? '')) ?></td>
                            <td><?= h((string) ($row['Job_No'] ?? '')) ?></td>
                            <td><?= h((string) ($row['Job_Task_No'] ?? '')) ?></td>
                            <td><?= h((string) ($row['Start_Date'] ?? '')) ?></td>
                            <td><?= h((string) ($row['End_Date'] ?? '')) ?></td>
                            <td><?= h((string) ($row['Task_Description'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</body>

</html>