"""Incremental synchronization: the shared change feed (PHASE A foundation).

These are functional tests, not source greps. Each scenario runs the real
migration (sql/057_sync_change_feed.sql) against a live MariaDB and then
asks the real App\\Services\\SyncChangeFeedService what it sees, so a
passing run means the triggers and cursor actually behave, not that a
reassuring string appears somewhere in a PHP file.

Baseline relevance
------------------
Before this change there was no change feed at all. `attendance` has no
updated_at column (only recorded_at, written once at insert), so no
timestamp keyset could see an edit, and nothing anywhere could see a
delete: a device that cached a row had no way to learn the row had been
corrected or removed on another device. Every assertion below fails on
that baseline, because sync_changes, its triggers and the service class
did not exist.

What is deliberately NOT claimed here: only `attendance` is wired to the
feed in this phase. Other domains still have no delta path.

ENVIRONMENT (mirrors tests/security/test_comm_e2e.py):
  .fkss_env.php   in the repo root, pointing at a DEDICATED test database.
                  The runner creates and drops its own tables in
                  $SSMS_SYNC_DB (default ssms_e2e) — never production.
  php             on PATH, or SSMS_E2E_PHP=/path/to/php (needs mysqli).
"""
import os
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
RUNNER = ROOT / "tests" / "e2e" / "sync_change_feed.php"
MIGRATION = ROOT / "sql" / "057_sync_change_feed.sql"
SERVICE = ROOT / "admin" / "backend" / "services" / "SyncChangeFeedService.php"
ROUTE = ROOT / "api" / "v1" / "routes" / "sync.php"
ENV_FILE = ROOT / ".fkss_env.php"

SYNC_DB = os.environ.get("SSMS_SYNC_DB", "ssms_e2e")


def _php_binary():
    """PHP CLI to use: $SSMS_E2E_PHP wins, else `php` on PATH."""
    override = os.environ.get("SSMS_E2E_PHP", "").strip()
    if override:
        return override if Path(override).is_file() else None
    return shutil.which("php")


def _probe_db(php):
    """Exit 0 = the disposable sync database is reachable."""
    probe = (
        "require %s; "
        "$m = @new mysqli(DB_HOST, DB_USER, DB_PASS, %s); "
        "exit($m->connect_errno ? 3 : 0);" % (repr(str(ENV_FILE)), repr(SYNC_DB))
    )
    proc = subprocess.run([php, "-r", probe], capture_output=True, text=True, timeout=30)
    return proc.returncode == 0


class SyncChangeFeedEndToEndTests(unittest.TestCase):
    """Behavioural scenarios driven through the live runner."""

    @classmethod
    def setUpClass(cls):
        php = _php_binary()
        if not php:
            raise unittest.SkipTest("php CLI not available — sync feed e2e skipped")
        if not RUNNER.is_file():
            raise unittest.SkipTest("tests/e2e/sync_change_feed.php not present")
        if not ENV_FILE.is_file():
            raise unittest.SkipTest(
                ".fkss_env.php not present — sync feed e2e skipped "
                "(dedicated test DB not configured)"
            )
        if not _probe_db(php):
            raise unittest.SkipTest(
                f"sync e2e database '{SYNC_DB}' unreachable — sync feed e2e skipped"
            )
        cls.php = php

    def _run(self, scenario):
        proc = subprocess.run(
            [self.php, str(RUNNER), scenario],
            capture_output=True, text=True, timeout=300, cwd=str(ROOT),
            # The harness creates and drops tables, so it refuses to run
            # unless the caller declares a disposable target. The suite is
            # the authorised caller and says so explicitly.
            env={**os.environ, "SSMS_AUDIT_TESTING": "1", "SSMS_SYNC_DB": SYNC_DB},
        )
        self.assertEqual(
            proc.returncode, 0,
            f"{scenario} exited {proc.returncode}:\n{proc.stdout}\n{proc.stderr}",
        )
        self.assertIn(
            "E2E-VERDICT: PASS", proc.stdout,
            f"{scenario} did not pass:\n{proc.stdout}\n{proc.stderr}",
        )
        return proc.stdout

    def test_insert_through_ordinary_sql_is_recorded(self):
        """A plain INSERT, with nothing sync-aware in the write path,
        still shows up in the feed. This is what triggers buy over a
        service-layer recorder that every endpoint must remember to call."""
        self._run("insert_recorded")

    def test_update_is_recorded_and_noop_update_is_not(self):
        """Edits are discoverable, but re-saving an unchanged sheet must
        not burn a revision and wake every device for nothing."""
        self._run("update_recorded")

    def test_delete_produces_a_tombstone(self):
        """Deletion arrives as an explicit DELETE entry with no payload.
        A device must never infer a deletion from a row's absence in a
        re-downloaded table."""
        self._run("delete_tombstone")

    def test_cursor_returns_only_later_changes_and_replays_identically(self):
        """The cursor is the whole contract: later changes only, and the
        same cursor twice gives the same answer, so a retry after a
        failed local apply is safe."""
        self._run("cursor_semantics")

    def test_pages_are_bounded_and_resumable(self):
        """Responses stay small on weak links, an interrupted sync resumes
        from its last applied cursor, and nothing is skipped or doubled."""
        self._run("pagination")

    def test_unusable_cursor_demands_bootstrap(self):
        """A cursor that is absent, ahead of the server, or older than
        retention cannot be served a complete delta, so it is refused
        rather than answered with a partial one."""
        self._run("bootstrap_required")

    def test_feed_is_permission_scoped(self):
        """A teacher's cursor returns their classes only — including
        tombstones. A cursor must never become a way to read records the
        caller could not fetch directly."""
        self._run("permission_scope")

    def test_canonical_payload_replaces_stale_local_data(self):
        """The server's representation is what lands locally, and the feed
        rows themselves stay pointers (ids and ops), never payloads."""
        self._run("canonical_payload")

    def test_change_on_one_device_reaches_another(self):
        """The end-to-end property this whole phase exists for: device A
        edits, device B's cursor reports it, device B applies the server
        value, then deletion propagates the same way."""
        self._run("two_devices")

    def test_row_changed_then_deleted_in_one_page_is_not_applied_as_live(self):
        """A device reading a page where a row was edited and then removed
        must end up without the row, not with the intermediate value."""
        self._run("change_then_delete")


