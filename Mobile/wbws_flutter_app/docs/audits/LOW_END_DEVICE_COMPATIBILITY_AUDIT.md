# FKSS Mobile — Low-End Device Compatibility Audit (P65)

**Problem:** the app installs on budget phones (itel A513W/Android 13, itel 18s,
ZTE Blade A31 Lite/Android 11, Samsung Galaxy A10/Android 11 2GB) but **opens
and closes instantly at the splash screen**. It works perfectly on the
developer's TECNO KG7h.

**Method:** forensic audit of the app's startup path, Android build config and
distribution flow + device-level hardware research + review of how Google,
Meta, Telegram and Microsoft engineer for this device class.

**Status: analysis complete. Fixes are staged in the action plan (§6); the
definitive field test (§5) takes 5 minutes on one affected phone and should be
run before code changes land.**

---

## 1 · Executive summary

The crash happens **before Dart code runs**. `main.dart` is defensively
written (every Dart-side failure lands in a recovery screen, never an instant
close), so an instant close at the splash means the process dies while the
Android layer loads the Flutter engine — native-library loading or renderer
initialization.

**Root cause #1 (high confidence): ABI mismatch.** The distributed build came
from `flutter run --release`, which builds **only for the connected device's
CPU architecture** (confirmed in flutter_tools source: `AndroidDevice
.targetPlatform` maps `arm64 → android_arm64`, single target). The developer's
TECNO KG7h is 64-bit, so the shared APK contains **only `arm64-v8a` native
libraries**. All four failing phones are **32-bit-userland devices** — the
engine's `libflutter.so` cannot be loaded, and the process dies at the splash
theme. The fix is a one-line build command change (`flutter build apk
--release` produces a universal APK with both ARM ABIs).

**Root cause #2 (will surface on some devices after #1 is fixed): the
renderer floor.** All four phones have 2015–2018-era GPUs (Mali-400,
Mali-T820, Mali-G71) with no Vulkan or broken Vulkan drivers. Modern Flutter
(3.27+, and mandatory since 3.44 on Android 10+) renders through Impeller.
Old-Mali/PowerVR/MediaTek devices are a documented crash class; Flutter's
official escape hatch is the manifest flag that falls back to the legacy GL
renderer. This must be validated on-device once the correct APK installs.

**Root cause #3 (chronic, the "1GB problem"): Android Go memory pressure.**
Two of the four phones have 1GB RAM. Even with a correct APK, low-memory
devices can kill the app during engine startup. The app's startup path is
already well-deferred; remaining work is tiering + cache budgets.

There is also a **distribution-system finding**: the in-app updater serves a
single APK to every device, so a wrong-ABI build doesn't just break shares —
it breaks *updates*. And the APK ships ~12.6MB of unused font files.

---

## 2 · The failing fleet — device forensics

| Device | SoC | CPU userland | GPU | GLES | Vulkan | Android | RAM | Verdict |
|---|---|---|---|---|---|---|---|---|
| itel A513W (= itel A18s) | MediaTek MT6580 | **32-bit only** (Cortex-A7) | **Mali-400 MP** | **2.0 only** | none | 13 (Go) | 1–2GB | Cannot load an arm64 APK; GPU is at Flutter's floor |
| itel 18s / A18-class | Unisoc SC7731E-class | **32-bit only** (Cortex-A7) | Mali-T820 | 3.1? | none | 10–11 (Go) | 1GB | Cannot load an arm64 APK |
| ZTE Blade A31 Lite | Unisoc SC9832E | 32-bit (Go build) | Mali-T820 MP1 | 3.1 | none | 11 (Go) | **1GB** | Cannot load an arm64 APK; LMK risk |
| Samsung Galaxy A10 | Exynos 7884 (64-bit SoC) | **32-bit official ROM** (arm32-binder64) | Mali-G71 MP2 | 3.2 | 1.0 (old driver) | 9→11 | 2GB | Cannot load an arm64 APK |
| *(works)* TECNO KG7h | Unisoc, arm64 | 64-bit | modern Mali | 3.2 | 1.1+ | 13/14 | 4GB+ | The device the APK was built for |

Key sourced facts:

