# Attendance Alerting — Owner Decisions vs. Current System: Gap Analysis, Open Questions, Open‑Source Research

**Date:** 2026-10-19 · **Status:** PLANNING ONLY (no code, no schema, no migration) ·
**Inputs:** owner decision document "SSMS Attendance System — Owner‑Approved Decisions" (§1–§12, received 2026‑10‑19) and
`ATTENDANCE_ALERTING_DEEP_RESEARCH_2026-10-19.md` (what the code does today).
Tags: **FACT** (verified in repo), **INFERENCE**, **UNKNOWN**, **DECISION NEEDED**.

Per the owner's closing sentence, nothing in §B below is treated as approved — each item is a question
that must be answered before a plan is written.

---

## A. Owner decision → current system → gap (one line each)

| § | Owner decision | What exists today (FACT) | Gap |
|---|---|---|---|
| 1 | Education and Mezmur separate; **per‑department member status** | One global `members.status` (active/warning/inactive/archived). Education has `class_enrollments.status`. **Mezmur has no membership concept at all** — its roster is every member grouped by `members.current_section` (`MezmurAttendanceService::sectionListWithCounts`, `docs/SECTION_AGE_GROUP_SOURCE_OF_TRUTH.md:77`). | New per‑department status is a **new data model** (see Q1). |
| 2 | Dept‑specific alerts + cross‑dept alerts to HR & Info with dates/history | One alert type, `hr_dept,info_dept` only; message is one sentence, no dates; `data` = `{member_id,class_id}`. Mezmur: none. | New alert payload (department, dates, streak, rate, link to history) + recipient matrix. |
| 3 | 5 consecutive absences (default), configurable per dept by School Admin; present/late/excused break the streak; excused = present in rates | Hard‑coded 3, per class, web path only; late/excused already break the streak; **excused is counted as neither present nor absent** in the monthly rate ((present+0.5·late)/total). | Threshold → settings; formula change; run on all write paths. |
| 4 | Monthly low‑attendance reporting mandatory, month filter, threshold to be set after review | Alert B is dead code; Reporting Centre V2 has attendance **bands for the whole academic year** only; Info "At‑Risk" tab uses rolling N days, present‑only. No month filter anywhere except `attendance_summary` (Gregorian month, web path only, never read by any UI). | Month dimension + shared formula + threshold decision (Q4, Q5). |
| 5 | Warnings persist until reviewed/closed; history retained | Warning = a `notifications` row + a status flag. No warning entity, no close action, no history. | **New `attendance_warnings` entity** with lifecycle + audit. |
| 6 | Escalation stages (e.g. 5 → 8 → 10/15), optional auto‑deactivation, per dept, School‑Admin configurable, audited | None. Only `active→warning` at 3. | New settings + evaluator + audit; default auto‑deactivation **off** (Q11). |
| 7 | Only HR & Info may set active→inactive; enforced server‑side; audited | `api_attendance_info.php update_status/bulk_status` open to teacher/attendance_taker/edu_dept (ROLE_MAP line 134, no inner check); `info_manage_member.php` edit form passes `status`; mobile `PUT /members/{id}` whitelists `status` behind `apiRolesPii()`. | Tighten three paths (Q8). |
| 8 | One shared at‑risk calculation (dashboard, At‑Risk tab, Education triage, Reporting Centre, Flutter) with separate Edu/Mezmur assessments | Five different thresholds/formulas (3 sessions / <50 % / <60 % / <70 % / 90‑75‑50 bands). | One `AttendanceRiskService` (server) consumed everywhere (Q5). |
| 9 | Mezmur: complete equivalent alerting | `api_mezmur.php` + `MezmurAttendanceService`: statuses present/late/absent/excused (sql/024), unique (date, member), packets `mezmur_submissions`; **zero alerting**. | Same evaluator, second department adapter. |
| 10 | Server authoritative for web + Flutter | Web sheet computes alerts; Flutter path computes nothing; Flutter shows `members.status` colours only. | Evaluator must run on `POST /api/v1/attendance/submit` and Mezmur mobile submit; Flutter reads results via API. |
| 11 | late = 0.5 present + 0.5 absent | `AttendanceSummaryService` already uses 0.5·late as present; Reporting Centre bands count late as attended (1.0); Info At‑Risk tab counts late as absent (0). | Align Reporting Centre + Info tab to 0.5. |
| 12 | Audit for warning create/close, auto‑deactivation, manual status change | `activity_logs` for manual status change only; `SecurityAuditService` exists (used by Reporting Centre). | Route all four events through `SecurityAuditService` + `activity_logs`. |

