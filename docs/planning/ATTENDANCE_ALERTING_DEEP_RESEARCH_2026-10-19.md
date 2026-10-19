# Attendance Alerting & Member "Warning" Status — Deep Research

**Date:** 2026-10-19 · **Mode:** analysis only (no code changed) · **Repo HEAD:** `abe4573`
**Method:** every file read in full or in the relevant region; every key claim was then
**executed against the local test environment** (`ssms_test`, PHP 8.4, MariaDB 11.8) and
the test data was rolled back afterwards. Each statement is tagged **FACT** (read in code
and/or reproduced), **INFERENCE** (reasoned from facts) or **UNKNOWN**.

---

## 0. Executive summary (short version)

| # | Finding | Tag |
|---|---------|-----|
| 1 | There are exactly **two** automatic attendance alerts in the system: **(A)** "Consecutive Absence Alert" (3 absences in a row in one class) and **(B)** "Low Attendance Alert" (monthly rate < 70 %). | FACT |
| 2 | Alert **A** is the **only** thing in the whole codebase that automatically sets a member to `status = 'warning'`. Nothing ever sets `inactive` automatically, and nothing ever sets a member **back to `active`** automatically. | FACT |
| 3 | Alert **A** fires **only** from the **web** attendance sheet (`admin/api_attendance.php?action=save_attendance`, used by the web Teacher and Attendance‑Taker dashboards). | FACT (reproduced) |
| 4 | The **mobile app** (`POST /api/v1/attendance` and `/attendance/submit`) writes attendance rows but runs **no alert logic, no warning flag, no monthly summary, no member totals**. Three consecutive absences submitted from the app produce nothing. | FACT (reproduced) |
| 5 | Alert **B** (Low Attendance) is **dead code on the web path**: it calls `sendNotification()` only `if (function_exists(...))`, and `api_attendance.php` never includes `workflow.php` where that function lives. A 0 % month produced no alert. It can only fire from two Education endpoints (`record_attendance`, `batch_attendance`) that **no front‑end calls**. | FACT (reproduced) |
| 6 | Alert **A** is addressed to `hr_dept,info_dept` only. **super_admin and school_admin never see it** (feed matching is exact `FIND_IN_SET`). Alert **B**, if it fired, would go to `super_admin, school_admin, info_dept` — *not HR*. | FACT (reproduced) |
| 7 | The 7‑day de‑duplication uses `data LIKE '%"member_id":123%'`, which also matches member 1234, 12345 … → alerts for some members can be wrongly suppressed. | FACT (SQL semantics) |
| 8 | "Warning" is cleared **only manually**, through the Info‑dept "Attendance & Status" page (`api_attendance_info.php?action=update_status|bulk_status`). That endpoint's ROLE_MAP also admits `edu_dept`, `teacher` and `attendance_taker`, and the action has no inner role check → a **teacher can change any member's status**. | FACT |
| 9 | The dashboard "AT‑RISK STUDENTS" card is simply `warning + inactive` counts; the "At‑Risk" tab, the Education triage roster and the Reporting Centre bands each use **different thresholds** (50 %, 60 %, 70 %, 90/75/50 %). There is no single definition of "at risk". | FACT |
| 10 | There is **no scheduler/cron**, no push (FCM/Telegram) for these alerts — they exist only as rows in the in‑app bell (`notifications` table). | FACT |

---

## 1. Data model involved

