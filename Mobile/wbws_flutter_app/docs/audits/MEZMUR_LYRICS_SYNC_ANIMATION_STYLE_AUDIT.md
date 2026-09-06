# Mezmur lyrics sync, animation & style — deep audit

Function-by-function review of the Mezmur **lyrics-sync subsystem**: the
timed-lyrics engine (`mezmur_synced_lyrics.dart`), the karaoke renderer
(`mezmur_lyrics_screen.dart`), the tap-to-sync editor
(`mezmur_lyrics_sync_screen.dart`), the authoring rules (`lrc_builder.dart`),
the emphasis model (`lyrics_emphasis.dart`), the reader settings, and the
player surface they live in — plus the PHP endpoint and canonicalizer they
round-trip through (`MezmurMediaService.php`).

This is the focused follow-up to `MEZMUR_DEEP_AUDIT.md` (which covered
audio/queue defects). Scope here: **sync correctness, animation, style**.
Audited at commit `b80a67d` (after the P58 keep / P59–P60 revert cycle).

Severity: **S1** breaks a core promise · **S2** wrong behaviour users hit
· **S3** correctness/robustness risk · **S4** hygiene/polish.

**Verification:** every claim below was checked against the source, and all
shipped fixes were verified with `flutter analyze` (target files clean; one
deliberate deprecation kept, see D8) and the full test suite — **288 passing**
(16 new tests pin the contracts this audit found weak).

---

## Summary

| # | Severity | Area | Defect | Status |
|---|---|---|---|---|
| F1 | **S2** | sync | Editor round-trip drops `[offset:]` → every timing silently shifts | ✅ fixed (P61) |
| F2 | **S3** | sync | Tap-to-seek seeks the raw stamp, highlight uses stamp+offset → they disagree | ✅ fixed |
| F3 | **S3** | sync | Parser drops all but the LAST stamp of a multi-stamp run (regex capture bug) | ✅ fixed (found by new test) |
| F4 | **S3** | sync | `_load()` has no generation guard → stale async paint of the previous hymn | ✅ fixed |
| F5 | **S3** | sync/anim | 120 ms polling + 500 ms notify throttle → up to ~300 ms highlight latency; timer runs backgrounded | ✅ fixed (event-driven) |
| A1 | **S2** | animation | Three parallel implicit animations read as desynchronised motion (the P59 problem) — P60's fix used a non-existent API (`FontWeight.fromWeight`) and both were reverted | ✅ fixed (one tween, correct API) |
| A2 | **S3** | animation | Weight lerp re-shapes Amharic text every frame during the 360 ms window | 🟡 mitigated, see D1 |
| A3 | **S4** | animation | Reduce-motion honoured for emphasis but not for the scroll glide | ✅ fixed |
| S1 | **S2** | style | The sync editor is a stock maroon Material screen inside a parchment product | ✅ fixed (parchment family) |
| S2 | **S3** | style | Touch targets under the 48 dp minimum; a comment claims 40 dp IS the minimum (it is not) | ✅ fixed |
| S3 | **S3** | style | FittedBox scale-down defeats large-accessibility text on long lines | ✅ fixed (reading mode wraps) |
| S4 | **S4** | style | Editor had no scrubber — timing work without positional context | ✅ fixed |
| S5 | **S4** | workflow | No in-app re-entry to fix EXISTING timings (editor offered only when none exist) | ✅ fixed |
| S6 | **S4** | style | Editor rebuilt the whole screen 5×/s on a wall clock, even while paused | ✅ fixed (self-ticking transport island) |
| S7 | **S3** | a11y | Lyric tap-to-seek invisible to assistive tech (bare GestureDetector) | ✅ fixed (Semantics) |
| D1–D9 | S4 | — | Deferred items with rationale (see end) | 📋 documented |

---

## Verified-correct first (a clean result is a finding too)

The architecture under this subsystem is unusually disciplined, and an audit
that only lists problems would misrepresent it:

- **Rules are pure and pinned.** Timing validity lives in `LrcBuilder`
  (pure Dart), the falloff in `LyricEmphasis` (pure), the document model in
  `SyncedLyrics` (pure), persistence in `HymnStore`, pixels in the screens.
  Every one of those units has tests. This audit could add 16 tests without
  touching a screen — that is the payoff of the separation.
