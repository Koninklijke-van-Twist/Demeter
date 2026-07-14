<?php

/**
 * Bouwt werkorderrijen voor de UI vanuit BC-overviewdata.
 */

require_once __DIR__ . '/finance_calculations.php';
require_once __DIR__ . '/bc_fetch/workorder_state_cache.php';
require_once __DIR__ . '/bc_fetch/odata_select.php';
require_once __DIR__ . '/bc_fetch/projectposten_workorders.php';

/**
 * Label → BC-veldnaam voor werkorder-memo's.
 *
 * @return array<string, string>
 */
function demeter_workorder_memo_label_map(): array
{
    return [
        'KVT_Memo' => 'Memo',
        'KVT_Memo_Internal_Use_Only' => 'Memo_Internal_Use_Only',
        'KVT_Memo_Invoice' => 'Memo_Invoice',
        'KVT_Memo_Billing_Details' => 'KVT_Memo_Invoice_Details',
        'KVT_Remarks_Invoicing' => 'KVT_Remarks_Invoicing',
    ];
}

/**
 * Bepaalt of een BC-werkorderrij memo-velden bevat (al opgehaald).
 */
function demeter_workorder_row_includes_memo_fields(array $workorder): bool
{
    foreach (bc_fetch_werkorder_memo_bc_fields() as $field) {
        if (array_key_exists($field, $workorder)) {
            return true;
        }
    }

    return false;
}

/**
 * Stabiele sleutel voor BC-werkorder + memo-koppeling.
 */
function demeter_workorder_bc_composite_key(array $workorder): string
{
    return implode('|', [
        trim((string) ($workorder['No'] ?? '')),
        trim((string) ($workorder['Job_No'] ?? '')),
        trim((string) ($workorder['Job_Task_No'] ?? '')),
        trim((string) ($workorder['Start_Date'] ?? '')),
    ]);
}

/**
 * Bouwt Notes-structuur vanuit BC-werkorder-memovelden.
 *
 * @return array{notes: list<array{label: string, value: string}>, notes_search: string, memos_loaded: bool}
 */
function demeter_build_workorder_notes_from_bc_row(array $workorder): array
{
    if (!demeter_workorder_row_includes_memo_fields($workorder)) {
        return [
            'notes' => [],
            'notes_search' => '',
            'memos_loaded' => false,
        ];
    }

    $notesParts = [];
    $notesSearchParts = [];

    foreach (demeter_workorder_memo_label_map() as $label => $bcField) {
        $value = trim((string) ($workorder[$bcField] ?? ''));
        $notesParts[] = [
            'label' => $label,
            'value' => $value,
        ];
        if ($value !== '') {
            $notesSearchParts[] = $value;
        }
    }

    return [
        'notes' => $notesParts,
        'notes_search' => implode("\n", $notesSearchParts),
        'memos_loaded' => true,
    ];
}

/**
 * Voegt numerieke overview-totalen samen.
 */
function demeter_merge_numeric_totals_map(array $base, array $addition): array
{
    foreach ($addition as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        if (!is_array($value)) {
            continue;
        }

        if (!isset($base[$key]) || !is_array($base[$key])) {
            $base[$key] = [
                'costs' => 0.0,
                'revenue' => 0.0,
            ];
        }

        $base[$key]['costs'] = finance_add_amount((float) ($base[$key]['costs'] ?? 0.0), (float) ($value['costs'] ?? 0.0));
        $base[$key]['revenue'] = finance_add_amount((float) ($base[$key]['revenue'] ?? 0.0), (float) ($value['revenue'] ?? 0.0));
    }

    return $base;
}

/**
 * Voegt projectposten-rijen per key samen.
 */
function demeter_merge_projectposten_rows_map(array $base, array $addition): array
{
    foreach ($addition as $key => $rows) {
        if (!is_string($key) || $key === '' || !is_array($rows)) {
            continue;
        }

        if (!isset($base[$key]) || !is_array($base[$key])) {
            $base[$key] = [];
        }

        $base[$key] = array_merge($base[$key], $rows);
    }

    return $base;
}

/**
 * Voegt overviewdata van een maand-chunk samen in een lopend totaal.
 */
