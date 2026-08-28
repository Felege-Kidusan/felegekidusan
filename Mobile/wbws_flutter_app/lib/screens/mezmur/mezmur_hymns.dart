import 'dart:async';

import 'package:flutter/material.dart';

import '../../services/hymn_store.dart';
import '../../services/sync_service.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/offline_banner.dart';
import 'mezmur_categories.dart';
import 'mezmur_hymn_detail.dart';
import 'mezmur_hymn_editor.dart';

/// Hymn library — LOCAL-FIRST (Telegram / Google Drive model).
///
/// Every read hits the on-device SQLite copy: opening the screen and
/// searching are instant and work with the radio off. Writes (add,
/// edit, archive, categories) are optimistic + queued; the sync
/// engine pushes them with idempotency keys and pulls a delta of
/// server changes via a change-token cursor.
class MezmurHymnsScreen extends StatefulWidget {
  const MezmurHymnsScreen({super.key});
  @override
  State<MezmurHymnsScreen> createState() => MezmurHymnsScreenState();
}

class MezmurHymnsScreenState extends State<MezmurHymnsScreen> {
  final _store = HymnStore();
  final _sync = SyncService();
  final _searchCtrl = TextEditingController();

  List<Map<String, dynamic>> _items = [];
  List<Map<String, dynamic>> _categories = [];
  String _category = '';
  bool _showArchived = false;
  bool _bootstrapping = true; // skeleton only on the very first open
  int _pending = 0;
  Timer? _searchDebounce;
  StreamSubscription<SyncStatus>? _syncSub;

  @override
  void initState() {
    super.initState();
    _store.addListener(_reload);
    _syncSub = _sync.syncStream.listen((_) => _refreshPending());
    _open();
  }

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _syncSub?.cancel();
    _store.removeListener(_reload);
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _open() async {
    await _reload();
    final count = await _store.hymns();
    if (!mounted) return;
    setState(() => _bootstrapping = false);
    if (count.isEmpty) {
      // First-ever open: bootstrap over the network (shows skeleton).
      await _store.pullChanges();
      if (!mounted) return;
      await _reload();
      setState(() => _bootstrapping = false);
    } else {
      // Stale-while-revalidate: show local data NOW, refresh behind it.
      unawaited(_store.refreshAll().catchError((_) {}));
    }
  }

  Future<void> _reload() async {
    final items = await _store.hymns(
      search: _searchCtrl.text,
      category: _category,
      includeArchived: _showArchived,
    );
    final cats = await _store.categories();
    if (!mounted) return;
    setState(() {
      _items = items;
      _categories = cats;
    });
    await _refreshPending();
  }

  Future<void> _refreshPending() async {
    final n = await _store.pendingOpsCount();
    if (!mounted) return;
    if (n != _pending) setState(() => _pending = n);
  }

