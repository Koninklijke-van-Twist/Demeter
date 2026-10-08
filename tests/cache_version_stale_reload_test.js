// Run: node tests/cache_version_stale_reload_test.js
// Catch-up op een cache van een oude cacheversie: pagina herladen (start volledige verversing) i.p.v. alleen
// de huidige week mergen; bij een versie-herbouw een duidelijke melding.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8');

let failures = 0;
function check (ok, label) { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) failures++; }

const fStart = src.indexOf('    function shouldReloadForStaleCacheVersion');
const fEnd = src.indexOf('    async function startCatchUpCurrentWeek');
check(fStart > 0 && fEnd > fStart, 'helper staat vóór startCatchUpCurrentWeek');
const shouldReload = new Function(src.slice(fStart, fEnd) + '\nreturn shouldReloadForStaleCacheVersion;')();
check(shouldReload({ cache_version_stale: true, skipped: true }) === true, 'stale => herladen');
check(shouldReload({ skipped: true }) === false && shouldReload(null) === false, 'normaal antwoord => niet herladen');

const catchUp = src.slice(fEnd, src.indexOf('    async function startIncrementalMonthLoading'));
const reloadIdx = catchUp.indexOf('shouldReloadForStaleCacheVersion(chunk)');
check(reloadIdx > 0 && reloadIdx < catchUp.indexOf('mergeMonthChunk(chunk'), 'check vóór het mergen van de chunk');
check(/shouldReloadForStaleCacheVersion\(chunk\)\)\s*\{[\s\S]{0,300}window\.location\.reload\(\);\s*return;/.test(catchUp), 'herlaadt en stopt');
check(src.includes("asyncLoadConfig.cache_version_rebuild === true"), 'melding bij versie-herbouw');

process.exit(failures === 0 ? 0 : 1);
