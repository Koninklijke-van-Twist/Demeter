// Run: node tests/load_token_and_zero_currency_test.js
// 1) Na de week-loop moet load_token uit de URL vóórdat de memo's worden opgehaald, en ook
//    wanneer de memo's mislukken. 2) Kosten/Opbrengst wo tonen een nul als '€ 0,00'.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8').replace(/\r\n/g, '\n');
let failures = 0;
function check (cond, label) { if (!cond) { console.error('FAIL ' + label); failures++; } else { console.log('ok ' + label); } }

const finStart = src.indexOf('    async function finalizeRefreshAfterLoad ()');
const finEnd = src.indexOf('    function initializePageLoaderHandlers ()');
check(finStart > 0 && finEnd > finStart, 'finalizeRefreshAfterLoad gevonden');
const fin = src.slice(finStart, finEnd);
const stripPos = fin.indexOf('stripLoadTokenFromUrl();');
const memoPos = fin.indexOf("updateHistoryLoadNote('Memo\\'s ophalen...')");
const fetchPos = fin.indexOf('await fetch(');
check(stripPos > 0 && memoPos > stripPos && fetchPos > stripPos, 'load_token gaat uit de URL vóór het memo-verzoek');

const initStart = src.indexOf('        startIncrementalMonthLoading()\n            .then(');
const initEnd = src.indexOf('    else if (asyncLoadConfig.hitchhike_enabled)', initStart);
const init = src.slice(initStart, initEnd);
const catchPart = init.slice(init.indexOf('.catch('));
check(initStart > 0 && catchPart.indexOf('stripLoadTokenFromUrl();') > 0, 'load_token gaat ook weg als de memo\'s mislukken');
check(catchPart.indexOf('updateHistoryLoadNote(') > 0, 'statusregel blijft niet op "Memo\'s ophalen..." hangen');

// Gedrag: finalize met een falend memo-verzoek strip toch de URL.
(async function ()
{
    const calls = [];
    const fn = new Function('payload', 'loadedCostCenter', 'stripLoadTokenFromUrl', 'updateHistoryLoadNote',
        'getPendingLoadProgressToken', 'refreshProgressTotalSteps', 'fetch', 'stopPageLoaderProgress', 'hidePageLoader', 'window',
        fin + '\nreturn finalizeRefreshAfterLoad;');
    const finalize = fn({ company: 'Testbedrijf' }, '70',
        function () { calls.push('strip'); },
        function (t) { calls.push('note:' + t); },
        function () { return 'tok'; }, 0,
        function () { calls.push('fetch'); return Promise.reject(new TypeError('Failed to fetch')); },
        function () {}, function () {}, { location: { search: '?load_token=tok' } });
    let threw = false;
    try { await finalize(); } catch (e) { threw = true; }
    check(threw && calls[0] === 'strip' && calls.indexOf('fetch') > 0, 'strip vóór fetch, ook als fetch faalt (' + calls.join(',') + ')');

    const curStart = src.indexOf('    function formatSignedCurrency (value)');
    const curEnd = src.indexOf('    // Lazy (var + function)');
    const curBlock = src.slice(curStart, curEnd);
    check(curBlock.indexOf("'€ 0'") < 0, "geen hardgecodeerde '€ 0' meer");
    const fmt = new Function(
        "const currencyFormatter = new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 });\n"
        + curBlock + '\nreturn { formatCurrencyOrZero, formatSignedCurrencyOrZero };')();
    const norm = function (s) { return String(s).replace(/\u00a0/g, ' '); };
    check(norm(fmt.formatCurrencyOrZero(0)) === '€ 0,00', 'formatCurrencyOrZero(0) = € 0,00 (' + norm(fmt.formatCurrencyOrZero(0)) + ')');
    check(norm(fmt.formatCurrencyOrZero(null)) === '€ 0,00', 'formatCurrencyOrZero(null) = € 0,00');
    check(norm(fmt.formatSignedCurrencyOrZero(0)) === '€ 0,00', 'formatSignedCurrencyOrZero(0) = € 0,00');
    check(norm(fmt.formatCurrencyOrZero(12.5)) === '€ 12,50', 'formatCurrencyOrZero(12.5) = € 12,50');
    check(norm(fmt.formatSignedCurrencyOrZero(-3)) === '-€ 3,00', 'formatSignedCurrencyOrZero(-3) = -€ 3,00 (' + norm(fmt.formatSignedCurrencyOrZero(-3)) + ')');

    if (failures > 0) { process.exit(1); }
    console.log('alle checks ok');
})();
