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
    const period = [yr.year_name, tm && tm.term_name].filter(Boolean).join(' · ');
    const logo = brand.logo || '/themes/fkss/assets/logos/school_logo.png';

      // How a subject is scheduled, straight from the backend. Without this a
      // full-year subject part way through the year shows a mark that looks
      // final. Nothing is calculated here - subject_status and duration_type
      // are decided by SubjectDurationPolicy and only displayed.
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
        return '<div style="font-size:.56rem;color:#6d28d9;font-weight:600;margin-top:.1rem">'
          + esc(label) + '</div>';
      }

      // ── 1.6.7 detailed annual layout (semester-based model) ──────────
      // Annual view: per subject, every assessment listed one by one under
      // its semester, each semester closing from 100%; then a summary table
      // (subject | 1st semester | 2nd semester | annual average). Semester-
      // only subjects show a dash in the semester they do not run.
      const isAnnualView = !(data.term && data.term.id);

      function semTotal(v) {
        return v != null ? Number(v).toFixed(1) + '%' : '—';
      }

      function assessmentLine(a) {
        const sc = a.score != null ? a.score : '—';
        const mx = a.max_score != null ? '/' + a.max_score : '';
        const wt = a.weight_percentage != null ? ' · ' + a.weight_percentage + '%' : '';
        const pc = a.percentage != null ? ' · ' + a.percentage + '%' : '';
        return '<div class="rc-detail-row"><span>' + esc(a.assessment_name || '') + '</span><span>'
          + esc(sc) + esc(mx) + esc(wt) + esc(pc) + '</span></div>';
      }

      function semesterGroup(label, detail, sub) {
        const dt = sub.duration_type || '';
        const offered = sub.offering_term_number || 0;
        const isS1 = label.indexOf('1') === 0;
        // A semester-only subject that does not run in this semester shows a
        // dash — the reader sees at a glance that the subject is semester-
        // based, not missing marks.
        if (dt === 'SEMESTER_ONLY' && offered && ((isS1 && offered !== 1) || (!isS1 && offered !== 2))) {
          return '<div class="rc-sem"><div class="rc-sem-hd">' + esc(label) + '</div>'
            + '<div class="rc-detail-row rc-dash"><span>Not offered this semester</span><span>—</span></div></div>';
        }
        if (dt === 'SEMESTER_ONLY' && !offered && detail && detail.total == null && !detail.assessments.length) {
          const other = isS1 ? (sub.semester_2_score != null) : (sub.semester_1_score != null);
          if (other) {
            return '<div class="rc-sem"><div class="rc-sem-hd">' + esc(label) + '</div>'
              + '<div class="rc-detail-row rc-dash"><span>Not offered this semester</span><span>—</span></div></div>';
          }
        }
        const list = (detail && detail.assessments || []).map(assessmentLine).join('');
        const total = detail ? detail.total : null;
        return '<div class="rc-sem"><div class="rc-sem-hd">' + esc(label)
          + '<span class="rc-sem-total">' + semTotal(total) + ' / 100%</span></div>'
          + (list || '<div class="rc-detail-row"><span>No scores yet</span><span>—</span></div>')
          + '</div>';
      }

      const detailedBlocks = subjects.length
        ? subjects.map(function (sub) {
            const det = sub.semester_detail || null;
            let blocks;
            if (det) {
              blocks = semesterGroup('1st Semester · 1ኛ ሴሚስተር', det.s1, sub)
                + semesterGroup('2nd Semester · 2ኛ ሴሚስተር', det.s2, sub);
            } else {
              // Legacy / term-scoped data without a semester split: keep the
              // flat assessment list so nothing is hidden.
              const chips = (sub.assessments || []).map(function (a) {
                return '<div class="rc-detail-row"><span>' + esc(a.assessment_name || '') + '</span><span>'
                  + esc(a.score != null ? a.score : '—') + (a.max_score != null ? '/' + a.max_score : '') + '</span></div>';
              }).join('');
              blocks = '<div class="rc-sem"><div class="rc-sem-hd">All assessments'
                + '<span class="rc-sem-total">' + semTotal(sub.average) + '</span></div>'
                + (chips || '<div class="rc-detail-row"><span>No scores yet</span><span>—</span></div>') + '</div>';
            }
            return '<div class="rc-subj">' +
              '<div class="rc-subj-hd"><span class="am">' + esc(sub.subject_name || '') + '</span>'
              + (sub.subject_name_en ? '<span class="rc-subj-en">' + esc(sub.subject_name_en) + '</span>' : '')
              + durationNote(sub) + '</div>' + blocks + '</div>';
          }).join('')
        : '<div class="rc-empty">No subjects or scores for this class yet.</div>';

      const summaryRows = subjects.length
        ? subjects.map(function (sub) {
            const s1 = sub.semester_1_score != null ? Number(sub.semester_1_score).toFixed(1) + '%' : '—';
            const s2 = sub.semester_2_score != null ? Number(sub.semester_2_score).toFixed(1) + '%' : '—';
            const fin = sub.final_percentage != null ? Number(sub.final_percentage).toFixed(1) + '%' : '—';
            return '<tr>'
              + '<td class="am">' + esc(sub.subject_name || '') + '</td>'
              + '<td class="num">' + s1 + '</td>'
              + '<td class="num">' + s2 + '</td>'
              + '<td class="num"><b>' + fin + '</b></td>'
              + '<td class="num"><span class="rc-letter ' + esc(sub.grade_letter || '') + '">' + esc(sub.grade_letter || '—') + '</span></td>'
              + '</tr>';
          }).join('') +
          '<tr class="rc-total"><td>Overall</td><td class="num">—</td><td class="num">—</td>'
          + '<td class="num">' + (oa != null ? Number(oa).toFixed(1) + '%' : '—') + '</td>'
          + '<td class="num"><span class="rc-letter ' + esc(og || '') + '">' + esc(og || '—') + '</span></td></tr>'
        : '<tr><td colspan="5" class="rc-empty">No subjects yet.</td></tr>';

      const subjectRows = subjects.length
        ? subjects.map(function (sub) {
            const gl = sub.grade_letter;
          const chips = (sub.assessments || []).map(function (a) {
            const sc = a.score != null ? a.score : '—';
            const mx = a.max_score != null ? a.max_score : '';
            return '<span class="rc-chip">' + esc(a.assessment_name || '') + ': ' + esc(sc) + (mx !== '' ? '/' + esc(mx) : '') + '</span>';
          }).join('');
          return '<tr>' +
            '<td class="am">' + esc(sub.subject_name || '') + (sub.subject_name_en ? '<div style="font-size:.58rem;color:#3b0000;font-weight:400">' + esc(sub.subject_name_en) + '</div>' : '') + durationNote(sub) + doneBar(sub.completion) + '</td>' +
            '<td><div class="rc-chips">' + (chips || '<span class="rc-chip">No scores yet</span>') + '</div></td>' +
            '<td class="num">' + dash(sub.obtained) + '</td>' +
            '<td class="num">' + dash(sub.max) + '</td>' +
            '<td class="num">' + (sub.average != null ? Number(sub.average).toFixed(1) + '%' : '—') + '</td>' +
            '<td class="num"><span class="rc-letter ' + esc(gl || '') + '">' + esc(gl || '—') + '</span></td>' +
            '</tr>';
        }).join('')
      : '<tr><td colspan="6" class="rc-empty">No subjects or scores for this class yet.</td></tr>';

    const attTotal = Number(att.total) || 0;
    const attBar = attTotal > 0
      ? '<div class="rc-att-bar">' +
          '<div style="width:' + (att.present / attTotal * 100) + '%;background:#047857"></div>' +
          '<div style="width:' + (att.late / attTotal * 100) + '%;background:#d97706"></div>' +
          '<div style="width:' + ((att.excused || 0) / attTotal * 100) + '%;background:#2563eb"></div>' +
          '<div style="width:' + (att.absent / attTotal * 100) + '%;background:#b91c1c"></div>' +
        '</div>'
      : '<div class="rc-att-bar"></div>';

    const scaleTxt = scale.length
      ? scale.map(function (g) { return g.letter + ' ' + g.min + '–' + g.max; }).join('  ·  ')
      : 'A 90–100  ·  B 80–89  ·  C 70–79  ·  D 60–69  ·  F below 60';

    return '<article class="rc-sheet">' +
      '<header class="rc-head">' +
        '<img class="rc-logo" src="' + esc(logo) + '" alt="" onerror="this.style.display=\'none\'">' +
        '<div class="rc-head-text">' +
          (brand.invocation ? '<div class="rc-invoc amharic">' + esc(brand.invocation) + '</div>' : '') +
          '<div class="rc-school-am amharic">' + esc(brand.school_am || '') + '</div>' +
          '<div class="rc-school-en">' + esc(brand.school_en || '') + '</div>' +
          (brand.parish_en ? '<div class="rc-parish">' + esc(brand.parish_en) + '</div>' : '') +
          '<div class="rc-title-row">Student Report Card · የተማሪ ሪፖርት ካርድ</div>' +
          '<div class="rc-subhead">' + esc(period) + (data.issued_on ? ' · Issued ' + esc(data.issued_on) : '') + '</div>' +
        '</div>' +
      '</header>' +
      '<div class="rc-body">' +
        '<div class="rc-meta">' +
          '<div><strong>Name</strong><span class="amharic">' + esc(s.student_name || '') + '</span></div>' +
          '<div><strong>Christian name</strong><span class="amharic">' + esc(s.christian_name || '—') + '</span></div>' +
          '<div><strong>Father</strong><span class="amharic">' + esc(s.father_name || '') + '</span></div>' +
          '<div><strong>ID</strong>' + esc(s.member_code || '—') + '</div>' +
          '<div><strong>Class</strong><span class="amharic">' + esc(cl.class_name || '') + '</span>' +
            (cl.class_name_en ? ' <span style="color:#8a7260">(' + esc(cl.class_name_en) + ')</span>' : '') + '</div>' +
          '<div><strong>Gender</strong>' + esc(s.gender === 'male' ? 'Male' : (s.gender === 'female' ? 'Female' : '—')) + '</div>' +
        '</div>' +
        '<div class="rc-kpis">' +
          '<div class="rc-kpi"><b>' + (oa != null ? esc(oa) + '%' : '—') + '</b><span>Overall average</span></div>' +
          '<div class="rc-kpi grade-' + esc(og || '') + '"><b>' + esc(og || '—') + '</b><span>Grade</span></div>' +
          '<div class="rc-kpi"><b>' + (rank ? esc(rank) + (data.rank_tied ? '=' : '') + '<span style="font-size:.7rem;font-weight:500"> / ' + esc(total) + '</span>' : '—') + '</b><span>Class rank</span></div>' +
          '<div class="rc-kpi"><b>' + esc(att.rate || 0) + '%</b><span>Attendance</span></div>' +
        '</div>' +
        (isAnnualView
          ? '<div class="rc-detail-wrap">' + detailedBlocks + '</div>'
            + '<table class="rc-table rc-summary">' +
              '<thead><tr>' +
                '<th>Subject</th><th class="num">1st Semester</th>' +
                '<th class="num">2nd Semester</th>' +
                '<th class="num">Annual (average)</th><th class="num">Grade</th>' +
              '</tr></thead>' +
              '<tbody>' + summaryRows + '</tbody>' +
            '</table>'
          : '') +
        '<table class="rc-table"' + (isAnnualView ? ' style="display:none"' : '') + '>' +
          '<thead><tr>' +
            '<th>Subject</th><th>Assessments</th>' +
            '<th class="num">Obtained</th><th class="num">Max</th>' +
            '<th class="num">Average</th><th class="num">Grade</th>' +
          '</tr></thead>' +
          '<tbody>' + subjectRows +
            (subjects.length ? '<tr class="rc-total">' +
              '<td>Overall</td>' +
              '<td>' + esc(totals.subjects_count || 0) + ' subject' + ((totals.subjects_count || 0) === 1 ? '' : 's') +
                ' · ' + esc(totals.assessments_count || 0) + ' assessment' + ((totals.assessments_count || 0) === 1 ? '' : 's') + '</td>' +
              '<td class="num">' + dash(totals.obtained) + '</td>' +
              '<td class="num">' + dash(totals.max) + '</td>' +
              '<td class="num">' + (oa != null ? Number(oa).toFixed(1) + '%' : '—') + '</td>' +
              '<td class="num"><span class="rc-letter ' + esc(og || '') + '">' + esc(og || '—') + '</span></td>' +
            '</tr>' : '') +
          '</tbody>' +
        '</table>' +
        '<div class="rc-att">' +
          '<div class="rc-att-label">Attendance: ' + esc(att.present || 0) + ' present, ' +
            esc(att.absent || 0) + ' absent, ' + esc(att.late || 0) + ' late' +
            ((att.excused || 0) ? ', ' + esc(att.excused) + ' excused' : '') +
            ' / ' + esc(att.total || 0) + ' days</div>' +
          attBar +
        '</div>' +
        '<div class="rc-hl">' +
          '<div><strong>Strongest subject</strong>' + esc((hl.strongest && hl.strongest.subject_name) || '—') +
            (hl.strongest && hl.strongest.average != null ? ' · ' + hl.strongest.average + '%' : '') + '</div>' +
          '<div><strong>Needs attention</strong>' + esc((hl.weakest && hl.weakest.subject_name) || '—') +
            (hl.weakest && hl.weakest.average != null ? ' · ' + hl.weakest.average + '%' : '') + '</div>' +
        '</div>' +
        '<div class="rc-scale">Grade scale: ' + esc(scaleTxt) + ' · Pass mark ' + esc(data.pass_mark != null ? data.pass_mark : 50) + '%</div>' +
        '<div class="rc-signs">' +
          '<div class="rc-sign"><div class="rc-sign-line"></div>Class Teacher</div>' +
          '<div class="rc-sign"><div class="rc-sign-line"></div>' + esc(brand.sig_head || 'Education Department') + '</div>' +
        '</div>' +
        '<div class="rc-foot">' + esc(brand.school_am || '') + ' · Official report card · Not valid without the school stamp</div>' +
      '</div>' +
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
