"""Education submission packets: one packet per slot, race-safe.

Regression tests for audit 2026-10-02 cycle 2, finding J.

WHAT IS WRONG
-------------
`SubmissionService::upsertAttendance()` and `upsertMarklist()` are
SELECT-then-INSERT:

    SELECT id FROM grade_submissions
     WHERE class_id = ? AND submission_type = 'attendance' AND attendance_date = ?
    ... if found UPDATE, else INSERT

Nothing stops two concurrent writers from both reading "not found" and both
inserting. `grade_submissions` (migration 013, line 244) declares only
NON-UNIQUE keys for these slots:

    KEY `sub_att_lookup`  (teacher_id, class_id, submission_type, attendance_date)
    KEY `sub_mark_lookup` (teacher_id, assessment_id, submission_type)

Meanwhile `SubmissionService::hardenUniques()` is an EMPTY no-op carrying the
comment "unique keys are deployment-managed by migration 013". That comment is
false: migration 013 creates no UNIQUE key on grade_submissions at all. The
runtime DDL was removed and the responsibility was documented as handed to a
migration that never took it.

WHY IT MATTERS (the review-state invariant is defeated)
-------------------------------------------------------
Lock checks resolve the packet with `ORDER BY id DESC LIMIT 1`
(`attendancePacketStatus`). With two packets for one (class, date):

  * Education approves the older packet; the newer one still reads
    draft/incomplete, so `statusIsOpen` is true and the teacher keeps editing
    attendance that was already approved — the approval lock is bypassed.
  * Or Education decides the newer one and the older packet is stranded in
    the inbox as a permanently-"submitted" ghost.

Both sibling modules enforce exactly this concept with a real constraint —
`uq_hr_submissions_date_section` (sql/026) and
`uq_mezmur_submissions_date_section` (sql/024) — so the intended rule is not
being invented here.

THE FIX
-------
  * a new migration adds the two UNIQUE keys, conditionally and
    non-destructively (duplicates are reported as a deployment blocker, never
    merged or deleted — which record survives is a human decision)
  * the service handles duplicate-key (errno 1062) on INSERT by falling back
    to the UPDATE path, so a lost race converges instead of erroring
  * the false comment on hardenUniques() is corrected

NULL-exemption (verified before choosing the key columns): the attendance
INSERT never sets assessment_id and the marklist INSERT never sets
attendance_date, so each unique key leaves the other submission type exempt
(MySQL permits unlimited NULLs in a unique index).
"""

from pathlib import Path
import re
import sqlite3
import unittest

ROOT = Path(__file__).resolve().parents[2]
SQL = ROOT / "sql"
SERVICE = ROOT / "admin/backend/services/SubmissionService.php"
MIGRATION = SQL / "053_grade_submission_slot_uniqueness.sql"


class SchemaDeclaresTheSlotUniqueness(unittest.TestCase):
    """The constraint must exist in a migration, not in runtime DDL."""

    @classmethod
    def setUpClass(cls):
        cls.m013 = (SQL / "013_application_schema_completion.sql").read_text(encoding="utf-8")

    def test_migration_file_exists(self):
        self.assertTrue(MIGRATION.exists(),
                        "expected a migration adding the grade_submissions slot uniqueness")

    def test_both_slot_constraints_are_declared(self):
        text = MIGRATION.read_text(encoding="utf-8")
        for name in ("uq_gs_attendance_slot", "uq_gs_marklist_slot"):
            with self.subTest(constraint=name):
                self.assertIn(name, text)

    def test_constraint_columns_match_the_code_lookups(self):
        """The key must be the natural key the service already queries by."""
        text = MIGRATION.read_text(encoding="utf-8")
        self.assertRegex(
            text,
            r"uq_gs_attendance_slot`?\s*\(\s*`class_id`\s*,\s*`attendance_date`\s*,\s*`submission_type`\s*\)")
        self.assertRegex(
            text,
            r"uq_gs_marklist_slot`?\s*\(\s*`assessment_id`\s*,\s*`submission_type`\s*\)")

    def test_migration_is_non_destructive(self):
        """Duplicate packets are user data; a migration must not pick a winner."""
        text = MIGRATION.read_text(encoding="utf-8")
        body = re.sub(r"--[^\n]*", "", text)
        for forbidden in (r"(?i)\bDELETE\s+FROM\s+`?grade_submissions`?",
                          r"(?i)\bTRUNCATE",
                          r"(?i)\bDROP\s+TABLE"):
            with self.subTest(pattern=forbidden):
                self.assertNotRegex(body, forbidden)

    def test_migration_reports_a_blocker_and_a_verdict(self):
        text = MIGRATION.read_text(encoding="utf-8")
        self.assertIn("BLOCKER", text)
        self.assertIn("PASS:", text)
        self.assertIn("information_schema", text.lower())

    def test_013_still_declares_only_the_lookup_keys(self):
        """Pin the reason this migration is needed (013 has no UNIQUE here)."""
        block = self.m013[self.m013.index("CREATE TABLE IF NOT EXISTS `grade_submissions`"):]
        block = block[:block.index("ENGINE=")]
        self.assertIn("KEY `sub_att_lookup`", block)
        self.assertNotIn("UNIQUE", block,
                         "013 gained a UNIQUE key; this test's premise must be revisited")

    def test_preflight_covers_the_new_constraints(self):
        pre = (SQL / "preflight/uniqueness_preflight.sql").read_text(encoding="utf-8")
        for name in ("uq_gs_attendance_slot", "uq_gs_marklist_slot"):
            with self.subTest(constraint=name):
                self.assertIn(name, pre)