- Galaxy A10 ships a **32-bit OS** despite a 64-bit-capable SoC — "arm32-
  binder64 … to users and app devs this is the same as arm32"; "the Galaxy
  A10 has a 32 bit official ROM and 64 bit unofficial ROMs." Budget Samsungs
  of that era (A10/A20/M10) are well-known 32-bit-userland devices.
- itel A513W is the itel A18s: **MT6580** (quad Cortex-A7, a 32-bit-only
  part) with **Mali-400 MP reporting OpenGL ES 2.0** — a 2011-class GPU.
- ZTE Blade A31 Lite: **SC9832E, 1GB RAM, Android 11 Go, Mali-T820 MP1**.
- Mali-T820 tops out at **OpenGL ES 3.1, no Vulkan**. Mali-400 is ES 2.0 only.
- All four are therefore **armeabi-v7a devices**.

## 3 · The evidence chain

**3.1 — What `flutter run --release` actually builds.** flutter_tools'
`AndroidDevice.targetPlatform` returns a single target derived from
`ro.product.cpu.abi`: an arm64 phone → `android-arm64`. `flutter run` exists
to iterate on *that* device; it is not a distribution command. The artifact it
leaves in `build/app/outputs/flutter-apk/app-release.apk` contains
`lib/arm64-v8a/libflutter.so` and `lib/arm64-v8a/libapp.so` **only**.

**3.2 — What happens on a 32-bit phone.** The launcher theme (the splash)
paints while the process starts; `MainActivity` → Flutter engine bootstrap →
`System.loadLibrary("flutter")` → no `armeabi-v7a` library present →
`UnsatisfiedLinkError` → instant close. Depending on the ROM, the *install*
step may also be rejected (`INSTALL_FAILED_NO_MATCHING_ABIS`) — several
itel/HiOS-era ROM installers are lax at install time and fail exactly at
launch, which matches the reported "installed fine, opens and closes
instantly."

**3.3 — Why it works on the developer's phone.** The APK was built for it,
literally.

**3.4 — The renderer question (next-in-line cause).** Flutter 3.27 made
Impeller the default on Android; per Flutter's docs (3.47): "On devices
running lower versions of Android or don't support Vulkan, Impeller falls
back to the legacy OpenGL renderer." Flutter 3.44 (May 2026) removed the Skia
path for Android 10+ on Vulkan-capable devices, making Impeller effectively
mandatory there. Known crash classes:

- Vulkan on old MediaTek/PowerVR/old-Mali drivers (Flutter 3.29 blog:
  black-screen and crash reports on those SoCs were why Vulkan was disabled
  for them).
- GLES 2.0-only GPUs (Mali-400) sit at the absolute floor of what Impeller's
  GL backend supports.
- Community-reported pattern: apps "open and close instantly" or black-screen
  after splash on exactly this hardware class; the documented mitigation is
  `<meta-data android:name="io.flutter.embedding.android.EnableImpeller"
  android:value="false"/>` (falls back to the legacy GL renderer).

**3.5 — RAM.** Blade A31 Lite and the itel 18s have 1GB. Android Go devices
report `isLowRamDevice=true`; the low-memory killer will terminate a process
that balloons during startup. The app already defers network/sync work past
the first frame (good), so the remaining exposure is engine+plugins+first
frame — tight but survivable on 1GB *if* nothing else is fighting for memory.

**3.6 — Repo-level findings (audit of this codebase).**

1. `android/app/build.gradle` release build: `minifyEnabled true`,
   `shrinkResources true` — good for size, and R8 misconfiguration would
   crash on *all* devices, not four specific ones. Not the cause.
2. `main()` → `JustAudioBackground.init` is try/caught; the whole bootstrap
   is guarded with a recovery screen. Dart-side failures are not instant
   closes. Not the cause.
3. **Self-update serves one APK to everyone**: `AppUpdateService` downloads
   `${apiBaseUrl}/app/download` and installs it via `ACTION_VIEW`. Whatever
   the server hosts reaches every phone — if the hosted artifact is the
   `flutter run` build, updates brick 32-bit phones the same way sharing
   does.
4. **~12.6MB of dead fonts ship in every APK**: pubspec declares
   `assets: - assets/` (the whole directory) — which bundles all 37
   NotoSansEthiopic variants (~360KB each) — but registers only Regular +
   Bold as fonts. ~35 font files are pure ballast in a 44.5MB APK.