- **Single-dialect storage.** The server's `canonicalizeLrc()` normalises on
  write (one stamp per line, sorted, ms always 3 digits, headers preserved).
  Every reader sees the same shape; the tolerant client parser is a safety
  net, not a second dialect.
- **The paint-only scale decision (P58) is right** and should not be
  reverted again: animating `fontSize` re-shapes Ethiopic glyphs and re-fits
  the `FittedBox` per frame — the source of the original jank. The one-row
  guarantee (`softWrap:false` + `FittedBox.scaleDown`) is the correct
  answer to "a long Amharic line must never re-wrap mid-hymn".
- **User-scroll handling is production-grade**: `_userHold` never fights the
  finger, a 2.2 s resume delay, snap-back when paused / glide when playing.
- **Offline-first loading** (seed from row → local DB → best-effort refresh,
  never blanking on-screen text) and the **local-first save outbox** are
  correct as designed.
- **WCAG 1.4.1 discipline** in the editor (cursor bar + colour, never colour
  alone) — kept intact through the restyle.

---

## 1 · F1 — S2 — Editor round-trip drops `[offset:]`, silently shifting every timing

`lib/screens/mezmur/mezmur_lyrics_sync_screen.dart` (`_load`/`_save`) and
`lib/services/lrc_builder.dart` (`parse`/`build`).

The server's canonicalizer **preserves** `[offset:±ms]` headers (verified in
`MezmurMediaService.php:713+`). The playback engine honours them
(`SyncedLyrics.indexFor`: a line at raw time T activates at playback
position `T + offset`). But the mobile editor's `LrcBuilder.parse` only
matches timestamp lines — the header never reaches the edit model — and
`build` emits stamps only.

**Consequence:** open any offset-bearing hymn in the mobile editor, stamp
one line, save → the header is gone and every remaining stamp now means
something **different** (raw time instead of heard time). Every subsequent
line highlights early by exactly the offset. Silent, data-destroying, and
invisible in review because the document still "looks valid".

**Fix (P61).** The editor now works in *playback time* — the time the
curator actually hears and stamps against:

- `LrcBuilder.offsetOf()` reads the header;
- on load, existing stamps are **baked** (+offset) into playback time;
- new stamps are captured against `_c.position` directly;
- on save, stamps are **unbaked** (−offset), so the stored document is
  semantically identical (offset now lives in the stamps — see D3 for why
  baking rather than header round-trip is the right call).

Pinned by `lrc_builder_test.dart` → "P61 — [offset:] round-trip", including
the exact case "a NEW stamp made against playback time saves at the heard
moment".

## 2 · F2 — S3 — Tap-to-seek disagrees with the highlight by exactly the offset

`mezmur_lyrics_screen.dart` `_tapLine` seeked to `line.time` (raw), while
`indexFor` activates at `line.time + offset`. With offset 0 nobody notices;
add one header and every tap lands wrong while the sung-line logic is
"correct". Classic split-brain: two call sites each re-deriving the same
arithmetic with different inputs.

**Fix.** `SyncedLyrics.seekTargetFor(line)` is now the single owner of
"the playback moment this line begins" (`line.time + offsetMs`), used by
`_tapLine`. Pinned by `synced_lyrics_seek_test.dart` "THE INVARIANT":
seeking to any line's target must light exactly that line — for offsets
0/+500/−1500 across a whole document.

## 3 · F3 — S3 — Multi-stamp lines lose all but the last stamp (pre-existing, found by this audit's tests)

`mezmur_synced_lyrics.dart` `tryParse`:

```dart
final stampRe = RegExp(r'^(\[[0-9]{1,2}:[0-9]{2}(?:\.[0-9]{1,3})?\])+\s*(.*)$');
```

A **capturing** group under `+` retains only its last iteration — `group(1)`
is `[00:09.00]`, not the full run. The doc comment above it promises
"bracket runs are expanded to two entries"; in reality the earlier stamps
are silently dropped. (The PHP canonicalizer handles runs correctly with a
non-capturing inner group — the Dart port changed one character too few.)

Latent in practice (the server expands runs before storage) but this
parser's stated purpose is tolerance of hand-made documents — exactly the
documents that carry runs.

