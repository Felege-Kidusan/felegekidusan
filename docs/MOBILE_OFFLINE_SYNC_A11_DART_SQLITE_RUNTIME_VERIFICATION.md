# A.11 — Real Dart SQLite / LocalDb Runtime Verification

## 1. Result

**PASS — DART/SQLITE RUNTIME VERIFIED; ANDROID SQLITE RUNTIME PENDING.**

The actual production Dart `LocalDb` executed against a real SQLite library in
Flutter tests. The focused runtime test opened a version-36 database, caused
production `LocalDb` to execute the v36 → v37 migration, performed production
legacy-outbox and hymn claims, settled retry and success outcomes, wrote and
closed the shared attempt ledger, enforced owner/session isolation, and passed
SQLite integrity checks.

This is **not** Android runtime verification. No Android emulator/device was
available in the local environment, and no claim is made about the Android
`sqflite_android` implementation.

## 2. Starting Commit

A.11 started from the verified A.10 remote head:

```text
ac554d27c4c8b4f4c56df5ac06301b9e85b04862
```

At inspection time, `HEAD == origin/main` and the working tree was clean. The
A.10 ancestry and lockfile baseline were preserved before the A.11 edits.

## 3. Final Commit

The final locally verified A.11 commit is the commit containing this report
and the implementation/test files. Because a commit cannot contain its own
hash without changing that hash, the exact final local commit is always the
value printed by `git rev-parse HEAD` in §29. Its parent history can be read
with `git log --oneline --decorate` after publication.

## 4. Rollback Recovery

No rollback was required during the A.11 implementation. Before each
meaningful increment, the remote was fetched and inspected. The actual remote
continued to resolve to the A.10 commit shown in §2.

The earlier A.10 recovery state was also rechecked: the remote URL was
available for fetch, the unrelated executable-bit drift was absent, and no
production dump or Android source rollback was performed.

## 5. A.10 Dependency Baseline

The A.10 production baseline remains:

```text
production direct dependency: sqflite: ^2.3.0
lockfile sqflite:             2.4.1
lockfile sqflite_common:     2.5.4+6
lockfile sqflite_android:     2.4.1
Flutter:                      3.44.9
Dart:                         3.12.2
```

A.10 had no FFI binding. A.11 added only the documented test-only runtime
support:

```text
sqflite_common_ffi: 2.3.4+4   (dev dependency, exact pin)
sqlite3:            2.9.4     (dev dependency, exact compatible driver pin)
```

The production `sqflite` dependency was retained. No Flutter, Dart, AGP,
Kotlin, Gradle, Android SDK, NDK, or production SQLite dependency was broadly
upgraded. `flutter pub get --enforce-lockfile` passed after the final lockfile
was generated.

The `sqlite3` pin is intentional: `sqflite_common_ffi 2.3.4+4` still imports
the compatibility entry point `sqlite3/open.dart`, which was removed from the
sqlite3 3.x line. The pin avoids forcing the newer FFI package and its newer
`sqflite_common` graph into the A.10 production lock.

## 6. SQLite Runtime Used

The focused test used:

```text
Flutter test runner:   Flutter 3.44.9 / Dart 3.12.2
sqflite API exercised: package:sqflite production API
Dart binding:          sqflite_common_ffi 2.3.4+4
SQLite driver:         sqlite3 2.9.4
host runtime:          Linux native SQLite through Dart FFI
```

The test assigned the FFI factory to the `sqflite` factory before the
production singleton opened its database. Production code still called its
ordinary `openDatabase`, `getDatabasesPath`, transactions, queries, updates,
and inserts.

This is a real Dart/SQLite runtime, not Python execution and not SQL copied
into a Python harness.

## 7. Why This Runtime

A real Android emulator/device and Android SDK were not available in the local
environment. The FFI binding was therefore the narrowest technically necessary
way to execute the actual production Dart `LocalDb` now, while leaving Android
production on `sqflite`/`sqflite_android` unchanged.

FFI proves the Dart production control flow and SQLite transaction behavior
against a real SQLite engine. It does not prove Android platform integration;
that remains explicitly pending rather than being inferred from FFI.

## 8. Production Dart Code Exercised

The runtime test executed the authoritative production implementation in:

```text
Mobile/wbws_flutter_app/lib/services/local_db.dart
```

The exercised production paths included:

