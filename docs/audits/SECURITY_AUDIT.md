# WBSS / FKSS Sunday School Management System
## Deep Security Audit — Phase 2

**Auditor role:** Senior application security engineer
**Scope:** Security only (authn/session, SQLi, XSS, file upload, API, access control, data exposure, input validation)
**System:** PHP 8.2 + MariaDB 10.6, Apache/`.htaccess`, session-based admin panel + homemade-JWT mobile/REST API
**Data at risk:** ~5,000 minors' PII (names, DOB, photos, home addresses, guardian phones), attendance, financial/fee records, multi-role admin credentials
**Method:** Manual code review of every `admin/api_*.php`, `info_*.php`, public entry point, and the `api/v1` + `api_mobile` layers; query-construction tracing; output-encoding review; auth-guard mapping across all endpoints.

> **Headline:** The app has real security *foundations* — bcrypt password hashing, prepared statements used consistently (SQL injection is genuinely low), CSRF on web forms, hardened session cookies, and token auth on the mobile API. But it has **go-live-blocking access-control failures**: two endpoints expose every student's full record to **unauthenticated** visitors, and eleven more let **any logged-in user of any role** read/modify data they shouldn't (including financial records). Combined with a stored XSS in the main admin dashboard and security that rests entirely on `.htaccess`, this system is **not safe to go live as-is**.

---

# VULNERABILITY FINDINGS

## 1. AUTHENTICATION & SESSION SECURITY

### VULNERABILITY: Predictable API token-secret fallback + secret regenerated per request
**FILE:** `admin/api_mobile.php` (line 60), `api/v1/core/auth.php` (line 7), `config.php` (lines 82-85)
**SEVERITY:** HIGH
**WHAT CAN GO WRONG:** The mobile token secret falls back to `EXPORT_PREFIX . '_mobile_' . DB_NAME . '_' . DB_HOST` → literally `fkss_mobile_school_db_localhost`, and the REST API to `md5(DB_PASS)`, whenever the env file fails to load. An attacker who knows the school/DB names (all in the public repo and `school_config.php`) can **forge a valid token for any user id and role — including `super_admin`** — and drive the entire mobile/REST API. Separately, `config.php:82-85` generates a *random* `JWT_SECRET` on each request when env is missing, which silently invalidates every already-issued mobile token on the next request (auth "randomly" breaks with no diagnostic).
**QUICK FIX DIRECTION:** Refuse to start (hard fail) if `JWT_SECRET`/token secret is not present from a persistent secret store; never derive token secrets from guessable values; load the secret once from env and fail closed.

### VULNERABILITY: Hardcoded fallback database credentials in source
**FILE:** `config.php` (lines 47-55)
**SEVERITY:** MEDIUM
**WHAT CAN GO WRONG:** If the env file is missing, the app falls back to hardcoded `DB_USER='school_db'`, `DB_PASS='ENV_FILE_MISSING'`, `JWT_SECRET='FALLBACK_ONLY_env_file_missing'`. These are in version control. On any host where those defaults happen to work (or are copied during setup), credentials are effectively public. The current live system *is* running on fallback (`admin/backend/error_log` and `error.log` both record "CRITICAL: env file not found! Using hardcoded fallback").
**QUICK FIX DIRECTION:** Remove credential fallbacks entirely; fail closed with a generic 503 if secrets are absent. Keep secrets only in the out-of-webroot env file.

### VULNERABILITY: Session role is mutable in-session via impersonation
**FILE:** `admin/api_impersonate.php` (lines 29-53)
**SEVERITY:** MEDIUM
**WHAT CAN GO WRONG:** Role is stored in `$_SESSION['admin_role']` and rewritten with no re-authentication by the impersonation endpoint. It is correctly gated to `school_admin`/`super_admin` today, but because role lives entirely in a mutable session value, **any stored XSS running in an admin session (see §3) can call `api_impersonate.php` to switch roles at will**, and any future logic bug that lets a low role seed `original_admin_role` becomes privilege escalation.
**QUICK FIX DIRECTION:** Require password re-entry to impersonate; store an immutable "real role" server-side and derive effective role from it; log every switch.

