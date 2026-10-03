/**
 * Behavioural harness for the Phase 5 Class Tracking workflow in
 * admin/js/academic_tracking.js.
 *
 * It boots the real controller against a stubbed network and a minimal
 * DOM, selects a class the way a person would, and drives the four
 * panels. Every assertion is about behaviour, not source text.
 *
 * The rules it exists to defend:
 *
 *   1. Nothing is selected for the user — including when there is
 *      exactly one class, which is the case rows[0] hides.
 *   2. A class is a SCOPE, not a filter. Selecting one must never
 *      appear in the applied-filters count or offer "clear filters".
 *   3. Homeroom and subject teaching are different relationships. A
 *      homeroom holder never appears as the teacher of a subject.
 *   4. A standing assignment (no academic year) is preserved and
 *      labelled, never rewritten into the selected year.
 *   5. Assessment exists / packet exists / packet status / mark
 *      recorded are FOUR facts, shown as four things.
 *   6. Nothing counted is rendered as a bare zero, and a real zero is
 *      never rendered as a dash.
 *   7. An empty class, an empty search and a failed request are three
 *      different states and only one of them mentions the search.
 *   8. A late response for a class the user has left is discarded.
 *   9. Students, subjects and teachers hand off into the workflows
 *      that already exist instead of re-implementing them.
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

/**
 * Five classes. Index 0 is the rich one. Class 4 is deliberately a
 * class with NO academic data at all for the selected year: it must
 * still be listed and still be selectable, because a class is not
 * created by its marks.
 */
const CLASSES = Array.from({ length: 5 }, (_, i) => ({
  id: 1 + i,
  class_name: 'Grade ' + (4 + i),
  class_name_en: 'Grade ' + (4 + i),
  class_code: i === 2 ? null : 'G' + (4 + i),
  section: i === 1 ? 'B' : null,
  level_order: 4 + i,
  is_active: i !== 4,
  student_count: [5, 3, 0, 2, 0][i]
}));

const HOMEROOM = [
  { teacher_id: 21, full_name: 'Tigist Haile', username: 'tigist',
    member_code: 'T-903', is_active: true, is_standing: true }
];

function detailFor(classId) {
  const c = CLASSES.find((x) => x.id === classId) || CLASSES[0];
  // Class 4 (index 3) has a roll but nothing academic; class 5 has
  // nothing at all. Both are real answers, not errors.
  const summary = {
    1: { students: 5, subjects: 2, teachers: 3, assessments: 4 },
    2: { students: 3, subjects: 1, teachers: 1, assessments: 3 },
    3: { students: 0, subjects: 0, teachers: 0, assessments: 0 },
    4: { students: 2, subjects: 1, teachers: 0, assessments: 0 },
    // A count the server could not establish is null, NOT zero.
    5: { students: 0, subjects: 0, teachers: 0, assessments: null }
  }[classId];
  return {
    status: 'success',
    scope: { type: 'class', class_id: classId },
    context: { year_id: 7, year_name: '2017 E.C.', term_id: 0 },
    class: {
      id: c.id, class_name: c.class_name, class_name_en: c.class_name_en,
      class_code: c.class_code, section: c.section, is_active: c.is_active
    },
    summary,
    homeroom_teachers: classId === 1 ? HOMEROOM : [],
    data_state: { klass: 'ready' }
  };
}

const STUDENTS = Array.from({ length: 5 }, (_, i) => ({
  member_id: 100 + i + 1,
  member_code: i === 4 ? null : 'M-' + pad(i + 1),
  student_name: 'Student ' + pad(i + 1),
  father_name: 'Father ' + pad(i + 1),
  gender: i % 2 ? 'female' : 'male',
  status: i === 3 ? 'withdrawn' : 'active'
}));

/**
 * Two offerings. The second has NO teacher even though the fixture's
 * teacher list proves teachers exist for this class — the relationship
 * comes from assignment records, never from the fact that work was
 * filed.
 */
const SUBJECTS = [
  { subject_id: 1, subject_name: 'Geez', subject_name_en: 'Geez',
    duration_type: 'FULL_YEAR', term_id: null,
    teachers: [{ teacher_id: 11, full_name: 'Bekele Tadesse', is_standing: false }],
    assessment_count: 2, has_results: true },
  { subject_id: 3, subject_name: 'History', subject_name_en: 'History',
    // NULL duration is migration 056's third value, not a missing one.
    duration_type: null, term_id: null,
    teachers: [],
    assessment_count: 0, has_results: false }
];

