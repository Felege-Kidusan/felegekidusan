# S2 — Durable Background Sync Execution + Idempotency Atomicity

**Status of this document:** IN PROGRESS. Goal B (F-20) is complete and verified.
Goal A (durable background sync, Dart-side) has not been started; its sections are
marked `NOT STARTED` rather than left blank, so nothing here can be mistaken for
work that was done.

Every claim below carries an evidence label:

| Label | Meaning |
|---|---|
| `VERIFIED WITH REAL DB` | Executed against a real MariaDB 11.8.6 instance this session |
| `VERIFIED IN CI` | Asserted by the committed test suite |
| `VERIFIED BY SOURCE` | Established by reading the shipped source, not by execution |
| `INFERRED` | Reasoned from verified facts, not directly observed |
| `NOT VERIFIED` | Stated in a prior phase or brief, not confirmed here |
| `PLATFORM LIMITATION` | Cannot be verified in this environment |

---

## §1 — Scope decisions taken this phase

Three forks were put to the user and answered. They are binding:

1. **Background execution = Dart-first.** Build and CI-verify the testable Dart
   core; any Kotlin/WorkManager code is written as reviewable but explicitly
   `NOT COMPILED`. The `workmanager` package is **not** added.
2. **F-20 = classify, do not blanket-wrap.** Classify every idempotent route and
   fix only what is genuinely unsafe.
3. **Sequence = F-20 first**, background sync afterwards.

---

## §2 — The brief's central premise is wrong (`VERIFIED BY SOURCE`)

The brief directs that a `committed_at` marker be set *inside the business
transaction* of "the 14 idempotent routes". Both halves of that instruction are
factually inapplicable to this codebase.

**There are 20 idempotent call sites, not 14.** `grep` of `apiIdempotencyBegin`
across `api/v1/routes/`:

| File | Sites | Route-level transaction | Pure autocommit |
|---|---|---|---|
| `attendance.php` | 2 (`:207`, `:292`) | **2** | 0 |
| `grades.php` | 2 (`:798`, `:920`) | 0 | 2 |
| `hr.php` | 2 (`:84`, `:184`) | **1** (`:84`) | 1 |
| `mezmur.php` | 12 (`:179` … `:717`) | **1** (`:179`) | 11 |
| `users.php` | 2 (`:362`, `:388`) | 0 | 2 |
| **Total** | **20** | **4** | **16** |

S1's count of 14 missed `users.php` entirely and over-counted transactions.

**Only 4 of 20 sites have a transaction at all.** For the other 16 there is no
single COMMIT moment into which a marker could be placed. `autocommit` is never
disabled anywhere in `api/`, `config.php`, or the services (`VERIFIED BY SOURCE`),
so those 16 routes execute every statement self-committed.

**Service transaction census** (`VERIFIED BY SOURCE`): `SubmissionService`,
`HrSubmissionService`, `MezmurSubmissionService`, `MezmurMediaService`,
`SecurityAuditService` and `ApiIdempotencyService` contain **zero** transactions
each. `MezmurHymnService` is the sole exception: 3 `begin_transaction`, 3
`commit`, 7 `rollback`.

**Consequence.** A plan that writes a marker "inside the business transaction"
is impossible for 16 of 20 routes, and actively dangerous for the rest: MySQL
implicitly commits an open transaction when `begin_transaction` is issued again,
so wrapping routes that call `MezmurHymnService` would silently sever that
service's own three transactions mid-flight. The user explicitly rejected
blanket wrapping for this reason.

---

## §3 — Write-shape census (`VERIFIED BY SOURCE`)

| Service | INSERT | UPDATE | DELETE |
|---|---|---|---|
| `SubmissionService` | 3 | 10 | 0 |
| `HrSubmissionService` | 2 | 9 | 0 |
| `MezmurSubmissionService` | 2 | 9 | 0 |
| `MezmurHymnService` | 5 | 24 | 3 |
| `MezmurMediaService` | 0 | 7 | 0 |
| `SecurityAuditService` | 2 | 0 | 0 |

**There is no `ON DUPLICATE KEY UPDATE` and no `REPLACE INTO` anywhere in these
six services.** Convergence under re-execution therefore never comes from the
SQL; it comes only from table-level `UNIQUE` constraints, or from a
find-then-update code path. That distinction decides the classification in §4.

---

## §4 — Route classification: ATOMIC / RECOVERABLE / VULNERABLE

Definitions used:

- **ATOMIC** — the business effect and the idempotency outcome commit together,
  or the effect is wholly contained in one transaction that cannot partially apply.
- **RECOVERABLE** — re-execution after a crash cannot produce a *second business
  effect*, because the write converges (unique key, find-then-update, or an
  idempotent state transition). The response may differ on replay; the durable
  state does not.
