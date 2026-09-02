"""Security & integrity tests for the Mezmur Department module (መዝሙር ክፍል).

Pins every guard the module relies on so a future refactor cannot
silently open the hymn library to the wrong roles, bypass CSRF, leak
exception internals, or break the separated front/back contract:

  - central access-control map entries
  - page shell role/feature gates (separated frontend pattern)
  - API controller: session gate, role re-check, feature gate (fail
    closed), CSRF, POST-only mutations, prepared statements, LIKE
    escaping, pagination clamp, no exception internals in responses
  - backend shim contract
  - sql/021 schema safety (new objects only, guarded seeds, FK policy)
  - role catalogue wired in every guard list
"""

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class MezmurModuleTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.access = (ROOT / "admin/access_control.php").read_text(encoding="utf-8")
        cls.page = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        cls.shim = (ROOT / "backend/api/mezmur.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.sql = (ROOT / "sql/021_mezmur_department.sql").read_text(encoding="utf-8")
        cls.guard = (ROOT / "admin/backend/services/AdminSessionGuard.php").read_text(encoding="utf-8")
        cls.impersonate = (ROOT / "admin/api_impersonate.php").read_text(encoding="utf-8")
        cls.user_toggle = (ROOT / "admin/backend/user-toggle.php").read_text(encoding="utf-8")
        cls.user_save = (ROOT / "admin/backend/user-save.php").read_text(encoding="utf-8")
        cls.hymn_svc = (ROOT / "admin/backend/services/MezmurHymnService.php").read_text(encoding="utf-8")

    # ── central gate ───────────────────────────────────────────
    def test_access_control_entries_admit_only_mezmur_roles(self):
        for entry in ["'mezmur.php'", "'api_mezmur.php'", "'mezmur_dept.php'"]:
            line = next((l for l in self.access.splitlines() if entry in l), None)
            self.assertIsNotNone(line, f"{entry} missing from ROLE_MAP")
            for role in ["'super_admin'", "'school_admin'", "'mezmur_dept'"]:
                self.assertIn(role, line)
            # no cross-department leakage
            self.assertNotIn("'finance_dept'", line)
            self.assertNotIn("'teacher'", line)

    # ── page shell (separated frontend pattern) ────────────────
    def test_page_shell_declares_role_and_feature_gates(self):
        self.assertIn("$requiredRoles = ['super_admin', 'school_admin', 'mezmur_dept'];", self.page)
        self.assertIn("$requiredFeature = 'mezmur';", self.page)
        self.assertIn("require __DIR__ . '/../layouts/base.php';", self.page)

    def test_page_shell_contains_no_queries_or_inline_logic(self):
        self.assertNotIn("$conn->", self.page)
        self.assertNotIn("mysqli", self.page)
        self.assertIn("$pageScript = 'mezmur';", self.page)

    # ── API controller guards ──────────────────────────────────
    def test_api_enforces_session_role_feature_and_csrf(self):
        self.assertIn("empty($_SESSION['admin_logged_in'])", self.api)
        self.assertIn("session_expired", self.api)
        self.assertIn("in_array($mezmurRole, ['super_admin', 'school_admin', 'mezmur_dept'], true)", self.api)
        self.assertIn("FeatureGate::isEnabled('mezmur')", self.api)
        self.assertIn("requireCsrfForPost()", self.api)
        # mutations must be POST-only (GET/CSRF-safe side effects)
        self.assertIn("'save', 'set_status'", self.api)
        self.assertIn("$_SERVER['REQUEST_METHOD'] !== 'POST'", self.api)

    def test_api_uses_prepared_statements_only(self):
        self.assertIn("$conn->prepare(", self.api)
        self.assertIn("bind_param(", self.api)
        # no interpolated WHERE/VALUES fragments built from request data
        self.assertNotIn("$_GET['search'] . '", self.api)
        self.assertNotIn("query(\"SELECT * FROM mezmur_hymns WHERE title", self.api)

    def test_api_escapes_like_wildcards_and_clamps_pagination(self):
        self.assertIn("ESCAPE", self.api)
        self.assertIn("str_replace(['\\\\', '%', '_']", self.api)
        self.assertIn("$perPage < 1 || $perPage > 100", self.api)

    def test_api_never_leaks_exception_internals(self):
        self.assertIn("catch (\\Throwable $e)", self.api)
        self.assertIn("error_log(", self.api)
        # getMessage() appears exactly four times and never reaches the
        # client in raw form: two server-side error_log lines (the
        # catch-all and the unhandled-exception handler, which sends only
        # a log token), plus the controlled DomainException 422 validator
        # message and the DomainException 409 packet lock/save message
        # thrown by our own services (never driver/diagnostic text).
        self.assertEqual(self.api.count("$e->getMessage()"), 4)
        self.assertIn("error_log('[mezmur] ' . $e->getMessage()", self.api)
        self.assertIn("error_log('[mezmur-unhandled #' . $token . '] ' . get_class($e) . ': '", self.api)
        # the unhandled handler must answer 200 with a token, no internals
        self.assertIn("Unexpected server fault (log reference", self.api)
        self.assertIn("catch (\DomainException $e)", self.api)
        self.assertNotIn("getTrace", self.api)

    def test_api_soft_deletes_only(self):
        self.assertIn("case 'set_status':", self.api)
        self.assertIn("in_array($status, ['active', 'archived'], true)", self.api)
        self.assertNotIn("DELETE FROM mezmur_hymns", self.api)

    def test_api_input_bounds(self):
        self.assertIn("mb_strlen($title) > 255", self.hymn_svc)
        self.assertIn("mb_strlen($lyrics) > 200000", self.hymn_svc)
        self.assertIn("mb_substr($search, 0, 100)", self.api)

    # ── shim contract ──────────────────────────────────────────
    def test_shim_delegates_to_admin_controller(self):
        self.assertIn("require_once __DIR__ . '/../../admin/api_mezmur.php';", self.shim)

    # ── front-end controller discipline ────────────────────────
    def test_js_escapes_all_dynamic_html(self):
        self.assertIn("function esc(", self.js)
        self.assertIn("esc(h.title)", self.js)
        self.assertIn("esc(h.category)", self.js)
        # P28: title_am retired; lyrics render escaped-first (renderLyrics),
        # never raw user text into innerHTML.
        self.assertIn("var txt = esc(", self.js)
        # mutations go through POST helper (CSRF auto-appended)
        self.assertIn("window.api.post('mezmur.php'", self.js)

    # ── schema safety ──────────────────────────────────────────
    def test_sql_creates_new_objects_only_and_is_idempotent(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS `mezmur_hymns`", self.sql)
        self.assertIn("WHERE NOT EXISTS (SELECT 1 FROM `departments` WHERE `code` = 'MZ')", self.sql)
        # no destructive statements against existing objects
        self.assertNotIn("DROP ", self.sql)
        self.assertNotIn("ALTER TABLE `users`", self.sql)

    def test_sql_scale_and_integrity_settings(self):
        self.assertIn("ENGINE=InnoDB", self.sql)
        self.assertIn("utf8mb4", self.sql)
        self.assertIn("ON DELETE SET NULL", self.sql)          # never cascade into users
        self.assertIn("idx_mezmur_hymns_status_category", self.sql)
        self.assertIn("BIGINT UNSIGNED", self.sql)

    # ── role wired into every guard list ───────────────────────
    def test_role_present_in_all_guard_lists(self):
        self.assertIn("'mezmur_dept'", self.guard)            # KNOWN_ROLES
        self.assertIn("'mezmur_dept'", self.impersonate)      # switch allowlist
        self.assertIn("'mezmur_dept'", self.user_toggle)      # management lists
        self.assertIn("'mezmur_dept',", self.user_save)       # creation whitelist
        self.assertNotIn("'mezmur_dept'", self.guard.split("PRIVILEGED_ROLES")[1].split(";")[0])

    # ── lint ───────────────────────────────────────────────────
    def test_module_files_lint_clean(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        for rel in [
            "admin/api_mezmur.php",
            "backend/api/mezmur.php",
            "frontend/pages/mezmur_dept.php",
        ]:
            proc = subprocess.run(
                [php, "-l", str(ROOT / rel)],
                capture_output=True, text=True, timeout=30,
            )
            self.assertEqual(proc.returncode, 0, f"php -l failed for {rel}: {proc.stdout}{proc.stderr}")


if __name__ == "__main__":
    unittest.main()
