"""P1-G Education Teachers list + detail local-first static pins.

The sandbox has no Flutter/sqflite runtime harness, so these tests pin the
source-level safety contract: DB v32 dedicated tables, a complete validated
server-page crawl before one atomic snapshot replacement, server sort-order
preservation, complete-cache local search with a 50-result display cap,
explicit academic-year scope, view-once local-first details, honest
failure/empty states, deletion/year invalidation, unchanged authorization,
and no interaction with protected workflow caches, outboxes, SyncService or
F8. Device behavior remains a physical-device gate.
"""

import glob
import os
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
APP = os.path.join(ROOT, 'Mobile', 'wbws_flutter_app')
LDB = os.path.join(APP, 'lib', 'services', 'local_db.dart')
TEACHERS = os.path.join(APP, 'lib', 'screens', 'edu_dept',
                        'edu_teachers_screen.dart')
APISVC = os.path.join(APP, 'lib', 'services', 'api_service.dart')
CONFIG = os.path.join(APP, 'lib', 'utils', 'config.dart')
SYNC = os.path.join(APP, 'lib', 'services', 'sync_service.dart')
TEACHERS_PHP = os.path.join(ROOT, 'api', 'v1', 'routes', 'teachers.php')
ACL = os.path.join(ROOT, 'api', 'v1', 'core', 'acl.php')
ADMIN_TEACHERS = os.path.join(ROOT, 'admin', 'api_teachers.php')
SQLDIR = os.path.join(ROOT, 'sql')
EDU = os.path.join(APP, 'lib', 'screens', 'edu_dept')

EXCLUDED_SCREENS = (
    os.path.join(EDU, 'edu_enrollment_screen.dart'),
    os.path.join(EDU, 'edu_classes_screen.dart'),
    os.path.join(EDU, 'edu_subjects_screen.dart'),
    os.path.join(EDU, 'edu_home.dart'),
)


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


class P1GDatabase(unittest.TestCase):
    def setUp(self):
        self.ldb = read(LDB)

    def test_version_tracks_current(self):
        # P1-G introduced v32. The current version is centralized in
        # the v34 schema contract without changing the teacher tables.
        self.assertIn('version: localDatabaseSchemaVersion,', self.ldb)
        self.assertNotIn('version: 31,', self.ldb)

    def test_additive_migration_and_fresh_install_wiring(self):
        self.assertIn('if (oldVersion < 32)', self.ldb)
        self.assertIn('await _createEduTeachersTables(db);', self.ldb)
        create = method_body(self.ldb, 'Future<void> _createTables(')
        self.assertIn('await _createEduTeachersTables(db);', create)
        self.assertIn('if (oldVersion < 31)', self.ldb)
        self.assertIn('await _createEduSubjectsTable(db);', self.ldb)

    def test_three_dedicated_tables_and_order_index(self):
        for table in ('cached_edu_teacher_snapshot',
                      'cached_edu_teachers',
                      'cached_edu_teacher_details'):
            self.assertIn(f'CREATE TABLE IF NOT EXISTS {table}', self.ldb)
        for column in (
            'id INTEGER PRIMARY KEY CHECK (id = 1)',
            'academic_year_id INTEGER NOT NULL DEFAULT 0',
            'total INTEGER NOT NULL DEFAULT 0',
            'sort_order INTEGER NOT NULL',
            'data_json TEXT NOT NULL',
            'PRIMARY KEY (teacher_id, academic_year_id)',
        ):
            self.assertIn(column, self.ldb)
        self.assertIn('idx_cached_edu_teachers_sort', self.ldb)

    def test_logout_wipes_all_teacher_read_model_tables(self):
        wipe = method_body(self.ldb, 'Future<void> clearAllUserData(')
        for table in ('cached_edu_teacher_snapshot',
                      'cached_edu_teachers',
                      'cached_edu_teacher_details'):
            self.assertIn(f"'{table}'", wipe)

    def test_migration_is_additive_and_has_no_server_sql(self):
        for table in ('cached_edu_teacher_snapshot',
                      'cached_edu_teachers',
                      'cached_edu_teacher_details'):
            self.assertNotIn(f'ALTER TABLE {table}', self.ldb)
            self.assertNotIn(f'DROP TABLE {table}', self.ldb)
        self.assertEqual(glob.glob(os.path.join(SQLDIR, '*p1g*')), [])
        for path in glob.glob(os.path.join(SQLDIR, '*.sql')):
            self.assertNotIn('cached_edu_teacher', read(path), path)

    def test_protected_existing_caches_are_not_reused(self):
        screen = read(TEACHERS)
        for protected in ('cached_members', 'cached_classes',
                          'cached_students', 'cached_subjects'):
            self.assertNotIn(protected, screen)
        # Their historical DDLs remain present and separate.
        self.assertIn('CREATE TABLE cached_classes', self.ldb)
        self.assertIn('CREATE TABLE cached_students', self.ldb)
        self.assertIn('CREATE TABLE cached_subjects', self.ldb)
        self.assertIn('CREATE TABLE IF NOT EXISTS cached_members', self.ldb)


