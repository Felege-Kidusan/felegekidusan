/*
 * Shared paginated list engine — Google/Meta-style Load More.
 *
 * Replaces the fragile cursor-chain pagination that broke across every
 * member-listing surface. Uses simple OFFSET paging (the API supports it
 * natively via ?page=N), appends rows on "Load More", and tracks position
 * internally so Previous/Next/Load More all work reliably regardless of
 * sort column or filter.
 *
 * Usage:
 *   var list = new PaginatedList({
 *     endpoint: '/admin/api_list_members.php',
 *     bodyElement: document.getElementById('myTableBody'),
 *     renderRow: function(member) { return tr; },
 *     getExtraParams: function() { return {view:'manager'}; },
 *     pageSize: 50,
 *   });
 *   list.load(1); // initial load
 */
(function (global) {
    'use strict';

    function PaginatedList(opts) {
        this.endpoint = opts.endpoint;
        this.body = opts.bodyElement;
        this.renderRow = opts.renderRow;
        this.getParams = opts.getParams || function () { return {}; };
        this.pageSize = opts.pageSize || 50;
        this.onLoaded = opts.onLoaded || null;
        this.loadMoreHost = opts.loadMoreHost || null;

        this.page = 1;
        this.total = 0;
        this.pages = 1;
        this.loadedCount = 0;
        this.request = null;
        this._loadMoreBtn = null;
        this._statusEl = null;
    }

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

    PaginatedList.prototype.load = async function (page, append) {
        if (this.request) this.request.abort();
        this.request = new AbortController();
        if (!append && this.body) {
            this._setBodyMessage('Loading…', false);
            this._removeLoadMore();
        }

        try {
            var includeTotal = !append;
            var response = await fetch(
                this.buildUrl(page, includeTotal),
                { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: this.request.signal }
            );
            var data = await response.json();
            if (!response.ok || data.status !== 'success')
                throw new Error(data.message || 'Failed to load.');

            var members = Array.isArray(data.members) ? data.members : [];
            if (data.total != null) this.total = Number(data.total) || 0;
            if (data.pages != null) this.pages = Number(data.pages) || 1;
            this.page = Number(data.page) || 1;

            if (append) {
                this._appendRows(members);
                this.loadedCount += members.length;
            } else {
                this._replaceRows(members);
                this.loadedCount = members.length;
            }
            this._renderLoadMore();
            if (this.onLoaded) this.onLoaded(data);
        } catch (err) {
            if (err.name === 'AbortError') return;
            this._setBodyMessage('Could not load members.', true);
            this._removeLoadMore();
            console.error('PaginatedList error:', err);
        } finally {
            this.request = null;
        }
    };

    PaginatedList.prototype.reset = function () {
        this.page = 1;
        this.total = 0;
        this.pages = 1;
        this.loadedCount = 0;
        this.load(1, false);
    };

    PaginatedList.prototype._replaceRows = function (members) {
        if (!this.body) return;
        this.body.replaceChildren();
        if (!members.length) {
            this._setBodyMessage('No results found.', false);
            return;
        }
        var self = this;
        members.forEach(function (m) {
            self.body.appendChild(self.renderRow(m));
        });
    };

    PaginatedList.prototype._appendRows = function (members) {
        if (!this.body) return;
        var self = this;
        members.forEach(function (m) {
            self.body.appendChild(self.renderRow(m));
        });
    };

    PaginatedList.prototype._setBodyMessage = function (msg, isError) {
        if (!this.body) return;
        this.body.replaceChildren();
        var row = document.createElement('tr');
        var cell = document.createElement('td');
        cell.colSpan = this.body.closest('table')?.querySelectorAll('th').length || 4;
        cell.className = 'p-4 text-center text-sm ' + (isError ? 'text-red-500' : 'text-slate-400');
        cell.textContent = msg;
        row.appendChild(cell);
        this.body.appendChild(row);
    };

    PaginatedList.prototype._hasMore = function () {
        return this.loadedCount < this.total;
    };

    PaginatedList.prototype._remaining = function () {
        return Math.max(0, this.total - this.loadedCount);
    };

    PaginatedList.prototype._renderLoadMore = function () {
        this._removeLoadMore();

        if (!this.loadMoreHost || this.total === 0) return;

        var wrapper = document.createElement('div');
        wrapper.className = 'flex items-center justify-between mt-3 px-4 py-2';

        var status = document.createElement('span');
        status.className = 'text-xs text-slate-500';
        status.textContent = 'Showing ' + this.loadedCount + ' of ' + this.total + ' members';
        wrapper.appendChild(status);

        if (this._hasMore()) {
            var self = this;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'px-4 py-2 rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 font-semibold text-sm transition';
            btn.textContent = 'Load More (' + this._remaining() + ' more)';
            btn.addEventListener('click', function () {
                btn.disabled = true;
                btn.textContent = 'Loading…';
                self.load(self.page + 1, true);
            });
            wrapper.appendChild(btn);
        } else {
            var done = document.createElement('span');
            done.className = 'text-xs text-emerald-600 font-semibold';
            done.textContent = '✓ All loaded';
            wrapper.appendChild(done);
        }

        this.loadMoreHost.appendChild(wrapper);
        this._loadMoreBtn = wrapper;
    };

    PaginatedList.prototype._removeLoadMore = function () {
        if (this._loadMoreBtn && this._loadMoreBtn.parentNode) {
            this._loadMoreBtn.parentNode.removeChild(this._loadMoreBtn);
        }
        this._loadMoreBtn = null;
    };

    global.PaginatedList = PaginatedList;
})(window);
