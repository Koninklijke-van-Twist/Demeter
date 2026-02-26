(function ()
{
    const app = document.getElementById('app');
    const pageLoader = document.getElementById('pageLoader');
    const pageLoaderText = document.getElementById('pageLoaderText');
    const controlsForm = document.querySelector('form.controls');
    const companySelect = document.getElementById('companySelect');
    const fromMonthInput = document.getElementById('fromMonth');
    const toMonthInput = document.getElementById('toMonth');
    const invoiceFilterSelect = document.getElementById('invoiceFilter');
    const payload = window.workorderOverviewData || {};
    const rows = Array.isArray(payload.rows) ? payload.rows.slice() : [];
    const invoiceDetailsById = payload && typeof payload.invoice_details_by_id === 'object' && payload.invoice_details_by_id !== null
        ? payload.invoice_details_by_id
        : {};
    const error = typeof payload.error === 'string' ? payload.error : null;
    const invoiceFilter = typeof payload.invoice_filter === 'string' ? payload.invoice_filter : 'both';
    const showInvoiced = invoiceFilter === 'both' || invoiceFilter === 'invoiced';
    const baseColumns = [
        { key: 'No', label: 'Werkorder' },
        { key: 'Order_Type', label: 'Ordertype' },
        { key: 'Job_No', label: 'Project Nr.' },
        { key: 'Customer_Id', label: 'Klant Nr.' },
        { key: 'Customer_Name', label: 'Klantnaam' },
        { key: 'Start_Date', label: 'Startdatum' },
        { key: 'Equipment_Number', label: 'Equipment Nr.' },
        { key: 'Description', label: 'Omschrijving' },
        { key: 'Actual_Costs', label: 'Werkelijke kosten' },
        { key: 'Total_Revenue', label: 'Totaalopbrengst' },
        { key: 'Actual_Total', label: 'Werkelijk totaal' },
        { key: 'Cost_Center', label: 'Kostenplaats' },
        { key: 'Status', label: 'Status' },
        { key: 'Document_Status', label: 'Documentstatus' }
    ];
    const memoFields = [
        { key: 'Memo_KVT_Memo', label: 'Memo', noteLabel: 'KVT_Memo' },
        { key: 'Memo_KVT_Memo_Internal_Use_Only', label: 'Memo Intern Gebruik', noteLabel: 'KVT_Memo_Internal_Use_Only' },
        { key: 'Memo_KVT_Memo_Invoice', label: 'Memo Factuur', noteLabel: 'KVT_Memo_Invoice' },
        { key: 'Memo_KVT_Memo_Billing_Details', label: 'Memo Bijzonderheden Facturatie', noteLabel: 'KVT_Memo_Billing_Details' },
        { key: 'Memo_KVT_Remarks_Invoicing', label: 'Bijzonderheden Facturatie', noteLabel: 'KVT_Remarks_Invoicing' }
    ];
    const memoFieldByKey = {};
    for (const field of memoFields)
    {
        memoFieldByKey[field.key] = field;
    }

    const loadedMemoSettings = payload && typeof payload.memo_column_settings === 'object' && payload.memo_column_settings !== null
        ? payload.memo_column_settings
        : {};
    const saveUserSettingsUrl = typeof payload.save_user_settings_url === 'string' ? payload.save_user_settings_url : 'index.php?action=save_user_settings';
    const selectedMemoColumnKeys = new Set();
    for (const field of memoFields)
    {
        if (loadedMemoSettings[field.key] !== false)
        {
            selectedMemoColumnKeys.add(field.key);
        }
    }

    let columns = buildTableColumns();
    const exportColumns = buildExportColumns();
    const sortState = {
        key: 'No',
        direction: 'asc'
    };
    const amountColumnKeys = new Set(['Actual_Costs', 'Total_Revenue', 'Actual_Total']);
    const numericSortKeys = new Set(['Actual_Costs', 'Total_Revenue', 'Actual_Total']);
    const compactColumnKeys = new Set(['Actual_Costs', 'Total_Revenue', 'Actual_Total', 'Cost_Center', 'Status', 'Document_Status']);
    const workorderAmountTooltip = 'Geen factuur gevonden voor deze werkorder - kosten en opbrengst worden gehaald uit de werkordergegevens.';
    const invoiceAmountTooltip = 'Factuur gevonden, kosten en opbrengst uit de factuur gelezen.';
    const workorderAmountModalMessage = 'Deze bedragen zijn overgenomen uit de werkorder. Controleer ze extra zorgvuldig; zonder gekoppelde factuur kunnen ze afwijken van de uiteindelijke factuur.';
    const invoiceAmountModalMessage = 'Deze bedragen komen direct uit de gekoppelde factuur en gelden als de meest betrouwbare bron.';
    const costCenterOptions = buildCostCenterOptions();
    const currencyFormatter = new Intl.NumberFormat('nl-NL', {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    const hiddenStatuses = new Set();
    const manuallyHiddenStatuses = new Set();
    let statusHintTimeoutId = null;
    let appliedSearchText = '';
    let selectedCostCenter = 'all';
    const statusOrder = ['open', 'signed', 'completed', 'checked', 'in-progress', 'planned', 'closed', 'cancelled'];
    const statusInfoMap = buildStatusInfoMap();
    // initializeDefaultStatusFilters(); // Commented: alles altijd standaard aanzetten.

    initializePageLoaderHandlers();

    if (!app)
    {
        hidePageLoader();
        return;
    }

    const memoMenuWrap = document.getElementById('memoMenuWrap');
    const memoMenuTrigger = document.getElementById('memoMenuTrigger');
    const memoMenuPanel = document.getElementById('memoMenuPanel');
    const memoMenuAll = document.getElementById('memoMenuAll');
    const memoMenuNone = document.getElementById('memoMenuNone');
    const memoMenuInputs = memoMenuPanel
        ? Array.from(memoMenuPanel.querySelectorAll('input[data-memo-key]'))
        : [];

    if (error)
    {
        app.innerHTML = '<div class="error">Fout bij ophalen van OData: ' + escapeHtml(error) + '</div>';
        hidePageLoader();
        return;
    }

    const summaryRow = document.createElement('div');
    summaryRow.className = 'summary-row';
    const summary = document.createElement('div');
    summary.className = 'summary';
    let summaryPrefix = 'Werkorders (beide): ';
    if (invoiceFilter === 'invoiced')
    {
        summaryPrefix = 'Gefactureerde werkorders: ';
    }
    else if (invoiceFilter === 'uninvoiced')
    {
        summaryPrefix = 'Niet-gefactureerde werkorders: ';
    }

    summary.textContent = summaryPrefix + rows.length;
    summaryRow.appendChild(summary);

    const statusHint = document.createElement('div');
    statusHint.className = 'status-filter-hint';
    statusHint.textContent = 'Tip: dubbel-klik op een filter om alleen die status weer te geven';

    const exportButton = document.createElement('button');
    exportButton.type = 'button';
    exportButton.className = 'export-btn';
    exportButton.textContent = 'Export';
    exportButton.addEventListener('click', exportVisibleRowsToCsv);
    summaryRow.appendChild(exportButton);
    app.appendChild(summaryRow);

    const statusFilterBar = document.createElement('div');
    statusFilterBar.className = 'status-filter-bar';
    app.appendChild(statusFilterBar);
    renderStatusButtons();

    if (rows.length === 0)
    {
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = 'Geen open werkorders gevonden.';
        app.appendChild(empty);
        hidePageLoader();
        return;
    }

    const table = document.createElement('table');
    table.className = 'workorders-table';
    const tableScrollWrap = document.createElement('div');
    tableScrollWrap.className = 'table-scroll-wrap';
    const thead = document.createElement('thead');
    let headRow = document.createElement('tr');
    const headerLabelByKey = {};
    table.appendChild(thead);
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    tableScrollWrap.appendChild(table);
    app.appendChild(tableScrollWrap);
    initializeTableDragScroll(tableScrollWrap);

    const noSearchResults = document.createElement('div');
    noSearchResults.className = 'empty table-no-results';
    noSearchResults.textContent = 'Geen regels gevonden voor deze zoekopdracht.';
    noSearchResults.style.display = 'none';
    app.appendChild(noSearchResults);

    const notesOverlay = document.createElement('div');
    notesOverlay.className = 'notes-overlay';
    notesOverlay.style.display = 'none';
    notesOverlay.innerHTML = [
        '<div class="notes-modal" role="dialog" aria-modal="true" aria-label="Notities">',
        '<div class="notes-modal-head">',
        '<strong class="notes-modal-title">Notities</strong>',
        '<button type="button" class="notes-close">Sluiten</button>',
        '</div>',
        '<div class="notes-modal-body"></div>',
        '</div>'
    ].join('');
    app.appendChild(notesOverlay);

    const notesBody = notesOverlay.querySelector('.notes-modal-body');
    const notesModalTitle = notesOverlay.querySelector('.notes-modal-title');
    const notesCloseButton = notesOverlay.querySelector('.notes-close');

    if (notesCloseButton)
    {
        notesCloseButton.addEventListener('click', closeNotesModal);
    }
    notesOverlay.addEventListener('click', function (event)
    {
        if (event.target === notesOverlay)
        {
            closeNotesModal();
        }
    });

    initializeMemoMenu();
    renderTableHeader();
    renderHeader();
    renderRows();
    hidePageLoader();

    function initializePageLoaderHandlers ()
    {
        if (controlsForm)
        {
            controlsForm.addEventListener('submit', function ()
            {
                showPageLoader('Gegevens laden...');
            });
        }

        const reloadTriggerInputs = [companySelect, fromMonthInput, toMonthInput, invoiceFilterSelect].filter(Boolean);
        for (const inputElement of reloadTriggerInputs)
        {
            inputElement.addEventListener('change', function ()
            {
                if (inputElement === fromMonthInput || inputElement === toMonthInput || inputElement === invoiceFilterSelect)
                {
                    showPageLoader('Filter toepassen...');
                    return;
                }

                showPageLoader('Gegevens laden...');
            });
        }

        window.addEventListener('beforeunload', function ()
        {
            showPageLoader('Gegevens laden...');
        });
    }

    function initializeTableDragScroll (scrollElement)
    {
        if (!scrollElement)
        {
            return;
        }

        let isPointerDown = false;
        let hasDragged = false;
        let suppressClick = false;
        let startClientX = 0;
        let startClientY = 0;
        let startScrollLeft = 0;
        let startScrollTop = 0;
        let startWindowScrollY = 0;

        const interactiveSelector = 'button, input, select, textarea, a, [role="button"], .notes-btn, .memo-cell-clickable, .invoice-id-clickable, .amount-info-clickable';

        function endDrag ()
        {
            if (!isPointerDown)
            {
                return;
            }

            isPointerDown = false;
            scrollElement.classList.remove('is-dragging-scroll');
            document.body.classList.remove('dragging-table-scroll');

            if (hasDragged)
            {
                suppressClick = true;
                window.setTimeout(function ()
                {
                    suppressClick = false;
                }, 0);
            }
        }

        scrollElement.addEventListener('mousedown', function (event)
        {
            if (event.button !== 0)
            {
                return;
            }

            const target = event.target;
            if (target instanceof Element && target.closest(interactiveSelector))
            {
                return;
            }

            isPointerDown = true;
            hasDragged = false;
            startClientX = event.clientX;
            startClientY = event.clientY;
            startScrollLeft = scrollElement.scrollLeft;
            startScrollTop = scrollElement.scrollTop;
            startWindowScrollY = window.scrollY || window.pageYOffset || 0;

            scrollElement.classList.add('is-dragging-scroll');
            document.body.classList.add('dragging-table-scroll');
            event.preventDefault();
        });

        window.addEventListener('mousemove', function (event)
        {
            if (!isPointerDown)
            {
                return;
            }

            const deltaX = event.clientX - startClientX;
            const deltaY = event.clientY - startClientY;
            if (Math.abs(deltaX) > 3 || Math.abs(deltaY) > 3)
            {
                hasDragged = true;
            }

            scrollElement.scrollLeft = startScrollLeft - deltaX;

            if (scrollElement.scrollHeight > scrollElement.clientHeight)
            {
                scrollElement.scrollTop = startScrollTop - deltaY;
            }
            else
            {
                window.scrollTo(window.scrollX, startWindowScrollY - deltaY);
            }
        });

        window.addEventListener('mouseup', endDrag);
        window.addEventListener('blur', endDrag);

        scrollElement.addEventListener('click', function (event)
        {
            if (!suppressClick)
            {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
        }, true);
    }

    function showPageLoader (text)
    {
        if (!pageLoader)
        {
            return;
        }

        if (pageLoaderText && typeof text === 'string' && text.trim() !== '')
        {
            pageLoaderText.textContent = text;
        }

        pageLoader.classList.add('is-visible');
    }

    function hidePageLoader ()
    {
        if (!pageLoader)
        {
            return;
        }

        pageLoader.classList.remove('is-visible');
    }

    function buildTableColumns ()
    {
        const list = baseColumns.slice();
        const hasGroupedMemoFields = getGroupedMemoFields().length > 0;

        for (const field of memoFields)
        {
            if (selectedMemoColumnKeys.has(field.key))
            {
                list.push({ key: field.key, label: field.label, isMemoField: true });
            }
        }

        if (showInvoiced)
        {
            list.push({ key: 'Invoice_Id', label: 'Factuur ID' });
        }

        if (hasGroupedMemoFields)
        {
            list.push({ key: 'Notes', label: 'Notities' });
        }

        return list;
    }

    function buildExportColumns ()
    {
        const list = baseColumns.slice();

        for (const field of memoFields)
        {
            list.push({ key: field.key, label: field.label, isMemoField: true });
        }

        if (showInvoiced)
        {
            list.push({ key: 'Invoice_Id', label: 'Factuur ID' });
        }

        return list;
    }

    function getGroupedMemoFields ()
    {
        return memoFields.filter(function (field)
        {
            return !selectedMemoColumnKeys.has(field.key);
        });
    }

    function initializeMemoMenu ()
    {
        syncMemoMenuInputs();

        if (memoMenuTrigger && memoMenuPanel)
        {
            memoMenuTrigger.addEventListener('click', function ()
            {
                memoMenuPanel.classList.toggle('is-open');
            });
        }

        if (memoMenuPanel)
        {
            memoMenuPanel.addEventListener('click', function (event)
            {
                event.stopPropagation();
            });
        }

        if (memoMenuWrap)
        {
            memoMenuWrap.addEventListener('click', function (event)
            {
                event.stopPropagation();
            });
        }

        document.addEventListener('click', function ()
        {
            if (memoMenuPanel)
            {
                memoMenuPanel.classList.remove('is-open');
            }
        });

        for (const input of memoMenuInputs)
        {
            input.addEventListener('change', function ()
            {
                const memoKey = String(input.dataset.memoKey || '');
                if (!isMemoFieldKey(memoKey))
                {
                    return;
                }

                if (input.checked)
                {
                    selectedMemoColumnKeys.add(memoKey);
                }
                else
                {
                    selectedMemoColumnKeys.delete(memoKey);
                }

                applyMemoColumnSelection();
                saveMemoColumnSettings();
            });
        }

        if (memoMenuAll)
        {
            memoMenuAll.addEventListener('click', function ()
            {
                setAllMemoColumnsSelected(true);
            });
        }

        if (memoMenuNone)
        {
            memoMenuNone.addEventListener('click', function ()
            {
                setAllMemoColumnsSelected(false);
            });
        }
    }

    function setAllMemoColumnsSelected (selected)
    {
        if (selected)
        {
            for (const field of memoFields)
            {
                selectedMemoColumnKeys.add(field.key);
            }
        }
        else
        {
            selectedMemoColumnKeys.clear();
        }

        applyMemoColumnSelection();
        saveMemoColumnSettings();
    }

    function syncMemoMenuInputs ()
    {
        for (const input of memoMenuInputs)
        {
            const memoKey = String(input.dataset.memoKey || '');
            input.checked = selectedMemoColumnKeys.has(memoKey);
        }
    }

    function applyMemoColumnSelection ()
    {
        columns = buildTableColumns();

        if (!columns.some(function (column)
        {
            return column.key === sortState.key;
        }))
        {
            sortState.key = 'No';
            sortState.direction = 'asc';
        }

        syncMemoMenuInputs();
        renderTableHeader();
        renderHeader();
        renderRows();
    }

    function saveMemoColumnSettings ()
    {
        const memoColumns = {};
        for (const field of memoFields)
        {
            memoColumns[field.key] = selectedMemoColumnKeys.has(field.key);
        }

        fetch(saveUserSettingsUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ memo_columns: memoColumns })
        }).catch(function ()
        {
        });
    }

    function renderTableHeader ()
    {
        thead.innerHTML = '';
        headRow = document.createElement('tr');
        for (const key of Object.keys(headerLabelByKey))
        {
            delete headerLabelByKey[key];
        }

        for (const column of columns)
        {
            const th = document.createElement('th');
            if (compactColumnKeys.has(column.key))
            {
                th.classList.add('col-compact');
            }

            if (column.key === 'Cost_Center')
            {
                th.classList.add('col-compact-cost-center');
            }

            if (column.key === 'Notes')
            {
                th.classList.add('col-notes');
            }

            if (column.key === 'Status' || column.key === 'Document_Status')
            {
                th.classList.add('col-status');
            }

            if (column.key === 'No')
            {
                th.classList.add('col-workorder');
            }

            if (column.key === 'Order_Type')
            {
                th.classList.add('col-ordertype');
            }

            if (column.key === 'Job_No')
            {
                th.classList.add('col-project-no');
            }

            if (column.key === 'Customer_Id')
            {
                th.classList.add('col-customer-id');
            }

            if (column.key === 'Start_Date')
            {
                th.classList.add('col-start-date');
            }

            if (column.key === 'Equipment_Number')
            {
                th.classList.add('col-equipment-number');
            }

            if (column.key === 'Memo_KVT_Remarks_Invoicing')
            {
                th.classList.add('col-memo-remarks');
            }

            th.setAttribute('role', 'button');
            th.tabIndex = 0;
            th.dataset.sortKey = column.key;
            th.title = 'Klik om te sorteren';
            th.addEventListener('click', function ()
            {
                updateSort(column.key);
            });
            th.addEventListener('keydown', function (event)
            {
                if (event.key === 'Enter' || event.key === ' ')
                {
                    event.preventDefault();
                    updateSort(column.key);
                }
            });

            const label = document.createElement('span');
            label.className = 'column-header-label';
            th.appendChild(label);
            headerLabelByKey[column.key] = label;

            if (column.key === 'Cost_Center')
            {
                th.classList.add('cost-center-th');

                const filterWrapper = document.createElement('div');
                filterWrapper.className = 'cost-center-filter-wrap';

                const filterSelect = document.createElement('select');
                filterSelect.className = 'cost-center-filter';

                const allOption = document.createElement('option');
                allOption.value = 'all';
                allOption.textContent = 'Alle';
                filterSelect.appendChild(allOption);

                for (const optionValue of costCenterOptions)
                {
                    const option = document.createElement('option');
                    option.value = optionValue;
                    option.textContent = optionValue;
                    filterSelect.appendChild(option);
                }

                filterSelect.value = selectedCostCenter;
                filterSelect.addEventListener('change', function ()
                {
                    selectedCostCenter = String(filterSelect.value || 'all');
                    renderRows();
                });

                for (const eventName of ['click', 'dblclick', 'mousedown', 'keydown'])
                {
                    filterSelect.addEventListener(eventName, function (event)
                    {
                        event.stopPropagation();
                    });
                }

                filterWrapper.appendChild(filterSelect);
                th.appendChild(filterWrapper);
            }

            headRow.appendChild(th);
        }

        thead.appendChild(headRow);
    }

    function updateSort (key)
    {
        if (sortState.key === key)
        {
            sortState.direction = sortState.direction === 'asc' ? 'desc' : 'asc';
        }
        else
        {
            sortState.key = key;
            sortState.direction = 'asc';
        }

        renderHeader();
        renderRows();
    }

    function renderHeader ()
    {
        for (const th of headRow.querySelectorAll('th'))
        {
            const key = th.dataset.sortKey || '';
            const column = columns.find(function (item)
            {
                return item.key === key;
            });
            if (!column)
            {
                continue;
            }

            const active = sortState.key === key;
            const arrow = active ? (sortState.direction === 'asc' ? ' ▲' : ' ▼') : '';
            const label = headerLabelByKey[key];
            if (!label)
            {
                continue;
            }

            label.textContent = formatDisplayLabel(column.label) + arrow;
        }
    }

    function renderRows ()
    {
        tbody.innerHTML = '';
        const visibleRows = getVisibleSortedRows();

        for (const row of visibleRows)
        {
            const tr = document.createElement('tr');
            const statusKey = normalizeStatus(row.Status || '');
            const workorderAmountCells = [];
            let invoiceIdCell = null;

            tr.className = 'status-' + statusKey;

            for (const column of columns)
            {
                const td = document.createElement('td');
                if (compactColumnKeys.has(column.key))
                {
                    td.classList.add('col-compact');
                }

                if (column.key === 'Cost_Center')
                {
                    td.classList.add('col-compact-cost-center');
                }

                if (column.key === 'Notes')
                {
                    td.classList.add('col-notes');
                }

                if (column.key === 'Status' || column.key === 'Document_Status')
                {
                    td.classList.add('col-status');
                }

                if (column.key === 'No')
                {
                    td.classList.add('col-workorder');
                }

                if (column.key === 'Order_Type')
                {
                    td.classList.add('col-ordertype');
                }

                if (column.key === 'Job_No')
                {
                    td.classList.add('col-project-no');
                }

                if (column.key === 'Customer_Id')
                {
                    td.classList.add('col-customer-id');
                }

                if (column.key === 'Start_Date')
                {
                    td.classList.add('col-start-date');
                }

                if (column.key === 'Equipment_Number')
                {
                    td.classList.add('col-equipment-number');
                }

                if (column.key === 'Memo_KVT_Remarks_Invoicing')
                {
                    td.classList.add('col-memo-remarks');
                }

                if (column.key === 'Invoice_Id')
                {
                    invoiceIdCell = td;
                }

                if (column.key === 'Notes')
                {
                    const groupedMemoFields = getGroupedMemoFields();
                    const groupedLabelSet = new Set(groupedMemoFields.map(function (field)
                    {
                        return field.noteLabel;
                    }));

                    const groupedParts = (Array.isArray(row.Notes) ? row.Notes : []).filter(function (part)
                    {
                        const label = String((part && part.label) || '').trim();
                        if (!groupedLabelSet.has(label))
                        {
                            return false;
                        }

                        return String((part && part.value) || '').trim() !== '';
                    });

                    const hasNotes = groupedParts.some(function (part)
                    {
                        return String((part && part.value) || '').trim() !== '';
                    });

                    if (hasNotes)
                    {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'notes-btn';
                        button.textContent = 'Bekijk';
                        button.addEventListener('click', function ()
                        {
                            openNotesModal(groupedParts);
                        });
                        td.appendChild(button);
                    }
                    else
                    {
                        td.textContent = '';
                    }
                }
                else if (isMemoFieldKey(column.key))
                {
                    const memoValue = getMemoFieldValue(row, column.key);
                    td.textContent = memoValue;
                    td.classList.add('memo-cell-full');

                    if (memoValue.trim() !== '')
                    {
                        const memoField = memoFieldByKey[column.key];
                        td.classList.add('memo-cell-clickable');
                        td.addEventListener('click', function ()
                        {
                            openNotesModal([
                                {
                                    label: memoField ? memoField.noteLabel : column.key,
                                    value: memoValue,
                                }
                            ]);
                        });
                    }
                }
                else
                {
                    if (column.key === 'Actual_Total')
                    {
                        const totalAmount = Number(row[column.key] || 0);
                        if (totalAmount === 0)
                        {
                            td.textContent = '';
                        }
                        else
                        {
                            td.textContent = formatSignedCurrency(totalAmount);
                            td.classList.add(totalAmount > 0 ? 'amount-positive' : 'amount-negative');
                        }
                    }
                    else if (column.key === 'Actual_Costs' || column.key === 'Total_Revenue')
                    {
                        td.textContent = formatCurrencyOrZero(row[column.key]);
                    }
                    else if (numericSortKeys.has(column.key))
                    {
                        td.textContent = formatCurrencyOrEmpty(row[column.key]);
                    }
                    else
                    {
                        if (column.key === 'Equipment_Number')
                        {
                            td.textContent = getEquipmentDisplayValue(row);
                        }
                        else
                        {
                            td.textContent = String(row[column.key] || '');
                        }
                    }
                }

                if (amountColumnKeys.has(column.key))
                {
                    const amountSourceInfo = getAmountSourceInfo(column.key, row);
                    if (amountSourceInfo.source === 'invoice')
                    {
                        td.title = invoiceAmountTooltip;
                    }
                    else if (amountSourceInfo.source === 'mixed')
                    {
                        td.title = 'Deze waarde is samengesteld uit meerdere bronnen (factuur en werkorder). Klik voor details.';
                    }
                    else
                    {
                        td.title = workorderAmountTooltip;
                    }
                    td.classList.add('amount-info-clickable');
                    td.addEventListener('click', function ()
                    {
                        openAmountSourceModal(column.key, row);
                    });

                    const matchPath = String(row.Invoice_Match_Path || '').trim();
                    if (amountSourceInfo.source === 'invoice' && matchPath === 'project_dimension_2')
                    {
                        td.classList.add('amount-underline-project');
                    }
                    else if (amountSourceInfo.source !== 'invoice')
                    {
                        td.classList.add('amount-underline-workorder');
                        workorderAmountCells.push(td);
                    }
                }

                if (column.key === 'Invoice_Id')
                {
                    const invoiceIdValue = String(row.Invoice_Id || '').trim();
                    if (invoiceIdValue !== '')
                    {
                        const matchPath = String(row.Invoice_Match_Path || '').trim();
                        const invoiceSuffix = matchPath === 'project_dimension_2' ? ' (PRJ)' : ' (WO)';
                        td.textContent = invoiceIdValue + invoiceSuffix;
                        td.classList.add('invoice-id-clickable');
                        td.title = 'Klik voor factuurdetails';
                        td.addEventListener('click', function ()
                        {
                            openInvoiceDetailsModal(invoiceIdValue);
                        });
                    }
                }

                tr.appendChild(td);
            }

            if (workorderAmountCells.length > 0 && invoiceIdCell)
            {
                for (const amountCell of workorderAmountCells)
                {
                    amountCell.addEventListener('mouseenter', function ()
                    {
                        triggerInvoiceCellBlink(invoiceIdCell);
                    });

                    amountCell.addEventListener('mouseleave', function ()
                    {
                        invoiceIdCell.classList.remove('invoice-cell-blink');
                    });
                }
            }

            tbody.appendChild(tr);
        }

        noSearchResults.style.display = visibleRows.length === 0 ? '' : 'none';
    }

    function triggerInvoiceCellBlink (cell)
    {
        cell.classList.remove('invoice-cell-blink');
        void cell.offsetWidth;
        cell.classList.add('invoice-cell-blink');
    }

    function renderStatusButtons ()
    {
        statusFilterBar.innerHTML = '';

        const title = document.createElement('span');
        title.className = 'status-filter-title';
        title.textContent = 'Statusfilters:';
        statusFilterBar.appendChild(title);

        const orderedStatusKeys = Object.keys(statusInfoMap).sort(function (a, b)
        {
            const leftIndex = statusOrder.indexOf(a);
            const rightIndex = statusOrder.indexOf(b);
            if (leftIndex === -1 && rightIndex === -1) return a.localeCompare(b, 'nl', { sensitivity: 'base' });
            if (leftIndex === -1) return 1;
            if (rightIndex === -1) return -1;
            return leftIndex - rightIndex;
        });

        const toggleAllButton = document.createElement('button');
        toggleAllButton.type = 'button';
        toggleAllButton.className = 'status-toggle-all-btn';
        toggleAllButton.textContent = areAllStatusesEnabled(orderedStatusKeys) ? 'Alles uit' : 'Alles aan';
        toggleAllButton.addEventListener('click', function ()
        {
            if (areAllStatusesEnabled(orderedStatusKeys))
            {
                for (const statusKey of orderedStatusKeys)
                {
                    hiddenStatuses.add(statusKey);
                }
            }
            else
            {
                for (const statusKey of orderedStatusKeys)
                {
                    hiddenStatuses.delete(statusKey);
                }
            }

            manuallyHiddenStatuses.clear();
            updateStatusHint();
            renderStatusButtons();
            renderRows();
        });
        statusFilterBar.appendChild(toggleAllButton);

        for (const statusKey of orderedStatusKeys)
        {
            const info = statusInfoMap[statusKey];
            if (!info) continue;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'status-filter-btn status-' + statusKey;
            button.textContent = info.label + ' (' + info.count + ')';
            button.setAttribute('aria-pressed', hiddenStatuses.has(statusKey) ? 'false' : 'true');

            if (hiddenStatuses.has(statusKey))
            {
                button.classList.add('is-off');
            }

            button.addEventListener('click', function ()
            {
                if (hiddenStatuses.has(statusKey))
                {
                    hiddenStatuses.delete(statusKey);
                    manuallyHiddenStatuses.delete(statusKey);
                }
                else
                {
                    hiddenStatuses.add(statusKey);
                    manuallyHiddenStatuses.add(statusKey);
                }

                updateStatusHint();
                renderStatusButtons();
                renderRows();
            });

            button.addEventListener('dblclick', function (event)
            {
                event.preventDefault();
                event.stopPropagation();

                dismissStatusHint();

                for (const otherStatusKey of orderedStatusKeys)
                {
                    if (otherStatusKey === statusKey)
                    {
                        hiddenStatuses.delete(otherStatusKey);
                    }
                    else
                    {
                        hiddenStatuses.add(otherStatusKey);
                    }
                }

                manuallyHiddenStatuses.clear();
                updateStatusHint();
                renderStatusButtons();
                renderRows();
            });

            statusFilterBar.appendChild(button);
        }

        statusFilterBar.appendChild(statusHint);

        renderSearchForm();
    }

    function renderSearchForm ()
    {
        const searchForm = document.createElement('form');
        searchForm.className = 'status-search-form';

        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Zoek in tabel';
        searchInput.value = appliedSearchText;
        searchInput.setAttribute('aria-label', 'Zoek in tabel');

        const searchButton = document.createElement('button');
        searchButton.type = 'submit';
        searchButton.textContent = 'Zoek';

        searchForm.addEventListener('submit', function (event)
        {
            event.preventDefault();
            appliedSearchText = String(searchInput.value || '').trim().toLowerCase();
            renderRows();
        });

        searchForm.appendChild(searchInput);
        searchForm.appendChild(searchButton);
        statusFilterBar.appendChild(searchForm);
    }

    function rowMatchesSearch (row)
    {
        if (appliedSearchText === '')
        {
            return true;
        }

        const notesSearch = String(row.Notes_Search || '').toLowerCase();
        if (notesSearch.includes(appliedSearchText))
        {
            return true;
        }

        for (const column of columns)
        {
            if (column.key === 'Notes')
            {
                continue;
            }

            if (column.key === 'Equipment_Number')
            {
                const equipmentValue = getEquipmentDisplayValue(row).toLowerCase();
                if (equipmentValue.includes(appliedSearchText))
                {
                    return true;
                }
                continue;
            }

            if (isMemoFieldKey(column.key))
            {
                const memoValue = getMemoFieldValue(row, column.key).toLowerCase();
                if (memoValue.includes(appliedSearchText))
                {
                    return true;
                }
                continue;
            }

            const value = String(row[column.key] || '').toLowerCase();
            if (value.includes(appliedSearchText))
            {
                return true;
            }
        }

        return false;
    }

    function buildStatusInfoMap ()
    {
        const map = {};
        for (const row of rows)
        {
            const statusValue = String(row.Status || '').trim();
            const key = normalizeStatus(statusValue);
            if (!key)
            {
                continue;
            }

            if (!map[key])
            {
                map[key] = {
                    label: statusValue || key,
                    count: 0
                };
            }

            map[key].count += 1;
        }

        return map;
    }

    function buildCostCenterOptions ()
    {
        const values = new Set();

        for (const row of rows)
        {
            const value = String(row.Cost_Center || '').trim();
            if (value === '')
            {
                continue;
            }

            values.add(value);
        }

        return Array.from(values).sort(function (a, b)
        {
            return a.localeCompare(b, 'nl', { numeric: true, sensitivity: 'base' });
        });
    }

    function getVisibleSortedRows ()
    {
        const sorted = rows.slice().sort(compareRows);
        return sorted.filter(function (row)
        {
            if (selectedCostCenter !== 'all')
            {
                const rowCostCenter = String(row.Cost_Center || '').trim();
                if (rowCostCenter !== selectedCostCenter)
                {
                    return false;
                }
            }

            const statusKey = normalizeStatus(row.Status || '');
            if (hiddenStatuses.has(statusKey))
            {
                return false;
            }

            return rowMatchesSearch(row);
        });
    }

    function exportVisibleRowsToCsv ()
    {
        const visibleRows = getVisibleSortedRows();
        const delimiter = ';';
        const headers = exportColumns.map(function (column)
        {
            return formatDisplayLabel(column.label);
        });

        const csvLines = [headers.map(escapeCsvValue).join(delimiter)];
        for (const row of visibleRows)
        {
            const values = exportColumns.map(function (column)
            {
                return getExportValue(row, column.key);
            });
            csvLines.push(values.map(escapeCsvValue).join(delimiter));
        }

        const csvContent = '\uFEFF' + csvLines.join('\r\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'demeter_export.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function getExportValue (row, key)
    {
        if (isMemoFieldKey(key))
        {
            return getMemoFieldValue(row, key);
        }

        if (key === 'Notes')
        {
            const parts = Array.isArray(row.Notes) ? row.Notes : [];
            const lines = [];
            for (const part of parts)
            {
                const label = String((part && part.label) || '').trim().replaceAll("_", " ");
                const value = String((part && part.value) || '').trim();
                if (value === '')
                {
                    continue;
                }
                lines.push(label + ': ' + value);
            }
            return lines.join(' | ');
        }

        if (key === 'Equipment_Number')
        {
            return getEquipmentDisplayValue(row);
        }

        if (key === 'Actual_Total')
        {
            return formatSignedCurrency(row[key]);
        }

        if (key === 'Actual_Costs' || key === 'Total_Revenue')
        {
            return formatCurrencyOrZero(row[key]);
        }

        return String(row[key] || '');
    }

    function escapeCsvValue (value)
    {
        const text = String(value || '');
        return '"' + text.replace(/"/g, '""') + '"';
    }

    function compareRows (a, b)
    {
        if (sortState.key === 'Equipment_Number')
        {
            const leftEquipment = getEquipmentDisplayValue(a);
            const rightEquipment = getEquipmentDisplayValue(b);
            const equipmentComparison = leftEquipment.localeCompare(rightEquipment, 'nl', { numeric: true, sensitivity: 'base' });
            return sortState.direction === 'asc' ? equipmentComparison : -equipmentComparison;
        }

        if (numericSortKeys.has(sortState.key))
        {
            const leftNumber = Number(a[sortState.key] || 0);
            const rightNumber = Number(b[sortState.key] || 0);
            const difference = leftNumber - rightNumber;
            return sortState.direction === 'asc' ? difference : -difference;
        }

        const left = normalizeSortValue(getColumnValueForSorting(a, sortState.key));
        const right = normalizeSortValue(getColumnValueForSorting(b, sortState.key));

        const comparison = left.localeCompare(right, 'nl', { numeric: true, sensitivity: 'base' });
        return sortState.direction === 'asc' ? comparison : -comparison;
    }

    function normalizeSortValue (value)
    {
        return String(value || '').trim();
    }

    function formatDisplayLabel (value)
    {
        return String(value || '').replaceAll('_', ' ').trim();
    }

    function isMemoFieldKey (key)
    {
        return Object.prototype.hasOwnProperty.call(memoFieldByKey, key);
    }

    function getMemoFieldValue (row, memoKey)
    {
        if (!row || typeof row !== 'object')
        {
            return '';
        }

        if (!row.__memoValues)
        {
            const mappedValues = {};
            const parts = Array.isArray(row.Notes) ? row.Notes : [];

            for (const part of parts)
            {
                const label = String((part && part.label) || '').trim();
                if (label === '')
                {
                    continue;
                }

                mappedValues[label] = String((part && part.value) || '');
            }

            row.__memoValues = mappedValues;
        }

        const field = memoFieldByKey[memoKey];
        if (!field)
        {
            return '';
        }

        return String(row.__memoValues[field.noteLabel] || '');
    }

    function getColumnValueForSorting (row, key)
    {
        if (key === 'Equipment_Number')
        {
            return getEquipmentDisplayValue(row);
        }

        if (isMemoFieldKey(key))
        {
            return getMemoFieldValue(row, key);
        }

        return row[key];
    }

    function formatCurrencyOrEmpty (value)
    {
        const amount = Number(value || 0);
        if (amount === 0)
        {
            return '';
        }

        return currencyFormatter.format(amount);
    }

    function formatSignedCurrency (value)
    {
        const amount = Number(value || 0);
        if (amount === 0)
        {
            return '';
        }

        const sign = amount > 0 ? '+' : '-';
        return sign + currencyFormatter.format(Math.abs(amount));
    }

    function formatCurrencyOrZero (value)
    {
        const amount = Number(value || 0);
        if (amount === 0)
        {
            return '€ 0';
        }

        return currencyFormatter.format(amount);
    }

    function normalizeStatus (value)
    {
        const normalized = String(value || '').trim().toLowerCase();
        const aliases = {
            'open': 'open',
            'afgesloten': 'closed',
            'geannuleerd': 'cancelled',
            'gecontroleerd': 'checked',
            'gepland': 'planned',
            'onderhanden': 'in-progress',
            'ondertekend': 'signed',
            'uitgevoerd': 'completed'
        };

        if (aliases[normalized])
        {
            return aliases[normalized];
        }

        return normalized.replaceAll(' ', '-');
    }

    function initializeDefaultStatusFilters ()
    {
        if (!statusInfoMap['in-progress'])
        {
            return;
        }

        for (const statusKey of Object.keys(statusInfoMap))
        {
            if (statusKey !== 'in-progress')
            {
                hiddenStatuses.add(statusKey);
            }
        }
    }

    function areAllStatusesEnabled (statusKeys)
    {
        if (!Array.isArray(statusKeys) || statusKeys.length === 0)
        {
            return false;
        }

        for (const statusKey of statusKeys)
        {
            if (hiddenStatuses.has(statusKey))
            {
                return false;
            }
        }

        return true;
    }

    function updateStatusHint ()
    {
        if (manuallyHiddenStatuses.size > 5)
        {
            statusHint.classList.add('is-visible');
            if (statusHintTimeoutId !== null)
            {
                clearTimeout(statusHintTimeoutId);
            }

            statusHintTimeoutId = setTimeout(function ()
            {
                dismissStatusHint();
            }, 20000);
        }
        else
        {
            statusHint.classList.remove('is-visible');
        }
    }

    function dismissStatusHint ()
    {
        statusHint.classList.remove('is-visible');

        if (statusHintTimeoutId !== null)
        {
            clearTimeout(statusHintTimeoutId);
            statusHintTimeoutId = null;
        }

        manuallyHiddenStatuses.clear();
    }

    function getEquipmentDisplayValue (row)
    {
        const numberValue = String((row && row.Equipment_Number) || '').trim();
        if (numberValue !== '')
        {
            return numberValue;
        }

        return String((row && row.Equipment_Name) || '').trim();
    }

    function openNotesModal (parts)
    {
        if (!notesBody)
        {
            return;
        }

        if (notesModalTitle)
        {
            notesModalTitle.textContent = 'Notities';
        }

        notesBody.innerHTML = '';
        let hasVisibleNotes = false;

        for (const part of parts)
        {
            const label = String((part && part.label) || '').trim().replaceAll("_", " ");
            const value = String((part && part.value) || '').trim();
            if (value === '')
            {
                continue;
            }

            const section = document.createElement('div');
            section.className = 'notes-section';

            const heading = document.createElement('div');
            heading.className = 'notes-section-title';
            heading.textContent = label;

            const content = document.createElement('pre');
            content.className = 'notes-section-text';
            content.textContent = value;

            section.appendChild(heading);
            section.appendChild(content);
            notesBody.appendChild(section);
            hasVisibleNotes = true;
        }

        if (!hasVisibleNotes)
        {
            const empty = document.createElement('div');
            empty.textContent = 'Geen notities beschikbaar.';
            notesBody.appendChild(empty);
        }

        notesOverlay.style.display = '';
    }

    function openInvoiceDetailsModal (invoiceId)
    {
        if (!notesBody)
        {
            return;
        }

        const normalizedInvoiceId = String(invoiceId || '').trim();
        const details = invoiceDetailsById[normalizedInvoiceId];

        if (notesModalTitle)
        {
            notesModalTitle.textContent = normalizedInvoiceId === ''
                ? 'Factuurdetails'
                : 'Factuurdetails: ' + normalizedInvoiceId;
        }

        notesBody.innerHTML = '';

        if (!details || typeof details !== 'object')
        {
            const empty = document.createElement('div');
            empty.textContent = 'Geen factuurdetails beschikbaar.';
            notesBody.appendChild(empty);
            notesOverlay.style.display = '';
            return;
        }

        const entries = Object.entries(details);
        if (entries.length === 0)
        {
            const empty = document.createElement('div');
            empty.textContent = 'Geen factuurdetails beschikbaar.';
            notesBody.appendChild(empty);
            notesOverlay.style.display = '';
            return;
        }

        for (const detailEntry of entries)
        {
            const key = String(detailEntry[0] || '').trim();
            if (key === '')
            {
                continue;
            }

            const value = detailEntry[1];
            let valueText = '';
            if (Array.isArray(value))
            {
                valueText = value.join(', ');
            }
            else
            {
                valueText = String(value ?? '').trim();
            }

            if (valueText === '')
            {
                continue;
            }

            const section = document.createElement('div');
            section.className = 'notes-section';

            const heading = document.createElement('div');
            heading.className = 'notes-section-title';
            heading.textContent = key.replaceAll('_', ' ');

            const content = document.createElement('pre');
            content.className = 'notes-section-text';
            content.textContent = valueText;

            section.appendChild(heading);
            section.appendChild(content);
            notesBody.appendChild(section);
        }

        if (notesBody.children.length === 0)
        {
            const empty = document.createElement('div');
            empty.textContent = 'Geen factuurdetails beschikbaar.';
            notesBody.appendChild(empty);
        }

        notesOverlay.style.display = '';
    }

    function getAmountSourceInfo (columnKey, row)
    {
        const safeRow = row && typeof row === 'object' ? row : {};

        if (columnKey === 'Actual_Costs')
        {
            return {
                source: safeRow.Actual_Costs_Source === 'invoice' ? 'invoice' : 'workorder',
                reason: String(safeRow.Actual_Costs_Source_Reason || '').trim(),
                label: 'Werkelijke kosten'
            };
        }

        if (columnKey === 'Total_Revenue')
        {
            return {
                source: safeRow.Total_Revenue_Source === 'invoice' ? 'invoice' : 'workorder',
                reason: String(safeRow.Total_Revenue_Source_Reason || '').trim(),
                label: 'Totaalopbrengst'
            };
        }

        return {
            source: safeRow.Actual_Total_Source === 'invoice' ? 'invoice' : (safeRow.Actual_Total_Source === 'workorder' ? 'workorder' : 'mixed'),
            reason: String(safeRow.Actual_Total_Source_Reason || '').trim(),
            label: 'Werkelijk totaal'
        };
    }

    function openAmountSourceModal (columnKey, row)
    {
        if (!notesBody)
        {
            return;
        }

        const sourceInfo = getAmountSourceInfo(columnKey, row);
        const source = sourceInfo.source;
        const diagnostics = row && typeof row === 'object' ? row : {};
        const sourceReason = String(diagnostics.Amount_Source_Reason || '').trim();
        const matchPath = String(diagnostics.Invoice_Match_Path || '').trim();
        const matchSource = String(diagnostics.Invoice_Match_Source || '').trim();
        const invoiceId = String(diagnostics.Invoice_Id || '').trim();
        const invoiceBasisLabel = matchPath === 'project_dimension_2' ? 'projectbasis' : 'werkorderbasis';
        const message = source === 'invoice'
            ? invoiceAmountModalMessage + ' Gevonden op ' + invoiceBasisLabel + '.'
            : (source === 'workorder' ? workorderAmountModalMessage : 'Dit totaal gebruikt een combinatie van bronnen (factuur en werkorder).');
        let matchPathLabel = '(onbekend)';
        if (matchPath === 'job_task')
        {
            matchPathLabel = 'Werkorderbasis (job + taak)';
        }
        else if (matchPath === 'job')
        {
            matchPathLabel = 'Werkorderbasis (job)';
        }
        else if (matchPath === 'reference')
        {
            matchPathLabel = 'Referentie-match (document/referentie)';
        }
        else if (matchPath === 'project_dimension_2')
        {
            matchPathLabel = 'Projectbasis, niet werkorderbasis';
        }
        else if (matchPath === 'none')
        {
            matchPathLabel = 'Geen match';
        }

        const projectMatchNote = matchPath === 'project_dimension_2'
            ? '⚠️ Let op: deze factuur is op projectbasis gematcht, niet op werkordernummer.'
            : '';

        if (notesModalTitle)
        {
            notesModalTitle.textContent = 'Herkomst bedragen';
        }

        notesBody.innerHTML = '';

        const section = document.createElement('div');
        section.className = 'notes-section';

        const heading = document.createElement('div');
        heading.className = 'notes-section-title';
        heading.textContent = sourceInfo.label + ' · Bron: ' + (source === 'invoice' ? ('factuur (' + invoiceBasisLabel + ')') : (source === 'workorder' ? 'werkorder' : 'gemengd'));

        const content = document.createElement('pre');
        content.className = 'notes-section-text';
        content.textContent = message;

        section.appendChild(heading);
        section.appendChild(content);
        notesBody.appendChild(section);

        const diagnosticsSection = document.createElement('div');
        diagnosticsSection.className = 'notes-section';

        const diagnosticsHeading = document.createElement('div');
        diagnosticsHeading.className = 'notes-section-title';
        diagnosticsHeading.textContent = 'Diagnose';

        const diagnosticsContent = document.createElement('pre');
        diagnosticsContent.className = 'notes-section-text';
        diagnosticsContent.textContent = [
            'Factuur ID: ' + (invoiceId === '' ? '(leeg)' : invoiceId),
            'Matchpad: ' + matchPathLabel,
            'Matchbron: ' + (matchSource === '' ? '(onbekend)' : matchSource),
            'Kolomreden: ' + (sourceInfo.reason === '' ? '(geen detail)' : sourceInfo.reason),
            'Rijreden: ' + (sourceReason === '' ? '(geen detail)' : sourceReason),
            projectMatchNote,
        ].join('\n');

        diagnosticsSection.appendChild(diagnosticsHeading);
        diagnosticsSection.appendChild(diagnosticsContent);
        notesBody.appendChild(diagnosticsSection);

        notesOverlay.style.display = '';
    }

    function closeNotesModal ()
    {
        notesOverlay.style.display = 'none';
    }

    function escapeHtml (value)
    {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
})();
