# Mezmur Department (መዝሙር ክፍል) — Deep Analysis & Audit

**Repo:** `SSMS` @ `main` / `3bf4a9c` — *"P0 Mezmur audio: R2 direct-upload plane + web console UI + Flutter parchment player with synced lyrics"*
**Audit date:** 2026-09-04
**Method:** full source read of every Mezmur file + live execution (PHP 8.4.23, moto S3 over HTTPS, project test suite, direct endpoint invocation). Every claim below is marked **VERIFIED** (executed/observed) or **CODE READ** (read but not executed).

---

## 1. What the module is

Mezmur is two systems sharing one name:

| | A. Attendance domain | B. Hymn library + media |
|---|---|---|
| Purpose | Date/section attendance for the department, submission packets, review inbox, analytics | Lyrics catalogue, categories/singers taxonomy, search, offline sync, **audio streaming (new)** |
| Migrations | 022, 023, 024, 026/027 (rollup) | 021, 025, 030–038 |
| Owner | `MezmurAttendanceService`, `MezmurSubmissionService` | `MezmurHymnService`, `MezmurMediaService` |

Both are fronted by the same two controllers (`admin/api_mezmur.php` for the session/CSRF web console, `api/v1/routes/mezmur.php` for the bearer-token mobile app), and both delegate to the same services — a genuine single-writer design, not two parallel implementations.

### Inventory (line counts, VERIFIED via `wc -l`)

| Layer | Files | Lines |
|---|---|---|
| Web console | `admin/api_mezmur.php`, `frontend/pages/mezmur_dept.php`, `frontend/js/mezmur.js` | 1,041 + 819 + 2,423 = **4,283** |
| Mobile REST | `api/v1/routes/mezmur.php`, `backend/api/mezmur.php` | 625 + 200 = **825** |
| Services | `MezmurHymnService` 1,984 · `MezmurAttendanceService` 788 · `MezmurSubmissionService` 765 · `MezmurMediaService` 576 · `MezmurSchemaReconciler` 377 | **4,490** |
| SQL | 14 migration files (021–038) | **761** |
| Flutter | 12 screens + `mezmur_audio_player` + `mezmur_synced_lyrics` + `hymn_store` | **7,948** |
| Tests | 5 python files + 1 smoke | **3,472** |
| **Total** | **~44 Mezmur-specific files, ~120 files reference the module** | **~21,800** |

### Migration chronology (from `git log`)

```
d16da8d  Feature 1  — taxonomy: multi-category + singers + length/language flags
9283d3a  F2/F3      — hymn filters + singer/category browse tabs
edc5758…f976d06     — MZ-1..MZ-7 audit fixes (single-writer status, rate limits, renames, unique titles)
64bc33c  F4         — Telegram-style similarity search
17e6a57  Patch 22   — two-stage typo-tolerant retrieval
4767e82…64e9348     — Patch 23–24: natural-key taxonomy sync, Genius-style lyrics markup
b7fb808  Patch 25   — FULLTEXT → inverted word index (InnoDB FT is blind to Ge'ez)
28c44f3  Patch 27   — unified local+server search chain
a430f64  Patch 26   — library shell rebuild
f6af951…fdeec66     — P28–P34: single Amharic title, two-level taxonomy, cover images
772bbe9…c816ee2     — P35–P36b: singer filters, self-healing selects
5492ec4  P37        — filter toolbar rebuilt as a one-way state machine
23887fd             — inline styles → CSS, contract tests reconciled   ← suite GREEN
2ebe154             — harden singer associations + schema compat       ← suite GREEN
3bf4a9c  P0         — R2 audio plane + parchment player + synced lyrics ← suite RED (13 tests)
```

This is a module that has been audited repeatedly and honestly — the commit messages name their own defects. The P0 audio commit is the first one that shipped without that discipline (see F11).

---

## 2. Architecture of the new audio plane

```
                 ┌─────────────── admin/api_mezmur.php (session + CSRF)
 client ─────────┤                     action=audio_presign / audio_confirm /
                 └─────────────── api/v1/routes/mezmur.php (bearer JWT)
                                        │
                              MezmurMediaService
                    ┌───────────────────┼────────────────────┐
              beginUpload()        confirmUpload()      removeAudio()
                    │                   │                   │
     presign('PUT', key, 900s)   presign('HEAD', key, 120s)  presign('DELETE',…)
                    │                   │
                    ▼                   ▼
        mezmur_hymns.audio_*      signed HEAD → 200/206?
        status: none→pending          pending → ready
                    │
        browser/app PUTs bytes ──────────────► Cloudflare R2  (never through PHP)
                    │
        public read URL = MEZMUR_MEDIA_PUBLIC_BASE + '/' + audio_key
        (audio_key itself is never returned to any client)
```

Design intent is sound and well argued in the file headers: bytes bypass PHP entirely so shared-hosting `upload_max_filesize` / `post_max_size` / `max_execution_time` are irrelevant; the public hostname is one config value so a domain move touches no rows; `audio_key` stays server-side and only a derived `audio_url` is exposed, and only when `audio_status === 'ready'`.

