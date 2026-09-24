# Build 24 authorization and outbox rollout runbook

**Scope:** consolidated Risks #1, #9, #8, and #10

**Mobile release:** `1.5.0+24`

**Database:** MariaDB 10.6+, server migrations `009`, `010`, `044`, `045`, `046`, `048`; mobile SQLite schema v34

**Rule:** stage, observe, and stop on any failed gate. This document does not authorize deployment by itself.

## 1. Non-negotiable safety properties

- Build 23 remains usable during the compatibility window. Build 24 receives live authorization-scope enforcement.
- Never enable global old-client enforcement before build 24 is the enforced minimum and update-block behavior is verified.
- The emergency switch pauses **outbound background drains only**. It must not delete or mark rows resolved, disable durable SQLite saves, clear owner metadata, hide recovery state, or wipe caches.
- SQLite v34 is forward-only. Never tell a user to reinstall or downgrade to build 23. Reinstall can destroy device-only work, and build 23 may not safely open a v34 database.
- Do not resolve mixed or stale operations by natural key, lexical `MAX(client_op_id)`, or deletion. Exact operation id, owner, scope, eligible state, and runtime generation remain mandatory.
- Observability is aggregate-only. Do not log tokens, PINs, payload JSON, member/name/code data, attendance or grade values, message bodies, or full client operation ids.

## 2. Release source of truth

The release being piloted is **version 1.5.0, build 24**.

1. `Mobile/wbws_flutter_app/pubspec.yaml` is the package release declaration: `1.5.0+24`.
2. `Mobile/wbws_flutter_app/lib/utils/config.dart` mirrors version/build for API headers and update decisions; the test suite pins equality.
3. `api/v1/core/app_release.php` and `api/v1/app_release.example.php` provide build-24-safe defaults/examples.
4. Production `/home/USER/.fkss_app_release.php` is the deployment-owned runtime source. It may advertise build 24 only after the matching APK is uploaded and its SHA-256/size are verified.
5. During pilot and broad rollout, keep `min_build` below 24 and `force_update` false. Raising `latest_build` prompts adoption; raising `min_build` enforces it.

Before publication, compare all four values and verify `/api/v1/app/config` (or the routed `/app/config`) reports the intended `latest_version`, `latest_build`, `min_build`, APK size, SHA-256, and release flags. Download each advertised universal/per-ABI artifact and independently compare SHA-256. Do not publish metadata for an artifact that is not yet present.

## 3. Phase 0 — backup and executable database preflight

### 3.1 Back up before DDL

Use the site's protected MySQL option file or an interactive password prompt; never put a password in shell history or a release log.

```bash
umask 077
mysqldump --defaults-extra-file=/secure/path/mysql.cnf \
  --single-transaction --routines --triggers --events \
  DATABASE_NAME | gzip > "ssms-pre-build24-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
sha256sum ssms-pre-build24-*.sql.gz > ssms-pre-build24.sha256
```

Also take the hosting/provider snapshot and a private live-file backup. Record UTC time, database identity, backup location, checksum, and restore owner. Do not copy secrets into the ticket.

### 3.2 Run the preflight

From the reviewed release checkout:

```bash
mysql --defaults-extra-file=/secure/path/mysql.cnf DATABASE_NAME \
  < sql/preflight/auth_outbox_preflight.sql \
  | tee build24-preflight.txt
```

The script checks prerequisites for all six migrations, detects partial `CREATE TABLE IF NOT EXISTS` objects, validates existing guarded column/index shapes, checks duplicate non-null message tags, displays aggregate DDL sizing, prints current grants, and raises SQLSTATE `45000` if any row is `BLOCK`.

**Manually confirm:** MariaDB 10.6+, enough free disk for table/index rebuilds, a low-traffic DDL window sized from the aggregate table report, and `ALTER`, `CREATE`, `INDEX`, and `TRIGGER` privileges. Treat unexpected live-schema differences or unavailable trigger privileges as stop conditions; do not edit migration SQL ad hoc in production.

### 3.3 Apply in reviewed order

The files are idempotent, but order still matters. Capture exit status and UTC start/end time for each command.

```bash
set -e
for migration in \
  sql/009_api_idempotency.sql \
  sql/010_refresh_token_rotation.sql \
  sql/044_message_edit_delete.sql \
  sql/045_comm_poll_indexes.sql \
  sql/046_message_client_tag.sql \
  sql/048_user_authorization_scope.sql
do
  mysql --defaults-extra-file=/secure/path/mysql.cnf DATABASE_NAME < "$migration"
done
```