### GOOD (no action): Login is fundamentally sound
`admin/backend/login.php` uses PDO prepared statements, `password_verify()` against bcrypt hashes, file-based rate limiting (5 / 5 min), `session_regenerate_id(true)` after login (prevents **session fixation**), and CSRF validation. `config.php` sets `session.cookie_httponly=1`, `session.use_only_cookies=1` (blocks URL-borne session IDs), `cookie_secure` on HTTPS, `SameSite=Lax`, and a 30-minute idle timeout. There is **no "remember me"** feature (nothing to get wrong). This is the strongest part of the system.

### VULNERABILITY: Idle timeout destroys session mid-form → lost work + confusing CSRF failure
**FILE:** `config.php` (lines 130-175)
**SEVERITY:** LOW (integrity/usability, not a breach)
**WHAT CAN GO WRONG:** A 30-minute idle timeout destroys the session; a long registration/edit form then POSTs into a dead session, fails CSRF, and silently loses all typed data with only a "please refresh" message.
**QUICK FIX DIRECTION:** Add periodic session keep-alive on active forms and client-side draft save; return an explicit re-auth prompt rather than a generic CSRF error.

---

## 2. SQL INJECTION

### Assessment: LOW — prepared statements are used consistently (a genuine strength)
Every user-value query path reviewed (`login.php`, `api_finance.php`, `api_reports.php`, `api_mobile.php`, `api_education.php`, `api_list_members.php`, `member.php`, `info_*`) uses **`prepare()` + `bind_param()`** for user input. Filter builders (e.g. `api_finance.php:82-90`, `api_mobile.php:186-188`) push all values through bound parameters; only `LIMIT` is interpolated and it is `(int)`-cast/clamped (`min((int)$_GET['limit'],500)`). No first-order SQL injection was found.

### VULNERABILITY: Dynamic SQL identifiers interpolated (latent, not currently exploitable)
**FILE:** `admin/api_reports.php` (line 165), `config.php` (633, 728), `admin/backend/groups_api.php` (85-92), `admin/dashboards/super-admin.php` (139-142)
**SEVERITY:** LOW
**WHAT CAN GO WRONG:** Table/column names are interpolated into queries (e.g. `SELECT DISTINCT \`$f\` ...`). Today `$f` iterates a **hardcoded whitelist** (`['city','sub_city','education_level','work_profession','woreda']`) so it is safe, but the pattern is fragile: the first time a developer routes a request parameter into one of these identifier slots, it becomes injection that prepared statements cannot catch.
**QUICK FIX DIRECTION:** Keep identifier sources on explicit allow-lists; add a helper that validates any dynamic identifier against a fixed set before use.

### VULNERABILITY: Verbose SQL errors returned to clients
**FILE:** `admin/info_manage_member.php` (199, 235, 273), `admin/api_education.php` (many `', '.$conn->error`), `admin/api_attendance.php` (135, 149), `admin/api_mobile.php` (214)
**SEVERITY:** MEDIUM
**WHAT CAN GO WRONG:** Endpoints echo `$conn->error` / `$e->getMessage()` in JSON. This leaks table/column names and query structure, accelerating any other attack and aiding blind injection attempts.
**QUICK FIX DIRECTION:** Log details server-side; return a generic error message + reference id to the client. (Production `display_errors` is already off — extend that discipline to hand-rolled error responses.)

---

## 3. XSS — CROSS-SITE SCRIPTING

### VULNERABILITY: Stored XSS in the Info-Dept member table (admin-context execution)
**FILE:** `admin/dashboards/info-dept.php` (`renderManageTable`, lines 2048-2065; `showDuplicateWarning`, lines ~4420-4430)
**SEVERITY:** HIGH
**WHAT CAN GO WRONG:** Member `student_name`/`father_name`/`grandfather_name` and `student_photo_path` are interpolated **raw** into `element.innerHTML` (`${m.student_name} ${m.father_name}`), with **no escaping** — even though this very file defines `escapeHtml()` at line 4021 and uses it elsewhere. Because input is **never sanitized** on the way in (see below), a member saved with a name like `<img src=x onerror=fetch('/admin/api_impersonate.php',...)>` executes JavaScript in the **Info-Dept / School-Admin / Super-Admin browser** that opens the manage-members list. That session can create users, change roles (via impersonation), export all data, or exfiltrate the CSRF token — full admin-panel takeover.
**QUICK FIX DIRECTION:** Escape every dynamic value at output (wrap in the existing `escapeHtml()`/`esc()`), consistently across all `innerHTML` builders; prefer `textContent` where possible.

