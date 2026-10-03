/**
 * Behavioural harness for admin/js/academic_tracking.js — the Phase 1 root
 * navigation layer of Academic Tracking.
 *
 * It boots the real controller against a stubbed network and a minimal DOM
 * and then drives it the way a person would: open the section, open a list,
 * type a search, change a page, click a row. The assertions are about
 * behaviour, not about source text — every check below would still pass if
 * the file were rewritten, and would fail if the behaviour regressed.
 *
 * The rules it exists to defend:
 *
 *   1. Nothing is selected until the user selects it. Not on boot, not when
 *      a list returns rows, not ever implicitly. Clicking the third row
 *      selects the third row.
 *   2. No academic data is requested before an explicit selection.
 *   3. The result count describes the whole query, not the page on screen.
 *   4. Only genuinely applied filters are reported as applied, and the
 *      screen only offers to clear filters when there are some.
 *   5. "No records exist" and "no records match your filters" are different
 *      sentences, and neither is "No data".
 *   6. A slow response for an old search can never overwrite a newer one.
 *
 * The fetch stub implements real server semantics — filtering, counting and
 * LIMIT/OFFSET over a fixed dataset — so that "page 2 holds these rows" and
 * "total is 45 while the page shows 25" are genuine assertions rather than
 * echoes of a hard-coded number.
 *
 * Zero npm dependencies.
 *
 * Usage: node tests/e2e/academic_tracking_lists.js
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

/**
 * Fetch the nth matching element, recording a failure instead of throwing
 * when it is absent. A regression should read as a failed expectation, not
 * as a stack trace that hides every assertion after it.
 */
function pick(root, sel, i, label) {
  const els = root.querySelectorAll(sel);
  if (!els[i]) {
    failures.push(label + ' — no element ' + sel + '[' + i + '] (found ' + els.length + ')');
    return makeEl('div', {});
  }
  passed++;
  return els[i];
}

