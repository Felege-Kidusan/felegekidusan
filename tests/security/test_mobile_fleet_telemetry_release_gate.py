"""Static contract tests for the mobile fleet telemetry Stage 0 release gate.

The SQL preflight is intended to run against an authorized staging database. These
checks do not claim that staging or production has been exercised; they ensure
that the committed gate remains read-only, bounded, and aligned with the current
051/059 contracts and runbook.
"""

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
PREFLIGHT = ROOT / "sql/preflight/mobile_fleet_telemetry_preflight.sql"
RUNBOOK = ROOT / "docs/audits/MOBILE_FLEET_TELEMETRY_STAGE0_RELEASE_GATE.md"
TELEMETRY_ROUTE = ROOT / "api/v1/routes/telemetry.php"
TELEMETRY_SERVICE = ROOT / "Mobile/wbws_flutter_app/lib/services/telemetry_service.dart"
SYNC_SERVICE = ROOT / "Mobile/wbws_flutter_app/lib/services/sync_service.dart"
MONITOR_SERVICE = ROOT / "admin/backend/services/ApiSyncAttemptMonitorService.php"


class MobileFleetTelemetryReleaseGateTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.preflight = PREFLIGHT.read_text(encoding="utf-8")
        cls.runbook = RUNBOOK.read_text(encoding="utf-8")
        cls.telemetry_route = TELEMETRY_ROUTE.read_text(encoding="utf-8")
        cls.telemetry_service = TELEMETRY_SERVICE.read_text(encoding="utf-8")
        cls.sync_service = SYNC_SERVICE.read_text(encoding="utf-8")
        cls.monitor_service = MONITOR_SERVICE.read_text(encoding="utf-8")

    def test_preflight_is_temporary_and_read_only_for_business_data(self):
        self.assertIn("CREATE TEMPORARY TABLE ssms_mobile_fleet_preflight", self.preflight)
        self.assertIn("DROP TEMPORARY TABLE IF EXISTS ssms_mobile_fleet_preflight", self.preflight)
        self.assertNotRegex(self.preflight, r"(?im)^\s*CREATE TABLE(?:\s|$)")
        self.assertNotRegex(self.preflight, r"(?im)^\s*ALTER TABLE\b")
        self.assertNotRegex(self.preflight, r"(?im)^\s*TRUNCATE\b")
        self.assertNotRegex(self.preflight, r"(?im)^\s*DELETE FROM\b")
        self.assertNotRegex(self.preflight, r"(?im)^\s*UPDATE\s+[A-Za-z0-9_`]+\s+SET\b")
        self.assertNotIn("INSERT INTO app_", self.preflight)
        self.assertNotIn("INSERT INTO api_", self.preflight)

    def test_preflight_checks_both_migrations_and_all_tables(self):
        for migration in ("'051'", "'059'"):
            self.assertIn(migration, self.preflight)
        for table in (
            "app_installations",
            "app_telemetry_events",
            "app_downloads",
            "api_sync_attempts",
        ):
            self.assertIn(table, self.preflight)
        self.assertIn("information_schema.TABLES", self.preflight)
        self.assertIn("information_schema.COLUMNS", self.preflight)
        self.assertIn("information_schema.STATISTICS", self.preflight)

    def test_preflight_pins_the_reviewed_column_contracts(self):
        for column in (
            "installation_id",
            "event_type",
            "event_data",
            "downloaded_at",
            "attempt_uid",
            "attempt_number",
            "execution_source",
            "request_id",
            "idempotency_state",
            "retry_decision",
            "error_code",
            "started_at",
            "completed_at",
        ):
            self.assertIn(column, self.preflight)
        self.assertIn("18 AS expected_count", self.preflight)
        self.assertIn("7,", self.preflight)
        self.assertIn("20,", self.preflight)

    def test_preflight_pins_required_index_shapes(self):
        for index_name in (
            "idx_app_install_ver",
            "idx_app_events_type_created",
            "idx_app_downloads_ver",
            "idx_sync_attempts_status_started",
            "idx_sync_attempts_request",
            "idx_sync_attempts_created",
        ):
            self.assertIn(index_name, self.preflight)
        self.assertIn("GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX", self.preflight)
        self.assertIn("UNIQUE:", self.preflight)
        self.assertIn("NONUNIQUE:", self.preflight)

    def test_preflight_has_a_deterministic_blocking_verdict(self):
        self.assertIn("result ENUM('PASS', 'BLOCK')", self.preflight)
        self.assertIn("WHERE result = 'BLOCK'", self.preflight)
        self.assertIn("SIGNAL SQLSTATE '45000'", self.preflight)
        self.assertIn("do not sign off deployment", self.preflight)

    def test_runbook_requires_real_authorized_staging_activity(self):
        for phrase in (
            "authorized controlled environment",
            "Do not use production credentials",
            "Do not insert fabricated rows",
            "Controlled real-flow drill",
            "sync_pass_completed",
            "get_sync_attempts",
            "Filter inconsistencies",
            "BLOCKED until this gate is executed in staging",
        ):
            self.assertIn(phrase, self.runbook)
        self.assertIn("mobile_fleet_telemetry_preflight.sql", self.runbook)
        self.assertIn("f437e45f7391795fddb801c3f017cb56f9ed7e94", self.runbook)

    def test_gate_remains_aligned_with_current_producer_and_monitor_boundaries(self):
        self.assertIn("/api/v1/telemetry/heartbeat", self.telemetry_route)
        self.assertIn("sync_pass_completed", self.telemetry_service)
        self.assertIn("recordSyncPass", self.sync_service)
        self.assertIn("recordStart", self.monitor_service)
        self.assertIn("completeWithinTransaction", self.monitor_service)
        self.assertIn("RETENTION_DAYS = 90", self.monitor_service)
        self.assertIn("isApiRateLimited('telemetry_ip'", self.telemetry_route)

    def test_gate_does_not_claim_live_verification(self):
        self.assertIn("UNKNOWN", self.runbook)
        self.assertIn("no authorized staging database is available", self.runbook)
        self.assertIn("must not be treated as a pass", self.runbook)


if __name__ == "__main__":
    unittest.main()
