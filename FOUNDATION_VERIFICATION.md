# WBSS / FKSS — Foundation Verification & Gap Analysis

**Reviewer:** Senior PHP engineer
**Scope:** Verify the previous hardening pass, close gaps, harden the environment, add health-check + backup tooling, and rule on readiness for Phase B (refactoring).
**System:** Pure PHP + MySQL, cPanel shared hosting, single school, 5,000+ students, Flutter app on the API. No live data yet.

> **Headline:** The previous fixes are real and correct, **but verification found two genuine problems** that would have hurt at launch — one was a gap the earlier pass missed (the `/frontend/` finance page had login-only protection), and one was a **regression the previous pass introduced** in the root `.htaccess` (it would have blocked the `/backend/` shim URLs the finance module actually depends on). Both are now fixed in this commit. Details below.

---

# 1. PREVIOUS FIXES VERIFICATION REPORT

| # | Fix | Verdict | Notes |
|---|-----|---------|-------|
| 1 | `admin/access_control.php` central role guard, wired into `config.php` | ✅ PASS (with 3 additions) | Runs on every `/admin/` + `/backend/` request; exempts CLI, the mobile API (`WBWS_API_REQUEST`), and the public site. **Fail-closed:** a null/empty role can never match a role list (`in_array('', …, true)` is false). Login pages, logout, theme, and the mobile bridge are correctly public. See additions below. |
| 2 | Unauthenticated `print_member.php` closed | ✅ PASS | File loads `config.php` at the top → guard runs → `print_member.php` mapped to `[super_admin, school_admin, info_dept, edu_dept]`. Logged-out access now redirects to login. |
| 3 | Unauthenticated `info_manage_member.php` closed | ✅ PASS | Same mechanism; both its GET (render) and POST (update) paths are behind the guard. |
| 4 | Action-level guards in `api_education.php` / `api_subjects.php` | ✅ PASS | Verified the `$__manageActions` block sits before the `switch`. Teachers/attendance-takers can read + record grades/attendance but get 403 on `delete_class`, `promote`, `save_academic_year`, subject/assessment CRUD, etc. |
| 5 | QR directory permissions `0777 → 0755` | ✅ PASS | All three `mkdir(..., 0777)` calls in `generate_id_card.php` / `view_id_card.php` / `qr_diagnostic.php` are now `0755`. (A grep "hit" on 0777 remains only inside an explanatory comment.) |
| 6 | XSS escaping in `member.php` + info-dept table | ✅ PASS | `member.php` address now `e($address)`; the member table routes every field through a local `esc()` and passes the archive name via a `data-` attribute (no code interpolation). |
| 7 | Attendance save made transactional | ✅ PASS | `save_attendance` wraps DELETE+INSERT in `begin_transaction`/`commit` with `rollback` on failure; on error the user is told it was NOT saved and old data is intact. |
| 8 | `user-delete.php` transactional cleanup | ✅ PASS | Verifies password, then in one transaction removes teacher assignments and NULLs attribution on attendance/grades/logs/tasks/notifications, then deletes. Rolls back on any error. Ignores "missing table/column" so it works across deployments. |
| 9 | `updateAttendanceSummary` calendar unified | ✅ PASS | `workflow.php` now uses Gregorian `date('n')/date('Y')` and `date('Y-m-01')/date('Y-m-t')`, matching `api_attendance.php`. The old Ethiopian-vs-Gregorian key clash is gone. |
| 10 | Atomic academic-year switch | ✅ PASS | `set_current_year` is wrapped in a transaction; `save_academic_year` sets the new year current first and clears the others only after success (worst case two current years, never zero). |
| 11 | `sql/003_production_hardening.sql` | ✅ PASS | Indexes (safe), FKs with orphan pre-cleanup, collation + archiving sections. |
| 12 | `admin/tools/year_rollover.php` | ✅ PASS | Super-admin only, preview + typed `ROLLOVER` confirm, single transaction, carry-forward or promote-one-level. |

