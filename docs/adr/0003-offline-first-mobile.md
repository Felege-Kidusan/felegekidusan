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
they direct the user to Sync Center, which retains its confirmation-required
attendance deletion guard. No manual acknowledgement/discard is enabled.

This is a negative-evidence guard, not positive receipt validation: marker-free
legacy upload acceptance remains unchanged. No new endpoint call, strict upload
header, capability handshake, durable receipt version, schema migration,
release activation or production deployment is introduced. Exact manifests,
long-lived atomic receipt storage, positive receipt validation and full safe
reconciliation remain release blockers. Fake transport plus real coordinator/
SQLite regressions prove this boundary, not installed-phone crash recovery.

## Exact attendance request-manifest codec (internal, not activated)

`AttendanceOperationManifest` in PHP and Dart is a pure, versioned identity
building block. Neither existing uploads nor outbox workers call it. Strict
receipt versions remain unsupported; the diagnostic cache and legacy request
hash are unchanged. A fingerprint is NOT acceptance, retry authority, a roster
snapshot, authorization, encryption, a durable receipt or permission to settle,
clean up, switch accounts or log out.

The canonical input has exactly `owner_user_id`, `client_op_id`, `packet_kind`,
`class_id`, `academic_year_id`, `date`, and `records`. Each record has exactly
`member_id`, `status`, `notes`. Extra or missing fields, including the ambiguous
legacy `note` alias, fail closed. Packet kind is exactly `draft` or `submit`;
a future legacy adapter must explicitly map `submitted`, not guess it. IDs are
actual positive integers at most 9007199254740991, not strings, booleans or
floats. Operation keys are 1–80 ASCII letters/digits/period/underscore/hyphen,
without trimming. Date is exact Gregorian YYYY-MM-DD, years 0001–9999. Original
class, year and date are mandatory, never today's replacements. Existing legacy
claim snapshots lack original academic-year provenance: they cannot be silently
upgraded to this contract. Capturing that provenance atomically in the existing
local intent remains separate future work, not a second queue.

Records number 1–2000, with unique member IDs and exact lowercase status
`present`, `absent`, `late`, or `excused`. Notes are valid Unicode strings of at
most 500 scalar values. Invalid UTF-8 and unpaired UTF-16 surrogates are rejected,
not replaced. No trimming, newline conversion or Unicode normalization occurs;
NUL, CRLF, non-BMP text and distinct combining forms retain their exact identity.
These are internal codec constraints aligned with the current attendance
service's limits, NOT changes to its legacy coercion/normalization rules.
Partial drafts may be hashed; submit completeness and date/roster/permission
rules are still the authoritative server's responsibility.

Each frame is the UTF-8 netstring `ASCII decimal byte length:bytes,`, with no
leading zeros. Records are sorted by NUMERIC member ID without changing the
caller's input. All integers are canonical unsigned decimal; hashes are
lowercase hexadecimal SHA-256. The exact frame sequences are:

1. Records: `ssms.attendance.records.v1`, record count, then member ID, exact
   status, exact notes for each sorted record.
2. Manifest: `ssms.attendance.manifest.v1`, owner ID, operation key, packet kind,
   original class ID, original academic-year ID, original date, record count,
   records SHA-256 (64 lowercase ASCII hex characters, not 32 binary bytes).

Hashing is incremental, without assembling a full encoded preimage. Validation
and sorting are bounded to 2000 records / 500 note scalars per record (at most
4,000,000 note UTF-8 bytes). There is no I/O or clock/session access. JSON/map
field ordering, retries, connection headers, current authority and mutable
attempt metadata do not enter the digest. Such metadata must never become
unhashed accepted request fields; a changed semantic contract needs a new
version/domain separator, not silent extension of v1.

