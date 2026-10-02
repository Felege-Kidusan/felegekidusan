"""Submission review state machine + resubmission hygiene.

Regression tests for audit 2026-10-02, findings A-D (plus the Education
attendance instance of C/D found during the same pass).

What went wrong
---------------
A/B  HrSubmissionService::reviewPacket() and MezmurSubmissionService::
     reviewPacket() read the packet's current status but used it ONLY to
     label the audit record. The UPDATE was `WHERE id = ?` with no status
     predicate and no affected_rows check, so a reviewer decision could be
     applied to a packet in ANY state: approved -> rejected,
     rejected -> approved, revision_needed -> approved, or a packet the
     taker had never submitted. Two reviewers deciding at once both won.
     Education already guarded this (finding H9); HR and Mezmur did not.

C/D  The upsert() paths never cleared review_notes / reviewed_by /
     reviewed_at, so a returned packet re-entered the reviewer's inbox
     still stamped with the previous decision. Education's upsertMarklist()
     already did this correctly (finding H10) — its upsertAttendance()
     sibling did not.

The rules these tests pin
------------------------
  * a reviewer decision is valid ONLY from 'submitted'
  * the UPDATE carries `AND status = 'submitted'` so the check-then-act
    race is closed at the storage layer, and affected_rows is verified
  * losing that race is a 409-style conflict, not a silent success
  * the immutable audit trail still records previous_status
  * role checks are unchanged (they live at the callers, by design)
  * the reviewer trail is cleared when a packet ENTERS the review queue,
    and NOT on draft saves or corrections that keep the current status
  * exactly ONE implementation of the state machine exists
"""

from pathlib import Path
import re
import sqlite3
import unittest

ROOT = Path(__file__).resolve().parents[2]
SERVICES = ROOT / "admin/backend/services"


def body_of(src: str, marker: str, end: str = "\n    public static function ") -> str:
    """Return the source of one method, from `marker` to the next method."""
    start = src.index(marker)
    nxt = src.find(end, start + len(marker))
    return src[start: nxt if nxt != -1 else len(src)]


class SharedPolicyExists(unittest.TestCase):
    """The rule lives in one department-neutral place."""

    @classmethod
    def setUpClass(cls):
        cls.policy = (SERVICES / "ReviewTransitionPolicy.php").read_text(encoding="utf-8")

    def test_policy_file_is_department_neutral(self):
        # It must not know about any department's tables or roles: that is
        # what lets HR and Mezmur share it without depending on Education.
        for leak in ("grade_submissions", "hr_submissions", "mezmur_submissions",
                     "mysqli", "hr_dept", "edu_dept"):
            self.assertNotIn(leak, self.policy,
                             f"ReviewTransitionPolicy must stay neutral, found {leak!r}")

    def test_policy_allows_only_submitted_to_be_decided(self):
        self.assertIn("if ($cur === self::STATUS_SUBMITTED)", self.policy)
        self.assertIn("return null;", self.policy)

    def test_policy_exposes_the_conflict_message(self):
        self.assertIn("function raceLostMessage", self.policy)
        self.assertIn("no longer awaiting review", self.policy)


