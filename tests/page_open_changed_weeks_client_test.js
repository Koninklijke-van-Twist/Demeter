// Page-open BC-delta in de browser: één sync_changes-call per page-open (na de catch-up, nooit op een timer),
// gemarkeerde weken via load_month met force_full + resume, en hooguit één automatische herlaadbeurt per 3 min.
// Run: node tests/page_open_changed_weeks_client_test.js
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.js'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.php'), 'utf8');
let failures = 0;
function check (ok, label)
{
    console.log((ok ? 'ok   ' : 'FAIL ') + label);
    if (!ok) { failures++; }
}

const calls = src.match(/syncChangesAndReloadWeeks\(\)/g) || [];
check(calls.length === 1, 'syncChangesAndReloadWeeks wordt op precies één plek aangeroepen (' + calls.length + ')');
const block = src.slice(src.indexOf('startCatchUpCurrentWeek()\n'), src.indexOf('startCatchUpCurrentWeek()\n') + 900);
check(/startCatchUpCurrentWeek\(\)[\s\S]*\.then\([\s\S]*syncChangesAndReloadWeeks\(\)/.test(block), 'sync na de page-open catch-up');
check(!/set(Interval|Timeout)\([^)]*syncChangesAndReloadWeeks/.test(src), 'nooit op een timer (geen polling)');
const fnStart = src.indexOf('async function syncChangesAndReloadWeeks');
// Tot het einde van de functie (sluitende accolade op topniveau-inspringing).
const fn = src.slice(fnStart, src.indexOf('\n    }\n', fnStart) + 7);
check(fn.includes("params.set('action', 'sync_changes')"), 'roept action=sync_changes aan');
check(fn.includes('{ reloadChanged: true }'), 'gemarkeerde weken via fetchHistoryWeek(..., { reloadChanged: true })');
check(/options\.reloadChanged === true\)[\s\S]{0,300}force_full', '1'\)[\s\S]{0,80}resume', '1'\)/.test(src), 'reloadChanged zet force_full=1 en resume=1');
check(/CHANGES_RELOAD_GUARD_MS = 180 \* 1000/.test(src) && src.includes('changesReloadRecentlyDone()'), 'hooguit één automatische herlaadbeurt per 3 minuten');
check(fn.includes('failed === 0'), 'alleen herladen als alle weken gelukt zijn');
check(php.includes("=== 'sync_changes'") && php.includes("'sync_changes_enabled' =>"), 'server-endpoint en config-vlag aanwezig');
process.exit(failures > 0 ? 1 : 0);
