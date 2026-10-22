/*
 * FKSSPager — the shared pager for lists that do not use PaginatedList
 * (review inbox, teachers, member fees, …). Same look and behaviour as the
 * member lists (Phase 3, owner request 2026-10-22):
 *
 *   Showing 51–100 of 440 packets            Per page [25|50|100|200]
 *   ‹ Previous   Page 2 of 9   Next ›         [Load more (340 left)]
 *
 * - Previous / Next REPLACE the rows; Load more APPENDS the next page.
 * - The per-page choice is remembered per screen (localStorage).
 *
 * Usage:
 *   var p = FKSSPager.create({
 *     host: element,                 // where the bar is drawn
 *     storageKey: 'fkss_pp_reviews', // remembered per-page
 *     noun: 'packets',
 *     sizes: [25, 50, 100, 200], defaultSize: 50,
 *     fetchPage: async function (page, perPage, append) {
 *        return { rows: [...], total: 440, page: 2, totalPages: 9 };
 *     },
 *     render: function (rows, append, firstIndex) { ... }, // firstIndex is 1-based
 *     onError: function (err) { ... }   // optional: failed Previous/Next/Load more
 *   });
 *   p.reset();   // load page 1 (call again when filters change)
 */
(function (global) {
    'use strict';

    var CSS_ID = 'fk-pg-css';
    function injectCss() {
        if (document.getElementById(CSS_ID)) return;
        var st = document.createElement('style');
        st.id = CSS_ID;
        st.textContent =
            '.fk-pg{display:flex;flex-direction:column;gap:.4rem;margin:.6rem 0 .2rem;font-size:.75rem;color:#64748b}' +
            '.fk-pg-row{display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap}' +
            '.fk-pg-row.fk-pg-nav{justify-content:center}' +
            '.fk-pg select{border:1px solid #e2e8f0;border-radius:6px;padding:.2rem .4rem;font-size:.75rem;background:#fff;color:#334155}' +
            '.fk-pg button{border:1px solid #e2e8f0;background:#fff;color:#475569;border-radius:8px;padding:.3rem .7rem;font-weight:600;font-size:.75rem;cursor:pointer}' +
            '.fk-pg button:hover:not(:disabled){background:#f8fafc}' +
            '.fk-pg button:disabled{color:#cbd5e1;cursor:not-allowed}' +
            '.fk-pg button.fk-pg-more{background:#eef2ff;border-color:#e0e7ff;color:#4338ca}' +
            '.fk-pg .fk-pg-done{color:#059669;font-weight:600}';
        (document.head || document.documentElement).appendChild(st);
    }

    function readSaved(key, sizes) {
        try {
            var v = parseInt(global.localStorage.getItem(key), 10);
            return sizes.indexOf(v) >= 0 ? v : null;
        } catch (e) { return null; }
    }

    function Pager(o) {
        this.host = o.host;
        this.sizes = Array.isArray(o.sizes) && o.sizes.length ? o.sizes : [25, 50, 100, 200];
        this.storageKey = o.storageKey || null;
        this.noun = o.noun || 'items';
        this.fetchPage = o.fetchPage;
        this.render = o.render;
        this.onError = o.onError || null;
        this.perPage = (this.storageKey && readSaved(this.storageKey, this.sizes)) || o.defaultSize || 50;
        this.firstPage = 1;
        this.page = 1;
        this.totalPages = 1;
        this.total = 0;
        this.shown = 0;
        this.seq = 0;
        this.busy = false;
    }

    Pager.prototype.reset = function () { return this.load(1, false); };
    Pager.prototype.goTo = function (p) {
        p = Math.min(Math.max(1, p), this.totalPages);
        return this.load(p, false);
    };
    Pager.prototype.more = function () {
        if (this.page >= this.totalPages) return Promise.resolve();
        return this.load(this.page + 1, true);
    };
    Pager.prototype.setPerPage = function (n) {
        n = parseInt(n, 10);
        if (this.sizes.indexOf(n) < 0 || n === this.perPage) return Promise.resolve();
        this.perPage = n;
        if (this.storageKey) { try { global.localStorage.setItem(this.storageKey, String(n)); } catch (e) { /* private mode */ } }
        return this.reset();
    };

    Pager.prototype.load = async function (page, append) {
        var my = ++this.seq;   // a newer request wins (filters typed quickly)
        this.busy = true;
        var res;
        try {
            res = await this.fetchPage(page, this.perPage, !!append);
        } catch (e) {
            if (my === this.seq) { this.busy = false; this.draw(); }
            throw e;
        }
        if (my !== this.seq || !res) return;
        this.busy = false;
        var rows = Array.isArray(res.rows) ? res.rows : [];
        this.total = Math.max(0, Number(res.total) || 0);
        this.totalPages = Math.max(1, Number(res.totalPages) || Math.ceil(this.total / this.perPage) || 1);
        this.page = Math.min(Math.max(1, Number(res.page) || page), this.totalPages);
        var firstIndex;
        if (append) {
            firstIndex = (this.firstPage - 1) * this.perPage + this.shown + 1;
            this.shown += rows.length;
        } else {
            this.firstPage = this.page;
            this.shown = rows.length;
            firstIndex = (this.firstPage - 1) * this.perPage + 1;
        }
        this.render(rows, append, firstIndex);
        this.draw();
    };

    /** Button-started loads: report failures instead of leaving an unhandled rejection. */
    Pager.prototype._safe = function (promise) {
        var self = this;
        if (promise && promise.catch) promise.catch(function (e) {
            if (self.onError) self.onError(e); else console.error('[FKSSPager]', e);
        });
    };

    Pager.prototype.range = function () {
        if (!this.total || !this.shown) return { from: 0, to: 0 };
        var from = (this.firstPage - 1) * this.perPage + 1;
        return { from: from, to: Math.min(this.total, from + this.shown - 1) };
    };

    Pager.prototype.draw = function () {
        if (!this.host) return;
        injectCss();
        var self = this;
        this.host.replaceChildren();
        if (!this.total) return;
        var r = this.range();

        var box = document.createElement('div');
        box.className = 'fk-pg';
        box.setAttribute('data-fk-pager', '');

        var top = document.createElement('div');
        top.className = 'fk-pg-row';
        var status = document.createElement('span');
        status.setAttribute('data-fk-pager-range', '');
        status.textContent = 'Showing ' + r.from + '–' + r.to + ' of ' + this.total + ' ' + this.noun;
        top.appendChild(status);

        var right = document.createElement('span');
        right.className = 'fk-pg-row';
        var lbl = document.createElement('label');
        lbl.appendChild(document.createTextNode('Per page '));
        var sel = document.createElement('select');
        sel.setAttribute('data-fk-pager-size', '');
        sel.setAttribute('aria-label', 'Rows per page');
        this.sizes.forEach(function (n) {
            var op = document.createElement('option');
            op.value = String(n); op.textContent = String(n);
            if (n === self.perPage) op.selected = true;
            sel.appendChild(op);
        });
        sel.addEventListener('change', function () { self._safe(self.setPerPage(sel.value)); });
        lbl.appendChild(sel);
        right.appendChild(lbl);

        if (r.to < this.total) {
            var more = document.createElement('button');
            more.type = 'button';
            more.className = 'fk-pg-more';
            more.setAttribute('data-fk-pager-more', '');
            more.textContent = 'Load more (' + (this.total - r.to) + ' left)';
            more.addEventListener('click', function () {
                more.disabled = true; more.textContent = 'Loading…';
                self._safe(self.more());
            });
            right.appendChild(more);
        } else {
            var done = document.createElement('span');
            done.className = 'fk-pg-done';
            done.setAttribute('data-fk-pager-done', '');
            done.textContent = r.from === 1 ? '✓ All ' + this.total + ' shown' : '✓ End of list';
            right.appendChild(done);
        }
        top.appendChild(right);
        box.appendChild(top);

        if (this.totalPages > 1) {
            var nav = document.createElement('div');
            nav.className = 'fk-pg-row fk-pg-nav';
            nav.setAttribute('data-fk-pager-nav', '');
            var prev = document.createElement('button');
            prev.type = 'button';
            prev.setAttribute('data-fk-pager-prev', '');
            prev.textContent = '‹ Previous';
            prev.disabled = this.firstPage <= 1;
            prev.addEventListener('click', function () { self._safe(self.goTo(self.firstPage - 1)); });
            var pos = document.createElement('span');
            pos.setAttribute('data-fk-pager-pos', '');
            pos.textContent = this.firstPage === this.page
                ? 'Page ' + this.page + ' of ' + this.totalPages
                : 'Pages ' + this.firstPage + '–' + this.page + ' of ' + this.totalPages;
            var next = document.createElement('button');
            next.type = 'button';
            next.setAttribute('data-fk-pager-next', '');
            next.textContent = 'Next ›';
            next.disabled = this.page >= this.totalPages;
            next.addEventListener('click', function () { self._safe(self.goTo(self.page + 1)); });
            nav.appendChild(prev); nav.appendChild(pos); nav.appendChild(next);
            box.appendChild(nav);
        }
        this.host.appendChild(box);
    };

    global.FKSSPager = { create: function (o) { return new Pager(o); } };
})(window);
