"""Attendance alerting — Phase 0 guardrails (2026-10-19).

Static guards (always run):
  - automation never writes members.status (api_attendance.php),
  - alert dedupe uses an exact JSON match (no LIKE prefix bug),
  - manual status is HR / Information only, active <-> inactive only
    (api_attendance_info.php, info_manage_member.php, mobile members.php),
  - Info dashboard quick-change no longer offers 'warning',
  - calendar mode is forced to Ethiopian; super-admin Gregorian option gone,
  - FEATURE_ATTENDANCE_V2 flag exists and defaults OFF.

Live guards (skipped unless the local test server is up):
  - role matrix on update_status / bulk_status (web),
  - value matrix ('warning', missing reason, archived member),
  - mobile PUT /members/{id} strips status for everyone but HR / Info,
  - info_manage_member.php ignores a posted status from other roles,
  - super-admin settings page renders no calendar switch.

Env: tests/e2e/provision_env.sh world (users audit_* / AuditTest#2026,
members 900000..900017) served at http://127.0.0.1:8080.
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
    "attendance_taker": "audit_taker",
}
TOKEN_RE = re.compile(r"[a-f0-9]{64}")


def read(rel):
    return (ROOT / rel).read_text(encoding="utf-8")


def code_lines(src):
    """PHP source with //-comment lines dropped (so comments may mention the old SQL)."""
    return "\n".join(l for l in src.splitlines() if not l.strip().startswith(("//", "*", "/*")))


