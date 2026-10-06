"""Static regression pins for the Failure Intelligence Phase 1 hardening.

Phase 1 records fleet context (app version, app build, anonymous
installation id) on server-observed sync attempts, so failures become
sliceable by build (regression detection baseline) and joinable to the
telemetry device directory. Everything is additive: columns are NULLable,
headers are validated but never required, and old clients are unaffected.

Pins the migration, the middleware validators, the monitor insert contract
(including a programmatic placeholder/type/variable count check), the admin
reader surface, the dashboard rendering, and the client header wiring.
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]


class FailureIntelligencePhase1Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = (ROOT / "sql/062_sync_attempt_context.sql").read_text(encoding="utf-8")
        cls.middleware = (ROOT / "api/v1/core/middleware.php").read_text(encoding="utf-8")
        cls.monitor = (
            ROOT / "admin/backend/services/ApiSyncAttemptMonitorService.php"
        ).read_text(encoding="utf-8")
        cls.admin_service = (
            ROOT / "admin/backend/services/ApiSyncAttemptAdminService.php"
        ).read_text(encoding="utf-8")
        cls.section = (
            ROOT / "admin/dashboards/sections/app_telemetry_section.php"
        ).read_text(encoding="utf-8")
        cls.ui_js = (ROOT / "admin/js/app_telemetry.js").read_text(encoding="utf-8")
        cls.api_service = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart"
        ).read_text(encoding="utf-8")
        cls.sync_doc = (ROOT / "docs/WEB_ADMIN_SYNC_MONITORING.md").read_text(encoding="utf-8")

    def test_migration_062_is_additive_and_repeat_safe(self):
        self.assertIn("ADD COLUMN IF NOT EXISTS `app_version` VARCHAR(32) NULL", self.migration)
        self.assertIn("ADD COLUMN IF NOT EXISTS `app_build` INT UNSIGNED NULL", self.migration)
        self.assertIn("ADD COLUMN IF NOT EXISTS `installation_id` VARCHAR(64) NULL", self.migration)
        self.assertIn("ADD INDEX IF NOT EXISTS `idx_sync_attempts_build_started`", self.migration)
        self.assertIn("ADD INDEX IF NOT EXISTS `idx_sync_attempts_install_started`", self.migration)
        self.assertNotIn("NOT NULL", self.migration)

    def test_middleware_validates_fleet_context_headers(self):
        for helper in (
            "apiSyncMonitorAppVersion",
            "apiSyncMonitorAppBuild",
            "apiSyncMonitorInstallationId",
        ):
            self.assertIn(f"function {helper}()", self.middleware)
        # UUID shape must mirror the public telemetry route's check.
        self.assertIn("[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab]", self.middleware)
        for key in ("'app_version'", "'app_build'", "'installation_id'"):
            self.assertIn(f"{key} => apiSyncMonitor", self.middleware)
        self.assertIn("X-Installation-Id", self.middleware)

    def test_monitor_insert_contract_is_consistent(self):
        sql = re.search(r"'(INSERT INTO api_sync_attempts.*?)'\n", self.monitor, re.S).group(1)
        for column in ("app_version", "app_build", "installation_id"):
            self.assertIn(column, sql)
        bind = re.search(r"bind_param\(\n\s+'([a-z]+)',\n(.*?)\);\n", self.monitor, re.S)
        types = bind.group(1)
        variables = [v.strip().rstrip(",") for v in bind.group(2).split("\n") if v.strip()]
        placeholders = sql.count("?")
        nows = len(re.findall(r"NOW\(\)", sql))
        columns_sql = re.search(r"\(([^)]+)\)\s*\n\s*VALUES", sql).group(1)
        columns = len([c for c in columns_sql.split(",")])
        self.assertEqual(placeholders, len(types))
        self.assertEqual(placeholders, len(variables))
        self.assertEqual(columns, placeholders + nows)
        self.assertIn("validatedAppVersion", self.monitor)
        self.assertIn("validatedAppBuild", self.monitor)
        self.assertIn("validatedInstallationId", self.monitor)

    def test_admin_reader_exposes_fleet_context(self):
        # Both the list and detail SELECTs carry the new columns.
        self.assertEqual(self.admin_service.count("app_version, app_build, installation_id,"), 2)
        self.assertIn("'app_version' => $row['app_version'] !== null", self.admin_service)
        self.assertIn("'installation_id' => $row['installation_id'] !== null", self.admin_service)
        self.assertIn("byAppBuild", self.admin_service)
        self.assertIn("'by_app_build'", self.admin_service)

    def test_dashboard_renders_app_context(self):
        self.assertIn("<th>App</th>", self.section)
        self.assertIn('id="sync-build-breakdown"', self.section)
        self.assertIn("renderBuildBreakdown", self.ui_js)
        self.assertIn("row.app_version", self.ui_js)
        self.assertIn("['App version', row.app_version || 'not observed']", self.ui_js)
        # Only the sync-monitor table widens to 9 columns.
        self.assertEqual(self.ui_js.count('colspan="9"'), 3)
        self.assertEqual(self.ui_js.count('colspan="8"'), 4)

    def test_client_sends_installation_id_on_sync_writes(self):
        self.assertIn("import 'telemetry_service.dart';", self.api_service)
        self.assertIn("X-Installation-Id", self.api_service)
        # Sent once before the request and re-applied on the 401 refresh path.
        self.assertEqual(self.api_service.count("headers['X-Installation-Id'] = installId;"), 2)
        # Gated to authenticated sync writes, not every request.
        self.assertIn("if (auth && (key.isNotEmpty || attempt.isNotEmpty))", self.api_service)

    def test_sync_monitoring_doc_tells_the_new_truth(self):
        self.assertIn(
            "server-observed only for sync writes sent by app builds",
            self.sync_doc,
        )
        self.assertNotIn("NOT YET SERVER-OBSERVABLE** because the current sync request contract", self.sync_doc)


if __name__ == "__main__":
    unittest.main()
