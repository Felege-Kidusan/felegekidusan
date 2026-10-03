/**
 * Behavioural harness for the Phase 2 Student Tracking workflow in
 * admin/js/academic_tracking.js.
 *
 * It boots the real controller against a stubbed network and a minimal DOM,
 * selects a student the way a person would, and then drives the five
 * sections. Every assertion is about behaviour, not about source text.
 *
 * The rules it exists to defend:
 *
 *   1. A student is tracked only after an explicit selection, and the
 *      member id that reaches the detail request is the one that was
 *      clicked.
 *   2. Overview, Subjects and Attendance cost ONE request between them;
 *      Assessments and the Report Card are fetched only when opened, and
 *      only once.
 *   3. Switching student discards the previous student's sections. No
 *      number from student A may ever appear under student B's name.
 *   4. An absent value is a dash or "Not available" — never 0, never 0%.
 *      "No attendance recorded" and "0% attendance" are different screens.
 *   5. A failed request says the load failed. It never degrades into an
 *      empty-data message.
 *   6. Assessment workflow status and the student's own result are shown
 *      as separate facts and never substituted for one another.
 *   7. Semester-only and full-year subjects keep their own duration, and
 *      no semester score is invented for a subject that has none.
 *   8. Scope (the student) is never written into the filters bag.
 *
 * Zero npm dependencies.
 *
 * Usage: node tests/e2e/student_tracking.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

// ── assertions ────────────────────────────────────────────────────────────

let passed = 0;
const failures = [];

function ok(label, cond, detail) {
  if (cond) { passed++; return; }
  failures.push(label + (detail ? ' — ' + detail : ''));
}
function eq(label, expected, actual) {
  ok(label, expected === actual, 'expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
}
function has(label, haystack, needle) {
  ok(label, String(haystack).indexOf(needle) !== -1, 'missing: ' + needle);
}
function hasNot(label, haystack, needle) {
  ok(label, String(haystack).indexOf(needle) === -1, 'unexpectedly present: ' + needle);
}
function pick(root, sel, i, label) {
  const els = root.querySelectorAll(sel);
  if (!els[i]) {
    failures.push(label + ' — no element ' + sel + '[' + i + '] (found ' + els.length + ')');
    return makeEl('div', {});
  }
  passed++;
  return els[i];
}

// ── minimal DOM (same shim as the Phase 1 harness) ────────────────────────

function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function parseAttrs(raw) {
  const attrs = {};
  const ar = /([a-zA-Z0-9-]+)="([^"]*)"/g;
  let a;
  while ((a = ar.exec(raw)) !== null) attrs[a[1]] = a[2];
  return attrs;
}
function makeEl(tag, attrs) {
  return {
    tagName: (tag || 'div').toUpperCase(),
    _attrs: attrs || {},
    _html: '',
    _text: '',
    _cache: null,
    style: { cssText: '' },
    className: (attrs && attrs.class) || '',
    value: (attrs && attrs.value) || '',
    listeners: {},
    get innerHTML() { return this._html; },
    set innerHTML(v) { this._html = String(v); this._cache = null; },
    get textContent() { return this._text; },
    set textContent(v) { this._text = String(v); this._html = escapeHtml(v); },
    setAttribute(k, v) { this._attrs[k] = String(v); },
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null; },
    addEventListener(ev, fn) { (this.listeners[ev] = this.listeners[ev] || []).push(fn); },
    dispatch(ev, payload) {
      (this.listeners[ev] || []).forEach((f) => f(Object.assign({ preventDefault() {}, key: '' }, payload || {})));
    },
    appendChild(c) { return c; },
    focus() {},
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; },
    querySelectorAll(sel) {
      if (!this._cache) this._cache = Object.create(null);
      if (this._cache[sel]) return this._cache[sel];
      let re;
      if (sel.startsWith('[') && sel.endsWith(']')) {
        re = new RegExp('<([a-zA-Z]+)((?:[^>"]|"[^"]*")*\\s' + sel.slice(1, -1) + '="[^"]*"(?:[^>"]|"[^"]*")*)>', 'g');
      } else if (sel.startsWith('#')) {
        re = new RegExp('<([a-zA-Z]+)((?:[^>"]|"[^"]*")*\\sid="' + sel.slice(1) + '"(?:[^>"]|"[^"]*")*)>', 'g');
      } else if (sel.startsWith('.')) {
        re = new RegExp('<([a-zA-Z]+)((?:[^>"]|"[^"]*")*\\sclass="[^"]*\\b' + sel.slice(1) + '\\b[^"]*"(?:[^>"]|"[^"]*")*)>', 'g');
      } else {
        re = new RegExp('<(' + sel + ')((?:[^>"]|"[^"]*")*)>', 'g');
      }
      const out = [];
      let m;
      while ((m = re.exec(this._html)) !== null) out.push(makeEl(m[1], parseAttrs(m[2])));
      this._cache[sel] = out;
      return out;
    }
  };
}

// ── fixtures: three deliberately different students ───────────────────────

function pad(n) { return (n < 10 ? '0' : '') + n; }

const STUDENTS = Array.from({ length: 12 }, (_, i) => ({
  id: 500 + i + 1,
  member_code: 'M-' + pad(i + 1),
  student_name: 'Student ' + pad(i + 1),
  father_name: 'Father ' + pad(i + 1),
  gender: i % 2 ? 'female' : 'male',
  status: 'active',
  class_id: 1,
  class_name: 'Grade 4'
}));

function baseDetail(memberId, over) {
  return Object.assign({
    status: 'success',
    scope: { type: 'student', member_id: memberId, class_id: 1 },
    context: {
      year_id: 7, year_name: '2017 E.C.', term_id: 0, term_name: '',
      is_annual: true, semester_weights: { s1: 40, s2: 60 }
    },
    student: {
      id: memberId, student_name: 'Student ' + pad(memberId - 500),
      father_name: 'Father ' + pad(memberId - 500), christian_name: 'Gebre',
      member_code: 'M-' + pad(memberId - 500), gender: 'male', status: 'active'
    },
    class: { id: 1, class_name: '4\u129b \u12ad\u134d\u120d', class_name_en: 'Grade 4' },
    overview: {
      subjects_total: 2, subjects_with_result: 2, subjects_pending: 0,
      overall_average: 78, overall_grade: 'C', rank: 2, rank_tied: false,
      total_in_class: 4, assessments_count: 3,
      strongest_subject: 'Geez', weakest_subject: 'Music'
    },
    subjects: [
      {
        subject_id: 1, subject_name: 'Geez', subject_name_en: 'Geez',
        duration_type: 'FULL_YEAR', offering_term: 0,
        semester_1_score: 80, semester_2_score: 90,
        semester_weights: { s1: 40, s2: 60 },
        final_percentage: 86, grade_letter: 'B',
        subject_status: 'CLOSED', status_reason: 'full-year subject combined',
        has_result: true, recorded_percent: 100, planned_percent: 100,
        assessments_count: 2
      },
      {
        subject_id: 2, subject_name: 'Music', subject_name_en: 'Music',
        duration_type: 'SEMESTER_ONLY', offering_term: 1,
        semester_1_score: 70, semester_2_score: null,
        semester_weights: { s1: 40, s2: 60 },
        final_percentage: 70, grade_letter: 'C',
        subject_status: 'CLOSED', status_reason: 'semester-only subject',
        has_result: true, recorded_percent: 100, planned_percent: 100,
        assessments_count: 1
      }
    ],
    attendance: {
      has_attendance: true, total: 10, present: 8, absent: 1, late: 1,
      excused: 0, rate: 90
    },
    data_state: { subjects: 'ok', results: 'ok', attendance: 'ok' },
    pass_mark: 50,
    grade_scale: { A: 90, B: 80, C: 70, D: 60 }
  }, over || {});
}

/** A student with subjects but not one final result, and no register taken. */
function sparseDetail(memberId) {
  const d = baseDetail(memberId);
  d.overview = Object.assign({}, d.overview, {
    subjects_with_result: 0, subjects_pending: 2,
    overall_average: null, overall_grade: null, rank: null,
    strongest_subject: null, weakest_subject: null
  });
  d.subjects = d.subjects.map((s) => Object.assign({}, s, {
    semester_1_score: null, semester_2_score: null,
    final_percentage: null, grade_letter: null,
    subject_status: 'PENDING', has_result: false
  }));
  d.attendance = {
    has_attendance: false, total: null, present: null, absent: null,
    late: null, excused: null, rate: null
  };
  d.data_state = { subjects: 'ok', results: 'no_results', attendance: 'no_attendance' };
  return d;
}

