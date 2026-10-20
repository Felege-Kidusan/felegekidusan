# End-to-End Audit — User Journeys, Security, Production Failure Risk
**Date:** 2026-10-20 · **Auditor:** SSMS Dev · **Scope:** every update since the last full audit (`b8bb475` → `f4eb071`), with special focus on what can break the live production database/system.

Committed fixes: `f4eb071` (main, both remotes) · dev merge `7eb110c` (suraman21 only).

---

## What was audited (new since `b8bb475`)

| Commit | Change |
|---|---|
| `4c52494` | Guard test: main must always build the production app (beta settings dev-only) |
| `d86b56f` | Branching policy docs + `branch-guard.yml` CI workflow |
| `b64df5e` | Teacher timetable bottom-nav tab, Daily·Weekly·Monthly, single header |
| `b6506e9` | Header calendar button = EC date picker (Monthly grid ↔ Daily) |
| `a8723ce` | Monthly Attendance: visible Export Excel button + rollup `execute()` failure no longer swallowed |
| `f4eb071` | This audit's fixes (see below) |

Method (per protocol): 1-file-at-a-time audit of the diff; full static + live suite against a freshly provisioned local stack (schema + migrations + seed); **production-shape two-state testing** (a database with NO `sql/070–077`, then post-migration); CI workflow logs pulled from the GitHub API; Flutter test/analyze.

---

## Findings

### F1 — CRITICAL (CONFIRMED, fixed in `f4eb071`): mobile timetable 500s before `sql/077`
**FACT (reproduced):** on any database without `sql/077_timetable_builder.sql`, `GET /api/v1/timetable/week` and `/today` returned **HTTP 500**. This is exactly the state production will be in the moment the owner runs `git pull` and before pasting the SQL — every teacher opening the new Timetable tab (and the Home “today” card) would hit a failing feed. Root cause: `teacherSchedule()` queries `timetable_class_days` / `e.academic_year_id` / `e.room` with no readiness check (`ensureSchema()` is a deliberate no-op — schema is deployment-managed).
**Fix:** `TimetableService::schemaReady()` (information_schema probe, per-request cache) guards **every public entry point** — `classSetup`, `teacherSchedule`, and all eight writes. Reads degrade to the empty-but-contract-complete payload (`available: false`); writes refuse with a clean message naming `sql/077_timetable_builder.sql`. The mobile route forwards the additive `available` flag. Error handling confirmed non-leaking (no stack traces in responses).

### F2 — MEDIUM (CONFIRMED, fixed in `f4eb071`): guard must cover the whole query surface
The first guard revision passed a DB whose `timetable_entries.room` was missing (created by `sql/007`, not by 077) and still 500'd. `schemaReady` now fail-closed checks both tables (`timetable_class_days`, `timetable_makeup_classes`), both 077 columns (`entries.academic_year_id`, `periods.class_id`) **and** `entries.room`. Verified two-state on the production-shape DB: pre-migration → graceful; post-migration → full happy path (`available: true`, real queries).

### F3 — MEDIUM (CONFIRMED, fixed in `f4eb071`): `branch-guard.yml` red on its own runs
The new CI workflow ran `python3 -m pytest …` on a bare runner: **“No module named pytest”** — every `Branch guard` run on main failed (verified via GitHub API run logs for `b6506e9` and `a8723ce`). Fixed: install pytest before the test step. The guard's own leak checks (no `fkss.beta` / `arkeonethiopia.com/api` / `isBeta` on main, production `apiBaseUrl` + `applicationId` pinned, `HOW_TO_BUILD_BETA.md` absent) all pass locally on `main`.

### F4 — LOW (fixed in `f4eb071`): `sql/077` not self-sufficient for `room`
077 assumed `sql/007` shapes. Added an information_schema-guarded `ALTER TABLE timetable_entries ADD COLUMN room …` (idempotent — safe to re-run in phpMyAdmin). **Dev already has `room`** (the timetable works there), so re-running 077 on dev is optional; production runs 071–077 fresh anyway.

