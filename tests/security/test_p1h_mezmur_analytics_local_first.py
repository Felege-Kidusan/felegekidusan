"""P1-H Mezmur Analytics bounded last-view local-first static pins.

No Flutter/sqflite runtime harness is available in this sandbox, so this suite
pins the source contract: DB v33 singleton pair cache, SQLite-only restore on
open, explicitly user-triggered Analyze, radio fast-fail, canonical member/
section window and sessions-held validation, no partial pair install/write,
honest 100-row cap/result-window states, logout wipe, and zero server/outbox/
SyncService/F8 changes. Physical behavior remains a device gate.
"""

import os
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, 'Mobile', 'wbws_flutter_app')
LDB = os.path.join(APP, 'lib', 'services', 'local_db.dart')
ANALYTICS = os.path.join(APP, 'lib', 'screens', 'mezmur',
                         'mezmur_analytics.dart')
ATTENDANCE = os.path.join(APP, 'lib', 'screens', 'mezmur',
                          'mezmur_attendance.dart')
HOME = os.path.join(APP, 'lib', 'screens', 'mezmur', 'mezmur_home.dart')
SYNC = os.path.join(APP, 'lib', 'services', 'sync_service.dart')
API = os.path.join(APP, 'lib', 'services', 'api_service.dart')
CONFIG = os.path.join(APP, 'lib', 'utils', 'config.dart')
ROUTE = os.path.join(ROOT, 'api', 'v1', 'routes', 'mezmur.php')
SERVICE = os.path.join(ROOT, 'admin', 'backend', 'services',
                       'MezmurAttendanceService.php')
ADMIN_API = os.path.join(ROOT, 'admin', 'api_mezmur.php')


def read(path):
    with open(path, encoding='utf-8') as f:
        return f.read()


def method_body(src, signature):
    i = src.find(signature)
    assert i >= 0, signature
    body_start = src.find(' async {', i)
    assert body_start > i, signature
    j = src.find('\n  }', body_start)
    assert j > body_start, signature
    return src[i:j]


class P1HDatabase(unittest.TestCase):
    def setUp(self):
        self.ldb = read(LDB)

    def test_version_bumped_v32_to_v33(self):
        self.assertIn('version: 33,', self.ldb)
        self.assertNotIn('version: 32,', self.ldb)
        self.assertNotIn('version: 34', self.ldb)

    def test_additive_migration_and_fresh_install_wiring(self):
        self.assertIn('if (oldVersion < 33)', self.ldb)
        self.assertIn('await _createMezmurAnalyticsTable(db);', self.ldb)
        self.assertNotIn('if (oldVersion < 34)', self.ldb)
        create = method_body(self.ldb, 'Future<void> _createTables(')
        self.assertIn('await _createMezmurAnalyticsTable(db);', create)
        self.assertIn('if (oldVersion < 32)', self.ldb)
        self.assertIn('await _createEduTeachersTables(db);', self.ldb)

    def test_singleton_schema_exact_fields(self):
        self.assertIn(
            'CREATE TABLE IF NOT EXISTS cached_mezmur_analytics_last',
            self.ldb)
        for column in (
            'id INTEGER PRIMARY KEY CHECK (id = 1)',
            'from_date TEXT NOT NULL',
            'to_date TEXT NOT NULL',
            'sessions_held INTEGER NOT NULL DEFAULT 0',
            'members_response_json TEXT NOT NULL',
            'sections_response_json TEXT NOT NULL',
            'fetched_at TEXT NOT NULL',
        ):
            self.assertIn(column, self.ldb)

    def test_logout_wipes_sensitive_analytics(self):
        wipe = method_body(self.ldb, 'Future<void> clearAllUserData(')
        self.assertIn("'cached_mezmur_analytics_last'", wipe)
        # It must never receive the shared hymn library exemption.
        exemption = wipe[wipe.find('Intentionally kept on logout'):]
        self.assertNotIn('cached_mezmur_analytics_last', exemption)

    def test_existing_mezmur_caches_and_outbox_not_reused(self):
        screen = read(ANALYTICS)
        for protected in ('cached_mezmur_sheet', 'cached_mezmur_sections',
                          'cached_mezmur_days', 'cached_members',
                          'pending_mezmur'):
            self.assertNotIn(protected, screen)
        for existing in ('CREATE TABLE cached_mezmur_sheet',
                         'CREATE TABLE cached_mezmur_sections',
                         'CREATE TABLE IF NOT EXISTS cached_mezmur_days',
                         'CREATE TABLE pending_mezmur'):
            self.assertIn(existing, self.ldb)

    def test_one_row_write_is_atomic_pair_replace(self):
        write = method_body(self.ldb,
                            'Future<void> cacheMezmurAnalyticsLast(')
        self.assertIn("'cached_mezmur_analytics_last'", write)
        self.assertIn("'id': 1", write)
        self.assertIn("'members_response_json': jsonEncode(membersResponse)",
                      write)
        self.assertIn("'sections_response_json': jsonEncode(sectionsResponse)",
                      write)
        self.assertIn('ConflictAlgorithm.replace', write)
        self.assertNotIn('cached_mezmur_sheet', write)

    def test_reader_rejects_corrupt_or_mixed_pair(self):
        body = method_body(self.ldb,
                           'Future<Map<String, dynamic>?> '
                           'getCachedMezmurAnalyticsLast(')
        for token in ("members['items'] is! List",
                      "sections['items'] is! List",
                      "strictInt(members['page']) != 1",
                      'memberItems.length > 100',
                      "members['sessions_held']",
                      "sections['sessions_held']",
                      "memberWindow['from'] != fromDate",
                      "sectionWindow['to'] != toDate"):
            self.assertIn(token, body)
        self.assertIn('catch (_)', body)
        self.assertIn('return null;', body)