/**
 * Four assignment rows covering every distinction that matters:
 * homeroom vs subject, standing vs year-scoped, active vs inactive.
 */
const TEACHERS = [
  { teacher_id: 21, full_name: 'Tigist Haile', username: 'tigist',
    member_code: 'T-903', is_active: true, is_homeroom: true,
    subject_name: null, assignment_role: 'homeroom',
    is_primary: false, is_standing: true },
  { teacher_id: 11, full_name: 'Bekele Tadesse', username: 'bekele',
    member_code: 'T-901', is_active: true, is_homeroom: false,
    subject_name: 'Geez', assignment_role: 'primary',
    is_primary: true, is_standing: false },
  { teacher_id: 12, full_name: 'Almaz Girma', username: 'almaz',
    member_code: null, is_active: true, is_homeroom: false,
    subject_name: 'Music', assignment_role: 'assistant',
    is_primary: false, is_standing: true },
  { teacher_id: 13, full_name: 'Kebede Worku', username: 'kebede',
    member_code: 'T-904', is_active: false, is_homeroom: false,
    subject_name: 'Geez', assignment_role: 'assistant',
    is_primary: false, is_standing: false }
];

/** The four facts, one assessment for each combination that exists. */
const ASSESSMENTS = [
  { assessment_id: 1, assessment_name: 'Midterm', assessment_type: 'test',
    subject_name: 'Geez', max_score: 100, weight: 40, term_id: 1,
    workflow_status: 'approved', workflow_label: 'Approved',
    submission_id: 9001, has_results: true },
  { assessment_id: 2, assessment_name: 'Final', assessment_type: 'test',
    subject_name: 'Geez', max_score: 100, weight: 60, term_id: 2,
    workflow_status: 'revision_needed', workflow_label: 'Needs revision',
    submission_id: 9002, has_results: false },
  { assessment_id: 3, assessment_name: 'Oral', assessment_type: 'oral',
    subject_name: 'Music', max_score: 50, weight: null, term_id: 1,
    // Status from loose marks: real status, NO packet, marks present.
    workflow_status: 'submitted', workflow_label: 'Complete',
    submission_id: null, has_results: true },
  { assessment_id: 7, assessment_name: 'Project', assessment_type: 'project',
    subject_name: 'Music', max_score: 100, weight: null, term_id: 2,
    // Exists, never started, no packet, no marks.
    workflow_status: null, workflow_label: 'Not started',
    submission_id: null, has_results: false }
];