**The SigV4 implementation is correct.** VERIFIED: I copied `MezmurMediaService.php` byte-for-byte (diff shows exactly one changed line — the endpoint host), pointed it at a real S3 implementation (moto over HTTPS), and called the project's own private `presign()` via Reflection:

```
presign(PUT) = https://…/fkss-media/mz/audio/42/….mp3?X-Amz-Algorithm=AWS4-HMAC-SHA256
               &X-Amz-Credential=…&X-Amz-Date=…&X-Amz-Expires=900
               &X-Amz-SignedHeaders=host&X-Amz-Signature=3d7c8d26…
LIVE PUT with the project's presign           → HTTP 200
LIVE signed HEAD (confirmUpload's check)      → HTTP 200
```

Signing key derivation (`AWS4` + secret → date → `auto` → `s3` → `aws4_request`), canonical request shape (empty-payload double newline, `UNSIGNED-PAYLOAD`), RFC-3986 path-segment encoding and query sorting are all right. This is a genuinely competent hand-rolled presigner.

---

## 3. Findings

Severity: **CRITICAL / HIGH / MEDIUM / LOW**. "VERIFIED" = I executed it.

---

### F1 · HIGH — `sql/038` is a *draft*, is not applied by anything, and the "Sync DB schema" button cannot fix it

**Evidence (VERIFIED):**
- `sql/038_mezmur_audio_media.sql` line 2 reads: `-- 038 — Mezmur audio media + synced lyrics (DRAFT — for review)`.
- `grep -n "038\|audio_key\|audio_status" admin/backend/services/MezmurSchemaReconciler.php` → **no matches**. The reconciler's `COLUMNS` contract stops at the pre-038 columns; it has never heard of `audio_key`, `audio_status`, `lyrics_synced`, `mezmur_play_stats` or `mezmur_user_favorites`.
- `admin/migrations/` and `backend/migrations/` top out at `006_*`; there is no runner that applies `sql/*.sql`.

**Why it matters.** `MezmurMediaService::beginUpload()` fails closed with *"Audio columns are missing. Run sql/038… (or press **Sync DB schema**) first."* That message is wrong: the Sync DB schema button (`action=migrate` → `MezmurSchemaReconciler::apply()`) cannot add these columns. Every deployment therefore ships an audio feature that is dead until a human runs the SQL by hand — and the error message actively sends them to the one control that won't help. This is the exact class of schema-drift incident the reconciler was written to kill.

**Fix.** Add the seven `audio_*` columns, the three `lyrics_synced*` columns and `idx_mz38_audio_status` to `MezmurSchemaReconciler::COLUMNS` / `apply()`. Drop the `DRAFT` marker once it is the applied path.

---

### F2 · HIGH — Unauthenticated diagnostic endpoints leak server internals (`backend/api/mezmur.php?diag=1|2`)

**Evidence (VERIFIED — executed with no session, no env file, no auth):**

```
$ php backend/api/mezmur.php   ($_GET['diag']='1')
{
  "diag": "mezmur-diag-3",
  "php": "8.4.23",
  "extensions": { "mysqli": true, "mbstring": false, "pdo_mysql": true },
  "opcache": "loaded but status unavailable",
  "parse": { "admin/api_mezmur.php": "parses on PHP 8.4.23", … 9 files … },
  "class_wiring": { "App\\Services\\SecurityRateLimiter": "loadable", … },
  "disk_controller": "current (has MEZMUR_API_VERSION marker)",
  "disk_controller_has_ping": true
}
```

**Reachability (CODE READ):** the `diag` block is at the top of `backend/api/mezmur.php` and `require_once __DIR__.'/../../admin/api_mezmur.php'` is the **last** line — so all five gates in the real controller (session, role, FeatureGate, CSRF, rate limit) run *after* it. `.htaccess` blocks `^api/v1/debug_`, `admin/get_schema.php`, `leak_detector.php`, `admin/id_cards/qr_diagnostic.php` — but **not** `backend/api/mezmur.php`. It is the only `diag` endpoint in the repo.

`?diag=2` additionally includes `config.php` and reports `p2['tables']` (which migrations are missing), `p2['feature_mezmur']`, and `p2['schema_drift']` from the reconciler. `config.php` only force-exits when the env file is missing or an *already-logged-in* admin session fails revalidation in a `/admin/` or `/monitor/` path — `$_SERVER['SCRIPT_NAME']` here is `/backend/api/mezmur.php`, so `_isPrivilegedBrowserArea()` is false and an anonymous caller falls through to the phase-2 output. (I could not execute `diag=2` here — no MySQL server in the sandbox — so the anonymous-reachability of phase 2 is **CODE READ**, not executed.)

**Fix.** Delete the block, or gate it behind `super_admin` + a constant that is off in production, and add `RewriteRule ^backend/api/mezmur\.php$ - [F,L]` alongside the other diagnostic denies.

