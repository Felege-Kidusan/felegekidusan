# Academic Tracking — Phase 1 outcome

Supersedes §H of `ACADEMIC_TRACKING_PHASE0.md`. Records what Phase 1 shipped, what it
deliberately did not ship, and the findings that belong to a later phase.

Commits: `8f6e3b2` (fixture) · `a17f997` (API) · `152632a` (controller + wiring) · `686a8c4` (tests).

---

## 1. What shipped

Four root lists — Students, Teachers, Subjects, Classes — reached from a home screen of
four entry cards. Each list has search, filters, sort, pagination, a true result count,
and explicit selection. Nothing is selected for the user at any point.

The controller is `admin/js/academic_tracking.js`, mounted in `sec-academic-tracking`.
It is additive: `sec-academic-intel` and `academic_intelligence.js` are untouched.

### State is split three ways

| concept | holds | cleared by |
|---|---|---|
| `context` | academic year | never (defaults to `is_current`) |
| `scope` | the selected entity, `null` until a click | year change, `clearSelection()`, `goHome()` |
| `lists[key].filters` | only what the user applied | `clearFilters()`, leaving the list |

A selected entity never enters the filter bag, so it is never counted as an active
filter and never produces a "remove filters" message. This is the structural fix for
Phase 0 §4C: the old code suppressed scope ids from the filter count with a hardcoded
denylist (`academic_intelligence.js:773`); the new code cannot acquire them.

### API

`get_classes` (`api_education.php`) and `get_subjects` (`api_subjects.php`) were
extended **in place** — no parallel API file. `roster` and `list_teachers` already had
the needed shape and were reused unchanged.

Paging is **opt-in**: it engages only when `page` or `per_page` is present. Without
them the responses are byte-identical to before, verified by capture → `git stash` →
capture → diff. Live dependents `edu_dept.php:2049` and `run_smoke.sh:164,669` are
unaffected.

Sorting goes through a server-side allowlist map, never string interpolation.
`per_page` clamps to 10–100, `page` floors at 1, `q` is a bound LIKE.

---

## 2. What Phase 1 did not build, by design

Selecting an entity opens a **boundary panel** naming the entity and stating that its
tracking screen arrives in Phase 2. It shows no KPIs, no charts and no invented
numbers. A placeholder that displays a plausible-looking zero is worse than one that
admits it is a placeholder.

Not built: detail screens, drill-down, progress/results/status surfaces, workflow
links, breadcrumbs beyond list-level, any calculation.

---

## 3. Measured behaviour

Every root list costs a **flat 3 statements** — year resolve, COUNT, one page —
regardless of filters, sort or dataset size. Measured on a 45-student / 15-teacher
fixture via the general query log.

| request | queries |
|---|---|
| any of the four root lists, any filter combination | 3 |
| old: options catalogue | 6 |
| old: class perspective | 13 |
| old: subject perspective (2 classes) | 29 |
| old: teacher perspective (2 assignments) | 30 |

Grepping the query log for `academic_records`, `assessments`, `attendance` and
`class_subject_durations` returns **nothing** for all four root lists. No academic
table is read before an entity is chosen, so `ReportCardService` cannot have run.
The old class perspective touches three of the four.

---

## 4. Calculation boundary

Phase 1 performs no academic calculation. The controller contains no occurrence of
`weight`, `semester`, `final_percentage`, `pass`, `rank`, `grade` or `average` in a
computational position; `tests/security/test_academic_tracking.py` pins this with a
regex table. `ReportCardService` remains the only engine, and Phase 1 did not modify
it.

Counts shown in the lists (`assigned_classes`, `student_count`, `class_count`) are
enrolment and assignment cardinalities, not academic results.

---

## 5. Findings recorded, not acted on

### 5.1 `roster` and `list_teachers` are broader than tier 3

Effective HTTP access, after `access_control.php` (auto-included at `config.php:901`,
and exempt for CLI by design — so any matrix built from the CLI harness overstates
access):

| action | effective roles |
|---|---|
| `roster` | super_admin, school_admin, edu_dept, teacher, attendance_taker |
| `list_teachers` | same five |
| `get_classes` | same five + hr_dept |
| `get_subjects` | super_admin, school_admin, edu_dept, teacher |

`roster` and `list_teachers` sit outside the tier-3 `$__analyticsActions` list that
guards the analytics actions. Phase 1 **adds no access**: the tracking UI lives in
`edu_dept.php`, which `access_control.php:118` restricts to the three intended roles,
and `edu_scrub_phone()` still masks phone numbers for `teacher` and
`attendance_taker`.

Moving these two actions into tier 3 would change authorization for consumers outside
Academic Tracking. That is a shared-surface change and is out of the Phase 1 boundary.
**Reported, not done.**

### 5.2 `list_teachers` had no frontend consumer

Before Phase 1 no JavaScript called `list_teachers`; `edu_dept.php:1775` uses
`api_teachers.php` instead. Academic Tracking is its first consumer. Worth knowing
before anyone assumes the action is load-bearing elsewhere.

### 5.3 Pagination floor

`roster` and `list_teachers` clamp `per_page` with `max(10, …)`. A `per_page` below 10
is silently raised. This is existing behaviour, left alone; it is the reason the test
fixture seeds more than 10 rows per list.

### 5.4 Filter-count denylist

`academic_intelligence.js:773` counts active filters by excluding known scope keys from
the filter bag. The new controller does not share this code. When Phase 2 mounts the
intelligence renderer behind the selection boundary, that denylist should be deleted
rather than carried forward.

---

## 6. Phase 2 boundary

Phase 2 mounts the detail screens behind the selection the user now makes explicitly.
It consumes `scope` and `context` from this controller, calls the existing detail
endpoints, and renders results produced by `ReportCardService`. It must not add a
second calculation path, a second authorization system, or a second chart runtime.

The four boundary panels are the mount points.

---

## 7. Tests

`tests/security/test_academic_tracking.py` — 53 tests, 28 subtests, against a live
database: the four root lists, backward compatibility, authorization, controller
behaviour (shelled out to the node harness) and source contracts.

`tests/e2e/academic_tracking_lists.js` — 135 behavioural checks against a stubbed
`fetch` that reproduces real server semantics (filter → count → LIMIT/OFFSET).

The node harness was mutation-tested. Each bug was injected into a copy of the
controller, the harness re-run, and the controller restored byte-identical:

| injected bug | failures raised |
|---|---|
| auto-select `rows[0]` | 29 |
| count from `rows.length` instead of `total` | 2 |
| always render "Clear filters" | 5 |
| collapse the two empty states into one | 2 |
| disable the `_seq` race guard | 2 |

Full suite at `686a8c4`: 1636 passed, 545 subtests, 0 skipped, 0 failed.
