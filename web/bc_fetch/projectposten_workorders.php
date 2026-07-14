<?php

/**
 * Werkorders ophalen op basis van ProjectPosten job/task-paren.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/cost_center.php';
require_once __DIR__ . '/workorder_state_cache.php';
require_once __DIR__ . '/odata_select.php';

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
 * - fetch_pairs: volledige BC-refresh (alleen nieuw ontbrekend in cache)
 * - status_check_pairs: lichte status-check (open, niet in posten, nog niet deze sessie)
 * - use_cached_rows: gesloten of open met behouden metadata (finance/status via ProjectPosten + status-check)
 *
 * @param array<string, bool> $pairKeysInPosten
 * @return array{
 *   fetch_pairs: list<array{job_no: string, job_task_no: string}>,
 *   status_check_pairs: list<array{job_no: string, job_task_no: string}>,
 *   use_cached_rows: list<array>
 * }
 */
function bc_fetch_resolve_workorder_fetch_plan(
    array $pairs,
    array $pairKeysInPosten,
    ?array $cachedState,
    bool $forceFull,
    string $loadSessionId = ''
): array {
    $fetchPairs = [];
    $statusCheckPairs = [];
    $useCachedRows = [];
    $cachedWorkorders = is_array($cachedState) && is_array($cachedState['workorders'] ?? null) ? $cachedState['workorders'] : [];

    if ($forceFull || $cachedState === null) {
        return [
            'fetch_pairs' => $pairs,
            'status_check_pairs' => [],
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
        if (!is_array($cachedEntry) || !is_array($cachedEntry['row'] ?? null)) {
            $seenFetch[$pairKey] = true;
            $fetchPairs[] = [
                'job_no' => $jobNo,
                'job_task_no' => $jobTaskNo,
            ];
            continue;
        }

        if (!empty($cachedEntry['is_closed'])) {
            $useCachedRows[] = $cachedEntry['row'];
            continue;
        }

        // Open + in ProjectPosten: metadata uit cache; finance/status via ProjectPosten + status-check.
        $useCachedRows[] = $cachedEntry['row'];
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

        if (demeter_workorder_state_is_pair_status_checked_this_session($cachedState, $loadSessionId, $pairKey)) {
            if (is_array($cachedEntry['row'] ?? null)) {
                $useCachedRows[] = $cachedEntry['row'];
            }
            continue;
        }

        $seenFetch[$pairKey] = true;
        $statusCheckPairs[] = [
            'job_no' => $jobNo,
            'job_task_no' => $jobTaskNo,
        ];
    }

    return [
        'fetch_pairs' => $fetchPairs,
        'status_check_pairs' => $statusCheckPairs,
        'use_cached_rows' => $useCachedRows,
    ];
}

/**
 * Bouwt een OData OR-filter voor stringvelden.
 *
 * @param list<string> $values
 */
function bc_fetch_build_odata_or_equals_filter(string $field, array $values): string
{
    $parts = [];
    foreach ($values as $value) {
        if (!is_string($value) && !is_numeric($value)) {
            continue;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            continue;
        }

        $escaped = str_replace("'", "''", $trimmed);
        $parts[] = $field . " eq '" . $escaped . "'";
    }

    if ($parts === []) {
        throw new InvalidArgumentException('Geen waarden voor OData OR-filter.');
    }

    return '(' . implode(' or ', $parts) . ')';
}

/**
 * Bouwt een stabiele rij-key voor Werkorders/AppWerkorders.
 */
function bc_fetch_werkorder_row_key(array $row): string
{
    return implode('|', [
        (string) ($row['No'] ?? ''),
        (string) ($row['Job_No'] ?? ''),
        (string) ($row['Job_Task_No'] ?? ''),
        (string) ($row['Start_Date'] ?? ''),
    ]);
}

/**
 * @param list<array{job_no: string, job_task_no: string}> $pairs
 * @return list<string>
 */
function bc_fetch_unique_job_nos_from_pairs(array $pairs): array
{
    $jobNos = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        if ($jobNo !== '') {
            $jobNos[$jobNo] = $jobNo;
        }
    }

    return array_values($jobNos);
}

/**
 * BC ondersteunt geen OR van samengestelde Job_No+Job_Task_No filters.
 * Eén paar met een simpele AND-filter werkt wel.
 */
