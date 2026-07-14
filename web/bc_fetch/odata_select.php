<?php

/**
 * Gedeelde OData $select-velden — alleen wat Demeter daadwerkelijk gebruikt.
 */

/**
 * Werkorders voor lijstweergave, cache en finance-koppeling.
 */
function bc_fetch_werkorders_list_select(): string
{
    return implode(',', [
        'No',
        'Task_Code',
        'Task_Description',
        'Status',
        'KVT_Document_Status',
        'Job_No',
        'Job_Task_No',
        'Contract_No',
        'Start_Date',
        'End_Date',
        'Sub_Entity_Description',
        'Component_No',
        'Bill_to_Customer_No',
        'Bill_to_Name',
        'Job_Dimension_1_Value',
    ]);
}

/**
 * Memo-velden voor on-demand laden (notities-modal / memokolommen).
 */
function bc_fetch_werkorders_memo_select(): string
{
    return implode(',', [
        'No',
        'Job_No',
        'Job_Task_No',
        'Start_Date',
        'Memo',
        'Memo_Internal_Use_Only',
        'Memo_Invoice',
        'KVT_Memo_Invoice_Details',
        'KVT_Remarks_Invoicing',
    ]);
}

/**
 * BC-veldnamen voor werkorder-memo's.
 *
 * @return list<string>
 */
function bc_fetch_werkorder_memo_bc_fields(): array
{
    return [
        'Memo',
        'Memo_Internal_Use_Only',
        'Memo_Invoice',
        'KVT_Memo_Invoice_Details',
        'KVT_Remarks_Invoicing',
    ];
}

/**
 * Lichtgewicht status-check (#4).
 */
function bc_fetch_werkorders_status_select(): string
{
    return 'Job_No,Job_Task_No,Status,KVT_Document_Status';
}

/**
 * AppWerkorders voor Component_Description-koppeling.
 */
function bc_fetch_app_werkorders_select(): string
{
    return 'No,Job_No,Job_Task_No,Start_Date,Component_Description';
}

/**
 * ProjectPosten voor week-range finance + kostenplaats-filter + UI-modal.
 *
 * @return list<string>
 */
function bc_fetch_projectposten_finance_select_fields(): array
{
    return [
        'Job_No',
        'Job_Task_No',
        'LVS_Work_Order_No',
        'Posting_Date',
        'Entry_Type',
        'Type',
        'No',
        'Description',
        'Total_Cost',
        'Line_Amount',
        'LVS_Global_Dimension_1_Code',
        'Global_Dimension_1_Code',
    ];
}

/**
 * @param list<string> $fields
 */
function bc_fetch_odata_select_csv(array $fields): string
{
    $unique = array_values(array_unique(array_filter(array_map('strval', $fields), static function (string $field): bool {
        return trim($field) !== '';
    })));

    return implode(',', $unique);
}
