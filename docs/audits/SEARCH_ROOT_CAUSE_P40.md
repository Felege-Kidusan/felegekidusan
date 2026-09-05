# Mezmur search — deep audit and root cause (P40)

## Evidence

Two screenshots, same session:

| Query | Results shown | Highlighting |
|---|---|---|
| `test` | ሃሌ ሃሌ ሉያ, ልዑል ውኑቱ, ሐረ እያሱስ | none |
| `ሰላምክ` | ሃሌ ሃሌ ሉያ, ልዑል ውኑቱ, ሐረ እያሱስ | none |

Two queries with nothing in common returned a **byte-identical list**.
A third screenshot shows hymn #1's lyrics contain `test search abebe`.

Deductions, before touching code:

1. Identical output for unrelated queries = **no filtering at all**.
   Not a matching-quality bug.
2. Zero highlighting on a row that genuinely matches = those rows
   **never passed through the ranker** (ranked rows always carry
   highlight ranges).
3. Failure is **script-agnostic** (English + Amharic alike), so it
   cannot be the Amharic morphology issue addressed in P39.

## Root cause

`hymn_store.dart` → `searchHymnsUnified`, merge step:

```dart
final localRow = byId[id];
if (localRow == null) {
  byId[id] = m;   // server row added, never verified
  continue;
}
```

Local rows were filtered correctly (`LyricsSearch.rankAll` drops
non-matches). **Server rows bypassed the ranker entirely** — not scored,
not highlighted, not filtered.

Trigger: when the deployed backend does not filter by `search` — an
older deployed build, or an empty `mezmur_hymn_words` table causing
`searchWordCandidates()` to return `[]` — it responds with the ordinary
hymn **list**. The app rendered that verbatim.

This one line explains every symptom simultaneously:

- same results for every query
- no highlighting anywhere
- "no match" for words visibly present in the lyrics
- identical failure in English and Amharic

The backend source in this repo is correct (it scores rows and drops
`similarity <= 0.0`). The defect is that the client **trusted** it.

## Why P37–P39 missed it

Each round verified the pure-Dart ranker in a harness, where it passes.
Nothing traced what happened to rows **after** ranking. The lesson:
when a fix does not change the symptom, re-derive from the data path
instead of refining the same layer.

## Fix — the client is authoritative

A server row is now a **candidate**, never a result:

- `_verifyServerRow()` ranks it with the same engine as local rows and
  returns `null` when it does not match.
- The server still surfaces hymns whose lyrics have not downloaded yet,
  but cannot inject noise. A stale backend degrades to **local-only**,
  never to wrong results.
- The server's `similarity` is no longer imported: the two engines use
  different scales, so mixing them reordered the list arbitrarily. Only
  match **context** (a snippet from lyrics we lack) is taken.

## Secondary defect found while verifying

Fuzzy rescue existed for **titles only**, so a near-miss in lyrics fell
straight to "no results". In Amharic this is the common case —
possessive/verb endings shift the final syllable (`ሰላምከ` / `ሰላምክ`), a
one-character edit.

- Added `SearchWeights.lyricsFuzzy = 20`, below every real lyrics hit
  (`lyricsInfix = 34`), so it can never displace a true match.
- Passed the fuzzy ordinal to the snippet anchor — `_bestAnchor` only
  recognises exact hits, so such a row would otherwise render blank.

## Verified against the screenshot data

```
query "test"
  BEFORE: [1, 2, 3]   every row, no highlights
  AFTER : id=1 ሃሌ ሃሌ ሉያ  score=310
          snippet: … ሰላምከ ዘኢትዮጵያ [test] search abebe

query "ሰላምክ"
  BEFORE: [1, 2, 3]
  AFTER : []          honest "no results"

identical result sets? false
```

`dart analyze` clean; **198 tests pass** (12 new, pinning the
reject-non-matching-rows contract).

## Still to confirm on device

- [ ] `test` returns only ሃሌ ሃሌ ሉያ, with `test` highlighted in the snippet
- [ ] a nonsense query returns an empty state, not the full catalogue
- [ ] different queries return visibly different lists
- [ ] typing `ሰላም` finds lyrics containing `ሰላምከ` / `በሰላም`
- [ ] results update per keystroke
- [ ] airplane mode: local-only search still filters correctly

## Backend follow-up (optional, app is now resilient either way)

1. Confirm the deployed `MezmurHymnService.php` matches this repo.
2. Confirm `mezmur_hymn_words` exists and is populated — if empty,
   `searchWordCandidates()` returns `[]` and the endpoint falls back to
   unfiltered behaviour.
