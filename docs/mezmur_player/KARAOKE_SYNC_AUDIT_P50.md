# Mezmur karaoke sync — deep audit (why the app never highlights while the web works)

**Focus:** timed (LRC) "karaoke" lyrics sync between the Mezmur web console and the
Flutter app. Scope per the report: fewer than ~50 hymns in the library, only ~2 with
audio; the **web player highlights, the app never does** (no emphasis, no animation,
no auto-scroll).

This is a diagnosis pass over the code **as it stands at `a5f4f7a` (P48/P49)**. It does
not assume the P48 fix worked — the symptom is reported as still present, so we walk
the whole path and mark every place the data can be lost, plus the one genuinely broken
contract, and then give a concrete build/fix plan.

---

## 1. The two "sync" things (so we never conflate them)

| System | What it moves | Status |
|---|---|---|
| **Data sync** | Hymn rows (title, lyrics blob, taxonomy, audio metadata) via a `(updated_at,id)` delta cursor | Working |
| **Karaoke sync** | `mezmur_hymns.lyrics_synced` (LRC text) | **broken on the app** |

Timed lyrics ride **the same delta cursor** as hymn rows (the "F5 convergence" change),
so they are not a separate protocol — but the app's **read** path is where they get lost.

---

## 2. The end-to-end path, both sides

### Web console (writes + always-fresh reads)
1. `frontend/js/mezmur.js` → `admin/api_mezmur.php?action=lyrics_synced_save` (`POST`).
2. `MezmurMediaService::saveSyncedLyrics()` (`admin/backend/services/MezmurMediaService.php:751`)
   → `canonicalizeLrc()` then
   `UPDATE mezmur_hymns SET lyrics_synced=?, …, updated_at=NOW(), revision=revision+1 WHERE id=?`.
   ✓ bumps **`updated_at` and `revision`**, so the delta cursor will see it.
3. The **web player** never trusts a cache: `frontend/js/mezmur_player.js:332`
   `state.lrc = parseLrc(h && h.lyrics_synced)` where `h` is fetched **fresh every time**
   from `action=get&id=…` (`mezmur_player.js:521`). → **The web always reads the freshest
   `lyrics_synced` straight from the DB.**

### Mobile app (cached-first reads)
4. `GET /mezmur/hymns/changes` → `MezmurHymnService::listChangedSince()` always selects
   `lyrics_synced, lyrics_synced_at` (F5), so the delta carries timed lyrics.
5. `HymnStore.pullChanges()` (`lib/services/hymn_store.dart:1154`) → `LocalDb.upsertHymns()`
   → `SyncedLyricsMerge.merge()` (only a **non-empty** value is authoritative).
6. The screen that must render karaoke is `MezmurLyricsScreen` (`lib/screens/mezmur/mezmur_lyrics_screen.dart`).
   It seeds from `track.lyricsSynced`, then `_load()` **reads the local cached row**, and only
   *if `synced.isEmpty && ConnectivityService().hasLink`* does it fetch `GET /mezmur/hymn?id=…`
   and upsert.

### The divergence that produces the symptom
**Web = always-fresh direct read. App = cache-first, and the cache is only refreshed by a
delta pull that depends on a sync cycle having already run.** If the timings were saved on the
web after the phone's last sync, the phone's `cached_hymns.lyrics_synced` is still empty and —
subject to the `hasLink` gate in step 6 — the app may never ask the server. That is exactly
"web works, phone doesn't," and it is why the previous fixes (which repaired the *delta*
delivery and the empty-string overwrite) did not necessarily fix a *stale cache on a device that
has not re-synced*.

---

## 3. Confirmed defects

### D1 — S1 (contract): a web-side "Clear timings" never reaches the app
- Web/REST "clear" → `RemoveSyncedLyrics()` writes **`lyrics_synced = NULL`** (`MezmurMediaService.php:812`).
- Delta therefore sends `lyrics_synced: null` for that hymn.
- `SyncedLyricsMerge.classify(null) → unknown` → `merge()` **returns the local value**
  (`lib/services/synced_lyrics_merge.dart:45-58`). So the app keeps the *old* timings forever.

