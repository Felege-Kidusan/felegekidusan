"""Static regression pins for the Failure Intelligence Phase 3 hardening.

Phase 3 is the unified engine + dashboard: one issue registry for the three
failure channels (sync / server_error / crash), a seeded remediation
catalog, recorded failure reports, ingest hooks on every channel, a nightly
reconcile job, and the super-admin "Failure Intelligence" section.
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]


class FailureIntelligencePhase3Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = (ROOT / "sql/064_failure_intelligence.sql").read_text(encoding="utf-8")
        cls.service = (
            ROOT / "admin/backend/services/FailureIssueService.php"
        ).read_text(encoding="utf-8")
        cls.monitor = (
            ROOT / "admin/backend/services/ApiSyncAttemptMonitorService.php"
        ).read_text(encoding="utf-8")
        cls.error_monitor = (ROOT / "monitor/error_monitor.php").read_text(encoding="utf-8")
        cls.telemetry_route = (ROOT / "api/v1/routes/telemetry.php").read_text(encoding="utf-8")
        cls.endpoint = (ROOT / "admin/api_failure_intelligence.php").read_text(encoding="utf-8")
        cls.reconcile = (ROOT / "admin/backend/failure_issue_reconcile.php").read_text(encoding="utf-8")
        cls.section = (
            ROOT / "admin/dashboards/sections/failure_intelligence_section.php"
        ).read_text(encoding="utf-8")
        cls.ui_js = (ROOT / "admin/js/failure_intelligence.js").read_text(encoding="utf-8")
        cls.super_admin = (ROOT / "admin/dashboards/super-admin.php").read_text(encoding="utf-8")
        cls.super_admin_js = (ROOT / "admin/js/super_admin.js").read_text(encoding="utf-8")
        cls.access_control = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")
        cls.api_telemetry = (ROOT / "admin/api_telemetry.php").read_text(encoding="utf-8")

    # ── Migration 064 ──────────────────────────────────────────────────────────

    def test_migration_creates_the_three_tables(self):
        for table in ("failure_issues", "failure_remediation_catalog", "failure_reports"):
            self.assertIn(f"CREATE TABLE IF NOT EXISTS `{table}`", self.migration)
        self.assertIn("UNIQUE KEY `uq_failure_issues_key` (`issue_key`)", self.migration)
        self.assertIn("`status` ENUM('open', 'acknowledged', 'resolved')", self.migration)
        self.assertIn("`regression_count` INT UNSIGNED NOT NULL DEFAULT 0", self.migration)
        self.assertIn("`last_auto_report_at` DATETIME NULL", self.migration)
        self.assertIn("PRIMARY KEY (`source`, `category`)", self.migration)
        self.assertIn("INDEX `idx_failure_reports_issue`", self.migration)

    def test_migration_seeds_the_remediation_catalog(self):
        # Every failure category from the client taxonomy, plus the server
        # error families.
        seeded = re.findall(r"\('(\w+)', '([A-Za-z0-9_ ]+)', '", self.migration)
        categories = {cat for src, cat in seeded if src == "sync"}
        for required in (
            "NETWORK_UNAVAILABLE", "TIMEOUT", "DNS_FAILURE", "TLS_FAILURE",
            "AUTH_EXPIRED", "AUTH_SCOPE_CHANGED", "HTTP_403", "VALIDATION_ERROR",
            "HTTP_404", "PAYLOAD_REJECTED", "IDEMPOTENCY_CONFLICT",
            "IDEMPOTENCY_IN_PROGRESS", "WORKFLOW_REJECTED", "REVISION_CONFLICT",
            "HTTP_429", "SERVER_ERROR", "SERVER_ERROR_REPLAYED",
            "SERVICE_UNAVAILABLE", "LOCAL_DB_ERROR", "SERIALIZATION_ERROR",
            "PROTOCOL_ERROR", "UNKNOWN",
        ):
            self.assertIn(required, categories, f"missing seed for {required}")
        server_families = {cat for src, cat in seeded if src == "server_error"}
        for required in ("Fatal Error", "Parse Error", "Warning", "Uncaught Exception", "FATAL"):
            self.assertIn(required, server_families)
        # Seed refresh is repeat-safe.
        self.assertIn("ON DUPLICATE KEY UPDATE", self.migration)

    # ── The engine ─────────────────────────────────────────────────────────────

    def test_service_records_all_three_channels_never_throwing(self):
        self.assertIn("final class FailureIssueService", self.service)
        for method in (
            "recordSyncFailure",
            "recordServerError",
            "recordCrashIssue",
            "getOverview",
            "getIssues",
            "getIssue",
            "updateIssue",
            "generateReport",
            "getReports",
            "getReport",
            "reconcile",
        ):
            self.assertIn(f"function {method}(", self.service)
        # Every ingest hook swallows everything.
        for method in ("recordSyncFailure", "recordServerError", "recordCrashIssue"):
            body = self.service.split(f"function {method}(", 1)[1]
            body = body.split("\n    public static function", 1)[0]
            self.assertIn("catch (\\Throwable $ignored)", body)
        # User-approved thresholds.
        self.assertIn("AUTO_REPORT_MIN_AFFECTED = 5", self.service)
        self.assertIn("AUTO_REPORT_MIN_OCCURRENCES = 50", self.service)

    def test_upsert_regression_semantics_ordering_is_correct(self):
        # MariaDB evaluates ON DUPLICATE KEY UPDATE assignments left to right:
        # the expressions that read the OLD status must appear BEFORE the
        # status reassignment. This pin fails if someone reorders them.
        upsert_sql = re.search(
            r"INSERT INTO failure_issues.*?ON DUPLICATE KEY UPDATE(.*?)'\n", self.service, re.S
        ).group(1)
        regression_pos = upsert_sql.find("regression_count = regression_count + IF(status")
        status_pos = upsert_sql.find("status = IF(status")
        resolved_pos = upsert_sql.find("resolved_at = IF(status")
        self.assertGreater(regression_pos, -1)
        self.assertGreater(status_pos, -1)
        self.assertGreater(resolved_pos, -1)
        self.assertLess(regression_pos, status_pos, "regression_count must read the OLD status")
        self.assertLess(resolved_pos, status_pos, "resolved_at must read the OLD status")

    def test_fingerprints_are_deterministic_and_low_cardinality(self):
        self.assertIn("md5(strtolower($category . '|' . $code . '|' . $domain . '|' . $operation))", self.service)
        self.assertIn("md5(strtolower($errorType . '|' . $file . '|' . $line))", self.service)
        # Crash issues are keyed by the crash key itself.
        self.assertIn("self::upsert($conn, $crashKey, 'crash', $class, $title);", self.service)

    # ── Ingest hooks ───────────────────────────────────────────────────────────

    def test_sync_monitor_hooks_all_failure_paths(self):
        self.assertIn("private const FAILURE_STATUSES = ['failed', 'rejected'];", self.monitor)
        self.assertIn("function recordFailureIssueForAttempt(", self.monitor)
        # insert() direct failures, completeWithinTransaction, and complete().
        self.assertEqual(self.monitor.count("self::recordFailureIssueForAttempt($conn, $monitorId);"), 2)
        self.assertIn("FailureIssueService::recordSyncFailure($conn, [", self.monitor)
        # The bookkeeping helper is itself never-throw.
        helper = self.monitor.split("function recordFailureIssueForAttempt", 1)[1]
        helper = helper.split("\n    private static function", 1)[0]
        self.assertIn("catch (\\Throwable $ignored)", helper)
        # The lazy class_exists loader keeps old deployments loadable.
        self.assertIn("require_once __DIR__ . '/FailureIssueService.php';", self.monitor)

    def test_error_monitor_hook_is_post_insert_and_never_throwing(self):
        self.assertIn("FailureIssueService::recordServerError($this->db, [", self.error_monitor)
        # The hook must come after the successful arkeon insert, not before.
        insert_pos = self.error_monitor.find("$errorId = $this->db->insert_id;")
        hook_pos = self.error_monitor.find("recordServerError")
        self.assertLess(insert_pos, hook_pos)
        # Never-throw around the hook.
        hook_region = self.error_monitor[insert_pos:insert_pos + 1200]
        self.assertIn("catch (Throwable $ignored)", hook_region)

    def test_telemetry_crash_hook_registered(self):
        self.assertIn("recordCrashIssue(", self.telemetry_route)
        self.assertIn(
            "require_once __DIR__ . '/../../../admin/backend/services/FailureIssueService.php';",
            self.telemetry_route,
        )

    # ── Admin endpoint ─────────────────────────────────────────────────────────

    def test_endpoint_is_super_admin_only_with_csrf_writes(self):
        self.assertIn("!== 'super_admin'", self.endpoint)
        self.assertIn("validateCsrf($csrfToken)", self.endpoint)
        self.assertIn("empty($_SESSION['admin_id'])", self.endpoint)
        for action in (
            "get_failure_overview",
            "get_failure_issues",
            "get_failure_issue",
            "update_failure_issue",
            "generate_failure_report",
            "get_failure_reports",
            "get_failure_report",
        ):
            self.assertIn(f"case '{action}':", self.endpoint)
            self.assertIn(action, self.ui_js)

    def test_endpoint_writes_only_the_failure_tables(self):
        # Reads are GET-only; writes touch failure_issues/failure_reports only.
        for forbidden in (
            "DELETE FROM",
            "TRUNCATE",
        ):
            self.assertNotIn(forbidden, self.endpoint)
        self.assertNotIn("app_telemetry_events", self.endpoint)
        self.assertNotIn("api_sync_attempts", self.endpoint)
        self.assertNotIn("arkeon_error_log", self.endpoint)

    def test_read_only_telemetry_boundary_was_not_extended(self):
        # The decision (documented in the report): api_telemetry.php stays a
        # pure read boundary; failure-intelligence writes live in their own
        # endpoint.
        self.assertNotIn("update_failure_issue", self.api_telemetry)
        self.assertNotIn("generate_failure_report", self.api_telemetry)
        self.assertNotIn("csrf", self.api_telemetry.lower())

    # ── Nightly reconcile job ──────────────────────────────────────────────────

    def test_reconcile_job_is_cli_only_locked_and_fail_closed(self):
        self.assertIn("PHP_SAPI !== 'cli'", self.reconcile)
        self.assertIn("flock($lock, LOCK_EX | LOCK_NB)", self.reconcile)
        self.assertIn("Apply migration 064", self.reconcile)
        self.assertIn("FailureIssueService::reconcile($conn)", self.reconcile)
        self.assertIn("exit(1)", self.reconcile)
        self.assertNotIn("$_GET", self.reconcile)
        self.assertNotIn("$_POST", self.reconcile)

    # ── Dashboard surface ──────────────────────────────────────────────────────

    def test_dashboard_section_is_a_pure_shell(self):
        # Zero server-side queries in the section (the Fleet Analytics
        # preamble pitfall — F7 — is deliberately not repeated).
        self.assertNotIn("SELECT", self.section)
        self.assertNotIn("AppTelemetryService", self.section)
        self.assertNotIn("FailureIssueService", self.section)
        for element_id in (
            "fi-kpi-failure-rate", "fi-kpi-p95", "fi-kpi-attempts-min",
            "fi-kpi-crash-free", "fi-kpi-open-issues",
            "fi-issues-body", "fi-reports-body", "fi-issue-detail", "fi-report-view",
        ):
            self.assertIn(f'id="{element_id}"', self.section)
            self.assertIn(element_id, self.ui_js)

    def test_dashboard_wiring_complete(self):
        self.assertIn('data-section="failure_intelligence"', self.super_admin)
        self.assertIn("sections/failure_intelligence_section.php", self.super_admin)
        self.assertIn("admin/js/failure_intelligence.js", self.super_admin)
        # switchSection hook AND the ALLOWED map (without the map entry the
        # nav button is a no-op).
        self.assertIn("if (id === 'failure_intelligence' && window.FailureIntelligenceUI)", self.super_admin_js)
        self.assertIn("failure_intelligence: 1", self.super_admin_js)
        self.assertIn("'api_failure_intelligence.php' => ['super_admin'],", self.access_control)

    def test_js_ui_escapes_and_uses_the_same_origin_fetch(self):
        self.assertIn("function escapeHtml(", self.ui_js)
        self.assertIn("credentials: 'same-origin'", self.ui_js)
        self.assertIn("getCsrfToken()", self.ui_js)
        # Client-side markdown rendering escapes before converting.
        view_report = self.ui_js.split("function viewReport", 1)[1]
        self.assertIn("escapeHtml(r.report_markdown)", view_report)


if __name__ == "__main__":
    unittest.main()
