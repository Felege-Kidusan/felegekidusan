/**
 * ════════════════════════════════════════════════════════════
 * Mezmur Department (መዝሙር ክፍል) — front-end controller
 *   • Hymn Library  • Attendance (section-based)
 *   • Analytics     • Attendance Takers
 * ════════════════════════════════════════════════════════════
 * Pure UI layer for frontend/pages/mezmur_dept.php. Every data
 * access goes through /backend/api/mezmur.php (shim →
 * admin/api_mezmur.php) which re-validates session, role, CSRF,
 * rate-limits and every input server-side.
 *
 * Security conventions (same as finance.js):
 *   - All dynamic values HTML-escaped via esc() before innerHTML;
 *     free-form long text uses textContent only.
 *   - All mutations are POST (CSRF auto-appended by SSMS.api).
 *   - All lists are server-side paginated (scale-safe).
 * ════════════════════════════════════════════════════════════
 */
(function () {
    'use strict';

    var PAGE_SIZE = 25;

    // ── shared helpers ─────────────────────────────────────────
    function $(id) { return document.getElementById(id); }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function fmtDate(s) {
        if (!s) return '—';
        var d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(s);
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function showError(el, msg) {
        el.textContent = msg || '';
        el.style.display = msg ? 'block' : 'none';
    }

    function pctLabel(v) { return v == null ? '—' : (Math.round(v * 10) / 10) + '%'; }

    var PROGRAM_LABELS = {
        rehearsal: 'Rehearsal', service: 'Service', feast: 'Feast',
        training: 'Training', other: 'Other'
    };

    // ══════════════════════════════════════════════════════════
    // MODULE 1 — HYMN LIBRARY
    // ══════════════════════════════════════════════════════════
    var lib = { page: 1, totalPages: 1, total: 0, search: '', category: '', status: 'active', loading: false };

    function loadStats() {
        return SSMS.api.get('mezmur.php?action=stats').then(function (d) {
            if (d.status !== 'success') return;
            $('mzStatTotal').textContent = d.total != null ? d.total : '—';
            $('mzStatActive').textContent = d.active != null ? d.active : '—';
            $('mzStatCategories').textContent = d.categories != null ? d.categories : '—';

            var sel = $('mzCategoryFilter'), cur = sel.value;
            sel.innerHTML = '<option value="">All categories</option>' +
                (d.category_list || []).map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
            sel.value = cur;
            $('mzCategoryOptions').innerHTML = (d.category_list || []).map(function (c) { return '<option value="' + esc(c) + '">'; }).join('');

            // analytics section filter gets the live section list
            var anSel = $('mzAnSection');
            if (anSel) {
                var anCur = anSel.value;
                anSel.innerHTML = '<option value="">All sections</option>' +
                    (d.section_list || []).map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
                anSel.value = anCur;
            }
        }).catch(function () { /* stats non-critical */ });
    }

    function loadList() {
        if (lib.loading) return;
        lib.loading = true;
        var tb = $('mzTbody');
        tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:1.5rem"><i class="fa-solid fa-spinner fa-spin"></i> Loading hymns…</td></tr>';
        var q = 'action=list&page=' + encodeURIComponent(lib.page) + '&per_page=' + PAGE_SIZE +
            '&search=' + encodeURIComponent(lib.search) + '&category=' + encodeURIComponent(lib.category) +
            '&status=' + encodeURIComponent(lib.status);
        SSMS.api.get('mezmur.php?' + q).then(function (d) {
            lib.loading = false;
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#f87171;padding:1.5rem"><i class="fa-solid fa-triangle-exclamation"></i> ' + esc(d.message || 'Unable to load hymns.') + '</td></tr>';
                return;
            }
            lib.totalPages = d.total_pages || 1;
            lib.total = d.total || 0;
            renderHymnRows(d.items || []);
            renderLibPagination();
        }).catch(function (err) {
            lib.loading = false;
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#f87171;padding:1.5rem">' + esc((err && err.message) || 'Connection error.') + '</td></tr>';
        });
    }

    function renderHymnRows(items) {
        var tb = $('mzTbody');
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:2rem">' +
                (lib.search || lib.category ? '<i class="fa-solid fa-magnifying-glass"></i> No hymns match your filters.'
                    : '<i class="fa-solid fa-music"></i> No hymns yet. Click <b>Add Hymn</b> to create the first one.') + '</td></tr>';
            return;
        }
        tb.innerHTML = items.map(function (h) {
            var archived = h.status === 'archived';
            return '<tr style="border-top:1px solid var(--school-border,rgba(255,255,255,.06))' + (archived ? ';opacity:.55' : '') + '">' +
                '<td style="padding:.65rem .75rem;font-weight:600;color:var(--school-text-bright)">' + esc(h.title) + '</td>' +
                '<td class="amharic" style="padding:.65rem .75rem">' + esc(h.title_am || '—') + '</td>' +
                '<td style="padding:.65rem .75rem">' + (h.category ? '<span class="badge badge-info">' + esc(h.category) + '</span>' : '—') + '</td>' +
                '<td style="padding:.65rem .75rem;color:var(--school-text-dim)">' + esc(h.reference || '—') + '</td>' +
                '<td style="padding:.65rem .75rem;color:var(--school-text-dim)">' + fmtDate(h.updated_at) + '</td>' +
                '<td style="padding:.65rem .75rem;text-align:right;white-space:nowrap">' +
                '<button class="btn-secondary btn-sm" title="View" onclick="Mezmur.view(' + h.id + ')"><i class="fa-solid fa-eye"></i></button> ' +
                '<button class="btn-secondary btn-sm" title="Edit" onclick="Mezmur.openEdit(' + h.id + ')"><i class="fa-solid fa-pen"></i></button> ' +
                (archived
                    ? '<button class="btn-secondary btn-sm" title="Restore" onclick="Mezmur.setStatus(' + h.id + ',\'active\')"><i class="fa-solid fa-rotate-left"></i></button>'
                    : '<button class="btn-secondary btn-sm" title="Archive" onclick="Mezmur.setStatus(' + h.id + ',\'archived\')"><i class="fa-solid fa-box-archive"></i></button>') +
                '</td></tr>';
        }).join('');
    }

    function renderLibPagination() {
        var el = $('mzPagination');
        if (lib.totalPages <= 1) { el.innerHTML = '<span style="color:var(--school-text-dim);font-size:.8rem">' + lib.total + ' hymn' + (lib.total === 1 ? '' : 's') + '</span><span></span>'; return; }
        el.innerHTML =
            '<button class="btn-secondary btn-sm" ' + (lib.page <= 1 ? 'disabled' : '') + ' onclick="Mezmur.libPage(' + (lib.page - 1) + ')"><i class="fa-solid fa-chevron-left"></i></button>' +
            '<span style="color:var(--school-text-dim);font-size:.8rem">Page ' + lib.page + ' of ' + lib.totalPages + ' • ' + lib.total + ' hymns</span>' +
            '<button class="btn-secondary btn-sm" ' + (lib.page >= lib.totalPages ? 'disabled' : '') + ' onclick="Mezmur.libPage(' + (lib.page + 1) + ')"><i class="fa-solid fa-chevron-right"></i></button>';
    }

    function clearHymnForm() {
        $('mzHymnId').value = '0'; $('mzTitle').value = ''; $('mzTitleAm').value = '';
        $('mzCategory').value = ''; $('mzReference').value = ''; $('mzLyrics').value = '';
        showError($('mzModalError'), '');
    }

    function openAdd() {
        clearHymnForm();
        $('mzModalTitle').innerHTML = '<i class="fa-solid fa-music"></i> Add Hymn';
        modal('mzHymnModal', true);
        setTimeout(function () { $('mzTitle').focus(); }, 60);
    }

    function openEdit(id) {
        clearHymnForm();
        $('mzModalTitle').innerHTML = '<i class="fa-solid fa-pen"></i> Edit Hymn';
        SSMS.api.get('mezmur.php?action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load this hymn.', 'e'); return; }
            var h = d.item;
            $('mzHymnId').value = h.id; $('mzTitle').value = h.title || ''; $('mzTitleAm').value = h.title_am || '';
            $('mzCategory').value = h.category || ''; $('mzReference').value = h.reference || ''; $('mzLyrics').value = h.lyrics || '';
            modal('mzHymnModal', true);
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    function saveHymn() {
        var title = $('mzTitle').value.trim();
        if (!title) { showError($('mzModalError'), 'Title is required.'); $('mzTitle').focus(); return; }
        var btn = $('mzSaveBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        SSMS.api.post('mezmur.php', {
            action: 'save', id: $('mzHymnId').value, title: title,
            title_am: $('mzTitleAm').value.trim(), category: $('mzCategory').value.trim(),
            reference: $('mzReference').value.trim(), lyrics: $('mzLyrics').value
        }).then(function (d) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Hymn';
            if (d.status !== 'success') { showError($('mzModalError'), d.message || 'Unable to save the hymn.'); return; }
            modal('mzHymnModal', false);
            window.toast(d.message || 'Hymn saved.', 's');
            loadStats(); loadList();
        }).catch(function (err) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Hymn';
            showError($('mzModalError'), (err && err.message) || 'Connection error.');
        });
    }

    function viewHymn(id) {
        SSMS.api.get('mezmur.php?action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load this hymn.', 'e'); return; }
            var h = d.item, meta = '';
            if (h.title_am) meta += '<span class="badge badge-info amharic">' + esc(h.title_am) + '</span>';
            if (h.category) meta += '<span class="badge badge-active">' + esc(h.category) + '</span>';
            if (h.reference) meta += '<span class="badge badge-warning">' + esc(h.reference) + '</span>';
            if (h.status === 'archived') meta += '<span class="badge badge-inactive">Archived</span>';
            $('mzViewTitle').textContent = h.title;
            $('mzViewMeta').innerHTML = meta;
            $('mzViewLyrics').textContent = h.lyrics || '(No lyrics recorded)';
            modal('mzViewModal', true);
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    function setHymnStatus(id, status) {
        var label = status === 'archived' ? 'archive' : 'restore';
        if (!window.confirm('Are you sure you want to ' + label + ' this hymn?')) return;
        SSMS.api.post('mezmur.php', { action: 'set_status', id: id, status: status }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Action failed.', 'e'); return; }
            window.toast(d.message || 'Done.', 's');
            loadStats(); loadList();
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 2 — ATTENDANCE (sessions + section sheets)
    // ══════════════════════════════════════════════════════════
    var att = { page: 1, totalPages: 1, date: null, sheet: null, marks: {} };

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padLeft(2, '0') + '-' + String(d.getDate()).padLeft(2, '0');
    }
    function loadDays(page) {
        att.page = page || 1;
        var tb = $('mzSessTbody');
        tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:1.5rem"><i class="fa-solid fa-spinner fa-spin"></i> Loading attendance days…</td></tr>';
        var q = 'action=days_list&page=' + att.page + '&per_page=' + PAGE_SIZE +
            '&from=' + encodeURIComponent($('mzSessFrom').value || '') + '&to=' + encodeURIComponent($('mzSessTo').value || '');
        SSMS.api.get('mezmur.php?' + q).then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#f87171;padding:1.5rem">' + esc(d.message || 'Unable to load days.') + '</td></tr>';
                return;
            }
            att.totalPages = d.total_pages || 1;
            renderDayRows(d.items || []);
            var pg = $('mzSessPagination');
            pg.innerHTML = att.totalPages <= 1
                ? '<span style="color:var(--school-text-dim);font-size:.8rem">' + d.total + ' attendance day' + (d.total === 1 ? '' : 's') + '</span><span></span>'
                : '<button class="btn-secondary btn-sm" ' + (att.page <= 1 ? 'disabled' : '') + ' onclick="Mezmur.sessPage(' + (att.page - 1) + ')"><i class="fa-solid fa-chevron-left"></i></button>' +
                  '<span style="color:var(--school-text-dim);font-size:.8rem">Page ' + att.page + ' of ' + att.totalPages + '</span>' +
                  '<button class="btn-secondary btn-sm" ' + (att.page >= att.totalPages ? 'disabled' : '') + ' onclick="Mezmur.sessPage(' + (att.page + 1) + ')"><i class="fa-solid fa-chevron-right"></i></button>';
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#f87171;padding:1.5rem">' + esc((err && err.message) || 'Connection error.') + '</td></tr>';
        });
    }

    function renderDayRows(items) {
        var tb = $('mzSessTbody');
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:2rem"><i class="fa-solid fa-calendar-check"></i> No attendance yet. Pick a date above and press <b>Take Attendance</b>.</td></tr>';
            return;
        }
        tb.innerHTML = items.map(function (d) {
            return '<tr style="border-top:1px solid var(--school-border,rgba(255,255,255,.06))">' +
                '<td style="padding:.65rem .75rem;white-space:nowrap">' + fmtDate(d.attendance_date) + '</td>' +
                '<td style="padding:.65rem .75rem"><span class="badge badge-info">' + esc(PROGRAM_LABELS[d.program_type] || d.program_type) + '</span></td>' +
                '<td style="padding:.65rem .75rem;color:var(--school-text-dim)">' + esc(d.title || '—') + '</td>' +
                '<td style="padding:.65rem .75rem">' + d.marked + '</td>' +
                '<td style="padding:.65rem .75rem;color:#10b981;font-weight:600">' + d.attended + '</td>' +
                '<td style="padding:.65rem .75rem;text-align:right;white-space:nowrap">' +
                '<button class="btn-primary btn-sm" onclick="Mezmur.openExisting(\'' + esc(d.attendance_date) + '\')"><i class="fa-solid fa-clipboard-check"></i> ' + (d.marked > 0 ? 'Review' : 'Open') + '</button>' +
                '</td></tr>';
        }).join('');
    }

    // Open (or start) the attendance sheet for a date. day_create is an
    // idempotent get-or-create; the optional program label is stored once.
    function openDay() {
        var date = $('mzAttDate').value;
        if (!date) { window.toast('Pick a date first.', 'e'); return; }
        SSMS.api.post('mezmur.php', {
            action: 'day_create', date: date,
            program_type: $('mzAttProgram').value
        }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Unable to open that date.', 'e'); return; }
            loadSheet(date);
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    function openExisting(date) { loadSheet(date); }

    function loadSheet(date) {
        $('mzSheetBody').innerHTML = '<div style="text-align:center;color:var(--school-text-dim);padding:2rem"><i class="fa-solid fa-spinner fa-spin"></i> Loading sheet…</div>';
        $('mzSessionListView').style.display = 'none';
        $('mzSheetView').style.display = 'block';
        SSMS.api.get('mezmur.php?action=sheet&date=' + encodeURIComponent(date)).then(function (d) {
            if (d.status !== 'success' || !d.day) { window.toast(d.message || 'Unable to load the sheet.', 'e'); closeSheet(); return; }
            att.sheet = d;
            att.date = date;
            att.marks = {};
            // Default every roster member to present; takers flip exceptions.
            Object.keys(d.sections).forEach(function (sec) {
                d.sections[sec].forEach(function (m) { att.marks[m.id] = m.mark || 'present'; });
            });
            $('mzSheetTitle').textContent = 'Attendance — ' + fmtDate(date);
            $('mzSheetMeta').textContent = (PROGRAM_LABELS[d.day.program_type] || d.day.program_type) + (d.day.title ? ' • ' + d.day.title : '');
            renderSheet();
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); closeSheet(); });
    }

    function renderSheet() {
        var html = '';
        Object.keys(att.sheet.sections).forEach(function (sec) {
            var members = att.sheet.sections[sec];
            html += '<div style="margin-bottom:1.25rem">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">' +
                '<h4 class="amharic" style="margin:0;color:var(--school-text-bright)"><i class="fa-solid fa-layer-group" style="color:#8b5cf6"></i> ' + esc(sec) + ' <span style="color:var(--school-text-dim);font-size:.75rem">(' + members.length + ')</span></h4>' +
                '<button class="btn-secondary btn-sm" onclick="Mezmur.markSection(\'' + esc(sec).replace(/'/g, "\\'") + '\',\'present\')">All present</button>' +
                '</div>';
            members.forEach(function (m) { html += memberRow(m); });
            html += '</div>';
        });
        $('mzSheetBody').innerHTML = html || '<div style="text-align:center;color:var(--school-text-dim);padding:2rem">No active members found.</div>';
        updateSheetSummary();
    }

    function memberRow(m) {
        var mark = att.marks[m.id] || 'present';
        function seg(status, label, color) {
            var on = mark === status;
            return '<button type="button" onclick="Mezmur.setMark(' + m.id + ',\'' + status + '\')" style="padding:.3rem .7rem;font-size:.72rem;border:1px solid ' + (on ? color : 'var(--school-border,rgba(255,255,255,.12))') + ';background:' + (on ? color : 'transparent') + ';color:' + (on ? '#fff' : 'var(--school-text-dim)') + ';cursor:pointer;border-radius:6px">' + label + '</button>';
        }
        return '<div data-mzrow="' + m.id + '" style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;padding:.5rem .25rem;border-top:1px solid var(--school-border,rgba(255,255,255,.05))">' +
            '<div style="flex:1;min-width:180px;font-size:.85rem;color:var(--school-text-bright)">' + esc(m.student_name) + ' ' + esc(m.father_name || '') +
            (m.full_name_am ? ' <span class="amharic" style="color:var(--school-text-dim);font-size:.75rem">' + esc(m.full_name_am) + '</span>' : '') + '</div>' +
            '<div style="display:flex;gap:.35rem">' + seg('present', '✓ Present', '#10b981') + seg('late', 'Late', '#f59e0b') + seg('absent', 'Absent', '#ef4444') + '</div>' +
            '</div>';
    }

    function setMark(memberId, status) {
        att.marks[memberId] = status;
        var row = document.querySelector('[data-mzrow="' + memberId + '"]');
        if (row && att.sheet) {
            Object.keys(att.sheet.sections).forEach(function (sec) {
                att.sheet.sections[sec].forEach(function (m) {
                    if (m.id === memberId) row.outerHTML = memberRow(m);
                });
            });
        }
        updateSheetSummary();
    }

    function markAll(status) {
        Object.keys(att.marks).forEach(function (id) { att.marks[id] = status; });
        renderSheet();
    }

    function markSection(section, status) {
        if (!att.sheet || !att.sheet.sections[section]) return;
        att.sheet.sections[section].forEach(function (m) { att.marks[m.id] = status; });
        renderSheet();
    }

    function updateSheetSummary() {
        var p = 0, l = 0, a = 0;
        Object.keys(att.marks).forEach(function (id) {
            if (att.marks[id] === 'present') p++;
            else if (att.marks[id] === 'late') l++;
            else a++;
        });
        var total = p + l + a;
        var rate = total > 0 ? Math.round((p + l) * 1000 / total) / 10 : 0;
        $('mzSheetSummary').innerHTML =
            '<b>' + total + '</b> members • <span style="color:#10b981">' + p + ' present</span> • <span style="color:#f59e0b">' + l + ' late</span> • <span style="color:#ef4444">' + a + ' absent</span> • rate <b>' + rate + '%</b>';
    }

    function saveSheet() {
        if (!att.sheet || !att.date) return;
        var records = Object.keys(att.marks).map(function (id) { return { member_id: parseInt(id, 10), status: att.marks[id] }; });
        var btn = $('mzSheetSaveBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        SSMS.api.post('mezmur.php', {
            action: 'save_sheet', date: att.date, records: JSON.stringify(records)
        }).then(function (d) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Attendance';
            if (d.status !== 'success') { window.toast(d.message || 'Unable to save attendance.', 'e'); return; }
            var sum = d.summary || {};
            window.toast('Saved: ' + sum.present + ' present, ' + sum.late + ' late, ' + sum.absent + ' absent.', 's');
            closeSheet();
            loadDays(att.page);
        }).catch(function (err) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Attendance';
            window.toast((err && err.message) || 'Connection error.', 'e');
        });
    }

    function closeSheet() {
        att.sheet = null;
        $('mzSheetView').style.display = 'none';
        $('mzSessionListView').style.display = 'block';
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 3 — ANALYTICS
    // ══════════════════════════════════════════════════════════
    var an = { page: 1, sort: 'rate', dir: 'desc', lastMembers: [], sessionsHeld: 0 };

    function anParams(page) {
        return 'action=analytics_members&page=' + (page || an.page) + '&per_page=' + PAGE_SIZE +
            '&sort=' + encodeURIComponent(an.sort) + '&dir=' + an.dir +
            '&section=' + encodeURIComponent($('mzAnSection').value || '') +
            '&program_type=' + encodeURIComponent($('mzAnProgram').value || '') +
            '&from=' + encodeURIComponent($('mzAnFrom').value || '') +
            '&to=' + encodeURIComponent($('mzAnTo').value || '') +
            '&search=' + encodeURIComponent($('mzAnSearch').value.trim()) +
            '&min_rate=' + encodeURIComponent($('mzAnMinRate').value || '') +
            '&min_attended=' + encodeURIComponent($('mzAnMinAtt').value || '');
    }

    function runAnalytics(page) {
        an.page = page || 1;
        var tb = $('mzAnTbody');
        tb.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--school-text-dim);padding:1.5rem"><i class="fa-solid fa-spinner fa-spin"></i> Analyzing…</td></tr>';

        var pMembers = SSMS.api.get('mezmur.php?' + anParams(an.page));
        var pSections = SSMS.api.get('mezmur.php?' + anParams(1).replace('action=analytics_members', 'action=analytics_sections'));
        var pTrends = SSMS.api.get('mezmur.php?action=analytics_trends&program_type=' + encodeURIComponent($('mzAnProgram').value || '') +
            '&from=' + encodeURIComponent($('mzAnFrom').value || '') + '&to=' + encodeURIComponent($('mzAnTo').value || ''));

        Promise.all([pMembers, pSections, pTrends]).then(function (res) {
            var dm = res[0], ds = res[1], dt = res[2];
            if (dm.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#f87171;padding:1.5rem">' + esc(dm.message || 'Unable to analyze.') + '</td></tr>';
                return;
            }
            an.lastMembers = dm.items || [];
            an.sessionsHeld = dm.sessions_held || 0;
            $('mzAnHeld').textContent = dm.sessions_held != null ? dm.sessions_held : '—';
            $('mzAnMembers').textContent = (dm.items || []).length;

            // average rate across ranked members (only those with data)
            var withRate = (dm.items || []).filter(function (m) { return m.rate != null; });
            var avg = withRate.length ? withRate.reduce(function (s, m) { return s + m.rate; }, 0) / withRate.length : null;
            $('mzAnAvgRate').textContent = pctLabel(avg);

            renderAnRows(dm);
            renderSectionCards(ds.status === 'success' ? ds.items || [] : []);
            renderTrend(dt.status === 'success' ? dt.items || [] : []);
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#f87171;padding:1.5rem">' + esc((err && err.message) || 'Connection error.') + '</td></tr>';
        });
    }

    function renderAnRows(dm) {
        var tb = $('mzAnTbody');
        var items = dm.items || [];
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--school-text-dim);padding:2rem">No members match these filters.</td></tr>';
            $('mzAnPagination').innerHTML = '';
            return;
        }
        var startRank = (dm.page - 1) * PAGE_SIZE;
        tb.innerHTML = items.map(function (m, i) {
            var rate = m.rate;
            var barColor = rate == null ? '#64748b' : rate >= 75 ? '#10b981' : rate >= 50 ? '#f59e0b' : '#ef4444';
            return '<tr style="border-top:1px solid var(--school-border,rgba(255,255,255,.06))">' +
                '<td style="padding:.6rem .75rem;color:var(--school-text-dim)">' + (startRank + i + 1) + '</td>' +
                '<td style="padding:.6rem .75rem;font-weight:600;color:var(--school-text-bright)">' + esc(m.student_name) + ' ' + esc(m.father_name || '') +
                (m.member_code ? '<div style="font-size:.7rem;color:var(--school-text-dim)">' + esc(m.member_code) + '</div>' : '') + '</td>' +
                '<td class="amharic" style="padding:.6rem .75rem">' + esc(m.section) + '</td>' +
                '<td style="padding:.6rem .75rem"><b>' + m.attended + '</b> / ' + m.sessions_held +
                ' <span style="color:var(--school-text-dim);font-size:.75rem">(' + pctLabel(m.sessions_held > 0 ? m.attended * 100 / m.sessions_held : null) + ')</span></td>' +
                '<td style="padding:.6rem .75rem;min-width:130px"><div style="display:flex;align-items:center;gap:.5rem">' +
                '<div style="flex:1;height:6px;background:var(--school-border,rgba(255,255,255,.08));border-radius:3px;overflow:hidden"><div style="width:' + (rate == null ? 0 : rate) + '%;height:100%;background:' + barColor + '"></div></div>' +
                '<b style="color:' + barColor + '">' + pctLabel(rate) + '</b></div></td>' +
                '<td style="padding:.6rem .75rem;color:#f87171">' + m.absent +
                ' <span style="color:var(--school-text-dim);font-size:.75rem">(' + pctLabel(m.absent_rate) + ')</span></td>' +
                '<td style="padding:.6rem .75rem;color:var(--school-text-dim)">' + fmtDate(m.last_attended) + '</td></tr>';
        }).join('');

        var tp = Math.max(1, Math.ceil(((dm.items || []).length === PAGE_SIZE ? an.page * PAGE_SIZE + 1 : (an.page - 1) * PAGE_SIZE + items.length) / PAGE_SIZE));
        $('mzAnPagination').innerHTML =
            '<button class="btn-secondary btn-sm" ' + (an.page <= 1 ? 'disabled' : '') + ' onclick="Mezmur.runAnalytics(' + (an.page - 1) + ')"><i class="fa-solid fa-chevron-left"></i></button>' +
            '<span style="color:var(--school-text-dim);font-size:.8rem">Page ' + an.page + '</span>' +
            '<button class="btn-secondary btn-sm" ' + (items.length < PAGE_SIZE ? 'disabled' : '') + ' onclick="Mezmur.runAnalytics(' + (an.page + 1) + ')"><i class="fa-solid fa-chevron-right"></i></button>';
    }

    function renderSectionCards(items) {
        var el = $('mzSectionCards');
        if (!items.length) { el.innerHTML = '<div style="color:var(--school-text-dim);font-size:.85rem">No section data for this window.</div>'; return; }
        el.innerHTML = items.map(function (s) {
            var rate = s.rate;
            var color = rate == null ? '#64748b' : rate >= 75 ? '#10b981' : rate >= 50 ? '#f59e0b' : '#ef4444';
            return '<div style="border:1px solid var(--school-border,rgba(255,255,255,.08));border-radius:.75rem;padding:.9rem">' +
                '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">' +
                '<b class="amharic">' + esc(s.section) + '</b><b style="color:' + color + '">' + pctLabel(rate) + '</b></div>' +
                '<div style="font-size:.78rem;color:var(--school-text-dim);line-height:1.7">' +
                s.members + ' members • ' + s.sessions_held + ' sessions<br>' +
                '<span style="color:#10b981">' + s.present + ' present (' + pctLabel(s.present_pct) + ')</span> • ' +
                '<span style="color:#f59e0b">' + s.late + ' late (' + pctLabel(s.late_pct) + ')</span><br>' +
                '<span style="color:#f87171">' + s.absent + ' absent (' + pctLabel(s.absent_pct) + ')</span>' +
                '</div></div>';
        }).join('');
    }

    function renderTrend(items) {
        var el = $('mzTrendBody');
        if (!items.length) { el.innerHTML = '<div style="color:var(--school-text-dim);font-size:.85rem">No sessions in this window.</div>'; return; }
        var max = Math.max.apply(null, items.map(function (t) { return t.rate == null ? 0 : t.rate; }).concat([1]));
        el.innerHTML = '<div style="display:flex;gap:.6rem;align-items:flex-end;overflow-x:auto;padding-bottom:.5rem">' +
            items.map(function (t) {
                var h = Math.max(6, Math.round((t.rate == null ? 0 : t.rate) / max * 110));
                return '<div style="text-align:center;min-width:74px">' +
                    '<div style="font-size:.75rem;color:var(--school-text-bright);font-weight:600">' + pctLabel(t.rate) + '</div>' +
                    '<div style="height:' + h + 'px;background:linear-gradient(180deg,#8b5cf6,#6d28d9);border-radius:6px 6px 0 0;margin:.25rem auto;width:34px"></div>' +
                    '<div style="font-size:.7rem;color:var(--school-text-dim)">' + esc(t.month) + '</div>' +
                    '<div style="font-size:.68rem;color:var(--school-text-dim)">' + t.sessions + ' sess • ' + t.attended + '/' + t.marks + '</div>' +
                    '</div>';
            }).join('') + '</div>';
    }

    function sortBy(col) {
        if (an.sort === col) { an.dir = an.dir === 'desc' ? 'asc' : 'desc'; }
        else { an.sort = col; an.dir = col === 'name' || col === 'section' ? 'asc' : 'desc'; }
        runAnalytics(1);
    }

    function exportCsv() {
        if (!an.lastMembers.length) { window.toast('Run an analysis first.', 'e'); return; }
        var head = ['Member', 'Code', 'Section', 'Sessions Held', 'Present', 'Late', 'Absent', 'Attended', 'Rate %', 'Absent %', 'Last Attended'];
        var rows = an.lastMembers.map(function (m) {
            return [
                m.student_name + ' ' + (m.father_name || ''), m.member_code || '', m.section,
                m.sessions_held, m.present, m.late, m.absent, m.attended,
                m.rate == null ? '' : m.rate, m.absent_rate == null ? '' : m.absent_rate,
                m.last_attended || ''
            ].map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
        });
        var csv = '\ufeff' + head.join(',') + '\n' + rows.join('\n');
        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'mezmur-analytics-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
        window.toast('CSV exported (current page).', 's');
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 4 — ATTENDANCE TAKERS
    // ══════════════════════════════════════════════════════════
    function loadTakers() {
        var tb = $('mzTakerTbody');
        tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:1.5rem"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</td></tr>';
        SSMS.api.get('mezmur.php?action=takers_list').then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#f87171;padding:1.5rem">' + esc(d.message || 'Unable to load takers.') + '</td></tr>';
                return;
            }
            var items = d.items || [];
            if (!items.length) {
                tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:2rem">No attendance taker accounts yet. Click <b>Add Taker</b>.</td></tr>';
                return;
            }
            tb.innerHTML = items.map(function (t) {
                return '<tr style="border-top:1px solid var(--school-border,rgba(255,255,255,.06))">' +
                    '<td style="padding:.65rem .75rem;font-weight:600;color:var(--school-text-bright)">' + esc(t.full_name || t.username) + '</td>' +
                    '<td style="padding:.65rem .75rem;color:var(--school-text-dim)">' + esc(t.username) + '</td>' +
                    '<td style="padding:.65rem .75rem" class="amharic">' + esc(t.student_name ? t.student_name + (t.member_code ? ' (' + t.member_code + ')' : '') : '—') + '</td>' +
                    '<td style="padding:.65rem .75rem;color:var(--school-text-dim)">' + fmtDate(t.created_at) + '</td>' +
                    '<td style="padding:.65rem .75rem">' + (t.is_active ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-inactive">Disabled</span>') + '</td>' +
                    '<td style="padding:.65rem .75rem;text-align:right">' +
                    '<button class="btn-secondary btn-sm" onclick="Mezmur.toggleTaker(' + t.id + ')">' + (t.is_active ? '<i class="fa-solid fa-ban"></i> Disable' : '<i class="fa-solid fa-check"></i> Enable') + '</button>' +
                    '</td></tr>';
            }).join('');
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#f87171;padding:1.5rem">' + esc((err && err.message) || 'Connection error.') + '</td></tr>';
        });
    }

    function openTakerModal() {
        $('mzTkName').value = ''; $('mzTkUser').value = ''; $('mzTkPass').value = '';
        showError($('mzTkError'), '');
        modal('mzTakerModal', true);
    }

    function createTaker() {
        var name = $('mzTkName').value.trim(), user = $('mzTkUser').value.trim(), pass = $('mzTkPass').value;
        if (!name || !user || !pass) { showError($('mzTkError'), 'All fields are required.'); return; }
        if (pass.length < 12) { showError($('mzTkError'), 'Password must be at least 12 characters.'); return; }
        var btn = $('mzTkSaveBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating…';
        // Reuses the hardened user pipeline — server re-checks that the
        // mezmur_dept role may only create attendance_taker accounts.
        SSMS.api.post('/admin/backend/user-save.php', {
            full_name: name, username: user, role: 'attendance_taker',
            password: pass, confirm_password: pass, is_active: 1
        }).then(function (d) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Create Account';
            if (d.status !== 'success') { showError($('mzTkError'), d.message || 'Unable to create the account.'); return; }
            modal('mzTakerModal', false);
            window.toast('Attendance taker account created.', 's');
            loadTakers();
        }).catch(function (err) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Create Account';
            showError($('mzTkError'), (err && err.message) || 'Connection error.');
        });
    }

    function toggleTaker(id) {
        SSMS.api.post('/admin/backend/user-toggle.php', { user_id: id, action: 'toggle_status' }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Action failed.', 'e'); return; }
            window.toast(d.message || 'Done.', 's');
            loadTakers();
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    // ── wiring ─────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        var debounce = null;
        $('mzSearch').addEventListener('input', function () {
            clearTimeout(debounce);
            var v = this.value;
            debounce = setTimeout(function () { lib.search = v.trim(); lib.page = 1; loadList(); }, 300);
        });
        $('mzCategoryFilter').addEventListener('change', function () { lib.category = this.value; lib.page = 1; loadList(); });
        $('mzStatusFilter').addEventListener('change', function () { lib.status = this.value; lib.page = 1; loadList(); });

        $('mzAttDate').value = todayStr();
        $('mzAttDate').max = todayStr();

        loadStats();
        loadList();
        loadDays(1);
        loadTakers();
    });

    // Public API (inline onclick handlers in the shell)
    window.Mezmur = {
        // library
        openAdd: openAdd, openEdit: openEdit, save: saveHymn, view: viewHymn, setStatus: setHymnStatus,
        closeModal: function () { modal('mzHymnModal', false); },
        closeView: function () { modal('mzViewModal', false); },
        libPage: function (p) { if (p >= 1 && p <= lib.totalPages) { lib.page = p; loadList(); } },
        // attendance
        loadDays: function () { loadDays(1); },
        sessPage: function (p) { loadDays(p); },
        openDay: openDay, openExisting: openExisting,
        closeSheet: closeSheet, saveSheet: saveSheet,
        setMark: setMark, markAll: markAll, markSection: markSection,
        // analytics
        runAnalytics: runAnalytics, sortBy: sortBy, exportCsv: exportCsv,
        // takers
        openTakerModal: openTakerModal, createTaker: createTaker, toggleTaker: toggleTaker
    };
})();
