# Mezmur offline downloads (P33)

Spotify-style "keep this hymn on my phone" for the Mezmur department app.
A downloaded hymn plays **with the radio off**: no signed-URL round trip,
no R2 fetch, no dependency on Ethio Telecom at 8 a.m. on a Sunday.

---

## 1. Why not just cache while streaming?

The obvious move is just_audio's `LockCachingAudioSource`, which writes
bytes to disk as they stream. It was evaluated and rejected:

| Requirement | `LockCachingAudioSource` | This implementation |
|---|---|---|
| "Downloaded" is a **promise** | ✗ only caches what you listened to | ✓ whole object fetched up front |
| Works with **presigned URLs** | ✗ cache key is the URL; our R2 links carry `X-Amz-Expires`, so every play mints a new key and never hits | ✓ URL used once, at download time, never stored |
| Queue / progress / retry | ✗ none | ✓ persistent queue, byte progress, backoff |
| Wi‑Fi-only, storage cap | ✗ none | ✓ both, with LRU eviction |
| Survives app kill | ✗ partial | ✓ resumes via `Range` requests |

The URL point is decisive on its own: with short-lived presigned links a
URL-keyed cache can never produce a hit.

## 2. Moving parts

| File | Role |
|---|---|
| `lib/services/mezmur_download_policy.dart` | **Pure** rules: metered-data guard, staleness, retry/backoff, LRU eviction plan. No I/O — unit-tested. |
| `lib/services/mezmur_download_manager.dart` | The engine: queue, HTTP with resume, integrity, storage accounting. A `ChangeNotifier`, so widgets just `addListener`. |
| `lib/services/local_db.dart` | Schema **v21**: `hymn_downloads` + `hymn_download_pins`, and their DAO. |
| `lib/widgets/download_button.dart` | `HymnDownloadButton` (5 visual states) + `OfflineBadge`. |
| `lib/screens/mezmur/mezmur_downloads.dart` | The Downloads/storage screen. |

Audio **bytes** live on the filesystem, never in SQLite:
`<app-support>/mezmur_audio/mz_<hymnId>.<ext>`, with a `.nomedia` marker
so the gallery/media scanner ignores them. App-support (not cache, not
temp) is deliberate: the OS will not reclaim it under storage pressure,
which is exactly the guarantee "downloaded" has to make.

## 3. The download path

```
tap ⭣  → hymn_downloads row (state=queued)      ← survives app kill
_pump() → policy.canTransfer(link, unmetered, wifiOnly)?
        → GET /mezmur/audio/{id}   (fresh presigned URL, used once)
        → HTTP GET with `Range: bytes=<already-have>-`  → mz_<id>.ext.part
        → size + sha256 check, then atomic rename → mz_<id>.ext
        → state=done, storage totals refreshed, cap enforced
```

* **Concurrency** capped at 2 — the phone stays responsive and the
  shared host is not hammered.
* **Resume**, not restart: a dropped 2G connection continues the `.part`
  file from where it stopped. If the server ignores `Range` (returns 200
  instead of 206) the partial file is discarded and it starts clean.
* **Integrity**: a body shorter than `Content-Length`, or under 1 KB, is
  never promoted to a playable file — it is retried instead.
* **Retry**: exponential backoff (2 s → 32 s, capped) for up to 4
  attempts, then `failed` with a tap-to-retry affordance.

## 4. Playback integration

`MezmurAudioPlayerController.openQueue()` resolves each track's source
**offline-first**:

```dart
final localPath = await _downloads.localPathFor(hymnId);
if (localPath != null) {
  // file:// — zero network
} else if (audioStatus == 'ready') {
  // mint a short-lived signed URL and stream
}
```

Queue neighbours resolve the same way, so skipping between downloaded
hymns stays instant and offline. If the file vanished (user cleared
storage), `localPathFor` self-heals: the row is dropped and the UI falls
back to streaming.

## 5. Freshness

Each stored file records the server's `audio_updated_at`. After every
hymn delta pull, `SyncService` calls `MezmurDownloadManager.syncPins()`,
which:

1. queues hymns newly added to a **pinned collection** (category/singer),
   so a pinned category keeps itself current the way a Spotify playlist
   does; and
2. re-downloads rows whose server stamp no longer matches the stored one.

`isStale()` deliberately treats "no stamp on either side" as *fresh*, so
rows predating the `038_mezmur_audio_media.sql` columns do not
re-download forever.

## 6. Data & storage policy

* **Wi‑Fi-only by default.** Mirrors Spotify's "Download over cellular"
  switch, and matters more here: mobile bundles in Ethiopia are metered
  and a bulk category download is tens of megabytes. The queue simply
  parks and the UI says *"Waiting for Wi‑Fi"* rather than doing nothing
  mysteriously.
* **Storage cap** (default 2 GB, configurable, `0` = unlimited). When
  exceeded, only `source='auto'` rows are evicted, least-recently-played
  first. **Rows the user downloaded on purpose are never deleted** — if
  pins alone exceed the cap the app stays over it and reports that,
  because silently deleting what someone explicitly asked to keep is the
  worst possible failure mode for this feature.

## 7. Where the user finds it

| Surface | Control |
|---|---|
| Hymn list row | download arrow → ring → green check; `OFFLINE` badge |
| Hymn library app bar | ⤓ sheet: "Download these N hymns" (respects the current search/filters) + link to storage |
| Now-playing header | parchment download chip for the current hymn |
| Mezmur home | **Downloads** tile |
| Downloads screen | usage bar, mobile-data switch, storage limit, pause/resume, per-item remove, remove-all |

## 8. Server side

**No server changes were required.** The feature reuses the existing
two-phase media contract:

* `GET /mezmur/audio/{hymnId}` → short-lived presigned R2 GET URL
  (`MezmurMediaService::playUrl`), rate-limited at 240/window.
* `audio_status`/`audio_updated_at`/`audio_format` from
  `sql/038_mezmur_audio_media.sql`, already delivered by the delta sync.

Object keys and R2 credentials still never leave the server, and the
presigned URL is used exactly once and never persisted to disk.

## 9. Tests

`test/mezmur_download_policy_test.dart` pins the rules that cost real
money or real files when wrong: the metered-data guard, queue-status
messaging, staleness (including the no-stamp loop), retry budget and
bounded backoff, and the eviction plan — in particular that a
user-pinned hymn is never evicted even when the library is over cap.

```bash
flutter test test/mezmur_download_policy_test.dart
```

## 10. Migration notes

* Local DB **v20 → v21** adds the two tables via a guarded `onUpgrade`;
  existing offline data is untouched and the upgrade is idempotent.
* New dependency: `path_provider: ^2.1.4` (run `flutter pub get`).
* `ConnectivityService` gained `isUnmetered` (Wi‑Fi/ethernet detection).
