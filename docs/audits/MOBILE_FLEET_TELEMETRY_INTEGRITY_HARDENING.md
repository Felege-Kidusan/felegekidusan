# Mobile Fleet Telemetry — Integrity Hardening

**Scope:** first hardening slice after the analytics-correctness patch

**Baseline:** `7676303f56d77d3c2ba03b92030981ee6eee4fff`

**Evidence status:** static source and contract review only in this workspace.
PHP, Dart, MariaDB, staging traffic, and production behavior remain
`NOT VERIFIED` unless an execution record is added below.

## 1. Objective

Prevent the telemetry boundary from turning local diagnostic material into
unbounded or misleading fleet data.

This slice addresses:

- repeated counting of the same native crash on every app launch;
- raw crash/error text being retained as durable telemetry payloads;
- arbitrary public event names becoming durable event types;
- unbounded request bodies and weak installation-id validation;
- one installation evading the IP bucket by changing networks;
- predictable date-only IP hashes;
- duplicate crash delivery after an ambiguous network response.

## 2. Current behavior and root causes

`runBootstrap()` reads the most recent native crash from the shared local log
on every launch and previously sent the full body through `recordCrash()`.
The server incremented `crash_count` for every accepted `crash_recorded` event.
There was no stable crash identity, so the same local log entry could become
multiple fleet events.

The public route accepted any `event_type`, accepted either an array or an
arbitrary string as `event_data`, coerced malformed numeric fields, and only
bounded the stored string after the request body had already been read.

These conclusions are `VERIFIED` by source inspection. Runtime frequency and
production impact are `NOT VERIFIED`.

## 3. Implemented contract

### 3.1 Device-side crash identity

`CrashLogEntry.reportKey` is SHA-256 over the local entry header and body.
Only the 64-character hexadecimal key and a controlled kind (`native` or
`dart`) are eligible for telemetry.

The raw diagnostic body remains available to the local Diagnostics screen and
is not included in the current crash event payload.

`TelemetryService` stores the last successfully accepted crash key in
`SharedPreferences`. A key is written only after the server returns HTTP 200.
A failed request therefore remains eligible for retry.

For rollout compatibility, the server accepts a bounded legacy `summary`
field from older clients but converts it to `legacy_summary_present: true` and
never stores the text. New clients do not send that field.

### 3.2 Server-side crash idempotency

Migration `060_telemetry_integrity_hardening.sql` adds nullable `dedupe_key` to
`app_telemetry_events` and a unique key over:

```text
installation_id, event_type, dedupe_key
```

Normal telemetry keeps `dedupe_key = NULL`, so ordinary event rows remain
append-only. Crash events use their SHA-256 key.

The route inserts the event and updates the installation aggregate in one
transaction. A duplicate crash-key insert is treated as an accepted no-op and
does not increment `crash_count`, including when the first response was lost
and the client retries.

Counter semantics (interpretation note): `crash_count` and the "Legacy Crash
Counters" KPI count **process-level failures** — every `crash_recorded` event
the server accepts — not only user-facing UI crashes. A background-sync
infrastructure failure that escapes `BackgroundSyncReceiver`'s own guards is
also a process-level failure and lands in these counters; the receiver catches
its own `Throwable` precisely so the *guarded* path does not. Read the KPI as
"the process died/failed", never as "the user saw a crash".

### Crash signatures (readable WHERE, privacy-preserving)

The crash key is deliberately opaque, which also means the fleet dashboard
cannot say where a crash happened. The `crash_signature` event is the bounded
companion: the app derives, from the same local log entry, the exception
class plus up to three **first-party** frame names (Dart frames under
`package:fkss_app/`, native frames under `com.arkeonethiopia`) as
`file.ext:line symbol` strings — nothing else.

Contract (enforced on both sides, pinned by test):

- `crash_key`: the same SHA-256 report key as the crash event, used as the
  event `dedupe_key`, so delivery is exact-once per installation per crash
  identity — the same chain, the same guarantees, including the
  "server committed, response lost" retry case.
- `signature_class` and each frame: allow-listed charset
  `[A-Za-z0-9 .:_/<>()$#-]`, at most 120 characters each; at most 3 frames;
  total `event_data` JSON at most 512 bytes (frames are dropped from the
  end if the budget would be exceeded).
