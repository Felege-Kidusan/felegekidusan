# WBSS / FKSS Sunday School Management System
## Phase 3 — Functional Correctness, Logic & Reliability Audit

**Reviewer role:** Senior PHP developer (functional/logic/reliability review — security audited separately)
**Focus:** Bugs, broken logic, and *silent killers* — code that passes a quick test but fails in production, under load, or across years.
**System:** PHP 8.2 + MariaDB 10.6; ~5,000 students expected; must run year-over-year without manual repair.
**Method:** Traced the registration write path, member-edit/denormalization sync, teacher/user deletion, attendance save + summary math, year-transition logic, and report calculations; counted silent-failure patterns; mapped dead tables/config/endpoints.

> **One-line verdict:** The app is defensively coded in places (guarded divisions, prepared statements, unique constraint on member code) but carries **several silent data-corruption bugs** — non-transactional attendance overwrite, an Ethiopian/Gregorian key collision in the attendance summary, unguarded teacher deletion that orphans records, no duplicate-student prevention, and **no year-rollover mechanism at all**. These do not fail in a 10-record demo; they fail at 5,000 records and at every September.

---

# BUG REPORT CARDS

## 1. REGISTRATION

### BUG ID: 1
**FILE:** `admin/info_register_member.php`
**FUNCTION/AREA:** whole write path; duplicate check delegated to `admin/api_check_duplicate.php`
**TYPE:** Data Corruption Risk
**DESCRIPTION:** Duplicate-student prevention is **advisory and client-side only**. `api_check_duplicate.php` is a separate AJAX call the form makes to *warn* the operator; the actual insert in `info_register_member.php` enforces uniqueness **only on `member_code`** (a random number). There is no server-side check on student identity (name + father + DOB). Two operators — or one operator clicking twice, or a re-registration next year — create two full member records for the same child, each with its own code.
**TRIGGERS WHEN:** The same student is registered twice (concurrent operators, double-submit, or re-enrollment), or the operator ignores/doesn't see the warning.
**IMPACT:** Silent duplicate students. Their attendance, grades, and fee records split across two IDs; head-counts and reports overcount; ID cards issued twice. No error shown.
**PRIORITY:** HIGH

### BUG ID: 2
**FILE:** `admin/info_register_member.php`
**FUNCTION/AREA:** `saveUploadedFile()` calls (lines 254-258) run *before* `$conn->begin_transaction()` (line 292); `catch` block (lines 394-416) has no file cleanup
**TYPE:** Silent Failure / resource leak
**DESCRIPTION:** Files are moved to disk with `move_uploaded_file()` **before** the DB transaction opens. If the `INSERT` throws and rolls back, the uploaded photo/documents remain on disk with no database row referencing them. The `catch` performs `$conn->rollback()` but never `unlink()`s the orphaned files.
**TRIGGERS WHEN:** Any registration that fails after upload but before/at commit (column mismatch, data-too-long, DB hiccup, code collision).
**IMPACT:** Orphaned files accumulate in `admin/uploads/members/` over years; slow disk leak, and orphaned PII files with no owning record. Invisible to users.
**PRIORITY:** MEDIUM

### BUG ID: 3
**FILE:** `admin/info_register_member.php`
**FUNCTION/AREA:** `generateMemberCode()` (lines 56-73)
**TYPE:** Concurrency Risk (mitigated but user-visible)
**DESCRIPTION:** Code generation is check-then-insert (TOCTOU): it `SELECT COUNT(*)`s a random code, then inserts later. Two concurrent registrations can pick the same free code. The `member_code` UNIQUE constraint correctly rejects the loser, and the `catch` detects "duplicate entry" and tells the user to click Save again (lines 401-405) — but recovery is **manual**, and on the retry all form re-validation/re-upload must succeed again. Also, if `prepare()` fails inside the loop, it `return`s the unchecked code (line 63).
**TRIGGERS WHEN:** Two admins submit registrations in the same instant (plausible with 5,000 intake).
**IMPACT:** Occasional "Code conflict, click Save again" message; re-upload burden. Data stays consistent (constraint holds), so this is reliability/UX, not corruption.
**PRIORITY:** LOW

