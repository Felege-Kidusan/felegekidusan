# S3 / A.8 — Phase 1: Android background sync producer — architecture decision record

**Status: PHASE 1 COMPLETE (reconnaissance). PHASES 2–6 BLOCKED pending two explicit decisions.**
**No production code was modified by this phase. This document is the only artefact.**

A.8 asks for the first real producer of `SyncExecutionSource.background`: an Android OS
scheduler that wakes the app and drives the *existing* `BackgroundSyncCoordinator` →
`SyncService.runSyncNow` → `_drain` → durable outbox → `sync_attempts`.

Phase 1 was told to choose the mechanism on evidence rather than familiarity, to revisit
F-19 explicitly, and to stop before proceeding if the chosen mechanism implies a parallel
database connection model. All three of those clauses fired. This document records what
was found and what must be decided before any Kotlin is written.

---

## 1. What the Dart side already provides (unchanged, correct, ready)

A.1–A.7 already built the entire consumer half. Nothing here needs redesign.

| Element | Location | Shape |
|---|---|---|
| `BackgroundSyncScheduler` | `lib/services/sync_execution.dart` | `ensureScheduled(BackgroundSyncRequest)`, `cancel()` |
| `BackgroundSyncRequest` | same | `notBefore: DateTime?`, `requiresNetwork: bool`, `uniqueWorkName = 'fkss.sync.drain'`; **no payload, no token, no user id — enforced by construction** |
| `NoopBackgroundSyncScheduler` | same | the installed default; background sync simply never happens |
| `BackgroundSyncCoordinator` | same | `requestOpportunity({notBefore, requiresNetwork})`, `execute(SyncExecutionSource)`, `cancelOpportunity()` |
| Producer wiring | `sync_service.dart:398` | retryable drain outcome → `requestOpportunity(notBefore: nextAttempt)` |
| Cancellation wiring | `sync_service.dart:144` | `stopAutoSync()` → `cancelOpportunity()` (cancels the *wake-up*, never the durable work) |
| Execution | `sync_service.dart:189` | `_coordinator.execute(source)` → `runSyncNow` → `_drain(generation, force, source)` |
| Provenance | A.7 | `_drain` → `pushPending(source:)` → `claimNextHymnOperation(executionSource:)` → `_openSyncAttempt` |

**Consequence: the missing piece is genuinely only the platform wake-up.** The contract the
native side must satisfy is two methods wide, carries no credentials, and was deliberately
designed in A.1 to be implementable by any OS scheduler.

The A.1 docstring suggests WorkManager (`enqueueUniquePeriodicWork`). A.8 explicitly
reopens that choice, and Phase 1 does **not** ratify it — see §5.

---

## 2. Reconnaissance findings (all read-only, all verified in this phase)

### 2.1 Flutter / dependency surface
* `pubspec.yaml`: `name: fkss_app`, `1.5.1+25`, `sdk >=3.3.0 <4.0.0`, `flutter >=3.27.0`.
* `pubspec.lock`: **130 packages**; `sdks:` records `dart >=3.12.0`, `flutter >=3.44.0`.
* **Absent from the lockfile:** `workmanager`, `android_alarm_manager_plus`,
  `flutter_background_service`. There is **no background-scheduling package in this app.**
* **Present and relevant:** `audio_service 0.18.19` + `just_audio_background` (a *media
  playback* foreground service), `connectivity_plus`, `sqflite`, `shared_preferences`,
  `flutter_secure_storage`, `path_provider`, `permission_handler`.

### 2.2 F-19, root cause pinned exactly
`url_launcher: ^6.3.2` is a declared direct dependency of `pubspec.yaml` and occurs
**zero times** in `pubspec.lock`. Therefore `flutter pub get --enforce-lockfile` cannot
succeed. CI works around this by running plain `flutter pub get`, so resolution in CI is
bounded by `pubspec.yaml`, **not** by the committed lockfile. The lockfile was additionally
produced by a far newer toolchain (flutter >=3.44.0) than the manifest floor (>=3.27.0).

**F-19 is still open and it is directly in A.8's path**: any dependency addition would have
to regenerate this already-inconsistent lockfile.

### 2.3 Android project
* Single `app` module; namespace/applicationId `com.arkeonethiopia.fkss`.
* AGP **8.11.1**, Kotlin **2.2.20**, Gradle wrapper descriptor **8.14.3**, Java/Kotlin target **1.8**.
* **`minSdk` / `targetSdk` / `compileSdk` are NOT pinned** — all three delegate to
  `flutter.minSdkVersion` / `flutter.targetSdkVersion` / `flutter.compileSdkVersion`.
  *The effective API levels are therefore a property of whichever Flutter SDK performs the
  build, not a fact recorded in this repository.* Any claim about Android version behaviour
  in A.8 must name the assumed levels rather than assert repository values.
