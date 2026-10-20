# Class-Day-Only Attendance + Yearly Calendar + "Day Off" — Implementation Report
**Date:** 2026-10-20 · Plan: `docs/planning/ATTENDANCE_CLASS_DAY_ENFORCEMENT_PLAN_2026-10-20.md` (rev 3) · Research: `ATTENDANCE_CLASS_DAY_CALENDAR_RESEARCH_2026-10-20.md`
**Owner decisions implemented (all five):** block unconfigured classes · 45-day backdating · per-entry "also cancel makeup classes?" choice · refuse Day Off when attendance exists · Education-only calendar writes. Feature name: **"Day Off"** (no "urgent"/"emergency" wording anywhere).

---

# Protocol §36 — Final Report

## 1. REQUEST
Force attendance to be taken only on class days taken from the timetable; no future attendance; past attendance only within a window; prepare the yearly timetable covering holidays; let Education mark a whole-school day off that can be reopened before the day passes; professional UI explaining the rules. Deep research first (delivered), then implementation after approval (P0–P4 approved).

## 2. CURRENT PROBLEM
Attendance could be recorded on any non-future date — weekends a class never meets, holidays, days the school is closed. No school calendar existed ("holiday" was only a row status); no backdating limit; no UI explaining any of this.

## 3. ROOT CAUSE
The system had date-blind save paths: only `FUTURE_DATE` was checked. Class-day knowledge lived in the timetable but was never consulted by attendance; there was no holiday/day-off layer at all.

## 4. SOLUTION
One rule engine (`SchoolCalendarService`) with two verdicts — `dayType()` (what kind of day is this, used by the Year Calendar grid so future days show Day Off/holiday) and `classDayStatus()` (can attendance be saved: dayType + no-future + 45-day window). Enforced in all three save paths (mobile API, web class/day + QR, lesson sheets) with machine codes + human EC-labelled reasons. Two additive tables: planned `school_holidays` + append-only `school_day_offs` (latest row per date wins; `cancel_makeup` per entry; writes only for `date ≥ today`; close refused when attendance exists). Year Calendar page for Education (holidays prep, Day Off form + reopen, unaccounted/not-configured classes). Web dashboards + the app show date status banners and disable save with the reason.

## 5. FILES CHANGED
- `sql/078_school_calendar.sql` — NEW: `school_holidays`, `school_day_offs` (idempotent, additive)
- `admin/backend/services/SchoolCalendarService.php` — NEW: rule engine + calendar writes (Education-only) + audit
- `admin/api_school_calendar.php` — NEW: reads (date_status/class_days/overview/holidays/day_offs/classes) + writes (save/delete holiday, mark/reopen Day Off); CSRF + rate limits; writes `edu_dept` only
- `api/v1/routes/attendance.php` — `apiAssertClassDay()` in both saves; `date_status` in the sheet GET
- `admin/api_attendance.php` — `$assertClassDay` closure in `save_attendance` + QR scan; `date_status` in `get_class_attendance`
- `admin/api_lesson_attendance.php` — save guard; `date_status` in `sheet`/`day`
- `admin/school_calendar.php` + `admin/js/school_calendar.js` — NEW Year Calendar page (EC year strip + month grid + holiday form + Day Off dialog with the makeup choice + reopen)
- `admin/js/school_calendar_widget.js` — NEW shared banner/save-guard for the dashboards
- `admin/dashboards/teacher.php`, `admin/dashboards/attendance_taker.php` — banner + save guard (consume `date_status`)
- `Mobile/wbws_flutter_app/lib/screens/attendance/attendance_screen.dart` — date_status banner (StatusBanner), 45-day picker window, save/submit guards, merged save-gate notifier
- `tests/security/test_school_calendar.py` — NEW: 7 static + 7 live tests (full decision matrix)
- `tests/e2e/seed.php`, `tests/e2e/provision_env.sh` — test world gets working-days rows; 070/075/076 re-applied after legacy extraction (fresh-provision FK ordering)
- `tests/security/test_timetable_builder.py`, `test_timetable_mobile.py` — fixtures restore (not delete) seeded rows; Addis-clock dates
- `docs/planning/ATTENDANCE_CLASS_DAY_ENFORCEMENT_PLAN_2026-10-20.md` rev 3, `docs/VISUAL_QA_GUIDE_2026-10-20.md`

