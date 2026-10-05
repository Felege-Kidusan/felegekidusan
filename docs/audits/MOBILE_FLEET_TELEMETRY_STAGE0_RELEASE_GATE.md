# Mobile Fleet Telemetry & Analytics — Stage 0 Release Gate

**Status:** Implemented as a read-only preflight and controlled staging runbook.
**Date:** 2026-10-06
**Scope:** Verify the end-to-end telemetry, integrity-hardened ingestion, and server-observed sync path before production sign-off.

This gate does **not** change mobile sync behavior, API routes, admin authorization, or dashboard boundaries. The deployment under test may include the additive telemetry-integrity migration `060`; the gate itself is read-only and creates no fake telemetry or synthetic monitor rows.

## Evidence labels

| Label | Meaning |
|---|---|
| `VERIFIED` | Demonstrated by source, SQL metadata, a test, or a controlled staging action. |
| `INFERRED` | Strongly supported by source inspection but not executed in the target environment. |
| `UNKNOWN` | Requires staging or production evidence and must not be treated as a pass. |

## Current status

- Repository preflight SQL: `VERIFIED` by source contract tests after implementation.
- Staging schema state: `UNKNOWN` — no authorized staging database is available in the current workspace.
- Live telemetry traffic: `UNKNOWN`.
- Browser/device flow: `UNKNOWN`.
- Production sign-off: **BLOCKED until this gate is executed in staging.**

## 1. Preconditions

1. Use a staging database or an explicitly authorized controlled environment.
2. Deploy the application commit under test, including the current telemetry and sync-monitor code.
3. Have one authorized mobile test account and one real test device or emulator.
4. Take a database backup or snapshot according to the existing deployment policy.
5. Do not use production credentials in command history or release artifacts.
6. Do not insert fabricated rows to make the dashboard appear populated.

The analytics baseline commit is:

```text
7676303f56d77d3c2ba03b92030981ee6eee4fff
```

The integrity-hardening commit under test must be recorded in the release evidence after it is pushed.

## 2. Read-only schema preflight

Run the new preflight against the intended staging database after the code and migrations are deployed:

```bash
mariadb --defaults-extra-file=/secure/staging.cnf staging_database \
  < sql/preflight/mobile_fleet_telemetry_preflight.sql \
  | tee /secure/release-evidence/mobile-fleet-telemetry-preflight.txt
```

The preflight is:

- read-only for application data;
- limited to `information_schema`, aggregate metadata, and one temporary table;
- safe to run repeatedly;
- deterministic;
- blocking when required tables, columns, engines, or indexes are missing or malformed.

It verifies migrations:

- `051_app_telemetry.sql`
- `059_api_sync_attempts.sql`
- `060_telemetry_integrity_hardening.sql`

It checks:

- all four required tables;
- InnoDB engines;
- all columns consumed by the mobile route, admin service, and monitor;
- primary and secondary index names, uniqueness, and column order;
- aggregate table sizing evidence.

A `BLOCK` row is a release stop. Do not apply unrelated repairs from this script, and do not convert the preflight into an automatic migration runner.

## 3. Controlled real-flow drill

Use the real staging application and controlled account. Record only timestamps, HTTP status, aggregate counts, event types, and monitor status. Do not record payloads, tokens, member data, full operation IDs, or crash bodies.

| Step | Controlled action | Expected evidence |
|---|---|---|
| A | Install/open the test APK | `/api/v1/telemetry/heartbeat` succeeds; one installation becomes visible; device metadata is present. |
| B | Reopen within 15 minutes | No new launch heartbeat is expected because the client throttle is active. |
| C | Reopen after the throttle window | A new launch heartbeat is accepted and `last_seen_at` advances. |
| D | Perform one successful authenticated sync | A server-observed `api_sync_attempts` row reaches `completed` or `replayed`; the admin monitor displays it. |
| E | Perform one approved staging failure/retry scenario | The monitor records the actual failed/retryable outcome; no fabricated row is added. |
| F | If feasible, hold one real request open beyond the stale threshold | The row remains conservatively in flight and is displayed as stale by the admin query. |
| G | Download an approved staging APK update | The download is hash-verified by the client and an `update_downloaded` event is recorded. |
| H | Exercise the existing native-crash reporting path only if an approved device drill exists | The crash event is bounded and does not contain account/member data. Do not manufacture a crash in production. |

### Important expected limitation

The current mobile sync event is `sync_pass_completed`, containing count-only data. The telemetry ingestion patch now translates its reviewed `succeeded`, `waiting_retry`, and `needs_attention` counts into the legacy installation counters with a per-request cap. Confirm this translation against one real staging pass; it is still not evidence that the server-observed monitor failed.

