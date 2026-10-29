"""Development display identity and immutable compatibility surface.

PHP exercises are real but DB-free. Frozen fixtures are from e334535;
these tests do not certify deployment, artwork acceptance or physical devices.
"""
import hashlib
import json
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SHORT = 'ውሉደ ብርሃን ሰንበት ት/ቤት'
FULL = 'የገላን ጉራ ቅዱስ ገብርኤል ቤተክርስቲያን ውሉደብርሃን ሰንበት ት/ቤት'
COMPACT = 'ውሉደ ብርሃን'


def php(code):
    result = subprocess.run(['php', '-d', 'display_errors=stderr', '-r', code],
                            cwd=ROOT, text=True, capture_output=True, check=True)
    assert not result.stderr, result.stderr
    return result.stdout


def constants():
    return json.loads(php("require 'school_config.php'; echo json_encode(get_defined_constants(true)['user']);"))


def test_owner_names_and_id_card_derivation():
    c = constants()
    assert c['SCHOOL_NAME'] == SHORT
    assert c['SCHOOL_NAME_AMHARIC'] == FULL
    assert c['SCHOOL_NAME_SHORT'] == c['SCHOOL_NAME_SHORT_AM'] == COMPACT
    assert c['ID_CARD_FOOTER_AM'] == SHORT
    assert c['ID_CARD_TITLE_AM'] == SHORT + ' አባል መታወቂያ ካርድ'
    assert c['PARISH_NAME_AM'] == FULL.split(' ውሉደብርሃን')[0]
    assert c['SCHOOL_TRANSLATION_EN'] == COMPACT
    assert c['PARISH_NAME_EN'] == c['PARISH_NAME_AM']


def test_every_non_display_constant_is_unchanged_from_e334535():
    expected = json.loads((ROOT / 'tests/fixtures/branding-protected-config.json').read_text())
    actual = constants()
    assert len(expected) == 74
    for key, value in expected.items():
        assert actual[key] == value, key


def test_all_preserved_assets_toolchains_and_color_values_are_byte_identical():
    expected = json.loads((ROOT / 'tests/fixtures/branding-protected-assets.json').read_text())
    assert len(expected) == 166
    for path, digest in expected.items():
        assert hashlib.sha256((ROOT / path).read_bytes()).hexdigest() == digest, path


def test_php_and_static_manifests_keep_routes_colors_icons_but_update_identity():
    dynamic = json.loads(php("require 'app/manifest.php';"))
    assert dynamic['name'] == SHORT
    assert dynamic['short_name'] == COMPACT
    assert SHORT in dynamic['description']
    assert dynamic['start_url'] == dynamic['scope'] == '/admin/'
    assert dynamic['display'] == 'browser'
    assert dynamic['theme_color'] == '#5A1212'
    static = json.loads((ROOT / 'app/manifest.json').read_text())
    assert static['name'] == SHORT
    assert static['short_name'] == COMPACT
    assert static['description'] == FULL
    assert static['start_url'] == '/app/'
    assert static['scope'] == '/'
    assert static['theme_color'] == '#064e3b'
    assert static['icons'][0]['src'] == '/app/icons/icon-192.svg'


def test_real_report_brand_metadata_and_print_banner():
    brand = json.loads(php("require 'school_config.php'; require 'admin/backend/services/ReportCardService.php'; echo json_encode(\\App\\Services\\ReportCardService::brand());"))
    assert brand['school_am'] == FULL
    assert brand['school_short'] == COMPACT
    assert brand['school_short_am'] == COMPACT
    assert 'Spring of Saints' not in brand['school_en']
    source = (ROOT / 'admin/dashboards/edu_dept.php').read_text()
    assert 'htmlspecialchars(SCHOOL_NAME_AMHARIC)' in source
    assert 'htmlspecialchars(SCHOOL_NAME)' in source
    assert 'Felege Kidusan Sunday School' not in source
    excel = (ROOT / 'admin/backend/services/ExcelExportService.php').read_text()
    assert "setCellValue('A1', defined('SCHOOL_NAME') ? SCHOOL_NAME" in excel


def test_scanner_accepts_new_identity_and_detects_previous_institution():
    keywords = json.loads(php("require 'school_config.php'; echo json_encode(unserialize(LEAK_DETECT_KEYWORDS));"))
    assert 'ፈለገ ቅዱሳን' in keywords
    assert 'Spring of Saints' in keywords
    assert 'Bole Bulbula St. Mary & St. John Holy Church' in keywords
    for keyword in keywords:
        assert keyword.casefold() not in (SHORT + FULL).casefold()
        assert keyword not in 'FKSS com.arkeonethiopia.fkss felegekidusan.com wbws_flutter_app'


def test_flutter_canonical_identity_and_launcher_match_php():
    base = ROOT / 'Mobile/wbws_flutter_app'
    config = (base / 'lib/utils/config.dart').read_text()
    assert f"appName = '{COMPACT}'" in config
    assert f"appNameAmharic = '{SHORT}'" in config
    assert FULL in config
    manifest = (base / 'android/app/src/main/AndroidManifest.xml').read_text()
    assert f'android:label="{SHORT}"' in manifest
    for name in ['teacher/teacher_home.dart', 'att_taker/att_taker_home.dart',
                 'edu_dept/edu_home.dart', 'finance/finance_home.dart',
                 'info_dept/info_home.dart', 'material/material_home.dart',
                 'admin/admin_home.dart']:
        source = (base / 'lib/screens' / name).read_text()
        assert 'Text(AppConfig.appName, maxLines: 1, overflow: TextOverflow.ellipsis' in source
        assert "Text('FKSS'" not in source