> **Note (good):** Required-field enforcement is partly at DB level — `members` has `NOT NULL` on `full_name_am`, `student_name`, `father_name`, and enum/`NOT NULL` defaults on `gender`/`status`. So the most critical fields cannot be bypassed by skipping PHP validation. Many *other* fields are nullable and validated only in PHP.

---

## 2. DATA MODIFICATION

### BUG ID: 4
**FILE:** `admin/backend/user-delete.php`
**FUNCTION/AREA:** `DELETE FROM users WHERE id = :id` (line 48)
**TYPE:** Data Corruption Risk (silent)
**DESCRIPTION:** Deleting a user runs a bare `DELETE` with **no cleanup of dependent rows**, and the referencing tables have **no foreign keys** (`teacher_assignments.teacher_id`, `attendance.recorded_by`, `academic_records.recorded_by`, `notifications.source_user_id`/`target_user_id`, `department_tasks.from_user_id`/`to_user_id`, `activity_logs.user_id`, `users.member_id`). So the delete succeeds and leaves dangling references everywhere. `api_teachers.php` even actively drops the FK on `teacher_assignments.academic_year_id`.
**TRIGGERS WHEN:** A super_admin deletes a departed teacher/staff account.
**IMPACT:** That teacher's class assignments silently vanish from `teacher_assignments` JOINs (teacher name renders NULL, class shows "no teacher"); their recorded attendance/grades lose attribution; pending tasks addressed to/from them become undeletable ghosts. No warning, no cascade.
**PRIORITY:** HIGH

### BUG ID: 5
**FILE:** `admin/api_education.php`
**FUNCTION/AREA:** `unenroll_student` (line 578-591) vs member denormalized columns
**TYPE:** Data Corruption Risk (out-of-sync duplication)
**DESCRIPTION:** Class membership is stored in **two places**: the `class_enrollments` table *and* denormalized columns on `members` (`current_class_id`, `spiritual_education` (holds class_code text), `academic_status`). `unenroll_student` sets `class_enrollments.status='withdrawn'` but **does not clear** `members.current_class_id`/`academic_status`. Promotion updates both (via `autoUpdateMemberClass`), but withdrawal, direct member edits, and year changes update only one side.
**TRIGGERS WHEN:** A student is withdrawn/unenrolled, or edited via `info_manage_member.php`, or a new year starts.
**IMPACT:** The member profile and mobile API keep showing a class the student is no longer enrolled in; "current class" disagrees with the enrollment table; class rosters and member cards contradict each other. Silent, and worsens every year.
**PRIORITY:** MEDIUM (→ HIGH across a year boundary)

### BUG ID: 6
**FILE:** `admin/info_manage_member.php`
**FUNCTION/AREA:** member update `UPDATE members SET … WHERE id=?` (lines ~190-235)
**TYPE:** Logic Error / lost update
**DESCRIPTION:** Member edit reads the form and overwrites the `members` row with no optimistic-locking/version check and no `SELECT … FOR UPDATE`. Two departments editing the same member (Info edits contact info while Education toggles `is_teacher`) → last writer wins, silently discarding the other's change. Role-flag edits here also may not re-run `syncMemberType()` (member_sync.php), so `member_type` can drift from the flags.
**TRIGGERS WHEN:** Two staff edit the same member around the same time; or a role flag is changed here rather than through the education flow.
**IMPACT:** Silent lost updates; `member_type` (regular/special_regular) out of sync with `is_teacher`/`is_staff` flags.
**PRIORITY:** MEDIUM

---

## 3. YEAR-TO-YEAR TRANSITION (the critical category)

