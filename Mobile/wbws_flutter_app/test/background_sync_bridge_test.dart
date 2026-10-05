import 'dart:async';

import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/android_background_sync_scheduler.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/background_sync_bridge.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/local_db.dart';
import 'package:fkss_app/services/sync_execution.dart';
import 'package:fkss_app/services/sync_service.dart';

/// S3 Goal A.8 Phase 3 — the Dart background entry point, behaviourally.
///
/// WHAT THESE TESTS ARE. Dart tests that drive the REAL [SyncService], the
/// REAL [BackgroundSyncCoordinator], the REAL `runSyncNow` and the REAL
/// `_drain`, with the two A.5 collaborators faked. They prove the background
/// invocation reaches the same claim the foreground reaches, carrying the
/// background label, and that every existing gate still stands in front of it.
///
/// WHAT THESE TESTS ARE NOT, STATED PLAINLY:
///   * They are NOT Android runtime tests. No Kotlin executes, no
///     AlarmManager exists, no process is woken. The platform channel is
///     mocked.
///   * No database is opened. `sqflite_common_ffi` is not a dev_dependency
///     and pubspec.yaml is frozen by F-19, so [LocalDb] is a fake. The actual
///     `sync_attempts` row written for a background drain, and the hymn
///     claim's own ledger write, are SQL-level facts proven against real
///     SQLite by tests/security/test_mobile_hymn_attempt_ledger.py (A.7) and
///     pinned by the mutation harness. What is proven HERE is that the
///     background label arrives at the claim boundary that writes them.
///   * Connectivity cannot be simulated (`ConnectivityService` is an
///     uninjected singleton, pre-existing gap).

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
      'A.8 fake: ApiService.${invocation.memberName} was not expected on the '
      'background drain path. Add it deliberately rather than widening it.');
}

class _FakeDb implements LocalDb {
  _FakeDb({this.nextAttempt});

  DateTime? nextAttempt;

  final List<String> calls = <String>[];
  final List<SyncExecutionSource> claimSources = <SyncExecutionSource>[];
  final List<int> claimGenerations = <int>[];
  final List<int> claimOwners = <int>[];
  final List<int> claimAuthVersions = <int>[];
  int claimCalls = 0;

  /// Lets a test hold the drain open so a second invocation overlaps it.
  Completer<void>? gate;

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
    claimOwners.add(ownerUserId);
    claimAuthVersions.add(authorizationVersion);
    calls.add('claim:${kind.name}:source=${executionSource.storageValue}');
    if (gate != null) await gate!.future;
    return null;
  }

  @override
  Future<DateTime?> nextOutboxAttemptAt({
    required int ownerUserId,
    required int authorizationVersion,
  }) async =>
      nextAttempt;

  @override
  Future<bool> hasDueLegacyOutbox({DateTime? now}) async => false;

  @override
  Future<void> cleanupSynced() async {}

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
  Future<void> logSync(String action, String detail, String status) async {}

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError(
      'A.8 fake: LocalDb.${invocation.memberName} was not expected on the '
      'background drain path. Add it deliberately rather than widening it.');
}

class _RecordingScheduler implements BackgroundSyncScheduler {
  final List<BackgroundSyncRequest> scheduled = <BackgroundSyncRequest>[];
  int cancelCount = 0;

  @override
  Future<void> ensureScheduled(BackgroundSyncRequest request) async =>
      scheduled.add(request);

  @override
  Future<void> cancel() async => cancelCount++;
}

class _Harness {
  _Harness._(this.db, this.api, this.scheduler, this.service);

  final _FakeDb db;
  final _FakeApi api;
  final _RecordingScheduler scheduler;
  final SyncService service;

  int generation = 1;
  bool sessionActive = true;

  factory _Harness({DateTime? nextAttempt}) {
    final db = _FakeDb(nextAttempt: nextAttempt);
    final api = _FakeApi();
    final scheduler = _RecordingScheduler();
    final service = SyncService.withCollaborators(api: api, db: db);
    final h = _Harness._(db, api, scheduler, service);
    service.backgroundScheduler = scheduler;
    service.sessionGenerationProvider = () => h.generation;
    service.activeSessionGate = () => h.sessionActive;
    return h;
  }

