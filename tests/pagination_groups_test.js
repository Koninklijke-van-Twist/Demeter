// Paginering: groepen bij elkaar met softcap paginagrootte + 20%; zonder groepen vaste pagina's.
// Run: node tests/pagination_groups_test.js
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.js'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, '..', 'web', 'index.php'), 'utf8');
let failures = 0;
function check (ok, label) { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) { failures++; } }

const start = src.indexOf('    function computePageStarts');
const end = src.indexOf('    function renderRows ()');
const computePageStarts = new Function(src.slice(start, end) + '\nreturn computePageStarts;')();
const pageSizes = function (sizes, pageSize)
{
    const starts = computePageStarts(sizes, pageSize);
    return starts.map(function (s, i)
    {
        return sizes.slice(s, i + 1 < starts.length ? starts[i + 1] : sizes.length).reduce(function (a, b) { return a + b; }, 0);
    });
};

check(JSON.stringify(pageSizes([45, 8], 50)) === '[53]', '45 + groep van 8 -> 53 op één pagina');
check(JSON.stringify(pageSizes([52, 3], 50)) === '[52,3]', '52 + groep van 3 -> volgende pagina');
check(JSON.stringify(pageSizes([50, 12], 50)) === '[50,12]', '50 + groep van 12 -> volgende pagina');
check(JSON.stringify(pageSizes([45, 16], 50)) === '[45,16]', 'boven softcap 60 -> volgende pagina');
check(JSON.stringify(pageSizes([5, 80, 5], 50)) === '[5,80,5]', 'groep groter dan softcap alleen op een pagina, niet gesplitst');
check(JSON.stringify(pageSizes(new Array(120).fill(1), 50)) === '[50,50,20]', 'zonder groepen: vaste 50 per pagina');
check(JSON.stringify(computePageStarts([], 50)) === '[0]', 'lege lijst: één (lege) pagina');

check(src.includes('const ROWS_PER_PAGE_OPTIONS = [20, 50, 100, 200, 500];') && src.includes('rows_per_page: rowsPerPage,'), 'dropdown 20/50/100/200/500, opgeslagen via saveUserSettings');
check(src.includes("textContent = 'Pagina ' + (i + 1) + ' van ' + pageCount;"), 'paginanummer en springen naar pagina');
check(php.includes('function normalize_rows_per_page') && php.includes("'rows_per_page' => normalize_rows_per_page("), 'server-side per gebruiker bewaard (usersettings)');
process.exit(failures > 0 ? 1 : 0);