### BUG ID: 7
**FILE:** (system-wide) `admin/api_education.php`, `admin/backend/workflow.php`
**FUNCTION/AREA:** no "close year / open year" routine exists anywhere
**TYPE:** Logic Error (missing mechanism)
**DESCRIPTION:** There is **no year-rollover process**. Creating a new `academic_years` row and flipping `is_current` is the entire "transition." Enrollments (`class_enrollments`), which are keyed by `academic_year_id`, are **not carried forward**; there is no bulk "advance grade / graduate / re-enroll." `promote` acts on one student at a time.
**TRIGGERS WHEN:** Every new academic year (September).
**IMPACT:** The instant a new year is marked current, every class roster is **empty** (old enrollments point at the prior year). Attendance and grade screens for the new year show no students until someone manually promotes/re-enrolls ~5,000 students one at a time. This is a yearly operational cliff, not a one-time setup.
**PRIORITY:** CRITICAL

### BUG ID: 8
**FILE:** `admin/api_education.php`
**FUNCTION/AREA:** `set_current_year` (802-805) and `save_academic_year` (`if($isCurrent) UPDATE … is_current=0` line 734)
**TYPE:** Data Corruption Risk / Concurrency
**DESCRIPTION:** Switching the current year is two separate statements — `UPDATE academic_years SET is_current=0` then `UPDATE … SET is_current=1 WHERE id=?` — with **no transaction**. A failure/timeout between them leaves **zero** rows with `is_current=1`.
**TRIGGERS WHEN:** Admin changes the active year and the second statement fails (crash, timeout, connection drop), or two admins do it concurrently.
**IMPACT:** Dozens of queries do `WHERE is_current=1 LIMIT 1` (enrollment, attendance, grades, mobile API). With zero current years they silently return nothing → enrollment/attendance/grades all behave as if no year exists, no error surfaced. Recoverable only by manually re-setting a current year.
**PRIORITY:** HIGH

### BUG ID: 9
**FILE:** `admin/api_attendance.php` (`updateAttendanceSummary`, lines 278-337) vs `admin/backend/workflow.php` (`updateAttendanceSummary`, lines 615-705)
**FUNCTION/AREA:** two different functions, same name, same target table
**TYPE:** Data Corruption Risk (silent)
**DESCRIPTION:** `attendance_summary` has UNIQUE key `(member_id, academic_year_id, month, year)`. The **workflow** version writes `month`/`year` as **Ethiopian** calendar values (lines 620-621); the **api_attendance** version writes **Gregorian** `date('m')`/`date('Y')` (lines 284-285). Both upsert the same table via different code paths.
**TRIGGERS WHEN:** Attendance saved through the class flow (Gregorian) vs the per-member education flow (Ethiopian) — both happen in normal use.
**IMPACT:** The same real month gets stored under two different `(month,year)` keys (e.g. EC 2018 vs GC 2026), so summaries **double-count or split** a month's attendance, and `total_attendance_rate` on the member becomes wrong. Completely silent; corrupts multi-year attendance history.
**PRIORITY:** HIGH

### BUG ID: 10
**FILE:** `member.php` (line 28), `admin/info_register_member.php` (line 132)
**FUNCTION/AREA:** Ethiopian year computed as `(int)date('Y') - 8`
**TYPE:** Logic Error (recurring)
**DESCRIPTION:** The Gregorian→Ethiopian year offset is hardcoded as `-8`, but the real offset is **7 or 8 depending on the month** (Ethiopian new year falls in September). A proper converter exists (`backend/ethiopian_date.php`) but these two spots bypass it.
**TRIGGERS WHEN:** ~4 months of every year (roughly Sept–Dec), and for the age display on the public QR page.
**IMPACT:** Age shown on the public verification page (`$age = $currentYearEth - dob_ec_year`) and the default registration year are **off by one** for part of every year. Subtle, recurs annually, easy to miss.
**PRIORITY:** MEDIUM

### BUG ID: 11
**FILE:** `admin/api_education.php` / `admin/dashboards/info-dept.php`
**FUNCTION/AREA:** `academic_status` enum has `graduated`, but no code path sets it
**TYPE:** Logic Error / Dead transition
**DESCRIPTION:** `members.academic_status` supports `graduated`, and the UI exposes a "Graduated" filter (`info-dept.php:4258`), but **no code ever sets a student to graduated** — there is no graduation flow. Students who finish the top class stay `active` with a stale `current_class_id`.
**TRIGGERS WHEN:** Any student completes the highest level.
**IMPACT:** "Active member" counts inflate year over year; graduates never leave the active population; reports overstate enrollment indefinitely.
**PRIORITY:** MEDIUM

