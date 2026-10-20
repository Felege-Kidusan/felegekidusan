# Implementation Plan — Class-Day-Only Attendance, Yearly Calendar, "Day Off" Days
**Date:** 2026-10-20 (rev 2 — owner decisions applied) · **Status: PLAN COMPLETE — awaiting implementation approval**
Research: `ATTENDANCE_CLASS_DAY_CALENDAR_RESEARCH_2026-10-20.md` · Change log at the bottom.

**Naming:** the override feature is called **"Day Off"** (Amharic label proposal: `ዕረፍት ቀን`). The words "urgent" and "emergency" do not appear in the UI. Technical table name `school_day_offs`.

---

## 1. The rule (final, with owner decisions)

A teacher can save attendance for (class, date) only when **all** of:

1. **Date ≤ today** (unchanged).
2. **Date ≥ today − 45 days** — backdating window **45 days** (owner decision 2). Refusal code `BACKDATE_LIMIT`.
3. The class's timetable working days are **configured** (`timetable_class_days` row exists for the active year) — otherwise the class is **blocked entirely** until Education configures it (owner decision 1). Refusal code `NOT_CONFIGURED`.
4. The date's weekday is one of that class's working days and falls inside the active academic year. Refusal codes `NOT_CLASS_DAY` / `OUT_OF_YEAR`.
5. The date is not a **planned holiday** (`school_holidays`). Refusal code `HOLIDAY`.
6. The date is not marked **"Day Off"** (`school_day_offs`, latest entry per date wins). Refusal code `DAY_OFF`.
7. **Makeup classes** (dated lessons) allow attendance for their class on an otherwise blocked date — **except** when the Day Off entry was created with **"also cancel makeup classes" = yes** (owner decision 3: the department chooses per Day Off). Refusal code `DAY_OFF_MAKEUP`.

Every refusal returns the machine code + a human reason (EC date + plain language) that the UI shows; the save button itself explains before anyone taps it.

## 2. Data model (new, additive — `sql/078_school_calendar.sql`, owner runs in phpMyAdmin)

