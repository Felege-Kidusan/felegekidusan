# SSMS — Complete System Knowledge Report

**Repository:** `suraman21/SSMS` (cloned at `/home/user/SSMS`)
**Analysis basis:** source code in the cloned repository (no runtime/database available in this environment).
**Protocol followed:** Master Codebase Understanding Protocol (attached PDF). Analysis phase only — no files modified.
**Today (reference):** 2026-10-16.

> Confidence legend used throughout: **VERIFIED** (directly demonstrated by source), **HIGH CONFIDENCE**, **INFERENCE**, **UNKNOWN**. Assumptions are never presented as facts.

---

## A. Executive System Summary

SSMS is a **Sunday School Management System** ("Felege Kidusan" / FKSS deployment) for the Ethiopian Orthodox Tewahdo Church parish "Bole Bulbula St. Mary & St. John Holy Church". It manages members/students, attendance, education (classes, subjects, assessments, grades, report cards), finance, material, HR attendance, groups, communication/messaging, notifications, CMS (public gallery), ID cards, Mezmur (hymn) library with audio, and a mobile Flutter client with offline sync.

It is a **multi-surface PHP web application** plus a **Flutter mobile app**:

- Web admin dashboards (`admin/`, `frontend/`) served by PHP 8.x on Apache (MariaDB 10.6).
- REST API v1 (`api/v1/`) for the mobile app and API clients (JWT access + rotating refresh tokens).
- Backend API helpers (`backend/api/`) used by web dashboards via fetch.
- Mobile app (`Mobile/wbws_flutter_app/`) Flutter 3.27+, Dart 3.3+, with SQLite offline cache and background sync.

The system is heavily feature-gated (`FeatureGate`) and supports per-deployment toggles (AI, groups, finance, material, mezmur, id_cards, attendance, grades, reports, export_pdf, monitor). Default all enabled.

**Verified from:** `school_config.php` (branding, feature toggles), `config.php`, `admin/backend/services/FeatureGate.php`, `Mobile/wbws_flutter_app/pubspec.yaml`, `database_schema.sql`, git log.

---

## B. Technology Stack (verified from source)

| Layer | Technology | Evidence |
|---|---|---|
| Server language | PHP 8.4 (production banner MariaDB 10.6) | `.github/workflows/backend-checks.yml`, `sql` comments |
| Web server | Apache (`.htaccess`, mod_rewrite) | `.htaccess` |
| Database | MySQL/MariaDB (InnoDB, utf8mb4) | `config.php` (mysqli + PDO), `database_schema.sql` |
| ORM/DB access | Raw mysqli + PDO (no ORM) | `config.php` |
| Frontend (web) | Vanilla JS + PHP-rendered HTML, CSS | `frontend/js/*`, `admin/js`, `index.php` |
| Mobile | Flutter/Dart (provider, sqflite, flutter_secure_storage, http) | `pubspec.yaml` |
| PDF | TCPDF (vendored) | `admin/backend/pdf/tcpdf/` |
| Spreadsheet | PhpSpreadsheet ^5.9 | `composer.json`, `vendor/phpoffice/` |
| QR | phpqrcode (vendored) | `admin/id_cards/libs/phpqrcode/` |
| Auth (web) | PHP sessions (hardened), CSRF tokens, rate limiter | `config.php`, `admin/backend/login.php`, `SecurityRateLimiter.php` |
| Auth (API) | Custom JWT (HMAC-SHA256) + rotating refresh sessions | `api/v1/core/auth.php` |
| Date system | Ethiopian calendar helpers | `admin/backend/ethiopian_date.php`, `backend/core/ethiopian_date.php` |
| CI | GitHub Actions (php -l + 129 Python security tests + MariaDB service) | `.github/workflows/backend-checks.yml` |
| Tests | Python (pytest-style) security/regression, JS node tests, PHP smoke, e2e PHP | `tests/` |

