<?php

/**
 * Werkorders ophalen op basis van ProjectPosten job/task-paren.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cost_center.php';
require_once __DIR__ . '/workorder_state_cache.php';

/**
 * Extra velden op ProjectPosten voor werkorder-discovery en finance-matching.
 */
function bc_fetch_projectposten_discovery_fields(): array
{
    return [
        'Job_Task_No',
        'LVS_Work_Order_No',
        'LVS_Global_Dimension_1_Code',
        'Global_Dimension_1_Code',
    ];
}

/**
 * Haalt unieke job/task-paren en finance-keys uit ruwe ProjectPosten-rijen.
 *
 * @return array{pairs: list<array{job_no: string, job_task_no: string}>, finance_key_by_pair: array<string, string>, pair_keys_in_posten: array<string, bool>}
 */
function bc_fetch_extract_workorder_keys_from_projectposten_rows(array $rows): array
{
    $pairs = [];
    $financeKeyByPair = [];
    $pairKeysInPosten = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $pairKey = demeter_workorder_pair_key($jobNo, $jobTaskNo);
        $pairs[$pairKey] = [
            'job_no' => $jobNo,
            'job_task_no' => $jobTaskNo,
        ];
        $pairKeysInPosten[$pairKey] = true;

        $lvsWorkOrderNo = trim((string) ($row['LVS_Work_Order_No'] ?? ''));
        $financeKey = $lvsWorkOrderNo !== '' ? $lvsWorkOrderNo : $jobTaskNo;
        if (!isset($financeKeyByPair[$pairKey]) || $lvsWorkOrderNo !== '') {
            $financeKeyByPair[$pairKey] = strtolower(trim($financeKey));
        }
    }

    return [
        'pairs' => array_values($pairs),
        'finance_key_by_pair' => $financeKeyByPair,
        'pair_keys_in_posten' => $pairKeysInPosten,
    ];
}

/**
 * Bepaalt welke job/task-paren opgehaald moeten worden (incrementeel of volledig).
 *
 * @param array<string, bool> $pairKeysInPosten
 * @param array<string, array> $cachedWorkorders
 * @return array{fetch_pairs: list<array{job_no: string, job_task_no: string}>, stale_pairs: list<array{job_no: string, job_task_no: string}>, use_cached_rows: list<array>}
 */
