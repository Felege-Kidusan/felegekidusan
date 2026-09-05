# Mezmur player audit — P34

Deep audit of the Mezmur mobile section: five reported defects, each
traced to a root cause in code, fixed against a researched industry
pattern rather than a guess.

---

## 1. Play/pause worked exactly once

**Symptom.** The first play/pause worked. Every later tap did nothing.

**Root cause.** From the just_audio contract for `AudioPlayer.play()`:

> The Future returned by this method completes when the playback
> completes or is paused or stopped.

`play()` does **not** complete when playback *starts*. The controller did:

```dart
_controlInFlight = true;
try   { await _player.play(); }        // parks here for the whole track
finally { _controlInFlight = false; }  // never reached until the song ends
```

so `_controlInFlight` stayed latched for minutes. Meanwhile:

* `play()`  began with `if (_controlInFlight) return;` — silent no-op.
* `pause()` began with `if (_controlInFlight) { _pausePending = true; return; }`.
* `toggle()`'s early return set `_pausePending = true` and **never cleared
  it**, so the next successful `play()` re-paused itself in its `finally`.

Four interacting booleans (`_controlInFlight`, `_pauseRequested`,
`_pausePending`, `_commandVersion`) encoded state the audio engine
already owns authoritatively.

**Fix.** Deleted all four flags. Now:

* **Never `await _player.play()`** — it is fired with `unawaited(...)`.
  `playerStateStream` already drives `_playing`, so the UI still updates.
* Async *setup* is serialised through one `_commandChain`, so two fast
  taps cannot interleave their loads.
* Intent is a monotonic `_commandVersion` generation counter plus
  `_wantPlaying`; a slow source load checks it is still the newest
  command before starting the engine, so a load can never override a
  pause the user pressed while it was in flight.
* `toggle()` reads `_player.playing` — the engine — rather than a
  hand-maintained boolean pair.

Precedent: just_audio#265 (use `playerStateStream`, not
`playbackEventStream`), AudioKit#2646 and Mozilla bug 1173848 — both the
same class of defect, where a stale internal play/pause pair makes the
second toggle a no-op. The canonical fix in every case is to derive
state from the engine.

The rules are extracted to `lib/services/mezmur_transport_gate.dart` and
covered by 15 tests in `test/mezmur_transport_gate_test.dart`, including
one that asserts the 2nd–5th play are treated exactly like the 1st.

## 2. Closing the mini player did not stop the audio

**Root cause.** The close button *did* call `pause()` — but `pause()` hit
the latched `_controlInFlight` guard from defect 1 and returned after
merely setting `_pausePending`. The bar disappeared, audio continued, and
the user had no remaining control to stop it.

**Fix.** Fixing defect 1 unblocks `pause()`, but the button now calls
`stopAndClear()` instead: closing the bar **ends the session** — stops
audio, releases the queue, removes the bar. This matches YouTube (the
video stops when the miniplayer is hidden) and YouTube Music (dismissing
the bar is "dismiss queue"). `stopAndClear()` also bumps the generation
counter so an in-flight load cannot resurrect playback afterwards.

## 3. Mini player vanished on some screens, and covered critical UI

**Root cause.** It was a `Positioned` child of the app shell's `Stack`:

```dart
Positioned(left: 12, right: 12, bottom: hideMainNav ? 72 : 8,
           child: const MezmurMiniPlayer()),
```

Two consequences:

1. It lived *inside* the shell, so any route pushed on the root Navigator
   (Downloads, the full player, attendance) drew on top of it and the bar
   disappeared.
2. As a pure overlay it consumed no layout height, so it floated over the
   attendance Save/Submit bar and the "take attendance" / "Add" FABs
   (`mezmur_home` L79, `mezmur_categories`, `mezmur_zemarians`) — those
   controls became unreachable.

**Fix.** New `MezmurMiniPlayerHost`, mounted in `MaterialApp.builder`
**above the Navigator** as a real row in a `Column`. This is the pattern
accepted on SO 64644547 and used by Spotify, YouTube Music and Apple
Music. Because the bar is a sibling of the Navigator rather than an
overlay:

* it survives every push/pop — it genuinely follows the user everywhere;
* it consumes real height, so the Navigator's viewport shrinks and every
  Scaffold bottom bar, FAB and `MediaQuery` inset lays out above it
  **automatically**. No screen needs manual padding, and no future screen
  can regress.

It uses `AnimatedSize` so appearing/disappearing reflows smoothly. The
shell's now-redundant `Stack` was collapsed back to a `Column`.

## 4. One tap created two categories

**Root cause — two independent defects.**

*UI:* the SAVE `FilledButton` had an `async onPressed` with no in-flight
guard and no disabled state, so a double tap entered `saveCategory`
twice.

*Store:* `saveCategory` was not idempotent for creates. It read
`getLocalCategories()` to enforce name-uniqueness and only *then* wrote —
a check-then-act race. Both calls passed the check, and `_localId` mints
a fresh negative id per call, producing **two rows and two
`category_save` outbox ops**.

**Fix.** Both layers:

* The dialog tracks `saving`, disables the button and shows a spinner —
  the cosmetic guard, and the honest UI signal.
* `saveCategory` now chains every call through `_taxonomyChain`, making
  check-then-write atomic. The second caller sees the first one's row and
  is correctly rejected as a duplicate. This is the authoritative guard:
  it holds even if some other call site forgets to debounce.

## 5. The category screen was too long and complex

It rendered every main **and** every sub in one flat list, which grew
unbounded and buried the row you wanted.

**Fix — drill-down.** Material defines no tree-view pattern and nested
navigation is discouraged on small screens; the mobile convention for
hierarchical data is to show one level and tap to descend.

`MezmurCategoriesScreen` now takes an optional `parent`:

* `parent == null` → lists main categories only. Each row shows its
  sub-category count and is tappable.
* tapping a main → pushes the **same screen** with that main as `parent`,
  listing and managing only that main's sub-categories. The FAB becomes
  "Add Sub" and creates directly under it.

One code path serves both levels, so rename / hide / cover-image /
reorder behave identically at each and nothing was duplicated. The
"Add sub-category" overflow action became "Manage sub-categories" and
descends, so a new sub is always created in view of its context.

---

## Verification

`dart analyze` clean on the extracted logic; all touched files parse and
are `dart format` clean.

**63 tests passing** (48 pre-existing + 15 new):

| Suite | Tests |
| --- | --- |
| `mezmur_transport_gate_test.dart` | 15 (new) |
| `mezmur_download_policy_test.dart` | 20 |
| `mezmur_playback_policy_test.dart` | 18 |
| `taxonomy_reconcile_test.dart` | 10 |

Widget files can only be parse-checked in this environment (no Flutter
SDK), so the changes to `mezmur_categories.dart`,
`mezmur_mini_player_host.dart`, `app_shell.dart` and `main.dart` still
want a device smoke test:

1. Play a hymn; press play/pause **five times** — it must respond every
   time.
2. Press X on the bar — audio must stop and the bar disappear.
3. Start playback, open Downloads and the attendance screen — the bar
   must still be there, and the Save/Submit bar and FABs must be fully
   tappable.
4. Double-tap SAVE on a new category — exactly one row must appear.
5. Tap a main category — its own sub-category screen must open.
