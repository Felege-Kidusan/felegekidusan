"""Static safety tests for the mobile telemetry retention job.

The target is deployment-owned CLI work. These tests do not claim that the cron
has been installed or that a staging database has been exercised.
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
JOB = ROOT / "admin/backend/mobile_telemetry_retention.php"
DOC = ROOT / "docs/audits/MOBILE_FLEET_TELEMETRY_RETENTION.md"
PREFLIGHT = ROOT / "sql/preflight/mobile_fleet_telemetry_preflight.sql"


class MobileFleetTelemetryRetentionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.job = JOB.read_text(encoding="utf-8")
        cls.doc = DOC.read_text(encoding="utf-8")
        cls.preflight = PREFLIGHT.read_text(encoding="utf-8")

    def test_job_is_cli_only_locked_and_bounded(self):
        self.assertIn("PHP_SAPI !== 'cli'", self.job)
        self.assertIn("flock($lock, LOCK_EX | LOCK_NB)", self.job)
        self.assertIn("MOBILE_TELEMETRY_RETENTION_DAYS = 90", self.job)
        self.assertIn("MOBILE_TELEMETRY_BATCH_SIZE = 5000", self.job)
        self.assertIn("MOBILE_TELEMETRY_MAX_BATCHES_PER_TABLE = 10", self.job)
        self.assertIn("'backlog_possible'", self.job)
        self.assertNotIn("$_GET", self.job)
        self.assertNotIn("$_POST", self.job)

    def test_only_approved_tables_and_time_columns_are_deleted(self):
        statements = re.findall(r"DELETE FROM `([^`]+)`", self.job)
        self.assertEqual(statements, ["{$table}"])
        self.assertIn("'app_telemetry_events' => 'created_at'", self.job)
        self.assertIn("'app_downloads' => 'downloaded_at'", self.job)
        self.assertNotIn("DELETE FROM `app_installations`", self.job)
        self.assertNotIn("DELETE FROM `api_sync_attempts`", self.job)
        self.assertNotIn("TRUNCATE", self.job.upper())
        self.assertNotIn("DROP TABLE", self.job.upper())
        self.assertIn("ORDER BY `id` ASC LIMIT", self.job)

    def test_job_fails_closed_on_database_or_schema_failure(self):
        self.assertIn("Database connection is unavailable.", self.job)
        self.assertIn("Required telemetry table is unavailable.", self.job)
        self.assertIn("Mobile telemetry retention failed.", self.job)
        self.assertIn("exit(1)", self.job)
        self.assertIn("information_schema.TABLES", self.job)

    def test_job_output_is_aggregate_only_and_lock_contention_is_safe(self):
        self.assertIn("'lock_acquired' => false", self.job)
        self.assertIn("'deleted_events'", self.job)
        self.assertIn("'deleted_downloads'", self.job)
        self.assertNotIn("installation_id", self.job.split("fwrite(STDOUT", 1)[-1])
        self.assertNotIn("event_data", self.job.split("fwrite(STDOUT", 1)[-1])
        self.assertIn("JSON_UNESCAPED_SLASHES", self.job)

    def test_documentation_separates_diagnostic_and_user_data_retention(self):
        for phrase in (
            "90 days",
            "CLI-only",
            "app_telemetry_events",
            "app_downloads",
            "api_sync_attempts",
            "mobile SQLite outbox tables",
            "Do not insert fabricated telemetry",
            "backlog_possible",
            "outside the web root",
        ):
            self.assertIn(phrase, self.doc)
        self.assertIn("Define and verify a retention job before production sign-off", self.preflight)


if __name__ == "__main__":
    unittest.main()
