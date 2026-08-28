"""Phase 8 — QR-scan attendance hardening contracts.

Pins the governed QR-roster endpoint, the QR carrier parity with the
ID card, and the mobile scan surface (all three departments, Amharic
feedback, instant autosave). See ANALYSIS/16.
"""

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
APP = ROOT / "Mobile" / "wbws_flutter_app"


def rd(p):
    return (ROOT / p).read_text(encoding="utf-8", errors="replace")


class QrRosterEndpointTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ep = rd("admin/api_qr_roster.php")
        cls.acl = rd("admin/access_control.php")

    def test_role_map_covers_departments(self):
        self.assertIn("'api_qr_roster.php' =>", self.acl)
        for role in ("edu_dept", "mezmur_dept", "hr_dept"):
            self.assertIn(role, self.acl.split("'api_qr_roster.php'")[1][:200])

    def test_governance_layer(self):
        self.assertIn("SecurityRateLimiter", self.ep)
        self.assertIn("consume('qr_roster_build'", self.ep)
        self.assertIn("SecurityAuditService::record", self.ep)
        self.assertIn("qr_roster_fail('Please sign in again.', 401)", self.ep)
        self.assertIn("permission to print this roster", self.ep)
        self.assertIn("bind_param", self.ep)

    def test_page_budget_and_payload(self):
        self.assertIn("QR_ROSTER_PAGE_SIZE = 200", self.ep)
        # same identifier payload as the ID card (never a credential)
        self.assertIn("'/member.php?code='", self.ep)
        idc = rd("admin/id_cards/generate_id_card.php")
        self.assertIn("'/member.php?code='", idc)

    def test_qr_engine_vendored(self):
        self.assertTrue(
            (ROOT / "admin/backend/pdf/tcpdf/tcpdf_barcodes_2d.php").is_file())
        self.assertIn("write2DBarcode", self.ep)


class MobileScanSurfaceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.edu = rd("Mobile/wbws_flutter_app/lib/screens/attendance/attendance_screen.dart")
        cls.mez = rd("Mobile/wbws_flutter_app/lib/screens/mezmur/mezmur_attendance.dart")
        cls.hr = rd("Mobile/wbws_flutter_app/lib/screens/hr/hr_attendance.dart")
        cls.svc = rd("Mobile/wbws_flutter_app/lib/services/qr_attendance.dart")
        cls.wid = rd("Mobile/wbws_flutter_app/lib/widgets/qr_scan_sheet.dart")

    def test_platform_permissions(self):
        self.assertIn("mobile_scanner", rd("Mobile/wbws_flutter_app/pubspec.yaml"))
        self.assertIn(
            "android.permission.CAMERA",
            rd("Mobile/wbws_flutter_app/android/app/src/main/AndroidManifest.xml"),
        )
        self.assertIn(
            "NSCameraUsageDescription",
            rd("Mobile/wbws_flutter_app/ios/Runner/Info.plist"),
        )

    def test_all_three_screens_have_scan_and_autosave(self):
        for src, save in (
            (self.edu, "saveAttendanceLocal"),
            (self.mez, "saveMezmurLocal"),
            (self.hr, "saveHrLocal"),
        ):
            self.assertIn("QrScanSheet.open", src)
            self.assertIn("_handleQrScan", src)
            self.assertIn("_autoSaveNow", src)
            self.assertIn("_scheduleAutoSave", src)
            self.assertIn("packetKind: 'draft'", src)
            self.assertIn(save, src)
            self.assertIn("FloatingActionButton.extended", src)

    def test_payload_parser_formats(self):
        self.assertIn("member.php", self.svc)
        self.assertIn("FKSS1:", self.svc)

    def test_amharic_feedback_is_big_and_complete(self):
        # titles ≥ 30sp / subs ≥ 17sp on the overlay
        self.assertIn("fontSize: 30", self.wid)
        self.assertIn("fontSize: 17", self.wid)
        for token in (
            "\u1270\u1218\u12dd\u130d\u1267\u120d",  # registered
            "\u1240\u12f5\u121e",                       # already (duplicate)
            "\u12e8\u1270\u1233\u1233\u1270 \u12ad\u134d\u120d",  # wrong class
            "\u12a0\u120d\u1270\u1308\u1298\u121d",  # not found
            "\u1295\u1241 \u12a0\u12ed\u12f0\u1208\u121d",  # not active
            "\u120d\u12ad \u12eb\u120d\u1206\u1290 \u12ae\u12f5",  # invalid
            "\u1270\u1246\u120d\u1313\u12a0\u120d",  # locked
        ):
            self.assertIn(token, self.svc, token)

    def test_offline_resolution_helpers(self):
        db = rd("Mobile/wbws_flutter_app/lib/services/local_db.dart")
        self.assertIn("findCachedMemberByCode", db)
        self.assertIn("cachedClassNameOfMember", db)


class ConsolePrintButtonTests(unittest.TestCase):
    def test_each_console_offers_qr_roster_print(self):
        edu = rd("admin/dashboards/edu_dept.php")
        hr = rd("admin/dashboards/hr-dept.php")
        mez = rd("frontend/pages/mezmur_dept.php")
        mezjs = rd("frontend/js/mezmur.js")
        self.assertIn("printQrRoster", edu)
        self.assertIn("api_qr_roster.php?dept=edu", edu)
        self.assertIn("printHrQrRoster", hr)
        self.assertIn("api_qr_roster.php?dept=hr", hr)
        self.assertIn("Mezmur.printQrRoster", mez)
        self.assertIn("api_qr_roster.php?dept=mezmur", mezjs)


if __name__ == "__main__":
    unittest.main()
