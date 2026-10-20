# Implementation Plan — Class-Day-Only Attendance, Yearly Calendar, Emergency Off-Days
**Date:** 2026-10-20 · **Status: PLAN ONLY — awaiting owner approval** (research: `ATTENDANCE_CLASS_DAY_CALENDAR_RESEARCH_2026-10-20.md`)
**Owner rules locked in:** day-level attendance stays (no per-lesson mobile); attendance only on **class days from the timetable**; no future attendance (already enforced); past attendance only on class days; yearly calendar covers holidays; edu dept can force a whole-school day off (reopenable **before the day passes**); professional UI that makes the rules obvious.

---

## 1. The rule (one paragraph)
A teacher can save attendance for (class, date) only when **all** of: (1) date ≤ today; (2) the date is a **class day** for that class — its weekday is in the class's timetable working days (`timetable_class_days`), inside the active academic year; (3) the date is not a **planned holiday** (`school_holidays`, entered when the year is prepared); (4) the date is not **closed by an override** (`school_day_overrides`, edu-dept emergency closure). Makeup classes (dated lessons) count as class days for their class. Every refusal returns a machine code + human reason shown in the UI.

## 2. Data model (new, additive — `sql/078_school_calendar.sql`, owner runs in phpMyAdmin)

```sql
school_holidays        -- planned off days, prepared for the whole year
  id, title, title_am, date_from, date_to,      -- single day = same date both
  type ENUM('holiday','vacation','closure','other'),
  created_by, created_at, updated_at

school_day_overrides   -- append-only emergency log; LATEST row per date wins
  id, day_date, is_closed TINYINT(1),           -- 1 = force off, 0 = reopen
  reason, created_by, created_at
```
- Both idempotent (`CREATE TABLE IF NOT EXISTS`), MariaDB-safe, no existing table touched.
- **Write rule:** `day_date >= today` only (Addis time) — close/reopen are for today-and-future, exactly like Arbor's "add closures before the day" and matching the owner's "reopen before the day passes". After the day passes the record is history and immutable.
- All writes to `activity_logs` (`entity_type='school_calendar'`).

## 3. Core service — `SchoolCalendarService` (single choke point)

```php
classDayStatus(classId, date): {ok: bool, code: 'OK'|'FUTURE_DATE'|'NOT_CLASS_DAY'|
   'HOLIDAY'|'SCHOOL_CLOSED'|'OUT_OF_YEAR', label: '…EC label…', detail: 'Holiday: Fasika'|'Closed: unexpected day off'}
classDaysForMonth(classId, ecYear, ecMonth): [date => status]   // fast path for UI grids
isClosedSchoolWide(date): bool                                   // latest override
```
- Reads `timetable_class_days` (fallback to Sat+Sun default when a class has no row — see Q3), `academic_years`, `school_holidays`, `school_day_overrides`.
- **Deployment-safe:** if the two new tables don't exist yet → `ok` behaves exactly like today (graceful, same pattern as `TimetableService::schemaReady`). Rule hardens only after `sql/078` is applied.

## 4. Enforcement points (all save paths; refusal = 422 with code + message)

