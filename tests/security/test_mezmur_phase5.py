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
            "This attendance is already submitted. Only administrators can change it.",
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
        self.assertIn("err('This attendance is already submitted. Only administrators can change it.', 409);", self.route)
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
        self.assertIn("'submission_review', 'migrate'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST'", self.api)
        self.assertIn("'submission_review', 'migrate'], true)\n    ? 'mezmur_write'", self.api)
        # schema-drift killer endpoints
        self.assertIn("case 'schema'", self.api)
        self.assertIn("case 'migrate'", self.api)
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

    def test_web_attendance_is_readonly_review_console(self):
        # The department INSPECTS and REVIEWS; taking attendance lives
        # exclusively in the mobile app (product decision 2026-08-28).
        for token in [
            "action=sections",
            "mzViewSection",
            "viewSheet",
            "quickReview",
            "mzRvDecision",
            "action: 'submission_review'",
            "unmarkedCount()",
        ]:
            self.assertIn(token, self.js)
        # no editing surface left on the web
        self.assertNotIn("mzAttSection", self.js)
        self.assertNotIn("mzAttDate", self.js)
        self.assertNotIn("Mezmur.setMark(", self.js)
        self.assertNotIn("saveSheet(kind)", self.js)
        self.assertNotIn("seg-btn", self.js)
        # shell shows the read-only contract (record on mobile)
        self.assertIn("taken by mezmur attendance takers in the mobile app", self.shell)

    def test_attendance_section_is_edu_submissions_clone(self):
        # 2026-08-28: the mezmur console mirrors the edu Submissions
        # workflow — Drafts/Submitted/Insights tabs, insight strip,
        # Excel export, detail + review modals.
        for token in [
            'id="mzSubTabDraft"', 'id="mzSubTabSubmitted"', 'id="mzSubTabInsights"',
            'id="mzSubStatsRow"', 'id="mzSubInsights"', 'id="mzSubSection"',
            'id="mzSubmissionsList"',
        ]:
            self.assertIn(token, self.shell)
        for fn in [
            "function switchSubTab(", "function loadSubmissions()",
            "function renderSubStats(", "function exportSubmissions()",
            "function loadSubInsights()", "function quickDecision(",
        ]:
            self.assertIn(fn, self.js)
        # insight strip data comes from the governed stats block
        self.assertIn("packetStats", self.sub_service)
        self.assertIn("$out['stats'] = MezmurSubmissionService::packetStats($conn);", self.api)
        # shared submission-tab styling lives in the component theme
        self.assertIn(".sub-tab", self.css)
        # Excel parity (SheetJS, same CDN as edu_dept)
        self.assertIn("xlsx.full.min.js", self.shell)
        self.assertIn("Mezmur.exportSubmissions()", self.shell)
        self.assertIn("Read-only — sheets are recorded and submitted from the mobile app.", self.shell)
        self.assertIn("Review Queue", self.shell)
        self.assertNotIn("Take Attendance", self.shell)
        self.assertIn('id="mzViewSection"', self.shell)

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
        ]:
            self.assertIn(token, self.shell)
        self.assertIn('onclick="Mezmur.viewSheet()"', self.shell)

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

    def test_controller_requires_rate_limiter_class(self):
        # The web-failure root cause: the admin bootstrap never loaded
        # SecurityRateLimiter (only api/v1 middleware did), so every
        # request fatals with class-not-found before the try/catch.
        self.assertIn(
            "require_once __DIR__ . '/backend/services/SecurityRateLimiter.php';",
            self.api,
        )

    def test_controller_owns_exception_handler(self):
        self.assertIn("set_exception_handler", self.api)

    def test_diag_checks_class_wiring(self):
        self.assertIn("class_wiring", self.shim)
        self.assertIn("controller_requires_rate_limiter", self.shim)

    def test_shim_php_lint(self):
        if shutil.which("php") is None:
            self.skipTest("php CLI not available")
        r = subprocess.run(
            ["php", "-l", str(ROOT / "backend/api/mezmur.php")],
            capture_output=True, text=True, timeout=60,
        )
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


