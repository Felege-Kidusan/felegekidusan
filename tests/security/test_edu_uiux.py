"""
Education dashboard UI/UX pass (P70) — regression & quality gates
════════════════════════════════════════════════════════════════
Pins the contracts of admin/dashboards/edu_dept.php's native mobile
upgrade without executing PHP:
  • token bridge — the page re-declares the design-system elevation /
    nav / safe-area tokens it depends on (root cause of the reported
    "modals overlapped by the bottom nav" defect)
  • bottom sheets on phones for every modal, above the bottom nav
  • card-row transform for all data tables with per-column labels that
    cannot drift from the rendered <thead> column sets
  • screen-only media guards (report-card printing untouched)
  • scope discipline — every rule is body.page-edu scoped; no shared
    file (mobile.css, bottom_nav.php, theme.php) carries edu styles
  • behaviour pins — modal ids, nav sections, shared component require,
    scroll reset; JS DOM targets the page relies on stay present
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class EduUiUxTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.page = (ROOT / "admin/dashboards/edu_dept.php").read_text(encoding="utf-8")
        m = re.search(r'<style id="p70-edu-mobile">(.*?)</style>', cls.page, re.S)
        assert m, "P70 style block missing from edu_dept.php"
        cls.css = m.group(1)
        cls.css_nc = re.sub(r'/\*.*?\*/', '', cls.css, flags=re.S)  # comments stripped
        cls.design = (ROOT / "themes/design-system.css").read_text(encoding="utf-8")
        cls.mobile_css = (ROOT / "admin/css/mobile.css").read_text(encoding="utf-8")
        cls.bnav = (ROOT / "admin/components/bottom_nav.php").read_text(encoding="utf-8")
        cls.theme = (ROOT / "admin/theme.php").read_text(encoding="utf-8")

    # ── 1. token bridge (root-cause fix) ───────────────────────────
    def test_token_bridge_mirrors_design_system(self):
        """Every token the shared mobile system needs is re-declared with the
        exact value from themes/design-system.css."""
        tokens = [
            "--nav-h", "--nav-safe-bottom", "--nav-total",
            "--safe-top", "--safe-left", "--safe-right",
            "--z-content", "--z-sticky", "--z-header", "--z-nav", "--z-dock",
            "--z-fab", "--z-toast", "--z-overlay", "--z-impersonate",
            "--z-tooltip", "--space-2", "--space-3", "--space-4",
        ]
        root_m = re.search(r':root\s*\{(.*?)\}', self.css_nc, re.S)
        assert root_m, "P70 :root block missing"
        page_root = root_m.group(1)
        for tok in tokens:
            self.assertIn(tok + ":", page_root, f"token {tok} missing from P70 bridge")
            # exact value parity with the single source of truth
            src = re.search(re.escape(tok) + r'\s*:\s*([^;]+);', self.design)
            dst = re.search(re.escape(tok) + r'\s*:\s*([^;]+);', page_root)
            self.assertIsNotNone(src, f"{tok} vanished from design-system.css?")
            self.assertEqual(src.group(1).strip(), dst.group(1).strip(),
                             f"{tok} value drifted from design-system.css")

    def test_elevation_order_keeps_nav_below_overlays(self):
        def val(name):
            return int(re.search(re.escape(name) + r'\s*:\s*(\d+)', self.css_nc).group(1))
        self.assertLess(val("--z-header"), val("--z-nav"))
        self.assertLess(val("--z-nav"), val("--z-toast"))
        self.assertLess(val("--z-toast"), val("--z-overlay"))
        self.assertLess(val("--z-overlay"), val("--z-impersonate"))

    def test_modal_rule_uses_overlay_token(self):
        self.assertRegex(self.page, r'\.mo\{[^}]*z-index:var\(--z-overlay\)')

    # ── 2. scope discipline ────────────────────────────────────────
    def test_body_carries_page_class(self):
        self.assertIn('<body class="page-edu">', self.page)

    def test_p70_rules_are_page_scoped(self):
        """Every selector in the P70 block is body.page-edu scoped (or :root /
        @keyframes / @media wrappers / keyframe stops)."""
        for sel in re.findall(r'([^{}]+)\{', self.css_nc):
            s = sel.strip()
            if s.startswith(('@media', '@keyframes', 'from', 'to', ':root')):
                continue
            self.assertIn('body.page-edu', s,
                          f"unscoped P70 selector: {s[:80]}")

    def test_shared_files_carry_no_edu_styles(self):
        for name, text in [("mobile.css", self.mobile_css),
                           ("bottom_nav.php", self.bnav),
                           ("theme.php", self.theme)]:
            self.assertNotIn("page-edu", text, f"{name} must stay edu-free")
            self.assertNotIn("p70", text.lower(), f"{name} must stay edu-free")

    # ── helpers ────────────────────────────────────────────────────
    @classmethod
    def rules(cls):
        """[(selector, declarations)] with whitespace normalised."""
        out = []
        for sel, body in re.findall(r'([^{}]+)\{([^{}]*)\}', cls.css_nc):
            out.append((' '.join(sel.split()), ' '.join(body.split())))
        return out

    @classmethod
    def decls_for(cls, sel):
        """All declaration blocks ever written for a selector (base + overrides)."""
        return [d for s, d in cls.rules() if s == sel]

    @classmethod
    def label_map(cls):
        """selector → ::before content text."""
        out = {}
        for sel, decls in cls.rules():
            m = re.search(r"content:\s*'([^']*)'", decls)
            if m and '::before' in sel:
                out[sel] = m.group(1)
        return out

    @staticmethod
    def thead_before(page, tbody_id):
        """<th> texts of the thead immediately preceding a tbody id."""
        i = page.index('<tbody id="%s">' % tbody_id)
        seg = page[page.rfind('<thead>', 0, i):i]
        m = re.search(r'<thead><tr>(.*?)</tr></thead>', seg, re.S)
        assert m, f"no thead found before #{tbody_id}"
        return re.findall(r'<th[^>]*>(.*?)</th>', m.group(1), re.S)

    # ── 3. bottom sheets on phones ─────────────────────────────────
    def test_sheets_contract(self):
        rules = dict(self.rules())
        self.assertIn('align-items: flex-end; padding: 0 !important',
                      rules.get('body.page-edu .mo', ''))
        mc = rules.get('body.page-edu .mo .mc', '')
        for frag in ['max-width: 100% !important', 'border-radius: 22px 22px 0 0',
                     'max-height: min(88dvh,88vh)', 'animation: eduSheetUp']:
            self.assertIn(frag.replace(', ', ',').replace(' ', ''), mc.replace(', ', ',').replace(' ', ''),
                          f"sheet rule missing {frag!r}")
        self.assertIn('@keyframes eduSheetUp', self.css_nc)

    def test_sheet_grabber_is_pure_css(self):
        rules = dict(self.rules())
        self.assertIn('content:', rules.get('body.page-edu .mo .mc::before', ''),
                      "CSS grabber on .mc::before missing")

    def test_rc_modal_preview_keeps_own_scroll(self):
        rules = dict(self.rules())
        self.assertIn('overflow-y: auto', rules.get('body.page-edu #rcModalBody', ''))

    def test_all_static_modals_exist(self):
        for mid in ["reviewModal", "rcModal", "teacherModal", "viewTeacherModal",
                    "subjectModal", "classModal", "assessmentModal", "yearModal",
                    "bulkEnrollModal", "transferModal", "termModal"]:
            self.assertIn(mid, self.page, f"modal {mid} vanished")

    # ── 4. card tables on phones ───────────────────────────────────
    def test_card_transform_contract(self):
        rules = dict(self.rules())
        self.assertIn('display: none', rules.get('body.page-edu .tw .dt thead', ''))
        self.assertIn('display: flex', rules.get('body.page-edu .tw .dt tbody', ''))
        td = ' '.join(self.decls_for('body.page-edu .tw .dt td'))
        self.assertIn('display: block', td)
        self.assertIn('white-space: normal', td)
        self.assertIn('body.page-edu .tw .dt td[colspan]', rules)  # empty/loading rows
        self.assertIn('width: 100% !important',
                      rules.get('body.page-edu .tw .dt td .inp', ''))

    def test_card_labels_match_table_columns(self):
        """Per-table label rules mirror each rendered <thead>. Any change to a
        template's column set must update the CSS labels (and vice versa)."""
        expected = {
            "#teacherBody": {2: "Username", 3: "Email", 4: "Member Link",
                             5: "Assignments", 6: "Status"},
            "#classBody": {3: "Name (English)", 4: "Code", 5: "Section",
                           6: "Age Group", 7: "Students", 8: "Status"},
            "#sec-subjects .dt": {2: "Subject (English)", 3: "Code", 4: "Classes"},
            "#yearBody": {2: "EC Year", 3: "GC Year", 4: "Start", 5: "End",
                          6: "Semesters", 7: "Current"},
            "#rcTableBody": {3: "Code", 4: "Obtained", 5: "Average",
                             6: "Grade", 7: "Attendance"},
            "#enrollArea .dt": {3: "Code", 4: "Type", 5: "Gender",
                                6: "Age", 7: "Enrolled"},
            "#rosterArea .dt": {3: "Code", 4: "Class", 5: "Type",
                                6: "Gender", 7: "Age"},
            "#unassignedArea .dt": {3: "Code", 4: "Type", 5: "Gender",
                                    6: "Age Group", 7: "Phone"},
            "#gradeArea .dt": {3: "Code", 4: "Score", 5: "Remark"},
            "#assessmentList .dt": {2: "Max Score", 3: "Weight"},
            "#submissionsList .dt": {3: "Class", 4: "What", 5: "Students",
                                     6: "Result", 7: "Status", 8: "Updated"},
            "#subInsights .dt": {2: "Marked", 3: "Present", 4: "Absent",
                                 5: "Late", 6: "Rate"},
        }
        labels = self.label_map()
        for scope, cols in expected.items():
            for idx, text in cols.items():
                sel = f"body.page-edu {scope} td:nth-child({idx})::before"
                self.assertEqual(labels.get(sel), text,
                                 f"label drift at {sel}: expected {text!r}")

    def test_table_column_sets_unchanged(self):
        """Pin every static thead so a column change cannot silently
        desynchronise the card labels above."""
        heads = {
            "teacherBody": ["Teacher", "Username", "Email", "Member Link",
                            "Assignments", "Status", "Actions"],
            "classBody": ["Order", "Name (Amharic)", "Name (English)", "Code",
                          "Section", "Age Group", "Students", "Status", "Actions"],
            "yearBody": ["Year Name", "EC Year", "GC Year", "Start", "End",
                         "Semesters", "Current", "Actions"],
            "rcTableBody": ["Rank", "Student", "Code", "Obtained", "Average",
                            "Grade", "Attendance", "Actions"],
        }
        for tbody, texts in heads.items():
            got = [t.strip() for t in self.thead_before(self.page, tbody)]
            self.assertEqual(got, texts, f"#{tbody} column set changed — update card labels")

    def test_data_last_tables_neutralise_action_row(self):
        """Roster/unassigned/insights/grade/review tables end on a data cell,
        not an Actions cell — the generic last-child action-row styling must
        be neutralised for them."""
        rules = dict(self.rules())
        for scope in ["#rosterArea", "#unassignedArea", "#subInsights",
                      "#gradeArea", "#reviewModalContent"]:
            decls = rules.get(f"body.page-edu {scope} .dt td:last-child", '')
            self.assertIn('display: block', decls,
                          f"{scope} last-child data cell still styled as action row")

    # ── 5. media guards & motion ───────────────────────────────────
    def test_phone_rules_are_screen_guarded(self):
        """Card/sheet rules must not leak into @media print (report cards
        print the desktop table layout)."""
        self.assertIn("@media screen and (max-width: 768px)", self.css_nc)
        self.assertIn("@media screen and (max-width: 380px)", self.css_nc)
        self.assertIn("@media screen and (min-width: 769px) and (max-width: 1024px)",
                      self.css_nc)
        # no unguarded max-width query in the P70 block
        self.assertNotRegex(self.css_nc, r'@media\s*\(\s*max-width')

    def test_reduced_motion_guard(self):
        self.assertIn("@media (prefers-reduced-motion: reduce)", self.css_nc)

    def test_ios_input_zoom_guard(self):
        rules = dict(self.rules())
        self.assertIn('font-size: 16px', rules.get(
            'body.page-edu .inp, body.page-edu select.inp, body.page-edu textarea.inp', ''))

    def test_touch_targets(self):
        rules = dict(self.rules())
        self.assertIn('width: 40px', rules.get('body.page-edu .ab', ''))
        self.assertIn('height: 40px', rules.get('body.page-edu .ab', ''))
        self.assertIn('min-height: 42px', rules.get('body.page-edu .btn', ''))

    def test_toast_clears_nav(self):
        self.assertIn("calc(var(--nav-total) + .75rem)", self.css_nc)

    # ── 6. behaviour pins (logic untouched) ────────────────────────
    def test_shared_bottom_nav_component_still_required(self):
        self.assertIn("require __DIR__ . '/../components/bottom_nav.php'", self.page)
        self.assertIn("$navItems", self.page)

    def test_all_ten_sections_routed(self):
        for sec in ["dashboard", "teachers", "classes", "subjects", "enrollment",
                    "grades", "assessments", "settings", "submissions", "reportcards"]:
            self.assertIn('id="sec-' + sec + '"', self.page, f"section {sec} vanished")
            self.assertIn('data-sec="' + sec + '"', self.page,
                          f"nav button for {sec} vanished")

    def test_nav_resets_scroll(self):
        self.assertRegex(self.page, r"const _main=document\.querySelector\('main'\); if\(_main\)_main\.scrollTop=0;")

    def test_shared_stylesheets_still_linked(self):
        self.assertIn('/admin/css/mobile.css', self.page)
        self.assertIn('/admin/css/report_card.css', self.page)
        self.assertIn('wbws_calendar_scripts', self.page)

    def test_no_new_external_resources(self):
        """The CDN set stays exactly what it was (Tailwind, FA, XLSX)."""
        urls = set(re.findall(r'(?:src|href)="(https?://[^"]+)"', self.page))
        self.assertEqual(
            urls,
            {"https://cdn.tailwindcss.com",
             "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css",
             "https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"},
            "external resource set changed — P70 must add none")

    def test_inline_max_widths_untouched(self):
        """Sheets override inline widths with !important at runtime; the
        inline declarations themselves must stay byte-identical."""
        for fragment in ['style="max-width:720px"', 'style="max-width:480px"',
                         'style="max-width:1100px"']:
            self.assertIn(fragment, self.page)


if __name__ == "__main__":
    unittest.main()
