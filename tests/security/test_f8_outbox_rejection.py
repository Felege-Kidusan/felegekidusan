"""F8 — critical data-loss fix: a 409 must NEVER mark an offline op
synced unless the response definitively establishes it was applied or
validly replayed.

Root cause being pinned shut: SyncService._accepted() treated every
409 as sync success, so a teacher's offline attendance/grades were
silently discarded + falsely reported synced when Education/admin
submitted that day/test first.

These are static source pins (the sandbox has no Flutter/PHP runtimes;
the Dart-behavior pins live in test/drain_outcome_test.dart, which the
user runs locally with `flutter test`).
"""
import re
import unittest

ROOT = __file__.rsplit('/tests/', 1)[0]
MW = f'{ROOT}/api/v1/core/middleware.php'
RESP = f'{ROOT}/api/v1/core/response.php'
ATT = f'{ROOT}/api/v1/routes/attendance.php'
GRA = f'{ROOT}/api/v1/routes/grades.php'
MEZ = f'{ROOT}/api/v1/routes/mezmur.php'
HR = f'{ROOT}/api/v1/routes/hr.php'
SYNC = (f'{ROOT}/Mobile/wbws_flutter_app/lib/services/sync_service.dart')
LDB = (f'{ROOT}/Mobile/wbws_flutter_app/lib/services/local_db.dart')
BANNER = (f'{ROOT}/Mobile/wbws_flutter_app/lib/widgets/sync_attention.dart')
SCREENS = {
    'attendance': (f'{ROOT}/Mobile/wbws_flutter_app/lib/screens/attendance/'
                   'attendance_screen.dart'),
    'grades': (f'{ROOT}/Mobile/wbws_flutter_app/lib/screens/teacher/'
               'teacher_grades.dart'),
    'mezmur': (f'{ROOT}/Mobile/wbws_flutter_app/lib/screens/mezmur/'
               'mezmur_attendance.dart'),
    'hr': (f'{ROOT}/Mobile/wbws_flutter_app/lib/screens/hr/'
           'hr_attendance.dart'),
}


def read(path):
    with open(path, encoding='utf-8') as fh:
        return fh.read()


class F8ServerCodes(unittest.TestCase):
    """Every outbox-reachable 409 carries a machine-readable code via
    err()'s $extra (the existing convention), so the client never has
    to sniff message text."""

    def setUp(self):
        self.mw = read(MW)
        self.att = read(ATT)
        self.gra = read(GRA)
        self.mez = read(MEZ)
        self.hr = read(HR)
        self.resp = read(RESP)

    def test_err_extra_merge_is_the_transport(self):
        # response.php merges $extra into the JSON body — the `code`
        # field rides the existing convention, no new mechanism.
        self.assertIn('array_merge($response, $extra)', self.resp)

    def test_middleware_conflict_and_processing_are_distinguishable(self):
        self.assertIn("'code' => 'IDEMPOTENCY_CONFLICT'", self.mw)
        self.assertIn("'code' => 'IDEMPOTENCY_IN_PROGRESS'", self.mw)

    def test_middleware_replay_returns_original_status(self):
        # A replay is the ORIGINAL (usually 200) status + body +
        # Idempotency-Replayed header — a 409 is never a replay, and
        # response-loss retries stay on the success path (F8 §11).
        self.assertIn("http_response_code((int)($result['status_code'] ?? 200))",
                      self.mw)
        self.assertIn("'Idempotency-Replayed: true'", self.mw)

    def test_attendance_locks_and_domain_rejections(self):
        self.assertEqual(
            self.att.count("['code' => 'ALREADY_SUBMITTED']"), 2,
            'save + submit locks must be machine-rejectable')
        self.assertEqual(
            self.att.count("['code' => 'WORKFLOW_REJECTED']"), 2,
            'DomainException catches (save + submit) must be coded')

    def test_grades_locks_and_marklist_failure(self):
        self.assertEqual(
            self.gra.count("['code' => 'ALREADY_SUBMITTED']"), 2,
            'save-lock + submit-lock must be machine-rejectable')
        self.assertEqual(
            self.gra.count("['code' => 'WORKFLOW_REJECTED']"), 1,
            'upsertMarklist workflow failure must be coded')
        # grades 885 is a review action (online-only, never drained):
        # it must NOT have been dragged into the outbox scheme.
        self.assertNotIn("['code' => 'ALREADY_SUBMITTED', 'review']", self.gra)

    def test_mezmur_lock_and_domain_rejection(self):
        self.assertEqual(
            self.mez.count("['code' => 'ALREADY_SUBMITTED']"), 1)
        self.assertEqual(
            self.mez.count("['code' => 'WORKFLOW_REJECTED']"), 1)
        # The hymn_save 409 conflict (data.item server-wins protocol,
        # handled by hymn_store.dart) must stay untouched by F8.
        hymn_conflict = re.search(
            r"err\([^\n]*already (?:saved|exists)[^\n]*409[^\n]*\);",
            self.mez)
        if hymn_conflict:
            self.assertNotIn('code', hymn_conflict.group(0),
                             'hymn conflict protocol is out of F8 scope')

    def test_hr_lock_and_domain_rejections(self):
        self.assertEqual(
            self.hr.count("['code' => 'ALREADY_SUBMITTED']"), 1)
        self.assertEqual(
            self.hr.count("['code' => 'WORKFLOW_REJECTED']"), 2,
            'save-route catch + global catch both drain-reachable')


