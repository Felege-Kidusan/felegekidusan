# Visual QA Guide — what changed on the dashboards and how to see it

_For the owner. Covers everything shipped since the attendance‑alerting work began (2026‑10‑19 → 2026‑10‑20): Attendance Alerting Phases 1–6, Analytics Hub / HR change, Reporting Centre V2, dashboard card fix, production‑readiness audit, Timetable (web T‑1 + mobile T‑3). Facts come from the repo at `e9e996a`._

---

## 0. Before you can see anything — 3 switches

Most of the new screens are **hidden until all three are done**. If you only `git pull`, the cards stay empty or missing.

| Step | What | Where |
|---|---|---|
| 1 | `git pull` | server shell |
| 2 | Run the SQL files **in order** in phpMyAdmin: `sql/071`, `072`, `073`, `074`, `075`, `076`, `077` (all additive + idempotent; nothing is deleted) | phpMyAdmin → `felegeki_…` DB → Import / SQL tab |
| 3 | **Turn the feature flag on**: add `define('FEATURE_ATTENDANCE_V2', true);` to `/home/felegeki/.fkss_env.php` (above the config include). Default is **OFF** → warnings / monthly / follow‑ups / lesson sheets / policy pages all refuse to work. | server file |

The **timetable (step 2's `sql/077`) does not need the flag** — it appears as soon as the SQL is in.

Then log out and log in again (new JS/CSS is versioned; a hard refresh `Ctrl+F5` is enough on desktop).

---

## 1. Where to test — three options

| Option | Safe for real data? | Effort | Recommendation |
|---|---|---|---|
| **A. Production, read‑only look** — log in with each role and just *look* (no saving) | ✅ | 20 min | **Do this first.** Everything in §2 is visible without creating data, except the "make a warning appear" scenario. |
| **B. Production, one tiny controlled scenario** (§3) | ⚠️ creates a few attendance rows for one real class | 15 min | Only if you accept a few test rows; §3 tells you how to undo each one through the UI. |
| **C. Local copy on your PC** (XAMPP/Laragon: `git clone`, import a DB export, add `.fkss_env.php`, flag on) | ✅ fully safe, play freely | 1–2 h once | Best if you want to click everything with fake members. |

You explicitly chose **no test subdomain**, so there is no staging site; A + (optionally) B is the practical path.

---

## 2. Role‑by‑role: what is new and where to click

Legend: 🆕 new element · ✏️ changed element · 🔔 bell notification

### 2.1 School Admin (`/admin/dashboards/school_admin.php`)
- **Attendance** (left menu) → 🆕 **"Attendance policy (per department)"** card: Education and Mezmur thresholds (75 % / 3 consecutive sessions / min sessions). Edit → Save → an audit row. Super Admin sees it read‑only.
- Members → open any member → 🆕 **Department Status** block (Education / Mezmur badges + history in EC dates). Deactivate/Reactivate buttons are **HR + Information only**; School Admin only reads.

### 2.2 Education department (`/admin/dashboards/edu_dept.php`)
- Dashboard home →
  - 🆕 **"Open Attendance Warnings"** card (count by level) → opens `/admin/attendance_warnings.php?department=edu` (list, filters, detail drawer, **Close** with outcome).
  - 🆕 **"Monthly Attendance"** card → `/admin/attendance_monthly.php?department=edu` (EC month picker, class filter, below‑threshold toggle, CSV export with EC dates only).
  - 🆕 **"Assessment follow‑ups"** card → `/admin/assessment_followups.php` (students who were inactive during an assessment; decide: make‑up / excuse / keep; record make‑up attempts).
- **Submissions / review inbox** → 🆕 **Approved** tab; open any attendance packet → 🆕 **History** list (Submitted / Approved / Returned / Reopened… with EC timestamps); on an *approved* sheet → 🆕 **"Reopen for correction"** / **"Reject sheet"** (reason required, ≥ 3 chars).
- 🆕 **Timetable** tab (new left‑menu item) → select a class → **Working days** (Mon–Sun chips) → **Bell schedule** (periods/breaks, copy from another class) → **Weekly grid** (click a cell → subject, teacher, room; clash with another class is refused with a message) → **Makeup / extra classes** card (date in EC, time, subject, teacher, reason).
- Analytics Hub (existing) ✏️: "Attendance alert" KPI and triage tiers now use the **policy threshold (75 %)** instead of the old 60 % when the flag is on.

### 2.3 Teacher (`/admin/dashboards/teacher.php`)
- Dashboard → 🆕 **"My Timetable"** card (`#tt-card`): today's lessons + this week + upcoming makeup classes, EC dates.
- Dashboard → 🆕 **"Assessment follow‑ups"** card (own classes only).
- **Attendance** section → 🆕 third selector **"Sheet"**: *Whole day (class)* (unchanged) **or** *Subject + L1…L6* (lesson‑level sheet, Phase 6). After a lesson sheet is saved for a day, the whole‑day sheet for that day shows a lock message (the day is derived from lessons).
- Member row → 🆕 "Lesson history" link → `/admin/lesson_attendance.php?member_id=…`.
- 🔔 Bell: "sheet returned / reopened / rejected" messages open the exact sheet.

### 2.4 HR department (`/admin/dashboards/hr-dept.php`)
- ✏️ HR **no longer takes attendance** — the Attendance menu shows the **shared Attendance Analytics Hub** (Education + Mezmur data) with a one‑line explanation.
- Dashboard → 🆕 **Open Attendance Warnings** (both departments, per‑department counts) · 🆕 **Monthly Attendance** · link to **Reporting Centre** (`/admin/reports_v2.php`).
- Member panel → 🆕 **Department Status** card with **Deactivate / Reactivate** (modal, reason required) + history (EC). Reactivating an Education‑inactive member automatically creates **assessment follow‑ups** for assessments missed while inactive (visible to Education/teacher).
- 🔔 Bell: new warnings (`attendance_warning` type) for HR as well.

### 2.5 Information department (`/admin/dashboards/info-dept.php`)
- Same 🆕 **Open Attendance Warnings** + **Monthly Attendance** cards; Attendance section's **"At‑risk members"** pane ✏️ now reads the monthly roll‑up (department + EC month pickers, "below threshold" link).
- Member panel → 🆕 **Department Status** card (Info may Deactivate/Reactivate, same as HR).
- Reporting Centre V2 (`/admin/reports_v2.php`) — datasets Info‑only by your decision; HR frozen.

### 2.6 Mezmur department (`/frontend/pages/mezmur_dept.php`)
- Overview → 🆕 **Open Attendance Warnings** (Mezmur) · 🆕 **Monthly Attendance** (Mezmur).
- Attendance review → 🆕 **Approved** tab · packet modal 🆕 **History** · approved packet → **Reopen** with reason.

### 2.7 Notification bell (all of the above roles)
- New type **"Attendance warning"** (opened / escalated) → department + HR + Info; **"Sheet returned / reopened / rejected"** → the teacher/taker. Nothing is e‑mailed or sent to Telegram — bell only, by requirement.

### 2.8 Phone app (APK 1.7.2 build 39 — you build it)
- Teacher home → 🆕 **My Timetable** card → Weekly / Daily / Monthly (EC month grid), Today, "Now" highlight, Makeup badge, works offline.
- Education/Mezmur review detail → 🆕 Reopen / Reject with reason; 🆕 review **History**; bell deep‑links (build 38 notes).
- Department status read/write on the member screen (HR/Info), warnings tile (needs the flag on the server).

---

## 3. One controlled scenario that makes the alerting *visible* (optional, ~15 min)

Goal: see a warning appear, escalate in place, hit the bell, show in Monthly, and be closed — using **one real class and one member you choose** (e.g. a staff child or a member who is away).

1. **Teacher login** → Attendance → that class → pick **three different past dates** (within the current EC month) → mark only the chosen member **Absent**, everyone else Present → **Save & submit** each sheet.
   - Rule: 3 consecutive absences **or** month rate below 75 % with ≥ 3 sessions → a warning opens on the 3rd submit.
2. **Education login** → dashboard **"Open Attendance Warnings"** shows **1** → open it → level, streak, month %, EC dates. 🔔 Bell shows "Attendance warning".
3. **HR / Information login** → same card shows 1; member panel → "Department Status" block shows the risk summary.
4. **Education → Monthly Attendance** → the member appears **below threshold** for this EC month; CSV export has EC dates only.
5. **Education → Submissions → Approved** → open one of the three sheets → **Reopen for correction** (reason) → the warning detail now shows **"invalidated"** (kept, not deleted). Teacher corrects → resubmits → valid again.
6. **Undo**: Education reopens each of the three sheets → teacher marks the member **Present** → resubmits. The warning engine re‑evaluates and the warning closes/invalidates; or Education closes it manually with outcome "data correction". Nothing needs to be deleted in the database.

Timetable needs no scenario: Education → Timetable → build one class → log in as that teacher → "My Timetable" card (web) / phone card.

---

## 4. Quick "is it wired?" checklist (2 minutes, any admin)

Open these URLs while logged in — each must render a page (not a 404 / blank / "feature disabled"):

```
/admin/attendance_warnings.php
/admin/attendance_monthly.php
/admin/assessment_followups.php
/admin/lesson_attendance.php?member_id=1
/admin/reports_v2.php
/admin/dashboards/edu_dept.php?section=timetable
/api/v1/timetable/week        (with a teacher's phone token — the app does this)
```

If `attendance_warnings.php` says the feature is disabled → step 0/3 (flag) is missing. If `edu_dept.php?section=timetable` shows "no classes / table missing" → `sql/077` not applied.

---

## 4b. School Calendar + class-day attendance (needs `sql/078_school_calendar.sql`)

What to check after `git pull` + `sql/078`:

```
/admin/school_calendar.php                      (Education role: year strip, month grid,
                                                 add holiday, "Mark as Day Off" with the
                                                 "Also cancel makeup classes?" choice,
                                                 Reopen on a Day Off day)
/admin/dashboards/teacher.php                   (attendance date banner + disabled save
                                                 with the reason on Day Off / holiday /
                                                 non-class days; Save refuses > 45 days back)
Mobile app → Attendance                          (same banner; date picker opens only the
                                                 last 45 days)
```

Rule: attendance only on class days (Timetable working days or a dated makeup), no future
dates, max 45 days back. Classes without configured working days are blocked (the Year
Calendar page lists them). Every action is in the audit log (`entity_type='school_calendar'`).

---

## 5. Want screenshots instead?

The sandbox has the full seeded test system (fake members, all SQL applied, flag on). On request I can re‑provision it and produce **a screenshot set per role** (desktop + phone width) of every item in §2 so you can review visually before touching production.
