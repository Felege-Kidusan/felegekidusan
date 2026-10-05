/// S3 Goal A.8 Phase 3 — the Dart background entry point.
///
/// This is the other half of the contract declared in
/// `android_background_sync_scheduler.dart`: Android has granted the
/// opportunity and now asks Dart to run it.
///
/// WHAT THIS FILE IS
/// -----------------
/// A parser and a router, and nothing else. It turns one platform message
/// into one typed [BackgroundSyncInvocation] and hands it to the ONE
/// authoritative execution entry point, `SyncService.runSyncNow`, which goes
/// through the existing `BackgroundSyncCoordinator`.
///
/// WHAT THIS FILE IS NOT
/// ---------------------
///   * Not a second sync engine, not a second drain, not a second
///     `SyncService`, not a second `LocalDb`, not a second coordinator.
///     It calls `runSyncNow` exactly the way the foreground does — the only
///     difference is the label it passes.
///   * Not a second authentication system. It performs NO session, owner,
///     authorization-version or logged-in check of its own. Every one of
///     those gates already exists inside `_syncAllForGeneration`
///     (`_ownsGeneration(generation) && _api.isLoggedIn`) and is reached by
///     this path unchanged. Re-checking here would be a second gate that
///     could drift from the real one.
///   * Not a second duplicate-execution guard. A.2/A.5 proved the
///     `_inflight`/`_queued` coalescing in `SyncService`; a duplicate native
///     callback is routed straight into it and joins the in-flight drain.
///
/// PROVENANCE IS EXPLICIT, NEVER INFERRED
/// --------------------------------------
/// The source is read from a named field in the message and must spell
/// `background` exactly. It is never derived from the thread, the Android
/// component, the lifecycle state, a hidden singleton flag, the caller stack
/// or timing. A message that does not say `background` is REJECTED rather
/// than defaulted, which is the one place in the app where
/// [SyncExecutionSource.fromStorage]'s tolerant fallback would be wrong:
/// that reader exists to interpret old database rows, not to guess at a live
/// invocation.
library;

import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb, visibleForTesting;
import 'package:flutter/services.dart';

import 'android_background_sync_scheduler.dart';
import 'sync_execution.dart';
import 'sync_service.dart';

/// One background execution request, after validation.
///
/// Immutable and tiny on purpose: there is nothing for the OS to tell us
/// except "now is a good time". Everything else — which operations exist, who
/// owns them, whether they are due — is re-read from the durable outbox under
/// the current session when the drain runs.
class BackgroundSyncInvocation {
  const BackgroundSyncInvocation({
    required this.source,
    required this.invocationId,
  });

  /// Always [SyncExecutionSource.background]; [parse] refuses anything else.
  final SyncExecutionSource source;

  /// Opaque id minted by the native side, echoed back so the OS can correlate
  /// its callback with the completion it receives. It is diagnostic only: it
  /// is never used to deduplicate, because deduplication belongs to the
  /// coordinator and to `SyncService`, not here.
  final String invocationId;

  /// Strict reader for the native message. Returns null for anything invalid.
  ///
  /// Rejects, deliberately:
  ///   * a non-map argument, or a null argument;
  ///   * a missing or non-string `source`;
  ///   * a `source` this build does not know;
  ///   * `source: 'foreground'` — the background entry point producing a
  ///     foreground label would mean the native side is mislabelling
  ///     provenance, and silently accepting it would corrupt the durable
  ///     `sync_attempts.execution_source` lineage A.7 established;
  ///   * a missing, non-string or empty `invocationId`.
  static BackgroundSyncInvocation? parse(Object? raw) {
    if (raw is! Map) return null;
    final map = Map<Object?, Object?>.from(raw);

    final rawSource = map[BackgroundSyncChannel.keySource];
    if (rawSource is! String) return null;
    SyncExecutionSource? source;
    for (final candidate in SyncExecutionSource.values) {
      if (candidate.storageValue == rawSource) source = candidate;
    }
    if (source != SyncExecutionSource.background) return null;

    final rawId = map[BackgroundSyncChannel.keyInvocationId];
    if (rawId is! String || rawId.isEmpty) return null;

    return BackgroundSyncInvocation(source: source!, invocationId: rawId);
  }
}

