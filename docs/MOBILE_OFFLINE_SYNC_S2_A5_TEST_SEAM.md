# Mobile Offline Sync — S2 Goal A.5: Constructor-Injection Test Seam

**Status:** COMPLETE. Native Android integration still pending.
**Code commit:** `b1cfbb6` · **Baseline:** `8438653` (S2 Goal A.4)
**CI:** run **#64** on `b1cfbb6` — 4/4 green, including *Flutter unit tests*.
**Scope:** Dart only. No Android, Kotlin, WorkManager, MethodChannel, dependency,
`pubspec`, schema, retry-policy, lease or idempotency change.

---

## 1. The gap A.5 closes

A.3 and A.4 both ended with the same caveat:

> A.2/A.4 behaviour is largely **source-verified** because `SyncService`
> constructs `ApiService()` and `LocalDb()` through field initialisers,
> preventing focused unit tests from substituting test doubles.

This was not a matter of taste. `SyncService` could not be *constructed* in a
test at all, so every claim about `runSyncNow`, `nudge`, the generation
handoff, `_inflight`/`_queued` coalescing and the A.4 opportunity request
rested on reading the source and on mutation pins over that text. Correct, but
one category of evidence only.

A.5 adds the smallest seam that makes the real class executable, and then uses
it. It is **not** a dependency-injection refactor.

---

## 2. The seam — and why the obvious shape would have been a trap

The design suggested for this phase was:

```dart
SyncService({ApiService? api, LocalDb? db})
    : _api = api ?? ApiService(), _db = db ?? LocalDb();
```

**That would have been silently wrong here.** `SyncService` is a singleton:

```dart
static final SyncService _instance = SyncService._internal();
factory SyncService() => _instance;
```

A `factory` returning a cached instance *ignores its arguments for every call
after the first*. The code would compile, and a test could pass a fake, get the
production singleton back, and still appear to pass. So the injection point is
a **separate named constructor**:

```dart
SyncService._internal()
    : _api = ApiService(),
      _db = LocalDb();

/// S2 Goal A.5 — the behavioural test seam, and nothing more.
SyncService.withCollaborators({ApiService? api, LocalDb? db})
    : _api = api ?? ApiService(),
      _db = db ?? LocalDb();

final ApiService _api;
final LocalDb _db;
```

| Property | Outcome |
|---|---|
| `SyncService()` | Unchanged. Same single instance, real `ApiService`, real `LocalDb`. |
| Existing callers | **Zero changed.** |
| Injectable collaborators | Exactly two — the two that stood between these paths and a test. |
| Hidden construction | None. The only `ApiService()`/`LocalDb()` in the file are the two constructor defaults; a pin deletes both initialiser lists and asserts nothing remains. |
| Frameworks | None. No GetIt/Provider/Riverpod, no service locator, no container, no interfaces invented for the task, no startup change. |

Each `withCollaborators` call returns a **fresh** instance, which is what gives
tests isolated `_inflight` / `_queued` / coordinator state.

---

## 3. The test doubles

`test/sync_service_behavior_test.dart` — 26 tests, no new dependency, no
database. The fakes use `implements` + `noSuchMethod`, so only the members the
drain path actually touches are written:

* **`_FakeDb`** — 11 members: the claim, `nextOutboxAttemptAt`,
  `hasDueLegacyOutbox`, `cleanupSynced`, the five pending counts,
  `getOutboxInventory`, `logSync`.
* **`_FakeApi`** — 4 members: `isLoggedIn`, `userId`, `authorizationVersion`,
  `userRole`.
* **`_RecordingScheduler`** — the existing public `backgroundScheduler` seam,
  recording what the **real** coordinator asked the platform to do.

Anything else throws through `noSuchMethod` rather than returning null, so if
production code later reaches further into either collaborator the test fails
loudly instead of passing quietly. The claim deliberately returns `null`: with
nothing claimable the drain still has to walk every kind, do its bookkeeping
and reach the A.4 request — which is the wiring under test.

**No Mockito, no Mocktail, no `sqflite_common_ffi`.** F-19 remains frozen.

