# Reporting / Analytics / Export Engine — Stack & UX Decision Record

**Companion to:** `ANALYTICS_CENTER_IMPROVEMENT_RESEARCH_2026-10.md` (what to add/remove) — this document answers **how to build it**: which mature open‑source pieces to adopt instead of writing from scratch, and what a modern UX for the centre should look like.
**Date:** 2026‑10‑18 · **Status:** PROPOSED — awaiting owner decisions in §7. No code changes.

Tags: **FACT** (verified in repo / official docs), **INFERENCE**, **PROPOSED**.

---

## 1. Hard constraints that decide the architecture (FACT)

| Constraint | Source | Consequence |
|---|---|---|
| Production = shared hosting, PHP 8.2, MariaDB 11.4, no cron, no Composer on server, no Java/Node/Python/Redis | owner's standing instructions; ACC D0 | A separate BI *server* (Metabase, Superset, Redash, Grafana, Lightdash, Cube, Evidence) **cannot run on the production host**. Everything must be PHP + MariaDB + static browser assets, vendored into the repo. |
| Already vendored: `phpoffice/phpspreadsheet ^5.9`, `edgaras/strsim`, TCPDF (`admin/backend/pdf/tcpdf`), Chart.js 4.4.1 (`admin/js/chart.umd.min.js`) | `composer.json`, `vendor/`, `admin/js` | PDF and Excel already exist; the gaps are the *query/definition layer* and the *interactive UI*. |
| Admin UI convention = PHP pages + Tailwind **CDN** (12 admin pages, incl. HR/Info dashboards) + vanilla JS modules in `admin/js/` (no bundler, no jQuery, no React) | `grep cdn.tailwindcss.com` → 12 files; no `package.json` | New UI libraries must be **drop‑in UMD/ESM files**, framework‑free. (Correction to the earlier research doc R3: removing Tailwind *only* from `reports.php` is not the fix — a one‑time compiled Tailwind build for the whole admin area is the right system‑wide item.) |
| Exports must stay audited, role‑scoped, bounded (≤5 000 rows), rate‑limited | `SecurityAuditService`, `MemberReportService`, `SecurityRateLimiter` | **All files are generated server‑side**. Browser‑side "download" features of grid libraries (CSV/XLSX via SheetJS) must be disabled — they would bypass audit and field‑level security. |

---

## 2. Options for the engine

### Option A — Separate BI product (Metabase OSS) · *not feasible now*
Metabase needs Java 21+/25, ≥1 vCPU + 2 GB RAM (JVM alone ≈1 GB), i.e. a VPS/container, plus remote DB access from that host to the shared‑hosting MariaDB. The free edition offers guest embeds (signed JWT) but **no row/column security, no per‑user drill‑through, "Powered by Metabase" banner**; per‑user SSO embeds are a paid plan ($575/month). It would also sit *outside* SSMS's role model and audit log. → Keep as a future option **only if** the school ever gets a VPS; not the path for this upgrade.

### Option B — PHP reporting framework (KoolReport core, MIT)
Pure PHP, runs on shared hosting, MIT core with data‑sources/processes/widgets. But the pieces that matter here — Excel, Export (PDF), Pivot, DataGrid, DrillDown, Inputs — are **paid add‑ons** ($29–$69 each / Pro $199+), the core targets PHP 5.6–8.0 era patterns, and its widgets would duplicate what SSMS already has (TCPDF, PhpSpreadsheet, Chart.js, own RBAC). → Adopting it buys little and adds a second rendering paradigm. Not recommended.

### Option C — **Composable: mature open‑source components + a thin governed PHP core** · *recommended*
Use best‑of‑breed libraries for every layer where a mature one exists, and write **only** the piece for which no mature PHP library exists: the *dataset/metric registry* that turns a declarative report definition into safe SQL under SSMS's role model.

