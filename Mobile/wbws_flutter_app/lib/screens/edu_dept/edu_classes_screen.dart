import 'dart:async';

import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/roster.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/status_banner.dart';

/// Class list + roster (name and code only). Same data as the website.
/// P1-E local-first: SQLite renders first on BOTH halves — the class
/// list and each open class's roster come from the dedicated education
/// read model (cached_edu_classes / cached_edu_class_rosters), and the
/// network only refreshes it in the background when the radio is up.
class EduClassesScreen extends StatefulWidget {
  const EduClassesScreen({super.key});
  @override
  State<EduClassesScreen> createState() => _EduClassesScreenState();
}

class _EduClassesScreenState extends State<EduClassesScreen>
    with WidgetsBindingObserver {
  final _api = ApiService();
  final _db = LocalDb();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _classes = [];
  String? _fresh;
  int? _openId;
  List<Map<String, dynamic>> _students = [];
  bool _loadingStudents = false;
  String? _studentError;
  String? _rosterNote;
  String? _rosterFresh;

  // P1-E local-first state: single-flight guards (list + roster) so
  // open/radio/resume/pull can never stack refreshes. The roster
  // guard is cross-class — switching classes while a roster refresh
  // is in flight still renders the new class's cached roster
  // instantly; only the network half is held back.
  bool _refreshing = false;
  bool _rosterRefreshing = false;
  StreamSubscription<bool>? _radioSub;

  @override
  void initState() {
    super.initState();
    // P1-E: radio-return + app-resume refresh (the established
    // P1-A/P1-B/P1-C/P1-D screen-local pattern).
    WidgetsBinding.instance.addObserver(this);
    _load();
    _radioSub = ConnectivityService().statusStream.listen((online) {
      if (online) {
        Future.delayed(const Duration(seconds: 1), () {
          if (mounted) _refreshAll();
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
    if (state == AppLifecycleState.resumed) _refreshAll();
  }

  /// P1-E: local-first class list — SQLite renders first
  /// (offline-capable); the server refresh runs only when the radio
  /// is up, REPLACES the complete snapshot on success (the endpoint
  /// returns the complete active class set for this scope, so
  /// renames, deactivations and eligible hard deletes propagate),
  /// and the list re-renders from the store. A failed or skipped
  /// refresh keeps the cached rows under an honest stale banner.
  Future<void> _load() async {
    if (_refreshing) return; // single-flight: open/radio/resume/pull
    _refreshing = true;
    try {
      // ── 1. LOCAL READ (instant; offline-capable) ───────────────
      final cached = await _db.getCachedEduClasses();
      final offline = !ConnectivityService().hasLink;
      if (!mounted) return;
      if (cached.isNotEmpty) {
        // Cached classes render immediately — the network never
        // gates the first paint when the scope has data.
        setState(() {
          _classes = cached;
          _loading = false;
          _fresh = _freshest(cached);
        });
      } else if (offline) {
        // Empty cache + offline: no network attempt, honest state.
        setState(() {
          _loading = false;
          _error = 'You are offline and no classes are cached yet.';
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
      final res = await _api.getClasses();
      if (!mounted) return;
      if (res.success && res.data != null && res.data!['classes'] is List) {
        final rows = (res.data!['classes'] as List)
            .whereType<Map>()
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
        // Replace-on-success: the endpoint is the complete active
        // class set for this scope — deletion-aware by design. A
        // failed or malformed response never reaches this write.
        await _db.replaceCachedEduClasses(rows);
        // ── 3. REREAD THE STORE so UI == SQLite ─────────────────
        final localNow = await _db.getCachedEduClasses();
        if (!mounted) return;
        setState(() {
          _loading = false;
          _error = null;
          _classes = localNow;
          _fresh = localNow.isEmpty ? null : _freshest(localNow);
        });
      } else {
        // Failure: keep whatever is on screen. Only a genuinely
        // empty cache may fall back to the error view.
        setState(() {
          _loading = false;
          _error = res.isNetworkError
              ? 'You appear to be offline.'
              : (res.message ?? 'Could not load classes');
        });
      }
    } finally {
      _refreshing = false; // always resets — success, failure, exception
      if (mounted) setState(() {});
    }
  }

  /// P1-E: full refresh — the list plus the open class's roster
  /// (each single-flight; one never blocks the other).
  Future<void> _refreshAll() async {
    await _load();
    final open = _openId;
    if (open != null) {
      await _loadRoster(open);
    }
  }

  Future<void> _openClass(dynamic c) async {
    final id = RosterParse.asInt(c['id']);
    if (id == null) return;
    // P1-E: NO destructive clear — selecting a class only selects
    // it. Every class's cached roster stays stored (each row is
    // keyed by class_id in cached_edu_class_rosters); this class's
    // own cached roster renders first, before any network attempt.
    setState(() {
      _openId = id;
      _loadingStudents = true;
      _students = [];
      _studentError = null;
      _rosterNote = null;
      _rosterFresh = null;
    });
    await _loadRoster(id);
  }

  /// P1-E: local-first roster — the selected class's cached roster
  /// renders first; the network refresh (single-flight across
  /// classes) replaces ONLY this class's cache row on success and
  /// the panel re-renders from the store. A response that arrives
  /// after the user switched away is persisted (its cache row is
  /// keyed by class_id and will be served on reopen) but is never
  /// painted under the wrong class.
  Future<void> _loadRoster(int id) async {
    // ── 1. LOCAL READ (instant; offline-capable) ───────────────
    final cached = await _db.getCachedEduClassRoster(id);
    final offline = !ConnectivityService().hasLink;
    if (mounted && _openId == id) {
      if (cached != null) {
        _applyRoster(cached);
      } else {
        // Nothing cached for this class yet — the panel falls to
        // the honest empty / error states below.
        setState(() => _loadingStudents = false);
      }
    }
    if (offline) {
      if (mounted && _openId == id) {
        // Cached roster shown (or the not-cached-yet empty state);
        // the radio is down so no refresh is attempted.
        setState(() => _studentError = _studentError ??
            (cached != null
                ? 'You appear to be offline.'
                : 'You are offline and this roster is not cached yet.'));
      }
      return;
    }
    if (_rosterRefreshing) {
      // Another roster refresh is in flight — this class's cache
      // (if any) is already on screen; the next open / resume /
      // pull refreshes it. Never stack roster requests.
      return;
    }
    _rosterRefreshing = true;
    try {
      // ── 2. SERVER REFRESH (this class only; authoritative) ────
      final res = await _api.getClassStudents(id);
      if (!mounted) return;
      if (!res.success || res.data == null) {
        if (_openId == id) {
          setState(() {
            _loadingStudents = false;
            _studentError = res.isNetworkError
                ? 'You appear to be offline.'
                : (res.message ?? 'Could not load students. Try again.');
          });
        }
        return;
      }
      final payload = Map<String, dynamic>.from(res.data!);
      // Replace-on-success for THIS class only — enrollments and
      // withdrawals propagate at refresh; stale students are never
      // merged into the new roster, and no other class's row is
      // touched. The year-resolution metadata is stored verbatim.
      await _db.cacheEduClassRoster(id, payload);
      // ── 3. REREAD THE STORE so UI == SQLite ──────────────────
      final localNow = await _db.getCachedEduClassRoster(id);
      if (!mounted || _openId != id) return;
      _applyRoster(localNow ?? payload);
    } finally {
      _rosterRefreshing = false; // always resets — success, failure, exception
      if (mounted) setState(() {});
    }
  }

  /// Paint a roster payload (cache or fresh) under its open class,
  /// including the server's year-fallback note when present.
  void _applyRoster(Map<String, dynamic> payload) {
    final parsed = RosterParse.students(payload);
    setState(() {
      _loadingStudents = false;
      _students = parsed;
      _rosterNote = _noteFor(payload);
      _rosterFresh = _stampOf(payload);
      _studentError = null;
      if (parsed.isEmpty && RosterParse.reportedCount(payload) > 0) {
        _studentError =
            'The server sent students but this phone could not read them.';
      }
    });
  }

  /// The server's roster-year note, built only from the response's
  /// own metadata (roster_fallback / roster_year_name). The
  /// current-year vs most-populated-prior-year resolution is server
  /// contract — it is never reconstructed locally.
  String? _noteFor(dynamic data) {
    if (RosterParse.fallback(data)) {
      final year = RosterParse.yearName(data);
      return year == null
          ? 'Showing students from a previous year.'
          : 'Showing the $year roster.';
    }
    return null;
  }

  /// P1-E: newest cache-write stamp among the rendered rows, as
  /// 'HH:MM' (same day) or 'YYYY-MM-DD'.
  String? _freshest(List<Map<String, dynamic>> rows) {
    String? maxIso;
    for (final r in rows) {
      final iso = r['local_fetched_at'];
      if (iso is String && (maxIso == null || iso.compareTo(maxIso) > 0)) {
        maxIso = iso;
      }
    }
    return maxIso == null ? null : _formatStamp(maxIso);
  }

  String? _stampOf(Map<String, dynamic> payload) {
    final iso = payload['local_fetched_at'];
    return iso is String ? _formatStamp(iso) : null;
  }

  String? _formatStamp(String iso) {
    final dt = DateTime.tryParse(iso);
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

  /// P1-E: honest stale state — a failed or skipped refresh keeps
  /// the cached classes and says so (the established notification-
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
            child: Text('$message — showing cached classes$suffix.',
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
      appBar: AppBar(title: const Text('Classes')),
      body: _loading
          ? const MemberListSkeleton()
          : (_error != null && _classes.isEmpty)
              ? Padding(
                  padding: const EdgeInsets.all(16),
                  child: AppErrorCard(
                    error: AppError.fromMessage(_error!),
                    onRetry: _refreshAll,
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _refreshAll,
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(16),
                    children: [
                      // P1-E: a failed/skipped refresh keeps the
                      // cached rows and says so (banner); an
                      // in-flight background refresh shows a slim
                      // line above them.
                      if (_error != null && _classes.isNotEmpty)
                        _staleBanner(_error!),
                      if (_refreshing && _classes.isNotEmpty)
                        const Padding(
                          padding: EdgeInsets.only(bottom: 8),
                          child: LinearProgressIndicator(
                              minHeight: 3, color: AppTheme.primary),
                        ),
                      if (_classes.isEmpty)
                        const EmptyState(
                          icon: Icons.class_rounded,
                          title: 'No classes yet',
                          subtitle:
                              'Create classes on the website under Education.',
                        ),
                      ..._classes.asMap().entries.map((e) {
                        final c = e.value;
                        final id = RosterParse.asInt(c['id']);
                        final open = id == _openId;
                        return Container(
                          margin: const EdgeInsets.only(bottom: 8),
                          decoration: BoxDecoration(
                            color: e.key.isEven
                                ? Colors.white
                                : const Color(0xFFF2F4F7),
                            borderRadius: BorderRadius.circular(12),
                            border: Border.all(
                                color: AppTheme.borderLight, width: 0.8),
                          ),
                          child: Column(
                            children: [
                              ListTile(
                                title: Text(c['class_name'] ?? '',
                                    style: const TextStyle(
                                        fontWeight: FontWeight.w600)),
                                subtitle:
                                    Text('${c['student_count'] ?? 0} students'),
                                trailing: Icon(open
                                    ? Icons.expand_less
                                    : Icons.expand_more),
                                onTap: () => open
                                    ? setState(() => _openId = null)
                                    : _openClass(c),
                              ),
                              if (open)
                                _loadingStudents
                                    ? const Padding(
                                        padding: EdgeInsets.all(16),
                                        child:
                                            StudentListSkeleton(count: 3),
                                      )
                                    : _students.isNotEmpty
                                        ? Column(
                                            children: [
                                              if (_rosterNote != null)
                                                StatusBanner.warning(
                                                    _rosterNote!),
                                              if (_rosterRefreshing)
                                                const Padding(
                                                  padding:
                                                      EdgeInsets.fromLTRB(
                                                          12, 0, 12, 8),
                                                  child:
                                                      LinearProgressIndicator(
                                                          minHeight: 2,
                                                          color: AppTheme
                                                              .primary),
                                                ),
                                              if (_studentError != null)
                                                StatusBanner.warning(
                                                    '$_studentError — showing the cached roster'
                                                    '${_rosterFresh == null ? '' : ' · updated $_rosterFresh'}.'),
                                              ..._students.map((s) => ListTile(
                                                    dense: true,
                                                    title: Text(
                                                        '${s['student_name'] ?? ''} ${s['father_name'] ?? ''}'),
                                                    subtitle: Text(
                                                        '${s['member_code'] ?? ''}'),
                                                  )),
                                            ],
                                          )
                                        : _studentError != null
                                            ? Padding(
                                                padding:
                                                    const EdgeInsets.fromLTRB(
                                                        12, 0, 12, 12),
                                                child: StatusBanner.error(
                                                  _studentError!,
                                                  onRetry: () =>
                                                      _openClass(c),
                                                ),
                                              )
                                            : Padding(
                                                padding:
                                                    const EdgeInsets.fromLTRB(
                                                        8, 0, 8, 12),
                                                child: EmptyState(
                                                  icon: Icons.people_outline,
                                                  title:
                                                      'No students in this class yet',
                                                  subtitle:
                                                      'If they were enrolled on the website, tap Refresh.',
                                                  action: TextButton.icon(
                                                    onPressed: () =>
                                                        _openClass(c),
                                                    icon: const Icon(
                                                        Icons.refresh,
                                                        size: 18),
                                                    label:
                                                        const Text('Refresh'),
                                                  ),
                                                ),
                                              ),
                            ],
                          ),
                        );
                      }),
                    ],
                  ),
                ),
    );
  }
}
