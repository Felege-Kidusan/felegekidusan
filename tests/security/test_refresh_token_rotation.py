"""Regression checks for rotating mobile refresh sessions."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class RefreshTokenRotationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.auth_core = (ROOT / "api/v1/core/auth.php").read_text(encoding="utf-8")
        cls.auth_route = (ROOT / "api/v1/routes/auth.php").read_text(encoding="utf-8")
        cls.service = (
            ROOT / "admin/backend/services/RefreshTokenService.php"
        ).read_text(encoding="utf-8")
        cls.migration = (
            ROOT / "sql/010_refresh_token_rotation.sql"
        ).read_text(encoding="utf-8")
        cls.mobile = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart"
        ).read_text(encoding="utf-8")
        cls.mobile_config = (
            ROOT / "Mobile/wbws_flutter_app/lib/utils/config.dart"
        ).read_text(encoding="utf-8")

    def test_access_tokens_are_short_lived_and_refresh_tokens_have_session_ids(self):
        self.assertIn("define('API_TOKEN_EXPIRY', 900)", self.auth_core)
        self.assertIn("API_LEGACY_CLIENT_COMPAT_UNTIL", self.auth_core)
        self.assertIn("API_ROTATION_CLIENT_BUILD", self.auth_core)
        self.assertIn("'jti' => (string)$sessionId", self.auth_core)
        self.assertIn("'fid' => (string)$familyId", self.auth_core)

    def test_rotation_is_atomic_and_detects_reuse(self):
        self.assertIn("LIMIT 1 FOR UPDATE", self.service)
        self.assertIn("consumed_at", self.service)
        self.assertIn("revokeFamily", self.service)
        self.assertIn("state' => 'reused'", self.service)
        self.assertIn("'ssisiss'", self.service)
        self.assertIn("Refresh token reuse detected", self.auth_route)

    def test_legacy_tokens_have_a_one_time_compatibility_exchange(self):
        self.assertIn("api_refresh_legacy_exchanges", self.migration)
        self.assertIn("exchangeLegacy", self.service)
        self.assertIn("INSERT IGNORE INTO api_refresh_legacy_exchanges", self.service)

    def test_logout_revokes_server_session_family(self):
        self.assertIn("$action === 'logout'", self.auth_route)
        self.assertIn("revokePresented", self.auth_route)
        self.assertIn("/auth/logout", self.mobile)

    def test_mobile_refresh_is_single_flight_and_persists_rotated_token_first(self):
        self.assertIn("Future<bool>? _refreshInFlight", self.mobile)
        self.assertIn("final existing = _refreshInFlight", self.mobile)
        refresh_write = self.mobile.index("key: AppConfig.refreshTokenKey, value: nextRefreshToken")
        access_write = self.mobile.index("key: AppConfig.tokenKey, value: nextToken")
        self.assertLess(refresh_write, access_write)
        self.assertIn("_notifyIfRefreshRejected", self.mobile)
        self.assertIn("'X-App-Build': '${AppConfig.appBuild}'", self.mobile)
        self.assertIn("appBuild = 17", self.mobile_config)

    def test_schema_is_deployment_managed(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `api_refresh_sessions`", self.migration)
        self.assertNotIn("CREATE TABLE", self.service)
        self.assertNotIn("CREATE TABLE", self.auth_route)


if __name__ == "__main__":
    unittest.main()
