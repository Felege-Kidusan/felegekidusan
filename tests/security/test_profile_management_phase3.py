from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
MOBILE = ROOT / "Mobile" / "wbws_flutter_app"


class ProfileManagementPhase3Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.model = (MOBILE / "lib/models/user_profile.dart").read_text(encoding="utf-8")
        cls.service = (MOBILE / "lib/services/profile_service.dart").read_text(encoding="utf-8")
        cls.cache = (MOBILE / "lib/services/profile_cache.dart").read_text(encoding="utf-8")
        cls.api = (MOBILE / "lib/services/api_service.dart").read_text(encoding="utf-8")
        cls.session = (MOBILE / "lib/services/session_service.dart").read_text(encoding="utf-8")
        cls.screen = (MOBILE / "lib/screens/profile/profile_screen.dart").read_text(encoding="utf-8")
        cls.shell = (MOBILE / "lib/screens/shell/app_shell.dart").read_text(encoding="utf-8")
        cls.config = (MOBILE / "lib/utils/config.dart").read_text(encoding="utf-8")
        cls.pubspec = (MOBILE / "pubspec.yaml").read_text(encoding="utf-8")

    def test_phase2_routes_are_used_without_alternate_profile_endpoints(self):
        for route in (
            "/users/me",
            "/users/change-password",
            "/users/me/profile-image",
        ):
            self.assertIn(route, self.api)
        self.assertIn("_profileJsonMutation('PATCH', '/users/me'", self.api)
        self.assertIn("_profileJsonMutation('DELETE', '/users/me/profile-image'", self.api)
        self.assertNotIn("/profile/update", self.api)
        self.assertNotIn("/profile/avatar", self.api)

    def test_no_client_selected_profile_owner_or_security_fields(self):
        profile_api = self.api.split(
            "// SELF-SERVICE PROFILE", 1
        )[1].split("// DASHBOARD", 1)[0]
        for unsafe in (
            "'user_id'",
            "'owner_id'",
            "'role'",
            "'is_active'",
            "'member_id'",
            "'authorization_version'",
            "'profile_image_path'",
        ):
            self.assertNotIn(unsafe, profile_api)
        self.assertIn("ownerUserId != userId", self.api)
        self.assertIn("ownerUserId == null || ownerUserId != userId", self.api)

    def test_profile_cache_is_owner_and_version_bound_without_new_sqlite_table(self):
        self.assertIn("['profile_images', '$ownerUserId', '$version.jpg']", self.cache)
        self.assertIn("_versionPattern", self.cache)
        self.assertIn("writeAsBytes(jpegBytes, flush: true)", self.cache)
        self.assertIn("temporary.rename(target.path)", self.cache)
        self.assertIn("clearOwner(int ownerUserId)", self.cache)
        self.assertIn("_operationIsCurrent", self.service)
        self.assertIn("_refreshInFlight = null", self.service)
        self.assertNotIn("CREATE TABLE", self.cache + self.service)
        self.assertNotIn("LocalDb", self.cache + self.service)

    def test_temporary_upload_cleanup_is_aged_confined_owner_bound_and_invoked(self):
        for contract in (
            "profile_uploads",
            r"^\.upload-[a-f0-9]{32}\.tmp$",
            "staleUploadAge = Duration(hours: 24)",
            "list(followLinks: false)",
            "_isConfinedUploadPath",
            "reapStaleUploads",
        ):
            self.assertIn(contract, self.cache)
        self.assertIn("unawaited(_reapStaleUploads())", self.service)
        self.assertIn("await _images.reapStaleUploads()", self.service)

    def test_flutter_image_policy_and_decoder_enforce_the_server_boundaries(self):
        for contract in (
            "minimumDimension = 64",
            "maximumDimension = 4096",
            "maximumPixels = 12000000",
            "width < minimumDimension || height < minimumDimension",
        ):
            self.assertIn(contract, self.model)
        self.assertIn("ui.ImageDescriptor.encoded", self.screen)
        self.assertIn("ProfileImageInputPolicy.validateDimensions", self.screen)
        self.assertIn("errorText: valueError", self.screen)
        self.assertIn("errorText: passwordError", self.screen)

    def test_mobile_shell_keeps_profile_available_for_every_role_mapping(self):
        self.assertIn("case 'profile':", self.shell)
        self.assertIn("return const ProfileScreen();", self.shell)
        # Twelve roles have explicit cases; content_editor intentionally uses
        # the fallback, which also exposes the canonical Profile tab.
        self.assertGreaterEqual(
            self.config.count("NavTab(id: 'profile', label: 'Profile'"), 13
        )
        self.assertIn("default:\n      return const [", self.config)

    def test_cached_profile_is_canonical_and_does_not_serialize_secrets(self):
        emitted = self.model.split("Map<String, dynamic> toJson() => {", 2)[2].split("};", 1)[0]
        for secret in (
            "password",
            "password_hash",
            "access_token",
            "refresh_token",
            "authorization_version",
        ):
            self.assertNotIn(secret, emitted)
        self.assertIn("UserProfile.tryFromJson(_gateway.cachedSessionUser)", self.service)
        self.assertIn("await _network.checkNow()", self.service)
        self.assertIn("await refresh()", self.service)

    def test_mutations_are_online_only_and_never_enter_an_outbox(self):
        self.assertIn("Profile changes require an internet connection.", self.service)
        self.assertIn("if (!_network.isOnline)", self.service)
        self.assertNotIn("OutboxService", self.service)
        self.assertNotIn("client_op_id", self.service)
        self.assertNotIn("SyncService", self.service)

    def test_profile_conflict_reloads_canonical_without_overwriting_dialog_draft(self):
        self.assertIn("response.errorCode == 'PROFILE_CONFLICT'", self.service)
        self.assertEqual(
            self.service.count("await refresh(allowDuringMutation: true);"), 3
        )
        self.assertIn("Your draft is still here", self.screen)
        self.assertIn("serverReloaded = result.conflict", self.screen)

    def test_claim_changes_use_existing_refresh_and_session_coordinator(self):
        self.assertIn("_session.reconcileProfileClaims()", self.service)
        self.assertIn("_api.refreshAccessToken()", self.session)
        self.assertIn("persistLocalSession", self.session)
        self.assertIn("ownerUserId: expectedOwner", self.session)
        self.assertIn("PROFILE_CLAIMS_CHANGED", self.service)

    def test_password_success_clears_existing_bundle_and_requires_reauthentication(self):
        self.assertIn("/users/change-password", self.api)
        self.assertIn("requireReauthenticationAfterPasswordChange", self.service)
        self.assertIn("enterReauthentication(reason: 'password_changed')", self.service)
        self.assertIn("await _api.clearCredentials();", self.session)
        self.assertNotRegex(self.service, r"write.*password")
        self.assertNotIn("passwordController.text", self.api)

    def test_image_failure_preserves_old_bytes_and_success_uses_opaque_etag(self):
        upload = self.service.split(
            "Future<ProfileActionResult> uploadImage", 1
        )[1].split("Future<ProfileActionResult> removeImage", 1)[0]
        self.assertNotIn("_imageBytes = null", upload)
        self.assertIn("_imageBytes = selectedBytes", upload)
        self.assertIn("forceDownload: true", upload)
        self.assertIn("if (etag != version ||", self.service)
        self.assertIn("profileVersion: canonical.profileVersion", self.service)
        self.assertIn("..fields['profile_version']", self.api)
        self.assertNotIn("..fields['user_id']", self.api)

    def test_logout_and_reauthentication_remove_profile_image_cache(self):
        self.assertIn("ProfileImageCache.instance.clearOwner(ownerUserId)", self.session)
        self.assertIn("ProfileImageCache.instance.clearAll()", self.session)
        self.assertIn("onProfileSessionCleared?.call()", self.session)
        self.assertIn("onProfileSessionCleared = _resetMemory", self.service)
        self.assertIn("await _api.logout();", self.session)
        self.assertIn("await _api.clearCredentials();", self.session)

    def test_screen_preserves_cache_during_refresh_and_protects_dirty_forms(self):
        self.assertIn("RefreshIndicator", self.screen)
        self.assertIn("if (_profiles.loading)", self.screen)
        self.assertIn("final valueController = TextEditingController", self.screen)
        self.assertNotIn("valueController.text =", self.screen)
        self.assertIn("No saved profile is available", self.screen)
        self.assertIn("Offline — showing saved profile data", self.screen)

    def test_existing_dependencies_are_reused_without_dependency_drift(self):
        self.assertRegex(self.pubspec, r"(?m)^\s*image_picker:")
        self.assertRegex(self.pubspec, r"(?m)^\s*path_provider:")
        self.assertRegex(self.pubspec, r"(?m)^\s*connectivity_plus:")
        self.assertIn("package:image_picker/image_picker.dart", self.screen)
        self.assertIn("package:path_provider/path_provider.dart", self.cache)

    def test_error_mapping_covers_required_profile_failures(self):
        for code in (
            "USERNAME_TAKEN",
            "EMAIL_TAKEN",
            "PROFILE_CONFLICT",
            "PROFILE_CLAIMS_CHANGED",
            "IMAGE_TOO_LARGE",
            "UNSUPPORTED_IMAGE",
            "INVALID_IMAGE",
            "RATE_LIMITED",
        ):
            self.assertIn(code, self.service)
        for status in ("401", "403", "413", "415", "422", "429"):
            self.assertIn(f"statusCode == {status}", self.service)
        self.assertIn("statusCode >= 500", self.service)


if __name__ == "__main__":
    unittest.main()