function bc_fetch_build_odata_single_pair_filter(string $jobNo, string $jobTaskNo): string
{
    $escapedJobNo = str_replace("'", "''", trim($jobNo));
    $escapedJobTaskNo = str_replace("'", "''", trim($jobTaskNo));

    return "Job_No eq '" . $escapedJobNo . "' and Job_Task_No eq '" . $escapedJobTaskNo . "'";
}

/**
 * Zet Component_Description op basis van Sub_Entity_Description (AppWerkorders niet nodig).
 */
function bc_fetch_apply_sub_entity_component_description(array $row): array
{
    $row['Component_Description'] = trim((string) ($row['Sub_Entity_Description'] ?? ''));

    return $row;
}

/**
 * Voegt opgehaalde werkorderrijen toe aan het resultaat (met pair-filter en dedup).
 *
 * @param array<string, bool> $wantedPairKeys
 * @param array<string, bool> $seenRowKeys
 * @param list<array> $allRows
 */
function bc_fetch_collect_matching_werkorder_rows(
    array $werkorderRows,
    array $wantedPairKeys,
    array &$seenRowKeys,
    array &$allRows
): void {
    foreach ($werkorderRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $pairKey = demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''));
        if (!isset($wantedPairKeys[$pairKey])) {
            continue;
        }

        $rowKey = bc_fetch_werkorder_row_key($row);
        if (isset($seenRowKeys[$rowKey])) {
            continue;
        }

        $seenRowKeys[$rowKey] = true;
        $allRows[] = bc_fetch_apply_sub_entity_component_description($row);
    }
}

/**
 * @param list<string> $jobNos
 * @return list<list<string>>
 */
function bc_fetch_chunk_string_values(array $jobNos, int $chunkSize): array
{
    $chunkSize = max(1, $chunkSize);
    $values = array_values(array_unique(array_filter(array_map('strval', $jobNos), static function (string $value): bool {
        return trim($value) !== '';
    })));

    return array_chunk($values, $chunkSize);
}

/**
 * Groepeert paren per Job_No.
 *
 * @param list<array{job_no: string, job_task_no: string}> $pairs
 * @return array<string, list<array{job_no: string, job_task_no: string}>>
 */
function bc_fetch_group_pairs_by_job_no(array $pairs): array
{
    $grouped = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        if ($jobNo === '') {
            continue;
        }

        $grouped[$jobNo][] = [
            'job_no' => $jobNo,
            'job_task_no' => trim((string) ($pair['job_task_no'] ?? '')),
        ];
    }

    return $grouped;
}

/**
 * Haalt Werkorders op via gerichte Job_No + Job_Task_No AND-filters.
 *
 * @param list<array{job_no: string, job_task_no: string}> $pairs
 */
function bc_fetch_workorders_by_pair_and_filters(
    string $company,
    array $pairs,
    string $select,
    array $wantedPairKeys,
    array &$seenRowKeys,
    array &$allRows,
    array $auth,
    int $ttl
): void {
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $filter = bc_fetch_build_odata_single_pair_filter($jobNo, $jobTaskNo);
        $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
            '$select' => $select,
            '$filter' => $filter,
        ]);
        $rows = odata_get_all($url, $auth, $ttl);
        bc_fetch_collect_matching_werkorder_rows($rows, $wantedPairKeys, $seenRowKeys, $allRows);
    }
}

/**
 * Past lichte status-updates toe op gecachte werkorderrijen.
 *
 * @param list<array> $rows
 * @return list<array>
 */
function bc_fetch_apply_status_snapshots_to_workorder_rows(array $rows, array $statusByPairKey): array
{
    if ($statusByPairKey === []) {
        return $rows;
    }

    $updated = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $pairKey = demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''));
        $snapshot = $statusByPairKey[$pairKey] ?? null;
        if (!is_array($snapshot)) {
            $updated[] = $row;
            continue;
        }

        $status = trim((string) ($snapshot['Status'] ?? ''));
        if ($status !== '') {
            $row['Status'] = $status;
        }

        $documentStatus = trim((string) ($snapshot['KVT_Document_Status'] ?? ''));
        if ($documentStatus !== '') {
            $row['KVT_Document_Status'] = $documentStatus;
        }

        $updated[] = $row;
    }

    return $updated;
}

/**
 * Lichtgewicht status-check voor open stale paren (#4), met batch Job_No (#3).
 *
 * @param list<array{job_no: string, job_task_no: string}> $pairs
 * @return array<string, array{Status: string, KVT_Document_Status: string}>
 */
