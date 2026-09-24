# WBWS App — Release Notes

User-facing notes per release. Keep the newest on top. The build
script runs the full test suite (including the version-sync pin) so
the version below must always match `pubspec.yaml` +
`AppConfig.appVersion`.

## 1.5.0 (build 24) — Safer access and recoverable offline work

- Live role, status, and teacher-assignment changes now reconcile before the
  app continues under an old authorization scope.
- Authentication expiry preserves owner-bound offline attendance, grades,
  communication, and hymn operations for safe same-account recovery.
- Sync Center shows queued and needs-attention work without exposing another
  user's payload, and recovery actions target the exact operation reviewed.
- Legacy attendance/grade replacement and settlement are atomic and
  operation-identity safe across rapid edits, retries, crashes, and races.
- Operations can remotely pause outbound background drains without disabling
  durable local saves or deleting queued rows.

## 1.4.0 (build 23) — Works offline (Messages)

The Messages feature is now offline-first, like WhatsApp: the phone's
database is what you see, and the network only refreshes it.

### Opens instantly, everywhere

- **Instant thread list** — Messages opens with your conversations
  immediately; the first-ever open is the only one that waits for the
  network.
- **Instant conversations** — every thread opens from its local
  history, airplane mode included, receipts and all. History you
  scrolled through before stays available offline ("Load older"
  serves it from the phone).
- **Instant badge** — the bell shows your unread count the moment the
  app starts, not after the first network round-trip.

### Sends that survive

- **Send in airplane mode** — a message leaves the composer
  immediately with a clock icon and delivers itself the moment the
  connection returns. The phone keeps trying on its own (with
  smart, staggered retries), so you never tap "retry" for a
  connection problem again.
- **Real failures are honest** — if the school's server rejects a
  message on the merits, the bubble turns red with the reason once;
  tap to retry, long-press to discard.
- **Drafts are kept** — a half-written reply survives closing the
  app, per conversation, like WhatsApp.

### Behind the scenes

- Messages are stored locally (with the same sign-out wipe as all
  your other data on a shared phone) and synced with cheap
  conditional requests — idle polls cost almost nothing.
- Each send carries a unique tag so a flaky connection can never
  post the same message twice (server migration 046).
- Local history is capped at the newest 500 messages per
  conversation (older ones load from the server on demand).

## 1.3.0 (build 22) — Communication polish (UX audit)

A full accessibility and craft pass over Messages and Notifications,
built on a professional UI/UX audit of the feature.

### Readable by everyone (accessibility)

- **High-contrast messages** — your own messages now use a light
  background with dark text (WhatsApp-style); timestamps, ✓✓ "Seen"
  and failed-send states are clearly visible on any screen, in
  sunlight, for low-vision users. Every text pair meets the WCAG AA
  contrast standard.
- **Screen-reader support** — unread badges, receipts, deleted
  placeholders and the notification bell now announce themselves
  properly to TalkBack/VoiceOver, including the unread count.
- **Bigger touch targets** for message menus and the bell badge.

### Messages that feel right

- **Message grouping** — streaks from one sender collapse into tidy
  groups: name once, time on the last bubble, tight spacing.
- **Copy & links** — long-press any message to copy it. Links,
  email addresses and phone numbers in messages are tappable (open
  in browser / mail / dialer).
- **Drafts** — a half-written reply survives leaving the
  conversation and comes back when you return.
- **New-message pill** — reading history while new messages arrive
  shows a "N new messages ↓" pill instead of jumping the screen.
- **Thread times** — the conversation list shows when each thread
  was last active (Today 14:05 / Yesterday / weekday / date).
- **Searchable recipients** — the new-conversation picker has a
  search box, removable chips and a selection counter.

### A trustworthy inbox

- **Actionable alerts** — tapping an alert about a person now opens
  that person's profile directly (in addition to marking it read).
- **Offline never loses your content** — a failed refresh keeps
  your rows and shows a slim "offline — showing recent" banner
  instead of wiping the list.
- **Per-type icons** — attendance, enrollment, tasks, roles and
  member events each get their own icon.
- **No more flashing skeletons** when returning from a conversation
  or marking everything read.
- **Battery-friendly** — the notification poll pauses whenever the
  app is in the background and refreshes immediately on return.

### For the server administrator

- No new migrations in this release (044 + 045 from the previous
  release still apply if not yet applied). The alert deep-link uses
  data the server already stores — an additive field older apps and
  the web simply ignore.

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
