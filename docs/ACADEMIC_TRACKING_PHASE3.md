# Academic Tracking — Phase 3

**Teacher Tracking: the second entity with a real workflow destination.**

Companion to `docs/ACADEMIC_TRACKING_PHASE0.md` (audit, architecture contract,
Phase 1 boundary), `docs/ACADEMIC_TRACKING_PHASE1.md` (the four root lists and
explicit selection) and `docs/ACADEMIC_TRACKING_PHASE2.md` (Student Tracking).
This document is the permanent record of what Phase 3 actually delivered,
verified against the repository at the commits below rather than against any
earlier report.

---

## 1. Executive status

| Item | Value |
|---|---|
| Phase | 3 — Teacher Tracking |
| Status | Implemented, tested, mutation-tested, measured |
| Base commit | `d812eb1` (Phase 2 documentation) |
| Commits | `9822508`, `f9cef92`, `e6b4af9`, `81accd8`, `dd1a4d4` |
| Files changed | 9 (2 new, both test files) |
| Net | +3081 / −21 |
| Schema changes | **None** |
| New migrations | **None** |
| New dependencies | **None** |
| New API files | **None** — the existing education API was extended |
| New permission system | **None** — the existing tier-3 gate and `canViewClass` |
| New workflow states | **None** — `SubmissionService` statuses only |
| Security suite | **CI #40: 1786 passed, 0 failed, 750 subtests** |
| Teacher suites | 89 pytest tests + 44 subtests · 166 node checks |
| Mutation testing | 32 mutations, **32 caught, 0 survived** |

CI run #40 on `a95b02c` is green on all three jobs: *PHP syntax (php -l)*,
*Security and regression suite* and *Migration numbering*.

Locally, 12 tests fail — `test_comm_e2e.py` (11) and `test_destructive_guard.py`
(1). They fail identically before and after Phase 3, the failure list was
diffed against the pre-Phase-3 baseline and is byte-identical, and they touch
nothing in Academic Tracking. CI, which seeds `ssms_comm_e2e` properly, passes
all 1786 — which confirms those 12 were environmental rather than real.

---

## 2. Phase 3 scope

**Built:**

* A teacher workspace reached by explicitly selecting a teacher from the
  existing Phase 1 teacher list.
* The classes and subjects that teacher is assigned to, read from the
  authoritative assignment table.
* For one selected class+subject offering: its assessments and the current
  workflow state of each mark list.
* A hand-off into the review screen that already exists.

**Not built, by instruction:**

* Subject Tracking, Class Tracking, academic exceptions, Phase C
  synchronisation, any Phase 4 work.
* Any teacher ranking, leaderboard, effectiveness percentage, productivity
  score or comparative teacher analytic.
* Any new assessment type, workflow state, permission architecture or
  academic calculation.

---

## 3. The required workflow, and where each step lives

```
Teachers            → the Phase 1 root list, unchanged
Select Teacher      → selectEntity('teachers', id)      explicit click
Classes & Subjects  → tracking_teacher_detail           assignments[]
Select Class+Subject→ selectOffering(classId, subjectId) explicit click
Assessments         → tracking_teacher_assessments      assessments[]
Submission Status   → workflow_status / workflow_label
Existing Action     → window.openReviewModal(submission_id)
```

This is the `LIST → SELECT ENTITY → SCOPED CONTEXT → RELATED DATA →
DETAILED WORKFLOW → ACTION` chain. Every arrow between a list and a selection
is a user action. There is no step the system takes on the user's behalf.

---

## 4. The teacher assignment model

This was the one thing the brief said must not be assumed, so it was
established by reading the schema and the code that already depends on it.

**Teachers are logins.** A teacher is a row in `users` with `role = 'teacher'`.
`users.member_id` links to the person in `members` and is **nullable** — a
login need not correspond to a registered member. The existing `list_teachers`
endpoint already LEFT JOINs on it for exactly that reason.

