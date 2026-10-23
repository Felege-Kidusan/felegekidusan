# S3 / A.8 — Native Android background sync producer

**Result: PASS — BUILD/SOURCE VERIFIED, RUNTIME LIMITED.**

A.8 created the first real producer of `SyncExecutionSource.background`. Before this
increment the enum value existed, the durable column existed, the coordinator existed and the
scheduler abstraction existed — but `NoopBackgroundSyncScheduler` was installed and no caller
anywhere in the app could emit a background execution. S3 was blocked on exactly that.

The flow now implemented end to end in source:

```
AlarmManager.setAndAllowWhileIdle(RTC_WAKEUP)
        -> BackgroundSyncReceiver            (exported=false, manifest-declared)
        -> BackgroundSyncProducer.deliver()  (invokes the live engine's channel)
        -> BackgroundSyncBridge.handleCall() (Dart, strict typed parse)
        -> SyncService.runSyncNow(source: background)
        -> BackgroundSyncCoordinator.execute()        [EXISTING, unchanged]
        -> _syncAllForGeneration -> _drain            [EXISTING, unchanged]
        -> legacy claim / HymnStore.pushPending       [EXISTING, unchanged]
        -> sync_attempts.execution_source = 'background'
```

`sync_service.dart`, `sync_execution.dart`, `local_db.dart` and `hymn_store.dart` were **not
modified by this increment**. The scheduler seam A.2 left behind (`set backgroundScheduler`)
was sufficient, which is the strongest available evidence that A.1–A.5 designed the boundary
correctly.

---

## 1. Mechanism selection

Full evaluation is in
`MOBILE_OFFLINE_SYNC_S3_A8_PHASE1_ANDROID_BACKGROUND_PRODUCER_ADR.md`. Summary of why
**AlarmManager + a minimal BroadcastReceiver** was chosen over the alternatives:

| Candidate | Verdict |
|---|---|
| `workmanager` (pub) | Best reboot/constraint story, but **forbidden by a standing S2 scoping decision**, requires a pubspec/lockfile change blocked by F-19, and still forces a second isolate. |
| `android_alarm_manager_plus` | New dependency, **no native network constraint**, still forces a second isolate. |
| JobScheduler (hand-written) | Technically excellent (native network constraint, `setPersisted`), but still needs a headless engine to reach Dart, so it inherits the stop condition at far higher Kotlin cost. |
| Foreground service | Disproportionate. A sync drain is not user-visible ongoing work; it would require a permanent notification and a `foregroundServiceType` that does not honestly describe syncing. |
| Reuse `audio_service` | Declared `foregroundServiceType="mediaPlayback"` and only alive while audio plays. Would misdeclare the service type and tie sync to mezmur playback. |
| **AlarmManager + receiver → existing engine** | **Selected.** Zero dependencies, zero new permissions, ~2 small Kotlin files, one `LocalDb`, one isolate, existing coalescing stays authoritative. |

Two properties made the decision, and neither is about familiarity:

1. **`setAndAllowWhileIdle` needs no permission.** `setExactAndAllowWhileIdle` would need
   `SCHEDULE_EXACT_ALARM` from API 31. Inexact is sufficient here *because* `notBefore` is
   advisory by design — the claim query, not the alarm, decides what is due.
2. **It is the only candidate that does not spawn a second Dart isolate**, and therefore the
   only one that does not create a second `LocalDb` singleton and a parallel sqflite
   connection model. A.8 names that a stop condition; see §5.

---

## 2. F-19 / dependency decision

**Verdict (A): no new dependency is required, and none was added.**

`pubspec.yaml` and `pubspec.lock` are **byte-identical to the A.7 baseline**. F-19 was not
reopened and did not need to be.

F-19 itself was pinned exactly during Phase 1: `url_launcher: ^6.3.2` is a declared direct
dependency and occurs **zero times** in a 130-package `pubspec.lock`, so
`flutter pub get --enforce-lockfile` cannot succeed; CI works around it with plain
`flutter pub get`. The lockfile also records `dart >=3.12.0 / flutter >=3.44.0` against a
manifest floor of `>=3.3.0 / >=3.27.0`.

Option (B) was checked and is false: nothing in the lockfile can schedule background work.
`audio_service` is present but is a media-playback foreground service, not a scheduler.

Had (C) been true it would have been **unexecutable here**: regenerating the lockfile needs a
local Flutter SDK, and there is none in this environment.

---

## 3. Permissions

**Zero new permissions.** The manifest still declares exactly the same nine:

