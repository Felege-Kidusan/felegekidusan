import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class FeatureGateEnforcementTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.gate = (ROOT / "admin/backend/services/FeatureGate.php").read_text()
        cls.school = (ROOT / "school_config.php").read_text()
        cls.config = (ROOT / "config.php").read_text()
        cls.access = (ROOT / "admin/access_control.php").read_text()
        cls.router = (ROOT / "api/v1/index.php").read_text()
        cls.app_route = (ROOT / "api/v1/routes/app.php").read_text()
        cls.dashboard_route = (ROOT / "api/v1/routes/dashboard.php").read_text()
        cls.web_dashboard = (ROOT / "admin/dashboard.php").read_text()
        cls.web_layout = (ROOT / "frontend/layouts/base.php").read_text()
        cls.finance_page = (ROOT / "frontend/pages/finance_dept.php").read_text()
        cls.subjects = (ROOT / "admin/api_subjects.php").read_text()
        cls.education = (ROOT / "admin/api_education.php").read_text()
        cls.communication = (ROOT / "admin/api_communication.php").read_text()
        cls.mobile_config = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/app_update_service.dart"
        ).read_text()
        cls.mobile_shell = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/shell/app_shell.dart"
        ).read_text()

    def test_gate_fixture_fails_closed_and_filters_mobile_tiles(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        completed = subprocess.run(
            [php, str(ROOT / "tests/fixtures/feature_gate.fixture"), str(ROOT)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        result = json.loads(completed.stdout.strip().splitlines()[-1])
        self.assertFalse(result["finance"])
        self.assertFalse(result["attendance"])
        self.assertTrue(result["grades"])
        self.assertFalse(result["ambiguous_string"])
        self.assertFalse(result["unknown"])
        self.assertEqual(result["finance_route"], "finance")
        self.assertEqual(result["id_route"], "id_cards")
        self.assertEqual(result["attendance_api"], "attendance")
        self.assertEqual(result["role_feature"], "material")
        self.assertTrue(result["mezmur"])
        self.assertEqual(result["mezmur_role_feature"], "mezmur")
        self.assertEqual(result["mezmur_route"], "mezmur")
        self.assertEqual(result["mezmur_page_route"], "mezmur")
        self.assertEqual(result["mezmur_api"], "mezmur")
        self.assertEqual(result["tiles"]["education"], ["classes", "grades"])
        self.assertEqual(result["tiles"]["invalid"], ["reports"])

    def test_environment_can_override_boolean_defaults(self):
        for name in [
            "AI_CHATBOT", "GROUPS", "FINANCE", "MATERIAL", "MEZMUR", "ID_CARDS",
            "ATTENDANCE", "GRADES", "REPORTS", "EXPORT_PDF", "MONITOR",
        ]:
            self.assertIn(
                f"if (!defined('FEATURE_{name}')) define('FEATURE_{name}', true);",
                self.school,
            )
            self.assertIn(f"'FEATURE_{name}'", self.gate)
        self.assertIn("constant($constant) === true", self.gate)

    def test_browser_and_rest_routes_enforce_flags_server_side(self):
        self.assertIn("FeatureGate::forAdminRequest", self.access)
        self.assertIn("FeatureGate::isEnabled", self.access)
        self.assertIn("FeatureGate::forApiResource", self.router)
        self.assertIn("This feature is not enabled for this deployment.", self.router)
        self.assertIn("FeatureGate::forRoleDashboard", self.web_dashboard)
        self.assertIn("feature_enabled('ai')", self.web_dashboard)
        self.assertIn("$requiredFeature = 'finance'", self.finance_page)
        self.assertIn("FeatureGate::isEnabled($requiredFeature)", self.web_layout)

    def test_mixed_education_routes_have_action_level_gates(self):
        self.assertIn("$__gradeActions", self.subjects)
        self.assertIn("feature_enabled('grades')", self.subjects)
        self.assertIn("'attendance' => ['record_attendance', 'batch_attendance']", self.education)
        self.assertIn("'grades' => ['record_grade']", self.education)
        self.assertIn("$__gradeActions", self.communication)
        self.assertIn("feature_enabled('grades')", self.communication)

    def test_monitor_and_pdf_flags_have_runtime_effect(self):
        export = (ROOT / "admin/export_pdf.php").read_text()
        monitor_index = (ROOT / "monitor/index.php").read_text()
        monitor_cron = (ROOT / "monitor/uptime_cron.php").read_text()
        self.assertIn("feature_enabled('monitor')", self.config)
        self.assertIn("feature_enabled('monitor')", monitor_index)
        self.assertIn("feature_enabled('monitor')", monitor_cron)
        self.assertIn("feature_enabled('export_pdf')", export)

    def test_mobile_receives_capabilities_and_removes_disabled_navigation(self):
        self.assertIn("FeatureGate::mobileCapabilities()", self.app_route)
        self.assertIn("FeatureGate::filterMobileTiles", self.app_route)
        self.assertIn("FeatureGate::mobileCapabilities()", self.dashboard_route)
        self.assertIn("final Map<String, bool> features", self.mobile_config)
        self.assertIn("featureEnabled(String feature", self.mobile_config)
        self.assertIn("_featureCacheKey", self.mobile_config)
        self.assertIn("SharedPreferences.getInstance", self.mobile_config)
        self.assertIn("attendanceEnabled: config.featureEnabled('attendance')", self.mobile_shell)
        self.assertIn("gradesEnabled: config.featureEnabled('grades')", self.mobile_shell)
        self.assertIn("_buildDisabledFeature", self.mobile_shell)


if __name__ == "__main__":
    unittest.main()
