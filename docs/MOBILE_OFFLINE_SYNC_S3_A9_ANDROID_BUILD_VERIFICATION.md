# S3 / A.9 — Android build verification

**Status: PASS — ANDROID BUILD VERIFIED.**
Authoritative evidence: GitHub Actions run `37338056454` on commit `8cdab50`,
5/5 jobs green, including a debug APK compiled from a clean checkout.

A.8 shipped a native Android background-sync producer that nothing had ever
compiled. The dev sandbox has no Flutter SDK, no Gradle, no Android SDK and no
`adb`, and this repository's only mobile CI job was `flutter test`, which
compiles Dart and never touches Kotlin. Every A.8 claim about
`BackgroundSyncProducer.kt` and `BackgroundSyncReceiver.kt` was therefore
source-verified only, and the A.8 report said so in those words. A.9 closes
that gap and nothing else: no A.8 source file was modified.

## 1. Why a clean checkout could not build

Four things are absent from a fresh clone, all of them correctly gitignored by
the stock `flutter create` template in `android/.gitignore`:

| Missing | Consequence |
|---|---|
| `android/gradlew`, `android/gradlew.bat` | no Gradle entry point |
| `android/gradle/wrapper/gradle-wrapper.jar` | `Could not find or load main class org.gradle.wrapper.GradleWrapperMain` |
| `android/local.properties` | `settings.gradle` asserts `flutter.sdk != null` and aborts configuration |

But the decisive blocker was none of those. It was that **no CI job had an
Android toolchain at all** — no JDK pinned, no Android SDK components, no
Flutter SDK outside the test job. The missing wrapper was a symptom; the
absence of an Android build was the cause.

## 2. The Gradle wrapper is deliberately NOT committed

This was verified against Flutter 3.44.9's own source rather than assumed.
`GradleUtils.getExecutable()` calls `injectGradleWrapperIfNeeded()`, which:

```dart
copyDirectory(
  _cache.getArtifactDirectory('gradle_wrapper'), directory,
  shouldCopyFile: (src, dest) => !dest.existsSync(),   // never overwrites
  onFileCopied: (src, dest) => _operatingSystemUtils.makeExecutable(dest),
);
...
if (propertiesFile.existsSync()) {
  return;                       // our pinned Gradle version survives
}
```

So `flutter build apk` supplies `gradlew`, `gradlew.bat` and
`gradle-wrapper.jar` from its own cache on every build, and because
`gradle-wrapper.properties` **is** committed, the early return preserves the
project's pinned **Gradle 8.14.3** — Flutter's `templateDefaultGradleVersion`
(9.1.0 in this SDK) is never substituted. CI confirms `gradle-8.14.3-all.zip`
is what actually ran.

Committing a binary `gradle-wrapper.jar` obtained out-of-band would be strictly
worse supply-chain posture for zero benefit. The job's first step asserts the
checkout really is wrapper-less, so if this ever stops being true the reasoning
is forced back into review rather than silently rotting.

## 3. Versions — all derived from the repository, none upgraded

| Component | Version | Where it comes from |
|---|---|---|
| Flutter | 3.44.9 | matches the existing `flutter-tests` job |
| Dart | 3.12.2 | bundled with the above |
| JDK | Temurin 17 | `errorJavaMinVersionAndroid = 17.0.0`; AGP 8.11.1 requires 17+ |
| Gradle | 8.14.3 | `gradle-wrapper.properties` (committed) |
| AGP | 8.11.1 | `android/settings.gradle` |
| Kotlin | 2.2.20 | `android/settings.gradle` |
| compileSdk / targetSdk | 36 / 36 | `flutter.compileSdkVersion` in flutter_tools 3.44.9 |
| minSdk | 24 | `flutter.minSdkVersion` in flutter_tools 3.44.9 |
| NDK | 26.1.10909125 | pinned in `app/build.gradle` with a documented rationale |

