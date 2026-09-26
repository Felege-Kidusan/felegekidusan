/**
 * Felege Kidusan Sunday School - Public Gallery Explorer
 * Decoupled from public_gallery.php with offline/standalone JSON fallback
 */
(function () {
  'use strict';

  var root = document.getElementById('fkssGallery');
  if (!root) return;

  var api = root.getAttribute('data-api') || 'data/gallery.json';
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

  function imgTag(src, alt, cls, fallback) {
    var extra = fallback ? ' data-fallback="' + esc(fallback) + '"' : '';
    return '<img src="' + esc(src) + '" alt="' + esc(alt) + '" class="' + (cls || '') + '"' + extra + ' loading="lazy" decoding="async">';
  }

  function bindImg(img) {
    if (!img) return;
    function ok() {
      img.classList.add('ready');
      var card = img.closest('.fkss-gal-card');
      if (card) card.classList.remove('broken');
    }
    function fail() {
      var next = img.getAttribute('data-fallback');
      if (next && next !== img.getAttribute('src')) {
        img.removeAttribute('data-fallback');
        img.src = next;
        return;
      }
      var card = img.closest('.fkss-gal-card');
      if (card) card.classList.add('broken');
    }
    img.addEventListener('load', ok);
    img.addEventListener('error', fail);
    if (img.complete) {
      if (img.naturalWidth) ok();
      else fail();
    }
  }

  async function get(qs) {
    try {
      var endpoint = api.indexOf('.json') !== -1 ? api : (api + qs);
      var r = await fetch(endpoint, { headers: { 'Accept': 'application/json' } });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      var ct = r.headers.get('content-type') || '';
      if (ct.includes('application/json') || endpoint.endsWith('.json')) {
        return await r.json();
      }
      throw new Error('Not JSON');
    } catch (err) {
      // Fallback to local mock data file
      if (api !== 'data/gallery.json') {
        var fb = await fetch('data/gallery.json');
        return await fb.json();
      }
      throw err;
    }
  }

  function caption(item) {
    return item.caption_am || item.caption || item.title_am || item.title || '';
  }

  function renderFilters() {
    if (!els.filters) return;
    var html = '<button type="button" data-album="0" class="' + (state.album === 0 ? 'on' : '') + '">All / ሁሉም</button>';
    state.albums.forEach(function (a) {
      html += '<button type="button" data-album="' + a.id + '" class="' + (state.album === a.id ? 'on' : '') + '">' +
        esc(a.name_am || a.name) + ' <small>(' + (a.photo_count || 0) + ')</small></button>';
    });
    els.filters.innerHTML = html;
    els.filters.hidden = state.albums.length === 0;
  }

  function renderGrid(append) {
    if (!els.grid) return;
    var list = state.items;
    if (state.album !== 0) {
      list = state.items.filter(function (it) { return it.album_id === state.album; });
    }

    if (!append && list.length === 0) {
      els.grid.innerHTML = '';
      if (els.empty) els.empty.hidden = false;
      if (els.more) els.more.hidden = true;
      return;
    }
    if (els.empty) els.empty.hidden = true;

    var html = '';
    list.forEach(function (it, idx) {
      var src = it.thumb_url || it.image_url;
      var cap = caption(it);
      html += '<button type="button" class="fkss-gal-card" data-idx="' + idx + '" aria-label="' + esc(cap || 'Photo') + '">' +
        '<span class="fkss-gal-ph"><i class="fa-solid fa-image"></i></span>' +
        imgTag(src, cap, '', it.image_url) +
        '</button>';
    });

    els.grid.innerHTML = html;
    els.grid.querySelectorAll('img').forEach(bindImg);

    if (els.more) {
      els.more.hidden = !state.hasMore;
    }
  }

  function renderHero() {
    if (!els.hero) return;
    if (state.featured.length === 0) {
      els.hero.hidden = true;
      return;
    }
    var it = state.featured[state.slide] || state.featured[0];
    var cap = caption(it);
    var tag = it.album_name_am || it.album_name || 'Featured';
    
    var html = imgTag(it.image_url, cap, '') +
      '<div class="fkss-gal-cap">' +
      '<strong>' + esc(cap) + '</strong>' +
      '<span>' + esc(tag) + '</span>' +
      '</div>';

    if (state.featured.length > 1) {
      html += '<button type="button" class="fkss-gal-nav fkss-gal-prev" aria-label="Previous slide"><i class="fa-solid fa-chevron-left"></i></button>' +
        '<button type="button" class="fkss-gal-nav fkss-gal-next" aria-label="Next slide"><i class="fa-solid fa-chevron-right"></i></button>' +
        '<div class="fkss-gal-dots">';
      for (var i = 0; i < state.featured.length; i++) {
        html += '<button type="button" data-dot="' + i + '" class="' + (i === state.slide ? 'on' : '') + '" aria-label="Slide ' + (i + 1) + '"></button>';
      }
      html += '</div>';
    }

    els.hero.innerHTML = html;
    els.hero.hidden = false;
    bindImg(els.hero.querySelector('img'));
  }

  // Lightbox Implementation
  var lbEl = null;

  function ensureLightbox() {
    if (lbEl) return lbEl;
    lbEl = document.createElement('div');
    lbEl.className = 'fkss-lb';
    lbEl.setAttribute('role', 'dialog');
    lbEl.setAttribute('aria-modal', 'true');
    lbEl.innerHTML = '<button type="button" class="fkss-lb-x" aria-label="Close">&times;</button>' +
      '<button type="button" class="fkss-lb-p" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></button>' +
      '<div class="fkss-lb-spin"></div>' +
      '<img src="" alt="" style="display:none">' +
      '<div class="fkss-lb-cap"></div>' +
      '<button type="button" class="fkss-lb-n" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></button>';
    document.body.appendChild(lbEl);

    lbEl.querySelector('.fkss-lb-x').addEventListener('click', closeLb);
    lbEl.querySelector('.fkss-lb-p').addEventListener('click', function () { navLb(-1); });
    lbEl.querySelector('.fkss-lb-n').addEventListener('click', function () { navLb(1); });
    
    lbEl.addEventListener('click', function (e) {
      if (e.target === lbEl) closeLb();
    });

    document.addEventListener('keydown', function (e) {
      if (!lbEl.classList.contains('open')) return;
      if (e.key === 'Escape') closeLb();
      if (e.key === 'ArrowLeft') navLb(-1);
      if (e.key === 'ArrowRight') navLb(1);
    });

    return lbEl;
  }

  function openLb(idx) {
    var it = state.items[idx];
    if (!it) return;
    state.lb = idx;
    var lb = ensureLightbox();
    var img = lb.querySelector('img');
    var spin = lb.querySelector('.fkss-lb-spin');
    var cap = lb.querySelector('.fkss-lb-cap');

    img.style.display = 'none';
    spin.style.display = 'block';
    cap.textContent = caption(it);

    img.onload = function () {
      spin.style.display = 'none';
      img.style.display = 'block';
    };
    img.onerror = function () {
      spin.style.display = 'none';
    };
    img.src = it.image_url;
    lb.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function navLb(step) {
    var next = state.lb + step;
    if (next < 0) next = state.items.length - 1;
    if (next >= state.items.length) next = 0;
    openLb(next);
  }

  function closeLb() {
    if (lbEl) lbEl.classList.remove('open');
    document.body.style.overflow = '';
  }

  async function boot() {
    if (els.grid) els.grid.innerHTML = skeletons(6);
    try {
      var d = await get('?action=boot');
      if (d.status === 'success') {
        state.albums = d.albums || [];
        state.featured = d.featured || [];
        state.items = d.items || [];
        state.hasMore = !!d.has_more;
        renderHero();
        renderFilters();
        renderGrid(false);
      } else {
        say(d.message || 'Gallery error', true);
      }
    } catch (e) {
      say('Photos unavailable', false);
    }
  }

  // Event Listeners
  if (els.filters) {
    els.filters.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-album]');
      if (!btn) return;
      state.album = parseInt(btn.getAttribute('data-album'), 10) || 0;
      renderFilters();
      renderGrid(false);
    });
  }

  if (els.grid) {
    els.grid.addEventListener('click', function (e) {
      var card = e.target.closest('.fkss-gal-card');
      if (!card || card.classList.contains('broken')) return;
      var idx = parseInt(card.getAttribute('data-idx'), 10);
      openLb(idx);
    });
  }

  if (els.hero) {
    els.hero.addEventListener('click', function (e) {
      var prev = e.target.closest('.fkss-gal-prev');
      var next = e.target.closest('.fkss-gal-next');
      var dot = e.target.closest('[data-dot]');
      if (prev) {
        state.slide = (state.slide - 1 + state.featured.length) % state.featured.length;
        renderHero();
      } else if (next) {
        state.slide = (state.slide + 1) % state.featured.length;
        renderHero();
      } else if (dot) {
        state.slide = parseInt(dot.getAttribute('data-dot'), 10) || 0;
        renderHero();
      }
    });
  }

  boot();
})();
