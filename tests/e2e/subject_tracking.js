/**
 * Behavioural harness for the Phase 4 Subject Tracking workflow in
 * admin/js/academic_tracking.js.
 *
 * It boots the real controller against a stubbed network and a minimal
 * DOM, selects a subject the way a person would, opens one of the
 * classes that offers it, and drives the teachers / students /
 * assessments panels. Every assertion is about behaviour, not source
 * text.
 *
 * The rules it exists to defend:
 *
 *   1. Nothing is selected for the user. Not the subject, not the class
 *      offering, not the assessment — including when the subject is
 *      offered to exactly one class, which is the case rows[0] hides.
 *   2. The class offering is a secondary SCOPE, not a filter. Opening
 *      one must never appear in the applied-filters count.
 *   3. A subject offered to no class, an offering with no teacher, one
 *      with no students and one with no assessments are four DIFFERENT
 *      states, and none of them is an error or a zero.
 *   4. A count of zero is rendered as words; a null count as a dash.
 *      They are different facts and must not collapse into each other.
 *   5. Teachers come from assignments and students from enrolment. The
 *      screen never presents a mark-list author as a teacher.
 *   6. A standing assignment is badged, not silently shown as a
 *      this-year one. A NULL duration is "Not classified", not full year.
 *   7. Assessment workflow status is workflow state, never an academic
 *      result, and no academic number appears on this screen.
 *   8. The action is the EXISTING review modal.
 *   9. Students are fetched only when their tab is opened, and never
 *      on load.
 *  10. A stale response can never overwrite a newer one — proven by
 *      resolving requests out of order, not by reading the source.
 *
 * Zero npm dependencies.
 *
 * Usage: node tests/e2e/subject_tracking.js
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

/** Six subjects. Index 0 is the rich one; they are NOT all the same. */
const SUBJECTS = Array.from({ length: 6 }, (_, i) => ({
  id: 300 + i + 1,
  subject_name: 'Subject ' + pad(i + 1),
  subject_name_en: 'Subject ' + pad(i + 1),
  subject_code: i === 3 ? null : 'S-' + pad(i + 1),
  is_active: 1,
  assigned_classes: i % 3
}));

const OFFERINGS = {
  // 301: three classes, covering every duration value including NULL.
  301: [
    {
      class_id: 1, class_name: 'Grade 4', class_name_en: 'Grade 4', class_active: true,
      duration_type: 'FULL_YEAR', term_id: null,
      teacher_count: 2, student_count: 5, assessment_count: 2
    },
    {
      class_id: 2, class_name: 'Grade 5', class_name_en: 'Grade 5', class_active: true,
      duration_type: 'SEMESTER_ONLY', term_id: 1,
      // Every count is a real zero here: no teacher, nobody enrolled,
      // nothing planned.
      teacher_count: 0, student_count: 0, assessment_count: 0
    },
    {
      class_id: 3, class_name: 'Grade 6', class_name_en: 'Grade 6', class_active: true,
      // NULL is migration 056's third value, not a missing one.
      duration_type: null, term_id: null,
      teacher_count: 1, student_count: 3, assessment_count: 1
    }
  ],
  // 302: EXACTLY ONE offering. The auto-selection trap.
  302: [
    {
      class_id: 4, class_name: 'Grade 7', class_name_en: 'Grade 7', class_active: true,
      duration_type: 'FULL_YEAR', term_id: null,
      teacher_count: 1, student_count: 2, assessment_count: 1
    }
  ],
  // 303: in the catalogue, offered nowhere.
  303: []
};

function detailFor(subjectId) {
  const s = SUBJECTS.find((x) => x.id === subjectId) || SUBJECTS[0];
  const rows = OFFERINGS[subjectId] || [];
  return {
    status: 'success',
    scope: { type: 'subject', subject_id: subjectId },
    context: { year_id: 7, term_id: 0 },
    subject: {
      id: s.id, subject_name: s.subject_name, subject_name_en: s.subject_name_en,
      subject_code: s.subject_code, is_active: true
    },
    offerings: rows,
    data_state: { offerings: rows.length ? 'ok' : 'not_offered' }
  };
}

