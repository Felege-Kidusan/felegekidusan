/**
 * Super Admin ID card designer.
 * Pick one piece (name, underline, expiry…). Only that piece’s sliders show.
 * Save writes the same numbers the printed card uses.
 */
(function () {
  'use strict';

  var schema = { elements: [], defaults: {} };
  var state = {};
  var side = 'front';
  var selected = 'name';
  var root = null;
  var frame = null;
  var cfg = window.ID_DESIGNER || {};

  function cssName(k) {
    return '--id-' + String(k).replace(/_/g, '-');
  }

  function opacityKey(k) {
    return /_opacity$/.test(k);
  }

  function cssVars() {
    var parts = [];
    Object.keys(state).forEach(function (k) {
      var v = state[k];
      if (typeof v === 'string' && v.charAt(0) === '#') {
        parts.push(cssName(k) + ':' + v);
      } else if (opacityKey(k)) {
        parts.push(cssName(k) + ':' + (Math.max(10, parseInt(v, 10) || 10) / 100));
      } else if (typeof v === 'number' || (typeof v === 'string' && v !== '' && /^-?\d+$/.test(String(v)))) {
        parts.push(cssName(k) + ':' + parseInt(v, 10) + 'px');
      }
    });
    parts.push('--id-sig-head:' + (state.sig_head_size || 140) + 'px');
    parts.push('--id-sig-head-op:' + ((state.sig_head_opacity || 90) / 100));
    parts.push('--id-sig-admin:' + (state.sig_admin_size || 140) + 'px');
    parts.push('--id-sig-admin-op:' + ((state.sig_admin_opacity || 90) / 100));
    parts.push('--id-bg:url(\'' + (cfg.bg || '/admin/id_cards/assets/backgrounds/id_card_bg.jpg') + '\')');
    return parts.join(';');
  }

  function textBag() {
    var bag = {};
    Object.keys(state).forEach(function (k) {
      if (typeof state[k] === 'string' && state[k].charAt(0) !== '#') bag[k] = state[k];
    });
    return bag;
  }

  function pushPreview() {
    if (!frame || !frame.contentWindow) return;
    frame.contentWindow.postMessage(
      { type: 'id-layout', style: cssVars(), pick: selected, texts: textBag() },
      window.location.origin
    );
  }

  function fitFrame() {
    if (!frame) return;
    var box = frame.parentElement;
    if (!box) return;
    var w = box.clientWidth || 0;
    if (w < 80) return;
    var scale = Math.min(1, w / 1011);
    frame.style.transform = 'scale(' + scale + ')';
    box.style.height = Math.round(638 * scale) + 'px';
  }

  function setFrame() {
    if (!frame) return;
    frame.src = '/admin/id_cards/preview.php?side=' + side + '&edit=1&t=' + Date.now();
    frame.onload = function () {
      fitFrame();
      pushPreview();
    };
    fitFrame();
  }

  function toast(msg, ok) {
    if (typeof _toast === 'function') { _toast(msg, ok ? 'ok' : 'err'); return; }
    alert(msg);
  }

  function visibleElements() {
    return (schema.elements || []).filter(function (el) {
      return el.side === side || el.side === 'both';
    });
  }

  function currentElement() {
    var list = visibleElements();
    var found = null;
    list.forEach(function (el) { if (el.id === selected) found = el; });
    return found || list[0] || null;
  }

  function bindInputs(scope) {
    (scope || root).querySelectorAll('[data-k]').forEach(function (inp) {
      inp.addEventListener('input', function () {
        var k = inp.getAttribute('data-k');
        if (inp.getAttribute('data-type') === 'text' || inp.tagName === 'TEXTAREA') {
          state[k] = inp.value;
        } else if (inp.type === 'color') {
          state[k] = inp.value.toUpperCase();
        } else {
          state[k] = parseInt(inp.value, 10);
        }
        var lab = root.querySelector('[data-val="' + k + '"]');
        if (lab) lab.textContent = state[k];
        pushPreview();
      });
    });
  }

  function renderInspector() {
    var box = document.getElementById('idcInspect');
    if (!box) return;
    var el = currentElement();
    if (!el) {
      box.innerHTML = '<p class="idc-note">Pick a piece on the card or from the list.</p>';
      return;
    }
    selected = el.id;
    var html = '<h4>' + el.label + '</h4>';
    (el.controls || []).forEach(function (c) {
      var val = state[c.k];
      if (val === undefined || val === null) val = c.def;
      if (c.type === 'color') {
        html += '<label>' + c.label + '</label><input type="color" value="' + val + '" data-k="' + c.k + '">';
      } else if (c.type === 'text') {
        var safe = String(val == null ? '' : val).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        html += '<label>' + c.label + '</label><input type="text" class="idc-text" value="' + safe + '" data-k="' + c.k + '" data-type="text">';
      } else {
        html += '<label>' + c.label + ' <span class="idc-val" data-val="' + c.k + '">' + val + '</span></label>' +
          '<input type="range" min="' + c.min + '" max="' + c.max + '" value="' + val + '" data-k="' + c.k + '">';
      }
    });
    box.innerHTML = html;
    bindInputs(box);
    root.querySelectorAll('.idc-item').forEach(function (b) {
      b.classList.toggle('on', b.getAttribute('data-id') === selected);
    });
    pushPreview();
  }

  function renderList() {
    var list = document.getElementById('idcList');
    if (!list) return;
    var html = '';
    visibleElements().forEach(function (el) {
      html += '<button type="button" class="idc-item' + (el.id === selected ? ' on' : '') + '" data-id="' + el.id + '">' + el.label + '</button>';
    });
    list.innerHTML = html;
    list.querySelectorAll('.idc-item').forEach(function (btn) {
      btn.addEventListener('click', function () {
        selected = btn.getAttribute('data-id');
        renderInspector();
      });
    });
  }

  function render() {
    root = document.getElementById('idDesigner');
    if (!root) return;
    var first = visibleElements()[0];
    if (first && !visibleElements().some(function (e) { return e.id === selected; })) {
      selected = first.id;
    }
    root.innerHTML =
      '<div class="idc-panel idc-tools">' +
        '<p class="idc-note">Click a piece of the card, or pick it here. Change only that piece. Save applies to every member card.</p>' +
        '<div class="idc-list" id="idcList"></div>' +
        '<div class="idc-inspect" id="idcInspect"></div>' +
        '<div class="idc-actions">' +
          '<button type="button" class="btn btn-outline" id="idcReset">Reset this piece</button>' +
          '<button type="button" class="btn btn-outline" id="idcResetAll">Reset all</button>' +
          '<button type="button" class="btn btn-primary" id="idcSave">Save to all cards</button>' +
        '</div>' +
      '</div>' +
      '<div class="idc-panel idc-preview">' +
        '<div class="idc-tabs">' +
          '<button type="button" class="idc-tab' + (side === 'front' ? ' on' : '') + '" data-side="front">Front</button>' +
          '<button type="button" class="idc-tab' + (side === 'back' ? ' on' : '') + '" data-side="back">Back</button>' +
        '</div>' +
        '<div class="idc-preview-wrap"><div class="idc-frame-box"><iframe id="idcFrame" title="ID card preview"></iframe></div></div>' +
        '<p class="idc-note">This is the real card. Gold outline shows the piece you are editing.</p>' +
      '</div>';

    frame = document.getElementById('idcFrame');
    renderList();
    renderInspector();
    setFrame();
    if (!window.__idcResizeBound) {
      window.__idcResizeBound = true;
      window.addEventListener('resize', function () { fitFrame(); });
    }
    setTimeout(fitFrame, 80);
    setTimeout(fitFrame, 400);

    root.querySelectorAll('.idc-tab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        side = btn.getAttribute('data-side');
        var firstEl = visibleElements()[0];
        if (firstEl) selected = firstEl.id;
        root.querySelectorAll('.idc-tab').forEach(function (b) { b.classList.toggle('on', b === btn); });
        renderList();
        renderInspector();
        setFrame();
      });
    });
    document.getElementById('idcReset').addEventListener('click', function () {
      var el = currentElement();
      if (!el) return;
      (el.controls || []).forEach(function (c) {
        if (schema.defaults && schema.defaults[c.k] !== undefined) state[c.k] = schema.defaults[c.k];
        else if (c.def !== undefined) state[c.k] = c.def;
      });
      renderInspector();
      toast('This piece is back to the school default. Click Save to keep it.', true);
    });
    document.getElementById('idcResetAll').addEventListener('click', function () {
      state = Object.assign({}, schema.defaults || {});
      renderInspector();
      toast('Whole card reset to school defaults. Click Save to keep them.', true);
    });
    document.getElementById('idcSave').addEventListener('click', save);
  }

  async function save() {
    var fd = new FormData();
    fd.append('action', 'save_settings');
    fd.append('settings', JSON.stringify(state));
    fd.append('csrf_token', cfg.csrf || (window.CSRF || ''));
    try {
      var r = await fetch('/admin/api_branding.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      var d = await r.json();
      toast(d.message || 'Saved.', d.status === 'success');
    } catch (e) {
      toast('Could not save. Check your connection.', false);
    }
  }

  function applyLoadedSettings(src) {
    state = Object.assign({}, schema.defaults || {});
    if (!src || typeof src !== 'object') return;
    Object.keys(state).forEach(function (k) {
      if (src[k] !== undefined && src[k] !== null && src[k] !== '') {
        state[k] = src[k];
      }
    });
  }

  async function load() {
    try {
      var r = await fetch('/admin/api_branding.php?action=get_assets', { credentials: 'same-origin' });
      var d = await r.json();
      if (d.status === 'success') {
        if (d.schema && d.schema.elements) schema = d.schema;
        applyLoadedSettings(d.settings);
        (d.assets || []).forEach(function (a) {
          if (a.asset_key === 'card_bg' && a.web_url) cfg.bg = a.web_url;
        });
      }
    } catch (e) {}
    if (!cfg.bg) cfg.bg = '/admin/id_cards/assets/backgrounds/id_card_bg.jpg';
    if (!schema.elements || !schema.elements.length) {
      toast('Could not load the card editor.', false);
      return;
    }
    render();
  }

  window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin) return;
    if (!e.data || e.data.type !== 'id-pick') return;
    var id = e.data.id;
    if (!id) return;
    if (!visibleElements().some(function (el) { return el.id === id; })) return;
    selected = id;
    renderInspector();
  });

  window.bootIdDesigner = load;
  window.reloadIdDesignerPreview = function (bg) {
    if (bg) cfg.bg = bg;
    if (frame) setFrame();
  };
})();
