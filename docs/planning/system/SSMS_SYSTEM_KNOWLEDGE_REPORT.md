# SSMS — System Knowledge Report (architecture baseline, reconciled)

> **Status: historical architecture baseline; reconciled 2026-10-22. This is not the accepted engineering handover.**
> - **Accepted handover:** [docs/ENGINEERING_HANDOVER.md](../../ENGINEERING_HANDOVER.md).
> - **Canonical current documentation:** [docs/ARCHITECTURE.md](../../ARCHITECTURE.md), [docs/API.md](../../API.md), [docs/DATABASE.md](../../DATABASE.md).
> - **Use of this report:** module inventory and change-impact orientation. If it disagrees with the canonical documents or the code, they win.

| Item | Value |
|---|---|
| Original analysis | AI-assisted static code reading, dated 2026-10-16. No runtime or database was available |
| Analysed commit | **`6d5a5f8`** (2026-10-09, *Merge pull request #10 from Felege-Kidusan/sync/from-development*), which was `main`'s tip until 2026-10-16 16:46 UTC |
| How the basis was identified | The original recorded no commit. Every count it gives matches `6d5a5f8` exactly: 2,379 tracked files, highest migration `067`, 129 `tests/security` test files, 36 `admin/api_*.php` files, 173 Dart files, 67 service classes. The next commit on `main` (`8092cae`) adds migration `068` |
| Reconciled against | `main` = **`46fbaa7`** (2026-10-22), 94 commits later |
| Original text | Unchanged in Git history at `ee2e43a` (`git show ee2e43a:docs/planning/system/SSMS_SYSTEM_KNOWLEDGE_REPORT.md`) |

Labels: **VERIFIED** = confirmed in source, CI or an owner-authorised read-only check. **INFERENCE** = reasoned from evidence. **UNKNOWN** = not established. Values below describe `46fbaa7` unless marked "at `6d5a5f8`".

---

## 0. Reconciliation: claims corrected on 2026-10-22

| # | Original claim (at `6d5a5f8`) | Current position (`46fbaa7`) | Label | Evidence |
|---|---|---|---|---|
| 1 | "PHP 8.4" / "PHP 8.x" as the server language | Production runs **PHP 8.2**; CI lints and tests on **PHP 8.4** | VERIFIED | `AGENTS.md`; `.github/workflows/backend-checks.yml` (`php-version: '8.4'`); read-only server inspection on 2026-10-22 |
| 2 | Web server "Apache" | Production runs **LiteSpeed**, which honours Apache-style `.htaccess` | VERIFIED (server inspection, 2026-10-22). Not observable externally, because a proxy masks the `server` header | Read-only inspection |
| 3 | "MariaDB 10.6" (production banner) | A production schema dump dated 2026-10-02 recorded **MariaDB 11.4**. The current server version was not re-verified. "10.6" appears only as a historical compatibility target in older headers (migrations `004`, `048`, `049`, `055`; `sql/preflight/`). CI uses `mariadb:11.4` | VERIFIED (2026-10-02 dump citation); UNKNOWN (current server) | `sql/054_legacy_era_columns.sql` line 33; `sql/066_academic_year_charset_repair.sql` line 5; `backend-checks.yml` |
| 4 | "67 numbered migrations" | At `6d5a5f8` there were **65** files (`001`–`067`; `047` and `061` absent). Now there are **76** files (`001`–`078`). Applied state in production is UNKNOWN; there is no ledger | VERIFIED (source); UNKNOWN (production) | `git ls-tree`; [DATABASE.md](../../DATABASE.md) |
| 5 | "129 Python security tests" | 129 was the number of test **files**. Now there are **153** files. Latest CI: **2 failed, 2,793 passed, 79 skipped** (test functions) | VERIFIED | Actions run `39744630068` (`46fbaa7`); PR #10 run, identical result |
| 6 | CI = "php -l + 129 Python security tests + MariaDB" | *Backend checks* has five jobs: lint, security suite (with a zero-skip gate), Flutter tests, migration checks, debug APK. Plus *Production integrity* and *Branch guard*. *Backend checks* has failed on `main` since `b9cbb0f` (2026-10-19) | VERIFIED | `.github/workflows/`; [DEVELOPMENT_AND_TESTING.md](../../DEVELOPMENT_AND_TESTING.md) |
| 7 | API auth = "Custom JWT (HMAC-SHA256)" | A **custom two-part token**: `base64(JSON payload) . hex(HMAC-SHA256)`. There is no JWT header and no `alg` field, so it is **not** a JWT and not JWT-compatible. The secret constant is still named `JWT_SECRET` for historical reasons | VERIFIED | `api/v1/core/auth.php` (`createToken`, `verifyTokenState`) |
| 8 | `database_schema.sql` "documents only 5 tables" | It defines **11** tables, both at `6d5a5f8` and now. It is still stale (the schema has about 100 tables) | VERIFIED | `grep -c 'CREATE TABLE' database_schema.sql` |
| 9 | `admin/backend/auto_fix_database.php`; `get_schema.php` | These paths do not exist. The real files are `admin/migrations/auto_fix_database.php` and `backend/migrations/auto_fix_database.php` (CLI-only, blocked from the web). `admin/get_schema.php` is a retired, CLI-only stub | VERIFIED | `git ls-files`; `.htaccess`; `admin/get_schema.php` |
| 10 | Roles = `users.role` enum of 6 values | That enum is in the stale `database_schema.sql`. The application accepts **13** roles. The production column definition is UNKNOWN | VERIFIED (code); UNKNOWN (production) | `admin/backend/user-save.php` (`$validRoles`); [ROLES_AND_PERMISSIONS.md](../../ROLES_AND_PERMISSIONS.md) |
| 11 | 36 `admin/api_*.php`; 16 REST v1 resources; 67 services; 52 Dart services; 173 Dart files | **46**; **17** (`timetable` added); **79**; **53**; **188** | VERIFIED | `git ls-files`; `api/v1/index.php` `$routeMap` |
| 12 | AI providers "Gemini/Groq/OpenAI" | Gemini, Groq, OpenRouter, OpenAI, Anthropic, plus any OpenAI-compatible URL. Production enablement is UNKNOWN | VERIFIED (code) | `admin/backend/ai_providers.php` |
| 13 | Email/SMS providers UNKNOWN | No application e-mail or SMS code was found (`mail(`, PHPMailer, SMTP, SMS APIs) | VERIFIED (absence in code) | Repository search at `46fbaa7` |
| 14 | Production deployment topology UNKNOWN | Deployment is a manual SFTP upload of changed files. `46fbaa7` was deployed on 2026-10-22 with 89 of 89 checksums verified. Server-side git metadata is not authoritative | VERIFIED (deployment record) | [OPERATIONS.md](../../OPERATIONS.md) |
| 15 | Mobile "Flutter 3.27+" | The constraint is `flutter: '>=3.27.0'`. CI pins 3.44.9. `main` has app **1.7.3+40**; production serves **1.6.10 (build 36)** | VERIFIED | `pubspec.yaml`; read-only `GET /api/v1/app/config` on 2026-10-22 |
| 16 | `ENTERPRISE_SECURITY_AUDIT_REPORT.md` cited as "prior audit" | Superseded. Its "certified" status is contradicted by later evidence | VERIFIED | Banner in that file; [docs/README.md](../../README.md) |
| 17 | Session timeout 30 min; login limits IP 20/300 s and account 5/300 s; access token 15 min; refresh 90 days | **Still accurate** | VERIFIED | `config.php` (`SESSION_TIMEOUT` 1800); `admin/backend/login.php` (`consume(…, 20, 300)`, `consume(…, 5, 300)`); `api/v1/core/auth.php` (`API_TOKEN_EXPIRY`, `API_REFRESH_EXPIRY`) |

---

## A. Executive system summary

SSMS is a Sunday School Management System, deployed as FKSS for the Felege Kidusan Sunday School.
- **Scope:** members and students, attendance, education (classes, subjects, assessments, grades, report cards, timetable, school calendar), finance, material, HR, groups, messaging, notifications, CMS (public site), ID cards, the Mezmur (hymn) library with audio, and an offline-capable Flutter mobile client.
- **Web dashboards** (`admin/`, `frontend/`): PHP 8.2 in production on LiteSpeed. MariaDB 11.4 per a 2026-10-02 dump, not re-verified (see §0 rows 1–3).
- **REST API v1** (`api/v1/`): used by the mobile app, with a custom HMAC-signed access token and rotating refresh tokens (§0 row 7).
- **Compatibility shims** (`backend/api/`): used by the `frontend/` pages; they delegate to `admin/api_*.php`.
- **Mobile app** (`Mobile/wbws_flutter_app/`): Flutter (≥3.27; CI 3.44.9) and Dart ≥3.3, with an SQLite offline cache, outboxes and background sync.

The system is feature-gated (`FeatureGate`), with per-deployment toggles for AI, groups, finance, material, Mezmur, ID cards, attendance, grades, reports, PDF export and the monitor. All toggles default to enabled.

**Evidence:** `school_config.php`, `config.php`, `admin/backend/services/FeatureGate.php`, `Mobile/wbws_flutter_app/pubspec.yaml`.

---

## B. Technology stack

| Layer | Technology | Evidence |
|---|---|---|
| Server language | PHP 8.2 in production; PHP 8.4 in CI | `AGENTS.md`, `backend-checks.yml` |
| Web server | LiteSpeed (Apache-compatible `.htaccess`, mod_rewrite rules) | Server inspection, 2026-10-22; `.htaccess` |
| Database | MariaDB (InnoDB, utf8mb4); 11.4 per a 2026-10-02 production dump, not re-verified; CI uses 11.4 | `config.php`, `backend-checks.yml` |
| DB access | Raw mysqli (predominant) and PDO; no ORM | `config.php` |
| Frontend (web) | Vanilla JS and PHP-rendered HTML/CSS | `frontend/js/*`, `admin/js/` |
| Mobile | Flutter/Dart (provider, sqflite, flutter_secure_storage, http) | `pubspec.yaml` |
| PDF | TCPDF (vendored) | `admin/backend/pdf/tcpdf/` |
| Spreadsheet | PhpSpreadsheet 5.9, OpenSpout | `composer.lock`, `vendor/` |
| QR | phpqrcode (vendored) | `admin/id_cards/libs/phpqrcode/` (the second copy, `backend/id_cards/libs/`, was removed on 2026-10-23, RFC 0001) |
| Auth (web) | PHP sessions, CSRF tokens, rate limiter, session revalidation | `config.php`, `admin/backend/login.php`, `SecurityRateLimiter.php`, `AdminSessionGuard.php` |
| Auth (API) | Custom HMAC-SHA256 two-part token plus rotating refresh sessions | `api/v1/core/auth.php`, `RefreshTokenService.php` |
| Date system | Ethiopian calendar helpers | `admin/backend/ethiopian_date.php`, `backend/core/ethiopian_date.php` |
| CI | GitHub Actions; see §0 row 6 | `.github/workflows/` |
| Tests | Python pytest (security, regression, e2e), JS tests driven from Python, PHP smoke and e2e harnesses, Flutter tests | `tests/`, `Mobile/wbws_flutter_app/test/` |

---

## C. Repository architecture (top level)

```
├── config.php, school_config.php, branding_defaults.php   # root config + branding
├── index.php, member.php, register_submit.php, public_gallery.php  # public web entry
├── .htaccess, composer.json, env.example.php
├── admin/        # admin web app: dashboards/, api_*.php, backend/ services, migrations/ (legacy, CLI-only)
├── backend/      # shared backend: api/ (shims), auth/, core/, config/, id_cards, migrations/ (legacy)
├── api/v1/       # REST API for mobile: core/, routes/
├── frontend/     # router-style pages (dashboard, edu, finance, mezmur)
├── app/          # legacy PWA shell (index.html, manifest, sw.js)
├── Mobile/       # Flutter app
├── monitor/      # error monitor + uptime script (needs cron)
├── themes/       # fkss, wbss themes
├── sql/          # 76 numbered migrations (001–078) + preflight/
├── tests/        # security (153 files), e2e (44), smoke (6), audit, js, mutation, fixtures
├── docs/         # documentation — index at docs/README.md
├── vendor/       # Composer dependencies (committed)
├── cgi-bin/, tools/, scripts/
```

---

## D. Application architecture

```
Browser / Mobile client
   ↓
Entry (index.php / admin/*.php / api/v1/index.php / backend/api/*.php)
   ↓
config.php (secrets file, session, DB connections: $conn mysqli + $pdo)
   ↓
Guards (admin/access_control.php, AdminSessionGuard, apiRequireAuth, FeatureGate, CSRF, rate limiter)
   ↓
Endpoints (admin/api_*.php, backend/api/*.php → admin/api_*.php, api/v1/routes/*.php)
   ↓
Service layer (admin/backend/services/*.php, 79 files, namespace App\Services)
   ↓
Database (mysqli/PDO) · object storage (Cloudflare R2) · AI providers (optional) · Telegram (alerts)
   ↓
Response (HTML/JSON)
```

Three API surfaces exist (see [ARCHITECTURE.md §3](../../ARCHITECTURE.md#3-the-three-api-layers-unfinished-consolidation)):
1. `admin/api_*.php`: session-based, with CSRF.
2. `backend/api/*.php`: shims that delegate to `admin/api_*.php`.
3. `api/v1/`: router plus route files, using a bearer token.

---

## E. Module-by-module overview

1. **Core/Auth.** Session login, CSRF, rate limiting, admin session guard, API token auth, refresh-token rotation, authorization-scope revalidation.
   - Files: `config.php`, `admin/access_control.php`, `admin/backend/login.php`, `AdminSessionGuard.php`, `SecurityRateLimiter.php`, `RefreshTokenService.php`, `api/v1/core/{auth,acl,middleware}.php`.
2. **Members.** Registration, duplicate detection, code generation, categories and types, age, files, export/import, directory, reports, guardians (Family Link).
   - Services: `Member*Service.php`, `MemberRegistrationPolicy.php`, `PasswordPolicy.php`, `MemberAge.php`, `MemberCategory.php`, `MemberCodeFormat.php`, `MemberFileService.php`.
3. **Attendance.** Records, summaries, roll-ups, QR roster, attendance takers, class-day enforcement, lesson-level sessions, warnings and department status.
   - Services: `AttendanceRecordService.php`, `AttendanceSummaryService.php`, and others.
   - APIs: `admin/api_attendance*.php`, `api/v1/routes/attendance.php`.
4. **Education/Academic.** Classes, subjects, enrolment, assignments, assessments with maximum scores, grades, report cards, academic tracking, analytics, timetable, school calendar, academic years and terms.
   - Services: `AcademicTrackingService.php`, `AssessmentTypeService.php`, `AssignmentService.php`, `EnrollmentService.php`, `EducationAnalyticsService.php`, `ReportCardService.php`, `SubjectDurationPolicy.php`, `TimetableService.php`.
5. **Finance.** Transactions, budgets, categories, member fees, ledger validation (`admin/api_finance.php`).
6. **Material.** Categories, items, requests, transactions (`admin/api_material.php`).
7. **HR.** Registration and promotion, HR submissions, HR attendance. HR attendance taking was retired (see `docs/attendance/ATTENDANCE_REWORK_2026-10.md`).
   - Services: `HrAttendanceService.php`, `HrSubmissionService.php`.
   - APIs: `admin/api_hr_*`, `api/v1/routes/hr.php`.
8. **Groups.** Group management, leaders, members (`admin/backend/groups_api.php`).
9. **Communication/Messaging.** Threads, messages, read receipts, edit/delete, polling.
   - Service: `NotificationCenterService.php`.
   - APIs: `admin/api_communication.php`, `api/v1/routes/notifications.php`.
10. **Notifications.** Notification centre, reads, bell (`admin/api_notifications.php`).
11. **Mezmur (hymns).** Categories, hymns, word index, singers, attendance, submissions, media (R2), art, analytics.
    - Services: `Mezmur*Service.php`, `MezmurSchemaReconciler.php` (runtime DDL).
12. **CMS/Public website.** Gallery, programmes, schedule, social links, teachers (`admin/api_cms.php`, `index.php`, `public_gallery.php`; the unused copy `public_website_frontend/` was removed on 2026-10-23, RFC 0001).
13. **ID cards.** Generation, templates, QR (`admin/id_cards/`, `backend/id_cards/`).
14. **AI assistant (optional).** `admin/backend/ai_providers.php`, `admin/api_ai.php`, dashboard widgets.
15. **App release and telemetry.** `AppReleaseManager.php`, `AppTelemetryService.php`, `admin/api_app_release.php`, `admin/api_telemetry.php`, `api/v1/routes/{app,telemetry}.php`.
16. **Failure intelligence.** `FailureAlertService.php`, `FailureIssueService.php`, `admin/api_failure_intelligence.php`.
17. **Sync (mobile offline).** Change feed, sync attempts, idempotency.
    - Services: `SyncChangeFeedService.php`, `SyncInstanceService.php`, `ApiSyncAttempt*Service.php`, `ApiIdempotencyService.php`.
    - API: `api/v1/routes/sync.php`.
18. **Backup and monitor.** `BackupService.php`, `admin/tools/backup.php`, `monitor/`.
19. **Identity migration.** `IdentityCodeService.php`, `IdentityMigrationService.php`, `admin/api_identity*.php`, `admin/tools/migrate_identity_codes.php`.
20. **Mobile app.** Screens for each module, plus services for the API, sync, outboxes and the local database.

---

## F. Important files

- `config.php`: root bootstrap. Loads the secrets file and fails closed if it is missing. Also handles branding, the session, CSRF helpers, DB connections and security headers.
- `admin/config.php`: a thin wrapper that loads the root `config.php`. VERIFIED (file header).
- `admin/access_control.php`: the central page and endpoint role guard.
- `school_config.php` and `branding_defaults.php`: branding and feature-toggle defaults.
- `backend/core/browser.php`: session cookie hardening and HTTPS detection.
- `api/v1/index.php` (router) and `api/v1/core/{acl,auth,database,middleware,response}.php`.
- `admin/backend/services/*.php`: 79 service files.
- `database_schema.sql`: a **stale** snapshot of 11 tables. Its header still says it "documents all tables" (§0 row 8).
- `sql/001–078` (76 files): incremental migrations, applied by hand. There is no ledger.
- `admin/migrations/*`, `backend/migrations/*`: legacy PHP migration scripts, including `auto_fix_database.php`. They are CLI-only and blocked from the web.
- `tests/security/*`: 153 Python test files.

---

## G. Key symbols

- `config.php`: `sanitize()`, `e()`, `esc()`, `generateCsrfToken()`, `csrfField()`, `validateCsrf()`, `requireCsrf()`, `requireCsrfForPost()`, `_isAjaxRequest()`, `_invalidateAdminSession()`.
- `FeatureGate`: `isEnabled()`, `all()`, `mobileCapabilities()`, `forAdminRequest()`, `forApiResource()`, `forRoleDashboard()`, `filterMobileTiles()`.
- `AdminSessionGuard::revalidate()`: reconciles session claims with the database account.
- `SecurityRateLimiter`: `consume()`, `clear()`.
- API auth: `createToken()`, `createRefreshToken()`, `verifyToken()`, `verifyTokenState()`, `getTokenFromRequest()`, `apiRequireAuth()`, `apiRequireRole()`, `apiRevalidateAuthorizationScope()`.
- Mobile: `ApiService`, `SessionService`, `LocalDb`, `SyncExecution`, `OutboxPolicy`, `CommOutboxService`.

**UNKNOWN:** the method-level behaviour of each of the 79 services has not been traced individually.

---

## H. Database architecture

- **Engine:** MariaDB, InnoDB, mostly `utf8mb4_unicode_ci`. The production version was 11.4 in a 2026-10-02 dump; it has not been re-verified (§0 row 3). `sql/066_academic_year_charset_repair.sql` repairs a `latin1` table seen in production.
- **Access:** mysqli (`$conn`) and PDO (`$pdo`), both created in `config.php`.
- **Tables:** 103 distinct names are created by `sql/` (CREATE TABLE scan at `46fbaa7`). The original report estimated about 105 from a broader scan. **Which tables exist in production is UNKNOWN.**
- **Schema management:**
  - the `sql/` migrations, applied by hand and without a ledger;
  - legacy PHP migration scripts;
  - runtime DDL in `MezmurSchemaReconciler`.
  
  See [DATABASE.md](../../DATABASE.md).
- **Ethiopian dates:** stored as `dob_ec_day/month/year` plus a Gregorian `date_of_birth`.
- **Soft delete/archive:** `status='archived'`, `*_archive` tables, `info_archive_member.php`.
- **Audit fields:** `activity_logs`, `hr_attendance_audit`, `mezmur_attendance_audit`, `wbws_audit_log`.

**UNKNOWN:** the column-level schema of the production database.

---

## I. API map

- **`admin/api_*.php` (46 files):** session and CSRF; grouped by domain in [API.md §5](../../API.md#5-admin-endpoints-adminapi_php).
- **`backend/api/*.php` (21 files):** 20 shims to `admin/api_*.php`, plus `init.php`, which has its own logic and returns the dashboard bootstrap JSON.
- **REST v1 (17 resources):** auth, members, attendance, classes, dashboard, teachers, enrollment, subjects, users, grades, app, telemetry, mezmur, hr, notifications, sync, timetable.
  - Access token 15 min; rotating refresh token 90 days.
  - CORS allow-list; feature gate per resource; JSON error handlers.
  - Consumer: the Flutter app.

---

## J. Authentication and authorization

### Web
- **Login (`admin/backend/login.php`):**
  - validates CSRF and input;
  - applies rate limits: per IP 20 per 300 s, per account 5 per 300 s;
  - checks the password with `password_verify` and confirms the account is active;
  - regenerates the session ID and logs the login to `activity_logs`.
- **Session:** 30-minute inactivity timeout (`SESSION_TIMEOUT`). Expiry is AJAX-aware: a JSON 401 instead of a redirect.
- **Session guard:** `AdminSessionGuard::revalidate()` checks role, active status, authorization version and impersonation integrity against the database, and fails closed.
- **Page access:** `admin/access_control.php`. See [ROLES_AND_PERMISSIONS.md](../../ROLES_AND_PERMISSIONS.md).
- **Roles:** 13 accepted by the application (§0 row 10).
- **Security headers:** X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, COOP, HSTS over HTTPS, and a baseline CSP (no script restriction).

### API (mobile)
- **Token:** custom two-part token, `base64(JSON).hex(HMAC-SHA256)`, with claims `uid`, `usr`, `rol`, `nam`, `av`, `iat`, `exp`, `typ`, `jti`. It is not a JWT (§0 row 7).
- **Lifetimes:** access token 15 min. The 30-day legacy tokens for builds below 16 ended on 2026-09-24.
- **Refresh:** rotation with sessions and families (`api_refresh_sessions`, `api_refresh_legacy_exchanges`).
- **Scope revalidation:** role, active status, `authorization_version`, username and full name are checked against the database.
- **Role gating:** `apiRequireRole()` and the helpers in `api/v1/core/acl.php`.

---

## K. State management

- **Web:** PHP `$_SESSION` holds the admin claims, CSRF token, last activity, academic-year view context and the impersonation original role. Browser `localStorage` usage has not been traced (UNKNOWN).
- **Mobile:**
  - Provider for state;
  - `flutter_secure_storage` for tokens and the staff profile;
  - SharedPreferences;
  - sqflite (schema version 37) for the offline cache and outboxes.
- **Server:** the database.

---

## L. Business rules (extracted from code; VERIFIED unless marked)

- Feature toggles default to true. Disabled modules are blocked in the browser, the REST API and the UI. Feature flags must be real booleans; strings fail closed.
- Session timeout: 30 minutes. Login limits: per IP 20 per 300 s, per account 5 per 300 s.
- Access token 15 minutes; refresh 90 days.
- Time zone: Africa/Addis_Ababa. The Ethiopian calendar is used in user-facing dates.
- Member registration types: waiting, transfer, direct. Member types: regular, special_regular, honorary. Age groups: 7_13, 14_17, 18_plus.
- Assessments: a per-semester 100% weight budget. Semester weights come from `sql/056_subject_duration_and_semester_weights.sql` and `SubjectDurationPolicy` (INFERENCE: the annual result follows backend policy, not client arithmetic).
- Semesters can be closed and reopened (`sql/067_semester_reopen_flag.sql`).
- Test-data tools are disabled unless `ENABLE_TEST_DATA_TOOLS` is true.

---

## M. Key workflows

- **Web login:** login form (`admin/index.php`) → `POST admin/backend/login.php` → CSRF and rate limit → user lookup → `password_verify` → session → dashboard (or JSON for AJAX) → audit log entry.
- **Dashboard initialisation:** `GET backend/api/init.php` → session check → user, school, theme, CSRF and stats → render.
- **Mobile auth:** `POST api/v1/auth/login` → access and refresh tokens → secure storage → bearer requests → `apiRequireAuth` → scope revalidation.
- **Member registration** (INFERENCE, high level): HR registration (`hr_register_member.php`) or import (`api_import_members.php`) → `MemberRegistrationPolicy` → duplicate check → code generation → insert → notifications → change log. Members are never registered from the mobile app.

**UNKNOWN:** complete end-to-end traces for each workflow.

---

## N. Status and state transitions

- Member status: active and archived (archive and restore endpoints). Temporary → permanent promotion is owned by HR.
- Academic year and semester: close and reopen flags.
- Grade submissions: one packet per slot (`sql/053_grade_submission_slot_uniqueness.sql`), with a submit → review workflow.
- Refresh tokens: rotation families.

**UNKNOWN:** the complete state machine for each entity.

---

## O. External integrations

- **Cloudflare R2** for Mezmur media: optional, configured through the `MEZMUR_MEDIA_*` keys.
- **AI providers:** Gemini, Groq, OpenRouter, OpenAI, Anthropic, or an OpenAI-compatible URL. Keys are stored encrypted in the database with `AI_ENC_KEY`. Whether it is enabled in production is UNKNOWN.
- **Telegram Bot API** for failure alerts: disabled unless `MONITOR_TELEGRAM_ENABLED` is set. The production setting is UNKNOWN.
- **E-mail/SMS:** none in the application code. **Payment providers:** none found.

---

## P. Configuration

- The secrets file (`.fkss_env.php`, legacy name `.wbws_env.php`) lives outside the web root and defines the keys listed in `env.example.php`. `config.php` refuses to start without it. Key names are in [SECURITY.md §3](../../SECURITY.md#3-configuration-and-secrets); values and locations are never documented.
- If the secrets file loads but `JWT_SECRET` is missing, `config.php` logs a critical error and substitutes a random per-request value. API tokens then stop working (fail-safe).
- `school_config.php` holds branding and feature-toggle defaults.
- An optional app-release override file outside the web root is read by `AppReleaseManager.php`.

---

## Q. Error handling

- PHP errors are logged, not displayed. The API returns a stable JSON 500 from its uncaught-exception handler.
- The API uses typed error codes: `ACCOUNT_REMOVED`, `ACCOUNT_DISABLED`, `AUTH_SCOPE_CHANGED`, `PROFILE_CLAIMS_CHANGED`, `INVALID_SCORES` (422), `confirm_phrase_required` (409).
- **UNKNOWN:** whether error handling is consistent across all `admin/api_*.php` files.

---

## R. Critical dependencies

- `config.php` is the root dependency of almost everything. It requires `school_config.php`, `branding_defaults.php`, `FeatureGate` and `access_control.php`.
- `AdminSessionGuard` depends on PDO and `MysqliProfileRepository`.
- Services use the global `$conn` and `$pdo` and call each other. They are referenced from `admin/`, `backend/` and `api/v1/` through relative requires.
- The mobile app depends on the REST v1 contract and on build-number compatibility (`X-App-Build`).

**UNKNOWN:** a full static dependency graph.

---

## S. Structural problems (documented, not fixed)

- `database_schema.sql` is stale (11 tables). The applied production schema is unverified, and there is no migration ledger.
- There are three API layers with duplicated rules. `admin/config.php` is only a thin wrapper, so it is not a duplication risk.
- `FeatureGate::isEnabled` returns false when a constant is undefined. Defaults are supplied by `school_config.php`, which `config.php` loads first.
- Runtime DDL (`MezmurSchemaReconciler`).
- CI runs PHP 8.4 while production runs 8.2.
- Dead code and orphaned files have not been classified (UNKNOWN).

Prioritised risks: [ENGINEERING_HANDOVER.md §6](../../ENGINEERING_HANDOVER.md#6-risk-register-public-summary).

---

## T. Unknowns (current)

- Column-level schema of production, and which migrations have been applied.
- Whether the production MariaDB version is still 11.4.
- Method-level behaviour of the 79 services; per-route request and response contracts.
- Browser `localStorage` usage and full frontend traceability.
- Internals of the mobile services beyond their names.
- Production values of feature flags, AI enablement and Telegram alerts.

Resolved since the original report: the deployment topology (§0 row 14), e-mail/SMS (row 13) and the role of `admin/config.php` (§F).

---

## U. Documentation mismatches

- The header of `database_schema.sql` says it "documents all tables", but it defines 11 (VERIFIED).
- `env.example.php` says omitted feature flags mean all modules are enabled. That holds because `config.php` loads `school_config.php` defaults before `FeatureGate` (VERIFIED).
- Older documents claiming certification or production readiness are superseded (see [docs/README.md](../../README.md)).

---

## V. Change-impact map

- `config.php`, `school_config.php` or the secrets file: affects the entire system.
- `FeatureGate`: affects admin routes, API resources and mobile tiles.
- `users` schema or `authorization_version`: affects the web session guard and API scope revalidation.
- `api/v1/core/auth.php`: affects mobile login and refresh. Backward compatibility with installed builds must be preserved.
- Database schema: needs a new numbered migration applied by the owner, tests, and a check for runtime DDL.
- Member code format: affects ID cards, exports and the mobile app.
- Ethiopian date helpers: affect reports and ID cards.

---

## Completeness

This report is complete at the **architecture** level and **incomplete** at function and file depth (services, mobile internals, per-route contracts, per-table schemas). Those traces must be done for each change before modification.