The P48 rule ("only a non-empty value is authoritative") is correct for the *episode* it fixed
(an absent schema column must not read as "cleared"), but it makes **real clears** indistinguishable
from **schema-absent / no-info**, because the DB stores both as `NULL`. Result: a curator deletes
timings on the console and every phone still shows them. (The app can clear its own copy because its
`lyrics_synced` outbox op writes `''` → `removeSyncedLyrics()` on the *same* device; the cross-client
clear is the broken direction.)

**Fix direction:** give a deliberate clear a distinct, authoritative signal rather than `NULL`:
- Store clears as an explicit sentinel (e.g. keep the column but also bump a `lyrics_synced_at`
  *and* send a non-null marker), **or**
- Server-side, distinguish "schema absent" (→ omit the key / keep `NULL AS …` only when the
  *column* is missing) from "row has no timings" (→ send an explicit **empty string** that the
  app treats as authoritative *clear*, since the P48 ambiguity was *only* about a missing column,
  not about an empty LRC). The cleanest is: `syncedColExpr` emits `NULL AS lyrics_synced` only when the
  *column* is absent; when the column exists, **always** emit a real value, and have the clear path
  write `''` (not `NULL`). Then the merge rule "non-empty = set, `''` = clear, `NULL` = no info" is
  unambiguous. Requires updating both `saveSyncedLyrics`' clear caller and the mobile merge test.

### D2 — S1 (root cause of the reported symptom): the app is cache-first + `hasLink`-gated, the web is always-fresh
- `mezmur_lyrics_screen.dart:_load()` only fetches the hymn when `synced.isEmpty && hasLink`.
  With a stale cache (timings added after the last sync) it will not fetch on an already-populated
  (but empty) row, and if the OS radio momentarily reports `none` it will not fetch at all.
- `pullChanges()` runs only from auto-sync / pull-to-refresh, so an unsynced phone has no
  `lyrics_synced` in its cache.

**Fix direction:** make the lyrics screen authoritative like the web player — on open, when
online, **always** re-fetch `GET /mezmur/hymn?id=…` (it is one lightweight row; the delta already
ships `lyrics_synced`, so this is a cheap belt-and-braces), replace the cache, and only fall back
to the cache when the fetch fails. Import `ConnectivityService` only to *skip* when genuinely
offline, and add a short timeout so a slow link doesn't hang the screen. This single change makes
"web works ⇒ app works" structural instead of lucky.

### D3 — S2 (correctness at scale): delta pull applies one page only
- `pullChanges()` calls `getMezmurHymnsChanges(cursor)` **once**, upserts `items`, stores
  `next_cursor`; it never loops while `has_more` is true (`hymn_store.dart:1157-1168`).
- Fine at <50 hymns, but a backlog (first run of a larger library, or many `updated_at` bumps
  from category rename cascades) needs multiple *sync cycles* to drain, so a device can be left
  behind for a long time.

**Fix direction:** loop up to a bounded number of pages (e.g. 10) while `data['has_more']` is
true, advancing the cursor each time, so one `pullChanges()` converges.

### D4 — S3 (latent): the lazy-lyrics backfill throws away `lyrics_synced`
- `pullChanges()` → `getHymnsMissingLyrics()` → for each, `getMezmurHymn(id)` returns the full
  row **including `lyrics_synced`**, then calls `LocalDb.updateHymnLyrics(id, lyrics, revision)`
  which updates only `lyrics` + `revision` (`local_db.dart:2343`). The fetched LRC is discarded.
- Non-destructive today (the delta ships it separately), but any hymn whose only delivery path is
  the single-hymn fetch (e.g. added to the cache via the server-search result path, which omits
  `lyrics_synced`) would never gain its timings.

**Fix direction:** route the backfill through `upsertHymns([item])` (which applies the same
`SyncedLyricsMerge` rule) instead of `updateHymnLyrics`, so the fetched row is stored whole.

### D5 — S4: stale after same-device edit (minor)
`saveSyncedLyrics` writes locally then enqueues a push; fine on the editing device. But a hymn
edited on **another** device relies entirely on D2/D3 to converge.

---

## 4. What is already correct (no action, verified)
- Server save/remove both bump `updated_at` **and** `revision` (so the delta cursor and the
  optimistic-concurrency guard both see the change).
