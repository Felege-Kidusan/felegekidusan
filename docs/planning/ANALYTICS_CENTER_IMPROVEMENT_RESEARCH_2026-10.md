# Member Analytics, Reporting & Export Centre — Improvement Research

**Scope:** `admin/reports.php` ("Open Advanced Analytics Center") and the reporting / export surfaces around it, as used by the **Information** and **HR** departments.
**Date:** 2026‑10‑18 · **Status:** research + proposal only (no implementation without approval).
**Related patch already shipped:** `c1fceff` — Info + HR can open the page; `api_reports.php` governed (role re‑check, GET‑only, rate limit, audit); Data‑Explorer chart bug fixed; permission bounces are now explained.

Every statement is tagged **FACT** (verified in the repository), **INFERENCE** (reasoned from facts), **INDUSTRY** (external source, cited), or **PROPOSED**.

---

## 1. Why this matters

Both departments are *information* departments:

* **Information dept** — viewer / data‑updater of the member directory, owner of data quality, answers "how many / who / where" questions for the school.
* **HR dept** — registration, promotion, Family Link, ID cards, group leadership; answers "who is eligible / who is missing what" questions.

Today each of them must **ask another department** (or an admin) for anything outside the member table, and even inside it they have no way to save or share a query. The goal of this document is a reporting centre where these two departments can answer their own questions, with the same numbers every time, without widening write access.

---

## 2. Current state (FACT)

### 2.1 Reporting surfaces that exist today — five, not one

| # | Surface | Who | What | Code |
|---|---|---|---|---|
| 1 | **Member Analytics & Export Centre** (`reports.php`) | admins, Info, HR (since `c1fceff`) | 13 member‑demographic charts, 13‑filter Data Explorer (≤2 000 rows, 500 shown), filtered CSV/PDF/Word, preset exports | `admin/reports.php`, `admin/api_reports.php` |
| 2 | **Attendance Analytics Hub** (shared partial) | admins, Info, HR | Education + Mezmur attendance KPIs, comparison, drill‑down, Excel, PDF reports | `admin/dashboards/sections/attendance_analytics_hub.php`, `api_info_analytics.php`, `api_info_reports.php` |
| 3 | **HR Attendance Reports → Member view** | HR | one member's Education + Mezmur attendance for a month | `api_hr_reports.php` |
| 4 | **school_admin "Reports & Analytics" section** | school_admin | quick roster export, browser‑built "custom report", membership analysis (needs members loaded first) | `admin/dashboards/school_admin.php:472` |
| 5 | **Education exports** | edu_dept, teacher | class report, executive report (Excel/PDF), filtered students | `export_class_report.php`, `export_executive_report_*.php`, `export_filtered_students.php` |

Plus the fixed **"Exports & Reports"** panels on the Info and HR dashboards (preset `export_pdf.php` links).

**INFERENCE:** the fragmentation is the first "information lack": the same question ("active members in section B with no phone") is answered differently (or not at all) depending on which surface the user opens, and nothing is reusable between users.

### 2.2 What `reports.php` can and cannot answer

| Can (FACT) | Cannot (FACT) |
|---|---|
| Counts by gender, age group (A/B/C), status, registration type, member type, ID‑card status, education level, profession, city, sub‑city | Anything outside `members`: class enrolment, grades, teacher, attendance rate, Mezmur participation, Family Link / guardians, groups, ID‑card history, documents uploaded, app installation |
| Registration trend 12 months, daily registrations 30 days, age bands from `dob_ec_year` | Membership tier (temporary / permanent) and promotion eligibility (`membership_tier` — present, indexed in `sql/014`/`sql/068` — `promoted_at`, `waiting_since` are not exposed) |
| Filtered list ≤ 2 000 rows with fixed 9 columns; CSV/PDF/Word of the filtered set (≤ 5 000 rows) | Choosing columns; saving a filter; sharing a filter; scheduling; Excel (`.xlsx`) of a filtered set (only CSV; `.xlsx` exists only for the full roster via `api_export_members.php`) |
| Gregorian date range on `created_at` | Ethiopian‑calendar periods; "registered this academic year"; comparing two periods |
| Fixed KPI tiles | Definitions — nothing explains what "Total Members", "Teachers" or "With ID" means (e.g. "Teachers" = `members.is_teacher`, a legacy flag superseded by `member_staff_positions` introduced in `sql/017_identity_codes.sql`) |

