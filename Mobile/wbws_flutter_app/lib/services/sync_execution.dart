/// S2 Goal A.1 — Dart-side background sync execution core.
///
/// This library deliberately imports NOTHING. No Flutter, no sqflite, no
/// platform channels, no `workmanager`. That is what makes it runnable in a
/// plain Dart VM test and what keeps the future Android implementation from
/// leaking into the sync engine.
///
/// WHAT THIS IS
/// ------------
/// A single place that answers two questions:
///
///   1. "Where was this drain triggered from?"      -> [SyncExecutionSource]
///   2. "Ask the platform to let us drain later."   -> [BackgroundSyncScheduler]
///
/// and one coordinator, [BackgroundSyncCoordinator], that funnels every caller
/// — foreground, future Android worker, future other scheduler — into the ONE
/// authoritative drain that already exists in `SyncService`.
///
/// WHAT THIS IS NOT
/// ----------------
/// It is not a sync engine. It holds no outbox, no queue, no copy of pending
/// operations, no retry clock and no backoff maths. The durable outbox in
/// `local_db.dart` remains the only source of truth about what work exists,
/// and `claimNextLegacyOperation` remains the only gate that decides what is
/// eligible right now. A scheduler request means exactly one thing:
///
///     "a sync execution is needed"
///
/// It never means "carry these five operations somewhere else".
library;

/// Where a drain was triggered from.
///
/// Intentionally two values. S1 lineage already records *what* happened to an
/// operation; this records *why the engine was running at all*. A larger
/// taxonomy (`startup`, `manual`, `connectivity`, ...) was considered and
/// rejected for this increment: those are all reasons a FOREGROUND drain
/// started, they are already distinguishable from existing telemetry context,
/// and inventing them now would bake an unverified vocabulary into a durable
/// column. Add a value only when a real caller cannot be described.
enum SyncExecutionSource {
  /// The app was running and something in the app asked for a drain:
  /// connectivity returned, the user pulled to refresh, a screen saved work,
  /// `startAutoSync`/`nudge` fired. This is the pre-S2 behaviour and the
  /// default everywhere.
  foreground('foreground'),

  /// The operating system granted a background execution opportunity and the
  /// platform entry point invoked the same drain. No such caller exists yet;
  /// the value exists so the durable column and the lineage can represent it
  /// the moment the native integration lands.
  background('background');

  const SyncExecutionSource(this.storageValue);

  /// Stable on-disk spelling. Never renamed: it is written into
  /// `sync_attempts.execution_source` and read back by later versions.
  final String storageValue;

  /// Tolerant reader for rows written by any version.
  ///
  /// Unknown and NULL both resolve to [foreground], and that is a factual
  /// claim rather than a convenient default: every attempt row written before
  /// schema v37 was necessarily produced by an in-app drain, because no
  /// background execution path existed to write any other kind.
  static SyncExecutionSource fromStorage(Object? raw) {
    if (raw is String) {
      for (final candidate in SyncExecutionSource.values) {
        if (candidate.storageValue == raw) return candidate;
      }
    }
    return SyncExecutionSource.foreground;
  }
}

/// A request for the platform to grant a future execution opportunity.
///
/// Note what this class cannot carry: there is no payload field, no token
/// field, no user id, no operation list. That is enforced by construction, not
/// by convention, because a scheduler request may be persisted by the OS and
/// may outlive the session that created it. Everything the drain needs is
/// already durable in the outbox and is re-read under the current session at
/// execution time.
class BackgroundSyncRequest {
  const BackgroundSyncRequest({
    this.notBefore,
    this.requiresNetwork = true,
  });

  /// The single logical unit of background work this app can schedule.
  ///
  /// There is exactly one, because there is exactly one drain. The native
  /// implementation MUST bind this to platform-level unique work (on Android,
  /// `enqueueUniquePeriodicWork`/`ExistingWorkPolicy.KEEP`) so that the
  /// deduplication in [BackgroundSyncCoordinator] is reinforced by the OS and
  /// survives process death, which in-memory state cannot.
  static const String uniqueWorkName = 'fkss.sync.drain';

  /// Earliest moment the platform should consider running. Advisory only.
  ///
  /// This is NOT a retry clock. It is a hint derived from the outbox's own
  /// `next_attempt_at` so the OS is not woken pointlessly early. The authority
  /// on eligibility remains the claim query at execution time: if the platform
  /// runs us late, early, or twice, the claim still admits exactly the rows
  /// whose `next_attempt_at` has passed.
  final DateTime? notBefore;

  /// Whether the platform should wait for connectivity before running.
  final bool requiresNetwork;

  @override
  String toString() => 'BackgroundSyncRequest('
      'work: $uniqueWorkName, '
      'notBefore: ${notBefore?.toUtc().toIso8601String() ?? 'now'}, '
      'requiresNetwork: $requiresNetwork)';
}

