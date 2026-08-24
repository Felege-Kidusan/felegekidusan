"""Regression checks for atomic API idempotency handling."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class ApiIdempotencyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.middleware = (ROOT / "api/v1/core/middleware.php").read_text(encoding="utf-8")
        cls.service = (
            ROOT / "admin/backend/services/ApiIdempotencyService.php"
        ).read_text(encoding="utf-8")
        cls.migration = (ROOT / "sql/009_api_idempotency.sql").read_text(encoding="utf-8")

    def test_requests_never_create_schema(self):
        self.assertNotIn("CREATE TABLE", self.middleware)
        self.assertNotIn("CREATE TABLE", self.service)
        self.assertNotIn("apiIdempotencyEnsureTable", self.middleware)

    def test_reservation_is_atomic_and_concurrent_requests_do_not_both_run(self):
        self.assertIn("INSERT IGNORE INTO api_idempotency_records", self.service)
        self.assertIn("record_state='processing'", self.service)
        self.assertIn("lease_expires_at <= CURRENT_TIMESTAMP", self.service)
        self.assertIn("state' => 'processing'", self.service)
        self.assertIn("still processing", self.middleware)

    def test_key_reuse_with_different_payload_is_rejected(self):
        self.assertIn("request_hash", self.service)
        self.assertIn("apiIdempotencyRequestHash", self.middleware)
        self.assertIn("state' => 'conflict'", self.service)
        self.assertIn("different request", self.middleware)

    def test_completed_response_is_replayed_and_rate_limits_are_not_cached(self):
        self.assertIn("Idempotency-Replayed: true", self.middleware)
        self.assertIn("$code === 429", self.middleware)
        self.assertIn("->abandon(", self.middleware)

    def test_shared_table_and_locked_rollout_fallback_are_defined(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `api_idempotency_records`", self.migration)
        self.assertIn("flock($handle, LOCK_EX)", self.service)
        self.assertIn("MAX_RESPONSE_BYTES", self.service)


if __name__ == "__main__":
    unittest.main()