The result is `manifest_version: 1`, `domain: attendance`, original owner/key/
kind/class/year/date, `record_count`, `records_sha256`, `manifest_sha256`. It
contains no raw roster, marks or notes, nor a positive settlement flag. Dart
returns an immutable map of scalar values; PHP returns a value array. Context
and digests remain sensitive metadata: hashing is not anonymization and does
not permit public logging/disclosure.

This binds the ORIGINAL canonical request DTO, not normalized business rows.
Future server integration must bind that original identity before legacy note
trimming or other permitted effect normalization; never recompute the original
request fingerprint from normalized rows to manufacture a match. Receipt
storage, atomic terminal outcome, retention, normalized-effects evidence,
authoritative conflict handling and positive client validation are NOT supplied
here. Owner ID in this pure DTO is not trusted authentication: a future handler
must bind it to the signed current owner and original operation, and preserve
all unresolved legacy work whose provenance cannot be proven.

Shared fixtures contain independently Python-generated expected netstring bytes
and SHA-256 vectors, executed against both real codecs. They include numeric
ordering, draft/submit, Unicode/delimiters, bounds and a full 2000-record / 500
non-BMP-scalar notes case. Malformed/missing context and records fail closed.
Synthetic unit and backend tests are not deployed-schema, phone, cold-restart,
receipt-lifecycle or production acceptance evidence.

## Original attendance year storage (local schema 38; producer not activated)

`pending_attendance.original_academic_year_id` is nullable INTEGER metadata on
existing operation rows, not a second outbox. The repeat-safe migration runs
on create and upgrade, never backfills a year or rewrites/deletes old work.
Unexpected existing column shape or SQL failure propagates; it cannot trigger
a database replacement or authorize a reset. Old unknown provenance stays NULL.
As with other forward schema changes, do not downgrade an installed database
or restore an older copy to make an older app open it.

`saveAttendanceLocal` accepts an optional explicit `originalAcademicYearId`.
A supplied value must be an actual positive integer within the manifest's
portable exact-ID bound. Metadata and marks commit in the existing replacement
transaction under the original owner/session fence. Never-attempted generations
may coalesce only with the SAME original year, including NULL-to-NULL. Different
known years, or known/unknown provenance, retain the complete older generation.
A mixed-provenance sibling set is never partially deleted. Existing protections
for attempted, held and in-flight work remain unchanged.

The immutable operation reference exposes the original year; a claimed packet
exposes it only if every captured row agrees on a valid integer. Empty, missing,
invalid or mixed provenance is unknown, never selected from the first row or
coerced from a string. This projection does not change legacy claim/upload
eligibility and is not authorization or receipt evidence. Undo/retry retain the
stored provenance; later edits cannot change an already captured snapshot.

IMPORTANT: this package does not supply a year from the current UI, login,
calendar, cache or HTTP response. GET attendance's existing `roster_year_id`
can be a PREVIOUS-year roster fallback; it is not proven original WRITE scope.
No existing producer passes the new optional argument. Therefore existing user
workflows continue to store unknown provenance, not a manufactured strict-ready
operation. Trusted server-attested write-scope capture and complete durable
prepared snapshots, including protection against relabeling pre-refresh edits,
remain a separate integration step. Keep unknown legacy work recoverable.

No wire body/header, server schema/API, manifest construction, strict receipt
support, positive settlement, account/logout permission, cleanup override or
production deployment is activated. Tests execute the real migration and
LocalDb with synthetic SQLite data, including actual minimal-v37 open/upgrade,
rollback, committed-state reading, different-year preservation and immutable
claims. These do not establish deployed-schema or installed-phone acceptance.


## Server write-context discovery (development; no mobile activation)

