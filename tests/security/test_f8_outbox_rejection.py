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
POLICY = (f'{ROOT}/Mobile/wbws_flutter_app/lib/services/outbox_policy.dart')
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
        self.policy = read(POLICY)

    def test_the_bug_is_dead(self):
        self.assertNotIn('bool _accepted(', self.s)
        self.assertNotIn('if (res.statusCode == 409) return true;', self.s)
        self.assertNotIn("m.contains('already submitted')", self.s)

    def test_classifier_exists_and_is_pure(self):
        self.assertIn('enum OutboxDecision {', self.policy)
        self.assertIn('OutboxDecision classifyOutboxResponse(', self.policy)
        self.assertNotIn('ApiService', self.policy)

    def test_replay_invariant_is_success_only(self):
        accepted = self.policy[self.policy.find('if (evidence.success'):
                               self.policy.find('if (evidence.success') + 350]
        self.assertIn('return OutboxDecision.accepted;', accepted)
        self.assertIn('status >= 200 && status < 300', accepted)

    def test_409_taxonomy(self):
        for code in ('ALREADY_SUBMITTED', 'WORKFLOW_REJECTED',
                     'IDEMPOTENCY_CONFLICT', 'IDEMPOTENCY_IN_PROGRESS'):
            self.assertIn(code, self.policy)
        conflict = self.policy[self.policy.find('if (status == 409)'):
                               self.policy.find('if (status == 409)') + 950]
        self.assertIn('OutboxDecision.retryable', conflict)
        self.assertIn('OutboxDecision.needsAttention', conflict)
        self.assertIn('_boundedUnknown', conflict)

    def test_transient_statuses(self):
        for status in ('status == 408', 'status == 425', 'status == 429',
                       'status >= 500'):
            self.assertIn(status, self.policy)

    def test_definite_protocol_refusals_are_rejected(self):
        for status in ('status == 400', 'status == 403', 'status == 404',
                       'status == 422'):
            self.assertIn(status, self.policy)

    def test_all_four_drains_use_one_typed_classifier(self):
        self.assertIn('for (final kind in LegacyOperationKind.values)', self.s)
        self.assertIn('classifyOutboxResponse(', self.s)
        self.assertIn('response.toOutboxEvidence(', self.s)
        self.assertNotIn('classifyDrainResponse(res)', self.s)

    def test_all_four_drains_skip_terminal_attention_batches(self):
        db = read(LDB)
        claim = db[db.find('claimNextLegacyOperation'):
                   db.find('claimNextLegacyOperation') + 2600]
        self.assertIn("sync_state IN ('pending', 'retry_wait')", claim)
        self.assertNotIn("sync_state = 'needs_attention'", claim)

    def test_all_four_drains_settle_rejections_with_reason(self):
        self.assertIn('LegacySettlementKind.needsAttention', self.s)
        self.assertIn('failureCode: response.errorCode', self.s)
        self.assertIn('failureMessage:', self.s)
        self.assertIn('settleLegacyOperation(', self.s)

    def test_synced_progress_only_after_exact_accepted_settlement(self):
        body = self.s[self.s.find('settleLegacyOperation('):
                      self.s.find('settleLegacyOperation(') + 1500]
        self.assertIn('result != LegacySettlementResult.applied', body)
        self.assertIn('decision == OutboxDecision.accepted', body)
        guard = body.find('result != LegacySettlementResult.applied')
        increment = body.find('synced++')
        self.assertGreater(increment, guard)


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

    def test_rejection_uses_exact_claim_settlement_only(self):
        for call in ('rejectAttendance', 'rejectGrades', 'rejectMezmur',
                     'rejectHr', 'markAttendanceSynced', 'markGradesSynced',
                     'markMezmurSynced', 'markHrSynced'):
            self.assertNotIn(f'Future<void> {call}(', self.db)
        settle = self.db[self.db.find('settleLegacyOperation'):
                         self.db.find('settleLegacyOperation') + 5200]
        self.assertIn('LegacySettlementKind.needsAttention', settle)
        self.assertIn("sync_state': 'needs_attention'", settle)
        exact = self.db[self.db.find('_legacyExactWhere'):
                        self.db.find('_legacyExactWhere') + 1100]
        self.assertIn("sync_state = 'in_flight'", exact)

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
        # drained with no key (safe only by upsert accident). The v34
        # feed returns an id only for one coherent generation; UUID lexical
        # MAX is explicitly forbidden because it is not temporal ordering.
        feed = self.db[self.db.find('getPendingGrades'):
                       self.db.find('getPendingGrades') + 650]
        self.assertIn('COUNT(DISTINCT client_op_id) = 1', feed)
        self.assertIn('MIN(client_op_id) ELSE NULL', feed)
        self.assertNotIn('MAX(client_op_id)', feed)


