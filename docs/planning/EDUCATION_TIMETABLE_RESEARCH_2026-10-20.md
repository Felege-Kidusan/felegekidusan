# Education Department Timetable — Research & Recommendation

**Date:** 2026‑10‑20  **Status:** T‑1 (web) IMPLEMENTED after owner approval of the interactive demo — see §10. Mobile (T‑3) still awaits separate approval.
**Protocol:** Universal AI Software Change, Patch & Update Protocol (research → system analysis → owner answers → plan → approval → implementation).

---

## 0. Owner decisions captured today (binding for this plan)

| # | Question | Owner answer |
|---|----------|--------------|
| O‑1 | Kind of timetable | **Manual builder** — Education staff place lessons on a grid; clash detection; teacher view; print. No automatic solving. |
| O‑2 | Days / period structure | **Saturday + Sunday by default, NOT confirmed** — "there may be other days also for specific grades, so make it that way" → day sets and bell schedules must be configurable **per grade/class group**, not one global week. |
| O‑3 | Audience | **Education staff + teachers (own schedule) only.** |
| O‑4 | Link to Phase‑6 lesson attendance | **Not now** — "no lesson number for now"; it must look like a *normal* timetable. |
| O‑5 | Reference | Owner's previous project **`suraman21/eduid`** (Laravel + React web builder, Flutter teacher app). "Investigate this repo … you can clone the Flutter code for the teachers' mobile." |

---

## 1. System analysis — what SSMS already has (FACTS from the repo)

| Item | Fact | Source |
|------|------|--------|
| Tables | `timetable_periods` (label, start, end, is_break, sort_order UNIQUE) and `timetable_entries` (class × period × ISO weekday → subject, teacher, room; UNIQUE on the triple) | `sql/007_timetable.sql` |
| Service | `TimetableService` — periods CRUD with overlap check, `classGrid`, `teacherSchedule`, `saveEntry` (teacher double‑booking check), `clearEntry` | `admin/backend/services/TimetableService.php` (379 lines) |
| API | `admin/api_timetable.php` — reads for Education + teacher (`my_schedule` forced to own id), writes Education‑only, CSRF, POST‑only, audited in pass 2 | ROLE_MAP l.128 |
| UI | **None.** No dashboard page calls `api_timetable.php`; no mobile route; no Flutter screen. | grep (0 callers) |
| Scope gaps | Not scoped to academic year/term; one global bell schedule; one implicit 7‑day week (no working‑day concept); no per‑class‑group structures; not linked to `teacher_assignments` (teacher picker is free); no audit rows; no print view; no Ethiopian‑calendar labels. | code reading |
| Related data | `classes(level_order, section, age_group)`, `subjects`, `class_subjects`, `teacher_assignments(class, subject, year, is_class_teacher)`, `academic_years`, `academic_terms` | schema |
| Calendar | Web `WBWSCalendar.formatDate` + PHP `ethiopian_date.php`; Flutter `utils/ethiopian_calendar.dart` (`EthiopianDate`) | existing |
| Mobile stack | Flutter 1.7.1+38, `provider`, `sqflite`, `http`, offline‑first sync services; teacher area = `screens/teacher/teacher_home.dart` + `teacher_grades.dart` | `Mobile/wbws_flutter_app` |
| PDF | No PDF library — reports use print‑ready HTML + browser "Save as PDF" | `MemberReportRenderer` |

**Inference:** `sql/007_timetable.sql` is a direct port of eduid's migration (same columns, minus `school_id`). The data layer was brought over; the product layer never was.

---

## 2. Reference analysis — `suraman21/eduid` (cloned read‑only, MIT)

### 2.1 Data model
* `timetable_periods` (per school; label/start/end/is_break/sort) — **one bell schedule per school**.
* `timetable_entries` (class × period × weekday → subject/teacher/room), unique per cell.
* `schools.working_days` JSON (ISO 1–7, default Mon–Sat) — **one day set per school**.
* `makeup_classes` — dated one‑off lessons outside the weekly grid ("template + dated exception" model) with reason, teacher‑busy check against both other make‑ups and the recurring grid.

