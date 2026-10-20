# FKSS — Sunday School Management Platform

**A production school-management platform for Felege Kidusan (FKSS/WBWS) Sunday schools** — member management, academics, attendance, reporting, communications, and a Flutter mobile app with offline-first sync.

![Status](https://img.shields.io/badge/status-production-blue) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white) ![Flutter](https://img.shields.io/badge/Flutter-3.x-02569B?logo=flutter&logoColor=white) ![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?logo=mysql&logoColor=white) ![Tests](https://img.shields.io/badge/tests-1%2C301%20passed%20%2F%201%2C347-success) ![CI](https://img.shields.io/badge/CI-active-success)

---

## Branches — read `BRANCHING.md` first

| Branch | Purpose |
|---|---|
| `main` | The system. Identical in `suraman21/SSMS` and the production repo `Felege-Kidusan/felegekidusan`; builds the production phone app **FKSS** (`felegekidusan.com`). |
| `dev` (`suraman21/SSMS` only) | `main` + **one** commit re‑targeting the phone app at the test server → **FKSS Beta** (`felegekidusan.arkeonethiopia.com`, package `com.arkeonethiopia.fkss.beta`). Always kept current by merging `main` **into** it. |

**⛔ `dev` is never merged into `main` and never pushed to the production repo** (owner rule, enforced by `tests/security/test_mobile_target_environment.py` and `.github/workflows/branch-guard.yml`).

## Overview

FKSS replaces fragmented spreadsheets and paper records with one platform used by HR, Education, Mezmur (hymn ministry), Information, and school administration departments:

- **Web admin** — PHP 8.1+ modular monolith with department workspaces (HR, Education, Mezmur, Info, School Admin, Super Admin), member registration & family linkage, grade entry & report cards, attendance, analytics & export centre, notifications, and branding controls
- **REST API v1** — JWT auth, rate limiting, CSP/HSTS, role-based access per endpoint
- **Flutter mobile app** (`fkss_app`) — Android + iOS, **offline-first SQLite sync**, biometric/PIN app lock (PBKDF2, brute-force backoff), report cards, Mezmur playback, resumable APK distribution
- **Database** — MySQL 8 / MariaDB 10.6+, 49 incremental migrations, views, triggers, audit trails

## Engineering highlights

| Area | What we do |
|---|---|
| **Testing** | 1,347 automated tests — 1,301 passed, 0 failed, 46 environment-skipped. Unit + security + contract + E2E (Playwright) + mutation + smoke suites under `tests/` |
| **CI** | GitHub Actions: `backend-checks` and `production-integrity` workflows gate every change |
| **Security** | OWASP Top 10 / ASVS / MASVS-mapped audit & hardening — zero runtime DDL, strict exception masking, SRI + CSP + HSTS, secrets outside the webroot with fail-closed startup. Full write-up in [`ENTERPRISE_SECURITY_AUDIT_REPORT.md`](./ENTERPRISE_SECURITY_AUDIT_REPORT.md) |
| **Engineering process** | Conventional commits, phase planning docs, ADRs, incident reports and observability audits under [`docs/`](./docs) |
| **Release workflow** | Development happens here → reviewed release PRs → production repository [Felege-Kidusan/felegekidusan](https://github.com/Felege-Kidusan/felegekidusan) (see `docs/PRODUCTION_WORKFLOW.md`) |

## Architecture

```
┌────────────────────┐     ┌──────────────────────┐     ┌─────────────┐
│  Flutter mobile     │────▶│  REST API v1 (PHP)    │────▶│  MySQL 8    │
│  (offline SQLite    │◀────│  JWT · rate limiting  │◀────│  49 migr.   │
│   sync, app lock)   │     │  role-based access    │     │  audit log  │
└────────────────────┘     └──────────┬───────────┘     └─────────────┘
                                      │
                           ┌──────────▼───────────┐
                           │  PHP admin web        │
                           │  HR · Education ·     │
                           │  Mezmur · Info ·      │
                           │  School/Super Admin   │
                           └──────────────────────┘
```

## Tech stack

| Layer | Technologies |
|---|---|
| Backend | PHP 8.1+, Apache (`mod_rewrite`, `mod_headers`), Composer |
| API | REST v1, JWT, PDO prepared statements |
| Mobile | Flutter 3.x / Dart, SQLite offline sync, background producers |
| Frontend | Vanilla JS + themed PHP views, WCAG-oriented visual analytics engine |
| Database | MySQL 8 / MariaDB 10.6+, `utf8mb4_unicode_ci` |
| Tests | pytest, Playwright, custom security/mutation harnesses |
| CI/CD | GitHub Actions → PR-based production sync |

## Getting started

```bash
git clone https://github.com/suraman21/fkss-sunday-school-platform.git
cd fkss-sunday-school-platform
composer install
cp env.example.php /path/outside/webroot/env.php   # secrets live ABOVE the webroot
# import sql/001_*.sql … 049_*.sql into MySQL, then configure the env file
```

> The application **fails closed** if its secrets file is missing — see `config.php` for details.

## Testing

```bash
pytest tests/                  # security / audit / contract suites
npx playwright test            # browser E2E (tests/e2e)
```

## Documentation

Serious engineering write-ups live in [`docs/`](./docs): phased delivery plans (academic tracking, mobile offline sync), incident post-mortems, failure-intelligence and observability audits, responsive audits, and the production workflow.

## Links

- **Production:** [Felege-Kidusan/felegekidusan](https://github.com/Felege-Kidusan/felegekidusan)
- **Security audit:** [`ENTERPRISE_SECURITY_AUDIT_REPORT.md`](./ENTERPRISE_SECURITY_AUDIT_REPORT.md)
