// Run: node tests/rebuild_keep_rows_and_resume_client_test.js
// Tijdens een (hervatte) herbouw blijven de rijen uit de cache in de tabel staan (geen merge van weken,
// wisselen bij klaar); een meeliftende tab hervat een vastgelopen verversing één keer automatisch; de
// aansturende tab meldt een opgegeven verversing aan de server; hervatten stuurt resume=1 mee.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8');

let failures = 0;
function check (ok, label) { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) failures++; }

const loopStart = src.indexOf('    async function startIncrementalMonthLoading');
const loop = src.slice(loopStart, src.indexOf('    function escapeHtml', loopStart));
check(/if \(!chunk\.skipped && !keepDisplayRowsDuringLoad\)\s*\{[\s\S]{0,900}mergeMonthChunk\(chunk/.test(loop), 'geen merge van weken in de tabel als de cache-rijen blijven staan');
check(loop.includes('completedKeys.length > 0 && !keepDisplayRowsDuringLoad'), 'rijen niet als geladen/ladend markeren tijdens herbouw');
check(/catch \(historyError\)\s*\{[\s\S]{0,200}reportLoadFailureToServer\(historyError\)/.test(loop), 'opgegeven verversing wordt aan de server gemeld');
check(src.includes("const keepDisplayRowsDuringLoad = asyncLoadConfig.keep_display_rows === true;"), 'vlag uit de server-config');

// decorateRebuildLoadNote: melding blijft staan (wordt niet meteen overschreven door de weekvoortgang).
const dStart = src.indexOf('    function decorateRebuildLoadNote');
const dEnd = src.indexOf('    // De aansturende browser geeft het op');
const makeDecorate = function (config)
{
    return new Function('asyncLoadConfig', 'keepDisplayRowsDuringLoad', src.slice(dStart, dEnd) + '\nreturn decorateRebuildLoadNote;')(config, config.keep_display_rows === true);
};
const versionNote = makeDecorate({ cache_version_rebuild: true, keep_display_rows: true, showing_previous_rows: true })('Oudere week laden: 2026-W38...');
check(versionNote.indexOf('Cacheversie gewijzigd') === 0 && versionNote.includes('vorige gegevens blijven zichtbaar'), 'versie-herbouw: blijvende melding + vorige gegevens zichtbaar');
const resumeNote = makeDecorate({ rebuild_resume: true, keep_display_rows: true })('Oudere week laden: 2026-W37...');
check(resumeNote.indexOf('Onderbroken verversing wordt hervat') === 0, 'hervatten: melding');

// Meeliften: vastgelopen (stale) => één keer herladen om te hervatten.
const hStart = src.indexOf('    function shouldAutoResumeStalledLoad');
const hEnd = src.indexOf('    async function startHitchhikeActiveLoad');
const store = {};
global.window = { sessionStorage: { getItem: function (k) { return store[k] || null; }, setItem: function (k, v) { store[k] = v; } } };
const shouldResume = new Function(src.slice(hStart, hEnd) + '\nreturn shouldAutoResumeStalledLoad;')();
check(shouldResume('abc') === true && shouldResume('abc') === false, 'maximaal één automatische hervatting per load (geen herlaad-lus)');
const hitch = src.slice(hEnd, src.indexOf('    async function maybeStartIdleCatchUp'));
check(/status === 'error' && progress && progress\.stale === true && shouldAutoResumeStalledLoad\(safeToken\)[\s\S]{0,600}reloadPageWithoutRefreshNow\(\)/.test(hitch), 'meeliften: stale => herladen');

const fetchStart = src.indexOf('    function fetchHistoryWeek (');
const fetchSrc = src.slice(fetchStart, fetchStart + 1500);
check(/asyncLoadConfig\.rebuild_resume === true[\s\S]{0,120}params\.set\('resume', '1'\)/.test(fetchSrc), 'hervatten stuurt resume=1 mee');

process.exit(failures === 0 ? 0 : 1);