```
INTERNET  WAKE_LOCK  FOREGROUND_SERVICE  FOREGROUND_SERVICE_MEDIA_PLAYBACK
POST_NOTIFICATIONS  CAMERA  USE_BIOMETRIC  ACCESS_NETWORK_STATE
REQUEST_INSTALL_PACKAGES
```

Deliberately **not** requested, each for a stated reason:

* `SCHEDULE_EXACT_ALARM` / `USE_EXACT_ALARM` — inexact scheduling is sufficient (§1).
* `RECEIVE_BOOT_COMPLETED` — re-arming at boot would schedule a wake-up into a process that
  is not running, which this mechanism cannot serve anyway. It would be a permission that
  buys nothing. See §7.
* No notification permission for sync. No foreground service. No native UI.

A test asserts the permission set exhaustively, so adding one silently fails CI.

---

## 4. The invocation contract

One channel, `fkss.app/background_sync`, carrying both directions. Every name is a named
constant on both sides, and a source-contract test pins each pairing so the two halves cannot
desynchronise.

**Dart → native**

| Method | Arguments |
|---|---|
| `ensureScheduled` | `uniqueWorkName`, `notBeforeEpochMs` (UTC ms or null), `requiresNetwork` |
| `cancel` | `uniqueWorkName` |

**Native → Dart**

| Method | Arguments |
|---|---|
| `runBackgroundSync` | `source` (always `"background"`), `invocationId` |

### Provenance is explicit and typed

`source` is a named field whose value must spell `background` exactly. It is **never** derived
from the thread name, the Android component, the lifecycle state, a hidden singleton boolean,
the caller stack or timing.

A message that says `foreground`, uses an unknown spelling, is not a map, or carries an empty
`invocationId` is **rejected** with `invalid_background_invocation` — it is not defaulted.
`SyncExecutionSource.fromStorage()` is deliberately *not* used here: its tolerant fallback to
`foreground` exists to interpret pre-v37 database rows, and applying it to a live invocation
would silently corrupt the `sync_attempts` lineage A.7 established.

### Nothing else crosses the boundary

The scheduling message carries exactly three keys and the callback exactly two. Tests assert
both key sets exhaustively and scan for `token` / `owner` / `user` / `authorization` /
`password` / `generation`. `BackgroundSyncRequest` cannot express a payload by construction.

---

## 5. The architectural stop condition, and how it was avoided

A.8 instructed: *do not create a parallel database connection model unless the chosen
mechanism genuinely requires one; if it does, STOP and document.*

**Every** Flutter mechanism that survives process death requires one. When Android wakes a
dead process there is no Dart isolate, so `workmanager`, `android_alarm_manager_plus`, a
hand-written headless `FlutterEngine` and JobScheduler all spin up a **new isolate**, which
gets its own `LocalDb()` (a second sqflite connection), its own `ApiService()`
(`isLoggedIn == false`), and its own `SyncService()` with `activeSessionGate` and
`sessionGenerationProvider` unset until `SessionService.bootstrap()` runs.

That would mean two connections draining concurrently while the UI isolate is alive. The
`_inflight`/`_queued` coalescing A.2/A.5 proved **does not span isolates**; cross-isolate
safety would rest entirely on the DB-level claim, which is genuinely atomic but which **no
test in this repository has ever exercised from two concurrent connections**.

The selected mechanism avoids this entirely by invoking the **existing** engine. One isolate,
one `LocalDb`, one `SyncService`, one already-bootstrapped session, existing coalescing fully
authoritative. The cost is stated honestly in §7: it does nothing once the process is dead.
A cold-start headless engine remains available as a future phase with its own cross-isolate
evidence.

---

## 6. Session, owner and authorization isolation

The bridge performs **no** session, owner, authorization-version, login or connectivity check
of its own. Adding one would be a second gate that could drift from the real one. Every gate
is the existing one inside `_syncAllForGeneration`:

```dart
if (!_ownsGeneration(generation) || !_api.isLoggedIn) return SyncResult(... 'Not logged in');
```

`runSyncNow` is called **without** a captured generation. This is deliberate and is the single
most important line in the bridge: a wake-up may be granted long after it was requested, so
the drain must validate against whichever session is current **at execution time**. Pinning a
generation at scheduling time would let a previous account's scheduled work execute.

| Requirement | Mechanism | Evidence |
|---|---|---|
| Logged out → no authenticated sync | `_api.isLoggedIn` | Dart test: claim count 0, message `Not logged in` |
| Inactive session → none | `activeSessionGate` | Dart test: claim count 0 |
| Stale generation → none | `_ownsGeneration` | Dart test: generation change blocks the wake-up |
| Owner isolation | claim takes `ownerUserId` from `ApiService` | Dart test: owner 42 reaches the claim |
| Authorization version | claim takes `authorizationVersion` | Dart test: version 9 reaches the claim |
| No credentials in the wake-up | `BackgroundSyncRequest` has no such field | Exhaustive key-set test |

