"""Regression checks for one consistent account password policy."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class PasswordPolicyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.policy = (
            ROOT / "admin/backend/services/PasswordPolicy.php"
        ).read_text(encoding="utf-8")
        cls.config = (ROOT / "config.php").read_text(encoding="utf-8")
        cls.settings = (ROOT / "admin/api_settings.php").read_text(encoding="utf-8")
        cls.teachers = (ROOT / "admin/api_teachers.php").read_text(encoding="utf-8")
        cls.user_save = (ROOT / "admin/backend/user-save.php").read_text(encoding="utf-8")
        cls.mobile_api = (ROOT / "api/v1/routes/users.php").read_text(encoding="utf-8")
        cls.school_ui = (
            ROOT / "admin/dashboards/school_admin.php"
        ).read_text(encoding="utf-8")
        cls.education_ui = (
            ROOT / "admin/dashboards/edu_dept.php"
        ).read_text(encoding="utf-8")
        cls.mobile_ui = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/profile/profile_screen.dart"
        ).read_text(encoding="utf-8")

    def test_policy_fixture_accepts_passphrases_and_rejects_unsafe_edges(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        completed = subprocess.run(
            [php, str(ROOT / "tests/fixtures/password_policy.fixture"), str(ROOT)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        result = json.loads(completed.stdout.strip().splitlines()[-1])
        self.assertEqual(result["minimum"], 12)
        self.assertEqual(result["maximum_bytes"], 72)
        self.assertEqual(result["cases"]["valid_passphrase"], [])
        self.assertEqual(result["cases"]["valid_unicode"], [])
        for key in ["too_short", "too_long", "common", "nul_byte", "whitespace"]:
            self.assertTrue(result["cases"][key], key)

    def test_global_compatibility_helper_delegates_to_domain_policy(self):
        block = self.config.split("function validatePassword", 1)[1].split("\n}", 1)[0]
        self.assertIn("PasswordPolicy::errors", block)
        self.assertNotIn("< 6", block)
        self.assertIn("MIN_CHARACTERS = 12", self.policy)
        self.assertIn("MAX_BYTES = 72", self.policy)

    def test_every_password_write_uses_the_shared_policy(self):
        self.assertIn("validatePassword($password)", self.user_save)
        self.assertGreaterEqual(self.teachers.count("validatePassword("), 3)
        self.assertIn("validatePassword($newPwd)", self.settings)
        self.assertIn("validatePassword($newPassword)", self.mobile_api)
        for source in (self.settings, self.teachers, self.mobile_api):
            self.assertNotIn("at least 4 characters", source)
            self.assertNotIn("at least 6 characters", source)

    def test_mobile_password_change_revokes_refresh_sessions(self):
        self.assertIn("UPDATE api_refresh_sessions", self.mobile_api)
        self.assertIn("revoked_at = COALESCE", self.mobile_api)
        self.assertIn("PASSWORD_DEFAULT", self.mobile_api)
        self.assertNotIn("PASSWORD_BCRYPT", self.mobile_api)

    def test_browser_and_flutter_clients_explain_the_same_minimum(self):
        self.assertIn("pw.length<12", self.school_ui)
        self.assertIn("pw.length<12", self.education_ui)
        self.assertIn('minlength="12"', self.school_ui)
        self.assertIn('minlength="12"', self.education_ui)
        self.assertIn("newPwd.runes.length < 12", self.mobile_ui)
        self.assertIn("utf8.encode(newPwd).length > 72", self.mobile_ui)


if __name__ == "__main__":
    unittest.main()
