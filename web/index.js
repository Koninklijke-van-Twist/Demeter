(function ()
{
    const app = document.getElementById('app');
    const pageLoader = document.getElementById('pageLoader');
    const pageLoaderContent = pageLoader ? pageLoader.querySelector('.page-loader-content') : null;
    const pageLoaderText = document.getElementById('pageLoaderText');
    const pageLoaderPercent = document.getElementById('pageLoaderPercent');
    const pageLoaderBarFill = document.getElementById('pageLoaderBarFill');
    const pageLoaderCall = document.getElementById('pageLoaderCall');
    const pageLoaderSpinner = pageLoader ? pageLoader.querySelector('.page-loader-spinner') : null;
    const pageLoaderWobbleTargets = [pageLoaderPercent, pageLoaderText, pageLoaderBarFill, pageLoaderCall].filter(Boolean);
    const controlsForm = document.querySelector('form.controls');
    const companySelect = document.getElementById('companySelect');
    const invoiceFilterSelect = document.getElementById('invoiceFilter');
    const costCenterSelect = document.getElementById('costCenterSelect');
    const loadProgressTokenInput = document.getElementById('loadProgressToken');
    const demeterModal = window.DemeterModal;
    const payload = window.workorderOverviewData || {};
    let rows = Array.isArray(payload.rows) ? payload.rows.slice() : [];
    const invoiceDetailsById = payload && typeof payload.invoice_details_by_id === 'object' && payload.invoice_details_by_id !== null
        ? payload.invoice_details_by_id
        : {};
    const projectPostenRowsByProject = payload && typeof payload.projectposten_rows_by_project === 'object' && payload.projectposten_rows_by_project !== null
        ? payload.projectposten_rows_by_project
        : {};
    const projectPostenRowsByProjectAndWorkorder = payload && typeof payload.projectposten_rows_by_project_and_workorder === 'object' && payload.projectposten_rows_by_project_and_workorder !== null
        ? payload.projectposten_rows_by_project_and_workorder
        : {};
    const loadMeta = payload && typeof payload.load_meta === 'object' && payload.load_meta !== null
        ? payload.load_meta
        : {};
    const cacheMeta = payload && typeof payload.cache_meta === 'object' && payload.cache_meta !== null
        ? payload.cache_meta
        : {};
    const nightlyStats = payload && typeof payload.nightly_stats === 'object' && payload.nightly_stats !== null
        ? payload.nightly_stats
        : {};
    const loadedCostCenter = typeof payload.cost_center === 'string' ? payload.cost_center.trim() : '';
    const asyncLoadConfig = payload && typeof payload.async_load === 'object' && payload.async_load !== null
        ? payload.async_load
        : {};
    let loadStatsFromCache = 0;
    let loadStatsUpdatedFromBc = 0;
    let loadStatsNote = null;

    const initialLoadStats = payload && typeof payload.initial_load_stats === 'object' && payload.initial_load_stats !== null
        ? payload.initial_load_stats
        : {};
    loadStatsFromCache = Number(initialLoadStats.from_cache_count || 0);
    loadStatsUpdatedFromBc = Number(initialLoadStats.updated_from_bc_count || 0);

    if (!asyncLoadConfig.enabled)
    {
        loadStatsFromCache += Number(loadMeta.from_cache_count || loadMeta.cached_row_count || 0);
        loadStatsUpdatedFromBc += Number(loadMeta.updated_from_bc_count || loadMeta.fetched_workorder_count || 0);
    }
    const error = typeof payload.error === 'string' ? payload.error : null;
    const invoiceFilter = typeof payload.invoice_filter === 'string' ? payload.invoice_filter : 'both';
    const showInvoiced = invoiceFilter === 'both' || invoiceFilter === 'invoiced';
    const baseColumns = [
        { key: 'No', label: 'Werkorder' },
        { key: 'Order_Type', label: 'Ordertype' },
        { key: 'Contract_No', label: 'Contractnummer' },
        { key: 'Customer_Id', label: 'Klant Nr.' },
        { key: 'Customer_Name', label: 'Klantnaam' },
        { key: 'Start_Date', label: 'Startdatum' },
        { key: 'Component_No', label: 'Component Nr.' },
        { key: 'Component_Description', label: 'Component Description' },
        { key: 'Description', label: 'Omschrijving' },
        { key: 'Actual_Costs', label: 'Kosten werkorder' },
        { key: 'Total_Revenue', label: 'Opbrengst werkorder' },
        { key: 'Actual_Total', label: 'Resultaat werkorder' },
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
    const loadedLayoutStyle = normalizeLayoutStyle(payload.layout_style);
    const loadedKeepProjectWorkordersTogether = payload.keep_project_workorders_together !== false;
    const saveUserSettingsUrl = typeof payload.save_user_settings_url === 'string' ? payload.save_user_settings_url : 'index.php?action=save_user_settings';
    const loadProgressStatusUrl = typeof payload.load_progress_status_url === 'string' ? payload.load_progress_status_url : 'odata.php?action=load_progress';
    const selectedMemoColumnKeys = new Set();
    for (const field of memoFields)
    {
        if (loadedMemoSettings[field.key] !== false)
        {
            selectedMemoColumnKeys.add(field.key);
        }
    }

    let layoutStyle = loadedLayoutStyle;
    let keepProjectWorkordersTogether = loadedKeepProjectWorkordersTogether;
    let columns = buildTableColumns();
    const rowsByKey = new Map();
    const rowLoadStates = new Map();
    const rowDomByKey = new Map();
    const monthScanEmptyStopCount = Number(asyncLoadConfig.empty_stop_count || 12);
    const historyParallelWeekLoads = Math.max(1, Number(asyncLoadConfig.parallel_week_loads || 2));
    let historyWeeksTotal = Number(asyncLoadConfig.history_weeks_total || 0) || null;
    const refreshProgressTotalSteps = Math.max(0, Number(asyncLoadConfig.progress_total_steps || 0));
    const loadWorkorderMemosUrl = typeof asyncLoadConfig.load_workorder_memos_url === 'string'
        ? asyncLoadConfig.load_workorder_memos_url
        : 'index.php?action=load_workorder_memos';
    const refreshAllMemosUrl = typeof asyncLoadConfig.refresh_all_memos_url === 'string'
        ? asyncLoadConfig.refresh_all_memos_url
        : 'index.php?action=refresh_all_memos';
    const callTimeLogSession = typeof payload.call_time_log_session === 'string'
        ? payload.call_time_log_session.trim()
        : '';
    const memoFetchInFlight = new Map();
    let monthScanState = asyncLoadConfig.month_scan && typeof asyncLoadConfig.month_scan === 'object'
        ? asyncLoadConfig.month_scan
        : { consecutive_empty: 0, stop_before_month: null, months: {} };
    let nextHistoryMonth = typeof asyncLoadConfig.next_month === 'string' ? asyncLoadConfig.next_month : null;
    let historyLoadRunning = false;
    let rowAnimationObserver = null;
    const cumulativeProjectTotals = {};

    for (const row of rows)
    {
        const rowKey = String(row.Row_Key || '').trim();
        if (rowKey !== '')
        {
            rowsByKey.set(rowKey, row);
            const normalizedJobNo = String(row.Job_No || '').trim().toLowerCase();
            if (normalizedJobNo !== '')
            {
                cumulativeProjectTotals[normalizedJobNo] = {
                    costs: Number(row.Project_Actual_Costs || 0),
                    revenue: Number(row.Project_Total_Revenue || 0)
                };
            }
        }
    }

    const pendingRowKeys = Array.isArray(payload.pending_row_keys) ? payload.pending_row_keys : [];
    const pendingCachedRowKeys = new Set();
    for (const pendingKey of pendingRowKeys)
    {
        const normalizedPendingKey = String(pendingKey || '').trim();
        if (normalizedPendingKey !== '')
        {
            pendingCachedRowKeys.add(normalizedPendingKey);
            rowLoadStates.set(normalizedPendingKey, 'loading');
        }
    }
    const exportColumns = buildExportColumns('table');
    const defaultSortState = {
        key: 'Job_No',
        direction: 'asc'
    };
    const sortState = {
        key: defaultSortState.key,
        direction: defaultSortState.direction
    };
    const amountColumnKeys = new Set([]);
    const numericSortKeys = new Set(['Actual_Costs', 'Total_Revenue', 'Actual_Total', 'Project_Actual_Costs', 'Project_Total_Revenue', 'Project_Total']);
    const compactColumnKeys = new Set(['Actual_Costs', 'Total_Revenue', 'Actual_Total', 'Project_Actual_Costs', 'Project_Total_Revenue', 'Project_Total', 'Cost_Center', 'Status', 'Document_Status']);
    const projectFinancialColumnKeys = new Set(['Project_Actual_Costs', 'Project_Total_Revenue', 'Project_Total']);
    const invoiceAmountTooltip = 'Factuur gevonden, kosten en opbrengst uit de factuur gelezen.';
    const workorderAmountModalMessage = 'Deze bedragen zijn overgenomen uit de werkorder. Controleer ze extra zorgvuldig; zonder gekoppelde factuur kunnen ze afwijken van de uiteindelijke factuur.';
    const invoiceAmountModalMessage = 'Deze bedragen komen direct uit de gekoppelde factuur en gelden als de meest betrouwbare bron.';
    const noneCostCenterValue = '__none__';
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
    let pageLoaderProgressTimerId = 0;
    let pageLoaderProgressRequestToken = '';
    let pageLoaderProgressFetchToken = 0;
    let pageLoaderShakeTimerId = 0;
    let pageLoaderShakePercent = 0;
    let pageLoaderElementShakeTimerId = 0;
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
    const layoutStyleSelect = document.getElementById('layoutStyleSelect');
    const keepProjectWorkordersTogetherInput = document.getElementById('keepProjectWorkordersTogether');
    const keepProjectWorkordersTogetherOption = keepProjectWorkordersTogetherInput
        ? keepProjectWorkordersTogetherInput.closest('.memo-menu-option')
        : null;
    const memoMenuInputs = memoMenuPanel
        ? Array.from(memoMenuPanel.querySelectorAll('input[data-memo-key]'))
        : [];

    if (error)
    {
        app.innerHTML = '<div class="error">Fout bij ophalen van OData: ' + escapeHtml(error) + '</div>';
        hidePageLoader();
        return;
    }

    renderCacheAgeBanner();

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

    if (loadedCostCenter !== '')
    {
        const costCenterNote = document.createElement('div');
        costCenterNote.className = 'summary-note';
        costCenterNote.textContent = 'Kostenplaats: ' + loadedCostCenter;
        summaryRow.appendChild(costCenterNote);
    }

    if (asyncLoadConfig.enabled || loadStatsFromCache > 0 || loadStatsUpdatedFromBc > 0)
    {
        loadStatsNote = document.createElement('div');
        loadStatsNote.className = 'summary-note';
        loadStatsNote.id = 'loadStatsNote';
        renderLoadStatsNote();
        summaryRow.appendChild(loadStatsNote);
    }

    const historyLoadNote = document.createElement('div');
    historyLoadNote.className = 'summary-note';
    historyLoadNote.id = 'historyLoadNote';
    historyLoadNote.style.display = 'none';
    summaryRow.appendChild(historyLoadNote);

    const statusHint = document.createElement('div');
    statusHint.className = 'status-filter-hint';
    statusHint.textContent = 'Tip: dubbel-klik op een filter om alleen die status weer te geven';
    let tableScrollWrap = null;

    const exportButton = document.createElement('button');
    exportButton.type = 'button';
    exportButton.className = 'export-btn';
    exportButton.textContent = 'Export';
    exportButton.addEventListener('click', function ()
    {
        exportButton.disabled = true;
        exportVisibleRowsToXlsx()
            .catch(function (error)
            {
                console.error(error);
                if (demeterModal)
                {
                    demeterModal.alert({
                        title: 'Export mislukt',
                        message: 'Het exporteren naar Excel is mislukt.'
                    });
                }
            })
            .finally(function ()
            {
                exportButton.disabled = false;
            });
    });
    summaryRow.appendChild(exportButton);
    app.appendChild(summaryRow);

    const statusFilterBar = document.createElement('div');
    statusFilterBar.className = 'status-filter-bar';
    app.appendChild(statusFilterBar);
    renderStatusButtons();

    if (rows.length === 0 && !asyncLoadConfig.enabled)
    {
        const empty = document.createElement('div');
        empty.className = 'empty';
        empty.textContent = loadedCostCenter !== ''
            ? 'Geen cachegegevens voor deze kostenplaats. Gebruik Ververs Nu om gegevens op te halen en naar cache te schrijven.'
            : 'Kies een bedrijf en kostenplaats om gecachte gegevens te bekijken.';
        app.appendChild(empty);
        hidePageLoader();
        return;
    }

    const table = document.createElement('table');
    table.className = 'workorders-table';
    tableScrollWrap = document.createElement('div');
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
    initializeTableScrollWrapAutoHeight();

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
    setupRowAnimationObserver();
    renderTableHeader();
    renderHeader();
    renderRows();
    updateSummaryCount();
    syncTableScrollWrapMaxHeight();
    hidePageLoader();
    if (asyncLoadConfig.enabled)
    {
        startIncrementalMonthLoading()
            .then(function ()
            {
                return finalizeRefreshAfterLoad();
            })
            .catch(function (refreshError)
            {
                console.error(refreshError);
            });
    }

    function renderCacheAgeBanner ()
    {
        if (loadedCostCenter === '')
        {
            return;
        }

        const banner = document.createElement('div');
        banner.className = 'cache-age-banner';

        const ageHours = Number(cacheMeta.age_hours);
        let ageText = 'Onbekend';
        if (Number.isFinite(ageHours))
        {
            if (ageHours < 1)
            {
                ageText = 'minder dan 1 uur';
            }
            else
            {
                ageText = ageHours.toFixed(1).replace('.', ',') + ' uur';
            }
        }
        else if (cacheMeta.has_data === false)
        {
            ageText = 'geen cache';
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Huidige gegevens zijn ' + ageText + ' oud.';
        button.addEventListener('click', openNightlyStatsModal);
        banner.appendChild(button);
        app.appendChild(banner);
    }

    function openNightlyStatsModal ()
    {
        let backdrop = document.getElementById('nightlyStatsModalBackdrop');
        if (!backdrop)
        {
            backdrop = document.createElement('div');
            backdrop.id = 'nightlyStatsModalBackdrop';
            backdrop.className = 'nightly-stats-modal-backdrop';
            backdrop.innerHTML = [
                '<div class="nightly-stats-modal" role="dialog" aria-modal="true" aria-labelledby="nightlyStatsModalTitle">',
                '<button type="button" class="nightly-stats-close" id="nightlyStatsModalClose">Sluiten</button>',
                '<h2 id="nightlyStatsModalTitle">Nightly laadtijden</h2>',
                '<div id="nightlyStatsModalBody"></div>',
                '</div>'
            ].join('');
            document.body.appendChild(backdrop);

            backdrop.addEventListener('click', function (event)
            {
                if (event.target === backdrop)
                {
                    backdrop.classList.remove('is-open');
                }
            });

            const closeButton = backdrop.querySelector('#nightlyStatsModalClose');
            if (closeButton)
            {
                closeButton.addEventListener('click', function ()
                {
                    backdrop.classList.remove('is-open');
                });
            }
        }

        const body = backdrop.querySelector('#nightlyStatsModalBody');
        if (body)
        {
            body.innerHTML = buildNightlyStatsTableHtml(nightlyStats);
        }

        backdrop.classList.add('is-open');
    }

    function buildNightlyStatsTableHtml (stats)
    {
        const startedAt = stats && stats.last_run_started_at ? String(stats.last_run_started_at) : '';
        const finishedAt = stats && stats.last_run_finished_at ? String(stats.last_run_finished_at) : '';
        const intro = '<p>Laatste nightly: '
            + escapeHtml(startedAt !== '' ? startedAt : 'onbekend')
            + (finishedAt !== '' ? (' → ' + escapeHtml(finishedAt)) : '')
            + '</p>';

        const companies = stats && typeof stats.companies === 'object' && stats.companies !== null
            ? stats.companies
            : {};
        const companyNames = Object.keys(companies).sort(function (left, right)
        {
            return left.localeCompare(right, 'nl', { sensitivity: 'base' });
        });

        if (companyNames.length === 0)
        {
            return intro + '<p>Nog geen nightly-statistieken beschikbaar.</p>';
        }

        const rowsHtml = [];
        for (const companyName of companyNames)
        {
            const companyEntry = companies[companyName];
            const costCenters = companyEntry && typeof companyEntry.cost_centers === 'object' && companyEntry.cost_centers !== null
                ? companyEntry.cost_centers
                : {};
            const costCenterCodes = Object.keys(costCenters).sort(function (left, right)
            {
                return left.localeCompare(right, 'nl', { numeric: true, sensitivity: 'base' });
            });

            if (costCenterCodes.length === 0)
            {
                const companyError = companyEntry && companyEntry.error ? String(companyEntry.error) : 'Geen kostenplaatsen';
                rowsHtml.push('<tr><td>' + escapeHtml(companyName) + '</td><td colspan="3">' + escapeHtml(companyError) + '</td></tr>');
                continue;
            }

            for (const costCenterCode of costCenterCodes)
            {
                const entry = costCenters[costCenterCode];
                const durationSeconds = entry && entry.duration_seconds !== undefined ? Number(entry.duration_seconds) : null;
                const status = entry && entry.status ? String(entry.status) : 'onbekend';
                const durationText = Number.isFinite(durationSeconds)
                    ? (durationSeconds.toFixed(1).replace('.', ',') + ' s')
                    : '—';
                const detailParts = [];
                if (entry && entry.weeks_processed !== undefined)
                {
                    detailParts.push(String(entry.weeks_processed) + ' weken');
                }
                if (entry && entry.memos_refreshed !== undefined)
                {
                    detailParts.push(String(entry.memos_refreshed) + ' memo\'s');
                }
                if (entry && entry.error)
                {
                    detailParts.push(String(entry.error));
                }

                rowsHtml.push([
                    '<tr>',
                    '<td>' + escapeHtml(companyName) + '</td>',
                    '<td>' + escapeHtml(costCenterCode) + '</td>',
                    '<td>' + escapeHtml(durationText) + '</td>',
                    '<td>' + escapeHtml(status + (detailParts.length > 0 ? (' — ' + detailParts.join(', ')) : '')) + '</td>',
                    '</tr>'
                ].join(''));
            }
        }

        return intro + [
            '<table class="nightly-stats-table">',
            '<thead><tr><th>Bedrijf</th><th>Kostenplaats</th><th>Duur</th><th>Status</th></tr></thead>',
            '<tbody>' + rowsHtml.join('') + '</tbody>',
            '</table>'
        ].join('');
    }

    async function finalizeRefreshAfterLoad ()
    {
        const company = String(payload.company || '').trim();
        const costCenter = loadedCostCenter;
        if (company === '' || costCenter === '')
        {
            return;
        }

        updateHistoryLoadNote('Memo\'s ophalen...');
        const params = new URLSearchParams();
        params.set('action', 'refresh_all_memos');
        params.set('company', company);
        params.set('cost_center', costCenter);
        const loadToken = getPendingLoadProgressToken();
        if (loadToken !== '')
        {
            params.set('load_token', loadToken);
        }
        if (refreshProgressTotalSteps > 0)
        {
            params.set('progress_total_steps', String(refreshProgressTotalSteps));
        }

        const response = await fetch('index.php?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        });
        const body = await response.json();
        if (!response.ok || !body || body.ok !== true)
        {
            throw new Error(body && body.error ? body.error : ('HTTP ' + response.status));
        }

        const redirectParams = new URLSearchParams(window.location.search);
        redirectParams.delete('refresh_now');
        redirectParams.delete('call_time_log_session');
        stopPageLoaderProgress();
        hidePageLoader();
        updateHistoryLoadNote('');

        const redirectUrl = 'index.php?' + redirectParams.toString();
        window.__demeterSuppressUnloadLoader = true;
        window.location.replace(redirectUrl);
    }

    function initializePageLoaderHandlers ()
    {
        if (controlsForm)
        {
            controlsForm.addEventListener('submit', function (event)
            {
                const submitter = event.submitter;
                const isRefreshRequest = submitter && submitter.name === 'refresh_now';

                if (isRefreshRequest)
                {
                    if (costCenterSelect && String(costCenterSelect.value || '').trim() === '')
                    {
                        event.preventDefault();
                        costCenterSelect.focus();
                        return;
                    }

                    if (controlsForm.dataset.refreshConfirmed === '1')
                    {
                        delete controlsForm.dataset.refreshConfirmed;
                        return;
                    }

                    event.preventDefault();

                    if (!demeterModal)
                    {
                        return;
                    }

                    demeterModal.confirm({
                        title: 'Ververs Nu',
                        message: 'Ververs Nu haalt alle gegevens opnieuw op uit Business Central en schrijft ze naar cache. Dit kan enkele minuten duren. Doorgaan?',
                        confirmText: 'Doorgaan',
                        cancelText: 'Annuleren'
                    }).then(function (confirmed)
                    {
                        if (!confirmed || !controlsForm)
                        {
                            return;
                        }

                        controlsForm.dataset.refreshConfirmed = '1';
                        window.__demeterSuppressUnloadLoader = true;
                        if (typeof controlsForm.requestSubmit === 'function' && refreshNowButton)
                        {
                            controlsForm.requestSubmit(refreshNowButton);
                        }
                        else if (refreshNowButton)
                        {
                            refreshNowButton.click();
                        }
                        else
                        {
                            controlsForm.submit();
                        }
                    });

                    return;
                }

                if (submitter && submitter.name === 'refresh_now')
                {
                    return;
                }
            });
        }

        const refreshNowButton = document.getElementById('refreshNowButton');
        if (refreshNowButton)
        {
            refreshNowButton.addEventListener('click', function (event)
            {
                if (costCenterSelect && String(costCenterSelect.value || '').trim() === '')
                {
                    event.preventDefault();
                    costCenterSelect.focus();
                }
            });
        }

        const reloadTriggerInputs = [companySelect, invoiceFilterSelect, costCenterSelect].filter(Boolean);
        for (const inputElement of reloadTriggerInputs)
        {
            inputElement.addEventListener('change', function ()
            {
                if (inputElement === invoiceFilterSelect)
                {
                    showPageLoader('Filter toepassen...');
                    return;
                }

                if (inputElement === companySelect)
                {
                    return;
                }

                if (inputElement === costCenterSelect)
                {
                    return;
                }

                showPageLoader('Gegevens laden...');
            });
        }

        window.addEventListener('beforeunload', function ()
        {
            if (window.__demeterSuppressUnloadLoader === true || asyncLoadConfig.enabled)
            {
                return;
            }

            startPageLoaderProgress('Gegevens laden...');
        });
    }

    function getRefreshWeekProgressTotal (monthScan)
    {
        const resolved = resolveHistoryWeeksTotal(monthScan);
        if (resolved && resolved > 0)
        {
            return resolved;
        }

        if (historyWeeksTotal && historyWeeksTotal > 0)
        {
            return historyWeeksTotal;
        }

        return monthScanEmptyStopCount;
    }

    function getPendingLoadProgressToken ()
    {
        if (loadProgressTokenInput)
        {
            const inputToken = String(loadProgressTokenInput.value || '').trim();
            if (inputToken !== '')
            {
                return inputToken;
            }
        }

        return typeof asyncLoadConfig.load_progress_token === 'string'
            ? asyncLoadConfig.load_progress_token.trim()
            : '';
    }

    function stopPageLoaderProgress ()
    {
        if (pageLoaderProgressTimerId !== 0)
        {
            window.clearInterval(pageLoaderProgressTimerId);
            pageLoaderProgressTimerId = 0;
        }

        pageLoaderProgressRequestToken = '';
        pageLoaderShakePercent = 0;
        stopPageLoaderShake();
        stopPageLoaderElementShake();
    }

    function clampLoaderValue (value, min, max)
    {
        return Math.max(min, Math.min(max, value));
    }

    function loaderColorByPercent (percent)
    {
        const safePercent = clampLoaderValue(Number(percent || 0), 0, 100);
        if (safePercent < 80)
        {
            return 'hsl(215, 78%, 42%)';
        }

        const ratio = (safePercent - 80) / 20;
        const hue = 215 * (1 - ratio);
        return 'hsl(' + hue + ', 78%, 46%)';
    }

    function loaderShakeIntensity (percent)
    {
        const safePercent = clampLoaderValue(Number(percent || 0), 0, 100);
        if (safePercent < 80)
        {
            return 0;
        }

        return (safePercent - 80) / 20;
    }

    function loaderElementShakeIntensity (percent)
    {
        const safePercent = clampLoaderValue(Number(percent || 0), 0, 100);
        if (safePercent < 95)
        {
            return 0;
        }

        return (safePercent - 95) / 3;
    }

    function loaderSpinnerDurationSeconds (percent)
    {
        const intensity = loaderElementShakeIntensity(percent);
        const baseSeconds = 0.8;
        const speedFactor = 1 + (intensity * 9);
        return baseSeconds / speedFactor;
    }

    function resetPageLoaderElementShake ()
    {
        for (const element of pageLoaderWobbleTargets)
        {
            if (!element)
            {
                continue;
            }

            element.style.transform = 'translate3d(0, 0, 0) rotate(0deg)';
        }
    }

    function stopPageLoaderShake ()
    {
        if (pageLoaderShakeTimerId !== 0)
        {
            window.clearInterval(pageLoaderShakeTimerId);
            pageLoaderShakeTimerId = 0;
        }

        if (pageLoaderContent)
        {
            pageLoaderContent.style.transform = 'translate3d(0, 0, 0) rotate(0deg)';
        }
    }

    function stopPageLoaderElementShake ()
    {
        if (pageLoaderElementShakeTimerId !== 0)
        {
            window.clearInterval(pageLoaderElementShakeTimerId);
            pageLoaderElementShakeTimerId = 0;
        }

        resetPageLoaderElementShake();
    }

    function startPageLoaderShake ()
    {
        if (!pageLoaderContent)
        {
            return;
        }

        if (pageLoaderShakeTimerId !== 0)
        {
            return;
        }

        pageLoaderShakeTimerId = window.setInterval(function ()
        {
            const intensity = loaderShakeIntensity(pageLoaderShakePercent);
            if (intensity <= 0)
            {
                pageLoaderContent.style.transform = 'translate3d(0, 0, 0) rotate(0deg)';
                return;
            }

            const amplitude = 0.35 + (intensity * 8);
            const rotation = (Math.random() * 2 - 1) * (0.2 + intensity * 1.8);
            const x = (Math.random() * 2 - 1) * amplitude;
            const y = (Math.random() * 2 - 1) * (amplitude * 0.6);
            pageLoaderContent.style.transform = 'translate3d(' + x.toFixed(2) + 'px, ' + y.toFixed(2) + 'px, 0) rotate(' + rotation.toFixed(3) + 'deg)';
        }, 45);
    }

    function startPageLoaderElementShake ()
    {
        if (pageLoaderWobbleTargets.length === 0)
        {
            return;
        }

        if (pageLoaderElementShakeTimerId !== 0)
        {
            return;
        }

        pageLoaderElementShakeTimerId = window.setInterval(function ()
        {
            const intensity = loaderElementShakeIntensity(pageLoaderShakePercent);
            if (intensity <= 0)
            {
                resetPageLoaderElementShake();
                return;
            }

            for (const element of pageLoaderWobbleTargets)
            {
                if (!element)
                {
                    continue;
                }

                const amplitude = 0.2 + (intensity * 3.8);
                const rotation = (Math.random() * 2 - 1) * (0.15 + intensity * 2.2);
                const x = (Math.random() * 2 - 1) * amplitude;
                const y = (Math.random() * 2 - 1) * (amplitude * 0.7);
                element.style.transform = 'translate3d(' + x.toFixed(2) + 'px, ' + y.toFixed(2) + 'px, 0) rotate(' + rotation.toFixed(3) + 'deg)';
            }
        }, 38);
    }

    function applyPageLoaderProgressVisuals (percent, callLabel)
    {
        const safePercent = clampLoaderValue(Number(percent || 0), 0, 100);
        const color = loaderColorByPercent(safePercent);

        if (pageLoaderPercent)
        {
            pageLoaderPercent.textContent = Math.round(safePercent) + '%';
            pageLoaderPercent.style.color = color;
        }

        if (pageLoaderBarFill)
        {
            pageLoaderBarFill.style.width = safePercent + '%';
            pageLoaderBarFill.style.backgroundColor = color;
        }

        if (pageLoaderCall)
        {
            const callText = String(callLabel || '').trim();
            pageLoaderCall.textContent = callText === '' ? '' : ('Huidige OData-call: ' + callText);
        }

        if (pageLoaderSpinner)
        {
            pageLoaderSpinner.style.animationDuration = loaderSpinnerDurationSeconds(safePercent).toFixed(3) + 's';
        }

        pageLoaderShakePercent = safePercent;
        if (loaderShakeIntensity(safePercent) > 0)
        {
            startPageLoaderShake();
        }
        else
        {
            stopPageLoaderShake();
        }

        if (loaderElementShakeIntensity(safePercent) > 0)
        {
            startPageLoaderElementShake();
        }
        else
        {
            stopPageLoaderElementShake();
        }
    }

    async function updatePageLoaderProgress ()
    {
        const token = pageLoaderProgressRequestToken;
        if (token === '')
        {
            return;
        }

        const fetchToken = ++pageLoaderProgressFetchToken;

        try
        {
            const requestUrl = new URL(loadProgressStatusUrl, window.location.href);
            requestUrl.searchParams.set('token', token);
            requestUrl.searchParams.set('_t', String(Date.now()));

            const response = await fetch(requestUrl.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok || fetchToken !== pageLoaderProgressFetchToken)
            {
                return;
            }

            const progress = await response.json();
            if (!progress || typeof progress !== 'object')
            {
                return;
            }

            const totalMonths = Number(progress.total_months || 0);
            const currentMonthIndex = Number(progress.current_month_index || 0);
            const status = String(progress.status || 'idle');
            const currentCallLabel = String(progress.current_call_label || '').trim();
            let text = String(progress.message || '').trim();
            let percent = 0;

            if (totalMonths > 0)
            {
                percent = Math.max(0, Math.min(100, Math.round((currentMonthIndex / totalMonths) * 100)));
                if (text === '')
                {
                    text = 'Stap ' + currentMonthIndex + ' van ' + totalMonths;
                }
                text += ' (' + percent + '%)';
            }
            else if (text === '')
            {
                text = 'Gegevens laden...';
            }

            if (status === 'completed')
            {
                percent = 100;
                if (text === '')
                {
                    text = 'Laden afgerond';
                }
            }

            if (status === 'error' && String(progress.error || '').trim() !== '')
            {
                text = String(progress.error || '').trim();
            }

            applyLoadProgressToUi(text, percent, currentCallLabel);
        }
        catch (error)
        {
            console.warn('Laadprogress ophalen mislukt', error);
        }
    }

    function applyLoadProgressToUi (text, percent, currentCallLabel)
    {
        if (asyncLoadConfig.enabled)
        {
            let noteText = text;
            const callText = String(currentCallLabel || '').trim();
            if (callText !== '')
            {
                noteText += ' — OData: ' + callText;
            }

            updateHistoryLoadNote(noteText);
            return;
        }

        showPageLoader(text);
        applyPageLoaderProgressVisuals(percent, currentCallLabel);
    }

    function startBackgroundLoadProgressPolling ()
    {
        const token = getPendingLoadProgressToken();
        if (token === '')
        {
            return;
        }

        if (pageLoaderProgressRequestToken === token && pageLoaderProgressTimerId !== 0)
        {
            return;
        }

        stopPageLoaderProgress();
        pageLoaderProgressRequestToken = token;
        updatePageLoaderProgress();
        pageLoaderProgressTimerId = window.setInterval(updatePageLoaderProgress, 700);
    }

    function startPageLoaderProgress (defaultText)
    {
        if (asyncLoadConfig.enabled)
        {
            startBackgroundLoadProgressPolling();
            return;
        }

        showPageLoader(defaultText);
        applyPageLoaderProgressVisuals(0, '');

        const token = getPendingLoadProgressToken();
        if (token === '')
        {
            return;
        }

        if (pageLoaderProgressRequestToken === token && pageLoaderProgressTimerId !== 0)
        {
            return;
        }

        stopPageLoaderProgress();
        pageLoaderProgressRequestToken = token;
        updatePageLoaderProgress();
        pageLoaderProgressTimerId = window.setInterval(updatePageLoaderProgress, 700);
    }

    function initializeTableScrollWrapAutoHeight ()
    {
        let rafId = 0;

        const scheduleSync = function ()
        {
            if (rafId !== 0)
            {
                cancelAnimationFrame(rafId);
            }

            rafId = requestAnimationFrame(function ()
            {
                rafId = 0;
                syncTableScrollWrapMaxHeight();
            });
        };

        window.addEventListener('resize', scheduleSync, { passive: true });
        window.addEventListener('orientationchange', scheduleSync, { passive: true });
        window.addEventListener('scroll', scheduleSync, { passive: true });

        if (window.visualViewport)
        {
            window.visualViewport.addEventListener('resize', scheduleSync, { passive: true });
            window.visualViewport.addEventListener('scroll', scheduleSync, { passive: true });
        }

        scheduleSync();
    }

    function syncTableScrollWrapMaxHeight ()
    {
        if (!tableScrollWrap)
        {
            return;
        }

        const viewportHeight = Math.floor(
            (window.visualViewport && window.visualViewport.height)
            || window.innerHeight
            || document.documentElement.clientHeight
            || 0
        );

        if (viewportHeight <= 0)
        {
            return;
        }

        const rect = tableScrollWrap.getBoundingClientRect();
        const bottomViewportPadding = 16;
        const availableHeight = Math.floor(viewportHeight - rect.top - bottomViewportPadding);
        const targetHeight = (availableHeight > 0 ? availableHeight : 120) - 20;
        tableScrollWrap.style.maxHeight = targetHeight + 'px';
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
        let latestClientX = 0;
        let latestClientY = 0;
        let startScrollLeft = 0;
        let startScrollTop = 0;
        let startWindowScrollY = 0;
        let canScrollVertically = false;
        let dragRafId = 0;

        const interactiveSelector = 'button, input, select, textarea, a, [role="button"], .notes-btn, .memo-cell-clickable, .invoice-id-clickable, .amount-info-clickable, .project-posten-link';

        function endDrag ()
        {
            if (!isPointerDown)
            {
                return;
            }

            if (dragRafId !== 0)
            {
                cancelAnimationFrame(dragRafId);
                dragRafId = 0;
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

        function applyDragPosition ()
        {
            dragRafId = 0;
            if (!isPointerDown)
            {
                return;
            }

            const deltaX = latestClientX - startClientX;
            const deltaY = latestClientY - startClientY;

            scrollElement.scrollLeft = startScrollLeft - deltaX;

            if (canScrollVertically)
            {
                scrollElement.scrollTop = startScrollTop - deltaY;
            }
            else
            {
                window.scrollTo(window.scrollX, startWindowScrollY - deltaY);
            }
        }

        function scheduleDragPositionUpdate ()
        {
            if (dragRafId !== 0)
            {
                return;
            }

            dragRafId = requestAnimationFrame(applyDragPosition);
        }

        scrollElement.addEventListener('mousedown', function (event)
        {
            if (event.button !== 0)
            {
                return;
            }

            const target = event.target;
            if (target instanceof Element && target.closest('thead'))
            {
                return;
            }

            if (target instanceof Element && target.closest(interactiveSelector))
            {
                return;
            }

            isPointerDown = true;
            hasDragged = false;
            startClientX = event.clientX;
            startClientY = event.clientY;
            latestClientX = event.clientX;
            latestClientY = event.clientY;
            startScrollLeft = scrollElement.scrollLeft;
            startScrollTop = scrollElement.scrollTop;
            startWindowScrollY = window.scrollY || window.pageYOffset || 0;
            canScrollVertically = scrollElement.scrollHeight > scrollElement.clientHeight;

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

            latestClientX = event.clientX;
            latestClientY = event.clientY;
            const deltaX = latestClientX - startClientX;
            const deltaY = latestClientY - startClientY;
            if (Math.abs(deltaX) > 3 || Math.abs(deltaY) > 3)
            {
                hasDragged = true;
            }

            scheduleDragPositionUpdate();
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

        if (asyncLoadConfig.enabled)
        {
            if (typeof text === 'string' && text.trim() !== '')
            {
                updateHistoryLoadNote(text);
            }

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

        stopPageLoaderProgress();
        applyPageLoaderProgressVisuals(0, '');
        pageLoader.classList.remove('is-visible');
    }

    function buildTableColumns ()
    {
        const list = buildBaseColumnsForLayout(layoutStyle);
        const hasGroupedMemoFields = getGroupedMemoFields().length > 0;

        for (const field of memoFields)
        {
            if (selectedMemoColumnKeys.has(field.key))
            {
                list.push({ key: field.key, label: field.label, isMemoField: true });
            }
        }

        if (showInvoiced && normalizeLayoutStyle(layoutStyle) === 'table')
        {
            list.push({ key: 'Invoice_Id', label: 'Factuur ID' });
        }

        if (hasGroupedMemoFields)
        {
            list.push({ key: 'Notes', label: 'Notities' });
        }

        return list;
    }

    function buildExportColumns (mode)
    {
        const layoutMode = normalizeLayoutStyle(mode);
        const list = buildBaseColumnsForLayout(layoutMode);

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

    function buildBaseColumnsForLayout (mode)
    {
        const layoutMode = normalizeLayoutStyle(mode);
        const list = [];

        for (const column of baseColumns)
        {
            list.push(column);

            if (layoutMode !== 'table')
            {
                continue;
            }

            if (column.key === 'Contract_No')
            {
                list.push({ key: 'Job_No', label: 'Project Nr.' });
            }

            if (column.key === 'Actual_Total')
            {
                list.push({ key: 'Project_Actual_Costs', label: 'Kosten project' });
                list.push({ key: 'Project_Total_Revenue', label: 'Opbrengst project' });
                list.push({ key: 'Project_Total', label: 'Resultaat project' });
            }
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
        syncLayoutStyleInput();
        syncKeepProjectWorkordersTogetherInput();

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

                applyPreferencesSelection();
                saveUserSettings();
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

        if (layoutStyleSelect)
        {
            layoutStyleSelect.value = layoutStyle;
            layoutStyleSelect.addEventListener('change', function ()
            {
                layoutStyle = normalizeLayoutStyle(layoutStyleSelect.value);
                applyPreferencesSelection();
                saveUserSettings();
            });
        }

        if (keepProjectWorkordersTogetherInput)
        {
            keepProjectWorkordersTogetherInput.checked = keepProjectWorkordersTogether;
            keepProjectWorkordersTogetherInput.addEventListener('change', function ()
            {
                keepProjectWorkordersTogether = !!keepProjectWorkordersTogetherInput.checked;
                applyPreferencesSelection();
                saveUserSettings();
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

        applyPreferencesSelection();
        saveUserSettings();
    }

    function syncMemoMenuInputs ()
    {
        for (const input of memoMenuInputs)
        {
            const memoKey = String(input.dataset.memoKey || '');
            input.checked = selectedMemoColumnKeys.has(memoKey);
        }
    }

    function applyPreferencesSelection ()
    {
        columns = buildTableColumns();

        if (!isSortKeySupported(sortState.key))
        {
            resetSortState();
        }

        syncMemoMenuInputs();
        syncLayoutStyleInput();
        syncKeepProjectWorkordersTogetherInput();
        renderTableHeader();
        renderHeader();
        renderRows();
    }

    function syncLayoutStyleInput ()
    {
        if (!layoutStyleSelect)
        {
            return;
        }

        layoutStyleSelect.value = layoutStyle;
    }

    function syncKeepProjectWorkordersTogetherInput ()
    {
        if (!keepProjectWorkordersTogetherInput)
        {
            return;
        }

        keepProjectWorkordersTogetherInput.checked = keepProjectWorkordersTogether;

        const shouldShow = layoutStyle !== 'projectgroups';
        if (keepProjectWorkordersTogetherOption)
        {
            keepProjectWorkordersTogetherOption.style.display = shouldShow ? '' : 'none';
        }

        keepProjectWorkordersTogetherInput.disabled = !shouldShow;
    }

    function saveUserSettings ()
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
            body: JSON.stringify({
                memo_columns: memoColumns,
                layout_style: layoutStyle,
                keep_project_workorders_together: keepProjectWorkordersTogether,
                cost_center: costCenterSelect ? String(costCenterSelect.value || '').trim() : ''
            })
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

            if (column.key === 'Component_No')
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
            th.addEventListener('mousedown', function (event)
            {
                event.stopPropagation();
            });
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

                const noneOption = document.createElement('option');
                noneOption.value = noneCostCenterValue;
                noneOption.textContent = 'Geen';
                filterSelect.appendChild(noneOption);

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
                    updateSummaryCount();
                    renderStatusButtons();
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
        renderStatusButtons();
    }

    function renderHeader ()
    {
        const headerSearchTotals = getHeaderSearchTotals();

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

            if (!headerSearchTotals)
            {
                continue;
            }

            let totalValue = null;
            let isResultTotal = false;

            if (key === 'Actual_Costs')
            {
                totalValue = headerSearchTotals.actualCosts;
            }
            else if (key === 'Total_Revenue')
            {
                totalValue = headerSearchTotals.totalRevenue;
            }
            else if (key === 'Actual_Total')
            {
                totalValue = headerSearchTotals.actualTotal;
                isResultTotal = true;
            }

            if (totalValue === null)
            {
                continue;
            }

            const totalLine = document.createElement('span');
            totalLine.className = 'column-header-total';

            if (totalValue > 0)
            {
                totalLine.classList.add('amount-positive');
            }
            else if (totalValue < 0)
            {
                totalLine.classList.add('amount-negative');
            }

            totalLine.textContent = isResultTotal
                ? formatSignedCurrencyOrZero(totalValue)
                : formatCurrencyOrZero(totalValue);

            label.appendChild(totalLine);
        }
    }

    function getHeaderSearchTotals ()
    {
        if (appliedSearchText === '')
        {
            return null;
        }

        const visibleRows = layoutStyle === 'projectgroups'
            ? getVisibleGlobalRowsForProjectGroups()
            : getVisibleSortedRows();

        let actualCosts = 0;
        let totalRevenue = 0;
        let actualTotal = 0;

        for (const row of visibleRows)
        {
            actualCosts += Number(getColumnValueForSorting(row, 'Actual_Costs') || 0);
            totalRevenue += Number(getColumnValueForSorting(row, 'Total_Revenue') || 0);
            actualTotal += Number(getColumnValueForSorting(row, 'Actual_Total') || 0);
        }

        return {
            actualCosts: actualCosts,
            totalRevenue: totalRevenue,
            actualTotal: actualTotal,
        };
    }

    function renderRows ()
    {
        updateSummaryCount();
        tbody.innerHTML = '';
        if (layoutStyle === 'projectgroups')
        {
            const projectGroups = getVisibleProjectGroups();
            let visibleRowCount = 0;

            for (const group of projectGroups)
            {
                visibleRowCount += group.rows.length;
                appendProjectGroupRows(group.rows, group.projectKey);
            }

            noSearchResults.style.display = visibleRowCount === 0 ? '' : 'none';
            syncRowDomMap();
            return;
        }

        if (keepProjectWorkordersTogether)
        {
            const groupedRows = getVisibleGlobalRows();
            const groups = buildProjectGroupsFromGlobalRows(groupedRows);
            let visibleRowCount = 0;
            let isFirstGroup = true;

            for (const group of groups)
            {
                const groupSize = group.rows.length;
                visibleRowCount += groupSize;
                let isFirstInGroup = true;

                for (const row of group.rows)
                {
                    const projectCellConfig = groupSize > 1
                        ? { rowspan: groupSize, isFirst: isFirstInGroup }
                        : null;
                    const tr = renderWorkorderRow(row, projectCellConfig);

                    if (!isFirstGroup && isFirstInGroup)
                    {
                        tr.classList.add('project-break-row');
                    }

                    tbody.appendChild(tr);
                    isFirstInGroup = false;
                }

                isFirstGroup = false;
            }

            noSearchResults.style.display = visibleRowCount === 0 ? '' : 'none';
            syncRowDomMap();
            return;
        }

        const visibleRows = getVisibleSortedRows();
        for (const row of visibleRows)
        {
            const tr = renderWorkorderRow(row);
            tbody.appendChild(tr);
        }

        noSearchResults.style.display = visibleRows.length === 0 ? '' : 'none';
        syncRowDomMap();
    }

    function syncRowDomMap ()
    {
        rowDomByKey.clear();
        if (!tbody)
        {
            return;
        }

        const rowElements = tbody.querySelectorAll('tr.workorder-data-row[data-row-key]');
        for (const tr of rowElements)
        {
            const key = String(tr.dataset.rowKey || '').trim();
            if (key !== '')
            {
                rowDomByKey.set(key, tr);
            }
        }
    }

    function usesIncrementalRowRendering ()
    {
        return layoutStyle === 'table' && !keepProjectWorkordersTogether;
    }

    function replaceWorkorderRowTr (rowKey, row)
    {
        const normalizedKey = String(rowKey || '').trim();
        if (normalizedKey === '')
        {
            return null;
        }

        const existingTr = rowDomByKey.get(normalizedKey);
        const newTr = renderWorkorderRow(row);
        if (existingTr && existingTr.parentNode)
        {
            existingTr.replaceWith(newTr);
        }

        rowDomByKey.set(normalizedKey, newTr);
        observeWorkorderRow(newTr, normalizedKey);

        return newTr;
    }

    function reorderVisibleRowsInDom ()
    {
        if (!tbody)
        {
            return;
        }

        const visibleRows = getVisibleSortedRows();
        const visibleKeys = new Set();

        for (const row of visibleRows)
        {
            const rowKey = String(row.Row_Key || '').trim();
            if (rowKey === '')
            {
                continue;
            }

            visibleKeys.add(rowKey);
            const tr = rowDomByKey.get(rowKey);
            if (tr)
            {
                tbody.appendChild(tr);
            }
        }

        for (const [key, tr] of rowDomByKey.entries())
        {
            if (!visibleKeys.has(key) && tr && tr.parentNode)
            {
                tr.remove();
                rowDomByKey.delete(key);
            }
        }
    }

    function applyChunkRowsToDom (monthRows)
    {
        if (!usesIncrementalRowRendering())
        {
            renderRows();
            return;
        }

        let touched = false;

        for (const monthRow of monthRows)
        {
            const rowKey = String(monthRow.Row_Key || '').trim();
            if (rowKey === '')
            {
                continue;
            }

            const row = rowsByKey.get(rowKey);
            if (!row)
            {
                continue;
            }

            touched = true;

            if (!rowDomByKey.has(rowKey))
            {
                const tr = renderWorkorderRow(row);
                rowDomByKey.set(rowKey, tr);
                observeWorkorderRow(tr, rowKey);
            }
            else
            {
                replaceWorkorderRowTr(rowKey, row);
            }
        }

        if (touched)
        {
            reorderVisibleRowsInDom();
        }

        updateSummaryCount();
        noSearchResults.style.display = getVisibleSortedRows().length === 0 ? '' : 'none';
    }

    function refreshRowLoadStatesDom (rowKeys)
    {
        if (!Array.isArray(rowKeys))
        {
            return;
        }

        for (const rowKey of rowKeys)
        {
            const normalizedKey = String(rowKey || '').trim();
            if (normalizedKey === '')
            {
                continue;
            }

            const tr = rowDomByKey.get(normalizedKey);
            if (!tr)
            {
                continue;
            }

            tr.classList.remove('is-row-loading', 'is-row-complete-flash');
            const loadState = rowLoadStates.get(normalizedKey) || 'stable';
            const showRowLoading = loadState === 'loading' && pendingCachedRowKeys.has(normalizedKey);

            if (showRowLoading)
            {
                tr.classList.add('is-row-loading');
            }
            else if (loadState === 'completing' && pendingCachedRowKeys.has(normalizedKey))
            {
                tr.classList.add('is-row-complete-flash');
            }
        }
    }

    function appendProjectGroupRows (projectRows, projectKey)
    {
        if (!Array.isArray(projectRows) || projectRows.length === 0)
        {
            return;
        }

        const summary = buildProjectGroupSummary(projectRows, projectKey);

        const headerRow = document.createElement('tr');
        headerRow.className = 'project-group-summary-row';

        const headerCell = document.createElement('td');
        headerCell.colSpan = columns.length;
        headerCell.className = 'project-group-summary-cell';

        if (summary.isWithoutProject)
        {
            headerCell.innerHTML = [
                '<div class="project-group-summary-content">',
                '<strong>' + escapeHtml(summary.text) + '</strong>',
                '</div>'
            ].join(' ');
        }
        else
        {
            const projectResult = summary.totalRevenue - summary.totalCosts;
            const projectResultClass = projectResult > 0 ? 'amount-positive' : (projectResult < 0 ? 'amount-negative' : '');
            const projectResultSpan = '<span' + (projectResultClass ? ' class="' + projectResultClass + '"' : '') + '>' + escapeHtml(formatSignedCurrency(projectResult)) + '</span>';

            const summaryParts = [
                '<div class="project-group-summary-content">',
                '<strong>Project: </strong><a href="#" class="project-posten-link project-posten-project-link">' + escapeHtml(summary.projectLabel) + '</a>',
                '<span class="project-group-summary-sep">|</span>',
                '<strong>Kosten: </strong>' + escapeHtml(formatCurrencyOrZero(summary.totalCosts)),
                '<span class="project-group-summary-sep">|</span>',
                '<strong>Opbrengst: </strong>' + escapeHtml(formatCurrencyOrZero(summary.totalRevenue)),
                '<span class="project-group-summary-sep">|</span>',
                '<strong>Resultaat: </strong>' + projectResultSpan,
                '<span class="project-group-summary-sep">|</span>',
                '<strong>Project taakregels: </strong>' + escapeHtml(String(summary.taskLineCount)),
                '<span class="project-group-summary-sep">|</span>',
                '<strong>Werkorders: </strong>' + escapeHtml(String(summary.workorderCount))
            ];

            if (summary.hasInvoiceLink)
            {
                summaryParts.push('<span class="project-group-summary-sep">|</span>');
                summaryParts.push('<strong>Facturen: </strong><a href="#" class="project-invoice-details-link invoice-id-clickable">' + escapeHtml(formatInvoiceIdPreview(summary.invoiceIds, 5)) + '</a>');
            }

            summaryParts.push('</div>');
            headerCell.innerHTML = summaryParts.join(' ');

            if (summary.hasInvoiceLink)
            {
                const detailsLink = headerCell.querySelector('.project-invoice-details-link');
                if (detailsLink)
                {
                    detailsLink.addEventListener('click', function (event)
                    {
                        event.preventDefault();
                        openInvoiceDetailsModal(summary.invoiceIds);
                    });
                }
            }

            const projectPostenLink = headerCell.querySelector('.project-posten-project-link');
            if (projectPostenLink)
            {
                projectPostenLink.addEventListener('click', function (event)
                {
                    event.preventDefault();
                    openProjectPostenModalForProject(summary.projectLabel);
                });
            }
        }

        headerRow.appendChild(headerCell);
        tbody.appendChild(headerRow);

        for (let index = 0; index < projectRows.length; index += 1)
        {
            const row = projectRows[index];
            const tr = renderWorkorderRow(row);
            tr.classList.add('project-group-row');
            if (index === projectRows.length - 1)
            {
                tr.classList.add('project-group-last-row');
            }

            tbody.appendChild(tr);
        }
    }

    function buildProjectGroupSummary (projectRows, projectKey)
    {
        const representativeRow = projectRows[0] || {};
        const totalCosts = Number(representativeRow.Project_Actual_Costs || 0);
        const totalRevenue = Number(representativeRow.Project_Total_Revenue || 0);
        const invoiceIdSet = new Set();
        for (const row of projectRows)
        {
            const rowInvoiceIds = getRowInvoiceIds(row);
            for (const invoiceId of rowInvoiceIds)
            {
                invoiceIdSet.add(invoiceId);
            }
        }

        const invoiceIds = Array.from(invoiceIdSet).sort(function (left, right)
        {
            return String(left || '').localeCompare(String(right || ''), 'nl', { numeric: true, sensitivity: 'base' });
        });

        let invoicedTotal = Number(representativeRow.Invoiced_Total || 0);
        if (invoicedTotal === 0)
        {
            for (const row of projectRows)
            {
                const candidate = Number((row && row.Invoiced_Total) || 0);
                if (candidate !== 0)
                {
                    invoicedTotal = candidate;
                    break;
                }
            }
        }
        const hasInvoiceLink = invoiceIds.length > 0;
        const taskLineKeys = new Set();
        for (const row of projectRows)
        {
            const taskNo = normalizeSortValue(row.Job_Task_No || '');
            if (taskNo !== '')
            {
                taskLineKeys.add(taskNo);
            }
        }

        const taskLineCount = taskLineKeys.size;
        const workorderCount = projectRows.length;
        const projectLabel = normalizeSortValue(projectKey);

        if (projectLabel === '')
        {
            return {
                isWithoutProject: true,
                projectLabel: '',
                totalCosts: totalCosts,
                totalRevenue: totalRevenue,
                hasInvoiceLink: hasInvoiceLink,
                invoiceIds: invoiceIds,
                invoiceIdsText: invoiceIds.join(', '),
                invoicedTotal: invoicedTotal,
                taskLineCount: taskLineCount,
                workorderCount: workorderCount,
                text: 'Werkorders zonder project: ' + String(workorderCount)
            };
        }

        return {
            isWithoutProject: false,
            projectLabel: projectLabel,
            totalCosts: totalCosts,
            totalRevenue: totalRevenue,
            hasInvoiceLink: hasInvoiceLink,
            invoiceIds: invoiceIds,
            invoiceIdsText: invoiceIds.join(', '),
            invoicedTotal: invoicedTotal,
            taskLineCount: taskLineCount,
            workorderCount: workorderCount,
            text: [
                'Project: ' + projectLabel,
                'Kosten: ' + formatCurrencyOrZero(totalCosts),
                'Opbrengst: ' + formatCurrencyOrZero(totalRevenue),
                'Resultaat: ' + formatSignedCurrency(totalRevenue - totalCosts),
                'Project taakregels: ' + String(taskLineCount),
                'Werkorders: ' + String(workorderCount)
            ].join(' | ')
        };
    }

    function renderWorkorderRow (row, projectCellConfig)
    {
        const tr = document.createElement('tr');
        const statusKey = normalizeStatus(row.Status || '');
        const rowKey = String(row.Row_Key || '').trim();
        const loadState = rowKey !== '' ? (rowLoadStates.get(rowKey) || 'stable') : 'stable';
        const showRowLoading = loadState === 'loading' && pendingCachedRowKeys.has(rowKey);

        tr.classList.add('status-' + statusKey);
        tr.classList.add('workorder-data-row');
        if (rowKey !== '')
        {
            tr.dataset.rowKey = rowKey;
        }

        if (showRowLoading)
        {
            tr.classList.add('is-row-loading');
        }
        else if (loadState === 'completing' && pendingCachedRowKeys.has(rowKey))
        {
            tr.classList.add('is-row-complete-flash');
        }

        for (const column of columns)
        {
            const isProjectFinancialColumn = projectFinancialColumnKeys.has(column.key);

            if (isProjectFinancialColumn && projectCellConfig !== null && projectCellConfig !== undefined && !projectCellConfig.isFirst)
            {
                continue;
            }

            const td = document.createElement('td');
            td.classList.add('cell-pulse-target');
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

            if (column.key === 'Component_No')
            {
                td.classList.add('col-equipment-number');
            }

            if (column.key === 'Memo_KVT_Remarks_Invoicing')
            {
                td.classList.add('col-memo-remarks');
            }

            if (isProjectFinancialColumn && projectCellConfig !== null && projectCellConfig !== undefined && projectCellConfig.isFirst && projectCellConfig.rowspan > 1)
            {
                td.rowSpan = projectCellConfig.rowspan;
                td.style.verticalAlign = 'middle';
                td.classList.add('project-finance-merged');
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
                const memosCached = rowMemosAreCached(row);

                if (!memosCached || hasNotes)
                {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'notes-btn';
                    button.textContent = 'Bekijk';
                    button.addEventListener('click', function ()
                    {
                        openNotesModalForRow(row, groupedLabelSet.size > 0 ? groupedLabelSet : null);
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
                const memosCached = rowMemosAreCached(row);
                td.textContent = memoValue;
                td.classList.add('memo-cell-full');

                if (!memosCached || memoValue.trim() !== '')
                {
                    const memoField = memoFieldByKey[column.key];
                    td.classList.add('memo-cell-clickable');
                    if (!memosCached)
                    {
                        td.title = 'Klik om memo te laden';
                    }

                    td.addEventListener('click', function ()
                    {
                        openNotesModalForRow(row, memoField ? new Set([memoField.noteLabel]) : null);
                    });
                }
            }
            else
            {
                if (column.key === 'No')
                {
                    const workorderValue = String(row[column.key] || '').trim();
                    td.textContent = workorderValue;
                    const workorderLines = getProjectPostenRowsForWorkorder(row);

                    if (workorderValue !== '' && workorderLines.length > 0)
                    {
                        td.classList.add('project-posten-link');
                        td.title = 'Klik voor ProjectPosten-regels van deze werkorder.';
                        td.addEventListener('click', function ()
                        {
                            openProjectPostenModalForWorkorder(row);
                        });
                    }
                }
                else if (column.key === 'Job_No')
                {
                    const projectValue = String(row[column.key] || '').trim();
                    td.textContent = projectValue;

                    if (projectValue !== '')
                    {
                        td.classList.add('project-posten-link');
                        td.title = 'Klik voor ProjectPosten-regels van dit project.';
                        td.addEventListener('click', function ()
                        {
                            openProjectPostenModalForProject(projectValue);
                        });
                    }
                }
                else if (column.key === 'Actual_Total' || column.key === 'Project_Total')
                {
                    const totalAmount = Number(getColumnValueForSorting(row, column.key) || 0);
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
                else if (column.key === 'Actual_Costs' || column.key === 'Total_Revenue' || column.key === 'Project_Actual_Costs' || column.key === 'Project_Total_Revenue')
                {
                    td.textContent = formatCurrencyOrZero(getColumnValueForSorting(row, column.key));
                }
                else if (numericSortKeys.has(column.key))
                {
                    td.textContent = formatCurrencyOrEmpty(row[column.key]);
                }
                else
                {
                    if (column.key === 'Component_No')
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
                    td.removeAttribute('title');
                }
                td.classList.add('amount-info-clickable');
                td.addEventListener('click', function ()
                {
                    openAmountSourceModal(column.key, row);
                });
            }

            if (column.key === 'Invoice_Id')
            {
                const invoiceIds = getRowInvoiceIds(row);

                if (invoiceIds.length > 0)
                {
                    td.textContent = formatInvoiceIdPreview(invoiceIds, 2);
                    td.classList.add('invoice-id-clickable');
                    td.title = buildInvoiceIdTooltip(invoiceIds);
                    td.addEventListener('click', function ()
                    {
                        openInvoiceDetailsModal(invoiceIds);
                    });
                }
            }

            if (column.key === 'No' && showRowLoading)
            {
                const spinner = document.createElement('span');
                spinner.className = 'row-load-spinner';
                spinner.setAttribute('aria-hidden', 'true');
                td.insertBefore(spinner, td.firstChild);
            }

            tr.appendChild(td);
        }

        observeWorkorderRow(tr, rowKey);
        return tr;
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
        const statusCounts = getStatusCountsForSelectedCostCenter();

        const title = document.createElement('span');
        title.className = 'status-filter-title';
        title.textContent = 'Statusfilters:';
        statusFilterBar.appendChild(title);

        const orderedStatusKeys = Array.from(new Set([
            ...Object.keys(statusInfoMap),
            ...Object.keys(statusCounts)
        ])).sort(function (a, b)
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
            const info = statusInfoMap[statusKey] || {
                label: statusKey,
                count: 0
            };
            if (!info) continue;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'status-filter-btn status-' + statusKey;
            button.textContent = info.label + ' (' + (statusCounts[statusKey] || 0) + ')';
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
        syncTableScrollWrapMaxHeight();
    }

    function renderSearchForm ()
    {
        const searchForm = document.createElement('form');
        searchForm.className = 'status-search-form';

        const resetSortButton = document.createElement('button');
        resetSortButton.type = 'button';
        resetSortButton.textContent = 'Reset sortering';
        resetSortButton.disabled = sortState.key === defaultSortState.key && sortState.direction === defaultSortState.direction;
        resetSortButton.addEventListener('click', function ()
        {
            resetSortState();
            renderHeader();
            renderRows();
            renderStatusButtons();
        });

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

        searchForm.appendChild(resetSortButton);
        searchForm.appendChild(searchInput);
        searchForm.appendChild(searchButton);
        statusFilterBar.appendChild(searchForm);
    }

    function resetSortState ()
    {
        sortState.key = defaultSortState.key;
        sortState.direction = defaultSortState.direction;
    }

    function isSortKeySupported (key)
    {
        if (key === defaultSortState.key)
        {
            return true;
        }

        return columns.some(function (column)
        {
            return column.key === key;
        });
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

        if (rowMatchesProjectOrInvoiceSearch(row))
        {
            return true;
        }

        for (const column of columns)
        {
            if (column.key === 'Notes')
            {
                continue;
            }

            if (column.key === 'Component_No')
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

    function rowMatchesProjectOrInvoiceSearch (row)
    {
        if (appliedSearchText === '')
        {
            return false;
        }

        const projectNumber = String((row && row.Job_No) || '').toLowerCase();
        if (projectNumber.includes(appliedSearchText))
        {
            return true;
        }

        const invoiceIds = getRowInvoiceIds(row);
        for (const invoiceId of invoiceIds)
        {
            if (invoiceId.toLowerCase().includes(appliedSearchText))
            {
                return true;
            }
        }

        return false;
    }

    function getRowInvoiceIds (row)
    {
        const sourceIds = Array.isArray(row && row.Invoice_Ids)
            ? row.Invoice_Ids
            : String((row && row.Invoice_Id) || '').split(',');

        const uniqueIds = new Set();
        for (const invoiceId of sourceIds)
        {
            const normalizedId = String(invoiceId || '').trim();
            if (normalizedId !== '')
            {
                uniqueIds.add(normalizedId);
            }
        }

        return Array.from(uniqueIds);
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

    function refreshStatusInfoFromRows ()
    {
        for (const row of rows)
        {
            const statusValue = String(row.Status || '').trim();
            const key = normalizeStatus(statusValue);
            if (!key || statusInfoMap[key])
            {
                continue;
            }

            statusInfoMap[key] = {
                label: statusValue || key,
                count: 0
            };
        }
    }

    function refreshStatusFilters ()
    {
        refreshStatusInfoFromRows();
        renderStatusButtons();
    }

    function getStatusCountsForSelectedCostCenter ()
    {
        const counts = {};

        for (const row of rows)
        {
            if (!matchesSelectedCostCenter(row))
            {
                continue;
            }

            const statusKey = normalizeStatus(row.Status || '');
            if (!statusKey)
            {
                continue;
            }

            counts[statusKey] = (counts[statusKey] || 0) + 1;
        }

        return counts;
    }

    function updateSummaryCount ()
    {
        let count = rows.length;

        if (selectedCostCenter !== 'all')
        {
            count = rows.filter(function (row)
            {
                return matchesSelectedCostCenter(row);
            }).length;
        }

        summary.textContent = summaryPrefix + count;
    }

    function getVisibleSortedRows ()
    {
        const globalRows = getVisibleGlobalRows();
        if (layoutStyle === 'table' && keepProjectWorkordersTogether)
        {
            return flattenProjectGroups(buildProjectGroupsFromGlobalRows(globalRows));
        }

        return globalRows;
    }

    function getVisibleGlobalRows ()
    {
        const globallyFilteredRows = getRowsMatchingGlobalFilters();
        const searchFilteredRows = globallyFilteredRows.filter(rowMatchesSearch);
        return searchFilteredRows.slice().sort(compareRowsForGlobalOrder);
    }

    function getRowsMatchingGlobalFilters ()
    {
        return rows.filter(function (row)
        {
            if (!matchesSelectedCostCenter(row))
            {
                return false;
            }

            const statusKey = normalizeStatus(row.Status || '');
            if (hiddenStatuses.has(statusKey))
            {
                return false;
            }

            return true;
        });
    }

    function normalizeCostCenterForMatch (value)
    {
        const trimmed = String(value || '').trim();
        if (trimmed === '')
        {
            return '';
        }

        const leadingDigits = trimmed.match(/^(\d+)/);
        if (leadingDigits)
        {
            const withoutLeadingZeros = leadingDigits[1].replace(/^0+/, '');
            return withoutLeadingZeros === '' ? '0' : withoutLeadingZeros;
        }

        return trimmed;
    }

    function matchesSelectedCostCenter (row)
    {
        if (selectedCostCenter === 'all')
        {
            return true;
        }

        const rowCostCenter = String(row.Cost_Center || '').trim();
        if (selectedCostCenter === noneCostCenterValue)
        {
            return rowCostCenter === '';
        }

        return normalizeCostCenterForMatch(rowCostCenter) === normalizeCostCenterForMatch(selectedCostCenter);
    }

    function getVisibleProjectGroups ()
    {
        const globalRows = getVisibleGlobalRowsForProjectGroups();
        return buildProjectGroupsFromGlobalRows(globalRows);
    }

    function getVisibleGlobalRowsForProjectGroups ()
    {
        const globallyFilteredRows = getRowsMatchingGlobalFilters().slice().sort(compareRowsForGlobalOrder);

        if (appliedSearchText === '')
        {
            return globallyFilteredRows;
        }

        const groups = buildProjectGroupsFromGlobalRows(globallyFilteredRows);
        const visibleRows = [];

        for (const group of groups)
        {
            const matchingRows = group.rows.filter(rowMatchesSearch);
            if (matchingRows.length === 0)
            {
                continue;
            }

            const hasProjectOrInvoiceMatch = group.rows.some(rowMatchesProjectOrInvoiceSearch);
            if (hasProjectOrInvoiceMatch)
            {
                visibleRows.push.apply(visibleRows, group.rows);
                continue;
            }

            visibleRows.push.apply(visibleRows, matchingRows);
        }

        return visibleRows;
    }

    function buildProjectGroupsFromGlobalRows (globalRows)
    {
        const groupsByProject = new Map();

        for (let index = 0; index < globalRows.length; index += 1)
        {
            const row = globalRows[index];
            const projectKey = normalizeSortValue(row.Job_No || '');
            const rank = index + 1;

            if (!groupsByProject.has(projectKey))
            {
                groupsByProject.set(projectKey, {
                    projectKey: projectKey,
                    rows: [],
                    sortScoreSum: 0,
                    sortScoreAverage: 0,
                    bestRank: rank
                });
            }

            const group = groupsByProject.get(projectKey);
            group.rows.push(row);
            group.sortScoreSum += rank;
            if (rank < group.bestRank)
            {
                group.bestRank = rank;
            }
        }

        const groups = Array.from(groupsByProject.values());
        for (const group of groups)
        {
            group.sortScoreAverage = group.rows.length > 0 ? (group.sortScoreSum / group.rows.length) : Number.POSITIVE_INFINITY;
        }

        groups.sort(function (leftGroup, rightGroup)
        {
            const scoreDifference = leftGroup.sortScoreAverage - rightGroup.sortScoreAverage;
            if (scoreDifference !== 0)
            {
                return scoreDifference;
            }

            const rankDifference = leftGroup.bestRank - rightGroup.bestRank;
            if (rankDifference !== 0)
            {
                return rankDifference;
            }

            return leftGroup.projectKey.localeCompare(rightGroup.projectKey, 'nl', { numeric: true, sensitivity: 'base' });
        });

        return groups;
    }

    function flattenProjectGroups (groups)
    {
        const flattenedRows = [];
        for (const group of groups)
        {
            for (const row of group.rows)
            {
                flattenedRows.push(row);
            }
        }

        return flattenedRows;
    }

    const EXPORT_STATUS_FILL_ARGB = {
        'open': 'FFFFFFFF',
        'signed': 'FFF6F9E9',
        'completed': 'FFE9F9EE',
        'checked': 'FFFFF1DD',
        'cancelled': 'FFFFA7A7',
        'closed': 'FFC5C5C5',
        'planned': 'FFDDEFFF',
        'in-progress': 'FFFFE9E9'
    };

    const EXPORT_SIGNED_AMOUNT_KEYS = new Set(['Actual_Total', 'Project_Total']);

    async function exportVisibleRowsToXlsx ()
    {
        if (typeof ExcelJS === 'undefined')
        {
            if (demeterModal)
            {
                await demeterModal.alert({
                    title: 'Export niet beschikbaar',
                    message: 'Excel-export is niet beschikbaar.'
                });
            }
            return;
        }

        const visibleRows = getVisibleSortedRows();
        const columns = exportColumns;
        const headerLabels = buildUniqueExportTableColumnNames(columns.map(function (column)
        {
            return formatDisplayLabel(column.label);
        }));

        const workbook = new ExcelJS.Workbook();
        workbook.creator = 'Demeter';
        const worksheet = workbook.addWorksheet('Werkorders', {
            views: [{ state: 'frozen', ySplit: 1 }]
        });

        const tableRows = [];
        const rowStatusKeys = [];
        const seenProjectsForExport = new Set();

        for (const row of visibleRows)
        {
            const exportProjectKey = normalizeSortValue(String(row.Job_No || ''));
            const isFirstProjectRowForExport = exportProjectKey === '' || !seenProjectsForExport.has(exportProjectKey);
            if (exportProjectKey !== '')
            {
                seenProjectsForExport.add(exportProjectKey);
            }

            tableRows.push(columns.map(function (column)
            {
                return getExportCellValue(row, column.key, isFirstProjectRowForExport);
            }));
            rowStatusKeys.push(normalizeStatus(row.Status || ''));
        }

        worksheet.addTable({
            name: 'DemeterWerkorders',
            ref: 'A1',
            headerRow: true,
            totalsRow: false,
            style: {
                theme: 'TableStyleLight1',
                showRowStripes: false
            },
            columns: headerLabels.map(function (name)
            {
                return { name: name, filterButton: true };
            }),
            rows: tableRows
        });

        const headerRow = worksheet.getRow(1);
        headerRow.font = { bold: true, color: { argb: 'FF203A63' } };
        headerRow.fill = {
            type: 'pattern',
            pattern: 'solid',
            fgColor: { argb: 'FFF1F5FB' }
        };
        headerRow.alignment = { vertical: 'middle', wrapText: true };

        for (let rowIndex = 0; rowIndex < rowStatusKeys.length; rowIndex += 1)
        {
            const excelRow = worksheet.getRow(rowIndex + 2);
            const statusKey = rowStatusKeys[rowIndex];
            const fillArgb = EXPORT_STATUS_FILL_ARGB[statusKey] || 'FFFFFFFF';
            const dataRow = visibleRows[rowIndex];

            excelRow.eachCell({ includeEmpty: true }, function (cell, colNumber)
            {
                const column = columns[colNumber - 1];
                if (!column)
                {
                    return;
                }

                cell.fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: { argb: fillArgb }
                };

                if (numericSortKeys.has(column.key) && typeof cell.value === 'number')
                {
                    cell.numFmt = '#,##0.00';
                }

                if (EXPORT_SIGNED_AMOUNT_KEYS.has(column.key))
                {
                    const amount = Number(getColumnValueForSorting(dataRow, column.key) || 0);
                    if (amount !== 0)
                    {
                        cell.font = {
                            bold: true,
                            color: { argb: amount > 0 ? 'FF0B6B2F' : 'FFB42318' }
                        };
                    }
                }
            });
        }

        worksheet.columns.forEach(function (column, columnIndex)
        {
            let maxLength = headerLabels[columnIndex].length;
            for (let rowIndex = 0; rowIndex < tableRows.length; rowIndex += 1)
            {
                const value = tableRows[rowIndex][columnIndex];
                if (value === null || value === undefined)
                {
                    continue;
                }

                const textLength = String(value).length;
                if (textLength > maxLength)
                {
                    maxLength = textLength;
                }
            }

            column.width = Math.min(42, Math.max(12, maxLength + 2));
        });

        const buffer = await workbook.xlsx.writeBuffer();
        const blob = new Blob([buffer], {
            type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'demeter_export.xlsx';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function buildUniqueExportTableColumnNames (labels)
    {
        const seenCounts = new Map();
        return labels.map(function (label)
        {
            const count = seenCounts.get(label) || 0;
            seenCounts.set(label, count + 1);
            if (count === 0)
            {
                return label;
            }

            return label + ' (' + (count + 1) + ')';
        });
    }

    function getExportCellValue (row, key, isFirstProjectRow)
    {
        if (projectFinancialColumnKeys.has(key) && !isFirstProjectRow)
        {
            return null;
        }

        if (isMemoFieldKey(key))
        {
            const memoValue = getMemoFieldValue(row, key);
            return memoValue === '' ? null : memoValue;
        }

        if (key === 'Notes')
        {
            const parts = Array.isArray(row.Notes) ? row.Notes : [];
            const lines = [];
            for (const part of parts)
            {
                const label = String((part && part.label) || '').trim().replaceAll('_', ' ');
                const value = String((part && part.value) || '').trim();
                if (value === '')
                {
                    continue;
                }
                lines.push(label + ': ' + value);
            }

            return lines.length === 0 ? null : lines.join(' | ');
        }

        if (key === 'Component_No')
        {
            const equipmentValue = getEquipmentDisplayValue(row);
            return equipmentValue === '' ? null : equipmentValue;
        }

        if (numericSortKeys.has(key))
        {
            const amount = Number(getColumnValueForSorting(row, key) || 0);
            if (EXPORT_SIGNED_AMOUNT_KEYS.has(key) && amount === 0)
            {
                return null;
            }

            return amount;
        }

        const textValue = String(row[key] || '').trim();
        return textValue === '' ? null : textValue;
    }

    function compareRowsForGlobalOrder (a, b)
    {
        const activeSortComparison = compareRowsByActiveSort(a, b);
        if (activeSortComparison !== 0)
        {
            return activeSortComparison;
        }

        const leftNo = normalizeSortValue(getColumnValueForSorting(a, 'No'));
        const rightNo = normalizeSortValue(getColumnValueForSorting(b, 'No'));
        const workorderComparison = leftNo.localeCompare(rightNo, 'nl', { numeric: true, sensitivity: 'base' });
        if (workorderComparison !== 0)
        {
            return workorderComparison;
        }

        const leftProjectNo = normalizeSortValue(getColumnValueForSorting(a, 'Job_No'));
        const rightProjectNo = normalizeSortValue(getColumnValueForSorting(b, 'Job_No'));
        return leftProjectNo.localeCompare(rightProjectNo, 'nl', { numeric: true, sensitivity: 'base' });
    }

    function compareRowsByActiveSort (a, b)
    {
        if (sortState.key === 'Component_No')
        {
            const leftEquipment = getEquipmentDisplayValue(a);
            const rightEquipment = getEquipmentDisplayValue(b);
            const equipmentComparison = leftEquipment.localeCompare(rightEquipment, 'nl', { numeric: true, sensitivity: 'base' });
            return sortState.direction === 'asc' ? equipmentComparison : -equipmentComparison;
        }

        if (numericSortKeys.has(sortState.key))
        {
            const leftNumber = Number(getColumnValueForSorting(a, sortState.key) || 0);
            const rightNumber = Number(getColumnValueForSorting(b, sortState.key) || 0);
            const difference = leftNumber - rightNumber;
            return sortState.direction === 'asc' ? difference : -difference;
        }

        const left = normalizeSortValue(getColumnValueForSorting(a, sortState.key));
        const right = normalizeSortValue(getColumnValueForSorting(b, sortState.key));
        const comparison = left.localeCompare(right, 'nl', { numeric: true, sensitivity: 'base' });
        return sortState.direction === 'asc' ? comparison : -comparison;
    }

    function normalizeLayoutStyle (value)
    {
        const normalized = String(value || '').trim().toLowerCase();
        return normalized === 'projectgroups' ? 'projectgroups' : 'table';
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
        if (key === 'Project_Actual_Costs' || key === 'Project_Total_Revenue')
        {
            return Number(row[key] || 0);
        }

        if (key === 'Project_Total')
        {
            const projectCosts = Number(row.Project_Actual_Costs || 0);
            const projectRevenue = Number(row.Project_Total_Revenue || 0);
            return projectRevenue - projectCosts;
        }

        if (key === 'Component_No')
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

    function formatSignedCurrencyOrZero (value)
    {
        const amount = Number(value || 0);
        if (amount === 0)
        {
            return '€ 0';
        }

        return formatSignedCurrency(amount);
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
        return String((row && (row.Component_No || row.Equipment_Number)) || '').trim();
    }

    function rowMemosAreCached (row)
    {
        if (!row || typeof row !== 'object')
        {
            return false;
        }

        if (row.Memos_Loaded === true)
        {
            return true;
        }

        const parts = Array.isArray(row.Notes) ? row.Notes : [];

        return parts.some(function (part)
        {
            return String((part && part.value) || '').trim() !== '';
        });
    }

    function buildWorkorderMemoRowRef (row)
    {
        return {
            row_key: String(row.Row_Key || '').trim(),
            no: String(row.Bc_No || row.No || '').trim(),
            job_no: String(row.Job_No || '').trim(),
            job_task_no: String(row.Job_Task_No || '').trim(),
            start_date: String(row.Start_Date || '').trim(),
        };
    }

    function applyMemosBundleToRow (row, bundle)
    {
        if (!row || typeof row !== 'object' || !bundle || typeof bundle !== 'object')
        {
            return;
        }

        row.Notes = Array.isArray(bundle.notes) ? bundle.notes : [];
        row.Notes_Search = String(bundle.notes_search || '');
        row.Memos_Loaded = bundle.memos_loaded === true;
        delete row.__memoValues;
    }

    function applyMemosResponseToRows (memosByRowKey)
    {
        if (!memosByRowKey || typeof memosByRowKey !== 'object')
        {
            return;
        }

        for (const rowKey of Object.keys(memosByRowKey))
        {
            const bundle = memosByRowKey[rowKey];
            const row = rowsByKey.get(rowKey);
            if (!row)
            {
                continue;
            }

            applyMemosBundleToRow(row, bundle);
        }
    }

    async function fetchWorkorderMemos (targetRows)
    {
        const refs = [];
        for (const row of targetRows)
        {
            if (!row || typeof row !== 'object')
            {
                continue;
            }

            const ref = buildWorkorderMemoRowRef(row);
            if (ref.row_key === '')
            {
                continue;
            }

            refs.push(ref);
        }

        if (refs.length === 0)
        {
            return {};
        }

        const response = await fetch(loadWorkorderMemosUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json'
            },
            body: JSON.stringify({
                company: String(payload.company || ''),
                cost_center: loadedCostCenter,
                rows: refs
            })
        });

        const body = await response.json();
        if (!response.ok || !body || body.ok !== true)
        {
            const errorText = body && body.error ? body.error : ('HTTP ' + response.status);
            const apiError = createDemeterApiError(errorText, body, response.status);
            logDemeterODataFailure('load_workorder_memos rows=' + String(refs.length), apiError);
            throw apiError;
        }

        return body.memos_by_row_key && typeof body.memos_by_row_key === 'object'
            ? body.memos_by_row_key
            : {};
    }

    async function fetchAndApplyWorkorderMemos (targetRows)
    {
        const pendingRows = targetRows.filter(function (row)
        {
            return row && typeof row === 'object' && !rowMemosAreCached(row);
        });

        if (pendingRows.length === 0)
        {
            return;
        }

        const memosByRowKey = await fetchWorkorderMemos(pendingRows);
        applyMemosResponseToRows(memosByRowKey);
    }

    async function ensureRowMemosLoaded (row)
    {
        if (!row || typeof row !== 'object' || rowMemosAreCached(row))
        {
            return row;
        }

        const rowKey = String(row.Row_Key || '').trim();
        if (rowKey === '')
        {
            return row;
        }

        if (memoFetchInFlight.has(rowKey))
        {
            await memoFetchInFlight.get(rowKey);

            return row;
        }

        const promise = fetchAndApplyWorkorderMemos([row]);
        memoFetchInFlight.set(rowKey, promise);

        try
        {
            await promise;
        }
        finally
        {
            memoFetchInFlight.delete(rowKey);
        }

        return row;
    }

    function buildNotesPartsForRow (row, labelFilterSet)
    {
        const parts = Array.isArray(row.Notes) ? row.Notes : [];

        if (!labelFilterSet || !(labelFilterSet instanceof Set) || labelFilterSet.size === 0)
        {
            return parts;
        }

        return parts.filter(function (part)
        {
            const label = String((part && part.label) || '').trim();

            return labelFilterSet.has(label);
        });
    }

    function showNotesModalLoading (message)
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
        const loading = document.createElement('div');
        loading.className = 'notes-section-text';
        loading.textContent = message || 'Memo laden...';
        notesBody.appendChild(loading);
        notesOverlay.style.display = '';
    }

    async function openNotesModalForRow (row, labelFilterSet)
    {
        showNotesModalLoading('Memo laden...');

        try
        {
            await ensureRowMemosLoaded(row);

            const rowKey = String(row.Row_Key || '').trim();
            if (rowKey !== '' && rowDomByKey.has(rowKey))
            {
                replaceWorkorderRowTr(rowKey, row);
            }

            openNotesModal(buildNotesPartsForRow(row, labelFilterSet));
        }
        catch (memoError)
        {
            logDemeterODataFailure('memo modal', memoError);
            if (!notesBody)
            {
                return;
            }

            notesBody.innerHTML = '';
            const error = document.createElement('div');
            error.className = 'notes-section-text';
            error.textContent = 'Memo laden mislukt: ' + String(memoError && memoError.message ? memoError.message : memoError);
            notesBody.appendChild(error);
            notesOverlay.style.display = '';
        }
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

        const invoiceIds = Array.isArray(invoiceId)
            ? invoiceId.map(function (value) { return String(value || '').trim(); }).filter(Boolean)
            : [String(invoiceId || '').trim()].filter(Boolean);

        if (notesModalTitle)
        {
            notesModalTitle.textContent = 'Factuurdetails';
        }

        notesBody.innerHTML = '';

        if (invoiceIds.length === 0)
        {
            const empty = document.createElement('div');
            empty.textContent = 'Geen factuurdetails beschikbaar.';
            notesBody.appendChild(empty);
            notesOverlay.style.display = '';
            return;
        }

        const table = document.createElement('table');
        table.className = 'workorders-table';

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        const headings = ['Factuurnummer', 'Status', 'Klantnummer', 'Beschrijving', 'Excl. BTW', 'Incl. BTW', 'Kortingpercentage', 'Korting'];
        for (const headingText of headings)
        {
            const th = document.createElement('th');
            th.textContent = headingText;
            headRow.appendChild(th);
        }
        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbodyElement = document.createElement('tbody');
        let hasLines = false;
        let totalAmountExcl = 0;
        let totalAmountIncl = 0;
        let totalDiscountAmount = 0;
        let effectiveDiscountBase = 0;
        let effectiveDiscountAmount = 0;
        for (const currentInvoiceId of invoiceIds)
        {
            const details = invoiceDetailsById[currentInvoiceId];
            const lines = details && typeof details === 'object' && Array.isArray(details.Lines)
                ? details.Lines
                : [];

            if (lines.length === 0)
            {
                continue;
            }

            for (const line of lines)
            {
                const amountExcl = Number((line && line.Amount) || 0);
                const amountIncl = Number((line && line.Amount_Including_Vat) || 0);
                const discountPercent = Number((line && line.Line_Discount_Percent) || 0);
                const discountAmount = Number((line && line.Line_Discount_Amount) || 0);

                totalAmountExcl += amountExcl;
                totalAmountIncl += amountIncl;
                totalDiscountAmount += discountAmount;
                if (amountExcl > 0)
                {
                    effectiveDiscountBase += amountExcl;
                    effectiveDiscountAmount += discountAmount > 0 ? discountAmount : ((amountExcl * discountPercent) / 100);
                }

                const tr = document.createElement('tr');
                const cellValues = [
                    currentInvoiceId,
                    getInvoiceStatusLabel(currentInvoiceId, String((line && line.Source_Entity) || '').trim()),
                    String((line && line.Customer_No) || ''),
                    String((line && line.Description) || ''),
                    formatCurrencyOrZero(amountExcl),
                    formatCurrencyOrZero(amountIncl),
                    String(discountPercent) + '%',
                    formatCurrencyOrZero(discountAmount)
                ];

                for (const cellValue of cellValues)
                {
                    const td = document.createElement('td');
                    td.textContent = cellValue;
                    tr.appendChild(td);
                }

                tbodyElement.appendChild(tr);
                hasLines = true;
            }
        }

        if (!hasLines)
        {
            const empty = document.createElement('div');
            empty.textContent = 'Geen factuurdetails beschikbaar.';
            notesBody.appendChild(empty);
            notesOverlay.style.display = '';
            return;
        }

        const totalsRow = document.createElement('tr');
        const effectiveDiscountPercent = effectiveDiscountBase > 0
            ? ((effectiveDiscountAmount / effectiveDiscountBase) * 100)
            : 0;
        const totalsCellValues = [
            'Totaal',
            '',
            '',
            '',
            formatCurrencyOrZero(totalAmountExcl),
            formatCurrencyOrZero(totalAmountIncl),
            String(Math.round(effectiveDiscountPercent * 100) / 100) + '%',
            formatCurrencyOrZero(totalDiscountAmount)
        ];

        for (const totalsCellValue of totalsCellValues)
        {
            const td = document.createElement('td');
            td.textContent = totalsCellValue;
            td.style.fontWeight = '700';
            totalsRow.appendChild(td);
        }
        tbodyElement.appendChild(totalsRow);

        table.appendChild(tbodyElement);
        notesBody.appendChild(table);

        notesOverlay.style.display = '';
    }

    function normalizeProjectPostenMapKey (value)
    {
        return String(value || '').trim().toLowerCase();
    }

    function buildProjectWorkorderCompositeKey (row, workorderKeyCandidate)
    {
        const projectKey = normalizeProjectPostenMapKey((row && row.Job_No) || '');
        const sourceWorkorderKey = normalizeProjectPostenMapKey(workorderKeyCandidate);
        if (projectKey === '' || sourceWorkorderKey === '')
        {
            return '';
        }

        return projectKey + '|' + sourceWorkorderKey;
    }

    function getProjectPostenRowsForWorkorder (row)
    {
        const keyCandidates = [
            (row && row.Workorder_Source_Key) || '',
            (row && row.Job_Task_No) || '',
            (row && row.No) || ''
        ];

        for (const candidate of keyCandidates)
        {
            const compositeKey = buildProjectWorkorderCompositeKey(row, candidate);
            if (compositeKey === '')
            {
                continue;
            }

            const lines = projectPostenRowsByProjectAndWorkorder[compositeKey];
            if (Array.isArray(lines) && lines.length > 0)
            {
                return lines.slice();
            }
        }

        return [];
    }

    function getProjectPostenRowsForProject (projectNo)
    {
        const projectKey = normalizeProjectPostenMapKey(projectNo);
        if (projectKey === '')
        {
            return [];
        }

        const lines = projectPostenRowsByProject[projectKey];
        return Array.isArray(lines) ? lines.slice() : [];
    }

    function sortProjectPostenRows (lines)
    {
        return lines.slice().sort(function (left, right)
        {
            const leftDate = normalizeSortValue((left && left.Posting_Date) || '');
            const rightDate = normalizeSortValue((right && right.Posting_Date) || '');
            const dateCompare = leftDate.localeCompare(rightDate, 'nl', { numeric: true, sensitivity: 'base' });
            if (dateCompare !== 0)
            {
                return dateCompare;
            }

            const leftDescription = normalizeSortValue((left && left.Description) || '');
            const rightDescription = normalizeSortValue((right && right.Description) || '');
            return leftDescription.localeCompare(rightDescription, 'nl', { numeric: true, sensitivity: 'base' });
        });
    }

    function openProjectPostenModalForWorkorder (row)
    {
        const lines = getProjectPostenRowsForWorkorder(row);
        const projectNo = String((row && row.Job_No) || '').trim();
        const workorderNo = String((row && row.No) || '').trim();
        const title = 'ProjectPosten - Werkorder ' + (workorderNo !== '' ? workorderNo : '(leeg)') + (projectNo !== '' ? ' (Project ' + projectNo + ')' : '');
        openProjectPostenModal(title, lines);
    }

    function openProjectPostenModalForProject (projectNo)
    {
        const safeProjectNo = String(projectNo || '').trim();
        const lines = getProjectPostenRowsForProject(safeProjectNo);
        const title = 'ProjectPosten - Project ' + (safeProjectNo !== '' ? safeProjectNo : '(leeg)');
        openProjectPostenModal(title, lines);
    }

    function openProjectPostenModal (title, lines)
    {
        if (!notesBody)
        {
            return;
        }

        if (notesModalTitle)
        {
            notesModalTitle.textContent = title;
        }

        notesBody.innerHTML = '';

        const normalizedLines = Array.isArray(lines) ? lines.filter(function (line)
        {
            return line && typeof line === 'object';
        }) : [];

        if (normalizedLines.length === 0)
        {
            const empty = document.createElement('div');
            empty.textContent = 'Geen ProjectPosten-regels beschikbaar.';
            notesBody.appendChild(empty);
            notesOverlay.style.display = '';
            return;
        }

        const table = document.createElement('table');
        table.className = 'workorders-table';

        const thead = document.createElement('thead');
        const headRow = document.createElement('tr');
        const headings = ['Workorder', 'Posting_Date', 'Entry_Type', 'Type', 'No', 'Description', 'Total_Cost', 'Line_Amount'];
        for (const headingText of headings)
        {
            const th = document.createElement('th');
            th.textContent = headingText;
            if (headingText === 'Type')
            {
                th.style.minWidth = '120px';
            }
            headRow.appendChild(th);
        }
        thead.appendChild(headRow);
        table.appendChild(thead);

        const tbodyElement = document.createElement('tbody');
        const sortedLines = sortProjectPostenRows(normalizedLines);

        for (const line of sortedLines)
        {
            const tr = document.createElement('tr');
            const cellValues = [
                String((line && line.Workorder) || ''),
                String((line && line.Posting_Date) || ''),
                String((line && line.Entry_Type) || ''),
                String((line && line.Type) || ''),
                String((line && line.No) || ''),
                String((line && line.Description) || ''),
                formatCurrencyOrZero(Number((line && line.Total_Cost) || 0)),
                formatCurrencyOrZero(Number((line && line.Line_Amount) || 0))
            ];

            for (const cellValue of cellValues)
            {
                const td = document.createElement('td');
                td.textContent = cellValue;
                tr.appendChild(td);
            }

            tbodyElement.appendChild(tr);
        }

        table.appendChild(tbodyElement);
        notesBody.appendChild(table);
        notesOverlay.style.display = '';
    }

    function buildInvoiceIdTooltip (invoiceIds)
    {
        const ids = Array.isArray(invoiceIds)
            ? invoiceIds.map(function (invoiceId) { return String(invoiceId || '').trim(); }).filter(Boolean)
            : [];
        if (ids.length === 0)
        {
            return 'Geen factuurdetails beschikbaar.';
        }

        return ids.length === 1
            ? 'Klik voor factuurdetails van ' + ids[0] + '.'
            : 'Klik voor factuurdetails (' + String(ids.length) + ' facturen).';
    }

    function formatInvoiceIdPreview (invoiceIds, maxVisible)
    {
        const ids = Array.isArray(invoiceIds)
            ? invoiceIds.map(function (invoiceId) { return String(invoiceId || '').trim(); }).filter(Boolean)
            : [];
        const safeMaxVisible = Number(maxVisible) > 0 ? Number(maxVisible) : ids.length;
        const visibleInvoiceIds = ids.slice(0, safeMaxVisible);
        const hiddenInvoiceCount = Math.max(0, ids.length - visibleInvoiceIds.length);

        return visibleInvoiceIds.join(', ') + (hiddenInvoiceCount > 0 ? ' +' + String(hiddenInvoiceCount) : '');
    }

    function getInvoiceStatusLabel (invoiceId, preferredSourceEntity)
    {
        const sourceFromLine = String(preferredSourceEntity || '').trim();
        if (sourceFromLine === 'SalesLines')
        {
            return 'Voorbereiding';
        }

        if (sourceFromLine === 'SalesInvoiceLines')
        {
            return 'Gefactureerd';
        }

        const normalizedId = String(invoiceId || '').trim();
        if (normalizedId === '')
        {
            return '';
        }

        const details = invoiceDetailsById[normalizedId];
        const sourceEntities = details && typeof details === 'object' && Array.isArray(details.Source_Entities)
            ? details.Source_Entities.map(function (source) { return String(source || '').trim(); }).filter(Boolean)
            : [];

        if (sourceEntities.includes('SalesLines'))
        {
            return 'Voorbereiding';
        }

        if (sourceEntities.includes('SalesInvoiceLines'))
        {
            return 'Gefactureerd';
        }

        const sourceEntity = String((details && details.Source_Entity) || '').trim();
        if (sourceEntity === 'SalesLines')
        {
            return 'Voorbereiding';
        }

        if (sourceEntity === 'SalesInvoiceLines')
        {
            return 'Gefactureerd';
        }

        return '';
    }

    function getAmountSourceInfo (columnKey, row)
    {
        const safeRow = row && typeof row === 'object' ? row : {};

        if (columnKey === 'Actual_Costs')
        {
            return {
                source: safeRow.Actual_Costs_Source === 'invoice' ? 'invoice' : 'workorder',
                reason: String(safeRow.Actual_Costs_Source_Reason || '').trim(),
                label: 'Kosten werkorder'
            };
        }

        if (columnKey === 'Total_Revenue')
        {
            return {
                source: safeRow.Total_Revenue_Source === 'invoice' ? 'invoice' : 'workorder',
                reason: String(safeRow.Total_Revenue_Source_Reason || '').trim(),
                label: 'Opbrengst werkorder'
            };
        }

        return {
            source: safeRow.Actual_Total_Source === 'invoice' ? 'invoice' : (safeRow.Actual_Total_Source === 'workorder' ? 'workorder' : 'mixed'),
            reason: String(safeRow.Actual_Total_Source_Reason || '').trim(),
            label: 'Resultaat werkorder'
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
        const message = source === 'invoice'
            ? invoiceAmountModalMessage
            : (source === 'workorder' ? workorderAmountModalMessage : 'Dit totaal gebruikt een combinatie van bronnen (factuur en werkorder).');

        if (notesModalTitle)
        {
            notesModalTitle.textContent = 'Herkomst bedragen';
        }

        notesBody.innerHTML = '';

        const section = document.createElement('div');
        section.className = 'notes-section';

        const heading = document.createElement('div');
        heading.className = 'notes-section-title';
        heading.textContent = sourceInfo.label + ' · Bron: ' + (source === 'invoice' ? 'factuur' : (source === 'workorder' ? 'werkorder' : 'gemengd'));

        const content = document.createElement('pre');
        content.className = 'notes-section-text';
        content.textContent = message;

        section.appendChild(heading);
        section.appendChild(content);
        notesBody.appendChild(section);

        notesOverlay.style.display = '';
    }

    function closeNotesModal ()
    {
        notesOverlay.style.display = 'none';
    }

    function setupRowAnimationObserver ()
    {
        if (!tableScrollWrap || rowAnimationObserver)
        {
            return;
        }

        rowAnimationObserver = new IntersectionObserver(function (entries)
        {
            for (const entry of entries)
            {
                entry.target.classList.toggle('is-visible-for-animation', entry.isIntersecting);
            }
        }, {
            root: tableScrollWrap,
            threshold: 0.08,
            rootMargin: '40px 0px'
        });
    }

    function observeWorkorderRow (tr, rowKey)
    {
        if (!rowAnimationObserver || !tr || !rowKey)
        {
            return;
        }

        rowAnimationObserver.observe(tr);
    }

    function shouldContinueHistoryLoading (monthScan, period)
    {
        if (!period || !/^\d{4}-W\d{2}$/.test(period))
        {
            return false;
        }

        const stopBefore = String((monthScan && monthScan.stop_before_month) || '').trim();
        if (stopBefore !== '' && period < stopBefore)
        {
            return false;
        }

        if (Number((monthScan && monthScan.consecutive_empty) || 0) >= monthScanEmptyStopCount)
        {
            return false;
        }

        return true;
    }

    function renderLoadStatsNote ()
    {
        if (!loadStatsNote)
        {
            return;
        }

        loadStatsNote.textContent = 'Uit cache geladen: '
            + String(loadStatsFromCache)
            + ', Geupdate uit BC: '
            + String(loadStatsUpdatedFromBc);
    }

    function accumulateLoadStats (meta)
    {
        if (!meta || typeof meta !== 'object' || meta.skipped_cached)
        {
            return;
        }

        loadStatsUpdatedFromBc += Number(meta.updated_from_bc_count || meta.fetched_workorder_count || 0);
        renderLoadStatsNote();
    }

    function updateHistoryLoadNote (message)
    {
        const note = document.getElementById('historyLoadNote');
        if (!note)
        {
            return;
        }

        if (!message)
        {
            note.style.display = 'none';
            note.textContent = '';
            return;
        }

        note.style.display = '';
        note.textContent = message;
    }

    function markRowsLoading (rowKeys)
    {
        if (!Array.isArray(rowKeys))
        {
            return;
        }

        for (const rowKey of rowKeys)
        {
            const normalizedKey = String(rowKey || '').trim();
            if (normalizedKey === '' || !pendingCachedRowKeys.has(normalizedKey))
            {
                continue;
            }

            rowLoadStates.set(normalizedKey, 'loading');
        }

        refreshRowLoadStatesDom(rowKeys);
        if (!usesIncrementalRowRendering() || rowKeys.some(function (rowKey)
        {
            const normalizedKey = String(rowKey || '').trim();
            return normalizedKey !== '' && pendingCachedRowKeys.has(normalizedKey) && !rowDomByKey.has(normalizedKey);
        }))
        {
            renderRows();
        }

        refreshStatusFilters();
    }

    function markRowsComplete (rowKeys)
    {
        if (!Array.isArray(rowKeys))
        {
            return;
        }

        const normalizedKeys = [];
        for (const rowKey of rowKeys)
        {
            const normalizedKey = String(rowKey || '').trim();
            if (normalizedKey === '' || !pendingCachedRowKeys.has(normalizedKey))
            {
                continue;
            }

            if (rowLoadStates.get(normalizedKey) === 'loading')
            {
                rowLoadStates.set(normalizedKey, 'completing');
                normalizedKeys.push(normalizedKey);
            }
        }

        if (normalizedKeys.length === 0)
        {
            return;
        }

        refreshRowLoadStatesDom(normalizedKeys);
        if (!usesIncrementalRowRendering() || normalizedKeys.some(function (normalizedKey)
        {
            return !rowDomByKey.has(normalizedKey);
        }))
        {
            renderRows();
        }

        refreshStatusFilters();

        setTimeout(function ()
        {
            for (const normalizedKey of normalizedKeys)
            {
                rowLoadStates.set(normalizedKey, 'stable');
                pendingCachedRowKeys.delete(normalizedKey);
            }

            refreshRowLoadStatesDom(normalizedKeys);
            if (!usesIncrementalRowRendering())
            {
                renderRows();
            }

            refreshStatusFilters();
        }, 620);
    }

    function mergeProjectPostenMap (target, source)
    {
        if (!source || typeof source !== 'object')
        {
            return;
        }

        for (const key of Object.keys(source))
        {
            if (!Array.isArray(source[key]))
            {
                continue;
            }

            if (!Array.isArray(target[key]))
            {
                target[key] = [];
            }

            target[key] = target[key].concat(source[key]);
        }
    }

    function addCumulativeProjectTotals (chunkTotals)
    {
        if (!chunkTotals || typeof chunkTotals !== 'object')
        {
            return;
        }

        for (const projectKey of Object.keys(chunkTotals))
        {
            const values = chunkTotals[projectKey];
            if (!values || typeof values !== 'object')
            {
                continue;
            }

            if (!cumulativeProjectTotals[projectKey])
            {
                cumulativeProjectTotals[projectKey] = { costs: 0, revenue: 0 };
            }

            cumulativeProjectTotals[projectKey].costs += Number(values.costs || 0);
            cumulativeProjectTotals[projectKey].revenue += Number(values.revenue || 0);
        }
    }

    function applyCumulativeProjectTotalsToRows ()
    {
        for (const row of rows)
        {
            const normalizedJobNo = String(row.Job_No || '').trim().toLowerCase();
            if (normalizedJobNo === '' || !cumulativeProjectTotals[normalizedJobNo])
            {
                continue;
            }

            row.Project_Actual_Costs = cumulativeProjectTotals[normalizedJobNo].costs;
            row.Project_Total_Revenue = cumulativeProjectTotals[normalizedJobNo].revenue;
        }
    }

    function mergeFinanceAmount (left, right)
    {
        return Number(left || 0) + Number(right || 0);
    }

    function preserveExistingMemosOnMerge (existing, incoming)
    {
        if (!existing || typeof existing !== 'object' || !incoming || typeof incoming !== 'object')
        {
            return null;
        }

        if (incoming.Memos_Loaded === true)
        {
            return null;
        }

        if (!rowMemosAreCached(existing))
        {
            return null;
        }

        return {
            Notes: existing.Notes,
            Notes_Search: existing.Notes_Search,
            Memos_Loaded: existing.Memos_Loaded
        };
    }

    function refreshWeekRowIntoExisting (existing, monthRow)
    {
        const preservedFinance = {
            Actual_Costs: existing.Actual_Costs,
            Total_Revenue: existing.Total_Revenue,
            Actual_Total: existing.Actual_Total,
            Project_Actual_Costs: existing.Project_Actual_Costs,
            Project_Total_Revenue: existing.Project_Total_Revenue
        };
        const preservedMemos = preserveExistingMemosOnMerge(existing, monthRow);

        const copyFields = [
            'No', 'Order_Type', 'Contract_No', 'Customer_Id', 'Start_Date', 'Component_No',
            'Component_Description', 'Equipment_Number', 'Equipment_Name', 'Description',
            'Customer_Name', 'Cost_Center', 'Status', 'Document_Status',
            'End_Date', 'Job_No', 'Job_Task_No', 'Workorder_Source_Key', 'Invoice_Id', 'Invoice_Ids', 'Bc_No'
        ];

        for (const field of copyFields)
        {
            const value = monthRow[field];
            if (value === null || value === undefined)
            {
                continue;
            }

            if (typeof value === 'string' && value.trim() === '')
            {
                continue;
            }

            existing[field] = value;
        }

        existing.Actual_Costs = preservedFinance.Actual_Costs;
        existing.Total_Revenue = preservedFinance.Total_Revenue;
        existing.Actual_Total = preservedFinance.Actual_Total;
        existing.Project_Actual_Costs = preservedFinance.Project_Actual_Costs;
        existing.Project_Total_Revenue = preservedFinance.Project_Total_Revenue;

        if (preservedMemos)
        {
            existing.Notes = preservedMemos.Notes;
            existing.Notes_Search = preservedMemos.Notes_Search;
            existing.Memos_Loaded = preservedMemos.Memos_Loaded;
            delete existing.__memoValues;
        }
        else if (monthRow.Memos_Loaded === true)
        {
            existing.Notes = Array.isArray(monthRow.Notes) ? monthRow.Notes : [];
            existing.Notes_Search = String(monthRow.Notes_Search || '');
            existing.Memos_Loaded = true;
            delete existing.__memoValues;
        }
    }

    function refreshFinanceRowIntoExisting (existing, monthRow)
    {
        const preservedMemos = preserveExistingMemosOnMerge(existing, monthRow);
        const financeFields = [
            'Actual_Costs',
            'Total_Revenue',
            'Actual_Total',
            'Project_Actual_Costs',
            'Project_Total_Revenue',
            'Invoiced_Total'
        ];

        for (const field of financeFields)
        {
            if (monthRow[field] === null || monthRow[field] === undefined)
            {
                continue;
            }

            existing[field] = monthRow[field];
        }

        const statusFields = ['Status', 'Document_Status', 'Invoice_Id', 'Invoice_Ids'];
        for (const field of statusFields)
        {
            const value = monthRow[field];
            if (value === null || value === undefined)
            {
                continue;
            }

            if (typeof value === 'string' && value.trim() === '')
            {
                continue;
            }

            existing[field] = value;
        }

        if (preservedMemos)
        {
            existing.Notes = preservedMemos.Notes;
            existing.Notes_Search = preservedMemos.Notes_Search;
            existing.Memos_Loaded = preservedMemos.Memos_Loaded;
            delete existing.__memoValues;
        }
    }

    function replaceMonthRowIntoExisting (existing, monthRow)
    {
        const rowKey = existing.Row_Key;
        const preservedMemos = preserveExistingMemosOnMerge(existing, monthRow);
        Object.assign(existing, monthRow);
        if (rowKey)
        {
            existing.Row_Key = rowKey;
        }

        if (preservedMemos)
        {
            existing.Notes = preservedMemos.Notes;
            existing.Notes_Search = preservedMemos.Notes_Search;
            existing.Memos_Loaded = preservedMemos.Memos_Loaded;
            delete existing.__memoValues;
        }
    }

    function mergeMonthRowIntoExisting (existing, monthRow)
    {
        existing.Actual_Costs = mergeFinanceAmount(existing.Actual_Costs, monthRow.Actual_Costs);
        existing.Total_Revenue = mergeFinanceAmount(existing.Total_Revenue, monthRow.Total_Revenue);
        existing.Actual_Total = existing.Total_Revenue - existing.Actual_Costs;

        const preservedMemos = preserveExistingMemosOnMerge(existing, monthRow);

        const copyFields = [
            'No', 'Order_Type', 'Contract_No', 'Customer_Id', 'Start_Date', 'Component_No',
            'Component_Description', 'Equipment_Number', 'Equipment_Name', 'Description',
            'Customer_Name', 'Cost_Center', 'Status', 'Document_Status',
            'End_Date', 'Job_No', 'Job_Task_No', 'Workorder_Source_Key', 'Invoice_Id', 'Invoice_Ids', 'Bc_No'
        ];

        for (const field of copyFields)
        {
            const value = monthRow[field];
            if (value === null || value === undefined)
            {
                continue;
            }

            if (typeof value === 'string' && value.trim() === '')
            {
                continue;
            }

            existing[field] = value;
        }

        if (preservedMemos)
        {
            existing.Notes = preservedMemos.Notes;
            existing.Notes_Search = preservedMemos.Notes_Search;
            existing.Memos_Loaded = preservedMemos.Memos_Loaded;
            delete existing.__memoValues;
        }
        else if (monthRow.Memos_Loaded === true)
        {
            existing.Notes = Array.isArray(monthRow.Notes) ? monthRow.Notes : [];
            existing.Notes_Search = String(monthRow.Notes_Search || '');
            existing.Memos_Loaded = true;
            delete existing.__memoValues;
        }
    }

    function sortRowsInPlace ()
    {
        rows.sort(function (a, b)
        {
            const projectCompare = String(a.Job_No || '').localeCompare(String(b.Job_No || ''), 'nl', { numeric: true, sensitivity: 'base' });
            if (projectCompare !== 0)
            {
                return projectCompare;
            }

            return String(a.No || '').localeCompare(String(b.No || ''), 'nl', { numeric: true, sensitivity: 'base' });
        });
    }

    function mergeMonthChunk (chunk, options)
    {
        if (!chunk || typeof chunk !== 'object')
        {
            return;
        }

        const isReplace = Boolean(options && options.replace);
        const isRefresh = Boolean(options && options.refresh);
        const isRefreshFinance = Boolean(options && options.refreshFinance);
        const resetTotals = Boolean(options && options.resetTotals);

        const chunkInvoiceDetails = chunk.invoice_details_by_id;
        if (chunkInvoiceDetails && typeof chunkInvoiceDetails === 'object')
        {
            Object.assign(invoiceDetailsById, chunkInvoiceDetails);
        }

        mergeProjectPostenMap(projectPostenRowsByProject, chunk.projectposten_rows_by_project);
        mergeProjectPostenMap(projectPostenRowsByProjectAndWorkorder, chunk.projectposten_rows_by_project_and_workorder);

        if (resetTotals)
        {
            for (const key of Object.keys(cumulativeProjectTotals))
            {
                delete cumulativeProjectTotals[key];
            }
        }

        if (!isRefresh)
        {
            addCumulativeProjectTotals(chunk.project_totals_by_job);
        }

        const monthRows = Array.isArray(chunk.rows) ? chunk.rows : [];
        for (const monthRow of monthRows)
        {
            const rowKey = String(monthRow.Row_Key || '').trim();
            if (rowKey === '')
            {
                continue;
            }

            const existing = rowsByKey.get(rowKey);
            if (existing)
            {
                if (isRefreshFinance)
                {
                    refreshFinanceRowIntoExisting(existing, monthRow);
                }
                else if (isRefresh)
                {
                    refreshWeekRowIntoExisting(existing, monthRow);
                }
                else if (isReplace)
                {
                    replaceMonthRowIntoExisting(existing, monthRow);
                }
                else
                {
                    mergeMonthRowIntoExisting(existing, monthRow);
                }
            }
            else
            {
                rows.push(monthRow);
                rowsByKey.set(rowKey, monthRow);
            }
        }

        applyCumulativeProjectTotalsToRows();
        sortRowsInPlace();
        renderHeader();
        applyChunkRowsToDom(monthRows);
        refreshStatusFilters();
        accumulateLoadStats(chunk.load_meta);
    }

    function yieldToUi ()
    {
        return new Promise(function (resolve)
        {
            requestAnimationFrame(function ()
            {
                requestAnimationFrame(resolve);
            });
        });
    }

    function previousIsoYearWeek (yearWeek)
    {
        const match = /^(\d{4})-W(\d{2})$/.exec(String(yearWeek || '').trim());
        if (!match)
        {
            return null;
        }

        const year = Number(match[1]);
        const week = Number(match[2]);
        const monday = isoWeekToUtcDate(year, week);
        if (!monday)
        {
            return null;
        }

        monday.setUTCDate(monday.getUTCDate() - 7);

        return dateToIsoYearWeek(monday);
    }

    function isoWeekToUtcDate (year, week)
    {
        const jan4 = new Date(Date.UTC(year, 0, 4));
        const dayOfWeek = jan4.getUTCDay() || 7;
        const mondayWeek1 = new Date(jan4);
        mondayWeek1.setUTCDate(jan4.getUTCDate() - dayOfWeek + 1);
        const monday = new Date(mondayWeek1);
        monday.setUTCDate(mondayWeek1.getUTCDate() + (week - 1) * 7);

        return monday;
    }

    function dateToIsoYearWeek (date)
    {
        const utcDate = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()));
        const dayOfWeek = utcDate.getUTCDay() || 7;
        utcDate.setUTCDate(utcDate.getUTCDate() + 4 - dayOfWeek);
        const isoYear = utcDate.getUTCFullYear();
        const yearStart = new Date(Date.UTC(isoYear, 0, 1));
        const week = Math.ceil((((utcDate - yearStart) / 86400000) + 1) / 7);

        return String(isoYear) + '-W' + String(week).padStart(2, '0');
    }

    function countIsoWeeksInLoadRange (startWeek, stopBeforeWeek)
    {
        let count = 0;
        let week = String(startWeek || '').trim();
        let safety = 0;

        while (week && safety < 400)
        {
            if (stopBeforeWeek && week < stopBeforeWeek)
            {
                break;
            }

            count++;
            week = previousIsoYearWeek(week);
            safety++;
        }

        return count;
    }

    function resolveHistoryWeeksTotal (monthScan)
    {
        const stopBefore = String((monthScan && monthScan.stop_before_month) || '').trim();
        if (!stopBefore)
        {
            return historyWeeksTotal;
        }

        const currentWeek = typeof asyncLoadConfig.current_week === 'string'
            ? asyncLoadConfig.current_week
            : (typeof asyncLoadConfig.current_month === 'string' ? asyncLoadConfig.current_month : null);
        if (!currentWeek)
        {
            return historyWeeksTotal;
        }

        const total = countIsoWeeksInLoadRange(currentWeek, stopBefore);

        return total > 0 ? total : historyWeeksTotal;
    }

    function formatHistoryLoadProgressSuffix (monthScan, weeksCompleted, isFirstWeek)
    {
        if (isFirstWeek)
        {
            return '';
        }

        const totalWeeks = resolveHistoryWeeksTotal(monthScan);
        if (!totalWeeks || totalWeeks <= 0)
        {
            return '';
        }

        const percent = Math.min(100, Math.round((weeksCompleted / totalWeeks) * 100));

        return ' (' + String(percent) + '%)';
    }

    function buildHistoryLoadNote (weekToLoad, monthScan, weeksCompleted, isFirstWeek)
    {
        const prefix = isFirstWeek
            ? ('Huidige week laden: ' + weekToLoad + '...')
            : ('Oudere week laden: ' + weekToLoad + '...');

        return prefix + formatHistoryLoadProgressSuffix(monthScan, weeksCompleted, isFirstWeek);
    }

    function createDemeterApiError (message, body, responseStatus)
    {
        const error = new Error(String(message || 'Onbekende fout'));
        if (body && typeof body.odata_debug === 'object' && body.odata_debug !== null)
        {
            error.odataDebug = body.odata_debug;
        }

        if (typeof responseStatus === 'number')
        {
            error.responseStatus = responseStatus;
        }

        return error;
    }

    function logDemeterODataFailure (contextLabel, error)
    {
        const debug = error && typeof error.odataDebug === 'object' && error.odataDebug !== null
            ? error.odataDebug
            : null;
        const attempts = debug && Array.isArray(debug.attempts) ? debug.attempts : [];

        console.error('[Demeter OData] ' + String(contextLabel || 'fout'), {
            message: String(error && error.message ? error.message : error),
            status: error && typeof error.responseStatus === 'number' ? error.responseStatus : null,
            url: debug && debug.url ? debug.url : null,
            attempts: attempts
        });
    }

    function isRetryableODataError (message)
    {
        const normalized = String(message || '').toLowerCase();
        if (normalized.indexOf('http 409') !== -1
            || normalized.indexOf('http 423') !== -1
            || normalized.indexOf('http 429') !== -1
            || normalized.indexOf('http 503') !== -1
            || normalized.indexOf('http 504') !== -1)
        {
            return true;
        }

        return normalized.indexOf('andere sessie') !== -1
            || normalized.indexOf('another session') !== -1
            || normalized.indexOf('probeer het later opnieuw') !== -1
            || normalized.indexOf('try again later') !== -1
            || normalized.indexOf('wordt bijgewerkt') !== -1;
    }

    function waitForMs (delayMs)
    {
        return new Promise(function (resolve)
        {
            window.setTimeout(resolve, delayMs);
        });
    }

    async function fetchHistoryWeekWithRetry (yearWeek, weekProgressIndex, weekProgressTotal, attempt)
    {
        const maxAttempts = 4;
        const currentAttempt = Number(attempt || 1);

        try
        {
            return await fetchHistoryWeek(yearWeek, weekProgressIndex, weekProgressTotal);
        }
        catch (loadError)
        {
            logDemeterODataFailure(
                'load_month week=' + String(yearWeek) + ' client_retry=' + String(currentAttempt),
                loadError
            );

            const errorMessage = String(loadError && loadError.message ? loadError.message : loadError);
            if (currentAttempt >= maxAttempts || !isRetryableODataError(errorMessage))
            {
                throw loadError;
            }

            const delayMs = Math.min(15000, 2000 * currentAttempt);
            updateHistoryLoadNote(
                'BC is bezig met een andere sessie, opnieuw proberen ('
                + String(currentAttempt)
                + '/'
                + String(maxAttempts)
                + ')...'
            );
            await waitForMs(delayMs);

            return fetchHistoryWeekWithRetry(yearWeek, weekProgressIndex, weekProgressTotal, currentAttempt + 1);
        }
    }

    function fetchHistoryWeek (yearWeek, weekProgressIndex, weekProgressTotal)
    {
        const params = new URLSearchParams();
        params.set('action', 'load_month');
        params.set('company', String(payload.company || ''));
        params.set('cost_center', loadedCostCenter);
        params.set('year_week', yearWeek);
        params.set('invoice_filter', invoiceFilter);
        if (asyncLoadConfig.force_full === true)
        {
            params.set('force_full', '1');
        }
        const loadToken = getPendingLoadProgressToken();
        if (loadToken !== '')
        {
            params.set('load_token', loadToken);
        }
        if (weekProgressIndex > 0)
        {
            params.set('week_progress_index', String(weekProgressIndex));
        }
        if (weekProgressTotal > 0)
        {
            params.set('week_progress_total', String(weekProgressTotal));
        }
        if (callTimeLogSession !== '')
        {
            params.set('call_time_log_session', callTimeLogSession);
        }

        return fetch('index.php?' + params.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        }).then(function (response)
        {
            return response.json().then(function (body)
            {
                if (!response.ok || !body || body.ok !== true)
                {
                    const errorText = body && body.error ? body.error : ('HTTP ' + response.status);
                    const apiError = createDemeterApiError(errorText, body, response.status);
                    logDemeterODataFailure('load_month week=' + String(yearWeek), apiError);
                    throw apiError;
                }

                return body;
            });
        });
    }

    async function startIncrementalMonthLoading ()
    {
        const currentWeek = typeof asyncLoadConfig.current_week === 'string'
            ? asyncLoadConfig.current_week
            : (typeof asyncLoadConfig.current_month === 'string' ? asyncLoadConfig.current_month : null);
        if (!asyncLoadConfig.enabled || historyLoadRunning || !currentWeek)
        {
            return;
        }

        hidePageLoader();
        historyLoadRunning = true;
        startBackgroundLoadProgressPolling();
        let weekBatch = [currentWeek];
        let isFirstBatch = true;
        let weeksCompleted = 0;

        try
        {
            while (weekBatch.length > 0)
            {
                const batch = weekBatch.slice(0, historyParallelWeekLoads);
                weekBatch = weekBatch.slice(batch.length);
                const batchWeekProgressTotal = getRefreshWeekProgressTotal(monthScanState);

                for (const yearWeek of batch)
                {
                    const weekMeta = monthScanState.months && monthScanState.months[yearWeek]
                        ? monthScanState.months[yearWeek]
                        : null;
                    const expectedRowKeys = weekMeta && Array.isArray(weekMeta.row_keys) ? weekMeta.row_keys : [];
                    if (expectedRowKeys.length > 0)
                    {
                        markRowsLoading(expectedRowKeys);
                    }
                }

                updateHistoryLoadNote(buildHistoryLoadNote(batch[0], monthScanState, weeksCompleted, isFirstBatch));

                const chunks = await Promise.all(batch.map(function (yearWeek, batchIndex)
                {
                    const weekProgressIndex = weeksCompleted + batchIndex + 1;
                    return fetchHistoryWeekWithRetry(yearWeek, weekProgressIndex, batchWeekProgressTotal);
                }));

                let shouldContinue = false;
                const nextWeeks = [];

                for (let chunkIndex = 0; chunkIndex < chunks.length; chunkIndex++)
                {
                    const chunk = chunks[chunkIndex];
                    const yearWeek = batch[chunkIndex];
                    monthScanState = chunk.month_scan && typeof chunk.month_scan === 'object' ? chunk.month_scan : monthScanState;
                    historyWeeksTotal = resolveHistoryWeeksTotal(monthScanState);

                    if (!chunk.skipped)
                    {
                        const weekLoadMode = chunk.load_meta && typeof chunk.load_meta.week_load_mode === 'string'
                            ? chunk.load_meta.week_load_mode
                            : 'full';
                        const useCacheRefresh = isFirstBatch && chunkIndex === 0 && loadStatsFromCache > 0;
                        const isLightweight = weekLoadMode === 'lightweight';

                        mergeMonthChunk(chunk, {
                            replace: isFirstBatch && chunkIndex === 0 && !useCacheRefresh && !isLightweight,
                            refresh: useCacheRefresh && !isLightweight,
                            refreshFinance: isLightweight,
                            resetTotals: isFirstBatch && chunkIndex === 0 && loadStatsFromCache === 0
                        });
                    }

                    const completedKeys = Array.isArray(chunk.row_keys) ? chunk.row_keys : [];
                    if (completedKeys.length > 0)
                    {
                        markRowsComplete(completedKeys);
                    }

                    weeksCompleted++;
                    if (chunk.should_continue)
                    {
                        shouldContinue = true;
                    }

                    const nextWeek = chunk.next_week || chunk.next_month || null;
                    if (nextWeek && shouldContinueHistoryLoading(monthScanState, nextWeek))
                    {
                        nextWeeks.push(nextWeek);
                    }
                }

                if (!isFirstBatch)
                {
                    updateHistoryLoadNote(buildHistoryLoadNote(batch[batch.length - 1], monthScanState, weeksCompleted, false));
                }

                isFirstBatch = false;
                await yieldToUi();

                if (!shouldContinue)
                {
                    break;
                }

                const seenWeeks = new Set();
                weekBatch = [];
                for (const nextWeek of nextWeeks)
                {
                    if (!nextWeek || seenWeeks.has(nextWeek))
                    {
                        continue;
                    }

                    seenWeeks.add(nextWeek);
                    weekBatch.push(nextWeek);
                }

                if (weekBatch.length > 0)
                {
                    nextHistoryMonth = weekBatch[0];
                }
            }
        }
        catch (historyError)
        {
            logDemeterODataFailure('load_month chain failed', historyError);
            updateHistoryLoadNote('Fout bij laden weken: ' + String(historyError && historyError.message ? historyError.message : historyError));
            stopPageLoaderProgress();
            historyLoadRunning = false;
            return;
        }

        updateHistoryLoadNote('');
        stopPageLoaderProgress();
        historyLoadRunning = false;
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
