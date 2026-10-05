import 'dart:async';

import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/sync_execution.dart';
import 'package:fkss_app/services/sync_service.dart';

/// S2 Goal A.5 — BEHAVIOURAL verification of the A.2 trigger wiring and the
/// A.4 opportunity request, by executing the real [SyncService].
///
/// WHY THIS FILE EXISTS. Through A.4 every claim about `runSyncNow`, `nudge`,
/// the generation handoff, `_inflight`/`_queued` coalescing and the
/// opportunity request was SOURCE-verified only: `SyncService` built
/// `ApiService()` and `LocalDb()` as field initialisers, so no test could
/// substitute anything and no test could run the class at all. A.5 added one
/// narrow seam — `SyncService.withCollaborators` — and these tests drive the
/// REAL coordinator, the REAL `runSyncNow`, the REAL `_drain` and the REAL
/// A.4 request through it.
///
/// WHAT IS STILL NOT PROVEN HERE, STATED PLAINLY:
///   * No database is opened. `sqflite_common_ffi` is not a dev_dependency
///     and pubspec.yaml is frozen by F-19, so [LocalDb] is a fake. Nothing
///     here verifies the claim SQL, `next_attempt_at` eligibility, owner or
///     authorization-version isolation inside the transaction, or any
///     migration. Those remain SQL-level facts proven elsewhere (or, for the
///     v36->v37 upgrade, still unproven).
///   * Connectivity cannot be simulated. `ConnectivityService` is an
///     uninjected singleton with no public test hook and `hasLink` defaults to
///     true, so the specific "offline device still schedules a wake-up" case
///     stays source-verified (python pin + mutation M28). What IS proven here
///     is the mechanism that makes it work: the request is emitted with
///     `requiresNetwork: true`.
///   * The real [HymnStore] and [MezmurDownloadManager] singletons are reached
///     inside `_drain`'s own try/catch and fail there without a database. That
///     is pre-existing behaviour (finding B-2) and is why these tests assert on
///     scheduler and claim observations rather than on `failed` counts.

/// Records what the coordinator asked the platform to do, without doing it.
class _RecordingScheduler implements BackgroundSyncScheduler {
  final List<BackgroundSyncRequest> scheduled = <BackgroundSyncRequest>[];
  int cancelCount = 0;

  @override
  Future<void> ensureScheduled(BackgroundSyncRequest request) async {
    scheduled.add(request);
  }

  @override
  Future<void> cancel() async {
    cancelCount++;
  }
}

/// Deliberately tiny. Anything the drain path does not already use throws
/// through [noSuchMethod] rather than silently returning null, so widening the
/// production code's reach into [ApiService] fails loudly instead of passing.
class _FakeApi implements ApiService {
  _FakeApi({this.loggedIn = true, this.id = 7, this.authVersion = 3});

  bool loggedIn;
  int id;
  int authVersion;

  @override
  bool get isLoggedIn => loggedIn;

  @override
  int get userId => id;

  @override
  int get authorizationVersion => authVersion;

  @override
  String get userRole => 'teacher';

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError(
      'A.5 fake: ApiService.${invocation.memberName} was not expected on the '
      'drain path. Add it deliberately rather than widening the fake.');
}

/// A fake outbox that claims nothing. That is the point: with no claimable
/// row the drain still has to walk every kind, settle its bookkeeping and
/// reach the A.4 opportunity request, which is exactly the wiring under test.
class _FakeDb implements LocalDb {
  _FakeDb({
    this.nextAttempt,
    this.moreDue = false,
    this.clearNextAttemptAfterRead = false,
  });

  DateTime? nextAttempt;
  bool moreDue;
  bool clearNextAttemptAfterRead;

  final List<String> calls = <String>[];
  final List<SyncExecutionSource> claimSources = <SyncExecutionSource>[];
  final List<int> claimGenerations = <int>[];
  int claimCalls = 0;
  int nextAttemptCalls = 0;

  @override
  Future<LegacyClaimSnapshot?> claimNextLegacyOperation({
    required LegacyOperationKind kind,
    required int ownerUserId,
    required int authorizationVersion,
    required int runtimeGeneration,
    DateTime? now,
    SyncExecutionSource executionSource = SyncExecutionSource.foreground,
  }) async {
    claimCalls++;
    claimSources.add(executionSource);
    claimGenerations.add(runtimeGeneration);
    calls.add('claim:${kind.name}:owner=$ownerUserId:av=$authorizationVersion');
    return null;
  }