* `ndkVersion 26.1.10909125` pinned deliberately — do not touch.
* Release build runs **R8**: `minifyEnabled true`, `shrinkResources true`, `proguard-rules.pro`.
  `proguard-rules.pro` keeps `io.flutter.**`, the sqflite plugin, and the audio_service /
  media3 classes. **Any new manifest-declared receiver or Dart-callback plumbing would need
  its keep rules reviewed before a release build could be trusted.**
* Release signing silently falls back to debug keys when `key.properties` is absent
  (fleet-compat behaviour — do not disturb).

### 2.4 AndroidManifest
* Permissions: INTERNET, WAKE_LOCK, FOREGROUND_SERVICE, FOREGROUND_SERVICE_MEDIA_PLAYBACK,
  POST_NOTIFICATIONS, CAMERA, USE_BIOMETRIC, ACCESS_NETWORK_STATE, REQUEST_INSTALL_PACKAGES.
* **Absent: `RECEIVE_BOOT_COMPLETED`, `SCHEDULE_EXACT_ALARM` / `USE_EXACT_ALARM`.**
  Reboot reconstruction or exact alarms would each require adding one.
* `.FkssApplication`; single `.MainActivity` (`singleTop`, exported, `flutterEmbedding` v2).
* `com.ryanheise.audioservice.AudioService` (`exported=false`, `foregroundServiceType=mediaPlayback`)
  and an exported `MediaButtonReceiver` already exist — so a manifest-declared service and
  receiver are **existing precedent** in this app, not a new structural concept.

### 2.5 Native sources — complete inventory
Exactly two Kotlin files:
* `FkssApplication.kt` — an uncaught-exception trap that appends to `fkss_bootstrap_error.log`
  and delegates to the platform handler. No Flutter engine interaction at all.
* `MainActivity.kt` — extends `AudioServiceFragmentActivity`; registers three MethodChannels
  **inside `configureFlutterEngine`**: `fkss.app/updater`, `fkss.app/app_lock`, `fkss.app/device`.

**Therefore: every existing platform channel in this app is bound to the Activity's engine and
exists only while the Activity exists. There is no headless engine, no cached engine, no
`PluginUtilities`, no `@pragma('vm:entry-point')` callback, and no background native code of
any kind** (verified by grep across `lib/` and `android/`).

### 2.6 Session and authorisation wiring — the part that constrains everything
`SessionService.bootstrap()` is what installs the gates:

```
_api.sessionGenerationProvider          = () => _generation;
SyncService().activeSessionGate         = () => isActive;
SyncService().sessionGenerationProvider = () => _generation;
HymnStore().activeSessionGate / sessionGenerationProvider = ...
CommOutboxService / NotificationService / CatalogService / WarmStore = ...
```

These are **instance fields on Dart singletons, installed at bootstrap**. `ApiService.isLoggedIn`
is `_token != null && _refreshToken != null` — in-memory state, populated by bootstrap from
durable storage.

**Consequence of first-order importance:** a Dart isolate that has not run
`SessionService.bootstrap()` has `activeSessionGate == null`, `generation == 0`, and
`isLoggedIn == false`. It cannot perform an authenticated sync, and it cannot evaluate the
generation/owner gates A.8 requires it to honour. **Any background execution path must run
the real bootstrap, or it is not a background sync — it is a no-op that reports success.**

### 2.7 Verification capability — the decisive constraint

| Capability | Local sandbox | CI (`.github/workflows/backend-checks.yml`) |
|---|---|---|
| `flutter` / `dart` | **ABSENT** | Flutter **3.44.9** (`flutter-tests` job) |
| `flutter test` | unavailable | **YES** — the only mobile gate that runs |
| Android SDK / `sdkmanager` / `adb` | **ABSENT** | **ABSENT** |
| Gradle | **ABSENT** (`gradle` not installed) | **ABSENT** |
| `gradlew`, `gradlew.bat`, `gradle-wrapper.jar` | **not in the repository** (gitignored) | same |
| `android/local.properties` | **not in the repository** (gitignored) — and `settings.gradle` *asserts* `flutter.sdk` is set | same |
| `GeneratedPluginRegistrant.java` | gitignored (generated at build time) | same |
| `flutter build apk` / lint / unit / instrumentation | no | **no such step exists** |
| Emulator / physical device | no | no |

The `flutter-tests` job comment states it plainly: *"No emulator, no Android SDK, no secrets,
no network fixtures and no database: every test under test/ is pure Dart or a widget test."*

