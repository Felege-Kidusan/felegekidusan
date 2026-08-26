"""Regression tests for Fix 2 (H3): atomic promote + enrollment transfer.

Guards that promote/transfer run all-or-nothing inside a database
transaction and that EnrollmentService participates in outer transactions.
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]


def extract_case(source: str, case_name: str) -> str:
    """Extract one switch case block from api_education.php."""
    start = source.find("case '" + case_name + "':")
    if start < 0:
        return ""
    match = re.search(r"\n    case '", source[start + 10:])
    end = start + 10 + match.start() if match else len(source)
    return source[start:end]


class TransactionalPromoteTransferTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api_education = (ROOT / "admin/api_education.php").read_text(encoding="utf-8")
        cls.enrollment = (
            ROOT / "admin/backend/services/EnrollmentService.php"
        ).read_text(encoding="utf-8")
        cls.promote = extract_case(cls.api_education, "promote")
        cls.transfer = extract_case(cls.api_education, "transfer_student")

    def test_promote_is_transactional(self):
        self.assertIn("$conn->begin_transaction();", self.promote)
        self.assertIn("$conn->commit();", self.promote)
        self.assertIn("$conn->rollback();", self.promote)

    def test_promote_validates_source_and_target(self):
        # Must verify an ACTIVE enrollment exists in the source class...
        self.assertIn("status = 'active' LIMIT 1", self.promote)
        # ...and that the target class exists before mutating anything.
        self.assertIn("SELECT id FROM classes WHERE id = ?", self.promote)
        # Self-promotion is rejected.
        self.assertIn("$fromClassId === $toClassId", self.promote)

    def test_promote_reports_no_partial_state(self):
        self.assertIn("No changes were made.", self.promote)

    def test_transfer_is_transactional(self):
        self.assertIn("$conn->begin_transaction();", self.transfer)
        self.assertIn("$conn->commit();", self.transfer)
        self.assertIn("$conn->rollback();", self.transfer)

    def test_transfer_validates_target_class(self):
        self.assertIn("SELECT id FROM classes WHERE id = ?", self.transfer)
        self.assertIn("Target class does not exist.", self.transfer)

    def test_enrollment_service_participates_in_transactions(self):
        self.assertIn("in_transaction()", self.enrollment)
        self.assertIn("$ownsTransaction", self.enrollment)
        # Owned transactions are committed on success and rolled back on
        # failure; foreign ones are left to the caller.
        self.assertIn("$conn->commit();", self.enrollment)
        self.assertIn("$conn->rollback();", self.enrollment)


if __name__ == "__main__":
    unittest.main()
