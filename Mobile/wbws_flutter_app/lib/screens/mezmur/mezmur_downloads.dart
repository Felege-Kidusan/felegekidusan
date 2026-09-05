import 'package:flutter/material.dart';

import '../../services/local_db.dart';
import '../../services/mezmur_audio_player.dart';
import '../../services/mezmur_download_manager.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';

/// P33 — "Downloads": the storage screen Spotify puts under Settings.
///
/// Answers the three questions a user on a metered Ethio Telecom bundle
/// actually has: what is on my phone, how much space is it using, and
/// how do I stop it eating my data.
class MezmurDownloadsScreen extends StatefulWidget {
  const MezmurDownloadsScreen({super.key});

  @override
  State<MezmurDownloadsScreen> createState() => _MezmurDownloadsScreenState();
}

class _MezmurDownloadsScreenState extends State<MezmurDownloadsScreen> {
  final _dl = MezmurDownloadManager.instance;
  final _db = LocalDb();
  List<Map<String, dynamic>> _rows = const [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _dl.addListener(_onChange);
    _load();
  }

  @override
  void dispose() {
    _dl.removeListener(_onChange);
    super.dispose();
  }

  void _onChange() {
    if (mounted) setState(() {});
  }

  Future<void> _load() async {
    final rows = await _db.downloadRows();
    if (!mounted) return;
    setState(() {
      _rows = rows;
      _loading = false;
    });
  }

  Future<void> _playRow(Map<String, dynamic> r) async {
    final id = _int(r['hymn_id']);
    final track = MezmurTrack(
      hymnId: id,
      title: '${r['title'] ?? 'መዝሙር $id'}',
      // Proper file:// URI — the player also re-resolves the local copy,
      // this just makes the track valid before it gets there.
      audioUrl: Uri.file('${r['file_path'] ?? ''}').toString(),
      audioStatus: 'ready',
      category: r['category'] as String?,
      durationSeconds: _int(r['audio_duration_s']) > 0
          ? _int(r['audio_duration_s'])
          : null,
    );
    await MezmurAudioPlayerController.instance
        .openCatalog([track], startIndex: 0);
  }

  @override
  Widget build(BuildContext context) {
    final done = _rows.where((r) => '${r['state']}' == 'done').toList();
    final pending = _rows.where((r) => '${r['state']}' != 'done').toList();

    return Scaffold(
      appBar: AppBar(
        title: const Text('Downloads'),
        actions: [
          if (done.isNotEmpty)
            IconButton(
              tooltip: 'Remove all downloads',
              icon: const Icon(Icons.delete_sweep_outlined),
              onPressed: _confirmRemoveAll,
            ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 12, 16, 96),
                children: [
                  _storageCard(),
                  const SizedBox(height: 14),
                  _settingsCard(),
                  if (pending.isNotEmpty) ...[
                    const SizedBox(height: 18),
                    _sectionTitle('In progress', pending.length),
                    ...pending.map(_pendingTile),
                  ],
                  const SizedBox(height: 18),
                  _sectionTitle('On this phone', done.length),
                  if (done.isEmpty)
                    const Padding(
                      padding: EdgeInsets.only(top: 24),
                      child: EmptyState(
                        icon: Icons.download_outlined,
                        title: 'No downloads yet',
                        subtitle:
                            'Tap the download arrow on any hymn to keep it on '
                            'your phone. Downloaded hymns play with no internet.',
                      ),
                    )
                  else
                    ...done.map(_doneTile),
                ],
              ),
            ),
    );
  }