class F8ClientClassifier(unittest.TestCase):
    """The buggy 409-is-success heuristic is gone; classification is
    explicit and evidence-based."""

    def setUp(self):
        self.s = read(SYNC)

    def test_the_bug_is_dead(self):
        self.assertNotIn('bool _accepted(', self.s)
        self.assertNotIn('if (res.statusCode == 409) return true;', self.s)
        self.assertNotIn("m.contains('already submitted')", self.s)

    def test_classifier_exists_and_is_pure(self):
        self.assertIn('enum DrainOutcome {', self.s)
        self.assertIn('DrainOutcome classifyDrainResponse(ApiResponse res) {',
                      self.s)

    def test_replay_invariant_is_success_only(self):
        # accepted == res.success — the only path that can mark synced.
        # A true replay returns the original 200, so it lands here.
        self.assertIn('if (res.success) return DrainOutcome.accepted;',
                      self.s)

    def test_409_taxonomy(self):
        # definitive codes → rejected; in-progress → transient;
        # unknown 409 → transient (never guessed, never success)
        for code in ('ALREADY_SUBMITTED', 'WORKFLOW_REJECTED',
                     'IDEMPOTENCY_CONFLICT'):
            self.assertIn(f"code == '{code}'", self.s)
        self.assertRegex(
            self.s,
            r"code == 'IDEMPOTENCY_IN_PROGRESS'\)"
            r"[^;]*;[\s\S]*?DrainOutcome\.transient")
        self.assertRegex(
            self.s, r"return DrainOutcome\.transient; // unknown 409")

    def test_transient_statuses(self):
        self.assertRegex(
            self.s,
            r'status == 401 \|\| status == 408 \|\| status == 429'
            r' \|\| status >= 500')

    def test_definite_protocol_refusals_are_rejected(self):
        # minimal F9 touch (§9): 400/403/404/422 must not retry forever
        self.assertRegex(
            self.s,
            r'status == 400 \|\| status == 403 \|\| status == 404'
            r' \|\| status == 422')

    def test_all_four_drains_classify_instead_of_accept(self):
        self.assertEqual(self.s.count('classifyDrainResponse(res)'), 4)
        self.assertEqual(self.s.count('DrainOutcome.accepted'), 5,
                         '4 loops + the classifier return')

    def test_all_four_drains_skip_rejected_batches(self):
        self.assertEqual(self.s.count("batch['rejected'] == 1"), 4)

    def test_all_four_drains_mark_rejected_with_reason(self):
        for call in ('rejectAttendance(', 'rejectGrades(', 'rejectMezmur(',
                     'rejectHr('):
            self.assertIn(call, self.s)
        # the engine records the verdict distinctly from errors
        self.assertEqual(self.s.count(", 'rejected');"), 4)

    def test_synced_only_on_accepted(self):
        # Each mark*Synced call sits inside its loop's accepted arm.
        s = self.s
        self.assertEqual(s.count('DrainOutcome.accepted) {'), 4)
        for call in ('markAttendanceSynced', 'markGradesSynced',
                     'markMezmurSynced', 'markHrSynced'):
            i = s.find(f'await _db.{call}(')
            self.assertGreater(i, -1, call)
            window = s[max(0, i - 400):i]
            self.assertIn('DrainOutcome.accepted', window,
                          f'{call} must run only in the accepted arm')