class F8DropPendingReconciliation(unittest.TestCase):
    """Audit blocker fix (2026-09-18): locked-day screen cleanup
    (dropPending*) must NEVER delete F8-rejected rows
    (synced=0 + sync_error NOT NULL) — they belong to the Needs
    Attention review / explicit Discard flow. Ordinary stale pending
    rows (sync_error NULL) keep the pre-existing cleanup behavior.

    Runtime DB-level testing is impossible in this repo (no sqflite
    test harness — documented limitation); these are the strongest
    static pins: the SQL WHERE clauses themselves."""

    def setUp(self):
        self.db = read(LDB)

    def _method(self, name):
        i = self.db.find(f'Future<void> {name}(')
        self.assertGreater(i, -1, name)
        j = self.db.find('\n  }', i)
        return self.db[i:j]

    def test_locked_day_cleanup_spares_rejected_rows(self):
        # Case 2: synced=0 + sync_error NOT NULL must survive the
        # automatic locked-day cleanup on all four outboxes.
        for name in ('dropPendingAttendance', 'dropPendingGrades',
                     'dropPendingMezmur', 'dropPendingHr'):
            body = self._method(name)
            self.assertIn('synced = 0', body, name)
            self.assertIn('sync_error IS NULL', body,
                          f'{name} must never delete F8-rejected rows')

    def test_stale_pending_cleanup_behavior_unchanged(self):
        # Case 1: the guard is strictly additive — ordinary stale
        # pending rows (sync_error NULL) are still cleaned, still
        # scoped to synced=0 only.
        for name in ('dropPendingAttendance', 'dropPendingGrades',
                     'dropPendingMezmur', 'dropPendingHr'):
            body = self._method(name)
            self.assertNotIn('synced = 1', body, name)
            self.assertIn("db.delete('pending_", body, name)

    def test_explicit_discard_still_deletes_rejected(self):
        # Case 3: the review sheet's Discard remains the deliberate
        # deleter of rejected rows — NO sync_error-IS-NULL guard there.
        for name in ('discardRejectedAttendance', 'discardRejectedGrades',
                     'discardRejectedMezmur', 'discardRejectedHr'):
            body = self._method(name)
            self.assertIn('synced = 0', body, name)
            self.assertNotIn('sync_error IS NULL', body, name)

    def test_resave_replacement_still_replaces_rejected(self):
        # All four public saves delegate to one serialized transaction that
        # deletes the whole unsynced natural-key generation (including a prior
        # rejection) before inserting one fresh operation id.
        for name in ('saveAttendanceLocal', 'saveGradesLocal',
                     'saveMezmurLocal', 'saveHrLocal'):
            body = self.db[self.db.find(f'Future<LegacyOperationRef> {name}('):
                           self.db.find(f'Future<LegacyOperationRef> {name}(') + 2200]
            self.assertIn('_replaceLegacyOperation(', body, name)
        replacement = self.db[self.db.find('_replaceLegacyOperation({'):
                              self.db.find('_replaceLegacyOperation({') + 3200]
        self.assertIn('final opId = newClientOpId();', replacement)
        self.assertIn("where: '$naturalKeyWhere AND synced = 0 '", replacement)
        self.assertIn("'AND owner_user_id = ? '", replacement)
        self.assertIn("'AND created_authorization_version = ?'", replacement)
        self.assertIn('...naturalKeyArgs', replacement)
        self.assertIn("binding['owner_user_id']", replacement)
        self.assertIn("binding['created_authorization_version']", replacement)
        self.assertNotIn('sync_error IS NULL', replacement)

    def test_needs_attention_pipeline_unaffected(self):
        # Case 4: the review system still reads the spared rows
        # (already pinned above; this asserts the invariant that makes
        # the spare meaningful — rejected visibility does not depend
        # on dropPending).
        for name in ('getRejectedBatches',):
            i = self.db.find(f'Future<List<Map<String, dynamic>>> {name}(')
            self.assertGreater(i, -1, name)
        self.assertNotIn('dropPending', read(SYNC),
                         'the sync engine must not invoke locked-day drops')


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
        self.assertIn('rejected: inventory.needsAttention,', s)
        self.assertIn('final inventory = await _db.getOutboxInventory();', s)

    def test_all_four_screens_wire_the_banner(self):
        for name, path in SCREENS.items():
            src = read(path)
            self.assertIn("import '../../widgets/sync_attention.dart';",
                          src, name)
            self.assertRegex(src, r'SyncAttentionBanner\(')
            self.assertIn('_rejectedCount = ', src, name)


if __name__ == '__main__':
    unittest.main()
