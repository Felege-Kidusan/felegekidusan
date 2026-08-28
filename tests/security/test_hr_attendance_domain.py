"""HR attendance domain — schema + services (Phase B part 1, 2026-08-28).

Product rule: HR takes section-based attendance with its OWN takers on
its OWN tables — never combined with Education or Mezmur. Mechanics
clone the mezmur section-sheet workflow (which clones the edu packet
workflow). The Information department will read it through the
governed analytics path only (Phase C).

Locked contracts:
  • sql/026 creates hr_attendance / hr_submissions / hr_attendance_audit
    idempotently with UNIQUE(date,member) + UNIQUE(date,section)
  • HrAttendanceService never references mezmur/edu tables
  • HrSubmissionService carries the full packet state machine
    (draft/incomplete/submitted/approved/rejected/revision_needed),
    note-required returns/rejects, admin-only lock overrides
  • reviewers are hr_dept + admins; taker attribution enforced by
    DeptTakerService + routes, not by the service internals
"""

from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


class HrDomainContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sql = (ROOT / "sql/026_hr_attendance.sql").read_text(encoding="utf-8")
        cls.att = (ROOT / "admin/backend/services/HrAttendanceService.php").read_text(encoding="utf-8")
        cls.sub = (ROOT / "admin/backend/services/HrSubmissionService.php").read_text(encoding="utf-8")

    # ── schema ─────────────────────────────────────────────────
    def test_schema_objects_and_uniques(self):
        for table in ("hr_attendance", "hr_submissions", "hr_attendance_audit"):
            self.assertIn("CREATE TABLE IF NOT EXISTS `%s`" % table, self.sql)
        self.assertIn("uq_hr_attendance_date_member", self.sql)
        self.assertIn("uq_hr_submissions_date_section", self.sql)
        self.assertIn("ENUM('present','late','absent','excused')", self.sql)
        self.assertIn("idx_hr_attendance_section_date", self.sql)

    # ── isolation ──────────────────────────────────────────────
    def test_hr_services_never_touch_other_departments(self):
        for src in (self.att, self.sub):
            self.assertNotIn("mezmur_", src)
            self.assertNotIn("grade_submissions", src)
            self.assertNotIn("FROM attendance ", src)
            self.assertNotIn("INSERT INTO attendance", src)
        # section roster comes from the shared member directory only
        self.assertIn("FROM members", self.att)

    # ── sheet mechanics ────────────────────────────────────────
    def test_sheet_write_is_transactional_and_validated(self):
        self.assertIn("begin_transaction", self.att)
        self.assertIn("rollback", self.att)
        self.assertIn("Attendance cannot be recorded for a future date", self.att)
        self.assertIn("out of date with the current roster", self.att)
        self.assertIn("Duplicate member in sheet", self.att)
        # section snapshot on the row → scoped deletes, never global
        self.assertIn("DELETE FROM hr_attendance WHERE attendance_date = ? AND section = ?", self.att)

    # ── packet state machine parity ────────────────────────────
    def test_packet_state_machine(self):
        for token in (
            "STATUS_INCOMPLETE", "STATUS_SUBMITTED", "STATUS_APPROVED",
            "STATUS_REJECTED", "STATUS_REVISION", "STATUS_DRAFT",
            "takerMayWrite", "isLockedForTaker", "reviewPacket",
            "listPackets", "packetStats", "client_op_id",
        ):
            self.assertIn(token, self.sub)
        # HR review attribution
        self.assertIn("['hr_dept', 'school_admin', 'super_admin']", self.sub)
        self.assertIn("['school_admin', 'super_admin']", self.sub)  # override is admin-only
        # non-approved decisions need a note for the taker
        self.assertIn("Write a short reason so the taker knows what to fix", self.sub)
        # legacy rows-without-packet read as submitted (mezmur parity)
        self.assertIn("packetHasRows", self.sub)
        # audit wiring on the HR audit table
        self.assertIn("hr_attendance_audit", self.sub)
        self.assertIn("'HR Submission Reviewed'", self.sub)

    # ── prepared statements only ───────────────────────────────
    def test_no_interpolated_queries(self):
        import re
        for src in (self.att, self.sub):
            for m in re.finditer(r'\$conn->query\(\s*"([^"]*)"', src):
                # static aggregation reads only — no user input in them
                self.assertNotIn("?", m.group(1))
                self.assertNotIn("$_", m.group(1))
            self.assertIn("$conn->prepare", src)


if __name__ == "__main__":
    unittest.main()
