/**
 * ════════════════════════════════════════════════════════════
 * Mezmur Department (መዝሙር ክፍል) — front-end controller
 *   • Overview • Hymn Library • Attendance (section-first,
 *     teachers/edu workflow clone) • Analytics • Takers
 *
 * Phase-5 performance model (big-company pattern):
 *   1. LAZY TABS — each section loads its data on first
 *      activation only; nothing fires on DOMContentLoaded except
 *      the active tab.
 *   2. BATCHED OVERVIEW — one `action=overview` round trip for
 *      every overview widget (BFF pattern), not 8 parallel GETs.
 *   3. BOUNDED GETS — every read races a 12 s timeout so a
 *      skeleton can NEVER animate forever; timeout renders the
 *      error state with Retry.
 *
 * Pure UI layer for frontend/pages/mezmur_dept.php. Every data
 * access goes through /backend/api/mezmur.php (shim →
 * admin/api_mezmur.php) which re-validates session, role, CSRF,
 * rate-limits and every input server-side.
 *
 * Security conventions (same as finance.js):
 *   - All dynamic values HTML-escaped via esc() before innerHTML;
 *     free-form long text uses textContent only.
 *   - All mutations are POST (CSRF auto-appended by window.api).
 *   - All lists are server-side paginated (scale-safe).
 * ════════════════════════════════════════════════════════════
 */
