# Attendance, Department Status & Alerts — Phase‑Based Implementation Plan

**Date:** 2026‑10‑19 · **Status:** PROPOSED — awaiting owner approval (no code, schema, or production change has been made)
**Governs:** `ATTENDANCE_DEPT_STATUS_ALERTS_REQUIREMENTS_2026-10-19.md` (R‑1…R‑14, locked baseline)
**Evidence base:** `ATTENDANCE_ALERTING_DEEP_RESEARCH_2026-10-19.md`, `ATTENDANCE_ALERTING_REQUIREMENTS_GAP_AND_OSS_RESEARCH_2026-10-19.md`
**Protocol:** PROJECT_RULES.md (plan → approve → implement per phase → test → push → owner pulls + runs SQL in phpMyAdmin).
Tags: FACT (verified in repo) · INFERENCE · UNKNOWN · **T‑n** = unresolved technical question (owner/tech decision needed) · **C‑n** = conflict with existing behaviour.

---

## 0. How to read this plan

Seven phases. Each phase is independently deployable, has its own SQL script(s), tests and approval gate. Later phases depend on
earlier ones as drawn:

```
Phase 0  Guardrails (permissions, calendar, stop legacy automation)      ── no new tables
Phase 1  Department status + School‑Admin policy settings                 ── 2 tables
Phase 2  Calculation engine + warnings + bell + dashboard card + rollout  ── 3 tables   (depends on 1)
Phase 3  Submission reopen/reject for attendance (Edu + Mezmur)           ── 0–1 table  (depends on 2)
Phase 4  Monthly reports (Ethiopian months) + one shared risk everywhere  ── 1 table    (depends on 2)
Phase 5  Assessment make‑up queue on Education reactivation               ── 2 tables   (depends on 1)
Phase 6  Lesson‑level Education attendance (structural)                   ── 2 tables   (depends on 2; separate approval)
```

Phases 0–2 deliver the owner's core (status separation, 5/8/10 warnings, bell, dashboard). Phases 3–5 complete §5, §9, §12.
Phase 6 is the only one that changes the shape of the `attendance` table and is therefore isolated (see C‑1 / T‑1).

---

## 1. Reuse inventory (what exists and will be reused) — FACT unless tagged

| Area | Existing asset | Reuse in |
|---|---|---|
| Education attendance rows | `attendance (member_id, class_id, academic_year_id, attendance_date, status present/absent/late/excused/holiday, notes, recorded_by)` UNIQUE `(member_id, class_id, attendance_date)`; writers `AttendanceRecordService::replaceSheet/mergeSheet` | Phase 2 reads; Phase 6 extends |
| Mezmur attendance rows | `mezmur_attendance (session_id, attendance_date, member_id, status present/late/absent/excused, marked_by)` UNIQUE `(attendance_date, member_id)`; `mezmur_days`; packets `mezmur_submissions (attendance_date, section, status, taker_id, reviewed_by, counts)` UNIQUE `(date, section)`; roster = members by `current_section` (`MezmurAttendanceService`) | Phase 1 exclusions, Phase 2 adapter |
| Education packets / review | `grade_submissions (submission_type='attendance', class_id, attendance_date, status incomplete/draft/submitted/approved/rejected/revision_needed, teacher_id, reviewed_by, review_notes, counts)`; `SubmissionService::upsertAttendance/attendanceReview/reviewTransitionError/teacherMayWriteAttendance`; web review action `api_communication.php?action=review_submission` already **requires a reason (≥ 3 chars) for every non‑approve transition** | Phase 2 eligibility, Phase 3 reopen/reject |
| Write paths | web `admin/api_attendance.php save_attendance/scan_member`; mobile `POST /api/v1/attendance` (draft merge) and `/attendance/submit`; Education `api_education.php record_attendance/batch_attendance` (no caller found); Mezmur web `api_mezmur.php save_sheet`, mobile `POST /api/v1/mezmur/sheet` | Phase 2 evaluator hooks |
| Rosters | `EnrollmentService::resolveRosterYear/fetchRoster` (web + mobile), grades rosters in `api/v1/routes/grades.php` (`ce.status='active'`), Mezmur section roster | Phase 1 exclusions |
| Calendar | `admin/backend/ethiopian_date.php` (`gregorian_to_ethiopian`, `ethiopian_to_gregorian`), `calendar_system.php` (`wbws_format_date`, `wbws_get_calendar_mode` default `ethiopian`), `ay_resolve()`; Flutter `lib/utils/ethiopian_calendar.dart` | all phases |
| Settings store | `dept_settings (setting_key, setting_value, updated_by)` with whitelist in `api_settings.php dept_save`; `system_settings` | Phase 1 (policy table preferred, see T‑4) |
| Notifications | `notifications` + `notification_reads`, `NotificationCenterService::feed` (exact `FIND_IN_SET(role, target_roles)`), `sendNotification()` in `workflow.php` with `$NOTIFICATION_MATRIX`; mobile feed routes by `data.member_id` | Phase 2 |
| Audit | `SecurityAuditService::record` (safe detail keys), `activity_logs`; Reporting Centre export log pattern | all phases |
| Access control | `admin/access_control.php $ROLE_MAP`, `FeatureGate.php`, `api/v1/core/acl.php` (`apiRolesPii`, `apiRolesAttendance`, `apiRequireClassAccess`) | Phase 0/1/2 |
| Reporting | Reporting Centre V2 (`ReportRegistry` with `R_ALL/R_HR/R_ADMIN/R_INFO`, `ReportQueryService`, openspout exports, saved views, bands 90/75/50 in `edu_attendance_band` / `mezmur_attendance_band`) | Phase 4 |
| Dashboards | Info/HR dashboard cards (`$atRiskStudents = warning + inactive`), Info "Attendance & Status" (`api_attendance_info.php`), Education triage (`EducationAnalyticsService::buildTriageRoster`, `AT_RISK_ATT = 60`), Analytics Hub (`InfoAnalyticsService`, group rates only) | Phase 2 card, Phase 4 unification |
| Assessments | `assessments (class_id, subject_id, term_id, academic_year_id, assessment_type, max_score, weight_percentage, due_date, is_published)`, `academic_records (member_id, class_id, subject_id, assessment_id, submission_id, score, max_score, recorded_by)`, marklist packets in `grade_submissions` | Phase 5 |
| Tests | `tests/security` (pytest pins, 2171 passing baseline), `tests/e2e/report_query_service_unit.php` harness pattern, Playwright `tests/e2e/*.py`, `api/v1/tests` contract tests, `tests/e2e/provision_env.sh` | every phase |

