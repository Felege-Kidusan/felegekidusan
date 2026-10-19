# Attendance alerting — Phase 4 implementation notes (2026-10-19)

Scope approved by the owner on 2026-10-19 ("continue to the next phase") per
`ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN_2026-10-19.md` §Phase 4:
**`attendance_month_rollup` (Ethiopian months) + a shared "Monthly attendance"
report in the Education, Mezmur, HR and Information dashboards (scoped
server-side; HR + Information see both) + every at-risk consumer unified onto
the shared risk service (Reporting Centre bands to the 0.5/0.5 formula).**

Requirements baseline: `ATTENDANCE_DEPT_STATUS_ALERTS_REQUIREMENTS_2026-10-19.md`
§1–§14 (unchanged). Owner T-answers applied: T-5 monthly reports in all four
dashboards (HR must have them; Reporting Centre stays Information-only), T-6
defaults 75 % / 3 sessions, Ethiopian calendar only.

---

## 1. What was built

| # | File | Role |
|---|------|------|
| 1 | `sql/074_attendance_month_rollup.sql` | **Owner runs in phpMyAdmin.** One derived table `attendance_month_rollup` (PK `department, member_id, ec_year, ec_month`; `sessions, present, absent, late, excused, present_eq, absent_eq, rate, band, below_threshold, threshold_value, computed_at`). Idempotent, InnoDB, no triggers/events. |
| 2 | `admin/backend/services/AttendanceMonthRollupService.php` | Set-based recompute of the **shared formula** (`AttendanceRiskService` constants only — `LATE_WEIGHT`, `BAND_*`, `legacyRowsEligible()`), identical eligibility rules (packet submitted/approved, legacy rows, holidays excluded, inactive department periods excluded with the exact `started_at <= d < ended_at` semantics). `refreshMonth` (whole month or given members, in a transaction; rows **reset to `no_data`, never deleted**), `refreshForDate` / `safeRefreshForDate` (post-write hook), `ensureFresh` (lazy: 10 min for the current month, 24 h for past months — no cron on shared hosting), `months`, `listMonth` (paged, filters: below / band / group / search; joined with class or canonical Mezmur section, open warnings, department status), `summary`, `groupsForMonth`, `memberMonths`, `dashboardCard`. |
| 3 | `admin/backend/services/AttendanceWarningService.php` | `safeEvaluateMany()` now ends with `AttendanceMonthRollupService::safeRefreshForDate(...)` → every official save / review transition (web + mobile, Education + Mezmur, Phase 3 recalc path) keeps the month in step. |
| 4 | `admin/api_attendance_monthly.php` | GET-only JSON API (`months`, `summary`, `list`, `groups`, `member`, `export`). Role scoping = warnings API (`READ_ROLES`, `departmentsForRole`; edu→Education only, mezmur→Mezmur only, HR/Info/School Admin/Super Admin both, all read-only). EC month validated 1–13 (Pagume = 13). Rate-limited; export audited (`attendance_monthly_export`). Export = openspout XLSX (CSV fallback) with EC title/stamp, **no Gregorian column**. |
| 5 | `admin/attendance_monthly.php` + `admin/js/attendance_monthly.js` | Standalone shared page (same shell/pattern as `attendance_warnings.php`, `reports_v2.css`, no CDN, no SQL). Department + EC month pickers, class/section + band + search filters, "below threshold only", summary cards (members / below / average / bands), paged table with weighted rate bar, per-member month drill-down, links to warnings, Excel export, "Refresh" = forced recompute. All labels arrive pre-formatted in the Ethiopian calendar. |
| 6 | Dashboards | New **"Monthly Attendance"** card (`id="am-dashboard-card"`) → `edu_dept.php` (Education only), `hr-dept.php` + `info-dept.php` (both departments, per-dept below counts), `frontend/pages/mezmur_dept.php` + `frontend/js/mezmur.js` (Mezmur only, filled from `action=summary`; page remains `style=`-free). Server-side `dashboardCard()` shows the current EC month's below-threshold count and average. |
| 7 | **Unification** — Reporting Centre `ReportRegistry.php` | `ea` / `ma` joins now compute `attended = present + excused + LATE_WEIGHT·late` over marked sessions (`SQL_ATTENDED_WEIGHTED_*` constants); band CASEs and `low_attendance` use `BAND_EXCELLENT/GOOD/FAIR` from `AttendanceRiskService`. Definition text updated. Scope note kept in code: RC is whole-academic-year, all rows (no packet/period scoping) — the official monthly figures are the roll-up. |
| 8 | **Unification** — Education analytics | `ReportCardService::attendanceRiskThreshold($conn)` = Education `attendance_policies.low_rate_threshold` **when `attendance_v2` is on**, legacy 60 % otherwise. Used by `filterStudentsPerformance` (KPI `at_risk_att`, triage tiers) and `EducationAnalyticsService::buildTriageRoster($students, $threshold)`; stats expose `attendance_threshold`; `advanced_analytics.js` labels/presets and `edu_dept.php` quick-filter buttons / "Attendance Alert" label read the live value. |
| 9 | **Unification** — Information "At-risk" pane | `api_attendance_info.php?action=at_risk_members` rewritten to read the roll-up (`department`, `ec_year`, `ec_month`; policy threshold + min sessions; returns `report_url`). `info-dept.php` pane: Department + EC-month selects replace the old "last N days / below X %" inputs; table shows class/section, sessions, weighted rate, open warnings, department status; "Full monthly report" link. |
| 10 | `tests/security/test_attendance_phase4.py` | 12 static pins + 8 live guards (formula equality incl. rejected packet / holiday / legacy rows, partial refresh reset, role scoping, 405, Pagume/14, EC-only CSV, Info pane = roll-up, dashboards render). |

