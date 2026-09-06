# Karaoke V2 — complete redesign spec (P64)

> **Status: IMPLEMENTED** (pending the user's local `flutter test` /
> device run). `lib/services/karaoke_engine.dart`,
> `lib/services/karaoke_style.dart`,
> `lib/screens/mezmur/mezmur_karaoke_view.dart` (new);
> `mezmur_lyrics_screen.dart` rewritten as a thin shell;
> `lyrics_emphasis.dart` deleted. Pure modules verified by a 4701-check
> standalone Dart harness; flutter tests shipped
> (`test/karaoke_engine_test.dart` 18, `test/karaoke_style_test.dart` 17;
> suite expected 336).

Ground-up replacement of the synced-lyrics karaoke system: new engine,
new renderer, new interaction model. Goal: the reference standard is
Spotify/Apple Music grade — progressive word fill, depth (blur +
asymmetric falloff), anchored predictive scroll, and a scroll-to-read
escape hatch — expressed in the parchment design language.

Research basis (external, read 2026-09):

- Enhanced LRC puts a word timestamp before each word:
  `[00:15.20]<00:15.20>Hello <00:15.65>beautiful` — the de-facto
  karaoke format (Kugou / QQ / NetEase / AIMP).
  https://www.quicklrc.com/subtitle-formats/enhanced-lrc
- Production Flutter lyric engines use highlight WIDTH driven by
  playback progress, distance-based scroll duration mapping,
  configurable anchors, tap-to-seek and auto-resume.
  https://pub.dev/packages/flutter_lyric
- Karaoke frames with per-token start/end times (interpolated between
  transport polls; rendering stops while paused).
  https://pub.dev/packages/desktop_lyrics , https://github.com/ateymoori/lyricglow
- Users' most-praised behavior (Apple Music): the sung line is
  brightened but "if you scroll, you can clearly read them" — scrolling
  lifts the depth treatment. https://www.reddit.com/r/truespotify/comments/1jg6qu3/

## What changes (and what deliberately does not)

| Area | V1 (P51–P63) | V2 (this spec) |
|---|---|---|
| Fill | binary word switch (word-timed docs only) | **continuous 0..1 progress**: per-word for enhanced LRC, **interpolated across the line for plain LRC** — every existing hymn gets a karaoke fill with zero re-authoring |
| Emphasis | symmetric distance falloff, scale-heavy | asymmetric **past dims harder than future**, subtle scale, **distance blur** |
| Scroll | centred active line, glide on change | active line **anchored at 34%** of the stage, **predictive glide** (starts before the line is due), distance-proportional settle |
| User scroll | 2.2 s silent auto-resume | **resume pill** + 3.5 s auto-resume while playing; paused browsing is respected |
| Repaint model | widget rebuild per fill change | fill is **paint-only** (ValueNotifier → CustomPaint repaint; no rebuild, no relayout at 60 Hz) |
| Parser | — | **unchanged** (`SyncedLyrics` — validated by tests; V2 builds on it) |
| Editor | — | **unchanged** (line-level tap-to-sync; warns on word-timed docs) |
| Reading mode | flat text | flat text (same contract: steady, full-size, wraps) |

## Engine — `lib/services/karaoke_engine.dart` (pure Dart, zero imports beyond the parser)

```
KaraokeFrame {
  int activeIndex;          // -1 before the first line
  double lineFill;          // 0..1 across the active line (no-word docs)
  List<double> wordFills;   // per-word 0..1 (enhanced LRC; empty otherwise)
  Duration? nextLineStartAt;// PLAYBACK time (offset applied) — drives predictive scroll
}
KaraokeEngine.frameFor(SyncedLyrics doc, Duration position)
```

Rules (all offset-aware, matching `indexFor` arithmetic exactly):

- Active line: `doc.indexFor(position)` (single source of truth).
- Line window: `[lineStart, nextLineStart)`; the last line uses
  `lineStart + 4.5 s`. Wordless fill caps its window at 6 s so long
  instrumental gaps don't crawl.
- Word window: `[wordStart, nextWordStart)`, last word ends at the line
  window end; each word window additionally caps at 4 s.
- Every fill is clamped 0..1; a zero-width window yields 0 or 1
  (already started → 1).
- `nextLineStartAt` = next line's start + offset, null past the last line.
- Value equality: two frames with equal content are `==` (the renderer's
  ValueNotifier then stays silent when nothing changed — e.g. paused).

Also `KaraokeEngine.wordCharSpans(words)` → char offsets of each chunk in
the joined text, with the pinned property `spans.last.end ==
words.join().length == line.text.length`.

## Style — `lib/services/karaoke_style.dart` (pure Dart)

