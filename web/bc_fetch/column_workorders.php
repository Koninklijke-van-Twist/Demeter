<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/odata_select.php';

/**
 * Functies
 */
/**
 * Haalt werkorders voor een maand op en groepeert ze op projectnummer.
 */
function bc_fetch_column_workorders(string $company, string $yearMonth, array $projectNumbers, array $auth, int $ttl): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
    if (!$from instanceof DateTimeImmutable) {
        return [
            'column' => 'workorders',
            'by_project' => [],
            'all_rows' => [],
        ];
    }

    $to = $from->modify('+1 month');
    $fromStr = $from->format('Y-m-d');
    $toStr = $to->format('Y-m-d');

    $dictionary = bc_fetch_seed_project_dictionary($projectNumbers);

    $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
        '$select' => bc_fetch_werkorders_list_select(),
        '$filter' => 'Start_Date ge ' . $fromStr . ' and Start_Date lt ' . $toStr,
    ]);
    $rows = odata_get_all($url, $auth, $ttl);

    $appWorkordersUrl = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'AppWerkorders', [
        '$select' => bc_fetch_app_werkorders_select(),
        '$filter' => 'Start_Date ge ' . $fromStr . ' and Start_Date lt ' . $toStr,
    ]);
    $appWorkorderRows = odata_get_all($appWorkordersUrl, $auth, $ttl);

    $componentDescriptionByRowKey = [];
    $componentDescriptionByTaskNo = [];
    foreach ($appWorkorderRows as $appWorkorderRow) {
        if (!is_array($appWorkorderRow)) {
            continue;
        }

        $componentDescription = trim((string) ($appWorkorderRow['Component_Description'] ?? ''));
        if ($componentDescription === '') {
            continue;
        }

        $rowKey = implode('|', [
            (string) ($appWorkorderRow['No'] ?? ''),
            (string) ($appWorkorderRow['Job_No'] ?? ''),
            (string) ($appWorkorderRow['Job_Task_No'] ?? ''),
            (string) ($appWorkorderRow['Start_Date'] ?? ''),
        ]);
        if ($rowKey !== '|||') {
            $componentDescriptionByRowKey[$rowKey] = $componentDescription;
        }

        $jobTaskNo = trim((string) ($appWorkorderRow['Job_Task_No'] ?? ''));
        if ($jobTaskNo !== '' && !isset($componentDescriptionByTaskNo[$jobTaskNo])) {
            $componentDescriptionByTaskNo[$jobTaskNo] = $componentDescription;
        }
    }

    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }

        $rowKey = implode('|', [
            (string) ($row['No'] ?? ''),
            (string) ($row['Job_No'] ?? ''),
            (string) ($row['Job_Task_No'] ?? ''),
            (string) ($row['Start_Date'] ?? ''),
        ]);
        $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        $fallbackDescription = trim((string) ($row['Sub_Entity_Description'] ?? ''));

        if (isset($componentDescriptionByRowKey[$rowKey])) {
            $row['Component_Description'] = $componentDescriptionByRowKey[$rowKey];
        } elseif ($jobTaskNo !== '' && isset($componentDescriptionByTaskNo[$jobTaskNo])) {
            $row['Component_Description'] = $componentDescriptionByTaskNo[$jobTaskNo];
        } else {
            $row['Component_Description'] = $fallbackDescription;
        }

        $projectNo = trim((string) ($row['Job_No'] ?? ''));
        if ($projectNo === '') {
            continue;
        }

        $normProjectNo = bc_fetch_normalize_project_no($projectNo);
        if (!isset($dictionary[$normProjectNo])) {
            $dictionary[$normProjectNo] = [];
        }
        if (!isset($dictionary[$normProjectNo]['rows'])) {
            $dictionary[$normProjectNo]['rows'] = [];
        }

        $dictionary[$normProjectNo]['rows'][] = $row;
    }
    unset($row);

    return [
        'column' => 'workorders',
        'by_project' => $dictionary,
        'all_rows' => $rows,
    ];
}
