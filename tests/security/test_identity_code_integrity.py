"""Regression tests for Fix 5 (M1-M4 + F8/L1): identity-code integrity and
user-management quick wins.

Guards:
  M1 registration/import/API never guess a category letter — unknown age
     groups produce a pending (NULL) code,
  M2 code allocation runs inside the registration transaction with the
     advisory lock held until commit/rollback; O(1) sequence table exists,
  M3 year_name uniqueness is enforced at app level and by a conditional
     UNIQUE index in migration 018,
  M4 user-delete detaches grade_submissions.reviewed_by (submitted_by
     never existed),
  F8/L1 user-toggle requires CSRF, supports both caller conventions and
     blocks self-lockout; school_admin sends the token.
"""

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]


class IdentityCodeIntegrityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.register = (ROOT / "admin/hr_register_member.php").read_text(encoding="utf-8")
        cls.identity = (
            ROOT / "admin/backend/services/IdentityCodeService.php"
        ).read_text(encoding="utf-8")
        cls.enrollment = (
            ROOT / "admin/backend/services/EnrollmentService.php"
        ).read_text(encoding="utf-8")
        cls.importer = (ROOT / "admin/api_import_members.php").read_text(encoding="utf-8")
        cls.migration = (
            ROOT / "sql/018_code_sequences_and_year_uniqueness.sql"
        ).read_text(encoding="utf-8")
        cls.api_education = (ROOT / "admin/api_education.php").read_text(encoding="utf-8")
        cls.user_delete = (ROOT / "admin/backend/user-delete.php").read_text(encoding="utf-8")
        cls.user_toggle = (ROOT / "admin/backend/user-toggle.php").read_text(encoding="utf-8")
        cls.school_admin = (
            ROOT / "admin/dashboards/school_admin.php"
        ).read_text(encoding="utf-8")

    # ── M1: never guess a category ────────────────────────────
    def test_registration_does_not_default_to_letter_a(self):
        self.assertNotIn("MemberCategory::LETTER_A", self.register)
        self.assertIn("letterFor($age_group)", self.register)
        self.assertIn("member_code_status", self.register)  # audit trail

    def test_generate_member_code_returns_null_without_age_group(self):
        self.assertIn("): ?string", self.enrollment)
        self.assertIn("if ($letter === null)", self.enrollment)
        self.assertIn("return null;", self.enrollment)

    def test_import_passes_row_age_group(self):
        self.assertIn("$rowData['age_group']", self.importer)
        self.assertNotIn("generateMemberCode($conn);", self.importer)

    # ── M2: allocation inside the transaction ────────────────
    def test_allocation_moves_inside_transaction(self):
        txn_pos = self.register.find("$conn->begin_transaction();")
        alloc_pos = self.register.find("allocateStudentHeld")
        self.assertGreater(txn_pos, 0)
        self.assertGreater(alloc_pos, txn_pos)
        # Lock is released on both success and failure paths.
        self.assertEqual(self.register.count("releaseCodeLock"), 2)

    def test_identity_service_supports_held_locks(self):
        self.assertIn("function allocateStudentHeld", self.identity)
        self.assertIn("function releaseCodeLock", self.identity)
        self.assertIn("RELEASE_LOCK", self.identity)

    def test_sequence_table_fast_path(self):
        self.assertIn("member_code_sequences", self.identity)
        self.assertIn("last_n = last_n + 1", self.identity)
        self.assertIn("member_code_sequences", self.migration)
        self.assertIn("INSERT IGNORE", self.migration)

    # ── M3: unique year names ─────────────────────────────────
    def test_year_name_app_level_precheck(self):
        self.assertIn("year_name = ? AND id <> ? LIMIT 1", self.api_education)
        self.assertIn(
            "An academic year with this name already exists", self.api_education
        )

    def test_year_name_conditional_unique_index(self):
        self.assertIn("uq_academic_years_year_name", self.migration)
        # The index is only added when no duplicates exist.
        self.assertIn("HAVING COUNT(*) > 1", self.migration)
        self.assertIn("v_duplicates = 0", self.migration)

    # ── M4: user-delete cleanup fix ───────────────────────────
    def test_user_delete_fixes_grade_submissions_cleanup(self):
        # No cleanup statement may reference the nonexistent column
        # (a comment explaining the fix may still mention it).
        self.assertNotIn(
            "UPDATE grade_submissions SET submitted_by", self.user_delete
        )
        self.assertIn(
            "UPDATE grade_submissions SET reviewed_by   = NULL WHERE reviewed_by   = :uid",
            self.user_delete,
        )

    # ── F8/L1: user-toggle hardening ──────────────────────────
    def test_user_toggle_requires_csrf(self):
        self.assertIn("requireCsrf();", self.user_toggle)

    def test_user_toggle_accepts_both_caller_conventions(self):
        self.assertIn("$_POST['user_id'] ?? ($_POST['id'] ?? 0)", self.user_toggle)
        self.assertIn("'toggle_status', 'activate', 'deactivate'", self.user_toggle)

    def test_user_toggle_blocks_self_lockout(self):
        self.assertIn("$_SESSION['admin_id']", self.user_toggle)
        self.assertIn("your own account", self.user_toggle)

    def test_school_admin_sends_csrf_on_toggle(self):
        self.assertIn("fd.append('csrf_token',CSRF);const r=await fetch('/admin/backend/user-toggle.php'", self.school_admin)

    # ── class-load smoke tests ────────────────────────────────
    def test_identity_service_classes_load(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        harness = (
            "require '" + str(ROOT / "admin/backend/services/MemberCategory.php") + "';"
            "require '" + str(ROOT / "admin/backend/services/IdentityCodeService.php") + "';"
            "echo \\App\\Services\\MemberCategory::letterFor('7_13') ?? 'none'; echo ',';"
            "echo \\App\\Services\\MemberCategory::letterFor('14_17') ?? 'none'; echo ',';"
            "echo \\App\\Services\\MemberCategory::letterFor('18_plus') ?? 'none'; echo ',';"
            "echo \\App\\Services\\MemberCategory::letterFor(null) ?? 'none'; echo ',';"
            "echo method_exists('\\App\\Services\\IdentityCodeService','allocateStudentHeld') ? 'held' : 'missing';"
        )
        completed = subprocess.run([php, "-r", harness], capture_output=True, text=True)
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(completed.stdout, "A,B,C,none,held")

    def test_php_syntax_all_touched_files(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        for rel in [
            "admin/hr_register_member.php",
            "admin/backend/services/IdentityCodeService.php",
            "admin/backend/services/EnrollmentService.php",
            "admin/api_import_members.php",
            "admin/api_education.php",
            "admin/backend/user-delete.php",
            "admin/backend/user-toggle.php",
            "admin/dashboards/school_admin.php",
        ]:
            completed = subprocess.run(
                [php, "-l", str(ROOT / rel)], capture_output=True, text=True
            )
            self.assertEqual(completed.returncode, 0, rel + ": " + completed.stdout)


if __name__ == "__main__":
    unittest.main()