class SyncChangeFeedContractTests(unittest.TestCase):
    """Properties of the shipped artefacts that the runner cannot assert
    from inside a database session."""

    @classmethod
    def setUpClass(cls):
        cls.migration = MIGRATION.read_text(encoding="utf-8")
        cls.service = SERVICE.read_text(encoding="utf-8")
        cls.route = ROUTE.read_text(encoding="utf-8")

    def test_migration_adds_no_foreign_keys(self):
        """scripts/restore_production_dump.sh asserts an exact FK count
        (39 pre-055 / 42 post-055). A tombstone also has to outlive the
        row it describes, so the feed intentionally references nothing."""
        statements = [
            line for line in self.migration.splitlines()
            if "FOREIGN KEY" in line.upper() and not line.strip().startswith("--")
        ]
        self.assertEqual(
            statements, [],
            "migration 057 must not create foreign keys:\n" + "\n".join(statements),
        )

    def test_migration_is_idempotent(self):
        """Re-running a migration is routine during a staged rollout."""
        self.assertIn("CREATE TABLE IF NOT EXISTS `sync_changes`", self.migration)
        self.assertIn("CREATE TABLE IF NOT EXISTS `sync_feed_state`", self.migration)
        for trigger in ("trg_attendance_sync_ai", "trg_attendance_sync_au", "trg_attendance_sync_ad"):
            self.assertIn(
                f"DROP TRIGGER IF EXISTS `{trigger}`", self.migration,
                f"{trigger} must be dropped before being recreated",
            )

    def test_revision_is_server_generated(self):
        """Cursors must never depend on a device clock, so the revision is
        an AUTO_INCREMENT assigned by the database."""
        self.assertRegex(
            self.migration,
            r"`revision`\s+BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
        )

    def test_feed_is_indexed_for_its_access_patterns(self):
        """Incremental reads scan by revision, scoped reads by class, and
        pruning by age. Without these the feed degrades as it grows."""
        for index in (
            "idx_sync_changes_entity",
            "idx_sync_changes_scope_class",
            "idx_sync_changes_changed_at",
        ):
            self.assertIn(index, self.migration)

    def test_route_refuses_unknown_entities(self):
        """The feed serves an explicit allow-list. It must not become a
        general 'all database changes' endpoint."""
        self.assertIn("Unsupported sync entity", self.route)
        self.assertEqual(
            ["attendance"],
            self._supported_entities(),
            "SUPPORTED_ENTITIES is the allow-list; widening it is a new phase",
        )

    def test_route_honours_the_module_feature_gate(self):
        """Disabling a module must also stop its changes leaking through
        sync, which is a different code path from the module's own route."""
        self.assertIn("FeatureGate::isEnabled('attendance')", self.route)

    def test_route_applies_the_domains_own_role_gate(self):
        """Sync is transport. It must not become a way around the
        authorization the ordinary endpoint enforces."""
        self.assertIn("apiRolesAttendance()", self.route)
        self.assertIn("apiIsClassRestricted($auth)", self.route)

    def _supported_entities(self):
        import re
        match = re.search(
            r"SUPPORTED_ENTITIES\s*=\s*\[(.*?)\]", self.service, re.S
        )
        self.assertIsNotNone(match, "SUPPORTED_ENTITIES not found")
        return re.findall(r"'([a-z_]+)'", match.group(1))


if __name__ == "__main__":
    unittest.main()