### VULNERABILITY: Input is never sanitized (root cause amplifying all XSS)
**FILE:** `admin/info_register_member.php` (`field()` line 49), `admin/info_manage_member.php` (`field()` line ~42), `register_submit.php` (`f()` line 48); `config.php` `sanitize()` defined (256) but **called nowhere**
**SEVERITY:** HIGH (root cause)
**WHAT CAN GO WRONG:** All member/registration input is stored with only `trim()`. The provided `sanitize()` (which strips tags) is dead code — grep confirms zero call sites. Every consumer must therefore escape perfectly on output; where any one fails (§3 above, §3 below), it is a live stored-XSS sink.
**QUICK FIX DIRECTION:** Decide on a policy (store raw + escape-on-output is fine) and *enforce it* — centralize output through escaping helpers and add a lint/grep gate for raw `innerHTML` interpolation. Do not rely on the unused input sanitizer.

### VULNERABILITY: Stored XSS on the PUBLIC QR verification page (unescaped address)
**FILE:** `member.php` (line 206, `echo $address;`)
**SEVERITY:** HIGH
**WHAT CAN GO WRONG:** The public ID-verification page escapes most fields with `e()` but prints the constructed `$address` (from member `city`/`sub_city`/`woreda`/`house_number`/`address`) **unescaped**. A member record whose address contains markup executes script for **any unauthenticated visitor** who scans/opens that member's QR URL. Because member codes are sequential (`0001`, `0003`, `0004`…), the page is also trivially enumerable.
**QUICK FIX DIRECTION:** Escape `$address` with `e()`/`htmlspecialchars()` like the surrounding fields.

### VULNERABILITY: Attribute-context injection via photo path
**FILE:** `admin/dashboards/info-dept.php` (~line 4425, `src="${m.student_photo_path}"`)
**SEVERITY:** MEDIUM
**WHAT CAN GO WRONG:** `student_photo_path` is placed inside an HTML attribute in `innerHTML` without escaping; a crafted value can break out of the `src` attribute and inject handlers.
**QUICK FIX DIRECTION:** Escape attribute values; validate stored paths against an expected `uploads/...` prefix.

### GOOD (no action): Some panels already escape correctly
`content_editor.php` renders public registration leads through `esc()` (`${esc(s.full_name)}`), so the unauthenticated-public → admin lead path is mitigated at output. `edu_dept`, `finance`, `material`, `school_admin` define and use `esc()`. The problem is **inconsistency**, concentrated in the highest-traffic member dashboard.

### Reflected XSS: LOW
URL parameters are generally `(int)`-cast (`print_member.php`, `info_manage_member.php`) or escaped (`member.php` title uses `e($code)`). No significant reflected vector found.

---

## 4. FILE UPLOAD SECURITY

### VULNERABILITY: Upload execution defense is `.htaccess`-only; PDFs unverified; 0777 QR dir uncovered
**FILE:** `admin/info_register_member.php` (`saveUploadedFile()` 223-252), `admin/info_manage_member.php` (44-70), `admin/api_cms.php` (49-85), `admin/uploads/.htaccess`, `admin/id_cards/view_id_card.php` (158,173)
**SEVERITY:** HIGH
**WHAT CAN GO WRONG:** Baseline validation is decent — extension allow-list, `getimagesize()` content check for images, 5 MB cap, and unpredictable random filenames (`field_time_rand.ext`, no path traversal). **But:** (a) PDFs are accepted on **extension only** — a PHP payload saved as `x.pdf` passes; (b) the *only* thing preventing execution of a malicious upload is Apache `.htaccess` (`php_flag engine off` / `FilesMatch` deny) in `admin/uploads/`; (c) the ID-card QR directory is created **world-writable `0777`** under `admin/id_cards/` — a path **not** covered by the uploads no-exec rule. On any Nginx migration, lost `AllowOverride`, or missing module, uploaded content becomes executable → remote code execution.
**QUICK FIX DIRECTION:** Store uploads outside the web root (serve via a PHP proxy that sets `Content-Disposition`/type); verify PDF magic bytes; drop QR dirs to `0755`; add server-level (not just `.htaccess`) execution blocking as defense-in-depth.

