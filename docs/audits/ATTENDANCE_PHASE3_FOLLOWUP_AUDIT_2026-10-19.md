# Audit — Attendance Phase 3 follow-up (`780bfd6`, Q-1 / Q-2 / Q-3)

Date: 2026-10-19 · Scope: every file in `c7b6284..780bfd6` (18 files), its callers and consumers, end to end (web, `/api/v1`, Flutter 1.7.1+38, tests, docs). Read-only audit; no code was changed while auditing. Labels: **FACT** (read in the repo / executed), **INFERENCE**, **UNKNOWN**.

## 1. Request being audited

Owner decisions on the Phase 3 questions: **Q-1** admins lose return/reject of *submitted* attendance packets (dept-only for every non-approve decision); **Q-2** bell to the teacher/taker on return/reopen/reject; **Q-3** mobile reopen/reject of an approved sheet in the next APK.

## 2. What was verified (FACT unless marked)

### 2.1 Server — Q-1 (department-only decisions)

| Path | Role gate before the service | Service rule (new) | Result |
|---|---|---|---|
| Web `admin/api_communication.php?action=review_submission` | `edu_dept, school_admin, super_admin` (line 259) | `SubmissionService::reviewPacket`: attendance packet + status ≠ approved + role ∉ `['edu_dept']` → `403 forbidden` (line ~998) | admins: approve only; teacher never reaches the service |
| Mobile `POST /api/v1/grades/submission-review` | `$__eduReviewRoles` = same three (line 1308) | same service; `err(message, 403)` | same |
| Web `admin/api_mezmur.php?action=submission_review` | `canReview` → `REVIEW_ROLES` (mezmur_dept, school_admin, super_admin) | `MezmurSubmissionService::reviewPacket`: status ≠ approved + role ∉ `['mezmur_dept']` → `code=forbidden`, caller maps to 403 (line 1177) | same |
| Mobile `POST /api/v1/mezmur/submission-review` | mezmur review roles | same service; `forbidden → 403` else 409 (line 765) | same |
| HR packets (`HrSubmissionService`) | untouched | untouched | no change (HR frozen) |
| Mark lists (`submission_type = marklist`) | unchanged | `$isAttendance` false → Q-1 check skipped; H9 rules as before | no change |

- The Q-1 check runs **before** the transition check, so an admin trying to reject a draft gets 403 (not 422). Harmless.
- Order of checks in both services: parameters → reason length → packet exists → **role (Q-1)** → transition (H9 / reopen) → guarded `UPDATE … WHERE status = expected` → audit → history event → recalc → **bell (Q-2)**. A refused or raced decision never reaches the bell (live test: "refused decisions send no bell").
- `$actorRole` is always taken from the session (`$_SESSION['admin_role']`) or the JWT (`$auth['rol']`), never from the request body.

### 2.2 Server — Q-2 (bell to the sheet owner)

- `SubmissionService::notifyTeacherOfReview` / `MezmurSubmissionService::notifyTakerOfReview`: prepared `INSERT INTO notifications (…, target_roles, target_user_id) VALUES (…, NULL, ?)`; `bind_param('ssssssii')` = 8 placeholders ✓; `priority` ∈ enum (`high` reject / `normal` otherwise) ✓; `title` ≤ 255 (longest observed 41 chars) ✓; `data` JSON (`JSON_UNESCAPED_UNICODE`) in `longtext` ✓; `source_user_id` nullable (actor may be null) ✓; early return when `teacher_id/taker_id ≤ 0` or date empty (so never a broadcast row — the feed treats `roles NULL AND user NULL` as broadcast) ✓; wrapped in `try/catch Throwable → error_log` (no `getMessage` next to `err(`/`json_encode`, disclosure guard passes) ✓.
- `ethio_date_format` exists in `admin/backend/ethiopian_date.php` (`dirname(__DIR__)` from `services/` resolves correctly); executed: `2026-10-02 → "Meskerem 22, 2019"` — EC only in the message; machine date only in `data.attendance_date` (consistent with the EC sweep rule) ✓.
- Feed: `NotificationCenterService::feed` WHERE `FIND_IN_SET(role) OR n.target_user_id = ? OR broadcast` → only the owner sees it ✓. Both web (`admin/api_notifications.php?action=feed`) and mobile (`/api/v1/notifications/feed`) use this service and return `target` ✓.
- `targetFor` adds `{kind: attendance_sheet, department, class_id, section, attendance_date}` only when `type = attendance_submission_review` **and** `submission_id > 0`; the existing `member` / `related_member_id` / `warning_id` branches are evaluated first and are untouched (test_notification_center pins still pass) ✓.
- Web bell (`admin/js/comm.js`): renders `esc(n.message)`; does **not** read `target` at all → new rows display and mark read like any other; no JS path can break ✓. Reason text (user input ≤ 500 chars) is escaped on web and rendered via Flutter `Text` (no HTML) ✓.
- Rows seen in the local DB after the live run (real output): `Attendance sheet rejected · ውድቅ ተደርጓል` / high / target 2 / "Your attendance sheet for 1ኛ ክፍል on Meskerem 21, 2019 was rejected (it was approved). Reason: …" — matches the design.

