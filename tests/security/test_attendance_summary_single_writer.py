"""Regression tests for Fix 1 (H1+H2): single attendance-summary writer.

Guards:
  - exactly one writer of attendance_summary (AttendanceSummaryService),
  - the summary month is derived from the RECORDED attendance date,
  - the unified formula credits late as 0.5,
  - low-attendance alerts are deduped,
  - api_education passes the record date to the delegate.
"""

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class AttendanceSummarySingleWriterTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = (
            ROOT / "admin/backend/services/AttendanceSummaryService.php"
        ).read_text(encoding="utf-8")
        cls.workflow = (ROOT / "admin/backend/workflow.php").read_text(encoding="utf-8")
        cls.api_attendance = (ROOT / "admin/api_attendance.php").read_text(encoding="utf-8")
        cls.api_education = (ROOT / "admin/api_education.php").read_text(encoding="utf-8")

    def test_service_is_the_single_writer(self):
        self.assertIn("INSERT INTO attendance_summary", self.service)
        # The two legacy writers must no longer persist summary rows
        # themselves; they only delegate to the service.
        self.assertNotIn("INSERT INTO attendance_summary", self.workflow)
        self.assertNotIn("INSERT INTO attendance_summary", self.api_attendance)

    def test_delegates_call_the_service(self):
        self.assertIn(
            "\\App\\Services\\AttendanceSummaryService::recordSaved", self.workflow
        )
        self.assertIn(
            "\\App\\Services\\AttendanceSummaryService::recordSaved",
            self.api_attendance,
        )

    def test_summary_keyed_on_record_date_not_today(self):
        # The service derives month/year from the saved record's date.
        self.assertIn("strtotime($attendanceDate)", self.service)
        # workflow delegate validates and forwards an optional record date.
        self.assertIn("$attendanceDate = null", self.workflow)
        # api_education passes the recorded date on both write paths.
        self.assertIn(
            "updateAttendanceSummary($conn, $memberId, $yearId, $attendanceDate)",
            self.api_education,
        )
        self.assertIn(
            "updateAttendanceSummary($conn, (int)$record['member_id'], $yearId, $attendanceDate)",
            self.api_education,
        )

    def test_unified_formula_credits_late_half(self):
        self.assertIn("$presentDays + $lateDays * 0.5", self.service)
        # The legacy present-only formula must be gone from the delegate.
        self.assertNotIn("$presentDays / $totalDays", self.workflow)

    def test_member_level_aggregation(self):
        # Summary unique key has no class column; aggregation must be
        # per-member across classes.
        self.assertIn("WHERE member_id = ?", self.service)
        # The api_attendance delegate must hand whole members to the
        # service, not class-scoped aggregates.
        self.assertIn(
            "SELECT DISTINCT member_id FROM attendance WHERE class_id = ?",
            self.api_attendance,
        )
        writer_start = self.api_attendance.find("function updateAttendanceSummary")
        writer_block = self.api_attendance[writer_start:]
        self.assertNotIn("GROUP BY", writer_block)

    def test_null_year_rows_replaced_not_duplicated(self):
        self.assertIn("academic_year_id IS NULL", self.service)

    def test_low_attendance_alert_is_deduped(self):
        self.assertIn("ALERT_SUPPRESSION_DAYS", self.service)
        self.assertIn("DATE_SUB(NOW(), INTERVAL ? DAY)", self.service)

    def test_php_syntax(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        for rel in [
            "admin/backend/services/AttendanceSummaryService.php",
            "admin/backend/workflow.php",
            "admin/api_attendance.php",
            "admin/api_education.php",
        ]:
            completed = subprocess.run(
                [php, "-l", str(ROOT / rel)],
                capture_output=True,
                text=True,
            )
            self.assertEqual(completed.returncode, 0, rel + ": " + completed.stdout)

    def test_service_class_loads_and_constants(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        harness = (
            "require '"
            + str(ROOT / "admin/backend/services/AttendanceSummaryService.php")
            + "';"
            "echo \\App\\Services\\AttendanceSummaryService::LOW_ATTENDANCE_THRESHOLD;"
            "echo '|';"
            "echo method_exists('\\App\\Services\\AttendanceSummaryService','recordSaved') ? 'ok' : 'missing';"
        )
        completed = subprocess.run(
            [php, "-r", harness], capture_output=True, text=True
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertIn("70", completed.stdout)
        self.assertIn("ok", completed.stdout)


if __name__ == "__main__":
    unittest.main()
