"""Regression tests for Fix 3 (H4+L4): impersonation-aware session guard.

Guards:
  - role-impersonation sessions survive periodic DB revalidation (the
    assumed role is preserved),
  - tampered impersonation sessions are invalidated,
  - privilege revocation ends an ongoing impersonation,
  - impersonation state-changing actions are POST-only + CSRF.
"""

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ImpersonationSessionGuardTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.guard = (
            ROOT / "admin/backend/services/AdminSessionGuard.php"
        ).read_text(encoding="utf-8")
        cls.impersonate = (ROOT / "admin/api_impersonate.php").read_text(encoding="utf-8")

    def test_guard_detects_impersonation_sessions(self):
        self.assertIn("original_admin_role", self.guard)

    def test_guard_preserves_assumed_role_on_revalidation(self):
        # The impersonation branch must NOT overwrite admin_role with the
        # database role; it refreshes the base account's original role only.
        self.assertIn("$session['original_admin_role'] = (string)$user['role'];", self.guard)
        self.assertIn("'database_impersonation'", self.guard)
        # The unconditional role overwrite must remain exclusive to the
        # non-impersonation branch (appears exactly once).
        self.assertEqual(
            self.guard.count("$session['admin_role'] = (string)$user['role'];"), 1
        )

    def test_guard_rejects_tampered_impersonation(self):
        self.assertIn("impersonation_tampered", self.guard)
        self.assertIn("PRIVILEGED_ROLES", self.guard)
        self.assertIn("KNOWN_ROLES", self.guard)

    def test_guard_ends_impersonation_when_privilege_revoked(self):
        self.assertIn("impersonation_base_revoked", self.guard)

    def test_impersonation_actions_are_post_only(self):
        # The endpoint must no longer accept actions via $_REQUEST (GET).
        self.assertNotIn("$_REQUEST['action']", self.impersonate)
        self.assertIn("$_POST['action']", self.impersonate)
        self.assertIn("Use POST for this action", self.impersonate)
        # CSRF still enforced for POST.
        self.assertIn("requireCsrfForPost()", self.impersonate)

    def test_guard_class_loads(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        harness = (
            "require '"
            + str(ROOT / "admin/backend/services/AdminSessionGuard.php")
            + "';"
            "echo \\App\\Services\\AdminSessionGuard::REVALIDATE_INTERVAL_SECONDS;"
        )
        completed = subprocess.run([php, "-r", harness], capture_output=True, text=True)
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertIn("300", completed.stdout)


if __name__ == "__main__":
    unittest.main()