const ASSESSMENTS = {
  status: 'success',
  scope: { type: 'student', member_id: 501, class_id: 1 },
  context: { year_id: 7, year_name: '2017 E.C.', term_id: 0, term_name: '' },
  rows: [
    {
      subject_id: 1, subject_name: 'Geez', assessment_id: 11,
      assessment_name: 'Midterm', weight: 40, has_result: true,
      score: 80, max_score: 100, percentage: 80, remarks: '',
      workflow_status: 'approved', workflow_label: 'Approved'
    },
    {
      // Approved mark list, but no score for THIS student.
      subject_id: 1, subject_name: 'Geez', assessment_id: 12,
      assessment_name: 'Final', weight: 60, has_result: false,
      score: null, max_score: null, percentage: null, remarks: '',
      workflow_status: 'approved', workflow_label: 'Approved'
    },
    {
      // Never started: no packet and no marks.
      subject_id: 2, subject_name: 'Music', assessment_id: 21,
      assessment_name: 'Practical', weight: 100, has_result: false,
      score: null, max_score: null, percentage: null, remarks: '',
      workflow_status: null, workflow_label: 'Not started'
    }
  ],
  unplanned_weight: [],
  data_state: 'ok'
};

const REPORT_CARD = {
  status: 'success',
  student: { id: 501, student_name: 'Student 01', father_name: 'Father 01' },
  class: { id: 1, class_name: 'Grade 4' },
  year: { id: 7, year_name: '2017 E.C.' },
  term: { id: 0 },
  subjects: [
    { id: 1, subject_name: 'Geez', semester_1_score: 80, semester_2_score: 90, final_percentage: 86, grade_letter: 'B' },
    { id: 2, subject_name: 'Music', semester_1_score: 70, semester_2_score: null, final_percentage: 70, grade_letter: 'C' },
    { id: 3, subject_name: 'Maths', semester_1_score: null, semester_2_score: null, final_percentage: null, grade_letter: null }
  ],
  totals: { average: 78, grade_letter: 'C', is_annual: true, semester_weights: { s1: 40, s2: 60 } },
  rank: 2, rank_tied: false, total_in_class: 4,
  pass_mark: 50,
  grade_scale: { A: 90, B: 80, C: 70, D: 60 }
};

