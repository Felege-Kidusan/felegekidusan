import 'dart:async';

import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../utils/theme.dart';
import '../../utils/transitions.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/paged_list_footer.dart';
import '../../widgets/loading_skeleton.dart';
import 'attendance_warning_detail_screen.dart';
import 'attendance_warning_widgets.dart';

/// ════════════════════════════════════════════════════════════
/// Open Attendance Warnings — mobile mirror of
/// /admin/attendance_warnings.php (attendance alerting Phase 2).
///
/// Roles: Education sees Education, Mezmur sees Mezmur, HR /
/// Information / admins see both — the SERVER scopes the list; the
/// app only hides filters that cannot apply. Closing is possible for
/// the owning department, HR and Information (server-enforced, the
/// app reads `can_close` from the payload). Admins are read-only.
/// All dates are Ethiopian-calendar labels from the server.
/// ════════════════════════════════════════════════════════════
class AttendanceWarningsScreen extends StatefulWidget {
  /// Preselected department filter: 'all' | 'edu' | 'mezmur'.
  final String department;

  const AttendanceWarningsScreen({super.key, this.department = 'all'});

  @override
  State<AttendanceWarningsScreen> createState() =>
      _AttendanceWarningsScreenState();
}

class _AttendanceWarningsScreenState extends State<AttendanceWarningsScreen> {
  final _api = ApiService();
  final _search = TextEditingController();
  Timer? _debounce;

  bool _loading = true;
  bool _available = true;
  String? _error;
  bool _isNetworkError = false;
  List<Map<String, dynamic>> _items = [];
  int _total = 0;
  bool _loadingMore = false; // Phase 3: Load more (was capped at 100)
  List<String> _departments = const [];
  Map<String, dynamic> _counts = {};

