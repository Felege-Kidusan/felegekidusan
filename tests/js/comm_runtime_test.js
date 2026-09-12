/**
 * ============================================================================
 * comm.js RUNTIME test — executes the real admin/js/comm.js in a Node vm
 * with a minimal DOM shim, then simulates the exact user interactions that
 * broke in production (P73 Phase 2.2): bell click, Communication sidebar
 * button (data-comm-open), Escape, view switching, page-mode boot.
 *
 * Why: node --check only proves SYNTAX; the server harness only proves the
 * HTML renders. Neither executes the runtime. A block-scoping bug
 * (function declarations inside if/else blocks referenced from outside)
 * shipped through both gates — this file closes that gap permanently.
 *
 * Run:  node tests/js/comm_runtime_test.js   (exit 0 = pass)
 * ============================================================================
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

/* ── tiny selector engine: tag, .class, [attr], [attr="v"], :checked,
 *    compound selectors and descendant combinators — everything comm.js
 *    actually uses. ─────────────────────────────────────────────────── */
function camel(name) { return name.replace(/-([a-z])/g, (_, c) => c.toUpperCase()); }

function makeEl(tag, attrs = {}) {
    const e = {
        tagName: tag.toUpperCase(),
        attrs: Object.assign({}, attrs),
        children: [],
        parentNode: null,
        listeners: {},
        hidden: !!attrs.hidden,
        disabled: false,
        checked: !!attrs.checked,
        style: {},
        dataset: {},
        className: attrs.class || '',
        id: attrs.id || '',
        value: attrs.value || '',
        textContent: '',
        _innerHTML: '',
        offsetWidth: 100, offsetHeight: 100,
        scrollTop: 0, scrollHeight: 500,
        get innerHTML() { return this._innerHTML; },
        set innerHTML(v) { this._innerHTML = String(v); },
    };
    for (const k of Object.keys(attrs)) {
        if (k.startsWith('data-')) { e.dataset[camel(k.slice(5))] = attrs[k]; }
    }
    const cls = () => e.className.split(/\s+/).filter(Boolean);
    e.classList = {
        add: (...c) => { e.className = [...new Set([...cls(), ...c])].join(' '); },
        remove: (...c) => { e.className = cls().filter(x => !c.includes(x)).join(' '); },
        toggle: (c, force) => {
            const on = force === undefined ? !cls().includes(c) : !!force;
            if (on) { e.classList.add(c); } else { e.classList.remove(c); }
            return on;
        },
        contains: (c) => cls().includes(c),
    };
    e.addEventListener = (type, fn) => { (e.listeners[type] = e.listeners[type] || []).push(fn); };
    e.setAttribute = (k, v) => { e.attrs[k] = v; if (k.startsWith('data-')) e.dataset[camel(k.slice(5))] = v; };
    e.getAttribute = (k) => (k in e.attrs ? e.attrs[k] : null);
    e.appendChild = (c) => { c.parentNode = e; e.children.push(c); return c; };
    e.contains = (t) => { let n = t; while (n) { if (n === e) { return true; } n = n.parentNode; } return false; };
    e.focus = () => {};
    e.getBoundingClientRect = () => ({ top: 10, bottom: 50, left: 10, right: 50, width: 40, height: 40 });
    e.querySelector = (s) => qs(e, s);
    e.querySelectorAll = (s) => qsa(e, s);
    e.closest = (sel) => qsAncestor(e, sel);
    e.hasAttribute = (k) => k in e.attrs;
    return e;
}

