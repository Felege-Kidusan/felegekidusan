## 1. Result

**PASS — COLD-START IMPLEMENTED; ANDROID RUNTIME PENDING.**

A.12 adds the minimum Android process-dead path without creating a second sync
system:

`AlarmManager → BackgroundSyncReceiver → one temporary FlutterEngine/isolate → backgroundSyncMain → fkss.app/background_sync → BackgroundSyncCoordinator → SyncService.runSyncNow(source: background) → existing _drain() → production LocalDb claim/settlement → engine cleanup`

The implementation and source-contract tests are complete. Android APK
compilation and device/emulator execution could not be performed in this
workspace because no Android SDK, emulator, or device is available. Those
limitations are not represented as Android runtime evidence.

## 2. Starting Commit

`94de4e9599af7af56323cc93b7feda40696fdac6` — the published A.11 baseline.

## 3. Final Commit

The final published tip is the commit containing this report and the verified
A.12 changes. Because putting a commit hash inside the same commit would be
self-referential, the authoritative value is emitted by the exact commands in
§34: `git rev-parse HEAD` and `git rev-parse origin/main`.

## 4. Rollback Recovery

Recovery is a normal revert, not a reset or force-push. Fetch `origin/main`,
verify the remote is an ancestor of the local tip, and revert the A.12 commits
in reverse order. The clean A.11 recovery point is
`94de4e9599af7af56323cc93b7feda40696fdac6`. Push the revert commit only after
rechecking `git status --short`, `git rev-parse HEAD`, `git rev-parse
origin/main`, and ancestry. No newer remote work may be overwritten.

## 5. A.11 Baseline

A.11 remains published and unchanged as the baseline: production Dart
`LocalDb` exercised through real SQLite FFI; v36 → v37 migration; normal and
hymn claims and settlements; attempt lineage; execution source; owner and
authorization isolation; retries; leases; atomicity; and integrity. The A.11
suite passed 588 Flutter tests, with 70/70 mutations caught and 70/70 anchors
unique. A.11's Android SQLite runtime remained pending. FFI evidence is not
Android SQLite runtime evidence.

## 6. Cold-Start Architecture

AlarmManager remains only the wake-up mechanism. The receiver does not open a
database, claim work, retry, inspect credentials, or implement HTTP. If the
existing warm channel is present, the established A.8 route is used. If it is
absent, a single temporary Flutter engine is created under a lock, the bundled
Dart entry point is executed, readiness is established over the authoritative
channel, the same background method is invoked, and the engine is destroyed.
There is no permanent service, worker, WorkManager job, JobScheduler path, boot
receiver, second outbox, second retry ladder, or second drain.

## 7. Flutter Entry Point

`lib/main.dart` now contains `@pragma('vm:entry-point')
Future<void> backgroundSyncMain()`. It performs only headless initialization:
`WidgetsFlutterBinding.ensureInitialized()`, installation of the existing
`BackgroundSyncBridge`, and `SessionCoordinator().bootstrap()`. It does not call
`runApp` or install a foreground scheduler. It sends `backgroundReady` with the
bootstrap/session readiness result. Bootstrap failure sends `ready: false` and
never becomes a successful sync.

## 8. Android Engine Lifecycle

`BackgroundSyncProducer.deliver(context, onFinished)` first checks the attached
warm `MethodChannel`. With no warm channel it enters `coldLock`, refuses a
second simultaneous cold engine, constructs exactly one
`FlutterEngine(appContext, emptyArray(), true)`, and uses Flutter's automatic
plugin-registration constructor. It resolves the bundled app path through
`FlutterInjector`, executes `backgroundSyncMain`, waits for the readiness
handshake, invokes the existing background method, and awaits its callback or
bounded timeout.

## 9. Warm vs Cold Path

Warm path: `channel != null` → shared `invokeBackground` → existing
`runBackgroundSync` payload → Dart coordinator/service/drain. The Activity's
attach/detach lifecycle remains intact.

Cold path: no attached channel → one temporary engine → one cold channel →
entry point → `backgroundReady` → the same `invokeBackground` helper and the
same `runBackgroundSync` payload → callback/failure/timeout → handler removal
and engine destruction. A duplicate wake while the cold engine is occupied is
finished without creating a second engine or second isolate.

## 10. MethodChannel Contract

The authoritative channel remains `fkss.app/background_sync`.

- Dart → native scheduling remains `ensureScheduled` with the existing
  trigger-only fields (`uniqueWorkName`, `notBeforeEpochMs`, and
  `requiresNetwork`).
