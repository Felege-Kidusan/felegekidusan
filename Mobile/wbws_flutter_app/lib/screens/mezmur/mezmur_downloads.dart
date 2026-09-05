import 'package:flutter/material.dart';

import '../../services/local_db.dart';
import '../../services/mezmur_download_manager.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
import 'mezmur_download_settings.dart';
import 'mezmur_player_screen.dart';

/// P33b — "Downloads": a clean audio library of what plays offline.
///
/// Deliberately shaped like the Hymn Library list, not like a settings
/// page: same card rows, same tap-to-play gesture, same player. All
/// policy controls (storage cap, mobile data, remove-all) moved to
/// [MezmurDownloadSettingsScreen] behind the gear button, so this screen
/// is purely content.
///
/// Tapping a row opens the full player with **the whole downloaded list
/// as its queue**, so next/previous walk your offline collection exactly
/// the way they walk a category in the library.
class MezmurDownloadsScreen extends StatefulWidget {
  const MezmurDownloadsScreen({super.key});

  @override
  State<MezmurDownloadsScreen> createState() => _MezmurDownloadsScreenState();
}

class _MezmurDownloadsScreenState extends State<MezmurDownloadsScreen> {
  final _dl = MezmurDownloadManager.instance;
  final _db = LocalDb();

  List<Map<String, dynamic>> _done = const [];
  List<Map<String, dynamic>> _pending = const [];
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
    if (!mounted) return;
    setState(() {});
    // A finished download moves between sections — refresh the rows so
    // the list reflects it without the user pulling to refresh.
    _reloadRowsSoon();
  }

  bool _reloadQueued = false;
  Future<void> _reloadRowsSoon() async {
    if (_reloadQueued) return;
    _reloadQueued = true;
    await Future<void>.delayed(const Duration(milliseconds: 400));
    _reloadQueued = false;
    if (mounted) await _load();
  }

  Future<void> _load() async {
    final rows = await _db.downloadRows();
    if (!mounted) return;
    setState(() {
      _done = rows.where((r) => '${r['state']}' == 'done').toList();
      _pending = rows.where((r) => '${r['state']}' != 'done').toList();
      _loading = false;
    });
  }

  /// Open the real player, queued with every downloaded hymn.
  ///
  /// The rows are hydrated from `cached_hymns` so the player gets the
  /// same shape the library gives it (lyrics, synced lyrics, duration,
  /// audio_status) — a downloaded hymn must not be a second-class
  /// citizen with a degraded now-playing screen.
  Future<void> _play(Map<String, dynamic> row) async {
    final tapped = _int(row['hymn_id']);
    final hymnRows = <Map<String, dynamic>>[];
    for (final r in _done) {
      final id = _int(r['hymn_id']);
      final h = await _db.getLocalHymn(id);
      if (h != null) {
        hymnRows.add(h);
      } else {
        // Library row missing (hymn deleted server-side but the file is
        // still here): synthesise the minimum the player needs.
        hymnRows.add({
          'id': id,
          'title': '${r['title'] ?? 'መዝሙር $id'}',
          'category': r['category'],
          'audio_status': 'ready',
          'audio_duration_s': r['audio_duration_s'],
        });
      }
    }
    if (!mounted || hymnRows.isEmpty) return;
    await MezmurPlayerScreen.openFromRows(context,
        rows: hymnRows, hymnId: tapped);
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      appBar: AppBar(
        title: const Text('Downloads'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            tooltip: 'Download settings',
            icon: const Icon(Icons.settings_outlined, size: 21),
            onPressed: () async {
              await Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => const MezmurDownloadSettingsScreen()));
              if (mounted) await _load();
            },
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: (_done.isEmpty && _pending.isEmpty)
                  ? ListView(children: const [
                      SizedBox(height: 70),
                      EmptyState(
                        icon: Icons.download_outlined,
                        title: 'No downloads yet',
                        subtitle:
                            'Tap the download arrow on any hymn to keep it on '
                            'your phone. Downloaded hymns play with no '
                            'internet — useful for church, travel and areas '
                            'with weak signal.',
                      ),
                    ])
                  : ListView.builder(
                      padding: const EdgeInsets.fromLTRB(16, 10, 16, 96),
                      cacheExtent: kListCacheExtent,
                      itemCount: _headerCount + _done.length,
                      itemBuilder: (context, i) {
                        if (i < _headerCount) return _buildHeader(i);
                        return _hymnTile(_done[i - _headerCount]);
                      },
                    ),
            ),
    );
  }

  /// Summary strip + (optionally) the in-progress block, above the list.
  int get _headerCount => _pending.isEmpty ? 1 : 2;

  Widget _buildHeader(int i) {
    if (i == 0) return _summaryStrip();
    return _inProgressBlock();
  }

  Widget _summaryStrip() {
    final status = _dl.queueStatus;
    final warn = status == 'waiting-wifi' || status == 'no-network';
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Icon(Icons.download_done_rounded,
              size: 15, color: AppTheme.success),
          const SizedBox(width: 6),
          Text(
            '${_done.length} hymn${_done.length == 1 ? '' : 's'} · '
            '${MezmurDownloadManager.formatBytes(_dl.bytesOnDisk)}',
            style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: AppTheme.textSecondary),
          ),
        ]),
        if (warn) ...[
          const SizedBox(height: 9),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            decoration: BoxDecoration(
              color: AppTheme.warning.withOpacity(0.10),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Row(children: [
              const Icon(Icons.info_outline, size: 14, color: AppTheme.warning),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  status == 'no-network'
                      ? '${_dl.queuedCount} waiting for a network connection.'
                      : '${_dl.queuedCount} waiting for Wi‑Fi.',
                  style: const TextStyle(fontSize: 11.2),
                ),
              ),
              if (status == 'waiting-wifi')
                TextButton(
                  onPressed: () => _dl.setWifiOnly(false),
                  style: TextButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      minimumSize: const Size(0, 30)),
                  child: const Text('Use data',
                      style: TextStyle(fontSize: 11.5)),
                ),
            ]),
          ),
        ],
      ]),
    );
  }

  Widget _inProgressBlock() {
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(
        padding: const EdgeInsets.only(bottom: 7, top: 2),
        child: Text('DOWNLOADING · ${_pending.length}',
            style: const TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.8,
                color: AppTheme.textSecondary)),
      ),
      ..._pending.map(_pendingTile),
      const Padding(
        padding: EdgeInsets.only(top: 10, bottom: 7),
        child: Text('ON THIS PHONE',
            style: TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.8,
                color: AppTheme.textSecondary)),
      ),
    ]);
  }

  /// A downloaded hymn — same visual language as the Hymn Library row.
  Widget _hymnTile(Map<String, dynamic> r) {
    final id = _int(r['hymn_id']);
    final secs = _int(r['audio_duration_s']);
    final meta = <String>[
      if ('${r['category'] ?? ''}'.isNotEmpty) '${r['category']}',
      if (secs > 0) _duration(secs),
      MezmurDownloadManager.formatBytes(_int(r['bytes_done'])),
    ].join(' · ');

    return RepaintBoundaryListItem(
      child: Card(
        margin: const EdgeInsets.only(bottom: 8),
        child: ListTile(
          onTap: () => _play(r),
          leading: CircleAvatar(
            backgroundColor: AppTheme.primary.withOpacity(0.1),
            child: const Icon(Icons.music_note,
                size: 18, color: AppTheme.primary),
          ),
          title: Text('${r['title'] ?? 'መዝሙር $id'}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                  fontSize: 13.5, fontWeight: FontWeight.w600)),
          subtitle: Row(children: [
            const Icon(Icons.download_done_rounded,
                size: 11, color: AppTheme.success),
            const SizedBox(width: 4),
            Expanded(
              child: Text(meta,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      fontSize: 11, color: AppTheme.textSecondary)),
            ),
          ]),
          trailing: PopupMenuButton<String>(
            icon: const Icon(Icons.more_vert, size: 18),
            onSelected: (v) async {
              if (v == 'play') await _play(r);
              if (v == 'remove') await _confirmRemove(r);
            },
            itemBuilder: (_) => const [
              PopupMenuItem(
                  value: 'play',
                  height: 36,
                  child: Row(children: [
                    Icon(Icons.play_arrow_rounded, size: 16),
                    SizedBox(width: 8),
                    Text('Play', style: TextStyle(fontSize: 12.5)),
                  ])),
              PopupMenuItem(
                  value: 'remove',
                  height: 36,
                  child: Row(children: [
                    Icon(Icons.delete_outline, size: 16),
                    SizedBox(width: 8),
                    Text('Remove download',
                        style: TextStyle(fontSize: 12.5)),
                  ])),
            ],
          ),
        ),
      ),
    );
  }

  Widget _pendingTile(Map<String, dynamic> r) {
    final id = _int(r['hymn_id']);
    final state = _dl.stateOf(id);
    final pr = _dl.progressOf(id);
    final failed = state == 'failed';
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: SizedBox(
          width: 34,
          height: 34,
          child: failed
              ? const Icon(Icons.error_outline,
                  color: AppTheme.warning, size: 22)
              : Stack(alignment: Alignment.center, children: [
                  CircularProgressIndicator(
                    value: state == 'downloading' && pr > 0 ? pr : null,
                    strokeWidth: 2.5,
                    valueColor: const AlwaysStoppedAnimation<Color>(
                        AppTheme.success),
                    backgroundColor: AppTheme.borderLight,
                  ),
                  if (state == 'downloading' && pr > 0)
                    Text('${(pr * 100).round()}',
                        style: const TextStyle(
                            fontSize: 8.5, fontWeight: FontWeight.w700)),
                ]),
        ),
        title: Text('${r['title'] ?? 'መዝሙር $id'}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style:
                const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600)),
        subtitle: Text(
          failed
              ? '${r['error'] ?? 'Download failed'}'
              : _pendingLabel(state, r),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
              fontSize: 11,
              color: failed ? AppTheme.warning : AppTheme.textSecondary),
        ),
        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
          if (failed)
            IconButton(
              icon: const Icon(Icons.refresh_rounded, size: 19),
              tooltip: 'Retry',
              onPressed: () => _dl.retry(id),
            ),
          IconButton(
            icon: const Icon(Icons.close_rounded, size: 19),
            tooltip: 'Cancel download',
            onPressed: () async {
              await _dl.remove(id);
              await _load();
            },
          ),
        ]),
      ),
    );
  }

  String _pendingLabel(String state, Map<String, dynamic> r) {
    if (state == 'paused') return 'Paused';
    if (state == 'downloading') {
      final total = _int(r['bytes_total']);
      return total > 0
          ? 'Downloading · ${MezmurDownloadManager.formatBytes(total)}'
          : 'Downloading…';
    }
    switch (_dl.queueStatus) {
      case 'no-network':
        return 'Waiting for network';
      case 'waiting-wifi':
        return 'Waiting for Wi‑Fi';
      default:
        return 'Queued';
    }
  }

  Future<void> _confirmRemove(Map<String, dynamic> r) async {
    final id = _int(r['hymn_id']);
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Remove download?'),
        content: Text('"${r['title'] ?? 'This hymn'}" will no longer play '
            'without internet. It stays in the hymn library.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Keep')),
          TextButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Remove')),
        ],
      ),
    );
    if (ok != true) return;
    await _dl.remove(id);
    await _load();
  }

  static String _duration(int s) {
    final m = s ~/ 60;
    final r = s % 60;
    return '$m:${r.toString().padLeft(2, '0')}';
  }

  static int _int(dynamic v) => v is int ? v : int.tryParse('${v ?? ''}') ?? 0;
}
