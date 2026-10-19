# Attendance alerting — Phase 3 implementation notes (2026-10-19)

Phase 3 of `ATTENDANCE_ALERTING_IMPLEMENTATION_PLAN_2026-10-19.md` §5 —
**submission reopen / reject with reason + recalculation** — for
Education and Mezmur. Requirements baseline: §5 of
`ATTENDANCE_DEPT_STATUS_ALERTS_REQUIREMENTS_2026-10-19.md` (unchanged).

Owner go-ahead: "continue to the next phase" (2026-10-19, after Phase 2
`9719fb3`).

## 1. What was built

| Area | Change |
|---|---|
| `sql/073_attendance_submission_events.sql` (NEW, owner runs in phpMyAdmin) | append-only `attendance_submission_events (department edu/mezmur, packet_id, from_status, to_status, actor_id, reason, channel, created_at)`. The packet tables keep only the *last* reviewer decision and clear it on resubmission; this table is the durable "who submitted / who reopened or rejected / when / why" trail §5 asks for. Never updated or deleted. Code degrades gracefully until the table exists. |
| `AttendanceSubmissionEventService` (NEW) | `record()` (best-effort, never throws), `forPacket()` (EC timestamps only), `eventFor()` labels: Submitted, Resubmitted after correction, Approved, Returned for correction, **Reopened for correction (was approved)**, Rejected (was approved). |
| `ReviewTransitionPolicy::reopenError()` / `isReopenable()` | department-neutral rule: a packet that is **submitted or approved** may be returned (`revision_needed`) or rejected — with a reason. Approving keeps the strict "only from submitted" rule (`error()` untouched, hardening pins intact). |
| `SubmissionService::reviewPacket()` (NEW, Education) | **one** decision path now shared by the web console (`api_communication.php review_submission`) and mobile (`POST /grades/submission-review`): reason ≥ 3 chars for every non-approve decision; H9 transition guard; race-safe guarded `UPDATE … WHERE id=? AND status=?` (bound to the status that was read); `SecurityAuditService` trail; history event; **attendance-warning recalculation for every member on the sheet**. Reopening an *approved* **attendance** sheet is allowed for `edu_dept` only (`ATTENDANCE_REOPEN_ROLES`); mark lists keep the old rule. |
| `SubmissionService::upsertAttendance()` | appends a `submitted` / `resubmitted` event whenever a packet enters the submitted state (web `channel=web`, mobile `channel=mobile`). |
| `MezmurSubmissionService::reviewPacket()` | same rule for Mezmur packets (`REOPEN_ROLES = ['mezmur_dept']`), second guarded statement `… AND status = 'approved'` for the reopen case, history event, recalculation. `upsert()` appends submitted events. Callers (`admin/api_mezmur.php`, `/api/v1/mezmur/submission-review`) now pass the actor role + channel; forbidden → 403. |
| `SubmissionService::detail()`, `MezmurSubmissionService::detail()` | `events[]` + `can_reopen` for the UIs (web + the existing mobile detail endpoints). |
| Web Education inbox (`admin/dashboards/edu_dept.php`) | new **Approved** tab; review modal shows the packet **History**; for an approved attendance sheet `edu_dept` gets "Reopen for correction" / "Reject sheet" with a required reason (admins see history only). |
| Web Mezmur (`frontend/pages/mezmur_dept.php`, `frontend/js/mezmur.js`, `themes/components.css`) | new **Approved** tab; review + packet modals show History; reopen note for approved packets. No inline styles (page rule). |
| Tests | `tests/security/test_attendance_phase3.py` — 9 static pins + 3 live flows (reason required; reopen → warning `invalidated` but kept open; teacher resubmit → valid again; school_admin 403 on approved sheets; teacher 403; mobile 422 without reason; approving an approved sheet still refused; history exposed with EC-only timestamps; Mezmur same rule). `test_phase9_mobile_reviews.py` pin follows the delegation. |

## 2. Rules (as implemented and tested)

