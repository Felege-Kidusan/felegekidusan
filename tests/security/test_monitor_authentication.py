"""Static regression checks for the monitor's privileged access boundary.

These tests intentionally use only Python's standard library so they can run in
minimal deployment/check environments where PHP tooling is unavailable.
"""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class MonitorAuthenticationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.controller = (ROOT / "monitor/index.php").read_text(encoding="utf-8")
        cls.access_service = (
            ROOT / "admin/backend/services/MonitorAccessService.php"
        ).read_text(encoding="utf-8")
        cls.rate_limiter = (
            ROOT / "admin/backend/services/SecurityRateLimiter.php"
        ).read_text(encoding="utf-8")
        cls.migration = (
            ROOT / "sql/008_security_rate_limits.sql"
        ).read_text(encoding="utf-8")

    def test_monitor_requires_the_existing_super_admin_session(self):
        self.assertIn("!isLoggedIn()", self.controller)
        self.assertIn("admin_role'] ?? '') !== 'super_admin'", self.controller)
        self.assertIn("findActiveSuperAdmin((int)($_SESSION['admin_id']", self.controller)
        self.assertNotIn("fetchAll()", self.controller)
        self.assertNotIn("WHERE role = 'super_admin' AND is_active = 1\"", self.controller)

    def test_step_up_is_short_lived_csrf_protected_and_throttled(self):
        self.assertIn("STEP_UP_TTL_SECONDS = 900", self.access_service)
        self.assertIn("validateCsrf($_POST['csrf_token']", self.controller)
        self.assertIn("SecurityRateLimiter", self.access_service)
        self.assertIn("monitor-step-up-ip", self.access_service)
        self.assertIn("monitor-step-up-account", self.access_service)

    def test_monitor_writes_are_post_only(self):
        for action in ("resolve", "delete", "clear_resolved", "test_error"):
            self.assertIn(f"'{action}'", self.controller)
        self.assertIn("$_SERVER['REQUEST_METHOD'] !== 'POST'", self.controller)
        self.assertIn("header('Allow: POST')", self.controller)

    def test_rate_limit_has_atomic_shared_storage_and_locked_fallback(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `security_rate_limits`", self.migration)
        self.assertIn("ON DUPLICATE KEY UPDATE", self.rate_limiter)
        self.assertIn("flock($handle, LOCK_EX)", self.rate_limiter)
        self.assertNotIn("CREATE TABLE", self.rate_limiter)


if __name__ == "__main__":
    unittest.main()