**UNKNOWN:** exact PHP 8.4 runtime availability in this sandbox (php not installed here) — not needed for analysis.

---

## C. Repository Architecture (top-level)

```
SSMS/
├── config.php, school_config.php, branding_defaults.php   # root config + branding
├── index.php, member.php, register_submit.php, public_gallery.php  # public web entry
├── .htaccess, composer.json, env.example.php
├── admin/        # admin web app: dashboards, api_*.php, backend services, migrations
├── backend/      # shared backend: api/, auth/, core/, config/, id_cards, migrations, users
├── api/v1/       # REST API for mobile: core/, routes/, tests/
├── frontend/     # dept-specific frontend pages (dashboard, edu, finance, mezmur)
├── app/          # PWA-ish app shell (index.html, manifest, sw.js)
├── Mobile/       # Flutter app
├── monitor/      # error monitor + uptime cron
├── themes/       # fkss, wbss themes (css, logos, seals)
├── sql/          # 67 numbered migrations + preflight
├── tests/        # security (129 py), smoke (6 php), e2e, js, mutation, audit, fixtures
├── docs/         # many phase/audit docs
├── uploads/      # user uploads
├── vendor/       # composer (phpspreadsheet, etc.) — third-party, excluded from analysis
├── cgi-bin/, tools/, scripts/
```

**Verified from:** `find` inventory, `.git`.

---

## D. Application Architecture

Layered flow (verified from code):

```
Browser/Mobile Client
   ↓
Entry (index.php / admin/*.php / api/v1/index.php / backend/api/*.php)
   ↓
config.php (env secrets, session, DB connections $conn mysqli + $pdo)
   ↓
Middleware/ACL (AdminSessionGuard, apiRequireAuth, FeatureGate, CSRF, rate limiter)
   ↓
Controller/Route (admin/api_*.php, backend/api/*.php, api/v1/routes/*.php)
   ↓
Service layer (admin/backend/services/*.php, ~67 classes under App\Services)
   ↓
Database (mysqli/PDO) / External (R2, AI providers, email/SMS unknown)
   ↓
Response (HTML/JSON) → UI update
```

Two parallel API surfaces:
1. **Web dashboard AJAX**: `backend/api/*.php` + `admin/api_*.php` (session-based, CSRF).
2. **Mobile REST v1**: `api/v1/index.php` router → `api/v1/routes/*.php` (JWT).

**Verified from:** `config.php`, `api/v1/index.php`, `admin/backend/login.php`, `backend/api/init.php`.

---

## E. Module-by-Module Explanation

Major modules (verified by directory + file names + services):