| Path | File |
|---|---|
| Mobile class/day save | `api/v1/routes/attendance.php` (next to the existing `FUTURE_DATE` check) |
| Web class/day + QR | `admin/api_attendance.php` |
| Web lesson sheets | `admin/api_lesson_attendance.php` `saveSheet` (class-day check only — sheets stay assignment-based per owner's "stay like that") |
| Read APIs | `GET sheet`/`day` responses gain `date_status` so UIs can explain before saving |

Mehmur submissions and Finance are **out of scope** (own workflows). Existing rows are never modified.

## 5. UI (professional, EC-first, shared components)

1. **Date selector** (web + mobile): each day chip carries a status + icon + color —
   ✅ **Class day** (green, active) · 🗓 **Class day — past** (amber tint: "you are recording a past class day") · ⬜ **Not a class day** (grey, disabled: "Class does not meet on Tuesdays") · 🔴 **School closed** (disabled: reason text) · 🟣 **Holiday** (disabled: holiday name) · 🔒 **Future** (disabled: "attendance opens on the day"). A one-line banner above the sheet explains the rule; a legend sits in the picker. All labels Amharic/EC (e.g. `እሁድ፣ ጥቅምት 12፣ 2019 ዓ.ም.`) with the reason in the school's UI language.
2. **Year Calendar page** (`admin/school_calendar.php`, edu dept + admins): full-year EC month grid with the same statuses; **"prepare the year"** flow — bulk-enter holidays/vacations (table + date ranges); **"Days Unaccounted For"** check (Arbor idea): class-day count vs expected, gaps highlighted; override panel: big **"Close the school for …"** button per upcoming day with reason field, and **"Reopen"** appearing on closed days while `date >= today`, greyed out afterwards; every action in the audit log with before/after.
3. **Monthly Attendance grid** (`attendance_monthly.php`): off days already excluded from rates; the grid gains the same day chips so "why is this day empty" is visible.

## 6. Phases (each needs explicit owner approval)

| Phase | Content | Touches mobile? |
|---|---|---|
| **P0** | `sql/078` + `SchoolCalendarService` + enforcement in the 3 save paths + API `date_status` + tests | Server only (rules apply to app traffic immediately) |
| **P1** | Year Calendar page (holidays bulk prep, overrides close/reopen, unaccounted-days check) | No |
| **P2** | Web attendance date pickers + banners (edu/teacher/taker/lesson pages) | No |
| **P3** | Mobile attendance date picker statuses + explanations (uses existing `date_status` API) — **mobile scope, planned here per decision G-4** | **Yes** |
| **P4** | Full test pass, VISUAL_QA update, §36 report | — |

## 7. Testing plan
- Static: rule presence in every save path; schema idempotency; EC-only labels; no future-dated writes.
- Live: matrix {class day, weekend-day for class, holiday, override-closed, reopened, future, out-of-year, makeup-day} × {today, past} × {mobile, web, lesson sheet} — every cell asserts code + message.
- Regression: rollups/warnings unchanged for existing data (no rows are rewritten); `flutter test` for P3.

## 8. Open questions (owner decisions needed)
1. **Q1 — Class with no timetable working-days row:** fall back to Sat+Sun (current default) or block until edu dept configures it? *(Plan default: fallback + warning on Year Calendar.)*
2. **Q2 — Backdating limit:** unlimited past (your words) or a window (e.g. 14 days, PowerSchool/RosarioSIS style)? *(Plan default: unlimited.)*
3. **Q3 — Makeup classes on a closed day:** closure blocks everything (including makeups) until reopened — OK? *(Plan default: yes, block.)*
4. **Q4 — Closing a day that already has attendance rows (same day):** block the close, or allow with a forced void + audit? *(Plan default: block; super_admin may force with reason.)*
5. **Q5 — Who prepares holidays:** edu dept only, or also super/school admin? *(Plan default: edu_dept + super_admin + school_admin.)*
6. **Q6 — Mezmur:** keep its own day rules for now? *(Plan default: out of scope.)*

## 9. Deployment order (shared hosting, owner executes)
1. `git pull` (P0 code) → app keeps working even before SQL (graceful).
2. Run `sql/078_school_calendar.sql` in phpMyAdmin → rule activates.
3. Open Year Calendar → enter the year's holidays → close/reopen becomes available to edu dept.
4. (P3) build the APK from `main` as usual.

## 10. Out of scope (recorded, not planned)
Per-lesson timetable-bound attendance (Option B/C of `ATTENDANCE_TIMETABLE_LINK_RESEARCH_2026-10-20.md`); mezmur calendar rules; expected-sessions reporting in monthly cards (can follow later using the same calendar service).