function demeter_merge_overview_chunks(array $aggregate, array $chunk): array
{
    $aggregate['workorders'] = bc_fetch_merge_workorder_rows(
        is_array($aggregate['workorders'] ?? null) ? $aggregate['workorders'] : [],
        is_array($chunk['workorders'] ?? null) ? $chunk['workorders'] : []
    );

    $aggregate['project_totals_by_job'] = demeter_merge_numeric_totals_map(
        is_array($aggregate['project_totals_by_job'] ?? null) ? $aggregate['project_totals_by_job'] : [],
        is_array($chunk['project_totals_by_job'] ?? null) ? $chunk['project_totals_by_job'] : []
    );
    $aggregate['workorder_totals_by_number'] = demeter_merge_numeric_totals_map(
        is_array($aggregate['workorder_totals_by_number'] ?? null) ? $aggregate['workorder_totals_by_number'] : [],
        is_array($chunk['workorder_totals_by_number'] ?? null) ? $chunk['workorder_totals_by_number'] : []
    );
    $aggregate['workorder_totals_by_project_and_number'] = demeter_merge_numeric_totals_map(
        is_array($aggregate['workorder_totals_by_project_and_number'] ?? null) ? $aggregate['workorder_totals_by_project_and_number'] : [],
        is_array($chunk['workorder_totals_by_project_and_number'] ?? null) ? $chunk['workorder_totals_by_project_and_number'] : []
    );

    $aggregate['projectposten_rows_by_project'] = demeter_merge_projectposten_rows_map(
        is_array($aggregate['projectposten_rows_by_project'] ?? null) ? $aggregate['projectposten_rows_by_project'] : [],
        is_array($chunk['projectposten_rows_by_project'] ?? null) ? $chunk['projectposten_rows_by_project'] : []
    );
    $aggregate['projectposten_rows_by_project_and_workorder'] = demeter_merge_projectposten_rows_map(
        is_array($aggregate['projectposten_rows_by_project_and_workorder'] ?? null) ? $aggregate['projectposten_rows_by_project_and_workorder'] : [],
        is_array($chunk['projectposten_rows_by_project_and_workorder'] ?? null) ? $chunk['projectposten_rows_by_project_and_workorder'] : []
    );

    $financeKeyByPair = is_array($aggregate['finance_key_by_pair'] ?? null) ? $aggregate['finance_key_by_pair'] : [];
    $chunkFinanceKeyByPair = is_array($chunk['finance_key_by_pair'] ?? null) ? $chunk['finance_key_by_pair'] : [];
    foreach ($chunkFinanceKeyByPair as $pairKey => $financeKey) {
        if (!is_string($pairKey) || $pairKey === '' || !is_string($financeKey) || $financeKey === '') {
            continue;
        }

        $financeKeyByPair[$pairKey] = $financeKey;
    }
    $aggregate['finance_key_by_pair'] = $financeKeyByPair;

    $invoiceDetailsById = is_array($aggregate['invoice_details_by_id'] ?? null) ? $aggregate['invoice_details_by_id'] : [];
    $chunkInvoiceDetails = is_array($chunk['invoice_details_by_id'] ?? null) ? $chunk['invoice_details_by_id'] : [];
    foreach ($chunkInvoiceDetails as $invoiceId => $details) {
        if (!is_string($invoiceId) || $invoiceId === '' || !is_array($details) || isset($invoiceDetailsById[$invoiceId])) {
            continue;
        }

        $invoiceDetailsById[$invoiceId] = $details;
    }
    $aggregate['invoice_details_by_id'] = $invoiceDetailsById;

    $projectInvoiceIdsByJob = is_array($aggregate['project_invoice_ids_by_job'] ?? null) ? $aggregate['project_invoice_ids_by_job'] : [];
    $chunkProjectInvoiceIds = is_array($chunk['project_invoice_ids_by_job'] ?? null) ? $chunk['project_invoice_ids_by_job'] : [];
    foreach ($chunkProjectInvoiceIds as $projectKey => $invoiceIds) {
        if (!is_string($projectKey) || $projectKey === '' || !is_array($invoiceIds)) {
            continue;
        }

        $existing = is_array($projectInvoiceIdsByJob[$projectKey] ?? null) ? $projectInvoiceIdsByJob[$projectKey] : [];
        $projectInvoiceIdsByJob[$projectKey] = array_values(array_unique(array_merge($existing, $invoiceIds)));
    }
    $aggregate['project_invoice_ids_by_job'] = $projectInvoiceIdsByJob;

    $projectInvoicedTotalByJob = is_array($aggregate['project_invoiced_total_by_job'] ?? null) ? $aggregate['project_invoiced_total_by_job'] : [];
    $chunkInvoicedTotals = is_array($chunk['project_invoiced_total_by_job'] ?? null) ? $chunk['project_invoiced_total_by_job'] : [];
    foreach ($chunkInvoicedTotals as $projectKey => $amount) {
        if (!is_string($projectKey) || $projectKey === '') {
            continue;
        }

        $projectInvoicedTotalByJob[$projectKey] = finance_add_amount(
            (float) ($projectInvoicedTotalByJob[$projectKey] ?? 0.0),
            (float) $amount
        );
    }
    $aggregate['project_invoiced_total_by_job'] = $projectInvoicedTotalByJob;

    return $aggregate;
}

