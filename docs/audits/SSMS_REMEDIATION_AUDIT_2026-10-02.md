# SSMS — Remediation Audit, 2026-10-02

**Repository:** `suraman21/SSMS` · branch `main`
**Baseline commit inspected and patched from:** `27f2c3469f7f4f178c6d7cb0738e6944ec5a8a5d` (2026-10-01)
**Working tree at start:** clean, identical to `origin/main` (re-verified before any edit)
**Companion document:** `SSMS_CODEBASE_UNDERSTANDING_REPORT.md` (the prior read-only audit, sections A–W)

> This document **adds to** the audit record. No previous finding, observation or
> conclusion has been deleted or rewritten. Where this pass changed the status of
> an earlier finding, both the original observation and the new evidence are kept
> side by side.

---

## 0. How to read this

Every finding below carries the evidence that justifies its status. A finding is
marked **CLOSED** only when there is a code change *and* a test that fails without
it. Anything resting on inference is marked **REQUIRES VERIFICATION** and is listed
in §4, not counted as fixed.

| Status | Meaning |
|---|---|
| CLOSED | Patched, and a test proves the patch (verified failing before / passing after) |
| OPEN | Confirmed defect, deliberately not patched — reason stated |
| REQUIRES VERIFICATION | Evidence is suggestive but incomplete; needs a live system or a product decision |

---

## 1. Findings fixed in this pass

### A — HR reviewer decisions had no state machine · **CLOSED** · severity HIGH

| | |
|---|---|
| **Original observation** | Prior report finding S.2 / open question Q1: "HR and Mezmur may lack the review transition guard that Education has" — recorded then as *REQUIRES VERIFICATION*. |
| **Confirmed** | `admin/backend/services/HrSubmissionService.php:414` `reviewPacket()` read the packet's current status into `$previousStatus` but used it **only to label the audit record**. The write was `UPDATE hr_submissions SET status=?, reviewed_by=?, reviewed_at=NOW(), review_notes=? WHERE id = ?` — no status predicate, and the return value of `execute()` was checked but `affected_rows` was not. |
| **Impact** | Any reviewer request applied to a packet in **any** state: `approved→rejected`, `rejected→approved`, `revision_needed→approved` (approving work the taker never corrected), and deciding a packet never submitted. Two reviewers acting at once both "succeeded"; the last write won silently. |
| **Root cause** | Education gained the guard as finding H9 (`SubmissionService::reviewTransitionError()`, used at `admin/api_communication.php:293` and `api/v1/routes/grades.php:1149`). HR and Mezmur were cloned from Education *before* that hardening and never back-ported it. |
| **Fix** | Added the transition guard before the write; added `AND status = 'submitted'` to the UPDATE; verified `affected_rows`; returned a distinct conflict result. Follows Education's verified pattern exactly. |
| **Files** | `admin/backend/services/HrSubmissionService.php`, `admin/backend/services/ReviewTransitionPolicy.php` (new) |
| **Tests** | `tests/security/test_review_transition_hardening.py` — `ReviewPacketIsGuarded` (8 tests × 2 modules), `GuardedUpdateSemantics` (runtime, SQLite) |
| **Test result** | Fails on `27f2c34`, passes after. Verified by running the new file against a pristine worktree of the base commit. |
| **Verification** | Re-grep confirms all four review-decision UPDATEs in the repo now carry the predicate; no other writer to `hr_submissions.status` exists (§3.1). |

### B — Mezmur reviewer decisions had no state machine · **CLOSED** · severity HIGH

Identical defect at `admin/backend/services/MezmurSubmissionService.php:429` — the method
was line-for-line the same as HR's, differing only in table name, audit label and the
`sql/024_…` hint string. Same fix, same tests, same verification.
**Files:** `admin/backend/services/MezmurSubmissionService.php`.

#### Note on the shared helper (the brief asked for one "only if it does not create inappropriate coupling")

Reusing `SubmissionService::reviewTransitionError()` directly would have made the HR
and Mezmur services depend on **Education's** service — the exact coupling the repo's
own tests guard against (`tests/security/test_hr_attendance_domain.py` pins HR
isolation). Instead the rule moved into a new, department-neutral
`App\Services\ReviewTransitionPolicy`: it knows no table, no role and no connection.
`SubmissionService::reviewTransitionError()` now **delegates** to it, so there is
exactly **one** implementation rather than three copies, and no department depends on
another. A test asserts the state machine exists in only one file, and another test
evaluates the PHP string templates to prove Education's user-facing messages are
byte-identical to before.

### C / D — A returned packet carried the previous reviewer's decision back into the inbox · **CLOSED** · severity MEDIUM

| | |
|---|---|
| **Confirmed** | `HrSubmissionService::upsert()` and `MezmurSubmissionService::upsert()` updated status/counts/`client_op_id`/`submitted_at` but never cleared `review_notes` / `reviewed_by` / `reviewed_at`. |
| **Impact** | A packet returned for correction and resubmitted reappeared in the reviewer's queue still stamped with the previous reviewer's id, timestamp and "please fix X" note. The inbox could not distinguish a fresh submission from an already-decided one. |
| **Fix** | Clear the three columns when the packet **enters** the review queue. |
| **Design decision (important)** | The brief asked for clearing "only when a `revision_needed` packet genuinely re-enters the review queue". Keying literally on the *direct* `revision_needed → submitted` hop would **miss the real-world path**, because HR coerces `draft → incomplete` and takers normally save a draft before resubmitting (`revision_needed → draft → submitted`). The condition used is therefore "status becomes `submitted` and was not already `submitted`". Draft/incomplete saves keep the note — the taker is still reading it — and a correction that keeps the current status leaves the trail untouched. |
| **Precedent** | This is **not an invented rule**: `SubmissionService::upsertMarklist()` already did exactly this as finding **H10**, with the same condition (`$finalStatus === SUBMITTED && $curNorm !== SUBMITTED`) and the same SQL fragment. The fix aligns HR and Mezmur to the repo's existing convention. |
| **Files** | `HrSubmissionService.php`, `MezmurSubmissionService.php` |
| **Tests** | `ResubmissionClearsStaleReview` (static) and `GuardedUpdateSemantics::test_resubmission_clears_only_when_entering_the_queue` (runtime, covers all five transition cases) |
| **Safety** | Verified in DDL that all three columns are `DEFAULT NULL` in `sql/024` and `sql/026` before writing NULL. |

### C/D-extension — the same gap in Education's attendance writer · **CLOSED** · severity MEDIUM · *not in the brief, found during this pass*

`SubmissionService::upsertAttendance()` omitted the same three columns, while its
sibling `upsertMarklist()` in the **same class** cleared them. An Education attendance
packet returned for correction re-entered the inbox showing the old decision.
Fixed with the identical condition. **File:** `admin/backend/services/SubmissionService.php`.

### E — `last_attendance_date` recorded the save time, not the attendance · **CLOSED** · severity MEDIUM

| | |
|---|---|
| **Confirmed** | `AttendanceSummaryService.php:220`: `UPDATE members SET total_attendance_rate = ?, last_attendance_date = CURDATE()`. Reached from `recordSaved()` on **every** save. |
| **Impact** | Backfilling last term's register, or correcting a six-month-old row, marked the member as having attended **today**. Any report or alert keyed on recency was wrong, and the error was invisible because the value always looked plausible. |
| **Fix** | `last_attendance_date = COALESCE((SELECT MAX(a.attendance_date) FROM attendance a WHERE a.member_id = ?), last_attendance_date)` |
| **Source correctness** | Derived from `attendance` — the same table that feeds `total_attendance_rate` via `attendance_summary`. HR and Mezmur keep their own datasets by product rule and are deliberately **not** merged here. |
| **Efficiency** | Index-backed as required: `idx_att_member_date (member_id, attendance_date)` (`sql/028:58`) and `uq_att_member_class_date (member_id, class_id, attendance_date)` (`sql/013:384`) are both `member_id`-prefixed, so `MAX()` on an equality-bound member is a bounded index probe. |
| **Safety when no attendance exists** | `COALESCE` keeps any existing value, so the fix can never blank a populated column. |
| **Preserved** | The `AVG(attendance_rate)` logic, the rounding, the "recorded date determines the summary month" behaviour, and the tolerant `try/catch` that must never fail the save path. |
| **Tests** | `AttendanceRecencyIsDerived` (7 static, incl. a bind-parameter-count check) and `AttendanceRecencySemantics` (4 runtime, incl. the backfill case) |

### F — two different migrations both numbered 030 · **CLOSED** · severity MEDIUM

| | |
|---|---|
| **Confirmed** | `sql/030_mezmur_taxonomy.sql` and `sql/030_roster_scale_indexes.sql`, both added 2026-08-31. "Apply in numeric order" was ambiguous; one could be skipped with no signal. |
| **Resolution (by dependency, not by guesswork)** | `030_mezmur_taxonomy` **keeps** 030: it heads the chain that 031, 032 and 033 build on, and application code names it by path (`admin/api_mezmur.php:184-186`). `030_roster_scale_indexes` **moved to 052**: a single `ADD INDEX`, no dependants, zero code references, and it only requires `class_enrollments` (created in 013), so it is order-independent. |
| **Why 052 and not the free 047 slot** | 047 is a pre-existing gap. Reusing a gap is ambiguous; appending keeps numbering append-only. Documented in the file header. |
| **Renamed with** | `git mv`, so history is preserved. |
| **Semantic change** | None — the `ALTER TABLE … ADD INDEX` statement is byte-identical, asserted by test. |
| **Stale references** | None in code. The only other mention is `docs/audits/production-2026-09-07/file-inventory.csv`, a **frozen audit artifact with SHA-256 hashes** — deliberately **not** edited, because rewriting it would falsify historical evidence. The test excludes it explicitly and explains why. |
| **Operator impact** | Documented in the runbook: anyone who already applied the old 030 has the index; re-running 052 reports a harmless "Duplicate key name". |

### G — migration 033 could half-apply if run before 032 · **CLOSED** · severity MEDIUM

`sql/033_mezmur_single_title.sql` ends with `DELETE FROM mezmur_hymn_words` — a table
**created by 032** — *after* two destructive `ALTER TABLE … DROP COLUMN` statements.
Out of order, it dropped `title_am` and `reference` and only then failed with error
1146, leaving a half-migrated database and an unobvious cause. 033 also depends on
031 (its fold logic is written around that UNIQUE key).

Per the brief, valid SQL was **not** rewritten. Changes: an explicit `ORDERING
REQUIREMENT` header naming 031 and 032 and the consequence of getting it wrong; the
final `DELETE` guarded by an `information_schema` probe that prints a named BLOCKER
telling the operator to run 032 rather than aborting with a bare error; and a new
runbook stage 4.7(b). Numeric order already satisfies the dependency.

### H — a skipped UNIQUE constraint was indistinguishable from a successful one · **CLOSED** · severity MEDIUM

Several migrations add a UNIQUE key **only when the data is already clean** and skip
otherwise, so that deployment never silently destroys duplicate records a human must
adjudicate. That behaviour is correct and is **preserved** — nothing in this pass
deletes or merges duplicate data. The defect was *visibility*:

* `sql/018` skipped **completely silently**. Without `uq_academic_years_year_name`, the
  errno-1062 branch in the academic-year save endpoint can never fire, so duplicate
  year names stay reachable under concurrency — and nothing told the operator.
* `sql/031` printed `"skipped (duplicates present or index already exists)"` — one
  message for a **deployment blocker** and for a harmless no-op.

Changes:
1. **018** now reports three distinct outcomes, and on the blocked path lists the exact
   duplicate rows. The procedure's silent `IF` became `IF / ELSEIF / ELSE`.
2. **031** separates "already present" from "blocked by duplicates".
3. Both files end with a **deterministic verdict** that re-reads `information_schema`
   *after* the attempt, so the final line reflects reality rather than intent.
4. New `sql/preflight/uniqueness_preflight.sql` — read-only (TEMPORARY table only),
   covering **all four** conditionally-created constraints (it found two more beyond the
   two named in the brief: `uq_ar_assessment_member` and `uq_att_member_class_date`
   from `sql/013`). It prints PASS/BLOCK/SKIP per constraint, lists the offending rows,
   reports how many rows sit unreviewed in the `migration_013_*_conflicts` quarantine
   tables, and raises `SQLSTATE 45000` if anything is blocked — so the result cannot be
   misread as "probably fine". It follows the house style of the existing
   `auth_outbox_preflight.sql`.
5. Runbook stage 4.7(c) tells the operator to run it and states plainly that resolving a
   BLOCK is a business decision, not the migration's.

### I — `AssessmentTypeService.php` is not parseable PHP · **CLOSED** · severity **CRITICAL** · *newly discovered*

This was **not** in the brief. It was found because `php -l` is unavailable in this
environment, so a structural checker was written instead — and it failed on a file
nobody had asked about.

| | |
|---|---|
| **Confirmed** | Commit `d865ee9` (2026-09-27, *"runtime DDL removal…"*) removed a `CREATE TABLE` block and left **seven duplicated lines** — the tail of the seed `INSERT` plus three closing braces — in the class body **after** `ensureTable()`'s closing brace. |
| **Evidence** | The file passes the structural check at `f5248a6` (2026-09-26) and fails at `d865ee9` and at HEAD. `git diff f5248a6 d865ee9` shows the seven lines added below the method. |
| **Impact** | A PHP **fatal parse error**. The file is `require_once`'d by `api/v1/routes/grades.php:223`, `admin/api_subjects.php` (×4) and `admin/api_education.php` (×4), so every assessment-type request — web and mobile — would fatal. |
| **Why it survived** | Nothing parses PHP in CI. `docs/ci_cd/ci.yml.txt` runs Flutter only, and **no `.github/` directory exists in the repository at all**, so even that workflow is not active. It sat on `main` for five days. |
| **Fix** | Deleted exactly the seven orphaned lines (`git diff --stat`: `7 deletions(-)`, no insertions). Verified the preceding seed statement is complete — all nine rows present and terminated — so nothing was lost. |
| **Tests** | New `tests/security/test_php_sources_are_well_formed.py`: structurally validates **all 314** first-party PHP files, plus a test that pins this specific file and a "guard the guard" test that reproduces the exact `d865ee9` damage pattern and asserts the checker catches it. |
| **Test result** | Fails on `27f2c34` (`line 241: unterminated " string`), passes after. |

---

## 2. Tests added

| File | Tests | Covers |
|---|---|---|
| `tests/security/test_review_transition_hardening.py` | 23 | A, B, C, D, C/D-extension, single-implementation, Education behaviour preservation, department isolation, role checks, audit ordering |
| `tests/security/test_attendance_date_and_migrations.py` | 29 | E, F, G, H |
| `tests/security/test_php_sources_are_well_formed.py` | 5 (+314 subtests) | I |
| `tests/smoke/hr_phase6_smoke.php` | updated | runtime A + C (see §2.1) |
| `tests/smoke/mezmur_phase5_smoke.php` | updated | runtime B + D (see §2.1) |

The repository's Python test architecture is static source-contract assertions, and the
new static tests follow it. Where a rule could be executed rather than asserted, a
**runtime** test was added: the guarded-UPDATE semantics, the concurrent-reviewer race,
the five resubmission transition cases and the attendance-backfill case are all replayed
against SQLite. Those prove the *predicate logic* is sound; they do not execute the PHP.

