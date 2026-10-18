# IMPLEMENTATION PLAN — "New Members" Tracking & Promotion Workflow (HR)

**Status:** PLAN ONLY — not implemented. Awaiting approval.
**Protocol:** Universal AI Software Change, Patch & Update Protocol v1.0 (saved as standing rule in `/home/user/PROJECT_RULES.md`).
**Deep research basis:** `/home/user/SSMS` source (hr-dept.php, hr_register_member.php, workflow.php, MemberDirectoryService, MemberRegistrationPolicy, NotificationCenterService, manage-members.js, paginated-list.js, api_list_members.php, info_manage_member.php).

---

## 1. REQUEST

- **Use case:** NEW FEATURE (with DB change + UI + notification).
- **Title:** "New Members" section in HR dashboard — track temporary members, highlight 6-month eligibility, filter, then promote to permanent after completing info + uploading registration form.
- **Objective:** Give HR a dedicated place to track new (temporary) members; visually mark those who have reached the 6-month milestone (green); filter/search them; run a promotion workflow that requires only the signed registration form upload, then promotes to permanent; notify HR when a member hits the milestone. Info dept is viewer/data-updater only.

## 2. CURRENT PROBLEM

- Temporary members are mixed into "All Members" / "Manage Members"; the only 6-month signal is a small amber widget on the HR dashboard with no filtering, no dedicated tracking, and no notification.
- No automated milestone detection; HR must remember to check.
- Promotion reuses the registration form and masquerades as "Member Registered" (logs `registered`, sends `member_registered`), so promotions are indistinguishable from new registrations in audit/notification.
- No state tracking "eligibility notified" → any automation would spam.

## 3. DESIRED BEHAVIOR (after change)

1. New sidebar item **"New Members"** in HR dashboard (Info dept gets read-only viewer only).
2. Section lists temporary (non-archived) members with: name, code, registered date, months elapsed, status (pending / eligible-green / promoted).
3. Visual: when `registered_at <= now-6mo`, row/badge turns green and shows "Eligible for promotion".
4. Filtering: search (name/code/phone), status, age group, section, registration type, "eligible only" toggle, sort by registered date.
5. Notification: when a member crosses 6 months, HR is notified once (deduped), and a department task is created for HR. Info dept is not notified (viewer only).
6. Promotion workflow: HR opens the member → uploads the signed registration form (`doc_signed_form_path`) → submits → member becomes permanent. Filling all profile fields is NOT required to promote; HR/Info may complete/develop the member profile later over time.
7. Info dept is a viewer/data-updater only (profile developer) — it does NOT promote members and is not responsible for the promotion. It may view the New Members list and update profile data, but promotion authority stays with HR.
8. Notification targets for eligibility/promotion: hr_dept + super_admin + school_admin only (info_dept excluded).

## 4. SCOPE

- HR dashboard (`admin/dashboards/hr-dept.php`) — new section + sidebar item + JS.
- New JS module `admin/js/new-members.js` (following `manage-members.js` + `PaginatedList`).
- Backend: `admin/api_list_members.php` (reuse with a new view/filter) OR new endpoint `admin/api_new_members.php`.
- `admin/hr_register_member.php` — distinguish upgrade vs new registration in audit/notification.
- `admin/backend/workflow.php` — new notification type `member_eligible_permanent`, new `onMemberPromoted()`, matrix entries.
- `admin/backend/services/MemberDirectoryService.php` — add a `new_members`/`temporary` view + `membership_tier` filter + months-elapsed projection.
- DB: new column `members.permanent_eligibility_notified_at DATETIME NULL` (migration + auto_fix) — OR reuse `member_changes`. Decision: new column (simpler, queryable).
- Cron/CLI detector `admin/backend/notify_permanent_eligibility.php` (deployment-owned schedule).
- Info dept dashboard gets a read-only "New Members" view (viewer/data-updater only; no promotion action, no promotion notifications).

## 5. CONSTRAINTS (must NOT change)

- Existing "All Members", "Manage Members", "Archive" sections and their JS/JS behavior.
- Existing registration form fields and upload validators.
- Existing notification feed / read-state logic.
- Existing member code allocation (upgrades keep original code).
- Feature gating / roles (hr_dept, info_dept, super_admin, school_admin remain privileged).
- No new dependencies.

## 6. ACCEPTANCE CRITERIA

