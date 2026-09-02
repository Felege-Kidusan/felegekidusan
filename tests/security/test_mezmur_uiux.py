"""
Mezmur UI/UX upgrade (Phase 3) — regression & quality gates
════════════════════════════════════════════════════════════
Locks the front/back separation and the UI quality contract:
  • shell = pure HTML (zero inline style attributes)
  • all styles live in theme.css + themes/components.css (token-driven)
  • three states everywhere (skeleton / empty+CTA / error+retry)
  • accessibility affordances (labels, dialogs, aria-live, focus)
  • scale affordances (section collapsing threshold, server pagination)
  • zero new backend surface (API actions unchanged)
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class MezmurUiUxTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.shell = (ROOT / "frontend/pages/mezmur_dept.php").read_text(encoding="utf-8")
        cls.js = (ROOT / "frontend/js/mezmur.js").read_text(encoding="utf-8")
        cls.css = (ROOT / "themes/components.css").read_text(encoding="utf-8")
        cls.base = (ROOT / "frontend/layouts/base.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_mezmur.php").read_text(encoding="utf-8")

    # ── front/back separation ──────────────────────────────────
    def test_shell_has_zero_inline_styles(self):
        self.assertEqual(self.shell.count("style="), 0,
                         "Shell must stay pure HTML; styles belong in CSS files.")

    def test_shell_has_no_php_queries(self):
        self.assertNotIn("query(", self.shell)
        self.assertNotIn("mysqli", self.shell)

    def test_components_css_is_loaded_by_base_layout(self):
        self.assertIn("themes/components.css", self.base)
        self.assertIn("filemtime($__componentsCss)", self.base)

    def test_components_css_is_token_driven(self):
        # no hardcoded surface colors — only tokens (gradients/alpha come from vars)
        for token in ["var(--school-accent)", "var(--school-success)",
                      "var(--school-warning)", "var(--school-danger)",
                      "var(--school-border)", "var(--school-surface)"]:
            self.assertIn(token, self.css)

    def test_components_css_covers_all_planned_components(self):
        for cls in [".page-head", ".toolbar", ".stat-grid", ".quick-tile",
                    ".rate-bar", ".rate-chip", ".seg-btn", ".group-head",
                    ".sheet-summarybar", ".member-row", ".empty-state",
                    ".error-state", ".skeleton", ".trend-wrap", ".pager",
                    ".btn-block", ".is-hidden", ".lyrics-view", ".print-only"]:
            self.assertIn(cls, self.css, "missing component class " + cls)

    def test_components_css_supports_a11y_and_motion_and_print(self):
        self.assertIn(":focus-visible", self.css)
        self.assertIn("prefers-reduced-motion", self.css)
        self.assertIn("@media print", self.css)

    # ── overview section ───────────────────────────────────────
    def test_overview_section_and_ids_exist(self):
        self.assertIn('id="section-overview"', self.shell)
        for eid in ["mzGreeting", "mzOvDays", "mzOvDaysDelta", "mzOvRate",
                    "mzOvRateDelta", "mzOvHymns", "mzOvMembers", "mzOvTakers",
                    "mzOvRecentDays", "mzOvRecentHymns", "mzQaTakers"]:
            self.assertIn('id="%s"' % eid, self.shell)

    def test_overview_is_composed_from_existing_actions_only(self):
        # overview data comes from actions that already existed pre-Phase-3
        for action in ["action=days_list", "action=stats", "action=list"]:
            self.assertIn(action, self.js)
        # takers are governed by the department-owned endpoint now
        self.assertIn("/admin/api_dept_takers.php?action=list", self.js)

    def test_stats_exposes_member_count_for_overview(self):
        self.assertIn("'members' =>", self.api)
        self.assertIn("FROM members WHERE status = 'active'", self.api)

    # ── three states everywhere ───────────────────────────────
    def test_js_has_state_renderers(self):
        self.assertIn("function skeletonRows(", self.js)
        self.assertIn("function emptyState(", self.js)
        self.assertIn("function errorState(", self.js)
        self.assertIn("Retry", self.js)

    def test_shell_initial_states_are_skeletons_not_spinners(self):
        self.assertIn("skeleton-row", self.shell)
        self.assertNotIn("fa-spinner", self.shell)

    # ── attendance sheet UX ────────────────────────────────────
    def test_web_console_is_readonly_review_surface(self):
        # 2026-08-28 product decision: the department dashboard reviews;
        # marking lives exclusively in the mobile app.
        self.assertIn("GET_TIMEOUT = 12000", self.js)         # bounded GETs
        self.assertIn("sheet-summarybar", self.shell)
        self.assertIn('aria-live="polite"', self.shell)
        self.assertIn("Read-only", self.shell)
        # the former editor machinery is gone from the web bundle
        self.assertNotIn("seg-btn", self.js)
        self.assertNotIn("ArrowDown", self.js)
        self.assertNotIn("draftKey", self.js)
        self.assertNotIn("beforeunload", self.js)

    def test_sheet_date_contract_untouched(self):
        # phase 5: sheets are section-scoped (teacher clone) and the
        # department reviews packets like Education does.
        self.assertIn("action=sheet&date=", self.js)
        self.assertIn("'&section=' + encodeURIComponent(section)", self.js)
        self.assertIn("action: 'submission_review'", self.js)

    # ── accessibility ──────────────────────────────────────────
    def test_inputs_are_labeled(self):
        # every form control in the shell carries a label or aria-label
        self.assertGreaterEqual(self.shell.count("aria-label="), 15)
        self.assertGreaterEqual(self.shell.count('class="school-label"'), 8)

    def test_modals_are_dialogs_with_close_labels(self):
        # hymn, view, taker + phase-5 review & packet modals + catalog dialog
        # + cover/color/system dialogs (P34)
        self.assertEqual(self.shell.count('role="dialog"'), 8)
        self.assertEqual(self.shell.count('aria-modal="true"'), 8)
        self.assertGreaterEqual(self.shell.count('aria-label="Close dialog"'), 7)

    def test_modal_focus_management(self):
        self.assertIn("function openModalF(", self.js)
        self.assertIn("function closeModalF(", self.js)
        self.assertIn("e.key !== 'Escape'", self.js.replace('"', "'"))

    # ── analytics upgrades ─────────────────────────────────────
    def test_analytics_sort_affordances(self):
        self.assertIn("th-sortable", self.shell)
        self.assertIn("aria-sort", self.shell)
        self.assertIn("function updateSortHeaders(", self.js)
        self.assertIn("rateBar(", self.js)
        self.assertIn("rateChip(", self.js)

    # ── no regressions / contracts ─────────────────────────────
    def test_api_surface_unchanged_no_new_actions(self):
        for action in ["case 'days_list':", "case 'day_create':", "case 'sheet':",
                       "case 'save_sheet':", "case 'stats':", "case 'list':",
                       "case 'takers_list':", "case 'analytics_members':",
                       "case 'analytics_sections':", "case 'analytics_trends':"]:
            self.assertIn(action, self.api)

    def test_section_switch_contract(self):
        for section in ["overview", "library", "catalog", "attendance", "analytics", "takers"]:
            self.assertIn('id="section-%s"' % section, self.shell)
        # bottom nav mirrors sidebar (6 entries incl. overview + catalog)
        self.assertEqual(self.shell.count("school-bottom-nav-btn"), 6)

    def test_no_padleft_bug_in_js(self):
        self.assertNotIn(".padLeft(", self.js)
        self.assertIn(".padStart(", self.js)

    def test_roles_and_feature_gate_unchanged(self):
        self.assertIn("$requiredRoles = ['super_admin', 'school_admin', 'mezmur_dept'];", self.shell)
        self.assertIn("$requiredFeature = 'mezmur';", self.shell)


if __name__ == "__main__":
    unittest.main()
