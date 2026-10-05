# Mobile Fleet Telemetry — Stage 1 Staging Evidence Phase

**Purpose:** execute and record the first authorized end-to-end validation of the
analytics, ingestion, crash-dedupe, sync-monitor, and retention contracts.

**Current status:** `BLOCKED — REQUIRED STAGING EVIDENCE MISSING`

This document is an operator checklist. It does not authorize production data
changes, create fake telemetry, or replace the Stage 0 preflight.

## 1. Evidence rules

Use only:

- an authorized staging/disposable database;
- one controlled staging account;
- one real test device or explicitly authorized emulator;
- real launch, sync, retry, and approved diagnostic flows.

Record only:

- timestamps;
- HTTP status codes;
- event type counts;
- aggregate database counts;
- dashboard/API totals;
- monitor lifecycle statuses;
- retention job aggregate output.

Do not record or export:

- request bodies;
- crash bodies or stack traces;
- installation IDs;
- request IDs or client operation IDs;
- member/user data;
- tokens, passwords, or secrets.

## 2. Preconditions

1. Confirm the deployed commit under test and record it in the evidence file.
2. Apply migrations in order:

   ```text
   051_app_telemetry.sql
   059_api_sync_attempts.sql
   060_telemetry_integrity_hardening.sql
   ```

3. Run the read-only Stage 0 preflight:

   ```bash
   mariadb --defaults-extra-file=/secure/staging.cnf staging_database \
     < sql/preflight/mobile_fleet_telemetry_preflight.sql \
     | tee /secure/release-evidence/mobile-fleet-telemetry-preflight.txt
   ```

4. Install/configure the retention cron only against staging first.
5. Confirm `TELEMETRY_HASH_SECRET` is configured in the staging secrets file.
6. Take a staging database snapshot according to the deployment policy.

## 3. Real mobile/API drill

Record timestamps and response statuses only.

| ID | Real action | Required observation |
|---|---|---|
| L1 | Cold launch the controlled app | Valid launch telemetry is accepted. |
| L2 | Reopen within the client throttle window | No extra launch event is expected during throttling. |
| L3 | Reopen after the throttle window | A later launch is accepted and `last_seen_at` advances. |
| S1 | Complete one real authenticated sync | `api_sync_attempts` reaches `completed` or `replayed`. |
| S2 | Exercise one approved retryable/failure flow | Monitor status reflects the actual retry/failure lifecycle. |
| C1 | Use an approved controlled crash diagnostic flow, if available | One hash-keyed crash event and one crash-counter increment. |
| C2 | Reopen the app repeatedly after C1 | No additional increment for the same crash key. |
| U1 | If applicable, complete one approved update download | One bounded `update_downloaded` event. |

If an approved crash flow is unavailable, mark C1/C2 `UNKNOWN`; do not
manufacture a crash solely to pass the gate.

## 4. Malformed-input checks

From an authorized staging client or controlled HTTP tool, send requests that
contain no real member or account data:

- oversized body over 8 KiB;
- invalid installation ID;
- unsupported event type;
- non-object `event_data`;
- unknown event field;
- crash payload with an invalid key;
- count greater than 100,000.

Expected outcomes:

```text
413 for an oversized request
422 for malformed or unsupported telemetry input
0 new application/monitor rows from rejected requests
```

Do not use production credentials or retain the test payloads in evidence.

## 5. Retention execution

Run the retention job manually once before relying on cron:

```bash
php admin/backend/mobile_telemetry_retention.php \
  | tee /secure/release-evidence/mobile-telemetry-retention.txt
```

Then verify the schedule and run again after the configured cron interval.
The output must be aggregate-only and contain:

```text
lock_acquired
retention_days
batch_size
max_batches_per_table
deleted_events
deleted_downloads
backlog_possible
```

No `api_sync_attempts` rows may be deleted by this job.

## 6. Post-drill reconciliation

Run the read-only reconciliation report:

```bash
mariadb --defaults-extra-file=/secure/staging.cnf staging_database \
  < sql/preflight/mobile_fleet_telemetry_postdrill.sql \
  | tee /secure/release-evidence/mobile-fleet-telemetry-postdrill.txt
```

Required outcomes:

- no `BLOCK` schema rows;
- zero duplicate current crash-key groups;
- current-client drill rows contain no raw summary/error fields;
- retention backlog is zero for the controlled staging fixture, or the
  documented bounded backlog is resolved by another scheduled run;
- monitor aggregate counts reconcile with S1/S2;
- no sensitive values appear in the evidence file.

## 7. Dashboard/API reconciliation

Using authorized admin roles only, compare the same controlled observations
through:

```text
get_overview
get_installations
get_events
get_sync_overview
get_sync_attempts
get_sync_attempt
```

Confirm:

- selected range and device directory share one cohort scope;
- lifetime counters remain labeled as lifetime counters;
- crash count reflects one current crash key only;
- sync monitor is the authoritative server-observed sync surface;
- retryable/pending states are not rendered as successful completion;
- rejected telemetry does not appear as an accepted event;
- pagination and filters remain consistent.

## 8. Gate decision

### Pass only when

- Stage 0 preflight has no `BLOCK` rows;
- L1/L2/L3 are reconciled;
- S1/S2 reconcile across raw aggregate data, API, and dashboard;
- C1/C2 pass or are explicitly accepted as `UNKNOWN` by release ownership;
- malformed inputs are rejected without database rows;
- retention executes with bounded aggregate output;
- post-drill report has no blocking checks;
- evidence contains no sensitive data.

### Block when

- a migration or index is missing;
- a valid event returns 500/503;
- duplicate crash keys increment the counter;
- a rejected payload is stored;
- monitor state contradicts the real sync result;
- the retention job touches a non-telemetry table;
- the dashboard mixes filtered and lifetime scopes;
- evidence contains payloads, identifiers, credentials, or member data.

## 9. Evidence record template

```text
Commit under test:
Staging database identifier (non-secret alias):
Staging drill start UTC:
Stage 0 preflight result:
Retention manual run result:
Cron verification timestamp:
L1 result:
L2 result:
L3 result:
S1 result:
S2 result:
C1 result:
C2 result:
Malformed-input result:
Post-drill blocking_checks:
Dashboard/API reconciliation result:
Sensitive-data review:
Release owner:
Decision: PASS / BLOCKED / UNKNOWN
```

Until this record is completed from an authorized staging run, production
sign-off remains blocked.
