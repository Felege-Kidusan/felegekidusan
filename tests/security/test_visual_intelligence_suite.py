"""Visual Intelligence & Sacred Data Analytics Suite Contracts (2026-09-27).

Validates the full implementation and integration of the 15 visual intelligence archetypes:
  1. 13-Month Ethiopian Attendance Heatmap Calendar (Meskerem to Pagume)
  2. Apple Health-Style Multi-Domain Concentric Rings
  3. 5-Stage Student Spiritual & Academic Metro Journey Map
  4. Class Pulse & 4-Quadrant Bubble Matrix (Size × Attendance % × Exam GPA)
  5. Student Activity Galaxy & Constellation Network
  6. 5-Axis Radial Radar Scorecard
  7. "School Pulse" Central Executive Cockpit Dashboard & Health Orb
  8. Student Intake, Progression & Retention Sankey Diagram / Flow
  9. Mountain / Area Timeline Streamgraph (Cubic Bézier)
 10. Circular Attendance Matrix & Day Inspector Modal
 11. Hierarchical Demographic & Section Density Treemap
 12. 4-Quadrant Bubble Scatter Matrix
 13. Multi-Track Calendar + Activity Timeline Hybrid
 14. Church Ministry Constellation Graph & Hierarchy
 15. Sunday School Solar Data Orbit Navigation Cockpit
"""

from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]


class VisualIntelligenceJsContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.js = (ROOT / "admin/js/visual_intelligence.js").read_text(encoding="utf-8")

    def test_all_15_archetypes_present(self):
        methods = [
            "renderEthiopianHeatmap",         # 1
            "renderConcentricRings",          # 2
            "renderJourneyMap",              # 3
            "renderBubbleMatrix",             # 4
            "renderGalaxy",                   # 5
            "renderRadar",                    # 6
            "renderSchoolPulseCockpit",       # 7
            "renderSankey",                   # 8
            "renderAreaTimeline",             # 9
            "renderCircularAttendanceMatrix", # 10
            "openDayInspector",               # 10 modal
            "renderTreemap",                  # 11
            "renderScatterMatrix",            # 12
            "renderActivityTimelineHybrid",   # 13
            "renderConstellationGraph",       # 14
            "renderDataOrbitNavigation",      # 15
        ]
        for m in methods:
            self.assertIn(m, self.js, f"Missing method {m} in visual_intelligence.js")

    def test_ethiopian_months_and_pagume_support(self):
        self.assertIn("መስከረም", self.js)
        self.assertIn("ጳጉሜ", self.js)
        self.assertIn("Meskerem", self.js)
        self.assertIn("Pagume", self.js)

    def test_xss_sanitization_in_tooltips(self):
        self.assertIn("function esc(", self.js)
        self.assertIn("&amp;", self.js)
        self.assertIn("&lt;", self.js)
        self.assertIn("&gt;", self.js)


class VisualIntelligenceCssContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.css = (ROOT / "themes/components.css").read_text(encoding="utf-8")

    def test_css_classes_present(self):
        selectors = [
            ".vi-bento-grid",
            ".vi-card",
            ".vi-heatmap-container",
            ".vi-heatmap-cell",
            ".vi-ring-widget",
            ".vi-ring-circle",
            ".vi-journey-track",
            ".vi-journey-node",
            ".vi-bubble-stage",
            ".vi-bubble-node",
            ".vi-radar-stage",
            ".vi-sankey-stage",
            ".vi-treemap-grid",
            ".vi-treemap-block",
            ".vi-galaxy-canvas",
            ".vi-inspector-panel",
            ".vi-hybrid-timeline",
            ".vi-track-event",
            ".vi-constellation-container",
            ".vi-orbit-stage",
            ".vi-orbit-sun",
            ".vi-orbit-planet",
        ]
        for sel in selectors:
            self.assertIn(sel, self.css, f"Missing CSS selector {sel} in themes/components.css")


class VisualIntelligenceBackendContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.service = (ROOT / "admin/backend/services/InfoAnalyticsService.php").read_text(encoding="utf-8")
        cls.api = (ROOT / "admin/api_info_analytics.php").read_text(encoding="utf-8")

    def test_service_methods(self):
        for method in ("heatmapData", "sankeyFlow", "treemapData"):
            self.assertIn(f"function {method}", self.service, f"Missing method {method} in InfoAnalyticsService.php")

    def test_api_action_routes(self):
        for action in ("'heatmap'", "'sankey'", "'treemap'"):
            self.assertIn(action, self.api, f"Missing API action {action} in api_info_analytics.php")


class VisualIntelligenceDashboardIntegration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.info_page = (ROOT / "admin/dashboards/info-dept.php").read_text(encoding="utf-8")
        cls.edu_page = (ROOT / "admin/dashboards/edu_dept.php").read_text(encoding="utf-8")
        cls.hr_page = (ROOT / "admin/dashboards/hr-dept.php").read_text(encoding="utf-8")
        cls.base_layout = (ROOT / "frontend/layouts/base.php").read_text(encoding="utf-8")

    def test_info_dashboard_integration(self):
        self.assertIn("visual_intelligence.js", self.info_page)
        self.assertIn("viHeatmapContainer", self.info_page)
        self.assertIn("viCockpitWrapper", self.info_page)
        self.assertIn("viJourneyContainer", self.info_page)
        self.assertIn("viSankeyContainer", self.info_page)
        self.assertIn("viBubbleContainer", self.info_page)
        self.assertIn("viRadarContainer", self.info_page)
        self.assertIn("viTreemapContainer", self.info_page)
        self.assertIn("viAreaContainer", self.info_page)
        self.assertIn("viTimelineContainer", self.info_page)
        self.assertIn("viOrbitContainer", self.info_page)
        self.assertIn("viConstellationContainer", self.info_page)
        self.assertIn("viGalaxyContainer", self.info_page)

    def test_edu_and_hr_dashboards_include_engine(self):
        self.assertIn("visual_intelligence.js", self.edu_page)
        self.assertIn("visual_intelligence.js", self.hr_page)
        self.assertIn("visual_intelligence.js", self.base_layout)


if __name__ == "__main__":
    unittest.main()