5. Release builds are signed with **debug keys** (fine for sideloading, but
   it flags on some OEM security scanners and is distribution hygiene debt).
6. No crash reporting exists (no Play Console, no Crashlytics/Sentry), so
   field crashes are invisible — this is why the diagnosis had to be done by
   research instead of reading a stack trace.

---

## 4 · How the big companies solve this (the researched playbook)

**Google — Android Go + platform signals + hard rules.** Android Go edition
(≤2GB devices) is an OS-level program: tuned memory management and a family
of "Go" apps (Maps Go, Gmail Go…) that are smaller, lighter, and offline-
first. For app developers Google provides: `ActivityManager.isLowRamDevice`
(official "this is a 1GB-class phone" flag), the Jetpack **Performance Class
APIs** (`androidx.core:core-performance`) for capability-aware behavior,
Play Console device catalogs + pre-launch reports across real device farms,
and Android vitals thresholds. New for 2026: Google set **memory rules with a
Feb 2027 deadline** — apps must manage RSS/bitmap memory and shrink DEX by
≥25% with R8, or the OS will throttle/kill them. Our app already runs R8 ✓.

**Meta — Facebook Lite: change the architecture, not just the app.** FB Lite
(<1MB at launch, runs on 2009-class phones) is a *thin client*: a small VM +
rendering shell, with product logic and **layout computed on the server**,
resources (icons, translations, images) downloaded on demand and cached,
Unicode glyphs instead of icon images, bounded LRU caches, animations
avoided, memory released when backgrounded. They gate every feature with
**size bots** (SizeBot comments on each PR's APK delta). The lesson for us is
the *principle*: move weight off the phone (we already have a server-driven
app: catalogs, feature flags, banners — lean into it), and treat APK size as
a feature gate.

**Telegram — device performance classes + a real device fleet.** Telegram's
Android source splits every device into **LOW / AVERAGE / HIGH** tiers
computed from CPU core count, frequencies, and memory class; the tier gates
animations, blur parameters, particle counts, and camera preview resolution.
Their Power Saving update shipped per-device **optimized defaults derived
from manually testing 200+ phone models**. The lesson: make the device class
a first-class input to the UI (our parchment blur/animations already honor
reduce-motion — tiering is the natural next step), and build a small *real*
test fleet of the phones your users actually own.

**Microsoft — lightweight variants + platform discipline.** Microsoft ships
Lite variants (Skype Lite) and commits to base-hardware floors (Windows on
8GB) — the pattern is: define the floor explicitly, ship a variant that
meets it, and never let the "main" app silently exceed the floor. Their
Android apps also follow the Play/Gradle discipline: release signing, R8,
baseline profiles, staged rollouts with automatic halt on crash-rate spikes.

**The common pattern across all four:** (1) an explicit, tested hardware
floor; (2) capability detection (RAM flag, performance class) instead of
assuming flagship hardware; (3) weight (code, assets, animations) scaled to
the device; (4) crash telemetry + staged rollout so a bad build reaches
hundreds, not everyone; (5) a real low-end device in the test loop.

---

## 5 · The definitive field test (run this first — 5 minutes)

On any ONE affected phone with the currently-distributed APK (needs a PC
with adb, or "USB debugging" enabled on the phone):

```bash
# 1. Confirm the phone's CPU architecture (expected: armeabi-v7a first)
adb shell getprop ro.product.cpu.abilist

# 2. Confirm the APK's shipped ABIs (expected: only arm64-v8a)
adb shell pm path com.arkeonethiopia.fkss
adb shell "unzip -l \$(pm path com.arkeonethiopia.fkss | cut -d: -f2) | grep .so"

# 3. Capture the crash itself — launch the app, then:
adb logcat -b crash -d
# (or the full filtered stream:)
adb logcat | grep -iE "flutter|fkss|UnsatisfiedLink|FATAL|impeller|vulkan"
```

- **Expected signature for root cause #1:** `java.lang.UnsatisfiedLinkError:
  ... libflutter.so` (or install-time `INSTALL_FAILED_NO_MATCHING_ABIS`).
- **Expected signature for root cause #2:** `FATAL EXCEPTION` or native
  `SIGSEGV` inside `libflutter.so` / Impeller / EGL/Vulkan init.
- **Expected signature for root cause #3:** no crash line — the process is
  killed by `lowmemorykiller` (visible in the main log buffer / `dmesg`).

Whichever signature appears on which phone decides the fix order below.

---

## 6 · Action plan (prioritized)

> **IMPLEMENTATION STATUS — P65 fix package landed (see `docs/RELEASE.md`).**
> Phase 1 ✅ (`scripts/build-release.ps1` + release guide + ABI-aware
> `/app/download?abi=` with per-ABI artifacts, hash sidecar cache, universal
> fallback). Phase 4 ✅ (`FkssApplication.kt` native crash trap → shared log +
> `CrashLogService` + Profile → App → Diagnostics with copy-report; no new
> dependencies). Phase 5 ✅ (34 unused font TTFs deleted: 13.3MB → 724KB of
> bundled fonts). Phase 3 ✅ (`DeviceTierService` — RAM-class tiering + image
> cache budgets 32/64MB on LOW/MID). Signing scaffolding ✅
> (`key.properties`-guarded, debug keys until the admin deliberately migrates
> — warning in RELEASE.md §3). Tests ✅ (`test/update_artifact_test.dart`,
> `test/crash_log_parse_test.dart`, `test/device_tier_test.dart`). Phase 0
> (field logcat) and Phase 2 (Impeller opt-out flag) remain **pending field
> test** — the flag is pre-decided in RELEASE.md §4 and lands only if a
> renderer crash is confirmed on a real phone.

**Phase 0 — measure (§5).** One logcat capture from one phone. Everything
after this is otherwise educated guesswork.

**Phase 1 — fix the artifact (fixes root cause #1; do regardless).**
1. Never distribute `flutter run` builds — dev-loop only.
2. Distribution build: `flutter build apk --release` → universal APK
   (armeabi-v7a + arm64-v8a) at `build/app/outputs/flutter-apk/app-release.apk`.
   Host **that** on `/app/download`.
3. Optional size optimization (per-device APKs, ~2× smaller):
   `flutter build apk --split-per-abi` → serve `app-armeabi-v7a-release.apk`
   vs `app-arm64-v8a-release.apk` by making `/app/download` ABI-aware (the
   app already knows its ABI via a 3-line method channel; pass it as a query
   param). Defer until the universal build is verified in the field.
4. Record the exact Flutter version with every release (renderer behavior
   changed materially across 3.27 → 3.47; "whatever is installed" is not a
   reproducible build). Add `flutter --version` output to the release notes /
   tag the repo per release.
5. Release signing: create a proper keystore, `key.properties`, signingConfig
   (debug-signed APKs attract OEM security scanners).

**Phase 2 — fix the renderer floor (root cause #2; test on the A10 + one
Mali-T820 phone after Phase 1).**
1. First test: does the universal APK run on all four phones as-is?
2. If any phone still dies at splash with an Impeller/GPU signature, add to
   `AndroidManifest.xml` under `<application>`:
   `<meta-data android:name="io.flutter.embedding.android.EnableImpeller"
   android:value="false" />` → forces the legacy GL renderer. Re-test.
3. If a **Mali-400 / ES-2.0-only** phone (the itel A513W) still fails under
   both renderers, that device is below Flutter's hardware floor. Options
   (choose deliberately):
   a. Declare ES-2.0-only phones out of scope (Android itself is dropping
      them; they are 2011-class GPUs on 2023 Go ROMs), with a clear
      minimum-requirements note for users; or
   b. Maintain a "legacy" build track on an older pinned Flutter (last
      Skia-on-Android version) distributed only to those devices. (Costly —
      only if that user segment matters to the school network.)

**Phase 3 — low-RAM hygiene (root cause #3; Telegram/Google patterns).**
1. Device tiering: one `DeviceTier` service (LOW = `isLowRamDevice` / ≤2GB,
   MID, HIGH) via a tiny method channel reading `ActivityManager` +
   `Build`. Gate: parchment blur, shimmer, image cache sizes, prefetch
   counts. (Our lyrics screen already honors reduce-motion — tiering plugs
   into the same switches.)
2. Bitmap hygiene: artwork/cover images decoded at screen-size cache rates;
   bound `ImageCache` (e.g. 24MB on LOW tier).
3. Keep the already-good startup deferral (sync/warm-store 2s post-frame ✓).

**Phase 4 — crash visibility (never debug by rumor again).**
1. Add a Java-level `Thread.setDefaultUncaughtExceptionHandler` in
   `MainActivity` that appends the stack to the existing
   `fkss_bootstrap_error.log` (same file the bootstrap writer uses) — catches
   the Java-side crashes like `UnsatisfiedLinkError`.
2. Better: integrate **Sentry** (free tier, catches native crashes too via
   its NDK integration) — the world-standard for apps distributed outside
   Play. It would have shown this exact crash with device model + ABI on day
   one.
3. Optional "send diagnostics" button on the recovery screen that shares the
   log file.

**Phase 5 — APK weight (low-end respect + Google's 2026 rules).**
1. Fix the font ballast: move the 35 unused TTFs out of `assets/` (or list
   only the needed files in pubspec) → APK drops ~12–13MB instantly. Only
   Regular + Bold are registered.
2. Re-check `logo_school.png` (1MB) and `parchment_hymn_bg.jpg` (540KB) —
   compress/resize for a ~720p-typical screen.
3. R8 already on ✓ (meets Google's DEX rule). Audit assets quarterly.

**Phase 6 — a real test fleet (the Telegram lesson, scaled to budget).**
Borrow/buy the three phones that represent the user base and run every
release on them for 10 minutes: (a) a 32-bit 1GB Android Go phone (itel
A18s-class), (b) the Samsung A10 (32-bit 2GB), (c) one modern arm64 phone.
₪ ~$70 of hardware removes an entire class of field surprises. If budgets
allow, Firebase Test Lab (free tier) runs smoke tests on real devices with
real GPUs in the cloud.

---

## 7 · Expected outcome

- Phase 1 alone should make the app **install and run on all four reported
  phones** except where Phase 2's renderer floor bites (most likely the
  Mali-400 itel).
- Phases 2–3 make it *stay* running and feel acceptable on 1GB Go devices.
- Phase 4 means the next field report arrives with a stack trace attached.
- Phase 5–6 are the long-term discipline that keeps this from regressing.

---

## Appendix · Sources

- Galaxy A10 = 32-bit userland (arm32-binder64): r/androiddev thread "Are
  there any modern devices still running 32-bit ARM" (May 2020);
  r/AndroidQuestions ARMEABI-V7A thread (Feb 2023).
- itel A513W = itel A18s, MT6580 (Cortex-A7), Mali-400 MP ES 2.0, Android 13:
  DeviceInfo HW database entry for itel A513W.
- ZTE Blade A31 Lite = SC9832E, 1GB, Android 11 Go, Mali-T820: GSMArena.
- Mali-T820 = ES 3.1 max: ARM/CNX SoC specs (Amlogic S912/Mali-T820MP3),
  Panfrost driver coverage notes.
- `flutter run` builds the device's single ABI: flutter_tools source
  (`AndroidDevice.targetPlatform`); Yrom's engineering note on
  `-Ptarget-platform=android-arm64` under `flutter run`.
- Impeller default + Vulkan-on-old-SoC crash class + 3.29 MediaTek/PowerVR
  Vulkan disable: "What's new in Flutter 3.29"; Stack Overflow "Flutter 3.27.2
  on Android runs black after the splash screen"; flutter/flutter#151240
  (Impeller no GL fallback issue), #176272 (Impeller crashes after 3.27
  migration).
- Flutter 3.44 removes the Skia path on Android 10+: Level Up Coding, "Flutter
  Just Removed Skia From Every Modern Android Device" (Jun 2026). Flutter
  docs (3.47) still document the Android `EnableImpeller=false` fallback and
  automatic GL fallback where Vulkan is missing.
- Facebook Lite architecture: engineering.fb.com "How we built Facebook Lite
  for every Android phone and network" (2016); TNW interview (SizeBot/BuildBot,
  2019).
- Telegram device performance classes: "A couple of interesting things from
  Telegram Android app source code" (2023); telegram.org/blog/power-saving
  (200+ device manual testing, 2023).
- Google 2026 memory rules: Tom's Hardware, "Google clamps down on Android
  app RAM usage" (Aug 2026); Android Go / isLowRamDevice / androidx
  core-performance: Android developer documentation.
