/// S3 Goal A.8 — the Android half of the A.1 scheduler boundary.
///
/// This is the FIRST real implementation of [BackgroundSyncScheduler]. It is
/// deliberately the only thing in the app that knows a platform channel is
/// involved in background sync, and it is deliberately incapable of doing
/// anything except asking the OS for a wake-up.
///
/// WHY THIS FILE EXISTS SEPARATELY FROM `sync_execution.dart`.
/// `sync_execution.dart` imports nothing at all — that is what keeps the sync
/// engine runnable in a plain Dart VM test and what stops Android details from
/// leaking into the coordinator. The platform dependency lives here instead,
/// behind the abstraction that already existed. The coordinator is unchanged
/// and still has no idea Android exists.
///
/// WHAT THIS CLASS MAY NOT DO, AND DOES NOT DO:
///   * It never inspects the outbox, the schema, or any operation.
///   * It never carries credentials, a user id, an authorization version or a
///     payload. [BackgroundSyncRequest] cannot express them, by construction.
///   * It never decides eligibility. `notBefore` is forwarded as the advisory
///     hint the outbox computed; the claim query at execution time remains the
///     only authority on what is actually due.
///   * It is not a second scheduler abstraction. It implements the existing
///     one, alongside [NoopBackgroundSyncScheduler].
library;

import 'package:flutter/services.dart';

import 'sync_execution.dart';

/// The single native<->Dart contract for background sync.
///
/// Both directions travel on one channel: Dart asks the OS to schedule
/// ([methodEnsureScheduled]/[methodCancel]) and the OS asks Dart to run
/// ([methodRunBackgroundSync], handled in `background_sync_bridge.dart`).
/// Keeping both names here means the contract can be read, and mutated by the
/// harness, in one place.
class BackgroundSyncChannel {
  const BackgroundSyncChannel._();

  /// Matches the three channels MainActivity already registers
  /// (`fkss.app/updater`, `fkss.app/app_lock`, `fkss.app/device`).
  static const String name = 'fkss.app/background_sync';

  /// Dart -> native. Ensure at most one pending opportunity exists.
  static const String methodEnsureScheduled = 'ensureScheduled';

  /// Dart -> native. Drop any pending opportunity.
  static const String methodCancel = 'cancel';

  /// Native -> Dart. The OS granted the opportunity; run the drain.
  static const String methodRunBackgroundSync = 'runBackgroundSync';

  /// Dart -> native. A cold-start entry point has installed its bridge and is
  /// ready for the native side to invoke [methodRunBackgroundSync].
  static const String methodBackgroundReady = 'backgroundReady';

  // ── Argument keys. Named constants rather than inline strings so that a
  // rename cannot silently desynchronise the two sides of the contract.
  static const String keyUniqueWorkName = 'uniqueWorkName';
  static const String keyNotBeforeEpochMs = 'notBeforeEpochMs';
  static const String keyRequiresNetwork = 'requiresNetwork';
  static const String keySource = 'source';
  static const String keyInvocationId = 'invocationId';
}

/// Asks Android for a background execution opportunity.
///
/// Installed only on Android, and only once, from the bootstrap in `main.dart`.
/// Every other platform — and any build whose native half is missing — keeps
/// the [NoopBackgroundSyncScheduler] behaviour, see [_invoke].
class AndroidBackgroundSyncScheduler implements BackgroundSyncScheduler {
  /// [channel] is injectable for tests only; production uses the const
  /// channel. This mirrors `SyncService.withCollaborators`: one narrow seam,
  /// not a dependency-injection framework.
  AndroidBackgroundSyncScheduler({MethodChannel? channel})
      : _channel = channel ?? const MethodChannel(BackgroundSyncChannel.name);

  final MethodChannel _channel;

  @override
  Future<void> ensureScheduled(BackgroundSyncRequest request) {
    return _invoke(BackgroundSyncChannel.methodEnsureScheduled, {
      // The OS-level unique work name. Passing it explicitly is what lets the
      // native side bind this request to one replaceable alarm slot, so the
      // coordinator's in-memory deduplication is reinforced by something that
      // survives process death, exactly as A.1 required.
      BackgroundSyncChannel.keyUniqueWorkName:
          BackgroundSyncRequest.uniqueWorkName,

      // UTC epoch milliseconds, or null for "as soon as the OS allows".
      // Sent as a primitive because a platform-channel message must survive
      // serialisation; the native side does no date arithmetic beyond
      // clamping a past value to "now".
      BackgroundSyncChannel.keyNotBeforeEpochMs:
          request.notBefore?.toUtc().millisecondsSinceEpoch,

      // Forwarded so the native side can apply an OS-level network constraint
      // where the chosen mechanism supports one. Where it does not, the final
      // network decision stays in Dart, where it already lives — this flag
      // never becomes a second connectivity implementation in Kotlin.
      BackgroundSyncChannel.keyRequiresNetwork: request.requiresNetwork,
    });
  }

  @override
  Future<void> cancel() {
    return _invoke(BackgroundSyncChannel.methodCancel, {
      BackgroundSyncChannel.keyUniqueWorkName:
          BackgroundSyncRequest.uniqueWorkName,
    });
  }

  /// Sends one call, converting "no native half here" into Noop behaviour.
  ///
  /// [MissingPluginException] is swallowed DELIBERATELY and is the only thing
  /// swallowed. It means the channel is unregistered — a non-Android host, a
  /// widget test, or an older APK being driven by newer Dart — and the correct
  /// response is precisely what [NoopBackgroundSyncScheduler] does: return
  /// normally and let background sync simply not happen. Foreground sync is
  /// untouched either way.
  ///
  /// A [PlatformException] is NOT swallowed. That is a native failure that
  /// really happened, and the coordinator needs it: it resets its own pending
  /// flag before rethrowing, so the next drain re-requests instead of
  /// believing in an opportunity the OS never took. `SyncService` already
  /// wraps the request in `unawaited(...catchError(...))`, so a drain is never
  /// failed or delayed by it.
  Future<void> _invoke(String method, Map<String, Object?> arguments) async {
    try {
      await _channel.invokeMethod<void>(method, arguments);
    } on MissingPluginException {
      return;
    }
  }
}
