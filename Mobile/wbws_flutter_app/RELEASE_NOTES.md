# WBWS App — Release Notes

User-facing notes per release. Keep the newest on top. The build
script runs the full test suite (including the version-sync pin) so
the version below must always match `pubspec.yaml` +
`AppConfig.appVersion`.

## 1.2.0 (build 21) — Communication parity

The Messages and Notifications experience now matches the web
Communication Center feature for feature.

### Conversations

- **Read receipts** — your messages show ✓✓ "Seen" once everyone in
  the conversation has read them; ✓ while still unread. Receipts
  update live while the conversation is open.
- **Edit & delete your messages** — tap the ⋯ on one of your messages
  (or long-press it) to edit it or delete it for everyone. Deleted
  messages leave a "This message was deleted" placeholder; the
  content is gone for good.
- **Load older messages** — long conversations page in from the
  server as you scroll up; your position never jumps.
- **Faster, more reliable sending** — your message appears the
  moment you tap send; if the network drops, it stays in place with
  a one-tap retry.
- **Day separators** — Today / Yesterday / dates between message
  groups.
- New conversations: pick several recipients at once, as before.

### Notifications inbox

- **All / Unread filter** on alerts, with the live unread count.
- **Load older** on both alerts and announcements — history pages in
  on demand instead of being capped at the first 40.
- **Instant mark-as-read** — tapping an alert or announcement clears
  its unread state immediately (the save happens in the background
  and self-corrects if it fails).
- **Clearer offline states** — "Could not load + Retry" screens when
  the list itself couldn't be fetched, instead of a misleading
  "No announcements".

### Performance & data

- **Idle polling now costs almost nothing** — the app asks the
  server "anything changed?" and a "no" (HTTP 304) carries zero
  payload. On a quiet day this removes the largest recurring
  background transfer the app makes.
- The open conversation also polls conditionally — no change, no
  download, no wasted writes.

### Fixes

- Fixed a build-blocking defect from 1.1.17: five department home
  screens (Admin, Attendance Taker, Teacher, Education Dept, Info
  Dept) had malformed code around the notification bell and could
  not compile. If you are on 1.1.17, update before anything else.
- Kept the notification badge from drifting when marking items read
  while offline (reverts cleanly on failure).

### Notes for this release

- Server side: requires the current SSMS server (P73+); the new
  message features degrade gracefully — the app simply doesn't show
  them — if the server is older, except the compile fix, which is
  client-only.
- Tasks remain web-only by design.

## 1.1.17 (build 20) — prior release

See repository history.
