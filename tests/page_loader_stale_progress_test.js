// Regressie 8 okt 2026 (KvT/70, 22:12 -> 22:16): na een mislukte catch-up (HTTP 409, CorrelationId d23859e6-...)
// toonde de eerste harde herlaad dezelfde 409 opnieuw. Oorzaak: bij 'beforeunload' volgt de laad-overlay de
// voortgang van het load_token van de vorige pagina; daar stond de 409 van de catch-up nog als status 'error',
// en die tekst kwam in de overlay tot de nieuwe pagina er was. Een fout/afronding die er al stond toen het
// volgen begon mag niet meer getoond worden; een fout die tijdens het volgen ontstaat wel.
// Run: node tests/page_loader_stale_progress_test.js
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.js'), 'utf8');

function run (progressSequence)
{
    const texts = [];
    const handlers = {};
    const intervals = [];
    let pollIndex = 0;
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
            set (target, prop, value)
            {
                if (prop === 'textContent' || prop === 'innerHTML') { texts.push(String(value)); }
                target[prop] = value;
                return true;
            },
            apply () { return makeStub(name + '()'); },
            construct () { return makeStub('new ' + name); }
        });
    }
    const pending = new Promise(() => {});
    const storage = { getItem () { return null; }, setItem () {}, removeItem () {} };
    const fetchFn = function (url)
    {
        if (String(url).indexOf('action=load_progress') !== -1)
        {
            const payload = progressSequence[Math.min(pollIndex, progressSequence.length - 1)];
            pollIndex++;
            return Promise.resolve({ ok: true, status: 200, json: async () => payload });
        }
        return pending;
    };
    const windowObj = {
        workorderOverviewData: {
            company: 'Koninklijke van Twist',
            cost_center: '70',
            rows: [{ Row_Key: 'k1', No: 'WO1', Bc_No: 'WO1', Job_No: 'PRJ1', Job_Task_No: 'WO1', Status: 'Open', Start_Date: '2026-10-01' }],
            cache_meta: { has_data: true, history_complete: true, updated_at: new Date().toISOString(), age_seconds: 30 },
            async_load: {
                enabled: false, catch_up_enabled: false, hitchhike_enabled: false,
                current_week: '2026-W41', current_month: '2026-W41', catch_up_week: '2026-W41',
                month_scan: { months: {}, consecutive_empty: 0 }, empty_stop_count: 52, parallel_week_loads: 2,
                load_progress_token: '0123456789abcdef0123456789abcdef', load_month_url: 'index.php?action=load_month'
            },
            load_progress_status_url: 'odata.php?action=load_progress',
            invoice_filter: 'both',
            memo_column_settings: {},
        },
        location: { href: 'https://x/demeter/?company=K', search: '', pathname: '/demeter/', reload () {} },
        history: { replaceState () {}, pushState () {} },
        sessionStorage: storage,
        localStorage: storage,
        addEventListener (type, fn) { (handlers[type] = handlers[type] || []).push(fn); },
        removeEventListener () {},
        setTimeout () { return 1; },
        clearTimeout () {},
        setInterval (fn) { intervals.push(fn); return intervals.length; },
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
        console: { log () {}, info () {}, warn () {}, debug () {}, error () {} },
        fetch: fetchFn,
        URL, URLSearchParams, AbortController, Date, Math, JSON, Promise, Set, Map, Intl, Number, String, Array, Object,
        setTimeout () { return 1; }, clearTimeout () {}, setInterval: windowObj.setInterval, clearInterval () {},
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

    return {
        texts,
        async unloadAndPoll (extraPolls)
        {
            const before = intervals.length;
            for (const fn of handlers.beforeunload || []) { fn({ preventDefault () {} }); }
            await new Promise((r) => setImmediate(r));
            await new Promise((r) => setImmediate(r));
            const poller = intervals[intervals.length - 1];
            for (let i = 0; i < extraPolls && intervals.length > before; i++)
            {
                poller();
                await new Promise((r) => setImmediate(r));
                await new Promise((r) => setImmediate(r));
            }
            return { hadHandler: (handlers.beforeunload || []).length > 0, polled: pollIndex };
        }
    };
}

(async function ()
{
    let failures = 0;
    const check = (ok, label) => { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) { failures++; } };
    const oldError = { status: 'error', error: 'HTTP 409 from OData: {"error":{"code":"Conflict","message":"CorrelationId: d23859e6-d670-43a7-969f-69ed70694c70"}}', message: 'HTTP 409', completed_at: 1760000000, total_months: 4, current_month_index: 1 };

    const a = run([oldError]);
    const ra = await a.unloadAndPoll(3);
    check(ra.hadHandler && ra.polled >= 2, 'herlaad: overlay volgt de voortgang van het vorige load_token (' + ra.polled + ' polls)');
    check(!a.texts.some((t) => t.indexOf('d23859e6') !== -1 || t.indexOf('409') !== -1), 'oude 409 van de vorige catch-up wordt bij de herlaad niet opnieuw getoond');

    const newError = Object.assign({}, oldError, { error: 'HTTP 409 from OData: nieuw, CorrelationId: 11111111', completed_at: 1760000300 });
    const b = run([{ status: 'running', message: 'Stap 1 van 4', total_months: 4, current_month_index: 1, updated_at: 1760000200 }, newError]);
    await b.unloadAndPoll(3);
    check(b.texts.some((t) => t.indexOf('11111111') !== -1), 'een fout die tijdens het volgen ontstaat wordt wel getoond');

    const c = run([oldError, newError]);
    await c.unloadAndPoll(3);
    check(c.texts.some((t) => t.indexOf('11111111') !== -1) && !c.texts.some((t) => t.indexOf('d23859e6') !== -1), 'een nieuwe fout na een oude (ander tijdstip) wordt wel getoond, de oude niet');

    process.exit(failures > 0 ? 1 : 0);
})();
