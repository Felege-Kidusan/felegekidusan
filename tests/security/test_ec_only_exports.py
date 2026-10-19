"""
Ethiopian-calendar-only guard for exports and remaining displays (2026-10-19).

Owner rule: the Ethiopian calendar is the ONLY calendar in user-facing output —
CSV / XLSX / PDF exports included. Storage stays Gregorian.

Static tests only (no server needed): they pin the exact call sites found in
the export/display sweep so a later edit cannot quietly reintroduce an ISO or
Gregorian string. Comment lines are stripped before matching.
"""
import os
import re
import unittest

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))


def read(rel):
    with open(os.path.join(ROOT, rel), encoding="utf-8") as fh:
        return fh.read()


def code_lines(text):
    out = []
    for line in text.splitlines():
        s = line.strip()
        if s.startswith("//") or s.startswith("*") or s.startswith("/*") or s.startswith("#") or s.startswith("--"):
            continue
        out.append(line)
    return "\n".join(out)


class EcOnlyHelpers(unittest.TestCase):
    def test_php_export_helpers_exist(self):
        src = read("admin/backend/ethiopian_date.php")
        for fn in ["function ethio_export_date(", "function ethio_export_datetime(", "function ethio_export_month("]:
            self.assertIn(fn, src)


class EcOnlyServerExports(unittest.TestCase):
    def test_reporting_centre_exports_convert_date_columns(self):
        src = code_lines(read("admin/backend/reporting/ReportExportService.php"))
        self.assertIn("if ($col['type'] === 'date')", src)
        self.assertIn("ethio_export_date", src)
        self.assertIn("ethiopian_date.php", src)

    def test_reporting_centre_month_bucket_is_ethiopian(self):
        src = code_lines(read("admin/backend/reporting/ReportRegistry.php"))
        self.assertNotIn("DATE_FORMAT(m.registered_at, '%Y-%m')", src)
        self.assertIn("sqlEcMonthBucket('m.registered_at')", src)
        self.assertIn("'Meskerem','Tikimt','Hidar','Tahsas','Tir','Yekatit','Megabit','Miyazya','Ginbot','Sene','Hamle','Nehasse','Pagume'", src)

    def test_info_pdf_reports_are_ethiopian(self):
        src = code_lines(read("admin/backend/services/PdfReportService.php"))
        self.assertNotIn("date('Y-m-d H:i')", src)
        self.assertNotIn("(string)$r['attendance_date'],", src)
        self.assertNotIn("$r['date'],\n", src)
        self.assertIn("ethio_export_date($kpi['from'])", src)
        self.assertIn("ethio_export_date($r['attendance_date'])", src)
        self.assertIn("ethio_export_datetime('now')", src)

    def test_excel_and_pdf_stamps_are_ethiopian(self):
        for rel in ["admin/backend/services/ReportCardService.php",
                    "admin/backend/services/EducationAnalyticsService.php",
                    "admin/api_qr_roster.php"]:
            src = code_lines(read(rel))
            self.assertNotIn("Exported: ' . date('Y-m-d H:i')", src, rel)
            self.assertNotIn("Generated ' . date('Y-m-d H:i')", src, rel)
            self.assertIn("ethio_export_datetime", src, rel)

    def test_executive_html_report_date_is_ethiopian(self):
        src = code_lines(read("admin/backend/services/EducationAnalyticsService.php"))
        self.assertNotIn("$today = date('F j, Y');", src)
        self.assertNotIn("$todayAm = date('Y-m-d');", src)


