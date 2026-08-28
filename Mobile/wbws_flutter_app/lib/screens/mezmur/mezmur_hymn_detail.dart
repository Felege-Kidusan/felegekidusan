import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/hymn_store.dart';
import '../../services/local_db.dart';
import '../../utils/theme.dart';
import '../../widgets/loading_skeleton.dart';
import 'mezmur_hymn_editor.dart';

/// Single hymn reader — LOCAL-FIRST: opens instantly from the on-device
/// copy; when the lyrics blob has not been downloaded yet it streams it
/// in the background and persists it for future offline reading.
class MezmurHymnDetailScreen extends StatefulWidget {
  final int id;
  const MezmurHymnDetailScreen({super.key, required this.id});
  @override
  State<MezmurHymnDetailScreen> createState() => _MezmurHymnDetailState();
}

class _MezmurHymnDetailState extends State<MezmurHymnDetailScreen> {
  final _store = HymnStore();
  final _api = ApiService();
  final _db = LocalDb();

  Map<String, dynamic>? _hymn;
  bool _fetchingLyrics = false;

  @override
  void initState() {
    super.initState();
    _store.addListener(_reload);
    _open();
  }

  @override
  void dispose() {
    _store.removeListener(_reload);
    super.dispose();
  }

  Future<void> _reload() async {
    final h = await _store.hymn(widget.id);
    if (!mounted) return;
    setState(() => _hymn = h);
  }

  Future<void> _open() async {
    await _reload();
    final h = _hymn;
    // Lazy lyrics blob: stream once, then cached for offline reading.
    if (h != null && h['lyrics'] == null && ConnectivityService().hasLink) {
      setState(() => _fetchingLyrics = true);
      try {
        final res = await _api.getMezmurHymn(widget.id);
        if (res.success && res.data is Map && res.data['item'] is Map) {
          final item = Map<String, dynamic>.from(res.data['item']);
          await _db.upsertHymns([item]);
          await _reload();
        }
      } catch (_) {}
      if (mounted) setState(() => _fetchingLyrics = false);
    }
  }

  Future<void> _edit() async {
    final h = _hymn;
    if (h == null) return;
    await Navigator.of(context).push<bool>(MaterialPageRoute(
        builder: (_) => MezmurHymnEditorScreen(hymn: h)));
    await _reload();
  }

  Future<void> _toggleArchive() async {
    final h = _hymn;
    if (h == null) return;
    final archived = '${h['status']}' == 'archived';
    final err = await _store.setHymnStatus(
        widget.id, archived ? 'active' : 'archived');
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(err ?? (archived ? 'Hymn restored.' : 'Hymn archived.')),
      duration: const Duration(seconds: 2),
    ));
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final h = _hymn;
    final lyrics = h == null ? null : '${h['lyrics'] ?? ''}';
    final archived = h != null && '${h['status']}' == 'archived';
    return Scaffold(
      appBar: AppBar(
        title: Text('${h?['title'] ?? 'Hymn'}',
            style: const TextStyle(fontSize: 15),
            maxLines: 1,
            overflow: TextOverflow.ellipsis),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          if (_store.canEdit && h != null)
            IconButton(
              tooltip: 'Edit hymn',
              icon: const Icon(Icons.edit_outlined, size: 19),
              onPressed: _edit,
            ),
          if (_store.canEdit && h != null)
            IconButton(
              tooltip: archived ? 'Restore hymn' : 'Archive hymn',
              icon: Icon(
                  archived ? Icons.unarchive_outlined : Icons.archive_outlined,
                  size: 19),
              onPressed: _toggleArchive,
            ),
          const SizedBox(width: 4),
        ],
      ),
      body: h == null
          ? const StudentListSkeleton()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if ('${h['title_am'] ?? ''}'.isNotEmpty)
                  Text('${h['title_am']}',
                      style: const TextStyle(
                          fontSize: 17, fontWeight: FontWeight.w700)),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    if ('${h['category'] ?? ''}'.isNotEmpty)
                      Chip(
                          label: Text('${h['category']}',
                              style: const TextStyle(fontSize: 11))),
                    if ('${h['reference'] ?? ''}'.isNotEmpty)
                      Chip(
                          label: Text('${h['reference']}',
                              style: const TextStyle(fontSize: 11))),
                    if (archived)
                      const Chip(
                          label: Text('Archived',
                              style: TextStyle(fontSize: 11))),
                  ],
                ),
                const SizedBox(height: 14),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: AppTheme.surfaceLight,
                    border: Border.all(color: AppTheme.borderLight),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: lyrics == null || lyrics.isEmpty
                      ? Row(children: [
                          if (_fetchingLyrics)
                            const SizedBox(
                                width: 14,
                                height: 14,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2))
                          else
                            const Icon(Icons.cloud_download_outlined,
                                size: 15, color: AppTheme.textSecondary),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              _fetchingLyrics
                                  ? 'Downloading lyrics…'
                                  : 'Lyrics not downloaded yet — open while online once, or wait for background sync.',
                              style: TextStyle(
                                  fontSize: 12.5,
                                  color: AppTheme.textSecondary),
                            ),
                          ),
                        ])
                      : Text(lyrics,
                          style:
                              const TextStyle(fontSize: 15, height: 1.9)),
                ),
              ],
            ),
    );
  }
}
