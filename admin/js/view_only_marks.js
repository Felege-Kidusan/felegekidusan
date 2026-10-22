/*
 * FKSS View-only marks (Phase 2, 2026-10-22, owner):
 *   "add a preview only capability for the assesments so the teacher can lock
 *    it on preview only and he can crosscheck without accidentally changing or
 *    erasing the marks, and also can show the members their result in that
 *    screen easly"
 *
 *   FKSSViewOnly.attach({
 *     card:        element whose header gets the toggle (first .p-4 / header),
 *     tbody:       the table body with one <tr> per student,
 *     input:       '.grade-input' — the score boxes (data-member-id / data-mid),
 *     extra:       '.remarks-input' — other boxes to lock too (optional),
 *     buttons:     ['saveGradesBtn'] — ids disabled while View only is on,
 *     assessmentId, assessmentName, maxScore
 *   });
 *
 * View only is remembered per test in this browser (localStorage) until the
 * teacher unlocks it — one click, no typing (LOW-risk action). Turning it
 * off restores each box's OWN previous state, so a box locked by a closed
 * semester or a submitted list stays locked.
 *
 * Clicking a student while View only is on opens a private result card:
 * that student's mark big and clear, plus their other results in the same
 * class + subject this semester (owner D2). No other student is shown.
 */