  void _onSearchChanged(String _) {
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 150), _reload);
  }

  Future<void> _refresh() async {
    await _store.refreshAll();
    await _reload();
  }

  // ── actions (curators only; server re-checks each write) ────

  Future<void> _addHymn() async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => const MezmurHymnEditorScreen()),
    );
    if (changed == true) await _reload();
  }

  Future<void> _editHymn(Map<String, dynamic> hymn) async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
          builder: (_) => MezmurHymnEditorScreen(hymn: hymn)),
    );
    if (changed == true) await _reload();
  }

  Future<void> _toggleArchive(Map<String, dynamic> hymn) async {
    final id = _asInt(hymn['id']);
    final archived = '${hymn['status']}' == 'archived';
    final err = await _store.setHymnStatus(id, archived ? 'active' : 'archived');
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(err ??
          (archived
              ? 'Hymn restored — syncing.'
              : 'Hymn archived — syncing.')),
      duration: const Duration(seconds: 2),
    ));
    await _reload();
  }

  Future<void> _openCategories() async {
    await Navigator.of(context)
        .push(MaterialPageRoute(builder: (_) => const MezmurCategoriesScreen()));
    await _reload();
  }

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  // ── build ───────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Hymn Library · መዝሙር'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          if (_store.canEdit)
            IconButton(
              tooltip: 'Manage categories',
              icon: const Icon(Icons.category_outlined, size: 20),
              onPressed: _openCategories,
            ),
          if (_pending > 0)
            Padding(
              padding: const EdgeInsets.only(right: 12),
              child: Center(
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(0.18),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.cloud_upload_outlined,
                        size: 13, color: Colors.white),
                    const SizedBox(width: 4),
                    Text('$_pending',
                        style: const TextStyle(
                            fontSize: 11,
                            color: Colors.white,
                            fontWeight: FontWeight.w700)),
                  ]),
                ),
              ),
            ),
        ],
      ),
      floatingActionButton: _store.canEdit
          ? FloatingActionButton.extended(
              backgroundColor: AppTheme.primary,
              foregroundColor: Colors.white,
              onPressed: _addHymn,
              icon: const Icon(Icons.add, size: 20),
              label: const Text('Add Hymn'),
            )
          : null,
      body: Column(
        children: [
          const OfflineBanner(),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            child: TextField(
              controller: _searchCtrl,
              onChanged: _onSearchChanged,
              decoration: InputDecoration(
                hintText: 'Search title, Amharic or reference…',
                hintStyle: const TextStyle(fontSize: 13),
                prefixIcon: const Icon(Icons.search, size: 18),
                suffixIcon: IconButton(
                  icon: const Icon(Icons.clear, size: 16),
                  onPressed: () {
                    _searchCtrl.clear();
                    _reload();
                  },
                ),
                isDense: true,
                border:
                    OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
              ),
            ),
          ),
          if (_categories.isNotEmpty || _store.canEdit)
            SizedBox(
              height: 42,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                children: [
                  _chip('All', ''),
                  for (final c in _categories) _chip('${c['name']}', '${c['name']}'),
                  if (_store.canEdit)
                    Padding(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 4, vertical: 6),
                      child: FilterChip(
                        avatar: const Icon(Icons.archive_outlined, size: 13),
                        label: Text(
                            _showArchived ? 'Hide archived' : 'Archived',
                            style: const TextStyle(fontSize: 11)),
                        selected: _showArchived,
                        onSelected: (_) {
                          setState(() => _showArchived = !_showArchived);
                          _reload();
                        },
                      ),
                    ),
                ],
              ),
            ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: _buildBody(),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (_bootstrapping && _items.isEmpty) return const StudentListSkeleton();
    if (_items.isEmpty) {
      final filtering =
          _searchCtrl.text.trim().isNotEmpty || _category.isNotEmpty;
      return ListView(children: [
        const SizedBox(height: 60),
        EmptyState(
          icon: Icons.music_off_outlined,
          title: filtering ? 'No hymns match' : 'No hymns yet',
          subtitle: filtering
              ? 'Try a different search or category.'
              : (_store.canEdit
                  ? 'Tap “Add Hymn” to start the library — works offline.'
                  : 'The library is empty. Ask the Mezmur department to add hymns.'),
        ),
      ]);
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
      cacheExtent: kListCacheExtent,
      itemCount: _items.length,
      itemBuilder: (context, i) {
        final h = _items[i];
        final archived = '${h['status']}' == 'archived';
        final hasLyrics = h['lyrics'] != null;
        return RepaintBoundaryListItem(
          child: Opacity(
            opacity: archived ? 0.55 : 1,
            child: Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                onTap: () async {
                  await Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => MezmurHymnDetailScreen(id: _asInt(h['id'])),
                  ));
                  await _reload();
                },
                leading: CircleAvatar(
                  backgroundColor: AppTheme.primary.withOpacity(0.1),
                  child: Icon(
                      archived ? Icons.archive_outlined : Icons.music_note,
                      size: 18,
                      color: AppTheme.primary),
                ),
                title: Text('${h['title']}',
                    style: const TextStyle(
                        fontSize: 13.5, fontWeight: FontWeight.w600),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis),
                subtitle: Text(
                  '${h['title_am'] ?? ''}'
                  '${'${h['title_am'] ?? ''}'.isNotEmpty && '${h['category'] ?? ''}'.isNotEmpty ? ' · ' : ''}'
                  '${h['category'] ?? ''}'
                  '${!hasLyrics ? ' · lyrics downloading…' : ''}',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      fontSize: 11, color: AppTheme.textSecondary),
                ),
                trailing: _store.canEdit
                    ? PopupMenuButton<String>(
                        icon: const Icon(Icons.more_vert, size: 18),
                        onSelected: (v) async {
                          if (v == 'edit') await _editHymn(h);
                          if (v == 'archive') await _toggleArchive(h);
                        },
                        itemBuilder: (_) => [
                          const PopupMenuItem(
                              value: 'edit',
                              height: 36,
                              child: Row(children: [
                                Icon(Icons.edit_outlined, size: 16),
                                SizedBox(width: 8),
                                Text('Edit', style: TextStyle(fontSize: 12.5))
                              ])),
                          PopupMenuItem(
                              value: 'archive',
                              height: 36,
                              child: Row(children: [
                                Icon(
                                    archived
                                        ? Icons.unarchive_outlined
                                        : Icons.archive_outlined,
                                    size: 16),
                                const SizedBox(width: 8),
                                Text(archived ? 'Restore' : 'Archive',
                                    style: const TextStyle(fontSize: 12.5))
                              ])),
                        ],
                      )
                    : const Icon(Icons.chevron_right, size: 18),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _chip(String label, String value) {
    final on = _category == value;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
      child: FilterChip(
        label: Text(label, style: const TextStyle(fontSize: 11)),
        selected: on,
        onSelected: (_) {
          setState(() => _category = value);
          _reload();
        },
      ),
    );
  }
}
