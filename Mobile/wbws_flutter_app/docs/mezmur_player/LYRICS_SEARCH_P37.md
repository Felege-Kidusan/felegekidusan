# P37 — Telegram-style lyrics search

Deep search across every hymn's lyrics, ranked by relevance, with the
matched words highlighted in each result the way Telegram and Telegram X
do it.

---

## Part 1 — Audit of what was there

### The headline defect: Amharic homophones were ignored

Amharic has **homophones** (ድምጸ ሞክሼ ሆሄያት) — distinct characters that
sound identical and are used interchangeably in ordinary writing. The
same word is spelt several equally-correct ways by different people:

| Word | Spellings all in real use |
| --- | --- |
| sun | ጸሐይ · ጸሀይ · ፀሐይ · ፀሀይ |
| peace | ሰላም · ሠላም |

The app compared **raw code points** everywhere — the word index, the
scorer and the snippet builder. So a member who typed ጸሀይ simply did not
find a hymn stored as ፀሐይ. For a hymn book whose entire corpus is
Amharic, that is not a ranking nicety; it is a total search failure, and
it was silent — the app returned "no results" as though the hymn did not
exist.

There was no normalisation of any kind in the codebase.

### The index could not support highlighting at all

`hymn_search_words(word, hymn_id)` is a **bag of words with no
positions**. Nothing recorded *where* a word occurred, so highlighting
was impossible in principle, and the snippet builder had to re-scan the
raw lyrics blob with `indexOf` to guess a location.

### Four further defects found while reading the query path

1. **`LIMIT` applied before ranking.** The candidate query was
   `WHERE word LIKE a% OR word LIKE b% … LIMIT 500` with no `ORDER BY`.
   SQLite returned an arbitrary 500 rows and the best match was routinely
   cut *before* the ranker ever saw it. Symptom: a hymn you know exists
   fails to appear for a common word.
2. **Multi-word queries were pure OR.** No preference at all for hymns
   containing *every* term over hymns containing just one.
3. **Snippet chose the first hit, not the best.** `_lyricSnippet` took
   `indexOf` of the earliest term, so a query matching a tight phrase
   late in the lyrics showed an unrelated fragment from the top.
4. **No phrase or proximity notion.** Words scattered paragraphs apart
   scored the same as the exact line the user was half-remembering.

---

## Part 2 — Research

### Why NOT SQLite FTS5

FTS5 is the obvious answer and gives `bm25()`, `snippet()` and
`highlight()` for free. It was rejected on two independent grounds:

**Availability.** `sqflite` links the **device's system SQLite**, and
FTS5 is not compiled into many Android builds — `no such module: fts5` is
a well-documented failure, generally absent below API 24 and vendor
dependent above it. A search index that fails to create on a member's
phone is unacceptable for the primary way people find a hymn. Bundling a
second SQLite (`sqlite3_flutter_libs`) would fix availability but adds
several MB per ABI to an APK used on low-end devices over poor
connections.

**It would not solve the actual bug anyway.** FTS5's `unicode61`
tokenizer does Unicode case folding and Latin diacritic stripping. It has
**no concept of Ethiopic homophones** — ጸ and ፀ are simply different
characters to it. The custom-tokenizer API is C-only and cannot be
reached through sqflite. So even with FTS5 the headline defect would
persist.

The ranking, snippet and highlight logic FTS5 would have done in C
therefore lives in Dart, where it is unit-testable and where it can be
taught Amharic.

### How Telegram actually presents results

Telegram and Telegram X render the matched substring in the **accent
colour with a heavier weight**, leaving surrounding text normal — not a
yellow marker-pen background, which reads as "warning" and fights a
themed surface. Prefix matches highlight **only the typed prefix**, not
the whole word. Results show a one-line excerpt centred on the match.
All three behaviours are reproduced here using the app's bronze/primary
accent.

### Amharic normalisation

The standard remedy in Amharic NLP is to fold each homophone group onto a
single representative (the first entry of that row in the fidel chart).
Applied to **both** the index and the query so the two meet at the same
token.

---

## Part 3 — What was built

### `lib/services/amharic_text.dart`