```text
LocalDb.database / _initDb / openDatabase
production onUpgrade v36 -> v37 callback
persistLocalSession
saveAttendanceLocal
claimNextLegacyOperation
settleLegacyOperation
enqueueHymnOp
claimNextHymnOperation
settleHymnOperation
_openSyncAttempt / _closeSyncAttempt
activeSessionMatches / requireActiveOwnerBinding
```

The test did not instantiate `LocalDbTest`, `TestLocalDb`, a fake database, a
second production architecture, or a duplicate production migration.

## 9. v36 → v37 Migration Result

**PASS.** The fixture was opened first as SQLite user version 36 with the
v36-shaped `sync_attempts` table deliberately lacking `execution_source`. The
next open was performed by production `LocalDb` at schema version 37.

Observed through the real opened database:

```text
PRAGMA table_info(sync_attempts): execution_source present
PRAGMA user_version:             37
```

This proves the production `onUpgrade` branch, its table probe, column probe,
and additive `ALTER TABLE` executed. Existing rows were not rewritten or
fabricated.

## 10. LocalDb Claim Result

**PASS.** Production `saveAttendanceLocal` created a two-record attendance
operation with one stable `client_op_id`, owner 101, and authorization version
3. Production `claimNextLegacyOperation` then:

```text
returned a coherent two-record LegacyClaimSnapshot
changed both outbox records to in_flight
incremented attempt_count to 1
created exactly one sync_attempts row
recorded the same client_op_id and owner/scope lineage
```

A second due claim after a retry created attempt number 2 for the same
operation, and the accepted settlement changed both records to `synced`.

## 11. Hymn Claim Result

**PASS.** Production `enqueueHymnOp('hymn_save', ...)` created a real shared
hymn outbox row. Production `claimNextHymnOperation` then:

```text
returned the real HymnOutboxClaim
changed the row to in_flight
incremented attempt_count to 1
created one sync_attempts row in domain pending_hymn_ops
preserved the operation creator's owner/scope provenance
```

The attempt was labelled `background` only because the test explicitly passed
`SyncExecutionSource.background` to the production claim method. No source was
inferred from the test runner or from ownership.

## 12. Hymn Settlement Result

**PASS.** Production `settleHymnOperation` accepted the exact hymn claim and
changed the row to:

```text
sync_state = synced
synced = 1
```

The same production settlement transaction closed the corresponding hymn
attempt with the supplied completion decision, HTTP status, server reference,
and `IDEMPOTENCY_REPLAY` category. A repeated stale-session settlement was
rejected as `supersededSession` and left the already-settled row unchanged.

## 13. Attempt Lineage Result

**PASS.** The shared production `sync_attempts` ledger contained:

```text
attendance operation: attempt 1, attempt 2
hymn operation:       attempt 1
```

The attendance rows shared one `client_op_id`, had unique attempt numbers and
unique attempt UIDs, and preserved the first retry outcome when attempt 2 later
succeeded. The hymn row used the same ledger and the `pending_hymn_ops` domain.

The recorded `entity_ref` was the production natural key only. The test did
not observe payload contents, member rows, credentials, or message bodies in
`entity_ref`.

## 14. Execution Source Result

**PASS.** The production ledger recorded:

```text
legacy attempt 1: foreground
legacy attempt 2: foreground
hymn attempt 1:   background
```

The values came from the explicit `SyncExecutionSource` arguments passed to the
production claim methods and were written by production `_openSyncAttempt`.
The `SyncExecutionSource` enum and its stable on-disk spellings were not
changed.

## 15. Owner / Authorization Isolation

**PASS.** A pending attendance operation created under owner 101 / authorization
version 3 was tested after the active session changed to owner 202 /
authorization version 4 and generation 8. The production claim returned null;
the operation remained:

```text
sync_state = pending
attempt_count = 0
```

After restoring the original active session, the same exact operation was
claimable by owner 101 / authorization version 3. This proves the session,
owner, authorization-version, and operation predicates operate on the real
SQLite rows rather than only on a source-contract model.

## 16. Retry / Lease Result

**PASS.** The first legacy claim was settled through the production retryable
path with `next_attempt_at = 2026-10-05T08:05:00.000Z`. The row became
`retry_wait`, and the first ledger row closed with:

```text
error_category = NETWORK_UNAVAILABLE
retry_decision = RETRY_SCHEDULED
```

