/**
 * Render harness for admin/js/academic_intelligence.js
 *
 * The workspace renderer reads several dozen field names off payloads built
 * by AcademicIntelligenceService. A typo in any one of them produces
 * "undefined" on an administrator's screen and nothing anywhere complains.
 * Source-string tests cannot catch that, and a browser is not available in
 * CI, so this file supplies the smallest DOM that the renderer actually
 * touches and runs it against REAL payloads captured from the live endpoint.
 *
 * It asserts what a reviewer would look for on screen:
 *   - rendering does not throw, for any perspective
 *   - no "undefined" / "NaN" / "[object Object]" reaches the markup
 *   - a null measurement renders as an em dash and never as 0
 *   - drill-down controls are present and carry real ids
 *   - charts are handed finite numbers, with gaps preserved as null
 *     rather than flattened to zero
 *
 * Zero npm dependencies on purpose: it must run wherever node exists.
 *
 * Usage: node tests/e2e/academic_intelligence_render.js <payload-dir>
 * where <payload-dir> holds student.json, teacher.json, subject.json,
 * class.json plus any *_empty.json variants.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

// ── the smallest DOM the renderer actually uses ───────────────────────────

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/** Pull `<tag ... >` occurrences matching a very small selector grammar. */
function matchTags(html, selector) {
  let re;
  if (selector.startsWith('[') && selector.endsWith(']')) {
    const attr = selector.slice(1, -1);
    re = new RegExp('<([a-zA-Z]+)([^>]*\\s' + attr + '=(?:"[^"]*")[^>]*)>', 'g');
  } else if (selector.startsWith('.')) {
    const cls = selector.slice(1);
    re = new RegExp('<([a-zA-Z]+)([^>]*\\sclass="[^"]*\\b' + cls + '\\b[^"]*"[^>]*)>', 'g');
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
  const el = {
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
    querySelectorAll(sel) { return matchTags(this._html, sel); },
    getContext() { return {}; }
  };
  return el;
}

function buildWindow() {
  const byId = Object.create(null);
  const doc = {
    createElement: (t) => makeEl(t, {}),
    getElementById: (id) => byId[id] || null,
    _register: (id) => (byId[id] = byId[id] || makeEl('div', { id }))
  };
  // Containers the renderer writes into. Everything else it creates itself
  // inside innerHTML strings, which this harness then scans.
  ['sec-academic-intel', 'aiFilterBar', 'aiBody', 'aiTrail', 'aiTabs', 'aiRefresh']
    .forEach(doc._register);

  const charts = [];
  function Chart(ctx, config) {
    charts.push(config);
    this.destroy = function () {};
  }

  const win = {
    document: doc,
    Chart,
    _charts: charts,
    _byId: byId,
    setTimeout: (fn) => fn,
    clearTimeout: () => {},
    fetch: () => Promise.reject(new Error('network disabled in harness')),
    encodeURIComponent
  };
  win.window = win;
  return win;
}

// ── missing-field detection ───────────────────────────────────────────────
//
// A mistyped field name does NOT show up as "undefined" on screen, because
// the renderer deliberately prints an em dash for anything missing -- a real
// requirement (an ungraded subject must not read as 0) that doubles as a
// place for typos to hide. So instead of inspecting the output, the payload
// is wrapped in a recording Proxy and every read of a key that does not
// exist is reported.
//
// A few reads are intentional optional probes rather than mistakes; they are
// listed here so that the list itself documents them.
const INTENTIONAL_OPTIONAL = new Set([
  // Chart point labels: subject views label by class, student views by
  // subject, and the renderer accepts either.
  'charts.semester_comparison[].subject_name',
  'charts.semester_comparison[].label',
  // Attendance block is absent for a student with no recorded sessions.
  'charts.attendance',
  // Only the class perspective paginates.
  'drilldown.total',
  'drilldown.has_more',
  'drilldown.students'
]);

const JS_INTERNALS = new Set([
  'then', 'toJSON', 'constructor', 'hasOwnProperty', 'nodeType', 'length',
  'inspect', 'valueOf', 'toString', 'name', 'message', 'splice'
]);

function observe(value, pathPrefix, misses) {
  if (value === null || typeof value !== 'object') return value;
  return new Proxy(value, {
    get(target, key, receiver) {
      if (typeof key === 'symbol') return Reflect.get(target, key, receiver);
      const isIndex = /^\d+$/.test(key);
      const childPath = Array.isArray(target)
        ? pathPrefix + '[]'
        : (pathPrefix ? pathPrefix + '.' + key : key);
      if (!(key in target) && !JS_INTERNALS.has(key) && !isIndex) {
        misses.add(childPath);
        return undefined;
      }
      return observe(Reflect.get(target, key, receiver), childPath, misses);
    }
  });
}

// ── assertions ────────────────────────────────────────────────────────────

let checks = 0, failures = 0;
function check(name, cond, detail) {
  checks++;
  if (!cond) {
    failures++;
    console.log('  FAIL  ' + name + (detail ? '  — ' + detail : ''));
  }
}
function section(t) { console.log('\n── ' + t + ' ' + '─'.repeat(Math.max(0, 56 - t.length))); }

// ── run ───────────────────────────────────────────────────────────────────

const payloadDir = process.argv[2];
if (!payloadDir || !fs.existsSync(payloadDir)) {
  console.error('usage: node academic_intelligence_render.js <payload-dir>');
  process.exit(2);
}

const SRC = path.join(__dirname, '..', '..', 'admin', 'js', 'academic_intelligence.js');
const source = fs.readFileSync(SRC, 'utf8');

function renderPayload(payload, misses) {
  const win = buildWindow();
  const ctx = vm.createContext(win);
  vm.runInContext(source, ctx, { filename: 'academic_intelligence.js' });

  const AI = win.AcademicIntelligence;
  const inst = new AI({ containerId: 'sec-academic-intel' });
  inst._booted = true;
  inst.perspective = payload.perspective;
  inst.data = misses ? observe(payload, '', misses) : payload;
  // renderShell writes the tab strip and creates aiBody/aiFilterBar holders.
  inst.renderShell();
  inst.render();
  return { win, inst, html: win._byId['aiBody'].innerHTML, charts: win._charts };
}

const files = fs.readdirSync(payloadDir).filter((f) => f.endsWith('.json')).sort();
if (!files.length) {
  console.error('no payloads in ' + payloadDir);
  process.exit(2);
}

files.forEach((file) => {
  const payload = JSON.parse(fs.readFileSync(path.join(payloadDir, file), 'utf8'));
  section(file + '  (' + payload.perspective + ')');

  const misses = new Set();
  let out;
  try {
    out = renderPayload(payload, misses);
  } catch (e) {
    check('renders without throwing', false, e.message + '\n' + (e.stack || '').split('\n')[1]);
    return;
  }
  check('renders without throwing', true);

  const realMisses = [...misses].filter((m) => !INTENTIONAL_OPTIONAL.has(m));
  check('reads no field the payload does not provide', realMisses.length === 0,
    realMisses.join(', '));

  const html = out.html;
  check('produced markup', html.length > 200, html.length + ' chars');

  // A field-name typo surfaces as one of these.
  check('no "undefined" in markup', !/\bundefined\b/.test(html),
    (html.match(/.{0,60}undefined.{0,40}/) || [''])[0]);
  check('no "NaN" in markup', !/\bNaN\b/.test(html),
    (html.match(/.{0,60}NaN.{0,40}/) || [''])[0]);
  check('no raw objects in markup', !/\[object Object\]/.test(html));
  check('no literal "null" rendered', !/>\s*null\s*</.test(html));

  // Chart data must be numeric; gaps stay null.
  out.charts.forEach((cfg, i) => {
    (cfg.data.datasets || []).forEach((ds) => {
      (ds.data || []).forEach((v) => {
        const ok = v === null || typeof v === 'number'
          ? (v === null || isFinite(v))
          : (v && typeof v === 'object' && isFinite(v.x) && isFinite(v.y));
        check('chart ' + i + ' dataset values finite or null', ok, JSON.stringify(v));
      });
    });
  });

  // Drill-down wiring.
  const drills = matchTags(html, '[data-drill]');
  // A perspective with no rows has nothing to drill into; that is the empty
  // state working, not a missing link.
  if (payload.perspective !== 'student' && (payload.rows || []).length > 0) {
    check('exposes drill-down controls', drills.length > 0, drills.length + ' found');
  }
  drills.forEach((d) => {
    const target = d.getAttribute('data-drill');
    check('drill target is a real perspective',
      ['student', 'teacher', 'subject', 'class'].indexOf(target) !== -1, target);
    const ids = ['data-class-id', 'data-subject-id', 'data-teacher-id', 'data-member-id']
      .map((k) => d.getAttribute(k)).filter((v) => v !== null);
    check('drill carries at least one id', ids.length > 0);
    ids.forEach((v) => check('drill id is numeric and set', /^\d+$/.test(v) && v !== '0', v));
  });

  // ── null vs zero, per individual KPI ───────────────────────────────
  //
  // "nobody passed" and "there is nothing to measure yet" are different
  // facts and must not render the same way. Checking the page as a whole
  // cannot tell them apart -- an empty class legitimately shows 0% for
  // curriculum recorded while every average beside it is unmeasurable -- so
  // each KPI is matched to the summary field behind it.
  const KPI_FIELD = {
    student: {
      'Attendance': 'attendance_rate'
    },
    teacher: {
      'Average result': 'average', 'Pass rate': 'pass_rate',
      'Assessment delivery': 'delivery_rate'
    },
    subject: {
      'Average': 'average', 'Pass rate': 'pass_rate', 'Highest': 'highest'
    },
    class: {
      'Pass rate': 'pass_rate', 'Highest': 'highest', 'Median': 'median',
      'Attendance': 'attendance_rate', 'Curriculum': 'curriculum_recorded_pct'
    }
  };
  const summary = payload.summary || {};
  const kpiRe = /letter-spacing:\.03em">([^<]*)<\/div><div style="font-size:1\.45rem[^"]*">([\s\S]*?)<\/div>/g;
  const seenKpis = {};
  let km;
  while ((km = kpiRe.exec(html)) !== null) seenKpis[km[1].trim()] = km[2].trim();
  check('KPI block was rendered', Object.keys(seenKpis).length > 0);

  const fieldMap = KPI_FIELD[payload.perspective] || {};
  Object.keys(fieldMap).forEach((label) => {
    if (!(label in seenKpis)) return;
    const field = fieldMap[label];
    const raw = summary[field];
    const shown = seenKpis[label];
    if (raw === null || raw === undefined) {
      check('unmeasurable "' + label + '" shows a dash, not zero',
        shown.indexOf('\u2014') !== -1,
        field + '=' + JSON.stringify(raw) + ' rendered as "' + shown + '"');
    } else if (Number(raw) === 0) {
      check('a real zero "' + label + '" shows 0, not a dash',
        /0/.test(shown) && shown.indexOf('\u2014') === -1,
        field + '=0 rendered as "' + shown + '"');
    }
  });
});

section('result');
console.log((failures === 0 ? '  PASS  ' : '  FAIL  ') + checks + ' checks, ' + failures + ' failed');
process.exit(failures === 0 ? 0 : 1);
