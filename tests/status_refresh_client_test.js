// Ververs Nu-statusverversing (client): start/meeliften, step-polling met voortgang chunks klaar/totaal,
// fouten (500) overleven, plus bevestigingsmodal en meelift-fix in index.js.
// Run: node tests/status_refresh_client_test.js
const fs = require('fs');
const path = require('path');
const assert = require('assert');
const api = require('../web/status-refresh.js');

let failures = 0;
function check (ok, label) { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) { failures++; } }

(async function ()
{
    const urls = [];
    const answers = [
        { ok: true, status: 'running', joined: true, chunks_done: 1, chunks_total: 3, percent: 33 },
        { ok: false, error: 'HTTP 500' },
        { ok: true, status: 'running', chunks_done: 2, chunks_total: 3, percent: 66 },
        { ok: true, status: 'completed', chunks_done: 3, chunks_total: 3, percent: 100, updated: 4 }
    ];
    let done = null;
    const res = await api.run({
        company: 'Koninklijke van Twist',
        costCenter: '70',
        pollMs: 0,
        sleep: () => Promise.resolve(),
        fetchJson: async (u) => { urls.push(u); return answers.shift(); },
        onDone: (p) => { done = p; }
    });
    check(urls[0].includes('action=status_refresh') && urls[0].includes('op=start') && urls[0].includes('cost_center=70'), 'eerst op=start met bedrijf + kostenplaats');
    check(urls.slice(1).every((u) => u.includes('op=step')), 'daarna alleen op=step (korte requests)');
    check(res.status === 'completed' && done && done.updated === 4, 'HTTP 500 tussendoor overleefd; klaar en onDone aangeroepen');
    check(api.progressText({ chunks_done: 2, chunks_total: 3, percent: 66 }) === 'Statussen verversen: 2 / 3 blokken (66%)', 'voortgang = chunks klaar / totaal');

    const src = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.js'), 'utf8');
    check(src.includes("message: 'Dit kan lang duren, weet u het zeker?'") && src.includes("confirmText: 'Ja'") && src.includes("cancelText: 'Annuleren'"), 'bevestigingsmodal Ja/Annuleren');
    check(/hitchhike_retrigger_refresh_now === true\)\s*\{\s*\/\/[^\n]*\n\s*reloadPageWithRefreshNow\(\)/.test(src), 'meelift op catch-up: daarna alsnog refresh_now=1');
    const php = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.php'), 'utf8');
    check(php.includes("$hitchhikeKind === 'catch_up'") && php.includes('ignore_user_abort(true)'), 'server: retrigger-vlag alleen bij catch_up; ignore_user_abort in status_refresh');
    process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
