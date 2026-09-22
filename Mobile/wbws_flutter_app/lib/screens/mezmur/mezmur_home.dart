import 'dart:async';

import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/config.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/feature_tile.dart';
import '../../widgets/loading_skeleton.dart';
import 'mezmur_analytics.dart';
import '../reviews/review_inbox_screen.dart';
import 'mezmur_attendance.dart';
import 'mezmur_hymns.dart';
import 'mezmur_downloads.dart';
import '../../widgets/notification_bell_button.dart';

/// Mezmur Department hub (mobile) — Ethiopian greeting, feature
/// tiles and recent attendance days. Attendance itself lives in
/// MezmurAttendanceScreen (teachers-grade UX, section-based).
class MezmurHomeScreen extends StatefulWidget {
  const MezmurHomeScreen({super.key});
  @override
  State<MezmurHomeScreen> createState() => MezmurHomeScreenState();
}

class MezmurHomeScreenState extends State<MezmurHomeScreen>
    with WidgetsBindingObserver {
  final _api = ApiService();
  final _db = LocalDb();
  bool _loading = true;
  String? _error;
  List<dynamic> _days = [];

  // P1-C local-first state: single-flight refresh guard + cache
  // freshness ('· updated HH:MM' for the stale banner).
  bool _refreshing = false;
  String? _fresh;
  StreamSubscription<bool>? _radioSub;

  bool get _isStaff {
    final role = _api.userRole;
    return role == UserRoles.mezmurDept ||
        role == UserRoles.schoolAdmin ||
        role == UserRoles.superAdmin;
  }

  @override
  void initState() {
    super.initState();
    // P1-C: radio-return + app-resume refresh (the established
    // P1-A/P1-B screen-local pattern — the shell's tab-return
    // refresh() already exists and flows through the same
    // single-flight _load). Cached rows stay visible throughout.
    WidgetsBinding.instance.addObserver(this);
    _load();
    _radioSub = ConnectivityService().statusStream.listen((online) {
      if (online) {
        Future.delayed(const Duration(seconds: 1), () {
          if (mounted) _load();
        });
      }
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _radioSub?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _load();
  }

  void refresh() => _load();

  /// P1-C: local-first — SQLite renders first (offline-capable); the
  /// server refresh runs only when the radio is up, persists page 1,
  /// and the list re-renders from the store. A failed or skipped
  /// refresh keeps the cached rows under an honest stale banner —
  /// never an error card over valid history.
  Future<void> _load() async {
    if (_refreshing) return; // single-flight: open/radio/resume/pull
    _refreshing = true;
    try {
      // ── 1. LOCAL READ (instant; offline-capable) ───────────────
      final cached = await _db.getCachedMezmurDays();
      final offline = !ConnectivityService().hasLink;
      if (!mounted) return;
      if (cached.isNotEmpty) {
        // Cached days render immediately — the network never gates
        // the first paint when history exists.
        setState(() {
          _days = cached.take(5).toList();
          _loading = false;
          _fresh = _freshest(cached);
        });
      } else if (offline) {
        // Empty cache + offline: no network attempt, honest state.
        setState(() {
          _loading = false;
          _error = 'You are offline and no attendance days are cached yet.';
        });
        return;
      }
      if (offline) {
        // Cached rows shown; the radio is down so no refresh is
        // attempted — say so honestly instead of silently skipping.
        setState(() => _error = 'You appear to be offline.');
        return; // radio-return refreshes
      }
      // ── 2. SERVER REFRESH (page 1; server-authoritative window) ─
      final res = await _api.getMezmurDays(page: 1);
      if (!mounted) return;
      if (res.success) {
        final raw = (res.data ?? {})['items'] ?? [];
        final items = raw is List
            ? raw
                .whereType<Map>()
                .map((e) => Map<String, dynamic>.from(e))
                .toList()
            : <Map<String, dynamic>>[];
        // Merge-upsert by server id — never a destructive replace.
        if (items.isNotEmpty) {
          await _db.cacheMezmurDays(items);
        }
        // Re-render from the local store so UI == SQLite.
        final localNow = await _db.getCachedMezmurDays();
        if (!mounted) return;
        setState(() {
          _loading = false;
          _error = null;
          _days = localNow.take(5).toList();
          _fresh = localNow.isNotEmpty ? _freshest(localNow) : null;
        });
      } else {
        // Failure: keep whatever is on screen. Only a genuinely
        // empty history may fall back to the error card.
        setState(() {
          _loading = false;
          _error = res.isNetworkError
              ? 'You appear to be offline.'
              : (res.message ?? 'Unable to load attendance days.');
        });
      }
    } finally {
      _refreshing = false; // always resets — success, failure, exception
      if (mounted) setState(() {});
    }
  }

  /// P1-C: newest cache-write stamp among the rendered rows, as
  /// 'HH:MM' (same day) or 'YYYY-MM-DD'.
  String? _freshest(List<dynamic> rows) {
    String? maxIso;
    for (final r in rows) {
      if (r is Map) {
        final iso = r['local_fetched_at'];
        if (iso is String && (maxIso == null || iso.compareTo(maxIso) > 0)) {
          maxIso = iso;
        }
      }
    }
    if (maxIso == null) return null;
    final dt = DateTime.tryParse(maxIso);
    if (dt == null) return null;
    final local = dt.toLocal();
    final now = DateTime.now();
    String p2(int v) => v.toString().padLeft(2, '0');
    final sameDay = local.year == now.year &&
        local.month == now.month &&
        local.day == now.day;
    return sameDay
        ? '${p2(local.hour)}:${p2(local.minute)}'
        : '${local.year}-${p2(local.month)}-${p2(local.day)}';
  }

  /// P1-C: honest stale state — a failed or skipped refresh keeps
  /// the cached rows and says so (same visual language as the
  /// notification center: #92400E on #FEF3C7 = 6.37:1).
  Widget _staleBanner(String message) {
    final suffix = _fresh == null ? '' : ' · updated $_fresh';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF3C7),
        borderRadius: BorderRadius.circular(11),
      ),
      child: Row(
        children: [
          const Icon(Icons.wifi_off_rounded,
              size: 15, color: Color(0xFF92400E)),
          const SizedBox(width: 7),
          Expanded(
            child: Text('$message — showing recent days$suffix.',
                style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF92400E))),
          ),
        ],
      ),
    );
  }

  void _openAttendance([String? date]) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => MezmurAttendanceScreen(initialDate: date),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mezmur · መዝሙር ክፍል'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [const NotificationBellButton(color: Colors.white)],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _openAttendance(),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.fact_check_outlined),
        label: const Text('Take Attendance'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
          children: [
            Text(getEthiopianGreeting(),
                style:
                    const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            const SizedBox(height: 2),
            Text('Today: ${getTodayEthiopian()}',
                style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
            const SizedBox(height: 16),

            // Feature tiles
            GridView.count(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisCount: 2,
              mainAxisSpacing: 10,
              crossAxisSpacing: 10,
              childAspectRatio: 2.1,
              children: [
                FeatureTile(
                  label: 'Attendance',
                  icon: Icons.fact_check_rounded,
                  color: AppTheme.primary,
                  onTap: () => _openAttendance(),
                ),
                FeatureTile(
                  label: 'Hymn Library',
                  icon: Icons.music_note,
                  color: AppTheme.warning,
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => const MezmurHymnsScreen())),
                ),
                FeatureTile(
                  label: 'Downloads',
                  icon: Icons.download_for_offline_outlined,
                  color: AppTheme.success,
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => const MezmurDownloadsScreen())),
                ),
                FeatureTile(
                  label: 'Analytics',
                  icon: Icons.insights,
                  color: AppTheme.info,
                  enabled: _isStaff,
                  onTap: _isStaff
                      ? () => Navigator.of(context).push(MaterialPageRoute(
                          builder: (_) => const MezmurAnalyticsScreen()))
                      : null,
                ),
                FeatureTile(
                  label: 'Reviews',
                  icon: Icons.inbox_rounded,
                  color: AppTheme.success,
                  enabled: _isStaff,
                  onTap: _isStaff
                      ? () => Navigator.of(context).push(MaterialPageRoute(
                          builder: (_) =>
                              const ReviewInboxScreen(dept: 'mezmur')))
                      : null,
                ),
              ],
            ),
            const SizedBox(height: 18),

            // Recent days
            const Text('Recent attendance days',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
            const SizedBox(height: 8),
            if (_loading)
              const StudentListSkeleton()
            else if (_error != null && _days.isEmpty)
              AppErrorCard(
                  error: AppError.fromMessage(_error), onRetry: _load)
            else if (_days.isEmpty)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    'No attendance days yet.\nPress “Take Attendance” to record the first day.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        fontSize: 12, color: AppTheme.textSecondary),
                  ),
                ),
              )
            else ...[
              // P1-C: a failed/skipped refresh keeps the cached rows
              // and says so; an in-flight refresh shows a slim line.
              if (_error != null) _staleBanner(_error!),
              if (_refreshing)
                const Padding(
                  padding: EdgeInsets.only(bottom: 8),
                  child: LinearProgressIndicator(
                      minHeight: 3, color: AppTheme.primary),
                ),
              for (final d in _days) _dayRow(d),
            ],
          ],
        ),
      ),
    );
  }

  Widget _dayRow(dynamic d) {
    final date = '${d['attendance_date'] ?? ''}';
    final marked = (d['marked'] ?? 0) is int
        ? d['marked'] as int
        : int.tryParse('${d['marked']}') ?? 0;
    final attended = (d['attended'] ?? 0) is int
        ? d['attended'] as int
        : int.tryParse('${d['attended']}') ?? 0;
    final rate =
        marked > 0 ? ((attended * 1000 / marked).round() / 10) : null;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        onTap: () => _openAttendance(date),
        leading: CircleAvatar(
          backgroundColor: AppTheme.primary.withOpacity(0.1),
          child: const Icon(Icons.calendar_month,
              size: 17, color: AppTheme.primary),
        ),
        title: Text(formatGregorianAsEthiopian(date),
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
        subtitle: Text(
          '$attended/$marked attended'
          '${rate != null ? ' · $rate%' : ''}',
          style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
        ),
        trailing: const Icon(Icons.chevron_right, size: 18),
      ),
    );
  }
}