A claim at the due time produced attempt 2. The accepted settlement then closed
attempt 2 with `COMPLETED` and changed the outbox rows to `synced`. No early
claim or attempt-number reuse was observed.

## 17. Transaction Atomicity Result

**PASS for the exercised production transaction boundaries.** The real claim
transactions committed the outbox state transition together with the matching
ledger insert. The real settlement transactions committed the outbox settlement
and matching ledger close together. The observed committed states were
correlated and complete; no half-claimed or half-closed state was produced.

The stale-session settlement path returned `supersededSession` without mutating
the already-settled hymn row or its closed ledger row. This is an atomicity and
isolation assertion over production transaction paths, not a claim that an
Android process-death fault was injected.

## 18. Database Integrity Result

**PASS.** The real SQLite database returned:

```text
PRAGMA integrity_check:   ok
PRAGMA foreign_key_check: empty result
```

The v37 execution-source column, outbox rows, session row, and attempt-ledger
indexes remained readable after all claims and settlements.

## 19. Test Results

Local A.11 and regression results:

```text
focused Dart runtime test:                                  1 passed
full Flutter test suite:                                  588 passed
A.6/A.7/local-storage Python lower-level tests:            119 passed
A.8 background-sync core contract test:                     63 passed
mutation harness:                70 attempted / 70 caught / 0 survived
mutation anchors:                                      70/70 unique
flutter pub get --enforce-lockfile:                              PASS
```

The A.6/A.7 Python tests remain lower-level evidence. They were not relabelled
as production Dart verification.

## 20. Mutation / Anchor Result

The existing mutation harness was run after the A.11 dependency-policy anchor
was updated to allow only the documented FFI binding. It reported:

```text
anchors unique: 70/70
MUTATION: 70 attempted / 70 caught / 0 survived / 0 skipped
```

No production sync mutation was introduced. The harness still covers the
single-drain, source propagation, migration, claim predicates, hymn ledger,
scheduler boundary, and Android producer/receiver contracts.

## 21. A.8 Regression

The A.8 source-contract/background-sync core suite passed locally with 63 tests.
The A.10 baseline remains intact: the full Flutter suite had 587 passes before
A.11 and has 588 after adding the real runtime test. The existing A.8
architecture and protected sync files were not redesigned.

The only A.8-adjacent source-contract adjustment was the old A.10 test that
asserted no test-only dependency could exist. It now permits exactly the
explicit A.11 FFI/sqlite3 test binding and continues rejecting unrelated
scheduler, mocking, and service-locator packages.

## 22. A.9 Android Build Regression

No local Android build was claimed: `flutter doctor -v` reported that the
Android SDK was unavailable. Consequently, no Android runtime or Android build
result is inferred from the FFI run.

A.10's verified Android regression remains the authoritative build evidence:
GitHub Actions run `37345245876` passed the Android debug APK build and A.8
packaged checks. A.11 changed only dev/test dependency resolution and Dart test
coverage; it did not change the Android source path, manifest, Gradle, AGP,
Kotlin, SDK, NDK, or protected Android sync files.

## 23. A.10 Lockfile Regression

**PASS locally.** With the A.11 manifest and lockfile committed locally:

```text
flutter pub get --enforce-lockfile: PASS
```

The production sqflite selections remain at the A.10 baseline. The only new
runtime packages are the exact, test-only FFI and compatible sqlite3 pins
documented in §5. No broad dependency upgrade was performed.

## 24. CI Verification

No new A.11 GitHub Actions run exists because the environment cannot authenticate
the HTTPS push. The final A.10 CI evidence remains:

```text
run 37345245876: all 5 jobs passed
run 37344522032: code-bearing A.10 run passed
```

Those A.10 runs proved the pre-A.11 Flutter/security/Android/lockfile baseline.
The local A.11 runtime results in §19 are the new direct evidence; a post-push
CI run is still required to verify the A.11 dependency and test changes in a
clean GitHub runner.

## 25. Files Changed

A.11 changed these tracked files:

```text
Mobile/wbws_flutter_app/pubspec.yaml
Mobile/wbws_flutter_app/pubspec.lock
Mobile/wbws_flutter_app/test/a11_local_db_runtime_test.dart
tests/security/test_mobile_background_sync_core.py
docs/MOBILE_OFFLINE_SYNC_A11_DART_SQLITE_RUNTIME_VERIFICATION.md
```

