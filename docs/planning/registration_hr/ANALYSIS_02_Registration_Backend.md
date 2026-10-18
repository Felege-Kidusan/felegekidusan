# DEEP ANALYSIS — Registration Backend (Files 2–6)

**Mode:** ANALYSIS ONLY — no code modified.
**Files covered:** `admin/hr_register_member.php`, `admin/backend/services/MemberRegistrationPolicy.php`, `admin/backend/services/MemberDuplicateService.php`, `admin/info_manage_member.php` (POST path), `admin/config.php` (auth/CSRF/session subset).

---

## FILE 2 — `admin/hr_register_member.php` (683 lines)

### Purpose
POST endpoint for new member registration AND promotion (upgrade) of temporary→permanent. Returns JSON.

### Execution path
1. `ob_start()` + header JSON (line 13-14).
2. Require config + services (15-22).
3. Load ethiopian_date (24-28).
4. `jsonExit()` helper (34-55) — cleans buffers, completes/abandons idempotency reservation, sets HTTP code, exits.
5. `registrationCanonicalize()` (58-64) — recursive ksort for canonical hashing.
6. `beginRegistrationIdempotency()` (66-132) — validates request id (16-80 chars, `[A-Za-z0-9._-]`), hashes payload+files, begin reservation; replay/conflict/processing handling.
7. Auth: admin_id session, POST method, CSRF, DB (134-146).
8. Policy prepare (148-155).
9. `field()` helper (160-169) — trim; quick_add restricts fields.
10. Collect/validate data (176-310).
11. Uploads via MemberFileService (312-360).
12. Promotion requires signed form (362-368).
13. Transaction: duplicate lock + strong match + insert/update (370-532).
14. Audit + commit (534-557).
15. Post-commit: PositionSync, class enrollment, workflow (559-617).
16. Response (619-624).
17. Catch: rollback, release lock, discard files, map DB errors (626-683).

### Security findings
- **S1 (GOOD):** All DB access via prepared statements with bind_param. No SQL injection via user input (column names are hardcoded array keys).
- **S2 (GOOD):** CSRF validated before any processing (line 141).
- **S3 (GOOD):** Session auth (line 135).
- **S4 (GOOD):** Role gating for uploads/upgrade delegated to policy (allow_uploads, allow_upgrade).
- **S5 (GOOD):** Idempotency prevents double-submit; request hash includes payload+file hashes.
- **S6 (GOOD):** On failure, uploaded files discarded (lines 630-634); on upload-error path, discarded (line 349).
- **S7 (GOOD):** Error messages generic; full error logged server-side (line 635). No stack trace to client.
- **S8 (GOOD):** `DuplicateMemberException` returns match data — verify match data doesn't leak sensitive fields. It returns id, member_code, status, name. Acceptable.
- **S9 (RISK — stored XSS):** `field()` returns trimmed raw strings; no HTML escaping. Values stored raw in DB. If any UI renders these without escaping (e.g., `innerHTML`), XSS possible. Need to verify all output sites escape. (See form/UI analysis.)
- **S10 (RISK — no input length caps on most fields):** `field()` does not cap length; only policy `text()` caps full_name_am, phone, current_section. Other fields (city, woreda, mender, etc.) have no length validation → could exceed column limits → DB error (handled as generic). Not a security issue but robustness.
- **S11 (RISK — no rate limiting):** Endpoint can be spammed with new registration_request_ids. Idempotency only dedups identical requests. Consider rate limiting per admin_id.
- **S12 (INFO):** `member_code_form = field('student_id')` is collected but never used (dead code). Minor.
- **S13 (INFO):** `$_POST['position_ids']` validated via filter_var with min_range 1. Good.
- **S14 (INFO):** `$_POST['upgrade_member_id']` cast to int. Good.
- **S15 (INFO):** `$_POST['class_id']` cast int. Good.
- **S16 (INFO):** `$_POST['reg_date_*']` cast int. Good.
- **S17 (GOOD):** Upgrade path unsets registered_at/waiting_since/member_code/registration_type → preserves original. Good.
- **S18 (GOOD):** `membership_tier` from policy defaults 'permanent' for non-quick-add. Good.
- **S19 (RISK):** `preg_match('//u', ...)` trick for UTF-8 validation — works but obscure. Fine.
- **S20 (GOOD):** Transaction rollback + lock release in catch. Good.

