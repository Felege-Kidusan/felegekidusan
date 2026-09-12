# Notification, Messaging & Announcement System — Overhaul (P72)

**Status:** DESIGN LOCKED → implementation
**Scope:** entire system + database (user-authorized), web + mobile, zero breakage
**Protocol:** Universal Change Protocol v1.0 + AI Design OS (UI work)

---

## 1. Request Contract

| | |
|---|---|
| **Objective** | (1) Professional, industry-standard notification system across all dashboards/departments/users; (2) messaging + announcement feature for necessary areas (education dept ↔ teachers, dept ↔ attendance takers, similar); (3) modern UI/UX on web + Flutter app, fully responsive, following AI Design OS. |
| **Current problem** | "unknown functionality and unstable, confusing, uninteractive notification bar" — verified below: 5 real defects. |
| **Constraints** | No unrelated files/logics; nothing working may break; pinned tests must stay green. |
| **Acceptance** | Fully functional, clear notification system + announcements/messaging on web and mobile without breaking anything. |
| **Non-goals** | Email/SMS/push-provider delivery (in-app only — no provider infra exists in deployment); real-time WebSockets (shared-hosting PHP — smart polling instead); member-facing app features (staff tool). |

---

## 2. Current-State Trace (VERIFIED, line numbers)

### 2.1 Schema
- `notifications` (sql/012_runtime_schema_baseline.sql L4-26): one row per event, `type/title/message/data/priority/source_dept/source_user_id/target_roles (CSV)/target_user_id/is_read/read_at/created_at`. **Read state is GLOBAL to the row** — no per-recipient state. Broadcast = both targets NULL.
- `department_tasks` — cross-dept tasks (from_dept/from_user/to_dept/to_user/related_member/priority/status). Shown in bell's Tasks tab.
- `member_changes` — dept-sync change log (`changes` action, restricted to member-management staff).
- No announcements table. No messaging tables. No per-user read state anywhere.

### 2.2 Backend
- `admin/backend/workflow.php` — `$DEPT_ROLES` (7 roles, **stale — missing teacher, attendance_taker, mezmur_attendance_taker, hr_attendance_taker, content_editor**), `$NOTIFICATION_MATRIX` (event→roles), `sendNotification()` (L67), `getUnreadNotifications()` (L117), `getUnreadNotificationCount()` (L155), `markNotificationRead()` (L185 — recipient-checked, sets global `is_read`), `markAllNotificationsRead()` (L206), `getPendingTasks()` (L410), `updateTaskStatus()` (L449), `logMemberChange()`, task helpers.
- Producers: `api_education.php:167` (class_enrolled), `AttendanceSummaryService.php:282` (attendance issues), `workflow.php` L391 (task_assigned), L526 (role_changed), L628 (member_registered), L673 (member_archived), `api_attendance.php:191` (attendance_alert — raw INSERT + 7-day LIKE dedupe).
- `admin/api_notifications.php` — actions: list, count, mark_read, mark_all_read, tasks, task_update, changes, sync_change. POST-only + CSRF enforced. `backend/api/notifications.php` = redirect shim; `backend/components/notification_bell.php` = byte-identical duplicate (legacy path).