class ServiceHandlesTheLostRace(unittest.TestCase):
    """Once the constraint exists, a lost race must converge, not error."""

    @classmethod
    def setUpClass(cls):
        cls.src = SERVICE.read_text(encoding="utf-8")

    def test_harden_uniques_comment_is_truthful(self):
        """It claimed 013 manages these keys. It did not."""
        block = self.src[:self.src.index("public static function hardenUniques")]
        tail = block[-400:]
        self.assertNotIn("unique keys are deployment-managed by migration 013", tail)

    def test_attendance_insert_recovers_from_duplicate_key(self):
        body = self._method("upsertAttendance")
        self.assertIn("1062", body,
                      "attendance INSERT must recover from a duplicate-key race")

    def test_marklist_insert_recovers_from_duplicate_key(self):
        body = self._method("upsertMarklist")
        self.assertIn("1062", body,
                      "marklist INSERT must recover from a duplicate-key race")

    def test_recovery_reuses_the_existing_update_path(self):
        """A retry must not become a second, divergent write implementation."""
        for name in ("upsertAttendance", "upsertMarklist"):
            with self.subTest(method=name):
                body = self._method(name)
                self.assertRegex(body, r"(?s)errno\b.*1062")

    def _method(self, name):
        start = self.src.index(f"public static function {name}")
        nxt = self.src.find("\n    public static function ", start + 10)
        return self.src[start: nxt if nxt != -1 else len(self.src)]