### Conditional logic
- quick_add (school_admin or registration_profile=quick_add): restricts uploads (allow_uploads=false), forces member_type=regular, status=active, tier=permanent, gender defaults male, requires ≥2 name parts. Good.
- upgrade requires allow_upgrade (super_admin/hr_dept, non-quick-add) + signed form. Good.

---

## FILE 3 — `admin/backend/services/MemberRegistrationPolicy.php` (106 lines)

### Purpose
Role-aware validation/capability policy. Defines registration contract.

### Findings
- **S1 (GOOD):** Allow-lists for registration_type, member_type, status, gender, tier. enum() rejects unknown.
- **S2 (GOOD):** text() validates UTF-8, caps length, collapses whitespace, rejects control chars.
- **S3 (GOOD):** phone regex restricts to digits/symbols.
- **S4 (RISK):** `quickAdd` determined by role OR input flag `registration_profile=quick_add`. A non-school_admin user could pass `registration_profile=quick_add` in POST to get quick_add behavior (fewer required fields, no uploads). **Check:** is this intended? The policy treats any caller sending that flag as quick_add. If the form doesn't send it for HR, HR is not quick_add. But a malicious HR user could send it to bypass uploads/validation. **Recommendation:** gate quick_add strictly on role (school_admin), ignore the input flag or require a separate capability. (Potential privilege/flow bypass.)
- **S5 (RISK):** `current_section` capped at 100 chars but not validated against a known section list. Minor.
- **S6 (INFO):** `full_name_am` capped at 150. Good.
- **S7 (INFO):** No validation that `membership_tier='temporary'` is only allowed for non-quick-add (it is, via TIERS + quick_add forces permanent). Good.

---

## FILE 4 — `admin/backend/services/MemberDuplicateService.php` (270 lines)

### Purpose
Strong-identity duplicate detection + advisory advisory matches + advisory locking.

### Findings
- **S1 (GOOD):** Strong match requires name pair + (complete EC DOB OR phone suffix). Prevents false positives on name alone.
- **S2 (GOOD):** Prepared statements with bind_param. The dynamic SQL builds column names from a hardcoded array (phone columns) — not user input. Safe.
- **S3 (GOOD):** `FOR UPDATE` locking on strong match. Advisory GET_LOCK for no-row race.
- **S4 (GOOD):** Lock name derived from hashed name fingerprint (not PII in lock name beyond hash). Good.
- **S5 (GOOD):** `findAdvisoryMatches` uses parameterized query; LIMIT interpolated as int (validated max 1-20). Safe.
- **S6 (RISK — SQL injection via LIMIT):** `$limit` is interpolated into SQL string but validated `max(1,min(20,$limit))` → int. Safe. But relies on validation; if caller passes non-int, cast via max/min (PHP coerces). Acceptable.
- **S7 (RISK — phone normalization in SQL):** Uses nested REPLACE for 6 chars. If a column value contains SQL-special chars, it's a column value (parameterized via bind), not interpolated. Safe.
- **S8 (INFO):** `normalizePhoneSuffix` requires ≥9 digits. Good.
- **S9 (INFO):** `findAdvisoryMatches` unset phone fields from result (privacy). Good.
- **S10 (INFO):** Advisory matches sorted by score. Good.

---

## FILE 5 — `admin/info_manage_member.php` POST path (member edit)