- **VULNERABLE** — re-execution can produce a second, distinct business effect.

| # | Route | Shape | Class | Basis |
|---|---|---|---|---|
| 1 | `POST /attendance` `:207` | txn + unique(member,date) | ATOMIC | `VERIFIED BY SOURCE` |
| 2 | `POST /attendance` `:292` | txn + unique(member,date) | ATOMIC | `VERIFIED BY SOURCE` |
| 3 | `POST /hr/sheet` `:84` | txn | ATOMIC | `VERIFIED BY SOURCE` |
| 4 | `POST /mezmur/sheet` `:179` | txn | ATOMIC | `VERIFIED BY SOURCE` |
| 5 | `POST /grades/save` `:798` | per-row `upsertScore`, no txn | RECOVERABLE † | `VERIFIED BY SOURCE` |
| 6 | `POST /grades/submit` `:920` | state transition | RECOVERABLE | `VERIFIED BY SOURCE` |
| 7 | `POST /hr/submission-review` `:184` | state transition | RECOVERABLE | `VERIFIED BY SOURCE` |
| 8 | `POST /mezmur/hymn` `:324` | **append-only INSERT, conditional unique** | **VULNERABLE → FIXED** | `VERIFIED WITH REAL DB` |
| 9 | `POST /mezmur/hymn-status` `:346` | idempotent UPDATE | RECOVERABLE | `VERIFIED BY SOURCE` |
| 10 | `POST /mezmur/category` `:375` | INSERT + **unconditional** `uq_mezmur_categories_name` | RECOVERABLE ‡ | `VERIFIED BY SOURCE` |
| 11 | `POST /mezmur/category-status` `:502` | idempotent UPDATE | RECOVERABLE | `VERIFIED BY SOURCE` |
| 12 | `POST /mezmur/zemarian` `:531` | INSERT + **unconditional** `uq_mezmur_zemarians_name` | RECOVERABLE ‡ | `VERIFIED BY SOURCE` |
| 13 | `POST /mezmur/zemarian-status` `:547` | idempotent UPDATE | RECOVERABLE | `VERIFIED BY SOURCE` |
| 14 | `POST /mezmur/audio-presign` `:578` | `MezmurMediaService::beginUpload` | **NOT CLASSIFIED** | see §5 |
| 15 | `POST /mezmur/audio-confirm` `:611` | UPDATE (`confirmUpload`,`setDuration`) | RECOVERABLE | `VERIFIED BY SOURCE` |
| 16 | `POST /mezmur/audio-remove` `:635` | UPDATE | RECOVERABLE | `VERIFIED BY SOURCE` |
| 17 | `POST /mezmur/lyrics-synced` `:656` | UPDATE | RECOVERABLE | `VERIFIED BY SOURCE` |
| 18 | `POST /mezmur/submission-review` `:717` | state transition | RECOVERABLE | `VERIFIED BY SOURCE` |
| 19 | `POST /users/me/profile-image` `:362` | replace, `profile_version` guarded | RECOVERABLE | `VERIFIED BY SOURCE` |
| 20 | `DELETE /users/me/profile-image` `:388` | remove, version guarded | RECOVERABLE | `VERIFIED BY SOURCE` |

**† Partial application is real and was not described by S0 or S1.**
`grades/save` loops `SubmissionService::upsertScore` once per grade with no
transaction. A crash mid-loop leaves a **partially applied marklist** behind an
idempotency record stuck in `processing`; after lease expiry the whole loop
re-runs. Each row converges via its unique key, so no *second* effect is
produced and the end state is correct — but the intermediate state is visible to
readers. Classified RECOVERABLE on the stated property, flagged here because it
is a genuine finding.

**‡ Why category/zemarian are RECOVERABLE and hymn is not.** All three are
append-only `INSERT`s with no `ON DUPLICATE KEY`. The difference is the
migration that guards them:

- `sql/025` creates `mezmur_categories` with `UNIQUE KEY uq_mezmur_categories_name`
  **in the `CREATE TABLE` itself** — always present.
- `sql/030` creates `mezmur_zemarians` with `UNIQUE KEY uq_mezmur_zemarians_name`
  **in the `CREATE TABLE` itself** — always present.
- `sql/031` adds `uq_mezmur_hymns_title` to an **existing** table, and
  **conditionally refuses**: if case-insensitive duplicate titles already exist it
  emits `BLOCKER: uq_mezmur_hymns_title NOT created` and leaves the index absent.
  Its own text concedes *"Until then duplicate hymn titles can still be created
  by concurrent writers."*

So hymn creation is the one route whose only protection against a duplicate
business effect **may or may not exist in a given deployment**, determined by
data that predates the migration. That is why it, and only it, was fixed.

