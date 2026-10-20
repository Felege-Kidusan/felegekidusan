"""
Timetable T-3 — teacher mobile timetable (read-only) guards.

Static part always runs (route registration, dependency guards, Flutter
screen contract: Weekly/Daily/Monthly, Today, Now highlight, EC-only dates,
per-user offline cache wiped on sign-out, FKSS palette, release pin).

Live part needs the local PHP test server + seeded `ssms_test` with sql/077
applied; it skips cleanly otherwise (and when earlier suites used up the
mobile login rate limit).
"""
from __future__ import annotations

import re
import sys
import unittest
import urllib.error
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(Path(__file__).resolve().parent))
from test_attendance_phase1 import (  # noqa: E402
    USERS, db, mobile, mobile_token, _live_ready,
)

FLUTTER = ROOT / "Mobile/wbws_flutter_app"
ROUTE = ROOT / "api/v1/routes/timetable.php"
SCREEN = FLUTTER / "lib/screens/teacher/teacher_timetable_screen.dart"
CACHE = FLUTTER / "lib/services/timetable_cache.dart"


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8")


def _token(role):
    try:
        return mobile_token(role)
    except urllib.error.HTTPError as e:
        if e.code == 429:
            raise unittest.SkipTest("mobile login rate-limited by earlier suites — rerun later")
        raise


class TimetableMobileStaticGuards(unittest.TestCase):
    def test_route_registered_and_guarded(self):
        index = read(ROOT / "api/v1/index.php")
        self.assertRegex(index, r"'timetable'\s*=>\s*'timetable\.php'")
        src = read(ROUTE)
        self.assertIn("TimetableService.php", src)
        self.assertIn("class_exists('\\\\App\\\\Services\\\\TimetableService')", src)
        self.assertIn("apiRequireAuth()", src)
        self.assertIn("isApiRateLimited('timetable'", src)
        # read-only resource
        self.assertIn("$method !== 'GET'", src)
        self.assertIn("405", src)
        # teacher forced onto own id; only Education roles may pass teacher_id
        self.assertIn("apiRoleIs($auth, apiRolesEducation()) && !empty($_GET['teacher_id'])", src)
        self.assertNotIn("getMessage(", src)
        # EC labels ride along with the stored Gregorian strings
        self.assertIn("today_date_ec", src)
        self.assertIn("lesson_date_ec", src)
        self.assertIn("ethio_date_format", src)

    def test_api_service_has_timetable_call(self):
        api = read(FLUTTER / "lib/services/api_service.dart")
        self.assertIn("getMyTimetableWeek() => get('/timetable/week')", api)

    def test_screen_contract_matches_eduid_layout(self):
        s = read(SCREEN)
        # three views + Today + Back to Today + Now highlight + day strip dots
        self.assertIn("enum _View { weekly, daily, monthly }", s)
        for label in ("'Weekly'", "'Daily'", "'Monthly'", "'Today'", "'Back to Today'", "_pill('Now'"):
            self.assertIn(label, s, label)
        self.assertIn("_weekDayCell(", s)
        self.assertIn("_monthCell(", s)
        self.assertIn("_isNow(", s)
        # makeup classes shown with their own badge
        self.assertIn("_pill('Makeup'", s)
        self.assertIn("makeup_classes", s)
        # empty / offline states
        self.assertIn("Can't load your timetable", s)
        self.assertIn("No timetable yet", s)
        self.assertIn("Offline — showing the timetable last saved on this phone", s)
        # read-only: no POST/PUT/DELETE from this screen
        self.assertNotIn(".post(", s)
        self.assertNotIn(".delete(", s)

    def test_screen_is_ethiopian_calendar_only(self):
        s = read(SCREEN)
        self.assertNotIn("package:intl", s)
        self.assertNotIn("DateFormat(", s)
        self.assertIn("EthiopianDate.fromGregorian", s)
        self.assertIn("monthNameAmharic(", s)
        self.assertIn("daysInEthiopianMonth(", s)
        self.assertIn("ethiopianToGregorian(", s)
        # month grid navigates across the 13 Ethiopian months
        self.assertIn("m = 13;", s)
        self.assertIn("if (m < 1)", s)
        # No Gregorian month names anywhere in user-facing strings
        for g in ("January", "February", "October", "December", "'Jan'", "'Oct'"):
            self.assertNotIn(g, s, g)

    def test_screen_uses_fkss_palette_not_eduid_colors(self):
        s = read(SCREEN)
        self.assertNotIn("AppColors.", s)
        self.assertIn("AppTheme.primary", s)
        self.assertIn("AppTheme.accent", s)
        theme = read(FLUTTER / "lib/utils/theme.dart")
        self.assertIn("Color(0xFF5A1212)", theme)
        self.assertIn("Color(0xFFD4AF37)", theme)

    def test_offline_cache_is_per_user_and_wiped_on_sign_out(self):
        c = read(CACHE)
        self.assertIn("SharedPreferences.getInstance()", c)
        self.assertIn("if (decoded['owner'] != userId) return null;", c)
        self.assertIn("Future<void> clearAll()", c)
        sess = read(FLUTTER / "lib/services/session_service.dart")
        self.assertIn("import 'timetable_cache.dart';", sess)
        self.assertEqual(sess.count("await TimetableCache.instance.clearAll();"), 2)
        # no sqflite schema change was needed for this cache
        self.assertNotIn("timetable", read(FLUTTER / "lib/services/local_db.dart").lower())

    def test_teacher_home_links_to_timetable(self):
        home = read(FLUTTER / "lib/screens/teacher/teacher_home.dart")
        self.assertIn("import 'teacher_timetable_screen.dart';", home)
        self.assertIn("_buildTimetableCard()", home)
        self.assertIn("const TeacherTimetableScreen()", home)

    def test_release_pin_moved_together(self):
        pubspec = read(FLUTTER / "pubspec.yaml")
        config = read(FLUTTER / "lib/utils/config.dart")
        notes = read(FLUTTER / "RELEASE_NOTES.md")
        rel = read(ROOT / "api/v1/core/app_release.php")
        self.assertRegex(pubspec, r"(?m)^version:\s*1\.7\.2\+39\s*$")
        self.assertIn("appVersion = '1.7.2'", config)
        self.assertIn("appBuild = 39", config)
        self.assertIn("## 1.7.2 (build 39)", notes)
        self.assertIn("My Timetable", notes)
        self.assertRegex(rel, r"'latest_version'\s*=>\s*'1\.7\.2'")
        self.assertRegex(rel, r"'latest_build'\s*=>\s*39")
        # pubspec untouched apart from the version line (no new packages)
        self.assertNotIn("calendar_view", pubspec)
        self.assertNotIn("table_calendar", pubspec)


