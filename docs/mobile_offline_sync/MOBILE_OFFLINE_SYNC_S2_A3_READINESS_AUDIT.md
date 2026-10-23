# S2 Goal A.3 — Reconciliation and Readiness Audit (read-only)

Audited commit: `94298c1` (= `origin/main`) · CI run **#60** on that exact SHA:
all four jobs `success`.

This phase implemented nothing. It re-derived the architecture from source,
checked every claim A.1/A.2 made against that source, and corrected four
objectively false verification statements. No production Dart, PHP, SQL,
schema, test or dependency was changed.

---

## 1. Repository baseline

```
HEAD            94298c1ce0ff7473d13a6142dfe98c37dc9aa85f
origin/main     94298c1ce0ff7473d13a6142dfe98c37dc9aa85f
branch          main
working tree    clean
unpushed        0
PAT material    0 occurrences in .git/config
```

Re-verified locally on the audited tree:

```
Python suite ... 2041 passed / 926 subtests / 0 failed / 0 skipped
F-20 harness ... 67 checks PASS
Mutation ....... 22 attempted / 22 caught / 0 survived / 0 skipped
CI run #60 ..... PHP syntax · Flutter unit tests · Migration numbering ·
                 Security and regression suite — all success
```

---

## 2. Architecture as it actually exists

### 2.1 The execution boundary

```
syncAll(force:)                  20 UI/service call sites
      │
      ▼
runSyncNow(source:, force:, generation:)        sync_service.dart:156
      │   stashes _pendingGeneration/_pendingForce
      ▼
BackgroundSyncCoordinator.execute(source)       sync_execution.dart:226
      │   clears _opportunityPending, calls the runner SYNCHRONOUSLY
      ▼
_executeCoordinatedDrain(source)                sync_service.dart:84
      │   reads + clears the handoff fields
      ▼
_syncAllForGeneration(generation, force:, source:)   sync_service.dart:169
      │   ownership gate · _inflight/_queued coalescing · bounded re-passes
      ▼
_drain(generation:, force:, source:)            sync_service.dart:254
      │
      ├─ for each LegacyOperationKind {attendance, grades, mezmur, hr}
      │     └─ claimNextLegacyOperation(...)     local_db.dart:3526   ← THE claim
      │           └─ _openSyncAttempt(... executionSource ...)
      │
      └─ HymnStore().pushPending()               hymn_store.dart:923  ← separate path
            └─ claimNextHymnOperation(...)       local_db.dart:6219
                  (no sync_attempts row, no execution_source)
```

`nudge` is the only timer. It captures the generation when the timer is
**armed** and hands it to `runSyncNow`:

```dart
final generation = sessionGenerationProvider?.call() ?? 0;
_retryTimer = Timer(delay, () {
  runSyncNow(source: SyncExecutionSource.foreground, generation: generation);
});
```

`startAutoSync` is untouched by A.2 and reaches execution only through
`nudge`. `stopAutoSync` cancels the timer, the connectivity subscription and
`_coordinator.cancelOpportunity()` — the wake-up, never the durable work.

### 2.2 The handoff is safe

`_pendingGeneration`/`_pendingForce` are instance fields, which would be a
race in a threaded runtime. They are safe here because `execute()` calls
`_runDrain(source)` **synchronously** with no `await` between the field write
in `runSyncNow` and the field read in `_executeCoordinatedDrain`, inside a
single-threaded Dart isolate. Verified by reading both methods, not assumed.

---

## 3. Call-path inventory and bypass classification

| Symbol | Sites | Where |
|---|---|---|
| `syncAll(` | 20 | 8 screens, `session_service.dart:965`, `offline_banner.dart:56`, 1 definition |
| `runSyncNow(` | 3 | definition, `nudge`, `syncAll` |
| `_coordinator.execute(` | 1 | `runSyncNow` only |
| `_syncAllForGeneration(` | 3 | definition, coordinator runner, internal recursion |
| `_drain(` (sync) | 2 | definition + 1 call site inside `_syncAllForGeneration` |
| `claimNextLegacyOperation(` | 2 | definition + 1 call site |
| `startAutoSync(` | 3 | definition, `session_service.dart:977`, self-call from `nudge` |
| `stopAutoSync(` | 4 | definition, `session_service.dart:980/994`, `sync_service.dart:732` |
| `claimNextHymnOperation(` | 2 | definition + `hymn_store.dart:945` |
| `pushPending(` | 12 | **11 inside `hymn_store.dart`** + 1 from `_drain` |
| `requestOpportunity(` | 1 + 11 | definition + **tests only — no production caller** |