1. **Core/Auth**: session login, CSRF, rate limiting, admin session guard, JWT API auth, refresh token rotation, authorization scope revalidation. Files: `config.php`, `admin/backend/login.php`, `admin/backend/services/AdminSessionGuard.php`, `SecurityRateLimiter.php`, `RefreshTokenService.php`, `api/v1/core/auth.php`, `acl.php`, `middleware.php`.
2. **Members**: registration, duplicate detection, code generation, categories, types, age, files, export/import, directory, reports. Services: `Member*Service.php`, `MemberRegistrationPolicy.php`, `PasswordPolicy.php`, `MemberAge.php`, `MemberCategory.php`, `MemberCodeFormat.php`, `MemberFileService.php`, `MemberExportService.php`, `MemberDirectoryService.php`, `MemberDuplicateService.php`, `MemberReportService.php`, `MemberReportRenderer.php`.
3. **Attendance**: records, summary, rollup, QR roster, attendance taker, info attendance. Services: `AttendanceRecordService.php`, `AttendanceSummaryService.php`. API: `backend/api/attendance.php`, `attendance_info.php`, `admin/api_attendance*.php`, `api/v1/routes/attendance.php`.
4. **Education/Academic**: classes, subjects, enrollment, assignments, assessments, grades, report cards, academic tracking, academic intelligence, analytics, timetable, academic years/terms. Services: `AcademicTrackingService.php`, `AcademicIntelligenceService.php`, `AssessmentTypeService.php`, `AssignmentService.php`, `EnrollmentService.php`, `EducationAnalyticsService.php`, `ReportCardService.php`, `SubjectDurationPolicy.php`, `TimetableService.php`.
5. **Finance**: transactions, budgets, categories, member fees, ledger validation. Services: `LedgerValidation.php`, `LedgerInputException.php`. API: `backend/api/finance.php`, `admin/api_finance.php`.
6. **Material**: categories, items, requests, transactions. API: `backend/api/material.php`, `admin/api_material.php`.
7. **HR**: attendance, submissions. Services: `HrAttendanceService.php`, `HrSubmissionService.php`. API: `backend/api/*` (hr via api/v1/routes/hr.php), `admin/api_hr_*`.
8. **Groups**: group management, leaders, members. API: `backend/api/groups.php`, `admin/backend/groups_api.php`.
9. **Communication/Messaging**: threads, messages, participants, read receipts, edit/delete, polling. Docs: `COMMUNICATION_UX_OVERHAUL.md`. Services: `SubmissionService.php`? (comm). API: `backend/api/communication.php`, `admin/api_communication.php`, `api/v1/routes/*` (notifications).
10. **Notifications**: center, reads, bell. Services: `NotificationCenterService.php`. API: `backend/api/notifications.php`, `admin/api_notifications.php`, `api/v1/routes/notifications.php`.
11. **Mezmur (Hymns)**: categories, hymns, words, zemarians, attendance, submissions, media (R2), art, analytics. Services: `Mezmur*Service.php` (6). API: `backend/api/mezmur.php`, `admin/api_mezmur.php`, `api/v1/routes/mezmur.php`.
12. **CMS/Public website**: gallery categories/photos, programs, schedule, social links, teachers. API: `admin/api_cms.php`. Dir: `public_website_frontend/`.
13. **ID Cards**: generation, templates, QR. Dir: `admin/id_cards/`, `backend/id_cards/`.
14. **AI**: providers, chatbot, assistant. Services: `ai_providers.php` (backend). API: `backend/api/ai.php`, `admin/api_ai.php`, dashboards `ai_assistant.php`, `ai_chatbot_widget.php`.
15. **App release/telemetry**: app downloads, installations, crash signatures, telemetry events. Services: `AppReleaseManager.php`, `AppTelemetryService.php`. API: `admin/api_app_release.php`, `admin/api_telemetry.php`, `api/v1/routes/app.php`, `telemetry.php`.
16. **Failure intelligence**: alerts, issues, remediation, reports. Services: `FailureAlertService.php`, `FailureIssueService.php`. API: `admin/api_failure_intelligence.php`.
17. **Sync (mobile offline)**: change feed, sync attempts, instance epoch. Services: `SyncChangeFeedService.php`, `SyncInstanceService.php`, `ApiSyncAttemptMonitorService.php`, `ApiSyncAttemptAdminService.php`, `ApiIdempotencyService.php`. API: `api/v1/routes/sync.php`.
18. **Backup/monitor**: backup service, health check, monitor dashboard, uptime cron. Services: `BackupService.php`. Files: `admin/tools/backup.php`, `monitor/`.
19. **Mobile Flutter app**: screens for all modules, services (api, sync, outbox, local db, etc.).
20. **Identity migration**: `IdentityCodeService.php`, `IdentityMigrationService.php`, `admin/api_identity*.php`, `admin/tools/migrate_identity_codes.php`.

---

## F. File-Level Map (important files)

