(function ()
{
    const app = document.getElementById('app');
    const payload = window.workorderOverviewData || {};
    const rows = Array.isArray(payload.rows) ? payload.rows.slice() : [];
    const error = typeof payload.error === 'string' ? payload.error : null;
    const showInvoiced = payload.gefactureerd === true;
    const columns = [
        { key: 'No', label: 'Werkorder' },
        { key: 'Order_Type', label: 'Ordertype' },
        { key: 'Customer_Id', label: 'Klant id' },
        { key: 'Customer_Name', label: 'Klantnaam' },
        { key: 'Start_Date', label: 'Startdatum' },
        { key: 'Equipment_Number', label: 'Equipment nummer' },
        { key: 'Description', label: 'Omschrijving' },
        { key: 'Actual_Costs', label: 'Werkelijke kosten' },
        { key: 'Total_Revenue', label: 'Totaalopbrengst' },
        { key: 'Actual_Total', label: 'Werkelijk totaal' },
        { key: 'Cost_Center', label: 'Kostenplaats' },
        { key: 'Status', label: 'Status' },
        { key: 'Notes', label: 'Notities' }
    ];
    if (showInvoiced)
    {
        columns.push({ key: 'Invoice_Id', label: 'Invoice ID' });
        columns.push({ key: 'Invoice_Type', label: 'Invoice Type' });
    }
    const sortState = {
        key: 'No',
        direction: 'asc'
    };
    const numericSortKeys = new Set(['Actual_Costs', 'Total_Revenue', 'Actual_Total']);
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
    const statusOrder = ['open', 'signed', 'completed', 'checked', 'in-progress', 'planned', 'closed', 'cancelled'];
    const statusInfoMap = buildStatusInfoMap();
    initializeDefaultStatusFilters();

    if (!app)
    {
        return;
    }

    if (error)
    {
        app.innerHTML = '<div class="error">Fout bij ophalen van OData: ' + escapeHtml(error) + '</div>';
        return;
    }

    const summaryRow = document.createElement('div');
    summaryRow.className = 'summary-row';
    const summary = document.createElement('div');
    summary.className = 'summary';
    summary.textContent = (showInvoiced ? 'Gefactureerde werkorders: ' : 'Niet-gefactureerde werkorders: ') + rows.length;
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
        return;
    }

    const table = document.createElement('table');
    table.className = 'workorders-table';
    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');

    for (const column of columns)
    {
        const th = document.createElement('th');
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
        headRow.appendChild(th);
    }

    thead.appendChild(headRow);
    table.appendChild(thead);
    const tbody = document.createElement('tbody');
    table.appendChild(tbody);
    app.appendChild(table);

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
        '<strong>Notities</strong>',
        '<button type="button" class="notes-close">Sluiten</button>',
        '</div>',
        '<div class="notes-modal-body"></div>',
        '</div>'
    ].join('');
    app.appendChild(notesOverlay);

    const notesBody = notesOverlay.querySelector('.notes-modal-body');
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

    renderHeader();
    renderRows();

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
            th.textContent = column.label + arrow;
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

            tr.className = 'status-' + statusKey;

            for (const column of columns)
            {
                const td = document.createElement('td');
                if (column.key === 'Notes')
                {
                    const hasNotes = Array.isArray(row.Notes) && row.Notes.some(function (part)
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
                            openNotesModal(Array.isArray(row.Notes) ? row.Notes : []);
                        });
                        td.appendChild(button);
                    }
                    else
                    {
                        td.textContent = '';
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
                tr.appendChild(td);
            }

            tbody.appendChild(tr);
        }

        noSearchResults.style.display = visibleRows.length === 0 ? '' : 'none';
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

        for (const column of columns)
        {
            if (column.key === 'Notes')
            {
                const notesSearch = String(row.Notes_Search || '').toLowerCase();
                if (notesSearch.includes(appliedSearchText))
                {
                    return true;
                }
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

    function getVisibleSortedRows ()
    {
        const sorted = rows.slice().sort(compareRows);
        return sorted.filter(function (row)
        {
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
        const headers = columns.map(function (column)
        {
            return column.label;
        });

        const csvLines = [headers.map(escapeCsvValue).join(delimiter)];
        for (const row of visibleRows)
        {
            const values = columns.map(function (column)
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
        if (key === 'Notes')
        {
            const parts = Array.isArray(row.Notes) ? row.Notes : [];
            const lines = [];
            for (const part of parts)
            {
                const label = String((part && part.label) || '').trim();
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

        const left = normalizeSortValue(a[sortState.key]);
        const right = normalizeSortValue(b[sortState.key]);

        const comparison = left.localeCompare(right, 'nl', { numeric: true, sensitivity: 'base' });
        return sortState.direction === 'asc' ? comparison : -comparison;
    }

    function normalizeSortValue (value)
    {
        return String(value || '').trim();
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

        notesBody.innerHTML = '';
        let hasVisibleNotes = false;

        for (const part of parts)
        {
            const label = String((part && part.label) || '').trim();
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