> **Good:** No hardcoded academic-year literals (`WHERE year=2024`) were found in query logic — year scoping uses `is_current=1` dynamically. The failure mode is the *absence* of rollover logic (Bugs 7-8), not hardcoded years.

---

## 4. REPORTS & DATA ACCURACY

### BUG ID: 12
**FILE:** `admin/api_reports.php`, `admin/api_communication.php`, `admin/reports.php`
**FUNCTION/AREA:** report aggregation queries
**TYPE:** (mostly correct) — data freshness
**DESCRIPTION:** Reports pull **live** data via `COUNT`/`SUM` at request time (no snapshot/cache table), so they don't go stale — good. The accuracy risk is inherited from Bug 1 (duplicate members overcount) and Bug 9 (attendance-summary key collision), not from the report queries themselves.
**TRIGGERS WHEN:** Whenever duplicates (Bug 1) or EC/GC summary rows (Bug 9) exist.
**IMPACT:** Totals that look authoritative but overcount duplicated students / miscount attendance. The report layer faithfully reports corrupted inputs.
**PRIORITY:** MEDIUM (derived)

### BUG ID: 13
**FILE:** `admin/api_attendance.php`
**FUNCTION/AREA:** `get_class_attendance` default status (lines 99-101)
**TYPE:** Logic Error
**DESCRIPTION:** When rendering a class sheet, any student without an existing record is defaulted to `present`. `save_attendance` then deletes and re-inserts everything. So opening a class and clicking Save (without marking anyone) records **everyone present**, and mid-year joiners with no prior record also default to present.
**TRIGGERS WHEN:** A teacher opens a class sheet and saves without explicitly marking absentees; or reviews a past date.
**IMPACT:** Attendance inflated toward "present"; absences under-recorded; attendance-rate reports skew high. Looks correct, silently optimistic.
**PRIORITY:** MEDIUM

> **Good:** Division-by-zero is **well defended** — percentage/average calculations are consistently guarded (`$total > 0 ? … : 0/null`) in `workflow.php:645`, `groups_api.php:1162-1167`, `api_communication.php:361,369,397,506,515,518`, and the dashboards. `calculateGradeLetter` guards `$maxScore == 0`. On PHP 8.2 an unguarded `/0` is a fatal `DivisionByZeroError`, so this discipline matters — and it's present. No live div/0 crash was found.

---

## 5. CONCURRENT USERS

### BUG ID: 14
**FILE:** `admin/api_attendance.php`
**FUNCTION/AREA:** `save_attendance` — `DELETE FROM attendance WHERE class_id=? AND attendance_date=?` (line 133) then per-record `INSERT` loop (143-175), **no transaction**
**TYPE:** Concurrency Risk / Data Corruption
**DESCRIPTION:** The save deletes the whole class/date first, then re-inserts row by row, with **no `begin_transaction`/`commit`**. If the request dies mid-loop (timeout on a big class, a fatal error, a dropped connection), the delete has happened but the inserts have not → that class's attendance for the day is **gone**. Two teachers saving the same class/date concurrently interleave delete/insert and clobber each other. `api_mobile.php` `attendance/save` (line 212) and `sync/push` (line 222) have the identical delete-then-insert-no-transaction pattern.
**TRIGGERS WHEN:** Interrupted save, or concurrent saves for the same class/date (plausible when 50 teachers submit near-simultaneously, or a teacher uses web + mobile).
**IMPACT:** Silent, total loss of a class's attendance for a date, or a mix of two teachers' entries. No error to the user (they see "records saved").
**PRIORITY:** HIGH