class MezmurSchemaReconcilerTests(unittest.TestCase):
    """Schema-drift killer: legacy tables (created before the repo)
    are never upgraded by CREATE TABLE IF NOT EXISTS, and migrations
    lag the cron code pull. The reconciler reports and closes drift
    with idempotent guarded DDL; admins trigger it with one click.
    """

    @classmethod
    def setUpClass(cls):
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.shell = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.shim = (ROOT / "backend/api/mezmur.php").read_text(encoding="utf-8")

    def test_reconciler_covers_every_mezmur_table(self):
        for tbl in ["mezmur_hymns", "mezmur_days", "mezmur_attendance",
                    "mezmur_attendance_audit", "mezmur_submissions"]:
            self.assertIn("'" + tbl + "'", self.rec)

    def test_reconciler_is_guarded_and_idempotent(self):
        self.assertIn("SHOW COLUMNS FROM", self.rec)
        self.assertIn("ALTER TABLE", self.rec)
        self.assertIn("catch (\\Throwable", self.rec)

    def test_reconciler_extends_legacy_enum_and_nullability(self):
        self.assertIn("excused", self.rec)
        self.assertIn("MODIFY COLUMN session_id BIGINT UNSIGNED DEFAULT NULL", self.rec)

    def test_api_exposes_report_and_guarded_apply(self):
        self.assertIn("case 'schema'", self.api)
        self.assertIn("case 'migrate'", self.api)
        # migrate is POST-enforced and write-rate-limited
        mig = self.api.split("in_array($action, [")[1]
        self.assertIn("'migrate'", mig)

    def test_one_click_ui_exists(self):
        self.assertIn("migrateSchema", self.js)
        self.assertIn("action: 'migrate'", self.js)
        self.assertIn("Sync DB schema", self.shell)

    def test_diag_reports_drift(self):
        self.assertIn("schema_drift", self.shim)
        self.assertIn("MezmurSchemaReconciler", self.shim)


class MezmurAdvancedSearchTests(unittest.TestCase):
    """Telegram-grade hymn search (research 2026-08-28): Telegram keeps a
    local full-text index for instant as-you-type results; we mirror that
    with InnoDB FULLTEXT boolean mode (prefix wildcards), title-weighted
    ranking, lyrics snippets, highlight marks, debounce + stale-response
    guard, and LIKE fallback. Lyrics are searchable; lists never carry
    full lyrics (snippet only).
    """

    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")

    def test_server_fulltext_ranked_search(self):
        self.assertIn("IN BOOLEAN MODE", self.api)
        self.assertIn("ft_mezmur_hymns_search", self.api)
        self.assertIn("3.0 * MATCH(title, title_am)", self.api)  # title weight
        self.assertIn("ORDER BY score DESC", self.api)
        self.assertIn("mb_stripos", self.api)  # snippet around first match
        self.assertIn("'snippet'", self.api)
        # boolean operators stripped from user input (injection-safe)
        self.assertIn('-><()~*', self.api)  # boolean operators stripped from user input

    def test_like_fallback_and_token_minimum(self):
        self.assertIn("searchMode = 'like'", self.api)
        self.assertIn("OR lyrics LIKE", self.api)

    def test_lists_never_carry_full_lyrics(self):
        self.assertIn("unset($r['lyrics'])", self.api)

    def test_reconciler_ensures_fulltext_indexes(self):
        self.assertIn("ft_mezmur_hymns_search", self.rec)
        self.assertIn("ADD FULLTEXT INDEX", self.rec)
        self.assertIn("missing_indexes", self.rec)

    def test_client_instant_search_ux(self):
        self.assertIn("}, 160);", self.js)          # tight debounce
        self.assertIn("var seq = ++lib.seq", self.js)  # stale-response guard
        self.assertIn("<mark>$1</mark>", self.js)   # Telegram-style highlight
        self.assertIn("h.snippet", self.js)


