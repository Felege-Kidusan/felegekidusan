# Lyrics syncing — how both systems work (P44)

"Lyrics syncing" means two different things in this project. Both exist.

---

## A) Data sync — getting lyrics onto the device

**Status: working.** Lyrics are heavy blobs, so they are NOT downloaded
with the hymn list.

1. Delta sync pulls metadata only (`include_lyrics=0`) — fast first run
2. Each cycle backfills **15 hymns' lyrics** (`getHymnsMissingLyrics`)
3. Opening a hymn fetches its lyrics on demand

Telegram's "download media as you go" pattern: first sync stays
seconds-fast instead of pulling megabytes of text nobody has asked for.

> ⚠️ **Known gap (Defect E).** Because the backfill is only 15 per
> cycle, most cached rows have no local lyrics for a while, so on-device
> lyrics *search* under-returns until it catches up. Still open.

---

## B) Time-synced lyrics — karaoke highlighting

**Status: was dormant, now activated by P44.**

### Format

Standard **LRC**, canonicalised on save:

```
[00:12.500] ሃሌ ሉያ
[00:18.250] ሀባ ሰላምከ
[00:24.000] ዘኢትዮጵያ
```

### Playback

Both players parse to `{t, text}`, then on each `timeupdate` find the
last line whose time has passed, mark it `.active` (earlier lines
`.past`), and smooth-scroll it to centre. Falls back to static lyrics
whenever `lyrics_synced` is empty.

### What P44 found

The feature was ~85% built and **0% usable**:

| Piece | Before |
|---|---|
| DB columns | ✅ |
| LRC validator/canonicaliser | ✅ |
| save/remove service functions | ✅ |
| mobile API route | ✅ |
| web player renderer | ✅ |
| mobile lyrics screen | ✅ |
| **web admin API action** | ❌ |
| **any authoring UI** | ❌ |

`saveSyncedLyrics()` was reachable from exactly one route that no client
called. `lyrics_synced` was always NULL, so both players silently used
static lyrics and the whole feature never ran.

### The editor

Mezmur console → hymn → **Audio** → **Sync lyrics** (only appears once
audio is `ready` — timings without audio are meaningless).

| Key | Action |
|---|---|
| `Space` | stamp the highlighted line, advance |
| `Backspace` | step back, re-arm that line |
| `←` / `→` | nudge audio ∓2s |
| `Enter` | play / pause |
| `−` `+` | nudge one line by 0.2s |
| click a timestamp | seek there |

Play the hymn and tap Space as each line begins. This is how every LRC
tool works (MiniLyrics, Musixmatch) — hand-typing timestamps for a
70-line hymn takes an hour and still drifts.

Re-opening restores existing timings where the line text still matches,
so corrections are incremental. Saving with nothing timed removes the
synced lyrics and falls back to static (confirmed first).

### Server guarantees

`canonicalizeLrc()` sorts by time, normalises to `[mm:ss.mmm]`, rejects
`[Section]` markup and untimed lines, caps at 20,000 lines, validates
UTF-8, bumps `revision` and writes an audit row.

Verified by porting the validator and exercising the round trip:

- editor output is accepted and returns **byte-identical** — the JS
  already emits the canonical dialect, so saving is not a silent rewrite
- canonicalisation is idempotent
- out-of-order stamps are sorted server-side
- `[Chorus]`, untimed text and empty input are rejected
- the player's own parser reproduces the input times exactly

---

## Verify in a browser

- [ ] Sync lyrics appears only when audio is ready
- [ ] audio plays inside the editor; the clock ticks
- [ ] Space stamps the current line and advances
- [ ] timings survive closing and reopening
- [ ] the saved hymn karaoke-scrolls in the web player
- [ ] the mobile app shows the same timings after a sync
- [ ] "Clear all timings" + save falls back to static lyrics

## Not built (deliberate)

**Word-level highlighting** (Apple Music style) needs Enhanced LRC and a
rewrite of both parsers, and is far slower to author. Line-level is what
congregations need for following along.

**Mobile authoring** — precise tapping on a phone is harder, and the
console has the keyboard and the big screen.

**Auto-alignment (forced alignment / ASR)** — the real long-term answer
for a large catalogue, but it needs an Amharic acoustic model. Revisit
if manual timing becomes the bottleneck.
