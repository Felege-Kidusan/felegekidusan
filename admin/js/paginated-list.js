/*
 * Shared paginated list engine — enterprise-grade page navigation.
 *
 * Supports two modes simultaneously:
 *   - Page numbers (1 2 3 … Next/Previous) for jumping
 *   - "Load More" for appending without losing scroll position
 *
 * Uses simple OFFSET paging (?page=N) which the API supports natively.
 * Replaces the fragile cursor-chain system that broke on every surface.
 */
(function (global) {
    'use strict';

    function PaginatedList(opts) {
        this.endpoint = opts.endpoint;
        this.body = opts.bodyElement;
        this.renderRow = opts.renderRow;
        this.getParams = opts.getParams || function () { return {}; };
        this.pageSize = opts.pageSize || 50;
        this.loadMoreHost = opts.loadMoreHost || null;
        this.pageNavHost = opts.pageNavHost || this.loadMoreHost;

        this.page = 1;
        this.total = 0;
        this.pages = 1;
        this.loadedCount = 0;
        this.request = null;
        this._loadMoreBtn = null;
        this._pageNavEl = null;
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
            this.page = Math.min(Math.max(1, Number(data.page) || 1), this.pages);

            if (append) {
                this._appendRows(members);
                this.loadedCount += members.length;
            } else {
                this._replaceRows(members);
                this.loadedCount = members.length;
            }
            this._renderControls();
        } catch (err) {
            if (err.name === 'AbortError') return;
            this._setBodyMessage('Could not load members. Please try again.', true);
            this._clearControls();
            console.error('PaginatedList error:', err);
        } finally {
            this.request = null;
        }
    };

    PaginatedList.prototype.reset = function () {
        this.page = 1; this.total = 0; this.pages = 1; this.loadedCount = 0;
        this.load(1, false);
    };

    PaginatedList.prototype.goToPage = function (page) {
        page = Math.min(Math.max(1, page), this.pages);
        this.load(page, false);
    };

    /* ── Rendering ── */

    PaginatedList.prototype._replaceRows = function (members) {
        if (!this.body) return;
        this.body.replaceChildren();
        if (!members.length) { this._setBodyMessage('No results found.', false); return; }
        var self = this;
        members.forEach(function (m) { self.body.appendChild(self.renderRow(m)); });
    };

    PaginatedList.prototype._appendRows = function (members) {
        if (!this.body) return;
        var self = this;
        members.forEach(function (m) { self.body.appendChild(self.renderRow(m)); });
    };

    PaginatedList.prototype._setBodyMessage = function (msg, isError) {
        if (!this.body) return;
        this.body.replaceChildren();
        var row = document.createElement('tr');
        var cell = document.createElement('td');
        var table = this.body.closest('table');
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
        return this.loadedCount < this.total;
    };

    /* ── Controls: Load More + Page Navigation ── */

    PaginatedList.prototype._renderControls = function () {
        this._clearControls();
        if (!this.loadMoreHost || this.total === 0) return;

        // ── Load More bar ──
        var lm = document.createElement('div');
        lm.className = 'flex items-center justify-between mt-3 px-4 py-2 flex-wrap gap-2';

        var status = document.createElement('span');
        status.className = 'text-xs text-slate-500';
        status.textContent = 'Showing ' + this.loadedCount + ' of ' + this.total + ' members';
        lm.appendChild(status);

        if (this._hasMore()) {
            var self = this;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'px-4 py-2 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 font-semibold text-sm transition';
            btn.textContent = 'Load More (' + (this.total - this.loadedCount) + ' remaining)';
            btn.addEventListener('click', function () {
                btn.disabled = true; btn.textContent = 'Loading…';
                self.load(self.page + 1, true);
            });
            lm.appendChild(btn);
        } else {
            lm.appendChild(document.createTextNode(''));
            var done = document.createElement('span');
            done.className = 'text-xs text-emerald-600 font-semibold';
            done.textContent = '✓ All ' + this.total + ' loaded';
            lm.appendChild(done);
        }
        this.loadMoreHost.appendChild(lm);

        // ── Page navigation ──
        if (this.pageNavHost && this.pages > 1) {
            var nav = document.createElement('div');
            nav.className = 'flex items-center justify-center gap-1.5 mt-3 pb-2 flex-wrap';

            var self2 = this;

            // Previous button
            var prev = this._navButton('‹ Previous', this.page > 1);
            if (prev) prev.addEventListener('click', function () { self2.goToPage(self2.page - 1); });
            if (prev) nav.appendChild(prev);

            // Page numbers with ellipsis
            var pages = this._pageNumbers();
            var self3 = this;
            pages.forEach(function (p) {
                if (p === '…') {
                    var ell = document.createElement('span');
                    ell.className = 'px-2 py-1 text-xs text-slate-400';
                    ell.textContent = '…';
                    nav.appendChild(ell);
                } else {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    var active = p === self3.page;
                    btn.className = active
                        ? 'min-w-[36px] px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-semibold text-xs'
                        : 'min-w-[36px] px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold text-xs transition';
                    btn.textContent = String(p);
                    if (!active) btn.addEventListener('click', function () { self3.goToPage(p); });
                    else btn.disabled = true;
                    nav.appendChild(btn);
                }
            });

            // Next button
            var next = this._navButton('Next ›', this.page < this.pages);
            if (next) next.addEventListener('click', function () { self2.goToPage(self2.page + 1); });
            if (next) nav.appendChild(next);

            this.pageNavHost.appendChild(nav);
        }
    };

    PaginatedList.prototype._navButton = function (label, enabled) {
        if (!enabled) return null;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold text-xs transition';
        btn.textContent = label;
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
