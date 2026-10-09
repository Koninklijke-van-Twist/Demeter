// Tim 9 okt 2026: geen automatische pagina-herlaad meer na de catch-up/wijzigingsronde of na het opbouwen
// van het projecttotalen-bestand (#34/#36), maar de bestaande tabel bijwerken (filters, sortering, scroll en
// modals blijven). index.js draait echt in node (vm) met een stub-DOM; fetch wordt per actie beantwoord.
// - wijzigingsronde met rows_changed: action=page_rows, rij in de tabel bijgewerkt, GEEN reload;
// - ensure_project_totals 'available': page_rows, projecttotalen in de rij, GEEN reload;
// - page_rows mislukt: vangnet = het oude gedrag (één reload).
// Run: node tests/inplace_refresh_instead_of_reload_test.js
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.js'), 'utf8');

function makeStub (name)
{
    const fn = function () { return makeStub(name + '()'); };
    return new Proxy(fn, {
        get (target, prop)
        {
            if (prop === Symbol.toPrimitive) { return () => ''; }
            if (prop === Symbol.iterator) { return function* () {}; }
            if (prop === 'then') { return undefined; }
            if (prop === 'length') { return 0; }
            if (prop === 'value' || prop === 'textContent' || prop === 'innerHTML' || prop === 'className' || prop === 'id') { return ''; }
            if (prop === 'checked' || prop === 'disabled' || prop === 'hidden') { return false; }
            if (prop === 'style' || prop === 'dataset') { return target[prop] || (target[prop] = {}); }
            if (prop === 'classList') { return { add () {}, remove () {}, toggle () {}, contains () { return false; } }; }
            if (prop === 'children' || prop === 'childNodes') { return []; }
            if (prop === 'querySelectorAll' || prop === 'getElementsByTagName' || prop === 'getElementsByClassName') { return () => []; }
            if (prop === 'getBoundingClientRect') { return () => ({ top: 0, left: 0, width: 0, height: 0, right: 0, bottom: 0 }); }
            if (prop in target) { return target[prop]; }
            return makeStub(name + '.' + String(prop));
        },
        set (target, prop, value) { target[prop] = value; return true; },
        apply () { return makeStub(name + '()'); },
        construct () { return makeStub('new ' + name); }
    });
}

function respond (body)
{
    const text = JSON.stringify(body);
    return Promise.resolve({ ok: true, status: 200, headers: { get () { return 'application/json'; } }, text: async () => text, json: async () => JSON.parse(text) });
}

