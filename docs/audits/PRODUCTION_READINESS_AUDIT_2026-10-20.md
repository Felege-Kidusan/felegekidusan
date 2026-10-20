# Production‑Readiness Audit — 2026‑10‑20 (after Phase 6 `3ad51cd`)

Scope requested by the owner: end‑to‑end production‑readiness audit (A→Z), every
workflow and user journey, bugs and security problems patched. This document is
the evidence record; the §36 report was delivered in chat.

## 1. Gates executed

| Gate | What | Result |
|---|---|---|
| A | `php -l` on all 396 PHP files; `node --check` on 59 JS files; inline `<script>` blocks of 34 PHP pages | 0 syntax errors (2 known regex false positives in `backend/academic_year.php`, `backend/calendar_system.php`) |
| B | Full automated suite before any change | 2273 passed / 0 failed / 458 skipped |
| C | Repo‑wide security scans (see §3) | 1 finding (F13), patched |
| D | Live journeys per role against the local stack (see §4) | all green after patches |
| E | Full suite after patches | **2277 passed / 0 failed**; phase 2–6 live suites re‑run standalone: 18/14/20/15/12 passed |

## 2. Findings and patches

| # | Severity | File | Problem (FACT) | Patch |
|---|---|---|---|---|
| F1 | Medium | `LessonAttendanceService.php` | After an Education cancel the derived class/day rows + packet were re‑written with the Education actor as `teacher_id` | re‑derivation always uses the lesson's own `teacher_id` |
| F2 | High (requirement §3 / Q6‑3) | same | Cancelling the *last* submitted lesson of a day left stale derived day rows and a `submitted` packet — day still counted and still locked the class/day sheet | derived rows (guarded `notes LIKE 'derived from %'`) removed when no submitted lesson remains; packet set to `incomplete`; class/day sheet writable again |
| F3 | Low | same | Unknown/inactive `subject_id` surfaced as a 500 (FK failure) | 422 "Unknown or inactive subject." |
| F4 | Medium | same | `upsertAttendance()` result ignored → a failed packet write could be committed with a lesson saved | failure raises inside the transaction → rollback |
| F5 | Low | same | Lesson sheet lacked open‑warning badges that class/day sheets show | `warning` per student via `AttendanceWarningService::openByMembers` |
| F6 | Low | `api_lesson_attendance.php` | `sheet` `lesson_no` unbounded | capped to `MAX_LESSON_NO` |
| F7 | Medium (privacy) | same | Teacher `member` history disclosed any member's name/history by id | 403 unless the member is enrolled in one of the teacher's classes |
| F8 | Low | same | Lesson sheet could be saved for a future date | 422 |
| F9 | Low | `dashboards/teacher.php` | Out‑of‑order responses when switching sheets quickly could paint the wrong roster; load errors left stale rows editable | request sequence token; rows cleared + sheet locked on error |
| F10 | Low | same | Double submit possible; after a `LESSON_DAY` 409 the stale sheet stayed editable | button guard/disable; reload on success and on `LESSON_DAY` |
| F11 | Low (UX) | `dashboards/attendance_taker.php` | Taker filled a sheet, then got a surprise 409 on save when the day already had lesson sheets | read‑only notice + lock on load; reload on `LESSON_DAY` |
| F12 | Low (UX) | `SubmissionService::detail()`, `dashboards/edu_dept.php` | Education review modal did not show which lesson sheets a derived day came from | `lessons[]` in detail payload; "Derived from lesson sheets" chips in the modal |
| F13 | Low‑Medium (information disclosure) | `.htaccess` | `sql/`, `docs/` (incl. audit evidence, `docs/audits/evidence/r2/affected_rows_backup.sql`), `tests/`, `scripts/`, `tools/`, `vendor/`, `Mobile/`, `.github/`, `database_schema.sql`, `*.md` were web‑reachable on the document root | Apache `RewriteRule … [F,L]` for those paths/extensions; guard test added (`test_deployment_tool_hardening.py`) |

