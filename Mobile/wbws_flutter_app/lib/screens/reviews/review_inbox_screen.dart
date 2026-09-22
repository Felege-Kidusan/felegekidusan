import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/theme.dart';

/// ════════════════════════════════════════════════════════════
/// Department review inbox (Phase 9) — the mobile mirror of the
/// web consoles' Submissions workflow, shared by all three
/// departments (edu / mezmur / hr) with clone parity.
///
/// Pattern (research-backed manager-approval UX): inbox list with
/// status filters → detail with FULL context (roster marks, taker,
/// date, notes) → one-tap Approve / Return from the bottom thumb
/// zone; Return always requires a reason (server enforces too).
/// The server stays authoritative; every decision is audited there.
/// ════════════════════════════════════════════════════════════
class ReviewInboxScreen extends StatefulWidget {
  /// 'edu' | 'mezmur' | 'hr'
  final String dept;

  const ReviewInboxScreen({super.key, required this.dept});

  String get _title => dept == 'edu'
      ? 'Education Reviews'
      : dept == 'hr'
          ? 'HR Reviews'
          : 'Mezmur Reviews';

  @override
  State<ReviewInboxScreen> createState() => _ReviewInboxScreenState();
}

class _ReviewInboxScreenState extends State<ReviewInboxScreen>
    with WidgetsBindingObserver {
  final _api = ApiService();
  final _db = LocalDb();
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _items = [];
  Map<String, dynamic> _stats = {};
  String _filter = 'attention';

  // P1-D local-first state: single-flight refresh guard, cache
  // freshness ('· updated HH:MM' for the stale banner), and the set
  // of filters whose window has been fetched from the server this
  // session (a filter switch never refetches a window we already
  // have — but a never-fetched window gets one honest first load).
  bool _refreshing = false;
  String? _fresh;
  StreamSubscription<bool>? _radioSub;
  final Set<String> _fetchedFilters = {};

  static const _filters = [
    ['attention', 'Needs review'],
    ['submitted', 'Submitted'],
    ['approved', 'Approved'],
    ['revision_needed', 'Returned'],
    ['all', 'All'],
  ];

  /// The server's per-filter status windows, reproduced locally.
  /// 'attention' is department-specific by server contract:
  /// edu's SubmissionService uses IN (incomplete, submitted, draft)
  /// while the mezmur/HR services also include revision_needed.
  List<String>? _statusWindow(String filter) {
    switch (filter) {
      case 'all':
        return null;
      case 'attention':
        return widget.dept == 'edu'
            ? const ['incomplete', 'submitted', 'draft']
            : const ['incomplete', 'draft', 'submitted', 'revision_needed'];
      case 'submitted':
        return const ['submitted'];
      case 'approved':
        return const ['approved'];
      case 'revision_needed':
        return const ['revision_needed'];
    }
    return null;
  }

  @override
  void initState() {
    super.initState();
    // P1-D: radio-return + app-resume refresh (the established
    // P1-A/P1-B/P1-C screen-local pattern).
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

  /// P1-D: local-first — SQLite renders first (offline-capable); the
  /// server refresh runs only when the radio is up, persists the
  /// current filter's page 1 plus the department stats, and the list
  /// re-renders from the store. A failed or skipped refresh keeps
  /// the cached rows under an honest stale banner.
  Future<void> _load() async {
    if (_refreshing) return; // single-flight: open/radio/resume/pull
    _refreshing = true;
    try {
      final dept = widget.dept;
      // ── 1. LOCAL READ (instant; offline-capable) ───────────────
      final cached = await _db.getCachedReviewPackets(dept,
          statusIn: _statusWindow(_filter));
      final cachedStats = await _db.getCachedReviewStats(dept);
      final offline = !ConnectivityService().hasLink;
      if (!mounted) return;
      if (cached.isNotEmpty) {
        // Cached packets render immediately — the network never
        // gates the first paint when the window has data.
        setState(() {
          _items = cached;
          _loading = false;
          _fresh = _freshest(cached);
          if (cachedStats != null) _stats = cachedStats;
        });
      } else if (offline) {
        // Empty cache + offline: no network attempt, honest state.
        setState(() {
          _loading = false;
          _error = 'You are offline and no reviews are cached yet.';
        });
        return;
      }
      if (offline) {
        // Cached rows shown; the radio is down so no refresh is
        // attempted — say so honestly instead of silently skipping.
        setState(() => _error = 'You appear to be offline.');
        return;
      }
      // ── 2. SERVER REFRESH (this filter's page 1; authoritative) ─
      final res = await _api.getReviewSubmissions(dept, status: _filter);
      if (!mounted) return;
      if (res.success && res.data != null) {
        _fetchedFilters.add(_filter);
        final raw = res.data!['submissions'];
        final items = raw is Map ? raw['items'] : raw;
        final rows = items is List
            ? items
                .whereType<Map>()
                .map((e) => Map<String, dynamic>.from(e))
                .toList()
            : <Map<String, dynamic>>[];
        // Merge-upsert by (dept, id) — never a destructive replace.
        if (rows.isNotEmpty) {
          await _db.cacheReviewPackets(dept, rows);
        }
        // Stats are server aggregates — stored verbatim, never
        // recomputed from the cached rows.
        final st = res.data!['stats'];
        if (st is Map) {
          await _db.cacheReviewStats(dept, Map<String, dynamic>.from(st));
        }
        // ── 3. REREAD THE STORE so UI == SQLite ─────────────────
        final localNow = await _db.getCachedReviewPackets(dept,
            statusIn: _statusWindow(_filter));
        final statsNow = await _db.getCachedReviewStats(dept);
        if (!mounted) return;
        setState(() {
          _loading = false;
          _error = null;
          _items = localNow;
          if (localNow.isNotEmpty) _fresh = _freshest(localNow);
          if (statsNow != null) _stats = statsNow;
        });
      } else {
        // Failure: keep whatever is on screen. Only a genuinely
        // empty window may fall back to the error view.
        setState(() {
          _loading = false;
          _error = res.isNetworkError
              ? 'You appear to be offline.'
              : (res.message ?? 'Could not load the review queue.');
        });
      }
    } finally {
      _refreshing = false; // always resets — success, failure, exception
      if (mounted) setState(() {});
    }
  }

  /// P1-D: a filter switch is a LOCAL read — no network request
  /// merely because the user changed a local filter. A window that
  /// was never fetched this session gets one honest first load
  /// (online), or the honest offline-empty state.
  Future<void> _applyFilter() async {
    final cached = await _db.getCachedReviewPackets(widget.dept,
        statusIn: _statusWindow(_filter));
    if (!mounted) return;
    if (cached.isNotEmpty || _fetchedFilters.contains(_filter)) {
      // Cached window (or a genuinely empty, already-fetched one):
      // serve locally; the previous banner state stays as it was.
      setState(() {
        _items = cached;
        _loading = false;
        if (cached.isNotEmpty) _fresh = _freshest(cached);
      });
      return;
    }
    if (!ConnectivityService().hasLink) {
      setState(() {
        _items = [];
        _loading = false;
        _error = 'You are offline and no reviews are cached yet.';
      });
      return;
    }
    setState(() => _loading = true);
    await _load();
  }

  /// P1-D: newest cache-write stamp among the rendered rows, as
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

  /// P1-D: honest stale state — a failed or skipped refresh keeps
  /// the cached rows and says so (the established notification-
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
            child: Text('$message — showing recent reviews$suffix.',
                style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF92400E))),
          ),
        ],
      ),
    );
  }

  String _titleOf(Map<String, dynamic> m) {
    final s = '${m['section'] ?? ''}';
    if (s.isNotEmpty) return s;
    final c = '${m['class_name'] ?? ''}';
    final t = '${m['submission_type'] ?? ''}';
    return c.isEmpty ? 'Submission' : (t.isEmpty ? c : '$c · $t');
  }

  Widget _statusChip(String status) {
    Color color;
    String label;
    switch (status) {
      case 'approved':
        color = AppTheme.success;
        label = 'Approved';
        break;
      case 'rejected':
        color = AppTheme.danger;
        label = 'Rejected';
        break;
      case 'revision_needed':
        color = AppTheme.warning;
        label = 'Returned';
        break;
      case 'submitted':
        color = AppTheme.info;
        label = 'Submitted';
        break;
      default:
        color = AppTheme.textSecondary;
        label = status.isEmpty ? 'Draft' : status;
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(0.12),
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: color.withOpacity(0.35)),
      ),
      child: Text(label,
          style: TextStyle(
              fontSize: 10, fontWeight: FontWeight.w700, color: color)),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget._title),
        automaticallyImplyLeading: Navigator.canPop(context),
      ),
      body: Column(
        children: [
          // status filter chips (thumb-sized, scrollable)
          SizedBox(
            height: 46,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              children: [
                for (final f in _filters)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: FilterChip(
                      label: Text(f[1]),
                      selected: _filter == f[0],
                      onSelected: (v) {
                        if (!v) return;
                        setState(() => _filter = f[0]);
                        // P1-D: filter switches read SQLite, not the
                        // network.
                        _applyFilter();
                      },
                      labelStyle: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: _filter == f[0]
                              ? Colors.white
                              : AppTheme.textPrimary),
                      selectedColor: AppTheme.primary,
                      checkmarkColor: Colors.white,
                    ),
                  ),
              ],
            ),
          ),
          if (_stats.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 6),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  '${_stats['pending'] ?? _stats['open'] ?? ''} waiting · ${_stats['approved'] ?? ''} approved'
                      .trim(),
                  style: TextStyle(
                      fontSize: 11, color: AppTheme.textSecondary),
                ),
              ),
            ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : (_error != null && _items.isEmpty)
                    ? ListView(
                        children: [
                          Padding(
                            padding: const EdgeInsets.all(24),
                            child: Text(_error!,
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                    color: AppTheme.danger, fontSize: 13)),
                          ),
                          Center(
                            child: TextButton(
                                onPressed: _load,
                                child: const Text('Retry')),
                          ),
                        ],
                      )
                    : _items.isEmpty
                        ? const Center(
                            child: Text('Nothing here for this filter.',
                                style: TextStyle(fontSize: 13)))
                        : RefreshIndicator(
                            onRefresh: _load,
                            child: ListView.separated(
                              padding: const EdgeInsets.fromLTRB(12, 4, 12, 24),
                              // P1-D: a failed/skipped refresh keeps the
                              // cached rows and says so (banner); an
                              // in-flight background refresh shows a
                              // slim line above them.
                              itemCount: _items.length +
                                  ((_error != null || _refreshing) ? 1 : 0),
                              separatorBuilder: (_, __) =>
                                  const SizedBox(height: 8),
                              itemBuilder: (_, i) {
                                if ((_error != null || _refreshing) &&
                                    i == 0) {
                                  if (_error != null) {
                                    return _staleBanner(_error!);
                                  }
                                  return const Padding(
                                    padding: EdgeInsets.only(bottom: 2),
                                    child: LinearProgressIndicator(
                                        minHeight: 3,
                                        color: AppTheme.primary),
                                  );
                                }
                                final m = _items[
                                    i - ((_error != null || _refreshing) ? 1 : 0)];
                                final status = '${m['status'] ?? ''}';
                                return Material(
                                  color: AppTheme.cardLight,
                                  borderRadius: BorderRadius.circular(12),
                                  child: InkWell(
                                    borderRadius: BorderRadius.circular(12),
                                    onTap: () async {
                                      final changed =
                                          await Navigator.of(context).push(
                                        MaterialPageRoute(
                                          builder: (_) => ReviewDetailScreen(
                                            dept: widget.dept,
                                            id: (m['id'] is int)
                                                ? m['id'] as int
                                                : int.tryParse('${m['id']}') ??
                                                    0,
                                            title: _titleOf(m),
                                          ),
                                        ),
                                      );
                                      if (changed == true) _load();
                                    },
                                    child: Padding(
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 12, vertical: 10),
                                      child: Row(
                                        children: [
                                          Expanded(
                                            child: Column(
                                              crossAxisAlignment:
                                                  CrossAxisAlignment.start,
                                              children: [
                                                Text(_titleOf(m),
                                                    style: const TextStyle(
                                                        fontSize: 14,
                                                        fontWeight:
                                                            FontWeight.w700),
                                                    maxLines: 1,
                                                    overflow: TextOverflow
                                                        .ellipsis),
                                                const SizedBox(height: 3),
                                                Text(
                                                  '${formatGregorianAsEthiopian('${m['attendance_date'] ?? ''}')} · ${m['taker_name'] ?? m['teacher_name'] ?? ''}',
                                                  style: TextStyle(
                                                      fontSize: 11,
                                                      color: AppTheme
                                                          .textSecondary),
                                                  maxLines: 1,
                                                  overflow: TextOverflow
                                                      .ellipsis,
                                                ),
                                              ],
                                            ),
                                          ),
                                          const SizedBox(width: 8),
                                          _statusChip(status),
                                        ],
                                      ),
                                    ),
                                  ),
                                );
                              },
                            ),
                          ),
          ),
        ],
      ),
    );
  }
}