### Edge cases checked
- **Session expires mid-action:** `config.php` destroys the session and returns JSON `session_expired` (AJAX) or redirects (page) *before* the guard; a never-logged-in user is then denied by the guard. Consistent, no half-states. A logged-in user whose session dies mid-form gets a clean "please refresh" rather than a broken write (the CSRF check also rejects the stale token).
- **Null / unexpected role:** fail-closed everywhere (empty role matches no role list; unmapped admin pages still require login).
- **Partial session** (`admin_logged_in` set but `admin_role` missing): mapped pages deny (good); unmapped pages allow login-only (acceptable).

### ⚠️ Two problems found and FIXED in this commit

**GAP 1 — the `/frontend/` finance dashboard was login-only, not role-restricted.**
The central guard only covers `/admin/` and `/backend/`. But `dashboard.php` sends finance users to `/frontend/pages/finance_dept.php`, which is under `/frontend/` and so **bypassed the guard**; its only protection was `base.php` calling `requireAuth()` (login, not role). Any logged-in user could load the finance dashboard *shell* (the finance *data* was still safe behind `api_finance.php`).
**Fix:** `frontend/layouts/base.php` now enforces an optional `$requiredRoles` list, and `frontend/pages/finance_dept.php` sets `['super_admin','school_admin','finance_dept']`. Now non-finance users are redirected away.

**GAP 2 (regression introduced by the previous pass) — the root `.htaccess` would have broken the finance module.**
The previous pass's plan called the `/backend/` tree "dead," and this session's first draft of the hardened `.htaccess` added `RewriteRule ^backend/ - [F,L]`. But the frontend finance module **actively uses** `/backend/api/finance.php`, `/backend/api/members.php`, `/backend/auth/login.php`, and `/backend/auth/logout.php` (thin shims into `/admin/`). Blocking `/backend/` would have broken frontend **login and the entire finance dashboard**.
**Fix:** the `.htaccess` now blocks only `backend/migrations/`, not the shim tree. Access control for the shims is handled by the guard (which is keyed on both the `/admin/` name *and* the `/backend/` shim name). **Corrected dead-code classification in Section 6.**