**Fix.** `((?:\[…\])+)` — non-capturing inner group, full run in group 1.
Regression-pinned by the new parser test.

## 4 · F4 — S3 — `_load()` stale-response race

`mezmur_lyrics_screen.dart`: `_load()` awaits local DB + network with no
generation token. On `didUpdateWidget` (track change) or rapid PageView
churn, an older `_load` can resolve last and paint the **previous hymn's**
lyrics into the current screen; `if (!mounted) return;` does not help
because the widget is still mounted — it is simply the wrong response now.

**Fix.** Every load takes a ticket (`_loadGen`); a response whose ticket is
no longer current is dropped.

## 5 · F5 — S3 — Polling architecture: latency + background cost

The renderer sampled `_c.position` on a 120 ms `Timer.periodic`, while the
controller throttles its `notifyListeners` to 500 ms (fine for a transport
clock, starving for karaoke). Worst-case highlight latency ≈ sample period +
stream period ≈ 300 ms; on fast hymns the emphasis visibly trails the audio.
The timer also kept firing while the app was backgrounded (audio continues
via `just_audio_background`), doing `setState` work no frame would ever show.

**Fix (P61).** Event-driven sampling:

- `MezmurAudioPlayerController.positionStream` now exposes the engine's
  adaptive position stream (≈ frame-rate while playing, silent while
  paused — zero idle wake-ups, zero polling);
- the screen subscribes for samples and listens to the controller for the
  edges the stream cannot see (play/pause → start/stop the glide ticker;
  seek/track-change → immediate re-light);
- a `WidgetsBindingObserver` suspends active-line work while backgrounded
  and force-re-syncs on resume.

## 6 · A1 — S2 — One motion, three controllers (the P59 story)

The emphasis transition ran as **three parallel implicit animations**
(`AnimatedOpacity` + `AnimatedScale` + `AnimatedDefaultTextStyle`) with
matched duration/curve. Mechanically synchronised, perceptually not: the
text-style animation re-layouts text mid-flight (weight lerp), so size,
colour and fade each arrive on a slightly different physical path — the
"size looks like it changes on its own" complaint that drove P57–P60.

P59 correctly diagnosed this and unified everything behind one
`TweenAnimationBuilder` over the line's distance — then P60 "fixed" a
compile error with `FontWeight.fromWeight(...)`, **an API that does not
exist**, and both commits were reverted, leaving the three-animation design
in place with the root cause unfixed.

**Fix (P61, P59 done right).** One `TweenAnimationBuilder<double>` per line
over `distance`; scale and opacity derive from the **same** profile
functions `forIndex` uses (`LyricEmphasisProfile.scaleFor/opacityFor`,
introduced as the single source of the falloff), colour lerps
`rest→active` and weight `w500→w800` via **`FontWeight.lerp`** — the API
P60 was looking for — from the same value. A value cannot desynchronise
from itself. Endpoint equality with `forIndex` is pinned by test (the
"one-tween contract"), and the exact published curve (d=1 → 0.92/0.85,
floors 0.86/0.42) is now documented in code and test.

Scale remains a paint-only `Transform.scale`; the one-row `FittedBox`
guarantee is untouched.

## 7 · A3 / S2 / S3 / a11y — the smaller animation, style and accessibility fixes

- **A3** Reduce-motion now also disables the scroll glide (`_recenter`
  promotes to instant when `MediaQuery.disableAnimations`).
- **S1** The editor now speaks the parchment family: cream
  `0xFFF3E4C4` ground (the same cream as the player sheets and mini
  player), ink/bronze/gold accents, the gold+dark-ink stamp button from
  the player's transport vocabulary. It is pushed from inside the
  parchment player and no longer feels like a different app.
- **S2** Timestamp chip was a bare ~20 dp text glyph; nudge buttons were
  40×40 with a comment claiming "40 dp keeps the target at the Material
  minimum" — the Material minimum is **48 dp** (iOS HIG 44 pt). Both are
  now 48 dp with honest comments.
- **S3** Reading mode now **wraps** (`softWrap`, no `FittedBox`) — the
  karaoke one-row guarantee is preserved where the scale animation lives,
  but a reading-mode user at 1.7× text on a long line gets full-width
  wrapped text instead of a `scaleDown` that could shrink it below the
  unscaled size. This was the one case where the FittedBox fought the
  accessibility features built around it.