### Finding B-1 — `requestOpportunity()` has no production caller

> **CLOSED by S2 Goal A.4 (commit `01e3162`).** `requestOpportunity()` now has
> exactly one production call site, at the tail of `_drain()` in
> `sync_service.dart`. The audit below describes the state at `742c992` and is
> retained unchanged as the record that motivated A.4. See
> `MOBILE_OFFLINE_SYNC_S2_A4_OPPORTUNITY_WIRING.md`.

**Classification: intentional / legitimate for this phase, but a native-phase
prerequisite.**

Nothing in `lib/` ever asks for a background opportunity. The scheduling half
of the coordinator is therefore inert in production. This is consistent with
`NoopBackgroundSyncScheduler` (which would do nothing anyway), so it is not a
defect today. It matters for the next phase: installing a real scheduler via
the `backgroundScheduler` setter is **not sufficient** — a caller must also
invoke `requestOpportunity()`, and no such caller exists yet.

### Finding B-2 — the hymn path bypasses the coordinator

**Classification: pre-existing legacy path. Not introduced by A.2. Not a
correctness defect. Materially under-documented.**

`HymnStore.pushPending()` is invoked from 11 places inside `hymn_store.dart`
(all `unawaited`, fired directly by hymn mutations) in addition to the one
call from `_drain`. Those 11 reach the network without passing through
`BackgroundSyncCoordinator` or `runSyncNow`.

Why it is not a correctness defect: `pushPending` carries its own complete
set of guards — `canEdit`, `_drainsAllowed`, `isLoggedIn`, connectivity, a
captured `generation` plus `_ownsGeneration`, its own `_pushing`/`_pushAgain`
coalescing, and the transactional `claimNextHymnOperation` which performs the
atomic `sync_state='in_flight'` transition. Two concurrent pushes cannot
transmit the same row, because the claim transaction is the serialisation
point.

What it does mean: the invariant "one execution path" is accurate for the
**legacy outbox** and inaccurate as a statement about the whole app. The
A.1/A.2 documents disclose the hymn *ledger* gap but never state that the
hymn path also has its own *trigger set and coalescing*. Recorded here.

### Finding B-3 — `comm_outbox_service.dart` has its own `_drain`

**Classification: intentional / legitimate, out of scope.** A distinct
messaging outbox with its own claim and generation. The "exactly one `_drain`"
invariant is scoped to `sync_service.dart`, and the pinned test scopes it
correctly.

**No suspicious bypass and no correctness defect was found.**

---

## 4. Session / account isolation

Scenario: user A arms a nudge timer → session switches to user B → the old
timer fires.

**The old timer cannot drain user A's work under user B.** Five independent
layers, each verified in source:

1. **Timer cancellation.** `session_service.dart:980/994` calls
   `stopAutoSync()` on session change, which cancels `_retryTimer`. In the
   normal path the timer never fires at all.
2. **Captured generation.** If it does fire, it carries A's generation, not
   a freshly read one.
3. **In-memory ownership gate.** `_syncAllForGeneration` opens with
   `if (!_ownsGeneration(generation) || !_api.isLoggedIn) return ...`, where
   `_ownsGeneration` requires `activeSessionGate() != false` **and**
   `generation == sessionGenerationProvider()`. Under B this is false, and the
   drain returns before touching the database.
4. **Durable session check inside the claim transaction.**
   `claimNextLegacyOperation` calls `activeSessionMatches(...)`, which reads
   `local_session_state` and requires `state == 'active'`, a matching
   `generation`, matching `owner_user_id` and matching
   `owner_authorization_version`. A stale generation claims nothing.
5. **Per-row predicates and settlement guard.** The candidate SELECT filters
   `owner_user_id = ?` and `created_authorization_version = ?`;
   `settleLegacyOperation` returns `supersededSession` if any of generation,
   owner or authorization version changed between claim and settle.