### GOOD (no action): No original-filename or traversal risk
Filenames are regenerated server-side with `time()+rand`; original names are not used in paths.

---

## 5. API SECURITY (Flutter mobile + REST v1)

### VULNERABILITY: No rate limiting on the mobile login (brute-force bypass)
**FILE:** `admin/api_mobile.php` (auth/login, lines 112-139)
**SEVERITY:** HIGH
**WHAT CAN GO WRONG:** The web login is rate-limited, but the mobile `auth/login` endpoint has **no throttling at all**. An attacker can script unlimited username/password attempts against the same user table via the API, fully bypassing the web protection. With a 6-char minimum password policy, offline-speed online brute force is feasible.
**QUICK FIX DIRECTION:** Apply the same IP+account rate limiter to the API login; add exponential backoff/lockout and optional CAPTCHA after N failures.

### VULNERABILITY: No role/authorization checks on authenticated API endpoints
**FILE:** `admin/api_mobile.php` (switch at line 160; e.g. `data/members` 183, `attendance/save` 208, `sync/pull` 226)
**SEVERITY:** HIGH
**WHAT CAN GO WRONG:** All post-auth endpoints require only *a valid token*, not a specific role (only `data/classes` filters for teachers). Any authenticated app user — regardless of role — can page through **all members with phone numbers**, pull 500-member sync dumps, and **overwrite attendance for any class** (`attendance/save` deletes then re-inserts). A single low-privilege or leaked token grants broad read/write.
**QUICK FIX DIRECTION:** Enforce per-endpoint role checks against `$auth['rol']`; scope teacher/attendance-taker tokens to their assigned classes.

### VULNERABILITY: Bearer token accepted in URL query string; 30-day expiry, no revocation
**FILE:** `admin/api_mobile.php` (`_mGetAuth` line 90, `TOKEN_EXPIRY` line 61), `api/v1/core/auth.php` (30/90-day)
**SEVERITY:** MEDIUM
**WHAT CAN GO WRONG:** `_mGetAuth()` falls back to `$_GET['token']`/`$_POST['token']`, so tokens land in server access logs, browser history, and `Referer` headers. Tokens are valid 30 days (90-day refresh) with **no server-side revocation list**, so a leaked/stolen token cannot be invalidated short of rotating the global secret (which logs everyone out).
**QUICK FIX DIRECTION:** Accept tokens only via the `Authorization: Bearer` header; shorten access-token lifetime; add a server-side revocation/`token_version` per user for logout-all.

### VULNERABILITY: Over-broad CORS (`Access-Control-Allow-Origin: *`) on API
**FILE:** `admin/api_mobile.php` (line 38), `api/v1/.htaccess` (`Header set Access-Control-Allow-Origin "*"`)
**SEVERITY:** LOW (token auth, non-cookie) → MEDIUM if any cookie-authed data is ever added
**WHAT CAN GO WRONG:** Wildcard CORS is acceptable for pure Bearer-token APIs, but the `api/v1` `.htaccess` sets `*` unconditionally; if any endpoint there ever trusts the session cookie, it becomes cross-site readable.
**QUICK FIX DIRECTION:** Reflect only allow-listed origins; never combine `*` with credentialed requests.

### GOOD (no action): Token integrity + auth gating are sound
Tokens are HMAC-SHA256 signed and verified with `hash_equals()` (constant-time), expiry is checked, and every non-public endpoint calls `_mGetAuth()`/`apiRequireAuth()`. `profile/update` scopes to the caller's own `id` and cannot change role. The design is correct — the gaps are secret management, rate limiting, and role scoping.

---

## 6. ACCESS CONTROL & PRIVILEGE ESCALATION  ← most severe area