// ── fake server ───────────────────────────────────────────────────────────

function makeFetch(log, control) {
  control = control || {};
  control._seen = {};
  return function (url) {
    const u = String(url);
    const qs = {};
    u.replace(/[?&]([^=&]+)=([^&]*)/g, (_, k, v) => { qs[decodeURIComponent(k)] = decodeURIComponent(v); return ''; });
    const action = qs.action;
    log.push({ url: u, action, qs });

    const reply = (body) => {
      if (control.hang && control.hang === action) return new Promise(() => {});
      // perCall lets one action answer out of order: the nth call to that
      // action waits perCall[action][n] ms. A stale-response guard can only
      // be exercised if an earlier request is allowed to land LAST.
      let d = (control.delay && control.delay[action]) || 0;
      if (control.perCall && control.perCall[action]) {
        const seq = control.perCall[action];
        const n = (control._seen[action] = (control._seen[action] || 0));
        control._seen[action] = n + 1;
        d = seq[Math.min(n, seq.length - 1)];
      }
      return new Promise((res) => {
        const give = () => res({ json: () => Promise.resolve(body) });
        if (d) setTimeout(give, d); else give();
      });
    };

    if (control.fail && control.fail === action) {
      return reply({ status: 'error', message: 'Database is unavailable.' });
    }
    if (control.boom && control.boom === action) {
      return Promise.reject(new Error('network down'));
    }

    if (action === 'roster') {
      const per = Math.min(100, Math.max(10, parseInt(qs.per_page, 10) || 25));
      const page = Math.max(1, parseInt(qs.page, 10) || 1);
      return reply({
        status: 'success', rows: STUDENTS.slice((page - 1) * per, (page - 1) * per + per),
        total: STUDENTS.length, page, per_page: per,
        pages: Math.ceil(STUDENTS.length / per), year_id: 7, year_name: '2017 E.C.'
      });
    }
    if (action === 'list_teachers') {
      const rows = Array.from({ length: 6 }, (_, i) => ({
        id: 700 + i + 1, full_name: 'Teacher ' + pad(i + 1),
        username: 'teacher' + pad(i + 1), member_id: null, member_code: '',
        assigned_classes: i % 3, is_active: 1
      }));
      return reply({
        status: 'success', teachers: rows, total: rows.length, page: 1,
        per_page: 25, pages: 1, year_id: 7, year_name: '2017 E.C.'
      });
    }
    if (action === 'get_classes') {
      return reply({ status: 'success', classes: [{ id: 1, class_name: 'Grade 4' }], total: 1, page: 1, per_page: 25, pages: 1 });
    }
    if (action === 'tracking_student_detail') {
      const mid = parseInt(qs.member_id, 10);
      if (control.sparse) return reply(sparseDetail(mid));
      return reply(baseDetail(mid));
    }
    if (action === 'tracking_student_assessments') {
      if (control.emptyAssessments) {
        return reply({ status: 'success', scope: {}, context: {}, rows: [], unplanned_weight: [], data_state: 'no_assessments' });
      }
      if (control.unlabelledAssessment) {
        // assessmentRow() seeds workflow_label as '' and the enrichment
        // loop overwrites it. If that loop ever skips a row the field
        // arrives empty, and a blank status chip tells the user nothing.
        return reply({
          status: 'success',
          scope: { type: 'student', member_id: 501, class_id: 11 },
          context: { year_id: 7, year_name: '2017 E.C.', term_id: 0, term_name: 'Annual', is_annual: true },
          rows: [{
            subject_id: 21, subject_name: 'Music', assessment_id: 9,
            assessment_name: 'Unlabelled Task', weight: 25, has_result: false,
            score: null, max_score: 100, percentage: null, remarks: '',
            workflow_status: null, workflow_label: ''
          }],
          unplanned_weight: [], data_state: 'ok'
        });
      }
      return reply(ASSESSMENTS);
    }
    if (action === 'get_report_card') {
      return reply(REPORT_CARD);
    }
    return reply({ status: 'error', message: 'unknown action ' + action });
  };
}