Layers 3–5 are re-checked at roughly 20 points throughout the drain, so a
switch *mid-drain* also stops it. Mutation **M21** proves layer 3's entry
guard is load-bearing; **M15** proves layer 2 is.

---

## 5. Coalescing semantics

Mechanism: `_inflight` (a `Completer`), `_inflightGeneration`, `_queued`,
`_forceNext`. No change was made by A.2 and none is needed.

| Case | Behaviour in source | Verdict |
|---|---|---|
| **A** trigger while idle | `_inflight == null` → creates the completer, enters the `do/while` | one execution |
| **B** two triggers before execution begins | first installs `_inflight`; second sees it, sets `_queued = true` (same generation), awaits the same future | coalesced, one drain, one extra pass |
| **C** trigger during `_drain()` | same as B — `_queued = true`, and the `do { … } while (_queued …)` loop runs another pass | preserved |
| **D** new work becomes eligible mid-drain | `_drain` itself sets `_queued = true` when `hasDueLegacyOutbox()` is true; the loop re-passes | not swallowed |
| **E** repeated timer nudges | `nudge` cancels `_retryTimer` before re-arming, so only one timer exists; each fire funnels into the same `_inflight` | one authoritative drain |

Two details worth recording because they are easy to break:

* Re-passes are **bounded at 10**; if `_queued` is still set the code calls
  `nudge(delay: 50ms)` rather than looping forever — continuing from the
  database instead of spinning in memory.
* A request arriving mid-drain with a **different** generation does *not* set
  `_queued`; it awaits the in-flight future and then re-enters only if
  `_inflight` has cleared. That is the correct behaviour for a session switch.

Mutation **M18** proves that adding a drop-if-busy guard in the coordinator
is caught. No second guard exists or should exist.

---

## 6. Eligibility remains authoritative

`sync_execution.dart` contains no `SELECT`, no `next_attempt_at`, no
`owner_user_id`, no `created_authorization_version`, no `claimNext*`, no
`List`/`Map`/`Queue`, no `insert(`, no sqflite — pinned by test and by
mutations M8–M10.

`claimNextLegacyOperation` continues to enforce, inside one transaction:

```sql
WHERE synced = 0
  AND sync_state IN ('pending', 'retry_wait')
  AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
  AND owner_user_id = ?
  AND created_authorization_version = ?
  AND client_op_id IS NOT NULL AND TRIM(client_op_id) <> ''
```

preceded by `activeSessionMatches(...)` and followed by an atomic
`UPDATE … SET sync_state='in_flight', attempt_count = attempt_count + 1`
whose affected-row count is asserted (`StateError` if not atomic).

```
Coordinator chooses WHEN to attempt sync.      ← holds
Claim logic chooses WHAT is eligible.          ← holds
```

The coordinator passes `source` only. It cannot widen eligibility because it
has no query. Mutations M4–M6 confirm deleting any predicate is caught.

---

## 7. Retry / lease / idempotency

| Question | Answer (source) |
|---|---|
| Who claims? | `claimNextLegacyOperation` (legacy) and `claimNextHymnOperation` (hymns) — both transactional |
| Who writes `sync_attempts`? | `_openSyncAttempt`, called **inside the claim transaction**, legacy path only |
| Where is `attempt_count` incremented? | in the claim `UPDATE`, same transaction — so a process death cannot lose the fact that an attempt started |
| Where is `next_attempt_at` computed? | at settlement, in `settleLegacyOperation` / `settleHymnOperation`, from the policy in `outbox_policy.dart` |
| Where is `Retry-After` honoured? | `response.retryAfterSeconds` flows into `describeRetryDecision` and the settlement's backoff |
| Lease expiry? | **There is no time-based lease.** `sync_state='in_flight'` is the lease and it is released by a recovery sweep, not by a timer |
| After process death? | `_recoverOrphanedInFlightWithDb` runs at database open (`local_db.dart:596`) and from `session_service.dart:304`. It resets `in_flight → retry_wait` with `next_attempt_at = now` across legacy tables, `pending_hymn_ops` and `comm_outbox`, and calls `_interruptOpenSyncAttempts` so an open attempt is closed as `interrupted` — never as success |
| Success but interrupted completion? | the row stays `in_flight` until the next recovery sweep, is retried, and the **server** idempotency layer returns the stored response instead of re-executing |
| Server idempotency? | `apiIdempotencyBegin(uid, client_op_id)` on entry; `apiIdempotencyCompleteAtomically($json, $code)` is the F-20 fix, used by the hymn-create route |