/**
 * Bouwt UI-rijen vanuit overviewdata.
 *
 * @return array{rows: list<array>, row_keys: list<string>}
 */
function demeter_build_workorder_rows_from_overview(array $overviewData, string $invoiceFilter): array
{
    $workorders = is_array($overviewData['workorders'] ?? null) ? $overviewData['workorders'] : [];
    $projectTotalsByJob = is_array($overviewData['project_totals_by_job'] ?? null) ? $overviewData['project_totals_by_job'] : [];
    $projectInvoiceIdsByJob = is_array($overviewData['project_invoice_ids_by_job'] ?? null) ? $overviewData['project_invoice_ids_by_job'] : [];
    $projectInvoicedTotalByJob = is_array($overviewData['project_invoiced_total_by_job'] ?? null) ? $overviewData['project_invoiced_total_by_job'] : [];
    $workorderTotalsByProjectAndNumber = is_array($overviewData['workorder_totals_by_project_and_number'] ?? null)
        ? $overviewData['workorder_totals_by_project_and_number']
        : [];
    $financeKeyByPair = is_array($overviewData['finance_key_by_pair'] ?? null) ? $overviewData['finance_key_by_pair'] : [];

    $rows = [];
    $rowKeys = [];

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $builtRow = demeter_build_single_workorder_row(
            $workorder,
            $invoiceFilter,
            $projectTotalsByJob,
            $projectInvoiceIdsByJob,
            $projectInvoicedTotalByJob,
            $workorderTotalsByProjectAndNumber,
            $financeKeyByPair
        );

        if ($builtRow === null) {
            continue;
        }

        $rows[] = $builtRow;
        $rowKeys[] = (string) ($builtRow['Row_Key'] ?? '');
    }

    usort($rows, static function (array $a, array $b): int {
        $projectCompare = strnatcasecmp((string) ($a['Job_No'] ?? ''), (string) ($b['Job_No'] ?? ''));
        if ($projectCompare !== 0) {
            return $projectCompare;
        }

        return strnatcasecmp((string) ($a['No'] ?? ''), (string) ($b['No'] ?? ''));
    });

    return [
        'rows' => $rows,
        'row_keys' => array_values(array_unique(array_filter($rowKeys, static function (string $key): bool {
            return trim($key) !== '';
        }))),
    ];
}

/**
 * Bouwt één UI-rij vanuit een BC-werkorder en finance-context.
 */