function bc_fetch_fetch_workorder_status_by_pairs(string $company, array $pairs, array $auth, int $ttl): array
{
    if ($pairs === []) {
        return [];
    }

    $wantedPairKeys = [];
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
    }

    if ($wantedPairKeys === []) {
        return [];
    }

    $statusByPairKey = [];
    $select = bc_fetch_werkorders_status_select();
    $pairsByJob = bc_fetch_group_pairs_by_job_no($pairs);
    $pairFirstPairs = [];
    $batchPairs = [];

    foreach ($pairsByJob as $jobNo => $jobPairs) {
        $validPairs = [];
        foreach ($jobPairs as $pair) {
            $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
            if ($jobTaskNo === '') {
                continue;
            }

            $validPairs[] = $pair;
        }

        if ($validPairs === []) {
            continue;
        }

        if (count($validPairs) <= DEMETER_WORKORDER_PAIR_FIRST_MAX_PAIRS_PER_JOB) {
            foreach ($validPairs as $pair) {
                $pairFirstPairs[] = $pair;
            }
            continue;
        }

        foreach ($validPairs as $pair) {
            $batchPairs[] = $pair;
        }
    }

    foreach ($pairFirstPairs as $pair) {
        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $filter = bc_fetch_build_odata_single_pair_filter($jobNo, $jobTaskNo);
        $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
            '$select' => $select,
            '$filter' => $filter,
        ]);
        $rows = odata_get_all($url, $auth, $ttl);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $pairKey = demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''));
            if (!isset($wantedPairKeys[$pairKey])) {
                continue;
            }

            $statusByPairKey[$pairKey] = [
                'Status' => trim((string) ($row['Status'] ?? '')),
                'KVT_Document_Status' => trim((string) ($row['KVT_Document_Status'] ?? '')),
            ];
        }
    }

    foreach (bc_fetch_chunk_string_values(bc_fetch_unique_job_nos_from_pairs($batchPairs), DEMETER_WORKORDER_JOB_NO_BATCH_SIZE) as $jobNoChunk) {
        $filter = bc_fetch_build_odata_or_equals_filter('Job_No', $jobNoChunk);
        $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
            '$select' => $select,
            '$filter' => $filter,
        ]);
        $rows = odata_get_all($url, $auth, $ttl);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $pairKey = demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''));
            if (!isset($wantedPairKeys[$pairKey])) {
                continue;
            }

            $statusByPairKey[$pairKey] = [
                'Status' => trim((string) ($row['Status'] ?? '')),
                'KVT_Document_Status' => trim((string) ($row['KVT_Document_Status'] ?? '')),
            ];
        }
    }

    return $statusByPairKey;
}

/**
 * Verwerkt stale open paren: lichte status-check (#4) + leeftijd (#2).
 *
 * @param list<array{job_no: string, job_task_no: string}> $statusCheckPairs
 * @param array<string, array> $cachedWorkorders
 * @return array{
 *   fetch_pairs: list<array{job_no: string, job_task_no: string}>,
 *   use_cached_rows: list<array>,
 *   status_updated_rows: list<array>
 * }
 */
function bc_fetch_process_stale_workorders_via_status_check(
    string $company,
    array $statusCheckPairs,
    array $cachedWorkorders,
    array $auth,
    int $ttl
): array {
    if ($statusCheckPairs === []) {
        return [
            'fetch_pairs' => [],
            'use_cached_rows' => [],
            'status_updated_rows' => [],
        ];
    }

    $statusByPairKey = bc_fetch_fetch_workorder_status_by_pairs($company, $statusCheckPairs, $auth, $ttl);
    $fetchPairs = [];
    $useCachedRows = [];
    $statusUpdatedRows = [];

    foreach ($statusCheckPairs as $pair) {
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
        if (!is_array($cachedEntry) || !is_array($cachedEntry['row'] ?? null)) {
            $fetchPairs[] = $pair;
            continue;
        }

        $statusSnapshot = $statusByPairKey[$pairKey] ?? null;
        if (!is_array($statusSnapshot)) {
            if (demeter_workorder_cache_entry_needs_full_refresh_by_age($cachedEntry)) {
                $fetchPairs[] = $pair;
            } else {
                $useCachedRows[] = $cachedEntry['row'];
            }
            continue;
        }

        $status = trim((string) ($statusSnapshot['Status'] ?? ''));
        $documentStatus = trim((string) ($statusSnapshot['KVT_Document_Status'] ?? ''));
        $isClosed = demeter_workorder_status_is_closed($status);

        if ($isClosed) {
            $updatedRow = $cachedEntry['row'];
            $updatedRow['Status'] = $status;
            if ($documentStatus !== '') {
                $updatedRow['KVT_Document_Status'] = $documentStatus;
            }
            $statusUpdatedRows[] = $updatedRow;
            continue;
        }

        if (demeter_workorder_cache_entry_needs_full_refresh_by_age($cachedEntry)) {
            $fetchPairs[] = $pair;
            continue;
        }

        $useCachedRows[] = $cachedEntry['row'];
    }

    return [
        'fetch_pairs' => $fetchPairs,
        'use_cached_rows' => $useCachedRows,
        'status_updated_rows' => $statusUpdatedRows,
    ];
}