**A.2 created no second retry mechanism and no second lease mechanism.**
`nudge` remains the single timer; retry *timing* still comes from
`nextOutboxAttemptAt` (which does include `pending_hymn_ops`), and the
coordinator holds no clock, no backoff and no durations.

One honest consequence of there being no lease timeout: a row wedged
`in_flight` by a hung request inside a **live** process is only released on
the next database open. Bounded in practice by the HTTP timeout. Unchanged by
A.2, and worth re-examining when a background isolate can also hold a claim.

---

## 8. Hymn-path observability gap

**Still present.** `claimNextHymnOperation` (`local_db.dart:6219`) takes no
`executionSource` parameter and never calls `_openSyncAttempt`.

Affected operations: `hymn_save`, `hymn_status`, `category_save`,
`zemarian_save`, `lyrics_synced`.

| Dimension | Effect |
|---|---|
| Correctness | **None.** Claim is transactional, atomic, dependency-ordered |
| Observability | **Yes.** No S1 lineage row, no `execution_source`, invisible to attempt-level telemetry and to the Recovery Center's attempt view |
| Retry behaviour | **No effect.** `attempt_count`, `next_attempt_at` and backoff live on `pending_hymn_ops` itself |
| Idempotency | **No effect.** `client_op_id` is sent and the server layer protects replay |
| Account/session isolation | **No effect.** `activeSessionMatches` runs inside the claim transaction |
| Safe to run from background? | **Yes.** A background drain reaches hymns via `_drain → pushPending` with identical guards. The only loss is that those attempts will be unattributable to `background` |

**Classification: MEDIUM.**

Not a BLOCKER: nothing is incorrect, nothing is unsafe, and background
execution over hymn operations is sound. Not LOW: once native background
execution ships, `execution_source` becomes the primary tool for answering
"did this fail because it ran in the background?", and the hymn family will
be a silent hole in exactly that analysis. It should be closed **before**
background execution is enabled in production, not before it is built.

---

## 9. `audio-presign` — now classified

Evidence located:

* Mobile API route: `api/v1/routes/mezmur.php:593`, `POST /mezmur/audio-presign`
* Admin route: `admin/api_mezmur.php:807`, action `audio_presign`
* Service: `admin/backend/services/MezmurMediaService.php::beginUpload`

| Question | Answer | Evidence |
|---|---|---|
| In the mobile outbox? | **No** | `grep -rn "audio-presign\|audio_presign" Mobile/` → **zero hits**. Outbox kinds are `attendance, grades, mezmur, hr` plus `pending_hymn_ops` ops; audio-presign is in neither |
| Idempotent? | **Yes, server-side** | calls `apiIdempotencyBegin((int)$auth['uid'], $input['client_op_id'])` before doing work |
| Retry behaviour? | **No client retry** | no outbox row exists, so no `attempt_count`, no `next_attempt_at`, no backoff. A failed presign is simply re-requested by the operator |
| Can it execute from the background path? | **No** | unreachable from `_drain`; it has no mobile caller at all |
| Needs lineage / `execution_source`? | **No** | lineage describes durable outbox attempts; this route creates none |

**Classification: NOT APPLICABLE to S2 background sync** — it is an
operator-driven, role-gated (`MEZMUR_LIBRARY_WRITE_ROLES`), rate-limited
two-phase upload initiated from the admin web UI, not a mobile durable
operation. This supersedes the previous `NOT CLASSIFIED` status with source
evidence; it is not an inference.

---

## 10. Database verification status — corrected

The A.1/A.2 documents claimed uniformly that no test opens a database and
that the v37 migration had never run against real SQLite. **Both claims are
false**, and this audit corrected them in place.

Ten harnesses under `tests/security/` execute real SQLite 3:
`test_attendance_date_and_migrations`, `test_attendance_sync_phase_b`,
`test_comm_thread_cache_consistency`, `test_education_packet_uniqueness`,
`test_mobile_scope_reconciliation`, `test_mobile_sync_attempt_ledger`,
`test_mobile_sync_recovery_center`, `test_mobile_v34_sqlite_runtime`,
`test_review_transition_hardening`, `test_save_path_lock_race`.

