# S2 Goal A.1 — Dart-Side Background Sync Core Architecture

Commit: `0ed7d72` · baseline `c21d11f` · schema v36 → **v37**

Evidence labels used throughout:

| Label | Meaning |
|---|---|
| `VERIFIED LOCALLY` | Executed in this environment, numbers in §9 |
| `VERIFIED BY SOURCE` | Established by reading shipped source |
| `NOT VERIFIED` | Could not be executed here; no success is inferred |
| `PLATFORM LIMITATION` | Impossible in this environment, with the reason |

---

## 1. The architecture before this increment (`VERIFIED BY SOURCE`)

`SyncService` was already a singleton with a single drain. Triggers were:

```
startAutoSync()  ──┐
nudge(delay)     ──┼──> _syncAllForGeneration(generation, force:)
syncAll(force:)  ──┘            │
                                ▼
                     _drain(generation:, force:)
                                │
                  for each LegacyOperationKind
                                ▼
                     _drainLegacyKind(kind, generation)
                                │
                                ▼
              LocalDb.claimNextLegacyOperation(...)   <-- the authoritative gate
```

The claim query — not the service — is what decides eligibility. It already
enforced, in one SQL statement inside one transaction:

```sql
WHERE synced = 0
  AND sync_state IN ('pending','retry_wait')
  AND (next_attempt_at IS NULL OR next_attempt_at <= ?)   -- retry schedule
  AND owner_user_id = ?                                   -- account isolation
  AND created_authorization_version = ?                   -- auth generation
```

preceded by `activeSessionMatches(runtimeGeneration, ownerUserId,
authorizationVersion)`.

