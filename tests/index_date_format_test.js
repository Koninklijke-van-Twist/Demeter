// Run: node tests/index_date_format_test.js
// Regressie: formatDutchDate wordt tijdens init (renderRows) aangeroepen vóór de definitieregel;
// helpers mogen dus geen top-level const/let gebruiken (TDZ → lege tabel op prod).
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8');
const start = src.indexOf('    var dutchDateFormatterInstance');
const end = src.indexOf('    function normalizeStatus');
if (start < 0 || end < 0) { console.error('FAIL helpers niet gevonden'); process.exit(1); }
const block = src.slice(start, end);
if (/^    (const|let) /m.test(block)) { console.error('FAIL top-level const/let in date helpers'); process.exit(1); }
// Aanroep vóór de definitie (zoals renderRows tijdens init) moet werken.
const fn = new Function('return formatDutchDate("2026-09-29") + "|" + formatDutchDate("0001-01-01") + "|" + formatDutchDate("x");\n' + block);
const out = fn();
if (out !== '29 september 2026||x') { console.error('FAIL ' + out); process.exit(1); }
console.log('ok ' + out);
