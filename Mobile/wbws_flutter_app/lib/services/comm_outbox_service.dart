import 'dart:async';
import 'dart:math';

import 'package:flutter/widgets.dart';

import 'api_service.dart';
import 'comm_store.dart';
import 'connectivity_service.dart';
import 'outbox_policy.dart';

/// O3 (offline-first architecture) — the comm outbox worker.
///
/// WhatsApp's write path, ported to our stack: the UI writes ONE
/// SQLite row (the outbox entry) and renders the bubble in the same
/// frame; a single worker owns the POST. Sends survive process death
/// and airplane mode, and drain on enqueue, on the radio coming back,
/// on app resume, and on their own backoff ladder with full jitter.
///
/// Exactly-once becomes authoritative when server migration 046 lands
/// (messages.client_tag + unique index). Until then this worker's
/// single-flight drain is the only dup guard — the exact window
/// today's optimistic send already has, so nothing regresses.
///
/// Mirrors SyncService's shape on purpose (Gmail outbox pattern):
/// kicks funnel through one gate, an in-flight drain absorbs later
/// kicks as "run again", and the ladder caps how fast we hammer a
/// struggling server.
class CommOutboxService extends ChangeNotifier
    with WidgetsBindingObserver {
  CommOutboxService._();
  static final CommOutboxService instance = CommOutboxService._();

  final _api = ApiService();
  final _random = Random(); // jitter only — not a security use

  StreamSubscription<bool>? _radioSub;
  Timer? _retryTimer;
  bool _started = false;

  bool _draining = false;
  bool _queued = false;
  bool Function()? activeSessionGate;
  int Function()? sessionGenerationProvider;
  bool Function()? drainEnabledGate;

  bool get _drainsAllowed => drainEnabledGate?.call() != false;

  bool _ownsGeneration(int generation) =>
      activeSessionGate?.call() != false &&
      generation == (sessionGenerationProvider?.call() ?? generation);

  void start() {
    if (activeSessionGate?.call() == false || !_drainsAllowed) return;
    if (_started) return;
    _started = true;
    WidgetsBinding.instance.addObserver(this);
    _radioSub?.cancel();
    _radioSub = ConnectivityService().statusStream.listen((hasLink) {
      // Radio came back — drain before the user finishes switching
      // apps (same 500 ms settle as SyncService).
      if (hasLink) kick(delay: const Duration(milliseconds: 500));
    });
    kick(delay: const Duration(milliseconds: 800));
  }

  void stop() {
    _started = false;
    WidgetsBinding.instance.removeObserver(this);
    _radioSub?.cancel();
    _radioSub = null;
    _retryTimer?.cancel();
    _retryTimer = null;
    // An in-flight pass owns this flag until its finally block. A newly
    // activated generation queues behind it instead of draining in parallel.
    _queued = false;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // D4 parity: resume is the cheapest catch-up trigger there is.
    if (state == AppLifecycleState.resumed) kick();
  }

  /// Request a drain. Safe from anywhere, any number of times —
  /// rapid sends collapse into one pass plus a re-check.
  void kick({Duration delay = const Duration(milliseconds: 300)}) {
    if (activeSessionGate?.call() == false || !_drainsAllowed) return;
    if (!_started) start();
    if (!_api.isLoggedIn) return; // entries wait behind session recovery
    final generation = sessionGenerationProvider?.call() ?? 0;
    _retryTimer?.cancel();
    _retryTimer = Timer(delay, () {
      _retryTimer = null;
      _drain(generation);
    });
  }

  Future<void> _drain(int generation) async {
    if (!_started || !_ownsGeneration(generation) || !_drainsAllowed) return;
    if (!_api.isLoggedIn) return;
    if (_draining) {
      _queued = true; // a pass is running — re-check when it ends
      return;
    }
    _draining = true;
    try {
      var pass = 0;
      do {
        _queued = false;
        pass++;
        await _drainOnce(generation);
      } while (_queued &&
          _ownsGeneration(generation) &&
          _drainsAllowed &&
          pass < 10); // safety valve against loops
      if (_ownsGeneration(generation) && _drainsAllowed) {
        _scheduleNextRetry(generation);
      }
    } finally {
      _draining = false;
      if (_queued && activeSessionGate?.call() != false) {
        kick(delay: Duration.zero);
      }
    }
  }

  /// One FIFO pass over the due pending entries. Not-due entries are
  /// left alone (their timer is rescheduled from the DB afterwards).
  Future<void> _drainOnce(int generation) async {
    for (var guard = 0; guard < 100; guard++) {
      // Finish settling a request that was already claimed, but never claim a
      // second row after the remote containment flag turns off.
      if (!_started ||
          !_api.isLoggedIn ||
          !_ownsGeneration(generation) ||
          !_drainsAllowed) {
        return;
      }
      final claim = await CommStore.instance.claimNextDueHead(
        ownerUserId: _api.userId,
        authorizationVersion: _api.authorizationVersion,
        runtimeGeneration: generation,
      );
      if (!_ownsGeneration(generation) || claim == null) return;

      final response = await _api.sendMessage(
        claim.threadId,
        claim.body,
        clientTag: claim.clientTag,
      );
      if (response.sessionSuperseded || !_ownsGeneration(generation)) return;
      final decision = classifyOutboxResponse(
        response.toOutboxEvidence(
          automaticAttemptCount: claim.attemptCount,
        ),
      );
      if (decision == OutboxDecision.supersededSession) return;
      final nextAttempt = decision == OutboxDecision.retryable
          ? nextOutboxAttemptAt(
              attemptCount: claim.attemptCount,
              retryAfterSeconds: response.retryAfterSeconds,
              randomUnit: _random.nextDouble(),
            )
          : null;
      final settlement = await CommStore.instance.settleClaim(
        claim: claim,
        decision: decision,
        currentOwnerUserId: _api.userId,
        currentAuthorizationVersion: _api.authorizationVersion,
        currentRuntimeGeneration: generation,
        failureCode: response.errorCode,
        failureHttpStatus:
            response.statusCode == 0 ? null : response.statusCode,
        failureMessage:
            decision == OutboxDecision.accepted ? null : response.message,
        nextAttemptAt: nextAttempt,
      );
      if (settlement == CommSettlementResult.supersededSession) return;
      if (settlement == CommSettlementResult.supersededLocal) {
        _queued = true;
        continue;
      }
      if (decision == OutboxDecision.pauseForAuthentication ||
          decision == OutboxDecision.pauseForAuthorizationScope) {
        return;
      }
      if (decision == OutboxDecision.accepted ||
          decision == OutboxDecision.needsAttention ||
          decision == OutboxDecision.resolvedConflict) {
        notifyListeners();
      }
      // A retry/attention head blocks only its own thread. The next claim query
      // can still select the due head of another thread in this same pass.
    }
    // The pass limit is a yield point, not a reason to strand row 101.
    if (_ownsGeneration(generation) && _drainsAllowed) _queued = true;
  }

  /// Anchor the retry timer on the earliest due entry in the DB (or
  /// cancel it when nothing waits). A stale timer simply re-drains
  /// and reschedules — the DB is the truth, the timer is a hint.
  void _scheduleNextRetry(int generation) {
    _retryTimer?.cancel();
    _retryTimer = null;
    CommStore.instance.outboxNextDue().then((due) {
      if (!_started ||
          !_ownsGeneration(generation) ||
          !_drainsAllowed ||
          due == null) return;
      final at = DateTime.tryParse(due);
      if (at == null) return;
      final wait = at.difference(DateTime.now());
      _retryTimer = Timer(
          wait <= Duration.zero
              ? const Duration(milliseconds: 50)
              : wait + const Duration(milliseconds: 50),
          () => _drain(generation));
    }).catchError((Object _, StackTrace __) {
      // Session deactivation can win the race with this read; the next active
      // generation owns scheduling and this worker must stay silent.
    });
  }
}
