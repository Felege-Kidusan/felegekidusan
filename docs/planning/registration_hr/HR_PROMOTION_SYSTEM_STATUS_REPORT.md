# System Status Report — Temporary → Permanent Member Promotion & HR Notification

**Scope:** HR department workflow for promoting temporary members to permanent after 6 months, and notifying HR (and Information dept) when a member reaches that milestone.
**Basis:** source code in `/home/user/SSMS` only. No DB/runtime available. No code modified.

---

## 1. Current State (VERIFIED)

### 1.1 Membership tiers
- `members.membership_tier` ENUM('temporary','permanent'), default 'permanent' (`admin/migrations/006_add_temporary_members_tier.php`).
- `members.registered_at` DATE set to today on every registration (`admin/hr_register_member.php`).
- `members.waiting_since` DATE set to today only when `registration_type = 'waiting'`.

### 1.2 The 6-month rule (exists, but only as a dashboard query)
- `admin/dashboards/hr-dept.php` (~lines 144–160) selects:
  ```sql
  WHERE membership_tier = 'temporary' AND status != 'archived'
    AND registered_at <= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  ```
  UI label: **"Eligible for Permanent Upgrade (6+ Months)"** (~line 893).
- There is **no cron/scheduled job** that detects this condition. Detection is manual (HR opens dashboard).
- There is **no auto-notification** when a member crosses the 6-month mark.

### 1.3 Promotion flow (exists, manual)
- HR dashboard lists eligible temporary members.
- "Upgrade" button → `toggleMemberRegistrationForm(true, 'permanent', 'Upgrade to Permanent Member')` → `openUpgradeModal(memberId)` fetches member data → form prefilled → `upgradeMemberIdField` set.
- Submit → `admin/hr_register_member.php` with `upgrade_member_id` > 0 → UPDATEs the existing member row (membership_tier becomes 'permanent' via hidden field `membershipTierField`), keeps original code/registered_at/waiting_since.
- Post-commit: `onMemberRegistered($conn, $newId)` is called (line ~600) → logs `member_changes` ('registered') + sends `member_registered` notification + (if direct/transfer) creates edu_dept task.

### 1.4 Notification system
- `admin/backend/workflow.php`: `sendNotification()` inserts into `notifications` with `target_roles` CSV; `NotificationCenterService::feed()` filters by `FIND_IN_SET(role, target_roles)`.
- `$NOTIFICATION_MATRIX['member_registered'] = ['super_admin','school_admin','edu_dept']` — **does NOT include hr_dept or info_dept**.
- HR dept receives notifications only via `task_assigned` (when a task targets `hr_dept`) or direct `target_user_id` or broadcast (NULL target_roles).
- Info dept receives: role_changed, teacher_assigned, class_enrolled, attendance_issue, document_shared, task_assigned.

### 1.5 Member change log / sync
- `logMemberChange()` writes to `member_changes` (requires_sync, synced_to CSV). `getUnsyncedChanges()` lets other depts pull changes. Not currently used for the 6-month milestone.

---

## 2. Gaps (VERIFIED)

1. **No automated 6-month detection** — only a live dashboard query; nothing runs on a schedule.
2. **No notification on milestone** — HR is never alerted that a member became eligible; HR must open the dashboard to see the widget.
3. **No dedup/state** — if automation were added naively, it would re-notify every run (no "notified" flag/column exists).
4. **HR not in `member_registered` targets** — even the promotion (upgrade) triggers `onMemberRegistered`, which notifies super_admin/school_admin/edu_dept, not HR.
5. **Info dept not involved** in the promotion workflow at all.
6. **Upgrade mislabeled as "registered"** — `onMemberRegistered` logs change_type='registered' and sends `member_registered` even during an upgrade; no distinct `member_upgraded`/`member_promoted` event exists.
7. **No mobile sync event** for the milestone/promotion (mobile consumes members via REST; sync_change_feed exists but not wired to this).

---

## 3. Desired Workflow (per user)

Temporary member reaches 6 months → HR notified → HR completes missing info → promotes member to permanent.

---

## 4. Recommended Minimal Design (PROPOSAL — not implemented)

**A. Add a milestone event type**
- New notification type `member_eligible_permanent` with matrix entry targeting `['hr_dept','info_dept','super_admin','school_admin']` (configurable).
- New change_type `eligible_for_permanent` logged to `member_changes`.