  @override
  Future<DateTime?> nextOutboxAttemptAt({
    required int ownerUserId,
    required int authorizationVersion,
  }) async {
    nextAttemptCalls++;
    calls.add('nextOutboxAttemptAt:owner=$ownerUserId:av=$authorizationVersion');
    final value = nextAttempt;
    if (clearNextAttemptAfterRead) nextAttempt = null;
    return value;
  }

  @override
  Future<bool> hasDueLegacyOutbox({DateTime? now}) async {
    calls.add('hasDueLegacyOutbox');
    final value = moreDue;
    moreDue = false; // at most one extra pass, never an unbounded test loop
    return value;
  }

  @override
  Future<void> cleanupSynced() async {
    calls.add('cleanupSynced');
  }

  @override
  Future<int> getPendingAttendanceCount() async => 0;

  @override
  Future<int> getPendingGradesCount() async => 0;

  @override
  Future<int> getPendingMezmurCount() async => 0;

  @override
  Future<int> getPendingHrCount() async => 0;

  @override
  Future<int> getPendingHymnOpsCount() async => 0;

  @override
  Future<OutboxInventory> getOutboxInventory({DateTime? now}) async =>
      const OutboxInventory();

  @override
  Future<void> logSync(String action, String detail, String status) async {
    calls.add('logSync:$action');
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError(
      'A.5 fake: LocalDb.${invocation.memberName} was not expected on the '
      'drain path. Add it deliberately rather than widening the fake.');
}

class _Harness {
  _Harness._(this.db, this.api, this.scheduler, this.service);

  final _FakeDb db;
  final _FakeApi api;
  final _RecordingScheduler scheduler;
  final SyncService service;

  int generation = 1;
  bool sessionActive = true;

  factory _Harness({
    DateTime? nextAttempt,
    bool moreDue = false,
    bool clearNextAttemptAfterRead = false,
  }) {
    final db = _FakeDb(
      nextAttempt: nextAttempt,
      moreDue: moreDue,
      clearNextAttemptAfterRead: clearNextAttemptAfterRead,
    );
    final api = _FakeApi();
    final scheduler = _RecordingScheduler();
    final service = SyncService.withCollaborators(api: api, db: db);
    final harness = _Harness._(db, api, scheduler, service);
    service.backgroundScheduler = scheduler;
    service.sessionGenerationProvider = () => harness.generation;
    service.activeSessionGate = () => harness.sessionActive;
    return harness;
  }

  /// Cancels any timer the drain armed so a test cannot leak work into the
  /// next one.
  void stop() => service.stopAutoSync();

