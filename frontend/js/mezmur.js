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
    // Inline editors behave like established desktop software: Enter
    // commits, Escape abandons (focus ring already global).
    function initInlineKeys() {
        document.addEventListener('keydown', function (e) {
            var t = e.target;
            if (!t || !t.id) return;
            var isEdit = ['mzMgrEditName', 'mzMgrSubName'].indexOf(t.id) >= 0;
            if (!isEdit) return;
            if (e.key === 'Enter') {
                e.preventDefault();
                var row = t.closest('.mz-mgr-edit');
                if (row) { var btn = row.querySelector('.btn-primary'); if (btn) btn.click(); }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                mgrCancel();
            }
        });
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
    //
    // P43 — DATABASE FLOOD PROTECTION.
    //
    // The debounce alone was not enough. At 160ms a continuous typist
    // issues ~6 requests/second = ~375/minute, which EXCEEDS the
    // server's own 240 reads/minute limit — one fast user could rate-
    // limit themselves out of their own dashboard. Each search also
    // costs several queries (word candidates + COUNT + page), so the
    // real database load was several times the request count.
    //
    // Three layers, the same pattern Google/Algolia use for
    // search-as-you-type:
    //   1. IN-FLIGHT DEDUPE — identical concurrent queries share one
    //      promise instead of hitting the server twice.
    //   2. ABORT SUPERSEDED — when a newer keystroke arrives, the older
    //      request is cancelled, so the server stops working on an
    //      answer nobody will read. This is the big one: without it the
    //      backend computes a full result set for every intermediate
    //      keystroke.
    //   3. CLIENT RATE CAP — a hard ceiling below the server's, so the
    //      UI degrades into a short wait instead of a 429 error.
    var inflight = {};          // query -> { promise, controller }
    var rateWindow = [];        // timestamps of recent GETs
    var RATE_MAX = 90;          // per minute, well under the server's 240
    var RATE_WINDOW_MS = 60000;

    function rateAllows() {
        var now = Date.now();
        // Drop timestamps outside the sliding window.
        while (rateWindow.length && now - rateWindow[0] > RATE_WINDOW_MS) {
            rateWindow.shift();
        }
        if (rateWindow.length >= RATE_MAX) return false;
        rateWindow.push(now);
        return true;
    }

    /** Cancel any in-flight GET whose query is not `keep`. Called when a
     *  newer keystroke supersedes older ones. */
    function abortStaleGets(keep) {
        Object.keys(inflight).forEach(function (k) {
            if (k === keep) return;
            try { if (inflight[k].controller) inflight[k].controller.abort(); } catch (e) {}
            delete inflight[k];
        });
    }

    function apiGet(q, opts) {
        opts = opts || {};
        // Layer 1: an identical request is already running — reuse it
        // rather than asking the database the same question twice.
        if (inflight[q]) return inflight[q].promise;

        if (!rateAllows()) {
            return Promise.reject(new Error(
                'Searching too quickly. Pause for a moment and try again.'));
        }

        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var p = window.api.get('mezmur.php?' + q, controller ? { signal: controller.signal } : undefined);

        var wrapped = new Promise(function (resolve, reject) {
            var done = false;
            var timer = setTimeout(function () {
                if (done) return;
                done = true;
                try { if (controller) controller.abort(); } catch (e) {}
                reject(new Error('The server took too long to answer. Check your connection and retry.'));
            }, GET_TIMEOUT);
            p.then(function (d) {
                if (!done) { done = true; clearTimeout(timer); resolve(d); }
            }).catch(function (e) {
                if (!done) {
                    done = true; clearTimeout(timer);
                    // An abort is a deliberate cancellation, not a
                    // failure — never surface it as an error to the user.
                    if (e && (e.name === 'AbortError' || /abort/i.test(e.message || ''))) {
                        var quiet = new Error('aborted');
                        quiet.aborted = true;
                        reject(quiet);
                    } else {
                        reject(e);
                    }
                }
            });
        });

        // Always clear the slot, success or failure, or a failed query
        // would be permanently un-retryable.
        var release = function () { if (inflight[q] && inflight[q].promise === wrapped) delete inflight[q]; };
        wrapped.then(release, release);

        inflight[q] = { promise: wrapped, controller: controller };
        if (opts.supersede) abortStaleGets(q);
        return wrapped;
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
        // P43: this used to append
        //   "the server backend may be outdated — ask the administrator
        //    to pull the latest code and run sql/024_mezmur_submissions.sql"
        // to ordinary TIMEOUTS. Two problems:
        //   1. It leaked internal deployment detail (file paths, schema
        //      names, the fact that migrations exist) to every user —
        //      useful reconnaissance for an attacker, and alarming for a
        //      person who just has slow Wi-Fi.
        //   2. It was almost always WRONG. A transient timeout is a
        //      network hiccup, not a stale deployment, which is why a
        //      reload makes it disappear.
        // Operators still get the detail — via the ping endpoint and the
        // console — but users get a calm, accurate sentence.
        return '';
    }

    /**
     * P43: is this failure worth one silent automatic retry?
     *
     * A transient timeout or network blip is the common case on mobile
     * data. Retrying once, quietly, turns most of these into a normal
     * result the user never sees — which is why the page "works after a
     * reload". We do the reload for them.
     *
     * Deliberately NOT retried: HTTP 4xx (the request itself is wrong),
     * and anything on a POST (it may have already been applied).
     */
    function isTransient(err) {
        var m = (err && err.message) || '';
        return /took too long|failed to fetch|network|connection error|load failed/i.test(m);
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
    // LYRIC TIMING EDITOR (P44) — tap-to-sync karaoke authoring
    //
    // The database columns, the LRC validator/canonicaliser, and the
    // karaoke renderers in BOTH players already existed. What did not
    // exist was any way to produce a timestamp, so lyrics_synced was
    // always NULL and the feature never ran. This is the authoring half.
    //
    // Interaction model is the industry standard (MiniLyrics, Musixmatch
    // and every LRC tool): play the audio and stamp each line as it is
    // sung. Hand-typing timestamps is not a real option.
    // ══════════════════════════════════════════════════════════
    var sync = { id: 0, lines: [], idx: 0, audio: null, dur: 0, raf: 0 };

    /** mm:ss.mmm — the canonical LRC stamp the server expects. */
    function lrcStamp(sec) {
        if (!isFinite(sec) || sec < 0) sec = 0;
        var ms = Math.round(sec * 1000);
        var m = Math.floor(ms / 60000);
        var ss = Math.floor((ms % 60000) / 1000);
        var mmm = ms % 1000;
        return '[' + String(m).padStart(2, '0') + ':' + String(ss).padStart(2, '0') +
               '.' + String(mmm).padStart(3, '0') + ']';
    }
    function clockText(sec) {
        if (!isFinite(sec) || sec < 0) sec = 0;
        var m = Math.floor(sec / 60);
        var r = sec - m * 60;
        return m + ':' + (r < 10 ? '0' : '') + r.toFixed(1);
    }

    /** Parse existing LRC back into rows so re-editing keeps prior work. */
    function parseExistingLrc(src) {
        var out = [];
        String(src || '').split(/\r?\n/).forEach(function (raw) {
            var m = raw.match(/^\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]\s*(.*)$/);
            if (!m) return;
            var t = (+m[1]) * 60 + (+m[2]) + parseInt(String(m[3] || '0').padEnd(3, '0'), 10) / 1000;
            out.push({ text: m[4], t: t });
        });
        return out;
    }

    /** Static lyrics -> timeable rows. Blank lines and [Section] markers
     *  are dropped: the server rejects non-timestamp lines, and timing a
     *  blank line would be meaningless. */
    function lyricRows(text) {
        return String(text || '').split(/\r?\n/)
            .map(function (l) { return l.trim(); })
            .filter(function (l) { return l !== '' && !/^\[.+\]$/.test(l); })
            .map(function (l) { return { text: l, t: null }; });
    }

    function syncOpen() {
        var id = parseInt(($('mzAudioHymnId') || {}).value, 10) || 0;
        if (!id) { window.toast('Open a hymn first.', 'e'); return; }
        sync.id = id;
        sync.idx = 0;
        syncErr('');
        $('mzSyncHymnId').value = id;

        apiGet('action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (!d || d.status !== 'success' || !d.item) {
                window.toast((d && d.message) || 'Could not load the hymn.', 'e');
                return;
            }
            var h = d.item;
            $('mzSyncHymnName').textContent = h.title || '';
            var existing = parseExistingLrc(h.lyrics_synced);
            var rows = lyricRows(h.lyrics);
            if (!rows.length) {
                window.toast('This hymn has no lyrics to time yet. Add lyrics first.', 'e');
                return;
            }
            // Re-open with previous timings applied where the text still
            // matches, so editing is incremental rather than all-or-nothing.
            if (existing.length) {
                rows.forEach(function (r, i) {
                    if (existing[i] && existing[i].text === r.text) r.t = existing[i].t;
                });
            }
            sync.lines = rows;
            sync.idx = 0;
            renderSyncLines();
            openModalF('mzSyncModal', '#mzSyncPlay');
            syncLoadAudio(id);
        }).catch(function (err) {
            window.toast((err && err.message) || 'Could not load the hymn.', 'e');
        });
    }

    function syncLoadAudio(id) {
        var a = $('mzSyncAudio');
        if (!a) return;
        sync.audio = a;
        apiGet('action=audio_stream&id=' + encodeURIComponent(id)).then(function (s2) {
            if (!s2 || s2.status !== 'success' || !s2.url) {
                syncErr('No audio is attached yet. Upload audio first — timings are meaningless without it.');
                return;
            }
            a.src = s2.url;
            a.load();
        }).catch(function () {
            syncErr('Could not load the audio for this hymn.');
        });
    }

    function syncErr(msg) {
        var el = $('mzSyncError');
        if (!el) return;
        el.textContent = msg || '';
        el.classList.toggle('is-hidden', !msg);
    }

    function renderSyncLines() {
        var box = $('mzSyncLines');
        if (!box) return;
        box.innerHTML = sync.lines.map(function (l, i) {
            var stamped = l.t != null;
            return '<div class="mz-sync-line' + (i === sync.idx ? ' is-current' : '') +
                   (stamped ? ' is-stamped' : '') + '" data-i="' + i + '">' +
                   '<button type="button" class="mz-sync-time" onclick="Mezmur.syncSeekTo(' + i + ')" ' +
                   'title="Jump to this time">' + (stamped ? clockText(l.t) : '—') + '</button>' +
                   '<span class="mz-sync-text amharic">' + esc(l.text) + '</span>' +
                   '<span class="mz-sync-nudge">' +
                   '<button type="button" onclick="Mezmur.syncNudge(' + i + ',-0.2)" aria-label="Earlier by 0.2 seconds"' +
                   (stamped ? '' : ' disabled') + '>−</button>' +
                   '<button type="button" onclick="Mezmur.syncNudge(' + i + ',0.2)" aria-label="Later by 0.2 seconds"' +
                   (stamped ? '' : ' disabled') + '>+</button>' +
                   '</span></div>';
        }).join('');
        var cur = box.querySelector('.mz-sync-line.is-current');
        if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'center' });
        var done = sync.lines.filter(function (l) { return l.t != null; }).length;
        var st = $('mzSyncStatus');
        if (st) st.textContent = done + ' of ' + sync.lines.length + ' lines timed.';
    }

    function syncPlayPause() {
        var a = sync.audio;
        if (!a || !a.src) { syncErr('No audio loaded.'); return; }
        if (a.paused) { a.play().catch(function () { syncErr('Playback was blocked by the browser.'); }); }
        else { a.pause(); }
    }

    /** Stamp the current line at the current playback position. */
    function syncStamp() {
        var a = sync.audio;
        if (!a || !a.src) { syncErr('Load audio before stamping.'); return; }
        if (sync.idx >= sync.lines.length) return;
        sync.lines[sync.idx].t = a.currentTime;
        sync.idx = Math.min(sync.idx + 1, sync.lines.length);
        renderSyncLines();
    }

    function syncBack() {
        if (sync.idx <= 0) return;
        sync.idx--;
        sync.lines[sync.idx].t = null; // re-timing this line
        renderSyncLines();
    }

    function syncNudge(i, delta) {
        if (!sync.lines[i] || sync.lines[i].t == null) return;
        sync.lines[i].t = Math.max(0, sync.lines[i].t + delta);
        renderSyncLines();
    }

    function syncSeekTo(i) {
        var a = sync.audio;
        if (!a || sync.lines[i] == null || sync.lines[i].t == null) return;
        a.currentTime = sync.lines[i].t;
        sync.idx = i;
        renderSyncLines();
    }

    function syncReset() {
        if (!window.confirm('Clear every timing for this hymn?')) return;
        sync.lines.forEach(function (l) { l.t = null; });
        sync.idx = 0;
        renderSyncLines();
    }

    function syncSave() {
        var timed = sync.lines.filter(function (l) { return l.t != null; });
        if (!timed.length) {
            // Saving with nothing timed means "remove timings" — make that
            // explicit rather than silently wiping.
            if (!window.confirm('No lines are timed. Remove synced lyrics and fall back to static lyrics?')) return;
        }
        var lrc = timed
            .slice()
            .sort(function (a, b) { return a.t - b.t; })
            .map(function (l) { return lrcStamp(l.t) + ' ' + l.text; })
            .join('\n');

        var btn = $('mzSyncSave');
        var restore = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…'; }
        apiPost({ action: 'lyrics_synced_save', id: sync.id, lrc: lrc }).then(function (d) {
            if (!d || d.status !== 'success') {
                window.toast((d && d.message) || 'Could not save timings.', 'e');
                return;
            }
            window.toast(d.message || 'Synced lyrics saved.', 's');
            syncClose();
        }).catch(function (err) {
            window.toast((err && err.message) || 'Network error — timings were not saved.', 'e');
        }).then(function () {
            if (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); btn.innerHTML = restore; }
        });
    }

    function syncClose() {
        var a = sync.audio;
        if (a) { try { a.pause(); } catch (e) {} a.removeAttribute('src'); a.load(); }
        if (sync.raf) { cancelAnimationFrame(sync.raf); sync.raf = 0; }
        closeModalF('mzSyncModal');
    }

    /** Keyboard: Space stamps, arrows nudge the audio, Backspace steps
     *  back. Only while the editor is open, and never while typing. */
    document.addEventListener('keydown', function (e) {
        var modalEl = $('mzSyncModal');
        if (!modalEl || !modalEl.classList.contains('show')) return;
        var t = e.target;
        var tag = t && t.tagName ? t.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
        var a = sync.audio;
        if (e.key === ' ') { e.preventDefault(); syncStamp(); }
        else if (e.key === 'Backspace') { e.preventDefault(); syncBack(); }
        else if (e.key === 'ArrowLeft' && a) { e.preventDefault(); a.currentTime = Math.max(0, a.currentTime - 2); }
        else if (e.key === 'ArrowRight' && a) { e.preventDefault(); a.currentTime = a.currentTime + 2; }
        else if (e.key === 'Enter') { e.preventDefault(); syncPlayPause(); }
    });

    // Clock + seek bar, driven by the audio element itself.
    document.addEventListener('DOMContentLoaded', function () {
        var a = $('mzSyncAudio');
        if (!a) return;
        a.addEventListener('timeupdate', function () {
            var c = $('mzSyncClock');
            if (c) c.textContent = clockText(a.currentTime);
            var sk = $('mzSyncSeek');
            if (sk && isFinite(a.duration) && a.duration > 0 && document.activeElement !== sk) {
                sk.value = String(Math.round((a.currentTime / a.duration) * 1000));
            }
        });
        a.addEventListener('play', function () {
            var l = $('mzSyncPlayLabel'); if (l) l.textContent = 'Pause';
        });
        a.addEventListener('pause', function () {
            var l = $('mzSyncPlayLabel'); if (l) l.textContent = 'Play';
        });
        var sk = $('mzSyncSeek');
        if (sk) {
            sk.addEventListener('input', function () {
                if (!isFinite(a.duration) || a.duration <= 0) return;
                a.currentTime = (Number(sk.value) / 1000) * a.duration;
            });
        }
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
        // supersede: this keystroke cancels every older in-flight query.
        apiGet(q, { supersede: true }).catch(function (err) {
            // P43: ONE silent retry for a transient failure. This is what
            // the user was doing manually by reloading the page; doing it
            // automatically (after a short backoff, so we do not hammer a
            // struggling server) turns most blips into a normal result
            // they never see. Aborts and hard errors fall straight
            // through.
            if (err && err.aborted) throw err;
            if (seq !== lib.seq) throw err;
            if (!isTransient(err)) throw err;
            return new Promise(function (res) { setTimeout(res, 600); })
                .then(function () { return apiGet(q); });
        }).then(function (d) {
            if (!d) return;
            if (seq === lib.seq && d.status === 'success') cachePut(q, d);
            applyList(seq, tb, d);
        }).catch(function (err) {
            // P43: an aborted request was cancelled BY US because the
            // user kept typing. It is not a failure and must never paint
            // an error over results that are about to arrive.
            if (err && err.aborted) return;
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
        lib.lastItems = d.items || [];
        renderHymnRows(d.items || []);
        renderLibPagination();
        announceResults();
    }

    /** Escape then wrap the user's search tokens in <mark>. */
    /** P42: tell screen readers what the search produced.
     *
     * The table repainted silently, so a non-sighted user had no way to
     * know whether a query matched anything — the only feedback was
     * visual. role="status" + aria-live="polite" waits for a pause in
     * speech, so it informs without interrupting typing. */
    function announceResults() {
        var el = $('mzSearchStatus');
        if (!el) return;
        var n = lib.total || 0;
        var msg;
        if (!lib.search) {
            msg = n + ' hymn' + (n === 1 ? '' : 's') + ' listed.';
        } else if (n === 0) {
            msg = 'No hymns match “' + lib.search + '”.';
        } else {
            msg = n + ' hymn' + (n === 1 ? '' : 's') + ' match “' + lib.search + '”.';
        }
        // Only speak on change, or the same string is re-announced on
        // every keystroke.
        if (el.textContent !== msg) el.textContent = msg;
    }

    /** P42: fold Amharic homophones exactly like the server's
     *  foldAmharic(), so the letters the server MATCHED are the letters
     *  we highlight. Without this the server returns a ፀሐይ row for a
     *  ጸሀይ query and the row renders with nothing marked, which reads
     *  as "why is this here?". Length-preserving: one syllable maps to
     *  one, so offsets into the original string stay valid. */
    var MZ_FOLD = (function () {
        var fams = [
            ['ሀሁሂሃሄህሆ', ['ሐሑሒሓሔሕሖ', 'ኀኁኂኃኄኅኆ']],
            ['ሰሱሲሳሴስሶ', ['ሠሡሢሣሤሥሦ']],
            ['ጸጹጺጻጼጽጾ', ['ፀፁፂፃፄፅፆ']],
            ['አኡኢኣኤእኦ', ['ዐዑዒዓዔዕዖ']]
        ];
        var m = {};
        fams.forEach(function (f) {
            var canon = Array.from(f[0]);
            f[1].forEach(function (variant) {
                Array.from(variant).forEach(function (ch, i) {
                    if (canon[i]) m[ch] = canon[i];
                });
            });
        });
        return function (s) {
            var out = '';
            for (var i = 0; i < s.length; i++) {
                var c = s.charAt(i);
                out += (m[c] || c);
            }
            return out;
        };
    })();

    /** Escape then wrap the user's search tokens in <mark>. */
    function hi(text) {
        var raw = text == null ? '' : String(text);
        if (!lib.search) return esc(raw);
        var toks = lib.search.split(/\s+/).filter(function (t) { return t.length >= 2; });
        if (!toks.length) return esc(raw);

        // Match against the FOLDED text, then slice the ORIGINAL so the
        // user still sees the real spelling inside <mark>.
        var folded = MZ_FOLD(raw.toLowerCase());
        var re = new RegExp('(' + toks.map(function (t) {
            return MZ_FOLD(t.toLowerCase()).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }).join('|') + ')', 'g');

        var out = '', last = 0, m;
        while ((m = re.exec(folded)) !== null) {
            if (m[0] === '') { re.lastIndex++; continue; }
            out += esc(raw.slice(last, m.index))
                + '<mark>' + esc(raw.slice(m.index, m.index + m[0].length)) + '</mark>';
            last = m.index + m[0].length;
        }
        return out + esc(raw.slice(last));
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
        ['mzSearch', 'mzCategoryFilter', 'mzZemarianFilter', 'mzLengthFilter', 'mzLanguageFilter'].forEach(function (id) {
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
            var playing = window.MezmurPlayer && window.MezmurPlayer.currentId && Number(window.MezmurPlayer.currentId()) === Number(h.id);
            return '<tr data-hymn="' + h.id + '" class="' + (archived ? 'mz-archived' : '') + (playing ? ' mz-is-playing' : '') + '">' +
                '<td style="padding:.65rem .75rem;font-weight:600;color:var(--school-text-bright)">' + hi(h.title) +
                (h.snippet ? '<div class="text-dim" style="font-size:.72rem;font-weight:400;margin-top:2px">' + hi(h.snippet) + '</div>' : '') + '</td>' +
                '<td style="padding:.65rem .75rem">' + catBadges(h) + '</td>' +
                '<td style="padding:.65rem .75rem;color:var(--school-text-dim)">' + fmtDate(h.updated_at) + '</td>' +
                '<td style="padding:.65rem .75rem;text-align:right;white-space:nowrap">' +
                (h.audio_status === 'ready'
                    ? '<button class="btn-secondary btn-sm" title="Play" style="color:var(--school-success,#22c55e)" onclick="Mezmur.audioPlay(' + h.id + ')"><i class="fa-solid fa-circle-play"></i></button> ' +
                      '<button class="btn-secondary btn-sm" title="Manage audio" onclick="Mezmur.audioManage(' + h.id + ')"><i class="fa-solid fa-headphones"></i></button> '
                    : '<button class="btn-secondary btn-sm" title="' + (h.audio_status === 'pending' ? 'Finish audio upload' : 'Attach audio') + '" onclick="Mezmur.audioManage(' + h.id + ')"><i class="fa-solid fa-headphones"></i></button> ') +
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

    /** P37: the filter toolbar is a one-way data flow (the pattern
     *  Google/Meta-scale admin tables use): user events and shortcuts
     *  write ONLY to `lib` (the single source of truth); rendering is
     *  a pure function of (catalog, lib); reconcileFilters() is the
     *  ONE place a stale filter may be dropped — and it says so with
     *  a toast, so the list can never appear to "filter by itself".
     *  (History: P31 removed renderCatalogList but left its call —
     *  the throw was swallowed by an empty catch, so the dropdowns
     *  silently never populated for five patches. Dead calls are now
     *  caught by the behavioral test, not just syntax checks.) */
    function loadCatalog() {
        var catsP = apiGet('action=categories').then(function (d) {
            return d && d.status === 'success' ? (d.items || []) : null;
        });
        var zemsP = apiGet('action=zemarians').then(function (d) {
            return d && d.status === 'success' ? (d.items || []) : null;
        });
        // P43: RETURN the promise. Callers could not previously wait for
        // the refresh, so a repaint scheduled after loadCatalog() ran
        // against stale state — part of why a new row appeared only
        // after a tab switch.
        return Promise.allSettled([catsP, zemsP]).then(function (res) {
            if (res[0].status === 'fulfilled' && res[0].value) catalog.categories = res[0].value;
            if (res[1].status === 'fulfilled' && res[1].value) catalog.zemarians = res[1].value;
            renderCatalogBoxes();
            renderFilterSelects();
            if (reconcileFilters()) loadList();
        }).catch(function (e) {
            console.error('catalog refresh failed', e); // never swallowed silently
        });
    }

    /** Render the filter toolbar from state — PURE: no mutations, no
     *  reloads. Options list active rows only (hidden taxonomy rows
     * cannot filter); the selected value always mirrors `lib`, so the
     * dropdowns can never lie about what is applied. */
    function renderFilterSelects() {
        var sel = $('mzZemarianFilter');
        if (sel) {
            var zems = (catalog.zemarians || []).filter(function (z) {
                return Number(z.is_active) === 1;
            });
            sel.innerHTML = '<option value="">All singers</option>' + zems.map(function (z) {
                return '<option value="' + Number(z.id) + '">' + esc(z.name) +
                    ' (' + (z.hymn_count || 0) + ')</option>';
            }).join('');
            sel.value = lib.zemarianId > 0 ? String(lib.zemarianId) : '';
        }
        var csel = $('mzCategoryFilter');
        if (csel) {
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
            csel.innerHTML = html;
            csel.value = lib.categoryId > 0 ? String(lib.categoryId) : '';
        }
    }

    function activeCategory(id) {
        return (catalog.categories || []).some(function (c) {
            return Number(c.id) === Number(id) && Number(c.is_active) === 1;
        });
    }
    function activeZemarian(id) {
        return (catalog.zemarians || []).some(function (z) {
            return Number(z.id) === Number(id) && Number(z.is_active) === 1;
        });
    }

    /** The ONLY place a filter may be dropped automatically: when the
     *  row it points at no longer exists / was hidden. Always visible
     *  (toast) — a silent change is what made filtering look broken. */
    function reconcileFilters() {
        var dropped = [];
        if (lib.categoryId > 0 && !activeCategory(lib.categoryId)) {
            lib.categoryId = 0; lib.category = '';
            dropped.push('category');
        }
        if (lib.zemarianId > 0 && !activeZemarian(lib.zemarianId)) {
            lib.zemarianId = 0;
            dropped.push('singer');
        }
        if (!dropped.length) return false;
        renderFilterSelects();
        window.toast('Filter cleared — the ' + dropped.join(' and ') +
            ' you had selected is no longer available.', 'i');
        return true;
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
        // P36 audit: MAIN categories carry hymn_count_total (rolled-up);
        // singers have neither parent_id nor hymn_count_total — they fell
        // through to a hardcoded 0. Fall back to their own hymn_count.
        var count = item.parent_id == null
            ? (item.hymn_count_total != null ? item.hymn_count_total : (item.hymn_count || 0))
            : (item.hymn_count || 0);
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
    /** View-modal shortcuts write state, then the SAME pipeline the
     *  dropdowns use renders it — one path, one truth. A shortcut to
     *  a since-hidden row reconciles visibly instead of filtering
     *  invisibly. */
    function applyFilterState() {
        reconcileFilters();
        renderFilterSelects();
        loadList();
    }
    function browseCategory(id) {
        lib.categoryId = id; lib.zemarianId = 0; lib.page = 1;
        applyFilterState();
    }
    function browseZemarian(id) {
        lib.zemarianId = id; lib.categoryId = 0; lib.page = 1;
        applyFilterState();
    }

    // ── standalone catalog manager (P31: its own section; every edit
    //    is INLINE — no popups, no browser dialogs) ──
    var mgr = { tab: 'categories', edit: null, uploading: 0, open: {} };

    function hymnFormHasDraft() {
        var modal = $('mzHymnModal');
        if (!modal || modal.classList.contains('is-hidden')) return false;
        var t = ($('mzTitle').value || '').trim();
        var body = ($('mzLyrics').value || '').trim();
        return !!(t || body);
    }
    function openCatalog(kind) {
        // navigate to the standalone Catalog section; an open hymn form
        // must close for it — but never silently discard a draft.
        var go = function () {
            closeModalF('mzHymnModal');
            var nav = document.querySelector('.school-nav-link[data-section="catalog"]');
            if (nav) nav.click(); else loadTab('catalog');
            mgrTab(kind || 'categories');
        };
        if (hymnFormHasDraft()) {
            sysConfirm('Leave the hymn form? Unsaved changes will be lost.', go);
        } else {
            go();
        }
    }
    function mgrTab(kind) {
        mgr.tab = kind;
        var c = $('mzMgrCatTabBtn'), z = $('mzMgrZemTabBtn');
        if (c) { c.classList.toggle('active', kind === 'categories'); c.setAttribute('aria-pressed', kind === 'categories' ? 'true' : 'false'); }
        if (z) { z.classList.toggle('active', kind === 'zemarians'); z.setAttribute('aria-pressed', kind === 'zemarians' ? 'true' : 'false'); }
        $('mzMgrCats').classList.toggle('is-hidden', kind !== 'categories');
        $('mzMgrZems').classList.toggle('is-hidden', kind === 'categories');
        renderCatalogManager();
    }

    function mgrCats() { return (catalog.categories || []); }
    function mgrMains() { return mgrCats().filter(function (c) { return c.parent_id == null; }); }
    function mgrSubsOf(id) { return mgrCats().filter(function (c) { return c.parent_id != null && c.parent_id === id; }); }

    function gradOf(item) {
        // P32: an admin-pinned gradient wins; otherwise the automatic
        // name-hashed palette.
        if (item && item.gradient_start && item.gradient_end) {
            return [item.gradient_start, item.gradient_end];
        }
        return PICK_GRADIENTS[hashCode(String((item && item.name) || '')) % PICK_GRADIENTS.length];
    }

    function mgrThumb(item, cls) {
        var img = item.image_url ? ' style="background-image:url(\'' + item.image_url + '\')"' : '';
        var label = item.image_url ? '' : esc((item.name || '?').trim().charAt(0));
        if (!img) {
            var g = gradOf(item);
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
                '<button id="mzMgrEditSave" class="btn-primary btn-sm" onclick="Mezmur.mgrSave(' + item.id + ')" aria-label="Save name"><i class="fa-solid fa-check"></i></button> ' +
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
            // P34: mains only — a chevron expands a main's subs inline
            // (short list; managing/navigating stays easy at any size).
            mgrMains().forEach(function (m) {
                var editing = mgr.edit === 'cat:' + m.id;
                var count = m.hymn_count_total || 0;
                var subs = mgrSubsOf(m.id);
                var open = !!mgr.open[m.id] || mgr.edit === 'addsub:' + m.id;
                var chev = '<button class="btn-secondary btn-sm mz-cmgr-exp' + (open ? ' open' : '') + '"' +
                    ' title="' + (open ? 'Collapse' : 'Expand') + ' sub-categories"' +
                    ' aria-expanded="' + (open ? 'true' : 'false') + '"' +
                    ' aria-label="Sub-categories of ' + esc(m.name) + '"' +
                    ' onclick="Mezmur.mgrToggleOpen(' + m.id + ')"><i class="fa-solid fa-chevron-right"></i></button> ';
                var nameHtml = editing ? mgrNameCell(m, editing, false)
                    : '<div class="mz-mgr-name">' + chev + mgrThumb(m, 'mz-mgr-thumb') +
                      '<span class="mz-mgr-namelabel">' + esc(m.name) +
                      (Number(m.is_active) !== 1 ? ' <span class="text-dim" style="font-size:.68rem">(hidden)</span>' : '') +
                      (subs.length ? ' <span class="text-dim" style="font-size:.7rem">· ' + subs.length + ' sub' + (subs.length === 1 ? '' : 's') + '</span>' : '') +
                      '</span></div>';
                html += '<tr' + (mgr.uploading === m.id ? ' class="mz-mgr-busy"' : '') + '>' +
                    '<td></td>' +
                    '<td>' + nameHtml + '</td>' +
                    '<td class="text-dim">' + count + '</td>' +
                    '<td>' + mgrSortButtons('cat', m.id) + '</td>' +
                    '<td>' + mgrCatActions(m) + '</td></tr>';
                if (!open) return; // collapsed: subs hidden until asked
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
                        '<button id="mzMgrSubAdd" class="btn-primary btn-sm" onclick="Mezmur.mgrAddSub(' + m.id + ')"><i class="fa-solid fa-check"></i> Add</button> ' +
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
                      '<input id="mzMgrEditName" class="school-input amharic" maxlength="100" value="' + esc(z.name) + '">' +
                      '<button id="mzMgrEditSave" class="btn-primary btn-sm" onclick="Mezmur.mgrSave(' + z.id + ')" aria-label="Save name"><i class="fa-solid fa-check"></i></button> ' +
                      '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrCancel()"><i class="fa-solid fa-xmark"></i></button></div>'
                    : '<span style="' + (hidden ? 'opacity:.5;' : '') + 'font-size:.84rem;font-weight:600">' + esc(z.name) +
                      (hidden ? ' <span class="text-dim" style="font-size:.68rem">(hidden)</span>' : '') + '</span>';
                return '<tr' + (mgr.uploading === z.id ? ' class="mz-mgr-busy"' : '') + '><td>' + mgrThumb(z, 'mz-mgr-thumb') + '</td>' +
                    '<td>' + nameCell + '</td>' +
                    '<td class="text-dim">' + (z.hymn_count || 0) + '</td>' +
                    '<td>' +
                    (editing ? '' :
                    '<button class="btn-secondary btn-sm" title="Rename" onclick="Mezmur.mgrEdit(' + z.id + ')"><i class="fa-solid fa-pen"></i></button> ' +
                    '<button class="btn-secondary btn-sm" title="Set cover image" onclick="Mezmur.mgrImage(' + z.id + ', true)"><i class="fa-solid fa-image"></i></button> ' +
                    (z.image_url ? '<button class="btn-secondary btn-sm" title="Remove cover image" onclick="Mezmur.mgrRemoveZemImage(' + z.id + ')"><i class="fa-solid fa-hide"></i></button> ' : '') +
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
            '<button class="btn-secondary btn-sm" title="Set cover image" onclick="Mezmur.mgrImage(' + item.id + ')"><i class="fa-solid fa-image"></i></button> ' +
            '<button class="btn-secondary btn-sm" title="Cover color" onclick="Mezmur.mgrColors(' + item.id + ')"><i class="fa-solid fa-palette"></i></button> ' +
            (isMain && mainId === undefined
                ? '<button class="btn-secondary btn-sm" title="Add sub-category" onclick="Mezmur.mgrAddSubOpen(' + item.id + ')"><i class="fa-solid fa-plus"></i> Sub</button> ' : '') +
            '<button class="btn-secondary btn-sm" onclick="Mezmur.mgrToggle(' + item.id + ')">' + (hidden ? 'Show' : 'Hide') + '</button></div>';
    }
    function mgrEdit(id) { mgr.edit = 'cat:' + id; if (mgrIsZem(id)) mgr.edit = 'zem:' + id; renderCatalogManager(); }
    function mgrIsZem(id) {
        return (catalog.zemarians || []).some(function (z) { return Number(z.id) === Number(id); }) &&
               !mgrCats().some(function (c) { return Number(c.id) === Number(id); });
    }
    function mgrCancel() { mgr.edit = null; renderCatalogManager(); }
    function mgrAddSubOpen(mainId) {
        mgr.edit = 'addsub:' + mainId;
        mgr.open[mainId] = true; // adding reveals the pane
        renderCatalogManager();
    }
    function mgrToggleOpen(id) {
        mgr.open[id] = !mgr.open[id];
        renderCatalogManager();
    }
    /**
     * P43: run a catalog mutation with REAL feedback.
     *
     * Every add/rename handler used to end `.catch(function () {})` —
     * errors were swallowed entirely. Combined with the fact that
     * loadCatalog() refreshes state but does NOT re-render the catalog
     * manager, the result was the reported symptom: you click Add,
     * nothing visibly happens, and the row only appears after switching
     * tabs and back. Success was invisible and failure was silent.
     *
     * This gives every mutation the four states a user needs:
     *   pending  — the button disables and says "Saving…", so a second
     *              click cannot double-submit
     *   success  — a toast, and an IMMEDIATE re-render
     *   failure  — the real server message, and the input is preserved
     *              so nothing typed is lost
     *   always   — the button is restored, even on an exception
     */
    function mgrMutate(btn, payload, okMsg, onOk) {
        var restore = null;
        if (btn) {
            restore = btn.innerHTML;
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
        }
        var release = function () {
            if (!btn) return;
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            btn.innerHTML = restore;
        };
        return apiPost(payload).then(function (d) {
            if (!d || d.status !== 'success') {
                // Surface what the server actually said (duplicate name,
                // permission, validation) instead of a blank freeze.
                window.toast((d && d.message) || 'Could not save. Please try again.', 'e');
                release();
                return false;
            }
            window.toast(okMsg, 's');
            if (typeof onOk === 'function') onOk();
            release();
            // Refresh state AND repaint the manager — loadCatalog alone
            // does not repaint it, which is why the row used to appear
            // only after a tab switch.
            return loadCatalog().then(function () { renderCatalogManager(); });
        }).catch(function (err) {
            window.toast((err && err.message) || 'Network error — nothing was saved.', 'e');
            release();
            return false;
        });
    }

    function mgrSave(id) {
        var name = ($('mzMgrEditName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        var isZem = mgr.edit === 'zem:' + id;
        var payload = isZem
            ? { action: 'save_zemarian', id: id, name: name, name_am: name } // P35: one Amharic name
            : { action: 'save_category', id: id, name: name, parent_id: mgrParentOf(id) };
        mgrMutate(
            document.getElementById('mzMgrEditSave'),
            payload,
            'Renamed to “' + name + '”.',
            function () { mgr.edit = null; }
        );
    }
    function mgrParentOf(id) {
        var found = mgrCats().filter(function (c) { return Number(c.id) === Number(id); })[0];
        return found && found.parent_id != null ? found.parent_id : '';
    }
    function mgrAddMain() {
        var name = ($('mzMgrMainName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        mgrMutate(
            document.getElementById('mzMgrMainAdd'),
            { action: 'save_category', name: name },
            'Category “' + name + '” added.',
            function () { var f = $('mzMgrMainName'); if (f) f.value = ''; }
        );
    }
    function mgrAddSub(mainId) {
        var name = ($('mzMgrSubName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        mgrMutate(
            document.getElementById('mzMgrSubAdd'),
            { action: 'save_category', name: name, parent_id: mainId },
            'Sub-category “' + name + '” added.',
            function () { mgr.edit = null; var f = $('mzMgrSubName'); if (f) f.value = ''; }
        );
    }
    function mgrAddZem() {
        var name = ($('mzMgrZemName') || {}).value || '';
        name = name.trim();
        if (!name) { window.toast('Name is required.', 'e'); return; }
        // P35: singers carry ONE name, written in Amharic — stored in
        // both name (canonical display/filter field) and name_am.
        mgrMutate(
            document.getElementById('mzMgrZemAdd'),
            { action: 'save_zemarian', name: name, name_am: name },
            'Singer “' + name + '” added.',
            function () { var f = $('mzMgrZemName'); if (f) f.value = ''; }
        );
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
        mgrMutate(null, payload, active ? 'Hidden.' : 'Shown.');
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
    var imgPick = { id: 0, file: null, url: '', kind: 'cat' };

    function mgrRemoveZemImage(id) {
        sysConfirm('Remove the singer\'s cover image?', function () {
            apiPost({ action: 'zemarian_image_remove', id: id }).then(function (d) {
                if (d.status !== 'success') { window.toast(d.message || 'Failed.', 'e'); return; }
                window.toast(d.message || 'Cover image removed.', 's');
                loadCatalog();
            }).catch(function () {});
        });
    }

    function mgrImage(id, zem) {
        var input = $('mzMgrFile');
        if (!input) return;
        input.value = '';
        input.onchange = function () {
            var file = input.files && input.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                window.toast('Image is larger than 2 MB.', 'e');
                return;
            }
            // P32: a real preview BEFORE the upload leaves the device.
            imgPick = { id: id, file: file, url: URL.createObjectURL(file), kind: zem ? 'zem' : 'cat' };
            $('mzImgPreviewImg').src = imgPick.url;
            $('mzImgMeta').textContent = file.name + ' · ' +
                (file.size / 1024).toFixed(0) + ' KB · ' + (file.type || 'image');
            openModalF('mzImageDialog');
        };
        input.click();
    }
    function initImageDialog() {
        var el = $('mzImageDialog');
        if (!el || el.dataset.p32) return;
        el.dataset.p32 = '1';
        $('mzImgUpload').addEventListener('click', function () {
            var file = imgPick.file;
            if (!file) { closeModalF('mzImageDialog'); return; }
            var id = imgPick.id;
            closeModalF('mzImageDialog');
            URL.revokeObjectURL(imgPick.url);
            imgPick = { id: 0, file: null, url: '', kind: 'cat' };
            mgr.uploading = id;
            renderCatalogManager();
            var fd = new FormData();
            fd.append('action', imgPick.kind === 'zem' ? 'zemarian_image' : 'category_image');
            fd.append('id', id);
            fd.append('image', file);
            apiPost(fd).then(function (d) {
                mgr.uploading = 0;
                if (d.status !== 'success') { window.toast(d.message || 'Upload failed.', 'e'); renderCatalogManager(); return; }
                window.toast(d.message || 'Image updated.', 's');
                loadCatalog();
            }).catch(function () { mgr.uploading = 0; renderCatalogManager(); });
        });
        $('mzImgCancel').addEventListener('click', function () {
            URL.revokeObjectURL(imgPick.url);
            imgPick = { id: 0, file: null, url: '', kind: 'cat' };
            closeModalF('mzImageDialog');
        });
    }

    // ── cover color dialog (P32: gradient picker + live preview) ──
    var colorPick = { id: 0, name: '', start: '', end: '', auto: false, hasImage: false, startOp: 100, endOp: 100 };

    // '#rrggbb' + optional alpha pair from a picked opacity (P33).
    function withAlpha(hex, op) {
        hex = String(hex || '#000000').slice(0, 7);
        if (!op || op >= 100) return hex;
        var a = Math.round(255 * op / 100).toString(16).padStart(2, '0');
        return hex + a;
    }
    function opOf(v) {
        v = String(v || '');
        return /^#[0-9a-fA-F]{8}$/.test(v)
            ? Math.round(parseInt(v.slice(7, 9), 16) * 100 / 255)
            : 100;
    }

    function mgrColors(id) {
        var found = mgrCats().filter(function (c) { return Number(c.id) === Number(id); })[0];
        if (!found) return;
        var auto = PICK_GRADIENTS[hashCode(String(found.name || '')) % PICK_GRADIENTS.length];
        colorPick = {
            id: id,
            name: String(found.name || ''),
            start: String(found.gradient_start || auto[0]).slice(0, 7),
            end: String(found.gradient_end || auto[1]).slice(0, 7),
            auto: !found.gradient_start && !found.gradient_end,
            hasImage: !!found.image_url,
            startOp: opOf(found.gradient_start),
            endOp: opOf(found.gradient_end)
        };
        $('mzColorPreviewName').textContent = colorPick.name;
        renderSwatches();
        refreshColorPreview();
        openModalF('mzColorDialog');
    }
    function renderSwatches() {
        var box = $('mzSwatches');
        box.innerHTML = PICK_GRADIENTS.map(function (g, i) {
            var sel = !colorPick.auto && g[0] === colorPick.start && g[1] === colorPick.end;
            return '<button type="button" class="mz-swatch' + (sel ? ' sel' : '') + '"' +
                ' style="background:linear-gradient(135deg,' + g[0] + ',' + g[1] + ')"' +
                ' aria-label="Preset ' + (i + 1) + '"' + (sel ? ' aria-pressed="true"' : ' aria-pressed="false"') +
                ' data-gs="' + g[0] + '" data-ge="' + g[1] + '"></button>';
        }).join('');
        Array.prototype.forEach.call(box.querySelectorAll('.mz-swatch'), function (b) {
            b.addEventListener('click', function () {
                colorPick.start = b.getAttribute('data-gs');
                colorPick.end = b.getAttribute('data-ge');
                colorPick.auto = false;
                renderSwatches();
                refreshColorPreview();
            });
        });
    }
    function refreshColorPreview() {
        var p = $('mzColorPreview');
        var grad = colorPick.auto
            ? PICK_GRADIENTS[hashCode(colorPick.name) % PICK_GRADIENTS.length]
            : [colorPick.start, colorPick.end];
        // P33: the checkerboard sits UNDER the (possibly transparent)
        // gradient, so opacity is visible — exactly like design tools.
        p.classList.add('mz-checker');
        p.style.setProperty('--mz-gs', withAlpha(grad[0], colorPick.auto ? 100 : colorPick.startOp));
        p.style.setProperty('--mz-ge', withAlpha(grad[1], colorPick.auto ? 100 : colorPick.endOp));
        if (colorPick.auto) p.setAttribute('data-auto', '1');
        else { p.removeAttribute('data-auto'); }
        $('mzGradStart').value = grad[0];
        $('mzGradEnd').value = grad[1];
        $('mzGradStartOp').value = colorPick.auto ? 100 : colorPick.startOp;
        $('mzGradEndOp').value = colorPick.auto ? 100 : colorPick.endOp;
        $('mzGradStartOpV').textContent = (colorPick.auto ? 100 : colorPick.startOp) + '%';
        $('mzGradEndOpV').textContent = (colorPick.auto ? 100 : colorPick.endOp) + '%';
        $('mzColorNote').textContent = colorPick.hasImage
            ? 'A cover image is set — the gradient shows only after the image is removed.'
            : 'This gradient shows wherever the category appears without a cover image.';
        $('mzRemoveImg').style.display = colorPick.hasImage ? '' : 'none';
    }
    function initColorDialog() {
        var el = $('mzColorDialog');
        if (!el || el.dataset.p32) return;
        el.dataset.p32 = '1';
        $('mzGradStart').addEventListener('input', function () {
            colorPick.start = this.value; colorPick.auto = false; renderSwatches(); refreshColorPreview();
        });
        $('mzGradEnd').addEventListener('input', function () {
            colorPick.end = this.value; colorPick.auto = false; renderSwatches(); refreshColorPreview();
        });
        $('mzGradStartOp').addEventListener('input', function () {
            colorPick.startOp = Number(this.value); colorPick.auto = false; refreshColorPreview();
        });
        $('mzGradEndOp').addEventListener('input', function () {
            colorPick.endOp = Number(this.value); colorPick.auto = false; refreshColorPreview();
        });
        $('mzGradAuto').addEventListener('click', function () {
            colorPick.auto = true;
            var g = PICK_GRADIENTS[hashCode(colorPick.name) % PICK_GRADIENTS.length];
            colorPick.start = g[0]; colorPick.end = g[1];
            colorPick.startOp = 100; colorPick.endOp = 100;
            renderSwatches(); refreshColorPreview();
        });
        $('mzGradSave').addEventListener('click', function () {
            closeModalF('mzColorDialog');
            apiPost({
                action: 'save_category', id: colorPick.id, name: colorPick.name,
                parent_id: mgrParentOf(colorPick.id),
                gradient_start: colorPick.auto ? '' : withAlpha(colorPick.start, colorPick.startOp),
                gradient_end: colorPick.auto ? '' : withAlpha(colorPick.end, colorPick.endOp)
            }).then(function (d) {
                if (d.status !== 'success') { window.toast(d.message || 'Could not save the color.', 'e'); return; }
                window.toast(d.message || 'Cover color saved.', 's');
                loadCatalog();
            }).catch(function () {});
        });
        $('mzRemoveImg').addEventListener('click', function () {
            sysConfirm('Remove the cover image? The gradient will show instead.', function () {
                closeModalF('mzColorDialog');
                apiPost({ action: 'category_image_remove', id: colorPick.id }).then(function (d) {
                    if (d.status !== 'success') { window.toast(d.message || 'Failed.', 'e'); return; }
                    window.toast(d.message || 'Cover image removed.', 's');
                    loadCatalog();
                }).catch(function () {});
            });
        });
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
        subSel.innerHTML = '<option value="">— Select sub-category —</option>' + subs.map(function (sb) {
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
        if (!selectedCategoryIds().length) {
            showError($('mzModalError'), 'Choose a category and sub-category for the hymn.');
            return;
        }
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
    // ══════════════════════════════════════════════════════════
    // P0 AUDIO MANAGER — attach / play / remove hymn audio.
    // The upload is a DIRECT browser PUT to a short-lived presigned
    // Cloudflare R2 URL (two-phase): PHP only hands out the signed
    // URL and later verifies the object. Shared-hosting upload limits
    // never apply because the bytes never cross PHP.
    // ══════════════════════════════════════════════════════════
    var audioMgr = { id: 0, file: null };

    function openAudio(id) {
        apiGet('action=get&id=' + encodeURIComponent(id)).then(function (d) {
            if (d.status !== 'success' || !d.item) { window.toast(d.message || 'Unable to load this hymn.', 'e'); return; }
            var h = d.item;
            audioMgr.id = h.id;
            audioMgr.file = null;
            $('mzAudioHymnId').value = h.id;
            $('mzAudioHymnName').textContent = h.title || '';
            var meta = [];
            if (h.categories && h.categories.length) meta.push(h.categories.map(function (c) { return c.name; }).join(', '));
            if (h.zemarians && h.zemarians.length) meta.push(h.zemarians.map(function (z) { return z.name; }).join(', '));
            $('mzAudioMeta').textContent = meta.join(' · ');

            var status = h.audio_status || 'none';
            var player = $('mzAudioPlayer');
            var pWrap = $('mzAudioPlayerWrap');
            var err = $('mzAudioErr');
            err.classList.add('is-hidden'); err.textContent = '';
            $('mzAudioFile').value = '';
            var listenBtn = $('mzAudioListenBtn');
            if (listenBtn) listenBtn.classList.add('is-hidden');
            // Always detach the previous object so a stale src cannot
            // sit at 0:00/0:00 after the modal reopens.
            try { player.pause(); } catch (e) {}
            player.removeAttribute('src');
            player.load();

            if (status === 'ready') {
                pWrap.classList.add('is-hidden');
                if (listenBtn) listenBtn.classList.remove('is-hidden');
                $('mzAudioPickLabel').textContent = 'Replace audio';
                $('mzAudioState').textContent = 'Ready · ' + (h.audio_format || '').toUpperCase() +
                    (h.audio_size ? ' · ' + fmtBytes(h.audio_size) : '') +
                    (h.audio_duration_s ? ' · ' + fmtDur(h.audio_duration_s) : '') +
                    ' — listen in the Mezmur player (lyrics, queue, next/previous).';
                $('mzAudioRemoveBtn').classList.remove('is-hidden');
                // P44: timings are only meaningful against real audio.
                var sb = $('mzAudioSyncBtn'); if (sb) sb.classList.remove('is-hidden');
            } else if (status === 'pending') {
                pWrap.classList.add('is-hidden');
                $('mzAudioPickLabel').textContent = 'Upload audio (choose the file)';
                $('mzAudioState').textContent = 'A previous upload was started but never finished, so it was discarded — choosing the file starts a fresh upload (there is no resume).';
                $('mzAudioRemoveBtn').classList.remove('is-hidden');
                var sb2 = $('mzAudioSyncBtn'); if (sb2) sb2.classList.add('is-hidden');
            } else {
                pWrap.classList.add('is-hidden');
                $('mzAudioPickLabel').textContent = 'Choose audio file…';
                $('mzAudioState').textContent = 'No audio yet. Pick an mp3 / m4a / ogg / wav (up to 15 MB) — it is uploaded straight to the media CDN.';
                $('mzAudioRemoveBtn').classList.add('is-hidden');
                var sb3 = $('mzAudioSyncBtn'); if (sb3) sb3.classList.add('is-hidden');
            }
            openModalF('mzAudioModal');
        }).catch(function (err) { window.toast((err && err.message) || 'Connection error.', 'e'); });
    }

    /** Bind a playable URL onto the modal <audio> and start loading. */
    function bindAudioSrc(url) {
        var player = $('mzAudioPlayer');
        if (!player || !url) return;
        player.pause();
        player.src = url;
        player.load();
    }

    /**
     * Mint a short-lived signed GET against the R2 API host. Upload
     * already proved that host works; the public custom-domain URL
     * often 403s (bucket not public / domain not connected), which
     * is why the native player sat at 0:00/0:00 on a Ready hymn.
     */
    function attachAudioStream(h) {
        if (!audioMgr.id) return;
        apiGet('action=audio_stream&id=' + encodeURIComponent(audioMgr.id)).then(function (s) {
            if (s.status === 'success' && s.url) {
                bindAudioSrc(s.url);
                $('mzAudioState').textContent = 'Ready · ' + (h.audio_format || '').toUpperCase() +
                    (h.audio_size ? ' · ' + fmtBytes(h.audio_size) : '') +
                    (h.audio_duration_s ? ' · ' + fmtDur(h.audio_duration_s) : '') +
                    ' — press play.';
                return;
            }
            if (h.audio_url) {
                bindAudioSrc(h.audio_url);
                $('mzAudioState').textContent = 'Ready · ' + (h.audio_format || '').toUpperCase() +
                    ' — using the public media URL.';
                return;
            }
            audioErr(s.message || 'Could not get a playback URL. Re-upload the file, or ask the administrator to check the R2 media settings.');
        }).catch(function (e) {
            if (h.audio_url) { bindAudioSrc(h.audio_url); return; }
            audioErr(((e && e.message) || 'Could not get a playback URL.') + ' Check your connection and retry.');
        });
    }

    function pickAudio() {
        var input = $('mzAudioFile');
        if (!input) return;
        input.click();
    }

    function fmtBytes(b) {
        b = parseInt(b, 10) || 0;
        return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : (b / 1024).toFixed(0) + ' KB';
    }
    function fmtDur(s) {
        s = parseInt(s, 10) || 0;
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    function audioErr(msg) {
        var err = $('mzAudioErr');
        err.textContent = msg; err.classList.remove('is-hidden');
    }

    function uploadAudio(file) {
        if (!audioMgr.id) return;
        var err = $('mzAudioErr');
        err.classList.add('is-hidden'); err.textContent = '';
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        var allowed = ['mp3', 'm4a', 'ogg', 'wav', 'aac', 'opus'];
        if (allowed.indexOf(ext) === -1) { audioErr('Unsupported audio format. Allowed: mp3, m4a, ogg, wav, aac, opus.'); return; }
        if (file.size <= 0 || file.size > 15 * 1024 * 1024) { audioErr('Audio must be between 1 byte and 15 MB.'); return; }

        apiPost({ action: 'audio_presign', hymn_id: audioMgr.id, ext: ext, size: file.size }).then(function (d) {
            if (d.status !== 'success' || !d.upload_url) {
                audioErr(d.message || 'Could not start the upload. If this persists, the R2 media settings may be missing on the server.');
                return;
            }
            var pw = $('mzAudioProgressWrap');
            var bar = $('mzAudioProgressBar');
            var lbl = $('mzAudioProgressLabel');
            pw.classList.remove('is-hidden');
            bar.style.width = '0%'; lbl.textContent = 'Uploading… 0%';

            var xhr = new XMLHttpRequest();
            xhr.open('PUT', d.upload_url, true);
            // Content-Type is SIGNED into the presigned URL, so it must be
            // the server-chosen value, not file.type. Sending anything else
            // makes storage reject the PUT. (Content-Length is signed too,
            // but browsers set it from the real body and forbid overriding
            // it — which is what pins the reserved size.)
            xhr.setRequestHeader('Content-Type', d.content_type || file.type || 'application/octet-stream');
            xhr.upload.onprogress = function (e) {
                if (e.lengthComputable) {
                    var pct = Math.round((e.loaded / e.total) * 100);
                    bar.style.width = pct + '%';
                    lbl.textContent = 'Uploading… ' + pct + '%';
                }
            };
            xhr.onload = function () {
                pw.classList.add('is-hidden');
                if (xhr.status < 200 || xhr.status >= 300) {
                    audioErr('Upload to the media CDN failed (HTTP ' + xhr.status + '). If you are on a private/old browser this may be a browser CORS rule on the bucket — ask the administrator to allow this site in the bucket CORS settings.');
                    return;
                }
                // Phase 2: verify the object landed and mark the hymn ready.
                apiPost({ action: 'audio_confirm', hymn_id: audioMgr.id }).then(function (c) {
                    if (c.status !== 'success') { audioErr(c.message || 'The file uploaded but could not be confirmed. Please retry.'); return; }
                    window.toast('Audio attached — streaming from the CDN.', 's');
                    loadList(); loadStats();
                    openAudio(audioMgr.id);
                    audioPlay(audioMgr.id);
                }).catch(function (e) {
                    audioErr(((e && e.message) || 'Could not confirm the upload.') + ' The file may still be on storage — press the audio button again to check.');
                });
            };
            xhr.onerror = function () {
                pw.classList.add('is-hidden');
                audioErr('Upload failed — network error while talking to the media CDN. Check your connection and retry.');
            };
            xhr.send(file);
        }).catch(function (e) {
            audioErr(((e && e.message) || 'Connection error.') + ' The server may be outdated — ask the administrator to pull the latest code.');
        });
    }

    function initAudioDialog() {
        var el = $('mzAudioModal');
        if (!el || el.dataset.p0) return;
        el.dataset.p0 = '1';
        var player = $('mzAudioPlayer');
        // Save measured duration when the player can read it (once per open).
        player.addEventListener('loadedmetadata', function () {
            var a = this;
            var s = Math.round(a.duration || 0);
            if (s > 0 && audioMgr.id) {
                apiPost({ action: 'audio_set_duration', hymn_id: audioMgr.id, duration_s: s });
            }
        });
        // Surface a real reason instead of a silent 0:00/0:00 player.
        player.addEventListener('error', function () {
            var code = (player.error && player.error.code) || 0;
            var map = {
                1: 'Playback was aborted.',
                2: 'Network error talking to the media host. The file may not be publicly reachable — try Replace audio.',
                3: 'This file could not be decoded. Re-encode as AAC M4A (ffmpeg -c:a aac -b:a 96k -movflags +faststart) and upload again.',
                4: 'No supported audio source. Re-upload as mp3 or m4a.'
            };
            audioErr(map[code] || 'Playback failed. Re-upload the file as mp3 or m4a.');
        });
        $('mzAudioFile').addEventListener('change', function () {
            var file = this.files && this.files[0];
            if (!file) return;
            if (!audioMgr.id) { this.value = ''; return; }
            uploadAudio(file);
        });
        var listenBtn = $('mzAudioListenBtn');
        if (listenBtn) listenBtn.addEventListener('click', function () {
            if (audioMgr.id) audioPlay(audioMgr.id);
        });
    }

    function audioPlay(id) {
        var queue = (lib.lastItems || []).filter(function (h) {
            return h && h.audio_status === 'ready';
        });
        if (window.MezmurPlayer && typeof window.MezmurPlayer.play === 'function') {
            if (Number(window.MezmurPlayer.currentId()) === Number(id)) {
                window.MezmurPlayer.toggle();
                return;
            }
            window.MezmurPlayer.play(id, queue);
            return;
        }
        openAudio(id);
    }

    function removeAudio() {
        if (!audioMgr.id) return;
        sysConfirm('Remove the audio from this hymn? The file is deleted from the media CDN and the hymn returns to lyrics-only.', function () {
            apiPost({ action: 'audio_remove', hymn_id: audioMgr.id }).then(function (d) {
                if (d.status !== 'success') { window.toast(d.message || 'Could not remove audio.', 'e'); return; }
                window.toast(d.message || 'Audio removed.', 's');
                loadList(); loadStats();
                openAudio(audioMgr.id);
            }).catch(function () {});
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var debounce = null;
        $('mzSearch').addEventListener('input', function () {
            clearTimeout(debounce);
            var v = this.value;
            // P43: 250ms, raised from 160ms. Nielsen's 0.1s "instant"
            // threshold applies to FEEDBACK, not to the network round
            // trip — and the input echoes the keystroke instantly either
            // way. 160ms let a continuous typist issue ~375 requests a
            // minute, above the server's own 240/min ceiling; 250ms plus
            // request-supersession keeps a burst of typing to roughly one
            // real query, which is what actually protects the database.
            debounce = setTimeout(function () {
                var t = v.trim();
                // P42: the old rule was `if (t.length === 1) return;`,
                // which ABANDONED the reload. Typing one character (or
                // deleting back to one) left the previous result list
                // frozen on screen with no indication it was stale — the
                // list said one thing, the search box another.
                //
                // The server still ignores 1-char queries (an unindexed
                // '%x%' scan over every hymn), so we do not send one.
                // Instead we treat it as "no filter yet" and show the
                // unfiltered list, which is honest and self-consistent.
                lib.search = (t.length === 1) ? '' : t;
                lib.page = 1;
                loadList();
            }, 250);
        });
        $('mzCategoryFilter').addEventListener('change', function () {
            lib.category = '';
            lib.categoryId = parseInt(this.value, 10) || 0;
            lib.page = 1; loadList();
        });
        $('mzStatusFilter').addEventListener('change', function () { lib.status = this.value; lib.page = 1; loadList(); });
        $('mzZemarianFilter').addEventListener('change', function () {
            lib.zemarianId = parseInt(this.value, 10) || 0; lib.page = 1; loadList();
        });
        $('mzHymnMainCat').addEventListener('change', onHymnMainChange);
        initPickPanels();
        initLyricsEditor();
        initSysDialog();
        initImageDialog();
        initAudioDialog();
        if (window.MezmurPlayer && typeof window.MezmurPlayer.init === 'function') {
            window.MezmurPlayer.init({
                get: apiGet,
                post: apiPost,
                toast: function (m, t) { window.toast(m, t); }
            });
        }
        initColorDialog();
        initInlineKeys();
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
        // P0 audio
        audioManage: openAudio, audioPlay: audioPlay, audioPick: pickAudio, audioRemove: removeAudio,
        closeAudio: function () { closeModalF('mzAudioModal'); },
        clearFilters: clearFilters,
        browseCategory: browseCategory, browseZemarian: browseZemarian,
        openCatalog: openCatalog,
        mgrTab: mgrTab, mgrAddMain: mgrAddMain, mgrAddSubOpen: mgrAddSubOpen, mgrAddSub: mgrAddSub, mgrAddZem: mgrAddZem,
        mgrEdit: mgrEdit, mgrSave: mgrSave, mgrCancel: mgrCancel, mgrToggle: mgrToggle, mgrSort: mgrSort, mgrImage: mgrImage,
        mgrToggleOpen: mgrToggleOpen, mgrRemoveZemImage: mgrRemoveZemImage,
        mgrColors: mgrColors, closeColorDialog: function () { closeModalF('mzColorDialog'); }, closeImageDialog: function () { URL.revokeObjectURL(imgPick.url); imgPick = { id: 0, file: null, url: '' }; closeModalF('mzImageDialog'); },
        closeModal: function () { closeModalF('mzHymnModal'); },
        // P44 lyric timing editor
        syncOpen: syncOpen, syncClose: syncClose, syncSave: syncSave,
        syncStamp: syncStamp, syncPlayPause: syncPlayPause,
        syncNudge: syncNudge, syncSeekTo: syncSeekTo, syncReset: syncReset,
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
