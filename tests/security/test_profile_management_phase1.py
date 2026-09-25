"""Focused Phase 1 profile-domain security and transaction checks."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]
SERVICES = ROOT / "admin/backend/services"


class ProfileManagementPhase1Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.profile = (SERVICES / "ProfileService.php").read_text(encoding="utf-8")
        cls.credential = (SERVICES / "AccountCredentialService.php").read_text(
            encoding="utf-8"
        )
        cls.image = (SERVICES / "ProfileImageService.php").read_text(encoding="utf-8")
        cls.auth = (ROOT / "api/v1/core/auth.php").read_text(encoding="utf-8")

    def test_php_behavior_fixture(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        completed = subprocess.run(
            [
                php,
                str(ROOT / "tests/fixtures/profile_management_phase1.fixture"),
                str(ROOT),
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=45,
            check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        result = json.loads(completed.stdout.strip().splitlines()[-1])
        self.assertTrue(all(result["profile"].values()), result)
        self.assertTrue(all(result["credential"].values()), result)
        if result["image"]["available"]:
            self.assertTrue(result["image"]["normalized"], result)
            self.assertTrue(result["image"]["private_read"], result)
            self.assertTrue(result["image"]["failure_safe"], result)
            self.assertTrue(result["image"]["idempotent"], result)

    def test_self_service_identity_and_allowed_field_boundary(self):
        self.assertIn("final class AuthenticatedProfileIdentity", self.profile)
        self.assertIn("fromTrustedUserId", self.profile)
        self.assertIn("function getOwnProfile(AuthenticatedProfileIdentity", self.profile)
        self.assertIn("AuthenticatedProfileIdentity $identity,", self.profile)
        self.assertIn("$identity->userId()", self.profile)
        self.assertIn("function updateProfileByAdministrator", self.profile)
        self.assertIn("AuthorizedAdministratorIdentity $administrator", self.profile)
        self.assertNotIn("function updateOwnProfile(\n        int $targetUserId", self.profile)

        allowed = self.profile.split("private const ALLOWED_INPUT_FIELDS", 1)[1].split(
            "];", 1
        )[0]
        self.assertIn("'username'", allowed)
        self.assertIn("'email'", allowed)
        self.assertIn("'full_name'", allowed)
        self.assertIn("'current_password'", allowed)
        for forbidden in (
            "'id'",
            "'user_id'",
            "'role'",
            "'is_active'",
            "'authorization_version'",
            "'member_id'",
            "'password_hash'",
            "'profile_image_path'",
        ):
            self.assertNotIn(forbidden, allowed)
        admin_allowed = self.profile.split(
            "private const ADMIN_ALLOWED_INPUT_FIELDS", 1
        )[1].split("];", 1)[0]
        self.assertNotIn("'current_password'", admin_allowed)
        for forbidden in ("'role'", "'is_active'", "'authorization_version'", "'member_id'"):
            self.assertNotIn(forbidden, admin_allowed)
        self.assertIn("PROFILE_FIELD_NOT_ALLOWED", self.profile)

    def test_profile_normalization_validation_and_optimistic_versioning(self):
        self.assertIn("strtolower(trim($value))", self.profile)
        self.assertIn("strlen($username)", self.profile)
        self.assertIn("/^[a-z0-9][a-z0-9_.]*[a-z0-9]$/D", self.profile)
        self.assertIn("/[._]{2}/", self.profile)
        for reserved in ("admin", "administrator", "root", "system", "support", "api", "null"):
            self.assertIn(f"'{reserved}'", self.profile)
        self.assertIn("preg_match('//u', $value)", self.profile)
        self.assertIn("/[\\x00-\\x1F\\x7F]/u", self.profile)
        self.assertIn("FILTER_VALIDATE_EMAIL", self.profile)
        self.assertIn("return null", self.profile)

        self.assertIn("function profileVersion", self.profile)
        self.assertIn("'profile_image_path'", self.profile)
        self.assertIn("hash('sha256', $canonical)", self.profile)
        self.assertIn("hash_equals(self::profileVersion($row), $expectedVersion)", self.profile)
        self.assertIn("'assignments' => $assignments", self.profile)
        self.assertIn("'profile_image' => [", self.profile)
        self.assertIn("PROFILE_CONFLICT", self.profile)
        self.assertIn("FOR UPDATE", self.profile)

    def test_profile_uniqueness_prechecks_do_not_replace_database_authority(self):
        self.assertIn("usernameExists", self.profile)
        self.assertIn("emailExists", self.profile)
        self.assertIn("$errno === 1062", self.profile)
        self.assertIn("USERNAME_TAKEN", self.profile)
        self.assertIn("EMAIL_TAKEN", self.profile)
        update = self.profile.split("public function updateProfileFields", 1)[1].split(
            "private function uniqueValueExists", 1
        )[0]
        self.assertIn("UPDATE users SET", update)
        self.assertIn("WHERE id = ?", update)
        self.assertNotIn("UPDATE users SET role", update)

    def test_sensitive_profile_edits_require_current_password(self):
        update = self.profile.split("private function mutateProfile", 1)[1].split(
            "public static function normalizeUsername", 1
        )[0]
        self.assertIn("password_verify", update)
        self.assertIn("CURRENT_PASSWORD_INCORRECT", update)
        self.assertIn("isset($updates['username'])", update)
        self.assertIn("array_key_exists('email', $updates)", update)

    def test_password_update_and_revocation_are_one_rollback_safe_transaction(self):
        mutate = self.credential.split("private function mutate", 1)[1].split(
            "private static function validateSubmittedPassword", 1
        )[0]
        sequence = [
            "$this->credentials->begin()",
            "$this->credentials->lockCredential($targetUserId)",
            "password_hash($newPassword, PASSWORD_DEFAULT)",
            "$this->credentials->updatePasswordHash",
            "$this->credentials->revokeAllRefreshSessions",
            "$this->credentials->commit()",
        ]
        positions = [mutate.index(fragment) for fragment in sequence]
        self.assertEqual(positions, sorted(positions))
        self.assertIn("$this->credentials->rollback()", mutate)
        self.assertIn("UPDATE api_refresh_sessions", self.credential)
        self.assertIn("WHERE user_id = ?", self.credential)
        self.assertNotIn("UPDATE refresh_tokens", self.credential)
        self.assertIn("PasswordPolicy::errors", self.credential)
        self.assertIn("define('API_TOKEN_EXPIRY', 900)", self.auth)

    def test_self_change_and_administrator_reset_are_distinct(self):
        self.assertIn("function changeOwnPassword", self.credential)
        self.assertIn("AuthenticatedProfileIdentity $identity", self.credential)
        self.assertIn("function resetPasswordByAdministrator", self.credential)
        self.assertIn("AuthorizedAdministratorIdentity $administrator", self.credential)
        admin = self.credential.split(
            "public function resetPasswordByAdministrator", 1
        )[1].split("private function mutate", 1)[0]
        self.assertNotIn("$currentPassword", admin)
        self.assertIn("actor_user_id", admin)
        self.assertIn("target_user_id", admin)
        self.assertNotIn("password_hash' =>", admin)
        self.assertNotIn("token", admin.lower())
        self.assertIn("'audit_action' => 'Profile Password Changed'", self.credential)
        self.assertIn("'audit_action' => 'Profile Password Reset By Administrator'", self.credential)
        for secret_key in ("'password' =>", "'password_hash' =>", "'token' =>"):
            self.assertNotIn(secret_key, admin)

    def test_profile_image_validation_and_metadata_stripping_contract(self):
        for contract in (
            "MAX_UPLOAD_BYTES = 4 * 1024 * 1024",
            "MAX_DIMENSION = 4096",
            "MAX_PIXELS = 12000000",
            "new \\finfo(FILEINFO_MIME_TYPE)",
            "getimagesizefromstring($raw)",
            "imagecreatefromstring($raw)",
            "imagecopyresampled",
            "OUTPUT_SIZE = 512",
            "JPEG_QUALITY = 85",
            "imagejpeg($target, null, self::JPEG_QUALITY)",
        ):
            self.assertIn(contract, self.image)
        self.assertNotIn("$file['name']", self.image)
        self.assertIn("is_uploaded_file", self.image)
        self.assertIn("private://profiles/", self.image)
        self.assertIn("bin2hex(random_bytes(32))", self.image)
        self.assertIn("fopen($temporaryPath, 'xb')", self.image)
        self.assertIn("@chmod($finalPath, 0600)", self.image)

    def test_image_replacement_removal_and_ownership_are_failure_safe(self):
        replace = self.image.split("public function replaceOwnImage", 1)[1].split(
            "public function removeOwnImage", 1
        )[0]
        replace_sequence = [
            "$this->storage->stage($artifact)",
            "$this->profiles->begin()",
            "$this->profiles->lockProfileState($identity->userId())",
            "$this->profiles->updateImageReference($identity->userId(), $newReference)",
            "$this->profiles->commit()",
            "$this->storage->discard($oldReference)",
        ]
        positions = [replace.index(fragment) for fragment in replace_sequence]
        self.assertEqual(positions, sorted(positions))
        catch = replace.split("catch (\\Throwable $error)", 1)[1]
        self.assertIn("$this->profiles->rollback()", catch)
        self.assertIn("$this->storage->discard($newReference)", catch)
        self.assertNotIn("$targetUserId", replace)

        remove = self.image.split("public function removeOwnImage", 1)[1]
        already_absent = remove.index("if ($oldReference === null)")
        version_check = remove.index("hash_equals(ProfileService::profileVersion($row)")
        self.assertLess(already_absent, version_check)
        self.assertIn("updateImageReference($identity->userId(), null)", remove)
        self.assertIn("$this->storage->discard($oldReference)", remove)

    def test_domain_services_contain_no_http_or_ip_policy(self):
        combined = self.profile + self.credential + self.image
        for forbidden in (
            "REMOTE_ADDR",
            "HTTP_X_FORWARDED_FOR",
            "header(",
            "http_response_code",
            "SecurityRateLimiter",
        ):
            self.assertNotIn(forbidden, combined)

    def test_phase_one_is_schema_optional_and_does_not_create_production_storage(self):
        self.assertIn("$profileImageColumnAvailable = false", self.profile)
        self.assertIn("NULL AS profile_image_path", self.profile)
        self.assertNotIn("ALTER TABLE", self.profile + self.credential + self.image)
        self.assertNotIn("CREATE TABLE", self.profile + self.credential + self.image)
        self.assertNotIn("mkdir(", self.image)


if __name__ == "__main__":
    unittest.main()
