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


## Attendance capture-session write fence

Manual save, submit and autosave supply an immutable snapshot of the loaded
sheet's owner, authorization version and runtime session generation. This is
capture identity, not an authorization grant. Attendance's internal save API
requires it; no unchecked default is supplied. Class/date/record arguments are
also materialized synchronously before the serialized save can await.

Inside the same SQLite transaction, before any pending-row replacement, reuse
the durable active-session predicate with that expected identity. A mismatch,
inactive session or invalid capture raises a typed stale-save error and leaves
already-durable rows unchanged. A delayed capture is never rebound to a later
active account. The UI explicitly reports that these edits were not saved,
keeps dirty/rendered state when still mounted, and does not report success.

This fence covers attendance only. Other-domain writers, cache-application
fences, immutable attempted generations and account-transition quiescence
remain separate work. Rejecting a stale, uncommitted save does not persist it
for recovery; preservation of such captures across screen disposal still needs
the lifecycle/durability design. No claim of full unsent-work recovery or P2
completion is made. No schema, API, authorization-duration or lock-policy change.


## Attendance download-session fence

The feed coordinator captures owner, authorization version and runtime
generation once per pull, reusing `LegacyWriteSession`. Before requests and
after responses it checks both the runtime identity and durable active session.
`applyAttendanceDelta`, bootstrap reset/completion, and scoped attendance status
writes require that original identity inside the same SQLite transaction before
mutation. Status recording reads its existing cursor inside that transaction.
A stale download raises `StaleAttendanceSyncSession`; it must not write a failure
status into a later account. Error reporting remains best-effort and the running
gate is released on failure. No new queue, schema or server authority is added.

This is not complete preparation or a repaired bootstrap contract. The existing
current-session bootstrap still clears cached sheets and adopts a server cursor;
complete authorized datasets, preservation-first staging and coverage validation
remain release blockers. Direct screen/WarmStore cache writers, read ownership,
inter-isolate cursor ordering and account-transition quiescence remain separate
work. The fence protects against obsolete download mutations, not all cache
races or recovery of uncommitted edits.


## Attempted attendance generation preservation

Attendance saves retain previously attempted or non-pristine generations in the
existing pending table. Only whole pristine pending generations with known IDs,
zero attempts, no attempt time and no ledger evidence may coalesce. Attempt or
non-pristine evidence in any sibling row protects the entire generation. New edits still save a complete generation
with a fresh stable ID. No schema or parallel queue is required.

Attendance claims respect same-owner/class/date insertion order across unresolved
generations, including older authorization versions. An in-flight, backed-off,
paused, review or unknown older operation blocks later sending for that sheet;
other sheets may progress. Exact accepted or resolved-conflict outcomes release
the next generation. This is local ordering, not cross-device conflict authority.

Attendance read projections show the latest complete persisted generation, not
a union of historical member rows. The latest-generation selection includes
synced history so older terminal rows cannot reappear after newer acceptance.
Undo targets only that newest generation and refuses all evidence of a previous
attempt; an unexpected partial undo rolls back. Exact settlement and attempt
closure remain scoped to their original operation ID.

Other packet domains, complete preparation/bootstrap retention, exact server
receipts/partial acceptance, cross-device ordering, workflow-lock reconciliation,
account-transition quiescence and auth/logout/revocation remain separate release
blockers. An older paused authorization generation may intentionally hold newer
sending until authorized reconciliation; no destructive override is introduced.

Attendance eligibility is shared by claims, due-work checks, retry wakeups and
status inventory. Newer blocked generations count as dependencies, not due work;
they cannot cause repeated immediate rescans or earlier useless retry wakeups.
Recovery retry preserves attendance attempt counters/timestamps so the next
transmission uses the next ledger attempt number, not a reused attempt 1.

Seven-day cleanup retains only the newest accepted attendance generation while
older same-owner/authorization/sheet unsynced history needs it as a projection
checkpoint. This also applies while an even newer edit remains unsynced.
Unneeded/intermediate accepted generations follow the existing cleanup rule;
unsynced rows are never removed by cleanup. No new retention period is invented.