---

### F3 · MEDIUM/HIGH — Presign constrains nothing but the key: no content-type, no content-length, no content sniffing

`presign()` signs `X-Amz-SignedHeaders=host` only. Nothing pins `Content-Type`, `Content-Length`, or an ETag/MD5.

**Evidence (VERIFIED against real S3):**

```
[6] LIVE PUT of 5 MB through a presign issued for a 1-byte declaration → HTTP 200
[7] LIVE PUT of text/html under an .mp3 key                            → HTTP 200

bucket listing afterwards:
  mz/audio/42/….mp3   1,232 B     ContentType: binary/octet-stream
  mz/audio/43/….mp3   5,242,880 B ContentType: binary/octet-stream
  mz/audio/44/….mp3          38 B ContentType: text/html      ← HTML under an .mp3 key
```

**Why it matters.**
1. **Storage-cost abuse.** `MEZMUR_MEDIA_MAX_BYTES` (15 MB) is checked only against the *client-declared* `size` in `beginUpload()`. The rate limit is 30 presigns/minute. One `mezmur_dept` account can write hundreds of GB to R2 while the DB records 1-byte files.
2. **Stored XSS on the media origin.** The public base is a custom domain (`https://media.fkss.arkeonethiopia.com`). R2/Cloudflare serves the stored `Content-Type`, so an `.mp3` object holding `text/html` renders as a page on your media subdomain — usable for phishing and for any cookie/origin assumptions tied to `*.arkeonethiopia.com`.
3. `confirmUpload()` claims to verify "with matching size" in its own docblock but the code only checks `in_array($head['status'], [200,206])`. `audio_size` in the DB is whatever the client declared and is never reconciled.

**Fix.** Sign `content-type` and `content-length` into the canonical request (`X-Amz-SignedHeaders=content-length;content-type;host`) and have clients send exactly those headers. In `confirmUpload()`, read `Content-Length` from the HEAD response and reject/repair a mismatch instead of trusting the reservation. Add a CDN-side rule forcing `Content-Disposition: attachment` / a fixed audio MIME for `mz/audio/*`.

---

### F4 · MEDIUM — Orphaned R2 objects, and a `pending` row the UI tells the user to "finish" but cannot

**Evidence (CODE READ):**
- `beginUpload()` builds a fresh key (`keyFor()`) and `UPDATE … SET audio_key=?` **without deleting the previously stored object**. Replace-audio and re-presign both orphan the old object forever; nothing lists or sweeps `mz/audio/`.
- A presign expires after 900 s. If the browser PUT never completes, the row sits at `audio_status='pending'` with a key that has no object. The only way out is another `beginUpload()`, which mints a new key.
- The web console's remedy (`frontend/js/mezmur.js:2218`) says *"Finish upload (choose the file again)"* — but choosing the file again calls `uploadAudio()` → `audio_presign` → **new key**, never a resume against the reserved one. The label describes a capability that does not exist.

**Fix.** On `beginUpload()`, `DELETE` the outgoing key first (best effort, like `removeAudio` already does). Add a nightly sweep for `mz/audio/**` objects with no live row, or store a `pending` object prefix and expire it.

---

### F5 · MEDIUM — Synced lyrics are an orphaned, unreachable feature with a client/server dialect mismatch

Four independent gaps, all **VERIFIED by grep across `.php`/`.dart`/`.js`/`.py`**:

1. **No producer.** `POST /mezmur/lyrics-synced` (`api/v1/routes/mezmur.php:540`) is the *only* writer. `frontend/js/mezmur.js` never mentions `lyrics_synced` or `lyrics-synced` — the web console's audio modal has pick/upload/confirm/remove/duration and **no LRC editor**. The mobile `api_service.dart` has no method for it. The only way to populate the column is `curl`.
2. **No test.** `grep -rn "lyrics.synced" tests/` → **zero hits**.
3. **It does not ride delta sync.** `MezmurHymnService::listChangedSince()` selects `$mediaCols` (`mediaColsExpr()` = `audio_key, audio_status, audio_duration_s, audio_size, audio_format, audio_updated_at`) and never `syncedColExpr()`. Only `getHymn()` selects `lyrics_synced`. So the mobile cache is populated solely by a per-hymn online fetch, and `local_db.dart:1742` *preserves* the cached copy when a delta omits the field — a server-side LRC edit therefore **never reaches a device that already cached the hymn**. There is no `lyrics_synced_at` comparison to invalidate on.
4. **The server validator is more permissive than the mobile parser, and the difference silently loses data.** Measured by running both regexes verbatim:

```
CASE                        | PHP GATE | PHP RESULT                          | DART PARSER
bracket run [00:01][00:09]  | ACCEPT   | t=1000ms text="[00:09.00]መዝሙር"      | 1 line, text="መዝሙር"  (00:01 stamp dropped)
three-stamp run             | ACCEPT   | t=60000ms text="[02:00][03:00]ድግስ"  | 1 line, text="ድግስ"     (2 stamps dropped)
[01:02:03.00] hour form     | REJECT   | "not a timestamp line"              | IGNORED
[Verse 1]                   | REJECT   | "not a timestamp line"              | IGNORED
two lines stamped [00:05.00]| ACCEPTED (validator uses `$ms < $lastMs`, not `<=`)
```

