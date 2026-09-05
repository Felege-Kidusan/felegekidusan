import 'dart:async';

import 'package:flutter/material.dart';

import '../../services/hymn_store.dart';
import '../../utils/config.dart';
import '../../utils/cover_palette.dart';
import '../../services/sync_service.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/highlighted_text.dart';
import '../../services/lyrics_search.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/offline_banner.dart';
import '../../widgets/taxonomy_pick_sheet.dart';
import '../../widgets/download_button.dart';
import '../../services/mezmur_download_manager.dart';
import 'mezmur_categories.dart';
import 'mezmur_player_screen.dart';
import 'mezmur_hymn_detail.dart';
import 'mezmur_category_screen.dart';
import 'mezmur_hymn_editor.dart';
import 'mezmur_zemarians.dart';
import 'mezmur_downloads.dart';
import 'mezmur_download_settings.dart';

/// Hymn library — LOCAL-FIRST (local-first model).
///
/// Every read hits the on-device SQLite copy: opening the screen and
/// searching are instant and work with the radio off. Writes (add,
/// edit, archive, categories) are optimistic + queued; the sync
/// engine pushes them with idempotency keys and pulls a delta of
/// server changes via a change-token cursor.
///
/// P26 structure (Material top-tabs, unified search):
/// - Hymns · Categories · Singers · Add (curators only) as AppBar tabs
///   (no nested bottom navigation — one navigation plane per screen).
/// - ONE search field above the tabs' content: the same query filters
///   whichever tab is open, so the tabs act as ranked-result
///   result-type filters (Hymns / Categories / Singers).
/// - Zero query -> cover-tile gradient browse tiles with on-device
///   counts; query active -> ranked result lists with match context.
class MezmurHymnsScreen extends StatefulWidget {
  /// Deep links — open straight into a filtered list (tapped a
  /// category/singer chip on a hymn, or a browse tile).
  const MezmurHymnsScreen(
      {super.key, this.initialCategoryId, this.initialZemarianId, this.onBack});
  final int? initialCategoryId;
  final int? initialZemarianId;

  /// When the library is hosted inside the app shell (not pushed as a
  /// route), the shell hides the main bottom bar and hands us a way
  /// back to the home screen (P31: one bottom bar at a time).
  final VoidCallback? onBack;
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

  /// P38: monotonic search generation. The old guard compared the query
  /// TEXT, which silently fails whenever the text returns to a previous
  /// value (type "sela", backspace to "sel", retype "sela") — two loads
  /// are then in flight for the same string and the slower one wins.
  /// A counter cannot collide.
  int _searchGeneration = 0;
  StreamSubscription<SyncStatus>? _syncSub;

