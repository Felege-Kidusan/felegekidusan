"""Static wiring checks for the authorized telemetry Stage 1 drill."""

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
REPORT = ROOT / "sql/preflight/mobile_fleet_telemetry_postdrill.sql"
RUNBOOK = ROOT / "docs/audits/MOBILE_FLEET_TELEMETRY_STAGE1_STAGING_EVIDENCE.md"
STAGE0 = ROOT / "docs/audits/MOBILE_FLEET_TELEMETRY_STAGE0_RELEASE_GATE.md"
RETENTION = ROOT / "admin/backend/mobile_telemetry_retention.php"


class MobileFleetTelemetryStage1Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.report = REPORT.read_text(encoding="utf-8")
        cls.runbook = RUNBOOK.read_text(encoding="utf-8")
        cls.stage0 = STAGE0.read_text(encoding="utf-8")
        cls.retention = RETENTION.read_text(encoding="utf-8")

    def test_postdrill_report_is_read_only_for_application_data(self):
        self.assertIn("CREATE TEMPORARY TABLE ssms_mobile_fleet_postdrill", self.report)
        self.assertIn("DROP TEMPORARY TABLE IF EXISTS ssms_mobile_fleet_postdrill", self.report)
        self.assertNotRegex(self.report, r"(?im)^\s*CREATE TABLE(?:\s|$)")
        self.assertNotRegex(self.report, r"(?im)^\s*ALTER TABLE\b")
        self.assertNotRegex(self.report, r"(?im)^\s*DELETE FROM\b")
        self.assertNotRegex(self.report, r"(?im)^\s*UPDATE\s+[A-Za-z0-9_`]+\s+SET\b")
        self.assertNotIn("INSERT INTO app_", self.report)
        self.assertNotIn("INSERT INTO api_", self.report)

    def test_postdrill_report_pins_the_three_migration_contract(self):
        for migration in ("051", "059", "060"):
            self.assertIn(migration, self.report)
            self.assertIn(migration, self.runbook)
        for token in (
            "dedupe_key",
            "uq_app_events_dedupe",
            "duplicate crash-key groups",
            "api_sync_attempts",
            "JSON_CONTAINS_PATH",
            "90 DAY",
        ):
            self.assertIn(token, self.report)

    def test_report_never_exposes_sensitive_row_values(self):
        for forbidden in (
            "SELECT event_data",
            "SELECT installation_id",
            "SELECT request_id",
            "SELECT client_op_id",
            "SELECT user_id",
            "SELECT token",
        ):
            self.assertNotIn(forbidden, self.report)
        self.assertIn("aggregate-only", self.report)
        self.assertIn("Do not add SELECTs that expose event_data", self.report)

    def test_runbook_requires_real_authorized_activity_and_retention(self):
        for phrase in (
            "authorized staging/disposable database",
            "real launch, sync, retry",
            "Do not record or export",
            "Malformed-input checks",
            "Retention execution",
            "Post-drill reconciliation",
            "sign-off remains blocked",
            "PASS / BLOCKED / UNKNOWN",
        ):
            self.assertIn(phrase, self.runbook)
        self.assertIn("mobile_telemetry_retention.php", self.runbook)
        self.assertIn("sql/preflight/mobile_fleet_telemetry_preflight.sql", self.stage0)

    def test_runbook_wires_the_bounded_retention_job(self):
        self.assertIn("MOBILE_TELEMETRY_BATCH_SIZE = 5000", self.retention)
        self.assertIn("MOBILE_TELEMETRY_MAX_BATCHES_PER_TABLE = 10", self.retention)
        self.assertIn("backlog_possible", self.retention)
        self.assertIn("No `api_sync_attempts` rows may be deleted", self.runbook)


if __name__ == "__main__":
    unittest.main()
