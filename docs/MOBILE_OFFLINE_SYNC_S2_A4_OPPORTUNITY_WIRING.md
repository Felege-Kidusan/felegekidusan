# Mobile Offline Sync — S2 Goal A.4: Opportunity Scheduling Wiring

**Status:** COMPLETE (Dart-side). Native Android integration still pending.
**Commit:** `01e3162` (code + tests + mutations), this document follows separately.
**Baseline:** `742c992` (S2 Goal A.3 readiness audit).
**Scope:** Dart only. No Android, Kotlin, WorkManager, MethodChannel, dependency,
`pubspec`, schema, retry-policy, lease or idempotency change.

---

## 1. What A.4 closes

A.3 recorded finding **B-1**:

> `BackgroundSyncCoordinator.requestOpportunity()` exists, is tested, and has
> **zero production callers**. The *execution* half of the coordinator is wired;
> the *scheduling* half is inert.

The practical consequence was concrete and easy to miss: a native
`BackgroundSyncScheduler` could have been implemented, registered and
unit-tested, and the app still would never have asked the operating system for
a single background wake-up — because nothing in the app ever called
`requestOpportunity()`. The seam was real but unreachable.

**B-1 is now CLOSED.** `requestOpportunity()` has exactly one production call
site. The invariant established by A.1/A.2 is unchanged:

```
trigger -> requestOpportunity() -> BackgroundSyncCoordinator
        -> runSyncNow() -> _drain() -> claimNextLegacyOperation()
        -> existing durable outbox
```

---

## 2. Trigger classification

Every candidate trigger was read **from source**, not from A.1/A.2
documentation. The classification below is complete for the Dart app.

| Candidate | Where | Class | Reason |
|---|---|---|---|
| **Drain tail** | `sync_service.dart` `_drain()`, after `nextOutboxAttemptAt` | **WIRE** | The only site holding the authoritative *"durable work remains, next eligible at T"* fact, with `T` read from the outbox itself. |
| `startAutoSync()` | `sync_service.dart` L93 | ALREADY COVERED | Calls `nudge` → `runSyncNow` → `_drain`. |
| Connectivity recovery | `sync_service.dart` L101–103, `if (hasLink) nudge(500ms)` | ALREADY COVERED | Same path. This is the one authoritative connectivity listener for sync. |
| Session establishment | `session_service.dart` L977 `startAutoSync()`, L965 `syncAll(force: true)` | ALREADY COVERED | Transitively reaches `_drain`. |
| Post-mutation saves | 20 UI `syncAll()` call sites | ALREADY COVERED | `syncAll` → `runSyncNow` → `_drain`. |
| `stopAutoSync()` | `sync_service.dart` L107–119 | **KEEP** | Already calls `unawaited(_coordinator.cancelOpportunity())`. Logout must cancel the *opportunity*, never durable work. |
| Per-screen lifecycle observers | 7+ `didChangeAppLifecycleState` sites | **DO NOT WIRE** | All are per-screen data refreshes. There is **no app-level lifecycle observer for sync** anywhere in the app. Wiring screens would make scheduling depend on which screen happened to be mounted. |
| Per-screen connectivity listeners | 10+ `statusStream.listen` sites | **DO NOT WIRE** | Same reason; the authoritative listener already exists in `startAutoSync`. |
| `HymnStore.pushPending()` | 12 call sites, own `_pushing`/`_pushAgain` | **DO NOT WIRE** | A.3 finding B-2, pre-existing and explicitly **not a defect**. A.4 does not redesign the hymn path — and does not need to (see §4). |

**Exactly one new call site is justified.** Because all live triggers converge
transitively on `_drain()`, adding a request at any of them would duplicate the
request, duplicate eligibility logic, or both.

---

## 3. The one deliberate behavioural choice

The pre-existing drain tail armed the retry nudge only when a link was present:

