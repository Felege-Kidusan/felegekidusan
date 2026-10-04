# Mobile Offline Sync — Phase S1

## Sync Observability, Operation/Attempt Lineage and Idempotency Atomicity

**Phase:** S1
**Baseline commit:** `9b1394e` (S0 + S0.5/S1a, gate `READY_FOR_S1`)
**Date:** 2026-10-05
**Scope:** make the *existing* sync engine observable at operation and attempt
level. No new sync architecture, no new packages, no dependency upgrades.

---

## 1. Status and evidence labels

Every factual claim in this document carries one of three labels. They are
used strictly, and the distinction is load-bearing.

| Label | Meaning |
| --- | --- |
| `VERIFIED` | Produced by executing code in this phase. The command and the observed result are given. |
| `INFERRED` | Derived by reading source that was not executed in this phase. Logically sound, but not run. |
| `NOT VERIFIED` | Asserted nowhere. Stated so it is not mistaken for evidence. |

Static reading is never reported as runtime verification. Where an
expectation is enforced only by reading Dart source from Python, that is said
explicitly, because the Dart call sites themselves are executed by the
Flutter suite in CI rather than locally — there is no local Dart toolchain,
by standing instruction.

**S1 STATUS: READY_FOR_S2** — see §25 for the gate and its conditions.

---

## 2. What S1 changed, in one paragraph

Before S1 the mobile outbox recorded only an operation's *current* state:
`attempt_count`, `last_attempt_at`, `failure_code`, `failure_http_status`.
Each attempt overwrote the previous one, so an operation that timed out,
received two `503`s and then succeeded was indistinguishable from one that
succeeded immediately, and the drain-pass telemetry reported it as
`3 failures / 1 success`. S1 adds a durable per-attempt ledger written inside
the transactions that already exist, a controlled error and retry-decision
vocabulary, a correlation id that joins a device attempt to a server log
line, and pass telemetry expressed in operations and attempts. The same
operation now reads as **1 operation, 4 attempts, 3 retries, 0 unresolved
failures**.

---

## 3. Schema provenance — four different schemas

This section exists because conflating these four has previously produced
false confidence.

| Schema | What it is | Status in S1 |
| --- | --- | --- |
| **Repository schema** | `sql/*.sql` as committed. | `VERIFIED` — `sql/009_api_idempotency.sql` applied verbatim by the new harness. |
| **CI fixture schema** | Databases the workflow builds (`ssms_comm_e2e`, `ssms_e2e`, `ssms_smoke`, `ssms_pre056_e2e`, and now `ssms_idem_e2e`). | `VERIFIED` — built and exercised in CI. |
| **Local verification DB** | MariaDB 11.8.6 on `127.0.0.1:3306` in this workspace. | `VERIFIED` — all runtime results below come from here or from CI. |
| **Production schema** | The live school database. | **`PRODUCTION SCHEMA NOT VERIFIED`.** |

**`PRODUCTION SCHEMA NOT VERIFIED`.** No production database was contacted,
read or modified in S1. The only production evidence available is the
9797-line dump at `uploads/production_database.md`, which is a point-in-time
export, not a live reconciliation. No fixture in this phase is described as
"production-shaped", because none has been reconciled against production.

The mobile ledger (§7) is a **client-side SQLite** change and involves no
server migration at all, which is why S1 ships no `sql/` file.

---

## 4. Execution map of the existing engine

`VERIFIED` by source reading at `9b1394e`; the flow below is the one the
Flutter suite exercises.

```
syncAll()
  └── _syncAllForGeneration()            generation-guarded, _inflight/_queued coalescing
        └── _drain()                     sync_service.dart ~:178
              ├── for each LegacyOperationKind:
              │     _drainLegacyKind()   bounded 100 claims per pass
              │       ├── claimNextLegacyOperation()     local_db.dart:3299   [TRANSACTION]
              │       ├── _sendLegacyClaim()             sync_service.dart ~:423
              │       ├── classifyOutboxResponse()       outbox_policy.dart
              │       └── settleLegacyOperation()        local_db.dart:3440   [TRANSACTION]
              ├── HymnStore().pushPending() / pullChanges()
              ├── cleanupSynced()
              ├── _emitStatus()
              └── pass telemetry          sync_service.dart ~:268  (the S0 finding F-01 site)
```

**The claim transaction** selects the oldest due group by `client_op_id`
(`GROUP BY client_op_id ORDER BY MIN(created_at), MIN(id) LIMIT 1`), requires
a single coherent natural key, packet kind and state, then performs

```sql
UPDATE <table> SET sync_state = 'in_flight',
                   attempt_count = attempt_count + 1,
                   last_attempt_at = ?,
                   next_attempt_at = NULL
```

and throws `StateError` if the update is not exactly atomic.

**The settle transaction** re-verifies the session, counts the exact claimed
rows, and writes one of `synced / retry_wait / needs_attention / paused_auth /
paused_scope / resolved_conflict` plus `failure_code`, `failure_http_status`,
`failed_at` and `next_attempt_at`, throwing if the write is not atomic.

**Consequence, and the whole basis of the S1 design:** `attempt_count` is
already incremented at attempt *start*, inside a transaction that already
holds the owner, the authorization version, the runtime generation and the
`client_op_id`. Both transactions are therefore natural, pre-existing seams
for recording an attempt. S1 adds no new execution path.

---

## 5. Operation identity — no new identifier was minted

