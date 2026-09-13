import 'dart:async';

import 'package:flutter/foundation.dart';

import 'api_service.dart';
import 'inbox_view_model.dart';

/// P72 — one badge source for the whole app.
///
/// Polls `/notifications/summary` every 30s while the app is in the
/// foreground (the web dashboards use the same cadence). Screens and
/// the bell button listen to [badge] / [counts]; nothing else fetches
/// the summary, so one poll serves every surface.
///
/// P74 Phase 3 — the poll is ETag-aware: the server answers 304 with
/// an empty body whenever nothing changed (same contract as the web),
/// so an idle poll costs a few header bytes and no JSON parse. The
/// stored ETag is only replaced on a full 200 response.
class NotificationService {
  NotificationService._();
  static final NotificationService instance = NotificationService._();

  final ApiService _api = ApiService();

  /// Total unread (alerts + announcements + messages + tasks).
  final ValueNotifier<int> badge = ValueNotifier<int>(0);

  /// Per-stream unread counts + role permissions, last known.
  final ValueNotifier<Map<String, dynamic>> summary =
      ValueNotifier<Map<String, dynamic>>({});

  Timer? _timer;
  bool _started = false;

  /// ETag of the last full summary response (P74 Phase 3).
  String? _summaryEtag;

  static const _pollInterval = Duration(seconds: 30);

  /// Start polling. Safe to call from every screen — only the first
  /// call actually starts the timer.
  void start() {
    if (_started) return;
    _started = true;
    refresh();
    _timer = Timer.periodic(_pollInterval, (_) => refresh());
  }

  /// Stop polling (sign-out / app lock).
  void stop() {
    _started = false;
    _timer?.cancel();
    _timer = null;
    badge.value = 0;
    summary.value = {};
    _summaryEtag = null;
  }

  /// One-shot refresh; returns the raw summary map — the freshly
  /// fetched one, or the current one unchanged when the server
  /// answered 304 (nothing changed). Null when offline / errored and
  /// nothing is known yet.
  Future<Map<String, dynamic>?> refresh() async {
    try {
      final res = await _api.getNotificationSummary(
          ifNoneMatch: _summaryEtag);
      if (res.notModified) {
        // Idle poll: keep badge + summary exactly as they are.
        return summary.value.isEmpty ? null : summary.value;
      }
      if (res.success && res.data is Map<String, dynamic>) {
        final map = (res.data as Map<String, dynamic>)['summary'];
        if (map is Map<String, dynamic>) {
          _summaryEtag = updateEtag(_summaryEtag, res.statusCode, res.etag);
          summary.value = map;
          badge.value = ((map['total'] ?? 0) as num).toInt();
          return map;
        }
      }
    } catch (_) {
      // Offline / auth-expired: keep the last known badge. The shell's
      // connectivity banner + auth handling own those states.
    }
    return null;
  }

  int count(String key) => ((summary.value[key] ?? 0) as num).toInt();

  /// P74 Phase 3 — optimistic local decrement after a mark-read (the
  /// write confirms in the background; the next poll reconciles). The
  /// zero floor matches the web's `Math.max(0, count - 1)`.
  void decrement(String key) {
    final s = Map<String, dynamic>.of(summary.value);
    s[key] = decrementCount(count(key));
    final total = count('total');
    s['total'] = decrementCount(total);
    summary.value = s;
    badge.value = ((s['total'] ?? 0) as num).toInt();
  }
}
