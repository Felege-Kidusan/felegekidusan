/**
 * ============================================================================
 * Academic Intelligence Workspace
 * ============================================================================
 * One workspace, four perspectives on the same academic data:
 *
 *   STUDENT  one learner across subjects
 *   TEACHER  one teacher across the classes and subjects they are assigned
 *   SUBJECT  one subject across every class that offers it
 *   CLASS    one class across its subjects and students
 *
 * They are not four pages. The perspective is a parameter on one endpoint
 * (api_education.php?action=get_academic_intelligence), the filter bar is
 * shared, and every table cell that names another entity is a drill-down
 * into the perspective that explains it:
 *
 *   Teacher -> Class -> Subject -> Students
 *   Subject -> Classes -> Teacher -> Students
 *   Class   -> Subjects -> Students
 *   Student -> Subjects -> Assessments / attendance
 *
 * NO ACADEMIC CALCULATION HAPPENS IN THIS FILE. Averages, grade letters,
 * semester weighting, pass rates, ranks and attendance rates all arrive
 * computed by ReportCardService through AcademicIntelligenceService. This
 * controller formats and draws them. A number that is null is rendered as
 * "—" and never as 0, because a subject that has not been graded yet is not
 * a zero.
 *
 * Depends on Chart.js, which edu_dept.php already loads.
 */