---

## 2. Conflicts between the requirements and current behaviour (must be resolved by the plan)

| ID | Conflict | Resolution in this plan |
|---|---|---|
| **C‑1** | §3 requires lesson/session‑level Education attendance ("subject, lesson, teacher"), but `attendance` has **one row per member per class per day and no subject/lesson column**; there is no lesson or timetable entity (FACT). | Phases 0–5 treat *class/day* as the session unit (the only unit that exists). Phase 6 introduces `attendance_sessions` and is approved separately (T‑1). |
| **C‑2** | §1.7 Ethiopian‑only, but `calendar_mode` setting (`dept_settings`, Super Admin "save_calendar_mode") allows `gregorian`; attendance screens use native `<input type="date">` pickers (Gregorian UI) (FACT for `attendance_taker.php`/`teacher.php`; other screens UNKNOWN until Phase 0 audit). | Phase 0: remove the Gregorian option (force `ethiopian`), inventory every date picker/format on affected screens, replace with the existing EC picker (`wbws_calendar_scripts`) or a shared EC component; keep Gregorian only in DB columns and API payloads (ISO), never rendered. |
| **C‑3** | §2 HR/Info only may change status, but `api_attendance_info.php update_status/bulk_status` is reachable by teacher/attendance_taker/edu_dept; `info_manage_member.php` edit form passes `status`; mobile `PUT /members/{id}` whitelists `status`. | Phase 0 closes all three server‑side. |
| **C‑4** | §1.4 automation must never change `members.status`, but `api_attendance.php` sets `warning` after 3 absences. | Phase 0 removes that UPDATE (legacy notification kept until Phase 2 replaces it). |
| **C‑5** | §5 "submitted is approved by default" vs. the web sheet creating **no packet at all** and the mobile draft path writing rows into the same table. | Phase 2: web save creates/updates a `submitted` packet (T‑2); evaluator counts only rows whose packet is `submitted`/`approved`; draft/incomplete/revision/rejected rows are ignored for streaks and rates. |
| **C‑6** | §9 monthly reports for Education, Mezmur, HR, Info via the reporting interface vs. owner decision of 2026‑10‑19 that future Reporting‑Centre datasets are **Info‑only** (V2‑4 paused). | Phase 4 needs an explicit owner decision (T‑5): either open a department‑scoped attendance dataset to edu_dept/mezmur_dept/hr_dept in the Reporting Centre, or deliver the monthly report inside each department dashboard and keep the Reporting Centre Info‑only. |
| **C‑7** | §6 bands retained (90/75/50) but Reporting Centre counts late as attended (1.0) and the Info tab counts late as absent (0). | Phase 4 recomputes both from the shared service (0.5/0.5). Saved views keep working; numbers may shift slightly — release note. |
| **C‑8** | Existing `attendance_summary` is keyed by **Gregorian** month and only written by the web path. | Not extended. Phase 4 adds `attendance_month_rollup` keyed by Ethiopian year/month for both departments. `attendance_summary` left in place (read only by `api_ai.php`) — retire later. |
| **C‑9** | Existing production members already carry `members.status = 'warning'` from the legacy rule. | Not touched by automation (R‑1.4). Phase 2 release note asks HR/Info to review them manually. |

---

## 3. Unresolved technical questions (answers needed at the phase indicated; recommendations given)

