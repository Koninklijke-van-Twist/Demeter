// Regressie #38 (9 okt 2026, KvT/70 08:54): na meeliften op een catch-up werd de tabel in place bijgewerkt,
// maar daarna bleef een blokkerende overlay 'Laden afgerond (100%)' staan. Oorzaak: een voortgangs-fetch van de
// laad-overlay die nog onderweg was toen het meeliften klaar was, kwam daarna binnen met status 'completed' en
// toonde de overlay (hitchhikeLoadRunning stond al op false). Vroeger verborg de herlaad dat.
// Run: node tests/page_loader_closes_after_inplace_test.js
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

function json (body)
{
    const text = JSON.stringify(body);
    return { ok: true, status: 200, headers: { get () { return 'application/json'; } }, text: async () => text, json: async () => JSON.parse(text) };
}

const tick = async (n) => { for (let i = 0; i < (n || 30); i++) { await new Promise((r) => setImmediate(r)); } };

async function run ()
{
    const loaderClasses = new Set();
    const loaderListeners = {};
    const loaderEl = makeStub('pageLoader');
    const loaderTarget = {
        classList: { add (c) { loaderClasses.add(c); }, remove (c) { loaderClasses.delete(c); }, toggle () {}, contains (c) { return loaderClasses.has(c); } },
        addEventListener (type, fn) { loaderListeners[type] = fn; },
        querySelector () { return makeStub('loaderChild'); },
    };
    const loader = new Proxy(loaderEl, { get (t, p) { return p in loaderTarget ? loaderTarget[p] : loaderEl[p]; } });
    const docStub = makeStub('document');
    const documentObj = new Proxy(docStub, {
        get (t, p)
        {
            if (p === 'getElementById') { return (id) => (id === 'pageLoader' ? loader : makeStub('el#' + id)); }
            if (p === 'addEventListener') { return () => {}; }
            return docStub[p];
        }
    });
    const progressDeferreds = [];
    let reloads = 0;
    let pageRowsCalls = 0;
    const fetchFn = function (url)
    {
        const u = String(url);
        if (u.indexOf('action=load_progress') !== -1)
        {
            return new Promise((resolve) => { progressDeferreds.push(resolve); });
        }
        if (u.indexOf('action=page_rows') !== -1)
        {
            pageRowsCalls++;
            return Promise.resolve(json({ ok: true, previous: false, history_complete: true, rows: [{ Row_Key: 'PRJ1|WO1', No: 'WO1', Bc_No: 'WO1', Job_No: 'PRJ1', Status: 'Open' }], project_totals_cumulative_by_job: {} }));
        }
        return new Promise(() => {});
    };
    const intervals = [];
    const storage = { getItem () { return null; }, setItem () {}, removeItem () {} };
    const windowObj = {
        workorderOverviewData: {
            company: 'Koninklijke van Twist', cost_center: '70',
            rows: [{ Row_Key: 'PRJ1|WO1', No: 'WO1', Bc_No: 'WO1', Job_No: 'PRJ1', Status: 'Checked' }],
            cache_meta: { has_data: true, history_complete: true, updated_at: new Date().toISOString(), age_seconds: 400 },
            async_load: {
                enabled: false, catch_up_enabled: false, hitchhike_enabled: true, hitchhike_kind: 'catch_up',
                current_week: '2026-W41', current_month: '2026-W41', catch_up_week: '2026-W41',
                month_scan: { months: {}, consecutive_empty: 0 }, empty_stop_count: 52, parallel_week_loads: 2,
                load_progress_token: '0123456789abcdef0123456789abcdef', load_month_url: 'index.php?action=load_month'
            },
            load_progress_status_url: 'odata.php?action=load_progress',
            invoice_filter: 'both', memo_column_settings: {},
        },
        location: { href: 'https://x/demeter/?company=K', search: '', pathname: '/demeter/', reload () { reloads++; }, replace () { reloads++; } },
        history: { replaceState () {}, pushState () {} },
        sessionStorage: storage, localStorage: storage,
        addEventListener () {}, removeEventListener () {},
        setTimeout () { return 1; }, clearTimeout () {},
        setInterval (fn, ms) { intervals.push(fn); return intervals.length; },
        clearInterval (id) { intervals[id - 1] = null; },
        requestAnimationFrame () { return 1; },
        matchMedia () { return { matches: false, addEventListener () {}, addListener () {} }; },
        getComputedStyle () { return makeStub('style'); },
        innerHeight: 800, innerWidth: 1200,
        DemeterModal: makeStub('DemeterModal'),
    };
    const context = {
        window: windowObj, document: documentObj, navigator: { userAgent: 'node' },
        console: { log () {}, info () {}, warn () {}, debug () {}, error () {} },
        fetch: fetchFn,
        URL, URLSearchParams, AbortController, Date, Math, JSON, Promise, Set, Map, Intl, Number, String, Array, Object,
        setTimeout () { return 1; }, clearTimeout () {}, setInterval: windowObj.setInterval, clearInterval: windowObj.clearInterval,
        requestAnimationFrame () { return 1; }, performance: { now: () => 0 },
    };
    class StubObserver { observe () {} unobserve () {} disconnect () {} }
    for (const name of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver']) { context[name] = StubObserver; windowObj[name] = StubObserver; }
    for (const name of ['HTMLElement', 'Element', 'Node', 'Event', 'CustomEvent', 'KeyboardEvent', 'Blob', 'FileReader']) { context[name] = function () {}; windowObj[name] = context[name]; }
    Object.assign(context, {
        getComputedStyle: windowObj.getComputedStyle, matchMedia: windowObj.matchMedia, location: windowObj.location,
        history: windowObj.history, sessionStorage: storage, localStorage: storage,
        addEventListener: windowObj.addEventListener, removeEventListener: windowObj.removeEventListener, innerHeight: 800, innerWidth: 1200,
    });
    Object.assign(windowObj, { document: documentObj, fetch: fetchFn, console: context.console });
    context.globalThis = context;
    vm.runInNewContext(source, context, { filename: 'index.js' });
    await tick();
    return { loaderClasses, loaderListeners, progressDeferreds, intervals, get reloads () { return reloads; }, get pageRowsCalls () { return pageRowsCalls; } };
}

(async function ()
{
    let failures = 0;
    const check = (ok, label) => { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) { failures++; } };
    const completed = { status: 'completed', message: 'Laden afgerond', total_months: 4, current_month_index: 4, completed_at: 1760000000 };
    const running = { status: 'running', message: 'Stap 3 van 4', total_months: 4, current_month_index: 3, updated_at: 1759999990 };

    const r = await run();
    // 1e poll van de overlay (direct bij het meeliften): nog bezig.
    check(r.progressDeferreds.length >= 1, 'meeliften volgt de voortgang (' + r.progressDeferreds.length + ' poll)');
    r.progressDeferreds.shift()(json(running));
    await tick();
    const loaderPoll = r.intervals.find((fn) => fn && fn.name === 'updatePageLoaderProgress');
    const hitchPoll = r.intervals.find((fn) => fn && fn.name !== 'updatePageLoaderProgress' && /hitchhikeLoadRunning = false/.test(fn.toString()));
    check(!!loaderPoll && !!hitchPoll, 'overlay-poll en meelift-poll gevonden');
    // Overlay-poll gaat de deur uit (blijft onderweg) ...
    loaderPoll();
    await tick();
    const inflight = r.progressDeferreds.shift();
    // ... intussen meldt de meelift-poll 'klaar' -> tabel in place bijgewerkt.
    hitchPoll();
    await tick();
    r.progressDeferreds.shift()(json(completed));
    await tick();
    check(r.pageRowsCalls === 1 && r.reloads === 0, 'catch-up klaar: tabel in place bijgewerkt, geen herlaad');
    // De nog lopende overlay-fetch komt nu binnen met 'completed'.
    inflight(json(completed));
    await tick();
    check(!r.loaderClasses.has('is-visible'), 'geen blokkerende overlay "Laden afgerond (100%)" na het bijwerken in de tabel');

    // Vangnet: zonder navigatie onderweg kan de overlay altijd weggeklikt worden.
    r.loaderClasses.add('is-visible');
    if (typeof r.loaderListeners.click === 'function') { r.loaderListeners.click({}); }
    check(!r.loaderClasses.has('is-visible'), 'overlay is weg te klikken als er geen navigatie loopt');

    process.exit(failures > 0 ? 1 : 0);
})();