`createNamedTaxonomy()` (`MezmurHymnService:896`) is reachable only from inside
`saveHymn` at lines 1292, 1297, 1396 and 1401, always as
`resolveNameToId(...) ?? createNamedTaxonomy(...)` — find-then-create, inside
saveHymn's transaction. It is convergent and not independently exposed
(`VERIFIED BY SOURCE`).

---

## §5 — What is deliberately *not* classified

`POST /mezmur/audio-presign` (`:578`, `MezmurMediaService::beginUpload`) is left
**NOT CLASSIFIED**. It spans a filesystem path as well as a DB path, and the
file-side behaviour under re-execution was not read this session. Guessing a
class for it would be exactly the "assume one helper fixes all routes" error the
brief forbids. It is recorded as open work in §33.

Additionally, **none of the `mezmur_*` tables exist in any of the nine local test
databases** (`VERIFIED WITH REAL DB` — checked `ssms_prodshape`, `ssms_comm_e2e`,
`ssms_post056`, `ssms_idem_e2e`). The mezmur routes therefore cannot be driven
end-to-end locally. The fix was proved on an equivalent append-only shape
(§7); the route wiring itself is `VERIFIED BY SOURCE` only.

---

## §6 — The fix

The F-20 window is the gap between the business `COMMIT` and the **autocommitted**
`complete()` that records the outcome:

```
begin()                  <- own statement, autocommitted
begin_transaction()
  ... business writes ...
commit()                 <- effect becomes durable
                         <-- CRASH HERE: effect exists, record says 'processing'
complete()               <- own statement, autocommitted
```

After the 300 s lease expires, that record is re-acquired and the operation
**re-executes**.

The fix removes the gap rather than narrowing it. When the completion happens
*inside* the business transaction, the two outcomes become one outcome:

- **commit** → business effect **and** completed record are both durable, so a
  retry replays;
- **rollback** → neither exists, the record stays `processing`, and a retry
  correctly re-executes.

No new column, no new state, no change to the nine-state machine, no lease
change, no `409` for successful work.

**`ApiIdempotencyService::completeWithinTransaction()`** issues the same `UPDATE`
as `complete()` but is caller-transaction-scoped. Deliberately omitted:

- no `commit`/`rollback` — the caller owns the transaction;
- no probabilistic expiry sweep — a random `DELETE … LIMIT 1000` has no business
  inside someone else's transaction and would widen its lock footprint;
- no file-fallback backend — it cannot join a DB transaction, so the method
  returns `false` rather than pretending to offer atomicity.

A `false` return means "not atomically completed" and leaves pre-S2 behaviour
intact. A throw is swallowed: idempotency bookkeeping must never abort a valid
business write.

**`apiIdempotencyCompleteAtomically()`** (middleware) resolves the in-flight
reservation from `$GLOBALS['_fkss_idem']` and delegates. It does **not** unset
the global, so the normal `apiIdempotencyStore()` still runs at response time —
and is a harmless no-op, because `complete()`'s `WHERE` clause requires
`record_state='processing'` (`VERIFIED WITH REAL DB`, §7 check I-4).

**`MezmurHymnService::saveHymn()`** gained an optional trailing
`?callable $beforeCommit = null`, invoked as the last statement before its
existing create-path `commit()`. The service stays ignorant of idempotency; it
only offers a hook. The signature change is backward compatible.

**`POST /mezmur/hymn`** passes a closure that rebuilds the envelope `ok()` would
emit — `{"status":"success","data":{saved,created,item},"server_meta":{...}}` —
so a replay is byte-identical to the original response. This matters: the
alternative (storing a minimal placeholder) would silently degrade the replay
contract.

---

## §7 — Verification (`VERIFIED WITH REAL DB`)

19 new checks were added to the **existing** harness
`tests/e2e/idempotency_lifecycle.php` as scenario `atomic_completion`. No test
was deleted or weakened. The harness drives the real shipped service against
real MariaDB rows; it never asserts on source strings.

The crash is modelled faithfully — by simply **not calling** `complete()`, which
is exactly what a dead PHP worker does.

| Group | What it proves | Result |
|---|---|---|
| **I-1** | **Regression baseline** — the legacy order still reproduces F-20: 1 durable row, record `processing`, re-acquired after lease expiry | 3/3 PASS |
| **I-2** | **The fix** — after commit-then-crash the record is already `completed` with the real status code and real body; the retry **REPLAYS**; still exactly one row | 9/9 PASS |
| **I-3** | **Rollback safety** — the completion is rolled back *with* the effect: no row, record back to `processing`, operation correctly re-executable | 5/5 PASS |
| **I-4** | **No-op safety** — a late `complete()` cannot overwrite the atomically stored body or status code | 2/2 PASS |

I-1 exists so this scenario can never silently stop testing anything: if the bug
were fixed elsewhere, I-1 fails and demands attention.