The scheduler request itself is credential-free *by construction*, which matters because an
alarm may be persisted by the OS and may outlive the session that created it.

---

## 7. Process state behaviour — stated precisely, with no invented guarantees

These four states are different and are not conflated:

| State | Behaviour | Why |
|---|---|---|
| **Foreground / backgrounded, process alive** | **Works.** The broadcast reaches the live engine and the drain runs. | This is the case the mechanism serves — and it is a real gap today, because Dart `Timer`s are frozen while the app is idle or Doze-suspended whereas this alarm is not. |
| **Process killed** (low memory, OEM swipe-away) | **Does not sync.** The manifest receiver still starts the process, finds no `FlutterEngine`, logs, and returns. It deliberately does **not** re-arm — that would be a wake loop with no progress. | No headless engine; see §5. The durable outbox is untouched and drains on next launch. One short wasted process start per missed opportunity; bounded, because the alarm is one-shot and never periodic. |
| **Force-stopped by the user** | **Does not sync, and cannot.** Android cancels the app's alarms and delivers no broadcasts until the user launches the app again. | Platform behaviour. No workaround exists and none is attempted. |
| **Device rebooted** | **Scheduling does not survive.** AlarmManager alarms are cleared at boot. | `RECEIVE_BOOT_COMPLETED` is deliberately not requested (§3). Reconstruction happens on next app launch: the first drain reads the outbox and the existing A.4 request re-arms. For this mechanism that is the safest supported reconstruction, because a boot-time alarm would target a process that is not running. |

**Doze:** `setAndAllowWhileIdle` fires during idle but is **rate-limited by the OS**
(historically ~one delivery per app per 9 minutes) and alarms are batched, so delivery is
best-effort and may be late. This is safe by construction: `notBefore` is advisory and the
claim admits only rows whose `next_attempt_at` has passed.

**Network:** AlarmManager **cannot** enforce a network constraint. `requiresNetwork` is
forwarded and logged, never interpreted natively — there is no `ConnectivityManager` anywhere
in the Kotlin, which a test asserts. The final network and session checks stay in Dart, where
they already live. This is the explicitly permitted fallback, and it is a genuine limitation
of the chosen mechanism rather than an oversight.

---

## 8. Scheduling, cancellation and duplicates

* **Unique work.** One request code (`0x5F55`) plus `FLAG_UPDATE_CURRENT` gives exactly one
  alarm slot. Ten `ensureScheduled` calls leave one pending alarm; a newer `notBefore`
  **replaces** an older one rather than queuing beside it. This is the OS-level counterpart of
  `BackgroundSyncRequest.uniqueWorkName` and reinforces the coordinator's in-memory
  deduplication with something that survives process death. A test asserts there is exactly
  one request code and exactly one `PendingIntent.getBroadcast` factory.
* **`notBefore`** is clamped forward to `now` when absent or past, and never backwards. A
  non-numeric value degrades to "as soon as allowed" rather than crashing or producing a wrong
  far-future alarm.
* **No periodic loop.** `setRepeating`, `setInexactRepeating`, `INTERVAL_*` and `setPeriodic`
  are all asserted absent. The only thing that schedules is a drain that found durable work.
* **Cancellation** calls `alarms.cancel(pending)` *and* `pending.cancel()`, so a later
  cancel/schedule pair cannot resurrect a stale intent. `stopAutoSync()` → `cancelOpportunity()`
  drops the wake-up only; durable operations stay in the outbox owned by their original user.
* **Duplicates** are *not* deduplicated natively — no seen-id set exists, which a test
  asserts. A duplicate callback is routed straight into the existing coordinator and joins the
  in-flight drain via `_inflight`/`_queued`. No second guard was added and the existing
  coalescing was not modified; no defect was found in it.
* **Engine binding.** `MainActivity` binds the channel with `applicationContext` (so the alarm
  outlives the Activity) and unbinds in `cleanUpFlutterEngine`. `detach` compares identity, so
  a late teardown of an old engine cannot unbind a newer one.
* **Broadcast lifetime.** The receiver uses `goAsync()` so the wakelock is held while Dart
  starts, finishes on both the acknowledge and the throw path, and is bounded at 8s — under
  the ~10s soft ANR limit. The *drain* is not bounded by it; it continues on the live engine's
  event loop.

---

## 9. Evidence, separated by kind

### A. Dart behavioural tests — 44 new, all green in CI