- `config.php` — root bootstrap: env loading, branding, session, CSRF helpers, DB connections, security headers.
- `school_config.php` — school branding + feature toggles defaults.
- `branding_defaults.php` — fail-soft fallbacks.
- `index.php` (763 lines) — public landing/login entry.
- `member.php` — public member view.
- `admin/index.php`, `admin/dashboard.php`, `admin/logout.php` — admin entry.
- `admin/config.php` — admin config (likely includes root).
- `backend/api/init.php` — dashboard bootstrap JSON.
- `backend/auth/login.php`, `logout.php` — auth endpoints.
- `backend/core/browser.php` — session hardening, HTTPS detection, URL helpers.
- `backend/core/ethiopian_date.php`, `admin/backend/ethiopian_date.php` — Ethiopian calendar.
- `backend/core/workflow.php` → `admin/backend/workflow.php` — notifications/tasks.
- `backend/config/loader.php` — finds root config.php by walking up.
- `api/v1/index.php` — REST router.
- `api/v1/core/{acl,auth,database,middleware,response}.php` — API core.
- `admin/backend/services/*.php` — 67 service classes under `App\Services`.
- `admin/backend/services/FeatureGate.php` — feature toggles.
- `database_schema.sql` — partial schema doc (only users, members, wbws_groups, wbws_group_leaders, cache_storage). **Most tables defined via migrations/auto_fix.**
- `sql/001..067_*.sql` — incremental migrations.
- `admin/migrations/*`, `backend/migrations/*` — PHP migration runners.
- `admin/backend/auto_fix_database.php` — auto schema repair.
- `tests/security/*` — 129 Python tests.
- `ENTERPRISE_SECURITY_AUDIT_REPORT.md` — prior audit.

---

## G. Function/Class/Component Map (key symbols)

- `config.php`: `sanitize()`, `e()`, `esc()`, `generateCsrfToken()`, `csrfField()`, `validateCsrf()` (likely), `_isAjaxRequest()`, `_invalidateAdminSession()`, `_isPrivilegedBrowserArea()`.
- `FeatureGate` (class): `isEnabled()`, `all()`, `mobileCapabilities()`, `forAdminRequest()`, `forApiResource()`, `forRoleDashboard()`, `filterMobileTiles()`.
- `AdminSessionGuard` (class): `revalidate()` — reconciles session claims with DB account.
- `SecurityRateLimiter` (class): `consume()`, `clear()`.
- API auth functions: `createToken()`, `createRefreshToken()`, `verifyToken()`, `verifyTokenState()`, `getTokenFromRequest()`, `apiRequireAuth()`, `apiRequireRole()`, `apiRevalidateAuthorizationScope()`, `apiAuthorizationScopeEnforcedForClient()`.
- `sendNotification()` (workflow.php) — notification dispatcher.
- Mobile: `ApiService`, `SessionService`, `LocalDb`, `SyncExecution`, `OutboxPolicy`, `CommOutboxService`, etc.

**UNKNOWN:** exact method signatures of all 67 services not individually traced (would require reading each file). Marked as needing per-file analysis if a change is requested.

---

## H. Database Architecture

- **Engine:** MariaDB/MySQL, InnoDB, utf8mb4_unicode_ci.
- **Access:** mysqli (`$conn`) + PDO (`$pdo`), both created in `config.php`.
- **Tables:** ~105 (enumerated via CREATE TABLE scan). Full list in analysis notes; includes members, users, attendance (+archive/rollup/summary), academic_records (+archive), academic_terms/years, assessments, assessment_types, classes, class_enrollments, class_subjects, subjects, teacher_assignments, timetable_*, grade_submissions, finance_*, material_*, hr_*, mezmur_* (~20 tables), cms_*, notifications, message_threads/messages/participants, api_idempotency*, api_refresh_*, api_sync_attempts, sync_changes/sync_feed_state, app_* telemetry, failure_*, member_changes, member_code_*, member_staff_positions, member_type_settings, staff_positions, departments, dept_settings, department_tasks, announcements, shared_documents, security_rate_limits, system_settings/branding, wbws_groups/leaders/members, wbws_audit_log, activity_logs (+archive), cache_storage, migration_* conflict tables.
- **Schema management:** `database_schema.sql` (partial doc), `sql/001..067_*.sql` migrations, `admin/migrations/*` PHP runners, `backend/migrations/*`, `auto_fix_database.php`, `get_schema.php`.
- **Ethiopian dates:** stored as `dob_ec_day/month/year` plus gregorian `date_of_birth`.
- **Soft delete/archive:** `status='archived'`, `*_archive` tables, `info_archive_member.php`.
- **Audit fields:** `activity_logs`, `hr_attendance_audit`, `mezmur_attendance_audit`, `wbws_audit_log`.

