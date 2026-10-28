// Handmatige Ververs Nu: laadbalk-modal blijft tot het einde, staptekst in de modal, niet bovenaan.
const fs = require('fs');
const path = require('path');
const js = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.js'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.php'), 'utf8');
let failures = 0;
function check (ok, label) { if (!ok) { failures++; console.log('FAIL ' + label); } else { console.log('ok   ' + label); } }
function slice (start, end) { const a = js.indexOf(start); const b = js.indexOf(end, a + 1); return js.slice(a, b); }

check(php.includes("'refresh_modal' => $userRefreshNowRequested && ($asyncLoadEnabled || $hitchhikeActiveLoad)"), 'server zet refresh_modal na handmatige Ververs Nu');
check(js.includes('let refreshModalActive = asyncLoadConfig.refresh_modal === true;'), 'client leest refresh_modal');
check(/if \(!pageLoader \|\| refreshModalActive\)/.test(js), 'hidePageLoader laat de modal staan');
check(js.includes("showRefreshModal('Er loopt al een verversing; meeliften...');"), 'meeliften via de knop toont de modal');
check(js.includes('finishRefreshModal(null);'), 'succes sluit de modal (herladen)');
check(js.includes("finishRefreshModal('Fout bij laden weken: '"), 'fout bij laden meldt in modal');

// updateHistoryLoadNote: tekst naar modal, bovenste regel leeg.
const block = slice('    function showRefreshModal (text)', '    function updateHistoryLoadNote (message)');
const noteStart = js.indexOf('    function updateHistoryLoadNote (message)');
const noteHead = js.slice(noteStart, js.indexOf('\n\n', js.indexOf('message = \'\';', noteStart)));
let received = null;
const loader = { cls: new Set(), classList: { add (c) { loader.cls.add(c); }, remove (c) { loader.cls.delete(c); } } };
const text = { textContent: '' };
const fn = new Function('pageLoader', 'pageLoaderText', 'hidePageLoader', 'reloadPageWithoutRefreshNow', 'demeterModal', 'sink',
    'let refreshModalActive = true;\n' + block + noteHead + '\n sink(message); }\nreturn updateHistoryLoadNote;')(
    loader, text, function () {}, function () {}, null, function (m) { received = m; });
fn('Stap 3 van 208');
check(text.textContent === 'Stap 3 van 208' && loader.cls.has('is-visible'), 'staptekst staat in de modal');
check(received === '', 'bovenste statusregel blijft leeg');

if (failures > 0) { process.exit(1); }
