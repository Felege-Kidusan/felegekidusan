import 'dart:async';

import 'package:flutter/material.dart';

import '../../services/hymn_store.dart';
import '../../services/sync_service.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
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
///
/// P26 structure (Material top-tabs, Telegram search):
/// - Hymns · Categories · Singers · Add (curators only) as AppBar tabs
///   (no nested bottom navigation — one navigation plane per screen).
/// - ONE search field above the tabs' content: the same query filters
///   whichever tab is open, so the tabs act as Telegram-style
///   result-type filters (Hymns / Categories / Singers).
/// - Zero query -> Spotify-style gradient browse tiles with on-device
///   counts; query active -> ranked result lists with match context.
class MezmurHymnsScreen extends StatefulWidget {
  /// Deep links — open straight into a filtered list (tapped a
  /// category/singer chip on a hymn, or a browse tile).
  const MezmurHymnsScreen(
      {super.key, this.initialCategoryId, this.initialZemarianId});
  final int? initialCategoryId;
  final int? initialZemarianId;
  @override
  State<MezmurHymnsScreen> createState() => MezmurHymnsScreenState();
}

class MezmurHymnsScreenState extends State<MezmurHymnsScreen>
    with SingleTickerProviderStateMixin {
  final _store = HymnStore();
  final _sync = SyncService();
  final _searchCtrl = TextEditingController();

  List<Map<String, dynamic>> _items = [];
  List<Map<String, dynamic>> _categories = [];
  List<Map<String, dynamic>> _zemarians = [];
  List<Map<String, dynamic>> _catResults = const [];
  List<Map<String, dynamic>> _zemResults = const [];
  Map<int, int> _catCounts = {};
  Map<int, int> _zemCounts = {};
  int _totalCount = 0;

  late final TabController _tabCtrl;
  static const int _hymnsTab = 0;
  static const int _categoriesTab = 1;
  static const int _singersTab = 2;
  // Add tab index differs by role; -1 when the role cannot curate.
  late final int _addTab;

  int? _categoryId;
  int? _zemarianId;
  String _length = '';
  String _language = '';
  bool _showArchived = false;
  bool _bootstrapping = true; // skeleton only on the very first open
  int _pending = 0;
  Timer? _searchDebounce;
  StreamSubscription<SyncStatus>? _syncSub;

  /// Telegram/Google keystroke rule (parity with the server + store):
  /// a single character never triggers a search.
  bool get _searching => _searchCtrl.text.trim().length >= 2;

  int get _tab => _tabCtrl.index;

  @override
  void initState() {
    super.initState();
    _categoryId = widget.initialCategoryId;
    _zemarianId = widget.initialZemarianId;
    _addTab = _store.canEdit ? 3 : -1;
    _tabCtrl = TabController(
        length: _store.canEdit ? 4 : 3, vsync: this, initialIndex: 0)
      ..addListener(() {
        if (!_tabCtrl.indexIsChanging) setState(() {}); // hint/flags update
      });
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
    _tabCtrl.dispose();
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
    final query = _searchCtrl.text;
    final items = await _store.hymns(
      search: query,
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
    // Telegram-style unified search: the same query also ranks the
    // category / singer catalogs for their result tabs.
    var catResults = const <Map<String, dynamic>>[];
    var zemResults = const <Map<String, dynamic>>[];
    if (query.trim().length >= 2) {
      catResults = await _store.searchCategories(query);
      zemResults = await _store.searchZemarians(query);
    }
    if (!mounted) return;
    setState(() {
      _items = items;
      _categories = cats;
      _zemarians = zem;
      _catCounts = catCounts;
      _zemCounts = zemCounts;
      _catResults = catResults;
      _zemResults = zemResults;
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
    setState(() {}); // hint / result-mode update, zero latency
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 150), _reload);
  }

  Future<void> _refresh() async {
    await _store.refreshAll();
    await _reload();
  }

  // ── actions (curators only; server re-checks each write) ────

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

  /// Tap a category/singer result (or tile): open the Hymns tab
  /// filtered by it and clear the query (Telegram: tapping a result
  /// opens it; the filter chip header keeps the context clearable).
  void _browseTaxonomy(int id, {required bool singer}) {
    _searchCtrl.clear();
    setState(() {
      if (singer) {
        _zemarianId = id;
        _categoryId = null;
      } else {
        _categoryId = id;
        _zemarianId = null;
      }
    });
    _tabCtrl.animateTo(_hymnsTab);
    _reload();
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
        bottom: TabBar(
          controller: _tabCtrl,
          isScrollable: false,
          indicatorColor: Colors.white,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          labelStyle: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700),
          tabs: [
            const Tab(
                icon: Icon(Icons.music_note_outlined, size: 18),
                text: 'Hymns'),
            const Tab(
                icon: Icon(Icons.grid_view_outlined, size: 18),
                text: 'Categories'),
            const Tab(
                icon: Icon(Icons.person_outline, size: 18),
                text: 'Singers'),
            if (_addTab > 0)
              const Tab(icon: Icon(Icons.add_circle_outline, size: 18), text: 'Add'),
          ],
        ),
      ),
      body: Column(
        children: [
          const OfflineBanner(),
          // One shared search field for the three browse tabs — the
          // tabs act as result-type filters over the same query
          // (Telegram's search mechanism). The Add tab is a form.
          if (_tab != _addTab)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
              child: TextField(
                controller: _searchCtrl,
                onChanged: _onSearchChanged,
                decoration: InputDecoration(
                  hintText: _tab == _hymnsTab
                      ? 'Search hymns — title, Amharic or lyrics…'
                      : _tab == _categoriesTab
                          ? 'Search categories…'
                          : 'Search singers…',
                  hintStyle: const TextStyle(fontSize: 13),
                  prefixIcon: const Icon(Icons.search, size: 18),
                  suffixIcon: _searchCtrl.text.isNotEmpty
                      ? IconButton(
                          icon: const Icon(Icons.clear, size: 16),
                          onPressed: () {
                            _searchCtrl.clear();
                            _reload();
                          },
                        )
                      : null,
                  isDense: true,
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10)),
                ),
              ),
            ),
          // Active-filter header (clearable) — hymns tab only.
          if (_tab == _hymnsTab &&
              (_categoryId != null || _zemarianId != null))
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
          // Quick filters (length / language / archived) — hymns tab.
          if (_tab == _hymnsTab)
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
          Expanded(
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: TabBarView(
                controller: _tabCtrl,
                children: [
                  _buildBody(),
                  _taxonomyTab(categories: true),
                  _taxonomyTab(categories: false),
                  if (_addTab > 0)
                    MezmurHymnEditorScreen(
                      embedded: true,
                      onSaved: () {
                        _tabCtrl.animateTo(_hymnsTab);
                        _reload();
                      },
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  // ── categories / singers tabs ────────────────────────────────
  // Zero query: Spotify-style gradient tiles (browse). Query active:
  // ranked result list (Telegram) — tap a result to open its hymns.

  Widget _taxonomyTab({required bool categories}) {
    if (!_searching) return _browseGrid(categories: categories);
    return _resultList(categories: categories);
  }

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
        onTap: () {
          _searchCtrl.clear();
          setState(() {
            _categoryId = null;
            _zemarianId = null;
          });
          _tabCtrl.animateTo(_hymnsTab);
          _reload();
        },
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
        onTap: () => _browseTaxonomy(id, singer: !categories),
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

  /// Telegram-style ranked results for the category / singer tabs.
  Widget _resultList({required bool categories}) {
    final rows = categories ? _catResults : _zemResults;
    if (rows.isEmpty) {
      return ListView(children: [
        const SizedBox(height: 60),
        EmptyState(
          icon: categories
              ? Icons.category_outlined
              : Icons.person_off_outlined,
          title: categories ? 'No categories match' : 'No singers match',
          subtitle: 'Try a different search.',
        ),
      ]);
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
      cacheExtent: kListCacheExtent,
      itemCount: rows.length,
      itemBuilder: (context, i) {
        final r = rows[i];
        final id = _asInt(r['id']);
        final count =
            categories ? (_catCounts[id] ?? 0) : (_zemCounts[id] ?? 0);
        return RepaintBoundaryListItem(
          child: Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: ListTile(
              onTap: () => _browseTaxonomy(id, singer: !categories),
              leading: CircleAvatar(
                backgroundColor:
                    _palettes[i % _palettes.length][0].withOpacity(0.12),
                child: Icon(
                    categories
                        ? Icons.category_outlined
                        : Icons.person_outline,
                    size: 18,
                    color: _palettes[i % _palettes.length][0]),
              ),
              title: Text('${r['name']}',
                  style: const TextStyle(
                      fontSize: 13.5, fontWeight: FontWeight.w600),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis),
              subtitle: Text(
                '$count hymn${count == 1 ? '' : 's'}'
                '${_asInt(r['is_active']) == 1 ? '' : ' · hidden'}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style:
                    TextStyle(fontSize: 11, color: AppTheme.textSecondary),
              ),
              trailing: const Icon(Icons.chevron_right, size: 18),
            ),
          ),
        );
      },
    );
  }

  // ── hymns tab ────────────────────────────────────────────────

  Widget _buildBody() {
    if (_bootstrapping && _items.isEmpty) return const StudentListSkeleton();
    if (_items.isEmpty) {
      final filtering = _searching || _categoryId != null || _zemarianId != null;
      return ListView(children: [
        const SizedBox(height: 60),
        EmptyState(
          icon: Icons.music_off_outlined,
          title: filtering ? 'No hymns match' : 'No hymns yet',
          subtitle: filtering
              ? 'Try a different search — or check the Categories and Singers tabs.'
              : (_store.canEdit
                  ? 'Open the Add tab to start the library — works offline.'
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
        final lyricMatch = _searching && '${h['match_in'] ?? ''}' == 'lyrics';
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
                subtitle: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${h['title_am'] ?? ''}'
                      '${'${h['title_am'] ?? ''}'.isNotEmpty && '${h['category'] ?? ''}'.isNotEmpty ? ' · ' : ''}'
                      '${h['category'] ?? ''}'
                      '${!hasLyrics ? ' · lyrics downloading…' : ''}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                          fontSize: 11, color: AppTheme.textSecondary),
                    ),
                    // P25/P26: lyrics matches carry a "Lyrics" tag and the
                    // matching line (Spotify: bold title, grey context).
                    if (lyricMatch) ...[
                      const SizedBox(height: 3),
                      Row(children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 5, vertical: 1),
                          decoration: BoxDecoration(
                            color: AppTheme.primary.withOpacity(0.10),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text('LYRICS',
                              style: TextStyle(
                                  fontSize: 8.5,
                                  fontWeight: FontWeight.w800,
                                  letterSpacing: 0.5,
                                  color: AppTheme.primary)),
                        ),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            '${h['snippet'] ?? ''}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                                fontSize: 10.5,
                                fontStyle: FontStyle.italic,
                                color: AppTheme.textSecondary),
                          ),
                        ),
                      ]),
                    ],
                  ],
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
