# S0 — Mobile Offline, Sync, Session & Incomplete-Data Forensic Audit

**Status:** Read-only forensic audit. **Nothing was fixed, changed, or refactored.**
**Date:** 2026-10-05
**Repository state audited:** `origin/main = d82945e`, working tree clean.
**App audited:** `Mobile/wbws_flutter_app/` — `fkss_app` version `1.5.1+25`, Flutter `>=3.27.0`, Dart SDK `>=3.3.0 <4.0.0`.
**Server audited:** `api/v1/` (6,648 PHP lines), `admin/backend/services/`.
**Production schema evidence:** `uploads/production_database.md` (`arkeonet_felegekidusan`, MariaDB 11.4.13).

---

## 1. Executive summary

### 1.1 The headline correction

The brief anticipates a system with little or no state model, no retry policy, no idempotency and no account isolation. **That is not what the code contains.** The single most important outcome of this audit is a correction of that premise:

> The FKSS mobile app already implements a **mature, well-reasoned offline write architecture**: a nine-state outbox machine, a jittered retry ladder, durable owner- and authorization-version scoping on every private row, a login gate that refuses to activate Account B while Account A's work is pending, Stripe-style server idempotency backed by a production table, and refresh-token rotation with reuse detection.

Much of what the brief proposes as S1–S4 **already exists in some form.** Specifying S1–S8 against the brief's assumed baseline would rebuild working machinery and risk regressing it.

### 1.2 What is actually broken

The real deficiency is **not** the write path. It is **observability** — the system is largely correct but *cannot explain itself*, and it is *unverified*. The genuine gaps:

| # | Gap | Severity |
|---|---|---|
| F-01 | Fleet telemetry counts **sync passes**, not operations or attempts — this is the exact origin of the ambiguous "3 failed, 1 successful" | **High** |
| F-02 | **No attempt-level record anywhere.** `attempt_count` is a bare counter; prior attempts are overwritten and unrecoverable | **High** |
| F-03 | **No request/correlation ID** anywhere in `api/v1` — a mobile operation cannot be tied to a server log line | **High** |
| F-04 | **No background sync whatsoever.** No scheduler package exists. Sync runs only while the app process is alive | **High** |
| F-05 | The 35 Dart tests **are never executed by CI** — the entire mobile state machine is unverified | **High** |
| F-06 | Telemetry is fire-and-forget with **no local queue** — it is silently lost exactly when the device is offline, i.e. when failures happen | **Medium-High** |
| F-07 | Idempotency records expire after **7 days**; the completion write is **not** in the business transaction | **Medium** (low for attendance, see §18) |
| F-08 | `sync_log` is vestigial — four untyped columns, no operation id, no status code | **Medium** |
| F-09 | No defined retention or pruning job for `app_telemetry_events` | **Medium** |
| F-10 | Production `attendance` has `UNIQUE(member_id, attendance_date)`, which is stricter than per-class uniqueness — a member enrolled in two classes cannot be marked in both on one day | **Medium**, latent, pre-existing |

### 1.3 The security answer up front

> **§15 — Can User B ever cause User A's pending operation to sync under User B's credentials?**
>
> **On the evidence read: No.** Three independent layers prevent it (§15). The strongest is `canActivateCandidate()`, which **refuses the login entirely** and revokes the newly issued token bundle if any private row carries a different owner.
>
> **Confidence: Medium-High, not Absolute.** The logic is correct *as written*, but it has **never been executed in CI** (F-05) and I could not run it (no Dart toolchain, §3). The guarantee is a **code-reading result, not a test result.**

---

## 2. Scope, method and evidence standard

### 2.1 Method

Static source inspection of the Flutter client and the PHP API, plus live `grep`/`awk` interrogation of the production SQL dump. No code was modified. No migration was run. No production system was contacted.

### 2.2 Evidence labelling used throughout

| Label | Meaning |
|---|---|
| **FACT** | Read directly from source or the production dump; file and line cited |
| **INFERENCE** | Reasoned from facts; the reasoning is shown so it can be challenged |
| **NOT VERIFIED** | Could not be established with the access available. Never guessed |

### 2.3 §30 schema-provenance statement

- **Server schema — PRODUCTION VERIFIED.** All server table claims in this report were checked against `uploads/production_database.md`, the authoritative dump. Where a table exists, its `CREATE TABLE` is quoted.
- **Mobile SQLite schema — NOT production-verified, and cannot be.** The local schema lives on user devices. The repository declares it (`local_schema_v34.dart`, `localDatabaseSchemaVersion = 35`), but **no device database was inspected.** Devices in the field may sit at **any** earlier version, and migration correctness is untested (F-05).
  > **Production mobile database state not verified.**

---

## 3. Evidence base and verification status

### 3.1 What was read (FACT)

| Artifact | Size | Role |
|---|---|---|
| `lib/services/local_db.dart` | 7,090 lines | Local SQLite: 48 tables, all outboxes, claim/settle logic |
| `lib/services/local_schema_v34.dart` | 158 lines | Declarative schema contract, `localDatabaseSchemaVersion = 35` |
| `lib/services/outbox_policy.dart` | 215 lines | Pure retry/classification policy |
| `lib/services/session_models.dart` | 294 lines | Session + activation state machine |
| `lib/services/session_service.dart` | 1,013 lines | Session lifecycle, login gate, logout |
| `lib/services/sync_service.dart` | 704 lines | Drain loop, triggers, telemetry emission |
| `lib/services/api_service.dart` | 1,932 lines | HTTP, token storage, idempotency key |
| `lib/services/telemetry_service.dart` | — | Fleet telemetry |
| `api/v1/core/middleware.php` | 303 lines | Server idempotency |
| `admin/backend/services/ApiIdempotencyService.php` | — | Idempotency persistence + retention |
| `uploads/production_database.md` | 9,797 lines | **Authoritative production schema** |

### 3.2 What could NOT be run — and why

**§29 forbids installing the Flutter SDK. No `flutter` or `dart` binary exists in this environment** (verified: `command -v flutter` → not found; `command -v dart` → not found).

Therefore the following are **NOT VERIFIED**:

- The **35 Dart test files** under `Mobile/wbws_flutter_app/test/` — including `outbox_policy_test.dart`, `session_state_test.dart`, `drain_outcome_test.dart`, `attendance_sync_coordinator_test.dart`, `comm_store_test.dart`, `sync_recovery_models_test.dart`. These are precisely the tests that would validate this audit's most important claims.
- Whether the app **compiles at all.** Prior session memory records that commit `a533115` (Phase B) shipped Dart that was never compiled.
- Any runtime behaviour: actual migration execution, actual claim atomicity under concurrency, actual UI strings as rendered.
- Real device SQLite contents, real telemetry volumes, real server logs.

**Every client-side finding in this report is a source-reading result, not an execution result.** This caveat is not repeated at each finding; it applies globally.

### 3.3 Critical meta-finding — F-05

**FACT.** The repository's only CI workflow is `.github/workflows/backend-checks.yml`, containing exactly three jobs: `php-lint`, `security-tests`, `migration-hygiene`. There is **no Flutter job, no Dart job, and no reference to `Mobile/` anywhere in CI.**

**Impact.** The mobile offline/sync/session architecture — the most safety-critical and most concurrency-sensitive code in the product — has **zero automated verification**. 35 test files exist and are never run. They may already be failing or may not compile.

**Severity: High.** **Recommended direction:** add a Flutter CI job before any S1–S8 work begins. This is the cheapest, highest-value action available and is a prerequisite for safely changing any of this code.

---

## 4. Client architecture overview

### 4.1 Dependency facts that shape everything (FACT, `pubspec.yaml`)

Present: `http`, `sqflite`, `shared_preferences`, `flutter_secure_storage`, `connectivity_plus`, `provider`, `crypto`, `local_auth`, `path_provider`.

**Absent — and each absence is architecturally decisive:**

| Absent package | Consequence |
|---|---|
| `workmanager`, `background_fetch`, `android_alarm_manager`, `flutter_background_service` | **No OS-scheduled background sync is possible.** → §24, F-04 |
| `uuid` | Op IDs are hand-rolled — correctly, as it happens (§16) |
| `dio` | No interceptor framework; auth/retry handled manually in `api_service.dart` |
| `drift`, `isar`, `hive` | Raw SQL against `sqflite`; no compile-time schema checking |
| `sqlcipher` / encrypted sqflite | **Local DB is not encrypted at rest by a dependency.** See §22 |
| `sentry`, `firebase_crashlytics` | No automatic crash reporting; a hand-rolled `crash_log_service.dart` exists instead |

### 4.2 Layering

```
screens/ (47 files)  →  services/ (48 files)  →  local_db.dart (SQLite)
                                              →  api_service.dart (HTTP)
```

Notably `outbox_policy.dart`, `session_models.dart` and `sync_recovery_models.dart` are deliberately **Flutter-free pure Dart** — their header comments state this is to keep the state machine deterministic and unit-testable. The intent is excellent. It is undermined entirely by F-05: those unit tests never run.

---

## 5. Server architecture overview

`api/v1/index.php` dispatches to `routes/`, with `core/auth.php`, `core/acl.php`, `core/middleware.php`, `core/response.php`.

Mobile write routes: `attendance.php` (456), `grades.php` (1,190), `hr.php` (204), `mezmur.php` (736), `notifications.php` (331), `sync.php` (175).

**Production-verified supporting tables** (all quoted from the dump):

- `api_idempotency_records` — idempotency with lease (§18)
- `api_idempotency` — a second, apparently legacy, idempotency table. **Its relationship to `api_idempotency_records` is NOT VERIFIED.** Worth a follow-up; two idempotency tables is a smell.
- `api_refresh_sessions` — refresh-token rotation (§16)
- `api_refresh_legacy_exchanges`
- `app_telemetry_events` — fleet telemetry (§21)

**There is no server-side operation ledger and no attempt table.** (FACT — no such table in the dump.)

---

## 6. Local database inventory (§6)

`localDatabaseSchemaVersion = 35` (FACT, `local_schema_v34.dart:5`). 48 tables created in `local_db.dart`.

### 6.1 Classification

| Class | Tables | Count |
|---|---|---|
| **Outboxes (write-behind)** | `pending_attendance`, `pending_grades`, `pending_hr`, `pending_mezmur`, `pending_hymn_ops`, `comm_outbox` | 6 |
| **Drafts** | `comm_drafts` | 1 |
| **Session/sync control** | `local_session_state`, `sync_state`, `sync_log`, `comm_meta`, `hymn_sync_meta` | 5 |
| **Read caches** | 27 × `cached_*` | 27 |
| **Messaging** | `comm_messages`, `comm_threads` | 2 |
| **Hymn media/search** | `hymn_downloads`, `hymn_download_pins`, `hymn_search_*` (4) | 6 |

