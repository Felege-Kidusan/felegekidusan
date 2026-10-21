/**
 * ============================================================================
 * Reporting Centre V2 — browser module (vanilla JS, no bundler).
 * ============================================================================
 * One population (filters + search) drives every tab:
 *   Overview  → KPI strip + clickable charts (ECharts)      action=kpis / aggregate
 *   Breakdown → group‑by table + chart                     action=aggregate
 *   List      → Tabulator grid, server paging & sorting    action=list
 *   Export    → server‑generated CSV / XLSX (audited)      action=export
 *
 * The browser never builds SQL; it sends registry field keys, operators and
 * values. Everything the UI knows about fields comes from action=schema.
 * Client‑side downloads are intentionally NOT used (audit + field security).
 */
(function () {
  'use strict';

  // ───────────────────────── config & state ─────────────────────────
  const CFG = JSON.parse(document.getElementById('rc-config').textContent);
  const ROLE = CFG.role;
  const LS = {
    lang: 'rc.lang',
    columns: 'rc.columns.' + ROLE,
    views: 'rc.views.' + ROLE,
    pageSize: 'rc.pagesize',
  };
  const QUICK_FIELDS = ['age_group', 'status', 'membership_tier', 'gender', 'edu_attendance_band', 'progression'];
  const PERIOD_OPS = ['this_ec_year', 'last_ec_year', 'last_days'];
  const KPI_METRICS = ['count', 'active', 'temporary', 'promotion_eligible', 'missing_phone', 'with_id_card'];
  const KPI_DRILL = {
    active: { field: 'status', op: 'eq', value: 'active' },
    temporary: { field: 'membership_tier', op: 'eq', value: 'temporary' },
    promotion_eligible: { field: 'promotion_eligible', op: 'is_true' },
    missing_phone: { field: 'has_phone', op: 'is_false' },
    with_id_card: { field: 'id_card_status', op: 'eq', value: 'generated' },
  };
  const OVERVIEW_CHARTS = [
    { field: 'gender', kind: 'pie' },
    { field: 'age_group', kind: 'bar' },
    { field: 'status', kind: 'pie' },
    { field: 'membership_tier', kind: 'pie' },
    { field: 'registered_ec_year', kind: 'bar', sortByBucket: true },
    { field: 'sub_city', kind: 'barh', top: 10 },
    { field: 'id_card_status', kind: 'pie' },
    { field: 'completeness_score', kind: 'bar', sortByBucket: true },
    { field: 'current_class', kind: 'barh', top: 12 },
    { field: 'age_band', kind: 'bar' },
    // V2‑2 cross‑domain (Education + Mezmur attendance, enrolment progression, Family Link)
    { field: 'edu_attendance_band', kind: 'pie' },
    { field: 'progression', kind: 'barh' },
    { field: 'mezmur_attendance_band', kind: 'pie' },
    { field: 'fl_guardian_relation', kind: 'pie' },
  ];
  const PALETTE = ['#0f766e', '#0e9aa7', '#e3b341', '#6f171e', '#7c3aed', '#2563eb', '#f97316', '#64748b', '#16a34a', '#db2777', '#0891b2', '#a16207'];

  const state = {
    lang: localStorage.getItem(LS.lang) || CFG.lang || 'en',
    filters: [],
    view: null,          // id of the saved view currently applied (server, V2‑3)
    search: '',
    columns: [],
    sort: [],
    page: 1,
    pageSize: parseInt(localStorage.getItem(LS.pageSize) || '50', 10),
    groupBy: 'age_group',
    metrics: ['count', 'female_pct'],
    tab: 'overview',
    starter: null,
  };
  let schema = null;
  let fieldByKey = {};
  let metricByKey = {};
  let table = null;          // Tabulator (list)
  let breakdownTable = null; // Tabulator (breakdown)
  const charts = {};         // ECharts instances by id
  const aborters = {};       // AbortControllers by kind
  const dirty = { overview: true, breakdown: true, list: true }; // tabs needing a reload
  let applyTimer = null;
  let applyingView = false;   // set while a saved view is being applied so apply() keeps state.view

  // ───────────────────────── helpers ─────────────────────────
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const L = (obj, base) => (state.lang === 'am' ? (obj[base + '_am'] || obj[base + '_en']) : (obj[base + '_en'] || obj[base + '_am'])) || '';
  const fieldLabel = (key) => (fieldByKey[key] ? L(fieldByKey[key], 'label') : key);
  const metricLabel = (key) => (metricByKey[key] ? L(metricByKey[key], 'label') : key);
  const opLabel = (op) => (schema && schema.operator_labels[op]) || op;
  const fmtNum = (v) => (v == null || v === '' ? '—' : Number(v).toLocaleString());
  // Ethiopian calendar only (system-wide rule). Dates arrive as Gregorian ISO
  // strings from the API and are converted for display here; exports are
  // converted server-side by ReportExportService::present().
  const fmtDate = (v) => {
    if (v == null || v === '') return '';
    const s = String(v);
    if (typeof WBWSCalendar === 'undefined' || typeof WBWSCalendar.formatDate !== 'function') return s;
    const iso = s.replace(' ', 'T');
    return s.length > 10 && typeof WBWSCalendar.formatDateTime === 'function' ? WBWSCalendar.formatDateTime(iso) : WBWSCalendar.formatDate(iso, 'medium');
  };

  function toast(msg, isErr) {
    const t = $('#rc-toast');
    t.textContent = msg;
    t.classList.toggle('is-err', !!isErr);
    t.hidden = false;
    clearTimeout(t._h);
    t._h = setTimeout(() => { t.hidden = true; }, isErr ? 5000 : 2600);
  }

  function definition(extra) {
    const d = { dataset: 'members', filters: state.filters, search: state.search };
    return Object.assign(d, extra || {});
  }

  async function api(action, params, kind) {
    if (kind) {
      if (aborters[kind]) aborters[kind].abort();
      aborters[kind] = new AbortController();
    }
    const qs = new URLSearchParams(Object.assign({ action: action, lang: state.lang }, params || {}));
    let res;
    try {
      res = await fetch(CFG.api + '?' + qs.toString(), { credentials: 'same-origin', signal: kind ? aborters[kind].signal : undefined, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    } catch (e) {
      if (e.name === 'AbortError') return null;
      throw new Error('Network error');
    }
    let body = null;
    try { body = await res.json(); } catch (e) { body = null; }
    if (res.status === 401) { window.location.href = '/admin/index.php?error=session_expired'; return null; }
    if (!res.ok || !body || body.status !== 'success') {
      const msg = (body && body.message) || ('Request failed (' + res.status + ')');
      throw new Error(msg);
    }
    if (body.generated_at) {
      const d = new Date(body.generated_at);
      $('#rc-asof').textContent = 'Data as of ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    return body.data;
  }

  // ───────────────────────── URL state ─────────────────────────
  function encodeState() {
    const d = { f: state.filters, s: state.search, c: state.columns, o: state.sort, g: state.groupBy, m: state.metrics, t: state.tab };
    if (state.view) d.v = state.view;
    return '#q=' + encodeURIComponent(JSON.stringify(d));
  }
  function readState() {
    const h = window.location.hash;
    if (!h.startsWith('#q=')) return false;
    try {
      const d = JSON.parse(decodeURIComponent(h.slice(3)));
      if (Array.isArray(d.f)) state.filters = d.f.filter((x) => x && fieldByKey[x.field]);
      if (typeof d.s === 'string') state.search = d.s;
      if (Array.isArray(d.c) && d.c.length) state.columns = d.c.filter((k) => fieldByKey[k]);
      if (Array.isArray(d.o)) state.sort = d.o;
      if (d.g && fieldByKey[d.g]) state.groupBy = d.g;
      if (Array.isArray(d.m) && d.m.length) state.metrics = d.m.filter((k) => metricByKey[k]);
      if (['overview', 'breakdown', 'list'].includes(d.t)) state.tab = d.t;
      state.view = Number.isInteger(d.v) && d.v > 0 ? d.v : null;
      return true;
    } catch (e) { return false; }
  }
  function writeState() {
    history.replaceState(null, '', encodeState());
  }

  // ───────────────────────── filters model ─────────────────────────
  function isQuick(f) {
    return (QUICK_FIELDS.includes(f.field) && f.op === 'in') || (f.field === 'registered_at' && PERIOD_OPS.includes(f.op));
  }
  function setQuick(field, values) {
    state.filters = state.filters.filter((f) => !(f.field === field && f.op === 'in'));
    if (values.length) state.filters.push({ field: field, op: 'in', value: values });
  }
  function setPeriod(v) {
    state.filters = state.filters.filter((f) => !(f.field === 'registered_at' && PERIOD_OPS.includes(f.op)));
    if (v === 'this_ec_year' || v === 'last_ec_year') state.filters.push({ field: 'registered_at', op: v });
    else if (v.startsWith('last_')) state.filters.push({ field: 'registered_at', op: 'last_days', value: parseInt(v.slice(5), 10) });
  }
  function addFilter(f) {
    // replace same field+op for single‑value ops to avoid contradictory stacks
    state.filters = state.filters.filter((x) => !(x.field === f.field && x.op === f.op));
    state.filters.push(f);
  }
  function removeFilterAt(i) { state.filters.splice(i, 1); }

  function describeValue(f) {
    const fd = fieldByKey[f.field];
    const lab = (v) => (fd && fd.options && fd.options[v] != null ? fd.options[v] : (fd && fd.type === 'date' ? fmtDate(v) : v));
    if (f.value == null) return f.op === 'last_days' ? '' : '';
    if (Array.isArray(f.value)) return f.value.map(lab).join(', ');
    return lab(f.value);
  }
  function describeFilter(f) {
    if (f.op === 'last_days') return fieldLabel(f.field) + ' · last ' + f.value + ' days';
    const v = describeValue(f);
    return fieldLabel(f.field) + ' ' + opLabel(f.op) + (v !== '' ? ' ' + v : '');
  }

  // Category label (schema.categories[key][lang]); falls back to the key.
  function catLabel(key) {
    const c = (schema.categories || {})[key];
    return c ? (c[state.lang] || c.en) : key;
  }
  // Fields grouped by category, in registry order of categories.
  function groupByCategory(fields) {
    const order = Object.keys(schema.categories || {});
    const groups = {};
    fields.forEach((f) => { (groups[f.category || 'system'] = groups[f.category || 'system'] || []).push(f); });
    return order.filter((k) => groups[k]).concat(Object.keys(groups).filter((k) => !order.includes(k))).map((k) => ({ key: k, label: catLabel(k), fields: groups[k] }));
  }

  // ───────────────────────── rail rendering ─────────────────────────
  function renderQuick() {
    QUICK_FIELDS.forEach((key) => {
      const box = $('#rc-quick-' + key);
      const fd = fieldByKey[key];
      if (!box || !fd) return;
      const cur = (state.filters.find((f) => f.field === key && f.op === 'in') || {}).value || [];
      let html = '<span class="rc-field-label">' + esc(fieldLabel(key)) + '</span><div class="rc-checks">';
      Object.keys(fd.options || {}).forEach((v) => {
        const on = cur.includes(v);
        html += '<label class="rc-check' + (on ? ' is-on' : '') + '"><input type="checkbox" value="' + esc(v) + '"' + (on ? ' checked' : '') + '> ' + esc(fd.options[v]) + '</label>';
      });
      box.innerHTML = html + '</div>';
      $$('input', box).forEach((inp) => inp.addEventListener('change', () => {
        setQuick(key, $$('input:checked', box).map((i) => i.value));
        state.starter = null;
        apply();
      }));
    });
    const period = state.filters.find((f) => f.field === 'registered_at' && PERIOD_OPS.includes(f.op));
    $('#rc-period').value = !period ? '' : (period.op === 'last_days' ? 'last_' + period.value : period.op);
    $('#rc-search').value = state.search;
  }

  function renderRules() {
    const wrap = $('#rc-rules');
    wrap.innerHTML = '';
    state.filters.forEach((f, i) => {
      if (isQuick(f)) return;
      wrap.appendChild(ruleRow(f, i));
    });
  }

  function ruleRow(f, index) {
    const row = document.createElement('div');
    row.className = 'rc-rule';
    const fields = schema.fields.filter((x) => x.filter && !x.hidden);
    const fsel = document.createElement('select');
    fsel.innerHTML = '<option value="">Field…</option>' + groupByCategory(fields).map((g) => '<optgroup label="' + esc(g.label) + '">' + g.fields.map((x) => '<option value="' + esc(x.key) + '"' + (x.key === f.field ? ' selected' : '') + '>' + esc(L(x, 'label')) + '</option>').join('') + '</optgroup>').join('');
    const osel = document.createElement('select');
    const vbox = document.createElement('div');
    vbox.className = 'rc-rule-row';
    const x = document.createElement('button');
    x.type = 'button'; x.className = 'rc-iconbtn rc-rule-x'; x.title = 'Remove'; x.innerHTML = '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    x.addEventListener('click', () => { if (index != null) removeFilterAt(index); else row.remove(); apply(); });

    const left = document.createElement('div');
    left.className = 'rc-rule-row';
    left.appendChild(fsel); left.appendChild(osel); left.appendChild(vbox);
    row.appendChild(left); row.appendChild(x);

    function ops() {
      const fd = fieldByKey[fsel.value];
      osel.innerHTML = '';
      if (!fd) return;
      schema.operators[fd.type].forEach((op) => {
        const o = document.createElement('option'); o.value = op; o.textContent = opLabel(op); osel.appendChild(o);
      });
      if (f.op && fd.key === f.field) osel.value = f.op;
    }
    function valueInputs() {
      const fd = fieldByKey[fsel.value];
      vbox.innerHTML = '';
      if (!fd) return;
      const op = osel.value;
      if (['is_empty', 'not_empty', 'is_true', 'is_false', 'this_ec_year', 'last_ec_year'].includes(op)) return;
      const cur = (fd.key === f.field && f.op === op) ? f.value : null;
      if (op === 'in' || op === 'not_in') {
        if (fd.options) {
          const box = document.createElement('div'); box.className = 'rc-rule-vals';
          Object.keys(fd.options).forEach((v) => {
            const on = Array.isArray(cur) && cur.includes(v);
            const lab = document.createElement('label'); lab.className = 'rc-check' + (on ? ' is-on' : '');
            lab.innerHTML = '<input type="checkbox" value="' + esc(v) + '"' + (on ? ' checked' : '') + '> ' + esc(fd.options[v]);
            box.appendChild(lab);
          });
          vbox.appendChild(box);
        } else {
          const inp = document.createElement('input'); inp.type = 'text'; inp.placeholder = 'value1, value2, …';
          inp.value = Array.isArray(cur) ? cur.join(', ') : '';
          vbox.appendChild(inp);
        }
        return;
      }
      if (op === 'between') {
        ['from', 'to'].forEach((ph, i) => {
          const inp = document.createElement('input'); inp.type = fd.type === 'date' ? 'date' : 'number'; inp.placeholder = ph;
          if (Array.isArray(cur)) inp.value = cur[i];
          vbox.appendChild(inp);
        });
        return;
      }
      if (op === 'last_days') {
        const inp = document.createElement('input'); inp.type = 'number'; inp.min = 1; inp.max = 3660; inp.placeholder = 'days'; inp.value = cur != null ? cur : 30; vbox.appendChild(inp); return;
      }
      if (fd.options) {
        const sel = document.createElement('select');
        sel.innerHTML = Object.keys(fd.options).map((v) => '<option value="' + esc(v) + '"' + (cur === v ? ' selected' : '') + '>' + esc(fd.options[v]) + '</option>').join('');
        vbox.appendChild(sel); return;
      }
      const inp = document.createElement('input');
      inp.type = fd.type === 'date' ? 'date' : (fd.type === 'int' ? 'number' : 'text');
      inp.value = cur != null ? cur : '';
      if (fd.type === 'string' && fd.group) {
        const listId = 'rc-dl-' + fd.key;
        inp.setAttribute('list', listId);
        if (!document.getElementById(listId)) {
          const dl = document.createElement('datalist'); dl.id = listId; document.body.appendChild(dl);
          api('options', { field: fd.key }).then((d) => { if (d) dl.innerHTML = d.values.map((v) => '<option value="' + esc(v.v) + '">').join(''); }).catch(() => {});
        }
      }
      vbox.appendChild(inp);
    }
    function commit() {
      const fd = fieldByKey[fsel.value];
      if (!fd) return;
      const op = osel.value;
      let value = null;
      const inputs = $$('input,select', vbox).filter((i) => !(i.readOnly && i.dataset && i.dataset.for));
      if (op === 'in' || op === 'not_in') {
        if (fd.options) value = $$('input:checked', vbox).map((i) => i.value);
        else value = (inputs[0].value || '').split(',').map((s) => s.trim()).filter(Boolean);
        if (!value.length) return;
      } else if (op === 'between') {
        value = [inputs[0].value, inputs[1].value];
        if (!value[0] || !value[1]) return;
      } else if (inputs.length) {
        value = inputs[0].value;
        if (value === '' && !['is_empty', 'not_empty', 'is_true', 'is_false', 'this_ec_year', 'last_ec_year'].includes(op)) return;
        if (fd.type === 'int' || op === 'last_days') value = Number(value);
      }
      const nf = { field: fd.key, op: op };
      if (value != null) nf.value = value;
      if (index != null) state.filters[index] = nf; else { state.filters.push(nf); index = state.filters.length - 1; }
      state.starter = null;
      apply();
    }
    fsel.addEventListener('change', () => { f = { field: fsel.value }; ops(); valueInputs(); });
    osel.addEventListener('change', () => { valueInputs(); commit(); });
    vbox.addEventListener('change', commit);
    vbox.addEventListener('keydown', (e) => { if (e.key === 'Enter') commit(); });
    ops(); valueInputs();
    return row;
  }

  function renderChips() {
    const box = $('#rc-chips');
    box.innerHTML = '';
    if (state.search) {
      box.insertAdjacentHTML('beforeend', '<span class="rc-chip"><b>Search</b> ' + esc(state.search) + ' <button type="button" data-search>×</button></span>');
    }
    state.filters.forEach((f, i) => {
      box.insertAdjacentHTML('beforeend', '<span class="rc-chip">' + esc(describeFilter(f)) + ' <button type="button" data-i="' + i + '" aria-label="Remove">×</button></span>');
    });
    if (!state.filters.length && !state.search) {
      box.insertAdjacentHTML('beforeend', '<span class="rc-muted">All members — add filters on the left or pick a starter question.</span>');
    }
    $$('button', box).forEach((b) => b.addEventListener('click', () => {
      if (b.hasAttribute('data-search')) state.search = ''; else removeFilterAt(parseInt(b.dataset.i, 10));
      state.starter = null;
      apply();
    }));
  }

  // ───────────────────────── starters & views ─────────────────────────
  function renderStarters() {
    const row = $('#rc-starters-row');
    row.innerHTML = '';
    const pinned = (views.list || []).filter((v) => v.pinned);
    if (pinned.length) {
      const g = document.createElement('div'); g.className = 'rc-starter-group';
      const lab = document.createElement('span'); lab.className = 'rc-starter-group-label'; lab.textContent = state.lang === 'am' ? 'ቋሚ ሪፖርቶች' : 'Standing reports'; g.appendChild(lab);
      pinned.forEach((v) => {
        const b = document.createElement('button'); b.type = 'button';
        b.className = 'rc-starter is-pinned' + (state.view === v.id ? ' is-on' : '');
        b.textContent = v.name; b.title = v.description || '';
        b.addEventListener('click', () => openView(v));
        g.appendChild(b);
      });
      row.appendChild(g);
    }
    const groupsMeta = schema.starter_groups || {};
    const order = Object.keys(groupsMeta);
    const byGroup = {};
    schema.starters.forEach((s) => { (byGroup[s.group || 'other'] = byGroup[s.group || 'other'] || []).push(s); });
    order.filter((k) => byGroup[k]).concat(Object.keys(byGroup).filter((k) => !order.includes(k))).forEach((gk) => {
      const g = document.createElement('div');
      g.className = 'rc-starter-group';
      const lab = document.createElement('span');
      lab.className = 'rc-starter-group-label';
      lab.textContent = groupsMeta[gk] ? (groupsMeta[gk][state.lang] || groupsMeta[gk].en) : gk;
      g.appendChild(lab);
      byGroup[gk].forEach((s) => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'rc-starter' + (state.starter === s.key ? ' is-on' : '');
        b.textContent = L(s, 'title');
        b.addEventListener('click', () => applyDefinition(s.definition, s.key));
        g.appendChild(b);
      });
      row.appendChild(g);
    });
  }
  function applyDefinition(def, starterKey, viewId) {
    const all = (def.filters || []).map((f) => Object.assign({}, f));
    state.filters = all.filter((f) => f && fieldByKey[f.field]);
    if (state.filters.length !== all.length) toast('Some filters in this view use fields your role cannot see — they were left out.', true);
    state.search = def.search || '';
    if (def.columns && def.columns.length) state.columns = def.columns.filter((k) => fieldByKey[k]);
    state.sort = def.sort ? def.sort.filter((s) => s && fieldByKey[s.field]).map((s) => ({ field: s.field, dir: String(s.dir).toLowerCase() })) : [];
    if (def.group_by && fieldByKey[def.group_by]) state.groupBy = def.group_by;
    if (Array.isArray(def.metrics) && def.metrics.length) state.metrics = def.metrics.filter((k) => metricByKey[k]);
    state.starter = starterKey || null;
    state.view = viewId || null;
    applyingView = !!viewId;
    state.page = 1;
    const wantTab = def.ui && def.ui.tab;
    if (wantTab && ['overview', 'breakdown', 'list'].includes(wantTab)) switchTab(wantTab);
    else if (def.columns && state.tab === 'overview') switchTab('list');
    rebuildTable();
    apply();
    renderViews();
  }
  // ───────────────────────── saved views (server, V2‑3) ─────────────────────────
  let views = { available: false, list: [], canPin: false, migration: '' };
  function currentDefinition() {
    return { dataset: 'members', filters: state.filters, search: state.search, columns: state.columns, sort: state.sort, group_by: state.groupBy, metrics: state.metrics, ui: { tab: state.tab } };
  }
  async function viewsApi(action, params, post) {
    const url = CFG.viewsApi + (post ? '' : '?' + new URLSearchParams(Object.assign({ action: action }, params || {})).toString());
    const init = { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (post) {
      const fd = new FormData();
      fd.append('action', action); fd.append('csrf_token', CFG.csrf);
      Object.keys(params || {}).forEach((k) => fd.append(k, params[k]));
      init.method = 'POST'; init.body = fd;
    }
    const res = await fetch(url, init);
    let body = null; try { body = await res.json(); } catch (e) { body = null; }
    if (res.status === 401) { window.location.href = '/admin/index.php?error=session_expired'; return null; }
    if (!res.ok || !body || body.status !== 'success') throw new Error((body && body.message) || ('Request failed (' + res.status + ')'));
    return body.data;
  }
  async function loadViewsFromServer() {
    try {
      const d = await viewsApi('list');
      if (!d) return;
      views = { available: !!d.available, list: d.views || [], canPin: !!d.can_pin, migration: d.migration || '' };
    } catch (e) {
      views = { available: false, list: [], canPin: false, migration: '' };
    }
    renderViews(); renderStarters();
  }
  function legacyLocalViews() { try { return JSON.parse(localStorage.getItem(LS.views) || '[]'); } catch (e) { return []; } }
  function renderViews() {
    const box = $('#rc-views');
    box.innerHTML = '';
    const saveBtn = $('#rc-save-view');
    if (!views.available) {
      saveBtn.disabled = true;
      saveBtn.title = 'Saved views are not set up yet' + (views.migration ? ' (run ' + views.migration + ')' : '');
      if (CFG.role === 'super_admin' || CFG.role === 'school_admin') {
        box.innerHTML = '<div class="rc-views-h">Saved views</div><div class="rc-muted">Not set up yet — run <code>' + esc(views.migration || 'sql/070_report_definitions.sql') + '</code> in phpMyAdmin.</div>';
      }
      return;
    }
    saveBtn.disabled = false; saveBtn.title = '';
    const groups = [
      { key: 'mine', label: 'My views', items: views.list.filter((v) => v.mine) },
      { key: 'department', label: 'My department', items: views.list.filter((v) => !v.mine && v.scope === 'department') },
      { key: 'shared', label: 'Shared with everyone', items: views.list.filter((v) => !v.mine && v.scope === 'shared') },
    ];
    if (CFG.role === 'super_admin' || CFG.role === 'school_admin') {
      groups.push({ key: 'others', label: 'Other private views (admin)', items: views.list.filter((v) => !v.mine && v.scope === 'private') });
    }
    groups.forEach((g) => {
      if (!g.items.length) return;
      const h = document.createElement('div'); h.className = 'rc-views-h'; h.textContent = g.label; box.appendChild(h);
      g.items.forEach((v) => {
        const el = document.createElement('div'); el.className = 'rc-view' + (state.view === v.id ? ' is-on' : '');
        const open = document.createElement('button'); open.type = 'button';
        open.textContent = v.name;
        open.title = (v.description ? v.description + ' — ' : '') + 'by ' + (v.owner || v.owner_role) + (v.run_count ? ' · opened ' + v.run_count + '×' : '');
        if (v.pinned) { const t = document.createElement('span'); t.className = 'rc-view-tag'; t.textContent = 'pinned'; open.appendChild(t); }
        open.addEventListener('click', () => openView(v));
        el.appendChild(open);
        if (v.can_edit) {
          const ed = document.createElement('button'); ed.type = 'button'; ed.className = 'rc-iconbtn'; ed.title = 'Rename / sharing'; ed.setAttribute('aria-label', 'Edit view');
          ed.innerHTML = '<svg viewBox="0 0 24 24"><path d="M4 20h4l10-10-4-4L4 16v4zM13 7l4 4"/></svg>';
          ed.addEventListener('click', () => openSaveDialog(v));
          el.appendChild(ed);
          const del = document.createElement('button'); del.type = 'button'; del.className = 'rc-iconbtn rc-view-del'; del.title = 'Delete'; del.setAttribute('aria-label', 'Delete view');
          del.innerHTML = '<svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>';
          del.addEventListener('click', () => deleteView(v));
          el.appendChild(del);
        }
        box.appendChild(el);
      });
    });
    // one‑time import of views saved in this browser before V2‑3
    const legacy = legacyLocalViews();
    if (legacy.length) {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'rc-btn rc-btn-ghost rc-btn-sm rc-views-import';
      b.textContent = 'Import ' + legacy.length + ' view' + (legacy.length > 1 ? 's' : '') + ' saved in this browser';
      b.addEventListener('click', importLegacyViews);
      box.appendChild(b);
    }
  }
  function openView(v) {
    applyDefinition(v.definition || {}, null, v.id);
    viewsApi('opened', { id: v.id }, true).catch(() => {});
  }
  async function deleteView(v) {
    // Danger confirmation: MEDIUM when the view is shared, LOW when private.
    if (!(window.DangerConfirm && window.DangerConfirm.ask)) { toast('The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.', true); return; }
    const shared = v.scope !== 'private';
    const ok = await window.DangerConfirm.ask(shared
      ? { tier: 'medium', title: 'Delete the shared view “' + v.name + '”?', object: v.name, summary: 'This saved view is shared with other people.', intent: 'I want to delete this view',
          consequences: ['The view disappears for EVERYONE who can see it.', 'Links that point to this view stop opening it.'], keeps: ['No member or attendance data is changed.'],
          irreversible: true, phrase: 'DELETE VIEW', confirmLabel: 'Delete view' }
      : { tier: 'low', title: 'Delete your view “' + v.name + '”?', summary: 'Only your saved filters are removed. No data is changed.', confirmLabel: 'Delete view' });
    if (!ok) return;
    try {
      await viewsApi('delete', { id: v.id }, true);
      if (state.view === v.id) { state.view = null; writeState(); }
      toast('View deleted');
      loadViewsFromServer();
    } catch (e) { toast(e.message, true); }
  }
  async function importLegacyViews() {
    const legacy = legacyLocalViews();
    let n = 0;
    for (const lv of legacy) {
      try {
        await viewsApi('save', { name: String(lv.name || 'Imported view').slice(0, 120), scope: 'private', definition: JSON.stringify(Object.assign({ dataset: 'members' }, lv.def || {})) }, true);
        n++;
      } catch (e) { /* keep going */ }
    }
    localStorage.removeItem(LS.views);
    toast(n + ' view' + (n === 1 ? '' : 's') + ' imported as private');
    loadViewsFromServer();
  }
  function openSaveDialog(existing) {
    const modal = $('#rc-saveview');
    const form = $('#rc-saveview-form');
    form.reset();
    form.elements.id.value = existing ? existing.id : '';
    form.elements.name.value = existing ? existing.name : '';
    form.elements.description.value = existing ? (existing.description || '') : '';
    const scope = existing ? existing.scope : 'private';
    $$('input[name=scope]', form).forEach((r) => { r.checked = r.value === scope; });
    if (form.elements.pinned) form.elements.pinned.checked = !!(existing && existing.pinned);
    $('#rc-saveview-title').textContent = existing ? 'Edit view' : 'Save view';
    $('#rc-saveview-submit').textContent = existing ? 'Save changes' : 'Save';
    const chips = state.filters.length + (state.search ? 1 : 0);
    $('#rc-saveview-summary').textContent = existing
      ? 'The population and columns are kept as saved; use “Save view” from the panel to store a new population under a new name.'
      : (chips ? chips + ' filter' + (chips > 1 ? 's' : '') : 'No filters (all members)') + ' · ' + state.columns.length + ' columns · opens on the ' + state.tab + ' tab.';
    form.dataset.mode = existing ? 'edit' : 'new';
    modal.hidden = false;
    form.elements.name.focus();
  }
  async function submitSaveDialog(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const existing = form.dataset.mode === 'edit' ? views.list.find((v) => String(v.id) === form.elements.id.value) : null;
    const params = {
      id: form.elements.id.value || '',
      name: form.elements.name.value.trim(),
      description: form.elements.description.value.trim(),
      scope: ($$('input[name=scope]:checked', form)[0] || {}).value || 'private',
      definition: JSON.stringify(existing ? existing.definition : currentDefinition()),
    };
    if (form.elements.pinned) { params.pinned = form.elements.pinned.checked ? '1' : '0'; if (params.pinned === '1') params.scope = 'shared'; }
    if (!params.name) { form.elements.name.focus(); return; }
    $('#rc-saveview-submit').disabled = true;
    try {
      const d = await viewsApi('save', params, true);
      $('#rc-saveview').hidden = true;
      toast(existing ? 'View updated' : 'View saved' + (params.scope === 'private' ? '' : ' and shared'));
      if (d && d.view && !existing) { state.view = d.view.id; writeState(); }
      loadViewsFromServer();
    } catch (err) { toast(err.message, true); }
    $('#rc-saveview-submit').disabled = false;
  }

  // ───────────────────────── KPIs ─────────────────────────
  async function loadKpis() {
    const box = $('#rc-kpis');
    box.classList.add('rc-loading');
    try {
      const d = await api('kpis', { q: JSON.stringify(definition({ metrics: KPI_METRICS })) }, 'kpis');
      if (!d) return;
      const total = Number((d.metrics.find((m) => m.key === 'count') || {}).value || 0);
      box.innerHTML = d.metrics.map((m) => {
        const v = Number(m.value || 0);
        const pct = (m.key !== 'count' && total) ? Math.round(1000 * v / total) / 10 + '%' : '';
        return '<div class="rc-kpi" data-k="' + esc(m.key) + '" title="' + esc(m.definition || '') + '">'
          + '<div class="rc-kpi-label">' + esc(L(m, 'label')) + ' <span class="rc-info">i</span></div>'
          + '<div class="rc-kpi-val">' + fmtNum(m.value) + '</div>'
          + (pct ? '<div class="rc-kpi-sub">' + pct + ' of population</div>' : '<div class="rc-kpi-sub">in current population</div>')
          + '</div>';
      }).join('');
      $$('.rc-kpi', box).forEach((el) => el.addEventListener('click', () => {
        const drill = KPI_DRILL[el.dataset.k];
        if (!drill) { switchTab('list'); return; }
        addFilter(Object.assign({}, drill));
        state.starter = null;
        switchTab('list');
        apply();
      }));
    } catch (e) { toast(e.message, true); }
    finally { box.classList.remove('rc-loading'); }
  }

  // ───────────────────────── charts ─────────────────────────
  function chartCard(id, title) {
    return '<div class="rc-card"><div class="rc-card-hd"><span>' + esc(title) + '</span><span class="rc-muted">click to filter</span></div><div class="rc-chart" id="' + id + '"></div></div>';
  }
  function ensureChart(id) {
    const el = document.getElementById(id);
    if (!el) return null;
    if (!charts[id]) charts[id] = echarts.init(el, null, { renderer: 'canvas' });
    return charts[id];
  }
  function bucketFilter(field, bucket) {
    const fd = fieldByKey[field];
    if (fd.type === 'bool') return { field: field, op: bucket == 1 ? 'is_true' : 'is_false' };
    if (bucket == null || bucket === '') return { field: field, op: 'is_empty' };
    return { field: field, op: 'eq', value: fd.type === 'int' ? Number(bucket) : String(bucket) };
  }
  function drawChart(id, spec, rows) {
    const ch = ensureChart(id);
    if (!ch) return;
    let data = rows.map((r) => ({ name: r.label, value: Number(r.count || 0), bucket: r.bucket }));
    if (spec.sortByBucket) data.sort((a, b) => (Number(a.bucket) || 0) - (Number(b.bucket) || 0));
    if (spec.top && data.length > spec.top) {
      const rest = data.slice(spec.top).reduce((s, x) => s + x.value, 0);
      data = data.slice(0, spec.top);
      if (rest) data.push({ name: 'Other', value: rest, bucket: '__other__' });
    }
    const base = { color: PALETTE, tooltip: { trigger: 'item' }, animationDuration: 300, textStyle: { fontFamily: 'inherit' } };
    let opt;
    if (spec.kind === 'pie') {
      opt = Object.assign(base, {
        legend: { bottom: 0, type: 'scroll', textStyle: { fontSize: 11 } },
        series: [{ type: 'pie', radius: ['42%', '68%'], center: ['50%', '45%'], data: data, label: { formatter: (p) => p.percent.toFixed(0) + '%', fontSize: 11, overflow: 'none' }, labelLine: { length: 8, length2: 6 }, emphasis: { scale: true } }],
      });
    } else {
      const horiz = spec.kind === 'barh';
      opt = Object.assign(base, {
        grid: { left: horiz ? 110 : 40, right: 16, top: 16, bottom: horiz ? 24 : 40, containLabel: !horiz },
        xAxis: horiz ? { type: 'value' } : { type: 'category', data: data.map((d) => d.name), axisLabel: { interval: 0, rotate: data.length > 6 ? 30 : 0, fontSize: 11 } },
        yAxis: horiz ? { type: 'category', data: data.map((d) => d.name).reverse(), axisLabel: { fontSize: 11, width: 100, overflow: 'truncate' } } : { type: 'value' },
        series: [{ type: 'bar', data: horiz ? data.map((d) => d.value).reverse() : data.map((d) => d.value), itemStyle: { color: PALETTE[0], borderRadius: 4 }, label: { show: true, position: horiz ? 'right' : 'top', fontSize: 11 } }],
      });
      if (horiz) opt._rev = true;
    }
    ch.setOption(opt, true);
    ch.off('click');
    ch.on('click', (p) => {
      let item;
      if (spec.kind === 'pie') item = data[p.dataIndex];
      else if (spec.kind === 'barh') item = data.slice().reverse()[p.dataIndex];
      else item = data[p.dataIndex];
      if (!item || item.bucket === '__other__') return;
      addFilter(bucketFilter(spec.field, item.bucket));
      state.starter = null;
      apply();
    });
  }
  async function loadOverview() {
    const grid = $('#rc-charts');
    const specs = OVERVIEW_CHARTS.filter((s) => fieldByKey[s.field] && fieldByKey[s.field].group);
    if (!grid.children.length) grid.innerHTML = specs.map((s) => chartCard('ch-' + s.field, fieldLabel(s.field))).join('');
    else specs.forEach((s) => { const hd = $('#ch-' + s.field).previousElementSibling.firstElementChild; if (hd) hd.textContent = fieldLabel(s.field); });
    grid.classList.add('rc-loading');
    try {
      await Promise.all(specs.map(async (s) => {
        const d = await api('aggregate', { q: JSON.stringify(definition({ group_by: s.field, metrics: ['count'] })) }, 'ov-' + s.field);
        if (!d) return;
        const el = document.getElementById('ch-' + s.field);
        if (!d.rows.length) { el.innerHTML = '<div class="rc-card-empty">No data for this population</div>'; if (charts[el.id]) { charts[el.id].dispose(); delete charts[el.id]; } return; }
        if (el.firstElementChild && el.firstElementChild.classList.contains('rc-card-empty')) el.innerHTML = '';
        drawChart(el.id, s, d.rows);
      }));
    } catch (e) { toast(e.message, true); }
    finally { grid.classList.remove('rc-loading'); }
  }

  // ───────────────────────── breakdown ─────────────────────────
  function renderBreakdownControls() {
    const g = $('#rc-groupby');
    g.innerHTML = schema.fields.filter((f) => f.group && !f.hidden).map((f) => '<option value="' + esc(f.key) + '"' + (f.key === state.groupBy ? ' selected' : '') + '>' + esc(L(f, 'label')) + '</option>').join('');
    const m = $('#rc-metrics');
    m.innerHTML = schema.metrics.map((x) => '<option value="' + esc(x.key) + '"' + (state.metrics.includes(x.key) ? ' selected' : '') + '>' + esc(L(x, 'label')) + '</option>').join('');
    m.size = Math.min(6, schema.metrics.length);
  }
  async function loadBreakdown() {
    const pane = $('#pane-breakdown');
    pane.classList.add('rc-loading');
    try {
      const metrics = state.metrics.length ? state.metrics : ['count'];
      const d = await api('aggregate', { q: JSON.stringify(definition({ group_by: state.groupBy, metrics: metrics })) }, 'bd');
      if (!d) return;
      $('#rc-breakdown-note').textContent = d.capped ? 'Showing the top ' + d.rows.length + ' groups' : d.rows.length + ' groups';
      const cols = [{ title: L(d.group_by, 'label'), field: 'label', widthGrow: 2 }].concat(metrics.map((k) => ({ title: metricLabel(k), field: k, hozAlign: 'right', sorter: 'number', formatter: (c) => fmtNum(c.getValue()) })));
      if (!breakdownTable) {
        breakdownTable = new Tabulator('#rc-breakdown-table', { data: d.rows, columns: cols, layout: 'fitColumns', height: '320px', placeholder: 'No data' });
        breakdownTable.on('rowClick', (e, row) => { const r = row.getData(); addFilter(bucketFilter(state.groupBy, r.bucket)); state.starter = null; switchTab('list'); apply(); });
      } else { breakdownTable.setColumns(cols); breakdownTable.replaceData(d.rows); }
      drawChart('rc-breakdown-chart', { field: state.groupBy, kind: d.rows.length > 8 ? 'barh' : 'bar', top: 20 }, d.rows.map((r) => Object.assign({}, r, { count: r[metrics[0]] })));
    } catch (e) { toast(e.message, true); }
    finally { pane.classList.remove('rc-loading'); }
  }

  // ───────────────────────── list (Tabulator) ─────────────────────────
  function cellFormatter(fd) {
    return function (cell) {
      const v = cell.getValue();
      if (v == null || v === '') return '<span class="rc-muted">—</span>';
      if (fd.type === 'bool') return '<span class="rc-bool ' + (Number(v) === 1 ? 'rc-bool-y' : 'rc-bool-n') + '"></span>' + (Number(v) === 1 ? 'Yes' : 'No');
      if (fd.type === 'date') return esc(fmtDate(v));
      if (fd.key === 'status' || fd.key === 'membership_tier') return '<span class="rc-tag rc-tag-' + esc(v) + '">' + esc(fd.options && fd.options[v] || v) + '</span>';
      if (fd.options && fd.options[v] != null) return esc(fd.options[v]);
      return esc(v);
    };
  }
  function tableColumns() {
    return state.columns.filter((k) => fieldByKey[k] && !fieldByKey[k].hidden).map((k) => {
      const fd = fieldByKey[k];
      return { title: L(fd, 'label'), field: k, formatter: cellFormatter(fd), headerSort: !!fd.sort, minWidth: 90, hozAlign: fd.type === 'int' ? 'right' : 'left', cssClass: /_am$/.test(k) ? 'amh' : '' };
    });
  }
  function rebuildTable() {
    if (table) { table.destroy(); table = null; }
    table = new Tabulator('#rc-table', {
      layout: 'fitDataFill',
      height: '62vh',
      columns: tableColumns(),
      placeholder: 'No members match this population.',
      pagination: true,
      paginationMode: 'remote',
      paginationSize: state.pageSize,
      paginationSizeSelector: false,
      paginationCounter: 'rows',
      sortMode: 'remote',
      ajaxURL: CFG.api,
      ajaxConfig: { method: 'GET', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } },
      ajaxURLGenerator: function (url, config, params) {
        const sort = (params.sort || []).map((s) => ({ field: s.field, dir: s.dir }));
        if (sort.length) state.sort = sort;
        const q = definition({ columns: state.columns.concat(['id']), sort: state.sort, page: params.page || 1, page_size: params.size || state.pageSize });
        const qs = new URLSearchParams({ action: 'list', lang: state.lang, q: JSON.stringify(q) });
        writeState();
        return url + '?' + qs.toString();
      },
      ajaxResponse: function (url, params, response) {
        if (!response || response.status !== 'success') {
          toast((response && response.message) || 'Could not load the list', true);
          return { last_page: 1, data: [] };
        }
        const d = response.data;
        const shown = Math.min(d.total, d.max_rows);
        $('#rc-list-count').textContent = fmtNum(d.total) + ' members' + (d.truncated ? ' · showing the first ' + fmtNum(shown) + ' — narrow the filters to see all' : '');
        if (response.generated_at) $('#rc-asof').textContent = 'Data as of ' + new Date(response.generated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return { last_page: Math.max(1, Math.ceil(shown / (params.size || state.pageSize))), data: d.rows };
      },
      ajaxError: function (xhr) {
        if (xhr && xhr.status === 401) window.location.href = '/admin/index.php?error=session_expired';
        else toast('Could not load the list', true);
      },
      initialSort: state.sort.map((s) => ({ column: s.field, dir: s.dir })).filter((s) => state.columns.includes(s.column)),
    });
    table.on('rowClick', (e, row) => {
      const d = row.getData();
      if (d.id) window.open(CFG.memberUrl + encodeURIComponent(d.id), '_blank', 'noopener');
    });
  }
  function loadList() { dirty.list = false; if (table) table.setPage(1).catch(() => {}); }

  function renderColumnPicker() {
    const pop = $('#rc-columns-pop');
    const fields = schema.fields.filter((f) => f.list && !f.hidden);
    pop.innerHTML = '<div class="rc-columns-head"><span>' + state.columns.length + ' selected</span><span><button type="button" class="rc-link" data-def>Defaults</button> · <button type="button" class="rc-link" data-all>All</button></span></div>'
      + groupByCategory(fields).map((g) => '<div class="rc-columns-cat">' + esc(g.label) + '</div>' + g.fields.map((f) => '<label><input type="checkbox" value="' + esc(f.key) + '"' + (state.columns.includes(f.key) ? ' checked' : '') + '> ' + esc(L(f, 'label')) + (f.pii ? '<span class="rc-pii">PII</span>' : '') + (!f.export ? '<span class="rc-pii">view only</span>' : '') + '</label>').join('')).join('');
    const commit = () => {
      const picked = $$('input:checked', pop).map((i) => i.value);
      // keep existing order for already‑selected, append new ones in catalogue order
      state.columns = state.columns.filter((k) => picked.includes(k)).concat(picked.filter((k) => !state.columns.includes(k)));
      if (!state.columns.length) state.columns = schema.default_columns.slice();
      localStorage.setItem(LS.columns, JSON.stringify(state.columns));
      renderColumnPicker();
      rebuildTable();
      writeState();
    };
    $$('input', pop).forEach((i) => i.addEventListener('change', commit));
    $('[data-def]', pop).addEventListener('click', () => { state.columns = schema.default_columns.slice(); localStorage.setItem(LS.columns, JSON.stringify(state.columns)); renderColumnPicker(); rebuildTable(); });
    $('[data-all]', pop).addEventListener('click', () => { state.columns = fields.map((f) => f.key); localStorage.setItem(LS.columns, JSON.stringify(state.columns)); renderColumnPicker(); rebuildTable(); });
  }

  // ───────────────────────── export ─────────────────────────
  function doExport(format) {
    const cols = state.columns.filter((k) => fieldByKey[k] && fieldByKey[k].export);
    if (!cols.length) { toast('None of the selected columns may be exported by your role.', true); return; }
    const title = state.starter ? state.starter : 'members';
    const q = definition({ columns: cols, sort: state.sort });
    const qs = new URLSearchParams({ action: 'export', format: format, lang: state.lang, title: title, q: JSON.stringify(q) });
    window.location.href = CFG.api + '?' + qs.toString();
    toast('Preparing ' + format.toUpperCase() + ' — up to 5 000 rows. This export is recorded.');
  }

  // ───────────────────────── glossary ─────────────────────────
  function renderGlossary() {
    const body = $('#rc-glossary-body');
    let html = '<div class="rc-def-h">Measures</div>';
    html += schema.metrics.map((m) => '<div class="rc-def"><b>' + esc(L(m, 'label')) + '</b><span>' + esc(m.definition || '') + '</span></div>').join('');
    html += '<div class="rc-def-h">Derived fields</div>';
    html += schema.fields.filter((f) => f.definition).map((f) => '<div class="rc-def"><b>' + esc(L(f, 'label')) + '</b><span>' + esc(f.definition) + '</span></div>').join('');
    html += '<div class="rc-def-h">Limits & privacy</div><div class="rc-def"><span>Lists show up to ' + fmtNum(schema.limits.list_rows) + ' rows (' + schema.limits.page_size + ' per page); files contain up to ' + fmtNum(schema.limits.export_rows) + ' rows. Fields marked PII are personal data — every list query and export is recorded in the audit log with who, when and which filters (never the values).</span></div>';
    body.innerHTML = html;
  }

  // ───────────────────────── export log (admins) ─────────────────────────
  let exportLogTable = null;
  function openExportLog() {
    const modal = $('#rc-exportlog');
    modal.hidden = false;
    api('export_history').then((d) => {
      if (!d) return;
      const rows = d.rows || [];
      $('#rc-exportlog-note').textContent = rows.length + ' export events (newest first). Filters are recorded as field:operator — values are never stored.';
      const cols = [
        { title: 'When', field: 'at', width: 150 },
        { title: 'User', field: 'username', width: 120 },
        { title: 'Role', field: 'role', width: 110 },
        { title: 'Event', field: 'action', width: 190 },
        { title: 'Format', field: 'format', width: 70, hozAlign: 'center' },
        { title: 'Title', field: 'title', width: 150 },
        { title: 'Filters', field: 'filters', formatter: (c) => { const v = c.getValue() || []; const w = document.createElement('span'); w.className = 'rc-chip-list'; v.forEach((x) => { const e = document.createElement('span'); e.textContent = x; w.appendChild(e); }); return w; } },
        { title: 'Cols', field: 'columns', width: 60, hozAlign: 'right' },
        { title: 'PII fields', field: 'pii_fields', formatter: (c) => { const v = c.getValue() || []; const w = document.createElement('span'); w.className = 'rc-chip-list'; v.forEach((x) => { const e = document.createElement('span'); e.textContent = x; w.appendChild(e); }); return w; } },
        { title: 'IP', field: 'ip', width: 120 },
      ];
      if (exportLogTable) { exportLogTable.setColumns(cols); exportLogTable.setData(rows); return; }
      exportLogTable = new Tabulator('#rc-exportlog-table', { data: rows, columns: cols, layout: 'fitColumns', height: '60vh', placeholder: 'No exports recorded yet' });
    }).catch(() => {});
  }

  // ───────────────────────── orchestration ─────────────────────────
  function switchTab(tab) {
    state.tab = tab;
    $$('.rc-tabs [data-tab]').forEach((b) => b.classList.toggle('is-on', b.dataset.tab === tab));
    $$('.rc-tabpane').forEach((p) => p.classList.toggle('is-on', p.id === 'pane-' + tab));
    writeState();
    if (tab === 'overview') Object.values(charts).forEach((c) => c.resize());
    if (tab === 'breakdown' && charts['rc-breakdown-chart']) charts['rc-breakdown-chart'].resize();
    if (tab === 'list' && table) table.redraw(true);
    if (dirty[tab]) refreshActiveTab();
  }
  // Only the visible tab talks to the server; the others reload when opened.
  function refreshActiveTab() {
    dirty[state.tab] = false;
    if (state.tab === 'overview') loadOverview();
    else if (state.tab === 'breakdown') loadBreakdown();
    else loadList();
  }
  function apply() {
    clearTimeout(applyTimer);
    applyTimer = setTimeout(() => {
      if (state.view && !applyingView) { state.view = null; renderViews(); renderStarters(); }
      applyingView = false;
      state.page = 1;
      renderQuick(); renderRules(); renderChips(); renderStarters();
      writeState();
      loadKpis();
      dirty.overview = dirty.breakdown = dirty.list = true;
      refreshActiveTab();
    }, 200);
  }

  function bindStatic() {
    $('#rc-search').addEventListener('input', (e) => { state.search = e.target.value.trim(); state.starter = null; apply(); });
    $('#rc-period').addEventListener('change', (e) => { setPeriod(e.target.value); state.starter = null; apply(); });
    $('#rc-add-rule').addEventListener('click', () => { $('#rc-rules').appendChild(ruleRow({}, null)); });
    $('#rc-clear').addEventListener('click', () => { state.filters = []; state.search = ''; state.starter = null; apply(); });
    $('#rc-save-view').addEventListener('click', () => openSaveDialog(null));
    $('#rc-saveview-form').addEventListener('submit', submitSaveDialog);
    $$('#rc-saveview [data-close]').forEach((b) => b.addEventListener('click', () => { $('#rc-saveview').hidden = true; }));
    $('#rc-saveview').addEventListener('click', (e) => { if (e.target.id === 'rc-saveview') e.currentTarget.hidden = true; });
    $('#rc-copy-link').addEventListener('click', () => {
      const url = window.location.origin + window.location.pathname + encodeState();
      (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(() => toast('Link copied — anyone with reporting access opens the same population.')).catch(() => {
        // Fallback without a browser prompt(): copy through a hidden textarea.
        const ta = document.createElement('textarea'); ta.value = url; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
        document.body.appendChild(ta); ta.select(); let ok = false; try { ok = document.execCommand('copy'); } catch (e) { ok = false; } ta.remove();
        toast(ok ? 'Link copied — anyone with reporting access opens the same population.' : 'Could not copy automatically. Copy the address from the browser bar.', !ok);
      });
    });
    $$('.rc-tabs [data-tab]').forEach((b) => b.addEventListener('click', () => { switchTab(b.dataset.tab); }));
    $$('.rc-seg [data-lang]').forEach((b) => b.addEventListener('click', () => {
      state.lang = b.dataset.lang; localStorage.setItem(LS.lang, state.lang);
      $$('.rc-seg [data-lang]').forEach((x) => x.classList.toggle('is-on', x === b));
      applyingView = !!state.view; // language change does not alter the population
      renderBreakdownControls(); renderColumnPicker(); renderGlossary(); rebuildTable(); apply();
    }));
    // menus
    const toggleMenu = (btn, pop) => { btn.addEventListener('click', (e) => { e.stopPropagation(); const open = !pop.hidden; $$('.rc-menu-pop').forEach((p) => { p.hidden = true; }); pop.hidden = open; }); pop.addEventListener('click', (e) => e.stopPropagation()); };
    toggleMenu($('#rc-export-btn'), $('#rc-export-menu'));
    toggleMenu($('#rc-columns-btn'), $('#rc-columns-pop'));
    document.addEventListener('click', () => { $$('.rc-menu-pop').forEach((p) => { p.hidden = true; }); });
    $$('#rc-export-menu [data-format]').forEach((b) => b.addEventListener('click', () => { $('#rc-export-menu').hidden = true; doExport(b.dataset.format); }));
    $('#rc-pagesize').addEventListener('change', (e) => { state.pageSize = parseInt(e.target.value, 10); localStorage.setItem(LS.pageSize, String(state.pageSize)); rebuildTable(); });
    $('#rc-groupby').addEventListener('change', (e) => { state.groupBy = e.target.value; writeState(); loadBreakdown(); });
    $('#rc-metrics').addEventListener('change', (e) => { state.metrics = Array.from(e.target.selectedOptions).map((o) => o.value); writeState(); loadBreakdown(); });
    // glossary
    $('#rc-glossary-btn').addEventListener('click', () => { $('#rc-glossary').hidden = false; });
    $$('#rc-glossary [data-close]').forEach((b) => b.addEventListener('click', () => { $('#rc-glossary').hidden = true; }));
    $('#rc-glossary').addEventListener('click', (e) => { if (e.target.id === 'rc-glossary') e.currentTarget.hidden = true; });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { $('#rc-glossary').hidden = true; $('#rc-saveview').hidden = true; const xl = $('#rc-exportlog'); if (xl) xl.hidden = true; $('#rc-rail').classList.remove('is-open'); } });
    // export log (button only rendered for school_admin / super_admin)
    const xlBtn = $('#rc-exportlog-btn');
    if (xlBtn) {
      xlBtn.addEventListener('click', openExportLog);
      $$('#rc-exportlog [data-close]').forEach((b) => b.addEventListener('click', () => { $('#rc-exportlog').hidden = true; }));
      $('#rc-exportlog').addEventListener('click', (e) => { if (e.target.id === 'rc-exportlog') e.currentTarget.hidden = true; });
    }
    // mobile rail
    $('#rc-rail-toggle').addEventListener('click', () => $('#rc-rail').classList.add('is-open'));
    $('#rc-rail-close').addEventListener('click', () => $('#rc-rail').classList.remove('is-open'));
    window.addEventListener('resize', () => { Object.values(charts).forEach((c) => c.resize()); });
    window.addEventListener('hashchange', () => { if (readState()) { rebuildTable(); renderBreakdownControls(); switchTab(state.tab); apply(); } });
  }

  async function boot() {
    $$('.rc-seg [data-lang]').forEach((x) => x.classList.toggle('is-on', x.dataset.lang === state.lang));
    try {
      schema = await api('schema');
    } catch (e) {
      $('#rc-kpis').innerHTML = '<div class="rc-card" style="padding:16px">' + esc(e.message) + '</div>';
      return;
    }
    schema.fields.forEach((f) => { fieldByKey[f.key] = f; });
    schema.metrics.forEach((m) => { metricByKey[m.key] = m; });
    const savedCols = (() => { try { return JSON.parse(localStorage.getItem(LS.columns) || 'null'); } catch (e) { return null; } })();
    state.columns = (Array.isArray(savedCols) && savedCols.length ? savedCols : schema.default_columns).filter((k) => fieldByKey[k]);
    readState();
    if (!state.columns.length) state.columns = schema.default_columns.slice();
    if (!schema.xlsx_available) { $('#rc-export-xlsx').disabled = true; $('#rc-export-note').textContent = 'Excel is unavailable on this server — CSV opens in Excel.'; }
    else $('#rc-export-note').textContent = 'Current population and visible columns. Up to 5 000 rows. Recorded in the audit log.';
    $('#rc-pagesize').value = String(state.pageSize);
    bindStatic();
    renderBreakdownControls();
    renderColumnPicker();
    renderGlossary();
    renderViews();
    rebuildTable();
    dirty.list = false; // rebuildTable already fetched page 1
    $$('.rc-tabs [data-tab]').forEach((b) => b.classList.toggle('is-on', b.dataset.tab === state.tab));
    $$('.rc-tabpane').forEach((p) => p.classList.toggle('is-on', p.id === 'pane-' + state.tab));
    applyingView = !!state.view; // a #q=…&v=id link keeps its view marker
    apply();
    loadViewsFromServer();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
