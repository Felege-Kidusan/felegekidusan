# Registration System — Deep Analysis Index

**Mode:** ANALYSIS ONLY (no code modified). Conducted per Universal AI Software Change/Patch/Update Protocol v1.0 and Master Codebase Understanding Protocol.

## Files analyzed

| # | File | Analysis doc | Focus |
|---|---|---|---|
| 1 | `admin/backend/services/MemberFileService.php` | `ANALYSIS_01_MemberFileService.md` | Upload validation, storage, path resolution, trojan/polyglot/PDF safety |
| 2 | `admin/member_file.php` | (in Analysis 01, §4 companion) | Serving controller: auth, headers, inline vs attachment, CSP |
| 3 | `admin/hr_register_member.php` | `ANALYSIS_02_Registration_Backend.md` §File2 | Registration/promotion endpoint: auth, idempotency, transaction, audit |
| 4 | `admin/backend/services/MemberRegistrationPolicy.php` | `ANALYSIS_02_Registration_Backend.md` §File3 | Role-aware validation, quick_add bypass risk |
| 5 | `admin/backend/services/MemberDuplicateService.php` | `ANALYSIS_02_Registration_Backend.md` §File4 | Duplicate detection, locking, SQL safety |
| 6 | `admin/info_manage_member.php` (POST) | `ANALYSIS_02_Registration_Backend.md` §File5 | Member edit: no transaction/audit/role check |
| 7 | `admin/config.php` (auth/CSRF/session) | `ANALYSIS_02_Registration_Backend.md` §File6 | validateCsrf, hasRole, requireAuth |
| 8 | `admin/dashboards/hr-dept.php` (form + JS) | `ANALYSIS_03_Form_UI_OutputEscaping.md` | Form structure, conditional logic, XSS surface |
| 9 | `admin/js/new-members.js`, `manage-members.js` | `ANALYSIS_03_Form_UI_OutputEscaping.md` | XSS safety via textContent |

## Top findings (cross-cutting)

### Security (trojan / XSS / injection)
1. **Stored XSS in showDuplicateWarning** (hr-dept.php): member_code, phone, section, age_group, match_reasons, photo path interpolated into innerHTML without escaping. (HIGH)
2. **showToast uses innerHTML** with message — potential XSS if message contains user input. (MEDIUM)
3. **Image polyglot risk:** MemberFileService does not re-encode images; appended payloads survive. (HIGH for trojan)
4. **PDFs served inline** with CSP sandbox; embedded PDF JS may still execute in some viewers. Recommend attachment for PDFs. (MEDIUM)
5. **quick_add bypass via POST flag** in MemberRegistrationPolicy — non-school_admin could send `registration_profile=quick_add` to bypass uploads/validation. (MEDIUM)
6. **No AV scanning** on uploads. (MEDIUM — enterprise gap)
7. **No audit on member edit** (info_manage_member.php POST). (MEDIUM — accountability)
8. **No transaction on member edit.** (LOW-MEDIUM)
9. **No rate limiting** on registration/edit endpoints. (LOW)
10. **No input length caps** on most fields in both endpoints. (LOW)

### Logic / workflow
11. Member edit does not check role — any authenticated admin can edit any member.
12. `member_code_form = field('student_id')` collected but unused (dead code).
13. `phone_primary` set to `phone_number` (not a separate primary). Minor.
14. Date conversion EC→Gregorian uses fixed offset; `min(day,28)` prevents invalid days. Acceptable.
15. Upgrade path correctly preserves registered_at/member_code/registration_type. Good.

### Strengths
- CSRF (hash_equals), prepared statements, MIME allow-list, randomized filenames, private storage outside web root, strict path resolution, idempotency, transaction + rollback + file discard, duplicate detection with locking, audit on registration/promotion, role-gated uploads, signed-form requirement for promotion.

## Recommended next steps (for approval, not yet implemented)
1. Fix stored XSS in showDuplicateWarning + showToast.
2. Add image re-encoding (GD) in MemberFileService.
3. Serve PDFs as attachment in member_file.php.
4. Gate quick_add strictly on role (ignore input flag).
5. Add audit + transaction to info_manage_member.php POST.
6. Add rate limiting to registration/edit endpoints.
7. Add input length caps.
8. (Optional) AV scanning integration.

## UNVERIFIED / UNKNOWN
- validateCsrf already verified (hash_equals). Good.
- Whether MEMBER_PRIVATE_STORAGE_PATH is set in production (U4).
- Whether opcache affects deploy freshness.
- Whether `member_search` API validates/escapes.

## Additional analysis doc
- `ANALYSIS_04_Additional_Services.md` — PositionSyncService, EnrollmentService, SecurityAuditService, ApiIdempotencyService, ethiopian_date.

## Additional analysis doc (final)
- `ANALYSIS_05_Remaining_Services.md` — IdentityCodeService, MemberCategory, member_sync, workflow.php, api_list_members/MemberDirectoryService.
