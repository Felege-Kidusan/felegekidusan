# WBSS / FKSS Sunday School Management System
## Phase 1 — Structural Audit & Risk Inventory

**Auditor role:** Senior software architect, production-readiness review
**Scope:** Full codebase structural inventory and risk map (no fixes proposed in this phase)
**Target load:** 5,000+ students, multi-year continuous operation
**Codebase snapshot:** single git commit `a27accb "project upload"`; branch `claude/wbss-fkss-audit-v8syg9`
**Platform:** PHP 8.2 (cPanel/ea-php82) + MariaDB 10.6, Apache with `.htaccess`

---

## ARCHITECTURE OVERVIEW

This is a **PHP/MariaDB Sunday School Management System** for an Ethiopian Orthodox Tewahdo church school (currently branded FKSS — "Felege Kidusan," `school_config.php:21`, deployed at `felegekidusan.arkeonethiopia.com`). It manages members/students, class enrollment, attendance, grades, teachers, groups/associations, finance, materials inventory, ID-card generation (with QR codes), a public CMS landing page, an AI chatbot, a PWA mobile app, and a JWT REST API. It is a **single-tenant, single-server, procedural PHP monolith** with no framework, no dependency manager (no Composer), no build step, no automated tests, and no migration runner — the entire schema is created and repaired ad-hoc at runtime. Authentication is session-based for the admin panel and homemade-JWT for the mobile API. The system is architecturally in the **middle of an unfinished refactor**: there are effectively **five overlapping presentation/API layers** (`admin/`, `backend/` shims, `api/v1/`, `app/`, `frontend/`), most of which are half-migrated. The whole application funnels through one 869-line `config.php` that also performs live schema mutation on nearly every request. It functions today at ~15 members but carries systemic scale, data-integrity, and single-point-of-failure risks that will surface between roughly 300 and 5,000 members.

### The five overlapping layers (key architectural finding)

| Layer | Path | Purpose | Status |
|-------|------|---------|--------|
| Primary admin monolith | `admin/*.php`, `admin/dashboards/`, `admin/api_*.php` | The real, live system | Active |
| "backend" shim tree | `backend/api/*.php`, `backend/auth/`, `backend/core/`, `backend/users/` | Redirect shims + duplicate copies pointing back into `admin/` | Half-migrated, stalled |
| REST API v1 | `api/v1/` | JWT token API for mobile clients | Parallel, separately maintained |
| PWA mobile app | `app/` | Offline-capable front-end (service worker) consuming `admin/api_mobile.php` + `api/v1` | Parallel |
| "New separated frontend" | `frontend/` | A rewrite; **only `finance_dept` has migrated to it** (`dashboard.php:71-74`) | 5% migrated |

This means the same business concept (e.g. "list members," "current academic year") is implemented **three to five times** in different styles, and a change to one does not propagate to the others.

---

## FILE INVENTORY TABLE

Legend — Risk: **CRITICAL** (will break production or expose data), **HIGH** (breaks at scale / hard to maintain), **MEDIUM** (fragile / duplicated), **LOW** (fine). "Dep count" = approximate number of other files that `require`/`fetch` it, or that it pulls in.

### Core / bootstrap

| File | Lines | Purpose | Size concern | Dep count | Risk |
|------|------:|---------|--------------|----------:|------|
| `config.php` | 869 | God bootstrap: env loading, DB connect (both MySQLi + PDO), sessions, CSRF, validation helpers, auth helpers, rate-limiting, **runtime DDL auto-fix**, monitor include | **God file**; does ~10 unrelated jobs | ~120 (everything) | **CRITICAL** |
| `school_config.php` | 332 | All branding/feature-toggle constants; single deploy-config file | Large constant block, OK | ~120 | MEDIUM |
| `admin/config.php` | 26 | Thin include of root `config.php` | OK | ~40 | LOW |
| `backend/config/loader.php` | 32 | Yet another config path resolver | Duplicate of admin/config | few | MEDIUM |
| `admin/backend/config.php` | 13 | Another thin config include | Duplicate | migrations | MEDIUM |
| `admin/app_session.php` | 53 | Mobile app session bridge | OK | app | LOW |

### Public / entry points

| File | Lines | Purpose | Size concern | Dep count | Risk |
|------|------:|---------|--------------|----------:|------|
| `index.php` | 833 | Public landing page; pulls CMS content live from DB | Large single-file HTML+PHP; **runs full `config.php` incl. DDL auto-fix on public homepage** | entry | HIGH |
| `index.html` | 715 | **Static, older copy** of the landing page | Apache serves `index.html` before `index.php` by default → can **shadow** the dynamic site | none | HIGH |
| `member.php` | 254 | Public QR-scan ID verification landing (`?code=`) | `SELECT *` on members for public scan | entry | MEDIUM |
| `register_submit.php` | 86 | Public self-registration intake | OK | entry | MEDIUM |
| `admin/index.php` | 359 | Admin login page + CSRF form | OK | entry | LOW |
| `admin/dashboard.php` | 509 | Role router → dashboards; impersonation bar | Role switch OK | hub | MEDIUM |
| `admin/logout.php` | 26 | Session destroy | OK | 1 | LOW |

