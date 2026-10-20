"""Class-day-only attendance + School Calendar ("Day Off") — 2026-10-20.

Owner decisions (docs/planning/ATTENDANCE_CLASS_DAY_ENFORCEMENT_PLAN_2026-10-20.md):
  1. unconfigured classes are BLOCKED (no Sat+Sun fallback);
  2. backdating window = 45 days;
  3. "Day Off" asks per entry whether makeup classes are also cancelled;
  4. marking a Day Off is refused once attendance exists for the date;
  5. Education dept only writes holidays / day offs.
Naming: the feature is "Day Off" — the words "urgent"/"emergency" must not
appear in its UI strings.
"""
from __future__ import annotations

import json
import os
import re
import sys
import unittest
import urllib.error
import urllib.parse
from datetime import date, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from test_attendance_phase1 import (  # noqa: E402
    BASE, USERS, WebSession, db, mobile, mobile_token, _live_ready,
)

SVC = "admin/backend/services/SchoolCalendarService.php"
SQL = "sql/078_school_calendar.sql"
API = "admin/api_school_calendar.php"
UI_FILES = [
    "admin/school_calendar.php",
    "admin/js/school_calendar.js",
    "admin/js/school_calendar_widget.js",
    "Mobile/wbws_flutter_app/lib/screens/attendance/attendance_screen.dart",
]


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class SchoolCalendarStatic(unittest.TestCase):
    def test_schema_is_additive_and_idempotent(self):
        src = read(SQL)
        self.assertIn("CREATE TABLE IF NOT EXISTS `school_holidays`", src)
        self.assertIn("CREATE TABLE IF NOT EXISTS `school_day_offs`", src)
        self.assertIn("cancel_makeup", src)
        # additive only: no touching of existing tables
        self.assertNotIn("ALTER TABLE `attendance`", src)
        self.assertNotRegex(src, r"(?i)ADD COLUMN IF NOT EXISTS")
        self.assertNotRegex(src, r"(?i)\b(CREATE PROCEDURE|CREATE EVENT|DELIMITER)\b")

    def test_service_constants_and_roles(self):
        src = read(SVC)
        self.assertIn("BACKDATE_DAYS = 45", src)
        self.assertIn("WRITE_ROLES = ['edu_dept']", src)
        self.assertIn("function classDayStatus", src)
        self.assertIn("function dayType", src)
        self.assertIn("ATTENDANCE_EXISTS", src)

    def test_enforcement_in_every_save_path(self):
        self.assertIn("apiAssertClassDay", read("api/v1/routes/attendance.php"))
        web = read("admin/api_attendance.php")
        self.assertIn("$assertClassDay", web)
        self.assertGreaterEqual(web.count("$assertClassDay($date, $classId);"), 2)
        lesson = read("admin/api_lesson_attendance.php")
        self.assertIn("SchoolCalendarService::classDayStatus", lesson)

    def test_date_status_in_read_apis(self):
        self.assertIn("'date_status'", read("api/v1/routes/attendance.php"))
        self.assertIn("'date_status'", read("admin/api_attendance.php"))
        self.assertIn("'date_status'", read("admin/api_lesson_attendance.php"))

    def test_ui_never_says_urgent_or_emergency(self):
        for rel in UI_FILES:
            src = read(rel).lower()
            self.assertNotIn("urgent", src, rel)
            self.assertNotIn("emergency", src, rel)

    def test_ui_offers_makeup_choice_and_reopen(self):
        js = read("admin/js/school_calendar.js")
        self.assertIn("cancel_makeup", js)
        self.assertIn("Also cancel makeup classes", js)
        self.assertIn("reopen_day_off", js)
        self.assertIn("mark_day_off", js)

    def test_mobile_picker_window_and_banner(self):
        dart = read("Mobile/wbws_flutter_app/lib/screens/attendance/attendance_screen.dart")
        self.assertIn("Duration(days: 45)", dart)
        self.assertIn("_dayAllowed", dart)
        self.assertIn("date_status", dart)