**This single fact shaped the whole increment.** The correctness properties the
brief asks about (overdue work runs, future work does not, user B cannot send
user A's work, a restarted process rediscovers work) are not properties of the
*trigger*. They are properties of the *claim*. The safest possible background
architecture is therefore one that adds no second claim — and that is what was
built.

Two pre-existing facts worth recording:

- `claimNextHymnOperation` (used by `HymnStore`) does **not** open a
  `sync_attempts` row. The S1 attempt ledger covers the legacy outbox path
  only. That is a pre-existing S1 gap, not introduced here, and `execution_source`
  consequently covers the same scope as S1 lineage did.
- There is no sqflite test binding in this repository (`sqflite_common_ffi` is
  not a dev_dependency) and `pubspec.yaml` is frozen by F-19, so no *Dart* test
  can open a database.
  **Corrected by the A.3 audit:** the original sentence here read "No test in
  the repo opens a database", which is false. Ten Python harnesses under
  `tests/security/` execute real SQLite 3 through `sqlite3`, loading the
  shipped DDL out of `local_schema_v34.dart`. The limitation is specific to
  Dart, not to the repository.

---

## 2. The new unified execution boundary

```
foreground trigger  ─┐
future OS worker    ─┼─> BackgroundSyncCoordinator.execute(source)
future scheduler    ─┘                  │
                                        ▼
                      SyncService.runSyncNow({source, force})
                                        │
                     ... the existing, unmodified drain ...
                                        ▼
                         claimNextLegacyOperation(...)
                                        ▼
                                     outbox
```

`SyncService.runSyncNow({SyncExecutionSource source, bool force})` is the entry
point. `syncAll({force})` is now a one-line delegation to it, so every
pre-existing foreground caller reaches the same place it always did.

A background caller changes exactly one thing: the `source` label on the
attempts it produces. It does not get its own drain, its own claim, its own
retry clock or its own queue.

**Pinned by test** (`test_mobile_background_sync_core.py`): exactly one
`claimNextLegacyOperation` call site exists in `lib/`; exactly one `_drain`
implementation exists; `syncAll` delegates to `runSyncNow`.

---

## 3. Execution-source semantics

```dart
enum SyncExecutionSource { foreground, background }   // storage: 'foreground' | 'background'
```

Two values, deliberately. A larger taxonomy (`startup`, `manual`,
`connectivity`) was considered and rejected: those are all reasons a
*foreground* drain started, and baking an unverified vocabulary into a durable
column is harder to undo than to add later. The rule for adding a value is
stated in the source: add one only when a real caller cannot be described.

Threading (`VERIFIED BY SOURCE`, mutation-pinned M1–M3):

```
runSyncNow(source:)
  -> _syncAllForGeneration(generation, force:, source:)
    -> _drain(generation:, force:, source:)
      -> _drainLegacyKind(kind, generation, source)
        -> claimNextLegacyOperation(..., executionSource: source)
          -> _openSyncAttempt(..., executionSource:)
            -> INSERT sync_attempts(execution_source, ...)
```

The write happens **inside the claim transaction**, alongside the attempt row
S1 already wrote, so provenance cannot be lost separately from the attempt
itself.

Operation identity is unchanged: `client_op_id`. No replacement id was
introduced (pinned: `operationUuid`/`backgroundOpId`/`jobId` must not appear).

### Schema v37

```sql
ALTER TABLE sync_attempts ADD COLUMN execution_source TEXT NOT NULL DEFAULT 'foreground'
```

Guarded by a `PRAGMA table_info` probe, because a v36 install created by
`_createSyncAttemptLedger` now already ships the column and adding it twice is
an error.

The `'foreground'` backfill is a **factual claim, not a convenient default**:
every attempt recorded before v37 was necessarily produced by an in-app drain,
because no background execution path existed to produce any other kind.
`SyncExecutionSource.fromStorage` resolves NULL/unknown to `foreground` for the
same reason.

The migration is additive only — pinned that no `DROP TABLE`, `DELETE FROM
sync_attempts` or `UPDATE sync_attempts` appears in the v37 block.

---

## 4. Scheduler abstraction

```dart
abstract class BackgroundSyncScheduler {
  Future<void> ensureScheduled(BackgroundSyncRequest request);
  Future<void> cancel();
}
```

`sync_execution.dart` **imports nothing** — no Flutter, no sqflite, no platform
channels, no `workmanager`. That is pinned by test, not by convention, so a
future import is a build failure rather than a review comment.

`BackgroundSyncRequest` carries `notBefore` and `requiresNetwork` and nothing
else. It is *structurally* incapable of carrying a payload or a credential, and
a test asserts the field list is exactly those two. This matters because a
scheduler request may be persisted by the OS and outlive the session that
created it.

`NoopBackgroundSyncScheduler` is the default. It does nothing and says so: with
it installed background sync never happens and foreground is untouched. That is
the correct behaviour for an app shipped before the native layer exists — not a
stub pretending to work.

---

## 5. Scheduler deduplication

There is exactly one logical unit of background work, so there is exactly one
unique work name: `BackgroundSyncRequest.uniqueWorkName = 'fkss.sync.drain'`.

`BackgroundSyncCoordinator.requestOpportunity()` collapses equivalent triggers
— connectivity returned, app resumed, user opened the app, a retry came due,
startup — into a single pending request. `execute()` consumes it so the next
trigger can re-arm. A failed enqueue clears the flag rather than wedging the
coordinator into believing in an opportunity the platform never took.

**Honest limitation, stated in the source as well as here:** the pending flag
is in-memory and does **not** survive process death. It is an optimisation, not
a correctness mechanism. Correctness rests on two layers that do survive:

1. the OS-level unique work name, which the native implementation MUST bind to
   `ExistingWorkPolicy.KEEP`-style unique work; and
2. the fact that a redundant execution is harmless — the claim admits only
   eligible rows, and per-operation idempotency (`client_op_id`, and the F-20
   fix in `c21d11f`) makes a re-send safe.

Worst case after a restart is one extra scheduling call. Never a duplicated
business effect.

The coordinator holds no queue: pinned that it contains no `List<`, `Map<`,
`Queue<`, `insert(` or `sqflite`.

---

## 6. Retry eligibility

Unchanged, and deliberately so. No retry ladder, no backoff maths, no second
clock was added — pinned that `pow(`, `backoff`, `retryLadder` and literal
durations do not appear in `sync_execution.dart`.

`BackgroundSyncRequest.notBefore` is **advisory only**: a hint so the OS is not
woken pointlessly early. It is not authoritative. If the platform runs the app
late, early, or twice, the claim still admits exactly the rows whose
`next_attempt_at` has passed. Overdue work is discovered by the existing query;
future work is excluded by it.

---

## 7. Process-death assumptions

Nothing volatile became a source of truth. Durable state remains: outbox rows
and their `sync_state`, `client_op_id`, `attempt_count`, `next_attempt_at`,
`owner_user_id`, `created_authorization_version`, and the S1 `sync_attempts`
ledger (now including `execution_source`).

A newly constructed executor determines required work solely by running the
existing claim against that durable state. No second persistent queue was
added. `onOpen` orphan recovery (`_recoverOrphanedInFlightWithDb`) is untouched
and still runs before any scheduler can observe work.

---

## 8. Account and session safety

The background path cannot bypass ownership because it has no query of its own.
The A→logout→B→background-send scenario is prevented by the same three
predicates that prevent it in the foreground, inside the same transaction.

`cancelOpportunity()` cancels the **wake-up**, never the work. Pending
operations stay in the outbox owned by their original user and drain when that
user is active again. Deleting them would be a data-loss bug masquerading as
isolation, and is explicitly not done.

Mutations M5 and M6 delete the `owner_user_id` and
`created_authorization_version` predicates from the claim; both are caught.

---

## 9. What is verified, with exact numbers (`VERIFIED LOCALLY`)

```
Python suite ... 2026 passed / 926 subtests passed / 0 failed / 0 skipped  (82.26 s)
                 S1 baseline was 2012; +14 new architecture tests
F-20 harness ... 67 checks PASS  (48 S1 + 19 from c21d11f)
Mutation ....... 13 attempted / 13 caught / 0 survived / 0 skipped
PHP lint ....... 5 files clean (unchanged from c21d11f)
```

Mutation coverage maps to the brief's required targets:

| Mutation | Target | Result |
|---|---|---|
| M1–M3 | remove execution-source propagation | CAUGHT |
| M4 | ignore `next_retry_at` | CAUGHT |
| M5 | bypass owner guard | CAUGHT |
| M6 | bypass auth-version guard | CAUGHT |
| M7 | route background through a different executor | CAUGHT |
| M8 | make the core platform-dependent | CAUGHT |
| M9 | let a request carry a payload | CAUGHT |
| M10 | give the coordinator a queue | CAUGHT |
| M11 | un-guard the migration | CAUGHT |
| M12 | revert the schema version | CAUGHT |
| M13 | stop delegating to the unified entry | CAUGHT |

**Two defects in my own tests were found by this mutation run and fixed before
the commit landed**, which is the only reason the table reads 13/13:

1. M5/M6 originally **survived**. The isolation assertion searched a 4000-char
   window in which the same SQL predicate occurs several times, so deleting it
   from the candidates `SELECT` went unnoticed. The assertion now pins that
   `SELECT` exactly.
2. M5 then still appeared to survive for a different reason: its anchor string
   occurs **8 times** in `local_db.dart`, so the mutation was silently editing
   an unrelated query. A mis-targeted mutation proves nothing; it is now
   anchored to text unique to the claim.

Both are recorded in `tests/mutation/background_sync_core_mutations.py` so the
harness documents its own history.

### Existing pins that moved

Four pins moved with the migration; none was weakened, skipped or deleted:

- `test_mobile_sync_attempt_ledger.py` — ledger column set gained
  `execution_source`; version pin 36 → 37, with `if (oldVersion < 36)` retained
  as unchanged history and `if (oldVersion < 37)` added.
- `test_attendance_sync_phase_b.py`, `test_mobile_v34_sqlite_runtime.py` —
  version pin 36 → 37.
- `test_mobile_session_coordinator.py` — **not** edited. It pins
  `_syncAllForGeneration(generation)` and `_drain(generation: generation`; my
  first draft broke both by adding a redundant explicit argument and by
  line-wrapping. The *code* was adjusted back instead of relaxing the pin,
  since the default already is `foreground`.

---

## 10. What is explicitly NOT verified

- **The 15 Dart tests in `test/sync_execution_test.dart` were NOT EXECUTED.**
  `PLATFORM LIMITATION`: there is no Flutter SDK in this environment, installing
  one is forbidden, every test in the repo imports `package:flutter_test`, and
  `pubspec.yaml` is frozen by F-19 so no VM-only test runner can be added. They
  run in CI (`flutter test`, Flutter 3.44.9). Until CI reports, their status is
  `NOT VERIFIED`.
- **Behavioural database coverage for this increment is partial.**
  Execution-source *persistence at write time*, overdue/future claim
  eligibility, process-restart discovery and account isolation are
  `VERIFIED BY SOURCE` and mutation-pinned, but not executed against SQLite.
  Brief items 10-B (ledger round-trip through a real DB), 10-E, 10-F, 10-G and
  10-H remain **NOT VERIFIED** at runtime. What *is* verified is that this
  increment did not modify the SQL that implements them, and that deleting any
  of those predicates fails the suite.
  **Corrected by the A.3 audit:** the original heading here read "No database
  test exists for this increment", which overstated the gap — see the next
  bullet.
- **The v37 *create* path IS runtime-verified.**
  `VERIFIED BY REAL SQLITE RUNTIME`.
  `tests/security/test_mobile_sync_attempt_ledger.py` extracts the shipped
  `localSyncAttemptsV36Sql` constant from `local_schema_v34.dart`, executes it
  in a real in-memory SQLite database, and asserts via `PRAGMA table_info`
  that the column set is exactly the v37 set *including* `execution_source`.
- **The v37 *upgrade* path has NOT been run against a real SQLite file.**
  `NOT VERIFIED`. The column-probe-guarded `ALTER TABLE sync_attempts ADD
  COLUMN execution_source` inside `onUpgrade` is the specific statement no
  test executes.
  **Corrected by the A.3 audit:** the original claim was the unqualified
  "The v37 migration has not been run against a real SQLite file", which is
  false for the create path and true only for the upgrade path.
- Nothing about Android. No Kotlin was written, no `MethodChannel` was defined,
  no APK was built.

---

## 11. The future native Android integration contract

When the native layer lands it must satisfy, and nothing here may be assumed to
work until it does:

1. Implement `BackgroundSyncScheduler` over a `MethodChannel`, following the
   three channels that already exist in `MainActivity.kt`
   (`fkss.app/updater`, `/app_lock`, `/device`).
2. `ensureScheduled` MUST map to platform **unique** work keyed by
   `BackgroundSyncRequest.uniqueWorkName`, so ten calls leave one work item.
3. The worker's only job is to call
   `BackgroundSyncCoordinator.execute(SyncExecutionSource.background)`. It must
   not read the outbox, construct requests, or touch credentials.
4. It must never transmit, serialise or inspect an operation payload.
5. Adding `workmanager` requires regenerating `pubspec.lock`, which requires a
   local Flutter install — both currently forbidden. F-19 must be resolved
   first, or the dependency added in an environment that can resolve it.
6. CI compiles no Kotlin and builds no APK, so native code will remain
   **100 % unverified by CI**. Device verification must be reported separately
   as `DEVICE RUNTIME VERIFICATION PENDING`.

---

## 12. Remaining S2 work

1. **Goal A.2** — native Android integration per §11.
2. Run the v37 migration against a real SQLite file.
3. Decide whether the hymn claim should also open an attempt row (pre-existing
   S1 gap, §1).
4. Wire `BackgroundSyncCoordinator` into the app's real trigger points. **This
   increment deliberately did not change any caller**: `runSyncNow` exists and
   is the boundary, but `startAutoSync`/`nudge` still call the drain directly
   via the unchanged foreground path. No user-visible behaviour changed.
5. Carried from the F-20 phase: classify `POST /mezmur/audio-presign`; decide
   on the `grades/save` partial-application window; remaining crash-injection
   points.

---

## Status

```
S2 GOAL A.1 STATUS:
BACKGROUND_SYNC_CORE_READY
NATIVE_ANDROID_INTEGRATION_PENDING
```

Not `READY_FOR_S3`. Goal A is not complete — this is A.1 only.
