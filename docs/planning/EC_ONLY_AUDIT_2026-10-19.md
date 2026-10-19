# Ethiopian-Calendar-Only Audit — Attendance Screens (Phase 0, 2026-10-19)

Scope: requirement §10 of `ATTENDANCE_DEPT_STATUS_ALERTS_REQUIREMENTS_2026-10-19.md`
("Ethiopian calendar ONLY in all user-facing output, system-wide"). This audit
covers the attendance-related screens and the calendar switch. Everything
below is FACT from the repo unless marked otherwise.

## 1. Calendar mode is now fixed

| Item | Before | After (Phase 0) |
|---|---|---|
| `admin/backend/calendar_system.php::wbws_get_calendar_mode()` | read `dept_settings.calendar_mode` (could be `gregorian`) and cached in `$_SESSION['wbws_calendar_mode']` | always returns `'ethiopian'`; DB row and session value are ignored (session is refreshed to `ethiopian`) |
| `admin/dashboards/super-admin.php` Settings → Calendar System | two radios (Ethiopian / Gregorian) + `saveCalendarMode()` JS posting `save_calendar_mode` | fixed card: "Ethiopian calendar — system-wide, not configurable"; JS removed |
| `admin/api_settings.php` `save_calendar_mode` | never existed (UI was dead — "Unknown action") | unchanged; test pins that the action is not accepted and the DB row stays `ethiopian` |
| `admin/js/wbws-calendar.js` | `MODE` from `WBWS_CALENDAR_MODE` (emitted by `wbws_calendar_scripts($conn)`) | unchanged — the PHP side now always emits `ethiopian`, so `MODE==='ethiopian'` everywhere |

Effect: every `input[type=date]` auto-converted by `wbws-calendar.js`, every
`fmtDate / fDate / fmtDateTime / WBWSCalendar.formatDate(...)` call and every
PHP `wbws_format_date()` call renders Ethiopian dates. No deployment can flip
back to Gregorian.

## 2. Coverage — where the calendar scripts are loaded

`wbws_calendar_scripts($conn)` is included in: `attendance_taker.php`,
`teacher.php` (×2), `edu_dept.php`, `info-dept.php`, `hr-dept.php`,
`super-admin.php`, `school_admin.php`, and `frontend/layouts/base.php:186`
(Mezmur front-end). `frontend/js/mezmur.js:52` and
`admin/js/attendance_analytics_hub.js:57` prefer `WBWSCalendar` and only fall
back to `toLocaleDateString` when the global is missing (it is not missing on
those pages).

## 3. Raw-date rendering fixed in Phase 0

| File:line | Was | Now |
|---|---|---|
| `admin/dashboards/attendance_taker.php:630` | `${h.attendance_date}` in history table | `fmtDate(h.attendance_date)` (guarded) |
| `admin/dashboards/info-dept.php:4067` | `r.attendance_date` in member attendance detail | `fmtDate(r.attendance_date)` (guarded) |
| `admin/dashboards/info-dept.php:3979` | weekly chart labels `w.day.slice(5)` (Gregorian `MM-DD`) | `WBWSCalendar.formatDate(w.day,'short')` (guarded) |

## 4. Residuals (not user-visible dates, or deliberately left — owner decision)

| File:line | What | Why left |
|---|---|---|
| `attendance_taker.php:636` | `editPastAttendance(classId, '${h.attendance_date}')` | ISO value passed as an identifier to a handler, not displayed |
| `frontend/js/mezmur.js:886, 2410` | `Mezmur.jumpToDate(esc(x.attendance_date))` | same — identifier, display uses `fmtDate` on the adjacent cell |
| `frontend/js/mezmur.js:2655` (Mezmur submissions CSV), `admin/dashboards/edu_dept.php:3483` (Education submissions CSV) | CSV export writes the ISO `attendance_date` | data-interchange export; **question for owner**: should CSV/XLSX exports carry EC dates too (planned to be unified in Phase 4 monthly reports / Reporting Centre exports)? |
| `edu_dept.php:4127`, `mezmur.js:2946` | `new Date().toISOString().slice(0,10)` in **file names** | file-name stamp, not displayed content |
| Mobile app (`Mobile/wbws_flutter_app/lib/utils/ethiopian_calendar.dart`) | already EC | not touched in Phase 0; re-checked in the Phase 2 APK |