## 4. Raw-row and API reconciliation

After the controlled actions, compare the following without exporting sensitive payloads:

1. Mobile HTTP response status and timestamp.
2. Aggregate table counts and newest timestamps.
3. Admin API response status and pagination totals.
4. Dashboard visible counts and monitor status.
5. Server logs using request correlation IDs where available.

For the authenticated monitor, record the responses from the existing read-only
admin actions `get_sync_overview`, `get_sync_attempts`, and `get_sync_attempt`.
These actions must remain GET-only and must not be replaced with direct database
writes or synthetic monitor rows.

The following must agree:

- a real launch produces an installation heartbeat and a corresponding event record;
- a real authenticated idempotent sync produces a monitor observation;
- a monitor replay is not misreported as a new successful business operation;
- a failed/retryable response is not rendered as completed;
- pending local work is not rendered as server-observed zero or success;
- API/database failures are visible as an error state rather than an empty successful dashboard.

## 5. Dashboard control matrix

Run each control with a known controlled data set and record the API response totals. Do not rely only on visual appearance.

| Control | Required check |
|---|---|
| Today / 24 hours | Headline values and device list must use a documented, consistent scope. |
| Seven days | Active counts, distributions, and table must not mix filtered and all-time cohorts without labeling. |
| Thirty days | Same consistency check. |
| All time | Must not silently behave like an unbounded lifetime-counter report while other panels remain filtered. |
| Version filter | Headline totals, distributions, counters, and device list must agree on whether the filter is applied. |
| Device search | Search must preserve pagination totals and return only matching installations. |
| Sync status/domain/source | Monitor rows and overview counts must use the same selected range and filters. |
| Pagination | Page totals, ordering, and detail rows must remain stable while navigating. |

A mismatch is a gate failure, not a dashboard cosmetic issue.

## 6. Authorization and privacy checks

With an unauthenticated browser/session:

- legacy admin actions return unauthorized;
- sync-monitor actions return unauthorized;
- no telemetry rows or monitor details are exposed.

With an authorized `school_admin` and `super_admin` account:

- confirm the intended role scope;
- confirm raw event data exposure through `get_events` is intentional;
- confirm monitor detail exposure of user IDs and safe entity references is intentional;
- confirm no request body, token, password, note, lyric, or member row appears in monitor output.

## 7. Gate decision

### Pass only if all are true

- Schema preflight returns no `BLOCK` rows.
- A real launch is accepted and visible through the expected path.
- A real sync is observed by `api_sync_attempts`.
- Failure/retry behavior is represented correctly.
- Admin authorization behaves correctly.
- Dashboard API and rendering agree on the controlled results.
- Filter inconsistencies are either fixed or explicitly accepted by product ownership.
- No sensitive data appears in release evidence.

### Block if any are true

- `051` or `059` is absent or incomplete.
- Telemetry returns 500/503 for a valid controlled event.
- The monitor table is missing or does not record a real sync.
- The dashboard displays empty success for an API/database failure.
- A filter produces mixed-scope or contradictory numbers.
- An unauthorized account can read telemetry or monitor data.
- Release evidence contains payloads, tokens, member data, or secrets.

## 8. Current implementation and next gate

The analytics-correctness patch implements the source-level scope and count changes:

1. legacy dashboard filters use one installation cohort scope;
2. lifetime counters are labeled as lifetime counters for that cohort;
3. the old aggregate sync percentage returns `null` when no counters exist;
4. `sync_pass_completed` counts are translated into legacy success/failure counters with a hard per-request cap;
5. the device directory receives the same selected range as the overview.

The integrity-hardening patch adds a separate trust-boundary layer:

1. crash reports use a hash-only identity and a persisted client marker;
2. duplicate crash delivery is transactionally idempotent through migration `060`;
3. telemetry events, counts, installation IDs, and request bodies are bounded and typed;
4. IP hashes use an HMAC secret when configured;
5. the existing sync monitor and mobile sync behavior remain unchanged.

The next required evidence is still staging execution: apply `060`, verify the query scopes, count translation, crash deduplication, malformed-input rejection, monitor reconciliation, and the 90-day retention job against real controlled rows. Use `docs/audits/MOBILE_FLEET_TELEMETRY_STAGE1_STAGING_EVIDENCE.md` and the read-only `sql/preflight/mobile_fleet_telemetry_postdrill.sql` report. The retention job is documented separately in `docs/audits/MOBILE_FLEET_TELEMETRY_RETENTION.md`; cron installation and execution must be recorded before production sign-off. No production sign-off is implied by static verification.