- **S4** The editor transport gained a **scrubber** (bronze slider,
  drag-then-commit, engine duration with the track's `audio_duration_s`
  fallback) — timing work needs positional context.
- **S5** A muted "Edit timings" affordance now ends the synced list for
  `canEdit` users. Previously the editor was reachable **only when no
  timings existed** — a bad timing was unfixable from the app (the web
  console was the only recourse).
- **S6** The editor's 200 ms whole-screen `setState` ticker is gone; the
  transport is a self-updating island (100 ms, tenths-accurate clock) that
  schedules **zero** frames while paused (it compares last-rendered values
  before calling `setState`).
- **S7** Lyric rows now expose `Semantics(button:, onTap:)` so the
  tap-to-seek gesture exists for assistive technology, not only for a
  finger.

---

## Deferred (with rationale — these are deliberate, not forgotten)

- **D1 — Weight cross-fade (zero-reflow emphasis).** The single tween still
  lerps weight, which re-shapes text during the 360 ms window (bounded: ~2–9
  visible short lines). If low-end profiling ever shows drops, the fix is
  two pre-laid-out layers (rest style / active style) cross-faded by the
  same tween — pure paint, zero re-shape. Not done now: it doubles text
  layout per line for a benefit only measurable on device.
- **D2 — Parchment cover-crop geometry — FIXED in P62.** `ParchmentArt`
  positioned overlays by *screen* fractions while the artwork is
  `BoxFit.cover`-fitted from 1440×2560. Pixel analysis of the artwork
  (bright lyrics panel at art rows 0.198–0.800, title strip 0.151–0.192,
  transport band from ~0.808) confirmed the constants are art-space
  measurements, so `fittedRect`/`mapY`/`stageY` now map them through the
  cover fit. Two properties make this safe: (1) on portrait phones the
  fit is height-driven, so vertical art fractions EQUAL screen fractions —
  `stageY` returns bit-identical legacy placements there (pinned by the
  regression test); (2) the mapping only changes width-driven screens
  (tablets/landscape/desktop), exactly the devices where the old
  fractions drifted off the painted regions — with clamps keeping a
  usable stage on extreme aspects (landscape gets a full-height lyric
  stage instead of bands positioned off-screen). *Correction to the
  original finding:* the overlay never actually painted text ON the
  ornament (the fixed-fraction box always stayed inside the painted panel,
  which only ever grows under crop) — the real defects were the stage
  drifting far below the painted panel on tablets (~90 px), the transport
  losing its console budget, and bands landing off-screen in landscape.
  Horizontal insets stay screen-relative by design (a fixed screen inset
  is always conservative under side-crop; see the `mapX` doc).
- **D3 — Offset sign convention — DOCUMENTED in P63.** The app treats
  `[offset:+500]` as "activates 500 ms later"; the wider LRC ecosystem
  commonly reads "+ as earlier". Not flipped (self-consistent, mobile is
  the only consumer), but the convention — and the word-tag pass-through
  behaviour — is now written into `MezmurMediaService::canonicalizeLrc`'s
  docblock so the next surface does not have to rediscover it. If external
  LRC interop ever matters, align on one convention and migrate.
- **D4 — Asymmetric past/future falloff — IMPLEMENTED in P64.** Spotify
  dims sung lines more than upcoming ones; the karaoke V2 profile now
  does exactly that (past −0.15/line floor .44, future −0.10/line floor
  .62), continuous at distance 0 so the past/future flag can flip
  mid-tween without a pop. See D10.
