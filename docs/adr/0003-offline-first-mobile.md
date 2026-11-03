# ADR-0003: Offline-first Android app with outboxes, idempotent writes and a change feed

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-08-19 to 2026-10-05 | Maintainers |

> Recorded on 2026-10-22; the decision date is shown above. Evidence is cited so the decision can be checked; where the rationale is not documented, this is stated.

## Context

Teachers record attendance and marks on low-end phones over 2G/3G or with no network. Lost or duplicated records are unacceptable.

## Decision

- Screens render from an on-device SQLite database first.
- Writes go to per-domain **outboxes** and are sent when online, with an `Idempotency-Key`. The server completes the idempotency record inside the business transaction, so a retry returns the original result.
- Outbox rows are bound to their owner and survive re-login.
- The app pulls updates from a server **change feed** (`sync_changes`, revision cursor).

## Consequences

- **Positive:** works without network; no duplicate records from retries.
- **Negative:** high complexity (`local_db.dart` is about 7,500 lines; schema version 37); conflicts and stale local data must be handled per domain; the local database is not encrypted at the application level.

## Evidence

`sql/009_api_idempotency.sql`, `sql/046_message_client_tag.sql`, `sql/057_sync_change_feed.sql`; `api/v1/core/middleware.php`; commits `86fdf15`, `3ed96b7`, `5ac8a1e`; `docs/MOBILE_OFFLINE_SYNC_*.md`.


## Locked-sheet preservation

A cached or freshly fetched packet lock proves workflow status, not receipt of
this phone's exact pending operation. Attendance, Mezmur and grade load paths
must keep their lock/read-only gates **without deleting local pending work**.
Only operation-specific settlement can establish upload acceptance; a locked
sheet must not silently acknowledge an unattempted draft.

The legacy `LocalDb.dropPendingAttendance`, `dropPendingGrades`,
`dropPendingMezmur` and `dropPendingHr` cleanup entry points are deprecated and
have no production callers. They are retained temporarily; their deletion
semantics are not safe automatic lock reconciliation. A source guard prevents
new production callers, and attendance widget tests verify locked responses
preserve the original pending payload and identity.

This is a bounded preservation fix, not a complete offline-first rollout.
Immutable attempted generations, exact business/receipt atomicity, final
reconciliation UX and the approved authorization/logout/device policy remain
separate implementation work. Existing explicit discard paths are unchanged
by this fix. No new database migration, API, role grant or encryption is added.


## Interrupted historical migration files

Before a writable SQLite open, inspect the database directory without following
links for historical plaintext-backup/encrypted-staging files and their
WAL/SHM/journal siblings. If any recognized artifact exists, keep all candidate
files unchanged and stop in the storage-protection screen. Directory inspection
errors also fail closed. A clean first install may create its parent directory;
ordinary main-only databases and their active SQLite WAL/SHM/journal are allowed.

Do not infer format, integrity, freshness or identical operation contents from
a backup filename. There is no automatic backup promotion, main deletion or
staging cleanup. Retry repeats inspection; it does not select or remove a copy.
The screen explains that files were preserved and warns against reinstalling
or clearing app data. This can intentionally block an installation with old
migration artifacts, even when its main database appears usable, until a safe
recovery decision is available.

This guard replaces the unsafe historical self-heal path; it does **not**
implement candidate comparison, full historical recovery, exclusive encrypted
conversion, SQLCipher or lost-key recovery. Those remain separate, tested design
work. Synthetic filesystem tests plus a real SQLite/LocalDb fixture verify that
a newer main database is not replaced by an older backup and that staging-only
survivors cannot authorize a fresh empty database.


## Orphaned ordinary SQLite sidecars

The same pre-open guard also blocks when `-wal`, `-shm` or `-journal` exists
without a regular main database file. A directory or symlink under the main
filename is not accepted as a regular main for this check. Preserve all files;
do not create a new main, pair/reconstruct survivors, checkpoint or delete them.
The file-review screen covers both historical migration and ordinary orphan
sidecar errors, with the same no-clear-data/no-reinstall guidance.

A regular main with its ordinary sidecars remains allowed: SQLite, not this
metadata guard, owns normal crash recovery. Enumeration must finish before
judging main absence because directory order is unspecified. This is not an
exclusive file-lock protocol or a diagnosis of corruption/format/unknown names.
An external writer after inspection remains a separate race boundary.

Synthetic regression evidence includes a committed update isolated in a WAL:
opening a missing main can create an unrelated database and discard that WAL.
Standalone sidecars therefore cannot justify a clean first-install decision.
See [SQLite corruption guidance](https://www.sqlite.org/howtocorrupt.html),
sections 1.2–1.4, for why main and recovery journals must stay together. Actual
recovery of incomplete survivor sets remains separate reviewed work.


## Recovery Retry lifecycle

The storage-protection screen permits one bootstrap attempt at a time and
restores Retry after an attempt completes if the screen is still mounted.
Handled storage failures can replace the root with another instance of the
same widget type and retain its State; a new error detail is not evidence that
the busy flag was reset. Successful root replacement or disposal must not
cause a later setState on the old screen. Unexpected bootstrap errors still
propagate; busy cleanup is not error suppression.

Production Retry still invokes the existing runBootstrap and all storage,
session and lock checks. An injectable bootstrap callback supports isolated
widget lifecycle tests, not a new UI bypass. No automatic retry, file recovery,
discard/reset action, worker or authorization policy is added.
