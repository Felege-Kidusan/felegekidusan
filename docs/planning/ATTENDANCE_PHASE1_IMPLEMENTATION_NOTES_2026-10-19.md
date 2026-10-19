# Attendance Alerting — Phase 1 implementation notes (2026-10-19)

Phase 1 of `ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN_2026-10-19.md` (department
status + policy settings). Requirements: §1, §2, §3, §7, §11 of the locked
requirements document. Everything is behind `FEATURE_ATTENDANCE_V2` and
degrades gracefully until `sql/071` is applied.

## 1. Deploy steps (owner)

1. `git pull` on the server.
2. phpMyAdmin → run `sql/071_department_status_and_policy.sql` (idempotent;
   creates `member_department_periods`, `attendance_policies`, seeds the two
   default policy rows 5 / 8 / 10, auto-deactivate OFF, 75 %, 3 sessions).
3. In the out-of-web-root env file add `define('FEATURE_ATTENDANCE_V2', true);`
   (see `env.example.php`). Until then every member is treated as active in
   both departments and the new screens say "not enabled yet".

Rollback: remove the define (code paths become no-ops); tables may stay.

## 2. What ships

| Area | Files |
|---|---|
| Schema | `sql/071_department_status_and_policy.sql` |
| Services | `admin/backend/services/DepartmentStatusService.php` (periods, `isActiveOn`, `activePeriods`, `setStatus`, `exclusionSql`), `admin/backend/services/AttendancePolicyService.php` (defaults, validate, save, audit) |
| Web APIs | `admin/api_department_status.php` (status / history / summary; `set` = HR + Info only), `admin/api_attendance_policy.php` (`get` for departments + admins; `save` = School Admin only) — both registered in `access_control.php` and `FeatureGate` |
| Mobile | `GET/POST /api/v1/members/{id}/department-status` (read: PII roles + HR/Edu/Mezmur; write: HR/Info) — backward compatible, no APK needed until Phase 2 |
| Roster exclusion (§11) | `EnrollmentService::fetchRoster` (`on_date`, `include_dept_inactive` options) → used by web sheet (`api_attendance.php` get/save), Education batch save + `get_class_students` (`?date=`), mobile `GET/POST/PUT /attendance`, grades roster, class roster; `MezmurAttendanceService` `rosterGroupedBySection` / `rosterIds` / `sectionRoster` / `sectionListWithCounts` (sheet date) |
| UI | Member panel (HR + Info dashboards): "Department Status" card with Education / Mezmur badges, Deactivate / Reactivate (modal, reason required), history with EC dates — `admin/js/department_status.js`; School Admin → Attendance: "Attendance policy (per department)" card |
| Tests | `tests/security/test_attendance_phase1.py` (8 static + 6 live), `tests/e2e/department_status_unit.php` (38 period/policy checks) |

## 3. Semantics (FACT, as implemented)

- No row in `member_department_periods` ⇒ active. A change closes the open
  period (`ended_at = NOW()`) and inserts a new one; reactivation therefore
  always starts a new period (streak reset by construction, §11).
- Inactive on day D ⇔ an inactive period has `started_at < D+1` and
  (`ended_at IS NULL` or `ended_at ≥ D+1`). Same-day reactivation ⇒ active
  that day. Deactivation day ⇒ inactive that day.
- Sheets for a past date use the status **as of that date**; today's sheet
  excludes members deactivated today. A stale browser sheet that still lists
  a now-inactive student is rejected with the existing "refresh the class"
  roster-mismatch error — no rows are ever written for excluded members.
- `members.status` is never touched by any of this (archived members cannot
  change department status).
- Report cards (`ReportCardService`) deliberately keep department-inactive
  students (historical document).
- Policy bounds: 1 ≤ standard < prolonged < critical ≤ 60; 0 < low-rate ≤ 100;
  1 ≤ min sessions ≤ 30. Audit event `Attendance Policy Updated` with old/new.
- Audit event `Department Status Changed` (member_id, department, from, to,
  reason, source, period_id) on web and mobile.

## 4. Roster call-site inventory (step 1 of the phase)

| Call site | Covered by | Note |
|---|---|---|
| `admin/api_attendance.php` get_class_attendance / save_attendance | `fetchRoster(..., ['on_date' => $date])` | sheet date |
| `admin/api_education.php` batch_attendance / get_class_students | `fetchRoster(..., ['on_date' => …])` | `get_class_students` accepts `?date=` |
| `api/v1/routes/attendance.php` GET sheet, POST draft, PUT submit | `apiValidateAttendanceSheet(..., $date)` + `fetchRoster(..., ['on_date' => $date])` | mobile |
| `api/v1/routes/grades.php` marklist roster, `api/v1/routes/classes.php` students, `admin/api_subjects.php` marklist | `fetchRoster` default (today) | grades/denominators |
| `admin/backend/services/ReportCardService.php` | `include_dept_inactive => true` | history kept |
| Mezmur `fetchSheet` / `saveSheet` / `fetchSectionSheet` / `saveSectionSheet` / `sectionListWithCounts` | `exclusionSql('mezmur', …, $date)` | sheet date |
| Legacy fallbacks when `EnrollmentService` class is missing (`attendance.php:146/155`, `grades.php:888`) | not changed | dead on production (class exists) |
| `EnrollmentService::countActive`, `rosterCountJoinSql`, class-count badges, `AcademicTrackingService` denominators, Mezmur analytics `m.status='active'` lists | not changed | counts/analytics — unified in Phase 4 (`AttendanceRiskService` + month rollup) |

## 5. Residuals / questions for the owner

1. **Education / Mezmur dashboards read-only badge** — those dashboards have no
   member-profile view today; the badge will appear in the Phase 2 warnings
   list/card (member rows carry both department statuses). Confirm this is
   acceptable instead of adding a new profile screen now.
2. **Reactivation reason** — currently required (same rule as deactivation),
   per the plan's recommendation. Say so if HR prefers it optional.
3. **Class-count badges / analytics denominators** still count department-
   inactive members until Phase 4 unifies all consumers on the service.
