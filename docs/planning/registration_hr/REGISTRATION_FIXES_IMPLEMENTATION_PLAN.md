# Registration System — Recommended Fixes Implementation Plan

**Status:** PLAN — awaiting approval. No code changes made.
**Date:** 2026-10-16
**Protocol:** Universal AI Software Change, Patch & Update Protocol v1.0 + Master Codebase Understanding Protocol.

---

## 1. REQUEST

Implement the fixes identified in the deep analysis of the registration system (ANALYSIS_01..05 + INDEX), prioritized by severity, without breaking existing behavior.

## 2. CURRENT PROBLEM

The registration subsystem has the following verified gaps (see analysis docs):
- HIGH: Stored XSS in `showDuplicateWarning` (hr-dept.php) — member_code, phone, section, age_group, match_reasons, photo path unescaped.
- HIGH: Stored XSS via `showToast` (innerHTML with message).
- HIGH: Image polyglot risk (MemberFileService does not re-encode).
- MEDIUM: PDFs served inline (member_file.php) — recommend attachment.
- MEDIUM: quick_add bypass via POST flag (MemberRegistrationPolicy).
- MEDIUM: No audit/transaction on member edit (info_manage_member.php POST).
- MEDIUM: No AV scanning (enterprise gap — optional, depends on server).
- LOW: No rate limiting on registration/edit endpoints.
- LOW: No input length caps on most fields.
- LOW: Dead code (`member_code_form`).

## 3. DESIRED BEHAVIOR

After implementation:
- No stored XSS via duplicate-warning or toast rendering.
- Uploaded images are re-encoded (polyglot destroyed) before storage.
- PDFs are served as attachments (not inline).
- quick_add cannot be triggered by a POST flag from non-school_admin roles.
- Member edits are audited and transactional.
- (Optional) Rate limiting + length caps + AV scanning if approved.

## 4. SCOPE

Files to modify:
- `admin/dashboards/hr-dept.php` (showDuplicateWarning, showToast, member search onclick validation)
- `admin/backend/services/MemberFileService.php` (image re-encoding)
- `admin/member_file.php` (Content-Disposition: attachment for PDFs)
- `admin/backend/services/MemberRegistrationPolicy.php` (quick_add gating)
- `admin/info_manage_member.php` (transaction + audit on POST)
- `admin/hr_register_member.php` (length caps, remove dead code, rate limiting — optional)

## 5. CONSTRAINTS (must NOT change)