function bc_fetch_resolve_workorder_fetch_plan(array $pairs, array $pairKeysInPosten, ?array $cachedState, bool $forceFull): array
{
    $fetchPairs = [];
    $stalePairs = [];
    $useCachedRows = [];
    $cachedWorkorders = is_array($cachedState) && is_array($cachedState['workorders'] ?? null) ? $cachedState['workorders'] : [];

    if ($forceFull || $cachedState === null) {
        return [
            'fetch_pairs' => $pairs,
            'stale_pairs' => [],
            'use_cached_rows' => [],
        ];
    }

    $seenFetch = [];

    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $pairKey = demeter_workorder_pair_key($jobNo, $jobTaskNo);
        $cachedEntry = $cachedWorkorders[$pairKey] ?? null;
        if (is_array($cachedEntry) && is_array($cachedEntry['row'] ?? null)) {
            $useCachedRows[] = $cachedEntry['row'];
            continue;
        }

        $seenFetch[$pairKey] = true;
        $fetchPairs[] = [
            'job_no' => $jobNo,
            'job_task_no' => $jobTaskNo,
        ];
    }

    foreach ($cachedWorkorders as $pairKey => $cachedEntry) {
        if (!is_string($pairKey) || $pairKey === '' || !is_array($cachedEntry)) {
            continue;
        }

        if (!empty($cachedEntry['is_closed'])) {
            continue;
        }

        if (isset($pairKeysInPosten[$pairKey])) {
            continue;
        }

        $jobNo = trim((string) ($cachedEntry['job_no'] ?? ''));
        $jobTaskNo = trim((string) ($cachedEntry['job_task_no'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        if (isset($seenFetch[$pairKey])) {
            continue;
        }

        $seenFetch[$pairKey] = true;
        $stalePairs[] = [
            'job_no' => $jobNo,
            'job_task_no' => $jobTaskNo,
        ];
    }

    return [
        'fetch_pairs' => $fetchPairs,
        'stale_pairs' => $stalePairs,
        'use_cached_rows' => $useCachedRows,
    ];
}

/**
 * Haalt Werkorders (+ AppWerkorders voor componentomschrijving) op voor job/task-paren.
 */
function bc_fetch_workorders_by_job_task_pairs(string $company, array $pairs, array $auth, int $ttl): array
{
    if ($pairs === []) {
        return [];
    }

    $werkorderSelect = 'No,Task_Code,Task_Description,Status,KVT_Document_Status,Job_No,Job_Task_No,Contract_No,External_Document_No,Start_Date,End_Date,Sub_Entity,Sub_Entity_Description,Component_No,Serial_No,Bill_to_Customer_No,Bill_to_Name,Sell_to_Customer_No,Sell_to_Name,Job_Dimension_1_Value,Memo,Memo_Internal_Use_Only,Memo_Invoice,KVT_Memo_Invoice_Details,KVT_Remarks_Invoicing,LVS_Show_on_Planboard,LVS_Fixed_Planned';
    $appSelect = 'No,Job_No,Job_Task_No,Start_Date,Component_Description';

    $wantedPairKeys = [];
    $jobNos = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $wantedPairKeys[demeter_workorder_pair_key($jobNo, $jobTaskNo)] = true;
        $jobNos[$jobNo] = true;
    }

    if ($wantedPairKeys === []) {
        return [];
    }

    $allRows = [];
    $seenRowKeys = [];
    $componentDescriptionByRowKey = [];
    $componentDescriptionByTaskNo = [];

    foreach (array_keys($jobNos) as $jobNo) {
        $escapedJobNo = str_replace("'", "''", $jobNo);
        $filter = "Job_No eq '" . $escapedJobNo . "'";

        $werkordersUrl = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
            '$select' => $werkorderSelect,
            '$filter' => $filter,
        ]);
        $werkorderRows = odata_get_all($werkordersUrl, $auth, $ttl);

        $appWorkordersUrl = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'AppWerkorders', [
            '$select' => $appSelect,
            '$filter' => $filter,
        ]);
        $appWorkorderRows = odata_get_all($appWorkordersUrl, $auth, $ttl);

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

            $taskNo = trim((string) ($appWorkorderRow['Job_Task_No'] ?? ''));
            if ($taskNo !== '' && !isset($componentDescriptionByTaskNo[$taskNo])) {
                $componentDescriptionByTaskNo[$taskNo] = $componentDescription;
            }
        }

        foreach ($werkorderRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
            $pairKey = demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), $jobTaskNo);
            if (!isset($wantedPairKeys[$pairKey])) {
                continue;
            }

            $rowKey = implode('|', [
                (string) ($row['No'] ?? ''),
                (string) ($row['Job_No'] ?? ''),
                (string) ($row['Job_Task_No'] ?? ''),
                (string) ($row['Start_Date'] ?? ''),
            ]);
            if (isset($seenRowKeys[$rowKey])) {
                continue;
            }

            $seenRowKeys[$rowKey] = true;
            $taskNo = trim((string) ($row['Job_Task_No'] ?? ''));
            $fallbackDescription = trim((string) ($row['Sub_Entity_Description'] ?? ''));

            if (isset($componentDescriptionByRowKey[$rowKey])) {
                $row['Component_Description'] = $componentDescriptionByRowKey[$rowKey];
            } elseif ($taskNo !== '' && isset($componentDescriptionByTaskNo[$taskNo])) {
                $row['Component_Description'] = $componentDescriptionByTaskNo[$taskNo];
            } else {
                $row['Component_Description'] = $fallbackDescription;
            }

            $allRows[] = $row;
        }
    }

    $foundPairKeys = [];
    foreach ($allRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $foundPairKeys[demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''))] = true;
    }

    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $pairKey = demeter_workorder_pair_key($jobNo, $jobTaskNo);
        if (isset($foundPairKeys[$pairKey])) {
            continue;
        }

        $escapedJobNo = str_replace("'", "''", $jobNo);
        $escapedJobTaskNo = str_replace("'", "''", $jobTaskNo);
        $pairFilter = "Job_No eq '" . $escapedJobNo . "' and Job_Task_No eq '" . $escapedJobTaskNo . "'";

        $pairWerkordersUrl = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
            '$select' => $werkorderSelect,
            '$filter' => $pairFilter,
        ]);
        $pairRows = odata_get_all($pairWerkordersUrl, $auth, $ttl);

        foreach ($pairRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowKey = implode('|', [
                (string) ($row['No'] ?? ''),
                (string) ($row['Job_No'] ?? ''),
                (string) ($row['Job_Task_No'] ?? ''),
                (string) ($row['Start_Date'] ?? ''),
            ]);
            if (isset($seenRowKeys[$rowKey])) {
                continue;
            }

            $seenRowKeys[$rowKey] = true;
            $row['Component_Description'] = trim((string) ($row['Sub_Entity_Description'] ?? ''));
            $allRows[] = $row;
            $foundPairKeys[$pairKey] = true;
        }
    }

    return $allRows;
}

