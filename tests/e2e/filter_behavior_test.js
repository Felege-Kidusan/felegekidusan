// ══════════════════════════════════════════════════════════════
// P37: hymn-library filter state machine — BEHAVIORAL test.
// Runs the REAL frontend/js/mezmur.js against a DOM shim and
// asserts the one-way data flow contract:
//   events -> lib (SSOT) -> pure render; reconcile is the only
//   automatic change and it is always announced.
// Would have caught the P31-P36 "silent renderCatalogList throw"
// (dropdowns never populated; filters applied invisibly).
// Usage: node tests/e2e/filter_behavior_test.js   (exit 1 on FAIL)
// ══════════════════════════════════════════════════════════════
// ── DOM shim harness: runs the REAL frontend/js/mezmur.js and traces
//    every list reload, select change, and toast. Reproduces the
//    "dropdown filters automatically" report with hard evidence.
'use strict';
const fs = require('fs');
const LOG = [];
const apiCalls = [];

function makeEl(id) {
    const listeners = {};
    const el = {
        _self: null,
        id: id || '', value: '', innerHTML: '', textContent: '',
        className: '', style: {}, dataset: {}, disabled: false, offsetHeight: 0,
        classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
        addEventListener(t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
        fire(t, evt) { (listeners[t] || []).forEach(fn => fn.call(el, evt || { target: el })); },
        querySelector(sel) {
            // support option[value="X"] against the innerHTML string
            const m = /option\[value="([^"]+)"\]/.exec(sel);
            if (m) return innerHas(el.innerHTML, m[1]) ? makeEl('opt') : null;
            return null;
        },
        querySelectorAll() { return []; },
        appendChild() {}, remove() {}, focus() {}, select() {}, click() {},
        setAttribute() {}, getAttribute() { return null; },
        closest() { return null; },
    };
    return el;
}
function innerHas(html, val) {
    return String(html).indexOf('value="' + val + '"') !== -1;
}

const els = {};
function $(id) { if (!els[id]) els[id] = makeEl(id); return els[id]; }

let zemariansPayload = [
    { id: 11, name: 'SingerX', is_active: 1, hymn_count: 2 },
    { id: 12, name:SingerHiddenName(), is_active: 0, hymn_count: 0 },
];
function SingerHiddenName() { return 'SingerHidden'; }
const categoriesPayload = [
    { id: 1, name: 'MainA', parent_id: null, is_active: 1, hymn_count_total: 3 },
    { id: 2, name: 'SubA', parent_id: 1, is_active: 1, hymn_count: 1 },
];

global.document = {
    getElementById: $,
    querySelector() { return null; },   // no active section -> overview
    querySelectorAll() { return []; },
    createElement(tag) { return makeEl(tag); },
    createTextNode(t) { return { textContent: t }; },
    body: makeEl('body'),
    addEventListener(t, fn) { if (t === 'DOMContentLoaded') global.__domReady = fn; },
};
global.window = {
    toast(msg, type) { LOG.push('TOAST[' + (type || 's') + ']: ' + msg); },
    api: {
        get(url) {
            apiCalls.push(String(url));
            let d;
            if (url.indexOf('action=categories') >= 0) {
                d = { status: 'success', items: categoriesPayload };
            } else if (url.indexOf('action=zemarians') >= 0) {
                d = { status: 'success', items: zemariansPayload };
            } else if (url.indexOf('action=list') >= 0) {
                const zid = /zemarian_id=(\d+)/.exec(url);
                const hidden = zid && Number(zid[1]) === 12;
                d = { status: 'success', items: hidden ? [] : [{ id: 7, title: 'Hymn A', categories: [], zemarians: [] }], total: hidden ? 0 : 1, page: 1, total_pages: 1 };
            } else {
                d = { status: 'success', items: [], stats: {} };
            }
            return Promise.resolve(d);
        },
        post(url, data) {
            apiCalls.push('POST ' + url + ' ' + JSON.stringify(data));
            return Promise.resolve({ status: 'success', saved: true });
        },
    },
};
global.modal = function (id, open) {};
global.openModalF = function (id) { LOG.push('openModal(' + id + ')'); };
global.closeModalF = function (id) { LOG.push('closeModal(' + id + ')'); };
global.URL = { createObjectURL() { return 'blob:'; }, revokeObjectURL() {} };
global.Blob = function () {};
global.setTimeout = setTimeout; global.clearTimeout = clearTimeout;

