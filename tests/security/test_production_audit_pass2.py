"""
Production-readiness audit 2026-10-20 — PASS 2 guards (findings A1–A14).

Static pins (always run):
  * A1  api_attendance.php: teachers are scoped to their assigned classes;
  * A3  api_attendance.php: no bare die()/echo-on-exception — Throwable → JSON 500;
  * A4  api_education.php TIER 2b: legacy direct-write adapters are Education-only
        and refuse lesson days (LESSON_DAY 409);
  * A5  api_education.php TIER 2c: school-wide enrolment reads are Education-only;
  * A6  future attendance dates are refused on every write path (web, mobile, legacy);
  * A7  requirePostActions() covers the write actions that were missing;
  * A8  assessment-type gates read the role from $_SESSION (were permanently 403);
  * A9  SubmissionService::upsertScore refuses NEW marks for members outside the class;
  * A10 submit_marklist runs on the canonical path (assignment check,
        teacherWriteRefusal lock, upsertMarklist packet — no blind INSERT);
  * A11 api_settings.php dept_save/system_info/clear_cache are role-gated;
  * A12 PUT /api/v1/members/{id} keeps the stored name parts in full_name_am;
  * A13 api_impersonate.php writes an audit row for switch and restore;
  * A14 api_finance.php add/update/delete transaction write activity_logs rows.
Live (skipped unless the local server + seed are present):
  * teacher 403 outside own class / taker allowed; future date 422 (web + legacy);
  * TIER 2c 403 for teacher + taker, 200 for Education;
  * Education assessment-type save → toggle → delete round-trip (A8);
  * save_grades: enrolled member saved, stranger rejected with message (A9);
  * submit_marklist: submit → packet, re-submit 409, unassigned class 403 (A10);
  * api_settings dept_save 403 for teacher, 200 for Information (A11);
  * mobile partial name update keeps full_name_am intact (A12);
  * role switch/restore produce activity_logs rows (A13).
"""
from __future__ import annotations

import json
import re
import unittest
import urllib.parse
import urllib.request
from datetime import date, timedelta
from pathlib import Path

from test_attendance_phase1 import BASE, USERS, WebSession, db, _live_ready, mobile_token  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
USERS.setdefault("attendance_taker", "audit_taker")


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def block(src: str, start: str, end_marker: str = "\n    case '") -> str:
    i = src.index(start)
    j = src.find(end_marker, i + len(start))
    return src[i: j if j > 0 else len(src)]