Tests added/updated: `tests/security/test_attendance_phase6.py` (static rules for
F1–F12, live `test_05_cancelling_every_lesson_reopens_the_day`, future‑date 422,
unknown subject 422, teacher member‑scope 403, packet owner stays teacher),
`tests/security/test_deployment_tool_hardening.py::test_apache_denies_repository_artefacts`.

## 3. Security scans (Gate C) — no further findings

- SQL: no superglobals inside SQL strings; all 96 interpolated `->query("…$var…")`
  sites reviewed — `(int)` casts or internal whitelisted identifiers/fragments.
- No `eval`/`exec`/`system`/`shell_exec` on user input (only PDO `exec` in the
  rate limiter and a string check in the super‑admin page).
- Variable `include`/`require`: fixed whitelists only (`api/v1/index.php` route map,
  config loaders, CLI‑only `leak_detector.php`).
- Output: no direct echo of superglobals; pages use `e()`.
- Admin auth: `admin/access_control.php` requires login for every non‑public
  admin script; `$ROLE_MAP` covers all role‑sensitive pages; 20 unmapped files are
  login‑only with internal checks (`api_mobile.php` 410, `api_year_context.php`
  session + CSRF, `profile_image.php` session).
- CSRF: every admin POST handler references `requireCsrfForPost`/`validateCsrf`.
- Open redirects: all `header('Location:')` targets are constants, whitelisted
  paths (`user-save.php`) or `(int)` ids.
- File streaming: `member_file.php` (regex + `realpath`), `download_backup.php`
  (`basename` + `BackupService::resolveForDownload`, super_admin only),
  `GalleryService`, `/app/download` (server‑side configured path).
- Uploads: all `move_uploaded_file` call sites go through validating services
  (`MemberFileService`, `ProfileImageService`, `AppReleaseManager`,
  `GalleryService`, `MezmurArtService`); import restricted to `.xlsx` + finfo.
- `/api/v1`: fixed route whitelist; every route calls `apiRequireAuth()` except
  the by‑design public `app` (config/APK) and `telemetry`; `attendance_warnings.php`
  and `assessment_followups.php` are sub‑included after auth.

## 4. Live journeys executed (local stack, users 1–9)

