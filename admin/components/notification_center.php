<?php
/**
 * ============================================================
 * WBWS Notification Center — ONE shared component for every
 * dashboard (P72). Replaces the legacy notification bell.
 * ============================================================
 * Usage:
 *   <?php include __DIR__ . '/components/notification_center.php'; ?>
 *   …in your header (as often as needed — mobile + desktop spots):
 *   <?= renderNotificationCenter() ?>
 *
 * What it does (see docs/NOTIFICATION_SYSTEM_OVERHAUL.md):
 *   • Bell + unread badge fed by ONE summary poll shared by every
 *     instance on the page (30s while visible; paused when hidden;
 *     instant on focus).
 *   • Panel with three tabs: Alerts / Announcements / Tasks.
 *   • Per-user read state (notification_reads) — your badge is
 *     yours; marking read never clears anyone else's.
 *   • CSRF token embedded by the component itself (fixes the old
 *     bell's silently-failing mark-read calls).
 *   • Responsive: floating panel ≥769px, iOS-style bottom sheet on
 *     phones (≤768px). aria + keyboard support. No external assets.
 *   • Multi-instance safe: style/script emitted once; each .nc-root
 *     is an independent bell sharing one poll.
 * Legacy: renderNotificationBell() (notification_bell.php) delegates
 * here, so old includes keep working.
 */

