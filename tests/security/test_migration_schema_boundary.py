"""The sql/ directory is not a self-contained schema — pin that boundary.

Audit 2026-10-02 cycle 2, finding M.

WHAT WAS FOUND
--------------
Running sql/001 .. sql/052 in order against an empty database does not build
this system. Two distinct problems:

  1. Seven core tables are ALTERed by migrations but never CREATEd anywhere
     in sql/ — including `users` and `members`, the two most fundamental
     tables in the product. Their real DDL lives outside the migration
     directory (legacy runtime DDL and the stale database_schema.sql).

  2. Four tables are ALTERed by an earlier migration than the one that
     creates them (e.g. 003 adds foreign keys to `teacher_assignments`,
     which is first created in 006). Migration 003 contains no
     information_schema guard, no "IF EXISTS", and no prepared statement —
     zero existence checks — so in strict numeric order it simply errors.

Neither is a mistake in the sense of a typo. sql/003's own header says "HOW
TO RUN (do this ONCE, from phpMyAdmin)": it is a one-time operational script
written against an already-populated production database, where those tables
already existed. The sql/ directory is a mix of hand-run operational scripts
and replayable migrations, and nothing distinguishes them. There is also no
`schema_migrations` table, so nothing records what has actually been applied.

WHY THIS IS NOT "FIXED" HERE
----------------------------
Reconstructing the missing DDL would mean inventing the production schema
from inference. Reordering or rewriting the applied history would risk a
live database. Both are deployment decisions that need the real production
schema in hand, so this is reported as REQUIRES DECISION, not patched.

WHAT THIS TEST DOES
-------------------
It pins the boundary exactly as measured, so the situation cannot quietly
get worse: a new migration that alters another unmanaged table, or that
alters a table before its CREATE, fails here and has to be justified.
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
SQL = ROOT / "sql"

# Measured 2026-10-02. Tables whose CREATE TABLE lives outside sql/.
# Shrinking this set is an improvement; growing it needs a deliberate decision.
KNOWN_EXTERNALLY_DEFINED = {
    "users",
    "members",
    "subjects",
    "finance_transactions",
    "finance_member_fees",
    "material_items",
    "material_transactions",
}

# Measured 2026-10-02. (migration, table) pairs altered before their CREATE.
KNOWN_FORWARD_REFERENCES = {
    ("003", "attendance"),
    ("003", "class_enrollments"),
    ("003", "academic_records"),
    ("003", "teacher_assignments"),
    ("004", "academic_years"),
    ("012", "wbws_groups"),
    ("012", "wbws_group_leaders"),
}


def migrations():
    return sorted(SQL.glob("[0-9][0-9][0-9]_*.sql"))


def strip_comments(text):
    return re.sub(r"--[^\n]*", "", text)


def created_tables():
    """table -> earliest migration number that creates it."""
    first = {}
    for path in migrations():
        num = path.name[:3]
        body = strip_comments(path.read_text(encoding="utf-8", errors="replace"))
        for m in re.finditer(r"CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?(\w+)`?", body, re.I):
            first.setdefault(m.group(1), num)
    return first


def altered_tables():
    """list of (migration number, table)."""
    out = []
    for path in migrations():
        num = path.name[:3]
        body = strip_comments(path.read_text(encoding="utf-8", errors="replace"))
        for m in re.finditer(r"ALTER TABLE\s+`?(\w+)`?", body, re.I):
            out.append((num, m.group(1)))
    return out


class MigrationSchemaBoundary(unittest.TestCase):
    def setUp(self):
        self.created = created_tables()
        self.altered = altered_tables()

    def test_the_directory_is_scanned_at_all(self):
        self.assertGreater(len(migrations()), 40)
        self.assertGreater(len(self.created), 40)

    def test_no_new_table_is_altered_without_being_created(self):
        unmanaged = {t for _, t in self.altered if t not in self.created}
        new = unmanaged - KNOWN_EXTERNALLY_DEFINED
        self.assertEqual(
            new, set(),
            "migration(s) ALTER a table that sql/ never creates. Either add its "
            "CREATE TABLE to sql/, or extend KNOWN_EXTERNALLY_DEFINED with a "
            f"documented reason. New: {sorted(new)}")

    def test_known_external_tables_are_still_actually_external(self):
        """If someone adds the missing DDL, tighten this list."""
        now_created = {t for t in KNOWN_EXTERNALLY_DEFINED if t in self.created}
        self.assertEqual(
            now_created, set(),
            "these tables are now created inside sql/ — remove them from "
            f"KNOWN_EXTERNALLY_DEFINED: {sorted(now_created)}")

    def test_no_new_forward_reference_is_introduced(self):
        forward = {
            (num, t) for num, t in self.altered
            if t in self.created and self.created[t] > num
        }
        new = forward - KNOWN_FORWARD_REFERENCES
        self.assertEqual(
            new, set(),
            "migration(s) ALTER a table before the migration that creates it, so "
            "a fresh in-order run fails. Move the CREATE earlier or guard the "
            f"ALTER. New: {sorted(new)}")

    def test_the_unsequenced_scripts_are_still_flagged_in_the_runbook(self):
        """The deployment doc must keep warning that sql/ is not replayable."""
        runbook = (ROOT / "docs/audits/DEPLOYMENT_RUNBOOK.md").read_text(encoding="utf-8")
        self.assertIn("not a fresh-install sequence", runbook.lower().replace("’", "'"),
                      "DEPLOYMENT_RUNBOOK must state that sql/ cannot build a new database")

    def test_there_is_still_no_applied_migration_ledger(self):
        """Pins finding Q10: nothing records which migrations ran."""
        has_ledger = any(
            re.search(r"CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?schema_migrations`?",
                      p.read_text(encoding="utf-8", errors="replace"), re.I)
            for p in migrations())
        self.assertFalse(
            has_ledger,
            "a schema_migrations ledger now exists — excellent; update this test "
            "and the audit, because migration state is no longer untracked")


if __name__ == "__main__":
    unittest.main()