I-3 exists because the obvious failure mode of this fix is trading duplicate
writes for **silently lost** writes. It proves that did not happen.

```
E2E-VERDICT: PASS (atomic_completion: 19 checks)
E2E-VERDICT: PASS (all: 67 checks)     # 48 pre-existing + 19 new
```

**Full suite: `2012 passed, 926 subtests passed, 0 failed, 0 skipped`.**
No regression against the S1 baseline of 2012 (`VERIFIED IN CI`).

### Verification log

This result was produced **twice**, on either side of an environment rollback
that destroyed the local toolchain and the unpushed commits:

| Run | Lint | Harness | Suite |
|---|---|---|---|
| Before rollback #12 | 4 files clean | 67 PASS | 2012 passed / 0 skipped, 85.52 s |
| After rebuild, on pushed commit `b6b8db9` | 5 files clean | 67 PASS | 2012 passed / 0 skipped, 72.56 s |

The second run is the authoritative one: it was executed against the exact
tree that is now on `origin/main`. The commit message for `b6b8db9` says
"re-verification pending a toolchain rebuild" because the push was deliberately
made *before* verification — the preceding rollback had already destroyed two
verified-but-unpushed commits, so getting the work onto the remote took
priority over tidiness. That caveat is now discharged by the table above.

---

## §8 — What this fix does *not* claim

- It does **not** make all 20 routes atomic. It makes **one** route atomic, because
  exactly one was shown to be genuinely vulnerable. The other 19 are classified,
  with the reasoning recorded, in §4.
- It does **not** change behaviour for the 16 autocommit routes. They remain
  RECOVERABLE by convergence, which is a weaker but sufficient guarantee for the
  stated property.
- It does **not** address the `grades/save` partial-application window (§4 †).
  That is a real finding, newly documented, and is listed as open work.
- The mezmur route wiring is `VERIFIED BY SOURCE` only — the tables do not exist
  locally (§5). The *mechanism* is `VERIFIED WITH REAL DB`.
- Mutation testing has **not** been run this phase. `NOT VERIFIED`.

---

## §9–§22 — Concurrency, crash-injection matrix, state machine

`NOT STARTED` for the remaining crash points. Of the nine required injection
points, the critical one — **after COMMIT / before idempotency completion** — is
covered by §7 I-2 for the fixed route and by I-1 as the reproduced legacy
baseline. Pre-commit failure, lease expiry, replay, error pinning, concurrent
same-key execution and append-only replay remain covered by the 48 pre-existing
harness checks from S1. The remaining points are open work.

---

## §23 — Route lifecycle reconstruction

Delivered above as §2–§5. This was a precondition of the fix, not a formality:
reconstructing it is what disproved the brief's premise and reduced the blast
radius from 20 routes to 1.

---

## §24–§32 — Goal A, durable background sync

**Goal A.1 is COMPLETE and pushed as `0ed7d72`** — see
`docs/MOBILE_OFFLINE_SYNC_S2_A1_BACKGROUND_CORE.md` for the unified
execution boundary, `execution_source` lineage (schema v37), the mockable
scheduler boundary and its deduplication, with exact verification numbers
(Python 2026 passed / 0 skipped; mutation 13 attempted / 13 caught) and an
explicit list of what is NOT verified. Goal A.2 (native Android) remains
`NOT STARTED`. Per §1 the scope is Dart-side only: unified trigger converging
foreground and background on the **existing** drain, `execution_source =
foreground|background` threaded through the S1 lineage and telemetry,
idempotent scheduling, `next_retry_at` remaining authoritative, overdue work
handled without resetting retry history, and a mockable platform boundary.

`PLATFORM LIMITATION`: CI runs only `flutter pub get` and `flutter test`. It
never compiles Kotlin and never builds an APK, so any native scheduler code is
**100 % unverified by CI** and must be labelled `NOT COMPILED`. Device behaviour
will be reported as `DEVICE RUNTIME VERIFICATION PENDING` with exact local steps.

---

## §33 — Open work

1. Classify `POST /mezmur/audio-presign` (§5), including its filesystem path.
2. Decide whether `grades/save` partial application (§4 †) warrants a fix or a
   documented accepted-risk.
3. Run mutation testing with real numbers against the new code.
4. Remaining crash-injection points (§9–§22).
5. All of Goal A (§24–§32).
6. Consider whether `sql/031` being blocked in a deployment should be surfaced
   as an operational warning, since it silently determines whether hymn creation
   is duplicate-safe at the storage layer.

---

## Gate

Not yet assessable — Goal A has not been started and mutation testing has not
been run. The final gate line (`S2 STATUS: READY_FOR_S3` or `NOT_READY_FOR_S3`)
will be emitted when the phase completes. Stating it now would be a false
signal.
