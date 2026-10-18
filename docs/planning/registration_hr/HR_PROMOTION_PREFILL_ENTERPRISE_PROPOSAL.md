# HR Promotion Prefill — Enterprise-Grade Workflow Proposal

**Status:** PROPOSAL — awaiting approval. No code changes made for this proposal.

**Date:** 2026-10-16
**Project:** SSMS (felegekidusan.arkeonethiopia.com)
**Scope:** HR "New Members (Temporary)" → "Promote to Permanent" flow, specifically the empty-form prefill defect and overall production-grade handling.

---

## 1. REQUEST

Make the "Promote" action on a temporary member open the permanent-registration form **with all existing member data prefilled correctly**, and bring the whole temporary→permanent promotion flow to enterprise production standard (data integrity, audit, security, idempotency, UX, observability).

## 2. CURRENT PROBLEM (VERIFIED)

When an HR user clicks **Promote** on a temporary member, the permanent-registration form opens but is **empty** (no prefilled data). In earlier builds the same click produced `alert("Error loading member data.")` and blocked promotion entirely.

## 3. ROOT CAUSE (VERIFIED FROM SOURCE)

| # | Finding | Evidence |
|---|---------|----------|
| R1 | `openUpgradeModal(memberId)` fetched `info_manage_member.php` (HTML page) but called `.json()` on it → parse error → catch → alert. | `admin/dashboards/hr-dept.php` ~line 3061 |
| R2 | Even after adding `?format=json`, prefill targeted field names that **do not exist** in the form: `student_name`, `father_name`, `grandfather_name`. The form uses a single `full_name_am` (combined). `guardian_phone1` DOES exist in the form (so that part of the prefill was actually valid). The name fields were the critical miss. |
| R3 | The member data is **already loaded in the page** by `new-members.js` (api_list_members.php, view=new_members), yet the code made a redundant second fetch to info_manage_member.php — a second failure point and a second source of truth. | `admin/js/new-members.js` renderMembers; `NEW_MEMBERS_COLUMNS` includes all needed fields. |
| R4 | `full_name_am` (the only name field the form requires) was never set, so even a "successful" fetch left the required name empty → form unusable. | hr_register_member.php requires `full_name_am`. |
| R5 | No client-side cache of loaded members → every Promote click re-fetches; on session expiry the fetch returns `session_expired` JSON and prefill silently fails. | `_isAjaxRequest` / session timeout in config.php. |

Conclusion: the defect is **field-name mismatch + redundant fetch + no cache**, not a missing capability. The backend upgrade path itself works (verified end-to-end earlier: promotion with signed PDF returned success).

## 4. ENTERPRISE RESEARCH FINDINGS

Principles applied (from SSOT, prefill, and promotion-workflow literature):

1. **Single Source of Truth (SSOT).** One authoritative store per data element; reads go to it directly; copies are read-only. → The `members` row (served by `api_list_members.php`) is the SSOT for member data. The form must prefill from that already-loaded record, not from a second endpoint.
2. **Prefill is controlled retrieval + review.** Data comes from an authoritative source, is editable, and the user reviews/corrects before submit. → Prefilled fields must be editable (they already are, inputs are not disabled) and clearly "prefilled, please verify".
3. **Map fields once, review periodically.** A documented field-mapping table prevents silent drift. → Maintain an explicit member-column → form-field mapping in one place.
4. **Idempotency & audit.** Promotion must be idempotent (re-submitting must not create duplicates) and audited. → Already present: `registration_request_id` idempotency, `upgrade_member_id` in-place UPDATE, `SecurityAuditService::record('Member Registration Updated')`, `onMemberPromoted` → `logMemberChange('promoted')` + `sendNotification('member_promoted')`.
5. **Eligibility gating with override.** Eligible (6+ months) highlighted; non-eligible promotion allowed only with explicit confirmation + reason. → Add confirmation dialog (already added) and an optional override reason field for audit.
6. **Security at trust boundary.** CSRF, session auth, role check, input validation, no sensitive data in URLs. → CSRF token already injected; session expiry returns JSON not redirect for AJAX; role gating via `$_SESSION['admin_role']`.
7. **Graceful degradation.** A failed prefill must never block the workflow; form opens, user fills manually, warning shown. → Already implemented (59efc06).
8. **Observability.** Failed prefill / promotion attempts logged for ops. → Add console + server-side logging of prefill source and outcome.
9. **Downstream consistency.** After promotion, all views (New Members list, All Members, notifications) reflect the new tier. → Refresh list after success (already added); ensure `membership_tier` becomes 'permanent'.

## 5. PROPOSED PROFESSIONAL WORKFLOW

### 5.1 Data flow (SSOT-aligned)

```
USER clicks "Promote" on a row
        ↓
new-members.js has the member object in memory (from last api_list_members load)
        ↓
openUpgradeModal(memberId, memberObject)   // no network round-trip for prefill
        ↓
toggleMemberRegistrationForm(true, 'permanent', 'Upgrade to Permanent Member')
set upgradeMemberIdField = memberId
prefillForm(memberObject)   // explicit field mapping, combined name
        ↓
HR reviews/corrects prefilled values, uploads signed form (required)
        ↓
submit → hr_register_member.php (upgrade_member_id > 0)
        ↓
in-place UPDATE of members row (registered_at, member_code, registration_type preserved)
        ↓
audit: SecurityAuditService 'Member Registration Updated'
       onMemberPromoted → logMemberChange('promoted') + sendNotification('member_promoted')
       targets: hr_dept, super_admin, school_admin (NOT info_dept)
        ↓
client: reset form, toast success, reload New Members list (member leaves list)
```

