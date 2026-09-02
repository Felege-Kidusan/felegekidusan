"""
Mezmur mobile Phase 4 — regression gates
═════════════════════════════════════════════════════════════
Locks: WBSS-U01 root-cause fix, teachers-grade attendance clone,
Ethiopian calendar usage, offline outbox parity, hymn/analytics
read-only mobile surface, role-gated tabs.
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
M = ROOT / "Mobile/wbws_flutter_app/lib"


class MobilePhase4Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.route = (ROOT / "api/v1/routes/mezmur.php").read_text(encoding="utf-8")
        cls.hymn_svc = (ROOT / "admin/backend/services/MezmurHymnService.php").read_text(encoding="utf-8")
        cls.db = (M / "services/local_db.dart").read_text(encoding="utf-8")
        cls.sync = (M / "services/sync_service.dart").read_text(encoding="utf-8")
        cls.api = (M / "services/api_service.dart").read_text(encoding="utf-8")
        cls.shell = (M / "screens/shell/app_shell.dart").read_text(encoding="utf-8")
        cls.config = (M / "utils/config.dart").read_text(encoding="utf-8")
        cls.att = (M / "screens/mezmur/mezmur_attendance.dart").read_text(encoding="utf-8")
        cls.hymns = (M / "screens/mezmur/mezmur_hymns.dart").read_text(encoding="utf-8")
        cls.ana = (M / "screens/mezmur/mezmur_analytics.dart").read_text(encoding="utf-8")
        cls.home = (M / "screens/mezmur/mezmur_home.dart").read_text(encoding="utf-8")

    # ── WBSS-U01 root cause ───────────────────────────────────
    def test_service_require_path_is_correct(self):
        self.assertIn(
            "require_once __DIR__ . '/../../../admin/backend/services/MezmurAttendanceService.php';",
            self.route)
        self.assertNotIn("dirname(__DIR__, 2)", self.route)

    # ── mobile hymn surface: curated writers, prepared-only ───
    def test_hymn_endpoints_reads_and_gated_writers(self):
        self.assertIn("MezmurHymnService::listHymns", self.route)
        self.assertIn("MezmurHymnService::getHymn", self.route)
        # Offline-first sync added curated writes (audit 2026-08-28):
        # they stay role-gated + prepared + audited on the server.
        self.assertIn("MezmurHymnService::saveHymn", self.route)
        self.assertIn("MezmurHymnService::setStatusHymn", self.route)
        self.assertIn("$MEZMUR_LIBRARY_WRITE_ROLES", self.route)
        self.assertIn("apiIdempotencyBegin(", self.route)
        self.assertIn("isApiRateLimited('mezmur_hymn_write'", self.route)
        # The service itself never concatenates input into SQL.
        self.assertIn("$stmt = $conn->prepare(", self.hymn_svc)
        self.assertIn("escapeLike(", self.hymn_svc)
        self.assertNotIn("$_GET", self.hymn_svc)
        self.assertNotIn("$_POST", self.hymn_svc)

    def test_mobile_analytics_sections_exposed(self):
        self.assertIn("analyticsSections($conn, $_GET)", self.route)
        self.assertIn("$ROUTE['parts'][2]", self.route)

    # ── offline outbox parity ─────────────────────────────────
    def test_localdb_v9_mezmur_tables(self):
        # v9 introduced the mezmur outbox; v10 (phase 5) made it
        # section-scoped. Both migration blocks must stay present.
        self.assertIn("version: 19,", self.db)
        self.assertIn("if (oldVersion < 11)", self.db)
        self.assertIn("if (oldVersion < 12)", self.db)  # HR outbox (Phase B)
        # v11: offline-first hymn library tables
        self.assertIn("CREATE TABLE IF NOT EXISTS cached_hymns", self.db)
        self.assertIn("CREATE TABLE IF NOT EXISTS pending_hymn_ops", self.db)
        self.assertIn("CREATE TABLE IF NOT EXISTS hymn_sync_meta", self.db)
        self.assertIn("CREATE TABLE pending_mezmur", self.db.replace("IF NOT EXISTS ", ""))
        self.assertIn("CREATE TABLE cached_mezmur_sheet", self.db.replace("IF NOT EXISTS ", ""))
        self.assertIn("if (oldVersion < 9)", self.db)
        self.assertIn("if (oldVersion < 10)", self.db)
        self.assertIn("cached_mezmur_sheet_v2", self.db)
        self.assertIn("CREATE TABLE cached_mezmur_sections", self.db.replace("IF NOT EXISTS ", ""))
        self.assertIn("PRIMARY KEY (date, section)", self.db)

    def test_localdb_mezmur_methods(self):
        for m in [
            "Future<void> saveMezmurLocal(",
            "Future<List<Map<String, dynamic>>> getPendingMezmur(",
            "Future<void> markMezmurSynced(",
            "Future<int> getPendingMezmurCount(",
            "Future<void> cacheMezmurSheet(",
            "Future<Map<String, dynamic>?> getCachedMezmurSheet(",
            "Future<void> cacheMezmurSections(",
            "Future<List<Map<String, dynamic>>?> getCachedMezmurSections(",
        ]:
            self.assertIn(m, self.db)
        # explicit-mark validation like the teachers pipeline (P/A/L/E)
        self.assertIn("const validStatuses = {'present', 'absent', 'late', 'excused'};", self.db)
        self.assertIn("(await getPendingMezmurCount())", self.db)
        self.assertIn("(await getPendingHymnOpsCount());", self.db)
        # phase 5: outbox is keyed by (date, section)
        self.assertIn("where: 'date = ? AND section = ? AND synced = 0'", self.db)

    def test_sync_drains_mezmur_outbox(self):
        self.assertIn("getPendingMezmurRecords(date, section)", self.sync)
        self.assertIn("saveMezmurSheet(date, apiRecords", self.sync)
        self.assertIn("markMezmurSynced(date, section)", self.sync)
        self.assertIn("final int pendingMezmur;", self.sync)
        # idempotency key flows with every delivery
        self.assertIn("clientOpId: opId", self.sync)
        # phase 5: section packets carry the draft/submitted kind + notes
        self.assertIn("section: section, kind: kind, clientOpId: opId", self.sync)
        self.assertIn("'notes': '${r['notes'] ?? ''}',", self.sync)

    # ── teachers-grade clone + Ethiopian calendar ─────────────
    def test_attendance_screen_clones_teacher_ux(self):
        for symbol in [
            "showEthiopianDatePicker", "formatGregorianAsEthiopian",
            "TeacherActionBar", "SubmittedBar", "PacketLock",
            "showQuickConfirm", "showUndoToast", "StatusBanner.error",
            "StudentListSkeleton", "EmptyState", "HapticFeedback",
            "_requireCompleteSheet", "saveMezmurLocal",
        ]:
            self.assertIn(symbol, self.att)

    def test_old_broken_sheet_screen_removed(self):
        self.assertFalse((M / "screens/mezmur/mezmur_sheet.dart").exists())
        self.assertNotIn("mezmur_sheet.dart", self.shell)

    def test_hymns_and_analytics_screens_wired(self):
        # Local-first since the 2026-08-28 offline upgrade: the list
        # reads the on-device store, never blocks on the network.
        self.assertIn("HymnStore()", self.hymns)
        self.assertIn("MezmurHymnDetailScreen", self.hymns)
        self.assertIn("MezmurHymnEditorScreen", self.hymns)
        self.assertIn("OfflineBanner", self.hymns)
        self.assertIn("getMezmurAnalytics(params:", self.ana)
        self.assertIn("showEthiopianDatePicker", self.ana)
        self.assertIn("case 'mezmur_hymns':", self.shell)
        self.assertIn("case 'mezmur_analytics':", self.shell)
        self.assertIn("MezmurAttendanceScreen(key: _mezmurAttKey)", self.shell)

    def test_hub_uses_ethiopian_greeting_and_tiles(self):
        self.assertIn("getEthiopianGreeting()", self.home)
        self.assertIn("getTodayEthiopian()", self.home)
        self.assertIn("FeatureTile(", self.home)

    # ── role-gated navigation ─────────────────────────────────
    def test_mezmur_dept_tabs_full(self):
        block = self.config.split("MEZMUR DEPARTMENT")[1].split("FALLBACK")[0]
        for tab in ["'home'", "'mezmur_attendance'", "'mezmur_hymns'",
                    "'mezmur_analytics'", "'profile'"]:
            self.assertIn(tab, block)

    def test_taker_gets_mezmur_attendance_only(self):
        block = self.config.split("ATTENDANCE TAKER")[1].split("EDUCATION")[0]
        self.assertIn("'mezmur_attendance'", block)
        self.assertNotIn("'mezmur_analytics'", block)
        self.assertNotIn("'mezmur_hymns'", block)

    def test_privacy_wipe_covers_mezmur_tables(self):
        wipe = self.db.split("clearAllUserData")[1]
        self.assertIn("'pending_mezmur',", wipe)
        self.assertIn("'cached_mezmur_sheet',", wipe)
        self.assertIn("'cached_mezmur_sections',", wipe)
        # Boundary (2026-08-28): hymns are shared library content —
        # they SURVIVE logout; only member/attendance data is wiped.
        self.assertNotIn("'pending_hymn_ops',\n", wipe.replace("'", ""))
        self.assertIn("Intentionally kept on logout", wipe)

    # ── api surface additions ─────────────────────────────────
    def test_api_service_hymn_readers(self):
        self.assertIn("Future<ApiResponse> getMezmurHymns(", self.api)
        self.assertIn("Future<ApiResponse> getMezmurHymn(int id)", self.api)
        self.assertIn("get('/mezmur/hymns'", self.api)
        self.assertIn("get('/mezmur/hymn'", self.api)


if __name__ == "__main__":
    unittest.main()
