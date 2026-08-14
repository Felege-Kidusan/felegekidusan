# 🚀 Go-Live Runbook — WBSS / FKSS

**Read this ONE document to deploy.** It puts every "what you must do" step from the audit reports in the exact order to do them. Each step says what to do and how to confirm it worked. Do them top to bottom. Don't skip.

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

# Writable folders stay 755 (never 777)
chmod 755 admin/uploads admin/uploads/members admin/uploads/members/docs \
          admin/uploads/members/photos admin/uploads/cache admin/uploads/backups \
          admin/id_cards/assets/qr uploads uploads/gallery uploads/teachers
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
[ ] 4.3  (Can wait to week 1, but better now) Copy SECTION B (foreign keys) and
         run it. It cleans up bad rows first, then adds the keys.
```

---

## STAGE 5 — Health check + backups (20 min)

```
[ ] 5.1  Open in your browser (use YOUR HEALTH_KEY from step 1.3):
            SITE/admin/tools/health_check.php?key=YOUR_HEALTH_KEY
         Confirm: Database = connected (green), and the counts look sane.

[ ] 5.2  Run a manual backup once (use YOUR BACKUP_KEY):
            SITE/admin/tools/backup.php?key=YOUR_BACKUP_KEY
         Confirm it prints "BACKUP OK" with a file name and size.

[ ] 5.3  Re-open the health check (5.1) — "Last backup" should now show your
         backup file, minutes old.

[ ] 5.4  Set the DAILY automatic backup: cPanel → Cron Jobs → add, once per day:
            0 2 * * * /usr/local/bin/php /home/YOURUSER/public_html/admin/tools/backup.php key=YOUR_BACKUP_KEY >/dev/null 2>&1
         (If that PHP path errors, try /usr/bin/php. Replace YOURUSER and the key.)
```

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
- Every role passed its test checklist.

## Still open (safe to launch, handle after) — from the audits
- **Member list speed** at a few thousand students: works, but gets slow. Server-side paging is the week-2 fix.
- **Old `admin/backend/cron_backup.php`**: replaced by `admin/tools/backup.php` (this one is memory-safe). You can ignore/remove the old cron once the new one runs.
- **Code cleanup (Phase B)**: only after the above is stable and tested on a staging copy.

## If something breaks
1. You have the file zip (0.2) and DB export (0.1) — restore them to undo.
2. Check the health check page — it usually points at the problem (DB down, disk full, env missing).
3. The real error detail is in the server error log (not shown to users, by design).
