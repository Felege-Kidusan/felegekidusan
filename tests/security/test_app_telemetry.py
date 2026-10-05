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
        cls.api_response = (ROOT / "api/v1/core/response.php").read_text(encoding="utf-8")
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
        cls.integrity_migration = (
            ROOT / "sql/060_telemetry_integrity_hardening.sql"
        ).read_text(encoding="utf-8")

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
        self.assertIn("getBody(8192)", self.route_telemetry)
        self.assertIn("telemetry_installation", self.route_telemetry)
        self.assertIn("preg_match('/^[0-9a-f]{8}-", self.route_telemetry)
        self.assertIn("hash_hmac('sha256'", self.route_telemetry)
        self.assertIn("TELEMETRY_HASH_SECRET", self.route_telemetry)
        self.assertIn("$allowedEventTypes", self.route_telemetry)
        self.assertIn("Unsupported telemetry event type.", self.route_telemetry)
        self.assertIn("ON DUPLICATE KEY UPDATE", self.route_telemetry)

    def test_telemetry_route_rejects_untyped_or_raw_event_payloads(self):
        self.assertIn("Telemetry event_data must be an object.", self.route_telemetry)
        self.assertIn("Unsupported telemetry event field.", self.route_telemetry)
        self.assertIn("rollout compatibility", self.route_telemetry)
        self.assertIn("legacy_summary_present", self.route_telemetry)
        self.assertIn("Crash telemetry requires a hash key and kind.", self.route_telemetry)
        self.assertIn("dedupe_key", self.route_telemetry)
        self.assertIn("duplicateCrash", self.route_telemetry)
        self.assertIn("$conn->begin_transaction()", self.route_telemetry)
        self.assertIn("$conn->commit()", self.route_telemetry)
        self.assertNotIn("substr($eventDataInput", self.route_telemetry)

    def test_crash_dedupe_migration_is_rerunnable_and_null_for_other_events(self):
        self.assertIn("ADD COLUMN IF NOT EXISTS `dedupe_key`", self.integrity_migration)
        self.assertIn("CHAR(64)", self.integrity_migration)
        self.assertIn("ADD UNIQUE KEY IF NOT EXISTS `uq_app_events_dedupe`", self.integrity_migration)
        self.assertIn("(`installation_id`, `event_type`, `dedupe_key`)", self.integrity_migration)
        self.assertIn("NULL keeps ordinary telemetry events append-only", self.integrity_migration)

    def test_request_body_limit_is_opt_in_and_returns_413(self):
        self.assertIn("function getBody(?int $maxBytes = null)", self.api_response)
        self.assertIn("$maxBytes + 1", self.api_response)
        self.assertIn("Request body too large.", self.api_response)
        self.assertIn("getBody(8192)", self.route_telemetry)

    def test_backend_service_computes_adoption_kpis_and_distributions(self):
        self.assertIn("getFleetMetrics", self.telemetry_service)
        self.assertIn("getInstallationsList", self.telemetry_service)
        self.assertIn("getRecentEvents", self.telemetry_service)
        self.assertIn("total_installations", self.telemetry_service)
        self.assertIn("active_today", self.telemetry_service)
        self.assertIn("active_7d", self.telemetry_service)
        self.assertIn("adoption_percentage", self.telemetry_service)

    def test_sync_pass_counts_reconcile_with_legacy_installation_counters(self):
        self.assertIn("$eventType === 'sync_pass_completed'", self.route_telemetry)
        self.assertIn("'succeeded'", self.route_telemetry)
        self.assertIn("'waiting_retry'", self.route_telemetry)
        self.assertIn("'needs_attention'", self.route_telemetry)
        self.assertIn("100000", self.route_telemetry)
        self.assertIn("$syncPassFail", self.route_telemetry)
        self.assertIn("$eventType === 'sync_pass_completed' ? $syncPassSuccess", self.route_telemetry)
        self.assertIn("$eventType === 'sync_pass_completed' ? $syncPassFail", self.route_telemetry)

    def test_installation_metrics_and_device_list_share_one_cohort_scope(self):
        self.assertIn("appendInstallationFilters", self.telemetry_service)
        self.assertIn("$cohortWhereClause", self.telemetry_service)
        self.assertIn("'installation_basis' => 'last_seen_at cohort'", self.telemetry_service)
        self.assertIn("'counter_basis' => 'lifetime installation counters for the selected cohort'", self.telemetry_service)
        self.assertIn("'range'   => !empty($_GET['range'])", self.api_telemetry)
        self.assertIn("'&range=' + encodeURIComponent(state.range)", self.telemetry_js)
        self.assertIn("Devices in Selected Window", self.telemetry_section)
        self.assertIn("Legacy Crash Counters", self.telemetry_section)

    def test_flutter_telemetry_service_generates_uuid_and_persists_safely(self):
        self.assertIn("FlutterSecureStorage", self.flutter_telemetry)
        self.assertIn("SharedPreferences", self.flutter_telemetry)
        self.assertIn("_generateInstallId", self.flutter_telemetry)
        self.assertIn("recordSyncResult", self.flutter_telemetry)
        self.assertIn("recordCrash", self.flutter_telemetry)
        self.assertIn("recordUpdateDownloaded", self.flutter_telemetry)

    def test_flutter_crash_telemetry_is_hash_only_and_deduplicated_after_success(self):
        crash_log = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/crash_log_service.dart"
        ).read_text(encoding="utf-8")
        main = (ROOT / "Mobile/wbws_flutter_app/lib/main.dart").read_text(encoding="utf-8")
        self.assertIn("sha256.convert(utf8.encode('$header\\n$body'))", crash_log)
        self.assertIn("reportKey", crash_log)
        self.assertIn("_kLastReportedCrashKey", self.flutter_telemetry)
        self.assertIn("prefs.getString(_kLastReportedCrashKey) == key", self.flutter_telemetry)
        self.assertIn("await prefs.setString(_kLastReportedCrashKey, key)", self.flutter_telemetry)
        self.assertIn("crashKey: recentCrash.reportKey", main)
        self.assertNotIn("recordCrash(summary: recentCrash.body)", main)
        self.assertIn("'crash_key': key", self.flutter_telemetry)
        self.assertNotIn("'summary': summary", self.flutter_telemetry)

    def test_dashboard_embeds_telemetry_section_and_ui_scripts(self):
        self.assertIn("'app_telemetry'", self.super_admin_dashboard)
        self.assertIn("data-section=\"app_telemetry\"", self.super_admin_dashboard)
        self.assertIn("app_telemetry_section.php", self.super_admin_dashboard)
        self.assertIn("app_telemetry.js", self.super_admin_dashboard)
        self.assertIn("AppTelemetryUI", self.telemetry_js)


if __name__ == "__main__":
    unittest.main()
