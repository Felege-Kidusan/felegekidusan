# Attendance Alerting — Phase 2 implementation notes (2026-10-19)

Status: **server + web implemented, tested, pushed** (`2a571c1`; rollout verify/batch safeguards in the follow-up commit). Flutter screens / APK: **not built** (see §7).
Binding requirements: `ATTENDANCE_DEPT_STATUS_ALERTS_REQUIREMENTS_2026-10-19.md` §1–§14 (unchanged).
Plan reference: `ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN_2026-10-19.md` → Phase 2.

> **Moved 2026-10-23:** the rollout script is now `scripts/attendance_rollout_2026.php` (it was `tools/`; [RFC 0001](../rfc/0001-repository-structure.md), Step 2a). Run the commands below with `scripts/` instead of `tools/`. The rest of this record is unchanged.

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
4. **Verify the historical records (read-only):**
   `php tools/attendance_rollout_2026.php --verify`
   Prints, per department: rows with packets vs legacy rows (span, days, classes), future-dated rows, missing classes/members, members with no enrolment in the class, legacy rows with no recorder, class-/section-days where *every* student is absent (blank sheet or holiday saved as absences), and how many would-open warnings depend on legacy rows (with member ids). Exit 0 = clean, exit 1 = something flagged. Corrections are made by Education / Mezmur through their screens — the tool never edits or deletes attendance. Re-run until you are satisfied.
5. **Dry run:** `php tools/attendance_rollout_2026.php` → review "Would open" and the summary. Use `--department=edu|mezmur` to split.
6. **Apply in batches** (members are processed in id order; the tool prints the next cursor):
   ```
   php tools/attendance_rollout_2026.php --apply --limit=50
   php tools/attendance_rollout_2026.php --apply --limit=50 --after-id=<printed cursor>
   …repeat until "Nothing to scan"
   ```
   Safeguards built in: the plan for the batch is shown first and you must type `APPLY` (or pass `--yes` in a non-interactive shell); `--max-open=100` default caps new warnings per batch (abort before writing if the plan exceeds it; raise deliberately or use `--max-open=0`); `--max-errors=5` aborts the batch; a lock file prevents two runs at once; re-running a batch is idempotent; every apply writes an `Attendance Warnings Rollout` audit row with cursor, counts and opened ids. Rollout rows are flagged "system-generated at rollout" and send no bells.
7. Spot-check `/admin/attendance_warnings.php` as HR, Info, Education, Mezmur.

## 5. Deliberately Gregorian (per `EC_ONLY_AUDIT_2026-10-19.md` §6)

DB storage, `/api/v1` machine fields (`as_of`, window `from/to`, streak `dates[]`), CLI flag `--as-of=YYYY-MM-DD`. Every label shown to people is EC (`opened_ec`, `closed_ec`, `start_ec`, `absence_dates_ec`, reasons in English + Amharic). Event payloads no longer expose raw dates.

## 6. Owner decisions on the open questions (2026-10-19)

| # | Question | Decision | Implementation |
|---|---|---|---|
| Q-1 | Legacy `members.status = 'warning'` rows | **Leave existing rows unchanged.** | Nothing touches them. They are scanned by the rollout like any non-archived member (status is global, not a department status). |
| Q-2 | Historical rows without a packet | **Keep valid historical attendance eligible, after verifying the existing records.** | `LEGACY_ROWS_ELIGIBLE = true` stays. New read-only `--verify` mode (step 4 above) audits the rows and reports which would-open warnings depend on legacy rows. `AttendanceRiskService::$legacyRowsOverride` exists for that tooling only. |
| Q-3 | Backlog size | **Yes — `--limit=` batches, with safeguards.** | `--after-id=` cursor, `--max-open` (default 100), `--max-errors` (default 5), interactive `APPLY` confirmation / `--yes`, lock file, per-batch audit row, idempotent re-runs (step 6 above). |

## 7. Phase 2 remainder — Flutter + roster markers (2026-10-19, second commit)

Owner chose "Flutter screens" as the next step. Everything below is behind
`FEATURE_ATTENDANCE_V2` (`attendance_v2` in `GET /app/config`); with the
flag off the app behaves exactly like 1.6.10 (build 36).

### 7.1 Server (small, additive)

