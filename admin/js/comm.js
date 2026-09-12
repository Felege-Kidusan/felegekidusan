/* ============================================================================
 * SSMS Communication runtime — P73 Phase 1
 * ----------------------------------------------------------------------------
 * ONE runtime for every communication surface. Phase 1 ships the bell
 * popover / mobile sheet; Phase 2+ sections reuse the same client, poller,
 * state renderers and toast service. Zero globals, one IIFE.
 *
 * Architecture notes (docs/COMMUNICATION_UX_OVERHAUL.md §4):
 *   - The panel is a SINGLE element per page (emitted once by the PHP
 *     component) anchored with position:fixed and measured coordinates.
 *     This escapes every ancestor overflow/stacking-context trap — the
 *     root cause of the desktop "off-screen / clipped / overlapped" bugs.
 *   - Polling: one summary poll per page — visibility-paused, focus-
 *     refreshed, exponential backoff on failure, request de-duplication.
 *   - Every list state is explicit: skeleton → content | empty | error+Retry
 *     (the retry button actually retries). Writes show busy + optimistic UI
 *     and recover from failure with a toast.
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
    var scrim = document.querySelector('.nc-scrim[data-nc-scrim]');
    if (!panel) { return; } // nothing to drive on this page
    var API = panel.dataset.api || '/admin/api_notifications.php';
    var CSRF = panel.dataset.csrf || '';
    var inflight = {};

    function get(action, qs) {
        var key = 'GET ' + action + (qs || '');
        if (inflight[key]) { return inflight[key]; }   // de-dup: one request per key
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
                p.fails = 0;                              // success resets the cadence
                p.timer = setTimeout(p.tick, p.ms);
            }).catch(function () {
                p.fails = Math.min(p.fails + 1, 4);       // backoff x2..x8, capped
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
                setTimeout(function () { p.fnsafe || p.fn().catch(function () {}); }, stagger);
                stagger += 150;                            // no thundering herd on resume
                p.timer = setTimeout(p.tick, p.ms);
            }
        });
    });
    window.addEventListener('focus', function () {
        Object.keys(pollers).forEach(function (n) { pollers[n].fn().catch(function () {}); });
    });

    /* ── toast (tooltip-level elevation, above any open sheet) ────── */
    var toastEl = null, toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'nc-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
        toastEl.textContent = msg;
        toastEl.className = 'nc-toast' + (kind === 'ok' ? ' nc-ok' : kind === 'err' ? ' nc-err' : '');
        void toastEl.offsetWidth;                          // restart transition
        toastEl.classList.add('is-in');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('is-in'); }, 2600);
    }

    /* ── state renderers ──────────────────────────────────────────── */
    function skeleton(el) {
        el.innerHTML = '<div class="nc-skeleton"><span></span><span></span><span></span></div>';
    }
    function emptyState(el, icon, text) {
        el.innerHTML = '<div class="nc-empty"><i class="fa-solid ' + icon + '"></i>' + esc(text) + '</div>';
    }
    function errorState(el, text, retryFn) {
        el.innerHTML = '<div class="nc-empty nc-error"><i class="fa-solid fa-triangle-exclamation"></i>' + esc(text) +
            '<br><button type="button" class="nc-retry"><i class="fa-solid fa-rotate-right"></i> Retry</button></div>';
        var btn = el.querySelector('.nc-retry');
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            retryFn();
        });
    }

    /* ── content renderers ────────────────────────────────────────── */
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

    /* ── panel wiring ─────────────────────────────────────────────── */
    var bells = [];                                        // every .nc-bell on the page
    var els = {
        alerts: panel.querySelector('.nc-list[data-list="alerts"]'),
        ann: panel.querySelector('.nc-list[data-list="announcements"]'),
        tasks: panel.querySelector('.nc-list[data-list="tasks"]'),
        cAlerts: panel.querySelector('.nc-count[data-count="alerts"]'),
        cAnn: panel.querySelector('.nc-count[data-count="announcements"]'),
        cTasks: panel.querySelector('.nc-count[data-count="tasks"]'),
        cMsg: panel.querySelector('.nc-count[data-count="messages"]'),
        msgLink: panel.querySelector('.nc-msg-link'),
        markAll: panel.querySelector('.nc-mark-all')
    };
    var state = { open: false, opener: null, tab: 'alerts', summary: {}, loadedTabs: {}, lastTotal: -1 };

    function isMobile() { return MOBILE.matches; }

    function placePanel() {
        if (isMobile() || !state.opener) {                 // CSS sheet owns mobile
            panel.style.left = panel.style.top = '';
            return;
        }
        var r = state.opener.getBoundingClientRect();
        var pw = panel.offsetWidth, ph = panel.offsetHeight;
        var vw = window.innerWidth, vh = window.innerHeight, M = 8;
        var left = r.right - pw;                           // right-align to the bell
        left = Math.max(M, Math.min(left, vw - M - pw));
        var top = r.bottom + 10;
        if (top + ph > vh - M) { top = r.top - ph - 10; }  // flip above when tight below
        top = Math.max(M, Math.min(top, vh - M - ph));
        panel.style.left = Math.round(left) + 'px';
        panel.style.top = Math.round(top) + 'px';
    }

    var rafPending = false;
    function schedulePlace() {
        if (rafPending || !state.open) { return; }
        rafPending = true;
        requestAnimationFrame(function () { rafPending = false; placePanel(); });
    }
    window.addEventListener('resize', schedulePlace);
    window.addEventListener('scroll', schedulePlace, true); // capture: dashboards scroll inner containers
    MOBILE.addEventListener('change', schedulePlace);

    function loadTab(tab) {
        var el = tab === 'alerts' ? els.alerts : tab === 'announcements' ? els.ann : els.tasks;
        skeleton(el);
        var run = function () {
            var p = tab === 'alerts' ? get('feed', '&limit=25').then(function (d) { renderAlerts(el, d); })
                : tab === 'announcements' ? get('announcements', '&limit=25').then(function (d) { renderAnn(el, d); })
                : get('tasks', '&limit=25').then(function (d) { renderTasks(el, d); });
            p.then(function () { schedulePlace(); }).catch(function () {
                errorState(el, 'Could not load this list.', function () { loadTab(tab); });
            });
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

    function selectTab(tab) {
        state.tab = tab;
        panel.querySelectorAll('.nc-tab').forEach(function (t) {
            var on = t.dataset.tab === tab;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (els.alerts) { els.alerts.hidden = tab !== 'alerts'; }
        if (els.ann) { els.ann.hidden = tab !== 'announcements'; }
        if (els.tasks) { els.tasks.hidden = tab !== 'tasks'; }
        syncMarkAll();
        if (!state.loadedTabs[tab]) { loadTab(tab); }
    }

    function openPanel(btn) {
        state.open = true;
        state.opener = btn;
        panel.hidden = false;
        panel.classList.add('is-open');
        panel.setAttribute('aria-modal', isMobile() ? 'true' : 'false');
        if (scrim) { scrim.hidden = !isMobile(); }
        bells.forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
        placePanel();
        state.loadedTabs = {};
        selectTab(state.tab);
        refreshSummary();
        panel.focus();
    }
    function closePanel() {
        state.open = false;
        panel.hidden = true;
        panel.classList.remove('is-open');
        if (scrim) { scrim.hidden = true; }
        bells.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        if (state.opener) { state.opener.focus(); }
        state.opener = null;
    }

    /* ── summary → badges + tab counts ────────────────────────────── */
    function applySummary(s) {
        state.summary = s || {};
        var total = (s.alerts || 0) + (s.announcements || 0) + (s.messages || 0) + (s.tasks || 0);
        document.querySelectorAll('.nc-root .nc-badge').forEach(function (b) {
            b.textContent = total > 99 ? '99+' : String(total);
            b.hidden = !(total > 0);
            if (total > state.lastTotal && state.lastTotal >= 0) {
                b.classList.remove('nc-pulse'); void b.offsetWidth; b.classList.add('nc-pulse');
            }
        });
        state.lastTotal = total;
        setCount(els.cAlerts, s.alerts || 0);
        setCount(els.cAnn, s.announcements || 0);
        setCount(els.cTasks, s.tasks || 0);
        setCount(els.cMsg, s.messages || 0);
        if (els.msgLink && s.can_message === false) { els.msgLink.hidden = true; }
        syncMarkAll();
    }
    function refreshSummary() {
        return get('summary').then(function (d) {
            if (d && d.status === 'success') { applySummary(d.summary); }
        });
    }

    /* ── list interactions: optimistic reads, busy-guarded writes ─── */
    function handleList(el, kind) {
        el.addEventListener('click', function (e) {
            var doBtn = e.target.closest('[data-do]');
            if (doBtn && kind === 'tasks') {
                e.stopPropagation();
                var taskItem = doBtn.closest('[data-task]');
                doBtn.classList.add('is-busy');
                post('task_update', { task_id: taskItem.dataset.task, task_status: doBtn.dataset.do })
                    .then(function (d) {
                        if (d && d.status === 'success') { loadTab('tasks'); refreshSummary(); }
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
                        if (d && d.status === 'success') { loadTab('tasks'); refreshSummary(); }
                        else { if (btn) { btn.classList.remove('is-busy'); } toast((d && d.message) || 'Could not complete the task.', 'err'); }
                    })
                    .catch(function () { if (btn) { btn.classList.remove('is-busy'); } toast('Network error — not updated.', 'err'); });
            }
        });
        el.addEventListener('keydown', function (e) {          // keyboard parity
            if (e.key === 'Enter' || e.key === ' ') {
                var item = e.target.closest('.nc-item');
                if (item) { e.preventDefault(); item.click(); }
            }
        });
    }

    panel.querySelectorAll('.nc-tab').forEach(function (t) {
        t.addEventListener('click', function () { selectTab(t.dataset.tab); });
    });
    var xBtn = panel.querySelector('.nc-x');
    if (xBtn) { xBtn.addEventListener('click', closePanel); }
    if (scrim) { scrim.addEventListener('click', closePanel); }
    document.addEventListener('click', function (e) {
        if (!state.open) { return; }
        var inBell = e.target.closest && e.target.closest('.nc-root');
        if (!panel.contains(e.target) && !inBell) { closePanel(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && state.open) { closePanel(); }
    });
    if (els.markAll) {
        els.markAll.addEventListener('click', function () {
            var scope = state.tab === 'announcements' ? 'announcements' : 'alerts';
            els.markAll.classList.add('is-busy');
            post('mark_all_read', { scope: scope })
                .then(function (d) {
                    els.markAll.classList.remove('is-busy');
                    if (d && d.status === 'success') { loadTab(state.tab); refreshSummary(); }
                    else { toast((d && d.message) || 'Could not mark all as read.', 'err'); }
                })
                .catch(function () { els.markAll.classList.remove('is-busy'); toast('Network error — try again.', 'err'); });
        });
    }
    handleList(els.alerts, 'alerts');
    handleList(els.ann, 'announcements');
    handleList(els.tasks, 'tasks');

    /* ── bind every bell on the page (multi-instance, ONE panel) ──── */
    function bindBells() {
        document.querySelectorAll('.nc-root').forEach(function (root) {
            var btn = root.querySelector('.nc-bell');
            if (!btn || btn.dataset.ncBound === '1') { return; }
            btn.dataset.ncBound = '1';
            bells.push(btn);
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (state.open && state.opener === btn) { closePanel(); } else { openPanel(btn); }
            });
        });
    }

    function boot() {
        bindBells();
        pollStart('summary', refreshSummary, POLL_MS);
        refreshSummary();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
