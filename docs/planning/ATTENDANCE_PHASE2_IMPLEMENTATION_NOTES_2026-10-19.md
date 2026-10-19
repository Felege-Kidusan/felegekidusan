# Attendance Alerting — Phase 2 implementation notes (2026-10-19)

Status: **server + web implemented, tested, pushed** (this commit). Flutter screens / APK: **not built** (see §7).
Binding requirements: `ATTENDANCE_DEPT_STATUS_ALERTS_REQUIREMENTS_2026-10-19.md` §1–§14 (unchanged).
Plan reference: `ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN_2026-10-19.md` → Phase 2.

## 1. What was built

| Area | Files | Notes |
|---|---|---|
| Schema | `sql/072_attendance_warnings.sql` | `attendance_warnings` + `attendance_warning_events`. MariaDB-safe, idempotent, no triggers/events. **Owner runs in phpMyAdmin.** |
| Risk engine | `admin/backend/services/AttendanceRiskService.php` | `riskFor($conn, dept, memberId, asOf, ecMonth)` → streak / EC-month rate / EC-year rate / band. Edu + Mezmur adapters, fully independent. |
| Warning lifecycle | `admin/backend/services/AttendanceWarningService.php` | `evaluate()` (open / escalate in place / invalidate / low-month), `close()` with outcome, `list/detail/counts/memberSummary`, bell to dept + HR + Info only. Never writes `members.status`. |
| Web API | `admin/api_attendance_warnings.php` | `GET list|detail|counts|member_summary|risk`, `POST close` (CSRF, rate-limited, audited `Attendance Warning Closed`). |
| Mobile API | `api/v1/routes/attendance_warnings.php`, `api/v1/routes/members.php` | `GET /attendance/warnings`, `/attendance/warnings/counts`, `/attendance/warnings/{id}`, `POST /attendance/warnings/{id}/close`, `GET /members/{id}/attendance-risk`. Backward compatible — old APK keeps working. |
| Hooks (evaluate after write) | `admin/api_attendance.php` (web save), `api/v1/routes/attendance.php` (mobile **submit only**, drafts ignored), `admin/api_education.php` (edits), `admin/api_mezmur.php` + `api/v1/routes/mezmur.php` (Mezmur), `admin/api_department_status.php` + mobile members (reactivation re-evaluates) | All wrapped in `safeEvaluate*` — a failure never breaks the save. Flag-gated by `FEATURE_ATTENDANCE_V2`. |
| UI | `admin/attendance_warnings.php` + `admin/js/attendance_warnings.js`; "Open Attendance Warnings" card on Info, HR, Education, Mezmur dashboards; member profile block (`admin/js/department_status.js`) | Replaces the old "At-risk Students" card. Close dialog = outcome radio (Contacted / Excused / Escalated / Deactivated / No Action) + optional note. |
| Legacy removal | `api_attendance.php` (`attendance_alert` producer), `AttendanceSummaryService.php` (`attendance_issue`), `workflow.php` matrix | Replaced by `attendance_warning` notification type (registered in `NotificationCenterService`, `access_control.php`, `FeatureGate`). |
| Rollout | `tools/attendance_rollout_2026.php` | CLI only. `--department=edu|mezmur`, `--as-of=`, `--limit=`, `--quiet`, `--apply`. Dry run default. Rows get `source='rollout'`, **no bells**. Idempotent. |

## 2. Engine rules (as implemented and unit-tested)

- Session unit = class/day (Edu) or session/day (Mezmur). Only packets with status `submitted` or `approved` count; `draft`, `incomplete`, `revision_needed`, `rejected` days are ignored.
- Legacy rows with no packet **count** (`LEGACY_ROWS_ELIGIBLE = true`) — see open question Q-2.
- A day is absent only if **all** rows that day are absent; `late`, `excused`, `present` break the streak. `holiday` rows are ignored. Days without eligible sessions are skipped (streak is not broken by them).
- Inactive department periods are excluded; the reactivation day is the floor of the streak.
- Rate = (present + 0.5·late) / sessions; `NULL` when no sessions; bands ≥90 good / ≥75 ok / ≥50 low / <50 critical.
- Thresholds from `attendance_policies` per department (5 / 8 / 10, 75 %, min 3 sessions, auto-deactivate off). The same warning escalates **in place** (standard → prolonged → critical → auto_deactivated); each escalation sends one bell.
- Low-month warnings are per EC month and are invalidated when the month recovers.
- `members.status` is **never** written by any of this code.