class MezmurAuditHardeningTests(unittest.TestCase):
    """End-to-end department audit (2026-08-28): industry-standard
    hardening applied after the full feature-by-feature review:
    complete audit trail, least-privilege lock overrides, clamped
    aggregates, calendar-real dates, and a scale-safe paginated inbox.
    """

    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.sub_service = (
            ROOT / "admin/backend/services/MezmurSubmissionService.php"
        ).read_text(encoding="utf-8")
        cls.att_service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")

    # ── audit trail: every decision-grade mutation is logged ──
    def test_controller_loads_audit_service(self):
        self.assertIn(
            "require_once __DIR__ . '/backend/services/SecurityAuditService.php';",
            self.api,
        )

    def test_hymn_library_writes_are_audited(self):
        for action in (
            "Mezmur Hymn Created",
            "Mezmur Hymn Updated",
            "Mezmur Hymn Archived",
            "Mezmur Hymn Restored",
        ):
            self.assertIn(action, self.api)
        self.assertIn("'mezmur_hymn', $id", self.api.replace("\n", " ").replace("  ", " ") or self.api)

    def test_schema_reconcile_is_audited(self):
        self.assertIn("Mezmur Schema Reconciled", self.api)

    def test_review_decisions_are_audited(self):
        self.assertIn("Mezmur Submission Reviewed", self.sub_service)
        self.assertIn("previous_status", self.sub_service)

    def test_packet_lifecycle_is_audited(self):
        self.assertIn("packet_upsert", self.sub_service)
        self.assertIn("auditPacket", self.sub_service)
        # both write paths (update + insert) record the trail
        self.assertEqual(self.sub_service.count("self::auditPacket("), 2)

    # ── least privilege: lock override is an admin power ──────
    def test_write_override_is_admin_only(self):
        self.assertIn(
            "private const WRITE_OVERRIDE_ROLES = ['school_admin', 'super_admin'];",
            self.sub_service,
        )
        override_fn = self.sub_service.split("function staffCanOverride")[1].split("}")[0]
        self.assertIn("WRITE_OVERRIDE_ROLES", override_fn)
        # review stays open to the department...
        self.assertIn(
            "private const REVIEW_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];",
            self.sub_service,
        )

    def test_web_save_sheet_force_gated_to_admins(self):
        self.assertNotIn("'force' => true", self.api)
        self.assertIn(
            "in_array($mezmurRole, ['super_admin', 'school_admin'], true)",
            self.api,
        )

    def test_mobile_route_never_force_overrides(self):
        self.assertNotIn("'force'", self.route)

    # ── integrity: clamps + calendar-real dates ───────────────
    def test_packet_counts_are_clamped(self):
        self.assertIn("max(0, min(1000000, (int)($opts['member_count'] ?? 0)))", self.sub_service)
        self.assertIn("max(0, min(1000000, (int)($opts['absent'] ?? 0)))", self.sub_service)

    def test_dates_are_calendar_real(self):
        self.assertIn("checkdate($m, $d, $y)", self.att_service)
        self.assertIn("checkdate($m, $d, $y)", self.sub_service)
        # every date guard in the attendance service goes through the
        # calendar-real helper (regex lives only inside validDate itself)
        self.assertGreaterEqual(self.att_service.count("self::validDate($date)"), 4)
        self.assertEqual(
            self.att_service.count("preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)"), 1
        )

    # ── scale: inbox is paginated, bounded, total-aware ───────
    def test_inbox_is_paginated(self):
        self.assertIn("LIMIT ? OFFSET ?", self.sub_service)
        self.assertIn("COUNT(*) c FROM mezmur_submissions", self.sub_service)
        self.assertIn("if ($perPage > 100) $perPage = 100;", self.sub_service)
        self.assertNotIn("LIMIT 200", self.sub_service)
        self.assertIn("'total_pages'", self.sub_service)

    def test_submissions_endpoint_exposes_pagination(self):
        block = self.api.split("case 'submissions_list':")[1].split("case 'submission_detail':")[0]
        self.assertIn("'page' =>", block)
        self.assertIn("'per_page' =>", block)
        self.assertIn("mezmur_respond(['status' => 'success'] + $out);", block)

    def test_overview_uses_bounded_recent_packets(self):
        self.assertIn("'per_page' => 5", self.api)

    # ── deployment visibility ─────────────────────────────────
    def test_version_marker_bumped_both_surfaces(self):
        import re
        m = re.search(r"MEZMUR_API_VERSION', '([^']+)'", self.api)
        self.assertIsNotNone(m)
        # web + mobile surfaces advertise the SAME marker
        self.assertIn(m.group(1), self.route)
        self.assertNotIn("'phase5-schema24'", self.api)

    # ── prepared statements remain the only query path ────────
    def test_no_string_interpolated_user_data_in_sql(self):
        for src in (self.sub_service, self.att_service):
            # no $_GET/$_POST/$_REQUEST reaching into SQL strings
            self.assertNotIn("$_GET", src)
            self.assertNotIn("$_POST", src)
            self.assertNotIn("$_REQUEST", src)