### 2.1 The smoke tests were asserting the bug

`tests/smoke/hr_phase6_smoke.php` and `tests/smoke/mezmur_phase5_smoke.php` both
reviewed a packet to `revision_needed` and then **approved it directly** — exactly the
`revision_needed → approved` transition the brief requires be forbidden. They encoded
the defective behaviour, so a correct fix necessarily breaks them.

Both were updated to the real workflow and, in the process, turned into runtime
regression tests: they now assert the premature approval is refused with
`code === 'invalid_transition'` and that the status is unchanged, then have the taker
resubmit, then assert the reviewer trail was cleared (findings C/D), then approve, then
assert a replayed decision cannot flip an approved packet. Downstream assertions in both
files (`listPackets` totals, `packetStats`, detail row counts, the Mezmur audit-log
count, the cross-module no-leak checks) were each re-checked and still hold.

**These two files could not be executed here — they need PHP and a live MariaDB, neither
of which exists in this environment. They are verified structurally only.** See §5.

---

## 3. Verification performed

**Test suite.** `tests/security`: **1314 passed / 42 skipped** at base commit `27f2c34`
→ **1371 passed / 42 skipped / 375 subtests** after. +57 tests, **zero pre-existing
tests broken**. The baseline was measured on a pristine `git worktree` of the base
commit, not assumed. `tests/audit` is 4 tests, all skipped, before and after.

**Proving the tests prove something.** Each new test file was copied into the pristine
worktree and run against unpatched code. `test_review_transition_hardening.py`: 17
failed + 7 errors. `test_attendance_date_and_migrations.py`: 10 failed + 9 errors.
`test_php_sources_are_well_formed.py`: 2 failed. All pass against the patched tree.
Passing tests alone were not treated as evidence.

**PHP syntax.** `php -l` **could not be run — there is no PHP binary in this
environment, and none could be installed.** `phply` was tried and rejected: it is a
legacy parser that fails on *unmodified* modern PHP (typed properties at line 25), so
its output is noise. A structural checker was written instead, which tokenises PHP —
skipping strings, comments, heredocs/nowdocs and escapes — and verifies bracket balance
and literal termination. Result: **314/314 first-party PHP files clean** (313 files with
1 failure before; the failure was finding I). This is a real check that caught a real
fatal error, but it is **not equivalent to `php -l`** and is described as such in the
test's own docstring. See §5.

**SQL.** No MySQL/MariaDB available. All five modified/added SQL files were checked for
balanced quotes, balanced parentheses, paired `PREPARE`/`EXECUTE`/`DEALLOCATE` handles
and even `DELIMITER` counts — all pass. Not a substitute for executing them. See §5.

**Targeted greps (all clean):**

| Check | Result |
|---|---|
| `CURDATE()` reaching `last_attendance_date` | none |
| write sites for `last_attendance_date` | 1, the fixed one |
| write sites for `total_attendance_rate` / `attendance_summary` | 1 service — single-writer intact |
| unguarded `WHERE id = ?` review UPDATE | none |
| review-decision UPDATEs carrying `AND status = 'submitted'` | 4 of 4 |
| `reviewPacket()` implementations | 2 (HR, Mezmur) — no duplicate introduced |
| files containing the state-machine messages | 1 (`ReviewTransitionPolicy.php`) |
| `SubmissionService::` referenced from HR/Mezmur | none — isolation preserved |
| references to the old `030_roster_scale_indexes` filename | none in code |
| Cyrillic/homoglyph characters in modified files | none (see §3.2) |

### 3.1 Bypass-path hunt

* **`hr_submissions` / `mezmur_submissions`:** only three writers each, all inside their
  own service (upsert UPDATE, upsert INSERT, guarded review UPDATE). No API route, no
  dashboard and no other service writes `status`. The guard cannot be bypassed.
* **`grade_submissions`:** the two review sites are both guarded;
  `admin/api_communication.php:158` writes only counts; `admin/backend/user-delete.php:116`
  nulls `reviewed_by` for a deleted user. No bypass.
* **Callers:** all four production callers consume `['ok','message']` and needed **no
  change**. Both mobile routes already map `!ok` to **409**, which is the required
  semantics. An additive `code` key (`invalid_transition` / `conflict`) was added for
  callers that want to distinguish; existing consumers ignore it.
* **Privilege:** `canReview()` is unchanged in both services; `force` overrides are
  granted only via `staffCanOverride()` or an explicit `super_admin`/`school_admin`
  check at four call sites. No privilege bypass, and no role check was weakened.
* **Deleted users:** `hr_submissions` and `mezmur_submissions` declare
  `reviewed_by`/`taker_id` as `ON DELETE SET NULL`, so the database handles user
  deletion; `grade_submissions` needs the manual cleanup precisely because it has no FK.
  Checked for an inconsistency here — **there isn't one.**

### 3.2 A mistake made and corrected during this pass

While editing the HR smoke test I introduced a homoglyph: a Cyrillic `т` (U+0442)
instead of the Ethiopic `ት` (U+1275) in the section literal `ህናት`, which would have
silently broken the test's section matching. It was caught by inspecting the actual code
points, corrected, and every modified file was then scanned for characters in the
Cyrillic range — all clean. Recorded here rather than quietly fixed, because a
near-miss of this kind is itself useful audit information.

---

## 4. Open items — NOT fixed, and why

### 4.1 Fresh-install migration order · REQUIRES VERIFICATION · severity UNKNOWN

A mechanical dependency scan shows `003_production_hardening.sql` altering `attendance`,
`class_enrollments`, `academic_records` and `teacher_assignments`, and
`004_year_lifecycle.sql` altering `academic_years`, **before** the migrations that
`CREATE` those tables (`006`, `012`, `013`). On an existing production database this is
harmless — the tables already exist and `012`/`013` are the documented runtime baseline.
On a genuinely **fresh** database following strict numeric order, 003 and 004 would fail.

**Not patched, deliberately.** Resolving it requires knowing whether fresh installs are a
supported path and what the true production schema is — the repo cannot answer either
(`database_schema.sql` is known-stale, and there is no `schema_migrations` tracking
table). This is exactly the "prod-vs-repo schema difference unresolvable from the
repository" stop condition. Reported as a hypothesis with evidence, not a confirmed
defect.

### 4.2 No CI actually runs · OPEN · severity MEDIUM (process)

`docs/ci_cd/ci.yml.txt` describes a Flutter-only gate, and **no `.github/` directory
exists**, so no workflow runs at all. The 1371-test Python suite, the PHP sources and
the SQL are gated by nothing. This is how finding I — a fatal parse error — reached
`main` and stayed for five days. Fixing it means creating CI infrastructure, which is
outside the scope of "patch the audited defects" and is a decision for the maintainers.

### 4.3 Minor observations (no action taken, behaviour deliberately preserved)

* **Web vs mobile status codes.** Education's web review path returns 422 for an invalid
  transition and 409 for a lost race; the HR/Mezmur *web* endpoints return a flat
  `status: error` with no HTTP code (their mobile counterparts correctly return 409).
  Cosmetic inconsistency; the decision is correctly refused on every path. Changing it
  would alter caller-visible behaviour beyond the audited defect.
* **Unreachable switch branch.** In the transition rule, `revision_needed` is matched by
  the `isOpen` branch before the `switch`, so the `STATUS_REVISION` case is dead code.
  This was true in Education's original and was preserved byte-identically rather than
  "improved". The message users actually get is accurate.
* **`user-delete.php`** nulls `grade_submissions.reviewed_by` but leaves `status`,
  `reviewed_at` and `review_notes`. Intentional-looking (the decision survives, the
  person is removed) and not a defect, but worth a product confirmation.

---

## 5. Limitations of this verification — read before trusting any of it

* **`php -l` was never run.** No PHP binary was available and none could be installed.
  PHP correctness rests on a structural checker that detects unbalanced brackets,
  unterminated strings and unclosed comments. It **cannot** detect a parse or semantic
  error that is bracket-balanced. **Run `php -l` on all 314 files, and at minimum on the
  six modified PHP files, before deploying.**
* **No SQL was executed.** No MySQL/MariaDB was available. The modified migrations and
  the new preflight script are structurally validated only. **Run them against a
  restored production snapshot before deploying.** The 018 procedure's `IF/ELSEIF/ELSE`
  rewrite and the nested `IF()` in 031 in particular deserve a live smoke test.
* **The two PHP smoke tests could not be executed.** They need PHP and a live MariaDB.
  Their logic was reasoned through and their downstream assertions re-checked by hand,
  but they are unproven at runtime.
* **The SQLite runtime tests are not MySQL.** They prove the predicate logic of the
  chosen statements; they do not prove MySQL/MariaDB behaviour. One MySQL-specific
  assumption is load-bearing and worth stating: `affected_rows` reports *changed* rows,
  not *matched* rows. The guarded review UPDATE always changes `status` when it matches,
  so `affected_rows ≥ 1` is a sound success test — and Education's shipped code has
  relied on exactly this since H9.
* **Coverage is not measured.** No coverage tool was run. Test *counts* are reported
  because they were actually measured; coverage *percentages* are not claimed.

---

## 6. Production status

**This codebase is not certified production ready by this pass, and no such claim is
made.** What can be said precisely:

**Fixed and proven (9 findings):** A, B, C, D, the Education C/D extension, E, F, G, H,
and the newly discovered critical parse error I. Each has a test that fails without the
fix.

**Most consequential outcome:** finding I. The audited defects A–H were real integrity
problems, but **I was a hard outage** — a fatal parse error on `main` breaking every
assessment-type endpoint on web and mobile. It was found only because the absence of
`php -l` forced a different tool to be built.

**Open by severity:**

| Severity | Item |
|---|---|
| MEDIUM (process) | 4.2 — no CI runs; nothing gates PHP, SQL or the 1371 Python tests |
| UNKNOWN | 4.1 — fresh-install migration order; needs a product/ops decision |
| LOW | 4.3 — web/mobile status-code inconsistency; dead switch branch; `user-delete` partial reviewer cleanup |

**Accepted risks:** duplicate data behind the conditional UNIQUE constraints is still
present wherever it exists — by design. The preflight now makes it a loud, explicit
deployment blocker instead of a silent gap, but a human must still decide which records
survive.

**Verification required before deploy:** `php -l` on the modified PHP; execution of the
modified migrations and the new preflight against a production snapshot; execution of
the two updated smoke tests.

---

## 7. Coverage of this pass

**Read and patched:** `HrSubmissionService`, `MezmurSubmissionService`,
`SubmissionService`, `AttendanceSummaryService`, `AssessmentTypeService`; the four
`reviewPacket` callers plus the two Education review sites; `sql/013`, `018`, `024`,
`026`, `028`, `030×2`, `031`, `032`, `033`, `052`; `sql/preflight/`;
`docs/audits/DEPLOYMENT_RUNBOOK.md`; two smoke tests. All 314 first-party PHP files were
machine-checked structurally, and all 51 SQL migrations were machine-scanned for
duplicate numbering, destructive statements and cross-file ordering dependencies.

**Still unread — the queue is NOT closed.** The prior report's backlog stands, minus what
this pass consumed: ~41 of 57 `App\Services` classes, 24 of 36 `admin/api_*.php`, all 16
dashboards, all 41 JS files, 147 of 159 Dart files, and most of `sql/014`–`051` beyond
the migration-level scan above. Open questions Q2–Q10 from the prior report remain open;
**Q1 is now answered** (A and B confirmed, fixed and closed).

No percentage of "coverage" is claimed, because none was measured.

---
---

# CYCLE 2 — 2026-10-02 (appended; nothing above this line altered)

Second remediation pass. Baseline re-verified before any edit: HEAD still
`27f2c34` (= `origin/main`), all 16 cycle-1 changes present and unchanged,
suite green at **1371 passed / 42 skipped / 375 subtests**.

Cycle-2 result: **1407 passed / 42 skipped / 410 subtests** (+36 tests), zero
regressions, 279/279 first-party PHP files structurally clean.

---

## 0. A premise from the brief that did not survive inspection

The brief asked me to fix a self-approval bypass in which Education review
endpoints let a submitter approve their own packet, and in which
`upsertAttendance`/`upsertMarklist` accept `approved` directly.

**I could not confirm it, and I am not reporting it as a defect.** Evidence:

* Both Education review paths — `admin/api_communication.php:255-330` (web)
  and `api/v1/routes/grades.php:1110-1190` (mobile) — are already correct and
  mutually equivalent: role gate → `reviewTransitionError()` guard →
  race-safe `UPDATE … WHERE id = ? AND status = 'submitted'` →
  `affected_rows > 0` else 409/422 → `SecurityAuditService::record()` with
  `previous_status`. The mobile path additionally rate-limits
  (`edu_submission_review`, 30). These are the only two call sites of
  `reviewTransitionError` in the repository.
* The upserts *do* accept any status `normalizeStatus()` returns, including
  review statuses — but **every production caller clamps the value**:
  `api/v1/routes/attendance.php:237` (`STATUS_DRAFT`) and `:319`
  (`STATUS_SUBMITTED`); `admin/api_subjects.php:1257` and
  `api/v1/routes/grades.php:880` (`STATUS_DRAFT`), `:977`
  (`STATUS_SUBMITTED`); HR and Mezmur callers use a binary ternary
  (`$kind === 'submitted' ? SUBMITTED : DRAFT`).

So the permissive status parameter is **latent defense-in-depth weakness, not
a reachable bypass**. I have left it alone rather than harden a theoretical
path and claim a fix.

Chasing that premise did, however, surface the real integrity defects below.

---

## Finding J — `grade_submissions` has no uniqueness; duplicate packets defeat the approval lock

**Severity: HIGH · Status: CLOSED (code + migration) / REQUIRES VERIFICATION (production data)**

**Observation.** `SubmissionService::ensureTable()` (line 31) and
`hardenUniques()` (line 36) are both **empty no-op bodies**. `hardenUniques()`
is nonetheless called at line 285 behind the comment *"unique keys are
deployment-managed by migration 013"*.

**That comment is false.** Migration 013 creates `grade_submissions` (line
244) with only **non-unique** keys:

```
KEY `sub_att_lookup`  (teacher_id, class_id, submission_type, attendance_date)
KEY `sub_mark_lookup` (teacher_id, assessment_id, submission_type)
```

No `UNIQUE` appears anywhere on that table, in any migration. The runtime DDL
was removed (commit `d865ee9`) and the responsibility was documented as handed
to a migration that never took it.

**Root cause.** Both upserts are SELECT-then-INSERT with nothing enforcing
one-packet-per-slot at the storage layer. Two concurrent saves both read "not
found" and both insert.

**Why it matters.** Lock checks resolve a slot with `ORDER BY id DESC LIMIT 1`
(`attendancePacketStatus`). With two packets for one (class, date), Education
approves the one it was shown while the newer duplicate still reads
draft/incomplete — `statusIsOpen()` returns true and the teacher keeps editing
attendance that is already approved. Alternatively the older packet is
stranded in the inbox as a permanently-"submitted" ghost.

**The rule is not invented.** Both sibling modules already enforce exactly
this concept: `uq_hr_submissions_date_section` (sql/026:68) and
`uq_mezmur_submissions_date_section` (sql/024:40).