**UNKNOWN:** full column-level schema for most tables (only `database_schema.sql` partial + migrations). For a change, inspect relevant migration + auto_fix.

---

## I. API Map

### Web dashboard AJAX (session/CSRF)
- `backend/api/*.php`: init, members, members_check, attendance, attendance_info, education, subjects, teachers, assignments, finance, material, groups, communication, notifications, reports, settings, branding, timetable, ai, impersonate, mezmur.
- `admin/api_*.php`: 36 endpoints (attendance, education, finance, hr, identity, import/export, material, mezmur, notifications, reports, settings, subjects, teachers, telemetry, timetable, year_context, cms, branding, failure_intelligence, etc.).

### REST v1 (`api/v1/index.php` router)
Resources: auth, members, attendance, classes, dashboard, teachers, enrollment, subjects, users, grades, app, telemetry, mezmur, hr, notifications, sync.
- Auth: JWT access (15 min) + rotating refresh (90 days); legacy compat for old builds.
- Middleware: CORS (`handleCors`), feature gate per resource, exception/shutdown handlers returning JSON.
- Consumers: Flutter mobile app.

**Verified from:** `api/v1/index.php`, `backend/api/` listing, `admin/api_*.php` listing.

---

## J. Authentication & Authorization

### Web
- Login: `admin/backend/login.php` (POST). Validates CSRF, input types/lengths, rate limits (IP 20/5min, account 5/5min via `SecurityRateLimiter`). Looks up `users` by username or email, `password_verify`, active check. Regenerates session id, sets `$_SESSION` admin claims, password hash version, profile context. Logs to `activity_logs`.
- Session: 30-min timeout (`SESSION_TIMEOUT`), last activity tracking, AJAX-aware expiry (JSON 401 vs redirect).
- Session guard: `AdminSessionGuard::revalidate()` runs on privileged requests, checks role/is_active/authorization_version against DB; fails closed.
- Roles (users.role enum): super_admin, school_admin, info_dept, edu_dept, finance_dept, material_dept. (Plus attendance_taker, teacher, dept heads as member flags.)
- CSRF: token in session, `validateCsrf()`.
- Security headers: X-Frame-Options, nosniff, CSP, HSTS (https), etc.

### API (mobile)
- JWT HMAC-SHA256 with payload claims uid/usr/rol/nam/av/iat/exp/typ/jti.
- Access token 15 min (legacy builds 30 days until 2026-09-24).
- Refresh token rotation with sessions/families (`api_refresh_sessions`, `api_refresh_legacy_exchanges`).
- Authorization scope revalidation for build 24+ / after compat deadline: checks role, is_active, authorization_version, username, full_name against DB.
- `apiRequireRole()` for role gating.

**Verified from:** `admin/backend/login.php`, `config.php`, `api/v1/core/auth.php`.

---

## K. State Management

- Web: PHP `$_SESSION` (admin claims, CSRF, last activity, academic year selection, impersonation original role). LocalStorage? JS may use; not fully traced.
- Mobile: Provider (state), flutter_secure_storage (JWT + staff profile), SharedPreferences, sqflite (offline cache/outbox).
- Server state: DB. Academic year context likely in session/DB settings.
- URL state: query params for filters.

**UNKNOWN:** exact web JS state usage (localStorage keys) — not traced.

---

## L. Business Rules (extracted)