  Widget _sectionTitle(String label, int n) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text('$label · $n',
            style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.4,
                color: AppTheme.textSecondary)),
      );

  Widget _storageCard() {
    final used = _dl.bytesOnDisk;
    final cap = _dl.capMb * 1024 * 1024;
    final frac = cap > 0 ? (used / cap).clamp(0.0, 1.0) : 0.0;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const Icon(Icons.sd_storage_outlined,
                size: 18, color: AppTheme.primary),
            const SizedBox(width: 8),
            Text('${MezmurDownloadManager.formatBytes(used)} used',
                style: const TextStyle(
                    fontSize: 14, fontWeight: FontWeight.w700)),
            const Spacer(),
            Text('${_dl.downloadedCount} hymns',
                style: const TextStyle(
                    fontSize: 11.5, color: AppTheme.textSecondary)),
          ]),
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: cap > 0 ? frac : null,
              minHeight: 6,
              backgroundColor: AppTheme.borderLight,
              valueColor: AlwaysStoppedAnimation<Color>(
                  frac > 0.9 ? AppTheme.warning : AppTheme.success),
            ),
          ),
          const SizedBox(height: 6),
          Text(
            _dl.capMb == 0
                ? 'No storage limit set'
                : 'Limit ${_dl.capMb >= 1024 ? '${(_dl.capMb / 1024).toStringAsFixed(_dl.capMb % 1024 == 0 ? 0 : 1)} GB' : '${_dl.capMb} MB'}'
                    ' · least-played automatic downloads are removed first',
            style: const TextStyle(
                fontSize: 10.5, color: AppTheme.textSecondary),
          ),
          if (_dl.waitingForWifi || _dl.waitingForNetwork) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
              decoration: BoxDecoration(
                color: AppTheme.warning.withOpacity(0.10),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(children: [
                const Icon(Icons.info_outline,
                    size: 14, color: AppTheme.warning),
                const SizedBox(width: 7),
                Expanded(
                  child: Text(
                    _dl.waitingForNetwork
                        ? '${_dl.queuedCount} waiting for a network connection.'
                        : '${_dl.queuedCount} waiting for Wi‑Fi. Turn on '
                            '"Download over mobile data" to continue now.',
                    style: const TextStyle(fontSize: 11),
                  ),
                ),
              ]),
            ),
          ],
        ]),
      ),
    );
  }

  Widget _settingsCard() {
    return Card(
      child: Column(children: [
        SwitchListTile(
          dense: true,
          value: !_dl.wifiOnly,
          onChanged: (v) => _dl.setWifiOnly(!v),
          title: const Text('Download over mobile data',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
          subtitle: const Text(
              'Off = downloads only start on Wi‑Fi. Recommended.',
              style: TextStyle(fontSize: 11)),
        ),
        const Divider(height: 1),
        ListTile(
          dense: true,
          title: const Text('Storage limit',
              style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
          subtitle: const Text(
              'When exceeded, the least-played automatic downloads go first. '
              'Hymns you downloaded yourself are always kept.',
              style: TextStyle(fontSize: 11)),
          trailing: DropdownButton<int>(
            value: _dl.capMb,
            underline: const SizedBox.shrink(),
            style: const TextStyle(fontSize: 12.5, color: AppTheme.textPrimary),
            items: const [
              DropdownMenuItem(value: 512, child: Text('512 MB')),
              DropdownMenuItem(value: 1024, child: Text('1 GB')),
              DropdownMenuItem(value: 2048, child: Text('2 GB')),
              DropdownMenuItem(value: 5120, child: Text('5 GB')),
              DropdownMenuItem(value: 0, child: Text('No limit')),
            ],
            onChanged: (v) => v == null ? null : _dl.setCapMb(v),
          ),
        ),
        if (_dl.queuedCount > 0) ...[
          const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            child: Row(children: [
              Expanded(
                child: Text('${_dl.queuedCount} in the queue',
                    style: const TextStyle(fontSize: 12)),
              ),
              TextButton.icon(
                onPressed: _dl.pauseAll,
                icon: const Icon(Icons.pause, size: 15),
                label: const Text('Pause', style: TextStyle(fontSize: 12)),
              ),
              TextButton.icon(
                onPressed: _dl.resumeAll,
                icon: const Icon(Icons.play_arrow, size: 15),
                label: const Text('Resume', style: TextStyle(fontSize: 12)),
              ),
            ]),
          ),
        ],
      ]),
    );
  }

  Widget _pendingTile(Map<String, dynamic> r) {
    final id = _int(r['hymn_id']);
    final state = _dl.stateOf(id);
    final pr = _dl.progressOf(id);
    final failed = state == 'failed';
    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: ListTile(
        dense: true,
        leading: SizedBox(
          width: 26,
          height: 26,
          child: failed
              ? const Icon(Icons.error_outline,
                  color: AppTheme.warning, size: 22)
              : CircularProgressIndicator(
                  value: state == 'downloading' && pr > 0 ? pr : null,
                  strokeWidth: 2.5,
                  valueColor:
                      const AlwaysStoppedAnimation<Color>(AppTheme.success),
                  backgroundColor: AppTheme.borderLight,
                ),
        ),
        title: Text('${r['title'] ?? 'መዝሙር $id'}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
        subtitle: Text(
          failed
              ? '${r['error'] ?? 'Download failed'} · tap retry'
              : (state == 'downloading'
                  ? '${(pr * 100).round()}% · '
                      '${MezmurDownloadManager.formatBytes(_int(r['bytes_total']))}'
                  : _queueLabel(state)),
          style: TextStyle(
              fontSize: 11,
              color: failed ? AppTheme.warning : AppTheme.textSecondary),
        ),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          if (failed)
            IconButton(
              icon: const Icon(Icons.refresh, size: 18),
              tooltip: 'Retry',
              onPressed: () => _dl.retry(id),
            ),
          IconButton(
            icon: const Icon(Icons.close, size: 18),
            tooltip: 'Cancel',
            onPressed: () async {
              await _dl.remove(id);
              await _load();
            },
          ),
        ]),
      ),
    );
  }

  Widget _doneTile(Map<String, dynamic> r) {
    final id = _int(r['hymn_id']);
    final auto = '${r['source']}' == 'auto';
    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: ListTile(
        dense: true,
        onTap: () => _playRow(r),
        leading: Container(
          width: 26,
          height: 26,
          decoration: const BoxDecoration(
              color: AppTheme.success, shape: BoxShape.circle),
          child: const Icon(Icons.check, size: 16, color: Colors.white),
        ),
        title: Text('${r['title'] ?? 'መዝሙር $id'}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
        subtitle: Text(
          '${MezmurDownloadManager.formatBytes(_int(r['bytes_done']))}'
          '${r['category'] != null ? ' · ${r['category']}' : ''}'
          '${auto ? ' · auto' : ''}',
          style: const TextStyle(fontSize: 11, color: AppTheme.textSecondary),
        ),
        trailing: IconButton(
          icon: const Icon(Icons.delete_outline, size: 18),
          tooltip: 'Remove download',
          onPressed: () async {
            await _dl.remove(id);
            await _load();
          },
        ),
      ),
    );
  }

  String _queueLabel(String state) {
    if (state == 'paused') return 'Paused';
    if (_dl.waitingForNetwork) return 'Waiting for network';
    if (_dl.waitingForWifi) return 'Waiting for Wi‑Fi';
    return 'Queued';
  }

  Future<void> _confirmRemoveAll() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Remove all downloads?'),
        content: Text(
            'This frees ${MezmurDownloadManager.formatBytes(_dl.bytesOnDisk)}. '
            'The hymn library stays — only the offline audio is deleted.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Cancel')),
          TextButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Remove all')),
        ],
      ),
    );
    if (ok != true) return;
    await _dl.removeAll();
    await _load();
  }

  static int _int(dynamic v) => v is int ? v : int.tryParse('${v ?? ''}') ?? 0;
}
