# Pre-Production Audit — Class-Day Attendance / School Calendar / "Day Off" (2026-10-21)
**Scope:** every update heading to production, with special depth on the newest (class-day enforcement P0–P4, Year Calendar, timetable-linked calendar, entry points: `a5e8fee`, `b2901fe`, `6f901c9`). Every user journey, UI/UX, frontend → API → service → database, and the mobile app. Patched findings are included in the pushed commit.
**Method:** file-by-file code audit (the protocol), live journey walks against the seeded stack, production-shape two-state testing (pre/post `sql/078`), query-level performance measurement, full static+live suite, migration idempotency, XSS/injection/authz review, PHP 8.2 compatibility scan.

---

# Protocol §36 — Final Report

## 1. REQUEST
End-to-end, production-readiness and full security audit of the new updates before they reach the production server; every journey/feature/UI/API/DB layer; patch findings professionally; nothing may break production.

## 2. CURRENT PROBLEM (findings before this patch)
| # | Sev | Finding |
|---|---|---|
| F-P1 | **HIGH (perf)** | Year Calendar load issued **736 SQL queries** (one day-off + one holiday lookup per day of the year); the month grid 151. On shared hosting that is a multi-second page. |
| F-P2 | **MED (contract)** | The 45-day backdating window enforced itself even **before `sql/078`** — contradicting the documented "identical behaviour until the migration runs" deployment promise. |
| F-S1 | LOW (security) | The class-day verdict ran **before** the class-access check on the mobile save path and the lesson-sheet save — a teacher could probe another class's schedule verdicts (which weekday it meets, day-off detail). |
| F-S2 | LOW (security/DoS) | Calendar API accepted unbounded `from`/`to` ranges (a single authenticated request could drive tens of thousands of iterations + a huge response). |
| F-C1 | INFO | `deleteHoliday` carried a comment describing a guard that does not exist (dead/misleading documentation). |
| F-C2 | INFO | Invalid date strings passed into `classDayStatus` could reach `new DateTime()` in `dayType` and throw. |
| F-T1 | INFO (test-only) | The live test fixtures could leave a teacher attendance row behind after an unexpected pass, cascading `ATTENDANCE_EXISTS` failures into later runs. |

## 3. ROOT CAUSE
F-P1: per-day helper calls without a range plan. F-P2: date-window checks placed above the activation gate. F-S1: guard ordering followed the edit history rather than the access model. F-S2: range parameters unbounded. F-T1: fixture cleanup narrower than the fixture's possible writes.

## 4. SOLUTION
- **F-P1:** `primeRange()` loads all day-offs/holidays/makeups for the window in **4 queries**; per-day lookups answer from memory; working days and the active year are memoized per request. Measured: Year Calendar **736 → 8 queries** (3 ms), month grid **151 → 5 queries** (1 ms).
- **F-P2:** the 45-day window (and thus the whole class-day rule) activates **only when `sql/078` exists** — pre-migration behaviour is byte-identical to before (verified on a production-shape DB: `available:false`, old dates `OK`).
- **F-S1:** the day verdict now runs **after** `apiRequireClassAccess` (mobile) / `$assertScope` (lesson sheets) — probes of inaccessible classes get 403, never schedule facts.
- **F-S2:** `rangeGuard` caps API date ranges at 400 days (and rejects inverted ranges) with a clear 400 message.
- **F-C1/F-C2:** comment corrected; invalid dates refuse cleanly (`INVALID_DATE`).
- **F-T1:** fixture cleanup now also removes any teacher-probe rows; regression tests pin the guard order, the range cap and the batching.

## 5. FILES CHANGED (this audit patch)
- `admin/backend/services/SchoolCalendarService.php` — range batching + memos, backdate gating, `INVALID_DATE`, comment fix, write-time cache invalidation
- `api/v1/routes/attendance.php` — guard order (both saves)
- `admin/api_lesson_attendance.php` — guard order
- `admin/api_school_calendar.php` — `rangeGuard`
- `tests/security/test_school_calendar.py` — 5 new regression tests + hardened cleanup

## 6. LOGIC CHANGED
Save verdict order is now: valid date → future (pre-existing) → **[if sql/078]** 45-day window → day type (day-off → holiday → class-day/makeup). Pre-`sql/078` the class-day layer and the window are both inert. Read APIs reject >400-day ranges. No change to any existing attendance row or historical report.

## 7. DATABASE CHANGES
None in this patch. (Feature schema remains `sql/078_school_calendar.sql` only — re-verified idempotent ×2, additive, MariaDB-safe.)

## 8. API CHANGES
`admin/api_school_calendar.php`: `from`/`to` over 400 days apart → 400. `date_status` gains `INVALID_DATE` for malformed dates. Save paths return the day verdict **only to callers who have class access**.

## 9. SECURITY CHANGES
Closed the cross-class schedule-verdict leak (F-S1) and the unbounded-range amplifier (F-S2). Re-verified in this pass: CSRF on all writes; `edu_dept`-only calendar writes; audit trail intact (detail keys pass `detailsAreSafe`); prepared statements everywhere; XSS review of the calendar JS/widget/page (all dynamic text escaped, values inserted as text nodes not attributes); no secrets; Flutter text rendering escapes natively.

## 10. UI/UX CHANGES
None in this patch (performance improves perceived speed of the Year Calendar).

## 11. TESTING
- `test_school_calendar.py`: **20/20** (decision matrix + timetable-link + guard-order + range-cap + batching pins).
- Full suite: **2319 passed / 0 failed / 0 errors** (610 subtests).
- Production-shape two-state: pre-`078` → `available:false`, rule inert, old dates `OK`; post-`078` → full rule live.
- Query probes: 736→8 / 151→5 (before/after measured).
- `sql/078` re-applied ×2: clean. php -l clean on all touched files.
- Flutter unchanged in this patch (audited earlier today: 616 tests, 0 analyze errors).

## 12. REGRESSION CHECK
All journeys re-run green: attendance phases 0–6, monthly rollups, warnings, timetable builder/mobile, academic intelligence, auth/idempotency, mezmur, reports, school-calendar journeys (mark/reopen/attendance-exists/makeup/holiday/not-configured/edu-only writes). Existing data untouched.

## 13. REMAINING RISKS (accepted / noted)
- **Known open items carried over** (owner decisions pending): mobile mezmur legacy `attendance_taker` save; finance hard delete; `api_teachers.php` CSRF-fail HTTP 200; CI zero-skips gate red (needs owner decision).
- Offline app saves queued for a date later closed as Day Off will be refused at sync with `DAY_OFF` (visible in sync failures) — expected semantics.
- Day-off marking has a millisecond race against a concurrent teacher save (`ATTENDANCE_EXISTS` check then insert); industry-accepted (same class of race Arbor documents), impact negligible.
- Month-strip holiday counts use a mid-month probe (cosmetic miscount possible for ranges that skip a month's midpoint).
- Test clock edge cases around Addis/UTC midnight are handled via the server's `today`, but future tests must keep using it (`self.today` from the API).

## 14. ASSUMPTIONS
- Deployment stays: `git pull` (safe immediately) then `sql/078` in phpMyAdmin (activates the rule); production also runs 071–077 as already planned.
- Production PHP 8.2 / MariaDB 11.x: no PHP 8.3+/8.4-only syntax used (scanned); no extensions beyond mysqli; no cron.
- The owner wants the rule active as soon as `sql/078` lands (all five decisions), with no flag gating (matches the approved plan).
