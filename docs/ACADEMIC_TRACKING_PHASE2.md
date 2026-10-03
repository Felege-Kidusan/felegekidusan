# Academic Tracking — Phase 2

**Student Tracking: the first entity with a real workflow destination.**

Companion to `docs/ACADEMIC_TRACKING_PHASE0.md` (audit, architecture contract,
Phase 1 boundary) and `docs/ACADEMIC_TRACKING_PHASE1.md` (the four root lists
and explicit selection). This document is the permanent record of what Phase 2
actually delivered, verified against the repository at the commit below rather
than against any earlier report.

---

## 1. Executive status

| Item | Verified value |
|---|---|
| Phase | Academic Tracking — Phase 2: Student Tracking |
| Branch | `main` |
| `HEAD` | `922eafc` |
| `origin/main` | `922eafc` |
| Working tree | clean |
| Baseline (Phase 1 tip) | `733d15e` |
| Commits in Phase 2 | 5 (`28aba64`, `dc438fd`, `c70d5f7`, `3ed8002`, `922eafc`) |
| Files changed | 9 (no schema, no migration, no production data) |
| CI | Run **#38**, workflow *Backend checks*, `922eafc` — **success** |
| CI jobs | *Security and regression suite* success · *PHP syntax (php -l)* success, 307 files linted · *Migration numbering* success |
| Suite at `922eafc` | **1696 passed, 702 subtests passed, 0 failed** (confirmed in the CI #38 job log, not only locally) |

Phase 2 delivers the Students path end to end. Teachers, Subjects and Classes
remain at the Phase 1 boundary panel and are explicitly out of scope.

---

## 2. Phase 2 scope

### In scope and delivered

- A scoped student tracking screen reached only by explicit selection.
- Two new read-only API actions on the existing Education API.
- A new resolution/shaping service that performs no academic calculation.
- A batch mark-list status lookup added to the existing `SubmissionService`.
- Behavioural and equivalence test suites, plus removal of one dead helper.

### Explicitly out of scope

Teacher tracking · Subject tracking · Class tracking · Phase 3 · Phase 4 ·
academic exception/override handling · Phase C synchronisation · any second
calculation engine · any second permission system · schema redesign.

Section 20 records the deferred list in full; section 21 records the Phase 3
boundary without implementing it.

---

## 3. Product / UX model

Academic Tracking is a tracking and workflow system. It is **not** another
generic analytics dashboard. The model is:

```
LIST → SELECT ENTITY → SCOPED CONTEXT → RELATED DATA → DETAILED WORKFLOW → ACTION
```

Four entry points exist, established in Phase 1:

| Entry point | Phase 1 | Phase 2 |
|---|---|---|
| **Students** | root list, explicit selection | **full tracking workflow** |
| Teachers | root list, explicit selection | boundary panel only |
| Subjects | root list, explicit selection | boundary panel only |
| Classes | root list, explicit selection | boundary panel only |

Phase 2 implements exactly one path: `Students → Student → Student Tracking`.

Charts support the tables and the workflow; they never replace them, and the
chart runtime is optional (see section 16).

---

## 4. Student tracking workflow

Flow as delivered:

```
Students (root list)
  → user explicitly selects a student
    → Student Tracking (scope = that student)
      → scoped academic context (year / term)
        → related academic data (subjects, assessments, attendance)
          → the real record: the existing report card
```

Delivered sections, verified in `admin/js/academic_tracking.js`
(`STUDENT_SECTIONS`):

| Section | Key | Icon | Data source | Load |
|---|---|---|---|---|
| Overview | `overview` | `fa-gauge-simple-high` | `tracking_student_detail` | with the detail |
| Subjects | `subjects` | `fa-book-open` | `tracking_student_detail` | with the detail |
| Assessments | `assessments` | `fa-clipboard-list` | `tracking_student_assessments` | on first open |
| Attendance | `attendance` | `fa-user-check` | `tracking_student_detail` | with the detail |
| Report Card | `report_card` | `fa-file-lines` | `get_report_card` (existing endpoint) | on first open |

The Report Card section reuses the already-existing endpoint at
`admin/api_communication.php:342`. It was not duplicated or reimplemented.

---

## 5. Scope and context model

Three concepts stay separate, because conflating them is what made the first
attempt mislead users (Phase 0, §4C).

| Concept | Meaning in Phase 2 | Carried in the payload as |
|---|---|---|
| **Scope** | the student the user explicitly selected | `scope.type`, `scope.member_id`, `scope.class_id` |
| **Context** | the reporting period: academic year and term | `context.year_id`, `year_name`, `term_id`, `term_name`, `is_annual`, `semester_weights` |
| **Filters** | search / class / status on the root list | list state only; never part of the student scope |

Rules preserved from Phase 0 and Phase 1:

- A selected entity is **not** an active filter and is never reported as one.
- Year and term are context, not filters, and not the selected entity.
- The class is resolved from the active enrolment when the caller does not
  supply one; a supplied class that contradicts the enrolment is refused
  rather than guessed (`test_the_wrong_class_is_refused_rather_than_guessed`).

---

## 6. Data-state semantics

The governing rule: **absence of data is never rendered as a numeric result.**
`no data ≠ zero`.

### Backend state names (verified constants)

`AcademicTrackingService` exposes six states:

| Constant | Value | Meaning |
|---|---|---|
| `STATE_OK` | `ok` | data present |
| `STATE_NO_ENROLMENT` | `no_enrolment` | the student is in no class |
| `STATE_NO_SUBJECTS` | `no_subjects` | the class offers no subjects |
| `STATE_NO_RESULTS` | `no_results` | subjects exist, nothing recorded |
| `STATE_NO_ASSESSMENTS` | `no_assessments` | no assessment has been created |
| `STATE_NO_ATTENDANCE` | `no_attendance` | no attendance row exists at all |

`studentDetail()` returns `data_state` as three independent regions —
`subjects`, `results`, `attendance` — so one empty region never blanks the
others. `studentAssessments()` returns a single `data_state`.

Error codes are named rather than generic, via `errorCode()`: `no_enrolment`,
`not_in_class`, `unavailable`, plus `invalid_student` for a rejected id and
`server_error` for a caught exception.

### Front-end states

| State | How it is shown |
|---|---|
| no entity selected | *"Nothing is selected yet. Choose an entry point, find the record you need, then open it."* |
| loading | skeleton placeholder (`.at-skel`), animation disabled under `prefers-reduced-motion` |
| ready | the data |
| no records / not offered / no assessments / no results / no attendance | distinct worded panels, each explaining the cause |
| filtered empty | *"No … match your search"*, naming the applied filters, with Clear filters |
| unfiltered empty | *"… are available for this academic context"* — states explicitly that it is **not** a filter problem |
| error | *"We couldn't load …"* with Try again, distinguished from empty |
| entity with no workflow yet | *"… tracking is not built yet"* boundary panel |

### Why null is not zero

Two concrete cases drove this:

1. **Attendance.** The engine's `fetchAttendance()` returns `rate => 0`
   together with `total => 0`; `total` is the only honest discriminator. The
   tracking layer therefore nulls **every** attendance field when
   `has_attendance` is false, rather than passing the placeholder zero
   through. The UI says in words that this *"is not the same as an attendance
   rate of 0% — nobody has taken the register"*.
2. **Unmarked assessments.** A planned assessment nobody has marked reports
   `has_result: false`, `score: null`, label **"Not started"**. It is rendered
   as a dash, never `0`.

A dash helper exists for exactly this purpose and is documented in source as
*"A dash is the visual form of 'no value exists'. Never a zero."*

---

## 7. Backend / API architecture

### Endpoints (extended in place — no parallel API file)

Both actions were added to the existing `admin/api_education.php`.

| Action | Returns |
|---|---|
| `tracking_student_detail` | identity, scope, context, overview, subjects, attendance, data_state, pass_mark, grade_scale |
| `tracking_student_assessments` | scope, context, per-assessment rows, `unplanned_weight`, data_state |

### Request parameters

| Parameter | Type | Behaviour |
|---|---|---|
| `member_id` | int, required | `<= 0` → HTTP 400, `invalid_student` |
| `class_id` | int, optional | when omitted, resolved from the active enrolment |
| `year_id` | int, optional | defaults to the current academic year |
| `term_id` | int, optional | defaults to `0`, which the engine treats as annual |

### Service

`admin/backend/services/AcademicTrackingService.php` — `namespace App\Services`,
`final class`, 405 lines. Public surface:

- `studentDetail(\mysqli $conn, int $memberId, int $classId = 0, int $yearId = 0, int $termId = 0): array`
- `studentAssessments(\mysqli $conn, int $memberId, int $classId = 0, int $yearId = 0, int $termId = 0): array`

Private helpers: `assessmentRow()`, `memberStatus()`, `positiveOrNull()`,
`errorCode()`.

### How the backend prevents the frontend from guessing

The payload states every fact the UI would otherwise have to infer:

- **scope** — which student and which class the answer belongs to;
- **context** — which year/term produced it, whether it is annual, and the
  semester weights in force;
- **data_state** — per region, why a region is empty;
- **`has_result` / `has_attendance`** — explicit booleans rather than relying
  on a magic value;
- **`pass_mark` and `grade_scale`** — supplied by the engine so the browser
  never defines them;
- **named error codes** — so a failure is distinguishable from emptiness.

---

## 8. Calculation authority

**`ReportCardService` remains the single authoritative academic calculation
engine.** Phase 2 created no second engine.

`AcademicTrackingService` is a resolution and shaping layer. It calls
`ReportCardService::getCard()` and reads results from it; it also takes
`PASS_MARK` and `GRADE_SCALE` from the engine rather than restating them.

Rules that stay in `ReportCardService`: grade letters, final percentages,
averages, pass/fail interpretation, ranking, semester handling, full-year
subject handling, and report-card composition.

### The permanent architectural rule

> **Academic values may be rendered in JavaScript, but academic values must
> never be calculated in JavaScript.**

### The tests that enforce it

Four tests, all verified in the repository:

| Test | File | What it checks |
|---|---|---|
| `test_the_tracking_service_contains_no_academic_formula` | `test_student_tracking.py:717` | the PHP service contains no formula |
| `test_the_controller_contains_no_academic_formula` | `test_student_tracking.py:742` | strips comments, then forbids `weight *`, `/ max * 100`, `>= 90/80/70`, `reduce(...) / x.length` |
| `test_the_controller_performs_no_academic_calculation` | `test_academic_tracking.py` | strips comments, then forbids a grade-letter threshold, a hard-coded pass mark (`PASS_MARK\|pass_mark [:=] digit`), `* weight` / `weight *`, `weight /`, `.reduce(`, assignment to `final_percentage`, assignment to `semester_[12]_average` |
| `test_the_controller_still_only_displays_engine_numbers` | `test_academic_tracking.py` | the companion: each academic field must be **read from a payload** (`x.field`) and must **never** be assigned a literal |

The Phase 1 version of this rule banned the *vocabulary* (`grade_letter`,
`final_percentage`, `weight`). That worked only while the controller never
touched an academic field. Phase 2 must legitimately display those fields, so
the ban was moved from the nouns to the arithmetic. The rule is unchanged; the
test is harder to pass, not easier, and was mutation-verified (section 15).

In addition, `CalculationEquivalenceTests` compares the endpoint against the
engine field by field — overall average, rank, and every subject's final
percentage and grade letter — by invoking `ReportCardService` directly as an
oracle. If the tracking layer ever begins computing its own answer, the two
stop agreeing.

---

## 9. Semester and full-year policy

Phase 2 **consumes** the existing policy and does not restate it.

| Fact | Where it is decided | How Phase 2 handles it |
|---|---|---|
| duration of a class offering | `class_subjects` / `SubjectDurationPolicy` | passed through as `duration_type` |
| semester weights for the year | engine, per academic year | passed through as `context.semester_weights` and per subject |
| whether the view is annual | engine (`term_id = 0` ⇒ annual) | surfaced as `context.is_annual` |
| final for a semester-only subject | engine | displayed verbatim |
| final for a full-year subject | engine | displayed verbatim |
| subject completion status | engine (`CLOSED` / `CONTINUING` / `PENDING`) | rendered as Complete / In progress / Pending |

The UI renders duration verbatim — *Full year*, *Semester*, *Unclassified* —
with the source comment *"Duration is policy, shown verbatim so SEMESTER_ONLY
never reads as FULL_YEAR."* An offering with no classification is shown as
**Unclassified**, not silently defaulted.

A full-year subject missing one semester stays **pending** with a null final
percentage; it is never scored as if the missing mark were zero
(`test_a_pending_full_year_subject_is_null_and_never_zero`).

No new academic formula was introduced.

---

## 10. Assessment workflow status vs academic result

These are two different facts and Phase 2 never merges them.

- **Workflow status** — how far the *teacher's mark-list submission* has
  travelled through review, for the whole class.
- **Academic result** — *this student's* own score on that assessment.

Both are reported independently, in separate columns, with an on-screen note
explaining why: an approved mark list can still carry no score for one
student, and a score can exist before the list is approved.

### Statuses actually used

Stored values come from `SubmissionService` constants: `incomplete`, `draft`,
`submitted`, `approved`, `rejected`, `revision_needed`.

`SubmissionService::statusLabel()` maps them to the displayed labels:

| Stored status | Displayed label |
|---|---|
| `incomplete`, `draft` | Incomplete |
| `submitted` | Complete |
| `approved` | Approved |
| `rejected` | Rejected |
| `revision_needed` | Needs revision |

The tracking layer adds exactly one label of its own, for the case the
workflow vocabulary has no word for: when no packet exists **and** no marks
exist, `workflow_status` is `null` and the label is **"Not started"**. No new
status system was created.

`test_status_and_result_are_independent` pins the separation.

---

## 11. SubmissionService integration

`SubmissionService` remains the owner of submission and review rules. Phase 2
consumes that architecture rather than duplicating it.

### What `28aba64` added

| Method | Purpose |
|---|---|
| `marklistPacketStatuses(\mysqli, array $ids): array` | packet status for many assessments in one query |
| `resolvedMarklistStatuses(\mysqli, array $ids): array` | resolved status for many assessments in one query |

The pre-existing single-id methods `marklistPacketStatus()` and
`resolvedMarklistStatus()` are retained for their current caller
(`api/v1/routes/grades.php:746`) and are now thin delegates to the batch
versions.

**Why it exists.** The student view lists every assessment across every
subject a student takes. A per-row lookup would be an N+1. More importantly,
the C2/H8 precedence rule — *a submitted or approved packet is the workflow
truth, so a newer draft packet must never silently re-open a locked mark
list* — now exists in exactly one implementation instead of being copied
beside itself where the two copies could drift.

The batch query orders ascending so the last write wins, which returns the
same answer as the per-id `ORDER BY id DESC LIMIT 1`. This is a performance
and single-source-of-truth change, **not** a new submission model.
`test_the_batched_status_lookup_has_one_implementation` pins it.

---

## 12. Authorization and security

No second permission system was introduced. Phase 2 reuses the existing rules.

### Path

1. **Tier 3 — Education analytics gate.** Both new actions were appended to
   the existing `$__analyticsActions` list in `admin/api_education.php`,
   alongside `get_education_hub`, `filter_students_performance`,
   `get_academic_intelligence` and `get_academic_intelligence_options`. The
   payload class is identical — another person's marks, grades, rank and
   attendance — so the actions joined the existing tier rather than receiving
   a gate of their own.
2. **Identifier validation.** `member_id` is cast and rejected when `<= 0`
   before it reaches any query. The browser is not trusted to supply a sane
   id.
3. **Class visibility on the way in.** When the caller supplies `class_id`, it
   is checked with `ReportCardService::canViewClass()` — the same rule the
   report card uses.
4. **Class visibility on the way out.** When the class was resolved from the
   enrolment rather than supplied, the resolved class is re-checked (see
   section 19 for the honest limitation).
5. **No local access SQL.** The endpoint issues no queries of its own; a test
   forbids `->query(`, `->prepare(`, `->real_query(` and `mysqli_query` inside
   the tracking block.

The selected student id is never trusted merely because the frontend sent it:
the class is resolved server-side from the active enrolment, a contradictory
`class_id` is refused, and the returned payload is asserted to belong to the
requested member (`test_the_payload_always_belongs_to_the_requested_student`).

### Observed role behaviour

Verified by probing the endpoints during Phase 2 verification:

| Role | `tracking_student_detail` |
|---|---|
| `super_admin`, `school_admin`, `edu_dept` | allowed |
| `teacher` | refused — *"Academic analytics are available to the Education department only."* |
| `attendance_taker` | refused — same tier-3 message |
| `finance_dept` | refused — same tier-3 message |
| `hr_dept` | refused earlier, by the pre-existing Education-action restriction |

A refused role receives no academic payload at all, not a reduced one
(`test_a_refused_role_gets_no_academic_data_at_all`).

---

## 13. Performance architecture

Lazy and scoped. Nothing academic is computed before an explicit selection.

### Root-list navigation

| Measurement | Value |
|---|---|
| All four root lists together | **16 queries** |
| Queries touching `academic_records`, `assessments`, `attendance` or duration/calculation tables | **0** |
| `ReportCardService` executions | **0** |

The zero is the important figure: the query log for all four root lists
contains no academic table at all, so the engine cannot have run during
navigation. Phase 1's guarantee is preserved.

### After selection

| Operation | Queries |
|---|---|
| `tracking_student_detail` (student with data) | **5** |
| `tracking_student_assessments` | **5** |
| `tracking_student_detail` (student in no class) | **4** |

Cost is flat per selection rather than proportional to the school.

### Front-end laziness

Overview, Subjects and Attendance arrive with the single detail call.
Assessments and Report Card each fetch on first open and exactly once;
switching students discards both so a stale section cannot be shown under a
new scope.

### Measurement provenance

These are manual measurements taken with the MariaDB general query log against
the `ssms_e2e` harness seed during Phase 2 verification. **No automated
assertion pins the query counts**, so they must be re-measured rather than
assumed after changes to the lists or the service.

Only same-seed measurements appear in this document. Earlier cross-seed
extrapolated comparisons were withdrawn as unsound and are deliberately not
repeated here.

---

## 14. Testing and verification

### Suite at `922eafc`

| Suite | Result | Verified by |
|---|---|---|
| `tests/security` (full) | **1696 passed, 702 subtests passed, 0 failed** | CI #38 job log, and twice locally |
| `tests/e2e/academic_tracking_lists.js` | **140 checks, 0 failed** | re-run at `922eafc` |
| `tests/e2e/student_tracking.js` | **122 checks, 0 failed** | re-run at `922eafc` |
| `tests/e2e/academic_intelligence.php all` | **255 checks, 0 failed** | run during Phase 2 verification |
| `php -l`, every first-party PHP file | 307 files, clean | CI #38 |
| Migration numbering | success | CI #38 |

Commands:

```bash
# full suite
SSMS_DB_NAME=ssms_comm_e2e SSMS_SYNC_DB=ssms_e2e SSMS_E2E_PHP=$(command -v php) \
  python3 -m pytest tests/security -q -p no:cacheprovider

# front-end behaviour (no database required)
node tests/e2e/academic_tracking_lists.js
node tests/e2e/student_tracking.js

# destructive scenario harness
SSMS_AUDIT_TESTING=1 SSMS_SYNC_DB=ssms_e2e php tests/e2e/academic_intelligence.php all
```

### Phase 2 test inventory

| File | Classes | Test methods |
|---|---|---|
| `tests/security/test_student_tracking.py` | 10 | 59 |
| `tests/security/test_academic_tracking.py` (extended) | 9 | 54 |

`test_student_tracking.py` groups: `CalculationEquivalenceTests`,
`StudentDetailTests`, `StudentAssessmentsTests`, `StudentAttendanceTests`,
`StudentEmptyStateTests`, `StudentTrackingAuthorizationTests`,
`StudentReportCardTests`, `StudentTrackingContractTests`,
`StudentTrackingControllerTests` (which runs the node harness).

### Fixtures that make the hard states reachable

`scenario_student_tracking_fixture` in `tests/e2e/academic_intelligence.php`
seeds the situations that are easy to get wrong:

| Fixture | Proves |
|---|---|
| student with marks but **zero attendance rows** | every attendance field null, not zero |
| student enrolled with **nothing recorded** | `no_results`, null average/rank, subjects still listed |
| student in **no class** | `no_enrolment` as its own state |
| full-year subject missing semester 2 | stays pending, final percentage null |
| weighted assessment **nobody has marked** | `has_result: false`, "Not started" |

### Correction: the withdrawn `comm_e2e` failure claim

An earlier report described four pre-existing failures in
`tests/security/test_comm_e2e.py`. **That claim is withdrawn.** A run of the
pristine Phase 1 tip `733d15e` produced **1636 passed, 0 failed**. The earlier
failures were environmental and are not product or test defects. They must not
be cited as current failures.

---

## 15. Mutation testing

Both Phase 2 suites were mutation tested before being trusted. Bugs were
injected, the suites re-run, and every mutation reverted.

### Mutations that were caught

Leaking the engine's placeholder zero as a real attendance rate · treating
workflow status as proof of a result · recomputing the average locally ·
inventing a semester-2 score for a semester-only subject · dropping the
tracking actions out of the authorization tier · collapsing the
`no_enrolment` state · returning a neighbouring student's card · running the
endpoint's own access SQL · auto-selecting the first row (29 failures in the
list harness) · falsely reporting filters as active (8 failures) · deriving a
grade letter in JS · hard-coding a pass mark in JS · averaging in the browser
· making an authorization decision in JS · faking the boundary panel with a
chart.

