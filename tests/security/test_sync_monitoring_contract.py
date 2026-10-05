"""Focused contract checks for the server/admin sync-monitoring foundation.

These are source-level checks for the deployment environments that do not have
PHP, MariaDB, or a browser. Runtime/database/browser results remain explicitly
separate in the verification report.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class SyncMonitoringContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.schema = (ROOT / "sql/059_api_sync_attempts.sql").read_text(encoding="utf-8")
        cls.monitor = (ROOT / "admin/backend/services/ApiSyncAttemptMonitorService.php").read_text(encoding="utf-8")
        cls.admin_service = (ROOT / "admin/backend/services/ApiSyncAttemptAdminService.php").read_text(encoding="utf-8")
        cls.middleware = (ROOT / "api/v1/core/middleware.php").read_text(encoding="utf-8")
        cls.response = (ROOT / "api/v1/core/response.php").read_text(encoding="utf-8")
        cls.admin_api = (ROOT / "admin/api_telemetry.php").read_text(encoding="utf-8")
        cls.dashboard = (ROOT / "admin/dashboards/sections/app_telemetry_section.php").read_text(encoding="utf-8")
        cls.ui = (ROOT / "admin/js/app_telemetry.js").read_text(encoding="utf-8")
        cls.api = (ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart").read_text(encoding="utf-8")
        cls.sync = (ROOT / "Mobile/wbws_flutter_app/lib/services/sync_service.dart").read_text(encoding="utf-8")
        cls.docs = (ROOT / "docs/WEB_ADMIN_SYNC_MONITORING.md").read_text(encoding="utf-8")

    def test_schema_is_forward_only_bounded_and_preserves_in_flight_rows(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `api_sync_attempts`", self.schema)
        self.assertIn("`request_id` VARCHAR(64) NOT NULL", self.schema)
        self.assertIn("`attempt_number` INT UNSIGNED NULL", self.schema)
        self.assertIn("`execution_source` VARCHAR(16) NULL", self.schema)
        self.assertIn("KEY `idx_sync_attempts_status_started`", self.schema)
        self.assertIn("status <> 'in_flight'", self.monitor)
        self.assertIn("RETENTION_DAYS = 90", self.monitor)
        self.assertIn("LIMIT 5000", self.monitor)

    def test_ingestion_validates_and_redacts(self):
        self.assertIn("validatedAttemptNumber", self.monitor)
        self.assertIn("validatedSource", self.monitor)
        self.assertIn("safeErrorCode", self.monitor)
        self.assertIn("entityReference", self.monitor)
        self.assertNotIn("response_body", self.monitor)
        self.assertNotIn("Authorization", self.monitor)
        self.assertIn("_fkss_sync_entity_ref", self.response)
        self.assertIn("X-Client-Attempt-Id", self.middleware)
        self.assertIn("X-Request-Id: ' . apiRequestId()", self.middleware)

    def test_idempotency_replay_and_completion_are_observed(self):
        self.assertIn("recordEvent", self.middleware)
        self.assertIn("'replayed',", self.middleware)
        self.assertIn("monitor_id", self.middleware)
        self.assertIn("completeWithinTransaction", self.middleware)
        self.assertIn("ApiSyncAttemptMonitorService::complete", self.middleware)
        self.assertIn("'abandoned'", self.middleware)
        self.assertIn("Idempotency-Replayed: true", self.middleware)

    def test_admin_queries_are_read_only_bounded_and_deterministic(self):
        for action in ("get_sync_overview", "get_sync_attempts", "get_sync_attempt"):
            self.assertIn(f"case '{action}'", self.admin_api)
        self.assertIn("header('Allow: GET')", self.admin_api)
        self.assertIn("MAX_PAGE = 1000", self.admin_service)
        self.assertIn("MAX_LIMIT = 100", self.admin_service)
        self.assertIn("ORDER BY started_at DESC, id DESC", self.admin_service)
        self.assertIn("not_server_observable", self.admin_service)
        self.assertIn("'pending' => null", self.admin_service)
        self.assertIn("'retrying' => null", self.admin_service)
        self.assertNotIn("DELETE FROM api_sync_attempts", self.admin_api)
        self.assertNotIn("force", self.admin_api.lower())
        self.assertNotIn("retry", self.admin_api.lower().replace("retry_decision", ""))

    def test_existing_admin_boundary_is_extended_not_replaced(self):
        self.assertIn("$_SESSION['admin_role']", self.admin_api)
        self.assertIn("['super_admin', 'school_admin']", self.admin_api)
        self.assertIn("sync-monitor-table-body", self.dashboard)
        self.assertIn("sync-monitor-source-filter", self.dashboard)
        self.assertIn("SyncMonitorUI", self.ui)
        self.assertIn("get_sync_attempt", self.ui)

    def test_only_narrow_sync_calls_send_new_observability_headers(self):
        self.assertIn("X-Client-Attempt-Number", self.api)
        self.assertIn("X-Execution-Source", self.api)
        self.assertIn("attemptNumber: claim.attemptCount", self.sync)
        self.assertIn("executionSource: source.storageValue", self.sync)
        self.assertIn("client_op_id", self.api)
        self.assertIn("installation_id", self.docs)
        self.assertIn("NOT YET SERVER-OBSERVABLE", self.docs)

    def test_dashboard_does_not_turn_unobserved_state_into_zero_or_success(self):
        self.assertIn("Not server-observable", self.dashboard)
        self.assertIn("displayCount", self.ui)
        self.assertIn("raw === null || raw === undefined ? '—'", self.ui)
        self.assertIn("pending/retrying", self.docs)
        self.assertNotIn('id="kpi-sync-rate"', self.dashboard)
        self.assertIn("single sync-health surface", self.docs)


if __name__ == "__main__":
    unittest.main()
