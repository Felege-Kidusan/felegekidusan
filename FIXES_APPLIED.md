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