Migration 048 must be last: it depends on the hardened teacher-assignment contract and installs authorization-version triggers.

### 3.4 Verify after migration

```bash
mysql --defaults-extra-file=/secure/path/mysql.cnf DATABASE_NAME \
  < sql/preflight/auth_outbox_verify.sql \
  | tee build24-post-migration.txt
```

Every row must be `PASS`. The verifier checks required columns, index order/uniqueness, `client_tag` uniqueness, positive authorization versions, trigger event/timing/table, and InnoDB storage. Preserve the aggregate outputs privately with the release evidence.

On a staging copy, additionally prove behavior rather than trigger presence alone:

1. Record one test user's authorization version.
2. Change role, then active status; each real change must increase the version monotonically.
3. Insert, materially update, and delete one test teacher assignment; the affected teacher version must increase each time.
4. A no-op assignment update must not create an unexplained loop or regression.
5. Restore the test fixture through normal application operations.

Do not run destructive fixture mutations against a real user in production.

## 4. Staged compatibility and enforcement order

### Phase 1 — additive schema only

- Complete §3 and keep the old server code running.
- Confirm ordinary web/mobile traffic remains healthy.
- Do not raise `min_build` and do not expire authorization compatibility.

### Phase 2 — additive server code

- Deploy the reviewed PHP/API code with migrations already present.
- Leave `API_AUTHZ_LEGACY_COMPAT_UNTIL` omitted (the compatibility default) during pilot/broad rollout.
- Verify build 23 login, token refresh, reads, and representative writes.
- Verify `X-App-Build: 23` remains on the temporary token-window behavior.
- Verify `X-App-Build: 24` receives live role/status/assignment revalidation and typed auth/outbox responses.
- Verify build-24 communication sends fail closed/retryably if migration 046 is deliberately absent in staging; they must never fall back to tagless queued sends.

### Phase 3 — controlled build 24 pilot

- Configure `latest_version=1.5.0`, `latest_build=24`, but keep `min_build < 24` and `force_update=false`.
- Publish the matching APK/checksums to a named pilot device group.
- Run every physical-device drill in §7, including both GET-first and POST-first scope changes.
- Monitor only aggregate reason/state counts in §8. Compare sync completion, retry, terminal, auth-refresh, and crash rates to the pre-pilot baseline.
- Stop expansion for any cross-user visibility/replay, unexplained queue loss, authorization bypass, duplicate send, or new failing gate.

### Phase 4 — broader rollout, build 23 still compatible

- Publish build 24 as an optional update and expand in measured cohorts.
- Keep the compatibility deadline open while build 23 remains supported.
- Record adoption by build without user/payload detail. Confirm build 23 can coexist throughout this phase.
- Run smoke drills after each server/release-config change, not only after APK changes.

### Phase 5 — minimum build, then global live enforcement

Use this exact order:

1. Confirm adoption has reached the approved threshold and remaining devices can obtain a verified build-24 APK.
2. Set production release metadata to `min_build=24`; use `force_update` only if separately approved.
3. Fetch `/app/config` from production and verify the minimum-build response.
4. On a build-23 physical device, verify the update gate blocks application use and the download path/checksum works.
5. Reconfirm build 24 login, recovery, local saves, and drains.
6. Only now set `API_AUTHZ_LEGACY_COMPAT_UNTIL` in the private environment file to a past Unix timestamp such as `1`.
7. Verify missing/spoofed old build headers no longer bypass live revalidation.
8. Keep the compatibility adapter code through the observation window. Remove it only in a later reviewed cleanup after adoption and rollback evidence are accepted.

Never reverse steps 2 and 6: global enforcement before a working minimum-build gate can strand build-23 users and defeats the compatibility plan.

## 5. Emergency background-drain containment

The deployment-owned switch is `background_drains_enabled` in `/home/USER/.fkss_app_release.php`. The API publishes it as `features.background_outbox_drain`. Build 24 checks release config at cold start and resume, durably caches the flag, and gates legacy, communication, and hymn claims. Non-outbox services and durable local writes remain available.

### Pause

1. Edit a staged copy of the private release file:

   ```php
   'background_drains_enabled' => false,
   ```