---

## 4. What is now behaviourally verified

Executing the real `SyncService`, the real `BackgroundSyncCoordinator` and the
real `_drain` — no reimplementation of production logic in the test:

| # | Behaviour | Was |
|---|---|---|
| 1 | `runSyncNow()` reaches the authoritative claim, once per legacy kind | source only |
| 2 | A background execution reaches the **same** claim, carrying only a different `execution_source` label — it gets no query of its own | source only |
| 3 | `syncAll()` reaches the same path, labelled foreground | source only |
| 4 | `nudge()` reaches the same path, and is **deferred, not direct** | source only |
| 5 | Owner id and authorization version arrive at the claim from the API | source only |
| 6 | The claim is told the generation the drain is running under | source only |
| 7 | **A nudge armed under generation A does not drain after the session moves to B** — it stops *before* touching the database | source only (M21) |
| 8 | An inactive session gate, and a logged-out API, both stop the drain | source only |
| 9 | Two overlapping executions become **one drain plus one queued pass** — not two parallel drains, and not one swallowed request | source only |
| 10 | A due backlog queues exactly one further pass | source only |
| 11 | Durable work remaining asks the platform for an opportunity, `notBefore` taken from the outbox | source only |
| 12 | The request carries `requiresNetwork` | source only |
| 13 | No durable work asks for nothing | source only |
| 14 | A far-future next attempt does **not** re-drain immediately; an already-due one does | source only |
| 15 | `stopAutoSync()` cancels the opportunity | source only |
| 16 | `stopAutoSync()` touches **no** durable work | source only |
| 17 | `SyncService()` is still the singleton; an injected instance is not it | n/a (new) |

---

## 5. What remains SOURCE-VERIFIED ONLY

Stated plainly, because the point of A.5 is honest evidence:

* **The offline case specifically.** `ConnectivityService` is an uninjected
  singleton with no public test hook and `hasLink` defaults to `true`, so
  "an offline device still schedules a wake-up" keeps its python pin and
  mutation **M28**. What *is* proven behaviourally is the mechanism that makes
  it work: the request is emitted with `requiresNetwork: true`. Injecting
  connectivity was out of A.5's declared two-collaborator scope.
* **Everything SQL.** No database is opened. Claim predicates,
  `next_attempt_at` eligibility, owner/authorization isolation *inside the
  transaction*, lease recovery and the **v36→v37 upgrade** are untouched by
  A.5 and remain exactly as verified — or unverified — before it. A fake
  database is not evidence about SQLite.
* **Hymn execution (B-2).** The real `HymnStore` and `MezmurDownloadManager`
  singletons are reached inside `_drain`'s own try/catch and fail there without
  a database. That is pre-existing behaviour, which is why these tests assert
  on scheduler and claim observations rather than on `failed` counts.

---

## 6. Mutations 29–35 (new, all caught)

| ID | Mutation | Invariant |
|---|---|---|
| M29 | The API collaborator stops being injectable | the seam itself |
| M30 | The DB collaborator stops being injectable | the seam itself |
| M31 | A method rebuilds the API instead of using the injected field | no hidden construction |
| M32 | A method rebuilds the DB instead of using the injected field | no hidden construction |
| M33 | A due backlog stops queueing another pass | `_queued` (backlog) |
| M34 | A request arriving mid-drain is swallowed instead of queued | `_queued` (coalescing) |
| M35 | The injecting constructor becomes a factory returning the singleton | the trap in §2 |

Bypassing the coordinator (M16, M26), bypassing `runSyncNow` (M13, M14, M20),
removing the generation handoff (M15) and the two A.4 mutations (M23, M28)
already existed and are unchanged.

### A real weakness M33/M34 exposed

The pre-A.5 pins asserted the **substring** `"_queued = true"`. Two separate
sites satisfy it — `if (hasMoreDueLegacy) _queued = true;` and
`if (sameGeneration) _queued = true;` — so deleting **either one individually**
left the substring present and survived. Each site is now pinned by count. This
is the same lesson as A.3's M-1: *presence is not position.*

