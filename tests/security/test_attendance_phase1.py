"""Attendance alerting — Phase 1: department status + policy (2026-10-19).

Static guards (always run):
  - sql/071 is idempotent and shared-hosting safe,
  - access_control / FeatureGate know the two new APIs,
  - write roles: department status = HR / Information; policy = School Admin,
  - Education roster (EnrollmentService::fetchRoster) and Mezmur rosters apply
    the department exclusion; report cards keep inactive students,
  - mobile route exists; PHP syntax; unit fixture (period math, flag-off no-op).

Live guards (skipped unless the local test server is up, FEATURE_ATTENDANCE_V2
is on and sql/071 is applied):
  - role matrix for set / save, validation 400s,
  - deactivated member disappears from the Education roster for today but not
    for yesterday and not from the Mezmur sheet (and vice-versa),
  - reactivation brings them back; audit rows written.
"""

from pathlib import Path
import http.cookiejar
import json
import os
import re
import shutil
import subprocess
import unittest
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, timedelta

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get("SSMS_TEST_BASE", "http://127.0.0.1:8080").rstrip("/")
PASSWORD = "AuditTest#2026"
USERS = {
    "super_admin": "audit_super",
    "school_admin": "audit_school",
    "hr_dept": "audit_hr",
    "info_dept": "audit_info",
    "edu_dept": "audit_edu",
    "teacher": "audit_teach",
    "mezmur_dept": "audit_mez",
}