### F5 — INFO (closed, environment artifact): phase-3 mezmur assertion `9 vs 8`
`test_attendance_phase3.py::test_03_mezmur_reopen_rule` pins the acting user's DB id (`audit_mez`=9, `audit_taker`=8 — the historical test world's creation order). My bootstrap seed created them in reverse order. Fixed the local seed to the canonical IDs; the test passes (and the earlier full-suite “2 failed” pair re-ran green once `audit_taker` existed). **Not a code bug.** The hardcoded IDs remain a latent test fragility worth un-hardcoding someday.

### F6 — MEDIUM (documented, needs owner decision — NOT changed): CI “Fail if any test skipped” gate is permanently red
`Backend checks` fails every main push at its deliberate zero-skips gate: 121 tests self-skip in CI (reasons: “live server + seeded DB required”, “mobile login rate-limited”, …). They run green locally with the provisioned stack (proven in this audit), but the CI job never starts `php -S` nor runs `tests/e2e/provision_env.sh`, and the tests' `db()` helper is hard-wired to a **local** `sudo mariadb ssms_test` socket — the CI MariaDB is a service container on 127.0.0.1. So CI cannot ever satisfy the gate as-is. A red-always gate hides real failures (its own comment: “a skipped test is an unverified claim”). Options: (a) provision the live stack inside the CI job and point `db()` at the service DB (proper fix, moderate test-infra work); (b) split the suite into “static in CI” + “live in sandbox” and make the gate apply to the static set. **Awaiting owner choice** — this is CI design, not a production runtime risk.

### F7 — INFO (verified clean): production-shape admin surface
Against the pre-migration DB the admin surfaces all behaved: `attendance_monthly.php` + its API (months/summary/list/groups) 200 with graceful emptiness; `api_attendance_risk` 200; `api_attendance_sessions` 200; `api_academic_intelligence` 200; `api_timetable` reads 200, writes refused cleanly; `reports_v2` 200. No fatals, no SQL errors surfaced. (`refresh`/`export` are GET-only by design — POST → 405; `api_department_status` 400 = missing required params in the probe, not a defect.)

### Security review of the new commits (all verified)
- **Authz:** mobile timetable route: `apiRequireAuth()` + education/teacher role gate; `teacher_id` override is **management-roles-only** — a teacher can only ever read their own schedule (re-verified line-level). Rate limit 120/min.
- **Leak guard:** `main` holds production values only (`com.arkeonethiopia.fkss`, `felegekidusan.com`); beta markers absent (guard test green); `dev` still carries the beta commit (`fkss.beta` confirmed after merge).
- **CI:** `branch-guard.yml` also rejects PRs whose head is `dev`; workflow files contain no secrets; `permissions: contents: read`.
- **Info-disclosure:** 500-handler returns a generic message (no stack traces) — confirmed in the failing case.
- **Migration safety:** `sql/070–077` re-applied ×3 → fully idempotent; no MariaDB-unsafe DDL (no `IF NOT EXISTS` column tricks outside the PREPARE-guard pattern, no procedures/events/triggers).

---

## User journeys walked (live)

1. **Teacher app:** login → Home (today card) → Attendance → Grades → **Timetable tab** → Daily default → Weekly → Monthly → calendar button → Monthly EC grid → tap month → Daily of that month → back. 616 Flutter tests green incl. the tab/header/calendar pins.
2. **Education monthly attendance:** month (EC) select → summary cards → band filter → **“Below threshold only”** (empty until 3 sessions — explained to owner) → member list → Export Excel (now visible after `a8723ce`) → Refresh forces rollup recompute.
3. **Timetable builder (admin):** classes → class setup (working days, periods, entries, makeups) → save/clear with conflict rules → audit rows — covered by `test_timetable_builder` live suite (green).
4. **Degradation journeys:** flag off; `sql/071–077` missing; `room` column missing; rate-limited login; expired session — all verified to fail soft and explainably.

---

# Protocol §36 — Final Report

## 1. REQUEST
End-to-end test of user workflow and user journey, security, and anything that would make the live production database or system fail — detailed audit with special attention to the newest updates.

## 2. CURRENT PROBLEM
One confirmed production-breaking defect (F1: mobile timetable 500 before `sql/077`), one residual fatal class (F2), one broken new CI workflow (F3), one migration self-sufficiency gap (F4), one environment artifact (F5), and one pre-existing permanently-red CI gate needing a decision (F6).