class EducationBehaviourPreserved(unittest.TestCase):
    """Delegating must not change a single user-visible Education string."""

    @classmethod
    def setUpClass(cls):
        cls.sub = (SERVICES / "SubmissionService.php").read_text(encoding="utf-8")
        cls.policy = (SERVICES / "ReviewTransitionPolicy.php").read_text(encoding="utf-8")

    def test_education_delegates_rather_than_reimplementing(self):
        body = body_of(self.sub, "public static function reviewTransitionError")
        self.assertIn("ReviewTransitionPolicy::error", body)
        # the old inline copy of the rule must be gone from this method
        self.assertNotIn("case self::STATUS_APPROVED:", body)

    def test_only_one_state_machine_implementation_remains(self):
        """No module may carry its own copy of the transition messages."""
        owners = []
        for php in SERVICES.glob("*.php"):
            text = php.read_text(encoding="utf-8")
            if "is already approved." in text:
                owners.append(php.name)
        self.assertEqual(owners, ["ReviewTransitionPolicy.php"],
                         f"state machine duplicated into {owners}")

    @staticmethod
    def _render_php_concat(expr: str, noun: str, actor: str) -> str:
        """Evaluate a PHP 'a' . $var . 'b' expression for known variables.

        Walks the expression so that a '.' inside a quoted literal (e.g.
        "...yet. The...") is kept as text instead of being mistaken for the
        concatenation operator.
        """
        out, i, n = [], 0, len(expr)
        while i < n:
            ch = expr[i]
            if ch.isspace() or ch == ".":
                i += 1
            elif ch == "'":
                i += 1
                buf = []
                while i < n and expr[i] != "'":
                    if expr[i] == "\\" and i + 1 < n:
                        buf.append(expr[i + 1])
                        i += 2
                        continue
                    buf.append(expr[i])
                    i += 1
                i += 1  # closing quote
                out.append("".join(buf))
            elif ch == "$":
                match = re.match(r"\$\w+", expr[i:])
                name = match.group(0)
                known = {"$noun": noun, "$actor": actor}
                if name not in known:  # pragma: no cover - template drift
                    raise AssertionError(f"unexpected variable in policy: {name}")
                out.append(known[name])
                i += len(name)
            else:  # pragma: no cover - template drift
                raise AssertionError(f"unexpected fragment in policy: {expr[i:i + 20]!r}")
        return "".join(out)

    def test_education_messages_are_byte_identical_after_delegation(self):
        """Evaluate the PHP templates and compare to the pre-fix strings.

        These are the exact sentences Education returned before the rule
        moved into ReviewTransitionPolicy. Delegation is only safe if the
        templates still render them character for character.
        """
        before = {
            "open": "This list has not been submitted for review yet. "
                    "The teacher must submit it first.",
            "approved": "This list is already approved.",
            "rejected": "This list was rejected. Ask the teacher to submit a corrected list.",
            "revision": "This list was returned to the teacher and has not been resubmitted yet.",
            "fallback": "This list cannot be reviewed in its current state.",
        }
        body = body_of(self.policy, "public static function error(",
                       end="\n    /**")
        returns = re.findall(r"return\s+('(?:[^']|\\')*'(?:\s*\.\s*(?:\$\w+|'(?:[^']|\\')*'))*)\s*;",
                             body)
        rendered = [self._render_php_concat(expr, "list", "teacher") for expr in returns]
        for key, expected in before.items():
            with self.subTest(message=key):
                self.assertIn(expected, rendered,
                              f"Education's {key!r} message changed: {rendered}")
        # the five sentences above are the complete set the method can return
        self.assertEqual(len(rendered), len(before))

    def test_hr_and_mezmur_render_their_own_vocabulary(self):
        """The same templates must read correctly for a 'packet'/'taker'."""
        body = body_of(self.policy, "public static function error(",
                       end="\n    /**")
        returns = re.findall(r"return\s+('(?:[^']|\\')*'(?:\s*\.\s*(?:\$\w+|'(?:[^']|\\')*'))*)\s*;",
                             body)
        rendered = [self._render_php_concat(e, "packet", "taker") for e in returns]
        self.assertIn("This packet is already approved.", rendered)
        self.assertIn("This packet was rejected. Ask the taker to submit a corrected packet.",
                      rendered)
        for sentence in rendered:
            self.assertNotIn("list", sentence)
            self.assertNotIn("teacher", sentence)