**`Android runtime/build verification unavailable in current CI`.**

And stronger than that: **the Android project cannot even be *configured* from a clean
checkout**, because the Gradle wrapper and `local.properties` are both absent and
`settings.gradle` asserts on the latter. Kotlin written today could not be compiled, linted,
or type-checked by any gate available to this project — not locally, not in CI.

---

## 3. Mechanism evaluation

All candidates were assessed against the criteria A.8 named. The three columns that decided
it are *dependency impact*, *whether it can reach the existing coordinator*, and *whether it
forces a second isolate*.

| Mechanism | New dep? | Reboot | Doze | Network constraint | Reaches existing coordinator | Second isolate / second `LocalDb`? |
|---|---|---|---|---|---|---|
| **A1 — AlarmManager + receiver → *existing* engine** | **none** | no | yes (`setAndAllowWhileIdle`) | must be checked in Dart (already is) | **yes, in the existing isolate** | **NO** |
| **A2 — AlarmManager + receiver → *headless* engine** | none | only with `RECEIVE_BOOT_COMPLETED` + reconstruction | yes | Dart-side | yes, in a new isolate | **YES — unavoidable** |
| JobScheduler (native) | none | `setPersisted` (needs `RECEIVE_BOOT_COMPLETED`) | yes | native `setRequiredNetworkType` | only via a headless engine | **YES** |
| `workmanager` (pub) | **YES** | yes | yes | yes | yes, in a new isolate | **YES** |
| `android_alarm_manager_plus` (pub) | **YES** | yes (`rescheduleOnReboot`) | yes | no native constraint | yes, in a new isolate | **YES** |
| Foreground service | none | no | n/a | n/a | yes | depends | 
| Reuse `audio_service` | none | — | — | — | — | — |

Rejections, with reasons:

* **Foreground service — rejected.** A.8 forbids one unless genuinely required. A sync drain
  is not user-visible ongoing work; it would require a permanent notification and (on modern
  Android) a `foregroundServiceType` that does not honestly describe syncing. Disproportionate.
* **Reuse `audio_service` — rejected.** It is declared `foregroundServiceType="mediaPlayback"`
  and only runs while audio plays. Driving sync through it would misdeclare the service type
  and tie sync availability to mezmur playback. This is dependency reuse in name only.
* **`android_alarm_manager_plus` — rejected even if dependencies were free.** It offers no
  native network constraint, so every wake-up would spin up a full Dart isolate merely to
  discover there is no connectivity.
* **`workmanager` — strongest on paper, blocked in practice.** It is the only candidate with
  first-class reboot persistence *and* native network constraints *and* battery-aware
  backoff. It is also **forbidden by default by a standing S2 scoping decision**, and adding
  it runs straight into F-19 (§4).
* **JobScheduler (hand-written) — technically the best no-dependency fit** (native network
  constraint, `setPersisted` reboot survival, Doze-aware batching), but it still needs a
  headless engine to reach Dart, so it inherits the §4.2 blocker while costing considerably
  more Kotlin than A2.

---

## 4. The two blockers

### 4.1 Blocker 1 — F-19 makes *any* new dependency unsafe right now

A.8 requires a verdict of (A) no new dependency, (B) an existing one suffices, or (C) one is
genuinely required.

**Verdict: (A) — no new dependency is required.** A1/A2/JobScheduler are all implementable
with the Flutter embedding's own APIs (`FlutterEngine`, `DartExecutor.DartCallback`,
`FlutterCallbackInformation`, `GeneratedPluginRegistrant`), which ship with Flutter itself
and are not pub packages. (B) is false: no package currently in the lockfile can schedule
general background work.

This verdict matters because (C) would have been **unexecutable in this environment**:

* Adding a package requires regenerating `pubspec.lock`, which requires a local Flutter SDK.
  **There is none in this sandbox.**
* The committed lockfile is already inconsistent with the manifest (F-19), so a regeneration
  would be a large, unreviewable diff entangled with a pre-existing defect.
* CI deliberately does not use `--enforce-lockfile`, so CI would *not* catch a lockfile that
  stayed wrong.

So the dependency question resolves cleanly in favour of native code — and that is a genuine
engineering argument, not a rationalisation: it is the one option that leaves F-19 untouched.

### 4.2 Blocker 2 — every mechanism that survives process death forces a second isolate

This is the one A.8 named as a stop condition, and it fired.

When Android wakes a dead app process, there is by definition no running Dart isolate. Every
Flutter background mechanism — `workmanager`, `android_alarm_manager_plus`, and the
hand-written A2/JobScheduler variants alike — resolves this the same way: it spins up a
**new, headless `FlutterEngine` with its own Dart isolate**. That isolate gets:

* its own `LocalDb()` singleton → **a second sqflite connection to the same database file**;
* its own `SyncService()` → `activeSessionGate` and `sessionGenerationProvider` **unset**;
* its own `ApiService()` → **`isLoggedIn == false`** until bootstrap runs;
* its own `SessionService()` → `_generation == 0` until `bootstrap()` runs.

Two consequences, both material:

1. **The isolate must run the real `SessionService.bootstrap()`** or it cannot satisfy A.8's
   session/owner/generation requirements at all. That is a full app bootstrap executed from a
   broadcast receiver — plugin registration, secure storage, DB open, credential restore.
2. **It is a parallel database connection model.** While the UI isolate is alive, two sqflite
   connections may drain concurrently. The in-memory `_inflight` / `_queued` coalescing that
   A.2/A.5 proved **does not span isolates**; cross-isolate safety would rest entirely on the
   DB-level claim (`affected != 1` → `StateError`) that A.7 verified against real SQLite, plus
   SQLite's own locking. That claim is genuinely atomic — but *it has never been exercised by
   two concurrent connections in any test in this repository*, and `database is locked`
   behaviour under sqflite's default journal/busy-timeout settings is unverified here.

A.8's instruction for exactly this situation is to stop and document before proceeding, and
not to add a second Dart guard or modify the existing coalescing unilaterally. Hence this
document.

**The A1 variant is the only option that avoids blocker 2 entirely** — it invokes the
coordinator in the *existing* isolate over a MethodChannel, so there is one `LocalDb`, one
`SyncService`, one already-bootstrapped session, and the existing coalescing remains fully
authoritative. Its honest limitation is equally stark: **it does nothing once the process is
dead.** It covers *backgrounded and Doze-suspended* (where Dart `Timer`s are frozen but
`setAndAllowWhileIdle` still fires — a real and currently-unserved gap), and it covers
nothing after force-stop, process death, or reboot.

---

## 5. Recommendation

**Do not write Kotlin yet.** Two decisions belong to the user, not to this phase.

**Decision 1 — scope of the first producer.**
* **A1 (warm-invoke only)** — AlarmManager + `exported=false` BroadcastReceiver → existing
  engine → MethodChannel → `coordinator.execute(background)`. Zero dependencies, zero new
  permissions, ~60 lines of Kotlin, no second isolate, no second DB connection, existing
  coalescing stays authoritative. Serves backgrounded/Doze-suspended processes only.
  **This is the smallest production-grade mechanism that honestly delivers a real
  `SyncExecutionSource.background` producer, and it is the only one that clears both blockers.**
* **A2 (cold-invoke, headless engine)** — adds survival across process death and (with
  `RECEIVE_BOOT_COMPLETED`) reboot, at the cost of accepting the parallel-isolate/parallel-DB
  model in §4.2 and the bootstrap-from-receiver path in §2.6.
* **A1 now, A2 later** — ship the verified-safe half first; treat the headless engine as its
  own phase with its own cross-isolate concurrency evidence.

**Decision 2 — what "verified" may mean for the native half.**
Whatever is chosen, with today's tooling the Kotlin cannot be compiled or run by any gate.
The achievable classification is at best
**`PASS — SOURCE VERIFIED, BUILD AND RUNTIME UNVERIFIED`**, which is weaker than A.8's
`PASS — BUILD/SOURCE VERIFIED, RUNTIME LIMITED` because *build* verification is unavailable
too. Raising it would require either a Flutter SDK in this sandbox or a new CI job
(`flutter build apk --debug`, which needs an Android SDK and a Gradle wrapper that the
repository does not currently contain).

The Dart half — the `BackgroundSyncScheduler` implementation, the entry point, provenance,
session gating, coalescing — **is fully verifiable today** by the existing CI `flutter test`
job and by the Python mutation harness, and all ten required Dart tests are achievable
regardless of which decision is taken.

---

## 6. Phase 1 compliance statement

* No production code modified; no `pubspec.yaml` / `pubspec.lock` edit; no manifest edit;
  no schema or DB-version change; no new permission; no dependency added.
* F-19 revisited explicitly and resolved as **(A) no new dependency required** — §4.1.
* Parallel-DB / second-`LocalDb` implication detected and escalated rather than absorbed — §4.2.
* Android build/runtime verification status stated exactly:
  **`Android runtime/build verification unavailable in current CI`** — §2.7.
* No Android guarantee asserted. Backgrounded, Doze-suspended, force-stopped, process-killed
  and rebooted are treated as distinct states throughout — §3, §4.2, §5.
