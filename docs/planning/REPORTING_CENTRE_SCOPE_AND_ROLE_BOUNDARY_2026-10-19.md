# Reporting Centre — Scope & Role Boundary Decision (2026‑10‑19)

**Status:** DECIDED by the owner on 2026‑10‑19 · **Review trigger:** first round of Info + HR user feedback on V2‑1…V2‑3
**Applies to:** `admin/reports_v2.php`, `admin/api_reports_v2.php`, `admin/api_report_views.php`, `App\Reporting\ReportRegistry`
**Related:** `REPORTING_ENGINE_STACK_AND_UX_DECISION_2026-10.md` (engine/stack ADR), `ANALYTICS_CENTER_IMPROVEMENT_RESEARCH_2026-10.md` (research)

---

## 1. Decision (owner's words, recorded)

> "These things will go to the Information Department only, because financial and other sensitive data doesn't work for HR — HR doesn't have to get these infos anyway. Document this properly and we will update it after the user feedback."

Interpretation used from here on (FACT = owner statement; INFERENCE = how it is applied):

| # | Statement | Type |
|---|---|---|
| D1 | Any **future cross‑department dataset** added to the Reporting Centre (Finance, Material, Mezmur department records, detailed Education data, system/telemetry data, …) is for the **Information Department** (and the two admin roles), **not HR**. | FACT |
| D2 | HR's reporting scope stays what HR actually needs for its own work: membership & registration, promotion to permanent, ID cards, data quality of member records, Family Link, and the enrolment / attendance **bands** already delivered in V2‑2 (HR uses Education + Mezmur attendance since 2026‑10‑18, HR takes no attendance of its own). | INFERENCE from D1 + the 2026‑10‑18 HR‑attendance decision |
| D3 | Nothing is removed from HR now; the boundary governs **additions**. Whether any currently shared field should also move to Info‑only is decided **after user feedback**. | INFERENCE (owner: "we will update it after the user feedback") |
| D4 | V2‑4 (department datasets) is **not started**. The next step is to collect feedback from real Info and HR users on V2‑1…V2‑3. | FACT |

## 2. Why (rationale, for future readers)

* **Need‑to‑know.** HR's processes (registration, promotion, ID cards, guardian links) never require fees, payments, materials, grades or device data. Giving a department data it does not act on only widens the audience for sensitive information and makes export audit trails harder to reason about.
* **Information Department is the cross‑cutting consumer.** Info is the department that answers questions *about the whole school* (reports to administration, statistics, data quality), so it is the natural single home for cross‑department analytics — which is exactly what the "never lack information" goal in the research document asked for.
* **One registry, two audiences.** The registry already carries per‑field `view` / `export` role lists; expressing "Info‑only" is a one‑line change per field/dataset, with no new pages or endpoints (see §5).

## 3. Role matrix — current state (as deployed in `072005e`)

| Capability | super_admin | school_admin | info_dept | hr_dept | others |
|---|---|---|---|---|---|
| Open Reporting Centre (`reports_v2.php`) | ✔ | ✔ | ✔ | ✔ | ✖ (302 access_denied) |
| Members dataset — identity, membership, contact, guardian, quality, staff, education enrolment, attendance bands (Edu + Mezmur) | ✔ | ✔ | ✔ | ✔ | ✖ |
| `created_by` (system) | ✔ | ✔ | ✖ | ✖ | ✖ |
| `emergency_phone` | ✔ | ✔ | ✖ | ✔ | ✖ |
| Starter "Active members without ID card" | ✔ | ✔ | ✖ | ✔ | ✖ |
| CSV / Excel export (audited) | ✔ | ✔ | ✔ | ✔ | ✖ |
| Export log (who exported what) | ✔ | ✔ | ✖ | ✖ | ✖ |
| Saved views: private / department / shared | ✔ | ✔ | ✔ | ✔ | ✖ |
| Pin standing reports; edit/delete any view | ✔ | ✔ | ✖ | ✖ | ✖ |
| Grades / academic records | ✖ | ✖ | ✖ | ✖ | — (out of scope by the 2026‑10‑18 decision; not in the registry at all) |