Nothing was bumped to make CI easier. The NDK pin in particular was respected
rather than "fixed": `app/build.gradle` records that P47 raised it to
27.0.12077973 and the build broke, so CI installs 26.1.10909125 explicitly
instead of inheriting whatever the runner image ships.

## 4. F-19 reproduced under execution

The first run failed on the lockfile gate, which turned out to be a finding
rather than a configuration error. **This repository cannot be built from its
own committed `pubspec.lock`.** `flutter pub get` exits 0 but rewrites it,
reporting `Changed 12 dependencies`:

* `+ url_launcher 6.3.3` and its 7 platform packages — declared in
  `pubspec.yaml`, absent from `pubspec.lock`. This *is* F-19.
* `< matcher 0.12.20→0.12.19`, `meta 1.19.0→1.18.0`,
  `test_api 0.7.12→0.7.11`, `vector_math 2.4.2→2.2.0` — SDK-constrained
  packages downgraded to what Flutter 3.44.9 pins, which proves the committed
  lockfile was written by a **newer** Flutter than `pubspec.yaml` declares.

This is pre-existing: the `flutter-tests` job has resolved exactly this way
since it was added, it simply never checked. A.9 does not repair it. The
runner is ephemeral, nothing is committed from CI, and `pubspec.yaml` and
`pubspec.lock` remain byte-identical in git.

The gate was therefore changed from "the lockfile must be unchanged" — an
assertion that can never hold here and would only block the phase — to a
tighter one: the manifest must be untouched, and resolution drift must match
that exact 12-package signature. Any other package moving fails the job. If
resolution ever genuinely fails, CI emits `F-19 blocks A.9 dependency
resolution.`

## 5. What the APK proves

`✓ Built build/app/outputs/flutter-apk/app-debug.apk` — 184,810,478 bytes,
`assembleDebug` in 346.5 s cold / 101.1 s warm.

Read back out of the artifact itself, not the build log:

* `classes.dex` contains `com.arkeonethiopia.fkss.BackgroundSyncProducer`,
  `BackgroundSyncReceiver` and `MainActivity`.
* The packaged binary manifest declares `BackgroundSyncReceiver` and
  `com.arkeonethiopia.fkss.action.BACKGROUND_SYNC`.
* The receiver is **not exported** in the merged manifest — load-bearing for
  A.8's threat model, and previously only ever checked in source where
  manifest merging could in principle have overridden it.
* 11 `uses-permission` entries vs the 9 the app declares. The two extras are
  contributed by plugin manifests during merge, not by A.8 (which changed zero
  permission lines, per `git diff 2cfbf9e..HEAD`):
  `android.permission.USE_FINGERPRINT` (biometric plugin) and
  `com.arkeonethiopia.fkss.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION`
  (auto-generated by AndroidX Core to keep dynamically-registered receivers
  private).

## 6. Second finding — F-20 was provisioned but never run

The `security-tests` job has created the `ssms_idem_e2e` database since F-20
landed, with a comment naming `tests/e2e/idempotency_lifecycle.php` as the
reason. **No step ever ran that harness.** The database was being provisioned
for nobody, and F-20's 67 checks were only ever verified by hand on a developer
machine. Structurally this is finding P again: infrastructure that reads like
coverage while verifying nothing. A.9 wires it up; CI now reports
`E2E-VERDICT: PASS (all: 67 checks)`.

## 7. What is still NOT verified

This job **compiles and packages. It does not run.** No emulator, no device, no
alarm ever fires in CI. Everything A.8 said about Android runtime behaviour
remains unverified by execution:

* that `setAndAllowWhileIdle` actually fires in Doze on real hardware;
* that the receiver reaches the live FlutterEngine and the MethodChannel round
  trip completes within the `goAsync()` budget;
* the force-stop / process-death / reboot limitations, which are design
  consequences and are not papered over.

A.9 proves the code is syntactically valid Kotlin, links against the Android
APIs it claims to use, merges into a valid manifest and packages into an
installable artifact. It does not prove the feature works on a phone.