2. Run `php -l` on the staged file, preserve owner/mode (`0600`), and replace it atomically.
3. Fetch `/app/config` without authentication and verify the JSON boolean is exactly false:

   ```text
   features.background_outbox_drain = false
   ```

   A quoted string is invalid and fails closed in the loader; use a real PHP boolean.
4. Cold-start and resume a build-24 pilot phone. Verify no new legacy, communication, or hymn row is claimed/sent after config observation.
5. Save new attendance/grade/message/hymn work offline and online. Verify the local save succeeds, queue counts rise, operation identities remain unchanged, and Sync Center still exposes aggregate recovery state.
6. Verify terminal and pending rows are not deleted, marked sent, or hidden. Verify there is no periodic retry wake-up while paused.

The switch is not an instantaneous network revocation: a request already claimed/in flight when the phone observes the flag may finish and must settle by its exact operation identity. The gate prevents the next claim. Devices that never launch/resume cannot fetch a changed flag, but they also cannot run foreground timers. Record the UTC publication and first-device observation times.

### Resume

1. Correct and validate the underlying issue first.
2. Set the real boolean to true and atomically publish the release file.
3. Verify `/app/config`, then cold-start/resume the pilot cohort.
4. Confirm each durable queue resumes from the same rows/operation ids, communication FIFO holds, and no duplicate delivery appears.
5. Expand only after aggregate retry/terminal/superseded counts stabilize.

Do not use module visibility flags as this switch. Do not clear app data, delete queue rows, force “synced,” lower SQLite version, or disable local save paths.

## 6. Rollback and containment

### Server rollback

Use the least destructive containment in order:

1. Set `API_AUTHZ_LEGACY_COMPAT_UNTIL` back to a future value **only if build 23 is still inside the approved compatibility window**. After build 24 is the enforced minimum, prefer a reviewed server patch rather than reopening unsupported-client bypass indefinitely.
2. Set `background_drains_enabled=false` when outbox transmission is implicated.
3. Leave additive tables, columns, indexes, and SQLite-compatible API fields in place.
4. Revert PHP with an ordinary reviewed revert/deploy; never force-reset production history.
5. Drop authorization triggers only when evidence proves they are the fault and the security owner approves the temporary loss of version bumps.
6. Restore the database backup only for proven data corruption, not for ordinary application rollback.

Old server code ignores additive `users.authorization_version`. Capture post-containment aggregate queue/auth health before changing another variable.

### Mobile rollback — forward-only SQLite v34

**Prohibited:** reinstalling, clearing app data, or downgrading to build 23. These actions can destroy local-only work or make the higher-version database unreadable.

Instead:

- pause drains remotely;
- disable global authorization enforcement server-side if required;
- keep local saves and all queue/recovery rows intact;
- ship build 25 (or later) as a v34-aware corrective patch;
- recover process-orphaned `in_flight` legacy rows to retryable using the same idempotency identity;
- preserve owner mismatch, reauthentication, scope-transition, and orphan-recovery markers; and
- never “repair” mixed generations by choosing one id or deleting ambiguous rows.

### Transition/bootstrap failure

`purging` and authorization-scope markers are repeat-safe. Restart and let the coordinator resume the recorded phase. If local storage cannot be read, show the protection-failure state and state that data was not deleted. Do not guess completion and do not recommend reset/reinstall.

## 7. Physical-device drill record

Run against a staging server with controllable latency/status responses. Record device model, Android version, app/build, server commit, DB migration evidence, start/end UTC, aggregate before/after counts, and pass/fail. Never capture screenshots/logs containing payloads, members, grades, attendance, message bodies, tokens, PINs, or full operation ids.

### A. Reauthentication and crash recovery

1. Create pending and needs-attention attendance/grade work plus communication draft/send.
2. Revoke the refresh family and trigger a protected request.
3. Confirm the recovery gate replaces the old shell, counts are accurate, and rows are preserved.
4. Kill/restart at the gate; confirm the marker and rows survive.
5. Reauthenticate as the same account; confirm safe resume and exactly-once delivery.

### B. Owner mismatch

1. Preserve private work for user A, then attempt login as B.
2. Confirm B cannot activate, view, or drain A's data and the candidate server session is revoked.
3. Explicitly discard A's private state through the reviewed destructive flow.
4. Login B and confirm clean private caches/queues. Shared hymn work must follow its separate shared-data policy.

### C. Live role/scope downgrade

