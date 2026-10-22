# FKSS — Sunday School Management System

FKSS (also referred to in the code as SSMS and, in older files, WBWS/WBSS) is the management system of the Felege Kidusan Sunday School. It replaces spreadsheets and paper records for member registration, education (classes, assessments, report cards, timetable, attendance), the Mezmur (hymn) ministry, communications and reporting. Staff use a PHP web application; teachers and department staff also use an Android app built with Flutter that works offline and synchronises with the server.

> **Status (2026-10-22, `main` = `46fbaa7`).** The system is in production use.
> - The automated *Backend checks* gate is currently **failing**: 2 failing tests and 79 skipped tests. See [Testing and CI](docs/DEVELOPMENT_AND_TESTING.md#3-current-ci-status).
> - Production deployments are manual. See [Operations](docs/OPERATIONS.md).
> - The project is being prepared for an engineering handover. Start with [docs/ENGINEERING_HANDOVER.md](docs/ENGINEERING_HANDOVER.md).
> - No independent security certification exists.

## Principal capabilities

| Area | What exists in the code |
|---|---|
| Members | Registration (HR), temporary → permanent promotion, guardian/sibling linking ("Family Link"), import/export, ID cards with QR codes, archiving |
| Education | Classes, subjects, teacher assignments, enrolment, assessments with a maximum score, submission → review/approval workflow, report cards, timetable builder, school calendar (holidays / days off) |
| Attendance | Class-day attendance (web and mobile), lesson-level sessions, monthly roll-ups, attendance warnings and department status |
| Mezmur | Hymn catalogue, lyrics with timing, audio stored in object storage, department attendance and submissions |
| Communication | Announcements, notifications, threaded messages (web and mobile) |
| Reporting | Reporting centre with saved views, Excel/PDF exports, department analytics |
| Administration | Users and roles, branding, app-release manager (APK distribution and update policy), system health, encrypted database backups, failure intelligence and mobile telemetry |
| Mobile app | Offline cache and outboxes (attendance, grades, HR, Mezmur, messages), background sync, app lock (PIN/biometric), in-app update from the server |

## Technology stack

| Layer | Technology | Evidence |
|---|---|---|
| Server runtime | PHP **8.2** in production (shared hosting, LiteSpeed, no shell access, no Composer, no cron assumed). CI lints and tests on PHP 8.4 | `AGENTS.md`, `.github/workflows/backend-checks.yml` |
| Database | MariaDB (CI: `mariadb:11.4`). Access mostly through **mysqli** (about 150 files), with PDO in a few places | `backend-checks.yml`, `config.php` |
| PHP libraries | Committed in `vendor/` (PhpSpreadsheet 5.9, OpenSpout, ZipStream). TCPDF and phpqrcode are bundled in `admin/` | `composer.lock` |
| Web front end | Server-rendered PHP pages with vanilla JavaScript (`admin/js`, `frontend/js`) | — |
| REST API | `/api/v1`. Custom HMAC-signed bearer tokens (*not* standard JWT) with rotating refresh sessions | [docs/API.md](docs/API.md) |
| Mobile | Flutter (CI: 3.44.9, stable), Dart ≥ 3.3, `sqflite`, `flutter_secure_storage`, `local_auth`. App version on `main`: **1.7.3+40** | `Mobile/wbws_flutter_app/pubspec.yaml` |
| Tests | Python `pytest` suites (security, regression, e2e with Python Playwright), PHP harnesses, Flutter unit tests | [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md) |
| CI | GitHub Actions: *Backend checks*, *Production integrity*, *Branch guard* | `.github/workflows/` |

## Architecture at a glance

```
 Browser (staff) ──► admin/*.php pages ──► admin/api_*.php  (session + CSRF)
                     frontend/pages   ──► backend/api/*.php (shims → admin/api_*.php)
 Android app ──────► /api/v1/{resource}/{id}/{action}  (bearer token)
                                │
                     admin/backend/services/*  (shared domain services)
                                │
                     MariaDB  ·  object storage (Mezmur media)  ·  Telegram (alerts)
```

There are **three API layers**; consolidation is unfinished. Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) before changing any endpoint.

## Repository layout

