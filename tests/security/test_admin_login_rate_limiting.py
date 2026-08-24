"""Regression checks for atomic admin login throttling."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class AdminLoginRateLimitingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.login = (ROOT / "admin/backend/login.php").read_text(encoding="utf-8")

    def test_shared_atomic_limiter_replaces_ad_hoc_json_counter(self):
        self.assertIn("SecurityRateLimiter.php", self.login)
        self.assertIn("new \\App\\Services\\SecurityRateLimiter", self.login)
        self.assertNotIn("file_put_contents", self.login)
        self.assertNotIn("file_get_contents", self.login)
        self.assertNotIn("$rateFile", self.login)

    def test_both_ip_and_account_dimensions_are_bounded(self):
        self.assertIn("consume('admin-login-ip', $ipAddress, 20, 300)", self.login)
        self.assertIn("consume('admin-login-account', $accountSubject, 5, 300)", self.login)
        self.assertIn("Retry-After", self.login)

    def test_status_is_not_disclosed_until_password_is_verified(self):
        credential_check = self.login.index("!password_verify")
        inactive_check = self.login.index("$user['is_active']")
        self.assertLess(credential_check, inactive_check)

    def test_success_cannot_reset_the_ip_credential_stuffing_bucket(self):
        self.assertIn("clear('admin-login-account'", self.login)
        self.assertNotIn("clear('admin-login-ip'", self.login)

    def test_inputs_are_bounded_before_database_and_hash_work(self):
        max_length = self.login.index("strlen($password) > 4096")
        query = self.login.index("SELECT id, username")
        self.assertLess(max_length, query)


if __name__ == "__main__":
    unittest.main()
