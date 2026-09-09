# Hymn Art — per-hymn cover images (P66)

> "Every hymn has to get its own image/banner, like Spotify."
> Approved design: local-disk storage (D1), palette parity fix (D2),
> 160/320/640 renditions (D3).

Every hymn can carry its own cover image. The web console and the member
app render it everywhere a hymn appears — list rows, the view hero, the
parchment player backdrop, the mini bar, the lock screen — with a
deterministic name-hash gradient as the fallback so nothing looks broken
before art is uploaded.

---

## Where things live

| Layer | File | What it does |
|---|---|---|
| Migration | `sql/040_mezmur_hymn_art.sql` | guarded, idempotent `art_key / art_status / art_color / art_uploaded_by / art_updated_at` + `idx_mz40_art_status` |
| Reconciler | `admin/backend/services/MezmurSchemaReconciler.php` | same 5 columns in the COLUMNS contract (Sync DB Schema path) |
| Service | `admin/backend/services/MezmurArtService.php` | upload/remove pipeline, probe guard, payload builder |
| Web API | `admin/api_mezmur.php` | `art_upload` / `art_remove` (POST + CSRF, write bucket) |
| Mobile API | `api/v1/routes/mezmur.php` | `POST /mezmur/art`, `POST /mezmur/art-remove` (library-write roles, `mezmur_art_write` bucket) |
| Reads | `admin/backend/services/MezmurHymnService.php` | art fragment in list + get + delta; decorated at `applyMedia` |
| Web UI | `frontend/pages/mezmur_dept.php`, `frontend/js/mezmur.js`, `themes/components.css` | 44px list thumb (button), view-modal hero, upload via the hardened image dialog |
| Flutter | `lib/…` (local_db, hymn_store, api_service, mezmur_audio_player, download_manager, cover_palette, screens) | cache v25, offline pinning, list/detail/mini/player art |
| Tests | `tests/security/test_mezmur_art.py`, `Mobile/wbws_flutter_app/test/hymn_art_test.dart` | contract pins + hash parity |

## Storage model (decision D1 — LOCAL disk, not R2)

```
uploads/mezmur_art/{hymn_id}/{uuid32}_{160|320|640}.jpg
```

* `art_key` stores the relative path PREFIX; public URLs are rebuilt at
  read time and version-tagged `?v=UNIX_TIMESTAMP(art_updated_at)`.
  New artwork ⇒ new URL ⇒ browsers/CDN cache files forever with no
  invalidation machinery (the Spotify/Apple immutable-rendition model).
* `art_key` NEVER leaves the server — only built URLs do
  (`MezmurArtService::decorateRow` strips it).
* `.htaccess` in the art root denies execution and sets
  `Cache-Control: public, max-age=31536000` for images.
* Replacing art unlinks the three old renditions (best effort); removing
  art deletes them and clears the row.

## Upload pipeline (one chain, both doors)

`is_uploaded_file` → 4 MB cap → `finfo` magic bytes (JPEG/PNG/WebP) →
`getimagesize` bounds (16–4000 px) → full GD decode → **center-crop to a
square** (the 1:1 cover standard) → dominant-color extraction → re-encode
as three JPEG renditions (q85) under a fresh `random_bytes` name →
prepared statements → audit log → previous files unlinked.

The re-encode strips EXIF/GPS and any polyglot payload — the exact chain
the repo's own security audit certifies for category/singer images.

## Dominant color (Spotify's "color as emotional infrastructure")

`art_color` is extracted ONCE server-side (32×32 downsample, 4-bit
histogram, score = frequency × saturation with a luminance floor) and
stored as `#RRGGBB`. One truth for web + mobile — clients never
recompute and disagree.

## Sync + offline

* Art mutations bump `revision` + `updated_at`, so the mobile delta
  cursor converges cover changes with zero extra machinery.
* `cached_hymns` v25 adds `art_status / art_color / art_url /
  art_url_medium / art_url_small` (guarded ALTERs; probe-guarded server
  responses degrade to "no art" on a pre-040 database).
* Downloading a hymn also pins its 160px rendition as
  `mezmur_audio/mz_<id>_art.jpg` (best effort — never fails the audio
  download). List/mini-player/detail prefer the pinned file offline.

## Client payload contract

```json
{
  "art_status": "ready",
  "art_color": "#5A1212",
  "art_url": "/uploads/mezmur_art/7/ab12…_640.jpg?v=1757385600",
  "art_url_medium": "/uploads/mezmur_art/7/ab12…_320.jpg?v=1757385600",
  "art_url_small": "/uploads/mezmur_art/7/ab12…_160.jpg?v=1757385600"
}
```

Empty strings / `'none'` when there is no art. `art_key` is never
serialized. Clients render nothing unless `art_status === 'ready'`, so a
stale URL can never show.

## The D2 palette fix (why gradients changed on some phones)

The old Dart port hashed names with `& 0x7fffffff` per step; JS wraps to
a signed 32-bit int each step and takes `Math.abs` at the END. Names
whose hash overflowed int31 got a different gradient on the phone than
the web console. Both now use the identical algorithm, pinned by
`test/hymn_art_test.dart` (expected indexes computed from the real JS)
and cross-verified by `tests/security/test_mezmur_art.py`. **Effect:
some hymns/categories without admin-pinned colors change gradient on the
app after the update — that is the fix, not a regression.**

## Deployment

1. Deploy code (cron pull or git).
2. Run **`sql/040_mezmur_hymn_art.sql`** in phpMyAdmin — or press
   **Sync DB schema** in the Mezmur console (the reconciler carries the
   same columns). Both are idempotent.
3. Ensure `uploads/mezmur_art/` is creatable (the service creates it on
   first upload; the uploads tree must stay web-writable as it already
   is for taxonomy covers).
4. `GET admin/api_mezmur.php?action=ping` must report
   `code_version: phase7-art01` and no missing columns.
5. Mobile: release 1.1.17+20 (`flutter test` must pass — it includes
   `test/hymn_art_test.dart` and the version-sync check).

Rollback: the feature is fully additive. Reverting the code leaves the
five nullable columns and the orphaned art files harmless; the probe
guards make pre-040 code work on a post-040 database and vice versa.

## Verification checklist (all pinned by tests)

* OWASP chain complete; server-chosen paths; prepared statements only.
* `art_upload`/`art_remove` are POST + CSRF; mobile routes role-gated
  with their own rate bucket.
* Version `phase7-art01` + `MEZMUR_SCHEMA_MIN 40` on both surfaces.
* Ping probes the art columns (the P46 "silent drift" lesson).
* Art rides list + get + the delta cursor through ONE decoration point.
* Zero new failures vs the upstream test baseline; 34-test art contract
  suite green.