class ReviewPacketIsGuarded(unittest.TestCase):
    """Findings A and B — the core fix, asserted per module."""

    MODULES = {
        "HrSubmissionService.php": "hr_submissions",
        "MezmurSubmissionService.php": "mezmur_submissions",
    }

    def _review_body(self, filename):
        src = (SERVICES / filename).read_text(encoding="utf-8")
        return body_of(src, "public static function reviewPacket")

    def test_transition_guard_runs_before_the_write(self):
        for filename in self.MODULES:
            with self.subTest(module=filename):
                body = self._review_body(filename)
                self.assertIn("ReviewTransitionPolicy::error(", body)
                self.assertIn("'invalid_transition'", body)
                guard = body.index("ReviewTransitionPolicy::error(")
                write = body.index("UPDATE ")
                self.assertLess(guard, write,
                                "the guard must precede the UPDATE")

    def test_update_is_race_safe_and_checked(self):
        for filename, table in self.MODULES.items():
            with self.subTest(module=filename):
                body = self._review_body(filename)
                self.assertIn(f"UPDATE {table}", body)
                self.assertIn("WHERE id = ? AND status = 'submitted'", body)
                self.assertIn("affected_rows", body)
                self.assertIn("'conflict'", body)
                self.assertIn("raceLostMessage", body)

    def test_unguarded_update_form_is_gone(self):
        """The exact defective statement must not come back."""
        for filename, table in self.MODULES.items():
            with self.subTest(module=filename):
                body = self._review_body(filename)
                offending = re.search(
                    r"UPDATE\s+" + table +
                    r"\s+SET status = \?, reviewed_by = \?, reviewed_at = NOW\(\), "
                    r"review_notes = \?\s+WHERE id = \?\"",
                    body)
                self.assertIsNone(offending,
                                  "review UPDATE lost its status predicate")

    def test_affected_rows_is_read_before_close(self):
        """$stmt->affected_rows is meaningless after close()."""
        for filename in self.MODULES:
            with self.subTest(module=filename):
                body = self._review_body(filename)
                self.assertLess(body.index("$affected = $up->affected_rows;"),
                                body.index("$up->close();"))

    def test_audit_trail_and_validation_preserved(self):
        for filename in self.MODULES:
            with self.subTest(module=filename):
                body = self._review_body(filename)
                self.assertIn("SecurityAuditService::record", body)
                self.assertIn("'previous_status' => $previousStatus", body)
                # pre-existing input validation must survive the patch
                self.assertIn("Invalid review parameters.", body)
                self.assertIn("Write a short reason so the taker knows what to fix.", body)
                self.assertIn("mb_substr($notes, 0, 500)", body)

    def test_audit_is_recorded_only_after_a_successful_write(self):
        for filename in self.MODULES:
            with self.subTest(module=filename):
                body = self._review_body(filename)
                self.assertLess(body.index("affected_rows"),
                                body.index("SecurityAuditService::record"))

    def test_role_checks_were_not_weakened(self):
        """Reviewer gating lives at the callers; canReview must be intact."""
        for filename in self.MODULES:
            with self.subTest(module=filename):
                src = (SERVICES / filename).read_text(encoding="utf-8")
                self.assertIn("function canReview", src)
                self.assertIn("'school_admin', 'super_admin'", src)

    def test_hr_does_not_depend_on_education_service(self):
        """Sharing the rule must not couple departments (see HR isolation)."""
        for filename in self.MODULES:
            with self.subTest(module=filename):
                src = (SERVICES / filename).read_text(encoding="utf-8")
                self.assertNotIn("SubmissionService::", src)
                self.assertIn("require_once __DIR__ . '/ReviewTransitionPolicy.php';", src)


class ResubmissionClearsStaleReview(unittest.TestCase):
    """Findings C and D, plus the Education attendance instance."""

    CASES = [
        ("HrSubmissionService.php", "UPDATE hr_submissions"),
        ("MezmurSubmissionService.php", "UPDATE mezmur_submissions"),
        ("SubmissionService.php", "UPDATE grade_submissions"),
    ]

    def test_upsert_clears_reviewer_columns_on_entering_the_queue(self):
        for filename, _ in self.CASES:
            with self.subTest(module=filename):
                src = (SERVICES / filename).read_text(encoding="utf-8")
                self.assertIn(
                    ", review_notes = NULL, reviewed_by = NULL, reviewed_at = NULL",
                    src)

    def test_clearing_is_conditional_not_unconditional(self):
        """Draft saves must keep the note the taker is working from."""
        for filename in ("HrSubmissionService.php", "MezmurSubmissionService.php"):
            with self.subTest(module=filename):
                src = (SERVICES / filename).read_text(encoding="utf-8")
                self.assertIn("$entersReviewQueue = $status === self::STATUS_SUBMITTED", src)
                self.assertIn(
                    "&& self::normalizeStatus($curStatus) !== self::STATUS_SUBMITTED", src)
                self.assertIn("$clearReviewSql = $entersReviewQueue", src)

    def test_education_attendance_matches_its_marklist_sibling(self):
        src = (SERVICES / "SubmissionService.php").read_text(encoding="utf-8")
        att = body_of(src, "public static function upsertAttendance")
        self.assertIn("$entersReviewQueue", att)
        self.assertIn("$clearReview", att)
        self.assertIn("review_notes = NULL", att)

    def test_reviewer_columns_are_nullable_in_schema(self):
        """Writing NULL is only safe because the DDL permits it."""
        for path, table in (("sql/026_hr_attendance.sql", "hr_submissions"),
                            ("sql/024_mezmur_submissions.sql", "mezmur_submissions")):
            with self.subTest(table=table):
                ddl = (ROOT / path).read_text(encoding="utf-8")
                self.assertIn("`reviewed_by`     INT UNSIGNED DEFAULT NULL", ddl)
                self.assertIn("`reviewed_at`     DATETIME DEFAULT NULL", ddl)
                self.assertIn("`review_notes`    VARCHAR(500) DEFAULT NULL", ddl)


