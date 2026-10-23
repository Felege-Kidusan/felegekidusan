# Mobile Offline Sync — S2 Goal A.6: v36 → v37 Upgrade Path Runtime Evidence

**Status:** COMPLETE, with one honestly-stated limitation.
**Baseline:** `713bc13` (S2 Goal A.5) · **Scope:** verification only.
**Production migration changed: NO. Schema changed: NO. No defect found.**

---

## 1. The gap

The v37 **create** path was already exercised against real SQLite. The path
that had never been run is the one **every already-installed device** takes:

```
existing v36 database → onUpgrade(db, 36, 37) → v37 database
```

A failure there is a data-layer failure *on upgrade* — the worst possible
moment to discover it, and invisible to any test that starts from a fresh
v37 database.

---

## 2. The migration, read from source

`LocalDb._open` → `openDatabase(version: localDatabaseSchemaVersion /* 37 */)`,
and the whole v37 transition is one guarded `ALTER`:

```dart
if (oldVersion < 37) {
  if (await _tableExists(db, 'sync_attempts')) {
    final columns = await _columnNames(db, 'sync_attempts');
    if (!columns.contains('execution_source')) {
      await db.execute(
        'ALTER TABLE sync_attempts ADD COLUMN execution_source '
        "TEXT NOT NULL DEFAULT 'foreground'",
      );
    }
  }
}
```

No `CREATE TABLE`, no `CREATE INDEX`, no `PRAGMA`, one statement. sqflite
supplies the surrounding transaction and writes the version itself; the app
never writes a version.

---

## 3. Honest boundary — what was and was not executed

```
actual production Dart onUpgrade executed: NO
real SQLite database used:                 YES
```

**Why not.** `openDatabase` is sqflite. sqflite has no implementation on a
Linux CI host: it needs an Android/iOS platform binding, or
`sqflite_common_ffi` — which is **not** in `pubspec.lock` and cannot be added,
because **F-19 freezes `pubspec.yaml`/`pubspec.lock`**. No Dart test in this
repository opens a database, for exactly this reason. Executing the real Dart
function would require either a forbidden dependency or native Android, both
explicitly out of scope.

**What was done instead.** `tests/security/test_mobile_v36_to_v37_upgrade.py`
runs against a **real `sqlite3` connection** — real DDL, real rows, real
`PRAGMA` introspection, real constraint enforcement — where:

* the **v36 CREATE**, the **five indexes**, the **ALTER** and the **target
  schema version** are all **extracted from the production source at test
  time**, never retyped. Change production and these tests change with it or
  fail.
* the surrounding **Dart control flow is reproduced** in Python, mirroring
  `_tableExists` / `_columnNames` / `!columns.contains(...)`.

A mirror cannot detect a change to the thing it mirrors, so a separate class
(`MigrationSourceContract`) pins the Dart branch, both probes, the absence of
an inverted guard, the one-statement body, and that the production helpers
still issue the SQL the mirror assumes. Mutations **M40/M41** exist precisely
to prove those pins bite.

Using the §16 vocabulary: this is **MIGRATION SQL EXTRACTED FROM PRODUCTION
AND EXECUTED AGAINST REAL SQLITE**, which is stronger than a reproduction and
weaker than running the Dart. **It does not fully close the finding.**

> **Consistency note, offered as a finding rather than a change.** The
> pre-existing *create-path* claim — labelled `VERIFIED BY REAL SQLITE
> RUNTIME` in the A.3 audit — rests on this **identical** mechanism
> (`test_mobile_sync_attempt_ledger.py::_ledger_db()` runs extracted SQL in
> Python `sqlite3`). Either both paths deserve that label or neither does.
> A.6 deliberately does not rewrite the A.3 wording; it records the
> inconsistency so the choice is made deliberately.

---

## 4. The v36 fixture

**Derived from production, not invented.** The shipped
`localSyncAttemptsV36Sql` **already contains `execution_source`** (A.1 added it
so fresh installs get the column without an ALTER). Using that constant as the
"v36" fixture would have made the column probe short-circuit and the test
would have proven nothing — the precise trap of re-testing the create path.

The fixture is therefore the shipped CREATE **minus** the `execution_source`
column and its comment, derived at runtime so it cannot drift. Its fidelity is
established two ways:

1. **Against history.** `git show 5b32fc9:…/local_schema_v34.dart` — the commit
   that introduced the ledger — was diffed against the derived text. They are
   identical apart from a trailing comma, and the five index statements are
   byte-identical. `0ed7d72` (A.1) is the commit that added the column.
2. **Continuously.** `test_the_v36_fixture_matches_the_historical_v36_columns`
   asserts the fixture's live `PRAGMA table_info` equals the recorded
   17-column v36 contract, so a change to the shipped table fails the test
   instead of silently redefining "v36".

The fixture is a real database: `PRAGMA foreign_keys = ON` (as `onConfigure`
does), the shipped indexes, and `PRAGMA user_version = 36`.

**It is populated.** Three representative rows: a completed attempt, a retried
attempt still waiting, and an open attempt with sparse/NULL columns. An empty
v36 database is covered as a *separate* case, not as the only one.

---

## 5. What the upgrade proved