- Feature toggles default true; disabled modules blocked at browser + REST + UI.
- Session timeout 30 min.
- Login rate limits: IP 20/300s, account 5/300s.
- JWT access 15 min; refresh 90 days; legacy 30 days until 2026-09-24.
- Authorization scope revalidation build 24+.
- Ethiopian timezone Africa/Addis_Ababa.
- Member registration types: waiting/transfer/direct; member types regular/special_regular/honorary.
- Age groups: 7_13, 14_17, 18_plus.
- Assessment: per-semester 100% weight budget; annual = average of semesters (per docs).
- Semester close/reopen with flags.
- Destructive test-data tools disabled by default.
- Feature flags must be real booleans (strings fail closed).
- Mobile tiles filtered by enabled features.

**Verified from:** `config.php`, `school_config.php`, `api/v1/core/auth.php`, docs, `FeatureGate.php`.

---

## M. Workflow Maps (key)

### Web login
User → admin/index.php form → POST admin/backend/login.php → CSRF + rate limit → DB user lookup → password_verify → session set → redirect admin/dashboard.php (or JSON for AJAX). Audit log insert.

### Dashboard init
Page load JS → GET backend/api/init.php → session check → returns user/school/theme/csrf/stats → UI render.

### Mobile auth
App → POST api/v1/auth/login → JWT + refresh issued → stored in secure storage → subsequent requests Bearer token → apiRequireAuth → scope revalidation.

### Member registration (inferred high-level)
UI → admin/api_import_members / register_submit.php / hr_register_member → MemberRegistrationPolicy → duplicate check → code generation (IdentityCodeService/MemberCodeFormat) → DB insert → notifications (workflow) → member changes log.

**UNKNOWN:** exact end-to-end trace for each workflow not fully traced; documented at module level. Per-change, trace individually (per protocol §37).

---

## N. Status/State Transition Maps

- Member status: active/archived (and possibly others) — `status!='archived'` in init stats. Transitions via archive/restore (`info_archive_member.php`, `info_restore_member.php`).
- Academic year/semester: close & reopen flags (`067_semester_reopen_flag.sql`).
- Assessment/grade submission: slot uniqueness.
- Refresh tokens: rotation families.
- **UNKNOWN:** complete state machine enumeration per entity not traced.

---

## O. External Integrations

- **Cloudflare R2** (Mezmur audio media): optional, env vars MEZMUR_MEDIA_*. Endpoints answer "not configured" if unset. Evidence: `env.example.php`.
- **AI providers** (Gemini/Groq/OpenAI): keys stored encrypted in DB; `AI_ENC_KEY` optional. Evidence: `env.example.php`, `admin/backend/ai_providers.php`, `admin/api_ai.php`.
- **Email/SMS providers:** not found in source (UNKNOWN whether used).
- **Payment providers:** none found (UNKNOWN).

---

## P. Configuration

- Secrets: `.fkss_env.php` / `.wbws_env.php` above web root (DB_HOST/NAME/USER/PASS, JWT_SECRET, BACKUP_KEY, HEALTH_KEY, MONITOR_SECRET_KEY, TELEMETRY_HASH_SECRET, optional overrides).
- `config.php` loads env from candidate paths; fails closed if missing.
- `school_config.php`: branding + feature toggles (defaults true).
- `env.example.php`: template.
- App release: `/home/USER/.fkss_app_release.php` (optional).
- Private member storage path optional.
- Backup storage path optional.

**CONFIGURATION EXPECTED BUT NOT PRESENT (in this clone):** real `.fkss_env.php` (gitignored), app release file, R2/AI keys. Correct — secrets not in repo.

---

## Q. Error Handling

- Web: `error_reporting(E_ALL)`, display off, log to `error.log`. `reportInternalError()` used in API. Uncaught exception handler in API v1 returns stable JSON 500.
- DB connection failures: logged, not fatal; pages handle.
- Login audit failures swallowed.
- API: typed error codes (ACCOUNT_REMOVED, ACCOUNT_DISABLED, AUTH_SCOPE_CHANGED, PROFILE_CLAIMS_CHANGED, etc.).
- **UNKNOWN:** full error-handling consistency across all admin/api files not traced.