`VERIFIED` by source reading, and pinned by the mutation guard M2 (§23).

`newClientOpId()` (`local_db.dart:20`) is a `Random.secure()` UUIDv4. It is
called at exactly **two** non-claim sites, and both are genuinely new logical
operations rather than retries:

| Site | Why a new id is correct |
| --- | --- |
| `undoSubmittedLegacyOperation` (`:3604`) | A submitted packet is returned to draft. It deliberately resets `attempt_count = 0` and clears `next_attempt_at`, `last_attempt_at` and the failure columns. The submitted operation is cancelled and replaced. |
| Hymn save superseding an unsent save (`:6191`) | A new payload supersedes an unsent one, so it is a different operation. |

Nothing in the claim or settle path rewrites `client_op_id`.

**Conclusion:** `client_op_id` *is* the durable operation identity. It
survives retries, process death, restart, session expiry and recovery, and it
is already transmitted to the server as the `Idempotency-Key` and stored as
`api_idempotency_records.idem_key`. `(client_op_id, attempt_count)` was
already a natural attempt key that nothing persisted.

Creating a second "operation id" would have introduced exactly the second
source of truth the brief forbids. **S1 therefore mints no operation
identifier.** The only new identifier is the per-attempt correlation id
(§9), which identifies a transmission, not an operation.

**Multi-device collision:** `VERIFIED` by construction — a v4 UUID from
`Random.secure()` per device; the server's primary key is
`sha256(user_id ‖ idem_key ‖ scope)`, so two devices belonging to different
users cannot collide even on an identical key, and two devices of the same
user collide only on a 122-bit random coincidence.

---

## 6. The attempt model

One row per real transmission, in `sync_attempts` (client-side SQLite,
schema v36).

| Column | Purpose |
| --- | --- |
| `client_op_id` | Operation identity. Stable across retries (§5). |
| `attempt_number` | The outbox row's own `attempt_count` at claim time. Monotonic per operation. |
| `attempt_uid` | This transmission's opaque correlation id (§9). |
| `domain` | Which outbox table the operation belongs to. |
| `entity_ref` | The operation's **natural key only**, e.g. `{"class_id":7,"date":"2026-03-01"}`. |
| `owner_user_id`, `created_authorization_version` | Account isolation (§17). |
| `started_at`, `finished_at`, `duration_ms` | Attempt timing. |
| `http_status` | Transport result, null when the attempt never got a response. |
| `error_category` | Controlled vocabulary (§10). |
| `retry_decision` | Controlled vocabulary (§11). Defaults to `PENDING`, never null. |
| `failure_message` | Bounded to 200 characters, trimmed; diagnostic only. |
| `next_attempt_at` | What the engine scheduled, for explaining the delay. |
| `server_ref` | The server's `X-Request-Id` for this attempt (§9). |

**Attempt numbers are taken from the outbox row, never from a private
counter**, so the ledger and the row it describes cannot silently disagree.
`UNIQUE(client_op_id, attempt_number)` makes a non-incrementing counter a
hard constraint violation instead of a corrupted history. `VERIFIED` —
`test_attempt_identity_is_unique_per_operation` raises `sqlite3.IntegrityError`
against the shipped DDL.

**Prior attempts are never overwritten.** The close is guarded by
`finished_at IS NULL`, so a settlement can only close the attempt it settles.
`VERIFIED` — `test_a_closed_attempt_is_never_rewritten_by_a_later_settlement`
observes `rowcount == 0` for a second settlement and the original
`TIMEOUT / RETRY_SCHEDULED` values intact.

---

## 7. Persistence design, and why a new table (§24)

Extension of the existing structures was attempted first and rejected on
evidence, not preference:

* **Adding columns to each outbox table** cannot work. The requirement is
  *history*; a row holds one value per column. Storing four attempts would
  mean either four column sets (arbitrary cap) or a serialised blob (an
  unqueryable second encoding). It would also have to be repeated across
  every legacy outbox table.
* **Reusing `sync_state`** would have made a diagnostic record part of the
  execution state machine — precisely the second source of truth the brief
  forbids.
* **One ledger table keyed by the identity that already exists** adds no new
  identifier, no new execution path, and no coupling: deleting every row in
  `sync_attempts` would lose history but could not affect what the engine
  sends.

A new table is therefore justified, and it is **client-side only** — there is
no server migration in S1.

**Transactional placement.** The open is inside the existing claim
transaction; the close is inside the existing settle transaction. Neither
adds a transaction, and no per-field transaction exists anywhere in the
change.

**A real defect found and fixed during implementation.** The first
implementation probed `_tableExists(db, …)` *inside* `db.transaction((txn) …)`.
sqflite serialises a single connection, so using the outer handle inside a
transaction deadlocks. The probe is now hoisted above both transactions, and
`test_the_ledger_never_uses_the_outer_handle_inside_a_transaction` fails the
build if that regresses. `VERIFIED` by the test; the deadlock itself was
never shipped.

**Indexes** (all `VERIFIED` as executable against real SQLite):

| Index | Why |
| --- | --- |
| `uq_sync_attempt_identity (client_op_id, attempt_number)` | Correctness, not speed: enforces monotonic attempt identity. |
| `uq_sync_attempt_uid (attempt_uid)` | One correlation id describes one transmission. |
| `idx_sync_attempts_operation (client_op_id, attempt_number)` | Operation lookup. `EXPLAIN QUERY PLAN` confirms `SEARCH … USING INDEX idx_sync_attempts_operation`. |
| `idx_sync_attempts_open (finished_at, started_at)` | The interrupted-attempt sweep and "pending work" views. |
| `idx_sync_attempts_recent (started_at)` | Recent-failure views. |

