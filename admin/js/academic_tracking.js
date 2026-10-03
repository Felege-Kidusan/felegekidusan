/**
 * ============================================================================
 * Academic Tracking — root navigation layer (Phase 1)
 * ============================================================================
 * Academic Tracking is a tracking system, not a dashboard. It starts with a
 * question -- what do you want to track? -- and four answers:
 *
 *     Students      Teachers      Subjects      Classes
 *
 * and then follows one path for all of them:
 *
 *     LIST  ->  SELECT ENTITY  ->  (Phase 2: scoped tracking)
 *
 * This file implements the first two steps only. Selecting an entity sets a
 * scope and stops at a clearly marked boundary; the detail screens are Phase
 * 2 and are deliberately absent rather than faked with placeholder numbers.
 *
 * THREE THINGS ARE KEPT SEPARATE, because conflating them is what made the
 * first attempt lie to the user:
 *
 *   context   the reporting period (academic year). Not a filter. Not a
 *             choice about which entity is under investigation.
 *   scope     the entity the user explicitly selected. Never auto-selected,
 *             never counted as a filter.
 *   filters   search / class / status / assignment. ONLY these are reported
 *             as active, and the screen only offers to clear filters when at
 *             least one is genuinely applied.
 *
 * NO ACADEMIC CALCULATION HAPPENS HERE. These lists exist to find and choose
 * an entity. They show identity and relationship counts -- a class's student
 * count, a teacher's assignment count, a subject's class count -- all of
 * which are plain relational counts from the list endpoints. No average, no
 * grade letter, no pass rate, no rank. ReportCardService remains the only
 * thing that calculates academic results, and Phase 1 never calls it.
 *
 * NO NEW ENDPOINTS. Students come from the existing school-wide roster,
 * teachers from list_teachers, classes from get_classes, subjects from
 * get_subjects. All four paginate, search and sort on the server.
 *
 * Dependency-free: no Chart.js, no framework. Every DOM handler delegates to
 * a plain method (openList, setFilter, setPage, selectEntity, ...) so the
 * behaviour can be driven and asserted without a browser.
 */