| Where | Change |
|---|---|
| `api/v1/routes/attendance.php` (mobile Edu roster), `admin/api_attendance.php` (web Edu sheet), `MezmurAttendanceService::fetchSectionSheet` (web + mobile Mezmur sheet) | every member row carries `warning: {id, kind, stage} | null` (one `AttendanceWarningService::openByMembers` query per sheet; no per-row queries) |
| `FeatureGate::mobileCapabilities` | adds `attendance_v2` |
| `FeatureGate::filterMobileTiles` | tile id `warnings` is gated by `attendance_v2` |
| `api/v1/core/app_release.php`, `app_release.example.php` | default Education tile list gains `warnings`; `latest_version 1.7.0`, `latest_build 37` |
| `frontend/js/mezmur.js`, `admin/dashboards/teacher.php`, `admin/dashboards/attendance_taker.php` | read-only "open warning" chip on the Mezmur web sheet row and on the teacher / attendance-taker Education sheet row (existing `rate-chip` / `badge-warning` classes, no new CSS) |

The deployed release config (`api/v1/app_release.local.php` / `.fkss_app_release.php`) is **not** in git. If its `tiles.education` list omits `warnings`, the app still shows the tile whenever `attendance_v2` is on (client-side append); adding `warnings` to that list is optional.

### 7.2 Flutter (`Mobile/wbws_flutter_app`, 1.7.0+37)

- `lib/services/api_service.dart` — `getAttendanceWarnings`, `getAttendanceWarningCounts`, `getAttendanceWarning`, `closeAttendanceWarning`, `getMemberAttendanceRisk`.
- `lib/screens/attendance_warnings/attendance_warning_widgets.dart` — pure helpers (`warningStageLabel/Color`, `warningRateText`, `warningInt`, `warningDepartmentLabel`, `kWarningOutcomeLabels`), `WarningPill`, `RowWarningMarker`.
- `attendance_warnings_screen.dart` — list with Open/Closed, kind filter, search, "Load more", per-department counts; `attendance_warning_detail_screen.dart` — detail, EC absence dates, month/year rates, history, other-department summary, "Member profile", close sheet (outcome + ≤500-char note; shown only when `can_close[dept]`). Pops `true` when a warning was closed so the list refreshes.
- Bell: `inbox_view_model.dart` `warningTargetId(n)` reads `target.warning_id`; `notification_center_screen.dart` opens the warning detail when present, else the member profile (unchanged behaviour for old alerts).
- Home tiles: `edu_home.dart` (`warnings` id, honours server tile list), `mezmur_home.dart` (staff only), `hr_home.dart`, `info_home.dart`.
- Roster marker (read-only): `attendance_screen.dart` (Edu class sheet) and `mezmur_attendance.dart` keep the row's `warning` key and render `RowWarningMarker`. Teachers / takers never get list or close access (server 403 is the gate).
- Member profile: `member_detail_screen.dart` "Attendance risk" block from `GET /members/{id}/attendance-risk`; silent on 403 / offline.
- `pubspec.yaml` 1.7.0+37, `AppConfig.appVersion/appBuild`, `RELEASE_NOTES.md` entry, `test/attendance_warnings_test.dart` (10 tests).

### 7.3 Verification (this commit)

- Flutter 3.27.4 SDK was installed in the sandbox for verification only: `flutter analyze` → **0 errors** (pre-existing infos/warnings only); `flutter test` → **607/607 passed** (includes `version_sync_test`, `inbox_parity_test`, new `attendance_warnings_test`). `tool/const_gate.py` PASS on all changed Dart files.
- Live API checks on the local test DB: Edu roster (mobile + web) and Mezmur sheet rows carry `warning` for a member with an open warning and `null` otherwise; `/app/config` reports `attendance_v2: true` for every staff role; `/attendance/warnings/counts` 200 for Edu/Mezmur/HR/Info and **403 for teacher**.
- `php -l` on all touched PHP; `tests/e2e/attendance_warning_unit.php` 85/85; full security suite **2234 passed / 0 failed / 437 skipped** (version pins in `test_auth_outbox_rollout_gates.py`, `test_comm_offline_first.py`, `test_mezmur_art.py` moved to 1.7.0+37, as on every release).

### 7.4 Owner steps — building the APK

1. `git pull` on the build machine (Flutter ≥ 3.44 / Dart ≥ 3.12 per `pubspec.lock`; no dependency changes).
2. `cd Mobile/wbws_flutter_app && flutter pub get && flutter test` (the build script already does this) then `flutter build apk --release` (or the usual split-per-ABI command) and sign as before.
3. Upload build 37 through the existing release page; `latest_version`/`latest_build` in the deployed release config should become `1.7.0` / `37`. Keep `min_build` unchanged (no forced update).
4. Production flag: the mobile screens appear only when `FEATURE_ATTENDANCE_V2` is true on the server (same flag as the web pages, §4).

### 7.5 Still not done

- Phase 3 (reopen/reject with reason + recalc), Phase 4 (monthly roll-up + shared monthly partial in all four dashboards, including HR), Phase 5 (make-up queue), Phase 6 (lesson-level) — each still needs its own approval.