### Mutations that escaped, and the test improvements they forced

**1 — stale-response race guard.** Removing the guard did not fail anything.
The stub delayed *every* response equally, so the abandoned student's reply
could never land last and the race never occurred. The stub gained per-call
ordering (`perCall: { tracking_student_detail: [80, 5] }`) so the **abandoned**
request is the slow one. The mutation now fails, proving a stale response
cannot overwrite a newer selection.

**2 — blank "Not started" fallback.** Emptying the fallback did not fail
anything, because the stub always supplied a label. The path is real:
`assessmentRow()` seeds `workflow_label` as `''` and the enrichment loop
overwrites it, so a skipped row would arrive blank. A fixture row with no
label was added, and the UI must not render an empty status chip.

**3 — `canViewClass` gate.** Deleting either visibility check left the other
behind to satisfy a substring assertion. The two are now pinned independently
by position relative to the service call: the inbound check must precede
`AcademicTrackingService::`, the recheck must follow it, and exactly two
`canViewClass` calls must exist. Both deletions now fail. This proves **source
placement, not a role-dependent behavioural difference** — see section 19.

**4 — dead `hasFilters()` helper.** The method could be made to return
anything with no test failing, because **nothing called it**. The empty states
and filter badge read `activeFilters(key).length` directly. It shipped unused
in Phase 1 and was removed in `3ed8002` after a repository-wide reference
search returned zero references outside its own definition.