class P1GCompleteListFlow(unittest.TestCase):
    def setUp(self):
        self.src = read(TEACHERS)
        self.load = method_body(self.src, 'Future<void> _load()')
        self.crawl = method_body(
            self.src, 'Future<_TeacherCrawl> _crawlCompleteDirectory()')
        self.ldb = read(LDB)

    def test_local_snapshot_and_rows_precede_network(self):
        local = self.load.find('getCachedEduTeacherSnapshot(')
        rows = self.load.find('getCachedEduTeachers(')
        network = self.load.find('_crawlCompleteDirectory(')
        self.assertGreaterEqual(local, 0)
        self.assertLess(local, network)
        self.assertLess(rows, network)

    def test_offline_fast_fail_skips_network(self):
        offline = self.load.find('if (offline) {')
        network = self.load.find('_crawlCompleteDirectory(')
        self.assertIn('!ConnectivityService().hasLink', self.load)
        self.assertGreater(offline, -1)
        self.assertLess(offline, network)
        self.assertIn('return; // radio fast-fail', self.load)
        self.assertIn('no teachers are cached yet', self.load)

    def test_page_one_is_not_assumed_complete(self):
        self.assertIn('while (true)', self.crawl)
        self.assertIn('page: page', self.crawl)
        self.assertIn('limit: _serverPageSize', self.crawl)
        self.assertIn('if (page >= pages) break;', self.crawl)
        self.assertIn('page++;', self.crawl)
        self.assertIn('static const int _serverPageSize = 50;', self.src)
        # The crawl is deliberately unfiltered: no search argument.
        get_call = self.crawl[self.crawl.find('_api.getTeachers('):]
        get_call = get_call[:get_call.find(');')]
        self.assertNotIn('search:', get_call)

    def test_pagination_is_strictly_validated(self):
        for token in ("pagination['page']", "pagination['total']",
                      "pagination['limit']", "pagination['pages']",
                      "pagination['has_more']", 'responsePage != page',
                      'total != expectedTotal', 'pages != expectedPages',
                      'limit != expectedLimit',
                      'rows.length != expectedTotal'):
            self.assertIn(token, self.crawl)
        # Current response helper truth: valid empty has pages=0.
        self.assertIn('(total == 0 && pages != 0)', self.crawl)
        self.assertIn('(total > 0 && pages !=', self.crawl)

    def test_ids_are_valid_and_deduplicated(self):
        self.assertIn('final seenIds = <int>{};', self.crawl)
        self.assertIn('!seenIds.add(id)', self.crawl)
        self.assertIn('id == null || id <= 0', self.crawl)

    def test_year_is_explicit_and_stable_across_pages(self):
        for token in ("containsKey('academic_year_id')",
                      "containsKey('academic_year_name')",
                      "item['academic_year_id']",
                      'rowYear != academicYearId',
                      'rowYearName != academicYearName'):
            self.assertIn(token, self.crawl)
        self.assertNotIn('DateTime.now().year', self.src)

    def test_no_partial_snapshot_write(self):
        crawl_call = self.load.find('await _crawlCompleteDirectory()')
        replace = self.load.find('replaceCachedEduTeachers(')
        self.assertGreater(crawl_call, -1)
        self.assertGreater(replace, crawl_call)
        # Crawler itself never writes; only returns validated rows.
        self.assertNotIn('replaceCachedEduTeachers(', self.crawl)
        self.assertNotIn('cacheEduTeacher', self.crawl)

    def test_atomic_replace_and_invalidation(self):
        replace = method_body(self.ldb,
                              'Future<void> replaceCachedEduTeachers(')
        self.assertIn('db.transaction', replace)
        self.assertIn("txn.delete('cached_edu_teachers')", replace)
        self.assertIn("txn.insert('cached_edu_teachers'", replace)
        self.assertIn("'sort_order': i", replace)
        self.assertIn("'cached_edu_teacher_snapshot'", replace)
        self.assertIn("txn.delete('cached_edu_teacher_details'", replace)
        self.assertIn("where: 'academic_year_id != ?'", replace)
        self.assertIn('WHERE NOT EXISTS', replace)
        self.assertIn('cached_edu_teacher_details.teacher_id', replace)

    def test_success_rereads_committed_store(self):
        replace = self.load.find('replaceCachedEduTeachers(')
        snapshot = self.load.find('getCachedEduTeacherSnapshot(', replace)
        local = self.load.find('getCachedEduTeachers(', replace)
        self.assertGreater(snapshot, replace)
        self.assertGreater(local, replace)

    def test_failure_keeps_complete_cache_visible(self):
        self.assertIn('(_error != null && !_hasSnapshot)', self.src)
        self.assertIn('if (_error != null && _hasSnapshot)', self.src)
        self.assertIn('— showing cached teachers', self.src)
        self.assertIn('Could not refresh teachers.', self.load)

    def test_search_is_local_complete_cache_with_display_cap(self):
        local_search = method_body(
            self.ldb,
            'Future<List<Map<String, dynamic>>> getCachedEduTeachers(')
        self.assertIn("'(full_name LIKE ? OR username LIKE ?)'", local_search)
        self.assertIn("orderBy: 'sort_order ASC'", local_search)
        self.assertIn('limit: limit', local_search)
        self.assertIn('static const int _displayLimit = 50;', self.src)
        apply_search = method_body(self.src, 'Future<void> _applySearch()')
        self.assertIn('getCachedEduTeachers(', apply_search)
        self.assertIn('limit: _displayLimit', apply_search)
        self.assertIn('onSubmitted: (_) => _applySearch()', self.src)

    def test_refresh_triggers_and_single_flight(self):
        self.assertIn('statusStream', self.src)
        self.assertIn('Duration(seconds: 1)', self.src)
        self.assertIn('AppLifecycleState.resumed', self.src)
        self.assertIn('onRefresh: _load', self.src)
        self.assertIn('if (_refreshing) return;', self.load)
        self.assertIn('} finally {', self.load)
        self.assertIn('_refreshing = false;',
                      self.load[self.load.find('} finally {'):])

    def test_server_counts_and_payload_are_stored_verbatim(self):
        replace = method_body(self.ldb,
                              'Future<void> replaceCachedEduTeachers(')
        self.assertIn("'assigned_classes': _asIntLocal(m['assigned_classes'])",
                      replace)
        self.assertIn("'assigned_subjects': _asIntLocal(m['assigned_subjects'])",
                      replace)
        self.assertIn("'data_json': jsonEncode(m)", replace)
        self.assertNotIn('COUNT(', replace)
        self.assertNotIn('SUM(', replace)