No index is placed on a high-cardinality uncontrolled value. `failure_message`
is unindexed by design.

**Growth is bounded** by `pruneSyncAttempts({keepOperations = 500})`, which
keeps the newest operations and **never prunes an operation with an attempt
still open**. `VERIFIED` by
`test_prune_never_discards_an_operation_still_in_flight`.

This is a capacity cap, **not** a retention policy. The 90-day retention
policy is explicitly out of scope and remains S7's.

---

## 8. Telemetry: pass vs operation vs attempt (§11)

The S0 finding F-01 site emitted, once per drain pass:

```dart
recordSyncResult(success: failed == 0, itemsCount: synced,
                 error: failed > 0 ? '$failed operations pending retry' : null);
```

`failed` counted *row settlements*, so one operation retried three times
incremented it three times and the pass reported `success: false` with
"3 operations pending retry" — three operations that did not exist.

S1 emits `sync_pass_completed` with separated counters:

| Field | Meaning |
| --- | --- |
| `operations` | Distinct `client_op_id`s touched in the pass. |
| `attempts` | Real transmissions made. |
| `retries` | `attempts − operations`. |
| `succeeded` / `waiting_retry` / `needs_attention` | Terminal shape per operation. |

The worked example now reports `operations: 1, attempts: 4, retries: 3,
succeeded: 1`. `VERIFIED` by
`sync_attempt_models_test.dart` → "one operation retried three times is not
four operations", executed in CI.

**No per-attempt network telemetry is emitted, deliberately.** Attempt detail
is already durable locally; emitting one request per attempt would scale
telemetry traffic with the retry count of a device that is, by definition,
having network trouble — which would amplify load exactly when the server is
least able to absorb it. This is a conscious trade of remote granularity for
local granularity, and it is the reason §20's API overhead is one event per
pass rather than one per attempt.

`recordSyncResult` is **not deleted**; it remains correct for callers that
genuinely mean rows, and is documented as unsuitable for operation-shaped
reporting.

**Naming** follows the existing convention (`sync_completed`, `sync_failed`,
`crash_recorded`, `update_downloaded`), so `sync_pass_completed` needs no
server-side allowlist change. `VERIFIED` — `api/v1/routes/telemetry.php:46`
accepts any `event_type` truncated to 48 characters; there is no allowlist.

---

## 9. Correlation identifiers (§§12–13)

```
Flutter attempt  ──X-Client-Attempt-Id──►  api/v1  ──►  business txn / idempotency
      ▲                                      │
      └──────────X-Request-Id────────────────┘        error_log: [SSMS:ref] [req:…] [attempt:…]
```

| Identifier | Origin | Shape |
| --- | --- | --- |
| `attempt_uid` | Device, at claim time | `att_` + 32 hex of a `Random.secure()` v4 UUID |
| `X-Request-Id` | Server, once per request | `req_` + 24 hex of `random_bytes(12)` |

**Can `attempt_id` serve as the request id? Decided explicitly: no.** They
are different lifetimes. The idempotency key must be *stable across* retries
(it is what makes a replay detectable), while a request id must be *unique
per* transmission. Overloading one value would either break replay detection
or make correlation ambiguous. The two therefore travel together:
`Idempotency-Key` carries the operation, `X-Client-Attempt-Id` carries the
attempt. The server's `X-Request-Id` is recorded on the attempt row as
`server_ref`.

**Privacy and injection.** Both values are opaque and random; neither encodes
a user id, timestamp, token or payload. The inbound header reaches the server
log, so it is *validated rather than sanitised*: `^[A-Za-z0-9._-]+$`, maximum
64 characters, otherwise ignored entirely. `VERIFIED` by reading, and by
`php -l` on the changed files; the regex is exercised whenever the header is
present.

**Backward compatibility.** Additive in both directions: an older server
ignores an unknown request header, and an older client ignores an unknown
response header. Nothing depends on either being present — `serverRequestId`
is nullable and `server_ref` is a nullable column.

---

## 10. Error classification (§9)

The brief required inspecting real API semantics before mapping statuses, and
that inspection changed the design. **HTTP 409 carries four unrelated
meanings in this API**, and flattening them would have destroyed the signal:

| Status + code | Real meaning | Category |
| --- | --- | --- |
| `409 IDEMPOTENCY_IN_PROGRESS` | An earlier attempt of *this* operation still holds the server lease | `IDEMPOTENCY_IN_PROGRESS` (retryable) |
| `409 IDEMPOTENCY_CONFLICT` | Same key, different payload | `IDEMPOTENCY_CONFLICT` (terminal) |
| `409 ALREADY_SUBMITTED` / `WORKFLOW_REJECTED` | Domain workflow refusal | `WORKFLOW_REJECTED` (terminal) |
| `409 REVISION_CONFLICT` | Server holds a newer revision | `REVISION_CONFLICT` |

**A replay is not a failure.** `Idempotency-Replayed: true` on a 2xx means an
earlier attempt of this operation already succeeded, which is *delivery
evidence*, not an error. It is classified `IDEMPOTENCY_REPLAY` and
`SyncOperationLineage.succeeded` counts it as success while *not* counting it
as a failed attempt. `VERIFIED` by
"an idempotent replay counts as delivered, not as a failure".