### A false positive found in the tests themselves

An assertion intended to forbid raw SQL in the endpoint matched the word
`SELECT` inside the user-facing message *"A student must be selected."* It was
replaced with pins on actual execution calls (`->query(`, `->prepare(`,
`->real_query(`, `mysqli_query`).

---

## 16. UI / accessibility rules

Permanent requirements for Academic Tracking, applying to all future phases:

- professional visual hierarchy; progressive disclosure over a wall of data;
- the selected scope is always visible, and the academic context is always
  stated;
- truthful empty states — every empty region explains *why* it is empty;
- never offer to clear filters unless filters are genuinely applied;
- no automatic entity selection, ever;
- no fabricated zeros; a missing value renders as a dash;
- charts support tables and workflows rather than replacing them;
- responsive layout; accessible controls; `aria-busy` on loading regions, text
  labels beside colour-coded bars, and animation suppressed under
  `prefers-reduced-motion`;
- the chart runtime stays optional — rendering is guarded by
  `typeof Chart === 'undefined'`, so a missing runtime degrades to the table
  instead of throwing. No second chart library.

### Icon rule

**Academic Tracking must use professional icons from the existing icon set
(Font Awesome). Emojis must never be used as UI icons.** This is a permanent
rule for all future phases.

Enforced by `test_the_controller_uses_no_emoji` (which scans the astral
pictograph ranges plus `\u2600-\u27BF`, `\uFE0F`, `\u2B00-\u2BFF`) and
`test_icons_come_from_the_existing_font_awesome_set`.