- Dart → native cancellation remains `cancel`.
- Native → Dart execution remains `runBackgroundSync` with
  `source: "background"` and an opaque `invocationId`.
- Cold Dart → native readiness is `backgroundReady` with `ready: true` or
  `ready: false`.
- Native completion is callback-based. Success, Dart error,
  `notImplemented`, bootstrap failure, engine creation failure, missing
  channel, and timeout all complete deterministically; failure is never
  rewritten as durable success.

## 11. SyncService/Coordinator Reuse

The bridge remains the only Dart channel execution route. It parses and
strictly accepts `SyncExecutionSource.background`, calls the existing
`BackgroundSyncCoordinator`, and reaches `SyncService.runSyncNow(source:
background)`. No native business logic was added and no second `_drain()` was
created.

## 12. LocalDb / SQLite Result

The production `LocalDb` remains the database implementation and existing
outbox remains the schema. Its `openDatabase` call now uses
`singleInstance: false` so a warm UI engine and a temporary cold engine can
hold independent production connections to the same SQLite file; SQLite
transactions and the existing claim/lease protocol remain authoritative. No
new database file, schema, migration, outbox, or retry mechanism was added.

The new Dart test uses production `LocalDb` in separate Dart isolates with
`sqflite_common_ffi` against one real SQLite database. It verifies durable
rows, `sync_state`, `synced`, attempt count, attempt lineage, owner and
authorization values, source, and `PRAGMA integrity_check`. This is local FFI
concurrency evidence only, not Android SQLite runtime evidence.

## 13. Foreground + Background Concurrency

`test/a12_cold_start_concurrency_test.dart` holds a foreground production claim
in one isolate, opens the same production database in a second cold/background
isolate, lets the existing recovery path interrupt/recover attempt 1, and
settles attempt 2 as background. A third production `LocalDb` inspector verifies
the durable result. The observed result is two synced rows, attempt 1
`INTERRUPTED`, attempt 2 `COMPLETED`, attempt numbers 1 and 2, distinct attempt
UIDs, shared client operation identity, preserved entity reference, and
`integrity_check = ok`.

## 14. Attempt Lineage

The test proves one logical client operation has two durable attempts rather
than two operations: attempt 1 is the foreground owner and is interrupted by
recovery; attempt 2 is the background owner and completes. Each attempt has a
non-empty distinct `attempt_uid`; the client operation identity and entity
reference are preserved; the attempt number increments exactly once. No native
code creates or edits lineage.

## 15. Execution Source

The only cold and warm execution provenance sent by native code is the existing
`SyncExecutionSource.background` value, encoded as `"background"`. Dart rejects
other provenance values. The test asserts the foreground interrupted attempt
and background completed attempt retain their respective durable source values.

## 16. Owner/Authorization Isolation

The coordinator and `LocalDb` gates are reused unchanged. The concurrency test
uses owner 701, authorization version 8, and runtime generation 41 and verifies
those values on both ledger attempts. Existing tests retain no-session, active
session, owner, authorization-version, and generation-at-wake-up coverage.
The cold engine adds no authorization bypass and transports no credentials or
operation payload through native code.

## 17. Failure Handling

Bootstrap failure sends `ready: false` and skips execution. Missing engine,
missing channel, Dart error, `notImplemented`, and timeout all finish through
the cleanup path. The native completion callback is protected by
`AtomicBoolean` and receiver completion is exactly once. The Dart bridge and
existing `SyncService`/`LocalDb` remain responsible for durable retry and
settlement decisions; a timed-out or failed wake-up is not reported as a
successful durable sync.

## 18. Receiver Lifecycle

`BackgroundSyncReceiver` remains non-exported and action-gated. It calls
`goAsync()`, passes the receiver `Context` to the producer, and finishes on
producer completion or exceptional failure. The bounded 8-second broadcast hold
prevents an unbounded receiver lifetime. Warm work may continue on the live
engine event loop after the broadcast hold; cold timeout destroys its temporary
engine and is a failure.

## 19. Engine Cleanup

`finishCold` clears the cold engine, channel, callback, and watchdog under the
cold lock; removes the cold channel handler; removes the watchdog; destroys the
engine inside a guarded cleanup block; and invokes the receiver callback once.
This cleanup is reached for callback success, Dart error, missing channel,
bootstrap-not-ready, engine-creation failure, and timeout.

## 20. Android Runtime Verification