// ── controller under test ─────────────────────────────────────────────────

const SRC = fs.readFileSync(path.join(__dirname, '..', '..', 'admin', 'js', 'academic_tracking.js'), 'utf8');

function build(control) {
  const log = [];
  const root = makeEl('div', { id: 'sec-academic-tracking' });
  const head = makeEl('head', {});
  const sandbox = {
    console,
    setTimeout,
    clearTimeout,
    encodeURIComponent,
    Promise,
    Object,
    Array,
    Math,
    JSON,
    String,
    Number,
    document: {
      getElementById: (id) => (id === 'sec-academic-tracking' ? root : null),
      createElement: (t) => makeEl(t, {}),
      head
    },
    fetch: makeFetch(log, control)
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(SRC, sandbox);
  const ctrl = new sandbox.AcademicTracking({ containerId: 'sec-academic-tracking' });
  return { ctrl, root, log, sandbox };
}

const tick = () => new Promise((r) => setTimeout(r, 0));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Open the students list and click the row for `index`. */
async function selectStudent(ctrl, root, index) {
  await ctrl.boot();
  await ctrl.openList('students');
  await tick();
  const row = pick(root, '[data-select-id]', index, 'setup: a student row exists at ' + index);
  row.dispatch('click');
  await tick();
  await tick();
  return row;
}

async function main() {
  // ========== 1. selection carries the clicked student into the request
  {
    const { ctrl, root, log } = build();
    await selectStudent(ctrl, root, 2);

    eq('selection: the third row was selected, not the first', 503, ctrl.scope.id);
    eq('selection: the scope type is the student', 'students', ctrl.scope.type);

    const det = log.filter((r) => r.action === 'tracking_student_detail');
    eq('request: exactly one detail request was made', 1, det.length);
    eq('request: it carries the clicked member id', '503', det[0].qs.member_id);
    ok('request: it carries the academic year as context', det[0].qs.year_id === '7');

    // Scope must never become a filter.
    eq('state: the students filter bag stays empty', 0, Object.keys(ctrl.lists.students.filters).length);
    eq('state: no filter is reported active', 0, ctrl.activeFilters('students').length);
    const html = root.innerHTML;
    hasNot('state: the screen does not offer to clear filters', html, 'Clear filters');
  }

  // ========== 2. the three cheap sections share one request
  {
    const { ctrl, root, log } = build();
    await selectStudent(ctrl, root, 0);

    eq('lazy: overview is the section that opens first', 'overview', ctrl.section);
    await ctrl.openSection('subjects');
    await tick();
    await ctrl.openSection('attendance');
    await tick();
    await ctrl.openSection('overview');
    await tick();

    eq('lazy: overview, subjects and attendance cost one request between them',
      1, log.filter((r) => r.action === 'tracking_student_detail').length);
    eq('lazy: assessments were never fetched unprompted',
      0, log.filter((r) => r.action === 'tracking_student_assessments').length);
    eq('lazy: the report card was never fetched unprompted',
      0, log.filter((r) => r.action === 'get_report_card').length);
  }

  // ========== 3. the expensive sections load on demand, exactly once
  {
    const { ctrl, root, log } = build();
    await selectStudent(ctrl, root, 0);

    await ctrl.openSection('assessments');
    await tick();
    eq('lazy: opening assessments fetches them',
      1, log.filter((r) => r.action === 'tracking_student_assessments').length);

    await ctrl.openSection('overview');
    await tick();
    await ctrl.openSection('assessments');
    await tick();
    eq('lazy: re-opening assessments does not refetch',
      1, log.filter((r) => r.action === 'tracking_student_assessments').length);

    await ctrl.openSection('report_card');
    await tick();
    eq('lazy: opening the report card fetches it',
      1, log.filter((r) => r.action === 'get_report_card').length);
    ok('lazy: the report card comes from the existing communication endpoint',
      log.some((r) => r.action === 'get_report_card' && /api_communication\.php/.test(r.url)));
  }

  // ========== 4. subjects honour the duration policy and invent nothing
  {
    const { ctrl, root } = build();
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('subjects');
    await tick();
    const html = root.innerHTML;

    has('subjects: the full-year subject is labelled full year', html, 'Full year');
    has('subjects: the semester-only subject is labelled semester', html, '>Semester<');
    has('subjects: the full-year final is the engine value', html, '86');
    has('subjects: the semester-only final is the engine value', html, '70');
    has('subjects: the configured weights are stated', html, '40% / 60%');
    has('subjects: a missing semester 2 renders as a dash', html, '\u2014');
    hasNot('subjects: a missing semester 2 is never rendered as zero', html, '>0<');
    has('subjects: the dash is explained', html, 'never means zero');
  }

  // ========== 5. no result anywhere → "Not available", never zero
  {
    const { ctrl, root } = build({ sparse: true });
    await selectStudent(ctrl, root, 0);
    const html = root.innerHTML;

    has('sparse: the overall average says it is unavailable', html, 'Not available');
    hasNot('sparse: the overall average is never shown as 0%', html, '>0%<');
    has('sparse: the reason is given', html, 'No subject has a final result yet');
    has('sparse: ranking explains itself too', html, 'Ranking needs a final result');
    has('sparse: the overview explains the situation', html, 'no final result exists yet');
  }

  // ========== 6. no attendance is not 0% attendance
  {
    const { ctrl, root } = build({ sparse: true });
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('attendance');
    await tick();
    const html = root.innerHTML;

    has('attendance: the empty state is explicit', html, 'No attendance has been recorded');
    has('attendance: it distinguishes itself from a zero rate', html, 'not the same as an attendance rate of 0%');
    hasNot('attendance: no rate figure is printed', html, 'attendance rate across');
    hasNot('attendance: no present-day tile is printed', html, '>Present<');
  }

  // ========== 7. a real attendance record renders the real numbers
  {
    const { ctrl, root } = build();
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('attendance');
    await tick();
    const html = root.innerHTML;

    has('attendance: the rate is the engine value', html, '90%');
    has('attendance: present days are shown', html, '>8<');
    has('attendance: the breakdown bar has a text alternative', html, 'aria-label="Present 8, absent 1, late 1, excused 0"');
    has('attendance: the rate is attributed to the engine', html, 'not recalculated here');
  }

  // ========== 8. workflow status and result are separate facts
  {
    const { ctrl, root } = build();
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('assessments');
    await tick();
    const html = root.innerHTML;

    has('assessments: an approved mark list says Approved', html, '>Approved<');
    has('assessments: an unstarted mark list says Not started', html, '>Not started<');
    has('assessments: a recorded score is shown', html, '>80<');
    has('assessments: the status column is named for what it is', html, 'Mark list status');
    has('assessments: the difference is explained', html, 'approved mark list can still have no score');
    // The Geez Final row is approved but unscored: status present, result absent.
    ok('assessments: an approved row with no score shows no score',
      html.indexOf('Final') !== -1 && html.indexOf('>0<') === -1);
  }

  // ========== 9. no assessments at all is its own state
  {
    const { ctrl, root } = build({ emptyAssessments: true });
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('assessments');
    await tick();
    const html = root.innerHTML;

    has('assessments: the empty state is explicit', html, 'No assessments have been created yet');
    has('assessments: it is distinguished from missing marks', html, 'different from marks being missing');
    hasNot('assessments: it does not blame filters', html, 'Clear filters');
  }

  // ========== 10. an API error is never an empty screen
  {
    const { ctrl, root } = build({ fail: 'tracking_student_detail' });
    await selectStudent(ctrl, root, 0);
    const html = root.innerHTML;

    has('error: the failure is reported as a failure', html, 'couldn\u2019t load this student');
    has('error: the server sentence is surfaced', html, 'Database is unavailable.');
    has('error: it is distinguished from absent data', html, 'not a sign that the student has none');
    has('error: a retry is offered', html, 'data-retry-detail');
    hasNot('error: it is not reported as an empty record', html, 'No subjects are offered');
  }

  // ========== 11. a transport failure behaves the same way
  {
    const { ctrl, root } = build({ boom: 'tracking_student_assessments' });
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('assessments');
    await tick();
    const html = root.innerHTML;

    has('error: a rejected fetch is reported', html, 'couldn\u2019t load the assessments');
    has('error: it says it is a loading failure', html, 'not an empty assessment list');
    has('error: the section offers its own retry', html, 'data-retry-section="assessments"');
    hasNot('error: it does not claim there are no assessments', html, 'No assessments have been created yet');
  }

  // ========== 12. a retry after an error actually refetches
  {
    const { ctrl, root, log } = build({ fail: 'tracking_student_detail' });
    await selectStudent(ctrl, root, 0);
    eq('retry: one failed attempt so far', 1, log.filter((r) => r.action === 'tracking_student_detail').length);
    pick(root, '[data-retry-detail]', 0, 'retry: the button exists').dispatch('click');
    await tick();
    eq('retry: the button issues a new request', 2, log.filter((r) => r.action === 'tracking_student_detail').length);
  }

  // ========== 13. switching student discards the previous student entirely
  {
    const { ctrl, root, log } = build();
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('assessments');
    await tick();
    ok('switch: the first student has assessments loaded', ctrl.lazy.assessments.status === 'ready');

    // Back to the list, then pick a different student.
    pick(root, '[data-go]', 1, 'switch: a back control exists').dispatch('click');
    await tick();
    const row = pick(root, '[data-select-id]', 4, 'switch: a different row exists');
    row.dispatch('click');
    await tick();

    eq('switch: the new student is the selected one', 505, ctrl.scope.id);
    eq('switch: the section resets to overview', 'overview', ctrl.section);
    eq('switch: the previous assessments were discarded', 'idle', ctrl.lazy.assessments.status);
    eq('switch: the previous report card was discarded', 'idle', ctrl.lazy.report_card.status);

    const det = log.filter((r) => r.action === 'tracking_student_detail');
    eq('switch: the newest detail request is for the new student', '505', det[det.length - 1].qs.member_id);
  }

  // ========== 14. a slow response for an abandoned student cannot land
  {
    // The ABANDONED student's response is the SLOW one, so it tries to
    // land after the survivor's. Without the sequence guard the stale
    // payload wins and the user sees a student they did not click.
    const { ctrl, root, log } = build({ perCall: { tracking_student_detail: [80, 5] } });
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();

    // Capture both rows first: the first click re-renders the container,
    // so looking the second row up afterwards would find nothing.
    const raceRows = root.querySelectorAll('[data-select-id]');
    ok('race: two rows are available to click', raceRows.length > 3);
    raceRows[0].dispatch('click');
    raceRows[3].dispatch('click');
    await sleep(140);

    const raceCalls = log.filter((r) => r.action === 'tracking_student_detail');
    eq('race: both clicks really did fire a request', 2, raceCalls.length);

    eq('race: the surviving scope is the last clicked', 504, ctrl.scope.id);
    eq('race: the rendered detail belongs to that student', 504, ctrl.detail.data.scope.member_id);
    has('race: the header names the surviving student', root.innerHTML, 'Student 04');
    hasNot('race: the abandoned student is gone', root.innerHTML, 'Student 01 Father 01');
  }

  // ========== 14b. a row that arrives without a workflow label
  {
    const { ctrl, root } = build({ unlabelledAssessment: true });
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('assessments');
    await tick();

    has('blank label: the row still appears', root.innerHTML, 'Unlabelled Task');
    has('blank label: it falls back to an honest status', root.innerHTML, 'Not started');
    hasNot('blank label: no empty status chip is rendered',
      root.innerHTML, '<span class="ch "></span>');
  }

  // ========== 15. clearing the selection drops every section
  {
    const { ctrl, root } = build();
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('report_card');
    await tick();
    ok('clear: the report card is loaded first', ctrl.lazy.report_card.status === 'ready');

    pick(root, '[data-clear-selection]', 0, 'clear: the control exists').dispatch('click');
    await tick();

    eq('clear: no scope remains', null, ctrl.scope);
    eq('clear: the report card was discarded', 'idle', ctrl.lazy.report_card.status);
    eq('clear: the detail was discarded', 'idle', ctrl.detail.status);
    has('clear: the list is shown again', root.innerHTML, 'data-select-id');
  }

  // ========== 16. the report card reuses the engine's own numbers
  {
    const { ctrl, root } = build();
    await selectStudent(ctrl, root, 0);
    await ctrl.openSection('report_card');
    await tick();
    const html = root.innerHTML;

    has('report card: the overall average is the engine value', html, '78%');
    has('report card: the rank is shown', html, '#2');
    has('report card: the class size is shown', html, 'of 4');
    has('report card: a passing subject is marked pass', html, '>Pass<');
    has('report card: an unfinished subject is not final', html, '>Not final<');
    has('report card: authority is attributed', html, 'does not recalculate them');
    hasNot('report card: an absent final is never zero', html, '>0<');
  }

  // ========== 17. the section nav is a real tablist
  {
    const { ctrl, root } = build();
    await selectStudent(ctrl, root, 0);
    const html = root.innerHTML;

    has('a11y: the nav is a tablist', html, 'role="tablist"');
    has('a11y: the open tab is marked selected', html, 'aria-selected="true"');
    has('a11y: the panel is a tabpanel', html, 'role="tabpanel"');
    has('a11y: the panel announces updates', html, 'aria-live="polite"');

    const tabs = root.querySelectorAll('[data-section]');
    eq('a11y: all five sections are offered', 5, tabs.length);

    // Arrow-key navigation moves between sections.
    tabs[0].dispatch('keydown', { key: 'ArrowRight' });
    await tick();
    eq('a11y: ArrowRight opens the next section', 'subjects', ctrl.section);
    root.querySelectorAll('[data-section]')[1].dispatch('keydown', { key: 'ArrowLeft' });
    await tick();
    eq('a11y: ArrowLeft goes back', 'overview', ctrl.section);
    root.querySelectorAll('[data-section]')[0].dispatch('keydown', { key: 'End' });
    await tick();
    eq('a11y: End jumps to the last section', 'report_card', ctrl.section);
  }

  // ========== 18. a teacher is a different workflow, not the student one
  //
  // Teachers gained their own screen in Phase 3. What this still pins is
  // the separation: selecting a teacher must never reach into the student
  // workflow or render the student workspace under a teacher's name.
  {
    const { ctrl, root, log } = build();
    await ctrl.boot();
    await ctrl.openList('teachers');
    await tick();
    pick(root, '[data-select-id]', 0, 'phase: a teacher row exists').dispatch('click');
    await tick();

    const html = root.innerHTML;
    hasNot('phase: no student workspace is rendered for a teacher', html, 'role="tablist"');
    eq('phase: no student detail was requested for a teacher',
      0, log.filter((r) => r.action === 'tracking_student_detail').length);
    eq('phase: no student assessments were requested for a teacher',
      0, log.filter((r) => r.action === 'tracking_student_assessments').length);
    eq('phase: no report card was requested for a teacher',
      0, log.filter((r) => r.action === 'get_report_card').length);
  }

  // ========== 19. the context is never mistaken for a filter
  {
    const { ctrl, root } = build();
    await selectStudent(ctrl, root, 0);
    const html = root.innerHTML;

    has('context: the academic year is shown as context', html, '2017 E.C.');
    has('context: it is labelled as context, not a filter', html, 'Context, not a filter');
    has('context: the annual mode is stated', html, 'Annual</span>');
    eq('context: selecting a student added no filter', 0, ctrl.activeFilters('students').length);
  }

  // ── report ───────────────────────────────────────────────────────────────
  const total = passed + failures.length;
  if (failures.length) {
    console.log('\n  FAILURES');
    failures.forEach((f) => console.log('    FAIL  ' + f));
    console.log('\n  FAIL  ' + total + ' checks, ' + failures.length + ' failed\n');
    process.exit(1);
  }
  console.log('\n  PASS  ' + total + ' checks, 0 failed\n');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