### 6.2 The two control tables (FACT, `local_schema_v34.dart:30–53`)

```sql
CREATE TABLE IF NOT EXISTS sync_state (
  domain TEXT PRIMARY KEY, cursor INTEGER NOT NULL DEFAULT 0,
  last_sync_at TEXT, status TEXT NOT NULL DEFAULT 'idle', error TEXT
)

CREATE TABLE IF NOT EXISTS local_session_state (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  owner_user_id INTEGER, owner_username TEXT, owner_display_name TEXT,
  owner_role TEXT, owner_authorization_version INTEGER,
  state TEXT NOT NULL, reason TEXT,
  generation INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL
)
```

`sync_state` is a well-designed **download** cursor: its comment states the cursor advances only after the apply transaction commits, so a crash re-pulls rather than skipping. `local_session_state` is a single-row session identity with a monotonic `generation` used to fence stale async work (§15.3).

### 6.3 Per-table analysis of the write-bearing tables

| Table | PK | Owner scoping | Retry state | Error fields | Survives restart | Survives logout | Survives account switch |
|---|---|---|---|---|---|---|---|
| `pending_attendance` | `id` AUTOINC | `owner_user_id` + `created_authorization_version` | `sync_state`, `attempt_count`, `next_attempt_at`, `last_attempt_at` | `sync_error`, `failure_code`, `failure_http_status`, `failed_at` | **Yes** | **Yes** if `preserveForReauthentication`; **No** if `discardPrivateData` | Switch is **blocked** while rows exist (§15) |
| `pending_grades` | `id` AUTOINC | same | same | same | Yes | same | blocked |
| `pending_hr` | `id` AUTOINC | same *(added by v34 specs)* | same *(added by v34 specs)* | same | Yes | same | blocked |
| `pending_mezmur` | `id` AUTOINC | same *(added by v34 specs)* | same *(added by v34 specs)* | same | Yes | same | blocked |
| `pending_hymn_ops` | `id` AUTOINC | `created_by_user_id` (**different name**) | full | full | Yes | **Yes — treated as shared, survives private purge** | permitted |
| `comm_outbox` | `client_tag` **TEXT** | `owner_user_id` + `created_authorization_version` | `state`, `attempts`, `next_attempt_at`, `last_attempt_at` | `fail_reason`, `failure_code`, `failure_http_status`, `failed_at` | Yes | same | blocked |
| `comm_drafts` | `thread_id` | `owner_user_id` + `created_authorization_version` | n/a (draft) | n/a | Yes | same | blocked |

### 6.4 An important correction I must record

My first reading of `local_db.dart` alone showed `pending_hr` and `pending_mezmur` with **only** `synced`/`sync_error` and **no `owner_user_id`** — which would have been a severe account-isolation hole. **That reading was wrong.**

`local_schema_v34.dart:82–99` declares `LocalColumnSpec` entries that add `sync_state`, `attempt_count`, `next_attempt_at`, `last_attempt_at`, `failure_code`, `failure_http_status`, `failed_at`, `created_authorization_version` **and** `owner_user_id` to both tables via a reconciliation harness. The inline `CREATE TABLE` is the historical v1 shape; the declarative spec is the current contract.

**Lesson, recorded deliberately:** in this codebase the `CREATE TABLE` text is *not* the schema. Any future work must read `local_schema_v34.dart` as the source of truth. A reviewer reading only `local_db.dart` would reach a false conclusion — as I briefly did.

**Residual risk (NOT VERIFIED):** the backfill adds `owner_user_id` as a **nullable** column with no default. Rows created *before* the v34 migration will have `owner_user_id IS NULL`. Because every claim query requires `owner_user_id = ?` (§7.3), such legacy rows become **permanently unclaimable** — invisible, undeletable by the sync path, and counted by inventory as private work that can block login (§15.4). Whether a backfill `UPDATE` populates them was not traced to a conclusion. **This is the single most important open question for S1** and is recorded as finding **F-11 (Medium-High)**.

### 6.5 Deletion / retention locally

`cleanupSynced()` is invoked after each drain (`sync_service.dart:250`). Rows reaching `synced` are removed. **No TTL or periodic cache-clearing exists** — consistent with the standing constraint prohibiting it.

---

## 7. Outbox inventory (§7)

### 7.1 Six outboxes, three maturity tiers

| Tier | Outboxes | Characteristics |
|---|---|---|
| **A — full** | `pending_attendance`, `pending_grades`, `comm_outbox` | Full state machine, owner scoping, backoff, failure codes, UI surfacing |
| **B — backfilled** | `pending_hr`, `pending_mezmur` | Same columns, but via v34 specs; legacy-row risk F-11 is highest here |
| **C — divergent** | `pending_hymn_ops` | Generic `op` + `payload_json`; `depends_on` for ordering; `entity_key`; uses `created_by_user_id`, and is deliberately **shared, not private** |

`pending_hymn_ops` differing is intentional — `session_models.dart:207` comments that *"Shared hymn operations survive private-account cleanup and are never included in the private-work decision."* That is a defensible product decision, but it means **hymn operations are the one class of pending work that does NOT block an account switch and is NOT purged on destructive logout.** Security-relevant and called out explicitly in §15.5.

### 7.2 The §7 interrogation — can the outbox answer the required questions?

| Question | Answer | Evidence |
|---|---|---|
| Which operation failed? | **Yes** | `client_op_id` + natural key columns |
| Which *attempt* failed? | **No** | Only `attempt_count`; attempts are not individually recorded — **F-02** |
| Why did it fail? | **Partly** | `failure_code`, `failure_http_status`, `sync_error` hold only the **most recent** failure; earlier causes overwritten |
| Is it retryable? | **Yes** | `sync_state ∈ {pending, retry_wait}` = retryable; `needs_attention` = terminal |
| How many retries? | **Yes (count only)** | `attempt_count` |
| Did the server ever accept it? | **Partly** | `idempotency-replayed` header is read at runtime but **not persisted**; after the fact, unanswerable — **F-02** |
| What is the final state? | **Yes** | `sync_state` |

**Five of seven answerable; the two unanswerable both stem from the absence of attempt records.**

### 7.3 The claim query (FACT, `local_db.dart:3299–3384`)

```sql
SELECT client_op_id, MIN(id) AS first_id FROM <table>
 WHERE synced = 0
   AND sync_state IN ('pending','retry_wait')
   AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
   AND owner_user_id = ?
   AND created_authorization_version = ?
   AND client_op_id IS NOT NULL AND TRIM(client_op_id) <> ''
 GROUP BY client_op_id
 ORDER BY MIN(created_at), MIN(id) LIMIT 1
```

Then, inside the same transaction:

```sql
UPDATE <table> SET sync_state='in_flight', attempt_count = attempt_count + 1,
                   last_attempt_at = ?, next_attempt_at = NULL
 WHERE client_op_id=? AND synced=0 AND sync_state=? AND <natural key> 
   AND owner_user_id=? AND created_authorization_version=?
```

**This is genuinely good engineering.** Observations:

1. **Atomic claim.** If `affected != rows.length` it throws `StateError('Legacy operation claim was not atomic.')` — it fails loudly rather than syncing a partial batch.
2. **Session fencing.** The transaction opens with `activeSessionMatches(runtimeGeneration, ownerUserId, authorizationVersion)` and returns `null` if the session moved on.
3. **Per-row ownership re-check** inside the loop (`3350–3353`) — defence in depth beyond the WHERE clause.
4. **Coherence guard** — re-reads claimed rows and throws if the count differs.
5. **Multi-row operations are grouped by `client_op_id`** — an attendance sheet of 40 students is **one operation**, not 40. This is the correct unit and directly supports §8.
6. **`attempt_count` increments at claim time**, not at response time — so an operation claimed and then lost to a crash still shows the attempt. Correct.

**Note:** `LIMIT 1` per kind per pass, with each kind bounded to 100 claims per pass (`sync_service.dart:241–248`), re-queuing if more remain.

---

## 8. Operation vs attempt (§8) — the core conceptual finding

### 8.1 The distinction as the code actually implements it

| Concept | Definition in this codebase | Durable? |
|---|---|---|
| **Operation** | One business intent, identified by `client_op_id` (UUIDv4), possibly spanning many rows sharing that id and a natural key (e.g. one attendance sheet = class + date) | **Yes** |
| **Attempt** | One claim→HTTP→settle cycle against one operation | **No — only counted** |
| **Sync pass (drain)** | One invocation of `_drain()`, which may process **many operations across 6 outboxes** | **No — not recorded at all** |

### 8.2 Resolving "3 failed, 1 successful" — definitively

**FACT**, `sync_service.dart:268–273`:

```dart
if (synced > 0 || failed > 0) {
  unawaited(TelemetryService.instance.recordSyncResult(
    success: failed == 0,
    itemsCount: synced,
    error: failed > 0 ? '$failed operations pending retry' : null,
  ));
}
```

**FACT**, `telemetry_service.dart:100–105`:

```dart
Future<void> recordSyncResult({required bool success, int itemsCount = 0, String? error}) async {
  await recordEvent(success ? 'sync_completed' : 'sync_failed', {
    'items_count': itemsCount,
    if (error != null) 'error': error.substring(0, min(200, error.length)),
  });
}
```

**Therefore — the dashboard's counts are counts of _sync passes_, not operations and not attempts.**

Three consequences, each independently misleading:

1. **`success: failed == 0` is all-or-nothing per pass.** A pass that successfully syncs 9 operations and fails 1 is recorded as **`sync_failed`**. Nine successes are erased from the fleet record.
2. **One operation generates many events.** An operation that fails three times and succeeds on the fourth produces exactly `sync_failed ×3` + `sync_completed ×1` — **precisely the "3 failed, 1 successful" pattern in the brief, for what is in truth one operation that ultimately succeeded.**
3. **The `error` field is a bare count string** (`"1 operations pending retry"`) — it carries **no failure code, no HTTP status, no operation id**. The classification work done so carefully in `outbox_policy.dart` is discarded before it reaches telemetry.

**Answer to the brief's question:** "3 failed, 1 successful" means **four drain passes occurred, three of which contained at least one non-succeeding operation.** It says **nothing** about how many operations existed, how many ultimately synced, or why any failed. It is entirely consistent with *a single operation that eventually succeeded*, and equally consistent with *hundreds of operations permanently lost*. **The metric cannot distinguish those two outcomes.** — **F-01, High.**

---

## 9. Attempt lineage (§9)

**FACT:** `grep -rniE "attempt_id|attempt_history|sync_attempts|CREATE TABLE.*attempt" lib/` returns **nothing**. No attempt table exists client-side. No attempt table exists in the production dump server-side.