| ID | Question | Needed by | Recommendation |
|---|---|---|---|
| **T‑1** | Definition of "required sessions for the day" for Education when there is no timetable: (a) every `attendance` row of that member on that date (class/day unit), or (b) a new lesson model (Phase 6). | Phase 2 (a) / Phase 6 (b) | Use (a) now; design Phase 6 so (b) slots in without re‑migrating warnings. |
| **T‑2** | Web `save_attendance` has no packet. Create a `grade_submissions` attendance packet with status `submitted` on every web save (teacher/taker) and on Education edits? | Phase 2 | Yes — makes "submitted by default" true for all clients and gives Phase 3 something to reopen. |
| **T‑3** | Mezmur eligibility: are Mezmur rows official when a `mezmur_submissions` row exists for (date, section), or always? Mobile Mezmur drafts exist (`docs/MOBILE_OFFLINE_SYNC_S2_BACKGROUND_F20.md` row 4) — INFERENCE, verify in Phase 2. | Phase 2 | Official = packet status `submitted`/`approved`; otherwise ignored. |
| **T‑4** | Where thresholds live: new `attendance_policies` table (one row per department, typed columns, `updated_by`) vs. key/value rows in `dept_settings`. | Phase 1 | New table (typed, validated, history via audit events). |
| **T‑5** | Reporting surface for §9 (see C‑6). | Phase 4 | Department dashboards get the monthly report (edu/mezmur/hr/info); Reporting Centre gets the same dataset for Info + admins; HR access to it decided in the pending feedback round. |
| **T‑6** | Default values for the two unspecified policy fields: low‑attendance alert threshold and minimum marked sessions. | Phase 1 | 75 % and 3 sessions (School Admin can change). |
| **T‑7** | Escalation after closure: if staff close a warning at stage 5 and the same streak reaches 8, should the system re‑open that warning with stage 8 (recommended) or open a second warning? | Phase 2 | Re‑open the same warning (dedupe per streak), log an `escalated` event, notify again. |
| **T‑8** | Make‑up attempt storage: new `assessment_makeup_attempts` table linked to the original `academic_records` row (recommended) vs. new columns on `academic_records`. | Phase 5 | Separate table; original row never modified. |
| **T‑9** | Flutter release: Phases 1–2 change what the app shows (department status, warnings read‑only, rosters shrink). Ship as one APK release after Phase 2, or after each phase? | Phase 1 | One APK after Phase 2; server stays backward compatible (new fields additive). |
| **T‑10** | Deployment flags: gate new behaviour behind `FEATURE_ATTENDANCE_V2` (FeatureGate) so the owner can enable per phase after running SQL? | Phase 0 | Yes — same pattern as Reporting Centre (`available:false` until migration applied). |

---

## 4. Target architecture (summary)

```
                     ┌──────────────────────────────────────────────────────┐
 write paths ──────▶ │ AttendancePolicyService  (per‑dept thresholds, cache) │
 web save            ├──────────────────────────────────────────────────────┤
 mobile submit       │ DepartmentStatusService  (periods, active‑on(date))   │
 edu edit            ├──────────────────────────────────────────────────────┤
 mezmur save_sheet   │ AttendanceRiskService                                 │
 mezmur mobile sheet │   adapters: EduAttendanceSource / MezmurAttendanceSource│
 review/reopen       │   eligibleSessions(member, dept, from, to)            │
 reactivation        │   dayOutcomes → streak(member, dept)                  │
 rollout script      │   monthRollup(member, dept, ecYear, ecMonth)          │
                     │   band(rate) 90/75/50 · riskFor(member, dept)         │
                     ├──────────────────────────────────────────────────────┤
                     │ AttendanceWarningService                              │
                     │   evaluate(member, dept) → open/escalate/auto‑deact   │
                     │   close(warning, outcome, note, actor)                │
                     │   notify (bell) · audit                               │
                     └──────────────────────────────────────────────────────┘
 readers: dashboards card · warnings list/close UI · At‑Risk tab · Edu triage · Reporting Centre · /api/v1 (Flutter)
```
All calculations are server‑side (R‑10 of the earlier decision set / §1 here); clients only render.

---

## 5. Phases

### Phase 0 — Guardrails (no new tables) · small

**Goal:** make the system comply with §1.4, §1.7, §2 (permissions) before any new feature.

