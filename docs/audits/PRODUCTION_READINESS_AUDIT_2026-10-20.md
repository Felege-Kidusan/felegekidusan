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
