import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/stat_card.dart';

/// Mezmur analytics (staff) — an explicitly user-run decision view.
///
/// P1-H local-first is deliberately a bounded LAST-VIEW cache, not a new
/// analytics engine: the server returns page 1 only (at most 100 members,
/// with no total/pages contract). SQLite restores the last validated member
/// page + section-rollup pair on open without an automatic network request.
class MezmurAnalyticsScreen extends StatefulWidget {
  const MezmurAnalyticsScreen({super.key});

  @override
  State<MezmurAnalyticsScreen> createState() => MezmurAnalyticsScreenState();
}

class MezmurAnalyticsScreenState extends State<MezmurAnalyticsScreen> {
  static const int _memberLimit = 100;

  final _api = ApiService();
  final _db = LocalDb();

  bool _restoring = true;
  bool _running = false;
  bool _hasRun = false;
  String? _error;

  // Selected controls. A separately tracked result window prevents old data
  // from being silently presented as if it belonged to newly selected dates.
  String _from = '';
  String _to = '';
  String? _resultFrom;
  String? _resultTo;
  String? _fresh;

  int _held = 0;
  List<Map<String, dynamic>> _members = [];
  List<Map<String, dynamic>> _sections = [];

  bool get _selectionDiffers =>
      _hasRun && (_from != _resultFrom || _to != _resultTo);

  @override
  void initState() {
    super.initState();
    _restoreLastView(); // SQLite only — analytics remains user-triggered.
  }

  void refresh() {
    if (_hasRun) _run();
  }

  Future<void> _restoreLastView() async {
    final cached = await _db.getCachedMezmurAnalyticsLast();
    if (!mounted) return;
    if (cached == null) {
      setState(() => _restoring = false);
      return;
    }
    _installCached(cached, offline: !ConnectivityService().hasLink);
  }