**3 additions to the role map (so real cross-department calls don't 403):**
- Added `finance_dept` to `api_list_members.php` / `members.php` — the finance dashboard fetches the student roster to assign fees.
- Added `info_dept` to `api_attendance_info.php` — the info-dept member profile shows attendance.
- Left `api_settings.php` login-only (not role-restricted) — every role reads its own profile/settings from it.

---

# 2. ROLE-BY-ROLE TEST CHECKLIST

> For a non-technical tester. Create one test user per role (super admin does this in **Users**). Log in as each, click through its list, and confirm the "cannot access" URLs bounce you to the login/access-denied screen. Replace `SITE` with your domain.

### GLOBAL (every role)
```
[ ] G1. Log in with correct username + password → lands on the right dashboard
[ ] G2. Log in with WRONG password 5 times → gets "too many attempts" (rate limit)
[ ] G3. While logged OUT, open  SITE/admin/print_member.php?id=1  → bounced to login (NOT a record)
[ ] G4. While logged OUT, open  SITE/admin/info_manage_member.php?id=1  → bounced to login
[ ] G5. While logged OUT, open  SITE/admin/dashboard.php  → bounced to login
[ ] G6. Leave the page idle 30+ minutes, then click something → asked to log in again (no broken save)
[ ] G7. Log out → then press browser Back → cannot see the dashboard (session cleared)
```

### ROLE: super_admin
```
[ ] 1. Log in → Super Admin dashboard loads
[ ] 2. Users: create a user, edit a user, deactivate/reactivate, delete a user (with password confirm)
[ ] 3. After deleting a teacher who had class assignments → app still works, no crash on class lists
[ ] 4. Branding: upload logo/seal → saves
[ ] 5. Settings: change a setting → saves
[ ] 6. System Health page loads
[ ] 7. Academic Years: create year, set current, add terms
[ ] 8. Run Year Rollover tool (SITE/admin/tools/year_rollover.php) in PREVIEW → shows counts
[ ] 9. Members/Education/Finance/Material sections all reachable
[ ] LAST. Log out clears session (G7)
   Should NOT be blocked from anything (full access).
```

### ROLE: school_admin
```
[ ] 1. Log in → School Admin dashboard loads
[ ] 2. Can view members, education, attendance, reports
[ ] 3. Impersonate: switch to another department view, then "Back to School Admin" restores
[ ] 4. CANNOT open  SITE/admin/users.php  → access denied (super admin only)
[ ] 5. CANNOT delete users (user-delete is super-admin only)
[ ] 6. CANNOT open  SITE/admin/tools/year_rollover.php  → denied
[ ] LAST. Log out clears session
```

### ROLE: info_dept
```
[ ] 1. Log in → Information dept dashboard loads
[ ] 2. Register a new member (all field types; upload a photo + a PDF) → saves, gets a code
[ ] 3. Register the SAME child again → duplicate WARNING appears
[ ] 4. Edit a member → saves
[ ] 5. Archive a member, then Restore them
[ ] 6. View archived members list
[ ] 7. Print a member sheet (print_member.php) → opens
[ ] 8. Generate an ID card
[ ] 9. CANNOT open  SITE/admin/api_finance.php  → "permission" error (not finance data)
[ ] 10. CANNOT open  SITE/admin/dashboards/super-admin.php  → denied
[ ] 11. CANNOT open  SITE/admin/users.php  → denied
[ ] LAST. Log out clears session
```

### ROLE: edu_dept
```
[ ] 1. Log in → Education dept dashboard loads
[ ] 2. Classes: create/edit a class, set level order
[ ] 3. Enrollment: enroll a student, unenroll, transfer, bulk-enroll
[ ] 4. Promote a student to the next class
[ ] 5. Academic Years/Terms: create, set current
[ ] 6. Subjects: create/edit/delete a subject; assign subjects to classes
[ ] 7. Assessments: create an assessment; record grades
[ ] 8. Teachers: assign a teacher to a class
[ ] 9. CANNOT open  SITE/admin/api_finance.php  → denied
[ ] 10. CANNOT open  SITE/admin/users.php  → denied
[ ] LAST. Log out clears session
```

### ROLE: finance_dept
```
[ ] 1. Log in → redirected to  SITE/frontend/pages/finance_dept.php  → Finance dashboard loads
[ ] 2. Dashboard totals (income/expense/balance) load
[ ] 3. Add an income transaction; add an expense transaction
[ ] 4. Categories: create/edit a category
[ ] 5. Member fees: view the student roster, assign a fee to a student, mark paid
[ ] 6. Reports: view finance report; export
[ ] 7. CANNOT open  SITE/admin/api_education.php?action=delete_class  → denied
[ ] 8. CANNOT open  SITE/admin/dashboards/super-admin.php  → denied
[ ] 9. As a TEACHER (separate test), open  SITE/frontend/pages/finance_dept.php  → redirected away (role check)
[ ] LAST. Log out clears session
```

### ROLE: material_dept
```
[ ] 1. Log in → Material dept dashboard loads
[ ] 2. Add an inventory item; edit quantity; record incoming/outgoing
[ ] 3. Categories: create/edit
[ ] 4. Material requests: create, approve/deny
[ ] 5. CANNOT open  SITE/admin/api_finance.php  → denied
[ ] 6. CANNOT open  SITE/admin/api_education.php  → denied
[ ] LAST. Log out clears session
```

### ROLE: teacher
```
[ ] 1. Log in → Teacher dashboard loads
[ ] 2. See ONLY assigned classes
[ ] 3. Take attendance for a class → saves (and re-opening shows the saved marks)
[ ] 4. Record grades / submit a marklist
[ ] 5. View report card / class report
[ ] 6. CANNOT delete a class: open  SITE/admin/api_education.php?action=delete_class&class_id=1 → "Only the Education department…" 403
[ ] 7. CANNOT create/delete a subject (api_subjects manage actions) → 403
[ ] 8. CANNOT open  SITE/admin/api_finance.php  → denied
[ ] 9. CANNOT open  SITE/frontend/pages/finance_dept.php  → redirected away
[ ] LAST. Log out clears session
```

### ROLE: attendance_taker
```
[ ] 1. Log in → Attendance Taker dashboard loads
[ ] 2. Pick a class, take attendance → saves
[ ] 3. View a class's daily/monthly attendance
[ ] 4. CANNOT promote students or manage classes (api_education manage actions) → 403
[ ] 5. CANNOT open  SITE/admin/api_finance.php  → denied
[ ] LAST. Log out clears session
```

### ROLE: content_editor
```
[ ] 1. Log in → Content Editor dashboard loads
[ ] 2. Edit public website content (programs, schedule, teachers, gallery, social) → saves
[ ] 3. Upload a gallery image → saves
[ ] 4. CANNOT open  SITE/admin/api_finance.php  → denied
[ ] 5. CANNOT open  SITE/admin/users.php  → denied
[ ] LAST. Log out clears session
```

### PUBLIC (no login) — should WORK without a login
```
[ ] P1. SITE/               → landing page loads
[ ] P2. SITE/member.php?code=XXXXX  → member verification card shows (for a valid code)
[ ] P3. A student name/address containing < > characters shows as text, does NOT run as code
```

---

# 3. ENVIRONMENT HARDENING (complete, in this commit)

### 3.1 Error reporting — ✅ already correct (`config.php`)
Production settings are active: `error_reporting(E_ALL); ini_set('display_errors', 0); ini_set('log_errors', 1); ini_set('error_log', ROOT_PATH.'/error.log');` — errors are **logged, never shown**. Optional belt-and-braces for `.htaccess` (already covered by the code, add only if you want a hard stop):
```apache
php_flag display_errors Off
php_flag log_errors On
```

### 3.2 Secrets & credentials — ✅ FIXED (`config.php` + `env.example.php`)
**What was wrong:** if the secrets file was missing, `config.php` fell back to a **fixed** `JWT_SECRET` (`'FALLBACK_ONLY_env_file_missing'`) — a known value that would let anyone forge login tokens — plus a placeholder DB password.
**Fix:** `config.php` now **fails closed** — if no secrets file is found it stops with a clear "Setup required" page instead of running insecurely. A template, **`env.example.php`**, is provided. Deploy steps (also in the template):
1. Copy `env.example.php` to `/home/<youruser>/.fkss_env.php` (ONE level above `public_html`).
2. Fill in the real DB name/user/password, and three long random strings for `JWT_SECRET`, `BACKUP_KEY`, `HEALTH_KEY`.
3. `chmod 600 /home/<youruser>/.fkss_env.php`.
No database credentials remain hardcoded in any web-accessible file.

### 3.3 File permissions plan — run these on the live server
```bash
# From inside public_html (the project root):

# 1. Directories → 0755, files → 0644 (baseline)
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

# 2. Upload/cache/backup dirs stay 0755 (writable by the web user, NOT world)
chmod 755 admin/uploads admin/uploads/members admin/uploads/members/docs \
          admin/uploads/members/photos admin/uploads/cache admin/uploads/backups \
          admin/id_cards/assets/qr uploads uploads/gallery uploads/teachers

# 3. The secrets file (ABOVE the web root) → 0600 (owner only)
chmod 600 /home/<youruser>/.fkss_env.php

# NEVER use 0777 on anything.
```

### 3.4 Upload directory security — ✅ verified good
- `admin/uploads/.htaccess` **blocks PHP execution** (`FilesMatch \.ph(p[345]?|t|tml)$ → deny`, plus `php_flag engine off`) and blocks the `backups/` and `cache/` sub-folders and `.sql` files. Confirmed present.
- **Uploaded files are renamed to random names** — every upload path uses `fieldname_<timestamp>_<random 4 digits>.ext` (verified in `info_register_member.php`, `info_manage_member.php`, `api_cms.php`, `api_branding.php`). Original filenames are never used on disk.
- Extension whitelist + `getimagesize()` verification for images + 5 MB cap. (Belt-and-braces improvement for later: verify PDF content-type too.)

### 3.5 Public exposure — ✅ FIXED (complete root `.htaccess` in this commit)
The rewritten root `.htaccess` blocks: config files, `school_config.php`, `.env`/`*_env.php`, `.sql/.bak/.log/.md/.ini/.sh`, `error.log`, `env.example.php`, **`leak_detector.php`**, `qr_diagnostic.php`, `generate_hash.php`, `api/v1/debug_*`, the API test console, and the `admin/uploads/backups`, `admin/uploads/cache`, and `admin/migrations` paths. **It intentionally does NOT block the whole `/backend/` tree** (only `backend/migrations/`), because the finance module uses the `/backend/api/…` and `/backend/auth/…` shims.

### 3.6 Session security — ✅ already correct (`config.php`)
Verified: `session.cookie_httponly=1`, `session.use_only_cookies=1`, `session.cookie_secure=1` **auto-enabled on HTTPS** (correctly not forced on HTTP, which would kill sessions), `cookie_samesite=Lax`, `cookie_lifetime=0` (expires on browser close), and a 30-minute idle timeout. Login calls **`session_regenerate_id(true)`** (prevents session fixation). No "remember me" feature exists to get wrong. This block is solid as-is; no change needed.

---

# 4. HEALTH CHECK FILE — ✅ delivered: `admin/tools/health_check.php`
Complete, syntax-valid, self-contained. Key points:
- **Does NOT use the main login** (has its own `HEALTH_KEY`) and **does NOT load `config.php`**, so it still works when the app is broken — which is when you need it.
- Open at: `SITE/admin/tools/health_check.php?key=YOUR_HEALTH_KEY`.
- Reports: PHP version, secret + branding constants present, **DB connection**, counts of users/members/attendance/years, **exactly-one-current-year check**, disk free %, **last error-log line + time**, and **last backup file + age + size**.
- If the secrets file is missing it says so plainly (that's the #1 thing to know).

# 5. BACKUP SCRIPT — ✅ delivered: `admin/tools/backup.php`
Complete, syntax-valid, self-contained. Key points:
- Own key (`BACKUP_KEY`), runs from browser **or** cron; refuses to run if the key is unset/placeholder.
- **Streams row-by-row to disk** (unbuffered `MYSQLI_USE_RESULT`) so it does **not** run out of memory on a large database — this fixes the old `cron_backup.php` weakness.
- Saves to a folder **outside the web root** if writable, else the `.htaccess`-protected `admin/uploads/backups/` (and writes that `.htaccess` if missing).
- Keeps the **newest 7**, deletes older, logs to `activity_logs`, and the health page reads its output.
- Deletes a half-written file on error (never leaves a corrupt backup).

**cPanel cron line (daily at 2 AM):**
```
0 2 * * * /usr/local/bin/php /home/USERNAME/public_html/admin/tools/backup.php key=YOUR_BACKUP_KEY >/dev/null 2>&1
```
*(Replace `USERNAME` and `YOUR_BACKUP_KEY`. If `/usr/local/bin/php` doesn't exist on your host, try `/usr/bin/php`.)*

---

# 6. DEAD CODE LIST (corrected — for human review, do NOT auto-delete)

> **Correction from the earlier audit:** the `/backend/` tree is **NOT** fully dead — the finance module depends on several of its shims. Only the *unused* shims and true duplicates are dead. Reclassified below.

### SAFE to delete (verified unused)
| Item | Why safe |
|------|----------|
| `admin/migrations/002_migration.php`, `003_migration.php` | Byte-identical duplicates of `002_add_academic_attendance_workflow.php` / `003_add_assessments.php`. Keep one of each. |
| `backend/migrations/` (whole folder) | Byte-identical duplicate of `admin/migrations/`; also blocked by `.htaccess`. |
| `backend/id_cards/libs/phpqrcode/` | Duplicate of `admin/id_cards/libs/phpqrcode/`. |
| `backend/components/notification_bell.php` | Duplicate of `admin/components/notification_bell.php`. |
| `leak_detector.php` (root) and `admin/leak_detector.php` | Dev tool; identical copies; browser auth is broken (checks wrong session key `$_SESSION['role']`) so it denies everyone anyway; now also `.htaccess`-blocked. |
| Table `cache_storage` | Created by migrations, **never read or written** by app code. |
| Table `shared_documents` | Created by migration 002, **never read or written** (the "communication" module is marklists, not documents). |
| `index.html` (root, 715 lines) | Static old landing page; referenced by nothing; can shadow `index.php`. |

### PROBABLY SAFE (very likely unused, glance before deleting)
| Item | Why / caveat |
|------|--------------|
| `admin/dashboards/finance_department.php` | Superseded — `dashboard.php` redirects finance to `/frontend/pages/finance_dept.php`. The old admin dashboard is orphaned. Confirm no direct link remains. |
| `admin/dashboards/education_department.php` (57-line stub) | The live file is `edu_dept.php`. Confirm nothing links to the stub. |
| `admin/id_cards/qr_diagnostic.php` | Dev diagnostic; now `.htaccess`-blocked. Safe to remove once QR printing is confirmed working. |
| `FEATURE_*` constants in `school_config.php` | Defined but used in **zero** files — toggling them does nothing. Safe to remove, but harmless to keep; wiring them up is a separate task. |

### VERIFY FIRST (used, or possibly used — do NOT delete yet)
| Item | Why to keep for now |
|------|--------------------|
| `backend/api/finance.php`, `backend/api/members.php`, `backend/auth/login.php`, `backend/auth/logout.php` | **ACTIVELY USED** by the frontend finance module. Not dead. |
| Other `backend/api/*.php` shims (ai, attendance, education, subjects, teachers, …) | Not used by any migrated frontend **yet**, but reachable and now guarded. Leave until the frontend migration is finished or abandoned as a decision. |
| `themes/wbss/` | Inactive theme (active is `fkss`), but the branding/leak-detector story references it. Keep until branding is finalized. |
| `app/` (PWA) and `api/v1/` (JWT REST) | Consumed by the Flutter app. Not dead. |

### Uncalled functions (spot-check, not exhaustive)
A full function-level sweep needs a static-analysis tool (e.g. `phpstan`). No obviously-dead **public** helper was found in `config.php` (all of `e()`, `esc()`, `validate*`, `requireRole`, `jsonResponse`, etc. are used). Recommend running `phpstan --level=1` in Phase B to list truly-unreferenced functions safely.

---

# 7. FOUNDATION STATUS — ready for Phase B?

## Verdict: **YES — ready to proceed to Phase B (code-quality refactoring), with two conditions.**

The security and reliability foundation is now sound: access control is centralized and fail-closed and (after this pass) actually covers the finance module; the unauthenticated data leaks are closed; attendance and year-change operations are transactional; secrets fail closed; the environment (permissions, uploads, exposure, sessions, error handling) is hardened; and the operator now has a health check and a memory-safe backup with rotation. Verification did its job — it caught a real gap and a real regression that would otherwise have surfaced on launch day.

**Two conditions before/at the start of Phase B:**

1. **Deploy + run the manual test pass (Section 2) on a staging copy, especially the finance role.** The two issues found this round were both in the least-tested path (the half-migrated `/frontend/` + `/backend/` finance flow). I fixed them by code review, but I could not run the app against a database here. The finance login → dashboard → fees flow must be clicked through once before you trust it.

2. **Do the secrets + database steps first** (create `.fkss_env.php`, set a real DB password, apply `sql/003` Section A indexes). Until the env file exists, the site now intentionally refuses to start — that's correct, but it means the very first deploy step is creating that file.

**Why it's safe to refactor now:** refactoring (splitting god-files, unifying the five layers, proper autoloading) is exactly the Phase-2 work in `PRODUCTION_HARDENING_PLAN.md`. It's safe to begin because the behavior is now pinned down by a clear access-control map and transactional writes — you have a correctness baseline to refactor *against*. The one caution for Phase B: the `/backend/` shim tree is **live**, not dead, so the refactor must treat "finish or retire the frontend/backend migration" as an explicit decision, not silently delete those shims (that mistake was already made once this round and caught).

**Not blockers, but carry into Phase B** (from earlier audits, still open by design/timeline): member-list server-side paging, the mobile-API rate limit, and swapping the old in-memory `cron_backup.php` for the new streaming `admin/tools/backup.php`.
