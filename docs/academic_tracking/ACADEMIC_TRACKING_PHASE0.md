# Academic Tracking — Phase 0

**Audit, architecture contract and Phase 1 boundary.**

Repository `suraman21/SSMS`, branch `main`, baseline `39f8b47`.
Every number and behaviour below was measured against a live MariaDB
11.8.6 + PHP 8.4.26 running the real code, not read from a previous
report.

> **Phase 0 builds no tracking UI.** The only behavioural change made in
> this phase is the removal of automatic entity selection (commit
> `a885d2f`), which §16 of the brief explicitly permits and which the new
> architecture cannot be built on top of.

---

## A. Current-state map

```
admin/dashboards/edu_dept.php          (4 integration lines)
 ├── nav   data-sec="academic-intel"                       L487
 ├── host  <div id="sec-academic-intel" class="sec">       L782
 ├── load  <script src="/admin/js/academic_intelligence.js">L73
 └── boot  AcademicIntelligenceInstance.boot()             L4117
            │
            ▼
admin/js/academic_intelligence.js      (~1270 L, vanilla, Chart.js)
 ├── boot()        → renderShell → loadCatalogue → load()
 ├── load()        → GET api_education.php?action=get_academic_intelligence
 └── render()      → renderStudent | renderTeacher | renderSubject | renderClass
            │
            ▼
admin/api_education.php
 ├── TIER 3 role gate  super_admin | school_admin | edu_dept      L89-108
 ├── case get_academic_intelligence            L1719   (+ canViewClass)
 └── case get_academic_intelligence_options    L1771
            │
            ▼
admin/backend/services/AcademicIntelligenceService.php  (~1570 L)
 ├── perspective() dispatcher → student | teacher | subject | classView
 ├── pack()        memoised ReportCardService::getClassReport per class
 ├── subjectCatalogue / subjectSlice / classSlice / cohortStats
 └── CONTAINS NO ACADEMIC FORMULA
            │
            ▼
admin/backend/services/ReportCardService.php   ← THE CALCULATION AUTHORITY
 ├── getCard() · getClassReport() · getClassCards() · filterStudentsPerformance()
 ├── canViewClass()  ← the authorization primitive
 └── PASS_MARK=50.0 · GRADE_SCALE A≥90 B≥80 C≥70 D≥60 F
            │
            ▼
DB  academic_years · academic_terms · classes · subjects · class_subjects
    class_enrollments · teacher_assignments · assessments · academic_records
    grade_submissions · attendance · members · users
```

### Relationship facts confirmed from schema

| Relationship | Carried by | Note |
|---|---|---|
| class ↔ subject | `class_subjects(class_id, subject_id)` UNIQUE | plus `duration_type`/`term_id` from migration 056 |
| teacher ↔ class/subject | `teacher_assignments(teacher_id, class_id, subject_id NULLABLE, academic_year_id, is_class_teacher, is_active)` | `subject_id` NULL = homeroom, not "all subjects" |
| student ↔ class | `class_enrollments(member_id, class_id, academic_year_id, status)` | per year |
| marks | `academic_records(member_id, class_id, subject_id, assessment_id, score, max_score, academic_year_id, term_id)` | |
| workflow | `grade_submissions(status ENUM draft, incomplete, submitted, approved, rejected, revision_needed, assessment_id)` | the real workflow tracking must reach |
| attendance | `attendance(member_id, class_id, academic_year_id, date, status ENUM present, absent, late, excused)` | |

Teachers are `users.role = 'teacher'`. `assessments` DDL lives in
`admin/migrations/003_add_assessments.php`, **not** under `sql/`.

---

## B. Keep / Modify / Remove

### KEEP — unchanged, architecturally load-bearing

| File / unit | Why |
|---|---|
| `ReportCardService.php` | The calculation authority. Averages, finals, grade letters, ranking, attendance, semester weighting. Phase 1 adds nothing to it. |
| `SubjectDurationPolicy` + migration 056 semantics | `FULL_YEAR` / `SEMESTER_ONLY`, per-year weights, CONTINUING/PENDING. Proven non-regressing. |
| `ReportCardService::canViewClass()` | The single authorization primitive. No second system. |
| TIER 3 role gate (`api_education.php` L89-108) | Closed a real hole; `teacher`/`attendance_taker` previously read school-wide marks. |
| `grade_submissions` workflow + `sec-submissions` UI | The real workflow tracking must link into, not reimplement. |
| `report_card.js` / `FKSSReportCard.fillModal()` / `viewStudentReportFromFilter()` | Existing report-card surface to link to. |
| `api_communication.php` `get_report_card` / `get_class_cards` / `get_class_report` | Already `canViewClass`-gated. The pattern to reuse. |
| `/admin/js/chart.umd.min.js` | The one chart runtime. |
| `tests/e2e/academic_intelligence.php` (246 checks) | Proves perspective numbers equal `ReportCardService`. Must keep passing. |
| `tests/e2e/academic_intelligence_render.js` (272 checks) | Executes the renderer; catches field typos and null→0. |
| `EducationAnalyticsService` | Separate hub feature; out of scope, untouched. |