`mezmur_synced_lyrics.dart` documents bracket-run expansion as a feature ("Bracket runs like `[00:01.00][00:09.00]…` are expanded to two entries") and has code for it — but the server stores such a line as a *single* timed line whose text still contains the literal second stamp, and the Dart parser then strips it. The validator accepts input its own consumer will mangle. Also: sql/038's notes claim "timestamps strictly non-decreasing" while the code permits equal timestamps.

**Fix.** Either ship the editor (web + mobile) or remove the endpoint, the Dart parser and the columns. If keeping it: add `lyrics_synced` to `listChangedSince()`, add a `lyrics_synced_at` cursor comparison on device, and tighten the validator to reject multi-stamp lines (or normalise them server-side before storing).

---

### F6 · MEDIUM — Zero test coverage for the entire P0 audio plane, and the commit shipped 13 red tests

**Evidence (VERIFIED):**
```
$ python3 -m unittest discover -s tests/security -p 'test_mezmur*.py'
Ran 306 tests … FAILED (failures=12, errors=1, skipped=4)      ← main / 3bf4a9c

$ git checkout 23887fd  (parent)
Ran 306 tests … OK (skipped=4)                                 ← green before P0
$ git checkout 2ebe154
Ran 306 tests … OK (skipped=4)                                 ← green before P0
```
Full suite on `main`: **578 tests, 15 failures, 7 errors, 18 skipped** — 13 of those are Mezmur.

```
ERROR test_api_exposes_report_and_guarded_apply   IndexError: self.api.split("in_array($action, [")[1]
FAIL  test_mobile_local_schema_v19 / test_mobile_gradient_pipeline   'version: 19,' vs actual 20
FAIL  test_web_and_rest_routes / test_routes_gate_writes…  apiRoleIs(...) count 14 != 10
FAIL  test_web_api_new_actions          $__postActions literal no longer matches
FAIL  test_modals_are_dialogs…          role="dialog" 9 != 8
FAIL  test_shell_has_zero_inline_styles style= 2 != 0   (the P0 modal adds 2)
FAIL  test_no_company_names_in_ui, test_mobile_single_title, test_localdb_v9/v11_hymn_tables,
      test_mobile_fullscreen_category_pages
```

`git show --stat 3bf4a9c` touches **no file under `tests/`** except a **0-byte `tests/e2e/analyze_flutter.sh`**. `grep -rn "audio\|presign\|r2\|lrc" tests/` → nothing about the audio plane.

Two structural notes: the suite is *static analysis* (`self.assertIn("version: 19,", self.db)` — grep-on-source assertions), so it catches drift but proves no behaviour; and a 0-byte shell script committed as the only "test" artefact of a 2,955-line feature is a red flag on its own.

**Fix.** Update the 13 assertions to the new contract (that is what `23887fd` did last time, honestly labelled "reconcile stale contract tests"). Then add real coverage: a presign/signature test against a mock S3, a `confirmUpload` size-mismatch test, an LRC validator table test, and a `listChangedSince` shape test asserting which columns ride the delta.

---

### F7 · MEDIUM — `AudioService` is `exported="true"` with no permission guard

**Evidence (VERIFIED from the P0 diff to `AndroidManifest.xml`):**
```xml
<service android:name="com.ryanheise.audioservice.AudioService"
         android:foregroundServiceType="mediaPlayback"
         android:exported="true"
         tools:ignore="Instantiatable">
```
Any app on the device can bind this `MediaBrowserService` and drive the media session (transport commands, browse tree, metadata). The `tools:ignore="Instantiatable"` also tells me the class was declared without a build to confirm it resolves.

**Fix.** `android:exported="false"` for the service (media-button intents arrive via the separate `MediaButtonReceiver`, which does need to stay exported). Build a release APK before shipping to confirm both classes resolve under R8.

---

### F8 · MEDIUM — `POST_NOTIFICATIONS` declared but never requested → no lock-screen transport on Android 13+

**Evidence (VERIFIED):** `grep -n permission pubspec.yaml` → no `permission_handler`. `grep -rn "Permission\.|requestPermission|POST_NOTIFICATIONS" lib/` → **no runtime request anywhere**. The manifest declares the permission; on API 33+ a declared-but-ungranted permission means the media notification is not shown, so the headline "lock-screen transport / headset buttons" feature silently does not appear.

**Fix.** Add `permission_handler` and request `Permission.notification` on first playback, with a graceful degrade message.

---

### F9 · LOW — Version marker and DB cost details

