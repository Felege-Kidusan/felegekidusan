# Attendance alerting — Phase 6 implementation notes (lesson‑level Education attendance)

Date: 2026‑10‑20 · Status: **implemented on `main` (web only), awaiting owner `git pull` + `sql/076`**
Plan: `ATTENDANCE_PHASE6_LESSON_LEVEL_PLAN_2026-10-20.md` (approved "continue with recommended")
Requirements: `ATTENDANCE_DEPT_STATUS_ALERTS_REQUIREMENTS_2026-10-19.md` §3, §4–§5, §8, §13
Protocol: Universal AI Software Change, Patch & Update Protocol (§14 new feature, §16 DB, §31 plan, §33 verification, §36 report)

---

## 1. System analysis (done before any edit)

| Area | Finding (FACT from repo) | Consequence |
|---|---|---|
| Class/day writers | 5 paths write `attendance` rows: web `api_attendance.php` `save_attendance` + `scan_member`, Education `api_education.php` `batch_attendance`, mobile `/api/v1/attendance` save + `/submit` (both through one helper `apiReplaceAttendanceRows`) | One guard function, called in these 4 files, is enough to make a lesson day read‑only |
| Readers of `attendance` | 20 PHP files (warnings, roll‑ups, report cards, HR/Info views, sync feed triggers in `sql/057`) | Option B: never change the table, its UNIQUE key or its readers — the day row stays the only thing they see |
| Review surface | `grade_submissions` packet per (class, date, `attendance`) with Phase 3 reopen/reject | The derived day keeps using the same packet (`submitted`, force) — no third table, no second review screen |
| Risk maths | `AttendanceRiskService::dayOutcomes` already folds multiple rows per day; Phase 4 monthly rate is per class/day | Monthly numbers unchanged (Q6‑4) |
| Teacher scope | `teacher_assignments(teacher_id,class_id,subject_id NULL = class‑wide,is_active)`; `class_subjects(class_id,subject_id)` | Lesson subjects per teacher come from these two tables; no timetable needed (Q6‑2/Q6‑9, `timetable_entries` is empty locally and unknown in production) |
| Feature gating | `FeatureGate` keys map to `FEATURE_*` constants; Phases 1–5 all use `attendance_v2` + "table exists" | **No new flag** (simplicity): lesson sheets appear when `FEATURE_ATTENDANCE_V2` is on **and** `sql/076` is applied |
| Audit | `SecurityAuditService::recordTrusted` → `activity_logs`; detail key `session_id` is rejected as a credential key | Audit detail uses `lesson_id` |
| Phase 4 lesson | new pages must be registered in `access_control.php` `$ROLE_MAP` + `FeatureGate` file map | done for both new files |

## 2. What was built (§31 plan → as implemented)

**One idea:** a teacher saves a *lesson* sheet (class + subject + date + lesson number). The lesson rows are stored; the class/day row is then *derived* from all submitted lessons of that member on that day and written through the existing `AttendanceRecordService::mergeSheet`, so everything downstream keeps working unchanged.