- Never included: exception messages, argument values, variable values,
  absolute paths, user data. The class is the exception *type*, not its
  message. An unknown charset or oversized part is rejected with 422.
- The client sends the signature ungated (no local marker): the server-side
  exact-once key makes repeats accepted no-ops, and signatures therefore
  also arrive for crash keys first reported by older app builds.
- Server side, `app_crash_signatures` (migration 063) keeps one row per
  crash key: the signature text plus durable lifetime aggregates
  (`total_events`, first/last seen, last reporting version/build) that
  survive the 90-day event retention. Signature bookkeeping is
  best-effort inside the telemetry transaction — it can never fail the
  event insert — and occurrence/affected-installation counts are computed
  at read time (indexed by `event_type, dedupe_key, installation_id`),
  because a distinct-count cannot be maintained correctly by upsert.

Migration ordering:

```text
051_app_telemetry.sql
059_api_sync_attempts.sql
060_telemetry_integrity_hardening.sql
```

The new migration must be applied before deploying the corresponding route.

### 3.3 Public ingestion boundary

The route now:

- reads at most 8 KiB for a telemetry request body;
- requires a canonical UUID installation ID;
- rejects unsupported event types;
- accepts typed event objects only;
- rejects unknown event fields;
- validates count fields as bounded non-negative integers;
- normalizes bounded legacy crash summaries without retaining their text;
- validates current crash keys as SHA-256 hex and crash kind as `native` or `dart`;
- validates update and legacy sync payload fields;
- applies an additional 60-per-minute installation bucket alongside the
  existing 120-per-minute IP bucket;
- uses `TELEMETRY_HASH_SECRET` when configured, with the existing JWT secret
  as a compatibility fallback, for rotating HMAC IP hashes.

The supported event names remain compatible with the current producer and
reviewed legacy names:

```text
event
launch
heartbeat
sync_success
sync_completed
sync_failed
sync_error
sync_pass_completed
crash
crash_recorded
update_downloaded
```

`sync_pass_completed` retains its count-only contract and the 100,000-per-field
cap. Its `succeeded`, `waiting_retry`, and `needs_attention` values still feed
the legacy installation counters exactly as in the analytics patch.

## 4. Preserved boundaries

The patch does not change:

- mobile sync execution, retry, ownership, or account isolation;
- the `api_sync_attempts` server-observability boundary;
- API route paths or HTTP method routing;
- installation IDs generated by the mobile client;
- Fleet Analytics authorization or the integrated sync-monitor surface;
- download routing;
- the preserved mode-only artifact at
  `scripts/restore_production_dump.sh`.

## 5. Static verification plan

The source contract tests cover:

- hash-only crash payloads;
- post-success crash marker persistence;
- rejection of raw summaries and untyped event data;
- event allowlisting and installation rate limiting;
- request body size enforcement;
- HMAC IP hashing;
- transactional duplicate-crash handling;
- rerunnable migration and nullable dedupe behavior.

Required runtime verification in an authorized staging environment:

1. Apply the three migrations in order.
2. Run the read-only fleet preflight.
3. Launch a controlled device with one real native crash log.
4. Confirm exactly one crash event and one crash-counter increment.
5. Reopen the app repeatedly and confirm no additional increment.
6. Force a response-loss/retry scenario if the staging operator can do so safely.
7. Confirm the duplicate event is accepted without another counter increment.
8. Send a malformed event, oversized request, invalid UUID, and raw summary;
   confirm the expected 413/422 responses and no database rows.
9. Run the existing real launch, sync, retry, raw reconciliation, filter, and
   sync-monitor checks from the Stage 0 runbook.

No fabricated telemetry or monitoring rows are authorized for this drill.

## 6. Remaining risks

- The PHP route and migration have not been executed locally because PHP and
  MariaDB are unavailable.
- Flutter analyzer/test execution is unavailable because the Flutter/Dart
  toolchain is unavailable in this workspace.
- Existing production rows have no dedupe key and are not backfilled. The new
  key prevents duplicates for newly hardened crash events only; legacy summary
  events remain compatible but are not exactly-once deduplicated.
- The fallback to `JWT_SECRET` is retained for deployments that have not yet
  added `TELEMETRY_HASH_SECRET`; staging should configure the dedicated secret
  before production rollout.
- Retention is now implemented as a separate CLI-only phase in
  `admin/backend/mobile_telemetry_retention.php`; cron installation and runtime
  execution remain unverified.
