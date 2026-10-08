// Run: node tests/week_request_retry_test.js
// Haperend antwoord op een week-request: max 3 retries (3s/10s/20s) met dezelfde week, daarna de gewone fout.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8');
const start = src.indexOf('    const TRANSIENT_WEEK_RETRY_DELAYS_MS');
const end = src.indexOf('    function fetchHistoryWeek (');
if (start < 0 || end < 0 || end < start) { console.error('FAIL helpers niet gevonden'); process.exit(1); }
const block = src.slice(start, end);
const classifiers = src.slice(src.indexOf('    function createDemeterApiError'), src.indexOf('    function logDemeterODataFailure'))
    + src.slice(src.indexOf('    function isConnectionRetryableODataError'), src.indexOf('    function waitForMs'));

let failures = 0;
function check (ok, label) { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) failures++; }

function build (responses) {
    const state = { calls: [], waits: [], notes: [] };
    const factory = new Function('state', 'responses', classifiers + block + `
        function logDemeterODataFailure () {}
        function updateHistoryLoadNote (text) { state.notes.push(text); }
        function waitForMs (ms) { state.waits.push(ms); return Promise.resolve(); }
        async function fetchHistoryWeek (yearWeek, index, total) {
            state.calls.push(yearWeek + '#' + index);
            const next = responses.shift();
            if (next instanceof Error) { throw next; }
            if (typeof next === 'function') { return next(); }
            return next;
        }
        return { run: fetchHistoryWeekWithRetry, parse: parseWeekResponseBody, transient: createTransientWeekResponseError };
    `);
    return { api: factory(state, responses), state };
}

