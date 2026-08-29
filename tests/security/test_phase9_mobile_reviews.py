"""Phase 9 — mobile department features: review inbox, QR lookup,
registration. Contract tests (static): every department's review
workflow is exposed to the mobile gateway with role gates + rate
limits, the server stays the single source of truth, and the mobile
surfaces (tabs, screens) exist with clone parity."""

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def rd(p):
    return (ROOT / p).read_text(encoding="utf-8", errors="replace")


class ReviewGatewayTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.mez = rd("api/v1/routes/mezmur.php")
        cls.hr = rd("api/v1/routes/hr.php")
        cls.grades = rd("api/v1/routes/grades.php")
        cls.members = rd("api/v1/routes/members.php")

    def test_mezmur_review_endpoints_role_gated(self):
        self.assertIn("GET' && $action === 'submissions'", self.mez)
        self.assertIn("GET' && $action === 'submission'", self.mez)
        self.assertIn("POST' && $action === 'submission-review'", self.mez)
        self.assertIn("MezmurSubmissionService::canReview($auth)", self.mez)
        self.assertIn("isApiRateLimited('mezmur_submission_review', 30)", self.mez)
        self.assertIn("MezmurSubmissionService::reviewPacket(", self.mez)

    def test_hr_review_endpoints_role_gated(self):
        self.assertIn("GET' && $action === 'submissions'", self.hr)
        self.assertIn("POST' && $action === 'submission-review'", self.hr)
        self.assertIn("HrSubmissionService::canReview($auth)", self.hr)
        self.assertIn("HrSubmissionService::reviewPacket(", self.hr)

    def test_edu_review_mirrors_web_console_rules(self):
        # Same statuses + reason rule as api_communication.php.
        self.assertIn("action === 'submissions'", self.grades)
        self.assertIn("action === 'submission-review'", self.grades)
        self.assertIn("'approved', 'rejected', 'revision_needed'", self.grades)
        self.assertIn("mb_strlen($notes) < 3", self.grades)
        self.assertIn("SecurityAuditService::record", self.grades)
        self.assertIn("$__eduReviewRoles", self.grades)

    def test_registration_has_duplicate_guard(self):
        self.assertIn("MemberDuplicateService::findStrongMatch", self.members)
        self.assertIn("duplicate_override", self.members)
        self.assertIn("duplicate_override_reason", self.members)
        self.assertIn("'data' => ['duplicate' => $strongMatch]", self.members)


class MobileSurfaceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.inbox = rd(
            "Mobile/wbws_flutter_app/lib/screens/reviews/"
            "review_inbox_screen.dart")
        cls.shell = rd(
            "Mobile/wbws_flutter_app/lib/screens/shell/app_shell.dart")
        cls.config = rd("Mobile/wbws_flutter_app/lib/utils/config.dart")
        cls.api = rd("Mobile/wbws_flutter_app/lib/services/api_service.dart")
        cls.members = rd(
            "Mobile/wbws_flutter_app/lib/screens/members/member_list_screen.dart")
        cls.register = rd(
            "Mobile/wbws_flutter_app/lib/screens/members/"
            "register_member_screen.dart")

    def test_inbox_shared_with_clone_parity(self):
        # One shared inbox parametrized by dept — never three drifts.
        for dept in ("'edu'", "'mezmur'", "'hr'"):
            self.assertIn(dept, self.inbox)
        self.assertIn("revision_needed", self.inbox)
        # Return requires a reason on-device too.
        self.assertIn("reason.length < 3", self.inbox)
        # Thumb-zone decision buttons.
        self.assertIn("height: 56", self.inbox)

    def test_shell_routes_reviews_by_role(self):
        self.assertIn("case 'reviews':", self.shell)
        self.assertIn("ReviewInboxScreen(dept: 'edu')", self.shell)
        self.assertIn("ReviewInboxScreen(dept: 'mezmur')", self.shell)
        self.assertIn("ReviewInboxScreen(dept: 'hr')", self.shell)
        self.assertIn("ReviewHubScreen", self.shell)
        self.assertIn("HrDeptHomeScreen", self.shell)

    def test_hr_role_exists_and_gets_tabs(self):
        self.assertIn("hrDept = 'hr_dept'", self.config)
        self.assertIn("case UserRoles.hrDept:", self.config)
        self.assertIn("'reviews'", self.config)

    def test_api_client_covers_all_three_depts(self):
        self.assertIn("_reviewBase(String dept)", self.api)
        self.assertIn("getReviewSubmissions", self.api)
        self.assertIn("reviewSubmission(", self.api)

    def test_member_qr_lookup_reuses_scanner(self):
        self.assertIn("QrScanSheet.open", self.members)
        self.assertIn("QrAttendance.extractMemberCode", self.members)
        self.assertIn("findCachedMemberByCode", self.members)
        self.assertIn("QrFeedback.memberFound", self.members)

    def test_registration_form_mirrors_web_rules(self):
        self.assertIn("student_name", self.register)
        self.assertIn("father_name", self.register)
        self.assertIn("duplicate_override", self.register)
        self.assertIn("7_13", self.register)


if __name__ == "__main__":
    unittest.main()
