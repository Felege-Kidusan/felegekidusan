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


class MezmurDeploymentResilienceTests(unittest.TestCase):
    """Root-cause fixes for the recurring production failures:

    1. A server whose migrations were never run (e.g. sql/024) must
       DEGRADE, never HTTP-500: PHP 8.1 mysqli throws on a missing
       table, so every mezmur_submissions read is wrapped in
       try/catch Throwable.
    2. Clients detect a stale backend via the server_meta version
       marker and show an actionable "update the server" message
       instead of a dead-end error.
    3. POSTs are bounded so the Save button can never hang forever.
    4. action=ping gives administrators a one-request deployment
       health check (code version + every migration + session_id).
    """

    @classmethod
    def setUpClass(cls):
        cls.sub_service = (
            ROOT / "admin/backend/services/MezmurSubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.att_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.response = (ROOT / "api/v1/core/response.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.screen = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_attendance.dart"
        ).read_text(encoding="utf-8")

    # ── 1. missing-migration resilience (reads degrade, no 500) ──
    def test_submission_reads_are_exception_safe(self):
        # PHP >= 8.1 throws mysqli_sql_exception from prepare() on a
        # missing table; every packet-table READ must catch Throwable.
        self.assertGreaterEqual(self.sub_service.count("catch (\\Throwable $e)"), 8)
        # the classic read entry points all return a safe fallback
        self.assertIn("ORDER BY id DESC LIMIT 1", self.sub_service)

    def test_missing_table_gives_actionable_save_message(self):
        self.assertIn(
            "sql/024_mezmur_submissions.sql",
            self.sub_service,
            "upsert/review failures must tell admins which migration to run",
        )

    def test_section_sheet_marks_query_is_exception_safe(self):
        # fetchSectionSheet: marks read wrapped so a legacy DB degrades
        # to an unmarked sheet instead of WBSS-U01.
        chunk = self.att_service.split("fetchSectionSheet")[1][:2200]
        self.assertIn("try {", chunk)
        self.assertIn("catch (\\Throwable $e)", chunk)
        self.assertIn("$marks = [];", chunk)

    def test_overview_degrades_to_zeros(self):
        ov = self.api.split("case 'overview'")[1].split("case 'submissions_list'")[0]
        self.assertIn("catch (\\Throwable $e)", ov)
        self.assertIn("'days' => 0, 'marked' => 0, 'attended' => 0", ov)

    def test_schema_probes_handle_php7_false_return(self):
        # PHP 7 returns false from query() instead of throwing.
        self.assertIn("if ($probe === false)", self.api)

    # ── 2. version handshake (detect stale deployments) ─────────
    def test_admin_api_stamps_every_response(self):
        self.assertIn("MEZMUR_API_VERSION", self.api)
        self.assertIn("server_meta", self.api)
        self.assertIn("MEZMUR_SCHEMA_MIN", self.api)

    def test_mobile_route_stamps_responses(self):
        self.assertIn("define('MEZMUR_API_VERSION'", self.route)
        self.assertIn("server_meta", self.response)
        self.assertIn("defined('MEZMUR_API_VERSION')", self.response)

    def test_web_js_explains_generic_server_errors(self):
        self.assertIn("staleHint", self.js)
        self.assertIn("sql/024_mezmur_submissions.sql", self.js)

    def test_app_detects_and_warns_about_stale_server(self):
        self.assertIn("_staleServer", self.screen)
        self.assertIn("server_meta", self.screen)
        self.assertIn("sql/024_mezmur_submissions.sql", self.screen)
        self.assertIn("StatusBanner.warning", self.screen)

    # ── 3. bounded POSTs (no more Saving… forever) ──────────────
    def test_post_requests_are_bounded(self):
        self.assertIn("POST_TIMEOUT", self.js)
        self.assertIn("var POST_TIMEOUT = 20000", self.js)
        # apiPost now races against its own timeout
        post_fn = self.js.split("function apiPost")[1][:900]
        self.assertIn("setTimeout", post_fn)

    # ── 4. deployment health endpoint ───────────────────────────
    def test_ping_action_exists_and_checks_all_migrations(self):
        self.assertIn("case 'ping'", self.api)
        ping = self.api.split("case 'ping'")[1].split("case 'stats'")[0]
        for tbl in [
            "mezmur_hymns",
            "mezmur_days",
            "mezmur_attendance",
            "mezmur_attendance_audit",
            "mezmur_submissions",
        ]:
            self.assertIn(tbl, ping)
        self.assertIn("code_version", ping)
        self.assertIn("missing_tables", ping)
        self.assertIn("session_id_nullable", ping)


