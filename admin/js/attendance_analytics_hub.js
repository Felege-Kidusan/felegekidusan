/* ════════════════════════════════════════════════════════════════
   AttendanceHub — shared read-only Attendance Analytics Hub
   (Information department + HR department, 2026-10-18)

   Reads the governed admin/api_info_analytics.php surface over the
   two independent attendance sources: Education (classes, recorded by
   teachers) and Mezmur (sections, recorded by Mezmur takers). HR's own
   attendance was retired on 2026-10-07 and is no longer a source.
   The hub compares departments side-by-side; it never merges their
   data and no analysing department ever writes attendance.

   Host page contract (see admin/dashboards/sections/attendance_analytics_hub.php):
     • container  #attendanceHub[data-api="…/api_info_analytics.php"]
     • globals    escapeHtml(), showToast(), CSRF_TOKEN (admins only for refresh)
     • optional   WBWSCalendar.formatDate, XLSX (falls back to CSV)
   Exposed as window.AttendanceHub; window.InfoHub stays as an alias for
   existing bookmarks/inline handlers.
════════════════════════════════════════════════════════════════ */
const AttendanceHub = (function () {
    'use strict';

    const SOURCES = ['edu', 'mezmur'];
    const SRC_COLORS = {
        edu:    ['#2563eb', '#3b82f6'],
        mezmur: ['#7c3aed', '#8b5cf6']
    };
    const SRC_TAB_ID = { edu: 'ihSrcEdu', mezmur: 'ihSrcMezmur' };

    let api = '';
    let initialized = false;
    let source = 'edu';
    let lastKpi = [];
    let lastComparison = [];
    let lastGroups = [];
    let lastTrends = [];
    let windowFrom = '';
    let windowTo = '';

    function $(id) { return document.getElementById(id); }
    function esc(s) { return (typeof escapeHtml === 'function') ? escapeHtml(s || '') : String(s || '').replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
    function toast(msg, kind) { if (typeof showToast === 'function') showToast(msg, kind); else if (kind === 'error') alert(msg); }

    function resolveApi() {
        if (api) return api;
        const host = $('attendanceHub');
        api = (host && host.getAttribute('data-api')) || '/admin/api_info_analytics.php';
        return api;
    }

    function fmtDate(s, fmt) {
        if (!s) return '—';
        if (typeof WBWSCalendar !== 'undefined' && typeof WBWSCalendar.formatDate === 'function') {
            return WBWSCalendar.formatDate(s, fmt || 'long');
        }
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(s);
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function renderGroupBadge(groupKey, src) {
        if (!groupKey || groupKey === '—') {
            return '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">—</span>';
        }
        if (src === 'edu') {
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200 shadow-xs"><i class="fa-solid fa-graduation-cap text-[10px] text-blue-500"></i>' + esc(groupKey) + '</span>';
        }
        const s = String(groupKey).trim();
        if (s.indexOf('ህጻናት') !== -1 || s.indexOf('ህፃናት') !== -1 || s === 'A' || s.toLowerCase() === 'children') {
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200 shadow-xs"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>ህጻናት (A) <span class="text-emerald-600 font-normal text-[10px]">7–13</span></span>';
        }
        if (s.indexOf('ማዕከላዊ') !== -1 || s.indexOf('ማእከላዊ') !== -1 || s === 'B' || s.toLowerCase() === 'intermediate' || s.toLowerCase() === 'middle') {
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-indigo-50 text-indigo-800 border border-indigo-200 shadow-xs"><span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>ማዕከላዊያን (B) <span class="text-indigo-600 font-normal text-[10px]">14–17</span></span>';
        }
        if (s.indexOf('ወጣት') !== -1 || s === 'C' || s.toLowerCase() === 'youth' || s.toLowerCase() === 'parish') {
            return '<span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200 shadow-xs"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>ወጣቶች (C) <span class="text-amber-600 font-normal text-[10px]">18+</span></span>';
        }
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700 border border-slate-200">' + esc(s) + '</span>';
    }

    function apiGet(query) {
        return fetch(resolveApi() + '?' + query, { credentials: 'same-origin' })
            .then(r => {
                if (!r.ok) {
                    return r.json().catch(() => ({ status: 'error', message: 'HTTP ' + r.status + ': Server communication error' }));
                }
                return r.json();
            });
    }

    function windowParams() {
        let q = '';
        windowFrom = $('ihFrom') ? $('ihFrom').value : '';
        windowTo = $('ihTo') ? $('ihTo').value : '';
        if (windowFrom) q += '&from=' + encodeURIComponent(windowFrom);
        if (windowTo) q += '&to=' + encodeURIComponent(windowTo);
        return q;
    }

    function rateBar(rate) {
        if (rate == null) return '<span class="text-slate-400 font-mono">—</span>';
        const num = typeof rate === 'number' ? rate : parseFloat(rate);
        if (isNaN(num)) return '<span class="text-slate-400 font-mono">—</span>';
        const w = Math.max(0, Math.min(100, num));
        const tone = num >= 80 ? 'bg-emerald-500' : (num >= 60 ? 'bg-amber-500' : 'bg-rose-500');
        return '<div class="flex items-center gap-2"><div class="w-20 h-2 bg-slate-100 rounded-full overflow-hidden flex-shrink-0"><div class="h-full ' + tone + '" style="width:' + w + '%"></div></div><span class="text-xs font-semibold text-slate-600">' + num + '%</span></div>';
    }

    // ── KPI band: one card per source ──────────────────────────
    function loadKpi() {
        const band = $('ihKpiBand');
        if (!band) return;
        band.innerHTML = '<div class="animate-pulse h-36 bg-slate-100 rounded-2xl"></div><div class="animate-pulse h-36 bg-slate-100 rounded-2xl"></div>';
        apiGet('action=kpi' + windowParams()).then(d => {
            if (d.status !== 'success') {
                band.innerHTML = '<div class="col-span-full p-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl text-xs"><i class="fa-solid fa-circle-exclamation mr-1.5"></i>' + esc(d.message || 'Could not load KPI overview.') + '</div>';
                return;
            }
            lastKpi = (d.items || []).filter(it => SOURCES.indexOf(it.source) !== -1);
            if (!lastKpi.length) {
                band.innerHTML = '<div class="col-span-full p-6 text-center text-slate-400 text-xs bg-slate-50 rounded-2xl">No attendance data recorded yet.</div>';
                return;
            }
            band.innerHTML = lastKpi.map(it => {
                const c = SRC_COLORS[it.source] || ['#64748b', '#94a3b8'];
                return '<div class="rounded-2xl p-4 text-white shadow-sm flex flex-col justify-between" style="background:linear-gradient(135deg,' + c[0] + ',' + c[1] + ')">' +
                    '<div><div class="flex items-center justify-between"><div class="text-sm font-bold tracking-tight">' + esc(it.label) + '</div>' +
                    '<div class="text-[10px] opacity-80">' + (it.days || 0) + ' days · ' + (it.groups_active || 0) + ' groups</div></div>' +
                    '<div class="mt-3 flex items-end gap-4">' +
                    '<div><div class="text-2xl font-black leading-tight">' + (it.rate == null ? '—' : it.rate + '%') + '</div><div class="text-[10px] opacity-80">attendance rate</div></div>' +
                    '<div><div class="text-lg font-bold leading-tight">' + (it.marked || 0) + '</div><div class="text-[10px] opacity-80">marks</div></div>' +
                    '<div><div class="text-lg font-bold leading-tight">' + (it.absent || 0) + '</div><div class="text-[10px] opacity-80">absent</div></div>' +
                    '</div></div>' +
                    '<div class="mt-3 pt-2 border-t border-white/20 text-[10px] opacity-80 flex items-center justify-between">' +
                    '<span>' + (it.source === 'edu' ? 'Class-based · recorded by teachers' : 'Section-based · recorded by ' + esc(it.label) + ' takers') + '</span>' +
                    '<span class="font-medium">' + (it.packets || 0) + ' packets (' + (it.approved || 0) + ' approved)</span>' +
                    '</div></div>';
            }).join('');
        }).catch(err => {
            console.error('AttendanceHub loadKpi error:', err);
            band.innerHTML = '<div class="col-span-full p-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl text-xs"><i class="fa-solid fa-triangle-exclamation mr-1.5"></i>Unable to load KPI summary. Please check connection.</div>';
        });
    }

    // ── comparison table ───────────────────────────────────────
    function loadComparison() {
        const tb = $('ihComparisonTbody');
        if (!tb) return;
        tb.innerHTML = '<tr><td colspan="9" class="py-4 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading…</td></tr>';
        apiGet('action=comparison' + windowParams()).then(d => {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="9" class="py-4 text-center text-rose-500 text-xs"><i class="fa-solid fa-circle-exclamation mr-1"></i>' + esc(d.message || 'Could not load comparison.') + '</td></tr>';
                return;
            }
            lastComparison = (d.items || []).filter(it => SOURCES.indexOf(it.source) !== -1);
            if (!lastComparison.length) {
                tb.innerHTML = '<tr><td colspan="9" class="py-6 text-center text-slate-400 text-xs">No attendance data in this window yet.</td></tr>';
                return;
            }
            tb.innerHTML = lastComparison.map(it =>
                '<tr class="border-b border-slate-50 hover:bg-slate-50/60 transition">' +
                '<td class="py-2.5 pr-3 font-semibold text-slate-800">' + esc(it.label) + '</td>' +
                '<td class="py-2.5 pr-3 font-medium">' + (it.days || 0) + '</td>' +
                '<td class="py-2.5 pr-3">' + (it.groups_active || 0) + '</td>' +
                '<td class="py-2.5 pr-3 font-medium">' + (it.marked || 0) + '</td>' +
                '<td class="py-2.5 pr-3">' + rateBar(it.rate) + '</td>' +
                '<td class="py-2.5 pr-3 text-rose-600 font-semibold">' + (it.absent || 0) + '</td>' +
                '<td class="py-2.5 pr-3 text-amber-600 font-medium">' + (it.late || 0) + '</td>' +
                '<td class="py-2.5 pr-3">' + (it.packets || 0) + '</td>' +
                '<td class="py-2.5 text-emerald-600 font-medium">' + (it.approved || 0) + '</td>' +
                '</tr>'
            ).join('');
        }).catch(err => {
            console.error('AttendanceHub loadComparison error:', err);
            tb.innerHTML = '<tr><td colspan="9" class="py-4 text-center text-rose-500 text-xs"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Connection error. Please try again.</td></tr>';
        });
    }

    // ── drill-down ─────────────────────────────────────────────
    function paintSourceTabs() {
        SOURCES.forEach(s => {
            const b = $(SRC_TAB_ID[s]);
            if (!b) return;
            const active = s === source;
            const c = SRC_COLORS[s] || ['#2563eb', '#3b82f6'];
            b.style.background = active ? c[0] : '#fff';
            b.style.color = active ? '#fff' : '#475569';
            b.style.borderColor = active ? c[0] : '#e2e8f0';
        });
    }

    function loadDrill() {
        paintSourceTabs();
        const tt = $('ihTrendTbody');
        const gt = $('ihGroupTbody');
        if (tt) tt.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading…</td></tr>';
        if (gt) gt.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading…</td></tr>';

        apiGet('action=trends&source=' + source + windowParams()).then(d => {
            if (!tt) return;
            if (d && d.status === 'error') {
                tt.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-rose-500 text-xs"><i class="fa-solid fa-circle-exclamation mr-1"></i>' + esc(d.message || 'Could not load trends.') + '</td></tr>';
                return;
            }
            lastTrends = (d && d.items) || [];
            if (!lastTrends.length) {
                tt.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-slate-400 text-xs">No recorded days for this department in the window.</td></tr>';
            } else {
                tt.innerHTML = lastTrends.slice(0, 14).map(r =>
                    '<tr class="border-b border-slate-50 hover:bg-slate-50/50">' +
                    '<td class="py-2 pr-3 whitespace-nowrap font-medium text-slate-700">' + fmtDate(r.date) + '</td>' +
                    '<td class="py-2 pr-3">' + (r.marked || 0) + '</td>' +
                    '<td class="py-2 pr-3 text-emerald-600 font-medium">' + (r.attended || 0) + '</td>' +
                    '<td class="py-2 pr-3 text-rose-600 font-medium">' + (r.absent || 0) + '</td>' +
                    '<td class="py-2">' + rateBar(r.rate) + '</td></tr>'
                ).join('');
            }
        }).catch(err => {
            console.error('AttendanceHub trends error:', err);
            if (tt) tt.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-rose-500 text-xs"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Connection error.</td></tr>';
        });

        apiGet('action=groups&source=' + source + '&per_page=100' + windowParams()).then(d => {
            if (!gt) return;
            if (d && d.status === 'error') {
                gt.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-rose-500 text-xs"><i class="fa-solid fa-circle-exclamation mr-1"></i>' + esc(d.message || 'Could not load groups.') + '</td></tr>';
                return;
            }
            lastGroups = (d && d.items) || [];
            if (!lastGroups.length) {
                gt.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-slate-400 text-xs">No ' + (source === 'edu' ? 'classes' : 'sections') + ' recorded in the window.</td></tr>';
            } else {
                gt.innerHTML = lastGroups.map(g =>
                    '<tr class="border-b border-slate-50 hover:bg-slate-50/50">' +
                    '<td class="py-2 pr-3 font-semibold text-slate-800">' + renderGroupBadge(g.group_key, source) + '</td>' +
                    '<td class="py-2 pr-3">' + (g.days || 0) + '</td>' +
                    '<td class="py-2 pr-3 font-medium">' + (g.marked || 0) + '</td>' +
                    '<td class="py-2 pr-3">' + rateBar(g.rate) + '</td>' +
                    '<td class="py-2 text-rose-600 font-semibold">' + (g.absent || 0) + '</td></tr>'
                ).join('');
            }
        }).catch(err => {
            console.error('AttendanceHub groups error:', err);
            if (gt) gt.innerHTML = '<tr><td colspan="5" class="py-4 text-center text-rose-500 text-xs"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Connection error.</td></tr>';
        });
    }

    // ── meta (rollup freshness) ────────────────────────────────
    function loadMeta() {
        apiGet('action=meta').then(d => {
            const el = $('ihGeneratedAt');
            if (el && d.status === 'success') {
                el.textContent = d.generated_at ? fmtDate(d.generated_at) : 'never (ask an admin to refresh)';
            }
        }).catch(() => { /* decorative */ });
    }

    // ── Excel export (comparison + current drill) ──────────────
    function exportAll() {
        if (!lastComparison.length) { toast('Nothing to export yet.', 'error'); return; }
        const cmpHead = ['Department', 'Days', 'Groups', 'Marks', 'Rate %', 'Absent', 'Late', 'Excused', 'Sheets', 'Approved'];
        const cmpRows = lastComparison.map(it => [it.label, it.days, it.groups_active, it.marked, it.rate ?? '', it.absent, it.late, it.excused, it.packets, it.approved]);
        const grpHead = ['Group (' + source + ')', 'Days', 'Marks', 'Rate %', 'Absent', 'Late', 'Excused', 'Sheets'];
        const grpRows = lastGroups.map(g => [g.group_key, g.days, g.marked, g.rate ?? '', g.absent, g.late, g.excused, g.packets]);
        if (window.XLSX) {
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([cmpHead].concat(cmpRows)), 'Comparison');
            XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([grpHead].concat(grpRows)), 'Groups ' + source);
            XLSX.writeFile(wb, 'FKSS_Analytics_' + (windowFrom || 'window') + '_' + (windowTo || 'now') + '.xlsx');
        } else {
            const csv = '\ufeff' + cmpHead.join(',') + '\n' + cmpRows.map(r =>
                r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'analytics-comparison.csv';
            document.body.appendChild(a); a.click(); a.remove();
        }
        toast('✓ Analytics exported.', 'success');
    }

    // ── admin-only rollup refresh ──────────────────────────────
    // Danger confirmation (LOW). Loads admin/js/danger_confirm.js on demand.
    var dcAskLazy = function (o) { return new Promise(function (res) { function go() { window.DangerConfirm.ask(o).then(res); } if (window.DangerConfirm && window.DangerConfirm.ask) return go(); var sc = document.createElement('script'); sc.src = '/admin/js/danger_confirm.js?v=20261022dc'; sc.onload = function () { window.DangerConfirm ? go() : res(false); }; sc.onerror = function () { res(false); }; document.head.appendChild(sc); }); };
    async function refresh() {
        if (!(await dcAskLazy({ tier: 'low', title: 'Rebuild the analytics rollup?', summary: 'The analytics totals are recalculated from the Education and Mezmur attendance records. Attendance itself is not changed. This can take a moment.', confirmLabel: 'Rebuild' }))) return;
        const fd = new FormData();
        fd.append('action', 'refresh');
        fd.append('csrf_token', (typeof CSRF_TOKEN !== 'undefined') ? CSRF_TOKEN : '');
        fetch(resolveApi(), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.status !== 'success') { toast(d.message || 'Refresh failed.', 'error'); return; }
                toast('✓ ' + (d.message || 'Analytics data refreshed.'), 'success');
                reload();
            })
            .catch(() => toast('Network error. Please try again.', 'error'));
    }

    function reload() {
        loadKpi();
        loadComparison();
        loadDrill();
        loadMeta();
    }

    function setSource(s) {
        if (SOURCES.indexOf(s) === -1) return;
        source = s;
        loadDrill();
    }

    function init() {
        if (initialized) return;
        if (!$('attendanceHub')) return;
        initialized = true;
        const now = new Date();
        const past = new Date();
        past.setDate(now.getDate() - 29);
        if ($('ihTo') && !$('ihTo').value) {
            $('ihTo').value = now.toISOString().slice(0, 10);
        }
        if ($('ihFrom') && !$('ihFrom').value) {
            $('ihFrom').value = past.toISOString().slice(0, 10);
        }
        reload();
    }

    return {
        init: init,
        reload: reload,
        setSource: setSource,
        exportAll: exportAll,
        refresh: refresh
    };
})();

window.AttendanceHub = AttendanceHub;
// Backward-compatible alias (Information dashboard inline handlers / bookmarks).
window.InfoHub = AttendanceHub;
