"""Static + live-harness regression pins for the Attendance Rework Phase A.

Contract under pin (product decision 2026-10-07, plan §4.2):
- DRAFT saves (POST /api/v1/attendance, POST /api/v1/mezmur/sheet with
  kind=draft) accept PARTIAL sheets and MERGE them: upsert only the
  submitted rows on the existing unique keys; never delete marks the
  payload did not mention. A teacher pausing mid-marking is a normal
  workflow state — the mobile app's instant autosave deliberately
  persists partial drafts, and the old complete-only validation turned
  every pause into a 422 "rejected" sync attempt on the web dashboard.
- SUBMITS still require the complete roster (complete sheet = authority,
  replace semantics) — protection against roster drift is unchanged.
- Unknown members (not on the roster) are rejected in BOTH modes with a
  machine-readable error code (ROSTER_MISMATCH), and an incomplete submit
  carries INCOMPLETE_SHEET, so sync monitoring and Failure Intelligence
  can tell a real mismatch from a workflow state.
"""

from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class AttendanceReworkPhaseATests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = (
            ROOT / "admin/backend/services/AttendanceRecordService.php"
        ).read_text(encoding="utf-8")
        cls.mobile_api = (ROOT / "api/v1/routes/attendance.php").read_text(
            encoding="utf-8"
        )
        cls.mezmur_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.mezmur_api = (ROOT / "api/v1/routes/mezmur.php").read_text(
            encoding="utf-8"
        )
        cls.admin_api = (ROOT / "admin/api_attendance.php").read_text(
            encoding="utf-8"
        )
        cls.education_api = (ROOT / "admin/api_education.php").read_text(
            encoding="utf-8"
        )
        cls.outbox_policy = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/outbox_policy.dart"
        ).read_text(encoding="utf-8")

    # ── The exception carries machine-readable codes ──────────────────────

    def test_sheet_exception_carries_machine_codes(self):
        self.assertIn("class AttendanceSheetInvalid extends \\DomainException", self.service)
        self.assertIn("CODE_INCOMPLETE_SHEET = 'INCOMPLETE_SHEET'", self.service)
        self.assertIn("CODE_ROSTER_MISMATCH = 'ROSTER_MISMATCH'", self.service)
        self.assertIn("getErrorCode", self.service)

    # ── Service: complete/partial split ───────────────────────────────────

    def test_complete_sheet_contract_delegates_and_is_preserved(self):
        # normalizeCompleteSheet stays the complete-only contract used by
        # submits and the full-sheet editors (web taker, Education batch).
        self.assertIn("public static function normalizeCompleteSheet", self.service)
        self.assertIn("return self::normalizeSheet($records, $roster, true);", self.service)
        # Explicit-status invariant (migration 015) is untouched.
        self.assertIn("VALID_STATUSES", self.service)
        self.assertNotIn("?? 'present'", self.service)
        self.assertNotIn("DEFAULT 'present'", self.service)

    def test_partial_drafts_are_valid_but_foreign_students_never_are(self):
        self.assertIn(
            "public static function normalizeSheet(array $records, array $roster, bool $requireComplete)",
            self.service,
        )
        # Unknown-member check is UNCONDITIONAL (before the completeness
        # guard) so drafts can never write marks for another class.
        mismatch_pos = self.service.index("array_diff_key($submittedIds, $rosterIds) !== []")
        incomplete_pos = self.service.index("if ($requireComplete")
        self.assertLess(mismatch_pos, incomplete_pos)
        self.assertIn("AttendanceSheetInvalid::CODE_ROSTER_MISMATCH", self.service)
        # Completeness is only demanded when the caller requires it.
        self.assertIn("AttendanceSheetInvalid::CODE_INCOMPLETE_SHEET", self.service)

    def test_live_harness_partial_draft_and_submit_split(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        harness = r'''
require "admin/backend/services/AttendanceRecordService.php";
$out = [];
$roster = [["member_id"=>1],["member_id"=>2],["member_id"=>3]];
try { S::normalizeSheet([["member_id"=>1,"status"=>"present"]], $roster, false); $out["partial_draft"] = "accepted"; }
catch (Throwable $e) { $out["partial_draft"] = "rejected:" . $e->getMessage(); }
try { S::normalizeSheet([["member_id"=>99,"status"=>"present"]], $roster, false); $out["foreign_draft"] = "accepted"; }
catch (\App\Services\AttendanceSheetInvalid $e) { $out["foreign_draft"] = $e->getErrorCode(); }
try { S::normalizeSheet([["member_id"=>1,"status"=>"late"]], $roster, true); $out["partial_submit"] = "accepted"; }
catch (\App\Services\AttendanceSheetInvalid $e) { $out["partial_submit"] = $e->getErrorCode(); }
echo json_encode($out);
'''
        completed = subprocess.run(
            [php, "-r", "use App\\Services\\AttendanceRecordService as S;" + harness],
            cwd=ROOT, capture_output=True, text=True, timeout=15, check=False,
        )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        result = __import__("json").loads(completed.stdout.strip().splitlines()[-1])
        self.assertEqual(result["partial_draft"], "accepted")
        self.assertEqual(result["foreign_draft"], "ROSTER_MISMATCH")
        self.assertEqual(result["partial_submit"], "INCOMPLETE_SHEET")

    # ── Service: merge persistence ────────────────────────────────────────

    def test_merge_upserts_without_deleting(self):
        self.assertIn("public static function mergeSheet(", self.service)
        merge_block = self.service.split("public static function mergeSheet(", 1)[1]
        merge_block = merge_block.split("public static function replaceSheet", 1)[0]
        self.assertIn("ON DUPLICATE KEY UPDATE", merge_block)
        self.assertNotIn("DELETE", merge_block)
        self.assertIn("INSERT INTO attendance", merge_block)

    def test_replace_semantics_unchanged_for_complete_sheets(self):
        replace_block = self.service.split("public static function replaceSheet", 1)[1]
        self.assertIn("DELETE FROM attendance WHERE class_id = ? AND attendance_date = ?", replace_block)
        self.assertIn("INSERT INTO attendance", replace_block)

    def test_merge_lands_on_the_existing_unique_key(self):
        # Evidence that the upsert has a unique key to land on (sql/013).
        migration_013 = (ROOT / "sql/013_application_schema_completion.sql").read_text(
            encoding="utf-8"
        )
        self.assertIn("uq_att_member_class_date", migration_013)
        migration_023 = (ROOT / "sql/023_mezmur_date_attendance.sql").read_text(
            encoding="utf-8"
        )
        self.assertIn("uq_mezmur_attendance_date_member", migration_023)

    # ── Mobile API route: draft merges, submit completes ──────────────────

    def test_mobile_api_draft_path_merges_partial_sheets(self):
        self.assertIn(
            "apiValidateAttendanceSheet($conn, $classId, $yearId, $records, false)",
            self.mobile_api,
        )
        self.assertIn(
            "apiValidateAttendanceSheet($conn, $classId, $yearId, $records, true)",
            self.mobile_api,
        )
        # Exactly one call site merges — the draft handler, not the submit.
        self.assertEqual(self.mobile_api.count("true // draft merge: upsert submitted rows, never delete unmarked"), 1)
        # The persistence helper routes merge vs replace.
        self.assertIn("AttendanceRecordService::mergeSheet(", self.mobile_api)
        self.assertIn("AttendanceRecordService::replaceSheet(", self.mobile_api)
        # Machine code rides the 422 payload.
        self.assertIn("['code' => $error->getErrorCode()]", self.mobile_api)
        # The post-submit lock is untouched.
        self.assertIn("'code' => 'ALREADY_SUBMITTED'", self.mobile_api)

    def test_mobile_api_structure_pins_still_hold(self):
        # Structure the older integrity suite pins (counts must stay stable).
        self.assertEqual(self.mobile_api.count("apiValidateAttendanceSheet("), 3)
        self.assertEqual(self.mobile_api.count("apiReplaceAttendanceRows("), 3)
        self.assertGreaterEqual(self.mobile_api.count("$conn->begin_transaction();"), 2)
        self.assertGreaterEqual(self.mobile_api.count("$conn->rollback();"), 4)
        self.assertIn("SubmissionService::upsertAttendance", self.mobile_api)
        self.assertNotIn("apiUpsertAttendanceRows", self.mobile_api)

    # ── Mezmur: same split ────────────────────────────────────────────────

    def test_mezmur_drafts_merge_and_submits_replace(self):
        self.assertIn(
            "bool $ownTransaction = true, bool $requireComplete = true",
            self.mezmur_service,
        )
        # Foreign members are rejected in both modes.
        self.assertIn(
            "array_diff(array_keys($submitted), $roster) !== []",
            self.mezmur_service,
        )
        self.assertIn(
            "if ($requireComplete\n            && (count($submitted) !== count($roster)",
            self.mezmur_service,
        )
        # Merge write exists and never deletes; delete stays complete-only.
        self.assertIn("private static function upsertRows(", self.mezmur_service)
        upsert_block = self.mezmur_service.split("private static function upsertRows(", 1)[1]
        self.assertIn("ON DUPLICATE KEY UPDATE", upsert_block)
        self.assertIn("uq_mezmur_attendance_date_member", self.mezmur_service)
        self.assertIn("if ($requireComplete) {", self.mezmur_service)

    def test_mezmur_route_passes_kind_to_service(self):
        self.assertIn(
            "$requireComplete = $packetStatus === MezmurSubmissionService::STATUS_SUBMITTED;",
            self.mezmur_api,
        )
        self.assertIn(
            "saveSectionSheet($conn, $date, $section, $records, (int)$auth['uid'], false, $requireComplete)",
            self.mezmur_api,
        )

    # ── Complete-sheet callers untouched in Phase A ───────────────────────

    def test_web_taker_and_education_stay_complete_sheet_editors(self):
        # Web taker UX parity is Phase D; Education batch edits full sheets
        # by design. Both keep the complete-sheet contract in Phase A.
        self.assertIn("AttendanceRecordService::normalizeCompleteSheet", self.admin_api)
        self.assertIn("AttendanceRecordService::replaceSheet", self.admin_api)
        self.assertIn("AttendanceRecordService::normalizeCompleteSheet", self.education_api)
        self.assertIn("AttendanceRecordService::replaceSheet", self.education_api)

    # ── Monitoring honesty is not weakened ────────────────────────────────

    def test_outbox_still_flags_real_validation_errors(self):
        # The fix removes the CAUSE of mass-422s (partial drafts), not the
        # classification: genuine validation errors must still stop retrying
        # and surface as needing attention.
        self.assertIn("status == 422", self.outbox_policy)
        self.assertIn("OutboxDecision.needsAttention", self.outbox_policy)


if __name__ == "__main__":
    unittest.main()
