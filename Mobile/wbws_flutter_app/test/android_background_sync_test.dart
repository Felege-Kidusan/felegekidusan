import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/android_background_sync_scheduler.dart';
import 'package:fkss_app/services/sync_execution.dart';

/// S3 Goal A.8 Phase 2 — the Android scheduler, behaviourally.
///
/// These are Dart tests running on the Flutter test binding with a MOCK
/// platform channel. They prove what Dart sends and how Dart reacts; they
/// prove nothing whatsoever about Kotlin, AlarmManager or any Android runtime
/// behaviour. See the A.8 document for that distinction, which is kept
/// deliberately sharp.

/// Captures every message Dart puts on the channel.
class _ChannelSpy {
  _ChannelSpy(this.channel);

  final MethodChannel channel;
  final List<MethodCall> calls = <MethodCall>[];

  /// What the native side should do when called: return normally, be absent,
  /// or fail.
  Object? Function(MethodCall call)? responder;

  void install() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (MethodCall call) async {
      calls.add(call);
      return responder?.call(call);
    });
  }

  /// Simulates a build with no native half at all (iOS, widget test, an older
  /// APK driven by newer Dart). An unregistered channel throws
  /// MissingPluginException.
  void uninstall() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, null);
  }

  Map<String, Object?> argsOf(int index) =>
      Map<String, Object?>.from(calls[index].arguments as Map);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  const channel = MethodChannel(BackgroundSyncChannel.name);
  late _ChannelSpy spy;
  late AndroidBackgroundSyncScheduler scheduler;

  setUp(() {
    spy = _ChannelSpy(channel)..install();
    scheduler = AndroidBackgroundSyncScheduler(channel: channel);
  });

  tearDown(() => spy.uninstall());

  group('A.8/P2 — it implements the EXISTING abstraction, not a new one', () {
    test('an Android scheduler is a BackgroundSyncScheduler', () {
      expect(scheduler, isA<BackgroundSyncScheduler>());
    });

    test('the coordinator accepts it with no Android-specific knowledge',
        () async {
      // The coordinator is constructed exactly as production constructs it.
      // If A.8 had introduced a second abstraction this would not compile.
      final coordinator = BackgroundSyncCoordinator<int>(
        runDrain: (_) async => 0,
        scheduler: scheduler,
      );
      await coordinator.requestOpportunity(
          notBefore: DateTime.utc(2026, 10, 5, 12));

      expect(spy.calls, hasLength(1));
      expect(spy.calls.single.method,
          BackgroundSyncChannel.methodEnsureScheduled);
    });

    test('the Noop default is still installed when nobody installs Android',
        () async {
      final coordinator = BackgroundSyncCoordinator<int>(runDrain: (_) async => 0);
      await coordinator.requestOpportunity();
      expect(spy.calls, isEmpty,
          reason: 'the default scheduler must not touch any platform channel');
    });
  });

  group('A.8/P2 — what the scheduling message carries', () {
    test('it binds the request to the OS-level unique work name', () async {
      await scheduler.ensureScheduled(const BackgroundSyncRequest());
      expect(spy.argsOf(0)[BackgroundSyncChannel.keyUniqueWorkName],
          BackgroundSyncRequest.uniqueWorkName);
      expect(BackgroundSyncRequest.uniqueWorkName, 'fkss.sync.drain',
          reason: 'the durable work name is a contract with the OS');
    });

    test('notBefore travels as UTC epoch milliseconds', () async {
      final when = DateTime.utc(2026, 10, 5, 9, 30);
      await scheduler.ensureScheduled(BackgroundSyncRequest(notBefore: when));
      expect(spy.argsOf(0)[BackgroundSyncChannel.keyNotBeforeEpochMs],
          when.millisecondsSinceEpoch);
    });

    test('a local-time notBefore is normalised to UTC before it is sent',
        () async {
      final local = DateTime(2026, 10, 5, 9, 30);
      await scheduler.ensureScheduled(BackgroundSyncRequest(notBefore: local));
      expect(spy.argsOf(0)[BackgroundSyncChannel.keyNotBeforeEpochMs],
          local.toUtc().millisecondsSinceEpoch,
          reason: 'the device timezone must never shift a wake-up');
    });

    test('no notBefore means null, not an invented "now"', () async {
      await scheduler.ensureScheduled(const BackgroundSyncRequest());
      expect(spy.argsOf(0)[BackgroundSyncChannel.keyNotBeforeEpochMs], isNull,
          reason: 'inventing a clock here would be a second retry clock');
    });

    test('requiresNetwork is forwarded in both states', () async {
      await scheduler
          .ensureScheduled(const BackgroundSyncRequest(requiresNetwork: true));
      await scheduler
          .ensureScheduled(const BackgroundSyncRequest(requiresNetwork: false));
      expect(spy.argsOf(0)[BackgroundSyncChannel.keyRequiresNetwork], isTrue);
      expect(spy.argsOf(1)[BackgroundSyncChannel.keyRequiresNetwork], isFalse);
    });

    test('the default request requires network, which is what lets the OS '
        'wake us when connectivity returns', () async {
      await scheduler.ensureScheduled(const BackgroundSyncRequest());
      expect(spy.argsOf(0)[BackgroundSyncChannel.keyRequiresNetwork], isTrue);
    });

    test('the message carries a trigger only — never work, never credentials',
        () async {
      await scheduler.ensureScheduled(
          BackgroundSyncRequest(notBefore: DateTime.utc(2026), ));
      // Exhaustive: anything beyond these three keys would mean operation
      // data, a token or an identity had started travelling to the OS.
      expect(
          spy.argsOf(0).keys.toSet(),
          <String>{
            BackgroundSyncChannel.keyUniqueWorkName,
            BackgroundSyncChannel.keyNotBeforeEpochMs,
            BackgroundSyncChannel.keyRequiresNetwork,
          });
      final serialised = spy.argsOf(0).toString();
      for (final forbidden in const [
        'token',
        'owner',
        'user',
        'authorization',
        'password',
      ]) {
        expect(serialised.toLowerCase(), isNot(contains(forbidden)));
      }
    });
  });

  group('A.8/P2 — cancellation', () {
    test('cancel names the same unique work the scheduling named', () async {
      await scheduler.cancel();
      expect(spy.calls.single.method, BackgroundSyncChannel.methodCancel);
      expect(spy.argsOf(0)[BackgroundSyncChannel.keyUniqueWorkName],
          BackgroundSyncRequest.uniqueWorkName);
    });

    test('cancelOpportunity on the real coordinator reaches the platform',
        () async {
      final coordinator = BackgroundSyncCoordinator<int>(
          runDrain: (_) async => 0, scheduler: scheduler);
      await coordinator.requestOpportunity();
      await coordinator.cancelOpportunity();

      expect(spy.calls.map((c) => c.method).toList(), <String>[
        BackgroundSyncChannel.methodEnsureScheduled,
        BackgroundSyncChannel.methodCancel,
      ]);
      expect(coordinator.opportunityPending, isFalse);
    });
  });

  group('A.8/P2 — degradation is Noop, failure is reported', () {
    test('a missing native half behaves exactly like the Noop scheduler',
        () async {
      spy.uninstall();
      // Must not throw: an iOS build, a widget test or an older APK simply
      // gets no background sync, and foreground sync is unaffected.
      await scheduler.ensureScheduled(const BackgroundSyncRequest());
      await scheduler.cancel();
    });

    test('a real native failure propagates so the coordinator can re-arm',
        () async {
      spy.responder = (_) => throw PlatformException(code: 'alarm_denied');

      final coordinator = BackgroundSyncCoordinator<int>(
          runDrain: (_) async => 0, scheduler: scheduler);
      await expectLater(
          coordinator.requestOpportunity(), throwsA(isA<PlatformException>()));

      expect(coordinator.opportunityPending, isFalse,
          reason: 'a failed enqueue must not wedge the coordinator into '
              'believing an opportunity exists');
    });

    test('after a failed enqueue the next request tries again', () async {
      spy.responder = (_) => throw PlatformException(code: 'alarm_denied');
      final coordinator = BackgroundSyncCoordinator<int>(
          runDrain: (_) async => 0, scheduler: scheduler);
      await coordinator.requestOpportunity().catchError((Object _) {});

      spy.responder = null;
      await coordinator.requestOpportunity();
      expect(spy.calls, hasLength(2));
      expect(coordinator.opportunityPending, isTrue);
    });
  });

  group('A.8/P2 — idempotence is preserved end to end', () {
    test('five equivalent triggers produce ONE platform message', () async {
      final coordinator = BackgroundSyncCoordinator<int>(
          runDrain: (_) async => 0, scheduler: scheduler);
      for (var i = 0; i < 5; i++) {
        await coordinator.requestOpportunity(notBefore: DateTime.utc(2026));
      }
      expect(spy.calls, hasLength(1),
          reason: 'the coordinator deduplicates; the OS unique work name is '
              'the second layer, not the first');
    });

    test('consuming the opportunity re-arms the next request', () async {
      final coordinator = BackgroundSyncCoordinator<int>(
          runDrain: (_) async => 0, scheduler: scheduler);
      await coordinator.requestOpportunity();
      await coordinator.execute(SyncExecutionSource.background);
      await coordinator.requestOpportunity();
      expect(spy.calls, hasLength(2));
    });
  });
}
