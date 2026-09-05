# P36 — audit remediation: playability signal, queue window, completion

Fixes findings 1-9 of `docs/audits/MEZMUR_DEEP_AUDIT.md`.

---

## Findings 1 + 2 (S1) — the playability signal and the one-item queue

These were one defect with two faces, so they were fixed together.

### The wrong signal

```dart
(i) => _catalog[i].audioUrl.trim().isNotEmpty,   // was
(i) => _catalog[i].hasAudio,                     // now
```

The server **never returns an `audio_url` column** — verified in both
`api/v1/routes/mezmur.php` and `MezmurHymnService.php`. Playback URLs
exist only as short-lived links minted per hymn by
`GET /mezmur/audio/{id}` (3600 s expiry, endpoint rate-limited to 240).

So a streamed hymn carries an **empty** `audioUrl` until the moment it is
resolved, and every URL-based test classified it as lyrics-only.

New `MezmurTrack.hasAudio` is the honest signal:

```dart
bool get hasAudio =>
    audioStatus.trim().toLowerCase() == 'ready' ||
    audioUrl.trim().isNotEmpty;
```

`audio_status` is cached and authoritative; the URL clause additionally
covers already-resolved and local `file://` tracks. Four call sites were
corrected: `_audioFlags`, `viewHasAudio`, `_syncAudio`'s lyrics-only
short-circuit, and `_play()`. A fifth was found during verification —
`MezmurPlayerScreen._hasAudio`, which gates the transport controls, so
**the play button was hidden entirely for any hymn not yet downloaded**.

### The one-item queue

`openQueue` signed only the selected hymn, then dropped every neighbour
whose URL was empty — i.e. every streamed one. The engine queue held a
single item, so native gapless advance had nowhere to go and the
lock-screen / headset / Bluetooth next-track button did nothing. In-app
skip appeared to work only because `moveTo` → `_syncAudio` rebuilt from
scratch each time, masking the defect.

Signing the whole catalog is not the fix: links expire in an hour and the
endpoint is rate limited, so a 200-hymn list would burn the budget on
URLs nobody reaches. `useLazyPreparation` does not help either — it lazily
*prepares* sources but never re-resolves their URLs. `StreamAudioSource`
was rejected for the same reason `LockCachingAudioSource` was: it needs a
local HTTP proxy and cleartext traffic.

**Solution: a sliding window** (`lib/services/mezmur_queue_window.dart`).
Resolve the current hymn plus a bounded look-ahead (3) and look-behind
(1), and slide it forward as playback advances:

* `plan()` — which catalog rows should hold a source. Walks outward over
  *playable* rows only, so lyrics-only hymns between songs do not consume
  window slots. Wraps when loop-all is on.
* `needsRefresh()` — whether to top up, triggered once ≤1 resolved row
  remains ahead. Deliberately returns false at the true end of the
  catalog so it cannot spin.
* `_ensureWindow()` — resolves the missing rows and splices them into the
  live engine queue with `insertAudioSource`, keeping engine order
  aligned with catalog order and adjusting `_index` when inserting ahead
  of the current item. Guarded by `_windowRefreshing`.

Because the engine queue is now a *window*, engine index ≠ catalog row.
A new `_queueRows` maps between them, and `currentIndexStream` uses it
(falling back to a hymn-id lookup) so the parchment page, the mini bar
and the audio all stay on the same hymn.

Resolved URLs are written back onto `_catalog` via `_rememberResolved`,
so revisiting a hymn in the same session does not mint a second link.

## Finding 3 (S2) — end of queue left the player stuck

just_audio's contract: `playing` stays true until `pause` or `stop` is
called. At the end of the last track the player therefore sat at
`playing == true, processingState == completed`, position pinned at the
end — the button showed **pause** forever and tapping it did nothing
audible.

`_handleCompletion()` now applies the documented remedy (pause +
`seek(zero)`), and is **deferred with a zero-duration timer**: just_audio's
event subject is `sync: true`, so calling back into the player from
inside its own state listener risks *"Cannot fire new event. Controller
is already firing an event"*.

It also handles the case the window creates: if a playable hymn remains
beyond the resolved window, it advances via `nextRow` rather than
stopping at the window edge. Loop-all/loop-one are left to just_audio,
which wraps natively.

## Finding 4 (S2) — stale name in the drill-down title

`widget.parent` is the snapshot captured when the row was tapped, so
renaming a main from inside its own sub-screen left the old name in the
AppBar. New `_liveParent` re-reads the row from `_categories` on every
build, falling back to the snapshot only until the first load completes.

`_reload()` additionally pops the screen if the parent has disappeared —
taxonomy sync can genuinely delete rows now, and sitting on an orphan
screen with no explanation is worse than leaving.

## Finding 5 (S3) — `setState` across the image-picker gap

`pickImage` backgrounds the app and Android may recreate the activity, so
the screen can be gone when it returns. Added `if (!mounted) return;` in
`mezmur_categories.dart` and `mezmur_zemarians.dart` — every other await
in both files was already guarded, so this was an oversight.

## Finding 6 (S3) — hiding a main did not hide its subs

Flipping a main's status left its sub-categories active, so they kept
appearing in hymn-editor pickers under a category the user believed was
hidden. `_toggleActive` now cascades to the subs whose state actually
matches the parent's old state (so a deliberately-hidden sub is not
silently revealed when the parent is shown again), and reports what it
did: *"Hidden, along with 3 sub-categories."*

## Finding 7 (S3) — a doc comment asserting a false invariant

The old comment claimed `_audioFlags` "mirrors the audio-queue
membership" so the two "can never disagree". With a sliding window they
*intentionally* differ. Rewritten to state the real contract: the flags
describe the whole catalog and are a deliberate **superset** of what the
engine holds; navigation targets come from the flags and the window
follows.

## Finding 8 (S4) — dead policy code implying coverage

`autoAdvanceRowAfter` still has no production call site. Completion
advances via `nextRow`, which works in catalog-row space;
`autoAdvanceRowAfter` works in audio-ordinal space, which stopped being
well defined once the queue became a window. Rather than delete a
well-tested statement of the loop semantics, it now carries an explicit
note that its passing tests are **not** evidence that production
auto-advance is covered.

## Finding 9 (S4) — attendance submit had no in-flight guard

Added `_submitting`, cleared on both the success and failure paths so
resubmission is never permanently blocked.

---

## Verification

`dart analyze` clean. **108 tests passing** (87 previous + 21 new).

| Suite | Tests |
| --- | --- |
| `taxonomy_names_test.dart` | 24 |
| `mezmur_queue_window_test.dart` | **21 (new)** |
| `mezmur_download_policy_test.dart` | 20 |
| `mezmur_playback_policy_test.dart` | 18 |
| `mezmur_transport_gate_test.dart` | 15 |
| `taxonomy_reconcile_test.dart` | 10 |

### Device checks still required

The audio and widget layers cannot be type-checked here (no Flutter SDK)
and lock-screen transport cannot be exercised at all, so these need a
real device:

1. Open a **not-downloaded** hymn — the play button must be visible and
   must start audio (this was the hidden-controls regression).
2. Let a hymn play to its end — playback must continue to the next hymn
   without touching the phone.
3. With the screen locked, press next/previous on the lock screen or a
   headset — it must move between hymns.
4. Let the **last** hymn finish with loop off — the button must return to
   "play" and the position reset to zero.
5. Play a long list past 4-5 hymns — advance must not stall at the window
   edge.
6. Rename a main from inside its sub-screen — the AppBar must update.
7. Hide a main with subs — a message must confirm the subs went too.