- Every role (super_admin, school_admin, edu_dept, teacher, hr_dept, info_dept,
  mezmur_dept, attendance_taker) opened its dashboard + attendance warnings,
  monthly report, lesson history, follow‑ups, Reporting Centre, users page —
  HTTP 200, no PHP warnings/notices in the server log (super‑admin dashboard
  shows the sandbox's PHP 8.4 `E_STRICT` log line; production is PHP 8.2).
- Teacher: lesson sheet save (L1/L2), class/day refused with 409 `LESSON_DAY`,
  `sheet`/`day`/`member` reads, member outside scope → 403, future date → 422.
- Education: cancel one lesson (precedence re‑derived), cancel all lessons
  (day re‑opens, history kept, packet `incomplete`), review modal detail
  shows `lessons[]`, approve → return for correction → teacher re‑save →
  packet `submitted` again.
- Attendance taker: class/day save on a lesson day → 409 and read‑only lock.
- Mobile `/api/v1/attendance` save on a lesson day → 409.
- HR / Info: per‑member lesson history page renders.

## 5. Pre‑existing items NOT changed (need owner decision)

1. Class/day `save_attendance` (web + mobile) still accepts future dates.
2. `batch_attendance` endpoint has no UI caller (dead API; still guarded).
3. Education has no UI to *create* a lesson sheet (API allows `edu_dept`
   writes; teachers create sheets in practice).
4. No timetable UI (Q6‑9 — by decision).

## 6. Coverage statement (honest)

ANALYSIS INCOMPLETE for line‑by‑line reading: the repository is ~220k lines of
PHP/JS (514 web files) plus ~63k lines of Dart. Read line‑by‑line in this audit:
all Phase 6 files, `access_control.php`, `api/v1/index.php`, the file‑streaming
and download controllers, `SubmissionService::detail`, the review path in
`api_communication.php`, `.htaccess`. Everything else was covered by automated
syntax gates, the 2277‑test suite, repo‑wide pattern scans (§3) and the live
role journeys (§4) — not by a human‑style reading of every line. The Flutter app
was not re‑audited (SDK unavailable; no mobile change in Phase 6).

---

# PASS 2 — file‑by‑file continuation (same day)

Scope of this pass: the Education / attendance / grading API surface, the
mobile `/api/v1` routes, settings, impersonation, user management, login,
Mezmur, AI, notifications, groups, workflow library. Every finding below is
PATCHED and pinned by `tests/security/test_production_audit_pass2.py`
(13 static + 8 live). Suite after pass 2: **2290 passed, 0 failed** (see §11).

## 7. Findings and patches (A1–A14)

| # | Severity | File | Problem | Fix |
|---|----------|------|---------|-----|
| A1 | High | `admin/api_attendance.php` | Teachers could read/write class/day attendance for **any** class (`class_id` unchecked). | `$requireClassScope` closure: teacher must hold an active `teacher_assignments` row → else 403. Takers keep all classes (by design). |
| A3 | Medium | `admin/api_attendance.php` | `export_history` used `die()`; unhandled exceptions leaked as HTML. | JSON errors; outer `catch (Throwable)` → JSON 500 (no message disclosure). |
| A4 | High | `admin/api_education.php` | Legacy `record_attendance` / `batch_attendance` / `record_grade` were callable by teachers/takers, bypassed lesson‑day lock and packet pipeline. | TIER 2b: Education‑only (403), future date 422, lesson day → 409 `LESSON_DAY`. |
| A5 | Medium | `admin/api_education.php` | School‑wide enumeration (`get_unassigned_members/teachers`, `enrollment_overview`, `roster`, `search_members`, `list_teachers`) open to teachers/takers. | TIER 2c: Education‑only (403). Teacher/taker dashboards only use `get_enrolled_students` (unchanged). |
| A6 | Medium | web + mobile + legacy | Class/day attendance accepted **future dates** (pass‑1 item §5.1). | 422 everywhere (`FUTURE_DATE` code on mobile). Mezmur already refused. |
| A7 | Low | `api_education.php`, `api_subjects.php` | Write actions missing from `requirePostActions` (GET‑triggerable). | Added `toggle_class`, assessment‑type save/toggle/delete, `save_class_subject_duration`. |
| A8 | High (functional) | `api_subjects.php`, `api_education.php` | Assessment‑type save/toggle/delete read undefined `$userRole` → **always 403**, feature was dead for Education. | Read `$_SESSION['admin_role']`. Live round‑trip verified. |
| A9 | High (integrity) | `SubmissionService::upsertScore` | New `academic_records` were created for members **not enrolled** in the assessment's class (web `save_grades`, mobile `/grades/save`, `submit_marklist`). | `memberBelongsToClass()` guard on new rows only; callers report "Member N is not enrolled in this class — mark not saved." |
| A10 | High | `api_communication.php` `submit_marklist` | Trusted POST `class_id/subject_id`, no assignment check, bypassed the submitted/approved lock, blind `INSERT` duplicated packets. | Rewritten on the canonical path: class/subject from the assessment, active `teacher_assignments` (403), `teacherWriteRefusal` (409), scores via `upsertScore` in a transaction, packet via `upsertMarklist`, audit row. |
| A11 | Medium | `admin/api_settings.php` | `dept_save` (member‑code format, registration defaults), `system_info`, `clear_cache` had **no role check** — any logged‑in teacher/taker could change them. | 403 unless super/school/info/hr. Profile actions untouched. |
| A12 | Low (data) | `api/v1/routes/members.php` PUT | Partial name update (e.g. only `father_name`) rebuilt `full_name_am` from the payload alone → other name parts wiped. | Falls back to stored parts. Web `info_manage_member.php` already correct. |
| A14 | Medium (audit) | `admin/api_finance.php` | Ledger add / update / **hard delete** of transactions left no audit trail. | `financeAudit()` → `activity_logs` rows (`Finance Transaction Added/Updated/Deleted`, amount/status/date deltas; deleted row captured before the DELETE). Semantics unchanged (soft‑delete would need owner approval). |
| A13 | Medium (audit) | `admin/api_impersonate.php` | Role switch / restore (password‑less department powers) left **no audit trail**. | `activity_logs` rows `Role Switched` / `Role Restored` (from → to). |

A2 (`scan_member` writes no packet) left as pass‑1 noted: QR scans are
single‑member updates feeding the same class/day row; packet is created by
the sheet save.

## 8. Reviewed in pass 2 with no finding

`api_teachers.php` (all 14 cases scoped; create forces role=teacher),
`api_communication.php` other cases, `api_mezmur.php` (role + feature gate +
POST list + future‑date refusal in service), `api_ai.php` (aggregates only,
super‑admin config), `api_notifications.php`, `api_test_seed.php` (flag +
super admin), `backend/user-save.php` (ROLE_MAP super‑admin; role matrix;
self‑lockout guard), `backend/login.php` (rate limits, `password_verify`,
`session_regenerate_id`, session reset), `backend/groups_api.php` (login +
CSRF + ROLE_MAP HR), `backend/workflow.php` (library only),
`info_manage_member.php` (status rule, `$cur` fallbacks), `api/v1` routes:
`users` (self‑service only), `members` (PII tiers, teacher scoping),
`grades` (teacher class+subject checks, Edu‑only assessments/reviews),
`enrollment`/`teachers` (Education roles), `hr` (410 retired), `mezmur`,
`telemetry`/`app` (rate‑limited, bounded inputs), `auth`.

## 9. Pass‑2 items NOT changed (owner decision)

1. Mobile `/api/v1/mezmur`: legacy generic `attendance_taker` accounts may
   still save Mezmur sheets (documented migration allowance). Removing it
   would lock out any remaining legacy taker — decide when migration is done.
2. Teachers/takers can still read any class roster via `get_enrolled_students`
   (phones masked) — needed by the taker UI.
3. `api_teachers.php` CSRF failure returns HTTP 200 JSON (functionally safe).
4. `delete_assessment` only checks `academic_records`, not an (unlikely)
   empty `grade_submissions` packet.

## 10. Coverage statement after pass 2 (honest)

Read line‑by‑line in pass 2: `api_attendance.php`, `api_education.php`
(header + all gated cases), `api_subjects.php` (gates, assessment‑type,
grading, assessment CRUD headers), `api_communication.php` (`submit_marklist`,
review path), `api_impersonate.php`, `api_settings.php` (gates + dept/system
cases), `api_test_seed.php` header, `backend/login.php`, `user-save.php`
(authorisation section), `api/v1/routes/{users,members,grades,enrollment,hr,
auth,mezmur header,telemetry/app limits}`, `SubmissionService::upsertScore`,
`api_finance.php` (transactions), `api_timetable.php` + `api_assignments.php`
(gates), `user-delete.php` / `user-toggle.php` (self‑lockout + password
re‑verification), upload validation in `api_branding.php` / `GalleryService`.
ANALYSIS INCOMPLETE remains for: the large dashboard pages
(`edu_dept.php`, `hr-dept.php`, `info-dept.php`, `super-admin.php`, teacher /
taker JS beyond the Phase‑6 parts), `api_identity.php`,
`api_cms.php`, `api_branding.php`, `api_guardians.php`,
`api_import_members.php`, `api_reports*.php`, `backend/academic_year.php`,
`calendar_system.php`, the services not named above, and the Flutter app.
These are covered by syntax gates, the 2289‑test suite, repo‑wide pattern
scans and the live role journeys — not by a human‑style reading of every line.

## 11. Pass‑2 verification record

* `php -l` clean on every touched file; `node --check` unchanged (no JS edits).
* `tests/security/test_production_audit_pass2.py`: 21 passed (13 static, 8 live).
* Full suite: **2290 passed, 0 failed, 468 skipped** (`timeout 1700 python3 -m
  pytest tests -q --ignore=tests/mutation -p no:cacheprovider`, ~47 s).
* Live journeys re‑run this pass (users 1–9 on the local stack): teacher class
  scope + future date; taker all classes; TIER 2b/2c matrix (teacher, taker,
  Education); Education assessment‑type save→toggle→delete; teacher
  `save_grades` with an enrolled + a stranger member; `submit_marklist`
  submit → 409 on re‑submit → 403 on unassigned class; settings gate
  (teacher 403 / Information 200); mobile partial name PUT; School Admin
  role switch + restore audit rows; Finance add → update → delete audit rows.
* No schema change; no migration for the owner; shared‑hosting compatible.

---

# PASS 3 — dashboards, JS, remaining APIs, public pages (same day)

## 12. Method
* Role/CSRF/method gates read for every remaining `admin/api_*.php`,
  `backend/*.php`, `member_file.php`, `register_submit.php`, `member.php`,
  `logout.php`, `app_session.php`; `ROLE_MAP` cross‑checked.
* Repo‑wide SQL‑construction scan (`->query("… $var`, dynamic `ORDER BY`):
  every dynamic fragment is an int cast, a whitelist lookup or a constant.
* DOM‑XSS scan: a tokenizer (`/tmp/xss_scan.py`) extracted every template
  literal assigned to `innerHTML` / `insertAdjacentHTML` in all dashboards,
  pages and JS files and listed `${…}` interpolations that go through no
  escaper; each candidate was read by hand. `onclick="fn('${esc(x)}')"`
  patterns were traced into the receiving function (browser entity‑decodes
  attribute values, so the receiver must escape again).
* Upload handling (`api_branding.php`, `GalleryService`, `MemberFileService`)
  and the storage layout (private documents outside the web root,
  `uploads/.htaccess` denies scripts) re‑checked.

## 13. Findings and patches

| # | Severity | File | Problem → Fix |
|---|---|---|---|
| A15 | Medium (stored XSS) | `admin/dashboards/edu_dept.php` | `openTransfer(enrollId, name, code)` wrote `name`/`code` raw into `innerHTML` although the caller had escaped them inside the `onclick` attribute (entity‑decoded again by the browser) → a student name containing HTML executed in the Education console. Fixed: `esc()` inside the function; teacher initials also escaped. |

## 14. Reviewed with no finding
`api_identity.php`, `api_dept_takers.php` + `DeptTakerService` (department
attribution, 12‑char passwords), `hr_register_member.php` (policy, CSRF,
rate limit, transaction), `member_file.php` (role gate, whitelist, private
root, `nosniff`/sandbox CSP), `register_submit.php` (POST‑only, honeypot,
5/h per IP, prepared), `member.php` (QR verify, 30/10 min, coarse address),
`logout.php`, `app_session.php` (410), `super-admin.php` user table (escaped,
delete = confirm page → POST + password re‑verification), `dashboard.php`
header, finance/material/groups/reports/teacher/taker dashboards (all
interpolations escaped or numeric), `frontend/js/*` (escapers used
throughout).

## 15. Verification
`tests/security/test_production_audit_pass2.py` now 22 (14 static + 8 live);
full suite **2291 passed, 0 failed, 468 skipped**.

## 16. Coverage statement after pass 3 (honest)
Everything listed in §10 as unread was covered in pass 3 by *targeted*
reading (gates, writes, dynamic SQL, DOM sinks, uploads) — not by a
sequential reading of every line of the four 4 000‑line dashboards. Residual
ANALYSIS INCOMPLETE: non‑sink JS logic in the big dashboards (UI state,
charts), `api_reports*.php` / `api_report_views.php` query builders beyond
the sort/filter whitelists, `calendar_system.php`, `BackupService` content,
the Flutter app (SDK unavailable). Those remain covered by syntax gates, the
2291‑test suite and the live role journeys.