1. Warm P1 caches and queue old-scope work.
2. Change role or teacher assignment in admin.
3. Test both a GET-first and POST-first next request.
4. Confirm the old request is blocked, refresh obtains the current profile/version, and the original POST is **not** automatically replayed.
5. Confirm old tabs/routes/caches disappear and old-scope work is retained/held with privacy-gated details.
6. Kill/restart mid-transition and confirm marker recovery.

### D. Queue policy matrix

Exercise timeout, socket failure with link present, transport status 0, HTTP 408, 429 with Retry-After, 500, immutable replayed 500, 400, 403, 404, each recognized 409 code, unknown 409, 422, and malformed response. For each, verify exact durable state, reason code, retry time/timer, and zero failure-driven deletion. Resume must not bypass Retry-After. A terminal-only queue must produce no periodic HTTP calls for at least 15 minutes.

### E. Communication FIFO

- Queue A then B in one thread and C in another.
- Make A transient: B must wait while C can proceed.
- Make A terminal: B remains blocked until explicit exact-operation resolution.
- Kill after request/before response; replay must yield one authoritative message.

### F. Hymn durability/dependencies

For create, edit, category, singer, status, and lyrics operations, exercise each policy class. Verify optimistic local content and queue rows survive; revision conflict preserves the attempted local payload; rejected lyrics are never counted sent; and a failed dependency blocks its dependent operation. Restart between claim and response and verify idempotent recovery.

### G. Exact legacy-operation races

Repeat for attendance, grades, Mezmur attendance, and HR attendance:

1. Stall draft A, save changed draft B, accept A: B remains pending and later sends as B.
2. Stall A, replace with B, reject A: B receives no A error.
3. Delay claim, Submit then Undo: Undo wins and only the resulting draft can reach the server.
4. Pause after claim, tap Undo: Undo refuses honestly.
5. Rapidly type multi-digit grades/remarks and immediately Submit: server sees one coherent final submitted generation.
6. Kill after claim/request and before response: restart replays the same identity exactly once.
7. Open Needs Attention A, replace it through the controlled hook, then discard stale A: B survives.
8. Repeat accepted-after-replace during auth expiry and scope downgrade.
9. Simulate seven-day cleanup: no replacement falsely marked synced becomes deletable.

### H. Explicit logout and forgot PIN

Exercise every logout entry point. Confirm one consistent preserve/destructive policy, accurate private-work inventory, owner recovery on preserve, explicit copy on destructive/forgot-PIN actions, and preservation of shared hymn work where specified.

### I. Kill-switch drill

Follow §5. Confirm pause survives process death and failed config refresh through the cached false value; new local saves remain durable; no queue rows disappear; resume sends the same operations exactly once.

### J. Low-memory hardware

Run A-I, especially G, on at least one low-memory Tecno-class Android phone. Background/kill the app during migration, auth transition, claim, request, and settlement. Confirm no bootstrap wipe, mixed payload generation, cross-owner visibility, duplicate send, or unbounded rescan.

## 8. Aggregate observability and release gates

Allowed aggregate counters/reason-code groupings include:

```text
auth_refresh_outcome
scope_change_count
reauth_required_reason_code
outbox_decision_by_kind/code
retry_due_count
in_flight_count
needs_attention_count
superseded_local_count_by_kind
legacy_mixed_operation_quarantine_count
owner_mismatch_count
migration_v34_result
```

Server protected audit practice may retain numeric user id with a machine reason code when already approved, but never token contents or payload detail. General analytics must not contain full operation ids.

Before each phase expansion, require:

- preflight/post-migration output has no `BLOCK`;
- focused and retained automated suites match the accepted baseline with no new failing IDs;
- build 23 compatibility tests pass until the minimum-build phase;
- build 24 physical drills pass, including low-memory/crash cases;
- no cross-user visibility/replay, queue loss, duplicate delivery, stale-operation mutation, or authorization bypass;
- aggregate retry/needs-attention rates stay within the approved baseline; and
- a named operator, rollback owner, and observation window are recorded.

Stop immediately if migration assumptions differ, migration 009/010/046 cannot be safely applied, trigger privileges are absent, assignment changes fail to bump authorization version, build 23 cannot coexist during compatibility, the minimum-build gate cannot block old clients, any failure path deletes queued work, or any device drill reveals cross-user visibility/replay. Do not “fix forward” by wiping device data.
