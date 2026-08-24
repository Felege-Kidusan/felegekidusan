"""Regression checks for privacy-minimized, deployment-managed monitoring."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class ErrorMonitorPrivacyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.monitor = (ROOT / "monitor/error_monitor.php").read_text(encoding="utf-8")
        cls.dashboard = (ROOT / "monitor/index.php").read_text(encoding="utf-8")
        cls.cron = (ROOT / "monitor/uptime_cron.php").read_text(encoding="utf-8")
        cls.migration = (ROOT / "sql/011_monitor_schema.sql").read_text(encoding="utf-8")

    def test_schema_is_managed_only_by_deployment_migration(self):
        self.assertNotIn("CREATE TABLE", self.monitor)
        self.assertNotIn("SHOW TABLES", self.monitor)
        self.assertNotIn("ensureTablesExist", self.monitor)
        self.assertIn("CREATE TABLE IF NOT EXISTS `arkeon_error_log`", self.migration)
        self.assertIn("CREATE TABLE IF NOT EXISTS `arkeon_uptime_log`", self.migration)

    def test_database_connection_is_lazy_on_actual_error(self):
        constructor = self.monitor[
            self.monitor.index("private function __construct"):
            self.monitor.index("private function connectDB")
        ]
        self.assertNotIn("connectDB", constructor)
        self.assertIn("ordinary application requests incur no monitor DB work", self.monitor)

    def test_request_and_session_values_are_not_snapshotted(self):
        self.assertNotIn("sanitizeData($_GET)", self.monitor)
        self.assertNotIn("sanitizeData($_POST)", self.monitor)
        self.assertNotIn("sanitizeData($_SESSION)", self.monitor)
        self.assertIn("'query_fields' => $this->fieldNames($_GET)", self.monitor)
        self.assertIn("'body_fields' => $this->fieldNames($_POST)", self.monitor)
        self.assertIn("getSessionMetadata", self.monitor)
        self.assertIn("actor_ref", self.monitor)
        self.assertIn("privacy_version", self.monitor)

    def test_sensitive_diagnostics_are_redacted_and_old_rows_are_hidden(self):
        for marker in ("Bearer [redacted]", "[email redacted]", "[phone redacted]", "Duplicate entry '[redacted]'"):
            self.assertIn(marker, self.monitor)
        self.assertIn("privacy-versioned metadata", self.dashboard)
        self.assertIn("legacy_data_removed", self.migration)
        self.assertIn("$detail[$field] = null", self.dashboard)

    def test_collector_never_attempts_automatic_mutation(self):
        self.assertIn("MONITOR_AUTO_FIX_ENABLED', false", self.monitor)
        for operation in ("fix_permissions", "fix_missing_directory", "attemptAutoFix", "@chmod", "@mkdir"):
            self.assertNotIn(operation, self.monitor)

    def test_fallback_file_and_retention_work_are_bounded(self):
        self.assertIn("flock($handle, LOCK_EX)", self.monitor)
        self.assertIn("ftruncate($handle, 0)", self.monitor)
        self.assertIn("5242880", self.monitor)
        self.assertNotIn("mt_rand", self.monitor)
        self.assertGreaterEqual(self.cron.count("LIMIT 5000"), 3)
        self.assertIn("INTERVAL 90 DAY", self.cron)

    def test_monitor_insert_binding_keeps_ip_as_string(self):
        self.assertIn("'ssisssissssssssiidsss'", self.monitor)


if __name__ == "__main__":
    unittest.main()
