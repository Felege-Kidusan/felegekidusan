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

## 7. Phase 2 — Messaging UX (Flutter) — SHIPPED

**No migration this round.** You still owe `044` + `045` (P73) if not
yet applied.

### What shipped (mobile app)

- **Read receipts** — own bubbles show ✓✓ "Seen" (watermark color
  `#38BDF8`, same as the web's `.nc-seen`) once every other
  participant's watermark reaches them; ✓ before that. Receipts flip
  live: the open conversation refreshes every 30 s (paused while the
  app is backgrounded, like the web's visibility-paused pollers).
- **Edit / delete own messages** — ⋯ affordance or long-press →
  Edit (prefilled sheet) / Delete with a "Delete for everyone?"
  confirmation. Both are optimistic with revert-on-failure; deletes
  leave a tombstone ("This message was deleted", no body, no menu,
  no receipt — the content never comes back).
- **Load older messages** — the server's stable `oldest_id` cursor;
  pages prepend without disturbing scroll position (reversed list).
- **Optimistic send + inline retry** — the bubble appears instantly
  with "Sending…"; failures keep the text in the bubble with the
  reason and a tap-to-retry state (long-press discards).
- **Day separators** — Today / Yesterday / `9 Sep 2026`, same labels
  and local-time parsing as the web.
- New pure-Dart view-model `lib/services/messaging_view_model.dart`
  is a line-by-line port of the web's rendering rules
  (admin/js/comm.js), pinned by 23 new unit tests.
- Deliberate divergence (documented): a refresh keeps already-loaded
  older pages instead of collapsing to the newest window like the
  web — yanking loaded history out from under the user's scroll
  position would be a bug on mobile, not parity.

### Drive-by fixes (pre-existing baseline breakage, blocks the gate)

The P72 mobile commit (`c385b97`) shipped with compile errors in five
home screens and `NotificationService` — the app could not compile,
so `flutter test` failed at baseline:

- `admin_home` / `att_taker_home` / `teacher_home`: a stray positional
  `NotificationBellButton` argument inside `SliverAppBar(...)` — moved
  into `actions:` (the pattern every other home screen uses).
- `edu_home` / `info_home`: duplicated `actions:` named argument from
  a bad merge — deduped (bell + refresh kept).
- `notification_service.dart`: missing
  `import 'package:flutter/foundation.dart';` (`ValueNotifier`
  undefined).

### Verification

- **Flutter SDK 3.47.4** (stable, downloaded to `/opt`, deleted
  immediately after): `flutter analyze` **0 errors** (497 issues =
  the 506 pre-existing baseline infos/warnings minus the 9 errors
  fixed above; this round added ZERO new issues — verified by a
  stash-in-main-tree baseline diff of the issue set). `flutter test`
  **358/358 passed** (335 pre-existing + 23 new
  `test/messaging_parity_test.dart`).
- Full pytest matrix: 780 passed / 42 skipped / 40 pre-existing env
  failures; failing-ID diff vs the previous commit **byte-identical
  (40/40)**. Runtime gate 108/108 (web JS untouched).

### Next: Phase 3 — Inbox UX + polling (Flutter)

ETag-aware 30 s poller (304 = no-op — the v1 surface already answers
conditional GETs), cursor "Load older" on alerts + announcements,
All/Unread filter, per-item mark-read with optimistic UI.

## 8. Phase 3 — Inbox UX + polling (Flutter) — SHIPPED

**No migration this round.** You still owe `044` + `045` (P73) if not
yet applied.

### What shipped (mobile app)

- **ETag-aware 30 s poller** — `NotificationService` stores the
  summary's `ETag` (only replaced on a full 200) and sends
  `If-None-Match` on every poll; a 304 is a no-op: zero body bytes,
  no JSON parse, badge/summary untouched. The v1 surface has answered
  conditional GETs since Phase 1 (pinned: 304 = empty body + zero DB
  writes), so an idle poll drops from the full summary JSON (~1–2 KB
  by role) to 0 body bytes + a ~40-byte request header. Plumbing:
  `ApiResponse.etag` / `.notModified`, conditional GETs bypass the
  in-flight dedup (caller-specific headers), 304 handled before body
  decode, ETag survives the 401-refresh retry.
- **"Load older" on alerts + announcements** — alerts page by the
  stable `before_id` cursor; announcements by the `(before_pin,
  before_id)` tuple (pinned ordering). Pages append newest-first,
  de-duplicated at the window edge; the control only appears while
  the server says an older page exists.
- **All / Unread filter** (alerts) — server-side filter (`unread=1`),
  same as the web's `unreadOnly` toggle; the Unread chip carries the
  live count.
- **Optimistic per-item mark-read** (alerts + announcements) — web
  parity (P73 Phase 4 / D7): the unread state clears instantly, the
  badge decrements locally (floored at zero), the write confirms in
  the background; failure reverts by refetching + toast. Read rows
  stay visible in the Unread-filtered view until reload (web
  behavior — the filter is a query, not a live sieve).
- New pure-Dart view-model `lib/services/inbox_view_model.dart`
  (ETag state machine, cursor/page merges, optimistic read,
  zero-floor decrement) pinned by 15 new unit tests
  (`test/inbox_parity_test.dart`).

### Verification (new workflow — user instruction 2026-09-13)

Per the user's instruction the Flutter/Dart SDK is no longer
downloaded into the sandbox: **the user runs `flutter analyze` +
`flutter test` and the device pass locally.** This round's sandbox
verification was therefore: careful static review of every Dart
change (including a string/comment-stripped brace/paren balance check
on all touched files), plus the unchanged server-side gates — pytest
780 passed / 42 skipped / 40 pre-existing env failures, failing-ID
diff **byte-identical (40/40)**, runtime gate 108/108. The user's
local run is the binding gate for this phase.

### Next: Phase 4 — Final verification & release prep

Full matrix, offline behavior review, docs, release notes for the
app build, five-rule-equivalent sign-off + user real-device
sign-off.
