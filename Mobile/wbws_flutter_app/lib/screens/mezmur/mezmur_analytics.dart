import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/stat_card.dart';

/// Mezmur analytics (staff) — decision view: every member with
/// number and percentage; section rollups. Server enforces the
/// same role gate; this tab is also hidden for takers.
class MezmurAnalyticsScreen extends StatefulWidget {
  const MezmurAnalyticsScreen({super.key});
  @override
  State<MezmurAnalyticsScreen> createState() => MezmurAnalyticsScreenState();
}

class MezmurAnalyticsScreenState extends State<MezmurAnalyticsScreen> {
  final _api = ApiService();

  bool _running = false;
  bool _hasRun = false;
  String? _error;
  String _from = '';
  String _to = '';
  int _held = 0;
  List<dynamic> _members = [];
  List<dynamic> _sections = [];

  void refresh() {
    if (_hasRun) _run();
  }

  String _rateTone(double? rate) {
    if (rate == null) return '—';
    if (rate >= 90) return 'ok';
    if (rate >= 70) return 'warn';
    return 'bad';
  }

  Color _rateColor(double? rate) {
    switch (_rateTone(rate)) {
      case 'ok':
        return AppTheme.success;
      case 'warn':
        return AppTheme.warning;
      case 'bad':
        return AppTheme.danger;
      default:
        return AppTheme.textSecondary;
    }
  }

  Future<void> _pickRange(bool fromField) async {
    final now = DateTime.now();
    final picked = await showEthiopianDatePicker(
      context: context,
      initialGregorianDate:
          fromField ? (_from.isEmpty ? _iso(now.subtract(const Duration(days: 90))) : _from) : (_to.isEmpty ? _iso(now) : _to),
      firstDate: now.subtract(const Duration(days: 730)),
      lastDate: now,
    );
    if (picked == null) return;
    setState(() {
      if (fromField) {
        _from = picked;
      } else {
        _to = picked;
      }
    });
  }

  String _iso(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _run() async {
    setState(() {
      _running = true;
      _error = null;
    });
    final res = await _api.getMezmurAnalytics(params: {
      if (_from.isNotEmpty) 'from': _from,
      if (_to.isNotEmpty) 'to': _to,
      'per_page': '100',
    });
    if (!mounted) return;
    if (!res.success) {
      setState(() {
        _running = false;
        _error = res.message ?? 'Unable to analyze.';
      });
      return;
    }
    final data = res.data ?? {};
    setState(() {
      _running = false;
      _hasRun = true;
      _held = (data['sessions_held'] ?? 0) is int
          ? data['sessions_held'] as int
          : int.tryParse('${data['sessions_held']}') ?? 0;
      _members = data['items'] ?? [];
    });

    final sec = await _api.get('/mezmur/analytics/sections', params: {
      if (_from.isNotEmpty) 'from': _from,
      if (_to.isNotEmpty) 'to': _to,
    });
    if (!mounted) return;
    if (sec.success && sec.data != null) {
      setState(() => _sections = sec.data!['items'] ?? []);
    }
  }

  Widget _buildBody() {
    if (_running) return const StudentListSkeleton();
    if (_error != null) {
      return ListView(children: [
        AppErrorCard(
            error: AppError.fromMessage(_error), onRetry: _run),
      ]);
    }
    if (!_hasRun) {
      return ListView(children: [
        const SizedBox(height: 60),
        EmptyState(
          icon: Icons.insights_outlined,
          title: 'No analysis yet',
          subtitle:
              'Pick a window and press Analyze to rank every member by attendance.',
        ),
      ]);
    }
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
      cacheExtent: kListCacheExtent,
      children: [
        Row(
          children: [
            Expanded(
              child: StatCard(
                  label: 'Days held',
                  value: '$_held',
                  icon: Icons.calendar_month,
                  color: AppTheme.primary),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: StatCard(
                  label: 'Members',
                  value: '${_members.length}',
                  icon: Icons.people,
                  color: AppTheme.info),
            ),
          ],
        ),
        const SizedBox(height: 12),
        if (_sections.isNotEmpty) ...[
          const Text('By section',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          for (final s in _sections) _sectionCard(s),
          const SizedBox(height: 12),
        ],
        const Text('Member ranking',
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
        const SizedBox(height: 6),
        if (_members.isEmpty)
          const EmptyState(
              icon: Icons.filter_alt_off_outlined,
              title: 'No members in this window',
              subtitle: 'Adjust the date window and run the analysis.'),
        for (var i = 0; i < _members.length; i++) _memberCard(i),
      ],
    );
  }

  Widget _sectionCard(dynamic s) {
    final rate = (s['rate'] is num) ? (s['rate'] as num).toDouble() : null;
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text('${s['section']}',
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.w700)),
                ),
                Text(rate == null ? '—' : '$rate%',
                    style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w800,
                        color: _rateColor(rate))),
              ],
            ),
            const SizedBox(height: 6),
            LinearProgressIndicator(
              value: rate == null ? 0 : (rate / 100).clamp(0, 1),
              backgroundColor: AppTheme.borderLight,
              color: _rateColor(rate),
            ),
            const SizedBox(height: 6),
            Text(
              '${s['members']} members · ${s['attended']}/${s['sessions_held']} attended',
              style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
            ),
          ],
        ),
      ),
    );
  }

  Widget _memberCard(int i) {
    final m = _members[i];
    final rate = (m['rate'] is num) ? (m['rate'] as num).toDouble() : null;
    return RepaintBoundaryListItem(
      child: Card(
        margin: const EdgeInsets.only(bottom: 6),
        child: ListTile(
          dense: true,
          leading: SizedBox(
            width: 30,
            child: Text('${i + 1}',
                style: TextStyle(
                    fontSize: 12, color: AppTheme.textSecondary)),
          ),
          title: Text(
            '${m['student_name'] ?? ''} ${m['father_name'] ?? ''}',
            style:
                const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          subtitle: Text(
            '${m['section'] ?? ''} · ${m['attended']}/${m['sessions_held']} attended',
            style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
          ),
          trailing: Text(
            rate == null ? '—' : '$rate%',
            style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w800,
                color: _rateColor(rate)),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Analytics'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          if (_hasRun) await _run();
        },
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: InkWell(
                          onTap: () => _pickRange(true),
                          child: InputDecorator(
                            decoration: InputDecoration(
                              isDense: true,
                              labelText: 'From',
                              border: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10)),
                            ),
                            child: Text(
                              _from.isEmpty
                                  ? '—'
                                  : formatGregorianAsEthiopian(_from),
                              style: const TextStyle(fontSize: 12),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: InkWell(
                          onTap: () => _pickRange(false),
                          child: InputDecorator(
                            decoration: InputDecoration(
                              isDense: true,
                              labelText: 'To',
                              border: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(10)),
                            ),
                            child: Text(
                              _to.isEmpty
                                  ? '—'
                                  : formatGregorianAsEthiopian(_to),
                              style: const TextStyle(fontSize: 12),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton.icon(
                      onPressed: _running ? null : _run,
                      icon: _running
                          ? const SizedBox(
                              width: 14,
                              height: 14,
                              child:
                                  CircularProgressIndicator(strokeWidth: 2))
                          : const Icon(Icons.insights, size: 16),
                      label: const Text('Analyze'),
                      style: FilledButton.styleFrom(
                          backgroundColor: AppTheme.primary),
                    ),
                  ),
                ],
              ),
            ),
            Expanded(child: _buildBody()),
          ],
        ),
      ),
    );
  }
}