- [ ] Sidebar shows "New Members" in HR dashboard; clicking loads the section.
- [ ] Section lists only temporary, non-archived members.
- [ ] Members with ≥6 months show green badge "Eligible" and sort to top (or filterable).
- [ ] Filtering works (search, status, age group, section, registration type, eligible-only).
- [ ] When a member crosses 6 months, HR receives exactly one notification (deduped) + a department task.
- [ ] Promotion requires uploading the signed registration form only; profile fields may be completed later. On success member becomes permanent.
- [ ] Audit log records "Member Promoted to Permanent" (not "Member Registered").
- [ ] Notification on promotion targets HR + Info + super_admin + school_admin.
- [ ] No duplicate notifications on repeated cron runs.
- [ ] Existing tests still pass; new tests cover eligibility query + dedup + promotion audit.

## 7. INVESTIGATION FINDINGS (key)

- Sidebar/sections: `admin/dashboards/hr-dept.php` uses `.content-section` divs toggled by `navigateToSection(name)`; sidebar items are `<a/button data-section="...">`. Adding a section = add sidebar item + `<section id="section-newmembers">` + JS init hook in `navigateToSection`.
- Data loading pattern: `PaginatedList` (`admin/js/paginated-list.js`) + endpoint `/admin/api_list_members.php` with `view` param; `MemberDirectoryService::search()` handles views (manager/archive/id_cards/picker) and filters.
- Uploads: `hr_register_member.php` handles `doc_signed_form_path`, `doc_school_records_path`, etc. via `saveUploadedFile` + `MemberFileService`; quick-add disables uploads. For promotion (upgrade), uploads are allowed only if `allow_uploads` (non-quick-add, hr/super_admin role).
- Notifications: `sendNotification($conn, $type, ...)` with `$options['target_roles']` override; matrix in `$NOTIFICATION_MATRIX`. Feed filters by `FIND_IN_SET(role, target_roles)`.
- Tasks: `createDepartmentTask($conn, $title, ['to_dept'=>..., 'related_member_id'=>...])` inserts `department_tasks` + fires `task_assigned` notification to that dept.
- Audit: `SecurityAuditService::record($conn, action, data, 'member', id)`.
- Dedup state: none exists for eligibility; must add column or reuse `member_changes`.

## 8. IMPACT ANALYSIS

- **DIRECT:** hr-dept.php (sidebar + section + JS), new-members.js, MemberDirectoryService, api_list_members.php (or new endpoint), hr_register_member.php (audit/notification on upgrade), workflow.php (new type + onMemberPromoted), migration + auto_fix, cron detector.
- **INDIRECT:** Info dept dashboard (read-only view), notification center (new type label), mobile sync (member tier changes).
- **POTENTIAL:** MemberDirectoryService projection (new column must be added to FULL_COLUMNS / new view); paginated list (eligible-only filter param).
- **UNRELATED (do not touch):** All Members, Manage Members, Archive, ID Cards, Groups, submissions, reports, settings, auth, config.

## 9. IMPLEMENTATION PLAN (ordered, minimal)

### Phase A — Data layer (DB + service)
1. Migration `sql/068_member_permanent_eligibility_notified.sql`: `ALTER TABLE members ADD COLUMN permanent_eligibility_notified_at DATETIME NULL DEFAULT NULL AFTER membership_tier;` (+ index). Add corresponding entry to `admin/migrations/` if that convention is used; update `auto_fix_database.php` schema repair.
2. `MemberDirectoryService`:
   - Add `NEW_MEMBERS_COLUMNS` projection (full columns + registered_at + waiting_since + permanent_eligibility_notified_at) or reuse FULL_COLUMNS and add the new column to FULL_COLUMNS.
   - Add view `'new_members'` in `projection()` (privileged roles only) returning FULL_COLUMNS + new column.
   - Add filter `'membership_tier'` to `normalize()` enum and `whereClause()` (so `membership_tier=temporary` can be passed).
   - Add computed `months_elapsed` / `eligible_for_promotion` (bool) in search output? Prefer compute in JS from `registered_at` to avoid SQL date math; but for "eligible only" filter, add criteria `eligible_for_promotion` (bool) → where `registered_at <= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)`.
   - Add `'new_members'` handling in `api_list_members.php` view param (already passes through).

### Phase B — Backend endpoints & workflow
3. `admin/backend/workflow.php`:
   - Add `$NOTIFICATION_MATRIX['member_eligible_permanent'] = ['hr_dept','super_admin','school_admin'];` (info_dept excluded — viewer only, not notified)
   - Add `$NOTIFICATION_MATRIX['member_promoted'] = ['hr_dept','super_admin','school_admin'];` (info_dept excluded)
   - Add `onMemberPromoted($conn, $memberId)` mirroring `onMemberRegistered` but: logMemberChange change_type='promoted', sendNotification type='member_promoted', no edu task (or optional).
4. `admin/hr_register_member.php`:
   - When `$upgrade_id > 0`, call `onMemberPromoted` instead of `onMemberRegistered`; otherwise keep `onMemberRegistered`.
   - Ensure signed form upload is required for promotion (validation): if upgrade and `doc_signed_form_path` missing → error.
