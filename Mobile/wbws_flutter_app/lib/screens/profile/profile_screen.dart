import 'dart:async';
import 'package:flutter/material.dart';
import '../../utils/transitions.dart';
import '../../services/api_service.dart';
import '../../services/local_db.dart';
import '../../services/session_service.dart';
import '../../services/sync_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../auth/login_screen.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  final _sync = SyncService();
  int _pendingSync = 0;
  bool _syncing = false;
  StreamSubscription<SyncStatus>? _syncSub;

  @override
  void initState() {
    super.initState();
    _loadPendingCount();
    _syncSub = _sync.syncStream.listen((s) {
      if (!mounted) return;
      setState(() {
        _pendingSync = s.totalPending;
      });
    });
  }

  @override
  void dispose() {
    _syncSub?.cancel();
    super.dispose();
  }

  Future<void> _loadPendingCount() async {
    await _sync.emitCurrentStatus();
    final s = _sync.lastStatus;
    if (mounted) {
      setState(() {
        _pendingSync = s.totalPending;
      });
    }
  }

  Future<void> _logout() async {
    // Check for pending data
    if (_pendingSync > 0) {
      final confirm = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Unsaved Data'),
          content: Text(
              'You still have ${_sync.lastStatus.breakdown} not yet sent. '
              'If you logout, they will be lost. Sync first?'),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('Logout anyway',
                    style: TextStyle(color: AppTheme.danger))),
            ElevatedButton(
                onPressed: () async {
                  Navigator.pop(ctx);
                  final result = await _sync.syncAll(force: true);
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(result.message)),
                    );
                    _loadPendingCount();
                  }
                },
                child: const Text('Sync first')),
          ],
        ),
      );
      if (confirm != false) return; // User chose to sync or dismissed
    } else {
      final confirm = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Logout'),
          content: const Text('Are you sure you want to logout?'),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('Cancel')),
            TextButton(
                onPressed: () => Navigator.pop(ctx, true),
                child: const Text('Logout',
                    style: TextStyle(color: AppTheme.danger))),
          ],
        ),
      );
      if (confirm != true) return;
    }

    await SessionService.signOut();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      SmoothPageRoute(page: const LoginScreen()),
      (route) => false,
    );
  }

  void _showChangePassword() {
    final currentCtrl = TextEditingController();
    final newCtrl = TextEditingController();
    final confirmCtrl = TextEditingController();
    bool loading = false;
    bool obscureCurrent = true;
    bool obscureNew = true;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppTheme.cardLight,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => Padding(
          padding: EdgeInsets.only(
            left: 20,
            right: 20,
            top: 20,
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Change Password',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              const SizedBox(height: 16),

              TextField(
                controller: currentCtrl,
                obscureText: obscureCurrent,
                decoration: InputDecoration(
                  hintText: 'Current password',
                  prefixIcon: const Icon(Icons.lock_outline, size: 18),
                  suffixIcon: IconButton(
                    icon: Icon(
                        obscureCurrent
                            ? Icons.visibility_off
                            : Icons.visibility,
                        size: 18),
                    onPressed: () => setModalState(
                        () => obscureCurrent = !obscureCurrent),
                  ),
                ),
              ),
              const SizedBox(height: 12),

              TextField(
                controller: newCtrl,
                obscureText: obscureNew,
                decoration: InputDecoration(
                  hintText: 'New password',
                  prefixIcon: const Icon(Icons.lock_rounded, size: 18),
                  suffixIcon: IconButton(
                    icon: Icon(
                        obscureNew ? Icons.visibility_off : Icons.visibility,
                        size: 18),
                    onPressed: () =>
                        setModalState(() => obscureNew = !obscureNew),
                  ),
                ),
              ),
              const SizedBox(height: 12),

              TextField(
                controller: confirmCtrl,
                obscureText: true,
                decoration: const InputDecoration(
                  hintText: 'Confirm new password',
                  prefixIcon: Icon(Icons.lock_rounded, size: 18),
                ),
              ),
              const SizedBox(height: 20),

              SizedBox(
                width: double.infinity,
                child: ElevatedButton(
                  onPressed: loading
                      ? null
                      : () async {
                          final current = currentCtrl.text;
                          final newPwd = newCtrl.text;
                          final confirm = confirmCtrl.text;

                          if (current.isEmpty ||
                              newPwd.isEmpty ||
                              confirm.isEmpty) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                  content: Text('Fill in all fields'),
                                  backgroundColor: AppTheme.warning),
                            );
                            return;
                          }

                          if (newPwd != confirm) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                  content: Text('New passwords do not match'),
                                  backgroundColor: AppTheme.danger),
                            );
                            return;
                          }

                          setModalState(() => loading = true);

                          final res = await _api.post('/users/change-password',
                              body: {
                                'current_password': current,
                                'new_password': newPwd,
                                'confirm_password': confirm,
                              });

                          if (!ctx.mounted) return;

                          if (res.success) {
                            Navigator.pop(ctx);
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                  content: Text('Password changed!'),
                                  backgroundColor: AppTheme.success),
                            );
                          } else {
                            setModalState(() => loading = false);
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                  content: Text(
                                      res.message ?? 'Failed to change password'),
                                  backgroundColor: AppTheme.danger),
                            );
                          }
                        },
                  child: loading
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white))
                      : const Text('Change Password'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final user = _api.userData ?? {};

    return Scaffold(
      appBar: AppBar(
        title: const Text('Profile'),
        automaticallyImplyLeading: false,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _buildProfileHeader(user),
          const SizedBox(height: 20),

          // Account info
          _buildSection('Account', [
            _infoTile(Icons.person_outline, 'Username', user['username'] ?? 'N/A'),
            _infoTile(Icons.badge_outlined, 'Full Name', user['full_name'] ?? 'N/A'),
            _infoTile(Icons.security_outlined, 'Role',
                UserRoles.displayName(_api.userRole)),
            _infoTile(Icons.translate, 'Role (Amharic)',
                UserRoles.displayNameAmharic(_api.userRole)),
          ]),
          const SizedBox(height: 12),

          // Sync status
          _buildSection('Sync Status', [
            _infoTile(
              _pendingSync > 0 ? Icons.sync_problem : Icons.cloud_done,
              'Pending Sync',
              _pendingSync > 0
                  ? '${_sync.lastStatus.breakdown} waiting'
                  : 'All synced',
            ),
            if (_pendingSync > 0)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: () async {
                      final result = await _sync.syncAll();
                      if (mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(content: Text(result.message)),
                        );
                        _loadPendingCount();
                      }
                    },
                    icon: const Icon(Icons.sync, size: 16),
                    label: const Text('Sync Now'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppTheme.primary,
                    ),
                  ),
                ),
              ),
          ]),
          const SizedBox(height: 12),

          // App info
          _buildSection('App', [
            _infoTile(Icons.info_outline, 'Version', '${AppConfig.appVersion} (${AppConfig.appBuild})'),
            _infoTile(Icons.cloud_outlined, 'Server', AppConfig.apiBaseUrl),
          ]),
          const SizedBox(height: 20),

          // Change password button
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              onPressed: _showChangePassword,
              icon: const Icon(Icons.key_rounded, size: 18),
              label: const Text('Change Password'),
              style: OutlinedButton.styleFrom(
                foregroundColor: AppTheme.primary,
                side: BorderSide(color: AppTheme.primary.withOpacity(0.4)),
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
            ),
          ),
          const SizedBox(height: 12),

          // Logout button
          SizedBox(
            width: double.infinity,
            child: OutlinedButton.icon(
              onPressed: _logout,
              icon: const Icon(Icons.logout_rounded, size: 18),
              label: const Text('Logout'),
              style: OutlinedButton.styleFrom(
                foregroundColor: AppTheme.danger,
                side: BorderSide(color: AppTheme.danger.withOpacity(0.4)),
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
            ),
          ),
          const SizedBox(height: 16),
        ],
      ),
    );
  }

  Widget _buildProfileHeader(Map<String, dynamic> user) {
    final initials = _getInitials(user['full_name'] ?? '?');
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            CircleAvatar(
              radius: 40,
              backgroundColor: AppTheme.primary.withOpacity(0.15),
              child: Text(initials,
                  style: const TextStyle(
                      color: AppTheme.primary,
                      fontSize: 24,
                      fontWeight: FontWeight.w700)),
            ),
            const SizedBox(height: 14),
            Text(user['full_name'] ?? 'User',
                style:
                    const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                textAlign: TextAlign.center),
            const SizedBox(height: 4),
            Container(
              padding:
                  const EdgeInsets.symmetric(horizontal: 14, vertical: 5),
              decoration: BoxDecoration(
                color: AppTheme.primary.withOpacity(0.12),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(UserRoles.displayName(_api.userRole),
                  style: const TextStyle(
                      color: AppTheme.primary,
                      fontSize: 12,
                      fontWeight: FontWeight.w600)),
            ),
            const SizedBox(height: 4),
            Text(UserRoles.displayNameAmharic(_api.userRole),
                style: TextStyle(
                    fontSize: 13,
                    color: AppTheme.textSecondary,
                    fontFamily: 'NotoSansEthiopic')),
          ],
        ),
      ),
    );
  }

  Widget _buildSection(String title, List<Widget> children) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title,
                style:
                    const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
            const SizedBox(height: 10),
            ...children,
          ],
        ),
      ),
    );
  }

  Widget _infoTile(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        children: [
          Icon(icon, size: 18, color: AppTheme.textSecondary),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label,
                    style:
                        TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                const SizedBox(height: 1),
                Text(value,
                    style: const TextStyle(
                        fontSize: 13, fontWeight: FontWeight.w500)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _getInitials(String name) {
    final parts = name.trim().split(' ');
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts[0][0].toUpperCase();
    return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
  }
}


