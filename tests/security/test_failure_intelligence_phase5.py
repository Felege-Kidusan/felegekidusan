"""Static regression pins for the Failure Intelligence Phase 5 hardening.

Phase 5 closes the release: a read-only staging preflight for migrations
062-065 (schema, indexes, seed, channel dependencies), the reserved-word
fix it surfaced (the failure_reports column `trigger` must always be
backticked in application SQL), and the deploy-order contract.
"""

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class FailureIntelligencePhase5Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.preflight = (
            ROOT / "sql/preflight/failure_intelligence_preflight.sql"
        ).read_text(encoding="utf-8")
        cls.issue_service = (
            ROOT / "admin/backend/services/FailureIssueService.php"
        ).read_text(encoding="utf-8")
        cls.alert_service = (
            ROOT / "admin/backend/services/FailureAlertService.php"
        ).read_text(encoding="utf-8")
        cls.contract = (ROOT / "docs/FAILURE_INTELLIGENCE.md").read_text(encoding="utf-8")
        cls.client_doc = (
            ROOT / "Mobile/wbws_flutter_app/docs/FAILURE_INTELLIGENCE_CLIENT.md"
        ).read_text(encoding="utf-8")
        cls.crash_service = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/crash_log_service.dart"
        ).read_text(encoding="utf-8")
        cls.api_service_dart = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart"
        ).read_text(encoding="utf-8")

    # ── The preflight is read-only for all business data ─────────────────────

    def test_preflight_is_temporary_and_read_only(self):
        self.assertIn("CREATE TEMPORARY TABLE ssms_failure_intel_preflight", self.preflight)
        self.assertIn("DROP TEMPORARY TABLE IF EXISTS ssms_failure_intel_preflight", self.preflight)
        self.assertNotRegex(self.preflight, r"(?im)^\s*CREATE TABLE(?:\s|$)")
        self.assertNotRegex(self.preflight, r"(?im)^\s*ALTER TABLE\b")
        self.assertNotRegex(self.preflight, r"(?im)^\s*TRUNCATE\b")
        self.assertNotRegex(self.preflight, r"(?im)^\s*DELETE FROM\b")
        self.assertNotRegex(self.preflight, r"(?im)^\s*UPDATE\s+[A-Za-z0-9_`]+\s+SET\b")
        # The only INSERTs target the temporary evidence table.
        for forbidden in (
            "INSERT INTO app_",
            "INSERT INTO api_",
            "INSERT INTO failure_",
            "INSERT INTO arkeon_",
            "INSERT INTO notifications",
        ):
            self.assertNotIn(forbidden, self.preflight)

    def test_preflight_covers_all_four_migrations_and_channels(self):
        for migration in ("'062'", "'063'", "'064'", "'065'"):
            self.assertIn(migration, self.preflight)
        for table in (
            "api_sync_attempts",
            "app_telemetry_events",
            "app_crash_signatures",
            "failure_issues",
            "failure_remediation_catalog",
            "failure_reports",
            "failure_alerts",
            "arkeon_error_log",
            "notifications",
        ):
            self.assertIn(table, self.preflight)
        for schema_view in (
            "information_schema.TABLES",
            "information_schema.COLUMNS",
            "information_schema.STATISTICS",
        ):
            self.assertIn(schema_view, self.preflight)

    def test_preflight_pins_the_load_bearing_contracts(self):
        # Column contracts (counts include every consumed column).
        for pin in (
            "'app_version,app_build,installation_id'",
            "SELECT '064', 'failure_issues', 19,",          # failure_issues
            "'id,issue_id,severity,window_start,window_end,trigger,report_markdown,generated_by,created_at'",
            "SELECT '065', 'failure_alerts', 11,",          # failure_alerts
        ):
            self.assertIn(pin, self.preflight)
        # Uniqueness contracts: fingerprints, cooldown keys, crash exact-once.
        for pin in (
            "'UNIQUE:issue_key'",
            "'UNIQUE:alert_key'",
            "'UNIQUE:crash_key'",
            "'UNIQUE:source,category'",
            "'NONUNIQUE:event_type,dedupe_key,installation_id'",
        ):
            self.assertIn(pin, self.preflight)
        # Seed contract: 28 catalog rows (22 sync + 6 server).
        self.assertIn("COUNT(*) >= 28, 'PASS', 'BLOCK'", self.preflight)
        # Deterministic fail-closed verdict.
        self.assertIn("SIGNAL SQLSTATE '45000'", self.preflight)
        self.assertIn("do not sign off deployment", self.preflight)

    # ── Reserved-word fix: `trigger` is always backticked in SQL ─────────────

    def test_reports_trigger_column_is_backticked_everywhere(self):
        # TRIGGER is a reserved word in MySQL/MariaDB: an unquoted reference
        # is a runtime SQL syntax error. All three queries must backtick it.
        self.assertIn("(issue_id, severity, window_start, window_end, `trigger`,", self.issue_service)
        self.assertEqual(self.issue_service.count("r.`trigger`"), 2)
        self.assertNotIn("window_end, trigger,", self.issue_service)
        self.assertNotIn(" r.trigger,", self.issue_service)

    # ── Deploy-order contract ────────────────────────────────────────────────

    def test_contract_documents_the_preflight_and_crons(self):
        self.assertIn("failure_intelligence_preflight.sql", self.contract)
        self.assertIn("failure_issue_reconcile.php", self.contract)
        self.assertIn("failure_alert_check.php", self.contract)
        self.assertIn("0,15,30,45", self.contract)

    def test_alert_service_thresholds_still_the_approved_defaults(self):
        # Guard against accidental threshold drift between phases.
        self.assertIn("CRASH_NEW_MIN_INSTALLS = 5", self.alert_service)
        self.assertIn("VELOCITY_MULTIPLIER = 2.0", self.alert_service)
        self.assertIn("SYNC_BUDGET_RATE_THRESHOLD = 10.0", self.alert_service)
        self.assertIn("COOLDOWN_HOURS_CONDITION = 24", self.alert_service)
        self.assertIn("COOLDOWN_HOURS_BUDGET = 6", self.alert_service)

    # ── Client contract documentation (plan Phase 5: client docs) ───────────

    def test_client_doc_documents_the_installation_header(self):
        self.assertIn("X-Installation-Id", self.client_doc)
        # Scoped to sync writes, reusing the telemetry identity.
        self.assertIn("sync writes", self.client_doc)
        self.assertIn("TelemetryService.instance.getInstallationId()", self.client_doc)
        # The doc may not drift from the actual client code.
        self.assertIn("headers['X-Installation-Id'] = installId", self.api_service_dart)
        self.assertIn(
            "if (auth && (key.isNotEmpty || attempt.isNotEmpty))", self.api_service_dart
        )

    def test_client_doc_signature_caps_match_the_code(self):
        for pin in ("120", "512", "first-party", "crash_log_service.dart",
                    "crash_signature_test.dart"):
            self.assertIn(pin, self.client_doc)
        # The doc's caps must equal the code's constants, by name and value.
        for constant in (
            "_kMaxSignaturePartLength = 120",
            "_kMaxSignatureFrames = 3",
            "_kMaxSignatureJsonBytes = 512",
        ):
            self.assertIn(constant, self.crash_service)
        self.assertIn("At most **3**", self.client_doc)

    def test_client_doc_states_exact_once_guarantee_unchanged(self):
        self.assertIn("exact-once guarantee", self.client_doc)
        self.assertIn("untouched", self.client_doc)
        self.assertIn("Idempotency-Key", self.client_doc)
        # Cross-references the server-side contract document.
        self.assertIn("docs/FAILURE_INTELLIGENCE.md", self.client_doc)


if __name__ == "__main__":
    unittest.main()