> **CORRECTION — S2 Goal A.7 (documentation/verification only).**
>
> The label `VERIFIED BY REAL SQLITE RUNTIME` used in the four rows below was
> **overstated by omission**. It is true that a real SQLite engine executes
> real production DDL — but a reader can fairly read "real SQLite runtime" as
> "the app's own database runtime ran", and that did not happen and still has
> not. Two different things were being collapsed into one phrase:
>
> | Phrase | What it actually means | True here? |
> |---|---|---|
> | **real SQLite database execution** | a real `sqlite3` engine executes real DDL/SQL extracted from production source, with real rows, real `PRAGMA` introspection and real constraint enforcement | **YES** |
> | **actual sqflite platform-runtime execution** | the production Dart path (`LocalDb` → sqflite `openDatabase`/`transaction`) actually runs | **NO** |
>
> sqflite needs an Android/iOS platform binding, or `sqflite_common_ffi`, which
> is not in `pubspec.lock` and cannot be added because F-19 freezes
> `pubspec.yaml`/`pubspec.lock`. No Dart test in this repository opens a
> database. So in every row below the **SQL is production's**, extracted at
> test time and never retyped, while the **Dart control flow around it is
> reproduced** by the Python harness and pinned separately against source.
>
> The claims themselves are unchanged and were not overturned — only their
> label was too generous. The rows are relabelled accordingly below. A.6
> recorded this inconsistency in its §3; A.7 is where it is fixed. Nothing in
> the original audit's findings is withdrawn.


| Claim | Status |
|---|---|
| v34 tables, columns, indexes, claim interleavings | **VERIFIED BY REAL SQLITE DATABASE EXECUTION** (not sqflite platform runtime — see correction above) — `test_mobile_v34_sqlite_runtime.py` loads production DDL from `local_schema_v34.dart` and executes it |
| `sync_attempts` **create** path incl. `execution_source` | **VERIFIED BY REAL SQLITE DATABASE EXECUTION** (not sqflite platform runtime — see correction above) — `test_mobile_sync_attempt_ledger.py` runs the shipped `localSyncAttemptsV36Sql`, asserts the exact column set via `PRAGMA table_info` |
| `sync_attempts` uniqueness constraints (`attempt_uid`, per-op identity) | **VERIFIED BY REAL SQLITE DATABASE EXECUTION** (not sqflite platform runtime — see correction above) — provokes real `sqlite3.IntegrityError` |
| `sync_attempts` indexes | **VERIFIED BY REAL SQLITE DATABASE EXECUTION** (not sqflite platform runtime — see correction above) — 5 index statements executed |
| v36→v37 **upgrade** `ALTER TABLE` | **NOT VERIFIED** at the time of this audit — the guarded `ALTER` in `onUpgrade` was executed by no test. *Amended by S2 Goal A.6:* now **VERIFIED BY REAL SQLITE DATABASE EXECUTION** against a derived-and-pinned v36 fixture (`test_mobile_v36_to_v37_upgrade.py`); the Dart `onUpgrade` method itself is still not executed |
| `localDatabaseSchemaVersion = 37` pin | **VERIFIED BY TEST** (source assertion, 4 pinned files) |
| Legacy outbox claim query predicates | **VERIFIED BY SOURCE** + mutation-pinned (M4, M5, M6) |
| `execution_source` written at claim time | **VERIFIED BY SOURCE** + mutation-pinned (M1, M2, M3) |
| `execution_source` round-trip through a real DB write/read | **NOT VERIFIED** at the time of this audit. *Amended by S2 Goal A.6 and A.7:* values are now written and read back in real SQLite, including `foreground`/`background` rows produced by the hymn claim's extracted SQL (`test_mobile_hymn_attempt_ledger.py`) |
| Owner / session predicates at runtime | **VERIFIED BY SOURCE**; `activeSessionMatches` not exercised against a real DB |
| sqflite transaction semantics | **NOT VERIFIED** — the Python harnesses use `sqlite3`, not sqflite |

No migration was executed against any production or development database
during this audit.

---

## 11. Flutter / Dart verification