---

## 17. Changed files and commits

### Commits

| Commit | Title | Files | What it actually changed |
|---|---|---|---|
| `28aba64` | SubmissionService: batch mark-list status lookups without a second rule | 1 | Added `marklistPacketStatuses()` and `resolvedMarklistStatuses()`; made the two single-id methods delegate to them so the C2/H8 precedence rule has one implementation. No behaviour change. |
| `dc438fd` | Academic Tracking: scoped student endpoints over the existing engine | 3 | New `AcademicTrackingService.php` (405 lines); the two actions and their authorization block in `api_education.php` (+88); `scenario_student_tracking_fixture` in the e2e harness (+50). |
| `c70d5f7` | Academic Tracking: the student workflow screen | 3 | The student workflow UI in `academic_tracking.js` (+881); list harness updated (+23/−); two Phase 1 assertions re-pointed in `test_academic_tracking.py` (+115). |
| `3ed8002` | Remove `hasFilters()`, a dead helper mutation testing found | 1 | Deleted 4 lines. Nothing else. |
| `922eafc` | Tests for the student tracking workflow | 2 | New `tests/e2e/student_tracking.js` (765) and `tests/security/test_student_tracking.py` (839). |

### Files changed in Phase 2 (`733d15e..922eafc`), by group

**Frontend (1)**
- `admin/js/academic_tracking.js`

