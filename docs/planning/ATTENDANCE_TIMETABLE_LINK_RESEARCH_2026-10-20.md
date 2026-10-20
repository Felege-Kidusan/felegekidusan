# Attendance × Timetable — Research & System Status (2026-10-20)
**Question (owner):** one teacher, two subjects, one class, one day — what does the attendance do today, and how do big school systems handle this? Should attendance become timetable-based? **Status: RESEARCH ONLY — owner will decide. No implementation.**

---

## 1. What our system does today (FACT from code)

| Layer | Where | Unit of marking | Your scenario (2 subjects × 1 class × 1 day) |
|---|---|---|---|
| Class/day sheet | Mobile app (`attendance_screen.dart` → `api/v1/routes/attendance.php`) | one mark per child per **class per day** | **One sheet for the whole day** covering both lessons. Taking it again in lesson 2 **overwrites** lesson 1's marks (same unique key). The app has no subject/lesson selector. |
| Lesson sheet | Web (`admin/api_lesson_attendance.php`, `LessonAttendanceService`, sql/076, Phase 6) | one mark per child per **lesson** = (class, date, subject, lesson_no) | **Two sheets** (one per subject). `lesson_no` separates two lessons of the *same* subject (max 6/day). |
| Derived day result | `LessonAttendanceService::deriveDay` | auto class/day row | Best status wins: **present › late › excused › absent**; holiday only if every lesson is holiday. Written through `AttendanceRecordService` so warnings/rollups/report cards see one day row. |
| Day lock | `assertClassDayWritable` (`DAY_LOCKED_MESSAGE`, owner Q6-5) | — | Once any lesson sheet exists for that day, **both** the mobile save and the web class/day sheet are **refused** — the day is read-only and derived from lessons. |
| Rates & warnings | `AttendanceMonthRollupService`, `AttendanceRiskService` | **days**, not lessons (Option B, phase plan §101) | A child present in lesson 1 and absent in lesson 2 derives **present** → counts 1 present day in the monthly rate and never triggers a warning. |

Lesson sheets today are **assignment-based, not timetable-based**: the subject list comes from `teacher_assignments` (teacher→class→subject) and the teacher picks the date + lesson number freely. The timetable (T-1/T-3) is not consulted. Mobile lesson sheets were deliberately deferred (owner decision G-4, 2026-10-19: no new mobile scope until separately planned).

## 2. How the big systems do it

Three industry patterns (they all keep **two layers**: per-lesson marks + one derived daily mark):

1. **Daily / homeroom only** (primary/elementary): one mark per child per day.
   PowerSchool elementary ("Once-daily, typically in the homeroom"); ManageBac "Homeroom Only" mode; Veracross daily via homeroom/advisory.
2. **Period / lesson (secondary)**: one mark per class meeting — two subjects in one class = two attendance events.
   PowerSchool "Meeting" attendance (teachers take it per meeting/class); RosarioSIS "Take Attendance … period attendance for all your students in the current period"; openSIS "take attendance for every period in a day"; Veracross "a student receives a class attendance record for each class that has been configured to track class attendance" + a per-class Taken/Not-Taken status; ManageBac "Class Attendance … for each lesson"; Toddle "daily, period by period, or AM/PM".