| Table / column | Role in alerting | Tag |
|---|---|---|
| `attendance (member_id, class_id, academic_year_id, attendance_date, status ENUM present/absent/late/excused/holiday, notes, recorded_by)` — unique `(member_id, class_id, attendance_date)` | Source of truth for both alerts. `holiday` exists in the ENUM but `AttendanceRecordService::VALID_STATUSES` excludes it (only the unused `record_attendance` endpoint can write it). | FACT |
| `members.status ENUM('active','warning','inactive','archived')` | The flag alert A sets. | FACT |
| `members.total_attendance_rate`, `members.last_attendance_date` | Derived rollup written by `AttendanceSummaryService::refreshMemberTotals()` (web path only). Read only by `api_ai.php` and the seed. | FACT |
| `attendance_summary (member_id, academic_year_id, month, year, total/present/absent/late/excused_days, attendance_rate)` | Monthly derived cache; rate = (present + 0.5·late)/total. Alert B reads the freshly computed rate. | FACT |
| `notifications (type, title, message, data JSON, priority, source_dept, source_user_id, target_roles VARCHAR, target_user_id, created_at)` + `notification_reads` | Where both alerts land. Visibility = `FIND_IN_SET(role, target_roles) OR target_user_id = me OR (both NULL)`. | FACT |
| `grade_submissions (submission_type='attendance', …)` | Mobile "submit" workflow packet for Education review. Carries counts only; no alert side effects. | FACT |

---

## 2. Alert A — "Consecutive Absence Alert" (sets `warning`)

**Location:** `admin/api_attendance.php`, `case 'save_attendance'`, lines 171–224. Introduced in
commit `2a214e3` (2026‑08‑15, "AI Alerts"). FACT.

### 2.1 Exact algorithm (FACT)

1. After the sheet is committed (`AttendanceRecordService::replaceSheet`, transaction) and the
   summary recomputed, collect every `member_id` whose status **in this save** is `absent`.
2. For each such member run:
   ```sql
   SELECT member_id, COUNT(*) absent_count
   FROM (SELECT member_id, status FROM attendance
         WHERE member_id = ? AND class_id = ?
         ORDER BY attendance_date DESC LIMIT 3) last_three
   WHERE status = 'absent'
   ```
3. If `absent_count == 3` (the member's **three most recent rows in that class** are all
   `absent`):
   a. De‑dupe: `SELECT id FROM notifications WHERE type='attendance_alert' AND data LIKE '%"member_id":<id>%' AND created_at >= NOW() - 7 DAY`.
   b. If none: insert `notifications(type='attendance_alert', title='Consecutive Absence Alert', message='<name> has been absent for 3 consecutive classes.', data={member_id,class_id}, priority='high', target_roles='hr_dept,info_dept', source_dept='attendance_taker')`
   c. **and** `UPDATE members SET status='warning' WHERE id=<id> AND status='active'`.

### 2.2 Behavioural consequences

| Behaviour | Tag |
|---|---|
| "Consecutive" = the last 3 **recorded rows** for that member+class, regardless of date gaps, academic year, or whether the saved date is today or a back‑fill. Re‑saving an old date can trigger it. | FACT |
| `late` or `excused` between absences **breaks** the streak (only `'absent'` counts). Reproduced: member with absent/late/absent → no alert. | FACT (reproduced) |
| A member absent 4, 5, 6… times in a row triggers again **only after the 7‑day suppression window**, each time `absent_count == 3` still holds (it always does while the streak continues). So a long streak re‑alerts roughly weekly. | INFERENCE from code |
| The status flip to `warning` happens **inside** the de‑dupe branch: if a notification exists in the last 7 days (for this member *or a member whose id is a prefix match, see 2.3*), the status is **not** set. | FACT |
| Only `active → warning`. Already `warning`/`inactive` members are left untouched; there is **no escalation to `inactive`** anywhere. | FACT |
| The streak is per **class**: a student absent 2× in class A and 2× in class B never triggers. | FACT |
| The alert insert uses a prepared statement but the member lookup and the status update are string‑interpolated with `$mId` (an int cast) — not injectable, but inconsistent style. | FACT |
| The whole block runs **outside** the transaction, after the commit, with no try/catch; a DB error here would surface as a 500 *after* the attendance was already saved (client would see failure although data is saved). | INFERENCE |

### 2.3 De‑duplication defect (FACT — SQL semantics)

