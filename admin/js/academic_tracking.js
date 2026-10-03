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

  // ── Phase 2: student workflow ────────────────────────────────────────────
  var API_EDU = '/admin/api_education.php';
  var API_COMM = '/admin/api_communication.php';

  var STUDENT_SECTIONS = ['overview', 'subjects', 'assessments', 'attendance', 'report_card'];
  var SECTION_META = {
    overview:    { label: 'Overview',    icon: 'fa-gauge-simple-high' },
    subjects:    { label: 'Subjects',    icon: 'fa-book-open' },
    assessments: { label: 'Assessments', icon: 'fa-clipboard-list' },
    attendance:  { label: 'Attendance',  icon: 'fa-user-check' },
    report_card: { label: 'Report Card', icon: 'fa-file-lines' }
  };

  function prefersReducedMotion() {
    try {
      return !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (e) { return false; }
  }

  function fullName(s) {
    if (!s) return '';
    return [s.student_name, s.father_name, s.grandfather_name]
      .filter(function (x) { return x; }).join(' ');
  }

  function titleCase(s) {
    s = String(s || '');
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
  }

  /** A dash is the visual form of "no value exists". Never a zero. */
  function dash() {
    return '<span style="color:#cbd5e1" title="No value recorded">\u2014</span>';
  }

  function numOrDash(v) {
    return (v === null || v === undefined || v === '') ? dash() : esc(v);
  }

  function fmtPct(v) {
    return (v === null || v === undefined) ? '' : (v + '%');
  }

  function gradeChip(letter) {
    var tone = { A: 'ch-ok', B: 'ch-ok', C: 'ch-i', D: 'ch-w', F: 'ch-d' }[String(letter)] || 'ch-d';
    return '<span class="ch ' + tone + '">' + esc(letter) + '</span>';
  }

  /** Duration is policy, shown verbatim so SEMESTER_ONLY never reads as FULL_YEAR. */
  function durationChip(d) {
    if (d === 'FULL_YEAR') {
      return '<span class="ch ch-p" title="Combined from both semesters">Full year</span>';
    }
    if (d === 'SEMESTER_ONLY') {
      return '<span class="ch ch-i" title="Final on its own semester">Semester</span>';
    }
    return '<span class="ch ch-d" title="Duration not classified for this offering">Unclassified</span>';
  }

  function subjectStatusChip(s) {
    var st = s.subject_status;
    var map = {
      CLOSED: ['ch-ok', 'Complete'],
      CONTINUING: ['ch-i', 'In progress'],
      PENDING: ['ch-w', 'Pending']
    };
    var m = map[st];
    if (!m) {
      return '<span class="ch ch-d" title="' + esc(s.status_reason || '') + '">Unclassified</span>';
    }
    return '<span class="ch ' + m[0] + '"' + (s.status_reason ? ' title="' + esc(s.status_reason) + '"' : '')
      + '>' + m[1] + '</span>';
  }

  /**
   * Workflow status of the class mark list. Null means the list was never
   * started — a real answer, not a missing one.
   */
  function workflowChip(status, label) {
    var tone = {
      approved: 'ch-ok',
      submitted: 'ch-i',
      revision_needed: 'ch-w',
      rejected: 'ch-d',
      draft: 'ch-d',
      incomplete: 'ch-d'
    }[String(status)] || 'ch-d';
    return '<span class="ch ' + tone + '">' + esc(label || 'Not started') + '</span>';
  }

  /**
   * A summary tile. When `value` is null the tile shows WHY rather than a
   * zero — this is the single place that rule is implemented.
   */
  function metricTile(icon, label, value, sub, missingReason) {
    var inner = value === null || value === undefined || value === ''
      ? '<div style="font-size:.82rem;font-weight:700;color:#94a3b8;margin-top:.1rem">Not available</div>'
        + (missingReason ? '<div style="font-size:.67rem;color:#cbd5e1;line-height:1.35;margin-top:.1rem">'
          + esc(missingReason) + '</div>' : '')
      : '<div style="font-size:1.3rem;font-weight:800;color:#1e293b;line-height:1.2">' + value + '</div>'
        + (sub ? '<div style="font-size:.68rem;color:#94a3b8">' + sub + '</div>' : '');

    return '<div style="border:1px solid #e2e8f0;border-radius:11px;padding:.7rem .8rem;background:#fff">'
      + '<div style="font-size:.69rem;color:#64748b;font-weight:700;text-transform:uppercase;'
      + 'letter-spacing:.03em;display:flex;align-items:center;gap:.3rem">'
      + '<i class="fa-solid ' + icon + '" style="color:#7c3aed" aria-hidden="true"></i> ' + esc(label) + '</div>'
      + inner + '</div>';
  }

  function noteBlock(icon, title, body) {
    return '<div style="margin-top:.85rem;border:1px solid #e2e8f0;border-left:3px solid #7c3aed;'
      + 'border-radius:9px;padding:.65rem .8rem;background:#faf9ff">'
      + '<div style="font-size:.78rem;font-weight:700;color:#475569">'
      + '<i class="fa-solid ' + icon + '" style="color:#7c3aed" aria-hidden="true"></i> ' + esc(title) + '</div>'
      + '<p style="font-size:.73rem;color:#64748b;line-height:1.5;margin:.25rem 0 0">' + esc(body) + '</p></div>';
  }

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
      // Section tabs. Horizontally scrollable rather than wrapping into an
      // unreadable stack on a narrow screen.
      + '#' + containerId + ' .at-tabs{display:flex;gap:.3rem;overflow-x:auto;border-bottom:1px solid #e2e8f0;'
      + 'padding-bottom:0;-webkit-overflow-scrolling:touch}'
      + '#' + containerId + ' .at-tab{flex:none;background:none;border:none;border-bottom:2px solid transparent;'
      + 'padding:.55rem .8rem;font:inherit;font-size:.8rem;font-weight:600;color:#64748b;cursor:pointer;'
      + 'white-space:nowrap;transition:color .15s,border-color .15s}'
      + '#' + containerId + ' .at-tab:hover{color:' + PALETTE.primary + '}'
      + '#' + containerId + ' .at-tab-on{color:' + PALETTE.primary + ';border-bottom-color:' + PALETTE.primary + '}'
      + '#' + containerId + ' .at-tab:focus-visible{outline:2px solid ' + PALETTE.primary + ';outline-offset:-2px;border-radius:6px}'
      + '#' + containerId + ' #at-section-panel:focus-visible{outline:2px solid ' + PALETTE.primary + ';outline-offset:2px}'
      + '@media (max-width:640px){#' + containerId + ' .at-tab{padding:.5rem .6rem;font-size:.75rem}}'
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

    // ── Phase 2: the scoped student workspace ──
    // Which section of the selected student's workflow is open. Reset on
    // every new selection so one student's open tab never carries its
    // scroll position or its data into the next student.
    this.section = 'overview';

    // detail  = header + overview + subjects + attendance (one request)
    // lazy    = the sections that cost extra, fetched only when opened
    this.detail = this.freshSection();
    this.lazy = {
      assessments: this.freshSection(),
      report_card: this.freshSection()
    };

    this._seq = 0;
    this._detailSeq = 0;
    this._lazySeq = { assessments: 0, report_card: 0 };
    this._searchTimer = null;
    this._booted = false;
  }

  /** Every async section uses the same four states. 'idle' means never asked. */
  AcademicTracking.prototype.freshSection = function () {
    return { status: 'idle', data: null, error: '', code: '' };
  };

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

    // A new scope invalidates everything the previous one loaded. Without
    // this, opening student B after student A would show A's assessments
    // under B's name until the fetch returned.
    this.section = 'overview';
    this.detail = this.freshSection();
    this.lazy.assessments = this.freshSection();
    this.lazy.report_card = this.freshSection();

    this.render();
    if (type === 'students') this.loadStudentDetail();
    return this.scope;
  };

  AcademicTracking.prototype.clearSelection = function () {
    this.scope = null;
    this.section = 'overview';
    this.detail = this.freshSection();
    this.lazy.assessments = this.freshSection();
    this.lazy.report_card = this.freshSection();
    this.render();
  };

  /**
   * Switch section inside the student workspace.
   *
   * Overview, Subjects and Attendance are already in hand — they are three
   * readings of the one detail response — so they cost nothing to open.
   * Assessments and Report Card are fetched the first time they are asked
   * for, and never on load.
   */
  AcademicTracking.prototype.openSection = function (name) {
    if (STUDENT_SECTIONS.indexOf(name) < 0) return Promise.resolve();
    this.section = name;
    this.render();
    if (name === 'assessments' && this.lazy.assessments.status === 'idle') {
      return this.loadStudentAssessments();
    }
    if (name === 'report_card' && this.lazy.report_card.status === 'idle') {
      return this.loadStudentReportCard();
    }
    return Promise.resolve();
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
    // Students has a real workflow as of Phase 2. The other three keep the
    // honest boundary panel below until their phases land.
    if (this.scope.type === 'students') return this.renderStudent();

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
      + '<i class="fa-solid fa-road-barrier" style="color:' + PALETTE.warn + '"></i> '
      + esc(d.singular.charAt(0).toUpperCase() + d.singular.slice(1)) + ' tracking is not built yet</div>'
      + '<p style="font-size:.76rem;color:#64748b;line-height:1.5;margin:0">'
      + 'This ' + esc(d.singular) + ' is now the active scope. The student workflow shipped in Phase 2; '
      + 'the scoped ' + esc(d.singular) + ' screen — results, progress, workflow status and the links into '
      + 'the existing report card and submission screens — is a later phase. Nothing is shown here rather '
      + 'than showing numbers that have not been built yet.</p></div>'
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

    // ── Phase 2: student workspace controls ──
    root.querySelectorAll('[data-retry-detail]').forEach(function (el) {
      el.addEventListener('click', function () { self.loadStudentDetail(); });
    });
    root.querySelectorAll('[data-retry-section]').forEach(function (el) {
      el.addEventListener('click', function () {
        var s = el.getAttribute('data-retry-section');
        if (s === 'assessments') self.loadStudentAssessments();
        else if (s === 'report_card') self.loadStudentReportCard();
      });
    });

    var tabs = root.querySelectorAll('[data-section]');
    tabs.forEach(function (el, idx) {
      el.addEventListener('click', function () { self.openSection(el.getAttribute('data-section')); });
      // Arrow-key navigation is what makes a tablist a tablist for anyone
      // not using a mouse.
      el.addEventListener('keydown', function (ev) {
        var n = tabs.length;
        var next = null;
        if (ev.key === 'ArrowRight') next = (idx + 1) % n;
        else if (ev.key === 'ArrowLeft') next = (idx - 1 + n) % n;
        else if (ev.key === 'Home') next = 0;
        else if (ev.key === 'End') next = n - 1;
        if (next === null) return;
        ev.preventDefault();
        var target = tabs[next];
        if (target) {
          self.openSection(target.getAttribute('data-section'));
          var moved = self.root() && self.root().querySelector
            ? self.root().querySelector('[data-section="' + target.getAttribute('data-section') + '"]')
            : null;
          if (moved && typeof moved.focus === 'function') moved.focus();
        }
      });
    });

    if (this.scope && this.scope.type === 'students'
        && this.section === 'subjects' && this.detail.status === 'ready') {
      this.drawSubjectChart();
    }

    if (key === 'students' && this.classOptions === null) this.loadClassOptions();
  };

  // ══════════════════════════════════════════════════════════════════════
  // PHASE 2 — STUDENT TRACKING WORKFLOW
  //
  // Students is the only entity with a detail workflow. Teachers, Subjects
  // and Classes deliberately still stop at the boundary panel: their
  // workflows are Phase 3/4 and a half-built screen would be worse than an
  // honest one.
  // ══════════════════════════════════════════════════════════════════════

  /** Loading / fetching ----------------------------------------------- */

  AcademicTracking.prototype.studentQs = function () {
    var p = ['action=tracking_student_detail', 'member_id=' + encodeURIComponent(this.scope.id)];
    if (this.context.year_id) p.push('year_id=' + encodeURIComponent(this.context.year_id));
    return p;
  };

  AcademicTracking.prototype.loadStudentDetail = function () {
    var self = this;
    if (!this.scope || this.scope.type !== 'students') return Promise.resolve();
    var seq = ++this._detailSeq;
    var memberId = this.scope.id;
    this.detail = { status: 'loading', data: null, error: '', code: '' };
    this.render();

    var qs = this.studentQs().join('&');
    return fetch(API_EDU + '?' + qs)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        // Two guards, not one: a stale response, and a response for a
        // student the user has since navigated away from.
        if (seq !== self._detailSeq || !self.scope || self.scope.id !== memberId) return;
        if (!d || d.status !== 'success') {
          self.detail = {
            status: 'error', data: null,
            error: (d && d.message) || 'The server did not return this student.',
            code: (d && d.code) || ''
          };
        } else {
          self.detail = { status: 'ready', data: d, error: '', code: '' };
          if (d.context && d.context.year_name) {
            self.context = { year_id: d.context.year_id, year_name: d.context.year_name };
          }
        }
        self.render();
      })
      .catch(function () {
        if (seq !== self._detailSeq) return;
        // A transport failure is NOT an empty student.
        self.detail = {
          status: 'error', data: null,
          error: 'We could not reach the server.', code: 'network'
        };
        self.render();
      });
  };

  AcademicTracking.prototype.loadStudentAssessments = function () {
    var self = this;
    if (!this.scope || this.scope.type !== 'students') return Promise.resolve();
    var seq = ++this._lazySeq.assessments;
    var memberId = this.scope.id;
    this.lazy.assessments = { status: 'loading', data: null, error: '', code: '' };
    this.render();

    var p = ['action=tracking_student_assessments', 'member_id=' + encodeURIComponent(memberId)];
    if (this.context.year_id) p.push('year_id=' + encodeURIComponent(this.context.year_id));
    var cid = this.detailClassId();
    if (cid) p.push('class_id=' + encodeURIComponent(cid));

    return fetch(API_EDU + '?' + p.join('&'))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (seq !== self._lazySeq.assessments || !self.scope || self.scope.id !== memberId) return;
        if (!d || d.status !== 'success') {
          self.lazy.assessments = {
            status: 'error', data: null,
            error: (d && d.message) || 'The server did not return these assessments.',
            code: (d && d.code) || ''
          };
        } else {
          self.lazy.assessments = { status: 'ready', data: d, error: '', code: '' };
        }
        self.render();
      })
      .catch(function () {
        if (seq !== self._lazySeq.assessments) return;
        self.lazy.assessments = {
          status: 'error', data: null,
          error: 'We could not reach the server.', code: 'network'
        };
        self.render();
      });
  };

  /**
   * The report card comes from the EXISTING endpoint, unchanged.
   *
   * api_communication.php?action=get_report_card already returns
   * ReportCardService::getCard() behind the same canViewClass check the
   * tracking actions use. Re-serving the same card through a new action
   * would have been duplication for its own sake.
   */
  AcademicTracking.prototype.loadStudentReportCard = function () {
    var self = this;
    if (!this.scope || this.scope.type !== 'students') return Promise.resolve();
    var seq = ++this._lazySeq.report_card;
    var memberId = this.scope.id;
    this.lazy.report_card = { status: 'loading', data: null, error: '', code: '' };
    this.render();

    var p = ['action=get_report_card', 'member_id=' + encodeURIComponent(memberId)];
    var cid = this.detailClassId();
    if (cid) p.push('class_id=' + encodeURIComponent(cid));
    if (this.context.year_id) p.push('year_id=' + encodeURIComponent(this.context.year_id));

    return fetch(API_COMM + '?' + p.join('&'))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (seq !== self._lazySeq.report_card || !self.scope || self.scope.id !== memberId) return;
        if (!d || d.status !== 'success') {
          self.lazy.report_card = {
            status: 'error', data: null,
            error: (d && d.message) || 'The server did not return a report card.',
            code: (d && d.code) || ''
          };
        } else {
          self.lazy.report_card = { status: 'ready', data: d, error: '', code: '' };
        }
        self.render();
      })
      .catch(function () {
        if (seq !== self._lazySeq.report_card) return;
        self.lazy.report_card = {
          status: 'error', data: null,
          error: 'We could not reach the server.', code: 'network'
        };
        self.render();
      });
  };

  AcademicTracking.prototype.detailClassId = function () {
    var d = this.detail && this.detail.data;
    return d && d.scope ? intOr(d.scope.class_id, 0) : 0;
  };

  /** Rendering --------------------------------------------------------- */

  AcademicTracking.prototype.renderStudent = function () {
    return this.renderStudentHeader()
      + this.renderSectionNav()
      + '<div class="crd" id="at-section-panel" role="tabpanel" '
      + 'aria-labelledby="at-tab-' + esc(this.section) + '" tabindex="0" '
      + 'style="padding:0;margin-top:.7rem;overflow:hidden" aria-live="polite" aria-busy="'
      + (this.sectionBusy() ? 'true' : 'false') + '">'
      + this.renderSectionBody()
      + '</div>';
  };

  AcademicTracking.prototype.sectionBusy = function () {
    if (this.section === 'assessments') return this.lazy.assessments.status === 'loading';
    if (this.section === 'report_card') return this.lazy.report_card.status === 'loading';
    return this.detail.status === 'loading';
  };

  /**
   * Identity and context, always visible above every section, so the four
   * questions the workflow must answer are never more than a glance away:
   * which student, which class, which year, what am I looking at.
   */
  AcademicTracking.prototype.renderStudentHeader = function () {
    var st = this.detail;
    var d = st.data;

    var name = d ? fullName(d.student) : this.scope.label;
    var sub, chips = '';

    if (st.status === 'loading') {
      sub = '<span class="at-skel" style="width:160px;display:inline-block;height:.7rem"></span>';
    } else if (st.status === 'error') {
      sub = '<span style="color:' + PALETTE.bad + '">Details unavailable</span>';
    } else if (d) {
      var bits = [];
      if (d.student.member_code) bits.push(esc(d.student.member_code));
      if (d.class.class_name) {
        bits.push(esc(d.class.class_name) + (d.class.class_name_en ? ' · ' + esc(d.class.class_name_en) : ''));
      }
      sub = bits.join(' &nbsp;·&nbsp; ') || 'No class recorded';

      chips += '<span class="ch ch-i" title="Reporting period. Context, not a filter.">'
        + '<i class="fa-solid fa-calendar"></i> ' + esc(d.context.year_name || 'Year not set') + '</span>';
      chips += '<span class="ch ch-p" title="' + (d.context.is_annual
        ? 'Annual view: full-year subjects are combined from both semesters using the configured weights.'
        : 'Single semester view.') + '">'
        + '<i class="fa-solid fa-hourglass-half"></i> '
        + (d.context.is_annual ? 'Annual' : esc(d.context.term_name || 'Semester')) + '</span>';
      if (d.student.status) {
        chips += '<span class="ch ' + (d.student.status === 'active' ? 'ch-ok' : 'ch-d') + '">'
          + esc(titleCase(d.student.status)) + '</span>';
      }
    } else {
      sub = '';
    }

    return '<div class="crd" style="padding:1rem 1.1rem">'
      + '<div style="display:flex;gap:.8rem;align-items:flex-start;flex-wrap:wrap">'
      + '<span style="width:46px;height:46px;border-radius:13px;background:#f5f3ff;color:' + PALETTE.primary
      + ';display:inline-flex;align-items:center;justify-content:center;font-size:1.15rem;flex:none">'
      + '<i class="fa-solid fa-user-graduate" aria-hidden="true"></i></span>'
      + '<div style="flex:1;min-width:180px">'
      + '<div class="amharic" style="font-weight:800;color:#1e293b;font-size:1.05rem;line-height:1.3">' + esc(name) + '</div>'
      + '<div style="font-size:.74rem;color:#64748b;margin-top:.15rem">' + sub + '</div>'
      + '</div>'
      + '<div style="display:flex;gap:.35rem;flex-wrap:wrap;align-items:center">' + chips + '</div>'
      + '</div>'
      + '<div style="margin-top:.8rem;display:flex;gap:.4rem;flex-wrap:wrap">'
      + '<button type="button" class="btn btn-o btn-xs" data-go="students">'
      + '<i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Students</button>'
      + '<button type="button" class="btn btn-o btn-xs" data-clear-selection="1">Clear selection</button>'
      + '</div></div>';
  };

  AcademicTracking.prototype.renderSectionNav = function () {
    var self = this;
    var tabs = STUDENT_SECTIONS.map(function (key) {
      var s = SECTION_META[key];
      var on = self.section === key;
      return '<button type="button" role="tab" id="at-tab-' + key + '" '
        + 'class="at-tab' + (on ? ' at-tab-on' : '') + '" '
        + 'data-section="' + key + '" '
        + 'aria-selected="' + (on ? 'true' : 'false') + '" '
        + 'tabindex="' + (on ? '0' : '-1') + '">'
        + '<i class="fa-solid ' + s.icon + '" aria-hidden="true"></i> ' + esc(s.label)
        + '</button>';
    }).join('');
    return '<div class="at-tabs" role="tablist" aria-label="Student tracking sections" '
      + 'style="margin-top:.7rem">' + tabs + '</div>';
  };

  AcademicTracking.prototype.renderSectionBody = function () {
    // Overview, Subjects and Attendance all read the detail response, so
    // they share its loading and error states.
    if (this.section === 'assessments') return this.renderAssessments();
    if (this.section === 'report_card') return this.renderReportCard();

    var st = this.detail;
    if (st.status === 'loading' || st.status === 'idle') return this.renderDetailSkeleton();
    if (st.status === 'error') return this.renderDetailError();

    if (this.section === 'subjects') return this.renderSubjects();
    if (this.section === 'attendance') return this.renderAttendance();
    return this.renderOverview();
  };

  AcademicTracking.prototype.renderDetailError = function () {
    var st = this.detail;
    if (st.code === 'no_enrolment') {
      return this.stateBlock('fa-user-slash', PALETTE.warn,
        'This student is not enrolled in a class.',
        'Academic tracking follows a student through a class. Enrol them in a class '
        + 'and their subjects, assessments and report card become available here.', '');
    }
    // Everything else is a failure to answer, not an answer of "nothing".
    return this.stateBlock('fa-triangle-exclamation', PALETTE.bad,
      'We couldn\u2019t load this student.',
      esc(st.error) + ' This is a problem reaching the data, not a sign that the student has none.',
      '<button type="button" class="btn btn-p btn-xs" data-retry-detail="1">'
      + '<i class="fa-solid fa-rotate" aria-hidden="true"></i> Try again</button>');
  };

  AcademicTracking.prototype.renderDetailSkeleton = function () {
    var rows = '';
    for (var i = 0; i < 4; i++) {
      rows += '<div class="at-skel" style="height:.8rem;width:' + (90 - i * 12) + '%;margin:.55rem 0"></div>';
    }
    return '<div style="padding:1.1rem" aria-label="Loading student">' + rows + '</div>';
  };

  /** OVERVIEW ---------------------------------------------------------- */

  AcademicTracking.prototype.renderOverview = function () {
    var d = this.detail.data;
    var o = d.overview;
    var ds = d.data_state;

    // A small number of meaningful summaries. Each one either has an
    // authoritative value or says plainly that it does not.
    var tiles = [
      metricTile('fa-chart-simple', 'Overall average',
        o.overall_average === null ? null : fmtPct(o.overall_average),
        o.overall_grade ? 'Grade ' + esc(o.overall_grade) : '',
        ds.results === 'no_results'
          ? 'No subject has a final result yet'
          : (ds.results === 'no_subjects' ? 'No subjects are offered' : '')),
      metricTile('fa-ranking-star', 'Rank in class',
        o.rank === null ? null : ('#' + o.rank + (o.rank_tied ? ' (tied)' : '')),
        o.total_in_class ? 'of ' + o.total_in_class + ' students' : '',
        'Ranking needs a final result'),
      metricTile('fa-book-open', 'Subjects with a result',
        o.subjects_total ? (o.subjects_with_result + ' of ' + o.subjects_total) : null,
        o.subjects_pending ? (o.subjects_pending + ' still pending') : 'All subjects complete',
        'No subjects are offered for this class'),
      metricTile('fa-user-check', 'Attendance',
        d.attendance.has_attendance ? fmtPct(d.attendance.rate) : null,
        d.attendance.has_attendance ? (d.attendance.total + ' days recorded') : '',
        'No attendance has been recorded')
    ].join('');

    var notes = '';
    if (ds.results === 'no_results' && ds.subjects === 'ok') {
      notes = noteBlock('fa-circle-info',
        'Subjects are set up, but no final result exists yet.',
        'The class offers ' + o.subjects_total + ' '
        + plural(o.subjects_total, 'subject', 'subjects')
        + '. Marks have not produced a final score for any of them, so there is no '
        + 'average, grade or rank to show. Those stay blank rather than showing zero.');
    } else if (ds.subjects === 'no_subjects') {
      notes = noteBlock('fa-circle-info',
        'This class has no subjects for the selected year.',
        'Nothing can be tracked academically until subjects are offered to the class.');
    }

    return '<div style="padding:1rem">'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.6rem">'
      + tiles + '</div>'
      + notes
      + this.renderOverviewHighlights(o)
      + '</div>';
  };

  AcademicTracking.prototype.renderOverviewHighlights = function (o) {
    if (!o.strongest_subject && !o.weakest_subject) return '';
    var items = '';
    if (o.strongest_subject) {
      items += '<li style="margin:.2rem 0"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true" '
        + 'style="color:' + PALETTE.ok + ';width:1.1rem"></i> Strongest subject: <strong class="amharic">'
        + esc(o.strongest_subject) + '</strong></li>';
    }
    if (o.weakest_subject) {
      items += '<li style="margin:.2rem 0"><i class="fa-solid fa-arrow-trend-down" aria-hidden="true" '
        + 'style="color:' + PALETTE.warn + ';width:1.1rem"></i> Needs attention: <strong class="amharic">'
        + esc(o.weakest_subject) + '</strong></li>';
    }
    return '<div style="margin-top:.9rem;border-top:1px solid #f1f5f9;padding-top:.75rem">'
      + '<div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;'
      + 'letter-spacing:.03em;margin-bottom:.3rem">Highlights</div>'
      + '<ul style="list-style:none;padding:0;margin:0;font-size:.8rem;color:#475569">' + items + '</ul>'
      + '<p style="font-size:.7rem;color:#94a3b8;margin:.5rem 0 0">'
      + 'Comparison between this student\u2019s own subjects. Not a comparison with other students.</p>'
      + '</div>';
  };

  /** SUBJECTS ---------------------------------------------------------- */

  AcademicTracking.prototype.renderSubjects = function () {
    var d = this.detail.data;
    var rows = d.subjects || [];
    if (!rows.length) {
      return this.stateBlock('fa-book-open', PALETTE.muted,
        'No subjects are offered to this class.',
        'The class has no subject offerings for ' + esc(d.context.year_name || 'this year')
        + '. This is a class setup matter, not a missing mark.', '');
    }

    var isAnnual = !!d.context.is_annual;
    var body = rows.map(function (s) {
      return '<tr>'
        + '<td><div class="amharic" style="font-weight:700;color:#1e293b">' + esc(s.subject_name) + '</div>'
        + (s.subject_name_en ? '<div style="font-size:.7rem;color:#94a3b8">' + esc(s.subject_name_en) + '</div>' : '')
        + '</td>'
        + '<td>' + durationChip(s.duration_type) + '</td>'
        + (isAnnual ? '<td style="text-align:right">' + numOrDash(s.semester_1_score) + '</td>'
          + '<td style="text-align:right">' + numOrDash(s.semester_2_score) + '</td>' : '')
        + '<td style="text-align:right;font-weight:700">' + numOrDash(s.final_percentage) + '</td>'
        + '<td>' + (s.grade_letter ? gradeChip(s.grade_letter) : dash()) + '</td>'
        + '<td>' + subjectStatusChip(s) + '</td>'
        + '</tr>';
    }).join('');

    var head = '<th>Subject</th><th>Duration</th>'
      + (isAnnual ? '<th style="text-align:right">Sem 1</th><th style="text-align:right">Sem 2</th>' : '')
      + '<th style="text-align:right">Final</th><th>Grade</th><th>Status</th>';

    var w = d.context.semester_weights;
    var weightNote = (isAnnual && w)
      ? '<p style="font-size:.71rem;color:#94a3b8;margin:.6rem 1rem 0">'
        + 'Full-year subjects combine Semester 1 and Semester 2 at the weights configured for this '
        + 'academic year (' + esc(w.s1) + '% / ' + esc(w.s2) + '%). Semester-only subjects are '
        + 'final on their own semester. Both are calculated by the report card engine, not here.</p>'
      : '';

    return this.chartBlock(rows)
      + '<div class="tw"><table class="dt"><thead><tr>' + head + '</tr></thead><tbody>'
      + body + '</tbody></table></div>'
      + weightNote
      + '<p style="font-size:.71rem;color:#94a3b8;margin:.35rem 1rem 1rem">'
      + 'A dash means no value exists yet. It never means zero.</p>';
  };

  /**
   * One chart, and only because it answers a question the table answers
   * slowly: which subjects stand apart. It is drawn from the same rows the
   * table shows, it is skipped entirely when Chart.js is absent or nothing
   * has a result, and the table underneath is always the record.
   */
  AcademicTracking.prototype.chartBlock = function (rows) {
    var scored = rows.filter(function (s) { return s.final_percentage !== null; });
    if (scored.length < 2) return '';
    var summary = scored.map(function (s) {
      return s.subject_name + ' ' + fmtPct(s.final_percentage);
    }).join(', ');
    return '<div style="padding:1rem 1rem 0">'
      + '<div style="font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;'
      + 'letter-spacing:.03em;margin-bottom:.4rem">Final result by subject (%)</div>'
      + '<div style="position:relative;height:200px"><canvas id="at-subject-chart" '
      + 'role="img" aria-label="Final result by subject. ' + esc(summary) + '"></canvas></div>'
      + '<p class="at-sr-text" style="font-size:.71rem;color:#94a3b8;margin:.4rem 0 0">'
      + esc(summary) + '. Pass mark ' + esc(this.detail.data.pass_mark) + '%.</p>'
      + '</div>';
  };

  AcademicTracking.prototype.drawSubjectChart = function () {
    if (typeof Chart === 'undefined') return;
    var root = this.root();
    if (!root) return;
    var canvas = root.querySelector ? root.querySelector('#at-subject-chart') : null;
    if (!canvas || typeof canvas.getContext !== 'function') return;

    var rows = (this.detail.data.subjects || []).filter(function (s) {
      return s.final_percentage !== null;
    });
    if (rows.length < 2) return;
    var pass = this.detail.data.pass_mark;

    if (this._chart) { try { this._chart.destroy(); } catch (e) { /* no-op */ } }
    this._chart = new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: rows.map(function (s) { return s.subject_name; }),
        datasets: [{
          label: 'Final %',
          data: rows.map(function (s) { return s.final_percentage; }),
          // Tone marks pass/fail, but the grade letter in the table carries
          // the same meaning in text — colour is never the only signal.
          backgroundColor: rows.map(function (s) {
            return s.final_percentage >= pass ? PALETTE.ok : PALETTE.bad;
          }),
          borderRadius: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: prefersReducedMotion() ? false : { duration: 220 },
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, max: 100, ticks: { callback: function (v) { return v + '%'; } } } }
      }
    });
  };

  /** ASSESSMENTS ------------------------------------------------------- */

  AcademicTracking.prototype.renderAssessments = function () {
    var st = this.lazy.assessments;
    if (st.status === 'idle' || st.status === 'loading') return this.renderDetailSkeleton();
    if (st.status === 'error') {
      return this.stateBlock('fa-triangle-exclamation', PALETTE.bad,
        'We couldn\u2019t load the assessments.',
        esc(st.error) + ' This is a loading failure, not an empty assessment list.',
        '<button type="button" class="btn btn-p btn-xs" data-retry-section="assessments">'
        + '<i class="fa-solid fa-rotate" aria-hidden="true"></i> Try again</button>');
    }
    var rows = (st.data && st.data.rows) || [];
    if (!rows.length) {
      return this.stateBlock('fa-clipboard-list', PALETTE.muted,
        'No assessments have been created yet.',
        'No test, assignment or exam exists for this student\u2019s subjects in this academic '
        + 'context. There is nothing to mark yet, which is different from marks being missing.', '');
    }

    var body = rows.map(function (a) {
      return '<tr>'
        + '<td><div class="amharic" style="color:#64748b;font-size:.74rem">' + esc(a.subject_name) + '</div>'
        + '<div style="font-weight:700;color:#1e293b">' + esc(a.assessment_name) + '</div></td>'
        + '<td style="text-align:right;color:#64748b">' + (a.weight === null ? dash() : esc(a.weight) + '%') + '</td>'
        + '<td>' + workflowChip(a.workflow_status, a.workflow_label) + '</td>'
        + '<td style="text-align:right">'
        + (a.has_result
          ? '<strong>' + esc(a.score) + '</strong>'
            + (a.max_score !== null ? '<span style="color:#94a3b8"> / ' + esc(a.max_score) + '</span>' : '')
          : dash())
        + '</td>'
        + '<td style="text-align:right">' + (a.percentage === null ? dash() : esc(a.percentage) + '%') + '</td>'
        + '</tr>';
    }).join('');

    var gaps = (st.data.unplanned_weight || []);
    var gapNote = gaps.length
      ? noteBlock('fa-circle-exclamation', 'Some subject weight is not yet assigned to an assessment.',
        gaps.map(function (g) {
          return g.subject_name + ' (' + g.weight + '% unassigned)';
        }).join(', ') + '. Until an assessment claims that weight, the subject cannot reach a complete result.')
      : '';

    return '<div class="tw"><table class="dt"><thead><tr>'
      + '<th>Assessment</th><th style="text-align:right">Weight</th>'
      + '<th>Mark list status</th><th style="text-align:right">Score</th>'
      + '<th style="text-align:right">Result</th>'
      + '</tr></thead><tbody>' + body + '</tbody></table></div>'
      + '<div style="padding:0 1rem 1rem">' + gapNote
      + '<p style="font-size:.71rem;color:#94a3b8;margin:.6rem 0 0">'
      + '<strong>Mark list status</strong> is where the teacher\u2019s submission has reached in the '
      + 'review workflow, for the whole class. <strong>Score</strong> and <strong>Result</strong> are '
      + 'this student\u2019s own marks. An approved mark list can still have no score for one student, '
      + 'and a score can exist before the list is approved \u2014 so the two are reported separately.</p>'
      + '</div>';
  };

  /** ATTENDANCE -------------------------------------------------------- */

  AcademicTracking.prototype.renderAttendance = function () {
    var d = this.detail.data;
    var a = d.attendance;

    if (!a.has_attendance) {
      return this.stateBlock('fa-calendar-xmark', PALETTE.muted,
        'No attendance has been recorded.',
        'Not one attendance row exists for this student in ' + esc(d.context.year_name || 'this year')
        + '. That is not the same as an attendance rate of 0% \u2014 nobody has taken the register, '
        + 'so there is no rate to report.', '');
    }

    var parts = [
      { key: 'present', label: 'Present', tone: PALETTE.ok, icon: 'fa-circle-check' },
      { key: 'absent', label: 'Absent', tone: PALETTE.bad, icon: 'fa-circle-xmark' },
      { key: 'late', label: 'Late', tone: PALETTE.warn, icon: 'fa-clock' },
      { key: 'excused', label: 'Excused', tone: PALETTE.primary, icon: 'fa-file-circle-check' }
    ];

    var tiles = parts.map(function (p) {
      return '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:.65rem .75rem">'
        + '<div style="font-size:.7rem;color:#64748b;display:flex;align-items:center;gap:.3rem">'
        + '<i class="fa-solid ' + p.icon + '" style="color:' + p.tone + '" aria-hidden="true"></i> '
        + p.label + '</div>'
        + '<div style="font-size:1.15rem;font-weight:800;color:#1e293b">' + esc(a[p.key]) + '</div>'
        + '<div style="font-size:.68rem;color:#94a3b8">' + plural(a[p.key], 'day', 'days') + '</div>'
        + '</div>';
    }).join('');

    // A proportional bar, labelled in text beside it. Never colour alone.
    var segs = parts.map(function (p) {
      var pctOf = a.total > 0 ? (a[p.key] / a.total) * 100 : 0;
      if (pctOf <= 0) return '';
      return '<div style="width:' + pctOf + '%;background:' + p.tone + '" '
        + 'title="' + p.label + ': ' + a[p.key] + '"></div>';
    }).join('');

    return '<div style="padding:1rem">'
      + '<div style="display:flex;align-items:baseline;gap:.5rem;margin-bottom:.6rem">'
      + '<span style="font-size:1.6rem;font-weight:800;color:#1e293b">' + esc(fmtPct(a.rate)) + '</span>'
      + '<span style="font-size:.76rem;color:#64748b">attendance rate across '
      + esc(a.total) + ' recorded ' + plural(a.total, 'day', 'days') + '</span></div>'
      + '<div style="display:flex;height:10px;border-radius:99px;overflow:hidden;background:#f1f5f9;margin-bottom:.8rem" '
      + 'role="img" aria-label="Present ' + esc(a.present) + ', absent ' + esc(a.absent)
      + ', late ' + esc(a.late) + ', excused ' + esc(a.excused) + '">' + segs + '</div>'
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.55rem">'
      + tiles + '</div>'
      + '<p style="font-size:.71rem;color:#94a3b8;margin:.75rem 0 0">'
      + 'The rate counts present and late days as attended, exactly as the report card engine '
      + 'computes it. It is not recalculated here.</p>'
      + '</div>';
  };

  /** REPORT CARD ------------------------------------------------------- */

  AcademicTracking.prototype.renderReportCard = function () {
    var st = this.lazy.report_card;
    if (st.status === 'idle' || st.status === 'loading') return this.renderDetailSkeleton();
    if (st.status === 'error') {
      return this.stateBlock('fa-triangle-exclamation', PALETTE.bad,
        'We couldn\u2019t load the report card.',
        esc(st.error) + ' This is a loading failure, not an empty report card.',
        '<button type="button" class="btn btn-p btn-xs" data-retry-section="report_card">'
        + '<i class="fa-solid fa-rotate" aria-hidden="true"></i> Try again</button>');
    }

    var c = st.data;
    var subjects = c.subjects || [];
    if (!subjects.length) {
      return this.stateBlock('fa-file-circle-question', PALETTE.muted,
        'No report card is available yet.',
        'The report card engine returned no subject lines for this student in this academic '
        + 'context.', '');
    }

    var t = c.totals || {};
    var isAnnual = !!t.is_annual;

    var body = subjects.map(function (s) {
      return '<tr>'
        + '<td class="amharic" style="font-weight:700;color:#1e293b">' + esc(s.subject_name) + '</td>'
        + (isAnnual ? '<td style="text-align:right">' + numOrDash(s.semester_1_score) + '</td>'
          + '<td style="text-align:right">' + numOrDash(s.semester_2_score) + '</td>' : '')
        + '<td style="text-align:right;font-weight:700">' + numOrDash(s.final_percentage) + '</td>'
        + '<td>' + (s.grade_letter ? gradeChip(s.grade_letter) : dash()) + '</td>'
        + '<td>' + (s.final_percentage === null
          ? '<span class="ch ch-d">Not final</span>'
          : (s.final_percentage >= c.pass_mark
            ? '<span class="ch ch-ok">Pass</span>'
            : '<span class="ch ch-w">Below pass mark</span>')) + '</td>'
        + '</tr>';
    }).join('');

    var head = '<th>Subject</th>'
      + (isAnnual ? '<th style="text-align:right">Sem 1</th><th style="text-align:right">Sem 2</th>' : '')
      + '<th style="text-align:right">Final</th><th>Grade</th><th>Outcome</th>';

    var rank = intOr(c.rank, 0);
    var summary = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));'
      + 'gap:.6rem;padding:1rem 1rem 0">'
      + metricTile('fa-chart-simple', 'Overall',
        t.average === null || t.average === undefined ? null : fmtPct(t.average),
        t.grade_letter ? 'Grade ' + esc(t.grade_letter) : '', 'No final result yet')
      + metricTile('fa-ranking-star', 'Rank',
        rank ? '#' + rank + (c.rank_tied ? ' (tied)' : '') : null,
        c.total_in_class ? 'of ' + esc(c.total_in_class) : '', 'Not available')
      + metricTile('fa-check-double', 'Pass mark', esc(c.pass_mark) + '%', 'Set by the engine', '')
      + '</div>';

    return summary
      + '<div class="tw" style="margin-top:.8rem"><table class="dt"><thead><tr>' + head
      + '</tr></thead><tbody>' + body + '</tbody></table></div>'
      + '<p style="font-size:.71rem;color:#94a3b8;margin:.6rem 1rem 1rem">'
      + 'Served by the existing report card endpoint and produced by ReportCardService \u2014 the same '
      + 'numbers the printed report card carries. Academic Tracking displays them, it does not '
      + 'recalculate them.</p>';
  };

  AcademicTracking.ENTITIES = ENTITIES;
  AcademicTracking.ORDER = ORDER;
  AcademicTracking.STUDENT_SECTIONS = STUDENT_SECTIONS;

  global.AcademicTracking = AcademicTracking;
  if (!global.AcademicTrackingInstance) {
    global.AcademicTrackingInstance = new AcademicTracking();
  }
}(typeof window !== 'undefined' ? window : this));