---

## R. Dependency Graph (critical)

- `config.php` is the root dependency of almost everything (DB, session, helpers).
- `school_config.php` + `branding_defaults.php` required by config.
- `FeatureGate` (admin/backend/services) required by config.php (function `feature_enabled`).
- `AdminSessionGuard` depends on PDO + `MysqliProfileRepository` (referenced).
- Services depend on `$conn`/`$pdo` (global) and each other.
- Mobile depends on REST v1 endpoints.
- Circular dependency risk: services under `App\Services` namespace in `admin/backend/services` but also referenced from `backend/` and `api/v1/` via relative requires.

**UNKNOWN:** full dependency graph not statically computed.

---

## S. Potential Problems (documented, not fixed)

- `database_schema.sql` documents only 5 tables; rest via migrations/auto_fix — drift risk.
- Two parallel config trees (`admin/config.php` vs root `config.php`) and duplicate service trees (`admin/backend/services` vs `backend/`) — duplication risk.
- `FeatureGate::isEnabled` returns false if constant undefined, but school_config defaults all to true — consistent only if school_config loaded.
- Legacy token compat window until 2026-09-24; build 23 retains old behavior.
- `ENABLE_TEST_DATA_TOOLS` default false (good).
- Security audit report exists (`ENTERPRISE_SECURITY_AUDIT_REPORT.md`) — prior findings.
- Potential dead code / orphaned files not classified (protocol requires ENTRY-POINT DEPENDENT checks) — UNKNOWN.

---

## T. Unknowns

- Exact column-level schemas for most tables.
- Email/SMS/push providers (none found).
- Payment providers (none found).
- Full method-level behavior of all 67 services and 129 security tests.
- Web JS state (localStorage keys) and full frontend traceability.
- Mobile Dart service internals (52 services) beyond names.
- Whether `admin/config.php` duplicates root config.
- Production deployment topology (hosting account layout).
- Whether all 105 tables are actually present in production.

---

## U. Documentation Mismatches

- `env.example.php` says feature flags omitted → all modules enabled, but `FeatureGate::isEnabled` fails closed if undefined. Reconciled by `school_config.php` defaults (true). So runtime OK as long as school_config loaded. **INFERENCE:** if a deployment loads FeatureGate without school_config, all features disabled — potential mismatch. Verified: `config.php` requires school_config before FeatureGate.
- `database_schema.sql` header says "documents all tables" but only 5 tables — DOCUMENTATION/IMPLEMENTATION MISMATCH (verified).

---

## V. Change Impact Map

- Changing `config.php` / `school_config.php` / env affects entire system.
- Changing `FeatureGate` affects admin routes, API resources, mobile tiles.
- Changing `users` schema/authorization_version affects web session guard + API scope revalidation.
- Changing API auth (`api/v1/core/auth.php`) affects mobile app login/refresh; must preserve backward compat with old builds.
- Changing DB schema requires migration + auto_fix + sql/ + tests.
- Changing member code format affects ID cards, exports, mobile.
- Changing Ethiopian date helpers affects reports/ID cards.

---

## Analysis completeness statement

Per protocol §36, this codebase is very large (2,379 files incl. vendor; ~358 first-party PHP, 173 Dart, 105 tables, 129 security tests, 67 SQL migrations). This report establishes global architecture, module inventory, technology stack, data model overview, auth flows, API map, and change-impact guidance. **Module-level and function-level deep traces are not exhaustively completed**; they are queued for on-demand analysis per §37 before any modification. Marking: **ANALYSIS INCOMPLETE** at function/file depth for services and mobile; architecture-level analysis is complete.

**Remaining to verify on demand:** per-service method traces, per-route request/response contracts, per-table column schemas, mobile service internals, web JS state flows, and test coverage mapping.