**Backend / API (1)**
- `admin/api_education.php`

**Services (2)**
- `admin/backend/services/AcademicTrackingService.php` *(new)*
- `admin/backend/services/SubmissionService.php`

**Tests (5)**
- `tests/e2e/student_tracking.js` *(new)*
- `tests/e2e/academic_tracking_lists.js`
- `tests/e2e/academic_intelligence.php`
- `tests/security/test_student_tracking.py` *(new)*
- `tests/security/test_academic_tracking.py`

**Documentation (1)**
- `docs/ACADEMIC_TRACKING_PHASE2.md` *(this file, committed separately)*

Files **not** changed by Phase 2, despite being central to it:
`ReportCardService.php`, `admin/api_communication.php`,
`admin/dashboards/edu_dept.php`.

---

## 18. Database / migration impact

Verified by inspecting the full `733d15e..922eafc` file list.

| Item | Result |
|---|---|
| Migrations added | **none** |
| Schema changes | **none** |
| Files under `sql/` touched | **none** |
| Production database changes | **none** |
| Production data modified | **none** |
| Deployment performed | **none** |

Phase 2 reuses the existing schema in full. The CI *Migration numbering* job
passed on `922eafc`.

Pre-existing production actions from earlier work remain outstanding and are
unaffected by Phase 2; they are tracked in the Phase 0 record, not here.