if (!function_exists('renderNotificationCenter')) {

/** Emit the shared <style>/<script> exactly once per page. */
function renderNotificationCenterAssets(): string
{
    static $emitted = false;
    if ($emitted) { return ''; }
    $emitted = true;

    // Self-contained CSRF — the component must never depend on the
    // host page having defined a token.
    $ncCsrf = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
    ob_start();
    ?>
    <style>
    /* ══════════════════════════════════════════════════════════
       All styles scoped under .nc-root — zero host-page impact.
       The bell uses currentColor so it adapts to ANY header theme.
       ══════════════════════════════════════════════════════════ */
    .nc-root { position: relative; display: inline-flex; flex: none; }
    .nc-bell {
        position: relative; display: inline-flex; align-items: center; justify-content: center;
        width: 40px; height: 40px; border: none; border-radius: 12px;
        background: color-mix(in srgb, currentColor 12%, transparent);
        color: inherit; font-size: 17px; cursor: pointer;
        transition: background .18s ease, transform .18s ease;
    }
    .nc-bell:hover { background: color-mix(in srgb, currentColor 22%, transparent); }
    .nc-bell:active { transform: scale(.94); }
    .nc-bell:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
    @supports not (background: color-mix(in srgb, red 10%, transparent)) {
        .nc-bell { background: rgba(127,127,127,.18); }
        .nc-bell:hover { background: rgba(127,127,127,.3); }
    }
    .nc-badge {
        position: absolute; top: -5px; right: -5px; min-width: 19px; height: 19px;
        padding: 0 5px; border-radius: 10px; background: #ef4444; color: #fff;
        font-size: 10.5px; font-weight: 700; line-height: 19px; text-align: center;
        box-shadow: 0 0 0 2px rgba(255,255,255,.85);
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    }
    .nc-badge.nc-pulse { animation: nc-pop .35s cubic-bezier(.2,1.4,.4,1); }
    @keyframes nc-pop { from { transform: scale(.4); } to { transform: scale(1); } }

    .nc-panel {
        position: absolute; top: calc(100% + 10px); right: 0; z-index: 1200;
        width: 396px; max-width: calc(100vw - 24px);
        background: #fff; border: 1px solid #e5e9f0; border-radius: 16px;
        box-shadow: 0 12px 48px rgba(15,23,42,.18), 0 2px 8px rgba(15,23,42,.06);
        overflow: hidden;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        color: #0f172a; text-align: left;
    }
    .nc-panel.is-open { animation: nc-in .18s ease-out; }
    @keyframes nc-in { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) {
        .nc-panel.is-open, .nc-badge.nc-pulse { animation: none; }
    }

    .nc-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 13px 16px; border-bottom: 1px solid #eef1f6; background: #f8fafc;
    }
    .nc-title { font-size: 13.5px; font-weight: 700; color: #0f172a; display: inline-flex; gap: 8px; align-items: center; }
    .nc-title i { color: #059669; font-size: 13px; }
    .nc-head-actions { display: inline-flex; gap: 6px; align-items: center; }
    .nc-link {
        background: none; border: none; cursor: pointer; font-size: 12px;
        color: #059669; font-weight: 600; padding: 4px 6px; border-radius: 6px;
    }
    .nc-link:hover { background: #d1fae5; }
    .nc-x {
        background: none; border: none; cursor: pointer; color: #64748b;
        font-size: 19px; line-height: 1; padding: 2px 7px; border-radius: 6px;
    }
    .nc-x:hover { background: #e2e8f0; color: #0f172a; }

    .nc-tabs { display: flex; border-bottom: 1px solid #eef1f6; background: #fff; }
    .nc-tab {
        flex: 1; padding: 11px 6px; background: none; border: none; cursor: pointer;
        font-size: 12.5px; font-weight: 600; color: #64748b; font-family: inherit;
        border-bottom: 2px solid transparent; transition: color .15s, border-color .15s;
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    }
    .nc-tab:hover { color: #0f172a; background: #f8fafc; }
    .nc-tab.is-active { color: #047857; border-bottom-color: #059669; }
    .nc-count {
        min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px;
        background: #e2e8f0; color: #334155; font-size: 10.5px; font-weight: 700;
        line-height: 18px; text-align: center;
    }
    .nc-tab.is-active .nc-count { background: #a7f3d0; color: #065f46; }
    .nc-count[hidden] { display: none; }
    .nc-foot .nc-count { background: #fecdd3; color: #9f1239; }

    .nc-body { max-height: 420px; overflow-y: auto; overscroll-behavior: contain; }
    .nc-list { padding: 6px; }
    .nc-list[hidden] { display: none; }

    .nc-item {
        display: flex; gap: 11px; padding: 11px 10px; border-radius: 12px;
        cursor: pointer; transition: background .15s; position: relative;
        border: 1px solid transparent;
    }
    .nc-item:hover { background: #f1f5f9; }
    .nc-item.nc-unread { background: #f0fdf4; border-color: #d1fae5; }
    .nc-item.nc-unread:hover { background: #dcfce7; }
    .nc-item.nc-urgent .nc-ico { background: #fee2e2; color: #dc2626; }
    .nc-item.nc-high .nc-ico { background: #fef3c7; color: #d97706; }
    .nc-ico {
        flex: none; width: 36px; height: 36px; border-radius: 11px;
        background: #dbeafe; color: #2563eb; display: flex; align-items: center;
        justify-content: center; font-size: 15px;
    }
    .nc-main { flex: 1; min-width: 0; }
    .nc-row1 { display: flex; align-items: baseline; gap: 8px; }
    .nc-t {
        font-size: 13px; color: #0f172a; flex: 1; min-width: 0;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .nc-unread .nc-t { font-weight: 700; }
    .nc-time { flex: none; font-size: 10.5px; color: #94a3b8; font-weight: 500; }
    .nc-msg {
        font-size: 12px; color: #475569; margin-top: 3px;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
        overflow: hidden; line-height: 1.45;
    }
    .nc-sub { font-size: 11px; color: #64748b; margin-top: 4px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .nc-chip {
        display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px;
        border-radius: 999px; font-size: 10.5px; font-weight: 700;
        background: #f1f5f9; color: #475569;
    }
    .nc-chip.nc-c-urgent { background: #fee2e2; color: #b91c1c; }
    .nc-chip.nc-c-high { background: #fef3c7; color: #b45309; }
    .nc-chip.nc-c-normal { background: #dbeafe; color: #1d4ed8; }
    .nc-dot { width: 8px; height: 8px; border-radius: 50%; background: #059669; flex: none; }

    .nc-actions { display: flex; gap: 8px; margin-top: 8px; }
    .nc-btn {
        border: none; cursor: pointer; font-size: 11px; font-weight: 700; font-family: inherit;
        padding: 5px 10px; border-radius: 8px; display: inline-flex; gap: 5px; align-items: center;
    }
    .nc-btn-done { background: #d1fae5; color: #065f46; }
    .nc-btn-done:hover { background: #a7f3d0; }
    .nc-btn-prog { background: #e2e8f0; color: #475569; }
    .nc-btn-prog:hover { background: #cbd5e1; }

    .nc-empty, .nc-loading {
        text-align: center; padding: 34px 16px; color: #94a3b8; font-size: 12.5px;
    }
    .nc-empty i { display: block; font-size: 26px; margin-bottom: 9px; color: #cbd5e1; }

    .nc-skeleton { padding: 10px; }
    .nc-skeleton span {
        display: block; height: 13px; border-radius: 6px; margin: 10px 8%;
        background: linear-gradient(90deg, #eef1f6 25%, #f8fafc 50%, #eef1f6 75%);
        background-size: 200% 100%; animation: nc-shimmer 1.2s infinite;
    }
    .nc-skeleton span:nth-child(2) { width: 72%; }
    .nc-skeleton span:nth-child(3) { width: 48%; }
    @keyframes nc-shimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }

    .nc-foot {
        display: flex; border-top: 1px solid #eef1f6; background: #f8fafc;
    }
    .nc-foot-link {
        flex: 1; text-align: center; padding: 11px 6px; font-size: 12px; font-weight: 600;
        color: #475569; text-decoration: none; display: inline-flex; gap: 7px;
        align-items: center; justify-content: center;
    }
    .nc-foot-link + .nc-foot-link { border-left: 1px solid #eef1f6; }
    .nc-foot-link:hover { background: #eef2f7; color: #0f172a; }
    .nc-foot-link[hidden] { display: none; }

    /* ── Phone: iOS bottom sheet (P70 pattern) ───────────────── */
    @media (max-width: 768px) {
        .nc-panel {
            position: fixed; inset: auto 0 0 0; width: 100%; max-width: none;
            top: auto; border-radius: 20px 20px 0 0;
            max-height: 82vh; display: flex; flex-direction: column;
            box-shadow: 0 -12px 48px rgba(15,23,42,.25);
        }
        .nc-panel::before {
            content: ""; position: absolute; top: 7px; left: 50%; transform: translateX(-50%);
            width: 42px; height: 5px; border-radius: 3px; background: #cbd5e1;
        }
        .nc-head { padding-top: 20px; }
        .nc-body { max-height: none; flex: 1; }
        .nc-root { position: static; }
    }
    </style>

    <script>
    (function () {
        'use strict';
        var API = '/admin/api_notifications.php';
        var CSRF = <?= json_encode($ncCsrf) ?>;
        var POLL_MS = 30000;

        // ── shared helpers ───────────────────────────────────────
        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
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
        function get(action, qs) {
            return fetch(API + '?action=' + encodeURIComponent(action) + (qs || ''),
                { credentials: 'same-origin' }).then(function (r) { return r.json(); });
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

        // ── one shared summary poll for ALL instances ───────────
        var instances = [];
        function refreshSummary() {
            get('summary').then(function (d) {
                if (!d || d.status !== 'success') { return; }
                instances.forEach(function (inst) { inst.applySummary(d.summary); });
            }).catch(function () {});
        }
        var pollTimer = null;
        function startPoll() { if (!pollTimer) { pollTimer = setInterval(refreshSummary, POLL_MS); } }
        function stopPoll() { clearInterval(pollTimer); pollTimer = null; }
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { stopPoll(); } else { refreshSummary(); startPoll(); }
        });
        window.addEventListener('focus', refreshSummary);

        // ── rendering ────────────────────────────────────────────
        function loading(el) {
            el.innerHTML = '<div class="nc-skeleton"><span></span><span></span><span></span></div>';
        }
        function empty(el, icon, text) {
            el.innerHTML = '<div class="nc-empty"><i class="fa-solid ' + icon + '"></i>' + esc(text) + '</div>';
        }

        function renderAlerts(el, d) {
            var rows = (d && d.rows) || [];
            if (!rows.length) { empty(el, 'fa-bell-slash', 'You are all caught up'); return; }
            el.innerHTML = rows.map(function (n) {
                var prio = n.priority || 'normal';
                return '<div class="nc-item ' + (n.is_unread ? 'nc-unread ' : '') +
                    (prio === 'urgent' ? 'nc-urgent' : prio === 'high' ? 'nc-high' : '') +
                    '" data-id="' + esc(n.id) + '">' +
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
            if (!rows.length) { empty(el, 'fa-bullhorn', 'No announcements yet'); return; }
            el.innerHTML = rows.map(function (a) {
                return '<div class="nc-item ' + (a.is_unread ? 'nc-unread ' : '') +
                    (a.priority === 'urgent' ? 'nc-urgent' : a.priority === 'high' ? 'nc-high' : '') +
                    '" data-ann="' + esc(a.id) + '">' +
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
            if (!rows.length) { empty(el, 'fa-circle-check', 'No pending tasks'); return; }
            el.innerHTML = rows.map(function (t) {
                var prio = t.priority || 'normal';
                return '<div class="nc-item nc-' + (prio === 'urgent' ? 'urgent' : prio === 'high' ? 'high' : '') + '" data-task="' + esc(t.id) + '">' +
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

        // ── instance factory (one per .nc-root on the page) ─────
        function initInstance(root) {
            if (root.dataset.ncBound === '1') { return; }
            root.dataset.ncBound = '1';

            var btn = root.querySelector('.nc-bell');
            var panel = root.querySelector('.nc-panel');
            var badge = root.querySelector('.nc-badge');
            var els = {
                alerts: root.querySelector('.nc-list[data-list="alerts"]'),
                ann: root.querySelector('.nc-list[data-list="announcements"]'),
                tasks: root.querySelector('.nc-list[data-list="tasks"]'),
                cAlerts: root.querySelector('.nc-count[data-count="alerts"]'),
                cAnn: root.querySelector('.nc-count[data-count="announcements"]'),
                cTasks: root.querySelector('.nc-count[data-count="tasks"]'),
                cMsg: root.querySelector('.nc-count[data-count="messages"]'),
                msgLink: root.querySelector('.nc-msg-link'),
                markAll: root.querySelector('.nc-mark-all')
            };
            var state = { open: false, tab: 'alerts', summary: null, loadedTabs: {}, lastTotal: -1 };

            function applySummary(s) {
                state.summary = s || {};
                var total = (s.alerts || 0) + (s.announcements || 0) + (s.messages || 0) + (s.tasks || 0);
                if (badge) {
                    badge.textContent = total > 99 ? '99+' : String(total);
                    badge.style.display = total > 0 ? '' : 'none';
                    if (total > state.lastTotal && state.lastTotal >= 0) {
                        badge.classList.remove('nc-pulse'); void badge.offsetWidth; badge.classList.add('nc-pulse');
                    }
                    state.lastTotal = total;
                }
                setCount(els.cAlerts, s.alerts || 0);
                setCount(els.cAnn, s.announcements || 0);
                setCount(els.cTasks, s.tasks || 0);
                setCount(els.cMsg, s.messages || 0);
                if (els.msgLink && s.can_message === false) { els.msgLink.hidden = true; }
                syncMarkAll();
            }

            function syncMarkAll() {
                if (!els.markAll) { return; }
                var n = state.tab === 'alerts' ? (state.summary.alerts || 0)
                      : state.tab === 'announcements' ? (state.summary.announcements || 0) : 0;
                els.markAll.hidden = !(n > 0);
            }

            function loadTab(tab) {
                var el = tab === 'alerts' ? els.alerts : tab === 'announcements' ? els.ann : els.tasks;
                loading(el);
                var p;
                if (tab === 'alerts') { p = get('feed', '&limit=25').then(function (d) { renderAlerts(el, d); }); }
                else if (tab === 'announcements') { p = get('announcements', '&limit=25').then(function (d) { renderAnn(el, d); }); }
                else { p = get('tasks', '&limit=25').then(function (d) { renderTasks(el, d); }); }
                p.catch(function () { empty(el, 'fa-triangle-exclamation', 'Could not load. Tap to retry.'); });
                state.loadedTabs[tab] = true;
            }

            function selectTab(tab) {
                state.tab = tab;
                root.querySelectorAll('.nc-tab').forEach(function (t) {
                    var on = t.dataset.tab === tab;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                els.alerts.hidden = tab !== 'alerts';
                els.ann.hidden = tab !== 'announcements';
                els.tasks.hidden = tab !== 'tasks';
                syncMarkAll();
                if (!state.loadedTabs[tab]) { loadTab(tab); }
            }

            function openPanel() {
                // close any other instance's open panel first
                instances.forEach(function (o) { if (o !== api) { o.close(); } });
                state.open = true;
                panel.hidden = false;
                panel.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
                state.loadedTabs = {};
                selectTab(state.tab);
                refreshSummary();
            }
            function close() {
                state.open = false;
                panel.hidden = true;
                panel.classList.remove('is-open');
                btn.setAttribute('aria-expanded', 'false');
            }

            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (state.open) { close(); } else { openPanel(); }
            });
            root.querySelector('.nc-x').addEventListener('click', close);
            document.addEventListener('click', function (e) {
                if (state.open && !root.contains(e.target)) { close(); }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && state.open) { close(); }
            });
            root.querySelectorAll('.nc-tab').forEach(function (t) {
                t.addEventListener('click', function () { selectTab(t.dataset.tab); });
            });

            function handleList(el, kind) {
                el.addEventListener('click', function (e) {
                    var doBtn = e.target.closest('[data-do]');
                    if (doBtn && kind === 'tasks') {
                        e.stopPropagation();
                        var taskItem = doBtn.closest('[data-task]');
                        post('task_update', { task_id: taskItem.dataset.task, task_status: doBtn.dataset.do })
                            .then(function () { loadTab('tasks'); refreshSummary(); });
                        return;
                    }
                    var item = e.target.closest('.nc-item');
                    if (!item) { return; }
                    if (kind === 'alerts' && item.dataset.id) {
                        post('mark_read', { id: item.dataset.id }).then(function () {
                            item.classList.remove('nc-unread');
                            var dot = item.querySelector('.nc-dot'); if (dot) { dot.remove(); }
                            refreshSummary();
                        });
                    } else if (kind === 'announcements' && item.dataset.ann) {
                        post('announcement_read', { id: item.dataset.ann }).then(function () {
                            item.classList.remove('nc-unread');
                            var dot = item.querySelector('.nc-dot'); if (dot) { dot.remove(); }
                            refreshSummary();
                        });
                    } else if (kind === 'tasks' && item.dataset.task) {
                        post('task_update', { task_id: item.dataset.task, task_status: 'completed' })
                            .then(function () { loadTab('tasks'); refreshSummary(); });
                    }
                });
            }
            handleList(els.alerts, 'alerts');
            handleList(els.ann, 'announcements');
            handleList(els.tasks, 'tasks');

            if (els.markAll) {
                els.markAll.addEventListener('click', function () {
                    var scope = state.tab === 'announcements' ? 'announcements' : 'alerts';
                    post('mark_all_read', { scope: scope }).then(function () {
                        loadTab(state.tab);
                        refreshSummary();
                    });
                });
            }

            var api = { close: close, applySummary: applySummary };
            instances.push(api);
            return api;
        }

        function bindAll() {
            document.querySelectorAll('.nc-root').forEach(initInstance);
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { bindAll(); startPoll(); refreshSummary(); });
        } else {
            bindAll(); startPoll(); refreshSummary();
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}

function renderNotificationCenter(): string
{
    return renderNotificationCenterAssets()
        . '<div class="nc-root">'
        . '<button type="button" class="nc-bell" aria-haspopup="true" aria-expanded="false" aria-label="Notifications" title="Notifications">'
        . '<i class="fa-solid fa-bell" aria-hidden="true"></i>'
        . '<span class="nc-badge" style="display:none">0</span>'
        . '</button>'
        . '<div class="nc-panel" role="dialog" aria-label="Notification center" hidden>'
        . '<div class="nc-head"><span class="nc-title"><i class="fa-solid fa-bell" aria-hidden="true"></i> Notifications</span>'
        . '<span class="nc-head-actions">'
        . '<button type="button" class="nc-link nc-mark-all" hidden>Mark all read</button>'
        . '<button type="button" class="nc-x" aria-label="Close">&times;</button>'
        . '</span></div>'
        . '<div class="nc-tabs" role="tablist">'
        . '<button type="button" class="nc-tab is-active" data-tab="alerts" role="tab" aria-selected="true">Alerts <span class="nc-count" data-count="alerts" hidden>0</span></button>'
        . '<button type="button" class="nc-tab" data-tab="announcements" role="tab" aria-selected="false">Announcements <span class="nc-count" data-count="announcements" hidden>0</span></button>'
        . '<button type="button" class="nc-tab" data-tab="tasks" role="tab" aria-selected="false">Tasks <span class="nc-count" data-count="tasks" hidden>0</span></button>'
        . '</div>'
        . '<div class="nc-body">'
        . '<div class="nc-list" data-list="alerts" role="tabpanel"><div class="nc-skeleton"><span></span><span></span><span></span></div></div>'
        . '<div class="nc-list" data-list="announcements" role="tabpanel" hidden><div class="nc-skeleton"><span></span><span></span><span></span></div></div>'
        . '<div class="nc-list" data-list="tasks" role="tabpanel" hidden><div class="nc-skeleton"><span></span><span></span><span></span></div></div>'
        . '</div>'
        . '<div class="nc-foot">'
        . '<a href="/admin/notifications.php" class="nc-foot-link"><i class="fa-solid fa-inbox" aria-hidden="true"></i> See all</a>'
        . '<a href="/admin/messages.php" class="nc-foot-link nc-msg-link"><i class="fa-solid fa-comments" aria-hidden="true"></i> Messages <span class="nc-count" data-count="messages" hidden>0</span></a>'
        . '</div>'
        . '</div>'
        . '</div>';
}

} // end function_exists guard
