"""Regression tests for the Identity & Codes management system.

Guards:
  W1  the removed 'under6' category never resurfaces (service, schema
      baseline, test roster),
  W2  a Super Admin management UI exists and is wired into the dashboard,
  W3  latent fatals stay fixed — every identity file loads QR support via
      admin/id_cards/libs/qr_loader.php, never the missing qrlib.php,
  W4  categories are never guessed (no LETTER_A fallback in the hub API),
  W5  sql/019 ships member_type_settings and drops the under6 ENUM,
  W6  the renumbering runners resync member_code_sequences after execute,
  W7  MemberCodeFormat renders the N marker smaller and escapes input,
  W8  display surfaces (ID card, verification page, printout, CSV export)
      adopt MemberCodeFormat / MemberTypeService.
"""

import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PHP = shutil.which("php")


class IdentityManagementTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api_identity = (ROOT / "admin/api_identity.php").read_text(encoding="utf-8")
        cls.api_migration = (ROOT / "admin/api_identity_migration.php").read_text(encoding="utf-8")
        cls.cli_tool = (ROOT / "admin/tools/migrate_identity_codes.php").read_text(encoding="utf-8")
        cls.category = (
            ROOT / "admin/backend/services/MemberCategory.php"
        ).read_text(encoding="utf-8")
        cls.code_format = (
            ROOT / "admin/backend/services/MemberCodeFormat.php"
        ).read_text(encoding="utf-8")
        cls.type_service = (
            ROOT / "admin/backend/services/MemberTypeService.php"
        ).read_text(encoding="utf-8")
        cls.sql_019 = (
            ROOT / "sql/019_member_types_and_under6_removal.sql"
        ).read_text(encoding="utf-8")
        cls.schema_baseline = (ROOT / "database_schema.sql").read_text(encoding="utf-8")
        cls.super_admin = (ROOT / "admin/dashboards/super-admin.php").read_text(encoding="utf-8")
        cls.section = (
            ROOT / "admin/dashboards/sections/identity_codes_section.php"
        ).read_text(encoding="utf-8")
        cls.view_id_card = (ROOT / "admin/id_cards/view_id_card.php").read_text(encoding="utf-8")
        cls.card_template = (
            ROOT / "admin/id_cards/id_card_template_layout.php"
        ).read_text(encoding="utf-8")
        cls.member_page = (ROOT / "member.php").read_text(encoding="utf-8")
        cls.print_member = (ROOT / "admin/print_member.php").read_text(encoding="utf-8")
        cls.export_pdf = (ROOT / "admin/export_pdf.php").read_text(encoding="utf-8")
        cls.report_renderer = (
            ROOT / "admin/backend/services/MemberReportRenderer.php"
        ).read_text(encoding="utf-8")
        cls.test_roster = (
            ROOT / "admin/backend/data/test_members_roster.php"
        ).read_text(encoding="utf-8")

    # ── W1: under6 fully removed ────────────────────────────────────
    def test_member_category_has_no_under6_logic(self):
        # Comments may document the removal; live mapping logic must not exist.
        self.assertNotIn("=== 'under6'", self.category)
        self.assertNotIn("'under6' =>", self.category)
        self.assertNotIn("=== 'under 6'", self.category)
        self.assertIn("categories are never guessed", self.category)

    def test_schema_baseline_dropped_under6_enum(self):
        self.assertNotIn("'under6'", self.schema_baseline)
        self.assertIn("enum('7_13','14_17','18_plus')", self.schema_baseline)

    def test_test_roster_has_no_under6(self):
        self.assertNotIn("under6", self.test_roster)

    # ── W5: sql/019 ─────────────────────────────────────────────────
    def test_sql_019_creates_member_type_settings(self):
        self.assertIn("member_type_settings", self.sql_019)
        self.assertIn("regular", self.sql_019)
        self.assertIn("special_regular", self.sql_019)
        self.assertIn("honorary", self.sql_019)

    def test_sql_019_removes_under6_enum_idempotently(self):
        self.assertIn("under6", self.sql_019)  # the purge itself names the value
        # Conditional ENUM modification — never crash on already-migrated DBs.
        self.assertIn("information_schema`.`columns", self.sql_019.lower())
        self.assertIn("like '%under6%'", self.sql_019.lower())

    # ── W3: QR loading fixed everywhere ──────────────────────────────
    def test_no_qrlib_require_in_identity_files(self):
        for name, source in (
            ("api_identity.php", self.api_identity),
            ("api_identity_migration.php", self.api_migration),
            ("migrate_identity_codes.php", self.cli_tool),
        ):
            with self.subTest(file=name):
                self.assertNotIn(
                    "phpqrcode/qrlib.php", source, f"{name} still requires the missing qrlib"
                )
                self.assertIn("qr_loader.php", source)

    # ── W4: never guess a category ───────────────────────────────────
    def test_assign_positions_never_defaults_to_letter_a(self):
        self.assertNotIn("MemberCategory::LETTER_A", self.api_identity)
        self.assertIn("letterFor($ageGroup)) !== null", self.api_identity)

    def test_update_member_identity_uses_held_allocation(self):
        # Held allocation keeps the advisory lock until commit (M2 race fix).
        self.assertIn("allocateStudentHeld", self.api_identity)
        self.assertIn("releaseCodeLock", self.api_identity)

    # ── W2: management UI exists and is wired ────────────────────────
    def test_super_admin_allows_identity_section(self):
        self.assertIn("'identity'", self.super_admin)
        self.assertIn("data-section=\"identity\"", self.super_admin)
        self.assertIn("sections/identity_codes_section.php", self.super_admin)

    def test_section_ui_is_super_admin_only_consumption(self):
        # UI performs zero SQL; every change flows through the gated APIs.
        self.assertNotIn("FROM members", self.section)
        self.assertNotIn("INSERT INTO", self.section)
        self.assertIn("/admin/api_identity.php", self.section)
        self.assertIn("/admin/api_identity_migration.php", self.section)
        self.assertIn("csrf_token", self.section)

    def test_section_exposes_all_management_tabs(self):
        for tab in ("departments", "positions", "types", "members", "migration"):
            with self.subTest(tab=tab):
                self.assertIn(f'idc-pane-{tab}', self.section)
        self.assertIn("list_departments", self.section)
        self.assertIn("save_position", self.section)
        self.assertIn("save_member_type", self.section)
        self.assertIn("update_member_identity", self.section)
        self.assertIn("assign_positions", self.section)
        self.assertIn("dry_run", self.section)

    def test_section_renders_small_n_marker_client_side(self):
        self.assertIn("mc-min", self.section)

    # ── W6: sequence resync after renumber ───────────────────────────
    def test_runners_resync_code_sequences(self):
        for name, source in (
            ("api_identity_migration.php", self.api_migration),
            ("migrate_identity_codes.php", self.cli_tool),
        ):
            with self.subTest(file=name):
                self.assertIn("member_code_sequences", source)
                self.assertIn("GREATEST(last_n, VALUES(last_n))", source)

    # ── W7: MemberCodeFormat behaviour ───────────────────────────────
    def test_code_format_renders_small_n_and_escapes(self):
        self.assertIn("font-size:0.72em", self.code_format)
        self.assertIn("htmlspecialchars", self.code_format)
        if not PHP:
            self.skipTest("php CLI not available")
        script = (
            "require '{root}/admin/backend/services/MemberCodeFormat.php';"
            "use App\\Services\\MemberCodeFormat;"
            "$a = MemberCodeFormat::html('EDHNT-83719');"
            "$b = MemberCodeFormat::html('A12');"
            "$c = MemberCodeFormat::html('<script>-x');"
            "if (substr_count($a, 'mc-min') !== 1) exit(1);"
            "if (strpos($a, 'EDH<span') !== 0) exit(2);"
            "if ($b !== 'A12') exit(3);"
            "if (strpos($c, '<script>') !== false) exit(4);"
            "echo 'ok';"
        ).format(root=ROOT)
        result = subprocess.run([PHP, "-r", script], capture_output=True, text=True)
        self.assertEqual(result.stdout.strip(), "ok", result.stderr)

    # ── W8: display surfaces adopt the formatting/labels ────────────
    def test_id_card_template_uses_code_format(self):
        self.assertIn("MemberCodeFormat::html", self.card_template)
        self.assertNotIn(
            "htmlspecialchars((string)$member['member_code'])", self.card_template
        )

    def test_view_id_card_title_is_escaped(self):
        title_line = next(
            (line for line in self.view_id_card.splitlines() if "<title>ID Card" in line), ""
        )
        self.assertIn("htmlspecialchars", title_line)

    def test_member_verification_page_renders_codes_safely(self):
        self.assertIn("MemberCodeFormat::html", self.member_page)
        self.assertIn("file_exists", self.member_page)  # graceful degradation

    def test_print_member_uses_format_and_type_labels(self):
        self.assertIn("MemberCodeFormat::html", self.print_member)
        self.assertIn("MemberTypeService::labelAm", self.print_member)

    def test_csv_export_uses_editable_type_labels(self):
        self.assertIn("$memberTypeLabels", self.report_renderer)
        self.assertIn("MemberTypeService::labels", self.export_pdf)
        # Optional parameter — old call sites keep working.
        self.assertIn("array $memberTypeLabels = []", self.report_renderer)

    def test_type_service_degrades_before_migration_019(self):
        self.assertIn("FALLBACK", self.type_service)
        self.assertIn("catch (\\Throwable $error)", self.type_service)

    # ── client/server section allow-lists must never drift ──────────
    def test_shell_js_allowlist_covers_php_sections(self):
        js = (ROOT / "admin/js/super_admin.js").read_text(encoding="utf-8")
        match = re.search(r"var ALLOWED = \{([^}]*)\}", js)
        self.assertIsNotNone(match, "super_admin.js ALLOWED map not found")
        js_sections = set(re.findall(r"(\w+):\s*1", match.group(1)))

        php_match = re.search(r"\$saAllowedSections = \[([^\]]*)\]", self.super_admin)
        self.assertIsNotNone(php_match, "$saAllowedSections not found")
        php_sections = set(re.findall(r"'(\w+)'", php_match.group(1)))

        missing = php_sections - js_sections
        self.assertFalse(
            missing,
            f"super_admin.js ALLOWED map is missing sections {sorted(missing)} — "
            f"their nav buttons would silently ignore clicks",
        )

    # ── lint every PHP file touched by this batch ────────────────────
    def test_php_syntax_of_identity_files(self):
        if not PHP:
            self.skipTest("php CLI not available")
        files = [
            "admin/api_identity.php",
            "admin/api_identity_migration.php",
            "admin/tools/migrate_identity_codes.php",
            "admin/backend/services/MemberCategory.php",
            "admin/backend/services/MemberTypeService.php",
            "admin/backend/services/MemberCodeFormat.php",
            "admin/backend/services/MemberReportRenderer.php",
            "admin/dashboards/super-admin.php",
            "admin/dashboards/sections/identity_codes_section.php",
            "admin/print_member.php",
            "admin/id_cards/id_card_template_layout.php",
            "admin/id_cards/view_id_card.php",
            "admin/export_pdf.php",
            "member.php",
        ]
        for rel in files:
            with self.subTest(file=rel):
                result = subprocess.run(
                    [PHP, "-l", str(ROOT / rel)], capture_output=True, text=True
                )
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
