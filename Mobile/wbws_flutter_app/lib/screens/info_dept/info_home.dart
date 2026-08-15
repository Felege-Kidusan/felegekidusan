import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/local_db.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/stat_card.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/feature_tile.dart';

class InfoHomeScreen extends StatefulWidget {
  const InfoHomeScreen({super.key});
  @override
  State<InfoHomeScreen> createState() => InfoHomeScreenState();
}

class InfoHomeScreenState extends State<InfoHomeScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  bool _loading = true;
  bool _isOffline = false;
  String? _error;
  Map<String, dynamic> _stats = {};

  @override
  void initState() { super.initState(); _load(); }
  void refresh() => _load();

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; _isOffline = false; });
    final res = await _api.getDashboardStats();
    if (!mounted) return;
    if (res.success && res.data != null) {
      setState(() { _stats = res.data['stats'] ?? {}; _loading = false; });
      _db.cacheDashboardStats(res.data, _api.userRole);
    } else {
      final cached = await _db.getCachedDashboardStats();
      if (cached != null) {
        setState(() { _stats = cached['stats'] ?? {}; _loading = false; _isOffline = true; });
      } else {
        setState(() { _error = res.message; _loading = false; _isOffline = res.isNetworkError; });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        child: CustomScrollView(
          slivers: [
            SliverAppBar(floating: true, automaticallyImplyLeading: false,
              title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Text('FKSS', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                Text('የመረጃ ክፍል • Info Department', style: TextStyle(fontSize: 11, color: Colors.white70)),
              ]),
              actions: [IconButton(icon: const Icon(Icons.refresh_rounded, size: 22), onPressed: _load)],
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              sliver: SliverList(delegate: SliverChildListDelegate(
                _loading ? [const DashboardSkeleton()]
                : _error != null ? [if (_isOffline) _offlineBanner(), AppErrorCard(error: AppError.fromMessage(_error, isNetwork: _isOffline), onRetry: _load, autoRetry: true)]
                : [if (_isOffline) _offlineBanner(), ..._buildContent()],
              )),
            ),
          ],
        ),
      ),
    );
  }

  Widget _offlineBanner() {
    return Container(
      margin: const EdgeInsets.only(bottom: 12), padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(color: AppTheme.warning.withOpacity(0.12), borderRadius: BorderRadius.circular(8)),
      child: Row(children: [
        Icon(Icons.cloud_off, size: 16, color: AppTheme.warning), const SizedBox(width: 8),
        Expanded(child: Text('Offline — showing cached data. Pull to refresh.',
            style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500))),
      ]),
    );
  }

  List<Widget> _buildContent() {
    final members = _stats['members'] ?? {};
    return [
      Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [AppTheme.primaryDark, AppTheme.primary, AppTheme.primaryLight], begin: Alignment.topLeft, end: Alignment.bottomRight),
          borderRadius: BorderRadius.circular(16)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('የመረጃ ክፍል', style: TextStyle(fontSize: 13, color: Colors.grey.shade300, fontFamily: 'NotoSansEthiopic')),
          const SizedBox(height: 2),
          Text('Information Department', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: Colors.white)),
          const SizedBox(height: 4),
          Text('Member registration, ID cards & records', style: TextStyle(fontSize: 12, color: Colors.grey.shade300)),
        ]),
      ),
      const SizedBox(height: 16),
      Row(children: [
        Expanded(child: StatCard(label: 'Total', value: '${members['total'] ?? 0}', icon: Icons.people_rounded, color: AppTheme.primary)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'Active', value: '${members['active'] ?? 0}', icon: Icons.check_circle, color: AppTheme.success)),
        const SizedBox(width: 10),
        Expanded(child: StatCard(label: 'New (7d)', value: '${_stats['recent_registrations'] ?? 0}', icon: Icons.person_add, color: AppTheme.info)),
      ]),
      const SizedBox(height: 20),
      const SectionHeader(title: 'Member Management'),
      GridView.count(
        crossAxisCount: 3, shrinkWrap: true, physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10, crossAxisSpacing: 10, childAspectRatio: 0.95,
        children: [
          FeatureTile(label: 'Register', icon: Icons.person_add_rounded, color: AppTheme.success, onTap: () {}),
          FeatureTile(label: 'Members', icon: Icons.people_rounded, color: AppTheme.primary, onTap: () {}),
          FeatureTile(label: 'ID Cards', icon: Icons.badge_rounded, color: Color(0xFF7C3AED), onTap: () {}),
          FeatureTile(label: 'Archive', icon: Icons.archive_rounded, color: Colors.grey, onTap: () {}),
          FeatureTile(label: 'Groups', icon: Icons.groups_rounded, color: AppTheme.accent, onTap: () {}),
          FeatureTile(label: 'Attendance', icon: Icons.fact_check_rounded, color: AppTheme.warning, onTap: () {}),
          FeatureTile(label: 'Reports', icon: Icons.assessment_rounded, color: AppTheme.info, onTap: () {}),
          FeatureTile(label: 'Att. Takers', icon: Icons.person_pin_rounded, color: AppTheme.danger, onTap: () {}),
          FeatureTile(label: 'Settings', icon: Icons.settings_rounded, color: Colors.grey, onTap: () {}),
        ],
      ),
    ];
  }
}




