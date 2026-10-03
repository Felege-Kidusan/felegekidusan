# Academic Tracking — Phase 4

**Subject Tracking: the third entity with a real workflow destination.**

Companion to `docs/ACADEMIC_TRACKING_PHASE0.md` (audit, architecture contract),
`PHASE1.md` (the four root lists and explicit selection), `PHASE2.md` (Student
Tracking) and `PHASE3.md` (Teacher Tracking). This document is the permanent
record of what Phase 4 actually delivered, verified against the repository at
the commits below rather than against any earlier report.

---

## 1. Executive status

| Item | Value |
|---|---|
| Phase | 4 — Subject Tracking |
| Status | Implemented, tested, mutation-tested, measured |
| Base commit | `04b374f` (Phase 3 documentation) |
| Commits | `bcf4aa2`, `4da00e2`, `81320a5`, `33410b4` |
| Files changed | 6 (2 new, both test files) |
| Net | +3683 / −13 |
| Schema changes | **None** |
| New migrations | **None** |
| New dependencies | **None** |
| New API files | **None** — the existing education API was extended |
| New permission system | **None** — the existing tier-3 gate and `canViewClass` |
| New workflow states | **None** — `SubmissionService` statuses only |
| `SubmissionService` / `ReportCardService` | **Untouched** (verified by diff) |
| Security suite | **CI #42: 1895 passed, 0 failed, 821 subtests** |
| Subject suites | 109 pytest tests + 71 subtests · 196 node checks |
| Mutation testing | 48 mutations, **47 caught, 1 equivalent and pinned** |

CI run #42 on `5d2286d` is green on all three jobs: *PHP syntax (php -l)*,
*Security and regression suite* and *Migration numbering*.

