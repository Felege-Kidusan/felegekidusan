# Attendance Rework — Default-Absent, Honest Drafts, HR Retirement (2026-10)

**Decision record + client/API contract.** Implemented 2026-10-07 in four
verified phases (commits `c3f7e7c`, `6385e94`, `c742580`, `9803203`).
Supersedes every earlier description of: unmarked-sheet attendance UX,
draft-save 422 behavior, and the HR attendance domain.

---

## 1. Product decisions (owner-approved)

1. **Default status is ABSENT** (positive attendance). Every student/member
   loads as *absent*; the teacher (or a QR scan) marks **present / late /
   excused**. Presence is never inferred or fabricated anywhere — the
   conservative failure mode of an untouched sheet is an all-absent day.
2. **Drafts are honest**: the mobile app's instant autosave deliberately
   sends partial sheets; the server accepts and **merge-upserts** them
   instead of rejecting with 422.
3. **Submit keeps the complete-roster requirement** (roster-drift
   protection). Updated clients satisfy it by construction.
4. **No per-student "defaulted vs explicit" tracking** — every stored row
   carries an explicit status; nothing records how it was chosen.
5. **HR attendance is RETIRED.** HR no longer takes or reviews attendance;
   the department reads combined **Education + Mezmur** reports instead.
6. **Mezmur gets the same fix** as class attendance; the **web taker**
   (`attendance_taker.php`, `teacher.php`) gets UX parity.
7. **No database migrations.** All merge paths land on unique keys that
   already exist (`uq_att_member_class_date` — sql/013;
   `uq_mezmur_attendance_date_member` — sql/023). HR tables (sql/026)
   stay as a permanent read-only historical archive.

## 2. Server contract (api/v1)

### Education — `POST /attendance` (draft) and `POST /attendance/submit`

| Aspect | Draft (`POST /attendance`) | Submit (`POST /attendance/submit`) |
|---|---|---|
| Completeness | **Partial sheet accepted** | Complete roster required (`INCOMPLETE_SHEET`) |
| Persistence | **Merge**: `INSERT … ON DUPLICATE KEY UPDATE` on `uq_att_member_class_date`; never deletes unmentioned rows | **Replace**: the day's complete sheet is the authority |
| Roster membership | Unknown member → 422 `ROSTER_MISMATCH` (always) | Same |
| Status values | `present / absent / late / excused` (always explicit) | Same |
| Idempotency / locks | unchanged | unchanged (`ALREADY_SUBMITTED`) |

Error payloads now carry a machine code: `INCOMPLETE_SHEET` vs
`ROSTER_MISMATCH` (both 422) — monitoring can tell a client bug apart
from roster drift. Validation lives in
`AttendanceRecordService::normalizeSheet($records, $roster, $requireComplete)`
with `AttendanceSheetInvalid::getErrorCode()`.

### Mezmur — `POST /mezmur/sheet`

Same split by packet kind: `kind=draft` → partial + merge
(`MezmurAttendanceService::saveSectionSheet(..., requireComplete: false)`
→ `upsertRows()`); `kind=submitted` → complete + replace. Foreign
members are rejected in **both** modes.

### HR — retired (410)

* `POST /api/v1/hr/sheet` and `POST /api/v1/hr/submission-review` →
  **410 Gone**, code `HR_ATTENDANCE_RETIRED`, for every role (not
  overridable).
* All **reads** stay (`/hr/sections`, `/hr/sheet`, `/hr/days`,
  `/hr/submissions`, `/hr/submission`) — history is preserved, nothing
  was dropped.
* Version handshake: `hr-retired-1` (was `phase6-hr26`).
* Old app versions surface the 410 through the outbox attention banner
  (410 classifies as needs-attention) with the server's exact message —
  no silent data loss.

### Web (admin) — read-only HR history + combined reports

* `admin/api_hr_attendance.php` — read actions unchanged;
  `submission_review` → 410.
* **NEW `admin/api_hr_reports.php`** (strictly GET, role-gated to
  super_admin / school_admin / hr_dept): `monthly_summary`,
  `member_search`, `member_detail` over `attendance` +
  `mezmur_attendance` only (never `hr_*` tables). Missing source tables
  degrade to zeros. Backs the HR dashboard's **Attendance Reports**
  section.
* `DeptTakerService::create` refuses new `hr_attendance_taker`
  accounts (existing accounts keep working; admins can still
  list/toggle).

## 3. Client behavior (mobile + web)

* **Class sheet** (`attendance_screen.dart`) and **Mezmur sheet**
  (`mezmur_attendance.dart`): status precedence **local pending edit >
  server-saved status > `absent`**; the "mark every student" gate is
  removed (Save/Submit always available); counter reads
  "N present · M absent"; "All Present" is the fast path, "All Absent"
  is the reset; autosave persists the whole roster.
* **QR scan = present.** Duplicate feedback ("ቀድሞ ተመዝግቧል!") only when the
  member is already present/late; an absent (default) or excused mark
  yields to the physical scan.
* **Web taker/teacher pages**: every row renders absent unless marked;
  Save always available; the shared `attendance-sheet.js` collector
  still reports unmarked rows truthfully and the server still enforces
  the complete roster on write (fail-closed backstop).
* **HR mobile**: the HR attendance screen, tab, read clients and the
  mobile review inbox are removed; taker/dept homes are retirement
  notices. The outbox HR drain (`saveHrSheet` → 410) stays so packets
  queued by old versions settle honestly instead of hanging.

## 4. Monitoring note

The historical 422 storm from paused partial drafts is *expected
behavior fixed*, not an outage: after this deploy, updated clients stop
producing `INCOMPLETE_SHEET` rejections entirely, and remaining HR
write attempts appear as one honest 410 `HR_ATTENDANCE_RETIRED` burst
per stale device (self-explanatory, decays as the fleet updates).
Consider deactivating `hr_attendance_taker` accounts at deploy time.

## 5. Where the contracts are pinned

`tests/security/test_attendance_rework_phase_a.py` (server split),
`..._phase_c.py` (mobile default-absent), `..._phase_d.py` (web parity +
HR report-only), `test_hr_attendance_domain.py` (retired contract),
`test_attendance_integrity.py` (fail-closed backstop). Deploy
verification steps: `docs/audits/DEPLOYMENT_RUNBOOK.md` STAGE 7B.