(function () {
    'use strict';

    var PAGE_SIZE = 25;
    var GET_TIMEOUT = 12000; // ms — skeletons may never outlive this

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

    function todayStr() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    // ── schema reconciliation (one-click migration) ──────────
    function migrateSchema() {
        if (!window.confirm('Align the mezmur database schema with the current code? This is safe to run at any time.')) return;
        apiPost({ action: 'migrate' }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Schema sync failed.', 'e'); return; }
            var applied = (d.applied || []).length;
            var failed = Object.keys(d.failed || {}).length;
            window.toast(d.message || ('Schema synced (' + applied + ' change(s)).'), failed ? 'e' : 's');
            if (failed) console.error('mezmur migrate failures:', d.failed);
            if (typeof Mezmur.loadOverview === 'function') Mezmur.loadOverview();
        }).catch(function (err) {
            window.toast(((err && err.message) || 'Connection error.') + staleHint(err), 'e');
        });
    }

    // ── bounded API access (the skeleton-forever fix) ─────────
    function apiGet(q) {
        var p = window.api.get('mezmur.php?' + q);
        return new Promise(function (resolve, reject) {
            var done = false;
            var timer = setTimeout(function () {
                if (!done) { done = true; reject(new Error('The server took too long to answer. Check your connection and retry.')); }
            }, GET_TIMEOUT);
            p.then(function (d) {
                if (!done) { done = true; clearTimeout(timer); resolve(d); }
            }).catch(function (e) {
                if (!done) { done = true; clearTimeout(timer); reject(e); }
            });
        });
    }

    var POST_TIMEOUT = 20000; // ms — a save can never hang the UI past this

    function apiPost(data) {
        var p = window.api.post('mezmur.php', data);
        return new Promise(function (resolve, reject) {
            var done = false;
            var timer = setTimeout(function () {
                if (!done) { done = true; reject(new Error('The server took too long to answer. Your changes may not have been saved — check the list before saving again.')); }
            }, POST_TIMEOUT);
            p.then(function (d) {
                if (!done) { done = true; clearTimeout(timer); resolve(d); }
            }).catch(function (e) {
                if (!done) { done = true; clearTimeout(timer); reject(e); }
            });
        });
    }

    /**
     * Stale-deployment hint. The current server marks every mezmur
     * response with server_meta; generic server errors with no marker
     * almost always mean the deployed backend is behind the web/app
     * build. Give an actionable message instead of a scary dead end.
     */
    function staleHint(err) {
        var m = (err && err.message) || '';
        if (/server error|invalid server response|took too long|failed to fetch|network|connection error/i.test(m)) {
            return ' If this keeps happening, the server backend may be outdated — ask the administrator to pull the latest code and run sql/024_mezmur_submissions.sql.';
        }
        return '';
    }

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

    var STATUS_META = {
        draft:             { label: 'Draft',           badge: 'badge-inactive' },
        incomplete:        { label: 'Draft',           badge: 'badge-inactive' },
        submitted:         { label: 'Submitted',       badge: 'badge-info' },
        approved:          { label: 'Approved',        badge: 'badge-active' },
        rejected:          { label: 'Rejected',        badge: 'badge-warning' },
        revision_needed:   { label: 'Needs revision',  badge: 'badge-warning' }
    };

    function statusChip(status) {
        var m = STATUS_META[status] || { label: status || '—', badge: 'badge-inactive' };
        return '<span class="badge ' + m.badge + '">' + esc(m.label) + '</span>';
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
    // LAZY TAB LOADING — data fetches on first tab activation
    // ══════════════════════════════════════════════════════════
    var tabLoaded = { overview: false, library: false, attendance: false, analytics: false, takers: false };

    function loadTab(name) {
        if (name === 'overview' && !tabLoaded.overview) {
            tabLoaded.overview = true;
            loadOverview();
        } else if (name === 'library' && !tabLoaded.library) {
            tabLoaded.library = true;
            loadStats();
            loadList();
        } else if (name === 'attendance' && !tabLoaded.attendance) {
            tabLoaded.attendance = true;
            loadSections();
            loadDays(1);
            loadSubmissions();
        } else if (name === 'analytics' && !tabLoaded.analytics) {
            tabLoaded.analytics = true;
            ensureAnalyticsSections();
        } else if (name === 'takers' && !tabLoaded.takers) {
            tabLoaded.takers = true;
            loadTakers();
        }
    }

    // Wrap core.js's switchSection so every activation (sidebar,
    // bottom nav, quick tiles, session restore) lazy-loads its tab.
    var _origSwitch = window.switchSection;
    if (typeof _origSwitch === 'function') {
        window.switchSection = function (name) {
            _origSwitch(name);
            loadTab(name);
        };
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 0 — OVERVIEW (ONE batched request)
    // ══════════════════════════════════════════════════════════
    function loadOverview() {
        var hour = new Date().getHours();
        var greet = hour < 12 ? 'Good Morning' : hour < 17 ? 'Good Afternoon' : 'Good Evening';
        var name = ((window.APP || {}).user || {}).name || '';
        $('mzGreeting').textContent = greet + (name ? ', ' + name.split(' ')[0] : '') + ' 🎵';

        apiGet('action=overview').then(function (d) {
            if (d.status !== 'success') {
                var msg = d.message || 'Unable to load the overview.';
                $('mzOvRecentDays').innerHTML = '<tr><td colspan="3">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
                $('mzOvRecentHymns').innerHTML = '<tr><td colspan="3">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
                $('mzOvQueue').innerHTML = '<tr><td colspan="5">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
                return;
            }

            $('mzOvHymns').textContent = d.hymns_total != null ? d.hymns_total : '—';
            $('mzOvMembers').textContent = d.members != null ? d.members : '—';
            $('mzOvTakers').textContent = (d.takers_active || 0) + ' / ' + (d.takers_total || 0);

            var m = d.month || {}, pv = d.prev_month || {};
            $('mzOvDays').textContent = m.days != null ? m.days : '—';
            $('mzOvRate').textContent = m.rate != null ? m.rate + '%' : '—';
            $('mzOvDaysDelta').innerHTML = deltaHtml(m.days, pv.days, '');
            $('mzOvRateDelta').innerHTML = (m.rate != null && pv.rate != null) ? deltaHtml(m.rate, pv.rate, ' pts') : '';

            // recent attendance days (no program labels — raw data)
            var body = $('mzOvRecentDays');
            var days = d.recent_days || [];
            if (!days.length) {
                body.innerHTML = '<tr><td colspan="3">' + emptyState('fa-calendar-check', 'No attendance yet this month', 'Open the Attendance tab and record your first day.') + '</td></tr>';
            } else {
                body.innerHTML = days.map(function (x) {
                    var rate = x.marked > 0 ? Math.round(x.attended * 1000 / x.marked) / 10 : null;
                    return '<tr class="clickable-row" onclick="Mezmur.jumpToDate(\'' + esc(x.attendance_date) + '\')" tabindex="0">' +
                        '<td class="nowrap">' + fmtDate(x.attendance_date) + '</td>' +
                        '<td>' + x.attended + '/' + x.marked + '</td>' +
                        '<td>' + rateChip(rate) + '</td></tr>';
                }).join('');
            }

            // recent hymns
            var hb = $('mzOvRecentHymns');
            var hymns = d.recent_hymns || [];
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

            // review queue preview
            var qb = $('mzOvQueue');
            var packets = d.recent_packets || [];
            if (!packets.length) {
                qb.innerHTML = '<tr><td colspan="5">' + emptyState('fa-inbox', 'Queue is empty', 'Packets saved or submitted by takers appear here for review.') + '</td></tr>';
            } else {
                qb.innerHTML = packets.map(function (p) {
                    return '<tr class="clickable-row" onclick="Mezmur.gotoAttendance()" tabindex="0">' +
                        '<td class="nowrap">' + fmtDate(p.attendance_date) + '</td>' +
                        '<td class="amharic">' + esc(p.section) + '</td>' +
                        '<td>' + p.member_count + '</td>' +
                        '<td>' + statusChip(p.status) + '</td>' +
                        '<td class="nowrap text-dim">' + fmtDate(p.updated_at) + '</td></tr>';
                }).join('');
            }
        }).catch(function (err) {
            var msg = ((err && err.message) || 'Connection error.') + staleHint(err);
            $('mzOvRecentDays').innerHTML = '<tr><td colspan="3">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
            $('mzOvRecentHymns').innerHTML = '<tr><td colspan="3">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
            $('mzOvQueue').innerHTML = '<tr><td colspan="5">' + errorState(msg, 'Mezmur.loadOverview()') + '</td></tr>';
        });
    }

    function gotoAttendance() { window.switchSection('attendance'); }
    function jumpToDate(date) {
        att.viewDate = date;
        window.switchSection('attendance');
        ensureViewSections();
        $('mzSessionListView').style.display = 'none';
        $('mzSheetView').style.display = 'block';
        $('mzSheetTitle').textContent = 'Attendance — ' + fmtDate(date);
        $('mzSheetMeta').textContent = 'Pick a section and press View to inspect the recorded sheet.';
        $('mzSheetBody').innerHTML = emptyState('fa-eye', 'Read-only view', 'Select a section above to see the recorded marks for this day.');
        $('mzSheetSummary').innerHTML = '';
        renderSheetStatus('');
    }

    /** Populate the read-only section selector (cached per tab visit). */
    function ensureViewSections() {
        var sel = $('mzViewSection');
        if (sel && sel.options.length <= 1) loadSections();
    }

    function viewSheet() {
        var section = $('mzViewSection').value;
        if (!section) { window.toast('Pick a section first.', 'e'); return; }
        var date = att.viewDate || todayStr();
        loadSheet(date, section);
    }

    function quickReview() {
        window.switchSection('attendance');
        loadSubmissions();
    }
    function quickTake() { window.switchSection('attendance'); }
    function quickLibrary() { window.switchSection('library'); }
    function quickAnalytics() { window.switchSection('analytics'); }
    function quickTakers() { window.switchSection('takers'); }

    // ══════════════════════════════════════════════════════════
    // MODULE 1 — HYMN LIBRARY
    // ══════════════════════════════════════════════════════════
    var lib = { page: 1, totalPages: 1, total: 0, search: '', category: '', status: 'active', loading: false, seq: 0 };

    function loadStats() {
        return apiGet('action=stats').then(function (d) {
            if (d.status !== 'success') return;
            $('mzStatTotal').textContent = d.total != null ? d.total : '—';
            $('mzStatActive').textContent = d.active != null ? d.active : '—';
            $('mzStatCategories').textContent = d.categories != null ? d.categories : '—';

            var sel = $('mzCategoryFilter'), cur = sel.value;
            sel.innerHTML = '<option value="">All categories</option>' +
                (d.category_list || []).map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
            sel.value = cur;
            $('mzCategoryOptions').innerHTML = (d.category_list || []).map(function (c) { return '<option value="' + esc(c) + '">'; }).join('');

            populateSectionSelect($('mzAnSection'), d.section_list || []);
        }).catch(function () { /* stats non-critical */ });
    }

    function populateSectionSelect(sel, sections) {
        if (!sel) return;
        var cur = sel.value;
        sel.innerHTML = '<option value="">All sections</option>' +
            sections.map(function (c) { return '<option value="' + esc(c) + '">' + esc(c) + '</option>'; }).join('');
        sel.value = cur;
    }

    function loadList() {
        if (lib.loading) return;
        lib.loading = true;
        var seq = ++lib.seq; // as-you-type: only the latest response renders
        var tb = $('mzTbody');
        tb.innerHTML = skeletonRows(6);
        var q = 'action=list&page=' + encodeURIComponent(lib.page) + '&per_page=' + PAGE_SIZE +
            '&search=' + encodeURIComponent(lib.search) + '&category=' + encodeURIComponent(lib.category) +
            '&status=' + encodeURIComponent(lib.status);
        apiGet(q).then(function (d) {
            lib.loading = false;
            if (seq !== lib.seq) return;
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
            var msg = ((err && err.message) || 'Connection error.') + staleHint(err);
            tb.innerHTML = '<tr><td colspan="6">' + errorState(msg, 'Mezmur.libReload()') + '</td></tr>';
        });
    }

    /** Escape then wrap the user's search tokens in <mark> (Telegram-style). */
    function hi(text) {
        var out = esc(text == null ? '' : text);
        if (!lib.search) return out;
        var toks = lib.search.split(/\s+/).filter(function (t) { return t.length >= 2; });
        if (!toks.length) return out;
        var re = new RegExp('(' + toks.map(function (t) {
            return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }).join('|') + ')', 'gi');
        return out.replace(re, '<mark>$1</mark>');
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
                '<td style="padding:.65rem .75rem;font-weight:600;color:var(--school-text-bright)">' + hi(h.title) +
                (h.snippet ? '<div class="text-dim" style="font-size:.72rem;font-weight:400;margin-top:2px">' + hi(h.snippet) + '</div>' : '') + '</td>' +
                '<td class="amharic" style="padding:.65rem .75rem">' + hi(h.title_am || '—') + '</td>' +
                '<td style="padding:.65rem .75rem">' + (h.category ? '<span class="badge badge-info">' + esc(h.category) + '</span>' : '—') + '</td>' +
                '<td style="padding:.65rem .75rem;color:var(--school-text-dim)">' + hi(h.reference || '—') + '</td>' +
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
        apiGet('action=get&id=' + encodeURIComponent(id)).then(function (d) {
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
        apiPost({
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
            showError($('mzModalError'), ((err && err.message) || 'Connection error.') + staleHint(err));
        });
    }

    function viewHymn(id) {
        apiGet('action=get&id=' + encodeURIComponent(id)).then(function (d) {
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
        apiPost({ action: 'set_status', id: id, status: status }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Action failed.', 'e'); return; }
            window.toast(d.message || 'Done.', 's');
            loadStats(); loadList();
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 2 — ATTENDANCE (section-first, teacher/edu clone)
    // ══════════════════════════════════════════════════════════
    var att = {
        page: 1, totalPages: 1, date: null, section: null, sheet: null,
        marks: {}, notes: {}, order: [], focusIdx: -1, dirty: false,
        packetStatus: '', reviewNote: ''
    };

    // ── section selector ([Section ▾] like teachers' [Class ▾]) ─
    function loadSections() {
        apiGet('action=sections').then(function (d) {
            if (d.status !== 'success') return;
            var sel = $('mzViewSection');
            if (!sel) return;
            var cur = sel.value;
            sel.innerHTML = '<option value="">Select section…</option>' +
                (d.items || []).map(function (s) {
                    var n = (s.members != null ? s.members : s.count);
                    return '<option value="' + esc(s.section) + '">' + esc(s.section) + (n != null ? ' · ' + n : '') + '</option>';
                }).join('');
            if (cur) sel.value = cur;
        }).catch(function () { /* retried on tab re-entry */ });
    }

    // ── days list ─────────────────────────────────────────────
    function loadDays(page) {
        att.page = page || 1;
        var tb = $('mzSessTbody');
        tb.innerHTML = skeletonRows(5);
        var q = 'action=days_list&page=' + att.page + '&per_page=' + PAGE_SIZE +
            '&from=' + encodeURIComponent($('mzSessFrom').value || '') + '&to=' + encodeURIComponent($('mzSessTo').value || '');
        apiGet(q).then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="5">' + errorState(d.message || 'Unable to load days.', 'Mezmur.loadDays()') + '</td></tr>';
                return;
            }
            att.totalPages = d.total_pages || 1;
            renderDayRows(d.items || []);
            var pg = $('mzSessPagination');
            pg.innerHTML = att.totalPages <= 1
                ? '<span class="text-dim">' + (d.total || 0) + ' attendance day' + ((d.total || 0) === 1 ? '' : 's') + '</span><span></span>'
                : '<button class="btn-secondary btn-sm" ' + (att.page <= 1 ? 'disabled' : '') + ' onclick="Mezmur.sessPage(' + (att.page - 1) + ')" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></button>' +
                  '<span class="text-dim">Page ' + att.page + ' of ' + att.totalPages + '</span>' +
                  '<button class="btn-secondary btn-sm" ' + (att.page >= att.totalPages ? 'disabled' : '') + ' onclick="Mezmur.sessPage(' + (att.page + 1) + ')" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></button>';
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="5">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.loadDays()') + '</td></tr>';
        });
    }

    function renderDayRows(items) {
        var tb = $('mzSessTbody');
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="5">' + emptyState('fa-calendar-check', 'No attendance yet',
                'Pick a section and date above and press Take Attendance to record your first day.') + '</td></tr>';
            return;
        }
        tb.innerHTML = items.map(function (d) {
            var rate = d.marked > 0 ? Math.round(d.attended * 1000 / d.marked) / 10 : null;
            return '<tr>' +
                '<td class="nowrap">' + fmtDate(d.attendance_date) + '</td>' +
                '<td>' + d.marked + '</td>' +
                '<td class="text-ok"><b>' + d.attended + '</b></td>' +
                '<td>' + rateBar(rate) + '</td>' +
                '<td class="nowrap"><button class="btn-primary btn-sm" onclick="Mezmur.jumpToDate(\'' + esc(d.attendance_date) + '\')">' +
                '<i class="fa-solid fa-eye"></i> ' + (d.marked > 0 ? 'Review' : 'View') + '</button></td></tr>';
        }).join('');
    }

    // ── open / load sheet (section-scoped) ────────────────────
    // (attendance taking was removed from the department dashboard;
    //  takers record sheets in the mobile app. openDay kept as a thin
    //  alias for viewSheet so old bookmarks/quotes never fatal.)
    function openDay() { viewSheet(); }

    function loadSheet(date, section) {
        $('mzSheetBody').innerHTML = skeletonRows(8);
        $('mzSessionListView').style.display = 'none';
        $('mzSheetView').style.display = 'block';
        renderSheetStatus('');
        apiGet('action=sheet&date=' + encodeURIComponent(date) + '&section=' + encodeURIComponent(section)).then(function (d) {
            if (d.status !== 'success' || !d.members) { window.toast(d.message || 'Unable to load the sheet.', 'e'); closeSheet(true); return; }
            att.sheet = d;
            att.date = date;
            att.section = section;
            att.marks = {};
            att.notes = {};
            att.order = [];
            att.focusIdx = -1;
            att.dirty = false;
            att.packetStatus = d.submission_status || '';
            att.reviewNote = d.review_notes || '';
            d.members.forEach(function (m) {
                att.marks[m.id] = m.mark || '';
                att.notes[m.id] = m.notes || '';
                att.order.push(m.id);
            });
            $('mzSheetTitle').textContent = 'Attendance — ' + section;
            $('mzSheetMeta').textContent = fmtDate(date) + ' • ' + att.order.length + ' members';
            var pm = $('mzPrintMeta');
            if (pm) pm.textContent = section + ' • ' + fmtDate(date) + ' • ' + att.order.length + ' members';
            renderSheet();
            renderSheetStatus(att.packetStatus);
            updateSheetSummary();
        }).catch(function (err) { window.toast(((err && err.message) || 'Connection error.') + staleHint(err), 'e'); closeSheet(true); });
    }


    function renderSheetStatus(status) {
        var el = $('mzSheetStatus');
        if (!el) return;
        if (!status) { el.classList.add('is-hidden'); el.innerHTML = ''; return; }
        var html = '';
        if (status === 'submitted') {
            html = '<div class="mz-banner info"><i class="fa-solid fa-paper-plane"></i><span><b>Submitted.</b> Waiting for the Mezmur department to review. You can still save corrections until it is approved.</span></div>';
        } else if (status === 'approved') {
            html = '<div class="mz-banner ok"><i class="fa-solid fa-circle-check"></i><span><b>Approved</b> by the Mezmur department.</span></div>';
        } else if (status === 'rejected') {
            html = '<div class="mz-banner bad"><i class="fa-solid fa-circle-xmark"></i><span><b>Rejected</b> by the Mezmur department.' + (att.reviewNote ? ' ' + esc(att.reviewNote) : '') + '</span></div>';
        } else if (status === 'revision_needed') {
            html = '<div class="mz-banner warn"><i class="fa-solid fa-rotate-left"></i><span><b>Returned for correction.</b> ' +
                (att.reviewNote ? esc(att.reviewNote) : 'Fix the sheet and submit it again.') + '</span></div>';
        } else if (status === 'draft') {
            html = '<div class="mz-banner info"><i class="fa-solid fa-file-pen"></i><span><b>Draft</b> — saved locally and visible to the department. Submit when the sheet is final.</span></div>';
        }
        el.innerHTML = html;
        el.classList.toggle('is-hidden', !html);
    }

    // ── sheet rendering (batched) ─────────────────────────────
    function renderSheet() {
        if (!att.sheet) return;
        var members = att.sheet.members || [];
        var html = '';
        if (!members.length) {
            html = emptyState('fa-users', 'No active members in this section', 'Assign members to this section to start recording attendance.');
        } else {
            html = '<div class="group"><div class="group-body">';
            members.forEach(function (m) { html += memberRow(m); });
            html += '</div></div>';
        }
        $('mzSheetBody').innerHTML = html;
    }

    /** Read-only roster row: the department INSPECTS recorded sheets;
     *  marking lives exclusively in the mobile app (takers). */
    function memberRow(m) {
        var mark = att.marks[m.id] || '';
        var note = att.notes[m.id] || '';
        var chip = mark
            ? '<span class="rate-chip ' + (mark === 'present' || mark === 'late' ? 'ok' : mark === 'excused' ? 'warn' : 'bad') + '">' +
              mark.charAt(0).toUpperCase() + mark.slice(1) + '</span>'
            : '<span class="text-dim">not marked</span>';
        return '<div class="member-row">' +
            '<div class="member-name">' + esc(m.student_name) + ' ' + esc(m.father_name || '') +
            '<div class="text-dim" style="font-size:.68rem">' + (note ? esc(note) : '') + '</div></div>' +
            chip + '</div>';
    }


    function unmarkedCount() {
        var n = 0;
        Object.keys(att.marks).forEach(function (id) { if (!att.marks[id]) n++; });
        return n;
    }

    function updateSheetSummary() {
        var p = 0, l = 0, a = 0, e = 0, u = 0;
        Object.keys(att.marks).forEach(function (id) {
            if (att.marks[id] === 'present') p++;
            else if (att.marks[id] === 'late') l++;
            else if (att.marks[id] === 'absent') a++;
            else if (att.marks[id] === 'excused') e++;
            else u++;
        });
        var marked = p + l + a + e;
        var rate = marked > 0 ? Math.round((p + l) * 1000 / marked) / 10 : 0;
        $('mzSheetSummary').innerHTML =
            '<b>' + marked + '</b> marked' + (u > 0 ? ' • <span class="text-warn"><b>' + u + '</b> unmarked</span>' : '') +
            ' • <span class="text-ok"><b>' + p + '</b> present</span>' +
            ' • <span class="text-warn"><b>' + l + '</b> late</span>' +
            ' • <span class="text-bad"><b>' + a + '</b> absent</span>' +
            ' • <span style="color:var(--school-info)"><b>' + e + '</b> excused</span>' +
            ' • rate <b>' + rate + '%</b>';
    }



    function closeSheet(force) {
        att.sheet = null;
        att.focusIdx = -1;
        $('mzSheetView').style.display = 'none';
        $('mzSessionListView').style.display = 'block';
    }

    // ── review inbox (department) ─────────────────────────────
    function loadSubmissions() {
        var tb = $('mzSubTbody');
        if (!tb) return;
        tb.innerHTML = skeletonRows(5);
        var q = 'action=submissions_list&status=' + encodeURIComponent($('mzSubStatus').value || 'attention');
        apiGet(q).then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="7">' + errorState(d.message || 'Unable to load the queue.', 'Mezmur.loadSubmissions()') + '</td></tr>';
                return;
            }
            var items = d.items || [];
            if (!items.length) {
                tb.innerHTML = '<tr><td colspan="7">' + emptyState('fa-inbox', 'Nothing to review',
                    'When a taker saves or submits a section sheet, the packet lands here.') + '</td></tr>';
                return;
            }
            tb.innerHTML = items.map(function (p) {
                var open = p.status === 'submitted' || p.status === 'revision_needed' || p.status === 'draft';
                return '<tr>' +
                    '<td class="nowrap">' + fmtDate(p.attendance_date) + '</td>' +
                    '<td class="amharic">' + esc(p.section) + '</td>' +
                    '<td>' + esc(p.taker_name || '—') + '</td>' +
                    '<td>' + p.member_count + '</td>' +
                    '<td>' + statusChip(p.status) + '</td>' +
                    '<td class="nowrap text-dim">' + fmtDate(p.updated_at) + '</td>' +
                    '<td class="nowrap">' +
                    '<button class="btn-secondary btn-sm" title="View rows" onclick="Mezmur.viewPacket(' + p.id + ')"><i class="fa-solid fa-eye"></i></button> ' +
                    (open
                        ? '<button class="btn-primary btn-sm" onclick="Mezmur.openReview(' + p.id + ')"><i class="fa-solid fa-gavel"></i> Review</button>'
                        : '<button class="btn-secondary btn-sm" onclick="Mezmur.openReview(' + p.id + ')"><i class="fa-solid fa-gavel"></i> Re-review</button>') +
                    '</td></tr>';
            }).join('');
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="7">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.loadSubmissions()') + '</td></tr>';
        });
    }

    var _reviewMeta = {};
    function openReview(id) {
        apiGet('action=submission_detail&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load the packet.', 'e'); return; }
            var p = d.item;
            _reviewMeta = p;
            $('mzRvId').value = p.id;
            $('mzRvMeta').innerHTML =
                '<span class="badge badge-info">' + fmtDate(p.attendance_date) + '</span>' +
                '<span class="badge badge-active amharic">' + esc(p.section) + '</span>' +
                '<span class="text-dim">by ' + esc(p.taker_name || '—') + '</span>' +
                statusChip(p.status);
            // A previously returned packet keeps its decision context.
            $('mzRvDecision').value = p.status === 'submitted' ? 'approved' : 'revision_needed';
            $('mzRvNotes').value = '';
            showError($('mzRvError'), '');
            openModalF('mzReviewModal', '#mzRvDecision');
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    function submitReview() {
        var id = parseInt($('mzRvId').value, 10);
        var decision = $('mzRvDecision').value;
        var notes = $('mzRvNotes').value.trim();
        if (!id) return;
        if (decision !== 'approved' && notes.length < 3) {
            showError($('mzRvError'), 'Write a short reason so the taker knows what to fix.');
            $('mzRvNotes').focus();
            return;
        }
        var btn = $('mzRvSaveBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Recording…';
        apiPost({ action: 'submission_review', submission_id: id, new_status: decision, notes: notes }).then(function (d) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-gavel"></i> Record Decision';
            if (d.status !== 'success') { showError($('mzRvError'), d.message || 'Unable to record the decision.'); return; }
            closeModalF('mzReviewModal');
            window.toast(d.message || 'Decision recorded.', 's');
            loadSubmissions();
        }).catch(function (err) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-gavel"></i> Record Decision';
            showError($('mzRvError'), (err && err.message) || 'Connection error.');
        });
    }

    function viewPacket(id) {
        apiGet('action=submission_detail&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load the packet.', 'e'); return; }
            var p = d.item;
            $('mzPacketTitle').innerHTML = '<i class="fa-solid fa-list-check"></i> ' + esc(p.section) + ' — ' + fmtDate(p.attendance_date);
            $('mzPacketMeta').innerHTML =
                statusChip(p.status) +
                '<span class="text-dim">by ' + esc(p.taker_name || '—') + '</span>' +
                '<span class="text-dim">' + p.member_count + ' members • ' +
                p.present_count + ' present • ' + p.late_count + ' late • ' +
                p.absent_count + ' absent • ' + p.excused_count + ' excused</span>';
            var rows = p.rows || [];
            var body = '<div class="table-shell"><table><thead><tr><th>#</th><th>Member</th><th>Code</th><th>Status</th><th>Note</th></tr></thead><tbody>';
            if (!rows.length) {
                body += '<tr><td colspan="5">' + emptyState('fa-users', 'No rows', 'No attendance rows are attached to this packet.') + '</td></tr>';
            } else {
                body += rows.map(function (r, i) {
                    return '<tr><td class="text-dim">' + (i + 1) + '</td>' +
                        '<td><b>' + esc(r.student_name) + '</b> ' + esc(r.father_name || '') + '</td>' +
                        '<td class="text-dim">' + esc(r.member_code || '—') + '</td>' +
                        '<td>' + esc(r.status) + '</td>' +
                        '<td class="text-dim">' + esc(r.notes || '—') + '</td></tr>';
                }).join('');
            }
            body += '</tbody></table></div>';
            $('mzPacketBody').innerHTML = body;
            openModalF('mzPacketModal', null);
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    // ══════════════════════════════════════════════════════════
    // MODULE 3 — ANALYTICS (no program filter — raw attendance)
    // ══════════════════════════════════════════════════════════
    var an = { page: 1, sort: 'rate', dir: 'desc', lastMembers: [], sessionsHeld: 0 };

    function ensureAnalyticsSections() {
        var sel = $('mzAnSection');
        if (sel && sel.options.length <= 1) loadStats();
    }

    function anParams(page) {
        return 'action=analytics_members&page=' + (page || an.page) + '&per_page=' + PAGE_SIZE +
            '&sort=' + encodeURIComponent(an.sort) + '&dir=' + an.dir +
            '&section=' + encodeURIComponent($('mzAnSection').value || '') +
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

        var pMembers = apiGet(anParams(an.page));
        var pSections = apiGet(anParams(1).replace('action=analytics_members', 'action=analytics_sections'));
        var pTrends = apiGet('action=analytics_trends' +
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
        apiGet('action=takers_list').then(function (d) {
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
        window.api.post('/admin/backend/user-save.php', {
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
        window.api.post('/admin/backend/user-toggle.php', { user_id: id, action: 'toggle_status' }).then(function (d) {
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
            debounce = setTimeout(function () { lib.search = v.trim(); lib.page = 1; loadList(); }, 160);
        });
        $('mzCategoryFilter').addEventListener('change', function () { lib.category = this.value; lib.page = 1; loadList(); });
        $('mzStatusFilter').addEventListener('change', function () { lib.status = this.value; lib.page = 1; loadList(); });


        // Lazy loading: fetch only what the user is actually looking at.
        // core.js may restore the last-used section before this runs;
        // whichever section is active now gets its (single) load.
        var active = document.querySelector('.school-section.active');
        var name = active ? (active.getAttribute('data-section') || 'overview') : 'overview';
        loadTab(name);
    });

    // Public API (inline onclick handlers in the shell)
    window.Mezmur = {
        // overview
        loadOverview: loadOverview,
        migrateSchema: migrateSchema, reloadTakers: loadTakers, libReload: loadList,
        quickTake: quickTake, quickReview: quickReview, quickLibrary: quickLibrary, quickAnalytics: quickAnalytics, quickTakers: quickTakers,
        gotoAttendance: gotoAttendance, jumpToDate: jumpToDate,
        // library
        openAdd: openAdd, openEdit: openEdit, save: saveHymn, view: viewHymn, setStatus: setHymnStatus,
        closeModal: function () { closeModalF('mzHymnModal'); },
        closeView: function () { closeModalF('mzViewModal'); },
        libPage: function (p) { if (p >= 1 && p <= lib.totalPages) { lib.page = p; loadList(); } },
        // attendance
        loadDays: function () { loadDays(1); },
        sessPage: function (p) { loadDays(p); },
        openDay: openDay, viewSheet: viewSheet, quickReview: quickReview,
        closeSheet: function () { closeSheet(false); },
        // review inbox
        loadSubmissions: loadSubmissions, openReview: openReview, submitReview: submitReview, viewPacket: viewPacket,
        // analytics
        runAnalytics: runAnalytics, sortBy: sortBy, exportCsv: exportCsv,
        // takers
        openTakerModal: openTakerModal, createTaker: createTaker, toggleTaker: toggleTaker
    };
})();
