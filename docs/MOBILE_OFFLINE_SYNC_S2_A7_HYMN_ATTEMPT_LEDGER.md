# S2 Goal A.7 — Hymn operations join the durable `sync_attempts` ledger

**Status:** CLOSED — hymn production path participates in durable lineage.
**Commit:** `373eba3` (implementation + tests), plus this document's commit.
**Scope:** observability / lineage. **Not** a sync rewrite.

---

## 1. The gap

S1 gave every legacy outbox transmission a durable lineage row: one
`sync_attempts` record per real transmission, opened inside the claim
transaction and closed inside the settlement transaction. S2 Goal A.1 added
`execution_source` to that row so a drain's provenance survives the process.
A.5 made the real `SyncService` testable; A.6 proved the v36→v37 upgrade that
ships the column.

Hymn operations never participated.

```
claimNextHymnOperation({runtimeGeneration, ownerUserId,
                        authorizationVersion, now})      // no source
  └── db.transaction
        ├── activeSessionMatches(...)
        ├── UPDATE ... sync_state = 'blocked_dependency'  (dependency sweep)
        ├── SELECT candidate.* ... ORDER BY candidate.id LIMIT 1
        ├── UPDATE ... sync_state = 'in_flight', attempt_count + 1
        ├── if (affected != 1) throw StateError(...)
        └── return HymnOutboxClaim(...)                   // no ledger write
```

Every shared-hymn transmission therefore executed **invisibly** to the
observability A.1/A.5/A.6 built: no execution source, no attempt row, no
attempt number, no owner lineage, no success/failure history. A Sync Center
or any later fleet view would under-report by exactly the hymn traffic.

## 2. What was deliberately **not** done

The brief's largest risk was copying `claimNextLegacyOperation` into the hymn
path. That was rejected after reading both:

| Legacy path | Hymn path |
|---|---|
| claim predicate includes `owner_user_id = ?` | shared; no owner predicate |
| one row per natural key, snapshot of N rows | one row, `entity_key` coalescing |
| `LegacyClaimSnapshot` | `HymnOutboxClaim` |
| settle throws if `affected != matching` | settle returns `supersededLocal` |
| `spec.table` as `domain` | `pending_hymn_ops` |

They are different enough that a copy would have changed behaviour. What the
two legitimately share is the **helper pair** `_openSyncAttempt` /
`_closeSyncAttempt` and the **placement rule** (open in the claim
transaction, close in the settlement transaction). Only those were reused.

Also not done: no new table, no new counter, no second enum or string
convention, no new execution abstraction, no schema version change, no
migration, no new dependency, no pubspec edit, no `HymnStore` redesign, no
retry/lease redesign, no native scheduling.

## 3. The integration point

The smallest point at which "this operation is definitely claimed" is already
true **and** the transaction is still open is the statement immediately after
the `affected != 1` check. That is where the attempt is opened.

```
claimNextHymnOperation({..., SyncExecutionSource executionSource = foreground})
  final hasAttemptLedger = await _tableExists(db, 'sync_attempts');
  └── db.transaction                      // ← unchanged transaction
        ├── activeSessionMatches(...)     // ← unchanged
        ├── dependency sweep              // ← unchanged
        ├── candidate SELECT              // ← unchanged
        ├── atomic in_flight UPDATE       // ← unchanged
        ├── if (affected != 1) throw StateError(...)   // ← unchanged
        ├── if (hasAttemptLedger && clientOpId.isNotEmpty)
        │       await _openSyncAttempt(txn, ...)        // ← NEW
        └── return HymnOutboxClaim(...)   // ← unchanged values

settleHymnOperation({..., SyncAttemptClosure? attemptClosure})
  └── db.transaction                      // ← unchanged transaction
        ├── activeSessionMatches(...)     // ← unchanged
        ├── UPDATE pending_hymn_ops ...   // ← unchanged
        ├── if (updated == 1 && attemptClosure != null && hasAttemptLedger
        │       && claim.clientOpId.isNotEmpty)
        │       await _closeSyncAttempt(txn, ...)       // ← NEW
        └── return updated                // ← unchanged
```

Nothing above the new lines moved. The returned `HymnOutboxClaim` carries the
same values it always did — `attemptCount` was *already* the post-increment
attempt number and `clientOpId` was *already* carried, which is why no new
identity had to be invented.

