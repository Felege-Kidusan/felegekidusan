"""Save paths must re-assert the review lock inside the UPDATE.

Regression tests for audit 2026-10-02 cycle 2, finding K.

WHAT IS WRONG
-------------
All four packet "upsert" paths guarded the review lock like this:

    SELECT status FROM <table> WHERE id = ?        -- read
    if (!statusIsOpen($curStatus) && !$force) { return 'already submitted'; }
    ...
    UPDATE <table> SET status = ?, ... WHERE id = ?   -- write, NO predicate

That is check-then-act. Between the read and the write a reviewer can approve
the packet, and the save then lands on an approved row: the counts are
overwritten, `status` is dragged back to draft/submitted, and the
`$clearReview` branch wipes review_notes / reviewed_by / reviewed_at. The
approval is silently undone and the audit trail loses the reviewer.

This is the same defect class as findings A and B, which were fixed on the
*review* transition. The save side was missed.

THE FIX
-------
The UPDATE repeats the precondition the SELECT checked:

    WHERE id = ? AND status IN ('draft','incomplete','revision_needed')

so a packet that stopped being open cannot be written. `affected_rows` is
then inspected; because MySQL reports 0 affected rows for a no-op save as
well as for a blocked one, the status is re-read to tell the two apart
before reporting the lock. An explicit staff override ('force') still
bypasses the guard, exactly as before.

The open-status set is not invented here: all three services define
`statusIsOpen()` as {draft, incomplete, revision_needed}, so `submitted`,
`approved` and `rejected` are closed to the author.
"""

from pathlib import Path
import re
import sqlite3
import unittest

ROOT = Path(__file__).resolve().parents[2]

PATHS = {
    "education-attendance": ("admin/backend/services/SubmissionService.php", "upsertAttendance"),
    "education-marklist": ("admin/backend/services/SubmissionService.php", "upsertMarklist"),
    "hr-attendance": ("admin/backend/services/HrSubmissionService.php", "upsert"),
    "mezmur-attendance": ("admin/backend/services/MezmurSubmissionService.php", "upsert"),
}

OPEN_SET = "AND status IN ('draft','incomplete','revision_needed')"


def method_body(path: str, name: str) -> str:
    src = (ROOT / path).read_text(encoding="utf-8")
    start = src.index(f"function {name}(")
    nxt = src.find("\n    public static function ", start + 10)
    return src[start: nxt if nxt != -1 else len(src)]


class EverySavePathGuardsTheUpdate(unittest.TestCase):
    def test_update_carries_the_open_status_predicate(self):
        for label, (path, name) in PATHS.items():
            with self.subTest(path=label):
                self.assertIn(OPEN_SET, method_body(path, name),
                              f"{label}: UPDATE must re-assert the open-status precondition")

    def test_guard_is_bypassable_only_by_an_explicit_override(self):
        for label, (path, name) in PATHS.items():
            with self.subTest(path=label):
                body = method_body(path, name)
                self.assertRegex(body, r"\$lockGuard\s*=",
                                 f"{label}: expected a named lock guard")
                self.assertRegex(body, r"(?s)\$lockGuard.*?(force)",
                                 f"{label}: the guard must key off the force override")

    def test_affected_rows_is_inspected_after_the_guarded_update(self):
        for label, (path, name) in PATHS.items():
            with self.subTest(path=label):
                body = method_body(path, name)
                self.assertIn("affected_rows", body,
                              f"{label}: a guarded UPDATE is pointless if the result is ignored")

    def test_blocked_save_is_reported_as_locked_not_as_success(self):
        for label, (path, name) in PATHS.items():
            with self.subTest(path=label):
                body = method_body(path, name)
                self.assertRegex(
                    body, r"(?s)affected_rows.*?statusIsOpen\(\$vStatus\).*?'ok'\s*=>\s*false",
                    f"{label}: a lost race must return ok=false, not a silent success")

    def test_no_op_save_is_not_misreported_as_locked(self):
        """0 affected rows is ambiguous in MySQL; the code must re-read."""
        for label, (path, name) in PATHS.items():
            with self.subTest(path=label):
                body = method_body(path, name)
                self.assertRegex(body, r"SELECT status FROM \w+ WHERE id = \? LIMIT 1",
                                 f"{label}: must re-read status before blaming the lock")

    def test_guard_matches_each_services_own_open_status_definition(self):
        """The literal must not drift from statusIsOpen()."""
        for path in {p for p, _ in PATHS.values()}:
            with self.subTest(path=path):
                src = (ROOT / path).read_text(encoding="utf-8")
                fn = src[src.index("function statusIsOpen"):]
                fn = fn[:fn.index("\n    }")]
                self.assertIn("STATUS_DRAFT", fn)
                self.assertIn("STATUS_INCOMPLETE", fn)
                self.assertIn("STATUS_REVISION", fn)
                self.assertNotIn("STATUS_SUBMITTED", fn,
                                 "submitted became open; the SQL guard literal must be updated too")