const TEACHERS = [
  {
    teacher_id: 11, full_name: 'Bekele Tadesse', username: 'bekele',
    member_code: 'T-901', is_active: true, assignment_role: 'primary',
    is_primary: true, is_standing: false
  },
  {
    // A second teacher on the SAME offering: the schema allows it and
    // neither may be silently dropped. Also a standing assignment.
    teacher_id: 12, full_name: 'Almaz Girma', username: 'almaz',
    member_code: null, is_active: true, assignment_role: 'assistant',
    is_primary: false, is_standing: true
  }
];

const ASSESSMENTS = [
  {
    assessment_id: 41, assessment_name: 'Midterm', assessment_type: 'test',
    max_score: 100, weight: 40, term_id: 1,
    workflow_status: 'approved', workflow_label: 'Approved', submission_id: 9001
  },
  {
    assessment_id: 42, assessment_name: 'Final', assessment_type: 'test',
    max_score: 100, weight: 60, term_id: 2,
    workflow_status: 'revision_needed', workflow_label: 'Needs revision',
    submission_id: 9002
  },
  {
    // Status resolved from loose marks: real status, NO packet.
    assessment_id: 43, assessment_name: 'Oral', assessment_type: 'oral',
    max_score: 50, weight: null, term_id: 1,
    workflow_status: 'submitted', workflow_label: 'Complete', submission_id: null
  },
  {
    // Never started: a real answer, not a gap.
    assessment_id: 44, assessment_name: 'Project', assessment_type: 'project',
    max_score: 100, weight: null, term_id: 2,
    workflow_status: null, workflow_label: 'Not started', submission_id: null
  }
];

function offeringFor(subjectId, classId, control) {
  const empty = classId === 2;          // the deliberately empty offering
  return {
    status: 'success',
    scope: { type: 'subject', subject_id: subjectId, class_id: classId },
    context: { year_id: 7, term_id: 0 },
    subject: { id: subjectId, subject_name: 'Subject 01' },
    class: { id: classId, class_name: 'Grade ' + (classId + 3) },
    offering: {
      duration_type: classId === 3 ? null : 'FULL_YEAR',
      term_id: null
    },
    teachers: empty ? [] : TEACHERS,
    assessments: empty ? [] : ASSESSMENTS,
    data_state: {
      teachers: empty ? 'no_teachers' : 'ok',
      assessments: empty ? 'no_assessments' : 'ok'
    }
  };
}