/**
 * Voegt werkorderrijen samen zonder duplicaten.
 */
function bc_fetch_merge_workorder_rows(array ...$rowLists): array
{
    $merged = [];
    $seenRowKeys = [];

    foreach ($rowLists as $rowList) {
        foreach ($rowList as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowKey = implode('|', [
                (string) ($row['No'] ?? ''),
                (string) ($row['Job_No'] ?? ''),
                (string) ($row['Job_Task_No'] ?? ''),
                (string) ($row['Start_Date'] ?? ''),
            ]);

            if (isset($seenRowKeys[$rowKey])) {
                continue;
            }

            $seenRowKeys[$rowKey] = true;
            $merged[] = $row;
        }
    }

    return $merged;
}

/**
 * Bouwt de cache-state op basis van opgehaalde werkorders en bestaande cache.
 *
 * @param array<string, bool> $pairKeysInPosten
 */
function bc_fetch_build_workorder_state_cache(
    array $workorderRows,
    array $financeKeyByPair,
    array $pairKeysInPosten,
    ?array $existingCache
): array {
    $state = is_array($existingCache) && is_array($existingCache['workorders'] ?? null) ? $existingCache['workorders'] : [];
    $updatedPairKeys = [];

    foreach ($workorderRows as $workorderRow) {
        if (!is_array($workorderRow)) {
            continue;
        }

        $jobNo = trim((string) ($workorderRow['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($workorderRow['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $pairKey = demeter_workorder_pair_key($jobNo, $jobTaskNo);
        $updatedPairKeys[$pairKey] = true;
        $financeKey = $financeKeyByPair[$pairKey] ?? strtolower($jobTaskNo);
        $seenInPosten = isset($pairKeysInPosten[$pairKey]);
        $existingEntry = $state[$pairKey] ?? null;
        if (is_array($existingEntry) && !empty($existingEntry['is_closed']) && is_array($existingEntry['row'] ?? null)) {
            continue;
        }

        $state[$pairKey] = demeter_workorder_state_cache_entry_from_row($workorderRow, $financeKey, $seenInPosten);
    }

    foreach ($state as $pairKey => $entry) {
        if (!is_string($pairKey) || !is_array($entry)) {
            continue;
        }

        if (isset($updatedPairKeys[$pairKey])) {
            continue;
        }

        if (!empty($entry['is_closed'])) {
            continue;
        }

        if (!isset($pairKeysInPosten[$pairKey])) {
            continue;
        }

        $entry['last_seen_in_posten'] = gmdate('Y-m-d');
        $state[$pairKey] = $entry;
    }

    return $state;
}