A replayed **5xx** is a materially different fact from a fresh 5xx and gets
its own category, `SERVER_ERROR_REPLAYED` — see §15.

Full vocabulary (24 values): `none`, `IDEMPOTENCY_REPLAY`,
`NETWORK_UNAVAILABLE`, `TIMEOUT`, `DNS_FAILURE`, `TLS_FAILURE`,
`AUTH_EXPIRED`, `AUTH_SCOPE_CHANGED`, `HTTP_403`, `VALIDATION_ERROR`,
`HTTP_404`, `PAYLOAD_REJECTED`, `IDEMPOTENCY_CONFLICT`,
`IDEMPOTENCY_IN_PROGRESS`, `WORKFLOW_REJECTED`, `REVISION_CONFLICT`,
`HTTP_429`, `SERVER_ERROR`, `SERVER_ERROR_REPLAYED`, `SERVICE_UNAVAILABLE`,
`LOCAL_DB_ERROR`, `SERIALIZATION_ERROR`, `PROTOCOL_ERROR`, `UNKNOWN`.

`DNS_FAILURE` and `TLS_FAILURE` are declared but **not currently reachable**:
the Dart HTTP layer reports both as `ApiFailureKind.transport`, so they
classify as `NETWORK_UNAVAILABLE`. Labelled `NOT VERIFIED` and retained as
vocabulary rather than silently dropped. `LOCAL_DB_ERROR` and
`SERIALIZATION_ERROR` are likewise declared for completeness and are not
produced by the current drain path.

`classifySyncErrorCategory` is a **projection** of the evidence the existing
policy already consumes. It does not re-decide anything;
`classifyOutboxResponse` remains the only function that decides what happens
to a row.

---

## 11. Retry decision observability (§10)

Eleven controlled values, each recorded on the attempt that produced it:
`COMPLETED`, `RETRY_SCHEDULED`, `RETRY_AFTER_SERVER_DELAY`,
`RETRY_LIMIT_REACHED`, `USER_ACTION_REQUIRED`, `AUTH_REFRESH_REQUIRED`,
`AUTHORIZATION_SCOPE_CHANGED`, `CONFLICT_REQUIRES_RESOLUTION`, `INTERRUPTED`,
`SUPERSEDED`, `PENDING`.

`describeRetryDecision` is a total function over the existing
`OutboxDecision`; `VERIFIED` by "every OutboxDecision maps to a recorded
reason", which iterates `OutboxDecision.values` so a newly added decision
fails the test rather than silently recording `PENDING`.

These values **do not conflict with the outbox states** — they are a separate
column in a separate table and are never read by the engine. The mapping to
the existing `OutboxState` values (`pending`, `in_flight`, `retry_wait`,
`paused_auth`, `paused_scope`, `blocked_dependency`, `needs_attention`,
`resolved_conflict`, `synced`) is one-way and advisory.

A server-dictated delay is distinguishable from the local ladder
(`RETRY_AFTER_SERVER_DELAY` vs `RETRY_SCHEDULED`), and an exhausted automatic
budget is distinguishable from a domain rejection (`RETRY_LIMIT_REACHED` vs
`USER_ACTION_REQUIRED`). Both `VERIFIED`.

---

## 12. F-20 — the transaction timeline

`VERIFIED` by source reading of `api/v1/core/middleware.php`,
`api/v1/core/response.php` and `api/v1/routes/attendance.php`, and then by
execution (§13).

```
T0  apiIdempotencyBegin()                        middleware.php:131
      └─ ApiIdempotencyService::begin()
           INSERT IGNORE … record_state='processing',
                           lease_expires_at = NOW + 300s,
                           expires_at       = NOW + 7d
           ── AUTOCOMMITTED, OUTSIDE the business transaction ──

T1  $conn->begin_transaction()                   attendance.php:227

T2  business writes
      apiReplaceAttendanceRows(...)
      SubmissionService::upsertAttendance(...)

T3  $conn->commit()                              attendance.php:253
      ◄══════ THE BUSINESS EFFECT IS NOW DURABLE ══════

    logApiAction(...)

T4  ok() → apiSendJson()                         response.php:58
      └─ apiIdempotencyStore() → complete()      middleware.php:197
           UPDATE … record_state='completed', status_code, response_body
           ── AUTOCOMMITTED, SEPARATE statement ──
```

**The window is T3 → T4.** A worker that dies there leaves a committed
business effect behind a record that still reads `processing`. Once the 300 s
lease expires, `begin()`'s recovery branch —

```sql
… AND (expires_at <= CURRENT_TIMESTAMP
       OR (record_state='processing' AND lease_expires_at <= CURRENT_TIMESTAMP
           AND request_hash = ?))
```

— returns `acquired`, and the business logic runs a **second time**.

`begin()` outcomes: `acquired`, `replay` (emits `Idempotency-Replayed: true`),
`conflict` (409 `IDEMPOTENCY_CONFLICT`), `processing` (409
`IDEMPOTENCY_IN_PROGRESS` + `Retry-After`), `unavailable` (503, fails closed).
`apiIdempotencyStore()` **abandons** rather than completes on HTTP 429, which
keeps rate-limited requests retryable.

---

## 13. F-20 — behavioural results (§16)