### Admin dashboards (presentation god-files)

| File | Lines | Bytes | Purpose | Risk |
|------|------:|------:|---------|------|
| `admin/dashboards/info-dept.php` | **5,161** | **299 KB** | Info-dept member management UI + inline PHP queries + huge inline JS | **CRITICAL god file** |
| `admin/dashboards/super-admin.php` | 1,855 | 143 KB | Super-admin console, user mgmt, backups | **HIGH god file** |
| `admin/dashboards/teacher.php` | 1,266 | 78 KB | Teacher dashboard | HIGH |
| `admin/dashboards/edu_dept.php` | 1,291 | 141 KB | Education dept (classes, enrollment, years) | HIGH |
| `admin/dashboards/school_admin.php` | 1,065 | 124 KB | School admin console | HIGH |
| `admin/dashboards/content_editor.php` | 748 | 47 KB | CMS editor | MEDIUM |
| `admin/dashboards/groups.php` | 693 | 40 KB | Groups UI | MEDIUM |
| `admin/dashboards/attendance_taker.php` | 514 | 28 KB | Attendance entry | MEDIUM |
| `admin/dashboards/ai_assistant.php` | 500 | 29 KB | AI assistant panel | MEDIUM |
| `admin/dashboards/finance_department.php` | 242 | 38 KB | Legacy finance (superseded by `frontend/`) | MEDIUM (dead-ish) |
| `admin/dashboards/material_department.php` | 234 | 36 KB | Material dept | MEDIUM |
| `admin/dashboards/ai_chatbot_widget.php` | 296 | 15 KB | Chat widget injected on all dashboards | MEDIUM |
| `admin/dashboards/education_department.php` | 57 | 2 KB | Thin/legacy redirect | LOW (dead-ish) |

### Admin APIs (business logic)

| File | Lines | Purpose | Risk |
|------|------:|---------|------|
| `admin/api_education.php` | 1,073 | Enrollment, promotion, grades, attendance, academic years/terms — **also runs DDL at request time** (`:42-95`) | **CRITICAL** |
| `admin/api_teachers.php` | 810 | Teacher assignments — **runs `ALTER TABLE` on live requests** (`:56-101`) | **CRITICAL** |
| `admin/backend/groups_api.php` | 1,272 | Groups/associations CRUD + audit; **creates 4 tables at runtime** (`:144-257`) | **HIGH god file** |
| `admin/api_ai.php` | 693 | AI chatbot; external API calls + file cache | HIGH |
| `admin/api_subjects.php` | 647 | Subjects + class-subject mapping | MEDIUM |
| `admin/api_communication.php` | 566 | Shared documents / notices | MEDIUM |
| `admin/api_branding.php` | 571 | Branding asset uploads (logo/seal/signatures) | HIGH (upload) |
| `admin/api_cms.php` | 450 | Public CMS content + image uploads | MEDIUM (upload) |
| `admin/api_settings.php` | 353 | System settings | MEDIUM |
| `admin/api_attendance.php` | 339 | Class attendance save/summary; **duplicate `updateAttendanceSummary()`** (see Dead Code) | HIGH |
| `admin/api_attendance_info.php` | 300 | Attendance read views | MEDIUM |
| `admin/api_finance.php` | 253 | Finance txns; runtime table check (`:21`) | MEDIUM |
| `admin/api_mobile.php` | 249 | Mobile aggregation API | HIGH |
| `admin/api_material.php` | 206 | Material inventory | MEDIUM |
| `admin/api_reports.php` | 178 | Report aggregation | MEDIUM |
| `admin/api_notifications.php` | 126 | Notification feed | LOW |
| `admin/api_check_duplicate.php` | 185 | Duplicate-member check on registration | MEDIUM |
| `admin/api_list_members.php` | 71 | **Returns ALL non-archived members, no LIMIT/pagination** | **CRITICAL (scale)** |
| `admin/api_impersonate.php` | 79 | Role switching without password | HIGH (privilege) |

### Member management / workflow

| File | Lines | Purpose | Risk |
|------|------:|---------|------|
| `admin/info_manage_member.php` | 1,009 | Member edit + uploads; own `saveUploadedFile()` copy | HIGH god file |
| `admin/info_register_member.php` | 417 | Member registration + uploads | HIGH |
| `admin/backend/workflow.php` | 831 | Notifications, member-change log, cross-dept tasks, attendance summary, **academic-year helpers** | **HIGH SPOF** |
| `admin/backend/member_sync.php` | 206 | Cross-department member sync | MEDIUM |
| `admin/info_archive_member.php` | 126 | Archive a member (soft delete) | MEDIUM |
| `admin/info_restore_member.php` | 109 | Restore archived member | MEDIUM |
| `admin/info_get_archived_members.php` | 45 | List archived | MEDIUM |
| `admin/backend/calendar_system.php` | 151 | Ethiopian/Gregorian calendar mode | MEDIUM |
| `admin/backend/ethiopian_date.php` | 111 | Ethiopian date conversion | MEDIUM (SPOF for dates) |
| `admin/backend/login.php` | 146 | Auth handler (PDO, rate limit, session) | HIGH (SPOF) |
| `admin/backend/user-save/-delete/-toggle.php` | 57–240 | User CRUD | MEDIUM |

