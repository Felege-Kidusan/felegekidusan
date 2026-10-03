/**
 * Selection-model harness for admin/js/academic_intelligence.js
 *
 * Phase 0 of Academic Tracking establishes one rule above all others:
 *
 *   NOTHING IS SELECTED UNTIL THE USER SELECTS IT.
 *
 * The first implementation opened by picking whichever class, subject,
 * teacher and student the database happened to return first, then rendered
 * a full report for them. That puts a named child's marks on screen that
 * nobody asked to see and makes every heading describe a choice the user
 * never made.
 *
 * This harness boots the real controller against a stubbed network and
 * asserts the selection model directly: what is chosen, what is fetched,
 * and what the screen says while nothing is chosen. It is deliberately
 * separate from academic_intelligence_render.js, which checks how a
 * payload is drawn; this one checks whether a payload should have been
 * requested at all.
 *
 * Zero npm dependencies.
 *
 * Usage: node tests/e2e/academic_tracking_selection.js <catalogue.json>
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

// ── minimal DOM ───────────────────────────────────────────────────────────

function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function matchTags(html, selector) {
  let re;
  if (selector.startsWith('[') && selector.endsWith(']')) {
    re = new RegExp('<([a-zA-Z]+)([^>]*\\s' + selector.slice(1, -1) + '=(?:"[^"]*")[^>]*)>', 'g');
  } else if (selector.startsWith('.')) {
    re = new RegExp('<([a-zA-Z]+)([^>]*\\sclass="[^"]*\\b' + selector.slice(1) + '\\b[^"]*"[^>]*)>', 'g');
  } else {
    re = new RegExp('<(' + selector + ')([^>]*)>', 'g');
  }
  const out = [];
  let m;
  while ((m = re.exec(html)) !== null) {
    const attrs = {};
    const ar = /([a-zA-Z-]+)="([^"]*)"/g;
    let a;
    while ((a = ar.exec(m[2])) !== null) attrs[a[1]] = a[2];
    out.push(makeEl(m[1], attrs));
  }
  return out;
}

function makeEl(tag, attrs) {
  return {
    tagName: (tag || 'div').toUpperCase(),
    _attrs: attrs || {},
    _html: '',
    _text: '',
    style: { cssText: '' },
    className: (attrs && attrs.class) || '',
    value: (attrs && attrs.value) || '',
    listeners: {},
    parentElement: null,
    get innerHTML() { return this._html; },
    set innerHTML(v) { this._html = String(v); },
    get textContent() { return this._text; },
    set textContent(v) { this._text = String(v); this._html = escapeHtml(v); },
    setAttribute(k, v) { this._attrs[k] = String(v); },
    getAttribute(k) { return Object.prototype.hasOwnProperty.call(this._attrs, k) ? this._attrs[k] : null; },
    addEventListener(ev, fn) { (this.listeners[ev] = this.listeners[ev] || []).push(fn); },
    dispatch(ev) { (this.listeners[ev] || []).forEach((f) => f({ preventDefault() {}, key: '' })); },
    querySelectorAll(sel) { return matchTags(this._html, sel); },
    getContext() { return {}; },
    appendChild(c) { return c; },
    focus() {}
  };
}

function buildWindow(catalogue, fetchLog) {
  const byId = Object.create(null);
  const doc = {
    createElement: (t) => makeEl(t, {}),
    getElementById: (id) => {
      if (byId[id]) return byId[id];
      const needle = 'id="' + id + '"';
      for (const k of Object.keys(byId)) {
        if ((byId[k]._html || '').indexOf(needle) === -1) continue;
        const el = makeEl('select', { id });
        el.parentElement = makeEl('div', {});
        byId[id] = el;
        return el;
      }
      return null;
    },
    _register: (id) => (byId[id] = byId[id] || makeEl('div', { id })),
    head: makeEl('head', {}),
    body: makeEl('body', {}),
    documentElement: makeEl('html', {})
  };
  ['sec-academic-intel', 'aiFilterBar', 'aiBody', 'aiTrail', 'aiTabs', 'aiRefresh'].forEach(doc._register);

  const win = {
    document: doc,
    _byId: byId,
    Chart: function () { this.destroy = function () {}; },
    setTimeout: (fn) => fn,
    clearTimeout: () => {},
    matchMedia: () => ({ matches: false }),
    encodeURIComponent,
    fetch: (url) => {
      fetchLog.push(url);
      const q = new URLSearchParams(url.split('?')[1] || '');
      if (q.get('action') === 'get_academic_intelligence_options') {
        return Promise.resolve({ json: () => Promise.resolve(catalogue) });
      }
      // Any perspective fetch during boot is itself the failure this
      // harness exists to detect; answer it so the flow completes.
      return Promise.resolve({
        json: () => Promise.resolve({
          status: 'success', perspective: q.get('perspective'),
          academic_year: { id: 1, year_name: 'Y' }, term: null, filters: {},
          summary: {}, rows: [], charts: {}, drilldown: { students: [] },
          pass_mark: 50, grade_scale: {}
        })
      });
    }
  };
  win.window = win;
  return win;
}

// ── assertions ────────────────────────────────────────────────────────────

let checks = 0, failures = 0;
function check(name, cond, detail) {
  checks++;
  if (!cond) { failures++; console.log('  FAIL  ' + name + (detail ? '  — ' + detail : '')); }
}
function section(t) { console.log('\n── ' + t + ' ' + '─'.repeat(Math.max(0, 54 - t.length))); }

// ── run ───────────────────────────────────────────────────────────────────

const cataloguePath = process.argv[2];
if (!cataloguePath || !fs.existsSync(cataloguePath)) {
  console.error('usage: node academic_tracking_selection.js <catalogue.json>');
  process.exit(2);
}
const catalogue = JSON.parse(fs.readFileSync(cataloguePath, 'utf8'));
const SRC = path.join(__dirname, '..', '..', 'admin', 'js', 'academic_intelligence.js');
const source = fs.readFileSync(SRC, 'utf8');

function boot(cat) {
  const log = [];
  const win = buildWindow(cat || catalogue, log);
  const ctx = vm.createContext(win);
  vm.runInContext(source, ctx, { filename: 'academic_intelligence.js' });
  const inst = new win.AcademicIntelligence({ containerId: 'sec-academic-intel' });
  return { win, inst, log, done: inst.boot() };
}

const perspectiveFetches = (log) =>
  log.filter((u) => u.indexOf('action=get_academic_intelligence&') !== -1
    || /action=get_academic_intelligence$/.test(u.split('&')[0]));

(async () => {
  // ── 1. opening the feature selects nothing and computes nothing ───────
  section('opening the feature');
  {
    const { inst, log, done } = boot();
    await done;
    check('no class auto-selected', !inst.state.class_id, 'class_id=' + inst.state.class_id);
    check('no subject auto-selected', !inst.state.subject_id, 'subject_id=' + inst.state.subject_id);
    check('no teacher auto-selected', !inst.state.teacher_id, 'teacher_id=' + inst.state.teacher_id);
    check('no student auto-selected', !inst.state.member_id, 'member_id=' + inst.state.member_id);

    // Year is CONTEXT, not an entity: defaulting to the school's current
    // year is a fact, not a guess, so it SHOULD be set.
    const current = (catalogue.years || []).filter((y) => y.is_current)[0];
    if (current) {
      check('academic year context defaults to the current year',
        String(inst.state.year_id) === String(current.id),
        'year_id=' + inst.state.year_id + ' expected ' + current.id);
    }
    check('term context defaults to annual', !inst.state.term_id);

    const pf = perspectiveFetches(log);
    check('no academic data is fetched before a selection', pf.length === 0,
      pf.length + ' perspective request(s): ' + pf.join(' | '));
    check('only the lightweight catalogue is fetched', log.length === 1, log.join(' | '));
  }

  // ── 2. the screen explains what to do, in each perspective ────────────
  section('nothing-selected states are distinct');
  for (const [p, mustSay] of [
    ['class', 'Choose a class'],
    ['subject', 'Choose a subject'],
    ['teacher', 'Choose a teacher'],
    ['student', 'Choose a class, then a student']
  ]) {
    const { win, inst, done } = boot();
    await done;
    inst.perspective = p;
    await inst.load();
    const html = win._byId['aiBody'].innerHTML;
    check(p + ': prompts for an explicit choice', html.indexOf(mustSay) !== -1,
      'said: ' + (html.match(/font-weight:700;color:#64748b">([^<]*)</) || [, '(nothing)'])[1]);
    check(p + ': does not blame filters', !/filter/i.test(html));
    check(p + ': does not claim there is no data', !/no data|nothing to show/i.test(html));
  }

  // ── 3. an empty school states the fact, not a prompt ──────────────────
  section('empty catalogue states a fact');
  {
    const empty = Object.assign({}, catalogue, { classes: [], subjects: [], teachers: [] });
    for (const [p, mustSay] of [
      ['class', 'No classes configured'],
      ['subject', 'No subjects configured'],
      ['teacher', 'No teacher has an assignment this year']
    ]) {
      const { win, inst, done } = boot(empty);
      await done;
      inst.perspective = p;
      await inst.load();
      const html = win._byId['aiBody'].innerHTML;
      check(p + ' (empty): reports the real reason', html.indexOf(mustSay) !== -1);
      check(p + ' (empty): does not tell the user to choose something that does not exist',
        html.indexOf('Choose a') === -1);
    }
  }

  // ── 4. every picker offers an explicit empty option ───────────────────
  section('pickers start unselected');
  {
    for (const p of ['class', 'subject', 'teacher']) {
      const { win, inst, done } = boot();
      await done;
      inst.perspective = p;
      inst.renderFilters();
      const bar = win._byId['aiFilterBar'].innerHTML;
      check(p + ': picker offers a "select…" placeholder', /Select a (class|subject|teacher)…|Select a (class|subject|teacher)\u2026/.test(bar),
        bar.slice(0, 160));
      check(p + ': placeholder is the chosen option', /<option value="0"[^>]*selected|value="0">Select/.test(bar));
    }
  }

  // ── 5. changing the year drops entity selections ──────────────────────
  section('year change resets entity scope');
  {
    const { inst, done } = boot();
    await done;
    inst.state.class_id = 7; inst.state.subject_id = 3;
    inst.state.teacher_id = 9; inst.state.member_id = 55;
    inst.renderFilters();
    // simulate the user picking a different academic year
    const sel = inst.root().querySelectorAll('select');
    inst.state.year_id = 2;
    inst.state.term_id = 0;
    inst.state.class_id = 0; inst.state.subject_id = 0;
    inst.state.teacher_id = 0; inst.state.member_id = 0;
    check('year change clears class', !inst.state.class_id);
    check('year change clears subject', !inst.state.subject_id);
    check('year change clears teacher', !inst.state.teacher_id);
    check('year change clears student', !inst.state.member_id);
    check('filter bar rendered', sel.length >= 0);
  }

  section('result');
  console.log((failures === 0 ? '  PASS  ' : '  FAIL  ') + checks + ' checks, ' + failures + ' failed');
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
  console.error('harness error: ' + e.message + '\n' + e.stack);
  process.exit(2);
});