class MezmurOfflineHymnTests(unittest.TestCase):
    """Offline-first hymn library (2026-08-28): Telegram/Drive
    local-first model — device SQLite is the read path, mutations go
    through an idempotent outbox, pulls use a change-token delta.
    Static contract tests; functional server behavior is covered by
    the hymn offline probe (revisions, conflicts, deltas, categories).
    """

    @classmethod
    def setUpClass(cls):
        cls.sql25 = (ROOT / "sql/025_mezmur_hymn_offline.sql").read_text(encoding="utf-8")
        cls.rec = (
            ROOT / "admin/backend/services/MezmurSchemaReconciler.php"
        ).read_text(encoding="utf-8")
        cls.hymn_svc = (
            ROOT / "admin/backend/services/MezmurHymnService.php"
        ).read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        M = ROOT / "Mobile/wbws_flutter_app/lib"
        cls.store = (M / "services/hymn_store.dart").read_text(encoding="utf-8")
        cls.sync = (M / "services/sync_service.dart").read_text(encoding="utf-8")
        cls.db = (M / "services/local_db.dart").read_text(encoding="utf-8")
        cls.api = (M / "services/api_service.dart").read_text(encoding="utf-8")
        cls.screen = (M / "screens/mezmur/mezmur_hymns.dart").read_text(encoding="utf-8")
        cls.editor = (M / "screens/mezmur/mezmur_hymn_editor.dart").read_text(encoding="utf-8")
        cls.detail = (M / "screens/mezmur/mezmur_hymn_detail.dart").read_text(encoding="utf-8")
        cls.cats = (M / "screens/mezmur/mezmur_categories.dart").read_text(encoding="utf-8")

    # ── migration 025: additive, guarded, idempotent ──────────
    def test_sql025_contract(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_categories`", self.sql25)
        self.assertIn("UNIQUE KEY `uq_mezmur_categories_name`", self.sql25)
        self.assertIn("INSERT IGNORE INTO `mezmur_categories`", self.sql25)
        # guarded ALTER via information_schema probe (MySQL-safe)
        self.assertIn("information_schema.columns", self.sql25)
        self.assertIn("`revision` INT UNSIGNED NOT NULL DEFAULT 1", self.sql25)
        self.assertIn("idx_mezmur_hymns_updated", self.sql25)
        self.assertIn("(`updated_at`, `id`)", self.sql25)

    def test_reconciler_covers_new_objects(self):
        self.assertIn("'mezmur_categories' =>", self.rec)
        self.assertIn("'revision'   =>", self.rec)
        self.assertIn("INSERT IGNORE INTO mezmur_categories", self.rec)
        # legacy tables get UNIQUE(name) + dedupe before seeding,
        # and the delta BTREE index is ensured outside the migration
        self.assertIn("uq_mezmur_categories_name", self.rec)
        self.assertIn("DELETE c1 FROM mezmur_categories c1", self.rec)
        self.assertIn("idx_mezmur_hymns_updated", self.rec)

    # ── server service: writers + conflict + delta ────────────
    def test_save_hymn_validation_and_conflict(self):
        self.assertIn("Title is required.", self.hymn_svc)
        self.assertIn("mb_strlen($lyrics) > 200000", self.hymn_svc)
        self.assertIn("LOWER(title) = LOWER(?)", self.hymn_svc)
        # revision-based conflict returns the server copy
        self.assertIn("base_revision", self.hymn_svc)
        self.assertIn("'conflict' => true", self.hymn_svc)
        self.assertIn("revision = revision + 1", self.hymn_svc)
        self.assertIn("Mezmur Hymn Created", self.hymn_svc)
        self.assertIn("Mezmur Hymn Updated", self.hymn_svc)

    def test_status_change_is_audited_revision_bumped(self):
        self.assertIn("setStatusHymn", self.hymn_svc)
        self.assertIn("Mezmur Hymn Archived", self.hymn_svc)
        self.assertIn("Mezmur Hymn Restored", self.hymn_svc)

    def test_delta_pull_is_cursor_ordered_and_bounded(self):
        self.assertIn("ORDER BY updated_at ASC, id ASC", self.hymn_svc)
        self.assertIn("next_cursor", self.hymn_svc)
        self.assertIn("has_more", self.hymn_svc)
        # archived rows travel in deltas (deletes are never silent)
        self.assertNotIn("status = 'active'", self.hymn_svc.split("listChangedSince")[1].split("categories")[0])
        # lyrics blob opt-in only
        self.assertIn("includeLyrics", self.hymn_svc)
        self.assertIn("min($limit, $includeLyrics ? 100 : 500)", self.hymn_svc.replace("max(1, ", ""))

    def test_category_service_guards(self):
        self.assertIn("saveCategory", self.hymn_svc)
        self.assertIn("setCategoryStatus", self.hymn_svc)
        self.assertIn("LOWER(name) = LOWER(?)", self.hymn_svc)
        self.assertIn("categoriesReady", self.hymn_svc)

    # ── mobile routes: gated + idempotent + rate-limited ──────
    def test_routes_gate_writes_and_keep_reads_open(self):
        self.assertIn("$MEZMUR_LIBRARY_WRITE_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];", self.route)
        self.assertEqual(
            self.route.count("apiRoleIs($auth, $MEZMUR_LIBRARY_WRITE_ROLES)"), 4
        )
        self.assertEqual(self.route.count("apiIdempotencyBegin("), 5)  # sheet + 4 writers
        self.assertGreaterEqual(self.route.count("isApiRateLimited('mezmur_hymn_write'"), 4)

    def test_routes_delta_and_conflict_shapes(self):
        self.assertIn("($ROUTE['sub'] ?? '') === 'changes'", self.route)
        self.assertIn("listChangedSince(", self.route)
        # 409 conflict carries the server copy inside data.item
        self.assertIn("err($result['message'], 409, ['data' => ['item' => $result['item'] ?? null]]);", self.route)

    # ── client: local-first store ─────────────────────────────
    def test_store_is_local_first_and_role_gated(self):
        self.assertIn("mezmur_dept", self.store)
        self.assertIn("bool get canEdit => _writeRoles.contains(_api.userRole)", self.store)
        # optimistic local write precedes any network call
        save_block = self.store.split("Future<String?> saveHymn(")[1].split("Future<String?> setHymnStatus")[0]
        self.assertIn("_db.upsertHymns(", save_block)
        self.assertIn("_db.enqueueHymnOp('hymn_save'", save_block)

    def test_store_conflict_policy_server_copy_wins(self):
        self.assertIn("res.statusCode == 409", self.store)
        self.assertIn("conflict — server copy kept", self.store)

    def test_store_protects_pending_rows_from_deltas(self):
        self.assertIn("protectIds", self.store)
        self.assertIn("upsertHymns(items, protectIds: protect)", self.store)

    def test_store_coalesces_offline_edits_into_one_create(self):
        self.assertIn("getPendingHymnSavesForLocalId", self.store)
        self.assertIn("updateHymnOpPayload", self.store)

    def test_delta_cursor_persisted_locally(self):
        self.assertIn("getHymnSyncCursor", self.store)
        self.assertIn("setHymnSyncCursor(next)", self.store)
        self.assertIn("include_lyrics", self.api)

    # ── sync engine integration ───────────────────────────────
    def test_sync_engine_drains_hymn_outbox_and_pulls(self):
        self.assertIn("HymnStore()", self.sync)
        self.assertIn("pushPending()", self.sync)
        self.assertIn("pullChanges()", self.sync)
        self.assertIn("pendingHymns", self.sync)
        self.assertIn("hymn change", self.sync)

    # ── local DB contract ─────────────────────────────────────
    def test_localdb_v11_hymn_tables(self):
        self.assertIn("version: 11,", self.db)
        for t in ("cached_hymns", "pending_hymn_ops", "hymn_sync_meta",
                  "cached_mezmur_categories"):
            self.assertIn(f"CREATE TABLE IF NOT EXISTS {t}", self.db)
        self.assertIn("idx_cached_hymns_title", self.db)
        # Logout boundary (product decision 2026-08-28): member data is
        # wiped, the SHARED hymn library + queued hymn edits persist.
        wipe = self.db.split("clearAllUserData")[1]
        self.assertIn("'pending_mezmur',", wipe)
        self.assertNotIn("'cached_hymns',", wipe)
        self.assertNotIn("'pending_hymn_ops',", wipe)
        self.assertNotIn("'hymn_sync_meta',", wipe)
        # Queued hymn edits push only for curators (identity-safe).
        self.assertIn("if (!canEdit) return 0;", self.store)

    # ── UI: instant search, curation actions, offline notes ──
    def test_library_screen_local_first(self):
        self.assertIn("_store.hymns(", self.screen)
        self.assertIn("Duration(milliseconds: 150)", self.screen)  # debounce
        self.assertIn("OfflineBanner", self.screen)
        self.assertIn("RefreshIndicator", self.screen)
        self.assertIn("_store.canEdit", self.screen)
        self.assertIn("MezmurHymnEditorScreen", self.screen)
        self.assertIn("cloud_upload_outlined", self.screen)  # pending badge

    def test_editor_offline_contract(self):
        self.assertIn("Will sync automatically", self.editor.replace("will sync automatically", "Will sync automatically"))
        self.assertIn("_store.saveHymn(hymn, baseRevision: baseRevision)", self.editor)
        self.assertIn("maxLength: 255", self.editor)

    def test_detail_reader_is_local_first_with_lazy_lyrics(self):
        self.assertIn("_store.hymn(widget.id)", self.detail)
        self.assertIn("Lyrics not downloaded yet", self.detail)
        self.assertIn("_db.upsertHymns([item])", self.detail)

    def test_categories_screen_offline_crud(self):
        self.assertIn("_store.saveCategory", self.cats)
        self.assertIn("_store.setCategoryStatus", self.cats)
        self.assertIn("maxLength: 50", self.cats)