**B. Add dedup state (DB change — migration)**
- Option 1: new column `members.permanent_eligibility_notified_at DATETIME NULL` (migration + auto_fix). Preferred — simple, queryable.
- Option 2: reuse `member_changes` (check for existing `eligible_for_permanent` row). No schema change but heavier.

**C. Add a CLI/cron detector** (follow `admin/backend/profile_image_cleanup.php` / `cron_backup.php` pattern)
- Script: `admin/backend/notify_permanent_eligibility.php` (CLI-only, access_control exempts CLI).
- Finds temporary, non-archived members with `registered_at <= now-6mo` and `permanent_eligibility_notified_at IS NULL`.
- For each: create department task to `hr_dept` (and `info_dept` if info needed), fire `sendNotification('member_eligible_permanent', ...)`, set notified_at.
- Deployment-owned cron (document in PRODUCTION_WORKFLOW.md style).

**D. Fix upgrade labeling**
- In `hr_register_member.php`, when `$upgrade_id > 0`, call a new `onMemberPromoted($conn, $newId)` instead of `onMemberRegistered`, logging `member_promoted` and sending a `member_promoted` notification (targets hr_dept, info_dept, super_admin, school_admin). Keep `onMemberRegistered` for new registrations.

**E. HR dashboard**
- Already lists eligible upgrades; add "Mark processed" / promote action (exists). Optionally show notified_at and a "Complete info" checklist.

**F. Info dept**
- If info dept must fulfill fields, create a department task to `info_dept` on eligibility (via the detector) and/or on promotion.

---

## 5. Files Involved (VERIFIED)

| File | Role |
|---|---|
| `admin/dashboards/hr-dept.php` | Eligible-upgrades query + widget + upgrade UI |
| `admin/hr_register_member.php` | Registration + upgrade (UPDATE) flow |
| `admin/backend/workflow.php` | `onMemberRegistered`, `sendNotification`, `$NOTIFICATION_MATRIX`, `createDepartmentTask`, `logMemberChange` |
| `admin/backend/services/MemberRegistrationPolicy.php` | Tier validation |
| `admin/backend/services/NotificationCenterService.php` | Feed/roles/permissions |
| `admin/backend/services/MemberDuplicateService.php` | Duplicate handling during registration |
| `admin/backend/services/PositionSyncService.php` | Post-commit position/code sync |
| `admin/backend/services/SecurityAuditService.php` | Audit recording |
| `admin/migrations/006_add_temporary_members_tier.php` | Tier column migration |
| `sql/` (numbered) | Migration convention |
| `admin/backend/profile_image_cleanup.php`, `cron_backup.php` | CLI/cron pattern to follow |

---

## 6. Risks / Open Questions

- **Dedup**: must add notified-state before automation, else spam.
- **Upgrade path calls `onMemberRegistered`** → duplicate "registered" notifications/changes on promotion; should be split (proposal D).
- **Timezone**: `registered_at` is DATE in Africa/Addis_Ababa (config sets TZ); 6-month comparison uses DB CURDATE() (server TZ). Verify server TZ = Addis Ababa to avoid off-by-one. UNKNOWN — verify in production.
- **Mobile sync**: if mobile caches member tier, promotion must propagate; check sync_change_feed wiring. UNKNOWN — not traced.
- **Info dept scope**: unclear which fields info dept must "fulfill" — needs user clarification (what info is missing at registration?).
- **No tests** currently cover the 6-month eligibility or notification; new tests needed per protocol §22.

---

## 7. What is NOT being changed now

Per protocol, this is research only. No code, schema, or config modified. Awaiting your go-ahead and clarification on info-dept responsibilities before implementation.

## 8. Assumptions (explicit)

- "Phase 0 → Phase 1" in your message maps to `temporary → permanent` tier (6 months). If you meant a different lifecycle, say so.
- "HR notified" assumed to mean via the existing notification center + department tasks (not email/SMS — no email/SMS provider found in source).
- Cron/deployment scheduling is deployment-owned (as with backup/retention scripts).

## Final Status

**INCOMPLETE — ADDITIONAL WORK REQUIRED** (design proposed; implementation pending your approval + clarification on info-dept fields).