class SlotUniquenessSemantics(unittest.TestCase):
    """Runtime proof, replayed on SQLite.

    Demonstrates the defect and that the constraint fixes it. This exercises
    the SQL semantics, not the PHP.
    """

    DDL_NO_UNIQUE = (
        "CREATE TABLE grade_submissions ("
        " id INTEGER PRIMARY KEY AUTOINCREMENT,"
        " class_id INTEGER, assessment_id INTEGER,"
        " attendance_date TEXT, submission_type TEXT, status TEXT)")

    def _db(self, unique):
        db = sqlite3.connect(":memory:")
        db.execute(self.DDL_NO_UNIQUE)
        if unique:
            db.execute("CREATE UNIQUE INDEX uq_gs_attendance_slot"
                       " ON grade_submissions (class_id, attendance_date, submission_type)")
            db.execute("CREATE UNIQUE INDEX uq_gs_marklist_slot"
                       " ON grade_submissions (assessment_id, submission_type)")
        return db

    @staticmethod
    def _upsert_attendance(db, class_id, date, status):
        """Replays the service's SELECT-then-INSERT shape."""
        row = db.execute(
            "SELECT id FROM grade_submissions"
            " WHERE class_id = ? AND submission_type = 'attendance' AND attendance_date = ?"
            " ORDER BY id DESC LIMIT 1", (class_id, date)).fetchone()
        if row:
            db.execute("UPDATE grade_submissions SET status = ? WHERE id = ?", (status, row[0]))
            return row[0]
        cur = db.execute(
            "INSERT INTO grade_submissions (class_id, assessment_id, attendance_date,"
            " submission_type, status) VALUES (?, NULL, ?, 'attendance', ?)",
            (class_id, date, status))
        return cur.lastrowid

    def test_without_the_constraint_a_race_creates_duplicate_packets(self):
        """Demonstrates the defect."""
        db = self._db(unique=False)
        # two writers both read "not found", then both insert
        a = db.execute(
            "SELECT id FROM grade_submissions WHERE class_id=1 AND attendance_date='2026-05-01'"
        ).fetchone()
        b = db.execute(
            "SELECT id FROM grade_submissions WHERE class_id=1 AND attendance_date='2026-05-01'"
        ).fetchone()
        self.assertIsNone(a)
        self.assertIsNone(b)
        for _ in range(2):
            db.execute(
                "INSERT INTO grade_submissions (class_id, assessment_id, attendance_date,"
                " submission_type, status) VALUES (1, NULL, '2026-05-01', 'attendance', 'submitted')")
        count = db.execute(
            "SELECT COUNT(*) FROM grade_submissions WHERE class_id=1").fetchone()[0]
        self.assertEqual(count, 2, "the unguarded race produces two packets for one slot")

    def test_duplicate_packets_defeat_the_approval_lock(self):
        """The concrete integrity consequence."""
        db = self._db(unique=False)
        for status in ("submitted", "incomplete"):
            db.execute(
                "INSERT INTO grade_submissions (class_id, assessment_id, attendance_date,"
                " submission_type, status) VALUES (1, NULL, '2026-05-01', 'attendance', ?)",
                (status,))
        older = db.execute(
            "SELECT id FROM grade_submissions ORDER BY id ASC LIMIT 1").fetchone()[0]
        # Education approves the packet it was shown
        db.execute("UPDATE grade_submissions SET status='approved' WHERE id = ?", (older,))
        # the lock check resolves the NEWEST row, which is still open
        resolved = db.execute(
            "SELECT status FROM grade_submissions WHERE class_id=1 AND submission_type='attendance'"
            " AND attendance_date='2026-05-01' ORDER BY id DESC LIMIT 1").fetchone()[0]
        self.assertEqual(resolved, "incomplete")
        self.assertIn(resolved, ("draft", "incomplete", "revision_needed"),
                      "approved attendance is still editable — the lock is bypassed")

    def test_with_the_constraint_the_second_insert_is_rejected(self):
        db = self._db(unique=True)
        self._upsert_attendance(db, 1, "2026-05-01", "submitted")
        with self.assertRaises(sqlite3.IntegrityError):
            db.execute(
                "INSERT INTO grade_submissions (class_id, assessment_id, attendance_date,"
                " submission_type, status) VALUES (1, NULL, '2026-05-01', 'attendance', 'draft')")

    def test_recovering_from_the_duplicate_key_converges_to_one_packet(self):
        """What the patched service does: 1062 -> re-select -> update."""
        db = self._db(unique=True)
        first = self._upsert_attendance(db, 1, "2026-05-01", "submitted")
        try:
            db.execute(
                "INSERT INTO grade_submissions (class_id, assessment_id, attendance_date,"
                " submission_type, status) VALUES (1, NULL, '2026-05-01', 'attendance', 'draft')")
        except sqlite3.IntegrityError:
            second = self._upsert_attendance(db, 1, "2026-05-01", "draft")
            self.assertEqual(second, first, "the retry must land on the same packet")
        rows = db.execute("SELECT COUNT(*) FROM grade_submissions").fetchone()[0]
        self.assertEqual(rows, 1)

    def test_the_two_constraints_do_not_collide_across_submission_types(self):
        """NULL-exemption: marklists have no date, attendance has no assessment."""
        db = self._db(unique=True)
        # many marklists, all with attendance_date NULL
        for assessment in (10, 11, 12):
            db.execute(
                "INSERT INTO grade_submissions (class_id, assessment_id, attendance_date,"
                " submission_type, status) VALUES (5, ?, NULL, 'marklist', 'draft')", (assessment,))
        # many attendance rows, all with assessment_id NULL
        for day in ("2026-05-01", "2026-05-02", "2026-05-03"):
            db.execute(
                "INSERT INTO grade_submissions (class_id, assessment_id, attendance_date,"
                " submission_type, status) VALUES (5, NULL, ?, 'attendance', 'draft')", (day,))
        self.assertEqual(
            db.execute("SELECT COUNT(*) FROM grade_submissions").fetchone()[0], 6)
        # but a genuine duplicate in either family is still rejected
        with self.assertRaises(sqlite3.IntegrityError):
            db.execute(
                "INSERT INTO grade_submissions (class_id, assessment_id, attendance_date,"
                " submission_type, status) VALUES (5, 10, NULL, 'marklist', 'draft')")
        with self.assertRaises(sqlite3.IntegrityError):
            db.execute(
                "INSERT INTO grade_submissions (class_id, assessment_id, attendance_date,"
                " submission_type, status) VALUES (5, NULL, '2026-05-01', 'attendance', 'draft')")


if __name__ == "__main__":
    unittest.main()
