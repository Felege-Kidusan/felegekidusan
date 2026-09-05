# Mezmur web dashboard — deep audit (P42)

Audits the web department against every defect fixed in the mobile app
(P34-P41), plus a layout/UX pass driven by the dashboard screenshot.

---

## Part 1 — parity with the mobile fixes

| App fix | Web status | Action |
|---|---|---|
| **P37** homophone folding (ጸ/ፀ, ሀ/ሐ/ኀ, አ/ዐ) | ❌ **absent entirely** | fixed |
| **P39** substring matching (Amharic prefixes) | ❌ **retrieval prefix-only** | fixed |
| **P40** verify rows before rendering | ✅ server scores and drops `similarity <= 0` | none |
| **P41** read-only row mutation | ✅ N/A — PHP arrays are values, not handles | none |
| **P35** duplicate-title prevention | ✅ UNIQUE index + 1062 mapping | none |
| **P34** no autoplay | ✅ web autoplay follows an explicit click | none |

### Defect 1 — retrieval and scoring contradicted each other

`searchWordCandidates()` matched `word = ? OR word LIKE 'tok%'` —
**prefix only**. But `searchScore()` scores a substring hit **70**.

So the two halves of search disagreed: the scorer was willing to rank a
row that retrieval would never hand it. Proven against the real code:

```
stored በሰላም       query ሰላም   retrieved=NO   score=70  -> LOST
stored የሰላም       query ሰላም   retrieved=NO   score=70  -> LOST
stored hallelujah  query lelu   retrieved=NO   score=70  -> LOST
```

Amharic attaches grammatical prefixes (በ- ለ- የ- ከ-), so the root a user
types routinely sits mid-word. Same defect as app P39, and — as the
`hallelujah/lelu` case shows — **not Amharic-specific**.

**Fix:** `LIKE '%tok%'`, bounded by `WORD_CANDIDATE_CAP`. This cannot use
the B-tree index, which is a deliberate trade: the word table stores
*distinct words*, so it stays small, and a fast wrong answer is still
wrong.

### Defect 2 — no homophone folding at all

The web had none. `ጸሀይ` scored **0** against a stored `ፀሐይ`.

**Fix:** `foldAmharic()` / `searchKey()`, mirroring
`lib/services/amharic_text.dart`, applied to the tokenizer, the scorer,
both snippet builders and the JS highlighter.

> **Bug caught during verification.** My first fold map was
> non-convergent: `ሀ→ሃ` while `ሐ→ሀ`, so `ፀሐይ` and `ጸሀይ` produced
> *different* keys and still failed to match. Rewritten as
> consonant-family tables folded index-for-index across all seven vowel
> orders. Now `ፀሐይ` and `ጸሀይ` both yield `ጸሀይ`, scoring **100**.

Folding is **length-preserving** (one syllable maps to one), so offsets
found in the folded copy stay valid in the original — which is what lets
snippets and highlights slice the real spelling.

---

## Part 2 — UI / UX audit

### Layout 1 — content trapped behind the player dock

`.mz-player` is `position: fixed`, 82px tall. The clearance rule
(`padding-bottom: 100px`) left **18px** below an 82px dock, so the last
row of every list sat under the player — visible in the screenshot.

**Fix:** a single `--mz-dock-h` token now drives the dock height, the
content padding (`+2rem`) and the toast offset together, so they cannot
drift apart. Added `scroll-margin-bottom` so anchors and keyboard focus
never land under the dock either.

### Layout 2 — the player could never be dismissed

There was **no close button**, and `body.mz-playing` was added but
**never removed**. Once opened, the dock covered the bottom of every
page for the rest of the session, and the reserved padding persisted
even with nothing playing.

**Fix:** a close control (mirroring the app, where X stops audio and
hides the bar) plus `closeDock()` — stops playback, hides the dock,
releases the padding, and returns focus to the search box rather than
stranding it on a hidden button.

Escape now dismisses in layers: panel first, then dock. Deliberately
implemented by **extending the existing `onKey` handler** rather than
adding a second `keydown` listener, which would have fired twice and
closed both at once. The existing `inField()` guard already prevents
interrupting typing.

### Layout 3 — `position: sticky` was inert

`.school-topbar` had `position: sticky; top: 0`, but the element that
scrolls is `.school-content`; the topbar is its **sibling**. Its scroll
container never scrolls, so it never stuck — the declaration only cost a
stacking context. The flex parent already pins it.

**Fix:** declared `position: relative; flex: 0 0 auto` — honest about
what actually holds it in place.

### UX 4 — one character froze the results

```js
if (t.length === 1) return;   // abandons the reload entirely
```

Typing a single character — or deleting back to one — **abandoned the
reload**, leaving the previous results frozen with no indication they
were stale. The list said one thing, the search box another.

**Fix:** treat 1 character as "no filter yet" and show the unfiltered
list. The server still never receives a 1-char query (an unindexed
`'%x%'` scan across every hymn), but the UI stays self-consistent.

### UX 5 — highlighting missed folded and mid-word matches

`hi()` matched raw text, so a row returned for `ጸሀይ` but stored as `ፀሐይ`
rendered with **nothing marked** — the user sees a result with no
apparent reason for being there.

**Fix:** the JS now folds with the same table as PHP, matches on folded
text, and slices the **original** so the real spelling shows inside
`<mark>`. Verified identical to the PHP output, mid-word included
(`hal[lelu]jah`, `በ[ሰላም]`). XSS-checked: `<img src=x onerror=...>` is
escaped, since the highlighter builds HTML.

### A11y 6 — results changed silently

The table repainted with no announcement, so screen-reader users had no
way to know whether a query matched. Other sections of this page already
use `aria-live`; the hymn list did not.

**Fix:** an `aria-live="polite"` status region announcing "N hymns match
…" / "No hymns match …", wired to `aria-controls` / `aria-describedby`.
Announces only on change, so it does not repeat on every keystroke.
Added the missing `.sr-only` utility (clip-rect, not `display:none`,
which would remove it from the accessibility tree).

---

## Part 3 — required migration

**`sql/039_mezmur_word_index_fold.sql` must be run.**

Folding changes the *keys*. Rows already in `mezmur_hymn_words` were
written by the old unfolded tokenizer, so the query side would no longer
find them — **without this rebuild, folding makes search worse, not
better**, for every existing hymn.

`backfillHymnWords()` will not fix it: it only touches hymns with *no*
rows, and these have rows — just stale ones.

The migration clears the index; the app refills it. Search degrades
gracefully meanwhile: an empty table makes `searchWordCandidates()`
return `[]`, and `listHymns()` falls back to the LIKE path. Users always
see results.

Safe because the table is purely derived — every row is recomputable
from `title` and `lyrics`. No authored data can be lost.

---

## Verified

- PHP syntax: all changed files clean (also covered by the new CI gate)
- JS syntax: `node --check` on both files
- CSS: braces balanced in both stylesheets
- Folding convergence: `ፀሐይ` and `ጸሀይ` → identical key, score 100
- Length preservation: confirmed across Amharic and Latin input
- Retrieval/scoring now agree on all four probe cases, and a true
  non-match (`ማርያም` vs `ሰላም`) still correctly returns nothing
- JS↔PHP folding parity, and XSS neutralised in the highlighter

## Not verified — needs a browser

Rendering, focus order and the dock clearance are reasoned from the CSS,
not observed. Worth checking: play a hymn and scroll to the last row;
press Escape twice; search `ጸሀይ` and confirm a `ፀሐይ` hymn appears **with
the match highlighted**.
