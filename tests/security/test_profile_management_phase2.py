"""Phase 2 profile database/backend/API integration security gates."""
from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]


class ProfileManagementPhase2Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.migration = (ROOT / "sql/049_user_profile_image.sql").read_text(encoding="utf-8")
        cls.users = (ROOT / "api/v1/routes/users.php").read_text(encoding="utf-8")
        cls.auth = (ROOT / "api/v1/core/auth.php").read_text(encoding="utf-8")
        cls.middleware = (ROOT / "api/v1/core/middleware.php").read_text(encoding="utf-8")
        cls.profile = (
            ROOT / "admin/backend/services/ProfileService.php"
        ).read_text(encoding="utf-8")
        cls.credentials = (
            ROOT / "admin/backend/services/AccountCredentialService.php"
        ).read_text(encoding="utf-8")
        cls.images = (
            ROOT / "admin/backend/services/ProfileImageService.php"
        ).read_text(encoding="utf-8")
        cls.audit = (
            ROOT / "admin/backend/services/SecurityAuditService.php"
        ).read_text(encoding="utf-8")
        cls.settings = (ROOT / "admin/api_settings.php").read_text(encoding="utf-8")
        cls.web_image = (ROOT / "admin/profile_image.php").read_text(encoding="utf-8")
        cls.user_save = (ROOT / "admin/backend/user-save.php").read_text(encoding="utf-8")
        cls.teachers = (ROOT / "admin/api_teachers.php").read_text(encoding="utf-8")

    def test_migration_is_additive_idempotent_and_exact(self):
        normalized = self.migration.lower()
        self.assertIn("information_schema.columns", normalized)
        self.assertRegex(
            normalized,
            r"add column if not exists `profile_image_path`\s+varchar\(255\) null",
        )
        self.assertIn("column_name = 'profile_image_path'", normalized)
        self.assertIn("data_type = 'varchar'", normalized)
        self.assertIn("character_maximum_length = 255", normalized)
        self.assertIn("is_nullable = 'yes'", normalized)
        self.assertIn("signal sqlstate '45000'", normalized)
        self.assertIn("deliberate rollback", normalized)
        self.assertNotRegex(normalized, r"\b(drop|truncate|delete|update)\s+(table|from|users)")
        self.assertNotIn("authorization_version", normalized)
        self.assertNotIn("password", normalized)
        self.assertNotIn("create index", normalized)

    def test_api_self_service_ownership_and_optimistic_contract(self):
        self.assertIn("$auth = apiRequireAuth()", self.users)
        self.assertIn("$auth['uid']", self.users)
        self.assertIn("fromTrustedUserId($userId)", self.users)
        self.assertIn("$method === 'GET'", self.users)
        self.assertIn("$method === 'PATCH'", self.users)
        self.assertIn("getOwnProfile($identity)", self.users)
        self.assertIn("updateOwnProfile($identity, $body, $profileVersion)", self.users)
        self.assertIn("Profile version is required", self.users)
        self.assertIn("PROFILE_CONFLICT", self.users)
        self.assertIn("reload_required", self.users)
        self.assertIn("$extra['profile']", self.users)
        self.assertIn("profile_image", self.users)
        self.assertIn("assignments", self.users)
        self.assertNotIn("WHERE id = $userId", self.users)
        self.assertNotRegex(self.users, r"UPDATE\s+users\s+SET")

    def test_api_rejects_target_and_security_fields_in_domain_boundary(self):
        allowed = self.profile.split("private const ALLOWED_INPUT_FIELDS", 1)[1].split("];", 1)[0]
        for allowed_field in ("'username'", "'email'", "'full_name'", "'current_password'", "'profile_version'"):
            self.assertIn(allowed_field, allowed)
        for forbidden in (
            "'id'", "'user_id'", "'owner_id'", "'role'", "'is_active'",
            "'authorization_version'", "'member_id'", "'password_hash'",
            "'profile_image_path'",
        ):
            self.assertNotIn(forbidden, allowed)
        self.assertIn("PROFILE_FIELD_NOT_ALLOWED", self.profile)
        self.assertIn("usersApiRejectOwnerParameters", self.users)
        self.assertIn("User ownership is derived from authentication", self.users)

    def test_claim_refresh_signal_is_not_authorization_version_churn(self):
        self.assertIn("SELECT role, is_active, authorization_version, username, full_name", self.auth)
        self.assertIn("PROFILE_CLAIMS_CHANGED", self.auth)
        self.assertIn("claims_refresh_required", self.auth)
        self.assertIn("409", self.auth)
        self.assertIn("claims_refresh_required", self.users)
        self.assertNotRegex(self.profile, r"UPDATE\s+users\s+SET[^;]*authorization_version")
        self.assertIn("define('API_TOKEN_EXPIRY', 900)", self.auth)

    def test_password_endpoints_delegate_to_atomic_credential_service(self):
        change = self.users.split("POST /users/change-password", 1)[-1]
        self.assertIn("AccountCredentialService", change)
        self.assertIn("MysqliCredentialRepository", change)
        self.assertIn("changeOwnPassword", change)
        self.assertNotIn("password_hash(", change)
        self.assertNotRegex(change, r"UPDATE\s+users")
        mutate = self.credentials.split("private function mutate", 1)[1].split(
            "private static function validateSubmittedPassword", 1
        )[0]
        ordered = [
            "$this->credentials->begin()",
            "$this->credentials->updatePasswordHash",
            "$this->credentials->revokeAllRefreshSessions",
            "$this->credentials->commit()",
        ]
        positions = [mutate.index(value) for value in ordered]
        self.assertEqual(positions, sorted(positions))
        self.assertIn("$this->credentials->rollback()", mutate)
        self.assertNotIn("catch (\\Throwable $ignored)", change)

    def test_web_session_password_marker_comes_from_committed_result(self):
        self.assertIn("CredentialMutationResult", self.credentials)
        self.assertIn("passwordVersion()", self.credentials)
        self.assertIn("hash('sha256', $passwordHash)", self.credentials)
        self.assertIn("$_SESSION['AUTH_PASSWORD_VERSION'] = $result->passwordVersion()", self.settings)
        self.assertIn("$_SESSION['AUTH_REVALIDATED_AT'] = time()", self.settings)
        self.assertIn("session_regenerate_id(true)", self.settings)

    def test_only_existing_account_admin_reset_paths_delegate(self):
        self.assertIn("resetPasswordByAdministrator", self.user_save)
        self.assertIn("resetPasswordByAdministrator", self.teachers)
        self.assertIn("_teacherResetExistingPassword", self.teachers)
        self.assertIn("ADMIN_PASSWORD_RESET", self.user_save)
        self.assertIn("ADMIN_PASSWORD_RESET", self.teachers)
        self.assertIn("$credentialResult->passwordVersion()", self.user_save)
        self.assertIn("$_SESSION['admin_username'] = $username", self.user_save)
        self.assertIn("$_SESSION['admin_full_name'] = $fullName", self.user_save)
        self.assertIn("session_regenerate_id(true)", self.user_save)
        self.assertNotIn("password_hash = :password_hash", self.user_save)
        self.assertEqual(
            len(re.findall(r"INSERT INTO users[^;]+password_hash", self.teachers, re.S)),
            2,
        )
        self.assertEqual(
            len(re.findall(r"UPDATE users SET[^\n]+password_hash", self.teachers)),
            0,
        )

    def test_audit_adapter_uses_trusted_actor_and_filters_secrets_recursively(self):
        self.assertIn("final class SecurityAuditActor", self.audit)
        self.assertIn("fromAuthenticatedContext", self.audit)
        self.assertIn("recordTrusted", self.audit)
        self.assertIn("actor_user_id", self.audit)
        self.assertIn("target_user_id", self.audit)
        self.assertIn("surface", self.audit)
        self.assertIn("request_method", self.audit)
        self.assertIn("request_route", self.audit)
        self.assertIn("parse_url($requestRoute, PHP_URL_PATH)", self.audit)
        self.assertIn("detailsAreSafe($value", self.audit)
        for forbidden_key in (
            "password", "password_hash", "access_token", "refresh_token",
            "session_id", "image_bytes", "profile_image_path",
        ):
            self.assertIn(f"'{forbidden_key}'", self.audit)
        self.assertIn("array_merge($details", self.audit)
        self.assertIn("recordTrusted", self.users)
        self.assertIn("recordTrusted", self.settings)
        self.assertNotIn("$body['actor", self.users)
        self.assertNotIn("$_REQUEST['actor", self.settings)

    def test_rate_limits_are_per_user_and_per_ip_at_sensitive_boundaries(self):
        self.assertIn("apiEnforceRateLimits", self.middleware)
        self.assertIn("SecurityRateLimiter", self.middleware)
        self.assertIn("profile-password-user", self.users)
        self.assertIn("profile-password-ip", self.users)
        self.assertIn("profile-image-user", self.users)
        self.assertIn("profile-image-ip", self.users)
        self.assertIn("profile-update-user", self.users)
        self.assertIn("profile-update-ip", self.users)
        self.assertIn("profile-username-user", self.users)
        self.assertIn("profile-username-ip", self.users)
        self.assertIn("RATE_LIMITED", self.middleware)
        self.assertIn("Retry-After", self.middleware)
        self.assertIn("settingsEnforceRateLimits", self.settings)
        self.assertIn("web-admin-password-reset-user", self.user_save)
        self.assertIn("web-admin-password-reset-ip", self.teachers)

    def test_private_image_upload_retrieval_removal_and_idor_contract(self):
        self.assertIn("readOwnImage(AuthenticatedProfileIdentity", self.images)
        self.assertIn("findProfileState($identity->userId())", self.images)
        self.assertNotIn("public_url", self.images.lower())
        self.assertIn("/users/me/profile-image", self.users)
        self.assertIn("Content-Type: image/jpeg", self.users)
        self.assertIn("X-Content-Type-Options: nosniff", self.users)
        self.assertIn("Cache-Control: private, no-store", self.users)
        self.assertIn("ETag", self.users)
        self.assertIn("jpegBytes()", self.users)
        self.assertIn("$_SESSION['admin_id']", self.web_image)
        self.assertIn("$_SESSION['admin_logged_in']", self.web_image)
        self.assertIn("AUTHENTICATION_REQUIRED", self.web_image)
        self.assertNotIn("includes/auth.php", self.web_image)
        self.assertIn("Content-Type: image/jpeg", self.web_image)
        self.assertIn("Cache-Control: private, no-store", self.web_image)
        self.assertIn("User ownership is derived from the session", self.web_image)
        self.assertNotRegex(self.users + self.web_image, r"Location:\s*.*profile")

    def test_multipart_idempotency_hash_includes_owner_route_operation_and_bytes(self):
        upload = self.users.split("if ($method === 'POST')", 1)[1].split(
            "if ($method === 'DELETE')", 1
        )[0]
        self.assertIn("$userId", upload)
        self.assertIn("POST /users/me/profile-image", upload)
        self.assertIn("hash('sha256', $artifact->jpegBytes())", upload)
        self.assertIn("$profileVersion", upload)
        self.assertIn("apiIdempotencyBegin($userId, null, $requestHash)", upload)
        self.assertIn("$explicitRequestHash", self.middleware)
        self.assertNotIn("$_FILES['image']['name']", upload)

    def test_web_settings_preserve_csrf_session_guard_and_response_shape(self):
        self.assertIn("requireCsrfForPost()", self.settings)
        self.assertIn("admin_logged_in", self.settings)
        self.assertIn("fromTrustedUserId($adminId)", self.settings)
        self.assertIn("getOwnProfile($settingsIdentity)", self.settings)
        self.assertIn("updateOwnProfile(", self.settings)
        self.assertIn("changeOwnPassword(", self.settings)
        self.assertIn("profile_image_upload", self.settings)
        self.assertIn("profile_image_remove", self.settings)
        self.assertIn("'status' => 'success'", self.settings)
        self.assertIn("$_SESSION['admin_username']", self.settings)
        self.assertIn("$_SESSION['admin_full_name']", self.settings)

    def test_api_middleware_supports_patch_and_byte_authoritative_idempotency(self):
        self.assertIn("GET, POST, PUT, PATCH, DELETE, OPTIONS", self.middleware)
        self.assertIn("?string $explicitRequestHash = null", self.middleware)
        self.assertIn("^[a-f0-9]{64}$", self.middleware)
        self.assertIn("$explicitRequestHash", self.middleware)


if __name__ == "__main__":
    unittest.main()
