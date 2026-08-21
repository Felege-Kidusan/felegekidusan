import 'package:flutter/material.dart';
import '../../utils/transitions.dart';
import '../../services/api_service.dart';
import '../../services/app_nav.dart';
import '../../services/sync_service.dart';
import '../../services/session_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/app_update_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/offline_banner.dart';
import '../auth/login_screen.dart';
import '../update/update_screen.dart';
// Role home screens
import '../teacher/teacher_home.dart';
import '../teacher/teacher_grades.dart';
import '../att_taker/att_taker_home.dart';
import '../admin/admin_home.dart';
import '../edu_dept/edu_home.dart';
import '../info_dept/info_home.dart';
import '../finance/finance_home.dart';
import '../material/material_home.dart';
// Shared screens
import '../attendance/attendance_screen.dart';
import '../members/member_list_screen.dart';
import '../profile/profile_screen.dart';

/// AppShell — Role-based bottom navigation with auto-refresh,
/// global offline banner, and auth expiry handling.
class AppShell extends StatefulWidget {
  const AppShell({super.key});
  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> with WidgetsBindingObserver {
  final _api = ApiService();
  final _connectivity = ConnectivityService();
  int _currentIndex = 0;
  late List<NavTab> _tabs;

  // Keys for refreshable screens
  final _teacherHomeKey = GlobalKey<TeacherHomeScreenState>();
  final _attTakerHomeKey = GlobalKey<AttTakerHomeScreenState>();
  final _adminHomeKey = GlobalKey<AdminHomeScreenState>();
  final _eduHomeKey = GlobalKey<EduHomeScreenState>();
  final _infoHomeKey = GlobalKey<InfoHomeScreenState>();
  final _financeHomeKey = GlobalKey<FinanceHomeScreenState>();
  final _materialHomeKey = GlobalKey<MaterialHomeScreenState>();
  final _attendanceKey = GlobalKey<AttendanceScreenState>();
  final _gradesKey = GlobalKey<TeacherGradesScreenState>();
  final Map<String, Widget> _openedTabs = {};

  @override
  void initState() {
    super.initState();
    _tabs = getTabsForRole(_api.userRole);
    WidgetsBinding.instance.addObserver(this);
    AppNav().tabStream.listen((id) {
      if (!mounted) return;
      final i = _tabs.indexWhere((t) => t.id == id);
      if (i >= 0) _onTabChanged(i);
    });

    // Handle auth expiry — redirect to login
    _api.onAuthExpired = _handleAuthExpired;
    SyncService().startAutoSync();

    // Radio came back — refresh the open tab after the link settles.
    // Do not pile cacheForOffline + ping + sync on the same 4G radio.
    _connectivity.statusStream.listen((online) {
      if (online && mounted) {
        Future.delayed(const Duration(seconds: 1), () {
          if (mounted) _refreshCurrentTab();
        });
      }
    });

    if (_tabs.isEmpty) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _forceLogout());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _api.onAuthExpired = null;
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _connectivity.startMonitoring();
      // Drain the outbox immediately — do not wait 90s or a tap.
      SyncService().startAutoSync();
      SyncService().syncAll(force: true);
      _refreshCurrentTab();
      AppUpdateService().check().then((_) {
        if (mounted) setState(() {});
      });
    } else if (state == AppLifecycleState.paused) {
      // Keep the outbox. Android freezes timers in the background anyway;
      // killing it here meant a failed Save sat until the teacher tapped Sync.
      _connectivity.stopMonitoring();
    }
  }

  void _handleAuthExpired() {
    if (!mounted) return;
    // Show a message and redirect to login
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Session expired. Please login again.'),
        backgroundColor: AppTheme.warning,
        duration: Duration(seconds: 3),
      ),
    );
    _forceLogout();
  }

  void _forceLogout() async {
    await SessionService.signOut();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
        SmoothPageRoute(page: const LoginScreen()),
        (route) => false);
  }

  void _onTabChanged(int index) {
    if (index == _currentIndex) {
      _refreshCurrentTab();
      return;
    }
    setState(() => _currentIndex = index);
    _refreshTab(index);
  }

  void _refreshCurrentTab() => _refreshTab(_currentIndex);

  void _refreshTab(int index) {
    if (index >= _tabs.length) return;
    final tabId = _tabs[index].id;
    switch (tabId) {
      case 'home':
        _refreshHome();
        break;
      case 'attendance':
        if (AppNav().shouldReload('attendance') || AppNav().attendanceClassId != null) {
          _attendanceKey.currentState?.refresh();
        }
        break;
      case 'grades':
        if (AppNav().shouldReload('grades')) {
          _gradesKey.currentState?.refresh();
        }
        break;
    }
  }

  void _refreshHome() {
    switch (_api.userRole) {
      case UserRoles.teacher:
        _teacherHomeKey.currentState?.refresh();
        break;
      case UserRoles.attendanceTaker:
        _attTakerHomeKey.currentState?.refresh();
        break;
      case UserRoles.superAdmin:
      case UserRoles.schoolAdmin:
        _adminHomeKey.currentState?.refresh();
        break;
      case UserRoles.eduDept:
        _eduHomeKey.currentState?.refresh();
        break;
      case UserRoles.infoDept:
        _infoHomeKey.currentState?.refresh();
        break;
      case UserRoles.financeDept:
        _financeHomeKey.currentState?.refresh();
        break;
      case UserRoles.materialDept:
        _materialHomeKey.currentState?.refresh();
        break;
    }
  }

  Widget _tabChild(int index) {
    final id = _tabs[index].id;
    if (_openedTabs.containsKey(id) || index == _currentIndex) {
      return _openedTabs.putIfAbsent(id, () => _buildScreen(id));
    }
    return const SizedBox.shrink();
  }

  Widget _buildScreen(String tabId) {
    switch (tabId) {
      case 'home':
        return _buildHomeForRole();
      case 'attendance':
        return AttendanceScreen(key: _attendanceKey);
      case 'grades':
        return TeacherGradesScreen(key: _gradesKey);
      case 'members':
        return const MemberListScreen();
      case 'profile':
        return const ProfileScreen();
      default:
        return _buildPlaceholder(tabId);
    }
  }

  Widget _buildHomeForRole() {
    switch (_api.userRole) {
      case UserRoles.teacher:
        return TeacherHomeScreen(key: _teacherHomeKey);
      case UserRoles.attendanceTaker:
        return AttTakerHomeScreen(key: _attTakerHomeKey);
      case UserRoles.superAdmin:
      case UserRoles.schoolAdmin:
        return AdminHomeScreen(key: _adminHomeKey);
      case UserRoles.eduDept:
        return EduHomeScreen(key: _eduHomeKey);
      case UserRoles.infoDept:
        return InfoHomeScreen(key: _infoHomeKey);
      case UserRoles.financeDept:
        return FinanceHomeScreen(key: _financeHomeKey);
      case UserRoles.materialDept:
        return MaterialHomeScreen(key: _materialHomeKey);
      default:
        return TeacherHomeScreen(key: _teacherHomeKey);
    }
  }

  Widget _serverBanner() {
    final text = AppUpdateService().config?.bannerText ?? '';
    if (text.isEmpty) return const SizedBox.shrink();
    final warn = AppUpdateService().config?.bannerKind == 'warn';
    return Material(
      color: warn ? AppTheme.warning.withOpacity(0.15) : AppTheme.info.withOpacity(0.12),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Text(text, style: TextStyle(fontSize: 12, color: warn ? AppTheme.warning : AppTheme.info)),
      ),
    );
  }

  Widget _updateBanner() {
    if (!AppUpdateService().decision.optional) return const SizedBox.shrink();
    return Material(
      color: AppTheme.primary.withOpacity(0.08),
      child: InkWell(
        onTap: () {
          Navigator.of(context).push(SmoothPageRoute(page: const UpdateScreen()));
        },
        child: const Padding(
          padding: EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: Row(children: [
            Icon(Icons.system_update_alt_rounded, size: 18, color: AppTheme.primary),
            SizedBox(width: 8),
            Expanded(child: Text('A newer FKSS app is ready — tap to update',
                style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.primary))),
            Icon(Icons.chevron_right, size: 18, color: AppTheme.primary),
          ]),
        ),
      ),
    );
  }

  Widget _buildPlaceholder(String tabId) {
    return Scaffold(
      body: Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.construction_rounded,
              size: 48, color: AppTheme.textSecondary),
          const SizedBox(height: 12),
          Text(
              '${tabId[0].toUpperCase()}${tabId.substring(1)} — Coming Soon',
              style: TextStyle(fontSize: 16, color: AppTheme.textSecondary)),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_tabs.isEmpty) {
      return const Scaffold(
          body: Center(child: CircularProgressIndicator()));
    }
    if (_currentIndex >= _tabs.length) _currentIndex = 0;

    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      body: Column(
        children: [
          // Global offline banner — appears at top when offline
          const OfflineBanner(),
          // Main content
          Expanded(
            child: IndexedStack(
              index: _currentIndex,
              children: List.generate(_tabs.length, _tabChild),
            ),
          ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Container(
          margin: const EdgeInsets.fromLTRB(20, 0, 20, 20),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(30),
            boxShadow: [
              BoxShadow(
                color: AppTheme.primaryDark.withOpacity(0.08),
                blurRadius: 20,
                spreadRadius: 2,
                offset: const Offset(0, 8),
              )
            ],
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: List.generate(_tabs.length, (index) {
                final tab = _tabs[index];
                final isSelected = _currentIndex == index;
                return GestureDetector(
                  onTap: () => _onTabChanged(index),
                  behavior: HitTestBehavior.opaque,
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 250),
                    curve: Curves.easeOutCubic,
                    padding: EdgeInsets.symmetric(
                        horizontal: isSelected ? 16 : 10, vertical: 10),
                    decoration: BoxDecoration(
                      color: isSelected ? AppTheme.primary.withOpacity(0.12) : Colors.transparent,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          isSelected ? tab.activeIcon : tab.icon,
                          color: isSelected ? AppTheme.primary : AppTheme.textSecondary.withOpacity(0.6),
                          size: 24,
                        ),
                        if (isSelected) ...[
                          const SizedBox(width: 6),
                          Text(
                            tab.label,
                            style: const TextStyle(
                                color: AppTheme.primary,
                                fontWeight: FontWeight.w700,
                                fontSize: 13),
                          ),
                        ]
                      ],
                    ),
                  ),
                );
              }),
            ),
          ),
        ),
      ),
    );
  }
}

