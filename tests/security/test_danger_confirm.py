"""Danger confirmation system — static + PHP checks (no server/DB needed).

Plan: docs/planning/DANGER_CONFIRMATION_RESEARCH_PLAN_2026-10-21.md
"""
import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(rel):
    return (ROOT / rel).read_text(encoding='utf-8', errors='replace')


# Files fully migrated to DangerConfirm: no native confirm()/prompt() allowed.
MIGRATED = [
    'admin/js/edu_timetable.js',
    'admin/js/school_calendar.js',
    # DC-2 Education + School Admin
    'admin/dashboards/edu_dept.php',
    'admin/dashboards/school_admin.php',
    'frontend/js/education_teachers.js',
    'frontend/js/education_assignments.js',
]

# (endpoint file, phrase key) — HIGH actions that must be enforced server-side.
SERVER_ENFORCED = [
    ('admin/api_timetable.php', 'timetable.remove_day'),
    ('admin/api_timetable.php', 'timetable.delete_period'),
    ('admin/api_timetable.php', 'timetable.copy_bell'),
    # DC-2
    ('admin/api_education.php', 'education.delete_class'),
    ('admin/api_education.php', 'education.delete_term'),
    ('admin/api_education.php', 'education.sync_types'),
    ('admin/api_subjects.php', 'subjects.delete_subject'),
    ('admin/api_subjects.php', 'subjects.apply_template'),
]

NATIVE = re.compile(r'(?<![\w.$])(?:window\.)?(confirm|prompt)\s*\(')


def strip_comments(js):
    js = re.sub(r'/\*.*?\*/', '', js, flags=re.S)
    return re.sub(r'(^|[^:\\])//[^\n]*', r'\1', js)


def php_phrases():
    src = read('admin/backend/danger_confirm.php')
    return dict(re.findall(r"'([a-z_]+\.[a-z_]+)'\s*=>\s*'([A-Z ]+)'", src))


class DangerConfirmComponent(unittest.TestCase):
    def test_component_core_rules(self):
        js = read('admin/js/danger_confirm.js')
        for needle, why in [
            ("'role', 'alertdialog'", 'alertdialog role'),
            ("'aria-modal', 'true'", 'aria-modal'),
            ("aria-describedby", 'described by body'),
            ('data-dcx-act="cancel"', 'cancel button'),
            ("ev.key === 'Escape'", 'Esc cancels'),
            ("autocomplete=\"off\"", 'no autocomplete on phrase'),
            ("I have read and understand these effects", 'step-2 acknowledgement (GitHub pattern)'),
            ("STEP ' + step + ' OF ' + steps", 'step indicator'),
            ("if (open) return Promise.resolve(false)", 'never stacks dialogs'),
            ("Working…", 'double-submit guard'),
        ]:
            self.assertIn(needle, js, why)
        self.assertIsNone(NATIVE.search(strip_comments(js)), 'component must not use native popups')
        # Initial focus is Cancel on non-typing steps (Primer rule).
        self.assertRegex(js, r"q\('\[data-dcx-act=\"cancel\"\]'\)")

    def test_phrases_are_plain_english_capitals(self):
        ph = php_phrases()
        self.assertGreaterEqual(len(ph), 10)
        for k, v in ph.items():
            self.assertRegex(v, r'^[A-Z]+( [A-Z]+)*$', k)


