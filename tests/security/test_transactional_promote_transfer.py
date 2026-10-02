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

    def test_transfer_requires_an_active_source_enrollment(self):
        """Finding K (2026-10-02): reproduced, then fixed.

        The source enrollment was closed with `WHERE id=?` and no status
        predicate, and affected_rows was never inspected. Replaying the
        request -- or two admins transferring at once -- therefore transferred
        an ALREADY-transferred enrollment and inserted a second 'active' row,
        leaving one member active in two classes simultaneously.

        Verified at runtime against a restored production copy before the fix:
        one replayed request produced 2 active enrollments for the same member
        in the same academic year; two concurrent requests did the same.

        The `unique_enrollment` key (member_id, class_id, academic_year_id)
        does NOT prevent this, because the two transfers name *different*
        target classes, so both INSERTs are unique.

        'promote' and EnrollmentService::transferByEnrollment() already
        enforced this rule; this path was the only one that did not.
        """
        # The precondition must live in the UPDATE itself, not only in the
        # earlier SELECT -- that is what makes it race-safe.
        self.assertRegex(
            self.transfer,
            r"UPDATE class_enrollments SET status='transferred'.*WHERE id=\?\s+AND status='active'",
            "the source-closing UPDATE must re-assert status='active' in its "
            "WHERE clause, or a replayed/concurrent transfer can close an "
            "already-transferred enrollment",
        )
        # A zero-row update means someone else won -- it must not be treated
        # as success.
        self.assertIn("affected_rows", self.transfer)
        self.assertIn("__ENROLLMENT_NOT_ACTIVE__", self.transfer)
        # ...and the caller must be told it was a conflict, not a crash.
        self.assertIn("409", self.transfer)

    def test_promote_requires_an_active_source_enrollment(self):
        """Finding K (2026-10-02): reproduced, then fixed.

        promote's pre-flight SELECT checks status='active', but that is a
        plain read. Verified at runtime against a restored production copy:
        two concurrent promotions of the same member to *different* target
        classes both passed the SELECT and both inserted an 'active' row,
        leaving the member active in two classes at once.

        `unique_enrollment` (member_id, class_id, academic_year_id) does not
        prevent it, because the target classes differ.
        """
        self.assertRegex(
            self.promote,
            r"UPDATE class_enrollments SET status = 'completed' WHERE id = \?\s+AND status = 'active'",
            "the source-closing UPDATE must re-assert status='active' so two "
            "concurrent promotions cannot both close the same enrollment",
        )
        self.assertIn("affected_rows", self.promote)
        self.assertIn("__ENROLLMENT_NOT_ACTIVE__", self.promote)
        self.assertIn("409", self.promote)

    def test_transfer_conflict_is_not_reported_as_an_internal_error(self):
        """A lost race is an expected outcome, not an internal fault."""
        self.assertIsNotNone(
            re.search(
                r"__ENROLLMENT_NOT_ACTIVE__.*?no longer active",
                self.transfer,
                re.S,
            ),
            "the conflict branch must return a user-facing 'no longer active' "
            "message instead of falling through to reportInternalError()",
        )

    def test_enrollment_service_participates_in_transactions(self):
        self.assertNotIn("$conn->in_transaction()", self.enrollment)
        self.assertIn("$withinTransaction", self.enrollment)
        self.assertIn("ROLLBACK TO SAVEPOINT", self.enrollment)
        self.assertIn("$ownsTransaction", self.enrollment)
        # Owned transactions are committed on success and rolled back on
        # failure; foreign ones are left to the caller.
        self.assertIn("$conn->commit();", self.enrollment)
        self.assertIn("$conn->rollback();", self.enrollment)


if __name__ == "__main__":
    unittest.main()