| Path | Contents |
|---|---|
| `admin/` | Staff web application: pages, department dashboards, `api_*.php` endpoints, `backend/` services, JS/CSS |
| `api/v1/` | REST API used by the mobile app |
| `backend/api/` | Compatibility shims that delegate to `admin/api_*.php` |
| `frontend/` | Newer router-style pages (dashboard, Mezmur, finance, education assignments/teachers) |
| `Mobile/wbws_flutter_app/` | Flutter app (`fkss_app`) |
| `sql/` | Numbered SQL migrations (`001`–`078`) and `preflight/` read-only checks |
| `tests/` | `security/`, `e2e/`, `smoke/`, `audit/`, `mutation/`, `js/` |
| `monitor/`, `app/`, `public_website_frontend/` | Error/uptime monitor, legacy PWA, standalone public site package |
| `docs/` | Documentation. Start at [docs/README.md](docs/README.md) |

## Local development (summary)

The full, verified procedure and its caveats are in [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md).

1. You need:
   - PHP 8.2 (production parity) or 8.4 (CI parity) with mysqli, pdo_mysql, mbstring, curl, xml, gd, zip and bcmath. These are the extensions CI and `provision_env.sh` install.
   - MariaDB, Python 3.11 or later, and Flutter for mobile work.
2. Create a secrets file named `.fkss_env.php` **outside the web root**, using `env.example.php` as the template. `config.php` refuses to start if the file is missing. Never commit it.
3. Build a disposable test database with `tests/e2e/provision_env.sh`. It is destructive and test-only, and expects Debian/Ubuntu with passwordless `sudo`. **Never run it against production.**
4. Serve the repository root with PHP's built-in server, as the e2e harnesses do (`php -S 0.0.0.0:8080 -t .`).

## Testing and CI

```bash
python3 -m pytest tests/security -q          # security / regression suite (as in CI)
cd Mobile/wbws_flutter_app && flutter test     # Flutter unit tests
```

Latest CI run on `main` (`46fbaa7`, 2026-10-22):

| Job | Result |
|---|---|
| PHP lint | pass (363 files) |
| Flutter tests | pass (657 tests) |
| Migration numbering | pass |
| Android debug APK | pass |
| Security and regression suite | **fail** (2 failed, 2,793 passed, 79 skipped; the job also fails whenever any test is skipped) |

Details and the work needed to make the gate trustworthy again: [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md).

## Documentation

| Topic | Canonical document |
|---|---|
| Index of all documents | [docs/README.md](docs/README.md) |
| Engineering handover | [docs/ENGINEERING_HANDOVER.md](docs/ENGINEERING_HANDOVER.md) |
| Architecture | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| API | [docs/API.md](docs/API.md) |
| Roles and permissions | [docs/ROLES_AND_PERMISSIONS.md](docs/ROLES_AND_PERMISSIONS.md) |
| Database and migrations | [docs/DATABASE.md](docs/DATABASE.md) |
| Development, testing, CI | [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md) |
| Deployment, backups, mobile release | [docs/OPERATIONS.md](docs/OPERATIONS.md) |
| Security controls and reporting | [docs/SECURITY.md](docs/SECURITY.md) |
| Branch rules | [BRANCHING.md](BRANCHING.md) |
| Release workflow | [docs/PRODUCTION_WORKFLOW.md](docs/PRODUCTION_WORKFLOW.md) |

## Contribution, review and release

- **Repositories:**
  - Development happens in `suraman21/fkss-sunday-school-platform`. That repository was formerly named `suraman21/SSMS`, and the old URL redirects to it.
  - `Felege-Kidusan/felegekidusan` is the private production repository.
  - Their `main` branches are kept identical.
- **Beta branch:** `dev` builds the beta mobile app. It is **never** merged into `main`. See [BRANCHING.md](BRANCHING.md).
- **Changes:** use a feature or documentation branch and a pull request against `main`. Changes to application logic, schema, migrations, deployment or production configuration need the owner's explicit approval.
- **Migrations:** applied to production manually by the owner.
- **Deployment:** a manual SFTP upload of the changed files, with a backup and checksum verification. See [docs/OPERATIONS.md](docs/OPERATIONS.md).
- **Security issues:** do **not** report them in public issues or pull requests. Contact the project owner privately. See [docs/SECURITY.md](docs/SECURITY.md).

No licence file is present in this repository.