/// The platform boundary: "ask the OS to let us sync later".
///
/// Deliberately tiny and deliberately ignorant of the sync engine. A native
/// implementation needs to know how to enqueue and cancel unique work; it must
/// never need to know what an outbox row is.
///
/// IMPLEMENTATION CONTRACT for the future native class:
///   * [ensureScheduled] MUST be idempotent. Calling it ten times must leave
///     exactly one pending unique work item, not ten.
///   * It MUST NOT transmit, serialise or inspect any operation payload.
///   * It MUST NOT carry credentials. The drain authenticates itself from the
///     durable session at execution time.
///   * It MAY be invoked when no work exists; a wasted wake-up is acceptable,
///     a duplicated business effect is not.
abstract class BackgroundSyncScheduler {
  /// Ensure that at most one background execution opportunity is pending.
  Future<void> ensureScheduled(BackgroundSyncRequest request);

  /// Drop any pending opportunity (for example at logout).
  Future<void> cancel();
}

/// Default scheduler used until the native integration lands.
///
/// It does nothing and says so. This is not a stub that pretends to work: with
/// this installed, background sync simply never happens, and foreground sync
/// is completely unaffected. That is the correct behaviour for an app shipped
/// before the platform implementation exists.
class NoopBackgroundSyncScheduler implements BackgroundSyncScheduler {
  const NoopBackgroundSyncScheduler();

  @override
  Future<void> ensureScheduled(BackgroundSyncRequest request) async {}

  @override
  Future<void> cancel() async {}
}

/// Runs the one authoritative drain.
///
/// Generic in its result type purely so this library can stay import-free;
/// `SyncService` supplies `Future<SyncResult> Function(SyncExecutionSource)`.
typedef SyncDrainRunner<R> = Future<R> Function(SyncExecutionSource source);

/// The single funnel every trigger passes through.
///
/// ```text
///   foreground trigger ─┐
///   future OS worker  ──┼─> BackgroundSyncCoordinator ─> SyncService drain ─> outbox
///   future scheduler  ──┘
/// ```
///
/// The coordinator owns exactly two responsibilities and nothing else:
/// deduplicating *scheduling requests*, and labelling an execution with its
/// [SyncExecutionSource]. It does not decide what is eligible, does not retry,
/// does not back off and does not touch the database.
class BackgroundSyncCoordinator<R> {
  BackgroundSyncCoordinator({
    required SyncDrainRunner<R> runDrain,
    BackgroundSyncScheduler scheduler = const NoopBackgroundSyncScheduler(),
  })  : _runDrain = runDrain,
        _scheduler = scheduler;

  final SyncDrainRunner<R> _runDrain;
  final BackgroundSyncScheduler _scheduler;

  bool _opportunityPending = false;

  /// Whether a background opportunity has been requested and not yet consumed.
  bool get opportunityPending => _opportunityPending;

  /// Ask for a background execution opportunity, at most one at a time.
  ///
  /// Every equivalent caller — connectivity returned, app resumed, a retry came
  /// due, startup — collapses into a single pending request, because there is
  /// only ever one thing to ask for: "drain the outbox".
  ///
  /// DURABILITY, STATED HONESTLY: [_opportunityPending] is in-memory and does
  /// NOT survive process death. It is an optimisation, not a correctness
  /// mechanism. Correctness has two other layers that do survive: the OS-level
  /// unique work name ([BackgroundSyncRequest.uniqueWorkName]), and the fact
  /// that a redundant execution is harmless — the claim query admits only
  /// eligible rows and per-operation idempotency makes a re-send safe. The
  /// worst case after a process restart is one extra scheduling call, never a
  /// duplicated business effect.
  Future<void> requestOpportunity({
    DateTime? notBefore,
    bool requiresNetwork = true,
  }) async {
    if (_opportunityPending) return;
    _opportunityPending = true;
    try {
      await _scheduler.ensureScheduled(BackgroundSyncRequest(
        notBefore: notBefore,
        requiresNetwork: requiresNetwork,
      ));
    } catch (_) {
      // A failed enqueue must not silently wedge the coordinator into a state
      // where it believes an opportunity exists that the platform never took.
      _opportunityPending = false;
      rethrow;
    }
  }

  /// Execute the authoritative drain now, labelled with its origin.
  ///
  /// This is the ONLY way a background caller may run sync. It is the same
  /// method the foreground uses, with a different label — not a second engine.
  Future<R> execute(SyncExecutionSource source) {
    // The opportunity (if any) is being consumed right now.
    _opportunityPending = false;
    return _runDrain(source);
  }

  /// Abandon any pending opportunity — for example on logout.
  ///
  /// Note this cancels the *wake-up*, never the work: pending operations stay
  /// in the durable outbox, owned by their original user, and are drained when
  /// that user is active again. Deleting them here would be a data-loss bug
  /// masquerading as account isolation.
  Future<void> cancelOpportunity() async {
    _opportunityPending = false;
    await _scheduler.cancel();
  }
}