function studentsFor(classId, page, q) {
  if (classId === 3) {
    return { status: 'success', students: [], total: 0, page: 1, per_page: 3,
      pages: 1, data_state: { students: 'no_records' } };
  }
  let all = STUDENTS;
  if (q) {
    all = STUDENTS.filter((s) =>
      (s.student_name + ' ' + (s.member_code || '')).toLowerCase()
        .indexOf(String(q).toLowerCase()) !== -1);
  }
  const per = 3;
  const p = Math.max(1, page || 1);
  return {
    status: 'success',
    scope: { type: 'class', class_id: classId },
    context: { year_id: 7 },
    students: all.slice((p - 1) * per, (p - 1) * per + per),
    total: all.length, page: p, per_page: per,
    pages: all.length ? Math.ceil(all.length / per) : 1,
    data_state: {
      students: all.length ? 'ok' : (q ? 'filtered_empty' : 'no_records')
    }
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

    if (action === 'get_classes') {
      const per = Math.min(100, Math.max(10, parseInt(qs.per_page, 10) || 25));
      const page = Math.max(1, parseInt(qs.page, 10) || 1);
      const rows = control.oneClass ? CLASSES.slice(0, 1) : CLASSES;
      return reply({
        status: 'success', classes: rows.slice((page - 1) * per, (page - 1) * per + per),
        total: rows.length, page, per_page: per,
        pages: Math.ceil(rows.length / per), year_id: 7, year_name: '2017 E.C.'
      });
    }
    if (action === 'roster' || action === 'list_teachers' || action === 'get_subjects') {
      return reply({
        status: 'success', rows: [], teachers: [], subjects: [], total: 0,
        page: 1, per_page: 25, pages: 1, year_id: 7, year_name: '2017 E.C.'
      });
    }
    if (action === 'tracking_class_detail') {
      if (control.unknownClass) {
        return reply({ status: 'error', code: 'unknown_class',
          message: 'That class does not exist.' });
      }
      if (control.forbidden) {
        return reply({ status: 'error', code: 'forbidden',
          message: 'Access denied for this class.' });
      }
      return reply(detailFor(parseInt(qs.class_id, 10)));
    }
    if (action === 'tracking_class_students') {
      return reply(studentsFor(parseInt(qs.class_id, 10),
        parseInt(qs.page, 10) || 1, qs.q || ''));
    }
    if (action === 'tracking_class_subjects') {
      const cid = parseInt(qs.class_id, 10);
      const rows = cid === 3 ? [] : SUBJECTS;
      return reply({ status: 'success', subjects: rows,
        data_state: { subjects: rows.length ? 'ok' : 'no_records' } });
    }
    if (action === 'tracking_class_teachers') {
      const cid = parseInt(qs.class_id, 10);
      const rows = cid === 3 ? [] : TEACHERS;
      return reply({ status: 'success', teachers: rows,
        data_state: { teachers: rows.length ? 'ok' : 'no_assignment' } });
    }
    if (action === 'tracking_class_assessments') {
      const cid = parseInt(qs.class_id, 10);
      const rows = cid === 3 ? [] : ASSESSMENTS;
      return reply({ status: 'success', assessments: rows,
        data_state: { assessments: rows.length ? 'ok' : 'no_assessments' } });
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

async function selectClass(ctrl, root, index) {
  await ctrl.boot();
  await ctrl.openList('classes');
  await tick();
  const row = pick(root, '[data-select-id]', index, 'setup: a class row exists at ' + index);
  row.dispatch('click');
  await tick();
  await tick();
  return row;
}

async function openTab(root, name) {
  const tabs = root.querySelectorAll('[data-class-tab]');
  const el = tabs.filter((t) => t.getAttribute('data-class-tab') === name)[0];
  if (!el) { failures.push('setup: no tab ' + name); return null; }
  el.dispatch('click');
  await tick();
  await tick();
  return el;
}

async function main() {

  // ========== 1. nothing is selected for the user
  {
    const { ctrl, root, log } = build();
    await ctrl.boot();
    await ctrl.openList('classes');
    await tick();

    eq('root: no class is selected on arrival', null, ctrl.scope);
    eq('root: and no class detail was fetched',
      0, log.filter((c) => String(c.action).indexOf('tracking_class') === 0).length);
    // "Nothing selected" is the list plus an explicit prompt to choose,
    // never a zeroed workspace pretending to be a class.
    hasNot('root: no class workspace is rendered before a choice',
      root.innerHTML, 'at-class-panel');
    has('root: the catalogue is shown instead', root.innerHTML, 'Classes');
    has('root: with a prompt to choose one',
      root.innerHTML, '\u12ad\u134d\u120d \u12ed\u121d\u1228\u1321');

    // The root list must stay cheap: no marks, no attendance, no mark
    // lists before anybody has chosen anything.
    const heavy = log.filter((c) => /report_card|academic_record|attendance|submission/.test(String(c.action)));
    eq('root: the class list loads nothing academic', 0, heavy.length);
  }

  // ========== 2. one single class is still not auto-selected
  {
    const { ctrl, root, log } = build({ oneClass: true });
    await ctrl.boot();
    await ctrl.openList('classes');
    await tick();
    eq('rows[0]: a sole class is still not selected for the user', null, ctrl.scope);
    eq('rows[0]: and nothing was loaded for it',
      0, log.filter((c) => c.action === 'tracking_class_detail').length);
    const rows = root.querySelectorAll('[data-select-id]');
    eq('rows[0]: the sole class is offered, not applied', 1, rows.length);
  }

  // ========== 3. selecting a class opens its workspace
  {
    const { ctrl, root, log } = build();
    await selectClass(ctrl, root, 0);

    eq('select: the class became the scope', 'classes', ctrl.scope.type);
    eq('select: with the right id', 1, ctrl.scope.id);
    has('select: the class workspace is rendered', root.innerHTML, 'at-class-panel');
    has('select: the class is named', root.innerHTML, 'Grade 4');

    // Identity vs reporting context.
    has('context: the reporting year is shown', root.innerHTML, '2017 E.C.');
    has('context: and labelled as context rather than identity',
      root.innerHTML, 'Context, not a filter');

    // A scope is not a filter.
    eq('select: selecting a class applies no filter', 0, ctrl.activeFilters('classes').length);
    hasNot('select: and offers no way to clear filters that were never set',
      root.innerHTML, 'Clear filters');
    has('select: it offers to clear the SELECTION instead',
      root.innerHTML, 'data-clear-selection');

    // Progressive disclosure.
    eq('select: exactly one detail request was made',
      1, log.filter((c) => c.action === 'tracking_class_detail').length);
    eq('select: and no panel loaded itself uninvited',
      0, log.filter((c) => c.action === 'tracking_class_subjects'
        || c.action === 'tracking_class_teachers'
        || c.action === 'tracking_class_assessments').length);
  }

  // ========== 4. the summary counts, and the zero rules
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 0);
    const html = root.innerHTML;
    has('summary: a real count is shown', html, '>5<');
    hasNot('summary: no invented performance score', html, 'Performance score');
    hasNot('summary: no ranking', html, 'Rank');
    hasNot('summary: no pass rate nobody asked for', html, 'Pass rate');

    // Class 3 (index 2) is genuinely empty: real zeros, stated in words.
    const b = build();
    await selectClass(b.ctrl, b.root, 2);
    const h2 = b.root.innerHTML;
    has('zeros: an empty class says so in words', h2, 'No students enrolled');
    has('zeros: for every dimension', h2, 'No subjects offered');
    has('zeros: including teachers', h2, 'No teachers assigned');
    hasNot('zeros: and never as a bare zero', h2, '>0<');

    // Class 5 (index 4) has a count the server could not establish.
    const c = build();
    await selectClass(c.ctrl, c.root, 4);
    has('zeros: an unknown count is a dash, not a zero',
      c.root.innerHTML, '\u2014');
    has('zeros: an empty class is still shown and selectable',
      c.root.innerHTML, 'Grade 8');
  }

  // ========== 5. a class with no academic data still exists
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 3);
    has('quiet year: the class did not disappear', root.innerHTML, 'Grade 7');
    has('quiet year: its roll is still real', root.innerHTML, '>2<');
    has('quiet year: and the absent assessments are stated',
      root.innerHTML, 'No assessments');
  }

  // ========== 6. homeroom is never a subject teacher
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 0);

    has('homeroom: the homeroom holder is named in the header',
      root.innerHTML, 'Tigist Haile');
    has('homeroom: and labelled Homeroom', root.innerHTML, 'Homeroom');
    has('homeroom: a standing homeroom assignment is marked Standing',
      root.innerHTML, 'Standing');

    await openTab(root, 'teachers');
    const html = root.innerHTML;
    has('teachers: the homeroom holder appears in the assignment list', html, 'Tigist Haile');
    has('teachers: described as Homeroom', html, '>Homeroom</span>');
    has('teachers: a subject teacher is described by subject', html, 'Bekele Tadesse');

    // GUARD THE GUARD: prove the subject column really is populated,
    // so "homeroom has no subject" is a finding and not an empty table.
    has('teachers: subjects really are rendered for subject teachers', html, 'Geez');
    has('teachers: and for the second one', html, 'Music');

    // The homeroom row must not carry a subject name. Isolate its cell.
    const hrRow = html.slice(html.indexOf('Tigist Haile'), html.indexOf('Bekele Tadesse'));
    hasNot('homeroom: the homeroom row names no subject', hrRow, 'Geez');
    hasNot('homeroom: none at all', hrRow, 'Music');

    // Standing vs year-scoped are distinguished, not merged.
    const bekele = html.slice(html.indexOf('Bekele Tadesse'), html.indexOf('Almaz Girma'));
    hasNot('standing: a year-scoped assignment is NOT marked Standing', bekele, 'Standing');
    const almaz = html.slice(html.indexOf('Almaz Girma'), html.indexOf('Kebede Worku'));
    has('standing: a standing assignment IS marked Standing', almaz, 'Standing');

    // Active/inactive is a fourth distinct fact.
    has('teachers: an inactive assignment is shown as inactive', html, 'Inactive');
  }

  // ========== 7. subjects come from offerings, not from work filed
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'subjects');
    const html = root.innerHTML;

    has('subjects: the offering is listed', html, 'Geez');
    has('subjects: including one with no teacher', html, 'History');

    // GUARD THE GUARD: teachers ARE rendered for the offering that has
    // one, so "no teacher assigned" is a real finding.
    has('subjects: a teacher is rendered where one is assigned', html, 'Bekele Tadesse');
    const hist = html.slice(html.indexOf('History'));
    has('subjects: and its absence is stated where there is none',
      hist, 'No teacher assigned');

    has('subjects: a NULL duration is "not classified", not a missing value',
      html, 'Not classified');
    has('subjects: a real duration is labelled', html, 'Full year');
    has('subjects: the standing nature of an offering is explained',
      html, 'not tied to one academic year');

    // Assessment count and marks are two facts, not one.
    has('subjects: marks recorded is its own column', html, 'Marks recorded');
    has('subjects: and its absence its own answer', html, 'No marks yet');
    has('subjects: an offering with nothing planned says so', hist, 'No assessments');
  }

  // ========== 8. the four assessment facts stay four facts
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'assessments');
    const html = root.innerHTML;

    has('four facts: the four columns are present', html, '<th scope="col">Mark list</th>');
    has('four facts: marks has its own column', html, '<th scope="col">Marks</th>');

    // Assessment 1: packet, approved, marks.
    const a1 = html.slice(html.indexOf('Midterm'), html.indexOf('Final'));
    has('four facts: a reviewed packet can be opened', a1, 'data-open-submission="9001"');
    has('four facts: its status is shown', a1, 'Approved');
    has('four facts: and its marks separately', a1, 'Recorded');

    // Assessment 2: packet exists, needs revision, NO marks. Proves
    // status and marks are not the same fact.
    const a2 = html.slice(html.indexOf('Final'), html.indexOf('Oral'));
    has('four facts: a packet needing revision is shown as such', a2, 'Needs revision');
    has('four facts: with no marks recorded', a2, 'None yet');

    // Assessment 3: NO packet, but a real status and real marks.
    const a3 = html.slice(html.indexOf('Oral'), html.indexOf('Project'));
    has('four facts: a status with no packet is still a status', a3, 'Complete');
    has('four facts: and says there is no packet to open', a3, 'No packet');
    hasNot('four facts: so it offers no review button', a3, 'data-open-submission');
    has('four facts: yet its marks are recorded', a3, 'Recorded');

    // Assessment 4: exists and nothing else.
    const a4 = html.slice(html.indexOf('Project'));
    has('four facts: an unstarted assessment says Not started', a4, 'Not started');
    has('four facts: and shows no marks', a4, 'None yet');

    // Never a fabricated result.
    hasNot('no fake zeros: a missing mark is never 0%', html, '>0%<');
    hasNot('no fake zeros: nor a zero average', html, '0.0%');

    // A null weight is a dash, not 0%.
    has('weights: a real weight is rendered', html, '40%');
    has('weights: an absent weight is a dash', a3, '\u2014');
  }

  // ========== 9. the review hand-off reuses the existing workflow
  {
    const { ctrl, root, reviewCalls } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'assessments');
    const btn = root.querySelectorAll('[data-open-submission]')[0];
    btn.dispatch('click');
    eq('handoff: the existing review modal was opened', 1, reviewCalls.length);
    eq('handoff: with the submission id, not the assessment id', 9001, reviewCalls[0]);
  }

  // ========== 10. student / subject / teacher hand-offs
  {
    const { ctrl, root, log } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'students');

    const s = root.querySelectorAll('[data-open-student]')[0];
    ok('handoff: a student offers a Track action', !!s);
    s.dispatch('click');
    await tick();
    await tick();
    eq('handoff: tracking a student switches scope to that student',
      'students', ctrl.scope.type);
    eq('handoff: with the student id', 101, ctrl.scope.id);
    has('handoff: and the EXISTING student workspace is rendered',
      root.innerHTML, 'at-section-panel');
    // It must be the student workflow, not a class-flavoured copy.
    eq('handoff: the student workflow loaded itself', 1,
      log.filter((c) => c.action === 'tracking_student_detail').length);
  }
  {
    const { ctrl, root, log } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'teachers');
    root.querySelectorAll('[data-open-teacher]')[0].dispatch('click');
    await tick();
    await tick();
    eq('handoff: tracking a teacher switches to the teacher workflow',
      'teachers', ctrl.scope.type);
    eq('handoff: with the teacher id', 21, ctrl.scope.id);
    eq('handoff: and that workflow fetched its own data', 1,
      log.filter((c) => c.action === 'tracking_teacher_detail').length);
  }
  {
    const { ctrl, root, log } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'subjects');
    root.querySelectorAll('[data-open-subject]')[0].dispatch('click');
    await tick();
    await tick();
    eq('handoff: tracking a subject switches to the subject workflow',
      'subjects', ctrl.scope.type);
    eq('handoff: with the subject id', 1, ctrl.scope.id);
    eq('handoff: and that workflow fetched its own data', 1,
      log.filter((c) => c.action === 'tracking_subject_detail').length);
  }

  // ========== 11. lazy loading, once each
  {
    const { ctrl, root, log } = build();
    await selectClass(ctrl, root, 0);
    const count = (a) => log.filter((c) => c.action === a).length;

    eq('lazy: no panel loaded on selection', 0, count('tracking_class_students'));
    await openTab(root, 'students');
    eq('lazy: the students panel loaded when opened', 1, count('tracking_class_students'));
    eq('lazy: and nothing else did', 0, count('tracking_class_teachers'));

    await openTab(root, 'teachers');
    eq('lazy: the teachers panel loaded when opened', 1, count('tracking_class_teachers'));

    await openTab(root, 'students');
    eq('lazy: returning to a loaded panel does not refetch',
      1, count('tracking_class_students'));
  }

  // ========== 12. search: a filtered empty is not an empty class
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'students');

    hasNot('search: with no search there is nothing to clear',
      root.innerHTML, 'Clear search');

    await ctrl.searchClassStudents('Student 01');
    await tick();
    has('search: a match is shown', root.innerHTML, 'Student 01');
    has('search: and now a clear control appears', root.innerHTML, 'Clear search');

    await ctrl.searchClassStudents('zzzz');
    await tick();
    const html = root.innerHTML;
    has('search: an unmatched search says so', html, 'No student matches this search');
    has('search: quoting what was searched for', html, 'zzzz');
    has('search: and offers to clear it', html, 'Clear search');
    hasNot('search: it does NOT claim the class is empty', html, 'No students enrolled');

    // And the reverse: a genuinely empty class must not blame a search.
    const b = build();
    await selectClass(b.ctrl, b.root, 2);
    await openTab(b.root, 'students');
    const h2 = b.root.innerHTML;
    has('search: an empty class says nobody is enrolled', h2, 'No students enrolled');
    hasNot('search: without mentioning a search nobody made', h2, 'No student matches');
    hasNot('search: and offers no clear-search control', h2, 'Clear search');

    // Clearing restores the full roll.
    const c = build();
    await selectClass(c.ctrl, c.root, 0);
    await openTab(c.root, 'students');
    await c.ctrl.searchClassStudents('zzzz');
    await tick();
    c.root.querySelectorAll('[data-class-student-clear]')[0].dispatch('click');
    await tick();
    await tick();
    eq('search: clearing resets the query', '', c.ctrl.classStudentQuery);
    has('search: and the roll is back', c.root.innerHTML, 'Student 01');
  }

  // ========== 13. pagination
  {
    const { ctrl, root, log } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'students');

    has('pager: page 1 of 2 is stated', root.innerHTML, 'Page 1 of 2');
    has('pager: a previous control exists on page 1',
      root.innerHTML, 'data-class-student-page="0"');
    has('pager: and is disabled at the lower boundary',
      root.innerHTML, 'data-class-student-page="0" disabled>Previous');

    // The shared shim only understands bare attribute selectors, so the
    // page is chosen by reading the attribute back.
    root.querySelectorAll('[data-class-student-page]')
      .filter((b) => b.getAttribute('data-class-student-page') === '2')[0]
      .dispatch('click');
    await tick();
    await tick();
    has('pager: page 2 loads', root.innerHTML, 'Page 2 of 2');
    has('pager: with the later rows', root.innerHTML, 'Student 04');
    const last = log.filter((c) => c.action === 'tracking_class_students').pop();
    eq('pager: the page was requested from the server', '2', last.qs.page);

    has('pager: next is disabled on the last page',
      root.innerHTML, 'data-class-student-page="3" disabled>Next');
  }

  // ========== 14. errors are not emptiness
  {
    const { ctrl, root } = build({ fail: 'tracking_class_detail' });
    await selectClass(ctrl, root, 0);
    const html = root.innerHTML;
    has('error: a failed load is reported as a failure', html, 'Could not load this class');
    has('error: with the server message', html, 'Database is unavailable.');
    has('error: and a retry', html, 'data-retry-class');
    hasNot('error: it is never shown as an empty class', html, 'No students enrolled');
    hasNot('error: nor as a zero', html, '>0<');
  }
  {
    const { ctrl, root } = build({ boom: 'tracking_class_detail' });
    await selectClass(ctrl, root, 0);
    has('error: a transport failure is distinguished',
      root.innerHTML, 'We could not reach the server.');
  }
  {
    const { ctrl, root } = build({ fail: 'tracking_class_teachers' });
    await selectClass(ctrl, root, 0);
    await openTab(root, 'teachers');
    const html = root.innerHTML;
    has('error: a panel failure is scoped to the panel', html, 'Could not load this section');
    has('error: with its own retry', html, 'data-retry-class-tab="teachers"');
    hasNot('error: and does not claim there are no teachers', html, 'No teachers assigned');
    // The class header survived the panel failure.
    has('error: the class itself is still shown', html, 'Grade 4');
  }
  {
    // Retry actually re-requests.
    const control = { fail: 'tracking_class_detail' };
    const { ctrl, root, log } = build(control);
    await selectClass(ctrl, root, 0);
    control.fail = null;
    root.querySelectorAll('[data-retry-class]')[0].dispatch('click');
    await tick();
    await tick();
    eq('error: retry re-requested the class',
      2, log.filter((c) => c.action === 'tracking_class_detail').length);
    has('error: and the class loaded', root.innerHTML, 'Grade 4');
  }

  // ========== 15. authorization answers are distinct
  {
    const { ctrl, root } = build({ forbidden: true });
    await selectClass(ctrl, root, 0);
    has('authz: a refusal is shown as a refusal',
      root.innerHTML, 'Access denied for this class.');
    hasNot('authz: not as an empty class', root.innerHTML, 'No students enrolled');
  }
  {
    const { ctrl, root } = build({ unknownClass: true });
    await selectClass(ctrl, root, 0);
    has('authz: an unknown class is its own answer',
      root.innerHTML, 'That class does not exist.');
  }

  // ========== 16. stale responses are discarded
  {
    // The FIRST class detail answers last. If the guard is removed the
    // screen ends up showing Grade 4 while Grade 5 is selected.
    const { ctrl, root } = build({ perCall: { tracking_class_detail: [80, 5] } });
    await ctrl.boot();
    await ctrl.openList('classes');
    await tick();
    // Captured before the first click, because selecting repaints the
    // list away.
    const rows = root.querySelectorAll('[data-select-id]');
    rows[0].dispatch('click');          // Grade 4 — slow
    await tick();
    rows[1].dispatch('click');          // Grade 5 — fast
    await sleep(140);

    eq('stale: the later selection is the scope', 2, ctrl.scope.id);
    eq('stale: and the state belongs to it', 2, ctrl.klass.data.class.id);
    has('stale: the screen shows the class the user chose', root.innerHTML, 'Grade 5');
    hasNot('stale: not the one they left', root.innerHTML, 'Grade 4');
  }
  {
    // Same race inside one panel.
    const { ctrl, root } = build({ perCall: { tracking_class_students: [80, 5] } });
    await selectClass(ctrl, root, 0);
    ctrl.searchClassStudents('Student 01');
    await sleep(5);
    ctrl.searchClassStudents('Student 02');
    await sleep(140);
    eq('stale: the last search wins', 'Student 02', ctrl.classStudentQuery);
    has('stale: and its result is on screen', root.innerHTML, 'Student 02');
    hasNot('stale: the superseded result is discarded', root.innerHTML, 'Student 01');
  }
  {
    // Leaving the class entirely while a panel is in flight.
    const { ctrl, root } = build({ delay: { tracking_class_students: 60 } });
    await selectClass(ctrl, root, 0);
    openTab(root, 'students');
    await sleep(5);
    ctrl.clearSelection();
    await sleep(120);
    eq('stale: clearing the selection kept it cleared', null, ctrl.scope);
    hasNot('stale: a late panel response did not repaint the class',
      root.innerHTML, 'at-class-panel');
    hasNot('stale: nor any of its roll', root.innerHTML, 'Student 01');
  }

  // ========== 17. leaving and returning
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'students');
    ctrl.clearSelection();
    await tick();
    eq('reset: the class state was cleared', null, ctrl.klass.data);
    eq('reset: and so was the panel state', 'idle', ctrl.classStudents.status);
    eq('reset: and the search', '', ctrl.classStudentQuery);

    // A second class must not inherit the first one's data.
    const b = build();
    await selectClass(b.ctrl, b.root, 0);
    await openTab(b.root, 'students');
    await b.ctrl.selectEntity('classes', 3, 'Grade 6');
    await tick();
    await tick();
    eq('reset: selecting another class resets its panels',
      'idle', b.ctrl.classStudents.status);
    hasNot('reset: and shows none of the previous roll', b.root.innerHTML, 'Student 01');
  }

  // ========== 18. accessibility and keyboard
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'students');
    const html = root.innerHTML;
    has('a11y: the panel announces updates', html, 'aria-live="polite"');
    has('a11y: the tabs are a tablist', html, 'role="tablist"');
    has('a11y: with a label', html, 'aria-label="Class sections"');
    has('a11y: the open tab is marked selected', html, 'aria-selected="true"');
    has('a11y: the panel points at its tab', html, 'aria-labelledby="at-classtab-students"');
    has('a11y: icons are decorative', html, 'aria-hidden="true"');
    has('a11y: table headers are scoped', html, '<th scope="col">Student</th>');

    const tabs = root.querySelectorAll('[data-class-tab]');
    eq('a11y: only the active tab is in the tab order', '0', tabs[0].getAttribute('tabindex'));
    eq('a11y: the others are not', '-1', tabs[1].getAttribute('tabindex'));

    tabs[0].dispatch('keydown', { key: 'ArrowRight' });
    await tick();
    eq('keyboard: ArrowRight moves to the next tab', 'subjects', ctrl.classTab);
    root.querySelectorAll('[data-class-tab]')[1].dispatch('keydown', { key: 'ArrowLeft' });
    await tick();
    eq('keyboard: ArrowLeft moves back', 'students', ctrl.classTab);
    root.querySelectorAll('[data-class-tab]')[0].dispatch('keydown', { key: 'End' });
    await tick();
    eq('keyboard: End jumps to the last tab', 'assessments', ctrl.classTab);
    root.querySelectorAll('[data-class-tab]')[3].dispatch('keydown', { key: 'Home' });
    await tick();
    eq('keyboard: Home jumps to the first', 'students', ctrl.classTab);
  }

  // ========== 19. no academic calculation in the browser
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'assessments');
    // Everything numeric on screen came from the payload.
    has('no-calc: the weight is the served weight', root.innerHTML, '40%');
    hasNot('no-calc: no total was computed', root.innerHTML, 'Total weight');
    hasNot('no-calc: no average was computed', root.innerHTML, 'Class average');
    hasNot('no-calc: no grade letter was derived', root.innerHTML, 'Grade letter');
  }

  // ========== 20. professional icons only
  {
    const { ctrl, root } = build();
    await selectClass(ctrl, root, 0);
    await openTab(root, 'subjects');
    const html = root.innerHTML;
    has('icons: font icons are used', html, 'fa-solid');
    const emoji = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u;
    ok('icons: no emoji anywhere in the class workspace', !emoji.test(html));
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
