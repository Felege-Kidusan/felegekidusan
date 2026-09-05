# WBSS / FKSS — Production Hardening Plan & Implemented Fixes

**Author:** Senior PHP architect
**Audience:** Non-technical founder + AI assistant
**Goal:** Get the system safe to go live for 5,000+ students within one week.
**Companion audits:** `AUDIT_PHASE1_STRUCTURAL.md`, `SECURITY_AUDIT.md`, `FUNCTIONAL_BUG_AUDIT.md`

---

## HOW TO READ THIS DOCUMENT

- **Phase 1** — the critical fixes. Most are **already implemented in this commit** as complete, working files. Each says plainly what was wrong and what changed, and whether you need to do anything.
- **Phase 2** — how to tidy the folder structure *after* go-live (a plan, not urgent).
- **Phase 3** — the exact database commands to run (indexes, keys) and the new year-rollover tool.
- **Phase 4** — a numbered checklist to tick off before you flip the switch.

A short **"What YOU must do"** box appears wherever a fix needs an action from you (running SQL, deleting a file, setting a password). If there's no box, the fix is already done in the code.

---

# PHASE 1 — CRITICAL FIXES (before go-live)

> **What "implemented" means:** the code is written and syntax-checked in this commit. You still need to **deploy the files** (upload/pull them to the live server) and do the few manual steps flagged in the boxes.

### FIX 1 — Locked down every admin page and API with one central guard ✅ implemented
**Files:** `admin/access_control.php` (new), `config.php` (one line added at the end)
**What was wrong:** `print_member.php` and `info_manage_member.php` had **no login check at all** — anyone on the internet could open `print_member.php?id=1`, `?id=2`, … and read every child's full record (name, date of birth, guardian phone, home address). Separately, 11 API endpoints only checked "are you logged in?" not "are you allowed?", so a teacher could read/write the **finance ledger** or **delete a class**.
**What I fixed:** Added one file, `admin/access_control.php`, that is loaded automatically by `config.php` on every request. It is the single place that decides who may open which page. It (a) forces a login on every admin page, and (b) checks the user's role against an allow-list per page (finance pages → finance staff + admins only, class management → education staff, user management → super admin only, and so on). The public website, the mobile app API, and cron jobs are deliberately exempt so nothing else breaks. Teachers and attendance-takers are still allowed into the education endpoints for grading and reading, but the destructive actions (delete class, promote, change academic year) are blocked for them by an extra check inside `api_education.php` and `api_subjects.php`.
**Why central instead of editing 13 files:** one guard = one place to get right and one place to audit, far safer than 13 separate edits that each risk a typo.

> **What YOU must do:** nothing to configure. Just deploy the files. After deploying, log in as each role once and confirm the dashboards still load (see Phase 4 checklist).

---

### FIX 2 — Removed the world-writable (0777) folders ✅ implemented
**Files:** `admin/id_cards/generate_id_card.php`, `admin/id_cards/view_id_card.php`, `admin/id_cards/qr_diagnostic.php`
**What was wrong:** The ID-card code created folders with permission `0777` (writable by anyone/anything on the server) inside the website folder. A world-writable web folder is a classic way for an attacker to plant and run a malicious script.
**What I fixed:** Changed every `0777` to `0755` (owner can write, others can only read) and removed the "chmod 777" advice from the messages.

> **What YOU must do:** on the live server, if the folder `admin/id_cards/assets/qr/` already exists with 777, reset it: `chmod 755 admin/id_cards/assets/qr/`.

---

### FIX 3 — Stopped shipping real student data inside the code repository ✅ implemented
**Files:** `.gitignore` (new); removed from git tracking: the committed DB backup, log files, the rate-limit cache, and uploaded student files.
**What was wrong:** A real database backup (`admin/uploads/backups/backup_2026-03-01_…sql`) containing real names, phone numbers, home addresses and the user table was committed into the code repository — anyone with repo access had the whole database.
**What I fixed:** Added a `.gitignore` that permanently excludes backups, logs, cache, uploaded student files, and secret env files, and removed those items from version control (the files stay on your server; they just stop travelling inside the code).

> **What YOU must do (important):** the old backup still exists in the repository's *history*. To fully remove it, after deploying, rotate anything sensitive and consider purging history. At minimum: **change the database password** and delete the backup file from the server's `admin/uploads/backups/` folder. (Full history purge with `git filter-repo` is a Phase-2 task; see the note in Phase 4.)

---

