import 'dart:async';
import 'package:flutter/material.dart';
import '../services/connectivity_service.dart';
import '../services/local_db.dart';
import '../services/sync_service.dart';
import '../utils/theme.dart';

/// Global offline banner — sits at the top of the app when offline.
/// Shows pending sync count and manual sync button.
class OfflineBanner extends StatefulWidget {
  const OfflineBanner({super.key});

  @override
  State<OfflineBanner> createState() => _OfflineBannerState();
}

class _OfflineBannerState extends State<OfflineBanner>
    with SingleTickerProviderStateMixin {
  final _connectivity = ConnectivityService();
  final _sync = SyncService();
  final _db = LocalDb();

  late AnimationController _animCtrl;
  late Animation<double> _slideAnim;
  StreamSubscription<bool>? _connectSub;
  StreamSubscription<SyncStatus>? _syncSub;

  bool _isOffline = false;
  int _pendingCount = 0;
  bool _syncing = false;
  bool _justCameOnline = false;

  @override
  void initState() {
    super.initState();
    _isOffline = !_connectivity.isOnline;

    _animCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 300),
    );
    _slideAnim = CurvedAnimation(parent: _animCtrl, curve: Curves.easeOut);

    if (_isOffline) _animCtrl.value = 1.0;

    _connectSub = _connectivity.statusStream.listen((online) {
      if (!mounted) return;
      if (!online && !_isOffline) {
        // Went offline
        setState(() => _isOffline = true);
        _animCtrl.forward();
        _loadPendingCount();
      } else if (online && _isOffline) {
        // Came back online — show green briefly, then hide
        setState(() {
          _isOffline = false;
          _justCameOnline = true;
        });
        // Auto-sync pending data
        _sync.syncAll();
        // Hide banner after 2 seconds
        Future.delayed(const Duration(seconds: 2), () {
          if (mounted) {
            _animCtrl.reverse();
            Future.delayed(const Duration(milliseconds: 300), () {
              if (mounted) setState(() => _justCameOnline = false);
            });
          }
        });
      }
    });

    _syncSub = _sync.syncStream.listen((status) {
      if (mounted) {
        setState(() {
          _pendingCount = status.totalPending;
          _syncing = status.syncing;
        });
      }
    });

    _loadPendingCount();
  }

  Future<void> _loadPendingCount() async {
    final count = await _db.getTotalPendingCount();
    if (mounted) setState(() => _pendingCount = count);
  }

  @override
  void dispose() {
    _connectSub?.cancel();
    _syncSub?.cancel();
    _animCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!_isOffline && !_justCameOnline) return const SizedBox.shrink();

    return SizeTransition(
      sizeFactor: _slideAnim,
      axisAlignment: -1,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        decoration: BoxDecoration(
          color: _justCameOnline
              ? AppTheme.success.withOpacity(0.15)
              : AppTheme.warning.withOpacity(0.15),
          border: Border(
            bottom: BorderSide(
              color: _justCameOnline
                  ? AppTheme.success.withOpacity(0.3)
                  : AppTheme.warning.withOpacity(0.3),
              width: 1,
            ),
          ),
        ),
        child: SafeArea(
          bottom: false,
          child: Row(
            children: [
              Icon(
                _justCameOnline ? Icons.cloud_done : Icons.cloud_off,
                size: 16,
                color: _justCameOnline ? AppTheme.success : AppTheme.warning,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  _justCameOnline
                      ? 'Back online — syncing data...'
                      : _pendingCount > 0
                          ? 'Offline — $_pendingCount unsaved changes will sync when connected'
                          : 'No internet connection',
                  style: TextStyle(
                    fontSize: 11,
                    color:
                        _justCameOnline ? AppTheme.success : AppTheme.warning,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
              if (!_justCameOnline && _pendingCount > 0)
                GestureDetector(
                  onTap: _syncing
                      ? null
                      : () async {
                          final result = await _sync.syncAll();
                          _loadPendingCount();
                        },
                  child: Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: AppTheme.warning.withOpacity(0.2),
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      _syncing ? 'Syncing...' : 'Retry',
                      style: TextStyle(
                        fontSize: 10,
                        color: AppTheme.warning,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