| Layer | Component (license, maturity) | Why this one | Status |
|---|---|---|---|
| Interactive data grid (list, column picker, header filters, grouping, column calcs, responsive collapse, layout persistence, localisation) | **Tabulator 6.x** (MIT, v6.6, framework‑free UMD, actively released 2026) | Has column visibility/order/width persistence, header filters, grouping + calcs, responsive layout and Amharic‑safe plain DOM rendering **without** a build step or React. (AG Grid Community lacks the column tool‑panel / pivot — those are Enterprise‑only.) | PROPOSED, vendored under `admin/js/vendor/tabulator/` |
| Charts | **Chart.js 4** (already local) for KPIs/doughnuts/trends; **Apache ECharts 6** (Apache‑2.0) *only if* dataZoom/brush/toolbox/data‑view or large‑series charts are wanted | Chart.js covers today's 13 charts; ECharts adds built‑in zoom/brush/"view data" toolbox but costs ~1 MB. Decision in §7‑Q2. | Chart.js FACT; ECharts PROPOSED‑optional |
| Streaming Excel export | **openspout/openspout** (MIT, PHP 8.1+, ~2 MB peak RAM at 100 k rows vs ~500 MB for PhpSpreadsheet) | Shared‑hosting memory limits; PhpSpreadsheet stays for templated/formatted workbooks (ID cards, executive report), openspout for bulk list exports. | PROPOSED, `composer require` locally → vendored |
| PDF | **TCPDF** (already vendored, Ethiopic font path proven by `PdfReportService`) | Real `.pdf` files instead of the "print page"; no new dependency. | FACT (exists) |
| CSV | existing streamed writer in `MemberReportService` (+ formula‑injection escaping already present) | — | FACT |
| Safe SQL assembly | **doctrine/dbal** QueryBuilder (MIT) **or** keep hand‑built prepared statements with identifier whitelists | DBAL gives identifier quoting, expression builder, parameter typing, and is the standard in PHP; cost ≈ 400 files vendored. Hand‑built is what SSMS does today and is adequate if the registry is small. Decision in §7‑Q3. | PROPOSED |
| Pivot / cross‑tab | Tabulator `groupBy` + column calcs (covers 80 %); **FINOS Perspective** (Apache‑2.0, WASM) later if true drag‑and‑drop pivoting is demanded | Avoid PivotTable.js (unmaintained since 2018). | PROPOSED‑later |
| Caching of aggregates | existing `cache_storage` table + lazy refresh (`ensureRollupFresh` pattern) | no Redis / cron | FACT (pattern exists) |
| Scheduling | none on host → "standing reports" re‑run on open; optional external HTTPS pinger hitting a signed endpoint | shared hosting | PROPOSED |
| **Dataset / metric registry (the only in‑house piece)** | PHP: `ReportRegistry` (datasets, fields, roles, metrics, joins), `ReportQueryService` (definition → one prepared SELECT with budgets), `ReportDefinitionStore` (saved reports) | No mature PHP semantic layer exists (Cube, dbt‑metrics, LookML are Node/Python/SaaS). This is ~1 000 lines, fully unit‑testable, and is where RBAC, field‑level security and audit are enforced once for *every* department. | PROPOSED |

**Why this is "not from scratch":** the user‑visible 80 % (grid, charts, Excel, PDF, persistence, filtering UI) is third‑party and mature; the in‑house 20 % is a declarative registry — the same split PowerSchool (DEM data sets + FLS), Infinite Campus (Ad Hoc data types) and Metabase (models + query builder) use internally.

---

## 3. Target architecture (PROPOSED)

