# P38 — making the search index self-healing

P37 shipped Amharic-aware ranking and highlighting. This pass fixes the
structural weakness in how that index is *maintained*, so search stays
accurate as hymns change daily.

---

## The flaw in P37

P37 rebuilt the word index inside the **v22 schema migration**. That is
the classic one-time-migration trap: it repairs the index exactly once,
for exactly the users who cross that version boundary, and then rots.

Three concrete failure modes:

1. **The normaliser changes.** Add one homophone rule and every word
   already in the index was tokenised under the old rules, while queries
   are normalised under the new ones. They no longer meet. Search
   degrades **silently** — nothing errors, the user simply concludes the
   hymn is missing. This is the worst possible failure mode for search.
2. **A rebuild is interrupted.** App killed or crashed mid-rebuild leaves
   a half-populated index that looks complete forever.
3. **Rows arrive by a path that forgets to reindex.**

For this app (3) is not hypothetical: **lyrics blobs download lazily**
(15 per sync cycle), so a hymn's body becomes searchable long after its
row was cached — and hymns are edited daily.

---

## The industry pattern

Elasticsearch/Lucene **cannot change an analyzer in place**: existing
documents were tokenised by the old analyzer and reinterpreting them at
query time is impossible. Elastic's own guidance is that changing an
analyzer requires building the index again and swapping to it. Production
systems therefore **version the analyzer** and reindex whenever the
stored version differs from the code's.

Ported to a phone:

| Concern | Mechanism |
| --- | --- |
| Analyzer changed | `kAnalyzerVersion` stamped in the DB, compared **on every open** |
| Rebuild interrupted | `rebuild_in_progress` flag; stamp written only on completion |
| Daily edits | Dirty queue + incremental reindex |
| Deleted hymns | Dirty row with no `cached_hymns` row purges its index entries |
| Large catalogue | Bounded batches |

The critical design choice: **the check is tied to the analyzer version,
not the schema version.** Changing tokenisation now repairs itself with
no schema migration at all.

## What was built

### `lib/services/search_index_policy.dart` (pure, testable)

* `kAnalyzerVersion` — bump when tokenisation changes. History: `1`
  pre-P37 raw code points, `2` P37 homophone folding.
* `decide(state)` → `incremental` | `fullRebuild`. Deliberately
  conservative: anything ambiguous rebuilds. A needless rebuild costs
  battery once; a missed one breaks search until reinstall.
* `mayRebuildNow(...)` — never start a full pass while the user is
  actively searching.
* `batches(...)` — bounded work units.
* `needsReindex(...)` — compares **searchable fields only**, so a
  play-count or cover-image sync costs no index rewrite.

### Schema v23 — `local_db.dart`

* `hymn_search_meta(id=1, analyzer_version, rebuild_in_progress, updated_at)`
* `hymn_search_dirty(hymn_id PK, queued_at)`

The v23 migration **does not schedule a rebuild**. It only creates the
tables and leaves the stamp `NULL`, so the on-open check notices and
repairs — the same code path that will handle every future analyzer
change, rather than a special case that only runs once.

* `ensureSearchIndexFresh()` — the entry point. Rebuilds when the stamp
  is missing/different or a rebuild was interrupted; otherwise drains the
  dirty queue. The stamp is written **only after** a rebuild completes.
* `processDirtySearchRows()` — a row leaves the queue only after its
  words are written, so an interruption retries rather than skipping.
* `upsertHymns` and `updateHymnLyrics` now **mark rows dirty** instead of
  reindexing inline, and `upsertHymns` skips rows whose searchable text
  is unchanged.

### Wiring

* On opening the hymns screen (unawaited — never delays first paint).
* After **every** sync, in `pullChanges`'s `finally`.

### Two accuracy fixes in the UI

* **Stale-result guard replaced with a generation counter.** The old
  guard compared query *text*, which silently fails when the text returns
  to a previous value — type `sela`, backspace to `sel`, retype `sela`
  and two loads are in flight for the same string, so the slower one
  wins and shows stale results. A monotonic counter cannot collide.
* **Debounce 150 ms → 180 ms**, within the 150–250 ms consensus band for
  search-as-you-type.

---

## Verification

`dart analyze` clean. **170 tests passing** (150 previous + 20 new).

Simulated lifecycle against the real policy:

```
--- analyzer currently v2 ---
fresh install (never stamped)            -> fullRebuild
upgrade from P37 build (stamped v1)      -> fullRebuild
normal open, clean                       -> incremental
normal open, 40 hymns edited today       -> incremental
app killed mid-rebuild                   -> fullRebuild

--- FUTURE: add a homophone rule, ship analyzer v3 ---
open with v2 index, code now v3          -> fullRebuild
after that rebuild completes             -> incremental
```

The last two lines are the point: a future normaliser improvement repairs
itself on next open, with no migration and no user action.

### Maintenance rule

**If you change how text becomes tokens in `amharic_text.dart`, bump
`kAnalyzerVersion`.** That is the only manual step, and a test asserts
the constant's current value so an accidental change is caught.

### Device checks

1. Update from the P37 build — first open rebuilds once (stamp was v1),
   subsequent opens do not.
2. Edit a hymn's lyrics on the server, sync, and confirm the new words
   are searchable without reinstalling.
3. Type a query fast, backspace, retype the same string — results must
   match the final query (generation-counter fix).
4. Force-kill during first launch, reopen, and confirm search still works
   (the interrupted rebuild is retried).