@unittest.skipUnless(_live_ready(), "local test server / seeded DB not available")
class SchoolCalendarLive(unittest.TestCase):
    TEMP_MEMBER = 900701

    @classmethod
    def setUpClass(cls):
        cls.edu = WebSession("edu_dept")
        cls.teacher = WebSession("teacher")
        # Anchor on the SERVER's day (Africa/Addis_Ababa): the sandbox clock
        # can be a different UTC day, and "today" drives both the 45-day
        # window and the Day Off write rule.
        from datetime import datetime as _dt
        probe_from = (_dt.now() - __import__('datetime').timedelta(days=2)).strftime('%Y-%m-%d')
        probe_to = (_dt.now() + __import__('datetime').timedelta(days=30)).strftime('%Y-%m-%d')
        st, data = cls.edu.get_json(
            f"/admin/api_school_calendar.php?action=overview&from={probe_from}&to={probe_to}")
        cls.today = data.get("today") or date.today().isoformat()

    def _api(self, session: "WebSession", action: str, payload: dict):
        payload = dict(payload)
        payload["action"] = action
        return session.post_json("/admin/api_school_calendar.php", payload)

    def _get(self, session: "WebSession", action: str, params: str):
        return session.get_json(f"/admin/api_school_calendar.php?action={action}&{params}")

    def _post_form(self, session: "WebSession", path: str, fields: dict):
        fields = dict(fields)
        fields.setdefault("csrf_token", session.csrf)
        data = urllib.parse.urlencode(fields).encode()
        status, body = session._open(BASE + path, data=data, headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "X-CSRF-Token": session.csrf,
        })
        try:
            return status, json.loads(body.decode("utf-8", "replace"))
        except Exception:
            return status, {"raw": body[:300].decode("utf-8", "replace")}

    def _cleanup(self):
        db(f"DELETE FROM school_day_offs WHERE day_date >= '{self.today}'")
        db(f"DELETE FROM school_holidays WHERE date_to >= '{self.today}'")
        db(f"DELETE FROM attendance WHERE member_id = {self.TEMP_MEMBER}")
        db(f"DELETE FROM attendance_summary WHERE member_id = {self.TEMP_MEMBER}")
        db(f"DELETE FROM class_enrollments WHERE member_id = {self.TEMP_MEMBER}")
        db(f"DELETE FROM members WHERE id = {self.TEMP_MEMBER}")
        db("DELETE FROM classes WHERE class_name_en LIKE 'SC Temp%'")
        db("DELETE FROM timetable_makeup_classes WHERE class_id NOT IN (1,2,3)")
        db("DELETE FROM timetable_class_days WHERE class_id NOT IN (SELECT id FROM classes)")

    def setUp(self):
        self._cleanup()

    def tearDown(self):
        self._cleanup()

    # ── decisions 3 + 4 + the full day-off lifecycle on TODAY ──────────────
    def test_day_off_blocks_saves_reopen_restores_and_attendance_locks(self):
        # a temp class whose ONLY student is the fixture member → the sheet is
        # complete with one record (save_attendance requires a full roster)
        db("INSERT INTO classes (class_name, class_name_en, class_code, level_order, is_active)"
           " VALUES ('SC Temp', 'SC Temp C', 'SCTMPC', 99, 1)")
        cid = int(db("SELECT id FROM classes WHERE class_name_en = 'SC Temp C' LIMIT 1"))
        db(f"INSERT INTO timetable_class_days (class_id, academic_year_id, working_days, updated_by)"
           f" VALUES ({cid}, 1, '1,2,3,4,5,6,7', 2)")
        db(f"INSERT INTO members (id, member_code, full_name_am, student_name, father_name, gender,"
           f" status, member_type, age_group, created_at)"
           f" VALUES ({self.TEMP_MEMBER}, 'SC701', 'SC ተመራጭ', 'SC', 'Test', 'male', 'active', 'regular', '7_13', NOW())")
        db(f"INSERT INTO class_enrollments (member_id, class_id, academic_year_id, status, enrolled_at)"
           f" VALUES ({self.TEMP_MEMBER}, {cid}, 1, 'active', CURDATE())")

        # 1. mark today off (makeups kept — decision 3 choice)
        st, data = self._api(self.edu, "mark_day_off",
                             {"date": self.today, "reason": "SC test day off", "cancel_makeup": "0"})
        self.assertEqual(st, 200, data)

        # 2. date_status shows DAY_OFF
        st, data = self._get(self.edu, "date_status", f"class_id={cid}&date={self.today}")
        self.assertEqual(data["date_status"]["code"], "DAY_OFF", data)

        # 3. web save refused with the code
        st, data = self._post_form(self.edu, "/admin/api_attendance.php",
                                   {"action": "save_attendance", "class_id": cid, "date": self.today,
                                    "records": json.dumps([{"member_id": self.TEMP_MEMBER, "status": "present"}])})
        self.assertEqual(st, 422, data)
        self.assertEqual(data.get("code"), "DAY_OFF", data)

        # 4. mobile save refused the same way (rule applies to the app)
        try:
            tok = mobile_token("teacher")
            st, data = mobile(tok, "POST", "/api/v1/attendance",
                              {"class_id": cid, "date": self.today,
                               "records": [{"member_id": self.TEMP_MEMBER, "status": "present"}]})
            self.assertEqual(st, 422, data)
            self.assertEqual(data.get("code"), "DAY_OFF", data)
        except urllib.error.HTTPError as e:
            self.assertEqual(e.code, 422)

        # 5. reopen restores the day (decision: before the day passes)
        st, data = self._api(self.edu, "reopen_day_off", {"date": self.today, "reason": ""})
        self.assertEqual(st, 200, data)

        # 6. save now goes through
        st, data = self._post_form(self.edu, "/admin/api_attendance.php",
                                   {"action": "save_attendance", "class_id": cid, "date": self.today,
                                    "records": json.dumps([{"member_id": self.TEMP_MEMBER, "status": "present"}])})
        self.assertEqual(st, 200, data)

        # 7. decision 4: now the day cannot be marked off again
        st, data = self._api(self.edu, "mark_day_off",
                             {"date": self.today, "reason": "too late", "cancel_makeup": "1"})
        self.assertEqual(st, 422, data)
        self.assertEqual(data.get("code"), "ATTENDANCE_EXISTS", data)

    # ── decision 2: 45-day window ─────────────────────────────────────────
    def test_backdate_window_is_45_days(self):
        today = date.fromisoformat(self.today)
        old = (today - timedelta(days=46)).isoformat()
        edge = (today - timedelta(days=45)).isoformat()
        st, data = self._get(self.edu, "date_status", f"class_id=1&date={old}")
        self.assertEqual(data["date_status"]["code"], "BACKDATE_LIMIT", data)
        st, data = self._get(self.edu, "date_status", f"class_id=1&date={edge}")
        self.assertIn(data["date_status"]["code"], ("OK", "NOT_CLASS_DAY", "HOLIDAY", "DAY_OFF"), data)

    def test_future_dates_still_refused(self):
        future = (date.fromisoformat(self.today) + timedelta(days=3)).isoformat()
        st, data = self._get(self.edu, "date_status", f"class_id=1&date={future}")
        self.assertEqual(data["date_status"]["code"], "FUTURE_DATE", data)

    # ── decision 1: unconfigured class is blocked ─────────────────────────
    def test_unconfigured_class_is_blocked(self):
        db("INSERT INTO classes (class_name, class_name_en, class_code, level_order, is_active)"
           " VALUES ('SC Temp', 'SC Temp A', 'SCTMP', 99, 1)")
        cid = int(db("SELECT id FROM classes WHERE class_name_en = 'SC Temp A' LIMIT 1"))
        st, data = self._get(self.edu, "date_status", f"class_id={cid}&date={self.today}")
        self.assertEqual(data["date_status"]["code"], "NOT_CONFIGURED", data)
        # overview lists it for the Education page
        st, data = self._get(self.edu, "overview", f"from={self.today}&to={self.today}")
        names = [c["name"] for c in data.get("unconfigured_classes", [])]
        self.assertIn("SC Temp A", names, names)

    # ── decision 3: makeup classes vs Day Off ─────────────────────────────
    def test_makeup_exception_and_cancel_makeup_flag(self):
        db("INSERT INTO classes (class_name, class_name_en, class_code, level_order, is_active)"
           " VALUES ('SC Temp', 'SC Temp B', 'SCTMPB', 99, 1)")
        cid = int(db("SELECT id FROM classes WHERE class_name_en = 'SC Temp B' LIMIT 1"))
        # the class meets on any weekday EXCEPT the server-today's → NOT_CLASS_DAY
        today_iso = date.fromisoformat(self.today).weekday() + 1   # Mon=1 .. Sun=7
        other_iso = (today_iso % 7) + 1
        db(f"INSERT INTO timetable_class_days (class_id, academic_year_id, working_days, updated_by)"
           f" VALUES ({cid}, 1, '{other_iso}', 2)")
        t = self.today
        st, data = self._get(self.edu, "date_status", f"class_id={cid}&date={t}")
        self.assertEqual(data["date_status"]["code"], "NOT_CLASS_DAY", (t, data))
        # with a makeup class on that date it becomes a class day
        db(f"INSERT INTO timetable_makeup_classes (class_id, academic_year_id, lesson_date, start_time,"
           f" end_time, reason, created_by) VALUES ({cid}, 1, '{t}', '09:00', '10:00', 'SC test', 2)")
        st, data = self._get(self.edu, "date_status", f"class_id={cid}&date={t}")
        self.assertTrue(data["date_status"]["ok"], data)
        self.assertTrue(data["date_status"].get("is_makeup"), data)
        # Day Off with cancel_makeup=0 keeps the makeup alive (decision 3)
        st, data = self._api(self.edu, "mark_day_off",
                             {"date": t, "reason": "SC test", "cancel_makeup": "0"})
        self.assertEqual(st, 200, data)
        st, data = self._get(self.edu, "date_status", f"class_id={cid}&date={t}")
        self.assertTrue(data["date_status"]["ok"], data)
        self.assertTrue(data["date_status"].get("is_makeup"), data)
        # reopen + mark with cancel_makeup=1 → everything off
        st, data = self._api(self.edu, "reopen_day_off", {"date": t, "reason": ""})
        self.assertEqual(st, 200, data)
        st, data = self._api(self.edu, "mark_day_off",
                             {"date": t, "reason": "SC test", "cancel_makeup": "1"})
        self.assertEqual(st, 200, data)
        st, data = self._get(self.edu, "date_status", f"class_id={cid}&date={t}")
        self.assertEqual(data["date_status"]["code"], "DAY_OFF", data)

    # ── holidays + the calendar-grid dayType (future days visible) ────────
    def test_holiday_shown_in_grid_even_for_future_days(self):
        future = (date.fromisoformat(self.today) + timedelta(days=4)).isoformat()
        st, data = self._api(self.edu, "save_holiday",
                             {"title": "SC Test Holiday", "title_am": "SC በዓል",
                              "date_from": future, "date_to": future, "type": "holiday"})
        self.assertEqual(st, 200, data)
        # save verdict on that future date stays FUTURE_DATE (saving is impossible)
        st, data = self._get(self.edu, "date_status", f"class_id=1&date={future}")
        self.assertEqual(data["date_status"]["code"], "FUTURE_DATE", data)
        # but the calendar grid (dayType) shows the holiday
        st, data = self._get(self.edu, "class_days", f"class_id=1&from={future}&to={future}")
        self.assertEqual(data["days"][future]["code"], "HOLIDAY", data)

    # ── timetable ↔ calendar link: school day / neutral / off ──────────────
    def test_school_days_follow_the_timetable(self):
        # temp class meets on the weekday AFTER today's → that day becomes a
        # school day, today becomes/keeps whatever the other classes say
        db("INSERT INTO classes (class_name, class_name_en, class_code, level_order, is_active)"
           " VALUES ('SC Temp', 'SC Temp D', 'SCTMPD', 99, 1)")
        cid = int(db("SELECT id FROM classes WHERE class_name_en = 'SC Temp D' LIMIT 1"))
        today_iso = date.fromisoformat(self.today).weekday() + 1
        meet_iso = (today_iso % 7) + 1
        meet_date = date.fromisoformat(self.today)
        while meet_date.weekday() + 1 != meet_iso:
            meet_date += timedelta(days=1)
        db(f"INSERT INTO timetable_class_days (class_id, academic_year_id, working_days, updated_by)"
           f" VALUES ({cid}, 1, '{meet_iso}', 2)")
        m = meet_date.isoformat()
        st, data = self._get(self.edu, "overview", f"from={self.today}&to={m}")
        days = data.get("school_days", {})
        self.assertEqual(days[m]["type"], "school", (m, days.get(m)))
        self.assertIn("class", days[m]["detail"], days[m])
        # a Day Off overrides the timetable verdict
        st, data = self._api(self.edu, "mark_day_off", {"date": m, "reason": "SC link test", "cancel_makeup": "1"})
        self.assertEqual(st, 200, data)
        st, data = self._get(self.edu, "overview", f"from={self.today}&to={m}")
        self.assertEqual(data["school_days"][m]["type"], "off", data["school_days"][m])
        self.assertEqual(data["school_days"][m]["kind"], "day_off", data["school_days"][m])
        # weekday_class_counts reports the linked timetable
        self.assertGreaterEqual(int(data.get("weekday_class_counts", {}).get(str(meet_iso), 0)), 1, data)

    # ── decision 5: Education dept only writes ────────────────────────────
    def test_writes_are_education_only(self):
        st, data = self._api(self.teacher, "mark_day_off",
                             {"date": (date.fromisoformat(self.today) + timedelta(days=2)).isoformat(),
                              "reason": "teacher tries", "cancel_makeup": "1"})
        self.assertEqual(st, 403, data)
        st, data = self._api(self.teacher, "save_holiday",
                             {"title": "Nope", "date_from": self.today})
        self.assertEqual(st, 403, data)
        # reads stay open for teachers (attendance UIs need statuses)
        st, data = self._get(self.teacher, "date_status", f"class_id=1&date={self.today}")
        self.assertEqual(st, 200, data)


if __name__ == "__main__":
    unittest.main()
