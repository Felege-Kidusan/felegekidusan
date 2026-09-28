"""Security and contract tests for the First-Party App Telemetry & Fleet Analytics Subsystem."""
import json
import os
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AppTelemetrySecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.access_control = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")
        cls.api_telemetry = (ROOT / "admin/api_telemetry.php").read_text(encoding="utf-8")
        cls.telemetry_service = (
            ROOT / "admin/backend/services/AppTelemetryService.php"
        ).read_text(encoding="utf-8")
        cls.route_telemetry = (
            ROOT / "api/v1/routes/telemetry.php"
        ).read_text(encoding="utf-8")
        cls.super_admin_dashboard = (
            ROOT / "admin/dashboards/super-admin.php"
        ).read_text(encoding="utf-8")
        cls.telemetry_section = (
            ROOT / "admin/dashboards/sections/app_telemetry_section.php"
        ).read_text(encoding="utf-8")
        cls.telemetry_js = (ROOT / "admin/js/app_telemetry.js").read_text(encoding="utf-8")
        cls.flutter_telemetry = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/telemetry_service.dart"
        ).read_text(encoding="utf-8")
        cls.sql_schema = (ROOT / "sql/051_app_telemetry.sql").read_text(encoding="utf-8")

    def test_access_control_mapping_for_telemetry_api(self):
        self.assertIn("'api_telemetry.php'", self.access_control)
        self.assertIn("['super_admin', 'school_admin']", self.access_control)
        self.assertIn("empty($_SESSION['admin_id'])", self.api_telemetry)
        self.assertIn("http_response_code(401)", self.api_telemetry)
        self.assertIn("http_response_code(403)", self.api_telemetry)

    def test_sql_schema_creates_indices_and_privacy_preserving_columns(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `app_installations`", self.sql_schema)
        self.assertIn("`installation_id` VARCHAR(64) NOT NULL", self.sql_schema)
        self.assertIn("`ip_hash` CHAR(64) NOT NULL", self.sql_schema)
        self.assertIn("CREATE TABLE IF NOT EXISTS `app_telemetry_events`", self.sql_schema)
        self.assertIn("CREATE TABLE IF NOT EXISTS `app_downloads`", self.sql_schema)

    def test_api_route_validates_installation_id_and_rate_limits(self):
        self.assertIn("isApiRateLimited('telemetry_ip'", self.route_telemetry)
        self.assertIn("strlen($installId) < 8", self.route_telemetry)
        self.assertIn("strlen($installId) > 64", self.route_telemetry)
        self.assertIn("ON DUPLICATE KEY UPDATE", self.route_telemetry)

    def test_backend_service_computes_adoption_kpis_and_distributions(self):
        self.assertIn("getFleetMetrics", self.telemetry_service)
        self.assertIn("getInstallationsList", self.telemetry_service)
        self.assertIn("getRecentEvents", self.telemetry_service)
        self.assertIn("total_installations", self.telemetry_service)
        self.assertIn("active_today", self.telemetry_service)
        self.assertIn("active_7d", self.telemetry_service)
        self.assertIn("adoption_percentage", self.telemetry_service)

    def test_flutter_telemetry_service_generates_uuid_and_persists_safely(self):
        self.assertIn("FlutterSecureStorage", self.flutter_telemetry)
        self.assertIn("SharedPreferences", self.flutter_telemetry)
        self.assertIn("_generateInstallId", self.flutter_telemetry)
        self.assertIn("recordSyncResult", self.flutter_telemetry)
        self.assertIn("recordCrash", self.flutter_telemetry)
        self.assertIn("recordUpdateDownloaded", self.flutter_telemetry)

    def test_dashboard_embeds_telemetry_section_and_ui_scripts(self):
        self.assertIn("'app_telemetry'", self.super_admin_dashboard)
        self.assertIn("data-section=\"app_telemetry\"", self.super_admin_dashboard)
        self.assertIn("app_telemetry_section.php", self.super_admin_dashboard)
        self.assertIn("app_telemetry.js", self.super_admin_dashboard)
        self.assertIn("AppTelemetryUI", self.telemetry_js)


if __name__ == "__main__":
    unittest.main()
