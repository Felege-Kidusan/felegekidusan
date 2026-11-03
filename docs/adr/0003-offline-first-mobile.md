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