### Findings
- **S1 (GOOD):** CSRF validated. Auth via config (session). DB check.
- **S2 (GOOD):** Member existence check before update.
- **S3 (GOOD):** File uploads via MemberFileService; on success discards old files; on failure discards new uploads.
- **S4 (GOOD):** `uploadsCommitted` flag prevents discard after commit. Good.
- **S5 (RISK — no transaction):** Unlike hr_register_member.php, the UPDATE here is NOT wrapped in a transaction. If PositionSyncService fails after UPDATE, partial state. (Non-fatal, logged.) Minor.
- **S6 (RISK — no idempotency):** Member edit has no idempotency key. Double-submit could apply update twice (usually harmless but not ideal).
- **S7 (RISK — no audit):** Member edit does not call SecurityAuditService::record. Changes to member data are not audited. Enterprise gap (accountability).
- **S8 (RISK — full_name_am recomputed from student+father+grandfather):** If edit form sends empty father name, full_name_am loses it. Acceptable.
- **S9 (RISK — age not recalculated on edit? Actually it is recalculated from dob_year).** Good.
- **S10 (RISK — `field()` here is the global helper from info_manage_member.php top, not the hr_register one):** `field($n, $d)` returns `isset($_POST[$n]) ? trim(...) : $d`. No length caps, no UTF-8 validation, no control-char rejection. Weaker than MemberRegistrationPolicy. Stored XSS risk if output unescaped.
- **S11 (RISK — no role check):** info_manage_member.php POST doesn't check role. Any authenticated admin can edit any member. Need to verify intended authorization. (Info dept is "viewer/data-updater only" — so info_dept CAN update. But should hr/super_admin also? Probably yes. But is there a restriction by department? Not enforced here.)
- **S12 (INFO):** `phone_primary` set to phone_number (not phone_primary). Minor duplication.
- **S13 (INFO):** `phone_guardian` set to guardian_phone1. Minor.

---

## FILE 6 — `admin/config.php` (auth/CSRF/session subset)

### Findings (relevant to registration)
- **S1 (GOOD):** Session timeout 30 min; updates LAST_ACTIVITY.
- **S2 (GOOD):** AJAX detection (`_isAjaxRequest`) returns JSON on session expiry instead of redirect. Good for fetch-based forms.
- **S3 (GOOD):** `requireAuth()` / `hasRole()` helpers. Good.
- **S4 (NEED VERIFY):** `validateCsrf()` implementation — must check token against session with hash_equals. (Not fully inspected; recommend verifying.)
- **S5 (GOOD):** `reportInternalError` logs without exposing to client (used in hr_register and info_manage).
- **S6 (INFO):** `$_SESSION['admin_role']` used for role gating. Good.

---

## CROSS-CUTTING FINDINGS (Registration area)

1. **Stored XSS risk:** Multiple `field()` helpers return raw trimmed strings stored in DB. Output escaping must be verified in all views (hr-dept.php, info-dept.php, manage-members.js). If any uses `innerHTML` without escaping, XSS possible. **Next analysis should cover output sites.**
2. **No audit on member edit (info_manage_member.php POST).** Enterprise accountability gap.
3. **No transaction on member edit.** Partial update risk.
4. **quick_add bypass via POST flag (MemberRegistrationPolicy).** Potential flow bypass.
5. **No rate limiting** on registration/edit endpoints.
6. **No input length caps** on most fields in both endpoints (except policy-capped ones).

## UNVERIFIED / UNKNOWN
- U1: `validateCsrf()` implementation details.
- U2: Output escaping in views (hr-dept.php, info-dept.php, manage-members.js).
- U3: Whether `MEMBER_PRIVATE_STORAGE_PATH` is set in production.
- U4: Whether opcache is configured (affects deploy freshness).

## NEXT FILES TO ANALYZE
- `admin/dashboards/hr-dept.php` (form HTML + JS, output escaping, file upload UI)
- `admin/dashboards/info-dept.php` (Info dept read-only UI + edit)
- `admin/js/manage-members.js` (member management JS)
- `admin/config.php` (validateCsrf, requireAuth, hasRole full)
- `admin/member_file.php` (already analyzed above in File 1 companion)
