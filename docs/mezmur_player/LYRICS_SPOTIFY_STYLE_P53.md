# Lyrics — Spotify-style, one-row lines (P53)

Follow-up to the live build. Two concrete defects and the requested redesign:

1. **A single lyric "line" was being split into two rendered rows.**
2. **The active-line highlight background (the gold blob/pill) was too
   distracting — remove it and recreate Spotify's lyric emphasis in the
   parchment design.**

---

## 1. Root cause of the wrapping

The lyric text was confined to a narrow column, narrower than the painted
frame:

* The player placed the lyric stage at `boxInsetX = 0.168` (16.8% margin each
  side), but the ornamental frame on the background art is at ≈ 9–11% margins.
  So the text got far less width than the visible rectangle provides.
* Each line was wrapped in a pill with `14px` horizontal padding, `6px` outer
  margin and `16px` inner padding — ~60px of consumed width on top.
* Result: at the reader's text size the available width was ~130px, so even a
  short Amharic line wrapped onto two rows.

**Fix (three parts):**
* `ParchmentArt.boxInsetX` `0.168 → 0.11` — line the text up with the frame's
  writing area (much wider run per line).
* Dropped the pill entirely (see below), removing its ~60px of horizontal
  padding.
* ListView side padding `10 → 6`.

**Guarantee of one row:** each line is now `softWrap:false` inside a
`FittedBox(fit: BoxFit.scaleDown)`. A `softWrap:false` text lays out on a single
line at its intrinsic width; the `FittedBox` scales it into the available width
instead of wrapping it. So a lyric is *always* exactly one row — no matter how
wide the text or how large the font.

---

## 2. Spotify-style emphasis (no bubble)

Removed the gold gradient, the blur halo and the left accent bar. The sung line
is now emphasised exactly the way a modern lyrics/streaming view does it — by
rendering, not by a box:

| Element | Sung line | Other lines |
|---|---|---|
| Color | darkest ink (`inkStrong`) — hard, saturated highlight | warm **bronze** that visibly recedes (`inkStrong` in high-contrast mode) |
| Weight | **bold** (`w800`) | `w500` |
| Opacity | 1.0 | recedes with distance (down to 0.42) |
| Scale | 1.0 | 0.86 at the nearest line, settling at 0.82 |

This is still the pure distance rule (`LyricEmphasis`) and still never changes
font *size* in layout (only a scale transform), so nothing reflows onto an extra
row. The emphasis is animated with implicit `AnimatedOpacity` + `AnimatedScale`
behind a `RepaintBoundary`, and `MediaQuery.disableAnimations` is honoured.

> Tuning (P54): the first pass was too subtle (scale step 2.5%, uniform dark
> ink, identical weight). The active line is now unmistakable — **bold,
> darkest** — while the rest recede to bronze and fade to ~42%.
>
> Smoothness tuning (P55–P58): the size emphasis is a **paint-only
> `AnimatedScale` wrapper** around the `FittedBox` — it never changes
> `fontSize`, so the Amharic glyphs never re-shape/re-layout and the size
> change scales the already-composited line on the GPU (perfectly smooth, zero
> reflow). The falloff is gentle (nearest line 0.92, settling 0.86), the
> transition uses a 360 ms `easeInOutCubic`, and the centering scroll glide
> uses stiffness 4.5 so the exiting line drifts away in step with its fade.

---

## 3. Files

| File | Change |
|---|---|
| `lib/screens/mezmur/parchment_style.dart` | `ParchmentArt.boxInsetX` `0.168 → 0.11` (wider lyric stage). |
| `lib/screens/mezmur/mezmur_lyrics_screen.dart` | Removed pill/gradient/glow/accent-bar; each line is one row via `FittedBox`+`softWrap:false`; Spotify-style bold/bright current line; thinner list padding. |
| `lib/services/lyrics_emphasis.dart` | Softened `karaoke` profile (scale step 0.025, floor 0.90). |

---

## 4. Verify on device

1. `flutter analyze && flutter test` — `lyrics_emphasis_test` passes (karaoke +
   reading profiles, and the clamp can no longer throw).
2. Open a timed hymn — every line is **one row**, including the sung line (no
   more split lyric).
3. The sung line is **bold + bright**, others recede; there is **no pill /
   blob / glow** around it.
4. Drag the **Aa** text-size slider up to Large — lines still stay one row
   (they scale down to fit rather than wrapping).
5. Play → the current line follows the audio smoothly (the P51 glide is
   unchanged); pause → the list stops animating.
6. Reading mode / high contrast still work (P52).
