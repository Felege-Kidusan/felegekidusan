"""Regression checks for API throttling security and scalability."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class ApiRateLimitingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.middleware = (ROOT / "api/v1/core/middleware.php").read_text(encoding="utf-8")

    def test_caller_controlled_idempotency_keys_do_not_bypass_limits(self):
        start = self.middleware.index("function isApiRateLimited")
        rate_limit_function = self.middleware[start:]
        self.assertNotIn("apiIdempotencyKey()", rate_limit_function)
        self.assertNotIn("return false;", rate_limit_function)

    def test_api_uses_shared_atomic_rate_limiter(self):
        self.assertIn("SecurityRateLimiter.php", self.middleware)
        self.assertIn("new \\App\\Services\\SecurityRateLimiter", self.middleware)
        self.assertNotIn("api_rate_{$key}.json", self.middleware)
        self.assertNotIn("file_put_contents($file", self.middleware)

    def test_blocked_responses_publish_retry_information(self):
        self.assertIn("header('Retry-After: '", self.middleware)
        self.assertIn("header('X-RateLimit-Limit: '", self.middleware)


if __name__ == "__main__":
    unittest.main()