### FIX 4 — Closed the stored-XSS holes in names and address ✅ implemented
**Files:** `member.php` (public QR page), `admin/dashboards/info-dept.php` (member table)
**What was wrong:** A student's name/address was printed into the page without escaping. If someone registered a child with a "name" containing a hidden script, that script would run — on the public verification page, and (worse) inside the **admin's** browser when they opened the member list, which could hijack the admin session.
**What I fixed:** The public page now escapes the address; the member table now escapes every name/code before displaying it, and the archive button passes the name safely via a data-attribute instead of building it into code.

> **What YOU must do:** nothing. Deploy the files.

---

### FIX 5 — Attendance saving can no longer wipe a class's day ✅ implemented
**File:** `admin/api_attendance.php`
**What was wrong:** Saving attendance first **deleted** the whole class's records for that day, then re-inserted them one by one — with no safety wrapper. If the save was interrupted (timeout, two teachers at once), the delete happened but the inserts didn't, and the day's attendance was **gone** while the teacher saw "saved".
**What I fixed:** Wrapped the delete-and-reinsert in a single all-or-nothing transaction. If anything fails, the old data is kept untouched and the teacher is told it wasn't saved.

> **What YOU must do:** nothing. Deploy the file.

---

### FIX 6 — Attendance totals no longer scramble across years ✅ implemented
**File:** `admin/backend/workflow.php`
**What was wrong:** There were two copies of the "update monthly attendance summary" routine using **different calendars** — one Ethiopian, one Gregorian — writing to the same table. The Ethiopian one also searched the wrong date range and found nothing. Result: the same month could be counted twice or split, quietly corrupting attendance history.
**What I fixed:** Made both copies use the same (Gregorian) month/year, matching how dates are actually stored, so they always agree.