## 3. Verification

- `tests/e2e/attendance_warning_unit.php` — 85/85 (fixture matrix: late/excused breaks, multi-class day, holiday gaps, inactive gap, reactivation reset, drafts/revision ignored, min_sessions guard, escalation in place, dedupe, Mezmur isolation).
- `tests/e2e/department_status_unit.php` — 38/38 (unchanged).
- `tests/security/test_attendance_phase2.py` — 18/18 (static guards + live: web save opens warning + bell to edu/hr/info only; list scoping per role; admins 403 on close; EC-only JSON; mobile submit triggers / draft does not; rollout dry-run → apply → idempotent; page shells).
- Full `tests/security` suite — **2234 passed, 437 skipped, 0 failed**.
- Playwright smoke (local): HR/Info/Edu/Mezmur dashboards show the card with correct counts; warnings page lists, filters, detail dialog, close flow (Edu closes with "Contacted"); member profile block renders; zero console errors.
- Guard updates: `test_attendance_phase0.py::test_legacy_alert_producers_removed` now asserts the legacy producers are gone (it previously pinned the removed dedupe SQL); `test_class_dependency_closure` satisfied by explicit `require_once` in the mobile routes.

## 4. Owner steps (production, in order)

1. `git pull`.
2. phpMyAdmin: run `sql/072_attendance_warnings.sql` (071 must already be applied).
3. Confirm `define('FEATURE_ATTENDANCE_V2', true);` in the environment file.
4. Rollout back-fill from the server shell (no cron needed):
   - `php tools/attendance_rollout_2026.php` → review counts (dry run, writes nothing)
   - `php tools/attendance_rollout_2026.php --department=edu --apply`
   - `php tools/attendance_rollout_2026.php --department=mezmur --apply`
   - rows are marked "system-generated at rollout" and send no bells.
5. Spot-check `/admin/attendance_warnings.php` as HR, Info, Education, Mezmur.

## 5. Deliberately Gregorian (per `EC_ONLY_AUDIT_2026-10-19.md` §6)

DB storage, `/api/v1` machine fields (`as_of`, window `from/to`, streak `dates[]`), CLI flag `--as-of=YYYY-MM-DD`. Every label shown to people is EC (`opened_ec`, `closed_ec`, `start_ec`, `absence_dates_ec`, reasons in English + Amharic). Event payloads no longer expose raw dates.

## 6. Open questions (not decided — need owner)

- **Q-1 legacy `warning` statuses** (C-9): members whose `members.status = 'warning'` from the old automation — leave as is, or HR/Info bulk-set to `active` via the existing status tool? Nothing in Phase 2 touches them.
- **Q-2 legacy rows without packets**: currently counted as eligible sessions (so history before the packet system still produces streaks at rollout). If you prefer rollout to start from "packet era only", set `LEGACY_ROWS_ELIGIBLE = false` before running `--apply`.
- **Q-3 who sees the rollout warnings first**: the dry-run count tells you the opening backlog; if large, consider `--limit=` batches so departments can close them gradually.

## 7. Not done in this commit (Phase 2 remainder)

- **Flutter**: warnings screen (list / detail / close) for HR, Info, Edu, Mezmur; teacher read-only indicator; bell deep-link to warning detail; **APK build**. The sandbox has no Flutter SDK, so nothing was written rather than shipping untested Dart. Server endpoints are live and documented in §1; the current APK is unaffected.
- **Teacher / attendance-taker web sheet indicator** ("open warning" marker on the roster row). Deferred together with the Flutter indicator so both read the same `GET /members/{id}/attendance-risk` contract.
- Phase 3 (reopen/reject with reason + recalc), Phase 4 (monthly roll-up + shared monthly partial in all four dashboards, including HR), Phase 5 (make-up queue), Phase 6 (lesson-level) — each still needs its own approval.