- `define('MEZMUR_SCHEMA_MIN', 30)` in `admin/api_mezmur.php:65` is stamped into **every** response as `server_meta.schema`. Migrations now run to 038 and the module needs 038 for audio. Clients comparing against it get a false "current" (VERIFIED: the constant is unchanged in the P0 diff).
- `audio_set_duration` fires from the audio modal's `loadedmetadata` handler on *every* open of the modal, even when the value is unchanged, and it does `revision = revision + 1` — which pushes a delta row to **every device** in the fleet (`frontend/js/mezmur.js:2317`, `MezmurMediaService::setDuration` → `UPDATE … revision = revision + 1 WHERE id=? AND audio_status='ready'`). Guard with `AND audio_duration_s <> ?`.
- `env.example.php:93` advertises R2 as "free tier: 10 GB storage, $0 egress". Egress is indeed free, but Class A (PUT/LIST) and Class B (GET) *operations* are billed beyond the free allowance — a streaming workload generates a lot of Class B. Worth stating before someone sizes the budget on the comment.
- `.gitignore` covers `uploads/mezmur_categories/*` but **not** `uploads/mezmur_zemarians/*` (VERIFIED: `grep -c mezmur_zemarians .gitignore` → 0), so singer cover images will be committed by the next careless `git add -A`.
- `taxonomyImageStore()` auto-writes `<dir>/.htaccess` using `Require all denied` — Apache 2.4-only syntax, with no `<IfModule mod_authz_core.c>` wrapper (unlike `uploads/.htaccess`, which does it properly). On a 2.2 host that file 500s the whole directory.
- `uploads/.htaccess` `Require all granted`s `.svg` globally. Mezmur's own uploader re-encodes to JPEG/PNG so it is not the vector, but any SVG landing there is served as a document — a repo-wide stored-XSS door worth closing.

---

## 4. What is genuinely good (do not "fix" these)

- **`audio_key` never leaves the server.** `decorateRow()` unsets it and emits `audio_url` only when `status === 'ready'`. I traced every reader (`listHymns`, `getHymn`, `listChangedSince`, `admin/api_mezmur.php` list) and the key is consistently stripped.
- **The SigV4 presigner is correct** — verified against a real S3 implementation, not just eyeballed.
- **Layered auth is real, not decorative:** `access_control.php` ROLE_MAP → controller session + role re-check → `FeatureGate` fail-closed → `requireCsrfForPost()` → per-user rate limit (30 writes / 240 reads per minute) → per-action `apiRoleIs()` in REST.
- **Every mutation is a prepared statement**; the reconciler's string-built DDL takes identifiers from class constants, not user input.
- **Image uploads are properly hardened:** finfo magic bytes → `getimagesizefromstring` → full GD decode → **re-encode** (EXIF and embedded payloads stripped) → random 16-byte filename → 2 MB cap → dimension bounds → old file unlinked.
- **Schema probes everywhere** so a pre-030/pre-038 DB degrades to "no audio" instead of a 1054 fatal.
- **Audit trail is fail-soft but never silent** — `SecurityAuditService::record()` wrapped, failures to `error_log` with action + entity.
- **The search design is honest engineering:** the comments record that InnoDB FULLTEXT cannot tokenise Ge'ez and that a dead index build silently returned 0 rows, and the fix (an inverted word table) is the right one.
- **Bytes bypassing PHP** genuinely solves the shared-hosting upload ceiling; that architectural call is correct.

---

## 5. Prioritised action list

| # | Action | Severity | Effort |
|---|---|---|---|
| 1 | Teach `MezmurSchemaReconciler` about `sql/038`; remove the `DRAFT` marker; fix the misleading error message | HIGH | S |
| 2 | Gate or delete `backend/api/mezmur.php?diag=1|2`; add an `.htaccess` deny | HIGH | S |
| 3 | Sign `content-type` + `content-length` into the presign; verify `Content-Length` on confirm; force-download / fixed MIME at the CDN for `mz/audio/*` | HIGH | M |
| 4 | Update the 13 broken assertions; add behavioural tests for the audio plane; delete or implement the 0-byte `analyze_flutter.sh` | MEDIUM | M |
| 5 | Decide on synced lyrics: ship the editor + delta-sync the column, or remove the endpoint/parser/columns | MEDIUM | M |
| 6 | `exported="false"` on `AudioService`; request `POST_NOTIFICATIONS` at runtime | MEDIUM | S |
| 7 | Delete the outgoing R2 object on replace; sweep orphaned `mz/audio/**`; make the "finish upload" label honest | MEDIUM | M |
| 8 | Bump `MEZMUR_SCHEMA_MIN`; guard `setDuration` against no-op revision bumps | LOW | XS |
| 9 | `.gitignore` `uploads/mezmur_zemarians/*`; wrap the generated `.htaccess` in `mod_authz_core`; drop `.svg` from the allowed list | LOW | XS |

---

## 6. Reproducing this audit