```
Browser (vanilla JS modules, no bundler)
  admin/js/reports/
    app.js              shell: left filter rail, tabs, URL state, saved views
    filters.js          Planning‑Center‑style rule rows (field · operator · value), chips
    grid.js             Tabulator wrapper (server‑side paging, column picker, FLS‑aware columns)
    charts.js           Chart.js (optionally ECharts) + click‑to‑drill → filters
    exports.js          calls server export endpoints only (no client‑side files)
  admin/js/vendor/tabulator/…, chart.umd.min.js (existing)

PHP (shared hosting, vendored libs)
  admin/reports.php                      page shell (role check, CSRF token, bootstraps JSON config)
  admin/api_reports.php                  controller: auth → rate‑limit → validate → ReportQueryService → audit
  admin/api_report_export.php            same pipeline → openspout / TCPDF / CSV streams
  admin/backend/reporting/
    ReportRegistry.php                   DATASETS: members, enrolments, attendance_rollup, guardians, staff_positions, groups, app_installations …
                                         FIELDS: key, label am/en, type, dataset, SQL expr, view_roles, export_roles, pii flag
                                         METRICS: count_members, pct_active, attendance_rate … with definition text (glossary)
    ReportQueryService.php               definition JSON → prepared SELECT (whitelisted joins), budgets, paging, totals
    ReportDefinitionStore.php            saved views (Tier 2: table report_definitions; definitions only, never rows)
    ReportExportService.php              column set + FLS → CSV | XLSX (openspout) | PDF (TCPDF)
    ReportGlossary.php                   serves metric definitions to the UI (ⓘ) and to docs
  tests/security/test_report_registry.py   pins every field's roles + every metric's SQL; forbids raw SQL from clients
```

**Security properties (PROPOSED, enforced in code, pinned by tests)**
1. Clients send *definitions* (field keys, operators, values) — never SQL. Unknown key/operator → 400.
2. Every field has `view_roles` and `export_roles`; the server strips fields the caller may not see **before** querying, and exports pass through the same filter (PowerSchool FLS model).
3. Budgets: grid page ≤ 200 rows (server paging via Tabulator `ajaxURL`), explorer total ≤ 2 000, export ≤ 5 000 — unchanged.
4. Every list and export call → `SecurityAuditService` (who, dataset, filter summary, row count, format); `SecurityRateLimiter` keys per action.
5. PII fields (`phone`, guardian phones, address) carry `pii: true` → masked in UI for roles without export right, excluded from files, and counted in the audit row.
6. No client‑side download features (Tabulator `download*` disabled), no SheetJS/jsPDF shipped.
7. Saved reports store JSON definitions, validated on load against the registry (fields that no longer exist or are no longer visible are dropped with a notice).
8. CSP: all assets same‑origin; drop `cdn.tailwindcss.com` in the same system‑wide step as the compiled Tailwind build.

---

## 4. UX findings — why the current tabs feel dated (FACT about `reports.php` + INFERENCE)

| Current behaviour | Problem | Modern pattern (sources §8) |
|---|---|---|
| Three tabs, each with its own controls; filters exist only in "Data Explorer" | the user must *remember* context; charts and list never agree | one **global filter rail** + chips that drive every tab (global filters updating all widgets) |
| 11 KPI tiles + 10 charts on first paint | "dashboard paralysis"; no hierarchy | 3–5 role‑specific KPIs on top → trend/context in the middle → tables behind drill‑down (progressive disclosure, Shneiderman "overview → zoom/filter → details") |
| Charts are static pictures | the answer is visible but not reachable | click a segment → filter applied → list opens (Metabase drill‑through, Realm) |
| Data Explorer = 13 dropdowns in a block, fixed 9 columns, "first 500" | discovery is hard; can't pick columns; truncation unexplained | rule rows ("Age group **is** B", "Phone **is empty**"), column picker, server paging with total count, empty/loading/partial states designed deliberately |
| Export Centre = static cards linking to `export_pdf.php` | no relation to what the user is looking at | one **Export** button on the current population with format + column set; recent exports list ("as of", rows, who) |
| No saved views, no starting points | every visit starts from zero | **Saved views** (private / department / shared) + role‑specific *starter questions* ("Eligible for promotion", "Members without guardian", "Class B without phone") |
| No definitions | numbers are disputed between departments | ⓘ glossary on every metric, provenance footer "source: members, as of 10:32" |
| Desktop‑only grid | HR/Info sometimes on tablets/phones | Tabulator responsive collapse, ≥44 px touch targets |

### 4.1 Proposed information architecture (PROPOSED)

