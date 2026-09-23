"""Static contracts for typed auth, hymn, communication, and replay errors.

Real database behavior remains a staging integration gate. These pins ensure the
server exposes stable machine evidence without changing mobile behavior in the
server-only commit.
"""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class TypedApiFailureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.auth_core = (ROOT / "api/v1/core/auth.php").read_text(encoding="utf-8")
        cls.auth_route = (ROOT / "api/v1/routes/auth.php").read_text(encoding="utf-8")
        cls.refresh = (
            ROOT / "admin/backend/services/RefreshTokenService.php"
        ).read_text(encoding="utf-8")
        cls.mezmur_route = (ROOT / "api/v1/routes/mezmur.php").read_text(
            encoding="utf-8"
        )
        cls.hymn_service = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.media_service = (
            ROOT / "admin/backend/services/MezmurMediaService.php"
        ).read_text(encoding="utf-8")
        cls.notifications_route = (
            ROOT / "api/v1/routes/notifications.php"
        ).read_text(encoding="utf-8")
        cls.notifications_service = (
            ROOT / "admin/backend/services/NotificationCenterService.php"
        ).read_text(encoding="utf-8")
        cls.middleware = (ROOT / "api/v1/core/middleware.php").read_text(
            encoding="utf-8"
        )

    def test_refresh_token_verification_distinguishes_signed_expiry(self):
        self.assertIn("function verifyTokenState($token): array", self.auth_core)
        self.assertIn(
            "return ['state' => 'expired', 'token_type' => (string)$payload['typ']]",
            self.auth_core,
        )
        self.assertIn("$verification = verifyTokenState($refreshToken)", self.auth_route)
        self.assertIn("($verification['token_type'] ?? '') !== 'refresh'", self.auth_route)
        self.assertIn("['code' => 'REFRESH_EXPIRED']", self.auth_route)
        # General access-token verification keeps its null-on-expiry contract.
        self.assertIn("$result = verifyTokenState($token)", self.auth_core)
        self.assertIn("=== 'valid' ? $result['payload'] : null", self.auth_core)

    def test_refresh_rotation_returns_the_complete_structured_taxonomy(self):
        for code in (
            "INVALID_REFRESH_TOKEN",
            "REFRESH_EXPIRED",
            "REFRESH_REUSED",
            "SESSION_REVOKED",
            "ACCOUNT_DISABLED",
            "ACCOUNT_REMOVED",
            "AUTH_SERVICE_UNAVAILABLE",
        ):
            self.assertIn(f"['code' => '{code}']", self.auth_route)
        for state in (
            "reused",
            "expired",
            "revoked",
            "account_disabled",
            "account_removed",
            "unavailable",
        ):
            self.assertIn(f"'state' => '{state}'", self.refresh)
        self.assertIn("$rotationState =", self.auth_route)
        self.assertNotIn("str_contains($rotation", self.auth_route)

    def test_refresh_account_state_is_not_flattened_to_invalid(self):
        self.assertIn("function findUserForUpdate", self.refresh)
        self.assertIn(
            "SELECT id, username, full_name, role, is_active, authorization_version FROM users",
            self.refresh,
        )
        self.assertNotIn("findActiveUserForUpdate", self.refresh)
        self.assertIn("(int)$user['is_active'] !== 1", self.refresh)
        self.assertIn("return ['state' => 'account_removed'];", self.refresh)
        self.assertIn("return ['state' => 'account_disabled'];", self.refresh)
        # Refreshing an active user intentionally returns current role/version;
        # a role/version mismatch is not a refresh rejection.
        refresh_block = self.auth_route[self.auth_route.index("$action === 'refresh-token'") :]
        self.assertNotIn("AUTH_SCOPE_CHANGED", refresh_block)
        self.assertIn("$user['authorization_version']", refresh_block)

    def test_mezmur_revision_conflict_is_distinct_and_canonical(self):
        self.assertIn("function mezmurWriteError(array $result): void", self.mezmur_route)
        self.assertIn("is_array($item)", self.mezmur_route)
        self.assertIn("$code === 'REVISION_CONFLICT'", self.mezmur_route)
        self.assertIn("$isRevisionConflict && !is_array($item)", self.mezmur_route)
        self.assertIn("Current hymn state could not be loaded. Please retry.", self.mezmur_route)
        self.assertIn("$code = 'REVISION_CONFLICT'", self.mezmur_route)
        self.assertIn("$extra['data'] = ['item' => $item]", self.mezmur_route)
        self.assertIn("$code === 'REVISION_CONFLICT' ? 409", self.mezmur_route)
        self.assertGreaterEqual(
            self.hymn_service.count("'code' => 'REVISION_CONFLICT'"), 2
        )
        self.assertGreaterEqual(self.mezmur_route.count("mezmurWriteError($result)"), 7)

    def test_mezmur_write_errors_have_stable_operation_codes(self):
        for code in (
            "REVISION_CONFLICT",
            "VALIDATION_FAILED",
            "TARGET_NOT_FOUND",
        ):
            self.assertIn(f"'{code}'", self.mezmur_route)
        self.assertIn("['code' => 'FORBIDDEN']", self.mezmur_route)
        self.assertIn("$code === 'TARGET_NOT_FOUND' ? 404 : 422", self.mezmur_route)
        self.assertGreaterEqual(
            self.hymn_service.count("'code' => 'TARGET_NOT_FOUND'"), 8
        )
        self.assertIn("'code' => 'TARGET_NOT_FOUND'", self.media_service)
        # Shared middleware 409s keep their own meanings and never flow through
        # the hymn revision resolver.
        self.assertIn("'code' => 'IDEMPOTENCY_CONFLICT'", self.middleware)
        self.assertIn("'code' => 'IDEMPOTENCY_IN_PROGRESS'", self.middleware)

    def test_communication_send_statuses_are_typed(self):
        expected = {
            "MESSAGE_EMPTY": 422,
            "MESSAGE_TOO_LONG": 422,
            "THREAD_FORBIDDEN": 403,
            "MESSAGE_SEND_UNAVAILABLE": 500,
        }
        for code, status in expected.items():
            self.assertIn(f"'code' => '{code}'", self.notifications_service)
            self.assertIn(f"'{code}' => {status}", self.notifications_route)
        send = self.notifications_service[
            self.notifications_service.index("public static function sendMessage") :
            self.notifications_service.index("private static function normalizeClientTag")
        ]
        self.assertIn("catch (\\Throwable $e)", send)
        self.assertNotIn("catch (\\Exception $e)", send)
        self.assertIn("$sendStatuses[$sendCode]", self.notifications_route)
        self.assertNotIn("err($result['error'] ?? 'Could not send.');", self.notifications_route)

    def test_tagged_message_never_degrades_when_migration_046_is_missing(self):
        send = self.notifications_service[
            self.notifications_service.index("public static function sendMessage") :
            self.notifications_service.index("private static function normalizeClientTag")
        ]
        gate = send.index("$clientTag !== null && !self::messagesHaveClientTag($conn)")
        tagged_insert = send.index("INSERT INTO messages (thread_id, sender_id, body, client_tag)")
        tagless_insert = send.index("INSERT INTO messages (thread_id, sender_id, body) VALUES")
        self.assertLess(gate, tagged_insert)
        self.assertLess(gate, tagless_insert)
        self.assertIn("Message retry protection is temporarily unavailable.", send)

    def test_idempotency_replay_retains_status_body_and_evidence_header(self):
        replay = self.middleware[
            self.middleware.index("if (($result['state'] ?? '') === 'replay')") :
            self.middleware.index("if (($result['state'] ?? '') === 'conflict')")
        ]
        self.assertIn("http_response_code((int)($result['status_code'] ?? 200))", replay)
        self.assertIn("header('Idempotency-Replayed: true')", replay)
        self.assertIn("echo (string)($result['body'] ?? '')", replay)

        store = self.middleware[
            self.middleware.index("function apiIdempotencyStore") :
            self.middleware.index("/**\n * Atomic API rate limiting")
        ]
        self.assertIn("if ($code === 429)", store)
        self.assertIn("->abandon(", store)
        self.assertIn("->complete($pack['reservation'], $json, $code)", store)
        self.assertNotIn("$code >= 500", store)
        self.assertNotIn("$code >= 400", store)

    def test_idempotency_in_progress_preserves_retry_after(self):
        self.assertIn("header('Retry-After: '", self.middleware)
        self.assertIn("['code' => 'IDEMPOTENCY_IN_PROGRESS']", self.middleware)


if __name__ == "__main__":
    unittest.main()