**Assignments live in `teacher_assignments`:**

| Column | Meaning |
|---|---|
| `teacher_id` | `users.id` of the teacher |
| `class_id` | the class |
| `subject_id` | **nullable** — NULL means homeroom |
| `academic_year_id` | **nullable** — NULL means a standing assignment |
| `is_active` | current assignments only |
| `is_class_teacher` | homeroom/class-teacher flag |
| `assignment_role` | `primary` / `assistant` / `homeroom` |

Two nullable columns carry real meaning and are preserved rather than
flattened:

* `subject_id IS NULL` is a homeroom assignment. Migration
  `006_assignment_hardening.sql` made the column nullable with the comment
  *"Homeroom does not need a subject"*, and added a generated `subject_key`
  column (`IFNULL(subject_id, 0)`) purely so the uniqueness constraint could
  cope with the NULL. A homeroom row is reported as an assignment **with no
  subject**, not hidden and not given a placeholder.
* `academic_year_id IS NULL` is a standing assignment. When a year is in
  scope such rows are **kept**, not dropped.

**This is the same table the rest of the application authorises against.**
`ReportCardService::canViewClass()` resolves a teacher's class access through
`teacher_assignments`. Phase 3 reads the same table, so there is one answer in
the system to "does this teacher teach this class" rather than two that can
drift apart. A test pins that both files reference it.

### 4.1 Relationships are read, never inferred

The obvious shortcut — deriving "subjects this teacher teaches" from the mark
lists they have submitted — is wrong, and the fixture proves it. It contains a
`grade_submissions` row where teacher Bekele submitted a **History** mark list
for class C2 while holding **no C2/History assignment**. That relationship does
not exist; it is an artefact of who happened to file paperwork.

Three tests cover this:

* the History offering never appears in his assignment list;
* reading that offering through his id is refused;
* the submission row still exists — so the negative assertion cannot quietly
  stop proving anything if the fixture changes.

---

## 5. Scope, context and filters

The three are kept strictly separate, as in Phase 2.

| Concept | Holder | Example |
|---|---|---|
| **Context** | `this.context` | academic year — reporting period, never a filter |
| **Scope** (primary) | `this.scope` | the selected teacher |
| **Scope** (secondary) | `this.offering` | the selected class + subject |
| **Filters** | `this.lists[key].filters` | search text the user typed |

`this.offering` is a field of its own. Class and subject ids are **never**
written into the filters bag. Consequences that are tested:

* selecting a teacher adds 0 active filters;
* opening an offering adds 0 active filters;
* the screen never says "clear filters" when no filter was applied;
* the offering is cleared by **"Change class or subject"**, which is a change
  of scope, not a filter reset.

The selected scope is always visible: the teacher in the header, and the
offering in a "Viewing: *class* › *subject*" strip beneath it.

---

## 6. Endpoints

Both actions were added to `admin/api_education.php`. No parallel API file was
created and no existing contract was changed.

### `tracking_teacher_detail`

| Parameter | Required | Notes |
|---|---|---|
| `teacher_id` | yes | `<= 0` → 400 `invalid_teacher` |
| `year_id` | no | defaults to the resolved current year |
| `term_id` | no | narrows the assessment counts |

```json
{
  "status": "success",
  "scope":   { "type": "teacher", "teacher_id": 11 },
  "context": { "year_id": 1, "term_id": 0 },
  "teacher": { "id": 11, "full_name": "...", "username": "...", "email": "...",
               "is_active": true, "member_code": "T-901", "phone": "..." },
  "assignments": [
    { "class_id": 1, "class_name": "...", "subject_id": 1, "subject_name": "...",
      "assignment_role": "primary", "is_class_teacher": false,
      "is_homeroom": false, "assessment_count": 2 }
  ],
  "data_state": { "assignments": "ok" }
}
```

### `tracking_teacher_assessments`