class P1HRestoreAndTriggerPolicy(unittest.TestCase):
    def setUp(self):
        self.src = read(ANALYTICS)
        self.restore = method_body(self.src, 'Future<void> _restoreLastView()')
        self.run = method_body(self.src, 'Future<void> _run()')

    def test_initstate_restores_sqlite_only(self):
        init_start = self.src.find('void initState()')
        init_end = self.src.find('\n  }', init_start)
        init = self.src[init_start:init_end]
        self.assertIn('_restoreLastView()', init)
        self.assertNotIn('_run()', init)
        self.assertNotIn('_api.', init)
        self.assertIn('getCachedMezmurAnalyticsLast(', self.restore)
        self.assertNotIn('_api.', self.restore)

    def test_missing_cache_preserves_no_analysis_state(self):
        self.assertIn('if (cached == null)', self.restore)
        self.assertIn('_restoring = false', self.restore)
        self.assertIn("title: 'No analysis yet'", self.src)

    def test_cached_open_restores_canonical_window_and_pair(self):
        install_start = self.src.find('void _installCached(')
        install_end = self.src.find('\n  }', install_start)
        install = self.src[install_start:install_end]
        for token in ("cached['from_date']", "cached['to_date']",
                      "cached['members_response']",
                      "cached['sections_response']",
                      '_resultFrom = from', '_resultTo = to',
                      '_hasRun = true'):
            self.assertIn(token, install)

    def test_analytics_remains_explicitly_user_triggered(self):
        self.assertIn(
            'onPressed: (_running || _restoring) ? null : _run', self.src)
        self.assertIn("label: const Text('Analyze')", self.src)
        self.assertNotIn('statusStream', self.src)
        self.assertNotIn('AppLifecycleState.resumed', self.src)
        self.assertNotIn('WidgetsBindingObserver', self.src)

    def test_pull_and_public_refresh_require_existing_view(self):
        self.assertIn('void refresh()', self.src)
        refresh_start = self.src.find('void refresh()')
        refresh_end = self.src.find('\n  }', refresh_start)
        self.assertIn('if (_hasRun) _run();',
                      self.src[refresh_start:refresh_end])
        self.assertIn('if (_hasRun) await _run();', self.src)

    def test_offline_fast_fail_precedes_http(self):
        offline = self.run.find('if (!ConnectivityService().hasLink)')
        member_call = self.run.find('getMezmurAnalytics(')
        self.assertGreaterEqual(offline, 0)
        self.assertLess(offline, member_call)
        self.assertIn('return;', self.run[offline:member_call])
        self.assertIn('no analysis is saved on this phone', self.run)
        self.assertIn('selected window has not been analyzed', self.run)

    def test_single_flight_and_finally_reset(self):
        self.assertIn('if (_running) return;', self.run)
        self.assertIn('_running = true;', self.run)
        self.assertIn('} finally {', self.run)
        self.assertIn('_running = false',
                      self.run[self.run.find('} finally {'):])

    def test_cached_data_stays_visible_during_refresh_and_failure(self):
        self.assertIn('if (_running && !_hasRun)', self.src)
        self.assertIn('if (_error != null && !_hasRun)', self.src)
        self.assertIn('if (_error != null) _statusBanner(_error!)', self.src)
        self.assertIn('if (_running)', self.src)
        self.assertIn('LinearProgressIndicator', self.src)


