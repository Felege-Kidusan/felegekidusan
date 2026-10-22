/*
 * Shared paginated list engine — one pager for every member list.
 *
 *   Showing 51–100 of 440 members          Per page [25|50|100|200]
 *   ‹ Previous  1 2 [3] 4 … 9  Next ›      [Load more (340 left)]
 *
 * - Previous / page numbers / Next REPLACE the rows (jump to a page).
 * - Load more APPENDS the next page under the rows already shown.
 * - The per-page choice is remembered per screen (localStorage).
 *
 * Uses simple OFFSET paging (?page=N&limit=M) which the API supports natively
 * (MemberDirectoryService: default 50, max 200). Callers are unchanged: the
 * constructor options are the same as before; new ones are optional:
 *   pageSizes  [25, 50, 100, 200]
 *   storageKey 'fkss_pp_<tbody id>'   (remembered per-page choice)
 *   noun       'members'
 *
 * Phase 3 (owner request 2026-10-22): per-page, Previous/Next, "51–100" range.
 */
(function (global) {
    'use strict';

    var SIZES = [25, 50, 100, 200];

    function readSaved(key, sizes) {
        try {
            var v = parseInt(global.localStorage.getItem(key), 10);
            return sizes.indexOf(v) >= 0 ? v : null;
        } catch (e) { return null; }
    }

    function PaginatedList(opts) {
        this.endpoint = opts.endpoint;
        this.body = opts.bodyElement;
        this.renderRow = opts.renderRow;
        this.getParams = opts.getParams || function () { return {}; };
        this.pageSizes = Array.isArray(opts.pageSizes) && opts.pageSizes.length ? opts.pageSizes : SIZES;
        this.noun = opts.noun || 'members';
        this.loadMoreHost = opts.loadMoreHost || null;
        this.pageNavHost = opts.pageNavHost || this.loadMoreHost;
        var id = (this.body && this.body.id) || (this.loadMoreHost && this.loadMoreHost.id) || 'list';
        this.storageKey = opts.storageKey || ('fkss_pp_' + id);
        this.pageSize = readSaved(this.storageKey, this.pageSizes) || opts.pageSize || 50;

        this.page = 1;        // last page loaded (Load more continues from here)
        this.firstPage = 1;   // first page in view (the range starts here)
        this.total = 0;
        this.pages = 1;
        this.loadedCount = 0;
        this.request = null;
    }

    /* ── URL building ── */

    PaginatedList.prototype.buildUrl = function (page, includeTotal) {
        var params = this.getParams();
        params.page = String(page);
        params.limit = String(this.pageSize);
        if (!includeTotal) params.include_total = '0';
        var qs = new URLSearchParams();
        Object.keys(params).forEach(function (k) {
            if (params[k] !== undefined && params[k] !== null && params[k] !== '')
                qs.set(k, String(params[k]));
        });
        return this.endpoint + '?' + qs.toString();
    };

    /* ── Public API ── */

    PaginatedList.prototype.load = async function (page, append) {
        if (this.request) this.request.abort();
        this.request = new AbortController();
        if (!append && this.body) {
            this._setBodyMessage('Loading…', false);
            this._clearControls();
        }
        try {
            var url = this.buildUrl(page, !append);
            var response = await fetch(url,
                { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: this.request.signal }
            );
            var raw = await response.text();
            var data;
            try { data = JSON.parse(raw); } catch (parseErr) {
                console.error('[PaginatedList] Non-JSON response from', url, 'HTTP', response.status, raw.substring(0, 300));
                throw new Error('Server returned invalid response (HTTP ' + response.status + ').');
            }
            if (!response.ok || data.status !== 'success') {
                console.error('[PaginatedList] API error:', data.message || data, 'HTTP', response.status, 'URL:', url);
                throw new Error(data.message || 'Failed to load (HTTP ' + response.status + ').');
            }

            var members = Array.isArray(data.members) ? data.members : [];
            if (data.total != null) this.total = Number(data.total) || 0;
            if (data.pages != null) this.pages = Math.max(1, Number(data.pages) || 1);
            this.page = Math.min(Math.max(1, Number(data.page) || page || 1), this.pages);

            if (append) {
                this._appendRows(members);
                this.loadedCount += members.length;
            } else {
                this.firstPage = this.page;
                this._replaceRows(members);
                this.loadedCount = members.length;
            }
            this._renderControls();
        } catch (err) {
            if (err.name === 'AbortError') return;
            this._setBodyMessage('Could not load ' + this.noun + '. Please try again.', true);
            this._clearControls();
            console.error('PaginatedList error:', err);
        } finally {
            this.request = null;
        }
    };

    PaginatedList.prototype.reset = function () {
        this.page = 1; this.firstPage = 1; this.total = 0; this.pages = 1; this.loadedCount = 0;
        this.load(1, false);
    };

    PaginatedList.prototype.goToPage = function (page) {
        page = Math.min(Math.max(1, page), this.pages);
        this.load(page, false);
    };

    PaginatedList.prototype.setPageSize = function (size) {
        size = parseInt(size, 10);
        if (this.pageSizes.indexOf(size) < 0 || size === this.pageSize) return;
        this.pageSize = size;
        try { global.localStorage.setItem(this.storageKey, String(size)); } catch (e) { /* private mode */ }
        this.reset();
    };

    /** First and last row numbers in view (1-based), e.g. 51 and 100. */
    PaginatedList.prototype.range = function () {
        if (!this.total || !this.loadedCount) return { from: 0, to: 0 };
        var from = (this.firstPage - 1) * this.pageSize + 1;
        return { from: from, to: Math.min(this.total, from + this.loadedCount - 1) };
    };

    /* ── Rendering ── */

    PaginatedList.prototype._replaceRows = function (members) {
        if (!this.body) return;
        this.body.replaceChildren();
        if (!members.length) { this._setBodyMessage('No results found.', false); return; }
        var self = this;
        // 2nd argument = the row's position in the whole list (1-based).
        var base = (this.firstPage - 1) * this.pageSize;
        members.forEach(function (m, i) { self.body.appendChild(self.renderRow(m, base + i + 1)); });
    };

    PaginatedList.prototype._appendRows = function (members) {
        if (!this.body) return;
        var self = this;
        var base = (this.firstPage - 1) * this.pageSize + this.loadedCount;
        members.forEach(function (m, i) { self.body.appendChild(self.renderRow(m, base + i + 1)); });
    };

    PaginatedList.prototype._setBodyMessage = function (msg, isError) {
        if (!this.body) return;
        this.body.replaceChildren();
        var row = document.createElement('tr');
        var cell = document.createElement('td');
        var table = this.body.closest ? this.body.closest('table') : null;
        cell.colSpan = table ? table.querySelectorAll('thead th').length || 4 : 4;
        cell.className = 'p-4 text-center text-sm ' + (isError ? 'text-red-500' : 'text-slate-400');
        cell.textContent = msg;
        row.appendChild(cell);
        this.body.appendChild(row);
    };

    PaginatedList.prototype._clearControls = function () {
        if (this.loadMoreHost) this.loadMoreHost.replaceChildren();
        if (this.pageNavHost && this.pageNavHost !== this.loadMoreHost) this.pageNavHost.replaceChildren();
    };

    PaginatedList.prototype._hasMore = function () {
        return this.range().to < this.total;
    };

    /* ── Controls: range + per page, then Previous / pages / Next + Load more ── */

    PaginatedList.prototype._renderControls = function () {
        this._clearControls();
        if (!this.loadMoreHost || this.total === 0) return;
        var self = this;
        var r = this.range();

        // ── Row 1: "Showing 51–100 of 440 members" + Per page ──
        var top = document.createElement('div');
        top.className = 'fk-pager flex items-center justify-between mt-3 px-4 py-2 flex-wrap gap-2';
        top.setAttribute('data-fk-pager', '');

        var status = document.createElement('span');
        status.className = 'text-xs text-slate-500';
        status.setAttribute('data-fk-pager-range', '');
        status.textContent = 'Showing ' + r.from + '–' + r.to + ' of ' + this.total + ' ' + this.noun;
        top.appendChild(status);

        var right = document.createElement('span');
        right.className = 'flex items-center gap-2 flex-wrap';
        var lbl = document.createElement('label');
        lbl.className = 'text-xs text-slate-500 flex items-center gap-1';
        lbl.appendChild(document.createTextNode('Per page'));
        var sel = document.createElement('select');
        sel.className = 'border border-slate-200 rounded-md px-2 py-1 text-xs bg-white';
        sel.setAttribute('data-fk-pager-size', '');
        sel.setAttribute('aria-label', 'Rows per page');
        this.pageSizes.forEach(function (n) {
            var o = document.createElement('option');
            o.value = String(n); o.textContent = String(n);
            if (n === self.pageSize) o.selected = true;
            sel.appendChild(o);
        });
        sel.addEventListener('change', function () { self.setPageSize(sel.value); });
        lbl.appendChild(sel);
        right.appendChild(lbl);

        if (this._hasMore()) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('data-fk-pager-more', '');
            btn.className = 'px-4 py-2 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 font-semibold text-sm transition';
            btn.textContent = 'Load more (' + (this.total - r.to) + ' left)';
            btn.addEventListener('click', function () {
                btn.disabled = true; btn.textContent = 'Loading…';
                self.load(self.page + 1, true);
            });
            right.appendChild(btn);
        } else {
            var done = document.createElement('span');
            done.className = 'text-xs text-emerald-600 font-semibold';
            done.setAttribute('data-fk-pager-done', '');
            done.textContent = r.from === 1 ? '✓ All ' + this.total + ' shown' : '✓ End of list';
            right.appendChild(done);
        }
        top.appendChild(right);
        this.loadMoreHost.appendChild(top);

        // ── Row 2: ‹ Previous · 1 2 [3] … 9 · Next › ──
        if (this.pageNavHost && this.pages > 1) {
            var nav = document.createElement('div');
            nav.className = 'flex items-center justify-center gap-1.5 mt-1 pb-2 flex-wrap';
            nav.setAttribute('data-fk-pager-nav', '');

            nav.appendChild(this._navButton('‹ Previous', this.firstPage > 1, 'prev', function () {
                self.goToPage(self.firstPage - 1);
            }));

            var inView = function (p) { return p >= self.firstPage && p <= self.page; };
            this._pageNumbers().forEach(function (p) {
                if (p === '…') {
                    var ell = document.createElement('span');
                    ell.className = 'px-2 py-1 text-xs text-slate-400';
                    ell.textContent = '…';
                    nav.appendChild(ell);
                    return;
                }
                var b = document.createElement('button');
                b.type = 'button';
                var active = inView(p);
                b.className = active
                    ? 'min-w-[36px] px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-semibold text-xs'
                    : 'min-w-[36px] px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold text-xs transition';
                b.textContent = String(p);
                b.setAttribute('data-fk-pager-page', String(p));
                if (active) { b.disabled = true; b.setAttribute('aria-current', 'page'); }
                else b.addEventListener('click', function () { self.goToPage(p); });
                nav.appendChild(b);
            });

            nav.appendChild(this._navButton('Next ›', this.page < this.pages, 'next', function () {
                self.goToPage(self.page + 1);
            }));

            var pos = document.createElement('span');
            pos.className = 'ml-2 text-xs text-slate-500';
            pos.setAttribute('data-fk-pager-pos', '');
            pos.textContent = this.firstPage === this.page
                ? 'Page ' + this.page + ' of ' + this.pages
                : 'Pages ' + this.firstPage + '–' + this.page + ' of ' + this.pages;
            nav.appendChild(pos);

            this.pageNavHost.appendChild(nav);
        }
    };

    /** Previous / Next stay in place (disabled at the ends) so the bar does not jump. */
    PaginatedList.prototype._navButton = function (label, enabled, kind, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('data-fk-pager-' + kind, '');
        btn.className = 'px-3 py-1.5 rounded-lg border border-slate-200 font-semibold text-xs transition '
            + (enabled ? 'text-slate-600 hover:bg-slate-50' : 'text-slate-300 cursor-not-allowed');
        btn.textContent = label;
        btn.disabled = !enabled;
        if (enabled) btn.addEventListener('click', onClick);
        return btn;
    };

    /** Google-style page number list with ellipsis for long ranges. */
    PaginatedList.prototype._pageNumbers = function () {
        var current = this.page, total = this.pages;
        if (total <= 7) return Array.from({ length: total }, function (_, i) { return i + 1; });
        if (current <= 3) return [1, 2, 3, 4, '…', total];
        if (current >= total - 2) return [1, '…', total - 3, total - 2, total - 1, total];
        return [1, '…', current - 1, current, current + 1, '…', total];
    };

    global.PaginatedList = PaginatedList;
})(window);
