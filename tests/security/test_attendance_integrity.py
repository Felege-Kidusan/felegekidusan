"""Regression checks for explicit, complete, atomic attendance sheets."""
import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class AttendanceIntegrityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = (
            ROOT / "admin/backend/services/AttendanceRecordService.php"
        ).read_text(encoding="utf-8")
        cls.admin_api = (ROOT / "admin/api_attendance.php").read_text(encoding="utf-8")
        cls.mobile_api = (
            ROOT / "api/v1/routes/attendance.php"
        ).read_text(encoding="utf-8")
        cls.education_api = (
            ROOT / "admin/api_education.php"
        ).read_text(encoding="utf-8")
        cls.submissions = (
            ROOT / "admin/backend/services/SubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.migration = (
            ROOT / "sql/015_explicit_attendance_status.sql"
        ).read_text(encoding="utf-8")
        cls.teacher = (
            ROOT / "admin/dashboards/teacher.php"
        ).read_text(encoding="utf-8")
        cls.taker = (
            ROOT / "admin/dashboards/attendance_taker.php"
        ).read_text(encoding="utf-8")
        cls.sheet_js = (
            ROOT / "admin/js/attendance-sheet.js"
        ).read_text(encoding="utf-8")
        cls.mobile_screen = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/attendance/attendance_screen.dart"
        ).read_text(encoding="utf-8")
        cls.local_db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")

    def test_policy_fixture_rejects_implicit_partial_and_duplicated_sheets(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        completed = subprocess.run(
            [
                php,
                str(ROOT / "tests/fixtures/attendance_record_policy.fixture"),
                str(ROOT),
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        result = json.loads(completed.stdout.strip().splitlines()[-1])
        self.assertEqual(result["valid_count"], 2)
        self.assertEqual(result["normalized_status"], "present")
        self.assertEqual(result["trimmed_note"], "Arrived early")
        self.assertTrue(all(result["rejected"].values()))

    def test_domain_service_requires_exact_server_roster_and_explicit_status(self):
        self.assertIn("normalizeCompleteSheet", self.service)
        self.assertIn("array_diff_key($rosterIds, $submittedIds)", self.service)
        self.assertIn("array_diff_key($submittedIds, $rosterIds)", self.service)
        self.assertIn("isset($submittedIds[$memberId])", self.service)
        self.assertIn("VALID_STATUSES", self.service)
        self.assertNotIn("?? 'present'", self.service)
        self.assertNotIn("DEFAULT 'present'", self.service)

    def test_browser_api_returns_unmarked_roster_and_validates_before_writing(self):
        self.assertIn("'status' => $att['status'] ?? null", self.admin_api)
        self.assertIn("AttendanceRecordService::normalizeCompleteSheet", self.admin_api)
        self.assertIn("EnrollmentService::fetchRoster", self.admin_api)
        self.assertIn("AttendanceRecordService::replaceSheet", self.admin_api)
        self.assertNotIn("$status = $record['status'] ?? 'present'", self.admin_api)

    def test_mobile_api_validates_complete_roster_and_commits_packet_atomically(self):
        self.assertEqual(self.mobile_api.count("apiValidateAttendanceSheet("), 3)
        self.assertEqual(self.mobile_api.count("apiReplaceAttendanceRows("), 3)
        self.assertGreaterEqual(self.mobile_api.count("$conn->begin_transaction();"), 2)
        self.assertGreaterEqual(self.mobile_api.count("$conn->rollback();"), 4)
        self.assertIn("SubmissionService::upsertAttendance", self.mobile_api)
        self.assertNotIn("validateEnum($rec['status']", self.mobile_api)
        self.assertNotIn("apiUpsertAttendanceRows", self.mobile_api)

    def test_legacy_education_adapters_do_not_reintroduce_implicit_present(self):
        record_block = self.education_api.split("case 'record_attendance':", 1)[1].split(
            "case 'batch_attendance':", 1
        )[0]
        batch_block = self.education_api.split("case 'batch_attendance':", 1)[1].split(
            "case 'get_class_students':", 1
        )[0]
        self.assertNotIn("?? 'present'", record_block)
        self.assertNotIn("?? 'present'", batch_block)
        self.assertIn("AttendanceRecordService::normalizeCompleteSheet", batch_block)
        self.assertIn("AttendanceRecordService::replaceSheet", batch_block)
        self.assertNotIn("?? 'present'", self.submissions)
        self.assertIn("MODIFY COLUMN `status`", self.migration)
        self.assertNotIn("DEFAULT 'present'", self.migration)

    def test_browser_clients_share_fail_closed_sheet_interaction(self):
        for source in (self.teacher, self.taker):
            self.assertIn("/admin/js/attendance-sheet.js", source)
            self.assertIn("AttendanceSheet.collect", source)
            self.assertIn("sheet.unmarked.length > 0", source)
            self.assertIn('data-attendance-status="excused"', source)
            self.assertNotIn("let status = 'present'", source)
            self.assertNotIn("s.status || 'present'", source)
        self.assertIn(".att-btn.active[data-attendance-status]", self.sheet_js)
        self.assertIn("unmarked.push(memberId)", self.sheet_js)
        self.assertNotIn("Attendance saved!", self.taker)

    def test_flutter_offline_flow_never_infers_present(self):
        self.assertIn("_requireCompleteSheet", self.mobile_screen)
        self.assertIn("_firstStatus", self.mobile_screen)
        self.assertIn("Attendance must explicitly mark every student", self.local_db)
        self.assertIn("await db.transaction((txn) async", self.local_db)
        self.assertNotIn("?? 'present'", self.mobile_screen)
        self.assertNotIn("DEFAULT 'present'", self.local_db)
        self.assertNotIn("r['status'] ?? 'present'", self.local_db)


if __name__ == "__main__":
    unittest.main()