### 2.3 Data that exists in the database but is invisible to Info/HR reporting (FACT: tables from `database_schema.sql` + `sql/0xx`; access from `admin/access_control.php` ROLE_MAP)

| Domain | Tables | Info can read today? | HR can read today? | Via |
|---|---|---|---|---|
| Member directory | `members`, `member_staff_positions`, `staff_positions`, `member_type_settings` | ✅ | ✅ | members.php, reports.php |
| Education structure & enrolment | `classes`, `class_enrollments`, `class_subjects`, `teacher_assignments`, `academic_years`, `academic_terms` | ❌ (only via attendance_info) | partial (`api_education.php`) | — |
| Grades | `academic_records`, `grade_submissions`, `assessment_types` | ❌ | ❌ | — |
| Attendance (Education / Mezmur) | `attendance`, `mezmur_attendance`, `attendance_rollup` | ✅ aggregated | ✅ aggregated + per‑member | Analytics Hub, api_hr_reports |
| Family Link | `guardians`, `member_guardians`, `guardian_aliases`, `guardian_link_suggestions` | ❌ | ✅ (queue UI only, no reports) | api_guardians.php |
| Groups | `wbws_groups`, `wbws_group_members`, `wbws_group_leaders` | ❌ | ✅ (management UI, no reports) | groups_api.php |
| ID cards | `members.id_card_status`, `id_card_generated_at` | ✅ (status only) | ✅ | reports.php, id_cards/ |
| Mezmur participation | `mezmur_sessions`, `mezmur_days`, `mezmur_play_stats`, `mezmur_user_favorites` | ❌ | ❌ | — |
| Mobile app adoption | `app_installations`, `app_downloads`, `app_telemetry_events` | ❌ | ❌ | — |
| Activity / audit | `activity_logs`, `wbws_audit_log` | ❌ | ❌ | super_admin only |

### 2.4 Governance that already exists and should be reused (FACT)

* `SecurityAuditService::record()` — used by every export and (since `c1fceff`) by the explorer.
* `SecurityRateLimiter` — per‑user, DB‑backed with file fallback (shared‑hosting safe).
* `MemberReportService` (bounded, streamed, whitelisted filters, `MAX_ROWS = 5000`) and `MemberExportService` + `ExcelColumnMap` (tiered column sets for `.xlsx`).
* `FeatureGate` (`FEATURE_REPORTS`, `FEATURE_EXPORT_PDF`).
* `MemberCategory` — single source of truth for section/age‑group labels.
* `InfoAnalyticsService` — a pre‑aggregated read model (`attendance_rollup`) refreshed lazily (180 s) — the pattern to copy for member analytics at scale.

---

## 3. Information‑need inventory (what Info and HR actually get asked)

**INFERENCE** from the department responsibilities recorded in the project rules and dashboards; to be confirmed with both departments (see §8 Q1).