class Pass2StaticGuards(unittest.TestCase):
    ATT = "admin/api_attendance.php"
    EDU = "admin/api_education.php"
    SUBJ = "admin/api_subjects.php"
    COMM = "admin/api_communication.php"
    SVC = "admin/backend/services/SubmissionService.php"
    MOB_ATT = "api/v1/routes/attendance.php"
    MOB_MEM = "api/v1/routes/members.php"
    SETTINGS = "admin/api_settings.php"
    IMP = "admin/api_impersonate.php"

    # A1 / A3 / A6 -------------------------------------------------------
    def test_a1_teacher_class_scope_closure_runs_before_dispatch(self):
        src = read(self.ATT)
        self.assertIn("$requireClassScope = static function (int $classId)", src)
        self.assertIn("FROM teacher_assignments WHERE teacher_id = ? AND class_id = ?", src)
        self.assertIn("You are not assigned to this class.", src)
        self.assertLess(src.index("$requireClassScope($scopedClassId);"), src.index("switch ($action)"))

    def test_a3_no_die_and_throwable_becomes_json_500(self):
        src = read(self.ATT)
        self.assertNotRegex(src, r"\bdie\s*\(")
        self.assertIn("} catch (Throwable $e) {", src)
        self.assertIn("http_response_code(500);", src)

    def test_a6_future_dates_refused_everywhere(self):
        att = read(self.ATT)
        self.assertIn("$refuseFutureDate = static function (string $date)", att)
        self.assertGreaterEqual(att.count("$refuseFutureDate($date);"), 2, "save_attendance + scan_member")
        mob = read(self.MOB_ATT)
        self.assertEqual(mob.count("'code' => 'FUTURE_DATE'"), 2, "POST /attendance + /attendance/submit")
        edu = read(self.EDU)
        self.assertGreaterEqual(edu.count("Attendance cannot be recorded for a future date."), 2)

    # A4 / A5 / A7 -------------------------------------------------------
    def test_a4_tier_2b_legacy_writes_are_education_only_and_lesson_day_safe(self):
        src = read(self.EDU)
        self.assertIn("$__legacyWriteActions = ['record_attendance', 'batch_attendance', 'record_grade'];", src)
        gate = src[src.index("$__legacyWriteActions = ["):]
        gate = gate[: gate.index("exit;")]
        self.assertIn("['super_admin', 'school_admin', 'edu_dept']", gate)
        self.assertIn("http_response_code(403);", gate)
        for case in ("case 'record_attendance':", "case 'batch_attendance':"):
            body = block(src, case)
            self.assertIn("LessonAttendanceService::dayHasLessons", body, case)
            self.assertIn("'code' => 'LESSON_DAY'", body, case)
            self.assertIn("http_response_code(409);", body, case)

    def test_a5_tier_2c_school_wide_reads_are_education_only(self):
        src = read(self.EDU)
        m = re.search(r"\$__manageReadActions = \[(.*?)\];", src, re.S)
        self.assertIsNotNone(m)
        names = set(re.findall(r"'([a-z_]+)'", m.group(1)))
        self.assertEqual(
            names,
            {"get_unassigned_members", "get_unassigned_teachers", "enrollment_overview",
             "roster", "search_members", "list_teachers"},
        )
        gate = src[m.end(): src.index("exit;", m.end())]
        self.assertIn("['super_admin', 'school_admin', 'edu_dept']", gate)
        self.assertIn("http_response_code(403);", gate)
        # teacher / taker dashboards only need the per-class roster
        self.assertNotIn("get_enrolled_students", names)

    def test_a7_require_post_actions_cover_the_write_actions(self):
        for rel, needed in (
            (self.EDU, ("toggle_class", "save_assessment_type", "toggle_assessment_type", "delete_assessment_type")),
            (self.SUBJ, ("save_class_subject_duration", "save_assessment_type", "toggle_assessment_type", "delete_assessment_type")),
        ):
            src = read(rel)
            m = re.search(r"requirePostActions\(\s*\$action\s*,\s*\[(.*?)\]", src, re.S)
            self.assertIsNotNone(m, rel)
            for name in needed:
                self.assertIn(f"'{name}'", m.group(1), f"{rel}: {name}")

    # A8 -----------------------------------------------------------------
    def test_a8_assessment_type_gates_read_the_session_role(self):
        for rel in (self.SUBJ, self.EDU):
            src = read(rel)
            for case in ("case 'save_assessment_type':", "case 'toggle_assessment_type':", "case 'delete_assessment_type':"):
                body = block(src, case)
                self.assertIn("$_SESSION['admin_role']", body, f"{rel} {case}")
                self.assertNotRegex(body, r"\$userRole\b", f"{rel} {case}: undefined $userRole")

    # A9 -----------------------------------------------------------------
    def test_a9_upsert_score_refuses_new_marks_for_strangers(self):
        src = read(self.SVC)
        self.assertIn("public static function memberBelongsToClass(\\mysqli $conn, int $memberId, int $classId): bool", src)
        self.assertIn("FROM class_enrollments", src[src.index("function memberBelongsToClass"):][:900])
        up = src[src.index("public static function upsertScore"):]
        up = up[: up.index("\n    }\n")]
        self.assertIn("!self::memberBelongsToClass($conn, $memberId, $classId)", up)
        self.assertIn("return 0;", up)
        self.assertIn("is not enrolled in this class", read(self.SUBJ))

    # A10 ----------------------------------------------------------------
    def test_a10_submit_marklist_is_on_the_canonical_path(self):
        src = read(self.COMM)
        body = block(src, "case 'submit_marklist':")
        self.assertIn("FROM teacher_assignments", body)
        self.assertIn("SubmissionService::teacherWriteRefusal(", body)
        self.assertIn("SubmissionService::upsertMarklist(", body)
        self.assertIn("SubmissionService::upsertScore(", body)
        self.assertIn("SubmissionService::STATUS_SUBMITTED", body)
        self.assertNotIn("INSERT INTO grade_submissions", body)
        self.assertNotIn("$_POST['class_id']", body)
        self.assertNotIn("$_POST['subject_id']", body)
        self.assertIn("http_response_code(403);", body)
        self.assertIn("http_response_code(409);", body)
        self.assertIn("activity_logs", body)

    # A11 ----------------------------------------------------------------
    def test_a11_department_settings_are_role_gated(self):
        src = read(self.SETTINGS)
        self.assertIn("$settingsPrivilegedActions = ['dept_save', 'system_info', 'clear_cache'];", src)
        gate = src[src.index("$settingsPrivilegedActions = ["):]
        gate = gate[: gate.index("exit;")]
        self.assertIn("['super_admin', 'school_admin', 'info_dept', 'hr_dept']", gate)
        self.assertIn("http_response_code(403);", gate)
        self.assertLess(src.index("$settingsPrivilegedActions = ["), src.index("switch ($action)"))

    # A12 ----------------------------------------------------------------
    def test_a12_partial_name_update_falls_back_to_stored_parts(self):
        src = read(self.MOB_MEM)
        self.assertIn("student_name, father_name, grandfather_name FROM members WHERE id = ?", src)
        for part in ("student_name", "father_name", "grandfather_name"):
            self.assertIn(f"$input['{part}'] ?? ($__memberBefore['{part}'] ?? '')", src)

    # A13 ----------------------------------------------------------------
    def test_a13_role_switch_and_restore_are_audited(self):
        src = read(self.IMP)
        self.assertIn("INSERT INTO activity_logs", src)
        self.assertIn("$impersonationAudit('Role Switched'", src)
        self.assertIn("$impersonationAudit('Role Restored'", src)
        self.assertLess(src.index("$impersonationAudit('Role Switched'"), src.index("case 'restore':"))

    # A14 ----------------------------------------------------------------
    def test_a14_finance_ledger_mutations_are_audited(self):
        src = read("admin/api_finance.php")
        self.assertIn("function financeAudit(string $action, string $details, ?int $entityId = null): void", src)
        self.assertIn("INSERT INTO activity_logs", src)
        for case, label in (
            ("case 'add_transaction':", "'Finance Transaction Added'"),
            ("case 'update_transaction':", "'Finance Transaction Updated'"),
            ("case 'delete_transaction':", "'Finance Transaction Deleted'"),
        ):
            body = block(src, case, "\ncase '")
            self.assertIn("financeAudit(" + label, body, case)
        # the deleted row is captured BEFORE the DELETE statement
        body = block(src, "case 'delete_transaction':", "\ncase '")
        self.assertLess(body.index("SELECT type, amount, description"), body.index("DELETE FROM finance_transactions"))