Not changed: `members.status` (never automated), warnings engine rules, Phase 1–3 behaviour, Reporting Centre scope (Info-only), HR datasets (frozen), mobile app (no APK needed for Phase 4).

## 2. Verification (local)

* `sql/074` applied twice → idempotent.
* Roll-up vs `AttendanceRiskService::riskFor()` for seeded members (present/late/absent/excused, holiday, rejected packet, inactive period, Mezmur canonical section) → **all MATCH**; partial refresh resets members with no eligible rows to `no_data` (row kept).
* Real web save (`save_attendance`) → roll-up rows written by the hook.
* Live API as edu/mezmur/hr/info/school: scoping 403s, CSV + XLSX export, Pagume accepted, month 14 → 400, dashboards 200 with the new card, Education hub payload shows `attendance_threshold: 75` and triage reasons "Attendance < 75%".
* Suites: `test_attendance_phase4.py` 20/20; full security suite **2257 passed / 0 failed / 448 skipped** (skips = environment-gated + the known 20-logins-per-5-min limit when attendance suites run back-to-back; each attendance file passes standalone).

## 3. Owner actions

1. `git pull` on production, then run **`sql/074_attendance_month_rollup.sql`** in phpMyAdmin (safe to re-run). Until it runs, the new card/report/Info pane show "not available yet" — nothing else breaks.
2. Nothing to do for mobile — Phase 4 is web-only.

## 4. Flags for owner decision (not decided here)

* **F-1 Education analytics threshold 60 % → policy 75 %.** With `attendance_v2` on, the Education hub "Attendance alert" KPI, triage tiers and triage roster now use the Education policy threshold (75 % default) instead of the historical 60 %. The flag-off behaviour is unchanged. If 60 % should stay for analytics, set the Education policy's `low_rate_threshold` accordingly or tell me to split the two.
* **F-2 Report-card attendance formula.** Printed/parent-facing report cards (`ReportCardService` attendance block) still use *present + late* ÷ total — it feeds the analytics `attendance_rate` per student. The threshold is unified (F-1) but this *formula* was left untouched because it changes a parent-facing document. Say the word and I'll switch it to the weighted formula too.
* **F-3 Reporting Centre scoping.** RC attendance columns are whole-academic-year over *all* rows (no packet/period scoping) as before; only the weights/bands were unified. Scoping RC to eligible rows is possible but is a visible data change for existing saved reports.

## 5. Next

Phase 5 (`assessment_followups` + `assessment_makeup_attempts` make-up queue on Education reactivation) — awaiting explicit approval.
