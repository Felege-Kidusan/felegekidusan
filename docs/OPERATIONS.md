# Operations: deployment, backups, mobile release, monitoring

**Status:** canonical. **Verified against:** `main` at `46fbaa7` and the deployment of that commit on 2026-10-22.

This repository is public. Host names, ports, accounts, server paths and network addresses are deliberately **omitted**; the owner holds them. Never add them here.

## 1. Environments

| Environment | Branch | Notes |
|---|---|---|
| Production | `main` | Shared hosting: LiteSpeed, MariaDB, PHP 8.5 (observed from the server error log on 2026-10-23; earlier records say 8.2 and 8.4, and the host can change it without notice). It sits behind a CDN/WAF proxy. File access is SFTP only; there is no shell, Composer or cron for the application, and no Python or Redis |
| Beta | `dev` | A separate server and a separate beta Android app. Only beta settings live on `dev`; see [BRANCHING.md](../BRANCHING.md) |
| Staging for production | — | **None.** Changes are verified locally (section 2) and then deployed |

## 2. Code deployment (manual SFTP)

This is the procedure actually used, most recently for `46fbaa7` on 2026-10-22, when 89 files were uploaded and all 89 checksums matched. **Deploy only with the owner's explicit approval.**

1. **Choose the commit.**
   - The commit must be on `main` and pushed to both repositories.
   - Record the CI result honestly (see [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md#3-current-ci-status)).
   - Run the relevant local tests against a test database.
2. **List the changed files.** Run `git diff --name-only <deployed-commit>..<target-commit>`.
   - Exclude files that exist only on the server: the secrets file, server-only `.htaccess` additions, and server-only folders.
   - Exclude anything outside the release, such as docs and tests if they are not served.
3. **Migrations.** If the change includes `sql/` files, the owner reviews them and applies them in phpMyAdmin. Follow the order stated in the change (usually migrations before code), then record them (see [DATABASE.md](DATABASE.md#4-applied-state-in-production-unverified)).
4. **Back up the server copy.**
   - Download the current server version of **every file that will be overwritten** into a dated local folder.
   - Record each file's checksum, and note which files are new.
5. **Upload** only the listed files over SFTP, preserving paths.
   - **Deleted files are not removed by an upload.** For every file the release deletes (`git diff --name-status` shows `D`), move the server copy into a dated backup folder outside `public_html` instead of deleting it, so it can be put back.
   - Confirm the server folders in [section 2.1](#21-server-folders-that-git-does-not-create) exist.
6. **Verify.**
   - Recompute checksums of the uploaded server files and compare them with the commit; every file must match.
   - Smoke-check the staff login page (`/admin/index.php`) and the mobile configuration endpoint (`/api/v1/app/config`).
   - Check the error monitor.
7. **Record** the deployed commit, the file list, the checksum result and any migrations in the owner's deployment log.

### 2.1 Server folders that Git does not create

Some folders are deliberately ignored by Git (`.gitignore`) but the code needs them at runtime. A fresh server, or a server set up by copying files, will not have them. Create them over SFTP with permission `755`, owned by the hosting account.

| Folder | Needed by | What breaks without it |
|---|---|---|
| `admin/id_cards/assets/qr/` | ID-card generation and QR renewal (`admin/id_cards/generate_id_card.php`, `IdentityCodeService`) | ID-card generation answers "ID-card generation is temporarily unavailable" and the error log says "The deployment-managed ID-card QR directory is unavailable". This happened on production on 2026-10-23: the folder had never been created there, and it was created that day |

The wider list of writable folders is in [`audits/DEPLOYMENT_RUNBOOK.md`](audits/DEPLOYMENT_RUNBOOK.md) ("Stage 3 — File permissions"). It has not been re-verified against production.

**Deployed version:** the repository does not record what is deployed, and nothing on the server reliably records it either. The owner's deployment record is authoritative. Do not infer the deployed version from server-side files.

## 3. Rollback

- **Code:** re-upload the files saved in step 4 and delete any files that were new in the release. Then repeat the verification in step 6.
  - Rollback **has not been rehearsed** as a drill.
  - A rollback package was prepared for the 2026-10-22 deployment but not executed.
- **Database:** migrations are forward-only; there are no down-migrations.
  - Additive migrations usually tolerate a code rollback, but this must be checked for each change.
  - Data changes can only be reversed from a database backup (section 4). No database restore has been tested.
- **Server configuration:** any production-only configuration change must have its prior copy saved before the change, so that it can be put back.

## 4. Backups

| Item | State (2026-10-22) | Evidence level |
|---|---|---|
| Application database backups | `BackupService` creates encrypted dumps (`BACKUP_ENCRYPTION_KEY`) at `BACKUP_STORAGE_PATH`. Recent encrypted backup files exist in the hosting account | Verified: file listing only |
| Location | **The same hosting account as production.** There is **no off-site copy** | Verified |
| Restore test | **Never performed.** No backup is known to be restorable | — |
| Hosting-provider backups | Unknown frequency, retention and restore procedure | Unverified |
| Key custody | Without `BACKUP_ENCRYPTION_KEY` the backups are unusable. Where it is held outside the server is not documented | Unverified |

**Required (not yet done):**
1. Make a regular off-site copy of the encrypted backups.
2. Record where the backup key is held, with two custodians.
3. Run a restore test every quarter, into a disposable database, with the result recorded.

Until a restore succeeds, treat backups as **unproven**.

## 5. Mobile release

1. **Build** with `Mobile/wbws_flutter_app/scripts/build-release.ps1`, which produces the universal APK. Follow `Mobile/wbws_flutter_app/docs/RELEASE.md`.
2. **Publish:** the super admin uploads the APK and sets the update policy in the web app-release manager (`admin/api_app_release.php`). Devices read `/api/v1/app/config`.
3. **Version state:**
   - `main` contains **1.7.3+40**.
   - On 2026-10-22, production served **1.6.10 (build 36)** as the latest app.
   - 1.7.3 has not been uploaded; uploading it needs the owner's decision.
4. **Signing (risk):**
   - Without `android/key.properties`, Gradle falls back to the **debug** signing configuration. The APK distributed in production is debug-signed.
   - Android only installs an update that has the same signature as the installed app. Losing the build machine's debug keystore therefore stops in-place updates for every device.
   - Switching to a proper release keystore requires every device to uninstall and reinstall. That would also erase unsynced offline data.
   - A key-custody decision and a planned migration are needed. See `RELEASE.md` §3 and the handover risk list.
5. **Old-app compatibility:** before releasing server changes, check that installed older builds keep working, or are told to update. The legacy-token window for builds below 16 ended on 2026-09-24.

## 6. Monitoring and incident response

| Capability | State |
|---|---|
| Error monitor | `monitor/` (error monitor and dashboard). The dashboard requires a super admin session that is revalidated against the database (`monitor/index.php`) |
| Telegram failure alerts | Implemented (`FailureAlertService`). Production configuration unverified |
| Failure intelligence and mobile telemetry | Super admin dashboards (`api_failure_intelligence.php`, `api_telemetry.php`) |
| Uptime checks | `monitor/uptime_cron.php` needs cron. Whether a cron job is configured is unverified; there may be **no** external uptime monitoring |
| On-call / escalation | None documented |

**Incident response (current, informal):**
1. The owner is the single point of contact.
2. Contain the problem by rolling back the code (section 3) or disabling the feature through its `FEATURE_*` flag in the secrets file.
3. Record what happened in `docs/` (see `docs/INCIDENT_2026-10-03_TRACKING_SCHEMA.md` for an example).

Security incidents follow [SECURITY.md](SECURITY.md). A written incident-response plan with roles and contact paths does not exist yet.
