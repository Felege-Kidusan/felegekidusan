# Attendance Alerting — Phase 6 Plan: Lesson‑level Education Attendance (Gate 6)

**Status: PLAN FOR APPROVAL — no code, schema or production change has been made.**
Date 2026‑10‑20. Follows the Universal AI Software Change Protocol and the Master Codebase
Understanding Protocol (every statement below is marked **FACT** = read in the repo / test DB,
**INFERENCE** = my conclusion, **UNKNOWN** = could not be determined from the repo).

Governing text: requirements **§3 Education** ("tracked by class and lesson/session … lesson‑level
detail remains mandatory … Education, HR and Information must be able to access lesson‑level history
including subject, lesson, teacher, date and outcome"), **§4** (formula), **§5** (draft never counts),
**§13** (nothing deleted, full audit); plan §Phase 6 (C‑1, T‑1 option b); Gate 6 = "separate plan
document before approval".

---

## 1. What exists today (research results)

| Item | Finding | Tag |
|---|---|---|
| Education attendance row | `attendance(member_id, class_id, academic_year_id, attendance_date, status present/absent/late/excused/holiday, check_in/out, notes, recorded_by)`, **UNIQUE `(member_id, class_id, attendance_date)`** — one row per member per class per day; no subject, lesson or teacher column. | FACT |
| Who the "teacher" is | Only via the packet: `grade_submissions(teacher_id, class_id, attendance_date, submission_type='attendance')`, UNIQUE `(class_id, attendance_date, submission_type)` — **one packet per class per day**; `attendance_submission_events` (Phase 3) hang off it. | FACT |
| Writers | `AttendanceRecordService::replaceSheet` (DELETE class/day then INSERT) and `mergeSheet` (upsert on the UNIQUE key). Callers: `admin/api_attendance.php save_attendance`, `admin/api_education.php`, `api/v1/routes/attendance.php` (POST save / submit; drafts merge, submits replace). QR `scan_member` also writes per class/day. | FACT |
| Readers of `attendance` | 20 PHP files (risk, rollup, summary, report card, Info analytics, HR reports, PDF, AI, school‑admin dashboard, mobile dashboard/members/classes, sync feed, …). | FACT |
| Offline / sync | MariaDB triggers `trg_attendance_sync_ai/au/…` (sql/057) write `sync_changes` with `scope_class_id/year/member`; `api/v1/routes/sync.php` feeds entity `attendance`. Flutter: `pending_attendance(class_id, date)` outbox, `attendance_delta.dart`, `attendance_sync_coordinator.dart`, `attendance_screen.dart` (1 097 lines) — **everything keyed by (class, date)**. | FACT |
| Streak rule | `AttendanceRiskService::dayOutcomes()` already folds **several rows of one day** into one day outcome (absent only if all rows absent) — written for T‑1 option (b). Rate = (present + excused + 0.5·late) ÷ **rows** (`sessions`). | FACT |
| Timetable | `timetable_periods` (bell schedule) + `timetable_entries(class_id, period_id, day_of_week, subject_id, teacher_id)` exist (sql/007) with `TimetableService` + `api_timetable.php`; comment: "thin store … does not own a public Education page". Empty in the test DB. | FACT |
| Timetable data in production | Not determinable from the repo. | UNKNOWN |
| Mezmur precedent | `mezmur_days(attendance_date UNIQUE, program_type, title)` and `mezmur_sessions(session_date, program_type, title, status)` — the department creates the day/session, attendance hangs off it. | FACT |
| Mobile scope | Owner decision G‑4 (2026‑10‑19): no new mobile scope until separately planned and approved. | FACT |

**Consequence (INFERENCE):** the blocking element is the UNIQUE key `(member_id, class_id, attendance_date)` plus the one‑packet‑per‑class‑day rule. Everything else (streak folding, rollups, bells) already tolerates several rows per day.

---

## 2. Two viable designs

### Option A — "sessions inside `attendance`" (the plan's original sketch)
* New `attendance_sessions(id, class_id, subject_id, teacher_id, session_date, slot, academic_year_id, created_by, …)`.
* `attendance.session_id` nullable; new UNIQUE `(member_id, session_id)`; the old key must change
  (several sessions per day collide) → replace it by UNIQUE `(member_id, class_id, attendance_date, session_key)`
  with a STORED generated column `session_key = COALESCE(session_id, 0)` (MariaDB 11.4 supports it; legacy rows keep `0`).
* Packets become per session (`grade_submissions.session_id`, new unique slot).
* **Pros:** one table, the streak rule works unchanged, "sessions" in the monthly rate are real lessons.
* **Cons:** touches the hot table and its UNIQUE key, both upsert writers, the sync triggers/feed, the packet
  uniqueness, QR scan, and **all 20 readers must decide what a row means** (lesson vs day); the Flutter
  outbox identity changes. Highest blast radius of any phase. Rollback = flag off but the key change stays.

### Option B — "lesson layer + derived day row" (recommended)
* New tables only: `attendance_sessions` (as above, + `status active/cancelled`, `source timetable|manual`)
  and `attendance_session_records(id, session_id, member_id, status, note, recorded_by, recorded_at)`
  UNIQUE `(session_id, member_id)`; packets per session in a new `attendance_session_submissions`
  (status draft/submitted/approved/rejected/reopened, teacher_id) reusing the Phase 3 event table shape.
* The existing `attendance` row stays the **class/day row** and is **derived server‑side** from the day's
  submitted session records (rule in §3). Legacy days (no sessions) keep working exactly as today.
* **Pros:** zero change to `attendance`, its key, the sync triggers, the 20 readers, warnings, rollups,
  report cards, QR, HR/Info views; fully additive and flag‑gated; rollback = flag off (tables retained).
  Lesson‑level history is a new read surface. Mobile can be added later as a separate screen (G‑4).
* **Cons:** two representations (lesson rows + derived day row) — derivation must be the single
  authority and re‑run on every session save/review; the day's `grade_submissions` packet becomes
  system‑owned once a day has session sheets (class/day sheet read‑only for that day).

**Recommendation (INFERENCE): Option B.** It satisfies §3 verbatim (every lesson outcome preserved,
subject/lesson/teacher/date/outcome queryable) without re‑shaping the table that every report and the
offline app depend on — which is exactly the risk C‑1 isolated Phase 6 for.

---

## 3. Option B — detailed design

### 3.1 Session model
* `attendance_sessions(id, class_id, academic_year_id, subject_id NULL, teacher_id NULL, session_date, slot SMALLINT, period_id NULL → timetable_periods, title VARCHAR(100) NULL, source ENUM('timetable','manual'), status ENUM('active','cancelled'), created_by, created_at, cancelled_by, cancelled_at, cancel_reason)`
  UNIQUE `(class_id, session_date, slot)`. Cancelled sessions stay (nothing deleted) and are excluded from "required".
* **Creation:** (a) a teacher assigned to class+subject opens "Lesson attendance" for a date and picks/creates the slot
  (auto‑suggested from `timetable_entries` for that weekday when populated); (b) Education may create/cancel
  sessions for any class. Takers (`attendance_taker`) keep the class/day sheet only (no subject assignment model for them).
* "**Required sessions for the day**" (§3) = active sessions of that class/date **whose sheet is submitted**.
  Draft/unsubmitted sessions never count (§5).

### 3.2 Lesson records & packets
* `attendance_session_records(session_id, member_id, status, note, recorded_by, recorded_at)` UNIQUE `(session_id, member_id)`; statuses as today.
* `attendance_session_submissions(session_id UNIQUE, teacher_id, status, submitted_at, reviewed_by, reviewed_at, review_note)` with the Phase 3 transition policy (`ReviewTransitionPolicy`) and events appended to `attendance_submission_events` (new `submission_kind='session'` column, additive).
* Roster per session = Education roster of the class on that date **minus members inactive in Education** (Phase 1 `exclusionSql`) — same as today.

### 3.3 Day derivation (single authority: `AttendanceDayDerivationService::deriveDay(class, date)`)
Runs after every session save / submit / review / cancel, inside the same transaction:

| Submitted session outcomes for the member that day | Derived day row |
|---|---|
| any **present** | present |
| none present, any **late** | late |
| none present/late, any **excused** | excused |
| **all** absent | absent |
| all sessions holiday | holiday |
| no submitted session yet | **no day row written** (draft never counts) |

The derived row is written through `AttendanceRecordService::mergeSheet` (so sync triggers, Phase 2
recalculation and rollups fire as today) with `notes = 'derived:n/m sessions'` and the day packet set to
`submitted` with `teacher_id` = last submitting teacher. **Precedence** is a business rule → Q6‑3.

### 3.4 What the monthly rate counts
Option B keeps **one session = one class/day row** in `AttendanceRiskService` / rollups (no change to
warnings or Phase 4 numbers). Counting lessons instead would change every rate → Q6‑4.

### 3.5 Read surfaces (§3 "lesson‑level history")
* Web: member drill‑down "Lesson attendance" (date EC, subject, lesson/slot, teacher, outcome, packet status) on the
  Education member view, HR per‑member view (kept per the HR decision), Information member view; class/day sheet shows a
  "derived from N lessons" badge with expand.
* API: `GET /api/v1/members/{id}/lesson-attendance` (role‑scoped, EC labels) for later mobile use.
* Reporting Centre: a `lesson_attendance` dataset **Information‑only** (owner rule 2026‑10‑19); HR frozen.
* Exports EC‑only (guard test).

### 3.6 Permissions (server‑side)
| Action | teacher (assigned class+subject) | edu_dept | attendance_taker | hr/info | admins |
|---|---|---|---|---|---|
| create session / take lesson sheet / submit | ✔ own | ✔ | ✖ | ✖ | ✖ |
| reopen / reject with reason | ✖ | ✔ | ✖ | ✖ | approve only (Phase 3 rule) |
| cancel session | ✖ (ask Education) | ✔ with reason | ✖ | ✖ | ✖ |
| read lesson history | own classes | ✔ | ✖ | ✔ read‑only | School Admin reports only; Super Admin none (§8) |

### 3.7 Delivery slices (each its own commit; owner runs SQL)
| Slice | Content | Size |
|---|---|---|
| **6‑A** | `sql/076_attendance_sessions.sql` (3 tables + event column), services (sessions, records, derivation), unit tests of derivation | M |
| **6‑B** | Web: teacher lesson sheet (class → subject → date → slot), Education session management, review hooks, day‑sheet read‑only badge | L |
| **6‑C** | Lesson history views (Edu / HR / Info member pages) + `/api/v1` read endpoint + Info‑only dataset + EC export | M |
| **6‑D** *(separate approval, per G‑4)* | Flutter lesson sheet + offline outbox identity `(class, date, slot)` + sync entity | L |
Flag: `FEATURE_ATTENDANCE_V2` + `FEATURE_LESSON_ATTENDANCE` (new, default off) so Option B can ship dark.

### 3.8 Tests (maps to §14.7)
Derivation table (all combinations incl. drafts/cancelled), no duplicate sessions, roster exclusion, permission matrix web+API, review transitions + events, derived row triggers Phase 2 recalculation and Phase 4 rollup, legacy class/day days untouched, EC‑only payloads/exports, no DELETE in new services, feature‑flag dark mode.

---

## 4. Questions for the owner (business decisions — not decided by me)

| # | Question | Options | My recommendation |
|---|---|---|---|
| **Q6‑1** | Storage model | A (sessions inside `attendance`, key change) / **B** (lesson layer + derived day row) | **B** |
| **Q6‑2** | Where sessions come from | timetable only / teacher‑created on the spot / Education‑created / **timetable suggestion + teacher‑created, Education may manage** | last |
| **Q6‑3** | Day outcome when lessons differ | precedence present › late › excused › absent (§3.3) / "late if any late" stricter / other | §3.3 table |
| **Q6‑4** | Monthly rate & low‑attendance denominator | per **class/day** (unchanged numbers) / per lesson (changes all rates and Phase 4 reports) | per class/day now; revisit after data exists |
| **Q6‑5** | Class/day sheet once a day has lesson sheets | read‑only derived / still editable (conflict) | read‑only derived |
| **Q6‑6** | Lesson history access | Edu + HR per‑member + Info, Reporting dataset Info‑only (§3.5) | as §3.5 |
| **Q6‑7** | Attendance takers | class/day only (no subject) / also lessons | class/day only |
| **Q6‑8** | Mobile | 6‑D later under separate approval (G‑4) / include now | later |
| **Q6‑9** | Timetable UI | build an Education timetable page in 6‑B (currently none) / suggestions only from existing data | suggestions only; timetable page is a separate feature |

---

## 5. Risks & mitigations
* **Double truth** (B): derivation is the only writer of day rows for days with sessions; a nightly‑free consistency check is exposed as `?action=verify_day` for Education (no cron on shared hosting).
* **Teacher confusion** (two sheets): the class/day sheet shows the badge and opens the lesson list; drafts cannot create day rows.
* **Production timetable unknown**: suggestions degrade to "no suggestions" when `timetable_entries` is empty.
* **Shared hosting**: plain mysqli, no triggers added, per‑request work bounded to one class/day.

## 6. Not in this phase
Timetable management UI (Q6‑9), lesson‑level make‑up logic (Phase 5 already keys on assessments), Mezmur (unchanged, independent), any `members.status` automation.

---
*ANALYSIS COMPLETE for the plan's purpose; UNKNOWN items are listed above and do not block the decision.*