### ID cards / QR (vendored library)

| File | Lines | Purpose | Risk |
|------|------:|---------|------|
| `admin/id_cards/libs/phpqrcode/*` | ~10,000 total | Vendored QR library (+ `bindings/tcpdf/qrcode.php` 2,875 lines) | LOW (stable lib) but **duplicated under `backend/`** |
| `admin/id_cards/generate_id_card.php` | 74 | QR generation; **`mkdir(...,0777)`** (`:43`) | HIGH (perms) |
| `admin/id_cards/view_id_card.php` | 349 | ID card render; **`mkdir(...,0777)`** (`:158,173`) | HIGH (perms) |
| `admin/id_cards/qr_diagnostic.php` | 116 | Dev diagnostic tool; instructs `chmod 777` | MEDIUM (dev tool in prod) |

### REST API v1 (parallel system)

| File | Lines | Purpose | Risk |
|------|------:|---------|------|
| `api/v1/core/auth.php` | 109 | **Homemade JWT** (base64+HMAC), 30-day access / 90-day refresh; fallback secret = `md5(DB_PASS)` | HIGH |
| `api/v1/core/database.php` | 47 | PDO wrapper | MEDIUM |
| `api/v1/core/middleware.php` | 99 | Rate limit + auth guard | MEDIUM |
| `api/v1/index.php` + `routes/*` | ~1,500 | Router + attendance/grades/members/classes/dashboard/users endpoints | MEDIUM |

### PWA / frontend (parallel systems)

| File | Lines | Purpose | Risk |
|------|------:|---------|------|
| `app/js/app.js` | 961 | PWA logic | MEDIUM |
| `app/sw.js` | 99 | Service worker cache | MEDIUM |
| `frontend/js/finance.js` | 540 | New finance UI (only migrated module) | MEDIUM |
| `frontend/pages/finance_dept.php` | 332 | New finance page | MEDIUM |

### Monitoring / ops

| File | Lines | Purpose | Risk |
|------|------:|---------|------|
| `monitor/error_monitor.php` | 777 | Global error handler, DB logging, Telegram alerts; **auto-included by `config.php:865`** | **HIGH SPOF** |
| `monitor/index.php` | 293 | Monitor dashboard | MEDIUM |
| `monitor/uptime_cron.php` | 150 | Uptime pinger | LOW |
| `admin/backend/cron_backup.php` | 183 | Daily SQL backup; **builds whole dump in memory** (`:94-122`) | **HIGH (scale)** |
| `admin/system_health.php` | 556 | Health dashboard | MEDIUM |
| `leak_detector.php` / `admin/leak_detector.php` | 315 | Branding-leak scanner (dev tool, **duplicated**) | LOW |

---

## DEAD CODE LIST

Files present that are unused, superseded, or exact duplicates. These inflate the attack surface, cause "fix it in the wrong copy" bugs, and mislead future maintainers.

1. **`backend/migrations/` — entire directory is a byte-identical duplicate of `admin/migrations/`** (verified `diff -qr` returns no differences). 9 files duplicated.
2. **`admin/migrations/002_migration.php` ≡ `admin/migrations/002_add_academic_attendance_workflow.php`** — identical (587 lines each). Same for **`003_migration.php` ≡ `003_add_assessments.php`** (211 lines each). Four files, two logical migrations.
3. **`backend/id_cards/libs/phpqrcode/` — full duplicate of `admin/id_cards/libs/phpqrcode/`** (~15 source files each, ~120 KB doubled), differing only by the runtime `cache/` folder.
4. **`backend/components/notification_bell.php` ≡ `admin/components/notification_bell.php`** — identical (513 lines).
5. **`leak_detector.php` (root) ≡ `admin/leak_detector.php`** — identical (315 lines).
6. **`backend/api/*.php` (18 files)** — thin redirect shims (`require_once '../../admin/api_*.php'`). Dead-weight indirection from a stalled migration; every one is a second public URL onto the same logic.
7. **`backend/auth/login.php` (1 line), `backend/auth/logout.php` (1 line), `backend/core/ethiopian_date.php` (1 line), `backend/core/workflow.php` (1 line), `backend/users/user-save.php` (1 line), `backend/id_cards/*.php` (1 line)** — one-line include shims, unused indirection.
8. **`index.html` (root, 715 lines)** — static older landing page superseded by `index.php`; not referenced anywhere. Actively dangerous because Apache's default `DirectoryIndex` prefers `index.html`, so it can silently shadow the live dynamic homepage.
9. **`admin/dashboards/education_department.php` (57 lines)** — thin legacy stub; the live file is `edu_dept.php`.
10. **`admin/dashboards/finance_department.php` (242 lines)** — superseded: `dashboard.php:71-74` now redirects `finance_dept` to `frontend/pages/finance_dept.php`. The old dashboard is orphaned.
11. **`admin/id_cards/qr_diagnostic.php`** — developer diagnostic that prints `chmod 777` instructions; should not ship to production.
12. **`themes/wbss/`** — the non-active theme (active is `fkss`); WBSS branding assets/CSS remain and are the reason the `leak_detector` keywords list still hunts for "WBWS/Wulde Birhan."
13. **`admin/backend/error_log`** — a committed stray log file.