class F8LocalOutbox(unittest.TestCase):
    """Rejected batches: kept, flagged, recoverable, never
    auto-deleted; no schema migration (sync_error already exists)."""

    def setUp(self):
        self.db = read(LDB)

    def test_no_migration_was_added(self):
        # rejected state = synced=0 + sync_error NOT NULL on existing
        # columns: sync_error ships in the original CREATE TABLE of
        # every pending_* table — F8 adds no column, table, or SQL
        # migration file (046 remains the last one).
        self.assertNotIn('ADD COLUMN sync_error', self.db)
        import glob
        self.assertEqual(glob.glob(f'{ROOT}/sql/*f8*'), [])

    def test_all_four_feeds_carry_the_rejected_flag(self):
        self.assertEqual(
            self.db.count(
                'MAX(CASE WHEN sync_error IS NOT NULL THEN 1 ELSE 0 END)'
                ' as rejected'), 4)

    def test_feeds_do_not_filter_rejected_rows(self):
        # getPendingAttendance is dual-use (drain feed AND the
        # teacher_home overlay): hiding rejected rows in the query
        # would hide locally-taken attendance from the UI.
        for feed in ('getPendingAttendance()', 'getPendingGrades()',
                     'getPendingMezmur()', 'getPendingHr()'):
            body = self.db[self.db.find(feed):self.db.find(feed) + 700]
            self.assertNotIn('sync_error IS NULL', body,
                             f'{feed} must not filter rejected rows')

    def test_reject_methods_set_reason_on_unsynced_only(self):
        for call in ('rejectAttendance', 'rejectGrades', 'rejectMezmur',
                     'rejectHr'):
            body = self.db[self.db.find(f'Future<void> {call}('):
                           self.db.find(f'Future<void> {call}(') + 420]
            self.assertIn("{'sync_error': reason}", body)
            self.assertIn('synced = 0', body)
            self.assertNotIn('synced = 1', body)

    def test_discard_requires_explicit_user_action(self):
        # discard* deletes ONLY unsynced rows and is only reachable
        # from the review sheet's confirmation dialog.
        for call in ('discardRejectedAttendance', 'discardRejectedGrades',
                     'discardRejectedMezmur', 'discardRejectedHr'):
            self.assertIn(f'Future<void> {call}(', self.db)
        # the sync engine must never discard
        self.assertNotIn('discardRejected', read(SYNC))

    def test_cleanup_only_removes_synced_rows(self):
        # rejected rows are never swept by housekeeping (data must
        # survive restarts until the user decides).
        for call in ('cleanupSyncedHr', 'cleanupSyncedMezmur',
                     'cleanupSynced'):
            body = self.db[self.db.find(f'Future<void> {call}('):
                           self.db.find(f'Future<void> {call}(') + 420]
            self.assertIn('synced = 1', body)
            self.assertNotIn('synced = 0', body)

    def test_get_rejected_batches_covers_all_four_outboxes(self):
        body = self.db[self.db.find('getRejectedBatches'):
                       self.db.find('getRejectedBatches') + 1600]
        for table in ('pending_attendance', 'pending_grades',
                      'pending_mezmur', 'pending_hr'):
            self.assertIn(table, body)
            self.assertIn('sync_error IS NOT NULL', body)
        self.assertIn('UNION ALL', body)

    def test_grades_outbox_now_has_an_idempotency_key(self):
        # getPendingGrades previously omitted client_op_id, so grades
        # drained with no key (safe only by upsert accident). F8 pins
        # the key into the feed.
        feed = self.db[self.db.find('getPendingGrades'):
                       self.db.find('getPendingGrades') + 500]
        self.assertIn('MAX(client_op_id) as client_op_id', feed)


class F8HonestUi(unittest.TestCase):
    """A refused packet is never reported as 'waiting for network' and
    is surfaced with its reason + an explicit Discard (§10)."""

    def setUp(self):
        self.banner = read(BANNER)

    def test_banner_and_sheet_exist(self):
        self.assertIn('class SyncAttentionBanner', self.banner)
        self.assertIn('showSyncRejectedSheet', self.banner)

    def test_discard_is_gated_by_confirmation(self):
        self.assertIn("'Discard this sheet?'", self.banner)
        self.assertIn('showDialog<bool>', self.banner)

    def test_sheet_says_data_is_not_on_the_server(self):
        self.assertIn('NOT on the server', self.banner)

    def test_status_counts_rejected_honestly(self):
        s = read(SYNC)
        self.assertIn('final int rejected;', s)
        self.assertIn("bool get needsAttention => rejected > 0;", s)
        self.assertIn("'$rejected need", s)  # breakdown callout
        self.assertIn('rejected: rejectedBatches.length,', s)

    def test_all_four_screens_wire_the_banner(self):
        for name, path in SCREENS.items():
            src = read(path)
            self.assertIn("import '../../widgets/sync_attention.dart';",
                          src, name)
            self.assertRegex(src, r'SyncAttentionBanner\(')
            self.assertIn('_rejectedCount = ', src, name)


if __name__ == '__main__':
    unittest.main()