### BUG ID: 15
**FILE:** `admin/api_education.php`
**FUNCTION/AREA:** `promote` (466-505), `save_academic_year` semester auto-create (776-788)
**TYPE:** Concurrency Risk
**DESCRIPTION:** Multi-step writes (mark old enrollment completed → insert new enrollment → update member columns → set `promoted_at`; or insert year → insert two terms) run as independent statements with no transaction. A failure partway leaves a half-promoted student (old enrollment completed but no new one, or member columns updated without an enrollment row).
**TRIGGERS WHEN:** Error/timeout during promotion or year creation; concurrent promotions of the same student.
**IMPACT:** Students in an inconsistent enrollment state; "completed" with nowhere enrolled. Manual cleanup needed.
**PRIORITY:** MEDIUM

> **Context:** Deadlock risk is low today (small tables, InnoDB row locks), but the DELETE-then-INSERT range operations on `attendance` (Bug 14) plus the runtime `ALTER TABLE` statements (Phase 1) can produce lock waits/metadata locks on a large `attendance` table under load.

---

## 6. SILENT FAILURES

### BUG ID: 16
**FILE:** system-wide (107 occurrences)
**FUNCTION/AREA:** empty `catch (Exception $e) {}` / comment-only catch blocks
**TYPE:** Silent Failure
**DESCRIPTION:** **107 empty or comment-only catch blocks** swallow exceptions with no logging (e.g. `api_teachers.php:88,90`, `member_sync.php:75,91`, `api_mobile.php:133`, `api_ai.php:45,65,81,239,264`, and many more). Combined with runtime DDL and unchecked queries, real failures (a failed `ALTER`, a failed insert, a missing table) vanish with no trace.
**TRIGGERS WHEN:** Any caught error in these paths.
**IMPACT:** Operations appear to succeed while silently doing nothing; no logs to diagnose. This is the connective tissue that makes the other silent bugs undiagnosable.
**PRIORITY:** HIGH (aggregate)

### BUG ID: 17
**FILE:** `admin/api_education.php`, `admin/api_attendance.php`, `admin/backend/workflow.php`, etc.
**FUNCTION/AREA:** ~101 unassigned/unchecked `$conn->query(...)` calls (from structural audit)
**TYPE:** Silent Failure
**DESCRIPTION:** Roughly 101 `$conn->query()` calls ignore the return value. On failure (`false`), code proceeds as if it succeeded. Read paths like `getUnsyncedChanges`/dashboard aggregations return empty arrays on error instead of surfacing it (e.g. `api_education.php:570-572` returns `students: []` with a "Tables may need setup" note on any exception).
**TRIGGERS WHEN:** A query fails (missing column/table, lock timeout, syntax from a bad runtime `ALTER`).
**IMPACT:** Empty lists and zero counts render as legitimate "no data," masking real failures. Users see blank screens, not errors.
**PRIORITY:** HIGH

### BUG ID: 18
**FILE:** `admin/info_manage_member.php`
**FUNCTION/AREA:** `saveUploadedFile()` (lines 44-70) returns `null` on every failure
**TYPE:** Silent Failure
**DESCRIPTION:** On edit, the upload helper returns `null` for size-exceeded, bad-extension, non-image, mkdir failure, and move failure alike — with no distinct error. The caller can't tell "no file provided" from "upload failed," so a failed document replacement is silently dropped (and may overwrite the stored path with null).
**TRIGGERS WHEN:** A staff member replaces a document with a too-large/invalid file during edit.
**IMPACT:** User believes the document was updated; it wasn't, or the old reference was lost. Silent.
**PRIORITY:** MEDIUM

### BUG ID: 19
**FILE:** `config.php` (627-834), `admin/api_education.php` (42-95), `admin/api_teachers.php` (56-101)
**FUNCTION/AREA:** runtime `CREATE/ALTER TABLE` wrapped in silent try/catch
**TYPE:** Silent Failure / Data Corruption Risk
**DESCRIPTION:** Schema is created/altered on live requests inside `catch{}` blocks that ignore errors. If an `ALTER` partially applies or fails (permissions, lock timeout on a large table), the code continues assuming the column/table exists; later inserts referencing it then fail into other silent catches.
**TRIGGERS WHEN:** First request of a session, or any endpoint that self-heals schema, on a large/locked DB or with a restricted DB user.
**IMPACT:** Inconsistent schema state, cascading silent insert failures, "data saved" messages for data that wasn't. Undiagnosable due to Bug 16.
**PRIORITY:** MEDIUM

