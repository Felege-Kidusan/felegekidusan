/* Monthly attendance report (Attendance alerting — Phase 4).
 * Pure browser JS, no framework. All month labels / dates arrive pre-formatted
 * in the Ethiopian calendar from api_attendance_monthly.php — this file never
 * formats a Gregorian date. */
(function () {
  'use strict';
  var cfg = window.AM_CONFIG || {};
  var api = cfg.api || '/admin/api_attendance_monthly.php';
  var els = {
    unavailable: document.getElementById('am-unavailable'),
    department: document.getElementById('am-department'),
    month: document.getElementById('am-month'),
    group: document.getElementById('am-group'),
    groupCaption: document.getElementById('am-group-caption'),
    band: document.getElementById('am-band'),
    q: document.getElementById('am-q'),
    below: document.getElementById('am-below'),
    total: document.getElementById('am-total'),
    cards: document.getElementById('am-cards'),
    list: document.getElementById('am-list'),
    meta: document.getElementById('am-meta'),
    prev: document.getElementById('am-prev'),
    next: document.getElementById('am-next'),
    size: document.getElementById('am-size'),
    more: document.getElementById('am-more'),
    refresh: document.getElementById('am-refresh'),
    exportBtn: document.getElementById('am-export'),
    warningsLink: document.getElementById('am-warnings-link'),
    modalRoot: document.getElementById('am-modal-root')
  };
  // Phase 3 paging: per-page choice remembered; Load more appends.
  var SIZE_KEY = 'fkss_pp_att_monthly';
  var savedSize = (function () { try { var v = parseInt(localStorage.getItem(SIZE_KEY), 10); return [25, 50, 100, 200].indexOf(v) >= 0 ? v : null; } catch (e) { return null; } })();
  var state = { months: [], items: [], total: 0, limit: savedSize || 100, offset: 0, summary: null, loading: false };
  if (els.size) { els.size.value = String(state.limit); }
  var BAND = { excellent: 'Excellent', good: 'Good', fair: 'Fair', low: 'Low', no_data: 'No data' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function toast(msg, kind) {
    var t = document.createElement('div');
    t.className = 'nc-toast' + (kind === 'ok' ? ' nc-ok' : kind === 'err' ? ' nc-err' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 3200);
  }
  function qs(params) {
    return Object.keys(params).filter(function (k) { return params[k] !== '' && params[k] != null && params[k] !== false; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k] === true ? 1 : params[k]); }).join('&');
  }
  function get(params) {
    return fetch(api + '?' + qs(params), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { j.__code = r.status; return j; }); });
  }
  function pill(cls, text) { return '<span class="pill pill-' + esc(cls) + '">' + esc(text) + '</span>'; }
  function fmtRate(r) { return r == null ? '—' : (Math.round(r * 10) / 10).toFixed(1) + '%'; }
  function fmtEq(v) { return v == null ? '0' : (Math.round(v * 10) / 10).toString(); }

  function dept() { return els.department.value; }
  function month() {
    var v = (els.month.value || '').split('-');
    return { ec_year: parseInt(v[0], 10) || 0, ec_month: parseInt(v[1], 10) || 0 };
  }
  function baseParams() {
    var m = month();
    return { department: dept(), ec_year: m.ec_year, ec_month: m.ec_month };
  }
  function filterParams() {
    var p = baseParams();
    p.group = els.group.value;
    p.band = els.band.value;
    p.q = els.q.value.trim();
    p.below = els.below.checked;
    return p;
  }
  function syncUrl() {
    var p = filterParams();
    var u = new URL(window.location.href);
    u.searchParams.set('department', p.department);
    u.searchParams.set('ec_year', p.ec_year);
    u.searchParams.set('ec_month', p.ec_month);
    if (p.below) { u.searchParams.set('below', '1'); } else { u.searchParams.delete('below'); }
    window.history.replaceState(null, '', u.toString());
    if (els.warningsLink) { els.warningsLink.href = (cfg.warningsUrl || '/admin/attendance_warnings.php') + '?department=' + encodeURIComponent(p.department); }
  }

  // ── months picker ─────────────────────────────────────────────
  function loadMonths(keep) {
    return get({ action: 'months', department: dept() }).then(function (j) {
      if (j.status !== 'success') { toast(j.message || 'Could not load months', 'err'); return; }
      if (!j.available) { els.unavailable.hidden = false; return; }
      els.unavailable.hidden = true;
      state.months = j.months || [];
      var want = keep && els.month.value ? els.month.value : '';
      var init = cfg.initial || {};
      if (!want && init.ec_year && init.ec_month) { want = init.ec_year + '-' + init.ec_month; init.ec_year = 0; }
      els.month.innerHTML = state.months.map(function (m) {
        return '<option value="' + m.ec_year + '-' + m.ec_month + '">' + esc(m.label) + ' · ' + esc(m.label_am) + (m.is_current ? ' (current)' : '') + '</option>';
      }).join('');
      if (want && Array.prototype.some.call(els.month.options, function (o) { return o.value === want; })) { els.month.value = want; }
    });
  }
  function loadGroups() {
    els.groupCaption.textContent = dept() === 'edu' ? 'Class' : 'Section';
    var p = baseParams(); p.action = 'groups';
    return get(p).then(function (j) {
      var cur = els.group.value;
      var html = '<option value="">All</option>';
      (j.groups || []).forEach(function (g) { html += '<option value="' + esc(g.id) + '">' + esc(g.label) + '</option>'; });
      els.group.innerHTML = html;
      if (Array.prototype.some.call(els.group.options, function (o) { return o.value === cur; })) { els.group.value = cur; }
    });
  }

  // ── summary cards ─────────────────────────────────────────────
  function renderCards(s) {
    if (!s) { els.cards.innerHTML = ''; return; }
    var cards = [
      { key: '', h: 'Members in month', big: s.members, sub: s.with_data + ' with sessions' },
      { key: 'below', h: 'Below ' + s.threshold + '%', big: s.below, sub: 'min ' + s.min_sessions + ' sessions' },
      { key: 'avg', h: 'Average rate', big: s.avg_rate == null ? '—' : s.avg_rate + '%', sub: 'weighted rate' },
      { key: 'excellent', h: 'Excellent ≥ 90%', big: s.bands.excellent, sub: '' },
      { key: 'good', h: 'Good 75–89%', big: s.bands.good, sub: '' },
      { key: 'fair', h: 'Fair 50–74%', big: s.bands.fair, sub: '' },
      { key: 'low', h: 'Low < 50%', big: s.bands.low, sub: '' },
      { key: 'no_data', h: 'No data', big: s.bands.no_data, sub: 'no eligible sessions' }
    ];
    var activeBand = els.band.value;
    els.cards.innerHTML = cards.map(function (c) {
      var active = (c.key === 'below' && els.below.checked) || (c.key && c.key === activeBand);
      return '<div class="am-card' + (active ? ' active' : '') + '" data-key="' + esc(c.key) + '" role="button" tabindex="0">' +
        '<h3>' + esc(c.h) + '</h3><div class="big">' + esc(c.big) + '</div><div class="sub">' + esc(c.sub) + '</div></div>';
    }).join('');
    Array.prototype.forEach.call(els.cards.querySelectorAll('.am-card'), function (card) {
      card.addEventListener('click', function () {
        var k = card.getAttribute('data-key');
        if (k === 'below') { els.below.checked = !els.below.checked; }
        else if (k === 'avg' || k === '') { els.band.value = ''; els.below.checked = false; }
        else { els.band.value = els.band.value === k ? '' : k; }
        state.offset = 0;
        load();
      });
    });
  }

  // ── table ─────────────────────────────────────────────────────
  function renderList() {
    var isEdu = dept() === 'edu';
    if (!state.items.length) {
      els.list.innerHTML = '<div class="am-empty">No members match for this month.</div>';
      return;
    }
    var html = '<table class="am-table"><thead><tr>' +
      '<th>#</th><th>Member</th><th class="opt">' + (isEdu ? 'Class' : 'Section') + '</th>' +
      '<th class="num">Sessions</th><th class="num opt">Present</th><th class="num opt">Late</th><th class="num opt">Excused</th><th class="num">Absent</th>' +
      '<th class="num">Rate</th><th>Band</th><th class="opt">Flags</th></tr></thead><tbody>';
    state.items.forEach(function (r, i) {
      var flags = [];
      if (r.below_threshold) { flags.push(pill('low', 'Below ' + (r.threshold_value != null ? r.threshold_value : '') + '%')); }
      if (r.open_warnings > 0) { flags.push('<a href="' + esc((cfg.warningsUrl || '/admin/attendance_warnings.php') + '?department=' + dept() + '&member_id=' + r.member_id) + '">' + pill('warn', r.open_warnings + ' open warning' + (r.open_warnings > 1 ? 's' : '')) + '</a>'); }
      if (r.department_status === 'inactive') { flags.push(pill('inactive', 'Inactive in dept.')); }
      var bar = r.rate == null ? '' : '<span class="am-bar"><i style="width:' + Math.max(0, Math.min(100, r.rate)) + '%"></i></span>';
      html += '<tr class="' + (r.below_threshold ? 'below' : '') + '" data-id="' + r.member_id + '">' +
        '<td>' + (state.offset + i + 1) + '</td>' +
        '<td><a href="#" class="am-member" data-id="' + r.member_id + '"><strong>' + esc(r.name) + '</strong></a><div class="am-note">' + esc(r.member_code) + (r.gender ? ' · ' + esc(r.gender) : '') + '</div></td>' +
        '<td class="opt">' + esc(r.group) + '</td>' +
        '<td class="num">' + r.sessions + '</td><td class="num opt">' + r.present + '</td><td class="num opt">' + r.late + '</td><td class="num opt">' + r.excused + '</td><td class="num">' + r.absent + '</td>' +
        '<td class="num">' + esc(fmtRate(r.rate)) + bar + '</td>' +
        '<td>' + pill(r.band, BAND[r.band] || r.band) + '</td>' +
        '<td class="opt">' + flags.join(' ') + '</td></tr>';
    });
    html += '</tbody></table>';
    els.list.innerHTML = html;
    Array.prototype.forEach.call(els.list.querySelectorAll('.am-member'), function (a) {
      a.addEventListener('click', function (ev) { ev.preventDefault(); openMember(parseInt(a.getAttribute('data-id'), 10)); });
    });
  }
  function renderFoot() {
    var from = state.total ? state.offset + 1 : 0;
    var to = Math.min(state.total, state.offset + state.items.length);
    els.total.textContent = state.total ? state.total + ' member' + (state.total === 1 ? '' : 's') : '';
    els.meta.textContent = (state.total ? 'Showing ' + from + '–' + to + ' of ' + state.total : '') +
      (state.summary && state.summary.computed_at_ec ? ' · computed ' + state.summary.computed_at_ec + ' EC' : '');
    els.prev.disabled = state.offset <= 0;
    els.next.disabled = state.offset + state.items.length >= state.total;
    if (els.more) {
      var left = Math.max(0, state.total - (state.offset + state.items.length));
      els.more.hidden = left <= 0;
      els.more.disabled = false;
      els.more.textContent = 'Load more (' + left + ' left)';
    }
  }

  function load(force, append) {
    if (state.loading) { return Promise.resolve(); }
    state.loading = true;
    var p = filterParams();
    p.action = 'list';
    p.limit = state.limit;
    p.offset = append ? state.offset + state.items.length : state.offset;
    if (force) { p.refresh = 1; }
    if (!append) { els.list.innerHTML = '<div class="am-empty">Loading…</div>'; }
    syncUrl();
    return get(p).then(function (j) {
      state.loading = false;
      if (j.status !== 'success') { els.list.innerHTML = ''; toast(j.message || 'Could not load', 'err'); return; }
      if (!j.available) { els.unavailable.hidden = false; els.list.innerHTML = ''; els.cards.innerHTML = ''; return; }
      els.unavailable.hidden = true;
      state.items = append ? state.items.concat(j.items || []) : (j.items || []);
      state.total = j.total || 0;
      state.summary = j.summary || null;
      renderCards(state.summary);
      renderList();
      renderFoot();
      if (force) { toast('Month recomputed', 'ok'); }
    }).catch(function () { state.loading = false; els.list.innerHTML = ''; toast('Network error', 'err'); });
  }

  // ── member drill-down ─────────────────────────────────────────
  function closeModal() { els.modalRoot.innerHTML = ''; }
  function openMember(id) {
    var row = state.items.filter(function (r) { return r.member_id === id; })[0];
    get({ action: 'member', department: dept(), member_id: id }).then(function (j) {
      if (j.status !== 'success') { toast(j.message || 'Could not load member', 'err'); return; }
      var months = j.months || [];
      var html = '<div class="am-modal" role="dialog" aria-modal="true"><div class="am-dialog">' +
        '<h2>' + esc(row ? row.name : 'Member #' + id) + '</h2>' +
        '<div class="am-note">' + esc(row ? row.member_code + ' · ' + row.group : '') + ' · ' + esc(j.department_label || '') + '</div>';
      if (!months.length) {
        html += '<div class="am-empty" style="margin-top:10px">No monthly data yet.</div>';
      } else {
        html += '<table class="am-table" style="margin-top:10px"><thead><tr><th>Month (EC)</th><th class="num">Sessions</th><th class="num">Present</th><th class="num">Late</th><th class="num">Excused</th><th class="num">Absent</th><th class="num">Rate</th><th>Band</th></tr></thead><tbody>';
        months.forEach(function (m) {
          html += '<tr class="' + (m.below_threshold ? 'below' : '') + '"><td>' + esc(m.label) + ' · ' + esc(m.label_am) + '</td><td class="num">' + m.sessions + '</td><td class="num">' + m.present + '</td><td class="num">' + m.late + '</td><td class="num">' + m.excused + '</td><td class="num">' + m.absent + '</td><td class="num">' + esc(fmtRate(m.rate)) + '</td><td>' + pill(m.band, BAND[m.band] || m.band) + '</td></tr>';
        });
        html += '</tbody></table>';
      }
      html += '<div class="am-actions">' +
        '<a class="rc-btn rc-btn-ghost rc-btn-sm" href="' + esc((cfg.warningsUrl || '/admin/attendance_warnings.php') + '?department=' + dept() + '&member_id=' + id + '&status=all') + '">Warnings</a>' +
        (cfg.memberUrl && ['info_dept', 'hr_dept', 'super_admin', 'school_admin'].indexOf(cfg.role) >= 0 ? '<a class="rc-btn rc-btn-ghost rc-btn-sm" href="' + esc(cfg.memberUrl + id) + '">Member profile</a>' : '') +
        '<button class="rc-btn rc-btn-sm" type="button" id="am-close">Close</button></div></div></div>';
      els.modalRoot.innerHTML = html;
      document.getElementById('am-close').addEventListener('click', closeModal);
      els.modalRoot.querySelector('.am-modal').addEventListener('click', function (ev) { if (ev.target === ev.currentTarget) { closeModal(); } });
    });
  }

  // ── wiring ────────────────────────────────────────────────────
  var qTimer = null;
  els.department.addEventListener('change', function () {
    state.offset = 0; els.group.value = '';
    loadMonths(false).then(loadGroups).then(function () { load(); });
  });
  els.month.addEventListener('change', function () { state.offset = 0; loadGroups().then(function () { load(); }); });
  [els.group, els.band].forEach(function (el) { el.addEventListener('change', function () { state.offset = 0; load(); }); });
  els.below.addEventListener('change', function () { state.offset = 0; load(); });
  els.q.addEventListener('input', function () { clearTimeout(qTimer); qTimer = setTimeout(function () { state.offset = 0; load(); }, 300); });
  els.prev.addEventListener('click', function () { state.offset = Math.max(0, state.offset - state.limit); load(); });
  els.next.addEventListener('click', function () { state.offset += Math.max(state.items.length, 1); load(); });
  if (els.more) { els.more.addEventListener('click', function () { els.more.disabled = true; els.more.textContent = 'Loading…'; load(false, true); }); }
  if (els.size) {
    els.size.addEventListener('change', function () {
      var n = parseInt(els.size.value, 10);
      if ([25, 50, 100, 200].indexOf(n) < 0) { return; }
      state.limit = n; state.offset = 0;
      try { localStorage.setItem(SIZE_KEY, String(n)); } catch (e) { /* private mode */ }
      load();
    });
  }
  els.refresh.addEventListener('click', function () { load(true); });
  els.exportBtn.addEventListener('click', function () {
    var p = filterParams(); p.action = 'export'; p.format = 'xlsx';
    window.location.href = api + '?' + qs(p);
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { closeModal(); } });

  var init = cfg.initial || {};
  if (init.department && Array.prototype.some.call(els.department.options, function (o) { return o.value === init.department; })) {
    els.department.value = init.department;
  }
  if (init.below) { els.below.checked = true; }
  loadMonths(false).then(loadGroups).then(function () {
    return load();
  }).then(function () {
    if (init.member_id > 0) { openMember(init.member_id); }
  });
})();