class GuardedUpdateSemantics(unittest.TestCase):
    """Runtime proof of the SQL semantics the fix depends on.

    PHP/MySQL are not available in every environment this suite runs in, so
    the statement shapes extracted above are replayed against SQLite. This
    does not exercise the PHP; it proves the PREDICATE LOGIC is sound —
    that `AND status = 'submitted'` plus a rowcount check actually rejects
    every bad transition and makes a double decision impossible.
    """

    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute(
            "CREATE TABLE hr_submissions ("
            " id INTEGER PRIMARY KEY, status TEXT,"
            " reviewed_by INTEGER, reviewed_at TEXT, review_notes TEXT)")

    def _seed(self, status):
        self.db.execute("DELETE FROM hr_submissions")
        self.db.execute(
            "INSERT INTO hr_submissions VALUES (1, ?, NULL, NULL, NULL)", (status,))

    def _review(self, new_status, actor=7, notes="x"):
        cur = self.db.execute(
            "UPDATE hr_submissions"
            " SET status = ?, reviewed_by = ?, reviewed_at = 'now', review_notes = ?"
            " WHERE id = ? AND status = 'submitted'",
            (new_status, actor, notes, 1))
        return cur.rowcount

    def test_decision_from_submitted_succeeds(self):
        for target in ("approved", "rejected", "revision_needed"):
            with self.subTest(target=target):
                self._seed("submitted")
                self.assertEqual(self._review(target), 1)
                row = self.db.execute(
                    "SELECT status, reviewed_by FROM hr_submissions").fetchone()
                self.assertEqual(row, (target, 7))

    def test_every_invalid_source_state_is_rejected(self):
        """The defect: these all used to succeed."""
        for current in ("approved", "rejected", "revision_needed",
                        "draft", "incomplete"):
            for target in ("approved", "rejected", "revision_needed"):
                with self.subTest(current=current, target=target):
                    self._seed(current)
                    self.assertEqual(
                        self._review(target), 0,
                        f"{current} -> {target} must not be writable")
                    self.assertEqual(
                        self.db.execute(
                            "SELECT status FROM hr_submissions").fetchone()[0],
                        current, "row must be untouched")

    def test_concurrent_reviewers_only_one_wins(self):
        """Both reviewers read 'submitted'; only one UPDATE may match."""
        self._seed("submitted")
        first = self._review("approved", actor=11)
        second = self._review("rejected", actor=22)
        self.assertEqual((first, second), (1, 0))
        status, reviewer = self.db.execute(
            "SELECT status, reviewed_by FROM hr_submissions").fetchone()
        self.assertEqual((status, reviewer), ("approved", 11),
                         "the loser must not overwrite the winner's decision")

    def test_resubmission_clears_only_when_entering_the_queue(self):
        """Mirrors the $entersReviewQueue condition."""
        def upsert(current, new_status):
            self.db.execute("DELETE FROM hr_submissions")
            self.db.execute(
                "INSERT INTO hr_submissions VALUES (1, ?, 5, 'then', 'fix the totals')",
                (current,))
            enters = new_status == "submitted" and current != "submitted"
            clear = (", review_notes = NULL, reviewed_by = NULL, reviewed_at = NULL"
                     if enters else "")
            self.db.execute(
                f"UPDATE hr_submissions SET status = ?{clear} WHERE id = ?",
                (new_status, 1))
            return self.db.execute(
                "SELECT reviewed_by, review_notes FROM hr_submissions").fetchone()

        # returned packet genuinely re-enters the queue -> trail cleared
        self.assertEqual(upsert("revision_needed", "submitted"), (None, None))
        # ...including via the realistic draft round-trip
        self.assertEqual(upsert("draft", "submitted"), (None, None))
        # taker is still editing -> the reviewer's note must stay visible
        self.assertEqual(upsert("revision_needed", "draft"), (5, "fix the totals"))
        self.assertEqual(upsert("revision_needed", "incomplete"), (5, "fix the totals"))
        # correction that keeps the current status -> trail untouched
        self.assertEqual(upsert("submitted", "submitted"), (5, "fix the totals"))

    def tearDown(self):
        self.db.close()


if __name__ == "__main__":
    unittest.main()
