import 'package:flutter/material.dart';

import '../services/local_db.dart';
import '../services/sync_service.dart';
import '../utils/theme.dart';

/// F8 — honest surfacing for offline packets the school's workflow
/// refused (the day/test was already submitted by another role, or a
/// school rule blocked the save). The server does NOT have this data;
/// it lives only on this phone until it is reviewed or deliberately
/// discarded. Nothing here is ever deleted without a confirmation.

/// Warning strip for the attendance / grades / mezmur / HR screens.
/// Mirrors the "Waiting for network" offline strip, but tells the
/// truth about packets that will NOT sync on their own.
class SyncAttentionBanner extends StatelessWidget {
  final int rejectedCount;
  const SyncAttentionBanner({super.key, required this.rejectedCount});

  @override
  Widget build(BuildContext context) {
    final n = rejectedCount;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      color: AppTheme.danger.withOpacity(0.12),
      child: Row(
        children: [
          const Icon(Icons.report_problem_outlined,
              size: 16, color: AppTheme.danger),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              '$n saved sheet${n == 1 ? '' : 's'} could not sync — the school '
              'server refused ${n == 1 ? 'it' : 'them'}. '
              'Kept safely on this phone.',
              style: const TextStyle(
                  fontSize: 11,
                  color: AppTheme.danger,
                  fontWeight: FontWeight.w500),
            ),
          ),
          const SizedBox(width: 8),
          GestureDetector(
            onTap: () => showSyncRejectedSheet(context),
            child: const Text('Review',
                style: TextStyle(
                    fontSize: 11,
                    color: AppTheme.primary,
                    fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }
}

/// Bottom sheet listing every rejected batch with the server's reason.
/// Per-item "Discard" (with confirmation) is the ONLY way these rows
/// are ever deleted — the sync engine never removes them.
Future<void> showSyncRejectedSheet(BuildContext context) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (_) => const _SyncRejectedSheet(),
  );
}

class _SyncRejectedSheet extends StatefulWidget {
  const _SyncRejectedSheet();

  @override
  State<_SyncRejectedSheet> createState() => _SyncRejectedSheetState();
}

class _SyncRejectedSheetState extends State<_SyncRejectedSheet> {
  final _db = LocalDb();
  List<Map<String, dynamic>> _batches = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    final batches = await _db.getRejectedBatches();
    if (mounted) {
      setState(() {
        _batches = batches;
        _loading = false;
      });
    }
  }

  Future<void> _discard(Map<String, dynamic> batch) async {
    final label = '${batch['label']} · ${batch['detail']}';
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Discard this sheet?'),
        content: Text(
            'The school server never received "$label". Discarding deletes '
            'it from this phone for good. This cannot be undone.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Keep it')),
          TextButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Discard',
                  style: TextStyle(color: AppTheme.danger))),
        ],
      ),
    );
    if (confirmed != true) return;
    final kind = '${batch['kind']}';
    final clientOpId = '${batch['client_op_id'] ?? ''}'.trim();
    if (clientOpId.isEmpty) return;
    await _db.discardRejectedOperation(kind, clientOpId);
    await _reload();
    await SyncService().emitCurrentStatus();
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * 0.7),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    const Icon(Icons.report_problem_outlined,
                        size: 20, color: AppTheme.danger),
                    const SizedBox(width: 8),
                    Text('Could not sync',
                        style: TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w800,
                            color: AppTheme.textPrimary)),
                  ]),
                  const SizedBox(height: 8),
                  const Text(
                    'These sheets were saved on this phone, but the school '
                    'server refused them — the day or test was already '
                    'submitted by someone else, or a school rule blocked '
                    'the save. They are NOT on the server.',
                    style: TextStyle(fontSize: 12),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'To retry, open the day on its screen and save again. '
                    'Discard only when you are sure — it deletes the data '
                    'from this phone for good.',
                    style: TextStyle(fontSize: 12),
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            if (_loading)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_batches.isEmpty)
              const Padding(
                padding: EdgeInsets.all(24),
                child: Center(
                    child: Text('Nothing needs attention.',
                        style: TextStyle(fontSize: 13))),
              )
            else
              Flexible(
                child: ListView.builder(
                  shrinkWrap: true,
                  itemCount: _batches.length,
                  itemBuilder: (_, i) {
                    final b = _batches[i];
                    return ListTile(
                      dense: true,
                      leading: const Icon(Icons.cloud_off_outlined,
                          size: 20, color: AppTheme.danger),
                      title: Text('${b['label']}',
                          style: const TextStyle(
                              fontSize: 13, fontWeight: FontWeight.w700)),
                      subtitle: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${b['detail']}',
                              style: TextStyle(
                                  fontSize: 11,
                                  color: AppTheme.textSecondary)),
                          if ('${b['reason'] ?? ''}'.isNotEmpty)
                            Text('Server: ${b['reason']}',
                                style: TextStyle(
                                    fontSize: 11,
                                    fontStyle: FontStyle.italic,
                                    color: AppTheme.textSecondary)),
                        ],
                      ),
                      trailing: TextButton(
                        onPressed: () => _discard(b),
                        child: const Text('Discard',
                            style: TextStyle(color: AppTheme.danger)),
                      ),
                    );
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }
}