class GuardedUpdateSemantics(unittest.TestCase):
    """Runtime proof of the predicate, replayed on SQLite."""

    def _db(self, status):
        db = sqlite3.connect(":memory:")
        db.execute("CREATE TABLE p (id INTEGER PRIMARY KEY, status TEXT,"
                   " present INTEGER, review_notes TEXT)")
        db.execute("INSERT INTO p VALUES (1, ?, 10, 'please fix')", (status,))
        return db

    GUARDED = ("UPDATE p SET status = ?, present = ?, review_notes = NULL"
               " WHERE id = 1 AND status IN ('draft','incomplete','revision_needed')")
    UNGUARDED = "UPDATE p SET status = ?, present = ?, review_notes = NULL WHERE id = 1"

    def test_unguarded_update_overwrites_an_approved_packet(self):
        """Demonstrates the defect."""
        db = self._db("approved")
        db.execute(self.UNGUARDED, ("submitted", 99))
        row = db.execute("SELECT status, present, review_notes FROM p").fetchone()
        self.assertEqual(row, ("submitted", 99, None),
                         "the approval and the reviewer trail were silently destroyed")

    def test_guarded_update_refuses_an_approved_packet(self):
        db = self._db("approved")
        cur = db.execute(self.GUARDED, ("submitted", 99))
        self.assertEqual(cur.rowcount, 0)
        self.assertEqual(db.execute("SELECT status, present, review_notes FROM p").fetchone(),
                         ("approved", 10, "please fix"))

    def test_guarded_update_refuses_a_submitted_packet(self):
        db = self._db("submitted")
        cur = db.execute(self.GUARDED, ("draft", 99))
        self.assertEqual(cur.rowcount, 0)
        self.assertEqual(db.execute("SELECT status FROM p").fetchone()[0], "submitted")

    def test_guarded_update_still_allows_every_open_status(self):
        for status in ("draft", "incomplete", "revision_needed"):
            with self.subTest(status=status):
                db = self._db(status)
                cur = db.execute(self.GUARDED, ("submitted", 42))
                self.assertEqual(cur.rowcount, 1, f"{status} must remain writable by the author")
                self.assertEqual(db.execute("SELECT status, present FROM p").fetchone(),
                                 ("submitted", 42))

    def test_the_race_window_is_what_the_guard_closes(self):
        """Approval lands between the author's read and write."""
        db = self._db("submitted")
        seen = db.execute("SELECT status FROM p WHERE id = 1").fetchone()[0]
        self.assertEqual(seen, "submitted")
        db.execute("UPDATE p SET status='approved' WHERE id=1 AND status='submitted'")
        cur = db.execute(self.GUARDED, ("draft", 0))
        self.assertEqual(cur.rowcount, 0, "the stale read must not authorise the write")
        self.assertEqual(db.execute("SELECT status FROM p").fetchone()[0], "approved")


if __name__ == "__main__":
    unittest.main()
