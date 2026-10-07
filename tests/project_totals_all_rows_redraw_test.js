// Run: node tests/project_totals_all_rows_redraw_test.js
// Projecttotalen staan op ALLE rijen van een project; rijen waarvan ze wijzigen worden opnieuw getekend.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8');
const start = src.indexOf('    function applyCumulativeProjectTotalsToRows');
const end = src.indexOf('    function mergeFinanceAmount');
if (start < 0 || end < 0 || end < start) { console.error('FAIL helper niet gevonden'); process.exit(1); }
const block = src.slice(start, end);

let failures = 0;
function check (ok, label) { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) failures++; }

const rows = [
    { Row_Key: 'a', No: 'WO-A', Job_No: 'P100', Project_Actual_Costs: 0, Project_Total_Revenue: 0 },
    { Row_Key: 'b', No: 'WO-B', Job_No: 'P100', Project_Actual_Costs: 0, Project_Total_Revenue: 0 },
    { Row_Key: 'c', No: 'P100', Job_No: 'P100', Project_Actual_Costs: 50, Project_Total_Revenue: 10 },
    { Row_Key: 'd', No: 'WO-D', Job_No: 'P200', Project_Actual_Costs: 5, Project_Total_Revenue: 5 }
];
const cumulativeProjectTotals = { p100: { costs: 50, revenue: 10 }, p200: { costs: 5, revenue: 5 } };
const apply = new Function('rows', 'cumulativeProjectTotals', block + '\nreturn applyCumulativeProjectTotalsToRows();');

const changed = apply(rows, cumulativeProjectTotals);
check(rows.filter(r => r.Job_No === 'P100').every(r => r.Project_Actual_Costs === 50 && r.Project_Total_Revenue === 10), 'alle rijen van P100 krijgen de projecttotalen');
check(changed.map(r => r.Row_Key).sort().join(',') === 'a,b', 'eerder getekende werkorders van hetzelfde project worden opnieuw getekend');
check(apply(rows, cumulativeProjectTotals).length === 0, 'niets opnieuw tekenen als niets wijzigt');

const merge = src.slice(src.indexOf('const projectTotalsChangedRows = applyCumulativeProjectTotalsToRows();'), src.indexOf('applyChunkRowsToDom(touchedRows);'));
check(merge.includes('touchedRows.push(changedRow)'), 'gewijzigde rijen gaan mee in applyChunkRowsToDom');

process.exit(failures === 0 ? 0 : 1);
