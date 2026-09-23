import 'dart:async';
import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

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
///
/// P1 audit D4 — the poll is lifecycle-aware: it observes
/// AppLifecycleState and stops the timer whenever the app is not
/// resumed (the web pauses on visibilitychange; same battery rule).
/// Returning to the foreground restarts the timer and refreshes
/// immediately, so the badge is fresh the moment the app is visible
/// again.
class NotificationService with WidgetsBindingObserver {
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
  bool Function()? activeSessionGate;
  int Function()? sessionGenerationProvider;

  bool _ownsGeneration(int generation) =>
      _started &&
      activeSessionGate?.call() != false &&
      generation == (sessionGenerationProvider?.call() ?? generation);

  /// ETag of the last full summary response (P74 Phase 3).
  String? _summaryEtag;

  /// O1 (offline-first): the last known summary is persisted so the
  /// bell renders its badge the moment the app starts — the first
  /// network refresh then reconciles it. WhatsApp shows the last
  /// known state instantly for the same reason.
  static const _summaryCacheKey = 'comm_summary_cache';

  static const _pollInterval = Duration(seconds: 30);

  /// Start polling. Safe to call from every screen — only the first
  /// call actually starts the timer.
  void start() {
    if (activeSessionGate?.call() == false) return;
    if (_started) return;
    _started = true;
    final generation = sessionGenerationProvider?.call() ?? 0;
    WidgetsBinding.instance.addObserver(this);
    _restoreCachedSummary(generation);
    refresh();
    _timer = Timer.periodic(_pollInterval, (_) => refresh());
  }

  Future<void> _restoreCachedSummary(int generation) async {
    if (summary.value.isNotEmpty || !_ownsGeneration(generation)) return;
    try {
      final prefs = await SharedPreferences.getInstance();
      if (!_ownsGeneration(generation)) return;
      final raw = prefs.getString(_summaryCacheKey);
      if (raw == null || raw.isEmpty) return;
      final map = jsonDecode(raw);
      if (_ownsGeneration(generation) &&
          map is Map<String, dynamic> &&
          map.isNotEmpty) {
        summary.value = map;
        badge.value = ((map['total'] ?? 0) as num).toInt();
      }
    } catch (_) {
      // Corrupt cache: ignore — the network refresh owns the truth.
    }
  }

  /// Stop polling (sign-out / app lock).
  void stop() {
    if (!_started) return;
    _started = false;
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    _timer = null;
    badge.value = 0;
    summary.value = {};
    _summaryEtag = null;
  }

  /// The coordinator calls this inside the destructive purge boundary. Auth
  /// loss/reauth preserves this private cache for the same owner.
  Future<bool> hasPersistedState() async {
    final prefs = await SharedPreferences.getInstance();
    final value = prefs.getString(_summaryCacheKey);
    return value != null && value.isNotEmpty;
  }

  Future<void> clearPersistedState() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_summaryCacheKey);
  }

  Future<void> _persistSummary(
      Map<String, dynamic> map, int generation) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      if (!_ownsGeneration(generation)) return;
      await prefs.setString(_summaryCacheKey, jsonEncode(map));
    } catch (_) {
      // Persistence is best-effort; the in-memory value is already set.
    }
  }

  /// P1 audit D4 — poll only while the app is visible: the timer is
  /// cancelled on every non-resumed state (paused/hidden/detached)
  /// and restarted with an immediate refresh on resume. The ETag
  /// keeps an idle restart cheap.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!_started) return;
    if (state == AppLifecycleState.resumed) {
      if (_timer == null) {
        _timer = Timer.periodic(_pollInterval, (_) => refresh());
      }
      refresh();
    } else {
      _timer?.cancel();
      _timer = null;
    }
  }

  /// One-shot refresh; returns the raw summary map — the freshly
  /// fetched one, or the current one unchanged when the server
  /// answered 304 (nothing changed). Null when offline / errored and
  /// nothing is known yet.
  Future<Map<String, dynamic>?> refresh() async {
    final generation = sessionGenerationProvider?.call() ?? 0;
    if (!_ownsGeneration(generation)) return null;
    try {
      final res = await _api.getNotificationSummary(
          ifNoneMatch: _summaryEtag);
      if (!_ownsGeneration(generation) || res.sessionSuperseded) return null;
      if (res.notModified) {
        // Idle poll: keep badge + summary exactly as they are.
        return summary.value.isEmpty ? null : summary.value;
      }
      if (res.success && res.data is Map<String, dynamic>) {
        final map = (res.data as Map<String, dynamic>)['summary'];
        if (map is Map<String, dynamic>) {
          _summaryEtag = updateEtag(_summaryEtag, res.statusCode, res.etag);
          if (!_ownsGeneration(generation)) return null;
          summary.value = map;
          badge.value = ((map['total'] ?? 0) as num).toInt();
          _persistSummary(map, generation);
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