```bash
# tests (VERIFIED)
python3 -m unittest discover -s tests/security -p 'test_mezmur*.py'   # 306 tests, 12F + 1E on main
python3 -m unittest discover -s tests/security                        # 578 tests, 15F + 7E on main
git checkout 23887fd && python3 -m unittest discover -s tests/security -p 'test_mezmur*.py'   # OK

# syntax
for f in admin/api_mezmur.php backend/api/mezmur.php api/v1/routes/mezmur.php \
         frontend/pages/mezmur_dept.php admin/backend/services/Mezmur*.php; do php -l "$f"; done

# the unauthenticated diagnostic (F2)
php -r '$_GET["diag"]="1"; $_SERVER["PHP_SELF"]="/backend/api/mezmur.php";
        include "backend/api/mezmur.php";'

# the live S3 harness (F1/F3) — /home/user/mz_audit/
#   MezmurMediaService_local.php  = project service, ONE line changed (endpoint host)
#   harness.php                   = drives the project's own presign() against moto over HTTPS
#   lrc_validator.php             = PHP gate vs Dart parser, regexes taken verbatim
sudo python3 -m moto.server -H 0.0.0.0 -p 5000 -s -c ca.pem -k key.pem
php harness.php live
```

---

## 7. Fix log — what has been applied

Baseline before fixes: Mezmur suite `306 tests, 12 failures + 1 error`. Full suite `578 tests, 15 failures + 7 errors`.

| Finding | Status | What changed | How it was verified |
|---|---|---|---|
| **F1** reconciler blind to 038 | **FIXED** | `MezmurSchemaReconciler::COLUMNS` gains all 7 `audio_*` + 3 `lyrics_synced*` columns; `CREATE` gains `mezmur_play_stats` + `mezmur_user_favorites`; `apply()` adds guarded `idx_mz38_audio_status`. The 4 misleading "Sync DB schema won't help" messages rewritten. `DRAFT` marker + dangling doc reference removed from `sql/038`. | `php -l`; `test_reconciler_closes_the_038_audio_drift` cross-checks the reconciler's column list against `sql/038` itself |
| **F2** unauthenticated `?diag` | **FIXED** | Gate inserted between the `diag` entry and the first disclosure: `session_start()` + `admin_logged_in` + `admin_role === 'super_admin'`, answering `404 {"status":"error","message":"Unknown endpoint."}` so it does not advertise itself. | **Executed** under 4 identities (`mz_audit/diag_gate.php`): `anon` / `mezmur_dept` / `school_admin` → all rejected; only `super_admin` receives the diagnostic. Regression test `test_diag_endpoint_is_super_admin_only` |
| **F3** unconstrained presign | **FIXED** | `presign()` now signs `content-type` + `content-length`; `contentTypeFor()` picks the MIME server-side from the extension; `beginUpload()` returns both so clients send them; `http()` captures response headers (`CURLOPT_HEADER`, plus the stream-wrapper fallback); `confirmUpload()` compares the object's real `Content-Length` to the reservation and sets `audio_status='rejected'` on mismatch. Web JS sends `d.content_type`, not `file.type`. Both controllers echo the new fields. | **Executed against real S3** (moto/HTTPS, `mz_audit/harness_signature.php`): `SignedHeaders = content-length;content-type;host`; signature changes when the headers, the reserved size, or the declared type change; honest PUT → 200 with `content-type: audio/mpeg`; `confirmUpload()` size check → `reserved=5242880 vs actual` mismatch → **reject**, and the exact-size case → **MATCH → ready**. 7 new tests in `MezmurMediaPlaneTests` |
| **F4** orphaned R2 objects | **PARTIALLY FIXED** | `beginUpload()` now `DELETE`s the outgoing key *before* reserving a new one, so replace and re-presign no longer leak. | `test_begin_upload_retires_the_outgoing_object` (asserts the DELETE precedes `keyFor()`). **Still open:** no sweeper for objects orphaned by an abandoned presign |
| **F7** `AudioService` exported | **FIXED** | `android:exported="false"` on the service (media buttons still arrive via the separately-exported `MediaButtonReceiver`). | `test_audio_service_is_not_exported`; manifest re-parsed as XML |
| **F8** `POST_NOTIFICATIONS` never requested | **FIXED** | `permission_handler: ^11.3.1` added; `_ensureNotificationPermission()` asks once inside `_ensureConfigured()`, before the first play, and a refusal only costs the shutter. | `test_notification_permission_is_declared_and_requested`. **Not compiled** — no Flutter SDK in this sandbox |
| **F9** LOW items | **FIXED** | `MEZMUR_SCHEMA_MIN` 30 → 38; `setDuration()` guarded with `audio_duration_s <> ?` so a modal open no longer bumps `revision` and forces a fleet-wide resync; `.gitignore` covers `uploads/mezmur_zemarians/*`; the auto-generated upload `.htaccess` now wraps both `mod_authz_core` and the 2.2 fallback; `.svg` removed from the `uploads/.htaccess` allow list; the R2 "$0 egress" cost claim corrected to mention Class A/B operations. | `php -l`; full suite re-run |
| **F6** red tests / no audio coverage | **FIXED** | 13 stale assertions reconciled to the shipped contract — and strengthened rather than merely renumbered: the role-gate count became a **named list of all 14 gated write routes** with a per-route gate + rate-limit check, plus a new `test_reads_stay_open_to_takers`; the brittle `$__postActions` literal match became a regex over the array; new classes `MezmurMediaPlaneTests` (7 tests) and `MezmurMobileAudioPlatformTests` (3). The `Spotify` brand-name violation the project's own policy test caught was removed, and the 2 inline styles moved into `themes/components.css`. | Mezmur suite **306 → 321 tests**, 13 broken → 0 (the single remaining failure is intentional, see below) |

