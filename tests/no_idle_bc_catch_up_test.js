// Een openstaand tabblad start geen eigen BC-catch-up (geen polling van BC); alleen page-open, nightly en hourly verversen.
// Run: node tests/no_idle_bc_catch_up_test.js
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8');
const start = src.indexOf('async function maybeStartIdleCatchUp');
const end = src.indexOf('async function pollActiveLoadForButton');
if (start < 0 || end < 0) { console.error('FAIL functies niet gevonden'); process.exit(1); }
const body = src.slice(start, end);
if (/startCatchUpCurrentWeek\s*\(/.test(body)) { console.error('FAIL idle-timer start nog een BC-catch-up'); process.exit(1); }
// Nieuwere cache door een ander: de tabel bijwerken (9 okt), met herladen als vangnet.
if (!/refreshRowsInPlace\s*\(\s*reloadPageWithoutRefreshNow\s*\)/.test(body)) { console.error('FAIL bijwerken bij nieuwere cache is weg'); process.exit(1); }
// De page-open catch-up blijft bestaan.
if (!/asyncLoadConfig\.catch_up_enabled\)\s*\{\s*startCatchUpCurrentWeek\(\)/.test(src)) { console.error('FAIL page-open catch-up is weg'); process.exit(1); }
console.log('ok   geen idle BC-catch-up; page-open catch-up en herladen blijven');
