"""Regression checks for browser security headers."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class SecurityHeaderTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.config = (ROOT / "config.php").read_text(encoding="utf-8")
        cls.apache = (ROOT / ".htaccess").read_text(encoding="utf-8")

    def test_dynamic_responses_have_defense_in_depth_headers(self):
        for value in (
            "Content-Security-Policy",
            "Strict-Transport-Security",
            "Permissions-Policy",
            "Cross-Origin-Opener-Policy",
            "X-Content-Type-Options",
            "Referrer-Policy",
        ):
            self.assertIn(value, self.config)

    def test_csp_blocks_high_risk_legacy_features_without_breaking_inline_ui(self):
        for directive in ("base-uri 'self'", "object-src 'none'", "frame-ancestors 'self'", "form-action 'self'"):
            self.assertIn(directive, self.config)
            self.assertIn(directive, self.apache)
        # A script-src directive cannot be enforced globally until remaining
        # legacy inline scripts have been extracted/nonced.
        self.assertNotIn("script-src", self.config)

    def test_hsts_is_only_sent_over_https(self):
        self.assertIn("if (!empty($isHttps))", self.config)
        self.assertIn('"expr=%{HTTPS} == \'on\'"', self.apache)

    def test_obsolete_xss_auditor_is_disabled(self):
        self.assertIn("X-XSS-Protection: 0", self.config)
        self.assertIn('X-XSS-Protection "0"', self.apache)
        self.assertNotIn("X-XSS-Protection: 1", self.config)


if __name__ == "__main__":
    unittest.main()