## 6. LOGIC CHANGED
- **Save verdict:** `date ≤ today` AND `date ≥ today−45` AND class has configured working days AND (class-day OR makeup-on-date) AND not holiday AND not Day Off (unless `cancel_makeup=0` AND the class has a makeup that date).
- **Day Off lifecycle:** mark/reopen only while `date ≥ today` (Addis); mark refused with `ATTENDANCE_EXISTS` once any attendance row exists for the date (class/day or lesson); latest row per date wins (reopen = `is_off=0` entry).
- **Makeups** punch through `NOT_CLASS_DAY`/`HOLIDAY`/`DAY_OFF(cancel_makeup=0)`; hard-blocked only by `DAY_OFF(cancel_makeup=1)`, `NOT_CONFIGURED`, and the date windows.
- **Deviation from plan rev 2:** `OUT_OF_YEAR` check removed (not an owner rule; wrongly blocked today against tight `academic_years` windows — would have blocked all attendance on servers with such rows).
- Offline app saves unchanged (Telegram model); a queued save rejected by the rule fails at sync with the same code.

## 7. DATABASE CHANGES
`sql/078_school_calendar.sql` only (two new tables, `CREATE TABLE IF NOT EXISTS`, no existing table touched). Owner runs it in phpMyAdmin after 077. Re-runnable.

## 8. API CHANGES
- New `admin/api_school_calendar.php` (GET date_status/class_days/overview/holidays/day_offs/classes; POST save_holiday/delete_holiday/mark_day_off/reopen_day_off — `edu_dept` only).
- `GET` attendance reads gain additive `date_status` `{ok, code, label, detail, is_makeup}` (mobile sheet, web class/day, lesson sheet/day).
- Save paths return `422` with codes: `FUTURE_DATE`, `BACKDATE_LIMIT`, `NOT_CONFIGURED`, `NOT_CLASS_DAY`, `HOLIDAY`, `DAY_OFF` (+ `ATTENDANCE_EXISTS` on mark_day_off). Mobile/web/lesson identical.

## 9. SECURITY CHANGES
Calendar writes: role gate `edu_dept` only + CSRF + rate limits + full audit (`entity_type='school_calendar'`, incl. reason and the makeup choice). Reads for all attendance roles. No secrets; no change to auth/session model. Test asserts the words "urgent"/"emergency" never appear in the UI strings.

## 10. UI/UX CHANGES
- **Year Calendar** (`admin/school_calendar.php`): EC year strip with holiday/day-off counts, month grid with status chips (class day / makeup / Day Off / holiday / no class / not configured), click a day → mark Day Off (reason + "Also cancel makeup classes on this day?" default yes) or Reopen (only while the day has not passed); holidays panel with bulk-range add; "classes without working days" list (decision 1); legend + rules banner.
- **Teacher/Taker dashboards + app:** date status banner (e.g. "Day Off — classes do not hold", "This is not a class day for this class", "up to 45 days back") + save disabled with the reason; the app's EC date picker now opens only the last 45 days.

## 11. TESTING
- New `test_school_calendar.py`: **14/14** — day-off blocks web+mobile saves (`DAY_OFF`) → reopen restores → save works → re-mark refused (`ATTENDANCE_EXISTS`); 45-day edge OK at day 45 / `BACKDATE_LIMIT` at 46; `FUTURE_DATE`; `NOT_CONFIGURED` + overview listing; makeup exception + `cancel_makeup` 0/1 flip; holiday visible in the calendar grid for future days; teacher writes → 403, reads → 200.
- Full suite **2316 passed / 0 failed / 0 errors** (610 subtests), twice in a row (cross-run stability proven after fixing fixtures that deleted seeded rows).
- `flutter test` **616 passed**; `flutter analyze lib` **0 errors**.
- php -l clean on every touched PHP file.

## 12. REGRESSION CHECK
All prior surfaces re-run green: attendance phases 0–6, monthly rollups, timetable builder + mobile, academic intelligence, auth/idempotency, mezmur, reports. Existing attendance rows never modified. Pre-078 databases behave exactly as before (graceful).

## 13. REMAINING RISKS
- Rollups/warnings still count **days**, not lessons (owner chose to stay day-level; unchanged).
- `attendance_policies` does not yet carry the 45-day window (constant `BACKDATE_DAYS = 45`; can move to policy later if wanted).
- Mezmur has its own day rules (out of scope, unchanged).
- If a teacher saves offline for a date later closed as Day Off, the sync will refuse that queue item with `DAY_OFF` (visible in sync failures) — expected.
- Known open items carried over: finance hard delete, `api_teachers.php` CSRF-fail HTTP 200, CI zero-skips gate (needs owner decision).

## 14. ASSUMPTIONS
- "Class day" = the class's `timetable_class_days` weekday **or** a dated makeup class; classes without a configured row are blocked (decision 1) — Education must configure working days in the Timetable builder after `sql/078`.
- Day Off/holiday writes are prospective-only (`≥ today`), matching "reopen before the day passes"; history is immutable.
- `sql/078` will be run by the owner in phpMyAdmin together with 071–077 on production; on dev only 078 is additionally required.