```
┌ Reports ───────────────────────────────────────────────────────────────────┐
│ Starters: [Eligible for promotion] [Missing guardian] [Incomplete records] │
│           [Class roster] [Registrations this E.C. year] [My saved views ▾]  │
├ Filter rail (persistent) ───┬ Workspace ───────────────────────────────────┤
│ Period: This E.C. year ▾     │  KPI strip (4 tiles, with Δ vs previous)    │
│ Section: ☐A ☑B ☐C            │  ─────────────────────────────────────────  │
│ Status: Active ▾             │  Tabs: Overview · Breakdown · List            │
│ + Add rule (field·op·value)  │  Overview = 4–6 charts, every segment clickable│
│ Chips: [Section B ×][Active ×]│  Breakdown = group by any field (Tabulator)  │
│ [Save view] [Share link]     │  List = grid, column picker, server paging    │
│                              │  Export ▾ (CSV · Excel · PDF) · columns set   │
└──────────────────────────────┴────────────────────────────────────────────────┘
Footer: Definitions ⓘ · Source datasets · Data as of HH:MM · Recent exports
```

Bilingual labels (am/en) come from the registry, so both UI and exports use the same wording.

---

## 5. Scalability & maintainability notes (INFERENCE)

* **Reads only, bounded, paged, cached**: grid pages via server paging; aggregates via lazily refreshed rollups (pattern already proven with `attendance_rollup`); MariaDB 11.4 window functions/CTEs available for rollup builders.
* **One registry, many consumers**: the same `ReportRegistry` later serves the Education, Mezmur and Finance departments (add datasets + role rules; no new pages), the staff mobile app (`/api/v1/reports/*`), and the glossary — "author once, reuse everywhere".
* **Testability**: the registry is pure PHP data + builders → static tests pin roles/SQL; HTTP role matrix extended in `tests/audit/http_regressions.py`.
* **Upgrade path**: Tabulator/ECharts/openspout are plain versioned files; no bundler to maintain. If a VPS ever appears, Metabase can read the same rollup tables — the registry work is not wasted.
* **Lifecycle**: `FEATURE_REPORTS_V2` flag; old `reports.php` kept until parity, then removed (R‑items from the research doc).

---

## 6. Phased plan with the stack (PROPOSED)

| Phase | Deliverable | Effort | Owner action |
|---|---|---|---|
| **V2‑0 Foundation** | `ReportRegistry` (members dataset only, all current fields + FLS roles), `ReportQueryService`, tests, new `api_reports.php` contract; vendor Tabulator; compiled Tailwind for admin area | 4–5 d | approve stack (Q1–Q4) |
| **V2‑1 New UI** | filter rail + rules + chips + URL state, KPI strip, drillable charts, Tabulator list with column picker & server paging, server exports (CSV/XLSX via openspout/PDF via TCPDF), glossary, starters | 5–6 d | UAT with Info + HR |
| **V2‑2 Cross‑domain datasets** | enrolments/classes, attendance bands (from rollups), guardians, staff positions, groups, app installs; Data‑Quality & Promotion starters; export history for school_admin | 5–6 d | confirm field matrix (research doc Q2–Q4) |
| **V2‑3 Saved views** | `report_definitions` table, private/department/shared, pinned standing reports, lazy refresh | 3–4 d | run 1 migration |
| **V2‑4 Roll‑out to other departments** | Education/Mezmur/Finance datasets behind the same registry; retire `school_admin` client‑side custom report and duplicated export cards | per dept 2–3 d | dept sign‑off |

---

### 6.1 Delivery status (updated 2026‑10‑19)