---

## 19. Known limitations

### 19.1 The `canViewClass` recheck is source-pinned, not behaviourally proven

A defence-in-depth recheck exists: when `class_id` was not supplied, the class
the engine resolved is re-checked with `canViewClass()` before the payload is
returned, so omitting `class_id` cannot reach a class the caller may not see.

**The current tests cannot demonstrate a behavioural difference.** All three
roles that can pass the tier-3 gate satisfy the same class-visibility
condition, so the branch is unreachable from outside today. The tests
therefore pin its presence and its position in source, and
`test_the_resolved_class_is_rechecked_when_it_was_not_supplied` says so
explicitly in its docstring.

This is an observability and test-coverage limitation, not a defect, and it is
recorded rather than hidden. The recheck exists so that widening the tier in a
later phase cannot silently open a hole — at which point it becomes
behaviourally testable and should be tested.

### 19.2 Query counts are not pinned by automated tests

The figures in section 13 are manual measurements. Nothing in the suite fails
if a future change reintroduces academic queries into root-list navigation.
Re-measure after changes to the lists or to `AcademicTrackingService`.

### 19.3 The standalone render harness needs a payload directory

`tests/e2e/academic_intelligence_render.js` requires a payload directory that
was destroyed in a sandbox rollback, so it cannot currently be executed
standalone without restoring or regenerating that directory. It is still
exercised through pytest where applicable. This is a **test-fixture
availability issue in the development environment, not a Phase 2 product
defect**, and the harness was deliberately left unmodified.