## 4. Execution-source propagation

```
SyncService._drain(generation, force, source)     ← authoritative boundary
   └── hymnStore.pushPending(source: source)
         └── _db.claimNextHymnOperation(..., executionSource: source)
               └── _openSyncAttempt(..., executionSource: executionSource)
                     └── 'execution_source': executionSource.storageValue
```

`_drain` already owned `source` for the legacy kinds; it now hands the same
value to the hymn push. The source is **decided once, at the boundary**, and
never inferred from a caller's name. `HymnStore`'s other eleven internal
`pushPending()` callers (save, delete, reorder, retry, …) take the
`foreground` default, which is factually correct: they only run while the app
is running.

Only the existing `SyncExecutionSource` is used. `background` is reachable
today — any caller that drives `_drain` with it produces `background` hymn
attempts — but **no production caller emits it yet**, because the scheduler
is still `NoopBackgroundSyncScheduler`. That remains S3 blocker (1) and is
unchanged by A.7.

## 5. Lineage recorded

| Column | Value | Why |
|---|---|---|
| `domain` | `pending_hymn_ops` | the table that owns the operation, matching legacy's `spec.table` |
| `client_op_id` | the row's own `client_op_id` | same key the server stores as `api_idempotency_records.idem_key` |
| `attempt_number` | the row's **post-increment** `attempt_count` | mirrors legacy exactly, so the two can never silently disagree |
| `attempt_uid` | `newAttemptUid()` | one correlation id per transmission |
| `execution_source` | the drain's source | A.1's durable column |
| `entity_ref` | `{op, entity_key}` | natural key only — **never** payload |
| `owner_user_id` | the operation's `created_by_user_id` | see below |
| `created_authorization_version` | the operation's own value | see below |
| `started_at` | the claim instant | identical to the row's `last_attempt_at` |
| `retry_decision` | `PENDING` until settled | |

**Owner lineage — a real difference from legacy.** The legacy claim predicate
contains `owner_user_id = ?`, so the row's owner and the draining session are
necessarily the same and the distinction never arose. Hymn operations are
*shared*: the session draining one may not be the session that created it.
The value recorded is therefore the **operation's own** creator and
authorization version, because that is what legacy's value actually means —
the claimed row's owner — not "whoever happened to drain it". Rows written
before those columns carried a value fall back to the claiming session.

**Rows with no `client_op_id`.** `sync_attempts.client_op_id` is `NOT NULL`
and is the identity. A hymn row predating that column has no transmission
identity, so it claims exactly as before and stays unledgered. Inventing a key
would be fabricated lineage. This is a *known, bounded* limitation, not a
silent one — it is asserted by a test.

## 6. Atomicity

| Hazard | Prevented by |
|---|---|
| a claimed operation with no attempt row | the write is inside the claim transaction |
| an attempt row for an operation that was not claimed | the write is *after* the `affected != 1` throw; the throw rolls the transaction back |
| duplicate rows for a retried/replayed claim | `ConflictAlgorithm.ignore` against `uq_sync_attempt_identity(client_op_id, attempt_number)` |
| a later success rewriting an earlier failure | `_closeSyncAttempt` matches only `finished_at IS NULL` |
| a settlement that did not apply closing an attempt | the close is guarded by `updated == 1` |

No existing idempotency or lease guarantee was weakened: the claim's
`WHERE id = ? AND synced = 0 AND sync_state = ?` and the settlement's
`AND client_op_id = ? AND last_attempt_at = ?` are untouched.

## 7. Verification boundary — carried forward verbatim from A.6

| Phrase | True for A.7? |
|---|---|
| **real SQLite database execution** | **YES** — a real `sqlite3` engine runs production's `pending_hymn_ops` CREATE, the shipped ledger DDL and its 5 indexes, the hymn dependency sweep, the candidate SELECT and the atomic claim UPDATE, all extracted from source at test time |
| **actual sqflite platform-runtime execution** | **NO**, and never claimed — sqflite needs a platform binding; `sqflite_common_ffi` is absent and forbidden by F-19 |
| **actual production migration executed** | **NO** — A.7 changes no migration and no schema version |