```sql
school_holidays        -- planned off days, prepared for the whole year (Education dept only)
  id, title, title_am, date_from, date_to,      -- single day = same date both
  type ENUM('holiday','vacation','closure','other'),
  created_by, created_at, updated_at

school_day_offs        -- append-only; LATEST row per date wins ("Day Off" / reopen)
  id, day_date,
  is_off TINYINT(1),                            -- 1 = mark Day Off, 0 = reopen (undo)
  cancel_makeup TINYINT(1) NOT NULL DEFAULT 1,  -- "also cancel makeup classes on this day?"
  reason, created_by, created_at
```
- Idempotent (`CREATE TABLE IF NOT EXISTS`), MariaDB-safe, no existing table touched.
- **Write rule (both tables): `day_date`/`date_from ≥ today` (Addis time)** — a Day Off can be marked or reopened only while the day is not over (owner's original rule). A date that has passed is immutable history.
- **Block-close rule (owner decision 4):** marking a Day Off is refused if attendance rows already exist for that date (`code: ATTENDANCE_EXISTS`).
- Every write → `activity_logs` (`entity_type='school_calendar'`) with before/after.

## 3. Core service — `SchoolCalendarService` (single choke point)

```php
classDayStatus(classId, date): {ok, code: 'OK'|'FUTURE_DATE'|'BACKDATE_LIMIT'|'NOT_CONFIGURED'|
   'NOT_CLASS_DAY'|'OUT_OF_YEAR'|'HOLIDAY'|'DAY_OFF'|'DAY_OFF_MAKEUP',
   label: '…EC label…', detail: 'Day Off: <reason>'|'Holiday: Fasika'|…}
classDaysForMonth(classId, ecYear, ecMonth): [date => status]   // fast path for UI grids
dayOffStatus(date): {is_off, cancel_makeup, reason, can_change: date >= today}
```
- `BACKDATE_DAYS = 45` as a named constant (documented; can later move into `attendance_policies` if the school wants it configurable — not in scope).
- Makeup rule: `classDayStatus` accepts a class on a blocked date iff `timetable_makeup_classes` has a row for (class, date) AND the winning `school_day_offs` row has `cancel_makeup = 0`.
- **Deployment-safe:** if the two new tables don't exist → behaviour identical to today (same pattern as `TimetableService::schemaReady`).

## 4. Enforcement points (all save paths; 422 + code + message)

| Path | File |
|---|---|
| Mobile class/day save | `api/v1/routes/attendance.php` (extends the existing `FUTURE_DATE` check) |
| Web class/day + QR | `admin/api_attendance.php` |
| Web lesson sheets | `admin/api_lesson_attendance.php` `saveSheet` (same day rule; sheets stay assignment-based) |
| Read APIs | `GET sheet`/`day` gain `date_status` so UIs explain before saving |

Mehmur and Finance are out of scope. Existing rows are never modified.

## 5. UI (professional, EC-first)

1. **Date selector** (web + mobile): status chips —
   ✅ **Class day** (green) · 🗓 **Class day — past** (amber: "recording a past class day, up to 45 days back") · ⚜ **Class day — makeup** (teal: "makeup class day") · ⬜ **Not a class day** (grey) · ⚙ **Not configured** (grey: "Education has not set this class's working days yet") · 🔵 **Day Off** (blue-grey, disabled + reason) · 🟣 **Holiday** (disabled + name) · 🔒 **Future / older than 45 days** (disabled + rule text). Banner + legend explain the rules in one line each. All dates EC (`ጥቅምት 12, 2019 ዓ.ም.`).
2. **Year Calendar page** (`admin/school_calendar.php`, **Education dept only** — owner decision 5):
   - full-year EC month grid with the same statuses;
   - **"Prepare the year"**: bulk-enter holidays/vacations (ranges + single days);
   - **"Days not yet accounted for"** check (class days vs expected; classes missing working-days highlighted — supports decision 1);
   - **"Mark day off"** form per upcoming/today date: reason (required) + a clearly-worded choice **"Also cancel makeup classes on this day?"** (default: yes; the department can switch to no — owner decision 3) + a **"Reopen"** button on Day-Off days while `date ≥ today`, greyed out afterwards;
   - closing is refused with a clear message if attendance already exists for the date (owner decision 4).
3. **Monthly Attendance grid**: day chips on the existing grid so empty days explain themselves.

## 6. Phases (each needs explicit owner approval)

| Phase | Content | Mobile? |
|---|---|---|
| **P0** | `sql/078` + `SchoolCalendarService` + enforcement in the 3 save paths + `date_status` in read APIs + tests | Server only (rules apply to app traffic immediately) |
| **P1** | Year Calendar page (holidays, Day Off + makeup choice + reopen, unaccounted-days, not-configured classes list) | No |
| **P2** | Web attendance date pickers + banners (edu/teacher/taker/lesson pages) | No |
| **P3** | Mobile attendance date picker statuses + explanations | **Yes (mobile scope per G-4)** |
| **P4** | Full test pass, VISUAL_QA update, §36 report | — |

## 7. Testing plan
- Static: rule + 45-day constant present in every save path; `school_day_offs` idempotent; EC-only labels; "urgent"/"emergency" wording absent from UI strings.
- Live matrix: {class day, weekday-without-lesson, unconfigured class, holiday, Day Off, Day Off with makeups allowed, makeup-only day, 46-days-past, future, out-of-year} × {mobile, web, lesson sheet} — assert code + message per cell; close-day with existing attendance → `ATTENDANCE_EXISTS`; reopen after day passes → refused.
- Regression: rollups/warnings untouched for existing data; `flutter test` for P3.

## 8. Owner decisions (2026-10-20) — LOCKED
1. **Unconfigured class → blocked** until Education sets working days (no Sat+Sun fallback).
2. **Backdating limit = 45 days.**
3. **Day Off form asks "Also cancel makeup classes on this day?"** — the department decides per entry. Feature renamed **"Day Off"** (no "urgent"/"emergency" wording).
4. **Marking a Day Off is refused if attendance already exists** that day.
5. **Education dept only** prepares holidays AND marks/reopens Day Offs (no super_admin/school_admin write; can be granted later by owner decision).

## 9. Deployment order (shared hosting, owner executes)
1. `git pull` → app keeps working (graceful before SQL).
2. Run `sql/078_school_calendar.sql` in phpMyAdmin → rules activate.
3. Education opens Year Calendar → enters the year's holidays → configures working days for every class (unconfigured classes stay blocked).
4. (P3) build the APK from `main` as usual.

## 10. Out of scope (recorded)
Per-lesson timetable-bound attendance (Option B/C research); mezmur calendar rules; configurable backdate window; holiday/super_admin calendar write access.

## Change log
| Date | Change |
|---|---|
| 2026-10-20 rev 1 | Initial plan (5 open questions). |
| 2026-10-20 rev 2 | Owner decisions 1–5 applied: block unconfigured classes (`NOT_CONFIGURED`), 45-day backdate limit, per-entry "cancel makeup classes?" choice, `ATTENDANCE_EXISTS` guard, Education-only calendar rights; feature renamed **"Day Off"** (`school_day_offs`, `is_off`, `cancel_makeup`). |