| # | Question | Who asks | Answerable today? | Blocker |
|---|---|---|---|---|
| N1 | How many active members per section / gender / sub‑city? | everyone | ✅ reports.php | — |
| N2 | Which members are **eligible for promotion** (temporary ≥ 6 months) and what is missing for each? | HR, school_admin | partly (HR "new members" tab) | not in analytics / export; no "missing document" view |
| N3 | Which members have **incomplete records** (no phone, no photo, no guardian, no documents, no ID card)? | Info (data‑updater), HR | partly (ID / phone filters) | no photo / document / guardian completeness dimensions |
| N4 | Which **children have no linked guardian** / which guardians have several children? | HR, Info | HR queue only | no report/export of Family Link coverage |
| N5 | Who is in **class X** this year? who is **not enrolled** in any class although age group A/B? | Info, HR, Edu | ❌ for Info; HR via edu API UI only | enrolment dimension not in reports |
| N6 | Members with **attendance below 60 %** this term (Education or Mezmur) — list, not just a chart | HR, Info | ❌ (hub is aggregate; HR member view is one member at a time) | no member‑level attendance band dimension |
| N7 | Registrations per month by **Ethiopian** month / academic year; this year vs last year | Info, admin | ❌ (Gregorian `created_at` only) | calendar + period comparison |
| N8 | **Teachers / staff** list with positions, department heads | Info, HR | ❌ (`is_teacher` KPI only) | `member_staff_positions` not exposed |
| N9 | Group membership / leaders per group | HR | management UI only | no export |
| N10 | **Duplicate** or suspicious records (same name + phone) | Info | ❌ in reports (only HR's `api_check_duplicate.php` at registration time) | no data‑quality report |
| N11 | Who has the **mobile app** / Family Link activation (future) | Info, HR | ❌ | app tables not exposed |
| N12 | "Send me the list you showed last month, updated" | admin → Info | ❌ | no saved / shared / scheduled report |
| N13 | Who exported what, when (for the school administration) | school_admin | partially (`wbws_audit_log`, super_admin UI) | no export‑history view for school_admin |

**Conclusion (INFERENCE):** N2, N3, N4, N5, N6, N12 are the questions that currently force Info/HR to ask Education, HR, or an admin. They are all answerable from data that already exists, read‑only.

---

## 4. How the industry handles this (INDUSTRY, cited)

### 4.1 PowerSchool SIS (the closest reference)

* **Reports are split in three layers:** *preconfigured system reports* (attendance, membership, statistics, student listings), *custom report templates* (report cards, labels, form letters, object reports) that are saved and reused, and *ReportWorks / Advanced Reports* for developers to publish parameterised reports to end users. Users pick a **student selection first**, then run any report against that group. [1][2][3]
* **Data Export Manager (DEM):** choose a table or a curated *data set* (pre‑defined SQL "PowerQuery"), pick fields, filter, export to text; **templates are saved** ("My Templates") and can be **scheduled**; scheduling is a separate role permission; results are constrained to the user's school scope and **field‑level security** (fields the user may not see are greyed out). [4][5][6]
* **Field‑Level Security** is complementary to page‑level security — Full / View‑only / No access per field, never more than the page allows. [7]
* **SIS Views:** pre‑defined, documented SQL views exposing *metrics*, not raw tables, so everyone joins on the same definitions. [8]
* **Report queue** processes heavy reports asynchronously; Advanced Reports exports PDF/HTML/Excel from templates. [3]

### 4.2 Infinite Campus

* **Ad Hoc Reporting → Filter Designer:** a *Query Wizard* builds a saved filter (people + fields + conditions); **Data Export** runs any saved filter to HTML, CSV, PDF, XML, fixed‑width; **Data Viewer** runs canned reports against a saved filter. [9][10]
* Filters are organised in **folders**, **shared with user groups**; anyone in the group may edit a shared filter, so the guidance is "keep a personal original, share a copy". [10][11]

### 4.3 Church / membership management systems (same domain as a Sunday‑school membership directory)

* **Planning Center People — "Lists":** rule‑based saved lists (any/all/none conditions, lists of lists), **auto‑refresh** nightly/weekly/monthly, **column picker** on results, CSV export of either all data or just the shown columns, **collaborators** per list, and since 2025 a dedicated *"Can export CSVs and reports"* permission because previously every user could export. Private custom fields are only exported by people who can see them. [12][13][14][15]
* **Realm (ACS):** dashboards where clicking a graph point opens the underlying **people list**, from which the selected records export to CSV; predefined reports can be edited, saved and shared. [16][17]
* **Breeze:** table reports with saved filter configurations ("Save Report"), visible to all roles that hold the report permission. [18]

### 4.4 Self‑service analytics governance (cross‑industry)

* A **semantic layer / certified metric definitions** ("active member means X everywhere") is the accepted cure for "spreadmarts" — every team exporting to Excel and producing its own version of the truth. [19][20]
* **Certified datasets + catalog** with owners replace raw‑table access; **role‑ and row‑level security** are tied to roles, not per‑report settings. [19]
* Governance only holds when **enforced by tooling**, not documentation: route every question to the governed definitions first, raw queries only as a documented fallback. [21]

### 4.5 Education data‑privacy practice

* Role matrices must answer three questions per role: what it may **see**, what it may **do**, and what it **logs**; *export* is a separate permission from *view*; minimum audit = logins, permission changes, **data exports (what, by whom, when)**, overrides, anomalous access. [22]
* FERPA's "legitimate educational interest" is implemented as RBAC with least privilege and append‑only access logs covering downloads and exports, not only database reads. [23][24]

### 4.6 Patterns extracted (what all of them share)

| Pattern | PowerSchool | Infinite Campus | Planning Center | SSMS today |
|---|---|---|---|---|
| Select a **population** first, then run reports/exports against it | ✅ student selection | ✅ saved filter | ✅ list | ❌ (filters live only inside one tab) |
| **Saved, named, shareable** queries | ✅ templates | ✅ filters + folders + groups | ✅ lists + collaborators | ❌ |
| **Column picker** for list and export | ✅ DEM | ✅ | ✅ | ❌ fixed columns |
| **Curated data sets / views** rather than raw tables | ✅ PowerQueries, SIS Views | ✅ data types | ✅ list rules | partial (`attendance_rollup`) |
| **Field‑level / export‑level permission** separate from page access | ✅ FLS + export role | ✅ user groups | ✅ export permission | partial (page‑level only; export roles = page roles) |
| **Scheduled / auto‑refreshed** outputs | ✅ scheduled templates | — | ✅ auto‑refresh lists | ❌ |
| **Drill from chart to list to export** | ✅ | ✅ (Data Viewer) | ✅ (Realm) | ❌ (charts and explorer are disconnected) |
| **Metric definitions visible** to users | SIS Views docs | — | rule counts per condition | ❌ |
| **Audit of exports** | ✅ | ✅ | ✅ | ✅ (export_pdf, api_export_members, explorer since c1fceff) |
| **Async queue** for heavy reports | ✅ report queue | — | e‑mailed CSV | ❌ (bounded 5 000 rows instead) |

---

## 5. Recommendations — what to add, change, remove (PROPOSED)

All recommendations respect the standing constraints: **shared hosting** (no cron, no Redis, no Composer on the server, MariaDB‑safe DDL), **read‑only for Info/HR**, members registered only through the web, no production DB touched by the agent.

### 5.1 Remove / retire

| Item | Why | Replacement |
|---|---|---|
| R1 — "Teachers" KPI from `members.is_teacher` | legacy flag superseded by `member_staff_positions` (`sql/017`); misleading | KPI "Staff with positions" from `member_staff_positions` (joined to `staff_positions`) |
| R2 — Duplicate preset export cards (same `export_pdf.php` links appear on `reports.php`, Info dashboard, HR dashboard, school_admin) | four copies drift independently (INFERENCE: nobody owns the list) | one **Export Centre** inside `reports.php`; dashboards keep a single "Open Reports" button |
| R3 — Tailwind from `cdn.tailwindcss.com` on `reports.php` | runtime CDN compile; the rest of the admin area ships its own CSS; breaks offline/blocked networks | local stylesheet (the page already has its own small CSS block) |
| R4 — "PDF" label for the print‑page export | it is a print‑ready HTML page, not a PDF; users expect a file | rename to "Print / Save as PDF" everywhere, or generate a real PDF with the already‑bundled TCPDF (`PdfReportService` does this for attendance) |
| R5 — Info sidebar "System Health Check" card | admin‑only page → dead link for Info (now explained by toast, still noise) | show only for `super_admin`/`school_admin` |
| R6 — Browser‑built "Custom Report" in `school_admin.php:472` (client‑side over a partially loaded member list) | different numbers from `reports.php` for the same question | link to the governed centre |

### 5.2 Add — Tier 1 (highest value, lowest risk; no schema change)

| Item | What | Reuses |
|---|---|---|
| **A1 — Population‑first workflow** | Filters move to a persistent left rail shared by *all three tabs*: every chart, KPI, list and export reflects the same population; URL carries the filter (`?gender=female&age_group=14_17…`) so a link *is* the report. | `getP()` already builds the query string |
| **A2 — Drill‑down everywhere** | Clicking any chart segment / KPI tile sets the corresponding filter and opens the list (Realm / Data Viewer pattern). | Chart.js `onClick`, existing filters |
| **A3 — Column picker** for the list and exports | Role‑aware column catalog (see A7); user ticks columns; CSV/Excel honour it; default sets per department. | `ExcelColumnMap` (already defines tiered column sets) |
| **A4 — Real Excel for filtered sets** | `.xlsx` of the filtered population (today only CSV; `.xlsx` exists only for the full roster). | `MemberExportService` streaming writer |
| **A5 — Metric glossary** | A "ⓘ" on every KPI/chart showing the exact definition ("Active = status in {active, warning}; archived excluded") sourced from **one PHP registry** (`ReportMetrics::definitions()`), which also drives the SQL — definitions can't drift from numbers. | new small service; `MemberCategory` for labels |
| **A6 — New read‑only dimensions (member‑level joins)** — the core of "no information lack": membership tier & promotion eligibility (`membership_tier`, `registered_at`, `promoted_at`), record completeness flags (photo, signed form, documents, guardian, phone), Family Link coverage (`member_guardians` count), current class & enrolment status (`class_enrollments` for the active `academic_years` row), staff position (`member_staff_positions`), attendance‑rate band for the selected window per source (from `attendance_rollup`‑style pre‑aggregates, never raw scans), group membership (`wbws_group_members`), app installed (`app_installations`). | read‑only SQL in a single `MemberQueryService`; budgets like `InfoAnalyticsService` |
| **A7 — Field‑level visibility matrix** (PowerSchool FLS pattern) | A static PHP matrix: column → roles that may *view* / *export*. Example defaults: Info and HR see everything in the directory they can already open in `info_manage_member.php`; grades remain Education‑only (not exposed at all in Tier 1); `activity_logs` never. Enforced server‑side in the column picker and in every export. | `access_control.php` style constant |
| **A8 — Ethiopian calendar periods** | Period picker: this/last academic year, this/last Ethiopian month, custom; period‑over‑period delta on KPIs. | `admin/backend/ethiopian_date.php`, `calendar_system.php`, `academic_years` |
| **A9 — Data‑quality report** (Information dept's core job) | Standing report: missing phone / photo / guardian / documents / ID card / age‑group vs DOB mismatch / probable duplicates (same normalised name + phone) with one‑click "open record". | `api_check_duplicate.php` logic, A6 flags |
| **A10 — Promotion & registration pipeline** (HR's core job) | Funnel: waiting → temporary → eligible (≥ 6 months) → promoted, with the "what is missing" list per eligible member, exportable. | HR eligibility SQL already in `hr-dept.php`/`info-dept.php` |
| **A11 — Export history for school_admin** | Table of `Member Report Requested` / `Member Export Requested` / `Member Analytics Explorer Queried` audit rows (who, when, filters, rows) — the oversight half of opening exports to two more departments. | `wbws_audit_log` via `SecurityAuditService` |

### 5.3 Add — Tier 2 (needs one small table; owner runs the migration)

| Item | What | Notes |
|---|---|---|
| **A12 — Saved reports ("Lists")** | Save a filter + column set + chart layout under a name; visibility *private / department / all reporting roles*; folders; "copy" before edit (Infinite Campus guidance). Opening a saved report re‑runs it live (no stored rows → no stale PII). | table `report_definitions` (id, owner_user_id, name, scope ENUM('private','department','shared'), department, definition JSON, created/updated). MariaDB‑safe; no FK to avoid rollback pain. |
| **A13 — Standing reports without cron** | "Pin" a saved report to the department dashboard; it re‑runs on open with cached aggregates (`cache_storage` table exists) and a visible "as of" stamp — Planning Center's auto‑refresh outcome without a scheduler. Optional: e‑mail/Telegram digest triggered by the first admin login of the day (lazy job pattern already used by `ensureRollupFresh`). | no cron dependency |
| **A14 — Pre‑aggregated member rollup** | `member_rollup_daily` (date × section × gender × status × tier counts) rebuilt lazily like `attendance_rollup`, so trend charts stop scanning `members` on every open as the roster grows. | same code pattern as `InfoAnalyticsService::refreshRollup` |

### 5.4 Add — Tier 3 (later / optional)

* **A15 — Report‑card‑style documents** (per‑member PDF "member file" with photo, guardians, attendance summary) via the bundled TCPDF + Ethiopic font — PowerSchool "object reports" idea, useful for HR's promotion review.
* **A16 — Read‑only data‑set API** (`/api/v1/reports/{dataset}`) for the future staff / parent apps, serving the same governed definitions (semantic‑layer principle: author once, reuse everywhere).
* **A17 — Grades exposure for Info/HR** — *only* as aggregate "has results this term yes/no" unless the Education department agrees to more (Q4).

### 5.5 Governance rules to adopt with the above

1. **View ≠ export.** Keep page roles as they are, but make *export* a column‑level decision (A7) and keep the audit (already in place). Consider a separate `can_export` flag per user later (Planning Center's 2025 change) — not needed for Tier 1.
2. **Row budgets stay:** list ≤ 2 000, export ≤ 5 000 with truncation banner, full roster only via `api_export_members.php`. For larger needs use pagination, not bigger limits.
3. **No stored result sets.** Saved reports store *definitions*, never rows.
4. **Definitions live in code, versioned, tested** (`tests/security/test_report_metrics.py` pins each definition's SQL) — the "enforced by tooling" lesson.
5. **Every new dimension is a read‑only join with a documented owner department** (Education owns enrolment, HR owns Family Link, Info owns directory quality) so the catalog answers "who do I ask if this number looks wrong".

---

## 6. Proposed target architecture (PROPOSED, shared‑hosting compatible)

```
admin/reports.php                 ── one page, three tabs, one persistent population filter rail
admin/api_reports.php             ── thin controller (auth, rate limit, audit) →
admin/backend/services/
   ReportMetrics.php              ── registry: metric/dimension name → label(am/en), definition text, SQL fragment, roles
   MemberQueryService.php         ── builds ONE prepared SELECT from whitelisted dimensions/measures/filters; budgets
   ReportDefinitionService.php    ── (Tier 2) save/load/share definitions
   MemberReportService.php        ── (existing) streamed CSV/print/Word, extended with column sets
   MemberExportService.php        ── (existing) xlsx writer, extended to filtered populations
```

* No new framework, no Composer dependency; `Chart.js` stays local; Tailwind replaced by local CSS.
* All reads are prepared statements over whitelisted identifiers; every list/export call is audited (already) and rate‑limited (already).
* Feature flag `FEATURE_REPORTS` continues to gate the module; a new `FEATURE_SAVED_REPORTS` flag gates Tier 2.

---

## 7. Roadmap & effort (PROPOSED estimates, one developer)

| Phase | Content | Effort | Needs owner action |
|---|---|---|---|
| **P0 (done)** | access fix, governance on `api_reports.php`, chart bug, denial toast | — | `git pull` |
| **P1** | R1–R6, A1, A2, A3, A4, A5, A8 | 4–5 days | none (no schema) |
| **P2** | A6, A7, A9, A10, A11 | 5–6 days | none (no schema); confirm field matrix (Q2) |
| **P3** | A12, A13, A14 | 4–5 days | run 1–2 small migrations in phpMyAdmin |
| **P4** | A15–A17 | later | Education agreement for grades (Q4) |

Acceptance for each phase: `php -l` / `node --check`, static security tests extended, HTTP role matrix in `tests/audit/http_regressions.py` updated, manual check as Info, HR and school_admin (including "Open as …" impersonation, which uses the *assumed* role).

---

## 8. Open questions for the owner (needed before P1 starts)

1. **Q1 – Need inventory:** does §3 match what Info and HR are actually asked? Anything missing (e.g. finance balances, material requests)?
2. **Q2 – Field matrix:** may Info export guardian phone numbers and addresses, or should those be HR‑only in exports (visible on screen but masked in files)?
3. **Q3 – Attendance at member level:** is a per‑member attendance band (e.g. "< 60 % this term") acceptable for Info to list and export, or HR only?
4. **Q4 – Grades:** should Info/HR see any academic result data (even yes/no), or does that stay with Education?
5. **Q5 – Saved‑report sharing scope:** private + department + all‑reporting‑roles (proposed), or private + all only?
6. **Q6 – Export permission:** keep export tied to page roles (today), or add a per‑user "can export files" switch managed by school_admin?
7. **Q7 – Real PDF vs print page:** generate true PDFs with the bundled TCPDF (slower, Amharic font ready) or keep the print page?

---

## 9. Sources (INDUSTRY)

1. PowerSchool SIS — Reports User Guide (custom vs preconfigured, group selection first): https://www.caminonuevo.org/ourpages/PowerSchool/advanced/ps_reports.pdf
2. PowerSchool SIS — Custom Reports: https://ps.powerschool-docs.com/pssis-admin/latest/custom-reports
3. PowerSchool SIS 24.6 release notes — Advanced Reports, Data Export Manager scheduling: https://ps.powerschool-docs.com/pssis-admin/latest/powerschool-sis-24-6-0-0
4. PowerSchool SIS — Data Export Manager (tables, data sets, PowerQueries, field‑level greying): https://ps.powerschool-docs.com/pssis-admin/latest/data-export-manager
5. PowerSchool SIS — Export data using DEM (security‑scoped data sources): https://ps.powerschool-docs.com/pssis-admin/latest/export-data-using-the-data-export-manager
6. PowerSchool SIS — Scheduled system templates for export / User Access Roles: https://ps.powerschool-docs.com/pssis-admin/latest/scheduled-system-templates-for-export , https://ps.powerschool-docs.com/pssis-admin/latest/user-access-roles
7. PowerSchool SIS — Field Level Security: https://ps.powerschool-docs.com/pssis-admin/latest/field-level-security
8. PowerSchool SIS — Import and Export → SIS Views: https://ps.powerschool-docs.com/pssis-admin/latest/import-and-export
9. Infinite Campus — Data Viewer: https://kb.infinitecampus.com/help/data-viewer
10. Montana OPI — AIM Ad Hoc Reporting user guide (Filter Designer, Data Export, folders, sharing to user groups): https://opi.mt.gov/Portals/182/Page%20Files/AIM/2%20AIM%20USER%20GUIDES/User%20Guides%20by%20Topic/Ad%20Hoc%20Reporting/AdHocReporting.pdf
11. META Solutions — Basic Ad Hoc (shared filter edit warning): https://www.metasolutions.net/wp-content/uploads/2025/07/FY26-BasicAdhoc-1.pdf
12. Planning Center — Lists overview: https://help.planningcenter.com/en/139197-lists-overview.html
13. Planning Center — Export data: https://help.planningcenter.com/en/138570-export-data.html
14. Planning Center — List settings (collaborators, auto‑refresh): https://pcopeople.zendesk.com/hc/en-us/articles/360037772313-List-settings
15. Planning Center — "Manage who can export CSVs and reports" (2025): https://www.planningcenter.com/blog/2025/02/5-new-features-you-may-have-missed-in-people
16. Realm (ACS Technologies) — Dashboards → export a dashboard list: https://help.acst.com/en/realm/how-to/reporting/dashboards/dashboards
17. Realm — Reports (custom queries, save and share): https://help.acst.com/en/realm/how-to/reporting/reports
18. Breeze ChMS — Creating attendance reports (saved reports, permission): https://support.breezechms.com/hc/en-us/articles/360001188754-Creating-Attendance-Reports
19. Domo — Self‑service analytics best practices (semantic layer, catalog, RBAC): https://www.domo.com/learn/article/self-service-analytics-best-practices
20. Domo — Self‑service reporting guide ("spreadmart" problem): https://www.domo.com/glossary/what-is-self-service-reporting
21. Anthropic — self‑service data analytics stack (governance enforced by tooling, canonical datasets): https://claude.com/blog/how-anthropic-enables-self-service-data-analytics-with-claude
22. Bluepes — Role‑based access in education systems (see/do/log; export as separate permission; minimum audit): https://bluepes.com/blog/role-based-access-education-systems
23. U.S. Dept. of Education — FERPA §99.31 legitimate educational interest: https://studentprivacy.ed.gov/ferpa
24. SecurePrivacy — FERPA compliance software guide (append‑only logs incl. exports/downloads): https://secureprivacy.ai/blog/ferpa-compliance-software