### VULNERABILITY: Unauthenticated full member-record disclosure (print view)
**FILE:** `admin/print_member.php` (whole file — `$_GET['id']` at line 11, `SELECT * FROM members` at 17, rendered with no auth)
**SEVERITY:** CRITICAL
**WHAT CAN GO WRONG:** The file `require`s config but performs **no login/role check whatsoever**. Anyone on the internet can request `/admin/print_member.php?id=1`, `?id=2`, … and receive a full A4 printout of each member: names, DOB, gender, phones, **guardian phone numbers, home address, uploaded documents**. IDs are sequential, so the entire database of ~5,000 minors' PII can be scraped in minutes with no credentials. This is a reportable data-protection breach.
**QUICK FIX DIRECTION:** Add `requireAuth()` + appropriate role at the top; verify the caller is allowed to view that member.

### VULNERABILITY: Unauthenticated member disclosure + raw SQL errors (manage view)
**FILE:** `admin/info_manage_member.php` (GET render at lines 281-287; touches **no** `$_SESSION` auth var anywhere)
**SEVERITY:** CRITICAL
**WHAT CAN GO WRONG:** Same class as above: `GET /admin/info_manage_member.php?id=N` renders the full member record with **no authentication**. The POST/edit path is partially shielded by CSRF (which needs a session token a stranger lacks), but the **GET disclosure is wide open**, and error branches leak raw `$conn->error`. Unauthenticated bulk PII exfiltration by id enumeration.
**QUICK FIX DIRECTION:** Add `requireAuth()` + role check at the very top (before any fetch/render); remove verbose DB errors.

