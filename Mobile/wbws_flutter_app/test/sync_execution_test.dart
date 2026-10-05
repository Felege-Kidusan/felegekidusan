import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/sync_attempt_models.dart';
import 'package:fkss_app/services/sync_execution.dart';

/// S2 Goal A.1 verification — Dart-side background sync execution core.
///
/// SCOPE, STATED HONESTLY. This repository has no sqflite test binding
/// (`sqflite_common_ffi` is not a dev_dependency and pubspec.yaml is frozen by
/// F-19), so no test here opens a database. These tests therefore verify the
/// execution/scheduling architecture and the ledger's read path. They do NOT
/// verify claim-query behaviour — `next_attempt_at` eligibility, owner and
/// authorization-version isolation and process-restart recovery all live in
/// SQL that this increment did not modify, and proving them needs a real
/// database. See the S2 document for exactly what remains unverified.

/// Records what the coordinator asked the platform to do, without doing it.
class FakeScheduler implements BackgroundSyncScheduler {
  final List<BackgroundSyncRequest> scheduled = <BackgroundSyncRequest>[];
  int cancelCount = 0;
  bool throwOnSchedule = false;

  @override
  Future<void> ensureScheduled(BackgroundSyncRequest request) async {
    if (throwOnSchedule) throw StateError('platform refused');
    scheduled.add(request);
  }

  @override
  Future<void> cancel() async => cancelCount++;
}

