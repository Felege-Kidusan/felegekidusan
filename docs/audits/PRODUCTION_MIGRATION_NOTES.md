# Production Migration Notes — discoveries inventory

> Created 2026-10-07 (production migration **Step 1**). This file records
> what the repository-establishment audit **discovered** and where each item
> lives, so the right future migration step can handle it. **Nothing listed
> here was changed in Step 1.** Evidence is file + symbol at the audited
> commit `7403cb1`.

## DOMAIN — felegekidusan.arkeonethiopia.com → felegekidusan.com

- `school_config.php` — `SITE_DOMAIN` is `'felegekidusan.arkeonethiopia.com'`
  (the single authoritative define; `SITE_URL`, `ADMIN_URL`, `CORS_ORIGINS`
  all derive from it). → change once, in the domain-cutover step.
- `school_config.php` — `HOSTING_USER` is `'arkeonet'` (documentation-only;
  also referenced in the `monitor/uptime_cron.php` header comment). → update
  for the new cPanel username.
- `Mobile/wbws_flutter_app/lib/utils/config.dart` — `AppConfig.apiBaseUrl`
  is a compile-time constant pointing at the old domain; `siteOrigin` is
  derived from it and every API call and mezmur image URL uses it. → requires
  a **new APK** (next build, e.g. 1.6.1+27); there is no runtime override.
- `Mobile/wbws_flutter_app/android/app/src/main/res/xml/network_security_config.xml`
  and the identical `debug/` copy — `<domain>` pins the old subdomain.
- Printed QR ID cards embed the old `SITE_URL` at print time. Emitters of
  `member.php?code=` URLs: `admin/api_qr_roster.php`,
  `admin/backend/services/IdentityCodeService.php`,
  `admin/id_cards/generate_id_card.php`, `admin/id_cards/view_id_card.php`.
  → already-printed cards keep working only while the old domain keeps
  answering (redirect/alias) or until reprinted. The mobile scanner itself is
  domain-agnostic (`lib/services/qr_attendance.dart` extracts the `code=`
  parameter from any `member.php` URL).
- `monitor/uptime_cron.php` — the monitored URL is `SITE_URL` (derived), so
  it self-corrects after the config change; its doc-comment cron path embeds
  `{HOSTING_USER}`.
- `Mobile/HOW_TO_SHIP_AN_UPDATE.md` — deploy commands and curl checks
  hardcode `/home/arkeonet/...` paths and the old domain. → rewrite when the
  new server exists.
- `env.example.php` / `api/v1/app_release.example.php` — illustrative
  comments reference `/home/arkeonet/...` and
  `media.fkss.arkeonethiopia.com` (the optional Cloudflare R2 public base
  for mezmur media, if enabled).

## IDENTIFIERS — intentionally NOT to change

- Android `applicationId`/`namespace` `com.arkeonethiopia.fkss`
  (`Mobile/wbws_flutter_app/android/app/build.gradle`). Renaming breaks
  in-place app updates; keep it even after the domain move.
- `BACKGROUND_SYNC` broadcast action
  `com.arkeonethiopia.fkss.action.BACKGROUND_SYNC` (AndroidManifest + Kotlin
  receiver) — tied to the package id; keep.
- School naming (FKSS / Felege Kidusan / ፍቅር ቤት…) — this is a hosting and
  domain migration, not a rebranding.

## DATABASE

- All database credentials and runtime secrets live in the untracked,
  server-side `.fkss_env.php` (template: `env.example.php`). Nothing is in
  git; the new server needs a verbatim copy with updated DB credentials.
- `JWT_SECRET`, `BACKUP_KEY`, `API_TOKEN_SECRET`, monitor/Telegram keys must
  be copied **unchanged** — rotating `JWT_SECRET` logs out every user, and
  old encrypted backups are only decryptable with the original `BACKUP_KEY`
  (`admin/backend/services/BackupService.php`).
- No absolute URLs are stored in the database: `system_branding` stores
  relative file paths composed into URLs at runtime
  (`admin/api_branding.php`); mezmur media stores object keys, not URLs
  (`admin/backend/services/MezmurMediaService.php`). → no DB URL rewrite is
  needed at cutover.

## AUTH

- JWT-based auth only; the JWT secret lives in the env file. **No OAuth
  callbacks or external auth providers exist** — there are no callback URLs
  to re-register at the new domain.

## DEPLOYMENT / HOSTING

- Current deployment is a manual server-side
  `git fetch origin main && git reset --hard origin/main` where the server's
  origin is **suraman21/SSMS** (`Mobile/HOW_TO_SHIP_AN_UPDATE.md`). → in the
  deployment step, the production server must instead pull from
  **Felege-Kidusan/felegekidusan**.
- Cron jobs on the current host (see `docs/audits/DEPLOYMENT_RUNBOOK.md` and
  the `monitor/uptime_cron.php` header): daily encrypted backup
  (`admin/tools/backup.php`, ~02:00), uptime monitoring, telemetry
  retention. Absolute paths are host-specific → recreate on the new cPanel.
- APK serving: `.fkss_app_release.php` and `fkss_releases/` live above the
  web root and are gitignored → must be copied to the new server; APK
  download is streamed by the API itself (`api/v1/routes/app.php`,
  `GET /app/download`).
- Untracked production data: `admin/uploads/` (member photos, documents,
  profile images), backups, telemetry locks — git does not carry them;
  a server-to-server copy is required.
- PHP requirements (from CI and code): PHP 8.3/8.4 with `mysqli`, `curl`,
  `gd`, `mbstring`, `zip`, `fileinfo`, `sodium` (encrypted backups),
  `openssl`.

## MOBILE RELEASE MACHINERY (domain-cutover step)

- The version-sync set that must move together for the next APK:
  `Mobile/wbws_flutter_app/pubspec.yaml`, `lib/utils/config.dart`,
  `Mobile/wbws_flutter_app/RELEASE_NOTES.md`, `api/v1/core/app_release.php`,
  `api/v1/app_release.example.php`; pinned by
  `tests/security/test_auth_outbox_rollout_gates.py`,
  `test_comm_offline_first.py`, `test_mezmur_art.py`.
- The in-app updater asks the API host for newer versions — old installed
  apps can self-update only while the old domain still reaches a server.

## Not changed in Step 1 (by design)

Step 1 established the production repository, its CI and its workflow
documentation only. Application configuration, domains, API URLs, database
configuration, school configuration, application identifiers and deployment
mechanisms were left untouched, exactly as instructed.
