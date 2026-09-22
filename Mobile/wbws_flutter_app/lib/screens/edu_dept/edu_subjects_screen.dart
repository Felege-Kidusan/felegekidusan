import 'dart:async';

import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/use_website_note.dart';

/// Subject catalog (read-only — same data as the website).
/// P1-F local-first: SQLite renders first — the catalog comes from
/// the dedicated education read model (cached_edu_subjects), and the
/// network only refreshes it in the background when the radio is up.
class EduSubjectsScreen extends StatefulWidget {
  const EduSubjectsScreen({super.key});
  @override
  State<EduSubjectsScreen> createState() => _EduSubjectsScreenState();
}

class _EduSubjectsScreenState extends State<EduSubjectsScreen>
    with WidgetsBindingObserver {
  final _api = ApiService();
  final _db = LocalDb();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _subjects = [];
  String? _fresh;

  // P1-F local-first state: single-flight refresh guard so
  // open/radio/resume/pull can never stack refreshes, plus cache
  // freshness for the '· updated HH:MM' banner.
  bool _refreshing = false;
  StreamSubscription<bool>? _radioSub;

  @override
  void initState() {
    super.initState();
    // P1-F: radio-return + app-resume refresh (the established
    // P1-A..P1-E screen-local pattern).
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

  /// P1-F: local-first — SQLite renders first (offline-capable); the
  /// server refresh runs only when the radio is up, REPLACES the
  /// complete snapshot on success (the endpoint returns the complete
  /// active subject set, so renames, deactivations and eligible hard
  /// deletes propagate), and the list re-renders from the store. A
  /// failed or skipped refresh keeps the cached rows under an honest
  /// stale banner.
  Future<void> _load() async {
    if (_refreshing) return; // single-flight: open/radio/resume/pull
    _refreshing = true;
    try {
      // ── 1. LOCAL READ (instant; offline-capable) ───────────────
      final cached = await _db.getCachedEduSubjects();
      final offline = !ConnectivityService().hasLink;
      if (!mounted) return;
      if (cached.isNotEmpty) {
        // Cached subjects render immediately — the network never
        // gates the first paint when the catalog has data.
        setState(() {
          _subjects = cached;
          _loading = false;
          _fresh = _freshest(cached);
        });
      } else if (offline) {
        // Empty cache + offline: no network attempt, honest state.
        setState(() {
          _loading = false;
          _error = 'You are offline and no subjects are cached yet.';
        });
        return;
      }
      if (offline) {
        // Cached rows shown; the radio is down so no refresh is
        // attempted — say so honestly instead of silently skipping.
        setState(() => _error = 'You appear to be offline.');
        return;
      }
      // ── 2. SERVER REFRESH (complete snapshot; authoritative) ───
      final res = await _api.getSubjects();
      if (!mounted) return;
      if (res.success && res.data != null && res.data!['subjects'] is List) {
        final rows = (res.data!['subjects'] as List)
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
        // Replace-on-success: the endpoint is the complete active
        // catalog — deletion-aware by design. A failed or malformed
        // response never reaches this write.
        await _db.replaceCachedEduSubjects(rows);
        // ── 3. REREAD THE STORE so UI == SQLite ─────────────────
        final localNow = await _db.getCachedEduSubjects();
        if (!mounted) return;
        setState(() {
          _loading = false;
          _error = null;
          _subjects = localNow;
          _fresh = localNow.isEmpty ? null : _freshest(localNow);
        });
      } else {
        // Failure: keep whatever is on screen. Only a genuinely
        // empty cache may fall back to the error view.
        setState(() {
          _loading = false;
          _error = res.isNetworkError
              ? 'You appear to be offline.'
              : (res.message ?? 'Could not load subjects');
        });
      }
    } finally {
      _refreshing = false; // always resets — success, failure, exception
      if (mounted) setState(() {});
    }
  }

  /// P1-F: newest cache-write stamp among the rendered rows, as
  /// 'HH:MM' (same day) or 'YYYY-MM-DD'.
  String? _freshest(List<Map<String, dynamic>> rows) {
    String? maxIso;
    for (final r in rows) {
      final iso = r['local_fetched_at'];
      if (iso is String && (maxIso == null || iso.compareTo(maxIso) > 0)) {
        maxIso = iso;
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

  /// P1-F: honest stale state — a failed or skipped refresh keeps
  /// the cached subjects and says so (the established notification-
  /// center visual language: #92400E on #FEF3C7 = 6.37:1).
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
            child: Text('$message — showing cached subjects$suffix.',
                style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF92400E))),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Subjects')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : (_error != null && _subjects.isEmpty)
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(_error!,
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: AppTheme.danger)),
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(16),
                    children: [
                      // P1-F: a failed/skipped refresh keeps the
                      // cached rows and says so (banner); an
                      // in-flight background refresh shows a slim
                      // line above them.
                      if (_error != null && _subjects.isNotEmpty)
                        _staleBanner(_error!),
                      if (_refreshing && _subjects.isNotEmpty)
                        const Padding(
                          padding: EdgeInsets.only(bottom: 8),
                          child: LinearProgressIndicator(
                              minHeight: 3, color: AppTheme.primary),
                        ),
                      const UseWebsiteNote(
                        title: 'Add a subject',
                        body:
                            'Create and edit subjects on the website Education screen.',
                      ),
                      const SizedBox(height: 12),
                      if (_subjects.isEmpty)
                        const EmptyState(
                          icon: Icons.book_rounded,
                          title: 'No subjects yet',
                          subtitle: 'Add them on the website.',
                        ),
                      ..._subjects.asMap().entries.map((e) => FastListRow(
                            index: e.key,
                            child: ListTile(
                              contentPadding: EdgeInsets.zero,
                              title: Text(e.value['subject_name'] ?? '',
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w700)),
                              subtitle: Text(
                                  '${e.value['subject_name_en'] ?? e.value['subject_code'] ?? ''} · ${e.value['class_count'] ?? 0} classes'),
                            ),
                          )),
                    ],
                  ),
                ),
    );
  }
}