void main() {
  group('A. one authoritative execution path', () {
    test('foreground and background reach the SAME drain runner', () async {
      final sources = <SyncExecutionSource>[];
      final coordinator = BackgroundSyncCoordinator<String>(
        runDrain: (source) async {
          sources.add(source);
          return 'drained';
        },
      );

      final fg = await coordinator.execute(SyncExecutionSource.foreground);
      final bg = await coordinator.execute(SyncExecutionSource.background);

      // Same function object served both. If a second engine were ever
      // introduced for background work, one of these would not arrive here.
      expect(sources,
          [SyncExecutionSource.foreground, SyncExecutionSource.background]);
      expect(fg, 'drained');
      expect(bg, 'drained');
    });

    test('the coordinator never runs a drain on its own initiative', () async {
      var runs = 0;
      final scheduler = FakeScheduler();
      final coordinator = BackgroundSyncCoordinator<int>(
        runDrain: (_) async => ++runs,
        scheduler: scheduler,
      );

      await coordinator.requestOpportunity();

      // Requesting an opportunity asks the platform; it does not sync.
      expect(scheduler.scheduled, hasLength(1));
      expect(runs, 0, reason: 'scheduling must not execute the drain');
    });
  });

  group('B. execution source', () {
    test('storage spellings are the durable contract', () {
      expect(SyncExecutionSource.foreground.storageValue, 'foreground');
      expect(SyncExecutionSource.background.storageValue, 'background');
      expect(SyncExecutionSource.values, hasLength(2));
    });

    test('the label chosen by the caller is the label the drain receives',
        () async {
      final seen = <SyncExecutionSource>[];
      final coordinator = BackgroundSyncCoordinator<void>(
        runDrain: (source) async => seen.add(source),
      );

      await coordinator.execute(SyncExecutionSource.background);

      expect(seen.single, SyncExecutionSource.background);
    });

    test('an attempt row round-trips its execution source', () {
      final record = SyncAttemptRecord.fromRow(<String, Object?>{
        'client_op_id': 'op-1',
        'attempt_number': 2,
        'attempt_uid': 'att_x',
        'domain': 'pending_attendance',
        'started_at': '2026-03-01T10:00:00.000Z',
        'execution_source': 'background',
      });

      expect(record.executionSource, SyncExecutionSource.background);
      expect(record.clientOpId, 'op-1',
          reason: 'operation identity stays client_op_id');
    });

    test('rows written before v37 read back as foreground, not as unknown',
        () {
      // Factual, not a convenience default: no background execution path
      // existed before v37, so every pre-v37 attempt was a foreground drain.
      for (final legacy in <Object?>[null, '', 'nonsense', 42]) {
        expect(SyncExecutionSource.fromStorage(legacy),
            SyncExecutionSource.foreground);
      }

      final record = SyncAttemptRecord.fromRow(<String, Object?>{
        'client_op_id': 'op-legacy',
        'attempt_number': 1,
        'attempt_uid': 'att_legacy',
        'domain': 'pending_grades',
        'started_at': '2026-03-01T10:00:00.000Z',
        // column absent entirely, exactly as an un-migrated row reads
      });
      expect(record.executionSource, SyncExecutionSource.foreground);
    });

    test('foreground remains the default so existing callers are unchanged',
        () {
      final record = SyncAttemptRecord(
        clientOpId: 'op-2',
        attemptNumber: 1,
        attemptUid: 'att_y',
        domain: 'pending_mezmur',
        startedAt: DateTime.utc(2026, 3, 1),
      );
      expect(record.executionSource, SyncExecutionSource.foreground);
    });
  });

  group('C. scheduler boundary is replaceable', () {
    test('a fake scheduler fully substitutes for the platform', () async {
      final scheduler = FakeScheduler();
      final coordinator = BackgroundSyncCoordinator<void>(
        runDrain: (_) async {},
        scheduler: scheduler,
      );

      final at = DateTime.utc(2026, 3, 1, 12);
      await coordinator.requestOpportunity(notBefore: at, requiresNetwork: false);

      expect(scheduler.scheduled.single.notBefore, at);
      expect(scheduler.scheduled.single.requiresNetwork, isFalse);
    });

    test('the default scheduler does nothing and breaks nothing', () async {
      final coordinator = BackgroundSyncCoordinator<String>(
        runDrain: (_) async => 'ok',
      );
      await coordinator.requestOpportunity();
      // No platform implementation exists yet: background simply never fires,
      // and the foreground path is untouched.
      expect(await coordinator.execute(SyncExecutionSource.foreground), 'ok');
    });

    test('a scheduler request carries a trigger only — never work', () {
      const request = BackgroundSyncRequest();
      // There is one logical unit of background work, so one unique name.
      expect(BackgroundSyncRequest.uniqueWorkName, 'fkss.sync.drain');
      expect(request.requiresNetwork, isTrue);
      expect(request.notBefore, isNull);
      // Structural guarantee: nothing in the rendered request resembles a
      // payload, a credential or an operation list.
      final rendered = request.toString();
      expect(rendered, contains('fkss.sync.drain'));
      expect(rendered.toLowerCase(), isNot(contains('token')));
      expect(rendered.toLowerCase(), isNot(contains('client_op_id')));
    });
  });

  group('D. scheduling is idempotent', () {
    test('five equivalent triggers produce ONE pending opportunity', () async {
      final scheduler = FakeScheduler();
      final coordinator = BackgroundSyncCoordinator<void>(
        runDrain: (_) async {},
        scheduler: scheduler,
      );

      // network returned, app resumed, user opened the app, a retry came due,
      // startup — all at roughly the same moment.
      for (var i = 0; i < 5; i++) {
        await coordinator.requestOpportunity();
      }

      expect(scheduler.scheduled, hasLength(1),
          reason: 'equivalent triggers must collapse, not accumulate');
      expect(coordinator.opportunityPending, isTrue);
    });

    test('executing consumes the opportunity so the next trigger re-arms',
        () async {
      final scheduler = FakeScheduler();
      final coordinator = BackgroundSyncCoordinator<void>(
        runDrain: (_) async {},
        scheduler: scheduler,
      );

      await coordinator.requestOpportunity();
      await coordinator.requestOpportunity();
      expect(scheduler.scheduled, hasLength(1));

      await coordinator.execute(SyncExecutionSource.background);
      expect(coordinator.opportunityPending, isFalse);

      await coordinator.requestOpportunity();
      expect(scheduler.scheduled, hasLength(2),
          reason: 'a consumed opportunity must be re-requestable');
    });

    test('a failed enqueue does not wedge the coordinator', () async {
      final scheduler = FakeScheduler()..throwOnSchedule = true;
      final coordinator = BackgroundSyncCoordinator<void>(
        runDrain: (_) async {},
        scheduler: scheduler,
      );

      await expectLater(
          coordinator.requestOpportunity(), throwsA(isA<StateError>()));
      expect(coordinator.opportunityPending, isFalse,
          reason: 'believing in an opportunity the platform never took would '
              'silently disable background sync');

      scheduler.throwOnSchedule = false;
      await coordinator.requestOpportunity();
      expect(scheduler.scheduled, hasLength(1));
    });

    test('deduplication never holds copies of outbox work', () async {
      final scheduler = FakeScheduler();
      final coordinator = BackgroundSyncCoordinator<void>(
        runDrain: (_) async {},
        scheduler: scheduler,
      );
      await coordinator.requestOpportunity();

      // The request type has no capacity to carry operations: the only state
      // it can express is "when" and "needs network".
      final request = scheduler.scheduled.single;
      expect(request.notBefore, isNull);
      expect(request.requiresNetwork, isTrue);
    });
  });

  group('H. logout cancels the wake-up, never the work', () {
    test('cancelling clears the opportunity and calls the platform', () async {
      final scheduler = FakeScheduler();
      final coordinator = BackgroundSyncCoordinator<void>(
        runDrain: (_) async {},
        scheduler: scheduler,
      );

      await coordinator.requestOpportunity();
      await coordinator.cancelOpportunity();

      expect(coordinator.opportunityPending, isFalse);
      expect(scheduler.cancelCount, 1);
      // Nothing here deletes pending operations: account isolation is enforced
      // by the owner/authorization-version predicates in the claim query, which
      // this increment did not touch.
    });
  });
}