```dart
if (nextAttempt != null) {
  if (force || ConnectivityService().hasLink) {   // nudge gated on connectivity
    nudge(delay: ...);
  }
}
```

So an offline device with pending durable work scheduled **nothing at all** —
which is exactly the case an in-app timer cannot cover, because the process may
not survive long enough to fire it.

The opportunity request is therefore placed **outside** that gate, before it:

```dart
if (nextAttempt != null) {
  unawaited(
    _coordinator
        .requestOpportunity(notBefore: nextAttempt)
        .catchError((Object _) {}),
  );
  if (force || ConnectivityService().hasLink) {   // UNCHANGED, byte for byte
    final wait = nextAttempt.difference(DateTime.now().toUtc());
    nudge(delay: wait <= Duration.zero ? Duration.zero : wait);
  }
}
```

`requiresNetwork: true` lets the platform wake the process when connectivity
returns. The nudge's condition and delay are byte-identical to A.3; this is
pinned both by `test_foreground_retry_timing_is_unchanged` and by mutation
**M28**, which moves the request inside the gate and must be caught.

`unawaited` + `catchError`: a scheduling failure must never fail or delay a
drain. The coordinator resets its own pending flag before rethrowing, so the
next drain simply requests again.

---

## 4. Why this reaches hymn work without touching the hymn path

`LocalDb.nextOutboxAttemptAt` (L4752) **UNIONs `pending_hymn_ops`** with the
legacy outbox tables. So when only hymn work is pending, `nextAttempt` is still
non-null and an opportunity is still requested. The hymn path keeps its own
`pushPending()` executor (B-2) and its own coalescing; A.4 adds no call into it.

This does **not** close A.3 finding B-3 (hymn operations remain invisible to the
`sync_attempts` ledger — MEDIUM, observability-only, deliberately out of A.4
scope).

---

## 5. Generation and session safety

`requestOpportunity()` intentionally carries **no generation, user id, or
payload**. This is safe, and is not an exception to A.2's capture-before-`Timer`
rule:

1. A platform opportunity is **session-agnostic by construction** —
   `BackgroundSyncRequest` can hold only `notBefore` and `requiresNetwork`
   (pinned by `test_the_request_carries_no_payload_or_identity`).
2. A granted opportunity must act on whoever is active **when it is granted**,
   not whoever was active when it was requested. Stamping a generation on it
   would be wrong, not safer.
3. On grant it re-enters via `_coordinator.execute()` → `runSyncNow()`, which
   reads the **current** generation at execution time.
4. The durable claim re-validates ownership inside its own transaction
   (`activeSessionMatches`, owner and authorization-version predicates).
5. Logout cancels the opportunity (`stopAutoSync` → `cancelOpportunity`).

A.2's rule — *capture the generation before `Timer(...)`* — governs in-process
timers armed under a session. It does not apply to a platform wake-up.

---

## 6. No second mechanism

| Mechanism | A.4 behaviour |
|---|---|
| Opportunity de-duplication | Reuses the coordinator's existing `_opportunityPending`. **No new flag.** |
| Execution coalescing | Still A.2's `_inflight` / `_queued`, untouched. |
| Drain | Still exactly one `_drain` in `sync_service.dart`. The trigger does **not** call it. |
| Claim | Still `claimNextLegacyOperation` (1 definition + 1 call). The trigger does **not** call it. |
| Scheduler | Still `NoopBackgroundSyncScheduler`; `backgroundScheduler` remains the native seam. |
| Timers / queues / generations | None added. |

Steady state: request → nudge fires → `execute()` clears `_opportunityPending`
→ next drain re-requests with a refreshed `notBefore`. This is the documented
idempotent `ensureScheduled` contract, not a second scheduler.

---

## 7. Verification