class P1GDetailFlow(unittest.TestCase):
    def setUp(self):
        self.src = read(TEACHERS)
        self.detail = method_body(
            self.src, 'Future<void> _load()',
        )
        # First _load belongs to the list; select the second explicitly.
        detail_class = self.src.find('class _TeacherDetailSheetState')
        self.detail_src = self.src[detail_class:]
        self.detail = method_body(self.detail_src, 'Future<void> _load()')
        self.ldb = read(LDB)

    def test_detail_cache_identity_is_teacher_plus_year(self):
        self.assertIn('PRIMARY KEY (teacher_id, academic_year_id)', self.ldb)
        read_detail = method_body(
            self.ldb, 'Future<Map<String, dynamic>?> getCachedEduTeacherDetail(')
        self.assertIn("where: 'teacher_id = ? AND academic_year_id = ?'",
                      read_detail)
        cache = method_body(self.ldb,
                            'Future<void> cacheEduTeacherDetail(')
        self.assertIn("'teacher_id': _asIntLocal(detail['id'])", cache)
        self.assertIn("'academic_year_id': academicYearId", cache)

    def test_detail_reads_local_before_server(self):
        self.assertLess(self.detail.find('getCachedEduTeacherDetail('),
                        self.detail.find('getTeacher('))
        self.assertIn('widget.teacherId, widget.academicYearId', self.detail)

    def test_detail_offline_uncached_is_honest(self):
        self.assertIn('these assignments are not saved on this phone',
                      self.detail)
        self.assertIn('return;', self.detail[self.detail.find('if (offline) {'):])
        self.assertIn('Assignments are not available.', self.detail_src)

    def test_valid_detail_requires_id_list_and_year_metadata(self):
        for token in ("detail['assignments'] is! List",
                      "containsKey('academic_year_id')",
                      "containsKey('academic_year_name')",
                      'returnedId != widget.teacherId'):
            self.assertIn(token, self.detail)

    def test_failed_detail_never_becomes_valid_empty(self):
        failure = self.detail.find('if (!res.success || res.data == null)')
        assignment_validation = self.detail.find(
            "detail['assignments'] is! List")
        cache = self.detail.find('cacheEduTeacherDetail(')
        self.assertGreaterEqual(failure, 0)
        self.assertGreater(assignment_validation, failure)
        self.assertGreater(cache, assignment_validation)
        failure_branch = self.detail[failure:assignment_validation]
        self.assertIn('_error =', failure_branch)
        self.assertNotIn('_detail =', failure_branch)
        self.assertNotIn('assignments = []', self.detail)
        self.assertNotIn('No class assignments this year.', self.src)

    def test_valid_empty_is_rendered_for_explicit_year(self):
        self.assertIn('No class assignments for $_yearLabel.', self.detail_src)
        self.assertIn("detail['assignments'] is! List", self.detail)
        # A valid empty List reaches the cache; there is no isNotEmpty guard.
        cache_at = self.detail.find('cacheEduTeacherDetail(')
        self.assertGreater(cache_at, -1)
        self.assertNotIn('assignments.isNotEmpty', self.detail[:cache_at])

    def test_detail_cache_is_view_once_not_prefetched(self):
        list_class = self.src[:self.src.find('class _TeacherDetailSheet')]
        self.assertNotIn('getTeacher(', list_class)
        self.assertIn('getTeacher(widget.teacherId)', self.detail)
        self.assertEqual(self.src.count('cacheEduTeacherDetail('), 1)


