"""
Academic-year charset repair (1.6.3+29 follow-up, 2026-10-08) — pin the fix
═════════════════════════════════════════════════════════════════════════════
LIVE INCIDENT: creating an academic year with the default Amharic name
("2019 ዓ.ም.") failed with the generic "Unable to save the academic year."
on the freshly cut-over production database.

ROOT CAUSE (proven from the production phpMyAdmin dump):
  • academic_years was CHARSET=latin1 (one of only 3 latin1 tables in the
    whole DB — restored verbatim from the old server where a pre-charset
    era setup created it with the server default).
  • The table was EMPTY (no year ever saved) and every column was present,
    so all column-hypotheses were disproven.
  • The admin connection is utf8mb4 (config.php set_charset); inserting
    Amharic into a latin1 column is errno 1366 "Incorrect string value"
    under strict mode (MariaDB 11.4 default).
  • PHP 8.1+ mysqli default report mode raises that as an exception, so
    save_academic_year's outer catch returned the generic message and the
    friendly errno-1062 branch below execute() was unreachable.
  • Prior art: tools/find_stored_mojibake.php already lists
    academic_years.year_name as a historically double-encoded column.

FIX (pinned here):
  • sql/066_academic_year_charset_repair.sql — idempotent CONVERT TO
    utf8mb4 for academic_years (+ sync_feed_state hygiene), explicitly
    NOT touching mezmur_hymn_words (binary hex word storage by design).
  • save_academic_year / save_term catch blocks now map mysqli exceptions:
    1062 → friendly duplicate message, 1366 → charset-repair instructions,
    anything else → generic message WITH the reportInternalError log
    reference and SQL code, so no future failure can be anonymous.
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class CharsetMigrationPinned(unittest.TestCase):
    def setUp(self):
        self.sql = read("sql/066_academic_year_charset_repair.sql")

    def test_converts_academic_years_to_utf8mb4(self):
        self.assertIn(
            "ALTER TABLE `academic_years`\n          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            self.sql,
        )

    def test_is_idempotent_information_schema_guarded(self):
        self.assertIn("DROP PROCEDURE IF EXISTS `wbss_repair_latin1_tables`", self.sql)
        self.assertIn("CALL `wbss_repair_latin1_tables`", self.sql)
        self.assertIn("information_schema.TABLES", self.sql)
        # re-run safe: the utf8mb4 branch must be a no-op
        self.assertIn("already utf8mb4 — nothing to do", self.sql)

    def test_sync_feed_state_hygiene_only(self):
        self.assertIn("ALTER TABLE `sync_feed_state`", self.sql)
        self.assertIn("hygiene", self.sql)

    def test_never_touches_mezmur_hymn_words(self):
        # binary hex word storage — conversion would risk re-encoding
        self.assertNotIn("ALTER TABLE `mezmur_hymn_words`", self.sql)
        self.assertIn("mezmur_hymn_words is latin1 on purpose", self.sql)


class SavePathHardeningPinned(unittest.TestCase):
    def setUp(self):
        self.api = read("admin/api_education.php")

    def test_year_save_maps_duplicate_key_to_friendly_message(self):
        seg = self.api.split("case 'save_academic_year'")[1].split("case 'set_current_year'")[0]
        self.assertIn("$code === 1062", seg)
        self.assertIn("already exists — please choose a different year name", seg)

    def test_year_save_maps_charset_error_to_repair_instructions(self):
        seg = self.api.split("case 'save_academic_year'")[1].split("case 'set_current_year'")[0]
        self.assertIn("$code === 1366", seg)
        self.assertIn("066_academic_year_charset_repair.sql", seg)

    def test_year_save_attaches_log_reference_and_sql_code(self):
        seg = self.api.split("case 'save_academic_year'")[1].split("case 'set_current_year'")[0]
        self.assertIn("$ref = reportInternalError('Academic year save failed', $e)", seg)
        self.assertIn("ref SSMS:'.$ref", seg)
        self.assertIn("SQL '.$code", seg)

    def test_term_save_has_the_same_hardening(self):
        seg = self.api.split("case 'save_term'")[1].split("case 'set_current_term'")[0]
        self.assertIn("$code === 1366", seg)
        self.assertIn("066_academic_year_charset_repair.sql", seg)
        self.assertIn("ref SSMS:'.$ref", seg)

    def test_exception_mode_is_the_documented_reason(self):
        seg = self.api.split("case 'save_academic_year'")[1].split("case 'set_current_year'")[0]
        self.assertIn("MYSQLI_REPORT", seg)
        self.assertIn("mysqli_sql_exception", seg)


if __name__ == "__main__":
    unittest.main()