class MezmurProdDiagTests(unittest.TestCase):
    """Production incident #2 (2026-08-28, evening): the host runs a
    handler that masks any failure as
    {"status":"error","message":"Server error. Please try again.","ref":"#N"}
    — it hijacked even action=ping. Two structural defenses:

    1. backend/api/mezmur.php?diag=1 — dependency-free diagnostic that
       always answers HTTP 200 (unmaskable), reports PHP version, OPcache
       state, parse-checks every mezmur file under the server's own PHP,
       probes every table and the feature constant.
    2. The mezmur controller answers EVERY operational outcome with
       HTTP 200 + a status field, because the host demonstrably mangles
       non-2xx responses (401 came back as a 302 with a plain-text body).
    """

    @classmethod
    def setUpClass(cls):
        cls.shim = (ROOT / "backend/api/mezmur.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")

    def test_diag_endpoint_exists_and_is_unmaskable(self):
        self.assertIn("isset($_GET['diag'])", self.shim)
        self.assertIn("TOKEN_PARSE", self.shim)
        self.assertIn("opcache_get_status", self.shim)
        self.assertIn("MEZMUR_API_VERSION", self.shim)  # disk-version probe
        self.assertIn("FEATURE_MEZMUR", self.shim)
        # diag runs BEFORE the real controller is required
        self.assertLess(
            self.shim.index("isset($_GET['diag'])"),
            self.shim.index("admin/api_mezmur.php"),
        )

    def test_controller_is_200_only(self):
        import re
        bad = re.findall(r"mezmur_respond\([^;]*?,\s*[1-5]\d\d\);", self.api, re.S)
        self.assertEqual(bad, [], "mezmur API must never emit non-2xx (host mangles them)")

    def test_shim_php_lint(self):
        if shutil.which("php") is None:
            self.skipTest("php CLI not available")
        r = subprocess.run(
            ["php", "-l", str(ROOT / "backend/api/mezmur.php")],
            capture_output=True, text=True, timeout=60,
        )
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


class MezmurSchemaToleranceTests(unittest.TestCase):
    """Incident #3 root cause: production members has
    `student_photo_path`, not `photo_url`; every roster SELECT that
    hardcoded photo_url threw mysqli_sql_exception (PHP 8.2) and the
    host masked it as the generic ref-JSON. Roster queries must detect
    the photo column at runtime and always emit the photo_url key.
    """

    @classmethod
    def setUpClass(cls):
        cls.att = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")

    def test_photo_column_is_detected_not_hardcoded(self):
        self.assertIn("photoSelectExpr", self.att)
        self.assertIn("student_photo_path", self.att)
        self.assertIn("SHOW COLUMNS FROM members LIKE", self.att)
        self.assertIn("NULL AS photo_url", self.att)
        # no SELECT may hardcode the photo column any more
        self.assertNotIn("full_name_am, photo_url", self.att)
        self.assertNotIn("m.photo_url", self.att)
        self.assertNotIn("father_name, photo_url", self.att)

    def test_api_v1_service_includes_resolve_to_repo_root(self):
        # the Aug-27 log showed require of .../api/admin/backend/... —
        # includes must climb three levels from api/v1/routes/.
        self.assertIn(
            "__DIR__ . '/../../../admin/backend/services/MezmurAttendanceService.php'",
            self.route,
        )
