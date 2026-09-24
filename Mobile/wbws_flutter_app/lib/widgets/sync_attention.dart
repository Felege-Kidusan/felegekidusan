import 'dart:async';

import 'package:flutter/material.dart';

import '../services/sync_service.dart';
import '../utils/theme.dart';

Future<void> openSyncCenter(BuildContext context) async {
  await Navigator.of(context).pushNamed('/sync-center');
}

/// Backward-compatible entry used by the four packet screens. The legacy
/// rejected-only sheet now routes to the unified, exact-operation center.
Future<void> showSyncRejectedSheet(BuildContext context) =>
    openSyncCenter(context);

/// Warning strip for a source screen when one or more current-scope packets
/// need review. Recovery itself always happens in the owner-safe Sync Center.
class SyncAttentionBanner extends StatelessWidget {
  final int rejectedCount;
  const SyncAttentionBanner({super.key, required this.rejectedCount});

  @override
  Widget build(BuildContext context) {
    final n = rejectedCount;
    return Material(
      color: AppTheme.danger.withOpacity(0.12),
      child: InkWell(
        onTap: () => openSyncCenter(context),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(children: [
            const Icon(Icons.report_problem_outlined,
                size: 16, color: AppTheme.danger),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                '$n saved sheet${n == 1 ? '' : 's'} need review. '
                'Kept safely on this phone.',
                style: const TextStyle(
                    fontSize: 11,
                    color: AppTheme.danger,
                    fontWeight: FontWeight.w500),
              ),
            ),
            const SizedBox(width: 8),
            const Text('Open Sync Center',
                style: TextStyle(
                    fontSize: 11,
                    color: AppTheme.primary,
                    fontWeight: FontWeight.w700)),
          ]),
        ),
      ),
    );
  }
}

/// Global shell banner for terminal, paused, and resolved-conflict states.
/// Ordinary offline waiting remains in OfflineBanner; this banner appears only
/// when recovery or acknowledgement may need a person.
class SyncRecoveryBanner extends StatefulWidget {
  const SyncRecoveryBanner({super.key});

  @override
  State<SyncRecoveryBanner> createState() => _SyncRecoveryBannerState();
}

class _SyncRecoveryBannerState extends State<SyncRecoveryBanner> {
  late SyncStatus _status;
  StreamSubscription<SyncStatus>? _subscription;

  @override
  void initState() {
    super.initState();
    _status = SyncService().lastStatus;
    _subscription = SyncService().syncStream.listen((status) {
      if (!mounted) return;
      setState(() => _status = status);
    });
    SyncService().emitCurrentStatus();
  }

  @override
  void dispose() {
    _subscription?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final attention = _status.rejected +
        _status.blockedDependency +
        _status.resolvedConflict;
    final paused = _status.pausedAuth + _status.pausedScope;
    final count = attention + paused;
    if (count <= 0) return const SizedBox.shrink();
    final text = [
      if (attention > 0) '$attention need review',
      if (paused > 0) '$paused paused safely',
    ].join(' · ');
    return Material(
      color: AppTheme.warning.withOpacity(0.13),
      child: InkWell(
        onTap: () => openSyncCenter(context),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(children: [
            const Icon(Icons.sync_problem_rounded,
                size: 17, color: AppTheme.warning),
            const SizedBox(width: 8),
            Expanded(
              child: Text(text,
                  style: const TextStyle(
                      fontSize: 11, fontWeight: FontWeight.w600)),
            ),
            const Text('Review',
                style: TextStyle(
                    fontSize: 11,
                    color: AppTheme.primary,
                    fontWeight: FontWeight.w800)),
            const SizedBox(width: 2),
            const Icon(Icons.chevron_right_rounded,
                size: 17, color: AppTheme.primary),
          ]),
        ),
      ),
    );
  }
}
