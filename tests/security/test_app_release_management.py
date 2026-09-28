"""Security and contract tests for the Super Admin App Release Management subsystem."""
import json
import os
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AppReleaseManagementTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.access_control = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")
        cls.api_app_release = (ROOT / "admin/api_app_release.php").read_text(encoding="utf-8")
        cls.app_release_manager = (
            ROOT / "admin/backend/services/AppReleaseManager.php"
        ).read_text(encoding="utf-8")
        cls.super_admin_dashboard = (
            ROOT / "admin/dashboards/super-admin.php"
        ).read_text(encoding="utf-8")
        cls.app_release_section = (
            ROOT / "admin/dashboards/sections/app_release_section.php"
        ).read_text(encoding="utf-8")
        cls.app_release_js = (ROOT / "admin/js/app_release.js").read_text(encoding="utf-8")

    def test_access_control_restricts_api_to_super_admin_only(self):
        self.assertIn("'api_app_release.php' => ['super_admin']", self.access_control)
        self.assertIn("($_SESSION['admin_role'] ?? '') !== 'super_admin'", self.api_app_release)
        self.assertIn("http_response_code(403)", self.api_app_release)

    def test_api_enforces_csrf_and_structured_actions(self):
        self.assertIn("validateCsrf", self.api_app_release)
        self.assertIn("case 'get_release':", self.api_app_release)
        self.assertIn("case 'save_config':", self.api_app_release)
        self.assertIn("case 'upload_apk':", self.api_app_release)
        self.assertIn("case 'delete_apk':", self.api_app_release)

    def test_service_validates_apk_extension_and_size_limits(self):
        self.assertIn("MAX_APK_BYTES = 209715200", self.app_release_manager)
        self.assertIn("pathinfo($originalName, PATHINFO_EXTENSION)", self.app_release_manager)
        self.assertIn("$ext !== 'apk'", self.app_release_manager)

    def test_dashboard_embeds_app_release_tab_and_scripts(self):
        self.assertIn("'app_release'", self.super_admin_dashboard)
        self.assertIn("data-section=\"app_release\"", self.super_admin_dashboard)
        self.assertIn("app_release_section.php", self.super_admin_dashboard)
        self.assertIn("app_release.js", self.super_admin_dashboard)
        self.assertIn("AppReleaseUI", self.app_release_js)

    def test_upload_progress_and_drag_drop_elements_exist_in_section(self):
        self.assertIn("id=\"upload-apk-input\"", self.app_release_section)
        self.assertIn("id=\"upload-progress-bar\"", self.app_release_section)
        self.assertIn("id=\"cfg-latest-version\"", self.app_release_section)
        self.assertIn("id=\"cfg-latest-build\"", self.app_release_section)
        self.assertIn("id=\"cfg-force-update\"", self.app_release_section)

    def test_releases_storage_protection_rule(self):
        self.assertIn("Options -Indexes", self.app_release_manager)
        self.assertIn("Deny from all", self.app_release_manager)


if __name__ == "__main__":
    unittest.main()