| Item | Reality |
|---|---|
| A.1 Dart tests | `test/sync_execution_test.dart`, **15** `test()` cases |
| A.2 Dart tests | **none** — A.2 added no Dart test |
| Executed in CI? | **Yes.** `flutter test --reporter expanded`, Flutter **3.44.9**, after `flutter pub get`. CI run **#60** on `94298c1`: job "Flutter unit tests" = `success` |
| Require a real database? | **None.** `sync_execution.dart` imports nothing; the tests use fake closures and a fake scheduler |
| Source/architecture tests | `test_mobile_background_sync_core.py` **29**, `test_mobile_session_coordinator.py` **12**, `test_mobile_sync_attempt_ledger.py` **24**, `test_mobile_v34_sqlite_runtime.py` **18** |
| Any test falsely described as integration coverage? | **No.** The A.2 doc explicitly labels its 15 wiring pins as architecture pins and states the Dart gap. After this audit's correction, the *reason* given for that gap is also accurate |
| Per-test count from CI | still unobtainable — `GET /actions/jobs/{id}/logs` returns 403 to non-admins. Pass/fail is authoritative; counts are from source |

No Dart test imports `sync_service.dart`, confirming A.2's stated limitation.

---

## 12. Mutation-testing quality

| Check | Result |
|---|---|
| Duplicate harness removed | **Yes** — 1 `MUT=[` list, 1 summary (was 2 and 2 in A.1) |
| Baseline-red protection | **Yes** — runs the suite first and `sys.exit(1)` on red |
| 22 declared == 22 distinct ids | **Yes** — M1…M22, no repeats |
| M21 genuinely caught | **Yes** — and the baseline gate now prevents the false-positive mode that briefly disguised it |
| Anchors uniquely targeted | **21 of 22.** See finding below |
| Re-run on the audited tree | `22 attempted / 22 caught / 0 survived / 0 skipped` |

### Finding M-1 — M4's anchor is not unique (LOW, currently harmless)

`M4`'s anchor `'AND (next_attempt_at IS NULL OR next_attempt_at <= ?) '`
occurs **twice** in `local_db.dart`:

* line **3555** — inside `claimNextLegacyOperation` ← the intended target
* line **6921** — inside `hasDueLegacyOutbox`

`str.replace(old, new, 1)` edits the first occurrence, which today *is* the
intended one, so M4 is correctly targeted as the file currently stands. It is
nonetheless fragile: if `hasDueLegacyOutbox` ever moves above the claim, M4
would silently begin mutating the wrong method — precisely the failure the
A.1 harness docstring records for M5.

Reported, not fixed: A.3 is read-only with respect to source, and the
mutation is presently valid. Recommended as a one-line follow-up (extend the
anchor with its neighbouring `AND owner_user_id = ?` line, as M5 already does).

### Finding M-2 — cosmetic escaping bug in the baseline gate (LOW)

The gate prints a literal `\n` instead of a newline:

```python
print("baseline green\\n")
```

Introduced by A.2 through shell-heredoc escaping. Output cosmetics only; the
gate itself functions correctly (verified by observing it run before the
mutations). Reported, not fixed, to keep A.3 free of gratuitous source edits.

---

## 13. Documentation drift

| # | Location | Problem | Action |
|---|---|---|---|
| D-1 | A.1 doc §1 | "No test in the repo opens a database" — **false** | **corrected** |
| D-2 | A.1 doc §12 | "No database test exists for this increment" — **overstated** | **corrected** |
| D-3 | A.1 doc §12 | "The v37 migration has not been run against a real SQLite file" — **false for the create path** | **corrected**, split into create (verified) / upgrade (not verified) |
| D-4 | A.2 doc §5.1 | "no test in this repository opens a database" — **false** | **corrected** to "no *Dart* test" |
| D-5 | A.2 doc §6 | "Database claims remain VERIFIED BY SOURCE" — **false** | **corrected** |
| D-6 | A.1/A.2 docs | Neither states that the hymn path has its own *trigger set and coalescing* (only the ledger gap is disclosed) | recorded here as finding **B-2**; no edit, to avoid turning A.3 into a rewrite |
| D-7 | A.1/A.2 docs | Neither notes that `requestOpportunity()` has no production caller | recorded here as finding **B-1** |
| D-8 | A.2 doc | `audio-presign` never mentioned | classified here, §9 |