| Area | Result |
|---|---|
| Branch selection | A v36 device enters **exactly one** branch, and it is `< 37` (parsed from the real `onUpgrade`) |
| Migration ran | The ALTER was actually performed (`['alter']`), not skipped |
| Column present | `execution_source` exists after upgrade |
| Type / nullability / default | `TEXT`, `notnull = 1`, `dflt_value = 'foreground'` — read from `PRAGMA table_info` |
| Version transition | `PRAGMA user_version` → `localDatabaseSchemaVersion` (37), read from the constant |
| **Data survival** | All three pre-existing rows **byte-for-byte identical** across all 17 v36 columns |
| Row count | 3 before, 3 after — nothing added or deleted |
| Primary keys | `1, 2, 3` unchanged |
| Backfill | Every pre-existing row reads `foreground` |
| NULLs | `finished_at` / `entity_ref` / `server_ref` still NULL, not rewritten |
| Indexes | All five shipped indexes present after the ALTER |
| Constraints | The unique identity index still rejects a duplicate `(client_op_id, attempt_number)` |
| Integrity | `PRAGMA integrity_check` → `ok` |

**Post-upgrade writes (§9).** A `background` attempt inserts and reads back as
`background`; an insert omitting the column takes the `foreground` default;
`NULL` is rejected by `NOT NULL`; rows partition correctly by
`execution_source`; the operation-lineage query over `op-alpha` still returns
both attempts in order.

**Edge cases the production code actually declares.** An empty v36 database
upgrades cleanly. A v36 install that *already* has the column (created by the
newer `_createSyncAttemptLedger`) is left alone — the probe suppresses the
ALTER and the column is not duplicated, which is the case the production
comment calls out. A database with no ledger table is untouched. A device
already on 37 runs nothing.

Per §10, **no general idempotence requirement was invented**: the repeat case
tested is the one the production guard explicitly exists for, not a claim that
`onUpgrade(36, 37)` may be re-run arbitrarily.

---

## 6. Mutations M36–M41 (new, all caught)

| ID | Mutation | Invariant |
|---|---|---|
| M36 | The v37 migration operation is removed entirely | the migration exists |
| M37 | The migration keys off the wrong source version (`< 36`) | a v36 device must be upgraded |
| M38 | The added column loses `NOT NULL` and its backfill default | column contract |
| M39 | The migration adds the wrong column (`execution_src`) | the right schema element |
| M40 | The column probe is inverted, so the branch silently skips | guard correctness |
| M41 | The table probe is dropped from the v37 branch | guard correctness |

The A.6 suite is **registered in the harness `TEST` list**; without that these
mutations would have been scored against a suite that never looks at the
migration. The permanent **anchor-uniqueness gate** and **baseline gate** are
unchanged and both ran: `anchors unique: 41/41`, `baseline green`.

---

## 7. Verification

| Check | Result |
|---|---|
| New A.6 migration suite | **38 tests, all passing** |
| Python (`tests/security`) | **2113 passed / 926 subtests / 0 skipped** (A.5 baseline 2075, **+38**) |
| Mutation harness | **41 attempted / 41 caught / 0 survived / 0 skipped** (A.5 was 35) |
| Anchor uniqueness | **41/41** · Baseline gate: green |
| F-20 idempotency harness | **67 checks PASS** |
| `git diff --check` | clean |
| CI | the suite runs in the existing **Security and regression suite** job — see §9 |

The create-path suite (`test_mobile_sync_attempt_ledger.py`) is **unchanged**,
so the two paths remain independently visible:

```
CREATE PATH   fresh v37 database   → test_mobile_sync_attempt_ledger.py
UPGRADE PATH  v36 → v37            → test_mobile_v36_to_v37_upgrade.py
```

---

## 8. Scope compliance

```
pubspec changed:              NO
pubspec.lock changed:         NO
native Android changed:       NO
new dependency:               NO
production migration changed: NO
schema changed:               NO
```

Only two files changed: the new test suite, and the mutation harness
(registration + M36–M41). Hymn sync, `HymnStore.pushPending()`, B-2 and the
hymn `execution_source` lineage were not touched.

---

## 9. Status after A.6

```
v36 → v37 SQLite upgrade path:
  MIGRATION SQL EXTRACTED FROM PRODUCTION AND EXECUTED AGAINST REAL SQLITE
  (populated + empty fixtures, data survival and post-upgrade writes proven)
  ACTUAL PRODUCTION DART onUpgrade: NOT EXECUTED — blocked by F-19
```

The S3 blocker is **substantially reduced, not formally closed**. What remains
unproven is narrow and specific: that sqflite invokes this branch as expected
inside its own transaction on a real device. Everything the branch *does* is
now proven against a real database.

**S3 remains NOT READY.** Native Android integration is still pending, the
hymn ledger gap (MEDIUM, observability-only) is still open, and
`SyncExecutionSource.background` still has no producer.

---

## 10. Recommended next phase (one)

**Goal A.7 — close the hymn attempt-ledger gap (A.3 finding §8).**
`claimNextHymnOperation` takes no `executionSource` and opens no
`sync_attempts` row, so hymn operations are invisible to the ledger that A.1
built and that A.6 has now migrated and proven.

Rationale: it is the last remaining non-native blocker, it is squarely inside
the Dart/SQLite area this phase has just put on solid runtime footing, and it
must land **before** background execution is enabled — once a wake-up can
drain hymn work unattended, work that writes no attempt row is work nobody can
account for after the fact. It is explicitly scoped as *observability*, not a
redesign of the hymn execution path (B-2 stays as-is). *Not started.*
