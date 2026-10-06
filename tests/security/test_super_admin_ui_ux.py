"""Static regression pins for the Super Admin dashboard UI/UX fixes.

Audit performed against the AI Design OS Universal UI/UX Engineering
Constitution (v1.0) after two live-site screenshots reported visual issues.
The screenshots could not be viewed (no image capability in the working
session), so the audit was code-driven against the constitution; every pin
below guards a fix that was evidence-backed in the code itself.
"""

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
DASH = ROOT / "admin/dashboards/super-admin.php"
SECTIONS = ROOT / "admin/dashboards/sections"
CSS = ROOT / "admin/css/super_admin.css"
SA_JS = ROOT / "admin/js/super_admin.js"

# Every file whose text colors must stay WCAG-conformant on the dark chrome.
# #475569 on #0f172a is 2.3:1 and #64748b on #0a0f1a is 4.0:1 — both under
# the 4.5:1 floor for small text. #7c8aa5 is 5.1–5.5:1 on the same surfaces.
CONTRAST_FILES = [
    ROOT / "admin/dashboards/super-admin.php",
    SECTIONS / "app_release_section.php",
    SECTIONS / "app_telemetry_section.php",
    SECTIONS / "failure_intelligence_section.php",
    SECTIONS / "identity_codes_section.php",
    ROOT / "admin/js/failure_intelligence.js",
    ROOT / "admin/js/app_telemetry.js",
    ROOT / "admin/js/app_release.js",
    CSS,
]


class SuperAdminUiUxTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.dash = DASH.read_text(encoding="utf-8")
        cls.css = CSS.read_text(encoding="utf-8")
        cls.sa_js = SA_JS.read_text(encoding="utf-8")
        cls.fi_section = (SECTIONS / "failure_intelligence_section.php").read_text(
            encoding="utf-8"
        )
        cls.at_section = (SECTIONS / "app_telemetry_section.php").read_text(
            encoding="utf-8"
        )

    # ── UX-1: the Failure Intelligence section must own its styles ─────────

    def test_fi_section_has_its_own_scoped_styles(self):
        # The at-*/ar-* classes are scoped under #section-app_telemetry /
        # #section-app_release and never reached this section: it rendered
        # with unstyled KPI cards and browser-white selects on the dark
        # chrome. The section must define its own scoped block (sibling
        # idiom) — this was the primary visible breakage.
        for pin in (
            "#section-failure_intelligence .at-card",
            "#section-failure_intelligence .at-kpi-card",
            "#section-failure_intelligence .at-kpi-num",
            "#section-failure_intelligence .at-kpi-label",
            "#section-failure_intelligence .at-kpi-sub",
            "#section-failure_intelligence .ar-table",
            "#section-failure_intelligence .ar-select",
            "#section-failure_intelligence .ar-select option",
            "#section-failure_intelligence th",
            "#section-failure_intelligence td",
            "#section-failure_intelligence tr:hover td",
        ):
            self.assertIn(pin, self.fi_section, pin)
        # Dark select contract (no browser-white dropdowns on dark chrome).
        self.assertIn(
            "#section-failure_intelligence .ar-select {\n"
            "    box-sizing: border-box;\n"
            "    background-color: #0f172a !important;",
            self.fi_section,
        )
        # Visual parity with the Fleet Analytics section it sits beside.
        self.assertIn("background: #1e293b;", self.fi_section)
        self.assertIn("background: #131d31;", self.fi_section)

    def test_fi_section_styles_mirror_telemetry_values(self):
        # Same product, same analytics surfaces: the KPI card values must
        # not drift from the telemetry definitions.
        for value in (
            "font-size: 1.75rem",
            "border-radius: 0.65rem",
            "padding: 1rem 1.15rem",
        ):
            self.assertIn(value, self.fi_section, value)
            self.assertIn(value, self.at_section, value)

    def test_fi_section_respects_reduced_motion(self):
        self.assertIn("@media (prefers-reduced-motion: reduce)", self.fi_section)
        self.assertIn("transform: none;", self.fi_section)

    # ── UX-2: mobile navigation must reach every section ───────────────────

    def test_mobile_nav_reaches_all_sections(self):
        # The bottom nav is the only navigation on phones; the sidebar is
        # display:none under 768px. These three sections were unreachable
        # on mobile before the fix.
        for section in ("failure_intelligence", "app_telemetry", "identity"):
            self.assertIn(
                "'attrs' => 'data-section=\"%s\"'" % section, self.dash, section
            )
        # And the nav is rendered through the shared bottom_nav component.
        self.assertIn("components/bottom_nav.php", self.dash)

    # ── UX-3: no unused Tailwind Play CDN on this page ─────────────────────

    def test_no_tailwind_play_cdn(self):
        # The Play CDN is a runtime compiler (render-blocking, fights the
        # chrome CSS — the sa-section-lock block exists because of it). A
        # file-by-file audit found ZERO utility classes on this page or any
        # of its includes. Do not re-add without re-auditing.
        self.assertNotIn("cdn.tailwindcss.com", self.dash)
        self.assertIn("No Tailwind Play CDN", self.dash)
        # Preflight's image hygiene must survive without Tailwind.
        self.assertIn("img,video{max-width:100%;height:auto}", self.css)
        # The section lock stays as defense in depth for theme.php layering.
        self.assertIn("sa-section-lock", self.dash)
        self.assertIn("main.main .content > section.section.active", self.css)

    # ── UX-4: text contrast floors on the dark chrome ──────────────────────

    def test_no_subfloor_contrast_grays(self):
        for path in CONTRAST_FILES:
            text = path.read_text(encoding="utf-8")
            self.assertNotIn("#475569", text, str(path))
            self.assertNotIn("#64748b", text, str(path))

    def test_css_uses_the_passing_caption_gray(self):
        for pin in (
            ".nav-title{font-size:.6rem;text-transform:uppercase;letter-spacing:.1em;color:#7c8aa5",
            ".log-meta{font-size:.65rem;color:#7c8aa5",
            "color:#94a3b8;font-size:.7rem;cursor:pointer",  # pw-toggle label
        ):
            self.assertIn(pin, self.css, pin)

    # ── UX-5/6: reduced motion + visible keyboard focus ────────────────────

    def test_chrome_has_reduced_motion_and_focus_visible(self):
        self.assertIn("@media(prefers-reduced-motion:reduce)", self.css)
        self.assertIn(".status-dot{animation:none}", self.css)
        self.assertIn(".nav-link:focus-visible", self.css)
        self.assertIn(".wbws-bnav-btn:focus-visible", self.css)
        # Programmatic section focus must not draw a container outline.
        self.assertIn(".section:focus{outline:none}", self.css)

    # ── UX-7: section switching is announced and focus-managed ─────────────

    def test_section_switch_sets_aria_current_and_focus(self):
        self.assertIn("setAttribute('aria-current', 'page')", self.sa_js)
        self.assertIn("removeAttribute('aria-current')", self.sa_js)
        self.assertIn("focus({ preventScroll: true })", self.sa_js)
        # Focus lands on the section container, which is not a tab stop.
        self.assertIn("tabIndex = -1", self.sa_js)

    # ── UX-8: Amharic content is language-tagged ───────────────────────────

    def test_amharic_strings_carry_lang_attribute(self):
        self.assertIn('class="brand-sub eth" lang="am"', self.dash)
        self.assertIn('class="dept-amharic eth" lang="am"', self.dash)

    # ── Deploy hygiene: changed assets must bust cache ─────────────────────

    def test_changed_assets_have_fresh_versions(self):
        self.assertIn("/admin/css/super_admin.css?v=20261006", self.dash)
        self.assertIn("/admin/js/super_admin.js?v=20261006", self.dash)
        self.assertIn("/admin/js/failure_intelligence.js?v=20261006", self.dash)

    # ── UX-1 bug CLASS guard: scoped analytics classes must not leak ───────
    # The at-*/ar-* classes are scoped under #section-app_telemetry /
    # #section-app_release (and, since the fix, #section-failure_intelligence).
    # Any NEW consumer of these exact tokens outside the owners renders
    # unstyled — the exact bug the live screenshots reported. This pin fails
    # when a new consumer appears, forcing a conscious decision (own scoped
    # styles, like failure_intelligence_section.php, or an allowlist entry).

    OWNERS = {
        "admin/dashboards/sections/app_telemetry_section.php",
        "admin/dashboards/sections/app_release_section.php",
        "admin/dashboards/sections/failure_intelligence_section.php",
        "admin/js/app_telemetry.js",  # renders inside #section-app_telemetry
        "admin/js/app_release.js",    # renders inside #section-app_release
        "admin/js/failure_intelligence.js",  # inside #section-failure_intelligence
        "admin/js/academic_tracking.js",      # self-scoped: injects its own
        # '.at-card' rules under its own container id (verified: lines with
        # "#' + containerId + ' .at-card{"); unrelated name collision only.
    }
    SCOPED_TOKENS = (
        "at-card", "at-kpi-card", "at-kpi-num", "at-kpi-label", "at-kpi-sub",
        "at-kpi-grid", "at-bar-track", "at-bar-fill", "at-filter-btn",
        "at-table-wrap", "ar-select", "ar-input", "ar-table", "ar-form-group",
    )

    def test_scoped_analytics_classes_have_no_unowned_consumers(self):
        import re
        pattern = re.compile(
            r'class="[^"]*\b(?:%s)\b[^"]*"' % "|".join(self.SCOPED_TOKENS)
        )
        offenders = []
        for path in (ROOT / "admin").rglob("*"):
            if path.suffix not in (".php", ".js") or not path.is_file():
                continue
            rel = str(path.relative_to(ROOT))
            if rel in self.OWNERS or "backend/pdf/" in rel:
                continue
            try:
                text = path.read_text(encoding="utf-8", errors="replace")
            except OSError:
                continue
            if pattern.search(text):
                offenders.append(rel)
        self.assertEqual(
            offenders, [],
            "New consumer(s) of the scoped at-*/ar-* analytics classes found. "
            "These classes are styled ONLY under their owning section ids — "
            "using them elsewhere reproduces the unstyled-section bug "
            "(see failure_intelligence_section.php for the scoped-style fix).",
        )

    def test_academic_tracking_still_self_scopes_its_at_card(self):
        # Unrelated name collision: academic_tracking.js renders .at-card
        # buttons on edu_dept, but injects its own scoped rules under its
        # container id. If that injection is ever removed, its cards go
        # unstyled — pin the injection itself.
        js = (ROOT / "admin/js/academic_tracking.js").read_text(encoding="utf-8")
        self.assertIn(".at-card{cursor:pointer", js)


if __name__ == "__main__":
    unittest.main()
