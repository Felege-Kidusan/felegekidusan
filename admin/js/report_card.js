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
    // so the cover keeps its structure (never display:none).
    const logoFallback = "data:image/svg+xml;utf8,<svg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 40 40%27><text x=%2720%27 y=%2728%27 font-size=%2728%27 text-anchor=%27middle%27 fill=%27%23c7a347%27>+%3C/text></svg>";

    // ── 1.6.10: foldable report card (approved design). One A4 portrait
    // sheet for the inside face (profile + metrics + attendance on the top
    // half, the single totals table + signatures on the bottom half) and
    // one for the outside face (back cover on top, front cover below).
    // Print double-sided (duplex, flip on SHORT edge), fold along the
    // middle — a classic A5 school report card booklet. Per-assessment
    // detail is intentionally GONE: totals only, one table.
    function num(v) {
      return v != null && v !== '' ? Number(v).toFixed(1) : null;
    }
    function cell(v) {
      const n = num(v);
      return n === null ? '<span class="rc-dash">—</span>' : n;
    }

    // ── inside top: profile + metrics ─────────────────────────────────
    const profileHtml =
      '<div class="rc-card rc-profile">' +
        '<div class="rc-card-head"><span>Student profile</span><span class="am">የተማሪ መረጃ</span></div>' +
        '<div class="rc-card-body">' +
          '<div class="rc-plabel">Name · <span class="am">ስም</span></div>' +
          '<div class="rc-pvalue am rc-pname">' + esc(s.student_name || '') + '</div>' +
          '<div class="rc-prow"><div><div class="rc-plabel">Father · <span class="am">አባት</span></div><div class="rc-pvalue am">' + esc(s.father_name || '—') + '</div></div>' +
          '<div><div class="rc-plabel">Christian name</div><div class="rc-pvalue am">' + esc(s.christian_name || '—') + '</div></div></div>' +
          '<div class="rc-prow"><div><div class="rc-plabel">Student ID</div><div class="rc-pvalue">' + esc(s.member_code || '—') + '</div></div>' +
          '<div><div class="rc-plabel">Gender · <span class="am">ጾታ</span></div><div class="rc-pvalue">' + esc(s.gender === 'male' ? 'Male' : (s.gender === 'female' ? 'Female' : '—')) + '</div></div></div>' +
        '</div>' +
      '</div>';

    const metricsHtml =
      '<div class="rc-metrics">' +
        '<div class="rc-metric"><div class="rc-mv">' + (oa != null ? num(oa) + '%' : '—') + '</div><div class="rc-ml">Overall average</div></div>' +
        '<div class="rc-metric"><div class="rc-mv rc-mv-g">' + esc(og || '—') + '</div><div class="rc-ml">Grade</div></div>' +
        '<div class="rc-metric"><div class="rc-mv">' + (rank ? esc(rank) + (data.rank_tied ? '=' : '') + '<span class="rc-of">/' + esc(total) + '</span>' : '—') + '</div><div class="rc-ml">Class rank</div></div>' +
        '<div class="rc-metric"><div class="rc-mv rc-mv-gr">' + esc(att.rate || 0) + '%</div><div class="rc-ml">Attendance</div></div>' +
      '</div>';

    const attTotal = Number(att.total) || 0;
    const presence = attTotal > 0
      ? ((att.rate >= 95) ? 'Excellent' : ((att.rate >= 90) ? 'Very good' : ((att.rate >= 80) ? 'Good' : 'Needs attention')))
      : '—';
    const attBar = attTotal > 0
      ? '<div class="rc-progress"><span style="width:' + (att.present / attTotal * 100) + '%"></span><i style="width:' + (att.late / attTotal * 100) + '%"></i></div>'
      : '<div class="rc-progress"></div>';
    const attHtml =
      '<div class="rc-card rc-att">' +
        '<div class="rc-card-head"><span>Attendance &amp; reflections</span><span class="am">ክትትል</span></div>' +
        '<div class="rc-card-body rc-att-body">' +
          '<div class="rc-att-left"><div class="rc-big">' + esc(att.rate || 0) + '%</div>' + attBar +
            '<div class="rc-statline"><span>Presence</span><b>' + esc(presence) + '</b></div></div>' +
          '<div class="rc-att-right">' +
            '<div class="rc-attnum"><b>' + esc(att.present || 0) + '</b> present · <b>' + esc(att.absent || 0) + '</b> absent · <b>' + esc(att.late || 0) + '</b> late / <b>' + esc(att.total || 0) + '</b> days</div>' +
            '<div class="rc-insight"><label>Strongest subject</label><span class="am">' + esc((hl.strongest && hl.strongest.subject_name) || '—') +
              (hl.strongest && hl.strongest.average != null ? ' · ' + hl.strongest.average + '%' : '') + '</span></div>' +
            '<div class="rc-insight"><label>Needs attention</label><span class="am">' + esc((hl.weakest && hl.weakest.subject_name) || '—') +
              (hl.weakest && hl.weakest.average != null ? ' · ' + hl.weakest.average + '%' : '') + '</span></div>' +
          '</div>' +
        '</div>' +
      '</div>';

    const insideTop =
      '<div class="rc-panel rc-panel-top">' +
        '<div class="rc-top-grid">' + profileHtml + metricsHtml + '</div>' + attHtml +
      '</div>';

    // ── inside bottom: the one totals table ───────────────────────────
    function subjectRow(sub) {
      const annual = num(sub.final_percentage);
      const continuing = sub.subject_status === 'CONTINUING' || (sub.duration_type === 'FULL_YEAR' && annual === null && (sub.semester_1_score != null || sub.semester_2_score != null));
      const semOnly = sub.duration_type === 'SEMESTER_ONLY';
      const grade = sub.grade_letter
        ? '<span class="rc-badge">' + esc(sub.grade_letter) + '</span>'
        : '<span class="rc-badge rc-badge-off">—</span>';
      const annualCell = annual === null
        ? (continuing ? '<span class="rc-dash">—&thinsp;*</span>' : '<span class="rc-dash">—</span>')
        : '<b>' + annual + (semOnly ? '&thinsp;†' : '') + '</b>';
      return '<tr>' +
        '<td class="rc-subj-cell"><span class="am">' + esc(sub.subject_name || '') + '</span>' +
          (sub.subject_name_en ? ' <small>· ' + esc(sub.subject_name_en) + '</small>' : '') +
          (semOnly ? ' <em class="rc-tag">semester subject</em>' : '') + '</td>' +
        (isAnnualView
          ? '<td class="num">' + cell(sub.semester_1_score) + '</td><td class="num">' + cell(sub.semester_2_score) + '</td>'
          : '<td class="num">' + cell(sub.average) + '</td>') +
        '<td class="num rc-annual-cell">' + annualCell + '</td>' +
        '<td class="num">' + grade + '</td>' +
        '</tr>';
    }

    const tableRows = subjects.length
      ? subjects.map(subjectRow).join('') +
        '<tr class="rc-total-row"><td>Overall · <span class="am">አጠቃላይ</span></td>' +
        (isAnnualView ? '<td class="num"><span class="rc-dash">—</span></td><td class="num"><span class="rc-dash">—</span></td>' : '<td class="num"><span class="rc-dash">—</span></td>') +
        '<td class="num"><b>' + (oa != null ? num(oa) + '%' : '—') + '</b></td>' +
        '<td class="num"><span class="rc-badge rc-badge-gold">' + esc(og || '—') + '</span></td></tr>'
      : '<tr><td colspan="5" class="rc-empty">No subjects yet.</td></tr>';

    const periodRight = [yr.year_name, tm && tm.term_name].filter(Boolean).join(' · ');
    const notesHtml = isAnnualView
      ? '<div class="rc-notes">Semester results are out of 100. <b>*</b>&thinsp;full-year subject still in progress — its annual result appears after the 2nd semester closes. <b>†</b>&thinsp;semester subject runs one semester only; that semester&rsquo;s result is its annual result. <b>—</b>&thinsp;= not offered / no result yet.</div>'
      : '<div class="rc-notes">Semester results are out of 100. <b>—</b>&thinsp;= not offered / no result yet. Annual figures are published on the annual card after the 2nd semester closes.</div>';

    const insideBottom =
      '<div class="rc-panel rc-panel-bottom">' +
        '<div class="rc-tbl-title"><span>' + (isAnnualView ? 'Annual subject summary' : 'Subject results') + ' · <span class="am">' + (isAnnualView ? 'ዓመታዊ ውጤት' : 'ውጤት') + '</span></span>' +
          '<span class="rc-tbl-period">' + esc(periodRight) + '</span></div>' +
        '<table class="rc-table rc-summary">' +
          '<thead><tr>' +
            '<th>Subject · <span class="am">ትምህርት</span></th>' +
            (isAnnualView
              ? '<th class="num">1st sem.</th><th class="num">2nd sem.</th><th class="num">Annual</th>'
              : '<th class="num">Result</th><th class="num">Annual</th>') +
            '<th class="num">Grade</th>' +
          '</tr></thead>' +
          '<tbody>' + tableRows + '</tbody>' +
        '</table>' +
        notesHtml +
        '<div class="rc-signs">' +
          '<div class="rc-sign">Class Teacher · <span class="am">አስተዳዳሪ</span> — signature &amp; date</div>' +
          '<div class="rc-sign">Education Department · <span class="am">የትምህርት ክፍል</span> — signature &amp; date</div>' +
        '</div>' +
      '</div>';

    // ── outside top: back cover ───────────────────────────────────────
    const scaleItems = scale.length
      ? scale.map(function (g) {
          return '<div><span class="rc-badge' + (g.letter === 'F' ? ' rc-badge-f' : '') + '">' + esc(g.letter) + '</span> ' + esc(g.min) + ' – ' + esc(g.max) + '</div>';
        }).join('')
      : '<div><span class="rc-badge">A</span> 90 – 100</div><div><span class="rc-badge">B</span> 80 – 89</div><div><span class="rc-badge">C</span> 70 – 79</div><div><span class="rc-badge">D</span> 60 – 69</div><div><span class="rc-badge rc-badge-f">F</span> below 60</div>';
    const readHtml = isAnnualView
      ? 'Each semester closes out of <b>100</b>. The <b>annual</b> result is the average of the two semesters. A <b>semester subject</b> runs one semester — that result is its annual result. <b>—</b> means not offered or no result yet.'
      : 'The semester closes out of <b>100</b>. Annual figures are the average of the two semesters and are published on the annual card once both close.';
    const backCover =
      '<div class="rc-panel rc-panel-top rc-back">' +
        '<div class="rc-back-grid">' +
          '<div class="rc-card"><div class="rc-card-head"><span>Grade scale</span><span class="am">የደረጃ መለኪያ</span></div>' +
            '<div class="rc-card-body rc-scale">' + scaleItems + '<div class="rc-scale-pass">Pass mark <b>' + esc(data.pass_mark != null ? data.pass_mark : 50) + '%</b></div></div></div>' +
          '<div class="rc-card"><div class="rc-card-head"><span>How to read</span><span class="am">አንባብ</span></div>' +
            '<div class="rc-card-body rc-read">' + readHtml + '</div></div>' +
        '</div>' +
        '<div class="rc-back-grid2">' +
          '<div class="rc-card rc-office"><div class="rc-card-head"><span>Office use</span><span class="am">ለጽሕፈት ቤት</span></div>' +
            '<div class="rc-card-body">' +
              '<div class="rc-office-line"><span>Received by / name</span><span class="rc-office-date">Date</span></div>' +
              '<div class="rc-office-line"><span>Parent / guardian signature</span><span class="rc-office-date">Date</span></div>' +
            '</div></div>' +
          '<div class="rc-stamp">School stamp<span class="am">ማህተም</span></div>' +
        '</div>' +
        '<div class="rc-back-foot"><span><b>' + esc(brand.school_en || '') + '</b>' + (brand.parish_en ? ' · ' + esc(brand.parish_en) : '') + '</span>' +
          '<span>' + (data.issued_on ? 'Issued ' + esc(data.issued_on) + ' · ' : '') + 'Confidential — deliver to parent / guardian</span></div>' +
      '</div>';

    // ── outside bottom: front cover ───────────────────────────────────
    const frontCover =
      '<div class="rc-panel rc-panel-bottom rc-front">' +
        '<div class="rc-cover-frame">' +
          '<div class="rc-cover-logo"><img src="' + esc(logo) + '" alt="" onerror="this.onerror=null;this.src=\'' + logoFallback + '\'"></div>' +
          '<div class="rc-cover-names">' +
            (brand.invocation ? '<div class="rc-invoc am">' + esc(brand.invocation) + '</div>' : '') +
            '<div class="rc-school-am am">' + esc(brand.school_am || '') + '</div>' +
            '<div class="rc-school-en">' + esc(brand.school_en || '') + '</div>' +
            (brand.parish_en ? '<div class="rc-parish">' + esc(brand.parish_en) + '</div>' : '') +
          '</div>' +
          '<div class="rc-cover-title">' +
            '<div class="rc-cover-t1">' + (isAnnualView ? 'Student<br>Report Card' : 'Semester<br>Report Card') + '</div>' +
            '<div class="am rc-cover-t2">' + (isAnnualView ? 'የተማሪ ሪፖርት ካርድ' : 'የሴሚስተር ሪፖርት ካርድ') + '</div>' +
            '<div class="rc-cover-badge">' + esc(periodRight || '') + '</div>' +
          '</div>' +
        '</div>' +
        '<div class="rc-cover-strip">' +
          '<div><div class="rc-plabel">Student · <span class="am">ተማሪ</span></div><div class="am rc-strip-name">' + esc(s.student_name || '') + '</div></div>' +
          '<div><div class="rc-plabel">Class · <span class="am">ክፍል</span></div><div class="am rc-strip-class">' + esc(cl.class_name || '') + (cl.class_name_en ? ' <small>(' + esc(cl.class_name_en) + ')</small>' : '') + '</div></div>' +
          '<div class="rc-strip-id"><div class="rc-plabel">Student ID</div><div class="rc-strip-code">' + esc(s.member_code || '—') + '</div></div>' +
        '</div>' +
      '</div>';

    const sheetLabel = function (n, inner) {
      return '<div class="rc-sheet-label no-print"><span>' + n + '</span> ' + inner + '</div>';
    };
    const fold = '<div class="rc-fold no-print"><span>fold line</span></div>';

    return '<article class="rc-sheet rc-book">' +
      '<section class="rc-book-page">' +
        sheetLabel('Sheet 1 · inside face', 'profile + results (duplex: print side 1)') +
        insideTop + fold + insideBottom +
      '</section>' +
      '<section class="rc-book-page">' +
        sheetLabel('Sheet 2 · outside face', 'back cover (top) + front cover (bottom) — duplex: flip on SHORT edge, then fold') +
        backCover + fold + frontCover +
      '</section>' +
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