## 4. Role matrix — planned additions (NOT built; governed by this decision)

| Future dataset / field group | info_dept | hr_dept | Notes |
|---|---|---|---|
| Finance (fees, payments, balances, receipts) | ✔ (planned) | ✖ | Sensitive; Finance department sign‑off required before any field is registered |
| Material department (inventory, issues/returns) | ✔ (planned) | ✖ | |
| Mezmur department records beyond the attendance band (groups, hymn programme participation) | ✔ (planned) | ✖ | Attendance band stays shared (already delivered) |
| Education detail beyond enrolment/attendance band (subjects, assessments, grades) | ✖ until Education decides | ✖ | Grades remain excluded for both unless a separate decision is taken |
| App / device telemetry, system health | ✔ (planned) | ✖ | Not member‑linked today; would be its own dataset |
| Audit / export history | admins only | ✖ | Unchanged |

Anything not listed defaults to **Info + admins only** until the owner says otherwise.

## 5. How the boundary is enforced in code (so the next change is small and safe)

1. **Field level** — `ReportRegistry::fields()` entries carry `'view' => …` and `'export' => …` role arrays. Shorthands exist: `R_ALL` (4 roles), `R_HR` (admins + HR), `R_ADMIN`. For Info‑only data add a shorthand `R_INFO = ['super_admin', 'school_admin', 'info_dept']` and use it on every such field (export is automatically clipped to ⊆ view).
2. **Dataset level** — `ReportRegistry::datasets()` is where a new base table (e.g. `payments`) would be declared; `schemaForRole()` must hide datasets whose fields are all invisible to the role (small addition when the first non‑members dataset arrives).
3. **Starters & metrics** — starters already carry `'roles'`; metrics inherit visibility from the fields they reference (a metric over an Info‑only join must declare `requires` and be given a `roles` key — add when needed).
4. **Saved views** — `api_report_views.php` validates every definition with the *saver's* role and the opener's UI drops fields its role cannot see, so a shared view can never leak an Info‑only field to HR.
5. **Tests to extend when the first Info‑only field lands** — `tests/security/test_reporting_centre_v2.py` (pin that no Finance/Material table appears in a field whose `view` includes `hr_dept`) and `tests/e2e/reports_v2_live_sweep.py` run as `hr` (schema must not list the field).

## 6. Feedback round — what we are waiting for

Collect for **two to three weeks** of real use, separately from Info and HR:

| Question | Why |
|---|---|
| Which starter questions did you actually use? Which are missing? | Decides the V2‑4 field matrix and whether starters need re‑grouping |
| Which columns did you add to lists most often? | Candidates for default columns |
| Did any list / export fail to answer a question you had to ask another department for? | This is the core goal — each case is a V2‑4 candidate |
| Were there numbers you did not trust? (which KPI / chart) | Definition or data‑quality issue |
| Amharic labels: wrong or missing? | Registry label fixes |
| Did the "Classic view" still get used, and for what? | Decides when `reports.php` can be retired |
| Saved views: who shared what, were department views useful? | Validates V2‑3 scopes |
| HR only: any field you did not need at all? | Feeds D3 (possible narrowing of HR's scope) |

Feedback should be recorded in `docs/planning/REPORTING_CENTRE_FEEDBACK_<date>.md` (one section per department); the owner then updates §3/§4 of this document and approves the V2‑4 field matrix before any code is written.

## 7. Parked until after feedback

* V2‑4 department datasets (Info‑only per this decision).
* Retirement of the classic `admin/reports.php` and the duplicated export cards on the Info/HR dashboards (parity sign‑off needed).
* PDF export through TCPDF in the new centre; compiled Tailwind for the 12 CDN pages.
* Any narrowing of HR's current scope (D3).

## 8. Change log

| Date | Change | By |
|---|---|---|
| 2026‑10‑19 | Initial decision recorded: future cross‑department / sensitive datasets are Information‑Department‑only; HR scope frozen at V2‑3; V2‑4 paused for user feedback. | owner (decision), agent (record) |
