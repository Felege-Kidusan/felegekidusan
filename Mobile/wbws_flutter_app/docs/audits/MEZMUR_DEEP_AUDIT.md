# Mezmur mobile section — deep audit

Function-by-function review of the Mezmur department app: 16 screens
(~7,700 lines) and 9 services (~3,200 lines), plus the PHP endpoints and
SQL they depend on.

This audit covers code **as it stands after P34/P35** (commit `0665c19`).
Findings are new unless marked otherwise. Nothing here is patched yet —
this is the diagnosis pass, deliberately separated so the fixes can be
reviewed against stated root causes rather than guessed at.

Severity: **S1** breaks a core promise · **S2** wrong behaviour users hit
· **S3** correctness/robustness risk · **S4** hygiene.

---

## Summary

| # | Severity | Area | Defect |
|---|---|---|---|
| 1 | **S1** | audio | `_audioFlags` misreads every non-downloaded hymn as lyrics-only |
| 2 | **S1** | audio | Queue neighbours are never signed → dropped from the engine queue |
| 3 | **S2** | audio | End of queue never pauses/rewinds; play button sticks on "pause" |
| 4 | **S2** | categories | Drill-down AppBar shows a stale name after rename |
| 5 | **S3** | categories/singers | `setState` after `await pickImage` with no `mounted` check |
| 6 | **S3** | categories | Hiding a main leaves its subs visible and selectable |
| 7 | **S3** | audio | `_audioFlags` doc claims a guarantee the code does not provide |
| 8 | **S4** | playback | `autoAdvanceRowAfter` is dead code, reachable only from tests |
| 9 | **S4** | attendance | `_submit()` has no in-flight guard (idempotent, so cosmetic) |

Verified-correct areas are listed at the end — a clean result is a
finding too, and worth recording so the next audit does not redo it.

---

## 1 · S1 — `_audioFlags` misreads playability for every streamed hymn

`lib/services/mezmur_audio_player.dart:234-239`

```dart
List<bool> get _audioFlags => List<bool>.generate(
      _catalog.length,
      (i) => _catalog[i].audioUrl.trim().isNotEmpty,   // ← wrong signal
      growable: false,
    );
```

**Why it is wrong.** `_catalog` is built from cached hymn rows via
`MezmurTrack.fromHymnRow`, which reads `row['audio_url']`. That column is
**never populated by the server** — I checked both the route file and
`MezmurHymnService`; neither ever emits `audio_url`. Audio URLs exist
only as short-lived signed links minted on demand by
`ApiService.getMezmurAudioUrl(hymnId)` (3600 s expiry).

So for any hymn that is not downloaded to disk, `audioUrl` is `''` and
its flag is `false` — the controller believes it has no audio at all.

**Consequences.** `_audioFlags` feeds `MezmurPlaybackPolicy` for
`canNext`, `canPrevious`, `nextRow`, `previousTarget` and
`autoAdvanceRowAfter`. All of them therefore reason over a catalog they
believe is almost entirely lyrics-only:

* next/previous affordances are enabled/greyed on false information;
* `_syncAudio` (L400-407) short-circuits on `audioUrl.isEmpty` and simply
  **pauses**, treating a perfectly playable streamed hymn as lyrics-only;
* the 18 passing `mezmur_playback_policy_test` cases are all correct —
  they test the policy against *hand-written* flags. The policy is fine.
  The flags fed to it in production are not. This is exactly why the
  test suite is green while the behaviour is wrong.

**Correct signal.** `audio_status == 'ready'` is the authoritative
playability field; it is cached, and the controller already trusts it at
L468 (`else if (selected.audioStatus == 'ready')`). `_audioFlags` should
be `status == 'ready' || audioUrl.isNotEmpty` (the latter covering
local-file tracks).

## 2 · S1 — queue neighbours are never signed, so they vanish

`lib/services/mezmur_audio_player.dart:465-506`

`openQueue` resolves a source for the **selected** track only:

```dart
final localPath = await _downloads.localPathFor(selected.hymnId);
if (localPath != null)      { /* file:// */ }
else if (selected.audioStatus == 'ready') { /* sign it */ }
```

Then it loops the whole catalog to build `sources`, but for `i !=
selectedIndex` it only checks for a **local** copy:

```dart
if (i != selectedIndex) {
  final lp = await _downloads.localPathFor(t.hymnId);
  if (lp != null) t = t.copyWith(audioUrl: Uri.file(lp).toString());
}
if (t.audioUrl.trim().isEmpty) continue;   // ← silently dropped
```

A neighbour that is `ready` but not downloaded still has `audioUrl == ''`
and is **dropped from the engine queue**.

**Consequences.**

* The just_audio queue usually contains exactly ONE item (the hymn you
  opened) plus any downloaded ones.
* Native gapless auto-advance has nothing to advance to.
* Lock-screen / headset / Bluetooth next-track does nothing, because
  those act on the engine queue, not on the app's catalog.