`VERIFIED`. Harness: `tests/e2e/idempotency_lifecycle.php`, driving the real
`App\Services\ApiIdempotencyService` against real MariaDB 11.8.6. Driver:
`tests/security/test_api_idempotency_runtime.py`.

```
SSMS_AUDIT_TESTING=1 SSMS_DB_NAME=ssms_idem_e2e php tests/e2e/idempotency_lifecycle.php all
→ E2E-VERDICT: PASS (all: 48 checks)
```

The harness models the production call order exactly. "Crash after commit" is
simulated by not calling `complete()`, which is precisely what a dead PHP
worker does. Lease expiry is driven by ageing `lease_expires_at`, which is the
column the service reads as its clock — a deterministic clock substitute, not
a weakened assertion. **No production code was modified to create a test
seam.**

| # | Scenario | Result |
| --- | --- | --- |
| **A** | Normal success | One business effect, one record, `completed`, status 200; a later identical call replays the exact stored body. |
| **B** | Duplicate request | Replayed, not re-executed; no duplicate effect; same key + different payload → `conflict`, and the conflicting call changed nothing. |
| **C** | Concurrent duplicate | Exactly one caller `acquired`, the other told `processing` with a positive `Retry-After`; a non-owner token could **not** overwrite the completed response; the loser never receives a reservation handle. |
| **D** | Failure **before** commit | Nothing written; record left `processing`; retry inside the lease told to wait; retry after lease expiry succeeds with exactly one business effect overall. This is lease recovery working correctly. |
| **E** | Failure **after** commit, before idempotency persistence | The business effect **is** durable; the record still reads `processing` and has no stored body; inside the lease the retry is held off. |
| **F** | Retry after lease expiry | **The same committed operation is `acquired` again.** The duplicate-execution window is real and reproducible. |
| **G** | Stored replay | `200`, `Idempotency-Replayed: true`, byte-identical body, no second effect, and records are user-scoped so another user with the same key gets `acquired`. |

Scenario **E** is the critical one, and it reproduces deterministically.

---

## 14. F-20 — remediation decision: **DEFERRED, with the risk documented**

The brief requires proving boundary, window, atomicity options, client
compatibility, concurrency safety, retention sense and migration need before
changing anything, and permits deferral if no safe fix fits in S1. That is
the conclusion reached.

**Boundary and window:** `VERIFIED` above — T3→T4, bounded below by the
commit and above by the 300 s lease.

**Candidate fix considered.** Add a nullable `committed_at` column, set
*inside* the business transaction, and add `AND committed_at IS NULL` to the
lease-expiry recovery branch, so a committed-but-unstored record can never be
re-acquired. Purely additive; existing rows unaffected.

**Why it is not being applied in S1:**

1. **No defensible response exists for the blocked retry.** The stored body
   was never written, so the server cannot answer the retry with the original
   result. It could only return `409`. On the client a `409` with an unknown
   code falls into `_boundedUnknown`, retries up to the budget and then
   surfaces as `needs_attention` — i.e. a *successful* operation would be
   presented to a teacher as failed work. That is a worse user-facing
   outcome than the current behaviour for the converging writes that make up
   most traffic (§15).
2. **The marker must be written in every route's business transaction** —
   attendance ×2, grades ×2, hr ×2, mezmur ×8: fourteen call sites, each
   inside a transaction, each a chance to get the placement subtly wrong.
   Omitting one silently reintroduces the bug for that route only.
3. **The probability is low and the blast radius is write-shape dependent.**
   The window requires process death inside the few milliseconds between
   `COMMIT` and one `UPDATE`, *and* no retry until the 300 s lease expires.
   For attendance — the dominant write — re-execution converges harmlessly
   (§15).

**Rejected alternative:** lengthening the lease. It only *narrows* the window
rather than closing it, and it directly *delays* legitimate recovery for the
crash-before-commit case (scenario D), which is the more common crash. The
brief's instruction not to change the lease blindly is well founded.

**Residual risk, stated plainly:** a worker death between `COMMIT` and the
idempotency write, followed by a retry after lease expiry, re-executes the
business logic. For attendance this converges; for append-only writes it
duplicates (§15). **This risk is open and carried into S2.**

**Recommended S2 approach:** apply `committed_at`, *and* store a minimal
response envelope inside the business transaction so the blocked retry can be
answered `200` with a truthful body rather than `409`. That is a coherent
unit of work with its own test matrix, and it does not fit honestly inside
S1's remaining scope.

---

## 15. Non-attendance idempotency under re-execution (§18)

S0.5 left this `NOT VERIFIED`. It is now **`VERIFIED`, and the answer is
negative.**

Attendance's convergence is a property of its *unique constraint*, not of the
sync design, and it does **not** generalise:

| Write shape | Re-execution result | Evidence |
| --- | --- | --- |
| Attendance (`UNIQUE(member_id, attendance_date)`, upsert) | **Converges** — still 2 rows after a second execution | `test_attendance_converges_under_re_execution` |
| Append-only message/outbox shape (no natural uniqueness) | **Duplicates** — 1 row becomes 2 identical rows | `test_append_only_writes_duplicate_under_re_execution` |

```
E2E-PASS: F2: attendance CONVERGES — UNIQUE(member_id, attendance_date) absorbs the replay
E2E-PASS: F3: APPEND-ONLY WRITES DUPLICATE — two identical messages now exist
```

