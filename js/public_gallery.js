/**
 * Public gallery explorer. Talks only to /public_gallery.php.
 */
(function () {
  'use strict';

  var root = document.getElementById('fkssGallery');
  if (!root) return;

  var api = root.getAttribute('data-api') || '/public_gallery.php';
  var state = {
    album: 0,
    page: 1,
    items: [],
    featured: [],
    albums: [],
    hasMore: false,
    loading: false,
    slide: 0,
    timer: null,
    lb: -1
  };

  var els = {
    hero: root.querySelector('[data-gal=hero]'),
    filters: root.querySelector('[data-gal=filters]'),
    grid: root.querySelector('[data-gal=grid]'),
    more: root.querySelector('[data-gal=more]'),
    status: root.querySelector('[data-gal=status]'),
    empty: root.querySelector('[data-gal=empty]')
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function say(msg, isErr) {
    if (!els.status) return;
    els.status.textContent = msg || '';
    els.status.hidden = !msg;
    els.status.style.color = isErr ? '#991b1b' : '';
  }

  function skeletons(n) {
    var html = '';
    for (var i = 0; i < n; i++) html += '<div class="fkss-gal-skel" aria-hidden="true"></div>';
    return html;
  }

  function imgTag(src, alt, cls) {
    return '<img src="' + esc(src) + '" alt="' + esc(alt) + '" class="' + (cls || '') + '" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
  }

  function bindImg(img) {
    if (!img) return;
    img.addEventListener('load', function () { img.classList.add('ready'); });
    img.addEventListener('error', function () {
      var card = img.closest('.fkss-gal-card');
      if (card) card.classList.add('gone');
      img.remove();
    });
    if (img.complete && img.naturalWidth) img.classList.add('ready');
  }

  async function get(qs) {
    var r = await fetch(api + qs, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
    var ct = r.headers.get('content-type') || '';
    if (!ct.includes('application/json')) throw new Error('bad');
    return r.json();
  }

  function caption(item) {
    return item.caption_am || item.caption || '';
  }

  function renderFilters() {
    if (!els.filters) return;
    var html = '<button type="button" data-album="0" class="' + (state.album === 0 ? 'on' : '') + '">All</button>';
    state.albums.forEach(function (a) {
      html += '<button type="button" data-album="' + a.id + '" class="' + (state.album === a.id ? 'on' : '') + '">' +
        esc(a.name_am || a.name) + ' <small>(' + a.photo_count + ')</small></button>';
    });
    els.filters.innerHTML = html;
    els.filters.hidden = state.albums.length === 0;
  }

  function renderHero() {
    if (!els.hero) return;
    if (!state.featured.length) {
      els.hero.hidden = true;
      els.hero.innerHTML = '';
      return;
    }
    els.hero.hidden = false;
    var it = state.featured[state.slide] || state.featured[0];
    var cap = caption(it);
    var dots = '';
    if (state.featured.length > 1) {
      state.featured.forEach(function (_, i) {
        dots += '<button type="button" data-dot="' + i + '" class="' + (i === state.slide ? 'on' : '') + '" aria-label="Photo ' + (i + 1) + '"></button>';
      });
    }
    els.hero.innerHTML =
      imgTag(it.thumb || it.full, cap) +
      (cap ? '<div class="fkss-gal-cap"><strong class="amharic-text">' + esc(it.caption_am || it.caption) + '</strong>' +
        (it.caption_am && it.caption ? '<span>' + esc(it.caption) + '</span>' : '') + '</div>' : '') +
      (state.featured.length > 1
        ? '<button type="button" class="fkss-gal-nav fkss-gal-prev" data-dir="-1" aria-label="Previous">‹</button>' +
          '<button type="button" class="fkss-gal-nav fkss-gal-next" data-dir="1" aria-label="Next">›</button>' +
          '<div class="fkss-gal-dots">' + dots + '</div>'
        : '');
    var img = els.hero.querySelector('img');
    if (img) {
      img.classList.add('ready');
      img.addEventListener('error', function () {
        state.featured.splice(state.slide, 1);
        if (!state.featured.length) { els.hero.hidden = true; return; }
        state.slide = state.slide % state.featured.length;
        renderHero();
      });
    }
  }

  function renderGrid(append) {
    if (!els.grid) return;
    if (!append) els.grid.innerHTML = '';
    state.items.forEach(function (it, idx) {
      if (append && idx < (state.page - 1) * 18) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'fkss-gal-card';
      btn.setAttribute('data-i', String(idx));
      btn.innerHTML = '<span class="fkss-gal-ph">✝</span>' + imgTag(it.thumb || it.full, caption(it));
      els.grid.appendChild(btn);
      bindImg(btn.querySelector('img'));
    });
    if (els.more) els.more.hidden = !state.hasMore;
    if (els.empty) els.empty.hidden = state.items.length > 0 || state.loading;
  }

  function startSlide() {
    if (state.timer) clearInterval(state.timer);
    if (state.featured.length < 2) return;
    state.timer = setInterval(function () {
      state.slide = (state.slide + 1) % state.featured.length;
      renderHero();
    }, 6000);
  }

  async function boot() {
    if (els.grid) els.grid.innerHTML = skeletons(6);
    say('Loading photos…');
    try {
      var d = await get('?action=boot');
      if (!d || d.status !== 'success') throw new Error('fail');
      state.albums = d.albums || [];
      state.featured = d.featured || [];
      state.items = d.items || [];
      state.hasMore = !!d.has_more;
      state.page = 1;
      renderFilters();
      renderHero();
      startSlide();
      renderGrid(false);
      say(state.items.length ? '' : '');
      if (!state.items.length && !state.featured.length) {
        if (els.empty) els.empty.hidden = false;
      }
    } catch (e) {
      if (els.grid) els.grid.innerHTML = '';
      say(navigator.onLine ? 'Could not load the gallery. Please try again in a moment.' : 'You appear to be offline.', true);
      if (els.empty) {
        els.empty.hidden = false;
        els.empty.textContent = 'Photos will appear here when they are available.';
      }
    }
  }

  async function loadPage(reset) {
    if (state.loading) return;
    state.loading = true;
    if (reset) {
      state.page = 1;
      state.items = [];
      if (els.grid) els.grid.innerHTML = skeletons(6);
    }
    if (els.more) { els.more.disabled = true; els.more.textContent = 'Loading…'; }
    try {
      var d = await get('?action=photos&album=' + encodeURIComponent(state.album) + '&page=' + state.page + '&limit=18');
      if (!d || d.status !== 'success') throw new Error('fail');
      state.items = reset ? (d.items || []) : state.items.concat(d.items || []);
      state.hasMore = !!d.has_more;
      renderGrid(!reset);
    } catch (e) {
      say('Could not load more photos. Check your connection and try again.', true);
    }
    state.loading = false;
    if (els.more) {
      els.more.disabled = false;
      els.more.textContent = 'Show more photos';
      els.more.hidden = !state.hasMore;
    }
  }

  root.addEventListener('click', function (e) {
    var albumBtn = e.target.closest('[data-album]');
    if (albumBtn && els.filters && els.filters.contains(albumBtn)) {
      state.album = parseInt(albumBtn.getAttribute('data-album'), 10) || 0;
      renderFilters();
      loadPage(true);
      return;
    }
    var dir = e.target.closest('[data-dir]');
    if (dir && els.hero && els.hero.contains(dir)) {
      var step = parseInt(dir.getAttribute('data-dir'), 10) || 1;
      state.slide = (state.slide + step + state.featured.length) % state.featured.length;
      renderHero();
      startSlide();
      return;
    }
    var dot = e.target.closest('[data-dot]');
    if (dot && els.hero && els.hero.contains(dot)) {
      state.slide = parseInt(dot.getAttribute('data-dot'), 10) || 0;
      renderHero();
      startSlide();
      return;
    }
    var card = e.target.closest('.fkss-gal-card');
    if (card) {
      openLb(parseInt(card.getAttribute('data-i'), 10) || 0);
    }
  });

  if (els.more) {
    els.more.addEventListener('click', function () {
      if (!state.hasMore) return;
      state.page += 1;
      loadPage(false);
    });
  }

  function ensureLb() {
    var box = document.getElementById('fkssLb');
    if (box) return box;
    box = document.createElement('div');
    box.id = 'fkssLb';
    box.className = 'fkss-lb';
    box.innerHTML = '<button type="button" class="fkss-lb-x" aria-label="Close">×</button>' +
      '<button type="button" class="fkss-lb-p" aria-label="Previous">‹</button>' +
      '<span class="fkss-lb-spin" hidden></span>' +
      '<img alt="">' +
      '<button type="button" class="fkss-lb-n" aria-label="Next">›</button>' +
      '<div class="fkss-lb-cap"></div>';
    document.body.appendChild(box);
    box.querySelector('.fkss-lb-x').addEventListener('click', closeLb);
    box.querySelector('.fkss-lb-p').addEventListener('click', function () { openLb(state.lb - 1); });
    box.querySelector('.fkss-lb-n').addEventListener('click', function () { openLb(state.lb + 1); });
    box.addEventListener('click', function (e) { if (e.target === box) closeLb(); });
    return box;
  }

  function openLb(i) {
    if (!state.items.length) return;
    state.lb = (i + state.items.length) % state.items.length;
    var it = state.items[state.lb];
    var box = ensureLb();
    var img = box.querySelector('img');
    var spin = box.querySelector('.fkss-lb-spin');
    var cap = box.querySelector('.fkss-lb-cap');
    spin.hidden = false;
    img.removeAttribute('src');
    img.alt = caption(it);
    cap.innerHTML = esc(it.caption_am || it.caption || '');
    box.classList.add('open');
    document.body.style.overflow = 'hidden';
    img.onload = function () { spin.hidden = true; };
    img.onerror = function () {
      spin.hidden = true;
      cap.textContent = 'This photo could not be loaded.';
    };
    img.src = it.full || it.thumb;
  }

  function closeLb() {
    var box = document.getElementById('fkssLb');
    if (box) box.classList.remove('open');
    document.body.style.overflow = '';
    state.lb = -1;
  }

  document.addEventListener('keydown', function (e) {
    var box = document.getElementById('fkssLb');
    if (!box || !box.classList.contains('open')) return;
    if (e.key === 'Escape') closeLb();
    if (e.key === 'ArrowLeft') openLb(state.lb - 1);
    if (e.key === 'ArrowRight') openLb(state.lb + 1);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
