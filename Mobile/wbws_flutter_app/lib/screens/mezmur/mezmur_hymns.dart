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
import 'mezmur_zemarians.dart';

/// Hymn library — LOCAL-FIRST (Telegram / Google Drive model).
///
/// Every read hits the on-device SQLite copy: opening the screen and
/// searching are instant and work with the radio off. Writes (add,
/// edit, archive, categories) are optimistic + queued; the sync
/// engine pushes them with idempotency keys and pulls a delta of
/// server changes via a change-token cursor.
class MezmurHymnsScreen extends StatefulWidget {
  /// P24: deep links — open straight into a filtered list (tapped a
  /// category/singer chip on a hymn, or a browse tile).
  const MezmurHymnsScreen(
      {super.key, this.initialCategoryId, this.initialZemarianId});
  final int? initialCategoryId;
  final int? initialZemarianId;
  @override
  State<MezmurHymnsScreen> createState() => MezmurHymnsScreenState();
}

class MezmurHymnsScreenState extends State<MezmurHymnsScreen> {
  final _store = HymnStore();
  final _sync = SyncService();
  final _searchCtrl = TextEditingController();

  List<Map<String, dynamic>> _items = [];
  List<Map<String, dynamic>> _categories = [];
  List<Map<String, dynamic>> _zemarians = [];
  Map<int, int> _catCounts = {};
  Map<int, int> _zemCounts = {};
  int _totalCount = 0;
  int _tab = 0; // 0 hymns · 1 categories · 2 singers (bottom nav)
  int? _categoryId;
  int? _zemarianId;
  String _length = '';
  String _language = '';
  bool _showArchived = false;
  bool _bootstrapping = true; // skeleton only on the very first open
  int _pending = 0;
  Timer? _searchDebounce;
  StreamSubscription<SyncStatus>? _syncSub;