class EcOnlyBrowserExports(unittest.TestCase):
    def test_education_submissions_export(self):
        src = code_lines(read("admin/dashboards/edu_dept.php"))
        self.assertIn("s.attendance_date?fD(s.attendance_date)", src)
        self.assertIn("fDT(s.updated_at||s.submitted_at)", src)
        self.assertIn("map(d=>[fD(d.date)", src)
        self.assertIn("function fDT(d)", src)

    def test_mezmur_exports(self):
        src = code_lines(read("frontend/js/mezmur.js"))
        self.assertIn("p.attendance_date ? fmtDate(p.attendance_date, 'medium') : ''", src)
        self.assertIn("fmtDateTime(p.updated_at)", src)
        self.assertIn("m.last_attended ? fmtDate(m.last_attended, 'medium') : ''", src)
        self.assertNotIn("p.attendance_date || ''", src)
        self.assertNotIn("m.last_attended || ''", src)

    def test_school_admin_member_exports(self):
        src = code_lines(read("admin/dashboards/school_admin.php"))
        self.assertEqual(src.count("m.created_at?fmtDate(m.created_at):''"), 2)
        self.assertNotIn("m.work_profession,m.created_at]", src)

    def test_reporting_centre_grid_and_pickers(self):
        js = code_lines(read("admin/js/reports/reports_v2.js"))
        self.assertIn("if (fd.type === 'date') return esc(fmtDate(v));", js)
        self.assertIn("filter((i) => !(i.readOnly && i.dataset && i.dataset.for))", js)
        page = read("admin/reports_v2.php")
        self.assertIn("wbws_calendar_scripts", page)
        self.assertIn("calendar_system.php", page)


class EcOnlyRemainingDisplays(unittest.TestCase):
    def test_attendance_subtitles(self):
        for rel in ["admin/dashboards/teacher.php", "admin/dashboards/attendance_taker.php"]:
            src = code_lines(read(rel))
            self.assertNotIn("`Date: ${date} |", src, rel)
            self.assertIn("WBWSCalendar.formatDate(date,'long')", src, rel)

    def test_activity_feeds_and_lists(self):
        for rel in ["admin/dashboards/info-dept.php", "admin/dashboards/hr-dept.php"]:
            self.assertNotIn("new Date(a.created_at).toLocaleString()", code_lines(read(rel)), rel)
        self.assertNotIn("new Date(member.registered_at).toLocaleDateString()", code_lines(read("admin/js/new-members.js")))
        self.assertIn("WBWSCalendar.formatDateTime(d)", code_lines(read("admin/js/family_link.js")))
        self.assertNotIn("Date: ' + new Date().toLocaleString()", code_lines(read("admin/dashboards/ai_assistant.php")))
        self.assertIn("wbws_calendar_scripts", read("admin/dashboards/ai_assistant.php"))

    def test_super_admin_technical_timestamps(self):
        for rel in ["admin/js/failure_intelligence.js", "admin/js/app_telemetry.js"]:
            src = code_lines(read(rel))
            self.assertIn("function fmtTs(", src, rel)
            self.assertNotIn(".slice(0, 10))", src, rel)
            # every *_at value that is rendered goes through fmtTs
            raw = re.findall(r"escapeHtml\(([\w.]+_at)\)", src)
            self.assertEqual(raw, [], (rel, raw))

    def test_no_new_gregorian_locale_dates_without_ec_guard(self):
        """Any toLocaleDateString/toLocaleString on a date must sit under a WBWSCalendar guard."""
        offenders = []
        for base in ["admin/js", "admin/dashboards", "frontend/js"]:
            for dirpath, _, files in os.walk(os.path.join(ROOT, base)):
                if "vendor" in dirpath:
                    continue
                for fn in files:
                    if not fn.endswith((".js", ".php")):
                        continue
                    rel = os.path.relpath(os.path.join(dirpath, fn), ROOT)
                    lines = read(rel).splitlines()
                    for i, line in enumerate(lines):
                        if "toLocaleDateString(" not in line and "Date(" not in line:
                            continue
                        if "toLocaleDateString(" in line or re.search(r"new Date\([^)]*\)\.toLocaleString\(", line):
                            window = "\n".join(lines[max(0, i - 6):i + 1])
                            if "WBWSCalendar" not in window and "wbws-calendar.js" not in rel and "report-format.js" not in rel:
                                offenders.append(f"{rel}:{i + 1}")
        self.assertEqual(offenders, [], offenders)


if __name__ == "__main__":
    unittest.main()