| Phase | Status | Commit |
|---|---|---|
| V2‑0 Foundation | DONE | `5d10294` |
| V2‑1 New UI (`admin/reports_v2.php`, beta; legacy page kept) | DONE | `3d44f1c` |
| V2‑2 Cross‑domain | DONE — members grain extended with Education enrolment (class section, enrolled‑on, class teacher, previous‑year class, year‑on‑year progression), Education + Mezmur attendance bands (current academic‑year date range, holidays excluded, late = attended), Family Link guardian (name/phone/relation, linked siblings), staff position titles; 5 new metrics; 5 new starters (Follow‑up / Promotion / Data quality); field categories; **export log** for school_admin/super_admin. Out of scope by decision: grades; groups (`wbws_group_members` has no `member_id`); app installs (device telemetry, not member‑linked). | this commit |
| V2‑3 Saved views (shared) | DONE — `sql/070_report_definitions.sql` (owner runs in phpMyAdmin; code degrades gracefully until then), `admin/api_report_views.php` (list/get/save/delete/pin/opened; CSRF; definitions validated with the saver's role; owner‑or‑admin edit; admins pin **standing reports** which appear in the starter row for everyone), rail panel My views / My department / Shared, share links carry the view id, one‑click import of pre‑V2‑3 browser‑local views. | this commit |
| V2‑4 Other departments | pending | — |

## 7. Decisions needed from the owner

1. **Q1 — Approve Option C** (composable stack with in‑house registry) over A (Metabase, needs VPS) and B (KoolReport, paid add‑ons)?
2. **Q2 — Charts:** keep Chart.js only (lighter, already local) or add Apache ECharts (zoom/brush/data‑view toolbox, ~1 MB)?
3. **Q3 — SQL layer:** vendor `doctrine/dbal` (standard, ~400 files) or stay with hand‑built prepared statements + whitelists (zero new dependency)? Recommendation: hand‑built for V2‑0/1 (registry is small), re‑evaluate at V2‑4.
4. **Q4 — Excel:** vendor `openspout` for bulk list exports (recommended for memory safety) or keep PhpSpreadsheet only?
5. **Q5 — Tailwind:** approve a one‑time compiled Tailwind CSS for the whole admin area (replaces the CDN script in 12 pages) as part of V2‑0?
6. **Q6 — Scope of first UAT:** Info + HR only (recommended) or include Education from V2‑1?
7. (From the research doc) field matrix / member‑level attendance / grades / sharing scope / export permission / real PDF.

---

## 8. Sources

* Metabase: production sizing (1 core/1 GB baseline, +1 CPU/2 GB per 20 users) — https://www.metabase.com/learn/metabase-basics/administration/administration-and-operation/metabase-in-production ; JAR requires Java 25 — https://www.metabase.com/docs/latest/installation-and-operation/running-the-metabase-jar-file ; embedding plans & guest‑embed limits — https://www.metabase.com/articles/is-metabase-embedding-free , https://www.metabase.com/docs/latest/embedding/guest-embedding ; query‑builder / drill‑through UX — https://www.metabase.com/learn/metabase-basics/getting-started/ask-a-question , https://www.metabase.com/learn/metabase-basics/overview/tour-of-metabase
* KoolReport core MIT + paid packages — https://github.com/koolreport/core
* Tabulator 6.x: download/persistence/options docs — https://www.tabulator.info/docs/6.x/download/ , https://www.tabulator.info/docs/6.x/persist/ , https://www.tabulator.info/docs/6.x/options/ ; npm 6.6.1 MIT — https://www.npmjs.com/package/tabulator-tables
* Chart.js vs Apache ECharts comparisons — https://www.usedatabrain.com/blog/javascript-chart-libraries , https://echarts.apache.org/en/feature.html
* openspout vs PhpSpreadsheet memory/throughput — https://github.com/turgutahmet/kolay-xlsx-stream (cross‑package benchmark table) , https://stackoverflow.com/questions/50731704/building-very-large-spreadsheet-with-phpspreadsheet
* Dashboard UX: progressive disclosure, global filters + saved presets, Shneiderman mantra, designed empty/loading states — https://uxpilot.ai/blogs/dashboard-design-principles , https://www.aufaitux.com/blog/dashboard-design-principles/ , https://querio.ai/blogs/best-practices-data-visualization , https://www.saasui.design/blog/saas-analytics-reporting-dashboard-ux-patterns , https://uiuxhero.com/blogs/saas-dashboard-design-best-practices-principles-examples-2025
