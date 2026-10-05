# S3 / A.10 — F-19 dependency / lockfile remediation

**Status: PASS — F-19 CLOSED.**

The repaired lockfile passed local and clean-checkout verification, and GitHub
Actions run `37344522032` passed all five jobs on commit `dbd8d1c`.

## 1. Original F-19 condition

The application directly declares:

```yaml
url_launcher: ^6.3.2
```

but the committed `pubspec.lock` did not contain `url_launcher` or any of its
platform implementations. With Flutter 3.44.9 / Dart 3.12.2, the original
lockfile failed the enforced-lockfile check:

```text
Would change 12 dependencies.
Unable to satisfy `pubspec.yaml` using `pubspec.lock`.
To update `pubspec.lock` run `flutter pub get` without
`--enforce-lockfile`.
Failed to update packages.
```

The command exited with status `65`.

## 2. Diagnosis

`url_launcher` is not dead code. It is directly imported and used by
`lib/screens/notifications/messages_screen.dart`:

```dart
import 'package:url_launcher/url_launcher.dart';
await launchUrl(uri, mode: LaunchMode.externalApplication);
```

The dependency is therefore correctly declared in `pubspec.yaml`. The problem
was a stale/incomplete lockfile, not an incorrect manifest constraint and not a
transitive-only dependency.

The other eleven changes reported by Pub were the seven missing URL-launcher
packages plus four SDK-constrained package selections:

```text
matcher       0.12.20 -> 0.12.19
meta          1.19.0  -> 1.18.0
test_api      0.7.12  -> 0.7.11
vector_math   2.4.2   -> 2.2.0
```

No broad upgrade was used. Pub reported newer versions as available, but the
existing manifest constraints and Flutter SDK selected the versions above.

## 3. Authoritative toolchain

The lockfile was regenerated using the exact A.9 toolchain:

```text
Flutter 3.44.9
Dart 3.12.2
Flutter revision 6b182d2c75
```

The official Flutter archive SHA-256 was verified before extraction.

## 4. Repair

The repair was generated with:

```bash
flutter pub get
```

using Flutter 3.44.9. `pubspec.yaml` was not changed.

The regenerated lockfile now contains:

```text
url_launcher                  6.3.3
url_launcher_android          6.3.33
url_launcher_ios              6.4.2
url_launcher_linux            3.2.3
url_launcher_macos            3.2.6
url_launcher_platform_interface 2.3.2
url_launcher_web              2.4.3
url_launcher_windows          3.1.6
```

There is no `sqflite_common_ffi` addition and no SQLite dependency change.

The Flutter-generated desktop registrants were updated because the now-locked
platform plugins are real application plugins. These were already tracked
repository files and are included so a clean `flutter pub get` leaves the
tracked checkout unchanged.

## 5. Enforce-lockfile verification

Local result using Flutter 3.44.9:

```text
flutter pub get --enforce-lockfile: SUCCESS
pubspec.yaml unchanged: YES
pubspec.lock unchanged: YES
tracked generated registrants unchanged: YES
```

A fresh local checkout of the remediation commit was also tested. It had no
`android/local.properties`, no Gradle wrapper scripts, and no wrapper JAR before
resolution. Both dependency commands succeeded without dirtying tracked files:

```text
clean checkout + flutter pub get --enforce-lockfile: PASS
clean checkout + flutter pub get: PASS
```

## 6. Dependency graph

`flutter pub deps --style=compact` resolves the direct package and all seven
platform implementations:

```text
url_launcher 6.3.3
url_launcher_android 6.3.33
url_launcher_ios 6.4.2
url_launcher_linux 3.2.3
url_launcher_macos 3.2.6
url_launcher_platform_interface 2.3.2
url_launcher_web 2.4.3
url_launcher_windows 3.1.6
```

## 7. CI enforcement

The existing Flutter and Android CI jobs were changed from plain `flutter pub
get` plus an allowed F-19 drift check to:

```bash
flutter pub get --enforce-lockfile
git diff --exit-code -- pubspec.yaml pubspec.lock
```

The old bounded F-19 exception has been removed. A future lockfile mismatch now
fails CI before tests or the Android build proceed.

GitHub Actions run `37344522032` completed successfully with 5/5 jobs green:

```text
Flutter unit tests: 587 passed
Security/regression: 2235 passed / 926 subtests / 0 skipped
PHP syntax: 308 files / 0 errors
F-20 lifecycle: 67/67
Migration numbering: unique
Android debug APK: built and A.8 artifact checks passed
```

The A.9 Android SDK, JDK, Gradle, AGP, Kotlin, NDK, wrapper, APK inspection,
and artifact-upload steps remain unchanged.

## 8. Verification results

Local:

```text
Flutter tests: 587 passed
Enforce-lockfile: passed
Normal pub get: passed
Clean-checkout dependency verification: passed
A.8/A.1-A.8 targeted Python tests: 110 passed
Mutation harness: 70/70 caught, 70/70 anchors unique
```

The existing A.8 source-contract and mutation checks were not changed. The
full MariaDB/PHP suites and Android APK were verified by GitHub Actions run
`37344522032` after the remediation was pushed.

## 9. Scope preservation

A.10 did not modify:

```text
BackgroundSyncProducer.kt
BackgroundSyncReceiver.kt
MainActivity.kt
AndroidManifest.xml
sync_service.dart
sync_execution.dart
local_db.dart
hymn_store.dart
```

No headless Flutter execution, second engine, second isolate, WorkManager,
SQLite runtime verification, or background-sync redesign was added.

## 10. Commits

Published commits:

```text
f19be7d fix(S3/A.10): repair Flutter dependency lockfile
661c4d0 ci(S3/A.10): enforce the committed Flutter lockfile
dbd8d1c docs(S3/A.10): record F-19 lockfile remediation
```

## 11. Remaining findings

1. `flutter pub get` reports 66 newer versions available under the existing
   dependency constraints. No upgrade was performed; this is informational.
2. The Android build logs retain the existing non-fatal NDK 27 plugin warnings;
   the pinned NDK 26.1.10909125 still produces a successful APK. No NDK change
   was made in A.10.
3. SQLite runtime verification remains explicitly out of scope for A.10.
4. Android runtime behavior remains outside the A.9 build-only verification.
