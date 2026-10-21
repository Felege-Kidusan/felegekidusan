/**
 * Department status widget (Attendance alerting — Phase 1).
 *
 * Renders the Education / Mezmur status badges, a "Change" action (HR and
 * Information only — the server enforces it) and the period history for a
 * member. Mount it on any container that holds
 *   <div data-dept-status="MEMBER_ID"></div>
 * e.g. after injecting info_manage_member.php into a dashboard panel:
 *   DeptStatus.mount(panelElement)
 *
 * Dates are rendered with the global Ethiopian-calendar helpers
 * (fmtDateTime / fmtDate from wbws-calendar.js) when present.
 */
(function (global) {
    'use strict';

    var API = '/admin/api_department_status.php';
    var WARN_API = '/admin/api_attendance_warnings.php';
    var LABELS = { edu: 'Education', mezmur: 'Mezmur' };
    var stylesInjected = false;

    function csrf() {
        if (typeof global.CSRF_TOKEN === 'string' && global.CSRF_TOKEN) return global.CSRF_TOKEN;
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fdt(v) {
        if (!v) return '—';
        if (typeof global.fmtDateTime === 'function') return global.fmtDateTime(v);
        if (typeof global.fmtDate === 'function') return global.fmtDate(v);
        return String(v);
    }
    function toast(msg, ok) {
        if (typeof global.attToast === 'function') return global.attToast(msg, ok);
        if (typeof global.showToast === 'function') return global.showToast(msg, ok ? 'success' : 'error');
        if (typeof global.toast === 'function') return global.toast(msg, ok ? 's' : 'e');
        if (!ok) alert(msg);
    }
    function injectStyles() {
        if (stylesInjected) return;
        stylesInjected = true;
        var css = '' +
            '.ds-wrap{font-size:13px;color:#1e293b}' +
            '.ds-row{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px}' +
            '.ds-dept{flex:1 1 220px;border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;background:#fff;display:flex;align-items:center;justify-content:space-between;gap:8px}' +
            '.ds-dept-name{font-weight:700;color:#334155}' +
            '.ds-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.02em}' +
            '.ds-badge.active{background:#d1fae5;color:#065f46}.ds-badge.inactive{background:#fee2e2;color:#991b1b}' +
            '.ds-since{font-size:11px;color:#64748b;margin-top:2px}' +
            '.ds-btn{border:1px solid #cbd5e1;background:#f8fafc;border-radius:8px;padding:5px 10px;font-size:12px;cursor:pointer;color:#334155}' +
            '.ds-btn:hover{background:#f1f5f9}.ds-btn.danger{border-color:#fca5a5;color:#b91c1c}.ds-btn.ok{border-color:#86efac;color:#166534}' +
            '.ds-note{font-size:11px;color:#64748b;margin:4px 0 8px}' +
            '.ds-hist{width:100%;border-collapse:collapse;font-size:12px}' +
            '.ds-hist th{text-align:left;color:#64748b;font-weight:600;padding:6px 8px;border-bottom:1px solid #e2e8f0;font-size:11px}' +
            '.ds-hist td{padding:6px 8px;border-bottom:1px solid #f1f5f9;vertical-align:top}' +
            '.ds-modal-bg{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px}' +
            '.ds-modal{background:#fff;border-radius:14px;max-width:420px;width:100%;padding:18px;box-shadow:0 20px 50px rgba(0,0,0,.25)}' +
            '.ds-modal h4{margin:0 0 6px;font-size:15px;color:#0f172a}' +
            '.ds-modal textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:8px;font-size:13px;min-height:80px;margin-top:8px}' +
            '.ds-modal .ds-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}' +
            '.ds-unavailable{font-size:12px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:8px 10px}';
        var el = document.createElement('style');
        el.textContent = css;
        document.head.appendChild(el);
    }

    function fetchJson(url, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return { status: 'error', message: 'Bad response' }; })
                .then(function (d) { d.__http = r.status; return d; });
        });
    }

    function render(box, memberId, data, history) {
        var html = '<div class="ds-wrap">';
        if (!data.available) {
            html += '<div class="ds-unavailable"><i class="fas fa-info-circle"></i> Department status is not enabled yet on this installation (feature flag / sql 071). Every member is treated as active in both departments.</div></div>';
            box.innerHTML = html;
            return;
        }
        html += '<div class="ds-row">';
        ['edu', 'mezmur'].forEach(function (d) {
            var st = (data.statuses && data.statuses[d]) || { status: 'active' };
            var isActive = st.status === 'active';
            html += '<div class="ds-dept" data-dept="' + d + '">' +
                '<div><div class="ds-dept-name">' + esc(LABELS[d]) + '</div>' +
                '<span class="ds-badge ' + (isActive ? 'active' : 'inactive') + '">' + (isActive ? 'Active' : 'Inactive') + '</span>' +
                (st.since ? '<div class="ds-since">since ' + esc(fdt(st.since)) + (st.reason ? ' · ' + esc(st.reason) : '') + '</div>' : '') +
                '</div>';
            if (data.can_change) {
                html += '<button type="button" class="ds-btn ' + (isActive ? 'danger' : 'ok') + '" data-ds-change="' + d + '" data-ds-to="' + (isActive ? 'inactive' : 'active') + '">' +
                    (isActive ? '<i class="fas fa-user-slash"></i> Deactivate' : '<i class="fas fa-user-check"></i> Reactivate') + '</button>';
            }
            html += '</div>';
        });
        html += '</div>';
        html += '<div class="ds-note">Department status is separate from the global member status. Inactive members are left off that department\'s attendance sheets and grade lists from the day of the change; their history is kept. Reactivation starts a fresh period.</div>';
        if (history && history.length) {
            html += '<table class="ds-hist"><thead><tr><th>Department</th><th>Status</th><th>From</th><th>To</th><th>By</th><th>Reason</th></tr></thead><tbody>';
            history.forEach(function (h) {
                html += '<tr><td>' + esc(LABELS[h.department] || h.department) + '</td>' +
                    '<td><span class="ds-badge ' + (h.status === 'active' ? 'active' : 'inactive') + '">' + esc(h.status) + '</span></td>' +
                    '<td>' + esc(fdt(h.started_at)) + '</td>' +
                    '<td>' + (h.ended_at ? esc(fdt(h.ended_at)) : '<em>current</em>') + '</td>' +
                    '<td>' + esc(h.changed_by_name || (h.source === 'auto' ? 'system' : h.source || '')) + '</td>' +
                    '<td>' + esc(h.reason || '') + '</td></tr>';
            });
            html += '</tbody></table>';
        } else {
            html += '<div class="ds-note">No department status changes recorded yet.</div>';
        }
        html += '<div class="ds-aw" data-ds-aw></div>';
        html += '</div>';
        box.innerHTML = html;
        box.querySelectorAll('[data-ds-change]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(box, memberId, btn.getAttribute('data-ds-change'), btn.getAttribute('data-ds-to'));
            });
        });
        loadWarnings(box.querySelector('[data-ds-aw]'), memberId);
    }

    // Attendance alerting Phase 2: shared risk + warnings per department
    // (api_attendance_warnings.php scopes departments by role; 403 = not for this role).
    function loadWarnings(el, memberId) {
        if (!el) return;
        fetchJson(WARN_API + '?action=member_summary&member_id=' + encodeURIComponent(memberId)).then(function (d) {
            if (d.status !== 'success' || !d.summary || !d.summary.departments) { el.innerHTML = ''; return; }
            var depts = d.summary.departments;
            var html = '<div class="ds-dept-name" style="margin-top:10px">Attendance risk &amp; warnings <span class="ds-since">(Ethiopian calendar)</span></div>';
            if (!d.available) {
                html += '<div class="ds-note">Attendance warnings are not enabled yet (feature flag / sql 072).</div>';
                el.innerHTML = html;
                return;
            }
            html += '<div class="ds-row">';
            Object.keys(depts).forEach(function (k) {
                var e = depts[k], r = e.risk || {}, st = r.streak || {}, m = r.month || {}, y = r.year || {};
                var open = (e.warnings || []).filter(function (w) { return w.status === 'open'; });
                html += '<div class="ds-dept"><div><div class="ds-dept-name">' + esc(e.label) + '</div>' +
                    '<div class="ds-since">Streak: <b>' + esc(st.length || 0) + '</b> day(s)' + (st.start_ec ? ' (' + esc(st.start_ec) + ' – ' + esc(st.last_ec) + ')' : '') + '</div>' +
                    '<div class="ds-since">' + esc(m.label || 'This month') + ': <b>' + (m.rate == null ? 'No data' : esc(m.rate) + '%') + '</b> · ' + esc(m.sessions || 0) + ' sessions</div>' +
                    '<div class="ds-since">Year ' + esc(y.ec_year || '') + ': <b>' + (y.rate == null ? 'No data' : esc(y.rate) + '%') + '</b> · ' + esc(y.sessions || 0) + ' sessions</div>' +
                    '<div class="ds-since">Open warnings: <b>' + open.length + '</b>' + (open.length ? ' · <a href="/admin/attendance_warnings.php?member_id=' + encodeURIComponent(memberId) + '&department=' + encodeURIComponent(k) + '">open list</a>' : '') + '</div>' +
                    // Phase 6: lesson-level history (Education only has lessons)
                    (k === 'edu' ? '<div class="ds-since"><a href="/admin/lesson_attendance.php?member_id=' + encodeURIComponent(memberId) + '">Lesson attendance history</a></div>' : '') +
                    '</div></div>';
            });
            html += '</div>';
            var all = [];
            Object.keys(depts).forEach(function (k) { (depts[k].warnings || []).forEach(function (w) { all.push(w); }); });
            if (all.length) {
                html += '<table class="ds-hist"><thead><tr><th>Department</th><th>Warning</th><th>Stage</th><th>Opened</th><th>Status</th><th>Outcome</th></tr></thead><tbody>';
                all.slice(0, 30).forEach(function (w) {
                    html += '<tr><td>' + esc(w.department_label) + '</td><td>' + esc(w.reason) + (w.source === 'rollout' ? ' <em>(rollout)</em>' : '') + '</td>' +
                        '<td>' + esc(w.kind === 'low_month' ? 'low month' : (w.stage || '')) + '</td>' +
                        '<td>' + esc(w.opened_ec) + '</td>' +
                        '<td><span class="ds-badge ' + (w.status === 'open' ? 'inactive' : 'active') + '">' + esc(w.status) + '</span>' + (w.status === 'open' && !w.below_threshold ? ' <em>no longer meets threshold</em>' : '') + '</td>' +
                        '<td>' + esc(w.outcome_label || '') + (w.closure_note ? ' — ' + esc(w.closure_note) : '') + '</td></tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<div class="ds-note">No attendance warnings for this member.</div>';
            }
            el.innerHTML = html;
        }).catch(function () { el.innerHTML = ''; });
    }

    // Danger confirmation (DC-4, MEDIUM): deactivating in a department removes
    // the member from that department's sheets. Uses the shared dialog
    // (loaded on demand); reactivation keeps the simple dialog below.
    function dcLoad() {
        return new Promise(function (res) {
            if (window.DangerConfirm && window.DangerConfirm.ask) return res(true);
            var sc = document.createElement('script');
            sc.src = '/admin/js/danger_confirm.js?v=20261022dc4';
            sc.onload = function () { res(!!window.DangerConfirm); };
            sc.onerror = function () { res(false); };
            document.head.appendChild(sc);
        });
    }
    function sendSet(box, memberId, dept, to, reason) {
        return fetchJson(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ action: 'set', member_id: memberId, department: dept, status: to, reason: reason, csrf_token: csrf() })
        }).then(function (d) {
            if (d.status === 'success') {
                toast(d.message || 'Saved', true);
                load(box, memberId);
                document.dispatchEvent(new CustomEvent('deptstatus:changed', { detail: { member_id: memberId, department: dept, status: to } }));
            } else {
                toast(d.message || 'Could not change status', false);
            }
        }).catch(function () { toast('Network error', false); });
    }
    function openModal(box, memberId, dept, to, plain) {
        if (to === 'inactive' && !plain) {
            dcLoad().then(function (ok) {
                if (!ok) { toast('The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.', false); return; }
                window.DangerConfirm.ask({
                    tier: 'medium', title: 'Deactivate this member in ' + LABELS[dept] + '?', object: LABELS[dept],
                    summary: 'The member is switched off in this department only. Nothing is deleted.', intent: 'I want to deactivate this member here',
                    consequences: ['From today the member no longer appears on ' + LABELS[dept] + ' attendance sheets or grade lists.', 'Open attendance warnings in this department stop growing.'],
                    keeps: ['All history (attendance, marks, warnings) is kept.', 'Other departments are not affected.', 'You can reactivate the member later.'],
                    reason: { label: 'Reason (at least 3 letters)', required: true, minLength: 3, maxLength: 500, placeholder: 'e.g. Travelled abroad for 3 months' },
                    phrase: 'DEACTIVATE MEMBER', confirmLabel: 'Deactivate'
                }).then(function (r) { if (r) sendSet(box, memberId, dept, to, r.reason); });
            });
            return;
        }
        var bg = document.createElement('div');
        bg.className = 'ds-modal-bg';
        var verb = to === 'inactive' ? 'Deactivate' : 'Reactivate';
        bg.innerHTML = '<div class="ds-modal" role="dialog" aria-modal="true">' +
            '<h4>' + verb + ' in ' + esc(LABELS[dept]) + '</h4>' +
            '<div class="ds-note">' + (to === 'inactive'
                ? 'The member will no longer appear on ' + esc(LABELS[dept]) + ' attendance sheets or grade lists from today. Nothing is deleted.'
                : 'The member returns to ' + esc(LABELS[dept]) + ' sheets from today. A new period starts, so earlier absences do not carry over.') + '</div>' +
            '<label style="font-size:12px;font-weight:600;color:#334155">Reason (required)</label>' +
            '<textarea maxlength="500" placeholder="e.g. Travelled abroad for 3 months"></textarea>' +
            '<div class="ds-actions"><button type="button" class="ds-btn" data-ds-cancel>Cancel</button>' +
            '<button type="button" class="ds-btn ' + (to === 'inactive' ? 'danger' : 'ok') + '" data-ds-confirm>' + verb + '</button></div></div>';
        document.body.appendChild(bg);
        var ta = bg.querySelector('textarea');
        setTimeout(function () { ta.focus(); }, 50);
        function close() { if (bg.parentNode) bg.parentNode.removeChild(bg); }
        bg.querySelector('[data-ds-cancel]').addEventListener('click', close);
        bg.addEventListener('click', function (e) { if (e.target === bg) close(); });
        bg.querySelector('[data-ds-confirm]').addEventListener('click', function () {
            var reason = ta.value.trim();
            if (reason.length < 3) { toast('Please give a reason (at least 3 characters).', false); ta.focus(); return; }
            var btn = this; btn.disabled = true;
            fetchJson(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify({ action: 'set', member_id: memberId, department: dept, status: to, reason: reason, csrf_token: csrf() })
            }).then(function (d) {
                btn.disabled = false;
                if (d.status === 'success') {
                    toast(d.message || 'Saved', true);
                    close();
                    load(box, memberId);
                    document.dispatchEvent(new CustomEvent('deptstatus:changed', { detail: { member_id: memberId, department: dept, status: to } }));
                } else {
                    toast(d.message || 'Could not change status', false);
                }
            }).catch(function () { btn.disabled = false; toast('Network error', false); });
        });
    }

    function load(box, memberId) {
        box.innerHTML = '<div class="ds-note"><i class="fas fa-spinner fa-spin"></i> Loading department status…</div>';
        Promise.all([
            fetchJson(API + '?action=status&member_id=' + encodeURIComponent(memberId)),
            fetchJson(API + '?action=history&member_id=' + encodeURIComponent(memberId))
        ]).then(function (res) {
            var st = res[0], hi = res[1];
            if (st.status !== 'success') {
                box.innerHTML = '<div class="ds-unavailable">' + esc(st.message || 'Department status unavailable') + '</div>';
                return;
            }
            render(box, memberId, st, hi.status === 'success' ? (hi.history || []) : []);
        }).catch(function () {
            box.innerHTML = '<div class="ds-unavailable">Could not load department status.</div>';
        });
    }

    function mount(root) {
        root = root || document;
        injectStyles();
        var nodes = root.querySelectorAll('[data-dept-status]');
        nodes.forEach(function (box) {
            if (box.getAttribute('data-ds-mounted') === '1') return;
            box.setAttribute('data-ds-mounted', '1');
            var id = parseInt(box.getAttribute('data-dept-status'), 10);
            if (id > 0) load(box, id);
        });
        return nodes.length;
    }

    global.DeptStatus = { mount: mount, reload: load };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { mount(document); });
    } else {
        mount(document);
    }
})(window);
