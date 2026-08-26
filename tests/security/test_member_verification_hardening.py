"""Regression tests for Fix 4 (H5): member.php enumeration + PII hardening.

Guards:
  - lookups are rate limited per IP BEFORE the members table is touched,
  - throttled visitors get a neutral page (no existence oracle),
  - precise home addresses (woreda / house number / free-text street) are
    never rendered on the public verification page,
  - verification results are never cached.
"""

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class MemberVerificationHardeningTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.page = (ROOT / "member.php").read_text(encoding="utf-8")

    def test_rate_limiter_runs_before_lookup(self):
        limiter_pos = self.page.find("SecurityRateLimiter")
        query_pos = self.page.find("SELECT * FROM members WHERE member_code")
        self.assertGreater(limiter_pos, 0)
        self.assertGreater(query_pos, 0)
        self.assertLess(limiter_pos, query_pos)

    def test_rate_limit_bucket_and_window(self):
        self.assertIn("'member-verify-ip'", self.page)
        # 30 lookups per 600 seconds per IP.
        self.assertIn("consume('member-verify-ip', $ipAddress, 30, 600)", self.page)

    def test_throttled_response_is_neutral(self):
        self.assertIn("renderUnavailableNotice", self.page)
        self.assertIn("temporarily unavailable", self.page)

    def test_precise_address_fields_are_not_disclosed(self):
        # The page must no longer render woreda or house numbers.
        self.assertNotIn("'Wor. '", self.page)
        self.assertNotIn("'H.No '", self.page)
        self.assertNotIn("$member['woreda']", self.page)
        self.assertNotIn("$member['house_number']", self.page)
        self.assertNotIn("$member['address']", self.page)
        # Coarse location stays available.
        self.assertIn("$member['city']", self.page)
        self.assertIn("$member['sub_city']", self.page)

    def test_no_cache_headers(self):
        self.assertIn("Cache-Control: no-store, no-cache, must-revalidate", self.page)
        self.assertIn("X-Content-Type-Options: nosniff", self.page)
        self.assertIn("Referrer-Policy: no-referrer", self.page)

    def test_code_input_is_bounded(self):
        self.assertIn("strlen($lookupCode) > 32", self.page)

    def test_php_syntax(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        completed = subprocess.run(
            [php, "-l", str(ROOT / "member.php")], capture_output=True, text=True
        )
        self.assertEqual(completed.returncode, 0, completed.stdout)


if __name__ == "__main__":
    unittest.main()