**What is retained per operation:** `attempt_count` (integer), `last_attempt_at`, and the **most recent** `failure_code` / `failure_http_status` / `sync_error` / `failed_at`.

**What is destroyed:** every earlier attempt. Each settle overwrites the previous failure fields.

**Worked example.** An operation that fails `503` → `503` → `409 IDEMPOTENCY_IN_PROGRESS` → `403` presents at rest as `attempt_count = 4, failure_code = 'FORBIDDEN', failure_http_status = 403`. The three prior causes — including the diagnostically crucial fact that **the server was in progress on attempt 3, implying it may have committed** — are unrecoverable.

**Impact.** Post-hoc diagnosis of a user's complaint ("my attendance never sent") is impossible beyond the final error. Support cannot distinguish *persistently offline* from *rejected once after four transport failures*. — **F-02, High.**

---

## 10. Attendance deep dive (§10) — 24 questions

Code paths traced: `lib/screens/attendance/attendance_screen.dart` (1,078 lines), `lib/services/attendance_sync_coordinator.dart`, `lib/services/attendance_delta.dart`, `lib/utils/packet.dart`, `local_db.dart::saveAttendanceLocal`/`claimNextLegacyOperation`, `api/v1/routes/attendance.php`, `admin/backend/services/AttendanceRecordService.php`.

| # | Question | Answer (evidence) |
|---|---|---|
| 1 | Where does attendance first persist? | `pending_attendance`, one row **per student**, grouped into one operation by shared `client_op_id` + natural key `(class_id, date)` |
| 2 | Is a partially marked sheet persisted? | **Yes** — as `packet_kind='draft'` |
| 3 | Is there a draft concept? | **Yes.** `packet_kind ∈ {'draft','submitted'}`. `utils/packet.dart`: *"Save = draft (still editable). Submit = finished (view only)."* |
| 4 | What marks it complete? | `attendance_screen.dart:351` — `_students.where((s) => _statusOf(s['status']).isEmpty).length == 0` |
| 5 | Is completeness enforced before submit? | **Yes, client-side.** Line 352–354 blocks submit and sets `'Mark attendance for every student ($unmarked remaining).'` |
| 6 | Is completeness enforced server-side? | **Yes** — `AttendanceRecordService::normalizeCompleteSheet($records, $roster)` (`attendance.php:44`) normalises against the authoritative roster |
| 7 | Can an incomplete sheet enter the outbox? | **Yes** — drafts are written to `pending_attendance` |
| 8 | Can an incomplete sheet **sync**? | **Yes — as a draft.** The claim query (§7.3) does **not** filter `packet_kind`; `local_db.dart:3411–3413` carries `LegacyPacketKind.draft` into the claim snapshot |
| 9 | Is that a defect? | **No — it is deliberate.** Drafts sync so partial work survives device loss. Distinguish clearly: **drafts sync as drafts; they are never submitted as complete.** |
| 10 | Does the server accept a draft? | Routed distinctly; `packet_kind` is carried. Exact server-side draft persistence **NOT VERIFIED** in full |
| 11 | What is the unit of sync? | The **sheet** (class + date), not the student row — correct |
| 12 | Idempotency key? | `client_op_id`, UUIDv4, sent as `Idempotency-Key` header **and** body field |
| 13 | Retry policy? | Shared ladder, §17 |
| 14 | Retries bounded? | **Transport failures: unbounded** (capped at 900 s). Unknown/protocol: bounded at 5 (`maxUnknownAutomaticAttempts`) |
| 15 | Survives app kill? | **Yes** — SQLite, committed at save |
| 16 | Survives logout? | **Yes if** `preserveForReauthentication`; **No if** `discardPrivateData` |
| 17 | Survives account switch? | The switch itself is **blocked** while the row exists (§15) |
| 18 | Can it be edited after submit? | **Yes, via a short UNDO window** — `_submittedUndoRef`, `attendance_screen.dart:404–458` |
| 19 | Does undo risk a double-submit? | **No.** `local_db.dart:3604–3624` mints a **fresh `client_op_id`** and reverts to draft, guarded by `WHERE packet_kind='submitted' AND sync_state IN ('pending','retry_wait')` — so undo is refused once in flight |
| 20 | Conflict detection? | `REVISION_CONFLICT` + canonical conflict item → `OutboxDecision.resolvedConflict` |
| 21 | Server write mechanism? | `AttendanceRecordService::replaceSheet` → `DELETE FROM attendance WHERE class_id=? AND attendance_date=?` then insert — **naturally idempotent** |
| 22 | Transactional? | **Yes** — `attendance.php:228` `$conn->begin_transaction()` |
| 23 | Does the user learn why it did not sync? | **Partly** — see §11 and §25 |
| 24 | Is the attempt history visible? | **No** — F-02 |

### 10.1 A latent production defect discovered incidentally — F-10

**FACT**, production dump, `ALTER TABLE attendance`:

```sql
ADD UNIQUE KEY `unique_attendance` (`member_id`,`attendance_date`),
ADD UNIQUE KEY `uq_att_member_class_date` (`member_id`,`class_id`,`attendance_date`),
```

`unique_attendance(member_id, attendance_date)` is **strictly stronger** than `uq_att_member_class_date`, making the latter redundant. The practical effect: **a member enrolled in two classes cannot have attendance recorded in both on the same date** — the second insert violates the unique key.

Combined with `replaceSheet`'s `DELETE ... WHERE class_id=? AND attendance_date=?`, syncing class B's sheet after class A's would fail on the unique key rather than coexisting.

**Severity: Medium.** **Confidence: High** on the schema; **NOT VERIFIED** whether any member is in fact enrolled in two classes in production. **Reproduction:** enrol one member in two classes; submit attendance for both on one date. **Recommended direction:** decide whether per-member-per-day or per-member-per-class-per-day is the intended rule, then drop the wrong index. **Do not fix in S0.**

---

## 11. The attendance UX problem (§11)

### 11.1 What the brief asks for, and what already exists

The brief treats *"Mark attendance for every student (N remaining)"* as a desired future state. **It already exists** — `attendance_screen.dart:352–354`, plus a live `'$n unmarked'` indicator at line 812.

### 11.2 The actual gap

The app correctly handles **incomplete**. It handles **failed-to-sync** with a reasonable vocabulary (§25). What it does **not** do is let the user see, for a *specific* class and date, **which of the following is true**:

| True state | Current user-visible signal |
|---|---|
| Sheet incomplete, never submitted | ✅ `"N remaining"` — clear |
| Complete, submitted, waiting on network | `"Could not send yet. Will retry on its own."` — clear |
| Complete, submitted, **session expired** | `"Sync paused until this account is active again."` — clear **in Sync Center**, but **NOT VERIFIED** whether it appears on the attendance screen itself |
| Complete, submitted, **server rejected** | `"The school did not accept this work."` — present, but carries **no reason** |
| Draft synced as draft | **No distinct signal identified** — a user cannot tell a synced draft from an unsynced one |

**Finding F-12 (Medium):** the vocabulary exists but is concentrated in the Sync Center (`lib/screens/profile/sync_center_screen.dart`). Whether the *attendance screen* surfaces paused/rejected state **NOT VERIFIED** — it requires rendering the widget tree, which I cannot do.

**The brief's critical principle is respected by the code**: incomplete and sync-failed are genuinely different states (`packet_kind` vs `sync_state`), stored in different columns, and not conflated.

---

## 12. App close, force-stop, reboot — cases A–H (§12)

Mechanism: every outbox write is a committed SQLite transaction. There is **no in-memory-only queue**. Sync scheduling, however, is **entirely in-process** (`Timer`, `sync_service.dart:27,86`).

| Case | Scenario | Data survives | Sync resumes | Evidence / caveat |
|---|---|---|---|---|
| **A** | Swipe-away mid-form, nothing saved | **No** | n/a | Form state is widget state; **NOT VERIFIED** whether an autosave exists |
| **B** | Swipe-away after Save (draft) | **Yes** | On next app open | Committed row |
| **C** | Swipe-away after Submit, before sync | **Yes** | On next app open | `sync_state='pending'` |
| **D** | Force-stop while `in_flight` | **Yes**, but **stuck in `in_flight`** | **Only if a recovery sweep resets it** | **Critical — see 12.1** |
| **E** | Device reboot | **Yes** | **Not until the user opens the app** | No background scheduler — F-04 |
| **F** | OS kills app in background | **Yes** | Same as E | F-04 |
| **G** | App crash | **Yes** | Next open; `crash_log_service.dart` records | Crash→telemetry only if online |
| **H** | Reinstall / clear data | **No — permanently lost** | n/a | SQLite destroyed; **no server-side copy of unsynced work exists** |

### 12.1 Case D — the `in_flight` recovery question

`attempt_count` is incremented and `sync_state` set to `in_flight` **at claim time**, before the HTTP call. If the process dies mid-flight, the row is left `in_flight`. The claim query admits only `('pending','retry_wait')` — so **an orphaned `in_flight` row is never re-claimed** unless something resets it.

Evidence that a recovery mechanism is intended: `sync_recovery_models.dart` exists; `local_db.dart:3227` queries `WHERE state='in_flight' AND owner_user_id = ?`; `sync_recovery_models_test.dart` exists.

**Whether the reset actually runs on every bootstrap, and whether it is correctly ordered before the first claim, is NOT VERIFIED** — it requires execution. Given `generation`-based fencing elsewhere, the design intent is clearly present.

**Finding F-13 (High if absent, Low if present — currently NOT VERIFIED).** This is the **highest-priority item to verify first in S1**, because if the sweep is missing or mis-ordered, *every force-stop during sync silently strands an operation forever*, and the user is told nothing. **Reproduction:** mark attendance, submit, force-stop during the HTTP call, reopen, inspect `sync_state`.

---

## 13. Logout (§13)

**FACT**, `session_models.dart:57`:
```dart
enum LogoutChoice { cancel, preserveForReauthentication, discardPrivateData }
```

**FACT**, `session_service.dart:839–853`:
```dart
case LogoutChoice.preserveForReauthentication:
  await enterReauthentication(reason: 'explicit_logout_preserve', revokeCurrentSession: true);
case LogoutChoice.discardPrivateData:
  await destructiveSignOut(reason: 'explicit_logout_discard');
```

**The brief's critical principle — "logout with pending valid data ≠ delete the data" — is already honoured by the design.** Logout is a *choice*, and `preserveForReauthentication` keeps every pending row while revoking the server session.

`LocalDataInventory.workSummary` (`session_models.dart:247–268`) generates human-readable text — *"2 attendance batch(es), 1 pending message(s), 3 nonempty draft(s)"* — evidently to tell the user what they would destroy.