### 2.3 Web UI — the confusion, precisely
- **Shared bell** (`admin/components/notification_bell.php`, 513 lines): included ONLY by hr-dept (L766), info-dept (L609), super-admin (L667).
- **school_admin.php** (L299, L604-607): its OWN hand-rolled mini bell — a second implementation.
- **NO bell at all on:** edu_dept, teacher, attendance_taker, dept_taker, content_editor, material_department, frontend/pages/finance_dept.php, frontend/pages/mezmur_dept.php — **7 of 12 live dashboards have no notifications**, including the Education department that `$NOTIFICATION_MATRIX` explicitly targets (member_registered, member_archived, role_changed) and teachers (task_assigned targets).
- Routing (admin/dashboard.php L54-118): super_admin, school_admin, hr_dept, info_dept, edu_dept, material_dept, teacher, attendance_taker, mezmur/hr_attendance_taker→dept_taker, content_editor; finance_dept + mezmur_dept → /frontend/pages/* (layouts/base.php, `window.APP` bridge, themes/ CSS).

### 2.4 Defects (root causes of "unstable, confusing")
1. **D1 — CSRF broken on the shared bell (functional bug):** `api_notifications.php` requires CSRF on POST (403 without token), but notification_bell.php's `markRead()`, `markAllRead()`, `updateTask()` POST **without** `csrf_token`. Every write silently fails (fetch doesn't reject on 403) — badge never clears, read state never persists. *This is the reported instability.*
2. **D2 — global read state:** one user marking a role-targeted notification read makes it vanish for every user of that role (`is_read` is a column of the row). `mark_all_read` by one HR user clears the whole department's badge.
3. **D3 — no history:** only unread rows are ever fetched; once read (even accidentally via D1's opposite path) a notification is gone forever. No feed page, no filters, no pagination.
4. **D4 — coverage gaps:** 7 dashboards with no bell; edu dept + teachers (the primary collaboration pair per the use case) are blind. `$DEPT_ROLES` stale.
5. **D5 — duplicated/conflicting implementations:** 2 bell implementations + a byte-identical backend/ copy; `event.target` implicit-global usage; badge mixes alerts+tasks.

### 2.5 Mobile (VERIFIED)
- Flutter app (role-based, IndexedStack shell, floating bottom nav, no shell AppBar; role homes have SliverAppBar with actions). No notification feature anywhere (grep: only the mezmur audio player's media notification).
- api/v1: bearer-token auth (routes/auth.php), ACL via `apiRequireAuth()/apiRoleIs()/err()` (core/acl.php), rate limiting helper `isApiRateLimited()`. **No notifications route.**

### 2.6 Pinned contracts that MUST keep passing
- `tests/audit/http_regressions.py`: anon→401 (changes); GET on write actions→405 + `Allow: POST` (mark_read, mark_all_read, task_update, sync_change); CSRF-missing→403; test_16 recipient-scoping (teacher cannot mark finance-targeted note read; `notifications.is_read` must stay 0 then become 1 for a real recipient); test_32 user-delete keeps private notifications from becoming broadcasts.
- `tests/security/test_runtime_schema_ownership.py`: `notifications` table is migration-owned (config must not create it).
- `admin/backend/user-delete.php` L120-127: notification/task cleanup SQL — schema and columns it touches must remain.
- `frontend/js/core.js` provides `window.toast()` and the `escapeHtml` alias used by the old bell.

---

## 3. Industry Research (what "professional" means here)

- **Per-recipient read state is the standard** — one delivery row per recipient (fan-out on write) or a per-recipient read pivot; composite index `(recipient, read, created)` serves the feed [oneuptime.com MySQL notification guide; SO Facebook-schema thread]. At school scale (tens of staff), a **lazy read-pivot** (no fan-out writes) gives identical UX with fewer writes.
- **Novu** (leading OSS notification infra, 39k★): inbox feed with read/unread + unseen badge, tabs by category, mark-all, preferences, embeddable center component [github.com/novuhq/novu; docs.novu.co]. We implement the same inbox semantics natively (no Node infra in deployment — user authorized research, but the architecture is shared-hosting PHP/MySQL; borrowing the *model*, not the runtime).
- **Meta/Airbnb production pattern:** fan-out on write for normal accounts; hybrid only at celebrity scale [intervu.dev notification-service guide] — confirms lazy/simple fan-out is correct at our scale.
- **Unread badge:** exact counts matter less than presence; cached/cheap count queries [softwareengineering.SE]. We compute counts in one indexed query per poll.
- **Delivery transport:** shared hosting without a socket server → **smart polling** (30s visible, paused when hidden, instant on focus) — the established LAMP pattern; WebSocket/SSE explicitly out of scope.

---

## 4. Design — "WBWS Communication Center"

### 4.1 Unified model (one general system)
```
notifications            — the EVENT STREAM (what happened, who it addresses)
notification_reads       — per-USER read state (one pivot for all subjects)
announcements            — dept → audience broadcasts (rich content)
message_threads/messages — two-way messaging (dept ↔ teachers etc.)
department_tasks         — unchanged (existing tab)
```
`notification_reads (user_id, subject_type ENUM('notification','announcement','message_thread'), subject_id, read_at)` — **one pivot serves all three domains** (Novu-style read/unseen semantics, minimal schema).

- **Alert feed for user U** = notifications addressed to U (role match / direct / broadcast) `LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=U AND subject_type='notification'` WHERE `nr.id IS NULL` → unread.
- `notifications.is_read` column: **kept and still written** by mark_read/mark_all_read (pinned test_16 reads it) — becomes "at least one recipient read it" (its effective meaning today).
- Unread badge = alerts + announcements + message-threads-with-new-messages (+ tasks count, shown separately in tab).

### 4.2 New schema (sql/042_notification_center.sql — additive only, idempotent)
1. `notification_reads` (above) + `uk_user_subject(user_id,subject_type,subject_id)` + `idx_user_type_read`.
2. `announcements`: id, title VARCHAR(200), body TEXT, priority ENUM('normal','high','urgent'), audience_type ENUM('roles','users'), target_roles VARCHAR(255) NULL, target_user_ids TEXT NULL (JSON array), created_by, source_dept, is_pinned, expires_at NULL, created_at. Read state via pivot. Guards: table-existence checks like 012.
3. `message_threads`: id, subject VARCHAR(200), created_by, created_at, last_message_at.
4. `message_thread_participants`: thread_id, user_id, added_by, UNIQUE(thread_id,user_id).
5. `messages`: id, thread_id, sender_id, body TEXT, created_at, KEY(thread_id,created_at). Read state via pivot (read_at = last-open time → unread count per thread = messages after read_at).

### 4.3 Permissions (config-level constants — no new tables; matches repo doctrine)
- `ANNOUNCE_SEND`: super_admin, school_admin → all roles; edu_dept → teacher, attendance_taker (+self-dept users); hr_dept → hr_attendance_taker; mezmur_dept → mezmur_attendance_taker; info_dept → none (read-only recipients); finance/material → none (announced TO, not FROM).
- `MESSAGE_PARTNERS` (two-way): super/school ↔ everyone; edu_dept ↔ teacher, attendance_taker, school_admin, super_admin, edu_dept; teacher ↔ edu_dept, school_admin, super_admin (teachers cannot message other departments — anti-spam); hr ↔ hr_attendance_taker + admins; takers ↔ their owning dept + admins; info/finance/material ↔ admins + own dept.
- Both matrices live in ONE place: `NotificationCenterService` (single source of truth, same doctrine as MemberCategory in P71).

### 4.4 Service — `admin/backend/services/NotificationCenterService.php`
Static methods, mysqli (matches repo style): `addressedWhere()` (shared SQL predicate), `feed()`, `unreadSummary()`, `markRead()`, `markAllRead()`, `postAnnouncement()`, `listAnnouncements()`, `markAnnouncementRead()`, `announceTargets()`, `threadsFor()`, `threadMessages()`, `startThread()`, `sendMessage()`, `markThreadRead()`, `canAnnounce()`, `canMessage()`, `ROLE_LABELS` (all 12 live roles — fixes stale $DEPT_ROLES). All writes audit via existing patterns where applicable.

`workflow.php` keeps every public function signature; internals of the four notification functions delegate to the service (pivot-aware) and still write `is_read` (compat).

### 4.5 API — `api_notifications.php` (additive; all pins untouched)
New actions: `summary` (badge counts), `feed` (paginated history + filter), `announcements`, `announcement_read`, `compose` (announcement — POST, permission+CSRF), `targets` (who may I address), `threads`, `thread` (messages of one), `send_message` (POST), `thread_read` (POST), `partners` (who may I message). New write actions appended to `requirePostActions`.

### 4.6 Web UI
1. `admin/components/notification_center.php` — ONE shared component (replaces notification_bell.php; old file keeps working as a thin include for the legacy backend/ path):
   - Bell + total badge; dropdown with tabs **Alerts / Announcements / Tasks** (per-tab counts), mark-all per tab, unread dots, priority-colored icons by type map, relative timestamps, skeleton loaders, empty states, "See all →" links.
   - **CSRF embedded by the component itself** (fixes D1 at the root; no host-page dependency).
   - Smart polling: 30s visible / paused hidden / instant on focus. Announcements > urgent get a distinct style.
   - Responsive: full dropdown ≥769px; **bottom sheet ≤768px** (Task-5 decision: phone = iOS bottom sheet). Dark/light adaptive, CSS-var driven, no external assets (Design OS: reuse host theme tokens; inline styles only).
2. Bell on **every** dashboard: edu_dept, teacher, attendance_taker, dept_taker, content_editor, material_department + existing three; school_admin's inline bell **replaced**; finance + mezmur frontend pages get it via their headers.
3. `admin/notifications.php` — full Notification Center page (All/Unread, type filters, announcements section, composer for permitted roles).
4. `admin/messages.php` — messaging page (thread list + conversation pane + compose with partner picker), permission-gated.
5. edu_dept dashboard: **"Announcements" quick action** (the user's headline use case) — opens composer modal targeting teachers/takers; plus a "Message Teachers" shortcut.

### 4.7 Mobile (Flutter + api/v1)
1. `api/v1/routes/notifications.php` — GET summary/feed/announcements/threads/thread; POST mark-read/mark-all/announcement-read/send-message/thread-read. Bearer-auth, role-checked, rate-limited, same service as web (one writer).
2. `lib/services/notification_service.dart` — ApiService-based client + unread badge stream.
3. `lib/screens/notifications/notification_center_screen.dart` (tabs: Alerts / Announcements) + `messages_screen.dart` (threads + conversation, bottom-sheet composer).
4. Bell button (`widgets/notification_bell_button.dart`) added to each role home's AppBar actions + badge; follows AppTheme + existing screen patterns (ReviewInboxScreen as list-pattern reference).

### 4.8 Explicit non-behavior-changes
- Existing notification PRODUCERS untouched (sendNotification signature same; matrix same).
- department_tasks, member_changes flows untouched.
- `is_read` still written; user-delete cleanup SQL untouched.
- Legacy `backend/` shims keep functioning.
- Mezmur audio media-notification untouched.

---

## 5. Change Plan (ordered; each step keeps the matrix green)

1. `sql/042_notification_center.sql` — 5 new tables, guarded, idempotent.
2. `NotificationCenterService.php` — the single writer + permission source.
3. `workflow.php` — delegate 4 functions (signatures unchanged).
4. `api_notifications.php` — additive actions.
5. `components/notification_center.php` — new shared bell; include on all 12 dashboards; replace school_admin inline; old bell file → compat wrapper.
6. `admin/notifications.php` + `admin/messages.php` pages; edu_dept composer shortcut.
7. `api/v1/routes/notifications.php` + Flutter service/screens/bell integration.
8. Tests: new `test_notification_center.py`; verify http_regressions pins unchanged; full matrix vs baseline.
9. Result log here + commit + push + final report.

## 6. Impact Map
- **DIRECT:** sql/042, service, workflow.php internals, api_notifications.php, bell component, 12 dashboard headers, 2 new pages, api/v1 route, Flutter (service+2 screens+~9 home app bars+shell).
- **INDIRECT:** http_regressions (must stay green — no edits), user-delete (no schema columns it touches change), core.js (unchanged; toast reused).
- **UNRELATED (untouched):** all department business logic, identity system, P71 section system, mezmur subsystem, members/attendance/grades APIs.

---

## 7. Result Log (implementation complete)

All change-plan steps executed; verified against the full matrix.

| Area | Result |
|---|---|
| `sql/042` | 5 tables: `notification_reads` (per-user read state, one pivot for notifications/announcements/threads), `announcements`, `message_threads`, `message_thread_participants`, `messages`. Pure idempotent DDL. `notifications` (012) untouched. |
| `NotificationCenterService` | The single writer: ROLE_LABELS (all 13 live roles — stale `$DEPT_ROLES` fixed), ANNOUNCE_SEND + MESSAGE_PARTNERS matrices, feed/markRead/markAllRead (still writes legacy `is_read` for pinned audit test_16), announcements (roles + padded-CSV user targeting — no JSON functions, portable), threads/messages, unreadSummary. No `$_SESSION` inside → web sessions and api/v1 bearer tokens share one writer. |
| `workflow.php` | 4 notification functions delegate to the service; every signature preserved; producers (`sendNotification` matrix) untouched. |
| `api_notifications.php` | Pinned actions byte-compatible (405/403/401/scoping pins intact). Added: summary, feed, announcements, announcement_read, compose, targets, partners, threads, thread, thread_start, send_message, thread_read; `mark_all_read` gained optional `scope`. New write actions POST+CSRF enforced. |
| Bell component | New `notification_center.php`: self-contained CSRF (fixes defect D1 at the root), per-user badges (D2), Alerts/Announcements/Tasks tabs, history + mark-all (D3), multi-instance safe (class-based, assets emitted once — sidebar dashboards render it twice), smart polling (30s visible / paused hidden / instant on focus), bottom sheet ≤768px, aria + reduced-motion. Old bell files → thin shims. |
| Coverage (D4) | All 12 dashboards + both frontend pages + both new pages: super-admin, school_admin (inline duplicate removed — D5), hr, info, edu (sidebar + mobile header + Announce/Message Teachers quick actions), material (×2), teacher, attendance_taker, dept_taker, content_editor, finance, mezmur. |
| New pages | `admin/notifications.php` (full history, announcements, composer w/ group+people picker, `#compose` deep link) and `admin/messages.php` (threads + conversation + composer, mobile master-detail). Zero business logic in pages; registered in access_control for all 13 roles. |
| api/v1 | `notifications.php` route registered; 13 actions; bearer-auth, rate-limited, delegates to the same service. |
| Flutter | ApiService notification methods; `NotificationService` (one badge stream, 30s poll, stops on sign-out); `NotificationBellButton` on all 10 role homes; Notification Center screen (tabs + composer) + Messages screen (threads/conversation). |
| Tests | NEW `test_notification_center.py` (25 tests). Mezmur zero-inline-styles pin PRESERVED (bell slots via theme classes). Full matrix: **723 tests / 42 failing = exact pre-existing environmental baseline. Zero new failures.** |
| Special requirement | Nothing downloaded — no SDKs/packages to clean up. |

Known follow-ups (non-blocking): tasks tab has no "create task" UI (pre-existing flow); announcements have no edit/delete (audit-trail-preserving by design); delivery is in-app only (email/SMS/push out of scope per §1 non-goals).