# ──────────────────────────────────────────────────────────────────────
# Static guards
# ──────────────────────────────────────────────────────────────────────
class Phase0StaticGuards(unittest.TestCase):
    def test_automation_never_writes_members_status(self):
        for rel in [
            "admin/api_attendance.php",
            "admin/api_education.php",
            "admin/backend/services/AttendanceSummaryService.php",
            "admin/backend/services/AttendanceRecordService.php",
            "admin/backend/workflow.php",
            "api/v1/routes/attendance.php",
            "admin/api_mezmur.php",
        ]:
            src = code_lines(read(rel))
            self.assertNotRegex(
                src, r"UPDATE\s+members\s+SET\s+status\s*=\s*'warning'", f"{rel} still auto-flags members"
            )

    def test_legacy_alert_producers_removed(self):
        # Phase 2 replaced the ad-hoc attendance_alert / attendance_issue producers
        # with AttendanceWarningService; the LIKE-prefix dedupe must never return.
        for rel in ["admin/api_attendance.php", "admin/backend/services/AttendanceSummaryService.php"]:
            src = code_lines(read(rel))
            self.assertNotIn('\'%"member_id":\'', src, f"{rel} still uses the LIKE prefix dedupe")
            self.assertNotIn("'attendance_alert'", src, rel)
            self.assertNotIn("'attendance_issue'", src, rel)

    def test_web_status_api_is_hr_info_only(self):
        src = read("admin/api_attendance_info.php")
        self.assertIn("ATTENDANCE_STATUS_WRITER_ROLES", src)
        self.assertIn("['hr_dept', 'info_dept']", src.replace('"', "'"))
        self.assertIn("ATTENDANCE_MANUAL_STATUSES", src)
        self.assertIn("['active', 'inactive']", src.replace('"', "'"))
        self.assertIn("'Member Status Changed'", src)
        self.assertIn("'Member Status Bulk Changed'", src)
        self.assertIn("AND status != 'archived'", src)

    def test_edit_form_status_is_role_gated(self):
        src = read("admin/info_manage_member.php")
        self.assertIn("hasRole(['hr_dept', 'info_dept'])", src)
        self.assertNotIn('<option value="warning"', src)
        self.assertNotIn('<option value="archived"', src)
        self.assertIn("'Member Status Changed'", src)

    def test_mobile_put_strips_status_for_other_roles(self):
        src = read("api/v1/routes/members.php")
        self.assertIn("apiRoleIs($auth, ['hr_dept', 'info_dept'])", src)
        self.assertIn("unset($input['status'])", src)
        self.assertIn("'Member Status Changed'", src)

    def test_info_dashboard_quick_change_offers_active_inactive_only(self):
        src = read("admin/dashboards/info-dept.php")
        start = src.index("function quickStatusChange(")
        # whole function (it grew when the danger-confirmation dialog replaced prompt())
        body = code_lines(src[start : src.index("\n}\n", start)])
        self.assertNotIn("'warning'", body)
        self.assertIn("['active','inactive']", body)
        self.assertIn("csrf_token", body)

    def test_calendar_mode_is_forced_ethiopian(self):
        src = read("admin/backend/calendar_system.php")
        fn = code_lines(src[src.index("function wbws_get_calendar_mode") : src.index("function wbws_format_date")])
        self.assertNotIn("dept_settings", fn)
        self.assertIn("return 'ethiopian';", fn)
        sa = read("admin/dashboards/super-admin.php")
        self.assertNotIn("saveCalendarMode", sa)
        self.assertNotIn('value="gregorian"', sa)
        self.assertNotIn("save_calendar_mode", sa)

    def test_attendance_screens_format_dates_via_calendar_helpers(self):
        taker = read("admin/dashboards/attendance_taker.php")
        self.assertNotIn("${h.attendance_date}</td>", taker)
        info = read("admin/dashboards/info-dept.php")
        self.assertNotIn("'+r.attendance_date+'", info)
        self.assertNotIn("labels:wt.map(w=>w.day.slice(5))", info)

    def test_feature_flag_exists_and_defaults_off(self):
        self.assertIn("'attendance_v2' => 'FEATURE_ATTENDANCE_V2'", read("admin/backend/services/FeatureGate.php"))
        self.assertIn(
            "if (!defined('FEATURE_ATTENDANCE_V2')) define('FEATURE_ATTENDANCE_V2', false);",
            read("school_config.php"),
        )
        self.assertIn("FEATURE_ATTENDANCE_V2", read("env.example.php"))

    def test_php_syntax(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        for rel in [
            "admin/api_attendance_info.php",
            "admin/api_attendance.php",
            "admin/info_manage_member.php",
            "admin/backend/calendar_system.php",
            "admin/backend/services/AttendanceSummaryService.php",
            "admin/backend/services/FeatureGate.php",
            "admin/dashboards/super-admin.php",
            "admin/dashboards/info-dept.php",
            "admin/dashboards/attendance_taker.php",
            "api/v1/routes/members.php",
            "school_config.php",
        ]:
            r = subprocess.run([php, "-l", str(ROOT / rel)], capture_output=True, text=True)
            self.assertEqual(r.returncode, 0, f"{rel}: {r.stdout}{r.stderr}")

    def test_calendar_mode_ignores_db_and_session(self):
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP CLI is not installed")
        fixture = (
            "session_start(); $_SESSION['wbws_calendar_mode']='gregorian';"
            "require '" + str(ROOT / "admin/backend/calendar_system.php").replace("'", "\\'") + "';"
            "$c = new class { public function query($q){ return new class { public function fetch_assoc(){ return ['setting_value'=>'gregorian']; } }; } };"
            "echo wbws_get_calendar_mode($c), '|', $_SESSION['wbws_calendar_mode'];"
        )
        r = subprocess.run([php, "-d", "error_reporting=24575", "-r", fixture], capture_output=True, text=True)
        self.assertEqual(r.stdout.strip(), "ethiopian|ethiopian", r.stdout + r.stderr)


# ──────────────────────────────────────────────────────────────────────
# Live guards
# ──────────────────────────────────────────────────────────────────────
def _server_up():
    try:
        urllib.request.urlopen(BASE + "/admin/index.php", timeout=3).read()
        return True
    except Exception:
        return False


class WebSession:
    def __init__(self, role):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.opener.addheaders = [("User-Agent", "phase0-tests/1.0")]
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
        if 'name="csrf_token"' in page and "login" in page.lower() and "dashboard" not in page.lower():
            raise AssertionError(f"login failed for {role}")
        m2 = re.search(r"csrf[-_]token['\"]?\s*(?:content=|=|value=)\s*['\"]([a-f0-9]{64})", page, re.I)
        self.csrf = m2.group(1) if m2 else (TOKEN_RE.search(page).group(0) if TOKEN_RE.search(page) else "")
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

    def post_form(self, path, fields):
        fields = dict(fields)
        fields.setdefault("csrf_token", self.csrf)
        status, body = self._open(BASE + path, data=urllib.parse.urlencode(fields).encode())
        try:
            return status, json.loads(body.decode("utf-8"))
        except Exception:
            return status, {"raw": body[:300].decode("utf-8", "replace")}

    def get(self, path):
        return self._open(BASE + path)


class _HealingSession:
    def __init__(self, role):
        self.role = role
        self.ws = WebSession(role)

    def _retry(self, fn, *a):
        status, data = fn(*a)
        if status == 401 or (isinstance(data, dict) and data.get("status") == "session_expired"):
            self.ws = WebSession(self.role)
            status, data = getattr(self.ws, fn.__name__)(*a)
        return status, data

    def post_json(self, path, payload):
        return self._retry(self.ws.post_json, path, payload)

    def post_form(self, path, fields):
        return self._retry(self.ws.post_form, path, fields)

    def get(self, path):
        status, body = self.ws.get(path)
        if status == 401:
            self.ws = WebSession(self.role)
            status, body = self.ws.get(path)
        return status, body


def mobile_token(role):
    req = urllib.request.Request(
        BASE + "/api/v1/auth/login",
        data=json.dumps({"username": USERS[role], "password": PASSWORD}).encode(),
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        data = json.loads(r.read().decode("utf-8"))
    return data["data"]["token"]


def mobile_put(token, member_id, payload):
    req = urllib.request.Request(
        BASE + f"/api/v1/members/{member_id}",
        data=json.dumps(payload).encode(),
        method="PUT",
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


def db(sql):
    r = subprocess.run(
        ["sudo", "-n", "mariadb", "--default-character-set=utf8mb4", "-N", "-B", "ssms_test", "-e", sql],
        capture_output=True,
        text=True,
    )
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


def member_status(mid):
    return db(f"SELECT status FROM members WHERE id={int(mid)}")


@unittest.skipUnless(_server_up(), "local test server not running")
class Phase0LiveGuards(unittest.TestCase):
    MID_A = 900010  # web update_status target
    MID_B = 900011  # bulk target
    MID_C = 900012  # mobile target
    MID_D = 900013  # edit-form target
    MID_ARCH = 900014  # archived fixture

    @classmethod
    def setUpClass(cls):
        try:
            db("SELECT 1")
        except Exception as e:  # pragma: no cover
            raise unittest.SkipTest(f"mariadb not reachable: {e}")
        cls._sessions = {}
        db(f"UPDATE members SET status='active' WHERE id IN ({cls.MID_A},{cls.MID_B},{cls.MID_C},{cls.MID_D})")
        db(f"UPDATE members SET status='archived' WHERE id={cls.MID_ARCH}")

    class _Sessions:
        """Lazy, self-healing sessions: other suites may invalidate audit_* logins."""

        def __init__(self, owner):
            self.owner = owner

        def __getitem__(self, role):
            cache = self.owner._sessions
            if role not in cache:
                cache[role] = _HealingSession(role)
            return cache[role]

    @property
    def sessions(self):
        return self._Sessions(type(self))

    @classmethod
    def tearDownClass(cls):
        db(
            f"UPDATE members SET status='active' WHERE id IN "
            f"({cls.MID_A},{cls.MID_B},{cls.MID_C},{cls.MID_D},{cls.MID_ARCH})"
        )

    # ── web: update_status / bulk_status ────────────────────────────
    def test_update_status_forbidden_for_non_hr_info(self):
        for role in ["edu_dept", "teacher", "attendance_taker", "super_admin", "school_admin"]:
            status, data = self.sessions[role].post_json(
                "/admin/api_attendance_info.php?action=update_status",
                {"member_id": self.MID_A, "new_status": "inactive", "reason": "phase0 test"},
            )
            self.assertEqual(status, 403, (role, status, data))
            self.assertEqual(data.get("status"), "error", (role, data))
        self.assertEqual(member_status(self.MID_A), "active")

    def test_bulk_status_forbidden_for_non_hr_info(self):
        for role in ["edu_dept", "teacher", "attendance_taker", "super_admin", "school_admin"]:
            status, data = self.sessions[role].post_json(
                "/admin/api_attendance_info.php?action=bulk_status",
                {"member_ids": [self.MID_B], "new_status": "inactive", "reason": "phase0 test"},
            )
            self.assertEqual(status, 403, (role, status, data))
        self.assertEqual(member_status(self.MID_B), "active")

    def test_info_can_toggle_active_inactive_with_reason(self):
        # api_attendance_info.php is the Information dashboard API (HR is not in
        # its page gate — HR changes status through the member edit form, see
        # test_edit_form_ignores_status_from_admins_but_honours_hr).
        info = self.sessions["info_dept"]
        status, data = info.post_json(
            "/admin/api_attendance_info.php?action=update_status",
            {"member_id": self.MID_A, "new_status": "inactive", "reason": "left the school"},
        )
        self.assertEqual((status, data.get("status")), (200, "success"), data)
        self.assertEqual(member_status(self.MID_A), "inactive")
        status, data = info.post_json(
            "/admin/api_attendance_info.php?action=update_status",
            {"member_id": self.MID_A, "new_status": "active", "reason": ""},
        )
        self.assertEqual((status, data.get("status")), (200, "success"), data)
        self.assertEqual(member_status(self.MID_A), "active")
        audit = db(
            "SELECT COUNT(*) FROM activity_logs WHERE action='Member Status Changed' "
            f"AND created_at >= NOW() - INTERVAL 2 MINUTE"
        )
        self.assertGreaterEqual(int(audit or 0), 2)

    def test_warning_and_missing_reason_rejected(self):
        hr = self.sessions["info_dept"]
        for payload in [
            {"member_id": self.MID_A, "new_status": "warning", "reason": "phase0"},
            {"member_id": self.MID_A, "new_status": "archived", "reason": "phase0"},
            {"member_id": self.MID_A, "new_status": "inactive", "reason": ""},
            {"member_id": self.MID_ARCH, "new_status": "active", "reason": "phase0"},
        ]:
            status, data = hr.post_json("/admin/api_attendance_info.php?action=update_status", payload)
            self.assertNotEqual(data.get("status"), "success", (payload, status, data))
        self.assertEqual(member_status(self.MID_A), "active")
        self.assertEqual(member_status(self.MID_ARCH), "archived")

    def test_bulk_status_info_ok_and_capped(self):
        hr = self.sessions["info_dept"]
        status, data = hr.post_json(
            "/admin/api_attendance_info.php?action=bulk_status",
            {"member_ids": [self.MID_B], "new_status": "inactive", "reason": "phase0 bulk"},
        )
        self.assertEqual((status, data.get("status")), (200, "success"), data)
        self.assertEqual(member_status(self.MID_B), "inactive")
        status, data = hr.post_json(
            "/admin/api_attendance_info.php?action=bulk_status",
            {"member_ids": [self.MID_B], "new_status": "active", "reason": ""},
        )
        self.assertEqual(member_status(self.MID_B), "active")
        status, data = hr.post_json(
            "/admin/api_attendance_info.php?action=bulk_status",
            {"member_ids": list(range(1, 202)), "new_status": "active", "reason": ""},
        )
        self.assertNotEqual(data.get("status"), "success", data)

    # ── mobile PUT /members/{id} ──────────────────────────────────────
    def test_mobile_put_status_matrix(self):
        info = mobile_token("info_dept")
        superadmin = mobile_token("super_admin")
        # info may deactivate / reactivate
        status, data = mobile_put(info, self.MID_C, {"status": "inactive"})
        self.assertEqual(status, 200, data)
        self.assertEqual(member_status(self.MID_C), "inactive")
        status, data = mobile_put(info, self.MID_C, {"status": "active"})
        self.assertEqual(member_status(self.MID_C), "active")
        # 'warning' is never accepted, even from info
        status, data = mobile_put(info, self.MID_C, {"status": "warning"})
        self.assertEqual(status, 403, data)
        self.assertEqual(member_status(self.MID_C), "active")
        # super_admin: status alone -> 403, status + other field -> other field applies, status ignored
        status, data = mobile_put(superadmin, self.MID_C, {"status": "inactive"})
        self.assertEqual(status, 403, data)
        status, data = mobile_put(superadmin, self.MID_C, {"status": "inactive", "city": "Addis Ababa"})
        self.assertEqual(status, 200, data)
        self.assertEqual(member_status(self.MID_C), "active")

    # ── edit form ─────────────────────────────────────────────────────
    def test_edit_form_ignores_status_from_admins_but_honours_hr(self):
        sa = self.sessions["super_admin"]
        status, data = sa.post_form(
            "/admin/info_manage_member.php", {"id": self.MID_D, "status": "inactive"}
        )
        self.assertEqual(data.get("status"), "success", data)
        self.assertEqual(member_status(self.MID_D), "active")
        hr = self.sessions["hr_dept"]
        status, data = hr.post_form(
            "/admin/info_manage_member.php", {"id": self.MID_D, "status": "warning"}
        )
        self.assertEqual(member_status(self.MID_D), "active")
        status, data = hr.post_form(
            "/admin/info_manage_member.php", {"id": self.MID_D, "status": "inactive"}
        )
        self.assertEqual(data.get("status"), "success", data)
        self.assertEqual(member_status(self.MID_D), "inactive")
        status, data = hr.post_form(
            "/admin/info_manage_member.php", {"id": self.MID_D, "status": "active"}
        )
        self.assertEqual(member_status(self.MID_D), "active")
        # the form itself renders read-only for admins
        page = sa.get(f"/admin/info_manage_member.php?id={self.MID_D}")[1].decode("utf-8", "replace")
        self.assertNotIn('<select name="status"', page)
        page = hr.get(f"/admin/info_manage_member.php?id={self.MID_D}")[1].decode("utf-8", "replace")
        self.assertIn('<select name="status"', page)
        self.assertNotIn('<option value="warning"', page)

    # ── calendar ─────────────────────────────────────────────────────
    def test_super_admin_settings_has_no_calendar_switch(self):
        page = self.sessions["super_admin"].get("/admin/dashboard.php?section=settings")[1].decode(
            "utf-8", "replace"
        )
        self.assertNotIn("saveCalendarMode", page)
        self.assertNotIn('value="gregorian"', page)
        self.assertIn("not configurable", page)

    def test_save_calendar_mode_is_not_an_action(self):
        status, data = self.sessions["super_admin"].post_form(
            "/admin/api_settings.php", {"action": "save_calendar_mode", "mode": "gregorian"}
        )
        self.assertNotEqual(data.get("status"), "success", data)
        self.assertEqual(
            db("SELECT setting_value FROM dept_settings WHERE setting_key='calendar_mode'"), "ethiopian"
        )


if __name__ == "__main__":
    unittest.main()