**NOT VERIFIED:** whether the UI actually **presents** this choice, whether `preserve` is the default, and whether `discard` requires confirmation. These are widget-tree facts I cannot reach. **If `discardPrivateData` were ever the default or a single unconfirmed tap, that would be a High-severity data-loss defect** — flagged for S1 verification as **F-14**.

Note `_stopPrivateServices()` is called before purging, which correctly prevents a concurrent drain from racing the purge.

---

## 14. Session expiry (§14)

**Client classification** (`outbox_policy.dart:134–143`): definitive auth rejection → `pauseForAuthentication` → state `paused_auth`. Crucially:

```dart
if (evidence.refreshOutcome == AuthRefreshOutcome.transientFailure) {
  return OutboxDecision.retryable;
}
```
with the comment: *"A failed refresh transport/protocol attempt is not evidence that either the credential or its authorization scope was definitively rejected."*

**This is exactly right**, and it is the distinction the brief insists on: a network failure during refresh must not be mistaken for an expired session. The code reasons about this explicitly.

Definitive codes: `INVALID_REFRESH_TOKEN`, `REFRESH_EXPIRED`, `REFRESH_REUSED`, `REFRESH_REVOKED`, `ACCOUNT_DISABLED`, `ACCOUNT_REMOVED`.
Scope codes: `AUTH_SCOPE_CHANGED`, `AUTH_SCOPE_REFRESH_REQUIRED` → `paused_scope`.

**Session expiry with complete data waiting:** data is **retained**, state becomes `paused_auth`, the session moves to `reauth_required` (`stateForMissingCredentials`, `session_models.dart:287–293`), and the user sees *"Sync paused until this account is active again."* **The brief's principle — "session expired with complete data waiting ≠ incomplete" — is honoured.**

**Server side (production-verified):** `api_refresh_sessions` implements rotation with `family_id`, `replaced_by`, `consumed_at`, `revoked_at`, `token_hash`, `user_agent_hash` — i.e. **refresh-token reuse detection**, which is why `REFRESH_REUSED` exists as a client code. This is a strong design.

---

## 15. Account-switching security (§15) — the priority question

> **Can User B ever cause User A's pending operation to sync under User B's credentials?**

### 15.1 Layer 1 — login is refused (strongest)

**FACT**, `session_models.dart:271–285`:

```dart
bool canActivateCandidate({required SessionState currentState, required int? boundOwnerUserId,
                           required int candidateUserId, required LocalDataInventory inventory}) {
  if (currentState == SessionState.purging ||
      currentState == SessionState.orphanedLocalData ||
      inventory.privateOwnerUserIds.any((id) => id != candidateUserId)) {
    return false;
  }
  if (boundOwnerUserId == null || boundOwnerUserId == candidateUserId) return true;
  return !inventory.hasPrivateDurableWork;
}
```

If **any** private row carries an owner other than the candidate, activation is refused. `session_service.dart:593–602` then **revokes the candidate's freshly issued token bundle** (`await _api.revokeBundle(candidate)`) and returns:

> *"This phone contains private work for the previous account. Sign in as that account or explicitly discard the work."*

**User B cannot even complete login while User A has pending work.** Revoking the bundle is a notably careful touch — it prevents a token from lingering after a refused activation.

### 15.2 Layer 2 — the claim query is owner-scoped

Even hypothetically past layer 1, `claimNextLegacyOperation` requires `owner_user_id = ?` **and** `created_authorization_version = ?`, re-verifies both per row (`3350–3353`), and re-applies both in the `UPDATE` and the snapshot re-read. The same scoping appears throughout `comm_store.dart` (lines 209, 239, 289, 356, 397, 439, 462, 484, 507).

### 15.3 Layer 3 — generation fencing

`activeSessionMatches(runtimeGeneration, ownerUserId, authorizationVersion)` opens the claim transaction; `_ownsGeneration(generation)` is re-checked at **six** points in `_drain` (`sync_service.dart:245, 251, 260`, etc.). An in-flight drain belonging to a superseded session aborts rather than settling. `OutboxDecision.supersededSession` and `supersededLocal` exist specifically for this, and `'Superseded work must not be settled.'` is an explicit guard string.

### 15.4 Failure modes that still deserve scrutiny

1. **`owner_user_id IS NULL` legacy rows (F-11).** `privateOwnerUserIds.any((id) => id != candidateUserId)` — behaviour over NULLs depends on how the inventory query collects them. If NULLs are excluded from `privateOwnerUserIds` **and** counted in `hasPrivateDurableWork`, login is blocked (safe). If excluded from both, pre-v34 rows are invisible to the gate — but they are **also unclaimable** by the owner-scoped claim query, so they cannot sync under anyone. **Net: likely safe-but-stranded rather than a leak.** Confidence Medium; **NOT VERIFIED**.
2. **`_hasOwnerMetadataConflict`** → `orphanedLocalData` + bundle revoked — conflicting owner metadata is treated as unattachable to *any* login. Conservative and correct.
3. **Hymn operations are exempt** (§15.5).

### 15.5 The one real exposure — shared hymn operations

`pending_hymn_ops` uses `created_by_user_id`, is explicitly excluded from the private-work decision, and **survives destructive sign-out**. Consequently:

> A hymn operation queued by User A, still pending, **can be drained while User B is signed in** — and will be transmitted under **User B's credentials**.

Whether the server attributes it to A (via a payload field) or to B (via the bearer token) is **NOT VERIFIED**. If the server attributes by token, **User B becomes the recorded author of User A's content change.**

**Finding F-15 — Severity: Medium (security-relevant attribution), High if hymn ops can delete or overwrite published content.** **Confidence: Medium-High** that the exemption is real (it is explicitly coded and commented); **NOT VERIFIED** as to server-side attribution. **Reproduction:** queue a hymn op offline as A, sign out (discard), sign in as B, go online, inspect the server's recorded actor. **Recommended direction:** decide deliberately whether hymn ops are "shared/device-scoped" (then record the original author in the payload and have the server honour it) or private (then scope them like the other five). **Do not fix in S0.**

### 15.6 Verdict

| Layer | Prevents cross-account sync? |
|---|---|
| Login gate + bundle revocation | **Yes** (private work) |
| Owner-scoped claim query | **Yes** (private work) |
| Generation fencing | **Yes** |
| Hymn ops | **No — deliberately exempt** |

**For the five private outboxes: No, User B cannot cause User A's operation to sync under B's credentials** — subject to the global caveat that none of this is covered by an executed test (F-05).

---

## 16. Identity model (§16)

| Identity | Mechanism | Scope | Evidence |
|---|---|---|---|
| **Operation id** | `client_op_id` — UUIDv4 via `Random.secure()`, RFC-4122 bits set (`b[6]=0x40`, `b[8]=0x80`) | per operation | `local_db.dart:20–28` |
| **Message id** | `client_tag` (PK of `comm_outbox`) | per message | — |
| **Owner** | `owner_user_id` / `created_by_user_id` | per row | §6.3 |
| **Authorization epoch** | `created_authorization_version` | per row | invalidates work across a scope change |
| **Runtime fence** | `generation` in `local_session_state` | per session activation | §15.3 |
| **Device** | `installation_id` (varchar 64) | per install | production `app_telemetry_events` |
| **Refresh session** | `session_id` + `family_id` | per login | production `api_refresh_sessions` |
| **Request / correlation id** | **NONE** | — | **F-03** |

`newClientOpId()` is a correct, cryptographically sound UUIDv4 — notable given no `uuid` dependency.

**The missing identity is the request id.** There is no value that appears in both a client record and a server log line, so no operation can be traced end-to-end. This is the keystone gap for §20 and the main reason S5/telemetry work would be premature before it is fixed.

---

## 17. Retry forensics (§17)

**FACT**, `outbox_policy.dart:26–59`:

```dart
const outboxRetryLadderSeconds = <int>[2, 5, 12, 30, 60, 120, 300, 900];
```

- **Full jitter**: `milliseconds = (cap * 1000 * randomUnit).ceil()`, clamped to `[1, cap*1000]`. Correct thundering-herd avoidance.
- **`Retry-After` is mandatory and wins**, clamped to 1–3600 s, **never shortened by jitter** — correct, and the comment says so.
- Index derivation `attemptCount <= 1 ? 0 : attemptCount - 1`, saturating at 900 s.
- Jitter source: `Random.secure()` in `hymn_store.dart`, plain `Random()` in `comm_outbox_service.dart:34` annotated *"jitter only — not a security use"* — a correct and well-labelled distinction.

### 17.1 Classification table (as implemented)

| Condition | Decision | Terminal? |
|---|---|---|
| `refreshOutcome == superseded` | `supersededSession` | n/a |
| `supersededLocal` | `supersededLocal` | n/a |
| `refreshOutcome == transientFailure` | **`retryable`** | No |
| scope changed / `AUTH_SCOPE_*` | `pauseForAuthorizationScope` | Paused |
| rejected / definitive auth codes | `pauseForAuthentication` | Paused |
| 2xx success | `accepted` | **Yes** |
| 2xx but protocol error | bounded unknown | ≤5 |
| transport / timeout / 0 / 408 / 425 / 429 | `retryable` | No |
| 5xx **and** `idempotencyReplayed` | **`needsAttention`** | Yes |
| 5xx otherwise | `retryable` | No |
| 409 `IDEMPOTENCY_IN_PROGRESS` | `retryable` | No |
| 409 `REVISION_CONFLICT` + canonical item | `resolvedConflict` | Yes |
| 409 terminal conflict codes | `needsAttention` | Yes |
| 400/403/404/405/410/413/415/422 | `needsAttention` | **Yes** |
| 401 / unknown / protocol | bounded unknown (≤5) | then yes |

The **5xx + replayed → `needsAttention`** rule is subtle and correct: if the server replayed a stored response *and* it was a 5xx, retrying cannot help, because the stored outcome is itself the error.

### 17.2 Gaps

- **No absolute attempt cap for transport failures.** A permanently unreachable host retries forever at 900 s intervals. Benign in isolation, but combined with F-01 it emits a `sync_failed` telemetry event **every 15 minutes per device, indefinitely** — inflating fleet failure counts without bound. **F-16, Medium.**
- **No per-attempt record** — §9.
- Backoff derives from `attempt_count`, which is lost on any manual reset.

---

## 18. Server accepted, response lost (§18)

### 18.1 The mechanism (production-verified)

`api_idempotency_records` — **EXISTS IN PRODUCTION**:

```sql
CREATE TABLE `api_idempotency_records` (
  `record_hash` char(64) ascii_bin NOT NULL,
  `user_id` int unsigned NOT NULL,
  `idem_key` varchar(80) ascii_bin NOT NULL,
  `request_scope` varchar(255) NOT NULL,
  `request_hash` char(64) ascii_bin NOT NULL,
  `owner_token` char(64) ascii_bin NOT NULL,
  `record_state` enum('processing','completed') NOT NULL DEFAULT 'processing',
  `status_code` smallint unsigned DEFAULT NULL,
  `response_body` mediumtext DEFAULT NULL,
  `lease_expires_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB;
```

Flow (`middleware.php:130–195`): key = `Idempotency-Key` header or body `client_op_id`; validated `^[A-Za-z0-9._-]{1,80}$`; scope = `METHOD + route`; `request_hash` = SHA-256 of the **recursively key-sorted canonical JSON** (a genuinely careful touch — key order cannot cause a false conflict).

| `begin()` result | Behaviour |
|---|---|
| `acquired` | proceed |
| `replay` | flush buffers, replay stored status + body, `Idempotency-Replayed: true`, `exit` |
| `conflict` | 409 `IDEMPOTENCY_CONFLICT` |
| `processing` | 409 `IDEMPOTENCY_IN_PROGRESS` + `Retry-After` |
| else | 503 |

**This correctly solves the central case:** server commits, response lost, client retries with the same key → stored response replayed → **no duplicate**. The client reads the header (`api_service.dart:86–87`) and feeds `idempotencyReplayed` into classification.

### 18.2 The window that remains

**FACT.** The ordering is: `apiIdempotencyBegin` → `begin_transaction` → write → `commit` → `ok()` → `response.php:58` → `apiIdempotencyStore`.

The completion `UPDATE` (`ApiIdempotencyService.php:88–103`) therefore runs **after and outside** the business transaction, wrapped in a `try/catch` that **silently swallows failure**:

```php
} catch (\Throwable $error) {
    // The application response remains valid. A failed persistence
    // write is not replaced with a misleading success/failure body.
}
```

So if the process dies — or that UPDATE fails — between commit and store, the record stays `processing` until `lease_expires_at`, after which a retry acquires a fresh reservation and **re-executes the business write**.

### 18.3 Severity, correctly qualified

For **attendance** the practical risk is **Low**, because the write is naturally idempotent: `AttendanceRecordService::replaceSheet` performs `DELETE FROM attendance WHERE class_id = ? AND attendance_date = ?` then re-inserts, and production enforces `UNIQUE(member_id, attendance_date)`. Re-execution converges to the same state.

For **grades, HR, mezmur and messages** the natural idempotency of the write is **NOT VERIFIED**. `comm_outbox` in particular (append-semantics messages) would be expected to **duplicate** on re-execution.

**F-07 — Severity: Medium** (Low for attendance; potentially High for messages, unverified). **Recommended direction:** move the idempotency completion into the business transaction, or make every mobile write natural-key idempotent. **Do not fix in S0.**

### 18.4 Retention interacts with this

`RETENTION_SECONDS = 604800` — **7 days** (`ApiIdempotencyService.php:14`). GC is probabilistic: `if (random_int(1, 1000) === 1) DELETE ... WHERE expires_at < CURRENT_TIMESTAMP LIMIT 1000`.

> **A device offline for more than 7 days that then retries will find its idempotency record expired, and the write will be re-executed.**

Given the retry ladder caps at 900 s and retries indefinitely, a device offline for 8+ days (plausible: holidays, broken screen, rural connectivity) hits exactly this. Again mitigated for attendance by `replaceSheet`; unverified elsewhere. Folded into **F-07**.

---

## 19. Client logging (§19)

`sync_log` (`local_db.dart`):
```sql
CREATE TABLE sync_log (id INTEGER PRIMARY KEY AUTOINCREMENT,
  action TEXT, detail TEXT, status TEXT, created_at TEXT)