---

## B. Open questions (DECISION NEEDED before a plan)

### Q1 — How is "department status" stored, and what happens to the global `members.status`?
FACT: Mezmur has no roster table; every non‑archived member appears on Mezmur sheets by section.
Options:
- **A (recommended):** new table `member_department_status (member_id, department ENUM('edu','mezmur'), status ENUM('active','inactive'), changed_by, reason, changed_at)` + history table (or append‑only). Default row = active. `members.status` stays the **church‑wide** status, changed only manually by HR/Info/admins; attendance automation **never** touches it again.
- B: reuse `class_enrollments.status` for Education and add a `mezmur_enrollments` table (introduces a Mezmur roster — bigger change, changes who appears on sheets).
Also decide: does the existing `warning` value of `members.status` remain? Proposal: it stops being automated; dashboards' "AT‑RISK" card switches to "open attendance warnings (Edu / Mezmur)".

### Q2 — Streak scope in Education: per member across all Education classes, or per class?
Today: per class. A member usually has one class per year, but subject/teacher classes exist (`teacher_assignments`, `classes`). Recommendation: **per member per department**, ordered by date — a day with any Education session counts once (present if any present/late/excused that day; absent only if all absent). DECISION NEEDED.

### Q3 — When is the evaluation run?
Options: (a) every write incl. mobile **drafts**; (b) web save + mobile **submit** + Education edits only; (c) only after Education **approves** the packet. Recommendation: **(b)** — drafts are partial by design (`normalizeSheet(..., false)`), approval can lag days. DECISION NEEDED.

### Q4 — What is a "month"?
`attendance_summary` uses the **Gregorian** month. The whole UI is Ethiopian‑calendar (header shows "Tikimt 9, 2019"; `ay_resolve`, EC converters exist). Recommendation: **Ethiopian month** for reports and the low‑attendance rule, with the EC month stored on the row. DECISION NEEDED.

### Q5 — Shared formula and bands (owner asked for a proposal after review)
Existing calculations reviewed (deep‑research §5). Proposed single formula for both departments:

```
sessions  = marked sessions for the member in the window, excluding 'holiday' rows
present_eq = present + excused + 0.5 × late
absent_eq  = absent + 0.5 × late
rate       = present_eq / sessions   (NULL when sessions = 0 → "no data", never "at risk")
```
Band options (pick one set, used everywhere):
- **Option 1 — keep the shipped Reporting Centre bands:** excellent ≥ 90, good 75–89, fair 50–74, **low < 50**. Already has Amharic labels, tests, saved views. Monthly "low‑attendance" alert would then fire at **< 75 %** (fair or low) or **< 50 %** (low) — owner picks.
- **Option 2 — Attendance Works tiers** (US chronic‑absence standard: satisfactory ≥ 95 %, at‑risk 90–94.99, moderate chronic 80–89.99, severe chronic < 80 %) [2](https://www.attendanceworks.org/wp-content/uploads/2017/04/Preventing-Missed-Opportunity-Full_FINAL9_8_16_2.pdf). Stricter; designed for 180‑day school years, less suited to once‑a‑week classes where one absence in a 4‑session month is already 75 %.
Recommendation: **Option 1**, alert at **< 75 %**, with a **minimum‑sessions guard** (no monthly alert until ≥ 3 sessions marked in that month — Moodle's `warnafter` idea [1](https://github.com/danmarsden/moodle-mod_attendance/blob/master/warnings.php)). DECISION NEEDED (threshold + guard).

### Q6 — Exact recipient matrix
| Event | Education dept | Mezmur dept | HR | Info | School/Super admin | Class teacher / taker |
|---|---|---|---|---|---|---|
| Edu consecutive warning | ✔ | – | ✔ (cross‑dept) | ✔ | **?** | **?** |
| Mezmur consecutive warning | – | ✔ | ✔ | ✔ | **?** | **?** |
| Prolonged‑absence (stage 2) | ✔/– | –/✔ | ✔ | ✔ | **?** | – |
| Auto‑deactivation performed | ✔/– | –/✔ | ✔ | ✔ | ✔ (proposed) | – |
| Monthly low‑attendance | report, not a bell alert (proposed) | | | | | |
Cells marked **?** need a decision. Note: today admins see **no** attendance alert at all.

### Q7 — Who may review/close a warning, and what must a closure record?
Proposal: owning department (edu_dept / mezmur_dept), HR, Info, admins; closure requires an **outcome** (`contacted` / `excused` / `escalated` / `deactivated` / `no_action`) + optional note; closed warnings stay visible in member history. Teachers/takers: view only. DECISION NEEDED.

### Q8 — Status‑change permission details (§7)
Confirm: (a) school_admin & super_admin are also allowed (they bypass ROLE_MAP today); (b) **inactive → active** re‑activation is also HR/Info‑only; (c) the general edit form (`info_manage_member.php`, Info only by ROLE_MAP — INFERENCE) and mobile `PUT /members/{id}` must drop/guard the `status` field; (d) department status (Q1) follows the same rule.

### Q9 — Delivery channel and scheduling
Today: in‑app bell only; no cron on production (crontab empty 2026‑10‑18). All proposed triggers are **save‑time** (no scheduler needed). A daily/weekly **digest** to HR/Info (Telegram via existing `FailureAlertService` transport, or e‑mail) would need cPanel cron. Is cPanel cron available / wanted? UNKNOWN.

### Q10 — Existing data at deploy
Apply the new rules to the current academic year's existing rows (open warnings created for streaks that are **currently** ≥ threshold, labelled "generated at migration"), or start fresh? Recommendation: back‑compute current AY only.

### Q11 — Auto‑deactivation defaults and effect
Confirm default **disabled**; stage thresholds default 5 / 8 / 10 per department. What does "inactive in department" do operationally — hidden from that department's attendance sheet, or shown greyed and excluded from rates? Recommendation: excluded from sheets and from denominators; re‑activation manual (HR/Info).

### Q12 — Holidays / non‑sessions
Confirm: Education `holiday` rows and Mezmur days with no session are excluded from both streaks and rates (as the Reporting Centre already does).

---

## C. Open‑source and industry research (patterns to borrow, libraries to use/avoid)

### C.1 How mature systems do it
| System | Pattern | Relevance |
|---|---|---|
| **Moodle mod_attendance** (GPL, PHP) | Per‑activity **warning rules**: `warningpercent`, `warnafter` (min sessions before a warning), `maxwarn` (max number sent), email the student and/or third‑party addresses; evaluated by a scheduled task; attendance % is points‑based (status set, default Present 2 / Late 1 / Excused 1 / Absent 0) [1](https://github.com/danmarsden/moodle-mod_attendance/blob/master/warnings.php) [9](https://ncsu.service-now.com/kb_view.do?sys_kb_id=33cf6195c384fe98666d4245df01319f). | Multiple configurable levels + min‑sessions guard + max‑repeat = exactly the owner's §3/§6 shape. Points‑based statuses = owner's §11 weighting. |
| **PowerSchool SIS** | "Consecutive Absences Notification": enable flag, **notification threshold**, nightly calculation into a `ConsecutiveDaysAlert` table, surfaced on attendance pages [12](https://ps.powerschool-docs.com/pssis-admin/25.1/attendance-preferences). | Persisted alert table (not just a message) — matches §5 (warning entity with history). |
| **Gibbon** (GPL, PHP) | "Consecutive Absences" report; "Attendance Percentage by Student" with **below‑% filter**, late counts as attended; CLI scripts for notifications [8](https://installatron.com/updatefeed/gibbon_27_0_01) [11](https://ask.gibbonedu.org/t/am-pm-registration-sessions-attendance-percentages-and-minutes-late/15604). | Report‑first approach for low attendance (§4), threshold filter in the report rather than a bell storm. |
| **Attendance Works** (research org) | Tiers: satisfactory (miss < 5 %), at‑risk (5–9.99 %), moderate chronic (10–19 %), severe chronic (≥ 20 %); 39 US states publish chronic absence at the 10 % line [2](https://www.attendanceworks.org/wp-content/uploads/2017/04/Preventing-Missed-Opportunity-Full_FINAL9_8_16_2.pdf) [9b](https://www.attendanceworks.org/wp-content/uploads/2019/06/2025-Policy-Brief.pdf). | Benchmark for Q5 Option 2; also the principle "bands, not a single cut‑off". |
| **Church CRMs** (ChurchCMS, SimpleChurch; ChurchCRM MIT) | "Flag members absent 2+ consecutive Sundays"; absence report with adjustable **threshold** and date range [2b](https://churchcms.app/features/church-attendance-tracking-software/) [5](https://www.simplechurchcrm.com/help-docs/reports/). ChurchCRM itself has no absentee alerting (issue closed "not planned") [10](https://github.com/ChurchCRM/CRM/issues/1861). | Confirms consecutive‑absence follow‑up is the standard pastoral pattern; nothing to import. |

**Verdict on adopting a whole system (Gibbon / RosarioSIS / openSIS / ChurchCRM) for alerting:** not viable — SSMS already owns the roster, Ethiopian calendar, Amharic UI, Flutter app and submission workflow; a second system would duplicate the roster and break the single‑source rule (§10). Borrow the patterns above only. INFERENCE.

### C.2 Libraries (shared‑hosting constraint: vendored PHP only, PHP 8.2 on prod, no cron/Redis)
| Need | Candidate | Verdict |
|---|---|---|
| Warning lifecycle (open → under review → closed) | `symfony/workflow` 6.4 LTS (MIT, PHP ≥ 8.1, framework‑agnostic, state_machine type, guard/entered events, audit‑trail listener) [8s](https://symfony.com/components/Workflow) [10s](https://symfony.com/releases/6.4) [2s](https://dev.to/gabrielanhaia/the-symfony-workflow-component-the-state-machine-php-quietly-ships-4jei) | **Optional / not recommended now.** Lifecycle is 3 states and 2 transitions; a column ENUM + `SecurityAuditService` is simpler and keeps the vendor footprint small. Keep as the upgrade path if lifecycle grows (e.g. approvals). |
| Configurable thresholds | Rules engine (`symfony/expression-language`, `bobthecow/ruler`) | **Not needed.** Settings are integers/booleans per department → existing `dept_settings` / `system_settings` tables + validation. |
| Scheduling | cron / queue | **Not needed** for alerts: evaluate at save/submit (the codebase's existing pattern, incl. `InfoAnalyticsService::ensureRollupFresh` lazy refresh). Only a digest would need cPanel cron (Q9). |
| Monthly reports, month filter, export | **Reporting Centre V2** (Tabulator/ECharts/openspout, already shipped, audited exports, saved views) | **Reuse.** Add month‑scoped attendance fields/dataset grain; note V2‑4 is currently PAUSED by owner decision — attendance reporting would need an explicit go‑ahead and lands under the Info‑only rule unless the owner says otherwise. |
| Calendar (Ethiopian month) | existing in‑repo EC converter (`ay_resolve`, dashboard EC dates) | **Reuse**; no new library. |
| Audit | `SecurityAuditService` + `activity_logs` (existing) | **Reuse.** |
| Notifications | `notifications` + `NotificationCenterService` (existing, role matrix, mobile feed routing by `member_id`) | **Reuse**; extend `data` payload (department, dates, streak, rate, warning_id). |

### C.3 Design implications already visible (INFERENCE, for the future plan — not decisions)
1. One `AttendanceRiskService` (server) with two adapters (`edu` = `attendance`, `mezmur` = `mezmur_attendance`) producing: streak, monthly rate, band, open warning — consumed by dashboards, At‑Risk tab, Education triage, Reporting Centre, `/api/v1` (Flutter).
2. One `attendance_warnings` table (department, member, kind = consecutive|prolonged|low_month|auto_deactivated, threshold used, streak/rate, first/last absence dates, status open|closed, opened_at, closed_by, outcome, note) — the PowerSchool/Moodle "persisted alert" pattern.
3. Settings per department under School Admin: consecutive threshold (5), prolonged threshold (8), auto‑deactivate on/off (off), critical threshold (10), low‑month threshold (75 %), min sessions (3).
4. Fix the two defects regardless of the rest: dead `sendNotification` include; `LIKE '%"member_id":12%'` prefix bug.

---

## D. What I need from the owner
Answers to **Q1–Q12** (short answers are fine; "use your recommendation" is a valid answer). Then I will produce the implementation plan (field matrix, schema DDL for phpMyAdmin, endpoints, UI, tests, rollout) for approval — still no code until approved.

Environment note: the sandbox test server was reset today (PHP/MariaDB gone); it will be rebuilt when implementation is approved. Nothing in this document depended on it.
