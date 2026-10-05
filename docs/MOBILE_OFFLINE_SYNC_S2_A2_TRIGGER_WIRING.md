# S2 Goal A.2 — Coordinator + Existing Trigger Wiring

Status: **COMPLETE** · Code `ac7d075` · Builds directly on A.1 (`0ed7d72` / `755229a`)

---

## 1. What this increment is

A.1 built the execution boundary but **changed no caller**. `runSyncNow(source:)`
existed; `startAutoSync` and `nudge` still reached the drain through the
untouched foreground path. A.2 is exactly that missing step: connect the
existing legitimate triggers to the boundary, and nothing else.

The required invariant, now structurally enforced and mutation-tested:

```
LEGITIMATE TRIGGER
      │
      ▼
BackgroundSyncCoordinator.execute(source)      ← A.1, reused, not renamed
      │
      ▼
runSyncNow(source:, force:, generation:)       ← THE entry point
      │
      ▼
_syncAllForGeneration(...)                     ← coalescing + ownership
      │
      ▼
_drain(generation:)                            ← one implementation
      │
      ▼
claimNextLegacyOperation(...)                  ← one claim, unchanged
      │
      ▼
existing durable outbox                        ← one source of truth
```

**No second sync implementation, no second claim, no second timer system, no
second queue, no user-visible change.**

## 2. What changed in `sync_service.dart`

| Element | Change |
|---|---|
| `_backgroundScheduler` | New field, defaults to `NoopBackgroundSyncScheduler`. |
| `backgroundScheduler` setter | Injection point for the later native layer. Unused today. |
| `_coordinator` | Lazily built `BackgroundSyncCoordinator`, `runDrain: _executeCoordinatedDrain`. |
| `_executeCoordinatedDrain(source)` | The runner the coordinator calls; forwards to `_syncAllForGeneration`. |
| `_pendingGeneration` / `_pendingForce` | Hand-off fields for arguments the A.1 `runDrain` typedef cannot carry. |
| `runSyncNow` | Now goes through `_coordinator.execute(source)`; gained optional `generation`. |
| `nudge` | Timer body calls `runSyncNow(...)` instead of `_syncAllForGeneration(generation)`. |
| `stopAutoSync` | Also `unawaited(_coordinator.cancelOpportunity())`. |

`startAutoSync` is **unmodified**. It reaches execution only via `nudge`, so
wiring `nudge` wired it too — startup, timers, lifecycle, retry timing, auth,
ownership, cancellation and UI state are all untouched.

## 3. Three design decisions that are load-bearing

### 3.1 `nudge` carries its captured generation

`nudge` reads `sessionGenerationProvider` when the timer is **armed**;
`runSyncNow` reads it at **call** time. These differ by exactly the window the
guard exists to cover.

Routing `nudge` through a generation-less `runSyncNow` would silently
re-validate against whichever session is current when the timer **fires** —
so a timer armed under user A would drain under user B. That is the precise
failure the session-generation guard was built to stop.

Hence the optional `generation` parameter: callers acting immediately omit it
and get call-time semantics; `nudge` passes the value it captured. Mutation
**M15** deletes `generation: generation` and is caught.

### 3.2 The coordinator deliberately adds **no** execution guard

An obvious-looking addition is "if a drain is already running, drop this
request". It is wrong here, and the existing code already says why:

```dart
// Gmail outbox: if a drain is already running, mark "run again"
// after it. Joining the in-flight future without that flag swallows
// any Save that landed while the first drain was already reading.
```

`_inflight`/`_queued` already coalesce near-simultaneous triggers in memory,
including the subtle case — a same-generation request arriving **mid-drain**
sets `_queued` so a second pass runs, rather than being dropped and losing
work saved after the first drain began reading.

A coordinator-level drop-if-busy guard would reintroduce that exact bug.
So A.2's job was to **prove** the existing mechanism satisfies the coalescing
requirement, not to duplicate it. Mutation **M18** adds such a guard and is
caught.

### 3.3 Logout cancels the opportunity, never the work

`stopAutoSync` cancels the pending background **wake-up**. Pending operations
stay in the durable outbox, still owned by their original user, and drain when
that user is active again. Mutation **M22** makes logout delete pending
operations and is caught; **M17** removes the cancellation and is caught.

## 4. The pinned test that had to change

`tests/security/test_mobile_session_coordinator.py` asserted the literal
`"_syncAllForGeneration(generation)"` — a string `nudge` no longer contains.

The standing rule is *adjust the code to fit the pin, never relax the pin*.
Here the pin's **property** ("a long-lived worker captures the generation and
checks it before acting") is fully preserved; only the syntactic form moved
behind the boundary. The pin was therefore rewritten to be **stricter** than
before, asserting both halves explicitly:

```python
assert "runSyncNow(" in SYNC
assert "generation: generation" in SYNC      # nudge hands the captured value over
assert "_syncAllForGeneration(generation" in SYNC
assert "_drain(generation: generation" in SYNC
```