(function (global) {
  'use strict';

  var ENDPOINT = '/admin/api_education.php?action=get_academic_intelligence';
  var OPTIONS_ENDPOINT = '/admin/api_education.php?action=get_academic_intelligence_options';

  var PALETTE = {
    brand: '#600000',
    primary: '#7c3aed',
    ok: '#059669',
    warn: '#f59e0b',
    bad: '#dc2626',
    info: '#2563eb',
    muted: '#94a3b8'
  };
  var GRADE_COLORS = {
    A: '#059669', B: '#2563eb', C: '#7c3aed', D: '#f59e0b', F: '#dc2626'
  };

  function esc(value) {
    if (value === null || value === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(value);
    return d.innerHTML;
  }

  /** The operating system setting wins over anything decorative here. */
  function prefersReducedMotion() {
    try {
      return !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (e) {
      return false;
    }
  }

  /**
   * A small stylesheet scoped to this workspace. It is injected rather than
   * added to the dashboard's global CSS so that nothing outside the section
   * changes appearance: the surrounding pages keep whatever focus treatment
   * they already have.
   */
  var STYLE_ID = 'ai-workspace-style';
  function injectStyle(containerId) {
    if (document.getElementById(STYLE_ID)) return;
    var css =
      '#' + containerId + ' button:focus-visible,'
      + '#' + containerId + ' select:focus-visible,'
      + '#' + containerId + ' input:focus-visible{'
      + 'outline:3px solid #7c3aed;outline-offset:2px;border-radius:6px}'
      // Rows are wide; a hover cue keeps the eye on one record while
      // scanning across eight columns.
      + '#' + containerId + ' tbody tr:hover{background:#fafbff}'
      + '@media (prefers-reduced-motion: reduce){'
      + '#' + containerId + ' *{animation:none !important;transition:none !important}}';
    var el = document.createElement('style');
    el.id = STYLE_ID;
    el.textContent = css;
    (document.head || document.body || document.documentElement).appendChild(el);
  }

  /** A missing measurement is a dash, never a zero. */
  function num(value, suffix) {
    if (value === null || value === undefined || value === '') return '—';
    var n = Number(value);
    if (!isFinite(n)) return '—';
    return (Math.round(n * 10) / 10) + (suffix || '');
  }

  function pct(value) { return num(value, '%'); }

  function intOr(value, fallback) {
    var n = parseInt(value, 10);
    return isNaN(n) ? fallback : n;
  }

  function gradeChip(letter) {
    if (!letter) return '<span class="ch ch-d" title="Not graded yet">—</span>';
    var map = { A: 'ch-ok', B: 'ch-i', C: 'ch-p', D: 'ch-w', F: 'ch-d' };
    return '<span class="ch ' + (map[letter] || 'ch-d') + '">' + esc(letter) + '</span>';
  }

  function statusChip(status) {
    if (!status) return '';
    var map = {
      CLOSED: ['ch-ok', 'Final'],
      CONTINUING: ['ch-i', 'Still running'],
      PENDING: ['ch-w', 'Pending']
    };
    var cfg = map[status] || ['ch-d', status];
    return '<span class="ch ' + cfg[0] + '" title="' + esc(status) + '">' + esc(cfg[1]) + '</span>';
  }

  function durationChip(duration) {
    if (!duration) return '<span class="ch ch-d" title="Not classified yet">Unclassified</span>';
    if (duration === 'FULL_YEAR') return '<span class="ch ch-p">Full year</span>';
    if (duration === 'SEMESTER_ONLY') return '<span class="ch ch-i">One semester</span>';
    return '<span class="ch ch-d">' + esc(duration) + '</span>';
  }

  function completionBar(completion) {
    if (!completion || completion.recorded === null || completion.recorded === undefined) {
      return '<span style="color:#94a3b8">—</span>';
    }
    var v = Math.max(0, Math.min(100, Number(completion.recorded) || 0));
    var colour = v >= 80 ? PALETTE.ok : (v >= 40 ? PALETTE.warn : PALETTE.bad);
    return '<div style="display:flex;align-items:center;gap:.4rem">'
      + '<div style="flex:1;min-width:48px;height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden">'
      + '<div style="width:' + v + '%;height:100%;background:' + colour + '"></div></div>'
      + '<span style="font-size:.7rem;color:#475569;white-space:nowrap">' + num(v, '%') + '</span></div>';
  }

  function distributionBar(dist) {
    if (!dist) return '—';
    var total = ['A', 'B', 'C', 'D', 'F'].reduce(function (s, k) { return s + (dist[k] || 0); }, 0);
    if (!total) return '<span style="color:#94a3b8">No grades yet</span>';
    var html = '<div style="display:flex;height:8px;border-radius:99px;overflow:hidden;min-width:70px" '
      + 'role="img" aria-label="Grade spread: '
      + ['A', 'B', 'C', 'D', 'F'].map(function (k) { return k + ' ' + (dist[k] || 0); }).join(', ') + '">';
    ['A', 'B', 'C', 'D', 'F'].forEach(function (k) {
      var n = dist[k] || 0;
      if (!n) return;
      html += '<div title="' + k + ': ' + n + '" style="width:' + ((n / total) * 100)
        + '%;background:' + GRADE_COLORS[k] + '"></div>';
    });
    return html + '</div>';
  }

  function kpi(label, value, sub, tone) {
    var colour = tone === 'bad' ? PALETTE.bad : (tone === 'warn' ? PALETTE.warn : '#1e293b');
    return '<div class="crd" style="padding:.85rem 1rem;margin:0">'
      + '<div style="font-size:.68rem;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.03em">'
      + esc(label) + '</div>'
      + '<div style="font-size:1.45rem;font-weight:800;color:' + colour + ';line-height:1.25">' + value + '</div>'
      + (sub ? '<div style="font-size:.68rem;color:#94a3b8">' + sub + '</div>' : '')
      + '</div>';
  }

  function emptyState(icon, title, message) {
    return '<div style="text-align:center;padding:2.5rem 1rem;color:#94a3b8">'
      + '<i class="fa-solid ' + icon + '" style="font-size:2rem;opacity:.45"></i>'
      + '<div style="margin-top:.6rem;font-weight:700;color:#64748b">' + esc(title) + '</div>'
      + '<div style="font-size:.78rem;margin-top:.2rem">' + esc(message) + '</div></div>';
  }

  // ══════════════════════════════════════════════════════════════════════

  function AcademicIntelligence(options) {
    this.options = Object.assign({ containerId: 'sec-academic-intel' }, options || {});
    this.perspective = 'class';
    this.state = {
      year_id: 0,
      term_id: 0,
      class_id: 0,
      subject_id: 0,
      teacher_id: 0,
      member_id: 0,
      gender: '',
      grade_letter: '',
      min_grade: '',
      max_grade: '',
      min_attendance: '',
      max_attendance: '',
      search: ''
    };
    this.catalogue = { years: [], terms: [], classes: [], subjects: [], teachers: [] };
    this.data = null;
    this.charts = {};
    this.trail = [];
    this._seq = 0;
    this._searchTimer = null;
    this._booted = false;
  }

  AcademicIntelligence.prototype.root = function () {
    return document.getElementById(this.options.containerId);
  };

  /** Called the first time the section is opened. */
  AcademicIntelligence.prototype.boot = function () {
    if (this._booted) return Promise.resolve();
    this._booted = true;
    var self = this;
    this.renderShell();
    return this.loadCatalogue().then(function () {
      return self.load();
    });
  };

  AcademicIntelligence.prototype.loadCatalogue = function () {
    var self = this;
    var url = OPTIONS_ENDPOINT + (this.state.year_id ? '&year_id=' + this.state.year_id : '');
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.status !== 'success') throw new Error((d && d.message) || 'Could not load filters');
        self.catalogue = {
          years: d.years || [], terms: d.terms || [], classes: d.classes || [],
          subjects: d.subjects || [], teachers: d.teachers || []
        };
        if (!self.state.year_id) {
          var cur = self.catalogue.years.filter(function (y) { return y.is_current; })[0];
          self.state.year_id = cur ? cur.id : (self.catalogue.years[0] ? self.catalogue.years[0].id : 0);
        }
        if (!self.state.class_id && self.catalogue.classes.length) {
          self.state.class_id = self.catalogue.classes[0].id;
        }
        if (!self.state.subject_id && self.catalogue.subjects.length) {
          self.state.subject_id = self.catalogue.subjects[0].id;
        }
        if (!self.state.teacher_id && self.catalogue.teachers.length) {
          self.state.teacher_id = self.catalogue.teachers[0].id;
        }
        self.renderFilters();
      })
      .catch(function (e) {
        var bar = document.getElementById('aiFilterBar');
        if (bar) {
          bar.innerHTML = '<div style="color:#dc2626;font-size:.8rem;padding:.5rem">'
            + '<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(e.message) + '</div>';
        }
      });
  };

  // ── shell ─────────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.renderShell = function () {
    var root = this.root();
    if (!root) return;
    injectStyle(this.options.containerId);
    var tabs = [
      ['student', 'fa-user-graduate', 'Student', 'ተማሪ'],
      ['teacher', 'fa-chalkboard-user', 'Teacher', 'መምህር'],
      ['subject', 'fa-book-open', 'Subject', 'ትምህርት'],
      ['class', 'fa-school', 'Class', 'ክፍል']
    ];
    root.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">'
      + '<div><h2 style="font-size:1.25rem;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:.5rem">'
      + '<span style="width:34px;height:34px;border-radius:10px;background:' + PALETTE.brand
      + ';color:#fff;display:inline-flex;align-items:center;justify-content:center"><i class="fa-solid fa-diagram-project"></i></span>'
      + '<span>Academic Intelligence</span></h2>'
      + '<p style="font-size:.75rem;color:#64748b" class="amharic">አንድ መረጃ፤ አራት እይታዎች — በተማሪ፣ በመምህር፣ በትምህርት እና በክፍል</p></div>'
      + '<button class="btn btn-o btn-xs" type="button" id="aiRefresh"><i class="fa-solid fa-rotate"></i> Refresh</button>'
      + '</div>'

      + '<div role="tablist" aria-label="Analysis perspective" id="aiTabs" '
      + 'style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.85rem">'
      + tabs.map(function (t) {
        return '<button role="tab" type="button" class="btn btn-o ai-tab" data-perspective="' + t[0] + '" '
          + 'aria-selected="false" style="flex:1 1 130px;justify-content:center">'
          + '<i class="fa-solid ' + t[1] + '"></i> <span>' + t[2] + '</span>'
          + '<span class="amharic" style="opacity:.7;font-size:.7rem">' + t[3] + '</span></button>';
      }).join('')
      + '</div>'

      + '<nav id="aiTrail" aria-label="Drill-down trail" style="display:none;margin-bottom:.6rem;font-size:.75rem;color:#64748b"></nav>'
      + '<div class="crd no-print" style="padding:.9rem" id="aiFilterBar"></div>'
      + '<div id="aiBody" aria-live="polite" aria-busy="false"></div>';

    var self = this;
    var tabEls = root.querySelectorAll('.ai-tab');
    tabEls.forEach(function (btn, i) {
      btn.addEventListener('click', function () {
        self.trail = [];
        self.setPerspective(btn.getAttribute('data-perspective'));
      });
      // role="tab" promises arrow-key navigation; without it the strip
      // announces itself as a tablist and then does not behave like one.
      btn.addEventListener('keydown', function (ev) {
        var step = ev.key === 'ArrowRight' ? 1 : (ev.key === 'ArrowLeft' ? -1 : 0);
        var jump = ev.key === 'Home' ? 0 : (ev.key === 'End' ? tabEls.length - 1 : null);
        if (!step && jump === null) return;
        ev.preventDefault();
        var next = jump !== null ? jump : ((i + step + tabEls.length) % tabEls.length);
        var target = tabEls[next];
        self.trail = [];
        self.setPerspective(target.getAttribute('data-perspective'));
        var refreshed = self.root().querySelectorAll('.ai-tab')[next];
        if (refreshed && refreshed.focus) refreshed.focus();
      });
    });
    var refresh = document.getElementById('aiRefresh');
    if (refresh) refresh.addEventListener('click', function () { self.load(); });
    this.syncTabs();
  };

  AcademicIntelligence.prototype.syncTabs = function () {
    var self = this;
    var root = this.root();
    if (!root) return;
    root.querySelectorAll('.ai-tab').forEach(function (b) {
      var on = b.getAttribute('data-perspective') === self.perspective;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.setAttribute('tabindex', on ? '0' : '-1');
      b.className = 'btn ai-tab ' + (on ? 'btn-p' : 'btn-o');
      b.style.cssText = 'flex:1 1 130px;justify-content:center';
    });
  };

  AcademicIntelligence.prototype.setPerspective = function (perspective, patch) {
    if (perspective) this.perspective = perspective;
    if (patch) Object.assign(this.state, patch);
    this.syncTabs();
    this.renderFilters();
    this.load();
  };

  // ── filters ───────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.renderFilters = function () {
    var bar = document.getElementById('aiFilterBar');
    if (!bar) return;
    var s = this.state;
    var self = this;

    function select(id, label, items, value, valueKey, labelKey, placeholder) {
      var opts = (placeholder ? '<option value="0">' + esc(placeholder) + '</option>' : '')
        + items.map(function (it) {
          var v = it[valueKey];
          return '<option value="' + esc(v) + '"' + (String(v) === String(value) ? ' selected' : '') + '>'
            + esc(it[labelKey]) + '</option>';
        }).join('');
      return '<div><label class="lbl" for="' + id + '">' + esc(label) + '</label>'
        + '<select class="inp" id="' + id + '">' + opts + '</select></div>';
    }

    var html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.65rem">';
    html += select('aiYear', 'Academic year', this.catalogue.years, s.year_id, 'id', 'year_name');
    html += '<div><label class="lbl" for="aiTerm">Term</label><select class="inp" id="aiTerm">'
      + '<option value="0"' + (!s.term_id ? ' selected' : '') + '>Annual (full year)</option>'
      + this.catalogue.terms.map(function (t) {
        return '<option value="' + t.id + '"' + (String(t.id) === String(s.term_id) ? ' selected' : '') + '>'
          + esc(t.term_name) + '</option>';
      }).join('') + '</select></div>';

    // The subject of the analysis depends on the perspective.
    if (this.perspective === 'teacher') {
      html += select('aiTeacher', 'Teacher', this.catalogue.teachers, s.teacher_id, 'id', 'full_name',
        this.catalogue.teachers.length ? '' : 'No teacher has an assignment');
    } else if (this.perspective === 'subject') {
      html += select('aiSubject', 'Subject', this.catalogue.subjects, s.subject_id, 'id', 'subject_name');
    } else {
      html += select('aiClass', 'Class', this.catalogue.classes, s.class_id, 'id', 'class_name');
      if (this.perspective === 'student') {
        html += '<div><label class="lbl" for="aiStudent">Student</label>'
          + '<select class="inp" id="aiStudent"><option value="0">Loading…</option></select></div>';
      }
    }

    // Cohort filters only mean something where a cohort is shown.
    if (this.perspective === 'class') {
      html += '<div><label class="lbl" for="aiGender">Gender</label><select class="inp" id="aiGender">'
        + ['', 'male', 'female'].map(function (g) {
          return '<option value="' + g + '"' + (g === s.gender ? ' selected' : '') + '>'
            + (g === '' ? 'All' : (g.charAt(0).toUpperCase() + g.slice(1))) + '</option>';
        }).join('') + '</select></div>';
      html += '<div><label class="lbl" for="aiGradeLetter">Grade</label><select class="inp" id="aiGradeLetter">'
        + ['', 'A', 'B', 'C', 'D', 'F'].map(function (g) {
          return '<option value="' + g + '"' + (g === s.grade_letter ? ' selected' : '') + '>'
            + (g === '' ? 'All' : g) + '</option>';
        }).join('') + '</select></div>';
      html += '<div><label class="lbl" for="aiMinGrade">Result from %</label>'
        + '<input class="inp" id="aiMinGrade" type="number" min="0" max="100" inputmode="numeric" '
        + 'value="' + esc(s.min_grade) + '" placeholder="0"></div>';
      html += '<div><label class="lbl" for="aiMaxGrade">Result to %</label>'
        + '<input class="inp" id="aiMaxGrade" type="number" min="0" max="100" inputmode="numeric" '
        + 'value="' + esc(s.max_grade) + '" placeholder="100"></div>';
      html += '<div><label class="lbl" for="aiMinAtt">Attendance from %</label>'
        + '<input class="inp" id="aiMinAtt" type="number" min="0" max="100" inputmode="numeric" '
        + 'value="' + esc(s.min_attendance) + '" placeholder="0"></div>';
      html += '<div><label class="lbl" for="aiSearch">Search student</label>'
        + '<input class="inp" id="aiSearch" type="search" value="' + esc(s.search) + '" '
        + 'placeholder="Name or code"></div>';
    }
    html += '</div>';

    if (this.perspective === 'class') {
      html += '<div style="margin-top:.6rem;display:flex;justify-content:flex-end">'
        + '<button class="btn btn-o btn-xs" type="button" id="aiClearFilters">'
        + '<i class="fa-solid fa-filter-circle-xmark"></i> Clear filters</button></div>';
    }
    bar.innerHTML = html;

    function bind(id, key, immediate) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('change', function () {
        self.state[key] = el.value;
        if (key === 'year_id') {
          self.state.term_id = 0;
          self.loadCatalogue().then(function () { self.load(); });
          return;
        }
        self.load();
      });
      if (immediate) {
        el.addEventListener('input', function () {
          clearTimeout(self._searchTimer);
          self._searchTimer = setTimeout(function () {
            self.state[key] = el.value;
            self.load();
          }, 350);
        });
      }
    }

    bind('aiYear', 'year_id');
    bind('aiTerm', 'term_id');
    bind('aiClass', 'class_id');
    bind('aiSubject', 'subject_id');
    bind('aiTeacher', 'teacher_id');
    bind('aiGender', 'gender');
    bind('aiGradeLetter', 'grade_letter');
    bind('aiMinGrade', 'min_grade');
    bind('aiMaxGrade', 'max_grade');
    bind('aiMinAtt', 'min_attendance');
    bind('aiSearch', 'search', true);

    var studentSel = document.getElementById('aiStudent');
    if (studentSel) {
      studentSel.addEventListener('change', function () {
        self.state.member_id = studentSel.value;
        self.load();
      });
      this.populateStudents();
    }

    var clear = document.getElementById('aiClearFilters');
    if (clear) {
      clear.addEventListener('click', function () {
        Object.assign(self.state, {
          gender: '', grade_letter: '', min_grade: '', max_grade: '',
          min_attendance: '', max_attendance: '', search: ''
        });
        self.renderFilters();
        self.load();
      });
    }
  };

  /**
   * The student picker is filled from the class roster the class
   * perspective already returns, so no extra endpoint is needed.
   */
  AcademicIntelligence.prototype.populateStudents = function () {
    var self = this;
    var sel = document.getElementById('aiStudent');
    if (!sel || !this.state.class_id) return;
    var qs = '&perspective=class&class_id=' + encodeURIComponent(this.state.class_id)
      + '&year_id=' + encodeURIComponent(this.state.year_id)
      + '&term_id=' + encodeURIComponent(this.state.term_id)
      + '&per_page=200';
    fetch(ENDPOINT + qs, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var students = (d && d.drilldown && d.drilldown.students) || [];
        if (!students.length) {
          sel.innerHTML = '<option value="0">No students in this class</option>';
          return;
        }
        sel.innerHTML = students.map(function (st) {
          var label = st.student_name + (st.father_name ? ' ' + st.father_name : '')
            + (st.member_code ? ' · ' + st.member_code : '');
          return '<option value="' + st.member_id + '"'
            + (String(st.member_id) === String(self.state.member_id) ? ' selected' : '')
            + '>' + esc(label) + '</option>';
        }).join('');
        if (!self.state.member_id || !students.some(function (s) {
          return String(s.member_id) === String(self.state.member_id);
        })) {
          self.state.member_id = students[0].member_id;
          sel.value = String(self.state.member_id);
          self.load();
        }
      })
      .catch(function () {
        sel.innerHTML = '<option value="0">Could not load students</option>';
      });
  };

  // ── load ──────────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.buildQs = function () {
    var s = this.state;
    var parts = [
      'perspective=' + encodeURIComponent(this.perspective),
      'year_id=' + encodeURIComponent(s.year_id || 0),
      'term_id=' + encodeURIComponent(s.term_id || 0)
    ];
    if (this.perspective === 'student') parts.push('member_id=' + encodeURIComponent(s.member_id || 0));
    if (this.perspective === 'teacher') parts.push('teacher_id=' + encodeURIComponent(s.teacher_id || 0));
    if (this.perspective === 'subject') parts.push('subject_id=' + encodeURIComponent(s.subject_id || 0));
    if (this.perspective === 'class' || this.perspective === 'student') {
      parts.push('class_id=' + encodeURIComponent(s.class_id || 0));
    }
    if (this.perspective === 'class') {
      ['gender', 'grade_letter', 'min_grade', 'max_grade', 'min_attendance', 'max_attendance', 'search']
        .forEach(function (k) {
          if (s[k] !== '' && s[k] !== null && s[k] !== undefined) {
            parts.push(k + '=' + encodeURIComponent(s[k]));
          }
        });
    }
    return '&' + parts.join('&');
  };

  AcademicIntelligence.prototype.load = function () {
    var self = this;
    var body = document.getElementById('aiBody');
    if (!body) return Promise.resolve();

    if (this.perspective === 'student' && !this.state.member_id) {
      body.innerHTML = emptyState('fa-user-graduate', 'Choose a student',
        'Pick a class, then a student, to see their results.');
      return Promise.resolve();
    }
    if (this.perspective === 'teacher' && !this.state.teacher_id) {
      body.innerHTML = emptyState('fa-chalkboard-user', 'No teacher selected',
        'No teacher currently holds an assignment for this academic year.');
      return Promise.resolve();
    }

    var seq = ++this._seq;
    body.setAttribute('aria-busy', 'true');
    body.innerHTML = '<div class="crd" style="padding:2.5rem;text-align:center;color:#94a3b8">'
      + '<i class="fa-solid fa-circle-notch fa-spin" style="font-size:1.6rem"></i>'
      + '<div style="margin-top:.6rem;font-size:.82rem">Building the analysis…</div></div>';

    return fetch(ENDPOINT + this.buildQs(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (seq !== self._seq) return; // a newer request already won
        body.setAttribute('aria-busy', 'false');
        if (!d || d.status !== 'success') {
          body.innerHTML = '<div class="crd" style="padding:1.5rem">'
            + emptyState('fa-triangle-exclamation', 'Could not build this analysis',
              (d && d.message) || 'The server rejected the request.')
            + '</div>';
          return;
        }
        self.data = d;
        self.render();
      })
      .catch(function (e) {
        if (seq !== self._seq) return;
        body.setAttribute('aria-busy', 'false');
        body.innerHTML = '<div class="crd" style="padding:1.5rem">'
          + emptyState('fa-plug-circle-xmark', 'Connection problem', e.message || 'Request failed.')
          + '</div>';
      });
  };

  // ── drill-down ────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.drill = function (perspective, patch, label) {
    this.trail.push({
      perspective: this.perspective,
      state: Object.assign({}, this.state),
      label: this.currentLabel()
    });
    this.setPerspective(perspective, patch);
    if (label) { /* label is derived on render */ }
  };

  AcademicIntelligence.prototype.currentLabel = function () {
    var d = this.data;
    if (!d || !d.summary) return this.perspective;
    switch (this.perspective) {
      case 'student': return d.summary.student_name || 'Student';
      case 'teacher': return d.summary.teacher_name || 'Teacher';
      case 'subject': return d.summary.subject_name || 'Subject';
      default: return d.summary.class_name || 'Class';
    }
  };

  AcademicIntelligence.prototype.renderTrail = function () {
    var nav = document.getElementById('aiTrail');
    if (!nav) return;
    if (!this.trail.length) { nav.style.display = 'none'; nav.innerHTML = ''; return; }
    var self = this;
    nav.style.display = 'block';
    nav.innerHTML = this.trail.map(function (step, i) {
      return '<button type="button" class="ai-trail-step btn btn-o btn-xs" data-index="' + i + '">'
        + '<i class="fa-solid fa-arrow-left"></i> ' + esc(step.label) + '</button>';
    }).join(' <span style="opacity:.5">›</span> ')
      + ' <span style="opacity:.5">›</span> <strong style="color:#1e293b">'
      + esc(this.currentLabel()) + '</strong>';
    nav.querySelectorAll('.ai-trail-step').forEach(function (b) {
      b.addEventListener('click', function () {
        var idx = intOr(b.getAttribute('data-index'), 0);
        var step = self.trail[idx];
        self.trail = self.trail.slice(0, idx);
        self.perspective = step.perspective;
        self.state = Object.assign({}, step.state);
        self.syncTabs();
        self.renderFilters();
        self.load();
      });
    });
  };

  /** Wire every [data-drill] button rendered into the body. */
  AcademicIntelligence.prototype.bindDrills = function () {
    var self = this;
    var body = document.getElementById('aiBody');
    if (!body) return;
    body.querySelectorAll('[data-drill]').forEach(function (el) {
      el.addEventListener('click', function (ev) {
        ev.preventDefault();
        var target = el.getAttribute('data-drill');
        var patch = {};
        ['class_id', 'subject_id', 'teacher_id', 'member_id'].forEach(function (k) {
          var v = el.getAttribute('data-' + k.replace('_', '-'));
          if (v !== null) patch[k] = intOr(v, 0);
        });
        // Entering a new entity clears cohort filters that belonged to the
        // previous one, so the headline numbers always describe what the
        // title says.
        Object.assign(patch, {
          gender: '', grade_letter: '', min_grade: '', max_grade: '',
          min_attendance: '', max_attendance: '', search: ''
        });
        self.drill(target, patch);
      });
    });
  };

  function drillBtn(target, attrs, text, title) {
    var data = Object.keys(attrs).map(function (k) {
      return 'data-' + k.replace(/_/g, '-') + '="' + esc(attrs[k]) + '"';
    }).join(' ');
    return '<button type="button" data-drill="' + target + '" ' + data
      + ' title="' + esc(title || ('Open ' + target + ' view')) + '" '
      + 'style="background:none;border:none;padding:0;color:' + PALETTE.primary
      + ';font:inherit;font-weight:600;cursor:pointer;text-align:left;text-decoration:underline">'
      + esc(text) + '</button>';
  }

  // ── render ────────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.render = function () {
    var d = this.data;
    var body = document.getElementById('aiBody');
    if (!body || !d) return;
    this.destroyCharts();

    var html = this.renderContext(d);
    switch (d.perspective) {
      case 'student': html += this.renderStudent(d); break;
      case 'teacher': html += this.renderTeacher(d); break;
      case 'subject': html += this.renderSubject(d); break;
      default: html += this.renderClass(d);
    }
    body.innerHTML = html;
    this.renderTrail();
    this.bindDrills();
    this.drawCharts(d);
  };

  AcademicIntelligence.prototype.renderContext = function (d) {
    var year = d.academic_year ? d.academic_year.year_name : '—';
    var term = d.term ? d.term.term_name : 'Annual (full year)';
    var filterCount = Object.keys(d.filters || {}).filter(function (k) {
      return ['page', 'per_page', 'class_id', 'subject_id'].indexOf(k) === -1;
    }).length;
    return '<div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;margin-bottom:.75rem;font-size:.72rem">'
      + '<span class="ch ch-p"><i class="fa-solid fa-calendar"></i>&nbsp;' + esc(year) + '</span>'
      + '<span class="ch ch-i">' + esc(term) + '</span>'
      + (filterCount
        ? '<span class="ch ch-w">' + filterCount + ' filter' + (filterCount > 1 ? 's' : '') + ' active</span>'
        : '')
      + '</div>';
  };

  function tableShell(caption, headers, rows, emptyMsg) {
    if (!rows.length) {
      return '<div class="crd" style="padding:0;overflow:hidden">'
        + emptyState('fa-table-list', 'Nothing to show', emptyMsg) + '</div>';
    }
    return '<div class="crd" style="padding:0;overflow:hidden">'
      + '<div style="overflow-x:auto">'
      + '<table style="width:100%;border-collapse:collapse;font-size:.8rem">'
      + '<caption class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">'
      + esc(caption) + '</caption>'
      + '<thead><tr style="background:#f8fafc;text-align:left">'
      + headers.map(function (h) {
        return '<th scope="col" style="padding:.6rem .7rem;font-size:.68rem;text-transform:uppercase;'
          + 'letter-spacing:.03em;color:#64748b;font-weight:700;white-space:nowrap">' + esc(h) + '</th>';
      }).join('')
      + '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div></div>';
  }

  function td(content, style) {
    return '<td style="padding:.55rem .7rem;border-top:1px solid #f1f5f9;' + (style || '') + '">'
      + content + '</td>';
  }

  // ── STUDENT ───────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.renderStudent = function (d) {
    var s = d.summary;
    var fullName = [s.student_name, s.father_name].filter(Boolean).join(' ');
    var rankText = s.rank ? ('#' + s.rank + (s.rank_tied ? ' (tied)' : '')) : '—';

    var head = '<div class="crd" style="padding:1rem;margin-bottom:1rem">'
      + '<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">'
      + '<div><div style="font-size:1.1rem;font-weight:800;color:#1e293b">' + esc(fullName) + '</div>'
      + '<div style="font-size:.75rem;color:#64748b;margin-top:.15rem">'
      + (s.christian_name ? 'Christian name: ' + esc(s.christian_name) + ' · ' : '')
      + (s.member_code ? 'Code: ' + esc(s.member_code) : '') + '</div>'
      + '<div style="margin-top:.4rem">'
      + drillBtn('class', { class_id: s.class_id }, s.class_name || 'Class', 'Open the class view')
      + '</div></div>'
      + '<div style="text-align:right"><div style="font-size:2rem;font-weight:800;color:' + PALETTE.brand + '">'
      + num(s.overall_average, '%') + '</div>'
      + '<div>' + gradeChip(s.grade_letter) + '</div></div></div></div>';

    var kpis = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:.6rem;margin-bottom:1rem">'
      + kpi('Class rank', rankText, s.total_in_class ? 'of ' + s.total_in_class + ' students' : '')
      + kpi('Attendance', s.has_attendance ? pct(s.attendance_rate) : '—',
        s.has_attendance ? (s.present_days + ' of ' + s.total_days + ' sessions') : 'Not recorded')
      + kpi('Subjects', String(s.subjects_count || 0), s.graded_subjects + ' graded')
      + kpi('Pending', String(s.pending_subjects || 0),
        s.pending_subjects ? 'awaiting a final result' : 'all results in',
        s.pending_subjects ? 'warn' : null)
      + kpi('Assessments', String(s.assessments_count || 0), 'marks recorded')
      + kpi('Strongest', s.strongest_subject ? esc(s.strongest_subject) : '—',
        s.weakest_subject ? 'Weakest: ' + esc(s.weakest_subject) : '')
      + '</div>';

    var charts = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:.75rem;margin-bottom:1rem">'
      + this.chartCard('aiChartSubjects', 'Subject performance')
      + (d.charts.semester_comparison && d.charts.semester_comparison.length
        ? this.chartCard('aiChartSemester', 'Semester comparison') : '')
      + (d.charts.assessment_progression && d.charts.assessment_progression.length
        ? this.chartCard('aiChartProgression', 'Assessment results') : '')
      + (s.has_attendance ? this.chartCard('aiChartAttendance', 'Attendance') : '')
      + '</div>';

    var self = this;
    var rows = d.rows.map(function (r) {
      return '<tr>'
        + td('<strong>' + esc(r.subject_name) + '</strong>'
          + (r.subject_name_en ? '<div style="font-size:.68rem;color:#94a3b8">' + esc(r.subject_name_en) + '</div>' : ''))
        + td(num(r.semester_1_score, '%'), 'text-align:right')
        + td(num(r.semester_2_score, '%'), 'text-align:right')
        + td('<strong>' + num(r.final_percentage, '%') + '</strong>', 'text-align:right')
        + td(gradeChip(r.grade_letter))
        + td(durationChip(r.duration_type) + ' ' + statusChip(r.subject_status))
        + td(completionBar(r.completion), 'min-width:110px')
        + '</tr>'
        + (r.status_reason
          ? '<tr><td colspan="7" style="padding:.1rem .7rem .5rem;font-size:.68rem;color:#94a3b8">'
            + esc(r.status_reason) + '</td></tr>'
          : '');
    });

    var table = tableShell('Subject results for ' + fullName,
      ['Subject', 'Semester 1', 'Semester 2', 'Final', 'Grade', 'Duration / status', 'Assessments recorded'],
      rows, 'This student has no subjects on record for the selected year and term.');

    var note = s.semester_weights
      ? '<p style="font-size:.7rem;color:#94a3b8;margin-top:.5rem">Annual results combine Semester 1 and '
        + 'Semester 2 using this year\'s configured weights (' + num(s.semester_weights.s1, '%')
        + ' / ' + num(s.semester_weights.s2, '%') + '). A subject still running has no final result and is '
        + 'left out of the average rather than counted as zero.</p>'
      : '';

    return head + kpis + charts + table + note;
  };

  // ── TEACHER ───────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.renderTeacher = function (d) {
    var s = d.summary;

    var head = '<div class="crd" style="padding:1rem;margin-bottom:1rem">'
      + '<div style="font-size:1.1rem;font-weight:800;color:#1e293b">' + esc(s.teacher_name) + '</div>'
      + '<div style="font-size:.75rem;color:#64748b">' + esc(s.teacher_email || '') + '</div>'
      + '<div style="font-size:.7rem;color:#94a3b8;margin-top:.45rem;max-width:62ch">'
      + '<i class="fa-solid fa-circle-info"></i> ' + esc(s.disclaimer || '') + '</div></div>';

    var kpis = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:.6rem;margin-bottom:1rem">'
      + kpi('Assignments', String(s.assignment_count || 0),
        (s.class_count || 0) + ' classes · ' + (s.subject_count || 0) + ' subjects')
      + kpi('Students taught', String(s.total_students || 0), 'across those classes')
      + kpi('Average result', pct(s.average), s.graded_results + ' results counted')
      + kpi('Pass rate', pct(s.pass_rate), 'at or above ' + num(d.pass_mark, '%'))
      + kpi('Assessment delivery', pct(s.delivery_rate),
        (s.assessments_approved || 0) + ' approved · ' + (s.assessments_submitted || 0) + ' submitted')
      + kpi('Not submitted', String(s.assessments_missing || 0),
        'of ' + (s.assessments_planned || 0) + ' planned',
        s.assessments_missing ? 'bad' : null)
      + '</div>';

    var charts = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:.75rem;margin-bottom:1rem">'
      + this.chartCard('aiChartTeacherClasses', 'Result by class and subject')
      + this.chartCard('aiChartDelivery', 'Assessment delivery')
      + this.chartCard('aiChartGrades', 'Grade spread across assignments')
      + '</div>';

    var rows = d.rows.map(function (r) {
      var missing = (r.missing_assessments || []);
      return '<tr>'
        + td(drillBtn('class', { class_id: r.class_id }, r.class_name, 'Open this class')
          + (r.is_class_teacher ? ' <span class="ch ch-p">Homeroom</span>' : ''))
        + td(r.subject_id
          ? drillBtn('subject', { subject_id: r.subject_id }, r.subject_name, 'Open this subject')
          : '<span style="color:#94a3b8">' + esc(r.subject_name) + '</span>')
        + td(String(r.student_count || 0), 'text-align:right')
        + td(num(r.average, '%') + '<div style="font-size:.66rem;color:#94a3b8">'
          + (r.graded_students || 0) + ' graded</div>', 'text-align:right')
        + td(pct(r.pass_rate), 'text-align:right')
        + td(distributionBar(r.grade_distribution), 'min-width:80px')
        + td(completionBar(r.completion), 'min-width:110px')
        + td('<span class="ch ch-ok">' + (r.submissions.approved || 0) + ' ✓</span> '
          + '<span class="ch ch-i">' + (r.submissions.submitted || 0) + '</span> '
          + (r.submissions.missing
            ? '<span class="ch ch-d" title="' + esc(missing.join(', ')) + '">'
              + r.submissions.missing + ' missing</span>'
            : ''))
        + '</tr>';
    });

    var table = tableShell('Assignments for ' + s.teacher_name,
      ['Class', 'Subject', 'Students', 'Average', 'Pass rate', 'Grade spread', 'Assessments recorded', 'Submissions'],
      rows,
      'This teacher holds no active assignment for the selected academic year.');

    return head + kpis + charts + table;
  };

  // ── SUBJECT ───────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.renderSubject = function (d) {
    var s = d.summary;

    var head = '<div class="crd" style="padding:1rem;margin-bottom:1rem">'
      + '<div style="font-size:1.1rem;font-weight:800;color:#1e293b">' + esc(s.subject_name) + '</div>'
      + '<div style="font-size:.75rem;color:#64748b">' + esc(s.subject_name_en || '') + '</div></div>';

    var kpis = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:.6rem;margin-bottom:1rem">'
      + kpi('Classes', String(s.class_count || 0), 'offering this subject')
      + kpi('Students', String(s.total_students || 0), s.graded_results + ' graded')
      + kpi('Average', pct(s.average), 'across all classes')
      + kpi('Pass rate', pct(s.pass_rate), 'at or above ' + num(d.pass_mark, '%'))
      + kpi('Highest', pct(s.highest), 'Lowest: ' + pct(s.lowest))
      + kpi('Teachers', String(s.teacher_count || 0), 'assigned')
      + '</div>';

    var charts = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:.75rem;margin-bottom:1rem">'
      + this.chartCard('aiChartSubjectByClass', 'Performance by class')
      + this.chartCard('aiChartGrades', 'Grade distribution')
      + this.chartCard('aiChartCompletion', 'Assessments recorded by class')
      + this.chartCard('aiChartSemester', 'Semester comparison')
      + '</div>';

    var rows = d.rows.map(function (r) {
      var teachers = (r.teachers || []).length
        ? r.teachers.map(function (t) {
          return drillBtn('teacher', { teacher_id: t.teacher_id }, t.teacher_name, 'Open this teacher');
        }).join(', ')
        : '<span class="ch ch-w">No teacher assigned</span>';
      return '<tr' + (r.offered_in_this_term ? '' : ' style="opacity:.6"') + '>'
        + td(drillBtn('class', { class_id: r.class_id }, r.class_name, 'Open this class')
          + (r.offered_in_this_term ? '' : '<div style="font-size:.66rem;color:#94a3b8">Not offered this term</div>'))
        + td(teachers)
        + td(String(r.student_count || 0), 'text-align:right')
        + td(num(r.average, '%') + '<div style="font-size:.66rem;color:#94a3b8">'
          + (r.graded_students || 0) + ' graded</div>', 'text-align:right')
        + td(pct(r.pass_rate), 'text-align:right')
        + td(distributionBar(r.grade_distribution), 'min-width:80px')
        + td(completionBar(r.completion), 'min-width:110px')
        + td(num(r.semester_1_average, '%') + ' → ' + num(r.semester_2_average, '%'), 'white-space:nowrap')
        + '</tr>';
    });

    var table = tableShell(s.subject_name + ' across classes',
      ['Class', 'Teacher(s)', 'Students', 'Average', 'Pass rate', 'Grade spread', 'Assessments recorded', 'S1 → S2'],
      rows, 'No class currently offers this subject.');

    return head + kpis + charts + table;
  };

  // ── CLASS ─────────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.renderClass = function (d) {
    var s = d.summary;

    var head = '<div class="crd" style="padding:1rem;margin-bottom:1rem">'
      + '<div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start">'
      + '<div><div style="font-size:1.1rem;font-weight:800;color:#1e293b">' + esc(s.class_name) + '</div>'
      + '<div style="font-size:.75rem;color:#64748b">' + esc(s.class_name_en || '') + '</div>'
      + (s.is_filtered
        ? '<div style="margin-top:.35rem"><span class="ch ch-w">Filtered: '
          + s.total_students + ' of ' + s.total_students_unfiltered
          + ' students — every number below describes the filtered group</span></div>'
        : '')
      + '</div>'
      + '<div style="text-align:right"><div style="font-size:2rem;font-weight:800;color:' + PALETTE.brand + '">'
      + num(s.class_average, '%') + '</div>'
      + '<div style="font-size:.7rem;color:#64748b">class average</div></div></div></div>';

    var kpis = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:.6rem;margin-bottom:1rem">'
      + kpi('Students', String(s.total_students || 0), (s.graded_students || 0) + ' graded')
      + kpi('Pass rate', pct(s.pass_rate), 'at or above ' + num(d.pass_mark, '%'))
      + kpi('Highest', pct(s.highest), 'Lowest: ' + pct(s.lowest))
      + kpi('Median', pct(s.median), 'middle result')
      + kpi('Attendance', pct(s.attendance_rate), 'where recorded')
      + kpi('Curriculum', pct(s.curriculum_recorded_pct),
        (s.subjects_incomplete || 0) + ' subject(s) incomplete',
        s.subjects_incomplete ? 'warn' : null)
      + '</div>';

    var charts = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:.75rem;margin-bottom:1rem">'
      + this.chartCard('aiChartSubjects', 'Subject performance')
      + this.chartCard('aiChartGrades', 'Student grade distribution')
      + this.chartCard('aiChartScatter', 'Attendance vs result')
      + this.chartCard('aiChartCompletion', 'Assessments recorded by subject')
      + '</div>';

    var subjectRows = d.rows.map(function (r) {
      return '<tr>'
        + td(drillBtn('subject', { subject_id: r.id }, r.subject_name, 'Open this subject across classes')
          + (r.subject_name_en ? '<div style="font-size:.68rem;color:#94a3b8">' + esc(r.subject_name_en) + '</div>' : ''))
        + td(num(r.average, '%'), 'text-align:right')
        + td((r.graded_students || 0) + (r.pending_students
          ? ' <span class="ch ch-w" title="awaiting a final result">+' + r.pending_students + '</span>' : ''),
          'text-align:right')
        + td(pct(r.pass_rate), 'text-align:right')
        + td(distributionBar(r.grade_distribution), 'min-width:80px')
        + td(durationChip(r.duration_type))
        + td(completionBar(r.completion), 'min-width:110px')
        + td(num(r.semester_1_average, '%') + ' → ' + num(r.semester_2_average, '%'), 'white-space:nowrap')
        + '</tr>';
    });

    var subjectTable = '<h3 style="font-size:.9rem;font-weight:700;color:#1e293b;margin:.4rem 0 .5rem">'
      + 'Subjects</h3>'
      + tableShell('Subjects in ' + s.class_name,
        ['Subject', 'Average', 'Graded', 'Pass rate', 'Grade spread', 'Duration', 'Assessments recorded', 'S1 → S2'],
        subjectRows,
        'No subject is attached to this class for the selected year and term.');

    var students = (d.drilldown && d.drilldown.students) || [];
    var studentRows = students.map(function (st) {
      return '<tr>'
        + td(st.rank ? ('#' + st.rank + (st.rank_tied ? '*' : '')) : '—', 'text-align:right;color:#64748b')
        + td(drillBtn('student', { member_id: st.member_id, class_id: s.class_id },
          [st.student_name, st.father_name].filter(Boolean).join(' '), 'Open this student')
          + (st.member_code ? '<div style="font-size:.66rem;color:#94a3b8">' + esc(st.member_code) + '</div>' : ''))
        + td(esc(st.gender || '—'))
        + td('<strong>' + num(st.overall_average, '%') + '</strong>', 'text-align:right')
        + td(gradeChip(st.grade_letter))
        + td(st.has_attendance ? pct(st.attendance_rate) : '<span style="color:#94a3b8">Not recorded</span>',
          'text-align:right')
        + '</tr>';
    });

    var pager = '';
    var dd = d.drilldown || {};
    if (dd.total > students.length) {
      pager = '<div style="padding:.6rem .7rem;font-size:.72rem;color:#64748b;border-top:1px solid #f1f5f9">'
        + 'Showing ' + students.length + ' of ' + dd.total + ' students.'
        + (dd.has_more ? ' Narrow the filters to see the rest.' : '') + '</div>';
    }

    var studentTable = '<h3 style="font-size:.9rem;font-weight:700;color:#1e293b;margin:1rem 0 .5rem">'
      + 'Students</h3>'
      + tableShell('Students in ' + s.class_name,
        ['Rank', 'Student', 'Gender', 'Result', 'Grade', 'Attendance'],
        studentRows,
        s.is_filtered
          ? 'No student in this class matches the current filters.'
          : 'No student is enrolled in this class for the selected year.')
      + pager;

    return head + kpis + charts + subjectTable + studentTable;
  };

  // ── charts ────────────────────────────────────────────────────────────

  AcademicIntelligence.prototype.chartCard = function (id, title) {
    return '<div class="crd" style="padding:.85rem">'
      + '<div style="font-size:.78rem;font-weight:700;color:#334155;margin-bottom:.5rem">' + esc(title) + '</div>'
      + '<div style="position:relative;height:210px"><canvas id="' + id + '"></canvas></div></div>';
  };

  AcademicIntelligence.prototype.destroyCharts = function () {
    var self = this;
    Object.keys(this.charts).forEach(function (k) {
      try { self.charts[k].destroy(); } catch (e) { /* already gone */ }
      delete self.charts[k];
    });
  };

  function hasChartJs() {
    return typeof global.Chart !== 'undefined';
  }

  /** Points with no value are dropped, never plotted as zero. */
  function cleanPairs(list, valueKey) {
    return (list || []).filter(function (p) {
      var v = p[valueKey === undefined ? 'value' : valueKey];
      return v !== null && v !== undefined && isFinite(Number(v));
    });
  }

  AcademicIntelligence.prototype.mount = function (id, config) {
    if (!hasChartJs()) return;
    var el = document.getElementById(id);
    if (!el) return;
    try {
      // Chart.js animates by default. That is animation for its own sake
      // here, and it is exactly what prefers-reduced-motion is for.
      config.options = config.options || {};
      config.options.animation = prefersReducedMotion() ? false : { duration: 220 };
      this.charts[id] = new global.Chart(el.getContext('2d'), config);
    } catch (e) {
      var holder = el.parentElement;
      if (holder) {
        holder.innerHTML = '<div style="font-size:.72rem;color:#94a3b8;text-align:center;padding-top:70px">'
          + 'Chart unavailable</div>';
      }
    }
  };

  function noData(id) {
    var el = document.getElementById(id);
    if (el && el.parentElement) {
      el.parentElement.innerHTML =
        '<div style="display:flex;align-items:center;justify-content:center;height:100%;'
        + 'font-size:.75rem;color:#cbd5e1">No data yet</div>';
    }
  }

  var BASE_OPTS = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false, // set per-mount from the user's motion preference
    plugins: {
      legend: { display: false },
      tooltip: { enabled: true }
    },
    scales: {
      y: { beginAtZero: true, max: 100, ticks: { font: { size: 10 } } },
      x: { ticks: { font: { size: 10 }, autoSkip: true, maxRotation: 40 } }
    }
  };

  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  AcademicIntelligence.prototype.drawCharts = function (d) {
    if (!hasChartJs()) return;
    var c = d.charts || {};
    var self = this;

    function bar(id, pairs, colour, label) {
      var data = cleanPairs(pairs);
      if (!data.length) { noData(id); return; }
      self.mount(id, {
        type: 'bar',
        data: {
          labels: data.map(function (p) { return p.label; }),
          datasets: [{
            label: label || 'Average %',
            data: data.map(function (p) { return Number(p.value); }),
            backgroundColor: colour, borderRadius: 5, maxBarThickness: 42
          }]
        },
        options: clone(BASE_OPTS)
      });
    }

    function grades(id, dist) {
      if (!dist) { noData(id); return; }
      var keys = ['A', 'B', 'C', 'D', 'F'];
      var values = keys.map(function (k) { return dist[k] || 0; });
      if (!values.some(function (v) { return v > 0; })) { noData(id); return; }
      var opts = clone(BASE_OPTS);
      opts.scales = { y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } } },
                      x: { ticks: { font: { size: 10 } } } };
      opts.plugins.legend = { display: false };
      self.mount(id, {
        type: 'bar',
        data: {
          labels: keys,
          datasets: [{
            label: 'Students', data: values,
            backgroundColor: keys.map(function (k) { return GRADE_COLORS[k]; }),
            borderRadius: 5, maxBarThickness: 46
          }]
        },
        options: opts
      });
    }

    function semester(id, pairs) {
      var data = (pairs || []).filter(function (p) {
        return (p.semester_1 !== null && p.semester_1 !== undefined)
          || (p.semester_2 !== null && p.semester_2 !== undefined);
      });
      if (!data.length) { noData(id); return; }
      var opts = clone(BASE_OPTS);
      opts.plugins.legend = { display: true, position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } };
      self.mount(id, {
        type: 'bar',
        data: {
          labels: data.map(function (p) { return p.label || p.subject_name; }),
          datasets: [
            {
              label: 'Semester 1',
              data: data.map(function (p) { return p.semester_1 === null ? null : Number(p.semester_1); }),
              backgroundColor: PALETTE.info, borderRadius: 4, maxBarThickness: 24
            },
            {
              label: 'Semester 2',
              data: data.map(function (p) { return p.semester_2 === null ? null : Number(p.semester_2); }),
              backgroundColor: PALETTE.primary, borderRadius: 4, maxBarThickness: 24
            }
          ]
        },
        options: opts
      });
    }

    switch (d.perspective) {
      case 'student':
        bar('aiChartSubjects', c.subject_performance, PALETTE.primary, 'Final %');
        semester('aiChartSemester', (c.semester_comparison || []).map(function (p) {
          return { label: p.subject_name, semester_1: p.semester_1, semester_2: p.semester_2 };
        }));
        bar('aiChartProgression', (c.assessment_progression || []).map(function (p) {
          return { label: p.subject_name + ' · ' + p.assessment_name, value: p.percentage };
        }), PALETTE.ok, 'Score %');
        if (c.attendance) {
          var a = c.attendance;
          if (!(a.present || a.absent || a.late || a.excused)) {
            noData('aiChartAttendance');
          } else {
            this.mount('aiChartAttendance', {
              type: 'doughnut',
              data: {
                labels: ['Present', 'Absent', 'Late', 'Excused'],
                datasets: [{
                  data: [a.present || 0, a.absent || 0, a.late || 0, a.excused || 0],
                  backgroundColor: [PALETTE.ok, PALETTE.bad, PALETTE.warn, PALETTE.info]
                }]
              },
              options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } } }
              }
            });
          }
        }
        break;

      case 'teacher':
        bar('aiChartTeacherClasses', c.class_averages, PALETTE.primary);
        var del = c.assessment_delivery || {};
        if (!(del.approved || del.submitted || del.missing)) {
          noData('aiChartDelivery');
        } else {
          this.mount('aiChartDelivery', {
            type: 'doughnut',
            data: {
              labels: ['Approved', 'Submitted', 'Not submitted'],
              datasets: [{
                data: [del.approved || 0, del.submitted || 0, del.missing || 0],
                backgroundColor: [PALETTE.ok, PALETTE.info, PALETTE.bad]
              }]
            },
            options: {
              responsive: true, maintainAspectRatio: false,
              plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } } }
            }
          });
        }
        grades('aiChartGrades', c.grade_distribution);
        break;

      case 'subject':
        bar('aiChartSubjectByClass', c.performance_by_class, PALETTE.primary);
        grades('aiChartGrades', c.grade_distribution);
        bar('aiChartCompletion', c.completion_by_class, PALETTE.warn, 'Recorded %');
        semester('aiChartSemester', c.semester_comparison);
        break;

      default:
        bar('aiChartSubjects', c.subject_performance, PALETTE.primary);
        grades('aiChartGrades', c.grade_distribution);
        bar('aiChartCompletion', c.completion_by_subject, PALETTE.warn, 'Recorded %');
        var pts = (c.attendance_vs_result || []).filter(function (p) {
          return p.attendance !== null && p.attendance !== undefined
            && p.result !== null && p.result !== undefined;
        });
        if (!pts.length) {
          noData('aiChartScatter');
        } else {
          this.mount('aiChartScatter', {
            type: 'scatter',
            data: {
              datasets: [{
                label: 'Students',
                data: pts.map(function (p) {
                  return { x: Number(p.attendance), y: Number(p.result), name: p.label };
                }),
                backgroundColor: PALETTE.primary
              }]
            },
            options: {
              responsive: true, maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    label: function (ctx) {
                      var r = ctx.raw || {};
                      return (r.name || '') + ' — attendance ' + r.x + '%, result ' + r.y + '%';
                    }
                  }
                }
              },
              scales: {
                x: { title: { display: true, text: 'Attendance %', font: { size: 10 } }, min: 0, max: 100 },
                y: { title: { display: true, text: 'Result %', font: { size: 10 } }, min: 0, max: 100 }
              }
            }
          });
        }
    }
  };

  global.AcademicIntelligence = AcademicIntelligence;
  global.AcademicIntelligenceInstance = global.AcademicIntelligenceInstance
    || new AcademicIntelligence();
}(window));