Commit references in both documents were checked and are accurate
(`0ed7d72`, `755229a`, `ac7d075`, `551a143`). Neither document claims native
integration is complete; both correctly say pending.

---

## 14. Readiness matrix

| Area | Status | Evidence | Remaining action |
|---|---|---|---|
| A.1 execution core | **PROVEN** | 15 Dart tests green in CI #60; imports nothing; M8–M10 | none |
| A.2 trigger wiring | **SOURCE-VERIFIED** | 15 architecture pins + M14–M20; no Dart test can construct `SyncService` | behavioural coverage needs a testable `SyncService` seam |
| Single execution path (legacy outbox) | **PROVEN** | 1 `_drain` call site, 1 `claimNextLegacyOperation` site, 3 `_syncAllForGeneration` refs, all mutation-pinned | none |
| Single execution path (whole app) | **NOT PROVEN** | hymn path has 11 independent triggers (finding B-2) | decide whether to route hymns through the coordinator |
| Session isolation | **SOURCE-VERIFIED (strong)** | 5 independent layers; M15, M21; `activeSessionMatches` in-transaction | runtime proof needs a real DB round-trip |
| Coalescing | **SOURCE-VERIFIED** | `_inflight`/`_queued` cases A–E traced; M18 | behavioural test blocked by the same Dart gap |
| Authoritative claim | **PROVEN (as source + mutation)** | coordinator has no query; M4–M6 | fix M4's anchor (M-1) |
| Retry semantics | **SOURCE-VERIFIED** | single timer; policy in `outbox_policy.dart`; `Retry-After` honoured | none for A.2 |
| Lease semantics | **SOURCE-VERIFIED, with a caveat** | no lease timeout; recovery at DB open | re-examine when a background isolate can hold a claim |
| Idempotency (server) | **PROVEN** | F-20 harness 67/67; `apiIdempotencyCompleteAtomically` | none |
| Hymn lineage / `execution_source` | **OPEN — MEDIUM** | `claimNextHymnOperation` opens no attempt row | close before enabling background in production |
| `audio-presign` | **NOT APPLICABLE** | no mobile caller; not an outbox operation | none |
| SQLite runtime | **PARTIAL** | create path verified; upgrade `ALTER` not | execute the v36→v37 upgrade against a real file |
| Flutter tests | **PROVEN GREEN** | CI #60 job success, Flutter 3.44.9 | per-test count unobtainable (403) |
| Native Android | **PENDING** | no Kotlin, no WorkManager, no MethodChannel, `NoopBackgroundSyncScheduler` installed, `requestOpportunity()` uncalled | the whole native phase |

---

## 15. Phase determination

**Is Dart-side Goal A architecture ready to freeze?**
`YES, WITH DOCUMENTED FOLLOW-UPS` — follow-ups: hymn lineage (MEDIUM), M4
anchor (LOW), gate escaping (LOW), v37 upgrade-path runtime proof (MEDIUM),
and the two documentation omissions B-1/B-2 recorded here.

**Is native Android integration ready to begin?**
`YES, AFTER ONE BLOCKING FOLLOW-UP` — the blocker is not a defect but an
absence: **no production code calls `requestOpportunity()`**, so the native
scheduler would have nothing to attach to. That wiring must be designed as
the first step of the native phase.

**Is the project ready for S3?**
`NOT READY`.

Blockers:

1. Native Android background execution does not exist. The `background`
   `SyncExecutionSource` value has no producer, so the durable column it was
   added for still only ever stores `foreground`.
2. Hymn operations are invisible to the attempt ledger (MEDIUM) — closing
   this after background ships means the first background telemetry has a
   known blind spot.
3. The v37 upgrade path has never executed against a real SQLite file.
4. A.2's trigger wiring has no behavioural coverage at all, only source
   pins, because `SyncService` cannot be constructed in a test.

---

## A.3 result

```
PASS — DOCUMENTATION/VERIFICATION CORRECTIONS ONLY
DART_BACKGROUND_SYNC_ARCHITECTURE_READY
NATIVE_ANDROID_INTEGRATION_PENDING
S3_NOT_READY
```

No correctness defect was found. Five false verification statements were
corrected in place (D-1 … D-5). No production source, schema, test or
dependency was modified.
