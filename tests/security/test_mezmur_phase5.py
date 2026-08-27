"""Phase-5 tests: mezmur attendance becomes the VERBATIM clone of
the teachers ↔ education workflow, scoped by (date, section):

  - sql/024: mezmur_submissions packet table (UNIQUE date+section),
    excused status + notes on mezmur_attendance (additive, guarded)
  - MezmurSubmissionService mirrors SubmissionService vocabulary
  - mobile API: section-scoped sheet + draft/submitted packets,
    409 lock semantics identical to teachers
  - web API: batched overview, review inbox, reason-mandatory review
  - web dashboard rescue: lazy tabs + bounded GETs (no more
    skeleton-forever), program types removed from every UI
  - mobile v2: [Section ▾] + P/A/L/E + notes + outbox keyed by
    (date, section), offline/background-sync parity

Static analysis only (mirrors the rest of the suite).
"""

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class MezmurPhase5Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql24 = (ROOT / "sql/024_mezmur_submissions.sql").read_text(encoding="utf-8")
        cls.sub_service = (
            ROOT / "admin/backend/services/MezmurSubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.att_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.shell = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.css = (ROOT / "themes/components.css").read_text(encoding="utf-8")
        cls.screen = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_attendance.dart"
        ).read_text(encoding="utf-8")
        cls.analytics = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_analytics.dart"
        ).read_text(encoding="utf-8")
        cls.db = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/local_db.dart"
        ).read_text(encoding="utf-8")
        cls.sync = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/sync_service.dart"
        ).read_text(encoding="utf-8")
        cls.dart_api = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart"
        ).read_text(encoding="utf-8")

    # ── sql/024: additive, idempotent, guarded ────────────────
    def test_sql024_packet_table_contract(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_submissions`", self.sql24)
        self.assertIn(
            "UNIQUE KEY `uq_mezmur_submissions_date_section` (`attendance_date`, `section`)",
            self.sql24,
        )
        # grade_submissions vocabulary parity
        for col in [
            "taker_id", "status", "member_count", "present_count",
            "late_count", "absent_count", "excused_count",
            "submitted_at", "reviewed_by", "reviewed_at", "review_notes",
            "client_op_id",
        ]:
            self.assertIn(f"`{col}`", self.sql24)
        # FKs set-null (never destroy history when a user is removed)
        self.assertIn("ON DELETE SET NULL", self.sql24)

    def test_sql024_is_guarded_and_additive(self):
        # excused widening + notes column + nullable session_id are
        # guarded ALTERs; nothing dropped
        self.assertIn("information_schema.columns", self.sql24)
        self.assertIn("COLUMN_TYPE LIKE '%excused%'", self.sql24)
        self.assertIn("IS_NULLABLE = 'YES'", self.sql24)
        self.assertIn("MODIFY COLUMN `session_id` BIGINT UNSIGNED DEFAULT NULL", self.sql24)
        self.assertNotIn("DROP TABLE", self.sql24)
        self.assertNotIn("DROP COLUMN", self.sql24)
        # mezmur_days untouched (legacy labels preserved)
        self.assertNotIn("ALTER TABLE `mezmur_days`", self.sql24)

    # ── MezmurSubmissionService: edu-workflow clone ───────────
    def test_submission_service_mirrors_edu_vocabulary(self):
        for token in [
            "STATUS_INCOMPLETE = 'incomplete'",
            "STATUS_SUBMITTED = 'submitted'",
            "STATUS_APPROVED = 'approved'",
            "STATUS_REJECTED = 'rejected'",
            "STATUS_REVISION = 'revision_needed'",
            "STATUS_DRAFT = 'draft'",
            "public static function statusIsOpen(",
            "public static function normalizeStatus(",
            "public static function staffCanOverride(",
            "public static function isLockedForTaker(",
            "public static function countsFromRecords(",
        ]:
            self.assertIn(token, self.sub_service)
        # review authority: mezmur dept + admins ONLY
        self.assertIn(
            "private const REVIEW_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];",
            self.sub_service,
        )

    def test_review_requires_reason_for_returns(self):
        self.assertIn("mb_strlen($notes) < 3", self.sub_service)
        self.assertIn("Write a short reason so the taker knows what to fix.", self.sub_service)
        self.assertIn("mb_substr($notes, 0, 500)", self.sub_service)
        # immutable audit trail on every decision
        self.assertIn("Mezmur Submission Reviewed", self.sub_service)
        self.assertIn("'mezmur_submission'", self.sub_service)
        self.assertIn("previous_status", self.sub_service)

    def test_upsert_lock_semantics_match_teachers(self):
        self.assertIn(
            "This attendance is already submitted. Only the Mezmur department can change it.",
            self.sub_service,
        )
        # open states: draft / incomplete / revision_needed
        self.assertIn("self::STATUS_REVISION], true)", self.sub_service)

    # ── MezmurAttendanceService: section-scoped sheets ────────
    def test_attendance_service_section_surface(self):
        for token in [
            "public static function sectionRoster(",
            "public static function sectionListWithCounts(",
            "public static function fetchSectionSheet(",
            "public static function saveSectionSheet(",
            "bool $ownTransaction = true",
        ]:
            self.assertIn(token, self.att_service)
        # teacher parity statuses incl. excused + notes column used
        self.assertIn(
            "public const STATUSES = ['present', 'late', 'absent', 'excused'];",
            self.att_service,
        )
        self.assertIn("notesByMember", self.att_service)

    def test_no_nested_transactions(self):
        # saveSectionSheet only commits/rolls back when it owns the tx
        self.assertIn("if ($ownTransaction) {", self.att_service)

    # ── mobile API routes: teacher-contract parity ────────────
    def test_mobile_route_section_endpoints(self):
        self.assertIn("$action === 'sections'", self.route)
        self.assertIn("sectionListWithCounts", self.route)
        self.assertIn("fetchSectionSheet", self.route)
        self.assertIn("saveSectionSheet", self.route)
        self.assertIn("MezmurSubmissionService::takerMayWrite", self.route)
        # 409 lock + idempotency + rate limiting intact
        self.assertIn("err('This attendance is already submitted. Only the Mezmur department can change it.', 409);", self.route)
        self.assertIn("apiIdempotencyBegin(", self.route)
        self.assertIn("isApiRateLimited('mezmur_sheet_save'", self.route)

    def test_mobile_route_atomic_rows_plus_packet(self):
        # rows and packet commit or roll back as ONE unit
        self.assertIn("$conn->begin_transaction();", self.route)
        self.assertIn("$conn->commit();", self.route)
        self.assertIn("$conn->rollback();", self.route)
        self.assertIn("false);", self.route)  # ownTransaction=false
        # legacy date-only save kept for older clients (break nothing)
        self.assertIn("MezmurAttendanceService::saveSheet($conn, $date, $records", self.route)

    # ── web API: overview batch + dept review ─────────────────
    def test_web_api_new_actions(self):
        for token in [
            "case 'overview':",
            "case 'sections':",
            "case 'submissions_list':",
            "case 'submission_detail':",
            "case 'submission_review':",
        ]:
            self.assertIn(token, self.api)
        # review is POST-only + role-checked + rate-limited as a write
        self.assertIn("'submission_review'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST'", self.api)
        self.assertIn("'submission_review'], true)\n    ? 'mezmur_write'", self.api)
        self.assertIn("MezmurSubmissionService::canReview(", self.api)
        # schema probe tells admins exactly which migration to run
        self.assertIn("sql/024_mezmur_submissions.sql", self.api)

    def test_overview_is_one_round_trip(self):
        ov = self.api.split("case 'overview':")[1].split("case 'submissions_list'")[0]
        self.assertIn("mezmur_respond([", ov)
        self.assertIn("'recent_packets'", ov)
        self.assertIn("'prev_month'", ov)

    # ── web dashboard rescue ──────────────────────────────────
    def test_js_no_undefined_api_global(self):
        # ROOT CAUSE of the skeleton-forever bug: SSMS.api never existed
        self.assertNotIn("SSMS.api", self.js)
        self.assertIn("window.api.get('mezmur.php?' + q)", self.js)
        self.assertIn("window.api.post('mezmur.php', data)", self.js)

    def test_js_bounded_gets(self):
        self.assertIn("GET_TIMEOUT = 12000", self.js)
        self.assertIn("clearTimeout(timer)", self.js)
        # every timeout lands in the error+Retry state, never a spinner
        self.assertIn("The server took too long to answer", self.js)

    def test_js_lazy_tabs(self):
        self.assertIn("var tabLoaded = { overview: false, library: false, attendance: false, analytics: false, takers: false };", self.js)
        self.assertIn("window.switchSection = function (name) {", self.js)
        self.assertIn("_origSwitch(name);", self.js)
        # DOMContentLoaded loads ONLY the active tab (wiring block)
        dom = self.js.split("document.addEventListener('DOMContentLoaded'")[1]
        dom = dom.split("// Public API")[0]
        self.assertIn("loadTab(name);", dom)
        self.assertNotIn("loadOverview();", dom)
        self.assertNotIn("loadTakers();", dom)
        self.assertNotIn("loadSubmissions();", dom)

    def test_js_section_first_attendance(self):
        for token in [
            "action=sections",
            "mzAttSection",
            "'&section=' + encodeURIComponent(section)",
            "section: att.section",
            "kind: kind",
            "seg('present', 'Present') + seg('late', 'Late') + seg('absent', 'Absent') + seg('excused', 'Excused')",
            "Mezmur.editNote",
            "mzRvDecision",
            "action: 'submission_review'",
        ]:
            self.assertIn(token, self.js)
        # completeness gate like teachers
        self.assertIn("unmarkedCount()", self.js)

    def test_program_types_removed_from_web_ui(self):
        self.assertNotIn("mzAttProgram", self.shell)
        self.assertNotIn("mzAnProgram", self.shell)
        self.assertNotIn("mzAttProgram", self.js)
        self.assertNotIn("PROGRAM_LABELS", self.js)
        # server keeps legacy program filter (older rows) but UI drops it
        self.assertNotIn("program_type=' +", self.js)

    def test_shell_review_inbox_and_status_banner(self):
        for token in [
            'id="mzSubTbody"',
            'id="mzSheetStatus"',
            'id="mzReviewModal"',
            'id="mzPacketModal"',
            'id="mzOvQueue"',
            'id="mzSheetSaveBtn"',
            'id="mzSheetSubmitBtn"',
        ]:
            self.assertIn(token, self.shell)
        self.assertIn('onclick="Mezmur.saveSheet(\'draft\')"', self.shell)
        self.assertIn('onclick="Mezmur.saveSheet(\'submitted\')"', self.shell)

    def test_css_supports_excused_and_banners(self):
        self.assertIn(".seg-btn[aria-pressed=\"true\"].seg-excused", self.css)
        self.assertIn(".mz-banner", self.css)

    # ── mobile v2: teacher clone ──────────────────────────────
    def test_mobile_screen_teacher_clone(self):
        for token in [
            "_loadSections()",
            "getMezmurSections()",
            "cacheMezmurSections(",
            "'present',\n    'absent',\n    'late',\n    'excused',",
            "_statusBtn('E', 'excused'",
            "_editNote(",
            "packetKind: 'submitted'",
            "showUndoToast(",
            "TeacherActionBar(",
            "SubmittedBar()",
            "showEthiopianDatePicker(",
            "formatGregorianAsEthiopian(_selectedDate)",
        ]:
            self.assertIn(token, self.screen)

    def test_mobile_screen_packet_semantics(self):
        # server packet status drives the lock, like teachers
        self.assertIn("submission_status", self.screen)
        self.assertIn("PacketLock.isLocked(", self.screen)
        self.assertIn("dropPendingMezmur(_selectedDate, section)", self.screen)
        # department wording (not "Education") on the return banner
        self.assertIn("Returned by the Mezmur department", self.screen)
        self.assertIn("_mezmurReturnNote(", self.screen)
        self.assertNotIn("Only Education can change this", self.screen)

    def test_mobile_program_types_gone(self):
        self.assertNotIn("_program", self.screen)
        self.assertNotIn("Rehearsal", self.screen)
        self.assertNotIn("_program", self.analytics)
        self.assertNotIn("program_type", self.analytics)

    def test_mobile_outbox_is_section_scoped(self):
        self.assertIn("saveMezmurLocal(_selectedDate, section, _records()", self.screen)
        self.assertIn("getPendingMezmurRecords(_selectedDate, section)", self.screen)
        self.assertIn("cacheMezmurSheet(_selectedDate, section", self.screen)
        self.assertIn("getMezmurSheet(_selectedDate, section: section)", self.screen)

    # ── php -l (when a PHP CLI is available) ──────────────────
    def test_php_lint_new_backend_files(self):
        if shutil.which("php") is None:
            self.skipTest("php CLI not available")
        for rel in [
            "admin/backend/services/MezmurSubmissionService.php",
            "admin/backend/services/MezmurAttendanceService.php",
            "admin/api_mezmur.php",
            "api/v1/routes/mezmur.php",
        ]:
            r = subprocess.run(
                ["php", "-l", str(ROOT / rel)],
                capture_output=True, text=True, timeout=60,
            )
            self.assertEqual(r.returncode, 0, f"php -l failed for {rel}: {r.stdout}{r.stderr}")


if __name__ == "__main__":
    unittest.main()
