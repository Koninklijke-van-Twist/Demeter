// Run: node tests/posting_only_rows_and_backfill_note_test.js
// 1) 'Import SAP'-rijen (projectposten zonder werkorder) tellen niet als werkorder en niet als 'Open',
//    ook niet uit een oudere cache (Status 'Open', zonder Is_Posting_Only).
// 2) Backfill voorbij de geschatte periode toont een duidelijke tekst i.p.v. 'Stap 208 van 208 (100%)'.
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

const postingBlock = slice('    function isPostingOnlyRow (row)', '    function getVisibleSortedRows ()');
const statusBlock = slice('    function normalizeStatus (value)', '\n    }\n') + '\n    }\n';
const env = new Function('rows', 'summary', 'summaryPrefix', 'matchesInvoiceFilter', 'selectedCostCenter', 'matchesSelectedCostCenter',
    statusBlock + postingBlock + '\nreturn { isPostingOnlyRow, normalizePostingOnlyRow, updateSummaryCount };');

const rows = [
    { Row_Key: 'prj1|wo1', No: 'WO1', Job_Task_No: 'WO1', Status: 'Open' },
    { Row_Key: 'prj1|wo2', No: 'WO2', Job_Task_No: 'WO2', Status: 'Afgesloten' },
    // Oudere cache: pseudo-rij met 'Open' en zonder vlag.
    { Row_Key: 'prj1|import sap arbeid jaar 2025', No: 'Import SAP', Job_Task_No: '', Status: 'Open' },
    // Nieuwe server: met vlag.
    { Row_Key: 'prj2|import sap mat_extern jaar 2025', No: 'Import SAP', Job_Task_No: '', Status: 'Geen werkorder', Is_Posting_Only: true }
];
const summary = { textContent: '' };
const api = env(rows, summary, 'Werkorders (beide): ', function () { return true; }, 'all', function () { return true; });
rows.forEach(api.normalizePostingOnlyRow);
check(rows[2].Status === 'Geen werkorder' && rows[2].Is_Posting_Only === true, 'pseudo-rij uit oude cache: Open -> Geen werkorder');
check(rows[0].Status === 'Open' && !rows[0].Is_Posting_Only, 'echte open werkorder blijft Open');
api.updateSummaryCount();
check(summary.textContent === 'Werkorders (beide): 2 (+2 regels zonder werkorder)', 'telling: ' + summary.textContent);
check(!api.isPostingOnlyRow({ No: 'Import SAP', Job_Task_No: 'WO9' }), 'rij met Job_Task_No is geen pseudo-rij');

// Projectgroep-samenvatting: 'Werkorders' telt geen 'Import SAP'-regels.
const groupBlock = slice('        const taskLineCount = taskLineKeys.size;', '        const projectLabel = normalizeSortValue(projectKey);');
const countWorkorders = new Function('projectRows', 'taskLineKeys', 'isPostingOnlyRow', groupBlock + '\nreturn workorderCount;');
check(countWorkorders(rows.filter(function (r) { return r.Row_Key.indexOf('prj1|') === 0; }), new Set(), api.isPostingOnlyRow) === 2, 'projectgroep prj1: 2 werkorders (Import SAP-regel telt niet)');
check(countWorkorders(rows.filter(function (r) { return r.Row_Key.indexOf('prj2|') === 0; }), new Set(), api.isPostingOnlyRow) === 0, 'projectgroep met alleen Import SAP: 0 werkorders');

// Init en week-merge normaliseren.
check(/let rows = Array\.isArray\(payload\.rows\) \? payload\.rows\.slice\(\) : \[\];\n    rows\.forEach\(normalizePostingOnlyRow\);/.test(src), 'init normaliseert payload.rows');
const merge = slice('    function mergeMonthChunk (chunk, options)', '    function yieldToUi ()');
check(merge.indexOf('normalizePostingOnlyRow(monthRow);') > 0, 'mergeMonthChunk normaliseert week-rijen');

// Backfill-tekst.
const noteBlock = slice('    function buildHistoryBackfillNote (weekToLoad, monthScan)', '    function createDemeterApiError (');
const noteApi = new Function('monthScanEmptyStopCount', 'resolveHistoryWeeksTotal', 'formatHistoryLoadProgressSuffix',
    'var historyBackfillNote = "";\n' + noteBlock + '\nreturn { buildHistoryLoadNote, get: function () { return historyBackfillNote; } };')(
    52, function () { return 52; }, function () { return ' (50%)'; });
const beyond = noteApi.buildHistoryLoadNote('2024-W39', { consecutive_empty: 20 }, 60, false);
check(beyond === 'Oudere historie nalopen: 2024-W39 · stopt na 52 lege weken op rij (nu 20 op rij leeg, nog 32 lege weken op rij nodig)', 'backfill-tekst: ' + beyond);
check(noteApi.get() === beyond, 'backfill-tekst onthouden voor de voortgangs-poller');
check(noteApi.buildHistoryLoadNote('2026-W30', { consecutive_empty: 0 }, 10, false) === 'Oudere week laden: 2026-W30... (50%)', 'binnen de schatting: normale tekst');
const apply = slice('    function applyLoadProgressToUi (text, percent, currentCallLabel)', '    function startBackgroundLoadProgressPolling ()');
check(apply.indexOf("historyBackfillNote !== '' ? historyBackfillNote : text") > 0, "poller toont backfill-tekst i.p.v. 'Stap 208 van 208'");
const loop = slice('    async function startIncrementalMonthLoading ()', '    function escapeHtml (value)');
check((loop.match(/historyBackfillNote = '';/g) || []).length >= 3, 'backfill-tekst wordt gewist bij start, fout en einde');

if (failures > 0) { process.exit(1); }
console.log('alle checks ok');
