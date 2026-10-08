"""
Academic Year admin fixes (1.6.3+29) — modal stacking, date integrity,
semester ownership
═════════════════════════════════════════════════════════════════════════════
Observed live (screenshot, 2026-10-08): on /admin/dashboard.php the "Add
Year" modal was open, but the page's sidebar, topbar and years table painted
at full brightness ABOVE the modal and the Save / date controls could not be
clicked. Root cause: .mo{z-index:var(--z-overlay)} evaluated to z-index:auto
in the user's browser (cached pre-2026-09-09 mobile.css without the
design-system @import), which drops the overlay into paint layer 6 while
aside{z-index:20} and main{position:relative;z-index:1} paint in layer 7 —
over the modal, intercepting every pointer event over it.

The fix has three legs; these tests pin all of them:

  • every dashboard that uses var(--z-*) in its own markup now carries an
    inline :root fallback with the canonical scale (values identical to
    themes/design-system.css) — modals stack correctly even with a stale
    or failed shared stylesheet
  • every /admin/css/mobile.css link is cache-busted with ?v=SSMS_ASSET_VER
    (defined in config.php) so a release always invalidates cached CSS
  • mobile.css treats .mo > .md as a bottom sheet on phones (previously the
    .md dialog class was not in the mobile dialog selector list)

Plus the product changes:
  • semester management moves to the Education Department (year lifecycle
    stays School-Admin-only) — api_education.php TIER 1 split
  • year/semester dates: optional but validated (ISO, end>=start), semester
    dates contained in the year's dates, non-blocking overlap warning
  • set_current_term: scoped to the ACTIVE year, refused while time-travel
  • school_admin UI: MoE-pattern date prefill via the real EC->GC converter,
    year-end rollover reminder, "dates suggest now" current-semester hint
  • edu_dept UI: semester management unlocked (previously view-only)
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ADMIN = ROOT / "admin"


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


class ZScaleFallbackPinned(unittest.TestCase):
    """Modal stacking must not depend on the shared stylesheet."""

    FALLBACK_FILES = [
        "admin/dashboards/school_admin.php",
        "admin/dashboards/finance_department.php",
        "admin/dashboards/material_department.php",
        "admin/dashboards/groups.php",
        "admin/dashboards/content_editor.php",
        "admin/dashboards/ai_chatbot_widget.php",
        "admin/info_manage_member.php",
    ]
    CANONICAL = ":root{--z-content:1;--z-sticky:100;--z-header:200;--z-nav:900;--z-dock:950;--z-fab:1000;--z-toast:1100;--z-overlay:1200;--z-impersonate:1300;--z-tooltip:1400}"

    def test_every_z_var_consumer_has_the_inline_fallback(self):
        for rel in self.FALLBACK_FILES:
            self.assertIn(self.CANONICAL, read(rel), rel)

    def test_fallback_values_match_the_canonical_design_system(self):
        import re
        ds = re.sub(r"[ \t]+", " ", read("themes/design-system.css"))
        for token, value in [
            ("--z-overlay", "1200"),
            ("--z-impersonate", "1300"),
            ("--z-nav", "900"),
            ("--z-toast", "1100"),
        ]:
            self.assertIn(f"{token}: {value};", ds)

    def test_impersonate_bar_no_longer_depends_on_the_token(self):
        # admin/dashboard.php injects the bar via register_shutdown_function;
        # it must not rely on a token defined in the (possibly stale) head.
        self.assertNotIn("var(--z-impersonate)", read("admin/dashboard.php"))
        self.assertIn("z-index:1300", read("admin/dashboard.php"))


class AssetCacheBustingPinned(unittest.TestCase):
    """Fresh HTML must never be paired with a months-old cached mobile.css."""

    def test_asset_version_constant_exists(self):
        cfg = read("config.php")
        self.assertIn("define('SSMS_ASSET_VER', '20261008')", cfg)

    def test_every_mobile_css_link_is_versioned(self):
        offenders = []
        for p in ADMIN.rglob("*.php"):
            text = p.read_text(encoding="utf-8", errors="replace")
            if 'href="/admin/css/mobile.css"' in text:
                offenders.append(str(p.relative_to(ROOT)))
        self.assertEqual(offenders, [], f"unversioned mobile.css links: {offenders}")

    def test_school_admin_busts_its_stylesheet(self):
        self.assertIn(
            'href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>"',
            read("admin/dashboards/school_admin.php"),
        )


class MobileSheetCoversMdDialogs(unittest.TestCase):
    def test_md_dialog_is_a_mobile_bottom_sheet(self):
        css = read("admin/css/mobile.css")
        self.assertIn(".mo > .md", css)

    def test_design_system_import_is_still_first(self):
        self.assertTrue(
            read("admin/css/mobile.css").lstrip().startswith('@import url("../../themes/design-system.css")')
        )


class SemesterOwnershipSplitPinned(unittest.TestCase):
    """Year = School Admin; semesters = Education Dept (+ admins)."""

    def setUp(self):
        self.api = read("admin/api_education.php")

    def test_year_lifecycle_actions_are_split_out_and_admin_only(self):
        self.assertIn(
            "$__yearLifecycleActions = ['save_academic_year', 'set_current_year', 'reopen_year', 'delete_year']",
            self.api,
        )
        # the year gate must NOT include edu_dept
        seg = self.api.split("$__yearLifecycleActions = ['save_academic_year', 'set_current_year', 'reopen_year', 'delete_year']")[1][:700]
        self.assertIn("super_admin', 'school_admin'", seg)
        self.assertNotIn("edu_dept", seg)

    def test_term_actions_allow_education_dept(self):
        # 1.6.5: the array grew reopen_term / close_term_reopen (same tier).
        self.assertIn(
            "$__termActions = ['save_term', 'delete_term', 'set_current_term', 'reopen_term', 'close_term_reopen']",
            self.api,
        )
        seg = self.api.split("'reopen_term', 'close_term_reopen']")[1][:700]
        self.assertIn("'edu_dept'", seg)

    def test_edu_dept_ui_manages_semesters(self):
        edu = read("admin/dashboards/edu_dept.php")
        self.assertIn("openTermModal(${parseInt(yearId)},null)", edu)
        self.assertIn("doSetCurrentTerm(${parseInt(t.id)})", edu)
        self.assertIn("doDeleteTerm(${parseInt(t.id)})", edu)
        self.assertNotIn("View only — managed by School Admin", edu)

    def test_edu_dept_header_states_the_ownership_split(self):
        edu = read("admin/dashboards/edu_dept.php")
        self.assertIn("Semesters: Education Dept", edu)


class DateIntegrityPinned(unittest.TestCase):
    def setUp(self):
        self.api = read("admin/api_education.php")

    def test_date_helpers_exist(self):
        self.assertIn("function _ay_valid_date_str", self.api)
        self.assertIn("function _ay_check_date_pair", self.api)

    def test_year_save_validates_and_reports_overlap(self):
        self.assertIn(
            "$dateErr = _ay_check_date_pair($start, $end, 'Academic year')",
            self.api,
        )
        self.assertIn("'Dates overlap with '", self.api)

    def test_term_save_validates_and_contains_within_year(self):
        self.assertIn(
            "$dateErr = _ay_check_date_pair($tstart, $tend, 'Semester')",
            self.api,
        )
        self.assertIn("must fall within the year", self.api)

    def test_current_term_is_scoped_to_the_active_year(self):
        self.assertIn(
            "SET is_current=0 WHERE academic_year_id=",
            self.api,
        )
        self.assertIn(
            "Only a semester of the ACTIVE academic year can be set as the current one.",
            self.api,
        )
        seg = self.api.split("case 'set_current_term'")[1][:900]
        self.assertIn("ay_block_if_readonly", seg)


class SchoolAdminUiPinned(unittest.TestCase):
    def setUp(self):
        self.ui = read("admin/dashboards/school_admin.php")

    def test_date_prefill_uses_the_real_converter(self):
        self.assertIn("ethiopian_to_gregorian($__ey, 1, 16)", self.ui)
        self.assertIn("ethiopian_to_gregorian($__ey, 12, 30)", self.ui)
        self.assertIn("const EC_YEAR_DEFAULTS=", self.ui)
        self.assertIn("window.EC_YEAR_DEFAULTS?.[String(ecYear)]", self.ui)

    def test_dead_is_current_control_is_gone(self):
        self.assertNotIn('id="yearCurrent"', self.ui)
        self.assertNotIn("yearCurrent').checked", self.ui)

    def test_year_end_rollover_reminder_exists(self):
        self.assertIn("function updateRolloverHint(", self.ui)
        self.assertIn('id="rolloverHint"', self.ui)

    def test_current_semester_date_hint_exists(self):
        self.assertIn("dates suggest now", self.ui)

    def test_save_year_surfaces_overlap_warnings(self):
        self.assertIn("(d.warnings||[]).forEach(w=>toast(w,'e'))", self.ui)


if __name__ == "__main__":
    unittest.main()
