"""Attendance recency + migration ordering/uniqueness.

Regression tests for audit 2026-10-02, findings E, F, G and H.

E  AttendanceSummaryService::refreshMemberTotals() stamped
   `members.last_attendance_date = CURDATE()`. Because it runs from
   recordSaved() on EVERY save, backfilling last term's register — or
   correcting a six-month-old row — marked the member as having attended
   TODAY. The column must describe the attendance data, not the moment
   the row was written.

F  Two different migrations both shipped as number 030, so "apply in
   numeric order" was ambiguous and one could be silently skipped.

G  033 drops columns and then clears a table that 032 CREATES. Applied
   out of order it destroyed data and only then failed on the missing
   table, leaving a half-applied migration.

H  018 and 031 add a UNIQUE key only when the data is already clean and
   skip otherwise — correct, because deleting duplicate user records is a
   human decision. But 018 skipped SILENTLY and 031 used one message for
   both "blocked by duplicates" and "already present", so a database could
   go live missing a guarantee the application assumes. The safety
   behaviour is preserved; the invisibility is what these tests close.
"""

from pathlib import Path
import re
import sqlite3
import unittest

ROOT = Path(__file__).resolve().parents[2]
SQL = ROOT / "sql"


class AttendanceRecencyIsDerived(unittest.TestCase):
    """Finding E."""

    @classmethod
    def setUpClass(cls):
        cls.src = (ROOT / "admin/backend/services/AttendanceSummaryService.php").read_text(
            encoding="utf-8")

    def test_last_attendance_date_is_never_curdate(self):
        self.assertNotIn("last_attendance_date = CURDATE()", self.src)
        # CURDATE() must not reach that column by any spelling
        for match in re.finditer(r"last_attendance_date\s*=\s*([^,\n]+)", self.src):
            self.assertNotIn("CURDATE", match.group(1).upper())

    def test_last_attendance_date_comes_from_max_of_real_rows(self):
        self.assertIn("SELECT MAX(a.attendance_date)", self.src)
        self.assertIn("FROM attendance a", self.src)
        self.assertIn("WHERE a.member_id = ?", self.src)

    def test_existing_value_is_preserved_when_no_attendance_exists(self):
        """COALESCE must stop the fix from blanking a populated column."""
        self.assertIn("COALESCE(", self.src)
        block = self.src[self.src.index("last_attendance_date = COALESCE("):]
        self.assertIn("last_attendance_date", block[:400].split("),")[0] + ")")

    def test_total_attendance_rate_logic_is_unchanged(self):
        self.assertIn("SELECT AVG(attendance_rate) AS avg_rate", self.src)
        self.assertIn("FROM attendance_summary", self.src)
        self.assertIn("round((float)$average['avg_rate'], 2)", self.src)

    def test_bind_types_match_the_new_placeholder_count(self):
        """Adding the subquery added a placeholder; the bind must follow."""
        stmt_start = self.src.index("UPDATE members")
        stmt = self.src[stmt_start:self.src.index("bind_param", stmt_start) + 120]
        placeholders = stmt[:stmt.index("bind_param")].count("?")
        self.assertEqual(placeholders, 3, "expected rate + member_id + member_id")
        self.assertIn("$update->bind_param('dii', $avgRate, $memberId, $memberId);", self.src)

    def test_never_fails_the_save_path(self):
        """The whole refresh stays inside the tolerant try/catch."""
        body = self.src[self.src.index("public static function refreshMemberTotals"):]
        body = body[:body.index("\n    /**")]
        self.assertIn("} catch (\\Throwable $error) {", body)
        self.assertIn("Attendance member totals refresh skipped: ", body)

    def test_query_is_backed_by_a_member_prefixed_index(self):
        """MAX() on an equality-bound member must be an index probe."""
        idx = (SQL / "028_analytics_scale.sql").read_text(encoding="utf-8")
        self.assertIn("idx_att_member_date", idx)
        # created through the file's add-index-if-missing helper, whose third
        # argument is the column list; member_id must come FIRST for an
        # equality-bound MAX() to be a probe rather than a scan.
        self.assertRegex(
            idx,
            r"'idx_att_member_date',\s*'`member_id`,\s*`attendance_date`'")
        # the older unique index is member-prefixed too, so the probe works
        # even on a database that has not reached 028 yet.
        base = (SQL / "013_application_schema_completion.sql").read_text(encoding="utf-8")
        self.assertIn("`uq_att_member_class_date` (`member_id`,`class_id`,`attendance_date`)", base)