  /// A bridge wired to this harness's SyncService, exactly as production
  /// wires it to the singleton.
  BackgroundSyncBridge get bridge => BackgroundSyncBridge(
      runDrain: (source) => service.runSyncNow(source: source));

  void stop() => service.stopAutoSync();
}

MethodCall _invocation({
  String source = 'background',
  Object? id = 'inv-1',
}) =>
    MethodCall(BackgroundSyncChannel.methodRunBackgroundSync, {
      BackgroundSyncChannel.keySource: source,
      BackgroundSyncChannel.keyInvocationId: id,
    });

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('A.8 — 1. the entry point uses SyncExecutionSource.background', () {
    test('a valid message parses to exactly the background source', () {
      final parsed = BackgroundSyncInvocation.parse({
        BackgroundSyncChannel.keySource: 'background',
        BackgroundSyncChannel.keyInvocationId: 'abc',
      });
      expect(parsed, isNotNull);
      expect(parsed!.source, SyncExecutionSource.background);
      expect(parsed.invocationId, 'abc');
    });

    test('the label reaches the real claim as background', () async {
      final h = _Harness();
      await h.bridge.handleCall(_invocation());
      expect(h.db.claimCalls, greaterThan(0));
      expect(h.db.claimSources, everyElement(SyncExecutionSource.background));
      h.stop();
    });

    test('provenance is read from the message, never inferred', () {
      // No thread, component, lifecycle or timing signal is consulted: an
      // otherwise-identical message with no source field is refused.
      expect(
          BackgroundSyncInvocation.parse(
              {BackgroundSyncChannel.keyInvocationId: 'abc'}),
          isNull);
    });

    test('a message claiming foreground is REJECTED, not downgraded', () async {
      final h = _Harness();
      await expectLater(
          h.bridge.handleCall(_invocation(source: 'foreground')),
          throwsA(isA<PlatformException>().having((e) => e.code, 'code',
              BackgroundSyncBridge.errorInvalidInvocation)));
      expect(h.db.claimCalls, 0,
          reason: 'a mislabelled invocation must not run at all');
      h.stop();
    });

    test('unknown, empty and malformed messages are all refused', () {
      expect(BackgroundSyncInvocation.parse(null), isNull);
      expect(BackgroundSyncInvocation.parse('background'), isNull);
      expect(BackgroundSyncInvocation.parse(<Object?, Object?>{}), isNull);
      expect(
          BackgroundSyncInvocation.parse({
            BackgroundSyncChannel.keySource: 'BACKGROUND',
            BackgroundSyncChannel.keyInvocationId: 'a',
          }),
          isNull,
          reason: 'the storage spelling is exact');
      expect(
          BackgroundSyncInvocation.parse({
            BackgroundSyncChannel.keySource: 'background',
            BackgroundSyncChannel.keyInvocationId: '',
          }),
          isNull);
      expect(
          BackgroundSyncInvocation.parse({
            BackgroundSyncChannel.keySource: 'background',
            BackgroundSyncChannel.keyInvocationId: 42,
          }),
          isNull);
      expect(
          BackgroundSyncInvocation.parse({
            BackgroundSyncChannel.keySource: 42,
            BackgroundSyncChannel.keyInvocationId: 'a',
          }),
          isNull);
    });
  });

  group('A.8 — 2/3. it routes into the EXISTING coordinator, once', () {
    test('production routes through SyncService.runSyncNow', () {
      // A compile-time fact made explicit: the production runner is the
      // authoritative entry point, not a private copy of it.
      expect(BackgroundSyncBridge.runViaSyncService,
          isA<BackgroundDrainRunner>());
    });

    test('one invocation produces exactly one drain pass', () async {
      final h = _Harness();
      await h.bridge.handleCall(_invocation());
      expect(h.db.claimCalls, LegacyOperationKind.values.length,
          reason: 'one pass walks each legacy kind exactly once — a second '
              'drain path would double this');
      h.stop();
    });

    test('the background drain reaches the SAME claim the foreground reaches',
        () async {
      final bg = _Harness();
      await bg.bridge.handleCall(_invocation());
      final bgLog = bg.db.calls.where((c) => c.startsWith('claim:')).toList();
      bg.stop();

      final fg = _Harness();
      await fg.service.syncAll();
      final fgLog = fg.db.calls.where((c) => c.startsWith('claim:')).toList();
      fg.stop();

      expect(bgLog.map((c) => c.split(':')[1]).toList(),
          fgLog.map((c) => c.split(':')[1]).toList(),
          reason: 'same kinds, same order, same single drain');
    });

    test('an unrelated channel method is not silently accepted', () async {
      final h = _Harness();
      await expectLater(h.bridge.handleCall(const MethodCall('somethingElse')),
          throwsA(isA<MissingPluginException>()));
      expect(h.db.claimCalls, 0);
      h.stop();
    });
  });

  group('A.8 — 4/5/6. session, owner and authorization gates still stand', () {
    test('logged out: a background invocation never reaches the claim',
        () async {
      final h = _Harness();
      h.api.loggedIn = false;
      final result = await h.bridge.handleCall(_invocation()) as Map;
      expect(h.db.claimCalls, 0);
      expect(result[BackgroundSyncBridge.keyMessage], 'Not logged in');
      h.stop();
    });

    test('an inactive session blocks background execution', () async {
      final h = _Harness();
      h.sessionActive = false;
      await h.bridge.handleCall(_invocation());
      expect(h.db.claimCalls, 0);
      h.stop();
    });

    test('an active session executes with the right owner and auth version',
        () async {
      final h = _Harness();
      h.api.id = 42;
      h.api.authVersion = 9;
      await h.bridge.handleCall(_invocation());
      expect(h.db.claimOwners, everyElement(42));
      expect(h.db.claimAuthVersions, everyElement(9));
      h.stop();
    });

    test('the drain validates against the generation current AT WAKE-UP, '
        'not one captured when the alarm was set', () async {
      final h = _Harness();
      h.generation = 5;
      await h.bridge.handleCall(_invocation());
      expect(h.db.claimGenerations, everyElement(5));
      h.stop();
    });

    test('a generation change between scheduling and waking blocks the work',
        () async {
      final h = _Harness();
      // Session A scheduled the opportunity.
      await h.service.runSyncNow(source: SyncExecutionSource.foreground);
      final before = h.db.claimCalls;
      // The account changed before the OS granted it.
      h.generation = 2;
      h.sessionActive = false;
      await h.bridge.handleCall(_invocation());
      expect(h.db.claimCalls, before,
          reason: 'no previous account background work may execute for the '
              'current account');
      h.stop();
    });

    test('the bridge itself adds no second auth check', () async {
      // Proof by behaviour: with the session gates satisfied the drain runs,
      // so nothing above `_syncAllForGeneration` is independently vetoing it.
      final h = _Harness();
      await h.bridge.handleCall(_invocation());
      expect(h.db.claimCalls, greaterThan(0));
      h.stop();
    });
  });

  group('A.8 — 9. foreground behaviour is unchanged', () {
    test('a foreground drain still claims as foreground', () async {
      final h = _Harness();
      await h.service.syncAll();
      expect(h.db.claimSources, everyElement(SyncExecutionSource.foreground));
      h.stop();
    });

    test('foreground remains the default of runSyncNow', () async {
      final h = _Harness();
      await h.service.runSyncNow();
      expect(h.db.claimSources, everyElement(SyncExecutionSource.foreground));
      h.stop();
    });

    test('a background drain does not change the label of a later foreground '
        'drain', () async {
      final h = _Harness();
      await h.bridge.handleCall(_invocation());
      final afterBg = h.db.claimSources.length;
      await h.service.syncAll();
      expect(h.db.claimSources.sublist(afterBg),
          everyElement(SyncExecutionSource.foreground));
      h.stop();
    });
  });

  group('A.8 — 10. duplicate native callbacks coalesce in the coordinator',
      () {
    test('two overlapping invocations become one drain plus one queued pass',
        () async {
      final h = _Harness();
      h.db.gate = Completer<void>();

      final first = h.bridge.handleCall(_invocation());
      await Future<void>.delayed(Duration.zero);
      final second = h.bridge.handleCall(_invocation(id: 'inv-2'));
      await Future<void>.delayed(Duration.zero);

      h.db.gate!.complete();
      h.db.gate = null;
      await Future.wait([first, second]);

      // Exactly the A.2/A.5 coalescing behaviour: the second caller joined the
      // in-flight drain and asked for one more pass, rather than starting a
      // parallel drain.
      expect(h.db.claimCalls,
          lessThanOrEqualTo(LegacyOperationKind.values.length * 2),
          reason: 'duplicates must not multiply drains');
      expect(h.db.claimSources, everyElement(SyncExecutionSource.background),
          reason: 'the coalesced pass keeps background provenance');
      h.stop();
    });

    test('both duplicate callbacks still receive a completion for the OS',
        () async {
      final h = _Harness();
      final a = await h.bridge.handleCall(_invocation(id: 'A')) as Map;
      final b = await h.bridge.handleCall(_invocation(id: 'B')) as Map;
      expect(a[BackgroundSyncChannel.keyInvocationId], 'A');
      expect(b[BackgroundSyncChannel.keyInvocationId], 'B');
      h.stop();
    });

    test('the bridge adds no guard of its own: each call is routed', () async {
      final h = _Harness();
      await h.bridge.handleCall(_invocation(id: 'A'));
      final after1 = h.db.claimCalls;
      await h.bridge.handleCall(_invocation(id: 'A'));
      expect(h.db.claimCalls, greaterThan(after1),
          reason: 'deduplication belongs to the coordinator and SyncService, '
              'not to a second guard in the bridge');
      h.stop();
    });
  });

  group('A.8 — completion is reported back to the OS', () {
    test('the result echoes the invocation id and the drain outcome',
        () async {
      final h = _Harness();
      final result = await h.bridge.handleCall(_invocation(id: 'xyz')) as Map;
      expect(result[BackgroundSyncChannel.keyInvocationId], 'xyz');
      expect(result.containsKey(BackgroundSyncBridge.keySynced), isTrue);
      expect(result.containsKey(BackgroundSyncBridge.keyFailed), isTrue);
      expect(result.containsKey(BackgroundSyncBridge.keyMessage), isTrue);
      h.stop();
    });

    test('the completion carries no credentials or operation payload',
        () async {
      final h = _Harness();
      final result = await h.bridge.handleCall(_invocation()) as Map;
      expect(result.keys.toSet(), <String>{
        BackgroundSyncChannel.keyInvocationId,
        BackgroundSyncBridge.keySynced,
        BackgroundSyncBridge.keyFailed,
        BackgroundSyncBridge.keyMessage,
      });
      h.stop();
    });
  });

  group('A.8 — the opportunity lifecycle survives the round trip', () {
    test('a drain with durable work left re-requests an opportunity',
        () async {
      final h = _Harness(nextAttempt: DateTime.now().toUtc().add(
            const Duration(minutes: 30),
          ));
      await h.bridge.handleCall(_invocation());
      expect(h.scheduler.scheduled, isNotEmpty,
          reason: 'completion reschedules from the outbox, not from a loop');
      expect(h.scheduler.scheduled.first.requiresNetwork, isTrue);
      h.stop();
    });

    test('the rescheduled time comes from the outbox, not from a fixed period',
        () async {
      final when = DateTime.now().toUtc().add(const Duration(minutes: 17));
      final h = _Harness(nextAttempt: when);
      await h.bridge.handleCall(_invocation());
      expect(h.scheduler.scheduled.first.notBefore, when);
      h.stop();
    });

    test('no durable work means no new opportunity', () async {
      final h = _Harness();
      await h.bridge.handleCall(_invocation());
      expect(h.scheduler.scheduled, isEmpty);
      h.stop();
    });

    test('logout cancels the pending opportunity', () async {
      final h = _Harness(nextAttempt: DateTime.now().toUtc().add(
            const Duration(minutes: 5),
          ));
      await h.bridge.handleCall(_invocation());
      h.service.stopAutoSync();
      expect(h.scheduler.cancelCount, greaterThan(0));
    });
  });
}