class P1HPairedResponse(unittest.TestCase):
    def setUp(self):
        self.src = read(ANALYTICS)
        self.run = method_body(self.src, 'Future<void> _run()')
        self.parse = self.src[self.src.find(
            '_AnalyticsPayload _parsePayload('):self.src.find(
                '\n  bool _validIsoDate', self.src.find(
                    '_AnalyticsPayload _parsePayload('))]

    def test_member_request_is_bounded_page_one(self):
        self.assertIn("'page': '1'", self.run)
        self.assertIn("'per_page': '$_memberLimit'", self.run)
        self.assertIn('static const int _memberLimit = 100;', self.src)
        self.assertIn("_strictInt(raw['page']) != 1", self.parse)
        self.assertIn('items.length > _memberLimit', self.parse)

    def test_member_payload_validated_before_sections(self):
        member_parse = self.run.find("label: 'member analytics'")
        sections_call = self.run.find("'/mezmur/analytics/sections'")
        self.assertGreater(member_parse, -1)
        self.assertGreater(sections_call, member_parse)
        for token in ("raw['items']", "raw['window']",
                      "raw['sessions_held']", 'items.any(',
                      '_validIsoDate(from)', '_validIsoDate(to)'):
            self.assertIn(token, self.parse)

    def test_sections_use_member_canonical_dates(self):
        section_call = self.run[self.run.find(
            "'/mezmur/analytics/sections'"):]
        self.assertIn("'from': members.from", section_call)
        self.assertIn("'to': members.to", section_call)
        self.assertNotIn("if (_from.isNotEmpty) 'from'", section_call)

    def test_pair_window_and_held_must_match(self):
        for token in ('members.from != sections.from',
                      'members.to != sections.to',
                      'members.sessionsHeld != sections.sessionsHeld'):
            self.assertIn(token, self.run)
        self.assertIn('described different windows', self.run)

    def test_cache_write_occurs_only_after_both_validation(self):
        member_parse = self.run.find("label: 'member analytics'")
        section_parse = self.run.find("label: 'section analytics'")
        equality = self.run.find('members.from != sections.from')
        write = self.run.find('cacheMezmurAnalyticsLast(')
        self.assertLess(member_parse, section_parse)
        self.assertLess(section_parse, equality)
        self.assertLess(equality, write)
        before_write = self.run[:write]
        self.assertNotIn('_members =', before_write)
        self.assertNotIn('_sections =', before_write)

    def test_member_only_or_section_failure_never_writes(self):
        member_failure = self.run.find(
            'if (!memberRes.success || memberRes.data == null)')
        section_failure = self.run.find(
            'if (!sectionRes.success || sectionRes.data == null)')
        write = self.run.find('cacheMezmurAnalyticsLast(')
        self.assertLess(member_failure, section_failure)
        self.assertLess(section_failure, write)
        self.assertIn('throw _AnalyticsFailure',
                      self.run[member_failure:write])

    def test_rereads_verified_store_after_pair_write(self):
        write = self.run.find('cacheMezmurAnalyticsLast(')
        reread = self.run.find('getCachedMezmurAnalyticsLast(', write)
        install = self.run.find('_installCached(saved)', reread)
        self.assertGreater(reread, write)
        self.assertGreater(install, reread)

    def test_valid_empty_pair_is_not_rejected(self):
        self.assertNotIn('items.isEmpty', self.parse)
        self.assertNotIn('items.isNotEmpty', self.parse)
        self.assertIn("title: 'No members in this window'", self.src)


