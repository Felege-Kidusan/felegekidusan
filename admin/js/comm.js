/* ============================================================================
 * SSMS Communication runtime — P73 Phase 2
 * ----------------------------------------------------------------------------
 * ONE runtime for every communication surface:
 *   • Bell popover / mobile sheet (Phase 1)
 *   • Communication SECTION — the dedicated in-dashboard destination with
 *     Inbox (alerts / announcements / tasks) + Messages (P73 Phase 2)
 *   • Sheets: new conversation + announcement composer
 *   • Thin-shell pages render the same partial in page mode.
 * Zero globals, one IIFE. Assets: admin/css/comm.css.
 *
 * Shared-hosting performance rules:
 *   one summary poll per page (visibility-paused, focus-refreshed,
 *   capped exponential backoff, GET de-duplication); threads/messages poll
 *   ONLY while the Messages view is actually open.
 * ==========================================================================*/
(function () {
    'use strict';

    var POLL_MS = 30000;          // summary cadence while visible
    var BACKOFF_MAX = 8;          // cap: 30s -> 4min after repeated failures
    var MOBILE = window.matchMedia('(max-width: 768px)');

    /* ── tiny helpers ─────────────────────────────────────────────── */
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function relTime(iso) {
        if (!iso) { return ''; }
        var t = Date.parse(String(iso).replace(' ', 'T'));
        if (isNaN(t)) { return ''; }
        var s = Math.max(0, (Date.now() - t) / 1000);
        if (s < 60) { return 'just now'; }
        if (s < 3600) { return Math.floor(s / 60) + 'm ago'; }
        if (s < 86400) { return Math.floor(s / 3600) + 'h ago'; }
        if (s < 604800) { return Math.floor(s / 86400) + 'd ago'; }
        return new Date(t).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    }
    function timeHM(iso) {
        var t = Date.parse(String(iso).replace(' ', 'T'));
        if (isNaN(t)) { return ''; }
        return new Date(t).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    }
    function dayLabel(iso) {
        var t = Date.parse(String(iso).replace(' ', 'T'));
        if (isNaN(t)) { return ''; }
        var d = new Date(t), now = new Date();
        if (d.toDateString() === now.toDateString()) { return 'Today'; }
        var y = new Date(now.getTime() - 86400000);
        if (d.toDateString() === y.toDateString()) { return 'Yesterday'; }
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }
    function initials(name) {
        name = String(name || '?').trim().split(/\s+/);
        return ((name[0] || ' ')[0] + (name.length > 1 ? name[name.length - 1][0] : '')).toUpperCase();
    }
    var ICONS = [
        [/member/, 'fa-user'], [/class|enroll/, 'fa-school'], [/attendance/, 'fa-clipboard-check'],
        [/grade|marks/, 'fa-graduation-cap'], [/task/, 'fa-list-check'], [/role/, 'fa-id-badge'],
        [/document|share/, 'fa-file-lines'], [/sync|change/, 'fa-rotate']
    ];
    function iconFor(type) {
        type = String(type || '');
        for (var i = 0; i < ICONS.length; i++) { if (ICONS[i][0].test(type)) { return ICONS[i][1]; } }
        return 'fa-bell';
    }
    function setCount(el, n) {
        if (!el) { return; }
        el.textContent = n > 99 ? '99+' : String(n);
        el.hidden = !(n > 0);
    }

    /* ── Composer auto-grow (P73 Phase 3, fixes D9) ─────────────────
     * Modern browsers size the textarea natively via CSS
     * `field-sizing: content` (see comm.css) — zero JS. Legacy engines
     * take the classic scrollHeight path below. Both paths cap growth at
     * NC_COMPOSER_MAX px and the scrollbar is hidden in CSS, so the box
     * NEVER shows an internal scrollbar while typing. */
    var NC_COMPOSER_MAX = 140;
    function autoGrow(el) {
        if (!el) { return; }
        if (window.CSS && CSS.supports && CSS.supports('field-sizing', 'content')) { return; }
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, NC_COMPOSER_MAX) + 'px';
    }

    /* ── API client (de-duplicated GETs, CSRF'd POSTs) ────────────── */
    var panel = document.querySelector('.nc-panel[data-nc-panel]');
    if (!panel) { return; } // nothing to drive on this page
    var section = document.querySelector('[data-nc-section]');
    var scrim = document.querySelector('.nc-scrim[data-nc-scrim]');
    var API = panel.dataset.api || '/admin/api_notifications.php';
    var CSRF = panel.dataset.csrf || '';
    var inflight = {};

    function get(action, qs) {
        var key = 'GET ' + action + (qs || '');
        if (inflight[key]) { return inflight[key]; }
        inflight[key] = fetch(API + '?action=' + encodeURIComponent(action) + (qs || ''),
            { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .finally(function () { delete inflight[key]; });
        return inflight[key];
    }
    function post(action, fields) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('csrf_token', CSRF);
        if (fields) { Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); }); }
        return fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    /* ── poller: visibility-paused, focus-refreshed, backoff-capped ── */
    var pollers = {};
    function pollStart(name, fn, ms) {
        pollStop(name);
        var p = pollers[name] = { fn: fn, ms: ms, fails: 0, timer: null, paused: false };
        p.tick = function () {
            if (p.paused) { return; }
            Promise.resolve().then(p.fn).then(function () {
                p.fails = 0;
                p.timer = setTimeout(p.tick, p.ms);
            }).catch(function () {
                p.fails = Math.min(p.fails + 1, 4);
                p.timer = setTimeout(p.tick, p.ms * Math.min(Math.pow(2, p.fails), BACKOFF_MAX));
            });
        };
        p.timer = setTimeout(p.tick, p.ms);
    }
    function pollStop(name) {
        var p = pollers[name];
        if (p) { clearTimeout(p.timer); delete pollers[name]; }
    }
    document.addEventListener('visibilitychange', function () {
        var names = Object.keys(pollers), stagger = 0;
        names.forEach(function (n) {
            var p = pollers[n];
            if (document.hidden) {
                p.paused = true; clearTimeout(p.timer);
            } else {
                p.paused = false;
                setTimeout(function () { p.fn().catch(function () {}); }, stagger);
                stagger += 150;
                p.timer = setTimeout(p.tick, p.ms);
            }
        });
    });
    window.addEventListener('focus', function () {
        Object.keys(pollers).forEach(function (n) { pollers[n].fn().catch(function () {}); });
    });

    /* ── toast ─────────────────────────────────────────────────────── */
    var toastEl = null, toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'nc-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
        toastEl.textContent = msg;
        toastEl.className = 'nc-toast' + (kind === 'ok' ? ' nc-ok' : kind === 'err' ? ' nc-err' : '');
        void toastEl.offsetWidth;
        toastEl.classList.add('is-in');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('is-in'); }, 2600);
    }

    /* ── state renderers ───────────────────────────────────────────── */
    function skeleton(el) {
        el.innerHTML = '<div class="nc-skeleton"><span></span><span></span><span></span></div>';
    }
    function emptyState(el, icon, text) {
        el.innerHTML = '<div class="nc-empty"><i class="fa-solid ' + icon + '"></i>' + esc(text) + '</div>';
    }
    function errorState(el, text, retryFn) {
        el.innerHTML = '<div class="nc-empty nc-error"><i class="fa-solid fa-triangle-exclamation"></i>' + esc(text) +
            '<br><button type="button" class="nc-retry"><i class="fa-solid fa-rotate-right"></i> Retry</button></div>';
        el.querySelector('.nc-retry').addEventListener('click', function (e) {
            e.stopPropagation();
            retryFn();
        });
    }

    /* ── content renderers ─────────────────────────────────────────── */
    function renderAlerts(el, d) {
        var rows = (d && d.rows) || [];
        if (!rows.length) { emptyState(el, 'fa-bell-slash', 'You are all caught up'); return; }
        el.innerHTML = rows.map(function (n) {
            var prio = n.priority || 'normal';
            return '<div class="nc-item ' + (n.is_unread ? 'nc-unread ' : '') +
                (prio === 'urgent' ? 'nc-urgent' : prio === 'high' ? 'nc-high' : '') +
                '" data-id="' + esc(n.id) + '" tabindex="0">' +
                '<div class="nc-ico"><i class="fa-solid ' + iconFor(n.type) + '"></i></div>' +
                '<div class="nc-main"><div class="nc-row1"><span class="nc-t">' + esc(n.title) + '</span>' +
                '<span class="nc-time">' + esc(relTime(n.created_at)) + '</span></div>' +
                '<div class="nc-msg">' + esc(n.message) + '</div>' +
                ((n.is_unread || (prio !== 'normal' && prio !== 'low')) ?
                    '<div class="nc-sub">' + (n.is_unread ? '<span class="nc-dot" title="Unread"></span>' : '') +
                    (prio !== 'normal' && prio !== 'low' ? '<span class="nc-chip nc-c-' + esc(prio) + '">' + esc(prio) + '</span>' : '') +
                    (n.source_dept ? '<span>' + esc(n.source_dept) + '</span>' : '') + '</div>' : '') +
                '</div></div>';
        }).join('');
    }
    function renderAnn(el, d) {
        var rows = (d && d.announcements) || [];
        if (!rows.length) { emptyState(el, 'fa-bullhorn', 'No announcements yet'); return; }
        el.innerHTML = rows.map(function (a) {
            return '<div class="nc-item ' + (a.is_unread ? 'nc-unread ' : '') +
                (a.priority === 'urgent' ? 'nc-urgent' : a.priority === 'high' ? 'nc-high' : '') +
                '" data-ann="' + esc(a.id) + '" tabindex="0">' +
                '<div class="nc-ico"><i class="fa-solid fa-bullhorn"></i></div>' +
                '<div class="nc-main"><div class="nc-row1"><span class="nc-t">' +
                (a.is_pinned == 1 ? '📌 ' : '') + esc(a.title) + '</span>' +
                '<span class="nc-time">' + esc(relTime(a.created_at)) + '</span></div>' +
                '<div class="nc-msg">' + esc(a.body) + '</div>' +
                '<div class="nc-sub">' + (a.is_unread ? '<span class="nc-dot"></span>' : '') +
                (a.priority !== 'normal' ? '<span class="nc-chip nc-c-' + esc(a.priority) + '">' + esc(a.priority) + '</span>' : '') +
                '<span>' + esc(a.author_label || a.source_dept) + (a.author_name ? ' · ' + esc(a.author_name) : '') + '</span></div>' +
                '</div></div>';
        }).join('');
    }
    function renderTasks(el, d) {
        var rows = (d && d.tasks) || [];
        if (!rows.length) { emptyState(el, 'fa-circle-check', 'No pending tasks'); return; }
        el.innerHTML = rows.map(function (t) {
            var prio = t.priority || 'normal';
            return '<div class="nc-item nc-' + (prio === 'urgent' ? 'urgent' : prio === 'high' ? 'high' : '') + '" data-task="' + esc(t.id) + '" tabindex="0">' +
                '<div class="nc-ico"><i class="fa-solid fa-list-check"></i></div>' +
                '<div class="nc-main"><div class="nc-row1"><span class="nc-t">' + esc(t.title) + '</span></div>' +
                '<div class="nc-msg">' + esc(t.description || '') + '</div>' +
                '<div class="nc-sub">' + (prio !== 'normal' && prio !== 'low' ? '<span class="nc-chip nc-c-' + esc(prio) + '">' + esc(prio) + '</span>' : '') +
                (t.from_user_name || t.from_dept ? '<span>From: ' + esc(t.from_user_name || t.from_dept) + '</span>' : '') + '</div>' +
                '<div class="nc-actions">' +
                '<button type="button" class="nc-btn nc-btn-done" data-do="completed"><i class="fa-solid fa-check"></i> Done</button>' +
                '<button type="button" class="nc-btn nc-btn-prog" data-do="in_progress"><i class="fa-solid fa-clock"></i> In progress</button>' +
                '</div></div></div>';
        }).join('');
    }

    /* ════════════════════════════════════════════════════════════════
       LIST CONTROLLER FACTORY — powers both the bell panel and the
       section's Inbox view. One implementation = identical behaviour.
       ════════════════════════════════════════════════════════════════ */
    function makeListController(root) {
        var els = {
            alerts: root.querySelector('.nc-list[data-list="alerts"]'),
            ann: root.querySelector('.nc-list[data-list="announcements"]'),
            tasks: root.querySelector('.nc-list[data-list="tasks"]'),
            cAlerts: root.querySelector('.nc-count[data-count="alerts"]'),
            cAnn: root.querySelector('.nc-count[data-count="announcements"]'),
            cTasks: root.querySelector('.nc-count[data-count="tasks"]'),
            markAll: root.querySelector('.nc-mark-all'),
            filterWrap: root.querySelector('[data-nc-filter]')
        };
        var state = { tab: 'alerts', summary: {}, loadedTabs: {}, unreadOnly: false };

        function loadTab(tab, force) {
            var el = tab === 'alerts' ? els.alerts : tab === 'announcements' ? els.ann : els.tasks;
            if (!el) { return; }
            if (state.loadedTabs[tab] && !force) { return; }
            skeleton(el);
            var run = function () {
                var p = tab === 'alerts' ? get('feed', '&limit=25' + (state.unreadOnly ? '&unread=1' : '')).then(function (d) { renderAlerts(el, d); })
                    : tab === 'announcements' ? get('announcements', '&limit=25').then(function (d) { renderAnn(el, d); })
                    : get('tasks', '&limit=25').then(function (d) { renderTasks(el, d); });
                p.catch(function () { errorState(el, 'Could not load this list.', function () { loadTab(tab, true); }); });
            };
            run();
            state.loadedTabs[tab] = true;
        }

        function syncMarkAll() {
            if (!els.markAll) { return; }
            var n = state.tab === 'alerts' ? (state.summary.alerts || 0)
                  : state.tab === 'announcements' ? (state.summary.announcements || 0) : 0;
            els.markAll.hidden = !(n > 0);
        }

        /* Filter chips (P73 Phase 4): All / Unread on the alerts feed.
         * The filter is server-backed (feed?unread=1) so it sees the whole
         * feed, not just the loaded page. Section surface only — the bell
         * panel ships no [data-nc-filter] row, so these are no-ops there. */
        function syncFilter() {
            if (!els.filterWrap) { return; }
            els.filterWrap.hidden = state.tab !== 'alerts';
        }
        if (els.filterWrap) {
            els.filterWrap.querySelectorAll('[data-filter]').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    var want = chip.dataset.filter === 'unread';
                    if (want === state.unreadOnly) { return; }
                    state.unreadOnly = want;
                    els.filterWrap.querySelectorAll('[data-filter]').forEach(function (c) {
                        var on = (c.dataset.filter === 'unread') === want;
                        c.classList.toggle('is-on', on);
                        c.setAttribute('aria-pressed', on ? 'true' : 'false');
                    });
                    loadTab('alerts', true);
                });
            });
        }

        function selectTab(tab, force) {
            state.tab = tab;
            root.querySelectorAll('.nc-tab').forEach(function (t) {
                var on = t.dataset.tab === tab;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            if (els.alerts) { els.alerts.hidden = tab !== 'alerts'; }
            if (els.ann) { els.ann.hidden = tab !== 'announcements'; }
            if (els.tasks) { els.tasks.hidden = tab !== 'tasks'; }
            syncMarkAll();
            syncFilter();
            loadTab(tab, force);
        }

        function handleList(el, kind) {
            el.addEventListener('click', function (e) {
                var doBtn = e.target.closest('[data-do]');
                if (doBtn && kind === 'tasks') {
                    e.stopPropagation();
                    var taskItem = doBtn.closest('[data-task]');
                    doBtn.classList.add('is-busy');
                    post('task_update', { task_id: taskItem.dataset.task, task_status: doBtn.dataset.do })
                        .then(function (d) {
                            if (d && d.status === 'success') { loadTab('tasks', true); refreshSummary(); }
                            else { doBtn.classList.remove('is-busy'); toast((d && d.message) || 'Could not update the task.', 'err'); }
                        })
                        .catch(function () { doBtn.classList.remove('is-busy'); toast('Network error — not updated.', 'err'); });
                    return;
                }
                var item = e.target.closest('.nc-item');
                if (!item) { return; }
                /* Optimistic read (P73 Phase 4, fixes D7): the unread state
                 * clears INSTANTLY, the write confirms in the background,
                 * and any failure reverts by refetching the authoritative
                 * list — the read action can never feel dead. */
                if ((kind === 'alerts' && item.dataset.id) || (kind === 'announcements' && item.dataset.ann)) {
                    var wasUnread = item.classList.contains('nc-unread');
                    if (!wasUnread) { return; }
                    item.classList.remove('nc-unread');
                    var dot = item.querySelector('.nc-dot'); if (dot) { dot.remove(); }
                    var key = kind === 'announcements' ? 'announcements' : 'alerts';
                    state.summary[key] = Math.max(0, (state.summary[key] || 0) - 1);
                    setCount(kind === 'announcements' ? els.cAnn : els.cAlerts, state.summary[key]);
                    syncMarkAll();
                    var readAction = kind === 'announcements' ? 'announcement_read' : 'mark_read';
                    var readId = kind === 'announcements' ? item.dataset.ann : item.dataset.id;
                    post(readAction, { id: readId }).then(function (d) {
                        if (d && d.status === 'success') { refreshSummary(); }
                        else { loadTab(state.tab, true); refreshSummary(); toast('Could not mark as read.', 'err'); }
                    }).catch(function () { loadTab(state.tab, true); refreshSummary(); toast('Could not mark as read.', 'err'); });
                } else if (kind === 'tasks' && item.dataset.task) {
                    var btn = item.querySelector('[data-do="completed"]') || item.querySelector('.nc-btn');
                    if (btn) { btn.classList.add('is-busy'); }
                    post('task_update', { task_id: item.dataset.task, task_status: 'completed' })
                        .then(function (d) {
                            if (d && d.status === 'success') { loadTab('tasks', true); refreshSummary(); }
                            else { if (btn) { btn.classList.remove('is-busy'); } toast((d && d.message) || 'Could not complete the task.', 'err'); }
                        })
                        .catch(function () { if (btn) { btn.classList.remove('is-busy'); } toast('Network error — not updated.', 'err'); });
                }
            });
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    var item = e.target.closest('.nc-item');
                    if (item) { e.preventDefault(); item.click(); }
                }
            });
        }

        root.querySelectorAll('.nc-tab').forEach(function (t) {
            t.addEventListener('click', function () { selectTab(t.dataset.tab); });
        });
        if (els.markAll) {
            els.markAll.addEventListener('click', function () {
                var scope = state.tab === 'announcements' ? 'announcements' : 'alerts';
                /* Optimistic (P73 Phase 4): every unread dot clears instantly
                 * and the count zeroes; the write confirms in the background
                 * and a failure reverts by refetching the list. */
                var list = state.tab === 'announcements' ? els.ann : els.alerts;
                if (list) {
                    list.querySelectorAll('.nc-item.nc-unread').forEach(function (n) {
                        n.classList.remove('nc-unread');
                        var d = n.querySelector('.nc-dot'); if (d) { d.remove(); }
                    });
                }
                state.summary[scope] = 0;
                setCount(scope === 'announcements' ? els.cAnn : els.cAlerts, 0);
                syncMarkAll();
                els.markAll.classList.add('is-busy');
                post('mark_all_read', { scope: scope })
                    .then(function (d) {
                        els.markAll.classList.remove('is-busy');
                        if (d && d.status === 'success') { refreshSummary(); }
                        else { loadTab(state.tab, true); refreshSummary(); toast((d && d.message) || 'Could not mark all as read.', 'err'); }
                    })
                    .catch(function () { els.markAll.classList.remove('is-busy'); loadTab(state.tab, true); refreshSummary(); toast('Network error — try again.', 'err'); });
            });
        }
        handleList(els.alerts, 'alerts');
        handleList(els.ann, 'announcements');
        handleList(els.tasks, 'tasks');

        return {
            root: root,
            applySummary: function (s) {
                state.summary = s || {};
                setCount(els.cAlerts, state.summary.alerts || 0);
                setCount(els.cAnn, state.summary.announcements || 0);
                setCount(els.cTasks, state.summary.tasks || 0);
                syncMarkAll();
            },
            selectTab: selectTab,
            reloadCurrent: function () { loadTab(state.tab, true); },
            prime: function () { loadTab(state.tab); }
        };
    }

    /* ════════════════════════════════════════════════════════════════
       BELL (Phase 1 behaviour, now on the shared factory)
       ════════════════════════════════════════════════════════════════ */
    var bells = [];
    var bell = {
        open: false, opener: null, lastTotal: -1,
        ctrl: makeListController(panel),
        msgLink: panel.querySelector('.nc-msg-link')
    };

    function isMobile() { return MOBILE.matches; }

    function placePanel() {
        if (isMobile() || !bell.opener) {
            panel.style.left = panel.style.top = '';
            return;
        }
        var r = bell.opener.getBoundingClientRect();
        var pw = panel.offsetWidth, ph = panel.offsetHeight;
        var vw = window.innerWidth, vh = window.innerHeight, M = 8;
        var left = r.right - pw;
        left = Math.max(M, Math.min(left, vw - M - pw));
        var top = r.bottom + 10;
        if (top + ph > vh - M) { top = r.top - ph - 10; }
        top = Math.max(M, Math.min(top, vh - M - ph));
        panel.style.left = Math.round(left) + 'px';
        panel.style.top = Math.round(top) + 'px';
    }
    var rafPending = false;
    function schedulePlace() {
        if (rafPending || !bell.open) { return; }
        rafPending = true;
        requestAnimationFrame(function () { rafPending = false; placePanel(); });
    }
    window.addEventListener('resize', schedulePlace);
    window.addEventListener('scroll', schedulePlace, true);
    MOBILE.addEventListener('change', schedulePlace);

    function openBellPanel(btn) {
        closeSection();                                 // the section supersedes the popover
        bell.open = true;
        bell.opener = btn;
        panel.hidden = false;
        panel.classList.add('is-open');
        panel.setAttribute('aria-modal', isMobile() ? 'true' : 'false');
        if (scrim) { scrim.hidden = !isMobile(); }
        bells.forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
        placePanel();
        bell.ctrl.selectTab(bell.ctrl.root.querySelector('.nc-tab.is-active') ? bell.ctrl.root.querySelector('.nc-tab.is-active').dataset.tab : 'alerts', true);
        refreshSummary();
        panel.focus();
    }
    function closeBellPanel() {
        bell.open = false;
        panel.hidden = true;
        panel.classList.remove('is-open');
        if (scrim) { scrim.hidden = true; }
        bells.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        if (bell.opener) { bell.opener.focus(); }
        bell.opener = null;
    }
    var xBtn = panel.querySelector('.nc-x');
    if (xBtn) { xBtn.addEventListener('click', closeBellPanel); }
    if (scrim) { scrim.addEventListener('click', closeBellPanel); }

    /* ════════════════════════════════════════════════════════════════
       SECTION (Phase 2)
       ════════════════════════════════════════════════════════════════ */
    var sec = null;

    /* Section controller functions — MUST live at IIFE top level (strict
       mode block-scopes function declarations; the Phase-2.2 incident had
       them inside the if/else blocks, so every bell click died with
       "closeSection is not defined"). Pages without a section no-op. */
    function setView(view) {
        if (!sec) { return; }
        sec.state.view = view;
        sec.el.querySelectorAll('.nc-sec-tab').forEach(function (t) {
            var on = t.dataset.ncView === view;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        sec.el.querySelectorAll('[data-nc-viewpane]').forEach(function (p) {
            p.hidden = p.dataset.ncViewpane !== view;
        });
        if (view === 'inbox' && !sec.state.inboxInit) { sec.state.inboxInit = true; sec.inbox.prime(); }
        if (view === 'messages') {
            if (!sec.state.msgsInit) { sec.state.msgsInit = true; initMessages(); }
            pollStart('msgs', pollMsgs, POLL_MS);
        } else {
            pollStop('msgs');
        }
    }
    function openSection(view) {
        if (!sec) { return; }
        if (sec.pageMode) { if (view) { setView(view); } return; }
        clearTimeout(sec.state.closeTimer);
        closeBellPanel();
        sec.state.open = true;
        sec.el.hidden = false;
        void sec.el.offsetWidth;                         // start the slide transition
        sec.el.classList.add('is-open');
        setView(view || sec.state.view);
        sec.el.focus();
        try { history.replaceState(null, '', '#' + (view || sec.state.view)); } catch (e) {}
    }
    function closeSection() {
        if (!sec || sec.pageMode || !sec.state.open) { return; }
        sec.state.open = false;
        sec.el.classList.remove('is-open');
        pollStop('msgs');
        sec.state.closeTimer = setTimeout(function () { sec.el.hidden = true; }, 260);
    }

    if (section) {
        var pageMode = section.classList.contains('nc-sec--page');
        if (!document.querySelector('#wbwsBottomNav, .school-bottom-nav')) { section.classList.add('nc-sec--nonav'); }

        var inboxPane = section.querySelector('[data-nc-viewpane="inbox"]');
        var secState = {
            open: pageMode,
            view: section.dataset.initial === 'messages' ? 'messages' : 'inbox',
            inboxInit: false,
            msgsInit: false,
            activeThread: null,
            closeTimer: null
        };
        sec = {
            el: section,
            inbox: makeListController(inboxPane),
            pageMode: pageMode,
            state: secState
        };

        var secScrim = document.querySelector('.nc-scrim[data-nc-sheet-scrim]'); // shared by sheets
        var announceBtn = section.querySelector('[data-nc-announce]');
        var newThreadBtn = section.querySelector('[data-nc-newthread]');

        sec.close = closeSection;
        sec.open = openSection;

        var secClose = section.querySelector('[data-nc-close]');
        if (secClose) { secClose.addEventListener('click', closeSection); }

        section.querySelectorAll('.nc-sec-tab').forEach(function (t) {
            t.addEventListener('click', function () { setView(t.dataset.ncView); });
        });

        if (announceBtn) {
            announceBtn.addEventListener('click', function () { openSheet('[data-nc-composer]'); });
        }
        if (newThreadBtn) {
            newThreadBtn.addEventListener('click', function () { openSheet('[data-nc-newsheet]'); });
        }
    }

    /* ── openers: anything with data-comm-open (sidebar, bottom nav,
         bell links). Falls back to the standalone pages when the page
         has no section (e.g. themed frontend shells). ─────────────── */
    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-comm-open]');
        if (opener) {
            if (section) {
                e.preventDefault();
                var v = opener.getAttribute('data-comm-open');
                if (v === 'compose') { openSection('inbox'); openSheet('[data-nc-composer]'); }
                else { openSection(v || 'inbox'); }
            }
            return;
        }
        // Any other in-page navigation (sidebar sections, bottom nav) closes
        // the section — it is a destination, not a persistent overlay.
        if (sec && sec.state.open && !sec.pageMode &&
            e.target.closest('[data-sec],[data-section],.wbws-bnav-btn,.school-bottom-nav-btn') &&
            !e.target.closest('[data-nc-section]')) {
            closeSection();
        }
    });

    /* ── keyboard: sheets first, then bell, then section ──────────── */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        if (closeSheetIfOpen()) { return; }
        if (bell.open) { closeBellPanel(); return; }
        if (sec && sec.state.open && !sec.pageMode) { closeSection(); }
    });

    /* ── outside-click closes the bell popover ────────────────────── */
    document.addEventListener('click', function (e) {
        if (!bell.open) { return; }
        var inBell = e.target.closest && e.target.closest('.nc-root');
        if (!panel.contains(e.target) && !inBell) { closeBellPanel(); }
    });

    /* ════════════════════════════════════════════════════════════════
       SHEETS (new conversation + announcement composer)
       ════════════════════════════════════════════════════════════════ */
    var sheetScrim = document.querySelector('.nc-scrim[data-nc-sheet-scrim]');
    var openSheetEl = null;

    function openSheet(sel) {
        var el = document.querySelector(sel);
        if (!el) { return; }
        closeSheetIfOpen();
        openSheetEl = el;
        el.hidden = false;
        if (sheetScrim) { sheetScrim.hidden = false; }
        if (el.hasAttribute('data-nc-composer')) { primeComposer(el); cmpGo(el, 1); }
        if (el.hasAttribute('data-nc-newsheet')) { primePartners(el); }
    }
    function closeSheetIfOpen() {
        if (!openSheetEl) { return false; }
        openSheetEl.hidden = true;
        openSheetEl = null;
        if (sheetScrim) { sheetScrim.hidden = true; }
        return true;
    }
    if (sheetScrim) { sheetScrim.addEventListener('click', closeSheetIfOpen); }
    document.querySelectorAll('.nc-sheet-card textarea').forEach(function (t) {
        t.addEventListener('input', function () { autoGrow(t); });
    });
    document.querySelectorAll('[data-nc-newcancel]').forEach(function (b) { b.addEventListener('click', closeSheetIfOpen); });
    document.querySelectorAll('[data-nc-cmpcancel]').forEach(function (b) { b.addEventListener('click', closeSheetIfOpen); });

    /* ── announcement composer ────────────────────────────────────── */
    var cmpTargets = null;
    function primeComposer(sheet) {
        if (cmpTargets) { return; }
        var rolesEl = sheet.querySelector('[data-nc-roles]');
        var usersEl = sheet.querySelector('[data-nc-targetusers]');
        get('targets').then(function (d) {
            cmpTargets = d || {};
            rolesEl.innerHTML = Object.keys(cmpTargets.roles || {}).map(function (r) {
                return '<div class="nc-pick-p" data-role="' + esc(r) + '">' + esc(cmpTargets.roles[r]) + '</div>';
            }).join('') || '<div class="nc-pick-p" style="cursor:default">No groups available</div>';
            rolesEl.querySelectorAll('[data-role]').forEach(function (p) {
                p.addEventListener('click', function () { p.classList.toggle('is-on'); });
            });
            usersEl.innerHTML = (cmpTargets.users || []).map(function (u) {
                return '<label class="nc-pickrow"><input type="checkbox" value="' + esc(u.id) + '">' +
                    '<span><span class="nc-pick-name">' + esc(u.label) + '</span></span></label>';
            }).join('') || '<div class="nc-empty">Nobody to announce to.</div>';
        }).catch(function () {
            errorState(usersEl, 'Could not load recipients.', function () { cmpTargets = null; primeComposer(sheet); });
        });
    }
    document.querySelectorAll('[data-nc-audience] .nc-pick-p').forEach(function (p) {
        p.addEventListener('click', function () {
            var wrap = p.closest('[data-nc-audience]');
            wrap.querySelectorAll('.nc-pick-p').forEach(function (q) { q.classList.remove('is-on'); });
            p.classList.add('is-on');
            var root = wrap.closest('.nc-sheet-card');
            root.querySelector('[data-nc-roleswrap]').hidden = p.dataset.a !== 'roles';
            root.querySelector('[data-nc-userswrap]').hidden = p.dataset.a !== 'users';
        });
    });
    /* ── announcement composer: 3-step form (P73 Phase 4) ───────────
     * Content → Audience → Review. Per-step validation with inline
     * errors, a progress indicator, Back/Next navigation, and a review
     * step so publishing is never a blind submit. */
    var cmpStepN = 1;
    function cmpGo(sheet, n) {
        cmpStepN = n;
        sheet.querySelectorAll('[data-nc-cmppane]').forEach(function (p) {
            p.hidden = p.dataset.ncCmppane !== String(n);
        });
        sheet.querySelectorAll('[data-nc-cmpsteps] li').forEach(function (s) {
            var sn = parseInt(s.dataset.step, 10);
            s.classList.toggle('is-on', sn === n);
            s.classList.toggle('is-done', sn < n);
        });
        var back = sheet.querySelector('[data-nc-cmpback]');
        var next = sheet.querySelector('[data-nc-cmpnext]');
        var pub = sheet.querySelector('[data-nc-cmppublish]');
        if (back) { back.hidden = n === 1; }
        if (next) { next.hidden = n === 3; }
        if (pub) { pub.hidden = n !== 3; }
        if (n === 3) { cmpReview(sheet); }
        var err = sheet.querySelector('[data-nc-cmperr]');
        if (err) { err.textContent = ''; }
    }
    function cmpValidate(sheet, n) {
        var err = sheet.querySelector('[data-nc-cmperr]');
        if (n === 1) {
            if (!sheet.querySelector('#ncCmpTitle').value.trim()) { err.textContent = 'Give the announcement a title.'; return false; }
            if (!sheet.querySelector('#ncCmpBody').value.trim()) { err.textContent = 'Write the message first.'; return false; }
        }
        if (n === 2) {
            var audience = sheet.querySelector('[data-nc-audience] .nc-pick-p.is-on');
            audience = audience ? audience.dataset.a : 'roles';
            if (audience === 'roles' && !sheet.querySelector('[data-nc-roles] .nc-pick-p.is-on')) {
                err.textContent = 'Choose at least one group.'; return false;
            }
            if (audience === 'users' && !sheet.querySelector('[data-nc-targetusers] input:checked')) {
                err.textContent = 'Choose at least one recipient.'; return false;
            }
        }
        return true;
    }
    function cmpReview(sheet) {
        var audience = sheet.querySelector('[data-nc-audience] .nc-pick-p.is-on');
        audience = audience ? audience.dataset.a : 'roles';
        var who;
        if (audience === 'roles') {
            var names = [];
            sheet.querySelectorAll('[data-nc-roles] .nc-pick-p.is-on').forEach(function (p) { names.push(p.textContent); });
            who = names.length === 1 ? names[0] : names.length + ' groups';
        } else {
            var c = sheet.querySelectorAll('[data-nc-targetusers] input:checked').length;
            who = c + (c === 1 ? ' person' : ' people');
        }
        var review = sheet.querySelector('[data-nc-cmpreview]');
        if (review) {
            review.innerHTML =
                '<div><dt>Title</dt><dd>' + esc(sheet.querySelector('#ncCmpTitle').value) + '</dd></div>' +
                '<div><dt>Message</dt><dd>' + esc(sheet.querySelector('#ncCmpBody').value) + '</dd></div>' +
                '<div><dt>Priority</dt><dd>' + esc(sheet.querySelector('#ncCmpPriority').value) + '</dd></div>' +
                '<div><dt>Audience</dt><dd>' + esc(who) + '</dd></div>';
        }
    }
    document.querySelectorAll('[data-nc-cmpnext]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sheet = btn.closest('.nc-sheet');
            if (cmpStepN < 3 && cmpValidate(sheet, cmpStepN)) { cmpGo(sheet, cmpStepN + 1); }
        });
    });
    document.querySelectorAll('[data-nc-cmpback]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sheet = btn.closest('.nc-sheet');
            if (cmpStepN > 1) { cmpGo(sheet, cmpStepN - 1); }
        });
    });

    document.querySelectorAll('[data-nc-cmppublish]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sheet = btn.closest('.nc-sheet-card');
            var err = sheet.querySelector('[data-nc-cmperr]');
            err.textContent = '';
            if (!cmpValidate(sheet, 1) || !cmpValidate(sheet, 2)) { return; }
            var audience = sheet.querySelector('[data-nc-audience] .nc-pick-p.is-on');
            audience = audience ? audience.dataset.a : 'roles';
            var roles = [], users = [];
            if (audience === 'roles') {
                sheet.querySelectorAll('[data-nc-roles] .nc-pick-p.is-on').forEach(function (p) { roles.push(p.dataset.role); });
            } else {
                sheet.querySelectorAll('[data-nc-targetusers] input:checked').forEach(function (c) { users.push(c.value); });
            }
            btn.classList.add('is-busy');
            post('compose', {
                title: sheet.querySelector('#ncCmpTitle').value,
                body: sheet.querySelector('#ncCmpBody').value,
                priority: sheet.querySelector('#ncCmpPriority').value,
                audience: audience,
                roles: roles.join(','),
                user_ids: users.join(',')
            }).then(function (d) {
                btn.classList.remove('is-busy');
                if (d && d.status === 'success') {
                    closeSheetIfOpen();
                    sheet.querySelector('#ncCmpTitle').value = '';
                    sheet.querySelector('#ncCmpBody').value = '';
                    sheet.querySelectorAll('[data-nc-roles] .nc-pick-p.is-on').forEach(function (p) { p.classList.remove('is-on'); });
                    sheet.querySelectorAll('[data-nc-targetusers] input:checked').forEach(function (c) { c.checked = false; });
                    cmpGo(sheet, 1);   // next announcement starts clean at step 1
                    toast('Announcement published ✓', 'ok');
                    if (sec) { sec.inbox.reloadCurrent(); }
                    refreshSummary();
                } else { err.textContent = (d && d.message) || 'Could not publish.'; }
            }).catch(function () { btn.classList.remove('is-busy'); err.textContent = 'Network error — try again.'; });
        });
    });

    /* ════════════════════════════════════════════════════════════════
       MESSAGES (Phase 2 interim — Telegram-grade in Phase 3)
       ════════════════════════════════════════════════════════════════ */
    var mEls = null;
    function initMessages() {
        if (!section) { return; }
        mEls = {
            threads: section.querySelector('[data-nc-threads]'),
            conv: section.querySelector('[data-nc-conv]'),
            convEmpty: section.querySelector('[data-nc-convempty]'),
            convHead: section.querySelector('[data-nc-convhead]'),
            convTitle: section.querySelector('[data-nc-convtitle]'),
            convWho: section.querySelector('[data-nc-convwho]'),
            convBack: section.querySelector('[data-nc-convback]'),
            msgs: section.querySelector('[data-nc-msgs]'),
            form: section.querySelector('[data-nc-form]'),
            reply: section.querySelector('[data-nc-reply]'),
            send: section.querySelector('[data-nc-send]'),
            editing: section.querySelector('[data-nc-editing]')
        };
        mEls.convBack.addEventListener('click', function () { closeConversation(); });
        if (mEls.editing) {
            mEls.editing.querySelector('[data-nc-editcancel]').addEventListener('click', function () { setEditing(null); });
        }
        wireMessageMenu();
        mEls.form.addEventListener('submit', function (e) { e.preventDefault(); sendReply(); });
        // Telegram-style composer: auto-grows, Enter sends, Shift+Enter = newline.
        mEls.reply.addEventListener('input', function () { autoGrow(mEls.reply); });
        mEls.reply.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                sendReply();
            }
        });
        loadThreads();
    }

    function loadThreads(keepActive) {
        if (!mEls) { return; }
        var el = mEls.threads;
        get('threads').then(function (d) {
            var rows = (d && d.threads) || [];
            if (!rows.length) {
                emptyState(el, 'fa-comment-dots', canMessage() ? 'No conversations yet — start one with “New”.' : 'No conversations yet.');
                return;
            }
            el.innerHTML = rows.map(function (t) {
                return '<div class="nc-im-thread ' + (t.id == sec.state.activeThread ? 'is-on' : '') + '" data-th="' + esc(t.id) + '">' +
                    '<div class="nc-im-avatar">' + esc(initials(t.participants_label || '?')) + '</div>' +
                    '<div class="nc-im-tmain"><div class="nc-im-trow"><span class="nc-im-tt">' + esc(t.subject) + '</span>' +
                    (t.unread_count > 0 ? '<span class="nc-im-unread">' + (t.unread_count > 9 ? '9+' : t.unread_count) + '</span>'
                                        : '<span class="nc-im-ttp">' + esc(relTime(t.last_message_at || t.created_at)) + '</span>') + '</div>' +
                    '<div class="nc-im-twho">' + esc(t.participants_label || '') + '</div>' +
                    '<div class="nc-im-tlast">' + esc(t.last_body || '') + '</div></div></div>';
            }).join('');
            el.querySelectorAll('[data-th]').forEach(function (n) {
                n.addEventListener('click', function () {
                    openThread(n.dataset.th, n.querySelector('.nc-im-tt').textContent, n.querySelector('.nc-im-twho').textContent);
                });
            });
        }).catch(function () {
            errorState(el, 'Could not load conversations.', function () { loadThreads(keepActive); });
        });
    }

    function openThread(id, subject, who) {
        if (!mEls) { return; }
        sec.state.activeThread = id;
        mEls.threads.querySelectorAll('[data-th]').forEach(function (n) { n.classList.toggle('is-on', n.dataset.th == id); });
        mEls.convEmpty.style.display = 'none';
        mEls.convHead.hidden = false;
        mEls.convTitle.textContent = subject;
        mEls.convWho.textContent = who;
        mEls.form.hidden = false;
        if (MOBILE.matches) { mEls.conv.classList.add('is-on'); }
        skeleton(mEls.msgs);
        get('thread', '&id=' + encodeURIComponent(id)).then(function (d) {
            renderMessages((d && d.messages) || [], (d && d.read_watermark) || 0);
            loadThreads(true);
        }).catch(function () {
            errorState(mEls.msgs, 'Could not load the conversation.', function () { openThread(id, subject, who); });
        });
    }
    function closeConversation() {
        if (!mEls) { return; }
        sec.state.activeThread = null;
        mEls.conv.classList.remove('is-on');
        mEls.convEmpty.style.display = '';
        mEls.convHead.hidden = true;
        mEls.form.hidden = true;
        mEls.msgs.innerHTML = '';
    }
    function renderMessages(msgs, watermark) {
        var el = mEls.msgs;
        var html = '', lastDay = '';
        msgs.forEach(function (m) {
            var day = dayLabel(m.created_at);
            if (day !== lastDay) { html += '<div class="nc-daysep">' + esc(day) + '</div>'; lastDay = day; }
            // Deleted (P73 Telegram-grade management): tombstone, no body,
            // no menu, no receipt — the content never comes back.
            if (m.deleted) {
                html += '<div class="nc-msg ' + (m.mine ? 'mine' : '') + ' nc-msg--deleted" data-mid="' + esc(m.id) + '">' +
                    '<div class="nc-bubble nc-bubble--gone"><i class="fa-solid fa-ban" aria-hidden="true"></i> This message was deleted</div></div>';
                return;
            }
            // Read receipts (P73 Phase 3): my messages show ✓✓ once every
            // other participant's watermark has reached them.
            var receipt = '';
            if (m.mine) {
                receipt = (watermark && (parseInt(m.id, 10) <= watermark))
                    ? '<span class="nc-seen"><i class="fa-solid fa-check-double"></i> Seen</span>'
                    : '<i class="fa-solid fa-check"></i> Sent';
            }
            var edited = m.edited ? ' <span class="nc-edited">edited</span>' : '';
            // Own live messages carry a ⋯ menu (Edit / Delete), revealed on
            // hover/tap — Telegram-style progressive disclosure.
            var menu = m.mine
                ? '<div class="nc-msg-menu" data-msgmenu>' +
                  '<button type="button" class="nc-msg-menu-btn" data-msg-menu aria-label="Message options" tabindex="0"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>' +
                  '<div class="nc-msg-menu-pop" role="menu">' +
                  '<button type="button" class="nc-msg-menu-item" role="menuitem" data-msg-edit><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button>' +
                  '<button type="button" class="nc-msg-menu-item nc-msg-menu-item--danger" role="menuitem" data-msg-del><i class="fa-solid fa-trash" aria-hidden="true"></i> Delete</button>' +
                  '<button type="button" class="nc-msg-menu-item nc-msg-menu-item--confirm" role="menuitem" data-msg-del-yes hidden><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Delete for everyone?</button>' +
                  '</div></div>'
                : '';
            html += '<div class="nc-msg ' + (m.mine ? 'mine' : '') + '" data-mid="' + esc(m.id) + '">' + menu +
                (m.mine ? '' : '<div class="nc-meta" style="margin-bottom:2px"><b>' + esc(m.sender_name) + '</b> · ' + esc(m.sender_label) + '</div>') +
                '<div class="nc-bubble">' + esc(m.body) + '</div>' +
                '<div class="nc-meta">' + esc(timeHM(m.created_at)) + edited + (m.mine ? ' · ' + receipt : '') + '</div></div>';
        });
        el.innerHTML = html || '<div class="nc-empty"><i class="fa-regular fa-comment"></i>No messages yet.</div>';
        el.scrollTop = el.scrollHeight;
    }
    function refreshOpenThread() {
        if (!mEls || !sec.state.activeThread) { return; }
        get('thread', '&id=' + encodeURIComponent(sec.state.activeThread)).then(function (r) {
            renderMessages((r && r.messages) || [], (r && r.read_watermark) || 0);
        });
    }
    /* ── message management: edit + delete OWN messages (P73,
     *     Telegram-grade) — ⋯ menu on own bubbles, edit mode in the
     *     composer, delete with an inline confirmation + tombstone. */
    var editState = null;   // { id } while the composer is in edit mode

    function setEditing(node) {
        if (!mEls) { return; }
        if (!node) {
            editState = null;
            if (mEls.editing) { mEls.editing.hidden = true; }
            mEls.reply.placeholder = 'Write a reply…';
            mEls.reply.value = '';          // cancel never leaves edit text to send by accident
            mEls.reply.style.height = '';
            return;
        }
        editState = { id: node.dataset.mid };
        if (mEls.editing) { mEls.editing.hidden = false; }
        mEls.reply.value = node.querySelector('.nc-bubble').textContent;
        mEls.reply.placeholder = 'Editing message…';
        autoGrow(mEls.reply);
        mEls.reply.focus();
    }

    function tombstone(node) {
        node.classList.add('nc-msg--deleted', 'nc-msg--gone');
        node.innerHTML = '<div class="nc-bubble nc-bubble--gone"><i class="fa-solid fa-ban" aria-hidden="true"></i> This message was deleted</div>';
    }

    function wireMessageMenu() {
        mEls.msgs.addEventListener('click', function (e) {
            var t = e.target;
            if (t.closest('[data-msg-menu]')) {
                var msgNode = t.closest('.nc-msg');
                var was = msgNode.classList.contains('is-open');
                mEls.msgs.querySelectorAll('.nc-msg.is-open').forEach(function (n) { n.classList.remove('is-open'); });
                msgNode.classList.toggle('is-open', !was);
                return;
            }
            if (t.closest('[data-msg-edit]')) {
                t.closest('.nc-msg').classList.remove('is-open');
                setEditing(t.closest('.nc-msg'));
                return;
            }
            if (t.closest('[data-msg-del]')) {
                var yes = t.closest('.nc-msg').querySelector('[data-msg-del-yes]');
                if (yes) { yes.hidden = false; }
                return;
            }
            if (t.closest('[data-msg-del-yes]')) {
                var node = t.closest('.nc-msg');
                node.classList.remove('is-open');
                var mid = node.dataset.mid;
                tombstone(node);   // optimistic — reverted by refetch on failure
                post('message_delete', { message_id: mid }).then(function (d) {
                    if (d && d.status === 'success') { loadThreads(true); }
                    else { refreshOpenThread(); toast((d && d.message) || 'Could not delete the message.', 'err'); }
                }).catch(function () { refreshOpenThread(); toast('Network error — not deleted.', 'err'); });
                return;
            }
            // any other click closes open menus
            mEls.msgs.querySelectorAll('.nc-msg.is-open').forEach(function (n) { n.classList.remove('is-open'); });
        });
    }

    /* Optimistic send (P73 Phase 3, fixes D11): the bubble appears
     * instantly in a pending state, is confirmed by a background refresh,
     * and on failure turns into an inline Retry — the message is never
     * lost and never blocks the composer. */
    var pendingSeq = 0;
    function sendReply() {
        if (!mEls || !sec.state.activeThread) { return; }
        var body = mEls.reply.value.trim();
        if (!body) { return; }
        // Edit mode (P73 Telegram-grade management): Enter saves the edit
        // optimistically — the bubble updates in place with an "edited"
        // marker; failures revert by refetching the authoritative thread.
        if (editState) {
            var eid = editState.id;
            var enode = mEls.msgs.querySelector('.nc-msg[data-mid="' + eid + '"]');
            if (enode) {
                enode.querySelector('.nc-bubble').textContent = body;
                var emeta = enode.querySelector('.nc-meta');
                if (emeta && !emeta.querySelector('.nc-edited')) {
                    var esp = document.createElement('span');
                    esp.className = 'nc-edited';
                    esp.textContent = 'edited';
                    emeta.appendChild(esp);
                }
            }
            mEls.reply.value = '';
            mEls.reply.style.height = '';
            setEditing(null);
            post('message_edit', { message_id: eid, body: body }).then(function (d) {
                if (d && d.status === 'success') { loadThreads(true); }
                else { refreshOpenThread(); toast((d && d.message) || 'Could not edit the message.', 'err'); }
            }).catch(function () { refreshOpenThread(); toast('Network error — not saved.', 'err'); });
            return;
        }
        var pid = 'nc-pending-' + (++pendingSeq);
        appendPendingMessage(pid, body);
        mEls.reply.value = '';
        mEls.reply.style.height = '';
        post('send_message', { thread_id: sec.state.activeThread, body: body }).then(function (d) {
            if (d && d.status === 'success') {
                var node = mEls.msgs.querySelector('[data-pending="' + pid + '"]');
                if (node) { node.remove(); }
                refreshOpenThread();
                loadThreads(true);
            } else {
                failPendingMessage(pid, (d && d.message) || 'Could not send.');
            }
        }).catch(function () { failPendingMessage(pid, 'Network error.'); });
    }
    function appendPendingMessage(pid, body) {
        var node = document.createElement('div');
        node.className = 'nc-msg mine nc-msg--pending';
        node.setAttribute('data-pending', pid);
        node.innerHTML = '<div class="nc-bubble">' + esc(body) + '</div>' +
            '<div class="nc-meta"><i class="fa-regular fa-clock" aria-hidden="true"></i> Sending…</div>';
        mEls.msgs.appendChild(node);
        mEls.msgs.scrollTop = mEls.msgs.scrollHeight;
    }
    function failPendingMessage(pid, why) {
        var node = mEls && mEls.msgs ? mEls.msgs.querySelector('[data-pending="' + pid + '"]') : null;
        if (!node) { return; }
        var body = node.querySelector('.nc-bubble').textContent;
        node.classList.add('nc-msg--failed');
        node.innerHTML = '<div class="nc-bubble">' + esc(body) + '</div>' +
            '<div class="nc-meta nc-meta--failed"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ' + esc(why) +
            ' · <button type="button" class="nc-retry nc-retry--msg">Retry</button></div>';
        node.querySelector('.nc-retry--msg').addEventListener('click', function () {
            node.remove();
            mEls.reply.value = body;
            sendReply();
        });
    }
    function pollMsgs() {
        if (!mEls || !sec.state.open || sec.state.view !== 'messages') { return Promise.resolve(); }
        var p = loadThreads(true);
        if (sec.state.activeThread) {
            p = get('thread', '&id=' + encodeURIComponent(sec.state.activeThread)).then(function (r) {
                renderMessages((r && r.messages) || [], (r && r.read_watermark) || 0);
            });
        }
        return p;
    }

    /* ── new conversation sheet: CONTACT LIST picker (P73 Phase 3,
     *     fixes D10) — search box, role groups, avatars, whole-row tap,
     *     keyboard operable (arrows/Enter), selection counter. ─────── */
    var partnerData = [];
    var partnerSel = {};
    var partnersLoaded = false;
    function renderPartners(box, search, emptyMsg, counter, q) {
        q = (q || '').trim().toLowerCase();
        var groups = {};
        (partnerData || []).forEach(function (p) {
            var label = String(p.label || p.full_name || '');
            var parts = label.split(' — ');
            var name = String(p.full_name || parts[0] || '?');
            var roleLabel = String(parts[1] || p.role || '');
            if (q && name.toLowerCase().indexOf(q) === -1 && roleLabel.toLowerCase().indexOf(q) === -1) { return; }
            var g = groups[p.role] = groups[p.role] || { label: roleLabel || p.role, people: [] };
            g.people.push({ id: p.id, name: name });
        });
        var html = '';
        Object.keys(groups).forEach(function (r) {
            html += '<div class="nc-contact-group"><div class="nc-contact-group-h">' + esc(groups[r].label) + '</div>';
            groups[r].people.forEach(function (p) {
                var on = !!partnerSel[p.id];
                html += '<div class="nc-contact' + (on ? ' is-on' : '') + '" data-pid="' + esc(p.id) +
                    '" role="option" aria-selected="' + on + '" tabindex="0">' +
                    '<span class="nc-im-avatar" aria-hidden="true">' + esc(initials(p.name)) + '</span>' +
                    '<span class="nc-contact-name">' + esc(p.name) + '</span>' +
                    '<span class="nc-contact-tick"><i class="fa-solid fa-check" aria-hidden="true"></i></span></div>';
            });
            html += '</div>';
        });
        box.innerHTML = html;
        if (emptyMsg) { emptyMsg.hidden = html !== ''; }
        syncPartnerCount(counter);
        box.querySelectorAll('.nc-contact').forEach(function (row) {
            row.addEventListener('click', function () {
                var id = row.getAttribute('data-pid');
                if (partnerSel[id]) {
                    delete partnerSel[id];
                    row.classList.remove('is-on');
                    row.setAttribute('aria-selected', 'false');
                } else {
                    partnerSel[id] = true;
                    row.classList.add('is-on');
                    row.setAttribute('aria-selected', 'true');
                }
                syncPartnerCount(counter);
            });
            row.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); row.click(); }
            });
        });
    }
    function syncPartnerCount(counter) {
        var n = Object.keys(partnerSel).length;
        if (counter) { counter.textContent = n + ' selected'; counter.hidden = n === 0; }
    }
    function primePartners(sheet) {
        var box = sheet.querySelector('[data-nc-partners]');
        var search = sheet.querySelector('[data-nc-partnersearch]');
        var emptyMsg = sheet.querySelector('[data-nc-partnersempty]');
        var counter = sheet.querySelector('[data-nc-partnercount]');
        if (!box || partnersLoaded) { return; }
        get('partners').then(function (d) {
            partnerData = (d && d.partners) || [];
            partnersLoaded = true;
            renderPartners(box, search, emptyMsg, counter, '');
            if (search) {
                search.addEventListener('input', function () {
                    renderPartners(box, search, emptyMsg, counter, search.value);
                });
                search.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        var first = box.querySelector('.nc-contact');
                        if (first) { first.click(); search.value = ''; renderPartners(box, search, emptyMsg, counter, ''); }
                    }
                });
            }
        }).catch(function () {
            errorState(box, 'Could not load people.', function () { partnersLoaded = false; primePartners(sheet); });
        });
    }
    document.querySelectorAll('[data-nc-newsend]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sheet = btn.closest('.nc-sheet-card');
            var err = sheet.querySelector('[data-nc-newerr]');
            err.textContent = '';
            var to = Object.keys(partnerSel);
            if (!to.length) { err.textContent = 'Choose at least one recipient.'; return; }
            var subject = sheet.querySelector('#ncNewSubject').value;
            var body = sheet.querySelector('#ncNewBody').value;
            if (!body.trim()) { err.textContent = 'Write a message first.'; return; }
            btn.classList.add('is-busy');
            post('thread_start', { to: to.join(','), subject: subject, body: body }).then(function (d) {
                btn.classList.remove('is-busy');
                if (d && d.status === 'success') {
                    closeSheetIfOpen();
                    sheet.querySelector('#ncNewSubject').value = '';
                    sheet.querySelector('#ncNewBody').value = '';
                    partnerSel = {};
                    var psearch = sheet.querySelector('[data-nc-partnersearch]');
                    if (psearch) { psearch.value = ''; }
                    renderPartners(sheet.querySelector('[data-nc-partners]'), psearch,
                        sheet.querySelector('[data-nc-partnersempty]'), sheet.querySelector('[data-nc-partnercount]'), '');
                    toast('Conversation started ✓', 'ok');
                    loadThreads();
                    refreshSummary();
                } else { err.textContent = (d && d.message) || 'Could not start the conversation.'; }
            }).catch(function () { btn.classList.remove('is-busy'); err.textContent = 'Network error — try again.'; });
        });
    });

    /* ════════════════════════════════════════════════════════════════
       SUMMARY → badges, counts, permission-gated buttons
       ════════════════════════════════════════════════════════════════ */
    var lastSummary = null;
    function canMessage() { return !!(lastSummary && lastSummary.can_message !== false); }
    function applySummary(s) {
        lastSummary = s = s || {};
        var total = (s.alerts || 0) + (s.announcements || 0) + (s.messages || 0) + (s.tasks || 0);
        document.querySelectorAll('.nc-root .nc-badge').forEach(function (b) {
            b.textContent = total > 99 ? '99+' : String(total);
            b.hidden = !(total > 0);
        });
        bell.ctrl.applySummary(s);
        if (sec) {
            sec.inbox.applySummary(s);
            setCount(sec.el.querySelector('.nc-count[data-count="inbox"]'), (s.alerts || 0) + (s.announcements || 0) + (s.tasks || 0));
            setCount(sec.el.querySelector('.nc-count[data-count="messages"]'), s.messages || 0);
            if (s.can_announce === true) { var a = sec.el.querySelector('[data-nc-announce]'); if (a) { a.hidden = false; } }
            if (s.can_message === true) { var n = sec.el.querySelector('[data-nc-newthread]'); if (n) { n.hidden = false; } }
        }
        if (bell.msgLink && s.can_message === false) { bell.msgLink.hidden = true; }
    }
    function refreshSummary() {
        return get('summary').then(function (d) {
            if (d && d.status === 'success') { applySummary(d.summary); }
        });
    }

    /* ── permission pre-seed from the server-rendered partial (pages
         pass $NC_COMM_CTX so buttons are correct before first poll) ── */
    if (section) {
        var pre = {
            alerts: 0, announcements: 0, tasks: 0, messages: 0,
            can_announce: section.dataset.canAnnounce === '1' || undefined,
            can_message: section.dataset.canMessage === '1' || undefined
        };
        if (pre.can_announce !== undefined || pre.can_message !== undefined) { applySummary(pre); }
    }

    /* ── bind every bell on the page (multi-instance, ONE panel) ──── */
    function bindBells() {
        document.querySelectorAll('.nc-root').forEach(function (root) {
            var btn = root.querySelector('.nc-bell');
            if (!btn || btn.dataset.ncBound === '1') { return; }
            btn.dataset.ncBound = '1';
            bells.push(btn);
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (bell.open && bell.opener === btn) { closeBellPanel(); } else { openBellPanel(btn); }
            });
        });
    }

    /* ── deep links (#inbox / #messages / #compose) ────────────────── */
    function applyHash() {
        if (!section) { return; }
        var h = (location.hash || '').replace('#', '');
        if (h === 'messages') { openSection('messages'); }
        else if (h === 'inbox') { openSection('inbox'); }
        else if (h === 'compose' && lastSummary && lastSummary.can_announce) { openSection('inbox'); openSheet('[data-nc-composer]'); }
    }

    function boot() {
        bindBells();
        pollStart('summary', refreshSummary, POLL_MS);
        refreshSummary().then(applyHash);
        if (sec && sec.pageMode) { setView(sec.state.view); applyHash(); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