| Check | Result |
|---|---|
| Python suite (`tests/security`) | **2055 passed / 926 subtests / 0 skipped** (A.3 baseline 2041, +14) |
| Mutation harness | **28 attempted / 28 caught / 0 survived / 0 skipped** (A.3 was 22) |
| Anchor-uniqueness gate | **28/28 unique** |
| Baseline gate | green |
| F-20 idempotency harness | **67 checks PASS** |
| Brace/paren balance of edited file | `(0,0)`, against a `(0,0)` control from `HEAD` |
| Dart tests | **not run locally — Flutter is unavailable in this environment.** CI remains the only Dart gate. No Dart test was changed. |

### New mutations (all CAUGHT)

| ID | Mutation | Invariant |
|---|---|---|
| M23 | Remove the opportunity request | B-1 must stay closed |
| M24 | Drain directly instead of requesting | Trigger must not execute |
| M25 | Claim directly instead of requesting | Trigger must not claim |
| M26 | Call `_backgroundScheduler.ensureScheduled` directly | Must route through the coordinator |
| M27 | Replace the Noop seam with a second scheduler | One scheduler seam only |
| M28 | Move the request inside the connectivity gate | Offline wake-up must survive |

### Harness quality (A.3 findings M-1, M-2)

* **M-1 FIXED.** M4's anchor occurred twice in `local_db.dart` (the claim query
  at 8-space indent and `hasDueLegacyOutbox` at 10-space indent). Because
  `str.replace(old, new, 1)` mutates the *first* match, M4 happened to hit the
  right site only by source ordering — it reported CAUGHT either way. It is now
  anchored to the claim's own preceding `AND sync_state IN (...)` line at the
  claim's indentation, which is unique.
* **New permanent ANCHOR-UNIQUENESS GATE.** Before the baseline gate, the
  harness counts every mutation's anchor occurrences and exits 1 on any anchor
  matching ≠ 1 time. This class of defect cannot recur silently. **This gate
  must never be removed.**
* **M-2 FIXED.** Both baseline-gate prints emitted a literal `\n`; now a real
  newline.

---

## 8. What A.4 does NOT change

No Android, Kotlin, WorkManager, MethodChannel, AndroidManifest, background
service, push/FCM or connectivity-plugin work. No new dependency, no `pubspec`
or `pubspec.lock` edit, no schema change. Retry timing, lease behaviour, claim
predicates, ordering, owner and authorization-generation isolation, session
isolation, server idempotency, outbox schema, foreground semantics and all
user-visible behaviour are preserved.

---

## 9. Readiness after A.4

**S3 is still NOT READY.** A.4 removes the *precondition* for blocker (1) — the
app now asks for opportunities — but closes none of the four S3 blockers:

1. **No native background producer.** `SyncExecutionSource.background` still has
   no real producer; the scheduler is still `NoopBackgroundSyncScheduler`, so
   the request is accepted and dropped. (Precondition removed by A.4.)
2. **Hymn operations invisible to the attempt ledger** (A.3 §8, MEDIUM) — close
   before *enabling* background execution in production.
3. **The v36→v37 upgrade path has never been executed.** Only the v37 *create*
   path is verified by real SQLite runtime.
4. **The A.4 wiring has source-level pins only** — no behavioural Dart test can
   reach it, because `SyncService` constructs `ApiService()` / `LocalDb()` as
   field initialisers and `sqflite_common_ffi` is not a dev dependency (frozen
   by F-19).

---

## 10. Recommended next phase (one)

**Goal A.5 — make the wiring behaviourally testable by injecting
`SyncService`'s collaborators**, converting the `ApiService()` / `LocalDb()`
field initialisers into constructor-injected dependencies with the current
values as defaults.

Rationale: every A.2/A.4 claim in this document is *source-verified only*, and
that is now the single largest gap between "architecturally ready" and
"provably correct". It is a Dart-only, dependency-free refactor that does not
require `sqflite_common_ffi` for the trigger tests, and it is a strict
precondition for trusting the native integration that follows — once native
code can wake the app, source pins are no longer sufficient evidence.
