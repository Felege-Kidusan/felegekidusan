/**
 * Behavioural harness for the Phase 3 Teacher Tracking workflow in
 * admin/js/academic_tracking.js.
 *
 * It boots the real controller against a stubbed network and a minimal DOM,
 * selects a teacher the way a person would, opens one of that teacher's
 * offerings, and drives the resulting assessment workflow. Every assertion
 * is about behaviour, not about source text.
 *
 * The rules it exists to defend:
 *
 *   1. Nothing is selected for the user. Not the teacher, not the class,
 *      not the subject, not the assessment — including when the teacher
 *      has exactly one assignment, which is the case rows[0] would hide.
 *   2. The offering is a secondary SCOPE, not a filter. Opening one must
 *      never appear in the applied-filters count.
 *   3. Relationships are shown as the server returned them. A teacher with
 *      no assignments is a distinct state, and so is an offering with no
 *      assessments, and neither is an error or a zero.
 *   4. A homeroom assignment has no subject. It is listed, it is not
 *      openable, and its assessment count is a dash — never 0.
 *   5. Assessment workflow status is shown as workflow state, never as an
 *      academic result, and no academic number appears on this screen.
 *   6. The action is the EXISTING review modal. The screen never approves,
 *      rejects or requests revision itself.
 *   7. A failed request says the load failed. It never degrades into an
 *      empty-data message, and a refusal reads as a refusal.
 *   8. A stale response can never overwrite a newer one — proven by
 *      resolving the requests out of order, not by reading the source.
 *
 * Zero npm dependencies.
 *
 * Usage: node tests/e2e/teacher_tracking.js
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


// ── fixtures ──────────────────────────────────────────────────────────────

function pad(n) { return (n < 10 ? '0' : '') + n; }

/** Six teachers. Index 0 is the rich one, and they are NOT all the same. */
const TEACHERS = Array.from({ length: 6 }, (_, i) => ({
  id: 700 + i + 1,
  full_name: 'Teacher ' + pad(i + 1),
  username: 'teacher' + pad(i + 1),
  email: 'teacher' + pad(i + 1) + '@example.org',
  member_id: i === 2 ? null : 900 + i,
  member_code: i === 2 ? '' : 'T-' + pad(i + 1),
  assigned_classes: i % 3,
  is_active: 1
}));

const ASSIGNMENTS = {
  // 701: two offerings plus a homeroom row with no subject at all.
  701: [
    {
      class_id: 1, class_name: 'Grade 4', subject_id: 11, subject_name: 'Geez',
      assignment_role: 'primary', is_class_teacher: false, is_homeroom: false,
      assessment_count: 2
    },
    {
      class_id: 2, class_name: 'Grade 5', subject_id: 11, subject_name: 'Geez',
      assignment_role: 'primary', is_class_teacher: false, is_homeroom: false,
      assessment_count: 0
    },
    {
      class_id: 1, class_name: 'Grade 4', subject_id: null, subject_name: null,
      assignment_role: 'homeroom', is_class_teacher: true, is_homeroom: true,
      assessment_count: null
    }
  ],
  // 702: EXACTLY ONE offering. This is the auto-selection trap.
  702: [
    {
      class_id: 3, class_name: 'Grade 6', subject_id: 12, subject_name: 'Music',
      assignment_role: 'primary', is_class_teacher: false, is_homeroom: false,
      assessment_count: 1
    }
  ],
  // 703: on file, teaches nothing this year.
  703: []
};

function detailFor(teacherId) {
  const t = TEACHERS.find((x) => x.id === teacherId) || TEACHERS[0];
  const rows = ASSIGNMENTS[teacherId] || [];
  return {
    status: 'success',
    scope: { type: 'teacher', teacher_id: teacherId },
    context: { year_id: 7, term_id: 0 },
    teacher: {
      id: t.id, full_name: t.full_name, username: t.username, email: t.email,
      is_active: true, member_code: t.member_code || null, phone: null
    },
    assignments: rows,
    data_state: { assignments: rows.length ? 'ok' : 'no_assignments' }
  };
}