function demeter_build_single_workorder_row(
    array $workorder,
    string $invoiceFilter,
    array $projectTotalsByJob,
    array $projectInvoiceIdsByJob,
    array $projectInvoicedTotalByJob,
    array $workorderTotalsByProjectAndNumber,
    array $financeKeyByPair
): ?array {
    $jobNo = trim((string) ($workorder['Job_No'] ?? ''));
    $jobTaskNo = trim((string) ($workorder['Job_Task_No'] ?? ''));
    $normalizedJobNo = strtolower(trim($jobNo));

    $invoiceIdsForProject = [];
    if ($normalizedJobNo !== '' && isset($projectInvoiceIdsByJob[$normalizedJobNo]) && is_array($projectInvoiceIdsByJob[$normalizedJobNo])) {
        $invoiceIdsForProject = $projectInvoiceIdsByJob[$normalizedJobNo];
    }

    $isInvoiced = $invoiceIdsForProject !== [];
    if ($invoiceFilter === 'invoiced' && !$isInvoiced) {
        return null;
    }

    if ($invoiceFilter === 'uninvoiced' && $isInvoiced) {
        return null;
    }

    $normalizedWorkorderNo = strtolower(trim((string) ($workorder['No'] ?? '')));
    $pairKey = $jobTaskNo !== '' ? demeter_workorder_pair_key($jobNo, $jobTaskNo) : '';
    $financeSourceKey = $pairKey !== '' && isset($financeKeyByPair[$pairKey])
        ? (string) $financeKeyByPair[$pairKey]
        : ($jobTaskNo !== '' ? strtolower($jobTaskNo) : $normalizedWorkorderNo);
    $normalizedWorkorderSourceKey = $financeSourceKey;
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
    $notesBundle = demeter_build_workorder_notes_from_bc_row($workorder);
    $notesParts = $notesBundle['notes'];
    $memosLoaded = $notesBundle['memos_loaded'];
    $notesSearch = $notesBundle['notes_search'];

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
        : $normalizedWorkorderSourceKey;

    $rowKey = demeter_workorder_row_key($jobNo, $normalizedPopupWorkorderSourceKey);

    return [
        'Row_Key' => $rowKey,
        'Bc_No' => (string) ($workorder['No'] ?? ''),
        'No' => $displayWorkorderNo,
        'Order_Type' => $displayOrderType,
        'Contract_No' => (string) ($workorder['Contract_No'] ?? ''),
        'Customer_Id' => (string) ($workorder['Bill_to_Customer_No'] ?? ''),
        'Start_Date' => (string) ($workorder['Start_Date'] ?? ''),
        'Component_No' => $equipmentNumber,
        'Component_Description' => (string) ($workorder['Component_Description'] ?? $workorder['Sub_Entity_Description'] ?? ''),
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
        'Notes_Search' => $notesSearch,
        'Memos_Loaded' => $memosLoaded,
        'Invoice_Id' => $invoiceIdText,
        'Invoice_Ids' => $invoiceIdsForProject,
        'Job_No' => $jobNo,
        'Job_Task_No' => $jobTaskNo,
        'Workorder_Source_Key' => $normalizedPopupWorkorderSourceKey,
        'End_Date' => (string) ($workorder['End_Date'] ?? ''),
    ];
}

/**
 * Werkt bestaande rijen bij met nieuwe maanddata (finance wordt opgeteld).
 *
 * @param array<string, array> $rowsByKey
 */
function demeter_merge_month_rows_into_existing(array $rowsByKey, array $monthRows, array $projectTotalsByJob): array
{
    foreach ($monthRows as $monthRow) {
        if (!is_array($monthRow)) {
            continue;
        }

        $rowKey = trim((string) ($monthRow['Row_Key'] ?? ''));
        if ($rowKey === '') {
            continue;
        }

        if (!isset($rowsByKey[$rowKey]) || !is_array($rowsByKey[$rowKey])) {
            $rowsByKey[$rowKey] = $monthRow;
            continue;
        }

        $existing = $rowsByKey[$rowKey];
        $existing['Actual_Costs'] = finance_add_amount((float) ($existing['Actual_Costs'] ?? 0.0), (float) ($monthRow['Actual_Costs'] ?? 0.0));
        $existing['Total_Revenue'] = finance_add_amount((float) ($existing['Total_Revenue'] ?? 0.0), (float) ($monthRow['Total_Revenue'] ?? 0.0));
        $existing['Actual_Total'] = finance_calculate_result((float) $existing['Total_Revenue'], (float) $existing['Actual_Costs']);

        foreach (['No', 'Order_Type', 'Contract_No', 'Customer_Id', 'Start_Date', 'Component_No', 'Component_Description', 'Equipment_Number', 'Equipment_Name', 'Description', 'Customer_Name', 'Cost_Center', 'Status', 'Document_Status', 'End_Date', 'Job_No', 'Job_Task_No', 'Workorder_Source_Key'] as $field) {
            if (array_key_exists($field, $monthRow) && trim((string) $monthRow[$field]) !== '') {
                $existing[$field] = $monthRow[$field];
            }
        }

        if (!empty($monthRow['Memos_Loaded'])) {
            $existing['Notes'] = is_array($monthRow['Notes'] ?? null) ? $monthRow['Notes'] : [];
            $existing['Notes_Search'] = (string) ($monthRow['Notes_Search'] ?? '');
            $existing['Memos_Loaded'] = true;
        }

        $normalizedJobNo = strtolower(trim((string) ($existing['Job_No'] ?? '')));
        if ($normalizedJobNo !== '' && isset($projectTotalsByJob[$normalizedJobNo]) && is_array($projectTotalsByJob[$normalizedJobNo])) {
            $existing['Project_Actual_Costs'] = (float) ($projectTotalsByJob[$normalizedJobNo]['costs'] ?? 0.0);
            $existing['Project_Total_Revenue'] = (float) ($projectTotalsByJob[$normalizedJobNo]['revenue'] ?? 0.0);
        }

        $rowsByKey[$rowKey] = $existing;
    }

    return $rowsByKey;
}