`test/android_background_sync_test.dart` (17) and `test/background_sync_bridge_test.dart` (27)
drive the **real** `SyncService`, the **real** `BackgroundSyncCoordinator`, the **real**
`runSyncNow` and the **real** `_drain`, with the two A.5 collaborators faked.

All ten behaviours A.8 required are covered: background source · routes into the existing
coordinator · no second drain path · generation/session gates active · logged-out blocks ·
active session uses the right owner and auth version · provenance retained · the label reaches
the claim boundary that writes `sync_attempts` · foreground unchanged · duplicates coalesce.

### B. Native Android source verification — 47 tests

`tests/security/test_mobile_android_background_producer.py` reads the Kotlin, the manifest, the
ProGuard rules and the Dart bridge **with comments stripped**, so prose can never satisfy an
assertion.

**This is source verification, not runtime verification, and is never described otherwise.**

### C. CI

`Android runtime/build verification unavailable in current CI.`

The only mobile job is `flutter test`. There is no Android SDK, no Gradle, no emulator and no
`flutter build apk` step. Stronger still: **the Android project cannot be configured from a
clean checkout**, because `gradlew`, `gradle-wrapper.jar` and `android/local.properties` are
all gitignored and absent while `settings.gradle` asserts `flutter.sdk`.

Raising this would require a Flutter SDK in the sandbox or a new CI job with an Android SDK.

### D. Device / runtime verification

**None performed. No device or emulator is available.** Nothing below the Dart layer has been
observed executing: no alarm has been seen to fire, no broadcast delivered, no Doze behaviour
measured, and the APK has not been built.

---

## 10. Numbers

| Gate | Result |
|---|---|
| CI run `37313004541` (`d0bf00f`) | **4/4 green** |
| Flutter unit tests (CI) | **587 passed** (543 + 44 new) |
| Security and regression suite (CI) | **2235 passed / 926 subtests / 0 skipped** (2188 + 47) |
| PHP syntax | **308 files, 0 errors** |
| Migration numbering | **57 migrations, unique** |
| F-20 lifecycle harness | **`E2E-VERDICT: PASS (all: 67 checks)`** |
| F-20 runtime suite | **12/12** |
| Mutation harness | **70 attempted / 70 caught / 0 survived / 0 skipped**; anchors unique 70/70 |
| A.8 mutations | M61–M70, all caught |
| Mobile schema version | **37, unchanged** |
| New dependencies | **0** |
| New permissions | **0** |

Local full-suite runs show 5 failures in `tests/security/test_comm_e2e.py`. These were
reproduced identically at the A.7 baseline commit `2cfbf9e` in a clean worktree: they are
pre-existing and environmental (the comm E2E harness needs a provisioned schema this sandbox
does not build) and are unrelated to A.8, which touches no PHP. CI, which provisions that
database, is green.

---

## 11. Known limitations

1. **No background sync after process death, force-stop or reboot.** §7. This is the chosen
   mechanism's deliberate boundary, not a bug.
2. **No OS-level network constraint.** AlarmManager cannot express one; Dart performs the
   final check. A future JobScheduler or WorkManager phase could.
3. **No Android build, lint, unit or instrumentation verification anywhere.** §9C.
4. **Doze delivery is best-effort and may be late**, and `setAndAllowWhileIdle` is OS
   rate-limited.
5. **The `sync_attempts` row for a background drain is not observed in Dart.** No database is
   opened (`sqflite_common_ffi` is not a dev_dependency and pubspec is frozen by F-19). What
   Dart proves is that the background label reaches the claim boundary; the row itself is
   proven against real SQLite by the A.7 suite and pinned by mutations M1–M3 and M42–M60.
6. **A cold-delivered wake-up costs one short wasted process start.** Bounded and one-shot.
7. Pre-existing and unchanged: hymn rows without `client_op_id` stay unledgered; B-2 hymn
   execution-path bypass; `ConnectivityService` has no test hook.

---

## 12. Scope compliance

Nothing was redesigned: `SyncService`, `BackgroundSyncCoordinator`, `HymnStore`, retry, lease,
idempotency and authentication are untouched — `sync_service.dart`, `sync_execution.dart`,
`local_db.dart` and `hymn_store.dart` have **zero diff** against the A.7 baseline.

No Sync Center UI, fleet analytics, server-side queue, Redis, Firebase, Supabase or backend
service. No WorkManager. No second scheduler abstraction, drain or sync engine. No schema or
DB-version change. No unrelated dependency or feature change. No production database was
touched.

13 files changed: 2 Kotlin (new), 1 Kotlin (modified), 1 manifest, 1 ProGuard, 2 Dart services
(new), 1 `main.dart`, 2 Dart tests (new), 1 Python suite (new), 1 mutation harness, 2 docs.
