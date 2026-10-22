/* Attendance Warnings page (Attendance alerting — Phase 2).
 * Pure browser JS, no framework. All dates arrive pre-formatted in the
 * Ethiopian calendar from api_attendance_warnings.php — this file never
 * formats a Gregorian date. */
(function () {
  'use strict';
  var cfg = window.AW_CONFIG || {};
  var api = cfg.api || '/admin/api_attendance_warnings.php';
  var els = {
    cards: document.getElementById('aw-cards'),
    list: document.getElementById('aw-list'),
    total: document.getElementById('aw-total'),
    unavailable: document.getElementById('aw-unavailable'),
    department: document.getElementById('aw-department'),
    status: document.getElementById('aw-status'),
    kind: document.getElementById('aw-kind'),
    stage: document.getElementById('aw-stage'),
    q: document.getElementById('aw-q'),
    refresh: document.getElementById('aw-refresh'),
    modalRoot: document.getElementById('aw-modal-root')
  };
  var state = { meta: null, items: [], total: 0 };

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
  function get(params) {
    var qs = Object.keys(params).filter(function (k) { return params[k] !== '' && params[k] != null; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(api + '?' + qs, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { j.__code = r.status; return j; }); });
  }
  function post(body) {
    body.csrf_token = cfg.csrf;
    return fetch(api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (j) { j.__code = r.status; return j; }); });
  }

  function pill(cls, text) { return '<span class="pill pill-' + esc(cls) + '">' + esc(text) + '</span>'; }
  function deptPill(w) { return pill(w.department, w.department_label); }
  function stagePill(w) {
    if (w.kind === 'low_month') return pill('low_month', 'Low month');
    return pill(w.stage || 'standard', (w.stage || 'standard').replace(/^./, function (c) { return c.toUpperCase(); }));
  }

  function renderCards(counts) {
    if (!els.cards) return;
    var html = '';
    Object.keys(counts || {}).forEach(function (d) {
      var c = counts[d];
      html += '<div class="aw-card"><h3>' + esc(c.label) + ' · open warnings</h3>' +
        '<div class="big">' + esc(c.total) + '</div>' +
        '<div class="sub">Consecutive ' + esc(c.consecutive) + ' (standard ' + esc(c.standard) + ' · prolonged ' + esc(c.prolonged) + ' · critical ' + esc(c.critical) + ') · Low month ' + esc(c.low_month) +
        (c.rollout ? ' · <span class="pill pill-rollout">' + esc(c.rollout) + ' from rollout</span>' : '') + '</div></div>';
    });
    els.cards.innerHTML = html;
  }

  function renderList() {
    if (!state.items.length) {
      els.list.innerHTML = '<div class="aw-empty">No attendance warnings match these filters.</div>';
      return;
    }
    var rows = state.items.map(function (w) {
      var canClose = w.status === 'open' && cfg.canClose && cfg.canClose[w.department];
      var flags = '';
      if (w.source === 'rollout') flags += ' ' + pill('rollout', 'system-generated at rollout');
      if (w.status === 'open' && !w.below_threshold) flags += ' ' + pill('flag', 'no longer meets threshold');
      if (w.auto_deactivated) flags += ' ' + pill('critical', 'auto-deactivated');
      return '<tr data-id="' + esc(w.id) + '">' +
        '<td><a href="' + esc(cfg.memberUrl + w.member_id) + '" target="_blank" rel="noopener">' + esc(w.member_name || ('#' + w.member_id)) + '</a>' +
        (w.member_code ? '<div class="rc-muted">' + esc(w.member_code) + (w.section ? ' · ' + esc(w.section) : '') + '</div>' : '') + '</td>' +
        '<td>' + deptPill(w) + '</td>' +
        '<td>' + esc(w.reason) + '<div class="rc-muted amh">' + esc(w.reason_am) + '</div>' + flags + '</td>' +
        '<td>' + stagePill(w) + '</td>' +
        '<td>' + esc(w.opened_ec) + '</td>' +
        '<td>' + pill(w.status, w.status === 'open' ? 'Open' : 'Closed') +
        (w.status === 'closed' ? '<div class="rc-muted">' + esc(w.outcome_label || '') + (w.closed_ec ? ' · ' + esc(w.closed_ec) : '') + '</div>' : '') + '</td>' +
        '<td><button type="button" class="rc-btn rc-btn-ghost rc-btn-sm" data-act="detail">Details</button>' +
        (canClose ? ' <button type="button" class="rc-btn rc-btn-primary rc-btn-sm" data-act="close">Close…</button>' : '') + '</td>' +
        '</tr>';
    }).join('');
    els.list.innerHTML = '<table class="aw-table"><thead><tr>' +
      '<th>Member</th><th>Department</th><th>Reason</th><th>Stage</th><th>Opened</th><th>Status</th><th></th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table>';
  }

  // Phase 3 paging: warnings page through everything (was cut at 200).
  var pager = null;
  function baseParams() {
    return {
      action: 'list',
      department: els.department.value,
      status: els.status.value,
      kind: els.kind.value,
      stage: els.stage.value,
      q: els.q.value.trim(),
      member_id: cfg.initial && cfg.initial.member_id ? cfg.initial.member_id : ''
    };
  }
  function fetchPage(page, perPage) {
    var params = baseParams();
    params.limit = perPage;
    params.offset = (page - 1) * perPage;
    return get(params).then(function (j) {
      if (j.status !== 'success') { throw new Error(j.message || 'Could not load warnings'); }
      state.meta = j;
      state.unavailable = !j.available;
      if (!j.available) {
        els.unavailable.hidden = false;
        els.list.innerHTML = '';
        els.cards.innerHTML = '';
        return { rows: [], total: 0, page: 1, totalPages: 1 };
      }
      els.unavailable.hidden = true;
      state.total = j.total || 0;
      els.total.textContent = state.total + ' warning' + (state.total === 1 ? '' : 's');
      return { rows: j.items || [], total: state.total, page: page, totalPages: Math.max(1, Math.ceil(state.total / perPage)) };
    });
  }
  function load() {
    if (window.FKSSPager && document.getElementById('aw-pager')) {
      if (!pager) {
        pager = window.FKSSPager.create({
          host: document.getElementById('aw-pager'),
          storageKey: 'fkss_pp_att_warnings', noun: 'warnings', defaultSize: 50,
          fetchPage: fetchPage,
          render: function (rows, append) {
            if (state.unavailable) { return; }
            state.items = append ? state.items.concat(rows) : rows;
            renderList();
          },
          onError: function (e) { toast(e.message || 'Network error', 'err'); }
        });
      }
      els.list.innerHTML = '<div class="aw-empty">Loading…</div>';
      return pager.reset().catch(function (e) { els.list.innerHTML = ''; toast(e.message || 'Network error', 'err'); });
    }
    var params = baseParams();
    params.limit = 200;
    els.list.innerHTML = '<div class="aw-empty">Loading…</div>';
    return get(params).then(function (j) {
      if (j.status !== 'success') { toast(j.message || 'Could not load warnings', 'err'); return; }
      state.meta = j;
      if (!j.available) {
        els.unavailable.hidden = false;
        els.list.innerHTML = '';
        els.cards.innerHTML = '';
        return;
      }
      els.unavailable.hidden = true;
      state.items = j.items || [];
      state.total = j.total || 0;
      els.total.textContent = state.total + ' warning' + (state.total === 1 ? '' : 's');
      renderList();
    }).catch(function () { toast('Network error', 'err'); });
  }
  function loadCounts() {
    return get({ action: 'counts' }).then(function (j) {
      if (j.status === 'success' && j.available) renderCards(j.counts);
    }).catch(function () {});
  }

  // ── detail / close dialogs ──────────────────────────────────
  function closeModal() { els.modalRoot.innerHTML = ''; }
  function riskBlock(risk) {
    if (!risk) return '';
    var m = risk.month || {}, y = risk.year || {}, s = risk.streak || {};
    return '<div class="aw-risk">' +
      '<div>Current streak<b>' + esc(s.length || 0) + ' day(s)</b>' + (s.start_ec ? esc(s.start_ec) + ' – ' + esc(s.last_ec) : '') + '</div>' +
      '<div>' + esc(m.label || 'This month') + '<b>' + (m.rate == null ? 'No data' : esc(m.rate) + '%') + '</b>' + esc(m.sessions || 0) + ' sessions · ' + esc(m.band || '') + '</div>' +
      '<div>Year ' + esc(y.ec_year || '') + '<b>' + (y.rate == null ? 'No data' : esc(y.rate) + '%') + '</b>' + esc(y.sessions || 0) + ' sessions · ' + esc(y.band || '') + '</div>' +
      '<div>Department status<b>' + esc(risk.status || 'active') + '</b>' + (risk.status_since ? '' : '') + '</div>' +
      '</div>';
  }
  function showDetail(id) {
    get({ action: 'detail', id: id }).then(function (j) {
      if (j.status !== 'success' || !j.warning) { toast(j.message || 'Could not load the warning', 'err'); return; }
      var w = j.warning;
      var canClose = w.status === 'open' && cfg.canClose && cfg.canClose[w.department];
      var events = (w.events || []).map(function (e) {
        var p = e.payload || {};
        var extra = '';
        if (e.event === 'closed') extra = (p.outcome || '') + (p.note ? ' — ' + p.note : '');
        else if (e.event === 'escalated' || e.event === 'reopened' || e.event === 'opened') extra = (p.stage ? 'stage ' + p.stage + ' · ' : '') + (p.streak_length ? p.streak_length + ' days' : '') + (p.rate != null ? ' rate ' + p.rate + '%' : '');
        else if (e.event === 'invalidated') extra = p.reason === 'month_recovered' ? 'month recovered above threshold' : 'streak below threshold after correction / return';
        else if (e.event === 'notified') extra = 'bell → ' + (p.roles || '');
        return '<li><span class="ev">' + esc(e.event.replace('_', ' ')) + '</span><span>' + esc(e.created_ec) + ' · ' + esc(e.actor || 'system') + (extra ? ' · ' + esc(extra) : '') + '</span></li>';
      }).join('');
      var absences = (w.absence_dates_ec || []).length ? '<dt>Absence dates</dt><dd>' + esc(w.absence_dates_ec.join(', ')) + '</dd>' : '';
      var other = '';
      if (j.member_summary && j.member_summary.departments) {
        Object.keys(j.member_summary.departments).forEach(function (d) {
          if (d === w.department) return;
          var e = j.member_summary.departments[d];
          var r = e.risk || {};
          other = '<dt>' + esc(e.label) + ' (other dept.)</dt><dd>status ' + esc(r.status || 'active') + ' · streak ' + esc((r.streak || {}).length || 0) +
            ' · month ' + ((r.month || {}).rate == null ? 'no data' : esc(r.month.rate) + '%') + ' · open warnings ' + esc((e.warnings || []).filter(function (x) { return x.status === 'open'; }).length) + '</dd>';
        });
      }
      els.modalRoot.innerHTML = '<div class="aw-modal" data-close="1"><div class="aw-dialog" role="dialog" aria-modal="true">' +
        '<h2>' + esc(w.member_name || ('#' + w.member_id)) + ' ' + deptPill(w) + ' ' + stagePill(w) + ' ' + pill(w.status, w.status) + '</h2>' +
        '<div class="aw-note">' + esc(w.reason) + ' · <span class="amh">' + esc(w.reason_am) + '</span></div>' +
        riskBlock(w.risk) +
        '<dl class="aw-kv">' +
        '<dt>Opened</dt><dd>' + esc(w.opened_ec) + (w.source === 'rollout' ? ' · system-generated at rollout' : '') + '</dd>' +
        '<dt>Threshold</dt><dd>' + esc(w.threshold_value) + (w.kind === 'low_month' ? ' %' : ' consecutive sessions') + '</dd>' +
        absences + other +
        (w.status === 'closed' ? '<dt>Closed</dt><dd>' + esc(w.closed_ec) + ' · ' + esc(w.outcome_label) + (w.closure_note ? ' — ' + esc(w.closure_note) : '') + '</dd>' : '') +
        (!w.below_threshold && w.status === 'open' ? '<dt>Note</dt><dd>No longer meets the threshold (a day was corrected or the member returned). Please close with an outcome.</dd>' : '') +
        '</dl>' +
        '<h3 style="font-size:13px;margin:10px 0 4px">History</h3><ul class="aw-events">' + events + '</ul>' +
        '<div class="aw-actions"><a class="rc-btn rc-btn-ghost rc-btn-sm" href="' + esc(cfg.memberUrl + w.member_id) + '" target="_blank" rel="noopener">Open member profile</a>' +
        (canClose ? '<button type="button" class="rc-btn rc-btn-primary rc-btn-sm" data-act="close" data-id="' + esc(w.id) + '">Close with outcome…</button>' : '') +
        '<button type="button" class="rc-btn rc-btn-ghost rc-btn-sm" data-close="1">Dismiss</button></div>' +
        '</div></div>';
    });
  }
  function showClose(id) {
    var w = state.items.filter(function (x) { return String(x.id) === String(id); })[0] || { id: id };
    var outcomes = (state.meta && state.meta.outcomes) || { contacted: 'Contacted', excused: 'Excused', escalated: 'Escalated', deactivated: 'Deactivated', no_action: 'No Action' };
    var radios = Object.keys(outcomes).map(function (k, i) {
      return '<label><input type="radio" name="aw-outcome" value="' + esc(k) + '"' + (i === 0 ? ' checked' : '') + '><span>' + esc(outcomes[k]) + '</span></label>';
    }).join('');
    els.modalRoot.innerHTML = '<div class="aw-modal" data-close="1"><div class="aw-dialog" role="dialog" aria-modal="true">' +
      '<h2>Close warning</h2><div class="aw-note">' + esc(w.member_name || '') + ' · ' + esc(w.reason || '') + '</div>' +
      '<p class="aw-note">Outcome (required) — what was done about this warning. The warning stays in the history with this outcome.</p>' +
      '<div class="aw-radio">' + radios + '</div>' +
      '<textarea id="aw-close-note" maxlength="500" placeholder="Optional note (max 500 characters)"></textarea>' +
      '<div class="aw-actions"><button type="button" class="rc-btn rc-btn-ghost rc-btn-sm" data-close="1">Cancel</button>' +
      '<button type="button" class="rc-btn rc-btn-primary rc-btn-sm" id="aw-close-confirm" data-id="' + esc(w.id) + '">Close warning</button></div>' +
      '</div></div>';
  }
  function doClose(id) {
    var outcome = (document.querySelector('input[name=aw-outcome]:checked') || {}).value || '';
    var note = (document.getElementById('aw-close-note') || {}).value || '';
    var btn = document.getElementById('aw-close-confirm');
    if (btn) btn.disabled = true;
    post({ action: 'close', id: id, outcome: outcome, note: note }).then(function (j) {
      if (j.status !== 'success' || !j.closed) {
        toast(j.message || 'Could not close the warning', 'err');
        if (btn) btn.disabled = false;
        return;
      }
      toast(j.message || 'Warning closed', 'ok');
      closeModal();
      load();
      loadCounts();
    }).catch(function () { toast('Network error', 'err'); if (btn) btn.disabled = false; });
  }

  // ── wiring ──────────────────────────────────────────────────
  els.list.addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]');
    if (!b) return;
    var tr = b.closest('tr');
    var id = tr ? tr.dataset.id : null;
    if (!id) return;
    if (b.dataset.act === 'detail') showDetail(id);
    if (b.dataset.act === 'close') showClose(id);
  });
  els.modalRoot.addEventListener('click', function (e) {
    var t = e.target;
    if (t.id === 'aw-close-confirm') { doClose(t.dataset.id); return; }
    var act = t.closest('[data-act=close]');
    if (act && act.dataset.id) { showClose(act.dataset.id); return; }
    if (t.dataset && t.dataset.close && (t === e.currentTarget.firstChild || t.tagName === 'BUTTON')) closeModal();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
  [els.department, els.status, els.kind, els.stage].forEach(function (s) { s.addEventListener('change', load); });
  var qTimer = null;
  els.q.addEventListener('input', function () { clearTimeout(qTimer); qTimer = setTimeout(load, 300); });
  els.refresh.addEventListener('click', function () { load(); loadCounts(); });

  if (cfg.initial) {
    if (cfg.initial.department && cfg.initial.department !== 'all') els.department.value = cfg.initial.department;
    if (cfg.initial.status) els.status.value = cfg.initial.status;
  }
  loadCounts();
  load().then(function () {
    if (cfg.initial && cfg.initial.id) showDetail(cfg.initial.id);
  });
})();