| Item | Change | Files (expected) |
|---|---|---|
| 0.1 | `api_attendance_info.php`: `update_status`/`bulk_status` → allow only `hr_dept`, `info_dept` (+ archive paths unchanged). `edu_dept/teacher/attendance_taker` keep read actions only. Audit via `SecurityAuditService` + `activity_logs` (already) with reason. | `admin/api_attendance_info.php`, `admin/access_control.php` |
| 0.2 | `info_manage_member.php`: `status` field only writable by hr_dept/info_dept (server‑side), and only `active/inactive` (never `warning` by hand unless owner wants it — UNKNOWN, default: allow active/inactive only). | `admin/info_manage_member.php` |
| 0.3 | Mobile `PUT /members/{id}`: remove `status` from `$allowedFields` unless role ∈ {hr_dept, info_dept}; contract test. | `api/v1/routes/members.php`, `api/v1/tests` |
| 0.4 | Remove `UPDATE members SET status='warning'` from `api_attendance.php` (legacy notification stays until Phase 2). Fix the `LIKE '%"member_id":N%'` prefix bug (`JSON_EXTRACT`) in both legacy places so the interim is correct. | `admin/api_attendance.php`, `AttendanceSummaryService.php` |
| 0.5 | Ethiopian‑only: remove `gregorian` from `save_calendar_mode` (force `ethiopian`), audit + fix date rendering/pickers on attendance screens (web taker/teacher/edu/mezmur/info, notifications text, exports). Produce a checklist file `docs/planning/EC_ONLY_AUDIT_<date>.md` listing every screen touched and every remaining Gregorian leak outside attendance (for later). | `admin/dashboards/super-admin.php`, `admin/api_settings.php`, attendance dashboards, `calendar_system.php` |
| 0.6 | Feature flag `FEATURE_ATTENDANCE_V2` wired into `FeatureGate` (off by default). | `FeatureGate.php`, `env.example.php` |

**Tests:** security pins (teacher/taker/edu 403 on status change web + mobile; hr/info 200; `members.status` never written by `api_attendance.php`; no `gregorian` option), Playwright smoke on taker/teacher sheets, existing suites green.
**Risk:** low. **Rollback:** revert commit.
**Gate 0:** owner approves Phase 0 → implement → push → owner pulls (no SQL).

---

### Phase 1 — Department status + policy settings · medium

**Schema (`sql/071_department_status_and_policy.sql`, MariaDB‑safe, idempotent):**

```sql
CREATE TABLE member_department_periods (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  department ENUM('edu','mezmur') NOT NULL,
  status ENUM('active','inactive') NOT NULL,
  started_at DATETIME NOT NULL,            -- effective from
  ended_at DATETIME NULL,                  -- NULL = current period
  source ENUM('manual','auto','rollout') NOT NULL,
  changed_by INT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  warning_id BIGINT UNSIGNED NULL,         -- set when source='auto' (Phase 2)
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mdp_member_dept (member_id, department, ended_at),
  KEY idx_mdp_dept_status (department, status, ended_at),
  CONSTRAINT fk_mdp_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
);
-- Rule: no row ⇒ active since academic‑year start (so no mass back‑fill is required).
CREATE TABLE attendance_policies (
  department ENUM('edu','mezmur') PRIMARY KEY,
  consecutive_threshold TINYINT UNSIGNED NOT NULL DEFAULT 5,
  prolonged_threshold   TINYINT UNSIGNED NOT NULL DEFAULT 8,
  critical_threshold    TINYINT UNSIGNED NOT NULL DEFAULT 10,
  auto_deactivate       TINYINT(1) NOT NULL DEFAULT 0,
  low_rate_threshold    DECIMAL(5,2) NOT NULL DEFAULT 75.00,   -- T‑6
  min_sessions          TINYINT UNSIGNED NOT NULL DEFAULT 3,   -- T‑6
  updated_by INT UNSIGNED NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT IGNORE INTO attendance_policies (department) VALUES ('edu'),('mezmur');
```
Validation server‑side: `1 ≤ consecutive < prolonged < critical ≤ 60`, `0 < low_rate ≤ 100`, `1 ≤ min_sessions ≤ 30`. Every change → `SecurityAuditService` event `Attendance Policy Updated` with old/new.

**Backend:**
- `DepartmentStatusService`: `currentStatus(member, dept)`, `isActiveOn(member, dept, date)`, `activePeriods(member, dept, from, to)`, `deactivate/reactivate(member, dept, actor, reason, source)` (closes current period, opens new; reactivation **always** starts a new period ⇒ streak reset by construction, §11).
- `AttendancePolicyService`: `for(dept)` cached per request.
- API `admin/api_department_status.php` (new): `GET status&member_id`, `GET history&member_id`, `POST set` (hr_dept/info_dept only, CSRF, reason required for deactivate — recommended also for reactivate), rate‑limited; `admin/api_attendance_policy.php` (new): `GET`, `POST save` (school_admin only; super_admin read). Mobile: `GET /members/{id}/department-status` (read; PII roles + HR/Info), `POST /members/{id}/department-status` (hr/info only).
- **Exclusions (§11, server‑side):** `EnrollmentService::fetchRoster` filters out members inactive in `edu` on the sheet date; grades rosters (`api/v1/routes/grades.php`, web marklist builders) same; Mezmur section roster filters `mezmur`‑inactive; dashboards "active member" counts per department; Reporting Centre gets `edu_status`/`mezmur_status` fields (Phase 4). Inactive members keep historical rows and remain visible in history screens (member profile, Reporting Centre). A grep inventory of every `ce.status = 'active'` / section‑roster call site is step 1 of this phase and is attached to the PR.
- Sheets never create rows for excluded members (so "inactivity must not create new absences" holds by construction).

