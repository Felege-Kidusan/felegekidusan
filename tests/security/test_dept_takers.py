"""Department-owned attendance takers (2026-08-28).

Product rule: three attendance sources (HR, Education, Mezmur) each
own their takers and their data — never shared, never combined.
Edu keeps its existing teacher/attendance_taker pipeline untouched;
mezmur_dept manages mezmur_attendance_taker; hr_dept manages
hr_attendance_taker; the Information department never takes
attendance (analytics only).

Locked contracts:
  • governed endpoint api_dept_takers.php behind ROLE_MAP
  • service-layer attribution (UI is never trusted)
  • advanced username validation (format, reserved, normalized
    collision)
  • mezmur console no longer calls the super-admin-only user pipeline
    (the old "you have no permission" bug)
  • mobile routes the two new roles to their own homes/tabs
"""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
M = ROOT / "Mobile/wbws_flutter_app"


class DeptTakerContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = (ROOT / "admin/backend/services/DeptTakerService.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_dept_takers.php").read_text(encoding="utf-8")
        cls.acl = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")
        cls.dashboard = (ROOT / "admin/dashboard.php").read_text(encoding="utf-8")
        cls.landing = (ROOT / "admin/dashboards/dept_taker.php").read_text(encoding="utf-8")
        cls.user_save = (ROOT / "admin/backend/user-save.php").read_text(encoding="utf-8")
        cls.mezmur_js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.mezmur_route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.mezmur_svc = (ROOT / "admin/backend/services/MezmurAttendanceService.php").read_text(encoding="utf-8")
        cls.config = (M / "lib/utils/config.dart").read_text(encoding="utf-8")
        cls.shell = (M / "lib/screens/shell/app_shell.dart").read_text(encoding="utf-8")

    # ── roles + attribution (service layer) ───────────────────
    def test_department_roles_and_attribution(self):
        self.assertIn("ROLE_MEZMUR_TAKER = 'mezmur_attendance_taker'", self.service)
        self.assertIn("ROLE_HR_TAKER = 'hr_attendance_taker'", self.service)
        for rule in (
            "case 'mezmur_dept':",
            "case 'hr_dept':",
            "case 'super_admin':",
        ):
            self.assertIn(rule, self.service)
        self.assertIn("default:\n                return [];", self.service)
        # create + toggle both enforce the attribution
        self.assertIn("if (!in_array($requestedRole, $managed, true))", self.service)
        self.assertIn("AND role IN", self.service)
        # password hashing only, never stored plaintext
        self.assertIn("password_hash($password, PASSWORD_DEFAULT)", self.service)
        self.assertIn("SecurityAuditService::record", self.service)

    def test_username_hardening(self):
        for needle in (
            "RESERVED_USERNAMES",
            "LOWER(username) = ?",          # case-insensitive dup
            "str_replace(['.', '_', '-'], '', $u)",  # normalized collision
            "preg_match('/^[a-z][a-z0-9._]*[a-z0-9]$/'",
        ):
            self.assertIn(needle, self.service)

    # ── governed endpoint ─────────────────────────────────────
    def test_endpoint_guard_layers(self):
        self.assertIn("validateCsrf", self.api)
        self.assertIn("SecurityRateLimiter", self.api)
        self.assertIn("['super_admin', 'school_admin', 'mezmur_dept', 'hr_dept']", self.api)
        self.assertIn("'api_dept_takers.php' => ['super_admin', 'school_admin', 'mezmur_dept', 'hr_dept']", self.acl)
        for action in ("'list'", "'create'", "'toggle'"):
            self.assertIn(action, self.api)

    # ── web routing for the new roles ─────────────────────────
    def test_dashboard_routes_new_roles(self):
        self.assertIn("case 'mezmur_attendance_taker':", self.dashboard)
        self.assertIn("case 'hr_attendance_taker':", self.dashboard)
        self.assertIn("dept_taker.php", self.dashboard)
        # landing page is read-only + fail-closed
        self.assertIn("http_response_code(403)", self.landing)
        self.assertNotIn("INSERT INTO", self.landing)
        self.assertNotIn("UPDATE ", self.landing)

    # ── the old bug is gone ───────────────────────────────────
    def test_mezmur_console_no_longer_uses_shared_pipeline(self):
        self.assertNotIn("/admin/backend/user-save.php", self.mezmur_js)
        self.assertNotIn("/admin/backend/user-toggle.php", self.mezmur_js)
        self.assertIn("/admin/api_dept_takers.php?action=list", self.mezmur_js)
        self.assertIn("role: 'mezmur_attendance_taker'", self.mezmur_js)
        # user-save.php still super-admin-only in ROLE_MAP
        self.assertIn("'user-save.php'   => ['super_admin']", self.acl)
        # departments manage takers ONLY via the governed endpoint
        self.assertNotIn("'mezmur_dept' => ['attendance_taker']", self.user_save)

    # ── data isolation of the mezmur taker roster ─────────────
    def test_mezmur_takers_list_scoped_to_own_role(self):
        self.assertIn("WHERE u.role = 'mezmur_attendance_taker'", self.mezmur_svc)
        self.assertIn("'mezmur_attendance_taker']", self.mezmur_route.split("$MEZMUR_ROLES")[1].split(";")[0])

    # ── mobile routing ────────────────────────────────────────
    def test_mobile_roles_and_tabs(self):
        self.assertIn("mezmurTaker = 'mezmur_attendance_taker'", self.config)
        self.assertIn("hrTaker = 'hr_attendance_taker'", self.config)
        self.assertIn("case UserRoles.mezmurTaker:", self.config)
        self.assertIn("case UserRoles.hrTaker:", self.config)
        # mezmur taker gets the attendance tab; hr taker not yet (Phase B)
        block = self.config.split("case UserRoles.mezmurTaker:")[1].split("// ---- HR")[0]
        self.assertIn("'mezmur_attendance'", block)
        self.assertIn("case UserRoles.mezmurTaker:", self.shell)
        self.assertIn("case UserRoles.hrTaker:", self.shell)
        self.assertIn("HrTakerHomeScreen", self.shell)


if __name__ == "__main__":
    unittest.main()