| Parameter | Required | Notes |
|---|---|---|
| `teacher_id` | yes | `<= 0` → 400 `invalid_teacher` |
| `class_id` | yes | `<= 0` → 400 `invalid_class` |
| `subject_id` | yes | `<= 0` → 400 `invalid_subject` |
| `year_id` / `term_id` | no | reporting context |

```json
{
  "status": "success",
  "scope":   { "type": "teacher", "teacher_id": 11, "class_id": 1, "subject_id": 1 },
  "context": { "year_id": 1, "term_id": 0 },
  "class":   { "id": 1, "class_name": "..." },
  "subject": { "id": 1, "subject_name": "..." },
  "assessments": [
    { "assessment_id": 1, "assessment_name": "Geez Midterm",
      "assessment_type": "test", "max_score": 100, "weight": null, "term_id": 1,
      "workflow_status": "approved", "workflow_label": "Approved",
      "submission_id": 1 }
  ],
  "data_state": { "assessments": "ok" }
}
```

### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_teacher` | 400 | no usable teacher id |
| `invalid_class` / `invalid_subject` | 400 | scope incomplete |
| `unknown_teacher` | 200 | no such `role='teacher'` user |
| `not_assigned` | 404 | the teacher does not hold that class+subject |
| `forbidden` | 403 | `canViewClass` refused the class |
| `server_error` | 500 | unexpected failure |

---

## 7. Authorization

**No second permission system was added.**

1. **Tier-3 gate.** Both actions were appended to the existing
   `$__analyticsActions` list. Only `super_admin`, `school_admin` and
   `edu_dept` pass; everyone else receives *"Academic analytics are available
   to the Education department only."* Tested for all four non-Education roles
   on both actions.
2. **Class gate.** When `class_id` is supplied it goes through
   `ReportCardService::canViewClass()` — the same call the report card uses.
3. **Assignment-list filter.** `tracking_teacher_detail` takes no `class_id`,
   so the gate above cannot run for it. The returned assignment list is
   filtered by `canViewClass()` per distinct class instead (memoised, so it is
   at most one check per class and zero for Education roles, which short-circuit
   on role). Without this, a caller who may not see a class could learn it
   exists by reading a teacher assigned to it. The empty state follows the
   **filtered** list, so a teacher whose every class is hidden reports
   `no_assignments` rather than `ok` with an empty array.
4. **Relationship validation.** `teacherAssessments()` re-validates the
   teacher/class/subject triple against `teacher_assignments`. `canViewClass`
   proves the viewer may see a class; it does **not** prove this teacher is
   connected to it. Without this second check any offering's workflow could be
   read through any teacher's id.

Nothing trusts a browser-supplied id. `teacher_id`, `class_id` and `subject_id`
are all validated before they reach a query, and the triple is re-checked
server-side on every scoped call.

### 7.1 A defect this found

Writing the tests exposed a real inconsistency, now fixed. `teacherDetail()`
rejected a user who is not `role='teacher'`, but `teacherAssessments()` only
validated the assignment row — so a non-teacher holding a stale
`teacher_assignments` row was reachable through the scoped endpoint. The two
endpoints disagreed about what a teacher is. `findAssignment()` now joins
`users` on the role, and the mismatch returns `unknown_teacher` on both.

The test seeds that user rather than searching for one, so it always runs
instead of skipping.

---

## 8. Assessment workflow and status semantics

`SubmissionService` is authoritative. This layer retrieves and labels status;
it never decides it, and it implements none of the submission, approval,
rejection, revision or locking rules.

| `workflow_status` | `workflow_label` | Meaning |
|---|---|---|
| `approved` | Approved | Education accepted the mark list |
| `submitted` | Complete | teacher marked it complete, awaiting review |
| `rejected` | Rejected | Education rejected it |
| `revision_needed` | Needs revision | handed back to the teacher |
| `incomplete` / `draft` | Incomplete | in progress |
| `null` | **Not started** | no packet and no marks — a real answer |