### 5.2 Prefill field mapping (single source of truth, documented)

| Member column (SSOT) | Form field | Transform |
|---|---|---|
| student_name + father_name + grandfather_name | full_name_am | `trim(join(' ', [student_name, father_name, grandfather_name]))` |
| baptismal_name | baptismal_name | direct |
| gender | gender | direct (select) |
| age_group | age_group | direct (select) |
| current_section | current_section | direct (select) |
| member_type | member_type | direct (select) |
| registration_type | registration_type | direct (select) |
| status | status | direct (select) |
| phone_number | phone_number | direct |
| alt_phone_number | alt_phone_number | direct |
| guardian_name | guardian_name | direct |
| city | city | direct |
| sub_city | sub_city | direct (if field exists) |
| woreda | woreda | direct (if field exists) |
| mender | mender | direct |
| block_number | block_number | direct |
| house_number | house_number | direct |
| education_level | education_level | direct (select) |
| spiritual_education | spiritual_education | direct (select) |
| student_photo_path | (preview only) | show thumbnail, do not overwrite file unless replace_ |
| registered_at | (not overwritten) | preserved by backend (unset in upgrade branch) |

Fields NOT prefilled (intentionally): `member_code` (preserved), `registered_at` (preserved), `doc_*` paths (require fresh upload of signed form; others preserved unless replace_).

### 5.3 Fallback & resilience

- If the member object is not in cache (e.g., promoted from another view), fall back to `info_manage_member.php?id=...&format=json`. If that also fails, open the form empty with a warning toast — never block promotion.
- Cache is keyed by member id and invalidated on list reload.

### 5.4 Non-eligible promotion (enterprise control)

- Eligible (≥6 months) → green badge, no friction.
- Non-eligible → Promote button shows confirmation dialog: "This member has not yet reached 6 months (X months). Promote anyway?" with optional override reason textarea. Override reason is sent to backend and recorded in audit. If user cancels, no action.

### 5.5 Concurrency / idempotency

- `registration_request_id` (already) prevents double-submit.
- `upgrade_member_id` in-place UPDATE prevents duplicate rows.
- Strong-identity duplicate lock already acquired.

### 5.6 Security

- CSRF token (already injected).
- Session auth + role check (HR/super_admin/school_admin only; info_dept excluded from notifications).
- No member PII in URLs beyond id; id is integer-cast.
- Signed form required (already enforced server-side).
- Override reason length-capped (≤500) and validated.

### 5.7 Observability

- Client: console.warn on prefill miss; toast to user.
- Server: logMemberChange + SecurityAudit already; add `prefill_source` (cache|fetch|none) to audit context.

## 6. IMPLEMENTATION PLAN (PROPOSED — NOT YET EXECUTED)

| Step | File | Change |
|---|---|---|
| 1 | `admin/js/new-members.js` | Add `memberCache` Map; populate in `renderMembers`; Promote click passes member object to `openUpgradeModal(id, member)`; invalidate cache on reload. |
| 2 | `admin/dashboards/hr-dept.php` | Change `openUpgradeModal(memberId)` → `openUpgradeModal(memberId, memberData)`; implement `prefillForm(m)` with the mapping table (full_name_am combined, selects via `.value`); keep fetch fallback; keep graceful degradation; add override-reason field handling. |
| 3 | `admin/hr_register_member.php` | Accept optional `promotion_override_reason`; pass to audit context; ensure `membership_tier` becomes 'permanent' on upgrade (verify policy sets it). |
| 4 | `admin/backend/workflow.php` | Include `prefill_source`/override reason in `onMemberPromoted` audit (optional). |
| 5 | Tests | Add a smoke test: load new_members list, click promote on a known member, assert full_name_am populated. (Manual verification plan provided.) |

**No DB schema changes. No API contract changes. No new dependencies.**

## 7. ACCEPTANCE CRITERIA

1. Clicking Promote on any temporary member opens the form with `full_name_am` and all available fields populated from the member record.
2. Prefilled values are editable.
3. Promotion succeeds with only the signed form uploaded (profile completion optional) — already verified.
4. Promotion updates the existing row in place (no duplicate member) and `membership_tier` becomes 'permanent'.
5. Member disappears from New Members list after promotion (list refreshes).
6. Audit record created with change_type 'promoted' and notification sent to hr_dept/super_admin/school_admin only.
7. Non-eligible promotion requires explicit confirmation (+ optional reason).
8. If prefill data unavailable, form still opens with warning (no hard block).
9. No regression in: new member registration, member search/filter/sort, eligibility cron, Info dept read-only view.

## 8. RISKS & ASSUMPTIONS

- **Assumption:** `api_list_members.php` returns all fields needed for prefill (verified: NEW_MEMBERS_COLUMNS includes them).
- **Assumption:** The form's select fields accept the stored string values (e.g., gender, age_group, current_section). If stored values differ in casing/whitespace, selects won't match — to be verified during implementation.
- **Risk:** If a member was created before some columns existed, nulls → empty fields (acceptable, user fills).
- **Risk:** Client cache staleness if another user edits the member between list load and promote → fallback fetch mitigates; backend is authoritative on submit.
- **No migration required.**

## 9. APPROVAL REQUEST

Please approve this proposal (or specify changes) before I implement. Once approved, I will implement steps 1–4, run the manual verification plan, and report results per the protocol.

---

**Do you approve this plan as-is, or would you like adjustments (e.g., additional prefilled fields, mandatory override reason, or a different eligibility threshold)?**
