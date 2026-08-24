"""Regression checks for privileged browser session revalidation."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class AdminSessionGuardTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.config = (ROOT / "config.php").read_text(encoding="utf-8")
        cls.guard = (
            ROOT / "admin/backend/services/AdminSessionGuard.php"
        ).read_text(encoding="utf-8")
        cls.login = (ROOT / "admin/backend/login.php").read_text(encoding="utf-8")
        cls.settings = (ROOT / "admin/api_settings.php").read_text(encoding="utf-8")

    def test_session_cookie_transport_uses_strict_php_mode(self):
        self.assertIn("session.use_strict_mode", self.config)
        self.assertIn("session.use_only_cookies", self.config)
        self.assertIn("session.cookie_httponly", self.config)
        self.assertIn("session.cookie_samesite", self.config)

    def test_active_role_and_password_are_periodically_revalidated(self):
        self.assertIn("REVALIDATE_INTERVAL_SECONDS = 300", self.guard)
        self.assertIn("ABSOLUTE_SESSION_SECONDS = 28800", self.guard)
        self.assertIn("password_hash, is_active", self.guard)
        self.assertIn("credentials_changed", self.guard)
        self.assertIn("$session['admin_role']", self.guard)

    def test_login_initializes_session_version_metadata(self):
        self.assertIn("session_regenerate_id(true)", self.login)
        self.assertIn("AUTH_STARTED_AT", self.login)
        self.assertIn("AUTH_REVALIDATED_AT", self.login)
        self.assertIn("AUTH_PASSWORD_VERSION", self.login)

    def test_password_change_keeps_current_session_and_revokes_mobile_sessions(self):
        self.assertIn("$_SESSION['AUTH_PASSWORD_VERSION'] = hash('sha256', $newHash)", self.settings)
        self.assertIn("UPDATE api_refresh_sessions SET revoked_at", self.settings)
        self.assertIn("session_regenerate_id(true)", self.settings)
        self.assertIn("$log->bind_param", self.settings)
        self.assertNotIn("VALUES ($adminId, '{$_SESSION", self.settings)

    def test_privileged_routes_fail_closed_but_public_pages_can_continue_anonymously(self):
        self.assertIn("_isPrivilegedBrowserArea", self.config)
        self.assertIn("revalidation_unavailable", self.config)
        self.assertIn("Public pages may still need a fresh anonymous CSRF session", self.config)


if __name__ == "__main__":
    unittest.main()
