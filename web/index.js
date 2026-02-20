(function ()
{
    const app = document.getElementById('app');
    const payload = window.workorderOverviewData || {};
    const rows = Array.isArray(payload.rows) ? payload.rows.slice() : [];
    const error = typeof payload.error === 'string' ? payload.error : null;
    const showInvoiced = payload.gefactureerd === true;
    const columns = [
        { key: 'No', label: 'Werkorder' },
        { key: 'Task_Description', label: 'Omschrijving' },
        { key: 'Status', label: 'Status' },
        { key: 'Resource_Name', label: 'Monteur' },
        { key: 'Main_Entity_Description', label: 'Hoofd entiteit' },
        { key: 'Sub_Entity_Description', label: 'Sub entiteit' },
        { key: 'Job_No', label: 'Project' },
        { key: 'Project_Description', label: 'Projectnaam' }
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
    const hiddenStatuses = new Set();
    const statusOrder = ['open', 'signed', 'completed', 'checked', 'in-progress', 'planned', 'closed', 'cancelled'];
    const statusInfoMap = buildStatusInfoMap();

    if (!app)
    {
        return;
    }

    if (error)
    {
        app.innerHTML = '<div class="error">Fout bij ophalen van OData: ' + escapeHtml(error) + '</div>';
        return;
    }

    const summary = document.createElement('div');
    summary.className = 'summary';
    summary.textContent = (showInvoiced ? 'Gefactureerde werkorders: ' : 'Niet-gefactureerde werkorders: ') + rows.length;
    app.appendChild(summary);

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
        const sorted = rows.slice().sort(compareRows);

        for (const row of sorted)
        {
            const tr = document.createElement('tr');
            const statusKey = normalizeStatus(row.Status || '');
            tr.className = 'status-' + statusKey;

            if (hiddenStatuses.has(statusKey))
            {
                tr.classList.add('status-hidden-by-filter');
            }

            for (const column of columns)
            {
                const td = document.createElement('td');
                td.textContent = String(row[column.key] || '');
                tr.appendChild(td);
            }

            tbody.appendChild(tr);
        }
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
                }
                else
                {
                    hiddenStatuses.add(statusKey);
                }

                renderStatusButtons();
                renderRows();
            });

            statusFilterBar.appendChild(button);
        }
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

    function compareRows (a, b)
    {
        const left = normalizeSortValue(a[sortState.key]);
        const right = normalizeSortValue(b[sortState.key]);

        const comparison = left.localeCompare(right, 'nl', { numeric: true, sensitivity: 'base' });
        return sortState.direction === 'asc' ? comparison : -comparison;
    }

    function normalizeSortValue (value)
    {
        return String(value || '').trim();
    }

    function normalizeStatus (value)
    {
        return String(value || '').trim().toLowerCase().replaceAll(" ", "-");
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