  List<String> get claimLog =>
      db.calls.where((c) => c.startsWith('claim:')).toList();
}

int get _kinds => LegacyOperationKind.values.length;

void main() {
  group('A.5 — the seam itself', () {
    test('production construction is unchanged: SyncService() is a singleton',
        () {
      expect(identical(SyncService(), SyncService()), isTrue);
    });

    test('an injected instance is NOT the production singleton', () {
      final injected =
          SyncService.withCollaborators(api: _FakeApi(), db: _FakeDb());
      expect(identical(SyncService(), injected), isFalse);
    });

    test('two injected instances do not share execution state', () {
      final a = _Harness();
      final b = _Harness();
      expect(identical(a.service, b.service), isFalse);
    });
  });

  group('A.2 — runSyncNow is the one execution path (behavioural)', () {
    test('runSyncNow() executes the real drain and reaches the claim',
        () async {
      final h = _Harness();

      await h.service.runSyncNow();

      // Every legacy kind was offered to the authoritative claim exactly once
      // (each returns null, which breaks that kind's loop).
      expect(h.db.claimCalls, _kinds);
      expect(h.db.calls, contains('cleanupSynced'));
      expect(h.db.nextAttemptCalls, 1);
      h.stop();
    });

    test('the claim receives owner and authorization version from the API',
        () async {
      final h = _Harness();

      await h.service.runSyncNow();

      expect(h.claimLog, isNotEmpty);
      for (final call in h.claimLog) {
        expect(call, contains('owner=7'));
        expect(call, contains('av=3'));
      }
      h.stop();
    });

    test('a background execution reaches the SAME claim, only relabelled',
        () async {
      final h = _Harness();

      await h.service.runSyncNow(source: SyncExecutionSource.background);

      expect(h.db.claimCalls, _kinds);
      expect(
        h.db.claimSources.every((s) => s == SyncExecutionSource.background),
        isTrue,
        reason: 'background must not get its own query, only its own label',
      );
      h.stop();
    });

    test('syncAll() reaches the same execution path, labelled foreground',
        () async {
      final h = _Harness();

      await h.service.syncAll();

      expect(h.db.claimCalls, _kinds);
      expect(
        h.db.claimSources.every((s) => s == SyncExecutionSource.foreground),
        isTrue,
      );
      h.stop();
    });

    test('nudge() eventually reaches the same execution path', () async {
      final h = _Harness();

      h.service.nudge(delay: const Duration(milliseconds: 10));
      expect(h.db.claimCalls, 0, reason: 'nudge must be deferred, not direct');

      await Future<void>.delayed(const Duration(milliseconds: 200));

      expect(h.db.claimCalls, greaterThanOrEqualTo(_kinds));
      h.stop();
    });

    test('a drain that is not logged in never reaches the claim', () async {
      final h = _Harness();
      h.api.loggedIn = false;

      await h.service.runSyncNow();

      expect(h.db.claimCalls, 0);
      h.stop();
    });
  });

  group('A.2 — generation handoff (behavioural)', () {
    test('a nudge armed under generation A cannot drain under generation B',
        () async {
      final h = _Harness();

      h.service.nudge(delay: const Duration(milliseconds: 40));
      h.generation = 2; // the session changes before the timer fires

      await Future<void>.delayed(const Duration(milliseconds: 220));

      expect(
        h.db.claimCalls,
        0,
        reason: 'the stale generation must stop BEFORE touching the database',
      );
      h.stop();
    });

    test('control: with no session change the same nudge does drain',
        () async {
      final h = _Harness();

      h.service.nudge(delay: const Duration(milliseconds: 40));

      await Future<void>.delayed(const Duration(milliseconds: 220));

      expect(h.db.claimCalls, greaterThanOrEqualTo(_kinds));
      h.stop();
    });

    test('an inactive session gate blocks the drain', () async {
      final h = _Harness();
      h.sessionActive = false;

      await h.service.runSyncNow();

      expect(h.db.claimCalls, 0);
      h.stop();
    });

    test('the claim is told the generation the drain is running under',
        () async {
      final h = _Harness();
      h.generation = 9;

      await h.service.runSyncNow();

      expect(h.db.claimGenerations, isNotEmpty);
      expect(h.db.claimGenerations.every((g) => g == 9), isTrue);
      h.stop();
    });
  });

  group('A.2 — coalescing (behavioural)', () {
    test('two overlapping executions become one drain plus one queued pass',
        () async {
      final h = _Harness();

      final first = h.service.runSyncNow();
      final second = h.service.runSyncNow();
      await Future.wait<SyncResult>([first, second]);

      // Not two parallel drains, and not one swallowed request: the second
      // sets _queued and the first loop runs a second pass for it.
      expect(h.db.claimCalls, 2 * _kinds);
      expect(h.db.nextAttemptCalls, 2);
      h.stop();
    });

    test('a due backlog queues exactly one more pass', () async {
      final h = _Harness(moreDue: true);

      await h.service.runSyncNow();

      expect(h.db.claimCalls, 2 * _kinds);
      h.stop();
    });

    test('a single execution runs exactly one pass', () async {
      final h = _Harness();

      await h.service.runSyncNow();

      expect(h.db.claimCalls, _kinds);
      expect(h.db.nextAttemptCalls, 1);
      h.stop();
    });
  });

  group('A.4 — the opportunity request (behavioural)', () {
    test('durable work remaining asks the platform for an opportunity',
        () async {
      final notBefore = DateTime.now().toUtc().add(const Duration(minutes: 5));
      final h = _Harness(nextAttempt: notBefore);

      await h.service.runSyncNow();

      expect(h.scheduler.scheduled, hasLength(1));
      expect(h.scheduler.scheduled.single.notBefore, notBefore);
      h.stop();
    });

    test('the request carries requiresNetwork, which is what lets the OS wake '
        'an offline device when connectivity returns', () async {
      final h = _Harness(
        nextAttempt: DateTime.now().toUtc().add(const Duration(minutes: 5)),
      );

      await h.service.runSyncNow();

      expect(h.scheduler.scheduled.single.requiresNetwork, isTrue);
      h.stop();
    });

    test('no durable work means no opportunity is requested', () async {
      final h = _Harness(); // nextOutboxAttemptAt returns null

      await h.service.runSyncNow();

      expect(h.scheduler.scheduled, isEmpty);
      h.stop();
    });

    test('the opportunity time comes from the outbox, not from a new clock',
        () async {
      final notBefore =
          DateTime.utc(2031, 3, 4, 5, 6, 7); // a value no timer could invent
      final h = _Harness(nextAttempt: notBefore);

      await h.service.runSyncNow();

      expect(h.scheduler.scheduled.single.notBefore, notBefore);
      h.stop();
    });

    test('one drain produces exactly one request, and a later drain refreshes '
        'it rather than stacking a second pending one', () async {
      final h = _Harness(
        nextAttempt: DateTime.now().toUtc().add(const Duration(minutes: 5)),
      );

      await h.service.runSyncNow();
      expect(h.scheduler.scheduled, hasLength(1),
          reason: 'a single drain must ask at most once');

      // execute() consumes the pending opportunity, so the next drain is
      // entitled to re-ask with a refreshed notBefore. That is the documented
      // idempotent ensureScheduled contract, not a second scheduler.
      await h.service.runSyncNow();
      expect(h.scheduler.scheduled, hasLength(2));
      expect(h.scheduler.scheduled.last.requiresNetwork, isTrue);
      h.stop();
    });

    test('a far-future next attempt does not re-drain immediately', () async {
      final h = _Harness(
        nextAttempt: DateTime.now().toUtc().add(const Duration(minutes: 5)),
      );

      await h.service.runSyncNow();
      final afterFirstDrain = h.db.claimCalls;

      await Future<void>.delayed(const Duration(milliseconds: 200));

      expect(
        h.db.claimCalls,
        afterFirstDrain,
        reason: 'the retry nudge must honour the outbox time, not fire now',
      );
      h.stop();
    });

    test('an already-due next attempt re-drains promptly', () async {
      final h = _Harness(
        nextAttempt: DateTime.now().toUtc().subtract(const Duration(minutes: 5)),
        clearNextAttemptAfterRead: true, // exactly one re-drain, no spin
      );

      await h.service.runSyncNow();
      final afterFirstDrain = h.db.claimCalls;

      await Future<void>.delayed(const Duration(milliseconds: 200));

      expect(h.db.claimCalls, greaterThan(afterFirstDrain));
      h.stop();
    });
  });

  group('A.4 — logout cancels the wake-up, never the work', () {
    test('stopAutoSync cancels the opportunity', () async {
      final h = _Harness(
        nextAttempt: DateTime.now().toUtc().add(const Duration(minutes: 5)),
      );

      await h.service.runSyncNow();
      expect(h.scheduler.scheduled, hasLength(1));

      h.service.stopAutoSync();
      await Future<void>.delayed(const Duration(milliseconds: 20));

      expect(h.scheduler.cancelCount, 1);
    });

    test('stopAutoSync does not touch the durable outbox', () async {
      final h = _Harness(
        nextAttempt: DateTime.now().toUtc().add(const Duration(minutes: 5)),
      );

      await h.service.runSyncNow();
      final before = List<String>.from(h.db.calls);

      h.service.stopAutoSync();
      await Future<void>.delayed(const Duration(milliseconds: 20));

      // Any delete/clear would either show here or throw through the fake's
      // noSuchMethod, because the fake implements nothing it does not expect.
      expect(h.db.calls, before);
    });

    test('a nudge after stopAutoSync does not drain while the session is gone',
        () async {
      final h = _Harness();
      h.service.nudge(delay: const Duration(milliseconds: 40));
      h.service.stopAutoSync();

      await Future<void>.delayed(const Duration(milliseconds: 200));

      expect(h.db.claimCalls, 0);
    });
  });
}