(async function () {
    const probe = build([]).api;
    const resp = (status) => ({ status, ok: status >= 200 && status < 300 });
    const throwsTransient = (fn) => { try { fn(); return false; } catch (e) { return e.transientResponse === true; } };

    check(throwsTransient(() => probe.parse('2026-W10', resp(200), '')), 'lege body = haperend');
    check(throwsTransient(() => probe.parse('2026-W10', resp(200), '{"ok":true,"rows":[{"a"')), 'afgekapte JSON = haperend');
    check(throwsTransient(() => probe.parse('2026-W10', resp(500), '<html><body>Internal Server Error</body></html>')), 'HTML-foutpagina = haperend');
    check(throwsTransient(() => probe.parse('2026-W10', resp(502), '{"ok":false}')), '5xx zonder foutmelding = haperend');
    check(!throwsTransient(() => probe.parse('2026-W10', resp(500), '{"ok":false,"error":"Kies eerst een kostenplaats"}')), 'echte serverfout (JSON met error) = geen haperend antwoord');
    check(probe.parse('2026-W10', resp(200), '{"ok":true,"rows":[]}').ok === true, 'geldig antwoord gaat door');

    // Twee keer haperen, dan gelukt: zelfde week, wachttijden 3s/10s, melding (1/3) en (2/3).
    let t = build([probe.transient('2026-W10', 'lege body', 502), probe.transient('2026-W10', 'afgekapt', 200), { ok: true, week: '2026-W10' }]);
    const result = await t.api.run('2026-W10', 7, 52);
    check(result.ok === true, 'na 2 haperingen alsnog gelukt');
    check(t.state.calls.join(',') === '2026-W10#7,2026-W10#7,2026-W10#7', 'steeds dezelfde week en stap opnieuw');
    check(t.state.waits.join(',') === '3000,10000', 'backoff 3s en 10s');
    check(t.state.notes[0] === 'Verbinding hapert, opnieuw proberen (1/3)...' && t.state.notes[1].includes('(2/3)'), 'melding tijdens retry');

    // Vier keer haperen: na 3 retries de gewone fout.
    t = build([1, 2, 3, 4].map(() => probe.transient('2026-W11', 'HTML-pagina i.p.v. JSON', 500)));
    let finalError = null;
    try { await t.api.run('2026-W11', 8, 52); } catch (e) { finalError = e; }
    check(finalError !== null && /ongeldig of onvolledig antwoord/.test(finalError.message), 'na de laatste poging de bestaande foutweergave');
    check(t.state.calls.length === 4 && t.state.waits.join(',') === '3000,10000,20000', 'max 3 retries (3s/10s/20s)');

    // Niet-tijdelijke fout: geen extra retries.
    t = build([new Error('Kies eerst een kostenplaats voordat gegevens worden opgehaald.')]);
    finalError = null;
    try { await t.api.run('2026-W12', 9, 52); } catch (e) { finalError = e; }
    check(finalError !== null && t.state.calls.length === 1 && t.state.waits.length === 0, 'echte fout faalt direct');

    // De fetch-code leest tekst en gebruikt parseWeekResponseBody; netwerkfouten worden haperend.
    const fetchFn = src.slice(src.indexOf('    function fetchHistoryWeek ('), src.indexOf('    // Tekst tijdens de catch-up'));
    check(fetchFn.includes('response.text()') && fetchFn.includes('parseWeekResponseBody(') && !fetchFn.includes('response.json()'), 'fetch parset zelf (geen response.json())');
    check(fetchFn.includes("createTransientWeekResponseError(yearWeek, fetchError"), 'netwerkfout = haperend');
    check(fetchFn.includes("params.set('load_token', loadToken)"), 'retry gebruikt dezelfde load_token');

    // Fetch-niveau: de echte fetchHistoryWeek bouwt bij elke retry de URL opnieuw op; alle pogingen
    // moeten dezelfde load_token (en week) meesturen.
    {
        const urls = [];
        const replies = [
            function () { return Promise.reject(new TypeError('Failed to fetch')); },
            function () { return Promise.resolve({ status: 502, ok: false, text: function () { return Promise.resolve('<html>Bad Gateway</html>'); } }); },
            function () { return Promise.resolve({ status: 200, ok: true, text: function () { return Promise.resolve('{"ok":true,"rows":[]'); } }); },
            function () { return Promise.resolve({ status: 200, ok: true, text: function () { return Promise.resolve('{"ok":true,"rows":[],"year_week":"2026-W12"}'); } }); }
        ];
        const fetchFactory = new Function('urls', 'replies', classifiers + block + fetchFn + `
            const payload = { company: 'Testbedrijf' };
            const loadedCostCenter = '70';
            const asyncLoadConfig = { force_full: true };
            const callTimeLogSession = '';
            const window = { setTimeout: function () { return 1; }, clearTimeout: function () {} };
            function getPendingLoadProgressToken () { return 'tok-123'; }
            function logDemeterODataFailure () {}
            function updateHistoryLoadNote () {}
            function waitForMs () { return Promise.resolve(); }
            function fetch (url) { urls.push(url); return replies.shift()(); }
            return fetchHistoryWeekWithRetry;
        `);
        const run = fetchFactory(urls, replies);
        let result = null;
        let fetchError = null;
        try { result = await run('2026-W12', 3, 52); } catch (e) { fetchError = e; }
        const tokens = urls.map(function (u) { return new URLSearchParams(u.split('?')[1]).get('load_token'); });
        const weeks = urls.map(function (u) { return new URLSearchParams(u.split('?')[1]).get('year_week'); });
        check(fetchError === null && result && result.ok === true, 'fetch-niveau: na 3 haperende antwoorden lukt poging 4');
        check(urls.length === 4, 'fetch-niveau: 4 requests (' + urls.length + ')');
        check(tokens.every(function (t) { return t === 'tok-123'; }), 'fetch-niveau: elke retry stuurt dezelfde load_token (' + tokens.join(',') + ')');
        check(weeks.every(function (w) { return w === '2026-W12'; }), 'fetch-niveau: elke retry vraagt dezelfde week');
    }

    process.exit(failures === 0 ? 0 : 1);
})();