### 19.4 Withdrawn claims

- The four `test_comm_e2e.py` failures reported earlier were environmental;
  pristine `733d15e` runs 1636 passed, 0 failed (section 14).
- An earlier extrapolated cross-seed performance comparison was withdrawn as
  unsound. Only same-seed measurements appear in this document.

---

## 20. Deliberately deferred work

Verified absent from the Phase 2 tree. `test_phase_three_workflows_were_not_built`
asserts that neither the controller nor the API contains `renderTeacher`,
`renderSubjectDetail`, `renderClassDetail`, `tracking_teacher_detail`,
`tracking_subject_detail` or `tracking_class_detail`.

| Deferred | Status |
|---|---|
| Teacher tracking | not implemented |
| Subject tracking | not implemented |
| Class tracking | not implemented |
| Phase 3 | not started |
| Phase 4 | not started |
| Academic exception / override handling | not implemented |
| Phase C synchronisation | not implemented |
| A new academic calculation engine | deliberately never to be built |
| A new permission system | deliberately never to be built |
| Speculative database redesign | not performed |

---

## 21. Phase 3 boundary

The next planned phase is:

## Phase 3 — Teacher Tracking

**Not implemented. Not started.** The intended high-level path only:

```
Teachers
  → select a teacher
    → teacher tracking context
      → classes / subjects they are assigned
        → select class + subject
          → assessments
            → submission / workflow status
              → direct workflow action
```