**UI:** HR + Info member profile: two badges (Education / Mezmur) + "Change" (reason prompt → modal, not `prompt()`), history table with EC dates. School Admin → Settings → "Attendance policy" (two columns Edu / Mezmur). Education/Mezmur dashboards: badge read‑only. Flutter: badges on member detail (read‑only; HR/Info change action in the HR screens) — released with Phase 2 APK (T‑9).

**Tests:** unit (periods math: overlapping, same‑day reactivation, `isActiveOn` boundaries), security pins (only hr/info may POST set; school_admin only may save policy; teacher 403 everywhere; policy validation 400s), roster tests (inactive member absent from Edu roster but present in Mezmur roster and vice‑versa), contract tests for mobile endpoints, Playwright for the profile badges + settings form.
**Risk:** medium — roster call‑site coverage. Mitigation: inventory + tests per call site; flag off until verified on the test server.
**Gate 1:** owner approves → implement → push → owner runs `sql/071` → enable flag.

---

### Phase 2 — Calculation engine, warnings, bell, dashboard card, rollout · large (core deliverable)

**Schema (`sql/072_attendance_warnings.sql`):**

```sql
CREATE TABLE attendance_warnings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department ENUM('edu','mezmur') NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  kind ENUM('consecutive','low_month') NOT NULL,
  stage ENUM('standard','prolonged','critical') NULL,   -- consecutive only; escalates in place (T‑7)
  dedupe_key VARCHAR(64) NOT NULL,     -- consecutive: dept|member|streak_start ; low_month: dept|member|ecY|ecM
  threshold_value DECIMAL(6,2) NOT NULL,
  streak_length SMALLINT UNSIGNED NULL, streak_start DATE NULL, streak_last DATE NULL,
  ec_year SMALLINT NULL, ec_month TINYINT NULL, sessions SMALLINT NULL, rate DECIMAL(5,2) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  source ENUM('live','rollout') NOT NULL DEFAULT 'live',
  opened_at DATETIME NOT NULL, closed_at DATETIME NULL, closed_by INT UNSIGNED NULL,
  outcome ENUM('contacted','excused','escalated','deactivated','no_action') NULL,
  closure_note VARCHAR(500) NULL,
  auto_deactivated TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aw_dedupe (dedupe_key),
  KEY idx_aw_open (department, status, opened_at),
  KEY idx_aw_member (member_id, department, status)
);
CREATE TABLE attendance_warning_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warning_id BIGINT UNSIGNED NOT NULL,
  event ENUM('opened','escalated','reopened','closed','auto_deactivated','notified') NOT NULL,
  actor_user_id INT UNSIGNED NULL,            -- NULL = system
  payload TEXT NULL,                          -- JSON: stage, streak, dates, rate, outcome, note
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_awe_warning (warning_id, created_at),
  CONSTRAINT fk_awe_warning FOREIGN KEY (warning_id) REFERENCES attendance_warnings(id) ON DELETE CASCADE
);
-- grade_submissions: no change. Web saves start writing packets (T‑2).
```

**Calculation rules (server, `AttendanceRiskService`):**
1. **Eligible sessions** for (member, dept, from, to): rows in the dept table whose date lies inside one of the member's *active periods*, inside the current Ethiopian academic year (`ay_resolve`), whose status ≠ `holiday`, and whose packet (`grade_submissions` attendance / `mezmur_submissions`) is `submitted` or `approved` (C‑5, T‑3). Rows with no packet, or packet in `draft/incomplete/revision_needed/rejected`, are **not** eligible.
2. **Day outcome** (Education, T‑1 option a): group eligible rows by date; day = `absent` only if every row that day is `absent`; otherwise `attended` (present/late/excused). Mezmur: one row per day already.
3. **Streak**: order day outcomes descending from the latest eligible day; count consecutive `absent` days until the first `attended` day or the start of the current active period. `streak_start` = earliest date of the run. Days with no eligible session are skipped (they neither break nor extend the streak).
4. **Rate** for any window: `present_eq = present + excused + 0.5·late`, `absent_eq = absent + 0.5·late`, `rate = present_eq / sessions × 100`; `sessions = 0 ⇒ NULL` ("No Data", never at risk). Band: ≥90 excellent, ≥75 good, ≥50 fair, else low.
5. **Risk object** returned to every consumer: `{department, status(active/inactive), streak:{length,start,last}, month:{ec_year,ec_month,sessions,rate,band}, year:{sessions,rate,band}, open_warning:{id,kind,stage}|null}`.