async function run (asyncLoad, routes)
{
    const urls = [];
    let reloads = 0;
    const storage = { data: {}, getItem (k) { return this.data[k] || null; }, setItem (k, v) { this.data[k] = String(v); }, removeItem (k) { delete this.data[k]; } };
    const row = { Row_Key: 'PRJ1|WO1', No: 'WO1', Bc_No: 'WO1', Job_No: 'PRJ1', Job_Task_No: 'WO1', Workorder_Source_Key: 'WO1', Status: 'Checked', Document_Status: '10-OPEN', Start_Date: '2026-09-02', Project_Actual_Costs: 10, Project_Total_Revenue: 0, Actual_Costs: 10, Total_Revenue: 0, Notes: ['memo blijft'], Memos_Loaded: true };
    const pending = new Promise(() => {});
    const fetchFn = function (url)
    {
        const u = String(url);
        urls.push(u);
        for (const [needle, body] of routes)
        {
            if (u.indexOf(needle) !== -1)
            {
                return typeof body === 'function' ? body() : respond(body);
            }
        }
        return pending;
    };
    const windowObj = {
        workorderOverviewData: {
            company: 'Koninklijke van Twist',
            cost_center: '70',
            rows: [row],
            cache_meta: { has_data: true, history_complete: true, updated_at: new Date().toISOString(), age_seconds: 400 },
            async_load: Object.assign({
                current_week: '2026-W41', current_month: '2026-W41', catch_up_week: '2026-W41',
                month_scan: { months: {}, consecutive_empty: 0 }, empty_stop_count: 52, parallel_week_loads: 2,
                load_progress_token: '0123456789abcdef0123456789abcdef', load_month_url: 'index.php?action=load_month'
            }, asyncLoad),
            invoice_filter: 'both',
            memo_column_settings: {},
        },
        location: { href: 'https://x/demeter/?company=K', search: '', pathname: '/demeter/', reload () { reloads++; }, replace () { reloads++; } },
        history: { replaceState () {}, pushState () {} },
        sessionStorage: storage,
        localStorage: storage,
        addEventListener () {},
        removeEventListener () {},
        setTimeout () { return 1; },
        clearTimeout () {},
        setInterval () { return 1; },
        clearInterval () {},
        requestAnimationFrame (cb) { return 1; },
        matchMedia () { return { matches: false, addEventListener () {}, addListener () {} }; },
        getComputedStyle () { return makeStub('style'); },
        innerHeight: 800,
        innerWidth: 1200,
        DemeterModal: makeStub('DemeterModal'),
    };
    const errors = [];
    const context = {
        window: windowObj,
        document: makeStub('document'),
        navigator: { userAgent: 'node' },
        console: { log () {}, info () {}, warn () {}, debug () {}, error (...a) { errors.push(a.map(String).join(' ')); } },
        fetch: fetchFn,
        URL, URLSearchParams, AbortController, Date, Math, JSON, Promise, Set, Map, Intl, Number, String, Array, Object,
        setTimeout () { return 1; }, clearTimeout () {}, setInterval () { return 1; }, clearInterval () {},
        requestAnimationFrame () { return 1; },
        performance: { now: () => 0 },
    };
    class StubObserver { observe () {} unobserve () {} disconnect () {} }
    for (const name of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver']) { context[name] = StubObserver; windowObj[name] = StubObserver; }
    for (const name of ['HTMLElement', 'Element', 'Node', 'Event', 'CustomEvent', 'KeyboardEvent', 'Blob', 'FileReader']) { context[name] = function () {}; windowObj[name] = context[name]; }
    Object.assign(context, {
        getComputedStyle: windowObj.getComputedStyle, matchMedia: windowObj.matchMedia, location: windowObj.location,
        history: windowObj.history, sessionStorage: storage, localStorage: storage,
        addEventListener: windowObj.addEventListener, removeEventListener: windowObj.removeEventListener, innerHeight: 800, innerWidth: 1200,
    });
    Object.assign(windowObj, { document: context.document, fetch: fetchFn, console: context.console });
    context.globalThis = context;
    vm.runInNewContext(source, context, { filename: 'index.js' });
    for (let i = 0; i < 40; i++) { await new Promise((r) => setImmediate(r)); }

    return { urls, reloads, row, errors };
}

const pageRows = {
    ok: true,
    previous: false,
    history_complete: true,
    rows: [
        { Row_Key: 'PRJ1|WO1', No: 'WO1', Bc_No: 'WO1', Job_No: 'PRJ1', Job_Task_No: 'WO1', Workorder_Source_Key: 'WO1', Status: 'Closed', Document_Status: '60-GEREED', Start_Date: '2026-09-02', Project_Actual_Costs: 4710.2, Project_Total_Revenue: 8478.2, Actual_Costs: 4710.2, Total_Revenue: 8478.2 },
        { Row_Key: 'PRJ2|WO2610905', No: 'WO2610905', Bc_No: 'WO2610905', Job_No: 'PRJ2', Job_Task_No: 'WO2610905', Workorder_Source_Key: 'WO2610905', Status: 'Open', Document_Status: '10-OPEN', Start_Date: '2026-10-02', Project_Actual_Costs: 0, Project_Total_Revenue: 0, Actual_Costs: 0, Total_Revenue: 0 },
    ],
    project_totals_cumulative_by_job: { prj1: { costs: 4710.2, revenue: 8478.2 }, prj2: { costs: 0, revenue: 0 } },
};
const catchUpChunk = { ok: true, week: '2026-W41', month: '2026-W41', skipped: true, catch_up: true, rows: [], row_keys: [], month_scan: { months: {}, consecutive_empty: 0 } };

(async function ()
{
    let failures = 0;
    const check = (ok, label) => { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) { failures++; } };

    const a = await run({ enabled: false, catch_up_enabled: true, sync_changes_enabled: true }, [
        ['action=load_month', catchUpChunk],
        ['action=sync_changes', { ok: true, status: 'synced', dirty_weeks: [], rows_changed: true, status_updates: 1 }],
        ['action=page_rows', pageRows],
    ]);
    check(a.urls.some((u) => u.indexOf('action=sync_changes') !== -1), 'catch-up gevolgd door de wijzigingsronde');
    check(a.urls.some((u) => u.indexOf('action=page_rows') !== -1), 'wijzigingsronde met gewijzigde rijen: tabel bijwerken via page_rows');
    check(a.reloads === 0, 'geen pagina-herlaad na de wijzigingsronde (' + a.reloads + ')');
    check(a.row.Status === 'Closed' && a.row.Document_Status === '60-GEREED', 'rij in de bestaande tabel bijgewerkt (status/documentstatus)');
    check(Array.isArray(a.row.Notes) && a.row.Notes[0] === 'memo blijft', 'geladen memo\'s van de rij blijven staan');

    const b = await run({ enabled: false, catch_up_enabled: false, project_totals_full_missing: true }, [
        ['action=ensure_project_totals', { ok: true, available: true, status: 'built' }],
        ['action=page_rows', pageRows],
    ]);
    check(b.urls.some((u) => u.indexOf('action=page_rows') !== -1), 'projecttotalen-bestand klaar: tabel bijwerken via page_rows');
    check(b.reloads === 0, 'geen pagina-herlaad na ensure_project_totals (' + b.reloads + ')');
    check(b.row.Project_Actual_Costs === 4710.2 && b.row.Project_Total_Revenue === 8478.2 && b.row.Actual_Costs === 4710.2, 'volledige project- en werkordertotalen in de bestaande rij');

    const c = await run({ enabled: false, catch_up_enabled: false, project_totals_full_missing: true }, [
        ['action=ensure_project_totals', { ok: true, available: true, status: 'built' }],
        ['action=page_rows', { ok: false, error: 'kapot' }],
    ]);
    check(c.reloads === 1, 'page_rows mislukt: vangnet = één herlaadbeurt (' + c.reloads + ')');

    const d = await run({ enabled: false, catch_up_enabled: true, sync_changes_enabled: true }, [
        ['action=load_month', catchUpChunk],
        ['action=sync_changes', { ok: true, status: 'fresh', dirty_weeks: [], rows_changed: false }],
        ['action=page_rows', pageRows],
    ]);
    check(!d.urls.some((u) => u.indexOf('action=page_rows') !== -1) && d.reloads === 0, 'niets gewijzigd: geen page_rows en geen herlaad (gedrag ongewijzigd)');

    process.exit(failures > 0 ? 1 : 0);
})();