class DangerConfirmMigration(unittest.TestCase):
    def test_migrated_files_have_no_native_popups(self):
        for rel in MIGRATED:
            hits = [m.group(0) for m in NATIVE.finditer(strip_comments(read(rel)))]
            self.assertEqual(hits, [], rel)

    def test_pages_using_dialog_load_component(self):
        """Every PHP page that loads a script calling DangerConfirm (or defines it
        inline) must also load admin/js/danger_confirm.js."""
        users = set()
        for base in ('admin', 'frontend'):
            for p in (ROOT / base).rglob('*.js'):
                if 'vendor' in p.parts or p.name.endswith('.min.js') or p.name == 'danger_confirm.js':
                    continue
                if 'DangerConfirm' in p.read_text(encoding='utf-8', errors='replace'):
                    users.add(p.name)
        self.assertTrue(users, 'no module uses DangerConfirm')
        missing = []
        for p in list((ROOT / 'admin').rglob('*.php')) + list((ROOT / 'frontend').rglob('*.php')):
            if 'vendor' in p.parts:
                continue
            src = p.read_text(encoding='utf-8', errors='replace')
            loads = [u for u in users if re.search(r'src="[^"]*/' + re.escape(u) + r'[?"]', src)]
            inline = 'DangerConfirm.ask' in src
            if (loads or inline) and 'danger_confirm.js' not in src:
                missing.append(str(p.relative_to(ROOT)))
        self.assertEqual(missing, [])

    def test_js_phrases_match_server(self):
        ph = php_phrases()
        for rel, key in SERVER_ENFORCED:
            phrase = ph[key]
            found = any(("'" + phrase + "'") in read(f) for f in MIGRATED + [
                p for p in (
                    'admin/dashboards/edu_dept.php', 'admin/dashboards/school_admin.php',
                    'admin/dashboards/super-admin.php', 'admin/js/family_link.js', 'admin/js/hr_data_sync.js',
                    'admin/js/app_release.js', 'admin/dashboards/finance_department.php',
                    'frontend/js/finance.js', 'admin/dashboards/groups.php', 'admin/groups.php')
                if (ROOT / p).exists()])
            self.assertTrue(found, f'{key}: dialog phrase {phrase!r} not found in any page')

    def test_frontend_shell_loads_component(self):
        # frontend/js/*.js page scripts are loaded by name through the shared layout
        self.assertIn('danger_confirm.js', read('frontend/layouts/base.php'))

    def test_high_dialogs_send_phrase(self):
        for rel in ('admin/dashboards/edu_dept.php', 'admin/dashboards/school_admin.php'):
            self.assertIn('confirm_phrase', read(rel), rel)

    def test_dc2_server_guards(self):
        src = read('admin/api_education.php')
        for needle in ("case 'impact_delete_class'", "case 'impact_delete_term'", "'term_in_use'"):
            self.assertIn(needle, src)
        # impact previews are read-only GET actions, never in the POST-only list
        post_line = re.search(r'\$requirePostActions\s*=\s*\[(.*?)\];', src, re.S)
        if post_line:
            self.assertNotIn('impact_delete', post_line.group(1))

    def test_server_enforcement_present(self):
        for rel, key in SERVER_ENFORCED:
            src = read(rel)
            self.assertIn("danger_confirm.php", src, rel)
            direct = f"dc_require_phrase('{key}'" in src
            # or via an action => key map that is passed to dc_require_phrase()
            mapped = re.search(r"=>\s*'" + re.escape(key) + r"'", src) and re.search(r'dc_require_phrase\(\$__dc\w+\[\$action\]\)', src)
            self.assertTrue(direct or mapped, f'{rel} must enforce {key}')


@unittest.skipUnless(shutil.which('php'), 'php CLI not installed')
class DangerConfirmPhp(unittest.TestCase):
    def run_php(self, code):
        out = subprocess.run(['php', '-r', code], capture_output=True, text=True, cwd=str(ROOT))
        return out.stdout.strip()

    def test_helper_behaviour(self):
        inc = "require 'admin/backend/danger_confirm.php';"
        self.assertEqual(self.run_php(inc + "echo dc_phrase_ok('timetable.remove_day',['confirm_phrase'=>'  remove   day ']) ? 'Y':'N';"), 'Y')
        self.assertEqual(self.run_php(inc + "echo dc_phrase_ok('timetable.remove_day',['confirm_phrase'=>'REMOVE']) ? 'Y':'N';"), 'N')
        self.assertEqual(self.run_php(inc + "echo dc_phrase_ok('timetable.remove_day',[]) ? 'Y':'N';"), 'N')
        self.assertEqual(self.run_php(inc + "echo dc_phrase_ok('nope.key',['confirm_phrase'=>'X']) ? 'Y':'N';"), 'N')
        body = self.run_php(inc + "$_POST=[]; dc_require_phrase('education.delete_class'); echo 'NOT-REACHED';")
        self.assertNotIn('NOT-REACHED', body)
        self.assertIn('confirm_phrase_required', body)
        self.assertIn('DELETE CLASS', body)

    def test_php_lint(self):
        for rel in ['admin/backend/danger_confirm.php'] + sorted({r for r, _ in SERVER_ENFORCED}):
            r = subprocess.run(['php', '-l', rel], capture_output=True, text=True, cwd=str(ROOT))
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


if __name__ == '__main__':
    unittest.main()