### MODIFY — survives, but changes shape for tracking

| Unit | Change required in Phase 1+ |
|---|---|
| `AcademicIntelligenceService::perspective()` | Split into **list** and **detail**. Today one call returns summary + rows + charts + drilldown together. |
| `AcademicIntelligenceService::subject()` / `teacher()` | **Measured +12 queries per class.** Must become paginated/lazy before a real school uses them (see §K). |
| `AcademicIntelligenceService::options()` | Becomes the four root lists' backing data, or is replaced by the existing list endpoints. |
| `academic_intelligence.js` | Becomes a router: root list → entity → related → record. Rendering helpers (`gradeChip`, `completionBar`, `distributionBar`, `kpi`, `emptyState`, `num`) are reusable as-is. |
| `get_academic_intelligence` | Keep the action, narrow the payload; add a list action rather than a parallel API file. |
| `edu_dept.php` integration | One section stays; it hosts a router instead of a tab strip. |
| `tests/security/test_academic_intelligence.py` | Extend with navigation/state tests; the calculation-equivalence tests stay. |

### REMOVE / REPLACE — specific to the first design

| Unit | Status | Evidence |
|---|---|---|
| Automatic first-item selection | **REMOVED in `a885d2f`** | Verified: it chose `class_id=1`, `subject_id=3`, `teacher_id=12` and fired a report request before any user action. |
| Auto-load of a class report on open | **REMOVED in `a885d2f`** | Open cost 17 → 5 queries. |
| Perspective **tab strip** as primary navigation | Replace in Phase 1 | Four tabs ≠ four root lists. Tabs imply "switch view of the same thing"; tracking needs "pick a thing". |
| Giant entity `<select>` dropdowns | Replace in Phase 1 | Unusable past ~50 students; brief §7 forbids them as the primary journey. |
| Single all-in-one perspective render | Replace in Phase 1 | `renderClass()` emits header + 6 KPIs + 4 charts + 2 tables in one pass with no progressive disclosure. |
| Cohort filters pinned to the class perspective only | Rework | Filters belong to whichever list is showing, not to one perspective. |

**Nothing else is removed.** No backend calculation is deleted.

---

## C. Confirmed problems — and two corrections

Each item in brief §4 was tested rather than assumed. Two did not hold.

### A. Automatic selection — **CONFIRMED, now fixed**
Boot chose `class_id=1`, `subject_id=3`, `teacher_id=12`, and the student
picker selected `students[0]`. One `get_academic_intelligence` request
was issued before the user acted. Fixed in `a885d2f`; regression-tested
against the unfixed controller.

### B. Dashboard-first layout — **CONFIRMED** (measured)

| Action | Queries |
|---|---|
| Catalogue (filter bar) | 5 |
| Auto class report nobody asked for | 12 |
| **Open the feature (before)** | **17** |
| **Open the feature (after `a885d2f`)** | **5** |

Depth is also unconditional: `renderClass()` renders 6 KPI cards, 4
charts, a subject table and a student table in a single pass — every
metric at the top level, which is the opposite of progressive disclosure.

### C. Mixed scope and filters — **PARTIALLY FALSE AS STATED**
The claim was that the UI reports selected entities as active filters.
**It does not.** Measured on live payloads:

| Request | `filters` echoed | Banner shown |
|---|---|---|
| class, no filters | `class_id, subject_id, page, per_page` | *(no banner)* |
| class + `gender=female` | `… gender …` | `1 filter active` |
| student (member_id = scope) | `class_id, subject_id, page, per_page` | *(no banner)* |
| teacher / subject | scope ids only | *(no banner)* |

The banner is correct today. **The real defect is structural**: scope ids
travel *inside* the `filters` object and are excluded from the count by a
hard-coded denylist `['page','per_page','class_id','subject_id']`
(`renderContext`). It is right by coincidence of which keys exist; add a
scope key and it miscounts. Phase 1 separates **context / scope /
filters** into three named objects so correctness is structural.

### D. Incorrect empty messaging — **LARGELY FALSE AS STATED**
No screen says "remove filters" when none are applied. Rendered output:

| Situation | Message shown | Correct? |
|---|---|---|
| empty class, no filters | "No student is enrolled in this class for the selected year." | yes |
| teacher with no assignments | "This teacher holds no active assignment for the selected academic year." | yes |
| filters match nobody | "No student in this class matches the current filters." | yes |
| subject offered nowhere | "No class currently offers this subject." | yes |

**The one real weakness:** the *headline* is always the generic "Nothing
to show" — only the sub-line is cause-specific. And before `a885d2f`,
"nothing selected" fell through to that same generic headline for class,
subject and teacher. Phase 1 gives each state its own headline.

### E. Scaling — **NEW, measured, not in the brief**
`subject()` and `teacher()` build one `ReportCardService` class pack per
related class. Measured slope:

| View | 1 class | 2 classes | Marginal |
|---|---|---|---|
| Subject | 16 q | 28 q | **+12 q / class** |
| Teacher | 17 q | 29 q | **+12 q / assignment** |

A subject offered by 40 classes ⇒ ~**484 queries in one request**;
`MAX_CLASSES_PER_REQUEST = 60` caps it near **724**. This is the single
biggest reason the subject and teacher views must become paginated in
Phase 1. *(Extrapolated from a measured linear slope on a 3-class seed —
REQUIRES VERIFICATION at production scale.)*

---

## D. Target information architecture

```
Academic Tracking
├── Students   ─┐
├── Teachers    │  four ENTRY POINTS into ONE graph,
├── Subjects    │  not four dashboards
└── Classes    ─┘
```

```
Student → Class → Subjects → Assessments → Results
        → Attendance
        → Report Card                      (existing surface)

Teacher → Classes → Subject → Assessments → Submission → WORKFLOW
        → Progress

Subject → Classes → Teacher(s) → Students → Assessments → Results

Class   → Students → Subject → Assessment
        → Subjects → Teacher(s)
        → Attendance
```

Every arrow is a real foreign key (§A table). No relationship is
inferred: a teacher is not assumed to teach every subject, and a class is
not assumed to offer every subject — `teacher_assignments` and
`class_subjects` are consulted.

---

## E. Screen map (defined, **not built**)

| # | Screen | Level | Primary job |
|---|---|---|---|
| 0 | Academic Tracking home | root | Explain the feature, show the four entry points, set year/term context |
| 1 | Students list | list | search · filter · sort · paginate · select |
| 2 | Student overview | detail | identity, average, rank, attendance, subject counts |
| 3 | Student → subject detail | related | S1/S2/final, duration, status, assessments |
| 4 | Student → attendance | related | trend + session records |
| 5 | Student → report card | **workflow** | opens the existing report card |
| 6 | Teachers list | list | search · filter (assigned/unassigned) · sort · paginate |
| 7 | Teacher overview | detail | assignments, delivery, **no quality score** |
| 8 | Teacher → class+subject | related | the assessment list for that assignment |
| 9 | Teacher → assessment → submission | **workflow** | Draft / Submitted / Needs revision / Approved / Not started → open the record |
| 10 | Subjects list | list | search · sort · paginate |
| 11 | Subject overview | detail | classes offering it, per-class results |
| 12 | Subject → class | related | teacher(s), students, assessments |
| 13 | Classes list | list | search · sort · paginate |
| 14 | Class overview | detail | students, subjects, teachers, completion |
| 15 | Class → subject | related | per-subject cohort |
| 16 | Class → student | related | jumps to screen 2 with scope preserved |

---

## F. API contract proposal

**Extend `admin/api_education.php`. No parallel API file.** Three shapes:

### 1 — LIST (lightweight, paginated)
Prefer **existing** endpoints; they already fit:

| Entity | Existing action | Already supports | Gap |
|---|---|---|---|
| Classes | `get_classes` | id, name, level_order, `student_count` | pagination |
| Subjects | `get_subjects` (`api_subjects.php`) | id, names, `assigned_classes` | pagination |
| Teachers | `list_teachers` | **`q`, `assigned`, `page`, `per_page`, `assigned_classes`** | already correct |
| Students | `search_members` / `get_class_students` / `roster` | search, class scope | a year-scoped list |

`list_teachers` already implements the exact root-list contract §7 asks
for. Reuse it; do not rebuild it.

### 2 — DETAIL (scoped to one chosen entity)
Keep `get_academic_intelligence` with `perspective` + entity id, but
**trim the payload**: summary + the immediate relation only. Today it
also ships charts and a full drilldown roster on every call.

### 3 — RELATED / WORKFLOW (only when opened)
New narrow actions, loaded on demand:
`get_tracking_assessments` (class+subject → assessments + submission
status), and reuse of `api_communication.php` `get_report_card`.