(function (global) {
  'use strict';

  var PALETTE = {
    brand: '#600000',
    primary: '#7c3aed',
    ok: '#059669',
    warn: '#f59e0b',
    bad: '#dc2626',
    info: '#2563eb',
    muted: '#94a3b8'
  };

  function esc(value) {
    if (value === null || value === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(value);
    return d.innerHTML;
  }

  function intOr(value, fallback) {
    var n = parseInt(value, 10);
    return isNaN(n) ? fallback : n;
  }

  /** "1,234" — counts are read far more often than they are computed. */
  function group(n) {
    var s = String(intOr(n, 0));
    return s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /** Pluralise a noun for a count: 1 student, 0 students, 245 students. */
  function plural(n, one, many) {
    return intOr(n, 0) === 1 ? one : many;
  }

  function statusChip(active, labels) {
    var on = String(active) === '1' || active === true || active === 1;
    var txt = on ? (labels && labels[0] ? labels[0] : 'Active')
                 : (labels && labels[1] ? labels[1] : 'Inactive');
    return '<span class="ch ' + (on ? 'ch-ok' : 'ch-d') + '">' + esc(txt) + '</span>';
  }

  function countChip(n, tone) {
    var cls = intOr(n, 0) > 0 ? (tone || 'ch-i') : 'ch-d';
    return '<span class="ch ' + cls + '">' + esc(group(n)) + '</span>';
  }

  // ── entity descriptors ────────────────────────────────────────────────────
  //
  // Each of the four root lists differs only in its endpoint, its filters and
  // its columns. Describing them as data rather than four hand-written
  // screens is what keeps "every list behaves the same way" true by
  // construction: paging, counting, race-safety, the empty states and the
  // selection rule are written once below.
  //
  // `filters` lists ONLY things a user applies. The academic year is context
  // and lives outside this structure; the selected entity is scope and never
  // appears here at all.

  var ENTITIES = {
    students: {
      key: 'students',
      label: 'Students',
      singular: 'student',
      pluralWord: 'students',
      amharic: 'ተማሪዎች',
      icon: 'fa-user-graduate',
      blurb: 'Find a student and track their subjects, results and attendance.',
      url: '/admin/api_education.php?action=roster',
      rowsKey: 'rows',
      idField: 'id',
      nameOf: function (r) {
        return [r.student_name, r.father_name].filter(Boolean).join(' ') || ('#' + r.id);
      },
      // `class` here narrows the list of students; it is a filter the user
      // applied, not the entity under investigation. Reporting it as active
      // is correct.
      filters: [
        { key: 'q', type: 'search', label: 'Search students', placeholder: 'Name, father, code…' },
        { key: 'class_id', type: 'select', label: 'Class', any: 'All classes', source: 'classes' },
        {
          key: 'status', type: 'select', label: 'Status', any: 'Any status',
          options: [['active', 'Active'], ['inactive', 'Inactive'], ['graduated', 'Graduated']]
        }
      ],
      sorts: [['name', 'Name'], ['code', 'Member code'], ['class', 'Class']],
      defaultSort: 'name',
      columns: [
        {
          head: 'Student',
          cell: function (r, self) {
            return '<div style="display:flex;flex-direction:column">'
              + '<span class="amharic" style="font-weight:700;color:#1e293b">' + esc(self.nameOf(r)) + '</span>'
              + '<span style="font-size:.68rem;color:#94a3b8">' + esc(r.member_code || '—') + '</span>'
              + '</div>';
          }
        },
        {
          head: 'Class',
          cell: function (r) {
            if (!r.class_name) return '<span style="color:#94a3b8">Not enrolled</span>';
            return '<span class="amharic">' + esc(r.class_name) + '</span>';
          }
        },
        {
          head: 'Status',
          cell: function (r) {
            var s = String(r.status || '').toLowerCase();
            var cls = s === 'active' ? 'ch-ok' : (s === 'archived' ? 'ch-d' : 'ch-w');
            return '<span class="ch ' + cls + '">' + esc(r.status || '—') + '</span>';
          }
        }
      ]
    },

    teachers: {
      key: 'teachers',
      label: 'Teachers',
      singular: 'teacher',
      pluralWord: 'teachers',
      amharic: 'መምህራን',
      icon: 'fa-chalkboard-user',
      blurb: 'Find a teacher and track the classes, subjects and assessments they own.',
      url: '/admin/api_education.php?action=list_teachers',
      rowsKey: 'teachers',
      idField: 'id',
      nameOf: function (r) { return r.full_name || r.username || ('#' + r.id); },
      filters: [
        { key: 'q', type: 'search', label: 'Search teachers', placeholder: 'Name, username, email…' },
        {
          key: 'assigned', type: 'select', label: 'Assignment', any: 'All teachers',
          options: [['1', 'Has assignments'], ['0', 'No assignments']]
        }
      ],
      sorts: [],
      defaultSort: '',
      columns: [
        {
          head: 'Teacher',
          cell: function (r, self) {
            return '<div style="display:flex;flex-direction:column">'
              + '<span style="font-weight:700;color:#1e293b">' + esc(self.nameOf(r)) + '</span>'
              + '<span style="font-size:.68rem;color:#94a3b8">' + esc(r.username || '—') + '</span>'
              + '</div>';
          }
        },
        {
          head: 'Member record',
          // A login without a members row is legal, so say so plainly rather
          // than rendering an empty cell that looks like a loading failure.
          cell: function (r) {
            return r.member_code
              ? '<code style="font-size:.7rem;background:#f1f5f9;padding:2px 6px;border-radius:4px">' + esc(r.member_code) + '</code>'
              : '<span style="color:#94a3b8;font-size:.72rem">Not linked</span>';
          }
        },
        { head: 'Classes', cell: function (r) { return countChip(r.assigned_classes, 'ch-p'); } },
        { head: 'Status', cell: function (r) { return statusChip(r.is_active); } }
      ]
    },

    subjects: {
      key: 'subjects',
      label: 'Subjects',
      singular: 'subject',
      pluralWord: 'subjects',
      amharic: 'ትምህርቶች',
      icon: 'fa-book-open',
      blurb: 'Find a subject and track how it is performing across the classes that offer it.',
      url: '/admin/api_subjects.php?action=get_subjects',
      rowsKey: 'subjects',
      idField: 'id',
      nameOf: function (r) { return r.subject_name || r.subject_name_en || ('#' + r.id); },
      filters: [
        { key: 'q', type: 'search', label: 'Search subjects', placeholder: 'Name or code…' },
        {
          key: 'status', type: 'select', label: 'Status', any: 'Any status',
          options: [['active', 'Active'], ['inactive', 'Inactive']]
        }
      ],
      sorts: [['name', 'Name'], ['code', 'Code'], ['classes', 'Classes offering']],
      defaultSort: 'name',
      columns: [
        {
          head: 'Subject',
          cell: function (r, self) {
            return '<div style="display:flex;flex-direction:column">'
              + '<span class="amharic" style="font-weight:700;color:#1e293b">' + esc(self.nameOf(r)) + '</span>'
              + '<span style="font-size:.68rem;color:#94a3b8">' + esc(r.subject_name_en || '—') + '</span>'
              + '</div>';
          }
        },
        {
          head: 'Code',
          cell: function (r) {
            return r.subject_code
              ? '<code style="font-size:.7rem;background:#f1f5f9;padding:2px 6px;border-radius:4px">' + esc(r.subject_code) + '</code>'
              : '<span style="color:#94a3b8">—</span>';
          }
        },
        { head: 'Classes offering', cell: function (r) { return countChip(r.assigned_classes, 'ch-p'); } },
        { head: 'Status', cell: function (r) { return statusChip(r.is_active); } }
      ]
    },

    classes: {
      key: 'classes',
      label: 'Classes',
      singular: 'class',
      pluralWord: 'classes',
      amharic: 'ክፍሎች',
      icon: 'fa-school',
      blurb: 'Find a class and track its subjects, students and progress.',
      url: '/admin/api_education.php?action=get_classes',
      rowsKey: 'classes',
      idField: 'id',
      nameOf: function (r) { return r.class_name || r.class_name_en || ('#' + r.id); },
      filters: [
        { key: 'q', type: 'search', label: 'Search classes', placeholder: 'Name or code…' },
        {
          key: 'status', type: 'select', label: 'Status', any: 'Any status',
          options: [['active', 'Active'], ['inactive', 'Inactive']]
        }
      ],
      sorts: [['level', 'Level'], ['name', 'Name'], ['students', 'Student count']],
      defaultSort: 'level',
      columns: [
        {
          head: 'Class',
          cell: function (r, self) {
            return '<div style="display:flex;flex-direction:column">'
              + '<span class="amharic" style="font-weight:700;color:#1e293b">' + esc(self.nameOf(r)) + '</span>'
              + '<span style="font-size:.68rem;color:#94a3b8">' + esc(r.class_name_en || '—') + '</span>'
              + '</div>';
          }
        },
        {
          head: 'Code',
          cell: function (r) {
            return '<code style="font-size:.7rem;background:#f1f5f9;padding:2px 6px;border-radius:4px">' + esc(r.class_code || '—') + '</code>';
          }
        },
        { head: 'Level', cell: function (r) { return esc(r.level_order); } },
        { head: 'Students', cell: function (r) { return countChip(r.student_count); } },
        { head: 'Status', cell: function (r) { return statusChip(r.is_active); } }
      ]
    }
  };

  var ORDER = ['students', 'teachers', 'subjects', 'classes'];

  var STYLE_ID = 'at-root-style';
  function injectStyle(containerId) {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '#' + containerId + ' .at-card{cursor:pointer;text-align:left;border:1px solid #e2e8f0;border-radius:14px;'
      + 'padding:1.1rem;background:#fff;transition:border-color .15s,box-shadow .15s,transform .15s;width:100%}'
      + '#' + containerId + ' .at-card:hover{border-color:' + PALETTE.primary + ';box-shadow:0 6px 18px rgba(124,58,237,.12);transform:translateY(-2px)}'
      + '#' + containerId + ' .at-card:focus-visible{outline:3px solid ' + PALETTE.primary + ';outline-offset:2px}'
      + '#' + containerId + ' .at-row{cursor:pointer}'
      + '#' + containerId + ' .at-row:hover{background:#faf5ff}'
      + '#' + containerId + ' .at-row:focus-visible{outline:2px solid ' + PALETTE.primary + ';outline-offset:-2px}'
      + '#' + containerId + ' .at-skel{background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 37%,#f1f5f9 63%);'
      + 'background-size:400% 100%;border-radius:6px;height:12px;animation:atshimmer 1.2s ease-in-out infinite}'
      + '@keyframes atshimmer{0%{background-position:100% 50%}100%{background-position:0 50%}}'
      + '@media (prefers-reduced-motion: reduce){#' + containerId + ' .at-skel{animation:none}'
      + '#' + containerId + ' .at-card:hover{transform:none}}';
    var el = document.createElement('style');
    el.id = STYLE_ID;
    el.textContent = css;
    document.head.appendChild(el);
  }

  // ── controller ────────────────────────────────────────────────────────────

  function AcademicTracking(options) {
    this.options = Object.assign({ containerId: 'sec-academic-tracking' }, options || {});

    // 'home' until the user picks something. Never a list by default: the
    // first screen asks the question instead of answering it for them.
    this.view = 'home';

    // Reporting period. The year is resolved by the server and shown as
    // context; it is not a filter and is not counted as one.
    this.context = { year_id: 0, year_name: '' };

    // The explicitly selected entity. Null until a click. There is no code
    // path anywhere in this file that assigns it from rows[0].
    this.scope = null;

    this.lists = {};
    for (var i = 0; i < ORDER.length; i++) this.lists[ORDER[i]] = this.freshList(ORDER[i]);

    // Class options for the students list's class filter. Loaded once,
    // lazily, and only because that one filter needs labels.
    this.classOptions = null;

    this._seq = 0;
    this._searchTimer = null;
    this._booted = false;
  }

  AcademicTracking.prototype.freshList = function (key) {
    var d = ENTITIES[key];
    return {
      filters: {},                 // user-applied only; empty object = none
      sort: d.defaultSort,
      dir: 'asc',
      page: 1,
      per_page: 25,
      status: 'idle',              // idle | loading | ready | error
      rows: [],
      total: 0,
      pages: 1,
      error: ''
    };
  };

  AcademicTracking.prototype.root = function () {
    return document.getElementById(this.options.containerId);
  };

  AcademicTracking.prototype.boot = function () {
    if (this._booted) return Promise.resolve();
    this._booted = true;
    injectStyle(this.options.containerId);
    this.render();
    return Promise.resolve();
  };

  // ── state transitions ─────────────────────────────────────────────────────

  /** Open one of the four root lists. Does NOT select anything in it. */
  AcademicTracking.prototype.openList = function (key) {
    if (!ENTITIES[key]) return Promise.resolve();
    this.view = key;
    this.scope = null;
    this.render();
    return this.load(key);
  };

  AcademicTracking.prototype.goHome = function () {
    this.view = 'home';
    this.scope = null;
    this.render();
  };

  /**
   * The only way an entity ever becomes selected.
   *
   * It takes an id that came from a row the user activated. Nothing calls it
   * on load, and the list renderer never calls it for rows[0].
   */
  AcademicTracking.prototype.selectEntity = function (type, id, label) {
    if (!ENTITIES[type]) return null;
    var numeric = intOr(id, 0);
    if (!numeric) return null;
    this.scope = { type: type, id: numeric, label: label || ('#' + numeric) };
    this.render();
    return this.scope;
  };

  AcademicTracking.prototype.clearSelection = function () {
    this.scope = null;
    this.render();
  };

  /**
   * Which filters are genuinely applied right now.
   *
   * This is the single source of truth for both the "N filters" badge and
   * the decision about whether the empty state may suggest clearing them.
   * An entity selection is not in here, and neither is the academic year.
   */
  AcademicTracking.prototype.activeFilters = function (key) {
    var st = this.lists[key];
    var d = ENTITIES[key];
    var out = [];
    if (!st) return out;
    for (var i = 0; i < d.filters.length; i++) {
      var f = d.filters[i];
      var v = st.filters[f.key];
      if (v === undefined || v === null || v === '') continue;
      out.push({ key: f.key, label: f.label, value: v });
    }
    return out;
  };

  AcademicTracking.prototype.hasFilters = function (key) {
    return this.activeFilters(key).length > 0;
  };

  /** Changing any filter resets to page 1 — page 3 of a different query is meaningless. */
  AcademicTracking.prototype.setFilter = function (key, name, value) {
    var st = this.lists[key];
    if (!st) return Promise.resolve();
    if (value === '' || value === null || value === undefined) delete st.filters[name];
    else st.filters[name] = String(value);
    st.page = 1;
    return this.load(key);
  };

  AcademicTracking.prototype.clearFilters = function (key) {
    var st = this.lists[key];
    if (!st) return Promise.resolve();
    st.filters = {};
    st.page = 1;
    return this.load(key);
  };

  AcademicTracking.prototype.setSort = function (key, sort, dir) {
    var st = this.lists[key];
    if (!st) return Promise.resolve();
    st.sort = sort;
    if (dir) st.dir = dir;
    st.page = 1;
    return this.load(key);
  };

  AcademicTracking.prototype.setPage = function (key, page) {
    var st = this.lists[key];
    if (!st) return Promise.resolve();
    var p = Math.max(1, Math.min(intOr(page, 1), Math.max(1, st.pages)));
    if (p === st.page) return Promise.resolve();
    st.page = p;
    return this.load(key);
  };

  /** Debounced so typing "Abebe" is one request, not five. */
  AcademicTracking.prototype.search = function (key, text) {
    var self = this;
    if (this._searchTimer) clearTimeout(this._searchTimer);
    return new Promise(function (resolve) {
      self._searchTimer = setTimeout(function () {
        resolve(self.setFilter(key, 'q', text));
      }, 250);
    });
  };

  // ── loading ───────────────────────────────────────────────────────────────

  AcademicTracking.prototype.buildQs = function (key) {
    var st = this.lists[key];
    var d = ENTITIES[key];
    var parts = [];
    var k;
    for (k in st.filters) {
      if (Object.prototype.hasOwnProperty.call(st.filters, k) && st.filters[k] !== '') {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(st.filters[k]));
      }
    }
    if (d.sorts.length && st.sort) {
      parts.push('sort=' + encodeURIComponent(st.sort));
      parts.push('dir=' + encodeURIComponent(st.dir));
    }
    parts.push('page=' + st.page);
    parts.push('per_page=' + st.per_page);
    return parts.length ? ('&' + parts.join('&')) : '';
  };

  AcademicTracking.prototype.load = function (key) {
    var self = this;
    var st = this.lists[key];
    var d = ENTITIES[key];
    if (!st) return Promise.resolve();

    // Race guard: a slow "Ab" must never overwrite a fast "Abebe".
    var seq = ++this._seq;
    st.status = 'loading';
    st.error = '';
    this.render();

    return fetch(d.url + this.buildQs(key), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (seq !== self._seq) return;                 // superseded
        if (!data || data.status !== 'success') {
          st.status = 'error';
          st.error = (data && data.message) || 'The server could not return the list.';
          st.rows = [];
          st.total = 0;
          self.render();
          return;
        }
        st.rows = data[d.rowsKey] || [];
        st.total = intOr(data.total, st.rows.length);
        st.pages = Math.max(1, intOr(data.pages, 1));
        st.page = Math.max(1, intOr(data.page, st.page));
        st.per_page = Math.max(1, intOr(data.per_page, st.per_page));
        if (data.year_id) {
          self.context.year_id = intOr(data.year_id, 0);
          self.context.year_name = data.year_name || '';
        }
        st.status = 'ready';
        self.render();
      })
      .catch(function (e) {
        if (seq !== self._seq) return;
        st.status = 'error';
        st.error = (e && e.message) ? String(e.message) : 'Network error.';
        st.rows = [];
        st.total = 0;
        self.render();
      });
  };

  /** The students list needs class labels for its class filter. */
  AcademicTracking.prototype.loadClassOptions = function () {
    var self = this;
    if (this.classOptions) return Promise.resolve(this.classOptions);
    return fetch('/admin/api_education.php?action=get_classes&status=active', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        self.classOptions = (d && d.classes) ? d.classes : [];
        self.render();
        return self.classOptions;
      })
      .catch(function () { self.classOptions = []; return []; });
  };

  // ── rendering ─────────────────────────────────────────────────────────────

  AcademicTracking.prototype.render = function () {
    var root = this.root();
    if (!root) return;
    var html = this.renderHeader();
    if (this.view === 'home') html += this.renderHome();
    else if (this.scope) html += this.renderSelection();
    else html += this.renderList(this.view);
    root.innerHTML = html;
    this.bind();
  };

  AcademicTracking.prototype.renderHeader = function () {
    var crumbs = ['<button type="button" class="at-crumb" data-go="home" '
      + 'style="background:none;border:none;padding:0;color:#64748b;cursor:pointer;font:inherit">Academic Tracking</button>'];
    if (this.view !== 'home') {
      var d = ENTITIES[this.view];
      if (this.scope) {
        crumbs.push('<button type="button" class="at-crumb" data-go="' + esc(this.view) + '" '
          + 'style="background:none;border:none;padding:0;color:#64748b;cursor:pointer;font:inherit">' + esc(d.label) + '</button>');
        crumbs.push('<span style="color:#1e293b;font-weight:700">' + esc(this.scope.label) + '</span>');
      } else {
        crumbs.push('<span style="color:#1e293b;font-weight:700">' + esc(d.label) + '</span>');
      }
    }

    var ctx = this.context.year_name
      ? '<span class="ch ch-i" title="Reporting period. This is context, not a filter.">'
        + '<i class="fa-solid fa-calendar"></i> ' + esc(this.context.year_name) + '</span>'
      : '';

    return '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.9rem;flex-wrap:wrap;gap:.5rem">'
      + '<div><h2 style="font-size:1.25rem;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:.5rem">'
      + '<span style="width:34px;height:34px;border-radius:10px;background:' + PALETTE.brand
      + ';color:#fff;display:inline-flex;align-items:center;justify-content:center"><i class="fa-solid fa-compass-drafting"></i></span>'
      + '<span>Academic Tracking</span></h2>'
      + '<p style="font-size:.75rem;color:#64748b" class="amharic">ተማሪ፣ መምህር፣ ትምህርት ወይም ክፍል ይምረጡ</p></div>'
      + '<div style="display:flex;align-items:center;gap:.5rem">' + ctx + '</div>'
      + '</div>'
      + '<nav aria-label="Breadcrumb" style="font-size:.75rem;color:#94a3b8;margin-bottom:.85rem;display:flex;gap:.4rem;flex-wrap:wrap">'
      + crumbs.join('<span aria-hidden="true">/</span>') + '</nav>';
  };

  AcademicTracking.prototype.renderHome = function () {
    var cards = ORDER.map(function (k) {
      var d = ENTITIES[k];
      return '<button type="button" class="at-card" data-open="' + k + '">'
        + '<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.45rem">'
        + '<span style="width:38px;height:38px;border-radius:11px;background:#f5f3ff;color:' + PALETTE.primary
        + ';display:inline-flex;align-items:center;justify-content:center;font-size:1rem">'
        + '<i class="fa-solid ' + d.icon + '"></i></span>'
        + '<span style="display:flex;flex-direction:column">'
        + '<span style="font-weight:800;color:#1e293b;font-size:.95rem">' + esc(d.label) + '</span>'
        + '<span class="amharic" style="font-size:.7rem;color:#94a3b8">' + esc(d.amharic) + '</span>'
        + '</span></div>'
        + '<span style="font-size:.76rem;color:#64748b;line-height:1.45">' + esc(d.blurb) + '</span>'
        + '</button>';
    }).join('');

    return '<div class="crd" style="padding:1.1rem">'
      + '<h3 style="font-size:.95rem;font-weight:800;color:#1e293b;margin-bottom:.25rem">Select what you want to track</h3>'
      + '<p style="font-size:.76rem;color:#64748b;margin-bottom:.9rem">'
      + 'Nothing is selected yet. Choose an entry point, find the record you need, then open it.</p>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem">'
      + cards + '</div></div>';
  };

  /**
   * The Phase 2 boundary.
   *
   * The user has chosen an entity and we stop here on purpose. Showing an
   * empty chart frame or a zeroed KPI would imply the tracking screen exists
   * and has no data, which is a different and false statement.
   */
  AcademicTracking.prototype.renderSelection = function () {
    var d = ENTITIES[this.scope.type];
    return '<div class="crd" style="padding:1.25rem">'
      + '<div style="display:flex;align-items:center;gap:.7rem;margin-bottom:.75rem">'
      + '<span style="width:42px;height:42px;border-radius:12px;background:#f5f3ff;color:' + PALETTE.primary
      + ';display:inline-flex;align-items:center;justify-content:center;font-size:1.05rem">'
      + '<i class="fa-solid ' + d.icon + '"></i></span>'
      + '<div><div style="font-weight:800;color:#1e293b;font-size:1rem" class="amharic">' + esc(this.scope.label) + '</div>'
      + '<div style="font-size:.72rem;color:#94a3b8">Selected ' + esc(d.singular)
      + ' · id ' + esc(this.scope.id) + '</div></div>'
      + '</div>'
      + '<div style="border:1px dashed #cbd5e1;border-radius:12px;padding:1rem;background:#f8fafc">'
      + '<div style="font-weight:700;color:#475569;font-size:.85rem;margin-bottom:.3rem">'
      + '<i class="fa-solid fa-road-barrier" style="color:' + PALETTE.warn + '"></i> Tracking view arrives in Phase 2</div>'
      + '<p style="font-size:.76rem;color:#64748b;line-height:1.5;margin:0">'
      + 'This ' + esc(d.singular) + ' is now the active scope. Phase 1 covers finding and choosing a record; '
      + 'the scoped tracking screen — results, progress, workflow status and the links into the existing '
      + 'report card and submission screens — is the next phase. Nothing is shown here rather than showing '
      + 'numbers that have not been built yet.</p></div>'
      + '<div style="margin-top:.85rem;display:flex;gap:.5rem;flex-wrap:wrap">'
      + '<button type="button" class="btn btn-o btn-xs" data-go="' + esc(this.scope.type) + '">'
      + '<i class="fa-solid fa-arrow-left"></i> Back to ' + esc(d.label) + '</button>'
      + '<button type="button" class="btn btn-o btn-xs" data-clear-selection="1">Clear selection</button>'
      + '</div></div>';
  };

  AcademicTracking.prototype.renderList = function (key) {
    var d = ENTITIES[key];
    var st = this.lists[key];
    var active = this.activeFilters(key);

    return '<div class="crd" style="padding:1rem">'
      + this.renderListHead(d, st, active)
      + this.renderFilterBar(d, st, active)
      + '</div>'
      + '<div class="crd" style="padding:0;margin-top:.75rem;overflow:hidden" aria-live="polite" aria-busy="'
      + (st.status === 'loading' ? 'true' : 'false') + '">'
      + this.renderBody(d, st, active)
      + '</div>'
      + this.renderPager(d, st);
  };

  AcademicTracking.prototype.renderListHead = function (d, st, active) {
    // The count describes the whole query, not the current page, and says so
    // differently when a filter is narrowing it.
    var count;
    if (st.status === 'loading' && st.status !== 'ready') {
      count = '<span style="color:#94a3b8">Counting…</span>';
    } else if (st.status === 'error') {
      count = '<span style="color:' + PALETTE.bad + '">Count unavailable</span>';
    } else if (active.length) {
      count = '<strong>' + esc(group(st.total)) + '</strong> '
        + esc(plural(st.total, d.singular, d.pluralWord)) + ' match the current filters';
    } else {
      count = '<strong>' + esc(group(st.total)) + '</strong> '
        + esc(plural(st.total, d.singular, d.pluralWord));
    }

    return '<div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.7rem">'
      + '<div style="display:flex;align-items:center;gap:.5rem">'
      + '<span style="width:30px;height:30px;border-radius:9px;background:#f5f3ff;color:' + PALETTE.primary
      + ';display:inline-flex;align-items:center;justify-content:center;font-size:.8rem">'
      + '<i class="fa-solid ' + d.icon + '"></i></span>'
      + '<span style="font-weight:800;color:#1e293b;font-size:1rem">' + esc(d.label) + '</span>'
      + '</div>'
      + '<div style="font-size:.78rem;color:#64748b">' + count + '</div>'
      + '</div>';
  };

  AcademicTracking.prototype.renderFilterBar = function (d, st, active) {
    var self = this;
    var controls = d.filters.map(function (f) {
      var val = st.filters[f.key] === undefined ? '' : st.filters[f.key];
      if (f.type === 'search') {
        return '<div style="flex:2 1 220px;display:flex;flex-direction:column;gap:.2rem">'
          + '<label class="lbl" for="at-f-' + esc(d.key) + '-' + esc(f.key) + '">' + esc(f.label) + '</label>'
          + '<input class="inp" type="search" id="at-f-' + esc(d.key) + '-' + esc(f.key) + '" '
          + 'data-search="' + esc(f.key) + '" placeholder="' + esc(f.placeholder || '') + '" '
          + 'value="' + esc(val) + '">'
          + '</div>';
      }
      var opts = ['<option value="">' + esc(f.any) + '</option>'];
      var list = f.options || [];
      if (f.source === 'classes') {
        var cls = self.classOptions || [];
        list = cls.map(function (c) { return [String(c.id), c.class_name || c.class_name_en || ('#' + c.id)]; });
      }
      list.forEach(function (o) {
        opts.push('<option value="' + esc(o[0]) + '"' + (String(val) === String(o[0]) ? ' selected' : '') + '>'
          + esc(o[1]) + '</option>');
      });
      return '<div style="flex:1 1 150px;display:flex;flex-direction:column;gap:.2rem">'
        + '<label class="lbl" for="at-f-' + esc(d.key) + '-' + esc(f.key) + '">' + esc(f.label) + '</label>'
        + '<select class="inp" id="at-f-' + esc(d.key) + '-' + esc(f.key) + '" data-filter="' + esc(f.key) + '">'
        + opts.join('') + '</select></div>';
    }).join('');

    var sortCtl = '';
    if (d.sorts.length) {
      sortCtl = '<div style="flex:1 1 150px;display:flex;flex-direction:column;gap:.2rem">'
        + '<label class="lbl" for="at-sort-' + esc(d.key) + '">Sort by</label>'
        + '<select class="inp" id="at-sort-' + esc(d.key) + '" data-sort="1">'
        + d.sorts.map(function (s) {
          return '<option value="' + esc(s[0]) + '"' + (st.sort === s[0] ? ' selected' : '') + '>' + esc(s[1]) + '</option>';
        }).join('')
        + '</select></div>';
    }

    // The badge only ever counts real filters. With none applied it is absent
    // entirely, so the screen never implies there is something to clear.
    var badge = active.length
      ? '<div style="margin-top:.6rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">'
        + '<span class="ch ch-p">' + esc(active.length) + ' '
        + esc(active.length === 1 ? 'filter' : 'filters') + ' applied</span>'
        + '<button type="button" class="btn btn-o btn-xs" data-clear-filters="1">'
        + '<i class="fa-solid fa-xmark"></i> Clear filters</button></div>'
      : '';

    return '<div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end">' + controls + sortCtl + '</div>' + badge;
  };

  AcademicTracking.prototype.renderBody = function (d, st, active) {
    if (st.status === 'loading') return this.renderSkeleton(d);
    if (st.status === 'error') {
      return this.stateBlock('fa-triangle-exclamation', PALETTE.bad,
        'We couldn\u2019t load ' + d.pluralWord + '.',
        esc(st.error) + ' Please try again.',
        '<button type="button" class="btn btn-p btn-xs" data-retry="1"><i class="fa-solid fa-rotate"></i> Try again</button>');
    }
    if (!st.rows.length) {
      // Two genuinely different situations, never merged into "No data".
      if (active.length) {
        return this.stateBlock('fa-filter-circle-xmark', PALETTE.warn,
          'No ' + d.pluralWord + ' match your search.',
          'The ' + (active.length === 1 ? 'filter' : 'filters') + ' currently applied ('
          + active.map(function (a) { return esc(a.label); }).join(', ')
          + ') exclude every record. Try changing or clearing them.',
          '<button type="button" class="btn btn-o btn-xs" data-clear-filters="1">Clear filters</button>');
      }
      return this.stateBlock('fa-inbox', PALETTE.muted,
        'No ' + d.pluralWord + ' are available for this academic context.',
        'Nothing has been recorded yet. This is not a filter problem — no '
        + d.pluralWord + ' exist to track.', '');
    }

    var self = this;
    var head = d.columns.map(function (c) { return '<th>' + esc(c.head) + '</th>'; }).join('')
      + '<th style="width:1%"></th>';
    var body = st.rows.map(function (r) {
      var id = intOr(r[d.idField], 0);
      var label = d.nameOf(r);
      var cells = d.columns.map(function (c) { return '<td>' + c.cell(r, d) + '</td>'; }).join('');
      return '<tr class="at-row" tabindex="0" role="button" data-select-id="' + esc(id) + '" '
        + 'data-select-label="' + esc(label) + '" '
        + 'aria-label="Open ' + esc(label) + '">'
        + cells
        + '<td style="text-align:right;color:' + PALETTE.muted + '"><i class="fa-solid fa-chevron-right"></i></td>'
        + '</tr>';
    }).join('');

    return '<div class="tw"><table class="dt"><thead><tr>' + head + '</tr></thead><tbody>'
      + body + '</tbody></table></div>';
  };

  AcademicTracking.prototype.renderSkeleton = function (d) {
    var cols = d.columns.length + 1;
    var rows = '';
    for (var i = 0; i < 6; i++) {
      var tds = '';
      for (var c = 0; c < cols; c++) {
        tds += '<td><div class="at-skel" style="width:' + (c === 0 ? '70%' : '45%') + '"></div></td>';
      }
      rows += '<tr>' + tds + '</tr>';
    }
    var head = d.columns.map(function (c) { return '<th>' + esc(c.head) + '</th>'; }).join('') + '<th></th>';
    return '<div class="tw" aria-label="Loading ' + esc(d.pluralWord) + '"><table class="dt">'
      + '<thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
  };

  AcademicTracking.prototype.stateBlock = function (icon, colour, title, message, action) {
    return '<div style="padding:2.2rem 1.25rem;text-align:center">'
      + '<i class="fa-solid ' + icon + '" style="font-size:1.6rem;color:' + colour + '"></i>'
      + '<div style="margin-top:.6rem;font-weight:700;color:#475569;font-size:.9rem">' + title + '</div>'
      + '<div style="margin-top:.3rem;font-size:.76rem;color:#94a3b8;max-width:460px;margin-left:auto;margin-right:auto;line-height:1.5">'
      + message + '</div>'
      + (action ? '<div style="margin-top:.8rem">' + action + '</div>' : '')
      + '</div>';
  };

  AcademicTracking.prototype.renderPager = function (d, st) {
    if (st.status !== 'ready' || !st.rows.length) return '';
    var first = ((st.page - 1) * st.per_page) + 1;
    var last = Math.min(st.total, first + st.rows.length - 1);

    var btns = '';
    var window_ = [];
    for (var p = Math.max(1, st.page - 2); p <= Math.min(st.pages, st.page + 2); p++) window_.push(p);
    window_.forEach(function (p) {
      btns += '<button type="button" class="btn btn-xs ' + (p === st.page ? 'btn-p' : 'btn-o') + '" '
        + 'data-page="' + p + '"' + (p === st.page ? ' aria-current="page"' : '') + '>' + p + '</button>';
    });

    return '<div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;margin-top:.7rem">'
      + '<div style="font-size:.74rem;color:#64748b">Showing ' + esc(group(first)) + '–' + esc(group(last))
      + ' of ' + esc(group(st.total)) + '</div>'
      + '<div style="display:flex;gap:.3rem;align-items:center;flex-wrap:wrap">'
      + '<button type="button" class="btn btn-o btn-xs" data-page="' + (st.page - 1) + '"'
      + (st.page <= 1 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-left"></i> Previous</button>'
      + btns
      + '<button type="button" class="btn btn-o btn-xs" data-page="' + (st.page + 1) + '"'
      + (st.page >= st.pages ? ' disabled' : '') + '>Next <i class="fa-solid fa-chevron-right"></i></button>'
      + '</div></div>';
  };

  // ── events ────────────────────────────────────────────────────────────────

  AcademicTracking.prototype.bind = function () {
    var self = this;
    var root = this.root();
    if (!root) return;
    var key = this.view;

    root.querySelectorAll('[data-open]').forEach(function (el) {
      el.addEventListener('click', function () { self.openList(el.getAttribute('data-open')); });
    });
    root.querySelectorAll('[data-go]').forEach(function (el) {
      el.addEventListener('click', function () {
        var g = el.getAttribute('data-go');
        if (g === 'home') self.goHome();
        else { self.scope = null; self.openList(g); }
      });
    });
    root.querySelectorAll('[data-clear-selection]').forEach(function (el) {
      el.addEventListener('click', function () { self.clearSelection(); });
    });
    root.querySelectorAll('[data-clear-filters]').forEach(function (el) {
      el.addEventListener('click', function () { self.clearFilters(key); });
    });
    root.querySelectorAll('[data-retry]').forEach(function (el) {
      el.addEventListener('click', function () { self.load(key); });
    });
    root.querySelectorAll('[data-filter]').forEach(function (el) {
      el.addEventListener('change', function () { self.setFilter(key, el.getAttribute('data-filter'), el.value); });
    });
    root.querySelectorAll('[data-search]').forEach(function (el) {
      el.addEventListener('input', function () { self.search(key, el.value); });
    });
    root.querySelectorAll('[data-sort]').forEach(function (el) {
      el.addEventListener('change', function () { self.setSort(key, el.value); });
    });
    root.querySelectorAll('[data-page]').forEach(function (el) {
      el.addEventListener('click', function () { self.setPage(key, el.getAttribute('data-page')); });
    });

    // A row is selected because it was activated, never because it was first.
    root.querySelectorAll('[data-select-id]').forEach(function (el) {
      var go = function () {
        self.selectEntity(key, el.getAttribute('data-select-id'), el.getAttribute('data-select-label'));
      };
      el.addEventListener('click', go);
      el.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); go(); }
      });
    });

    if (key === 'students' && this.classOptions === null) this.loadClassOptions();
  };

  AcademicTracking.ENTITIES = ENTITIES;
  AcademicTracking.ORDER = ORDER;

  global.AcademicTracking = AcademicTracking;
  if (!global.AcademicTrackingInstance) {
    global.AcademicTrackingInstance = new AcademicTracking();
  }
}(typeof window !== 'undefined' ? window : this));
