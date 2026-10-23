# S0.5 + S1a — Flutter CI Baseline & Unknown-Behaviour Verification

**Phase status:** Complete.
**Gate decision:** `READY_FOR_S1` (qualified — see §24 and §20).
**Date:** 2026-10-05
**Predecessor:** `docs/MOBILE_OFFLINE_SYNC_S0_AUDIT.md`, commit `3124a5e`.
**Rule observed:** no synchronization machinery was rebuilt, replaced, simplified or refactored. No application source file was modified in this phase.

---

## 1. Purpose

S0 established that the mobile app already contains substantial, well-built synchronization infrastructure, and that the real deficiency is observability rather than correctness. S0 could not, however, execute anything: no Dart/Flutter toolchain was available, so every client-side conclusion was a source-reading result. Four behaviours were explicitly left `NOT VERIFIED`.

This phase does two things and nothing else:

1. **S0.5** — make the existing mobile test suite continuously executable in CI, and measure the true baseline.
2. **S1a** — verify the unresolved runtime behaviours, so that S1 is designed against facts rather than assumptions.

The governing principle: *never redesign a subsystem because we assumed it was missing when the code already contains it.*

---

## 2. S0 baseline carried into this phase

S0 recorded these as unresolved or unverified:

| ID | S0 status | Why it mattered |
|---|---|---|
| F-05 | 35 test files never executed by CI | The whole state machine was unverified |
| F-13 | `in_flight` recovery sweep **NOT VERIFIED** | If absent, every force-stop during sync strands an operation forever |
| F-14 | Logout discard default/confirmation **NOT VERIFIED** | If discard were default, one tap = irreversible data loss |
| F-11 | Pre-v34 rows with NULL `owner_user_id` **NOT VERIFIED** | Potential invisible, unclaimable work, or an isolation hole |
| F-12 | Sync state surfacing on originating screen **NOT VERIFIED** | UX honesty |
| F-15 | Hymn ops exempt from account isolation | Possible cross-account attribution/authorisation issue |
| F-07 | Idempotency completion outside the business transaction | Possible duplicate writes |

---

## 3. Flutter CI configuration

### 3.1 Toolchain decision (evidence-based)

A direct conflict exists in the repository, and resolving it correctly was a prerequisite:

| Source | Declares |
|---|---|
| `pubspec.yaml` | `sdk: '>=3.3.0 <4.0.0'`, `flutter: '>=3.27.0'` |
| `pubspec.lock` (`sdks:` block) | `dart: ">=3.12.0 <4.0.0"`, `flutter: ">=3.44.0"` |

`pubspec.yaml` states a *floor*; `pubspec.lock` records what the dependency graph was actually resolved against. **The lock is the binding constraint** — installing Flutter 3.27 could not resolve these packages at all.