/**
 * Filtert opgeslagen UI-rijen op factuurfilter.
 *
 * @param array<string, array> $rowsByKey
 * @return list<array>
 */
function demeter_filter_display_rows_by_invoice(array $rowsByKey, string $invoiceFilter): array
{
    $rows = [];

    foreach ($rowsByKey as $row) {
        if (!is_array($row)) {
            continue;
        }

        $invoiceIds = is_array($row['Invoice_Ids'] ?? null) ? $row['Invoice_Ids'] : [];
        $isInvoiced = $invoiceIds !== [];

        if ($invoiceFilter === 'invoiced' && !$isInvoiced) {
            continue;
        }

        if ($invoiceFilter === 'uninvoiced' && $isInvoiced) {
            continue;
        }

        $rows[] = $row;
    }

    usort($rows, static function (array $left, array $right): int {
        $projectCompare = strnatcasecmp((string) ($left['Job_No'] ?? ''), (string) ($right['Job_No'] ?? ''));
        if ($projectCompare !== 0) {
            return $projectCompare;
        }

        return strnatcasecmp((string) ($left['No'] ?? ''), (string) ($right['No'] ?? ''));
    });

    return $rows;
}

/**
 * Werkt display_rows bij na het laden van een maand-chunk.
 *
 * @param array<string, array> $displayRowsByKey
 * @param list<array> $monthBuiltRows
 * @return array<string, array>
 */
function demeter_merge_display_rows_for_month_chunk(
    array $displayRowsByKey,
    array $monthBuiltRows,
    bool $replaceCurrentMonth,
    array $projectTotalsByJob
): array {
    if ($replaceCurrentMonth) {
        foreach ($monthBuiltRows as $monthRow) {
            if (!is_array($monthRow)) {
                continue;
            }

            $rowKey = trim((string) ($monthRow['Row_Key'] ?? ''));
            if ($rowKey === '') {
                continue;
            }

            $displayRowsByKey[$rowKey] = $monthRow;
        }

        return $displayRowsByKey;
    }

    return demeter_merge_month_rows_into_existing($displayRowsByKey, $monthBuiltRows, $projectTotalsByJob);
}

/**
 * Bouwt UI-rijen voor de eerste paint vanuit display_rows cache.
 *
 * @param array<string, array> $displayRowsByKey
 * @return list<array>
 */
function demeter_build_paint_rows_from_workorder_cache(
    array $cachedState,
    string $invoiceFilter,
    array $displayRowsByKey = []
): array {
    if ($displayRowsByKey !== []) {
        return demeter_filter_display_rows_by_invoice($displayRowsByKey, $invoiceFilter);
    }

    if (is_array($cachedState['display_rows'] ?? null) && $cachedState['display_rows'] !== []) {
        return demeter_filter_display_rows_by_invoice($cachedState['display_rows'], $invoiceFilter);
    }

    $overviewData = demeter_build_overview_from_workorder_cache($cachedState);

    return demeter_build_workorder_rows_from_overview($overviewData, $invoiceFilter)['rows'];
}

/**
 * Haalt memo-velden op voor specifieke werkorderrijen (on-demand, OData-cache).
 *
 * @param list<array{row_key?: string, no?: string, job_no?: string, job_task_no?: string, start_date?: string}> $rowRefs
 * @return array<string, array{notes: list<array{label: string, value: string}>, notes_search: string, memos_loaded: bool}>
 */