`null` is a genuine answer, not a gap, and is the Phase 2 convention reused
unchanged. Tests assert no row ever carries a blank label, that every label
emitted is one the owning service produces, and that no status outside
`SubmissionService`'s own set ever appears.

### 8.1 Status is not a result

An approved mark list says the marks were accepted. It says nothing about how
anyone performed. The payload therefore carries **no** `score`, `percentage`,
`average`, `grade`, `grade_letter`, `final_percentage` or `pass_mark` for an
assessment — asserted field by field. `max_score` is present because it
describes the assessment definition, not anyone's mark.

### 8.2 The packet id, and when there is none

`submission_id` is the `grade_submissions` row the review screen opens. It is
`null` when the status was resolved from loose marks rather than from a packet —
a real status with genuinely nothing to open. Inventing an id there would send
the user to a dead modal, so no action is offered for those rows instead.

A test confirms the id addresses the actual packet row for that assessment, and
another confirms a locked packet's id wins over a newer draft's.

---

## 9. `SubmissionService` integration

Phase 3 needed the packet **id** as well as its status. Looking the id up
separately would have meant a second copy of the C2/H8 precedence rule deciding
*which* packet is the right one, and two copies can disagree.

`marklistPacketRefs()` is therefore now the single implementation of that rule,
and `marklistPacketStatuses()` is a projection of it. Behaviour is unchanged —
the Phase 2 student suite passes untouched.

Measurement then showed a second problem: `resolvedMarklistStatuses()` and
`marklistPacketRefs()` each issued the same `grade_submissions` batch query, so
opening an offering cost two identical round trips. `resolvedMarklistRefs()`
now returns status and packet together in one pass, and
`resolvedMarklistStatuses()` is a projection of *it*. Both the precedence rule
and the marks fallback keep exactly one implementation.

A test asserts the batched precedence query appears exactly once in the file.

---

## 10. Calculation boundaries

**Nothing in Phase 3 calculates anything, in PHP or in JavaScript.**

Phase 3 is unusual in that it calls **no calculation engine at all**.
`ReportCardService` is never invoked for a teacher, because a teacher has no
academic result: scores, averages, grades and ranks belong to a student in a
class. Aggregating them under a teacher's name would convert a tracking screen
into a staff evaluation, which the brief forbids and which this phase does not
do.

`ReportCardService` remains the only authoritative calculation engine, and is
untouched.

Enforced by test:

* the teacher service contains no formula token (`weight *`, `/ max * 100`,
  grade thresholds, `array_sum(`, `pass_mark = n`);
* the teacher service never references `ReportCardService` or `getCard`;
* the teacher screen computes nothing — no total weight, no completion
  percentage, no grade threshold, no `.reduce(`;
* weights are printed exactly as the server sent them, and a null weight is a
  dash, never `0%`.

Mutations that inserted a `rows.reduce(...)` weight total and a
`max_score >= 90 ? 'A' : 'F'` threshold into the browser were both caught.

---

## 11. Data-state semantics — an absent record is never a zero

Five distinct states, none of which is an error, a zero or a filter problem:

| State | Where | Rendered as |
|---|---|---|
| `no_assignments` | teacher holds nothing this year | "No classes or subjects assigned" |
| `no_assessments` | offering has none planned | "No assessments for this class and subject" |
| homeroom | assignment with no subject | "No subject — homeroom", action "Not applicable" |
| `not_assigned` | refusal | "Not assigned to this class and subject" |
| error / network | load failed | "Could not load this teacher" + retry |

Nulls that are preserved rather than zeroed:

* a homeroom row's `assessment_count` is **null** — the question does not apply
  without a subject — and renders as a dash;
* an absent `weight` stays **null** and renders as a dash, not `0%`;
* an unlinked teacher's `member_code` stays **null**, not `""`;
* a homeroom row's `subject_name` stays **null**, not `""`.