| Current action | Verdict |
|---|---|
| `get_academic_intelligence` | **extend** — narrow the payload, keep the contract |
| `get_academic_intelligence_options` | **modify** — context (years/terms) only; entity lists move to the list endpoints |
| `get_education_hub` | **keep** — separate Analytics Hub feature |
| `filter_students_performance` | **keep + reuse** — already the students list with real filters |
| `get_classes`, `list_teachers`, `get_subjects` | **keep + reuse** as root lists |

**Nothing is loaded before an entity is selected** except context and the
root list the user is looking at.

---

## G. Data-state model

Seven states, never collapsed:

| State | Trigger | Must say |
|---|---|---|
| `NO_SELECTION` | no entity chosen | "Choose a class" — never "no data", never a filter message |
| `NO_ENTITIES` | catalogue empty | "No classes configured" — a fact, not a prompt |
| `NO_ACADEMIC_DATA` | entity exists, nothing recorded | "No marks recorded for this class yet" |
| `NOT_OFFERED` | subject not in `class_subjects` for this term | "Not offered this term" (semester-only) |
| `NO_TEACHER` | no `teacher_assignments` row | "No teacher assigned" — never guess one |
| `NO_MARKS` | assessment exists, no `academic_records` | "Assessment created, no marks entered" |
| `FILTERED_EMPTY` | filters applied, zero rows | "No student matches the current filters" + **Clear filters** |
| `ERROR` | server/network | explain + retry; never a silent empty table |

Plus the invariant already enforced and tested: **null is not zero.** An
ungraded subject renders `—`, is excluded from averages, and is plotted
as a gap.

## G2. State model

```
context   { academic_year_id, term_id }        ← year defaults to is_current
scope     { entity_type, entity_id, parent }   ← ALWAYS explicit; never auto
filters   { search, gender, status, range }    ← user-applied ONLY
sort      { field, direction }
page      { number, size, total }
trail     [ {entity_type, entity_id, label} ]
```

Rules, enforced by `tests/e2e/academic_tracking_selection.js`:
- `scope` starts empty; nothing is fetched while it is empty.
- changing `context.year` clears `scope` and `filters`.
- entering a new entity clears `filters` (they belonged to the old one).
- `filters` **never** contains scope or context ids — that conflation is
  the structural defect in §C.
- only non-empty `filters` may be reported as active.

---

## H. Phase 1 boundary

### Phase 1 WILL
1. Academic Tracking home with the four entry points and a context header.
2. **Classes** and **Teachers** root lists — search, sort, pagination,
   explicit selection — reusing `get_classes` and `list_teachers`.
3. Class overview and Teacher overview, progressively disclosed.
4. One drill-down each: Class → Subject, Teacher → Class+Subject.
5. Separate `context` / `scope` / `filters` in state and payload.
6. All seven §G empty states with their own headlines.
7. Pagination for the subject/teacher per-class loops (§C.E).
8. Behavioural tests per §13.

### Phase 1 will NOT
- Students or Subjects root lists (Phase 2).
- Assessment/submission workflow screens (Phase 2) — Phase 1 only
  *links* to the existing `sec-submissions`.
- Attendance detail, report-card embedding (Phase 2).
- Any change to `ReportCardService` or any calculation.
- Grade editing / academic exceptions — **not authorised**.
- Phase C synchronisation — **not authorised**.
- Schema migrations.

### Known test-infrastructure gap
`tests/e2e/academic_intelligence.php` seeds `users` **without
`member_id`**, so `list_teachers` (which `LEFT JOIN members m ON
u.member_id = m.id`) cannot run against it — it returns `server_error`.
Phase 1 must extend the seed before testing the teachers root list. This
is a seed limitation, **not** a product defect.

---

## Test matrix for the rebuild (brief §13)

| Area | Checks | Status |
|---|---|---|
| Navigation | opens · root lists load · selection · breadcrumb · drill-up | Phase 1 |
| State | **no auto-selection** · no leak between perspectives · filters cleared on entity change · year/term predictable | **partly done — `a885d2f`, 37 checks** |
| Filtering | only real filters reported active · never "remove filters" without filters · count correct · deterministic sort · pagination | Phase 1 |
| Empty states | all seven of §G distinct | Phase 1 (3 done) |
| Data integrity | values equal `ReportCardService` · 056 rules intact · null ≠ 0 | **done — 246 + 272 checks** |
| Security | 3 roles allowed · others get no payload | **done — 12 subtests** |
| Workflow links | submission → real workflow · report card → real card · identity preserved across drill-down | Phase 1 |

---

## Phase 0 commits

| Hash | Content |
|---|---|
| `a885d2f` | Remove automatic entity selection; explicit no-selection states; 37-check selection harness |
| *(this doc)* | Audit, architecture contract, Phase 1 boundary |