function demeter_fetch_workorder_memos_for_row_refs(string $company, array $rowRefs, array $auth, int $ttl): array
{
    if ($rowRefs === []) {
        return [];
    }

    $wantedCompositeKeys = [];
    $rowKeyByCompositeKey = [];
    $jobNos = [];

    foreach ($rowRefs as $ref) {
        if (!is_array($ref)) {
            continue;
        }

        $rowKey = trim((string) ($ref['row_key'] ?? ''));
        $compositeKey = demeter_workorder_bc_composite_key([
            'No' => (string) ($ref['no'] ?? ''),
            'Job_No' => (string) ($ref['job_no'] ?? ''),
            'Job_Task_No' => (string) ($ref['job_task_no'] ?? ''),
            'Start_Date' => (string) ($ref['start_date'] ?? ''),
        ]);

        if ($compositeKey === '|||' || $rowKey === '') {
            continue;
        }

        $wantedCompositeKeys[$compositeKey] = true;
        $rowKeyByCompositeKey[$compositeKey] = $rowKey;

        $jobNo = trim((string) ($ref['job_no'] ?? ''));
        if ($jobNo !== '') {
            $jobNos[$jobNo] = true;
        }
    }

    if ($wantedCompositeKeys === []) {
        return [];
    }

    $memosByRowKey = [];
    $select = bc_fetch_werkorders_memo_select();

    foreach (bc_fetch_chunk_string_values(array_keys($jobNos), DEMETER_WORKORDER_JOB_NO_BATCH_SIZE) as $jobNoChunk) {
        $filter = bc_fetch_build_odata_or_equals_filter('Job_No', $jobNoChunk);
        $url = company_entity_url_with_query($GLOBALS['baseUrl'], $GLOBALS['environment'], $company, 'Werkorders', [
            '$select' => $select,
            '$filter' => $filter,
        ]);
        $bcRows = odata_get_all($url, $auth, $ttl);

        foreach ($bcRows as $bcRow) {
            if (!is_array($bcRow)) {
                continue;
            }

            $compositeKey = demeter_workorder_bc_composite_key($bcRow);
            if (!isset($wantedCompositeKeys[$compositeKey])) {
                continue;
            }

            $uiRowKey = $rowKeyByCompositeKey[$compositeKey] ?? '';
            if ($uiRowKey === '') {
                continue;
            }

            $notesBundle = demeter_build_workorder_notes_from_bc_row($bcRow);
            $notesBundle['memos_loaded'] = true;
            $memosByRowKey[$uiRowKey] = $notesBundle;
        }
    }

    foreach ($rowKeyByCompositeKey as $compositeKey => $rowKey) {
        if (isset($memosByRowKey[$rowKey])) {
            continue;
        }

        $memosByRowKey[$rowKey] = [
            'notes' => [],
            'notes_search' => '',
            'memos_loaded' => true,
        ];
    }

    return $memosByRowKey;
}

/**
 * Schrijft opgehaalde memo's terug naar de display-cache.
 *
 * @param array<string, array{notes?: list<array>, notes_search?: string, memos_loaded?: bool}> $memosByRowKey
 */
function demeter_persist_workorder_memos_to_display_cache(string $company, string $costCenter, array $memosByRowKey): bool
{
    if ($costCenter === '' || $memosByRowKey === []) {
        return false;
    }

    $displayRowsByKey = demeter_workorder_state_cache_load_display_rows($company, $costCenter);
    if ($displayRowsByKey === []) {
        return false;
    }

    $changed = false;

    foreach ($memosByRowKey as $rowKey => $bundle) {
        if (!is_string($rowKey) || $rowKey === '' || !is_array($bundle)) {
            continue;
        }

        if (!isset($displayRowsByKey[$rowKey]) || !is_array($displayRowsByKey[$rowKey])) {
            continue;
        }

        $displayRowsByKey[$rowKey]['Notes'] = is_array($bundle['notes'] ?? null) ? $bundle['notes'] : [];
        $displayRowsByKey[$rowKey]['Notes_Search'] = (string) ($bundle['notes_search'] ?? '');
        $displayRowsByKey[$rowKey]['Memos_Loaded'] = true;
        $changed = true;
    }

    if (!$changed) {
        return false;
    }

    return demeter_workorder_state_cache_save_display_rows($company, $costCenter, $displayRowsByKey);
}