```

Four untyped, all-nullable columns. **No `client_op_id`, no outbox name, no HTTP status, no attempt number, no owner.** It cannot be joined to any operation. — **F-08, Medium.** It is effectively vestigial and should be either dropped or replaced by the S1 ledger; it is **not** a foundation to build on.

`crash_log_service.dart` exists with a parse test (`crash_log_parse_test.dart`). Its storage location, retention and whether it captures sync context are **NOT VERIFIED**.

**There is no user-accessible diagnostic export.** A user cannot send support a log bundle; support cannot ask for one.

---

## 20. Server logging (§20)

**FACT.** `grep -rniE "request_id|correlation|X-Request-Id|trace_id" api/v1/core/*.php api/v1/index.php` → **no matches**.
**FACT.** `grep -rn "error_log" api/v1/` → **11 occurrences across 6,648 lines.**

There is therefore:
- **no request id** generated, logged, or returned to the client;
- **no correlation** between a client `client_op_id` and any server log line;
- **no structured request/response logging** of mobile writes;
- **no per-endpoint error rate** observable from the application.

The one positive: `api_idempotency_records` incidentally retains `user_id`, `request_scope`, `status_code` and `response_body` for 7 days — **the closest thing to a server-side operation log that exists**, and it is keyed by `idem_key`, which **is** the client's `client_op_id`.

> **This is the single most valuable discovery for S1.** A join key between client and server already exists in production. `api_idempotency_records.idem_key = pending_*.client_op_id`. An operation ledger does not need to invent correlation — it needs to **persist** what is already flowing and stop discarding it after 7 days.

— **F-03, High.**

---

## 21. Fleet telemetry (§21)

**Production-verified:**
```sql
CREATE TABLE `app_telemetry_events` (
  `id` bigint unsigned NOT NULL,
  `installation_id` varchar(64) NOT NULL,
  `event_type` varchar(48) NOT NULL,
  `event_data` text DEFAULT NULL,
  `app_version` varchar(32) NOT NULL,
  `app_build` int unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB;
```

Event types emitted by the client: `launch`, `sync_completed`, `sync_failed`, `crash_recorded`, `update_downloaded`.

### 21.1 Structural limits

- **No `user_id`.** Only `installation_id`. Good for privacy (§22); means fleet failures cannot be attributed to an account without a separate join.
- **No `client_op_id`, no attempt number, no HTTP status, no failure code.** Everything beyond the type is unstructured `event_data` TEXT.
- **Granularity is the drain pass** — §8, F-01.

### 21.2 Delivery is lossy exactly when it matters — F-06

`_sendPayload` is a bare `http.post` inside a `try { } catch { }`, called via `unawaited(...)`. **There is no local telemetry queue and no retry.**

> If the device is offline, the telemetry event is **silently discarded**.

Since `sync_failed` is overwhelmingly caused by being offline, **the fleet dashboard systematically under-reports the most common failure mode.** The events that do arrive are biased toward devices that had connectivity — i.e. the healthy ones. **This is a survivorship bias baked into the metric.** Severity: **Medium-High**.

### 21.3 The dashboard

`AppTelemetryService.php` aggregates installs, actives (1/7/30 d), downloads, versions, OS, brand. Its event view (line ~280) is:

```sql
SELECT e.id, e.installation_id, e.event_type, e.event_data, e.app_version, e.app_build,
       e.created_at, i.device_brand, i.device_model, i.os_version
  FROM app_telemetry_events e
  LEFT JOIN app_installations i ON e.installation_id = i.installation_id
 ORDER BY e.id DESC LIMIT ?
```

A reverse-chronological raw list. **No grouping by operation, no lineage, no success-rate computation.** Whatever "3 failed, 1 successful" is rendered from, it is counting rows of this shape — i.e. passes.

---

## 22. Telemetry PII (§22)

| Field | PII? | Assessment |
|---|---|---|
| `installation_id` | Pseudonymous | Device-scoped, not user-scoped — **good** |
| `app_version` / `app_build` | No | — |
| `os_version`, `sdk_int`, `device_brand`, `device_model`, `abi`, `ram_mb`, `is_low_ram` | Low | Fingerprinting surface, modest |
| `event_data.items_count` | No | A count |
| **`event_data.error`** | **Possible** | **200-char substring of an arbitrary error string** |

**Finding F-17 (Medium, privacy):** `recordSyncResult` truncates the error to 200 chars but does **not sanitise** it. For the current call site the string is the fixed `'$failed operations pending retry'` — safe. But `recordEvent` is general-purpose, and `recordCrash` sends a 300-char `summary` whose contents are **NOT VERIFIED**. A crash summary can readily contain a stack frame with a member name, a class name, or a URL with identifiers.

No `user_id` is sent — a deliberate and commendable choice. **Recommended direction:** allowlist telemetry fields rather than truncating free text; never send raw exception text. Flag before any S5 expansion of telemetry, because S5 will be tempted to add exactly the identifying fields currently and correctly absent.

---

## 23. Retention (§23)

**The brief's distinction — diagnostic retention vs user-data retention — must not be conflated, so I separate them explicitly.**

### 23.1 Diagnostic retention (target: 90 days)

| Store | Current retention | vs 90-day target |
|---|---|---|
| `api_idempotency_records` | **7 days**, probabilistic GC | ✗ far short |
| `app_telemetry_events` | **None defined; no pruning job found** | ✗ unbounded growth |
| `sync_log` (client) | None defined | ✗ and useless anyway (F-08) |
| Client crash logs | **NOT VERIFIED** | ? |
| Server `error_log` | Host-managed, **NOT VERIFIED** | ? |

**F-09, Medium:** `app_telemetry_events` has no retention policy and no pruning. It grows without bound — simultaneously a cost/performance risk and a data-minimisation concern (GDPR-style principles would expect a defined horizon).

### 23.2 User-data retention (entirely separate)

Unsynced local operations persist **indefinitely** until synced, explicitly discarded at logout, or the app is uninstalled. `cleanupSynced()` removes only rows that reached `synced`. **No TTL expires user work** — correct, and consistent with the standing "no TTL/periodic cache clearing" constraint.

> **These two must never be unified.** A 90-day diagnostic horizon must not be allowed to delete a user's 91-day-old unsynced attendance sheet. Any S7 work must keep the ledger's retention policy strictly separate from the outbox's.

---

## 24. Background sync (§24)

**FACT.** No background execution package exists in `pubspec.yaml` (verified individually: `workmanager`, `android_alarm_manager`, `background_fetch`, `flutter_background_service` — all absent).

**Triggers, exhaustively** (`sync_service.dart`):

| Trigger | Line | Mechanism |
|---|---|---|
| App start | `startAutoSync()` :56, nudge :67 | in-process |
| Connectivity regained | `_radioSub = ConnectivityService().statusStream.listen(...)` :64–65 | in-process listener |
| Post-drain re-queue | :140, :265 | `Timer` |
| Scheduled retry | `_retryTimer = Timer(delay, ...)` :86 | **in-process `Timer`** |
| Manual | Sync Center / pull-to-refresh | user action |

Several screens call `_load()` / `_refreshAll()` on `AppLifecycleState.resumed`, but these are **read refreshes**, not outbox drains.

**Consequences:**
1. **App killed → all `Timer`s die.** No retry occurs until the user reopens the app.
2. **Reboot → nothing syncs** until the app is opened.
3. **Backgrounded → Dart timers are throttled/suspended** by both Android and iOS; on Android the process may be reaped at any time.
4. A user who submits attendance offline and does not reopen the app has work that **will never sync**, with no notification.

**F-04, High.** `audio_service` is present (for mezmur playback), which does grant a foreground-service capability on Android — **whether it could host sync is an architectural question for S2, not a current behaviour.** Note the standing constraint "no background services" from the paused sync work; **S2 must get an explicit decision from the owner before adding one.**

---

## 25. Local-first UX messaging (§25)

Strings found (`sync_service.dart`, `sync_recovery_models.dart`, `widgets/sync_attention.dart`, `widgets/offline_banner.dart`, `widgets/status_banner.dart`):

| String | State | Assessment |
|---|---|---|
| `'Sent to Education'` | synced | Clear |
| `'Sent $synced. $failed still waiting — will retry.'` | partial | **Good** — honest about partial success |
| `'Could not send yet. Will retry on its own.'` | retryable | **Good** — not alarming, sets expectation |
| `'Kept safely on this phone.'` | durable | **Excellent** — directly addresses the user's real fear |
| `'Sending is temporarily paused. Your work remains saved.'` | paused | **Good** |
| `'Sync paused until this account is active again.'` | `paused_auth` | **Good** — distinguishes auth from network |
| `'The school did not accept this work.'` | `needs_attention` | Honest but **gives no reason and no next step** |
| `'Could not send this work.'` | terminal | Vague |
| `'Nothing waiting to send'` | empty | Clear, and correctly an empty state rather than an error |
| `'Local work changed while sending. Checking the new copy.'` | superseded | Precise |
| `'Open Sync Center'` | affordance | A sync centre **already exists** |

**This vocabulary is better than the brief assumes.** It already separates *offline* from *paused* from *rejected*, and it never claims success it cannot prove.

**Gaps:**
- `'The school did not accept this work.'` has no reason and no remedy, despite `failure_code` and `failure_http_status` being stored. **F-18, Medium** — the data to improve this is already persisted; only the presentation is missing.
- Whether these strings reach the **originating screen** (vs only the Sync Center) is **NOT VERIFIED** (§11.2, F-12).
- No message distinguishes *"synced as draft"* from *"not yet synced"*.

---

## 26. Future state model feasibility (§26)

The brief's required states, mapped to what exists:

| Required state | Exists today? | Representation |
|---|---|---|
| incomplete | **Yes** | `packet_kind='draft'` + unmarked count |
| local draft | **Yes** | `packet_kind='draft'`; `comm_drafts` |
| ready-to-sync | **Yes** | `packet_kind='submitted'`, `sync_state='pending'` |
| queued | **Yes** | `sync_state='pending'` + `next_attempt_at` |
| syncing | **Yes** | `sync_state='in_flight'` |
| retrying | **Yes** | `sync_state='retry_wait'` |
| synced | **Yes** | `synced=1` / `sync_state='synced'` |
| permanently failed | **Yes** | `needs_attention` |
| auth-blocked | **Yes** | `paused_auth` |
| permission-blocked | **Yes** | `paused_scope` |
| validation-blocked | **Partly** | folded into `needs_attention`; `failure_code` distinguishes it but the *state* does not |
| conflict | **Yes** | `resolved_conflict` + `REVISION_CONFLICT` |
| cancelled / discarded | **No** | discard deletes the row; no tombstone |
| *(extra)* blocked on dependency | **Yes** | `blocked_dependency` (hymn `depends_on`) |

**Conclusion: 11 of 13 required states already exist**, plus one the brief did not ask for. **The state model does not need to be built; it needs to be (a) tested, (b) surfaced, (c) extended by two states.**

Feasible additions:
1. Split `validation_blocked` out of `needs_attention` — purely a classification change in `outbox_policy.dart`; data already present.
2. Add `discarded` as a terminal state with a tombstone, rather than deleting — required if discarded operations are to be auditable.

**Recommending a rewrite of this state machine would be a mistake.** It is the most carefully built part of the system.

---

## 27. Operation ledger and attempt history feasibility (§27)

### 27.1 Client-side ledger — highly feasible

A single generic table replaces nothing and breaks nothing (additive):

```
operation_ledger(operation_id TEXT PK, domain TEXT, natural_key TEXT,
                 owner_user_id INT, authorization_version INT,
                 created_at, submitted_at, terminal_at, final_state TEXT)

operation_attempt(attempt_id INTEGER PK AUTOINCREMENT, operation_id TEXT,
                  attempt_no INT, started_at, finished_at,
                  http_status INT, failure_code TEXT, decision TEXT,
                  idempotency_replayed INT, transport TEXT, message TEXT)
```

**Everything needed is already computed and then thrown away.** `claimNextLegacyOperation` already knows `client_op_id`, owner, auth version, and the incremented `attempt_count`; the settle path already has `OutboxResponseEvidence` with `statusCode`, `errorCode`, `idempotencyReplayed`, `failureKind` and the resulting `OutboxDecision`. **An attempt row is one INSERT at settle time using values already in hand.** This is a low-risk, high-value change — the single best first move.

### 27.2 Server-side — also feasible, and partly already there

As noted in §20, `api_idempotency_records` already stores `user_id`, `idem_key` (= `client_op_id`), `request_scope`, `status_code`, `response_body` — for 7 days. A server ledger can be built by **writing an append-only row at `apiIdempotencyBegin`/`Store` time** rather than inventing new plumbing.

**Prerequisite: F-03.** Without a request id, server log lines still cannot be tied to operations even with a ledger. Introduce the request id **first**, echo it in the response, and persist it on both sides.

### 27.3 Sequencing implication

**The ledger must come before telemetry work.** Fixing telemetry granularity (F-01) without an attempt record would merely relabel the same impoverished data. With an attempt record, correct per-operation and per-attempt metrics fall out naturally.

---

## 28. Failure categories (§28) — mapped to real API behaviour

The brief's categories, **revised against what the API actually returns** rather than adopted blindly:

| Brief category | Real equivalent | Codes/statuses actually produced | Keep? |
|---|---|---|---|
| Network failure | `ApiFailureKind.transport` / `timeout`, status 0 | — | **Keep** |
| Server error | 5xx | — | **Keep**, but split: *5xx + replayed* is terminal, *5xx fresh* is retryable |
| Auth failure | `pauseForAuthentication` | `INVALID_REFRESH_TOKEN`, `REFRESH_EXPIRED`, `REFRESH_REUSED`, `REFRESH_REVOKED`, `ACCOUNT_DISABLED`, `ACCOUNT_REMOVED` | **Keep** |
| Permission failure | `pauseForAuthorizationScope` | `AUTH_SCOPE_CHANGED`, `AUTH_SCOPE_REFRESH_REQUIRED`, 403 | **Keep** — and note the code already separates it from auth, which many systems do not |
| Validation failure | 400 / 422 | — | **Keep**, and **promote to its own state** (§26) |
| Conflict | 409 | `REVISION_CONFLICT`, `ALREADY_SUBMITTED`, `WORKFLOW_REJECTED`, `IDEMPOTENCY_CONFLICT`, `IDEMPOTENCY_IN_PROGRESS` | **Keep**, but note `IDEMPOTENCY_IN_PROGRESS` is **retryable**, not a conflict — it is a 409 that means "wait" |
| — | **Rate limiting** | 429 + `Retry-After` | **ADD** — handled in code, absent from the brief's list |
| — | **Protocol/parse failure** | `ApiFailureKind.protocol` (2xx with an unparseable body) | **ADD** — real, handled, and diagnostically distinct |
| — | **Superseded** (session or local) | `supersededSession`, `supersededLocal` | **ADD** — not a failure at all; must not be counted as one |
| — | **Idempotency service unavailable** | 503 | **ADD** |

**Three corrections to the brief's taxonomy:** (1) `IDEMPOTENCY_IN_PROGRESS` must not be bucketed as a conflict; (2) *superseded* is a non-failure and counting it as failure will inflate error rates; (3) a 5xx that was *replayed* is terminal, not retryable — the opposite of the usual rule.

---

## 29. Data-flow diagram, with failure paths (§32)

```
┌─────────────────────────── CLIENT ───────────────────────────┐
│                                                               │
│  UI (attendance_screen.dart)                                  │
│    │  user marks students                                     │
│    ├──► [unmarked > 0] ──► BLOCK submit                       │
│    │        "Mark attendance for every student (N remaining)" │
│    │        (state: INCOMPLETE — never a sync failure)        │
│    │                                                          │
│    ├──► Save ───────► packet_kind='draft'   ─┐                │
│    └──► Submit ─────► packet_kind='submitted'─┤               │
│                                               ▼               │
│                      pending_attendance (SQLite, COMMITTED)   │
│                      client_op_id=UUIDv4, owner_user_id,      │
│                      created_authorization_version,           │
│                      sync_state='pending'                     │
│                             │                                 │
│        ┌────────────────────┤ survives: kill, crash, reboot   │
│        │                    │ lost on:  uninstall, discard    │
│        ▼                    ▼                                 │
│   [no scheduler]      SyncService._drain()                    │
│   app must be         triggers: app start │ connectivity      │
│   OPEN to sync ◄──────          │ in-process Timer only       │
│   (F-04)                    │                                 │
│                             ▼                                 │
│              claimNextLegacyOperation()  [TRANSACTION]        │
│              ├─ activeSessionMatches(generation, owner, authV)│
│              ├─ WHERE owner_user_id=? AND created_auth_ver=?  │
│              ├─ GROUP BY client_op_id  (sheet = 1 operation)  │
│              └─ UPDATE → 'in_flight', attempt_count += 1      │
│                             │                                 │
│        ┌────────────────────┴── process dies here ──┐         │
│        │                                   STUCK 'in_flight'  │
│        │                                   recovery sweep?    │
│        ▼                                   (F-13 NOT VERIFIED)│
│   ApiService.post()                                           │
│   Idempotency-Key: <client_op_id>                             │
│   body.client_op_id = <client_op_id>                          │
└─────────────┬─────────────────────────────────────────────────┘
              │  ✗ offline/timeout ──► retryable, ladder 2…900s
              │                        (jittered, unbounded)
              ▼
┌─────────────────────────── SERVER ───────────────────────────┐
│  api/v1/index.php ──► core/auth.php (bearer) ──► acl.php      │
│      ✗ 401 definitive ──► paused_auth   (data RETAINED)       │
│      ✗ 403 / scope    ──► paused_scope  (data RETAINED)       │
│                        │                                      │
│  middleware.php :: apiIdempotencyBegin()                      │
│      key + METHOD+route + sha256(canonical JSON)              │
│      ├─ replay     ──► stored body + "Idempotency-Replayed"   │
│      │                 ──► client: accepted, NO duplicate ✔   │
│      ├─ conflict   ──► 409 IDEMPOTENCY_CONFLICT (terminal)    │
│      ├─ processing ──► 409 IN_PROGRESS + Retry-After (retry)  │
│      └─ acquired   ──► continue                               │
│                        │                                      │
│  routes/attendance.php :: begin_transaction()                 │
│      normalizeCompleteSheet(records, roster)   ← server-side  │
│      replaceSheet: DELETE WHERE class_id+date; INSERT         │
│      commit()                                                 │
│         │                                                     │
│         ├── ✗ CRASH HERE ──► committed, but record stays      │
│         │                    'processing' → lease expires →   │
│         │                    retry RE-EXECUTES (F-07)         │
│         ▼                                                     │
│  response.php :: ok() ──► apiIdempotencyStore()  [SEPARATE]   │
│                           7-day retention (F-07)              │
│  ✗ NO request_id / correlation_id anywhere (F-03)             │
└─────────────┬─────────────────────────────────────────────────┘
              │ response (may be LOST in transit)
              ▼
    classifyOutboxResponse(evidence)  → 1 of 8 decisions
              │
    ┌─────────┼──────────┬───────────┬────────────┐
    ▼         ▼          ▼           ▼            ▼
 accepted  retryable  needs_     paused_*    superseded
 synced=1  retry_wait attention  (RETAINED)  (not a failure)
 cleanup   +backoff   terminal
    │         │          │
    └─────────┴──────────┴──► attempt_count++ ONLY
                              prior attempts OVERWRITTEN (F-02)
                              │
                              ▼
            TelemetryService.recordSyncResult()
            ONE event per DRAIN PASS, not per operation (F-01)
            success = (failed == 0)   ← 9 ok + 1 bad = "failed"
            fire-and-forget; LOST if offline (F-06)
                              │
                              ▼
                  app_telemetry_events (production)
                  installation_id, event_type, event_data TEXT
                  no user_id, no op_id, no attempt, no status
                  no retention policy (F-09)
                              │
                              ▼
          Admin dashboard: SELECT … ORDER BY id DESC LIMIT ?
          ⇒ "3 failed, 1 successful" = 4 PASSES, not 4 operations
```

---

## 30. Careless-user journey map (§33)

**J1 — Teacher marks 18 of 40 students, phone dies.**
Nothing was saved unless Save was pressed (Case A). If saved: draft row persists. On reopen the sheet shows 22 unmarked and the correct message. **Not a sync failure, and the system does not call it one.** ✔

**J2 — Marks all 40, taps Submit on the bus with no signal.**
Row → `submitted`/`pending`. Drain attempts, transport failure, `retry_wait`, backoff 2 s → 900 s. User sees *"Could not send yet. Will retry on its own."* and *"Kept safely on this phone."* ✔ **But** if they close the app, retries stop entirely (F-04), and the `sync_failed` telemetry never leaves the device (F-06).

**J3 — Same, then force-stops the app during the upload.**
Row stranded `in_flight`. Recovery sweep existence **NOT VERIFIED** (F-13). **If absent, the sheet never syncs and the user is never told.** This is the worst plausible outcome in the system.

**J4 — Session expires overnight with a complete sheet waiting.**
Refresh fails definitively → `paused_auth`; data retained; *"Sync paused until this account is active again."* On re-login as the same user, `authorization_version` must still match or the row is not claimable. ✔ (with the caveat in §34/Q31)

**J5 — Teacher A hands the phone to Teacher B, who tries to log in.**
`canActivateCandidate` → false → B's bundle **revoked** → *"This phone contains private work for the previous account."* **A's work is untouched and cannot sync as B.** ✔ Strong.

**J6 — B taps "discard" to get in.**
`destructiveSignOut` purges A's private work **permanently**. The `workSummary` text exists to warn them. Whether it is actually shown and confirmed is **NOT VERIFIED** (F-14). **If not, this is one tap from irreversible data loss.**

**J7 — Double-tap Submit.**
Same natural key; the undo/replace path mints ids deliberately; server idempotency catches a genuine duplicate key with a replay. ✔

**J8 — Server commits, response lost, client retries.**
Replay returns the stored response with `Idempotency-Replayed: true`; client marks accepted. **No duplicate.** ✔ Unless >7 days elapsed (F-07).

**J9 — Device offline 10 days, then connects.**
Retries resume at 900 s intervals. Idempotency record has **expired** → write re-executes. For attendance, `replaceSheet` makes this harmless; for messages, **NOT VERIFIED** and plausibly a duplicate. ⚠

**J10 — Reboots the phone and never reopens the app.**
**Nothing ever syncs.** No notification, no background task. The data is safe on the device and invisible to everyone. (F-04)

**J11 — Reinstalls the app.**
All unsynced work is **destroyed with no warning and no server copy.** Unavoidable given a local-only outbox; worth stating plainly in user documentation.

**J12 — Admin asks "did Teacher A's attendance sync?"**
Admin opens the fleet dashboard, sees `sync_failed ×3, sync_completed ×1` for an `installation_id` that is **not linked to a user**, with no operation id and no reason. **The question cannot be answered.** (F-01 + F-03 + F-02 together.)

---

## 31. Failure-state matrix (§38)

| # | Scenario | Current state | Data survives | Sync possible | User message | Recoverable | Security risk | Future requirement |
|---|---|---|---|---|---|---|---|---|
| 1 | Form abandoned mid-entry, not saved | none (widget state) | **No** | n/a | none | No | None | Autosave draft |
| 2 | Incomplete sheet saved | `draft` / `pending` | Yes | Yes (as draft) | "N remaining" | Yes | None | Distinguish *synced draft* |
| 3 | Complete, offline | `submitted` / `retry_wait` | Yes | On reconnect | "Could not send yet. Will retry." | Yes | None | Background retry (F-04) |
| 4 | App force-stopped mid-upload | `in_flight` **stranded** | Yes | **NOT VERIFIED** | **none** | **Unknown** | None | **Verify recovery sweep (F-13)** |
| 5 | Device rebooted, app not reopened | `pending` | Yes | **No** | none | Yes, on open | None | Background scheduler (F-04) |
| 6 | Session expired, data waiting | `paused_auth` | Yes | After re-login | "Paused until this account is active" | Yes | None | Re-login state check |
| 7 | Scope/permission changed | `paused_scope` | Yes | After reconciliation | "Sending is temporarily paused" | Yes | Low | Explain which permission |
| 8 | Logout — preserve | `reauth_required` | **Yes** | After re-login | workSummary text | Yes | None | Confirm default (F-14) |
| 9 | Logout — discard | purged | **No — permanent** | n/a | workSummary warning | **No** | None | Require confirmation (F-14) |
| 10 | Other user attempts login | login **refused**, bundle revoked | Yes (A's) | Only as A | "Contains private work for the previous account" | Yes | **Mitigated** | Keep; add tests (F-05) |
| 11 | Server committed, response lost | `retry_wait` → replay → `accepted` | Yes | Yes | "Sent to Education" | Yes | None | Extend 7-day window (F-07) |
| 12 | Server rejected (400/422/403) | `needs_attention` | Yes | **No** — terminal | "The school did not accept this work." | Manual only | None | **Surface the reason (F-18)** |

---

## 32. Findings register, phase recommendation, and the question set

### 32.1 Findings register

| ID | Finding | Sev | Component | Location | Reproduced | Confidence |
|---|---|---|---|---|---|---|
| F-01 | Telemetry counts drain passes, not operations/attempts | High | Client telemetry | `sync_service.dart:268–273`; `telemetry_service.dart:100–105` | No (static) | **High** |
| F-02 | No attempt-level record; prior attempts overwritten | High | Client DB | no attempt table; `local_db.dart` settle | No | **High** |
| F-03 | No request/correlation id in the API | High | Server | `api/v1/core/*` — zero matches | No | **High** |
| F-04 | No background sync; in-process `Timer` only | High | Client sync | `pubspec.yaml`; `sync_service.dart:27,86` | No | **High** |
| F-05 | 35 Dart tests never run in CI | High | CI | `.github/workflows/backend-checks.yml` | Yes (inspected) | **High** |
| F-06 | Telemetry fire-and-forget; lost when offline | Med-High | Client telemetry | `telemetry_service.dart::_sendPayload` | No | **High** |
| F-07 | Idempotency completion outside txn; 7-day expiry | Med | Server | `ApiIdempotencyService.php:14,88–103`; `response.php:58` | No | **High** (mechanism); Med (impact) |
| F-08 | `sync_log` vestigial, unjoinable | Med | Client DB | `local_db.dart` | No | **High** |
| F-09 | No retention/pruning for `app_telemetry_events` | Med | Server | no DELETE found | No | Med-High |
| F-10 | `UNIQUE(member_id, attendance_date)` blocks multi-class same-day | Med | Production schema | dump, `ALTER TABLE attendance` | No | **High** (schema); NOT VERIFIED (occurrence) |
| F-11 | Pre-v34 rows may have NULL `owner_user_id` → unclaimable | Med-High | Client DB | `local_schema_v34.dart:71–99` | No | **Medium** |
| F-12 | Sync state may not surface on originating screen | Med | Client UI | `sync_center_screen.dart` | No | **NOT VERIFIED** |
| F-13 | `in_flight` recovery sweep not confirmed | High if absent | Client sync | `sync_recovery_models.dart`; `local_db.dart:3227` | No | **NOT VERIFIED** |
| F-14 | Logout discard may lack confirmation / be default | High if true | Client UI | `session_service.dart:839–853` | No | **NOT VERIFIED** |
| F-15 | Hymn ops exempt from account isolation | Med (High if destructive) | Client/server | `session_models.dart:207`; `pending_hymn_ops` | No | Med-High |
| F-16 | Unbounded transport retries → unbounded telemetry noise | Med | Client | `outbox_policy.dart` | No | **High** |
| F-17 | Unsanitised error/crash text in telemetry | Med (privacy) | Client telemetry | `telemetry_service.dart:101–111` | No | Med |
| F-18 | Rejection message carries no reason though stored | Med | Client UI | `sync_service.dart` strings | No | **High** |

### 32.2 Revised phase recommendation (§39)

The brief proposes S1 ledger → S2 sync engine → S3 session/account isolation → S4 sync-center UX → S5 telemetry → S6 analytics → S7 90-day history → S8 chaos. **The evidence supports a different and safer order**, for three reasons: the state machine and account isolation largely already exist (so S2/S3 are mostly *verification*, not construction); nothing is test-covered (so any change is unsafe today); and telemetry cannot be fixed before the ledger exists.

| Order | Phase | Why here |
|---|---|---|
| **S0.5 — NEW, blocking** | **Flutter CI + run the 35 existing tests** | Nothing below is safe without it. Cheapest, highest value. Resolves F-05 and may immediately resolve F-13/F-14 by executing existing tests |
| **S1a** | **Verify, don't build:** F-13 (`in_flight` recovery), F-14 (logout default), F-11 (NULL owners), F-12 (screen-level status) | These are *unknowns about current behaviour*, and two are potential data-loss paths. Must precede design |
| **S1b** | **Operation ledger + attempt history** (§27) | Additive; all values already computed. Unblocks S5/S6/S7 |
| **S2** | **Request/correlation id, end-to-end** (F-03) | Small, additive, and the join key already half-exists in `api_idempotency_records.idem_key` |
| **S3** | **Telemetry granularity** (F-01, F-06, F-16, F-17) | Only now is there per-attempt truth to report. Add a local telemetry queue |
| **S4** | **Background sync** (F-04) | Largest behavioural change; **requires owner sign-off** against the standing "no background services" constraint |
| **S5** | **Sync-centre UX** (F-18, F-12, state surfacing) | Needs the ledger to display anything new |
| **S6** | **Idempotency hardening** (F-07) + retention policy (F-09, F-23) | Independent; can run in parallel after S2 |
| **S7** | **Account-isolation hardening** (F-15 hymn ops) | Deliberately late: the private path is already sound; this is a scoped decision, not a rebuild |
| **S8** | **Analytics console / 90-day history** | Consumes S1b + S3 |
| **S9** | **Chaos testing** | Last, and only once CI exists to run it |

**Key sequencing argument:** the brief's S1 (ledger) is correct as the first *build*, but it must be preceded by CI and by resolving the four NOT VERIFIED behaviours — otherwise S1 will be designed against assumptions rather than facts, which is the very error this audit was commissioned to prevent.

### 32.3 §42 — the enumerated questions, answered

| Area | Verdict |
|---|---|
| Where does data live? | 6 outboxes + 1 draft table in SQLite; §6, §7 |
| What makes it sync-eligible? | `synced=0`, `sync_state ∈ {pending, retry_wait}`, `next_attempt_at` due, owner + auth-version match, non-empty `client_op_id`; §7.3 |
| What blocks it? | Wrong owner, stale auth version, superseded generation, `paused_auth`/`paused_scope`, `needs_attention`, stranded `in_flight`, NULL owner; §7.3, §12.1 |
| Retries? | Ladder `[2,5,12,30,60,120,300,900]`s, full jitter, `Retry-After` honoured; unbounded for transport, ≤5 for unknown; §17 |
| Failures recorded? | Latest only; **no lineage**; §9 |
| Session expiry? | Data retained, `paused_auth`, honest message; §14 |
| Unexpected closure? | Data survives; **scheduling does not**; §12, §24 |
| Logout? | A choice — preserve or discard; §13 |
| Another user logs in? | **Blocked, token revoked**; §15 |
| Isolation of pending work? | Enforced at 3 layers for 5 of 6 outboxes; hymn ops exempt; §15 |
| Recoverable? | Yes, except: unsaved forms, discarded logout, uninstall, possibly stranded `in_flight`; §31 |
| Can the system explain why it did not sync? | **To the user: partly** (good vocabulary, no reasons). **To an admin: no**; §19, §20, §21 |
| Can admins trace every operation and attempt? | **No.** This is the central deficiency; §8, §9, §20 |

**Questions that remain open and must be closed in S1a:** F-11, F-12, F-13, F-14, plus server-side attribution for F-15 and natural idempotency for non-attendance writes (F-07).

---

## Appendix A — Verification status summary

| Claim class | Status |
|---|---|
| Server table existence & columns | **PRODUCTION VERIFIED** against `uploads/production_database.md` |
| Server code behaviour | Source-verified; **not executed** |
| Client schema contract | Source-verified (`local_schema_v34.dart`) |
| **Client runtime behaviour** | **NOT VERIFIED — no Dart/Flutter toolchain, installation forbidden by §29** |
| **Mobile production DB state** | **NOT VERIFIED — no device access.** *Production mobile database state not verified.* |
| Dart test results | **NOT VERIFIED — never run here, never run in CI** |
| UI rendering / message placement | **NOT VERIFIED** |

**Nothing in this report was fixed. No code, schema, migration, dependency, API, auth, sync behaviour or UI was modified.**

---

# S1a Verification Addendum (2026-10-05)

**The S0 findings above are preserved verbatim and deliberately not rewritten.** This addendum records what the S0.5 + S1a phase proved or disproved by execution. Full method and evidence: `docs/MOBILE_OFFLINE_SYNC_S0_5_S1A_VERIFICATION.md`.

S0 was written without any Dart/Flutter toolchain, so its client-side conclusions were source-reading results. S0.5 put the mobile suite into CI; S1a then verified the open behaviours. Four `NOT VERIFIED` findings are now closed, two of them in S0's favour and two against S0's worst-case framing.

| S0 finding | Original S0 status | S1a verification |
|---|---|---|
| **F-05** — 35 test files never run by CI | High, confirmed | **RESOLVED.** A pinned `flutter-tests` job (Flutter 3.44.9 / Dart 3.12.2) now runs them on every push. CI run #49: **478 test cases, 478 passed, 0 failed, 0 skipped, 13 s.** Note the correct unit: 35 test *files* contain **478 test cases**. |
| **F-13** — `in_flight` recovery sweep **NOT VERIFIED** ("High if absent") | NOT VERIFIED | **VERIFIED — the sweep exists and cannot be mis-ordered.** `recoverOrphanedInFlightOperations()` (`local_db.dart:796`) resets `in_flight → retry_wait` with `next_attempt_at = now` across all six outboxes in one transaction, and is wired into sqflite's **`onOpen`** (`local_db.dart:561–566`), so no caller can obtain a database handle before it runs. Runtime-proven against real SQLite: before recovery `claimable=0` (stranded), after recovery `claimable=1`, `state=retry_wait`, `attempt_count` **preserved**. S0's concern is disproven. |
| **F-14** — logout discard may be default / unconfirmed ("High if true") | NOT VERIFIED | **VERIFIED — not a defect.** `session_logout_dialog.dart` uses `barrierDismissible: false`, names the exact work via `inventory.workSummary`, offers Cancel / "Keep work & sign out" / a red "Discard permanently", and falls back to **`?? LogoutChoice.cancel`**. Discard does delete (via `destructiveSignOut`), but it is non-default, non-dismissible and itemised. It also discloses the shared-hymn exception to the user. |
| **F-11** — pre-v34 rows with NULL `owner_user_id` | Medium-High, NOT VERIFIED | **RESOLVED, downgraded to Low.** Runtime-proven that NULL-owner rows are claimable by **nobody** (safe-but-stranded, not a leak). `backfillOwnerlessRows` (`local_db.dart:6599`) adopts them across all six outboxes and `comm_drafts`, and its caller binds them to the **previous** authorization scope, never the new one. |
| **F-15** — hymn ops exempt from account isolation | Medium (High if destructive) | **VERIFIED and reclassified: `INTENTIONAL BUT NEEDS DOCUMENTATION`.** `api/v1/routes/mezmur.php` authenticates (`:19`) and re-checks Mezmur staff/admin role on every write (`:36`, `:320`, `:342`) against the **current** bearer token, so User B can never exceed their own authority — **no privilege escalation**. The real defect is **attribution**: `saveHymn(..., $auth['uid'])` records the transmitting user. Severity **Low-Medium** (audit integrity). |
| **§15 — can User B sync User A's pending work?** | "No", Medium-High confidence (code-reading only) | **Confidence raised to High.** Runtime-proven at the SQL layer with the verbatim claim query: owner A claims 1 row, owner B claims **0**, and a stale `authorization_version` claims **0**. |
| **F-07** — idempotency completion outside the business transaction | Medium, mechanism High / impact Medium | **CONFIRMED and made more precise.** Runtime-proven against real MariaDB: replay returns the stored `200` and exact body with no duplicate row; `processing` returns `retry_after=300`; **after lease expiry the retry re-acquires** — i.e. the write re-executes. See new finding **F-20**. |
| **Retry ladder / `Retry-After`** | Source-verified | **Now continuously executed.** `drain_outcome_test.dart:8` asserts `[2,5,12,30,60,120,300,900,900]` and clamps `Retry-After` at 0 / 7200; `outbox_policy_test.dart` pins all 8 decisions. Backoff honoured by the claim query, runtime-proven. |
| **§10 Q8 — do drafts sync?** | "Yes, deliberately" (source) | **Runtime-confirmed.** A `packet_kind='draft'` row is claimable (`claimable=1`); drafts sync *as drafts*. |
| **F-12** — sync state on the originating screen | NOT VERIFIED | **Still NOT VERIFIED** — requires a device/widget harness. |
| **F-01 / F-02 / F-03 / F-04 / F-06 / F-08 / F-09 / F-10 / F-16 / F-17 / F-18** | various | **Unchanged.** None was contradicted. F-01 re-confirmed by exact code reading (still not runtime-executed). |

### New findings raised by S1a

| ID | Finding | Severity |
|---|---|---|
| **F-19** | `pubspec.lock` cannot satisfy `pubspec.yaml`: `url_launcher` is a declared direct dependency and is **absent from the lock entirely**, so `--enforce-lockfile` fails (CI run #48, exit 65) and builds are not reproducible | **Medium-High** |
| **F-20** | The binding idempotency constant is **`LEASE_SECONDS = 300`**, not the 7-day retention. Since the client retry ladder saturates at **900 s**, a server that dies between `COMMIT` and `apiIdempotencyStore` is retried *after* the lease expires and the write re-executes. Reachable in production, not theoretical. Mitigated for attendance by `replaceSheet`; **unverified for `comm_outbox`** | **Medium** |
| — | `tests/security/test_api_idempotency.py` is **source-string assertion only** — it would still pass if the logic were inverted, provided the strings remained | **Low** (test quality) |

### Net effect on the S0 conclusion

S0's central thesis is **strengthened, not revised**: the synchronization machinery is sound and must be preserved; the deficiency is observability. Two of S0's three worst-case data-loss candidates (F-13, F-14) are now disproven, and the account-isolation verdict is proven rather than reasoned. The gate for the next phase is **`READY_FOR_S1`**.