**Warning rules (`AttendanceWarningService::evaluate(member, dept)`), idempotent, run after every official write:**
- `streak ≥ consecutive_threshold` → open `consecutive` warning (stage standard) keyed by `streak_start`; already open → update `streak_length/streak_last`. `≥ prolonged_threshold` → stage prolonged (event + notify again). `≥ critical_threshold` → stage critical (event + notify); **if** `auto_deactivate = 1` → `DepartmentStatusService::deactivate(source='auto', warning_id)` + event `auto_deactivated` + notify "member deactivated in <dept>"; if `0` → notification text "recommend review of department status". A closed warning whose streak reaches a higher stage is **re‑opened** (T‑7).
- Monthly (Phase 2 computes it, Phase 4 reports it): after recompute of the EC month containing the saved date, if `sessions ≥ min_sessions` and `rate < low_rate_threshold` → open `low_month` warning keyed by (ec_year, ec_month); if the month recovers above threshold the warning stays open until closed (R‑5 of §5 earlier: "returning must not auto‑close").
- Never writes `members.status` (R‑1.4). Never crosses departments.
- **Recalculation after edits/reopen/reject** (Phase 3 wires the triggers; Phase 2 ships the function): recompute streak; if an open `consecutive` warning's streak no longer meets the threshold (e.g. a day was corrected to present), the warning is **not deleted** — it receives an event `invalidated` and status stays open with a visible "no longer meets threshold" flag for staff to close (audit‑safe; avoids silent disappearance). INFERENCE: acceptable under §13 "preserve history"; confirm (add to T‑7 answer).