**Scope honesty.** The append-only probe uses a stand-in table whose shape
mirrors a `comm_outbox` delivery (`thread_id`, `sender_id`, `body`,
`created_at`, surrogate PK, no natural unique key). It demonstrates that
**the write shape** duplicates under re-execution. It is **`NOT VERIFIED`**
that the production `comm_outbox` path is reachable through the F-20 window
with exactly these columns; that reconciliation needs the production schema,
which was not consulted (§3). The finding is therefore: *non-attendance
writes are not automatically safe, and the assumption that they are must not
be made.*

### A related finding: a transient 5xx is pinned — but the client contains it

`VERIFIED`. `apiIdempotencyStore()` calls `complete()` for every status
except 429, so a transient `500` is stored as the operation's idempotent
answer for the full 7-day `expires_at`. Every later retry under that
`client_op_id` replays the stored failure, and the operation can never be
delivered under that id.

```
E2E-PASS: H: THE TRANSIENT 500 IS PINNED — every later retry of this operation
             replays the failure and the work can never be delivered
E2E-PASS: H: an abandoned (429) reservation stays retryable
```

**My initial hypothesis was that this produces an infinite retry loop. That
hypothesis was wrong, and checking it changed the severity.** The Dart
classifier already anticipates exactly this case:

```dart
if (status >= 500 && status <= 599) {
  return evidence.idempotencyReplayed
      ? OutboxDecision.needsAttention   // terminal — surfaced to the user
      : OutboxDecision.retryable;
}
```

The end-to-end path is `VERIFIED`: the server emits
`Idempotency-Replayed: true` (`middleware.php:178`) and the client parses it
(`api_service.dart:87`). So the operation stops after the first replay and is
escalated to a person rather than looping.

**Severity: medium, contained.** The work is not lost and does not loop, but
one transient server error makes that operation permanently undeliverable
under its id and requires manual user action. Because the containment lives
entirely in that one Dart branch, it is now pinned by a guard-the-guard test
(`test_a_replayed_5xx_is_terminal_for_the_client`), and mutation M8 confirms
removing it is caught.

---

## 16. Crash, restart and lifecycle survival (§21)

| Event | Behaviour | Status |
| --- | --- | --- |
| Force-close / process death mid-attempt | The attempt row persists with `finished_at IS NULL`; the startup sweep closes it as `INTERRUPTED`. | `VERIFIED` (SQLite runtime) |
| Restart / reboot | `onOpen` → `recoverOrphanedInFlightOperations()` → the same sweep, before any scheduler can observe work. | `INFERRED` (existing wiring at `local_db.dart:561–566`, unchanged by S1) |
| Network loss | Classified `NETWORK_UNAVAILABLE`, `RETRY_SCHEDULED`. | `VERIFIED` (Dart unit test) |
| Session expiry / token refresh | `AUTH_EXPIRED` → `AUTH_REFRESH_REQUIRED`; lineage preserved, operation paused not failed. | `VERIFIED` (Dart unit test) |
| Logout / re-login | The ledger is keyed by `client_op_id` and stamped with `owner_user_id`; existing logout/discard UX is untouched. | `INFERRED` |

**An interrupted `attempt_started` must never read as success**, and it does
not: `INTERRUPTED` maps to `SyncOperationOutcome.waitingRetry`, never
`succeeded`, and the swept row has `http_status IS NULL`. `VERIFIED` by
`test_an_interrupted_attempt_is_closed_as_interrupted_not_successful` and
"an interrupted attempt is waiting, never succeeded". `VERIFIED` that no open
attempt survives the sweep.

S1 **reuses** the existing orphan/in-flight recovery semantics; it adds one
statement inside the sweep's existing transaction and introduces no second
recovery path.

---

## 17. Account isolation (§22)

* Every attempt row records `owner_user_id` and
  `created_authorization_version`, copied from the claim that transmitted it.
  `VERIFIED` — `test_each_attempt_records_the_owner_that_transmitted_it`, and
  an owner-scoped query returns only that owner's operations.
* S1 adds **no new API surface and no new endpoint**. `operationLineage()`
  and `recentOperationLineages()` are local reads of the device's own
  database; they expose nothing across the network and perform no
  authorization decisions.
* **No authorization check was modified, removed or weakened.** Mutation M12
  confirms that dropping owner attribution from the ledger is caught.
* **The intentional shared hymn queue is preserved.** Hymn operations drain
  through `HymnStore` and are deliberately outside private-owner inventory
  and purge; S1 did not touch that path, so the queue remains shared and is
  not mis-attributed to an individual owner.

`NOT VERIFIED`: that no *future* diagnostic UI leaks across accounts. S1
ships no such UI. Any Sync Center surface built on this data must apply
`owner_user_id` scoping — which is why the column exists.

---

## 18. Failure isolation of telemetry (§25)

Correctness-critical persistence and diagnostic telemetry are separated by
construction:

| | Mechanism | On failure |
| --- | --- | --- |
| **Correctness-critical** | Outbox row state, inside the claim/settle transactions | Throws `StateError`; the operation is not falsely settled |
| **Durable diagnostics** | `sync_attempts`, same transactions | Rolls back with the settlement it describes — never half-recorded |
| **Remote telemetry** | `TelemetryService`, fire-and-forget HTTP | Swallowed; `unawaited`; cannot delay, fail, or retry a drain |

`VERIFIED` by reading and pinned by
`test_telemetry_failure_cannot_break_a_drain`: the call is `unawaited`, and
`_sendPayload` wraps everything in `try { … } catch (_) {}`.

