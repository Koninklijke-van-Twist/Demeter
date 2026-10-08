// Run: node tests/partial_cache_and_catch_up_test.js
// Onvolledige cache (verversing niet afgerond) toont ⚠; catch-up toont stap/⏳ en verstreken tijd.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8');

let failures = 0;
function check (ok, label) { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) failures++; }

// 1) Catch-up-tekst
const fStart = src.indexOf('    function formatCatchUpProgressNote');
const fEnd = src.indexOf('    // Pollt de voortgang van de catch-up');
const format = new Function(src.slice(fStart, fEnd) + '\nreturn formatCatchUpProgressNote;')();
check(format('2026-W41', null, 0) === '⏳ Huidige week bijwerken: 2026-W41...', 'starttekst met ⏳');
const running = { status: 'running', total_months: 16, current_month_index: 5, message: 'Stap 5 van 16: maand 2026-W41/2026-10-06: ProjectPosten laden' };
check(format('2026-W41', running, 30000) === '⏳ Huidige week bijwerken: Stap 5 van 16: maand 2026-W41/2026-10-06: ProjectPosten laden (31%)', 'stap en percentage van de server');
check(format('2026-W41', running, 75000).includes('loopt 1 min') && !format('2026-W41', running, 75000).includes('langer dan normaal'), 'verstreken tijd na 1 min');
check(format('2026-W41', running, 130000).includes('duurt langer dan normaal') && format('2026-W41', running, 130000).includes('Ververs Nu'), 'duidelijke tekst na 2 min');

// 2) Onvolledige cache → 'partial-cache' (⚠), meeliften → 'loading'
const init = src.slice(src.indexOf("if (!asyncLoadConfig.enabled && cacheMeta.has_data === true && cacheMeta.history_complete === false)"), src.indexOf('    if (asyncLoadConfig.enabled)\n'));
check(init.includes("'partial-cache'") && init.includes("asyncLoadConfig.hitchhike_enabled ? 'loading'"), 'init zet partial-cache bij niet afgeronde verversing');

const mStart = src.indexOf('    function getProjectTotalsIncompleteTooltip');
const mEnd = src.indexOf('    // Markeert een projecttotaal-cel');
const marker = new Function('state', 'let projectTotalsIncompleteState = state;\n' + src.slice(mStart, mEnd) + '\nreturn { tooltip: getProjectTotalsIncompleteTooltip(), warning: isProjectTotalsIncompleteWarningState() };');
const partial = marker('partial-cache');
check(partial.warning === true && partial.tooltip.includes('niet afgerond') && partial.tooltip.includes('Ververs Nu'), 'partial-cache: ⚠ met uitleg');
check(marker('loading').warning === false && marker('').tooltip === '', 'loading/compleet ongewijzigd');
check(/state === 'partial-cache' \? state/.test(src) || src.includes("|| state === 'partial-cache' ? state : ''"), 'setProjectTotalsIncompleteState accepteert partial-cache');

process.exit(failures === 0 ? 0 : 1);
