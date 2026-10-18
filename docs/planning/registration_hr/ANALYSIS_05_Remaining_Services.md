# DEEP ANALYSIS — Remaining Services (Files 13–17)

**Mode:** ANALYSIS ONLY — no code modified.

---

## FILE 13 — `admin/backend/services/IdentityCodeService.php` (331 lines)

### Findings
- **S1 (GOOD):** Random 5-digit tails (enumeration-safe, no sequential IDs).
- **S2 (GOOD):** Prepared statements for uniqueness probe.
- **S3 (GOOD):** Regex validation of tokens (1-4 uppercase letters).
- **S4 (GOOD):** `composePrefix` is the single source of truth for prefix ordering.
- **S5 (GOOD):** QR regeneration uses tempnam + rename (atomic-ish), chmod 0644.
- **S6 (INFO):** QR path uses `memberId` (int) in filename — no traversal.
- **S7 (INFO):** `allocateWithPrefix` retries up to 16 times; throws if exhausted.
- **S8 (LOW):** QR content includes SITE_URL + code in URL — code is not secret (it's an identifier). Acceptable.

---

## FILE 14 — `admin/backend/services/MemberCategory.php` (284 lines, partially read)

### Findings
- **S1 (GOOD):** Single source of truth for category letters (A/B/C) and section metadata.
- **S2 (GOOD):** Unknown/missing groups return null — categories never guessed.
- **S3 (INFO):** Former 4th category removed (sql/017/019). Good.

---

## FILE 15 — `admin/backend/member_sync.php` (216 lines)

### Findings
- **S1 (GOOD):** Prepared statements throughout.
- **S2 (GOOD):** Honorary members never auto-changed.
- **S3 (GOOD):** syncMemberTeacherFlag checks for other active teacher accounts before clearing flag.
- **S4 (INFO):** batchSyncMemberTypes is maintenance-only (full table scan). Not request path.
- **S5 (INFO):** syncMemberType called from PositionSyncService (post-commit). Good.

---

## FILE 16 — `admin/backend/workflow.php` (708 lines, relevant functions)

### Findings
- **S1 (GOOD):** `sendNotification` uses prepared statements; JSON-encodes data.
- **S2 (GOOD):** `logMemberChange` uses prepared statements.
- **S3 (GOOD):** `onMemberPromoted` targets hr_dept, super_admin, school_admin (NOT info_dept) — verified.
- **S4 (GOOD):** `onMemberPromoted` records override reason in audit + notification.
- **S5 (INFO):** `sourceDept` defaults to session role. Good.
- **S6 (INFO):** Notifications silently fail (never crash main operation). Good.

---

## FILE 17 — `admin/api_list_members.php` + `MemberDirectoryService.php`

### Findings
- **S1 (GOOD):** Auth check (admin_id session). Role-aware projection via service.
- **S2 (GOOD):** Cache-Control: no-store, private. Good.
- **S3 (GOOD):** JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE. Good.
- **S4 (GOOD):** Error messages generic ("Could not load members."). Good.
- **S5 (INFO):** MemberDirectoryService NEW_MEMBERS_COLUMNS includes all needed fields. Good.

---

## FINAL OVERALL VERDICT

The registration subsystem is **substantially well-engineered** with strong security fundamentals. The complete analysis (5 docs + index) identifies:

**HIGH priority:**
1. Stored XSS in `showDuplicateWarning` (member_code, phone, section, age_group, match_reasons, photo path).
2. Stored XSS via `showToast` (innerHTML with message).
3. Image polyglot (no re-encoding).

**MEDIUM priority:**
4. PDF inline serving (recommend attachment).
5. quick_add bypass via POST flag.
6. No audit/transaction on member edit.
7. No AV scanning.

**LOW priority:**
8. No rate limiting.
9. No input length caps on most fields.
10. Dead code (member_code_form unused).

All other components (idempotency, duplicate detection, audit service, position sync, enrollment, identity codes, notifications) are well-designed and secure.

**Analysis is now COMPLETE for the entire registration-form dependency graph.**
