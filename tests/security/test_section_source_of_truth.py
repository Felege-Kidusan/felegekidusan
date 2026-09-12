"""
Section / Age Group single source of truth (P71) — regression & quality gates
═════════════════════════════════════════════════════════════════════════════
Pins the contract that App\Services\MemberCategory is the ONLY definition of
the section/age-group concept, and that every consumer derives from it:

  • the definition itself (codes, letters, names, age ranges, alias map)
  • the education class modal posts ONE Section/Age select; the API derives
    the section name server-side (the stored pair can never disagree)
  • no UI hardcodes the lists any more (and the historical wrong vocabulary
    ልጆች / ማእከላዊ / ሰበከላ is gone from markup)
  • the data normalization migration mirrors the alias map and is idempotent
  • JS label maps are injected from PHP, never hand-typed
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class SectionSourceOfTruthTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.cat = (ROOT / "admin/backend/services/MemberCategory.php").read_text(encoding="utf-8")
        cls.edu = (ROOT / "admin/dashboards/edu_dept.php").read_text(encoding="utf-8")
        cls.edu_api = (ROOT / "admin/api_education.php").read_text(encoding="utf-8")
        cls.migration = (ROOT / "sql/041_section_age_source_of_truth.sql").read_text(encoding="utf-8")
        cls.import_api = (ROOT / "admin/api_import_members.php").read_text(encoding="utf-8")

    # ── 1. the definition ─────────────────────────────────────────
    def test_definition_is_complete(self):
        for token in ["'7_13'", "'14_17'", "'18_plus'", "'ህጻናት'", "'ማዕከላዊያን'",
                      "'ወጣቶች'", "SECTION_META", "SECTION_ALIASES"]:
            self.assertIn(token, self.cat, f"MemberCategory lost {token}")
        for method in ["sections(", "sectionAm(", "sectionEn(", "ageRangeLabel(",
                       "ageGroupForSectionAm(", "normalizeSectionAm("]:
            self.assertIn(method, self.cat, f"MemberCategory lost accessor {method}")
        # existing API surface untouched (identity system depends on it)
        for method in ["normalizeGroup(", "letterFor(", "groupFor(", "labelsAm(",
                       "labelAm(", "labelEn(", "letters(", "groups("]:
            self.assertIn(method, self.cat)

    def test_alias_map_covers_the_historical_wrong_vocabulary(self):
        for alias, canonical in [("ልጆች", "ህጻናት"), ("ማእከላዊ", "ማዕከላዊያን"),
                                 ("ሰበከላ", "ወጣቶች")]:
            self.assertRegex(
                self.cat,
                r"'" + alias + r"'\s*=>\s*'" + canonical + r"'",
                f"alias {alias!r} missing")

    # ── 2. education modal + API (the reported defect) ────────────
    def test_class_modal_has_one_section_age_select(self):
        self.assertIn('id="classAge"', self.edu)
        self.assertNotIn('id="classSection"', self.edu, "old separate Section select still present")
        self.assertRegex(self.edu, r'Section / Age Group')
        # options rendered from the source of truth, not hand-typed
        self.assertRegex(self.edu, r"MemberCategory::sections\(\)")
        self.assertNotIn('<option value="ሰበከላ">', self.edu)
        self.assertNotIn('<option value="ልጆች">', self.edu)

    def test_save_class_derives_section_server_side(self):
        api = self.edu_api
        self.assertIn("MemberCategory::groups()", api)      # validation whitelist
        self.assertIn("MemberCategory::sectionAm(", api)    # derived section name
        self.assertIn("MemberCategory::ageGroupForSectionAm(", api)  # legacy posts
        # no inline duplicated whitelist may return
        self.assertNotIn("['7_13','14_17','18_plus']", api)
        self.assertNotIn("['7_13', '14_17', '18_plus']", api)

    def test_edu_js_uses_injected_definition(self):
        self.assertIn("EDU_SECTIONS=", self.edu)
        self.assertIn("eduSecLabel(", self.edu)
        self.assertIn("eduSecCode(", self.edu)

    def test_wrong_vocabulary_eliminated_from_all_ui(self):
        """The old modal list (ልጆች/ማእከላዊ/ሰበከላ) must not appear as UI options
        anywhere — only MemberCategory's alias map and the migration may
        reference it (to normalize historical data)."""
        allowed = {"admin/backend/services/MemberCategory.php",
                   "sql/041_section_age_source_of_truth.sql"}
        for path in ROOT.rglob("*.php"):
            rel = path.relative_to(ROOT).as_posix()
            if "vendor" in rel or rel in allowed or rel.startswith("docs/"):
                continue
            if "ሰበከላ" in path.read_text(encoding="utf-8", errors="replace"):
                self.fail(f"ሰበከላ still present in {rel}")
        for path in ROOT.glob("admin/js/*.js"):
            if "ሰበከላ" in path.read_text(encoding="utf-8", errors="replace"):
                self.fail(f"ሰበከላ still present in {path.name}")

    # ── 3. every former hardcode site now sources from the truth ──
    def test_dashboards_render_options_from_member_category(self):
        sites = {
            "admin/dashboards/school_admin.php": 1,
            "admin/dashboards/hr-dept.php": 3,
            "admin/dashboards/info-dept.php": 3,
            "admin/reports.php": 1,
            "admin/dashboards/edu_dept.php": 4,  # modal + roster + unassigned + bulk
        }
        for rel, minimum in sites.items():
            src = (ROOT / rel).read_text(encoding="utf-8")
            count = src.count("MemberCategory::sections()")
            self.assertGreaterEqual(count, minimum,
                                    f"{rel} renders fewer MemberCategory-sourced lists than expected")
            self.assertNotIn('<option value="7_13">', src,
                             f"{rel} still hardcodes a section/age option")

    def test_js_label_maps_are_injected_not_typed(self):
        all_members = (ROOT / "admin/js/all-members.js").read_text(encoding="utf-8")
        self.assertIn("window.WBWS_SECTIONS", all_members)
        self.assertNotIn("'7_13':", all_members)
        for rel in ["admin/dashboards/hr-dept.php", "admin/dashboards/info-dept.php"]:
            src = (ROOT / rel).read_text(encoding="utf-8")
            self.assertIn("window.WBWS_SECTIONS=", src, f"{rel} must inject the map")
        reports = (ROOT / "admin/reports.php").read_text(encoding="utf-8")
        self.assertIn("RP_SECTIONS=", reports)

    def test_report_renderer_delegates_to_member_category(self):
        renderer = (ROOT / "admin/backend/services/MemberReportRenderer.php").read_text(encoding="utf-8")
        self.assertIn("MemberCategory::sectionAm(", renderer)
        self.assertNotIn("AGE_LABELS", renderer)

    def test_import_gate_normalizes_sections(self):
        self.assertIn("normalizeSectionAm", self.import_api)
        self.assertIn("current_section", self.import_api)

    # ── 4. the migration ──────────────────────────────────────────
    def test_migration_normalizes_both_tables_and_categories(self):
        sql = self.migration
        for table in ["`classes`", "`members`", "`mezmur_categories`"]:
            self.assertIn(table, sql)
        # classes: pair alignment in BOTH directions
        self.assertIn("WHERE `age_group` = '7_13'", sql)
        self.assertIn("AND `section` = 'ህጻናት'", sql)
        # the historical wrong values are mapped
        for wrong in ["'ልጆች'", "'ማእከላዊ'", "'ሰበከላ'", "'ህናት'", "'ማዕከዊያን'", "'ጣቶች'"]:
            self.assertIn(wrong, sql, f"migration misses legacy value {wrong}")
        # no DELETE / DROP / schema change — pure value normalization
        self.assertNotRegex(sql.upper(), r'\bDELETE\b')
        self.assertNotRegex(sql.upper(), r'\bDROP\b')
        self.assertNotRegex(sql.upper(), r'\bALTER\s+TABLE\b')

    def test_migration_alias_map_matches_member_category(self):
        """Every variant the PHP alias map knows must be normalized by the
        SQL too (they are the same map in two places by necessity — SQL
        cannot call PHP)."""
        cat_aliases = set(re.findall(r"'([^']+)' => '(?:ህጻናት|ማዕከላዊያን|ወጣቶች)'", self.cat))
        for alias in cat_aliases:
            self.assertIn(alias, self.migration,
                          f"SQL migration misses alias {alias!r} present in MemberCategory")

    # ── 5. untouched neighbours (scope discipline) ────────────────
    def test_identity_code_doctrine_preserved(self):
        """MemberCategory must still never guess: unknown groups → null."""
        self.assertIn("return null;", self.cat)
        self.assertIn("never guessed", self.cat.lower() if "never guessed" in self.cat.lower() else self.cat)

    def test_flutter_and_apis_unchanged_in_contract(self):
        """The mobile app has no hardcoded lists (it displays raw values —
        the data migration fixes its display) and the v1 API still accepts
        the same filter params."""
        members_api = (ROOT / "api/v1/routes/members.php").read_text(encoding="utf-8")
        self.assertIn("age_group", members_api)
        self.assertIn("current_section", members_api)
        flutter_hits = []
        for path in (ROOT / "Mobile/wbws_flutter_app/lib").rglob("*.dart"):
            if "ህጻናት (A)" in path.read_text(encoding="utf-8", errors="replace"):
                flutter_hits.append(path.name)
        self.assertEqual(flutter_hits, [], "Flutter must not hardcode section labels")


if __name__ == "__main__":
    unittest.main()