**Pending.** No Android SDK, emulator, or physical device was available. The
attempted command was `flutter build apk --debug`; Flutter stopped with
`[!] No Android SDK found. Try setting the ANDROID_HOME environment variable.`
No Android APK build, AlarmManager delivery, process-dead receiver execution,
Flutter engine execution, Android `sqflite`, or device log evidence is claimed.

## 21. Test Results

- `python -m pytest tests/security/test_mobile_android_background_producer.py
  tests/security/test_mobile_android_cold_start.py
  tests/security/test_mobile_background_sync_core.py -q`: **131 passed**.
- `flutter test` with Flutter 3.44.9 / Dart 3.12.2: **589 tests passed**.
  This is the A.11 588-test baseline plus the A.12 production SQLite
  concurrency test.
- Focused A.12 concurrency test: **1 passed**.
- `flutter pub get --enforce-lockfile`: passed; no dependency upgrade was
  made.
- Dart formatting and Python compilation checks: passed.

## 22. Mutation/Anchor Results

The original A.11/A.8 mutation harness remains green at **70/70 mutations
caught, 0 survived, 70/70 anchors unique**. It was extended with A.12
entry-point, engine, automatic-plugin-registration, readiness, cleanup, and
LocalDb connection mutations. The extended run reports **77/77 mutations
caught, 0 survived, 77/77 anchors unique**. Mutation runs restore every source
file byte-for-byte.

## 23. A.8 Regression

A.8 warm-path behavior remains covered by the existing Android producer,
background bridge, scheduler, and coordinator tests. The full 589-test Flutter
suite and 131 focused Python source-contract tests passed. The warm attached
engine still invokes the same channel and background method.

## 24. A.9 Regression

No A.9 server API, outbox contract, authentication model, or retry behavior was
modified. The full Flutter regression suite passed. A.9 evidence remains the
published evidence for its own scope and is not relabeled as Android runtime
evidence.

## 25. A.10 Regression

No A.10 schema, server, UI, audio, or public hymn behavior was modified. The
full Flutter regression suite passed, including the existing hymn and local
storage tests. A.10 evidence remains separate from the A.12 Android runtime
classification.

## 26. A.11 Regression

The A.11 production FFI LocalDb runtime test passed inside the full suite along
with the new multi-isolate concurrency test. The v36 → v37, claim/settlement,
lineage, retry, lease, isolation, authorization, and integrity baseline remains
intact. A.11's 588-test and 70/70 mutation results are preserved; A.12's 589
and 77/77 results are additive. Neither is Android runtime evidence.

## 27. CI Verification

No remote CI job or Android runner was available to this agent. Local
CI-equivalent verification passed for the focused Python contracts, the full
Flutter suite, `pub get --enforce-lockfile`, formatting, mutation/anchor gates,
and the real SQLite FFI concurrency test. Android CI/build verification remains
pending with the runtime.

## 28. Dependencies

No dependency was added or upgraded. Existing Flutter engine APIs are used:
`FlutterEngine`, automatic plugin registration, `FlutterInjector`,
`DartExecutor.DartEntrypoint`, and `MethodChannel`. Existing package versions
and lockfile remain pinned. Existing Android build pins remain untouched:
compile/target SDK 36, min SDK 24, NDK 26.1.10909125, JDK 17, Gradle 8.14.3,
AGP 8.11.1, and Kotlin 2.2.20.

## 29. Files Changed

- `Mobile/wbws_flutter_app/lib/main.dart` — annotated cold Dart entry point.
- `Mobile/wbws_flutter_app/lib/services/android_background_sync_scheduler.dart`
  — `backgroundReady` contract constant.
- `Mobile/wbws_flutter_app/lib/services/local_db.dart` — independent
  production engine connections with `singleInstance: false`.
- `Mobile/wbws_flutter_app/android/app/src/main/kotlin/com/arkeonethiopia/fkss/BackgroundSyncProducer.kt`
  — temporary engine, plugin registration, entry point, handshake, callback,
  timeout, and cleanup.
- `Mobile/wbws_flutter_app/android/app/src/main/kotlin/com/arkeonethiopia/fkss/BackgroundSyncReceiver.kt`
  — Context-aware delivery.
- `Mobile/wbws_flutter_app/test/a12_cold_start_concurrency_test.dart` — real
  SQLite FFI multi-isolate race and lineage test.
- `tests/security/test_mobile_android_background_producer.py` — A.12-compatible
  producer contracts.
- `tests/security/test_mobile_android_cold_start.py` — cold-start source
  contracts.