One call: `profile.forDistance(distance, past: …)` →
`{opacity, scale, sigma}`. Curves (continuous at d=0 for both branches —
the past/future flag can flip without a pop):

| d (lines from active) | future opacity | past opacity | scale | blur σ |
|---|---|---|---|---|
| 0 | 1.00 | 1.00 | 1.00 | 0 |
| 1 | 0.90 | 0.85 | 0.97 | 0 |
| 2 | 0.80 | 0.70 | 0.94 | 0.50 |
| 3 | 0.70 | 0.55 | 0.91 | 1.00 |
| ≥4 | 0.62 floor | 0.44 floor | 0.88 floor | 1.40 cap |

- `KaraokeProfile.spotify` — the table above (σ = 0.5·max(0, d−1), cap 1.4).
- `KaraokeProfile.reading` — flat: opacity floor 0.86, no scale, no blur.
- `KaraokeProfile.highContrast` — no blur, floors 0.85 future / 0.75 past.

Motion stays the P61 pattern: ONE tween per line over its distance;
opacity/scale derive from it; blur σ derives from it (quantised to 0.35
steps at paint time so raster caches survive).

## Renderer — `lib/screens/mezmur/mezmur_karaoke_view.dart`

- Owns everything synced: position stream (event-driven), play/pause
  ticker edges, lifecycle (suspend while backgrounded, force re-sync on
  resume), scroll, user-hold, predictive glide, resume pill, per-line
  Semantics + tap-to-seek (`doc.seekTargetFor`), and the "Edit timings"
  tail for curators.
- Anchor: `getOffsetToReveal(line, 0.34)`; list padding 36% top / 30%
  bottom so the first/last lines can reach the anchor.
- Glide: exponential, frame-rate independent (k = 1 − e^(−4·dt)) — settle
  time naturally scales with jump distance.
- Predictive: when `nextLineStartAt − position ≤ min(650 ms, 45% of the
  line gap)`, retarget the glide to the next line so it settles as the
  line begins. Disabled while the user holds the scroll.
- Per line: `RepaintBoundary` → conditional `ImageFiltered` blur (never
  at σ≈0, never in reading/high-contrast, **never while the user is
  scrolling** — scroll-to-read) → `Opacity` → `Transform.scale` (paint
  only) → `FittedBox(scaleDown)` → `CustomPaint`.
- The painter holds two laid-out `TextPainter`s (role base colour; fill
  ink twin). The ACTIVE line's `CustomPaint` gets
  `repaint: frameNotifier`: fills update by **repaint only** — no
  setState, no relayout, at position-stream rate. Fill pass = per-word
  (or whole-line) `clipRect` of the fill twin — no saveLayer, no
  ShaderMask (cheapest possible progressive fill).
- Colours are role-based and snap with the role (bronze receded /
  bronzeSoft pending + ink filled); depth motion is carried by
  opacity/scale/blur tweens. Weight snaps with the role too (w500/w800)
  — one bounded relayout per line change instead of 60 Hz re-shaping.
- Reading mode renders plain wrapping `Text` (steady single colour).
- Reduce-motion: instant re-centre, no glide, no predictive scroll;
  fills continue (they are information, not decoration).
- Resume pill: bottom-centre cream chip, down-chevron, 180 ms
  slide+fade; `Semantics('Return to the current line')`; auto-resume
  3.5 s after scroll ends while playing; paused users browse freely
  until they tap it (or tap any line to seek, which re-engages).

## Perf budget (the reason this design is shaped this way)

- Playing: per position event → one `frameFor` (µs) + repaint of ONE
  small painter. Line change → one setState of the list + one tween per
  visible line (paint-only transforms) + ≤2 relayouts (role change).
- Paused: zero work (position stream silent; no timers anywhere).
- Blur: ≤ 1.4 σ, quantised, wrapped in per-line RepaintBoundaries,
  skipped on the paths above.
- Fallbacks: no words → line fill; no doc → static lyrics screen
  (unchanged); reading mode → flat Text.

## Verification

- Pure logic (engine, style, spans): pinned by
  `test/karaoke_engine_test.dart` / `test/karaoke_style_test.dart`
  (flutter_test, runs on any dev machine) **and** verified in this
  workspace with a temporary Dart SDK on a standalone harness (SDK
  deleted immediately after, per the space constraint).
- Widget layer: written against stable, repo-proven APIs only
  (TextPainter/TextBox/getBoxesForRange, CustomPaint repaint listenable,
  ImageFiltered, Ticker, getOffsetToReveal — all already used in this
  codebase or its dependencies); verified by careful static review here,
  build + on-device testing on the user's machine.
- `lib/services/lyrics_emphasis.dart` and its test are DELETED
  (superseded by `karaoke_style.dart`). Parser, editor and their tests
  are untouched.
