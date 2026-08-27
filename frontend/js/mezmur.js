/**
 * ════════════════════════════════════════════════════════════
 * Mezmur Department (መዝሙር ክፍል) — Hymn Library v1
 * ════════════════════════════════════════════════════════════
 * Pure front-end controller for frontend/pages/mezmur_dept.php.
 * All data access goes through /backend/api/mezmur.php (shim →
 * admin/api_mezmur.php), which re-validates session, role, CSRF
 * and every input server-side.
 *
 * Security conventions (same as finance.js):
 *   - Every dynamic value is HTML-escaped via esc() before it
 *     touches innerHTML. Lyrics/long text use textContent only.
 *   - All mutations are POST (CSRF auto-appended by SSMS.api).
 *   - Server-side pagination — the list never loads more than
 *     one page at a time (scales to very large libraries).
 * ════════════════════════════════════════════════════════════
 */
(function () {
    'use strict';

    var PAGE_SIZE = 25;

    var state = {
        page: 1,
        totalPages: 1,
        total: 0,
        search: '',
        category: '',
        status: 'active',
        loading: false
    };

    // ── helpers ────────────────────────────────────────────────
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
        el.textContent = msg || 'Something went wrong.';
        el.style.display = msg ? 'block' : 'none';
    }

    // ── data loading ───────────────────────────────────────────
    function loadStats() {
        return SSMS.api.get('mezmur.php?action=stats').then(function (d) {
            if (d.status !== 'success') return;
            $('mzStatTotal').textContent = d.total != null ? d.total : '—';
            $('mzStatActive').textContent = d.active != null ? d.active : '—';
            $('mzStatCategories').textContent = d.categories != null ? d.categories : '—';

            var sel = $('mzCategoryFilter');
            var cur = sel.value;
            sel.innerHTML = '<option value="">All categories</option>' +
                (d.category_list || []).map(function (c) {
                    return '<option value="' + esc(c) + '">' + esc(c) + '</option>';
                }).join('');
            sel.value = cur;

            var dl = $('mzCategoryOptions');
            dl.innerHTML = (d.category_list || []).map(function (c) {
                return '<option value="' + esc(c) + '">';
            }).join('');
        }).catch(function () { /* stats are non-critical */ });
    }

    function loadList() {
        if (state.loading) return;
        state.loading = true;
        var tb = $('mzTbody');
        tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:1.5rem"><i class="fa-solid fa-spinner fa-spin"></i> Loading hymns…</td></tr>';

        var q = 'action=list'
            + '&page=' + encodeURIComponent(state.page)
            + '&per_page=' + PAGE_SIZE
            + '&search=' + encodeURIComponent(state.search)
            + '&category=' + encodeURIComponent(state.category)
            + '&status=' + encodeURIComponent(state.status);

        SSMS.api.get('mezmur.php?' + q).then(function (d) {
            state.loading = false;
            if (d.status !== 'success') {
                tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#f87171;padding:1.5rem">' +
                    '<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(d.message || 'Unable to load hymns.') + '</td></tr>';
                return;
            }
            state.totalPages = d.total_pages || 1;
            state.total = d.total || 0;
            renderRows(d.items || []);
            renderPagination();
        }).catch(function (err) {
            state.loading = false;
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#f87171;padding:1.5rem">' +
                '<i class="fa-solid fa-triangle-exclamation"></i> ' + esc((err && err.message) || 'Connection error.') + '</td></tr>';
        });
    }

    function renderRows(items) {
        var tb = $('mzTbody');
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--school-text-dim);padding:2rem">' +
                (state.search || state.category ? '<i class="fa-solid fa-magnifying-glass"></i> No hymns match your filters.'
                    : '<i class="fa-solid fa-music"></i> No hymns yet. Click <b>Add Hymn</b> to create the first one.') +
                '</td></tr>';
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

    function renderPagination() {
        var el = $('mzPagination');
        if (state.totalPages <= 1) {
            el.innerHTML = '<span style="color:var(--school-text-dim);font-size:.8rem">' + state.total + ' hymn' + (state.total === 1 ? '' : 's') + '</span><span></span>';
            return;
        }
        var btns = '';
        btns += '<button class="btn-secondary btn-sm" ' + (state.page <= 1 ? 'disabled' : '') + ' onclick="Mezmur.goPage(' + (state.page - 1) + ')"><i class="fa-solid fa-chevron-left"></i></button> ';
        btns += '<span style="color:var(--school-text-dim);font-size:.8rem">Page ' + state.page + ' of ' + state.totalPages + ' • ' + state.total + ' hymns</span> ';
        btns += '<button class="btn-secondary btn-sm" ' + (state.page >= state.totalPages ? 'disabled' : '') + ' onclick="Mezmur.goPage(' + (state.page + 1) + ')"><i class="fa-solid fa-chevron-right"></i></button>';
        el.innerHTML = btns;
    }

    // ── modal: add / edit ──────────────────────────────────────
    function clearForm() {
        $('mzHymnId').value = '0';
        $('mzTitle').value = '';
        $('mzTitleAm').value = '';
        $('mzCategory').value = '';
        $('mzReference').value = '';
        $('mzLyrics').value = '';
        showError($('mzModalError'), '');
    }

    function openAdd() {
        clearForm();
        $('mzModalTitle').innerHTML = '<i class="fa-solid fa-music"></i> Add Hymn';
        modal('mzHymnModal', true);
        setTimeout(function () { $('mzTitle').focus(); }, 60);
    }

    function openEdit(id) {
        clearForm();
        $('mzModalTitle').innerHTML = '<i class="fa-solid fa-pen"></i> Edit Hymn';
        SSMS.api.get('mezmur.php?action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) {
                window.toast(d.message || 'Unable to load this hymn.', 'e');
                return;
            }
            var h = d.item;
            $('mzHymnId').value = h.id;
            $('mzTitle').value = h.title || '';
            $('mzTitleAm').value = h.title_am || '';
            $('mzCategory').value = h.category || '';
            $('mzReference').value = h.reference || '';
            $('mzLyrics').value = h.lyrics || '';
            modal('mzHymnModal', true);
        }).catch(function (err) {
            window.toast((err && err.message) || 'Connection error.', 'e');
        });
    }

    function save() {
        var title = $('mzTitle').value.trim();
        if (!title) {
            showError($('mzModalError'), 'Title is required.');
            $('mzTitle').focus();
            return;
        }
        var btn = $('mzSaveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

        SSMS.api.post('mezmur.php', {
            action: 'save',
            id: $('mzHymnId').value,
            title: title,
            title_am: $('mzTitleAm').value.trim(),
            category: $('mzCategory').value.trim(),
            reference: $('mzReference').value.trim(),
            lyrics: $('mzLyrics').value
        }).then(function (d) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Hymn';
            if (d.status !== 'success') {
                showError($('mzModalError'), d.message || 'Unable to save the hymn.');
                return;
            }
            modal('mzHymnModal', false);
            window.toast(d.message || 'Hymn saved.', 's');
            loadStats();
            loadList();
        }).catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Hymn';
            showError($('mzModalError'), (err && err.message) || 'Connection error.');
        });
    }

    // ── view ───────────────────────────────────────────────────
    function view(id) {
        SSMS.api.get('mezmur.php?action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) {
                window.toast(d.message || 'Unable to load this hymn.', 'e');
                return;
            }
            var h = d.item;
            $('mzViewTitle').textContent = h.title;
            var meta = '';
            if (h.title_am) meta += '<span class="badge badge-info amharic">' + esc(h.title_am) + '</span>';
            if (h.category) meta += '<span class="badge badge-active">' + esc(h.category) + '</span>';
            if (h.reference) meta += '<span class="badge badge-warning">' + esc(h.reference) + '</span>';
            if (h.status === 'archived') meta += '<span class="badge badge-inactive">Archived</span>';
            $('mzViewMeta').innerHTML = meta;
            $('mzViewLyrics').textContent = h.lyrics || '(No lyrics recorded)';
            modal('mzViewModal', true);
        }).catch(function (err) {
            window.toast((err && err.message) || 'Connection error.', 'e');
        });
    }

    // ── archive / restore ──────────────────────────────────────
    function setStatus(id, status) {
        var label = status === 'archived' ? 'archive' : 'restore';
        if (!window.confirm('Are you sure you want to ' + label + ' this hymn?')) return;
        SSMS.api.post('mezmur.php', { action: 'set_status', id: id, status: status }).then(function (d) {
            if (d.status !== 'success') {
                window.toast(d.message || 'Action failed.', 'e');
                return;
            }
            window.toast(d.message || 'Done.', 's');
            loadStats();
            loadList();
        }).catch(function (err) {
            window.toast((err && err.message) || 'Connection error.', 'e');
        });
    }

    // ── wiring ─────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        var debounce = null;
        $('mzSearch').addEventListener('input', function () {
            clearTimeout(debounce);
            var v = this.value;
            debounce = setTimeout(function () {
                state.search = v.trim();
                state.page = 1;
                loadList();
            }, 300);
        });
        $('mzCategoryFilter').addEventListener('change', function () {
            state.category = this.value;
            state.page = 1;
            loadList();
        });
        $('mzStatusFilter').addEventListener('change', function () {
            state.status = this.value;
            state.page = 1;
            loadList();
        });

        loadStats();
        loadList();
    });

    // Public API (used by inline onclick handlers in the shell)
    window.Mezmur = {
        openAdd: openAdd,
        openEdit: openEdit,
        save: save,
        view: view,
        setStatus: setStatus,
        closeModal: function () { modal('mzHymnModal', false); },
        closeView: function () { modal('mzViewModal', false); },
        goPage: function (p) {
            p = parseInt(p, 10);
            if (isNaN(p) || p < 1 || p > state.totalPages || p === state.page) return;
            state.page = p;
            loadList();
        }
    };
})();