### 2.3 Flutter 1.7.1+38 — Q-3 + deep-link

- `ReviewDetailScreen`: `_canReopenApproved` = server `can_reopen` (only emitted for **attendance** packets by `SubmissionService::detail` line 1671 and by `MezmurSubmissionService::detail` line 864 — mark lists and HR never carry it) **and** role `edu_dept`/`mezmur_dept` for the matching dept. `bottomNavigationBar`: `open → _decisionBar()` (unchanged buttons), `approved && _canReopenApproved → _reopenBar()`, else `null` ✓. Both reopen buttons go through `_askReasonAndDecide(status, reopen: true)` → same `_decide` → `reviewSubmission(dept, id, status, notes)` → the server rules above ✓. History card reads `events[]` (label, actor_name, created_ec, reason) — keys match `AttendanceSubmissionEventService::forPacket` ✓. The cached detail (`LocalDb.cacheReviewPacketDetail`) stores the full JSON payload, so `events`/`can_reopen` survive offline ✓.
- Inbox 'approved' filter exists (`review_inbox_screen.dart` line 63/83) so dept users can reach approved sheets ✓.
- Deep-link: `sheetTarget()` (dept ∈ {edu, mezmur}, date validated `^\d{4}-\d{2}-\d{2}$`) → `AttendanceScreen(initialClassId, initialDate)` or `MezmurAttendanceScreen(initialDate, initialSection)`. `AttendanceScreen.initState` applies `initialDate` before `_loadClasses()`; class pick = `AppNav().attendanceClassId ?? widget.initialClassId` (line 182); both screens have `automaticallyImplyLeading: Navigator.canPop(context)` so the pushed route gets a back arrow ✓. Mobile notification rows are cached whole (`cacheNotificationRows` → `jsonEncode(m)`), so the `target` survives restarts ✓. Every role that can own a sheet has the bell button (teacher_home, att_taker_home, mezmur_home for mezmur_dept + mezmur taker) ✓. Older APKs: unknown `kind` → `memberTargetId` returns null → just mark read (unchanged behaviour) ✓.
- Toolchain: `flutter analyze` 0 errors (all warnings pre-existing, none in touched files); `flutter test` 610/610 incl. new `test/attendance_phase3_review_test.dart` ✓.

### 2.4 Tests, pins, release metadata

- `tests/security/test_attendance_phase3.py` 14/14 standalone with the 3 live classes actually running (not skipped): Q-1 (Education web: school_admin + super_admin × return/reject → 403 on a *submitted* sheet; Mezmur mobile: admin → 403 on submitted, dept → 200), Q-2 (one bell row, owner target, `target_roles NULL`, EC-only text, data block, feed `target.kind`, no bell after a refused decision), Q-3 static pins.
- Full security suite at `780bfd6`: **2245 passed / 0 failed / 440 skipped** (the 2 new tests account for 2243 → 2245).
- Version pins moved together: `pubspec.yaml`, `AppConfig.appVersion/appBuild`, `RELEASE_NOTES.md`, `api/v1/core/app_release.php` + example, `test_auth_outbox_rollout_gates.py` (incl. the `latest_version/latest_build` regex and test name), `test_comm_offline_first.py`, `test_mezmur_art.py` ✓.
- Schema: none. Dependencies: none. Config: none (no new flag; Phase 2 `FEATURE_ATTENDANCE_V2` unchanged — the bell and Q-1 do **not** depend on the flag; INFERENCE: intended, since they are review-flow rules, not alerting).

## 3. Findings