`'%"member_id":12%'` matches `{"member_id":12,…}` **and** `{"member_id":123,…}`,
`{"member_id":1234,…}`. On production ids are small integers (members ~1…300), so e.g. an alert
for member **123** within the last 7 days suppresses a legitimate alert **and the warning flag**
for member **12**. The same pattern is copied in Alert B (`maybeAlertLowAttendance`).
Correct forms would be `JSON_EXTRACT(data,'$.member_id') = ?` or `LIKE '%"member_id":12,%'`
/ `'%"member_id":12}%'`.

### 2.4 Who receives it (FACT — reproduced)

`target_roles = 'hr_dept,info_dept'`. The feed query (`NotificationCenterService::feed`, line 143)
is `FIND_IN_SET(?, n.target_roles) > 0 OR n.target_user_id = ? OR (both NULL)`. Reproduced on the
test server after triggering an alert:

| Role | Sees "Consecutive Absence Alert"? |
|---|---|
| hr_dept | yes |
| info_dept | yes |
| school_admin | **no** (feed total 0) |
| super_admin | **no** (same query; INFERENCE, not separately executed) |
| edu_dept / teacher | no |

Note the contradiction with the owner's 2026‑10‑18 decision that HR "uses" attendance data but
does not take it: the only automatic attendance alert goes to HR + Info, while the people who
*own* attendance (Education) and the administrators never see it.

### 2.5 Which write paths run it (FACT — reproduced)

| Write path | Who uses it | Alert A | Alert B | Summary / member totals |
|---|---|---|---|---|
| `admin/api_attendance.php?action=save_attendance` | web Teacher dashboard (`teacher.php:988`), web Attendance Taker (`attendance_taker.php:544`) | **yes** | no (dead, §3) | yes |
| `admin/api_attendance.php?action=scan_member` (barcode, marks `present`) | web Attendance Taker | no (only marks present) | no (dead) | yes |
| `POST /api/v1/attendance` (mobile draft, merge) | Flutter app | **no** | **no** | **no** |
| `POST /api/v1/attendance/submit` (mobile submit, replace + packet) | Flutter app | **no** | **no** | **no** |
| `admin/api_education.php?action=record_attendance` (single row) | **no caller found** in repo (grep of admin/js, dashboards, frontend, Mobile) | no | yes (workflow.php is included) | yes |
| `admin/api_education.php?action=batch_attendance` | **no caller found** | no | yes | yes |

Reproduction (mobile): three `/attendance/submit` calls on 2026‑10‑05/12/19 with member 900004
`absent` → rows written, `grade_submissions` packets created, **0 notifications**, member still
`active`, `total_attendance_rate`/`last_attendance_date` still NULL, no `attendance_summary` row.

Reproduction (web): one `save_attendance` with member 900000 absent (already 5 prior absences)
→ 1 notification, member → `warning`, summary row created, totals updated.

Consequence (INFERENCE): on production the alerting coverage depends entirely on **which client
the teacher uses**. If teachers mainly use the Flutter app (which is the documented primary tool
with draft autosave and "submit to Education"), **most attendance never reaches the alert logic**,
and `members.total_attendance_rate` / `attendance_summary` are stale for those classes.

---

## 3. Alert B — "Low Attendance Alert" (`attendance_issue`, < 70 % monthly)

**Location:** `admin/backend/services/AttendanceSummaryService.php` (`LOW_ATTENDANCE_THRESHOLD = 70.0`,
`ALERT_SUPPRESSION_DAYS = 7`, `maybeAlertLowAttendance()`, called from `recordSaved()`).
Introduced in `dfbf604` (2026‑08‑26). FACT.

### 3.1 Algorithm (FACT)

