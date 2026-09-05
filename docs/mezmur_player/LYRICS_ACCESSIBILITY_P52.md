# Mezmur lyrics — accessible, readable text (P52)

The karaoke engine (P51) is smooth, but readability is a first-class
requirement: **elderly members and younger members both have to be able to
read the words**, and some members **only need the lyrics** (they want to read
the hymn, not necessarily follow the sung line). This makes the work an
**accessibility** task, not just a visual one.

---

## 1. What "best industry standard" means here

Accessible dynamic text is governed by two layers that must BOTH work:

1. **OS accessibility font scaling** (`MediaQuery.textScaler`). The app never
   disables it, so a user who already set the system font size to "extra
   large" gets an even bigger result for free.
2. **An in-app, persistent text-size control.** Users don't always know about
   the OS setting, and a congregational app should offer a one-tap choice.
   This is exactly what browsers and reading apps do with their "Aa" control.

Plus **a distraction-free readable mode** for members who only want the words:
steady, full-size text with minimal motion (no aggressive shrink/fade), so the
lyric reads like a clean hymn sheet instead of a dramatic sing-along.

The state lives in one place — `LyricsReaderSettings` — is `ChangeNotifier`,
and is persisted via `shared_preferences` (already a dependency). That means:
the player's "Aa" sheet and every lyrics screen share the same source of truth,
one choice applies across all hymns, and it survives restart (an elderly user
is never asked again).

---

## 2. Files

| File | Change |
|---|---|
| `lib/services/lyrics_reader_settings.dart` | **New** — persisted accessibility model (`textScale`, `readingMode`, `highContrast`), `ChangeNotifier` singleton, `boot()` restore. |
| `lib/services/lyrics_emphasis.dart` | Added `LyricEmphasisProfile` with two presets: `karaoke` (default) and `reading`. `forIndex` now takes a `profile`. |
| `lib/screens/mezmur/mezmur_lyrics_screen.dart` | Applies the reader model: `fontSize = 17 × textScale`, reading-aware line-height & contrast, reading profile (flat scale), drops the heavy glow in reading mode, re-centres on a size change. |
| `lib/screens/mezmur/mezmur_player_screen.dart` | New **"Aa" chip → Text & reading sheet**: Large text toggle, Reading mode switch, High contrast switch, and a fine-grained text-size slider with an `A→A` preview. |
| `lib/main.dart` | Boots `LyricsReaderSettings` at app start (device-local, no network). |
| `test/lyrics_emphasis_test.dart` | New tests pin the reading profile: scale stays `1.0`, fade stays `≥0.86`, active line intact, and it is strictly gentler than karaoke. |

---

## 3. The three controls

| Control | Effect | Why |
|---|---|---|
| **Large text** | One-tap toggle to `textScale = 1.35` (back to default `1.15`) | The single most common accessibility need; covers "old people and the youngs" out of the box. |
| **Text size slider** | `0.85 → 1.70` granular | Fine control for hard-of-sight users and for the young who want it smaller. |
| **Reading mode** | Flat emphasis (no shrink, full-size off lines, only gentle fade), steady text, less motion. | "Lyrics only" — for members who just want to read. |
| **High contrast** | Darkest ink (`inkStrong`) for all text. | Maximum legibility on the parchment. |

Base font size was raised from **16 → 17**, and multiplied by the user's
`textScale`. With the default `1.15` and one-tap large (`1.35`), an ordinary
line reads at ≈ **19.5 – 23** without touching the OS — and higher still if
the user also raised the system font size. Line height grows a little with
size and reading mode so large text is never cramped.

---

## 4. Reading mode vs. karaoke mode

| Property | Karaoke (default) | Reading ("lyrics only") |
|---|---|---|
| Emphasis profile | `karaoke`: shrink 5.5%/line, fade 15%/line | `reading`: no shrink, fade 5%/line (floor 86%) |
| Off-line size | smaller with distance | **full size** — nothing reflows or shrinks |
| Active line | gold glow pill + blur halo | gold gradient pill, **no heavy halo** |
| Motion | dramatic deep-water reveal | steady, minimised |
| Purpose | sing-along immersion | read the words comfortably |

Because emphasis is *always* expressed through scale/opacity (never font
size/weight — the P51 hard rule), neither mode can re-wrap a long Amharic line
and push words to a second row. The reflow bug stays fixed in BOTH modes.

---

## 5. Mandatory rules — confirmed

| Rule | How it is met |
|---|---|
| Front/back separation, easy UI updates | The preference model (`LyricsReaderSettings`) and the emphasis rule (`LyricEmphasisProfile`) are pure services with unit tests; widgets only read them. A redesign touches only the widgets. |
| Scale to hundreds of thousands | State is a tiny persisted model; no per-user server work. Rendering cost is unchanged (same `RepaintBoundary` + implicit animations). |
| 100% security | **No new network or storage of personal data.** The only persistence is local device preferences (text-size/mode), in `SharedPreferences` alongside existing prefs. No PII is transmitted. |
| Don't break anything | Additive: new service + one screen/one sheet/one boot line. Existing karaoke path preserves the `karaoke` profile as the default. Emphasis API is backward compatible (`profile` is optional). |
| Maintainable / extensible | Adding another accessibility option (e.g. auto-scroll toggle, a fullscreen reader) is one new field on `LyricsReaderSettings` + one widget. `LyricEmphasisProfile` can be extended with new presets without touching call sites. |
| Industry standards | Respects OS font scaling, offers a discoverable persistent "Aa" control, provides a distraction-free readable mode, and honors `MediaQuery.disableAnimations`. |

---

## 6. Verify on a device

1. `flutter analyze` (and the CI `nullable_field_lint` gate) → clean;
   `flutter test` → `lyrics_emphasis_test` passes (both profiles).
2. Open a timed hymn → tap the new **Aa** chip.
3. Toggle **Large text** → the lyric text grows instantly; toggle again → back
   to default.
4. Drag the **text size** slider → it follows live; the active line stays
   centred as the size changes.
5. Toggle **Reading mode** → the lyrics become steady full-size text; the gold
   halo disappears; the active line is still subtly marked on the left.
6. Toggle **High contrast** → the text darkens.
7. Kill and relaunch the app → your text size / reading mode are remembered.
8. The OS font setting on the phone (Settings → Display → Font size) still
   scales the lyrics on top of the in-app control.
