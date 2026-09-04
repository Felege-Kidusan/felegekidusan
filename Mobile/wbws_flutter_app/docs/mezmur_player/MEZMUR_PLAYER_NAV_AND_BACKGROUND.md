# Mezmur Department Player — Deep Analysis, Bug Fix & Feature Design

**Scope:** Flutter mobile app under `Mobile/wbws_flutter_app` (the "Mezmur / መዝሙር" audio+lyrics player).
**Topics:** next/previous confusion on audio hymns · swipe navigation · background playback · "leave the screen but keep hearing" + return UX · device compatibility (low → high end) · front/back separation · scale · security.
**Status / how to read this:** The core navigation bug is fixed with a **pure, unit-tested policy module** (already added + verified with the Dart analyzer & a 30+ assertion harness). This doc explains *why*, gives the architecture and the copy-paste UX snippets for the remaining screen work, and lists the exact verification steps + rollback. **The author of this repo builds and verifies with a real Flutter toolchain; a `flutter analyze` / `flutter test` + device pass is required before shipping (see "Verification checklist").**

---

## 1. Executive summary

The Mezmur player is **already unusually well-engineered for its domain**. It ships:

- **`just_audio` + `just_audio_background`** (audio_service under the hood) — the industry-standard Flutter streaming/background stack. [1]
- **Correct Android media configuration**: `android.permission.FOREGROUND_SERVICE_MEDIA_PLAYBACK` (required for Android 14+/API 34), `FOREGROUND_SERVICE`, `WAKE_LOCK`, `POST_NOTIFICATIONS`, a `foregroundServiceType="mediaPlayback"` service, and the `AudioServiceFragmentActivity` binding in `MainActivity.kt`. This is the part that most apps get wrong and that blocks Android 12–14 background audio. [4][5]
- Runtime `POST_NOTIFICATIONS` request before first play (Android 13+), a proper **audio session** (ducking/interruptions), an **app-wide mini-player** that follows the user across every tab in the shell, and lock-screen / headset transport via the media session.
- A local-first `sqflite` hymn cache and a signed-stream media service on the backend (offline-friendly, no public-CDN URL leaks).

So **background playback, the media notification, lock-screen controls, and "leave the screen but keep listening" already work** and are configured correctly for modern Android. The remaining problems are (a) a **navigation/semantics bug** that makes next/previous feel random, (b) a handful of **UX gaps** around leaving/returning, and (c) **performance** polish for low-end devices.

This change fixes (a) with a clean, testable model and lays out (b) + (c).

---

## 2. What I found (code-grounded root cause)

The heart of the bug lives in `lib/services/mezmur_audio_player.dart`. The player deliberately keeps **two parallel structures**, and they are easy to let drift out of sync:

| Structure | Contents | Length | Who advances it |
|---|---|---|---|
| `_catalog` + `_viewIndex` | **Every** hymn the user browsed into, list order — audio optional. This is "the hymn on screen". | N | In-app next/prev/swipe (`skipView`/`moveTo`), and remapping below |
| `_queue` + `_index` | **Only rows with a playable audio URL**, fed to the engine via `setAudioSources`. | ≤ N | The engine, on natural completion / lock-screen / headset skip |

Symptoms the user described ("next/previous get very confused, especially from hymns that have audio") map to three concrete defects:

1. **Previous/next semantics were computed from the *audio engine* index, not the hymn on screen.**
   Old `skipView` did `final viewingPlaying = viewHasAudio && currentTrack?.hymnId == viewTrack?.hymnId && _playing;` then restarted-or-stepped using that. `currentTrack` is `_queue[_index]`. Whenever the two lists had diverged — e.g. after a natural auto-advance, a lock-screen skip, or after visiting a lyrics-only row (which **pauses** audio but does **not** move `_index`) — the "is it actually playing this hymn?" check was answered with a stale audio index, so Previous could restart the *wrong* song or step the *wrong* direction. Buttons reacted to whatever was stale instead of to the hymn the user was looking at.

2. **The visible cursor and the engine were only loosely re-synced.**
   A `currentIndexStream` listener remaps `_viewIndex` to whatever audio item the engine lands on, and `_syncAudio` rebuilds/seeks by re-deriving the playable sub-list. Because the engine advances in *audio-ordinal* space (skipping lyrics-only rows) while buttons/swipe advance in *visible-row* space, the two could disagree about the "current hymn", which is exactly the "it jumps / it plays something else" confusion.

3. **Visiting a lyrics-only hymn silently ended the session for the mini-player.**
   `showMiniPlayer` was `(_playing || viewHasAudio)`. Browse from a playing audio hymn onto a lyrics-only hymn → the engine pauses and the current view has no audio → `_playing=false` and `viewHasAudio=false` → the mini-bar **disappears**. The user appears to have "lost" the session with no obvious way back, reinforcing the sense of confusion.

