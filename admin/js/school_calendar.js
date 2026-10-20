/**
 * School Calendar (P1) — yearly preparation + "Day Off" days.
 * Class-day-only attendance plan (2026-10-20). Ethiopian calendar UI only;
 * the API stores Gregorian dates, WBWSCalendar converts.
 */
(function () {
  'use strict';
  var CFG = window.SCHOOL_CALENDAR_CFG || {};
  var API = CFG.api || '/admin/api_school_calendar.php';
  var EC = window.WBWSCalendar;
  var state = {
    year: 0, month: 1, classId: 0,
    yearHolidays: [], yearDayOffs: [], unconfigured: [],
    classDays: null, classes: []
  };

  // ── helpers ─────────────────────────────────────────────────────────────
  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function ymd(dt) {
    var m = dt.getMonth() + 1, d = dt.getDate();
    return dt.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
  }
  function ecDateOf(y, m, d) { return ymd(EC.toGregorian(y, m, d)); }
  function toast(msg, ok) {
    var host = $('sc-toast-host');
    host.innerHTML = '<div class="nc-toast ' + (ok ? 'nc-ok' : 'nc-err') + '">' + esc(msg) + '</div>';
    setTimeout(function () { host.innerHTML = ''; }, 4200);
  }
  function call(action, params, method) {
    var qs = '';
    var body = null;
    var opts = { credentials: 'same-origin', method: method || 'GET' };
    if (opts.method === 'GET') {
      qs = '?' + Object.keys(params || {}).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
      }).join('&');
    } else {
      body = new FormData();
      Object.keys(params || {}).forEach(function (k) { body.append(k, params[k]); });
      body.append('action', action);
      body.append('csrf_token', CFG.csrfToken || '');
      opts.body = body;
    }
    return fetch(API + (opts.method === 'GET' ? '?action=' + action + '&' + qs.slice(1) : ''), opts)
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); });
  }

  // ── data ────────────────────────────────────────────────────────────────
  function loadYear() {
    var from = ecDateOf(state.year, 1, 1);
    var to = ymd(new Date(EC.toGregorian(state.year + 1, 1, 1).getTime() - 86400000));
    return call('overview', { from: from, to: to }).then(function (r) {
      if (r.body.status !== 'success') { toast(r.body.message || 'Could not load the calendar.', false); return; }
      state.yearHolidays = r.body.holidays || [];
      state.yearDayOffs = r.body.day_offs || [];
      state.unconfigured = r.body.unconfigured_classes || [];
      renderPanels();
      renderMonthStrip();
    });
  }
  function loadMonth() {
    if (state.classId > 0) {
      return call('class_days', { class_id: state.classId, ec_year: state.year, ec_month: state.month })
        .then(function (r) {
          state.classDays = (r.body.status === 'success') ? (r.body.days || {}) : {};
          renderGrid();
        });
    }
    state.classDays = null;
    renderGrid();
    return Promise.resolve();
  }

  // ── day status (school view: from year data) ────────────────────────────
  function schoolStatus(greg) {
    var off = state.yearDayOffs.filter(function (o) { return o.date === greg; })[0];
    if (off) return { cls: 'st-dayoff', tag: 'Day Off', why: off.reason || '' };
    var hol = state.yearHolidays.filter(function (h) { return h.date_from <= greg && h.date_to >= greg; })[0];
    if (hol) return { cls: 'st-holiday', tag: 'Holiday', why: hol.title_am || hol.title };
    var dt = new Date(greg + 'T12:00:00');
    var dow = dt.getDay(); // 0=Sun
    if (dow === 0) return { cls: 'st-classday', tag: 'Sunday', why: '' };
    return { cls: 'st-ok', tag: 'Open', why: '' };
  }
  function classStatus(greg) {
    var s = (state.classDays || {})[greg];
    if (!s) return { cls: 'st-off', tag: '—', why: '' };
    if (s.ok) {
      if (s.is_makeup) return { cls: 'st-makeup', tag: 'Makeup', why: '' };
      return { cls: 'st-classday', tag: 'Class day', why: '' };
    }
    switch (s.code) {
      case 'DAY_OFF': return { cls: 'st-dayoff', tag: 'Day Off', why: s.detail || '' };
      case 'HOLIDAY': return { cls: 'st-holiday', tag: 'Holiday', why: s.detail || '' };
      case 'NOT_CLASS_DAY': return { cls: 'st-off', tag: 'No class', why: s.detail || '' };
      case 'NOT_CONFIGURED': return { cls: 'st-off', tag: 'Not configured', why: s.detail || '' };
      case 'FUTURE_DATE': return { cls: 'st-off', tag: 'Future', why: '' };
      case 'BACKDATE_LIMIT': return { cls: 'st-off', tag: 'Too old', why: s.detail || '' };
      case 'OUT_OF_YEAR': return { cls: 'st-off', tag: 'Out of year', why: s.detail || '' };
      default: return { cls: 'st-off', tag: '—', why: s.detail || '' };
    }
  }

  // ── render ──────────────────────────────────────────────────────────────
  function renderMonthStrip() {
    var strip = $('sc-monthstrip');
    var names = EC.months ? EC.months.am : [];
    var html = '';
    for (var m = 1; m <= 13; m++) {
      var hol = 0, off = 0, i;
      for (i = 0; i < state.yearHolidays.length; i++) {
        var h = state.yearHolidays[i];
        var mid = ecDateOf(state.year, m, 15);
        if (h.date_from <= mid && h.date_to >= mid) hol++;
      }
      for (i = 0; i < state.yearDayOffs.length; i++) {
        var o = state.yearDayOffs[i];
        if (EC.toEthiopian(new Date(o.date + 'T12:00:00')).month === m) off++;
      }
      html += '<button type="button" class="sc-month' + (m === state.month ? ' active' : '') + '" data-m="' + m + '">'
        + esc(names[m] || m) + (hol ? '<span class="cnt">· ' + hol + ' hol</span>' : '')
        + (off ? '<span class="cnt">· ' + off + ' off</span>' : '') + '</button>';
    }
    strip.innerHTML = html;
    Array.prototype.forEach.call(strip.querySelectorAll('.sc-month'), function (b) {
      b.addEventListener('click', function () {
        state.month = parseInt(b.getAttribute('data-m'), 10);
        $('sc-month').value = String(state.month);
        renderMonthStrip();
        loadMonth();
      });
    });
  }

  function renderGrid() {
    var names = EC.months ? EC.months.am : [];
    var en = EC.months ? EC.months.en : [];
    $('sc-gridtitle').textContent = (names[state.month] || '') + ' ' + state.year + ' ዓ.ም. · '
      + (en[state.month] || '') + (state.classId > 0 ? ' · class view' : ' · school view');
    var wd = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $('sc-weekdays').innerHTML = wd.map(function (d) { return '<div class="sc-wd">' + d + '</div>'; }).join('');

    var first = EC.toGregorian(state.year, state.month, 1);
    var offset = (first.getDay() + 6) % 7; // Monday-first
    var total = EC.daysInMonth(state.month, state.year);
    var html = '';
    for (var i = 0; i < offset; i++) html += '<div></div>';
    for (var d = 1; d <= total; d++) {
      var greg = ecDateOf(state.year, state.month, d);
      var st = state.classId > 0 ? classStatus(greg) : schoolStatus(greg);
      var dowName = wd[(new Date(greg + 'T12:00:00').getDay() + 6) % 7];
      var canClick = CFG.canWrite && greg >= CFG.today;
      html += '<div class="sc-day ' + st.cls + (canClick ? ' clickable' : '') + '" data-date="' + greg + '">'
        + '<div class="n">' + d + '</div><div class="wd">' + dowName + '</div>'
        + '<span class="tag">' + esc(st.tag) + '</span>'
        + (st.why ? '<div class="why">' + esc(st.why) + '</div>' : '')
        + '</div>';
    }
    $('sc-days').innerHTML = html;
    Array.prototype.forEach.call($('sc-days').querySelectorAll('.sc-day.clickable'), function (el) {
      el.addEventListener('click', function () { openDayDialog(el.getAttribute('data-date')); });
    });
    $('sc-legend').innerHTML =
      '<span><i style="background:#f0fdf4;border-color:#bbf7d0"></i>Class day / open</span>'
      + '<span><i style="background:#f0fdfa;border-color:#99f6e4"></i>Makeup class day</span>'
      + '<span><i style="background:#faf5ff;border-color:#e9d5ff"></i>Holiday</span>'
      + '<span><i style="background:#fff7ed;border-color:#fed7aa"></i>Day Off</span>'
      + '<span><i style="background:#f8fafc"></i>No class / not configured</span>'
      + '<span style="opacity:.7">Click a day (today or later) to mark or reopen a Day Off.</span>';
  }

  function renderPanels() {
    var hol = $('sc-holidays');
    if (!state.yearHolidays.length) {
      hol.innerHTML = '<li><span class="s">No holidays entered for ' + state.year + ' ዓ.ም. yet.</span></li>';
    } else {
      hol.innerHTML = state.yearHolidays.map(function (h) {
        var range = EC.formatDate(new Date(h.date_from + 'T12:00:00'), 'long');
        if (h.date_to !== h.date_from) {
          range += ' → ' + EC.formatDate(new Date(h.date_to + 'T12:00:00'), 'long');
        }
        return '<li><div><div class="t">' + esc(h.title_am || h.title) + '</div>'
          + '<div class="s">' + esc(h.type) + ' · ' + range + '</div></div>'
          + (CFG.canWrite ? '<button class="rc-btn rc-btn-ghost rc-btn-sm sc-del-hol" data-id="' + h.id + '">Delete</button>' : '')
          + '</li>';
      }).join('');
      Array.prototype.forEach.call(hol.querySelectorAll('.sc-del-hol'), function (b) {
        b.addEventListener('click', function () {
          if (!window.confirm('Delete this holiday?')) return;
          call('delete_holiday', { id: b.getAttribute('data-id') }, 'POST').then(function (r) {
            toast(r.body.message || (r.body.status === 'success' ? 'Deleted.' : 'Failed.'), r.body.status === 'success');
            loadYear();
          });
        });
      });
    }

    var offs = $('sc-dayoffs');
    if (!state.yearDayOffs.length) {
      offs.innerHTML = '<li><span class="s">No day off days for ' + state.year + ' ዓ.ም.</span></li>';
    } else {
      offs.innerHTML = state.yearDayOffs.map(function (o) {
        return '<li><div><div class="t">' + esc(EC.formatDate(new Date(o.date + 'T12:00:00'), 'long')) + '</div>'
          + '<div class="s">' + esc(o.reason || '') + (o.cancel_makeup ? ' · makeups cancelled' : ' · makeups kept') + '</div></div>'
          + (CFG.canWrite && o.can_change
            ? '<button class="rc-btn rc-btn-ghost rc-btn-sm sc-reopen" data-date="' + o.date + '">Reopen</button>'
            : '<span class="s">final</span>')
          + '</li>';
      }).join('');
      Array.prototype.forEach.call(offs.querySelectorAll('.sc-reopen'), function (b) {
        b.addEventListener('click', function () {
          if (!window.confirm('Reopen this day? Classes can hold again.')) return;
          call('reopen_day_off', { date: b.getAttribute('data-date'), reason: '' }, 'POST').then(function (r) {
            toast(r.body.message || 'Done.', r.body.status === 'success');
            loadYear().then(loadMonth);
          });
        });
      });
    }

    var unc = $('sc-unconfigured');
    if (!state.unconfigured.length) {
      unc.innerHTML = 'All active classes have working days configured. ✓';
    } else {
      unc.innerHTML = '<strong>These classes cannot take attendance yet</strong> — set their working days in the Timetable builder first:<br><br>'
        + state.unconfigured.map(function (c) { return esc(c.name); }).join(' · ');
    }
  }

  // ── day dialog (mark / reopen) ─────────────────────────────────────────
  function openDayDialog(greg) {
    var host = $('sc-dialog-host');
    var label = EC.formatDate(new Date(greg + 'T12:00:00'), 'long');
    var isOff = state.yearDayOffs.some(function (o) { return o.date === greg; });
    host.innerHTML = ''
      + '<div class="sc-dialog"><div class="sc-dialogbox">'
      + '<h2>' + esc(label) + '</h2>'
      + '<div class="s" style="font-size:12px;color:#64748b;margin-bottom:8px">' + esc(greg) + '</div>'
      + (isOff
        ? '<p style="font-size:13px;line-height:1.6">This day is a <strong>Day Off</strong>. Reopening it lets classes hold again. '
          + 'A day can be reopened only before it passes.</p>'
          + '<div class="sc-actions"><button class="rc-btn rc-btn-ghost rc-btn-sm" id="sc-cancel">Close</button>'
          + '<button class="rc-btn rc-btn-primary rc-btn-sm" id="sc-do-reopen">Reopen this day</button></div>'
        : '<p style="font-size:13px;line-height:1.6">Mark this day off for the whole school. Classes will not hold and attendance cannot be taken.</p>'
          + '<div class="sc-form">'
          + '<label>Reason<input id="sc-off-reason" maxlength="255" placeholder="e.g. water supply cut — classes cannot hold"></label>'
          + '<label class="sc-check"><input type="checkbox" id="sc-off-makeup" checked> Also cancel makeup classes on this day</label>'
          + '</div>'
          + '<div class="sc-actions"><button class="rc-btn rc-btn-ghost rc-btn-sm" id="sc-cancel">Cancel</button>'
          + '<button class="rc-btn rc-btn-primary rc-btn-sm" id="sc-do-off">Mark as Day Off</button></div>')
      + '</div></div>';

    var cancel = $('sc-cancel');
    if (cancel) cancel.addEventListener('click', function () { host.innerHTML = ''; });
    var doOff = $('sc-do-off');
    if (doOff) doOff.addEventListener('click', function () {
      var reason = ($('sc-off-reason').value || '').trim();
      if (!reason) { toast('A reason is required.', false); return; }
      var cancelMakeup = $('sc-off-makeup').checked ? '1' : '0';
      call('mark_day_off', { date: greg, reason: reason, cancel_makeup: cancelMakeup }, 'POST').then(function (r) {
        toast(r.body.message || 'Done.', r.body.status === 'success');
        if (r.body.status === 'success') { host.innerHTML = ''; loadYear().then(loadMonth); }
      });
    });
    var doReopen = $('sc-do-reopen');
    if (doReopen) doReopen.addEventListener('click', function () {
      call('reopen_day_off', { date: greg, reason: '' }, 'POST').then(function (r) {
        toast(r.body.message || 'Done.', r.body.status === 'success');
        if (r.body.status === 'success') { host.innerHTML = ''; loadYear().then(loadMonth); }
      });
    });
  }

  // ── boot ────────────────────────────────────────────────────────────────
  function boot() {
    if (!EC) { document.querySelector('.sc-wrap').innerHTML = '<div class="sc-panel">Calendar library failed to load.</div>'; return; }
    state.year = EC.toEthiopian(new Date()).year;
    state.month = EC.toEthiopian(new Date()).month;

    // year + month selects
    var ys = $('sc-year');
    for (var y = state.year; y >= state.year - 1; y--) {
      ys.insertAdjacentHTML('beforeend', '<option value="' + y + '">' + y + ' ዓ.ም.</option>');
    }
    var ms = $('sc-month');
    for (var m = 1; m <= 13; m++) {
      ms.insertAdjacentHTML('beforeend', '<option value="' + m + '">' + (EC.months.am[m] || m) + '</option>');
    }
    ys.value = String(state.year);
    ms.value = String(state.month);
    ys.addEventListener('change', function () { state.year = parseInt(ys.value, 10); loadYear().then(loadMonth); });
    ms.addEventListener('change', function () { state.month = parseInt(ms.value, 10); renderMonthStrip(); loadMonth(); });

    // class select
    call('classes', {}).then(function (r) {
      var sel = $('sc-class');
      (r.body.classes || []).forEach(function (c) {
        sel.insertAdjacentHTML('beforeend', '<option value="' + c.id + '">' + esc(c.name) + '</option>');
      });
      sel.addEventListener('change', function () {
        state.classId = parseInt(sel.value, 10) || 0;
        loadMonth();
      });
      return loadYear();
    }).then(function () {
      renderGrid();
      if (CFG.canWrite) {
        var form = $('sc-holiday-form');
        if (form) {
          form.addEventListener('submit', function (e) {
            e.preventDefault();
            call('save_holiday', {
              title: $('sc-h-title').value,
              title_am: $('sc-h-title-am').value,
              date_from: $('sc-h-from').value,
              date_to: $('sc-h-to').value || $('sc-h-from').value,
              type: $('sc-h-type').value
            }, 'POST').then(function (r) {
              toast(r.body.message || 'Done.', r.body.status === 'success');
              if (r.body.status === 'success') {
                form.reset();
                loadYear();
              }
            });
          });
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
