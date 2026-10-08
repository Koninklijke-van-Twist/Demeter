// Run: node tests/progress_total_backfill_test.js
// Stapteller mag niet op 'N van N' blijven staan terwijl oudere weken (incl. facturen) nog worden
// teruggelezen; een trage stap toont 'duurt langer dan normaal', een echte fout gaat altijd voor.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8').replace(/\r\n/g, '\n');
let failures = 0;
function check (cond, label) { if (!cond) { console.error('FAIL ' + label); failures++; } else { console.log('ok ' + label); } }
function slice (startMarker, endMarker)
{
    const a = src.indexOf(startMarker);
    const b = src.indexOf(endMarker, a + 1);
    if (a < 0 || b < 0) { throw new Error('marker niet gevonden: ' + startMarker); }
    return src.slice(a, b);
}

const totalBlock = slice('    function getEstimatedHistoryWeeksTotal (monthScan)', '    function getPendingLoadProgressToken ()');
const api = new Function('resolveHistoryWeeksTotal', 'historyWeeksTotal', 'monthScanEmptyStopCount',
    totalBlock + '\nreturn { getRefreshWeekProgressTotal, formatSlowStepSuffix };')(function () { return null; }, null, 52);

// Eerste volledige verversing: einde onbekend, schatting 52 weken (208 stappen).
check(api.getRefreshWeekProgressTotal({ consecutive_empty: 0 }, 10, 2) === 52, 'binnen de schatting: 52 weken');
// Voorbij de schatting (bv. 2023-W02, ~195 weken terug): totaal groeit mee, nooit gelijk aan de huidige index.
const total = api.getRefreshWeekProgressTotal({ consecutive_empty: 3 }, 194, 2);
check(total > 196, 'teruglezen voorbij 52 weken: totaal groeit (' + total + ' weken)');
check(total === 194 + 2 + (52 - 3 - 2), 'totaal = gedaan + batch + minimaal nog nodige lege weken');
// Laatste batch: genoeg lege weken op rij → totaal valt samen met het einde.
check(api.getRefreshWeekProgressTotal({ consecutive_empty: 50 }, 240, 2) === 242, 'laatste batch: totaal = einde');
// Bekend einde (stop_before_month): exact aantal weken.
const exact = new Function('resolveHistoryWeeksTotal', 'historyWeeksTotal', 'monthScanEmptyStopCount',
    totalBlock + '\nreturn getRefreshWeekProgressTotal;')(function () { return 120; }, 120, 52);
check(exact({ stop_before_month: '2024-W20', consecutive_empty: 0 }, 60, 2) === 120, 'bekend einde: exact totaal');

// Server-stap nooit hoger dan het totaal dat de client meestuurt.
const serverIndex = (194 + 2) * 4;
check(serverIndex <= total * 4, 'serverstap ' + serverIndex + ' <= totaal ' + (total * 4));

// Trage stap.
const slow = api.formatSlowStepSuffix({ status: 'running', slow: true, last_activity_text: '8 oktober 2026, 10:56' });
check(slow === ' · deze stap duurt langer dan normaal (laatste serveractiviteit 8 oktober 2026, 10:56), het laden loopt nog', 'trage stap: ' + slow);
check(api.formatSlowStepSuffix({ status: 'running', slow: false }) === '', 'normale stap: geen extra tekst');
check(api.formatSlowStepSuffix({ status: 'error', slow: true }) === '', 'fout: geen traag-tekst');

// Voortgangs-poller: fout (vastgelopen) gaat voor op de backfill-tekst; traag-tekst wordt toegevoegd.
const applyBlock = slice('    function applyLoadProgressToUi (text, percent, currentCallLabel, status, slowSuffix)', '    function startBackgroundLoadProgressPolling ()');
let shown = '';
const apply = new Function('asyncLoadConfig', 'hitchhikeLoadRunning', 'historyBackfillNote', 'updateHistoryLoadNote', 'showPageLoader', 'applyPageLoaderProgressVisuals',
    applyBlock + '\nreturn applyLoadProgressToUi;')({ enabled: true }, false, 'Oudere historie teruglezen (incl. facturen): 2023-W05', function (t) { shown = t; }, function () {}, function () {});
apply('Stap 9 van 10', 90, '', 'running', ' · deze stap duurt langer dan normaal');
check(shown === 'Oudere historie teruglezen (incl. facturen): 2023-W05 · deze stap duurt langer dan normaal', 'backfill + traag: ' + shown);
apply('Laden is vastgelopen: …', 0, '', 'error', '');
check(shown === 'Laden is vastgelopen: …', 'echte fout gaat voor op backfill-tekst');

check(src.indexOf('getRefreshWeekProgressTotal(monthScanState, weeksCompleted, batch.length)') > 0, 'week-loop geeft voortgang mee aan het totaal');
check(src.indexOf('applyLoadProgressToUi(text, percent, currentCallLabel, status, formatSlowStepSuffix(progress))') > 0, 'poller geeft status en traag-tekst door');

if (failures > 0) { process.exit(1); }
console.log('alle checks ok');