The additive [API discovery contract](../API.md#attendance-write-context-discovery-development)
separates the server write year from `roster_year_id`. The read requires current
server scope even for legacy builds, the attendance role and current class
assignment; owner/version cannot be supplied by a client. It returns minimal
point-in-time owner/version/class/year/date dimensions and explicit false flags
for offline work and settlement. It creates neither a lease nor a complete
prepared snapshot. It performs no roster query, write or operation claim.

The discovery response is NOT yet an original operation context. A future
producer must bind a complete consistent authorized snapshot to its owner,
version, class, date and write year BEFORE editing; store the captured original
year atomically with the marks; and retain that original on retry, rollover,
roster refresh, navigation and authorization changes. Re-fetching discovery must
never relabel earlier saved work. Unknown legacy years remain unknown. Current
authority/calendar/workflow must be checked again at upload; historical scope
reconciliation requires server rules and durable exact receipts, not client
substitution of today's year. Rolling 30-day offline authorization, complete
preparation, revocation and account/logout rules are not implemented by this read.

Synthetic route tests execute the real adapter with boundary doubles. Separate
isolated HTTP tests exercise actual authorization/class checks, including real
assignment-version invalidation and a previous-roster-year fallback. These are
not hosted deployment, phone, server-race or offline acceptance evidence.

## Atomic shared roster-cache refresh (preparation prerequisite only)

`LocalDb.cacheStudents` captures incoming cache rows before its first await and
replaces one class's roster in a single SQLite transaction. The delete and all
inserts either commit together or roll back together; a late SQL/duplicate/input
failure must not erase the previous complete roster. Successful empty responses
retain their existing meaning and replace only that class. The legacy ID alias,
field defaults and all three established callers remain unchanged.

This fixes cache-integrity failure, not authorized preparation: rows are not a
lease, scope-bound snapshot, original-year proof, complete roster-plus-sheet
commit or offline-readiness grant. Server consistency and atomic owner/context/
calendar/sheet binding remain required before new offline activation. Synthetic
real-SQLite tests cover rollback and an independent committed-state reader, not
process-death or owner-phone acceptance. No schema or wire contract changes.

## Atomic attendance read-cache pair and durable session fence

Online attendance refresh now calls `LocalDb.cacheAttendanceSnapshot` instead
of independently committing its roster and class/day sheet. The new method
captures JSON and roster scalars before its first await, then checks the
captured owner, authorization version, runtime generation and active durable
session inside the same SQLite transaction that replaces both existing caches.
A failed roster or sheet write rolls both back. Other classes/dates and pending
attendance are not deleted. Old standalone cache APIs remain for compatibility.

The screen reports cache-storage refusal without clearing edits or pending work.
A durable-session refusal blocks further editing through the existing access
gate; a superseded callback cannot update a newer view. This is not a new server
snapshot, lease, original-year producer, receipt or offline-readiness grant.
Legacy UI publication timing and other consumers' independent cache writes do
not establish complete authorized preparation. Server-consistent datasets and
atomic calendar/context/readiness publication remain separate work.

## Strict minimal preparation roster reads (opt-in primitives)

`EnrollmentService.resolvePreparationRosterYear` preserves the existing
preferred-year / unscoped / rollover-fallback rule but throws on query failure
instead of interpreting it as zero. Its preferred write year remains separate
from the resolved roster year. `fetchPreparationRoster` reads only the member
identity, student/father names and member code used by attendance UI/QR, under
that explicit server-resolved scope and date. Invalid, orphaned or duplicate
identities fail rather than silently produce an apparently complete roster.

The opt-in strict argument to `DepartmentStatusService.exclusionSql` requires
an explicit valid date and probes all eligibility columns when attendance v2
is enabled. It does not trust the legacy permissive availability cache. Feature
OFF remains OFF; all existing callers retain the old default behavior. Both
mysqli reporting modes fail closed in the new read primitives, with no slim
query or error-to-empty fallback. A genuinely empty successful query is valid.

These helpers do not authenticate, grant offline work, own/commit transactions,
or activate a route. Their future preparation caller must bind current authority,
class access, write year, strict calendar and workflow facts inside one consistent
read transaction. Server snapshot publication and atomic mobile calendar/context/
readiness binding remain unfinished. No schema, lease or policy activation.
