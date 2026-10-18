# DEEP ANALYSIS — File 7: Registration Form UI, Output Escaping & XSS Surface

**Mode:** ANALYSIS ONLY — no code modified.
**File:** `admin/dashboards/hr-dept.php` (registration form HTML + JS, ~4900 lines), `admin/js/manage-members.js`, `admin/js/new-members.js`.

---

## 1. FORM STRUCTURE (verified)

- Form element: `<form id="memberRegistrationForm">` (line 1209).
- Hidden: `csrf_token`, `registration_request_id`, `upgrade_member_id` (line 1292), `promotion_override_reason` (injected dynamically).
- Fields: full_name_am (required), baptismal_name, gender, phone_number, alt_phone_number, current_section, age_group, member_type, registration_type, status, membership_tier, dob_day/month/year, city, sub_city, woreda, mender, block_number, house_number, education_level, spiritual_education, work_profession, guardian_*, class_id, position_ids[], reg_date_*, doc_* uploads, student_photo, guardian_photo.
- Submit via fetch with FormData (line 3486/3816/4167).

## 2. XSS / OUTPUT ESCAPING FINDINGS

### 2.1 Stored XSS vectors (HIGH)
In `showDuplicateWarning()` (lines 4084-4115):
- `m.member_code`, `m.current_section`, `m.age_group`, `m.phone_number` interpolated into innerHTML WITHOUT escaping.
- `m.match_reasons` joined and interpolated without escaping.
- `m.student_photo_path` interpolated into `<img src="${...}">` without attribute escaping (quote breakout possible if path contains `"`).
- `fullName` IS escaped via `escapeHtml`. But the others are not.
- **Impact:** A malicious member record (e.g., phone_number = `<img src=x onerror=alert(1)>`) could execute JS when the duplicate-warning UI renders. Stored XSS.
- **Fix needed:** escape all interpolated values; use `escapeHtml()` or attribute-encode for src.

### 2.2 Other innerHTML sites
- Line 4835: member search results — uses `esc()` for names/codes. Good. But `m.id` interpolated into onclick unquoted? `onclick="HrReports.openMember(' + m.id + ')"` — m.id is from server (int). If server returns non-int, could break. Server casts to int. Acceptable but should validate.
- Line 3362: `durationEl.innerHTML` with `durationText` (computed from dates, not user input). Low risk.
- Line 4812/4885/4891: use `esc()` for labels. Good.
- Line 4706-4707: `esc(p.section)`. Good.

### 2.3 new-members.js & manage-members.js
- Both use `textContent` for all member-derived values. **Safe (no XSS).** Good.

## 3. FILE UPLOAD UI
- Upload inputs accept files; client-side no type/size validation (server enforces). Acceptable (server is authoritative).
- No preview sanitization. Fine.

## 4. CONDITIONAL LOGIC (form)
- quick_add vs full registration handled server-side (policy). UI sends `registration_profile`.
- Upgrade mode: form title changes, upgrade_member_id set, prefill (now fixed).
- Position picker: only for regular/special_regular. Good.
- Class assignment: optional. Good.

## 5. SECURITY FINDINGS (form/UI)
- **S1 (GOOD):** CSRF token injected via `csrf_field()` / JS CSRF_TOKEN. Good.
- **S2 (RISK):** `CSRF_TOKEN` global in JS — if page is cached by a proxy, token could be stale. Minor.
- **S3 (RISK):** Form submission uses `fetch` with credentials (same-origin default). Good.
- **S4 (RISK):** `member_search` results render `m.id` into onclick without validation on client. Server returns int. Low.
- **S5 (INFO):** `showToast` uses innerHTML with `message` — if message contains user input, XSS. Need to verify callers pass escaped/safe messages. Most callers pass server messages (which could contain user input echoed back). **Potential XSS via toast.** Recommend using textContent.

## 6. OTHER FINDINGS
- `escapeHtml` defined twice? No — once at 3679, plus `esc` alias at 4484 and 4771 (shadowing). The 4771 version handles null. Fine.
- No client-side validation of required fields beyond HTML `required` attributes. Server validates. Acceptable.

## 7. UNVERIFIED
- Whether `member_search` API escapes/validates. (api_list_members / member search endpoint.)
- Whether `HrReports.openMember` sanitizes id.

## 8. RECOMMENDED FIXES (analysis only — not applied)
1. Escape all member fields in `showDuplicateWarning` (member_code, section, age_group, phone, match_reasons, photo path attribute).
2. Use `textContent` in `showToast` instead of `innerHTML`.
3. Validate `m.id` is integer before interpolating into onclick.
4. Consider server-side escaping at output boundary (defense in depth) — but current pattern is escape at render; ensure all render sites escape.