Homophone folding for the ሀ, ሰ/ሠ, አ/ዐ and ጸ/ፀ groups, Ethiopic
punctuation (፡ ። ፣ …) folded to separators, and a positional tokenizer.

**Length-preserving by contract.** `normalize` maps exactly one code
point to one code point, and a test asserts
`normalize(s).length == s.length`. This is load-bearing: matches are
found in *normalised* text but painted over the *original* string, so any
length change would silently mis-place every highlight. A tempting extra
rule — collapsing labialised pairs (ሉዋ → ሏ) — is deliberately **not**
implemented because it would shift offsets. Its benefit is small;
correct highlighting is not.

A skipped short word still consumes an ordinal, so two words either side
of it are not mistaken for a phrase.

### `lib/services/lyrics_search.dart`

Pure-Dart ranking, snippets and highlight ranges.

Tiers, ordered so they can never overlap regardless of term count:

| Tier | Weight |
| --- | --- |
| Exact title | 1000 |
| Title prefix | 600 |
| Title word | 400 |
| Title fuzzy (≤1 edit, words ≥4 chars) | 150 |
| Lyrics phrase (consecutive) | 300 |
| Lyrics word | 60 |
| Lyrics proximity | 40 |
| All query terms present | +250 |
| Match at start of lyrics | +25 |

Snippets centre on the **densest cluster** of terms rather than the first
occurrence, snap to word boundaries, and flatten newlines so a result row
stays one line. Ranges are re-derived against the flattened excerpt,
because collapsing whitespace shifts offsets.

Highlight ranges are returned as `[start,end)` objects rather than
embedded `<b>` markup, so the UI never has to parse tags out of user
content.

### `lib/widgets/highlighted_text.dart`

Renders text with ranges emphasised. Defensive: a malformed or stale
range degrades to plain text rather than throwing a `RangeError` inside a
list item.

### Wiring

* **`local_db.dart`** — schema **v22**. The word index is **dropped and
  rebuilt**, because every previously indexed word was stored *without*
  homophone folding and could never match a normalised query.
  `_reindexHymnSearchIndex` now uses the shared normaliser and batches
  its inserts. The candidate query became
  `GROUP BY hymn_id ORDER BY COUNT(DISTINCT word) DESC LIMIT ?`, fixing
  defects 1 and 2 together. The old `_searchTokens` was deleted so it
  cannot drift out of sync.
* **`hymn_store.dart`** — inline scoring replaced by `LyricsSearch`;
  ranges travel on the row. Server-supplied snippets get ranges computed
  via `highlightRangesFor`, so server-discovered rows are not the only
  results rendered without highlighting. Category/singer search now
  normalises both sides too.
* **`mezmur_hymns.dart`** — title and snippet both render through
  `HighlightedText`.

---

## Verification

`dart analyze` clean. **150 tests passing** (108 previous + 42 new).

Rendered output from a harness run against the real ranker
(`[…]` marks a highlight):

```
query "ጸሀይ"      title : [ፀሐይ] ወጣች            score=650  ← homophone
query "halle"     title : [Halle]lujah Chorus  score=850  ← prefix only
query "ሰላም ለኪ"   lyrics: … [ሰላም] [ለኪ] ማርያም …  score=710  ← phrase
query "ሰላም ለኪ"   lyrics: [ሰላም] ብዙ … [ለኪ]      score=435  ← scattered
query "hallelujuh" title: [Hallelujah]         score=400  ← typo
```

The first line is the whole point: the query and the stored title share
no code points, yet the correct characters are highlighted.

### Device checks still required

Ranking and highlighting are fully covered by tests, but the database and
widget layers cannot be type-checked here (no Flutter SDK):

1. **First launch after update** — the v22 migration rebuilds the word
   index. On a large catalogue this runs once at open; confirm it is not
   perceptibly slow.
2. Search `ጸሀይ` and confirm hymns spelt ፀሐይ appear, highlighted.
3. Search a common word and confirm the hymn you expect is present — this
   is the `LIMIT`-before-ranking fix.
4. Search a two-word phrase and confirm the exact line ranks above hymns
   with the words scattered.
5. Confirm highlighting appears on both the title and the lyrics line.
6. Search while offline, then online, and confirm server-discovered rows
   are highlighted too.
