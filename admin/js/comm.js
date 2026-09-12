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
            markAll: root.querySelector('.nc-mark-all')
        };
        var state = { tab: 'alerts', summary: {}, loadedTabs: {} };

        function loadTab(tab, force) {
            var el = tab === 'alerts' ? els.alerts : tab === 'announcements' ? els.ann : els.tasks;
            if (!el) { return; }
            if (state.loadedTabs[tab] && !force) { return; }
            skeleton(el);
            var run = function () {
                var p = tab === 'alerts' ? get('feed', '&limit=25').then(function (d) { renderAlerts(el, d); })
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
                if (kind === 'alerts' && item.dataset.id) {
                    post('mark_read', { id: item.dataset.id }).then(function (d) {
                        if (d && d.status === 'success') {
                            item.classList.remove('nc-unread');
                            var dot = item.querySelector('.nc-dot'); if (dot) { dot.remove(); }
                            refreshSummary();
                        }
                    }).catch(function () { toast('Could not mark as read.', 'err'); });
                } else if (kind === 'announcements' && item.dataset.ann) {
                    post('announcement_read', { id: item.dataset.ann }).then(function (d) {
                        if (d && d.status === 'success') {
                            item.classList.remove('nc-unread');
                            var dot2 = item.querySelector('.nc-dot'); if (dot2) { dot2.remove(); }
                            refreshSummary();
                        }
                    }).catch(function () { toast('Could not mark as read.', 'err'); });
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
                els.markAll.classList.add('is-busy');
                post('mark_all_read', { scope: scope })
                    .then(function (d) {
                        els.markAll.classList.remove('is-busy');
                        if (d && d.status === 'success') { loadTab(state.tab, true); refreshSummary(); }
                        else { toast((d && d.message) || 'Could not mark all as read.', 'err'); }
                    })
                    .catch(function () { els.markAll.classList.remove('is-busy'); toast('Network error — try again.', 'err'); });
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
    if (section) {
        var pageMode = section.classList.contains('nc-sec--page');
        if (!document.getElementById('wbwsBottomNav')) { section.classList.add('nc-sec--nonav'); }

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

        function setView(view) {
            secState.view = view;
            section.querySelectorAll('.nc-sec-tab').forEach(function (t) {
                var on = t.dataset.ncView === view;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            section.querySelectorAll('[data-nc-viewpane]').forEach(function (p) {
                p.hidden = p.dataset.ncViewpane !== view;
            });
            if (view === 'inbox' && !secState.inboxInit) { secState.inboxInit = true; sec.inbox.prime(); }
            if (view === 'messages') {
                if (!secState.msgsInit) { secState.msgsInit = true; initMessages(); }
                pollStart('msgs', pollMsgs, POLL_MS);
            } else {
                pollStop('msgs');
            }
        }

        function openSection(view) {
            if (pageMode) { if (view) { setView(view); } return; }
            clearTimeout(secState.closeTimer);
            closeBellPanel();
            secState.open = true;
            section.hidden = false;
            void section.offsetWidth;                    // start the slide transition
            section.classList.add('is-open');
            setView(view || secState.view);
            section.focus();
            try { history.replaceState(null, '', '#' + (view || secState.view)); } catch (e) {}
        }
        function closeSection() {
            if (!sec || pageMode || !secState.open) { return; }
            secState.open = false;
            section.classList.remove('is-open');
            pollStop('msgs');
            secState.closeTimer = setTimeout(function () { section.hidden = true; }, 260);
        }
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
    } else {
        function closeSection() {}                       // no section on this page
        function openSection() {}
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
            e.target.closest('[data-sec],[data-section],.wbws-bnav-btn') &&
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
        if (el.hasAttribute('data-nc-composer')) { primeComposer(el); }
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
    document.querySelectorAll('[data-nc-cmppublish]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sheet = btn.closest('.nc-sheet-card');
            var err = sheet.querySelector('[data-nc-cmperr]');
            err.textContent = '';
            var audience = sheet.querySelector('[data-nc-audience] .nc-pick-p.is-on');
            audience = audience ? audience.dataset.a : 'roles';
            var roles = [], users = [];
            if (audience === 'roles') {
                sheet.querySelectorAll('[data-nc-roles] .nc-pick-p.is-on').forEach(function (p) { roles.push(p.dataset.role); });
                if (!roles.length) { err.textContent = 'Choose at least one group.'; return; }
            } else {
                sheet.querySelectorAll('[data-nc-targetusers] input:checked').forEach(function (c) { users.push(c.value); });
                if (!users.length) { err.textContent = 'Choose at least one recipient.'; return; }
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
            send: section.querySelector('[data-nc-send]')
        };
        mEls.convBack.addEventListener('click', function () { closeConversation(); });
        mEls.form.addEventListener('submit', function (e) { e.preventDefault(); sendReply(); });
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
            renderMessages((d && d.messages) || []);
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
    function renderMessages(msgs) {
        var el = mEls.msgs;
        var html = '', lastDay = '';
        msgs.forEach(function (m) {
            var day = dayLabel(m.created_at);
            if (day !== lastDay) { html += '<div class="nc-daysep">' + esc(day) + '</div>'; lastDay = day; }
            html += '<div class="nc-msg ' + (m.mine ? 'mine' : '') + '">' +
                (m.mine ? '' : '<div class="nc-meta" style="margin-bottom:2px"><b>' + esc(m.sender_name) + '</b> · ' + esc(m.sender_label) + '</div>') +
                '<div class="nc-bubble">' + esc(m.body) + '</div>' +
                '<div class="nc-meta">' + esc(timeHM(m.created_at)) + (m.mine ? ' · Sent' : '') + '</div></div>';
        });
        el.innerHTML = html || '<div class="nc-empty"><i class="fa-regular fa-comment"></i>No messages yet.</div>';
        el.scrollTop = el.scrollHeight;
    }
    function sendReply() {
        if (!mEls || !sec.state.activeThread) { return; }
        var body = mEls.reply.value.trim();
        if (!body) { return; }
        mEls.send.disabled = true;
        post('send_message', { thread_id: sec.state.activeThread, body: body }).then(function (d) {
            mEls.send.disabled = false;
            if (d && d.status === 'success') {
                mEls.reply.value = '';
                get('thread', '&id=' + encodeURIComponent(sec.state.activeThread)).then(function (r) {
                    renderMessages((r && r.messages) || []);
                });
                loadThreads(true);
            } else { toast((d && d.message) || 'Could not send.', 'err'); }
        }).catch(function () { mEls.send.disabled = false; toast('Network error.', 'err'); });
    }
    function pollMsgs() {
        if (!mEls || !sec.state.open || sec.state.view !== 'messages') { return Promise.resolve(); }
        var p = loadThreads(true);
        if (sec.state.activeThread) {
            p = get('thread', '&id=' + encodeURIComponent(sec.state.activeThread)).then(function (r) {
                renderMessages((r && r.messages) || []);
            });
        }
        return p;
    }

    /* ── new conversation sheet: partner checkbox picker ──────────── */
    var partnersLoaded = false;
    function primePartners(sheet) {
        var el = sheet.querySelector('[data-nc-partners]');
        if (partnersLoaded) { return; }
        get('partners').then(function (d) {
            var rows = (d && d.partners) || [];
            el.innerHTML = rows.length ? rows.map(function (p) {
                return '<label class="nc-pickrow"><input type="checkbox" value="' + esc(p.id) + '">' +
                    '<span><span class="nc-pick-name">' + esc(p.label) + '</span></span></label>';
            }).join('') : '<div class="nc-empty">Nobody to message yet.</div>';
            partnersLoaded = true;
        }).catch(function () {
            errorState(el, 'Could not load people.', function () { partnersLoaded = false; primePartners(sheet); });
        });
    }
    document.querySelectorAll('[data-nc-newsend]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var sheet = btn.closest('.nc-sheet-card');
            var err = sheet.querySelector('[data-nc-newerr]');
            err.textContent = '';
            var to = [];
            sheet.querySelectorAll('[data-nc-partners] input:checked').forEach(function (c) { to.push(c.value); });
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