> File writes that *are* checked (good): `cron_backup.php` checks `file_put_contents(...) === false`; branding upload checks `is_writable`/`move_uploaded_file`. The unchecked ones are the `@mkdir`/`@unlink`/`@file_put_contents` rate-limit and cache writes (login rate limiting silently no-ops if the cache dir is unwritable).

---

## 7. DEAD FEATURES / DEAD CODE

### BUG ID: 20
**FILE:** `school_config.php` (lines 190-199)
**FUNCTION/AREA:** `FEATURE_*` toggles
**TYPE:** Dead Code (misleading config)
**DESCRIPTION:** Every feature flag — `FEATURE_AI_CHATBOT`, `FEATURE_GROUPS`, `FEATURE_FINANCE`, `FEATURE_MATERIAL`, `FEATURE_ID_CARDS`, `FEATURE_ATTENDANCE`, `FEATURE_GRADES`, `FEATURE_REPORTS`, `FEATURE_EXPORT_PDF`, `FEATURE_MONITOR` — is referenced in **0** application files (verified). Toggling any of them has **no effect**; the modules load regardless.
**TRIGGERS WHEN:** An operator sets `FEATURE_FINANCE=false` expecting to disable finance.
**IMPACT:** False sense of control; a "disabled" feature stays fully active and reachable. A deployment-configuration trap.
**PRIORITY:** MEDIUM

### BUG ID: 21
**FILE:** `database_schema.sql` / migrations
**FUNCTION/AREA:** `cache_storage` and `shared_documents` tables
**TYPE:** Dead Code
**DESCRIPTION:** `cache_storage` is created (schema + `auto_fix_database.php`) but **never read or written** by any code (caching uses files instead). `shared_documents` is created by migration 002 but **never read or written** — the "communication" module (`api_communication.php`) is actually marklists/report-cards (`submit_marklist`, `get_report_card`, `get_class_report`), not document sharing. The document-sharing feature is a table with no feature behind it.
**TRIGGERS WHEN:** N/A — dead weight.
**IMPACT:** Misleads maintainers into thinking caching/document-sharing exist; wasted schema.
**PRIORITY:** LOW

### BUG ID: 22
**FILE:** `backend/` tree; duplicate migrations; orphaned dashboards
**TYPE:** Dead Code
**DESCRIPTION:** (Confirmed in Phase 1) `backend/api/*.php`, `backend/auth/*`, `backend/core/*`, `backend/users/*` are shims; `backend/migrations/*`, `backend/id_cards/libs/*`, `backend/components/notification_bell.php` are byte-identical duplicates of `admin/…`; `002_migration.php`≡`002_add_academic_attendance_workflow.php` and `003_*` pairs are identical; `index.html` (static) shadows `index.php`; `admin/dashboards/finance_department.php` and `education_department.php` are orphaned by the router; `admin/id_cards/qr_diagnostic.php` is a dev tool.
**TRIGGERS WHEN:** A fix applied to `admin/…` silently doesn't take on the duplicate `backend/…` URL.
**IMPACT:** Divergent duplicates; "fixed it but it's still broken" from editing the wrong copy.
**PRIORITY:** MEDIUM

---

# SUMMARY

## BUGS THAT WILL CAUSE IMMEDIATE, VISIBLE FAILURES
- **Bug 7 (CRITICAL):** Every new academic year, all class rosters go empty — attendance/grades unusable until ~5,000 students are manually re-enrolled. Visible cliff every September.
- **Bug 8 (HIGH):** A failed/concurrent "set current year" leaves zero current years → enrollment/attendance/grade screens abruptly show nothing.
- **Bug 3 (LOW):** "Code conflict — click Save again" on concurrent registrations.
- **Bug 14 (HIGH):** An interrupted attendance save shows "records saved" but the class's day is wiped — visible the next time someone opens that sheet.