class P1GServerAndBoundaries(unittest.TestCase):
    def setUp(self):
        self.php = read(TEACHERS_PHP)
        self.src = read(TEACHERS)
        self.ldb = read(LDB)

    def test_authorization_semantics_are_exactly_preserved(self):
        self.assertIn('apiRequireAuth();', self.php)
        self.assertIn('apiRequireRole($auth, apiRolesEducation());', self.php)
        acl = read(ACL)
        self.assertIn("return ['edu_dept', 'school_admin', 'super_admin'];",
                      acl)
        self.assertNotIn("array_merge(apiRolesEducation(), ['teacher'])",
                         self.php)

    def test_only_additive_year_fields_use_existing_resolver(self):
        self.assertIn('$year = getCurrentAcademicYear();', self.php)
        self.assertIn("'academic_year_id' => $yearId > 0 ? $yearId : null",
                      self.php)
        self.assertIn("'academic_year_name' => $year['year_name'] ?? null",
                      self.php)
        # Exactly one list literal + one detail assignment per field.
        self.assertEqual(self.php.count("'academic_year_id' =>"), 1)
        self.assertEqual(self.php.count("'academic_year_name' =>"), 1)
        self.assertEqual(self.php.count("$teacher['academic_year_id'] ="), 1)
        self.assertEqual(self.php.count("$teacher['academic_year_name'] ="), 1)
        self.assertIn("u.role = 'teacher'", self.php)
        self.assertIn('u.is_active = 1', self.php)

    def test_no_sensitive_fields_enter_mobile_cache_contract(self):
        helper_start = self.ldb.find(
            'Future<void> _createEduTeachersTables(Database db)')
        helper_end = self.ldb.find('Future<void> _createTables(', helper_start)
        schema = self.ldb[helper_start:helper_end]
        for sensitive in ('email', 'phone', 'password', 'member_id',
                          'address'):
            self.assertNotIn(sensitive, schema)
        route_list = self.php[self.php.find("if ($method === 'GET' && $id === null)"):
                              self.php.find("if ($method === 'GET' && $id !== null")]
        for sensitive in ('password_hash', 'phone_number', 'address'):
            self.assertNotIn(sensitive, route_list)

    def test_timeout_and_api_signatures_unchanged(self):
        config = read(CONFIG)
        self.assertIn('connectionTimeout = 45', config)
        self.assertIn('postTimeout = 60', config)
        api = read(APISVC)
        self.assertIn(
            'Future<ApiResponse> getTeachers({int page = 1, int limit = 50, String? search})',
            api)
        self.assertIn("Future<ApiResponse> getTeacher(int id) => get('/teachers/$id');",
                      api)

    def test_no_outbox_syncservice_or_f8_work(self):
        combined = self.src + self.ldb
        for token in ('pending_teachers', 'pending_teacher',
                      'teacher_outbox', 'enqueueTeacher'):
            self.assertNotIn(token, combined)
        self.assertNotIn('SyncService', self.src)
        self.assertNotIn('sync_service.dart', self.src)
        sync = read(SYNC)
        self.assertNotIn('cached_edu_teachers', sync)
        self.assertNotIn('cached_edu_teacher_details', sync)

    def test_teacher_writes_remain_website_only(self):
        api = read(APISVC)
        for write_method in ('createTeacher(', 'updateTeacher(',
                             'deleteTeacher(', 'assignTeacher('):
            self.assertNotIn(write_method, api)
        admin = read(ADMIN_TEACHERS)
        for action in ('create_teacher', 'update_teacher', 'toggle_status',
                       'delete_teacher', 'add_assignment',
                       'remove_assignment', 'save_teacher_bundle'):
            self.assertIn(action, admin)
        self.assertIn('stay on the website', self.src)

    def test_unrelated_education_screens_unchanged_by_contract(self):
        # P1-G only imports/links its own screen; it must not couple to sibling
        # implementation classes or their caches.
        for path in EXCLUDED_SCREENS:
            self.assertTrue(os.path.exists(path), path)
        for token in ('EduEnrollmentScreen', 'EduClassesScreen',
                      'EduSubjectsScreen', 'Mezmur'):
            self.assertNotIn(token, self.src)


if __name__ == '__main__':
    unittest.main()