**Evaluator hooks (all inside the write transaction's success path, failures logged, never failing the save):** `api_attendance.php save_attendance` (after packet upsert, T‑2) · `/api/v1/attendance/submit` (not `/attendance` drafts) · `api_education.php` attendance edits · Mezmur `save_sheet` + `/api/v1/mezmur/sheet` when the sheet is submitted · Phase 3 review transitions · Phase 1 reactivation (resets by construction; evaluate to clear stale month data).

**Notifications (bell only, §8):** type `attendance_warning`, `priority high`, `target_roles` = `edu_dept,hr_dept,info_dept` or `mezmur_dept,hr_dept,info_dept`; **not** school_admin/super_admin; teachers/takers not targeted (read‑only via API). `data` = `{warning_id, department, member_id, kind, stage, streak_length, streak_start_ec, streak_last_ec, absence_dates_ec[], ec_year, ec_month, rate, auto_deactivated}`; title/message in English + Amharic with **EC dates only**. One notification per event (`opened`, `escalated`, `auto_deactivated`, `reopened`); dedupe is by the warning row, not by a 7‑day LIKE. `NOTIFICATION_MATRIX['attendance_warning']` added; legacy `attendance_alert` producer removed; legacy `attendance_issue` producer removed (it never fired).

**APIs:** `admin/api_attendance_warnings.php` (new): `GET list` (filters: department, status, kind, stage, member, EC month range; role scoping: edu_dept → edu only, mezmur_dept → mezmur only, hr/info/school_admin/super_admin → both; teacher/taker → own classes'/sections' members, read‑only), `GET detail&id` (events, absence dates, lesson/class rows, cross‑dept summary for HR/Info), `POST close` (outcome required, note optional; edu_dept closes edu, mezmur_dept closes mezmur, hr/info both; admins **403** per §8), `GET member_summary&member_id` (both departments, for HR/Info). Mobile: `GET /attendance/warnings`, `GET /attendance/warnings/{id}`, `POST /attendance/warnings/{id}/close`, `GET /members/{id}/attendance-risk` (replaces client‑side colouring). All CSRF/rate‑limited/audited.

**UI:** Info + HR + Education + Mezmur dashboards: **"Open Attendance Warnings"** card replaces "AT‑RISK" (two counters Edu / Mezmur, by stage) → warnings list page (table: member, department, reason — "7 consecutive absences, ጥቅምት 2–23" or "Low month ጥቅምት 2019: 42 %", stage, opened (EC), status, actions). Close dialog with outcome radio + optional note. Member profile: warnings history tab. School Admin: read‑only list + report (no close button, server also 403). Teacher/taker screens: read‑only indicator on the sheet row ("open warning") — no PII beyond what they already see. Flutter: HR/Info/Edu/Mezmur warnings screen (list, detail, close) + teacher read‑only indicator; bell deep‑links to warning detail (feed `targetFor` extended with `warning_id`).

**Rollout script (`tools/attendance_rollout_2026.php`, CLI, run by owner once; dry‑run default, `--apply`):** for every member × department: compute streak and current EC month over the current EC academic year using the rules above (packet‑eligibility respected — unsubmitted days ignored), open warnings with `source='rollout'` where thresholds are met, no notifications for rollout rows (they appear in the list, flagged "system‑generated at rollout"), summary printed and written to `SecurityAuditService`. Idempotent (dedupe keys).

**Tests:** PHP unit harness for the service (fixture matrix: late/excused breaks, multi‑class day, holiday gaps, inactive period gap, reactivation reset, drafts ignored, revision_needed ignored, min_sessions guard, thresholds from policy, escalation in place, auto‑deactivate on/off, dedupe on re‑save, Mezmur isolation from Edu) ≥ 60 cases; security pins (recipient roles, close permissions incl. admin 403, no `members.status` write anywhere in attendance code, bell not sent to admins); API contract tests (mobile submit triggers warning; draft does not); Playwright (card, list, close flow, EC dates only — regex forbids `\d{4}-\d{2}-\d{2}` in rendered attendance UI); rollout script dry‑run on the seeded test DB with expected counts.
**Risk:** medium‑high (touches every write path). Mitigations: hooks are additive and wrapped; flag‑gated; legacy producers removed only after new path verified on the test server; APK released after server deploy (server tolerant of old app).
**Gate 2:** owner approves → implement → push → owner runs `sql/072` → owner runs rollout dry‑run, reviews counts, then `--apply` → enable flag → APK release.

---

### Phase 3 — Submission reopen / reject for attendance (Edu + Mezmur) · small–medium

- Reuse `SubmissionService` transitions and the existing **reason ≥ 3 chars** rule; expose for attendance packets: web Education review screen (already lists packets — INFERENCE: verify UI exists for attendance type) and Mezmur review; mobile endpoints `POST /attendance/review` (edu_dept), `POST /mezmur/review` (mezmur_dept).
- Transitions: `submitted|approved → revision_needed` (reopen) / `rejected` (reject), both with reason; teacher re‑submit → `submitted`. Each transition: `SecurityAuditService` + `grade_submissions.review_notes/reviewed_by/reviewed_at` (exists) and a new append‑only `attendance_submission_events` table **only if** the existing columns cannot hold history (they hold the last review only) — proposal: add `sql/073_submission_events.sql` (packet_id, from, to, actor, reason, at).
- On every transition: `AttendanceWarningService::evaluate` for all members on the packet (rows become ineligible/eligible).
- Tests: transition matrix, reason required (400), permission (teacher cannot reopen), warnings recomputed (e.g. reopen removes eligibility → warning flagged, not deleted).
**Gate 3.**

---

### Phase 4 — Monthly reports (Ethiopian months) + one shared risk everywhere · medium

**Schema (`sql/074_attendance_month_rollup.sql`):** `attendance_month_rollup (department, member_id, ec_year, ec_month, sessions, present_eq, absent_eq, rate, band, below_threshold TINYINT, computed_at, PRIMARY KEY(department, member_id, ec_year, ec_month))` — derived cache written by `AttendanceRiskService::monthRollup` (idempotent), refreshed on every official write and by a lazy "refresh if older than N minutes" on report open (same pattern as `InfoAnalyticsService::ensureRollupFresh`; no cron).
**Reports:**
- Department dashboards (Edu, Mezmur) and HR/Info: "Monthly attendance" page — EC year/month filter (EC picker), table (member, section/class, sessions, present_eq, absent_eq, rate, band, below‑threshold flag, open warnings), "below threshold only" toggle, export via openspout with EC dates. Historical months preserved (rollup rows are never deleted; recomputed only when source rows change).
- Reporting Centre: new fields on the members grain — `edu_status`, `mezmur_status`, `edu_month_rate`, `mezmur_month_rate`, `edu_open_warning`, `mezmur_open_warning` with an **EC month parameter**; bands switched to the shared formula (C‑7). Visibility per T‑5 decision; defaults to Info + admins (current rule).
- **Unification:** Info "At‑Risk" tab, Education triage (`AT_RISK_ATT`), Analytics Hub member drill‑downs, dashboard cards, Reporting Centre bands and mobile `/members/{id}/attendance-risk` all call `AttendanceRiskService` — no local thresholds remain (grep‑pinned by a security test: forbidden literals `< 50`, `< 60`, `70.0` in attendance code).
**Tests:** rollup math vs. harness, EC month boundaries (Pagume), export EC‑only, pins for unification, Reporting Centre harness update, saved‑views compatibility.
**Gate 4.**

---

### Phase 5 — Assessment make‑up queue on Education reactivation · medium

**Schema (`sql/075_assessment_followups.sql`):**
`assessment_followups (id, member_id, period_id → member_department_periods, assessment_id, class_id, subject_id, teacher_id, decision ENUM('pending','makeup_required','exempt','not_applicable'), decided_by, decided_at, note, created_at)` UNIQUE (member_id, period_id, assessment_id);
`assessment_makeup_attempts (id, followup_id, original_record_id → academic_records NULL, score, max_score, recorded_by, recorded_at, remarks)` (T‑8).
**Flow:** on Education reactivation (Phase 1 service emits an event) → find `assessments` of the member's class(es) whose `due_date` (or term window when NULL) falls inside the inactive period and for which no `academic_records` row exists → create `pending` follow‑ups → notify the responsible teacher(s) (bell, `teacher_assignments` subject/class match) and edu_dept. Teacher queue UI (web teacher dashboard + Flutter): decide per row; `makeup_required` enables "record attempt" (separate row, linked; original never overwritten; no automatic zero). Report cards/marklists read the make‑up attempt when present (INFERENCE: `ReportCardService` must be told which score counts — proposal: make‑up supersedes for display, original retained; confirm in Gate 5).
**Permissions:** teacher assigned to class+subject or edu_dept; server‑side via `apiRequireClassAccess`; HR/Info read‑only.
**Tests:** detection window, no duplicates on repeated reactivation, decision transitions, attempt linking, report card precedence, permissions.
**Gate 5.**

---

### Phase 6 — Lesson‑level Education attendance (structural; separate approval)

**Why separate:** C‑1. Needs a session/lesson entity before "subject, lesson, teacher" can be recorded. **Proposal:** `attendance_sessions (id, class_id, subject_id, teacher_id, session_date, slot, academic_year_id, created_by)` + `attendance.session_id` (nullable at first; new unique `(member_id, session_id)`), sheets keyed by session, "required sessions for a day" = sessions created for that class/date (teachers/Education create them, as Mezmur creates `mezmur_days`). Streak rule (T‑1 option b) switches to session‑aware day outcomes without changing warning rows. Flutter sheet UI, offline sync (`sync.php` cursor entity `attendance`) and Education analytics all change. Size: large; recommended only after Phases 0–5 are stable.
**Gate 6:** separate plan document before approval.

---

## 6. Cross‑cutting requirements

| Topic | Plan |
|---|---|
| **Ethiopian calendar only (§1.7)** | Server renders EC strings for every user‑facing date (`gregorian_to_ethiopian`), APIs return both ISO (machine) and `*_ec` (display) fields; Flutter uses `ethiopian_calendar.dart`; Playwright/API tests assert no ISO date in rendered attendance UI or exports; Phase 0 audit file tracks leaks outside attendance for a later sweep. |
| **Audit (§13)** | Every create/close/escalate/auto‑deactivate/status change/policy change/review transition → `SecurityAuditService` (+ `activity_logs` where staff already look). Append‑only event tables for warnings and submissions. Nothing is deleted; closures and invalidations are flags/events. |
| **Separation (§1)** | Department is a column on every new table; services take `dept` explicitly; tests assert cross‑department isolation. |
| **Server authority (§1.6, §5)** | All thresholds, streaks, rates, bands computed server‑side; mobile endpoints return the risk object; Flutter removes local colouring logic. |
| **Shared hosting** | No new vendor libraries; no cron; per‑request work bounded (evaluate only the members on the saved sheet; rollups per member/month; indexes above). Rollout script is the only full scan (CLI, once). |
| **Flags & rollback** | `FEATURE_ATTENDANCE_V2` (Phase 0) + per‑table `available:false` UX until SQL applied. Rollback = flag off; tables retained (no destructive migration in any phase). |
| **Deployment procedure per phase** | agent pushes to both remotes → owner `git pull` → owner runs `sql/07x` in phpMyAdmin → (Phase 2 only) owner runs rollout dry‑run then `--apply` → owner enables flag → agent runs live sweeps against the test server copy, owner spot‑checks production UI. |

## 7. Test matrix (maps to §14.7)

| §14.7 item | Where covered |
|---|---|
| Department separation | Phase 1 roster tests, Phase 2 harness isolation cases, Phase 4 pins |
| Permissions | Phase 0 pins (status), Phase 1 (policy/status APIs), Phase 2 (close/recipients/admin 403), Phase 3 (review), Phase 5 (teacher scope) |
| Absence calculations | Phase 2 harness (formula, late 0.5/0.5, excused, holidays, multi‑class day, No Data) |
| Incomplete attendance | Phase 2 harness + API contract (draft never counts; revision/rejected ignored) |
| Warnings | Phase 2 harness (open/escalate/reopen/dedupe/auto‑deactivate on/off), Playwright close flow |
| Reactivation | Phase 1 period tests, Phase 2 streak reset cases, Phase 5 queue creation |
| Assessment make‑ups | Phase 5 |
| Historical preservation | Every phase: "no DELETE" pins on new tables; closed warnings/rollups/periods remain queryable; Phase 3 events |
| Reporting | Phase 4 rollup math, EC‑only exports, Reporting Centre harness |
| Ethiopian calendar | Phase 0 audit + Playwright/API regex pins in Phases 2 and 4 |

## 8. Deployment risks (summary)

1. **Roster exclusion breadth (Phase 1)** — a missed call site shows an inactive member on a sheet. Mitigation: inventory + per‑site test; flag.
2. **Write‑path hooks (Phase 2)** — a bug in the evaluator must never block attendance saving: hooks are post‑commit, try/catch, logged, and covered by contract tests.
3. **Rollout warnings volume** — the first run may open many warnings (long‑standing absences). Dry‑run with counts per department before `--apply`; rollout rows send no bell notifications.
4. **Numbers shift** — Reporting Centre bands move to 0.5/0.5 (C‑7) and inactive members leave denominators; release note to Info/HR.
5. **APK coordination (T‑9)** — old app continues to work; new fields are additive; warnings screens require the new APK.
6. **Legacy `warning` statuses (C‑9)** — manual HR/Info review; automation never touches them.

## 9. Approval checklist for the owner

- [ ] Approve the phase split (0–5 now, 6 later) or request a different order.
- [ ] Answer T‑1 … T‑10 (or "use recommendation").
- [ ] Decide C‑6 / T‑5 (reporting surface for §9).
- [ ] Approve **Phase 0** to start (no SQL).
- [ ] Confirm the test‑server rebuild may proceed (sandbox PHP/MariaDB were reset; rebuild needed before Phase 0 tests).

Nothing is implemented until the relevant gate is approved.
