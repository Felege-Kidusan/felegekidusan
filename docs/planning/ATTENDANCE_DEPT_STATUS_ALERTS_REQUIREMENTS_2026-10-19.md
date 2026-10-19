# SSMS Attendance, Department Status, Alerts & Assessment Follow‑Up — Consolidated Owner‑Approved Requirements

**Recorded:** 2026‑10‑19 · **Source:** owner message of 2026‑10‑19 (recorded verbatim below, §1–§14) ·
**Status:** Requirements and planning decisions **approved**; **implementation not yet authorized**.
**Companion documents:** `ATTENDANCE_ALERTING_DEEP_RESEARCH_2026-10-19.md` (current behaviour),
`ATTENDANCE_ALERTING_REQUIREMENTS_GAP_AND_OSS_RESEARCH_2026-10-19.md` (gap analysis, Q1–Q12, open‑source research),
`ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN_2026-10-19.md` (phase plan, awaiting approval).

This file is the **locked baseline**. Wording is the owner's; it is not reworded or expanded. Changes only via the
change log in §15 on owner instruction. Requirement IDs (R‑x.y) are added in the margin index (§0) for traceability only.

---

## 0. Requirement index (traceability only — the text in §1–§14 governs)

| ID | Section | Topic |
|---|---|---|
| R‑1.1…R‑1.8 | §1 | General rules (independence, no auto change of `members.status`, history, server‑side auth, **Ethiopian calendar only**, no code before approval) |
| R‑2 | §2 | `member_department_status` + history; HR/Info only may change; School Admin configures; Super Admin none |
| R‑3 | §3 | Streak per member per **Education day** across required sessions; drafts never count; holidays never; lesson‑level detail mandatory; Mezmur by section, independent |
| R‑4 | §4 | Formula: Present 1/0, Excused 1/0, Late 0.5/0.5, Absent 0/1; `rate = present_eq / sessions × 100`; No Data when no sessions |
| R‑5 | §5 | Evaluate after web save, mobile submission, Education edits; submitted = approved by default; reopen/reject with reason; audit; recalc |
| R‑6 | §6 | Defaults 5 / 8 / 10 per dept, configurable; bands 90/75/50 retained; alert threshold + min sessions configurable per dept; auto‑deactivation off by default |
| R‑7 | §7 | Replace AT‑RISK card with **Open Attendance Warnings** (Edu / Mezmur) |
| R‑8 | §8 | In‑app bell only; recipients; closing rights; outcomes list; history retained |
| R‑9 | §9 | Monthly low‑attendance reports, Ethiopian months, separate per dept, reporting interface |
| R‑10 | §10 | Rollout back‑fill for current Ethiopian academic year, system‑generated flag, no duplicates |
| R‑11 | §11 | Inactivation excludes from operational workflows server‑side; reactivation = new active period, streak reset |
| R‑12 | §12 | Teacher‑reviewed assessment make‑up queue on Education reactivation |
| R‑13 | §13 | Auditability and data integrity |
| R‑14 | §14 | Instructions to the developer AI (inspect → plan → wait for approval) |

---

## 1. General Rules

1. Education and Mezmur attendance must operate independently.
2. Department-specific attendance, absence streaks, warnings, risk classification, monthly reports, and active/inactive status must remain separate.
3. A member may be active in Education but inactive in Mezmur, or vice versa.
4. Attendance automation must never automatically change the church-wide `members.status`.
5. Preserve historical attendance, grades, assessments, warnings, and audit records.
6. All authorization must be enforced server-side in APIs and forms, not only by hiding interface controls.
7. **Ethiopian calendar only:** This is a mandatory system-wide requirement. All user-facing dates, reports, exports, filters, dashboards, notifications, date pickers, and audit displays must use the Ethiopian calendar. Gregorian dates must never leak into the user interface.
8. Do not introduce code, schema, or production changes until the owner explicitly approves the implementation plan.

## 2. Separate Department Status

Implement a department-specific status concept, such as `member_department_status`, with an auditable history.

Suggested fields include:
- Member ID
- Department: Education or Mezmur
- Status: Active or Inactive
- Changed by
- Reason
- Effective date/time