## BUGS THAT WILL CAUSE SILENT DATA CORRUPTION (the worst kind)
- **Bug 9 (HIGH):** Ethiopian vs Gregorian month/year keys collide in `attendance_summary` → attendance history double-counted/split across years. Silent, cumulative.
- **Bug 14 (HIGH):** Non-transactional DELETE-then-INSERT attendance save → silent loss of a class/date, or merged entries from two teachers.
- **Bug 4 (HIGH):** Deleting a user orphans teacher assignments, attendance/grade attribution, tasks, and notifications (no FKs, no cleanup).
- **Bug 1 (HIGH):** No server-side duplicate-student guard → two records per child, splitting their history and inflating counts.
- **Bug 5 (MEDIUM→HIGH):** `current_class_id`/`academic_status` drift from `class_enrollments` on withdrawal/edit/year change.
- **Bug 6 (MEDIUM):** Concurrent member edits silently lose updates; `member_type` drifts from role flags.
- **Bug 13 (MEDIUM):** Default-present attendance silently inflates presence.
- **Bug 10 (MEDIUM):** Hardcoded `date('Y')-8` makes displayed ages off-by-one for ~4 months yearly.
- **Bugs 16-19 (HIGH aggregate):** 107 empty catches + ~101 unchecked queries + silent runtime DDL make all of the above **undiagnosable** — failures render as empty/zero data, not errors.

## DEAD CODE INVENTORY
- `FEATURE_*` toggles in `school_config.php` — all 10 are non-functional (used nowhere). **(Bug 20)**
- `cache_storage` table — never read/written. `shared_documents` table — never read/written (document-sharing feature absent). **(Bug 21)**
- `backend/` shim + duplicate tree (api shims, duplicate migrations, duplicate phpqrcode lib, duplicate `notification_bell.php`). **(Bug 22)**
- Duplicate migrations: `002_migration.php`≡`002_add_academic_attendance_workflow.php`; `003_migration.php`≡`003_add_assessments.php`.
- `index.html` (static, shadows dynamic `index.php`); orphaned `admin/dashboards/finance_department.php` and `education_department.php`; `admin/id_cards/qr_diagnostic.php` (dev tool); committed `admin/backend/error_log`.

## READINESS ASSESSMENT — is this safe to go live with 5,000 students? Honest answer: **No.**

Not because it's broken today at 15 records — it demos fine — but because its failure modes are **exactly the ones that only appear at production scale and across time**, and they fail *silently*:

1. **It cannot cross a year boundary on its own.** There is no rollover; the first September will empty every roster and require manually re-enrolling thousands of students, and the non-transactional year flip (Bug 8) can strand the whole system with no current year. This alone disqualifies "runs year after year without manual intervention."
2. **Attendance — the daily core function — corrupts silently at scale.** The non-transactional overwrite (Bug 14) loses data on any interruption or concurrency, and the EC/GC summary collision (Bug 9) scrambles the historical numbers the school will rely on. With 50 teachers and 5,000 students, both trigger routinely.
3. **Core records drift out of sync** (Bugs 1, 4, 5, 6) with no referential integrity to stop it, and **107 empty catches + ~101 unchecked queries** guarantee that when things go wrong, they go wrong *quietly*.

**What it would take to be go-live-ready (functional side):** wrap attendance save and year/promotion operations in transactions; unify the two attendance-summary functions on one calendar and make the `is_current` flip atomic; build a real year-rollover (bulk promote/graduate/carry-forward) routine; add a server-side duplicate-student guard; make user deletion clean up or block on dependents (add the missing FKs); and replace empty catches with logging so failures are visible. These are focused, achievable fixes — the data model largely supports them — but until at least the attendance transaction (14), the summary-key collision (9), and the year-rollover mechanism (7-8) are done, running this for 5,000 students means **quietly losing and corrupting attendance and enrollment data that no one will notice until report time.**

*(Security findings are tracked separately in `SECURITY_AUDIT.md`; structural findings in `AUDIT_PHASE1_STRUCTURAL.md`. Several bugs here compound those — e.g. the missing foreign keys behind Bug 4/5 are cataloged as database risks in Phase 1.)*