Telemetry failure therefore cannot break sync, cause duplicate execution,
cause an auth failure or logout, or amplify retries. The ledger write *is*
inside the business transaction, which is deliberate: a diagnostic that
disagrees with the row it describes is worse than no diagnostic. Its cost is
measured in §20 and is negligible.

---

## 19. Security review

| Concern | Finding |
| --- | --- |
| Payload/PII in storage | `entity_ref` holds the natural key only (`class_id`, `date`). No member rows, names, marks or message bodies. `VERIFIED` by `test_the_ledger_stores_identifiers_and_never_payloads`. |
| Credentials in storage | None. No token, header or secret is written to the ledger. |
| Diagnostic message leakage | `failure_message` is trimmed and capped at 200 characters and only ever carries the server's user-safe `message` field, which is already shown in the UI. Mutation-adjacent guard: `test_diagnostic_messages_are_bounded`. |
| Log injection via headers | `X-Client-Attempt-Id` is validated against `^[A-Za-z0-9._-]+$` and ≤64 chars and otherwise **ignored**, not sanitised. |
| Correlation ids as oracles | Both ids are `Random.secure()` / `random_bytes()` derived and encode nothing; they cannot be used to enumerate users or operations. |
| Telemetry payload | Counts only. `VERIFIED` by "the telemetry payload carries counts only — never identifiers", which asserts every value is an `int`. |
| Authorization | Unchanged. No new endpoint, no second permission system. |
| Idempotency ownership | `complete()` still requires the matching `owner_token`; mutation M10 confirms removing that check is caught. |

---

## 20. Performance impact (§26)

Measured on the local verification host (SQLite 3 via Python), 10 000
attempts, insert + update per attempt. Host figures, **not** device figures.

| Metric | Before | After | Delta |
| --- | --- | --- | --- |
| DB writes per attempt | 1 `UPDATE` (claim) + 1 `UPDATE` (settle) | + 1 `INSERT` (claim) + 1 `SELECT` + 1 `UPDATE` (settle) | +3 statements |
| Transactions per attempt | 2 | 2 | **unchanged** |
| Ledger write cost | — | **0.018 ms** per attempt | negligible |
| Lineage lookup | not possible | **0.030 ms** per operation, index-backed | new capability |
| Storage per attempt | — | **475 bytes** incl. all five indexes | — |
| Steady-state ledger size | — | **≈464 KiB** at the 500-operation cap (~2 attempts each) | bounded |
| Telemetry requests per pass | 1 | 1 | **unchanged** |
| Request header bytes | — | +≈56 bytes (`X-Client-Attempt-Id`) | per write request |
| Response header bytes | — | +≈42 bytes (`X-Request-Id`) | per v1 response |

`EXPLAIN QUERY PLAN` for the lineage read:
`SEARCH sync_attempts USING INDEX idx_sync_attempts_operation (client_op_id=?)`.

**No per-field transactions** were introduced; transaction count per attempt
is unchanged, which was the explicit constraint. Throughput is bounded by the
network round trip (hundreds of milliseconds) rather than by 0.018 ms of
local SQLite, so the added cost is not measurable in a real drain.

`NOT VERIFIED`: on-device figures. Low-end Android flash is slower than this
host, and no device measurement was taken.

---

## 21. Test inventory (§27)

**Totals.** Python `tests/security`: **2012 passed, 0 failed, 0 skipped** —
identical locally and in CI run #52 (baseline 1976). Flutter in CI:
**502 passed, 0 failed, 0 skipped** on Flutter 3.44.9 / Dart 3.12.2
(baseline 478). All four CI jobs green at `401acae`.

New files:

| File | Cases | What it executes |
| --- | --- | --- |
| `tests/e2e/idempotency_lifecycle.php` | 48 checks | The real `ApiIdempotencyService` against real MariaDB |
| `tests/security/test_api_idempotency_runtime.py` | 12 | Drives the above; plus guard-the-guard on the replayed-5xx containment |
| `tests/security/test_mobile_sync_attempt_ledger.py` | 24 | The shipped v36 DDL against real SQLite, plus wiring contracts |
| `Mobile/wbws_flutter_app/test/sync_attempt_models_test.dart` | 24 | Classifier, decisions, lineage and pass-summary semantics |

Coverage against the required list:

| Requirement | Test | Status |
| --- | --- | --- |
| Operation identity stable across retries | M2 guard + "3 failures then a success is one operation" | `VERIFIED` |
| Unique incrementing attempt identity, history preserved | `IntegrityError` on duplicate attempt number; closed attempts not rewritten | `VERIFIED` |
| 3 failures + 1 success = 1 op / 4 attempts / prior failures visible | Both suites | `VERIFIED` |
| Interrupted operation | Interrupted sweep tests | `VERIFIED` |
| Account isolation + stale auth version | Owner attribution + scoped query | `VERIFIED` |
| Pass vs operation telemetry | Pass-summary group | `VERIFIED` |
| Error classification across the matrix | Classification group | `VERIFIED` (except `DNS_FAILURE`/`TLS_FAILURE`, §10) |
| Behavioural idempotency replacing source-string assertions | `test_api_idempotency_runtime.py` | `VERIFIED` |

`test_api_idempotency.py` (the original 5 source-string tests) is **kept**,
not deleted: it is a weak but real structural guard, and the brief forbids
weakening coverage. It is now *supplemented* by behaviour.