### Current state

```
MEZMUR SUITE : 321 tests, 1 failure, 0 errors, 0 skipped
FULL SUITE   : 593 tests, 2 failures, 6 errors, 3 skipped
```

The **one** remaining Mezmur failure is deliberate — `test_pubspec_lock_contains_the_audio_dependencies`. It fails because of a blocker found while fixing (see F10). The 6 full-suite errors + 1 failure are the pre-existing missing member-registration module, confirmed present on the pre-P0 commit `23887fd`.

---

## 8. F10 · BLOCKER (found while fixing) — `pubspec.lock` has no entry for any P0 audio package

**Evidence (VERIFIED):**
```
$ git show --stat 3bf4a9c -- Mobile/wbws_flutter_app/pubspec.lock Mobile/wbws_flutter_app/pubspec.yaml
 Mobile/wbws_flutter_app/pubspec.yaml | 12 ++++++++++++
 1 file changed, 12 insertions(+)          ← the lock was NOT touched

$ grep -c "^  just_audio:" pubspec.lock          → 0
   just_audio_background / audio_service / audio_session / permission_handler → 0, 0, 0, 0
$ grep -c "^  [a-z_]*:" pubspec.lock             → 112 packages, none of them audio
```
and the code imports them:
```
lib/main.dart:5                        import 'package:just_audio_background/just_audio_background.dart';
lib/services/mezmur_audio_player.dart:3 import 'package:audio_session/audio_session.dart';
lib/services/mezmur_audio_player.dart:4 import 'package:audio_service/audio_service.dart';
lib/services/mezmur_audio_player.dart:6 import 'package:just_audio/just_audio.dart';
```

`pubspec.lock` is what `flutter build` resolves against. Four imports across two files reference packages the committed lock has never heard of, so **the mobile app cannot build** — `flutter pub get` was never run after the P0 dependency additions. The same is true of `MainActivity extends AudioServiceFragmentActivity`, whose class comes from `audio_service`.

This also means the P0 mobile work was never compiled, which is consistent with `tools:ignore="Instantiatable"` on the manifest entries and with the 0-byte `tests/e2e/analyze_flutter.sh`.

**Required action (cannot be done here — no Flutter SDK in the sandbox):**
```bash
cd Mobile/wbws_flutter_app
flutter pub get          # regenerates pubspec.lock with the audio packages + permission_handler
flutter analyze          # the four imports must resolve
flutter build apk --release   # confirms AudioServiceFragmentActivity + the ProGuard keeps
git add pubspec.lock && git commit
```
Then `test_pubspec_lock_contains_the_audio_dependencies` goes green on its own. Until that runs, treat the P0 mobile player as **unbuilt**.

---

## 9. Still open

| # | Item | Severity | Notes |
|---|---|---|---|
| F10 | Run `flutter pub get` and commit `pubspec.lock`; then `flutter analyze` + a release build | **BLOCKER** | Test written and failing on purpose |
| F5 | Synced lyrics | MEDIUM | Needs a decision: ship the editor (web + mobile) and add `lyrics_synced` to `listChangedSince()`, **or** remove the endpoint, the Dart parser and the columns. The client/server dialect mismatch is measured and documented in §3/F5 — bracket-run lines are accepted by the validator and silently lose stamps in the parser. |
| F4b | Orphan sweep for objects left by an abandoned 15-minute presign window | LOW | Replace/re-presign leaks are fixed; abandoned reservations still accumulate |
| — | CDN hardening for `mz/audio/*` | MEDIUM | Belt-and-braces alongside the signed Content-Type: force a fixed audio MIME / `Content-Disposition: attachment` on the media domain, and document the required bucket CORS rule (PUT from the app origin) in `DEPLOYMENT_RUNBOOK.md` |

---

## 10. Second remediation pass (2026-09-04) — F5, F10, executed end-to-end proof

### F10 · pubspec.lock — RESOLVED (executed)
`tests/e2e/analyze_flutter.sh` provisions swap + a real Flutter SDK and was run:
`flutter pub get` **regenerated `pubspec.lock`** — `just_audio`, `just_audio_background`,
`audio_service`, `audio_session`, `permission_handler` all present (one entry each).
`flutter analyze`: **0 errors** (470 pre-existing info-level lints app-wide; the only
warnings are in screens this remediation never touched). The mobile app now resolves
its audio imports; `test_pubspec_lock_contains_the_audio_dependencies` passes and stays
as a regression guard. **Remaining real-world step: `flutter build apk` on a dev machine.**