* In-app next/prev *appears* to work only because `moveTo` →
  `_syncAudio` re-signs and rebuilds — masking the defect during casual
  testing while the background/lock-screen experience is broken.

**Also:** `openQueue` never writes the signed URL back into `_catalog`,
so even the currently playing hymn keeps `audioUrl == ''` in the catalog
— which is what makes finding 1 permanent rather than transient.

**Fix direction.** Sign lazily but correctly: keep `_catalog` as the
display model, and treat `audio_status == 'ready'` as queue-eligible.
Because signed URLs expire in an hour, pre-signing an entire long queue
is wrong; the right shape is a `ProxyAudioSource`/resolver that mints a
URL when the engine actually reaches the item, or re-signing on
`currentIndexStream` transitions.

## 3 · S2 — end of queue leaves the player stuck

`lib/services/mezmur_audio_player.dart:136-148`

The `playerStateStream` listener records `_completed` but nothing ever
reacts to it. just_audio's documented contract is explicit:

> This method causes `playing` to become true, and it will remain true
> until `pause` or `stop` is called.

So at the end of the last track the player sits at
`playing == true, processingState == completed`, position pinned at the
end. The transport button therefore keeps showing **pause**, and tapping
it calls `pause()` on an already-finished stream — nothing audible
happens, and the user must scrub or reopen to recover.

The remedy is the documented one (also SO 71621109): on
`ProcessingState.completed`, `pause()` + `seek(Duration.zero)`. Note the
same source warns these calls must be deferred out of the state callback
(`Future.delayed(Duration.zero, …)`) because just_audio's event subject
is `sync: true` — calling back into the player from inside the listener
risks *"Cannot fire new event. Controller is already firing an event"*.

Interaction with finding 2: once the queue really contains every
playable hymn, this also becomes the hook that must respect loop mode.

## 4 · S2 — stale category name in the drill-down title

`lib/screens/mezmur/mezmur_categories.dart:586-590`

```dart
final parent = widget.parent;                    // immutable snapshot
title: Text(_isDetail ? '${parent!['name']}' : 'Hymn Categories'),
```

`parent` is the `Map` captured when the row was tapped. Renaming that
main from within its own sub-screen updates the store and the list, but
the AppBar keeps rendering the **old** name until the user pops and
re-enters. The same snapshot is used for the empty-state text
(`'Add the first sub-category under "…"'`).

Related, lower severity: if the parent is deleted server-side (taxonomy
reconcile can now genuinely delete rows) while the user is inside it,
the screen stays open on an orphan with no explanation. It should detect
that `_parentId` is gone after `_reload()` and pop.

**Fix direction.** Resolve the parent by id out of `_categories` on every
build, falling back to the snapshot only until the first load completes.

## 5 · S3 — `setState` across the image-picker gap

`mezmur_categories.dart:246` and `mezmur_zemarians.dart:198`

```dart
final picked = await _picker.pickImage(source: ImageSource.gallery, …);
if (picked == null) return;
setState(() => _uploadingId = _asInt(category['id']));   // ← no mounted check
```

`pickImage` backgrounds the whole app; on Android the activity can be
recreated, and the user may pop the screen (or the OS may reclaim it)
before it returns. Calling `setState` on a disposed `State` throws.

Every *other* async boundary in these two files is correctly guarded
with `if (!mounted) return;` — including the line immediately after this
one — so this is an oversight, not a convention. Two call sites, one-line
fix each.

## 6 · S3 — hiding a main category does not cascade to its subs

`mezmur_categories.dart:219-223` → `HymnStore.setCategoryStatus`

Setting a main inactive flips only that row. Its sub-categories keep
`is_active = 1`, so they continue to appear in hymn-editor pickers and
filters even though their parent is hidden. The user's mental model
("hiding a category hides its contents") is violated, and there is no
warning that N subs remain live.

The server's reparenting guard shows the two-level invariant is taken
seriously elsewhere; the status change should either cascade or state
plainly that it will not.

## 7 · S3 — a doc comment that promises what the code cannot deliver

`mezmur_audio_player.dart:231-234`

> This mirrors the audio-queue membership and is the single source of
> truth the pure `MezmurPlaybackPolicy` works on, so buttons/swipe and
> the engine can never disagree about what a navigation means.

Given findings 1 and 2, `_audioFlags` (derived from `_catalog`, unsigned)
and the engine queue (derived from signed/local URLs) **systematically
disagree** — the flags say "lyrics-only", the queue says "absent", and
neither matches `audio_status`. A comment asserting an invariant that
does not hold is worse than no comment: it discourages exactly the check
that would find the bug. It must be corrected alongside finding 1.

## 8 · S4 — `autoAdvanceRowAfter` is dead code