### VULNERABILITY: Broken function-level authorization — 11 endpoints check login but not role
**FILE:** `admin/api_finance.php` (9), `admin/api_reports.php` (11), `admin/api_list_members.php` (12), `admin/api_attendance.php` (11), `admin/api_attendance_info.php` (14), `admin/api_subjects.php` (11), `admin/api_education.php` (13), `admin/api_material.php` (9), `admin/api_notifications.php` (15), `admin/api_check_duplicate.php` (12), `admin/info_register_member.php` (35)
**SEVERITY:** CRITICAL
**WHAT CAN GO WRONG:** These endpoints gate only on `$_SESSION['admin_id']`/`admin_logged_in` — **no role check** (verified: zero `hasRole`/`in_array($role,...)` in each). So the **lowest-privilege roles** (`attendance_taker`, `teacher`, `material_dept`) can:
- **`api_finance.php`** — read *and write* financial transactions (`add/update/delete_transaction`): a Sunday-school teacher can alter the money ledger.
- **`api_list_members.php` / `api_reports.php` / `api_attendance_info.php`** — dump all members (26 fields incl. addresses) and export full reports.
- **`api_education.php` / `api_subjects.php` / `api_attendance.php`** — enroll/**promote** students, **delete classes**, record grades, overwrite attendance, create/switch academic years.
- **`info_register_member.php`** — create arbitrary member records (and, via §3, inject stored XSS).
**QUICK FIX DIRECTION:** Add per-endpoint role authorization (e.g. `requireRole([...])`) mapping each action to the departments allowed to perform it; default-deny.

### VULNERABILITY: Login-only (any role) archive/restore/list of members
**FILE:** `admin/info_archive_member.php` (10), `admin/info_restore_member.php` (10), `admin/info_get_archived_members.php` (10)
**SEVERITY:** HIGH
**WHAT CAN GO WRONG:** These check only `$_SESSION['admin_username']` (logged in, any role) + CSRF. Any logged-in user can archive (soft-delete) or restore any member and list all archived members' PII. `info_get_archived_members` also returns full archived-member data to any role.
**QUICK FIX DIRECTION:** Restrict to `info_dept`/`school_admin`/`super_admin`.

### VULNERABILITY: Public enumeration of minors' photos + addresses via sequential QR codes
**FILE:** `member.php` (public; `?code=` lookup, renders photo/name/age/address)
**SEVERITY:** HIGH
**WHAT CAN GO WRONG:** The public verification page exposes each member's **photo, full name, age, and home address**, and member codes are sequential 4–5 digit numbers. An unauthenticated attacker can enumerate `?code=0001…9999` and harvest a photo-plus-home-address dataset of thousands of children — a serious child-safety/privacy exposure even though the page is "meant" to be public.
**QUICK FIX DIRECTION:** Show only minimal verification (name + validity status + photo), never the home address; make codes non-sequential (random/opaque token) or require the QR's signed token rather than a guessable code.

### GOOD (no action): Where role checks exist, they are correct
`users.php` (super_admin only), `admin/backend/user-save.php` (a `$rolePermissions` matrix controlling which roles may create which — prevents a school_admin minting a super_admin), `api_branding.php`/`api_cms.php`/`api_teachers.php`/`system_health.php` (explicit allow-lists), and `dashboard.php` (routes by role) are all properly gated. `api_settings.php` operates only on the caller's own `id` and re-verifies the current password before changes (no escalation). The problem is that this discipline was applied to *some* endpoints and not the rest.

---

## 7. DATA EXPOSURE

### VULNERABILITY: Real production PII database dump committed to the repository
**FILE:** `admin/uploads/backups/backup_2026-03-01_13-08-51.sql`
**SEVERITY:** CRITICAL
**WHAT CAN GO WRONG:** A live backup with real member rows (names, phones, home addresses, guardian details) and the `users` table is checked into git and shipped in every clone/deploy. Anyone with repo access — or who downloads it from the web root if `.htaccess` fails — has the whole dataset.
**QUICK FIX DIRECTION:** Purge from git history, rotate anything sensitive, add `admin/uploads/` to `.gitignore`, and keep backups off the web root.

### VULNERABILITY: `.htaccess`-only protection of backups, cache, config, and logs
**FILE:** root `.htaccess`, `admin/.htaccess`, `admin/uploads/.htaccess`, `admin/uploads/backups/`, `admin/uploads/cache/`
**SEVERITY:** HIGH
**WHAT CAN GO WRONG:** Daily SQL backups (with `password_hash` + PII), the login rate-limit cache, `config.php`, and `error.log` are inside the web root, protected only by Apache rewrite/`FilesMatch` rules. Any move to Nginx, `AllowOverride None`, or a disabled module makes them directly downloadable at guessable paths (`admin/uploads/backups/auto_backup_YYYY-MM-DD_HH-MM-SS.sql`).
**QUICK FIX DIRECTION:** Move secrets/backups/cache outside the web root; treat `.htaccess` as defense-in-depth only, not the sole control.

### VULNERABILITY: `admin/backend/error_log` is web-accessible (htaccess gap)
**FILE:** `admin/backend/error_log`, `admin/.htaccess` (matches `\.log$` / `error\.log$` — **not** `error_log`)
**SEVERITY:** MEDIUM
**WHAT CAN GO WRONG:** The filename `error_log` (no dot) is not matched by the log-blocking patterns, so it is downloadable. It currently reveals `CRITICAL: env file not found! Using hardcoded fallback` — telling an attacker the system is running on the known fallback secrets/credentials (see §1). The committed root `error.log` (64 KB) additionally contains SQL fragments and server paths.
**QUICK FIX DIRECTION:** Block by exact filename and directory; move logs out of web root; remove committed logs from the repo.

### GOOD (no action): Passwords are hashed correctly
`password_hash($pw, PASSWORD_DEFAULT)` (bcrypt) on creation (`user-save.php:183`, `api_settings.php:172`) and `password_verify()` on login (web + mobile). **No MD5/SHA1, no plaintext passwords** anywhere. This is done right.

---

## 8. INPUT VALIDATION

### VULNERABILITY: Member/registration input bypasses the validation helpers
**FILE:** `admin/info_register_member.php` (`field()` 49), `register_submit.php` (`f()` 48)
**SEVERITY:** MEDIUM
**WHAT CAN GO WRONG:** `config.php` provides solid server-side validators (`validateDate`, `validatePhone`, `validateAmount`, `validateEnum`, `safeInt`, `validatePassword`) and the **finance** module uses them well. But member registration collects names/address/profession with `trim()` only — no length caps (beyond a single `full_name > 150` check and DB column limits), no character validation, and no sanitization. Oversized/malformed input relies entirely on the database to reject it, and free-text fields feed the XSS sinks in §3.
**QUICK FIX DIRECTION:** Route all member fields through explicit server-side validation (length, allowed characters, enum for gender/type); enforce consistently, not just in finance.

### VULNERABILITY: Weak password policy (6 characters, no complexity)
**FILE:** `config.php` (`validatePassword` 378-387)
**SEVERITY:** MEDIUM
**WHAT CAN GO WRONG:** Minimum 6 characters, no complexity/breached-password check. Combined with the **unthrottled mobile login** (§5), admin accounts are realistically brute-forceable.
**QUICK FIX DIRECTION:** Raise to ≥12 chars, check against common/breached lists, and pair with API rate limiting/lockout.

### GOOD (no action): Numeric IDs are consistently `(int)`-cast
`(int)$_GET['id']`, `(int)$_POST['class_id']`, etc. are used throughout, which both prevents type confusion and blocks injection via id parameters.

---

# EXECUTIVE SUMMARY

## CRITICAL SECURITY ISSUES — must fix BEFORE go-live
1. **Unauthenticated full member PII disclosure via `print_member.php?id=N`** (§6) — sequential-id scrape of all ~5,000 minors' records, no login required.
2. **Unauthenticated member disclosure via `info_manage_member.php?id=N`** (§6) — same, plus raw SQL error leakage.
3. **Broken function-level authorization on 11 endpoints** (§6) — any logged-in role can read/write **financial records**, dump all member PII, delete classes, promote students, and overwrite attendance.
4. **Real PII database backup committed to the git repo** (§7) — full member + user dataset in version control and (if `.htaccess` fails) on the web.

## HIGH SECURITY ISSUES — fix within the first week
5. **Stored XSS in the Info-Dept member dashboard** (§3) — member name → script execution in admin sessions → panel takeover.
6. **Stored XSS on the public QR page** via unescaped address (§3), and **input never sanitized** (root cause).
7. **No rate limiting on the mobile API login** (§5) — brute-force bypass of the web protection.
8. **No role scoping on authenticated mobile API endpoints** (§5) — one token = broad read/write.
9. **Predictable/regenerating API token secrets** (§1) — forgeable `super_admin` tokens when env is missing.
10. **Upload execution & sensitive-file exposure rest solely on `.htaccess`** (§4, §7), incl. `0777` QR dir and extension-only PDF acceptance → RCE/data-exposure if Apache overrides are lost.
11. **Login-only archive/restore/list of members** and **public enumeration of children's photos+addresses** (§6).

## MEDIUM / LOW — fix within the next month
- Hardcoded DB/JWT fallback credentials in source (§1, MEDIUM).
- Verbose SQL/exception errors returned to clients (§2, MEDIUM).
- Bearer token accepted in URL; long-lived, non-revocable tokens (§5, MEDIUM).
- `admin/backend/error_log` web-accessible; committed `error.log` with SQL/paths (§7, MEDIUM).
- Member input bypasses validators; weak 6-char password policy (§8, MEDIUM).
- Session-mutable role via impersonation (§1, MEDIUM); attribute-context photo-path injection (§3, MEDIUM).
- Over-broad CORS on the API (§5, LOW); idle-timeout data loss (§1, LOW); latent identifier-interpolation SQL pattern (§2, LOW).

## OVERALL SECURITY RATING: **3 / 10**

**Honest explanation.** The cryptographic and query fundamentals are better than typical for this class of app: **bcrypt** password hashing, **prepared statements used consistently** (SQL injection is genuinely a low risk here), **CSRF tokens** on web forms, **hardened session cookies** with fixation protection, and **HMAC token auth** on the mobile API. Those strengths are real and keep this out of 1–2 territory.

But security rating is governed by the worst reachable outcome, and here that outcome is severe and **requires no authentication**: any visitor can enumerate and download the complete PII of thousands of children through `print_member.php` / `info_manage_member.php`, and any *logged-in* user of the lowest role can read and modify **financial records** and academic data because eleven endpoints check only that you are logged in, not who you are. A stored XSS in the main dashboard escalates any low-privilege or malicious member record into full admin-panel compromise, and the entire upload/secret/backup protection layer depends on `.htaccess` alone. A production PII dump is even committed to the repository.

These are not theoretical: they are direct, trivially exploitable breaches of minors' personal data and of the financial ledger. **This system must not go live until at least the four CRITICAL items are fixed** — realistically that is a focused few days of work (add auth/role guards, purge the committed backup, escape output), because the foundations to build on are already present. Re-rate after the CRITICAL and HIGH items are closed; with those resolved this is plausibly a 6–7/10.