- Existing registration/promotion workflow must remain functional.
- API contracts (JSON response shape) must not break.
- DB schema changes must be backward-compatible (migrations only if needed — none planned here).
- File storage paths/contracts (private://, uploads/members/) must remain compatible with existing stored records.
- Info dept remains viewer/data-updater only.

## 6. NON-GOALS

- No AV scanning (depends on server infrastructure; can be a separate task).
- No new features.
- No UI redesign.
- No changes to notification matrix or workflow.

## 7. IMPLEMENTATION PLAN (ordered, minimal changes)

### Step 1 — XSS fix in showDuplicateWarning (hr-dept.php)
- Escape `m.member_code`, `m.current_section`/`m.age_group`, `m.phone_number`, `m.match_reasons` via `escapeHtml()`.
- Attribute-encode `m.student_photo_path` in `<img src="...">` (use escapeHtml + ensure no quotes; or build via DOM).
- **Files:** `admin/dashboards/hr-dept.php` (~lines 4084-4115).

### Step 2 — showToast XSS fix (hr-dept.php)
- Replace `toast.innerHTML = message` with `toast.textContent = message` (or escape). If HTML formatting needed, escape first.
- **Files:** `admin/dashboards/hr-dept.php` (~line 3667).

### Step 3 — member search onclick validation (hr-dept.php)
- Validate `m.id` is a positive integer before interpolating into onclick; skip rendering if invalid.
- **Files:** `admin/dashboards/hr-dept.php` (~line 4835).

### Step 4 — Image re-encoding in MemberFileService (PHP)
- In `storeRequestUpload`, after `getimagesize()` passes, re-encode the image using GD (`imagecreatefromjpeg/png/gif/webp` → `imagejpeg/png/gif/webp` to a temp file) to strip EXIF/appended data. Replace `$tmp` with the re-encoded file before move_uploaded_file. If GD unavailable, fall back to current behavior (log warning).
- **Files:** `admin/backend/services/MemberFileService.php`.
- **Risk:** GD may not be installed; must check `extension_loaded('gd')`. If absent, skip re-encoding (log).

### Step 5 — PDFs as attachment (member_file.php)
- If `$mime === 'application/pdf'`, set `Content-Disposition: attachment; filename="..."`. Keep inline for images.
- **Files:** `admin/member_file.php` (~line 62).

### Step 6 — quick_add gating (MemberRegistrationPolicy.php)
- Change `quickAdd` to be determined solely by role === 'school_admin', ignoring `registration_profile` input flag (or require a separate server-side capability check). Keep `registration_profile` for UI hint only, not security.
- **Files:** `admin/backend/services/MemberRegistrationPolicy.php` (line 26).

### Step 7 — Member edit: transaction + audit (info_manage_member.php POST)
- Wrap the UPDATE in `$conn->begin_transaction()` / commit; rollback on failure.
- Call `SecurityAuditService::record($conn, 'Member Updated', [...], 'member', $id)` after successful update.
- **Files:** `admin/info_manage_member.php` (POST path).

### Step 8 — Input length caps (hr_register_member.php + info_manage_member.php)
- Apply `MemberRegistrationPolicy::text()`-style caps to city, woreda, mender, etc. (or add caps in `field()`). Minimal: cap each field to column length.
- **Files:** both endpoints.

### Step 9 — Remove dead code (hr_register_member.php)
- Remove `$member_code_form = field('student_id')` (unused). (Or wire it up if intended — verify first.)
- **Files:** `admin/hr_register_member.php`.

### Step 10 — Rate limiting (optional, if approved)
- Add per-admin_id rate limit (e.g., 30 registrations/edits per 5 min) using existing rate-limit infrastructure (sql/008_security_rate_limits.sql). Verify the rate-limit helper exists and how to call it.
- **Files:** `admin/hr_register_member.php`, `admin/info_manage_member.php`, `admin/config.php`.

## 8. ACCEPTANCE CRITERIA

1. `showDuplicateWarning` renders member data safely (no script execution) — verified by injecting `<script>` into a test member's phone_number and confirming no execution.
2. `showToast` with HTML-like message renders as text, not HTML.
3. Uploaded JPEG/PNG with appended PHP payload is re-encoded; the payload is gone (verified by inspecting stored file bytes).
4. PDFs download instead of rendering inline.
5. A non-school_admin user cannot trigger quick_add by sending `registration_profile=quick_add`.
6. Member edit is wrapped in a transaction; audit row created in activity_logs.
7. All existing registration/promotion flows still work (regression).
8. No new PHP syntax errors (php -l).
9. No new JS syntax errors (node --check).

## 9. RISKS

- Image re-encoding requires GD; if absent, skip (log). Must verify GD availability in production.
- Transaction wrapping in info_manage_member.php must not break the existing file-discard logic (uploadsCommitted flag).
- quick_add change could affect school_admin registration flow if it relied on the flag. Verify UI sends role-based capability, not just the flag.
- Rate limiting requires verifying the existing rate-limit helper API.

## 10. VERIFICATION PLAN

- Local: install PHP+MariaDB, load schema, run php -l on all changed files, node --check on JS.
- Functional: register new member, promote temporary member, edit member, upload photo+PDF, trigger duplicate warning.
- Security: inject `<script>alert(1)</script>` into phone_number, verify no execution in duplicate warning and toast.
- Security: upload polyglot image, verify re-encoded output has no appended payload.
- Security: verify PDF served with Content-Disposition: attachment.

## 11. ASSUMPTIONS

- GD extension is available in production (to be verified).
- Existing rate-limit infrastructure (sql/008) is usable.
- No DB schema changes required.

## 12. APPROVAL REQUEST

Please approve this plan (or specify changes/reordering) before I implement. I will not modify any code until approved.