/**
 * Haalt Werkorders op voor job/task-paren.
 */
function bc_fetch_workorders_by_job_task_pairs(string $company, array $pairs, array $auth, int $ttl): array
{
    if ($pairs === []) {
        return [];
    }

    $werkorderSelect = bc_fetch_werkorders_list_select();

    $wantedPairKeys = [];
    $normalizedPairs = [];
    foreach ($pairs as $pair) {
        if (!is_array($pair)) {
            continue;
        }

        $jobNo = trim((string) ($pair['job_no'] ?? ''));
        $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $normalizedPairs[] = [
            'job_no' => $jobNo,
            'job_task_no' => $jobTaskNo,
        ];
        $wantedPairKeys[demeter_workorder_pair_key($jobNo, $jobTaskNo)] = true;
    }

    if ($wantedPairKeys === []) {
        return [];
    }

    $allRows = [];
    $seenRowKeys = [];
    $pairsByJob = bc_fetch_group_pairs_by_job_no($normalizedPairs);
    $pairFirstPairs = [];
    $batchPairs = [];

    foreach ($pairsByJob as $jobNo => $jobPairs) {
        $validPairs = [];
        foreach ($jobPairs as $pair) {
            $jobTaskNo = trim((string) ($pair['job_task_no'] ?? ''));
            if ($jobTaskNo === '') {
                continue;
            }

            $validPairs[] = $pair;
        }

        if ($validPairs === []) {
            continue;
        }

        if (count($validPairs) <= DEMETER_WORKORDER_PAIR_FIRST_MAX_PAIRS_PER_JOB) {
            foreach ($validPairs as $pair) {
                $pairFirstPairs[] = $pair;
            }
            continue;
        }

        foreach ($validPairs as $pair) {
            $batchPairs[] = $pair;
        }
    }

    bc_fetch_workorders_by_pair_and_filters(
        $company,
        $pairFirstPairs,
        $werkorderSelect,
        $wantedPairKeys,
        $seenRowKeys,
        $allRows,
        $auth,
        $ttl
    );

    foreach (bc_fetch_chunk_string_values(bc_fetch_unique_job_nos_from_pairs($batchPairs), DEMETER_WORKORDER_JOB_NO_BATCH_SIZE) as $jobNoChunk) {
        $filter = bc_fetch_build_odata_or_equals_filter('Job_No', $jobNoChunk);
        $werkordersUrl = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
            '$select' => $werkorderSelect,
            '$filter' => $filter,
        ]);
        $werkorderRows = odata_get_all($werkordersUrl, $auth, $ttl);
        bc_fetch_collect_matching_werkorder_rows($werkorderRows, $wantedPairKeys, $seenRowKeys, $allRows);
    }

    $foundPairKeys = [];
    foreach ($allRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $foundPairKeys[demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''))] = true;
    }

    $missingPairs = [];
    foreach ($normalizedPairs as $pair) {
        $pairKey = demeter_workorder_pair_key($pair['job_no'], $pair['job_task_no']);
        if (!isset($foundPairKeys[$pairKey])) {
            $missingPairs[] = $pair;
        }
    }

    foreach ($missingPairs as $pair) {
        $filter = bc_fetch_build_odata_single_pair_filter($pair['job_no'], $pair['job_task_no']);
        $pairWerkordersUrl = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
            '$select' => $werkorderSelect,
            '$filter' => $filter,
        ]);
        $pairRows = odata_get_all($pairWerkordersUrl, $auth, $ttl);
        bc_fetch_collect_matching_werkorder_rows($pairRows, $wantedPairKeys, $seenRowKeys, $allRows);

        foreach ($pairRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $foundPairKeys[demeter_workorder_pair_key((string) ($row['Job_No'] ?? ''), (string) ($row['Job_Task_No'] ?? ''))] = true;
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