@unittest.skipUnless(_live_ready(), "live test server / seeded DB not available")
class TimetableMobileLive(unittest.TestCase):
    """Seeds class 1 (Sat/Sun bell + two lessons + one makeup today) for
    teacher audit_teach (id 3) and reads it back through /api/v1/timetable."""

    @classmethod
    def setUpClass(cls):
        cls.tearDownClass()
        db(
            "INSERT INTO timetable_class_days (class_id,academic_year_id,working_days) VALUES (1,1,'6,7');"
            "INSERT INTO timetable_periods (class_id,academic_year_id,label,start_time,end_time,is_break,sort_order)"
            " VALUES (1,1,'TT-P1','08:00','08:45',0,9901),(1,1,'TT-Break','08:45','09:00',1,9902),(1,1,'TT-P2','09:00','09:45',0,9903);"
            "SET @p1=(SELECT id FROM timetable_periods WHERE sort_order=9901 AND class_id=1);"
            "SET @p2=(SELECT id FROM timetable_periods WHERE sort_order=9903 AND class_id=1);"
            "INSERT INTO timetable_entries (class_id,academic_year_id,period_id,day_of_week,subject_id,teacher_id,room)"
            " VALUES (1,1,@p1,6,1,3,'Room 1'),(1,1,@p2,7,2,3,'Hall');"
            "INSERT INTO timetable_makeup_classes (class_id,academic_year_id,lesson_date,start_time,end_time,subject_id,teacher_id,reason)"
            " VALUES (1,1,CURDATE(),'14:00','14:45',1,3,'tt-mobile-test');"
        )
        cls.teacher = _token("teacher")

    @classmethod
    def tearDownClass(cls):
        db(
            "DELETE FROM timetable_makeup_classes WHERE reason='tt-mobile-test';"
            "DELETE FROM timetable_entries WHERE class_id=1 AND period_id IN (SELECT id FROM timetable_periods WHERE sort_order IN (9901,9903));"
            "DELETE FROM timetable_periods WHERE sort_order IN (9901,9902,9903);"
            "DELETE FROM timetable_class_days WHERE class_id=1 AND working_days='6,7';"
        )

    def test_week_returns_own_lessons_keyed_by_iso_weekday_with_ec_labels(self):
        code, body = mobile(self.teacher, "GET", "/api/v1/timetable/week")
        self.assertEqual(code, 200, body)
        d = body["data"]
        self.assertEqual(d["teacher_id"], 3)
        self.assertIn("6", d["days"])
        self.assertIn("7", d["days"])
        sat = d["days"]["6"][0]
        self.assertEqual(sat["start_time"], "08:00")
        self.assertEqual(sat["room"], "Room 1")
        self.assertEqual(sat["subject_name_en"], "Holy Bible")
        self.assertEqual(sorted(d["working_days"]), [6, 7])
        # no break rows leak into a teacher's list
        self.assertFalse(any(l["period_label"] == "TT-Break" for l in d["lessons"]))
        # EC labels present, in English month names, 2019 EC for Oct 2026
        self.assertRegex(d["today_date_ec"], r"^[A-Z][a-z]+day, [A-Za-z]+ \d{1,2}, \d{4}$")
        mk = [m for m in d["makeup_classes"] if m["reason"] == "tt-mobile-test"]
        self.assertEqual(len(mk), 1)
        self.assertEqual(mk[0]["lesson_date"], d["today_date"])
        self.assertEqual(mk[0]["lesson_date_ec"], d["today_date_ec"])

    def test_today_filters_to_today(self):
        code, body = mobile(self.teacher, "GET", "/api/v1/timetable/today")
        self.assertEqual(code, 200, body)
        d = body["data"]
        self.assertNotIn("days", d)
        for l in d["lessons"]:
            self.assertEqual(l["day_of_week"], d["today_iso"])
        for m in d["makeup_classes"]:
            self.assertEqual(m["lesson_date"], d["today_date"])

    def test_teacher_cannot_read_another_teachers_schedule(self):
        code, body = mobile(self.teacher, "GET", "/api/v1/timetable/week?teacher_id=1")
        self.assertEqual(code, 200, body)
        self.assertEqual(body["data"]["teacher_id"], 3)

    def test_write_methods_and_unknown_sub_rejected(self):
        code, _ = mobile(self.teacher, "POST", "/api/v1/timetable/week", {"x": 1})
        self.assertEqual(code, 405)
        code, _ = mobile(self.teacher, "GET", "/api/v1/timetable/nope")
        self.assertEqual(code, 404)

    def test_non_education_roles_are_refused(self):
        tok = _token("hr_dept")
        code, _ = mobile(tok, "GET", "/api/v1/timetable/week")
        self.assertEqual(code, 403)


if __name__ == "__main__":
    unittest.main()