/// Runs the authoritative drain. Injectable for tests only — production uses
/// [BackgroundSyncBridge.runViaSyncService], which is the real singleton.
typedef BackgroundDrainRunner = Future<SyncResult> Function(
    SyncExecutionSource source);

/// Receives the OS callback and routes it into the existing coordinator.
class BackgroundSyncBridge {
  /// One narrow seam, matching the `SyncService.withCollaborators` precedent:
  /// a channel and a runner, both defaulted to production.
  BackgroundSyncBridge({
    MethodChannel? channel,
    BackgroundDrainRunner? runDrain,
  })  : _channel = channel ?? const MethodChannel(BackgroundSyncChannel.name),
        _runDrain = runDrain ?? runViaSyncService;

  /// THE production route. `runSyncNow` is the A.1 authoritative entry point:
  /// it sets no generation (so the drain validates against whichever session
  /// is current at execution time, which is exactly what a wake-up that may
  /// fire long after it was scheduled requires) and goes through
  /// `BackgroundSyncCoordinator.execute`.
  static Future<SyncResult> runViaSyncService(SyncExecutionSource source) =>
      SyncService().runSyncNow(source: source);

  final MethodChannel _channel;
  final BackgroundDrainRunner _runDrain;

  /// Result keys sent back to the OS.
  static const String keySynced = 'synced';
  static const String keyFailed = 'failed';
  static const String keyMessage = 'message';

  /// Error code returned to native when the message is not a valid background
  /// invocation. Native treats it as a permanent failure of that callback and
  /// does NOT retry it, because retrying a malformed message cannot help.
  static const String errorInvalidInvocation = 'invalid_background_invocation';

  void install() => _channel.setMethodCallHandler(handleCall);

  @visibleForTesting
  Future<Object?> handleCall(MethodCall call) async {
    if (call.method != BackgroundSyncChannel.methodRunBackgroundSync) {
      // Not ours. Behave like an unimplemented channel method rather than
      // silently succeeding, so a contract drift is loud.
      throw MissingPluginException(
          'BackgroundSyncBridge does not implement ${call.method}');
    }

    final invocation = BackgroundSyncInvocation.parse(call.arguments);
    if (invocation == null) {
      throw PlatformException(
        code: errorInvalidInvocation,
        message: 'A background invocation must name its source explicitly as '
            '"${SyncExecutionSource.background.storageValue}" and carry a '
            'non-empty "${BackgroundSyncChannel.keyInvocationId}".',
      );
    }

    // The ONE call. Note there is no session check, no owner lookup and no
    // connectivity check above this line: all three already happen inside the
    // drain, and duplicating them here would create a second gate.
    final result = await _runDrain(invocation.source);

    return <String, Object?>{
      BackgroundSyncChannel.keyInvocationId: invocation.invocationId,
      keySynced: result.synced,
      keyFailed: result.failed,
      keyMessage: result.message,
    };
  }
}

/// Installs both halves of the Android producer. Called once, from the
/// bootstrap in `main.dart`, after the session has been reconciled.
///
/// Non-Android hosts keep [NoopBackgroundSyncScheduler] and never register a
/// handler, so nothing about their behaviour changes.
///
/// Never throws: a producer that cannot be installed must degrade to "no
/// background sync", never to "the app will not start".
Future<void> installAndroidBackgroundSyncProducer() async {
  if (kIsWeb) return;
  try {
    if (!Platform.isAndroid) return;
    SyncService().backgroundScheduler = AndroidBackgroundSyncScheduler();
    BackgroundSyncBridge().install();
  } catch (_) {
    // Deliberately silent and deliberately total.
  }
}