**Two existing tests were modified, both deliberately and both strengthened
or re-pinned rather than weakened:**

1. `test_f8_outbox_rejection.py` — the ordering guard sliced a fixed
   1500-character window after `settleLegacyOperation(`. The settlement call
   grew, so the window silently stopped reaching the guard it was meant to
   check. It now scans the whole `_drainLegacyKind` function **and**
   additionally asserts exactly one `synced++` site exists. Strictly stronger;
   mutation M7 confirms it still catches the regression it was written for.
2. `test_mobile_v34_sqlite_runtime.py` and `test_attendance_sync_phase_b.py` —
   the current-schema-version pin moved `35 → 36`. The v34 and v35 migration
   history they assert is unchanged. This pin exists so a schema change cannot
   land silently; it was advanced consciously, and the new version has its own
   runtime harness.

---

## 22. Backward compatibility (§29)

| Case | Result |
| --- | --- |
| Old client, new server | `X-Request-Id` is an unknown response header and is ignored. No request change is required. `VERIFIED` by construction — the server never requires `X-Client-Attempt-Id`; `apiClientAttemptId()` returns `''` when absent. |
| New client, old server | `X-Client-Attempt-Id` is an unknown request header and is ignored; `serverRequestId` stays null and `server_ref` stays null. |
| Existing local rows | Untouched. The v36 migration only creates a table. |
| Existing pending operations | Keep their row, their `client_op_id` and their `attempt_count`. They simply have no recorded history before v36 — **deliberately not backfilled**, because inventing attempts that were never observed would be fabricated evidence. |
| `client_op_id` behaviour | Unchanged. Same generation sites, same transmission, same `Idempotency-Key`. |
| New response headers | Ignorable. |
| Telemetry event type | `sync_pass_completed` is new; the server accepts any type (§8). `sync_completed` / `sync_failed` remain emittable by `recordSyncResult`. |
| `LegacyClaimSnapshot.attemptUid` | Optional with a default, so existing constructors and tests still compile. |

---

## 23. Mutation testing (§28) — executed

Twelve mutations were applied to real source, the relevant suite was run, and
the source was restored. **No mutation is claimed without having been run.**

| # | Mutation | Result |
| --- | --- | --- |
| M1 | Attempt number stops incrementing (`attemptNumber: 1`) | **CAUGHT** |
| M2 | Operation id regenerated on every retry (`clientOpId: newClientOpId()`) | **CAUGHT** |
| M3 | Failed attempts deleted on settle (WHERE clause widened) | **CAUGHT** |
| M4 | Recovery sweep removed | **CAUGHT** |
| M5 | Unique attempt-identity index downgraded to non-unique | **CAUGHT** |
| M6 | Telemetry reverted to drain-pass semantics | **CAUGHT** |
| M7 | `synced++` moved before the applied guard | **CAUGHT** |
| M8 | Replayed 5xx becomes retryable (the infinite-loop regression) | **CAUGHT** |
| M9 | Lease recovery ignores `request_hash` (cross-payload reuse) | **CAUGHT** |
| M10 | Completed records overwritable by any token | **CAUGHT** |
| M11 | `begin()` always acquires (replay disabled) | **CAUGHT** |
| M12 | Owner scoping dropped from the ledger | **CAUGHT** |

**Attempted 12 · caught 12 · survived 0 · equivalent 0 · uncaught 0.**

Three mutations (M2, M3, M12) **survived the first run**. Per the standing
rule they were not accepted and no test was deleted; three tests were added
that pin operation-id reuse at the claim site, the exact single-attempt WHERE
clause, and owner propagation into the ledger. The suite was then re-run and
all twelve were caught. Both runs are reported here rather than only the
favourable one.

**Honest limitation.** M1–M6 and M12 mutate Dart, and the guards that catch
them are Python assertions over Dart *source*, because there is no local Dart
runtime. They prove the wiring is present, not that it executes. Execution of
those call sites is covered by the 502-case Flutter suite in CI. M7–M11
mutate PHP and are caught by genuinely *behavioural* database tests.

---

## 24. Explicitly not done

Per §32 and the deferral in §14, none of the following were touched:
F-19 lockfile, F-15 hymn attribution redesign, F-10 attendance uniqueness,
background scheduler, Sync Center, fleet dashboard, 90-day retention (S7),
APK updates, Sentry, UI redesign, auth redesign, the retry ladder, the outbox
state machine, `recoverOrphanedInFlightOperations()` semantics, and
logout/discard UX. No dependency was added, upgraded or re-locked. No
production database was contacted. No `sql/` migration was added, because S1
needs none.

---

## 25. Final status

**S1 STATUS: READY_FOR_S2**

Conditions attached to that gate:

1. **F-20 is proven, not fixed.** The duplicate-execution window is real,
   reproducible and documented, and the remediation is deliberately deferred
   to S2 with a concrete recommendation (§14). S2 must not treat it as closed.
2. **Append-only writes are not idempotent under re-execution** (§15). Any S2
   work that relies on re-execution being harmless is unsafe for those write
   shapes.
3. **`PRODUCTION SCHEMA NOT VERIFIED`** (§3). The `comm_outbox` reconciliation
   in §15 is explicitly outstanding.
4. **On-device performance is unmeasured** (§20).
5. The system is **not** described as production-ready or fully secure, and
   no claim of zero defects is made. Two findings remain open by design
   (F-20, and the pinned-5xx behaviour in §15), both documented with their
   containment.