### 2.2 Web builder (`TimetableController` + `Pages/School/Timetable/Index.tsx`, 547 lines React)
* Class selector → **Working days** card (toggle buttons; removing a day deletes its lessons, with a warning) → **Bell schedule** chips (add/edit/delete period; overlap check, back‑to‑back allowed; deleting a period cascades lessons) → **Weekly grid** (rows = periods, columns = working days; click a cell → inline editor: subject, teacher (pre‑filled from class‑subject default), room; Save / Clear).
* Server rules: day must be a working day; teacher cannot be in two classes in the same period+day; upsert per cell; audit log on save / working‑days change / make‑up create.
* Tests: 18 feature tests (period add, double booking, cascade, working days, make‑ups, mobile today endpoint).

### 2.3 Mobile (`schedule_screen.dart`, 767 lines; `ScheduleController`)
* API: `GET /schedule/today` (periods with the teacher's entry per period + today's make‑ups), `GET /schedule/week` (working days × periods, subject colour). Cached locally for offline.
* UI: "My Timetable" — **Weekly · Daily · Monthly** segmented switch, day strip, "now" highlight, subject colour/icon, Today button, skeleton while first sync, offline empty‑state. Material, `provider`, `sqflite` cache — **same stack as SSMS**.

### 2.4 What transfers, what does not
| eduid piece | Reuse in SSMS? | Why |
|---|---|---|
| Data model | Partly — already ported, but must gain **per‑class‑group structures** (O‑2) and **academic‑year scope** | Sunday School ≠ one school‑wide week |
| Controller rules (overlap, double‑booking, cascade, working‑day guard, make‑up busy check) | **Yes** — port logic into `TimetableService` (plain PHP, no Laravel) | proven, tested |
| React/Inertia page | **No** (SSMS has no React/Vite build) — re‑implement the same UX as a vanilla‑JS page inside `edu_dept.php`, like every other Education tab | stack mismatch |
| Flutter schedule screen | **Yes, clone & adapt** — same packages; swap Dio→`http`, SyncEngine→SSMS sync service, Gregorian labels→`EthiopianDate`, add Amharic | owner permitted |
| Make‑up classes | **Recommended** (small; matches "cover after holiday / cancelled lesson" needs) | Phase 2 |

---

## 3. Requirements derived (owner answers + system needs)

**Functional**
1. Education creates **timetable structures** (e.g. "Weekend – children", "Weekend – youth", "Grade 12 weekday evening"): a named day set (any of Mon–Sun) + its own bell schedule (periods/breaks).
2. Each class is attached to exactly one structure **per academic year** (default: the one weekend structure). → satisfies "other days for specific grades".
3. Per class, a weekly grid (periods × days of its structure) with subject, teacher, room; teacher list restricted to **teachers assigned to that class** (`teacher_assignments`), pre‑filled from the class‑subject assignment; "other teacher" allowed with a visible warning.
4. Clash rules: teacher not in two classes at the same day+period across structures with **overlapping times**; room optional clash (warn, not block); period overlap inside a structure blocked.
5. Teacher views: own weekly timetable (web dashboard card + mobile screen), "today" with current‑period highlight; Education views: class grid, teacher grid, whole‑school day sheet (all classes for one day).
6. Print: A4 class timetable and teacher timetable (print‑ready HTML → browser PDF, same approach as reports) with school header, EC academic year.
7. Make‑up / extra class (dated, outside the grid) with reason — Phase 2.
8. Everything Education changes is written to `activity_logs`; nothing is hard‑deleted silently (period delete requires confirmation and logs the count).

**Non‑functional (standing constraints)**
* Shared hosting: plain PHP 8.2, MariaDB, no composer on server, no cron; vendored libs only if needed.
* Ethiopian calendar for every date label (weekday names in Amharic/English; EC dates on make‑ups and print headers). Weekday grid itself is calendar‑neutral.
* Mobile scope is a **separate approval** (standing rule) — this plan lists it as Phase 3.
* Simplicity: Education must be able to build a timetable in minutes; no timetable jargon ("columns/rows/tie days to dates").

---

## 4. Landscape — full systems (evaluated for ideas, not adoption)