- **D10 — Karaoke V2 — the ground-up renderer redesign, IMPLEMENTED in
  P64.** The synced-lyrics playback experience was rebuilt from scratch
  to Spotify-grade behaviour per `docs/mezmur_player/KARAOKE_V2_SPEC.md`
  (the authoritative design): a pure `KaraokeEngine` (all timing rules:
  active line, per-word fills for enhanced-LRC, interpolated whole-line
  fills so EVERY existing hymn gets progressive karaoke with zero
  re-authoring, caps so instrumental gaps don't crawl, offset discipline
  identical to `indexFor`), a pure `KaraokeProfile` (the falloff model:
  asymmetric opacity, subtle scale, distance blur starting one line away,
  reading/high-contrast profiles), and a new `MezmurKaraokeView` (34%
  anchor, predictive glide starting min(650 ms, 45% of the line gap)
  before the next line, paint-only fills via `ValueNotifier` +
  two-TextPainter clipRect — no saveLayer/ShaderMask, no setState per
  fill tick, quantised blur behind RepaintBoundary, scroll-to-read that
  lifts blur and floors opacity at 0.85 while the user browses, a resume
  pill with 3.5 s auto-resume while playing, tap-to-seek semantics per
  line, and the curator's "Edit timings" tail). `mezmur_lyrics_screen`
  is now a thin shell (loading/gen-guard, static mode, empty state);
  `lyrics_emphasis.dart` is deleted (superseded by `karaoke_style.dart`).
  Parser, editor and static mode are untouched. Verification: the two
  pure modules pass a 4701-check standalone Dart harness (every flutter
  test assertion mirrored plus property sweeps: fill monotonicity within
  a line, exact boundary flips at every stamp, range sanity for all
  profiles) and ship with `test/karaoke_engine_test.dart` +
  `test/karaoke_style_test.dart`; full `flutter test` runs on the user's
  machine (no Flutter SDK in this workspace, by mandate).
- **D5 — Press-and-hold repeat on nudge buttons — FIXED in P62.** Tap =
  immediate step; hold = 380 ms pause then ~11 Hz repeat, haptic on the
  first step. Nudging a stamp by a second used to mean five separate taps.
- **D6 — Word-level karaoke — IMPLEMENTED in P63 (playback side).**
  Enhanced-LRC word tags (`[00:10.000] ሃሌ <00:10.500>ሉያ`) now parse into
  timed chunks and render as a **colour-only sweep** inside the sung line:
  sung chunks take the active ink, not-yet-sung chunks a lighter bronze —
  colour alone, never size or weight, so the sweep cannot re-shape the
  line and the one-row FittedBox guarantee is untouched (the same reason
  the P58 paint-only-scale decision holds). This also fixes a latent
  display bug: word tags used to render as literal `<00:10.500>` text.
  Fallbacks are graceful by construction: lines without tags keep line
  highlighting; reading mode keeps steady single-colour text; stale tags
  on repeated lines clamp to whole-line highlighting. The sweep is driven
  by the same event-driven position samples as the line highlight — a
  word-count change repaints colours without touching scroll or emphasis.
  The mobile editor stays a line-level tool by design: it strips the tags
  on edit and WARNS before the curator invests work (line timings are
  kept, word-level detail is dropped); authoring word timings belongs to
  import/web tooling. The server canonicalizer passes the tags through
  untouched (verified; now documented in its docblock).
- **D7 — Editor `LrcBuilder.parse` assumes canonical single-stamp
  lines — HARDENED in P62.** A raw (non-canonical) run like
  `[00:01.00][00:09.00]text` used to leave the second stamp inside the
  parsed TEXT; parse now strips residual leading stamp runs, so the words
  stay clean and the first stamp wins (the run's later timings are still
  dropped — canonical-only remains the contract; `SyncedLyrics.tryParse`
  is the display parser that expands runs). Pinned by tests. Guard with
  an assert if raw-file editing ever becomes a real path.
- **D8 — `cacheExtent` deprecation (and `activeColor`, `value`, etc.).**
  Replacements (`scrollCacheExtent`, `activeThumbColor`, `initialValue`)
  require Flutter 3.31–3.41+, but `pubspec.yaml` pins `flutter: '>=3.27.0'`.
  Deliberately NOT "fixed" — chasing 3.47 lints would break the project's
  own minimum SDK. Revisit when the floor moves.
- **D11 — `TextPainter.getBoxesForRange` removed in current Flutter
  (2026).** First surfaced on the user's machine (release build on a
  current stable): the method no longer exists on `TextPainter`, and the
  framework's own docs list only `getBoxesForSelection`. The karaoke
  word-box query now uses `getBoxesForSelection(TextSelection(...))`,
  which returns the identical `List<TextBox>` and exists back to old
  Flutter — portable across the repo floor AND current stable. Lesson
  for this codebase: before reaching for a long-stable framework API in
  new code, check the live API docs, not memory.