| Item | File | Notes |
|---|---|---|
| Schema | `sql/076_attendance_sessions.sql` | 2 tables: `attendance_sessions` (UNIQUE class+date+subject+lesson_no, status `submitted|cancelled`, counts, cancel fields) and `attendance_session_records` (UNIQUE session+member). Additive, idempotent, MariaDB‑safe, no triggers. |
| Service | `admin/backend/services/LessonAttendanceService.php` | `deriveDayStatus()` (pure rule), `sheet()`, `sessionsForDay()`, `dayHasLessons()` / `assertClassDayWritable()`, `memberHistory()`, `saveSheet()` (one transaction: upsert lesson → replace its rows → derive day rows via `mergeSheet` → packet `submitted`; after commit `safeEvaluateMany` + audit), `cancelSession()` (Education; kept + flagged; re‑derives), teacher scoping helpers. |
| Web API | `admin/api_lesson_attendance.php` | `subjects`, `sheet`, `day`, `member` (GET) · `save`, `cancel` (POST, CSRF, rate‑limited). Roles: READ = super/school admin, Edu, teacher (own classes), HR, Info; WRITE = teacher (own class + subject) + Edu; cancel = Edu only. Returns `available:false` until flag + table. |
| Guards | `admin/api_attendance.php` (save + scan), `admin/api_education.php` (batch), `api/v1/routes/attendance.php` (`apiReplaceAttendanceRows`) | 409 `LESSON_DAY` (web) / 409 `WORKFLOW_REJECTED` (mobile) with one shared message. `get_class_attendance` now also returns `lessons[]` + `lesson_attendance_available` (additive). |
| Teacher UI | `admin/dashboards/teacher.php` | Third selector **"Sheet": "Whole day (class)" (default, unchanged flow) or a subject + L1…L6**. Same table, same buttons, same Save. On a lesson day the whole‑day sheet locks itself and shows "Day result is calculated from N lesson sheet(s)". Selector hidden when not available. |
| History | `admin/lesson_attendance.php?member_id=` | Per‑member lesson history (date EC, class, subject, lesson, teacher, outcome, note, cancelled flag); Edu can cancel here. Linked from the member profile risk panel (`admin/js/department_status.js`, Education block). |
| Registration | `admin/access_control.php`, `FeatureGate.php` | both new files mapped. |
| Tests | `tests/security/test_attendance_phase6.py` | 7 static + 4 live. |

### Derivation rule (owner Q6‑3)
present if present in **any** submitted lesson › else late › else excused › else absent; holiday only if every lesson is holiday; cancelled lessons do not count; no submitted lesson → no day row (draft never counts). Derived rows carry note `derived from N lesson(s)`.

### Deliberately NOT built (simplicity)
No third table, no new feature flag, no timetable UI, no separate "lesson packet"/second approval, no draft state on web (T‑2: save = submit), no mobile change (slice 6‑D later), no Reporting‑Centre dataset (can be added Info‑only later if asked), no change to the attendance‑taker or Mezmur flows.

## 3. Verification (§33)

* `php -l` clean on all touched PHP; `node --check` on teacher inline JS + `department_status.js`.
* Live (local MariaDB `ssms_test`, PHP dev server): two lessons → derived day rows `present / excused / absent / late` exactly per precedence; packet `submitted 4/1/1`; class/day web save → 409 `LESSON_DAY`; Education batch → 409; QR scan → 409; mobile save/submit → 409; cancel (Edu, reason ≥ 3 chars) → day re‑derived from the remaining lesson, rows kept, history shows `cancelled`; re‑saving the slot re‑opens it; HR/Info read history (200) and cannot write (403); attendance_taker 403; teacher outside assignment 403; lesson_no 9 → 422; incomplete roster → 422; bad date → 400; missing CSRF → 403; audit rows `Lesson Attendance Saved/Cancelled` in `activity_logs`.
* `tests/security/test_attendance_phase6.py`: **11 passed** standalone.
* Full security suite: **2273 passed, 0 failed, 458 skipped** (Phase 6 live tests skip inside the long run because of the shared login rate limit, as Phase 5 does — confirmed standalone).

## 4. Owner steps after `git pull`

1. phpMyAdmin → run `sql/076_attendance_sessions.sql` once (after 071–075).
2. Nothing else: `FEATURE_ATTENDANCE_V2` is already on; the "Sheet" selector appears on the teacher dashboard automatically. Teachers keep using "Whole day (class)" until they choose a subject.
3. Optional check: open a member profile → Department Status → "Lesson attendance history".

## 5. Rollback
Code: `git revert` of the Phase 6 commit (all changes are additive). Data: the two tables can stay (ignored when the code is reverted); never drop them — §13.

## 6. Open points (not decisions)
* Mobile (slice 6‑D): until planned, the app gets a clear 409 on a lesson day; teachers use the web lesson sheet. 
* Reporting Centre "lesson attendance" dataset (Info‑only) — add on request.
* `grade_submissions.teacher_id` of a derived day = the teacher who saved the last lesson (acceptable; visible in lesson history).