A.6 proved that *a mirrored harness cannot catch a mutation of the thing it
mirrors*: reproducing control flow in Python leaves that control flow
unprotected. A.7 therefore pairs the runtime harness with
`HymnLedgerSourceContract`, 25 pins over the production Dart — call placement
relative to the atomicity throw, the transaction executor argument, the
domain string, the attempt-number expression, the owner-lineage expression,
the payload-free `entityRef`, the `updated == 1` close guard, the
`pushPending`/`_drain` threading, and the absence of any second convention.
Mutations M42–M60 show each half catching what the other cannot.

## 8. Numbers

| Metric | Result |
|---|---|
| New suite `test_mobile_hymn_attempt_ledger.py` | **75 tests, 75 passed** |
| Full Python suite | **2188 passed / 926 subtests / 0 skipped** (2113 → 2188, **+75**) |
| Mutation harness | **60 attempted / 60 caught / 0 survived / 0 skipped** |
| Anchor uniqueness gate | **60 / 60 unique** |
| F-20 idempotency E2E | **67 / 67** |
| Production files changed | 3 (`local_db.dart`, `hymn_store.dart`, `sync_service.dart`) |
| Schema version changed | **NO** (`37`) |
| Migrations added | **0** |
| `pubspec.yaml` / `pubspec.lock` changed | **NO** |
| Native Android files changed | **NO** |

## 9. Three existing assertions tightened — none weakened

1. **`test_mobile_background_sync_core.py`** asserted the substring
   `executionSource: executionSource`. A second ledger call site made that
   satisfiable by either site alone, and mutation **M2** ("drop the source
   before the legacy ledger write") regressed from CAUGHT to **SURVIVED**.
   It now requires exactly two occurrences. *This is the A.7 analogue of
   A.3's M-1 finding, and the reason the harness re-runs every mutation
   rather than only the new ones.*
2. **`test_mobile_session_coordinator.py`**, **`test_mezmur_phase5.py`** and
   **`test_mobile_outbox_race_safe.py`** pinned the literal
   `pushPending()` call shape. They now pin `pushPending(source: source)` /
   the new signature — strictly stronger, same intent.

No test was deleted, skipped or relaxed.

## 10. Documentation correction (required by the brief)

`MOBILE_OFFLINE_SYNC_S2_A3_READINESS_AUDIT.md` labelled four rows
`VERIFIED BY REAL SQLITE RUNTIME`. The underlying claims were and remain
true, but the label collapsed two different things. §10 of that document now
carries an explicit correction note separating **real SQLite database
execution** (true) from **actual sqflite platform-runtime execution**
(false), and the four rows are relabelled. The same phrase in the A.1 and A.2
documents is corrected in place with a pointer. Two rows that later work
genuinely moved (`v36→v37 upgrade`, `execution_source` round-trip) are marked
as dated amendments naming A.6/A.7 rather than silently flipped. Nothing was
withdrawn and no history was rewritten.

## 11. Still open after A.7

1. **No native background producer.** The scheduler is still
   `NoopBackgroundSyncScheduler`, so nothing in production emits
   `SyncExecutionSource.background`. The hymn path is now *capable* of
   recording it; no caller does. **S3 blocker, unchanged by A.7.**
2. **The Dart `onUpgrade` and `LocalDb` methods are still never executed by
   any test.** F-19-blocked; narrow and recorded since A.6.
3. **Hymn rows with no `client_op_id` stay unledgered** (§5). Bounded and
   asserted.
4. **B-2 hymn execution-path bypass** (`MezmurDownloadManager.instance
   .syncPins()` swallowing) — pre-existing, out of A.7's scope, untouched.
5. **Dart per-test counts remain unverifiable** — GitHub returns 403 for raw
   job logs; CI pass/fail is the only Dart gate.

## 12. Recommended next phase

**A.8 / S3-entry — the background producer.** Every durable consumer of
`SyncExecutionSource.background` now exists and is proven: the column (A.1),
the coordinator and boundary (A.2/A.4), the testable service (A.5), the
upgrade that ships the column to installed devices (A.6), and — as of A.7 —
both the legacy *and* hymn claim paths writing it. The single remaining
blocker to end-to-end background sync is that nothing produces the value.
That work is native-adjacent and was explicitly out of scope for all of S2
Goal A; it should be scoped on its own, with the `workmanager` package still
forbidden and F-19's pubspec freeze reconsidered explicitly rather than
incidentally.
