import 'dart:async';
import 'dart:math';

import 'package:flutter/widgets.dart';

import 'api_service.dart';
import 'comm_store.dart';
import 'connectivity_service.dart';
import 'messaging_view_model.dart';

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

  /// SyncService's ladder, extended: a queued chat message can wait
  /// longer than an attendance batch. Full jitter rides on top.
  static const _backoff = <int>[2, 5, 12, 30, 60, 120, 300];

  void start() {
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
    _draining = false;
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
    if (!_started) start();
    if (!_api.isLoggedIn) return; // entries wait; logout wipes them
    Timer(delay, _drain);
  }

  Future<void> _drain() async {
    if (!_started) return;
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
        await _drainOnce();
      } while (_queued && pass < 10); // safety valve against loops
      _scheduleNextRetry();
    } finally {
      _draining = false;
    }
  }

  /// One FIFO pass over the due pending entries. Not-due entries are
  /// left alone (their timer is rescheduled from the DB afterwards).
  Future<void> _drainOnce() async {
    final pending = await CommStore.instance.pendingOutbox();
    for (final e in pending) {
      if (!_started || !_api.isLoggedIn) return; // stopped mid-pass (sign-out)
      final tag = e['client_tag']?.toString() ?? '';
      final threadId = (e['thread_id'] as num?)?.toInt() ?? 0;
      final body = e['body']?.toString() ?? '';
      if (tag.isEmpty || threadId <= 0 || body.isEmpty) continue;

      final waitUntil =
          DateTime.tryParse(e['next_attempt_at']?.toString() ?? '');
      if (waitUntil != null && DateTime.now().isBefore(waitUntil)) {
        continue; // not due yet — backoff owns it
      }

      final res =
          await _api.sendMessage(threadId, body, clientTag: tag);
      if (res.success) {
        await CommStore.instance.deleteOutbox(tag);
        notifyListeners(); // screens drop the bubble + poll
      } else if (isTransientSendFailure(
          res.isNetworkError, res.statusCode)) {
        final attempts = ((e['attempts'] as num?)?.toInt() ?? 0) + 1;
        final rung = _backoff[attempts.clamp(1, _backoff.length) - 1];
        // Full jitter: uniform in (0, rung] — desynchronizes a whole
        // school's phones retrying after the same outage.
        final jitter = rung * (_random.nextDouble() * 0.9 + 0.1);
        await CommStore.instance.updateOutbox(tag, {
          'attempts': attempts,
          'next_attempt_at':
              DateTime.now().add(Duration(seconds: jitter.ceil())).toIso8601String(),
        });
        // No notify: the bubble stays "pending" (clock) — WhatsApp
        // shows the clock the whole time the message is queued.
      } else {
        await CommStore.instance.updateOutbox(tag, {
          'state': 'failed',
          'fail_reason': res.message ?? 'Could not send.',
        });
        notifyListeners(); // bubble flips to failed + tap-to-retry
      }
    }
  }

  /// Anchor the retry timer on the earliest due entry in the DB (or
  /// cancel it when nothing waits). A stale timer simply re-drains
  /// and reschedules — the DB is the truth, the timer is a hint.
  void _scheduleNextRetry() {
    _retryTimer?.cancel();
    _retryTimer = null;
    CommStore.instance.outboxNextDue().then((due) {
      if (!_started || due == null) return;
      final at = DateTime.tryParse(due);
      if (at == null) return;
      final wait = at.difference(DateTime.now());
      _retryTimer = Timer(
          wait <= Duration.zero
              ? const Duration(milliseconds: 50)
              : wait + const Duration(milliseconds: 50),
          _drain);
    });
  }
}