Retain the existing church-wide `members.status` for church-wide membership. Attendance automation must not modify it.

### Who can change status?

- **HR and Information:** The only roles permitted to manually deactivate or reactivate members in either department.
- **Education and Mezmur:** May monitor attendance, follow up, and manage their own warnings, but cannot manually change department status.
- **School Admin:** Configures attendance thresholds and automatic-deactivation rules but cannot manually change status through the attendance workflow.
- **Super Admin:** Technical oversight only; no manual status changes through the attendance workflow.

Apply these restrictions in every relevant API and interface, including general member-edit forms and mobile member-update endpoints. Prevent unauthorized status changes even when requests are sent directly to the server.

Maintain a history of status changes, including the responsible user, reason, and effective date.

## 3. Attendance Tracking and Absence Streaks

### Education

Education attendance is tracked by class and lesson/session.

The official consecutive-absence streak is calculated per member, per Education day, across all required Education sessions for that day:

- Count one absent day only when **all required sessions for that day** are marked absent.
- If at least one required session is Present, Late, or Excused, that day does not count as a consecutive-absence day.
- Draft, incomplete, or unsubmitted attendance must never automatically count as absent.
- Holidays must not create absence streaks.

**Lesson-level detail remains mandatory.** If a student attends two lessons but misses two other lessons on the same day, preserve every individual lesson outcome. These records are important for teacher decisions about quizzes and assessments and for HR/Information follow-up.

Education, HR, and Information must be able to access the relevant lesson-level history, including subject, lesson, teacher, date, and attendance outcome, according to their permissions.

### Mezmur

Mezmur attendance is tracked by section and calculated independently from Education attendance.

Education attendance must never alter Mezmur attendance status, streaks, or warnings, and vice versa.

## 4. Attendance Outcomes and Calculation Formula

Use the same attendance calculation formula for Education and Mezmur.

| Attendance outcome | Present equivalent | Absent equivalent | Breaks consecutive-absence streak? |
|---|---:|---:|---|
| Present | 1 | 0 | Yes |
| Excused | 1 | 0 | Yes |
| Late | 0.5 | 0.5 | Yes |
| Absent | 0 | 1 | No |

For a selected reporting period:

- `sessions` = eligible, marked attendance sessions in the period, excluding holiday rows.
- `present_eq` = Present + Excused + (0.5 × Late).
- `absent_eq` = Absent + (0.5 × Late).
- `attendance_rate` = `(present_eq / sessions) × 100`.

If no eligible sessions exist, display **No Data**. Never classify a member as At Risk when there is no attendance data.

Keep the official calculation consistent across dashboards, reports, warnings, and other attendance features.

## 5. Attendance Submission and Recalculation

Evaluate attendance after:
- Web attendance saves.
- Mobile attendance submissions.
- Education attendance edits.

**Submitted attendance is approved by default.** Do not introduce mandatory approval of every attendance packet.

When a problem is identified, authorized staff may reopen or reject a submission. Reopening and rejection must require a specific reason.

Audit:
- Who submitted the attendance.
- Who reopened or rejected it.
- When each action occurred.
- The reason for reopening or rejection.

After relevant changes, recalculate attendance totals, streaks, risk classifications, and open warnings.

Reopened, rejected, incomplete, or invalid records must not create false absence warnings. Drafts and unsubmitted attendance must not trigger official warnings or status changes.

## 6. Warning Thresholds and Risk Classification

### Consecutive-absence warnings

Use these initial defaults, independently configurable for Education and Mezmur by School Admin:

| Stage | Default |
|---|---:|
| Standard consecutive-absence warning | 5 absences |
| Prolonged-absence alert | 8 absences |
| Critical consecutive-absence threshold | 10 absences |

School Admin must be able to configure these thresholds separately for each department. Do not hardcode the thresholds.

### Low-attendance classification

Retain the existing SSMS Reporting Centre classification bands:

| Classification | Attendance rate |
|---|---:|
| Excellent | 90% or higher |
| Good | 75% to below 90% |
| Fair | 50% to below 75% |
| Low | Below 50% |