`recordSaved()` → `recompute()` the member's **calendar month (Gregorian) of the recorded date**
across all classes, rate = (present + 0.5·late)/total → `refreshMemberTotals()` → if rate
< 70: de‑dupe on `type='attendance_issue' AND data LIKE '%"member_id":<id>%'` within 7 days →
`sendNotification($conn, 'attendance_issue', 'Low Attendance Alert', '<name> has 12.5% attendance this month', priority high, data {member_id, attendance_rate})`.
`target_roles` come from `$NOTIFICATION_MATRIX['attendance_issue'] = ['super_admin','school_admin','info_dept']` in `workflow.php`.
It does **not** change `members.status`.

### 3.2 Why it never fires from the web sheet (FACT — reproduced)

```php
if (function_exists('sendNotification')) { sendNotification(...); }
```
`sendNotification` is defined **only** in `admin/backend/workflow.php`. `admin/api_attendance.php`
includes `config.php`, `EnrollmentService`, `AttendanceRecordService`, `AttendanceSummaryService` —
**not** `workflow.php` (`config.php` does not pull it in either; verified by reading the include
chain). Reproduction: after the web save above, two members had a 0 % October rate; the
`notifications` table contained **only** the consecutive‑absence row. The guard silently skips.

The two endpoints that *do* include `workflow.php` (`api_education.php` `record_attendance` /
`batch_attendance`) have no front‑end caller (grep across `admin/js`, `admin/dashboards`,
`frontend`, `Mobile/**/*.dart`). INFERENCE: Alert B has effectively **never fired in production**
unless something outside this repo calls those endpoints (UNKNOWN).

### 3.3 Semantics if it were alive

| Point | Tag |
|---|---|
| "This month" = **Gregorian** calendar month of the recorded date, not the Ethiopian month and not a rolling window. On the 1st–2nd Sunday of a month one absence = 0 % or 50 % → alert for almost everyone who misses the first session of the month. | FACT (formula) + INFERENCE (effect) |
| Suppression is per member for 7 days; a weekly class would re‑alert every week while under 70 %. | INFERENCE |
| HR is **not** a target even though HR is the audience of Alert A. | FACT |

---

## 4. Manual status management ("Attendance & Status" page)

**Location:** `admin/api_attendance_info.php` (+ Info dashboard JS `quickStatusChange`, `loadAtRisk`).

| Point | Tag |
|---|---|
| Actions `update_status` (one member) and `bulk_status` set any of `active|warning|inactive` (`archived` excluded in bulk; single update does not check current `archived`). Writes `activity_logs` ('Status Change' / 'Bulk Status Change' with optional free‑text reason). No notification is sent. | FACT |
| The other writers of `members.status` are only: Alert A (`warning`), `info_archive_member.php` (`archived`), `info_restore_member.php` (`active`), the generic edit form `info_manage_member.php` (field `status` passes through), and mobile `PUT /members/{id}` (`status` is in `$allowedFields`, behind `apiRolesPii()`). | FACT |
| `access_control.php` ROLE_MAP line 134: `api_attendance_info.php => super_admin, school_admin, info_dept, edu_dept, teacher, attendance_taker`. The `update_status`/`bulk_status` cases have **no further role check** → a teacher or attendance_taker session can set any member to `inactive` or back to `active`. | FACT |
| UI uses `prompt()` dialogs (free‑typed status string) — no reason required, no audit beyond `activity_logs`. | FACT |
| `at_risk_members` query: members `active|warning` whose **present‑only** rate (late not counted) over the last *N* days (default 30) is below *threshold* (default 50 %) **or who have zero rows** in the window — so every member of a class that simply was not marked in the window is listed as "at risk". | FACT |
| "Never attended" KPI = active/warning members with **no attendance row ever**. | FACT |

---

## 5. All the different "at‑risk" definitions in the product (FACT)