- `listChangedSince` always selects `lyrics_synced` + `lyrics_synced_at` (F5), and `decorateRow()
  preserves them.
- `SyncedLyricsMerge` correctly prevents an *absent-column* `''`/`NULL` from wiping local LRC
  (the P48 bug).
- The Dart `SyncedLyrics.tryParse` accepts the canonical `[mm:ss.mmm] text` dialect the server emits.
- `getHymn()` (REST `/mezmur/hymn` and web `get`) returns `lyrics_synced`.

---

## 5. How to confirm which hop is failing on *your* deployment
Run these in order; the first that surprises you is the broken hop.

**Server**
1. `?action=ping` (web) → confirm `missing_columns` is empty and, in particular, that
   `mezmur_hymns.lyrics_synced` is present (needs `sql/038_mezmur_audio_media.sql`).
2. In the DB, for the 2 audio hymns: `SELECT id, title, lyrics_synced, lyrics_synced_at, updated_at, revision FROM mezmur_hymns WHERE id IN (…);`
   — does `lyrics_synced` hold real `[mm:ss.mmm]` text? Do `updated_at`/`revision` reflect the
   last edit?
3. `GET /api/v1/mezmur/hymn?id=N` (as a curator) → in `data.item`, is `lyrics_synced` present and
   non-empty?

**App**
4. Open a **different** hymn (not the editor screen) and instrument the phone: after a pull-to-refresh,
   query the local DB: `SELECT id, lyrics_synced, updated_at FROM cached_hymns WHERE id=N;`
   — is `lyrics_synced` populated? If empty, the delta/hasLink path is the problem.
5. Open the hymn **online**. Does `MezmurLyricsScreen` show "No timed lyrics…" or the karaoke lines?
   If it still shows static, the `_load()` on-demand fetch is not running or failing (check
   `ApiService().getMezmurHymn` returns `success` and `data.item.lyrics_synced` non-empty).

**Version**
6. The app's cached row keeps being empty even though the server has LRC → the on-board app is
   **older than P48/P49** (the empty-string wipe). Rebuild from `a5f4f7a`.

---

## 6. Build / fix plan (prioritised)

| # | Defect | Change | Risk |
|---|---|---|---|
| 1 | **D2** | `MezmurLyricsScreen._load()`: when online, always fetch `getMezmurHymn(id)`, `upsertHymns([item])`, and repaint from the fresh row; fall back to cache only on failure. Add a timeout. Drop the `synced.isEmpty` precondition. | Low; most likely to resolve the reported symptom |
| 2 | **D1** | Server: clear writes an authoritative empty (`''`), not `NULL`, when the column **exists**; keep `NULL AS lyrics_synced` only for schema-absent. Update merge test + `classify` semantics (`''` → authoritative-clear). | Medium (touches a security-reviewed route); needs the cross-language contract test updated |
| 3 | **D3** | `pullChanges()` loops on `has_more` (bounded, e.g. ≤10 pages), advancing the cursor each pass. | Low |
| 4 | **D4** | `pullChanges()` backfill uses `upsertHymns([item])` instead of `updateHymnLyrics(...)`. | Low |
| 5 | **D5** | After a push of a `lyrics_synced` op, trigger `pullChanges(lyricsBatch: 0)` so other devices converge (already the pattern used for cover images). | Low |

Plus one **verification** addition that the repo explicitly flags as missing: P48/P49 verified the
*data* reaches the DB from several angles but did **not** verify that highlighting/animation render
against real audio on a device. The P49 notes list this as "NOT VERIFIED — needs a device." That
device check is what will confirm whether the symptom is fully gone after #1.

**Suggested order:** #1 first (smallest, most targeted), then #3/#4 (cheap robustness, same file),
then #2 (the contract fix, needs a careful migration of the clear semantics and its tests), then
#5.

---

## 7. One-line summary for the team
The karaoke data *is* correctly saved and shipped on the delta, but the **app reads a possibly-stale
local cache and only refreshes on demand when offline-gated**, while the **web always re-fetches the
fresh row**. Fix the app's lyrics screen to be authoritative-on-open (like the web), and fix the
"clear timings" contract so a deliberate clear is distinguishable from "unknown." Those two changes
close both the reported symptom and the reverse-direction staleness.