  @override
  void initState() {
    super.initState();
    _categoryId = widget.initialCategoryId;
    _zemarianId = widget.initialZemarianId;
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
      categoryId: _categoryId,
      includeArchived: _showArchived,
      length: _length.isEmpty ? null : _length,
      language: _language.isEmpty ? null : _language,
      zemarianId: _zemarianId,
    );
    final cats = await _store.categories();
    final zem = await _store.zemarians();
    final catCounts = await _store.categoryHymnCounts();
    final zemCounts = await _store.zemarianHymnCounts();
    if (!mounted) return;
    setState(() {
      _items = items;
      _categories = cats;
      _zemarians = zem;
      _catCounts = catCounts;
      _zemCounts = zemCounts;
    });
    if (!_showArchived && _categoryId == null && _zemarianId == null) {
      _totalCount = items.length;
    }
    await _refreshPending();
  }

  String _catName(int id) {
    for (final c in _categories) {
      if (_asInt(c['id']) == id) return '${c['name']}';
    }
    return 'Category #$id';
  }

  String _zemName(int id) {
    for (final z in _zemarians) {
      if (_asInt(z['id']) == id) return '${z['name']}';
    }
    return 'Singer #$id';
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

  Future<void> _openZemarians() async {
    await Navigator.of(context)
        .push(MaterialPageRoute(builder: (_) => const MezmurZemariansScreen()));
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
          if (_store.canEdit)
            IconButton(
              tooltip: 'Manage singers',
              icon: const Icon(Icons.person_outline, size: 20),
              onPressed: _openZemarians,
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
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _tab,
        onTap: (i) => setState(() => _tab = i),
        type: BottomNavigationBarType.fixed,
        selectedItemColor: AppTheme.primary,
        unselectedItemColor: AppTheme.textSecondary,
        items: const [
          BottomNavigationBarItem(
              icon: Icon(Icons.music_note_outlined),
              activeIcon: Icon(Icons.music_note),
              label: 'Hymns'),
          BottomNavigationBarItem(
              icon: Icon(Icons.grid_view_outlined),
              activeIcon: Icon(Icons.grid_view_rounded),
              label: 'Categories'),
          BottomNavigationBarItem(
              icon: Icon(Icons.person_outline),
              activeIcon: Icon(Icons.person),
              label: 'Singers'),
        ],
      ),
      body: Column(
        children: [
          const OfflineBanner(),
          if (_tab == 0) ...[
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
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10)),
                ),
              ),
            ),
            if (_categoryId != null || _zemarianId != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                child: Wrap(
                  spacing: 6,
                  children: [
                    if (_categoryId != null)
                      InputChip(
                        avatar: const Icon(Icons.category_outlined,
                            size: 13, color: AppTheme.primary),
                        label: Text(_catName(_categoryId!),
                            style: const TextStyle(fontSize: 11)),
                        onDeleted: () {
                          setState(() => _categoryId = null);
                          _reload();
                        },
                      ),
                    if (_zemarianId != null)
                      InputChip(
                        avatar: const Icon(Icons.person_outline,
                            size: 13, color: AppTheme.info),
                        label: Text(_zemName(_zemarianId!),
                            style: const TextStyle(fontSize: 11)),
                        onDeleted: () {
                          setState(() => _zemarianId = null);
                          _reload();
                        },
                      ),
                  ],
                ),
              ),
            SizedBox(
              height: 42,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                children: [
                  _flagChip('Long', _length == 'long', () {
                    setState(() => _length = _length == 'long' ? '' : 'long');
                    _reload();
                  }),
                  _flagChip('Short', _length == 'short', () {
                    setState(() => _length = _length == 'short' ? '' : 'short');
                    _reload();
                  }),
                  _flagChip('Geez', _language == 'geez', () {
                    setState(
                        () => _language = _language == 'geez' ? '' : 'geez');
                    _reload();
                  }),
                  _flagChip('Amharic', _language == 'amharic', () {
                    setState(() =>
                        _language = _language == 'amharic' ? '' : 'amharic');
                    _reload();
                  }),
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
          ],
          Expanded(
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: _tab == 0
                  ? _buildBody()
                  : _tab == 1
                      ? _browseGrid(categories: true)
                      : _browseGrid(categories: false),
            ),
          ),
        ],
      ),
    );
  }

  // ── P24: Spotify-like browse tiles (categories / singers) ────

  static const _palettes = [
    [Color(0xFF4f46e5), Color(0xFF7c3aed)],
    [Color(0xFF0ea5e9), Color(0xFF2563eb)],
    [Color(0xFF059669), Color(0xFF0d9488)],
    [Color(0xFFd97706), Color(0xFFdc2626)],
    [Color(0xFFdb2777), Color(0xFF9333ea)],
  ];

  Widget _browseGrid({required bool categories}) {
    final rows = categories ? _categories : _zemarians;
    final tiles = <Widget>[
      _tile(
        'All Hymns',
        _totalCount,
        icon: Icons.library_music_outlined,
        colors: _palettes[1],
        onTap: () => setState(() {
          _categoryId = null;
          _zemarianId = null;
          _tab = 0;
          _reload();
        }),
      ),
    ];
    for (var i = 0; i < rows.length; i++) {
      final r = rows[i];
      final id = _asInt(r['id']);
      final count =
          categories ? (_catCounts[id] ?? 0) : (_zemCounts[id] ?? 0);
      tiles.add(_tile(
        '${r['name']}',
        count,
        icon: categories ? Icons.category_outlined : Icons.person_outline,
        colors: _palettes[i % _palettes.length],
        onTap: () => setState(() {
          if (categories) {
            _categoryId = id;
            _zemarianId = null;
          } else {
            _zemarianId = id;
            _categoryId = null;
          }
          _tab = 0;
          _reload();
        }),
      ));
    }
    return GridView.count(
      crossAxisCount: 2,
      childAspectRatio: 1.45,
      padding: const EdgeInsets.fromLTRB(10, 12, 10, 96),
      children: tiles,
    );
  }

  Widget _tile(
    String name,
    int count, {
    required IconData icon,
    required List<Color> colors,
    VoidCallback? onTap,
  }) {
    return Padding(
      padding: const EdgeInsets.all(6),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: colors,
            ),
            borderRadius: BorderRadius.circular(14),
            boxShadow: [
              BoxShadow(
                color: colors[0].withOpacity(0.35),
                blurRadius: 8,
                offset: const Offset(0, 3),
              ),
            ],
          ),
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, color: Colors.white, size: 22),
              const Spacer(),
              Text(name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 13)),
              const SizedBox(height: 2),
              Text('$count hymn${count == 1 ? '' : 's'}',
                  style: TextStyle(
                      color: Colors.white.withOpacity(0.85), fontSize: 10.5)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildBody() {
    if (_bootstrapping && _items.isEmpty) return const StudentListSkeleton();
    if (_items.isEmpty) {
      final filtering = _searchCtrl.text.trim().isNotEmpty ||
          _categoryId != null ||
          _zemarianId != null;
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

  Widget _flagChip(String label, bool selected, VoidCallback onTap) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
      child: FilterChip(
        label: Text(label, style: const TextStyle(fontSize: 11)),
        selected: selected,
        onSelected: (_) => onTap(),
      ),
    );
  }

}