School Admin configures the low-attendance alert threshold and the minimum number of marked sessions required before an alert is eligible. Configure these independently for Education and Mezmur.

Changing the alert threshold must not silently change the classification bands.

### Automatic department deactivation

- Automatic deactivation is **disabled by default**.
- School Admin may configure whether it is enabled and define its applicable rules separately for Education and Mezmur.
- If enabled, automation may change only the affected department's status according to the configured rules.
- Automation must never change church-wide membership status.
- Audit automatic status changes and notify the responsible department, HR, and Information.
- School Admin reviews these events through reports; Super Admin receives no attendance notification.

## 7. Open Attendance Warnings Dashboard

Replace the general **AT-RISK** dashboard card with **Open Attendance Warnings**.

Separate the dashboard information into:
- Education warnings.
- Mezmur warnings.

Display useful information such as the member, affected department, absence streak or low-attendance reason, warning stage, and current closure status.

Keep the warning system separate from monthly low-attendance reports. Consecutive-absence warnings and monthly attendance-rate warnings serve different purposes and both are required.

## 8. Warning Recipients and Permissions

### Notification channel

Use the **in-app notification bell only**.

Do not implement email or Telegram attendance summaries. Monthly reports remain accessible in the reporting interface; this workflow does not require a cron job for scheduled notifications.

### Recipients

**Education warning**
- Education receives detailed Education attendance information.
- HR and Information receive the relevant cross-department details needed for follow-up.

**Mezmur warning**
- Mezmur receives detailed Mezmur attendance information.
- HR and Information receive the relevant cross-department details needed for follow-up.

HR and Information must be able to identify whether a member has an Education issue, a Mezmur issue, or issues in both departments. Relevant dates and attendance history must be available for their follow-up responsibilities.

Education and Mezmur are responsible for following up on attendance problems within their departments. HR and Information have an additional responsibility because they are the roles authorized to access general member contact information.

### School Admin and Super Admin

- **School Admin:** Views warnings, outcomes, escalation history, and reports through the reporting interface; configures thresholds and automatic-deactivation settings. No routine attendance-alert notifications and no routine warning-closure responsibility.
- **Super Admin:** Technical support and oversight only. No attendance alerts and no warning-management responsibility.

### Closing warnings

- Education may review and close Education warnings.
- Mezmur may review and close Mezmur warnings.
- HR and Information may review and close warnings across both departments.
- Teachers and attendance takers may view relevant attendance and warning details in read-only mode, but cannot close warnings.
- School Admin may review warning outcomes and history through reports, but cannot manage routine closures.
- Super Admin has no warning-management role.

A warning closure must retain the warning and its history. Provide an outcome such as:
- Contacted
- Excused
- Escalated
- Deactivated
- No Action

A closure note may be optional. Record who closed the warning and when. Do not delete warning history when a warning is closed.

## 9. Monthly Attendance Reports

Provide monthly low-attendance reports for both Education and Mezmur.

Requirements:
1. Use Ethiopian calendar months and Ethiopian-calendar-only date filters.
2. Keep Education and Mezmur reporting separate.
3. Apply the approved attendance calculation formula.
4. Respect each department's configured low-attendance threshold and minimum-session requirement.
5. Show No Data when no eligible sessions exist.
6. Preserve historical results and support authorized historical review.
7. Make reports available through the reporting interface.
8. Keep monthly low-attendance reporting separate from consecutive-absence warnings.
9. Ensure all exported reports and displayed dates follow the Ethiopian-calendar-only requirement.

## 10. Existing Attendance Records at Deployment

During initial rollout:

1. Evaluate existing attendance records from the **current Ethiopian academic year**.
2. Apply the configured department-specific thresholds and the approved calculation rules.
3. Generate warnings for members who already meet the applicable thresholds.
4. Mark warnings created during initial rollout as system-generated.
5. Avoid creating duplicate warnings for the same ongoing absence streak.
6. Preserve existing attendance and history.
7. Do not treat incomplete or unsubmitted attendance as absences.
8. Use the Ethiopian calendar for academic-year selection, date interpretation, and all user-facing displays.

Do not modify production data until the deployment and implementation plan has been explicitly approved.