**The fix:** stop computing navigation decisions ad hoc in the stateful controller. Extract every decision into a **pure, stateless, unit-testable module** that answers "which visible row, and should it autoplay / restart?" Given the *catalog audio flags*, the decisions are deterministic and provable. The controller then just performs the audio-engine work the policy dictates. Because the policy operates on the **visible catalog** (one row = one hymn, audio optional), next/previous/swipe always mean "the adjacent hymn" — which is precisely what the request asks — and the engine is told to follow.

---

## 3. Deep research — how the big players do it (and which library we chose)

### 3.1 Queue / navigation model
Spotify, Apple Music, YouTube Music and podcast apps share two universal rules that we now encode:

- **The playlist the user sees is the source of truth for on-screen navigation.** Next/previous/swipe move one *item in that visible list*, whatever it is.
- **The transport has two layers.** On-screen buttons act on the visible list; lock-screen/headset/media buttons act on the *media session queue* (which in our sparse-audio catalogue is the playable subset). Our catalog already includes lyrics-only rows as "real neighbours", so on-screen navigation reaches them; the media session naturally advances only playable audio.

### 3.2 Industry "Previous" semantics
The near-universal rule (Spotify, Apple, and most CD/digital players): **previous restarts the current track if it has been playing > ~3 s; otherwise it moves to the previous track.** [2][3] We implement exactly this in `MezmurPlaybackPolicy.previousTarget` with a `restartThresholdMs = 3000`. Because this threshold is a single named constant, a product decision to make previous *always* go back (some users prefer that) is a one-line change.

### 3.3 Auto-advance on completion
When an audio track ends, real apps keep sound flowing by advancing to the next *playable* item (skipping anything that cannot play), and repeat/re-wrap per the loop mode. Our `autoAdvanceRowAfter` gives the target visible row for that, so the on-screen page, the audio, and the mini-player always point at the same hymn again after a natural completion — no silent drift.

### 3.4 Background playback stack — chosen & already present
The right Flutter choices for background audio with a media notification + lock-screen controls are **`just_audio` + `just_audio_background`** (which wraps `audio_service`); for apps with *one* auto-managed player (exactly our case), this is the recommended path, and `audio_service` remains a direct dependency for its public types. [1] We keep this stack; nothing to swap. Requirements for it to keep working on Android 12–14 are satisfied in the manifest (see §1).

### 3.5 "Leave but keep hearing" + return
The professional pattern (used by Spotify/Apple) is:
1. Leaving the now-playing screen does **not** stop audio; playback continues via the background service.
2. A persistent, app-wide **mini-player bar** floats above whatever screen you are on; tapping it pushes the now-playing screen **back onto the top of the existing navigator stack**, so when you close it you return to the exact screen you left (Navigator gives this for free).
3. The **OS media notification** is the control you have even with the app fully backgrounded.