def read(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


class Phase1StaticGuards(unittest.TestCase):
    def test_sql_071_is_idempotent_and_shared_hosting_safe(self):
        sql = read("sql/071_department_status_and_policy.sql")
        self.assertEqual(sql.count("CREATE TABLE IF NOT EXISTS"), 2)
        self.assertIn("`member_department_periods`", sql)
        self.assertIn("`attendance_policies`", sql)
        self.assertIn("INSERT IGNORE INTO `attendance_policies`", sql)
        upper = "\n".join(l for l in sql.splitlines() if not l.strip().startswith("--")).upper()
        for forbidden in [" JSON", "CHECK (", "GENERATED ALWAYS", "CREATE EVENT", "CREATE TRIGGER"]:
            self.assertNotIn(forbidden, upper, forbidden)
        self.assertIn("ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", sql)

    def test_access_control_and_feature_gate_know_new_apis(self):
        ac = read("admin/access_control.php")
        self.assertIn("'api_department_status.php' =>", ac)
        self.assertIn("'api_attendance_policy.php' =>", ac)
        fg = read("admin/backend/services/FeatureGate.php")
        self.assertIn("'api_department_status.php' => 'attendance'", fg)
        self.assertIn("'api_attendance_policy.php' => 'attendance'", fg)

    def test_write_roles(self):
        ds = read("admin/api_department_status.php")
        self.assertIn("const DS_WRITE_ROLES = ['hr_dept', 'info_dept'];", ds)
        self.assertIn("validateCsrf(", ds)
        ap = read("admin/api_attendance_policy.php")
        self.assertIn("const AP_WRITE_ROLES = ['school_admin'];", ap)
        self.assertIn("validateCsrf(", ap)
        mobile = read("api/v1/routes/members.php")
        self.assertIn("$sub === 'department-status'", mobile)
        self.assertIn("$dsWriteRoles = ['hr_dept', 'info_dept'];", mobile)

    def test_rosters_apply_department_exclusion(self):
        es = read("admin/backend/services/EnrollmentService.php")
        self.assertIn("DepartmentStatusService::exclusionSql($conn, 'edu', 'm.id'", es)
        self.assertEqual(es.count("$sql .= $deptExclusion;"), 2, "main and slim roster query")
        mz = read("admin/backend/services/MezmurAttendanceService.php")
        self.assertGreaterEqual(mz.count("DepartmentStatusService::exclusionSql($conn, 'mezmur', 'members.id'"), 4)
        self.assertIn("self::rosterGroupedBySection($conn, $date)", mz)
        self.assertIn("self::rosterIds($conn, $date)", mz)
        self.assertIn("self::sectionRoster($conn, $section, $date)", mz)
        rc = read("admin/backend/services/ReportCardService.php")
        self.assertIn("'include_dept_inactive' => true", rc)
        # sheet call sites pass the sheet date
        self.assertEqual(read("admin/api_attendance.php").count("['on_date' => $date]"), 2)
        self.assertIn("['on_date' => $attendanceDate]", read("admin/api_education.php"))
        att = read("api/v1/routes/attendance.php")
        self.assertIn("['on_date' => $sheetDate]", att)
        self.assertIn("['on_date' => $date]", att)

    def test_service_never_automates_members_status(self):
        for rel in [
            "admin/backend/services/DepartmentStatusService.php",
            "admin/backend/services/AttendancePolicyService.php",
            "admin/api_department_status.php",
            "admin/api_attendance_policy.php",
        ]:
            self.assertNotRegex(read(rel), r"UPDATE\s+members\s+SET\s+status", rel)

    def test_ui_hooks(self):
        self.assertIn('data-dept-status="<?= (int)$m[\'id\'] ?>"', read("admin/info_manage_member.php"))
        for rel in ["admin/dashboards/hr-dept.php", "admin/dashboards/info-dept.php"]:
            src = read(rel)
            self.assertIn("department_status.js", src, rel)
            self.assertIn("DeptStatus.mount(content)", src, rel)
        sa = read("admin/dashboards/school_admin.php")
        self.assertIn("api_attendance_policy.php", sa)
        self.assertIn('id="attPolicyCard"', sa)

    def test_php_syntax(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        for rel in [
            "admin/backend/services/DepartmentStatusService.php",
            "admin/backend/services/AttendancePolicyService.php",
            "admin/backend/services/EnrollmentService.php",
            "admin/backend/services/MezmurAttendanceService.php",
            "admin/backend/services/ReportCardService.php",
            "admin/api_department_status.php",
            "admin/api_attendance_policy.php",
            "admin/api_attendance.php",
            "admin/api_education.php",
            "admin/info_manage_member.php",
            "admin/dashboards/school_admin.php",
            "admin/dashboards/hr-dept.php",
            "admin/dashboards/info-dept.php",
            "api/v1/routes/members.php",
            "api/v1/routes/attendance.php",
        ]:
            r = subprocess.run([php, "-l", str(ROOT / rel)], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, f"{rel}: {r.stdout}{r.stderr}")

    def test_flag_off_is_a_no_op(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        code = (
            "define('FEATURE_ATTENDANCE_V2', false);"
            "require '" + str(ROOT / "admin/backend/services/DepartmentStatusService.php") + "';"
            "require '" + str(ROOT / "admin/backend/services/AttendancePolicyService.php") + "';"
            "$c = new class extends mysqli { public function __construct() {} };"
            "echo json_encode(["
            "  'avail' => \\App\\Services\\DepartmentStatusService::available($c),"
            "  'excl' => \\App\\Services\\DepartmentStatusService::exclusionSql($c, 'edu', 'm.id'),"
            "  'status' => \\App\\Services\\DepartmentStatusService::currentStatus($c, 5, 'edu')['status'],"
            "  'on' => \\App\\Services\\DepartmentStatusService::isActiveOn($c, 5, 'edu', '2026-01-01'),"
            "  'pol' => \\App\\Services\\AttendancePolicyService::for($c, 'mezmur')['consecutive_threshold'],"
            "]);"
        )
        r = subprocess.run([php, "-d", "error_reporting=24575", "-r", code], capture_output=True, text=True)
        self.assertEqual(r.returncode, 0, r.stderr)
        data = json.loads(r.stdout.strip().splitlines()[-1])
        self.assertEqual(data, {"avail": False, "excl": "", "status": "active", "on": True, "pol": 5})


# ──────────────────────────────────────────────────────────────────────
# Live
# ──────────────────────────────────────────────────────────────────────
def _server_up():
    try:
        urllib.request.urlopen(BASE + "/admin/index.php", timeout=3).read()
        return True
    except Exception:
        return False


def db(sql):
    r = subprocess.run(
        ["sudo", "-n", "mariadb", "--default-character-set=utf8mb4", "-N", "-B", "ssms_test", "-e", sql],
        capture_output=True,
        text=True,
    )
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


class WebSession:
    def __init__(self, role):
        self.role = role
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.opener.addheaders = [("User-Agent", "phase1-tests/1.0")]
        body = self._open(BASE + "/admin/index.php")[1]
        m = re.search(rb'name="csrf_token"\s+value="([a-f0-9]+)"', body)
        assert m, "csrf token not found on login page"
        data = urllib.parse.urlencode(
            {"username": USERS[role], "password": PASSWORD, "csrf_token": m.group(1).decode()}
        ).encode()
        status, body = self._open(BASE + "/admin/backend/login.php", data=data)
        if status == 429 or b"Too many login attempts" in body:
            raise unittest.SkipTest("admin login rate-limited (20 logins / 5 min per IP) — rerun later")
        page = self._open(BASE + "/admin/dashboard.php")[1].decode("utf-8", "replace")
        m2 = re.search(r"csrf[-_]token['\"]?\s*(?:content=|=|value=)\s*['\"]([a-f0-9]{64})", page, re.I)
        if m2:
            self.csrf = m2.group(1)
        else:
            m3 = re.search(r"CSRF\s*=\s*['\"]([a-f0-9]{64})['\"]", page) or re.search(r"[a-f0-9]{64}", page)
            self.csrf = (m3.group(1) if m3 and m3.lastindex else (m3.group(0) if m3 else ""))
        assert self.csrf, f"no CSRF token found for {role}"

    def _open(self, url, data=None, headers=None, method=None):
        req = urllib.request.Request(url, data=data, method=method)
        for k, v in (headers or {}).items():
            req.add_header(k, v)
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, r.read()
        except urllib.error.HTTPError as e:
            return e.code, e.read()

    def post_json(self, path, payload):
        payload = dict(payload)
        payload.setdefault("csrf_token", self.csrf)
        status, body = self._open(
            BASE + path,
            data=json.dumps(payload).encode(),
            headers={"Content-Type": "application/json", "X-CSRF-Token": self.csrf},
        )
        try:
            return status, json.loads(body.decode("utf-8"))
        except Exception:
            return status, {"raw": body[:300].decode("utf-8", "replace")}

    def get_json(self, path):
        status, body = self._open(BASE + path)
        try:
            return status, json.loads(body.decode("utf-8"))
        except Exception:
            return status, {"raw": body[:300].decode("utf-8", "replace")}


def mobile_token(role):
    req = urllib.request.Request(
        BASE + "/api/v1/auth/login",
        data=json.dumps({"username": USERS[role], "password": PASSWORD}).encode(),
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode("utf-8"))["data"]["token"]


def mobile(token, method, path, payload=None):
    req = urllib.request.Request(
        BASE + path,
        data=json.dumps(payload).encode() if payload is not None else None,
        method=method,
        headers={"Content-Type": "application/json", "Authorization": "Bearer " + token},
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body = e.read()
        try:
            return e.code, json.loads(body.decode("utf-8"))
        except Exception:
            return e.code, {"raw": body[:300].decode("utf-8", "replace")}


def _live_ready():
    if not _server_up():
        return False
    try:
        return db("SHOW TABLES LIKE 'member_department_periods'") != ""
    except Exception:
        return False


@unittest.skipUnless(_live_ready(), "local test server / sql 071 not available")
class Phase1LiveGuards(unittest.TestCase):
    MID = 900001  # enrolled in class 1 (seed)
    CLASS_ID = 1
    _sessions = {}

    @classmethod
    def setUpClass(cls):
        db(f"DELETE FROM member_department_periods WHERE member_id IN ({cls.MID}, 900002)")
        db("UPDATE attendance_policies SET consecutive_threshold=5, prolonged_threshold=8, critical_threshold=10, auto_deactivate=0, low_rate_threshold=75.00, min_sessions=3")

    @classmethod
    def tearDownClass(cls):
        db(f"DELETE FROM member_department_periods WHERE member_id IN ({cls.MID}, 900002)")
        db("UPDATE attendance_policies SET consecutive_threshold=5, prolonged_threshold=8, critical_threshold=10, auto_deactivate=0, low_rate_threshold=75.00, min_sessions=3")

    def s(self, role):
        if role not in self._sessions:
            self._sessions[role] = WebSession(role)
        return self._sessions[role]

    def setUp(self):
        status, data = self.s("hr_dept").get_json(f"/admin/api_department_status.php?action=status&member_id={self.MID}")
        if not (status == 200 and data.get("available")):
            self.skipTest("FEATURE_ATTENDANCE_V2 is off in this environment")

    # ── department status ─────────────────────────────────────────
    def test_set_forbidden_for_non_hr_info(self):
        before = db(f"SELECT COUNT(*) FROM member_department_periods WHERE member_id={self.MID}")
        for role in ["edu_dept", "teacher", "mezmur_dept", "school_admin", "super_admin"]:
            status, data = self.s(role).post_json(
                "/admin/api_department_status.php",
                {"action": "set", "member_id": self.MID, "department": "edu", "status": "inactive", "reason": "phase1 test"},
            )
            self.assertEqual(status, 403, (role, status, data))
        self.assertEqual(db(f"SELECT COUNT(*) FROM member_department_periods WHERE member_id={self.MID}"), before)

    def test_reads_allowed_for_departments_and_admins(self):
        for role in ["edu_dept", "mezmur_dept", "school_admin", "super_admin", "hr_dept", "info_dept"]:
            status, data = self.s(role).get_json(f"/admin/api_department_status.php?action=status&member_id={self.MID}")
            self.assertEqual(status, 200, (role, data))
            self.assertEqual(data["statuses"]["edu"]["status"], "active", role)
            self.assertEqual(data["can_change"], role in ("hr_dept", "info_dept"), role)
        status, data = self.s("teacher").get_json(f"/admin/api_department_status.php?action=status&member_id={self.MID}")
        self.assertEqual(status, 403, data)

    def test_validation(self):
        hr = self.s("hr_dept")
        for payload, code in [
            ({"member_id": self.MID, "department": "edu", "status": "inactive", "reason": "x"}, 400),
            ({"member_id": self.MID, "department": "hr", "status": "inactive", "reason": "phase1"}, 400),
            ({"member_id": self.MID, "department": "edu", "status": "warning", "reason": "phase1"}, 400),
            ({"member_id": 0, "department": "edu", "status": "inactive", "reason": "phase1"}, 400),
            ({"member_id": 999999999, "department": "edu", "status": "inactive", "reason": "phase1"}, 422),
        ]:
            status, data = hr.post_json("/admin/api_department_status.php", dict(payload, action="set"))
            self.assertEqual(status, code, (payload, status, data))

    def test_deactivate_excludes_from_edu_roster_only_and_reactivation_restores(self):
        hr, info, edu, mez = self.s("hr_dept"), self.s("info_dept"), self.s("edu_dept"), self.s("mezmur_dept")
        today = date.today().isoformat()
        yesterday = (date.today() - timedelta(days=1)).isoformat()

        def edu_ids(d):
            status, data = edu.get_json(f"/admin/api_education.php?action=get_class_students&class_id={self.CLASS_ID}&date={d}")
            self.assertEqual(data.get("status"), "success", data)
            rows = data.get("students") or data.get("data") or []
            return {int(r.get("member_id") or r.get("id")) for r in rows}

        def taker_ids(d):
            status, data = edu.get_json(f"/admin/api_attendance.php?action=get_class_attendance&class_id={self.CLASS_ID}&date={d}")
            self.assertEqual(data.get("status"), "success", data)
            rows = data.get("students") or data.get("data") or data.get("roster") or []
            return {int(r.get("member_id") or r.get("id")) for r in rows}

        def mezmur_ids(d):
            status, data = mez.get_json(f"/admin/api_mezmur.php?action=sheet&date={d}")
            self.assertEqual(data.get("status"), "success", data)
            ids = set()
            for members in (data.get("sections") or {}).values():
                ids |= {int(m["id"]) for m in members}
            return ids

        self.assertIn(self.MID, edu_ids(today))
        self.assertIn(self.MID, mezmur_ids(today))

        status, data = hr.post_json(
            "/admin/api_department_status.php",
            {"action": "set", "member_id": self.MID, "department": "edu", "status": "inactive", "reason": "travelled abroad"},
        )
        self.assertEqual((status, data.get("status"), data.get("changed")), (200, "success", True), data)
        self.assertNotIn(self.MID, edu_ids(today), "inactive in Education → off today's roster")
        self.assertNotIn(self.MID, taker_ids(today))
        self.assertIn(self.MID, edu_ids(yesterday), "status as of yesterday still active")
        self.assertIn(self.MID, mezmur_ids(today), "Mezmur is independent")

        # Mezmur deactivation by Information, Education untouched for 900002
        status, data = info.post_json(
            "/admin/api_department_status.php",
            {"action": "set", "member_id": 900002, "department": "mezmur", "status": "inactive", "reason": "choir leave"},
        )
        self.assertEqual((status, data.get("changed")), (200, True), data)
        self.assertNotIn(900002, mezmur_ids(today))
        self.assertIn(900002, edu_ids(today))

        # idempotent + history + audit
        status, data = hr.post_json(
            "/admin/api_department_status.php",
            {"action": "set", "member_id": self.MID, "department": "edu", "status": "inactive", "reason": "again"},
        )
        self.assertEqual(data.get("changed"), False, data)
        status, data = hr.get_json(f"/admin/api_department_status.php?action=history&member_id={self.MID}")
        self.assertEqual(len(data["history"]), 1)
        self.assertEqual(data["history"][0]["reason"], "travelled abroad")
        self.assertGreaterEqual(
            int(db("SELECT COUNT(*) FROM activity_logs WHERE action='Department Status Changed' AND created_at >= NOW() - INTERVAL 2 MINUTE") or 0), 2
        )

        # reactivate → back on today's roster, fresh period
        status, data = hr.post_json(
            "/admin/api_department_status.php",
            {"action": "set", "member_id": self.MID, "department": "edu", "status": "active", "reason": "returned"},
        )
        self.assertEqual((status, data.get("changed")), (200, True), data)
        self.assertIn(self.MID, edu_ids(today))
        self.assertEqual(db(f"SELECT COUNT(*) FROM member_department_periods WHERE member_id={self.MID}"), "2")
        self.assertEqual(db(f"SELECT COUNT(*) FROM member_department_periods WHERE member_id={self.MID} AND ended_at IS NULL"), "1")
        status, data = hr.get_json("/admin/api_department_status.php?action=summary")
        self.assertEqual(data["inactive"]["mezmur"], 1)
        self.assertEqual(data["inactive"]["edu"], 0)

    def test_mobile_department_status(self):
        info = mobile_token("info_dept")
        superadmin = mobile_token("super_admin")
        edu = mobile_token("edu_dept")
        status, data = mobile(edu, "GET", f"/api/v1/members/{self.MID}/department-status")
        self.assertEqual(status, 200, data)
        self.assertFalse(data["data"]["can_change"])
        status, data = mobile(superadmin, "POST", f"/api/v1/members/{self.MID}/department-status",
                              {"department": "mezmur", "status": "inactive", "reason": "phase1 mobile"})
        self.assertEqual(status, 403, data)
        status, data = mobile(info, "POST", f"/api/v1/members/{self.MID}/department-status",
                              {"department": "mezmur", "status": "inactive", "reason": "x"})
        self.assertEqual(status, 400, data)
        status, data = mobile(info, "POST", f"/api/v1/members/{self.MID}/department-status",
                              {"department": "mezmur", "status": "inactive", "reason": "phase1 mobile"})
        self.assertEqual(status, 200, data)
        self.assertTrue(data["data"]["changed"])
        status, data = mobile(info, "GET", f"/api/v1/members/{self.MID}/department-status")
        self.assertEqual(data["data"]["statuses"]["mezmur"]["status"], "inactive")
        self.assertEqual(data["data"]["statuses"]["edu"]["status"], "active")
        status, data = mobile(info, "POST", f"/api/v1/members/{self.MID}/department-status",
                              {"department": "mezmur", "status": "active", "reason": "phase1 mobile back"})
        self.assertEqual(status, 200, data)

    # ── policy ────────────────────────────────────────────────────
    def test_policy_roles_and_validation(self):
        good = {"action": "save", "department": "edu", "consecutive_threshold": 4, "prolonged_threshold": 7,
                "critical_threshold": 9, "auto_deactivate": 0, "low_rate_threshold": 70, "min_sessions": 2}
        for role in ["hr_dept", "info_dept", "edu_dept", "mezmur_dept", "super_admin"]:
            status, data = self.s(role).post_json("/admin/api_attendance_policy.php", good)
            self.assertEqual(status, 403, (role, status, data))
        status, data = self.s("teacher").get_json("/admin/api_attendance_policy.php?action=get")
        self.assertEqual(status, 403, data)
        for role in ["hr_dept", "edu_dept", "super_admin", "school_admin"]:
            status, data = self.s(role).get_json("/admin/api_attendance_policy.php?action=get")
            self.assertEqual(status, 200, (role, data))
            self.assertEqual(data["policies"]["edu"]["consecutive_threshold"], 5)
            self.assertEqual(data["can_edit"], role == "school_admin")
        sa = self.s("school_admin")
        for bad in [
            dict(good, prolonged_threshold=3),
            dict(good, critical_threshold=61),
            dict(good, low_rate_threshold=0),
            dict(good, low_rate_threshold=101),
            dict(good, min_sessions=0),
            dict(good, consecutive_threshold="abc"),
            dict(good, department="hr"),
        ]:
            status, data = sa.post_json("/admin/api_attendance_policy.php", bad)
            self.assertEqual(status, 400, (bad, status, data))
        status, data = sa.post_json("/admin/api_attendance_policy.php", good)
        self.assertEqual((status, data.get("changed")), (200, True), data)
        self.assertEqual(data["policy"]["consecutive_threshold"], 4)
        self.assertEqual(data["policies"]["mezmur"]["consecutive_threshold"], 5, "other department untouched")
        self.assertEqual(db("SELECT consecutive_threshold FROM attendance_policies WHERE department='edu'"), "4")
        self.assertGreaterEqual(
            int(db("SELECT COUNT(*) FROM activity_logs WHERE action='Attendance Policy Updated' AND created_at >= NOW() - INTERVAL 2 MINUTE") or 0), 1
        )
        status, data = sa.post_json("/admin/api_attendance_policy.php", good)
        self.assertEqual(data.get("changed"), False)


if __name__ == "__main__":
    unittest.main()
