# Attendance Alerting — Phase 5 Implementation Notes (2026‑10‑19)

Scope: **plan §Phase 5** (`ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN_2026-10-19.md`) implementing the
locked requirements **§11 Reactivation** and **§12 Assessment follow‑up** of
`ATTENDANCE_DEPT_STATUS_ALERTS_REQUIREMENTS_2026-10-19.md`, with owner decision **T‑8**
(make‑up attempts in a separate table). Approved 2026‑10‑19 ("ok continue to the next").

Everything is additive, behind `FEATURE_ATTENDANCE_V2`, and answers `available:false` until
`sql/075` is applied. Nothing in `assessments` / `academic_records` is altered or deleted.

---

## 1. What was built

| Area | File | Notes |
|---|---|---|
| Schema | `sql/075_assessment_followups.sql` | `assessment_followups` (UNIQUE member+period+assessment, FK → members / member_department_periods (BIGINT) / assessments, CASCADE) + `assessment_makeup_attempts` (FK → followup, nullable `original_record_id`). MariaDB‑safe, idempotent. |
| Service | `admin/backend/services/AssessmentFollowupService.php` | Detection, queue, decisions, attempts, bell, audit, report‑card precedence helper. |
| Hook | `DepartmentStatusService::setStatus` | After the commit + audit: `dept==='edu' && status==='active' && previous==='inactive'` → `safeOnEducationReactivation(member, closedInactivePeriodId)`. Never throws; result returned as `followups` key (additive). |
| Web API | `admin/api_assessment_followups.php` | `list / counts / detail / member` (GET) · `decide / attempt` (POST + CSRF). Rate‑limited. |
| Web UI | `admin/assessment_followups.php` + `admin/js/assessment_followups.js` | Standalone queue page (cards, filters, detail dialog with decision + make‑up form). EC dates only. |
| Dashboards | `admin/dashboards/teacher.php` (card `af-dashboard-card`, teacher‑scoped counts), `admin/dashboards/edu_dept.php` (card, all) | Link to the queue. |
| Mobile API | `api/v1/routes/assessment_followups.php` mounted from `routes/grades.php` at `/grades/followups` | Same rules as the web API; mounted **before** the active‑year check (§12 historical access). |
| Report card | `ReportCardService::applyMakeupPrecedence` | Latest make‑up supersedes the shown score; `is_makeup:1`; `original_score` kept on the row; synthesizes a row when no original existed. |
| Guards | `admin/access_control.php`, `FeatureGate.php` | New files registered (and the Phase 4 `attendance_monthly` pair that had been missed). |
| Tests | `tests/security/test_attendance_phase5.py` | 9 static + 6 live (15/15 locally). Full security suite **2266 passed / 0 failed / 454 skipped**. |

## 2. Behaviour (as implemented)

**Detection (on Education reactivation only)** — for the inactive period that was just closed
(`started_at..ended_at`), assessments of classes the member is enrolled in
(`class_enrollments` status active/completed, same academic year) where

* `due_date` is inside the window, **or** `due_date IS NULL` and the assessment's term window
  overlaps the inactive period;
* the member has **no scored** `academic_records` row for the assessment;
* the member has **no follow‑up already** for that assessment (any period);

→ one `pending` follow‑up each (`INSERT IGNORE`, UNIQUE key ⇒ idempotent). Assessments with
neither a due date nor term dates cannot be placed in time and are skipped (limitation, see §4).

**No automatic score.** The service never writes `academic_records` (static test pins it).

**Bell (in‑app only, §7)** — one row per responsible teacher (`target_user_id`; teachers =
`teacher_assignments` active, same class, subject match or class‑wide) + one row for the
`edu_dept` role. Type `assessment_followup`; the Notification Center routes it to the member.

**Decisions** — `pending → makeup_required | exempt | not_applicable` (and back to `pending`
while nothing is recorded). `exempt` / `not_applicable` require a short note. Once a make‑up
attempt exists the decision is locked to `makeup_required`.

**Make‑up attempt** — only when `makeup_required`; `0 ≤ score ≤ max` (max defaults to the
assessment's); stored in `assessment_makeup_attempts` with `original_record_id` linked when an
original row exists (verified: a NULL‑score original stays NULL). Several attempts allowed; the
latest is shown.

**Who may do what (server‑side on web and mobile)**

| Role | Read | Decide / record |
|---|---|---|
| teacher | only follow‑ups inside an active `teacher_assignments` row (class + subject, or class‑wide) | same scope |
| edu_dept | all | yes |
| hr_dept, info_dept, school_admin, super_admin | all (read‑only) | 403 |
| mezmur_dept, others | 403 | 403 |

**Audit** — `Assessment Follow-ups Opened`, `Assessment Follow-up Decided`,
`Assessment Make-up Recorded` (SecurityAuditService, trusted actor on web/API).

**Ethiopian calendar** — every date in payloads is an EC label (`due_date_ec`,
`inactive_from_ec/to_ec`, `decided_at_ec`, `created_at_ec`, `recorded_at_ec`); the live test
asserts no `YYYY-MM-DD` string in the list payload.

## 3. Owner actions

1. `git pull` on production.
2. Run **`sql/075_assessment_followups.sql`** in phpMyAdmin (after 071–074). Until then every
   endpoint answers `available:false` and the hook is a no‑op.
3. Nothing else: the flag `FEATURE_ATTENDANCE_V2` already gates it.

## 4. Decisions needed / flagged (not decided by me)

| # | Item | What was implemented (reversible) | Question |
|---|---|---|---|
| **G‑1** | **Report‑card precedence** (plan §Phase 5 said "confirm in Gate 5") | Latest make‑up **supersedes** the displayed score on report cards; original kept and exposed as `original_score`; `is_makeup` marker. Marklists / grade‑entry screens are **unchanged** (they still show the original slot). | Keep "make‑up supersedes"? Or show both / mark the card? |
| G‑2 | Assessments with no `due_date` and no term dates | Skipped (cannot be placed in time). | Acceptable, or should every undated assessment of the class be offered to the teacher? |
| G‑3 | Who decides when several teachers share class+subject | Any assigned teacher (or Education) may decide; the last decision wins, all audited. | OK? |
| G‑4 | Flutter queue screen | **Not yet in the app.** The `/api/v1/grades/followups` endpoints are live and tested; the teacher web dashboard carries the queue. | Build the mobile screen in the next APK (needs the Flutter SDK restored in the sandbox), or web‑only for now? |
| G‑5 | Bell to HR / Info | Not sent (teachers + Education only, per plan). | Confirm. |

## 5. Verification log

* `php -l` all touched PHP; `node --check` JS.
* `test_attendance_phase5.py` standalone: 15 passed (static + live).
* Phase 1–5 files together: green (long runs may hit the 20‑logins/5‑min limit → Phase 5
  live tests skip with an explicit message instead of failing).
* Full `tests/security`: 2266 passed, 0 failed, 454 skipped.
* Manual edge run: unassigned teacher → 403 message; HR → 403; attempt links existing NULL‑score
  original (`original_record_id` = original id) and leaves it NULL; report card shows 17.5/20
  with `is_makeup:1`; decision locked after an attempt.
* Dashboards render 200 (teacher / Education / HR) with no PHP warnings; the Education card shows
  pending count, the teacher card only when the feature is available.

## 6. Not done / out of scope

* Flutter screen (G‑4). * Marklist display of make‑ups (G‑1). * Phase 6 (lesson‑level
  attendance) — separate approval.
