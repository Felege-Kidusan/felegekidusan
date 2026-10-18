"""Regression pins for the School Admin "Open as <department>" switch.

Incident (2026-10-18): school_admin could not open any department dashboard —
api_impersonate.php answered "Security token expired" because
admin/dashboards/school_admin.php posted switch/restore WITHOUT a csrf_token
and read the session token without ever generating it (login.php resets the
session, so the first render after login had an empty CSRF constant).

These tests pin:
  1. switchRole() and restoreRole() send csrf_token with the POST body.
  2. The dashboard generates its token (generateCsrfToken()) instead of only
     reading $_SESSION['csrf_token'].
  3. Every FormData POST in the file carries a csrf_token (no new omissions).
  4. api_impersonate.php keeps its server-side CSRF enforcement.
"""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class SchoolAdminImpersonateCsrfTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.dashboard = (ROOT / "admin/dashboards/school_admin.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_impersonate.php").read_text(encoding="utf-8")

    def _function_body(self, name):
        match = re.search(r"async function %s\([^)]*\)\{(.*?)\n" % re.escape(name), self.dashboard)
        self.assertIsNotNone(match, "%s() must exist in school_admin.php" % name)
        return match.group(1)

    def test_switch_role_sends_csrf_token(self):
        body = self._function_body("switchRole")
        self.assertIn("api_impersonate.php", body)
        self.assertIn("fd.append('csrf_token',CSRF)", body)

    def test_restore_role_sends_csrf_token(self):
        body = self._function_body("restoreRole")
        self.assertIn("api_impersonate.php", body)
        self.assertIn("fd.append('csrf_token',CSRF)", body)

    def test_dashboard_generates_its_own_token(self):
        self.assertIn("$csrfToken = generateCsrfToken();", self.dashboard)
        self.assertNotIn("$csrfToken = $_SESSION['csrf_token'] ?? '';", self.dashboard)
        self.assertIn("const CSRF='<?=$csrfToken?>';", self.dashboard)

    def test_every_formdata_post_carries_csrf(self):
        # Each "new FormData()" that is later posted must append csrf_token
        # before its fetch(...{method:'POST'...}) call on the same line/block.
        blocks = re.findall(r"new FormData\(\);(.*?)fetch\(", self.dashboard, re.S)
        self.assertGreater(len(blocks), 5)
        missing = [b[:80] for b in blocks if "csrf_token" not in b]
        self.assertEqual(missing, [], "FormData POST without csrf_token: %r" % missing)

    def test_api_keeps_server_side_csrf_check(self):
        self.assertIn("requireCsrfForPost();", self.api)
        self.assertLess(self.api.index("requireCsrfForPost();"), self.api.index("switch ($action)"))


if __name__ == "__main__":
    unittest.main()