| System | Licence / stack | Timetable model | Verdict for SSMS |
|---|---|---|---|
| **Gibbon** (GibbonEdu/core) | GPL‑3, PHP 8 + MySQL 8 | Reusable **Columns** (day structures of Rows/periods) → **Timetables** per school year with **Days** bound to columns → classes placed in periods → "Tie Days to Dates". Multiple timetables per year for different year groups; v29 redesigned viewer; Google Calendar overlay. | Best‑in‑class open‑source *model*. Too heavy to embed (whole SIS), but its **structure concept is exactly what O‑2 needs** — we adopt a simplified version (structure = column + day set). |
| **RosarioSIS** | GPL‑2, PHP, MySQL/PostgreSQL | Periods (title, length, block), course periods with meeting days, multiple **calendars** attributed to courses/students for variable schedules; scheduler for student requests. | Course‑centric US model; student‑level scheduling irrelevant here. Idea kept: per‑group calendars. |
| **openSIS Classic** | GPL, PHP | Fixed vs variable schedules, meeting days per course period, rotating/block schedules. | Same as above; heavier. |
| **FET / UniTime / OptaPlanner** | GPL / Apache | Automatic constraint solvers (C++/Java). | **Out of scope** (O‑1 manual; Java/C++ impossible on shared hosting). |
| **eduid (owner)** | MIT, Laravel + React + Flutter | School‑wide working days + bell schedule + class grid + make‑ups; mobile weekly/daily/monthly. | **Primary reference** (UX + rules + Flutter screen). Needs the per‑group structure extension. |

---

## 5. Landscape — libraries for the web builder

| Option | Licence | Fit | Notes |
|---|---|---|---|
| **Plain HTML table grid + inline cell editor** (eduid approach) | — | ★★★★★ | Periods × days is a *discrete* grid, not a time axis. A table is the standard rendering; zero dependency; prints perfectly; matches every other Education tab (vanilla JS, `esc()`, `getAPI/postAPI`). |
| **SortableJS** (optional add‑on) | MIT, ~45 kB, no deps | ★★★★ | Drag a lesson from one cell to another / from a subject palette; works on touch. Add only if staff ask for drag‑and‑drop; click‑to‑edit is enough for v1. |
| **vkurko/event‑calendar** | MIT, 35 kB, zero‑dep, resource‑timeline free | ★★★ | Excellent FullCalendar alternative with free resource views (DayPilot/FullCalendar charge for these). Suited to a *time‑axis* day sheet (all classes as resources). Candidate for the "whole‑school day view", not for the builder. |
| **DayPilot Lite** | Apache‑2 (attribution required) | ★★★ | Horizontal resource scheduler, drag‑drop. Heavier; attribution text; overkill for v1. |
| **FullCalendar** | MIT core; resource views **premium** | ★★ | Resource timeline needed for class/teacher lanes is paid. |
| Tailwind/React builders | — | ✗ | SSMS has no build pipeline. |

**Recommendation (web):** eduid‑style **table grid, vanilla JS**, server‑rendered inside `edu_dept.php` (new "Timetable" tab) + a print page. Keep `vkurko/event‑calendar` as the optional library for a later whole‑school day/time view (vendored under `assets/vendor/`, no CDN).

---

## 6. Landscape — libraries for the teacher mobile screen

