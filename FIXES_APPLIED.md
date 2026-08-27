# SSMS Phase-1 Fix Batch — Applied & Verified

Branch: `arena/deep-fixes-phase1` (6 commits on top of `main` @ 0808332)
Method: deep file-by-line analysis first (see `/home/user/ANALYSIS/01–06`), fixes
implemented against the verified findings register (H1–H5, M1–M8, L…).
Verification: **171/171 tests pass** in `tests/security/` (incl. 43 new
regression tests and the 100k-row DB scaling fixture).

---

## Fix 1 — Single attendance-summary writer (H1 + H2)  `6a53bdc`

**Problem.** Two independent writers maintained `attendance_summary`:
`workflow.php` keyed rows on *today's* month with `present/total` and spammed
low-attendance alerts on every save; `api_attendance.php` keyed rows on the
*recorded* date's month with `(present + 0.5·late)/total` and class-scoped
aggregation — while the table's UNIQUE key `(member_id, academic_year_id,
month, year)` has **no class column**. Whichever endpoint saved last silently
overwrote the other's numbers; backfills corrupted the current month.

**Fix — derived-cache pattern (industry standard for aggregates):**

- New `App\Services\AttendanceSummaryService` — the ONLY writer:
  - recomputes the affected member+month **from the `attendance` source rows**
    (idempotent, self-healing, replay-safe — no drifting deltas);
  - one formula everywhere: `(present + 0.5·late) / total`;
  - month/year derived from the **recorded date**, never "today";
  - member-level aggregation across classes (matches the unique key, keeps a
    transferred student's month intact);
  - NULL-year rows replaced deterministically (NULL can't match UNIQUE);
  - low-attendance alert (< 70 %) deduped per member per 7 days.
- `workflow.php` + `api_attendance.php` collapsed into thin delegates —
  signatures and JSON contracts unchanged (no UI touched).
- `api_education.php` passes the record date so backfills are correct.

## Fix 2 — Atomic promote + enrollment transfer (H3)  `a31974f`

**Problem.** `promote` and `transfer_student` ran 4 autocommit steps each; a
mid-sequence failure could leave a member in two classes or in none.

**Fix.**
- `promote`: full `begin_transaction/commit/rollback`; pre-flight validation
  with fixed messages (active source enrollment must exist, target class must
  exist, self-promotion rejected, active year required).
- `transfer_student`: close-old + create-new wrapped in one transaction;
  target class validated first.
- `EnrollmentService::transferByEnrollment` participates in an outer
  transaction (`mysqli::in_transaction`) or owns its own — same atomicity for
  the Excel/HR assignment path.
- All failure responses now state **"No changes were made."**

## Fix 3 — Impersonation-aware AdminSessionGuard (H4 + L4)  `2f22dcd`

**Problem.** Every 300 s the guard re-synced `admin_role` from the DB,
silently reverting role-impersonation sessions to the real role (broken UX,
security-relevant confusion). Separately, `api_impersonate.php` accepted
`$_REQUEST`, so cross-site GETs could switch an admin's role.

**Fix.**
- Revalidation now detects impersonation (`original_admin_role`) and
  reconciles the **base account** (username, full name, password version,
  privilege) **without reverting the assumed role**.
- Tamper defenses: impersonation may only originate from
  `super_admin`/`school_admin`, the assumed role must stay in the known role
  set (else invalidated), and privilege revocation ends ongoing
  impersonations.
- `api_impersonate.php`: `switch`/`restore` are POST-only with CSRF;
  `status` remains a read-only GET.

## Fix 4 — member.php enumeration + PII hardening (H5)  `910fdd6`

**Problem.** Member codes are sequential (A1…An): anyone could sweep
`/member.php?code=` and harvest photos + **full home addresses of minors**.

**Fix.**
- Per-IP rate limiting (30 lookups / 10 min) via `SecurityRateLimiter`
  **before** any members-table access — atomic DB bucket scales across
  instances, locked-file fallback included.
- Throttled requests get a neutral "temporarily unavailable" page —
  identical for existing and non-existing codes (no existence oracle).
- Disclosure trimmed to **city + sub-city only**; woreda, house numbers and
  free-text street addresses are no longer public (they remain in the
  authenticated dashboards).
- `no-store` / `no-cache` / `nosniff` / `no-referrer` headers; code input
  bounded to 32 chars.

## Fix 5 — Quick wins (M1–M4, F8, L1)  `6c1d341`

| ID | Problem | Fix |
|----|---------|-----|
| M1 | Registrations without an age group were silently coded into ህጻናት (A), corrupting ministry categories | Codes are **never guessed**: unknown age group ⇒ `member_code = NULL` ("Pending"); the Identity hub / migration tool issues the right code later. Applies to registration, Excel import and the mobile API. Audit log records `assigned`/`pending`. |
| M2 | Code allocated before the transaction; GET_LOCK released before INSERT ⇒ two concurrent registrations could draw the same number; REGEXP MAX scan is O(n) per registration | Allocation moved **inside** the transaction with the advisory lock held until commit/rollback (`allocateStudentHeld`/`releaseCodeLock`). New `member_code_sequences` table (`sql/018`) gives **O(1) atomic allocation**; legacy scan stays as automatic fallback until the migration runs. |
| M3 | `academic_years.year_name` had no UNIQUE — the "already exists" branch could never fire | App-level duplicate pre-check on save + **conditional** UNIQUE index in `sql/018` (skipped when legacy duplicates exist; the 1062 handler becomes reachable after cleanup). |
| M4 | `user-delete.php` set `grade_submissions.submitted_by = NULL` — that column does not exist (silent no-op inside the delete transaction) | Now detaches `reviewed_by` (the real nullable column). `teacher_id` is NOT NULL and intentionally kept so academic records are never destroyed by a user deletion (documented). |
| F8 | school_admin's activate/deactivate user button sent `id` + `activate/deactivate` while the endpoint demanded `user_id` + `toggle_status` ⇒ dead button | `user-toggle.php` accepts both conventions; school_admin.js now also sends the CSRF token. |
| L1 | `user-toggle.php` had no CSRF | Mandatory CSRF + self-lockout guard + idempotent responses. |

## Migration — `sql/018_code_sequences_and_year_uniqueness.sql`

Idempotent / re-runnable:
1. `member_code_sequences (letter PK, last_n)` + seeded from current MAX per letter;
2. conditional `UNIQUE(year_name)` on `academic_years` (procedure checks for
   duplicates first; keeps deployments with legacy duplicates running).

## Compliance with the mandatory rules

- **Frontend/backend separation:** zero UI redesigns — all fixes live in
  endpoints/services; the only JS change is 2 lines restoring a dead button
  (it now sends the CSRF token like every other call).
- **Scale (100k+ users):** derived-cache recomputes touch only the member's
  own indexed rows; code allocation became O(1); rate limiter is DB-backed
  and multi-instance safe; all SQL prepared + index-covered.
- **Security:** CSRF everywhere on state-changing calls, no existence
  oracles, PII minimized, no diagnostics in client responses (passes the
  repo's own disclosure tests), tamper-aware impersonation, atomicity
  guarantees data integrity.
- **Current-status compatible:** every JSON contract preserved; fallbacks
  for un-migrated deployments (legacy code scan, NULL-year handling,
  optional columns); re-runnable migration.
- **Maintainable/extensible:** each fix is one focused service or block with
  a documented contract (`AttendanceSummaryService` is the template for any
  future derived cache); 43 regression tests lock the behavior.

## Fix 6 — ID-card subsystem (production "Something went wrong")  `6d66582`

**Root cause (verified by reproduction):** `id_card_template_layout.php`
reads branding constants (`RELIGIOUS_INVOCATION`, `PARISH_NAME_AM`,
`ID_CARD_TITLE_AM/EN`, …) **unguarded**. On PHP 8, a deployment whose
`school_config.php` drifted behind the codebase throws
`Uncaught Error: Undefined constant` — exactly the generic error page
(Ref #555). Reproduced locally: rendering the template with a minimal
config fatals at line 38; with the fix it renders.

**Fixes:**
- **`branding_defaults.php`** (loaded in `config.php` right after
  `school_config.php`): guarded fallbacks for the *entire* branding
  constant set. Complete configs are byte-for-byte unchanged; drifted
  configs now degrade gracefully instead of throwing. Generic values
  only. This protects every consumer (login, dashboards, ID cards,
  printable reports, member.php) — the same class of fatal can no longer
  occur anywhere in the app.
- **`admin/id_cards/libs/qr_loader.php`**: the repo shipped the
  single-file phpqrcode build but the code required the missing
  multi-file entry point (`qrlib.php`) → QR generation dead, "renew"
  503'd. The loader defines `QRcode` from either build; QR generation,
  on-the-fly self-healing and renewal all work again (test asserts a
  real PNG is produced).
- **Hardening:** absolute `__DIR__` includes; URL-encoded QR links;
  explicit `isLoggedIn()` gates on `view_id_card.php`,
  `generate_id_card.php` (auth *before* CSRF — anonymous sessions also
  hold CSRF tokens) and `print_member.php`, as defense-in-depth on top
  of the central `access_control.php` role map (verified intact).
  `qr_diagnostic.php` remains unreachable over HTTP (404 via
  access_control).

## Verification

```bash
php -l <every changed file>                      # syntax: all clean
python3 -m unittest tests.security.*             # 171 tests — OK
```

Run `sql/018` during the next deployment to activate the sequence table and
the UNIQUE year-name index (safe to run repeatedly; automatic fallbacks
cover the window before it runs).

## Fix 7 — Autofill hardening (form inputs)  `b74cba6`

21 admin/registration form inputs were hardened against browser autofill
leaking or overwriting sensitive fields: legacy `autocomplete="nope"`
hacks (ignored by Chrome) replaced with a single proper
`autocomplete="off"` + `type="search"` strategy, validated with a
full-tag DOTALL regex so multi-line `<input>` tags could not hide
duplicates. 4 new tests; covered by the suite below.

## Fix 8 — Identity & Codes management system  `(this commit)`

Deep line-by-line analysis (ANALYSIS/07) found the identity-code
infrastructure (sql/017 schema, `IdentityCodeService`, category model,
hub APIs, CLI + web migration runners) already existed — but **no UI
consumed it**, and several latent bugs would break it on first use.
This batch delivers the management UI and fixes every defect:

### A. Bugs fixed

- **QR fatal everywhere the identity system regenerates codes** —
  `api_identity.php`, `api_identity_migration.php` and the CLI tool all
  required the missing `id_cards/libs/phpqrcode/qrlib.php` (only the
  single-file build ships in the repo). All three now load through the
  canonical `admin/id_cards/libs/qr_loader.php` with a `class_exists`
  guard. The web runner additionally never regenerated QR at all
  (`class_exists` tested before anything was loaded) — fixed.
- **Category guessing removed** — `assign_positions` fell back to
  `MemberCategory::LETTER_A` when a member had no resolvable age group.
  It now leaves the code pending (NULL). Categories are never inferred.
- **Race on category changes** — the new advanced editor uses
  `allocateStudentHeld()`/`releaseCodeLock()` so the advisory lock is
  held until commit (same guarantee as Fix 5 / M2).
- **Sequence drift after renumbering** — both runners now resync
  `member_code_sequences` (sql/018) from the renumbered maxima after
  execute, so post-migration allocations continue correctly
  (`GREATEST(last_n, …)` — never moves backwards).
- **XSS** — `view_id_card.php` printed `member_code` unescaped into the
  page `<title>`; now `htmlspecialchars`.
- **sql/019 robustness** — every statement is guarded: scrubbing of the
  `classes` table only runs when the table exists, the ENUM shrink only
  when the column is still an ENUM containing `under6` and no row uses
  it (databases already upgraded to VARCHAR by the 012 baseline are
  untouched). Fully re-runnable.

### B. Super Admin UI — new "Identity & Codes" section

`admin/dashboards/sections/identity_codes_section.php`, wired into
`super-admin.php` (allowed-sections list, Management nav group, section
include). Pure presentation — zero SQL in the file; every change flows
through the session+CSRF-gated hub APIs. Five tabs:

1. **Departments** — create/edit/deactivate; codes are 1–4 A–Z letters
   and become staff-code prefixes (ED, ID, HRD, …).
2. **Positions** — per-department letter codes (Teacher = T, …); `H`
   marks the department head; `N` is reserved for ordinary members.
3. **Membership types** — the three tiers keep their stable ENUM keys
   (reports/mobile sync depend on them) but their Amharic/English
   labels are now editable data (`member_type_settings`, sql/019).
4. **Member editor** — search by name/code, then change category,
   membership type and position assignments with a live code preview
   (N marker rendered smaller, exactly as on cards). Every change is
   audited and re-codes the member (`legacy_member_code` preserved,
   QR refreshed).
5. **Renumbering** — dry-run preview + `RENUMBER`-confirmed execute of
   the alphabetical renumber (A1, A2…/B1…/C1… by name within category);
   staff codes untouched, sequences resynced, QR regenerated.

New hub-API actions: `list_member_types`, `save_member_type`,
`identity_search`, `get_member_identity`, `update_member_identity`.

### C. under6 (አጸደ ህጻናት) removed completely

- `MemberCategory` no longer normalizes it (unknown → null → pending).
- sql/019 scrubs stragglers into ህጻናት (7_13) and drops the ENUM value
  on `members`/`classes`.
- Test roster seed migrated to 7_13; `database_schema.sql` baseline now
  ships the three-letter ENUM plus all identity tables for fresh
  installs.
- Auto age/section assignment was verified already disabled by design
  (`hr_register_member.php`) — section assignment stays manual.

### D. Presentation layer

- **`MemberCodeFormat`** renders staff codes with the ordinary `N`
  marker typeset smaller (`font-size:0.72em`) and fully escaped.
  Adopted by: ID-card template (both card sides), public verification
  page (`member.php` badge), `print_member.php`.
- **`MemberTypeService`** labels adopted by `print_member.php` and the
  bounded CSV report (`MemberReportRenderer` + `export_pdf.php`,
  optional param — old call sites unchanged).

## Verification (Fix 8)

```bash
php -l <every changed file>                          # syntax: all clean
python3 -m pytest tests/security/ -q                 # 207 tests — OK
```

Deployment: `git pull`, then run `sql/019` in phpMyAdmin (idempotent).
The alphabetical renumber is then available from the new Identity &
Codes section (dry run first) or `php admin/tools/migrate_identity_codes.php`.

## Fix 9 — Identity hub API strict-mode hardening  `(this commit)`

Production runs PHP 8.2, where mysqli defaults to **strict exception
reporting** (`config.php` never calls `mysqli_report`). Three identity
code paths still used the old false-return idiom and therefore failed
with the generic "Unable to complete the identity request" error:

1. `list_positions` — its primary query used `ORDER BY … NULLS FIRST`,
   which MySQL rejects; the MariaDB fallback only ran when the query
   returned `false`, which strict mode never does (it throws). Replaced
   with a single portable `COALESCE(d.sort_order, 9999)` query.
2. `save_department` / `save_position` — a duplicate key (1062) threw a
   `mysqli_sql_exception` before the `errno === 1062` branch could run.
   Both actions now catch the exception *and* keep the errno path, so
   they behave identically under strict and legacy reporting.
3. `MemberTypeService::saveLabel` — `prepare()` throws when sql/019 has
   not been run yet; converted to the same safe "run sql/019" operator
   message instead of the generic error.

Errors at the API boundary always log internally and return
user-actionable messages that never leak schema/SQL internals.
3 new tests (211 total, all passing).

## Fix 10 — Identity code format v2 + position-driven registration  `(this commit)`

Leadership's corrected spec (ANALYSIS/08), implemented in three shippable
phases after plan approval:

### Code format v2 (sequential numbers retired)
- Every code is now `{PREFIX}-{random unique 5-digit tail}`:
  students `A-76392`, staff `EDHT-83719`, free positions first:
  `DEDHT-98798` (Director+Ed-head+Teacher), `DT-98798`.
- `IdentityCodeService` rewritten: single parser (`parse()`,
  `isStudentCode()`, `isStaffCode()`), pure `composePrefix()`
  (free → dept segment H/N → dept positions), random-tail allocation over
  the UNIQUE key with a bounded indexed probe. Sequence tables, GET_LOCK
  and sequential scans retired (schema kept for rollback safety).
- Shared migration engine `IdentityMigrationService::renumberAll()` used
  by BOTH the CLI tool and the web runner: re-issues codes for everyone,
  preserves old codes in `legacy_member_code`, regenerates QR, skips
  already-correct prefixes (idempotent), keyset-paginated.

### Free positions + manageable mapping
- `sql/020` (idempotent): `staff_positions.department_id` nullable;
  `legacy_flag` column maps a position onto a legacy flag column.
- Reserved letters: `N` always; `A/B/C` for department-less positions.
- Registration (`hr-dept.php`) and member edit (`info_manage_member.php`)
  now render a position picker fed by `PositionSyncService::catalogue()`
  — the hard-coded 12-checkbox role block is gone; picker available to
  regular AND special_regular.
- `PositionSyncService` is the single writer: replaces assignments,
  re-codes, derives legacy flags, keeps the special_regular sync rule.
- Edu-dept teacher flows (`member_sync.php`, `workflow.php`) converge:
  flag writes mirror onto the mapped Teacher position + re-code.
- Super Admin: Member Editor removed (no member search there); Identity
  section UX rebuilt — single-flight buttons (spinner + aria-busy, the
  double-click duplicate bug cannot happen), toasts, inline modal for
  destructive/execute confirmations, forms reset on success.

### Verification
php -l on every touched file; 207 tests passing (contract tests for the
v2 parser/composer, free-position guards, convergence hooks, single-flight
UI, form pickers, shared migration engine).

Deployment: `git pull`; run `sql/020` in phpMyAdmin (idempotent); then
Identity & Codes → Renumbering → dry run → execute.

## Fix 11 — Pre-migration grace for sql/020 + section loading UX  `(this commit)`

Production pulled the v2 code before running `sql/020`, so every query
touching `staff_positions.legacy_flag` threw MySQL 1054 under PHP 8.2's
strict mysqli reporting → "Unable to complete the identity request" and
a stuck "Loading…" table. The code now degrades gracefully on
pre-migration deployments (progressive-rollout / schema feature
detection, the pattern used for safe staged rollouts at scale):

- `PositionSyncService::hasLegacyFlag()` — one cached
  information_schema probe per process; `deriveFlags()`,
  `syncPositionFromFlag()`, `list_positions` (NULL placeholder column)
  and `save_position` (column-aware SQL) all branch on it. Pre-020
  deployments keep full functionality; the flag mapping simply activates
  once the migration runs. Saving a flag pre-020 reports
  "(legacy flag ignored until sql/020 is applied)".
- Section loading UX/perf: lists now render their own error rows
  instead of spinning forever, department/position fetches run in
  parallel, and the visible pane auto-loads on entry (deep links).

2 new regression tests; 209 total passing.

## Fix 12 — Tab navigation blanked the Identity section  `(this commit)`

The Fix-11 refactor of the tab handler deactivated every pane but never
re-activated the clicked one, so any in-section navigation left the
section body blank ("it disappears when I navigate"). The handler now
re-activates the target pane, and a regression test pins the exact
activation line so a future refactor cannot drop it silently.
211 tests passing.