  late String _department = widget.department;
  String _status = 'open';
  String _kind = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  /// Phase 3: next 100 warnings after the ones on screen, appended.
  Future<void> _loadMore() async {
    if (_loadingMore || _items.length >= _total) return;
    setState(() => _loadingMore = true);
    final res = await _api.getAttendanceWarnings(
      department: _department,
      status: _status,
      kind: _kind,
      query: _search.text,
      offset: _items.length,
    );
    if (!mounted) return;
    if (!res.success || res.data is! Map) {
      setState(() => _loadingMore = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res.message ?? 'Could not load more warnings.')));
      return;
    }
    final data = res.data as Map;
    final raw = data['items'];
    final seen = _items.map((e) => '${e['id']}').toSet();
    final more = <Map<String, dynamic>>[];
    if (raw is List) {
      for (final r in raw) {
        if (r is Map && !seen.contains('${r['id']}')) {
          more.add(Map<String, dynamic>.from(r));
        }
      }
    }
    setState(() {
      _loadingMore = false;
      _items = [..._items, ...more];
      _total = warningInt(data['total']);
    });
  }

  Future<void> _load() async {
    setState(() {
      _loading = _items.isEmpty;
      _error = null;
    });
    final results = await Future.wait([
      _api.getAttendanceWarnings(
        department: _department,
        status: _status,
        kind: _kind,
        query: _search.text,
      ),
      _api.getAttendanceWarningCounts(),
    ]);
    if (!mounted) return;
    final list = results[0];
    final counts = results[1];
    if (!list.success || list.data is! Map) {
      setState(() {
        _loading = false;
        _error = list.message ?? 'Could not load attendance warnings.';
        _isNetworkError = list.isNetworkError;
      });
      return;
    }
    final data = list.data as Map;
    final raw = data['items'];
    final items = <Map<String, dynamic>>[];
    if (raw is List) {
      for (final r in raw) {
        if (r is Map) items.add(Map<String, dynamic>.from(r));
      }
    }
    final depts = data['departments'];
    setState(() {
      _loading = false;
      _available = data['available'] != false;
      _items = items;
      _total = warningInt(data['total']);
      _departments = depts is List ? depts.map((d) => '$d').toList() : const [];
      if (counts.success && counts.data is Map && (counts.data as Map)['counts'] is Map) {
        _counts = Map<String, dynamic>.from((counts.data as Map)['counts'] as Map);
      }
    });
  }

  void _onSearchChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), _load);
  }

  Future<void> _openDetail(Map<String, dynamic> w) async {
    final changed = await Navigator.of(context).push<bool>(SmoothPageRoute(
        page: AttendanceWarningDetailScreen(warningId: warningInt(w['id']))));
    if (changed == true && mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Attendance Warnings',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
          Text('የተገኝነት ማስጠንቀቂያዎች',
              style: TextStyle(
                  fontSize: 11, color: Colors.white70, fontFamily: 'NotoSansEthiopic')),
        ]),
        actions: [
          IconButton(icon: const Icon(Icons.refresh_rounded), onPressed: _load),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _loading
            ? const MemberListSkeleton()
            : _error != null
                ? ListView(padding: const EdgeInsets.all(16), children: [
                    AppErrorCard(
                        error: AppError.fromMessage(_error, isNetwork: _isNetworkError),
                        onRetry: _load,
                        autoRetry: _isNetworkError),
                  ])
                : !_available
                    ? ListView(padding: const EdgeInsets.all(16), children: const [
                        EmptyState(
                          icon: Icons.notifications_paused_outlined,
                          title: 'Attendance warnings are not switched on yet',
                          subtitle:
                              'The school has not enabled attendance alerting on the server. Nothing is wrong with this phone.',
                        ),
                      ])
                    : _body(),
      ),
    );
  }

  Widget _body() {
    return ListView(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 24),
      children: [
        if (_counts.isNotEmpty) _countCards(),
        _filters(),
        const SizedBox(height: 6),
        Text('$_total warning${_total == 1 ? '' : 's'}',
            style: TextStyle(fontSize: 11.5, color: AppTheme.textSecondary)),
        const SizedBox(height: 6),
        if (_items.isEmpty)
          const EmptyState(
            icon: Icons.verified_outlined,
            title: 'No attendance warnings match',
            subtitle: 'Change the filters or pull down to refresh.',
          )
        else ...[
          ..._items.map(_row),
          PagedListFooter(
            shown: _items.length,
            total: _total,
            noun: 'warnings',
            loading: _loadingMore,
            onLoadMore: _loadMore,
          ),
        ],
      ],
    );
  }

  Widget _countCards() {
    final cards = <Widget>[];
    for (final entry in _counts.entries) {
      final c = entry.value is Map ? entry.value as Map : const {};
      cards.add(Expanded(
        child: Container(
          margin: const EdgeInsets.only(right: 8, bottom: 10),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: AppTheme.cardLight,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AppTheme.borderLight),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${c['label'] ?? entry.key}'.toUpperCase(),
                style: TextStyle(
                    fontSize: 10, fontWeight: FontWeight.w800, letterSpacing: .6,
                    color: AppTheme.textSecondary)),
            const SizedBox(height: 4),
            Text('${warningInt(c['total'])}',
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
            Text(
                'Consecutive ${warningInt(c['consecutive'])} · Low month ${warningInt(c['low_month'])}'
                '${warningInt(c['rollout']) > 0 ? ' · ${warningInt(c['rollout'])} from rollout' : ''}',
                style: TextStyle(fontSize: 10.5, color: AppTheme.textSecondary)),
          ]),
        ),
      ));
    }
    return Row(children: cards);
  }

  Widget _filters() {
    Widget chip(String label, bool selected, VoidCallback onTap) => Padding(
          padding: const EdgeInsets.only(right: 6),
          child: ChoiceChip(
            label: Text(label, style: const TextStyle(fontSize: 11.5)),
            selected: selected,
            onSelected: (_) => onTap(),
            visualDensity: VisualDensity.compact,
          ),
        );
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      TextField(
        controller: _search,
        onChanged: _onSearchChanged,
        decoration: InputDecoration(
          isDense: true,
          hintText: 'Search name or code',
          prefixIcon: const Icon(Icons.search, size: 18),
          suffixIcon: _search.text.isEmpty
              ? null
              : IconButton(
                  icon: const Icon(Icons.close, size: 16),
                  onPressed: () {
                    _search.clear();
                    _load();
                  }),
          border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
          contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        ),
      ),
      const SizedBox(height: 8),
      SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(children: [
          chip('Open', _status == 'open', () => setState(() { _status = 'open'; _load(); })),
          chip('Closed', _status == 'closed', () => setState(() { _status = 'closed'; _load(); })),
          chip('All', _status == 'all', () => setState(() { _status = 'all'; _load(); })),
          const SizedBox(width: 8),
          chip('Any kind', _kind == '', () => setState(() { _kind = ''; _load(); })),
          chip('Consecutive', _kind == 'consecutive', () => setState(() { _kind = 'consecutive'; _load(); })),
          chip('Low month', _kind == 'low_month', () => setState(() { _kind = 'low_month'; _load(); })),
          if (_departments.length > 1) ...[
            const SizedBox(width: 8),
            chip('Both depts', _department == 'all', () => setState(() { _department = 'all'; _load(); })),
            chip('Education', _department == 'edu', () => setState(() { _department = 'edu'; _load(); })),
            chip('Mezmur', _department == 'mezmur', () => setState(() { _department = 'mezmur'; _load(); })),
          ],
        ]),
      ),
    ]);
  }

  Widget _row(Map<String, dynamic> w) {
    final isOpen = '${w['status'] ?? ''}' == 'open';
    final name = '${w['member_name'] ?? ''}'.trim();
    final code = '${w['member_code'] ?? ''}';
    final section = '${w['section'] ?? ''}';
    final rollout = '${w['source'] ?? ''}' == 'rollout';
    final stale = isOpen && w['below_threshold'] == false;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => _openDetail(w),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(
                  child: Text(name.isEmpty ? '#${w['member_id']}' : name,
                      style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis)),
              WarningPill(
                  text: warningDepartmentLabel(w),
                  color: '${w['department']}' == 'mezmur' ? const Color(0xFF7C3AED) : AppTheme.info),
              const SizedBox(width: 6),
              WarningPill(text: warningStageLabel(w), color: warningStageColor(w)),
            ]),
            if (code.isNotEmpty || section.isNotEmpty)
              Text([code, section].where((s) => s.isNotEmpty).join(' · '),
                  style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
            const SizedBox(height: 6),
            Text('${w['reason'] ?? ''}', style: const TextStyle(fontSize: 12.5)),
            if ('${w['reason_am'] ?? ''}'.isNotEmpty)
              Text('${w['reason_am']}',
                  style: TextStyle(
                      fontSize: 11.5, color: AppTheme.textSecondary, fontFamily: 'NotoSansEthiopic')),
            const SizedBox(height: 6),
            Wrap(spacing: 6, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
              WarningPill(
                  text: isOpen ? 'Open' : 'Closed',
                  color: isOpen ? AppTheme.success : AppTheme.textSecondary),
              Text('Opened ${w['opened_ec'] ?? ''}',
                  style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
              if (!isOpen && '${w['outcome_label'] ?? ''}'.isNotEmpty)
                Text('· ${w['outcome_label']}${'${w['closed_ec'] ?? ''}'.isNotEmpty ? ' · ${w['closed_ec']}' : ''}',
                    style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
              if (rollout) const WarningPill(text: 'from rollout', color: AppTheme.textSecondary),
              if (stale) const WarningPill(text: 'no longer meets threshold', color: AppTheme.warning),
            ]),
          ]),
        ),
      ),
    );
  }
}