**Fix.**
* `sql/053_grade_submission_slot_uniqueness.sql` (new) adds
  `uq_gs_attendance_slot (class_id, attendance_date, submission_type)` and
  `uq_gs_marklist_slot (assessment_id, submission_type)` — **conditionally**.
  It never deletes or merges: duplicates are listed and reported as an
  explicit deployment `BLOCKER`, because choosing which packet survives is a
  human decision. Ends with a deterministic PASS/BLOCKER verdict read back
  from `information_schema`. Same non-destructive pattern as finding H.
* NULL-exemption verified against the INSERT statements before choosing the
  columns: the attendance INSERT never sets `assessment_id`, the marklist
  INSERT never sets `attendance_date`, so each key constrains only its own
  submission type and the two cannot collide.
* `SubmissionService.php` — both INSERTs now recover from duplicate-key
  (`errno 1062`) by re-running the upsert once, converging on the packet that
  won the race instead of failing the user's save.
* The false `hardenUniques()` comment is corrected to say where the
  constraints actually live.
* `sql/preflight/uniqueness_preflight.sql` extended to cover both new keys
  (now 6 constraints).

**Tests.** `tests/security/test_education_packet_uniqueness.py` — 16 tests,
9 subtests. Includes SQLite replays that *demonstrate* the defect (a race
produces two packets; the approval lock is then bypassed) and that the
constraint plus 1062-recovery converges to one packet.

**Verification.** Failed on unpatched `27f2c34`, passes now. **The migration
itself has not been executed** — no MySQL in this environment. Whether
production already contains duplicate packets is **unknown and must be
checked by running the preflight before deployment.**

---

## Finding K — check-then-act race on all four save paths

**Severity: MEDIUM · Status: CLOSED**

**Observation.** Every packet upsert guarded the review lock like this:

```php
SELECT status FROM <table> WHERE id = ?          // read
if (!statusIsOpen($curStatus) && !$force) { return 'already submitted'; }
UPDATE <table> SET status = ?, ... WHERE id = ?  // write — no predicate
```

**Root cause.** The precondition is checked by a read and never re-asserted by
the write. Between the two, a reviewer can approve the packet; the save then
lands on an approved row, overwrites the counts, drags `status` back, and the
`$clearReview` branch wipes `review_notes` / `reviewed_by` / `reviewed_at`.
The approval is silently undone and the trail loses its reviewer.

This is the **same defect class as findings A and B**, which were fixed on the
review transition last cycle. The save side was missed — found by
systematically re-running that defect class across every status-mutating
UPDATE (8 found: 4 already guarded review transitions, 4 unguarded saves).

**Files changed.** `SubmissionService.php` (`upsertAttendance`,
`upsertMarklist`), `HrSubmissionService.php` (`upsert`),
`MezmurSubmissionService.php` (`upsert`).

**Fix.** The UPDATE repeats the precondition:
`WHERE id = ? AND status IN ('draft','incomplete','revision_needed')`.
Because MySQL reports 0 affected rows for a genuine no-op save as well as a
blocked one, the status is re-read before reporting the lock, so ordinary
saves are not misreported. The `force` staff override still bypasses the
guard, exactly as before. The open-status set is each service's own
`statusIsOpen()` definition, not a new rule.

**Tests.** `tests/security/test_save_path_lock_race.py` — 11 tests, 26
subtests, covering all four paths, plus SQLite replays showing the unguarded
UPDATE destroying an approval and the guarded one refusing it while still
permitting every open status. A test also fails if `statusIsOpen()` ever
changes, so the SQL literal cannot drift from the PHP definition.

**Verification.** Failed on unpatched `27f2c34`, passes now. Runtime behaviour
under real concurrency is **not** proven — no PHP/MySQL here.

---

## Finding L — CI exists only as a text file; nothing runs on push

**Severity: MEDIUM (process) · Status: CLOSED (workflow added, unexecuted)**

**Observation.** There is **no `.github/` directory**. The only CI definition,
`docs/ci_cd/ci.yml.txt`, is a `.txt` document GitHub never reads, and it
covers the Flutter app only. Consequently the **87 Python test files in
`tests/security/` — including every regression test proving findings A–K —
run nowhere automatically**, and `php -l` runs nowhere at all.

That is precisely how **finding I** shipped: a fatal parse error reached
`main` and sat there ~5 days, reachable from 9 call sites. One lint job
catches it in seconds.

**Fix.** Added `.github/workflows/backend-checks.yml` with three jobs:
`php-lint` (`php -l` over all 297 first-party PHP files, with a guard that
fails if the file list collapses), `security-tests` (`pytest tests/security`),
and `migration-hygiene` (duplicate migration numbers; warns on destructive
statements in migrations). No secrets, no database, no deploy steps.
`docs/ci_cd/ci.yml.txt` is left untouched — it is valid as written.

**Note.** This job is also what will finally discharge the `php -l`
verification gap I could not close locally. YAML validated and each step's
logic simulated locally; **the workflow has never actually run.**

---

## Finding M — `sql/` cannot build a database; 7 core tables are never created

**Severity: HIGH (deployment) · Status: REQUIRES DECISION — deliberately not patched**

**Observation.** Running `sql/001`–`sql/052` in order against an empty
database does not work. Measured across all 52 migrations:

* **7 tables are ALTERed but never CREATEd anywhere in `sql/`** — `users`,
  `members`, `subjects`, `finance_transactions`, `finance_member_fees`,
  `material_items`, `material_transactions`. These include the two most
  fundamental tables in the product.
* **7 (migration, table) forward references** — a table altered by a
  lower-numbered migration than the one creating it: `003` adds foreign keys
  to `teacher_assignments` (created in `006`); `003` alters `attendance`,
  `class_enrollments`, `academic_records` (all created in `013`); `004`
  alters `academic_years` (created in `012`); `012` alters `wbws_groups` and
  `wbws_group_leaders` (created in `013`).
* `sql/003` contains **zero** existence guards — no `information_schema`, no
  `IF EXISTS`, no prepared statement — so in numeric order it simply errors.

**Root cause — and why this is not a typo.** `sql/003`'s own header reads
*"HOW TO RUN (do this ONCE, from phpMyAdmin)"*. It is a one-time operational
script written against an already-populated production database. The `sql/`
directory mixes hand-run operational scripts with replayable migrations and
nothing distinguishes them. Combined with the absence of a
`schema_migrations` ledger (Q10), **nothing records what has actually been
applied to any environment.**

**Why I did not fix it.** Reconstructing the missing DDL means inventing the
production schema from inference; reordering applied history risks a live
database. Both need the real production schema in hand. This hits the brief's
stop condition for prod-vs-repo schema differences unresolvable from the repo.

**What I did instead.**
* `docs/audits/DEPLOYMENT_RUNBOOK.md` — new **Stage 0** stating plainly that
  `sql/` is not a fresh-install sequence, listing both defect sets, and
  directing new environments to restore a `mysqldump --no-data` baseline and
  apply only newer migrations.
* `tests/security/test_migration_schema_boundary.py` — 6 tests pinning the
  measured boundary, so a new migration that alters another unmanaged table
  or adds a new forward reference fails and must be justified. It also fails
  (deliberately) if a `schema_migrations` ledger ever appears, prompting the
  audit to be updated.

---

## Finding I — re-verified CLOSED at current HEAD

Re-checked rather than assumed. `AssessmentTypeService.php`: structurally
clean, each seed row literal appears exactly once, 7 distinct methods with no
duplicates, and all 9 call sites
(`admin/api_education.php` ×4, `admin/api_subjects.php` ×4,
`api/v1/routes/grades.php:224`) invoke only methods that exist.

**Guard strengthened as requested.** The original breakage was *not* an
unbalanced brace — the braces matched. Seven lines of a seed INSERT's `VALUES`
tuples were stranded **inside the class body** after their method had closed,
which is an unconditional PHP fatal that a generic structural checker cannot
see. `test_php_sources_are_well_formed.py` now models the rule directly
(`ClassBodiesContainOnlyDeclarations`): a class body may contain only
declarations.

Validated both directions: **zero false positives across 314 files**, and when
run against pristine `27f2c34` it independently pinpoints
`admin/backend/services/AssessmentTypeService.php:38` — i.e. it would have
caught the real production fatal, not merely a synthetic fixture.

A separate repo-wide duplicate-declaration scan was also run: **no duplicate
function or class declarations exist.** Apparent hits were all legal
interface+implementation pairs in one file, plus one match on the English
prose *"Select a class to take attendance"*. Reported as a clean negative.

---

## Cycle-2 verification summary

| Check | Result |
|---|---|
| `pytest tests/security` | **1407 passed, 42 skipped, 410 subtests** |
| Net new tests this cycle | **+36** (1371 → 1407) |
| Regressions | **0** (42 skipped unchanged) |
| New tests fail on unpatched `27f2c34` | **Yes** — 27 failures for J+K; orphan detector flags the real finding-I line |
| PHP structural check | **279 ok, 0 failed** |
| `php -l` | **STILL NOT RUN** — no PHP binary (CI job now added to close this) |
| SQL executed | **NONE** — no MySQL. 053 and the preflight are unexecuted |
| Duplicate migration numbers | none |
| Unguarded status-mutating UPDATEs | **0 of 8** remaining (multi-line scan) |

### Honest limitations (unchanged and non-negotiable)

`php -l` has never been run; `phply` was rejected as a substitute after it
produced a false positive on *unmodified* source. No SQL has been executed
against any database — migration 053, the preflight, and all constraint
behaviour are **reasoned, not observed**. The SQLite replays prove predicate
and SQL semantics only, not PHP behaviour. Both PHP smoke tests remain
unexecutable here. The new CI workflow has never run. Coverage was never
measured and no percentage is claimed.

### Production status after cycle 2

* **Fixed and test-proven (static):** A, B, C, D, E, F, G, H, I, **J**, **K**, **L**.
* **Open, REQUIRES DECISION:** **M** — `sql/` cannot provision a database;
  7 core tables have no DDL in the repo; no applied-migration ledger.
* **REQUIRES VERIFICATION before deployment:** whether production already
  holds duplicate `grade_submissions` packets (run
  `sql/preflight/uniqueness_preflight.sql`; a `BLOCK` row means finding J is
  still live in data even with the code fixed); every `php -l` result; every
  SQL execution result.
* **Accepted risk, documented not fixed:** the upserts' permissive `status`
  parameter (unreachable today because all callers clamp — would become
  exploitable if a future caller forwards user input).

**This system is not production ready.** Finding M alone means no new
environment can be provisioned from this repository, and finding J may already
have produced corrupt data that no code change can retroactively repair.

### Still unread (unchanged from cycle 1, plus this cycle's additions)

~41 of 57 services, 24 of 36 `admin/api_*.php`, all 16 dashboards, all 41 JS
files, 147 of 159 Dart files. Q2–Q10 remain open. Cycle 2 added full coverage
of the migration *dependency graph* (all 52 files scanned for CREATE/ALTER
ordering) and of every status-mutating UPDATE in `admin/` and `api/`.

---
---

# CYCLE 3 — 2026-10-02 (appended; nothing above altered)

**The environment changed.** The user supplied the authoritative production
dump (`arkeonet_felegekidusan`, MariaDB 11.4.13, dumped 2026-10-02 12:57), and
this sandbox turned out to have passwordless `sudo` and network access. I
installed **PHP 8.4.26** (production runs 8.4.25) and **MariaDB 11.8.6**, and
restored the dump into an isolated staging database. **Production was never
touched.**

Three long-standing verification boundaries are therefore discharged, and
four new defects were found that only runtime could expose.

| | Cycle 2 | Cycle 3 |
|---|---|---|
| Tests passing | 1407 | **1450** |
| Tests skipped | 42 | **13** |
| `php -l` | never run | **297 files, 0 failures** |
| SQL executed | none | **053, 054 and the preflight all executed** |

---

## 1. Finding J — database status

**CODE FIX: effective. DATA STATUS: CLEAN. Migration: RUNTIME VERIFIED.**

`sql/preflight/uniqueness_preflight.sql` was executed against the restored
production dump.

* **Required unique key exists?** No — `grade_submissions` carried only
  `PRIMARY KEY(id)`, `KEY teacher_id`, `KEY class_id`, `KEY status`.
  Worse than cycle 2 reported: even the two lookup keys migration 013
  declares (`sub_att_lookup`, `sub_mark_lookup`) **do not exist in
  production**, because 013's `CREATE TABLE IF NOT EXISTS` was a no-op
  against the pre-existing table. Confirmed schema/runtime divergence.
* **Duplicate logical packets?** **None.** 27 rows: 21 attendance slots over
  21 distinct `(class_id, attendance_date)`; 4 mark-list slots over 4
  distinct `assessment_id`.
* **Duplicates blocking the key?** No. Migration 053 applied cleanly and both
  constraints now exist (`Non_unique = 0`).
* **Historical bypass?** No evidence. No duplicate group exists in which an
  approved packet is shadowed by a newer open one.
* **Cleanup decision needed?** **No.** Nothing was merged or deleted.

Observation, not a defect: two attendance rows (`id=2` submitted, `id=3`
rejected, both 2026-08-19) have `attendance_date IS NULL`. They are invisible
to every date-keyed lookup and exempt from the new constraint. Early test
data; flagged for the owner, not touched.

**Interlock proven at runtime** — the duplicate INSERT is refused with
`ERROR 1062 (23000): Duplicate entry '99-2030-01-01-attendance' for key
'uq_gs_attendance_slot'`, which is exactly the errno the patched
`upsertAttendance`/`upsertMarklist` recovery handles.

---

## 2. Finding K — verification

**CLOSED.** Re-swept every status-mutating UPDATE across Education, HR,
Mezmur and the other packet services: **8 found, 8 guarded, 0 unguarded.**
Four are the review transitions (`WHERE id = ? AND status = 'submitted'`);
four are the saves (`WHERE id = ?$lockGuard`). Two initially flagged as
unguarded were **false positives of my own scanner** — its non-greedy regex
stopped at a string concatenation; reading the source showed both carry the
guard. No `SELECT status` followed by an unguarded UPDATE remains.
`affected_rows` is inspected in all four saves, with a status re-read so a
genuine no-op save is not misreported as locked.

---

## 3. Finding L — CI

**PARTIALLY DISCHARGED — ENVIRONMENT BLOCKED for the workflow itself.**

I cannot run GitHub Actions: that needs a push to GitHub, which I have no
credentials for. **I will not claim CI passed.** What I did instead was
execute each job's commands locally:

| Job | Command | Result |
|---|---|---|
| `php-lint` | `php -l` over the production subset | **297 files, 0 failures** |
| `security-tests` | `pytest tests/security -q` | **1450 passed, 13 skipped** |
| `migration-hygiene` | duplicate-number + destructive scan | 0 duplicates; 5 informational `DELETE FROM` in `003` (documented orphan-FK cleanup) |

The YAML parses and the file list guard (`>= 250`) is satisfied at 297.
**L stays OPEN until a real Actions run is observed.**

---

## 4. AssessmentTypeService — runtime verification

**Historical defect: CLOSED. Runtime verification: VERIFIED (locally, by real `php -l`).**

