# Academic Tracking — Phase 5

**Class Tracking: the fourth and last root entity.**

Companion to `docs/ACADEMIC_TRACKING_PHASE0.md` (audit, architecture contract),
`PHASE1.md` (the four root lists and explicit selection), `PHASE2.md` (Student
Tracking), `PHASE3.md` (Teacher Tracking) and `PHASE4.md` (Subject Tracking).
This document is the permanent record of what Phase 5 actually delivered,
verified against the repository at the commits below rather than against any
earlier report.

Every claim below is labelled. **Verified** means it was reproduced against the
live fixture database or read back from `information_schema` during this phase.
**Limitation** means it is a known shortcoming of what shipped. **Deferred**
means it was deliberately not done. Nothing here is an assumption presented as
a fact.

---

## 1. Executive status

Class Tracking is implemented, tested and committed. With it, all four root
entities — students, teachers, subjects and classes — have real workflow
destinations, and the Academic Tracking surface is complete as scoped.

| | |
|---|---|
| Commits | `9f2864c`, `ee9bf2c`, `59b953c`, `b9bfe96` (+ this document) |
| Baseline | `c24510b` (Phase 4, CI #43 green) |
| Schema changes | **none** |
| New endpoints | 5, all inside `admin/api_education.php` |
| New files | 2, both tests |
| Test totals | **CI #44: 1958 passed, 0 failed, 926 subtests** |
| Added this phase | 62 pytest tests (98 subtests), 189 node checks |
| Mutation testing | 24 run, **24 caught**, 0 survivors |
| Query growth | **flat** at 1 / 5 / 10 offerings on every endpoint |

This is a report of a verified test state, not a claim of correctness. No
statement anywhere in this document should be read as "zero bugs".

---

## 2. Phase 5 scope

**In scope.** The class workflow: `CLASS LIST → SELECT CLASS → CLASS TRACKING
CONTEXT → STUDENTS / SUBJECTS / TEACHERS → ASSESSMENTS / SUBMISSIONS / RESULTS`.

**Explicitly out of scope, and not touched:**

- No redesign of Student, Teacher or Subject Tracking.
- No Academic Intelligence changes.
- No Finance, Attendance, HR, messaging or notification work.
- No academic exception handling, no synchronization work.
- No new authentication, authorization or permission framework.
- No new database architecture, no migration.
- No unrelated UI cleanup, no opportunistic refactoring.
- No new frontend framework, chart library or dependency.

Unrelated defects found along the way are documented (§19, §20), not fixed.

---

## 3. The required workflow, and where each step lives

| Step | Where | Note |
|---|---|---|
| Class list | existing root list, `get_classes` | Phase 1 architecture, reused unchanged. No second class-management list was built. |
| Select class | `selectEntity('classes', …)` | Only ever called from a row the user activated. |
| Class tracking context | `tracking_class_detail` | Identity + reporting context + four counts + homeroom. |
| Students | `tracking_class_students` | Lazy, paginated, searchable. |
| Subjects | `tracking_class_subjects` | Offerings with teachers, assessment counts and whether marks exist. |
| Teachers | `tracking_class_teachers` | Homeroom and subject assignments, kept distinct. |
| Assessments / submissions / results | `tracking_class_assessments` | The four facts, kept separate. |
| Detailed workflow | existing screens | `openReviewModal` for a mark list; Student / Subject / Teacher Tracking for a person or a subject. |

**Tracking is an entry point into existing workflows, never a second workflow.**
The three `Track` buttons call `selectEntity` and hand over to the Phase 2, 3
and 4 screens. *Verified* behaviourally: the node harness asserts that clicking
a student's `Track` switches the scope to that student, renders the existing
student workspace, and causes `tracking_student_detail` to be fetched — i.e.
the real workflow ran, not a copy of it. Same for teachers and subjects.

---

## 4. The class domain model

Read back from the live `information_schema` during this phase, not assumed.

| Table | Columns that matter | Note |
|---|---|---|
| `classes` | `id, class_name, class_name_en, class_code, level_order, section, age_group, is_active` | **No `academic_year_id`.** |
| `class_subjects` | `id, class_id, subject_id, duration_type, term_id` | **No `academic_year_id`** (migration 056 added the last two). |
| `teacher_assignments` | `teacher_id, class_id, subject_id` **NULLABLE**, `academic_year_id` **NULLABLE**, `is_class_teacher, is_primary, is_active, assignment_role ENUM('primary','assistant','homeroom')` | Two meaningful NULLs. |
| `class_enrollments` | `member_id, class_id, academic_year_id, status` | → `members`. |
| `assessments` | `class_id, subject_id, academic_year_id, term_id, is_active` | Class-owned. |
| `academic_records` | `member_id, class_id, subject_id, assessment_id, academic_year_id, term_id, score, max_score` | The result store. |

**The consequence, and the central design decision of this phase:** a class is
year-agnostic, exactly as a subject offering is. The academic year scopes the
data hanging *off* a class — enrolments, assignments, assessments, marks — and
is never part of the class's identity.

*Verified:* a class asked about a year in which nothing happened still returns
its identity intact with honest zero counts. A class never disappears because a
year was quiet. Two tests read `information_schema` directly and fail if either
`classes` or `class_subjects` ever gains an `academic_year_id`, so this premise
cannot silently stop being true.

**§26 STOP conditions: none were met.** Class academic-year semantics are as
Phase 0–4 described, `class_subjects` is structurally unchanged,
`teacher_assignments` semantics are unchanged, enrolment and
assessment-to-class relationships are unambiguous, authorization was
established with existing primitives, no service conflict arose, no migration
was needed, no new permission model was needed, no Phase 1–4 behaviour had to
break, no calculation required duplicating `ReportCardService`, and no test
revealed an architectural contradiction.

---

## 5. The four relationships are read, never inferred

Each relationship has exactly one authoritative source:

| Relationship | Source of truth | Never inferred from |
|---|---|---|
| Students in a class | `class_enrollments` (status `active`) | marks, assessments, submissions, attendance, report cards |
| Subjects of a class | `class_subjects` | assessments that happen to name the subject |
| Teachers of a class | `teacher_assignments` + `users.role='teacher'` | who filed a mark list |
| Assessments of a class | `assessments.class_id` | a matching `subject_id` or `teacher_id` |

The relationship-integrity fixtures deliberately contain misleading records, so
that each of these is a real finding rather than an empty table:

- **A mark for a class the student is not enrolled in.** An `academic_records`
  row is inserted for student 201 (enrolled in C2) against C1. *Verified:* they
  do not appear on C1's roll and C1's student count stays 5.
- **An assessment for a subject the class does not offer.** An `assessments`
  row is inserted for C1/History, which has no `class_subjects` row. *Verified:*
  History does not join C1's offering list and C1's subject count stays 2.
- **A mark list filed by someone with no assignment.** The fixture's standing
  trap: assessment 6 is C2/History and its packet was filed by BEKELE, who holds
  no History assignment anywhere. *Verified:* C2/History reports **no teacher**,
  while C2/Geez — where he does hold an assignment — correctly reports him.
- **An assignment outliving the teacher role.** A `finance_dept` user is given
  an active assignment. *Verified:* absent from the list, absent from the
  per-subject teachers and absent from the summary count; promoting the same
  user to `teacher` makes the same row appear, proving the role was the only
  thing excluding them.
- **A withdrawn enrolment.** *Verified:* excluded from both the roll and the
  count; restoring the status brings the student back.

**Guard-the-guard is mandatory here and was applied throughout.** Every "X must
not appear" assertion is paired with one proving X exists and is reachable. A
negative test against an empty table proves nothing.

---

## 6. The two meaningful NULLs

Both are real values in this schema and neither may be reinterpreted.

**`teacher_assignments.subject_id IS NULL` = homeroom.** A homeroom assignment
covers the class itself. It is surfaced in `homeroom_teachers` and flagged
`is_homeroom` in the teacher list, and it is **never** attached to a subject
offering. *Verified* in both directions: a homeroom holder appears against no
subject (while subject teachers demonstrably do appear against theirs), and no
teacher holding a `subject_id` is ever reported as homeroom.

**`teacher_assignments.academic_year_id IS NULL` = standing.** The assignment is
not tied to one year. It is preserved as stored, flagged `is_standing`, and
returned for every year including ones it was never stamped with. *Verified:*
the stored row is re-read after the call and is unchanged — nothing rewrites a
standing assignment into the selected year. The opposite is also verified: a
year-scoped assignment is **not** flagged standing and does **not** leak into
another year.

Both the homeroom query and the teacher-list query build their own year
predicate, so both are tested separately. Mutation M18 (§15) existed precisely
because the homeroom query's standing branch was initially untested.

---

## 7. Scope, context and filters

**The selected class is a scope, not a filter.** It never enters the filter bag,
never increments the applied-filter count, and is cleared with "Clear selection"
rather than "Clear filters". *Verified:* `activeFilters('classes').length === 0`
after selecting a class, and the string `Clear filters` does not appear.

**The academic year and term are reporting context.** They are displayed in the
header as chips carrying the title *"Reporting period. Context, not a filter."*
They qualify the data shown beside the class; they are not part of what the
class is.

**"No class selected" is a real state.** There is no auto-selection, including
when exactly one class exists — the case `rows[0]` hides. *Verified:* after
opening the class list, `scope` is `null`, no `tracking_class_*` call has been
made, and no class workspace is rendered; the catalogue plus an explicit prompt
to choose is what the user sees.

**"Clear search" appears only when a search was made.** *Verified:* absent with
no query, present with one, and an empty class never offers it.

---

## 8. API endpoints

All five live in the existing `admin/api_education.php`. **No parallel API file
was created** — tested, along with the absence of `api_class_tracking.php`,
`api_tracking.php` and `api_classes.php`.

| Action | Returns | Notes |
|---|---|---|
| `tracking_class_detail` | identity, context, 4 counts, `homeroom_teachers`, `data_state` | The only call made on selection. |
| `tracking_class_students` | paginated roll, `total/page/per_page/pages` | `q` search; `per_page` clamped 10–100. |
| `tracking_class_subjects` | offerings + batched teachers, assessment counts, `has_results` | |
| `tracking_class_teachers` | assignments, homeroom vs subject, standing vs year | |
| `tracking_class_assessments` | assessments + workflow status + `submission_id` + `has_results` | Optional `subject_id`, validated against `class_subjects`. |

All five share one `case` block and one authorization path. Error contract:

| Condition | HTTP | `code` |
|---|---|---|
| `class_id` absent or ≤ 0 | 400 | `invalid_class` |
| class does not exist | 200 | `unknown_class` |
| subject not offered by this class | 404 | `not_offered_here` |
| caller outside the analytics tier | 403 | — |
| unexpected failure | 500 | `server_error` |

**Backward compatibility.** Every change is additive. `get_classes` was already
Phase 1-extended (allowlisted sort `level|name|code|students`, year-scoped
`student_count`) and is untouched by this phase. No legacy response shape
changed. *Verified* by the unchanged Phase 0–4 suites.

---

## 9. Authorization

**No second permission system.** The five actions join the existing tier-3
`$__analyticsActions` list (super_admin / school_admin / edu_dept) and the class
itself is authorized with the existing `ReportCardService::canViewClass()` — the
same primitive the report card uses.

Authorization happens **before any read**, on every one of the five actions.
*Verified* behaviourally and pinned at source: a test locates the combined
action block and asserts that the `canViewClass` call appears before the first
`AcademicTrackingService::` call, and that validation precedes authorization.

Eight cross-scope cases are tested:

1. Every class action refuses every non-education role.
2. Each education role is allowed (so the refusals are about the role).
3. A missing `class_id` is rejected before any read.
4. Non-positive `class_id` values are rejected.
5. Non-numeric `class_id` payloads cannot inject.
6. An unknown class is its own answer, distinct from a refusal.
7. A `subject_id` is validated against the selected class — asking C1 about
   History returns `not_offered_here`, while C2/History is answered normally.
8. Authorization precedes the read, pinned at source.

Plus: a refused response leaks nothing about the class — no name, no code, no
member id appears anywhere in the payload.

**On `class_id` and SQL injection.** The defence is an integer cast, not a
rejection. `"1 OR 1=1"` therefore collapses to `1` and is answered as class 1,
and `"abc"` collapses to `0` and is refused as `invalid_class`. The test asserts
what is actually true — the payload collapses to a harmless number, the answer
is for exactly that number, and the trailing SQL never reaches the database
(row counts are compared before and after) — rather than asserting a rejection
that does not happen. *Verified.*

---

## 10. `SubmissionService` integration

`SubmissionService` remains the sole authority on submission and workflow state.
The class layer calls `resolvedMarklistRefs()` for status and `statusLabel()`
for the label, and adds only the one label the service does not own:
**"Not started"** for a null status.

**One change was made to `SubmissionService`**, and it is additive:
`marklistsHaveRows(array $ids): array<int,bool>` was added, and the existing
`marklistHasRows()` is now a projection of it. The singular form issues one
query per assessment, which is N+1 across a class.

This was a judgement call and is recorded as such. The alternative — asking the
same question a second way inside the tracking layer — would have created two
definitions of "this mark list has rows", which the standing constraints forbid.
Extending the authoritative service additively, keeping the old entry point as a
projection, is the same pattern Phases 3 and 4 used. It reads existence only; no
score is read and nothing is averaged. *Verified:* the Phase 2/3/4 suites were
re-run immediately after the change and were unaffected, and a test asserts that
`marklistHasRows` still delegates, so the two cannot drift apart.

---

## 11. `ReportCardService` authority and the calculation boundary

`ReportCardService` remains the only calculation engine. **The class layer
performs no academic calculation at all** — it reports existence and counts, and
nothing else.

*Verified* at source: the Phase 5 region of `AcademicTrackingService` contains no
`PASS_MARK`, no `GRADE_SCALE`, no grade thresholds, no `AVG(`, no `SUM(`, no
`* 100` and no `/ max_score`; it never queries `academic_records` or any report
card table; and it returns no key named `score`, `average`, `percentage`,
`grade_letter`, `rank` or `pass_rate`.

Note it *does* select `assessments.max_score`. That is the assessment's
configured maximum — a property of the plan, not a student's mark. The test
documents this distinction explicitly rather than banning the string.

**No academic value is calculated in JavaScript.** *Verified* by the
comment-stripped regex suite the earlier phases established, applied to the
Phase 5 block: no `weight *`, no `/ max * 100`, no `>= 90|80|70|60`, no
`.reduce(`, no `pass_mark = digit`. Mutation M12 introduces exactly such a
calculation and is caught.

**No invented metric.** There is no class performance score, no ranking, no
effectiveness figure, no pass rate and no class average anywhere in the class
workspace. *Verified* by assertion. Charts were not added; nothing in this
workflow needed one.

---

## 12. The four facts

The assessment exists · a packet was submitted · the packet has a status · a
mark was recorded. Four independent facts, four columns, never collapsed.

The fixture now contains every combination, because the first mutation run
showed it did not:

| Assessment | Packet | Status | Marks |
|---|---|---|---|
| Music Project (7) | none | *Not started* | no |
| Music Test (3) | **none** | *Complete* (derived from marks) | **yes** |
| Geez Midterm (1) | yes | *Approved* | yes |
| scratch (951) | **yes** | *Complete* | **no** |

The last row was added during mutation hardening: every packet in the original
fixture also had marks, so "a status exists" and "a mark exists" agreed
everywhere and either could have stood in for the other without any test
noticing. A test now asserts that the four facts actually disagree somewhere.

**A missing result is never rendered as `0%`.** *Verified:* `>0%<` and `0.0%` do
not appear. A null weight renders as a dash, not `0%`.

---

## 13. State semantics — an absent record is never a zero

The UI distinguishes three different kinds of nothing:

- **A real `COUNT(*)` of zero** is stated in words — "No students enrolled",
  "No subjects offered", "No teachers assigned", "No assessments".
- **A figure nobody looked up** (`null`) is an em dash.
- **A bare `0`** is never rendered. *Verified:* `>0<` does not appear in an
  empty class's workspace.

Backend states, as actually emitted:

| State | Meaning |
|---|---|
| `ok` | records exist |
| `no_students` | nobody enrolled for the selected year |
| `no_subjects_offered` | no `class_subjects` row |
| `no_teacher_assignments` | no assignment (used for both the teacher list and homeroom) |
| `no_assessments` | nothing planned |
| `no_results` | no marks |
| `not_offered` / `not_offered_here` | the named subject is not offered by this class |
| `filtered_empty` | a search matched nothing |

*Limitation, deliberate:* the brief named a generic `no_records`. The code emits
the entity-specific name each endpoint already used in Phases 2–4
(`no_students`, `no_subjects_offered`, …). Renaming them would have changed
Phase 2–4 response shapes for no behavioural gain, which is out of scope. The
distinction the brief was protecting — *empty* is not *filtered empty* is not
*error* — is fully implemented and tested. A test pins the vocabulary, so a new
undeclared state cannot appear unnoticed.

The frontend states `no_entity_selected`, `ready`, `loading` and `error` are
rendered as distinct screens. **An error is never shown as emptiness:** a failed
load says "Could not load this class" with the server's message and a retry
button, and a transport failure is distinguished from a server error. A failed
panel does not claim its section is empty, and the class header survives it.

---

## 14. Testing and verification

| Suite | Count | Result |
|---|---|---|
| `tests/security/test_class_tracking.py` | 62 tests, 98 subtests | pass |
| `tests/e2e/class_tracking.js` | 189 checks | pass |
| `tests/e2e/subject_tracking.js` | 196 | pass |
| `tests/e2e/teacher_tracking.js` | 166 | pass |
| `tests/e2e/student_tracking.js` | 123 | pass |
| `tests/e2e/academic_tracking_lists.js` | 145 | pass |
| **`tests/security` (full)** | **1946 passed, 926 subtests** | **12 failed** |

**The 12 failures are environmental and pre-existing**, not caused by this
phase: `test_comm_e2e.py` ×11 and `test_destructive_guard.py` ×1, all from an
unseeded `ssms_comm_e2e` database in this sandbox. The identical 12 were
measured as the pre-Phase-5 baseline before any code was written, and CI seeds
that database properly and passes them. *Verified* by diffing the failure list
against the baseline.

**CI #44 (`4a9cb93`) is green: 1958 passed, 0 failed, 926 subtests.** CI seeds
`ssms_comm_e2e` properly, so the 12 environmental failures above pass there —
1946 + 12 = 1958, which is the arithmetic confirming they are the only
difference between the two environments.

Growth over Phase 4: **+63 tests, +105 subtests** (CI #42: 1895/821 →
CI #44: 1958/926).

**Two existing guard tests were narrowed, not deleted.** Both had run out of
things to guard now that the fourth workflow exists:

- `test_student_tracking.py::test_later_workflows_were_not_built` → **
  `test_the_tracking_surface_is_exactly_the_four_root_entities`**. Written in
  Phase 2 to exclude Teacher, Subject and Class tracking, narrowed in Phase 3
  and Phase 4. It now pins the opposite property: the tracking action surface is
  exactly twelve named actions and the root catalogue is exactly four entities,
  so a fifth workflow cannot appear unnoticed. A companion test asserts each
  entity renders its own workspace.
- `test_academic_tracking.py::test_the_unbuilt_entities_state_their_boundary…`
  → **`test_no_entity_fakes_a_workflow_it_does_not_have`**. It now asserts all
  four entities route to their real workflow, that none of them fakes a
  dashboard (no chart, canvas, KPI or placeholder percentage), and that the
  three retired placeholder strings cannot creep back.

**No test was weakened to make Phase 5 pass, and none was skipped.**

Two findings came out of writing the tests rather than out of the code:

1. `per_page` is clamped to a minimum of 10, so the fixture's five students
   could never cross a page boundary — the original pagination test would have
   passed on a single page while proving nothing. It now seeds a 23-student
   class and walks three pages, asserting no overlap and no loss.
2. The `class_id` injection test was asserting a rejection that does not happen
   (§9). It was rewritten to assert what the int cast actually guarantees.

---

## 15. Mutation testing

**24 mutations run. 24 caught. 0 survivors.** All 15 named mutations from the
brief are covered; several are split where one description mapped to more than
one real code site.

| # | Mutation | Result |
|---|---|---|
| M1 | remove class authorization | caught |
| M2 | replace the selected class with another id | caught |
| M3a–d | remove `users.role='teacher'` (list / count / per-subject / homeroom) | caught ×4 |
| M4a | treat homeroom as a subject teacher | caught |
| M4b/c | report every / no assignment as homeroom | caught ×2 |
| M5 | remove the student-class condition | caught |
| M6 | remove the class-subject condition | caught |
| M7 | remove the assessment-class condition | caught |
| M8 | make empty data appear as zero | caught |
| M9 | auto-select the first class | caught |
| M10a/b | disable the stale-response guard (class / panel) | caught ×2 |
| M11 | make "clear filters" always visible | caught |
| M12 | replace a backend value with a frontend calculation | caught |
| M13 | remove the pagination boundary | caught |
| M14 | remove the server-side sorting allowlist | caught |
| M15 | remove the cross-class negative condition | caught |
| M16 | collapse submission status into marks-exist | caught |
| M17 | count withdrawn students as enrolled | caught |
| M18 | drop the standing-assignment branch (homeroom) | caught |

**Six survived the first pass. Every one was a gap in the fixture, and every one
was closed by strengthening a test — none was deleted or weakened.**

- **M3d** — the role condition removed from the *homeroom* query alone. That
  query is separate from the teacher list and nothing reached it. Closed by
  adding a non-teacher holding a homeroom row, then promoting the same user to
  prove the role was the only thing hiding them.
- **M9** — my first attempt added a method nobody called, so it changed no
  behaviour. That was an invalid mutation, not a passing test. Rewritten to
  auto-select from `openList`, where it is now caught by six assertions
  including the single-class case.
- **M14** — the original test only sent payloads that were invalid SQL, so the
  query failed and the test "passed" without the allowlist doing anything. It
  now sends `student_count`, a real column absent from the map, and separately
  proves that the same column via its allowlisted alias *does* change the order.
- **M16** — every packet in the fixture also had marks, so status and
  marks-exist agreed everywhere. Closed by adding a submitted packet with no
  mark rows (§12).
- **M17** — all eight fixture enrolments were active, so the `status = 'active'`
  condition was invisible. Closed by withdrawing a student and restoring them.
- **M18** — the standing branch of the *homeroom* query. Closed by adding a
  homeroom assignment with no academic year, and its year-scoped opposite.

M14, M16 and M17 are the Phase 4 M22 lesson repeating: **a mutation that
survives usually means the fixture cannot tell the difference, not that the
mutation is equivalent.** Each was investigated before being called either.

One harness improvement came out of this: M9 broke the controller badly enough
that the harness threw partway through, hiding six assertions that had already
failed. The harness now prints its findings whether `main()` completes or
throws — the moment a crash hides failures is exactly when they matter most.

---

## 16. Performance

Measured with the MariaDB general log, marker rows delimiting each call,
counting `Query` + `Execute` and excluding the application's shared
`academic_years` resolver (which every endpoint in the system issues).

| Call | Queries | Composition |
|---|---|---|
| Root class list | **2** | `COUNT(*)` + one page |
| Select class (`detail`) | **6** | identity, 4 counts, homeroom |
| Students panel | **3** | identity, count, page |
| Subjects panel | **5** | identity, offerings, batched teachers, batched assessments, batched marklists |
| Teachers panel | **2** | identity, list |
| Assessments panel | **5** | identity, assessments, submissions, 2 × `academic_records` |
| Refused call | **0** | nothing is read before authorization |

**The root class list loads nothing academic.** *Verified* twice: at source, a
test asserts the `get_classes` block contains no reference to
`academic_records`, `report_card`, `attendance` or `grade_submissions`; and in
the log, zero academic reads appear even with 10 offerings seeded.

**N+1 verification.** A scaling fixture was built at 1, 5 and 10 subject
offerings with proportional growth (5 students and 3 assessments per offering,
so 10 offerings = 50 students, 30 assessments, 10 teachers):

| Call | N=1 | N=5 | N=10 |
|---|---|---|---|
| Root class list | 3 | 3 | 3 |
| Select class | 7 | 7 | 7 |
| Students | 4 | 4 | 4 |
| Subjects | 6 | 6 | 6 |
| Teachers | 3 | 3 | 3 |
| Assessments | 6 | 6 | 6 |

(counts here include the shared year resolver, hence +1 against the table above)

**Flat at every scale. No linear growth, no N+1.** The per-subject teachers,
per-subject assessments and mark-list existence lookups are all batched, which
is what `marklistsHaveRows()` exists for (§10).

*Limitation, measured:* the assessments panel issues **two** `academic_records`
queries where one would do. `resolvedMarklistRefs()` asks "do marks exist?" for
packetless assessments in order to derive their status, and `marklistsHaveRows()`
then asks the same question for all assessments to populate `has_results`; the
second result set is a superset of the first. This is a constant one extra query
per call, not N+1 — it does not grow with the class. Removing it would mean
changing `SubmissionService`'s internals for a performance gain rather than a
verified defect, which the standing constraints forbid. It is recorded here
rather than fixed. See §20.

No performance improvement is claimed beyond what is measured above.

---

## 17. Stale-response protection

The existing guard pattern is reused and not weakened. Two sequence counters
exist: `_classSeq` for the class detail and `_classTabSeq[name]` per panel.
Every handler checks both its sequence number *and* that the scope still points
at the class the request was issued for.

*Verified* behaviourally with out-of-order responses (`perCall` delays that let
an earlier request land last) in three scenarios:

1. Two classes selected in quick succession, the first answering last — the
   screen shows the class the user chose, not the one they left.
2. Two searches within one panel, the first answering last — the later result
   wins and the superseded one is discarded.
3. The selection cleared while a panel request is in flight — the late response
   does not repaint the class workspace.

Mutations M10a and M10b disable each guard and are both caught.

---

## 18. UI, accessibility and responsiveness

- **Professional icons only.** Font Awesome throughout, consistent with Phases
  2–4. *Verified:* a regex scan for emoji code points over the whole controller
  returns zero, and a test fails if any emoji appears in the class workspace.
- **Progressive disclosure.** Selecting a class loads identity and four counts
  and nothing else; each panel fetches on first open and is not refetched when
  revisited. *Verified* by request counting.
- **Accessibility.** `role="tablist"` with `aria-label`, `aria-selected`,
  roving `tabindex`, `aria-labelledby` linking panel to tab, `aria-live="polite"`
  with `aria-busy`, scoped table headers, and `aria-hidden` on decorative icons.
  Arrow / Home / End keyboard navigation between tabs. *Verified.*
- **Responsiveness.** Flex-wrapped header, chips and summary cells; horizontally
  scrollable tab strip and tables. Same patterns as Phases 2–4.
- **No fake data in production UI.** No sample students, no placeholder charts,
  no fabricated percentages. All fixtures are test-only.

---

## 19. Known limitations

1. **`canViewClass()` returns `true` unconditionally for all three tier-3
   roles**, which are the only roles that reach these endpoints. Class-level
   visibility filtering therefore cannot be exercised behaviourally and is
   pinned at source instead. Carried forward from Phases 2–4; unchanged by this
   phase. *Limitation.*
2. **Two `academic_records` queries in the assessments panel** where one would
   suffice (§16). Constant, not N+1. *Limitation, measured and deliberate.*
3. **Data-state names are entity-specific**, not the generic `no_records` the
   brief named (§13). *Limitation, deliberate.*
4. **`class_id` is defended by an integer cast, not rejection**, so malformed
   input collapses to a number rather than erroring (§9). Safe, but it means a
   typo'd id silently answers for a different class. *Limitation.*
5. **Search is a `LIKE '%…%'` scan** over `student_name`, `father_name` and
   `member_code`, so it cannot use an index. Acceptable at class scale (tens of
   rows), unmeasured at thousands. *Limitation.*
6. **The search is not transliteration-aware** — Amharic and Latin spellings of
   a name do not match each other. Pre-existing behaviour, consistent with the
   rest of the system. *Limitation.*

---

## 20. Deliberately deferred work

1. **Collapsing the duplicate `academic_records` query** (§16, §19.2). Would
   require threading a precomputed existence map through
   `SubmissionService::resolvedMarklistRefs()`. Not a defect, does not block the
   workflow, and the standing constraints forbid changing that service without
   one. *Deferred.*
2. **Attendance in the class workspace.** Out of scope for this phase and not
   required by the workflow. *Deferred.*
3. **Any class-level aggregate** — average, pass rate, ranking, completion
   percentage. Explicitly excluded by the brief as unsupported KPIs, and nothing
   in the workflow needed one. *Deferred, and should stay deferred unless a real
   workflow demands it.*
4. **Export or print of the class roll.** Not requested. *Deferred.*
5. **Unifying the data-state vocabulary across Phases 2–5** (§13). Would change
   Phase 2–4 response shapes for no behavioural gain. *Deferred.*

---

## 21. Changed files and commits

| Commit | Subject |
|---|---|
| `9f2864c` | `feat: Class Tracking backend scope` |
| `ee9bf2c` | `feat: implement Class Tracking` |
| `59b953c` | `test: add Class Tracking security and workflow coverage` |
| `b9bfe96` | `test: strengthen Class Tracking mutation coverage` |

| File | Change |
|---|---|
| `admin/backend/services/AcademicTrackingService.php` | +778 — 3 state constants, 5 public methods, 10 private helpers |
| `admin/api_education.php` | +94 — 5 actions in one authorized `case` block |
| `admin/backend/services/SubmissionService.php` | +50/−10 — `marklistsHaveRows()`; singular becomes a projection |
| `admin/js/academic_tracking.js` | +671 — the class workspace and its bindings |
| `tests/security/test_class_tracking.py` | **new**, 1365 lines |
| `tests/e2e/class_tracking.js` | **new**, 986 lines |
| `tests/security/test_student_tracking.py` | scope-creep guard narrowed |
| `tests/security/test_academic_tracking.py` | boundary guard narrowed |
| `tests/e2e/academic_tracking_lists.js` | boundary assertion re-pointed |

No Phase 0–4 commit was rewritten, nothing was squashed, nothing was
force-pushed.

---

## 22. Database and migration impact

**Zero schema changes.** No migration was written, and none is needed to deploy
this phase. The §26 STOP-and-report condition for migrations was never reached.

No production data was read, modified or deleted at any point. All verification
ran against the dedicated `ssms_e2e` fixture database. Every scratch row seeded
by a test is removed in a `finally` block, and the fixture was counted before
and after the full run to confirm it is unchanged.

---

## 23. Phase 5 boundary

With classes built, all four root entities have real workflows and the Academic
Tracking surface as scoped is complete. There is no Phase 6 in this brief.

The scope-creep guard has been re-pointed accordingly (§14): rather than
excluding the next unbuilt thing, it now pins the surface at exactly four
entities and twelve actions. Anything added beyond that will fail a test, which
is the intended behaviour — a fifth workflow should be a deliberate decision,
not a drift.

---

## 24. Final acceptance checklist

| Requirement | Status |
|---|---|
| Existing class list reused, no second list | yes |
| No auto-selection, no `rows[0]` | yes, mutation-tested |
| Selected class + year + term visibly communicated | yes |
| No fake zeros | yes |
| Students/subjects/teachers from authoritative relationships only | yes, with misleading fixtures |
| `subject_id IS NULL` never a subject teacher | yes, both directions |
| `academic_year_id IS NULL` preserved, not reinterpreted | yes, row re-read after the call |
| `users.role='teacher'` required wherever a teacher is exposed | yes, all four query sites |
| Assessments proven to belong to the class | yes |
| Four facts kept distinct | yes, all combinations in the fixture |
| `SubmissionService` / `ReportCardService` remain authoritative | yes |
| No JS academic calculation | yes, mutation-tested |
| Explicit distinct data states | yes (§13, with one labelled deviation) |
| Every class-scoped endpoint authorizes server-side | yes, before any read |
| Progressive disclosure, no dashboard-first loading | yes, request-counted |
| Professional icons, no emojis | yes |
| Responsive + accessible | yes |
| Stale-response protection | yes, mutation-tested |
| Guard-the-guard on every negative assertion | yes |
| Mutation testing, survivors analysed not deleted | 24/24 caught |
| Performance measured at 1 / 5 / 10 | yes, flat |
| Zero schema changes | yes |
| No unrelated fixes | yes, documented instead |

**Not claimed:** that the system is bug-free, production-ready or fully secure.
What is claimed is the verified test state recorded above, together with the
limitations in §19 and the deferred work in §20.