| Surface | Definition | Threshold |
|---|---|---|
| Info/HR dashboard card "AT‑RISK STUDENTS" | `members.status IN ('warning','inactive')` (non‑archived) | — (status driven) |
| Alert A (sets `warning`) | last 3 rows in one class all `absent` | 3 |
| Alert B (`attendance_issue`, dead) | monthly rate (present + ½ late) | < 70 % |
| Info "At‑Risk" tab (`at_risk_members`) | present‑only rate, rolling N days, or no rows | < 50 % (options 75/50/25/never) |
| Education triage roster (`EducationAnalyticsService::buildTriageRoster`) | `attendance_rate < AT_RISK_ATT` | < 60 % (plus score < 50 %) |
| Reporting Centre `edu_attendance_band` / `mezmur_attendance_band` | attended/sessions, current AY, holidays excluded, late = attended | excellent ≥ 90, good 75–89, fair 50–74, **low < 50** |
| Info Analytics Hub (`InfoAnalyticsService`) | group/trend rates only — **no per‑member risk** | — |
| Mezmur attendance (`api_mezmur.php`) | **no alerting at all** | — |
| AI assistant (`api_ai.php`) | prompts the model to describe "students at risk"; reads `members.status` counts and raw aggregates | free text |

Five different numeric thresholds (3 sessions, 50 %, 60 %, 70 %, 90/75/50 %) and three different
rate formulas (present‑only; present + ½ late; late = attended). INFERENCE: staff comparing the
dashboard card, the At‑Risk tab and the Reporting Centre will get three different lists for
"who is at risk", and none of them is tied to the automatic `warning` flag.

---

## 6. Lifecycle of the `warning` flag (end‑to‑end)

```
active ──(Alert A: 3 consecutive absents, WEB sheet only, not suppressed)──▶ warning
warning ──(manual: Info "Attendance & Status", edit form, mobile PUT)──▶ active | inactive
inactive ──(manual only)──▶ active | warning
any ──(info_archive_member)──▶ archived ──(info_restore_member)──▶ active
```

| Gap | Tag |
|---|---|
| No automatic recovery: a student flagged `warning` who then attends 20 weeks in a row stays `warning` until a human notices. | FACT |
| No automatic escalation `warning → inactive`. | FACT |
| No record of *why/when* a member became `warning` other than the notification row (which the bell can mark read / which has no retention). `activity_logs` is not written by Alert A. | FACT |
| Member profile pages show the status badge but not the triggering streak. | INFERENCE (not every profile view was read) |
| Status is global, but the trigger is per class — a member in two classes can be flagged by one and perfect in the other. | FACT |

---

## 7. Delivery / visibility of the alerts

| Point | Tag |
|---|---|
| Delivery channel = in‑app bell only (`admin/api_notifications.php` feed/summary; mobile `/api/v1/notifications`). No e‑mail, Telegram, FCM, SMS. `FailureAlertService` (Telegram) is for system failures, not attendance. | FACT |
| No cron / scheduler exists in the repo (`monitor/uptime_cron.php` is uptime only; prod crontab was empty on 2026‑10‑18 read‑only check). All alerting is request‑time side effect of a save. | FACT |
| Mobile feed routes a tapped `attendance_alert` to the member (`targetFor()` → `member_id`). | FACT |
| HR and Info both get Alert A in the web bell and in the Flutter app (same feed). school_admin / super_admin get neither alert A (not targeted) nor alert B (dead). | FACT |

---

## 8. Test coverage (FACT)

* `tests/security/test_attendance_summary_single_writer.py` — guards that only
  `AttendanceSummaryService` writes `attendance_summary`, that the threshold constant exists,
  that the formula is the (present + ½ late) one. It does **not** test that an alert is created.
* No test anywhere asserts "3 consecutive absences → notification/warning", nor that the mobile
  path alerts (it does not), nor the de‑dupe LIKE pattern.
* Mobile contract tests (`api/v1/tests`) were not read line‑by‑line — UNKNOWN whether any touches
  alerting (grep for `attendance_alert`/`warning` found none in `tests/` or `api/v1/tests`).

---

## 9. Verified reproduction log (local test DB, rolled back)

