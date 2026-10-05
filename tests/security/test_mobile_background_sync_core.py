"""S2 Goal A.1 — architectural invariants of the background sync core.

WHY THESE ARE PYTHON TESTS

The Dart tests in `test/sync_execution_test.dart` prove the behaviour of the
coordinator, but they cannot prove a *negative* about the whole codebase, and
they cannot run in this environment at all (no Flutter SDK, and `pubspec.yaml`
is frozen by F-19 so no sqflite test binding can be added).

The single most important property of this increment is a negative one:

    there is exactly ONE authoritative drain, and background execution
    cannot acquire its own path to the outbox.

That is a whole-repository claim, so it is pinned the same way this suite
already pins other architectural contracts (see test_section_source_of_truth).
These run locally today and in CI.
"""

import os
import re
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, "Mobile", "wbws_flutter_app")
LIB = os.path.join(APP, "lib", "services")


def read(*parts):
    with open(os.path.join(*parts), "r", encoding="utf-8") as handle:
        return handle.read()


def strip_comments(source):
    """Remove // and /* */ comments so prose can never satisfy a test."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    return "\n".join(
        re.sub(r"//.*$", "", line) for line in source.splitlines()
    )


class BackgroundSyncCoreArchitecture(unittest.TestCase):
    def setUp(self):
        self.execution = read(LIB, "sync_execution.dart")
        self.sync_service = read(LIB, "sync_service.dart")
        self.local_db = read(LIB, "local_db.dart")
        self.schema = read(LIB, "local_schema_v34.dart")
        self.attempts = read(LIB, "sync_attempt_models.dart")

    # ── one authoritative path ──────────────────────────────────────────
    def test_exactly_one_legacy_claim_call_site(self):
        """A second claim site would be a second engine reaching the outbox."""
        hits = []
        for folder, _dirs, files in os.walk(os.path.join(APP, "lib")):
            for name in files:
                if not name.endswith(".dart"):
                    continue
                body = strip_comments(read(folder, name))
                for match in re.finditer(r"claimNextLegacyOperation\s*\(", body):
                    # the declaration itself is not a call site
                    prefix = body[max(0, match.start() - 40):match.start()]
                    if "Future<" in prefix:
                        continue
                    hits.append(os.path.join(folder, name))
        self.assertEqual(
            len(hits), 1,
            "expected a single claimNextLegacyOperation call site, found: %r" % hits,
        )
        self.assertTrue(hits[0].endswith("sync_service.dart"))

    def test_sync_all_delegates_to_the_unified_entry_point(self):
        body = strip_comments(self.sync_service)
        self.assertIn("Future<SyncResult> runSyncNow(", body)
        # syncAll must not re-implement the drain; it must call runSyncNow.
        match = re.search(
            r"Future<SyncResult>\s+syncAll\s*\([^)]*\)\s*=>\s*([^;]+);", body, re.S
        )
        self.assertIsNotNone(match, "syncAll should be a thin delegation")
        self.assertIn("runSyncNow", match.group(1))

    def test_no_second_drain_implementation(self):
        body = strip_comments(self.sync_service)
        self.assertEqual(
            len(re.findall(r"Future<SyncResult>\s+_drain\s*\(", body)), 1,
            "more than one _drain implementation would fork the engine",
        )

    # ── execution source reaches the durable ledger ─────────────────────
    def test_execution_source_is_threaded_into_the_claim(self):
        service = strip_comments(self.sync_service)
        self.assertIn("executionSource: source", service,
                      "the drain must hand its source to the claim")
        db = strip_comments(self.local_db)
        self.assertIn("executionSource: executionSource", db,
                      "the claim must hand the source to the attempt ledger")
        self.assertIn("'execution_source': executionSource.storageValue", db,
                      "the source must be written as a durable column value")

    def test_schema_version_and_column(self):
        self.assertIn("const localDatabaseSchemaVersion = 37;", self.schema)
        self.assertIn("execution_source TEXT NOT NULL DEFAULT 'foreground'",
                      self.schema)

    def test_migration_is_guarded_and_additive(self):
        db = strip_comments(self.local_db)
        self.assertIn("if (oldVersion < 37)", db)
        self.assertIn(
            "ALTER TABLE sync_attempts ADD COLUMN execution_source", db
        )
        self.assertIn("columns.contains('execution_source')", db,
                      "adding the column twice must be impossible")
        # An additive migration must never rewrite or drop lineage.
        window = db.split("if (oldVersion < 37)", 1)[1][:1200]
        for forbidden in ("DROP TABLE", "DELETE FROM sync_attempts", "UPDATE sync_attempts"):
            self.assertNotIn(forbidden, window)

    def test_operation_identity_is_still_client_op_id(self):
        self.assertIn("clientOpId", self.attempts)
        body = strip_comments(self.execution)
        for invented in ("operationUuid", "backgroundOpId", "jobId"):
            self.assertNotIn(invented, body,
                             "operation identity must remain client_op_id")

    # ── the boundary stays a boundary ───────────────────────────────────
    def test_execution_core_is_dependency_free(self):
        imports = re.findall(r"^\s*import\s+'([^']+)'", self.execution, re.M)
        self.assertEqual(
            imports, [],
            "sync_execution.dart must import nothing so it stays VM-testable "
            "and platform-agnostic; found %r" % imports,
        )

    def test_no_scheduling_package_was_added(self):
        pubspec = read(APP, "pubspec.yaml")
        for banned in ("workmanager", "android_alarm_manager", "background_fetch"):
            self.assertNotIn(banned, pubspec,
                             "F-19: pubspec must not gain a scheduler package")

    def test_scheduler_request_cannot_carry_payload_or_credentials(self):
        body = strip_comments(self.execution)
        request = body.split("class BackgroundSyncRequest", 1)[1]
        request = request.split("abstract class", 1)[0]
        fields = re.findall(r"final\s+([\w<>,?\s]+?)\s+(\w+);", request)
        names = sorted(name for _type, name in fields)
        self.assertEqual(
            names, ["notBefore", "requiresNetwork"],
            "a scheduler request may describe WHEN to wake, never WHAT to send",
        )
        for leak in ("token", "password", "payload", "records", "userId",
                     "authorization"):
            self.assertNotIn(leak.lower(), request.lower())

    def test_coordinator_owns_no_queue(self):
        body = strip_comments(self.execution)
        coordinator = body.split("class BackgroundSyncCoordinator", 1)[1]
        for queue_like in ("List<", "Queue<", "Map<", "insert(", "sqflite"):
            self.assertNotIn(
                queue_like, coordinator,
                "the coordinator must not hold a second copy of outbox work",
            )

    def test_no_second_retry_clock(self):
        body = strip_comments(self.execution)
        # Backoff maths belongs to outbox_policy, not to the scheduler.
        for banned in ("pow(", "backoff", "retryLadder", "Duration(seconds: 2)"):
            self.assertNotIn(banned, body)

    def test_claim_still_enforces_eligibility_and_isolation(self):
        """This increment must not have weakened the authoritative gate.

        Pinned against the *candidates* SELECT specifically rather than a
        loose window: mutation testing showed that a window-wide search still
        passed when a predicate was deleted from the SELECT, because the same
        text occurs again in the later UPDATE. The precise anchor is what
        makes M5/M6 detectable.
        """
        db = strip_comments(self.local_db)
        claim = db.split("claimNextLegacyOperation", 1)[1]
        start = claim.index("SELECT client_op_id, MIN(id) AS first_id")
        candidates = claim[start:claim.index("ORDER BY", start)]

        self.assertIn(
            "AND (next_attempt_at IS NULL OR next_attempt_at <= ?)", candidates,
            "the claim must admit only work whose retry time has arrived",
        )
        self.assertIn("AND owner_user_id = ?", candidates,
                      "account isolation must be enforced in the claim itself")
        self.assertIn("AND created_authorization_version = ?", candidates,
                      "a stale authorization must not be able to claim work")
        self.assertIn("activeSessionMatches", claim)

    def test_no_native_android_claim_in_dart(self):
        body = strip_comments(self.execution)
        for native in ("MethodChannel", "WorkManager", "androidx"):
            self.assertNotIn(
                native, body,
                "the Dart core must not reference an unverified native layer",
            )


if __name__ == "__main__":
    unittest.main()
