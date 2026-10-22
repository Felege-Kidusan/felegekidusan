/* Assessment follow-ups / make-up queue (Attendance alerting — Phase 5).
 * Pure browser JS, no framework. Every date arrives pre-formatted in the
 * Ethiopian calendar from api_assessment_followups.php — this file never
 * formats a Gregorian date. */
(function () {
  'use strict';
  var cfg = window.AF_CONFIG || {};
  var api = cfg.api || '/admin/api_assessment_followups.php';
  var els = {
    unavailable: document.getElementById('af-unavailable'),
    cards: document.getElementById('af-cards'),
    decision: document.getElementById('af-decision'),
    q: document.getElementById('af-q'),
    total: document.getElementById('af-total'),
    list: document.getElementById('af-list'),
    meta: document.getElementById('af-meta'),
    prev: document.getElementById('af-prev'),
    next: document.getElementById('af-next'),
    size: document.getElementById('af-size'),
    more: document.getElementById('af-more'),
    reload: document.getElementById('af-reload'),
    modalRoot: document.getElementById('af-modal-root')
  };
  // Phase 3 paging: per page remembered; Load more appends.
  var SIZE_KEY = 'fkss_pp_followups';
  var savedSize = (function () { try { var v = parseInt(localStorage.getItem(SIZE_KEY), 10); return [25, 50, 100, 200].indexOf(v) >= 0 ? v : null; } catch (e) { return null; } })();
  var state = { items: [], total: 0, limit: savedSize || 50, offset: 0, counts: null, canWrite: false, labels: {}, loading: false };
  if (els.size) { els.size.value = String(state.limit); }

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
  function post(body) {
    body.csrf_token = cfg.csrf;
    return fetch(api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (j) { j.__code = r.status; return j; }); });
  }
  function pill(cls, text) { return '<span class="pill pill-' + esc(cls) + '">' + esc(text) + '</span>'; }
  function label(d) { return (state.labels[d] || d || '').split(' · ')[0]; }
  function num(v) { return v == null ? '—' : (Math.round(v * 100) / 100).toString(); }

  function filters() {
    var d = els.decision.value;
    return {
      action: 'list',
      decision: (d === '' || d === 'all') ? '' : d,
      open: d === '' ? 1 : '',
      q: els.q.value.trim(),
      member_id: cfg.initial && cfg.initial.member_id ? cfg.initial.member_id : '',
      class_id: cfg.initial && cfg.initial.class_id ? cfg.initial.class_id : '',
      limit: state.limit, offset: state.offset + (state.appendFrom || 0)
    };
  }

  function renderCards() {
    var c = state.counts || {};
    var cards = [
      { key: 'pending', title: 'Pending', sub: 'awaiting a decision' },
      { key: 'makeup_required', title: 'Make-up required', sub: (c.with_attempt || 0) + ' with a result' },
      { key: 'exempt', title: 'Exempt', sub: 'no make-up needed' },
      { key: 'not_applicable', title: 'Not applicable', sub: 'closed' },
      { key: 'all', title: 'All follow-ups', sub: 'every decision' }
    ];
    els.cards.innerHTML = cards.map(function (k) {
      var v = k.key === 'all' ? (c.total || 0) : (c[k.key] || 0);
      var active = els.decision.value === k.key;
      return '<div class="af-card' + (active ? ' active' : '') + '" data-key="' + k.key + '"><h3>' + esc(k.title) + '</h3><div class="big">' + v + '</div><div class="sub">' + esc(k.sub) + '</div></div>';
    }).join('');
    Array.prototype.forEach.call(els.cards.querySelectorAll('.af-card'), function (el) {
      el.addEventListener('click', function () {
        els.decision.value = el.getAttribute('data-key');
        state.offset = 0;
        load();
      });
    });
  }

  function renderList() {
    if (!state.items.length) {
      els.list.innerHTML = '<div class="af-empty">No follow-ups match these filters.</div>';
    } else {
      var rows = state.items.map(function (it) {
        var result = it.attempt_count > 0
          ? pill('score', num(it.latest_score) + ' / ' + num(it.latest_max_score) + (it.latest_percentage != null ? ' (' + it.latest_percentage + '%)' : ''))
          : (it.original_score != null ? '<span class="rc-muted">orig. ' + num(it.original_score) + '</span>' : '<span class="rc-muted">—</span>');
        return '<tr data-id="' + it.id + '">' +
          '<td><a href="' + esc(cfg.memberUrl + it.member_id) + '" target="_blank" rel="noopener">' + esc(it.member_name) + '</a><br><span class="rc-muted">' + esc(it.member_code) + '</span></td>' +
          '<td>' + esc(it.assessment_name) + '<br><span class="rc-muted">' + esc(it.subject_name || it.subject_name_en) + ' · ' + esc(it.class_name) + '</span></td>' +
          '<td class="opt">' + esc(it.due_date_ec || '—') + '</td>' +
          '<td class="opt">' + esc((it.inactive_from_ec || '') + ' – ' + (it.inactive_to_ec || '')) + '</td>' +
          '<td>' + pill(it.decision, label(it.decision)) + (it.decided_by_name ? '<br><span class="rc-muted">' + esc(it.decided_by_name) + '</span>' : '') + '</td>' +
          '<td>' + result + '</td>' +
          '<td><button class="rc-btn rc-btn-ghost rc-btn-sm" type="button" data-open="' + it.id + '">' + (state.canWrite ? 'Decide' : 'View') + '</button></td>' +
          '</tr>';
      }).join('');
      els.list.innerHTML = '<table class="af-table"><thead><tr><th>Member</th><th>Assessment</th><th class="opt">Due (EC)</th><th class="opt">Inactive period (EC)</th><th>Decision</th><th>Make-up result</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>';
      Array.prototype.forEach.call(els.list.querySelectorAll('[data-open]'), function (b) {
        b.addEventListener('click', function () { openDetail(parseInt(b.getAttribute('data-open'), 10)); });
      });
    }
    var from = state.total ? state.offset + 1 : 0;
    var to = Math.min(state.offset + state.items.length, state.total);
    els.meta.textContent = state.total ? ('Showing ' + from + '–' + to + ' of ' + state.total) : '';
    els.total.textContent = state.total ? (state.total + ' follow-up' + (state.total === 1 ? '' : 's')) : '';
    els.prev.disabled = state.offset <= 0;
    els.next.disabled = state.offset + state.items.length >= state.total;
    if (els.more) {
      var left = Math.max(0, state.total - (state.offset + state.items.length));
      els.more.hidden = left <= 0;
      els.more.disabled = false;
      els.more.textContent = 'Load more (' + left + ' left)';
    }
  }

  function load(append) {
    if (state.loading) { return; }
    state.loading = true;
    state.appendFrom = append ? state.items.length : 0;
    var f = filters();
    state.appendFrom = 0;
    Promise.all([
      get(f),
      get({ action: 'counts', member_id: f.member_id, class_id: f.class_id })
    ]).then(function (res) {
      var list = res[0], counts = res[1];
      state.loading = false;
      if (list.status !== 'success') { toast(list.message || 'Unable to load follow-ups.', 'err'); return; }
      els.unavailable.hidden = !!list.available;
      state.canWrite = !!list.can_write;
      state.labels = list.decisions || {};
      state.items = append ? state.items.concat(list.items || []) : (list.items || []);
      state.total = list.total || 0;
      state.counts = counts.counts || {};
      renderCards();
      renderList();
    }).catch(function () { state.loading = false; toast('Network error.', 'err'); });
  }

  function closeModal() { els.modalRoot.innerHTML = ''; }

  function openDetail(id) {
    get({ action: 'detail', id: id }).then(function (j) {
      if (j.status !== 'success' || !j.item) { toast(j.message || 'Not found.', 'err'); return; }
      var it = j.item;
      var attempts = (it.attempts || []).map(function (a) {
        return '<li>' + pill('score', num(a.score) + ' / ' + num(a.max_score) + (a.percentage != null ? ' (' + a.percentage + '%)' : '')) +
          ' <span class="rc-muted">' + esc(a.recorded_at_ec) + ' · ' + esc(a.recorded_by_name) + '</span>' +
          (a.remarks ? '<br>' + esc(a.remarks) : '') + '</li>';
      }).join('');
      var canWrite = state.canWrite;
      var locked = it.attempt_count > 0;
      var decisionForm = canWrite ? (
        '<div class="af-form"><strong>Decision</strong>' +
        '<label>Decision<select id="af-d-decision"' + (locked ? ' disabled' : '') + '>' +
        ['pending', 'makeup_required', 'exempt', 'not_applicable'].map(function (d) {
          return '<option value="' + d + '"' + (it.decision === d ? ' selected' : '') + '>' + esc(state.labels[d] || d) + '</option>';
        }).join('') + '</select></label>' +
        '<label>Note (required for exempt / not applicable)<textarea id="af-d-note" rows="2" maxlength="500">' + esc(it.note || '') + '</textarea></label>' +
        (locked ? '<span class="af-note">A make-up result exists, so the decision stays “make-up required”.</span>' : '') +
        '<div class="af-actions"><button class="rc-btn rc-btn-sm" type="button" id="af-d-save"' + (locked ? ' disabled' : '') + '>Save decision</button></div></div>'
      ) : '';
      var attemptForm = (canWrite && it.decision === 'makeup_required') ? (
        '<div class="af-form"><strong>Record make-up result</strong>' +
        '<div class="af-row"><label>Score<input type="number" id="af-a-score" step="0.01" min="0" max="' + esc(it.assessment_max_score) + '"></label>' +
        '<label>Out of<input type="number" id="af-a-max" step="0.01" min="0.01" max="' + esc(it.assessment_max_score) + '" value="' + esc(it.assessment_max_score) + '"></label></div>' +
        '<label>Remarks<input type="text" id="af-a-remarks" maxlength="500"></label>' +
        '<span class="af-note">Stored as a separate make-up attempt; the original assessment row is kept unchanged.</span>' +
        '<div class="af-actions"><button class="rc-btn rc-btn-sm" type="button" id="af-a-save">Save result</button></div></div>'
      ) : '';
      els.modalRoot.innerHTML =
        '<div class="af-modal" id="af-modal"><div class="af-dialog" role="dialog" aria-modal="true">' +
        '<h2>' + esc(it.assessment_name) + ' · ' + esc(it.member_name) + '</h2>' +
        '<div class="rc-subtitle">' + esc(it.subject_name || it.subject_name_en) + ' · ' + esc(it.class_name) + ' · ' + pill(it.decision, label(it.decision)) + '</div>' +
        '<div class="af-grid">' +
        '<div><b>Member code</b>' + esc(it.member_code) + '</div>' +
        '<div><b>Due date (EC)</b>' + esc(it.due_date_ec || '—') + '</div>' +
        '<div><b>Inactive period (EC)</b>' + esc((it.inactive_from_ec || '') + ' – ' + (it.inactive_to_ec || '')) + '</div>' +
        '<div><b>Max score</b>' + esc(num(it.assessment_max_score)) + '</div>' +
        '<div><b>Original score</b>' + esc(it.original_score == null ? 'none' : num(it.original_score)) + '</div>' +
        '<div><b>Opened (EC)</b>' + esc(it.created_at_ec) + '</div>' +
        (it.decided_at_ec ? '<div><b>Decided</b>' + esc(it.decided_at_ec) + ' · ' + esc(it.decided_by_name) + '</div>' : '') +
        (it.note ? '<div><b>Note</b>' + esc(it.note) + '</div>' : '') +
        '</div>' +
        '<strong>Make-up attempts</strong>' + (attempts ? '<ul class="af-attempts">' + attempts + '</ul>' : '<p class="af-note">None recorded.</p>') +
        decisionForm + attemptForm +
        '<div class="af-actions"><button class="rc-btn rc-btn-ghost rc-btn-sm" type="button" id="af-close">Close</button></div>' +
        '</div></div>';
      document.getElementById('af-close').addEventListener('click', closeModal);
      document.getElementById('af-modal').addEventListener('click', function (e) { if (e.target.id === 'af-modal') { closeModal(); } });
      var dSave = document.getElementById('af-d-save');
      if (dSave) {
        dSave.addEventListener('click', function () {
          dSave.disabled = true;
          post({ action: 'decide', id: it.id, decision: document.getElementById('af-d-decision').value, note: document.getElementById('af-d-note').value })
            .then(function (r) {
              dSave.disabled = false;
              if (r.status !== 'success') { toast(r.message || 'Unable to save.', 'err'); return; }
              toast('Decision saved.', 'ok');
              load();
              openDetail(it.id);
            }).catch(function () { dSave.disabled = false; toast('Network error.', 'err'); });
        });
      }
      var aSave = document.getElementById('af-a-save');
      // Score limit (2026-10-22): the score can never exceed "Out of", and
      // "Out of" can never exceed the assessment's own max (server enforces too).
      if (aSave && window.FKSSScoreLimit) {
        window.FKSSScoreLimit.bind(els.modalRoot, '#af-a-score, #af-a-max');
        var mxEl = document.getElementById('af-a-max');
        mxEl.addEventListener('input', function () {
          var v = parseFloat(mxEl.value);
          if (isFinite(v) && v > 0) document.getElementById('af-a-score').setAttribute('max', String(v));
        });
      }
      if (aSave) {
        aSave.addEventListener('click', function () {
          if (window.FKSSScoreLimit) {
            var bad = window.FKSSScoreLimit.problems(els.modalRoot, '#af-a-score, #af-a-max');
            if (bad.length) { bad[0].el.focus(); toast(window.FKSSScoreLimit.problemMessage(bad), 'err'); return; }
          }
          aSave.disabled = true;
          post({ action: 'attempt', id: it.id, score: document.getElementById('af-a-score').value, max_score: document.getElementById('af-a-max').value, remarks: document.getElementById('af-a-remarks').value })
            .then(function (r) {
              aSave.disabled = false;
              if (r.status !== 'success') { toast(r.message || 'Unable to save.', 'err'); return; }
              toast('Make-up result recorded.', 'ok');
              load();
              openDetail(it.id);
            }).catch(function () { aSave.disabled = false; toast('Network error.', 'err'); });
        });
      }
    });
  }

  els.decision.addEventListener('change', function () { state.offset = 0; load(); });
  var qTimer = null;
  els.q.addEventListener('input', function () { clearTimeout(qTimer); qTimer = setTimeout(function () { state.offset = 0; load(); }, 300); });
  els.prev.addEventListener('click', function () { state.offset = Math.max(0, state.offset - state.limit); load(); });
  els.next.addEventListener('click', function () { state.offset += Math.max(state.items.length, 1); load(); });
  if (els.more) { els.more.addEventListener('click', function () { els.more.disabled = true; els.more.textContent = 'Loading…'; load(true); }); }
  if (els.size) {
    els.size.addEventListener('change', function () {
      var n = parseInt(els.size.value, 10);
      if ([25, 50, 100, 200].indexOf(n) < 0) { return; }
      state.limit = n; state.offset = 0;
      try { localStorage.setItem(SIZE_KEY, String(n)); } catch (e) { /* private mode */ }
      load();
    });
  }
  els.reload.addEventListener('click', load);

  if (cfg.initial && cfg.initial.decision) { els.decision.value = cfg.initial.decision; }
  load();
  if (cfg.initial && cfg.initial.id) { openDetail(cfg.initial.id); }
})();