An assessment count of `0` **is** permitted, because it is a genuine `COUNT(*)`
result. The UI still renders it as the words "No assessments" rather than the
digit. What is forbidden is a `0` standing in for a value nobody looked up —
which is exactly why homeroom is null and not zero.

Loading is distinct from empty: the panel carries `aria-busy="true"` and a
skeleton, and never shows an empty-state message while a request is in flight.

---

## 12. Stale-response protection

The Phase 2 race-guard pattern is reused and not weakened. Two independent
sequence counters, `_teacherSeq` and `_offeringSeq`, plus identity checks on
the entity that returned.

The offering guard needs its own check because switching between two offerings
of the **same** teacher does not change the teacher, so the teacher guard
cannot help.

Both are proven **by resolving requests out of order**, not by reading source:
the harness makes the *abandoned* request the slow one (`perCall: { action:
[80, 5] }`) so it lands last, and asserts it did not overwrite the newer result.
Both mutations that removed a guard were caught.

---

## 13. Performance

Measured with the MariaDB general query log against the same seeded fixture,
counting executed statements (prepared `Execute` plus direct `Query`).

| Step | Queries | Reads of `academic_records` / `attendance` |
|---|---|---|
| Teacher root list (25 rows) | 3 | 0 |
| Select a teacher (2 assignments) | 4 | 0 |
| Open an offering (2 assessments) | 4 | 0 |
| Teacher with no assignments | 3 | 0 |
| Refused cross-scope request | 3 | 0 |

**The root list stays lightweight.** Listing teachers costs 3 queries flat and
loads no report card, no assessment, no attendance and no academic record for
any teacher. The existing `list_teachers` endpoint was not modified.

**No N+1.** A teacher with 13 assignments costs the **same 4 queries** as one
with 2, because the assessment counts are a single grouped query over all
assigned offerings rather than one query per assignment. Verified by seeding 13
assignments and re-measuring.

**One redundant query removed.** Opening an offering cost 5 queries until the
duplicate `grade_submissions` lookup described in §9 was eliminated; it is now
4.

Because zero steps touch `academic_records` or `attendance`, no calculation
engine can run during teacher navigation.

These are measurements of this fixture, not a load test. No automated
performance assertion was added.

---

## 14. Testing and verification

```bash
# full security suite
SSMS_DB_NAME=ssms_comm_e2e SSMS_SYNC_DB=ssms_e2e \
  python3 -m pytest tests/security -q

# teacher tracking only
python3 -m pytest tests/security/test_teacher_tracking.py -q

# front-end behaviour (no database required)
node tests/e2e/teacher_tracking.js
```

