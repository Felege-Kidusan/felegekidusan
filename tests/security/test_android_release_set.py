"""Synthetic record consistency; not SDK signing, installability or device tests."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

from apk_fixture import write_apk

SCRIPT = Path(__file__).resolve().parents[2] / 'tests/e2e/android_release_artifacts.py'
spec = importlib.util.spec_from_file_location('android_release_set', SCRIPT)
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class AndroidReleaseSetTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.directory = Path(self.temp.name)
        items = []
        for kind, abis in release.KINDS.items():
            apk = write_apk(self.directory / (kind + '.apk'), abis=tuple(sorted(abis)))
            sha = release.digest(apk)
            # Deliberately fabricated tool records. Actual SDK/ELF/signature
            # checks are exercised separately, with real APKs, by Android CI.
            report = {'package': 'com.arkeonethiopia.fkss', 'version': '1.7.4', 'version_code': 41,
                      'min_sdk': 24, 'target_sdk': 36, 'sha256': sha,
                      'native_libraries': {f'lib/{abi}/libapp.so': {'abi': abi} for abi in abis}}
            report_name = kind + '.json'
            (self.directory / report_name).write_text(json.dumps(report))
            items.append({'kind': kind, 'name': apk.name, 'sha256': sha, 'compatibility_report': report_name})
        self.manifest = {'app_version': '1.7.4', 'app_build': '41', 'artifacts': items}
        self.save_manifest()

    def tearDown(self):
        self.temp.cleanup()

    def save_manifest(self):
        # Also exercise Windows PowerShell 5 UTF8 BOM handling.
        (self.directory / 'release-manifest.json').write_text(json.dumps(self.manifest), encoding='utf-8-sig')

    def change_report(self, **values):
        path = self.directory / 'arm64-v8a.json'
        data = json.loads(path.read_text())
        data.update(values)
        path.write_text(json.dumps(data))

    def test_matching_base_build_and_expected_abi_sets(self):
        result = release.check_set(self.directory)
        self.assertEqual(set(result), set(release.KINDS))
        self.assertEqual({row['version_code'] for row in result.values()}, {41})

    def test_offset_split_build_is_refused_not_normalized(self):
        self.change_report(version_code=2041)
        with self.assertRaisesRegex(ValueError, 'version mismatch'):
            release.check_set(self.directory)

    def test_report_hash_and_actual_bytes_must_agree(self):
        apk = self.directory / 'arm64-v8a.apk'
        apk.write_bytes(apk.read_bytes() + b'changed')
        with self.assertRaisesRegex(ValueError, 'hash mismatch'):
            release.check_set(self.directory)

    def test_duplicate_missing_or_extra_artifact_kinds_refused(self):
        self.manifest['artifacts'][1]['kind'] = 'universal'
        self.save_manifest()
        with self.assertRaises(ValueError):
            release.check_set(self.directory)

    def test_plugin_only_or_extra_abi_cannot_masquerade_as_split(self):
        self.change_report(native_libraries={'lib/arm64-v8a/libapp.so': {'abi': 'arm64-v8a'},
                                            'lib/armeabi-v7a/libplugin.so': {'abi': 'armeabi-v7a'}})
        with self.assertRaisesRegex(ValueError, 'complete ABI set'):
            release.check_set(self.directory)

    def test_package_or_sdk_regressions_refused(self):
        for values in ({'min_sdk': 29}, {'min_sdk': 24, 'target_sdk': 35},
                       {'target_sdk': 36, 'package': 'example.other'}):
            with self.subTest(values=values):
                self.change_report(**values)
                with self.assertRaises(ValueError):
                    release.check_set(self.directory)

    def test_evidence_paths_cannot_escape_directory(self):
        for name in ('../outside.json', '/outside.json', 'nested/report.json', '..\\outside.json'):
            with self.subTest(name=name), self.assertRaises(ValueError):
                release.local_file(self.directory, name)

    def test_real_php_inspector_with_explicitly_noninstallable_fixtures(self):
        # This exercises fixture plumbing and PHP version/ELF identity checks,
        # NOT real SDK verification. Real artifacts run through this same path
        # in Android CI after the owner script's mandatory SDK gates.
        result = release.inspect_publication(release.check_set(self.directory))
        self.assertEqual(set(result), set(release.KINDS))
        self.assertEqual({row['version_code'] for row in result.values()}, {41})


if __name__ == '__main__':
    unittest.main()
