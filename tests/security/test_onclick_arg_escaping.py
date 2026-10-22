"""Readiness audit 2026-10-22 — names passed into inline onclick="…" handlers.

A member / class name containing ' or " used to break out of the JS string
(or the HTML attribute) in three inline handlers. The browser regression is
tests/e2e/xss_attr_names_browser.py; this pins the safe encoding statically.
"""
import os
import unittest

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))


def read(rel):
    with open(os.path.join(ROOT, rel), encoding='utf-8') as f:
        return f.read()


class OnclickArgEscaping(unittest.TestCase):
    def test_family_link_unlink_uses_json_encoded_name(self):
        js = read('admin/js/family_link.js')
        self.assertNotIn("esc(kidName(k)).replace(/'/g, '')", js)
        self.assertIn("',' + esc(JSON.stringify(String(kidName(k)))) + ')\"", js)

    def test_family_link_tag_bumped(self):
        self.assertIn('family_link.js?v=<?= SSMS_ASSET_VER ?>-20261022u', read('admin/dashboards/hr-dept.php'))

    def test_info_quick_status_uses_jsarg(self):
        php = read('admin/dashboards/info-dept.php')
        self.assertIn('function jsArg(v)', php)
        self.assertNotIn("quickStatusChange('+m.id+',\\''+escapeHtml(", php)
        self.assertEqual(php.count("quickStatusChange('+parseInt(m.id,10)+','+jsArg("), 2)

    def test_edu_edit_class_attribute_escaped(self):
        php = read('admin/dashboards/edu_dept.php')
        self.assertNotIn("onclick='editClass(${JSON.stringify(c)})'", php)
        self.assertIn("onclick='editClass(${JSON.stringify(c).replace(/&/g,'&amp;').replace(/'/g,'&#39;')})'", php)


if __name__ == '__main__':
    unittest.main()