### M-1 / M-2 protections

Verified intact. The **anchor-uniqueness gate** (refuses to run if any anchor
matches ≠ 1 time) and the **baseline gate** (refuses to run on red source) both
remain and both ran: `anchors unique: 35/35`, `baseline green`.

---

## 7. Verification

| Check | Result |
|---|---|
| **Flutter CI** (`flutter test`, run #64 on `b1cfbb6`) | **success** — the job runs the whole `test/` directory, so the 26 new tests compiled and passed; any failure would have failed the job |
| Full CI | **4/4 green**: Flutter unit tests · PHP syntax · Migration numbering · Security and regression suite |
| Python (`tests/security`) | **2075 passed / 926 subtests / 0 skipped** (A.4 baseline 2055, **+20** new A.5 pins) |
| Mutation harness | **35 attempted / 35 caught / 0 survived / 0 skipped** (A.4 was 28) |
| Anchor uniqueness | **35/35** |
| Baseline gate | green |
| F-20 idempotency harness | **67 checks PASS** |
| `git diff --check` | clean |
| Static/format tooling | none added; CI's existing checks are the gate |

**Dart was not run locally — this environment has no Flutter SDK.** CI is the
only Dart gate, and the per-test breakdown is not readable because GitHub
returns 403 on job logs to non-admins. No Dart test was deleted or weakened.

---

## 8. Scope compliance

```
pubspec changed:        NO
pubspec.lock changed:   NO
schema changed:         NO
native Android changed: NO
new dependency:         NO
new scheduler:          NO
second drain:           NO
```

Retry timing, jitter, `Retry-After`, lease behaviour, attempt counting, claim
predicates, ordering, owner isolation, authorization generation, session
generation, `_inflight`, `_queued`, `_opportunityPending`, `requestOpportunity`,
scheduler semantics, hymn behaviour and all user-visible behaviour are
unchanged. No correctness defect was found; nothing was silently fixed.

---

## 9. Readiness after A.5

**S3 is still NOT READY.** A.5 changes the *quality of evidence*, not the
capability. Of the four S3 blockers:

1. **No native background producer.** `SyncExecutionSource.background` still
   has no real producer; the scheduler is still `NoopBackgroundSyncScheduler`.
   **OPEN.**
2. **Hymn operations invisible to the `sync_attempts` ledger** (MEDIUM,
   observability-only). **OPEN.**
3. **The v36→v37 upgrade path has never been executed.** **SUBSTANTIALLY
   REDUCED by S2 Goal A.6** — the migration's SQL is now extracted from
   production and executed against a real SQLite database (populated and
   empty v36 fixtures, data survival, backfill, indexes, constraints and
   post-upgrade writes all proven). **Not formally closed:** the Dart
   `onUpgrade` function itself is still not executed, because sqflite needs a
   platform binding and `sqflite_common_ffi` is barred by F-19. See
   `MOBILE_OFFLINE_SYNC_S2_A6_MIGRATION_RUNTIME.md`.
4. **The wiring had source-level pins only.** **CLOSED for the coordinator and
   trigger path** (§4); still open for the offline-connectivity case and for
   everything SQL (§5).

So A.5 closes blocker (4) in substance and narrows nothing else.

---

## 10. Recommended next phase (one)

**Goal A.6 — execute the v36→v37 SQLite upgrade path against a real database
in CI**, using the real-SQLite runtime harness that already exists on the
Python side (`tests/security/test_mobile_sync_attempt_ledger.py::_ledger_db()`
proves the v37 *create* path today).

Rationale: of the three remaining blockers this is the only one that is both
(a) a correctness risk rather than an observability or capability gap, and
(b) reachable without native Android or a `pubspec` change. Every existing
device in the field will take the `ALTER` path, not the create path, and that
path has never been run — a failure there is a data-layer failure on upgrade,
which is the worst moment to discover it. It is also a strict precondition for
enabling background execution: a wake-up that runs against a half-migrated
database is worse than no wake-up at all. *Not started.*
