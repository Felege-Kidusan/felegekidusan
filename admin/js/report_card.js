/**
 * Shared report-card renderer for Education and the teacher portal.
 * Numbers come from the server. This file only paints and prints.
 */
(function (global) {
  'use strict';

  function esc(t) {
    const d = document.createElement('div');
    d.textContent = t == null ? '' : String(t);
    return d.innerHTML;
  }

  function letterColor(letter) {
    return ({ A: '#047857', B: '#0369a1', C: '#b45309', D: '#c2410c', F: '#b91c1c' }[letter] || '#64748b');
  }

  function dash(v, suffix) {
    if (v === null || v === undefined || v === '') return '—';
    return suffix ? v + suffix : String(v);
  }

  function doneBar(c) {
    if (!c || typeof c !== 'object') return '';
    const rec = Number(c.recorded || 0);
    const left = Number(c.remaining || 0);
    const items = Array.isArray(c.items) ? c.items : [];
    const list = items.length
      ? '<ul class="rc-items">' + items.map(function (it) {
          const w = it.weight != null ? it.weight + '%' : '';
          const cls = it.recorded ? 'ok' : 'wait';
          const st = it.recorded ? 'Recorded' : 'Still left';
          return '<li class="' + cls + '"><span>' + esc(it.name || '') + (w ? ' · ' + w : '') + '</span><span>' + st + '</span></li>';
        }).join('') + '</ul>'
      : ((c.missing || []).length ? '<div class="rc-done-miss">Still left: ' + esc((c.missing || []).join(', ')) + '</div>' : '');
    return '<div class="rc-done">' +
      '<div class="rc-donebar"><i style="width:' + rec + '%"></i><em></em></div>' +
      '<div class="rc-done-lbl">' + rec + '% of this semester recorded · <span class="left">' + left + '% still left</span></div>' +
      list +
      '</div>';
  }

  function renderSheet(data) {
    if (!data || data.status !== 'success') {
      return '<div class="rc-sheet"><div class="rc-empty">This report card could not be opened.</div></div>';
    }
    const brand = data.brand || {};
    const s = data.student || {};
    const cl = data.class || {};
    const yr = data.year || {};
    const tm = data.term || {};
    const att = data.attendance || { total: 0, present: 0, absent: 0, late: 0, excused: 0, rate: 0 };
    const subjects = data.subjects || [];
    const totals = data.totals || {};
    const oa = data.overall_average != null ? data.overall_average : totals.average;
    const og = data.overall_grade || totals.grade_letter;
    const rank = data.rank;
    const total = data.total_in_class || 0;
    const hl = data.highlights || {};
    const scale = data.grade_scale || [];
    const isAnnualView = !(data.term && data.term.id);
    const logo = brand.logo || '/themes/fkss/assets/logos/school_logo.png';
    // If the logo ever fails to load, fall back to an inline gold-cross seal
    // so the header grid keeps its three columns (never display:none).
    const logoFallback = "data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 40 40%27><text x=%2720%27 y=%2728%27 font-size=%2728%27 text-anchor=%27middle%27 fill=%27%23c7a347%27>+%3C/text></svg>";
    const period = [yr.year_name, tm && tm.term_name].filter(Boolean).join(' · ');

    // ── 1.6.9: A4-landscape statement layout (reference design, readability
    // pass). Left column: profile, metrics, subject summary, attendance,
    // scale & signatures. Right column: the assessment ledger — every
    // assessment one row per semester, aligned numeric columns, semester
    // totals, annual in the summary table.
    function semTotal(v) {
      return v != null ? Number(v).toFixed(1) + '%' : '—';
    }

    function durationNote(sub) {
      const dt = sub.duration_type || '';
      const st = sub.subject_status || '';
      let label = '';
      if (st === 'CONTINUING') {
        label = 'Full year — continues next semester';
      } else if (dt === 'FULL_YEAR') {
        label = 'Full year';
      } else if (dt === 'SEMESTER_ONLY') {
        label = 'Semester only';
      }
      if (!label) return '';
      return '<div class="rc-dur">' + esc(label) + '</div>';
    }

    // ── left column ──────────────────────────────────────────────────
    const profileHtml =
      '<section class="rc-card"><div class="rc-card-head"><span>Student profile</span><span class="am">የተማሪ መረጃ</span></div>' +
      '<div class="rc-card-body rc-identity">' +
        '<div class="rc-field"><label>Name / ስም</label><strong class="am">' + esc(s.student_name || '') + '</strong></div>' +
        '<div class="rc-field"><label>Student ID</label><strong>' + esc(s.member_code || '—') + '</strong></div>' +
        '<div class="rc-field"><label>Father / አባት</label><strong class="am">' + esc(s.father_name || '') + '</strong></div>' +
        '<div class="rc-field"><label>Gender / ጾታ</label><strong>' + esc(s.gender === 'male' ? 'Male' : (s.gender === 'female' ? 'Female' : '—')) + '</strong></div>' +
        '<div class="rc-field rc-field-wide"><label>Class / ክፍል</label><strong class="am">' + esc(cl.class_name || '') +
          (cl.class_name_en ? ' <span class="rc-soft">(' + esc(cl.class_name_en) + ')</span>' : '') + '</strong>' +
          (s.christian_name ? '<label>Christian name</label><strong class="am">' + esc(s.christian_name) + '</strong>' : '') + '</div>' +
      '</div></section>';

    const metricsHtml =
      '<div class="rc-metrics">' +
        '<div class="rc-metric"><div class="rc-mv">' + (oa != null ? esc(oa) + '%' : '—') + '</div><div class="rc-ml">Overall average</div></div>' +
        '<div class="rc-metric"><div class="rc-mv rc-mv-g">' + esc(og || '—') + '</div><div class="rc-ml">Grade</div></div>' +
        '<div class="rc-metric"><div class="rc-mv">' + (rank ? esc(rank) + (data.rank_tied ? '=' : '') + '<span class="rc-of">/' + esc(total) + '</span>' : '—') + '</div><div class="rc-ml">Class rank</div></div>' +
        '<div class="rc-metric"><div class="rc-mv">' + esc(att.rate || 0) + '%</div><div class="rc-ml">Attendance</div></div>' +
      '</div>';

    const summaryRows = subjects.length
      ? subjects.map(function (sub) {
          const s1 = sub.semester_1_score != null ? Number(sub.semester_1_score).toFixed(1) + '%' : '—';
          const s2 = sub.semester_2_score != null ? Number(sub.semester_2_score).toFixed(1) + '%' : '—';
          const fin = sub.final_percentage != null ? Number(sub.final_percentage).toFixed(1) + '%' : '—';
          const badge = sub.grade_letter
            ? '<span class="rc-badge">' + esc(sub.grade_letter) + '</span>'
            : '<span class="rc-badge rc-badge-off">—</span>';
          return '<tr>' +
            '<td class="rc-subj-cell"><span class="am">' + esc(sub.subject_name || '') + '</span>' +
              (sub.subject_name_en ? ' <small>' + esc(sub.subject_name_en) + '</small>' : '') + '</td>' +
            (isAnnualView
              ? '<td class="num">' + s1 + '</td><td class="num">' + s2 + '</td>'
              : '<td class="num">' + (sub.average != null ? Number(sub.average).toFixed(1) + '%' : '—') + '</td>') +
            '<td class="num"><b>' + fin + '</b></td>' +
            '<td class="num">' + badge + '</td>' +
            '</tr>';
        }).join('') +
        '<tr class="rc-total-row"><td>Overall</td>' +
        (isAnnualView ? '<td class="num">—</td><td class="num">—</td>' : '<td class="num">—</td>') +
        '<td class="num"><b>' + (oa != null ? Number(oa).toFixed(1) + '%' : '—') + '</b></td>' +
        '<td class="num"><span class="rc-badge">' + esc(og || '—') + '</span></td></tr>'
      : '<tr><td colspan="5" class="rc-empty">No subjects yet.</td></tr>';

    const summaryHtml =
      '<section class="rc-card"><div class="rc-card-head"><span>' +
      (isAnnualView ? 'Annual subject summary' : 'Subject summary') + '</span><span class="am">' +
      (isAnnualView ? 'ዓመታዊ ውጤት' : 'ውጤት') + '</span></div>' +
      '<table class="rc-table rc-summary"><thead><tr>' +
        '<th>Subject</th>' +
        (isAnnualView
          ? '<th class="num">1st sem.</th><th class="num">2nd sem.</th><th class="num">Annual</th>'
          : '<th class="num">Average</th><th class="num">Result</th>') +
        '<th class="num">Grade</th>' +
      '</tr></thead><tbody>' + summaryRows + '</tbody></table></section>';

    const attTotal = Number(att.total) || 0;
    const presence = attTotal > 0
      ? ((att.rate >= 95) ? 'Excellent' : ((att.rate >= 90) ? 'Very good' : ((att.rate >= 80) ? 'Good' : 'Needs attention')))
      : '—';
    const attBar = attTotal > 0
      ? '<div class="rc-progress"><span style="width:' + (att.present / attTotal * 100) + '%"></span><i style="width:' + (att.late / attTotal * 100) + '%"></i></div>'
      : '<div class="rc-progress"></div>';
    const attendanceHtml =
      '<section class="rc-card"><div class="rc-card-head"><span>Attendance &amp; reflections</span><span class="am">ክትትል</span></div>' +
      '<div class="rc-card-body rc-att">' +
        '<div class="rc-att-big"><div class="rc-big">' + esc(att.rate || 0) + '%</div>' + attBar + '</div>' +
        '<div class="rc-att-detail">' +
          '<strong>' + esc(att.present || 0) + ' present</strong>' +
          '<div class="rc-note">' + esc(att.absent || 0) + ' absent · ' + esc(att.late || 0) + ' late' +
            ((att.excused || 0) ? ' · ' + esc(att.excused) + ' excused' : '') + ' / ' + esc(att.total || 0) + ' days</div>' +
          '<div class="rc-statline"><span>Presence</span><b>' + esc(presence) + '</b></div>' +
        '</div>' +
      '</div>' +
      '<div class="rc-card-body rc-two">' +
        '<div class="rc-insight"><label>Strongest subject</label><strong class="am">' + esc((hl.strongest && hl.strongest.subject_name) || '—') +
          (hl.strongest && hl.strongest.average != null ? ' · ' + hl.strongest.average + '%' : '') + '</strong></div>' +
        '<div class="rc-insight"><label>Needs attention</label><strong class="am">' + esc((hl.weakest && hl.weakest.subject_name) || '—') +
          (hl.weakest && hl.weakest.average != null ? ' · ' + hl.weakest.average + '%' : '') + '</strong></div>' +
      '</div></section>';

    const scaleTxt = scale.length
      ? scale.map(function (g) { return '<b>' + esc(g.letter) + '</b> ' + esc(g.min) + '–' + esc(g.max); }).join(' · ')
      : '<b>A</b> 90–100 · <b>B</b> 80–89 · <b>C</b> 70–79 · <b>D</b> 60–69 · <b>F</b> below 60';
    const authHtml =
      '<section class="rc-card"><div class="rc-card-head"><span>Grade scale &amp; authorization</span><span class="am">ማረጋገጫ</span></div>' +
      '<div class="rc-card-body">' +
        '<div class="rc-scale-line">' + scaleTxt + ' · Pass mark ' + esc(data.pass_mark != null ? data.pass_mark : 50) + '%</div>' +
        (isAnnualView ? '<div class="rc-note rc-note-mid">Annual = average of the two semester totals. “—” = not offered that semester.</div>' : '') +
        '<div class="rc-signs"><div class="rc-sign">Class Teacher</div><div class="rc-sign">' + esc(brand.sig_head || 'Education Department') + '</div></div>' +
      '</div></section>';

    // ── right column: the assessment ledger ──────────────────────────
    function slot(a) {
      const score = a.score != null ? esc(a.score) : '—';
      const mx = a.max_score != null ? '/' + esc(a.max_score) : '';
      return '<div class="rc-slot">' +
        '<span class="rc-slot-name">' + esc(a.assessment_name || '') + '</span>' +
        '<span class="rc-slot-score">' + score + mx + '</span>' +
        '<span class="rc-slot-wt">' + esc(a.weight_percentage != null ? a.weight_percentage + '%' : '—') + '</span>' +
        '<span class="rc-slot-pct">' + esc(a.percentage != null ? a.percentage + '%' : '—') + '</span>' +
        '</div>';
    }

    function semesterCell(detail, sub, isS1) {
      const dt = sub.duration_type || '';
      const offered = sub.offering_term_number || 0;
      if (dt === 'SEMESTER_ONLY' && offered && ((isS1 && offered !== 1) || (!isS1 && offered !== 2))) {
        return '<div class="rc-sem-cell"><div class="rc-slot rc-slot-off"><span class="rc-slot-name">Not offered this semester</span><span class="rc-slot-score">—</span><span class="rc-slot-wt">—</span><span class="rc-slot-pct">—</span></div></div>';
      }
      if (dt === 'SEMESTER_ONLY' && !offered && detail && detail.total == null && !(detail.assessments || []).length) {
        const other = isS1 ? (sub.semester_2_score != null) : (sub.semester_1_score != null);
        if (other) {
          return '<div class="rc-sem-cell"><div class="rc-slot rc-slot-off"><span class="rc-slot-name">Not offered this semester</span><span class="rc-slot-score">—</span><span class="rc-slot-wt">—</span><span class="rc-slot-pct">—</span></div></div>';
        }
      }
      const list = (detail && detail.assessments || []).map(slot).join('');
      return '<div class="rc-sem-cell">' +
        (list || '<div class="rc-slot rc-slot-off"><span class="rc-slot-name">No scores yet</span><span class="rc-slot-score">—</span><span class="rc-slot-wt">—</span><span class="rc-slot-pct">—</span></div>') +
        '</div>';
    }

    function subjectCell(sub) {
      return '<div class="rc-ledg-subj"><span class="am">' + esc(sub.subject_name || '') + '</span>' +
        (sub.subject_name_en ? '<small>' + esc(sub.subject_name_en) + '</small>' : '') +
        durationNote(sub) +
        ((sub.untagged_mark_rows || 0) > 0 ? '<div class="rc-dur">' + esc(sub.untagged_mark_rows) + ' mark(s) without a semester</div>' : '') +
        '</div>';
    }

    let ledgerBody;
    if (isAnnualView) {
      ledgerBody = subjects.map(function (sub) {
        const det = sub.semester_detail || { s1: { assessments: [], total: null }, s2: { assessments: [], total: null } };
        return '<div class="rc-ledg-row">' +
          subjectCell(sub) +
          semesterCell(det.s1, sub, true) +
          '<div class="rc-ledg-total">' + semTotal(det.s1 && det.s1.total) + '</div>' +
          semesterCell(det.s2, sub, false) +
          '<div class="rc-ledg-total">' + semTotal(det.s2 && det.s2.total) + '</div>' +
          '</div>';
      }).join('');
    } else {
      ledgerBody = subjects.map(function (sub) {
        return '<div class="rc-ledg-row rc-ledg-row-term">' +
          subjectCell(sub) +
          '<div class="rc-sem-cell">' +
            ((sub.assessments || []).map(slot).join('') ||
              '<div class="rc-slot rc-slot-off"><span class="rc-slot-name">No scores yet</span><span class="rc-slot-score">—</span><span class="rc-slot-wt">—</span><span class="rc-slot-pct">—</span></div>') +
          '</div>' +
          '<div class="rc-ledg-total">' + semTotal(sub.average) + '</div>' +
          '</div>';
      }).join('');
    }

    const ledgerHtml =
      '<section class="rc-ledger"><div class="rc-ledg-title"><span>Assessment ledger · ' +
        (isAnnualView ? 'both semesters' : esc(tm.term_name || 'Semester')) + '</span><span class="am">የፈተና ዝርዝር</span></div>' +
      '<div class="rc-legend"><span><b>Name</b> · score/max · <b>weight</b> · weighted %</span>' +
        '<span><b>—</b> = no score entered</span></div>' +
      (isAnnualView
        ? '<div class="rc-ledg-grid rc-ledg-head"><div>Subject</div><div>1st semester · 1ኛ ሴሚስተር</div><div class="rc-c">Total</div><div>2nd semester · 2ኛ ሴሚስተር</div><div class="rc-c">Total</div></div>'
        : '<div class="rc-ledg-grid rc-ledg-grid-term rc-ledg-head"><div>Subject</div><div>Assessments</div><div class="rc-c">Total</div></div>') +
      (ledgerBody || '<div class="rc-ledg-row"><div class="rc-empty">No subjects or scores for this class yet.</div></div>') +
      '<div class="rc-ledg-foot"><span><b>Total</b> = semester result from 100%</span>' +
        '<span><b>Annual</b> = average of the semester totals</span>' +
        '<span><b>A4</b> landscape</span></div>' +
      '</section>';

    // ── page frame ───────────────────────────────────────────────────
    return '<article class="rc-sheet rc-a4l">' +
      '<header class="rc-head">' +
        '<img class="rc-logo" src="' + esc(logo) + '" alt="" onerror="this.onerror=null;this.src=\'' + logoFallback + '\'">' +
        '<div class="rc-head-main">' +
          (brand.invocation ? '<div class="rc-invoc am">' + esc(brand.invocation) + '</div>' : '') +
          '<div class="rc-school-am am">' + esc(brand.school_am || '') + '</div>' +
          '<div class="rc-school-en">' + esc(brand.school_en || '') + '</div>' +
          (brand.parish_en ? '<div class="rc-parish">' + esc(brand.parish_en) + '</div>' : '') +
        '</div>' +
        '<div class="rc-head-title"><b>Student Report Card</b>' +
          '<span>' + esc(period) + (data.issued_on ? ' · Issued ' + esc(data.issued_on) : '') + '</span>' +
          '<span class="am">' + (isAnnualView ? 'ዓመታዊ የተማሪ ሪፖርት ካርድ' : 'የሴሚስተር ሪፖርት ካርድ') + '</span></div>' +
      '</header>' +
      '<section class="rc-content">' +
        '<div class="rc-col-left">' + profileHtml + metricsHtml + summaryHtml + attendanceHtml + authHtml + '</div>' +
        '<div class="rc-col-right">' + ledgerHtml + '</div>' +
      '</section>' +
      '<footer class="rc-foot">' +
        '<span><strong>' + esc(brand.school_en || '') + '</strong>' + (brand.parish_en ? ' · ' + esc(brand.parish_en) : '') + '</span>' +
        '<span>' + esc(s.member_code ? 'Student ID ' + s.member_code : '') + ' · Confidential academic record</span>' +
      '</footer>' +
    '</article>';
  }

  function ensurePrintRoot() {
    let root = document.getElementById('rcPrintRoot');
    if (!root) {
      root = document.createElement('div');
      root.id = 'rcPrintRoot';
      document.body.appendChild(root);
    }
    return root;
  }

  function hideAiForPrint() {
    const fab = document.getElementById('ai-fab');
    const win = document.getElementById('ai-win');
    if (fab) fab.style.display = 'none';
    if (win) win.style.display = 'none';
    return function () {
      if (fab) fab.style.display = '';
      if (win) win.style.display = '';
    };
  }

  function printSheets(cards) {
    const list = Array.isArray(cards) ? cards : [cards];
    const root = ensurePrintRoot();
    root.innerHTML = list.map(renderSheet).join('');
    document.body.classList.add('rc-print-mode');
    const restoreAi = hideAiForPrint();
    const done = function () {
      document.body.classList.remove('rc-print-mode');
      restoreAi();
      window.removeEventListener('afterprint', done);
    };
    window.addEventListener('afterprint', done);
    window.print();
    setTimeout(done, 1500);
  }

  function fillModal(bodyEl, data) {
    if (!bodyEl) return;
    bodyEl.innerHTML = renderSheet(data) +
      '<div class="rc-actions no-print">' +
        '<button type="button" class="btn btn-o" data-rc-close>Close</button>' +
        '<button type="button" class="btn btn-p" data-rc-print><i class="fa-solid fa-print"></i> Print</button>' +
      '</div>';
    const closeBtn = bodyEl.querySelector('[data-rc-close]');
    const printBtn = bodyEl.querySelector('[data-rc-print]');
    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        const modal = bodyEl.closest('.mo') || document.getElementById('rcModal') || document.getElementById('reportCardModal');
        if (modal) {
          modal.classList.remove('show');
          modal.style.display = 'none';
        }
      });
    }
    if (printBtn) {
      printBtn.addEventListener('click', function () { printSheets(data); });
    }
  }

  global.FKSSReportCard = {
    renderSheet: renderSheet,
    fillModal: fillModal,
    printSheets: printSheets,
    doneBar: doneBar,
    letterColor: letterColor,
    esc: esc,
    dash: dash
  };
})(window);