Design and verification belong to Phase 3 itself. The following must **not**
be created:

- teacher effectiveness scoring, teacher quality scores, or any teacher
  ranking — a standing prohibition carried from Feature 1;
- best/worst teacher comparisons or speculative analytics;
- new workflow states;
- new permissions or a second authorization system;
- new database structures.

Phase 3 must reuse the existing teacher-assignment rules, the existing
submission and review workflow, and `ReportCardService` for anything
academic — exactly as Phase 2 did.

---

## 22. Final acceptance checklist

| # | Check | Status |
|---|---|---|
| 1 | `docs/ACADEMIC_TRACKING_PHASE2.md` exists | yes |
| 2 | Content verified against the repository, not an earlier report | yes |
| 3 | Phase 0 and Phase 1 referenced consistently | yes |
| 4 | Student Tracking scope clearly defined | section 2, 4 |
| 5 | Teacher / Subject / Class explicitly outside Phase 2 | sections 2, 20, 21 |
| 6 | `ReportCardService` identified as the calculation authority | section 8 |
| 7 | JavaScript calculation prohibition documented, with the enforcing tests | section 8 |
| 8 | Workflow status distinguished from academic result | section 10 |
| 9 | Authorization architecture documented | section 12 |
| 10 | `canViewClass` limitation documented honestly | section 19.1 |
| 11 | Only verified, same-seed performance numbers used | section 13 |
| 12 | Withdrawn historical performance claim not repeated | sections 13, 19.4 |
| 13 | Mutation testing findings documented | section 15 |
| 14 | `hasFilters()` removal documented (`3ed8002`) | sections 15, 17 |
| 15 | Withdrawn `comm_e2e` claim not presented as current | sections 14, 19.4 |
| 16 | Render-harness payload limitation documented | section 19.3 |
| 17 | Professional icon / no-emoji rule documented | section 16 |
| 18 | No production DB or schema change claimed or made | section 18 |
| 19 | Phase 3 boundary documented but not implemented | section 21 |
| 20 | No application files modified by this task | documentation-only commit |
| 21 | No test files modified by this task | documentation-only commit |
| 22 | No API behaviour changed | documentation-only commit |
| 23 | No migrations created | section 18 |
| 24 | No unrelated cleanup performed | documentation-only commit |

---

*Record of Academic Tracking Phase 2 at `922eafc`. Phase 3 is not started.*