`mezmur_playback_policy.dart:202-223` is fully implemented, carefully
documented, and covered by 5 tests — but `grep` across `lib/` finds
**zero** production call sites. Auto-advance currently works only
because just_audio advances its own queue natively and
`currentIndexStream` mirrors the cursor back (L116-128).

That is not a bug today, but it is a trap: 5 green tests imply the
project's auto-advance policy is exercised, when in reality the
behaviour comes from the plugin and the policy is unreachable. Either
wire it into the completion handler (finding 3) or delete it. Do not
leave tested-but-unused policy code implying coverage it does not have.

## 9 · S4 — attendance submit lacks an in-flight guard

`mezmur_attendance.dart:460` — `_submit()` has no `_submitting` flag,
unlike `mezmur_hymn_editor.dart` which guards properly (`if (_saving)
return;` plus a disabled button). In practice the write is keyed by
(date, section) and is idempotent, so a double tap does not corrupt data
— but it can fire two `syncAll(force: true)` calls and two toasts. Worth
aligning with the editor's pattern for consistency.

---

## Verified correct (no action)

Recording these so the next pass does not re-investigate them.

* **Transport state machine** (post-P34) — no stale `_controlInFlight`
  latch; `play()` is never awaited; generation counter correctly
  supersedes in-flight commands. `stopAndClear()` bumps the counter, so a
  slow load cannot resurrect audio after close.
* **Outbox id handling** (post-P35) — all four save/status ops now
  enqueue consistent ids; `_status` ops resolve placeholders by name at
  push time; the wire payload is normalised to `0` for creates.
* **Server-side taxonomy** — `uq_mezmur_categories_name`,
  `uq_mc_parent_name (parent_id, name)` and `uq_mezmur_zemarians_name`
  back the client checks, and both `saveCategory`/`saveZemarian` return
  an idempotent *"already exists — linked"* for duplicate creates. The
  server was never the source of the duplication; it correctly returned
  the existing row, and the client orphaned its placeholder beside it.
* **Download manager** — `_pump()` re-entrancy guard, concurrency cap,
  `whenComplete` slot release and microtask re-entry are all sound;
  resume via `Range`, sha256 verification and `.part` rename are correct.
* **Memory/lifecycle** — every `addListener` in the Mezmur screens has a
  matching `removeListener`; all `TextEditingController`s and
  `TabController`s are disposed; `Timer`s (`_searchDebounce`,
  `_resumeHold`) are cancelled and re-check `mounted` in their callbacks.
* **Attendance async safety** — `_loadSections`/`_loadSheet` guard with
  `if (!mounted) return;` after every network await.
* **No N+1 in list rendering** — no `await`/`FutureBuilder` inside any
  `itemBuilder`; counts are pre-aggregated via `categoryHymnCounts()`.
* **Arithmetic safety** — `_totalMs`/`_clampedPositionMs` guard against
  zero duration; `openSession` early-returns on an empty catalog before
  `clamp(0, length - 1)` could throw.

---

## Recommended order of work

1. **Findings 1 + 2 together** — they are one defect in two places
   (playability signal, and queue construction). Fixing 1 alone would
   light up next/prev affordances that the engine queue still cannot
   honour. Requires a real device to validate lock-screen behaviour.
2. **Finding 3** — small, self-contained, and immediately visible.
   Defer the calls out of the state callback.
3. **Findings 4, 5, 6** — screen-level, independent, low risk.
4. **Findings 7, 8, 9** — hygiene; fold into whichever change touches
   the file.

Findings 1-3 need device verification: the widget and audio layers
cannot be type-checked in this environment (no Flutter SDK), and
lock-screen/headset transport cannot be exercised at all from here.


---

# Remediation status

All 9 findings were fixed in P36. See
`docs/mezmur_player/QUEUE_WINDOW_P36.md` for the analysis and patches.

| # | Sev | Finding | Status |
| --- | --- | --- | --- |
| 1 | S1 | Playability judged by `audioUrl`, which the server never sends | Fixed — `MezmurTrack.hasAudio` |
| 2 | S1 | Engine queue held one item; native next-track dead | Fixed — sliding window |
| 3 | S2 | End of queue left the transport stuck on "pause" | Fixed — `_handleCompletion` |
| 4 | S2 | Stale parent name in the drill-down AppBar | Fixed — `_liveParent` |
| 5 | S3 | `setState` after the image picker without `mounted` | Fixed |
| 6 | S3 | Hiding a main did not hide its sub-categories | Fixed — cascade + message |
| 7 | S3 | Doc comment asserted a false invariant | Fixed |
| 8 | S4 | Dead `autoAdvanceRowAfter` implied coverage | Documented |
| 9 | S4 | Attendance submit had no in-flight guard | Fixed |

A fifth instance of finding 1 was discovered during verification:
`MezmurPlayerScreen._hasAudio` gates the transport controls, so the play
button was hidden for every hymn that was not already downloaded.
