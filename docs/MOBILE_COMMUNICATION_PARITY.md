# Mobile Communication Parity — Master Plan (P74)

Bring the Flutter app's Communication experience to parity with the
P73 web Communication Center. One backend service
(`NotificationCenterService`), one permission matrix, two clients.

## 0. Request contract (verbatim understanding)

- "ok the web version is great now lets move to the mobile app"
- Scope decision: **full P73 parity**, but **Tasks stay web-only**
  (user decision 2026-09-13 — no Tasks tab on mobile).
- Verification decision (user instruction): the Flutter/Dart SDK is
  **downloaded into the sandbox only when needed, used for
  `flutter analyze` + `flutter test`, then deleted immediately** —
  workspace space is limited. The user additionally builds and
  verifies on **real devices** locally before release.
- All standing rules carry over: universal protocol (deliverables,
  verification, 15-section report, no assumptions — label
  INFERENCE/UNKNOWN), one phase per round, explicit staging, byte
  identical failing-ID diffs, migrations called out prominently.

## 1. Current state (verified in code, 2026-09-13)

- The app talks to REST **API v1** (`/api/v1/notifications/*`,
  JWT Bearer, per-IP rate limits 480 read / 120 write per minute) —
  never to the admin AJAX surface.
- `api/v1/routes/notifications.php` is a **P72 snapshot**: no
  `message-edit`/`message-delete`, no `thread-read`, no cursor
  pagination params, no thread-window metadata (has_older/oldest_id/
  read_watermark), no ETag/304.
- `NotificationService` (app) polls `summary` every 30 s — full
  response every time, no conditional GET.
- `NotificationCenterScreen`: 2 tabs (Alerts/Announcements), mark-all,
  announcement composer (can_announce), single-fetch lists.
- `MessagesScreen`: thread list → conversation → reply, new-thread via
  partners. No receipts, no edit/delete, no load-older, no optimistic
  send.
- The service layer already supports **everything** P73 added (the web
  admin API exposes it); v1 just doesn't surface it.

## 2. Non-goals

Tasks on mobile (user decision); push notifications (unchanged);
media/voice/typing indicators (P73 non-goals carry over);
WebSockets/SSE; changes to the admin AJAX surface (already shipped,
frozen).

## 3. Phase plan (one phase per work unit; each ships green)

| Phase | Scope | Acceptance |
|---|---|---|
| **1 — v1 API parity surface** (current) | `api/v1/routes/notifications.php`: cursor params on feed/announcements/threads/thread (+ window metadata + read_watermark), `message-edit`, `message-delete`, `thread-read`, ETag/304 on `summary` + `thread`. **Additive only — existing endpoints stay byte-compatible for installed builds.** | New v1 e2e suite green on real MariaDB + PHP 8.3 (JWT-minted requests, child-process-per-request); legacy offset path still green; admin e2e suite still green; full pytest matrix with byte-identical failing-ID diff. |
| 2 — Messaging UX (Flutter) | ✓✓ read receipts, edit/delete own messages + tombstones, "Load older messages" (window cursor), optimistic send + inline retry, day separators. | `flutter analyze` clean + `flutter test` green (SDK installed then deleted); API-service layer unit-tested where pure-Dart; user does a real-device pass. |
| 3 — Inbox UX + polling (Flutter) | ETag-aware 30 s poller (304 = no-op, zero wasted bytes), cursor "Load older" on alerts + announcements, All/Unread filter, per-item mark-read with optimistic UI. | Same gate discipline; battery/data win measured (poll bytes before/after on a seeded DB). |
| 4 — Final verification & release prep | Full matrix, offline behavior review, docs, release notes for the app build. | Five-rule equivalent sign-off; user real-device sign-off. |

## 4. Compatibility rules

- v1 additions are **additive**: new optional query params, new
  response fields, new actions. Installed app builds must keep
  working unchanged (old fields never removed/renamed; legacy offset
  pagination stays).
- No new migration in Phase 1 (tables unchanged — service owns them).
  If a later phase needs one, it gets the prominent manual-apply
  callout in commit + doc + report.

## 5. Verification workflow

- **PHP rounds (Phase 1):** sandbox e2e — real MariaDB 11.8 + static
  PHP 8.3 (bulk build), JWTs minted with the test `JWT_SECRET`, one
  child process per request (the v1 core `exit`s after every
  response). Rate-limiter table applied from `sql/008`.
- **Flutter rounds (Phases 2–4):** Flutter SDK downloaded to `/opt`
  (outside the workspace snapshot), `flutter analyze` + `flutter test`
  run, SDK **deleted immediately after** (user instruction — limited
  disk). User then builds and verifies on real devices.
- Every round: full pytest matrix + failing-ID diff byte-identical
  (stash-in-main-tree method), 15-section report, explicit staging.

## 6. Phase 1 — v1 API parity surface — SHIPPED

**No migration this round** (the service layer owns the tables;
nothing schema-level changed). You still owe `044` + `045` from P73 if
not yet applied.

### What shipped (`api/v1/routes/notifications.php`, additive only)

- **Cursor pagination**: feed `before_id`; announcements
  `before_pin`+`before_id` (+ has_more/next_before/next_pin in the
  response); threads `limit` + `(before_lm, before_id)` tuple cursor
  (+ has_more/next); thread window `before_id` + `read_watermark` /
  `has_older` / `oldest_id` — the newest-200 window contract the web
  ships.
- **Message management**: `POST message-edit` / `POST message-delete`
  (ownership enforced atomically in the service's SQL — "only your own
  messages") and `POST thread-read` (explicit mark without refetch).
- **Conditional GETs**: `summary` + `thread` answer
  `ETag: "ncsum-…"/"ncthr-…"` and reply **304 with an empty body** on
  If-None-Match; the 304 path precedes `markThreadRead`, so an idle
  poll performs zero writes. `threadVersion` is participation-gated —
  non-participants get no 304 oracle (404, never 304).
- Legacy offset params and every P72 response field are unchanged —
  installed app builds keep working.

### Verification

- New E2E suite `tests/e2e/comm_v1_lifecycle.php` (real MariaDB +
  PHP 8.3; JWTs minted with the test secret; one child process per
  request because the v1 core `exit`s per response):
  **34 checks green** — parity 15 · etag304 10 · legacy 6 · unauth 3.
  Pins: cursor pages tile exactly (30 alerts, 260-message thread,
  13 conversations 5+5+3), window metadata exact, edit/delete
  ownership + tombstone (body never returned), role-matrix denial,
  304 = empty body + zero writes (backdated read-state probe),
  non-participant guessed-etag → 404, offset path + P72 field shapes
  intact, forged/refresh/missing tokens → 401.
- Admin e2e suite unchanged and green (57 checks, 9 scenarios) — one
  harness fix: the `ratelimit` scenario now clears BOTH limiter
  backends (the API is DB-backed whenever `$pdo` connects —
  admin/config.php loads the root config — with file fallback), and
  the block-persists check reads the bucket state directly (a
  behavioral re-probe is impossible by design: the 429 path `exit`s).
- pytest: 780 passed / 42 skipped / 40 pre-existing env failures;
  failing-ID diff vs `f7924e7` **byte-identical (40/40)**.
- Runtime gate 108/108 (web client untouched); `php -l` clean.

### Next: Phase 2 — Messaging UX (Flutter)

Receipts (✓✓ via read_watermark), edit/delete + tombstones,
"Load older" (window cursor), optimistic send + inline retry.
Verified with a Flutter SDK downloaded into the sandbox, used for
`flutter analyze` + `flutter test`, then deleted immediately; the
user does the real-device pass.
