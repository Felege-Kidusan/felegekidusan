"""
Sync-instance epoch (1.6.2+28) — ghost-data prevention after dataset changes
═════════════════════════════════════════════════════════════════════════════
The hymn library on phones is local-first SQLite kept current by an
upsert-by-id delta cursor. Deletions travel only as archived tombstones
inside ONE dataset's timeline, so after a server migration or DB restore,
rows synced from the previous dataset can never be removed by deltas
("ghost hymns" — observed live after the felegekidusan.com cutover), and
a carried-over cursor can skip rows that predate it.

The fix is the industry sync-anchor pattern: the server publishes a
per-dataset identity in GET /app/config; clients bind their local corpus
to it and rebuild when it changes. These tests pin the contract:

  • server — SyncInstanceService: stable id in system_settings,
    race-safe bootstrap, fails open to '' (config endpoint survives a
    DB outage; clients skip the check)
  • route — app/config exposes sync_instance_id and loads the service
  • mobile — hymn_store checks the epoch before pulling deltas and
    purges server-derived rows (protecting pending outbox edits);
    local_db implements the reset transactionally (cursor cleared,
    unprotected corpus deleted, orphaned joins removed)
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile/wbws_flutter_app/lib"


class ServerEpochTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = (
            ROOT / "admin/backend/services/SyncInstanceService.php"
        ).read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/app.php").read_text(encoding="utf-8")

    def test_config_route_publishes_the_dataset_identity(self):
        self.assertIn("'sync_instance_id'", self.route)
        self.assertIn("SyncInstanceService::instanceId", self.route)
        # The service must be loaded by the route that uses it.
        self.assertIn("SyncInstanceService.php", self.route)

    def test_identity_lives_in_the_existing_settings_store(self):
        # No new table: system_settings exists since sql/013 everywhere,
        # so the epoch works on any deployment without a migration.
        self.assertIn("system_settings", self.service)
        self.assertIn("setting_key", self.service)

    def test_bootstrap_is_race_safe_and_idempotent(self):
        # Two concurrent first-reads must converge on ONE value: the
        # INSERT is idempotent and the value is re-SELECTed afterwards,
        # so a race loser returns the winner's id, never its own.
        self.assertIn("ON DUPLICATE KEY UPDATE", self.service)
        self.assertIn("random_bytes", self.service)

    def test_service_fails_open_to_empty(self):
        # A database outage must never break GET /app/config: every
        # failure path returns '' and clients treat that as
        # "epoch unknown — skip the check" (legacy servers, which do not
        # publish the field, behave identically).
        self.assertIn("return '';", self.service)
        self.assertIn("catch (\\Throwable", self.service)

    def test_identity_is_opaque_and_validated(self):
        # The value is a random hex token, validated on read so a
        # corrupted settings row degrades to a regeneration instead of
        # poisoning every client.
        self.assertIn("ctype_xdigit", self.service)


class MobileEpochTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.store = (MOBILE / "services/hymn_store.dart").read_text(encoding="utf-8")
        cls.db = (MOBILE / "services/local_db.dart").read_text(encoding="utf-8")

    def test_pull_changes_checks_the_epoch_before_pulling_deltas(self):
        self.assertIn("_reconcileSyncInstanceEpoch(generation)", self.store)
        # The check must run inside the pull, before the delta cursor
        # is consumed (order matters: purge-then-pull rebuilds).
        self.assertLess(
            self.store.index("_reconcileSyncInstanceEpoch(generation)"),
            self.store.index("getMezmurHymnsChanges(cursor: cursor)"),
        )

    def test_epoch_check_reads_the_public_config_and_never_throws(self):
        self.assertIn("get('/app/config', auth: false)", self.store)
        self.assertIn("'sync_instance_id'", self.store)
        # An absent field (legacy server) is skipped, not fatal.
        self.assertIn("if (serverId.isEmpty) return;", self.store)

    def test_rebuild_protects_pending_local_edits(self):
        # The same protect idiom as delta pulls: ids with unpushed
        # outbox edits survive the purge and are re-pushed.
        self.assertIn("resetHymnSyncForInstanceChange(protect)", self.store)
        self.assertIn("getPendingHymnOps()", self.store)
        self.assertIn("setHymnSyncInstanceId(serverId)", self.store)

    def test_local_reset_is_transactional_and_complete(self):
        self.assertIn("resetHymnSyncForInstanceChange", self.db)
        # The delta cursor is cleared so the rebuild starts from zero.
        self.assertIn(
            "delete('hymn_sync_meta', where: \"key = 'cursor'\")", self.db
        )
        # Unprotected rows go; protected ids stay.
        self.assertIn("id NOT IN ($marks)", self.db)
        # Taxonomy join rows for removed hymns are dropped too.
        self.assertIn("DELETE FROM cached_hymn_categories", self.db)
        self.assertIn("DELETE FROM cached_hymn_zemarians", self.db)

    def test_instance_id_is_stored_beside_the_cursor(self):
        self.assertIn("getHymnSyncInstanceId", self.db)
        self.assertIn("setHymnSyncInstanceId", self.db)
        self.assertIn("key = 'sync_instance_id'", self.db)


if __name__ == "__main__":
    unittest.main()
