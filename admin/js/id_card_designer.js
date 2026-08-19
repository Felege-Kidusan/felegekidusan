/**
 * Super Admin ID card designer.
 * Sliders change the live preview. Save writes the same numbers the printed card uses.
 */
(function () {
  'use strict';

  var DEFAULTS = {
    logo_x: 22, logo_y: 14, logo_size: 118, logo_opacity: 100,
    seal_x: 830, seal_y: 390, seal_size: 150, seal_opacity: 85,
    sig_head_size: 140, sig_head_opacity: 90,
    sig_admin_size: 140, sig_admin_opacity: 90,
    title_size: 24, parish_size: 26, label_size: 20, value_size: 24, code_size: 32,
    bar_height: 38, photo_w: 210, photo_h: 260, qr_size: 220,
    label_color: '#600000', title_color: '#600000', gold_color: '#B8860B',
    bar_color: '#600000', value_color: '#1A0A0A'
  };

  var GROUPS = [
    { title: 'Logo', fields: [
      { k: 'logo_x', label: 'Left', min: 0, max: 900 },
      { k: 'logo_y', label: 'Top', min: 0, max: 500 },
      { k: 'logo_size', label: 'Size', min: 48, max: 260 },
      { k: 'logo_opacity', label: 'Opacity', min: 10, max: 100 }
    ]},
    { title: 'Seal', fields: [
      { k: 'seal_x', label: 'Left', min: 0, max: 940 },
      { k: 'seal_y', label: 'Top', min: 0, max: 560 },
      { k: 'seal_size', label: 'Size', min: 40, max: 280 },
      { k: 'seal_opacity', label: 'Opacity', min: 10, max: 100 }
    ]},
    { title: 'Photo & QR', fields: [
      { k: 'photo_w', label: 'Photo width', min: 120, max: 320 },
      { k: 'photo_h', label: 'Photo height', min: 140, max: 360 },
      { k: 'qr_size', label: 'QR size', min: 120, max: 300 }
    ]},
    { title: 'Text size', fields: [
      { k: 'parish_size', label: 'Parish', min: 14, max: 40 },
      { k: 'title_size', label: 'Title', min: 14, max: 40 },
      { k: 'label_size', label: 'Labels', min: 12, max: 32 },
      { k: 'value_size', label: 'Values', min: 14, max: 40 },
      { k: 'code_size', label: 'ID number', min: 18, max: 48 },
      { k: 'bar_height', label: 'Bar height', min: 16, max: 64 }
    ]},
    { title: 'Signatures', fields: [
      { k: 'sig_head_size', label: 'Head width', min: 40, max: 280 },
      { k: 'sig_head_opacity', label: 'Head opacity', min: 10, max: 100 },
      { k: 'sig_admin_size', label: 'Director width', min: 40, max: 280 },
      { k: 'sig_admin_opacity', label: 'Director opacity', min: 10, max: 100 }
    ]},
    { title: 'Colours', colors: [
      { k: 'title_color', label: 'Titles' },
      { k: 'label_color', label: 'Labels' },
      { k: 'value_color', label: 'Values' },
      { k: 'bar_color', label: 'Bar' },
      { k: 'gold_color', label: 'Gold line' }
    ]}
  ];

  var state = Object.assign({}, DEFAULTS);
  var side = 'front';
  var root = null;
  var frame = null;
  var cfg = window.ID_DESIGNER || {};

  function cssVars() {
    return [
      '--id-logo-x:' + state.logo_x + 'px',
      '--id-logo-y:' + state.logo_y + 'px',
      '--id-logo-size:' + state.logo_size + 'px',
      '--id-logo-opacity:' + (state.logo_opacity / 100),
      '--id-seal-x:' + state.seal_x + 'px',
      '--id-seal-y:' + state.seal_y + 'px',
      '--id-seal-size:' + state.seal_size + 'px',
      '--id-seal-opacity:' + (state.seal_opacity / 100),
      '--id-sig-head:' + state.sig_head_size + 'px',
      '--id-sig-head-op:' + (state.sig_head_opacity / 100),
      '--id-sig-admin:' + state.sig_admin_size + 'px',
      '--id-sig-admin-op:' + (state.sig_admin_opacity / 100),
      '--id-title-size:' + state.title_size + 'px',
      '--id-parish-size:' + state.parish_size + 'px',
      '--id-label-size:' + state.label_size + 'px',
      '--id-value-size:' + state.value_size + 'px',
      '--id-code-size:' + state.code_size + 'px',
      '--id-bar-height:' + state.bar_height + 'px',
      '--id-photo-w:' + state.photo_w + 'px',
      '--id-photo-h:' + state.photo_h + 'px',
      '--id-qr-size:' + state.qr_size + 'px',
      '--id-label-color:' + state.label_color,
      '--id-title-color:' + state.title_color,
      '--id-gold-color:' + state.gold_color,
      '--id-bar-color:' + state.bar_color,
      '--id-value-color:' + state.value_color,
      '--id-bg:url(\'' + (cfg.bg || '/admin/id_cards/assets/backgrounds/id_card_bg.jpg') + '\')'
    ].join(';');
  }

  function pushPreview() {
    if (!frame || !frame.contentWindow) return;
    frame.contentWindow.postMessage({ type: 'id-layout', style: cssVars() }, window.location.origin);
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
    frame.src = '/admin/id_cards/preview.php?side=' + side + '&t=' + Date.now();
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

  function render() {
    root = document.getElementById('idDesigner');
    if (!root) return;
    var controls = '';
    GROUPS.forEach(function (g) {
      controls += '<div class="idc-group"><h4>' + g.title + '</h4>';
      if (g.fields) {
        g.fields.forEach(function (f) {
          controls += '<label>' + f.label + ' <span class="idc-val" data-val="' + f.k + '">' + state[f.k] + '</span></label>' +
            '<input type="range" min="' + f.min + '" max="' + f.max + '" value="' + state[f.k] + '" data-k="' + f.k + '">';
        });
      }
      if (g.colors) {
        controls += '<div class="idc-row">';
        g.colors.forEach(function (c) {
          controls += '<div><label>' + c.label + '</label><input type="color" value="' + state[c.k] + '" data-k="' + c.k + '"></div>';
        });
        controls += '</div>';
      }
      controls += '</div>';
    });

    root.innerHTML =
      '<div class="idc-panel">' + controls +
        '<div class="idc-actions">' +
          '<button type="button" class="btn btn-outline" id="idcReset">Reset</button>' +
          '<button type="button" class="btn btn-primary" id="idcSave">Save to all cards</button>' +
        '</div>' +
        '<p class="idc-note">Saved settings apply to every member ID card. Upload logo, seal, signatures, and background in the tiles above.</p>' +
      '</div>' +
      '<div class="idc-panel idc-preview">' +
        '<div class="idc-tabs">' +
          '<button type="button" class="idc-tab on" data-side="front">Front</button>' +
          '<button type="button" class="idc-tab" data-side="back">Back</button>' +
        '</div>' +
        '<div class="idc-preview-wrap"><div class="idc-frame-box"><iframe id="idcFrame" title="ID card preview"></iframe></div></div>' +
        '<p class="idc-note">This is the real card. Move the sliders and the print file will match after you save.</p>' +
      '</div>';

    frame = document.getElementById('idcFrame');
    setFrame();
    if (!window.__idcResizeBound) {
      window.__idcResizeBound = true;
      window.addEventListener('resize', function () { fitFrame(); });
    }
    setTimeout(fitFrame, 80);
    setTimeout(fitFrame, 400);

    root.querySelectorAll('input[data-k]').forEach(function (inp) {
      inp.addEventListener('input', function () {
        var k = inp.getAttribute('data-k');
        state[k] = inp.type === 'color' ? inp.value.toUpperCase() : parseInt(inp.value, 10);
        var lab = root.querySelector('[data-val="' + k + '"]');
        if (lab) lab.textContent = state[k];
        pushPreview();
      });
    });
    root.querySelectorAll('.idc-tab').forEach(function (btn) {
      btn.addEventListener('click', function () {
        side = btn.getAttribute('data-side');
        root.querySelectorAll('.idc-tab').forEach(function (b) { b.classList.toggle('on', b === btn); });
        setFrame();
      });
    });
    document.getElementById('idcReset').addEventListener('click', function () {
      state = Object.assign({}, DEFAULTS);
      root.querySelectorAll('input[data-k]').forEach(function (inp) {
        var k = inp.getAttribute('data-k');
        inp.value = state[k];
        var lab = root.querySelector('[data-val="' + k + '"]');
        if (lab) lab.textContent = state[k];
      });
      pushPreview();
      toast('Reset to school defaults. Click Save to keep them.', true);
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

  async function load() {
    try {
      var r = await fetch('/admin/api_branding.php?action=get_assets', { credentials: 'same-origin' });
      var d = await r.json();
      if (d.status === 'success' && d.settings && typeof d.settings === 'object') {
        Object.keys(DEFAULTS).forEach(function (k) {
          if (d.settings[k] !== undefined && d.settings[k] !== null && d.settings[k] !== '') {
            state[k] = d.settings[k];
          }
        });
      }
      (d.assets || []).forEach(function (a) {
        if (a.asset_key === 'card_bg' && a.web_url) cfg.bg = a.web_url;
      });
      if (!cfg.bg) cfg.bg = '/admin/id_cards/assets/backgrounds/id_card_bg.jpg';
    } catch (e) {}
    render();
  }

  window.bootIdDesigner = load;
  window.reloadIdDesignerPreview = function (bg) {
    if (bg) cfg.bg = bg;
    if (frame) setFrame();
  };
})();