- **Submitted = official** (T‑3). Returning or rejecting a *submitted* packet was already possible with a reason; Phase 3 adds the same for *approved* attendance packets, department role only.
- **Reason mandatory** (≥ 3, ≤ 500 chars) for `revision_needed` and `rejected`, on web and mobile, both departments.
- **Recalculation** after every transition (approve / return / reject / reopen) and after every (re)submission: `AttendanceWarningService::safeEvaluateMany` for all members on the packet. A reopened or rejected sheet is no longer eligible (`AttendanceRiskService` counts only `submitted|approved` packets), so streaks and month rates drop; an open warning that no longer meets its threshold gets an `invalidated` event and `below_threshold = 0` — **it is never deleted** and still has to be closed with an outcome (T‑7). When the teacher resubmits, the sheet counts again and the same warning row is re-validated.
- **Admins** (owner decision Q‑1, 2026‑10‑19): School Admin / Super Admin may still **approve** a submitted attendance sheet, but every other decision on an attendance sheet — return, reject, reopen — whether it is *submitted* or *approved*, is refused (403) for everyone except the owning department (`edu_dept` / `mezmur_dept`). Mark lists keep the historical reviewer roles. (Whether admins should keep *approve* was not asked — kept; say so if it should go too.)
- **Bell to the sheet owner** (owner decision Q‑2): every return / reopen / reject inserts ONE `notifications` row of type `attendance_submission_review` targeted at the packet's `teacher_id` / `taker_id` only (`target_user_id`, no role broadcast), priority `high` for reject, `normal` otherwise, EN + AM text with the reason and the Ethiopian‑calendar date; `data` carries `submission_id, department, class_id | section, attendance_date (machine), previous_status, new_status, reopened, reason`. Best‑effort (never fails the review). `NotificationCenterService::targetFor` adds `target: {kind: 'attendance_sheet', department, class_id, section, attendance_date}` for those rows; older apps see an unknown kind and just mark the row read. Approvals send no bell (unchanged).
- **`members.status` is never written** by any Phase 3 path (static pin).
- **Audit**: `SecurityAuditService` ("Submission Reviewed" / "Mezmur Submission Reviewed", with `previous_status`, `reason`, `reopened`, `channel`) **and** the new per-packet history.
- **Ethiopian calendar only** in the history (`created_ec`); DB storage stays Gregorian.

## 3. Verification

- `php -l` on every touched PHP file; `node --check` on `frontend/js/mezmur.js` and the inline scripts of `edu_dept.php`.
- `tests/security/test_attendance_phase3.py`: **14/14** standalone (live flows on the local stack with sql/071–073) — now also pins Q‑1 (admins 403 on *submitted* sheets, Education web + Mezmur mobile), Q‑2 (bell row to the owner, EC‑only text, deep‑link data, feed `target.kind = attendance_sheet`, refused decisions send no bell) and the Q‑3 Flutter pieces.
- Attendance phase 1/2/3 + `test_review_transition_hardening.py` + `test_phase9_mobile_reviews.py` + `test_class_dependency_closure.py`: 80 passed (2 known rate-limit skips).
- Full security suite: **2243 passed / 0 failed / 440 skipped** (3 extra skips = the per-IP login cap when every live file runs in one go; phase 3 passes standalone).
- Flutter app **1.7.1+38** (owner decision Q‑3 — "next APK"): `ReviewDetailScreen` shows, on an *approved* attendance sheet and only for `edu_dept` / `mezmur_dept` accounts (server `can_reopen` + role), a bottom bar **Reopen for correction** / **Reject sheet** (reason dialog, same `reviewSubmission` endpoint); a **History** card renders the server `events[]` (label, actor, EC stamp, reason); the reason dialog names the action (Return / Reopen / Reject). Bell deep‑link: `sheetTarget()` in `inbox_view_model.dart` routes `attendance_sheet` rows to `AttendanceScreen(initialClassId, initialDate)` (new `initialDate` param) or `MezmurAttendanceScreen(initialDate, initialSection)`. `flutter analyze` 0 errors, `flutter test` 610/610 (new `test/attendance_phase3_review_test.dart`). Version pins (`test_auth_outbox_rollout_gates.py`, `test_comm_offline_first.py`, `test_mezmur_art.py`), `app_release.php` / example and `RELEASE_NOTES.md` moved to 1.7.1 / 38.

## 4. Owner steps (production, in order)

1. `git pull` (after Phase 2 steps are done: sql/071, sql/072, feature flag, rollout).
2. phpMyAdmin: run `sql/073_attendance_submission_events.sql` (idempotent).
3. Spot-check: Education → Submissions → **Approved** tab → open an attendance sheet → History + "Reopen for correction"; Mezmur → Submissions → Approved → Review.
4. Build and publish APK **38** (`flutter pub get && flutter test && flutter build apk --release`, upload via the release page, set latest = 1.7.1 / 38, keep `min_build`). If build 37 was never published, publish 38 directly — it contains everything from 37. No new SQL for this follow‑up.

## 5. Owner decisions on the Phase 3 questions (2026‑10‑19) — implemented in the follow‑up commit

- **Q‑1 YES** — School Admin / Super Admin can no longer *return/reject submitted* attendance packets (Education and Mezmur); only the owning department decides anything other than approve. Implemented in `SubmissionService::reviewPacket` (attendance packets only) and `MezmurSubmissionService::reviewPacket`.
- **Q‑2 YES** — reopen / return / reject sends an in‑app bell with the reason to the teacher / taker (`attendance_submission_review`, see §2); tapping it opens the sheet in the app (1.7.1+38) and in the web bell it behaves like any other notification.
- **Q‑3 next APK** — mobile reopen / reject of an approved sheet + history shipped in 1.7.1+38 (see §3).
- Open (not decided, current behaviour kept): whether admins should also lose **approve** on attendance sheets. Today they keep it.

## 6. Not done (next phases, each needs approval)

Phase 4 (monthly roll-up + shared "Monthly attendance" partial in Education, Mezmur, HR and Information dashboards; unify all at-risk consumers), Phase 5 (make-up queue), Phase 6 (lesson-level attendance).