## 5. Tests

`tests/security/test_attendance_phase0.py`:
- static: `wbws_get_calendar_mode` has no DB read and returns `'ethiopian'`; super-admin page has no `saveCalendarMode` / `value="gregorian"`; attendance screens no longer render the raw strings listed in §3;
- PHP fixture: session `gregorian` + fake connection returning `gregorian` → function still returns `ethiopian` and rewrites the session value;
- live: super-admin Settings page has no switch; `save_calendar_mode` is rejected and `dept_settings.calendar_mode` stays `ethiopian`.

## 6. Export & remaining-display sweep (owner instruction 2026-10-19: "EC everywhere, exports included")

Owner closed the §4 question: every export and every remaining display now shows Ethiopian dates. Storage stays Gregorian.

| Surface | Before | After |
|---|---|---|
| Reporting Centre CSV / XLSX (`ReportExportService::present`) | raw `YYYY-MM-DD` for every `type=date` column | `ethio_export_date` / `ethio_export_datetime` |
| Reporting Centre grid, filter chips, date pickers (`reports_v2.js`, `reports_v2.php`) | ISO cells, native Gregorian `<input type=date>` | EC cells (`fmtDate`), shared `wbws-calendar.js` pickers (page shell still has no DB access) |
| Reporting Centre "Registration month" bucket (`ReportRegistry`) | `DATE_FORMAT(%Y-%m)` Gregorian | `sqlEcMonthBucket()` → `2019-02 Tikimt` (verified against the PHP converter for 1 491 consecutive days incl. Pagume and the 11/12 Sept boundary) |
| Information PDF reports (`PdfReportService`) | Window / Generated / trend dates / member rows Gregorian | all EC |
| QR roster PDF, Report-card XLSX, Executive XLSX "Exported/Generated" stamps | `date('Y-m-d H:i')` | EC date + time |
| Executive HTML report "Report Date" | Gregorian + ISO | EC long + `dd/mm/yyyy ዓ.ም.` |
| AI report prompt date (`api_ai.php`) | Gregorian | EC (labelled) |
| Education submissions XLSX (`edu_dept.php`) | `attendance_date`, `updated_at`, 14-day `date` ISO | `fD` / new `fDT` |
| Mezmur submissions XLSX/CSV + analytics CSV (`mezmur.js`) | `attendance_date`, `updated_at`, `last_attended` ISO | `fmtDate` / new `fmtDateTime` |
| School Admin member CSV + XLSX | `created_at` raw | `fmtDate` |
| Teacher / Attendance-taker sheet subtitle "Date: 2026-10-19" (missed in §3) | ISO | EC long |
| HR + Info system activity feed, HR New-members list, Family Link timestamps, AI chat export stamp | `toLocaleString()` Gregorian | EC via `WBWSCalendar` |
| Super Admin telemetry + failure-intelligence tables (`app_telemetry.js`, `failure_intelligence.js`) | raw timestamps | `fmtTs` EC date + time |

New PHP helpers in `admin/backend/ethiopian_date.php`: `ethio_export_date()`, `ethio_export_datetime()`, `ethio_export_month()` (empty → '', unparsable → raw, never throws).
Guard test: `tests/security/test_ec_only_exports.py` (14 checks, incl. a repo-wide scan that any `toLocaleDateString`/`toLocaleString` on a date sits under a `WBWSCalendar` guard).

Deliberately unchanged: **file-name stamps** (`FKSS_Members_2026-10-19.xlsx` etc.) stay Gregorian ISO so files sort correctly on disk — say so if you want EC there too. Mobile app already renders EC (`formatGregorianAsEthiopian`). API JSON payloads stay ISO (machine interchange).