| Step | Result |
|---|---|
| Web `save_attendance` class 1, 2026‑10‑19: 900000 absent (6th in a row), 900002 absent (prev. late, absent), 900001/900006 present | `notifications`: 1 row `attendance_alert` for 900000 (target `hr_dept,info_dept`); 900000 → `warning`; 900002 stays `active` (streak broken by `late`); `attendance_summary` Oct rows 0 % for both; **no `attendance_issue` row** despite 0 % < 70 % |
| Feed as hr / info / school_admin | hr & info: 1 alert; school_admin: 0 |
| Mobile `POST /api/v1/attendance/submit` class 2 on 10‑05, 10‑12, 10‑19 with 900004 absent | 3 packets `submitted`, rows written; **0 new notifications**, 900004 `active`, totals NULL, no summary rows |
| Cleanup | rows/packets/notification deleted, members reset — `attendance` back to 70 rows, `notifications` 0 |

---

## 10. Open questions for the owner (needed before any change is proposed)

1. **Audience.** Who should receive automatic attendance alerts — Education (owners of the data), Info (status managers), HR, administrators? Today: A → HR+Info, B → admins+Info.
2. **Rule.** Keep "3 consecutive absences per class" as the `warning` trigger? Should `late`/`excused` break the streak? Should the rule be per class or per member across classes?
3. **Mobile parity.** Should the Flutter submit path run the same rules (it is the main teacher tool)? Or should alerting be computed centrally on the data (e.g. when Education approves a packet, or on every save regardless of client)?
4. **Low‑attendance alert.** Revive it (fix the dead include) — and if so, which window (Ethiopian month, rolling 4 weeks, academic term?) and which threshold? Or retire it in favour of the Reporting Centre bands?
5. **Recovery.** Should `warning` auto‑clear after N consecutive presences, or stay manual?
6. **Escalation.** Should prolonged absence (e.g. 6–8 consecutive) auto‑set `inactive`, or only recommend it?
7. **Permissions.** Should `teacher` / `attendance_taker` / `edu_dept` keep the ability to change member status via `api_attendance_info.php`? (Today they can.)
8. **One definition of "at risk".** Pick one formula/threshold set for the dashboard card, At‑Risk tab, Education triage and Reporting Centre bands — or document that they intentionally differ.
9. **Mezmur.** Should Mezmur attendance participate in any alerting? (Today: none.)

---

## 11. Files read (for traceability)

`admin/api_attendance.php` (full), `admin/backend/services/AttendanceRecordService.php`,
`admin/backend/services/AttendanceSummaryService.php` (full), `admin/backend/workflow.php`
(matrix, `sendNotification`, `updateAttendanceSummary`, feed delegates),
`admin/backend/services/NotificationCenterService.php` (feed/targetFor), `admin/api_notifications.php`
(summary/feed), `api/v1/routes/attendance.php` (full), `admin/backend/services/SubmissionService.php`
(`upsertAttendance`), `admin/api_education.php` (attendance cases, includes), `admin/api_attendance_info.php`
(full), `admin/access_control.php` (ROLE_MAP rows), `admin/dashboards/info-dept.php` (at‑risk card,
At‑Risk tab JS, status‑change JS), `admin/dashboards/teacher.php` / `attendance_taker.php` (save calls),
`admin/backend/services/EducationAnalyticsService.php` (triage constants), `admin/backend/reporting/ReportRegistry.php`
(bands), `admin/backend/services/InfoAnalyticsService.php` (function list), `admin/api_mezmur.php` (grep),
`admin/info_manage_member.php`, `api/v1/routes/members.php` (PUT whitelist), `Mobile/.../api_service.dart`
(attendance endpoints), `sql/012_runtime_schema_baseline.sql`, live `SHOW CREATE TABLE` for
`notifications`, `notification_reads`, `attendance`, `members`.

**ANALYSIS INCOMPLETE — not verified:** Flutter‑side handling of `attendance_alert` beyond
routing; whether any external/legacy client calls `api_education.php` `record_attendance`/`batch_attendance`
on production; production `notifications` contents (no production access used).
