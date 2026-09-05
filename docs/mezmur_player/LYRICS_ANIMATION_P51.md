# Mezmur lyrics — buttery, modern karaoke animation (P51)

Scope: the **mobile** timed-lyrics view inside the parchment player box
(`lib/screens/mezmur/mezmur_lyrics_screen.dart`). The web player already
highlights correctly; this rebuilds the app's rendering so it is smooth,
modern, and aligned to the parchment UI/background — and it fixes the two
complaints ("not smooth" and "words push to the bottom when highlighted").

---

## 1. What the research told us

The techniques below are the ones production players use (Apple Music,
Spotify/Musixmatch, Spotui, the `flutter_lyric` package):

| Technique | Source | What we took |
|---|---|---|
| "Float on the surface, loom in deep water" | Apple Music lyric animation | Focus the active line at full strength; let every other line recede with distance |
| Scale + brighten + auto-scroll | Spotui lyrics engine ("fires recomposition triggers that smoothly scale, brighten, and auto-scroll… spring physics for line transitions") | Drive emphasis with **scale/opacity/color**, and glide the scroll with physics inertia |
| Centre the near line; fade far lines | LyricGlow, dynamic-lyrics apps | A narrow focused window around the playhead, soft fade at the edges |
| No reflow | Flutter performance guidance | **Never** change font size/weight to emphasise, or a long line re-wraps |
| Isolate animation regions | Flutter performance guidance (Digia / community) | `RepaintBoundary` + implicit animations, and **no per-frame `setState`** |
| Stop rendering when silent | LyricGlow ("rendering stops completely when nothing is playing") | The scroll Ticker stops on pause |

---

## 2. Root causes of the two complaints

### 2.1 "Not smooth"
The old code called `Scrollable.ensureVisible` from a **220 ms** `Timer.periodic`.
When the active line changed (≈ every few seconds) it **restarted** a 420 ms
`ensureVisible` animation. If the next line cut in mid-animation the two fights
→ a visible stutter. Also the position was only sampled ~4.5×/s.

### 2.2 "Words push to the bottom when highlighted"
The active line was rendered with `fontSize 18.5 / weight w800` vs `15.5 / w500`
for the rest. Font weight changes glyph widths, so a line that previously fit
on one row **re-wrapped** to two rows when it became active → its words dropped
onto a second line. That is exactly "some words of the line get pushed to the
bottom."

---

## 3. How it is now built

### 3.1 Emphasis never reflows — pure distance rule (`lib/services/lyrics_emphasis.dart`)
A small, **Flutter-free**, unit-tested rule returns `(isActive, scale, opacity,
distance)`. Every line keeps the **same font size (16) and weight (w600)**; the
active line is brighter + on a gold glow pill, and each further line is
progressively smaller/fainter (≈5.5% smaller, 15% fainter per line, clamped so
nothing vanishes). Because layout metrics never change, a long Amharic line
cannot re-wrap — the reflow bug is gone by construction.

### 3.2 Smooth scroll — one Ticker, exponential glide
Replaced the stuttery `ensureVisible` with a `Ticker` (≈60 fps) that eases the
scroll offset toward a **centred target** using frame-rate-independent
exponential smoothing:

```
k = 1 - exp(-stiffness * dt)     // stiffness = 6.0 → natural, kinetic settle
next = current + (target - current) * k
```

The target is computed once per active-line change with the render-viewport
reveal API (`RenderAbstractViewport.getOffsetToReveal(…, 0.5)`), the same
primitive `ensureVisible` uses, so centring is exact even for variable-height
(wrapped) lines.

### 3.3 Idle-cost like a real player
The Ticker **starts on play and stops on pause** (a `Ticker.start()` while
active would throw, so it is guarded with `isActive`). When paused, the settled
position is reached with an instant snap; when playing, it glides.

### 3.4 Uses the side space, keeps the line centred
Each line is a `Stack(alignment: center)`: the text is centred and the bronze
"now" accent bar is `Positioned` on the left — so the bar occupies the side
margin and **never shifts the words out of the middle**. Vertical+horizontal
padding and a rounded glow pill give breathing room inside the ornamental box.

### 3.5 Accessibility & performance
* Honors `MediaQuery.disableAnimations` (durations collapse to zero).
* Each line is a `RepaintBoundary`, so animating one line can't repaint the list.
* Emphasis is driven by implicit animations only (`AnimatedScale`,
  `AnimatedOpacity`, `AnimatedContainer`) — **no per-frame `setState`** on the list.

---

## 4. Files

| Path | Change |
|---|---|
| `lib/services/lyrics_emphasis.dart` | **New** — pure, UI-free emphasis rule |
| `test/lyrics_emphasis_test.dart` | **New** — pins the falloff + active-line contract |
| `lib/screens/mezmur/mezmur_lyrics_screen.dart` | **Rewritten render** — smooth glide, no-reflow emphasis, glow pill, side-space accent |

---

## 5. Tuning knobs

| What | Where | Default |
|---|---|---|
| Falloff speed | `lyrics_emphasis.dart` `_scaleStep` / `_opacityStep` | 0.055 / 0.15 |
| Min visible | `_scaleStep`/`_opacityStep` clamp bounds | 0.80 / 0.30 |
| Glide stiffness | `mezmur_lyrics_screen.dart` `stiffness` in `_onSmoothTick` | 6.0 (higher = snappier) |
| Emphasis duration | `_anim` (also `_curve`) | 280 ms, easeOutCubic |
| Sample rate | `_positionTicker` interval | 120 ms |
| Position of the bar | `Positioned(left: 4, …)` | 4 px |
| Glow tint / alpha | `_kActiveGradient`, `_kActiveGlow`, bar `BoxShadow` | gold/bronze alpha |

To make the scroll follow the *next* line continuously (rather than gliding on
each change), raise `stiffness` and sample faster; for a lazier, more cinematic
feel, lower `stiffness`.

---

## 6. Mandatory rules — confirmed

| Rule | How it is met |
|---|---|
| Front/back separation, easy UI updates | The **emphasis rule is a pure, Flutter-free library** (`lyrics_emphasis.dart`) + test; the screen owns pixels only. A redesign re-skews the screen, never the rule. |
| Scale to hundreds of thousands | Fixed 280 ms implicit animations, `RepaintBoundary` isolation, no per-frame `setState`, Ticker stops when paused → cost scales with visible lines, not library size. |
| 100% security | Pixels only; **no new network, storage, or auth code**. The earlier security review is unchanged. |
| Don't break anything | Changes are additive to one screen; the data/sync contract is untouched. Existing P48/P50 code path (authoring, delta, merge) is preserved. |
| Maintain/extend/integrate | The emphasis rule is reusable for word-level highlighting or a future full-screen immersive view; the scroll engine is a self-contained ticker. |

---

## 7. Verify on a device (needed — static analysis cannot render)

1. `flutter analyze` (and the CI `nullable_field_lint` gate) → clean.
2. Play a hymn with timings (e.g. በተቀርስታይን) → the active line highlights and the
   **auto-scroll glides smoothly**; it never stutters at a line change.
3. Watch a long/unwrapped line become active → its words **stay on the same row**
   (no reflow / no push-to-bottom).
4. Pause → the list stops animating (idle); resume → it glides again.
5. Drag manually → auto-scroll pauses for 2.2 s, then resumes/snaps to the sung line.
6. Tap a line → seeks and snaps to it.