class AttendanceRecencySemantics(unittest.TestCase):
    """Runtime proof of the chosen SQL, replayed on SQLite.

    Proves the statement shape does what finding E requires: the stored
    date tracks the data, backfills do not move it forward, and an empty
    attendance set cannot blank an existing value.
    """

    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.db.execute("CREATE TABLE members (id INTEGER PRIMARY KEY,"
                        " total_attendance_rate REAL, last_attendance_date TEXT)")
        self.db.execute("CREATE TABLE attendance (member_id INTEGER, attendance_date TEXT)")
        self.db.execute("INSERT INTO members VALUES (1, NULL, NULL)")

    def _refresh(self, rate=90.0):
        self.db.execute(
            "UPDATE members SET total_attendance_rate = ?,"
            " last_attendance_date = COALESCE("
            "   (SELECT MAX(a.attendance_date) FROM attendance a WHERE a.member_id = ?),"
            "   last_attendance_date)"
            " WHERE id = ?", (rate, 1, 1))
        return self.db.execute(
            "SELECT last_attendance_date FROM members WHERE id = 1").fetchone()[0]

    def test_tracks_the_latest_real_attendance_row(self):
        self.db.executemany("INSERT INTO attendance VALUES (1, ?)",
                            [("2026-03-01",), ("2026-05-12",), ("2026-04-02",)])
        self.assertEqual(self._refresh(), "2026-05-12")

    def test_backfilling_an_old_register_does_not_move_the_date_forward(self):
        """The defect: this used to become today's date."""
        self.db.execute("INSERT INTO attendance VALUES (1, '2026-05-12')")
        self.assertEqual(self._refresh(), "2026-05-12")
        self.db.execute("INSERT INTO attendance VALUES (1, '2025-11-04')")  # backfill
        self.assertEqual(self._refresh(), "2026-05-12",
                         "a backfill must not make the member look recently present")

    def test_no_attendance_rows_never_blanks_an_existing_value(self):
        self.db.execute("UPDATE members SET last_attendance_date = '2026-01-09'")
        self.assertEqual(self._refresh(), "2026-01-09")

    def test_other_members_rows_are_not_counted(self):
        self.db.executemany("INSERT INTO attendance VALUES (?, ?)",
                            [(1, "2026-02-02"), (2, "2026-09-09")])
        self.assertEqual(self._refresh(), "2026-02-02")

    def tearDown(self):
        self.db.close()


class MigrationNumbersAreUnique(unittest.TestCase):
    """Finding F."""

    def test_no_two_migrations_share_a_number(self):
        seen = {}
        for path in sorted(SQL.glob("*.sql")):
            number = path.name.split("_", 1)[0]
            if not number.isdigit():
                continue
            seen.setdefault(number, []).append(path.name)
        clashes = {n: f for n, f in seen.items() if len(f) > 1}
        self.assertEqual(clashes, {}, f"duplicate migration numbers: {clashes}")

    def test_old_duplicate_filename_is_gone_everywhere(self):
        self.assertFalse((SQL / "030_roster_scale_indexes.sql").exists())
        self.assertTrue((SQL / "052_roster_scale_indexes.sql").exists())

    def test_no_code_still_consumes_the_old_path(self):
        """No executable reference to the renamed file may survive.

        Prose that EXPLAINS the rename is expected and desirable (the
        runbook, the note in 052, this test). What must not exist is code
        or a deploy script still pointing at the old filename. The frozen
        2026-09-07 audit inventory is excluded outright: it is historical
        evidence and rewriting it would falsify the record.
        """
        allowed = {
            "sql/052_roster_scale_indexes.sql",          # documents its own rename
            "docs/audits/DEPLOYMENT_RUNBOOK.md",          # tells the operator
            "tests/security/test_attendance_date_and_migrations.py",
        }
        code_suffixes = {".php", ".py", ".js", ".dart", ".sh", ".yml", ".yaml",
                         ".json", ".sql", ".txt"}
        stale = []
        for path in ROOT.rglob("*"):
            if not path.is_file() or ".git/" in str(path):
                continue
            if path.suffix.lower() not in code_suffixes:
                continue
            if "docs/audits/production-2026-09-07/" in str(path):
                continue  # immutable historical inventory
            rel = str(path.relative_to(ROOT))
            if rel in allowed:
                continue
            try:
                text = path.read_text(encoding="utf-8")
            except (UnicodeDecodeError, OSError):
                continue
            if "030_roster_scale_indexes" in text:
                stale.append(rel)
        self.assertEqual(stale, [], f"stale references to the old 030 name: {stale}")

    def test_renamed_migration_kept_its_semantics(self):
        text = (SQL / "052_roster_scale_indexes.sql").read_text(encoding="utf-8")
        self.assertIn("ALTER TABLE `class_enrollments`", text)
        self.assertIn("ADD INDEX `idx_enroll_year_status_member` "
                      "(`academic_year_id`, `status`, `member_id`)", text)
        self.assertIn("RENUMBERED", text)

    def test_mezmur_taxonomy_still_owns_030_and_is_still_referenced(self):
        self.assertTrue((SQL / "030_mezmur_taxonomy.sql").exists())
        api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")
        self.assertIn("sql/030_mezmur_taxonomy.sql", api)