5. Cron detector `admin/backend/notify_permanent_eligibility.php` (CLI-only):
   - Find temporary, non-archived members with registered_at <= now-6mo and notified_at IS NULL.
   - For each: createDepartmentTask to hr_dept (and info_dept if info must complete), fire sendNotification('member_eligible_permanent'), set notified_at = now().
   - Log to error_log / stdout for cron.
6. Optional: `admin/api_new_members.php` if a dedicated endpoint is cleaner; prefer reusing `api_list_members.php` with `view=new_members&membership_tier=temporary`.

### Phase C — UI (HR dashboard)
7. Sidebar: add `<a data-section="newmembers">New Members</a>` (with badge count of eligible).
8. Section `#section-newmembers`: header, filter bar (search, status, age group, section, registration type, eligible-only toggle), paginated table with columns: Member, Code, Registered, Months, Status badge (green "Eligible" when ≥6mo), Actions (View/Complete & Promote).
9. New JS `admin/js/new-members.js` using PaginatedList with `view=new_members`, `membership_tier=temporary`; renderRow builds green badge when months>=6; wires "Promote" button → opens upgrade modal (reuse `openUpgradeModal`) with required-form enforcement.
10. `navigateToSection`: add `if (name === 'newmembers') { loadNewMembers(); }`.
11. Include the JS file in hr-dept.php.
12. Badge count on sidebar: query count of eligible temporary members (server-side PHP in hr-dept.php, like `$eligibleUpgrades`).

### Phase D — Info dept (viewer/data-updater only)
13. Add a read-only "New Members" view in info-dept.php (list of temporary members), reusing the same endpoint with view=new_members. No promotion action, no eligibility/promotion notifications. Info may update profile data via existing manage flow.

### Phase E — Tests
14. Python security tests:
   - Eligibility query returns only temporary + ≥6mo.
   - Cron detector dedups (second run notifies zero).
   - Promotion sets tier=permanent, audit action = "Member Promoted to Permanent", notification type = member_promoted.
   - Required signed form enforced on promotion.
15. PHP lint (CI) passes.

## 10. DATABASE CHANGES

- New column `members.permanent_eligibility_notified_at DATETIME NULL`.
- Index on `(membership_tier, status, registered_at)` (optional, for detector query).
- Migration file + auto_fix repair entry.

## 11. API CHANGES

- `api_list_members.php` accepts `view=new_members` and `membership_tier` filter (additive, backward compatible).
- New notification types: `member_eligible_permanent`, `member_promoted`.
- New cron endpoint (CLI-only, not web-exposed).

## 12. SECURITY CHANGES

- New column nullable, no PII.
- Cron script must be CLI-only (access_control exempts CLI; add guard `if (php_sapi_name() !== 'cli') exit`).
- Promotion still requires hr_dept/super_admin role + CSRF + idempotency key (existing).
- Required signed-form upload enforced server-side (not just UI).
- No new public endpoints.

## 13. UI/UX CHANGES

- New sidebar item + section in HR dashboard only.
- Green badge for eligible members; amber for pending.
- Filter bar mirroring Manage Members.
- Promotion modal reuses existing upgrade form with required-field enforcement + upload.

## 14. TESTING

- Unit: MemberDirectoryService view projection, eligibility filter.
- Integration: cron detector dedup; promotion audit/notification.
- Regression: All Members / Manage / Archive still load; existing member_registered notifications unchanged for new registrations.

## 15. REGRESSION CHECK

- Verify `onMemberRegistered` still called for genuinely new registrations (upgrade branch now calls `onMemberPromoted`).
- Verify idempotency key handling in hr_register_member unchanged.
- Verify `PaginatedList` reuse doesn't break manage-members.

## 16. REMAINING RISKS

- Timezone: server CURDATE() vs app TZ (Africa/Addis_Ababa) — verify in production.
- Mobile sync of tier change — not traced; may need sync_change_feed hook on promotion.
- Info dept: viewer/data-updater only; no promotion authority. Profile completion is optional and can happen later via existing manage flow.
- Cron scheduling is deployment-owned (not in repo).

## 17. ASSUMPTIONS

- "Phase 0 → Phase 1" = temporary → permanent (6 months).
- "New Members" section is HR-dashboard-scoped; Info dept gets a read-only viewer (no promotion, no notifications).
- Promotion requires signed registration form upload (user stated "their registration form also have to be uploaded").
- Cron schedule (e.g., daily) is configured by deployment, not shipped.

## 18. FINAL STATUS

**INCOMPLETE — ADDITIONAL WORK REQUIRED** (plan ready; implementation pending your approval + clarification on info-dept fields and mobile sync).