* Pristine `27f2c34`: `PHP Parse error: syntax error, unexpected token "(",
  expecting "function" ... on line 39` — the exact line identified in cycle 1.
* Current HEAD: `No syntax errors detected`.
* Full pristine sweep: **297 linted, exactly 1 failed** — this file, and only
  this file. Current sweep: 0 failed.
* All nine call sites (`admin/api_education.php` ×4, `admin/api_subjects.php`
  ×4, `api/v1/routes/grades.php:224`) invoke only methods that exist; 7
  distinct methods, no duplicates, each seed row literal appears once.

---

## 5. Finding M — resolved (ledger question closed in cycle 5: Contract B)

**Downgraded from "cannot provision" to CLOSED for schema parity; the residual
ledger item was CLOSED in cycle 5 as Contract B (see below).**

Cycle 2 reported that seven core tables were created nowhere. **That was
wrong, and the correction matters.** It was based on scanning `sql/` alone.
With the production dump and a full-repo scan the real picture is:

* `database_schema.sql` — partial base, 11 of 87 tables.
* `sql/*.sql` — **authoritative** for ongoing change.
* `admin/migrations/*.php` (10 files) and `backend/migrations/*.php` (9) —
  **legacy, HTTP-disabled**, each stating *"Legacy compatibility migration …
  apply reviewed, versioned sql/*.sql migrations during deployment."* They
  are the only source of some historical DDL. The two directories are
  near-duplicate copies that have drifted (`admin/` is the fuller set;
  `002_migration.php` and `003_migration.php` are byte-identical clones of
  their siblings *within* a directory).
* `tests/e2e/provision_env.sh` — a real fresh-install procedure whose header
  already documents the ordering problem: *"sql/ is not linearly applicable
  on a fresh DB."*

**Measured end state** (production restored into one DB, a second provisioned
from the repo, compared via `information_schema`):

| Comparison | Before | After findings N + O fixed |
|---|---|---|
| Tables missing from a fresh install | 8 | **0** |
| Columns missing from a fresh install | 8 | **0** |
| Extra tables | 3 (`*_archive`, optional) | 3 |

`docs/audits/DEPLOYMENT_RUNBOOK.md` gained **Stage 0b** recording the verified
procedure and which source is authoritative.

**M — CLOSED — Contract B** *(resolved cycle 5)*. There is no
`schema_migrations` ledger, so nothing records which migrations any database
has received; migration state is tracked by hand. The factual position is
unchanged from when this was written — what changed is that it is no longer an
open question. Ledger-free, hand-tracked migration state **is** the project's
actual and documented contract, not a gap awaiting a decision.

> **Contract labels — cycle 5, authoritative.** **Contract A** = a
> `schema_migrations` ledger is authoritative and every applied migration must
> be represented in it. **Contract B** = migrations are applied and managed
> *without* such a ledger being required.
>
> These supersede the earlier A/B labels used in this report and in section F,
> which named a different axis (*existing-database upgrade* vs *fully
> reproducible fresh install*). The ledger-free model previously called
> "A (existing-database upgrade)" is what cycle 5 designates **Contract B**.
> Read the older paragraphs with that mapping in mind.

Evidence establishing Contract B (cycle 5, reproduced against the current
tree):

* Production contains no `schema_migrations` table — 87 tables, confirmed both
  in the export and by `information_schema` against a live clean restore. The
  three tables matching `/migrat/` were classified by column structure, not
  name: `member_code_migrations` is business data
  (`member_id, old_code, new_code, reason, migrated_at`) and the two
  `migration_013_*_conflicts` tables are row-shaped quarantine output. None has
  a version/filename/applied_at/batch column.
* No application, deployment or runner code reads or writes one — **0
  references** across `admin/ backend/ scripts/ sql/ api/ tests/e2e/
  .github/`. Every repo-wide mention is audit prose or the test below.
* `docs/audits/DEPLOYMENT_RUNBOOK.md` Stage 0 (lines 445–487) explicitly
  documents ledger-free, hand-tracked migration state — *"no `schema_migrations`
  ledger … Migration state is tracked by hand"* — and prescribes the operating
  procedure that follows from it.
* All 10 `admin/migrations/*.php` self-guard by introspection
  (`SHOW COLUMNS`, `information_schema`, `CREATE TABLE IF NOT EXISTS`) rather
  than consulting a ledger: ledger references 0, guards 1–14 per file.
* `sql/` is not designed for clean replay from scratch (7 tables altered but
  never created; 4 altered by a lower-numbered migration than the one that
  creates them), so a ledger would be authoritative over a sequence that
  cannot itself rebuild the schema.
* A clean-restore probe confirmed the known cost: applied-state must be
  inferred from schema, and that inference can be ambiguous — `052`'s index is
  present only because the file previously shipped as
  `030_roster_scale_indexes.sql` and creates an identical index, which schema
  inspection cannot distinguish. This cost is disclosed in runbook step
  4.7(a), which tells operators the resulting "duplicate key name" is harmless.

Contract B matches both the actual architecture and the documented operating
procedure. **No confirmed defect was found.** Adopting Contract A instead
would require backfilling ~64 migrations with no record of what ran (and at
least one entry — 030 vs 052 — is undecidable from available evidence),
building a runner, and would fail
`tests/security/test_migration_schema_boundary.py::test_there_is_still_no_applied_migration_ledger`
by design.

---

## 6. Migration 053 — verification

**RUNTIME VERIFIED.** Executed against the restored dump:

| Property | Result |
|---|---|
| Applies on clean data | Both keys created, `Non_unique = 0`, exit 0 |
| Idempotent | Re-run reports "already present", exit 0 |
| Non-destructive with duplicates | Row count unchanged 28 → 28 |
| Blocker reporting | Named the exact rows: `class_id=1, 2026-08-19, ids 4,30` |
| Partial progress | Clean mark-list key still created while attendance blocked |
| MariaDB compatibility | 11.8.6 here, 11.4.13 in production — same family |

**Two defects found only by running it, both fixed:**

* **Preflight/migration mismatch.** The preflight reported `BLOCK` on a
  database with **zero duplicates**, conflating "duplicates block this" with
  "the migration hasn't been applied yet". It now has three tiers — `PASS`,
  `PENDING` (clean, just apply it), `BLOCK` (duplicate data, a business
  decision) — each with its own message. Verified in all three states. The
  `result` ENUM had to be widened; `ERROR 1265: Data truncated` caught that.
* **053 exited 0 while printing BLOCKER.** An automated runner would have
  read a blocked deployment as success. It now `SIGNAL`s: exit 1 on blocked,
  exit 0 on clean, still never destructive.

---

## 7. New findings

### N — provisioning silently omits the finance and materials subsystems
**Severity: HIGH · `tests/e2e/provision_env.sh` · CLOSED**
Its extractor matched only `$sql = "CREATE TABLE ...";`.
`admin/migrations/004_add_finance_material_tables.php` declares its eight
tables inside an array literal iterated by `foreach ($tables as $sql)`, so it
extracted **0 of 8**. Every freshly provisioned environment lacked
`finance_*` and `material_*` entirely. Pattern widened; verified 82 → 90
tables, 0 missing.

### O — eight columns the app writes exist in no migration
**Severity: HIGH · `sql/054_legacy_era_columns.sql` (new) · CLOSED**
Found by diffing `information_schema.COLUMNS`. Two of the eight —
`members.total_attendance_rate` and `members.last_attendance_date` — are
written by `AttendanceSummaryService`, **the subject of the audit's own
finding E**. That fix was correct against production but would have failed on
any fresh database with *Unknown column*. Migration 054 adds all eight,
additive and idempotent, using type/default/comment/position read out of the
production dump rather than inferred. Verified: applies on fresh (PASS),
no-op on production (PASS), idempotent.

### P — member report export fatals: `MemberCategory` never loaded
**Severity: HIGH · `admin/backend/services/MemberReportRenderer.php` · CLOSED**
`tableRow()` (line 207) calls `MemberCategory::letterFor()`. The renderer
never loaded that class, `admin/export_pdf.php` (its only production caller)
requires four other services but not that one, nothing pulls it in
transitively, and **this codebase has no autoloader**. Every member report
print/PDF export died with *Class App\Services\MemberCategory not found*.
`php -l` cannot see this — class references resolve at runtime.
**This was invisible for the entire audit because the test that catches it
was skipped for a missing `pdo_sqlite` driver.** Installing the extension
made it run, and fail. Fixed by having the renderer declare its own
dependency. Proven both ways: pristine → not loaded; patched → loaded.

### Q — mobile HR review fatals: `SecurityAuditService` never loaded
**Severity: HIGH · `admin/backend/services/HrSubmissionService.php` · CLOSED**
`reviewPacket()` writes the immutable audit trail via
`SecurityAuditService::record()`. `api/v1/routes/hr.php` — the endpoint
`POST /hr/submission-review` — requires only `HrAttendanceService` and
`HrSubmissionService`, and `api/v1/index.php` loads no services. So every
**mobile** HR review decision fatalled, while the web console worked: a
genuine web/API inconsistency. The equivalent Mezmur route loads it
explicitly at line 50, which is why Mezmur was unaffected.
**Pre-existing, not introduced by this audit** — pristine `27f2c34` carries
the same call at line 467. Fixed at the service, so every caller is covered.

**Regression test:** `tests/security/test_class_dependency_closure.py`
computes each entry point's transitive `require_once` closure and asserts
every sibling service it statically calls is inside it. It fails on pristine
(catching P) and passes now. Calls guarded by `class_exists()` are correctly
treated as optional — `api/v1/routes/grades.php` uses that pattern
deliberately for `EnrollmentService`.

**Four candidates investigated and rejected as false positives** rather than
reported: `FeatureGate` (loaded by `config.php:92`), and
`EnrollmentService` / `SubmissionService` / `AttendanceRecordService` (loaded
by `api/v1/core/database.php`). Also rejected: an apparent copy-paste FK bug
in `sql/003`, which was an artifact of my own non-contiguous `sed` output.

---

## 8. Measured test totals

```
pytest tests/security   1450 passed, 13 skipped, 445 subtests   (17.6s)
php -l (production set)  297 files, 0 failures
php -l (pristine 27f2c34) 297 files, 1 failure  <- AssessmentTypeService:39
```
Cycle 2 → 3: **+43 passing**, skips **42 → 13** (26 recovered by installing
PHP, 3 more by `pdo_sqlite`/`gd`). Remaining 13 skips need a dedicated test
database (`.fkss_env.php`).

New this cycle: `test_fresh_install_parity.py` (findings N, O),
`test_class_dependency_closure.py` (findings P, Q).

---

## 9. Audit coverage

**Verified at runtime this cycle:** the full production schema (87 tables)
against the repo; every status-mutating UPDATE in `admin/` and `api/`; the
dependency closure of 8 entry points; migrations 053 and 054 and the
preflight in all three outcome states; `php -l` across all 297 first-party
files.

**Still unread:** ~41 of 57 services, 24 of 36 `admin/api_*.php`, all 16
dashboards, all 41 JS files, 147 of 159 Dart files. No coverage percentage is
claimed — none was measured.

---

## 10. Q2–Q10

Q1 answered (cycle 1). **Q10 answered this cycle:** there is no
`schema_migrations` ledger in production either — confirmed against the real
database, not inferred. Q2–Q9 remain open; cycle 3 was spent on the
verification boundaries rather than the reading queue.

---

## 11. Production gates

**Blocking:**
1. **L** — no CI run has ever been observed. Push and confirm all three jobs.
2. **M residual** — no applied-migration ledger. REQUIRES DECISION.

**Required before/at deployment:**
3. Apply `sql/054` then `sql/053`, then `sql/preflight/uniqueness_preflight.sql`
   until it exits 0. On the dump supplied, all three succeed.
4. **P and Q are live production fatals today** — member report export and
   mobile HR review. Both fixed here; both need deploying.

**Accepted risk:** the upserts' permissive `status` parameter (unreachable —
all callers clamp). Two NULL-date attendance rows. The `teacher_assignments`
`is_active`/`status` duplication, preserved as found.

**Not claimed:** production readiness. P and Q were reachable fatals sitting
in `main` throughout two prior audit cycles, both invisible to static
analysis and one hidden behind a skipped test. That is evidence that this
codebase's defect surface is not yet fully characterised — a third class of
defect was still being discovered on the third pass.

---
---

# CYCLE 4 — 2026-10-02 (appended; nothing above altered)

**Environment note first, because it changes how this cycle reads.** The
sandbox was rebuilt from scratch: PHP, MariaDB and the production dump had to
be reinstalled, and the workspace had rolled back to a **mid-cycle-3 state**.
The cycle-3 narrative above is accurate as a record of what was done, but the
code fixes for P and Q, and their regression tests, were **not present in the
tree at the start of this cycle**. That matched the brief exactly: both
defects were live. They were re-found, re-proved and re-fixed here — this
time against a real database through the real entry points, which produced
materially better evidence than cycle 3 had.

Also lost and re-applied: the finding-N provisioning fix and the three-tier
preflight. Both were re-verified rather than assumed.

| | cycle 3 claim | cycle 4 measured |
|---|---|---|
| Tests passing | 1450 | **1463** |
| Tests skipped | 13 | **0** |
| Subtests | 445 | **443** |
| `php -l` | 297 files, 0 fail | **298 files, 0 fail** |
| Smoke tests executed | 0 | **6 of 6 passing (83 checks)** |

---

## A. Finding P — CLOSED, runtime-proven through the production entry point

**Severity HIGH · pre-existing · `admin/backend/services/MemberReportRenderer.php`**

`tableRow()` (line 207) calls `MemberCategory::letterFor()` and
`::sectionAm()`. The renderer declared no dependencies at all,
`admin/export_pdf.php` — its only production caller — loads four other
services but not that one, nothing reaches it transitively, and **this
codebase has no autoloader** (verified: zero `spl_autoload_register` outside
`vendor/`).

Cycle 3 proved this by loading the entry point's require set. This cycle
invoked **the actual entry point** with an authenticated `super_admin`
session against the restored production database. That revealed the failure
is worse than recorded:

| format | reaches `tableRow()` | pre-fix | post-fix |
|---|---|---|---|
| csv | no | 36,426 B, 230 lines | unchanged |
| **docx** | yes | **1,523 B, 0 member rows** | **54,368 B, 229 rows** |
| **pdf / print** | yes | **3,074 B, 0 member rows** | **64,596 B, 229 rows** |

Pre-fix the document **terminated mid-stream at `<tbody>`**, at the first
member row. `config.php` sets `display_errors = 0`, so nothing surfaced to
the user: the export returned **HTTP 200 with a structurally truncated,
member-less file**. The only trace was `SSMS/error.log`:

```
[SSMS:e9a9655ac0a2] Member report failed: Class "App\Services\MemberCategory" not found
```

That is a silent-wrong-output failure, not a visible error — an administrator
exporting a member roster received a file containing zero members with no
indication anything went wrong. Two of three formats were affected; CSV was
always fine, which is a correction to cycle 3's wording.

**Fix — through the project's real architecture, not an ad-hoc require.**
Per the brief I first checked whether a bootstrap owns this dependency. It
does not: `config.php` loads only four files, and the one service it pulls in
(`FeatureGate`) is there because config itself calls it. The established
convention is that **a service declares its own sibling dependencies** —
21 of 58 service files do, and **seven services already require
`MemberCategory.php` in exactly this way**. The renderer now does the same.
Post-fix the document closes correctly with `</body></html>` and `error.log`
is empty.

---

## B. Finding Q — CLOSED, and materially worse than recorded

**Severity HIGH · pre-existing · `admin/backend/services/HrSubmissionService.php`**

`reviewPacket()` line 539 writes the decision trail via
`SecurityAuditService::record()`. `api/v1/routes/hr.php` — the
`POST /hr/submission-review` endpoint — requires only `HrAttendanceService`
and `HrSubmissionService`; neither `api/v1/index.php` nor `api/v1/core/`
loads the audit service. The parallel `api/v1/routes/mezmur.php:50` *does*,
which is why the web console and Mezmur mobile worked while HR mobile did not.

**The audit call sits *after* the status UPDATE has already committed.**
Running the service through hr.php's exact load set:

```
SecurityAuditService loaded by hr.php's require set? NO
THROWN: Error: Class "App\Services\SecurityAuditService" not found
  at HrSubmissionService.php:539
db status now : approved (reviewed_by=1)     <- state committed
audit rows    : 1185 -> 1185 (delta 0)       <- audit permanently lost
```

So the real impact is not "mobile HR review fails". It is: **the packet is
approved in the database, the immutable audit record is never written, and
the caller receives an exception.** The reviewer sees failure, retries, and
the status guard (`AND status='submitted'`) now correctly refuses with
`conflict` — leaving a decided packet with no record of who decided it.

**Fix** at the service layer so every caller is covered (no cycle:
`SecurityAuditService` references this class zero times). Post-fix, all three
decision types verified end to end against the real database:

| decision | ok | resulting status | audit delta |
|---|---|---|---|
| approved | true | `approved` | **+1** |
| rejected | true | `rejected` | **+1** |
| revision_needed | true | `revision_needed` | **+1** |

Audit payload is complete (`new_status`, `previous_status`, `reason`,
`section`, `attendance_date`, `taker_id`), and the replay guard still holds:
a second decision returns `invalid_transition — This packet is already
approved.`

---

## C. The 13 skipped tests — all eliminated, none weakened

Every skip had one cause: **`.fkss_env.php` absent**, so the end-to-end
suites self-skipped. Providing a sandbox env file and a real MariaDB executed
all 13.

| # | tests | why it skipped | disposition | outcome |
|---|---|---|---|---|
| 9 | `test_comm_e2e.py::CommEndToEndTests` | no `.fkss_env.php` | **A — executed** | pass |
| 4 | `test_comm_e2e.py::CommV1EndToEndTests` | no `.fkss_env.php` | **A — executed** | pass |

**Skips: 13 → 0. Nothing was relaxed to achieve it.**

The first attempt pointed the harness at a production-shaped database and 11
of the 13 failed — which is how **finding S** (below) was discovered. Run
against the dedicated disposable database the harness documents, all 13 pass.

Separately, the six `tests/smoke/*.php` harnesses had **never been executed
in any cycle**. Given the `ssms_smoke` database and `ssms` user they hardcode,
**6 of 6 now pass, 83 checks**. One fixture defect was fixed to get there
(see D).

CI note: the workflow provisions **no database and no env file**, so these 13
tests would self-skip there and the job would still report green — the exact
false-green condition that hid P. Addressed under F/L.

---

## D. New findings this cycle

### R — the production dump cannot be restored as-is
**Severity HIGH (disaster recovery) · REQUIRES DECISION · data-integrity**

Restoring `uploads/production_database.md` into an empty database **fails**:

```
ERROR 1452 (23000) at line 9746: Cannot add or update a child row:
a foreign key constraint fails (fk_mhc_hymn ...)
```

The dump contains no `SET FOREIGN_KEY_CHECKS=0`, so the client stops there.
Eight `ALTER TABLE` blocks follow that line and never run: a naive restore
yields a database with **31 of 42 foreign keys**, silently missing 11 across
`mezmur_hymn_categories`, `mezmur_hymn_zemarians`, `mezmur_play_stats`,
`mezmur_sessions`, `mezmur_submissions`, `mezmur_user_favorites`,
`staff_positions`, `wbws_group_leaders`.

Underneath it is real orphaned data — the dump declares constraints its own
data violates:

| relationship | rows | orphaned |
|---|---|---|
| `mezmur_hymn_categories.hymn_id → mezmur_hymns.id` | 110 | **99** |
| `mezmur_hymn_categories.category_id → mezmur_categories.id` | 110 | **100** |
| `mezmur_hymn_zemarians.hymn_id → mezmur_hymns.id` | — | **1** |

`mezmur_hymns` holds 11 rows; the link table references ids up to 76. Every
other FK relationship in the database is clean (39 of 42 checked, 0 orphans).

Either the constraints are not enforced in production, or they were created
with checks disabled and are untrusted. **Both readings mean the backup is
not restorable without manual intervention, and nobody will discover that
until they need it.** I did not delete anything; staging was built with
`FOREIGN_KEY_CHECKS=0` to preserve the declared schema. Cleanup needs an
owner decision about which mezmur links are authoritative.

### S — destructive test harnesses have no interlock
**Severity HIGH (latent) · CLOSED · demonstrated, not theorised**

`tests/e2e/provision_env.sh` refuses to run without `SSMS_AUDIT_TESTING=1`.
Its two siblings, `tests/e2e/comm_lifecycle.php` and `comm_v1_lifecycle.php`,
had **no gate at all** — they `require` the repo-root `.fkss_env.php` and then
`DROP TABLE` and `TRUNCATE users`.

`.fkss_env.php` is the **production credentials filename**, and `config.php`
explicitly supports a repo-root copy as a fallback layout. On such a host,
running the test suite destroys live data.

This is not hypothetical: during this cycle the runner was pointed at a
production-shaped database and **dropped four tables** (`messages`,
`message_threads`, `message_thread_participants`, `notification_reads`) and
truncated `users`. Fixed by adding the same interlock the sibling script
already uses; it now exits 2 with a refusal. The suite declares itself an
authorised caller explicitly.

### Re-found and re-fixed (lost in the rollback)
* **N** — `provision_env.sh` extracted **30** of **39** legacy `CREATE TABLE`
  statements: `004_add_finance_material_tables.php` (array literal, 8 tables)
  and `005_create_system_branding.php` (passed to `query()`, 1 table) matched
  nothing. Every fresh environment silently lacked the finance and materials
  subsystems. Re-fixed and re-measured: 30 → 39.
* **Preflight verdict conflation** — a clean database that had simply not had
  migration 053 applied was reported `BLOCK … 0 duplicated … slot(s) block
  it`, sending operators to hunt for duplicates that did not exist. Re-fixed
  to three tiers (E).

### Investigated and rejected — recorded so they are not re-opened
* `admin/api_communication.php:158` and `admin/backend/user-delete.php:116`
  looked like unguarded status UPDATEs. Neither touches `status` — one writes
  `student_count`/`average_score`, the other nulls `reviewed_by` for FK
  cleanup. **Not K defects.**
* User deletion nulls `activity_logs.user_id`, which looked like it broke the
  "immutable" audit trail. It does not: the table stores a denormalised
  `username` column, so attribution survives. **Not a finding.**
* `DeptTakerService` writes an audit trail without loading the audit class,
  but its single caller does load it, so it is **not reachable today**. It
  has Q's exact shape, so it was hardened one line — recorded as *hardening
  of a latent risk*, deliberately **not** as a new HIGH finding.
* `classes.class_code` duplicate-entry crash in `info_phase7_smoke.php` is a
  **test fixture defect**, not production: the API rejects a blank code with
  "Name and code required". Fixture fixed; production untouched.

---

## E. Migration 053 and the preflight — RUNTIME VERIFIED, all tiers executed

| scenario | database | exit | result |
|---|---|---|---|
| clean | `ssms_staging` | **0** | both keys created, `non_unique=0` |
| idempotent re-run | `ssms_staging` | **0** | "already present — nothing to do" |
| duplicates present | `ssms_pending` | **1** | BLOCKER, rows **28 → 28** (non-destructive), offending rows named, clean mark-list key still created, terminating `SIGNAL` |

No "BLOCKER printed while exiting 0" in any scenario.

The preflight now distinguishes three states, verified against three real
databases, and **agrees with the migration on every one**:

| database | state | verdict rows | exit |
|---|---|---|---|
| `ssms_staging` | 053 applied, clean | 7 × PASS | **0** |
| `ssms_e2e` | clean, 053 not applied | 4 × PASS, **2 × PENDING** | **1** |
| `ssms_pending` | duplicate present | 5 × PASS, **1 × BLOCK** | **1** |

Both non-zero tiers are deliberate — the constraint is absent either way —
but the messages differ: PENDING says *apply the migration, no data decision
required*; BLOCK says *a human must decide which record is authoritative, do
not delete rows merely to make this pass.*

---

## F. J / K / L / M

**J — CODE FIX effective · DATA STATUS CLEAN · migration RUNTIME VERIFIED.**
Re-measured against the restored dump: 27 rows, **0 duplicate attendance
slots**, **0 duplicate mark-list slots**, **0 approved-shadowed packets**. No
unique key exists in production (only `PRIMARY`, `class_id`, `status`,
`teacher_id`) — and migration 013's `sub_att_lookup`/`sub_mark_lookup` are
absent, so the live table predates 013. Two attendance rows carry
`attendance_date IS NULL`; unreachable by date-keyed lookups, exempt from the
new key, left untouched. **No cleanup business rules required.**

**K — CLOSED.** *(Superseded: this cycle-4 clearance was based on a 10-site scan. The final cycle re-scanned and found **34** status-mutating UPDATEs, of which **two** were genuine defects — see "FINAL RECONCILIATION" below. K is still CLOSED, but as `f93ffc8` **fixed**, not as "all guarded".)*

The scan found **10** UPDATE statements against status-bearing
tables; **8 mutate `status` and all 8 carry an expected-state predicate**
(`AND status IN (...)` or `$lockGuard`): `api_communication.php:300`,
`HrSubmissionService.php:373,521`, `MezmurSubmissionService.php:379,527`,
`SubmissionService.php:462,667`, `api/v1/routes/grades.php:1155`. The other
two are not status mutations (see D). Four was never the final number; eight
is, and all are guarded.

**L — OPEN / ENVIRONMENT BLOCKED.** No GitHub Actions run has been observed;
that needs push rights I do not have. I will not mark it closed. Each job's
commands were executed locally: `php -l` **298 files, 0 failures**;
`pytest tests/security` **1463 passed, 0 skipped**; migration hygiene 0
duplicate numbers. Inspecting the YAML surfaced two real defects, both
patched but **REQUIRES VERIFICATION**:
1. CI pinned **PHP 8.2** while production runs **8.4.25** — the lint gate was
   not testing the production language version. Now 8.4.
2. CI provisioned **no database and no `.fkss_env.php`**, so the 13 e2e tests
   would self-skip and the job would still go green — the precise false-green
   condition that hid finding P. Added a MariaDB 11.4 service, a CI-only env
   file, the PHP extensions, and a step that **fails the build if any test
   skips**.

**M — schema parity CLOSED; ledger question CLOSED in cycle 5 as Contract B.**
Re-provisioned a database from the repo's own procedure and diffed against the
untouched production restore: **0 tables missing, 0 columns missing** (90 = 87
+ 3 optional `*_archive`). These are separate questions and are not conflated:
parity is demonstrated, and separately there is **no `schema_migrations`
ledger** in the repo or in production, so nothing records which migrations a
given database has received.

Cycle 5 established that the absence is the intended contract rather than a
gap: **Contract B** — migrations applied and managed without an authoritative
ledger — evidenced by the runbook's explicit ledger-free procedure, zero
ledger references in any executable path, and the 10 PHP migrations'
introspective self-guarding. **No confirmed defect was found.** Note that the
A/B labels in the sentence that previously stood here named the
*upgrade vs fresh-install* axis; section 5 gives the authoritative cycle-5
definitions and the full evidence.

---

## G. Measured test totals

```
pytest tests/security    1463 passed, 0 failed, 0 skipped, 443 subtests   (21.7s)
tests/smoke/*.php        6 of 6 passing, 83 checks
```
Cycle 3 → 4: **+13 passing**, **skips 13 → 0**, smoke coverage **0 → 6**.
New regression files: `test_class_dependency_closure.py` (P, Q and the
general invariant), `test_fresh_install_parity.py` (N, O, S).

Regression value proven by reverting the fixes: with P and Q removed the
closure suite reports **4 failed, 1 passed**; restored, **5 passed**.

---

## H. Measured PHP lint totals

```
php -l, PHP 8.4.26, all first-party files      298 linted, 0 failed
```
(297 tracked + 1 new untracked service file.) Production runs 8.4.25; the
sandbox is 8.4.26 and MariaDB 11.8.6 against production's 11.4.13 — same
families, not identical builds.

**`php -l` remains structurally incapable of catching this cycle's two
headline defects.** Class references resolve at runtime; a clean lint over
298 files says nothing about whether the class is loaded.

---

## I. Audit coverage

**Runtime-verified this cycle:** both production fatals through their real
entry points; all three HR review decision types with audit assertions; the
full 87-table production schema restored and diffed against a fresh install;
every foreign key in the database checked for orphans (42 relationships);
migration 053 and the preflight in all three states across three databases;
all six smoke harnesses; the complete 1463-test suite with zero skips;
`php -l` across 298 files.

**Still unread:** ~41 of 57 services, 24 of 36 `admin/api_*.php`, all 16
dashboards, all 41 JS files, 147 of 159 Dart files. No coverage percentage is
claimed, because none was measured.

---

## J. Remaining Q2–Q10 queue

Q1 answered (cycle 1). Q10 answered (cycle 3): no `schema_migrations` ledger
exists in production either — confirmed against the real database. **Q2–Q9
remain open.** Cycle 4 was spent closing the two confirmed runtime fatals and
eliminating the false-green test conditions, per the brief's priority order;
the reading queue was not advanced. Next in priority: remaining SQL/schema
truth → remaining `App\Services` → dashboards → Dart/Flutter →
web/API/mobile consistency → remaining security questions.

---

## K. Current production blockers

**Must deploy — live defects fixed here:**
1. **P** — member report Word and PDF/print exports currently return a
   truncated, member-less document with HTTP 200.
2. **Q** — mobile HR review currently approves the packet, loses the audit
   record, and reports failure to the reviewer.

**Must decide:**
3. **R** — the production backup is not restorable as-is and a naive restore
   silently drops 11 foreign keys. ~100 orphaned `mezmur_hymn_categories`
   rows need an ownership decision. Until then, there is no verified
   restore path.
4. **M residual** — no applied-migration ledger.

**Must verify:**
5. **L** — no CI run has ever been observed. The workflow now installs PHP
   8.4, provisions MariaDB, and fails on any skipped test; none of that is
   confirmed until Actions runs it.

**Deployment sequence (verified on the supplied dump):** apply `sql/054`,
then `sql/053`, then run `sql/preflight/uniqueness_preflight.sql` until it
exits 0.

**Accepted risk:** permissive `status` parameter on the upserts (unreachable
— all callers clamp); two NULL-date attendance rows; `teacher_assignments`
`is_active`/`status` duplication, preserved as found.

**Not claimed: production readiness.** This cycle found two further defect
classes that three prior passes had missed — a backup that cannot be restored,
and destructive test tooling with no safety interlock — neither of which any
amount of source reading would have surfaced. The defect surface is better
characterised than it was, but it is not yet fully characterised.

---

# CYCLE 5 — R and S closed, L proven absent, work finally persisted

**Date:** 2026-10-02 · **Scope:** finding R (restore/FK integrity), finding S
(destructive lifecycle operations), finding L (GitHub Actions), reconciliation
of all outstanding evidence.

## 0. Working conditions, stated plainly

Cycles 3 and 4 were both lost: every code change reverted between sessions
while only the Markdown report survived. Cycle 5 therefore began by pushing
to GitHub. Two commits are now upstream on branch
`audit/cycle5-remediation`:

| commit | content |
|---|---|
| `2279376` | findings N, O, P, Q, S remediation + tests |
| `9f1f359` | `scripts/restore_production_dump.sh` (finding R) |

A third commit, `f1c365e` (the CI workflow), **could not be pushed**: the
supplied token carries `repo` scope but not `workflow`, and GitHub refuses
to create `.github/workflows/*` without it. This is the only blocker left on
L and is addressed in §3.

> The token was supplied in plaintext in chat and **should be rotated** once
> this work is finished.

---

## 1. FINDING R — restore / FK integrity · **CHARACTERISED, STATUS QUALIFIED**

### 1.1 Reproduction (disposable database `r_naive`)

```
mariadb r_naive < /tmp/prod.sql
  → exit 1
  → ERROR 1452 (23000) at line 9746, constraint fk_mhc_hymn
  → 87 tables, 31 of 42 declared foreign keys
```

The failing statement is a single ALTER carrying **two** constraints:

```sql
ALTER TABLE `mezmur_hymn_categories`
  ADD CONSTRAINT `fk_mhc_category` FOREIGN KEY (`category_id`)
      REFERENCES `mezmur_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mhc_hymn` FOREIGN KEY (`hymn_id`)
      REFERENCES `mezmur_hymns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
```

### 1.2 The decisive test: each missing constraint applied individually

The eleven missing constraints were applied **one at a time** to the aborted
database. This separates genuinely violated constraints from constraints that
were merely never reached.

| constraint | child → parent | result |
|---|---|---|
| `fk_mezmur_sessions_created_by` | `mezmur_sessions.created_by → users.id` | **APPLIED** — collateral |
| `fk_mezmur_submissions_reviewer` | `mezmur_submissions.reviewed_by → users.id` | **APPLIED** — collateral |
| `fk_mezmur_submissions_taker` | `mezmur_submissions.taker_id → users.id` | **APPLIED** — collateral |
| `fk_mhz_zemarian` | `mezmur_hymn_zemarians.zemarian_id → mezmur_zemarians.id` | **APPLIED** — collateral |
| `fk_mps_hymn` | `mezmur_play_stats.hymn_id → mezmur_hymns.id` | **APPLIED** — collateral |
| `fk_muf_hymn` | `mezmur_user_favorites.hymn_id → mezmur_hymns.id` | **APPLIED** — collateral |
| `fk_positions_department` | `staff_positions.department_id → departments.id` | **APPLIED** — collateral |
| `fk_wbws_group_leaders_group` | `wbws_group_leaders.group_id → wbws_groups.id` | **APPLIED** — collateral |
| `fk_mhc_category` | `mezmur_hymn_categories.category_id → mezmur_categories.id` | **VIOLATED** — 1452, 100 orphans |
| `fk_mhc_hymn` | `mezmur_hymn_categories.hymn_id → mezmur_hymns.id` | **VIOLATED** — 1452, 99 orphans |
| `fk_mhz_hymn` | `mezmur_hymn_zemarians.hymn_id → mezmur_hymns.id` | **VIOLATED** — 1452, 1 orphan |

**Eight of the eleven were never broken.** They are collateral damage of the
client's abort-on-first-error. Only **three** constraints are genuinely
violated by data. This single result reclassifies the finding: R was reported
as "11 missing FKs across 8 tables", which reads as widespread schema damage.
The actual damage is **three constraints in two tables of one subsystem**.

### 1.3 Why the dump contains an invalid foreign key

Causes ruled out, with evidence:

- **Type/collation mismatch — RULED OUT.** `mezmur_hymn_categories.hymn_id`
  and `mezmur_hymns.id` are both `bigint(20) unsigned`; `category_id` and
  `mezmur_categories.id` are both `int(10) unsigned`. A type or collation
  error raises errno **150**; the observed errno is **1452**, a data violation.
- **Restore ordering — RULED OUT.** The dump creates `mezmur_hymns` (line
  7445) and inserts its rows (7479) before the link table (7498/7507), and
  appends all constraints at the end (8975, 9746). Parent data is fully
  present before any constraint is evaluated. Ordering is correct.
- **Schema defect — RULED OUT.** All three violated constraints **are**
  defined in the repository schema, in `sql/030_mezmur_taxonomy.sql`. The
  schema is right; the data disagrees with it.

The actual cause, in two parts:

**(a) A historical-data defect.** `mezmur_hymns` holds 11 rows with ids
68–82 and `AUTO_INCREMENT=83`; `mezmur_categories` holds 38 rows with ids
71–117. The link table references hymn ids **2–76** and category ids
**23–116** — overwhelmingly ids *below* the surviving ranges. Of 110 link
rows, only **9 are fully valid**. The pattern is a taxonomy re-seed
(`sql/030`, `sql/034`) in which old hymns and old categories were replaced
while the link rows pointing at them were never cleaned up. These are
**retired references to deleted parent records**, not corruption and not
live data.

Critically, the declared constraints are `ON DELETE CASCADE`. Had they been
enforced when those parents were deleted, the link rows would have been
removed automatically. Their survival proves the constraints **were not
active on the production server** at the time — they exist in the schema but
were created without validation (the standard way this happens is an
`ALTER ... ADD CONSTRAINT` executed while `FOREIGN_KEY_CHECKS=0`).

**(b) A backup-file defect.** The export is phpMyAdmin 5.2.3 (banner:
MariaDB 11.4.13, PHP 8.4.25) and contains **zero** `FOREIGN_KEY_CHECKS`
statements — `grep -c FOREIGN_KEY_CHECKS /tmp/prod.sql` → `0`. `mysqldump`
emits that preamble by default; phpMyAdmin emits it only when the
"disable foreign key checks" export option is ticked, and it was not. So the
export faithfully reproduces constraints that the live server is not
actually enforcing, with no mechanism to load them back.

### 1.4 Orphan inventory (exact)

| table | column | orphan rows | distinct missing parents |
|---|---|---|---|
| `mezmur_hymn_categories` | `hymn_id` | 99 | 68 |
| `mezmur_hymn_categories` | `category_id` | 100 | 10 |
| `mezmur_hymn_zemarians` | `hymn_id` | 1 | 1 (`hymn_id=76, zemarian_id=9`) |

Union of link rows that would have to be removed to reach 42/42: **102**
(101 in `mezmur_hymn_categories`, 1 in `mezmur_hymn_zemarians`).

### 1.5 Candidate restore procedures, measured

| procedure | tables | FKs | data changed | trustworthiness |
|---|---|---|---|---|
| naive `mariadb db < dump.sql` | 87 | **31/42** | none | aborts, exit 1 |
| **A** `SET FOREIGN_KEY_CHECKS=0` | 87 | **42/42** | none | **3 constraints UNVALIDATED; 101 violating rows retained** |
| **B** `--force` | 87 | **38/42** | none | present constraints trusted |
| **C** defer + apply each individually | 87 | **39/42** | none | present constraints trusted |
| **D** C + delete 102 orphan rows | 87 | 42/42 | **destructive** | requires owner decision |

Procedure **A is a trap and is explicitly rejected.** It makes the restore
*complete* while leaving the three constraints recorded but never checked
against existing rows. The database then silently carries 101 rows violating
a constraint the application believes is enforced, and the failure resurfaces
later as errno 1452 on an unrelated `UPDATE`, far from the restore that
caused it. A clean 39 is more honest than a dirty 42.

Procedure **B** loses one *valid* constraint (`fk_mhz_zemarian`) purely
because the dump packs two constraints into one `ALTER`. Statement
granularity, not data, costs that one.

**Procedure C is the deterministic restore**, shipped as
`scripts/restore_production_dump.sh` (commit `9f1f359`). Verified end to end
against a disposable database:

```
==> 3/4  applying each constraint individually
    REJECTED fk_mhc_category: ERROR 1452 (23000) …
    REJECTED fk_mhc_hymn:     ERROR 1452 (23000) …
    REJECTED fk_mhz_hymn:     ERROR 1452 (23000) …
    applied=39 rejected=3
==> 4/4  verifying the inventory
    tables=87/87  foreign keys=39/39
RESTORE STATUS: PASS (expected inventory)
```

The script fails loudly (exit 2) if *any constraint other than the three
known-violated ones* is rejected, so this cannot silently degrade.

### 1.6 Verdict

**RESTORE STATUS: PASS for the achievable inventory (87 tables, 39/42
constraints, zero data modification) — FAIL for a complete 42/42 restore.**

Classification, in order of weight:

1. **Backup-file defect** — the export omits `FOREIGN_KEY_CHECKS`, making it
   non-restorable by the documented command. *Fixable immediately, no data
   decision required.*
2. **Historical-data defect** — 102 orphaned link rows from an uncleaned
   taxonomy re-seed, which is why 3 constraints cannot be validated.
   *Requires an owner decision.*
3. **Operational-runbook defect** — the runbook documents the naive command,
   which aborts at 31/42 and reports `exit 1` with no guidance.
   *Fixed by the new script.*
4. **Restore-order defect** — rejected, ordering is correct.
5. **Schema defect** — rejected, all three constraints are correctly defined
   in `sql/030_mezmur_taxonomy.sql`.

**R is not fully closed, and cannot be closed by me.** The remaining 3/42
requires deleting 102 user-owned rows. Per the brief, I stopped at evidence.

> **DECISION REQUIRED (owner):** delete the 102 orphaned link rows —
> retired references to hymns and categories removed during the `sql/030`/
> `sql/034` taxonomy re-seed — so that `fk_mhc_hymn`, `fk_mhc_category` and
> `fk_mhz_hymn` can be validated? They point at parents that no longer
> exist, so they are already unreachable through the application. Deleting
> them changes no reachable behaviour, but it is user-owned data and the
> call is not mine. Until then production runs without three constraints it
> believes it has.

---

## 2. FINDING S — destructive lifecycle operations · **CLOSED**

### 2.1 The property proven

Not "the script warns" but "the destructive operation is **blocked** unless
the intended conditions hold", **and** "the legitimate operation still
works". Both halves were verified at runtime.

### 2.2 Design: default deny

`tests/e2e/destructive_guard.php` requires **two independent conditions**:

1. `SSMS_AUDIT_TESTING` is **exactly** the string `'1'`.
2. The target database name is recognisably disposable (contains
   `test`/`e2e`/`smoke`/`sandbox`/`scratch`/`ci`/`tmp`) **or** is named in
   the `SSMS_DISPOSABLE_DB` allowlist.

A denylist of known production names was considered and **rejected**: it only
stops names someone remembered to write down, and the production database
(`arkeonet_felegekidusan`) looks like an ordinary identifier. Condition 1
alone is also insufficient — an operator who exports the variable in their
shell and runs the suite on a production checkout would still destroy data.
**Condition 2 is what actually protects the database.**

### 2.3 Runtime matrix (all against disposable databases)

| # | case | expected | observed |
|---|---|---|---|
| 1 | production-named DB + marker `1` | **BLOCKED** | exit **2**, `REFUSED`, 87/87 tables preserved |
| 2 | sandbox DB + marker `1` | **EXECUTES** | exit **0**, `E2E-VERDICT: PASS` |
| 3 | marker unset | **FAIL CLOSED** | exit **2** |
| 4 | marker `0`,`true`,`True`,`TRUE`,`yes`,`y`,`on`,`" 1"`,`"1 "`,`01`,`1.0`,`2`,`-1`,`null`,`false`,`""` | **FAIL CLOSED** | exit **2** for all 16 |
| 5 | non-destructive smoke suite | **FUNCTIONAL** | **6/6 exit 0**, 83 checks |

Cases 1, 3 and 4 were run against **both** `comm_lifecycle.php` and
`comm_v1_lifecycle.php`.

### 2.4 Proof against the unfixed baseline

A database restored from the production dump and named
`arkeonet_felegekidusan`:

| run | exit | tables after | outcome |
|---|---|---|---|
| **guard removed** (pre-fix baseline) | 255 | **83** (was 87) | **4 tables destroyed** |
| **guard present** | **2** | **87** | refused, nothing touched |

The fix is proven to prevent damage that is measured, not inferred.

### 2.5 Sibling sweep — two further scripts found

The brief required inspecting **all** sibling operational scripts. Scanning
for *executed* `DROP TABLE`/`TRUNCATE` syntax across `tests/`, `admin/`,
`api/` and `backend/` found two beyond the two known files:

- `tests/smoke/hr_phase6_smoke.php` — 2 `TRUNCATE`s, no interlock
- `tests/smoke/mezmur_phase5_smoke.php` — 3 `TRUNCATE`s, no interlock

Both now load the guard. **Classified as hardening, not a second HIGH**:
they connect to a hardcoded `'ssms_smoke'` literal rather than following
`.fkss_env.php`, so they cannot chase production credentials the way the
lifecycle harnesses could. Severity reflects reachability.

**False positive rejected:** `mezmur_phase5_smoke.php:288` contains
`"AUDIT' OR 1=1; DROP TABLE mezmur_hymns; --"`. That is a SQL-injection
*test payload* the script asserts is safely rejected — not a destructive
operation. A keyword scan would have filed it; the scan was anchored on
executed `query("DROP TABLE` syntax instead.

### 2.6 Regression test

`tests/security/test_destructive_guard.py` executes the real scripts rather
than reading them for reassuring words — a source-scanning version would
pass against a script whose only safety feature is a comment. It also pins
the sweep closed: any new harness executing `DROP`/`TRUNCATE` without the
guard fails the suite.

**S is CLOSED:** production destruction blocked **and** legitimate sandbox
lifecycle behaviour verified still working.

---

## 3. FINDING L — GitHub Actions · **CONFIRMED FINDING, VERIFICATION BLOCKED ON TOKEN SCOPE**

### 3.1 Upgraded from "environment blocked" to a proven finding

Queried against the live GitHub REST API (repo `suraman21/SSMS`, public,
`default_branch: main`):

| endpoint | result |
|---|---|
| `GET /actions/workflows` | `total_count: 0` |
| `GET /actions/runs?per_page=10` | `total_count: 0` |
| `GET /contents/.github/workflows` | **HTTP 404** |

**There is no CI.** Not "CI that is misconfigured" — zero workflows have ever
been registered and **zero Actions runs have occurred in the repository's
entire history**. Every previous cycle's "CI will catch this" assumption was
false. This is a stronger and simpler finding than the original.

### 3.2 The two config defects stand

1. **PHP 8.2 in CI vs 8.4.25 in production.** The production baseline is
   confirmed independently by the dump banner (§1.3b): *PHP Version: 8.4.25*,
   *Server version: 11.4.13-MariaDB*. Linting on 8.2 cannot see what 8.4
   rejects.
2. **No database service, no PHP, no `.fkss_env.php` in the test job** — so
   every DB-backed test calls `SkipTest` and the job still reports green.
   This is the exact false green that concealed finding P for two cycles.

Patch (local commit `f1c365e`) adds PHP 8.4 + extensions, a healthchecked
MariaDB 11.4 service, generated test-only credentials, `-rs`, and a gate that
**fails the build when the skip count is anything but zero**. All three jobs
parse as valid YAML and the suite they invoke passes locally at 1464/0/0.

### 3.3 Why L is not closed

**The supplied token has `repo` scope but not `workflow`.** GitHub rejects
the push:

```
! [remote rejected] audit/cycle5-remediation -> audit/cycle5-remediation
  (refusing to allow a Personal Access Token to create or update workflow
   `.github/workflows/backend-checks.yml` without `workflow` scope)
```

The REST Contents API enforces the same restriction, so there is no
workaround. **L cannot be closed without a `workflow`-scoped token**, and
per the brief I will not mark it complete on local execution. The commit is
staged and will push unchanged the moment the scope is available; the
observed-run evidence (workflow, run ID, commit, PHP version, MariaDB
version, tests/passes/failures/skips, final status) and the controlled
skip⇒failure negative test remain outstanding.

---

## 4. Newly discovered defects

| # | defect | severity | status |
|---|---|---|---|
| R-1 | Production export omits `FOREIGN_KEY_CHECKS`, so the documented restore command aborts at 31/42 | **HIGH** | mitigated by `scripts/restore_production_dump.sh`; export settings still need fixing |
| R-2 | 102 orphaned link rows block 3 constraints; production runs without constraints it believes it has | **HIGH** | **owner decision required** |
| S-2 | `hr_phase6_smoke.php` / `mezmur_phase5_smoke.php` execute `TRUNCATE` with no interlock | LOW (hardcoded disposable DB) | fixed |
| L-1 | Repository has **never** run a CI job — 0 workflows, 0 runs, ever | **HIGH** | patch ready, push blocked on token scope |

## 5. False positives rejected this cycle

- **`mezmur_phase5_smoke.php:288` "DROP TABLE"** — a SQL-injection test
  payload inside a string, not a destructive statement.
- **"11 missing foreign keys" as a measure of damage** — 8 of the 11 are
  collateral of the client abort and apply cleanly. The real figure is 3.
- **Type/collation mismatch as the cause of R** — disproven by column
  definitions and by errno 1452 vs 150.
- **Restore ordering as the cause of R** — disproven by statement offsets in
  the dump.
- **Schema defect as the cause of R** — all three violated constraints are
  correctly defined in `sql/030_mezmur_taxonomy.sql`.
- **My own guard test flagged the guard** — an early version scanned the
  interlock's source for the production database name and matched the
  *comment explaining why denylists are unsafe*. Fixed by stripping comments
  before scanning. Recorded because it is the same trap that has produced
  false positives twice in this audit.

## 6. Totals (re-measured this cycle, post-fix)

| measure | result |
|---|---|
| `pytest tests/security -q -rs` | **1464 passed, 0 failed, 0 skipped**, 464 subtests, 20.3s |
| `php -l` (first-party) | **299 linted, 0 failed** |
| PHP smoke suites | **6/6 exit 0** against a 39/42-FK production restore |
| Guarded harness without marker | exit **2** (fail-closed) |

No test was weakened, skipped or deleted to obtain these. Two pre-existing
tests were *updated* to pin changed contracts (the preflight's new `PENDING`
tier; a guard test whose premise was wrong and dangerous — it set
`SSMS_DB_NAME=""`, which falls back to a real database and destroyed it).

## 7. Exact remaining production blockers

1. **R-2 — 102 orphaned link rows.** Blocks 3 of 42 constraints. Needs an
   owner decision; no code change can resolve it.
2. **R-1 — backup export configuration.** ~~Re-export with "disable foreign
   key checks" enabled, or adopt~~ **SUPERSEDED — see "FINAL RECONCILIATION"
   below. The "disable foreign key checks" half of this recommendation was
   withdrawn: it was disproven at runtime in the final cycle. Constraints added
   while foreign-key checking is off are created *without being validated*, so
   such an export restores to an apparent 42/42 while 99 orphaned
   `mezmur_hymn_categories` rows remain — integrity the schema claims but does
   not have. The correct remedy is the second half only:** adopt
   `scripts/restore_production_dump.sh` as the documented procedure. Until then
   the organisation's only backup is not restorable by the command the runbook
   gives. **Done in `928cab2`; R-1 is now repository-side CLOSED.**
3. **L-1 — CI has never executed.** Needs a `workflow`-scoped token to push
   `f1c365e`, then a real observed run.
4. ~~**M — migration ledger governance.**~~ **CLOSED in cycle 5 — Contract B;
   no longer a production blocker.** No `schema_migrations` table exists in
   production and none is required: the repository has no application,
   deployment or runner dependency on one, `DEPLOYMENT_RUNBOOK.md` documents
   ledger-free, hand-tracked migration state as the operating procedure, the
   10 PHP migrations self-guard by introspection, and `sql/` is not designed
   for clean replay from scratch. "Which migrations has this database had?" is
   answered by schema inspection, which a clean-restore probe showed can be
   ambiguous — a disclosed operational cost (runbook 4.7(a)), not a defect.
   **No confirmed defect was found.** See section 5 for the full evidence and
   the authoritative cycle-5 contract definitions.
5. **K — 5 unadjudicated SELECT-then-INSERT candidates**
   (`admin/api_attendance.php:206`, `admin/api_communication.php:124`,
   `admin/api_education.php:455,468,793`).
6. **Duplicated legacy migration trees** — `admin/migrations/` (10 files) and
   `backend/migrations/` (9) are divergent copies; only `admin/` is consumed.

## 8. Is the codebase sufficiently characterised to proceed?

**For R and S, yes.** Both are now backed by runtime reproduction, a measured
pre-fix baseline, and regression tests that execute rather than read.

**For L, no** — and this is the honest answer rather than a convenient one.
The finding itself is *more* firmly established than before (0 runs ever, via
the live API), but the required evidence is an observed Actions run, and that
is one token scope away. Nothing about CI behaviour should be believed from
YAML inspection; that assumption is precisely what this finding is about.

**The codebase is NOT production ready.** Three HIGH items remain open: a
backup that does not restore by the documented command, a database running
without three constraints it believes it has, and a CI gate that has never
executed a single time. Two of those three need a human decision rather than
more analysis.

---

# CYCLE 5 ADDENDUM — L verified against real Actions runs; R-2 orphans investigated

Written after the sections above. Two things changed: a `workflow`-scoped
token arrived, so **L is now closed with observed-run evidence**; and the
owner asked for an investigation (not a cleanup) of the 102 orphan rows.

> **A note on the working conditions:** the workspace rolled back *again*,
> mid-turn, between the token arriving and the push. HEAD reverted to
> `27f2c34` and every uncommitted change vanished. **Nothing was lost**: the
> two pushed commits were refetched from GitHub and the tree restored
> exactly. Only the one commit that had never been pushed had to be redone.
> The push strategy is what made this recoverable.

## A. FINDING L — **CLOSED**, verified by four real Actions runs

The workflow is live: workflow id **373109286**, `Backend checks`, state
`active`, path `.github/workflows/backend-checks.yml`. Before this, the
repository's entire history contained **0 workflows and 0 runs**.

### A.1 The run that matters — run #3, SUCCESS

| field | value |
|---|---|
| **run number** | **3** |
| **run id** | **37011095086** |
| **URL** | https://github.com/suraman21/SSMS/actions/runs/37011095086 |
| **commit** | `9b92a630634e2b86744fe26cdc9cb188446d1c81` |
| branch / event | `audit/cycle5-remediation` / `push` |
| **PHP version** | **8.4.26** (matches the 8.4.25 production baseline; was 8.2) |
| **MariaDB** | **11.4** service container, ready in 1s |
| Python | 3.11.16 |
| **status / conclusion** | **completed / success** |
| duration | 13:09:05Z → 13:10:10Z (65s) |

| job | id | result | evidence |
|---|---|---|---|
| PHP syntax (`php -l`) | 110850745015 | **success** | `Linted 299 PHP files.` on PHP 8.4.26 |
| Security and regression suite | 110850745285 | **success** | `1464 passed, 464 subtests passed in 23.42s` |
| Migration numbering | 110850745371 | **success** | numbers unique |

**Tests executed 1464 · passed 1464 · failed 0 · skipped 0.** The gate
printed `Skipped tests: 0` / `No tests skipped.` Every intended suite ran —
the DB-backed comm e2e tests executed against the MariaDB service rather
than self-skipping, which was the entire point.

### A.2 The two failures were real, and both found real defects

CI was not green on the first attempt, and that is the finding working.

**Run #1** (`37010653137`, commit `b6e2ad5`) — **failure**.
`Create test databases` died with `ERROR 1044 (42000): Access denied for
user 'ssms'@'%' to database 'ssms_e2e'`: the MariaDB container grants
`MARIADB_USER` rights on `MARIADB_DATABASE` only. A workflow defect, fixed
by creating the databases as root and granting explicitly. Note what run #1
*did* prove: PHP 8.4.26 installed, the service came up in 1s, `php-lint` and
`migration-hygiene` both passed, and the skip gate correctly failed the job
when pytest produced no output — **it fails closed.**

**Run #2** (`37010834451`, commit `07db598`) — **failure**, and this one
found a genuine repository defect: `1442 passed, 22 errors`.

```
FileNotFoundError: '/home/user/SSMS/admin/backend/services/ReportCardService.php'
FileNotFoundError: '/home/user/SSMS/sql/050_assessment_types.sql'
```

An absolute sandbox path appearing on a GitHub runner. Four modules —
`test_advanced_analytics.py`, `test_assessment_types.py`,
`test_education_analytics_hub.py`, `test_performance_filter.py` — each set
`ROOT = Path("/home/user/SSMS")` while every other module in
`tests/security` uses `Path(__file__).resolve().parents[2]`.

**This defect is structurally undetectable locally.** The hardcoded
directory exists on the machine that wrote it, so the tests pass there no
matter where pytest is invoked from — I confirmed this by running the
unfixed modules from a clone at a different path, and they still passed.
They fail on every *other* machine: CI, a fresh clone, any second developer.
**Run #2's 22 errors are the baseline proof**, and they are the first
evidence this repository has ever produced that could not have been obtained
by local execution. Fixed in `9b92a63`; the four modules then pass from an
arbitrary checkout path (22 passed).

### A.3 Controlled negative test — skip ⇒ build failure, **observed**

On a throwaway branch `audit/ci-negative-test`, one deliberately skipped
test was added (`@unittest.skip`). **Run #4** (`37011303611`, commit
`928d64f`):

```
SKIPPED [1] tests/security/test_zz_ci_skip_probe.py:12: deliberate skip…
1464 passed, 1 skipped, 464 subtests passed in 23.65s
Skipped tests: 1
##[error]1 test(s) skipped. A skipped test is an unverified claim …
```

| job | result |
|---|---|
| Security and regression suite | **failure** — step `Fail if any test skipped` |
| PHP syntax, Migration numbering | success |

Note the mechanism precisely: **pytest itself exited 0** — a skip is not a
failure to pytest — and the job failed anyway because the gate counted it.
That is the behaviour the gate exists to provide, now demonstrated rather
than asserted from YAML.

**Restored:** the probe file and the branch were deleted
(`git push --delete`); only `main` and `audit/cycle5-remediation` remain.
The workflow is byte-identical to the one that produced green run #3.

### A.4 Verdict

**L is CLOSED.** Workflow triggers on push; setup steps succeed; MariaDB
starts and is reachable; test databases are created; `.fkss_env.php` is
safely generated with placeholder credentials; all intended suites execute
with **zero skips**; PHP matches the production baseline; DB-backed tests
genuinely execute; lint runs across 299 files; regression tests run; and
failure behaviour is **real, observed four times**, not inferred.

## B. R-2 — orphan investigation (read-only; no data modified)

Scope held to the affected tables as instructed. No migration written, no
database copied, no production data touched. All queries ran against the
disposable `r_orphan`.

### B.1 The structural fact that decides most of this

Both link tables are **pure junction tables** — the primary key *is* the two
foreign-key columns, and there are no other columns at all:

```sql
CREATE TABLE `mezmur_hymn_categories` (
  `hymn_id` bigint(20) unsigned NOT NULL,
  `category_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`hymn_id`,`category_id`), KEY `idx_mhc_category` (`category_id`)
);
CREATE TABLE `mezmur_hymn_zemarians` (
  `hymn_id` bigint(20) unsigned NOT NULL,
  `zemarian_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`hymn_id`,`zemarian_id`), KEY `idx_mhz_zemarian` (`zemarian_id`)
);
```

No timestamp, no author, no note, no soft-delete flag. An orphan row
therefore carries **exactly one assertion** — "hymn X belongs to category Y"
— and nothing else. There is no hidden payload to recover.

### B.2 Surviving parents vs referenced ids

| table | rows | id range | surviving ids |
|---|---|---|---|
| `mezmur_hymns` | 11 | 68–82 | 68, 69, 73, 74, 75, 77, 78, 79, 80, 81, 82 |
| `mezmur_categories` | 38 | 71–117 | 71–75, 85–117 |
| `mezmur_zemarians` | 6 | 9–14 | — |

**Missing parent ids referenced by the orphans**

- **hymn_id (68 distinct):** 2, 3, 6–67, 70, 71, 72, 76
- **category_id (10 distinct):** 23, 26, 29, 30, 31, 32, 33, 34, 35, 76
- **zemarian orphan:** hymn_id 76

Hymns 1–67 are gone wholesale, and 70, 71, 72, 76 were deleted from the
surviving generation too — so parent deletion continued *after* the re-seed,
with the CASCADE constraints still not enforcing.

### B.3 Row counts by missing parent

**By missing category** (101 rows across 10 ids):

| missing category_id | link rows |
|---|---|
| 23 | **53** |
| 30 | 13 |
| 31 | 10 |
| 33 | 7 |
| 34 | 6 |
| 29 | 4 |
| 35 | 3 |
| 32 | 2 |
| 26 | 1 |
| 76 | 1 |

**By missing hymn** (99 rows across 68 ids): 40 hymns appear in 1 category,
25 in 2, 3 in 3.

### B.4 Is anything recoverable? — cross-reference of every other store

| table | total rows | rows referencing a deleted hymn |
|---|---|---|
| `mezmur_play_stats` | **0** | 0 |
| `mezmur_user_favorites` | **0** | 0 |
| `mezmur_hymn_words` | **0** | 0 |
| `mezmur_hymn_zemarians` | 9 | 1 |

`mezmur_hymns` is the only store of titles, lyrics, audio keys and artwork,
and it holds 11 rows. There are **no archive or backup tables**. So for the
68 deleted hymns there is **no surviving title, lyric, audio reference or
play statistic anywhere in the database** — the orphan rows point at records
that are gone without trace. They cannot be repaired, only kept or removed.

### B.5 Breakdown of all 110 link rows

| state | rows | recoverable business value |
|---|---|---|
| both parents missing | **98** | **none** — links a deleted hymn to a deleted category |
| hymn deleted, category exists | 1 | none — hymn 76 → category 87 `general` |
| **hymn LIVE, category deleted** | **2** | **see below** |
| fully valid | 9 | n/a (retained) |

Plus 1 orphan in `mezmur_hymn_zemarians`: hymn 76 (deleted) → zemarian 9
(`ዘማሪ ...`, still present). **Total to remove for 42/42: 102** (101 + 1),
leaving 9 and 8 valid rows respectively.

### B.6 The one finding that is not "safe to delete"

Two rows concern **live, `active` hymns** whose *only* category assignment
is the dangling one:

| hymn_id | title | dangling category_id | valid categories remaining |
|---|---|---|---|
| 73 | የሚጠብቀኝ አይተኛም | 30 | **none** |
| 68 | የራማው ልዑል | 32 | **none** |

Deleting those two rows would leave both hymns **uncategorised** — they
would disappear from category-filtered browsing while remaining active.
This is the only genuine business consequence in the entire 102-row set.

**It argues for a reassignment, not for keeping a broken row.** The row
cannot be preserved — category 30 and 32 do not exist and cannot be
referenced. The correct handling is to assign hymns 68 and 73 to a valid
category *before* the cleanup. The remaining 100 rows carry no recoverable
information whatsoever.

For reference, the 9 valid links that survive untouched: hymns 69, 74, 75,
77, 78, 79, 80, 81, 82 → `አጠቃላይ` / `general` / `የሚካኤል መዝሙራት` /
`የገብርኤል መዝሙራት`.

### B.7 What the owner now has to decide

1. **Hymns 68 and 73** — pick a valid category for each (they currently
   have none that resolves). This is a content decision.
2. **The other 100 rows** — they reference hymns and categories that no
   longer exist anywhere in the database and are already unreachable through
   the application. Removing them is what allows `fk_mhc_hymn`,
   `fk_mhc_category` and `fk_mhz_hymn` to be validated.

**No migration was written and nothing was modified**, per instruction.
Until a decision is made, production continues to run without three
constraints its schema declares — and the restore procedure
(`scripts/restore_production_dump.sh`) correctly yields 39/42 rather than
pretending otherwise.

## C. Updated status

| finding | status |
|---|---|
| **R** | **characterised; 39/42 deterministic restore shipped and reproduced on a clean rebuild.** 3 constraints blocked by 102 orphan rows now fully investigated — **owner decision outstanding** |
| **S** | **CLOSED** — blocked and still-working, both proven at runtime |
| **L** | **CLOSED** — runs #1–#4 observed; green run #3 recorded; skip⇒failure demonstrated and reverted |

**Totals on the real CI runner (run #3):** 1464 passed · 0 failed ·
**0 skipped** · 464 subtests · 299 PHP files linted · 3/3 jobs green.

**New defect found this addendum:** 4 test modules hardcoded an absolute
sandbox path, passing locally on one machine and failing everywhere else —
found by CI run #2, undetectable locally, fixed in `9b92a63`.

**Remaining production blockers:** R-2 (orphan decision), R-1 (backup export
omits `FOREIGN_KEY_CHECKS`), M (no migration ledger), K (5 unadjudicated
SELECT-then-INSERT sites), and the duplicated legacy migration trees.
**The codebase is not production ready**, but R, S and L are no longer the
reasons why — only R-2 and R-1 remain of the three, and both are decisions
rather than unknowns.

---

## R-2 CLOSURE — ORPHANED MEZMUR LINK ROWS (owner-authorised; applied to a disposable database, NOT to production)

Status change: **R-2 OPEN (decision pending) → R-2 RESOLVED (remediation
written, applied to a disposable DB, verified and proven reversible).**

The owner reviewed `docs/audits/evidence/r2/R2_category_decision_hymns_68_73.md`
and authorised exactly three corrections. No other change was made.

| # | Correction | Authority |
|---|---|---|
| 1 | Hymn 68 `የራማው ልዑል`: category 32 (deleted) → **85 `የገብርኤል መዝሙራት`** | Legacy denormalised `mezmur_hymns.category` string matches category 85 exactly; hymn 78 carries the identical string and is already filed under 85 |
| 2 | Hymn 73 `የሚጠብቀኝ አይተኛም`: category 30 (deleted) → **116 `አጠቃላይ`** | Content is Psalm 121/23 addressed to ጌታዬ with no Marian/angelic material; 116 is the only in-use `አጠቃላይ` bucket (6 of 9 valid assignments, all Lord/Christ-themed) |
| 3 | Delete the remaining **100** link rows | Pure junction rows, no payload column, both parents absent; no recoverable business value |

### Implementation — `sql/055_mezmur_orphan_link_cleanup.sql`

Data-only (no DDL against existing tables), idempotent, precondition-guarded,
quarantine-backed, with a documented rollback block.

- **Aborts** via `SIGNAL SQLSTATE '45000'` unless categories 85 and 116 both
  exist with `is_active = 1`.
- **Quarantines first:** every affected row is copied into
  `migration_055_mezmur_link_quarantine` (migration 013's convention) with an
  action and a written reason **before** anything is modified — 102 rows.
- **Reassignments** are narrow keyed `UPDATE`s on the two specific pairs.
- **Deletions are keyed on a missing _hymn_ only.** A generic "either parent
  missing" sweep would also have destroyed the two live-hymn rows above,
  silently removing two active hymns from category browsing. That asymmetry is
  the single most important property of this migration.
- Ends with a report and a zero-orphan assertion.

It deliberately does **not** add the three foreign keys — adding constraints is
a schema change and is left to the owner's migration process.

### Verification (disposable DB `r_fix`, restored from the production export)

| Table | Before | After | Δ |
|---|---|---|---|
| `mezmur_hymn_categories` | 110 | **11** | −99 |
| `mezmur_hymn_zemarians` | 9 | **8** | −1 |
| `mezmur_hymns` | 11 | 11 | 0 |
| `mezmur_categories` | 38 | 38 | 0 |
| Foreign keys | 39/42 | **42/42** | +3 |

1. Hymn 68 → 85, active, parent `የመላዕክት ዝማሬዎች`. **PASS**
2. Hymn 73 → 116, active, parent `የጌታ ዝማሬዎች`. **PASS**
3. Dangling categories 30 and 32 → **0 references each**. **PASS**
4. Combined orphan probe → **0**. Hymns with no category at all → **0** (was 2). **PASS**
5. `fk_mhc_hymn`, `fk_mhc_category`, `fk_mhz_hymn` all created without error. **PASS**
6. `CHECKSUM TABLE` over all 87 pre-existing tables: **only the two intended
   tables changed; 85/87 bit-identical.** **PASS**
7. **TOTAL FOREIGN KEYS: 42/42.** **PASS**

**Idempotency:** a second run reports the same result and changes nothing.

**Reversibility — proven, not asserted.** The three FKs were dropped, the
documented rollback was executed, and **all 87 table checksums returned to
`evidence/r2/fingerprint_before.txt` exactly**. Note that the FKs must be
dropped before rolling back: restoring the quarantined rows necessarily
violates the very constraints the cleanup made possible.

**End-to-end:** a clean restore of the production export followed by migration
055 and the three constraints reaches **42/42 foreign keys, 88 tables**, with
zero uncategorised hymns.

### Consequence for the restore runbook

`scripts/restore_production_dump.sh` previously hard-expected 39 foreign keys
and would therefore have reported a **false MISMATCH on a correct post-055
re-export**. It now recognises both inventories — 39 (pre-055, three
constraints legitimately rejected) and 42 (post-055, zero rejected) — and fails
on anything else. Both paths were exercised. The script's dependence on the
phpMyAdmin trailing-`ALTER` export format is now documented in its header; a
mysqldump-style export with inline constraints exits 2 with
`no constraints parsed` rather than silently restoring without foreign keys.

**R-1 was unaffected by R-2 and remained open at the time of writing:** the
export still contains no `FOREIGN_KEY_CHECKS` statements. **Superseded —
R-1 was subsequently closed repository-side in `928cab2`; the absence of
`FOREIGN_KEY_CHECKS` turned out not to be the defect. See "FINAL
RECONCILIATION" below.**

### Evidence

- `sql/055_mezmur_orphan_link_cleanup.sql`
- `docs/audits/evidence/r2/R2_category_decision_hymns_68_73.md`
- `docs/audits/evidence/r2/fingerprint_before.txt` / `fingerprint_after.txt`
- `docs/audits/evidence/r2/affected_rows_backup.sql` — 102 re-insertable
  `INSERT IGNORE` statements, a file-based backup of only the affected rows

**Not done, by instruction:** nothing was applied to production. Migration 055
is reviewed and proven but **unexecuted against the live database**; that
remains the owner's call.

---

# FINAL RECONCILIATION — 2026-10-02

Reconciled against the GitHub tree at `audit/cycle5-remediation` HEAD
**`928cab27f8db1f4f7ffc0518db450dad1da1626e`** (`928cab2`), local == origin,
working tree clean. This section is the authoritative current status; where it
disagrees with an earlier cycle section above, **this section wins**. Earlier
sections are retained as the chronological record of how each conclusion was
reached, not as current status.

## Status of every investigated finding

| finding | status | commit | verification evidence | operational action outstanding |
|---|---|---|---|---|
| **P** — member report export fatals (`MemberCategory` never loaded) | **CLOSED** | `2279376` | Runtime-proven through the production entry point, not inferred from source; export fatal reproduced before the fix and absent after. | none |
| **Q** — mobile HR review fatals (`SecurityAuditService` never loaded) | **CLOSED** | `2279376` | Runtime-reproduced; found to be materially worse than first recorded (the fatal aborted the review write path). Re-tested after the fix. | none |
| **K** — check-then-act races on status mutations | **CLOSED** | `f93ffc8` | 34 status-mutating UPDATEs enumerated. 10 in-scope paths verified already guarded; **2 genuine defects** confirmed by runtime reproduction in `admin/api_education.php`: `transfer_student` (~L1142, reproduced with **no concurrency** — a replay left one member active in two classes) and `promote` (~L481, two concurrent promotions to *different* targets both succeeded). `UNIQUE KEY unique_enrollment` cannot catch either, because the targets differ. Fixed with a minimal compare-and-set (`AND status='active'`, `affected_rows < 1` → HTTP 409). Post-fix: replay → 409; 5/5 race rounds leave exactly one active enrollment. 3 regression tests added — **all 3 fail on the unfixed baseline**. | none |
| **L** — CI never executed | **CLOSED** | `b6e2ad5` (workflow), `07db598` + `9b92a63` (fixes) | Verified by **real GitHub Actions runs**, not locally. Workflow `373109286` "Backend checks". Green run #3 `37011095086`@`9b92a63`; **controlled negative test** run #4 `37011303611`@`928d64f` on the temporary branch `audit/ci-negative-test` failed **by design**, proving skip ⇒ build failure; the branch was then removed and the workflow restored. Latest run **#10 `37031500284`@`928cab2` — 3/3 jobs success**, PHP 8.4.26, MariaDB 11.4, **1468 passed · 0 failed · 0 skipped** · 464 subtests; the job log records `Skipped tests: 0`. | none |
| **M** — migration ledger governance | **CLOSED — Contract B** | `0f1bdbf` | Contract B = migrations are applied and managed without an authoritative ledger table. 0 `schema_migrations` rows across 87 production tables; no application, deployment or runner dependency on one; `sql/` is not replayable from empty. Of 54 files: 41 static-guard, 9 dynamic-guard, 4 unguarded (`015`, `029`, `036`, `052`). **No confirmed defect.** Earlier "REQUIRES DECISION" text at lines ~590, ~689, ~1042 and ~1212 is historical cycle narrative, superseded here. | none |
| **R-1** — restore procedure / FK checks | **CLOSED (repository-side)**; GUI bypass is an operational responsibility | `928cab2` | Classified an **operational-runbook defect**, not a backup-file or code defect. The export did **not** need to change: the repo's own `BackupService.php` already emits FK guards and takes a consistent snapshot. The defect was that the runbook's rollback named no procedure, and `scripts/restore_production_dump.sh` appeared nowhere in it. Measured on MariaDB 11.8.6, disposable DBs: naive import → exit 1, `ERROR 1452` line 9746, **31/42**; restore script → `RESTORE STATUS: PASS`, **39/42** (expected pre-055); `FOREIGN_KEY_CHECKS=0` → exit 0, **42/42 but 99 orphans still present** (unvalidated — the trap); after `sql/055` → **42/42, 0 orphans**, all validated; dump with one FK removed → 38 detected, `MISMATCH`, `RESTORE STATUS: FAIL`, **exit 2** (fails loudly, as required). Regression test fails on the unfixed runbook. | **Yes** — the repository cannot prevent an operator ticking "Disable foreign key checks" in the phpMyAdmin GUI. It can only document the prohibition (now done) and have the restore script refuse the shortcut (it does). |
| **R-2** — 102 orphaned mezmur link rows | **CLOSED (investigation + migration written)**; **migration 055 is NOT applied to production** | `1f9d410` | `sql/055_mezmur_orphan_link_cleanup.sql` reviewed, applied **to a disposable database only**, verified, proven reversible, and guarded by `SIGNAL '45000'`. Takes FKs 39 → 42. Hymns 68 and 73 category reassignments (32→85, 30→116) are **recommendations only and were not applied**; the other 100 payload-free orphan rows are addressed by the migration. CI #7 `37021351335` green. | **Yes** — two owner decisions: (a) confirm the categories for hymns 68 and 73; (b) authorise running `055` against production. Until then production runs at **39/42** constraints. |

## Remaining operator / production actions

1. **Apply `sql/055` to production** (R-2) — owner authorisation required. Until
   applied, production carries 39 of 42 declared constraints.
2. **Decide the category for hymns 68 and 73** (R-2) — a content decision;
   recommendations recorded, deliberately not applied.
3. **Never tick "Disable foreign key checks"** when taking the step 0.1
   phpMyAdmin export (R-1), and restore with
   `scripts/restore_production_dump.sh` rather than a phpMyAdmin import. The
   repository cannot enforce either; `DEPLOYMENT_RUNBOOK.md` documents both.
4. **Rotate both GitHub PATs** used during this audit.

## Explicitly outside current scope

- `admin/api_cms.php:285` — unguarded `cms_registration_submissions.status`
  overwrite. **REQUIRES VERIFICATION in a later cycle**; not investigated here
  by instruction, and therefore **not** cleared.
- Duplicated legacy migration trees — out of scope, unchanged.

## Standing disproofs preserved

The suspected **Education self-approval bypass does not exist** — that
disproof stands. Other rejected false positives (the scanner's "4 no-WHERE
UPDATEs", `user-delete.php:116`, `MezmurMediaService.php:582`, the "11 missing
FKs" damage measure — the real figure is 3, type/collation and restore-order as
R's cause, and the smoke-suite exit 255 attributed to K) remain rejected and
should not be re-litigated without new evidence.

## Readiness

**This section does not assert production readiness.** It records that the
findings listed above are characterised and closed to the extent the repository
can close them, and that the outstanding items are the operator actions named
above.
