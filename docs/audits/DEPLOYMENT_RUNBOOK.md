# 🚀 Go-Live Runbook — WBSS / FKSS

**Read this ONE document to deploy.** It puts every "what you must do" step from the audit reports in the exact order to do them. Each step says what to do and how to confirm it worked. Do them top to bottom. Don't skip.

> **Build 24 authorization/outbox release:** also complete the mandatory staged
> migration, build-23 compatibility, background-drain containment, device-drill,
> and forward-only SQLite v34 gates in
> [`AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md`](AUTH_OUTBOX_BUILD24_ROLLOUT_RUNBOOK.md).
> Its stop conditions supersede generic rollback advice for this release.

> You have other documents for detail (`FOUNDATION_VERIFICATION.md`, `PRODUCTION_HARDENING_PLAN.md`, the three audits). You do **not** need to read them to deploy — this runbook is the checklist. They're reference if something is unclear.

---

## STAGE 0 — Before you touch the live server (15 min)

```
[ ] 0.1  Make a full backup of the CURRENT live database (cPanel → phpMyAdmin →
         Export), even if you think there's no real data yet. Keep it somewhere safe.
[ ] 0.2  Make a copy of the current live files (cPanel → File Manager → compress
         public_html to a zip, download it). This is your undo button.
```

---

## STAGE 1 — Secrets file (the site won't run without this) (15 min)

The system now **refuses to start** without a secrets file — that's on purpose (it used to run with a guessable security key). Create it once.

```
[ ] 1.1  In cPanel → MySQL Databases, note your database NAME, USER, and PASSWORD.
         If you don't know the password, create a new one there and save it.

[ ] 1.2  In cPanel → File Manager, go UP one level ABOVE public_html
         (usually /home/YOURUSER/). Create a new file named:   .fkss_env.php

[ ] 1.3  Open the project file  env.example.php  (in public_html), copy ALL its
         contents into your new .fkss_env.php, and fill in:
            - DB_NAME, DB_USER, DB_PASS  → the real values from step 1.1
            - JWT_SECRET, BACKUP_KEY, HEALTH_KEY  → three DIFFERENT long random
              strings. Generate them in cPanel → Terminal with:
                 php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
              (run it three times, paste a different result into each).
            - WRITE DOWN the BACKUP_KEY and HEALTH_KEY — you'll need them later.

[ ] 1.4  Set its permission to 0600:  in File Manager right-click .fkss_env.php →
         Change Permissions → 600 (only the owner boxes ticked).

[ ] 1.5  CONFIRM: open your site. It should load normally. If you see
         "Setup required: secrets file missing", the file is in the wrong place
         or misnamed — recheck step 1.2.
```

---

## STAGE 2 — Deploy the new code (10 min)

```
[ ] 2.1  Upload/pull the new project files to the live server (all the files in
         this branch). If using Git on the server:  git pull

[ ] 2.2  CONFIRM the site still loads and you can reach the login page at
         SITE/admin/index.php
```

---

## STAGE 3 — File permissions (10 min)

In cPanel → Terminal, from inside `public_html`:

```bash
# Baseline: folders 755, files 644
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

# Public generated-QR directory is deployment-owned; requests never create it.
install -d -m 755 admin/id_cards/assets/qr

# Public asset/upload folders stay 755 (never 777). Private member files and
# encrypted backups are created with 0700/0600 outside public_html by the app.
chmod 755 admin/uploads admin/uploads/members admin/uploads/members/photos \
          admin/uploads/cache admin/id_cards/assets/qr uploads uploads/gallery \
          uploads/teachers
```
```
[ ] 3.1  Run the commands above.
[ ] 3.2  If admin/id_cards/assets/qr/ was ever set to 777, it's now 755 — good.
```

---

## STAGE 4 — Database hardening (15 min)

