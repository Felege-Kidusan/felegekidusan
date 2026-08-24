"""Regression checks for member upload validation and private document serving."""
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]


class PrivateMemberFileTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = (ROOT / "admin/backend/services/MemberFileService.php").read_text(encoding="utf-8")
        cls.controller = (ROOT / "admin/member_file.php").read_text(encoding="utf-8")
        cls.manage = (ROOT / "admin/info_manage_member.php").read_text(encoding="utf-8")
        cls.register = (ROOT / "admin/hr_register_member.php").read_text(encoding="utf-8")

    def test_uploads_are_validated_by_content_and_randomly_named(self):
        for requirement in (
            "is_uploaded_file($tmp)",
            "new \\finfo(FILEINFO_MIME_TYPE)",
            "getimagesize($tmp)",
            "hasPdfHeader($tmp)",
            "random_bytes(16)",
            "MAX_BYTES = 5242880",
        ):
            self.assertIn(requirement, self.service)
        self.assertNotIn("pathinfo($file['name']", self.service)

    def test_guardian_photos_and_documents_use_private_storage(self):
        self.assertIn("'guardian_photo' => ['category' => 'guardian_photos', 'private' => true", self.service)
        for field in ("doc_school_records", "doc_spiritual", "doc_signed_form"):
            self.assertIn(f"'{field}' => ['category' => 'docs', 'private' => true", self.service)
        self.assertIn("dirname($projectRoot) . '/ssms_private/members'", self.service)
        self.assertIn("private://members/", self.service)

    def test_private_controller_looks_up_allowlisted_database_fields(self):
        self.assertIn("requireAuth();", self.controller)
        self.assertIn("hasRole(['super_admin', 'school_admin', 'info_dept', 'hr_dept'])", self.controller)
        for field in (
            "guardian_photo_path",
            "doc_school_records_path",
            "doc_spiritual_path",
            "doc_signed_form_path",
        ):
            self.assertIn(f"'{field}'", self.controller)
        self.assertNotIn("$_GET['path']", self.controller)
        self.assertIn("Cache-Control: private, no-store", self.controller)
        self.assertIn("X-Content-Type-Options: nosniff", self.controller)

    def test_legacy_private_directories_are_denied(self):
        parent = (ROOT / "admin/uploads/.htaccess").read_text(encoding="utf-8")
        self.assertIn("members/(docs|guardian_photos)", parent)
        for relative in (
            "admin/uploads/members/docs/.htaccess",
            "admin/uploads/members/guardian_photos/.htaccess",
        ):
            rules = (ROOT / relative).read_text(encoding="utf-8")
            self.assertIn("Require all denied", rules)
            self.assertIn("Deny from all", rules)

    def test_member_forms_delegate_to_the_upload_service(self):
        for source in (self.manage, self.register):
            self.assertIn("MemberFileService::storeRequestUpload", source)
            self.assertNotIn("move_uploaded_file(", source)
        self.assertIn("MemberFileService::discard", self.manage)
        self.assertIn("MemberFileService::discard", self.register)
        self.assertNotIn("fixPath($m['guardian_photo_path'])", self.manage)

    def test_other_upload_surfaces_verify_content_and_bound_work(self):
        branding = (ROOT / "admin/api_branding.php").read_text(encoding="utf-8")
        gallery = (ROOT / "admin/backend/services/GalleryService.php").read_text(encoding="utf-8")
        workbook = (ROOT / "admin/api_import_members.php").read_text(encoding="utf-8")
        for source in (branding, gallery, workbook):
            self.assertIn("is_uploaded_file(", source)
            self.assertIn("filesize(", source)
        self.assertIn("getimagesize($tmpPath)", branding)
        self.assertIn("begin_transaction()", branding)
        self.assertIn(".rollback-", branding)
        self.assertIn("new ZipArchive()", workbook)
        self.assertIn("$expandedBytes > 50 * 1024 * 1024", workbook)
        self.assertIn("$zip->numFiles > 5000", workbook)


if __name__ == "__main__":
    unittest.main()
