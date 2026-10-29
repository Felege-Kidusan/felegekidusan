"""
Mobile target-environment guard.

`main` must retain the approved API and Android package identity:
felegekidusan.com API, package com.arkeonethiopia.fkss.
Development display-only branding does not switch the target environment.
Production promotion remains a separately authorized operation.
The FKSS Beta build lives ONLY on the `dev` branch of suraman21/SSMS
(owner rule 2026-10-20: beta settings must never sync to the production
repo). If a dev-branch commit is ever merged into main, this test fails
before any APK can be built.
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
M = ROOT / "Mobile/wbws_flutter_app"

PROD_API = "https://felegekidusan.com/api/v1"
PROD_PACKAGE = "com.arkeonethiopia.fkss"


def read(rel):
    return (M / rel).read_text(encoding="utf-8")


class MobileTargetEnvironmentGuard(unittest.TestCase):
    def test_production_api_and_identity(self):
        config = read("lib/utils/config.dart")
        self.assertIn(f"static const String apiBaseUrl = '{PROD_API}';", config)
        self.assertIn("static const String appName = 'ውሉደ ብርሃን';", config)
        self.assertNotIn("arkeonethiopia.com/api", config)
        self.assertNotIn("isBeta", config)
        gradle = read("android/app/build.gradle")
        self.assertIn(f'applicationId = "{PROD_PACKAGE}"', gradle)
        self.assertNotIn(".fkss.beta", gradle)
        manifest = read("android/app/src/main/AndroidManifest.xml")
        self.assertIn('android:label="ውሉደ ብርሃን ሰንበት ት/ቤት"', manifest)
        self.assertNotIn("Beta", manifest)
        self.assertNotIn("isBeta", read("lib/screens/auth/login_screen.dart"))


if __name__ == "__main__":
    unittest.main()