> Note: because these duplicates exist, a security or logic fix applied to `admin/…` will **not** protect the identical `backend/…` URL. Both are independently reachable over the web.

---

## SINGLE POINTS OF FAILURE

Ranked by blast radius — "if this breaks, what else breaks."

1. **`config.php` (869 lines).** Required by ~every PHP entry point, the public homepage, and the mobile bridge. It owns DB connections (both `$conn` MySQLi and `$pdo`), sessions, CSRF, all validation/auth helpers, rate-limiting, security headers, output buffering, **and** the runtime "auto-fix" DDL block (`:627-834`). A syntax error, a slow `ALTER TABLE`, or an exception here takes down **the entire site including the public landing page**. It also silently continues on DB failure (`:225-228` "Don't die here"), so downstream pages each re-handle a possibly-null `$conn`.
2. **The single MariaDB instance / `$conn`.** No replica, no read/write split, no connection pooling. Every dashboard, API, and the public CMS homepage hits it directly. `config.php` swallows connection errors, so an outage produces scattered blank pages and JSON errors rather than one clean failure.
3. **`monitor/error_monitor.php` (777 lines), auto-included by `config.php:865`.** The global error handler is itself loaded on every request. If it throws, writes to a full disk, or blocks on a Telegram HTTP call, it can degrade or hang **every page** — the watchdog becomes the failure.
4. **`admin/backend/workflow.php` (831 lines).** Central dependency for education, attendance, member registration/archive, and notifications. It also defines `getCurrentAcademicYear()`/`getCurrentTerm()`. If its assumptions (tables exist, `is_current=1` row present) fail, enrollment/attendance/grades all fail together.
5. **`.htaccess` (Apache-only).** All upload-execution blocking, backup/cache/migration hiding, and security headers depend on `mod_rewrite`/`mod_headers`. There is **no application-level enforcement**. Move to Nginx, lose `AllowOverride`, or drop a module → uploads become executable and backups/config become downloadable instantly.
6. **`school_config.php`.** One `require` failure or typo blanks branding, URLs, CORS origins, and feature flags across admin, API, PWA, and public site simultaneously.
7. **`academic_years` "current year" row (`is_current=1`).** A soft SPOF in data: dozens of queries assume exactly one current year exists (`api_education.php:32`, `api_attendance.php:31`, `api_mobile.php`, `workflow.php:795`, etc.). Zero or two current-year rows silently corrupts enrollment/attendance scoping (see Year-to-Year section).

**Circular / tangled dependencies:** `config.php` → `school_config.php` → (constants) and `config.php` → `monitor/error_monitor.php` → (uses `$conn` created later in `config.php`). `index.php` (public) → `admin/config.php` → root `config.php` (public site depends on the admin bootstrap). `workflow.php` self-loads `config.php` if constants missing (`:19-21`) while `config.php`-loaded pages also load `workflow.php` — mutually re-entrant guards rather than a clean dependency graph. No hard fatal cycle, but the load order is fragile and undocumented.

---

## PERMISSION VIOLATIONS

> The git checkout normalizes all modes to 755/644 (git stores only the exec bit), so these findings are drawn from the **code that sets permissions at runtime** and from **`.htaccess` exposure**, which is what production will actually exhibit.

1. **`mkdir(..., 0777)` for QR/ID-card directories — world-writable:**
   - `admin/id_cards/generate_id_card.php:43` → `mkdir($qr_dir, 0777, true)`
   - `admin/id_cards/view_id_card.php:158` and `:173` → `mkdir($qr_dir, 0777, true)`
   - `admin/id_cards/qr_diagnostic.php:38,99` → same, plus prints "run: `chmod 777 admin/id_cards/assets/qr/`"
   `0777` makes the QR asset directory writable by any user/process on the host. Combined with the fact that this directory is under the web root and **not** covered by the uploads `php_flag engine off` rule, it is the single most dangerous permission in the codebase.