| Suite | Result |
|---|---|
| `tests/security` (full, CI #40) | **1786 passed, 0 failed, 750 subtests** |
| `tests/security` (full, local) | 1774 passed, 750 subtests, 12 pre-existing environmental failures |
| `tests/security/test_teacher_tracking.py` | **89 passed, 44 subtests, 0 skipped** |
| `tests/security/test_student_tracking.py` | 59 passed, 151 subtests (Phase 2, unaffected) |
| `tests/e2e/teacher_tracking.js` | **166 checks, 0 failed** |
| `tests/e2e/student_tracking.js` | 123 checks, 0 failed |
| `tests/e2e/academic_tracking_lists.js` | 143 checks, 0 failed |
| `php -l` | 251 first-party files, all clean |

Phase 3 adds **+90 tests and +48 subtests** to the security suite, measured
against the Phase 2 CI baseline of 1696 passed / 702 subtests (run #38).

No test was skipped, weakened or deleted to get green.

### 14.1 Three Phase 2 expectations were updated, not removed

Phase 2 contained assertions that teacher tracking **did not exist**. Building
it made them false. Each was rewritten against the new behaviour rather than
deleted:

| Test | Before | After |
|---|---|---|
| `academic_tracking_lists.js` | selecting a teacher shows the "not built yet" panel | a teacher opens the workspace; the boundary assertion moved onto a **class**, which really is still unbuilt |
| `student_tracking.js` | selecting a teacher shows the boundary | selecting a teacher must not reach the student workflow; now also asserts no report card is fetched |
| `test_phase_three_workflows_were_not_built` | Teacher, Subject and Class tracking all absent | renamed `test_later_workflows_were_not_built`; Subject and Class tracking still absent — it is now the **scope-creep guard** for Phase 3 |

A new companion test asserts Student Tracking was not modified to accommodate
Phase 3. One Phase 2 test sliced `api_education.php` between two cases and was
inflated by the new endpoints landing between them; its slice is now bounded by
the next case so later phases cannot satisfy it by accident.

---

## 15. Mutation testing

Driver: `mutate_phase3.py` (kept outside the repository). Each mutation breaks
one assumption, runs the suites that should notice, and is reverted with
`git checkout --`.

**Final: 32 mutations, 32 caught, 0 survived.**

| # | Assumption | Caught by |
|---|---|---|
| M1–M1b | no auto-selection of an offering | (covered behaviourally, §16) |
| M2–M3 | the tier-3 gate covers both actions | pytest |
| M4 | class visibility filters the assignment list | pytest |
| M4b | the empty state follows the filtered list | pytest |
| M5 | assignments come only from `teacher_assignments` | pytest |
| M6 | the year scopes the assignment list | pytest |
| M7 | the role is required, not just the assignment row | pytest |
| M8 | an unknown teacher is not an empty teacher | pytest |
| M9 | the teacher/class/subject triple is validated | pytest |
| M10–M11 | the assessment query is scoped to class and subject | pytest |
| M12 | a missing subject id is rejected | pytest |
| M13–M15 | the stale-response guards | node + pytest |
| M16–M17 | status is never fabricated or blanked | pytest |
| M18 | a packet id is never invented | pytest |
| M19 | the packet precedence rule is not duplicated | pytest |
| M20–M23 | nulls are not converted to zeros or empty strings | pytest |
| M24–M25 | the review modal gets the packet id; no action without a packet | node |
| M26–M27 | the browser computes nothing | node + pytest |
| M28–M30 | the empty states stay distinct from each other and from errors | node |
| M31–M32 | the service's own empty states | pytest |

### 15.1 The first run, and what survived

Five mutations survived the first run. **None was deleted.** Two were real
coverage gaps and three were redundant by construction.

**Real gaps, now closed:**

* **M19 — the C2/H8 precedence rule.** No fixture contained two packets for one
  assessment, so disabling the rule changed nothing observable. There are now
  tests that insert a newer `draft` packet behind an `approved` one and assert
  the approved **status and id** both win, through both entry points. Without
  the id assertion, "Open review" could still open the wrong row.
* **M31 — the service's empty state.** The endpoint recomputes `data_state`
  after filtering by visibility, masking whatever the service decided. The
  service is now called directly as well as through the API.

**Redundant by construction, now pinned at source:**

* **M4 — the visibility filter.** `canViewClass()` returns `true`
  unconditionally for `super_admin`, `school_admin` and `edu_dept`, and those
  are the only roles that can reach these endpoints. Removing the filter
  changes no response **today**; it would start mattering the day the tier is
  widened, which is exactly when nobody would remember to re-add it. This is
  the same observability limitation Phase 2 recorded for its own recheck.
* **M12 — the subject id check.** Validated at the endpoint and again in the
  service. Removing either leaves behaviour identical.
* **M15 — the offering identity check.** The sequence number alone suffices
  today because every load increments it; the identity check is what keeps that
  true if a future caller ever reloads without a new sequence.

Each pin asserts the exact statement form the mutation breaks, and each carries
a comment explaining why an outcome assertion cannot do the job.

---

## 16. UI rules and accessibility

* **No auto-selection anywhere.** No `rows[0]`, no `assignments[0]`. The
  decisive test is a teacher with **exactly one** assignment: it is still not
  opened, because the next teacher's single assignment would be a different
  one. Tested behaviourally and pinned at source.
* **Professional icons only.** Font Awesome classes from the existing library
  (`fa-chalkboard-user`, `fa-folder-open`, `fa-clipboard-question`,
  `fa-user-slash`, `fa-ban`, `fa-rotate-right`). A regex scan asserts **no
  emoji** appears anywhere in the controller.
* **The action is the existing one.** `window.openReviewModal(submission_id)` —
  the alias `edu_dept.php` already exports. The screen does not approve, reject
  or request revision and contains none of those rules. If the host dashboard
  is absent it falls back to `nav('submissions')` rather than silently doing
  nothing.
* **Accessibility.** `aria-live="polite"` and `aria-busy` on the panel,
  `scope="col"` on every table header, `aria-hidden="true"` on decorative
  icons, real `<button>` elements for every action.
* **Responsive.** The same primitives Phase 2 uses: `.tw{overflow-x:auto}`
  wrappers so tables scroll rather than overflow on mobile, `flex-wrap` headers
  with `min-width` content columns, and the existing `@media (max-width:640px)`
  and `prefers-reduced-motion` rules.
* **No new framework, no chart library, no new dependency.** No chart was added
  — there is no teacher metric worth plotting that would not be a performance
  score.

---

## 17. Changed files and commits

| File | Change |
|---|---|
| `admin/backend/services/AcademicTrackingService.php` | +426 — `teacherDetail`, `teacherAssessments`, 4 private helpers, `STATE_NO_ASSIGNMENTS` |
| `admin/backend/services/SubmissionService.php` | +73/−8 — `marklistPacketRefs`, `resolvedMarklistRefs`; the two status methods become projections |
| `admin/api_education.php` | +121 — two actions, tier-3 registration, visibility filter |
| `admin/js/academic_tracking.js` | +468/−8 — the teacher workspace |
| `tests/security/test_teacher_tracking.py` | **new**, 1132 lines |
| `tests/e2e/teacher_tracking.js` | **new**, 816 lines |
| `tests/security/test_student_tracking.py` | +33/−7 — expectations updated, scope guard narrowed |
| `tests/e2e/student_tracking.js` | +11/−6 — expectation updated |
| `tests/e2e/academic_tracking_lists.js` | +22/−5 — expectation updated |

| Commit | Subject | Stat |
|---|---|---|
| `9822508` | teacher scope backend | 3 files, +556/−2 |
| `f9cef92` | teacher workflow screen | 3 files, +493/−8 |
| `e6b4af9` | teacher tracking tests | 3 files, +1769 |
| `81accd8` | strengthen tests against surviving mutations | 2 files, +223/−7 |
| `dd1a4d4` | resolve status and packet in one query | 2 files, +44/−8 |

A diff review against `d812eb1` confirms: no `sql/` change, no CI change, no
dependency or config change, no unrelated refactor or rename, and the only two
added files are tests.

---

## 18. Database and migration impact

**None.** Every structure Phase 3 needs already exists:
`teacher_assignments`, `users`, `members`, `classes`, `subjects`,
`assessments`, `grade_submissions`.

No migration was written, no column added, no index added. A test asserts no
migration file mentions Phase 3 teacher tracking.

The `sql/055`–`sql/058` instructions are unchanged by this phase and none was
executed.

---

## 19. Known limitations

1. **The visibility filter is not observable from outside.** `canViewClass()`
   is unconditionally true for the three roles that can reach these endpoints,
   so the assignment-list filter cannot be exercised behaviourally today. It is
   held by a source pin. Same limitation as Phase 2 §19.1.
2. **A teacher cannot see their own tracking record.** This is an Education
   oversight screen and fails closed. Widening it to the subject of the record
   is a product decision nobody has taken; it is tested as a refusal so the
   behaviour is deliberate rather than accidental.
3. **Assessment status is per assessment, not per student.** Whether individual
   students within an approved mark list have marks is a Student Tracking
   question and is answered there.
4. **Homeroom assignments have no workflow destination.** A subjectless
   assignment has no subject mark list, so the row is listed and labelled
   "Not applicable". If homeroom duties ever acquire their own trackable
   artefact, that is new scope.
5. **Performance figures are fixture measurements**, not a load test, and carry
   no automated assertion.
6. **`assignment_role` is surfaced but not acted on.** `assistant` and
   `homeroom` are shown as chips; no behaviour differs by role, because no
   existing rule in the repository distinguishes them.
7. **The 12 failing `comm_e2e` / `destructive_guard` tests are environmental**
   and pre-date this work. They were not investigated, being outside this
   brief, and were confirmed identical before and after.

---

## 20. Deliberately deferred work

* Subject Tracking and Class Tracking — the remaining two root entities.
* Academic exceptions and Phase C synchronisation.
* Any teacher workload, coverage or completion analytic. Note that even a
  neutral-sounding "mark lists outstanding per teacher" becomes a performance
  metric the moment it is sorted, so it is not being added without an explicit
  product decision.
* Allowing a teacher to view their own record.
* An automated performance regression assertion.

---

## 21. Phase 4 boundary

Phase 3 ends at the teacher. Two of the four root entities now have real
workflows:

| Entity | State |
|---|---|
| Students | Phase 2 — complete |
| Teachers | **Phase 3 — complete** |
| Classes | boundary panel, honestly stated |
| Subjects | boundary panel, honestly stated |

Selecting a class or a subject still renders the "tracking is not built yet"
panel. `test_later_workflows_were_not_built` enforces that neither acquired an
endpoint or a renderer in this phase.

---

## 22. Final acceptance checklist

| § 35 criterion | Status |
|---|---|
| Teacher root list searchable, paginated, sorted, truthful count | yes — existing `list_teachers`, unmodified |
| Root list lightweight, no heavy academic calculation | yes — 3 queries, 0 engine reads |
| Explicit selection; no auto-selection; no `rows[0]` | yes — including the single-assignment case |
| Selected scope and academic context visible | yes — header + "Viewing" strip |
| Relationships from authoritative repo data only | yes — `teacher_assignments`, never inferred |
| Invalid cross-scope access rejected server-side | yes — triple re-validated, `not_assigned` |
| Existing `SubmissionService` states reused; no new state machine | yes |
| Assessment status distinct from academic result | yes — no score field exists |
| Existing workflow actions reused, not rebuilt | yes — `openReviewModal` |
| No fake zeros, no fake status | yes — nulls preserved, tested |
| Loading / error / empty / no-assignments / no-assessments / refusal distinct | yes |
| No browser-side academic calculation | yes — tested and mutation-tested |
| `ReportCardService` remains authoritative | yes — untouched, never called here |
| No teacher ranking / effectiveness / scoring | yes — asserted by name |
| No second permission system | yes |
| No unnecessary migration | yes — none at all |
| Professional icons, no emojis | yes — scanned |
| Responsive desktop / tablet / mobile | yes — existing primitives |
| Accessibility satisfied | yes — aria-live, aria-busy, scope, buttons |
| Stale-response protection tested out of order | yes — both guards |
| Mutation testing covers the 10 named assumptions | yes — 32 mutations, 32 caught |
| Root navigation lightweight, N+1 checked | yes — flat at 13 assignments |
| Academic Intelligence / Student Tracking / Education still functional | yes — suites pass |
| Regression tests pass | yes — baseline identical |
| Documentation complete | this document |
| Clean tree, pushed, CI | yes — `a95b02c` on `origin/main`, CI #40 green |