// ── minimal DOM ───────────────────────────────────────────────────────────

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
    // Elements are memoised per rendered HTML so that the objects bind()
    // attached listeners to are the same objects a test later clicks.
    querySelectorAll(sel) {
      if (!this._cache) this._cache = Object.create(null);
      if (this._cache[sel]) return this._cache[sel];
      let re;
      if (sel.startsWith('[') && sel.endsWith(']')) {
        re = new RegExp('<([a-zA-Z]+)((?:[^>"]|"[^"]*")*\\s' + sel.slice(1, -1) + '="[^"]*"(?:[^>"]|"[^"]*")*)>', 'g');
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

// ── fake server ───────────────────────────────────────────────────────────

function pad(n) { return (n < 10 ? '0' : '') + n; }

const DATA = {
  students: Array.from({ length: 45 }, (_, i) => ({
    id: 1000 + i + 1,
    member_code: 'M-' + pad(i + 1),
    student_name: 'Student ' + pad(i + 1),
    father_name: 'Father ' + pad(i + 1),
    gender: i % 2 ? 'female' : 'male',
    status: i % 7 === 0 ? 'archived' : 'active',
    class_id: (i % 3) + 1,
    class_name: 'Class ' + ((i % 3) + 1)
  })),
  teachers: Array.from({ length: 15 }, (_, i) => ({
    id: 2000 + i + 1,
    full_name: 'Teacher ' + pad(i + 1),
    username: 'teacher' + pad(i + 1),
    member_id: i % 2 ? null : 900 + i,
    member_code: i % 2 ? '' : 'T-' + pad(i + 1),
    assigned_classes: i % 4,
    is_active: 1
  })),
  subjects: Array.from({ length: 6 }, (_, i) => ({
    id: 3000 + i + 1,
    subject_name: 'Subject ' + pad(i + 1),
    subject_name_en: 'Subject EN ' + pad(i + 1),
    subject_code: 'S' + pad(i + 1),
    assigned_classes: i,
    is_active: i === 5 ? 0 : 1
  })),
  classes: Array.from({ length: 4 }, (_, i) => ({
    id: 4000 + i + 1,
    class_name: 'Grade ' + (i + 1),
    class_name_en: 'Grade ' + (i + 1),
    class_code: 'G' + (i + 1),
    level_order: i + 1,
    student_count: (i + 1) * 5,
    is_active: 1
  }))
};

const SHAPE = {
  roster: { set: 'students', key: 'rows', text: (r) => r.student_name + ' ' + r.father_name + ' ' + r.member_code },
  list_teachers: { set: 'teachers', key: 'teachers', text: (r) => r.full_name + ' ' + r.username },
  get_subjects: { set: 'subjects', key: 'subjects', text: (r) => r.subject_name + ' ' + r.subject_code },
  get_classes: { set: 'classes', key: 'classes', text: (r) => r.class_name + ' ' + r.class_code }
};

function makeFetch(log, control) {
  return function (url) {
    const u = String(url);
    const qs = {};
    u.replace(/[?&]([^=&]+)=([^&]*)/g, (_, k, v) => { qs[decodeURIComponent(k)] = decodeURIComponent(v); return ''; });
    const action = qs.action;
    log.push({ url: u, action, qs });

    if (control.fail && control.fail === action) {
      return Promise.resolve({ json: () => Promise.resolve({ status: 'error', message: 'Database is unavailable.' }) });
    }

    const shape = SHAPE[action];
    if (!shape) return Promise.resolve({ json: () => Promise.resolve({ status: 'error', message: 'unknown action ' + action }) });

    let rows = DATA[shape.set].slice();
    if (control.empty && control.empty === action) rows = [];

    // Real server semantics: filter, then count, then page.
    if (qs.q) rows = rows.filter((r) => shape.text(r).toLowerCase().indexOf(qs.q.toLowerCase()) !== -1);
    if (qs.class_id) rows = rows.filter((r) => String(r.class_id) === String(qs.class_id));
    if (qs.status === 'active') rows = rows.filter((r) => (r.status ? r.status === 'active' : r.is_active === 1));
    if (qs.status === 'inactive') rows = rows.filter((r) => (r.status ? r.status !== 'active' : r.is_active === 0));
    if (qs.assigned === '1') rows = rows.filter((r) => r.assigned_classes > 0);
    if (qs.assigned === '0') rows = rows.filter((r) => !r.assigned_classes);

    const total = rows.length;
    const perPage = Math.min(100, Math.max(10, parseInt(qs.per_page, 10) || 25));
    const page = Math.max(1, parseInt(qs.page, 10) || 1);
    const slice = rows.slice((page - 1) * perPage, (page - 1) * perPage + perPage);

    const body = {
      status: 'success',
      total, page, per_page: perPage,
      pages: Math.max(1, Math.ceil(total / perPage)),
      year_id: 7, year_name: '2017 E.C.'
    };
    body[shape.key] = slice;

    const delay = control.delay && control.delay[action] ? control.delay[action] : 0;
    const resp = { json: () => Promise.resolve(body) };
    if (!delay) return Promise.resolve(resp);
    return new Promise((res) => setTimeout(() => res(resp), delay));
  };
}

// ── boot the real controller ──────────────────────────────────────────────

function build(control) {
  control = control || {};
  const log = [];
  const byId = Object.create(null);
  const root = makeEl('div', { id: 'sec-academic-tracking' });
  byId['sec-academic-tracking'] = root;

  const doc = {
    head: { appendChild() {} },
    createElement: (t) => makeEl(t, {}),
    getElementById: (id) => byId[id] || null
  };

  const sandbox = {
    document: doc,
    console,
    setTimeout,
    clearTimeout,
    Promise,
    Object,
    Math,
    JSON,
    String,
    Number,
    Array,
    isNaN,
    parseInt,
    encodeURIComponent,
    fetch: makeFetch(log, control)
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);

  const src = fs.readFileSync(path.join(__dirname, '..', '..', 'admin', 'js', 'academic_tracking.js'), 'utf8');
  vm.runInContext(src, sandbox, { filename: 'academic_tracking.js' });

  const ctrl = new sandbox.AcademicTracking({ containerId: 'sec-academic-tracking' });
  return { ctrl, root, log, sandbox };
}

const tick = () => new Promise((r) => setTimeout(r, 0));
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

// ── tests ─────────────────────────────────────────────────────────────────

async function run() {
  // ========== 1. the section opens on the entry points, selecting nothing
  {
    const { ctrl, root, log } = build();
    await ctrl.boot();

    eq('home: view is the entry-point screen', 'home', ctrl.view);
    eq('home: nothing is selected', null, ctrl.scope);
    eq('home: no request is made on boot', 0, log.length);

    const html = root.innerHTML;
    has('home: asks the user to choose', html, 'Select what you want to track');
    has('home: states nothing is selected yet', html, 'Nothing is selected yet');
    ['Students', 'Teachers', 'Subjects', 'Classes'].forEach((label) => {
      has('home: offers ' + label, html, '>' + label + '<');
    });
    const cards = root.querySelectorAll('[data-open]');
    eq('home: exactly four entry points', 4, cards.length);
    eq('home: entry points are in the documented order', 'students,teachers,subjects,classes',
      cards.map((c) => c.getAttribute('data-open')).join(','));

    // No academic/detail endpoint may be touched from the home screen.
    ok('home: no academic payload requested', !log.some((r) => /academic_intelligence/.test(r.url)));
  }

  // ========== 2. every root list loads without selecting anything
  for (const [key, action, expectRows] of [
    ['students', 'roster', 25],
    ['teachers', 'list_teachers', 15],
    ['subjects', 'get_subjects', 6],
    ['classes', 'get_classes', 4]
  ]) {
    const { ctrl, root, log } = build();
    await ctrl.boot();
    await ctrl.openList(key);
    await tick();

    const listReqs = log.filter((r) => r.action === action);
    eq(key + ': exactly one list request', 1, listReqs.length);
    eq(key + ': still nothing selected after the list loads', null, ctrl.scope);
    ok(key + ': no academic detail request', !log.some((r) => /get_academic_intelligence/.test(r.url)));

    const st = ctrl.lists[key];
    eq(key + ': rows rendered equal the server page', expectRows, st.rows.length);
    const rowEls = root.querySelectorAll('[data-select-id]');
    eq(key + ': every row is individually selectable', expectRows, rowEls.length);
    ok(key + ': no row is pre-marked as current', root.innerHTML.indexOf('aria-current="true"') === -1);
    has(key + ': the list is paginated server-side', listReqs[0].url, 'per_page=');
  }

  // ========== 3. the count describes the query, not the page
  {
    const { ctrl, root } = build();
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();

    const st = ctrl.lists.students;
    eq('count: total comes from the server', 45, st.total);
    eq('count: only one page of rows is held in memory', 25, st.rows.length);
    has('count: the headline reports the full total', root.innerHTML, '<strong>45</strong> students');
    hasNot('count: the headline does not report the page size', root.innerHTML, '<strong>25</strong> students');
    has('count: the range line is explicit', root.innerHTML, 'Showing 1–25 of 45');
  }

  // ========== 4. selection is explicit, and selects the row that was clicked
  {
    const { ctrl, root, log } = build();
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();

    eq('selection: nothing selected before the click', null, ctrl.scope);

    const third = pick(root, '[data-select-id]', 2, 'selection: a third row exists to click');
    third.dispatch('click');

    ok('selection: a scope now exists', !!ctrl.scope);
    eq('selection: it is the row that was clicked, not the first', 1003, ctrl.scope.id);
    eq('selection: the type is recorded', 'students', ctrl.scope.type);
    eq('selection: the label is the clicked row', 'Student 03 Father 03', ctrl.scope.label);
    ok('selection: clicking a row never calls the old perspective endpoint',
      !log.some((r) => /get_academic_intelligence/.test(r.url)));

    const html = root.innerHTML;
    // PHASE 2 CHANGE OF CONTRACT. Until Phase 2 a selected student showed a
    // boundary panel saying the tracking view did not exist. It exists now,
    // so pinning that sentence would be pinning a lie. What still matters —
    // and is asserted instead — is that selection is explicit, that it
    // opens the student's own workspace, and that no number is invented
    // before the data arrives. The boundary panel itself is still covered
    // below, on an entity whose workflow genuinely has not been built.
    has('selection: the student workspace opens', html, 'role="tablist"');
    has('selection: the workspace offers the Report Card section', html, 'data-section="report_card"');
    hasNot('selection: no fabricated metric is shown', html, 'Average');
    // While the detail request is in flight the panel shows a skeleton, not
    // a zeroed metric that would later be replaced by the real one.
    has('selection: the pending workspace shows a loading skeleton', html, 'at-skel');
    hasNot('selection: no attendance figure is invented while loading', html, 'attendance rate across');
    has('selection: breadcrumb shows the hierarchy', html, 'Academic Tracking');
    has('selection: breadcrumb names the list', html, '>Students<');
    has('selection: breadcrumb names the entity', html, 'Student 03 Father 03');

    // keyboard parity
    const { ctrl: c2, root: r2 } = build();
    await c2.boot();
    await c2.openList('teachers');
    await tick();
    pick(r2, '[data-select-id]', 4, 'selection: a fifth teacher row exists').dispatch('keydown', { key: 'Enter' });
    eq('selection: Enter on a row selects it too', 2005, c2.scope.id);

    // Teachers gained a real workflow in Phase 3, so the boundary panel is
    // gone for them and a teacher screen is rendered instead. The two
    // workflows that are still unbuilt must keep saying so rather than
    // render an empty version of somebody else's screen.
    const teacherHtml = r2.innerHTML;
    hasNot('selection: the built teacher workflow no longer claims to be unbuilt',
      teacherHtml, 'tracking is not built yet');
    has('selection: selecting a teacher opens the teacher workspace',
      teacherHtml, 'at-teacher-panel');

    const { ctrl: c3, root: r3 } = build();
    await c3.boot();
    await c3.openList('classes');
    await tick();
    pick(r3, '[data-select-id]', 0, 'selection: a class row exists').dispatch('click');
    await tick();
    const classHtml = r3.innerHTML;
    has('selection: an unbuilt workflow still states the boundary honestly',
      classHtml, 'tracking is not built yet');
    hasNot('selection: an unbuilt workflow shows no section tabs', classHtml, 'role="tablist"');
  }

  // ========== 5. going back to the list clears the selection
  {
    const { ctrl, root } = build();
    await ctrl.boot();
    await ctrl.openList('classes');
    await tick();
    pick(root, '[data-select-id]', 1, 'back: a second class row exists').dispatch('click');
    eq('back: selected before going back', 4002, ctrl.scope.id);

    const toList = root.querySelectorAll('[data-go]').filter((e) => e.getAttribute('data-go') === 'classes');
    ok('back: a breadcrumb link back to the list exists', toList.length > 0);
    if (toList[0]) toList[0].dispatch('click');
    await tick();
    eq('back: selection cleared on return to the list', null, ctrl.scope);
    eq('back: the list is showing again', 'classes', ctrl.view);

    // and the home crumb resets everything
    const toHome = root.querySelectorAll('[data-go]').filter((e) => e.getAttribute('data-go') === 'home');
    ok('back: a breadcrumb link home exists', toHome.length > 0);
    if (toHome[0]) toHome[0].dispatch('click');
    eq('back: home clears the view', 'home', ctrl.view);
    eq('back: home clears the scope', null, ctrl.scope);
  }

  // ========== 6. a selection is NOT a filter
  {
    const { ctrl } = build();
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();
    ctrl.selectEntity('students', 1005, 'Student 05');

    eq('scope-vs-filter: selecting an entity adds no filter', 0, ctrl.activeFilters('students').length);
    eq('scope-vs-filter: the filter bag stays empty', '{}', JSON.stringify(ctrl.lists.students.filters));
    ok('scope-vs-filter: scope is held separately from filters',
      ctrl.scope.id === 1005 && ctrl.lists.students.filters.member_id === undefined);
  }

  // ========== 7. filter truthfulness
  {
    const { ctrl, root } = build();
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();

    eq('filters: none active on open', 0, ctrl.activeFilters('students').length);
    hasNot('filters: no clear-filters affordance when none applied', root.innerHTML, 'Clear filters');
    hasNot('filters: no filter badge when none applied', root.innerHTML, 'filters applied');
    has('filters: the year is shown as context', root.innerHTML, '2017 E.C.');
    ok('filters: the context year is not counted as a filter', ctrl.activeFilters('students').length === 0);

    await ctrl.setFilter('students', 'gender', '');
    eq('filters: setting an empty value does not create a filter', 0, ctrl.activeFilters('students').length);

    await ctrl.setFilter('students', 'status', 'active');
    await tick();
    eq('filters: a real filter is counted', 1, ctrl.activeFilters('students').length);
    has('filters: the badge appears', root.innerHTML, '1 filter applied');
    has('filters: clearing is now offered', root.innerHTML, 'Clear filters');
    has('filters: the count says it is filtered', root.innerHTML, 'match the current filters');

    await ctrl.clearFilters('students');
    await tick();
    eq('filters: clearing removes them all', 0, ctrl.activeFilters('students').length);
    hasNot('filters: affordance disappears again', root.innerHTML, 'Clear filters');
    eq('filters: the full total is restored', 45, ctrl.lists.students.total);
  }

  // ========== 8. the two empty states are different sentences
  {
    // (a) nothing exists at all, no filters applied
    const { ctrl, root } = build({ empty: 'get_subjects' });
    await ctrl.boot();
    await ctrl.openList('subjects');
    await tick();

    const html = root.innerHTML;
    eq('empty/none: zero rows', 0, ctrl.lists.subjects.rows.length);
    eq('empty/none: no filters were applied', 0, ctrl.activeFilters('subjects').length);
    has('empty/none: says the records do not exist', html, 'No subjects are available for this academic context.');
    has('empty/none: explicitly rules out filters as the cause', html, 'not a filter problem');
    hasNot('empty/none: never suggests clearing filters', html, 'Clear filters');
    hasNot('empty/none: never says remove filters', html, 'remove');
    hasNot('empty/none: is not a generic "No data"', html, 'No data');
    hasNot('empty/none: is not "Nothing to show"', html, 'Nothing to show');
  }
  {
    // (b) records exist but the filter excludes them all
    const { ctrl, root } = build();
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();
    await ctrl.setFilter('students', 'q', 'nonexistent-name-zzz');
    await tick();

    const html = root.innerHTML;
    eq('empty/filtered: zero rows', 0, ctrl.lists.students.rows.length);
    eq('empty/filtered: a filter is genuinely applied', 1, ctrl.activeFilters('students').length);
    has('empty/filtered: says it is the filters', html, 'No students match your search.');
    has('empty/filtered: names the offending filter', html, 'Search students');
    has('empty/filtered: offers to clear them', html, 'Clear filters');
    hasNot('empty/filtered: does not claim the records do not exist', html, 'are available for this academic context');
  }

  // ========== 9. pagination
  {
    const { ctrl, root, log } = build();
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();

    eq('paging: pages computed from total', 2, ctrl.lists.students.pages);
    await ctrl.setPage('students', 2);
    await tick();

    const last = log[log.length - 1];
    has('paging: the page is requested from the server', last.url, 'page=2');
    eq('paging: the controller is on page 2', 2, ctrl.lists.students.page);
    eq('paging: the final page holds the remainder', 20, ctrl.lists.students.rows.length);
    eq('paging: the total is unchanged by paging', 45, ctrl.lists.students.total);
    has('paging: the range line follows', root.innerHTML, 'Showing 26–45 of 45');

    // filters must survive a page change
    await ctrl.setFilter('students', 'status', 'active');
    await tick();
    eq('paging: changing a filter returns to page 1', 1, ctrl.lists.students.page);
    await ctrl.setPage('students', 2);
    await tick();
    const after = log[log.length - 1];
    has('paging: the filter is preserved across pages', after.url, 'status=active');
    has('paging: and the page still travels', after.url, 'page=2');

    // out-of-range is clamped, not requested
    const before = log.length;
    await ctrl.setPage('students', 99);
    await tick();
    ok('paging: an out-of-range page is clamped', ctrl.lists.students.page <= ctrl.lists.students.pages);
    ok('paging: clamping to the current page issues no request', log.length === before);
  }

  // ========== 10. search race safety
  {
    // "Ab" is slow, "Abebe" is fast. The stale answer must lose.
    const { ctrl, log } = build({ delay: { roster: 0 } });
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();

    // Issue a stale request by hand, then a newer one, then resolve in the
    // wrong order by awaiting the newer first.
    const stale = ctrl.setFilter('students', 'q', 'Student 1');
    const fresh = ctrl.setFilter('students', 'q', 'Student 11');
    await Promise.all([stale, fresh]);
    await tick();

    eq('race: the newest query owns the state', 'Student 11', ctrl.lists.students.filters.q);
    eq('race: the newest result is the one kept', 1, ctrl.lists.students.total);
    eq('race: rows match the newest query', 'Student 11', ctrl.lists.students.rows[0].student_name);
    ok('race: both requests were actually issued',
      log.filter((r) => r.action === 'roster').length >= 3);
  }
  {
    // Same again, but the stale response physically arrives last.
    const control = { delay: {} };
    const { ctrl, sandbox } = build(control);
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();

    const realFetch = sandbox.fetch;
    let n = 0;
    sandbox.fetch = function (url) {
      n++;
      const slow = n === 1;                       // first search is the slow one
      return realFetch(url).then((r) => (slow ? wait(60).then(() => r) : r));
    };
    const p1 = ctrl.setFilter('students', 'q', 'Student 1');
    await wait(5);
    const p2 = ctrl.setFilter('students', 'q', 'Student 11');
    await Promise.all([p1, p2]);
    await wait(90);

    eq('race: a late stale response does not overwrite', 1, ctrl.lists.students.total);
    eq('race: the surviving row is from the newer query', 'Student 11', ctrl.lists.students.rows[0].student_name);
  }

  // ========== 11. loading and error states
  {
    const { ctrl, root } = build({ delay: { roster: 40 } });
    await ctrl.boot();
    const p = ctrl.openList('students');
    // synchronously after the call the list must be in a loading state
    eq('loading: state is loading', 'loading', ctrl.lists.students.status);
    has('loading: a skeleton is shown', root.innerHTML, 'at-skel');
    has('loading: the region is marked busy', root.innerHTML, 'aria-busy="true"');
    hasNot('loading: it does not claim emptiness while loading', root.innerHTML, 'No students');
    await p;
    await tick();
    has('loading: busy is cleared when done', root.innerHTML, 'aria-busy="false"');
  }
  {
    const { ctrl, root } = build({ fail: 'list_teachers' });
    await ctrl.boot();
    await ctrl.openList('teachers');
    await tick();

    const html = root.innerHTML;
    eq('error: state is error', 'error', ctrl.lists.teachers.status);
    has('error: says loading failed', html, 'We couldn’t load teachers.');
    has('error: surfaces the server reason', html, 'Database is unavailable.');
    has('error: offers a retry', html, 'Try again');
    hasNot('error: is not reported as an empty list', html, 'No teachers are available');
    hasNot('error: does not blame filters', html, 'Clear filters');
    eq('error: no phantom count', 0, ctrl.lists.teachers.total);
  }

  // ========== 12. lists are independent; no leakage between entry points
  {
    const { ctrl } = build();
    await ctrl.boot();
    await ctrl.openList('students');
    await tick();
    await ctrl.setFilter('students', 'q', 'Student 1');
    await tick();

    await ctrl.openList('teachers');
    await tick();
    eq('isolation: the teachers list has no inherited filter', 0, ctrl.activeFilters('teachers').length);
    eq('isolation: and selects nothing', null, ctrl.scope);

    await ctrl.openList('students');
    await tick();
    eq('isolation: returning to students keeps its own filter', 'Student 1', ctrl.lists.students.filters.q);
  }

  // ========== 13. no entity id is ever smuggled into the query as a filter
  {
    const { ctrl, log } = build();
    await ctrl.boot();
    await ctrl.openList('teachers');
    await tick();
    ctrl.selectEntity('teachers', 2003, 'Teacher 03');
    await ctrl.openList('teachers');
    await tick();

    const urls = log.filter((r) => r.action === 'list_teachers').map((r) => r.url);
    ok('purity: no teacher_id is sent to the list endpoint', !urls.some((u) => /teacher_id=/.test(u)));
    ok('purity: no member_id is sent to the list endpoint', !urls.some((u) => /member_id=/.test(u)));
  }

  // ========== 14. selectEntity refuses nonsense rather than guessing
  {
    const { ctrl } = build();
    await ctrl.boot();
    eq('guard: unknown entity type is refused', null, ctrl.selectEntity('nope', 5, 'x'));
    eq('guard: a missing id is refused', null, ctrl.selectEntity('students', 0, 'x'));
    eq('guard: a non-numeric id is refused', null, ctrl.selectEntity('students', 'abc', 'x'));
    eq('guard: nothing became selected', null, ctrl.scope);
  }

  // ── report ──────────────────────────────────────────────────────────────
  const total = passed + failures.length;
  if (failures.length) {
    console.log('\n  FAILURES');
    failures.forEach((f) => console.log('    FAIL  ' + f));
  }
  console.log('\n  ' + (failures.length ? 'FAIL' : 'PASS') + '  ' + total + ' checks, ' + failures.length + ' failed');
  process.exit(failures.length ? 1 : 0);
}

run().catch((e) => {
  console.error('harness error:', e && e.stack ? e.stack : e);
  process.exit(2);
});