2. **`admin/api_branding.php:326` and `qr_diagnostic.php:31,41`** instruct operators to `chmod 777` asset folders on failure — normalizing world-writable perms as a "fix."
3. **`0775` upload dirs** at `info_register_member.php:244`, `info_manage_member.php:61`, `api_cms.php:75` — group-writable upload roots. Lower risk than 0777 but still broader than needed.
4. **Backups written under the web root:** `admin/uploads/backups/` (`cron_backup.php:58`). Protection is **only** `.htaccess` (`admin/uploads/.htaccess` + root `.htaccess` `RewriteRule ^admin/uploads/backups/ - [F,L]`). These SQL dumps contain **`users.password_hash` and full member PII**. If `.htaccess` is not honored, every daily backup is publicly downloadable at a guessable path (`auto_backup_YYYY-MM-DD_HH-MM-SS.sql`).
5. **A real production backup is committed to the repository:** `admin/uploads/backups/backup_2026-03-01_13-08-51.sql` (82 KB, 1,038 lines) contains live member rows — real names, phone numbers, home addresses, guardian details — plus the `users` table structure. This is **PII checked into version control**, readable by anyone with repo access, and shipped in every clone/deploy.
6. **Rate-limit cache is under the web root:** `admin/uploads/cache/*.json` — protected only by `.htaccess` (`Deny from all`). Contains per-IP login-attempt data. Fine if Apache holds, exposed if not.
7. **Publicly reachable files that should not be:**
   - `admin/migrations/*.php` — web-runnable schema mutators. Blocked by root `.htaccess:36` (`RewriteRule ^admin/migrations/ - [F,L]`) — but see the contradiction under Database Risks (this also blocks the documented way to run them).
   - `admin/backend/cron_backup.php` — reachable in-browser with the `?key=` secret (`:39`); the whole DB dump is one correct/guessed key away, over HTTP.
   - `admin/id_cards/qr_diagnostic.php` — a diagnostic that reveals paths and writability, with no auth gate at the top.
   - `monitor/error_monitor.php` — sensitive, but `monitor/.htaccess` denies it directly (good) — again Apache-dependent.
8. **Uploads execution defense is `.htaccess`-only.** `admin/uploads/.htaccess` and `uploads/.htaccess` disable PHP via `FilesMatch`/`php_flag engine off`. There is no server-level `php_admin_flag`, and no check that the web root disallows `.php` under uploads. PDFs are accepted by extension only (no content verification), and any bypass of the Apache rule (Nginx, module missing, case/encoding tricks) restores executable uploads.

---

## DATABASE RISKS

Schema reconstructed from the committed production backup (`admin/uploads/backups/backup_2026-03-01_13-08-51.sql`, 33 tables), the documented `database_schema.sql` (only 4 tables — badly out of date), the runtime auto-fix in `config.php`, and `admin/migrations/005_optimize_database.php`.

### Missing indexes on high-traffic columns (confirmed against production dump)

- **`members` table has only `PRIMARY KEY(id)` and `UNIQUE(member_code)` in production** (backup lines 806-807). It has **no index on `status`**, yet the hottest query in the app — `api_list_members.php:56` `WHERE status != 'archived'` — and dozens of dashboards filter on `status`. At 5,000 rows every member list is a **full table scan**.
- No index on `members.current_class_id`, `members.gender`, `members.age_group`, `members.registration_type` — all used as filters in `api_education.php` and `info-dept.php`.
- **The fix exists but was never applied.** `admin/migrations/005_optimize_database.php:86-111` adds exactly these indexes (`idx_members_status`, `_gender`, `_age_group`, composite `status,gender`, etc.). The production dump proves they are absent → the optimization migration has **not been run**.
- **The optimization migration is effectively unrunnable via its own instructions.** Its header (`:11-12`) says "visit directly URL: `/admin/migrations/005_optimize_database.php`," but root `.htaccess:36` does `RewriteRule ^admin/migrations/ - [F,L]` — that exact URL returns 403. So the documented path to add indexes is blocked, which is likely *why* production has none.
- `config.php`'s auto-fix block (`:627-834`) only **adds columns and creates tables** — it never adds the performance indexes. So no normal request path ever creates them.

### Tables with no foreign keys that should have them

- **`teacher_assignments`** (backup `:881-897`) has plain `KEY`s on `teacher_id`, `class_id`, `subject_id`, `academic_year_id` but **no `CONSTRAINT`/FK**. `api_teachers.php:82-85` actively **drops** any FK found on `academic_year_id`. Deleting a class or year leaves orphan assignments.
- **`finance_transactions`, `finance_member_fees`, `finance_budgets`, `finance_categories`** (`:511-588`) — indexed by member/category but **no FKs** (they are `utf8mb4_general_ci` MyISAM-style additions). Deleting a member or category orphans money records — a reconciliation hazard for a finance module.
- **`material_items`, `material_requests`, `material_transactions`** (`:636-693`) — `category_id`/`item_id` are plain keys, no FK. Deleting a category/item orphans inventory history.
- **`grade_submissions`, `assessments` vs `academic_records`** — `academic_records` (`:4-28`) has **six `KEY`s but zero FKs**, while the newer `assessments` table (`:226-229`) *does* have FKs. Integrity enforcement is inconsistent table-to-table.
- **`notifications.source_user_id` / `target_user_id`**, **`department_tasks.to_user_id`/`from_user_id`**, **`activity_logs.user_id`** — all reference users with no FK; deleting a user leaves dangling references throughout the audit/notification trail.

Where FKs *do* exist they are good (`attendance`, `class_enrollments`, `member_changes`, `wbws_group_leaders` all cascade correctly) — the problem is **inconsistency**: integrity is enforced on some tables and not on the financially/academically important ones.

### Mixed collations / engines

Tables are split between `utf8mb4_unicode_ci` (core) and `utf8mb4_general_ci` (groups, finance, materials, activity_logs). JOINs across the boundary (e.g. finance ↔ members) can hit "illegal mix of collations" errors and cannot use indexes efficiently.

### Tables that will grow unbounded with no archiving strategy