### F5 · synced lyrics — FIXED
- `MezmurMediaService::canonicalizeLrc()` — new **pure** canonicalizer
  (normalize-on-write, the pattern used by timed-text ingest pipelines):
  bracket runs `[00:01.00][00:09.00]text` are **expanded**, never folded into text or
  dropped; lines stable-sorted by time; 3-digit ms; `[00:75]`→`[01:15.000]`,
  `.5`→`.500` repaired; `[Section]`/hour-stamps rejected loudly. `saveSyncedLyrics()`
  persists the canonical doc, so the PHP validator and the Dart parser now share one
  dialect by construction.
- `MezmurHymnService::listChangedSince()` now selects `lyrics_synced` +
  `lyrics_synced_at` on **both** delta branches (probe-guarded). The Flutter
  `local_db.dart` upsert already applied those keys when present — the convergence
  gap was server-side only and is closed.
- New `MezmurSyncedLyricsContractTests` (7 tests): four of them **execute the real
  PHP** (`php -r` → `canonicalizeLrc`) instead of re-implementing it; three are
  source-contract regression guards (canonical doc persisted, delta carries the
  columns, Dart upsert still applies the keys).

### F4 residual UX — FIXED
`mezmur.js` pending-state copy no longer implies a resume that does not exist:
*"A previous upload was started but never finished, so it was discarded — choosing the
file starts a fresh upload (there is no resume)."*

### Executed end-to-end verification (this pass)
- `php -l` clean on all six changed PHP files.
- Mezmur suite: **328 ran / 0 failures / 0 errors** (was 306/12F/1E at baseline).
- Full suite: 600 ran / 1 F / 6 E — identical to the pre-P0 parent commits
  (missing `admin/hr_register_member.php` member module; out of mezmur scope).
- Media plane against real S3 protocol (moto 5.2.3 over TLS):
  `SignedHeaders=content-length;content-type;host`; signature changes when the header
  set, the reserved size, or the declared type change (YES×3); honest PUT → HEAD
  1232/audio-mpeg → **MATCH → ready**; wrong-type and 5 MB-vs-1232 attacks accepted by
  the lenient mock but **rejected by `confirmUpload()`'s size check**; signed
  DELETE → 204. (Mock does not enforce signed headers; a SigV4-compliant backend
  does — that is precisely why the headers are inside the signature.)
- Diag gate executed under 4 identities: anon/mezmur_dept/school_admin →
  `Unknown endpoint.`; super_admin → full JSON.
- Industry alignment: signing Content-Type/Content-Length into presigned PUTs and
  ≤15-min upload expiry follow AWS presign guidance; the delta-cursor sync follows the
  Microsoft Graph deltaLink / Drive change-token pattern (persist cursor, replay,
  full-resync fallback).

## 11. Mandatory-rules compliance map (this update)

| Rule | How this update complies |
|---|---|
| Front/back separation, easy UI swaps | Contracts live in services (`mediaColsExpr`, `syncedColExpr`, `decorateRow`, `canonicalizeLrc`); web JS and Flutter only *consume* fields; styles in `themes/components.css`; no business logic in views. |
| Scales to 100k+ users | Bytes never touch PHP/MySQL (direct-to-R2 presign); delta cursor pagination with caps; per-action rate limits; bulk taxonomy attach (no N+1); inverted word index for Ge'ez search; CDN-cached reads via custom domain. |
| No security gap on member data | Role gates per action, idempotency keys, rate limits, all-prepared SQL, signed `content-type`+`content-length` presign, server-side size verify → `rejected`, diag super_admin-only answering 404, fail-soft-but-logged audit, `.htaccess` exec/SVG hardening, UTF-8+size caps on LRC, exported-activity lockdown. |
| Matches current system, breaks nothing | Probe-guarded schema expressions (works with or without 038); reconciler adds missing columns; 328 green mezmur tests; `flutter analyze` 0 errors; full suite unchanged outside pre-existing member-module gap; rollback = revert one commit. |
| Maintainable / extendable | Pure unit-tested canonicalizer; single `MEZMUR_MEDIA_PUBLIC_BASE` config knob; one reconciler as schema source of truth; tests execute real code paths, not copies. |

## 12. Open items after this pass (honest remainder)

1. `flutter build apk --release` on a real dev machine (SDK proven here; build not run).
2. Orphan sweep for objects left by *abandoned* 15-min presign windows (F4b, LOW).
3. CDN hardening on the media custom domain (fixed audio MIME / attachment disposition) +
   bucket CORS rule documentation (MEDIUM, belt-and-braces).
4. Optional: a synced-lyrics *editor* UI (the endpoint is now safe and convergent; the
   editor is a feature decision, not a bug).
5. Pre-existing, out of scope: missing member-registration module (6E/1F), 470 Flutter
   info lints.