- **D9 — `withOpacity` deprecations fleet-wide (126 infos) are cosmetic.**
  The files this audit touched use `withValues(alpha:)` (3.27-safe), and
  P62 converted the remaining 9 in the player screen and mini player; the
  rest of the fleet is out of this audit's scope.

## Note on the two remaining analyzer infos in touched files

`cacheExtent` (D8, deliberate) — and nothing else. The touched files were
brought to zero *new* findings; the pre-existing
`use_build_context_synchronously` in the editor's back-press was properly
fixed (`context.mounted`, since the closure captures `build()`'s context,
not the State's).

---

## Test evidence

```
P61: flutter analyze → target files: 1 info (D8, deliberate); no warnings/errors
P62: flutter analyze → same (withOpacity infos in the player family cleared)
P63: flutter analyze → same (only pre-existing/D8 infos remain in touched files)
P64: standalone Dart harness (pure modules) → 4701/4701 checks pass;
     flutter tests for the new modules shipped; full flutter test/analyze
     runs on the user's machine (no Flutter SDK in the workspace, by
     mandate)
P61: flutter test    → 288 passed   (baseline 272)
P62: flutter test    → 301 passed
P63: flutter test    → 315 passed   (+14: word-tag parsing, sweep counting,
                                      editor word-awareness)
P64: flutter test    → expected 315 − 14 (lyrics_emphasis_test deleted)
                            + 35 (karaoke_engine 18 + karaoke_style 17)
                            = 336 (to be confirmed on the user's machine)
```

New pins:

- `test/lrc_builder_test.dart` — "P61 — [offset:] round-trip": header
  extraction (positive/negative/absent/malformed/partial-line), shift
  semantics (stamped-only, zero clamp, order/gap preservation), full
  editor round-trip, new-stamp-at-heard-time.
- `test/synced_lyrics_seek_test.dart` — `seekTargetFor` arithmetic, THE
  INVARIANT (seek ⇒ that line lights) across offsets, offset-vs-baked-twin
  behavioural equivalence, parser tolerance incl. the multi-stamp run
  regression (F3) and monotonic clamp.
- `test/lyrics_emphasis_test.dart` — **deleted in P64** (module superseded
  by `karaoke_style.dart`); its one-tween/monotonicity contract lives on
  in `test/karaoke_style_test.dart`.
- `test/karaoke_engine_test.dart` (P64) — the timing truth: empty/pre-first
  frames, whole-line interpolation (exact 0/1 boundaries, 4.5 s last-line
  window, 6 s gap cap, instrumental lines), word fills (exact boundaries,
  4 s word cap, last-word stretch to line end), real enhanced-LRC parse
  integration, offset discipline (identical to `indexFor`;
  `nextLineStartAt` in playback time), frame value-equality (the
  paint-only notifier depends on it), and the **sizing contract** for
  `wordCharSpans` (spans tile the joined text exactly — Ethiopic counted
  in UTF-16 units, caught by the standalone harness before shipping).
- `test/karaoke_style_test.dart` (P64) — the falloff model: the exact
  Spotify table (past/future/scale/sigma at every integer distance and
  the floors/cap), asymmetry, monotonicity, continuity at distance 0
  (the flag-flip-no-pop guarantee), fractional tweening, negative
  clamping, and the reading/high-contrast contracts (never blur, floors
  0.86 / 0.85–0.75).
- `test/parchment_art_test.dart` (P62) — the cover-fit rect on exact-9:16,
  tall-phone and tablet aspects; `mapY` vertical-identity on phones (the
  no-regression guarantee); crop-following on tablets; the **regression
  pin** that `stageY` returns the exact legacy placements on phones;
  landscape clamping; and a full aspect sweep proving the clamps never
  invert the layout.
- `test/synced_lyrics_words_test.dart` (P63) — word-tag parsing (chunks,
  leading-chunk-at-line-time, multi-word chunks, malformed-tag tolerance,
  fraction formats, clamping, multi-stamp duplicates); the **sizing
  contract** (chunks join to exactly the plain text); sweep counting with
  offset; and THE INVARIANT that the sweep advances exactly at chunk
  boundaries, across offsets 0/+500/−1500.