- **`attendance`** — one row per member per session. 5,000 members × ~50 Sundays/yr = **~250,000 rows/year**, growing forever. No partitioning, no archival, and (see above) no `status` index on the member side of the join. This is the table that will make attendance screens crawl first.
- **`activity_logs`** — every login and action; already 116 rows in a ~15-member test system (`AUTO_INCREMENT=117`). At 5,000 members this grows without bound and is **never pruned** (nothing deletes from it; only the backup `.sql` files are rotated).
- **`notifications`** — one+ row per member event, `is_read` flips but rows are never deleted. Unbounded.
- **`member_changes`** — audit of every field change per member. Unbounded.
- **`wbws_audit_log`**, **`academic_records`**, **`attendance_summary`** — all monotonic growth, no retention policy.

No table has a documented archive/rollup/partition plan. The only "cleanup" anywhere is `cron_backup.php` deleting `.sql` files older than 7 days — that trims backups, not data.

### Runtime DDL (schema mutation on live requests) — the structural database risk

Schema is created and altered **inside request handlers**, not by a migration runner:
- `config.php:627-834` runs `CREATE TABLE`/`ALTER TABLE`/seed on the first request of every session (guarded by `$_SESSION['_db_checked']`).
- `api_education.php:42-95`, `api_teachers.php:56-101`, `groups_api.php:144-257`, `api_finance.php:21`, `calendar_system.php:36`, `workflow.php:26-58` each issue `ALTER`/`CREATE` at request time.
- ~**101 unassigned `$conn->query()` calls** (many DDL) run with no result check.

Consequences: (a) `ALTER TABLE` on a 250k-row `attendance` table locks it mid-request and can time out a user action; (b) two concurrent users can race the same `ALTER`; (c) the app DB user must hold `ALTER`/`CREATE` rights in production (privilege over-grant); (d) the "real" schema is defined by scattered PHP, not `database_schema.sql`, which documents only 4 of 33 tables and is **months stale**.

---

## YEAR-TO-YEAR TRANSITION ASSESSMENT

**Does the system have a concept of academic year?** Partially. There is an `academic_years` table with `is_current TINYINT` and `academic_terms` (semesters) with cascade FKs. `class_enrollments`, `attendance`, `attendance_summary`, `assessments`, and `academic_records` all carry `academic_year_id`. So the *data model* is year-aware.

**How is rollover handled? — It is NOT.** There is **no year-rollover routine anywhere in the codebase.** Searching the whole tree, the only year operations are:
- `save_academic_year` / `set_current_year` (`api_education.php:718-808`) — an admin manually creates a new year row and flips `is_current`. Flipping is a bare `UPDATE academic_years SET is_current=0` then set one to 1 (`:802-805`), **not transactional** — a crash between the two statements leaves **zero** current years, silently breaking every enrollment/attendance query that does `WHERE is_current=1 LIMIT 1`.
- `promote` (`api_education.php:466-505`) — promotes **one student at a time** from class A to class B. There is no bulk "advance everyone one grade," no "graduate the top class," no "carry forward / reset enrollments for the new year."

**What happens at a real year boundary (the gap):**
1. Admin creates AY 2018 EC and marks it current. **Nothing is carried forward.** Every student's `class_enrollments` row still points at the *old* year, and `status='active'`. The new year has **zero enrollments** until someone manually re-enrolls or promotes all ~5,000 students **one by one** through the UI. Until then, attendance/grade screens for the new year show empty classes.
2. `members.current_class_id`, `members.promoted_at`, `members.academic_status` are **denormalized duplicates** of enrollment state, updated only inside `promote`/`autoUpdateMemberClass` (`workflow.php:582-610`). After a manual year flip they are stale and can disagree with `class_enrollments` — two sources of truth for "what class is this student in this year," with nothing reconciling them.
3. **Graduated / top-of-school members:** `academic_status` has a `graduated` enum value and the info-dept UI exposes it (`info-dept.php:4258`), but **no code ever sets it automatically.** There is no graduation flow. Students who finish the highest class simply remain `active` with a stale `current_class_id`, inflating every active-member count year over year.
4. **Archived members:** archival is a manual soft-delete (`info_archive_member.php`) setting `status='archived'`. It is unrelated to years — archived members are excluded globally (`status != 'archived'`), so historical archived students never "belong" to a year and drop out of all year-scoped reporting.
5. **Attendance summary month/year mismatch (latent corruption):** `workflow.php:updateAttendanceSummary()` writes the summary keyed on **Ethiopian** month/year (`:620-621`), while `api_attendance.php:updateAttendanceSummary()` (a *different* function, same name) writes the **Gregorian** month/year (`:284-285`) into the *same* `attendance_summary` table whose unique key is `(member_id, academic_year_id, month, year)`. Depending on which path runs, the same month is stored under EC (e.g. year 2018) or GC (2026), so rows collide or double-count. This will quietly scramble multi-year attendance history.

**Data orphaned/corrupted when a new year starts:** enrollments stranded on the prior year; `members.current_class_id`/`promoted_at`/`academic_status` left stale; teacher_assignments (no FK) pointing at prior-year context; attendance_summary rows keyed inconsistently EC vs GC. **Net: the system has the *columns* for multi-year operation but none of the *transition logic*, and the very first September rollover with real students is an unscripted, manual, non-transactional event.**

