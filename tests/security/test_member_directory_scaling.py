import json
from pathlib import Path
import shutil
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[2]


class MemberDirectoryScalingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.controller = (ROOT / "admin/api_list_members.php").read_text()
        cls.service = (
            ROOT / "admin/backend/services/MemberDirectoryService.php"
        ).read_text()
        cls.manager_js = (ROOT / "admin/js/manage-members.js").read_text()
        cls.picker_js = (ROOT / "frontend/js/member-picker.js").read_text()
        cls.archive_js = (ROOT / "admin/js/archive-members.js").read_text()
        cls.export_controller = (ROOT / "admin/api_export_members.php").read_text()
        cls.export_service = (
            ROOT / "admin/backend/services/MemberExportService.php"
        ).read_text()

    def test_real_database_integration_over_one_hundred_thousand_rows(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        completed = subprocess.run(
            [
                php,
                str(ROOT / "tests/fixtures/member_directory_integration.fixture"),
                str(ROOT),
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=30,
            check=False,
        )
        output = completed.stdout.strip()
        self.assertNotIn("Fatal error", output + completed.stderr)
        result = json.loads(output.splitlines()[-1])
        self.assertEqual(result["rows"], 100025)
        self.assertEqual(result["first_page"], 50)
        self.assertEqual(result["second_page"], 50)
        self.assertGreater(result["total"], 75000)
        self.assertEqual(result["editable_rows"], 2000)
        self.assertEqual(result["audit_rows"], 1)
        self.assertEqual(result["id_card_rows"], 50)
        self.assertEqual(result["archive_rows"], 50)
        self.assertEqual(result["report_rows"], 5000)

    def test_controller_delegates_domain_query_and_is_get_only(self):
        self.assertIn("new MemberDirectoryService($pdo)", self.controller)
        self.assertIn("REQUEST_METHOD'] !== 'GET'", self.controller)
        self.assertNotIn("SELECT ", self.controller)
        self.assertIn("Cache-Control: no-store, private", self.controller)

    def test_service_bounds_pages_and_uses_keyset_cursor(self):
        self.assertIn("public const MAX_PAGE_SIZE = 200", self.service)
        self.assertIn("`id` < ?", self.service)
        self.assertIn("'include_total'", self.service)
        self.assertIn("$criteria['limit'] + 1", self.service)
        self.assertIn("MATCH ({$columnSql}) AGAINST (? IN BOOLEAN MODE)", self.service)
        self.assertIn("'next_cursor' => $nextCursor", self.service)
        self.assertIn("LIMIT ? OFFSET ?", self.service)  # legacy page adapter remains bounded
        self.assertNotIn("SELECT *", self.service)

    def test_role_aware_projection_minimizes_finance_data(self):
        finance_branch = self.service.split("if ($role === 'finance_dept')", 1)[1]
        self.assertIn("return self::PICKER_COLUMNS", finance_branch)
        picker_block = self.service.split("private const PICKER_COLUMNS", 1)[1].split("];", 1)[0]
        for sensitive in ["guardian_name", "phone_number", "city", "student_photo_path"]:
            self.assertNotIn(sensitive, picker_block)

    def test_management_dashboards_share_server_backed_renderer(self):
        for relative in [
            "admin/dashboards/info-dept.php",
            "admin/dashboards/hr-dept.php",
        ]:
            source = (ROOT / relative).read_text()
            self.assertIn('/admin/js/manage-members.js', source)
            self.assertIn('id="manageMembersPagination"', source)
            self.assertNotIn("embeddedManageMembers", source)
            self.assertNotIn("function loadManageMembers()", source)
        self.assertIn("view: 'manager'", self.manager_js)
        self.assertIn("params.set('cursor'", self.manager_js)
        self.assertIn("AbortController", self.manager_js)

        school = (ROOT / "admin/dashboards/school_admin.php").read_text()
        self.assertIn("include_options", school)
        self.assertIn("memberCursors", school)
        self.assertIn("AbortController", school)
        self.assertNotIn('value="999999">All', school)
        self.assertIn("tier=all&amp;format=csv", school)

    def test_embedded_dashboard_rosters_are_replaced_with_search(self):
        info = (ROOT / "admin/dashboards/info-dept.php").read_text()
        hr = (ROOT / "admin/dashboards/hr-dept.php").read_text()
        self.assertNotIn("LIMIT 400", info)
        self.assertNotIn("$membersForAttaker", info + hr)
        for source in [info, hr]:
            self.assertIn('data-member-picker-target="attakerMemberId"', source)
            self.assertIn('data-member-picker-status="active"', source)
        self.assertIn("input.dataset.memberPickerStatus", self.picker_js)

    def test_archive_directory_is_bounded_minimal_and_server_filtered(self):
        compatibility = (ROOT / "admin/info_get_archived_members.php").read_text()
        self.assertIn("'archive'", compatibility)
        self.assertIn("new MemberDirectoryService($pdo)", compatibility)
        self.assertNotIn("$members[]", compatibility)
        self.assertIn("view: 'archive'", self.archive_js)
        self.assertIn("limit: '50'", self.archive_js)
        self.assertIn("params.set('cursor'", self.archive_js)
        self.assertIn("params.set('archive_type'", self.archive_js)
        self.assertIn("replaceChildren", self.archive_js)
        self.assertNotIn("innerHTML", self.archive_js)
        for relative in [
            "admin/dashboards/info-dept.php",
            "admin/dashboards/hr-dept.php",
        ]:
            source = (ROOT / relative).read_text()
            self.assertIn('/admin/js/archive-members.js', source)
            self.assertIn('id="archivedMembersPagination"', source)
            self.assertNotIn("function loadArchivedMembers()", source)
            self.assertNotIn("info_get_archived_members.php", source)

    def test_finance_picker_searches_instead_of_loading_full_roster(self):
        self.assertIn("view: 'picker'", self.picker_js)
        self.assertIn("limit: '50'", self.picker_js)
        self.assertIn("AbortController", self.picker_js)
        for relative in [
            "frontend/pages/finance_dept.php",
            "admin/dashboards/finance_department.php",
        ]:
            source = (ROOT / relative).read_text()
            self.assertIn("data-member-picker-target=", source)

    def test_id_card_directory_is_bounded_and_generation_is_post_only(self):
        id_card_js = (ROOT / "admin/js/id-card-directory.js").read_text()
        generator = (ROOT / "admin/id_cards/generate_id_card.php").read_text()
        viewer = (ROOT / "admin/id_cards/view_id_card.php").read_text()
        access = (ROOT / "admin/access_control.php").read_text()
        self.assertIn("view: 'id_cards'", id_card_js)
        self.assertIn("limit: '50'", id_card_js)
        self.assertIn("include_total", id_card_js)
        self.assertIn("form.method = 'post'", id_card_js)
        self.assertIn("validateCsrf", generator)
        self.assertIn("REQUEST_METHOD'] ?? 'GET') !== 'POST'", generator)
        self.assertIn("FOR UPDATE", generator)
        self.assertIn("SecurityAuditService::record", generator)
        self.assertIn("'qr_member_' . (int)$memberId", generator)
        self.assertNotIn("mkdir(", generator)
        self.assertNotIn("generate_id_card.php?member_id=", viewer)
        self.assertIn(
            "'generate_id_card.php' => ['super_admin', 'school_admin', 'hr_dept']",
            access,
        )
        hr = (ROOT / "admin/dashboards/hr-dept.php").read_text()
        info = (ROOT / "admin/dashboards/info-dept.php").read_text()
        self.assertIn('/admin/js/id-card-directory.js', hr)
        self.assertNotIn("$id_sql", info + hr)

    def test_complete_export_streams_and_never_collects_rows(self):
        self.assertIn("MemberExportService::streamCsv", self.export_controller)
        self.assertIn("['temporary', 'permanent', 'all']", self.export_controller)
        self.assertIn("php://output", self.export_service)
        self.assertIn("while ($row = $statement->fetch(PDO::FETCH_ASSOC))", self.export_service)
        stream_method = self.export_service.split("public static function streamCsv", 1)[1]
        self.assertNotIn("$data[]", stream_method)
        self.assertIn("connection_aborted()", stream_method)
        self.assertIn("spreadsheetSafeValue", stream_method)

    def test_editable_export_is_bounded_and_audited(self):
        self.assertIn("MAX_EDITABLE_ROWS = 2000", self.export_service)
        self.assertIn("bounded_workbook_rejected", self.export_controller)
        self.assertIn("SecurityAuditService::record", self.export_controller)
        self.assertIn("format=csv", (ROOT / "admin/dashboards/hr-dept.php").read_text())

    def test_scaling_indexes_are_deployment_managed(self):
        migration = (ROOT / "sql/014_member_directory_scaling.sql").read_text()
        self.assertIn("idx_members_status_id", migration)
        self.assertIn("idx_members_tier_id", migration)
        self.assertIn("idx_members_archive_type_id", migration)
        self.assertIn("idx_ce_member_year_status_id", migration)
        self.assertIn("ft_members_directory", migration)
        self.assertNotIn("014_member_directory_scaling", self.controller)
        self.assertNotIn("ALTER TABLE", self.controller + self.service)


if __name__ == "__main__":
    unittest.main()
