# Mezmur search — deep audit and root cause (P40 / P41)

> **P41 supersedes the P40 conclusion below.** The device log revealed a
> crash that made search fail before any of the P40 analysis mattered.
> Read this section first; the P40 material remains valid as a
> secondary defect that was also fixed.
>
> ## P41 — the actual cause: read-only rows
>
> ```
> Unhandled Exception: Unsupported operation: read-only
>   QueryRow.[]= (sqflite_common/src/collection_utils.dart:152)
>   HymnStore.hymns (hymn_store.dart:148)
> ```
>
> sqflite returns `QueryRow`, a **read-only** map. `hymn_store` wrote
> match metadata directly onto rows returned by a query
> (`row['similarity'] = ...`), so the **first result of the first
> search threw**. `_reload` never reached `setState`, so the UI kept
> the previous list — identical rows for every query, no highlighting,
> apparently dead search.
>
> **The ranking engine was correct the whole time.** It never got to
> publish its output.
>
> ### The detail that proves it
>
> The query `አምገሊሊ` DID show a correct "No hymns match" empty state.
> No matches → the decorate loop never ran → nothing threw → the real
> (empty) result was published. **Only non-empty results crashed.**
> That asymmetry is the signature of this bug, and it rules out the
> P40 theory that the server was injecting unfiltered rows.
>
> ### Fix
>
> `HymnStore._decorate(row, hit)` returns a **writable copy**
> (`{...row, ...}`). All three decorate sites route through it:
> `hymns()`, `_verifyServerRow()`, and the server-merge snippet branch
> (which mutated `localRow` — the same hazard one level down).
>
> Audited the rest of `lib/` for the pattern: `hr_attendance`,
> `mezmur_attendance` and `roster.dart` all copy with `Map.from()`
> first. `hymn_store` was the only offender.
>
> ### Lesson
>
> Three rounds were spent theorising about matching semantics without a
> device log. **The stack trace named the file, line and operation in
> one line.** Ask for runtime logs before deep-diving static analysis.

---

## P40 (secondary defect, also fixed)

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