---

## SILENT KILLERS FOUND

Features that appear to work at 15 members and fail quietly at scale, plus suppressed/unhandled failures.

1. **Unpaginated full-member load (works for 15, dies for 5,000).** `api_list_members.php:27-57` selects ~26 columns for **every** non-archived member with **no `LIMIT`, no pagination**, `ORDER BY id DESC`. The front-end `info-dept.php:2039-2065` then builds a DOM `<tr>` for **every** returned row in a synchronous `forEach` with `innerHTML` string concatenation. At 5,000 members this is a multi-megabyte JSON payload and 5,000 synchronous DOM insertions → multi-second tab freeze, then browser jank on every search keystroke (search filters the in-memory array client-side). This is the #1 thing that breaks at scale, and it fails **silently** (no error — just an unusably slow page). Compounded by the missing `members.status` index making the query itself a full scan.
2. **In-memory database backup will silently OOM.** `cron_backup.php:94-122` concatenates the **entire** database (all tables, all rows) into one `$sql` PHP string, then `file_put_contents` once. `attendance` alone reaches hundreds of thousands of rows; the string exceeds PHP `memory_limit` and the process dies. The failure is caught (`:142-145`) and merely `error_log`'d — the cron "runs," reports partial output, and **you have no backup precisely when the DB is large enough to matter.**
3. **Non-transactional "current year" flip.** `api_education.php:802-805` and `:734` do `UPDATE academic_years SET is_current=0` then set one row to 1, outside a transaction. A failure between them yields **zero** current years; every `WHERE is_current=1 LIMIT 1` (enrollment, attendance, grades, mobile API) then silently returns nothing and the app behaves as if no year exists — no error surfaced.
4. **Two different `updateAttendanceSummary()` functions writing incompatible keys** (EC vs GC month/year) into the same uniquely-keyed table — see Year-to-Year #5. Silent multi-year data corruption.
5. **Error suppression `@` hiding real failures.** 14 × `@mkdir`, 11 × `@unlink`, 3 × `@getimagesize`, 2 × `@file_put_contents`, 2 × `@file_get_contents`, plus `@chmod`, `@scandir`, `@disk_free_space`. Notably `config.php:477,500` `@mkdir` the cache dir and `login.php:44` `@mkdir` — if the uploads/cache dir is unwritable, rate-limiting and login-attempt tracking **silently no-op** (brute-force protection off, no signal). `@file_put_contents` on rate files means a full disk disables limiting invisibly.
6. **~101 unchecked `$conn->query()` calls** (grep of `admin/ api/ config.php`). Many are DDL, but read queries like `groups_api.php:98` `return $conn->query($sql)` return `false` on failure and callers iterate a boolean. In MySQLi non-exception contexts these fail to `null`/`false` and produce empty lists rather than errors — data silently "disappears" from the UI.
7. **Session timeout mid-operation loses work.** `config.php:107-170` hard-enforces a 30-minute idle timeout and, for AJAX, returns `{status:'session_expired'}` with HTTP 401. A user filling the long member-registration or member-edit form past 30 minutes submits into a destroyed session: CSRF token no longer matches, the POST is rejected, and **all typed data is lost** with only a "please refresh" toast. There is no draft-save. For a data-entry-heavy workflow with 5,000 registrations this will bite constantly.
8. **Public homepage coupled to admin bootstrap + live DB.** `index.php:1-26` loads `admin/config.php` (→ full `config.php`, including the per-session DDL auto-fix) and issues 6 live CMS queries on every public hit. A slow `ALTER TABLE` or DB hiccup degrades the **public** site, and the DDL auto-fix can fire from an anonymous visitor's session.
9. **`0777` QR directories created on demand** (`view_id_card.php:158,173`) — every ID-card view can (re)create a world-writable directory under the web root; a race or a stray uploaded file there is executable unless the uploads `.htaccess` happens to cover it (it does not cover `admin/id_cards/`).
10. **Duplicate-fix divergence.** Because `backend/` mirrors `admin/` byte-for-byte, a silent-killer fixed in one copy remains live in the other reachable URL. Security patches will "not take" from the attacker's perspective.
11. **Homemade JWT, 30/90-day expiry, weak fallback secret.** `api/v1/core/auth.php:7` derives the token secret from `md5(DB_PASS)` if `JWT_SECRET` is unset (and `config.php:82-85` generates a *random per-request* JWT secret when the env file is missing — meaning **every issued mobile token is invalidated on the next request** if the env file ever fails to load). Mobile logins would then flap for reasons no log explains.

---

## TOP 10 RISKS
### (ranked by severity — what breaks first as usage approaches 5,000 students)

**1. Unpaginated member list + missing `members.status` index → the member screen becomes unusable.**
`api_list_members.php` returns all members with no `LIMIT`; `info-dept.php` renders every row synchronously; the DB has no `status` index (production dump confirms only PK + `member_code`). This is the **first** wall you hit — likely between 500 and 1,500 members — and it presents as a silent freeze, not an error. *(Files: `admin/api_list_members.php:27-57`, `admin/dashboards/info-dept.php:2008-2065`, backup schema `members` `:806-807`.)*