| # | Severity | Where | Finding (FACT) | Recommended fix |
|---|---|---|---|---|
| A-1 | **MEDIUM** (test asset, not production) | `tests/smoke/mezmur_phase5_smoke.php` lines 164/168/214 | Calls `MezmurSubmissionService::reviewPacket($conn,$pid,'revision_needed',…, 2)` **without** `$actorRole` (defaults to `''`). After Q-1 the "valid review" step returns `forbidden` → this manual CLI smoke script now **fails** at "valid review failed". Not part of the automated suite (it TRUNCATEs tables and runs only against a disposable DB), so the 2245/0 result did not catch it. | Pass `'mezmur_dept'` as the 6th argument in the three calls (one-line each). |
| A-2 | LOW (UX) | Flutter `ReviewDetailScreen._decisionBar()` + the "Reject packet" popup | For School/Super Admin on a *submitted* **attendance** sheet (Education) or any Mezmur packet, Return / Reject are still shown; tapping them now yields the server's 403 message ("Only the … department can return or reject…"). Correct and safe, but a dead-end button. `submission_type` is available in `_sub` for Education, so the UI can know. | In `_decisionBar()`/popup: when `(dept == 'mezmur' \|\| _sub['submission_type'] == 'attendance')` and role ∉ {edu_dept, mezmur_dept}, show **Approve only** (full width) and hide Return/Reject. Ship inside APK 38 (not built yet). |
| A-3 | LOW (UX) | Web `frontend/pages/mezmur_dept.php` (accessible to `super_admin, school_admin, mezmur_dept`, line 29) + `frontend/js/mezmur.js` review modal | Same as A-2 on the web Mezmur page: the decision dropdown offers Return/Reject to admins; the server refuses and `showError` displays the message inline (verified handler at line 2803). Education web inbox is **not** affected (dashboard `edu_dept.php` is edu-only; admins have no web Education review UI). | Emit the role to the page (one `data-` attribute) and hide the non-approve options for admins; or leave as is (graceful). |
| A-4 | LOW (cosmetic) | `MezmurSubmissionService::notifyTakerOfReview` | Bell text uses the raw section **code** (`children`) rather than the canonical labels (`MemberCategory::sectionEn/sectionAm`) used elsewhere. | Use `sectionEn()` in the English sentence and `sectionAm()` in the Amharic one, falling back to the code. |
| A-5 | LOW (test hygiene) | `tests/security/test_attendance_phase3.py::_clean` | New cleanup line `JSON_EXTRACT(data,'$.reason') LIKE 'P3-TEST%'` never matches because `JSON_EXTRACT` of a string keeps the quotes (verified: 0 rows vs 4 with `JSON_UNQUOTE`). Test-DB rows accumulate; assertions still hold because they are scoped by `submission_id`. Production unaffected. | Wrap in `JSON_UNQUOTE(...)`. |
| A-6 | INFO (doc drift) | `admin/api_communication.php` ~line 276, `api/v1/routes/grades.php` ~line 1366 | Comments still say "Approved ATTENDANCE sheets may be reopened / rejected by edu_dept only" — true but incomplete after Q-1 (now also submitted sheets). | One-line comment update each. |
| A-7 | OPEN QUESTION (not a defect) | Q-1 scope | Admins **still approve** submitted attendance sheets in both departments (never asked; left as-is and flagged in the notes §5). | Owner to confirm keep / remove. |

No HIGH or CRITICAL findings. No security regression found: all role decisions are server-side in the shared service, inputs are bound, outputs escaped, notifications are owner-scoped, no new endpoint, no schema change.

## 4. Regression surface checked

- Mark-list review (Education): `$isAttendance = false` → unchanged. HR review: untouched. Approve paths for admins: unchanged (live test approves as `edu_dept`; admin approve path unchanged in code — INFERENCE, not re-executed as admin).
- Phase 2 warning bells (`attendance_warning`, roles-targeted) and `warning_id` deep-link: untouched; `targetFor` new branch is after them.
- `test_review_transition_hardening.py`, `test_phase9_mobile_reviews.py`, `test_notification_center.py`, `test_class_dependency_closure.py`, `test_error_disclosure.py`, EC-only guards: all green in the 2245/0 run.
- Older APK (≤37) against the new server: review of submitted sheets by dept users unchanged; admins get a clear 403 message instead of success; bells render as plain rows.

## 5. Remediation (same day, owner-approved)

A-1…A-6 applied in the follow-up commit after this audit:

- A-1 `tests/smoke/mezmur_phase5_smoke.php` — all five `reviewPacket` calls pass `'mezmur_dept'`.
- A-2 Flutter `ReviewDetailScreen` — `_canReturnOrReject` hides Return / Reject (bar + popup) for admins on attendance sheets; Approve stays full-width. Analyze 0 errors, 610/610.
- A-3 `frontend/js/mezmur.js openReview` — non-approve options hidden/disabled unless `window.APP.role === 'mezmur_dept'`; admins opening an *approved* packet get a toast instead of the modal.
- A-4 `MezmurSubmissionService::notifyTakerOfReview` — `MemberCategory::normalizeSectionAm` + `sections()` give the Amharic / English labels (raw value kept as fallback and in `data`). Verified: `children → ህጻናት / Children`, `ወጣቶች → Youth`, unknown stays as-is.
- A-5 test cleanup uses `JSON_UNQUOTE` — 0 leftover rows after a run.
- A-6 comments updated in `admin/api_communication.php`, `api/v1/routes/grades.php`.
- Pins added to `test_attendance_phase3.py` (A-2/A-3 static, A-4 live). Suite after remediation: 2245 passed / 0 failed / 440 skipped.

## 6. Status

`COMPLETE — VERIFIED` — Q-1, Q-2, Q-3 and the regression surface above; A-1…A-6 remediated (§5). A-7 (admin *approve* on attendance sheets) remains an open owner question; current behaviour kept. Nothing here requires SQL or touches production data.