// load the real controller
const path = require('path');
const root = path.resolve(__dirname, '..', '..');
const src = fs.readFileSync(process.argv[2] || path.join(root, 'frontend/js/mezmur.js'), 'utf8');
eval(src);

function calls(kind) { return apiCalls.filter(c => c.indexOf(kind) >= 0); }
function listCalls() { return calls('action=list'); }
function flush() { return new Promise(r => setTimeout(r, 30)); }

(async () => {
    global.__domReady();
    await flush();
    LOG.push('--- after DOMContentLoaded: list calls=' + listCalls().length);

    // S2: user picks an ACTIVE singer in the dropdown
    $('mzZemarianFilter').value = '11';
    $('mzZemarianFilter').fire('change');
    await flush();
    LOG.push('--- S2 user picks SingerX: list calls=' + listCalls().length +
        ' last=' + (listCalls().slice(-1)[0] || '').slice(0, 90));

    LOG.push('S2-DROPDOWN=' + $('mzZemarianFilter').value);
    // S3: user opens the catalog manager (loadCatalog re-runs; singer 11
    // still active) — NO list reload should happen
    const before = listCalls().length;
    global.window.Mezmur.openCatalog();
    await flush();
    LOG.push('--- S3 manager opened (singer active): EXTRA list calls=' + (listCalls().length - before));

    // S4: singer 11 gets hidden (by this user in the manager, or another
    // admin); the next catalog refresh re-runs populate
    zemariansPayload = [{ id: 12, name: 'SingerHidden', is_active: 0, hymn_count: 0 }];
    const before2 = listCalls().length;
    const toastsBefore = LOG.filter(l => l.startsWith('TOAST')).length;
    // a real manager mutation (rename) re-fetches the catalog
    global.window.Mezmur.mgrEdit(11);
    $('mzMgrEditName').value = 'SingerX Renamed';
    global.window.Mezmur.mgrSave(11);
    await flush();
    const autoLoads = listCalls().length - before2;
    const toastsAfter = LOG.filter(l => l.startsWith('TOAST')).length;
    LOG.push('--- S4 stale filter reconciled: EXTRA list calls=' + autoLoads +
        ' toasts=' + (toastsAfter - toastsBefore) +
        ' select now="' + $('mzZemarianFilter').value + '"');

    // S5: view-modal shortcut to a hidden singer
    const before3 = listCalls().length;
    global.window.Mezmur.browseZemarian(12);
    await flush();
    LOG.push('--- S5 browse hidden singer: list calls=' + (listCalls().length - before3) +
        ' select="' + $('mzZemarianFilter').value + '"');

    LOG.push('=== VERDICTS ===');
    function expect(name, cond) { LOG.push((cond ? 'PASS: ' : 'FAIL: ') + name); if (!cond) process.exitCode = 1; }
    const zemCalls = listCalls();
    expect('S2 user pick loads filtered list', zemCalls.some(c => /zemarian_id=11/.test(c)));
    expect('S2 dropdown reflects the pick', (LOG.find(l => l.startsWith('S2-DROPDOWN=')) || '').endsWith('11'));
    const s4Line = LOG.find(l => l.startsWith('--- S4')) || '';
    expect('S4 stale filter auto-cleared exactly once', /EXTRA list calls=1 /.test(s4Line));
    expect('S4 change was ANNOUNCED (toast)', /toasts=1/.test(s4Line));
    expect('S4 dropdown no longer shows the dropped value', $('mzZemarianFilter').value === '');
    const s5Line = LOG.find(l => l.startsWith('--- S5')) || '';
    expect('S5 shortcut to hidden row reconciles visibly', /select=""/.test(s5Line));
    if (process.env.VERBOSE) process.stdout.write(LOG.join('\n') + '\n');
else process.stdout.write(LOG.filter(l => /^(PASS|FAIL)/.test(l)).join('\n') + '\n');
})();