Phase 4 adds **+109 tests and +71 subtests**, measured against the Phase 3 CI
baseline of 1786 passed / 750 subtests (run #40) — the same delta as the local
measurement, from an independently seeded environment.

The 12 failing tests are `test_comm_e2e.py` (11) and `test_destructive_guard.py`
(1). They fail identically before and after Phase 4 — the failure list was
diffed against the pre-Phase-4 baseline and is byte-identical — are caused by an
unseeded local `ssms_comm_e2e`, and touch nothing in Academic Tracking. CI,
which seeds that database properly, passed all of them in Phase 3.

### 1.1 Environment note

A fourth sandbox rollback occurred at the start of this phase: `HEAD` and
`origin/main` had been rewound to `c10d788` (pre-Phase-0) and the PHP/MariaDB
toolchain was gone. Git was recovered from the remote, which held Phase 3
intact at `04b374f`; before discarding anything the local files were diffed
against the remote and found to be *older* copies. The toolchain was rebuilt and
the databases re-initialised. This affected the working environment only — no
repository content was lost, and the Phase 3 baseline was re-measured and
confirmed identical before any Phase 4 work began.

---

## 2. Phase 4 scope

**Built:**

* A subject workspace reached by explicitly selecting a subject from the
  existing Phase 1 subject catalogue.
* The classes that offer that subject, read from the authoritative offering
  table, with year-scoped counts.
* For one selected class offering: its assigned teachers, its enrolled
  students, and its assessments with mark-list workflow state.
* A hand-off into the review screen that already exists.

**Not built, by instruction:**

* Class Tracking, academic exceptions, Phase C synchronisation.
* Any subject ranking, score, effectiveness, "strongest/weakest subject",
  leaderboard or predictive analytic.
* Any teacher ranking or effectiveness figure.
* Any new assessment type, workflow state, permission architecture or
  academic calculation.

---

## 3. The required workflow, and where each step lives

```
Subjects              → the Phase 1 catalogue, unchanged
Select Subject        → selectEntity('subjects', id)        explicit click
Classes / Offerings   → tracking_subject_detail             offerings[]
Select Offering       → selectSubjectOffering(classId)      explicit click
  ├── Teachers        → tracking_subject_offering           teachers[]
  ├── Students        → tracking_subject_students           lazy + paginated
  └── Assessments     → tracking_subject_offering           assessments[]
Submission Status     → workflow_status / workflow_label
Existing Action       → window.openReviewModal(submission_id)
```

This is the `LIST → SELECT ENTITY → SCOPED CONTEXT → RELATED DATA →
DETAILED WORKFLOW → ACTION` chain. Every arrow between a list and a selection
is a user action.

---

## 4. The subject/offering relationship model

This was established by reading the schema and the code that already depends
on it, not assumed.

### 4.1 The central structural fact: an offering has no academic year

```sql
class_subjects (
  id, class_id, subject_id,
  duration_type,   -- migration 056
  term_id,         -- migration 056
  UNIQUE (class_id, subject_id)
)
```

**`class_subjects` has no `academic_year_id`. Neither does `classes`.**
`ReportCardService::fetchSubjects()` — the authoritative engine — joins this
table scoped only by `class_id`.

A (class × subject) pair is therefore a **standing arrangement that persists
across years**. Three consequences, all deliberate:

1. **The academic year does not filter the offering.** It scopes the data
   hanging off it: who is assigned this year, who is enrolled this year, which
   assessments belong to this year. Querying a year the subject has no data for
   returns its offerings with zero counts, not an empty list.
2. **"Not offered" means one thing only** — the subject is in the catalogue and
   no `class_subjects` row exists for it. Inventing a per-year "offered" flag
   would be inventing a column the schema does not have.
3. A test reads `information_schema` and asserts that `academic_year_id` really
   is absent from `class_subjects`, so if that column is ever added the model is
   re-examined rather than silently kept.

### 4.2 The full graph — every edge authoritative

```
subjects (is_active)
  └── class_subjects ................ THE OFFERING (+ duration_type, term_id)
        └── classes
              ├── teacher_assignments ..... teacher_id, class_id, subject_id,
              │                             academic_year_id, is_active,
              │                             assignment_role, is_primary
              │                             + users.role = 'teacher'
              ├── class_enrollments ....... member_id, class_id,
              │                             academic_year_id, status → members
              └── assessments ............. class_id, subject_id,
                                            academic_year_id, term_id, is_active
                                            → grade_submissions
                                              via SubmissionService
```

### 4.3 Duration is a three-valued fact

Migration 056 added `duration_type` to the offering. `FULL_YEAR` and
`SEMESTER_ONLY` are reported verbatim; **`NULL` is a real third value meaning
unclassified** and renders as "Not classified". It is never defaulted to full
year. The fixture's History offering is deliberately NULL, and a mutation that
coerced it to `FULL_YEAR` was caught.

---

## 5. Relationships are read, never inferred

The brief's §7/§9/§10 requirement, and the thing most of the test suite exists
to defend.

### 5.1 Teachers

A teacher appears under an offering only when `teacher_assignments` says so.
**A mark-list author, submission creator or historical assessment actor is not
a teacher of that subject.**

The fixture proves why. History in class C2 has an assessment *and* a
`revision_needed` packet filed by Bekele — who holds **no** History assignment.
That offering reports **no teachers**. Three tests cover it: the teacher list is
empty, the batched count is 0, and a companion test asserts the packet still
exists so the negative assertion cannot quietly stop testing anything.

### 5.2 Students

A student takes a subject because they are **enrolled in a class that offers
it** — `class_enrollments`, status `active`, scoped to the year. Membership is
never inferred from a mark, an assessment row, an attendance record or a mark
list containing their name.

Tested by removing student 101's enrolment while leaving their marks in place:
they disappear from the roll. A companion test asserts those marks are still
there, so the first test keeps meaning something.

### 5.3 The two meaningful NULLs in `teacher_assignments`

| NULL | Meaning | Treatment |
|---|---|---|
| `subject_id IS NULL` | **Homeroom** — an assignment to the *class*, not to any subject | Excluded by a deliberate `ta.subject_id = ?` equality. A homeroom teacher never becomes the teacher of every subject the class offers. Tested in both the listing and the batched count. |
| `academic_year_id IS NULL` | **Standing** — not tied to one year | Kept when a year is in scope (`= ? OR IS NULL`), never rewritten to look like the selected year, and surfaced as `is_standing` so the UI can badge it. Tested against the scoped year and against a year it was never granted for. |

### 5.4 Role validation — the Phase 3 defect does not recur

An assignment row can outlive the role it was granted for. Possession of the
row is not proof of being a teacher, so `users.role = 'teacher'` is required in
**both** the teacher listing and the batched teacher count. Tested by seeding a
`finance_dept` user with a valid assignment row: they appear in neither.

### 5.5 Multiple teachers

The schema permits more than one assignment per class+subject. All are
returned, ordered by `is_primary` then name; none is collapsed or silently
preferred. Tested by seeding a second assistant onto an existing offering.

---

## 6. Scope, context and filters

| Concept | Holder | Example |
|---|---|---|
| **Context** | `this.context` | academic year — reporting period, never a filter |
| **Scope** (primary) | `this.scope` | the selected subject |
| **Scope** (secondary) | `this.subjectOffering` | the selected class |
| **Filters** | `this.lists[key].filters` | search text the user typed |

`this.subjectOffering` is a field of its own; class ids are never written into
the filters bag. Tested: selecting a subject adds 0 active filters, opening an
offering adds 0, and the screen never says "clear filters" when none is applied.
The offering is cleared with **"Change class"** — a change of scope, not a
filter reset.

---

## 7. API endpoints

Three progressive actions were added to `admin/api_education.php`. No parallel
API file was created, no existing contract changed, and there is deliberately
no `get_everything_for_subject`.

### `tracking_subject_detail`

| Parameter | Required | Notes |
|---|---|---|
| `subject_id` | yes | `<= 0` → 400 `invalid_subject` |
| `year_id` / `term_id` | no | scopes the counts, **not** the offerings |

```json
{
  "status": "success",
  "scope":   { "type": "subject", "subject_id": 1 },
  "context": { "year_id": 1, "term_id": 0 },
  "subject": { "id": 1, "subject_name": "...", "subject_name_en": "Geez",
               "subject_code": null, "is_active": true },
  "offerings": [
    { "class_id": 1, "class_name": "...", "class_name_en": "Grade 4",
      "class_active": true, "duration_type": "FULL_YEAR", "term_id": null,
      "teacher_count": 1, "student_count": 5, "assessment_count": 2 }
  ],
  "data_state": { "offerings": "ok" }
}
```

### `tracking_subject_offering`

`subject_id` + `class_id` required. Returns `teachers[]`, `assessments[]`, the
offering's duration, and `data_state` for each collection.

```json
{
  "teachers": [
    { "teacher_id": 11, "full_name": "...", "username": "bekele",
      "member_code": "T-901", "is_active": true,
      "assignment_role": "primary", "is_primary": true, "is_standing": false }
  ],
  "assessments": [
    { "assessment_id": 1, "assessment_name": "Geez Midterm",
      "assessment_type": "test", "max_score": 100, "weight": null,
      "term_id": 1, "workflow_status": "approved",
      "workflow_label": "Approved", "submission_id": 1 }
  ],
  "data_state": { "teachers": "ok", "assessments": "ok" }
}
```

### `tracking_subject_students`

`subject_id` + `class_id` required, plus `page` / `per_page`. Students are the
only collection that can be large, so they are separate, lazy and paginated.
`per_page` is clamped to **10–100**, matching the Phase 1 catalogue; `page` is
clamped to ≥ 1. Returns `students[]`, `total`, `page`, `per_page`, `pages`.

### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_subject` / `invalid_class` | 400 | scope incomplete |
| `unknown_subject` | 200 | no such subject |
| `not_offered_here` | 404 | that class does not offer that subject |
| `forbidden` | 403 | `canViewClass` refused the class |
| `server_error` | 500 | unexpected failure |

---

## 8. Authorization

**No second permission system.** Four layers:

1. **Tier-3 gate.** All three actions were appended to the existing
   `$__analyticsActions`. Only `super_admin`, `school_admin` and `edu_dept`
   pass. Tested for all four non-Education roles on all three actions.
2. **Class gate.** When `class_id` is supplied it goes through
   `ReportCardService::canViewClass()` — the same call the report card uses.
3. **Offering-list filter.** `tracking_subject_detail` takes no `class_id`, so
   the gate above cannot run for it. Its offering list is filtered by
   `canViewClass()` per distinct class (memoised), and the empty state follows
   the **filtered** list. Without this, a caller who may not see a class could
   learn it exists — and how many students it holds — by reading a subject
   taught there.
4. **Relationship validation.** `findOffering()` re-validates that the named
   class really offers the named subject before any scoped call returns a row.
   Both `tracking_subject_offering` and `tracking_subject_students` go through
   it; the students endpoint needs it just as much, or naming any class
   alongside any subject would read that class's roll under the subject's name.

Nothing trusts a browser-supplied id. The endpoint executes no SQL of its own —
a test asserts that — so all scoped reads live in the service where they are
tested.

### 8.1 Cross-scope cases proven to fail

| Request | Result |
|---|---|
| Music + class C2 (C2 does not offer Music) | `not_offered_here` |
| History + class C1 (C1 does not offer History) | `not_offered_here` |
| Students of Music + C2 | `not_offered_here` |
| Unknown subject + a real class | `unknown_subject` |
| Any subject + a nonexistent class | `not_offered_here` |
| Teacher assigned only to another subject | absent from the list |
| Non-teacher holding an assignment row | absent from the list and the count |
| Homeroom teacher of the class | absent from every subject |
| Student enrolled only in another class | absent from the roll |
| Assessments of C1/Geez vs C1/Music | disjoint sets |
| Assessments of C1/Geez vs C2/Geez | disjoint sets |

A refusal returns **no data at all** — a test asserts `teachers`, `students`,
`assessments`, `class` and `subject` are all absent from the error payload.

---

## 9. `SubmissionService` integration

`SubmissionService` remains authoritative. This layer retrieves and labels
status; it never decides it, and implements none of the submission, approval,
rejection, revision or locking rules.

Phase 4 reuses the Phase 3 helper **`resolvedMarklistRefs()`**, which returns
the resolved status and the winning packet id in one pass. No new resolution
path was written, so the C2/H8 precedence rule keeps exactly one
implementation. A test asserts the subject layer never queries
`grade_submissions` directly.

| `workflow_status` | `workflow_label` |
|---|---|
| `approved` | Approved |
| `submitted` | Complete |
| `rejected` | Rejected |
| `revision_needed` | Needs revision |
| `incomplete` / `draft` | Incomplete |
| `null` | **Not started** — a real answer |

`submission_id` is `null` when the status came from loose marks rather than a
packet: a real status with nothing to open. No button is drawn for those rows
rather than one that opens a dead modal.

---

## 10. `ReportCardService` authority and the calculation boundary

**`ReportCardService` remains the only authoritative calculation engine and is
untouched** (verified by diff).

Phase 4, like Phase 3, calls **no calculation engine at all**. A subject has no
academic result of its own in this phase: the screen answers where a subject is
taught, who is responsible for it, who studies it and what state the work is
in. No average, pass rate, grade or percentage is computed or displayed.

Enforced by test:

* the subject service contains no formula token (`weight *`, `/ max * 100`,
  grade thresholds, `array_sum(`, `pass_mark = n`);
* the subject service never references `ReportCardService` or `getCard`;
* the subject screen computes nothing — no total weight, no completion
  percentage, no grade threshold, no `.reduce(`;
* weights are printed exactly as the server sent them; a null weight is a dash,
  never `0%`;
* no academic field is attached to a student or an assessment row.

Mutations inserting a `rows.reduce(...)` weight total and a
`max_score >= 90 ? 'A' : 'F'` threshold into the browser were both caught.

**No subject ranking exists.** A test asserts the payload contains none of
`effectiveness`, `subject_score`, `ranking`, `rank`, `leaderboard`,
`strongest`, `weakest`, `pass_rate`, `overall_average`, `performance_score`,
and the UI harness asserts the same strings never render.

---

## 11. State semantics — an absent record is never a zero

Seven distinct states, none of which is an error, a zero or a filter problem:

| State | Where | Rendered as |
|---|---|---|
| `not_offered` | subject in the catalogue, offered to no class | "Not offered to any class" |
| `no_teachers` | offering with no assignment | "No teacher assigned" |
| `no_students` | nobody enrolled this year | "No students enrolled" |
| `no_assessments` | nothing planned for the offering | "No assessments for this class" |
| `not_offered_here` | refusal — that class does not offer it | "That class does not offer this subject" |
| loading | request in flight | skeleton + `aria-busy="true"` |
| error / network | load failed | "Could not load this …" + retry |

Nulls preserved rather than zeroed:

* a `NULL` `duration_type` stays null and renders "Not classified";
* an absent `weight` stays null and renders as a dash;
* an unlinked teacher's `member_code` stays `null`, not `""` — with a companion
  test asserting a linked teacher still gets their real code, so null means
  *absent* rather than *always null*.

**Counts may legitimately be 0** because they are genuine `COUNT(*)` results.
The UI still renders them as words — "No teacher", "No students", "No
assessments" — rather than the digit, and a `null` count renders as a dash. A
test asserts the digit `0` never appears as a count, and a mutation removing the
words-for-zero rule was caught.

An over-scrolled student page is **not** an empty class: `total` drives the
state, so page 999 of a 5-student class reports `ok` with an empty array.

---

## 12. Stale-response protection

The Phase 2/3 pattern is reused and not weakened. Three independent sequence
counters — `_subjectSeq`, `_subjOfferingSeq`, `_subjStudentSeq` — each paired
with an identity check on the entity that returned.

The offering and student guards need their own identity checks because
switching between two classes of the **same** subject does not change the
subject, so the subject guard cannot help.

All three are proven **by resolving requests out of order**, not by reading
source: the harness makes the *abandoned* request the slow one
(`perCall: { action: [80, 5] }`) so it lands last, and asserts it did not
overwrite the newer result. Covered: subject → subject, offering → offering
within one subject, and student page 1 → page 2. All three guard mutations were
caught.

---

## 13. Performance

Measured with the MariaDB general query log against the same seeded fixture,
counting executed statements (prepared `Execute` plus direct `Query`). These
are **local, automated measurements of this fixture** — not a load test, and
with no automated performance assertion.

| Step | Queries | Reads of `academic_records` / `attendance` |
|---|---|---|
| Subject root list (25 rows) | 3 | 0 |
| Select a subject (2 offerings) | 6 | 0 |
| Open an offering (teachers + assessments) | 5 | 0 |
| Students tab (page 1) | 4 | 0 |
| Refused cross-scope request | 3 | 0 |

**The root list stays lightweight.** The Phase 1 subject catalogue was reused
unmodified: 3 queries flat, with no class, teacher, student, assessment or
report-card load for any subject before selection.

**Progressive disclosure holds.** Students are not fetched when an offering is
opened — only when their tab is opened, and only once. Tested behaviourally and
pinned at source.

**Zero steps touch `academic_records` or `attendance`**, so no calculation
engine can run during subject navigation.

### 13.1 N+1 verification

A scratch subject was seeded into 1, 5 and 10 class offerings, each with a
teacher assignment, an enrolment and an assessment, and the subject-detail call
was measured at each size:

| Offerings | Queries |
|---|---|
| 1 | **6** |
| 5 | **6** |
| 10 | **6** |

**Flat.** The three per-offering counts are single grouped queries over the
whole offering set, not one query per offering. Two tests pin this structurally:
each count must appear exactly once in `subjectDetail()`, and neither a count
nor a `prepare()` may appear inside the per-offering loop.

---

## 14. Testing and verification

```bash
# full security suite
SSMS_DB_NAME=ssms_comm_e2e SSMS_SYNC_DB=ssms_e2e python3 -m pytest tests/security -q

# subject tracking only
python3 -m pytest tests/security/test_subject_tracking.py -q

# front-end behaviour (no database required)
node tests/e2e/subject_tracking.js
```

| Suite | Result | Evidence |
|---|---|---|
| `tests/security` (full) | **1895 passed, 0 failed, 821 subtests** | **CI #42** |
| `tests/security` (full) | 1883 passed, 821 subtests, 12 environmental failures | local |
| `tests/security/test_subject_tracking.py` | **109 passed, 71 subtests, 0 skipped** | local |
| `tests/security/test_student_tracking.py` | 59 passed (Phase 2, unaffected) | local |
| `tests/security/test_teacher_tracking.py` | 89 passed (Phase 3, unaffected) | local |
| `tests/e2e/subject_tracking.js` | **196 checks, 0 failed** | local |
| `tests/e2e/teacher_tracking.js` | 166 checks, 0 failed | local |
| `tests/e2e/student_tracking.js` | 123 checks, 0 failed | local |
| `tests/e2e/academic_tracking_lists.js` | 143 checks, 0 failed | local |
| `php -l` | 251 first-party files, all clean | local |

No test was skipped, weakened or deleted to get green.

### 14.1 One Phase 3 expectation was narrowed, not removed

`test_phase_three_workflows_were_not_built` was written in Phase 2 to assert
Teacher, Subject and Class tracking were all absent. Phase 3 narrowed it to
Subject and Class. Phase 4 narrows it again to **Class alone** — which is the
boundary still in front of us. It has never been deleted, and it is now the
scope-creep guard for Phase 5. Its companion,
`test_the_student_workflow_is_unchanged_by_later_phases`, was renamed from
`..._by_phase_three` for the same reason.

---

## 15. Mutation testing

Driver: `mutate_phase4.py` (kept outside the repository). Each mutation breaks
one assumption, runs the suites that should notice, and is reverted with
`git checkout --`.

**Final: 48 mutations, 47 caught, 1 equivalent by construction and pinned.**

| Assumption (brief §37) | Mutations | Result |
|---|---|---|
| 1 auto-select first subject / offering | M1, M1b | covered behaviourally (§16) |
| 2 subject authorization | M2–M4 | caught |
| 3 subject→offering validation | M6, M8, M10 | caught |
| 4 offering→subject validation | M7, M9 | caught |
| 5 teacher role validation | M11, M12 | caught |
| 6 homeroom as subject assignment | M13, M14 | caught |
| 7 standing-assignment semantics | M15, M16, M17 | caught |
| 8 teacher inferred from submission | M11–M18 | caught |
| 9 student membership inferred | M19–M22b | caught (M22 equivalent, pinned) |
| 10 assessment scope validation | M23–M25 | caught |
| 11 stale-response guard | M26–M29 | caught |
| 12 missing data replaced with zero | M30–M33 | caught |
| 13 fake status | M34–M36 | caught |
| 14 workflow-action authorization | M37, M38 | caught |
| 15 browser-side academic calculation | M39, M40 | caught |
| 16 empty-state distinction | M41–M47 | caught |
| (extra) class-visibility filter | M5, M5b | caught |

### 15.1 The first run, and what happened to each survivor

The first run produced **five survivors and six non-applications**. No test was
deleted.

**Six non-applications were my own fault.** The subject layer and the Phase 3
teacher layer share several byte-identical lines, so those anchors matched twice
and the driver skipped them. Each was re-anchored on text unique to the subject
version — the two assessment queries differ in quoting style, and the two action
blocks differ in comment wording — and all six are now caught.

**Four survivors were real coverage gaps, now closed:**

* **M22b / M25 — the batched counts.** They were only ever asserted as *zero*,
  so a count that ignored its class or its subject read the same. They are now
  asserted against deliberately different numbers: C1 holds five students to
  C2's three, and C1 offers four assessments across two subjects, so a scoping
  slip changes the answer.
* **The student count and the roll are separate queries.** A withdrawn student
  was proven to leave the roll but never proven to leave the count.
* **M32 — a null member code.** A teacher with no linked member record was
  never checked, with a companion test so null means *absent* and not
  *always null*.
* **M44 — the service's own empty state.** Masked because the endpoint
  recomputes `data_state` after filtering by visibility. The service is now
  called directly.

**M29 also exposed a weak test of mine.** The stale-response pin used a fixed
3200-character window that overran into the following loader, which contains the
same guard, so it passed even with the guard removed. The windows are now
bounded by the next function.

### 15.2 The one equivalent mutant

**M22 — widening the `IN ($place)` clause on the batched student count.**

It cannot be caught behaviourally, and the reason is structural rather than a
gap in the tests. Each count is `GROUP BY class_id` and the result is read back
by class id, so widening the `IN` clause returns extra rows that are never
looked up — **the answer is identical**. The clause is a *performance* guard,
not a correctness one: without it these queries scan every enrolment, assignment
and assessment in the school on every subject open.

It is therefore pinned at source, on all three count queries, together with the
N+1 guard that no count may be issued inside the per-offering loop. The test
states explicitly why a behavioural assertion cannot reach it. This is the same
honest treatment Phases 2 and 3 gave their own unreachable invariants.

### 15.3 Invariants pinned at source because they are unobservable

| Invariant | Why behaviour cannot reach it |
|---|---|
| the offering-list visibility filter | `canViewClass()` is unconditionally true for the only three roles that reach these endpoints, so removing the filter changes no response today — it would matter the day the tier is widened |
| the `class_id` check at the endpoint | validated again in the service; removing either leaves behaviour identical |
| the entity-identity half of each race guard | the sequence number alone suffices today because every load increments it |
| the `IN` clause on the three count queries | §15.2 |

---

## 16. UI, accessibility and responsiveness

* **No auto-selection anywhere.** No `rows[0]`, no `offerings[0]`. The decisive
  test is a subject offered to **exactly one** class: it is still not opened,
  because the next subject's single class would be a different one. Tested
  behaviourally and pinned at source.
* **Progressive disclosure.** Teachers and assessments arrive together (both
  small, both needed to read the workflow); students are lazy and paginated.
* **Professional icons only**, from the existing Font Awesome set
  (`fa-book-open`, `fa-chalkboard-user`, `fa-user-graduate`, `fa-clipboard-list`,
  `fa-book-bookmark`, `fa-user-slash`, `fa-users-slash`, `fa-ban`). A regex scan
  asserts **no emoji** appears anywhere in the controller.
* **The action is the existing one.** `window.openReviewModal(submission_id)` —
  the alias `edu_dept.php` already exports. The screen does not approve, reject
  or request revision and contains none of those rules.
* **Accessibility.** A real `role="tablist"` with `aria-selected`, roving
  `tabindex`, and Arrow/Home/End keyboard navigation (behaviourally tested);
  `aria-live="polite"` and `aria-busy` on the panel; `scope="col"` on every
  table header; `aria-hidden="true"` on decorative icons; real `<button>`
  elements for every action. Status is carried by a text label, not by colour
  alone.
* **Responsive.** The same primitives Phases 2 and 3 use: `.tw{overflow-x:auto}`
  wrappers so tables scroll rather than overflow, `flex-wrap` headers with
  `min-width` content columns, a horizontally scrollable tab strip, and the
  existing `@media (max-width:640px)` and `prefers-reduced-motion` rules.
* **No chart was added.** There is no subject metric worth plotting that would
  not be a performance score, which is out of scope by instruction.

---

## 17. Changed files and commits

| File | Change |
|---|---|
| `admin/backend/services/AcademicTrackingService.php` | +649 — `subjectDetail`, `subjectOffering`, `subjectStudents`, 9 private helpers, 3 new state constants |
| `admin/api_education.php` | +121 — three actions, tier-3 registration, visibility filter |
| `admin/js/academic_tracking.js` | +667/−13 — the subject workspace, `durationLabel()`, `countCell()` |
| `tests/security/test_subject_tracking.py` | **new**, 1289 lines |
| `tests/e2e/subject_tracking.js` | **new**, 947 lines |
| `tests/security/test_student_tracking.py` | +23/−13 — scope guard narrowed to Class only |

| Commit | Subject | Stat |
|---|---|---|
| `bcf4aa2` | subject scope backend | 2 files, +770 |
| `4da00e2` | subject workflow screen | 2 files, +677/−13 |
| `81320a5` | subject tracking tests | 2 files, +2046 |
| `33410b4` | strengthen tests against surviving mutations | 1 file, +195/−5 |

A diff review against `04b374f` confirms: no `sql/` change, no CI change, no
dependency or config change, no unrelated refactor or rename,
`SubmissionService` and `ReportCardService` untouched, and the only two added
files are tests. The sole deletion in `admin/` is a three-line stale comment.

---

## 18. Database and migration impact

**None.** Every structure Phase 4 needs already exists: `subjects`,
`class_subjects` (with migration 056's `duration_type` / `term_id`), `classes`,
`teacher_assignments`, `class_enrollments`, `members`, `users`, `assessments`,
`grade_submissions`.

No migration was written, no column added, no index added. A test asserts no
migration file mentions Phase 4 subject tracking.

The `sql/055`–`sql/058` instructions are unchanged by this phase and none was
executed.

---

## 19. Known limitations

1. **The offering-list visibility filter is not observable from outside.**
   `canViewClass()` is unconditionally true for the three roles that reach these
   endpoints. Held by a source pin. Same limitation as Phases 2 and 3.
2. **The `IN` clause on the batched counts is a performance guard, not a
   correctness one** (§15.2), and is therefore pinned at source rather than
   behaviourally tested.
3. **An offering carries no per-year existence.** Because `class_subjects` has
   no academic year, Subject Tracking cannot distinguish "offered in 2016 but
   not in 2017" — the schema does not record it. The counts tell the user
   whether anything actually happened in the selected year. Representing
   per-year offerings would require a schema change, which was out of scope and
   is **not** recommended without a product decision.
4. **`class_subjects.term_id` is read but not acted on.** It is returned
   alongside `duration_type`; no behaviour differs by it, because no existing
   rule in the repository distinguishes them at this level.
5. **A teacher cannot see Subject Tracking**, consistent with Phases 2 and 3.
   It is an Education oversight screen and fails closed.
6. **Performance figures are local fixture measurements**, not a load test, and
   carry no automated assertion.
7. **The 12 failing `comm_e2e` / `destructive_guard` tests are environmental**
   and pre-date this work. They were not investigated, being outside this brief,
   and were confirmed byte-identical before and after. CI, which seeds
   `ssms_comm_e2e` properly, passes all 1895 — which confirms those 12 are
   environmental rather than real.

---

## 20. Deliberately deferred work

* Class Tracking — the last of the four root entities.
* Academic exceptions and Phase C synchronisation.
* Any subject workload, coverage or completion analytic. Note that even a
  neutral-sounding "mark lists outstanding per subject" becomes a performance
  metric the moment it is sorted, so it is not being added without an explicit
  product decision.
* Per-year subject offerings (§19.3) — would require a schema change.
* Letting a teacher view their own subjects.
* An automated performance regression assertion.

---

## 21. Phase 5 boundary

Phase 4 ends at the subject. Three of the four root entities now have real
workflows:

| Entity | State |
|---|---|
| Students | Phase 2 — complete |
| Teachers | Phase 3 — complete |
| Subjects | **Phase 4 — complete** |
| Classes | boundary panel, honestly stated |

Selecting a class still renders the "tracking is not built yet" panel.
`test_later_workflows_were_not_built` enforces that Class Tracking acquired no
endpoint and no renderer in this phase.

The likely conceptual direction for Phase 5 is
**Classes → Select Class → Students / Subjects / Teachers → Assessments →
Workflow**. Two observations from Phase 4 that a Phase 5 design should start
from, rather than rediscover:

* `classes` is year-agnostic, exactly as `class_subjects` is. A class's
  academic year comes from `class_enrollments`, `teacher_assignments` and
  `assessments`, so Class Tracking will face the same "the year scopes the
  related data, not the entity" question this phase answered.
* The class→subject edge is already implemented here as
  `AcademicTrackingService::subjectOfferings()`, read in the subject direction.
  Phase 5 needs the same edge read in the class direction, and should extend
  rather than duplicate it.

This is a design boundary, not permission to implement.

---

## 22. Final acceptance checklist

| § 44 criterion | Status |
|---|---|
| Subject root list works | yes — existing Phase 1 catalogue, unmodified |
| No automatic subject selection | yes |
| Subject selection is explicit | yes — including the single-offering case |
| Subject scope visible | yes — header + "Viewing" strip |
| Academic context visible | yes — year chip, labelled as context |
| Offerings use authoritative relationships | yes — `class_subjects` |
| Unrelated offerings cannot be accessed | yes — `not_offered_here`, both endpoints |
| Teacher relationships use `teacher_assignments` | yes |
| `users.role='teacher'` validated | yes — listing and count |
| Homeroom not treated as a subject assignment | yes — listing and count, tested |
| Standing-assignment semantics preserved | yes — kept, flagged, tested |
| Student relationships authoritative | yes — `class_enrollments` |
| Membership not inferred from mark lists | yes — tested with marks left in place |
| Assessments correctly scoped | yes — by class and by subject, disjoint sets |
| Submission statuses reuse existing workflow | yes — `resolvedMarklistRefs()` |
| No new workflow state machine | yes |
| Workflow actions reuse existing workflows | yes — `openReviewModal` |
| Academic calculations remain server-side | yes — none performed at all |
| No JavaScript academic calculation | yes — tested and mutation-tested |
| No subject ranking/effectiveness | yes — asserted by name, both layers |
| No fake zeros | yes — nulls preserved, zeros rendered as words |
| Not-offered / no-teacher / no-student / no-assessment distinct | yes |
| Loading / error / filtered-empty distinct | yes |
| Cross-scope authorization tested | yes — 11 cases |
| Race protection tested behaviourally | yes — all three guards, out of order |
| N+1 behaviour tested | yes — flat at 1, 5 and 10 offerings |
| Performance measurements recorded | yes — §13, local |
| Mutation testing catches critical mutations | yes — 47/48, 1 equivalent and pinned |
| Student Tracking still functional | yes — 59 tests, 123 checks |
| Teacher Tracking still functional | yes — 89 tests, 166 checks |
| Academic Intelligence still functional | yes — suite passes |
| Existing Subject management still functional | yes — catalogue unmodified, pinned |
| No unnecessary migration | yes — none at all |
| No unnecessary dependency | yes |
| No new permission system | yes |
| No new API file | yes |
| Professional icons, no emojis | yes — scanned |
| Responsive / accessibility | yes — §16 |
| Documentation complete | this document |
| No unrelated changes | yes — §17 diff review |
| Clean tree, pushed, CI green | yes — `5d2286d` on `origin/main`, CI #42 green |