3. **Hybrid with a daily bridge** (the mainstream): per-lesson marks feed one computed daily record.
   PowerSchool: a "bridge period" converts meeting marks into the daily record (one-way bridge when teachers mark every period; two-way when marked once a day) ([SC DoD guide](https://ed.sc.gov/data/information-systems/other-resources/powerschool-attendance-setup-guide/), [PowerSchool docs](https://ps-compliance.powerschool-docs.com/pssis-nm/latest/attendance-preferences)). openSIS: daily auto-calculated from period minutes vs full/half-day thresholds, office may override ([openSIS](https://help.opensis.com/portal/en/kb/articles/override-daily-attendance)). RosarioSIS: Daily Summary colors per day from period marks (absent all day / half day / present). ManageBac: homeroom absence is shown as context ("red X") inside class attendance ([ManageBac](https://help.managebac.com/hc/en-us/articles/360033716151-Recording-Homeroom-or-Class-Attendance)). Gibbon: "Take Attendance by Class … for each and every lesson during the day", with a school setting **"Count Class Attendance as School Attendance"** to let class marks roll up to the school day ([forum](https://ask.gibbonedu.org/t/attendance/1388)); double lessons = mark once for the first lesson ([forum](https://ask.gibbonedu.org/t/attendance-and-timetable-layout/15066)).

**Timetable binding** (what the owner proposes) is exactly what ManageBac/Veracross do: class-attendance dates are restricted to the class's timetable dates (ManageBac "jump to the previous/next available date … to avoid incorrectly entered attendance"); each timetable slot becomes one attendance event. Veracross tracks which of the day's classes have been taken ("Class Attendance Status: Taken / Not Taken / N/A") and gives the office a "who hasn't taken attendance" list.

## 3. Where we stand vs the big systems

- We ALREADY have both layers (class/day + lesson sheets + derived day + day lock) — Option B is structurally the same as the PowerSchool/openSIS/Gibbon hybrid.
- The three differences:
  1. **No timetable binding** — lesson sheets ask for subject + lesson number instead of offering "today's lessons from the timetable".
  2. **Derivation rule = best status wins** — lesson-2-only absence counts as present for the day. (openSIS = minutes-based; PowerSchool = bridge-period code; RosarioSIS = period-by-period report.)
  3. **Mobile is class/day only** — teachers who take attendance on the phone cannot record lesson detail (deliberate: decision G-4).

## 4. Options for the decision (NOT implemented)

- **A. Keep as-is** — mobile class/day + web lesson sheets. Simple; per-lesson absence invisible in monthly rates.
- **B. Timetable-bound lesson attendance everywhere (web + mobile)** — app shows "today's 2 lessons" from the timetable; one sheet per lesson; day stays derived; office sees "which lesson hasn't been marked" (Veracross model). Biggest change; mobile scope needs its own plan + approval (G-4).
- **C. Middle: timetable-bound on the web only; mobile stays class/day** (and is refused on lesson days, as today); optionally change the derivation rule (e.g. worst status, or "absent if absent ≥ 2 lessons") so partial absence counts.

Whatever is chosen: rates/warnings can keep counting days (Gibbon/PowerSchool both report the day) or start counting lessons (ManageBac weekly class tally) — a separate, small policy decision.

## 5. Sources
- PowerSchool Attendance Setup Guide (SC DoD, Dec 2025) — bridge period, meeting vs daily, one-way/two-way: https://ed.sc.gov/data/information-systems/other-resources/powerschool-attendance-setup-guide/
- PowerSchool compliance docs (NM) — bridge period rationale: https://ps-compliance.powerschool-docs.com/pssis-nm/latest/attendance-preferences
- PowerSchool "Setting an attendance method" — once-daily / twice-daily / by class (secondary only): https://support.powerschool.com/help/sms/843/SchoolUser/Content/Topics/Setting_an_attendance_me.htm
- Gibbon features + forums — class vs school attendance, "Count Class Attendance as School Attendance", double lessons: https://gibbonedu.org/features/ ; https://ask.gibbonedu.org/t/attendance/1388 ; https://ask.gibbonedu.org/t/attendance-and-timetable-layout/15066
- RosarioSIS teacher/admin handbooks — period attendance, Daily Summary day colors: https://www.rosariosis.org/documentation/teacher-handbook/ ; https://www.rosariosis.org/documentation/school-administrator-handbook/
- openSIS — period attendance, daily auto-calc from minutes, override: https://opensis.com/feature-attendance ; https://help.opensis.com/portal/en/kb/articles/override-daily-attendance
- Veracross — daily vs class attendance, Class Attendance Status, per-class tracking: https://community.veracross.com/s/article/Tracking-Daily-or-Class-Attendance ; https://community.veracross.com/s/article/Knowing-When-Attendance-Has-Been-Taken
- ManageBac — Homeroom vs Class Attendance, timetable-bound dates: https://help.managebac.com/hc/en-us/articles/360033716151-Recording-Homeroom-or-Class-Attendance ; https://help.managebac.com/hc/en-us/articles/360018804292-Configuring-Attendance-Settings
- Toddle — daily / period-by-period / AM-PM, reconciled official record: https://www.toddleapp.com/product/class-operations/