  /// Keystroke rule (parity with the server + store):
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
    // P38: verify the search index whenever this screen opens. Cheap
    // when clean (one metadata read); repairs dirty rows or rebuilds
    // outright when the analyzer version moved. Unawaited so it never
    // delays first paint.
    unawaited(_store.ensureSearchIndexFresh().catchError((_) {}));
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
    final generation = ++_searchGeneration;
    final query = _searchCtrl.text;
    final searching = query.trim().length >= 2;
    // P27: while searching, the store merges the on-device index with
    // the SERVER word index (lyrics blobs are lazily downloaded, so
    // the local copy alone cannot see most lyrics yet).
    final items = searching
        ? await _store.searchHymnsUnified(
            query,
            categoryId: _categoryId,
            includeArchived: _showArchived,
            length: _length.isEmpty ? null : _length,
            language: _language.isEmpty ? null : _language,
            zemarianId: _zemarianId,
          )
        : await _store.hymns(
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
    // ranked-result unified search: the same query also ranks the
    // category / singer catalogs for their result tabs.
    var catResults = const <Map<String, dynamic>>[];
    var zemResults = const <Map<String, dynamic>>[];
    if (query.trim().length >= 2) {
      catResults = await _store.searchCategories(query);
      zemResults = await _store.searchZemarians(query);
    }
    if (!mounted) return;
    // Stale guard (P27, hardened P38): the unified search round-trips
    // the server; if a newer load started while we were awaiting, the
    // slower response must never clobber it.
    if (generation != _searchGeneration) return;
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
    // 180ms: long enough to coalesce a typing burst, short enough that
    // the field never feels unresponsive (research consensus is
    // 150-250ms for search-as-you-type).
    _searchDebounce = Timer(const Duration(milliseconds: 180), _reload);
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

  /// P33 — download sheet: bulk-pin exactly what the user is looking at
  /// (search results / filtered category), or manage what is already
  /// stored. Mirrors Spotify's "Download" toggle on a playlist header.
  Future<void> _openDownloads() async {
    final dl = MezmurDownloadManager.instance;
    final withAudio = _items
        .where((h) => '${h['audio_status'] ?? ''}' == 'ready')
        .toList(growable: false);
    final notYet = withAudio
        .where((h) => !dl.isDownloaded(_asInt(h['id'])))
        .toList(growable: false);
    if (!mounted) return;
    await showModalBottomSheet<void>(
      context: context,
      builder: (sheetCtx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const SizedBox(height: 8),
          ListTile(
            dense: true,
            leading: const Icon(Icons.sd_storage_outlined, size: 20),
            title: Text(
                '${dl.downloadedCount} hymns offline · '
                '${MezmurDownloadManager.formatBytes(dl.bytesOnDisk)}',
                style: const TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w700)),
            subtitle: Text(
                dl.wifiOnly ? 'Downloading on Wi‑Fi only' : 'Mobile data allowed',
                style: const TextStyle(fontSize: 11)),
          ),
          const Divider(height: 1),
          ListTile(
            dense: true,
            enabled: notYet.isNotEmpty,
            leading: const Icon(Icons.download_rounded,
                size: 20, color: AppTheme.success),
            title: Text(
                notYet.isEmpty
                    ? 'Everything here is already downloaded'
                    : 'Download these ${notYet.length} hymns',
                style: const TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w600)),
            subtitle: Text(
                notYet.isEmpty
                    ? 'This view plays fully offline.'
                    : 'Queues the hymns currently listed, including your search '
                        'and filters.',
                style: const TextStyle(fontSize: 11)),
            onTap: notYet.isEmpty
                ? null
                : () async {
                    Navigator.pop(sheetCtx);
                    final n = await dl.downloadAll(notYet);
                    if (!mounted) return;
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                      content: Text(dl.waitingForWifi
                          ? '$n queued — will start on Wi‑Fi'
                          : '$n hymns queued for download'),
                      duration: const Duration(seconds: 2),
                    ));
                    setState(() {});
                  },
          ),
          ListTile(
            dense: true,
            leading: const Icon(Icons.library_music_outlined, size: 20),
            title: const Text('Go to Downloads',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
            subtitle: const Text('Play and manage what is on this phone.',
                style: TextStyle(fontSize: 11)),
            onTap: () {
              Navigator.pop(sheetCtx);
              Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => const MezmurDownloadsScreen()));
            },
          ),
          ListTile(
            dense: true,
            leading: const Icon(Icons.settings_outlined, size: 20),
            title: const Text('Download settings',
                style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
            subtitle: const Text(
                'Storage limit, mobile data, pause the queue.',
                style: TextStyle(fontSize: 11)),
            onTap: () {
              Navigator.pop(sheetCtx);
              Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => const MezmurDownloadSettingsScreen()));
            },
          ),
          const SizedBox(height: 8),
        ]),
      ),
    );
    if (mounted) setState(() {});
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
  /// filtered by it and clear the query (unified search: tapping a result
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

  /// P37: ranges travel from the ranker as objects; guard the cast so a
  /// server row without them renders plain instead of throwing.
  List<HighlightRange> _rangesOf(dynamic v) =>
      v is List<HighlightRange> ? v : const <HighlightRange>[];

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  // ── P0 audio: one tap opens the parchment player for ANY hymn in
  //    the current view (audio optional — lyrics still show). ────────
  void _openPlayer(Map<String, dynamic> h) {
    MezmurPlayerScreen.openFromRows(
      context,
      rows: _items,
      hymnId: _asInt(h['id']),
    );
  }

  Future<void> _openDetails(Map<String, dynamic> h) async {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => MezmurHymnDetailScreen(id: _asInt(h['id'])),
    ));
    await _reload();
  }

  // ── build ───────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Hymn Library · መዝሙር'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        leading: widget.onBack == null
            ? null
            : IconButton(
                tooltip: 'Back to home',
                icon: const Icon(Icons.arrow_back, size: 20),
                onPressed: widget.onBack,
              ),
        actions: [
          // P33: offline downloads — bulk-download the current view and
          // reach the storage manager.
          IconButton(
            tooltip: 'Downloads',
            icon: const Icon(Icons.download_for_offline_outlined, size: 20),
            onPressed: _openDownloads,
          ),
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
      // P30 (item 6): the section's own navigation lives at the BOTTOM —
      // this screen is pushed full-screen, so the main app bar is out of
      // the way and the hymn library owns the bottom position.
      bottomNavigationBar: NavigationBar(
        height: 64,
        selectedIndex: _tab.clamp(0, _addTab > 0 ? 3 : 2),
        onDestinationSelected: (i) => _tabCtrl.animateTo(i),
        destinations: [
          const NavigationDestination(
              icon: Icon(Icons.music_note_outlined),
              selectedIcon: Icon(Icons.music_note),
              label: 'Hymns'),
          const NavigationDestination(
              icon: Icon(Icons.grid_view_outlined),
              selectedIcon: Icon(Icons.grid_view),
              label: 'Categories'),
          const NavigationDestination(
              icon: Icon(Icons.person_outline),
              selectedIcon: Icon(Icons.person),
              label: 'Singers'),
          if (_addTab > 0)
            const NavigationDestination(
                icon: Icon(Icons.add_circle_outline),
                selectedIcon: Icon(Icons.add_circle),
                label: 'Add'),
        ],
      ),
      body: Column(
        children: [
          const OfflineBanner(),
          // One shared search field for the three browse tabs — the
          // tabs act as result-type filters over the same query
          // (the unified-search pattern). The Add tab is a form.
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
          // Active-filter header (clearable) — hymns tab only. Every
          // active filter stays visible with its own clear (UX: active
          // chips must always be in sight, never buried in the sheet).
          if (_tab == _hymnsTab &&
              (_categoryId != null ||
                  _zemarianId != null ||
                  _length.isNotEmpty ||
                  _language.isNotEmpty))
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
                  if (_length.isNotEmpty)
                    InputChip(
                      avatar: const Icon(Icons.timeline,
                          size: 13, color: AppTheme.success),
                      label: Text(
                          _length == 'long' ? 'Long' : 'Short',
                          style: const TextStyle(fontSize: 11)),
                      onDeleted: () {
                        setState(() => _length = '');
                        _reload();
                      },
                    ),
                  if (_language.isNotEmpty)
                    InputChip(
                      avatar: const Icon(Icons.language,
                          size: 13, color: AppTheme.warning),
                      label: Text(
                          _language == 'geez' ? 'Geez (ግዕዝ)' : 'Amharic',
                          style: const TextStyle(fontSize: 11)),
                      onDeleted: () {
                        setState(() => _language = '');
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
                  // P28 (item 5): full filter sheet — category + singer
                  // pickers live here (chips above stay for one-tap
                  // toggles). The dot marks filters only the sheet can
                  // clear (taxonomy picks).
                  Padding(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 4, vertical: 6),
                    child: FilterChip(
                      avatar: Badge(
                        isLabelVisible:
                            _categoryId != null || _zemarianId != null,
                        smallSize: 5,
                        child: const Icon(Icons.tune, size: 13),
                      ),
                      label: const Text('Filters',
                          style: TextStyle(fontSize: 11)),
                      selected:
                          _categoryId != null || _zemarianId != null,
                      onSelected: (_) => _openFilterSheet(),
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
  // Zero query: cover-tile gradient tiles (browse). Query active:
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
    // P30 (items 4+5): the grid shows MAIN categories with cover
    // images; a tap opens the category's own full screen (its subs
    // and hymns live there).
    final rows = categories
        ? _categories.where((c) => c['parent_id'] == null).toList()
        : _zemarians;
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
      final img = '${r['image_url'] ?? ''}'; // singers carry covers too (P34)
      tiles.add(_tile(
        '${r['name']}',
        count,
        icon: categories ? Icons.category_outlined : Icons.person_outline,
        colors: categories
            ? coverColors(r, '${r['name']}') // pinned gradient wins
            : _palettes[i % _palettes.length],
        imageUrl: img.isEmpty ? null : img,
        onTap: () {
          if (categories) {
            Navigator.of(context).push(MaterialPageRoute(
              builder: (_) => MezmurCategoryScreen(
                categoryId: id,
                name: '${r['name']}',
                imageUrl: img.isEmpty ? null : img,
                gradientStart: (r['gradient_start'] ?? '').toString().isEmpty ? null : r['gradient_start'].toString(),
                gradientEnd: (r['gradient_end'] ?? '').toString().isEmpty ? null : r['gradient_end'].toString(),
              ),
            ));
          } else {
            _browseTaxonomy(id, singer: true);
          }
        },
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
    String? imageUrl,
  }) {
    return Padding(
      padding: const EdgeInsets.all(6),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Container(
          decoration: BoxDecoration(
            // P30 (item 5): uploaded cover under a dark scrim; the
            // signature gradient shows through while it loads (and
            // forever, for categories without a cover).
            image: imageUrl != null && imageUrl!.isNotEmpty
                ? DecorationImage(
                    image: NetworkImage(
                        '${AppConfig.apiBaseUrl.replaceFirst(RegExp(r'/api/v1$'), '')}$imageUrl'),
                    fit: BoxFit.cover,
                    colorFilter: ColorFilter.mode(
                        Colors.black45, BlendMode.darken))
                : null,
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

  /// ranked-result ranked results for the category / singer tabs.
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
              onTap: () {
                if (!categories) {
                  _browseTaxonomy(id, singer: true);
                } else if (r['parent_id'] == null) {
                  final img = (r['image_url'] ?? '').toString();
                  Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => MezmurCategoryScreen(
                      categoryId: id,
                      name: (r['name'] ?? '').toString(),
                      imageUrl: img.isEmpty ? null : img,
                      gradientStart: (r['gradient_start'] ?? '').toString().isEmpty ? null : r['gradient_start'].toString(),
                      gradientEnd: (r['gradient_end'] ?? '').toString().isEmpty ? null : r['gradient_end'].toString(),
                    ),
                  ));
                } else {
                  final img = (r['image_url'] ?? '').toString();
                  Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => MezmurSubListScreen(
                      categoryId: id,
                      name: (r['name'] ?? '').toString(),
                      imageUrl: img.isEmpty ? null : img,
                      gradientStart: (r['gradient_start'] ?? '').toString().isEmpty ? null : r['gradient_start'].toString(),
                      gradientEnd: (r['gradient_end'] ?? '').toString().isEmpty ? null : r['gradient_end'].toString(),
                    ),
                  ));
                }
              },
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
                onTap: () => _openPlayer(h),
                leading: CircleAvatar(
                  backgroundColor: AppTheme.primary.withOpacity(0.1),
                  child: Icon(
                      archived ? Icons.archive_outlined : Icons.music_note,
                      size: 18,
                      color: AppTheme.primary),
                ),
                // P37: highlight the matched part of the title too —
                // Telegram emphasises the hit wherever it occurs, which
                // is what makes a long result list scannable.
                title: HighlightedText(
                    text: '${h['title']}',
                    ranges: _rangesOf(h['title_ranges']),
                    style: const TextStyle(
                        fontSize: 13.5, fontWeight: FontWeight.w600),
                    highlightStyle: TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w800,
                        color: AppTheme.primary),
                    maxLines: 1),
                subtitle: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      // P33: at-a-glance "this one plays with no signal".
                      if (MezmurDownloadManager.instance
                          .isDownloaded(_asInt(h['id']))) ...[
                        const OfflineBadge(),
                        const SizedBox(width: 6),
                      ],
                      Expanded(
                        child: Text(
                          '${h['category'] ?? ''}'
                          '${!hasLyrics ? ' · lyrics downloading…' : ''}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                              fontSize: 11, color: AppTheme.textSecondary),
                        ),
                      ),
                    ]),
                    // P25/P26: lyrics matches carry a "Lyrics" tag and the
                    // matching line (bold title, grey context).
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
                          // P37: the matched words are painted in the
                          // accent colour, Telegram-style, using ranges
                          // the ranker already computed.
                          child: HighlightedText(
                            text: '${h['snippet'] ?? ''}',
                            ranges: _rangesOf(h['snippet_ranges']),
                            ellipsisBefore: h['snippet_before'] == true,
                            ellipsisAfter: h['snippet_after'] == true,
                            maxLines: 1,
                            style: TextStyle(
                                fontSize: 10.5,
                                fontStyle: FontStyle.italic,
                                color: AppTheme.textSecondary),
                            highlightStyle: TextStyle(
                                fontSize: 10.5,
                                fontStyle: FontStyle.italic,
                                fontWeight: FontWeight.w800,
                                color: AppTheme.primary),
                          ),
                        ),
                      ]),
                    ],
                  ],
                ),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  // P33: Spotify-style offline download, right where the
                  // hymn is — one tap, no menu digging.
                  HymnDownloadButton(hymn: h, size: 19),
                  PopupMenuButton<String>(
                  icon: const Icon(Icons.more_vert, size: 18),
                  onSelected: (v) async {
                    if (v == 'details') await _openDetails(h);
                    if (v == 'edit') await _editHymn(h);
                    if (v == 'archive') await _toggleArchive(h);
                  },
                  itemBuilder: (_) => [
                    const PopupMenuItem(
                        value: 'details',
                        height: 36,
                        child: Row(children: [
                          Icon(Icons.info_outline, size: 16),
                          SizedBox(width: 8),
                          Text('Details', style: TextStyle(fontSize: 12.5))
                        ])),
                    if (_store.canEdit) ...[
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
                  ],
                ),
                ]),
              ),
            ),
          ),
        );
      },
    );
  }

  /// Mains first, each followed by its subs (indented) — the filter
  /// sheet mirrors the two-level taxonomy; picking a MAIN rolls up.
  List<Map<String, dynamic>> _filterCategoryItems() {
    final mains = _categories.where((c) => c['parent_id'] == null).toList();
    final out = <Map<String, dynamic>>[];
    for (final m in mains) {
      out.add(m);
      final mid = _asInt(m['id']);
      out.addAll(_categories
          .where((c) =>
              c['parent_id'] != null && _asInt(c['parent_id']) == mid)
          .map((c) => {...c, 'name': '— ${c['name']}'}));
    }
    return out;
  }

  // ── P28 (item 5): the filter sheet ──────────────────────────
  // Full-screen-context filters: taxonomy single-picks, length,
  // language, archived. The Apply button carries the LIVE result count
  // ("Show 47 hymns" — uxpin), a zero count warns instead of dead-
  // ending (insaim), and Clear all resets every filter at once.
  Future<void> _openFilterSheet() async {
    var dCategoryId = _categoryId;
    var dZemarianId = _zemarianId;
    var dLength = _length;
    var dLanguage = _language;
    var dArchived = _showArchived;
    var count = -1; // -1 = still counting
    var searchGeneration = 0;

    Future<void> recount(StateSetter setSheet) async {
      final gen = ++searchGeneration;
      final n = await _store.countHymns(
          length: dLength.isEmpty ? null : dLength,
          language: dLanguage.isEmpty ? null : dLanguage,
          categoryId: dCategoryId,
          zemarianId: dZemarianId,
          includeArchived: dArchived);
      if (gen != searchGeneration) return; // a newer draft is counting
      setSheet(() => count = n);
    }

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) {
          void changed(void Function() fn) {
            setSheet(fn);
            recount(setSheet);
          }

          return SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    const Icon(Icons.tune, size: 16),
                    const SizedBox(width: 6),
                    const Expanded(
                      child: Text('Filter hymns',
                          style: TextStyle(
                              fontSize: 14, fontWeight: FontWeight.w700)),
                    ),
                    TextButton(
                      onPressed: () => changed(() {
                        dCategoryId = null;
                        dZemarianId = null;
                        dLength = '';
                        dLanguage = '';
                        dArchived = false;
                      }),
                      child: const Text('CLEAR ALL'),
                    ),
                  ]),
                  const SizedBox(height: 4),
                  TaxonomyPickField(
                    label: 'Category',
                    items: _filterCategoryItems(),
                    selected: dCategoryId == null ? {} : {dCategoryId!},
                    icon: Icons.category_outlined,
                    single: true,
                    counts: _catCounts,
                    onChanged: (sel) => changed(
                        () => dCategoryId = sel.isEmpty ? null : sel.first),
                  ),
                  const SizedBox(height: 12),
                  TaxonomyPickField(
                    label: 'Singer / Zemarian',
                    items: _zemarians,
                    selected: dZemarianId == null ? {} : {dZemarianId!},
                    icon: Icons.person_outline,
                    single: true,
                    counts: _zemCounts,
                    onChanged: (sel) => changed(
                        () => dZemarianId = sel.isEmpty ? null : sel.first),
                  ),
                  const SizedBox(height: 14),
                  const Text('Length',
                      style:
                          TextStyle(fontSize: 12.5, color: AppTheme.textSecondary)),
                  const SizedBox(height: 6),
                  Wrap(spacing: 6, children: [
                    for (final v in ['', 'long', 'short'])
                      ChoiceChip(
                        label: Text(
                            v.isEmpty ? 'Any' : (v == 'long' ? 'Long' : 'Short'),
                            style: const TextStyle(fontSize: 11.5)),
                        selected: dLength == v,
                        onSelected: (_) => changed(() => dLength = v),
                      ),
                  ]),
                  const SizedBox(height: 12),
                  const Text('Language',
                      style:
                          TextStyle(fontSize: 12.5, color: AppTheme.textSecondary)),
                  const SizedBox(height: 6),
                  Wrap(spacing: 6, children: [
                    for (final v in ['', 'amharic', 'geez'])
                      ChoiceChip(
                        label: Text(
                            v.isEmpty
                                ? 'Any'
                                : (v == 'geez' ? 'Geez (ግዕዝ)' : 'Amharic (አማርኛ)'),
                            style: const TextStyle(fontSize: 11.5)),
                        selected: dLanguage == v,
                        onSelected: (_) => changed(() => dLanguage = v),
                      ),
                  ]),
                  if (_store.canEdit) ...[
                    const SizedBox(height: 12),
                    SwitchListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      title: const Text('Show archived hymns',
                          style: TextStyle(fontSize: 13)),
                      value: dArchived,
                      onChanged: (v) => changed(() => dArchived = v),
                    ),
                  ],
                  const SizedBox(height: 10),
                  // Live count: on Apply when there are results, a
                  // friendly warning when there are none (never a dead
                  // end — Clear all is one tap away).
                  if (count == 0)
                    const Padding(
                      padding: EdgeInsets.only(bottom: 8),
                      child: Row(children: [
                        Icon(Icons.info_outline,
                            size: 14, color: AppTheme.warning),
                        SizedBox(width: 6),
                        Expanded(
                          child: Text(
                              'No hymns match these filters yet — clear one or tap Clear all.',
                              style: TextStyle(
                                  fontSize: 11.5,
                                  color: AppTheme.textSecondary)),
                        ),
                      ]),
                    ),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppTheme.primary,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 13),
                      ),
                      onPressed: () {
                        Navigator.of(ctx).pop();
                        setState(() {
                          _categoryId = dCategoryId;
                          _zemarianId = dZemarianId;
                          _length = dLength;
                          _language = dLanguage;
                          _showArchived = dArchived;
                        });
                        _reload();
                      },
                      icon: const Icon(Icons.check, size: 18),
                      label: Text(count < 0
                          ? 'Apply filters'
                          : 'Show $count hymn${count == 1 ? '' : 's'}'),
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
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