Existing destructive discard/reset/logout paths are not approved implementations
of the confirmed no-override policy. Their safe replacement/guarding, legacy
inconsistent-ledger repair and malformed-operation quarantine remain release
blockers. This change neither adds nor authorizes such bypasses.


## Attendance recovery requires exact server disposition

The attendance recovery UI no longer offers destructive Discard or Acknowledge.
Both previously reached the same unsynced-row deletion. A local rejected/review/
resolved-conflict label and explicit user consent are not an exact receipt or a
terminal server-confirmed disposition. LocalDb now rejects attendance manual
recovery deletion with a typed confirmation-required result. The deprecated
natural-key attendance drop fails closed too; there is no force/receipt boolean
bypass. A stale action explains why saved attendance is retained.

This deliberately holds attendance history until protected authoritative
reconciliation exists. It does not implement that receipt/review contract or
claim that the existing upload settlement response proves full acceptance.
Normal settlement and accepted-row cleanup are unchanged. Other-domain recovery,
account reset/logout/private cleanup and revocation remain separate release
blockers; their existing destructive paths are not approved policy by analogy.

## Database-backed attendance success-response atomicity

Attendance draft/submit now build their unchanged success envelope and complete
an acquired database-backed replay reservation inside the existing business
transaction, before commit. The wire sender shares envelope/JSON encoding. A
failed completion/encoding raises a typed precommit failure; rollback must
succeed before the processing reservation can be abandoned. The ordinary
response sender is detached BEFORE rollback so even an outer exception-handler
500 cannot replace an uncertain outcome. The resulting fixed 503 is retryable, not stored
as a permanent replay. An unconfirmed rollback does not abandon anything.
Unknown commit outcomes never use this precommit cleanup path.

Existing unkeyed and known file-backed legacy compatibility behavior remains;
it is NOT strict response atomicity. Other endpoints and the permissive generic
helper are unchanged. This is not the full exact receipt contract: replay expiry
is still seven days, authenticated long-lived status lookup/manifest binding,
capability negotiation, audit delivery, cross-device ordering and installed
transactional-schema verification remain release blockers. No schema, TTL,
permission, URL, mobile settlement or rollout policy changes are implied.

The strict attendance path invokes the existing replay service directly, not
the permissive wrapper that also runs advisory monitor SQL. Monitoring remains
in the ordinary postcommit sender. An advisory deadlock must not silently roll
back business/receipt writes after their final checkpoint; a lost response may
leave stale advisory monitoring, but cannot invalidate the committed receipt.

## Read-only attendance replay-cache inspection (development only)

`POST /api/v1/attendance/operation-status?kind=draft|submit` inspects the existing
shared database replay cache. Send the unchanged original JSON and original
operation key (header or `client_op_id`; conflicting keys are rejected). JSON
requests are bounded to 1 MiB for this diagnostic endpoint only; larger original
uploads cannot use this diagnostic and must remain unresolved. Existing upload
limits and clients are unchanged. Hashing uses the existing canonical request
helper with the ORIGINAL `POST attendance` or `POST attendance/submit` scope,
not the lookup URL. The signed current user supplies the owner identity.

Current account/role/authorization-version checks are mandatory even for clients
outside the legacy authorization rollout cohort. The shared scope verifier has
an optional server-only current-scope requirement; its existing callers retain
their compatibility behavior. Current attendance role, class assignment and
academic-year access must pass. This is not a historical/revoked-scope access
exception. Inspection does not rerun roster, class-day or backdate WRITE rules.

Every result identifies version 1, authority `legacy_replay_cache`, `durable:
false` and `authorizes_settlement: false`. States are `unknown`, `processing`,
`payload_mismatch`, or `completed` (only `cached_http_status` is exposed).
Unavailable backend/schema produces fixed 503 `OPERATION_STATUS_UNAVAILABLE`
with the same non-settlement flags. No cached response, request hash, lease or
owner token is disclosed. A completed cached 500 is not a business rejection.

The service performs one indexed SELECT. It never claims work, recovers an
expired lease, extends expiry, sweeps/deletes rows, reads legacy/file caches,
creates schema or invokes attendance mutations. Uncommitted completion is not
visible from another connection. Expired completion is `unknown`, NOT evidence
that nothing committed; an expired processing lease is still `processing`
while its cache record is unexpired. All observations preserve unsent work and
provide neither retry authority nor permission for cleanup/logout/settlement.