> **What YOU must do:** nothing. (Optional: after go-live, have staff spot-check one class's monthly attendance number looks right.)

---

### FIX 7 — Deleting a user no longer breaks the records they touched ✅ implemented
**File:** `admin/backend/user-delete.php`
**What was wrong:** Deleting a user ran a bare delete with no cleanup, leaving broken links: the ex-teacher's class assignments still pointed at a missing user, their recorded attendance/grades lost their author, tasks/notifications became ghosts.
**What I fixed:** Deletion now runs inside one transaction that first removes the teacher's assignments and sets the author fields on attendance/grades/logs/tasks to "none" (keeping the history), then deletes the user. All-or-nothing.

> **What YOU must do:** nothing. Deploy the file.

---

### FIX 8 — Changing the academic year is now safe ✅ implemented
**File:** `admin/api_education.php`
**What was wrong:** Switching the current year cleared the old flag and set the new one in two separate steps. If the second step failed, the system was left with **no current year**, and attendance/enrolment/grades silently stopped working.
**What I fixed:** The "set current year" switch now runs as one transaction (all-or-nothing). The "save year" screen sets the new year first and only then clears the others, so the worst case is two current years (harmless — the app always takes the first) instead of zero.

> **What YOU must do:** nothing. Deploy the file. (For starting a whole new school year, use the new tool in Phase 3.)

---

### CRITICAL items that are configuration, not code — YOU must do these

These were flagged CRITICAL/HIGH in the audits and cannot be "fixed in a file" — they are server settings:

> **A. Put the database password in the secret env file (not in code).**
> `config.php` falls back to hard-coded credentials if the secret file is missing, and the logs show it *is* currently missing (so it's using the fallback, and the mobile API token secret becomes random every request). Create `/home/<user>/.fkss_env.php` **above** the public web folder with:
> ```php
> <?php
> define('DB_HOST', 'localhost');
> define('DB_NAME', 'your_real_db_name');
> define('DB_USER', 'your_real_db_user');
> define('DB_PASS', 'your_real_db_password');
> define('JWT_SECRET', 'paste-a-long-random-string-here'); // keep this stable & secret
> define('BACKUP_KEY', 'another-long-random-string');
> ```
> Then confirm the site loads and the error log no longer says "env file not found".

> **B. Confirm error display is OFF in production.** Already set in `config.php` (`display_errors = 0`). Just verify the live site never shows PHP errors to visitors.

> **C. Confirm `.htaccess` is active.** All upload-execution blocking and backup hiding depends on Apache honouring `.htaccess`. Verify by trying to open a backup URL — it must return "Forbidden".

---

### CRITICAL item that CANNOT be safely finished in one week — go in with eyes open

**Member list performance (the "first thing to break").** `api_list_members.php` returns *every* student in one response and the browser draws them all at once. The **index fix in Phase 3 makes the database query fast**, which buys real headroom, but the *full* fix (server-side paging + search) requires reworking the Info-Dept screen's JavaScript and is too risky to rush before launch.
**Risk of going live without the full fix:** with a few thousand students the member list page will get slow to load and sluggish to search (several seconds), though it will still work. It is a performance annoyance, **not** data loss or a security hole.
**Recommendation:** ship with the indexes now; schedule the paging rework for week 2. (Direction: change `api_list_members.php` to accept `?page=&search=` and `LIMIT/OFFSET`, and change the Info-Dept table to fetch pages and search on the server.)

---

# PHASE 2 — ARCHITECTURE CLEANUP PLAN (after go-live, not urgent)

The system currently has **five overlapping layers** and many duplicate files. Do **not** restructure during launch week — it's change for its own sake at the worst time. Here is the target and the safe path to it.

### Target folder structure
```
/public_html
  /public          ← things the internet may hit directly
     index.php         (landing page)
     member.php        (QR verification)
     register_submit.php
     /assets           (css, js, images)
  /admin           ← the admin panel (login required)
     dashboard.php, index.php, logout.php
     /pages            (info-dept, edu, finance… the screens)
     /api              (api_*.php — the endpoints)
     /tools            (year_rollover.php, migrations)
  /includes        ← shared PHP, never hit directly
     config.php, school_config.php, access_control.php
     /lib              (workflow.php, ethiopian_date.php, member_sync.php)
     /auth             (login.php, guard)
  /storage         ← OUTSIDE the web root ideally
     /uploads          (student photos/docs)
     /backups
     /cache
     /logs
  /vendor          ← third-party libs (phpqrcode)
```

### What to merge / split / delete
- **Delete the duplicate `/backend` tree.** `backend/api/*`, `backend/migrations/*`, `backend/id_cards/*`, `backend/components/*` are byte-for-byte copies or thin shims of the real `admin/` files. They double the attack surface and cause "I fixed it but it's still broken" bugs. *(Safe to delete once you confirm nothing links to `/backend/…` URLs — the app uses `/admin/…`.)*
- **Delete duplicate migrations:** `002_migration.php` = `002_add_academic_attendance_workflow.php`; `003_migration.php` = `003_add_assessments.php`. Keep one of each.
- **Delete `index.html`** (a stale static copy that can shadow the real `index.php`).
- **Delete dead files:** `admin/dashboards/finance_department.php` (replaced by `frontend/`), `admin/dashboards/education_department.php` (stub), `admin/id_cards/qr_diagnostic.php` (dev tool).
- **Split the "god files" later:** `admin/dashboards/info-dept.php` (5,161 lines) and `config.php` (900 lines) should each be broken into smaller includes — but only well after launch, one screen at a time.

### Autoloading / include strategy (no framework, no breakage)
You don't need Composer. Add **one** file, `includes/bootstrap.php`, that every entry page includes first:
```php
require_once __DIR__ . '/config.php';          // DB, session, helpers, access guard
require_once __DIR__ . '/lib/workflow.php';    // shared functions
```
Then migrate pages to include `bootstrap.php` instead of reaching across folders with `../../`. Do this **gradually**: keep the old `require_once __DIR__ . '/config.php'` working (it already does), and move files into the new layout one at a time, testing each. Nothing has to change all at once.

---

# PHASE 3 — DATABASE PRODUCTION HARDENING

### 3.1 Indexes and foreign keys — run the provided SQL file
**File:** `sql/003_production_hardening.sql` (complete, ready to run)
It has four clearly-labelled sections:
- **Section A — Indexes (run first, always safe).** These are the **most important go-live database fix**: today the members table has no index on `status`, so every member list is a full scan. Section A adds indexes on the columns the app filters and joins on (member status/gender/class, attendance by class+date, enrolments, grades).
- **Section B — Foreign keys.** Adds the missing integrity rules on finance/teacher/material/grade tables. It **cleans up orphaned rows first**, then adds each key, so it won't fail on existing bad data.
- **Section C — Collation fix (optional).** Makes all tables use the same text collation so cross-table joins don't error.
- **Section D — Archiving (optional, read the comments).** Creates `_archive` tables and shows how to move a closed year's attendance/grades out of the live tables so they stay fast.

> **What YOU must do:** Take a full backup, then in phpMyAdmin run **Section A** first (safe), then **Section B**. Sections C and D can wait.

### 3.2 Year-to-year transition — use the new tool
**File:** `admin/tools/year_rollover.php` (new, complete)
This is the missing "close one year, open the next" mechanism. Open it as a super admin at `/admin/tools/year_rollover.php`. It:
1. Shows a **preview** and requires you to type `ROLLOVER` to confirm (no accidental runs).
2. Makes the chosen year current **atomically** (never leaves zero current years).
3. Moves every active, non-archived student into the new year, either **carry-forward** (same class — recommended, so rosters aren't empty) or **promote one level** (top level → graduated).
4. Marks the old year's enrolments completed.
5. Runs entirely inside **one transaction** — it all works or nothing changes.

> **What YOU must do:** Test it once on a copy/staging with the current data before the real September rollover. Always back up first. (Direction if you don't have staging: create next year in Education → Academic Years, then run the tool in "carry forward" mode.)

### 3.3 Archiving strategy (the long game)
- Attendance grows ~250,000 rows/year and never shrinks. Use **Section D** of the SQL file after each year-end to move the finished year's attendance and grades into `_archive` tables. The live tables stay small and fast; the archive tables remain for historical reports.
- Prune `activity_logs` to the last 12 months (also in Section D).
- Keep 7 daily backups (the existing `cron_backup.php` already rotates them) — but see the note below.

> **Known limitation (document, fix week 2):** the backup script builds the whole dump in memory and will fail silently once the database is large. Until it's switched to a streaming `mysqldump` cron, **verify the daily backup file actually appears and has a sensible size** each week.

---

# PHASE 4 — GO-LIVE CHECKLIST

Tick every box before flipping the switch. Grouped by "must" and "should".

### MUST — do not go live until these are true
1. [ ] **Secret env file created** above the web root with the real DB password + a long stable `JWT_SECRET` (Phase 1, box A). Error log no longer says "env file not found".
2. [ ] **Database password changed** from whatever was in the old committed backup.
3. [ ] **Old backup file deleted** from `admin/uploads/backups/` on the server, and `.gitignore` deployed.
4. [ ] **All Phase 1 code files deployed** (access_control.php, config.php, the id_cards files, member.php, info-dept.php, api_attendance.php, workflow.php, user-delete.php, api_education.php, api_subjects.php).
5. [ ] **Access control tested per role:** log in as super_admin, school_admin, info_dept, edu_dept, finance_dept, material_dept, teacher, attendance_taker — each dashboard loads, and each is **blocked** from another department's page (e.g. a teacher opening `api_finance.php` gets "permission" error, not data).
6. [ ] **Unauthenticated test:** while logged out, open `/admin/print_member.php?id=1` and `/admin/info_manage_member.php?id=1` — both must redirect to login, **not** show a record.
7. [ ] **Indexes applied:** `sql/003_production_hardening.sql` Section A run successfully.
8. [ ] **Error display OFF:** a forced error shows a friendly message, never PHP code/paths, to a normal visitor.
9. [ ] **.htaccess honoured:** opening a backup URL or a `.sql` file returns "Forbidden".
10. [ ] **Folder permissions:** `admin/id_cards/assets/qr/`, `admin/uploads/…` are `0755`, not `0777`.
11. [ ] **HTTPS on** and session cookies secure (the config auto-enables secure cookies on HTTPS).

### SHOULD — do in the first week after go-live
12. [ ] **Foreign keys applied:** `sql/003_production_hardening.sql` Section B run successfully.
13. [ ] **Year rollover tested** on a data copy (Phase 3.2).
14. [ ] **Backup verified working:** a daily backup file appears with a realistic size (Phase 3.3 note).
15. [ ] **Delete the duplicate `/backend` tree** and dead files (Phase 2) after confirming nothing links to them.
16. [ ] **Member-list paging** rework scheduled (the deferred CRITICAL performance item).
17. [ ] **Attendance spot-check:** confirm one class's monthly attendance % looks correct.
18. [ ] **Mobile login rate-limit** added (mobile API currently has no brute-force limit — HIGH from the security audit).
19. [ ] **Git history purge** of the old backup (full removal beyond `.gitignore`), if the repo is shared.

### NICE TO HAVE — within the first month
20. [ ] Collation fix (SQL Section C).
21. [ ] Archiving cron set up (SQL Section D).
22. [ ] Replace the in-memory backup with streaming `mysqldump`.
23. [ ] Begin splitting the two "god files" (`info-dept.php`, `config.php`).
24. [ ] Add server-side length validation on free-text fields.

---

## HONEST BOTTOM LINE

With **Phase 1 deployed + the MUST checklist done**, the system moves from "unsafe to launch" to "safe to launch with known, managed limitations." The launch-blocking holes — unauthenticated student data, any-role finance access, silent attendance loss, the year-change trap, and the exposed backup — are **closed in this commit** or covered by a clearly-boxed manual step.

Two things are honestly **deferred, with low risk if managed:**
- **Member-list speed** at scale — mitigated by indexes now, fully fixed by paging in week 2. Worst case is a slow screen, not lost data.
- **Backup at scale** — works now, needs the streaming switch before the database gets large; mitigated by verifying the backup file weekly.

Everything else in the three audits is either fixed here, scripted for you (SQL + rollover tool), or scheduled on the checklist. Do the MUST list, and you can go live.