```
[ ] 4.1  In phpMyAdmin, select your database → SQL tab.
[ ] 4.2  Open the project file  sql/003_production_hardening.sql. Copy SECTION A
         (indexes) and run it. This is the important speed fix. If any line says
         "Duplicate key name", that's harmless — keep going.
[ ] 4.3  Apply sql/004_year_lifecycle.sql through
         sql/016_member_duplicate_lookup.sql IN NUMERIC ORDER. These migrations
         are re-runnable and own all schema needed by the matching application
         code; normal web/API requests intentionally never repair schema. Run
         014 during this maintenance window because building its FULLTEXT index
         can briefly lock a large members table. 015 makes attendance status
         explicitly required and 016 adds the duplicate-lookup index; both are
         idempotent. 008, 009 and 010 are required BEFORE the new code is
         deployed: without 010 the mobile/REST login and token refresh return
         503 by design (safe, but the app cannot authenticate). The migrations
         are safe to run while the old code is still live (new tables/indexes
         only), so do this stage before pulling the new files.
[ ] 4.4  (Can wait to week 1, but better now) Copy SECTION B of
         sql/003_production_hardening.sql (foreign keys) and run it. It cleans up
         bad rows first, then adds the keys.
[ ] 4.5  Confirm the directory indexes exist:
         SHOW INDEX FROM members WHERE Key_name IN
         ('idx_members_status_id','idx_members_tier_id',
          'idx_members_archive_type_id','ft_members_directory');
         SHOW INDEX FROM class_enrollments
         WHERE Key_name = 'idx_ce_member_year_status_id';
[ ] 4.6  (P66 hymn art — release phase7-art01) Run
         sql/040_mezmur_hymn_art.sql (guarded and idempotent — safe on a
         live database, old or new code keeps working either way because
         every reader is probe-guarded). It adds the five art_* columns to
         mezmur_hymns plus idx_mz40_art_status. The Mezmur console's
         "Sync DB schema" button applies the same columns. Verify with
         admin/api_mezmur.php?action=ping — it must report
         code_version phase7-art01 with no missing_columns. Full design:
         docs/mezmur_player/HYMN_ART_P66.md.
```

---

## STAGE 5 — Health check + backups (20 min)

```
[ ] 5.1  Open the health check using HTTP Basic authentication. Do NOT put the
         key in the URL (URLs are logged). With curl:
            curl -u health:YOUR_HEALTH_KEY https://SITE/admin/tools/health_check.php
         Confirm: Database = available (green), and the counts look sane.

[ ] 5.2  Log in as Super Admin → Backup & Data → Create Encrypted Backup.
         Confirm an encrypted `.sql.gz.ssb` file appears. New backups are kept
         outside public_html and the newest seven are retained.

[ ] 5.3  Re-run the health check (5.1) — "Last backup" should report an
         encrypted backup created minutes ago.

[ ] 5.4  Set the DAILY automatic backup in cPanel → Cron Jobs:
            0 2 * * * /usr/local/bin/php /home/YOURUSER/public_html/admin/tools/backup.php >/dev/null 2>&1
         The local CLI process is trusted; never put BACKUP_KEY in a cron command
         or URL. If that PHP path errors, try /usr/bin/php.

[ ] 5.5  Perform a restore drill to a NEW file outside public_html, then import
         it into an empty staging database (never overwrite production first):
            php admin/tools/backup.php --decrypt=BACKUP_NAME --output=/home/YOURUSER/restore-test.sql
         Preserve BACKUP_KEY securely: without it encrypted backups cannot be restored.
```

### Profile Image Orphan Cleanup

The profile-image service writes normalized JPEGs to private atomic storage and
stores only logical `private://profiles/...` references in the database. A
failed process or post-commit deletion can leave an unreferenced final image or
a staging file behind. `admin/backend/profile_image_cleanup.php` is the
CLI-only maintenance command that reclaims those artifacts after the fixed
24-hour grace period.

#### Private storage and execution boundary

The cleanup uses the same configured private root as profile-image operations,
in this order:

1. `PROFILE_PRIVATE_STORAGE_PATH`, when configured;
2. `MEMBER_PRIVATE_STORAGE_PATH/profiles`, when configured; or
3. the fallback `ssms_private/profiles` directory beside the project/web root.

For this production installation the application root is
`/home/arkeonet/felegekidusan.arkeonethiopia.com`, so the unoverridden fallback
resolves to `/home/arkeonet/ssms_private/profiles`. If either trusted server
constant overrides that location, verify the configured absolute path instead.
The root must remain outside the public web root and writable only by the
application/maintenance account. The command refuses web execution and must
never be invoked with `curl`, a query-string key, or an HTTP scheduler. It
accepts no client path, filename, user ID, or storage-root argument.