/** Four assessments covering every workflow state the screen must show. */
const OFFERING_ROWS = [
  {
    assessment_id: 41, assessment_name: 'Geez Midterm', assessment_type: 'test',
    max_score: 100, weight: 40,
    term_id: 1, workflow_status: 'approved', workflow_label: 'Approved',
    submission_id: 9001
  },
  {
    assessment_id: 42, assessment_name: 'Geez Final', assessment_type: 'test',
    max_score: 100, weight: 60,
    term_id: 2, workflow_status: 'revision_needed', workflow_label: 'Needs revision',
    submission_id: 9002
  },
  {
    // Status resolved from loose marks: real status, but NO packet exists.
    assessment_id: 43, assessment_name: 'Oral Recitation', assessment_type: 'oral',
    max_score: 50, weight: null,
    term_id: 1, workflow_status: 'submitted', workflow_label: 'Complete',
    submission_id: null
  },
  {
    // Never started: no packet and no marks. A real answer, not a gap.
    assessment_id: 44, assessment_name: 'Class Project', assessment_type: 'project',
    max_score: 100, weight: null,
    term_id: 2, workflow_status: null, workflow_label: 'Not started',
    submission_id: null
  }
];

function offeringFor(classId, subjectId, rows) {
  return {
    status: 'success',
    scope: { type: 'teacher', teacher_id: 701, class_id: classId, subject_id: subjectId },
    context: { year_id: 7, term_id: 0 },
    class: { id: classId, class_name: classId === 1 ? 'Grade 4' : 'Grade 5' },
    subject: { id: subjectId, subject_name: 'Geez' },
    assessments: rows,
    data_state: { assessments: rows.length ? 'ok' : 'no_assessments' }
  };
}

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

    if (action === 'list_teachers') {
      const per = Math.min(100, Math.max(10, parseInt(qs.per_page, 10) || 25));
      const page = Math.max(1, parseInt(qs.page, 10) || 1);
      return reply({
        status: 'success', teachers: TEACHERS.slice((page - 1) * per, (page - 1) * per + per),
        total: TEACHERS.length, page, per_page: per,
        pages: Math.ceil(TEACHERS.length / per), year_id: 7, year_name: '2017 E.C.'
      });
    }
    if (action === 'roster') {
      return reply({ status: 'success', rows: [], total: 0, page: 1, per_page: 25, pages: 1, year_id: 7, year_name: '2017 E.C.' });
    }
    if (action === 'get_classes' || action === 'get_subjects') {
      return reply({ status: 'success', classes: [], subjects: [], total: 0, page: 1, per_page: 25, pages: 1 });
    }
    if (action === 'tracking_teacher_detail') {
      return reply(detailFor(parseInt(qs.teacher_id, 10)));
    }
    if (action === 'tracking_teacher_assessments') {
      const cid = parseInt(qs.class_id, 10);
      const sid = parseInt(qs.subject_id, 10);
      if (control.notAssigned) {
        return reply({
          status: 'error', code: 'not_assigned',
          message: 'That teacher is not assigned to this class and subject.'
        });
      }
      if (control.emptyAssessments) return reply(offeringFor(cid, sid, []));
      if (control.unlabelled) {
        return reply(offeringFor(cid, sid, [Object.assign({}, OFFERING_ROWS[3], { workflow_label: '' })]));
      }
      // Class 2 is the offering the fixture gives no assessments.
      if (cid === 2) return reply(offeringFor(cid, sid, []));
      return reply(offeringFor(cid, sid, OFFERING_ROWS));
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
  const reviewCalls = [];
  const navCalls = [];
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
  // The dashboard exports these two. The tracking screen is supposed to
  // call them rather than own a review workflow of its own.
  if (!(control && control.noReviewModal)) {
    sandbox.openReviewModal = (id) => { reviewCalls.push(id); };
  }
  sandbox.nav = (section) => { navCalls.push(section); };
  vm.createContext(sandbox);
  vm.runInContext(SRC, sandbox);
  const ctrl = new sandbox.AcademicTracking({ containerId: 'sec-academic-tracking' });
  return { ctrl, root, log, sandbox, reviewCalls, navCalls };
}

const tick = () => new Promise((r) => setTimeout(r, 0));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Open the teachers list and click the row for `index`. */
async function selectTeacher(ctrl, root, index) {
  await ctrl.boot();
  await ctrl.openList('teachers');
  await tick();
  const row = pick(root, '[data-select-id]', index, 'setup: a teacher row exists at ' + index);
  row.dispatch('click');
  await tick();
  await tick();
  return row;
}

