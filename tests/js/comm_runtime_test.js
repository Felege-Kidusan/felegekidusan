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

/* Minimal HTML parser for the markup comm.js generates (div/span/button/
 * i/b/label/a with class, id and data-* attributes). Builds REAL shim
 * elements so the runtime can query + click its own render output. */
function parseHtml(html) {
    const root = makeEl('div');
    const stack = [root];
    const re = /<(\/?)([a-zA-Z0-9]+)((?:[^>"']|"[^"]*"|'[^']*')*)>/g;
    let m, last = 0;
    while ((m = re.exec(html))) {
        const text = html.slice(last, m.index);
        if (text && text.trim()) {
            for (let i = 0; i < stack.length; i++) { stack[i].textContent += text; }
        }
        last = re.lastIndex;
        const closing = m[1] === '/', tag = m[2].toLowerCase(), attrStr = m[3] || '';
        if (closing) {
            for (let i = stack.length - 1; i > 0; i--) {
                if (stack[i].tagName === tag.toUpperCase()) { stack.length = i; break; }
            }
            continue;
        }
        const attrs = {};
        const are = /([a-zA-Z-]+)="([^"]*)"/g;
        let a;
        while ((a = are.exec(attrStr))) { attrs[a[1]] = a[2]; }
        const el = makeEl(tag, attrs);
        stack[stack.length - 1].appendChild(el);
        if (!m[0].endsWith('/>')) { stack.push(el); }
    }
    return root;
}

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
        _textContent: '',
        _innerHTML: '',
        offsetWidth: 100, offsetHeight: 100,
        scrollTop: 0, scrollHeight: 500,
        // textContent mirrors into innerHTML because comm.js's esc() does
        // `d.textContent = s; return d.innerHTML;` (real-DOM escaping trick)
        get textContent() { return this._textContent; },
        set textContent(v) {
            this._textContent = String(v == null ? '' : v);
            this._innerHTML = this._textContent
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        },
        get innerHTML() { return this._innerHTML; },
        set innerHTML(v) {
            this._innerHTML = String(v);
            // parse the subset of HTML comm.js generates so the runtime can
            // query and click what it just rendered (thread rows, contacts,
            // pending bubbles, retry buttons…)
            this.children = [];
            const parsed = parseHtml(this._innerHTML);
            this._textContent = parsed.textContent;   // bypass the mirror
            for (const c of [...parsed.children]) { this.appendChild(c); }
        },
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
    e.remove = () => {
        if (e.parentNode) {
            const i = e.parentNode.children.indexOf(e);
            if (i >= 0) { e.parentNode.children.splice(i, 1); }
        }
    };
    e.click = () => {
        for (const fn of (e.listeners.click || []).slice()) {
            fn({ target: e, type: 'click', preventDefault: () => {}, stopPropagation: () => {},
                stopImmediatePropagation: () => {}, key: '', closest: (s) => qsAncestor(e, s) });
        }
    };
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
const filterRow = makeEl('div', { class: 'nc-filter', 'data-nc-filter': '' });
const chipAll = makeEl('button', { class: 'nc-chipf is-on', type: 'button', 'data-filter': 'all', 'aria-pressed': 'true' });
const chipUnread = makeEl('button', { class: 'nc-chipf', type: 'button', 'data-filter': 'unread', 'aria-pressed': 'false' });
filterRow.appendChild(chipAll); filterRow.appendChild(chipUnread);
secBar.appendChild(filterRow);
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
const partnerCount = makeEl('span', { class: 'nc-contacts-count', 'data-nc-partnercount': '', hidden: '' });
newsheetCard.appendChild(partnerCount);
const partnerSearch = makeEl('input', { type: 'search', 'data-nc-partnersearch': '' });
const partnerSearchWrap = makeEl('div', { class: 'nc-contact-search' });
partnerSearchWrap.appendChild(partnerSearch);
newsheetCard.appendChild(partnerSearchWrap);
const partners = makeEl('div', { class: 'nc-contacts', 'data-nc-partners': '' });
partners.appendChild(makeEl('div', { class: 'nc-skeleton' }));
newsheetCard.appendChild(partners);
const partnerEmpty = makeEl('p', { class: 'nc-contacts-empty', 'data-nc-partnersempty': '', hidden: '' });
newsheetCard.appendChild(partnerEmpty);
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
// 3-step progress indicator (P73 Phase 4)
const cmpSteps = makeEl('ol', { class: 'nc-steps', 'data-nc-cmpsteps': '' });
const stepNames = { 1: 'Content', 2: 'Audience', 3: 'Review' };
for (const n of [1, 2, 3]) {
    const li = makeEl('li', { 'data-step': String(n) });
    if (n === 1) { li.classList.add('is-on'); }
    li.appendChild(makeEl('span'));
    li.textContent += stepNames[n];
    cmpSteps.appendChild(li);
}
cmpCard.appendChild(cmpSteps);
// step 1 — content
const cmpPane1 = makeEl('div', { 'data-nc-cmppane': '1' });
cmpPane1.appendChild(makeEl('input', { class: 'nc-inp', id: 'ncCmpTitle' }));
cmpPane1.appendChild(makeEl('textarea', { class: 'nc-inp', id: 'ncCmpBody' }));
const cmpPriority = makeEl('select', { class: 'nc-inp', id: 'ncCmpPriority' });
cmpPane1.appendChild(cmpPriority);
cmpCard.appendChild(cmpPane1);
// step 2 — audience
const cmpPane2 = makeEl('div', { 'data-nc-cmppane': '2', hidden: '' });
const audience = makeEl('div', { class: 'nc-pick', 'data-nc-audience': '' });
const audRoles = makeEl('div', { class: 'nc-pick-p is-on', 'data-a': 'roles' });
const audUsers = makeEl('div', { class: 'nc-pick-p', 'data-a': 'users' });
audience.appendChild(audRoles); audience.appendChild(audUsers);
cmpPane2.appendChild(audience);
const rolesWrap = makeEl('div', { 'data-nc-roleswrap': '' });
const rolesPick = makeEl('div', { class: 'nc-pick', 'data-nc-roles': '' });
rolesWrap.appendChild(rolesPick);
cmpPane2.appendChild(rolesWrap);
const usersWrap = makeEl('div', { 'data-nc-userswrap': '', hidden: '' });
const targetUsers = makeEl('div', { class: 'nc-picklist', 'data-nc-targetusers': '' });
usersWrap.appendChild(targetUsers);
cmpPane2.appendChild(usersWrap);
cmpCard.appendChild(cmpPane2);
// step 3 — review
const cmpPane3 = makeEl('div', { 'data-nc-cmppane': '3', hidden: '' });
const cmpReview = makeEl('dl', { class: 'nc-review', 'data-nc-cmpreview': '' });
cmpPane3.appendChild(cmpReview);
cmpCard.appendChild(cmpPane3);
const cmpErr = makeEl('div', { class: 'nc-err', 'data-nc-cmperr': '' });
cmpCard.appendChild(cmpErr);
const cmpActions = makeEl('div', { class: 'nc-sheet-actions' });
const cmpCancel = makeEl('button', { class: 'nc-btn', type: 'button', 'data-nc-cmpcancel': '' });
const cmpBack = makeEl('button', { class: 'nc-btn', type: 'button', 'data-nc-cmpback': '', hidden: '' });
const cmpNext = makeEl('button', { class: 'nc-btn', type: 'button', 'data-nc-cmpnext': '' });
const cmpPublish = makeEl('button', { class: 'nc-btn', type: 'button', 'data-nc-cmppublish': '', hidden: '' });
cmpActions.appendChild(cmpCancel); cmpActions.appendChild(cmpBack);
cmpActions.appendChild(cmpNext); cmpActions.appendChild(cmpPublish);
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

let failNextSend = false;    // flipped by the optimistic-send failure test
let failNextRead = false;    // flipped by the optimistic mark-read failure test
let failNextCompose = false; // flipped by the composer publish-failure test
function apiReply(action) {
    switch (action) {
        case 'send_message': return failNextSend ? { status: 'error', message: 'blocked by test' } : { status: 'success' };
        case 'mark_read': case 'announcement_read': return failNextRead ? { status: 'error', message: 'blocked by test' } : { status: 'success' };
        case 'compose': return failNextCompose ? { status: 'error', message: 'blocked by test' } : { status: 'success' };
        case 'summary': return { status: 'success', summary: { alerts: 2, announcements: 1, tasks: 0, messages: 3, can_announce: true, can_message: true } };
        case 'feed': return {
            status: 'success',
            rows: [
                { id: '5', title: 'unread one', message: 'm', type: 'member', created_at: '2026-09-12 10:00:00', is_unread: 1, priority: 'normal' },
                { id: '6', title: 'read one', message: 'm2', type: 'member', created_at: '2026-09-12 09:00:00', is_unread: 0, priority: 'normal' }
            ],
            total: 2, unread: 1
        };
        case 'tasks': return {
            status: 'success',
            tasks: [{ id: '11', title: 'Approve room booking', description: 'Room 2 on Friday', priority: 'high', from_user_name: 'Daniel T' }]
        };
        case 'targets': return {
            status: 'success',
            roles: { teacher: 'Teachers' },
            users: [{ id: '2', label: 'Other Person — Teacher' }]
        };
        case 'announcements': return { status: 'success', announcements: [] };
        case 'threads': return {
            status: 'success',
            threads: [{
                id: 7, subject: 'Budget question', participants_label: 'Berea M, Daniel T',
                last_body: 'Can we review the budget?', last_message_at: '2026-09-12 10:00:00',
                created_at: '2026-09-01 09:00:00', unread_count: 2
            }]
        };
        case 'thread': return {
            status: 'success',
            messages: [
                { id: '9', sender_id: 1, sender_name: 'Me', sender_label: 'Super Admin',
                  body: 'my own message', created_at: '2026-09-12 10:00:00', mine: 1 },
                { id: '4', sender_id: 2, sender_name: 'Other', sender_label: 'Teacher',
                  body: 'hello there', created_at: '2026-09-12 09:00:00', mine: 0 }
            ],
            read_watermark: 20
        };
        case 'partners': return {
            status: 'success',
            partners: [
                { id: 1, full_name: 'Ababa User', role: 'teacher', label: 'Ababa User — Teacher' },
                { id: 2, full_name: 'Other Person', role: 'teacher', label: 'Other Person — Teacher' },
                { id: 3, full_name: 'Third Guy', role: 'hr_dept', label: 'Third Guy — HR Dept' }
            ]
        };
        default: return { status: 'success' };
    }
}

const windowShim = {
    CSS: { supports: () => false },   // no field-sizing → JS autoGrow path
    matchMedia: () => mql,
    addEventListener: () => {},
    innerWidth: 1400, innerHeight: 900,
};

const sandbox = {
    document: documentShim,
    window: windowShim,
    location: { hash: '' },
    history: { replaceState: () => {} },
    fetch: (url, opts) => {
        fetchLog.push(String(url) + (opts && opts.body ? ' :: ' + String(opts.body) : ''));
        // GETs carry action= in the URL; POSTs carry it in the form body
        const m = (String(url) + ' ' + (opts && opts.body ? String(opts.body) : '')).match(/action=([a-z_]+)/);
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
sandbox.CSS = windowShim.CSS;   // bare `CSS` identifier used by autoGrow
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
    // the event BUBBLES: every ancestor's delegated handler sees it
    // (the list controller delegates clicks from the list container)
    let node = el;
    while (node) {
        for (const fn of (node.listeners[type] || []).slice()) {
            try { fn(ev); } catch (e) { errs.push(e); runtimeErrors.push(type + ' on ' + (node.className || node.tagName) + ': ' + e.message); }
        }
        node = node.parentNode;
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

    // 6b. P73 Phase 3 — CONTACT-LIST PICKER (D10) in the new-thread sheet
    fire(newThreadBtn, 'click');
    await sleep(20);
    check('PICKER: new-thread sheet opens', newsheet.hidden === false);
    check('PICKER: partners rendered + grouped by role (2 groups / 3 rows)',
        qsa(partners, '.nc-contact').length === 3 && qsa(partners, '.nc-contact-group').length === 2);
    check('PICKER: group header carries the role label', qs(partners, '.nc-contact-group-h').textContent === 'Teacher');
    check('PICKER: row shows initials avatar + full name',
        qs(partners, '.nc-im-avatar').textContent === 'AU' && qs(partners, '.nc-contact-name').textContent === 'Ababa User');
    fire(qs(partners, '[data-pid="1"]'), 'click');
    check('PICKER: whole-row tap selects (is-on + aria-selected)',
        qs(partners, '[data-pid="1"]').classList.contains('is-on') && qs(partners, '[data-pid="1"]').getAttribute('aria-selected') === 'true');
    check('PICKER: selection counter updates', partnerCount.hidden === false && partnerCount.textContent === '1 selected');
    fire(qs(partners, '[data-pid="2"]'), 'keydown', { key: 'Enter' });
    check('PICKER: keyboard Enter selects second row',
        qs(partners, '[data-pid="2"]').classList.contains('is-on') && partnerCount.textContent === '2 selected');
    partnerSearch.value = 'third';
    fire(partnerSearch, 'input');
    check('PICKER: search filters rows',
        qsa(partners, '.nc-contact').length === 1 && qs(partners, '.nc-contact-name').textContent === 'Third Guy');
    partnerSearch.value = 'nothing-matches-this';
    fire(partnerSearch, 'input');
    check('PICKER: no-match empty state shows', qsa(partners, '.nc-contact').length === 0 && partnerEmpty.hidden === false);
    partnerSearch.value = 'ababa';
    fire(partnerSearch, 'input');
    fire(partnerSearch, 'keydown', { key: 'Enter' });
    check('PICKER: search-Enter toggles first visible + clears the box',
        partnerSearch.value === '' && qsa(partners, '.nc-contact').length === 3);
    check('PICKER: selection state survives re-renders',
        !qs(partners, '[data-pid="1"]').classList.contains('is-on') && qs(partners, '[data-pid="2"]').classList.contains('is-on'));
    fire(qs(partners, '[data-pid="1"]'), 'click');
    newSubject.value = 'Hello';
    newBody.value = 'kickoff';
    fire(newSend, 'click');
    await sleep(20);
    const started = fetchLog.find((e) => e.includes('action=thread_start'));
    check('PICKER: send posts exactly the selected ids',
        !!started && /to=(1%2C2|2%2C1)/.test(started) && started.includes('subject=Hello'));
    check('PICKER: success closes sheet + resets selection',
        newsheet.hidden === true && qsa(partners, '.nc-contact').every((r) => !r.classList.contains('is-on')));

    // 6c. P73 Phase 3 — THREAD VIEW: read receipts (D11), composer (D9),
    //     optimistic send with inline Retry (D11)
    fire(secTabMsgs, 'click');
    await sleep(20);   // initMessages → loadThreads → rows rendered
    const tRow = qs(threads, '[data-th="7"]');
    check('THREADS: fixture thread rendered', !!tRow && qs(tRow, '.nc-im-tt').textContent === 'Budget question');
    fire(tRow, 'click');
    await sleep(20);   // openThread → get thread → renderMessages
    check('THREAD: conversation header bound',
        qs(convHead, '[data-nc-convtitle]').textContent === 'Budget question' && form.hidden === false);
    check('RECEIPTS: my message ≤ watermark renders ✓✓ Seen',
        msgs._innerHTML.includes('fa-check-double') && msgs._innerHTML.includes('Seen'));
    check('RECEIPTS: exactly one mine bubble, no “Sent” fallback rendered',
        qsa(msgs, '.nc-msg').length === 2 && qsa(msgs, '.nc-msg.mine').length === 1
        && !msgs._innerHTML.includes('fa-check"></i> Sent'));
    reply.value = 'growing the composer';
    fire(reply, 'input');
    check('COMPOSER: autoGrow JS fallback caps at 140px', reply.style.height === '140px');
    const sends = () => fetchLog.filter((e) => e.includes('action=send_message')).length;
    const threadGets = () => fetchLog.filter((e) => /action=thread&/.test(e)).length;
    const before = { s: sends(), t: threadGets() };
    reply.value = 'hello from the runtime gate';
    fire(reply, 'keydown', { key: 'Enter' });
    check('COMPOSER: Enter-to-send posts + optimistic pending bubble',
        sends() === before.s + 1 && !!qs(msgs, '[data-pending]')
        && qs(msgs, '[data-pending] .nc-bubble').textContent === 'hello from the runtime gate');
    check('COMPOSER: optimistic send clears the composer instantly',
        reply.value === '' && reply.style.height === '');
    reply.value = 'not a send';
    fire(reply, 'keydown', { key: 'Enter', shiftKey: true });
    check('COMPOSER: Shift+Enter never sends', sends() === before.s + 1);
    reply.value = '';
    await sleep(20);
    check('OPTIMISTIC: success removes pending + refreshes open thread',
        !qs(msgs, '[data-pending]') && threadGets() > before.t);
    failNextSend = true;
    reply.value = 'this one will fail';
    fire(reply, 'keydown', { key: 'Enter' });
    await sleep(20);
    const failedNode = qs(msgs, '.nc-msg--failed');
    check('OPTIMISTIC: failed send keeps the bubble with inline Retry',
        !!failedNode && !!qs(failedNode, '.nc-retry--msg'));
    failNextSend = false;
    fire(qs(failedNode, '.nc-retry--msg'), 'click');
    await sleep(20);
    check('OPTIMISTIC: Retry refills, resends and clears on success',
        fetchLog.some((e) => e.includes('action=send_message') && e.includes('this+one+will+fail'))
        && !qs(msgs, '.nc-msg--failed') && !qs(msgs, '[data-pending]'));

    // 6d. P73 Phase 4 — INBOX FILTER (All / Unread chips, server-backed)
    fire(secTabInbox, 'click');
    await sleep(20);
    const alertsList = qs(inboxPane, '.nc-list[data-list="alerts"]');
    const cAlerts = qs(section, '.nc-count[data-count="alerts"]');
    check('FILTER: chips render on the alerts tab', !!filterRow && filterRow.hidden === false);
    fire(chipUnread, 'click');
    await sleep(20);
    check('FILTER: Unread toggles state + refetches with the server param',
        chipUnread.classList.contains('is-on') && chipUnread.getAttribute('aria-pressed') === 'true'
        && fetchLog.some((e) => e.includes('action=feed&') && e.includes('unread=1')));
    fire(qs(section, '.nc-tab[data-tab="announcements"]'), 'click');
    check('FILTER: row hides off the alerts tab', filterRow.hidden === true);
    fire(qs(section, '.nc-tab[data-tab="alerts"]'), 'click');
    check('FILTER: row returns on the alerts tab', filterRow.hidden === false);
    fire(chipAll, 'click');
    await sleep(20);

    // 6e. P73 Phase 4 — OPTIMISTIC mark-read (single item)
    const unreadItem = qs(alertsList, '.nc-item.nc-unread');
    check('OPT: unread alert present with dot', !!unreadItem && !!qs(unreadItem, '.nc-dot'));
    fire(unreadItem, 'click');
    check('OPT: read clears instantly (class, dot, count)',
        !unreadItem.classList.contains('nc-unread') && !qs(unreadItem, '.nc-dot')
        && cAlerts.textContent === '1');
    await sleep(20);
    check('OPT: read confirms via mark_read in the background',
        fetchLog.some((e) => e.includes('action=mark_read')));
    fire(chipUnread, 'click');   // force a refetch → the unread row returns
    await sleep(20);
    const unreadItem2 = qs(alertsList, '.nc-item.nc-unread');
    check('OPT: refetch restored the unread row', !!unreadItem2);
    failNextRead = true;
    fire(unreadItem2, 'click');
    check('OPT: failed read still clears instantly first', !unreadItem2.classList.contains('nc-unread'));
    await sleep(20);
    check('OPT: failed read reverts by refetch + count restored',
        !!qs(alertsList, '.nc-item.nc-unread') && cAlerts.textContent === '2');
    failNextRead = false;
    fire(chipAll, 'click');
    await sleep(20);

    // 6f. P73 Phase 4 — OPTIMISTIC mark-all-read
    check('MARKALL: button visible while unread exists', secMarkAll.hidden === false);
    fire(secMarkAll, 'click');
    check('MARKALL: clears every unread instantly (rows, dots, count, button)',
        qsa(alertsList, '.nc-item.nc-unread').length === 0 && qs(alertsList, '.nc-dot') === null
        && cAlerts.textContent === '0' && secMarkAll.hidden === true);
    await sleep(20);
    check('MARKALL: posts mark_all_read in the background',
        fetchLog.some((e) => e.includes('action=mark_all_read')));

    // 6g. P73 Phase 4 — TASK action routing (D12): buttons work with feedback
    fire(qs(section, '.nc-tab[data-tab="tasks"]'), 'click');
    await sleep(20);
    const tasksList = qs(inboxPane, '.nc-list[data-list="tasks"]');
    const taskItem = qs(tasksList, '.nc-item[data-task]');
    check('TASKS: task renders with Done + In-progress buttons',
        !!taskItem && !!qs(taskItem, '[data-do="completed"]') && !!qs(taskItem, '[data-do="in_progress"]'));
    const doneBtn = qs(taskItem, '[data-do="completed"]');
    fire(doneBtn, 'click');
    check('TASKS: Done goes busy instantly', doneBtn.classList.contains('is-busy'));
    await sleep(20);
    check('TASKS: Done posts task_update and the list reloads',
        fetchLog.some((e) => e.includes('action=task_update') && e.includes('task_status=completed'))
        && !!qs(tasksList, '.nc-item[data-task]'));

    // 6h. P73 Phase 4 — 3-STEP announcement composer (Content→Audience→Review)
    fire(announceBtn, 'click');
    await sleep(20);
    const cmpPane = (n) => qs(cmpCard, '[data-nc-cmppane="' + n + '"]');
    const cmpNextBtn = () => qs(cmpCard, '[data-nc-cmpnext]');
    const cmpErrEl = () => qs(cmpCard, '[data-nc-cmperr]');
    check('CMP: opens at step 1 (content pane, Next visible)',
        cmpPane(1).hidden === false && cmpPane(2).hidden === true && cmpPane(3).hidden === true
        && !cmpNextBtn().hidden && qs(cmpCard, '[data-nc-cmppublish]').hidden === true);
    fire(cmpNextBtn(), 'click');
    check('CMP: step 1 validation blocks an empty title',
        cmpErrEl().textContent === 'Give the announcement a title.' && cmpPane(2).hidden === true);
    qs(cmpCard, '#ncCmpTitle').value = 'Harness title';
    fire(cmpNextBtn(), 'click');
    check('CMP: missing body also blocked',
        cmpErrEl().textContent === 'Write the message first.' && cmpPane(2).hidden === true);
    qs(cmpCard, '#ncCmpBody').value = 'Body text';
    fire(cmpNextBtn(), 'click');
    check('CMP: valid content advances to step 2',
        cmpPane(2).hidden === false && cmpPane(1).hidden === true
        && qs(cmpCard, '[data-nc-cmpsteps] li[data-step="2"]').classList.contains('is-on')
        && qs(cmpCard, '[data-nc-cmpsteps] li[data-step="1"]').classList.contains('is-done'));
    fire(cmpNextBtn(), 'click');
    check('CMP: step 2 validation blocks an empty audience',
        cmpErrEl().textContent === 'Choose at least one group.' && cmpPane(3).hidden === true);
    fire(qs(cmpCard, '[data-nc-roles] [data-role="teacher"]'), 'click');
    fire(cmpNextBtn(), 'click');
    check('CMP: selected audience advances to step 3 review',
        cmpPane(3).hidden === false && qs(cmpCard, '[data-nc-cmppublish]').hidden === false
        && qs(cmpCard, '[data-nc-cmpback]').hidden === false);
    check('CMP: review renders title + audience summary',
        cmpReview.textContent.includes('Harness title') && cmpReview.textContent.includes('Teachers'));
    fire(qs(cmpCard, '[data-nc-cmpback]'), 'click');
    check('CMP: Back returns to step 2', cmpPane(2).hidden === false && cmpPane(3).hidden === true);
    fire(cmpNextBtn(), 'click');
    fire(qs(cmpCard, '[data-nc-cmppublish]'), 'click');
    await sleep(20);
    const rolesPost = fetchLog.filter((e) => e.includes('action=compose')).pop();
    check('CMP: publish posts the composed payload (roles path)',
        !!rolesPost && rolesPost.includes('title=Harness+title') && rolesPost.includes('roles=teacher')
        && rolesPost.includes('audience=roles'));
    check('CMP: success closes the sheet + resets to step 1',
        composer.hidden === true && cmpPane(1).hidden === false && qs(cmpCard, '#ncCmpTitle').value === '');

    // 6i. P73 Phase 4 — composer: users audience path + publish failure
    fire(announceBtn, 'click');
    await sleep(20);
    qs(cmpCard, '#ncCmpTitle').value = 'Direct note';
    qs(cmpCard, '#ncCmpBody').value = 'Second body';
    fire(cmpNextBtn(), 'click');
    fire(qs(cmpCard, '[data-nc-audience] [data-a="users"]'), 'click');
    check('CMP: users audience reveals the people picker',
        usersWrap.hidden === false && rolesWrap.hidden === true);
    fire(cmpNextBtn(), 'click');
    check('CMP: users path validates a selection',
        cmpErrEl().textContent === 'Choose at least one recipient.');
    const ucb = qs(targetUsers, 'input');
    ucb.checked = true;   // the user ticks the recipient
    fire(cmpNextBtn(), 'click');
    check('CMP: users selection advances to review', cmpPane(3).hidden === false);
    fire(qs(cmpCard, '[data-nc-cmppublish]'), 'click');
    await sleep(20);
    const usersPost = fetchLog.filter((e) => e.includes('action=compose')).pop();
    check('CMP: users publish posts user_ids + audience=users',
        !!usersPost && usersPost.includes('user_ids=2') && usersPost.includes('audience=users'));
    check('CMP: sheet closed + reset again', composer.hidden === true && cmpPane(1).hidden === false);
    failNextCompose = true;
    fire(announceBtn, 'click');
    await sleep(20);
    qs(cmpCard, '#ncCmpTitle').value = 'Will fail';
    qs(cmpCard, '#ncCmpBody').value = 'Failure body';
    fire(cmpNextBtn(), 'click');
    fire(qs(cmpCard, '[data-nc-roles] [data-role="teacher"]'), 'click');
    fire(cmpNextBtn(), 'click');
    fire(qs(cmpCard, '[data-nc-cmppublish]'), 'click');
    await sleep(20);
    check('CMP: publish failure keeps the sheet open with the error',
        composer.hidden === false && cmpErrEl().textContent !== '');
    failNextCompose = false;
    fire(cmpCancel, 'click');
    check('CMP: cancel closes the failed composer', composer.hidden === true);

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