This is recorded here rather than being changed silently.

## 5. Coverage — and its honest limits

### 5.1 Dart tests for the wiring are **not possible**

No Dart test can cover the trigger wiring. `SyncService` initialises
`ApiService()` and `LocalDb()` as **field initialisers**, so merely
constructing the class pulls in sqflite; no test in this repository opens a
database, and `sqflite_common_ffi` is not a dev dependency (`pubspec.yaml` is
frozen by F-19). This is a real gap, stated rather than papered over.

Actual coverage is therefore:

| Layer | Covered by | Executed? |
|---|---|---|
| Coordinator behaviour | `test/sync_execution_test.dart` (15 tests, A.1) | CI only |
| Trigger wiring | `TriggerWiringA2`, 15 pins | Yes, locally |
| Session-generation handoff | `test_mobile_session_coordinator.py` | Yes, locally |
| Claim / outbox / schema | A.1 pins (14) | Yes, locally |

### 5.2 Mutation results

```
MUTATION: 22 attempted / 22 caught / 0 survived / 0 skipped
```

M14–M22 are the A.2 additions:

| # | Mutation | Result |
|---|---|---|
| M14 | `nudge` bypasses the coordinated boundary | CAUGHT |
| M15 | `nudge` re-reads the generation at fire time | CAUGHT |
| M16 | `runSyncNow` skips the coordinator | CAUGHT |
| M17 | logout stops cancelling the wake-up | CAUGHT |
| M18 | coordinator gains a drop-if-busy guard | CAUGHT |
| M19 | a foreground trigger mislabels itself `background` | CAUGHT |
| M20 | `startAutoSync` reaches around `nudge` into the drain | CAUGHT |
| M21 | the executor drops its entry ownership guard | CAUGHT |
| M22 | logout destroys durable work, not just the wake-up | CAUGHT |

### 5.3 Two defects found in the A.1 harness itself

Both were found by running the harness rather than trusting its output:

1. **`background_sync_core_mutations.py` contained the whole harness twice.**
   A.1's reported "13 attempted / 13 caught" was 13 mutations executed twice
   and printed twice. The mutation *results* were valid; the file was not.
   Deduplicated.

2. **M21 initially "survived", then briefly looked "caught" for the wrong
   reason.** The first ownership assertion was a substring search, and
   `_syncAllForGeneration` re-checks ownership at ~10 later points — so
   deleting the **entry** guard still matched. Tightening it to pin the entry
   guard overshot and made the assertion fail on *clean* source, at which
   point every mutation reports CAUGHT while proving nothing. Both are fixed,
   and the harness now **refuses to run on a red baseline** so this class of
   false positive cannot recur.

## 6. Still open — not fixed, not claimed

- **Hymn `sync_attempts` gap.** `claimNextHymnOperation` still opens no
  `sync_attempts` row, so `execution_source` does **not** cover the hymn path.
  Unchanged by A.2. Tracked, not fixed.
- **Database claims remain `VERIFIED BY SOURCE`.** The v37 migration has never
  executed against a real SQLite file.
- **Dart test counts are CI-verified for pass/fail only.** GitHub returns
  403 on job logs for non-admins, so the per-test count cannot be retrieved.
- **Native Android integration is still pending** — no workmanager package,
  no Kotlin, no APK. `backgroundScheduler` is the seam it will attach to.

## 7. Numbers

```
Mutation ....... 22 attempted / 22 caught / 0 survived / 0 skipped   [A.1 was 13]
Targeted ....... 41 passed (background core 29 + session coordinator 12)
Python suite ... 2041 passed / 926 subtests / 0 failed / 0 skipped   [A.1 baseline 2026, +15]
F-20 harness ... 67 checks PASS
PHP lint ....... no PHP files changed
Dart ........... no Dart test files changed; CI is the gate
```

The Python-suite and F-20 figures were measured on this exact change set
before an environment rollback destroyed the local toolchain; they were
re-confirmed after the rebuild. The mutation and targeted-suite figures were
measured after the rebuild as well.

## 8. Environment note

This increment was interrupted twice by workspace rollbacks that reverted the
repository to `3124a5e` and deleted the local PHP/MariaDB toolchain. The
recovery procedure that works, and which is now proven three times:

1. back up dirty files outside the repo (never `/tmp`);
2. `git checkout --` tracked modifications, park untracked files;
3. re-add the remote and `git fetch origin main`;
4. **`git merge-base --is-ancestor HEAD origin/main`** — this is the step that
   proves nothing local is being discarded;
5. `git merge --ff-only origin/main`.

The first rollback cost nothing because the work was pushed. The second cost
the entire A.2 implementation because it was committed but **not yet pushed**.
Push immediately after each commit.

Status: `TRIGGER_WIRING_COMPLETE` + `NATIVE_ANDROID_INTEGRATION_PENDING`