  void _installCached(Map<String, dynamic> cached, {bool offline = false}) {
    final membersResponse =
        Map<String, dynamic>.from(cached['members_response'] as Map);
    final sectionsResponse =
        Map<String, dynamic>.from(cached['sections_response'] as Map);
    final from = '${cached['from_date']}';
    final to = '${cached['to_date']}';
    setState(() {
      _from = from;
      _to = to;
      _resultFrom = from;
      _resultTo = to;
      _held = (cached['sessions_held'] as num).toInt();
      _members = (membersResponse['items'] as List)
          .map((item) => Map<String, dynamic>.from(item as Map))
          .toList();
      _sections = (sectionsResponse['items'] as List)
          .map((item) => Map<String, dynamic>.from(item as Map))
          .toList();
      _fresh = cached['local_fetched_at'] as String?;
      _hasRun = true;
      _restoring = false;
      _running = false;
      _error = offline ? 'You appear to be offline.' : null;
    });
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
    if (_running) return;
    final now = DateTime.now();
    final picked = await showEthiopianDatePicker(
      context: context,
      initialGregorianDate: fromField
          ? (_from.isEmpty
              ? _iso(now.subtract(const Duration(days: 90)))
              : _from)
          : (_to.isEmpty ? _iso(now) : _to),
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
      _error = null;
    });
  }

  String _iso(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  /// Explicit Analyze/refresh only. Members and sections are one logical
  /// result: neither visible state nor SQLite changes until BOTH validated
  /// responses agree on the canonical server window and sessions held.
  Future<void> _run() async {
    if (_running) return;
    if (!ConnectivityService().hasLink) {
      setState(() {
        _error = _hasRun && _selectionDiffers
            ? 'You are offline. The selected window has not been analyzed.'
            : (_hasRun
                ? 'You appear to be offline.'
                : 'You are offline and no analysis is saved on this phone.');
      });
      return;
    }

    setState(() {
      _running = true;
      _error = null;
    });

    try {
      final memberRes = await _api.getMezmurAnalytics(params: {
        if (_from.isNotEmpty) 'from': _from,
        if (_to.isNotEmpty) 'to': _to,
        'page': '1',
        'per_page': '$_memberLimit',
      });
      if (!mounted) return;
      if (!memberRes.success || memberRes.data == null) {
        throw _AnalyticsFailure(memberRes.message ?? 'Unable to analyze.');
      }
      final members = _parsePayload(memberRes.data,
          memberPage: true, label: 'member analytics');

      // Use the server's canonical dates (defaults/clamps/swaps already
      // resolved) so the second response cannot describe another window.
      final sectionRes = await _api.get(
        '/mezmur/analytics/sections',
        params: {'from': members.from, 'to': members.to},
      );
      if (!mounted) return;
      if (!sectionRes.success || sectionRes.data == null) {
        throw _AnalyticsFailure(
            sectionRes.message ?? 'Unable to load section analytics.');
      }
      final sections = _parsePayload(sectionRes.data,
          memberPage: false, label: 'section analytics');

      if (members.from != sections.from ||
          members.to != sections.to ||
          members.sessionsHeld != sections.sessionsHeld) {
        throw const _AnalyticsFailure(
            'The analytics responses described different windows.');
      }

      // One row contains both verbatim responses. A member-only success or
      // any malformed/failed section response never reaches this write.
      await _db.cacheMezmurAnalyticsLast(
        fromDate: members.from,
        toDate: members.to,
        sessionsHeld: members.sessionsHeld,
        membersResponse: members.raw,
        sectionsResponse: sections.raw,
      );
      final saved = await _db.getCachedMezmurAnalyticsLast();
      if (saved == null) {
        throw const _AnalyticsFailure(
            'The saved analytics view could not be verified.');
      }
      if (!mounted) return;
      _installCached(saved);
    } on _AnalyticsFailure catch (e) {
      if (!mounted) return;
      setState(() => _error = e.message);
    } catch (_) {
      if (!mounted) return;
      setState(() => _error = 'Unable to analyze.');
    } finally {
      if (mounted) setState(() => _running = false);
    }
  }

  _AnalyticsPayload _parsePayload(dynamic value,
      {required bool memberPage, required String label}) {
    if (value is! Map) {
      throw _AnalyticsFailure('The $label response was malformed.');
    }
    final raw = Map<String, dynamic>.from(value);
    final items = raw['items'];
    final window = raw['window'];
    final held = _strictInt(raw['sessions_held']);
    if (items is! List ||
        items.any((item) => item is! Map) ||
        window is! Map ||
        held == null ||
        held < 0) {
      throw _AnalyticsFailure('The $label response was malformed.');
    }
    if (memberPage &&
        (_strictInt(raw['page']) != 1 || items.length > _memberLimit)) {
      throw _AnalyticsFailure('The $label response was malformed.');
    }
    final from = window['from'];
    final to = window['to'];
    if (from is! String ||
        to is! String ||
        !_validIsoDate(from) ||
        !_validIsoDate(to) ||
        from.compareTo(to) > 0) {
      throw _AnalyticsFailure('The $label window was malformed.');
    }
    return _AnalyticsPayload(
      raw: raw,
      from: from,
      to: to,
      sessionsHeld: held,
    );
  }

  bool _validIsoDate(String value) {
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(value);
    if (match == null) return false;
    final year = int.parse(match.group(1)!);
    final month = int.parse(match.group(2)!);
    final day = int.parse(match.group(3)!);
    final parsed = DateTime.tryParse(value);
    return parsed != null &&
        parsed.year == year &&
        parsed.month == month &&
        parsed.day == day;
  }

  int? _strictInt(dynamic value) {
    if (value is int) return value;
    if (value is num && value.isFinite && value == value.roundToDouble()) {
      return value.toInt();
    }
    if (value is String) return int.tryParse(value);
    return null;
  }

  String _windowLabel(String? from, String? to) {
    if (from == null || to == null) return 'Unknown window';
    return '${formatGregorianAsEthiopian(from)} – ${formatGregorianAsEthiopian(to)}';
  }

  String? _freshLabel() {
    if (_fresh == null) return null;
    final dt = DateTime.tryParse(_fresh!)?.toLocal();
    if (dt == null) return null;
    final now = DateTime.now();
    String p2(int v) => v.toString().padLeft(2, '0');
    final sameDay = dt.year == now.year &&
        dt.month == now.month &&
        dt.day == now.day;
    return sameDay
        ? '${p2(dt.hour)}:${p2(dt.minute)}'
        : '${dt.year}-${p2(dt.month)}-${p2(dt.day)}';
  }

  Widget _statusBanner(String message) {
    final fresh = _freshLabel();
    final suffix = fresh == null ? '' : ' · updated $fresh';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF3C7),
        borderRadius: BorderRadius.circular(11),
      ),
      child: Row(children: [
        const Icon(Icons.wifi_off_rounded,
            size: 15, color: Color(0xFF92400E)),
        const SizedBox(width: 7),
        Expanded(
          child: Text('$message — showing the last saved analysis$suffix.',
              style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF92400E))),
        ),
      ]),
    );
  }

  Widget _selectionBanner() => Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
        decoration: BoxDecoration(
          color: AppTheme.info.withOpacity(0.10),
          borderRadius: BorderRadius.circular(11),
        ),
        child: Row(children: [
          const Icon(Icons.date_range_rounded,
              size: 15, color: AppTheme.info),
          const SizedBox(width: 7),
          Expanded(
            child: Text(
              'The selected window has not been analyzed yet. Results below are for ${_windowLabel(_resultFrom, _resultTo)}.',
              style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: AppTheme.info),
            ),
          ),
        ]),
      );

  Widget _resultWindowCard() {
    final fresh = _freshLabel();
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppTheme.primary.withOpacity(0.07),
        borderRadius: BorderRadius.circular(11),
        border: Border.all(color: AppTheme.primary.withOpacity(0.14)),
      ),
      child: Row(children: [
        const Icon(Icons.calendar_month_rounded,
            size: 17, color: AppTheme.primary),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Result window',
                  style: TextStyle(
                      fontSize: 10.5,
                      fontWeight: FontWeight.w700,
                      color: AppTheme.textSecondary)),
              Text(_windowLabel(_resultFrom, _resultTo),
                  style: const TextStyle(
                      fontSize: 12.5, fontWeight: FontWeight.w700)),
            ],
          ),
        ),
        if (fresh != null)
          Text('Saved $fresh',
              style:
                  TextStyle(fontSize: 10.5, color: AppTheme.textSecondary)),
      ]),
    );
  }

  Widget _buildBody() {
    if (_restoring) return const StudentListSkeleton();
    if (_running && !_hasRun) return const StudentListSkeleton();
    if (_error != null && !_hasRun) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          AppErrorCard(
              error: AppError.fromMessage(_error), onRetry: _run),
        ],
      );
    }
    if (!_hasRun) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: const [
          SizedBox(height: 60),
          EmptyState(
            icon: Icons.insights_outlined,
            title: 'No analysis yet',
            subtitle:
                'Pick a window and press Analyze to rank up to 100 active members by attendance.',
          ),
        ],
      );
    }
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
      cacheExtent: kListCacheExtent,
      children: [
        if (_error != null) _statusBanner(_error!),
        if (_selectionDiffers) _selectionBanner(),
        if (_running)
          const Padding(
            padding: EdgeInsets.only(bottom: 10),
            child: LinearProgressIndicator(
                minHeight: 3, color: AppTheme.primary),
          ),
        _resultWindowCard(),
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
                  label: 'Members shown',
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
        const Text('Member ranking (up to 100)',
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
        if (_members.length == _memberLimit)
          Padding(
            padding: const EdgeInsets.only(top: 5, bottom: 7),
            child: Text(
              'Showing the first 100 rows returned by the server. Use the website for complete paged analytics.',
              style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
            ),
          )
        else
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

  Widget _sectionCard(Map<String, dynamic> s) {
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
                style:
                    TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
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
                          onTap: (_running || _restoring)
                              ? null
                              : () => _pickRange(true),
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
                          onTap: (_running || _restoring)
                              ? null
                              : () => _pickRange(false),
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
                      onPressed: (_running || _restoring) ? null : _run,
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

class _AnalyticsPayload {
  final Map<String, dynamic> raw;
  final String from;
  final String to;
  final int sessionsHeld;

  const _AnalyticsPayload({
    required this.raw,
    required this.from,
    required this.to,
    required this.sessionsHeld,
  });
}

class _AnalyticsFailure implements Exception {
  final String message;
  const _AnalyticsFailure(this.message);
}
