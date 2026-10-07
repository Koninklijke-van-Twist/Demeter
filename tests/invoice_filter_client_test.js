// Run: node tests/invoice_filter_client_test.js
// Factuurfilter client-side: zelfde regel als PHP (gefactureerd = heeft Invoice_Ids), zonder navigatie.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8');
const start = src.indexOf('    function normalizeInvoiceFilter');
const end = src.indexOf('    function applyInvoiceFilterClientSide');
if (start < 0 || end < 0 || end < start) { console.error('FAIL helpers niet gevonden'); process.exit(1); }
const block = src.slice(start, end);

let failures = 0;
function check (ok, label) { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) failures++; }

const harness = new Function('state', block + `
    return {
        normalize: normalizeInvoiceFilter,
        prefix: getInvoiceFilterSummaryPrefix,
        matches: function (filter, row) { invoiceFilter = filter; return matchesInvoiceFilter(row); }
    };`.replace(/^/, 'let invoiceFilter = "both";\n'));
const h = harness({});

const invoiced = { No: 'WO1', Invoice_Ids: ['S001', 'C001'] };
const uninvoiced = { No: 'WO2', Invoice_Ids: [] };
const noField = { No: 'WO3' };

check(h.normalize('INVOICED') === 'invoiced' && h.normalize('x') === 'both' && h.normalize('') === 'both', 'normaliseren');
check(h.matches('both', invoiced) && h.matches('both', uninvoiced) && h.matches('both', noField), 'beide: alles zichtbaar');
check(h.matches('invoiced', invoiced) && !h.matches('invoiced', uninvoiced) && !h.matches('invoiced', noField), 'gefactureerd: alleen met Invoice_Ids');
check(!h.matches('uninvoiced', invoiced) && h.matches('uninvoiced', uninvoiced) && h.matches('uninvoiced', noField), 'ongefactureerd: zonder Invoice_Ids');
check(h.prefix('invoiced') === 'Gefactureerde werkorders: ' && h.prefix('both') === 'Werkorders (beide): ', 'samenvattingstekst');

// Het factuurfilter mag geen navigatie (submit) meer doen.
const handlerStart = src.indexOf('if (inputElement === invoiceFilterSelect)');
const handler = src.slice(handlerStart, src.indexOf('return;', handlerStart));
check(handler.includes('applyInvoiceFilterClientSide') && !handler.includes('.submit('), 'filterwissel zonder submit');
check(/params\.set\('invoice_filter', 'both'\)/.test(src), 'week-loads halen altijd alle rijen op');

process.exit(failures === 0 ? 0 : 1);
