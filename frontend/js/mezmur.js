/**
 * ════════════════════════════════════════════════════════════
 * Mezmur Department (መዝሙር ክፍል) — front-end controller
 *   • Overview • Hymn Library • Attendance (date/section-based)
 *   • Analytics • Attendance Takers
 * UI states everywhere: skeleton → data / empty(+CTA) / error(+retry).
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
        el.classList.toggle('is-hidden', !msg);
    }

    function pctLabel(v) { return v == null ? '—' : (Math.round(v * 10) / 10) + '%'; }

    var PROGRAM_LABELS = {
        rehearsal: 'Rehearsal', service: 'Service', feast: 'Feast',
        training: 'Training', other: 'Other'
    };

    // ── shared state renderers (skeleton / empty / error) ─────
    function skeletonRows(n) {
        var r = '';
        for (var i = 0; i < n; i++) {
            r += '<tr><td colspan="9"><div class="skeleton-row"><div class="skeleton"></div><div class="skeleton"></div></div></td></tr>';
        }
        return r;
    }

    function emptyState(icon, title, text, ctaHtml) {
        return '<div class="empty-state"><i class="fa-solid ' + icon + '"></i>' +
            '<div class="state-title">' + esc(title) + '</div>' +
            '<p class="state-text">' + esc(text) + '</p>' + (ctaHtml || '') + '</div>';
    }

    function errorState(msg, retryCall) {
        return '<div class="error-state"><i class="fa-solid fa-triangle-exclamation"></i>' +
            '<div class="state-title">Something went wrong</div>' +
            '<p class="state-text">' + esc(msg || 'Connection error.') + '</p>' +
            '<button class="btn-secondary btn-sm" onclick="' + retryCall + '"><i class="fa-solid fa-rotate-right"></i> Retry</button></div>';
    }

    function rateTone(rate) { return rate == null ? '' : rate >= 90 ? 'ok' : rate >= 70 ? 'warn' : 'bad'; }

    function rateChip(rate) {
        if (rate == null) return '<span class="text-dim">—</span>';
        return '<span class="rate-chip ' + rateTone(rate) + '">' + rate + '%</span>';
    }

    function rateBar(rate) {
        var w = rate == null ? 0 : Math.max(0, Math.min(100, rate));
        return '<div class="rate-bar ' + rateTone(rate) + '"><div class="rate-bar-track"><div class="rate-bar-fill" style="width:' + w + '%"></div></div><span class="rate-num">' + (rate == null ? '—' : rate + '%') + '</span></div>';
    }

    function deltaHtml(cur, prev, unit) {
        if (prev == null || cur == null) return '';
        var d = Math.round((cur - prev) * 10) / 10;
        if (d === 0) return '<span class="stat-delta flat">— same as last month</span>';
        var up = d > 0;
        return '<span class="stat-delta ' + (up ? 'up' : 'down') + '"><i class="fa-solid fa-arrow-trend-' + (up ? 'up' : 'down') + '"></i> ' + Math.abs(d) + (unit || '') + ' vs last month</span>';
    }

    // ── modal focus management (Esc to close, focus return) ──
    var _modalTrigger = null;
    function openModalF(id, focusSelector) {
        _modalTrigger = document.activeElement;
        modal(id, true);
        setTimeout(function () {
            var el = document.querySelector('#' + id + ' ' + (focusSelector || 'input, select, textarea, button'));
            if (el && el.focus) el.focus();
        }, 60);
    }
    function closeModalF(id) {
        modal(id, false);
        if (_modalTrigger && _modalTrigger.focus) { _modalTrigger.focus(); _modalTrigger = null; }
    }
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelectorAll('.school-modal.show');
        if (open.length) closeModalF(open[open.length - 1].id);
    });

    // ══════════════════════════════════════════════════════════
    // MODULE 0 — OVERVIEW (composed from EXISTING api actions)
    // ══════════════════════════════════════════════════════════
    function isoDate(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function monthWindow(offset) {
        var now = new Date();
        var first = new Date(now.getFullYear(), now.getMonth() + offset, 1);
        var last = offset === 0 ? now : new Date(now.getFullYear(), now.getMonth() + offset + 1, 0);
        return { from: isoDate(first), to: isoDate(last) };
    }

    function loadOverview() {
        var hour = new Date().getHours();
        var greet = hour < 12 ? 'Good Morning' : hour < 17 ? 'Good Afternoon' : 'Good Evening';
        var name = ((window.APP || {}).user || {}).name || '';
        $('mzGreeting').textContent = greet + (name ? ', ' + name.split(' ')[0] : '') + ' 🎵';

        var cur = monthWindow(0), prev = monthWindow(-1);
        var daysQ = function (w) { return 'mezmur.php?action=days_list&page=1&per_page=100&from=' + w.from + '&to=' + w.to; };

        Promise.all([
            SSMS.api.get('mezmur.php?action=stats'),
            SSMS.api.get(daysQ(cur)),
            SSMS.api.get(daysQ(prev)),
            SSMS.api.get('mezmur.php?action=list&page=1&per_page=5&status='),
            SSMS.api.get('mezmur.php?action=takers_list')
        ]).then(function (r) {
            var st = r[0], dc = r[1], dp = r[2], hy = r[3], tk = r[4];

            if (st.status === 'success') {
                $('mzOvHymns').textContent = st.total != null ? st.total : '—';
                $('mzOvMembers').textContent = st.members != null ? st.members : '—';
            }
            if (tk.status === 'success') {
                var items = tk.items || [];
                var active = items.filter(function (t) { return t.is_active; }).length;
                $('mzOvTakers').textContent = active + ' / ' + items.length;
            }

            function agg(d) {
                if (d.status !== 'success') return null;
                var list = d.items || [], marked = 0, attended = 0;
                list.forEach(function (x) { marked += x.marked || 0; attended += x.attended || 0; });
                return { days: d.total || list.length, marked: marked, attended: attended,
                         rate: marked > 0 ? Math.round(attended * 1000 / marked) / 10 : null };
            }
            var c = agg(dc), pv = agg(dp);
            if (c) {
                $('mzOvDays').textContent = c.days;
                $('mzOvRate').textContent = c.rate != null ? c.rate + '%' : '—';
                $('mzOvDaysDelta').innerHTML = deltaHtml(c.days, pv ? pv.days : null, '');
                $('mzOvRateDelta').innerHTML = c.rate != null && pv && pv.rate != null ? deltaHtml(c.rate, pv.rate, ' pts') : '';
            }

            // recent attendance days (top 5 of current window)
            var body = $('mzOvRecentDays');
            if (dc.status !== 'success') {
                body.innerHTML = '<tr><td colspan="4">' + errorState(dc.message, 'Mezmur.loadOverview()') + '</td></tr>';
            } else {
                var days = (dc.items || []).slice(0, 5);
                if (!days.length) {
                    body.innerHTML = '<tr><td colspan="4">' + emptyState('fa-calendar-check', 'No attendance yet this month', 'Open the Attendance tab and record your first day.') + '</td></tr>';
                } else {
                    body.innerHTML = days.map(function (d) {
                        var rate = d.marked > 0 ? Math.round(d.attended * 1000 / d.marked) / 10 : null;
                        return '<tr class="clickable-row" onclick="Mezmur.openExisting(\'' + esc(d.attendance_date) + '\')" tabindex="0">' +
                            '<td class="nowrap">' + fmtDate(d.attendance_date) + '</td>' +
                            '<td><span class="badge badge-info">' + esc(PROGRAM_LABELS[d.program_type] || d.program_type) + '</span></td>' +
                            '<td>' + d.attended + '/' + d.marked + '</td>' +
                            '<td>' + rateChip(rate) + '</td></tr>';
                    }).join('');
                }
            }

            // recent hymns
            var hb = $('mzOvRecentHymns');
            if (hy.status !== 'success') {
                hb.innerHTML = '<tr><td colspan="3">' + errorState(hy.message, 'Mezmur.loadOverview()') + '</td></tr>';
            } else {
                var hymns = hy.items || [];
                if (!hymns.length) {
                    hb.innerHTML = '<tr><td colspan="3">' + emptyState('fa-music', 'No hymns yet', 'Add the first hymn to start the library.') + '</td></tr>';
                } else {
                    hb.innerHTML = hymns.map(function (h) {
                        return '<tr class="clickable-row" onclick="Mezmur.view(' + h.id + ')" tabindex="0">' +
                            '<td>' + esc(h.title) + '</td>' +
                            '<td>' + (h.category ? '<span class="badge badge-info">' + esc(h.category) + '</span>' : '<span class="text-dim">—</span>') + '</td>' +
                            '<td class="nowrap text-dim">' + fmtDate(h.updated_at) + '</td></tr>';
                    }).join('');
                }
            }
        }).catch(function (err) {
            $('mzOvRecentDays').innerHTML = '<tr><td colspan="4">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.loadOverview()') + '</td></tr>';
            $('mzOvRecentHymns').innerHTML = '<tr><td colspan="3">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.loadOverview()') + '</td></tr>';
        });
    }

    function quickTake() {
        switchSection('attendance');
        $('mzAttDate').value = todayStr();
        openDay();
    }
    function quickLibrary() { switchSection('library'); }
    function quickAnalytics() { switchSection('analytics'); }
    function quickTakers() { switchSection('takers'); }

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
        tb.innerHTML = skeletonRows(6);
        var q = 'action=list&page=' + encodeURIComponent(lib.page) + '&per_page=' + PAGE_SIZE +
            '&search=' + encodeURIComponent(lib.search) + '&category=' + encodeURIComponent(lib.category) +
            '&status=' + encodeURIComponent(lib.status);
        SSMS.api.get('mezmur.php?' + q).then(function (d) {
            lib.loading = false;
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="6">' + errorState(d.message || 'Unable to load hymns.', 'Mezmur.libReload()') + '</td></tr>';
                return;
            }
            lib.totalPages = d.total_pages || 1;
            lib.total = d.total || 0;
            renderHymnRows(d.items || []);
            renderLibPagination();
        }).catch(function (err) {
            lib.loading = false;
            tb.innerHTML = '<tr><td colspan="6">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.libReload()') + '</td></tr>';
        });
    }

    function renderHymnRows(items) {
        var tb = $('mzTbody');
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="6">' + (lib.search || lib.category
                ? emptyState('fa-magnifying-glass', 'No matches', 'No hymns match your current search or filters.')
                : emptyState('fa-music', 'No hymns yet', 'Start the library by adding the first hymn.',
                    '<button class="btn-primary btn-sm" onclick="Mezmur.openAdd()"><i class="fa-solid fa-plus"></i> Add Hymn</button>')) + '</td></tr>';
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
        openModalF('mzHymnModal', '#mzTitle');
    }

    function openEdit(id) {
        clearHymnForm();
        $('mzModalTitle').innerHTML = '<i class="fa-solid fa-pen"></i> Edit Hymn';
        SSMS.api.get('mezmur.php?action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load this hymn.', 'e'); return; }
            var h = d.item;
            $('mzHymnId').value = h.id; $('mzTitle').value = h.title || ''; $('mzTitleAm').value = h.title_am || '';
            $('mzCategory').value = h.category || ''; $('mzReference').value = h.reference || ''; $('mzLyrics').value = h.lyrics || '';
            openModalF('mzHymnModal', '#mzTitle');
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
            closeModalF('mzHymnModal');
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
            openModalF('mzViewModal', null);
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
    // ══════════════════════════════════════════════════════════
    // MODULE 2 — ATTENDANCE (date-based, section-grouped)
    // ══════════════════════════════════════════════════════════
    var COLLAPSE_THRESHOLD = 300; // sections collapse above this roster size
    var att = {
        page: 1, totalPages: 1, date: null, sheet: null, marks: {},
        order: [], focusIdx: -1, dirty: false
    };

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function draftKey(date) { return 'mzDraft:' + date; }
    function draftSave() {
        if (!att.date) return;
        try { sessionStorage.setItem(draftKey(att.date), JSON.stringify(att.marks)); } catch (e) { /* storage full/blocked */ }
    }
    function draftClear() {
        if (!att.date) return;
        try { sessionStorage.removeItem(draftKey(att.date)); } catch (e) { /* ignore */ }
    }
    function markDirty() { att.dirty = true; draftSave(); }

    // ── days list ─────────────────────────────────────────────
    function loadDays(page) {
        att.page = page || 1;
        var tb = $('mzSessTbody');
        tb.innerHTML = skeletonRows(5);
        var q = 'action=days_list&page=' + att.page + '&per_page=' + PAGE_SIZE +
            '&from=' + encodeURIComponent($('mzSessFrom').value || '') + '&to=' + encodeURIComponent($('mzSessTo').value || '');
        SSMS.api.get('mezmur.php?' + q).then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="7">' + errorState(d.message || 'Unable to load days.', 'Mezmur.loadDays()') + '</td></tr>';
                return;
            }
            att.totalPages = d.total_pages || 1;
            renderDayRows(d.items || [], d.total || 0);
            var pg = $('mzSessPagination');
            pg.innerHTML = att.totalPages <= 1
                ? '<span class="text-dim">' + (d.total || 0) + ' attendance day' + ((d.total || 0) === 1 ? '' : 's') + '</span><span></span>'
                : '<button class="btn-secondary btn-sm" ' + (att.page <= 1 ? 'disabled' : '') + ' onclick="Mezmur.sessPage(' + (att.page - 1) + ')" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></button>' +
                  '<span class="text-dim">Page ' + att.page + ' of ' + att.totalPages + '</span>' +
                  '<button class="btn-secondary btn-sm" ' + (att.page >= att.totalPages ? 'disabled' : '') + ' onclick="Mezmur.sessPage(' + (att.page + 1) + ')" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></button>';
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="7">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.loadDays()') + '</td></tr>';
        });
    }

    function renderDayRows(items, total) {
        var tb = $('mzSessTbody');
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="7">' + emptyState('fa-calendar-check', 'No attendance yet',
                'Pick a date above and press Take Attendance to record your first day.',
                '<button class="btn-primary btn-sm" onclick="Mezmur.openDay()"><i class="fa-solid fa-clipboard-check"></i> Take Attendance</button>') + '</td></tr>';
            return;
        }
        tb.innerHTML = items.map(function (d) {
            var rate = d.marked > 0 ? Math.round(d.attended * 1000 / d.marked) / 10 : null;
            return '<tr>' +
                '<td class="nowrap">' + fmtDate(d.attendance_date) + '</td>' +
                '<td><span class="badge badge-info">' + esc(PROGRAM_LABELS[d.program_type] || d.program_type) + '</span></td>' +
                '<td class="text-dim">' + esc(d.title || '—') + '</td>' +
                '<td>' + d.marked + '</td>' +
                '<td class="text-ok"><b>' + d.attended + '</b></td>' +
                '<td>' + rateBar(rate) + '</td>' +
                '<td class="nowrap"><button class="btn-primary btn-sm" onclick="Mezmur.openExisting(\'' + esc(d.attendance_date) + '\')">' +
                '<i class="fa-solid fa-clipboard-check"></i> ' + (d.marked > 0 ? 'Review' : 'Open') + '</button></td></tr>';
        }).join('');
    }

    // ── open / load sheet ─────────────────────────────────────
    // day_create is an idempotent get-or-create; program label stored once.
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
        $('mzSheetBody').innerHTML = skeletonRows(8);
        $('mzSessionListView').style.display = 'none';
        $('mzSheetView').style.display = 'block';
        SSMS.api.get('mezmur.php?action=sheet&date=' + encodeURIComponent(date)).then(function (d) {
            if (d.status !== 'success' || !d.day) { window.toast(d.message || 'Unable to load the sheet.', 'e'); closeSheet(true); return; }
            att.sheet = d;
            att.date = date;
            att.marks = {};
            att.order = [];
            att.focusIdx = -1;
            att.dirty = false;
            Object.keys(d.sections).forEach(function (sec) {
                d.sections[sec].forEach(function (m) {
                    att.marks[m.id] = m.mark || 'present';
                    att.order.push(m.id);
                });
            });
            var label = (PROGRAM_LABELS[d.day.program_type] || d.day.program_type) + (d.day.title ? ' • ' + d.day.title : '');
            $('mzSheetTitle').textContent = 'Attendance — ' + fmtDate(date);
            $('mzSheetMeta').textContent = label + ' • ' + att.order.length + ' members';
            var pm = $('mzPrintMeta');
            if (pm) pm.textContent = label + ' • ' + att.order.length + ' members';
            renderSheet();
            restoreDraft();
            updateSheetSummary();
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); closeSheet(true); });
    }

    function restoreDraft() {
        var raw = null;
        try { raw = sessionStorage.getItem(draftKey(att.date)); } catch (e) { /* ignore */ }
        if (!raw) return;
        var saved = {};
        try { saved = JSON.parse(raw); } catch (e) { saved = {}; }
        var applied = 0;
        Object.keys(saved).forEach(function (id) {
            if (att.marks.hasOwnProperty(id) && ['present', 'late', 'absent'].indexOf(saved[id]) !== -1 && att.marks[id] !== saved[id]) {
                att.marks[id] = saved[id];
                applied++;
            }
        });
        if (applied > 0) {
            att.dirty = true;
            renderSheet();
            window.toast('Restored ' + applied + ' unsaved change' + (applied === 1 ? '' : 's') + ' from your draft.', 's');
        }
    }

    // ── sheet rendering (section-grouped, batched) ────────────
    function renderSheet() {
        if (!att.sheet) return;
        var sections = Object.keys(att.sheet.sections);
        var total = att.order.length;
        var collapseDefault = total > COLLAPSE_THRESHOLD;
        var html = '';
        if (!total) {
            html = emptyState('fa-users', 'No active members', 'Add members to the roster to start recording attendance.');
        }
        sections.forEach(function (sec, gi) {
            var members = att.sheet.sections[sec];
            var collapsed = collapseDefault && gi > 0;
            html += '<div class="group">' +
                '<div class="group-head">' +
                (collapseDefault ? '<button class="group-caret" aria-expanded="' + (!collapsed) + '" onclick="Mezmur.toggleGroup(' + gi + ')" aria-label="Toggle ' + esc(sec) + '"><i class="fa-solid fa-chevron-' + (collapsed ? 'right' : 'down') + '"></i></button>' : '') +
                '<h4><i class="fa-solid fa-layer-group"></i> ' + esc(sec) + '</h4>' +
                '<span class="group-count">' + members.length + ' members</span>' +
                '<div class="group-actions no-print">' +
                '<button class="btn-secondary btn-sm" onclick="Mezmur.markSection(' + gi + ',\'present\')">All present</button>' +
                '</div></div>' +
                '<div class="group-body' + (collapsed ? ' is-collapsed' : '') + '" data-group="' + gi + '">';
            members.forEach(function (m) { html += memberRow(m); });
            html += '</div></div>';
        });
        $('mzSheetBody').innerHTML = html;
    }

    function toggleGroup(gi) {
        var body = document.querySelector('[data-group="' + gi + '"]');
        if (!body) return;
        var collapsed = body.classList.toggle('is-collapsed');
        var caret = body.previousElementSibling && body.previousElementSibling.querySelector('.group-caret');
        if (caret) {
            caret.setAttribute('aria-expanded', String(!collapsed));
            caret.innerHTML = '<i class="fa-solid fa-chevron-' + (collapsed ? 'right' : 'down') + '"></i>';
        }
    }

    function memberRow(m) {
        var mark = att.marks[m.id] || 'present';
        function seg(status, label) {
            return '<button type="button" class="seg-btn seg-' + status + '" aria-pressed="' + (mark === status) + '" ' +
                'onclick="Mezmur.setMark(' + m.id + ',\'' + status + '\')" aria-label="' + label + '"><span aria-hidden="true">' +
                (status === 'present' ? '✓ ' : '') + label + '</span></button>';
        }
        return '<div class="member-row" data-mzrow="' + m.id + '">' +
            '<div class="member-name">' + esc(m.student_name) + ' ' + esc(m.father_name || '') +
            (m.full_name_am ? '<span class="member-am amharic">' + esc(m.full_name_am) + '</span>' : '') + '</div>' +
            '<div class="seg-group" role="group" aria-label="Attendance status">' +
            seg('present', 'Present') + seg('late', 'Late') + seg('absent', 'Absent') +
            '</div></div>';
    }

    // ── marking (single-row update, no full re-render) ────────
    function setMark(memberId, status) {
        if (!att.sheet || !att.marks.hasOwnProperty(memberId)) return;
        att.marks[memberId] = status;
        markDirty();
        var row = document.querySelector('[data-mzrow="' + memberId + '"]');
        if (row) {
            row.querySelectorAll('.seg-btn').forEach(function (btn) {
                btn.setAttribute('aria-pressed', String(btn.classList.contains('seg-' + status)));
            });
        }
        updateSheetSummary();
    }

    function markAll(status) {
        if (!att.sheet) return;
        Object.keys(att.marks).forEach(function (id) { att.marks[id] = status; });
        markDirty();
        renderSheet();
        updateSheetSummary();
    }

    function markSection(gi, status) {
        if (!att.sheet) return;
        var sec = Object.keys(att.sheet.sections)[gi];
        if (!sec) return;
        att.sheet.sections[sec].forEach(function (m) { att.marks[m.id] = status; });
        markDirty();
        renderSheet();
        updateSheetSummary();
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
            '<b>' + total + '</b> members • <span class="text-ok"><b>' + p + '</b> present</span> • ' +
            '<span class="text-warn"><b>' + l + '</b> late</span> • <span class="text-bad"><b>' + a + '</b> absent</span> • rate <b>' + rate + '%</b>';
    }

    // ── keyboard marking: ↑/↓ move, P/L/A set ─────────────────
    document.addEventListener('keydown', function (e) {
        if (!att.sheet || $('mzSheetView').style.display === 'none') return;
        if (document.querySelector('.school-modal.show')) return;
        var t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT')) return;
        var k = e.key;
        if (k === 'ArrowDown' || k === 'ArrowUp') {
            e.preventDefault();
            var next = att.focusIdx + (k === 'ArrowDown' ? 1 : -1);
            if (next < 0) next = 0;
            if (next > att.order.length - 1) next = att.order.length - 1;
            setFocusRow(next);
        } else if (k === 'p' || k === 'P') { if (att.focusIdx >= 0) { e.preventDefault(); setMark(att.order[att.focusIdx], 'present'); } }
        else if (k === 'l' || k === 'L') { if (att.focusIdx >= 0) { e.preventDefault(); setMark(att.order[att.focusIdx], 'late'); } }
        else if (k === 'a' || k === 'A') { if (att.focusIdx >= 0) { e.preventDefault(); setMark(att.order[att.focusIdx], 'absent'); } }
    });

    function setFocusRow(idx) {
        att.focusIdx = idx;
        document.querySelectorAll('.member-row.is-focused').forEach(function (el) { el.classList.remove('is-focused'); });
        var el = document.querySelector('[data-mzrow="' + att.order[idx] + '"]');
        if (el) {
            el.classList.add('is-focused');
            if (el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        }
    }

    // ── save / close ──────────────────────────────────────────
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
            att.dirty = false;
            draftClear();
            window.toast('Saved: ' + sum.present + ' present, ' + sum.late + ' late, ' + sum.absent + ' absent.', 's');
            closeSheet(true);
            loadDays(att.page);
        }).catch(function (err) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Attendance';
            window.toast((err && err.message) || 'Connection error.', 'e');
        });
    }

    function closeSheet(force) {
        if (!force && att.dirty && !window.confirm('You have unsaved attendance changes. Leave anyway? Your draft is kept for this date.')) {
            return;
        }
        if (!att.dirty) draftClear();
        att.sheet = null;
        att.dirty = false;
        att.focusIdx = -1;
        $('mzSheetView').style.display = 'none';
        $('mzSessionListView').style.display = 'block';
    }

    // warn on page unload with unsaved marks
    window.addEventListener('beforeunload', function (e) {
        if (att.dirty && att.sheet) { e.preventDefault(); e.returnValue = ''; }
    });

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
        tb.innerHTML = skeletonRows(8);

        var pMembers = SSMS.api.get('mezmur.php?' + anParams(an.page));
        var pSections = SSMS.api.get('mezmur.php?' + anParams(1).replace('action=analytics_members', 'action=analytics_sections'));
        var pTrends = SSMS.api.get('mezmur.php?action=analytics_trends&program_type=' + encodeURIComponent($('mzAnProgram').value || '') +
            '&from=' + encodeURIComponent($('mzAnFrom').value || '') + '&to=' + encodeURIComponent($('mzAnTo').value || ''));

        Promise.all([pMembers, pSections, pTrends]).then(function (res) {
            var dm = res[0], ds = res[1], dt = res[2];
            if (dm.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="7">' + errorState(dm.message || 'Unable to analyze.', 'Mezmur.runAnalytics(' + an.page + ')') + '</td></tr>';
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
            tb.innerHTML = '<tr><td colspan="7">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.runAnalytics(' + an.page + ')') + '</td></tr>';
        });
    }

    function renderAnRows(dm) {
        var tb = $('mzAnTbody');
        var items = dm.items || [];
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="7">' + emptyState('fa-filter-circle-xmark', 'No members match', 'Adjust the filters above and press Analyze again.') + '</td></tr>';
            $('mzAnPagination').innerHTML = '';
            return;
        }
        var startRank = (dm.page - 1) * PAGE_SIZE;
        tb.innerHTML = items.map(function (m, i) {
            return '<tr>' +
                '<td class="text-dim">' + (startRank + i + 1) + '</td>' +
                '<td><b>' + esc(m.student_name) + '</b> ' + esc(m.father_name || '') +
                (m.member_code ? '<div class="text-dim">' + esc(m.member_code) + '</div>' : '') + '</td>' +
                '<td class="amharic">' + esc(m.section) + '</td>' +
                '<td><b>' + m.attended + '</b> / ' + m.sessions_held +
                ' <span class="text-dim">(' + pctLabel(m.sessions_held > 0 ? m.attended * 100 / m.sessions_held : null) + ')</span></td>' +
                '<td>' + rateBar(m.rate) + '</td>' +
                '<td class="text-bad">' + m.absent +
                ' <span class="text-dim">(' + pctLabel(m.absent_rate) + ')</span></td>' +
                '<td class="text-dim nowrap">' + fmtDate(m.last_attended) + '</td></tr>';
        }).join('');
        updateSortHeaders();

        var tp = Math.max(1, Math.ceil(((dm.items || []).length === PAGE_SIZE ? an.page * PAGE_SIZE + 1 : (an.page - 1) * PAGE_SIZE + items.length) / PAGE_SIZE));
        $('mzAnPagination').innerHTML =
            '<button class="btn-secondary btn-sm" ' + (an.page <= 1 ? 'disabled' : '') + ' onclick="Mezmur.runAnalytics(' + (an.page - 1) + ')"><i class="fa-solid fa-chevron-left"></i></button>' +
            '<span style="color:var(--school-text-dim);font-size:.8rem">Page ' + an.page + '</span>' +
            '<button class="btn-secondary btn-sm" ' + (items.length < PAGE_SIZE ? 'disabled' : '') + ' onclick="Mezmur.runAnalytics(' + (an.page + 1) + ')"><i class="fa-solid fa-chevron-right"></i></button>';
    }

    function renderSectionCards(items) {
        var el = $('mzSectionCards');
        if (!items.length) { el.innerHTML = emptyState('fa-layer-group', 'No section data', 'No attendance falls inside this window.'); return; }
        el.innerHTML = items.map(function (s) {
            return '<div class="school-card">' +
                '<div class="page-head" style="margin-bottom:.6rem"><h3 class="amharic">' + esc(s.section) + '</h3>' + rateChip(s.rate) + '</div>' +
                rateBar(s.rate) +
                '<div class="text-dim mt-1">' +
                s.members + ' members • ' + s.sessions_held + ' days<br>' +
                '<span class="text-ok">' + s.present + ' present (' + pctLabel(s.present_pct) + ')</span> • ' +
                '<span class="text-warn">' + s.late + ' late (' + pctLabel(s.late_pct) + ')</span><br>' +
                '<span class="text-bad">' + s.absent + ' absent (' + pctLabel(s.absent_pct) + ')</span>' +
                '</div></div>';
        }).join('');
    }

    function renderTrend(items) {
        var el = $('mzTrendBody');
        if (!items.length) { el.innerHTML = emptyState('fa-chart-line', 'No attendance in this window', 'Record attendance days to see the monthly trend.'); return; }
        var max = Math.max.apply(null, items.map(function (t) { return t.rate == null ? 0 : t.rate; }).concat([1]));
        el.innerHTML = '<div class="trend-wrap">' +
            items.map(function (t) {
                var h = Math.max(6, Math.round((t.rate == null ? 0 : t.rate) / max * 110));
                return '<div class="trend-col">' +
                    '<div class="trend-col-rate">' + pctLabel(t.rate) + '</div>' +
                    '<div class="trend-col-bar" style="height:' + h + 'px"></div>' +
                    '<div class="trend-col-month">' + esc(t.month) + '</div>' +
                    '<div class="trend-col-sub">' + t.sessions + ' days • ' + t.attended + '/' + t.marks + '</div>' +
                    '</div>';
            }).join('') + '</div>';
    }

    function sortBy(col) {
        if (an.sort === col) { an.dir = an.dir === 'desc' ? 'asc' : 'desc'; }
        else { an.sort = col; an.dir = col === 'name' || col === 'section' ? 'asc' : 'desc'; }
        runAnalytics(1);
    }

    function updateSortHeaders() {
        var map = { name: 1, section: 2, attended: 3, rate: 4, absent: 5, last_attended: 6 };
        document.querySelectorAll('#section-analytics .th-sortable').forEach(function (th, idx) {
            var col = Object.keys(map).filter(function (k) { return map[k] === idx + 1; })[0];
            th.classList.remove('sort-asc', 'sort-desc');
            if (col === an.sort) {
                th.classList.add(an.dir === 'asc' ? 'sort-asc' : 'sort-desc');
                th.setAttribute('aria-sort', an.dir === 'asc' ? 'ascending' : 'descending');
            } else {
                th.setAttribute('aria-sort', 'none');
            }
        });
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
        tb.innerHTML = skeletonRows(4);
        SSMS.api.get('mezmur.php?action=takers_list').then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="6">' + errorState(d.message || 'Unable to load takers.', 'Mezmur.reloadTakers()') + '</td></tr>';
                return;
            }
            var items = d.items || [];
            if (!items.length) {
                tb.innerHTML = '<tr><td colspan="6">' + emptyState('fa-user-shield', 'No taker accounts yet', 'Create an account so a trusted member can record attendance from web or mobile.', '<button class="btn-primary btn-sm" onclick="Mezmur.openTakerModal()"><i class="fa-solid fa-user-plus"></i> Add Taker</button>') + '</td></tr>';
                return;
            }
            tb.innerHTML = items.map(function (t) {
                return '<tr>' +
                    '<td><b>' + esc(t.full_name || t.username) + '</b></td>' +
                    '<td class="text-dim">' + esc(t.username) + '</td>' +
                    '<td class="amharic">' + esc(t.student_name ? t.student_name + (t.member_code ? ' (' + t.member_code + ')' : '') : '—') + '</td>' +
                    '<td class="text-dim nowrap">' + fmtDate(t.created_at) + '</td>' +
                    '<td>' + (t.is_active ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-inactive">Disabled</span>') + '</td>' +
                    '<td class="nowrap">' +
                    '<button class="btn-secondary btn-sm" onclick="Mezmur.toggleTaker(' + t.id + ')">' + (t.is_active ? '<i class="fa-solid fa-ban"></i> Disable' : '<i class="fa-solid fa-check"></i> Enable') + '</button>' +
                    '</td></tr>';
            }).join('');
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="6">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.reloadTakers()') + '</td></tr>';
        });
    }

    function openTakerModal() {
        $('mzTkName').value = ''; $('mzTkUser').value = ''; $('mzTkPass').value = '';
        showError($('mzTkError'), '');
        openModalF('mzTakerModal', '#mzTkName');
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
            closeModalF('mzTakerModal');
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

        loadOverview();
        loadStats();
        loadList();
        loadDays(1);
        loadTakers();
    });

    // Public API (inline onclick handlers in the shell)
    window.Mezmur = {
        // overview
        loadOverview: loadOverview, reloadTakers: loadTakers, libReload: loadList,
        quickTake: quickTake, quickLibrary: quickLibrary, quickAnalytics: quickAnalytics, quickTakers: quickTakers,
        // library
        openAdd: openAdd, openEdit: openEdit, save: saveHymn, view: viewHymn, setStatus: setHymnStatus,
        closeModal: function () { closeModalF('mzHymnModal'); },
        closeView: function () { closeModalF('mzViewModal'); },
        libPage: function (p) { if (p >= 1 && p <= lib.totalPages) { lib.page = p; loadList(); } },
        // attendance
        loadDays: function () { loadDays(1); },
        sessPage: function (p) { loadDays(p); },
        openDay: openDay, openExisting: openExisting,
        closeSheet: function () { closeSheet(false); }, saveSheet: saveSheet,
        setMark: setMark, markAll: markAll, markSection: markSection, toggleGroup: toggleGroup,
        // analytics
        runAnalytics: runAnalytics, sortBy: sortBy, exportCsv: exportCsv,
        // takers
        openTakerModal: openTakerModal, createTaker: createTaker, toggleTaker: toggleTaker
    };
})();