class MezmurMigrationOrdering(unittest.TestCase):
    """Finding G."""

    @classmethod
    def setUpClass(cls):
        cls.m033 = (SQL / "033_mezmur_single_title.sql").read_text(encoding="utf-8")

    def test_033_declares_its_dependency_on_032(self):
        self.assertIn("ORDERING REQUIREMENT", self.m033)
        self.assertIn("032_mezmur_hymn_words.sql", self.m033)
        self.assertIn("031_mezmur_hymn_title_unique.sql", self.m033)

    def test_the_table_033_clears_is_created_by_032(self):
        m032 = (SQL / "032_mezmur_hymn_words.sql").read_text(encoding="utf-8")
        self.assertIn("CREATE TABLE IF NOT EXISTS mezmur_hymn_words", m032)
        self.assertIn("mezmur_hymn_words", self.m033)

    def test_033_fails_loudly_instead_of_half_applying(self):
        self.assertIn("information_schema.TABLES", self.m033)
        self.assertIn("BLOCKER", self.m033)
        self.assertNotIn("\nDELETE FROM mezmur_hymn_words;", self.m033)

    def test_ordering_is_documented_for_the_operator(self):
        runbook = (ROOT / "docs/audits/DEPLOYMENT_RUNBOOK.md").read_text(encoding="utf-8")
        self.assertIn("031 -> 032 -> 033", runbook)


class ConditionalUniquesAreVisible(unittest.TestCase):
    """Finding H — safety preserved, silence removed."""

    @classmethod
    def setUpClass(cls):
        cls.m018 = (SQL / "018_code_sequences_and_year_uniqueness.sql").read_text(encoding="utf-8")
        cls.m031 = (SQL / "031_mezmur_hymn_title_unique.sql").read_text(encoding="utf-8")
        cls.pre = (SQL / "preflight/uniqueness_preflight.sql").read_text(encoding="utf-8")

    def test_duplicate_data_is_still_never_destroyed(self):
        """The whole point: these files must not resolve duplicates."""
        for name, text in (("018", self.m018), ("031", self.m031), ("preflight", self.pre)):
            with self.subTest(file=name):
                self.assertNotRegex(text, r"(?i)\bDELETE\s+FROM\s+`?academic_years`?")
                self.assertNotRegex(text, r"(?i)\bDELETE\s+FROM\s+`?mezmur_hymns`?")
                self.assertNotRegex(text, r"(?i)\bDROP\s+TABLE\s+`?academic_years`?")

    def test_018_no_longer_skips_silently(self):
        self.assertIn("BLOCKER", self.m018)
        self.assertIn("duplicate year_name value(s) exist", self.m018)
        self.assertIn("m018_verification", self.m018)

    def test_031_distinguishes_blocked_from_already_present(self):
        self.assertNotIn("duplicates present or index already exists", self.m031)
        self.assertIn("already present", self.m031)
        self.assertIn("BLOCKER", self.m031)
        self.assertIn("mz31_verification", self.m031)

    def test_both_migrations_end_with_a_deterministic_verdict(self):
        for name, text, index in (("018", self.m018, "uq_academic_years_year_name"),
                                  ("031", self.m031, "uq_mezmur_hymns_title")):
            with self.subTest(file=name):
                tail = text[text.rindex("SELECT CASE"):]
                self.assertIn("information_schema", tail.lower())
                self.assertIn(index, tail)
                self.assertIn("PASS:", tail)
                self.assertIn("BLOCKER:", tail)

    def test_preflight_is_read_only(self):
        self.assertIn("TEMPORARY TABLE", self.pre)
        body = re.sub(r"--[^\n]*", "", self.pre)  # strip comments
        for forbidden in (r"\bUPDATE\s+`?members`?", r"\bINSERT\s+INTO\s+`?academic_years`?",
                          r"\bDELETE\s+FROM\s+`?mezmur_hymns`?", r"\bALTER\s+TABLE"):
            self.assertNotRegex(body, forbidden)

    def test_preflight_covers_every_conditional_unique(self):
        for constraint in ("uq_academic_years_year_name", "uq_mezmur_hymns_title",
                           "uq_ar_assessment_member", "uq_att_member_class_date"):
            with self.subTest(constraint=constraint):
                self.assertIn(constraint, self.pre)

    def test_preflight_blocks_the_deployment_deterministically(self):
        self.assertIn("SIGNAL SQLSTATE '45000'", self.pre)
        self.assertIn("result = 'BLOCK'", self.pre)
        # Four tiers, not three. PENDING was split out of BLOCK because a
        # database with clean data that simply has not had the owning
        # migration applied needs a mechanical step, not a human decision
        # about which duplicate row survives; reporting it as BLOCK sent
        # operators hunting for duplicates that did not exist. Both tiers
        # still exit non-zero -- the constraint is absent either way.
        self.assertIn("ENUM('PASS', 'PENDING', 'BLOCK', 'SKIP')", self.pre)
        self.assertIn("result = 'PENDING'", self.pre)
        self.assertIn("WHEN @ssms_pending > 0 THEN", self.pre)

    def test_preflight_reports_the_quarantined_rows_from_013(self):
        self.assertIn("migration_013_academic_record_conflicts", self.pre)
        self.assertIn("migration_013_attendance_conflicts", self.pre)

    def test_runbook_tells_the_operator_to_run_it(self):
        runbook = (ROOT / "docs/audits/DEPLOYMENT_RUNBOOK.md").read_text(encoding="utf-8")
        self.assertIn("sql/preflight/uniqueness_preflight.sql", runbook)
        self.assertIn("DEPLOYMENT BLOCKER", runbook)


if __name__ == "__main__":
    unittest.main()