(function (global) {
  'use strict';

  var KEY = 'fkss_view_only_';
  var STYLE_ID = 'fk-vo-style';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function label(n) {
    var v = Number(n);
    return isFinite(v) ? String(Math.round(v * 100) / 100) : String(n == null ? '' : n);
  }
  function isOn(aid) {
    try { return global.localStorage.getItem(KEY + aid) === '1'; } catch (_) { return false; }
  }
  function remember(aid, on) {
    try {
      if (on) global.localStorage.setItem(KEY + aid, '1');
      else global.localStorage.removeItem(KEY + aid);
    } catch (_) {}
  }

  function css() {
    if (document.getElementById(STYLE_ID)) return;
    var st = document.createElement('style');
    st.id = STYLE_ID;
    st.textContent =
      '.fk-vo-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-top:.6rem}' +
      '.fk-vo-btn{display:inline-flex;align-items:center;gap:.35rem;border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:999px;padding:.35rem .8rem;font-size:.8rem;font-weight:600;cursor:pointer}' +
      '.fk-vo-btn[aria-pressed="true"]{background:#1e3a8a;border-color:#1e3a8a;color:#fff}' +
      '.fk-vo-search{flex:1;min-width:180px;max-width:320px;border:1px solid #cbd5e1;border-radius:8px;padding:.35rem .6rem;font-size:.82rem;color:#0f172a;background:#fff}' +
      '.fk-vo-banner{width:100%;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:8px;padding:.45rem .7rem;font-size:.8rem;font-weight:600}' +
      '.fk-vo-off{opacity:.45!important;cursor:not-allowed!important;filter:grayscale(.4)}' +
      'tr.fk-vo-row{cursor:pointer}tr.fk-vo-row:hover td{background:#f8fafc}' +
      '.fk-vo-ro{background:#f8fafc!important;color:#0f172a!important;font-weight:700}' +
      '.fk-rc-back{position:fixed;inset:0;z-index:99990;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:1rem}' +
      '.fk-rc{background:#fff;border-radius:18px;width:min(560px,100%);max-height:92vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);font-family:inherit}' +
      '.fk-rc-head{display:flex;justify-content:space-between;align-items:center;padding:.8rem 1rem;border-bottom:1px solid #e5e7eb}' +
      '.fk-rc-body{padding:1.2rem 1.3rem 1.4rem;text-align:center}' +
      '.fk-rc-name{font-size:1.7rem;font-weight:800;line-height:1.2;color:#111827}' +
      '.fk-rc-sub{color:#6b7280;font-size:.85rem;margin-top:.2rem}' +
      '.fk-rc-big{margin:1.1rem 0 1.3rem;border:1.5px solid rgba(90,18,18,.25);border-radius:18px;padding:1.4rem 1rem}' +
      '.fk-rc-score{font-size:4.4rem;font-weight:900;color:#5a1212;line-height:1}' +
      '.fk-rc-max{font-size:1.6rem;font-weight:700;color:#6b7280}' +
      '.fk-rc-dnt{display:inline-block;background:#fef3c7;color:#92400e;border-radius:999px;padding:.35rem 1rem;font-weight:800;font-size:1.05rem}' +
      '.fk-rc-ne{display:inline-block;background:#f3f4f6;color:#6b7280;border-radius:999px;padding:.35rem 1rem;font-weight:800;font-size:1.05rem}' +
      '.fk-rc-list{text-align:left}.fk-rc-row{display:flex;justify-content:space-between;align-items:center;gap:.6rem;border:1px solid #e5e7eb;border-radius:12px;padding:.6rem .8rem;margin-bottom:.45rem}' +
      '.fk-rc-row.cur{border-color:rgba(90,18,18,.35);background:rgba(90,18,18,.05)}' +
      '.fk-rc-row small{color:#6b7280}.fk-rc-row b{color:#5a1212}' +
      '.fk-rc-chip{font-size:.72rem;font-weight:700;border-radius:999px;padding:.15rem .55rem;white-space:nowrap}' +
      '.fk-rc-chip.dnt{background:#fef3c7;color:#92400e}.fk-rc-chip.ne{background:#f3f4f6;color:#6b7280}' +
      '.fk-rc-tot{border:1px solid #e5e7eb;border-radius:12px;padding:.7rem .8rem;margin-top:.5rem;text-align:left}' +
      '.fk-rc-note{color:#6b7280;font-size:.72rem;margin-top:.8rem}';
    (document.head || document.documentElement).appendChild(st);
  }

  function memberIdOf(input) {
    return parseInt(input.getAttribute('data-member-id') || input.getAttribute('data-mid') || '0', 10) || 0;
  }

  // ── private result card ────────────────────────────────────────────────
  var lastFocus = null;
  function closeCard() {
    var b = document.querySelector('.fk-rc-back');
    if (b) b.remove();
    document.removeEventListener('keydown', onKey, true);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (_) {} }
  }
  function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); closeCard(); } }

  function bigMark(row, fallbackText, max) {
    if (row && row.score_state === 'scored') {
      return '<span class="fk-rc-score" data-fk-rc-score>' + esc(label(row.score)) + '</span><span class="fk-rc-max">  / ' + esc(label(row.max_score)) + '</span>';
    }
    if (row && row.score_state === 'not_taken') return '<span class="fk-rc-dnt" data-fk-rc-state>Did not take</span>';
    if (row && row.score_state === 'not_entered' && !fallbackText) return '<span class="fk-rc-ne" data-fk-rc-state>Not entered yet</span>';
    if (fallbackText) {
      return '<span class="fk-rc-score" data-fk-rc-score>' + esc(fallbackText) + '</span><span class="fk-rc-max">  / ' + esc(label(max)) + '</span>';
    }
    return '<span class="fk-rc-ne" data-fk-rc-state>Not entered yet</span>';
  }

  function openCard(opts, memberId, name, code, typed) {
    css();
    closeCard();
    lastFocus = document.activeElement;
    var back = document.createElement('div');
    back.className = 'fk-rc-back';
    back.innerHTML =
      '<div class="fk-rc" role="dialog" aria-modal="true" aria-label="Result">' +
      '<div class="fk-rc-head"><strong>Result</strong><button type="button" class="fk-vo-btn" data-fk-rc-close>Close</button></div>' +
      '<div class="fk-rc-body">' +
      '<div class="fk-rc-name">' + esc(name) + '</div>' +
      (code ? '<div class="fk-rc-sub">' + esc(code) + '</div>' : '') +
      '<div class="fk-rc-sub" data-fk-rc-scope></div>' +
      '<div class="fk-rc-big"><div style="font-weight:700;font-size:1.05rem;margin-bottom:.8rem">' + esc(opts.assessmentName || '') + '</div>' +
      '<div data-fk-rc-mark>' + bigMark(null, typed, opts.maxScore) + '</div></div>' +
      '<div class="fk-rc-list"><div style="font-weight:800;margin-bottom:.45rem">Other results</div><div data-fk-rc-list>Loading…</div></div>' +
      '<div class="fk-rc-note">Only this student\u2019s results are shown.</div>' +
      '</div></div>';
    back.addEventListener('click', function (e) { if (e.target === back) closeCard(); });
    back.querySelector('[data-fk-rc-close]').addEventListener('click', closeCard);
    document.body.appendChild(back);
    document.addEventListener('keydown', onKey, true);
    back.querySelector('[data-fk-rc-close]').focus();

    fetch('/admin/api_subjects.php?action=student_results&assessment_id=' + encodeURIComponent(opts.assessmentId) +
      '&member_id=' + encodeURIComponent(memberId), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var list = back.querySelector('[data-fk-rc-list]');
        if (!d || d.status !== 'success') {
          list.textContent = (d && d.message) || 'Could not load the other results.';
          return;
        }
        var subj = d.subject || {};
        back.querySelector('[data-fk-rc-scope]').textContent =
          [subj.class_name, subj.subject_name, subj.term_name].filter(Boolean).join(' · ');
        var rows = d.results || [];
        var cur = null;
        rows.forEach(function (r) { if (r.is_current) cur = r; });
        back.querySelector('[data-fk-rc-mark]').innerHTML = bigMark(cur, typed, opts.maxScore);
        list.innerHTML = rows.length ? rows.map(function (r) {
          var right = r.score_state === 'scored'
            ? '<b>' + esc(label(r.score)) + ' / ' + esc(label(r.max_score)) + '</b>'
            : '<span class="fk-rc-chip ' + (r.score_state === 'not_taken' ? 'dnt' : 'ne') + '">' +
              (r.score_state === 'not_taken' ? 'Did not take' : 'Not entered yet') + '</span>';
          return '<div class="fk-rc-row' + (r.is_current ? ' cur' : '') + '" data-fk-rc-row="' + esc(r.assessment_id) + '"><div><div style="font-weight:' +
            (r.is_current ? 800 : 600) + '">' + esc(r.assessment_name) + '</div><small>' +
            esc([r.assessment_type, r.is_current ? 'this test' : ''].filter(Boolean).join(' · ')) + '</small></div>' + right + '</div>';
        }).join('') : '<div class="fk-rc-sub">No other tests yet.</div>';
        var t = d.totals || {};
        if (rows.length) {
          list.insertAdjacentHTML('beforeend',
            '<div class="fk-rc-tot"><div style="display:flex;justify-content:space-between"><strong>Marks so far</strong><b data-fk-rc-total style="color:#5a1212">' +
            esc(t.label || '—') + (t.percent != null ? '  (' + esc(label(t.percent)) + '%)' : '') + '</b></div>' +
            (t.not_taken > 0 ? '<div style="color:#92400e;font-size:.8rem;font-weight:600;margin-top:.3rem">Did not take ' + esc(t.not_taken) +
              ' test' + (t.not_taken === 1 ? '' : 's') + ' — not counted in the marks.</div>' : '') + '</div>');
        }
      })
      .catch(function () {
        back.querySelector('[data-fk-rc-list]').textContent = 'Could not load the other results. Check the connection.';
      });
  }

  // ── attach to one table ────────────────────────────────────────────────
  function attach(opts) {
    if (!opts || !opts.card || !opts.tbody || !opts.assessmentId) return;
    css();
    var card = opts.card, tbody = opts.tbody, aid = String(opts.assessmentId);
    var head = card.querySelector('[data-fk-vo-head]') || card.querySelector('.p-4') || card;
    var bar = head.querySelector('.fk-vo-bar');
    if (!bar) {
      bar = document.createElement('div');
      bar.className = 'fk-vo-bar';
      bar.innerHTML =
        '<button type="button" class="fk-vo-btn" data-fk-vo-toggle aria-pressed="false"></button>' +
        '<input type="search" class="fk-vo-search" data-fk-vo-search placeholder="Find a student by name or code" style="display:none" aria-label="Find a student">' +
        '<div class="fk-vo-banner" data-fk-vo-banner style="display:none">View only. Click 🔒 to edit. Click a student to show their result.</div>';
      head.appendChild(bar);
      bar.querySelector('[data-fk-vo-toggle]').addEventListener('click', function () {
        var st = bar._fkVo;
        if (!st) return;
        var on = !isOn(st.aid);
        remember(st.aid, on);
        apply(st, on);
      });
      bar.querySelector('[data-fk-vo-search]').addEventListener('input', function () {
        if (bar._fkVo) filter(bar._fkVo, this.value);
      });
      tbody.addEventListener('click', function (e) {
        var st = bar._fkVo;
        if (!st || !isOn(st.aid)) return;
        var tr = e.target.closest('tr');
        if (!tr || !tbody.contains(tr)) return;
        var input = tr.querySelector(st.input);
        if (!input) return;
        var cells = tr.querySelectorAll('td');
        var name = cells[1] ? cells[1].textContent.trim() : '';
        var code = cells[2] ? cells[2].textContent.trim() : '';
        if (code === '—') code = '';
        openCard(st, memberIdOf(input), name, code, (input.value || '').trim());
      });
    }
    // New render (or another test): fresh state, same bar.
    bar._fkVo = {
      aid: aid, card: card, tbody: tbody, bar: bar,
      input: opts.input || '.grade-input', extra: opts.extra || '',
      buttons: opts.buttons || [], assessmentId: aid,
      assessmentName: opts.assessmentName || '', maxScore: opts.maxScore
    };
    var search = bar.querySelector('[data-fk-vo-search]');
    search.value = '';
    apply(bar._fkVo, isOn(aid));
  }

  function boxes(st) {
    var sel = st.input + (st.extra ? ', ' + st.extra : '');
    return Array.prototype.slice.call(st.tbody.querySelectorAll(sel));
  }

  function apply(st, on) {
    var btn = st.bar.querySelector('[data-fk-vo-toggle]');
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.textContent = on ? '🔒 View only — click to edit' : '👁 View only';
    btn.title = on ? 'Unlock to edit marks' : 'Lock this list to view only (no accidental changes)';
    st.bar.querySelector('[data-fk-vo-banner]').style.display = on ? '' : 'none';
    var search = st.bar.querySelector('[data-fk-vo-search]');
    search.style.display = on ? '' : 'none';
    if (!on) { search.value = ''; filter(st, ''); }
    boxes(st).forEach(function (el) {
      if (on) {
        if (!el.hasAttribute('data-fk-vo-was')) el.setAttribute('data-fk-vo-was', el.disabled ? '1' : '0');
        el.disabled = true;
        el.classList.add('fk-vo-ro');
      } else if (el.hasAttribute('data-fk-vo-was')) {
        el.disabled = el.getAttribute('data-fk-vo-was') === '1';
        el.removeAttribute('data-fk-vo-was');
        el.classList.remove('fk-vo-ro');
      }
    });
    Array.prototype.forEach.call(st.tbody.querySelectorAll('tr'), function (tr) {
      if (tr.querySelector(st.input)) tr.classList.toggle('fk-vo-row', on);
    });
    st.buttons.forEach(function (id) {
      var b = document.getElementById(id);
      if (!b) return;
      if (on) {
        if (!b.hasAttribute('data-fk-vo-was')) b.setAttribute('data-fk-vo-was', b.disabled ? '1' : '0');
        b.disabled = true;
        b.classList.add('fk-vo-off');
      } else if (b.hasAttribute('data-fk-vo-was')) {
        b.disabled = b.getAttribute('data-fk-vo-was') === '1';
        b.removeAttribute('data-fk-vo-was');
        b.classList.remove('fk-vo-off');
      }
    });
  }

  function filter(st, q) {
    q = String(q || '').trim().toLowerCase();
    Array.prototype.forEach.call(st.tbody.querySelectorAll('tr'), function (tr) {
      if (!tr.querySelector(st.input)) return;
      tr.style.display = !q || tr.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
  }

  /** True while View only is on for this test — Save/Submit refuse. */
  function on(aid) { return isOn(String(aid)); }

  global.FKSSViewOnly = { attach: attach, isOn: on, openCard: openCard, close: closeCard };
})(window);