**2. No year-rollover logic → the first academic-year transition is a manual, non-transactional catastrophe.**
No bulk promote/graduate/carry-forward exists; the year flip is a two-statement non-atomic `UPDATE` that can leave zero current years; `current_class_id`/`academic_status` desync from `class_enrollments`; two `updateAttendanceSummary()` write EC vs GC keys into one table. The system has year *columns* but no year *transition*. *(Files: `admin/api_education.php:466-505,718-808`, `admin/backend/workflow.php:582-621`, `admin/api_attendance.php:278-337`.)*

**3. Backups silently fail at scale, while a real PII backup sits in the git repo.**
`cron_backup.php` builds the whole dump in memory (will OOM once `attendance` is large) and only `error_log`s the failure — you lose backups exactly when data is biggest. Meanwhile `admin/uploads/backups/backup_2026-03-01_...sql` (real names/phones/addresses) is committed to version control, and live backups sit web-served behind `.htaccess` only. *(Files: `admin/backend/cron_backup.php:94-145`, `admin/uploads/backups/…`.)*

**4. `config.php` is an all-or-nothing SPOF that also mutates schema on live requests.**
869 lines owning DB/session/CSRF/auth/rate-limit/security-headers/DDL, required by everything including the public site. Its per-session `ALTER TABLE` block can lock large tables mid-request and requires production `ALTER`/`CREATE` privileges. Any fault here is a total outage. *(File: `config.php:1-868`, esp. `:627-834`.)*

**5. Security rests entirely on `.htaccess` — uploads execution, backup/config hiding, headers.**
No server-level or application-level enforcement. Uploaded PHP is blocked only by `FilesMatch`/`php_flag`; backups/cache/migrations are hidden only by rewrite rules. A move to Nginx, a lost module, or `AllowOverride None` instantly makes uploads executable and DB backups (with `password_hash` + PII) downloadable. *(Files: root `.htaccess`, `admin/uploads/.htaccess`, `admin/.htaccess`.)*

**6. Runtime DDL + 101 unchecked queries → concurrency locks, races, and silent data loss.**
Schema is defined by scattered `CREATE/ALTER` in request handlers guarded only by session flags; ~101 `$conn->query()` results are never checked. At multi-user scale these race and lock; failed reads return empty lists rather than errors, so records "vanish" from the UI with no trace. *(Files: `admin/api_education.php:42-95`, `admin/api_teachers.php:56-101`, `admin/backend/groups_api.php:144-257`.)*

**7. World-writable `0777` directories under the web root (ID-card/QR).**
`generate_id_card.php:43`, `view_id_card.php:158,173`, `qr_diagnostic.php` create/chmod QR dirs to `0777`, in a path **not** covered by the uploads no-exec `.htaccess`. Highest-severity permission in the tree; a writable+executable web dir is a direct RCE primitive if any write path is reachable. *(Files: `admin/id_cards/*`.)*

**8. Unbounded tables with no archiving (`attendance`, `activity_logs`, `notifications`, `member_changes`).**
~250k attendance rows/year and ever-growing logs, no partitioning, no retention, no rollup. Reports and attendance screens degrade continuously; there is no plan to ever trim them. *(Backup schema: `attendance`, `activity_logs`, `notifications`, `member_changes`.)*

**9. Five overlapping, half-migrated layers with byte-identical duplicates.**
`admin/` vs `backend/` (exact dup) vs `api/v1/` vs `app/` vs `frontend/`, plus duplicated migrations, QR lib, notification bell, and leak detector. Every fix must be applied N times or it silently doesn't take on the other live URL; maintenance cost and bug-reintroduction risk compound over the multi-year horizon. *(Dirs: `backend/`, `admin/migrations/` dup pairs, `backend/id_cards/libs/`.)*

**10. Missing FKs on finance/teacher/material tables + non-transactional multi-row writes → integrity drift.**
`teacher_assignments`, `finance_*`, `material_*`, `academic_records` lack FKs (and `api_teachers.php:82-85` actively drops one); deletions orphan money, inventory, and grade records. Combined with the non-transactional year flip and mixed collations, financial and academic history will drift out of consistency over years with no database-level guardrail. *(Backup schema `:511-897`; `admin/api_teachers.php:82-90`.)*

---

### Cross-cutting notes for Phase 2 (not fixes, just flags)
- **PII in version control** and **hardcoded fallback DB creds/JWT** in `config.php:51-55` warrant a secrets/exposure work-stream of their own.
- `database_schema.sql` documents 4 of 33 tables and is the *wrong* source of truth — the real schema lives in `config.php` + migrations + `api_*` runtime DDL.
- `index.html` shadowing `index.php` (Apache `DirectoryIndex` default) is a quiet correctness bug worth confirming on the live host.
- Homemade JWT with a per-request-random fallback secret (`config.php:82-85` × `api/v1/core/auth.php:7`) can flap mobile sessions with no diagnostic trail.
