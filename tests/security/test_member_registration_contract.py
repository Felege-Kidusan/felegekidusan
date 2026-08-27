import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class MemberRegistrationContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.controller = (ROOT / "admin/hr_register_member.php").read_text()
        cls.policy = (
            ROOT / "admin/backend/services/MemberRegistrationPolicy.php"
        ).read_text()
        cls.category_service = (
            ROOT / "admin/backend/services/MemberCategory.php"
        ).read_text()
        cls.school = (ROOT / "admin/dashboards/school_admin.php").read_text()

    def test_role_aware_policy_executes_without_database(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        completed = subprocess.run(
            [
                php,
                str(ROOT / "tests/fixtures/member_registration_policy.fixture"),
                str(ROOT),
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertNotIn("Fatal error", completed.stdout + completed.stderr)
        result = json.loads(completed.stdout.strip().splitlines()[-1])
        self.assertTrue(result["school_profile"])
        self.assertTrue(result["hr_upgrade"])
        self.assertTrue(result["invalid_rejected"])

    def test_school_quick_add_uses_explicit_supported_contract(self):
        self.assertIn("registration_profile','quick_add", self.school)
        self.assertIn("fetch('/admin/hr_register_member.php'", self.school)
        self.assertIn("quickAddInFlight", self.school)
        self.assertIn("s.textContent=String(msg||'')", self.school)
        self.assertIn("$role === 'school_admin'", self.policy)
        self.assertIn("'allow_uploads' => !$quickAdd", self.policy)
        self.assertIn("'allow_upgrade' => !$quickAdd", self.policy)

    def test_controller_validates_and_audits_inside_registration_transaction(self):
        self.assertIn("MemberRegistrationPolicy::prepare", self.controller)
        self.assertIn("validateCsrf()", self.controller)
        self.assertIn("REQUEST_METHOD'] !== 'POST'", self.controller)
        self.assertIn("SecurityAuditService::record", self.controller)
        self.assertLess(
            self.controller.index("SecurityAuditService::record"),
            self.controller.index("$conn->commit()"),
        )
        self.assertIn("$registrationPolicy['allow_uploads']", self.controller)
        self.assertIn("$registrationPolicy['allow_upgrade']", self.controller)

    def test_member_codes_follow_ministry_category_system(self):
        identity = (
            ROOT / "admin/backend/services/IdentityCodeService.php"
        ).read_text()
        # FORMAT v2 (ANALYSIS/08): {PREFIX}-{random unique 5-digit tail}.
        # Uniqueness comes from the members.member_code UNIQUE key with a
        # bounded indexed probe — race-safe at six-figure rosters without
        # locks or sequential scans (which were retired with the old
        # per-category numbers).
        self.assertNotIn("GET_LOCK", identity)
        self.assertNotIn("MAX(CAST(SUBSTRING(member_code, 2) AS UNSIGNED))", identity)
        self.assertIn("random_int(self::TAIL_MIN, self::TAIL_MAX)", identity)
        self.assertIn("STUDENT_REGEX", identity)
        self.assertIn("HEAD_MARKER = 'H'", identity)
        self.assertIn("ORDINARY_MARKER = 'N'", identity)
        # Registration delegates code issuance to the single position-sync
        # writer (positions + manual category; never guessed).
        self.assertIn("PositionSyncService::applyPositions", self.controller)
        enrollment = (
            ROOT / "admin/backend/services/EnrollmentService.php"
        ).read_text()
        self.assertIn("IdentityCodeService::allocateStudent", enrollment)

    def test_route_authorizes_only_registration_owners(self):
        access = (ROOT / "admin/access_control.php").read_text()
        self.assertIn(
            "'hr_register_member.php'  => ['super_admin', 'school_admin', 'hr_dept']",
            access,
        )


if __name__ == "__main__":
    unittest.main()