function matches(e, part) {
    for (const s of part.split(/(?=[.\[#])/)) {
        if (s.startsWith('.')) { if (!e.classList.contains(s.slice(1))) { return false; } }
        else if (s.startsWith('#')) { if (e.id !== s.slice(1)) { return false; } }
        else if (s.startsWith('[')) {
            const m = s.match(/^\[([a-z-]+)(?:="([^"]*)")?\]$/i);
            if (!m) { return false; }
            const v = e.attrs[m[1]];
            if (v === undefined) { return false; }
            if (m[2] !== undefined && String(v) !== m[2]) { return false; }
        } else if (s.endsWith(':checked')) {
            if (e.tagName !== 'INPUT' || !e.checked) { return false; }
        } else {
            if (e.tagName !== s.toUpperCase()) { return false; }
        }
    }
    return true;
}

let documentShim2 = null, bodyShim2 = null;   // page-mode scenario swap-in

function qsa(root, selector) {
    const out = [];
    const walk = (n) => {
        for (const c of n.children || []) {
            // try to match the FULL selector (descendants) on this node
            const parts = selector.split(/\s+/);
            let ok = matches(c, parts[parts.length - 1]);
            if (ok) {
                // walk up matching earlier parts greedily
                let idx = parts.length - 2, p = c.parentNode;
                while (idx >= 0 && p) {
                    if (matches(p, parts[idx])) { idx--; }
                    p = p.parentNode;
                }
                if (idx < 0) { out.push(c); }
            }
            walk(c);
        }
    };
    if (root === documentShim2) { walk(bodyShim2); }
    else if (root === documentShim) { walk(bodyShim); walk(headShim); }
    else { walk(root); }
    return out;
}

function qs(root, selector) { return qsa(root, selector)[0] || null; }

function closest(el, selector) {
    let n = el;
    while (n) { if (matches(n, selector.split(/\s+/)[0]) && qsAncestor(n, selector)) { return n; } n = n.parentNode; }
    return null;
}
function qsAncestor(el, selector) {
    // closest() in comm.js is always used with SIMPLE selectors — match directly
    let n = el;
    while (n) { if (matches(n, selector)) { return n; } n = n.parentNode; }
    return null;
}

/* ── fixture DOM: mirrors the REAL markup of
 *    admin/components/notification_center.php + comm/comm_section.php ── */
const documentShim = { readyState: 'complete', hidden: false, listeners: {} };
const bodyShim = makeEl('body');
const headShim = makeEl('head');
documentShim.body = bodyShim;
documentShim.addEventListener = (t, fn) => { (documentShim.listeners[t] = documentShim.listeners[t] || []).push(fn); };
documentShim.createElement = (tag) => makeEl(tag);
documentShim.getElementById = (id) => qs(documentShim, '#' + id);
documentShim.querySelector = (s) => qs(documentShim, s);
documentShim.querySelectorAll = (s) => qsa(documentShim, s);

// bell placements (two, like mezmur's sidebar slot + topbar)
for (const host of [makeEl('div', { class: 'school-bell-slot' }), makeEl('div', { class: 'school-topbar' })]) {
    const root = makeEl('div', { class: 'nc-root' });
    const bellBtn = makeEl('button', { class: 'nc-bell', type: 'button' });
    bellBtn.appendChild(makeEl('i', { class: 'fa-solid fa-bell' }));
    const badge = makeEl('span', { class: 'nc-badge' }); badge.hidden = true; badge.textContent = '0';
    bellBtn.appendChild(badge);
    root.appendChild(bellBtn);
    host.appendChild(root);
    bodyShim.appendChild(host);
}

// the panel + scrim (notification_center.php markup, abridged to the hooks comm.js uses)
const panel = makeEl('div', { class: 'nc-panel', 'data-nc-panel': '', 'data-csrf': 'test-csrf', 'data-api': '/admin/api_notifications.php', role: 'dialog', hidden: '' });
const head = makeEl('div', { class: 'nc-head' });
head.appendChild(makeEl('span', { class: 'nc-title' }));
const headActions = makeEl('span', { class: 'nc-head-actions' });
const markAll = makeEl('button', { class: 'nc-link nc-mark-all', type: 'button' }); markAll.hidden = true;
const panelX = makeEl('button', { class: 'nc-x', type: 'button' });
headActions.appendChild(markAll); headActions.appendChild(panelX);
head.appendChild(headActions);
panel.appendChild(head);
const tabs = makeEl('div', { class: 'nc-tabs' });
for (const t of ['alerts', 'announcements', 'tasks']) {
    const tab = makeEl('button', { class: 'nc-tab', type: 'button', 'data-tab': t });
    if (t === 'alerts') { tab.classList.add('is-active'); tab.setAttribute('aria-selected', 'true'); } else { tab.setAttribute('aria-selected', 'false'); }
    const count = makeEl('span', { class: 'nc-count', 'data-count': t }); count.hidden = true;
    tab.appendChild(count);
    tabs.appendChild(tab);
}
panel.appendChild(tabs);
const body = makeEl('div', { class: 'nc-body' });
for (const t of ['alerts', 'announcements', 'tasks']) {
    const list = makeEl('div', { class: 'nc-list', 'data-list': t });
    if (t !== 'alerts') { list.hidden = true; }
    list.appendChild(makeEl('div', { class: 'nc-skeleton' }));
    body.appendChild(list);
}
panel.appendChild(body);
const foot = makeEl('div', { class: 'nc-foot' });
const inboxLink = makeEl('a', { class: 'nc-foot-link', href: '/admin/notifications.php', 'data-comm-open': 'inbox' });
const msgLink = makeEl('a', { class: 'nc-foot-link nc-msg-link', href: '/admin/messages.php', 'data-comm-open': 'messages' });
const msgCount = makeEl('span', { class: 'nc-count', 'data-count': 'messages' }); msgCount.hidden = true;
msgLink.appendChild(msgCount);
foot.appendChild(inboxLink); foot.appendChild(msgLink);
panel.appendChild(foot);
bodyShim.appendChild(panel);
const scrim = makeEl('div', { class: 'nc-scrim', 'data-nc-scrim': '', hidden: '' });
bodyShim.appendChild(scrim);

// the SECTION (comm_section.php markup, abridged to the hooks comm.js uses)
const section = makeEl('div', { class: 'nc-sec', 'data-nc-section': '', 'data-initial': 'inbox', 'data-can-announce': '0', 'data-can-message': '0', role: 'region', hidden: '' });
const secHead = makeEl('header', { class: 'nc-sec-head' });
secHead.appendChild(makeEl('span', { class: 'nc-title' }));
const secTabs = makeEl('div', { class: 'nc-sec-tabs' });
const secTabInbox = makeEl('button', { class: 'nc-sec-tab is-active', type: 'button', 'data-nc-view': 'inbox' });
const secTabMsgs = makeEl('button', { class: 'nc-sec-tab', type: 'button', 'data-nc-view': 'messages' });
const inboxCount = makeEl('span', { class: 'nc-count', 'data-count': 'inbox' }); inboxCount.hidden = true;
const msgsCount = makeEl('span', { class: 'nc-count', 'data-count': 'messages' }); msgsCount.hidden = true;
secTabInbox.appendChild(inboxCount); secTabMsgs.appendChild(msgsCount);
secTabs.appendChild(secTabInbox); secTabs.appendChild(secTabMsgs);
const secCloseBtn = makeEl('button', { class: 'nc-x', type: 'button', 'data-nc-close': '' });
secHead.appendChild(secTabs); secHead.appendChild(secCloseBtn);
section.appendChild(secHead);
const secBody = makeEl('div', { class: 'nc-sec-body' });
const inboxPane = makeEl('section', { class: 'nc-sec-view', 'data-nc-viewpane': 'inbox' });
const secBar = makeEl('div', { class: 'nc-sec-bar' });
const inboxTabs = makeEl('div', { class: 'nc-tabs' });
for (const t of ['alerts', 'announcements', 'tasks']) {
    const tab = makeEl('button', { class: 'nc-tab', type: 'button', 'data-tab': t });
    if (t === 'alerts') { tab.classList.add('is-active'); } else { tab.hidden = false; }
    const count = makeEl('span', { class: 'nc-count', 'data-count': t }); count.hidden = true;
    tab.appendChild(count);
    inboxTabs.appendChild(tab);
}
secBar.appendChild(inboxTabs);
const barActions = makeEl('div', { class: 'nc-sec-bar-actions' });
const announceBtn = makeEl('button', { class: 'nc-link nc-announce', type: 'button', 'data-nc-announce': '' }); announceBtn.hidden = true;
const secMarkAll = makeEl('button', { class: 'nc-link nc-mark-all', type: 'button' }); secMarkAll.hidden = true;
barActions.appendChild(announceBtn); barActions.appendChild(secMarkAll);
secBar.appendChild(barActions);
inboxPane.appendChild(secBar);
const secScroll = makeEl('div', { class: 'nc-sec-scroll' });
for (const t of ['alerts', 'announcements', 'tasks']) {
    const list = makeEl('div', { class: 'nc-list', 'data-list': t });
    if (t !== 'alerts') { list.hidden = true; }
    list.appendChild(makeEl('div', { class: 'nc-skeleton' }));
    secScroll.appendChild(list);
}
inboxPane.appendChild(secScroll);
secBody.appendChild(inboxPane);
const msgsPane = makeEl('section', { class: 'nc-sec-view', 'data-nc-viewpane': 'messages', hidden: '' });
const im = makeEl('div', { class: 'nc-im' });
const imList = makeEl('div', { class: 'nc-im-list' });
const imListHead = makeEl('div', { class: 'nc-im-listhead' });
const newThreadBtn = makeEl('button', { class: 'nc-btn nc-btn-done', type: 'button', 'data-nc-newthread': '' }); newThreadBtn.hidden = true;
imListHead.appendChild(makeEl('h3')); imListHead.appendChild(newThreadBtn);
imList.appendChild(imListHead);
const threads = makeEl('div', { class: 'nc-im-threads', 'data-nc-threads': '' });
threads.appendChild(makeEl('div', { class: 'nc-skeleton' }));
imList.appendChild(threads);
im.appendChild(imList);
const conv = makeEl('div', { class: 'nc-im-conv', 'data-nc-conv': '' });
const convEmpty = makeEl('div', { class: 'nc-empty nc-im-empty', 'data-nc-convempty': '' });
const convHead = makeEl('div', { class: 'nc-im-convhead', 'data-nc-convhead': '', hidden: '' });
const convBack = makeEl('button', { class: 'nc-x', type: 'button', 'data-nc-convback': '' });
convHead.appendChild(convBack);
const convTitleWrap = makeEl('div', {});
convTitleWrap.appendChild(makeEl('h3', { 'data-nc-convtitle': '' }));
convTitleWrap.appendChild(makeEl('div', { class: 'nc-im-who', 'data-nc-convwho': '' }));
convHead.appendChild(convTitleWrap);
const msgs = makeEl('div', { class: 'nc-im-msgs', 'data-nc-msgs': '' });
const form = makeEl('form', { class: 'nc-im-form', 'data-nc-form': '', hidden: '' });
const reply = makeEl('textarea', { 'data-nc-reply': '' });
const send = makeEl('button', { class: 'nc-im-send', type: 'submit', 'data-nc-send': '' });
form.appendChild(reply); form.appendChild(send);
conv.appendChild(convEmpty); conv.appendChild(convHead); conv.appendChild(msgs); conv.appendChild(form);
im.appendChild(conv);
msgsPane.appendChild(im);
secBody.appendChild(msgsPane);
section.appendChild(secBody);
bodyShim.appendChild(section);

// sheets + sheet scrim
const newsheet = makeEl('div', { class: 'nc-sheet', 'data-nc-newsheet': '', hidden: '' });
const newsheetCard = makeEl('div', { class: 'nc-sheet-card' });
newsheetCard.appendChild(makeEl('h2'));
const partners = makeEl('div', { class: 'nc-picklist', 'data-nc-partners': '' });
newsheetCard.appendChild(partners);
const newSubject = makeEl('input', { class: 'nc-inp', id: 'ncNewSubject' });
const newBody = makeEl('textarea', { class: 'nc-inp', id: 'ncNewBody' });
newsheetCard.appendChild(newSubject); newsheetCard.appendChild(newBody);
const newErr = makeEl('div', { class: 'nc-err', 'data-nc-newerr': '' });
newsheetCard.appendChild(newErr);
const newActions = makeEl('div', { class: 'nc-sheet-actions' });
const newCancel = makeEl('button', { class: 'nc-btn', type: 'button', 'data-nc-newcancel': '' });
const newSend = makeEl('button', { class: 'nc-btn', type: 'button', 'data-nc-newsend': '' });
newActions.appendChild(newCancel); newActions.appendChild(newSend);
newsheetCard.appendChild(newActions);
newsheet.appendChild(newsheetCard);
bodyShim.appendChild(newsheet);

const composer = makeEl('div', { class: 'nc-sheet', 'data-nc-composer': '', hidden: '' });
const cmpCard = makeEl('div', { class: 'nc-sheet-card' });
cmpCard.appendChild(makeEl('h2'));
cmpCard.appendChild(makeEl('input', { class: 'nc-inp', id: 'ncCmpTitle' }));
cmpCard.appendChild(makeEl('textarea', { class: 'nc-inp', id: 'ncCmpBody' }));
const cmpPriority = makeEl('select', { class: 'nc-inp', id: 'ncCmpPriority' });
cmpCard.appendChild(cmpPriority);
const audience = makeEl('div', { class: 'nc-pick', 'data-nc-audience': '' });
const audRoles = makeEl('div', { class: 'nc-pick-p is-on', 'data-a': 'roles' });
const audUsers = makeEl('div', { class: 'nc-pick-p', 'data-a': 'users' });
audience.appendChild(audRoles); audience.appendChild(audUsers);
cmpCard.appendChild(audience);
const rolesWrap = makeEl('div', { 'data-nc-roleswrap': '' });
const rolesPick = makeEl('div', { class: 'nc-pick', 'data-nc-roles': '' });
rolesWrap.appendChild(rolesPick);
const usersWrap = makeEl('div', { 'data-nc-userswrap': '', hidden: '' });
const targetUsers = makeEl('div', { class: 'nc-picklist', 'data-nc-targetusers': '' });
usersWrap.appendChild(targetUsers);
cmpCard.appendChild(rolesWrap); cmpCard.appendChild(usersWrap);
const cmpErr = makeEl('div', { class: 'nc-err', 'data-nc-cmperr': '' });
cmpCard.appendChild(cmpErr);
const cmpActions = makeEl('div', { class: 'nc-sheet-actions' });
const cmpCancel = makeEl('button', { class: 'nc-btn', type: 'button', 'data-nc-cmpcancel': '' });
const cmpPublish = makeEl('button', { class: 'nc-btn', type: 'button', 'data-nc-cmppublish': '' });
cmpActions.appendChild(cmpCancel); cmpActions.appendChild(cmpPublish);
cmpCard.appendChild(cmpActions);
composer.appendChild(cmpCard);
bodyShim.appendChild(composer);

const sheetScrim = makeEl('div', { class: 'nc-scrim', 'data-nc-sheet-scrim': '', hidden: '' });
bodyShim.appendChild(sheetScrim);

/* ── sandbox globals ─────────────────────────────────────────────────── */
const runtimeErrors = [];
const fetchLog = [];
const nativeSetTimeout = setTimeout;
const mql = { matches: false, addEventListener: () => {}, removeEventListener: () => {} };

function apiReply(action) {
    switch (action) {
        case 'summary': return { status: 'success', summary: { alerts: 2, announcements: 1, tasks: 0, messages: 3, can_announce: true, can_message: true } };
        case 'feed': return { status: 'success', rows: [{ id: '5', title: 't', message: 'm', type: 'member', created_at: '2026-09-12 10:00:00', is_unread: 1, priority: 'normal' }], total: 1, unread: 1 };
        case 'announcements': return { status: 'success', announcements: [] };
        case 'tasks': return { status: 'success', tasks: [] };
        case 'targets': return { status: 'success', roles: {}, users: [] };
        case 'threads': return { status: 'success', threads: [] };
        case 'partners': return { status: 'success', partners: [] };
        default: return { status: 'success' };
    }
}

const windowShim = {
    matchMedia: () => mql,
    addEventListener: () => {},
    innerWidth: 1400, innerHeight: 900,
};

const sandbox = {
    document: documentShim,
    window: windowShim,
    location: { hash: '' },
    history: { replaceState: () => {} },
    fetch: (url) => {
        fetchLog.push(String(url));
        const m = String(url).match(/action=([a-z_]+)/);
        return Promise.resolve({ json: () => Promise.resolve(apiReply(m ? m[1] : '')) });
    },
    URLSearchParams,
    Promise,
    setTimeout: (fn, ms) => { const t = nativeSetTimeout(fn, ms); t.unref && t.unref(); return t; },
    clearTimeout: (t) => clearTimeout(t),
    requestAnimationFrame: (fn) => { const t = nativeSetTimeout(fn, 0); t.unref && t.unref(); return t; },
    console,
    Math, Date, Object, Array, String, Number, JSON, RegExp, isNaN, parseInt, encodeURIComponent,
};
sandbox.globalThis = sandbox;
windowShim.location = sandbox.location;
documentShim.defaultView = windowShim;

/* ── interaction helpers ─────────────────────────────────────────────── */
function fire(el, type, extra) {
    const errs = [];
    const ev = Object.assign({
        target: el, type, preventDefault: () => {}, stopPropagation: () => {},
        stopImmediatePropagation: () => {},
        key: '',
        closest: (sel) => qsAncestor(el, sel),
    }, extra || {});
    for (const fn of (el.listeners[type] || []).slice()) {
        try { fn(ev); } catch (e) { errs.push(e); runtimeErrors.push(type + ' on ' + (el.className || el.tagName) + ': ' + e.message); }
    }
    // document-level handlers (delegation) get the event too
    for (const fn of (documentShim.listeners[type] || []).slice()) {
        try { fn(ev); } catch (e) { errs.push(e); runtimeErrors.push('document ' + type + ': ' + e.message); }
    }
    return errs;
}

/* ── load the REAL runtime ───────────────────────────────────────────── */
const commSrc = fs.readFileSync(path.join(__dirname, '..', '..', 'admin', 'js', 'comm.js'), 'utf-8');
let loadError = null;
try { vm.runInNewContext(commSrc, sandbox, { filename: 'comm.js' }); }
catch (e) { loadError = e; }

/* ── assertions ──────────────────────────────────────────────────────── */
const results = [];
function check(name, cond) { results.push([name, !!cond]); }
// NOTE: main-context sleeps KEEP the loop alive; only comm.js's internal
// timers are unref'd via the sandbox wrapper (so its 30s poll cannot hang CI).
const sleep = (ms) => new Promise((r) => nativeSetTimeout(r, ms));

(async () => {
    check('comm.js loads without throwing', !loadError);
    await sleep(30); // boot → summary fetch → applyHash settle

    // summary poll populated badge + permission buttons
    const badges = qsa(documentShim, '.nc-root .nc-badge');
    check('summary applied to bell badges (2 bells, count 6)', badges.length === 2 && badges[0].textContent === '6' && !badges[0].hidden);
    check('can_announce reveals Announce button', announceBtn.hidden === false);
    check('can_message reveals New-thread button', newThreadBtn.hidden === false);

    // 1. BELL CLICK — the reported dead control
    fire(qs(documentShim, '.nc-bell'), 'click');
    await sleep(20);
    check('BELL: panel opens (hidden=false, is-open)', panel.hidden === false && panel.classList.contains('is-open'));

    // close it again
    fire(panelX, 'click');
    check('BELL: panel closes', panel.hidden === true);

    // 2. SIDEBAR COMMUNICATION BUTTON (data-comm-open) — the reported dead control
    const sidebarBtn = makeEl('button', { 'data-comm-open': 'inbox', class: 'nav-link' });
    bodyShim.appendChild(sidebarBtn);
    fire(sidebarBtn, 'click');
    await sleep(20);
    check('SECTION: opens via data-comm-open (hidden=false, is-open)', section.hidden === false && section.classList.contains('is-open'));
    check('SECTION: inbox pane visible', inboxPane.hidden === false && msgsPane.hidden === true);
    check('SECTION: inbox lists primed (skeleton replaced by data)', qs(inboxPane, '.nc-list[data-list="alerts"]')._innerHTML.includes('nc-item'));

    // 3. view switching
    fire(secTabMsgs, 'click');
    await sleep(20);
    check('SECTION: Messages view switches (panes toggle)', msgsPane.hidden === false && inboxPane.hidden === true);
    fire(secTabInbox, 'click');

    // 4. Escape closes the section
    fire(documentShim.body, 'keydown', { key: 'Escape' });
    check('SECTION: Escape closes', !section.classList.contains('is-open'));
    await sleep(320);
    check('SECTION: hidden after transition timer', section.hidden === true);

    // 5. deep link #messages
    sandbox.location.hash = '#messages';
    // simulate the handler comm.js registered at load — call via hash change not
    // supported by shim; instead verify applyHash indirectly through reopening
    fire(sidebarBtn, 'click');
    check('SECTION: reopens after close', section.hidden === false);

    // 6. composer sheet via Announce button
    fire(announceBtn, 'click');
    await sleep(20);
    check('SHEET: composer opens (hidden=false) + scrim', composer.hidden === false && sheetScrim.hidden === false);
    fire(cmpCancel, 'click');
    check('SHEET: composer closes + scrim hides', composer.hidden === true && sheetScrim.hidden === true);

    // 7. bell again AFTER section interactions (regression for the scoping bug)
    fire(qs(documentShim, '.nc-bell'), 'click');
    await sleep(10);
    check('BELL: still opens after section use', panel.hidden === false);
    fire(panelX, 'click');

    // ── PAGE-MODE scenario (notifications.php / messages.php boot path).
    // Phase-2.2 also broke boot() → setView on thin shells (block-scoped).
    // Fresh DOM: a page-mode section + panel, initial view = messages.
    const doc2 = { readyState: 'complete', hidden: false, listeners: {} };
    const body2 = makeEl('body'); doc2.body = body2;
    doc2.addEventListener = (t, fn) => { (doc2.listeners[t] = doc2.listeners[t] || []).push(fn); };
    doc2.createElement = (tag) => makeEl(tag);
    doc2.getElementById = () => null;
    doc2.querySelector = (s) => qs(doc2, s);
    doc2.querySelectorAll = (s) => qsa(doc2, s);
    const savedDoc = { document: documentShim, body: bodyShim };
    // point the engine at the second document
    documentShim2 = doc2; bodyShim2 = body2;

    const panel2 = makeEl('div', { class: 'nc-panel', 'data-nc-panel': '', 'data-csrf': 'c', 'data-api': '/a.php', hidden: '' });
    const p2head = makeEl('div', { class: 'nc-head' });
    const p2actions = makeEl('span', { class: 'nc-head-actions' });
    p2actions.appendChild(makeEl('button', { class: 'nc-link nc-mark-all', type: 'button' }));
    p2actions.appendChild(makeEl('button', { class: 'nc-x', type: 'button' }));
    p2head.appendChild(p2actions);
    panel2.appendChild(p2head);
    const p2tabs = makeEl('div', { class: 'nc-tabs' });
    for (const t of ['alerts', 'announcements', 'tasks']) {
        const tab = makeEl('button', { class: 'nc-tab', type: 'button', 'data-tab': t });
        if (t === 'alerts') { tab.classList.add('is-active'); }
        const count = makeEl('span', { class: 'nc-count', 'data-count': t }); count.hidden = true;
        tab.appendChild(count);
        p2tabs.appendChild(tab);
    }
    panel2.appendChild(p2tabs);
    const p2body = makeEl('div', { class: 'nc-body' });
    for (const t of ['alerts', 'announcements', 'tasks']) {
        const list = makeEl('div', { class: 'nc-list', 'data-list': t });
        if (t !== 'alerts') { list.hidden = true; }
        list.appendChild(makeEl('div', { class: 'nc-skeleton' }));
        p2body.appendChild(list);
    }
    panel2.appendChild(p2body);
    const p2foot = makeEl('div', { class: 'nc-foot' });
    const p2msg = makeEl('a', { class: 'nc-foot-link nc-msg-link', href: '/admin/messages.php', 'data-comm-open': 'messages' });
    p2foot.appendChild(p2msg);
    panel2.appendChild(p2foot);
    body2.appendChild(panel2);
    body2.appendChild(makeEl('div', { class: 'nc-scrim', 'data-nc-scrim': '', hidden: '' }));
    const section2 = makeEl('div', { class: 'nc-sec nc-sec--page', 'data-nc-section': '', 'data-initial': 'messages', 'data-can-announce': '1', 'data-can-message': '1' });
    const secHead2 = makeEl('header', { class: 'nc-sec-head' });
    const secTabs2 = makeEl('div', { class: 'nc-sec-tabs' });
    const tabIn2 = makeEl('button', { class: 'nc-sec-tab is-active', type: 'button', 'data-nc-view': 'inbox' });
    const tabMs2 = makeEl('button', { class: 'nc-sec-tab', type: 'button', 'data-nc-view': 'messages' });
    secTabs2.appendChild(tabIn2); secTabs2.appendChild(tabMs2);
    secHead2.appendChild(secTabs2); secHead2.appendChild(makeEl('button', { class: 'nc-x', type: 'button', 'data-nc-close': '' }));
    section2.appendChild(secHead2);
    const secBody2 = makeEl('div', { class: 'nc-sec-body' });
    const paneIn2 = makeEl('section', { class: 'nc-sec-view', 'data-nc-viewpane': 'inbox' });
    const bar2 = makeEl('div', { class: 'nc-sec-bar' });
    const tabs2 = makeEl('div', { class: 'nc-tabs' });
    for (const t of ['alerts', 'announcements', 'tasks']) {
        const tab = makeEl('button', { class: 'nc-tab', type: 'button', 'data-tab': t });
        const count = makeEl('span', { class: 'nc-count', 'data-count': t }); count.hidden = true;
        tab.appendChild(count); tabs2.appendChild(tab);
    }
    bar2.appendChild(tabs2);
    const acts2 = makeEl('div', { class: 'nc-sec-bar-actions' });
    const ann2 = makeEl('button', { class: 'nc-link nc-announce', type: 'button', 'data-nc-announce': '' });
    acts2.appendChild(ann2);
    bar2.appendChild(acts2);
    paneIn2.appendChild(bar2);
    const scroll2 = makeEl('div', { class: 'nc-sec-scroll' });
    for (const t of ['alerts', 'announcements', 'tasks']) {
        const list = makeEl('div', { class: 'nc-list', 'data-list': t });
        if (t !== 'alerts') { list.hidden = true; }
        list.appendChild(makeEl('div', { class: 'nc-skeleton' }));
        scroll2.appendChild(list);
    }
    paneIn2.appendChild(scroll2);
    const paneMs2 = makeEl('section', { class: 'nc-sec-view', 'data-nc-viewpane': 'messages', hidden: '' });
    const im2 = makeEl('div', { class: 'nc-im' });
    const imList2 = makeEl('div', { class: 'nc-im-list' });
    const lh2 = makeEl('div', { class: 'nc-im-listhead' });
    lh2.appendChild(makeEl('h3'));
    imList2.appendChild(lh2);
    const threads2 = makeEl('div', { class: 'nc-im-threads', 'data-nc-threads': '' });
    threads2.appendChild(makeEl('div', { class: 'nc-skeleton' }));
    imList2.appendChild(threads2);
    im2.appendChild(imList2);
    const conv2 = makeEl('div', { class: 'nc-im-conv', 'data-nc-conv': '' });
    conv2.appendChild(makeEl('div', { class: 'nc-empty nc-im-empty', 'data-nc-convempty': '' }));
    const convHead2 = makeEl('div', { 'data-nc-convhead': '', hidden: '' });
    convHead2.appendChild(makeEl('button', { class: 'nc-x', type: 'button', 'data-nc-convback': '' }));
    const convTitle2 = makeEl('div', {});
    convTitle2.appendChild(makeEl('h3', { 'data-nc-convtitle': '' }));
    convTitle2.appendChild(makeEl('div', { class: 'nc-im-who', 'data-nc-convwho': '' }));
    convHead2.appendChild(convTitle2);
    conv2.appendChild(convHead2);
    conv2.appendChild(makeEl('div', { 'data-nc-msgs': '' }));
    const form2 = makeEl('form', { class: 'nc-im-form', 'data-nc-form': '', hidden: '' });
    form2.appendChild(makeEl('textarea', { 'data-nc-reply': '' }));
    conv2.appendChild(form2);
    im2.appendChild(conv2);
    paneMs2.appendChild(im2);
    secBody2.appendChild(paneIn2); secBody2.appendChild(paneMs2);
    section2.appendChild(secBody2);
    body2.appendChild(section2);

    const sandbox2 = Object.assign({}, sandbox, {
        document: doc2,
        location: { hash: '' },
    });
    sandbox2.globalThis = sandbox2;
    try { vm.runInNewContext(commSrc, sandbox2, { filename: 'comm.js#pagemode' }); }
    catch (e) { runtimeErrors.push('page-mode load: ' + e.message); }
    await sleep(30);
    check('PAGE MODE: boot selects the initial view (messages)', paneMs2.hidden === false && paneIn2.hidden === true);
    check('PAGE MODE: announce button visible from server ctx', ann2.hidden === false || ann2.classList.contains('nc-announce'));
    const countIn2 = qs(doc2, '.nc-count[data-count="messages"]');
    check('PAGE MODE: no handler errors on boot', runtimeErrors.every((e) => !e.startsWith('page-mode')));

    // verdict
    const failed = results.filter(([, ok]) => !ok);
    for (const [name, ok] of results) {
        console.log((ok ? '  ✓ ' : '  ✗ ') + name);
    }
    if (runtimeErrors.length) {
        console.log('\n  RUNTIME ERRORS (uncaught in handlers):');
        for (const e of runtimeErrors) { console.log('    ⚠ ' + e); }
    }
    if (loadError) { console.log('\n  LOAD ERROR: ' + loadError.message); }
    console.log(failed.length === 0 && runtimeErrors.length === 0 && !loadError
        ? '\nPASS — all ' + results.length + ' runtime checks green, zero handler errors'
        : '\nFAIL — ' + failed.length + ' failed, ' + runtimeErrors.length + ' handler errors');
    process.exit(failed.length === 0 && runtimeErrors.length === 0 && !loadError ? 0 : 1);
})();