## 11. Department Inactivation and Reactivation

### When a member becomes inactive

An inactive member must be excluded from that department's operational workflows. Hiding the member only in the interface is insufficient.

For an inactive Education member, exclude them from relevant active workflows such as:
- Attendance sheets.
- Active class and lesson participation.
- Grades, quizzes, and assessments requiring active participation.
- Active member lists and dashboards.
- Other operational features that depend on active Education membership.

Apply the equivalent department-specific rules to Mezmur.

Enforce exclusions server-side across relevant APIs and operations. Preserve existing attendance, grades, assessments, and other historical records for authorized historical review.

Inactive members must be excluded from active attendance-rate denominators. Their inactivity must not create new absences.

A member inactive in Education may remain active in Mezmur and continue participating in Mezmur workflows.

### When a member is reactivated

Only HR and Information may manually reactivate the member's department status.

On reactivation:
1. Record a new active period and its effective date.
2. Preserve the previous active period and status history.
3. Reset the consecutive-absence streak for that department.
4. Count only eligible sessions on or after the new active date.
5. Do not count sessions during the inactive period as absences.
6. Preserve historical attendance, grades, assessments, and warning records.
7. Ensure old absences do not automatically become part of the new consecutive-absence streak.

Current-period attendance calculations must respect the member's active period. Historical reporting must preserve the previous periods and their records.

## 12. Assessments Missed During Inactivity

Use a **teacher-reviewed make-up queue** when a member is reactivated in Education.

### Required workflow

1. When Education membership is reactivated, identify potentially missed assessments from the inactive period.
2. Present those assessments in a follow-up queue for the responsible teacher to review.
3. For each assessment, the teacher selects one of:
   - **Make-up Required**
   - **Exempt**
   - **Not Applicable**
4. When a make-up is required, allow the teacher to record the new attempt separately and link it to the original assessment.
5. Preserve the original assessment and its results. Never silently overwrite the original attempt.
6. Do not automatically assign a zero simply because the member was inactive when the assessment occurred.
7. Record relevant follow-up decisions and assessment history.
8. Allow authorized teachers to access relevant historical assessments and the make-up workflow, even though the member is excluded from ordinary active class lists while inactive.
9. Enforce access and modification permissions server-side.
10. Ensure the member's return does not improperly erase historical academic records or automatically treat every missed assessment as requiring a make-up.

This workflow gives teachers discretion to determine whether an assessment should be repeated, excused, or considered not applicable.

## 13. Required Auditability and Data Integrity

Maintain reliable records of:
- Department status changes and effective dates.
- Manual versus automatic deactivation/reactivation.
- Attendance submissions, reopening, rejection, and reasons.
- Warning creation, including initial-rollout system-generated warnings.
- Warning closure outcomes, responsible users, and timestamps.
- Assessment follow-up decisions and make-up attempts.

Preserve historical records instead of silently overwriting or deleting them. Prevent duplicate warnings for the same ongoing streak and ensure recalculations are consistent when attendance changes.

All operations must respect role permissions and the separation between Education and Mezmur.

## 14. Implementation Instructions to the Developer AI

Before implementation:

1. Inspect the existing SSMS attendance, reporting, membership-status, authorization, notification, and assessment workflows.
2. Identify the existing tables, APIs, roles, and UI components that can be reused.
3. Produce an implementation plan showing proposed schema changes, API changes, permission checks, UI changes, recalculation logic, migration/backfill strategy, tests, and deployment risks.
4. Identify any conflicts between these requirements and existing behavior.
5. Identify unresolved technical questions rather than making business-policy decisions without approval.
6. Verify Ethiopian-calendar handling throughout all affected user-facing workflows.
7. Provide tests for department separation, permissions, absence calculations, incomplete attendance, warnings, reactivation, assessment make-ups, historical preservation, and reporting.
8. Present the plan for owner review and wait for explicit approval.

---

## 15. Change log

| Date | Change | Authorised by |
|---|---|---|
| 2026‑10‑19 | Baseline recorded verbatim from owner message; index §0 and this log added. | Owner (message of 2026‑10‑19) |