Per the public release record, Flutter 3.44.0 (May 2026) ships Dart 3.12.0, and **Flutter 3.44.9** (2026-08-06) is the newest patch of that series, shipping Dart 3.12.2 [8](https://flutterreleases.com/flutter-versions/). Pinning 3.44.9 therefore:

- satisfies the lock's `>=3.44.0` / `>=3.12.0`;
- satisfies `pubspec.yaml`'s floor;
- stays **within the series the lock was built from** — a patch, not a minor upgrade;
- is one stable release behind current (3.47.1), which is the conventional enterprise position [5](https://docs.shorebird.dev/getting-started/flutter-version/).

**No Flutter or Dart upgrade was performed. No package was upgraded. No lint rule was changed. No application source was modified.**

### 3.2 The job as committed

```yaml
  flutter-tests:
    name: Flutter unit tests
    runs-on: ubuntu-latest
    timeout-minutes: 20
    defaults:
      run:
        working-directory: Mobile/wbws_flutter_app
    steps:
      - uses: actions/checkout@v4
      - uses: subosito/flutter-action@v2
        with:
          flutter-version: 3.44.9
          channel: stable
          cache: true
      - name: Toolchain versions
        run: flutter --version
      - name: Resolve dependencies
        run: flutter pub get
      - name: Run the existing test suite
        run: flutter test --reporter expanded
```

Deliberately **absent**: emulator, Android SDK, secrets, network fixtures, database, build/codegen steps, deployment, and any unrelated job. Every test under `test/` is pure Dart or a widget test, so none of those are required (verified in §5.3).

### 3.3 Requirements analysis (§1.5 of the brief)

| Requirement | Needed? | Evidence |
|---|---|---|
| Flutter SDK | **Yes** | all 35 files import `package:flutter_test` |
| Android SDK | No | no `integration_test`, no platform channels under test |
| Emulator / device | No | one widget test (`widget_test.dart`) runs headless |
| Network | No | no HTTP client is exercised |
| Database | No | the only two files mentioning `sqflite` do so **in comments** |
| Secrets | No | none referenced |
| Backend services | No | none referenced |

### 3.4 Did I install Flutter locally?

**No.** The brief permits installation only if the CI implementation requires it; it did not. The developer machine and the CI runner are separate environments, and CI is the execution authority here. All Dart execution in this phase happened **on the GitHub Actions runner**, and its logs are the evidence.

---

## 4. Test baseline

### 4.1 The honest two-run sequence

| Run | Commit | Flutter job | What it proved |
|---|---|---|---|
| **#48** | `b9809c7` | **failure** (exit 65) | Toolchain installed correctly; `--enforce-lockfile` cannot be satisfied → **new finding F-19** |
| **#49** | `4a38f33` | **success** | Full baseline obtained |

Run #48 is reported rather than hidden: it is the evidence for F-19.

### 4.2 Run #48 failure, verbatim

```
Would change 12 dependencies.
Unable to satisfy `pubspec.yaml` using `pubspec.lock`.
To update `pubspec.lock` run `flutter pub get` without `--enforce-lockfile`.
Failed to update packages.
##[error]Process completed with exit code 65.
```

Classification: **TOOLCHAIN_FAILURE caused by a REAL REPOSITORY DEFECT** — not an environment problem (see §4.5).

### 4.3 Run #49 — the baseline

```
Flutter 3.44.9 • channel stable
Tools • Dart 3.12.2 • DevTools 2.57.0

00:13 +478: All tests passed!
```

| Metric | Value |
|---|---|
| Test files discovered | **35** |
| Test cases discovered | **478** |
| Test cases executed | **478** |
| **Passed** | **478** |
| **Failed** | **0** |
| **Skipped** | **0** |
| Errored | 0 |
| Duration | **13 s** |
| Flutter | 3.44.9 (stable) |
| Dart | 3.12.2 |
| Environment | `ubuntu-latest`, GitHub Actions, no emulator/device/secrets |

> **Correction to the brief's premise.** The brief refers to "the existing 35 tests". 35 is the number of test **files**; they contain **478 test cases**. The larger number is the correct one and is used throughout.

### 4.4 Whole-run status, run #49 (`4a38f33`)

| Job | Result |
|---|---|
| PHP syntax (`php -l`) | success |
| Security and regression suite | success — **1976 passed**, 926 subtests, 0 failed, 0 skipped, 89.5 s |
| **Flutter unit tests** | **success — 478 passed** |
| Migration numbering | success |

Total automated coverage now continuously executed: **2,454 tests** (1,976 Python + 478 Dart).

### 4.5 F-19 — the committed lockfile does not describe a buildable app

**NEW FINDING. Severity: Medium-High (build reproducibility). Confidence: High. Reproduced: yes (CI run #48, and locally).**

`url_launcher` is a declared direct dependency of `pubspec.yaml` (`url_launcher: ^6.3.2`) and is **absent from `pubspec.lock` entirely**:

```
direct deps: 23
MISSING from pubspec.lock: ['url_launcher']
```

Consequently `flutter pub get --enforce-lockfile` cannot succeed, and pub reports it would change 12 dependencies (adding the 8 `url_launcher*` packages, downgrading `test_api` and `vector_math`).

**Impact.** The lockfile cannot pin builds. Every developer, every CI run, and every release build may resolve a different dependency set within `pubspec.yaml`'s ranges. For a release-signed mobile app this is a reproducibility and supply-chain concern.

**Why it was not fixed here.** Regenerating the lock requires a local Flutter SDK and *is* dependency work, which this phase explicitly forbids. CI therefore runs plain `flutter pub get`, bounded by `pubspec.yaml`'s declared constraints, with the reason recorded in a comment in the workflow.

**Recommended direction (S1, first task):** a developer with Flutter runs `flutter pub get`, commits the regenerated `pubspec.lock`, then CI restores `--enforce-lockfile`. Small, mechanical, and it makes the pin real.

---

## 5. Test coverage assessment (§7 of the brief)

Classification uses the required vocabulary. "Dart" = `Mobile/wbws_flutter_app/test/`; "Python" = `tests/security/` (already in CI). Both now run on every push.

| Behaviour | Status | Evidence |
|---|---|---|
| Outbox state transitions | **COVERED** | `sync_recovery_models_test.dart`; `test_mobile_v34_sqlite_runtime.py` (real SQLite) |
| Retry behaviour (classification) | **COVERED** | `outbox_policy_test.dart` pins **all 8** `OutboxDecision` values |
| Retry delays (ladder) | **COVERED** | `drain_outcome_test.dart:8` asserts `[2,5,12,30,60,120,300,900,900]` incl. saturation |
| `Retry-After` | **COVERED** | `drain_outcome_test.dart` tests clamping at `0` and `7200` → 1 s / 3600 s |
| Idempotency (client recognition) | **PARTIALLY_COVERED** | `outbox_policy_test.dart` covers `idempotencyReplayed`; no client test posts a real request |
| Idempotency (server) | **PARTIALLY_COVERED** | `test_api_idempotency.py` is **source-string only** (§12.4); runtime proven here by probe, not by a committed test |
| Duplicate operations | **PARTIALLY_COVERED** | natural-key grouping covered; end-to-end duplicate suppression not |
| Account ownership / `owner_user_id` | **COVERED** | `session_state_test.dart`; `test_mobile_session_coordinator.py`; runtime SQL probe §15 |
| Authorization version | **COVERED (Python only)** | `test_authorization_scope_version.py`, `test_mobile_scope_reconciliation.py`; **0 Dart files** |
| Logout | **COVERED (Python only)** | `test_mobile_session_coordinator.py::test_logout_and_forgot_pin_use_explicit_central_destructive_policy`; **0 Dart files** |
| Session expiration | **COVERED** | `outbox_policy_test.dart`, `session_state_test.dart`, `test_refresh_token_rotation.py` |
| App restart | **PARTIALLY_COVERED** | recovery SQL covered; no test boots the app |
| Stranded `in_flight` | **PARTIALLY_COVERED** | `sync_recovery_models_test.dart` + runtime SQL probe §6; **no test asserts the `onOpen` wiring** |
| Account switching | **COVERED** | `session_state_test.dart` (`canActivateCandidate`), `test_mobile_session_coordinator.py` |
| Incomplete attendance | **PARTIALLY_COVERED** | `attendance_sync_coordinator_test.dart` covers delta reconciliation, not form completeness |
| Sync acknowledgement | **COVERED** | `drain_outcome_test.dart`, `sync_recovery_models_test.dart` |
| Replay behaviour | **PARTIALLY_COVERED** | client classification covered; server replay proven by probe only |
| **Telemetry granularity** | **NOT_COVERED** | no test asserts what `recordSyncResult` emits — see §13 |
| **Background sync** | **NOT_TESTABLE_IN_CURRENT_SUITE** | no scheduler exists to test (S0 F-04) |
| **Force-stop / reboot** | **NOT_TESTABLE_IN_CURRENT_SUITE** | needs a device/emulator harness |

**Testing roadmap implication:** the two cheapest, highest-value additions for S1 are (a) a Dart test pinning telemetry emission semantics, and (b) a test asserting that `recoverOrphanedInFlightOperations` is wired into `onOpen`. Neither requires new architecture.

---

## 6. F-13 verification — stranded `in_flight` recovery

> **Result: RESOLVED. The recovery sweep exists, and its ordering is structurally guaranteed. S0's concern is disproven.**

### 6.1 The mechanism (FACT)

`local_db.dart:796–834`:

```dart
/// Public for startup orchestration and deterministic recovery tests.
Future<void> recoverOrphanedInFlightOperations() async { ... }

Future<void> _recoverOrphanedInFlightWithDb(Database db) async {
  ...
  await db.transaction((txn) async {
    for (final spec in existingLegacy) {
      await txn.rawUpdate(
        "UPDATE ${spec.table} SET sync_state = 'retry_wait', next_attempt_at = ? "
        "WHERE synced = 0 AND sync_state = 'in_flight'", [now]);
    }
    if (hasHymn) { ...pending_hymn_ops... }
    if (hasComm) {
      await txn.rawUpdate(
        "UPDATE comm_outbox SET state = 'retry_wait', next_attempt_at = ? "
        "WHERE state = 'in_flight'", [now]);
    }
  });
}
```

All six outboxes, one transaction, `next_attempt_at = now` (immediately due).

### 6.2 The ordering guarantee — the part S0 could not establish

`local_db.dart:561–566`:

```dart
onOpen: (db) async {
  // No HTTP request survives its issuing process. Recover durable claims
  // before any scheduler can observe/select work, preserving operation
  // and idempotency identity for safe replay.
  await _recoverOrphanedInFlightWithDb(db);
},
```

The sweep is wired into **sqflite's `onOpen` callback**, which runs before `openDatabase()` completes. Since every query in the app obtains its handle from that future, **no caller can observe the database before the sweep has run.** Mis-ordering is not merely unlikely — it is structurally impossible.

A second call site exists for scope changes (`session_service.dart:304`), ordered before quarantine, with the rationale in a comment.

### 6.3 Runtime proof (executed)

Real SQLite, with the claim and recovery SQL lifted verbatim from `local_db.dart`:

```
=== F-13: stranded in_flight recovery (REAL SQL) ===
  before recovery: claimable=0  (0 => stranded, invisible)
  after  recovery: claimable=1  state=retry_wait attempt_count=1 (preserved, not reset) next_due=set
```

### 6.4 Answers to the §9 questions

| Question | Answer |
|---|---|
| Does `in_flight` survive termination? | **Yes** — committed SQLite row |
| Converted back to retryable? | **Yes** — → `retry_wait`, due immediately |
| Considered failed? | **No** |
| Permanently stranded? | **No** — but it *is* unclaimable until the sweep runs (proven: `claimable=0`) |
| Does a startup sweep execute? | **Yes** — on every database open |
| Retry counter changed? | **No — preserved** (`attempt_count=1`). Correct: the attempt genuinely happened |
| Operation duplicated? | **No** — the same `client_op_id` is reused, so the server replays rather than re-executing (§12) |
| Idempotency protects? | **Yes** — identity is deliberately preserved, as the code comment states |
| User sees anything? | Counted in `inventory.inFlight` and surfaced via the offline banner / Sync Center |
| Telemetry records it? | **No** — recovery is invisible to telemetry |

**Scenario coverage:** Scenario A (force close) and Scenario B (process death) are both covered by the same mechanism and are proven at SQL level. **Scenario C (device reboot) was NOT reproduced** — no device/emulator. The mechanism is reboot-agnostic (it triggers on database open, not on a lifecycle event), but that is an inference, not an execution result.

---

## 7. F-14 verification — logout "discard" path

> **Result: RESOLVED. Not a defect. The implementation is careful, and the safe default is Cancel.**

`lib/widgets/session_logout_dialog.dart`, in full behaviour:

- Loads a live SQLite inventory before prompting (`session.refreshInventory()`).
- **If `inventory.hasPrivateDurableWork`:** shows an `AlertDialog` with **`barrierDismissible: false`** (cannot be tapped away), titled *"Keep your offline work?"*, naming the exact work via `inventory.workSummary` (e.g. *"2 attendance batch(es), 1 pending message(s), 3 nonempty draft(s)"*), and offering three actions:
  1. `Cancel`
  2. `Keep work & sign out` → `preserveForReauthentication`
  3. **`Discard permanently`** → `discardPrivateData`, rendered as a **red `FilledButton`**
- **Safe default:** `await session.applyLogoutChoice(choice ?? LogoutChoice.cancel)` — any null result (dismissal) becomes **Cancel**, never discard.
- It additionally discloses the shared-hymn exception: *"N shared hymn operation(s) are not private-account work and will be kept."*

### 7.1 Per-case outcome (§10 of the brief)

| Case | Preserve chosen | Discard chosen |
|---|---|---|
| **A. Incomplete draft** | **PRESERVED** | **DELETED** (counted in `communicationDrafts` / packet rows, so the user is warned) |
| **B. Valid ready-to-sync** | **PRESERVED** | **DELETED** (warned) |
| **C. Currently syncing (`in_flight`)** | **PRESERVED**, then recovered to `retry_wait` on next open | **DELETED**; `_stopPrivateServices()` runs first, preventing a drain/purge race |
| **D. `retry_wait`** | **PRESERVED** | **DELETED** (warned) |
| **E. `paused_auth`** | **PRESERVED** | **DELETED** (warned; counted in `pausedOperations`) |

Exact code path: `showSessionLogoutDialog` → `SessionCoordinator.applyLogoutChoice` (`session_service.dart:839–853`) → either `enterReauthentication(reason:'explicit_logout_preserve', revokeCurrentSession:true)` or `destructiveSignOut(reason:'explicit_logout_discard')`.

> **The brief's instruction — "do not assume the word *discard* in a UI means the database record is deleted; verify it" — was followed.** It *does* delete, via `destructiveSignOut` → `_markPurging` → `_finishPurging`. The protection is not that deletion is avoided, but that it is explicit, itemised, non-default and non-dismissible.

**Not verified:** the dialog was not rendered (no device). This is a source-reading result for the widget tree; the *policy* beneath it is covered by `test_mobile_session_coordinator.py`, which passes in CI.

---

## 8. Session expiration verification

> **Result: CONFIRMED as S0 described. No change.**

- Transient refresh failure is **explicitly not** treated as expiry — `outbox_policy.dart:131–133` returns `retryable`, with the reasoning in a comment. This is the distinction the brief insists on, and it is covered by `outbox_policy_test.dart` (now executing).
- Definitive rejection → `pauseForAuthentication` → `paused_auth`; session → `reauth_required` (`stateForMissingCredentials`).
- `session_service.dart` persists recovery **before** clearing credentials — pinned by `test_mobile_session_coordinator.py::test_auth_loss_persists_recovery_before_clearing_credentials`.
- Server-side rotation with reuse detection is production-verified (`api_refresh_sessions`) and covered by `test_refresh_token_rotation.py`.

| Aspect at expiry | Outcome |
|---|---|
| Current screen | Driven by coordinator state (`SessionRoot`) |
| Form state (unsaved) | **Lost** — widget state, never persisted |
| Local draft | **Retained** |
| Incomplete state | **Retained**, still `packet_kind='draft'` |
| Outbox | **Retained**, `paused_auth` |
| Sync worker | Paused; generation fencing discards late settlements |
| Token state | Cleared **after** recovery is persisted |
| Retry state | `attempt_count` / backoff preserved |
| User message | *"Sync paused until this account is active again."* |
| Recovery path | Re-login as the same user; rows become claimable once owner **and** `authorization_version` match again |

**Attendance specifically:** a complete, submitted sheet waiting at session expiry is retained and is **not** presented as incomplete — `packet_kind` and `sync_state` are independent columns (runtime-confirmed in §11).

---

## 9. Account switching verification

> **Result: CONFIRMED and now runtime-proven at the database layer.**

### 9.1 Runtime proof (real SQLite, verbatim claim SQL)

```
=== ACCOUNT ISOLATION: can User B claim User A's row? ===
  claim as owner A(11,v4): 1  <- A can claim own work
  claim as owner B(22,v4): 0  <- B CANNOT claim A's work

=== AUTH VERSION FENCE: stale scope cannot claim ===
  claim as A with old authv=3: 0  <- stale scope blocked
```

This is the §17 requirement — proven at the **database/repository/sync-worker query level**, not from the UI.

### 9.2 The three layers (unchanged, not weakened)

1. `canActivateCandidate()` refuses activation and `_api.revokeBundle(candidate)` revokes the new token bundle.
2. Every claim requires `owner_user_id = ? AND created_authorization_version = ?`, re-checked per row.
3. `generation` fencing aborts settlements belonging to a superseded session.

### 9.3 F-11 resolved — ownerless rows

`backfillOwnerlessRows` (`local_db.dart:6599–6640`) adopts `owner_user_id IS NULL` rows across all four legacy outboxes, `comm_outbox`, `comm_drafts` and `pending_hymn_ops`. Its caller (`session_service.dart:293–299`) binds them to the **previous** authorization scope, never the new one:

> *"Bind them to the previous scope, never the new one, so the quarantine below cannot accidentally authorize legacy work."*

Runtime proof that the pre-backfill state is **safe-but-stranded**, not a leak:

```
=== NULL OWNER (pre-v34 rows): claimable? ===
  claimable by anyone: 0  (0 => stranded until backfillOwnerlessRows runs)
```

**F-11 is downgraded from Medium-High to Low** and closed.

---

## 10. F-15 — `pending_hymn_ops` security/domain assessment

> **Classification: `INTENTIONAL BUT NEEDS DOCUMENTATION`.**
> **No privilege escalation. The defect is attribution only.**

| # | Question | Finding |
|---|---|---|
| 1 | Why shared? | Hymn library content is shared catalogue data, not per-account work. Stated in `session_models.dart:207` |
| 2 | What entity? | Shared hymn/category/zemarian catalogue + synced lyrics |
| 3 | Private user data? | **No** — ops are `hymn_save`, `hymn_status`, `category_save`, `category_status`, `zemarian_save`, `zemarian_status`, `lyrics_synced` |
| 4 | Account-specific authorization baked in? | **No** — `pending_hymn_ops` has no `created_authorization_version` gate on claim |
| 5 | Server accepts independently of the mobile account? | **No.** Every request is authenticated and re-authorised |
| 6 | Can a different account legitimately transmit it? | **Only if that account independently holds the permission** |
| 7 | Could B cause A's operation to execute? | **Yes — but only within B's own authority** |
| 8 | Unauthorized data changes? | **No** |
| 9 | Documented? | **Partly** — the logout dialog discloses it to the user; no engineering doc existed before this one |
| 10 | Is the server the real boundary? | **Yes** |

### 10.1 Server evidence (`api/v1/routes/mezmur.php`)

```php
:19   $auth = apiRequireAuth();
:36   if (!apiRoleIs($auth, $MEZMUR_ROLES)) err('You cannot access the Mezmur module.', 403, ['code'=>'FORBIDDEN']);
:320  err('Only Mezmur staff and admins can edit the hymn library.', 403, ...);
:328  $result = MezmurHymnService::saveHymn($conn, $input, (int)$auth['uid']);
:342  err('Only Mezmur staff and admins can edit the hymn library.', 403, ...);
```

Authorisation is re-evaluated **per request against the current bearer token**. A non-privileged User B receives 403; the operation then classifies as `needsAttention` and stops. There is no path by which A's queued op grants B anything, nor by which B's transmission grants A's op more authority than B has.

### 10.2 The real defect

`saveHymn(..., (int)$auth['uid'])` records **the transmitting user (B)** as the actor. If both A and B are Mezmur staff, A's edit is applied and attributed to B. The audit trail is wrong, though no unauthorised change occurs.

**Severity: Low-Medium (attribution/audit integrity, not authorisation).** **Not fixed in S1a, as instructed.** **Recommended direction for a later phase:** carry the originating `created_by_user_id` in the payload and have the server record it as the author while continuing to authorise on the bearer token.

---

## 11. Attendance verification

### 11.1 Runtime proof — drafts are sync-eligible

```
=== DRAFT vs SUBMITTED: is an incomplete draft sync-eligible? ===
  draft claimable: 1  (1 => drafts DO sync, as drafts)
```

This **confirms S0 §10 Q8 at runtime**: the claim query does not filter `packet_kind`, so a draft is claimed and transmitted — *as a draft*. This is deliberate (partial work survives device loss), not a leak of incomplete data into submitted records.

### 11.2 The three required cases

The brief's three sequences were traced through code and the persistence layer. **None was executed on a device** (no emulator) — persistence-layer facts are runtime-proven, UI facts are source-read.

| | Close app | Session expiry → auto-logout | Explicit logout |
|---|---|---|---|
| Partial work exists? | Only if **Save** was pressed | Same | Same |
| Where? | `pending_attendance`, `packet_kind='draft'` | same | same |
| Survives? | **Yes** | **Yes** | **Yes** if *Keep work*; **No** if *Discard* |
| Recoverable? | Yes, on reopen | Yes, after re-login as same user | Yes / No as above |
| UI knows it is incomplete? | **Yes** — `attendance_screen.dart:351` counts unmarked students | Yes | Yes |
| UI wrongly reports a sync problem? | **No** | **No** — shows `paused_auth` wording | **No** |
| Reaches the outbox? | Yes, as a draft row | Yes | Yes (unless discarded) |
| Reaches the server? | Yes, **as a draft** | Blocked while `paused_auth` | Only if preserved and re-authenticated |
| User can see what is missing? | **Yes** — *"Mark attendance for every student (N remaining)"* and a *"N unmarked"* indicator | Yes | Yes |
| Can resume? | Yes | Yes | Yes if preserved |
| Can discard intentionally? | Yes, via logout discard | Yes | Yes |

### 11.3 The required three-way distinction — does the system hold it?

| State | Stored as | Distinct? |
|---|---|---|
| **INCOMPLETE DATA** | `packet_kind='draft'` + unmarked count computed from roster | **Yes** |
| **VALID DATA WAITING FOR SYNC** | `packet_kind='submitted'`, `sync_state ∈ {pending, retry_wait}` | **Yes** |
| **VALID DATA FAILED TO SYNC** | `packet_kind='submitted'`, `sync_state ∈ {needs_attention, paused_auth, paused_scope}` + `failure_code` | **Yes** |

**These are three independent columns, never collapsed.** S0's central principle (*incomplete ≠ sync failed*) is upheld by the data model, now confirmed at runtime.

---

## 12. Idempotency verification — executed against real MariaDB

> **Result: the "server accepted, response lost" case is VERIFIED SAFE. A real re-execution window exists and is narrower than S0 estimated.**

Method: PHP 8.4.24 + MariaDB 11.8.6 locally; `sql/009_api_idempotency.sql` applied to a disposable database `ssms_idem_e2e`; the **real `ApiIdempotencyService`** driven directly. No production system contacted; no repository file changed.

### 12.1 Results (verbatim)

```
=== SCENARIO 1: first send (fresh key) ===
begin #1                                state=acquired

=== SCENARIO 2: server commits, response LOST, client retries ===
begin #2 (same key, same payload)       state=replay  status=200  body={"ok":true,"saved":40}
  rows in api_idempotency_records: 1 (1 = no duplicate reservation)

=== SCENARIO 3: same key, DIFFERENT payload ===
begin #3                                state=conflict

=== SCENARIO 4: concurrent in-flight (never completed) ===
begin A (acquires)                      state=acquired
begin B (same key, A in flight)         state=processing   retry_after=300

=== SCENARIO 5: LEASE EXPIRY ===
  record_state before retry: processing (lease now expired)
begin after lease expiry                state=acquired
  => the business write WOULD BE RE-EXECUTED

=== SCENARIO 6: different USER, same key ===
begin (user 99, same key)               state=acquired

=== SCENARIO 7: 7-day retention expiry ===
begin after retention expiry            state=acquired
```

### 12.2 Answers to the §14 questions

| Question | Answer |
|---|---|
| Does the server detect replay? | **Yes** — `state=replay` |
| Duplicate DB writes prevented? | **Yes** — stored response returned; one reservation row; business code never re-entered |
| HTTP response returned? | The **original** status and body, plus header `Idempotency-Replayed: true` |
| Does the client recognise replay? | **Yes** — `api_service.dart:86–87` parses the header into `idempotencyReplayed` |
| Does telemetry distinguish original success from replay? | **No** — telemetry has no operation dimension at all (§13) |
| Does the operation become `SYNCED`? | **Yes** — `classifyOutboxResponse` returns `accepted` for a 2xx replay |

### 12.3 F-20 — the re-execution window is 5 minutes, not 7 days

**NEW FINDING. Severity: Medium. Confidence: High. Reproduced: yes (Scenario 5).**

S0 framed the duplicate risk around the 7-day `RETENTION_SECONDS`. The runtime probe shows the binding constant is **`LEASE_SECONDS = 300`**: once a reservation's lease expires, a retry **re-acquires** and the business write re-executes.

This matters because the client's retry ladder saturates at **900 s**. A server that dies after `COMMIT` but before `apiIdempotencyStore` leaves the record `processing`; the client's next retry arrives ~900 s later — **comfortably past the 300 s lease** — and re-executes. This is a reachable production path, not a theoretical one, and it raises the practical likelihood of S0's F-07.

**Mitigation that genuinely applies:** for attendance, `AttendanceRecordService::replaceSheet` performs `DELETE FROM attendance WHERE class_id = ? AND attendance_date = ?` then re-inserts, and production enforces `UNIQUE(member_id, attendance_date)` — so re-execution converges. **For `comm_outbox` (append-semantics messages) duplication remains plausible and is still NOT VERIFIED.**

**Not fixed, as instructed.**

### 12.4 A note on the existing idempotency test

`tests/security/test_api_idempotency.py` (5 tests) is **entirely source-string assertion** — it greps `middleware.php` and `ApiIdempotencyService.php` for substrings and never executes the flow. It passes, but it would also pass if the logic were inverted, provided the strings remained. Given the standing project preference for behavioural over source-string tests, this is worth replacing with a behavioural test in S1; the probe in §12.1 is a ready template.

---

## 13. Telemetry verification

> **Result: S0's F-01 CONFIRMED by exact code reading. NOT runtime-executed — and I will not claim otherwise.**

`sync_service.dart:268–273`:

```dart
if (synced > 0 || failed > 0) {
  unawaited(TelemetryService.instance.recordSyncResult(
    success: failed == 0,
    itemsCount: synced,
    error: failed > 0 ? '$failed operations pending retry' : null,
  ));
}
```

| Scenario | Emitted today |
|---|---|
| **A.** One operation fails 3×, then succeeds | **4 events** across 4 drain passes: `sync_failed ×3` + `sync_completed ×1`. Each carries only `items_count` and the string `"1 operations pending retry"` |
| **B.** One pass: 9 succeed, 1 fails | **1 event**, `event_type = sync_failed`, `items_count = 9`. The nine successes are recorded under a *failed* event |
| **C.** Can telemetry reconstruct *same operation → attempt 1,2,3 → success*? | **No.** There is no operation id, no attempt number, no HTTP status and no failure code in the payload |

**This is the definitive answer to the "3 failed, 1 successful" question:** those are four **drain passes**, and the pattern is exactly what a *single successfully-synced operation* produces. The metric cannot distinguish that from four distinct lost operations.

**Verification status: CODE-VERIFIED, NOT RUNTIME-VERIFIED.** The expression is deterministic and three lines long, so confidence is High, but no test executes it and no device emitted an event during this phase. **This is the single most valuable Dart test to add in S1** — it is cheap and would permanently pin the semantics.

**Not redesigned, as instructed.**

---

## 14. Retry verification

> **Result: source behaviour CONFIRMED, and now continuously executed in CI.**

`drain_outcome_test.dart:8` asserts the exact ladder including saturation:

```dart
const expected = <int>[2, 5, 12, 30, 60, 120, 300, 900, 900];
```

and exercises `retryAfterSeconds: 0` and `retryAfterSeconds: 7200` against the documented 1–3600 s clamp. `outbox_policy_test.dart` pins all eight `OutboxDecision` outcomes. **All of these passed in CI run #49 — for the first time ever.**

Runtime proof that backoff is actually honoured by the claim query (real SQLite):

```
=== BACKOFF: retry_wait with a future next_attempt_at ===
  due now:       0  (backoff honoured)
  due at +1000s: 1  (becomes due)
```

| Property | Status |
|---|---|
| Retry count persists across restart | **Yes** — `attempt_count` column; preserved by the recovery sweep (§6.3) |
| Retry delay persists | **Yes** — `next_attempt_at` is durable, not an in-memory timer |
| Restart preserves retry state | **Yes** |
| `Retry-After` overrides local delay | **Yes**, clamped 1–3600 s, never shortened by jitter |
| Permanent errors stop retrying | **Yes** — 400/403/404/405/410/413/415/422 → `needsAttention` |
| Auth errors behave correctly | **Yes** — definitive → `paused_auth`; transient refresh failure → `retryable` |

**Caveat retained from S0:** transport failures have **no absolute attempt cap** (F-16). Still true, still not fixed.

**Not changed, as instructed.**

---

## 15. Database ownership verification (§17)

Executed against real SQLite using the verbatim claim SQL. Per-row columns inspected directly rather than through the UI.

```
owner A(11, authv=4) claims own work        -> 1 row
owner B(22, authv=4) claims A's work        -> 0 rows
owner A with stale authv=3                  -> 0 rows
rows with owner_user_id IS NULL             -> 0 rows claimable by anyone
```

Columns confirmed present and enforced on every private outbox (`pending_attendance`, `pending_grades`, `pending_hr`, `pending_mezmur`, `comm_outbox`, `comm_drafts`): `owner_user_id`, `created_authorization_version`, `client_op_id`, `sync_state`/`state`.

**Proven: `User A operation ≠ User B operation` at the database, repository and sync-worker layer.** No protection was weakened; `canActivateCandidate()` and token revocation are untouched; no outbox schema was modified.

---

## 16. Security findings

| ID | Finding | Severity | Status |
|---|---|---|---|
| F-15 | Hymn ops transmit under the signed-in account and are attributed to it | **Low-Medium** | `INTENTIONAL BUT NEEDS DOCUMENTATION`. No escalation — server re-authorises per request |
| F-11 | Ownerless pre-v34 rows | **Low** (was Med-High) | **CLOSED** — unclaimable before backfill; backfill binds to the *previous* scope |
| — | Cross-account sync of private work | — | **Disproven as a risk.** Runtime-proven blocked at the query layer |

**No new security vulnerability was found in this phase.** The S0 verdict stands and is now stronger: it rests on executed evidence rather than code reading alone.

---

## 17. Data-loss findings

| Path | Verdict |
|---|---|
| Force-stop mid-sync | **Safe** — recovered on next DB open, identity preserved (§6) |
| Reboot | **Safe for data**; sync still will not run until the app is opened (S0 F-04, unchanged) |
| Logout → Keep work | **Safe** |
| Logout → Discard | **Deletes**, but non-default, non-dismissible, itemised and red-labelled (§7) |
| Session expiry | **Safe** — retained as `paused_auth` |
| Account switch attempt | **Safe** — login refused before anything is touched |
| Unsaved form | **Lost** — widget state only. Unchanged from S0 |
| Reinstall | **Lost permanently** — no server copy. Unchanged from S0 |

**No new data-loss defect was found.** S0's two worst-case candidates (F-13, F-14) are both disproven.

---

## 18. Observability findings

Unchanged from S0, and now sharper:

| ID | Finding | Status after S1a |
|---|---|---|
| F-01 | Telemetry counts drain passes, not operations/attempts | **Confirmed** (§13), still the primary gap |
| F-02 | No attempt lineage | **Confirmed** — `attempt_count` proven to be a bare counter preserved across recovery |
| F-03 | No request/correlation id | Unchanged |
| F-06 | Telemetry fire-and-forget, lost offline | Unchanged |
| F-08 | `sync_log` vestigial | Unchanged |
| **F-20** | **Idempotency lease is 300 s, making re-execution reachable within one retry interval** | **New** |
| **F-19** | **`pubspec.lock` cannot satisfy `pubspec.yaml`** | **New** |

**The join key noted in S0 is confirmed:** `api_idempotency_records.idem_key` **is** the client's `client_op_id` (the probe used exactly that identity end to end). A server-side ledger can be built on an identity that already flows in production — it only needs to stop being discarded after 7 days.

---

## 19. Confirmed architecture

Verified by execution this phase, and **to be preserved**:

1. **Nine-state outbox machine** — all eight decisions pinned by passing tests.
2. **Retry ladder `[2,5,12,30,60,120,300,900]`** with full jitter and `Retry-After` clamping — exact values asserted by a passing test; backoff honoured by the claim query at runtime.
3. **Durable, owner- and scope-scoped claiming** — proven at SQL level, including the stale-scope fence.
4. **Atomic claim** with coherence guards that throw rather than sync partial work.
5. **`onOpen` recovery sweep** that makes stranded `in_flight` structurally impossible to leave behind, while preserving operation identity for safe replay.
6. **Login gate + bundle revocation** preventing cross-account activation.
7. **`backfillOwnerlessRows`** binding legacy rows to the previous scope, never the new one.
8. **Stripe-style server idempotency** — runtime-proven to replay, conflict, and serialise correctly.
9. **Refresh-token rotation with reuse detection** (production-verified table).
10. **A logout policy that treats the user's unsent work as valuable.**

**None of this should be rebuilt in S1.**

---

## 20. Remaining unknowns

| Unknown | Why it remains | Blocks S1? |
|---|---|---|
| Device-level force-stop / reboot (Scenario C) | No emulator or device harness | **No** — mechanism is DB-open-triggered and SQL-proven |
| Widget-tree rendering of sync state on the originating screen (F-12) | No device; widget tests do not cover these screens | **No** — a UX question for S4/S5 |
| Telemetry emission at runtime (§13) | Requires a running app | **No** — deterministic, and S1 will replace the emission path anyway |
| Natural idempotency of non-attendance writes under re-execution (F-07/F-20) | Requires seeding grades/comm schemas and driving the routes | **No, but it is the first thing S1 should measure** |
| Whether `api_idempotency` (legacy table) is still read anywhere | Not traced | No |
| Real production telemetry volumes / retention pressure (F-09) | No production access | No |

---

## 21. Recommendations for S1

Ordered by dependency, revised against this phase's evidence:

1. **Regenerate `pubspec.lock` and restore `--enforce-lockfile`** (F-19). Mechanical, makes the version pin real, and must precede any dependency-touching work.
2. **Add two cheap Dart tests** that permanently pin behaviour this phase could only read:
   - telemetry emission semantics (§13);
   - the `onOpen` wiring of `recoverOrphanedInFlightOperations` (§6.2).
3. **Build the operation ledger + attempt history** (S0 §27). Everything needed is already computed at claim and settle time and then discarded. Additive; nothing is replaced.
4. **Introduce a request/correlation id** (F-03), echoed in responses and persisted both sides — joining on the already-flowing `client_op_id` / `idem_key` identity.
5. **Measure natural idempotency of grades/HR/mezmur/comm writes** under forced re-execution, then decide whether to move `apiIdempotencyStore` into the business transaction (F-07/F-20).
6. **Replace `test_api_idempotency.py`'s source-string assertions with the behavioural probe** from §12.

**Explicitly not in S1:** no new outbox, sync engine, retry system, auth system, telemetry system or scheduler.

## 22. Recommendations for S2+

- **S2** — telemetry granularity (F-01, F-06, F-16, F-17), on top of the S1 ledger.
- **S3** — background sync (S0 F-04). Largest behavioural change; **requires explicit owner sign-off** against the standing "no background services" constraint.
- **S4** — sync-centre and screen-level status surfacing (F-18, F-12).
- **S5** — hymn-op attribution (F-15): carry the originating author in the payload; keep authorising on the bearer token.
- **S6** — retention policy for `app_telemetry_events` (F-09), kept strictly separate from user-data retention.
- **S7** — 90-day diagnostic history; **S8** — chaos/device testing, now that CI exists to run it.
- **Separately, cheap:** decide the intent of `UNIQUE(member_id, attendance_date)` vs `uq_att_member_class_date` (S0 F-10).

---

## 23. Test evidence

| Evidence | Where |
|---|---|
| CI run **#48** (`b9809c7`) — Flutter job **failure**, exit 65, lockfile unsatisfiable | GitHub Actions run `37240296263`, job `111547495213` |
| CI run **#49** (`4a38f33`) — Flutter job **success**, `00:13 +478: All tests passed!` | run `37240553489`, job `111548245327` |
| CI run #49 — Security suite **1976 passed**, 926 subtests, 0 failed, 0 skipped | same run |
| Mobile Python subset executed locally — **78 passed in 0.93 s** | `test_mobile_v34_sqlite_runtime.py`, `test_mobile_sync_recovery_center.py`, `test_mobile_session_coordinator.py`, `test_mobile_outbox_race_safe.py`, `test_mobile_scope_reconciliation.py`, `test_mobile_local_storage.py` |
| Idempotency/telemetry/auth subset executed locally — **103 passed in 0.31 s** | `test_api_idempotency.py`, `test_app_telemetry.py`, `test_typed_api_failures.py`, `test_refresh_token_rotation.py`, `test_authorization_scope_version.py`, `test_f8_outbox_rejection.py`, `test_attendance_sync_phase_b.py`, `test_auth_outbox_rollout_gates.py` |
| **Idempotency runtime probe** — 7 scenarios, real PHP 8.4.24 + MariaDB 11.8.6 | §12.1 (script held outside the repository) |
| **Outbox runtime probe** — 6 scenarios, real SQLite with verbatim claim/recovery SQL | §6.3, §9.1, §11.1, §14 |

**No test was added, weakened, skipped, duplicated or marked as an expected failure in this phase.** The two probes were deliberately kept outside the repository: they verify current behaviour rather than assert a requirement, and committing them would imply a contract this phase was not asked to define. §21 recommends converting the idempotency probe into a committed behavioural test in S1.

---

## 24. Verification limitations

Stated plainly, because the brief forbids claiming runtime verification where only static inspection was possible:

| Claim class | Status |
|---|---|
| Dart test suite passes | **EXECUTED** — GitHub Actions, Flutter 3.44.9 / Dart 3.12.2 |
| Outbox claim / ownership / backoff / recovery SQL | **EXECUTED** — real SQLite, verbatim SQL from `local_db.dart` |
| Server idempotency replay / conflict / lease / retention | **EXECUTED** — real PHP + MariaDB against the real service |
| Server authorisation on hymn writes | **SOURCE-VERIFIED** (exact file and lines); not executed |
| `onOpen` wiring of the recovery sweep | **SOURCE-VERIFIED**; the structural guarantee is an inference from sqflite's documented contract |
| Logout dialog behaviour | **SOURCE-VERIFIED**; the dialog was never rendered |
| Telemetry emission | **SOURCE-VERIFIED**; never emitted |
| Device force-stop / reboot / account switch on hardware | **NOT VERIFIED** — no device or emulator |
| Production mobile database state | **NOT VERIFIED** — no device access. *Production mobile database state not verified.* |

I did **not** install the Flutter SDK locally; all Dart execution happened on the CI runner.

---

## S1 DESIGN GATE

```
READY_FOR_S1
```

**Justification.** The four behaviours S0 could not verify are resolved: the `in_flight` recovery sweep exists and cannot be mis-ordered (§6); logout is explicit, itemised and defaults to Cancel (§7); ownerless rows are stranded-not-leaked and are adopted into the *previous* scope (§9.3); and the hymn-queue exemption is an attribution defect, not an authorisation hole (§10). Account isolation and the retry ladder are now proven at runtime rather than read. Crucially, the mobile suite is executing in CI for the first time (478/478), so S1 can change this code with a regression net underneath it.

**Qualification — two conditions, neither of which blocks design:**

1. **F-19 must be fixed before any dependency-touching work.** Until `pubspec.lock` is regenerated, the CI pin is real for the SDK but not for packages.
2. **The remaining unknowns in §20 are all *measurements*, not *architecture questions*.** None of them can change the shape of S1, which is additive observability over machinery now demonstrated to work.

The gap S1 must close is unchanged and now evidenced from both ends: the system can retry correctly but cannot explain itself. **Add lineage and diagnostic visibility. Preserve the synchronization infrastructure.**
