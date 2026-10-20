# Class-Day-Only Attendance + Yearly Calendar + Emergency Off-Days — Research (2026-10-20)
**Owner decision (2026-10-20):** keep day-level attendance as-is (no per-lesson mobile), but **force attendance to be taken only on class days taken from the timetable**. No future attendance (already enforced), past attendance only on class days. The timetable becomes **yearly** (covers holidays). Edu dept can mark an urgent **whole-school day off** (override) which can be **reopened before the day passes**. Professional UI must make the rules obvious. **Implementation plan is separate (`ATTENDANCE_CLASS_DAY_ENFORCEMENT_PLAN_2026-10-20.md`).**

---

## 1. Current system status (FACT from code)

| Question | Status today |
|---|---|
| Future attendance blocked? | **YES** — mobile save `422 FUTURE_DATE` (`api/v1/routes/attendance.php`), lesson sheets `422` (`api_lesson_attendance.php`). |
| Past (backdated) attendance? | **Allowed, unlimited**, any past date — no class-day check. |
| Class-day check (timetable)? | **NONE.** Attendance can be saved on any non-future date, even if the class never meets that weekday. |
| Yearly calendar / holiday table? | **NONE.** "Holiday" exists only as an `attendance.status ENUM(...,'holiday')` row value — someone marks a day holiday in the sheet; the system has no calendar to check against. `calendar_system.php` is the Ethiopian-date formatter, not a school calendar. |
| Year structure? | `academic_years` (start_date/end_date, ec_year) + `academic_terms` (start/end per term) — used for year scoping, not day-level rules. |
| Timetable data usable for this? | **YES** — `timetable_class_days` (per class + year working days, e.g. "6,7"), `timetable_entries` (weekly grid per subject/period), `timetable_makeup_classes` (dated extra lessons). Yearly = weekly grid × working days − off days. |
| Emergency day-off mechanism? | **NONE.** This is entirely new. |
| UI indication of allowed dates? | **NONE** — dates are free; failures (if any) come as API errors. |

**Conclusion:** the owner's rule is mostly NEW work. The only piece already in place is "no future attendance". The timetable has exactly the data needed for "is this a class day?"; what's missing is the calendar layer (planned off days + urgent overrides) and the enforcement + UI.

## 2. How the big systems do it

The industry standard is: **the academic calendar is the single source of truth** for whether registers/attendance exist on a day.

1. **Arbor / SIMS / UK MIS (the clearest "year + closure" model):**
   - The academic year must account for **every day**: terms + holidays + INSET/training + closures ("Every academic day must be accounted for … check the Days Unaccounted For list"). This is exactly the owner's "prepare the full year covering most holidays".
   - Holidays/closures added to the calendar → **no registers that day** → attendance not required ("As long as registers were not opened that day, you won't need to take attendance").
   - **Planned** closure vs **unexpected** closure are distinct (codes `#` vs `Y4` "unexpected whole school closure") — two mechanisms, like our planned holidays vs urgent override.
   - Guidance: "add closure days **before** the date" — if registers were already opened, marks stay and need manual fixing. This matches the owner's "reopen before the day passes": calendar changes are meant for the future/today, history is protected.
2. **PowerSchool:** calendar day types (Normal School Day / Weekend / Holiday) + **Calendar Day Exceptions** ("Snow Day", "Christmas Vacation") assigned to specific dates; "In Session" flag per day. Exception patterns can only be applied to dates **for which attendance has not been taken** (future marks get deleted). Teachers have configurable **attendance windows**: "Number of days teachers may alter attendance prior to / after the current date" — outside the window the attendance page is greyed out.
3. **Gibbon:** School Year → Terms → **Special Days** ("Use Manage Special Days to close school on certain days") → **Tie Days to Dates** (the timetable is bound to real dates = the yearly timetable). Meetings/lessons "won't be created during holidays or suspended timetable days". Attendance is never assumed for a non-recorded day (greyed out in history).
4. **ManageBac:** class attendance dates are **restricted to the class's timetable dates** ("jump to the previous/next available date … to avoid incorrectly entered attendance") — the strictest "class days only" behaviour, exactly what the owner proposes.
5. **RosarioSIS / PowerSchool:** configurable **backdate window** ("teachers can only take attendance within a certain number of days before and after the school day").

**Pattern summary:** (a) every day of the year is classified (in-session / holiday / closed) — prepared up front; (b) emergencies = calendar exceptions added before/by the day, removable while the day is not over; (c) attendance windows control past edits; (d) UI greys out and explains invalid dates.

## 3. Sources
- Arbor — registers & closure days, add-before-the-day, planned vs unexpected closures: https://support.arbor-education.com/hc/en-us/articles/360018726417-How-do-I-put-in-attendance-marks-on-an-inset-day-or-holiday ; https://support.arbor-education.com/hc/en-us/articles/360015540453-Recording-the-school-as-closed-for-a-day ; https://support.arbor-education.com/hc/en-us/articles/16894229660189-Registers-still-there-when-school-is-marked-as-closed ; https://support.arbor-education.com/hc/en-us/articles/28613578338205-How-to-prepare-and-migrate-your-secondary-schools-future-academic-year-and-timetable ("Days Unaccounted For")
- PowerSchool — Exception days / Calendar Day Exceptions / day types: https://support.powerschool.com/help/sms/800/districtuser/Content/Topics/Exception_days.htm ; https://support.powerschool.com/help/sms/800/districtuser/Content/Topics/Day_types.htm ; teacher attendance windows: https://help.powerschool.com/t5/PowerSchool-Learning-Forum/Users-unable-to-send-attendance-at-certain-periods-throuhout-the/m-p/124281
- Gibbon — lifecycle (years → terms → special days → tie days to dates), closing school on certain days: https://docs.gibbonedu.org/guides/administration/gibbon-lifecycle ; https://ask.gibbonedu.org/t/would-like-to-delete-attendance-taken-for-wrong-date/1171 ; https://ask.gibbonedu.org/t/school-meetings/15597
- ManageBac — attendance limited to timetable dates: https://help.managebac.com/hc/en-us/articles/360046492791-Using-ManageBac-to-track-Attendance-QuickStart-Guide
- RosarioSIS — attendance edit window (days before/after): https://www.rosariosis.org/tips-did-you-knows/
- Prior research (per-lesson vs daily models): `ATTENDANCE_TIMETABLE_LINK_RESEARCH_2026-10-20.md`
