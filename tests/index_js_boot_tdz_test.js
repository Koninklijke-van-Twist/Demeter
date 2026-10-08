// Regressie 8 okt 2026: 'Cannot access 'keepDisplayRowsDuringLoad' before initialization' bij elke verversing
// die bij het laden van de pagina start (herbouw, hervatten, Ververs Nu). index.js wordt hier echt uitgevoerd
// in node (vm) met een stub-DOM, voor elk opstartpad (verversing, hervatten, catch-up, meeliften); een
// ReferenceError (TDZ) in het synchrone opstartdeel of de eerste stap van de load faalt de test.
// Run: node tests/index_js_boot_tdz_test.js
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

function runScenario (label, asyncLoad)
{
    const errors = [];
    const pending = new Promise(() => {}); // fetch blijft hangen: alleen het synchrone deel + eerste stap
    const storage = { getItem () { return null; }, setItem () {}, removeItem () {} };
    const windowObj = {
        workorderOverviewData: {
            company: 'Koninklijke van Twist',
            cost_center: '70',
            rows: [],
            cache_meta: { has_data: true, history_complete: true, updated_at: new Date().toISOString(), age_seconds: 400 },
            async_load: Object.assign({
                current_week: '2026-W41', current_month: '2026-W41', catch_up_week: '2026-W41',
                month_scan: { months: {}, consecutive_empty: 0 }, empty_stop_count: 52, parallel_week_loads: 2,
                load_progress_token: 'abc', load_month_url: 'index.php?action=load_month'
            }, asyncLoad),
            invoice_filter: 'both',
            memo_column_settings: {},
        },
        location: { href: 'https://x/demeter/?company=K', search: '', pathname: '/demeter/', reload () {} },
        history: { replaceState () {}, pushState () {} },
        sessionStorage: storage,
        localStorage: storage,
        addEventListener () {},
        removeEventListener () {},
        setTimeout () { return 1; },
        clearTimeout () {},
        setInterval () { return 1; },
        clearInterval () {},
        requestAnimationFrame () { return 1; },
        matchMedia () { return { matches: false, addEventListener () {}, addListener () {} }; },
        getComputedStyle () { return makeStub('style'); },
        innerHeight: 800,
        innerWidth: 1200,
        DemeterModal: makeStub('DemeterModal'),
    };
    const context = {
        window: windowObj,
        document: makeStub('document'),
        navigator: { userAgent: 'node' },
        console: {
            log () {}, info () {}, warn () {}, debug () {},
            error (...args) { errors.push(args.map((a) => (a && a.message) ? a.message : JSON.stringify(a)).join(' ')); }
        },
        fetch () { return pending; },
        URL, URLSearchParams, AbortController, Date, Math, JSON, Promise, Set, Map, Intl, Number, String, Array, Object,
        setTimeout () { return 1; }, clearTimeout () {}, setInterval () { return 1; }, clearInterval () {},
        requestAnimationFrame () { return 1; },
        performance: { now: () => 0 },
    };
    class StubObserver { observe () {} unobserve () {} disconnect () {} }
    for (const name of ['IntersectionObserver', 'ResizeObserver', 'MutationObserver']) { context[name] = StubObserver; windowObj[name] = StubObserver; }
    for (const name of ['HTMLElement', 'Element', 'Node', 'Event', 'CustomEvent', 'KeyboardEvent', 'Blob', 'FileReader']) { context[name] = function () {}; windowObj[name] = context[name]; }
    context.getComputedStyle = windowObj.getComputedStyle;
    context.matchMedia = windowObj.matchMedia;
    context.location = windowObj.location;
    context.history = windowObj.history;
    context.sessionStorage = storage;
    context.localStorage = storage;
    context.addEventListener = windowObj.addEventListener;
    context.removeEventListener = windowObj.removeEventListener;
    context.innerHeight = 800;
    context.innerWidth = 1200;
    Object.assign(windowObj, { document: context.document, fetch: context.fetch, console: context.console });
    context.globalThis = context;
    let thrown = null;
    try
    {
        vm.runInNewContext(source, context, { filename: 'index.js' });
    }
    catch (e)
    {
        thrown = e;
    }
    return new Promise((resolve) =>
    {
        setImmediate(() =>
        {
            const tdz = errors.filter((e) => /before initialization|is not defined/.test(e));
            const thrownTdz = thrown && /before initialization|is not defined/.test(String(thrown && thrown.message));
            resolve({ label, ok: tdz.length === 0 && !thrownTdz, detail: thrownTdz ? String(thrown.message) : tdz.join(' | '), thrown });
        });
    });
}

(async function ()
{
    let failures = 0;
    const scenarios = [
        ['verversing bij openen (herbouw/Ververs Nu)', { enabled: true, force_full: true, keep_display_rows: true }],
        ['hervatten van een onderbroken herbouw', { enabled: true, force_full: true, rebuild_resume: true, keep_display_rows: true }],
        ['cacheversie-herbouw zonder vorige rijen', { enabled: true, force_full: true, cache_version_rebuild: true, keep_display_rows: false }],
        ['catch-up bij openen', { enabled: false, catch_up_enabled: true }],
        ['meeliften op een lopende load', { enabled: false, hitchhike_enabled: true, hitchhike_kind: 'refresh' }],
    ];
    for (const [label, cfg] of scenarios)
    {
        const r = await runScenario(label, cfg);
        console.log((r.ok ? 'ok   ' : 'FAIL ') + label + (r.ok ? '' : ': ' + r.detail));
        if (!r.ok) { failures++; }
    }
    process.exit(failures > 0 ? 1 : 0);
})();