/// Full-context detail + big thumb-zone decision buttons.
class ReviewDetailScreen extends StatefulWidget {
  final String dept;
  final int id;
  final String title;

  const ReviewDetailScreen(
      {super.key, required this.dept, required this.id, required this.title});

  @override
  State<ReviewDetailScreen> createState() => _ReviewDetailScreenState();
}

class _ReviewDetailScreenState extends State<ReviewDetailScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  bool _loading = true;
  String? _error;
  Map<String, dynamic> _sub = {};
  bool _busy = false;

  // P1-D local-first state: cache freshness for the stale banner.
  String? _fresh;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// P1-D: local-first — the cached detail renders immediately; the
  /// server refresh runs only when the radio is up, persists the
  /// payload, and the detail re-renders from the store. Offline with
  /// no cached detail is an honest unavailable state — never
  /// fabricated data.
  Future<void> _load() async {
    // ── 1. LOCAL READ (instant; offline-capable) ───────────────
    final cached =
        await _db.getCachedReviewPacketDetail(widget.dept, widget.id);
    final offline = !ConnectivityService().hasLink;
    if (!mounted) return;
    if (cached != null && cached.isNotEmpty) {
      setState(() {
        _sub = cached;
        _loading = false;
        final stamp = cached['local_fetched_at'];
        _fresh = stamp is String ? _formatStamp(stamp) : null;
      });
    } else if (offline) {
      // No cached detail + offline: no network attempt, honest state.
      setState(() {
        _loading = false;
        _error = 'You are offline and this review is not cached yet.';
      });
      return;
    }
    if (offline) {
      // Cached detail shown; the radio is down so no refresh is
      // attempted — say so honestly.
      setState(() => _error = 'You appear to be offline.');
      return;
    }
    // ── 2. SERVER REFRESH (authoritative) ──────────────────────
    final res = await _api.getReviewSubmission(widget.dept, widget.id);
    if (!mounted) return;
    if (res.success && res.data != null) {
      final payload =
          Map<String, dynamic>.from(res.data!['submission'] ?? {});
      if (payload.isNotEmpty) {
        await _db.cacheReviewPacketDetail(widget.dept, widget.id, payload);
      }
      // ── 3. REREAD THE STORE so UI == SQLite ──────────────────
      final localNow =
          await _db.getCachedReviewPacketDetail(widget.dept, widget.id);
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = null;
        if (localNow != null && localNow.isNotEmpty) {
          _sub = localNow;
          final stamp = localNow['local_fetched_at'];
          _fresh = stamp is String ? _formatStamp(stamp) : null;
        }
      });
    } else {
      // Failure: keep whatever is on screen. Only a genuinely
      // missing detail may fall back to the error view.
      setState(() {
        _loading = false;
        _error = res.isNetworkError
            ? 'You appear to be offline.'
            : (res.message ?? 'Could not open this submission.');
      });
    }
  }

  /// P1-D: cache stamp as 'HH:MM' (same day) or 'YYYY-MM-DD'.
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

  /// P1-D: honest stale state over a cached detail (the established
  /// banner language).
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
            child: Text('$message — showing the cached review$suffix.',
                style: const TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF92400E))),
          ),
        ],
      ),
    );
  }

  Map<String, int> _counts() {
    final rows = _sub['rows'];
    final c = {'present': 0, 'absent': 0, 'late': 0, 'excused': 0, 'none': 0};
    if (rows is List) {
      for (final r in rows.whereType<Map>()) {
        final s = '${r['status'] ?? ''}'.toLowerCase();
        if (c.containsKey(s)) {
          c[s] = c[s]! + 1;
        } else {
          c['none'] = c['none']! + 1;
        }
      }
    } else {
      c['present'] = (num.tryParse('${_sub['present_count'] ?? 0}') ?? 0).toInt();
      c['absent'] = (num.tryParse('${_sub['absent_count'] ?? 0}') ?? 0).toInt();
      c['late'] = (num.tryParse('${_sub['late_count'] ?? 0}') ?? 0).toInt();
      c['excused'] =
          (num.tryParse('${_sub['excused_count'] ?? 0}') ?? 0).toInt();
    }
    return c;
  }

  Future<bool> _confirm(String verb, Color color) async {
    return await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: Text('$verb this packet?'),
            content: Text(
                '${widget.title} · ${formatGregorianAsEthiopian('${_sub['attendance_date'] ?? ''}')}'),
            actions: [
              TextButton(
                  onPressed: () => Navigator.pop(ctx, false),
                  child: const Text('Cancel')),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: color),
                onPressed: () => Navigator.pop(ctx, true),
                child: const Text('Confirm'),
              ),
            ],
          ),
        ) ??
        false;
  }

  Future<void> _askReasonAndDecide(String status) async {
    final ctrl = TextEditingController();
    final reason = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Return with a reason'),
        content: TextField(
          controller: ctrl,
          maxLines: 3,
          maxLength: 500,
          autofocus: true,
          decoration: const InputDecoration(
              hintText: 'Tell the taker what to fix…'),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
                backgroundColor: AppTheme.warning),
            onPressed: () => Navigator.pop(ctx, ctrl.text.trim()),
            child: const Text('Return'),
          ),
        ],
      ),
    );
    if (reason == null) return;
    if (reason.length < 3) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Write a short reason so the taker knows what to fix.')));
      return;
    }
    await _decide(status, reason);
  }

  Future<void> _decide(String status, String notes) async {
    setState(() => _busy = true);
    HapticFeedback.mediumImpact();
    final res = await _api.reviewSubmission(widget.dept, widget.id, status,
        notes: notes);
    if (!mounted) return;
    setState(() => _busy = false);
    if (res.success) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res.message ?? 'Reviewed.'),
          backgroundColor: AppTheme.success));
      Navigator.of(context).pop(true);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res.message ?? 'Review failed.'),
          backgroundColor: AppTheme.danger));
    }
  }

  @override
  Widget build(BuildContext context) {
    final status = '${_sub['status'] ?? ''}';
    final open = status == 'submitted' ||
        status == 'draft' ||
        status == 'revision_needed' ||
        status == 'incomplete' ||
        status.isEmpty;
    final counts = _counts();
    final rows = _sub['rows'] is List ? _sub['rows'] as List : <dynamic>[];

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title, style: const TextStyle(fontSize: 16)),
        automaticallyImplyLeading: true,
        actions: [
          if (open)
            PopupMenuButton<String>(
              onSelected: (v) {
                if (v == 'rejected') _askReasonAndDecide('rejected');
              },
              itemBuilder: (_) => const [
                PopupMenuItem(
                    value: 'rejected', child: Text('Reject packet')),
              ],
            ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : (_error != null && _sub.isEmpty)
              ? Center(child: Text(_error!))
              : ListView(
                  padding: const EdgeInsets.fromLTRB(12, 12, 12, 130),
                  children: [
                    // P1-D: a failed or skipped refresh keeps the
                    // cached detail visible and says so.
                    if (_error != null) _staleBanner(_error!),
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Text(
                                    formatGregorianAsEthiopian(
                                        '${_sub['attendance_date'] ?? ''}'),
                                    style: const TextStyle(
                                        fontSize: 16,
                                        fontWeight: FontWeight.w800),
                                  ),
                                ),
                                Text(status.toUpperCase(),
                                    style: TextStyle(
                                        fontSize: 11,
                                        fontWeight: FontWeight.w800,
                                        color: status == 'approved'
                                            ? AppTheme.success
                                            : (status == 'rejected'
                                                ? AppTheme.danger
                                                : AppTheme.warning))),
                              ],
                            ),
                            const SizedBox(height: 6),
                            Text(
                              'Taker: ${_sub['taker_name'] ?? _sub['teacher_name'] ?? '—'}',
                              style: TextStyle(
                                  fontSize: 12,
                                  color: AppTheme.textSecondary),
                            ),
                            if ('${_sub['review_notes'] ?? ''}'
                                .trim()
                                .isNotEmpty)
                              Padding(
                                padding: const EdgeInsets.only(top: 8),
                                child: Container(
                                  padding: const EdgeInsets.all(8),
                                  decoration: BoxDecoration(
                                    color: AppTheme.warning.withOpacity(0.1),
                                    borderRadius: BorderRadius.circular(8),
                                  ),
                                  child: Text(
                                      'Review note: ${_sub['review_notes']}',
                                      style: const TextStyle(fontSize: 12)),
                                ),
                              ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 8,
                      children: [
                        for (final e in [
                          ['P', counts['present']!, AppTheme.success],
                          ['A', counts['absent']!, AppTheme.danger],
                          ['L', counts['late']!, AppTheme.warning],
                          ['E', counts['excused']!, AppTheme.info],
                        ])
                          Chip(
                            label: Text('${e[0]} ${e[1]}'),
                            side: BorderSide(color: e[2] as Color),
                            labelStyle: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                                color: e[2] as Color),
                          ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    if (rows.isNotEmpty)
                      ...rows.whereType<Map>().map((r) {
                        final st = '${r['status'] ?? ''}'.toLowerCase();
                        final color = st == 'present'
                            ? AppTheme.success
                            : st == 'absent'
                                ? AppTheme.danger
                                : st == 'late'
                                    ? AppTheme.warning
                                    : st == 'excused'
                                        ? AppTheme.info
                                        : AppTheme.textSecondary;
                        return Padding(
                          padding:
                              const EdgeInsets.symmetric(vertical: 5),
                          child: Row(
                            children: [
                              Container(
                                width: 30,
                                height: 30,
                                alignment: Alignment.center,
                                decoration: BoxDecoration(
                                  color: color.withOpacity(0.12),
                                  borderRadius: BorderRadius.circular(8),
                                  border: Border.all(
                                      color: color.withOpacity(0.4)),
                                ),
                                child: Text(
                                    st.isEmpty
                                        ? '–'
                                        : st[0].toUpperCase(),
                                    style: TextStyle(
                                        fontSize: 12,
                                        fontWeight: FontWeight.w800,
                                        color: color)),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: Text(
                                  '${r['student_name'] ?? ''} ${r['father_name'] ?? ''}',
                                  style: const TextStyle(fontSize: 13),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              if ('${r['notes'] ?? ''}'.trim().isNotEmpty)
                                const Icon(Icons.note_outlined,
                                    size: 14,
                                    color: AppTheme.textSecondary),
                            ],
                          ),
                        );
                      }),
                  ],
                ),
      bottomNavigationBar: open
          ? SafeArea(
              child: Container(
                color: Theme.of(context).scaffoldBackgroundColor,
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: Row(
                  children: [
                    Expanded(
                      child: SizedBox(
                        height: 56,
                        child: ElevatedButton(
                          style: ElevatedButton.styleFrom(
                              backgroundColor: AppTheme.warning,
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(14))),
                          onPressed: _busy
                              ? null
                              : () => _askReasonAndDecide('revision_needed'),
                          child: const Text('Return',
                              style: TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.w800)),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: SizedBox(
                        height: 56,
                        child: ElevatedButton(
                          style: ElevatedButton.styleFrom(
                              backgroundColor: AppTheme.success,
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(14))),
                          onPressed: _busy
                              ? null
                              : () async {
                                  if (await _confirm(
                                      'Approve', AppTheme.success)) {
                                    await _decide('approved', '');
                                  }
                                },
                          child: const Text('Approve',
                              style: TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.w800)),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            )
          : null,
    );
  }
}

/// Admin landing for reviews — pick a department inbox.
/// Departments land directly in their own inbox (see AppShell).
class ReviewHubScreen extends StatelessWidget {
  const ReviewHubScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final depts = [
      ['edu', 'Education', Icons.school_outlined],
      ['mezmur', 'Mezmur', Icons.music_note_outlined],
      ['hr', 'HR', Icons.work_outline],
    ];
    return Scaffold(
      appBar: AppBar(title: const Text('Reviews')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          for (final d in depts)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Material(
                color: AppTheme.cardLight,
                borderRadius: BorderRadius.circular(14),
                child: InkWell(
                  borderRadius: BorderRadius.circular(14),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => ReviewInboxScreen(dept: d[0] as String))),
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Row(
                      children: [
                        Icon(d[2] as IconData, color: AppTheme.primary, size: 26),
                        const SizedBox(width: 14),
                        Text('${d[1]} reviews',
                            style: const TextStyle(
                                fontSize: 15, fontWeight: FontWeight.w800)),
                        const Spacer(),
                        const Icon(Icons.chevron_right_rounded),
                      ],
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