class P1HHonestyAndBoundaries(unittest.TestCase):
    def setUp(self):
        self.src = read(ANALYTICS)
        self.route = read(ROUTE)
        self.service = read(SERVICE)

    def test_result_window_and_selected_window_are_separate(self):
        for token in ('String? _resultFrom;', 'String? _resultTo;',
                      'bool get _selectionDiffers',
                      "const Text('Result window'",
                      'selected window has not been analyzed yet'):
            self.assertIn(token, self.src)

    def test_copy_is_honest_about_100_row_cap(self):
        self.assertIn("label: 'Members shown'", self.src)
        self.assertIn("'Member ranking (up to 100)'", self.src)
        self.assertIn('rank up to 100 active members', self.src)
        self.assertIn('Showing the first 100 rows returned by the server',
                      self.src)
        self.assertNotIn('rank every member', self.src)
        self.assertNotIn('decision view: every member', self.src)

    def test_server_cap_and_missing_total_are_pinned(self):
        self.assertIn('min($perPage, 100)', self.service)
        self.assertIn("'page' => $page", self.service)
        members_return = self.service[self.service.find(
            "return ['items' => $items, 'page' => $page"):
            self.service.find('\n    }', self.service.find(
                "return ['items' => $items, 'page' => $page"))]
        for absent in ('total', 'pages', 'has_more'):
            self.assertNotIn(absent, members_return)

    def test_exact_analytics_role_gate_preserved(self):
        self.assertIn(
            "$MEZMUR_ANALYTICS_ROLES = ['mezmur_dept', 'school_admin', 'super_admin'];",
            self.route)
        self.assertIn('if (!apiRoleIs($auth, $MEZMUR_ANALYTICS_ROLES))',
                      self.route)
        home = read(HOME)
        self.assertIn('role == UserRoles.mezmurDept', home)
        self.assertIn('role == UserRoles.schoolAdmin', home)
        self.assertIn('role == UserRoles.superAdmin', home)

    def test_mobile_route_pii_stripping_preserved(self):
        analytics = self.route[self.route.find(
            '// ── GET /mezmur/analytics[/sections]'):
            self.route.find('// ── GET /mezmur/hymns')]
        self.assertIn("unset($r['full_name_am'], $r['photo_url']);",
                      analytics)
        self.assertNotIn('phone_number', analytics)
        self.assertNotIn('address', analytics)

    def test_timeout_and_api_method_unchanged(self):
        config = read(CONFIG)
        self.assertIn('connectionTimeout = 45', config)
        self.assertIn('postTimeout = 60', config)
        api = read(API)
        self.assertIn('Future<ApiResponse> getMezmurAnalytics(', api)
        self.assertIn("get('/mezmur/analytics', params: params)", api)

    def test_no_sync_outbox_or_pending_overlay(self):
        for token in ('SyncService', 'pending_mezmur', 'saveMezmurSheet',
                      'enqueue', 'outbox'):
            self.assertNotIn(token, self.src)
        sync = read(SYNC)
        self.assertNotIn('cached_mezmur_analytics_last', sync)
        attendance = read(ATTENDANCE)
        self.assertNotIn('cached_mezmur_analytics_last', attendance)

    def test_server_and_web_contract_files_need_no_p1h_change(self):
        # Source pins: P1-H consumes the existing pair exactly; no new route,
        # service method, web action, or SQL table is part of the design.
        self.assertIn('MezmurAttendanceService::analyticsMembers($conn, $_GET)',
                      self.route)
        self.assertIn('MezmurAttendanceService::analyticsSections($conn, $_GET)',
                      self.route)
        self.assertIn("case 'analytics_members'", read(ADMIN_API))
        self.assertIn("case 'analytics_sections'", read(ADMIN_API))
        self.assertNotIn('p1h', self.route.lower())
        self.assertNotIn('p1h', self.service.lower())


if __name__ == '__main__':
    unittest.main()
