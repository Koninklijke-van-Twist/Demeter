// Run: node tests/project_totals_incomplete_marker_test.js
// Projecttotaal-markering tijdens het laden: aan bij 'loading', weg bij compleet; getallen blijven ongemoeid.
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../web/index.js', 'utf8');
const start = src.indexOf('    function getProjectTotalsIncompleteTooltip');
const end = src.indexOf('    function buildInvoiceIdTooltip');
if (start < 0 || end < 0 || end < start) { console.error('FAIL helpers niet gevonden'); process.exit(1); }
const block = src.slice(start, end);

function makeEl (tag, attrs) {
    const el = {
        tagName: tag, dataset: Object.assign({}, attrs.dataset || {}), attrs: Object.assign({}, attrs.attrs || {}),
        children: [], classes: new Set(attrs.classes || []), textContent: attrs.text || '',
        getAttribute (n) { return n === 'title' ? (this.attrs.title === undefined ? null : this.attrs.title) : null; },
        removeAttribute (n) { delete this.attrs[n]; },
        querySelector (sel) { return this.children.find(c => c.classes.has(sel.replace('.', ''))) || null; },
        appendChild (c) { c.parent = this; this.children.push(c); return c; },
        remove () { const p = this.parent; p.children = p.children.filter(c => c !== this); },
        classList: null
    };
    Object.defineProperty(el, 'className', { get () { return Array.from(this.classes).join(' '); }, set (v) { this.classes = new Set(String(v).split(/\s+/).filter(Boolean)); } });
    Object.defineProperty(el, 'title', { get () { return this.attrs.title || ''; }, set (v) { this.attrs.title = String(v); } });
    el.classList = { add: c => el.classes.add(c), contains: c => el.classes.has(c), toggle: (c, on) => (on ? el.classes.add(c) : el.classes.delete(c)) };
    return el;
}

const cellWithTitle = makeEl('td', { classes: ['project-total-cell'], attrs: { title: 'basis' }, text: '€ 1,00' });
const cellPlain = makeEl('td', { classes: ['project-total-cell'], text: '€ 2,00' });
const thProject = makeEl('th', { dataset: { sortKey: 'Project_Actual_Costs' } });
const thOther = makeEl('th', { dataset: { sortKey: 'Actual_Costs' } });
const modal = makeEl('div', {});
const note = modal.appendChild(makeEl('div', { classes: ['project-incomplete-note'] }));
note.parentNode = modal;
const document = {
    querySelectorAll (sel) {
        if (sel === '.project-total-cell') return [cellWithTitle, cellPlain];
        if (sel === '.project-incomplete-note') return modal.children.filter(c => c.classes.has('project-incomplete-note'));
        return [thProject, thOther];
    },
    createElement (tag) { return makeEl(tag, {}); }
};
const projectFinancialColumnKeys = new Set(['Project_Actual_Costs', 'Project_Total_Revenue', 'Project_Total']);
let projectTotalsIncompleteState = '';
eval(block);

let failures = 0;
const check = (ok, label) => { console.log((ok ? 'ok   ' : 'FAIL ') + label); if (!ok) failures++; };

setProjectTotalsIncompleteState('loading');
check(cellWithTitle.classes.has('project-total-incomplete') && cellPlain.classes.has('project-total-incomplete'), 'cellen gemarkeerd tijdens laden');
check(cellPlain.title === 'Projecttotaal is nog niet compleet: niet alle weken zijn geladen.', 'tooltip op cel');
check(thProject.children.length === 1 && thProject.children[0].textContent.includes('nog aan het laden'), 'badge in projectkolomkop');
check(thOther.children.length === 0, 'geen badge op werkorderkolom');
check(cellWithTitle.textContent === '€ 1,00', 'bedrag ongewijzigd');
check(!cellPlain.classes.has('project-total-error'), 'geen foutklasse tijdens laden');
check(note.textContent.includes('Nog aan het laden'), 'popup-notitie tijdens laden');

setProjectTotalsIncompleteState('');
check(!cellWithTitle.classes.has('project-total-incomplete') && !cellPlain.classes.has('project-total-incomplete'), 'markering weg na laden');
check(cellWithTitle.title === 'basis', 'oorspronkelijke tooltip terug');
check(cellPlain.getAttribute('title') === null, 'geen tooltip als er geen was');
check(thProject.children.length === 0, 'badge weg na laden');
check(modal.children.length === 0, 'popup-notitie verwijderd na laden');

setProjectTotalsIncompleteState('error');
check(cellPlain.title.includes('afgebroken') && thProject.children[0].textContent.includes('niet compleet'), 'foutstatus blijft gemarkeerd');
check(cellPlain.classes.has('project-total-error') && cellPlain.classes.has('project-total-incomplete'), 'foutklasse op cel (⚠ i.p.v. ⏳)');

process.exit(failures > 0 ? 1 : 0);
