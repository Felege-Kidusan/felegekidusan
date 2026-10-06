"""Static regression pins for the Failure Intelligence Phase 4 hardening.

Phase 4 is alerting: three symptom-based conditions evaluated on a
15-minute CLI schedule — new crash identities (>=5 distinct installs/24h),
build velocity vs the trailing fleet baseline (2x, with noise floors), and
the multi-window sync error budget (both 1h and 6h breaching) — delivered
through the existing notification center (super_admin) and the monitor's
Telegram bot, each payload carrying the remediation one-liner.
"""

from pathlib import Path
import re
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class FailureIntelligencePhase4Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = (ROOT / "sql/065_failure_alerts.sql").read_text(encoding="utf-8")
        cls.service = (
            ROOT / "admin/backend/services/FailureAlertService.php"
        ).read_text(encoding="utf-8")
        cls.job = (ROOT / "admin/backend/failure_alert_check.php").read_text(encoding="utf-8")
        cls.workflow = (ROOT / "admin/backend/workflow.php").read_text(encoding="utf-8")
        cls.endpoint = (ROOT / "admin/api_failure_intelligence.php").read_text(encoding="utf-8")
        cls.section = (
            ROOT / "admin/dashboards/sections/failure_intelligence_section.php"
        ).read_text(encoding="utf-8")
        cls.ui_js = (ROOT / "admin/js/failure_intelligence.js").read_text(encoding="utf-8")

    # ── Migration 065 ──────────────────────────────────────────────────────────

    def test_migration_creates_the_alert_ledger(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `failure_alerts`", self.migration)
        self.assertIn("UNIQUE KEY `uq_failure_alerts_key` (`alert_key`)", self.migration)
        self.assertIn("`kind` ENUM('crash_new', 'velocity', 'sync_budget')", self.migration)
        self.assertIn("`last_sent_at` DATETIME NOT NULL", self.migration)
        self.assertIn("`sent_count` INT UNSIGNED NOT NULL DEFAULT 1", self.migration)

    # ── Thresholds (user-approved defaults are the contract) ──────────────────

    def test_user_approved_thresholds_are_constants(self):
        self.assertIn("CRASH_NEW_MIN_INSTALLS = 5", self.service)
        self.assertIn("VELOCITY_MAX_BUILD_AGE_DAYS = 7", self.service)
        self.assertIn("VELOCITY_BASELINE_DAYS = 30", self.service)
        self.assertIn("VELOCITY_MULTIPLIER = 2.0", self.service)
        self.assertIn("SYNC_BUDGET_RATE_THRESHOLD = 10.0", self.service)
        self.assertIn("COOLDOWN_HOURS_CONDITION = 24", self.service)
        self.assertIn("COOLDOWN_HOURS_BUDGET = 6", self.service)

    def test_all_three_conditions_implemented_with_guards(self):
        for check in ("checkCrashNew", "checkVelocity", "checkSyncBudget"):
            self.assertIn(f"private static function {check}(", self.service)
        # Crash: distinct installs + genuinely-new identity (registry age).
        self.assertIn("COUNT(DISTINCT installation_id) AS installs", self.service)
        self.assertIn("HAVING installs >= ", self.service)
        # Velocity: newness from the all-time build first appearance.
        self.assertIn("MIN(started_at) AS first_seen FROM api_sync_attempts WHERE app_build = ?",
                      self.service)
        self.assertIn("app_build <> ?", self.service)  # baseline excludes the new build
        # Error budget: BOTH windows must breach.
        self.assertIn("windowRate($conn, 1)", self.service)
        self.assertIn("windowRate($conn, 6)", self.service)
        self.assertIn("Multi-window: BOTH windows must breach.", self.service)

    def test_evaluation_never_throws_and_counts_suppressions(self):
        self.assertIn("'suppressed' => 0", self.service)
        # Every condition check is individually wrapped, with distinct
        # failure logs (plus a fourth in the channel sender — that one is
        # pinned separately in the channels test).
        for message in (
            "Failure alert crash_new check failed",
            "Failure alert velocity check failed",
            "Failure alert sync_budget check failed",
        ):
            self.assertEqual(self.service.count(f"error_log('{message}"), 1)
        # Cooldown ledger read before send.
        self.assertIn("SELECT last_sent_at FROM failure_alerts WHERE alert_key = ?", self.service)
        self.assertIn("sent_count = sent_count + 1", self.service)

    def test_channels_reuse_existing_infrastructure(self):
        # Notification center: sendNotification with super_admin targeting.
        self.assertIn("function_exists('sendNotification')", self.service)
        self.assertIn("'target_roles' => ['super_admin']", self.service)
        # The notifications table enum is low/normal/high/urgent — critical
        # must map to urgent, never written as-is.
        self.assertIn("$severity === 'critical' ? 'urgent' : 'high'", self.service)
        # Telegram: identical gating constants to the error monitor.
        self.assertIn("MONITOR_TELEGRAM_ENABLED", self.service)
        self.assertIn("api.telegram.org/bot", self.service)
        self.assertIn("CURLOPT_SSL_VERIFYPEER => true", self.service)
        # Matrix entry registered in the workflow system.
        self.assertIn("'failure_alert' => ['super_admin'],", self.workflow)

    def test_alert_payloads_carry_the_remediation_one_liner(self):
        self.assertIn("remediationLine(", self.service)
        self.assertIn("failure_remediation_catalog", self.service)
        # Issue override wins over the catalog; catalog wins over fallback.
        self.assertIn("issueOverride", self.service)
        for kind in ("crash_new", "velocity", "sync_budget"):
            self.assertIn(f"'{kind}'", self.service)

    # ── CLI job ────────────────────────────────────────────────────────────────

    def test_alert_job_is_cli_only_locked_and_fail_closed(self):
        self.assertIn("PHP_SAPI !== 'cli'", self.job)
        self.assertIn("flock($lock, LOCK_EX | LOCK_NB)", self.job)
        self.assertIn("Apply migrations 064 and 065", self.job)
        self.assertIn("FailureAlertService::evaluate($conn)", self.job)
        self.assertIn("exit(1)", self.job)
        self.assertNotIn("$_GET", self.job)
        self.assertNotIn("$_POST", self.job)

    def test_alert_and_service_files_parse(self):
        # This gate exists because of a real bug it caught in review: a cron
        # schedule written as "star-slash 15" inside the docblock closed the
        # comment early and made the file a parse error.
        php = shutil.which("php")
        if php is None:
            self.skipTest("php CLI not available; static pins still enforced")
        for rel in (
            "admin/backend/services/FailureAlertService.php",
            "admin/backend/failure_alert_check.php",
            "admin/api_failure_intelligence.php",
        ):
            with self.subTest(target=rel):
                result = subprocess.run(
                    [php, "-l", str(ROOT / rel)],
                    capture_output=True, text=True, timeout=60,
                )
                self.assertEqual(result.returncode, 0, result.stderr)

    def test_no_docblock_contains_a_comment_terminator(self):
        # The */15 trap, generalized: no docblock body may contain "*/".
        for rel in (
            "admin/backend/services/FailureAlertService.php",
            "admin/backend/failure_alert_check.php",
            "admin/backend/services/FailureIssueService.php",
            "admin/backend/failure_issue_reconcile.php",
        ):
            src = (ROOT / rel).read_text(encoding="utf-8")
            for m in re.finditer(r"/\*\*(.*?)\*/", src, re.S):
                self.assertNotIn("*/", m.group(1), f"docblock trap in {rel}")

    # ── Admin surface ──────────────────────────────────────────────────────────

    def test_endpoint_exposes_alert_history(self):
        self.assertIn("case 'get_failure_alerts':", self.endpoint)
        self.assertIn("FailureAlertService::getRecentAlerts($conn, $limit)", self.endpoint)

    def test_dashboard_lists_recent_alerts(self):
        self.assertIn('id="fi-alerts-body"', self.section)
        self.assertIn("Recent Alerts", self.section)
        self.assertIn("get_failure_alerts", self.ui_js)
        self.assertIn("loadAlerts();", self.ui_js)
        # All rendering is escaped.
        alerts_fn = self.ui_js.split("function loadAlerts", 1)[1].split("function loadIssues", 1)[0]
        self.assertIn("escapeHtml(a.severity)", alerts_fn)
        self.assertIn("escapeHtml(a.title)", alerts_fn)


if __name__ == "__main__":
    unittest.main()