| Option | Licence | Fit | Notes |
|---|---|---|---|
| **Clone eduid `schedule_screen.dart`** | MIT (owner's) | ★★★★★ | Already a weekly/daily/monthly teacher timetable with offline cache, same packages (`provider`, `sqflite`, `intl`). Adapt: `http` client, SSMS session/sync, `EthiopianDate` labels, Amharic weekday names, FKSS theme. No new dependency. |
| `calendar_view` (Simform) | MIT | ★★★ | Day/week/month event views with working‑days support; time‑axis based (periods become events). Fine, but adds a dependency and a model translation. |
| `kalender` (KDAB) | MIT | ★★★ | Very polished day/multi‑day/month; drag‑drop irrelevant for read‑only teachers; heavier. |
| `timetide` | MIT | ★★ | 13 views incl. resource timelines; new (2026), low adoption. |
| `time_planner` / `time_planner_2` | MIT | ★★ | Simple hour × day table; limited styling; not updated. |
| `flutter_week_view` | MIT | ★★ | Day/week time axis; okay but the eduid clone already covers it. |
| Syncfusion calendar | Commercial (free community licence with conditions) | ✗ | Licence friction. |

**Recommendation (mobile, Phase 3, separate approval):** clone and adapt the eduid screen; no new package. Pubspec stays untouched (important: `flutter pub get` pins are version‑gated by tests).

---

## 7. Recommended architecture (for approval — not implemented)

### 7.1 Data model (additive, MariaDB‑safe, idempotent `sql/077_timetable_structures.sql`)
```
timetable_structures   id, academic_year_id, name, name_am, working_days JSON [6,7], is_default, is_active, created_by, timestamps
timetable_periods      + structure_id (FK)  -- existing rows migrate into a default "Weekend" structure
class_timetable        class_id, academic_year_id, structure_id   (UNIQUE class+year)
timetable_entries      + academic_year_id (FK)  -- UNIQUE (class_id, academic_year_id, period_id, day_of_week)
timetable_makeups      id, class_id, academic_year_id, date, start_time, end_time, subject_id, teacher_id, room, reason, created_by  (Phase 2)
```
No table is dropped; existing `timetable_periods`/`timetable_entries` keep working (default structure = current year, days 6,7).

### 7.2 Service rules (`TimetableService`, ported from eduid + SSMS needs)
* Period overlap blocked **within a structure**; back‑to‑back allowed.
* Teacher clash: same ISO day and **time overlap** across any structure (not just same period id).
* Entry day must be in the class's structure `working_days`; period must belong to the same structure.
* Teacher picker = `teacher_assignments` for that class+year; default from class‑subject assignment; override allowed (logged).
* Changing a class's structure or a structure's days → report how many lessons would be dropped; require confirmation; log counts.
* Every write → `activity_logs` (`Timetable Period Saved`, `Timetable Entry Saved`, …), consistent with pass‑2 audit style.

### 7.3 API (`admin/api_timetable.php`, extend in place)
Reads: `structures`, `class_grid(class_id)`, `teacher_grid(teacher_id)`, `day_sheet(day)`, `my_schedule`, `my_today`.
Writes (Education, POST+CSRF): `save_structure`, `toggle_structure`, `assign_class_structure`, `save_period`, `delete_period`, `save_entry`, `clear_entry`, `copy_class_grid(from,to)`, Phase 2 `save_makeup`/`delete_makeup`.
Mobile: `GET /api/v1/timetable/week`, `/timetable/today` (teacher‑scoped; FeatureGate `timetable`).

### 7.4 UI
* **Education → Timetable tab** (edu_dept.php): Structure chips → Bell schedule → Class grid (table), cell editor popover, teacher/room/subject; "Copy from class…"; Print button.
* **Teacher dashboard**: "My timetable" card (this week, today highlighted) + print.
* **Print page** `admin/timetable_print.php?class_id|teacher_id` — A4 landscape, EC year/term header, Amharic + English subject names.
* All labels EC; weekday names bilingual (ሰኞ…እሁድ).

### 7.5 Phases
| Phase | Scope | Approval |
|---|---|---|
| T‑1 | Schema 077 + service + API + Education builder tab + teacher web card + print | needs approval |
| T‑2 | Make‑up classes + whole‑school day sheet (optional `event‑calendar`) | after T‑1 feedback |
| T‑3 | Mobile teacher timetable (eduid clone) + APK | separate mobile approval (standing rule) |
| Later | Timetable → Phase‑6 lesson pre‑fill (O‑4 says not now) | owner decision |

---

## 8. Open questions for the owner (not decided by me)
1. Default structure name/days for 2018 EC: "Weekend (Sat + Sun)"? Which periods (e.g. 3 lessons + break)?
2. May a teacher be scheduled in a class where they have **no** assignment (warn) or must it be blocked (strict)?
3. Should Education be able to **copy** a whole timetable from the previous academic year at rollover?
4. Room field: free text (eduid) or a managed room list?
5. T‑3 mobile: include "Monthly" view (eduid has it) or Weekly + Daily only (simpler)?

---

## 9. Sources
* Gibbon timetabling docs — https://docs.gibbonedu.org/administrators/timetable/timetabling/ ; module source https://github.com/GibbonEdu/core/tree/main/modules/Timetable ; releases https://github.com/GibbonEdu/core/releases ; requirements (PHP 8.0 / MySQL 8) https://gibbonedu.org/download/
* RosarioSIS School module (calendars, periods, block periods) — https://www.rosariosis.org/modules/school/
* openSIS course periods (fixed vs variable schedule, meeting days) — https://support.os4ed.com/hc/en-us/articles/201203483-How-to-create-Subjects-Courses-Course-Periods-in-openSIS
* vkurko/event‑calendar (MIT, resource timeline free) — https://github.com/vkurko/calendar ; releases v5.17.0 (2026‑10‑08) https://github.com/vkurko/calendar/releases/tag/v5.17.0
* DayPilot Lite (Apache‑2, attribution) — https://javascript.daypilot.org/open-source/ ; licence https://javascript.daypilot.org/daypilot-lite-license/
* Flutter: `calendar_view` 2.0.0 MIT https://pub.dev/packages/calendar_view ; `kalender` MIT https://pub.dev/packages/kalender ; `timetide` MIT https://pub.dev/packages/timetide ; `time_planner` MIT https://pub.dev/packages/time_planner ; `flutter_week_view` https://pub.dev/packages/flutter_week_view ; JonasWanke/timetable https://github.com/JonasWanke/timetable
* Owner reference — https://github.com/suraman21/eduid.git (MIT): `app/Http/Controllers/School/TimetableController.php`, `app/Http/Controllers/Api/V1/ScheduleController.php`, `resources/js/Pages/School/Timetable/Index.tsx`, `mobile/lib/screens/schedule_screen.dart`, `database/migrations/2026_06_28_000002_create_timetable_tables.php`, `…000003_create_makeup_classes.php`, `tests/Feature/TimetableTest.php`.

---

## 10. Implementation record — T‑1 web builder (2026‑10‑20)

**Owner decisions that changed the plan (from the demo review):**
* "Make it exactly like eduid": **one class at a time**; the bell schedule is **per class** (not a shared structure object), working days are **per class** with Cancel / Save days; UI language **English**; simple and clean.
* **Ethiopian calendar only** for every date shown (mandatory). The weekday grid carries no dates; makeup‑class dates use the system EC date picker and `WBWSCalendar.formatDate`; storage stays Gregorian (same rule as the rest of SSMS).
* Teacher not assigned to the class: allowed, labelled "(not assigned to this class)", logged (`teacher_assigned_to_class = 0`).
* Rooms: free text (max 50). Makeup classes included in T‑1.

**Files**
| File | Change |
|---|---|
| `sql/077_timetable_builder.sql` | NEW, additive + idempotent: `timetable_class_days`, `timetable_makeup_classes`; `timetable_periods` + `class_id`, `academic_year_id`, index; `timetable_entries` + `academic_year_id`, index. **Owner runs it in phpMyAdmin after 071–076.** |
| `admin/backend/services/TimetableService.php` | Rewritten: `classSetup`, `workingDays`, `periodsFor`, `entriesFor`, `teachersFor` (teacher_assignments flag + subjects), `subjects`, `makeupsFor`, `teacherSchedule`; writes `saveWorkingDays`, `savePeriod`, `deletePeriod`, `copyBell`, `saveEntry`, `clearEntry`, `saveMakeup`, `deleteMakeup`; all prepared statements; every write → `activity_logs` (`entity_type='timetable'`). |
| `admin/api_timetable.php` | Rewritten: actions `classes`, `class_setup`, `teacher_schedule`, `my_schedule` (reads) and `save_days`, `save_period`, `delete_period`, `copy_bell`, `save_entry`, `clear_entry`, `save_makeup`, `delete_makeup` (POST + CSRF + Education/School/Super only + `ay_require_writable`). Teachers are forced onto their own id. |
| `admin/js/edu_timetable.js` | NEW vanilla‑JS module (`EduTimetableInstance.boot()`), eduid layout, no CDN, EC dates, `esc()` on every dynamic string, print CSS (A4 landscape). |
| `admin/dashboards/edu_dept.php` | Sidebar + bottom‑nav item **Timetable**, `#sec-timetable`, script include, nav boot hook. |
| `admin/dashboards/teacher.php` | "My Timetable" card on the teacher home: Today (current lesson marked *Now*, makeup classes), weekly table, upcoming makeup classes, Print. |
| `tests/security/test_timetable_builder.py` | 5 static guards + 3 live tests (full round‑trip incl. overlap/clash/cascade/makeup rules, access control, page render). |

**Rules enforced server‑side:** period overlap (per class) blocked, back‑to‑back allowed; lesson only on a working day and on a non‑break period of the same class; teacher time‑overlap clash across classes (same ISO weekday, same year) blocked with a message naming the class; removing days / periods clears lessons and reports the count; copy bell replaces target periods+days and clears its lessons; makeup ≥ today, teacher busy against weekly grid (weekday) and other makeups (date); time‑travel to a past year → read‑only (403).

**Not in T‑1 (unchanged scope):** mobile teacher timetable (T‑3, separate approval), link to Phase‑6 lesson attendance (owner: not now), whole‑school day sheet.