- `tests/mutation/background_sync_core_mutations.py` — A.12 mutation/anchor
  cases M71–M77.
- `docs/MOBILE_OFFLINE_SYNC_S3_A12_ANDROID_COLD_START.md` — this report.

## 30. Files Intentionally Unchanged

`AndroidManifest.xml`, `FkssApplication.kt`, `MainActivity.kt`, generated
plugin registrant, Android build files and pins, `pubspec.yaml`,
`pubspec.lock`, schemas, migrations, server APIs, outbox/attempt schema,
retry ladder, lease algorithm, owner isolation, authorization-version model,
hymn ledger, unrelated UI, audio, and public hymn functionality were not
changed by A.12. The existing manifest receiver declaration and Activity
lifecycle wiring are reused.

## 31. Remaining Findings

1. Android runtime execution is pending until an Android SDK and an emulator or
   physical device are available.
2. The attempted Android build could not start because `ANDROID_HOME` and
   `ANDROID_SDK_ROOT` were unset and no SDK was installed in the workspace.
3. Doze batching, OEM process-kill behavior, real Android `sqflite`, AlarmManager
   delivery, and release/profile engine execution remain device-level work.
4. The FFI concurrency test proves the production Dart/SQLite protocol and is
   intentionally not promoted to Android runtime evidence.

## 32. Evidence Classification

- **Implemented/source evidence:** Dart entry point, authoritative channel,
  Kotlin lifecycle, receiver privacy, plugin-registration choice, timeout,
  cleanup, and no-second-architecture contracts.
- **Production Dart/SQLite evidence:** 589 Flutter tests, including real
  production `LocalDb` FFI migration/ledger/concurrency/integrity tests.
- **Mutation evidence:** 77/77 caught and 77/77 unique anchors for the extended
  local source-contract harness; A.11's 70/70 result preserved.
- **Not demonstrated:** Android SDK compilation, Android engine execution,
  Android `sqflite`, AlarmManager delivery, process-dead behavior on a device,
  and release APK runtime.

## 33. Exact Verification Commands

```sh
# Remote/ancestry gate
 git fetch origin main
 git status --short
 git rev-parse HEAD
 git rev-parse origin/main
 git merge-base --is-ancestor origin/main HEAD

# Flutter setup used for local verification
 curl -fsSL https://storage.googleapis.com/flutter_infra_release/releases/stable/linux/flutter_linux_3.44.9-stable.tar.xz -o /var/tmp/flutter_linux_3.44.9-stable.tar.xz
 PATH=/var/tmp/flutter/bin:$PATH flutter --version

# Dart dependency, formatting, and regression tests
 cd Mobile/wbws_flutter_app
 PATH=/var/tmp/flutter/bin:$PATH flutter pub get --enforce-lockfile
 PATH=/var/tmp/flutter/bin:$PATH dart format lib/main.dart lib/services/android_background_sync_scheduler.dart test/a12_cold_start_concurrency_test.dart
 PATH=/var/tmp/flutter/bin:$PATH flutter test

# Android attempt and expected environment limitation here
 PATH=/var/tmp/flutter/bin:$PATH flutter build apk --debug

# Python contracts and mutation/anchor gates
 cd ../..
 python -m pytest tests/security/test_mobile_android_background_producer.py tests/security/test_mobile_android_cold_start.py tests/security/test_mobile_background_sync_core.py -q
 python tests/mutation/background_sync_core_mutations.py

# Final Git/Push gate
 git fetch origin main
 git status --short
 test -z "$(git status --short)"
 test "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)"
 git merge-base --is-ancestor origin/main HEAD
```

## 34. Git/Push Status

Each meaningful implementation increment was fetched, ancestry-checked,
committed, and pushed before the next increment. The published history is
linear from the A.11 starting commit. The final gate must show an empty
`git status --short`, `HEAD == origin/main`, and zero unpushed commits. The
exact final SHA is therefore the output of:

```sh
git rev-parse HEAD
git rev-parse origin/main
git log -1 --oneline
```

## 35. Final A.12 Status

**PASS — COLD-START IMPLEMENTED; ANDROID RUNTIME PENDING.**

The required temporary-engine implementation, preserved entry point, existing
channel/coordinator/service/drain reuse, deterministic timeout/failure/cleanup,
production LocalDb concurrency evidence, regression suite, and mutation/anchor
coverage are complete and published. Android runtime and APK compilation stay
explicitly pending until the required Android environment is available.