/** Click the "Open" button of the offering at `index`. */
async function openOffering(root, index, label) {
  const btn = pick(root, '[data-offering-class]', index, label || ('setup: an offering button exists at ' + index));
  btn.dispatch('click');
  await tick();
  await tick();
  return btn;
}

async function main() {
  // ========== 1. nothing is selected until it is clicked
  {
    const { ctrl, root, log } = build();
    await ctrl.boot();
    await ctrl.openList('teachers');
    await tick();

    eq('auto: opening the teachers list selects no teacher', null, ctrl.scope);
    eq('auto: and asks for no teacher detail',
      0, log.filter((r) => r.action === 'tracking_teacher_detail').length);
    eq('auto: and asks for no assessments',
      0, log.filter((r) => r.action === 'tracking_teacher_assessments').length);

    // The root list must stay cheap: listing teachers must not drag in
    // report cards, assessments or attendance for any of them.
    eq('auto: the root list loads no report card',
      0, log.filter((r) => r.action === 'get_report_card').length);
    eq('auto: the root list loads no student detail',
      0, log.filter((r) => r.action === 'tracking_student_detail').length);
  }

  // ========== 2. the clicked teacher is the one requested
  {
    const { ctrl, root, log } = build();
    await selectTeacher(ctrl, root, 3);

    eq('select: the fourth row became the scope', 704, ctrl.scope.id);
    const det = log.filter((r) => r.action === 'tracking_teacher_detail');
    eq('select: exactly one detail request', 1, det.length);
    eq('select: it carried the clicked teacher', '704', det[0].qs.teacher_id);
    eq('select: scope type is teachers', 'teachers', ctrl.scope.type);
  }

  // ========== 3. selecting a teacher does NOT open one of their offerings
  {
    const { ctrl, root, log } = build();
    await selectTeacher(ctrl, root, 0);

    eq('auto: no offering is selected on arrival', null, ctrl.offering);
    eq('auto: and no assessment request was made',
      0, log.filter((r) => r.action === 'tracking_teacher_assessments').length);

    const html = root.innerHTML;
    has('auto: the assignments list is shown instead', html, 'Classes and subjects');
    has('auto: the first offering is offered, not opened', html, 'data-offering-class="1"');
  }

  // ========== 4. THE rows[0] TRAP: one assignment is still not auto-opened
  {
    const { ctrl, root, log } = build();
    await selectTeacher(ctrl, root, 1);        // teacher 702, exactly one offering

    eq('single: the teacher really does have one offering',
      1, root.querySelectorAll('[data-offering-class]').length);
    eq('single: a sole offering is still not selected', null, ctrl.offering);
    eq('single: and still triggers no assessment request',
      0, log.filter((r) => r.action === 'tracking_teacher_assessments').length);

    // ...and it opens normally once actually clicked.
    await openOffering(root, 0, 'single: the sole offering can be opened');
    eq('single: now it is the scope', 3, ctrl.offering.class_id);
    eq('single: and exactly one request went out',
      1, log.filter((r) => r.action === 'tracking_teacher_assessments').length);
  }

  // ========== 5. the offering is a scope, never a filter
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    eq('scope: selecting a teacher applied no filter', 0, ctrl.activeFilters('teachers').length);

    await openOffering(root, 0);
    eq('scope: opening an offering applied no filter either',
      0, ctrl.activeFilters('teachers').length);
    eq('scope: the offering is not in the filters bag',
      undefined, ctrl.lists.teachers.filters.class_id);
    eq('scope: nor is the subject',
      undefined, ctrl.lists.teachers.filters.subject_id);
    ok('scope: it lives in its own field', ctrl.offering && ctrl.offering.subject_id === 11);

    const html = root.innerHTML;
    has('scope: the selected scope is visible', html, 'Viewing');
    has('scope: the class is named', html, 'Grade 4');
    has('scope: it is cleared by changing scope, not by clearing filters',
      html, 'Change class or subject');
    hasNot('scope: the screen never tells the user to remove filters',
      html, 'Clear filters');
  }

  // ========== 6. the request carries the clicked class AND subject
  {
    const { ctrl, root, log } = build();
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 1);               // Grade 5 / Geez

    const req = log.filter((r) => r.action === 'tracking_teacher_assessments');
    eq('ids: one request', 1, req.length);
    eq('ids: the teacher id came from the selection', '701', req[0].qs.teacher_id);
    eq('ids: the class id came from the clicked row', '2', req[0].qs.class_id);
    eq('ids: the subject id came from the clicked row', '11', req[0].qs.subject_id);
  }

  // ========== 7. a teacher with no assignments is its own state
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 2);        // teacher 703, none

    const html = root.innerHTML;
    has('empty: the no-assignments state is stated', html, 'No classes or subjects assigned');
    hasNot('empty: it is not an error', html, 'Could not load this teacher');
    hasNot('empty: it does not claim there are no assessments', html, 'No assessments for this');
    hasNot('empty: it invents no count of 0', html, '>0<');
    hasNot('empty: and does not blame filters', html, 'Clear filters');
    eq('empty: no offering was opened', null, ctrl.offering);
  }

  // ========== 8. an offering with no assessments is a DIFFERENT state
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 1);               // Grade 5 — fixture has none

    const html = root.innerHTML;
    has('empty: the no-assessments state is stated', html, 'No assessments for this class and subject');
    hasNot('empty: it is not the no-assignments state', html, 'No classes or subjects assigned');
    hasNot('empty: it is not an error', html, 'Could not load these assessments');
    ok('empty: the user can get back to the assignments',
      root.querySelectorAll('[data-clear-offering]').length > 0);
  }

  // ========== 9. a homeroom assignment has no subject and is not openable
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    const html = root.innerHTML;

    has('homeroom: it is listed as a real assignment', html, 'No subject');
    has('homeroom: and named as homeroom', html, 'homeroom');
    // Three assignments, but only the two with a subject are openable.
    eq('homeroom: only subject offerings are openable',
      2, root.querySelectorAll('[data-offering-class]').length);
    has('homeroom: its action says so', html, 'Not applicable');
    hasNot('homeroom: its assessment count is not a fabricated zero', html, '>0</span>');
  }

  // ========== 10. an assessment count of zero is words, not a zero
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    const html = root.innerHTML;
    has('zero: an offering with none says so in words', html, 'No assessments');
    has('zero: an offering with some shows the real count', html, '>2<');
  }

  // ========== 11. every workflow state is shown, and none is invented
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;

    has('status: approved is shown', html, 'Approved');
    has('status: needs revision is shown', html, 'Needs revision');
    has('status: complete is shown', html, 'Complete');
    has('status: never started is a real answer', html, 'Not started');
    hasNot('status: nothing is labelled unknown', html, 'Unknown');
  }

  // ========== 12. status is workflow state, NOT an academic result
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;

    has('boundary: the screen says status is not a result',
      html, 'not a result');
    // No academic number may appear for a teacher.
    hasNot('boundary: no average is shown', html, 'Average');
    hasNot('boundary: no pass rate is shown', html, 'Pass rate');
    // "Grade" alone would match the class names, so pin the column itself.
    hasNot('boundary: no grade letter column', html, '<th scope="col">Grade</th>');
    hasNot('boundary: no rank', html, 'Rank');
    hasNot('boundary: no score column', html, '>Score<');
  }

  // ========== 13. no teacher ranking, scoring or effectiveness anywhere
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    const assignmentsHtml = root.innerHTML;
    await openOffering(root, 0);
    const html = assignmentsHtml + root.innerHTML;

    ['Effectiveness', 'Performance score', 'Productivity', 'Leaderboard',
     'Top teacher', 'Best teacher', 'Ranking', 'Compared to'].forEach((bad) => {
      hasNot('evaluation: the screen never shows "' + bad + '"', html, bad);
    });
  }

  // ========== 14. the action is the EXISTING review modal
  {
    const { ctrl, root, reviewCalls, navCalls } = build();
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);

    const buttons = root.querySelectorAll('[data-open-submission]');
    eq('action: only the two assessments with a packet offer review', 2, buttons.length);
    eq('action: the first carries the real packet id', '9001', buttons[0].getAttribute('data-open-submission'));

    buttons[0].dispatch('click');
    eq('action: it called the dashboard review modal', 1, reviewCalls.length);
    eq('action: with the packet id, not the assessment id', '9001', String(reviewCalls[0]));
    eq('action: and did not navigate away instead', 0, navCalls.length);

    // The screen must not implement the workflow itself.
    const html = root.innerHTML;
    hasNot('action: the screen does not approve', html, 'data-approve');
    hasNot('action: the screen does not reject', html, 'data-reject');
    hasNot('action: the screen does not request revision', html, 'data-request-revision');
  }

  // ========== 15. a status with no packet offers no broken action
  {
    const { ctrl, root, reviewCalls } = build();
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;

    // Row 43 is "Complete" but has no packet; row 44 never started.
    has('action: a status without a packet says so', html, 'No packet');
    eq('action: and offers no review button for it',
      2, root.querySelectorAll('[data-open-submission]').length);
    eq('action: nothing was opened by rendering', 0, reviewCalls.length);
  }

  // ========== 16. with no dashboard modal present it degrades honestly
  {
    const { ctrl, root, navCalls } = build({ noReviewModal: true });
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    root.querySelectorAll('[data-open-submission]')[0].dispatch('click');

    eq('action: it falls back to the submissions screen', 1, navCalls.length);
    eq('action: which is the existing section', 'submissions', navCalls[0]);
  }

  // ========== 17. a refusal reads as a refusal, not as emptiness
  {
    const { ctrl, root } = build({ notAssigned: true });
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;

    has('refusal: the rejection is stated', html, 'Not assigned to this class and subject');
    hasNot('refusal: it is not shown as an empty list', html, 'No assessments for this class and subject');
    eq('refusal: no assessments were rendered', 0, root.querySelectorAll('[data-open-submission]').length);
  }

  // ========== 18. a failed load is a failure, not an empty teacher
  {
    const { ctrl, root } = build({ fail: 'tracking_teacher_detail' });
    await selectTeacher(ctrl, root, 0);
    const html = root.innerHTML;

    has('error: the failure is stated', html, 'Could not load this teacher');
    hasNot('error: it does not claim the teacher has no assignments',
      html, 'No classes or subjects assigned');
    ok('error: a retry is offered', root.querySelectorAll('[data-retry-teacher]').length > 0);
  }

  // ========== 19. a transport failure is also not an empty teacher
  {
    const { ctrl, root } = build({ boom: 'tracking_teacher_detail' });
    await selectTeacher(ctrl, root, 0);
    const html = root.innerHTML;

    has('error: a network failure is stated', html, 'could not reach the server');
    hasNot('error: it is not an empty state', html, 'No classes or subjects assigned');
    eq('error: the code is recorded', 'network', ctrl.teacher.code);
  }

  // ========== 20. a failed assessment load does not empty the screen
  {
    const { ctrl, root } = build({ fail: 'tracking_teacher_assessments' });
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;

    has('error: the assessment failure is stated', html, 'Could not load these assessments');
    hasNot('error: it does not claim there are none', html, 'No assessments for this class and subject');
    ok('error: a retry is offered', root.querySelectorAll('[data-retry-offering]').length > 0);
  }

  // ========== 21. STALE RESPONSES — resolved out of order, not by reading source
  {
    // Teacher A's detail is made SLOW and teacher B's fast, so A — the
    // abandoned request — lands LAST. Without the guard it would overwrite B.
    const { ctrl, root } = build({ perCall: { tracking_teacher_detail: [80, 5] } });
    await ctrl.boot();
    await ctrl.openList('teachers');
    await tick();

    const rows = root.querySelectorAll('[data-select-id]');
    rows[0].dispatch('click');          // teacher 701 — slow
    await tick();
    rows[3].dispatch('click');          // teacher 704 — fast
    await sleep(140);                   // let BOTH land

    eq('race: the last selection is the scope', 704, ctrl.scope.id);
    eq('race: the abandoned response did not overwrite it',
      704, ctrl.teacher.data.teacher.id);
    has('race: and the screen shows the right teacher', root.innerHTML, 'Teacher 04');
    hasNot('race: not the abandoned one', root.innerHTML, 'Teacher 01');
  }

  // ========== 22. STALE RESPONSES between two offerings of ONE teacher
  {
    // Same teacher throughout, so the teacher guard cannot help: only the
    // offering guard can stop the first offering's slow answer from
    // replacing the second's.
    const { ctrl, root } = build({ perCall: { tracking_teacher_assessments: [80, 5] } });
    await selectTeacher(ctrl, root, 0);

    const btns = root.querySelectorAll('[data-offering-class]');
    btns[0].dispatch('click');          // Grade 4 / Geez — slow, has rows
    await tick();
    const btns2 = root.querySelectorAll('[data-offering-class]');
    (btns2[1] || btns[1]).dispatch('click');   // Grade 5 / Geez — fast, empty
    await sleep(140);

    eq('race: the second offering is the scope', 2, ctrl.offering.class_id);
    const html = root.innerHTML;
    has('race: its own (empty) result is shown', html, 'No assessments for this class and subject');
    hasNot('race: the abandoned offering\u2019s rows did not land', html, 'Geez Midterm');
  }

  // ========== 23. switching teacher discards the previous one entirely
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    ok('reset: an offering is open', ctrl.offering !== null);
    ok('reset: assessments are loaded', ctrl.teacherAssessments.status === 'ready');

    await ctrl.openList('teachers');
    await tick();
    root.querySelectorAll('[data-select-id]')[2].dispatch('click');   // 703
    await tick();
    await tick();

    eq('reset: the new teacher is the scope', 703, ctrl.scope.id);
    eq('reset: the previous offering was dropped', null, ctrl.offering);
    eq('reset: and its assessments with it', 'idle', ctrl.teacherAssessments.status);
    hasNot('reset: no row from the previous teacher survives', root.innerHTML, 'Geez Midterm');
  }

  // ========== 24. clearing the offering returns to the assignments
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    root.querySelectorAll('[data-clear-offering]')[0].dispatch('click');
    await tick();

    eq('back: the offering is cleared', null, ctrl.offering);
    eq('back: the teacher is still selected', 701, ctrl.scope.id);
    has('back: the assignments are shown again', root.innerHTML, 'Classes and subjects');
  }

  // ========== 25. a blank status label never reaches the screen
  {
    const { ctrl, root } = build({ unlabelled: true });
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;
    hasNot('label: no empty status chip is rendered', html, '<span class="ch ch-d"></span>');
    has('label: an unlabelled row still says something', html, 'Not started');
  }

  // ========== 26. the screen computes nothing
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;

    // Weights are printed exactly as given, never summed or normalised.
    has('calc: the first weight is shown as sent', html, '40%');
    has('calc: the second weight is shown as sent', html, '60%');
    // 40 + 60 = 100 must NOT appear as a computed total.
    hasNot('calc: no total weight is computed', html, 'Total weight');
    hasNot('calc: no completion percentage is computed', html, '% complete');
    // A null weight is a dash, not 0%. ("0%" alone matches inside "40%".)
    hasNot('calc: a missing weight is not rendered as zero', html, '>0%<');
    has('calc: a missing weight renders as a dash', html, '\u2014</span></td>');
  }

  // ========== 27. accessibility and the loading state
  {
    const { ctrl, root } = build({ hang: 'tracking_teacher_detail' });
    await selectTeacher(ctrl, root, 0);
    const html = root.innerHTML;

    has('a11y: the panel announces itself busy while loading', html, 'aria-busy="true"');
    has('a11y: the region is live', html, 'aria-live="polite"');
    hasNot('a11y: a loading screen is not an empty screen', html, 'No classes or subjects assigned');
  }

  // ========== 28. headers are real headers, and icons are not emojis
  {
    const { ctrl, root } = build();
    await selectTeacher(ctrl, root, 0);
    const assignHtml = root.innerHTML;
    await openOffering(root, 0);
    const html = assignHtml + root.innerHTML;

    has('a11y: table headers are scoped', html, 'scope="col"');
    has('a11y: decorative icons are hidden from screen readers', html, 'aria-hidden="true"');
    ok('icons: professional icon classes are used', html.indexOf('fa-chalkboard-user') !== -1);
    // No emoji may be used as a UI icon.
    ok('icons: no emoji is used as an icon',
      !/[\u231A-\u27BF\u2B00-\u2BFF\uD83C-\uDBFF]/.test(html.replace(/[\u2014\u2013\u2018\u2019\u201C\u201D\u00b7]/g, '')));
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