function studentsFor(subjectId, classId, page) {
  const empty = classId === 2;
  const all = empty ? [] : Array.from({ length: 5 }, (_, i) => ({
    member_id: 100 + i + 1, member_code: 'M-' + pad(i + 1),
    student_name: 'Student ' + pad(i + 1), father_name: 'Father ' + pad(i + 1),
    gender: i % 2 ? 'female' : 'male', status: 'active'
  }));
  const per = 3;
  const p = Math.max(1, page || 1);
  return {
    status: 'success',
    scope: { type: 'subject', subject_id: subjectId, class_id: classId },
    context: { year_id: 7 },
    students: all.slice((p - 1) * per, (p - 1) * per + per),
    total: all.length, page: p, per_page: per,
    pages: all.length ? Math.ceil(all.length / per) : 1,
    data_state: { students: all.length ? 'ok' : 'no_students' }
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
      // action waits perCall[action][n] ms. A stale-response guard can
      // only be exercised if an earlier request is allowed to land LAST.
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

    if (action === 'get_subjects') {
      const per = Math.min(100, Math.max(10, parseInt(qs.per_page, 10) || 25));
      const page = Math.max(1, parseInt(qs.page, 10) || 1);
      return reply({
        status: 'success', subjects: SUBJECTS.slice((page - 1) * per, (page - 1) * per + per),
        total: SUBJECTS.length, page, per_page: per,
        pages: Math.ceil(SUBJECTS.length / per), year_id: 7, year_name: '2017 E.C.'
      });
    }
    if (action === 'roster' || action === 'list_teachers') {
      return reply({
        status: 'success', rows: [], teachers: [], total: 0, page: 1,
        per_page: 25, pages: 1, year_id: 7, year_name: '2017 E.C.'
      });
    }
    if (action === 'get_classes') {
      return reply({ status: 'success', classes: [], total: 0, page: 1, per_page: 25, pages: 1 });
    }
    if (action === 'tracking_subject_detail') {
      return reply(detailFor(parseInt(qs.subject_id, 10)));
    }
    if (action === 'tracking_subject_offering') {
      if (control.notOffered) {
        return reply({
          status: 'error', code: 'not_offered_here',
          message: 'That class does not offer this subject.'
        });
      }
      return reply(offeringFor(parseInt(qs.subject_id, 10), parseInt(qs.class_id, 10), control));
    }
    if (action === 'tracking_subject_students') {
      return reply(studentsFor(parseInt(qs.subject_id, 10), parseInt(qs.class_id, 10),
        parseInt(qs.page, 10) || 1));
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
    console, setTimeout, clearTimeout, encodeURIComponent,
    Promise, Object, Array, Math, JSON, String, Number,
    document: {
      getElementById: (id) => (id === 'sec-academic-tracking' ? root : null),
      createElement: (t) => makeEl(t, {}),
      head
    },
    fetch: makeFetch(log, control)
  };
  sandbox.window = sandbox;
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

async function selectSubject(ctrl, root, index) {
  await ctrl.boot();
  await ctrl.openList('subjects');
  await tick();
  const row = pick(root, '[data-select-id]', index, 'setup: a subject row exists at ' + index);
  row.dispatch('click');
  await tick();
  await tick();
  return row;
}

async function openOffering(root, index, label) {
  const btn = pick(root, '[data-offering-class-id]', index,
    label || ('setup: an offering button exists at ' + index));
  btn.dispatch('click');
  await tick();
  await tick();
  return btn;
}

async function openTab(root, name) {
  const tabs = root.querySelectorAll('[data-subject-tab]');
  const el = tabs.filter((t) => t.getAttribute('data-subject-tab') === name)[0];
  if (!el) { failures.push('setup: no tab ' + name); return null; }
  el.dispatch('click');
  await tick();
  await tick();
  return el;
}

async function main() {
  // ========== 1. nothing is selected until it is clicked
  {
    const { ctrl, root, log } = build();
    await ctrl.boot();
    await ctrl.openList('subjects');
    await tick();

    eq('auto: opening the subjects list selects no subject', null, ctrl.scope);
    eq('auto: and asks for no subject detail',
      0, log.filter((r) => r.action === 'tracking_subject_detail').length);
    eq('auto: no offering is requested',
      0, log.filter((r) => r.action === 'tracking_subject_offering').length);
    eq('auto: no students are requested',
      0, log.filter((r) => r.action === 'tracking_subject_students').length);

    // The root list must stay cheap.
    eq('auto: the root list loads no report card',
      0, log.filter((r) => r.action === 'get_report_card').length);
    eq('auto: the root list loads no assessments',
      0, log.filter((r) => String(r.action).indexOf('assessment') >= 0).length);
  }

  // ========== 2. the clicked subject is the one requested
  {
    const { ctrl, root, log } = build();
    await selectSubject(ctrl, root, 2);

    eq('select: the third row became the scope', 303, ctrl.scope.id);
    const det = log.filter((r) => r.action === 'tracking_subject_detail');
    eq('select: exactly one detail request', 1, det.length);
    eq('select: it carried the clicked subject', '303', det[0].qs.subject_id);
    eq('select: scope type is subjects', 'subjects', ctrl.scope.type);
  }

  // ========== 3. selecting a subject does NOT open one of its offerings
  {
    const { ctrl, root, log } = build();
    await selectSubject(ctrl, root, 0);

    eq('auto: no offering is selected on arrival', null, ctrl.subjectOffering);
    eq('auto: and no offering request was made',
      0, log.filter((r) => r.action === 'tracking_subject_offering').length);
    has('auto: the offerings list is shown instead', root.innerHTML, 'Classes offering this subject');
  }

  // ========== 4. THE rows[0] TRAP: one offering is still not auto-opened
  {
    const { ctrl, root, log } = build();
    await selectSubject(ctrl, root, 1);       // subject 302, exactly one offering

    eq('single: the subject really has one offering',
      1, root.querySelectorAll('[data-offering-class-id]').length);
    eq('single: a sole offering is still not selected', null, ctrl.subjectOffering);
    eq('single: and still triggers no offering request',
      0, log.filter((r) => r.action === 'tracking_subject_offering').length);

    await openOffering(root, 0, 'single: the sole offering can be opened');
    eq('single: now it is the scope', 4, ctrl.subjectOffering.class_id);
    eq('single: and exactly one request went out',
      1, log.filter((r) => r.action === 'tracking_subject_offering').length);
  }

  // ========== 5. the offering is a scope, never a filter
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    eq('scope: selecting a subject applied no filter', 0, ctrl.activeFilters('subjects').length);

    await openOffering(root, 0);
    eq('scope: opening an offering applied no filter either',
      0, ctrl.activeFilters('subjects').length);
    eq('scope: the class is not in the filters bag',
      undefined, ctrl.lists.subjects.filters.class_id);
    ok('scope: it lives in its own field', ctrl.subjectOffering.class_id === 1);

    const html = root.innerHTML;
    has('scope: the selected scope is visible', html, 'Viewing');
    has('scope: it is cleared by changing scope, not filters', html, 'Change class');
    hasNot('scope: the screen never tells the user to remove filters', html, 'Clear filters');
  }

  // ========== 6. the request carries the clicked class
  {
    const { ctrl, root, log } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 2);              // Grade 6, class_id 3

    const req = log.filter((r) => r.action === 'tracking_subject_offering');
    eq('ids: one request', 1, req.length);
    eq('ids: the subject id came from the selection', '301', req[0].qs.subject_id);
    eq('ids: the class id came from the clicked row', '3', req[0].qs.class_id);
  }

  // ========== 7. a subject offered nowhere is its own state
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 2);       // subject 303, no offerings

    const html = root.innerHTML;
    has('empty: the not-offered state is stated', html, 'Not offered to any class');
    hasNot('empty: it is not an error', html, 'Could not load this subject');
    hasNot('empty: it does not claim there are no assessments', html, 'No assessments for this class');
    hasNot('empty: it does not blame filters', html, 'Clear filters');
    eq('empty: no offering was opened', null, ctrl.subjectOffering);
  }

  // ========== 8. no teachers / no students / no assessments are DIFFERENT
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 1);              // class 2 — everything empty

    const teachersHtml = root.innerHTML;
    has('empty: the no-teacher state is stated', teachersHtml, 'No teacher assigned');
    hasNot('empty: it is not the not-offered state', teachersHtml, 'Not offered to any class');
    hasNot('empty: it is not an error', teachersHtml, 'Could not load this offering');

    await openTab(root, 'assessments');
    const aHtml = root.innerHTML;
    has('empty: the no-assessment state is stated', aHtml, 'No assessments for this class');
    hasNot('empty: it is not the no-teacher state', aHtml, 'No teacher assigned');

    await openTab(root, 'students');
    const sHtml = root.innerHTML;
    has('empty: the no-student state is stated', sHtml, 'No students enrolled');
    hasNot('empty: it is not the no-teacher state', sHtml, 'No teacher assigned');
    hasNot('empty: it is not the no-assessment state', sHtml, 'No assessments for this class');
  }

  // ========== 9. a zero count is words; a null count is a dash
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    const html = root.innerHTML;

    has('zero: no teachers reads as words', html, 'No teacher');
    has('zero: no students reads as words', html, 'No students');
    has('zero: no assessments reads as words', html, 'No assessments');
    has('zero: a real count is shown as the number', html, '>5<');
    hasNot('zero: a bare zero is never printed as a count', html, '>0<');
  }

  // ========== 10. duration is shown verbatim, NULL is not full year
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    const html = root.innerHTML;

    has('duration: full year is labelled', html, 'Full year');
    has('duration: semester-only is labelled', html, 'Semester');
    has('duration: a NULL duration says it is unclassified', html, 'Not classified');
  }

  // ========== 11. multiple teachers are all shown, none silently chosen
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;

    has('teachers: the first is listed', html, 'Bekele Tadesse');
    has('teachers: the second is listed too', html, 'Almaz Girma');
    has('teachers: the primary is marked', html, 'Primary');
    has('teachers: the assistant keeps its own role', html, 'Assistant');
  }

  // ========== 12. a standing assignment is badged, not disguised
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;
    has('standing: a standing assignment is labelled', html, 'Standing');
    has('standing: and explained', html, 'Not tied to one academic year');
  }

  // ========== 13. the screen never presents a mark-list author as a teacher
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 1);              // no teachers, but assessments exist
    const html = root.innerHTML;

    has('inference: it says no teacher is assigned', html, 'No teacher assigned');
    has('inference: and says filing work does not make one', html, 'does not make them its teacher');
    // The fixture's packets belong to teacher 11; that must not leak in.
    hasNot('inference: no teacher name appears on a teacherless offering', html, 'Bekele');
  }

  // ========== 14. students are lazy and never fetched on load
  {
    const { ctrl, root, log } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    eq('lazy: opening an offering fetches no students',
      0, log.filter((r) => r.action === 'tracking_subject_students').length);

    await openTab(root, 'students');
    eq('lazy: opening the tab fetches them once',
      1, log.filter((r) => r.action === 'tracking_subject_students').length);

    await openTab(root, 'teachers');
    await openTab(root, 'students');
    eq('lazy: re-opening the tab does not refetch',
      1, log.filter((r) => r.action === 'tracking_subject_students').length);
  }

  // ========== 15. students are paginated
  {
    const { ctrl, root, log } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    await openTab(root, 'students');

    has('students: the roll is shown', root.innerHTML, 'Student 01');
    has('students: a pager appears when there is more than one page', root.innerHTML, 'Page 1 of 2');

    const next = root.querySelectorAll('[data-student-page]').filter(
      (e) => e.getAttribute('data-student-page') === '2')[0];
    ok('students: a next-page control exists', !!next);
    if (next) {
      next.dispatch('click');
      await tick(); await tick();
      const req = log.filter((r) => r.action === 'tracking_subject_students');
      eq('students: the second page was requested', '2', req[req.length - 1].qs.page);
      has('students: page two is shown', root.innerHTML, 'Student 04');
    }
  }

  // ========== 16. workflow status, and none invented
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    await openTab(root, 'assessments');
    const html = root.innerHTML;

    has('status: approved is shown', html, 'Approved');
    has('status: needs revision is shown', html, 'Needs revision');
    has('status: complete is shown', html, 'Complete');
    has('status: never started is a real answer', html, 'Not started');
    hasNot('status: nothing is labelled unknown', html, 'Unknown');
  }

  // ========== 17. status is workflow state, NOT an academic result
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    await openTab(root, 'assessments');
    const html = root.innerHTML;

    has('boundary: the screen says status is not a result', html, 'not a result');
    hasNot('boundary: no average', html, 'Average');
    hasNot('boundary: no pass rate', html, 'Pass rate');
    hasNot('boundary: no grade column', html, '<th scope="col">Grade</th>');
    hasNot('boundary: no score column', html, '<th scope="col">Score</th>');
  }

  // ========== 18. no subject ranking or scoring anywhere
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    const offerings = root.innerHTML;
    await openOffering(root, 0);
    const t = root.innerHTML;
    await openTab(root, 'assessments');
    const html = offerings + t + root.innerHTML;

    ['Subject score', 'Subject rank', 'Ranking', 'Leaderboard', 'Strongest',
     'Weakest', 'Best subject', 'Effectiveness', 'Performance score'].forEach((bad) => {
      hasNot('ranking: the screen never shows "' + bad + '"', html, bad);
    });
  }

  // ========== 19. the action is the EXISTING review modal
  {
    const { ctrl, root, reviewCalls, navCalls } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    await openTab(root, 'assessments');

    const buttons = root.querySelectorAll('[data-open-submission]');
    eq('action: only assessments with a packet offer review', 2, buttons.length);
    eq('action: the first carries the real packet id', '9001', buttons[0].getAttribute('data-open-submission'));

    buttons[0].dispatch('click');
    eq('action: it called the dashboard review modal', 1, reviewCalls.length);
    eq('action: with the packet id, not the assessment id', '9001', String(reviewCalls[0]));
    eq('action: and did not navigate away instead', 0, navCalls.length);

    const html = root.innerHTML;
    hasNot('action: the screen does not approve', html, 'data-approve');
    hasNot('action: the screen does not reject', html, 'data-reject');
  }

  // ========== 20. a status with no packet offers no broken action
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    await openTab(root, 'assessments');
    has('action: a status without a packet says so', root.innerHTML, 'No packet');
    eq('action: and offers no review button for it',
      2, root.querySelectorAll('[data-open-submission]').length);
  }

  // ========== 21. a refusal reads as a refusal
  {
    const { ctrl, root } = build({ notOffered: true });
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    const html = root.innerHTML;

    has('refusal: the rejection is stated', html, 'That class does not offer this subject');
    hasNot('refusal: it is not shown as an empty offering', html, 'No teacher assigned');
    hasNot('refusal: nor as not-offered-at-all', html, 'Not offered to any class');
  }

  // ========== 22. a failed load is a failure, not an empty subject
  {
    const { ctrl, root } = build({ fail: 'tracking_subject_detail' });
    await selectSubject(ctrl, root, 0);
    const html = root.innerHTML;

    has('error: the failure is stated', html, 'Could not load this subject');
    hasNot('error: it does not claim the subject is offered nowhere',
      html, 'Not offered to any class');
    ok('error: a retry is offered', root.querySelectorAll('[data-retry-subject]').length > 0);
  }

  // ========== 23. a transport failure is also not an empty subject
  {
    const { ctrl, root } = build({ boom: 'tracking_subject_detail' });
    await selectSubject(ctrl, root, 0);
    has('error: a network failure is stated', root.innerHTML, 'could not reach the server');
    hasNot('error: it is not an empty state', root.innerHTML, 'Not offered to any class');
    eq('error: the code is recorded', 'network', ctrl.subject.code);
  }

  // ========== 24. a failed student load does not empty the roll
  {
    const { ctrl, root } = build({ fail: 'tracking_subject_students' });
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    await openTab(root, 'students');
    const html = root.innerHTML;

    has('error: the student failure is stated', html, 'Could not load these students');
    hasNot('error: it does not claim nobody is enrolled', html, 'No students enrolled');
    ok('error: a retry is offered', root.querySelectorAll('[data-retry-subject-students]').length > 0);
  }

  // ========== 25. STALE RESPONSES between two subjects
  {
    // Subject A's detail is slow and B's fast, so A — the abandoned
    // request — lands LAST. Without the guard it would overwrite B.
    const { ctrl, root } = build({ perCall: { tracking_subject_detail: [80, 5] } });
    await ctrl.boot();
    await ctrl.openList('subjects');
    await tick();

    const rows = root.querySelectorAll('[data-select-id]');
    rows[0].dispatch('click');          // 301 — slow, has offerings
    await tick();
    rows[2].dispatch('click');          // 303 — fast, offered nowhere
    await sleep(140);

    eq('race: the last selection is the scope', 303, ctrl.scope.id);
    eq('race: the abandoned response did not overwrite it',
      303, ctrl.subject.data.subject.id);
    has('race: the newer (empty) result is shown', root.innerHTML, 'Not offered to any class');
    hasNot('race: the abandoned subject\u2019s offerings did not land',
      root.innerHTML, 'Classes offering this subject');
  }

  // ========== 26. STALE RESPONSES between two offerings of ONE subject
  {
    // Same subject throughout, so the subject guard cannot help: only the
    // offering guard can stop the first class's slow answer landing.
    const { ctrl, root } = build({ perCall: { tracking_subject_offering: [80, 5] } });
    await selectSubject(ctrl, root, 0);

    const btns = root.querySelectorAll('[data-offering-class-id]');
    btns[0].dispatch('click');          // class 1 — slow, has teachers
    await tick();
    const btns2 = root.querySelectorAll('[data-offering-class-id]');
    (btns2[1] || btns[1]).dispatch('click');   // class 2 — fast, empty
    await sleep(140);

    eq('race: the second offering is the scope', 2, ctrl.subjectOffering.class_id);
    const html = root.innerHTML;
    has('race: its own (empty) result is shown', html, 'No teacher assigned');
    hasNot('race: the abandoned offering\u2019s teachers did not land', html, 'Bekele Tadesse');
  }

  // ========== 27. STALE student pages resolve in order
  {
    const { ctrl, root } = build({ perCall: { tracking_subject_students: [80, 5] } });
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    // first students call is slow
    ctrl.openSubjectTab('students');
    await tick();
    // second (page 2) is fast and must win
    ctrl.loadSubjectStudents(2);
    await sleep(140);

    eq('race: the newer page is the one shown', 2, ctrl.subjectStudents.data.page);
    has('race: its rows are shown', root.innerHTML, 'Student 04');
  }

  // ========== 28. switching subject discards the previous one entirely
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    await openTab(root, 'students');
    ok('reset: an offering is open', ctrl.subjectOffering !== null);
    ok('reset: students are loaded', ctrl.subjectStudents.status === 'ready');

    await ctrl.openList('subjects');
    await tick();
    root.querySelectorAll('[data-select-id]')[2].dispatch('click');   // 303
    await tick(); await tick();

    eq('reset: the new subject is the scope', 303, ctrl.scope.id);
    eq('reset: the previous offering was dropped', null, ctrl.subjectOffering);
    eq('reset: and its students with it', 'idle', ctrl.subjectStudents.status);
    eq('reset: and its teachers/assessments', 'idle', ctrl.offeringData.status);
    hasNot('reset: no row from the previous subject survives', root.innerHTML, 'Bekele Tadesse');
  }

  // ========== 29. clearing the offering returns to the class list
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    root.querySelectorAll('[data-clear-offering-class]')[0].dispatch('click');
    await tick();

    eq('back: the offering is cleared', null, ctrl.subjectOffering);
    eq('back: the subject is still selected', 301, ctrl.scope.id);
    has('back: the class list is shown again', root.innerHTML, 'Classes offering this subject');
  }

  // ========== 30. the screen computes nothing
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    await openTab(root, 'assessments');
    const html = root.innerHTML;

    has('calc: the first weight is shown as sent', html, '40%');
    has('calc: the second weight is shown as sent', html, '60%');
    hasNot('calc: no total weight is computed', html, 'Total weight');
    hasNot('calc: no completion percentage', html, '% complete');
    hasNot('calc: a missing weight is not rendered as zero', html, '>0%<');
  }

  // ========== 31. accessibility and the loading state
  {
    const { ctrl, root } = build({ hang: 'tracking_subject_detail' });
    await selectSubject(ctrl, root, 0);
    const html = root.innerHTML;

    has('a11y: the panel announces itself busy while loading', html, 'aria-busy="true"');
    has('a11y: the region is live', html, 'aria-live="polite"');
    hasNot('a11y: a loading screen is not an empty screen', html, 'Not offered to any class');
  }

  // ========== 32. tabs are a real tablist, icons are not emojis
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    const offHtml = root.innerHTML;
    await openOffering(root, 0);
    const html = offHtml + root.innerHTML;

    has('a11y: the tabs are a tablist', html, 'role="tablist"');
    has('a11y: tabs report selection', html, 'aria-selected="true"');
    has('a11y: table headers are scoped', html, 'scope="col"');
    has('a11y: decorative icons are hidden', html, 'aria-hidden="true"');
    ok('icons: professional icon classes are used', html.indexOf('fa-book-open') !== -1);
    ok('icons: no emoji is used as an icon',
      !/[\u231A-\u27BF\u2B00-\u2BFF\uD83C-\uDBFF]/.test(
        html.replace(/[\u2014\u2013\u2018\u2019\u201C\u201D\u00b7]/g, '')));
  }

  // ========== 33. keyboard navigation across the tabs
  {
    const { ctrl, root } = build();
    await selectSubject(ctrl, root, 0);
    await openOffering(root, 0);
    eq('keyboard: teachers is the opening tab', 'teachers', ctrl.subjectTab);

    const tabs = root.querySelectorAll('[data-subject-tab]');
    tabs[0].dispatch('keydown', { key: 'ArrowRight' });
    await tick();
    eq('keyboard: ArrowRight moves to the next tab', 'students', ctrl.subjectTab);

    const tabs2 = root.querySelectorAll('[data-subject-tab]');
    tabs2[1].dispatch('keydown', { key: 'ArrowLeft' });
    await tick();
    eq('keyboard: ArrowLeft moves back', 'teachers', ctrl.subjectTab);

    const tabs3 = root.querySelectorAll('[data-subject-tab]');
    tabs3[0].dispatch('keydown', { key: 'End' });
    await tick();
    eq('keyboard: End jumps to the last tab', 'assessments', ctrl.subjectTab);
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