The first four implementation/test files are in the local A.11 commits. This
report is the final documentation increment.

## 26. Files Intentionally Unchanged

The following remained unchanged by A.11:

```text
Mobile/wbws_flutter_app/lib/services/local_db.dart
Mobile/wbws_flutter_app/lib/services/local_schema_v34.dart
Mobile/wbws_flutter_app/lib/services/hymn_store.dart
Mobile/wbws_flutter_app/lib/services/sync_execution.dart
Mobile/wbws_flutter_app/lib/services/sync_service.dart
AndroidManifest.xml
MainActivity.kt
BackgroundSyncProducer.kt
BackgroundSyncReceiver.kt
.github/workflows/backend-checks.yml
```

No A.12 cold-start/headless behavior, WorkManager, second Flutter engine,
second isolate, receiver, boot path, or new production migration was added.

## 27. Remaining Findings

1. Android platform SQLite runtime verification is pending an actual emulator or
device executing production `sqflite`/`sqflite_android`.
2. A post-A.11 GitHub Actions run is pending because this environment's HTTPS
remote has no available push credential.
3. The final remote branch is therefore still the A.10 commit until the local
A.11 commits are published. This is a publication blocker, not a Dart/SQLite
runtime failure.
4. Existing A.10 informational package-update and NDK-warning findings remain
unchanged.

## 28. Evidence Classification

```text
DIRECT RUNTIME:
  actual production Dart LocalDb + sqflite API + real SQLite through FFI

LOWER-LEVEL SUPPORTING EVIDENCE:
  A.6/A.7 Python real-SQLite/extracted-control-flow tests
  retained and explicitly not called production Dart verification

SOURCE / MUTATION:
  A.8 architecture contracts and 70/70 mutation anchors

BUILD-ONLY:
  A.9/A.10 Android APK/build evidence; no Android runtime claim

NOT PROVEN:
  Android platform SQLite execution
  post-A.11 remote CI execution until publication succeeds
```

## 29. Exact Verification Commands

Commands used for the direct A.11 evidence:

```bash
cd Mobile/wbws_flutter_app
PATH=/var/tmp/flutter/bin:$PATH flutter pub get --enforce-lockfile
PATH=/var/tmp/flutter/bin:$PATH flutter test test/a11_local_db_runtime_test.dart
PATH=/var/tmp/flutter/bin:$PATH flutter test
```

Commands used for retained lower-level and contract evidence:

```bash
cd /home/user/SSMS
python -m pytest \
  tests/security/test_mobile_v36_to_v37_upgrade.py \
  tests/security/test_mobile_hymn_attempt_ledger.py \
  tests/security/test_mobile_local_storage.py -q -rs
python -m pytest tests/security/test_mobile_background_sync_core.py -q
python tests/mutation/background_sync_core_mutations.py
```

Commands used for remote and final-state verification:

```bash
git fetch origin main
git rev-parse HEAD
git rev-parse origin/main
git status --short --branch
git log --oneline --decorate -4
```

## 30. Git / Push Status

The local working tree is clean, but publication is not complete:

```text
local HEAD:  4a347b3f24eff7014acbf99cd9a41da5463c634a
origin/main: ac554d27c4c8b4f4c56df5ac06301b9e85b04862
unpushed:    A.11 implementation/test commits
```

Each push attempt used the inspected `origin` remote and failed before network
publication with:

```text
fatal: could not read Username for 'https://github.com': No such device or address
```

No uncommitted implementation files remain. The final report commit will also
be local until GitHub authentication is restored. No claim is made that
`HEAD == origin/main`.

## 31. Final A.11 Status

**PASS — DART/SQLITE RUNTIME VERIFIED; ANDROID SQLITE RUNTIME PENDING.**

The requested actual production Dart `LocalDb` verification is complete and
reproducible locally. A.11 is not fully publish-closed: Android runtime
verification and remote publication/CI remain pending. The honest final state
is therefore:

```text
DART SQLITE RUNTIME: VERIFIED
ANDROID SQLITE RUNTIME: PENDING
A.12: NOT STARTED
REMOTE PUSH / POST-A.11 CI: BLOCKED BY MISSING GITHUB HTTPS CREDENTIAL
```