# ──────────────────────────────────────────────────────────────────────
# Live
# ──────────────────────────────────────────────────────────────────────
def _seed_ready() -> bool:
    try:
        return (
            db("SELECT COUNT(*) FROM teacher_assignments WHERE teacher_id=3 AND class_id=1 AND subject_id=1") == "1"
            and db("SELECT COUNT(*) FROM class_enrollments WHERE class_id=1 AND member_id=900000 AND status='active'") >= "1"
            and db("SELECT COUNT(*) FROM users WHERE username='audit_taker'") == "1"
        )
    except Exception:
        return False


@unittest.skipUnless(_live_ready() and _seed_ready(), "local test server / seed not available")
class Pass2LiveGuards(unittest.TestCase):
    NAME = "AUDIT-P2 marklist"
    TYPE_NAME = "AUDIT-P2 type"
    _sessions: dict = {}

    @classmethod
    def setUpClass(cls):
        cls._clean()
        db(
            "INSERT INTO assessments (class_id, subject_id, academic_year_id, term_id, assessment_name, assessment_type,"
            " weight_percentage, max_score, due_date, is_published, created_by)"
            f" VALUES (1, 1, 1, 1, '{cls.NAME}', 'test', 1, 100, CURDATE(), 1, 2),"
            f" (2, 1, 1, 1, '{cls.NAME} unassigned', 'test', 1, 100, CURDATE(), 1, 2)"
        )
        cls.aid = int(db(f"SELECT id FROM assessments WHERE assessment_name='{cls.NAME}'"))
        cls.aid_other = int(db(f"SELECT id FROM assessments WHERE assessment_name='{cls.NAME} unassigned'"))

    @classmethod
    def tearDownClass(cls):
        cls._clean()

    @classmethod
    def _clean(cls):
        ids = db(f"SELECT GROUP_CONCAT(id) FROM assessments WHERE assessment_name LIKE '{cls.NAME}%'")
        if ids and ids != "NULL":
            db(f"DELETE FROM academic_records WHERE assessment_id IN ({ids})")
            db(f"DELETE FROM grade_submissions WHERE assessment_id IN ({ids})")
            db(f"DELETE FROM assessments WHERE id IN ({ids})")
        if cls._has_assessment_types():
            db(f"DELETE FROM assessment_types WHERE type_name='{cls.TYPE_NAME}' OR type_key='audit_p2_type'")
        db("DELETE FROM activity_logs WHERE action IN ('Role Switched','Role Restored') AND username='audit_school'")

    @staticmethod
    def _has_assessment_types() -> bool:
        return db("SHOW TABLES LIKE 'assessment_types'") != ""

    def s(self, role) -> WebSession:
        if role not in self._sessions:
            self._sessions[role] = WebSession(role)
        return self._sessions[role]

    def form(self, role, path, payload):
        s = self.s(role)
        data = dict(payload)
        data.setdefault("csrf_token", s.csrf)
        status, body = s._open(BASE + path, data=urllib.parse.urlencode(data).encode())
        try:
            return status, json.loads(body.decode("utf-8"))
        except Exception:
            return status, {"raw": body[:300].decode("utf-8", "replace")}

    # A1 / A6 ------------------------------------------------------------
    def test_teacher_scope_and_future_date_on_web_attendance(self):
        status, _ = self.s("teacher").get_json("/admin/api_attendance.php?action=get_class_attendance&class_id=3")
        self.assertEqual(status, 403)
        status, _ = self.s("teacher").get_json("/admin/api_attendance.php?action=get_class_attendance&class_id=1")
        self.assertEqual(status, 200)
        status, _ = self.s("attendance_taker").get_json("/admin/api_attendance.php?action=get_class_attendance&class_id=3")
        self.assertEqual(status, 200, "takers cover every class by design")
        tomorrow = (date.today() + timedelta(days=1)).isoformat()
        status, out = self.form("teacher", "/admin/api_attendance.php", {
            "action": "save_attendance", "class_id": 1, "date": tomorrow,
            "records": json.dumps([{"member_id": 900000, "status": "present"}]),
        })
        self.assertEqual(status, 422, out)

    # A4 / A5 ------------------------------------------------------------
    def test_tier_2b_and_2c_gates(self):
        status, _ = self.form("teacher", "/admin/api_education.php", {
            "action": "record_attendance", "class_id": 1, "member_id": 900000, "status": "present", "date": date.today().isoformat(),
        })
        self.assertEqual(status, 403)
        for role, expected in (("teacher", 403), ("attendance_taker", 403), ("edu_dept", 200)):
            for action in ("roster", "search_members&q=a", "list_teachers", "get_unassigned_members", "enrollment_overview"):
                status, _ = self.s(role).get_json("/admin/api_education.php?action=" + action)
                self.assertEqual(status, expected, f"{role} {action}")
            status, _ = self.s(role).get_json("/admin/api_education.php?action=get_enrolled_students&class_id=1")
            self.assertEqual(status, 200, f"{role} get_enrolled_students")

    # A8 -----------------------------------------------------------------
    def test_education_assessment_type_round_trip(self):
        if not self._has_assessment_types():
            self.skipTest("assessment_types table not present")
        status, out = self.form("edu_dept", "/admin/api_subjects.php", {
            "action": "save_assessment_type", "type_name": self.TYPE_NAME, "type_name_en": self.TYPE_NAME,
            "type_key": "audit_p2_type", "default_weight": 5, "default_max_score": 100,
        })
        self.assertEqual(status, 200, out)
        self.assertEqual(out.get("status"), "success", out)
        tid = db(f"SELECT id FROM assessment_types WHERE type_key='audit_p2_type' ORDER BY id DESC LIMIT 1")
        self.assertTrue(tid, "type row was not created")
        status, out = self.form("edu_dept", "/admin/api_subjects.php", {"action": "toggle_assessment_type", "id": tid})
        self.assertEqual(status, 200, out)
        status, out = self.form("edu_dept", "/admin/api_subjects.php", {"action": "delete_assessment_type", "id": tid})
        self.assertEqual(status, 200, out)
        status, out = self.form("teacher", "/admin/api_subjects.php", {"action": "save_assessment_type", "type_name": "x", "type_key": "x"})
        self.assertEqual(status, 403, out)

    # A9 -----------------------------------------------------------------
    def test_save_grades_rejects_members_outside_the_class(self):
        status, out = self.form("teacher", "/admin/api_subjects.php", {
            "action": "save_grades", "assessment_id": self.aid,
            "grades": json.dumps([
                {"member_id": 900000, "score": 71},
                {"member_id": 900010, "score": 99},
            ]),
        })
        self.assertEqual(status, 200, out)
        self.assertEqual(db(f"SELECT COUNT(*) FROM academic_records WHERE assessment_id={self.aid} AND member_id=900000"), "1")
        self.assertEqual(db(f"SELECT COUNT(*) FROM academic_records WHERE assessment_id={self.aid} AND member_id=900010"), "0")
        self.assertIn("not enrolled in this class", json.dumps(out))

    # A10 ----------------------------------------------------------------
    def test_submit_marklist_cycle(self):
        payload = {
            "action": "submit_marklist", "assessment_id": self.aid,
            "grades": json.dumps([{"member_id": 900001, "score": 64}, {"member_id": 900010, "score": 10}]),
        }
        status, out = self.form("teacher", "/admin/api_communication.php", payload)
        self.assertEqual(status, 200, out)
        self.assertEqual(out.get("status"), "success", out)
        self.assertEqual(
            db(f"SELECT COUNT(*) FROM grade_submissions WHERE assessment_id={self.aid} AND submission_type='marklist' AND status='submitted'"),
            "1",
        )
        self.assertEqual(db(f"SELECT COUNT(*) FROM academic_records WHERE assessment_id={self.aid} AND member_id=900010"), "0")
        status, out = self.form("teacher", "/admin/api_communication.php", payload)
        self.assertEqual(status, 409, out)
        self.assertEqual(
            db(f"SELECT COUNT(*) FROM grade_submissions WHERE assessment_id={self.aid} AND submission_type='marklist'"),
            "1", "no duplicate packets",
        )
        status, out = self.form("teacher", "/admin/api_communication.php", {
            "action": "submit_marklist", "assessment_id": self.aid_other,
            "grades": json.dumps([{"member_id": 900003, "score": 50}]),
        })
        self.assertEqual(status, 403, out)

    # A11 ----------------------------------------------------------------
    def test_department_settings_gate(self):
        before = db("SELECT setting_value FROM dept_settings WHERE setting_key='dept_name_en'") or "Information Department"
        for role, expected in (("teacher", 403), ("info_dept", 200)):
            s = self.s(role)
            status, body = s._open(
                BASE + "/admin/api_settings.php?action=dept_save",
                data=json.dumps({"dept_name_en": before}).encode(),
                headers={"Content-Type": "application/json", "X-CSRF-TOKEN": s.csrf},
            )
            self.assertEqual(status, expected, (role, body[:200]))
            status, _ = s.get_json("/admin/api_settings.php?action=system_info")
            self.assertEqual(status, expected, role)
            status, _ = s.get_json("/admin/api_settings.php?action=profile_get")
            self.assertEqual(status, 200, role)

    # A12 ----------------------------------------------------------------
    def test_mobile_partial_name_update_keeps_full_name(self):
        try:
            tok = mobile_token("info_dept")
        except urllib.error.HTTPError as e:
            if e.code == 429:
                self.skipTest("mobile login rate-limited (10/min per IP) — rerun standalone")
            raise
        row = db("SELECT student_name, father_name, IFNULL(grandfather_name,'') FROM members WHERE id=900010").split("\t")
        student, father = row[0], row[1]

        def put(body):
            req = urllib.request.Request(
                BASE + "/api/v1/members/900010", data=json.dumps(body).encode(), method="PUT",
                headers={"Content-Type": "application/json", "Authorization": "Bearer " + tok},
            )
            try:
                with urllib.request.urlopen(req, timeout=30) as r:
                    return r.status
            except urllib.error.HTTPError as e:
                return e.code

        try:
            self.assertEqual(put({"father_name": father + "X"}), 200)
            full = db("SELECT full_name_am FROM members WHERE id=900010")
            self.assertTrue(full.startswith(student + " " + father + "X"), full)
        finally:
            self.assertEqual(put({"father_name": father}), 200)
        self.assertTrue(db("SELECT full_name_am FROM members WHERE id=900010").startswith(student + " " + father))

    # A13 ----------------------------------------------------------------
    def test_role_switch_is_audited(self):
        status, out = self.form("school_admin", "/admin/api_impersonate.php", {"action": "switch", "role": "hr_dept"})
        self.assertEqual(status, 200, out)
        status, out = self.form("school_admin", "/admin/api_impersonate.php", {"action": "restore"})
        self.assertEqual(status, 200, out)
        rows = db("SELECT action, details FROM activity_logs WHERE action IN ('Role Switched','Role Restored') ORDER BY id DESC LIMIT 2")
        self.assertIn("Role Switched\tschool_admin -> hr_dept", rows)
        self.assertIn("Role Restored\thr_dept -> school_admin", rows)


if __name__ == "__main__":
    unittest.main()
