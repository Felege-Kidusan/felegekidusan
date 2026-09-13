import 'dart:async';

import 'package:flutter/foundation.dart';

import 'api_service.dart';

/// P72 — one badge source for the whole app.
///
/// Polls `/notifications/summary` every 30s while the app is in the
/// foreground (the web dashboards use the same cadence). Screens and
/// the bell button listen to [badge] / [counts]; nothing else fetches
/// the summary, so one poll serves every surface.
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
  }

  /// One-shot refresh; returns the raw summary map (or null offline).
  Future<Map<String, dynamic>?> refresh() async {
    try {
      final res = await _api.getNotificationSummary();
      if (res.success && res.data is Map<String, dynamic>) {
        final map = (res.data as Map<String, dynamic>)['summary'];
        if (map is Map<String, dynamic>) {
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
}
