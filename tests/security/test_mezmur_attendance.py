"""Security & integrity tests for Mezmur attendance, analytics,
attendance-taker management and the mobile surface (Fix/Feature 2).

Pins the non-negotiables:
  - separate dataset (never touches class attendance)
  - complete-sheet validation + transactional replace + UNIQUE guard
  - audit trail for decision-critical records
  - rate limiting on every surface, fail-closed feature gate
  - analytics aggregate server-side; sort columns whitelisted
  - attendance-taker creation stays scoped to attendance_taker
  - mobile route is role-gated in one place (core/acl discipline)
"""

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class MezmurAttendanceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql = (ROOT / "sql/022_mezmur_attendance.sql").read_text(encoding="utf-8")
        cls.sql23 = (ROOT / "sql/023_mezmur_date_attendance.sql").read_text(encoding="utf-8")
        cls.service = (
            ROOT / "admin/backend/services/MezmurAttendanceService.php"
        ).read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.index = (ROOT / "api/v1/index.php").read_text(encoding="utf-8")
        cls.gate = (ROOT / "admin/backend/services/FeatureGate.php").read_text(encoding="utf-8")
        cls.user_save = (ROOT / "admin/backend/user-save.php").read_text(encoding="utf-8")
        cls.user_toggle = (ROOT / "admin/backend/user-toggle.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.shell = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.dart_config = (
            ROOT / "Mobile/wbws_flutter_app/lib/utils/config.dart"
        ).read_text(encoding="utf-8")
        cls.dart_shell = (
            ROOT / "Mobile/wbws_flutter_app/lib/screens/shell/app_shell.dart"
        ).read_text(encoding="utf-8")
        cls.dart_api = (
            ROOT / "Mobile/wbws_flutter_app/lib/services/api_service.dart"
        ).read_text(encoding="utf-8")

    # ── schema ─────────────────────────────────────────────────
    def test_schema_is_additive_and_idempotent(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_sessions`", self.sql)
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_attendance`", self.sql)
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_attendance_audit`", self.sql)
        self.assertNotIn("DROP ", self.sql)
        self.assertNotIn("ALTER TABLE `attendance`", self.sql)

    def test_schema_integrity_and_scale(self):
        # one mark per member per session — idempotent resubmits
        self.assertIn("UNIQUE KEY `uq_mezmur_attendance_session_member` (`session_id`, `member_id`)", self.sql)
        self.assertIn("KEY `idx_mezmur_attendance_member` (`member_id`, `session_id`)", self.sql)
        self.assertIn("BIGINT UNSIGNED", self.sql)
        self.assertIn("utf8mb4", self.sql)
        # sessions are soft-deletable; members/users FKs are sane
        self.assertIn("ENUM('active','deleted')", self.sql)
        self.assertIn("ON DELETE SET NULL", self.sql)

    def test_dataset_is_separate_from_class_attendance(self):
        self.assertNotIn("`attendance`", self.sql.replace("`mezmur_attendance`", ""))

    def test_date_based_migration_is_guarded_and_non_destructive(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_days`", self.sql23)
        self.assertIn("UNIQUE KEY `uq_mezmur_days_date` (`attendance_date`)", self.sql23)
        self.assertIn("`uq_mezmur_attendance_date_member` (`attendance_date`, `member_id`)", self.sql23)
        self.assertIn("PREPARE stmt FROM @mz_add_date; EXECUTE stmt; DEALLOCATE PREPARE stmt;", self.sql23)
        self.assertIn("SET a.attendance_date = s.session_date", self.sql23)
        self.assertNotIn("DROP", self.sql23)

    # ── domain service ─────────────────────────────────────────
    def test_service_enforces_complete_sheet(self):
        self.assertIn("count($submitted) !== count($roster)", self.service)
        self.assertIn("The sheet is out of date with the current roster", self.service)

    def test_service_validates_inputs(self):
        self.assertIn("in_array($status, self::STATUSES, true)", self.service)
        self.assertIn("in_array($programType, self::PROGRAM_TYPES, true)", self.service)
        self.assertIn("preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)", self.service)

    def test_service_save_is_transactional_and_audited(self):
        self.assertIn("$conn->begin_transaction()", self.service)
        self.assertIn("$conn->commit()", self.service)
        self.assertIn("$conn->rollback()", self.service)
        self.assertIn("mezmur_attendance_audit", self.service)
        self.assertIn("'sheet_saved'", self.service)
        self.assertIn("'day_created'", self.service)

    def test_service_is_date_based_not_session_based(self):
        self.assertIn("saveSheet(\mysqli $conn, string $date", self.service)
        self.assertIn("DELETE FROM mezmur_attendance WHERE attendance_date = ?", self.service)
        self.assertIn("Attendance cannot be recorded for a future date.", self.service)
        self.assertIn("ensureDay(", self.service)
        self.assertIn("programJoinFilter(", self.service)

    def test_service_analytics_are_bounded_and_whitelisted(self):
        # date windows hard-capped at two years (scan bound)
        self.assertIn("'-2 years'", self.service)
        # sort columns come from a whitelist, never raw input
        self.assertIn("self::SORTABLE[$sortKey] ?? 'rate'", self.service)
        # pagination clamped
        self.assertIn("min($perPage, 100)", self.service)
        # LIKE wildcard escaping on member search
        self.assertIn("str_replace(['\\\\', '%', '_']", self.service)

    # ── web API ────────────────────────────────────────────────
    def test_api_rate_limits_reads_and_writes(self):
        self.assertIn("SecurityRateLimiter", self.api)
        self.assertIn("'mezmur_write'", self.api)
        self.assertIn("'mezmur_read'", self.api)
        self.assertIn("$__rlCheck['allowed']", self.api)

    def test_api_write_actions_are_post_only(self):
        self.assertIn("'save_sheet', 'day_create'", self.api)
        self.assertIn("$_SERVER['REQUEST_METHOD'] !== 'POST'", self.api)

    def test_api_is_date_based_and_probes_023(self):
        self.assertIn("case 'days_list':", self.api)
        self.assertIn("case 'day_create':", self.api)
        self.assertIn("sql/023_mezmur_date_attendance.sql", self.api)

    def test_api_sheet_size_and_domain_errors(self):
        self.assertIn("count($records) > 500000", self.api)
        self.assertIn("catch (\\DomainException $e)", self.api)
        # 022 schema probe message exists
        self.assertIn("sql/022_mezmur_attendance.sql", self.api)

    # ── mobile surface ─────────────────────────────────────────
    def test_mobile_route_registered_and_gated(self):
        self.assertIn("'mezmur'        => 'mezmur.php',", self.index)
        self.assertIn("'mezmur' => 'mezmur',", self.gate)  # forApiResource
        self.assertIn("apiRoleIs($auth, $MEZMUR_ROLES)", self.route)
        # analytics stay restricted to mezmur staff + admins
        self.assertIn("apiRoleIs($auth, $MEZMUR_ANALYTICS_ROLES)", self.route)
        # reuses the single-writer service — no duplicated SQL domain
        self.assertIn("MezmurAttendanceService::saveSheet", self.route)
        self.assertIn("$action === 'days'", self.route)
        self.assertIn("(string)($input['date'] ?? '')", self.route)
        # idempotency + rate limiting on mobile saves
        self.assertIn("apiIdempotencyBegin(", self.route)
        self.assertIn("isApiRateLimited('mezmur_sheet_save'", self.route)

    def test_mobile_sheet_strips_pii_fields(self):
        self.assertIn("unset($m['full_name_am']);", self.route)
        self.assertIn("unset($r['full_name_am'], $r['photo_url']);", self.route)

    # ── taker creation stays scoped ────────────────────────────
    def test_mezmur_may_only_create_attendance_takers(self):
        self.assertIn("'mezmur_dept' => ['attendance_taker'],", self.user_save)
        self.assertIn("'mezmur_dept' => ['attendance_taker'],", self.user_toggle)
        # and nothing broader was granted
        self.assertNotIn("'mezmur_dept' => ['teacher']", self.user_save)

    # ── web UI discipline ──────────────────────────────────────
    def test_shell_has_all_sections_and_gates(self):
        for section in ['library', 'attendance', 'analytics', 'takers']:
            self.assertIn('id="section-%s"' % section, self.shell)
        self.assertIn("$requiredRoles = ['super_admin', 'school_admin', 'mezmur_dept'];", self.shell)
        self.assertIn("$requiredFeature = 'mezmur';", self.shell)
        self.assertIn('id="mzAttDate"', self.shell)
        self.assertIn('Mezmur.openDay()', self.shell)
        self.assertNotIn('mzSessionModal', self.shell)

    def test_js_new_modules_escape_output(self):
        self.assertIn("esc(t.username)", self.js)
        self.assertIn("esc(m.student_name)", self.js)
        self.assertIn("esc(m.section)", self.js)
        # mutations go through POST helper (CSRF auto-appended)
        self.assertIn("action: 'save_sheet'", self.js)
        self.assertIn("action: 'day_create'", self.js)
        self.assertNotIn("openSessionModal", self.js)
        self.assertNotIn("sessions_list", self.js)
        self.assertIn("SSMS.api.post('/admin/backend/user-save.php'", self.js)

    # ── flutter wiring ─────────────────────────────────────────
    def test_flutter_role_and_navigation_wired(self):
        self.assertIn("static const String mezmurDept = 'mezmur_dept';", self.dart_config)
        self.assertIn("case mezmurDept: return 'Mezmur Department';", self.dart_config)
        self.assertIn("case mezmurDept: return 'መዝሙር ክፍል';", self.dart_config)
        self.assertIn("id: 'mezmur_attendance'", self.dart_config)
        self.assertIn("mezmurEnabled", self.dart_config)
        self.assertIn("UserRoles.mezmurDept: 'mezmur',", self.dart_shell)
        self.assertIn("MezmurHomeScreen(key: _mezmurHomeKey)", self.dart_shell)
        self.assertIn("case 'mezmur_attendance':", self.dart_shell)

    def test_flutter_api_surface(self):
        for method in [
            "Future<ApiResponse> getMezmurDays(",
            "Future<ApiResponse> createMezmurDay(",
            "Future<ApiResponse> getMezmurSheet(String date)",
            "Future<ApiResponse> saveMezmurSheet(",
            "Future<ApiResponse> getMezmurAnalytics(",
        ]:
            self.assertIn(method, self.dart_api)
        self.assertIn("idempotencyKey: clientOpId", self.dart_api)

    # ── lint ───────────────────────────────────────────────────
    def test_new_php_lints_clean(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        for rel in [
            "admin/api_mezmur.php",
            "admin/backend/services/MezmurAttendanceService.php",
            "api/v1/routes/mezmur.php",
            "frontend/pages/mezmur_dept.php",
        ]:
            proc = subprocess.run(
                [php, "-l", str(ROOT / rel)],
                capture_output=True, text=True, timeout=30,
            )
            self.assertEqual(proc.returncode, 0, f"php -l failed for {rel}: {proc.stdout}{proc.stderr}")


if __name__ == "__main__":
    unittest.main()
