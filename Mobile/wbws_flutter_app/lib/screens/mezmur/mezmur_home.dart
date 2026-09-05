import 'package:flutter/material.dart';
import '../../services/api_service.dart';
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

/// Mezmur Department hub (mobile) — Ethiopian greeting, feature
/// tiles and recent attendance days. Attendance itself lives in
/// MezmurAttendanceScreen (teachers-grade UX, section-based).
class MezmurHomeScreen extends StatefulWidget {
  const MezmurHomeScreen({super.key});
  @override
  State<MezmurHomeScreen> createState() => MezmurHomeScreenState();
}

class MezmurHomeScreenState extends State<MezmurHomeScreen> {
  final _api = ApiService();
  bool _loading = true;
  String? _error;
  List<dynamic> _days = [];

  bool get _isStaff {
    final role = _api.userRole;
    return role == UserRoles.mezmurDept ||
        role == UserRoles.schoolAdmin ||
        role == UserRoles.superAdmin;
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  void refresh() => _load();

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final res = await _api.getMezmurDays(page: 1);
    if (!mounted) return;
    if (!res.success) {
      setState(() {
        _loading = false;
        _error = res.message ?? 'Unable to load attendance days.';
      });
      return;
    }
    setState(() {
      _loading = false;
      _days = ((res.data ?? {})['items'] ?? []).take(5).toList();
    });
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
            else if (_error != null)
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
            else
              for (final d in _days) _dayRow(d),
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
