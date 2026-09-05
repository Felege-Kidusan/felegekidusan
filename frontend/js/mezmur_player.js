/**
 * Mezmur web player — isolated playback chrome.
 *
 * Why a native <audio> engine, not Howler / Plyr / wavesurfer:
 *   those libraries use Web Audio, which REQUIRES CORS on the media
 *   host. Our R2 bucket CORS is PUT-only (upload). A media-element
 *   `src` does not need CORS. Same split major streaming apps use
 *   on the web: HTMLMediaElement for bytes, custom chrome for
 *   controls, Media Session API for OS keys / lock screen.
 *
 * This file owns ONLY playback UI. Upload/replace/remove stay in
 * mezmur.js. Backend contract is unchanged: GET audio_stream + get.
 * The internal object key never leaves the server.
 *
 * Scale: the queue is the current library page (≤ 25 ready hymns),
 * never the whole catalog. Signed URLs are cached until ~60 s before
 * expiry; the next track is prefetched near the end of the current one.
 */
(function (global) {
    'use strict';

    var PREF_VOL = 'mz.player.vol';
    var PREF_MUTE = 'mz.player.muted';
    var PREF_REPEAT = 'mz.player.repeat';
    var PREF_SHUFFLE = 'mz.player.shuffle';
    var PREF_RATE = 'mz.player.rate';
    var SKIP_S = 15;
    var RESTART_S = 3;
    var RATES = [1, 1.25, 1.5, 0.75];
    var ART = [
        ['#5A1212', '#D4AF37'], ['#4f46e5', '#7c3aed'], ['#0ea5e9', '#2563eb'],
        ['#059669', '#0d9488'], ['#d97706', '#dc2626'], ['#db2777', '#9333ea']
    ];

    var deps = { get: null, post: null, toast: null, onTrack: null };
    var engine = null;
    var state = {
        queue: [],
        index: -1,
        hymn: null,
        repeat: 0,
        shuffle: false,
        order: [],
        seeking: false,
        seq: 0,
        lrc: [],
        lrcIdx: -1,
        rate: 1,
        panel: '',
        cache: {}
    };

    function $(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function toast(msg, kind) {
        if (deps.toast) deps.toast(msg, kind || 'e');
        else if (global.toast) global.toast(msg, kind || 'e');
    }
    function prefGet(k, fallback) {
        try {
            var v = localStorage.getItem(k);
            return v == null ? fallback : v;
        } catch (e) { return fallback; }
    }
    function prefSet(k, v) {
        try { localStorage.setItem(k, String(v)); } catch (e) {}
    }
    function fmtTime(s) {
        if (!isFinite(s) || s < 0) return '0:00';
        s = Math.floor(s);
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        var core = m + ':' + String(sec).padStart(2, '0');
        return h > 0 ? h + ':' + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0') : core;
    }
    function hashCode(str) {
        var h = 0;
        for (var i = 0; i < str.length; i++) h = ((h << 5) - h + str.charCodeAt(i)) | 0;
        return Math.abs(h);
    }
    function artPair(title) {
        return ART[hashCode(String(title || '')) % ART.length];
    }
    function safeImg(url) {
        url = String(url || '').trim();
        if (!url || /["'<>\\\s]/.test(url)) return '';
        if (url.charAt(0) === '/' || /^https?:\/\//i.test(url)) return url;
        return '';
    }
    function singersOf(h) {
        if (!h) return '';
        if (h.zemarians && h.zemarians.length) {
            return h.zemarians.map(function (z) { return z.name; }).filter(Boolean).join(', ');
        }
        return '';
    }
    function catsOf(h) {
        if (!h) return '';
        if (h.categories && h.categories.length) {
            return h.categories.map(function (c) { return c.name; }).filter(Boolean).join(', ');
        }
        return h.category || '';
    }
    function subtitleOf(h) {
        var bits = [];
        var s = singersOf(h); if (s) bits.push(s);
        var c = catsOf(h); if (c) bits.push(c);
        return bits.join(' · ');
    }
    function coverOf(h) {
        if (!h) return '';
        var list = (h.zemarians || []).concat(h.categories || []);
        for (var i = 0; i < list.length; i++) {
            var u = safeImg(list[i] && list[i].image_url);
            if (u) return u;
        }
        return '';
    }
    function inField(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
        if (el.isContentEditable) return true;
        return false;
    }
    function current() {
        if (state.index < 0 || state.index >= state.queue.length) return null;
        var qi = state.order.length === state.queue.length ? state.order[state.index] : state.index;
        return state.queue[qi] || null;
    }

    function showDock() {
        var dock = $('mzPlayer');
        if (!dock) return;
        dock.hidden = false;
        dock.classList.remove('is-hidden');
        document.body.classList.add('mz-playing');
    }

    /**
     * P42: dismiss the dock — stop audio, hide the bar, release the
     * reserved space.
     *
     * `mz-playing` was previously added and NEVER removed, so the
     * content kept its bottom padding for the rest of the session even
     * with nothing playing, and there was no way to get the dock off
     * the screen at all.
     */
    function closeDock() {
        try {
            if (engine) { engine.pause(); engine.currentTime = 0; }
        } catch (e) { /* engine may not exist yet */ }
        var dock = $('mzPlayer');
        if (dock) {
            dock.hidden = true;
            dock.classList.add('is-hidden');
        }
        setPanel('');
        document.body.classList.remove('mz-playing');
        // Return focus somewhere sensible instead of leaving it on a
        // now-hidden button (keyboard users would lose their place).
        var search = document.getElementById('mzSearch');
        if (search) { try { search.focus({ preventScroll: true }); } catch (e) { search.focus(); } }
    }
    function setPanel(which) {
        var np = $('mzNowPlaying');
        if (!np) return;
        var open = !!which;
        state.panel = open ? which : '';
        np.hidden = !open;
        np.classList.toggle('is-hidden', !open);
        np.setAttribute('aria-hidden', open ? 'false' : 'true');
        var lyrics = $('mzNpLyrics'), queue = $('mzNpQueue');
        var tabL = $('mzNpTabLyrics'), tabQ = $('mzNpTabQueue');
        var showQ = which === 'queue';
        if (lyrics) lyrics.classList.toggle('is-hidden', showQ);
        if (queue) queue.classList.toggle('is-hidden', !showQ);
        if (tabL) { tabL.classList.toggle('active', !showQ); tabL.setAttribute('aria-selected', !showQ ? 'true' : 'false'); }
        if (tabQ) { tabQ.classList.toggle('active', showQ); tabQ.setAttribute('aria-selected', showQ ? 'true' : 'false'); }
        var lb = $('mzPLyricsBtn'), qb = $('mzPQueueBtn');
        if (lb) lb.setAttribute('aria-pressed', which === 'lyrics' ? 'true' : 'false');
        if (qb) qb.setAttribute('aria-pressed', which === 'queue' ? 'true' : 'false');
        if (open && which === 'queue') renderQueue();
    }
    function togglePanel(which) {
        setPanel(state.panel === which ? '' : which);
    }

    function paintArt(el, letterEl, h) {
        if (!el) return;
        var img = coverOf(h);
        var g = artPair(h && h.title);
        el.style.backgroundImage = img
            ? ('url("' + img + '")')
            : ('linear-gradient(135deg,' + g[0] + ',' + g[1] + ')');
        el.style.backgroundSize = 'cover';
        el.style.backgroundPosition = 'center';
        if (letterEl) {
            letterEl.textContent = img ? '' : String((h && h.title) || '♪').trim().charAt(0);
            letterEl.classList.toggle('is-hidden', !!img);
        }
    }
    function paintMeta(h) {
        var title = (h && h.title) || '—';
        var sub = subtitleOf(h) || 'Mezmur';
        var t1 = $('mzPTitle'), t2 = $('mzNpTitle');
        var s1 = $('mzPSub'), s2 = $('mzNpSub');
        if (t1) t1.textContent = title;
        if (t2) t2.textContent = title;
        if (s1) s1.textContent = sub;
        if (s2) s2.textContent = sub;
        paintArt($('mzPArtBtn'), $('mzPArtLetter'), h);
        paintArt($('mzNpArt'), $('mzNpArtLetter'), h);
        if (deps.onTrack) deps.onTrack(h && h.id);
        document.querySelectorAll('#mzTbody tr[data-hymn]').forEach(function (tr) {
            tr.classList.toggle('mz-is-playing', h && String(tr.getAttribute('data-hymn')) === String(h.id));
        });
    }
    function paintPlayBtn() {
        var btn = $('mzPPlay');
        if (!btn || !engine) return;
        var playing = !engine.paused;
        btn.setAttribute('aria-label', playing ? 'Pause' : 'Play');
        btn.title = playing ? 'Pause' : 'Play';
        btn.classList.toggle('is-playing', playing);
        var icon = btn.querySelector('i');
        if (icon) icon.className = playing ? 'fa-solid fa-pause' : 'fa-solid fa-play';
        try {
            if (navigator.mediaSession) {
                navigator.mediaSession.playbackState = playing ? 'playing' : 'paused';
            }
        } catch (e) {}
    }
    function paintRepeat() {
        var btn = $('mzPRepeat');
        if (!btn) return;
        btn.setAttribute('data-mode', String(state.repeat));
        btn.setAttribute('aria-pressed', state.repeat > 0 ? 'true' : 'false');
        btn.title = state.repeat === 2 ? 'Repeat one' : state.repeat === 1 ? 'Repeat all' : 'Repeat off';
        var icon = btn.querySelector('i');
        if (icon) icon.className = state.repeat === 2 ? 'fa-solid fa-repeat-1' : 'fa-solid fa-repeat';
        // fa-repeat-1 may be missing on FA 6 free — fall back to badge.
        if (state.repeat === 2) btn.classList.add('mz-repeat-one');
        else btn.classList.remove('mz-repeat-one');
    }
    function paintShuffle() {
        var btn = $('mzPShuffle');
        if (!btn) return;
        btn.setAttribute('aria-pressed', state.shuffle ? 'true' : 'false');
        btn.title = state.shuffle ? 'Shuffle on' : 'Shuffle off';
    }
    function paintRate() {
        var btn = $('mzPRate');
        if (btn) btn.textContent = (state.rate === 1 ? '1×' : String(state.rate) + '×');
    }
    function paintSeek() {
        if (!engine || state.seeking) return;
        var dur = engine.duration;
        var cur = engine.currentTime || 0;
        var seek = $('mzPSeek');
        if (seek && isFinite(dur) && dur > 0) {
            seek.value = String(Math.round((cur / dur) * 1000));
        }
        var c = $('mzPCur'), d = $('mzPDur');
        if (c) c.textContent = fmtTime(cur);
        if (d) d.textContent = isFinite(dur) ? fmtTime(dur) : '0:00';
        try {
            if (navigator.mediaSession && navigator.mediaSession.setPositionState && isFinite(dur) && dur > 0) {
                navigator.mediaSession.setPositionState({ duration: dur, playbackRate: engine.playbackRate || 1, position: Math.min(cur, dur) });
            }
        } catch (e) {}
        syncLrc(cur);
        maybePrefetch(cur, dur);
    }
    function paintMute() {
        var btn = $('mzPMute');
        if (!btn || !engine) return;
        var muted = !!engine.muted || engine.volume === 0;
        var icon = btn.querySelector('i');
        if (icon) icon.className = muted ? 'fa-solid fa-volume-xmark' : 'fa-solid fa-volume-high';
        btn.setAttribute('aria-pressed', muted ? 'true' : 'false');
        btn.title = muted ? 'Unmute' : 'Mute';
    }

    function parseLrc(src) {
        var lines = [];
        String(src || '').split(/\r?\n/).forEach(function (raw) {
            var m = raw.match(/^\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]\s*(.*)$/);
            if (!m) return;
            var ms = ((+m[1]) * 60 + (+m[2])) * 1000 + parseInt(String(m[3] || '0').padEnd(3, '0'), 10);
            lines.push({ t: ms / 1000, text: m[4] });
        });
        return lines;
    }
    function staticLyricsHtml(src) {
        var txt = esc(src == null ? '' : String(src));
        if (!txt.trim()) {
            return '<div class="mz-np-empty">No lyrics recorded for this hymn.</div>';
        }
        var out = [], buf = [];
        function flush() {
            if (!buf.length) return;
            out.push('<p class="mz-np-stanza">' + buf.join('<br>') + '</p>');
            buf = [];
        }
        txt.split(/\r?\n/).forEach(function (raw) {
            var line = raw.trim();
            if (line === '') { flush(); return; }
            var m = line.match(/^\[(.+)\]$/);
            if (m) {
                flush();
                out.push('<div class="mz-np-sec">' + m[1] + '</div>');
                return;
            }
            buf.push(line
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/__(.+?)__/g, '<u>$1</u>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>'));
        });
        flush();
        return out.join('');
    }
    function renderLyrics(h) {
        var box = $('mzNpLyrics');
        if (!box) return;
        state.lrc = parseLrc(h && h.lyrics_synced);
        state.lrcIdx = -1;
        if (state.lrc.length) {
            box.innerHTML = state.lrc.map(function (ln, i) {
                return '<div class="mz-lrc-line" data-i="' + i + '">' + esc(ln.text || ' ') + '</div>';
            }).join('');
            return;
        }
        box.innerHTML = staticLyricsHtml(h && h.lyrics);
    }
    function syncLrc(cur) {
        if (!state.lrc.length) return;
        var idx = -1;
        for (var i = 0; i < state.lrc.length; i++) {
            if (state.lrc[i].t <= cur + 0.05) idx = i;
            else break;
        }
        if (idx === state.lrcIdx) return;
        state.lrcIdx = idx;
        var box = $('mzNpLyrics');
        if (!box) return;
        var lines = box.querySelectorAll('.mz-lrc-line');
        for (var j = 0; j < lines.length; j++) {
            lines[j].classList.toggle('active', j === idx);
            lines[j].classList.toggle('past', j < idx);
        }
        if (idx >= 0 && lines[idx] && typeof lines[idx].scrollIntoView === 'function') {
            lines[idx].scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }
    function renderQueue() {
        var box = $('mzNpQueue');
        if (!box) return;
        if (!state.queue.length) {
            box.innerHTML = '<div class="mz-np-empty">Play a hymn from the library to build a queue.</div>';
            return;
        }
        var curId = state.hymn && state.hymn.id;
        var seq = (state.order.length === state.queue.length)
            ? state.order
            : state.queue.map(function (_, i) { return i; });
        box.innerHTML = seq.map(function (qi, i) {
            var h = state.queue[qi] || {};
            var active = curId && Number(h.id) === Number(curId);
            return '<button type="button" class="mz-q-item' + (active ? ' active' : '') + '" data-q="' + qi + '">' +
                '<span class="mz-q-num">' + (i + 1) + '</span>' +
                '<span class="mz-q-body"><span class="mz-q-title amharic">' + esc(h.title || 'Hymn') + '</span>' +
                '<span class="mz-q-sub">' + esc(subtitleOf(h) || '') + '</span></span>' +
                (active ? '<i class="fa-solid fa-volume-high" aria-hidden="true"></i>' : '') +
                '</button>';
        }).join('');
    }

    function rebuildOrder(keepId) {
        var n = state.queue.length;
        var order = [];
        for (var i = 0; i < n; i++) order.push(i);
        if (state.shuffle && n > 1) {
            for (var j = n - 1; j > 0; j--) {
                var k = Math.floor(Math.random() * (j + 1));
                var tmp = order[j]; order[j] = order[k]; order[k] = tmp;
            }
            if (keepId) {
                var pos = -1;
                for (var p = 0; p < n; p++) {
                    if (Number(state.queue[order[p]].id) === Number(keepId)) { pos = p; break; }
                }
                if (pos > 0) {
                    var hold = order[pos];
                    order.splice(pos, 1);
                    order.unshift(hold);
                }
            }
        }
        state.order = order;
        if (keepId) {
            for (var x = 0; x < order.length; x++) {
                if (Number(state.queue[order[x]].id) === Number(keepId)) { state.index = x; break; }
            }
        }
    }

    function mediaSession(h) {
        if (!navigator.mediaSession) return;
        try {
            var art = coverOf(h);
            navigator.mediaSession.metadata = new MediaMetadata({
                title: (h && h.title) || 'Mezmur',
                artist: singersOf(h) || 'Mezmur',
                album: catsOf(h) || 'Hymn library',
                artwork: art ? [{ src: art, sizes: '300x300' }] : []
            });
        } catch (e) {}
    }
    function bindMediaSession() {
        if (!navigator.mediaSession || !navigator.mediaSession.setActionHandler) return;
        var map = {
            play: function () { playPause(true); },
            pause: function () { playPause(false); },
            previoustrack: function () { prev(); },
            nexttrack: function () { next(); },
            seekbackward: function (d) { seekBy(-((d && d.seekOffset) || SKIP_S)); },
            seekforward: function (d) { seekBy((d && d.seekOffset) || SKIP_S); },
            seekto: function (d) {
                if (d && typeof d.seekTime === 'number') seekTo(d.seekTime);
            }
        };
        Object.keys(map).forEach(function (k) {
            try { navigator.mediaSession.setActionHandler(k, map[k]); } catch (e) {}
        });
    }

    function streamFor(id, hymn) {
        var c = state.cache[id];
        if (c && c.exp - Date.now() > 60000 && c.url) {
            return Promise.resolve(c);
        }
        if (!deps.get) {
            return Promise.resolve({ url: (hymn && hymn.audio_url) || '', exp: 0 });
        }
        return deps.get('action=audio_stream&id=' + encodeURIComponent(id)).then(function (s) {
            if (s && s.status === 'success' && s.url) {
                var exp = Date.now() + ((s.expires_in || 3600) * 1000);
                var rec = { url: s.url, exp: exp };
                state.cache[id] = rec;
                return rec;
            }
            var fallback = (hymn && hymn.audio_url) || '';
            if (fallback) return { url: fallback, exp: Date.now() + 300000 };
            throw new Error((s && s.message) || 'Could not get a playback URL.');
        });
    }
    function maybePrefetch(cur, dur) {
        if (!isFinite(dur) || dur <= 0 || cur / dur < 0.75) return;
        var nxt = peekNext();
        if (!nxt || state.cache[nxt.id]) return;
        streamFor(nxt.id, nxt).catch(function () {});
    }
    function peekNext() {
        if (!state.queue.length) return null;
        if (state.repeat === 2) return current();
        var ni = state.index + 1;
        if (ni >= state.queue.length) {
            if (state.repeat === 1) ni = 0;
            else return null;
        }
        var qi = state.order.length === state.queue.length ? state.order[ni] : ni;
        return state.queue[qi] || null;
    }

    function attachSrc(url) {
        if (!engine || !url) return;
        engine.pause();
        engine.src = url;
        engine.playbackRate = state.rate;
        engine.load();
    }
    function playPause(forcePlay) {
        if (!engine) return;
        var want = forcePlay == null ? engine.paused : !!forcePlay;
        if (want) {
            var p = engine.play();
            if (p && p.catch) p.catch(function () { toast('Playback was blocked by the browser. Press play again.', 'e'); });
        } else {
            engine.pause();
        }
        paintPlayBtn();
    }
    function seekTo(t) {
        if (!engine || !isFinite(engine.duration)) return;
        engine.currentTime = Math.max(0, Math.min(engine.duration, t));
        paintSeek();
    }
    function seekBy(delta) {
        seekTo((engine && engine.currentTime || 0) + delta);
    }

    function loadTrack(h, autoplay) {
        if (!h || !h.id) return;
        state.hymn = h;
        state.lrc = [];
        state.lrcIdx = -1;
        showDock();
        paintMeta(h);
        renderLyrics(h);
        renderQueue();
        mediaSession(h);
        var seq = ++state.seq;
        var metaP = deps.get
            ? deps.get('action=get&id=' + encodeURIComponent(h.id)).catch(function () { return null; })
            : Promise.resolve(null);
        var streamP = streamFor(h.id, h);
        metaP.then(function (d) {
            if (seq !== state.seq) return;
            if (d && d.status === 'success' && d.item) {
                state.hymn = d.item;
                paintMeta(d.item);
                renderLyrics(d.item);
                mediaSession(d.item);
            }
        });
        streamP.then(function (rec) {
            if (seq !== state.seq) return;
            attachSrc(rec.url);
            if (autoplay !== false) playPause(true);
        }).catch(function (err) {
            if (seq !== state.seq) return;
            toast((err && err.message) || 'Could not start playback.', 'e');
        });
    }

    function play(id, queue) {
        id = Number(id);
        if (!id) return;
        if (queue && queue.length) {
            state.queue = queue.filter(function (h) { return h && h.id && (h.audio_status == null || h.audio_status === 'ready'); });
        }
        if (!state.queue.length) {
            state.queue = [{ id: id, title: '…', audio_status: 'ready' }];
        }
        var found = -1;
        for (var i = 0; i < state.queue.length; i++) {
            if (Number(state.queue[i].id) === id) { found = i; break; }
        }
        if (found < 0) {
            state.queue.push({ id: id, title: '…', audio_status: 'ready' });
            found = state.queue.length - 1;
        }
        rebuildOrder(id);
        var h = current() || state.queue[found];
        loadTrack(h, true);
    }

    function jump(delta) {
        if (!state.queue.length) return;
        if (delta < 0 && engine && engine.currentTime > RESTART_S) {
            seekTo(0);
            playPause(true);
            return;
        }
        var ni = state.index + delta;
        if (ni < 0) {
            if (state.repeat === 1) ni = state.queue.length - 1;
            else { seekTo(0); playPause(true); return; }
        }
        if (ni >= state.queue.length) {
            if (state.repeat === 1) ni = 0;
            else { playPause(false); return; }
        }
        state.index = ni;
        loadTrack(current(), true);
    }
    function next() { jump(1); }
    function prev() { jump(-1); }

    function onEnded() {
        if (state.repeat === 2) {
            seekTo(0);
            playPause(true);
            return;
        }
        var nxt = peekNext();
        if (nxt) next();
        else paintPlayBtn();
    }
    function onError() {
        var code = (engine && engine.error && engine.error.code) || 0;
        var map = {
            1: 'Playback was aborted.',
            2: 'Network error talking to the media host. Try play again, or re-upload the file.',
            3: 'This file could not be decoded. Re-encode as AAC M4A and upload again.',
            4: 'No supported audio source. Re-upload as mp3 or m4a.'
        };
        toast(map[code] || 'Playback failed. Re-upload the file as mp3 or m4a.', 'e');
        paintPlayBtn();
    }

    function bindDom() {
        engine = $('mzEngine');
        if (!engine) return;
        engine.addEventListener('timeupdate', paintSeek);
        engine.addEventListener('loadedmetadata', function () {
            paintSeek();
            var s = Math.round(engine.duration || 0);
            if (s > 0 && state.hymn && state.hymn.id && deps.post) {
                deps.post({ action: 'audio_set_duration', hymn_id: state.hymn.id, duration_s: s });
            }
        });
        engine.addEventListener('play', paintPlayBtn);
        engine.addEventListener('pause', paintPlayBtn);
        engine.addEventListener('ended', onEnded);
        engine.addEventListener('error', onError);
        engine.addEventListener('volumechange', paintMute);

        click('mzPPlay', function () { playPause(); });
        click('mzPPrev', prev);
        click('mzPNext', next);
        click('mzPBack', function () { seekBy(-SKIP_S); });
        click('mzPFwd', function () { seekBy(SKIP_S); });
        click('mzPShuffle', function () {
            state.shuffle = !state.shuffle;
            prefSet(PREF_SHUFFLE, state.shuffle ? '1' : '0');
            rebuildOrder(state.hymn && state.hymn.id);
            paintShuffle();
        });
        click('mzPRepeat', function () {
            state.repeat = (state.repeat + 1) % 3;
            prefSet(PREF_REPEAT, String(state.repeat));
            paintRepeat();
        });
        click('mzPLyricsBtn', function () { togglePanel('lyrics'); });
        click('mzPQueueBtn', function () { togglePanel('queue'); });
        click('mzPArtBtn', function () { togglePanel(state.panel ? '' : 'lyrics'); });
        click('mzNpClose', function () { setPanel(''); });
        click('mzNpTabLyrics', function () { setPanel('lyrics'); });
        click('mzNpTabQueue', function () { setPanel('queue'); });
        click('mzPRate', function () {
            var i = RATES.indexOf(state.rate);
            state.rate = RATES[(i + 1) % RATES.length];
            if (engine) engine.playbackRate = state.rate;
            prefSet(PREF_RATE, String(state.rate));
            paintRate();
        });
        click('mzPMute', function () {
            if (!engine) return;
            engine.muted = !engine.muted;
            prefSet(PREF_MUTE, engine.muted ? '1' : '0');
            paintMute();
        });
        // P42: dismiss the dock (stops audio, frees the reserved space).
        click('mzPClose', closeDock);


        var seek = $('mzPSeek');
        if (seek) {
            seek.addEventListener('input', function () { state.seeking = true; });
            seek.addEventListener('change', function () {
                state.seeking = false;
                if (!engine || !isFinite(engine.duration) || engine.duration <= 0) return;
                seekTo((Number(seek.value) / 1000) * engine.duration);
            });
        }
        var vol = $('mzPVol');
        if (vol) {
            vol.addEventListener('input', function () {
                if (!engine) return;
                engine.volume = Math.max(0, Math.min(1, Number(vol.value) / 100));
                engine.muted = engine.volume === 0;
                prefSet(PREF_VOL, String(engine.volume));
                prefSet(PREF_MUTE, engine.muted ? '1' : '0');
                paintMute();
            });
        }
        var qbox = $('mzNpQueue');
        if (qbox) {
            qbox.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('[data-q]') : null;
                if (!btn) return;
                var i = Number(btn.getAttribute('data-q'));
                if (!state.queue[i]) return;
                play(state.queue[i].id, state.queue);
            });
        }
        var lbox = $('mzNpLyrics');
        if (lbox) {
            lbox.addEventListener('click', function (e) {
                var line = e.target.closest ? e.target.closest('.mz-lrc-line') : null;
                if (!line || !state.lrc.length) return;
                var i = Number(line.getAttribute('data-i'));
                if (state.lrc[i]) seekTo(state.lrc[i].t);
            });
        }

        document.addEventListener('keydown', onKey, true);
    }
    function click(id, fn) {
        var el = $(id);
        if (el) el.addEventListener('click', fn);
    }
    function onKey(e) {
        if (inField(e.target)) return;
        if (e.key === 'Escape') {
            // P42: layered dismiss — the panel first, then the dock.
            // Extends the EXISTING handler rather than adding a second
            // listener, which would have fired twice and closed both at
            // once. inField() above already protects typing.
            if (state.panel) { setPanel(''); e.stopPropagation(); e.preventDefault(); return; }
            if (document.body.classList.contains('mz-playing')) {
                closeDock(); e.stopPropagation(); e.preventDefault();
            }
            return;
        }
        if (e.altKey || e.metaKey || e.ctrlKey) return;
        if (!$('mzPlayer') || $('mzPlayer').hidden) return;
        switch (e.key) {
            case ' ':
            case 'k':
            case 'K':
                e.preventDefault();
                playPause();
                break;
            case 'ArrowLeft':
                e.preventDefault();
                if (e.shiftKey) prev(); else seekBy(-10);
                break;
            case 'ArrowRight':
                e.preventDefault();
                if (e.shiftKey) next(); else seekBy(10);
                break;
            case 'm':
            case 'M':
                e.preventDefault();
                if (engine) { engine.muted = !engine.muted; paintMute(); }
                break;
            case 'j':
            case 'J':
                e.preventDefault();
                seekBy(-SKIP_S);
                break;
            case 'l':
            case 'L':
                e.preventDefault();
                togglePanel('lyrics');
                break;
        }
    }

    function restorePrefs() {
        var vol = parseFloat(prefGet(PREF_VOL, '1'));
        if (!isFinite(vol)) vol = 1;
        vol = Math.max(0, Math.min(1, vol));
        state.repeat = parseInt(prefGet(PREF_REPEAT, '0'), 10) || 0;
        if (state.repeat < 0 || state.repeat > 2) state.repeat = 0;
        state.shuffle = prefGet(PREF_SHUFFLE, '0') === '1';
        var rate = parseFloat(prefGet(PREF_RATE, '1'));
        state.rate = RATES.indexOf(rate) >= 0 ? rate : 1;
        if (engine) {
            engine.volume = vol;
            engine.muted = prefGet(PREF_MUTE, '0') === '1';
            engine.playbackRate = state.rate;
        }
        var volEl = $('mzPVol');
        if (volEl) volEl.value = String(Math.round(vol * 100));
        paintRepeat();
        paintShuffle();
        paintRate();
        paintMute();
        paintPlayBtn();
    }

    function init(options) {
        deps = options || deps;
        if ($('mzPlayer') && $('mzPlayer').dataset.ready) return;
        if ($('mzPlayer')) $('mzPlayer').dataset.ready = '1';
        bindDom();
        restorePrefs();
        bindMediaSession();
    }

    global.MezmurPlayer = {
        init: init,
        play: play,
        pause: function () { playPause(false); },
        toggle: function () { playPause(); },
        next: next,
        prev: prev,
        seekBy: seekBy,
        setQueue: function (items) {
            state.queue = (items || []).filter(function (h) { return h && h.id; });
            rebuildOrder(state.hymn && state.hymn.id);
        },
        currentId: function () { return state.hymn && state.hymn.id; },
        isPlaying: function () { return !!(engine && !engine.paused); }
    };
})(window);