This endpoint is deliberately not wired into mobile settlement. Durable receipt
lifetime, manifest coverage, negotiation, safe reconciliation, immediate
access/refresh lost-device revocation and installed-schema/device evidence
remain separate blockers. This is development-source evidence, not deployment
or full P5 acceptance.

## Negative durable-receipt negotiation (development only)

`GET /api/v1/attendance/capabilities` is an authenticated, current-scope,
attendance-role-only protocol declaration: version 1,
`durable_receipt_versions: []`, `durable: false`,
`authorizes_settlement: false`. It exposes no roster, assignments, backend
status or installed-schema readiness. It is not a complete offline-readiness
capability and does not claim strict receipts are available. GET only; no
nested paths; no-store. Existing global OPTIONS preflight remains unchanged.

An attendance draft/submit POST carrying **any present**
`X-Attendance-Receipt-Version` header fails with 412
`ATTENDANCE_RECEIPT_CONTRACT_UNSUPPORTED`, the same negative capability flags
and no-store. Empty, unknown and future versions also fail. Current account,
role and authorization scope are checked even outside the legacy rollout
cohort. The precondition guard precedes body parsing, reservation/replay,
write-rate accounting, workflow and attendance mutations. Even an existing
legacy success cache entry is not replayed to satisfy this header.
The header is permitted by CORS without expanding origin/credential policy.
Header-free legacy writes/replays keep their prior behavior and envelopes.

This is server-side **negative negotiation infrastructure**, not implementation
of any durable receipt version or a mobile handshake. No existing client is
switched onto it. Future clients must discover an explicitly supported known
version before transmitting strict operations: old servers may ignore unknown
headers and commit without receipts. A ping, successful login, cached
capability, old replay, or mixed-server rollout is not adequate compatible
receipt proof. The 412 describes this attempt's unsupported precondition, not
a terminal business disposition of this operation or proof that earlier
attempts did not commit. Preserve all unresolved work; never use these flags
or responses to authorize settlement, cleanup, retry or logout.

No receipt schema, lifetime/compaction, conflict arbitration, mobile settlement,
revocation exception, owner signing or production deployment is enabled here.
Those contracts and their positive end-to-end acceptance tests remain required.

## Mobile non-settlement response guard

ApiResponse preserves typed `nonSettlement` evidence from the response root,
normalized data, or the known `lookup`/`capabilities` protocol containers.
Presence of `authorizes_settlement` (including true or malformed values),
`durable_receipt_versions`, or authority `legacy_replay_cache` is not validated
operation receipt proof. Arbitrary member/record payloads are not recursively
searched; ordinary `durable:false` alone does not change legacy behavior.
Generation/refresh response copies retain the evidence. The unsupported receipt
contract code is independently recognized regardless of HTTP success/status.

The existing outbox policy holds this evidence as needs-attention, never as
accepted, successful replay or resolved conflict, and does not consume five
ordinary unknown retries. Session supersession and definitive authentication/
scope outcomes retain their precedence. A transient refresh result cannot turn
non-settlement evidence into retry authority. The attempt ledger describes held
observations as protocol errors/user action required, not successful execution.
Saved payloads remain owned by their exact operation and generation; newer
local edits are not erased. Recovery/cleanup permissions are unchanged.

The worker explains that server confirmation is incomplete and saved work is
kept. Passes containing needs-attention work no longer promise automatic retry;
they direct the user to Sync Recovery, which retains its confirmation-required
attendance deletion guard. No manual acknowledgement/discard is enabled.

This is a negative-evidence guard, not positive receipt validation: marker-free
legacy upload acceptance remains unchanged. No new endpoint call, strict upload
header, capability handshake, durable receipt version, schema migration,
release activation or production deployment is introduced. Exact manifests,
long-lived atomic receipt storage, positive receipt validation and full safe
reconciliation remain release blockers. Fake transport plus real coordinator/
SQLite regressions prove this boundary, not installed-phone crash recovery.