All three already exist in this app (`MezmurMiniPlayer` lives in the app shell's `Stack`; `openSession` pushes onto the current navigator). The polish requested ("it has to know the screen the user is on") is satisfied by this navigator-stack design — see §6 for the small enhancements to make it explicit and to restore a *resumable* audio session even after browsing onto a lyrics-only hymn.

### 3.6 Device compatibility & performance (low → high end)
Research consensus for smooth Flutter on low-end Android [6][7][8]:
- **Limit/avoid large `BackdropFilter` (frosted-glass) regions.** A full-width blur at sigma ~20 costs ~6–9 ms of raster on a mid-tier device. `saveLayer`+Gaussian blur is the single most expensive built-in effect. The parchment player's `_GlassPanel` currently uses `BackdropFilter(sigma 18)` — fine on mid/high-end, a risk on low-end.
- Use `RepaintBoundary` to isolate the transport (which animates/updates every ~250 ms with the lyrics ticker) from the static parchment art.
- Avoid per-frame rebuilds; the controller already rate-limits position notifications to every 500 ms.
- Prefer asset/translucent fills over live blur where the backdrop is static (the parchment artwork is static → a translucent fill or a pre-blurred overlay is "free").

---

## 4. What changed (verified)

### 4.1 New pure module — `lib/services/mezmur_playback_policy.dart`
No Flutter/plugin imports (mirrors the repo's existing pure-logic pattern in `mezmur_synced_lyrics.dart`, so it runs in `flutter test` without a device). It exposes:

- Index mapping: `audioOrdinalForRow`, `rowForAudioOrdinal` (catalog ↔ audio queue).
- Visible stepping: `nextRow`, `previousRow`, `stepRow` (one visible row, wrap in loop-all) — buttons/swipe.
- Previous decision: `previousTarget(...)` returning `{targetRow, restartCurrent, shouldAutoPlay}` (industry >3 s restart rule).
- Affordance: `canGoPrevious`, `canGoNext`.
- Auto-advance: `autoAdvanceRowAfter(...)` (continuous listening / repeat / stop).
- Loop constants `PlaybackLoop { off, all, one }` matching the controller.

**Verified:** `dart analyze` reports **no issues**; an executable harness of **30+ assertions** covering mapping, boundaries, loop wrap, >3 s restart, lyrics-only neighbours, and completion auto-advance all **pass**.

### 4.2 Controller — `lib/services/mezmur_audio_player.dart`
Localized, low-risk edits:
- Added `_audioFlags` derived from the catalog (the single source of truth the policy reads).
- `canPrevious` / `canNext` now delegate to the policy.
- `skipView` now delegates next/previous to the policy and always steps **one visible row**, then tells the engine to follow — fixing defects 1 & 2.
- `showMiniPlayer` keeps a **live session** visible whenever playable audio is loaded (`_playing || _queue.isNotEmpty || viewHasAudio`), fixing defect 3.
- The engine’s existing auto-advance + `currentIndexStream` remap now stays consistent with the policy’s catalog-space model (completion moves the visible cursor to the next playable hymn).

**Syntax-verified** with the Dart parser (`dart format` parses the file cleanly). Because this file imports Flutter/plugin packages, a full type check needs the repo toolchain — see §7.

### 4.3 Regression test — `test/mezmur_playback_policy_test.dart`
Pins the whole policy under `flutter_test` so the exact behaviours (including the >3 s rule and "lyrics-only rows are real neighbours") can never silently regress.

### 4.4 Patch
A reviewable combined patch is at `patches/mezmur_player_nav_bg.patch`.

---

## 5. Target architecture (separation, scale, maintenance)

```
┌─ UI layer (Flutter) ────────────────────────────────────────┐
│ mezmur_player_screen · mezmur_lyrics_screen · mini_player    │
│ mezmur_hymns / category screens                              │
│   └ talk only to the controller (ChangeNotifier) + policy    │
├─ Domain / session layer ────────────────────────────────────┤
│ MezmurAudioPlayerController (stateful, ONE instance)          │
│   └ delegates every DECISION to:                              │
│      MezmurPlaybackPolicy  (PURE, tested)   ◄── NEW           │
│      MezmurTrack  (data + toMediaItem)                       │
│   └ performs only audio I/O via just_audio/audio_service      │
├─ Infra / plugin layer ───────────────────────────────────────┤
│ just_audio · just_audio_background · audio_service ·         │
│ audio_session · sqflite · api_service                        │
└──────────────────────────────────────────────────────────────┘
```

Why this is the right split (and matches the "separate front/back, easy to maintain/extend/scale" mandatory rule):
- **UI never computes navigation.** Screens just render controller state and emit intents (`next()`, `previous()`, `moveTo(i)`, `seek`). You can restyle or even rebuild the entire UI without touching playback logic.
- **Playback logic never embeds UI.** Decisions live in a pure module that cannot be broken by a refactor of a screen.
- **Testable without hardware.** The policy is proven in unit tests; the controller is a thin adapter over plugin calls (needs a device/integration test).
- **Scales.** One controller instance per app; state is `ChangeNotifier`-driven so thousands of rebuilds are cheap and there is no per-widget ownership. The engine holds only playable rows in memory; the catalog stays as lightweight `MezmurTrack` metadata (no audio bytes), so hundreds of thousands of users/hymns do not affect the client memory model — the heavy data (audio, full lyrics) is streamed from the signed media service and cached in `sqflite` only on demand.

---

## 6. Recommended UX enhancements (copy-paste ready — apply after `flutter analyze`)

> These are the remaining "leave & return" and "swipe" items from the request. They are intentionally small and isolated so they can be added/removed without touching core logic.

### 6.1 Swipe navigation (already present) — confirm & keep
The lyrics stage is already a horizontal `PageView.builder` over the **whole catalog**; `onPageChanged` → `moveTo(i)`. With the new policy this now always means "the adjacent hymn", audio or not. No new code needed; verify the gesture on device (both directions, crossing lyrics-only rows).

### 6.2 Return to the last screen + a resumable session
`openSession()` (tapped from the mini-player) already pushes the now-playing screen onto the **current navigator stack**, so closing it returns to exactly where the user was. Two tiny additions make this explicit and restore a paused audio session even after browsing onto a lyrics-only row:

- In `MezmurAudioPlayerController`, keep a `_lastPlayedAudioRow` (catalog index of the most recent playable hymn) and expose a `resumeLastPlayed()` that calls `moveTo(_lastPlayedAudioRow, autoPlay: true)`. Set it inside `_syncAudio`/`openQueue` whenever a playable hymn is selected.
- In `MezmurMiniPlayer`, when the current view is lyrics-only but a live/resumable audio session exists (`_queue.isNotEmpty`), show a play glyph that calls `resumeLastPlayed()` instead of being disabled, so the user can always get back to the audio they were hearing.

```dart
// mezmur_mini_player.dart — inside the play/pause IconButton's onPressed:
onPressed: c.viewHasAudio
    ? c.toggle
    : (c.hasQueue ? c.resumeLastPlayed : null),
```

### 6.3 Optional: swipe-down-to-close (keep playing)
If you want the "pull the player down to leave but keep listening" gesture, add a dedicated drag on the **header** area (NOT the whole screen — the lyrics box scrolls vertically and would conflict):

```dart
// wrap _buildHeader's Row in a vertical drag that closes when flung down:
GestureDetector(
  onVerticalDragEnd: (d) {
    if (d.primaryVelocity != null && d.primaryVelocity! > 700) {
      Navigator.of(context).maybePop(); // audio keeps playing; mini-bar appears
    }
  },
  behavior: HitTestBehavior.translucent,
  child: _buildHeader(context),
)
```

### 6.4 Low-end performance (optional but recommended)
Replace the live `BackdropFilter` in `_GlassPanel` with a translucent solid fill for lower-tier devices, or gate blur on device tier. This matches the perf guidance in §3.6 and is a one-file change.

---

## 7. Verification checklist (do in the repo toolchain)

```bash
cd Mobile/wbws_flutter_app
flutter pub get
flutter analyze          # must be clean (controller imports Flutter/plugins — needs this)
flutter test             # runs mezmur_playback_policy_test.dart + existing tests
```

Manual / device pass:
1. Open a hymn **list** containing a mix of audio and lyrics-only rows.
2. Next / Previous step exactly one visible hymn (including onto lyrics-only rows) — confirm no skipping, no wrong restart.
3. On an audio hymn playing > 3 s, tap Previous → it restarts; tap again quickly → it moves to the previous hymn (industry rule).
4. Natural completion → advances to the next **playable** hymn (skips lyrics-only), cursor stays in sync; loop-all wraps; loop-one repeats; loop-off stops at the end.
5. Leave the now-playing screen while audio plays → audio continues; the mini-player remains; the OS media notification + lock-screen controls work; tapping the mini-player returns to the player and closing it returns to the previous screen.
6. Pause, navigate around the app, return via the mini-player → resumes the last viewed hymn.
7. Rotate/tier test on a low-end Android (API 24+) and a high-end device; confirm no jank from blur and smooth swipe.

**Rollback (if anything regresses):**
```bash
cd Mobile/wbws_flutter_app
git checkout lib/services/mezmur_audio_player.dart        # undo controller edits
git clean -f lib/services/mezmur_playback_policy.dart      # remove the new module
# or, from the repo root:  git apply -R patches/mezmur_player_nav_bg.patch
```

---

## 8. Mandatory-rules conformance

- **UI/UX maintainability (front/back separation):** navigation decisions live in a pure policy module; screens only emit intents. §5.
- **Scale to hundreds of thousands of users:** one app-side session controller; only playable rows are in memory; heavy media is streamed/cached on demand from the signed service. §5.
- **No security risk / Sunday-school data protected:** this change is **client-side playback logic only** — it touches no member data, no API surface, no tokens, no storage beyond the already-encrypted app sandbox. No new permissions or network surface are added. Backend signed-media access is unchanged. Existing audit posture (`SECURITY_AUDIT.md`, encrypted token storage, manifest lockdown, `exported="false"` media service) is untouched.
- **Matches current system state / breaks nothing:** the diff is additive + localized to navigation semantics and one getter; all pre-existing playback paths (`openCatalog`, `moveTo`, `openQueue`, background service, media session) are preserved.
- **Easy to maintain/extend/integrate:** the policy is a single pure file with one regression test; new behaviours (e.g. "previous always goes back", long-press-to-rewind, gapless next) are small additions to the policy + a test, not scattered edits.

---

## 9. Sources
[1] pub.dev `just_audio_background` — single auto-managed player; use `audio_service` directly only for advanced/multi-player needs.
[2] UX StackExchange — previous-track restart-vs-skip semantics across players.
[3] Apple/Spotify — ~3 s previous-track restart rule.
[4] just_audio GitHub #1144 — Android 14 requires `FOREGROUND_SERVICE_MEDIA_PLAYBACK`.
[5] Android FGS-type requirements (API 34+).
[6] Medium "Designing Flutter Apps for Low-End Devices".
[7] "Flutter Performance Deep Dive: Skia, Impeller" — `BackdropFilter`/`saveLayer` cost.
[8] Flutter performance/jank checklist — RebuildBoundary, rebuild minimization.