## 3. ROOT CAUSE
F1/F2: deployment-managed schema with no readiness probe — code assumed `sql/007`+`sql/077` shapes on tables whose migration the owner runs manually *after* pulling code. F3: workflow authored without a Python/pytest setup step. F4: 077 only guaranteed its own artifacts, not the 007 columns its queries use.

## 4. SOLUTION
`TimetableService::schemaReady()` fail-closed readiness probe guarding all entry points (reads → empty contract-complete payload with `available:false`; writes → clean refusal naming the SQL file); mobile route forwards `available`; 077 self-heals `room`; `branch-guard.yml` installs pytest; 3 static regression tests pin all of it.

## 5. FILES CHANGED
- `admin/backend/services/TimetableService.php` — schemaReady + guards on classSetup/teacherSchedule/8 writes
- `api/v1/routes/timetable.php` — forwards additive `available`
- `sql/077_timetable_builder.sql` — guarded `room` ALTER (idempotent)
- `.github/workflows/branch-guard.yml` — pip install pytest
- `tests/security/test_timetable_mobile.py` — `SchemaGuardStatic` (3 tests)

## 6. LOGIC CHANGED
Before `sql/077`: timetable APIs 500 → now empty data / clean refusal. After: behaviour identical (`available:true` added, all contract keys unchanged). Admin builder writes pre-migration return the same error JSON shape the UI already handles. No change to any attendance, HR, auth, or Family Link path.

## 7. DATABASE CHANGES
`sql/077` + one guarded ALTER (`timetable_entries.room`, self-healing, re-runnable). No other schema change. Owner re-running 077 is optional on dev (already has `room`); required never — production will run 071–077 for the first time and gets it inline.

## 8. API CHANGES
`GET /api/v1/timetable/week|today` response gains additive `"available"` boolean. All previously documented keys unchanged (verified against the T-3 contract test). New pre-migration behaviour: 200 with empty data instead of 500.

## 9. SECURITY CHANGES
No auth model change. Re-verified: role gates, teacher self-scope (no `teacher_id` spoofing), rate limiting, generic 500 messages, no secrets in CI, beta-leak guards on main.

## 10. UI/UX CHANGES
None. Pre-migration the app now shows its designed empty timetable state instead of a load error; admin writes explain exactly which SQL to run.

## 11. TESTING
- Full suite (provisioned stack, flag on): **2309 passed, 0 failed** (607 subtests); live batches (phase0–6, pass2, timetable builder+mobile) green with rate-limits cleared; flutter test **616 passed**; `flutter analyze lib` 0 errors.
- Production-shape two-state smoke (admin + mobile surfaces): pre-migration **0 fatals** (was 2) → re-run 077 → happy path 200s with `available:true`.
- `sql/070–077` idempotency: re-applied 3×, no errors.
- CI: branch-guard fix verified by the pinned static test; run logs of `b6506e9`/`a8723ce` used as evidence for F3/F6.

## 12. REGRESSION CHECK
Teacher timetable journeys, monthly attendance, analytics hub, reports V2, attendance risk/sessions/intelligence APIs, mobile auth/config, migration scripts, guard tests — all re-run and green. `pubspec.lock` untouched; production `applicationId`/`apiBaseUrl` pinned on main (verified post-push).

## 13. REMAINING RISKS
- **F6 (needs owner decision):** CI zero-skips gate stays red until we provision the live stack in CI or split the gate (options in F6).
- Production remains pre-`071–077` until the owner runs the SQL — now safe (F1/F2 fixed), but the new attendance/timetable features are inert until then (by design).
- Known open items carried over (T-3 audit, not in this scope): mobile mezmur legacy `attendance_taker` save, finance hard delete, `api_teachers.php` CSRF-fail returns HTTP 200.
- Test fragility: phase-3 pins raw user ids (F5) — benign today, brittle if seeding order changes.

## 14. ASSUMPTIONS
- Production `timetable_entries`/`timetable_periods` come from `sql/007` (high confidence: deployed `my_schedule` feature reads them; schemaReady fail-closed covers the alternative).
- Owner runs `sql/071–077` in phpMyAdmin as previously planned (agent never touches production DB).
- `dev` already has `sql/071–077` (owner's working Monthly Attendance screenshots prove 074 is live there).
