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

    // ── in-system confirm dialog (P31: the system never uses browser
    //    popups — confirm/prompt/alert are replaced by styled UI) ──
    var sysDialogCb = null;
    function sysConfirm(body, onYes) {
        var el = $('mzSysDialog');
        if (!el) { if (onYes) onYes(); return; }
        $('mzSysDialogBody').textContent = body;
        sysDialogCb = onYes || null;
        openModalF('mzSysDialog');
    }
    function initSysDialog() {
        var el = $('mzSysDialog');
        if (!el || el.dataset.p31) return;
        el.dataset.p31 = '1';
        $('mzSysDialogYes').addEventListener('click', function () {
            var cb = sysDialogCb; sysDialogCb = null;
            closeModalF('mzSysDialog');
            if (cb) cb();
        });
        $('mzSysDialogNo').addEventListener('click', function () {
            sysDialogCb = null;
            closeModalF('mzSysDialog');
        });
    }

    // ── schema reconciliation (one-click migration) ──────────
    function migrateSchema() {
        sysConfirm('Align the mezmur database schema with the current code? This is safe to run at any time.', migrateRun);
    }
    function migrateRun() {
        sysConfirm('Align the mezmur database schema with the current code? This is safe to run at any time.', function () { migrateRun(); });
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
                // Every mutation (save, set_status, catalog, migrate,
                // submission review) lands here — drop cached list results.
                if (d && d.status === 'success') listCache = {};
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
        } else if (name === 'catalog') {
            loadCatalog();
            renderCatalogManager();
        } else if (name === 'attendance' && !tabLoaded.attendance) {
            tabLoaded.attendance = true;
            loadSections();
            loadSubSectionOptions();
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
    var lib = { page: 1, totalPages: 1, total: 0, search: '', category: '', status: 'active', length: '', language: '', categoryId: 0, zemarianId: 0, loading: false, seq: 0 };

    /** Keystroke cache (P22): identical search queries are answered from
     *  memory instead of re-hitting the server; every successful mutation
     *  (all of which travel through apiPost) drops it. Bounded to 10 keys. */
    var listCache = {};
    function cachePut(key, data) {
        var keys = Object.keys(listCache);
        if (keys.length >= 10) delete listCache[keys[0]];
        listCache[key] = data;
    }

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
            var catOpts = $('mzCategoryOptions');
            if (catOpts) catOpts.innerHTML = (d.category_list || []).map(function (c) { return '<option value="' + esc(c) + '">'; }).join('');

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
        var seq = ++lib.seq; // as-you-type: only the latest response renders
        var tb = $('mzTbody');
        var q = 'action=list&page=' + encodeURIComponent(lib.page) + '&per_page=' + PAGE_SIZE +
            '&search=' + encodeURIComponent(lib.search) + '&category=' + encodeURIComponent(lib.category) +
            '&length=' + encodeURIComponent(lib.length) + '&language=' + encodeURIComponent(lib.language) +
            '&category_id=' + encodeURIComponent(lib.categoryId || '') + '&zemarian_id=' + encodeURIComponent(lib.zemarianId || '') +
            '&status=' + encodeURIComponent(lib.status);
        // Cached answer (same query typed again): render immediately, no
        // server round trip — the seq guard above still applies.
        if (listCache[q]) {
            applyList(seq, tb, listCache[q]);
            return;
        }
        tb.innerHTML = skeletonRows(6);
        apiGet(q).then(function (d) {
            if (seq === lib.seq && d.status === 'success') cachePut(q, d);
            applyList(seq, tb, d);
        }).catch(function (err) {
            if (seq !== lib.seq) return;
            var msg = ((err && err.message) || 'Connection error.') + staleHint(err);
            tb.innerHTML = '<tr><td colspan="6">' + errorState(msg, 'Mezmur.libReload()') + '</td></tr>';
        });
    }

    function applyList(seq, tb, d) {
        if (seq !== lib.seq) return;
        if (d.status !== 'success') {
            tb.innerHTML = '<tr><td colspan="6">' + errorState(d.message || 'Unable to load hymns.', 'Mezmur.libReload()') + '</td></tr>';
            return;
        }
        lib.totalPages = d.total_pages || 1;
        lib.total = d.total || 0;
        renderHymnRows(d.items || []);
        renderLibPagination();
    }

    /** Escape then wrap the user's search tokens in <mark>. */
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

    /** MZ-15: render every category the hymn carries (server attaches the
     *  join rows); the legacy single string stays as the fallback. */
    function catBadges(h) {
        var cats = h.categories || [];
        if (cats.length) {
            var extra = cats.length > 3 ? ' <span class="text-dim" style="font-size:.7rem">+' + (cats.length - 3) + '</span>' : '';
            return cats.slice(0, 3).map(function (c) {
                return '<span class="badge badge-info">' + esc(c.name) + '</span>';
            }).join(' ') + extra;
        }
        return h.category ? '<span class="badge badge-info">' + esc(h.category) + '</span>' : '—';
    }

    /** MZ-12: reset every library filter and reload (empty-state recovery). */
    function clearFilters() {
        lib.search = ''; lib.category = ''; lib.length = ''; lib.language = '';
        lib.categoryId = 0; lib.zemarianId = 0; lib.page = 1;
        ['mzSearch', 'mzCategoryFilter', 'mzLengthFilter', 'mzLanguageFilter'].forEach(function (id) {
            var el = $(id); if (el) el.value = '';
        });
        loadList();
    }

    function renderHymnRows(items) {
        var tb = $('mzTbody');
        if (!items.length) {
            // MZ-12: distinguish "filtered to nothing" from "library empty"
            // (Carbon/NNG empty-state pattern: reflect what was applied and
            // offer a recovery action). Every active filter counts.
            var filtered = !!(lib.search || lib.category || lib.length || lib.language || lib.categoryId || lib.zemarianId || lib.status === 'archived');
            tb.innerHTML = '<tr><td colspan="6">' + (filtered
                ? emptyState('fa-magnifying-glass', 'No matches', 'No hymns match your current search or filters.',
                    '<button class="btn-secondary btn-sm" onclick="Mezmur.clearFilters()"><i class="fa-solid fa-filter-circle-xmark"></i> Clear filters</button>')
                : emptyState('fa-music', 'No hymns yet', 'Start the library by adding the first hymn.',
                    '<button class="btn-primary btn-sm" onclick="Mezmur.openAdd()"><i class="fa-solid fa-plus"></i> Add Hymn</button>')) + '</td></tr>';
            return;
        }
        tb.innerHTML = items.map(function (h) {
            var archived = h.status === 'archived';
            return '<tr style="border-top:1px solid var(--school-border,rgba(255,255,255,.06))' + (archived ? ';opacity:.55' : '') + '">' +
                '<td style="padding:.65rem .75rem;font-weight:600;color:var(--school-text-bright)">' + hi(h.title) +
                (h.snippet ? '<div class="text-dim" style="font-size:.72rem;font-weight:400;margin-top:2px">' + hi(h.snippet) + '</div>' : '') + '</td>' +
                '<td style="padding:.65rem .75rem">' + catBadges(h) + '</td>' +
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

    var catalog = { categories: [], zemarians: [], tab: 'categories' };
    var browseMode = 'all';

    function loadCatalog() {
        apiGet('action=categories').then(function (d) {
            if (d && d.status === 'success') catalog.categories = d.items || [];
            renderCatalogBoxes(); renderCatalogList(); populateCategoryFilter();
        }).catch(function () {});
        apiGet('action=zemarians').then(function (d) {
            if (d && d.status === 'success') catalog.zemarians = d.items || [];
            renderCatalogBoxes(); renderCatalogList();
        }).catch(function () {});
    }

    /** P30: the library filter follows the two-level taxonomy — mains
     *  group their subs (optgroup); picking a MAIN filters roll-up (the
     *  server matches the main + all of its subs). */
    function populateCategoryFilter() {
        var sel = $('mzCategoryFilter');
        if (!sel) return;
        var cur = sel.value;
        var cats = catalog.categories || [];
        var mains = cats.filter(function (c) { return c.parent_id == null && Number(c.is_active) === 1; });
        var html = '<option value="">All categories</option>';
        mains.forEach(function (m) {
            var subs = cats.filter(function (c) {
                return c.parent_id != null && c.parent_id === m.id && Number(c.is_active) === 1;
            });
            if (subs.length) {
                html += '<optgroup label="' + esc(m.name) + ' (' + (m.hymn_count_total || 0) + ')">';
                html += '<option value="' + Number(m.id) + '">All of ' + esc(m.name) + '</option>';
                subs.forEach(function (sb) {
                    html += '<option value="' + Number(sb.id) + '">' + esc(sb.name) + ' (' + (sb.hymn_count || 0) + ')</option>';
                });
                html += '</optgroup>';
            } else {
                html += '<option value="' + Number(m.id) + '">' + esc(m.name) + ' (' + (m.hymn_count_total || m.hymn_count || 0) + ')</option>';
            }
        });
        sel.innerHTML = html;
        sel.value = cur;
        if (sel.value !== cur) { sel.value = ''; lib.categoryId = 0; }
    }

    /** P23: hidden catalog entries stay pickable (a hymn may legitimately
     *  carry one) but are labelled — web and mobile curators now see the
     *  same truth instead of an invisible difference. */
    function catLabel(i) {
        var hidden = Number(i.is_active) !== 1;
        return esc(i.name) + (hidden ? ' <span class="text-dim" style="font-size:.68rem">(hidden)</span>' : '');
    }

    // ── P30 taxonomy pickers (dropdown panels; two-level for categories) ──
    var PICK_GRADIENTS = [
        ['#5A1212', '#D4AF37'], ['#4f46e5', '#7c3aed'], ['#0ea5e9', '#2563eb'],
        ['#059669', '#0d9488'], ['#d97706', '#dc2626'], ['#db2777', '#9333ea']
    ];
    function hashCode(str) {
        var h = 0;
        for (var i = 0; i < str.length; i++) { h = ((h << 5) - h + str.charCodeAt(i)) | 0; }
        return Math.abs(h);
    }
    function thumbHtml(item) {
        var img = item.image_url ? ' style="background-image:url(\'' + item.image_url + '\')"' : '';
        var label = item.image_url ? '' : esc((item.name || '?').trim().charAt(0));
        if (!img) {
            var g = PICK_GRADIENTS[hashCode(String(item.name || '')) % PICK_GRADIENTS.length];
            img = ' style="background:linear-gradient(135deg,' + g[0] + ',' + g[1] + ')"';
        }
        return '<span class="mz-thumb"' + img + ' aria-hidden="true">' + label + '</span>';
    }
    function pickItemHtml(box, item, checked) {
        var count = item.parent_id == null ? (item.hymn_count_total || 0) : (item.hymn_count || 0);
        return '<label class="mz-pick-item"><input type="checkbox" value="' + Number(item.id) + '"' +
            (checked ? ' checked' : '') + '> ' + thumbHtml(item) +
            '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(item.name) + '</span>' +
            '<span class="text-dim" style="font-size:.7rem">' + count + '</span></label>';
    }
    function renderCatalogBoxes(selCats, selZem) {
        var zbox = $('mzZemariansBox');
        if (zbox) {
            var zems = catalog.zemarians || [];
            zbox.innerHTML = zems.map(function (z) {
                var checked = selZem && selZem.some(function (x) { return String(x.id) === String(z.id); });
                return pickItemHtml(zbox, z, checked);
            }).join('') || '<span class="text-dim" style="font-size:.75rem">No singers yet — add one first.</span>';
            updatePickBtn('mzZemPickBtn', 'mzZemariansBox', 'Select singers…');
        }
    }

    function updatePickBtn(btnId, boxId, placeholder) {
        var btn = $(btnId), box = $(boxId);
        if (!btn || !box) return;
        var picked = [];
        box.querySelectorAll('input:checked').forEach(function (cb) {
            var item = cb.closest('.mz-pick-item');
            var name = item ? item.querySelector('span[style*="flex"]') : null;
            picked.push(name ? name.textContent.trim() : '#' + cb.value);
        });
        btn.innerHTML = '';
        btn.appendChild(document.createTextNode(picked.length
            ? picked.slice(0, 3).join(', ') + (picked.length > 3 ? ' +' + (picked.length - 3) : '')
            : placeholder));
    }
    function initPickPanels() {
        [['mzZemPickBtn', 'mzZemariansBox']].forEach(function (pair) {
            var btn = $(pair[0]), box = $(pair[1]);
            if (!btn || !box || btn.dataset.p30) return;
            btn.dataset.p30 = '1';
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                box.classList.toggle('is-hidden');
            });
            box.addEventListener('click', function (e) {
                e.stopPropagation();
                setTimeout(function () { updatePickBtn(pair[0], pair[1], pair[0] === 'mzCatPickBtn' ? 'Select categories…' : 'Select singers…'); }, 0);
            });
        });
        document.addEventListener('click', function () {
            ['mzCategoriesBox', 'mzZemariansBox'].forEach(function (id) {
                var el = $(id); if (el) el.classList.add('is-hidden');
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            ['mzCategoriesBox', 'mzZemariansBox'].forEach(function (id) {
                var el = $(id); if (el) el.classList.add('is-hidden');
            });
        });
    }

    function checkedIds(boxId) {
        var ids = [];
        var box = document.getElementById(boxId);
        if (!box) return ids;
        box.querySelectorAll('input:checked').forEach(function (cb) {
            var id = parseInt(cb.value, 10);
            if (id > 0) ids.push(id);
        });
        return ids;
    }

    // ── browse tabs (All / Categories / Zemarians) ──
    function tab(mode) {
        browseMode = mode;
        document.querySelectorAll('.mz-tab').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-tab') === mode);
        });
        var browse = $('mzBrowse');
        if (browse) browse.classList.toggle('is-hidden', mode === 'all');
        if (mode === 'all') { lib.categoryId = 0; lib.zemarianId = 0; loadList(); }
        else { renderBrowse(); }
    }

    function renderBrowse() {
        var list = $('mzBrowseList');
        if (browseMode === 'categories') {
            lib.zemarianId = 0;
            list.innerHTML = (catalog.categories || []).map(function (c) {
                return '<button class="btn-secondary btn-sm" onclick="Mezmur.browseCategory(' + c.id + ')">' + esc(c.name) + '</button>';
            }).join('') || '<span class="text-dim" style="font-size:.8rem">No categories yet.</span>';
        } else {
            lib.categoryId = 0;
            list.innerHTML = (catalog.zemarians || []).map(function (z) {
                return '<button class="btn-secondary btn-sm" onclick="Mezmur.browseZemarian(' + z.id + ')">' + esc(z.name) + '</button>';
            }).join('') || '<span class="text-dim" style="font-size:.8rem">No singers yet.</span>';
        }
    }

    function browseCategory(id) { lib.categoryId = id; lib.zemarianId = 0; lib.page = 1; loadList(); }
    function browseZemarian(id) { lib.zemarianId = id; lib.categoryId = 0; lib.page = 1; loadList(); }

    // ── standalone catalog manager (P31: its own section; every edit
    //    is INLINE — no popups, no browser dialogs) ──
    var mgr = { tab: 'categories', edit: null, uploading: 0 };

    function openCatalog(kind) {
        // navigate to the standalone Catalog section (and close any open
        // hymn form — the curator is leaving to manage the taxonomy)
        closeModalF('mzHymnModal');
        var nav = document.querySelector('.school-nav-link[data-section="catalog"]');
        if (nav) nav.click(); else loadTab('catalog');
        mgrTab(kind || 'categories');
    }
    function mgrTab(kind) {
        mgr.tab = kind;
        var c = $('mzMgrCatTabBtn'), z = $('mzMgrZemTabBtn');
        if (c) c.classList.toggle('active', kind === 'categories');
        if (z) z.classList.toggle('active', kind === 'zemarians');
        $('mzMgrCats').classList.toggle('is-hidden', kind !== 'categories');
        $('mzMgrZems').classList.toggle('is-hidden', kind === 'categories');
        renderCatalogManager();
    }

    function mgrCats() { return (catalog.categories || []); }
    function mgrMains() { return mgrCats().filter(function (c) { return c.parent_id == null; }); }
    function mgrSubsOf(id) { return mgrCats().filter(function (c) { return c.parent_id != null && c.parent_id === id; }); }

    function mgrThumb(item, cls) {
        var img = item.image_url ? ' style="background-image:url(\'' + item.image_url + '\')"' : '';
        var label = item.image_url ? '' : esc((item.name || '?').trim().charAt(0));
        if (!img) {
            var g = PICK_GRADIENTS[hashCode(String(item.name || '')) % PICK_GRADIENTS.length];
            img = ' style="background:linear-gradient(135deg,' + g[0] + ',' + g[1] + ')"';
        }
        return '<span class="' + (cls || 'mz-thumb') + '"' + img + ' aria-hidden="true">' + label + '</span>';
    }

    function mgrNameCell(item, editing, isSub) {
        if (editing) {
            // Categories carry a single name (no translation column);
            // only singers have the Amharic name field.
            return '<div class="mz-mgr-edit">' +
                '<input id="mzMgrEditName" class="school-input" maxlength="50" value="' + esc(item.name) + '">' +
                '<button class="btn-primary btn-sm" onclick="Mezmur.mgrSave(' + item.id + ')"><i class="fa-solid fa-check"></i></button> ' +
                '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrCancel()"><i class="fa-solid fa-xmark"></i></button></div>';
        }
        var hidden = Number(item.is_active) !== 1;
        return '<div class="mz-mgr-name">' + mgrThumb(item, 'mz-mgr-thumb') +
            '<span class="mz-mgr-namelabel"' + (hidden ? ' style="opacity:.5"' : '') + '>' + esc(item.name) +
            (hidden ? ' <span class="text-dim" style="font-size:.68rem">(hidden)</span>' : '') + '</span></div>';
    }

    function renderCatalogManager() {
        var rows = $('mzMgrCatRows');
        if (rows) {
            var html = '';
            mgrMains().forEach(function (m) {
                var editing = mgr.edit === 'cat:' + m.id;
                var count = m.hymn_count_total || 0;
                html += '<tr' + (mgr.uploading === m.id ? ' class="mz-mgr-busy"' : '') + '>' +
                    '<td>' + mgrThumb(m, 'mz-mgr-thumb') + '</td>' +
                    '<td>' + mgrNameCell(m, editing, false) + '</td>' +
                    '<td class="text-dim">' + count + '</td>' +
                    '<td>' + mgrSortButtons('cat', m.id) + '</td>' +
                    '<td>' + mgrCatActions(m) + '</td></tr>';
                mgrSubsOf(m.id).forEach(function (sb) {
                    var editingSub = mgr.edit === 'cat:' + sb.id;
                    html += '<tr class="mz-mgr-sub"' + (mgr.uploading === sb.id ? ' style="opacity:.45"' : '') + '>' +
                        '<td>' + mgrThumb(sb, 'mz-mgr-thumb') + '</td>' +
                        '<td>' + mgrNameCell(sb, editingSub, true) + '</td>' +
                        '<td class="text-dim">' + (sb.hymn_count || 0) + '</td>' +
                        '<td>' + mgrSortButtons('sub', sb.id) + '</td>' +
                        '<td>' + mgrCatActions(sb, m.id) + '</td></tr>';
                });
                // inline "add sub" row (opened per main)
                if (mgr.edit === 'addsub:' + m.id) {
                    html += '<tr class="mz-mgr-sub"><td></td><td colspan="4"><div class="mz-mgr-edit">' +
                        '<input id="mzMgrSubName" class="school-input" maxlength="50" placeholder="New sub-category name…">' +
                        '<button class="btn-primary btn-sm" onclick="Mezmur.mgrAddSub(' + m.id + ')"><i class="fa-solid fa-check"></i> Add</button> ' +
                        '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrCancel()"><i class="fa-solid fa-xmark"></i></button></div></td></tr>';
                }
            });
            rows.innerHTML = html || '<tr><td colspan="5" class="text-dim" style="padding:.9rem .75rem">No categories yet — add the first main category above.</td></tr>';
            afterMgrRender('mzMgrEditName');
        }
        var zrows = $('mzMgrZemRows');
        if (zrows) {
            zrows.innerHTML = (catalog.zemarians || []).map(function (z) {
                var editing = mgr.edit === 'zem:' + z.id;
                var hidden = Number(z.is_active) !== 1;
                var nameCell = editing
                    ? '<div class="mz-mgr-edit">' +
                      '<input id="mzMgrEditName" class="school-input" maxlength="100" value="' + esc(z.name) + '">' +
                      '<input id="mzMgrEditNameAm" class="school-input amharic" maxlength="100" placeholder="በአማርኛ" style="max-width:150px" value="' + esc(z.name_am || '') + '">' +
                      '<button class="btn-primary btn-sm" onclick="Mezmur.mgrSave(' + z.id + ')"><i class="fa-solid fa-check"></i></button> ' +
                      '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrCancel()"><i class="fa-solid fa-xmark"></i></button></div>'
                    : '<span style="' + (hidden ? 'opacity:.5;' : '') + 'font-size:.84rem;font-weight:600">' + esc(z.name) +
                      (hidden ? ' <span class="text-dim" style="font-size:.68rem">(hidden)</span>' : '') + '</span>';
                return '<tr><td>' + nameCell + '</td>' +
                    '<td class="amharic">' + esc(z.name_am || '—') + '</td>' +
                    '<td class="text-dim">' + (z.hymn_count || 0) + '</td>' +
                    '<td>' +
                    (editing ? '' :
                    '<button class="btn-secondary btn-sm" title="Rename" onclick="Mezmur.mgrEdit(' + z.id + ')"><i class="fa-solid fa-pen"></i></button> ' +
                    '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrToggle(' + z.id + ')">' + (hidden ? 'Show' : 'Hide') + '</button>') +
                    '</td></tr>';
            }).join('') || '<tr><td colspan="4" class="text-dim" style="padding:.9rem .75rem">No singers yet — add the first one above.</td></tr>';
            afterMgrRender('mzMgrEditName');
        }
    }
    function afterMgrRender(inputId) {
        var el = $(inputId);
        if (el) { el.focus(); el.select(); }
    }
    function mgrSortButtons(kind, id) {
        return '<div style="display:flex;gap:.25rem">' +
            '<button class="btn-secondary btn-sm" title="Move up" onclick="Mezmur.mgrSort(' + id + ',-1)"><i class="fa-solid fa-arrow-up"></i></button> ' +
            '<button class="btn-secondary btn-sm" title="Move down" onclick="Mezmur.mgrSort(' + id + ',1)"><i class="fa-solid fa-arrow-down"></i></button></div>';
    }
    function mgrCatActions(item, mainId) {
        if (mgr.edit === 'cat:' + item.id) return '';
        var hidden = Number(item.is_active) !== 1;
        var isMain = item.parent_id == null;
        return '<div style="display:flex;gap:.25rem;flex-wrap:wrap">' +
            '<button class="btn-secondary btn-sm" title="Rename" onclick="Mezmur.mgrEdit(' + item.id + ')"><i class="fa-solid fa-pen"></i></button> ' +
            (isMain
                ? '<button class="btn-secondary btn-sm" title="Set cover image" onclick="Mezmur.mgrImage(' + item.id + ')"><i class="fa-solid fa-image"></i></button> ' +
                  (mainId !== undefined ? '' : '<button class="btn-secondary btn-sm" title="Add sub-category" onclick="Mezmur.mgrAddSubOpen(' + item.id + ')"><i class="fa-solid fa-plus"></i> Sub</button> ')
                : '<button class="btn-secondary btn-sm" title="Set cover image" onclick="Mezmur.mgrImage(' + item.id + ')"><i class="fa-solid fa-image"></i></button> ') +
            '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrToggle(' + item.id + ')">' + (hidden ? 'Show' : 'Hide') + '</button></div>';
    }
    function mgrEdit(id) { mgr.edit = 'cat:' + id; if (mgrIsZem(id)) mgr.edit = 'zem:' + id; renderCatalogManager(); }
    function mgrIsZem(id) {
        return (catalog.zemarians || []).some(function (z) { return Number(z.id) === Number(id); }) &&
               !mgrCats().some(function (c) { return Number(c.id) === Number(id); });
    }
    function mgrCancel() { mgr.edit = null; renderCatalogManager(); }
    function mgrAddSubOpen(mainId) { mgr.edit = 'addsub:' + mainId; renderCatalogManager(); }
    function mgrSave(id) {
        var name = ($('mzMgrEditName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        var isZem = mgr.edit === 'zem:' + id;
        var payload = isZem
            ? { action: 'save_zemarian', id: id, name: name, name_am: ((($('mzMgrEditNameAm') || {}).value) || '').trim() }
            : { action: 'save_category', id: id, name: name, parent_id: mgrParentOf(id) };
        apiPost(payload).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Rename failed.', 'e'); return; }
            mgr.edit = null;
            loadCatalog();
        }).catch(function () {});
    }
    function mgrParentOf(id) {
        var found = mgrCats().filter(function (c) { return Number(c.id) === Number(id); })[0];
        return found && found.parent_id != null ? found.parent_id : '';
    }
    function mgrAddMain() {
        var name = ($('mzMgrMainName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        apiPost({ action: 'save_category', name: name }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Failed.', 'e'); return; }
            $('mzMgrMainName').value = '';
            loadCatalog();
        }).catch(function () {});
    }
    function mgrAddSub(mainId) {
        var name = ($('mzMgrSubName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        apiPost({ action: 'save_category', name: name, parent_id: mainId }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Failed.', 'e'); return; }
            mgr.edit = null;
            loadCatalog();
        }).catch(function () {});
    }
    function mgrAddZem() {
        var name = ($('mzMgrZemName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        apiPost({ action: 'save_zemarian', name: name, name_am: (($('mzMgrZemNameAm') || {}).value || '').trim() }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Failed.', 'e'); return; }
            $('mzMgrZemName').value = ''; $('mzMgrZemNameAm').value = '';
            loadCatalog();
        }).catch(function () {});
    }
    function mgrToggle(id) {
        var isZem = (catalog.zemarians || []).some(function (z) { return Number(z.id) === Number(id); }) &&
                    !mgrCats().some(function (c) { return Number(c.id) === Number(id); });
        var list = isZem ? catalog.zemarians : mgrCats();
        var found = (list || []).filter(function (i) { return Number(i.id) === Number(id); })[0];
        var active = found ? Number(found.is_active) === 1 : true;
        var payload = isZem
            ? { action: 'zemarian_status', id: id, active: active ? 0 : 1 }
            : { action: 'category_status', id: id, active: active ? 0 : 1 };
        apiPost(payload).then(function () { loadCatalog(); }).catch(function () {});
    }
    function mgrSort(id, dir) {
        // swap sort_order with the adjacent sibling in the same level
        var found = mgrCats().filter(function (c) { return Number(c.id) === Number(id); })[0];
        if (!found) return;
        var siblings = found.parent_id == null ? mgrMains() : mgrSubsOf(found.parent_id);
        var idx = siblings.findIndex(function (c) { return Number(c.id) === Number(id); });
        var other = siblings[idx + dir];
        if (!other) { window.toast('Already at the ' + (dir < 0 ? 'top' : 'bottom') + '.', 'i'); return; }
        var a = found.sort_order || 0, b = other.sort_order || 0;
        var done = 0;
        function fin() { done++; if (done >= 2) loadCatalog(); }
        apiPost({ action: 'save_category', id: found.id, name: found.name, parent_id: found.parent_id == null ? '' : found.parent_id, sort_order: b === a ? a + dir : b }).then(fin).catch(fin);
        apiPost({ action: 'save_category', id: other.id, name: other.name, parent_id: other.parent_id == null ? '' : other.parent_id, sort_order: a === b ? b - dir : a }).then(fin).catch(fin);
    }
    function mgrImage(id) {
        var input = $('mzMgrFile');
        if (!input) return;
        input.value = '';
        input.onchange = function () {
            var file = input.files && input.files[0];
            if (!file) return;
            mgr.uploading = id;
            renderCatalogManager();
            var fd = new FormData();
            fd.append('id', id);
            fd.append('image', file);
            apiPost(fd).then(function (d) {
                mgr.uploading = 0;
                if (d.status !== 'success') { window.toast(d.message || 'Upload failed.', 'e'); renderCatalogManager(); return; }
                window.toast(d.message || 'Image updated.', 's');
                loadCatalog();
            }).catch(function () { mgr.uploading = 0; renderCatalogManager(); });
        };
        input.click();
    }

    // ── hymn form: cascading category -> sub-category selects ──
    function populateHymnCats(selectedCatId) {
        var mainSel = $('mzHymnMainCat'), subSel = $('mzHymnSubCat');
        if (!mainSel || !subSel) return;
        var mains = mgrMains();
        var keepMain = mainSel.value;
        mainSel.innerHTML = '<option value="">— Select category —</option>' + mains.map(function (m) {
            return '<option value="' + Number(m.id) + '">' + esc(m.name) + '</option>';
        }).join('');
        // preselect from the edited hymn's link (sub -> its parent; main -> itself)
        if (selectedCatId) {
            var linked = mgrCats().filter(function (c) { return Number(c.id) === Number(selectedCatId); })[0];
            if (linked) {
                keepMain = linked.parent_id != null ? String(linked.parent_id) : String(linked.id);
            }
        }
        if (keepMain) mainSel.value = keepMain;
        hymnSubOptions(keepMain, selectedCatId);
    }
    function hymnSubOptions(mainId, selectId) {
        var subSel = $('mzHymnSubCat');
        if (!subSel) return;
        if (!mainId) {
            subSel.innerHTML = '<option value="">Select a category first…</option>';
            subSel.disabled = true;
            return;
        }
        var subs = mgrSubsOf(Number(mainId));
        if (!subs.length) {
            // a main with no subs: the hymn files under the main itself
            subSel.innerHTML = '<option value="' + Number(mainId) + '">No sub-categories — use the main category</option>';
            subSel.value = String(mainId);
            subSel.disabled = true;
            return;
        }
        subSel.disabled = false;
        subSel.innerHTML = subs.map(function (sb) {
            return '<option value="' + Number(sb.id) + '">' + esc(sb.name) + '</option>';
        }).join('');
        subSel.value = selectId ? String(selectId) : subs.length === 1 ? String(subs[0].id) : '';
    }
    function onHymnMainChange() { hymnSubOptions($('mzHymnMainCat') ? $('mzHymnMainCat').value : '', null); }
    function selectedCategoryIds() {
        var subSel = $('mzHymnSubCat');
        var v = subSel ? parseInt(subSel.value, 10) : 0;
        return v > 0 ? [v] : [];
    }

    function clearHymnForm() {
        $('mzHymnId').value = '0'; $('mzTitle').value = '';
        setEditorMarkup('');
        populateHymnCats(null);
        $('mzLength').value = 'long'; $('mzLanguage').value = 'amharic';
        renderCatalogBoxes([], []);
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
            $('mzHymnId').value = h.id; $('mzTitle').value = h.title || '';
            setEditorMarkup(h.lyrics || '');
            $('mzLength').value = h.length || 'long'; $('mzLanguage').value = h.language || 'amharic';
            renderCatalogBoxes(h.categories || [], h.zemarians || []);
            populateHymnCats((h.categories || [])[0] && h.categories[0].id);
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
            categories: selectedCategoryIds(),
            zemarians: checkedIds('mzZemariansBox'),
            length: $('mzLength').value, language: $('mzLanguage').value,
            lyrics: $('mzLyrics').value
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

    /** P24: styled lyrics rendering. Plain text is stored;
     *  [Section] lines become headers, **bold** / *italic* become emphasis.
     *  ESCAPE FIRST — everything after that only adds our own safe tags. */
    // ── P30 visual lyrics editor: type styled text, store the same
    //    portable markup (**bold**, *italic*, __underline__, [Section])
    //    so old data and every existing client keep working as-is. ──
    function markupToHtml(src) {
        var txt = String(src == null ? '' : src);
        if (!txt.trim()) return '';
        return txt.split(/\r?\n/).map(function (line) {
            var m = line.match(/^\[(.+)\]$/);
            if (m) return '<div class="mz-ed-sec">' + esc(m[1]) + '</div>';
            var body = esc(line)
                .replace(/\*\*(.+?)\*\*/g, '<b>$1</b>')
                .replace(/__(.+?)__/g, '<u>$1</u>')
                .replace(/\*(.+?)\*/g, '<i>$1</i>');
            return '<div>' + (body || '<br>') + '</div>';
        }).join('');
    }
    function editorToMarkup(ed) {
        var lines = [], buf = [];
        function flush() { lines.push(buf.join('')); buf = []; }
        function walk(node) {
            if (node.nodeType === 3) { buf.push(node.textContent); return; }
            if (node.nodeName === 'BR') { flush(); return; }
            if (node.classList && node.classList.contains('mz-ed-sec')) {
                flush();
                var t = (node.textContent || '').trim();
                lines.push(t ? '[' + t + ']' : '');
                return;
            }
            var tag = node.nodeName;
            var open = (tag === 'B' || tag === 'STRONG') ? '**' : (tag === 'I' || tag === 'EM') ? '*' : (tag === 'U') ? '__' : '';
            if (open) buf.push(open);
            node.childNodes.forEach(walk);
            if (open) buf.push(open);
        }
        Array.prototype.forEach.call(ed.childNodes, function (n) { walk(n); flush(); });
        // collapse trailing empties
        while (lines.length && lines[lines.length - 1] === '') lines.pop();
        return lines.join('\n');
    }
    function setEditorMarkup(txt) {
        var ed = $('mzEditor');
        if (!ed) return;
        ed.innerHTML = markupToHtml(txt);
        ed.dataset.empty = ed.textContent.trim() ? '' : '1';
        $('mzLyrics').value = txt == null ? '' : String(txt);
    }
    function syncEditor() {
        var ed = $('mzEditor');
        if (!ed) return;
        $('mzLyrics').value = editorToMarkup(ed);
        ed.dataset.empty = ed.textContent.trim() ? '' : '1';
    }
    function initLyricsEditor() {
        var ed = $('mzEditor');
        if (!ed || ed.dataset.p30) return;
        ed.dataset.p30 = '1';
        ed.addEventListener('input', syncEditor);
        // paste as plain text — styling comes from the toolbar only
        ed.addEventListener('paste', function (e) {
            e.preventDefault();
            var txt = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, txt);
        });
        document.querySelectorAll('.mz-ed-btn').forEach(function (btn) {
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep selection
            btn.addEventListener('click', function () {
                ed.focus();
                var cmd = btn.getAttribute('data-cmd');
                if (cmd === 'section') {
                    toggleSecPop(true); // in-system popover, never a browser prompt
                } else {
                    document.execCommand(cmd, false, null);
                }
                syncEditor();
            });
        });
    }

    // ── section-header popover (P31: replaces the browser prompt) ──
    function toggleSecPop(show) {
        var pop = $('mzSecPop');
        if (!pop) return;
        pop.classList.toggle('is-hidden', !show);
        if (show) { var i = $('mzSecPopInput'); i.value = ''; i.focus(); }
    }
    function initSecPop() {
        var pop = $('mzSecPop');
        if (!pop || pop.dataset.p31) return;
        pop.dataset.p31 = '1';
        var insert = function () {
            var label = $('mzSecPopInput').value.trim();
            toggleSecPop(false);
            if (!label) return;
            $('mzEditor').focus();
            document.execCommand('insertHTML', false,
                '</div><div class="mz-ed-sec">' + esc(label) + '</div><div><br></div>');
            syncEditor();
        };
        $('mzSecPopOk').addEventListener('click', insert);
        $('mzSecPopInput').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); insert(); }
            if (e.key === 'Escape') toggleSecPop(false);
        });
        $('mzSecPopCancel').addEventListener('click', function () { toggleSecPop(false); });
    }

    function renderLyrics(src) {
        var txt = esc(src == null ? '' : String(src));
        if (!txt) return '<div style="font-size:.9rem;opacity:.65;font-style:italic">(No lyrics recorded)</div>';
        var out = [], buf = [];
        function flush() {
            if (!buf.length) return;
            out.push('<div style="font-size:.95rem;line-height:2;white-space:pre-wrap">' + buf.join('<br>') + '</div>');
            buf = [];
        }
        txt.split(/\r?\n/).forEach(function (raw) {
            var line = raw.trim();
            if (line === '') { flush(); return; }
            var m = line.match(/^\[(.+)\]$/);
            if (m) {
                flush();
                out.push('<div style="margin-top:18px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;font-size:.78rem;color:var(--school-primary,#4f46e5)">' + m[1] + '</div>');
                return;
            }
            // bold first, then italic on what remains
            buf.push(line
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/__(.+?)__/g, '<u>$1</u>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>'));
        });
        flush();
        return out.join('');
    }

    function viewHymn(id) {
        apiGet('action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load this hymn.', 'e'); return; }
            var h = d.item, meta = '';
            if (h.category) meta += '<span class="badge badge-active">' + esc(h.category) + '</span>';
            if (h.status === 'archived') meta += '<span class="badge badge-inactive">Archived</span>';
            $('mzViewTitle').textContent = h.title;
            $('mzViewMeta').innerHTML = meta;
            $('mzViewLyrics').innerHTML = renderLyrics(h.lyrics);
            openModalF('mzViewModal', null);
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    function setHymnStatus(id, status) {
        var label = status === 'archived' ? 'archive' : 'restore';
        sysConfirm('Are you sure you want to ' + label + ' this hymn?', function () {
        apiPost({ action: 'set_status', id: id, status: status }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Action failed.', 'e'); return; }
            window.toast(d.message || 'Done.', 's');
            loadStats(); loadList();
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
        });
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
        var tb = $('mzSessTbody');
        if (!tb) return; // day-history card retired; submissions inbox + Insights cover it
        att.page = page || 1;
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

    // ── review inbox (department) — edu Submissions workflow clone ─
    var _allPackets = [];

    function switchSubTab(tab) {
        ['draft', 'submitted', 'insights'].forEach(function (t) {
            var id = 'mzSubTab' + (t === 'insights' ? 'Insights' : t.charAt(0).toUpperCase() + t.slice(1));
            var b = $(id);
            if (!b) return;
            b.classList.toggle('active', t === tab);
            b.setAttribute('aria-selected', t === tab ? 'true' : 'false');
        });
        var hid = $('mzSubTabStatus');
        if (tab === 'insights') {
            $('mzSubmissionsList').classList.add('is-hidden');
            $('mzSubInsights').classList.remove('is-hidden');
            loadSubInsights();
            return;
        }
        $('mzSubmissionsList').classList.remove('is-hidden');
        $('mzSubInsights').classList.add('is-hidden');
        hid.value = tab;
        loadSubmissions();
    }

    function loadSubmissions() {
        var tb = $('mzSubTbody');
        if (!tb) return;
        tb.innerHTML = skeletonRows(5);
        var status = $('mzSubTabStatus').value || 'draft';
        var q = 'action=submissions_list&per_page=100&status=' + encodeURIComponent(status);
        var sec = $('mzSubSection') ? $('mzSubSection').value : '';
        var from = $('mzSubFrom') ? $('mzSubFrom').value : '';
        var to = $('mzSubTo') ? $('mzSubTo').value : '';
        if (sec) q += '&section=' + encodeURIComponent(sec);
        if (from) q += '&from=' + encodeURIComponent(from);
        if (to) q += '&to=' + encodeURIComponent(to);
        apiGet(q).then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="8">' + errorState(d.message || 'Unable to load submissions.', 'Mezmur.loadSubmissions()') + '</td></tr>';
                return;
            }
            _allPackets = d.items || [];
            renderSubStats(d.stats || {});
            if (!_allPackets.length) {
                var empty = status === 'draft'
                    ? 'No drafts yet. When a taker taps Save, the unfinished sheet appears here.'
                    : 'No submitted sheets yet. Submit is used when the section sheet is complete.';
                tb.innerHTML = '<tr><td colspan="8">' + emptyState('fa-inbox', status === 'draft' ? 'No drafts' : 'Nothing submitted', empty) + '</td></tr>';
                return;
            }
            tb.innerHTML = _allPackets.map(function (p) {
                var result = p.present_count + 'P / ' + p.late_count + 'L / ' + p.absent_count + 'A' + (p.excused_count ? ' / ' + p.excused_count + 'E' : '');
                var returned = p.status === 'revision_needed' && p.reviewer_name
                    ? '<div class="text-dim" style="font-size:.68rem;margin-top:2px"><i class="fa-solid fa-arrow-rotate-left"></i> ' + esc(p.reviewer_name) +
                      (p.review_notes ? ': ' + esc(String(p.review_notes).length > 60 ? String(p.review_notes).slice(0, 60) + '…' : p.review_notes) : '') + '</div>'
                    : '';
                var actions = '<button class="btn-secondary btn-sm" title="Open packet" onclick="Mezmur.viewPacket(' + p.id + ')"><i class="fa-solid fa-eye"></i></button> ' +
                    '<button class="btn-secondary btn-sm" title="Review" onclick="Mezmur.openReview(' + p.id + ')"><i class="fa-solid fa-gavel"></i></button>';
                if (p.status === 'submitted') {
                    actions += ' <button class="btn-primary btn-sm" title="Approve now" onclick="Mezmur.quickDecision(' + p.id + ',\'approved\')"><i class="fa-solid fa-check"></i></button>';
                }
                return '<tr>' +
                    '<td class="nowrap">' + fmtDate(p.attendance_date) + '</td>' +
                    '<td class="amharic">' + esc(p.section) + '</td>' +
                    '<td>' + esc(p.taker_name || '—') + '</td>' +
                    '<td>' + p.member_count + '</td>' +
                    '<td style="font-weight:600;font-size:.78rem">' + result + '</td>' +
                    '<td>' + statusChip(p.status) + returned + '</td>' +
                    '<td class="nowrap text-dim">' + fmtDate(p.updated_at) + '</td>' +
                    '<td class="nowrap">' + actions + '</td>' +
                    '</tr>';
            }).join('');
        }).catch(function (err) {
            tb.innerHTML = '<tr><td colspan="8">' + errorState((err && err.message) || 'Connection error.', 'Mezmur.loadSubmissions()') + '</td></tr>';
        });
    }

    function renderSubStats(st) {
        var row = $('mzSubStatsRow');
        if (!row) return;
        var today = st.today_packets
            ? (st.today_present || 0) + ' P · ' + (st.today_absent || 0) + ' A · ' + (st.today_late || 0) + ' L'
            : '—';
        row.innerHTML =
            '<div class="sub-stat" style="background:linear-gradient(135deg,#2563eb,#3b82f6)"><b>' + (st.drafts || 0) + '</b><span>Drafts (not finished)</span></div>' +
            '<div class="sub-stat" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><b>' + (st.submitted || 0) + '</b><span>Submitted (needs review)</span></div>' +
            '<div class="sub-stat" style="background:linear-gradient(135deg,#059669,#10b981)"><b>' + (st.approved || 0) + '</b><span>Approved</span></div>' +
            '<div class="sub-stat" style="background:linear-gradient(135deg,#7c3aed,#6366f1)"><b style="font-size:1rem">' + today + '</b><span>Today\u2019s marks (' + (st.today_packets || 0) + ' sheets)</span></div>';
    }

    function quickDecision(id, decision) {
        // Approve straight from the table. Anything else (reject /
        // return) goes through the review modal so a note explains
        // the decision to the taker.
        if (decision !== 'approved') { openReview(id); return; }
        apiPost({ action: 'submission_review', submission_id: id, new_status: decision, notes: '' }).then(function (d) {
            if (d.status !== 'success') { window.toast(d.message || 'Unable to record the decision.', 'e'); return; }
            window.toast(d.message || 'Approved.', 's');
            loadSubmissions();
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    // Printable QR tiles for the selected section (Phase 8 QR attendance).
    // GET-only governed endpoint; the section picker doubles as the guard.
    function printQrRoster() {
        var sec = $('mzSubSection') ? $('mzSubSection').value : '';
        if (!sec) { window.toast('Pick a section first.', 'e'); return; }
        window.open('/admin/api_qr_roster.php?dept=mezmur&section=' + encodeURIComponent(sec), '_blank');
    }

    function exportSubmissions() {
        if (!_allPackets.length) { window.toast('Nothing to export on this tab.', 'e'); return; }
        var head = ['Date', 'Section', 'Taker', 'Members', 'Present', 'Late', 'Absent', 'Excused', 'Status', 'Updated'];
        var rows = _allPackets.map(function (p) {
            return [p.attendance_date || '', p.section || '', p.taker_name || '', p.member_count || 0,
                p.present_count || 0, p.late_count || 0, p.absent_count || 0, p.excused_count || 0,
                p.status_label || p.status || '', p.updated_at || ''];
        });
        if (window.XLSX) {
            var ws = XLSX.utils.aoa_to_sheet([head].concat(rows));
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Submissions');
            XLSX.writeFile(wb, 'FKSS_Mezmur_Submissions.xlsx');
        } else {
            var csv = '\ufeff' + head.join(',') + '\n' + rows.map(function (r) {
                return r.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
            }).join('\n');
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'mezmur-submissions-' + todayStr() + '.csv';
            document.body.appendChild(a); a.click(); a.remove();
        }
        window.toast('Submissions exported.', 's');
    }

    function loadSubInsights() {
        var box = $('mzSubInsights');
        if (!box) return;
        box.innerHTML = skeletonRows(3);
        // Last 14 attendance days (existing bounded action) + packet
        // status distribution — the same shape edu's Insights tab uses.
        apiGet('action=days_list&per_page=14').then(function (d) {
            var days = (d && d.items) || [];
            var html = '<div class="toolbar"><div class="toolbar-title"><h3 class="school-card-title"><i class="fa-solid fa-chart-line"></i> Last 14 attendance days</h3></div></div>';
            if (!days.length) {
                html += emptyState('fa-calendar-check', 'No attendance days yet', 'Recorded days appear here once takers submit sheets.');
            } else {
                html += '<div class="table-shell"><table><thead><tr><th>Date</th><th>Marked</th><th>Attended</th><th>Rate</th></tr></thead><tbody>' +
                    days.map(function (x) {
                        var marked = Number(x.marked || 0), attended = Number(x.attended || 0);
                        var rate = marked > 0 ? Math.round(attended * 1000 / marked) / 10 : null;
                        return '<tr><td class="nowrap">' + fmtDate(x.attendance_date) + '</td><td>' + marked + '</td><td>' + attended + '</td><td>' + rateBar(rate) + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
            }
            html += '<p class="text-dim" style="margin-top:.75rem;font-size:.75rem">Full member / section / trend analytics live in the Analytics section.</p>';
            box.innerHTML = html;
        }).catch(function (err) {
            box.innerHTML = errorState((err && err.message) || 'Connection error.', 'Mezmur.loadSubInsights()');
        });
    }

    function loadSubSectionOptions() {
        var sel = $('mzSubSection');
        if (!sel || sel.dataset.loaded) return;
        apiGet('action=sections').then(function (d) {
            var items = (d && d.items) || [];
            items.forEach(function (x) {
                var name = x.section || x.name;
                if (!name) return;
                var opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name + (x.members != null ? ' (' + x.members + ')' : '');
                sel.appendChild(opt);
            });
            sel.dataset.loaded = '1';
        }).catch(function () { /* filter stays optional */ });
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
        window.api.get('/admin/api_dept_takers.php?action=list').then(function (d) {
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="6">' + errorState(d.message || 'Unable to load takers.', 'Mezmur.reloadTakers()') + '</td></tr>';
                return;
            }
            var items = d.items || [];
            if (!items.length) {
                tb.innerHTML = '<tr><td colspan="6">' + emptyState('fa-user-shield', 'No mezmur takers yet', 'Create a mezmur attendance taker so a trusted member can record section attendance from the mobile app. These accounts belong to the mezmur department only.', '<button class="btn-primary btn-sm" onclick="Mezmur.openTakerModal()"><i class="fa-solid fa-user-plus"></i> Add Taker</button>') + '</td></tr>';
                return;
            }
            tb.innerHTML = items.map(function (t) {
                return '<tr>' +
                    '<td><b>' + esc(t.full_name || t.username) + '</b></td>' +
                    '<td class="text-dim">' + esc(t.username) + '</td>' +
                    '<td class="text-dim">' + esc(t.role_label || 'Mezmur Attendance Taker') + '</td>' +
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
        // Governed department-taker endpoint: the server re-checks
        // that mezmur_dept may only create mezmur_attendance_taker
        // accounts, and runs advanced username validation.
        window.api.post('/admin/api_dept_takers.php', {
            action: 'create', role: 'mezmur_attendance_taker',
            full_name: name, username: user, password: pass
        }).then(function (d) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Create Account';
            if (d.status !== 'success') { showError($('mzTkError'), d.message || 'Unable to create the account.'); return; }
            closeModalF('mzTakerModal');
            window.toast('Mezmur attendance taker created.', 's');
            loadTakers();
        }).catch(function (err) {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Create Account';
            showError($('mzTkError'), (err && err.message) || 'Connection error.');
        });
    }

    function toggleTaker(id) {
        window.api.post('/admin/api_dept_takers.php', { action: 'toggle', user_id: id }).then(function (d) {
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
            debounce = setTimeout(function () {
                var t = v.trim();
                if (t.length === 1) return; // P22: wait for the 2nd character
                lib.search = t; lib.page = 1; loadList();
            }, 160);
        });
        $('mzCategoryFilter').addEventListener('change', function () {
            lib.category = '';
            lib.categoryId = parseInt(this.value, 10) || 0;
            lib.page = 1; loadList();
        });
        $('mzStatusFilter').addEventListener('change', function () { lib.status = this.value; lib.page = 1; loadList(); });
        $('mzHymnMainCat').addEventListener('change', onHymnMainChange);
        initPickPanels();
        initLyricsEditor();
        initSysDialog();
        initSecPop();
        setEditorMarkup('');
        populateHymnCats(null);
        $('mzLengthFilter').addEventListener('change', function () { lib.length = this.value; lib.page = 1; loadList(); });
        $('mzLanguageFilter').addEventListener('change', function () { lib.language = this.value; lib.page = 1; loadList(); });
        loadCatalog();


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
        clearFilters: clearFilters,
        tab: tab, browseCategory: browseCategory, browseZemarian: browseZemarian,
        openCatalog: openCatalog,
        mgrTab: mgrTab, mgrAddMain: mgrAddMain, mgrAddSubOpen: mgrAddSubOpen, mgrAddSub: mgrAddSub, mgrAddZem: mgrAddZem,
        mgrEdit: mgrEdit, mgrSave: mgrSave, mgrCancel: mgrCancel, mgrToggle: mgrToggle, mgrSort: mgrSort, mgrImage: mgrImage,
        closeModal: function () { closeModalF('mzHymnModal'); },
        closeView: function () { closeModalF('mzViewModal'); },
        libPage: function (p) { if (p >= 1 && p <= lib.totalPages) { lib.page = p; loadList(); } },
        // attendance
        loadDays: function () { loadDays(1); },
        sessPage: function (p) { loadDays(p); },
        openDay: openDay, viewSheet: viewSheet, quickReview: quickReview,
        closeSheet: function () { closeSheet(false); },
        // review inbox
        loadSubmissions: loadSubmissions, switchSubTab: switchSubTab, quickDecision: quickDecision,
        printQrRoster: printQrRoster,
        exportSubmissions: exportSubmissions, openReview: openReview, submitReview: submitReview, viewPacket: viewPacket,
        // analytics
        runAnalytics: runAnalytics, sortBy: sortBy, exportCsv: exportCsv,
        // takers
        openTakerModal: openTakerModal, createTaker: createTaker, toggleTaker: toggleTaker
    };
})();