Create or verify the private directory as the application/maintenance account,
never from a web request:

```bash
umask 077
install -d -m 0700 /home/arkeonet/ssms_private
install -d -m 0700 /home/arkeonet/ssms_private/profiles
stat -c '%a %U %G %n' /home/arkeonet/ssms_private /home/arkeonet/ssms_private/profiles
find /home/arkeonet/ssms_private/profiles -maxdepth 1 -type f -printf '%m %f\n'
```

Both directories must be mode `700` and owned by the deployment account.
Normalized JPEGs, staging files, and `.profile-cleanup.lock` must be mode `600`;
the service applies that file mode when it creates them. Correct ownership as a
hosting operation if needed, but never use `chmod 777` or make the root
web-readable.

Before scanning storage, the command verifies the migrated nullable
`users.profile_image_path` column and enumerates every non-empty value from that
authoritative column. A missing schema, database error, or failed reference
query exits with code `1` before cleanup; never bypass that fail-closed gate.

The repository provides the CLI reaper but cannot install or prove a cPanel or
hosting cron. Until the following cron is installed and observed on the actual
host, cleanup is deployment-dependent and is **not runtime-verified**.

#### Required daily cron

First obtain the actual CLI PHP binary on that hosting account:

```bash
command -v php
php -v
```

`/usr/local/bin/php` below is only the common cPanel value. It **must** be
replaced with the exact path printed by `command -v php` when they differ.
Install this daily entry in cPanel → Cron Jobs:

```cron
17 3 * * * umask 077 && /usr/local/bin/php "/home/arkeonet/felegekidusan.arkeonethiopia.com/admin/backend/profile_image_cleanup.php" >> "/home/arkeonet/profile_image_cleanup.log" 2>&1
```

The log deliberately lives at `/home/arkeonet/profile_image_cleanup.log`,
outside the application and public web root. Do not redirect it into the
repository, `admin/uploads`, or any other web-served directory. Configure
host-level log rotation/retention for this file if required.

#### Manual pre-cron verification

Run from the actual deployed project root:

```bash
cd "/home/arkeonet/felegekidusan.arkeonethiopia.com"
command -v php
php -v
php -m | grep -Fx mysqli
php admin/backend/profile_image_cleanup.php
echo $?
```

Replace the three `php` invocations with the absolute path returned by
`command -v php` if the cron will use that absolute binary. A successful,
lock-owning run must:

- print one valid aggregate JSON object;
- exit with code `0`;
- report `"lock_acquired":true`; and
- expose no private path, image filename, user ID, credentials, token, or image
  contents.

Expected output shape (counts will vary):

```json
{"lock_acquired":true,"deleted_orphans":0,"deleted_temporaries":0,"skipped_referenced":0,"skipped_fresh":0}
```

`lock_acquired:false` with exit code `0` means another cleanup process owns the
lock. It is safe, but it does not prove that this invocation scanned storage;
wait for the other process to finish and retry once.

#### Verify the first scheduled execution

After the first 03:17 run, execute:

```bash
stat "/home/arkeonet/profile_image_cleanup.log"
tail -n 1 "/home/arkeonet/profile_image_cleanup.log"
grep -c '^Profile-image cleanup failed\.$' "/home/arkeonet/profile_image_cleanup.log"
```

Confirm all of the following:

1. the log modification time is later than the cron installation time and
   matches the expected scheduled window;
2. the final line is valid aggregate JSON with `lock_acquired:true`;
3. only the five documented fields are present;
4. the log remains outside
   `/home/arkeonet/felegekidusan.arkeonethiopia.com`; and
5. the failure count did not increase for that run.

The final log line plus the log modification time is the authoritative status
for the latest scheduled run. When that line is valid JSON, its modification
time is the latest successful execution time and the object provides the run
result, deleted-final count, deleted-temporary count, skipped-referenced count,
and skipped-fresh count. A generic failure line instead marks the latest run as
failed, and the `grep` result provides a retained failure count. The command
does not maintain a separate historical last-success registry; use the
outside-web-root log and the hosting cron history when investigating an older
run. No public status endpoint or web-readable status file is required or
permitted.

#### Failure response

A failure prints the generic line `Profile-image cleanup failed.` to stderr and
exits with code `1`. If that occurs:

1. disable only this cron entry to stop repeated failures;
2. run the manual sequence above as the same hosting user;
3. verify database connectivity and that the profile-image migration is
   present;
4. verify the configured private root exists and is readable/writable by that
   user;
5. verify the selected binary is CLI PHP and has the required mysqli support;
6. check the server's private PHP error log for the detailed operator error;
7. correct permissions/configuration without using `chmod 777`; and
8. rerun manually until exit `0` and valid aggregate JSON are observed, then
   re-enable the cron.

Do not bypass a reference-enumeration failure, lower the 24-hour grace period,
remove the cleanup lock, follow symlinks, or point the script at a
client-supplied directory.

#### Security and rollback

The scheduled user should be the normal application/maintenance account, not a
public web request and not an unnecessarily privileged system user. Keep the
private root and cleanup log inaccessible over HTTP. The cron command must not
contain database credentials, tokens, backup keys, image names, or user IDs.

To roll back the scheduled operation, remove or disable only the cron entry.
Do **not** delete the private storage root or run a broad filesystem deletion.
Existing database references and image files remain valid when the cron is
disabled. Retain or securely remove the outside-web-root operational log under
the hosting retention policy. Reinstall the same cron after the operational
issue is resolved.

---

## STAGE 6 — Security spot-checks (10 min)

```
[ ] 6.1  While LOGGED OUT, open  SITE/admin/print_member.php?id=1
         → must bounce you to login (NOT show a student). 
[ ] 6.2  While LOGGED OUT, open  SITE/admin/info_manage_member.php?id=1
         → must bounce to login.
[ ] 6.3  Open  SITE/admin/uploads/backups/  → must be "Forbidden".
[ ] 6.4  Open  SITE/error.log  → must be "Forbidden" (or Not Found).
[ ] 6.5  Force any error while browsing → you see a friendly message, never PHP
         code or file paths.
```

---

## STAGE 7 — Role testing (30–45 min) — the most important step

Use the **ROLE-BY-ROLE TEST CHECKLIST** in `FOUNDATION_VERIFICATION.md` (Section 2).

```
[ ] 7.1  Create one test user per role (Users page, as super admin).
[ ] 7.2  Log in as EACH role and run its checklist. Pay special attention to:
           - finance_dept: log in → finance dashboard loads → can add a
             transaction → can see the student roster for fees.
           - teacher: can take attendance and it SAVES (re-open shows the marks);
             but CANNOT delete a class (should get a "permission" message).
[ ] 7.3  Confirm each role is BLOCKED from other departments' pages (the "CANNOT"
         lines in the checklist).
[ ] 7.4  Confirm logout, then Back button, cannot see the dashboard.
```

**If anything in Stage 7 fails, STOP and note exactly which role + which URL.** That's the highest-risk area (the access-control map) and is worth getting right before real users arrive.

---

## STAGE 8 — First real academic year (only when you're ready to enroll)

```
[ ] 8.1  Education → Academic Years → create the current year, set it current.
[ ] 8.2  Create your classes and enroll students normally.
[ ] 8.3  For NEXT year (in ~12 months): use  SITE/admin/tools/year_rollover.php
         (super admin). Preview first, back up first, then run "carry forward".
         Do NOT start a new year by hand — the tool keeps enrollments intact.
```

---

## ✅ You are live when…
- Stages 1–7 are all ticked.
- The health check is green.
- A backup file exists and the daily cron is set.
- The Profile Image Orphan Cleanup cron is installed and its first scheduled
  aggregate JSON result is verified from the outside-web-root log.
- Every role passed its test checklist.

## Still open (safe to launch, handle after) — from the audits
- **Editable member sync batches**: XLSX round trips remain intentionally bounded to 2,000 rows; use the complete streaming CSV export for large read-only extracts.
- **Legacy cron path**: `admin/backend/cron_backup.php` is now a CLI-only compatibility adapter to the same encrypted streaming service. New schedules should use `admin/tools/backup.php`.
- **Code cleanup (Phase B)**: only after the above is stable and tested on a staging copy.

## If something breaks
1. You have the file zip (0.2) and DB export (0.1) — restore them to undo.
2. Check the health check page — it usually points at the problem (DB down, disk full, env missing).
3. The real error detail is in the server error log (not shown to users, by design).
