import 'package:flutter/material.dart';

import '../../services/hymn_store.dart';
import '../../utils/config.dart';
import '../../utils/cover_palette.dart';
import '../../utils/theme.dart';
import 'mezmur_hymn_detail.dart';

/// P31: the category browse drill-down, three visual levels.
///
/// MAIN category -> this screen: a stylized header (cover image or
/// signature gradient under a dark scrim, the name set large in
/// white) and a grid of SUB-CATEGORY boxes. The sub boxes share the
/// category-box design but are deliberately SHORTER, so the level
/// difference is obvious at a glance. An "All hymns" box leads the
/// grid.
///
/// SUB category / All -> [MezmurSubListScreen]: a compact header with
/// the sub's own name, then the hymn list — the leaf of the drill.
class MezmurCategoryScreen extends StatefulWidget {
  const MezmurCategoryScreen({
    super.key,
    required this.categoryId,
    required this.name,
    this.imageUrl,
    this.gradientStart,
    this.gradientEnd,
  });

  final int categoryId;
  final String name;
  final String? imageUrl;

  /// Admin-pinned cover gradient (shows when no image is set).
  final String? gradientStart;
  final String? gradientEnd;

  @override
  State<MezmurCategoryScreen> createState() => _MezmurCategoryScreenState();
}

class _MezmurCategoryScreenState extends State<MezmurCategoryScreen> {
  final _store = HymnStore();
  List<Map<String, dynamic>> _subs = const [];
  List<Map<String, dynamic>> _hymns = const [];
  Map<int, int> _counts = {};
  bool _loading = true;

  List<Color> get _headerColors => coverColors(
      {'gradient_start': widget.gradientStart,
       'gradient_end': widget.gradientEnd},
      widget.name);

  List<Color> gradientFor(String name) => coverColors(null, name);

  @override
  void initState() {
    super.initState();
    _load();
    _store.addListener(_load);
  }

  @override
  void dispose() {
    _store.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    final cats = await _store.categories();
    final subs = cats
        .where((c) =>
            c['parent_id'] != null &&
            _asInt(c['parent_id']) == widget.categoryId)
        .toList();
    final counts = await _store.categoryHymnCounts();
    // With subs the grid replaces the inline list; without subs the
    // main itself is the leaf, so its hymns list right here.
    final hymns =
        subs.isEmpty ? await _store.hymns(categoryId: widget.categoryId) : const <Map<String, dynamic>>[];
    if (!mounted) return;
    setState(() {
      _subs = subs;
      _counts = counts;
      _hymns = hymns;
      _loading = false;
    });
  }

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  String _img(String url) =>
      '${AppConfig.apiBaseUrl.replaceFirst(RegExp(r'/api/v1$'), '')}$url';

  void _openList({
    required int id,
    required String name,
    String? imageUrl,
  }) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) =>
          MezmurSubListScreen(categoryId: id, name: name, imageUrl: imageUrl),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final rolledUp = _counts[widget.categoryId] ?? 0;
    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      body: Column(
        children: [
          // ── stylized header: cover (or gradient) + scrim + title ──
          Stack(
            children: [
              SizedBox(
                height: 148,
                width: double.infinity,
                child: widget.imageUrl != null && widget.imageUrl!.isNotEmpty
                    ? Image.network(
                        _img(widget.imageUrl!),
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => DecoratedBox(
                          decoration: BoxDecoration(
                              gradient: LinearGradient(
                                  begin: Alignment.topLeft,
                                  end: Alignment.bottomRight,
                                  colors: gradientFor(widget.name))),
                        ),
                      )
                    : DecoratedBox(
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: _headerColors),
                        ),
                      ),
              ),
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        Colors.black.withOpacity(0.25),
                        Colors.black.withOpacity(0.62),
                      ],
                    ),
                  ),
                ),
              ),
              SafeArea(
                bottom: false,
                child: Row(
                  children: [
                    IconButton(
                      tooltip: 'Back',
                      icon: const Icon(Icons.arrow_back, color: Colors.white),
                      onPressed: () => Navigator.of(context).pop(),
                    ),
                  ],
                ),
              ),
              Positioned(
                left: 18,
                bottom: 14,
                right: 18,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(widget.name,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontSize: 24,
                            fontWeight: FontWeight.w800,
                            color: Colors.white,
                            height: 1.15)),
                    const SizedBox(height: 4),
                    Text(
                      '$rolledUp hymn${rolledUp == 1 ? '' : 's'}'
                      '${_subs.isNotEmpty ? ' · ${_subs.length} sub-categor${_subs.length == 1 ? 'y' : 'ies'}' : ''}',
                      style: TextStyle(
                          fontSize: 12.5,
                          color: Colors.white.withOpacity(0.85),
                          fontWeight: FontWeight.w600),
                    ),
                  ],
                ),
              ),
            ],
          ),
          // ── body: sub boxes (smaller than category boxes) or, when
          //    the main has no subs, its hymn list directly ──
          Expanded(
            child: _loading
                ? const Center(
                    child: CircularProgressIndicator(strokeWidth: 2))
                : _subs.isNotEmpty
                    ? GridView.count(
                        crossAxisCount: 2,
                        // Category boxes on the browse tab sit at 1.45;
                        // 2.2 makes these clearly the "smaller" level.
                        childAspectRatio: 2.2,
                        padding: const EdgeInsets.fromLTRB(10, 14, 10, 24),
                        mainAxisSpacing: 4,
                        crossAxisSpacing: 4,
                        children: [
                          _subBox(
                            name: 'All hymns',
                            count: rolledUp,
                            icon: Icons.library_music_outlined,
                            colors: _headerColors,
                            imageUrl: widget.imageUrl,
                            onTap: () => _openList(
                                id: widget.categoryId,
                                name: widget.name,
                                imageUrl: widget.imageUrl),
                          ),
                          for (final sub in _subs)
                            _subBox(
                              name: '${sub['name'] ?? ''}',
                              count: _counts[_asInt(sub['id'])] ?? 0,
                              icon: Icons.category_outlined,
                              colors: coverColors(sub, '${sub['name'] ?? ''}'),
                              imageUrl:
                                  ('${sub['image_url'] ?? ''}').isEmpty
                                      ? null
                                      : '${sub['image_url']}',
                              onTap: () => _openList(
                                  id: _asInt(sub['id']),
                                  name: '${sub['name'] ?? ''}',
                                  imageUrl:
                                      ('${sub['image_url'] ?? ''}').isEmpty
                                          ? null
                                          : '${sub['image_url']}'),
                            ),
                        ],
                      )
                    : hymnList(_hymns, _load),
          ),
        ],
      ),
    );
  }

  /// A sub-category box: same cover/scrim treatment as the category
  /// boxes on the browse tab, at a smaller height.
  Widget _subBox({
    required String name,
    required int count,
    required IconData icon,
    required List<Color> colors,
    String? imageUrl,
    VoidCallback? onTap,
  }) {
    return Padding(
      padding: const EdgeInsets.all(6),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Container(
          decoration: BoxDecoration(
            image: imageUrl != null && imageUrl.isNotEmpty
                ? DecorationImage(
                    image: NetworkImage(_img(imageUrl)),
                    fit: BoxFit.cover,
                    colorFilter: ColorFilter.mode(
                        Colors.black45, BlendMode.darken))
                : null,
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: colors,
            ),
            borderRadius: BorderRadius.circular(12),
            boxShadow: [
              BoxShadow(
                color: colors[0].withOpacity(0.3),
                blurRadius: 6,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          padding: const EdgeInsets.all(10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, color: Colors.white, size: 17),
              const Spacer(),
              Text(name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 12)),
              const SizedBox(height: 1),
              Text('$count hymn${count == 1 ? '' : 's'}',
                  style: TextStyle(
                      color: Colors.white.withOpacity(0.85), fontSize: 9.5)),
            ],
          ),
        ),
      ),
    );
  }
}

/// The leaf of the drill-down: the hymn list under one name — a sub
/// category, or a whole main via its "All hymns" box. Compact header
/// (smaller than the category header) carrying the selected name.
class MezmurSubListScreen extends StatefulWidget {
  const MezmurSubListScreen({
    super.key,
    required this.categoryId,
    required this.name,
    this.imageUrl,
    this.gradientStart,
    this.gradientEnd,
  });

  final int categoryId;
  final String name;
  final String? imageUrl;
  final String? gradientStart;
  final String? gradientEnd;

  @override
  State<MezmurSubListScreen> createState() => _MezmurSubListScreenState();
}

class _MezmurSubListScreenState extends State<MezmurSubListScreen> {
  final _store = HymnStore();
  List<Map<String, dynamic>> _hymns = const [];
  Map<int, int> _counts = {};
  bool _loading = true;

  List<Color> get _gradient => coverColors(
      {'gradient_start': widget.gradientStart,
       'gradient_end': widget.gradientEnd},
      widget.name);

  @override
  void initState() {
    super.initState();
    _load();
    _store.addListener(_load);
  }

  @override
  void dispose() {
    _store.removeListener(_load);
    super.dispose();
  }

  Future<void> _load() async {
    final hymns = await _store.hymns(categoryId: widget.categoryId);
    final counts = await _store.categoryHymnCounts();
    if (!mounted) return;
    setState(() {
      _hymns = hymns;
      _counts = counts;
      _loading = false;
    });
  }

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  @override
  Widget build(BuildContext context) {
    final count = _counts[widget.categoryId] ?? _hymns.length;
    final img = widget.imageUrl ?? '';
    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      body: Column(
        children: [
          // ── compact header: the selected name over its cover ──
          Stack(
            children: [
              SizedBox(
                height: 104,
                width: double.infinity,
                child: img.isNotEmpty
                    ? Image.network(
                        '${AppConfig.apiBaseUrl.replaceFirst(RegExp(r'/api/v1$'), '')}$img',
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => DecoratedBox(
                          decoration: BoxDecoration(
                              gradient: LinearGradient(
                                  begin: Alignment.topLeft,
                                  end: Alignment.bottomRight,
                                  colors: _gradient)),
                        ),
                      )
                    : DecoratedBox(
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: _gradient),
                        ),
                      ),
              ),
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        Colors.black.withOpacity(0.25),
                        Colors.black.withOpacity(0.6),
                      ],
                    ),
                  ),
                ),
              ),
              SafeArea(
                bottom: false,
                child: Row(
                  children: [
                    IconButton(
                      tooltip: 'Back',
                      icon: const Icon(Icons.arrow_back, color: Colors.white),
                      onPressed: () => Navigator.of(context).pop(),
                    ),
                  ],
                ),
              ),
              Positioned(
                left: 18,
                bottom: 12,
                right: 18,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(widget.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.w800,
                            color: Colors.white)),
                    const SizedBox(height: 3),
                    Text(
                        '$count hymn${count == 1 ? '' : 's'} · tap to open',
                        style: TextStyle(
                            fontSize: 11.5,
                            color: Colors.white.withOpacity(0.85),
                            fontWeight: FontWeight.w600)),
                  ],
                ),
              ),
            ],
          ),
          Expanded(
            child: _loading
                ? const Center(
                    child: CircularProgressIndicator(strokeWidth: 2))
                : hymnList(_hymns, _load),
          ),
        ],
      ),
    );
  }
}

/// Shared hymn list used by both levels of the drill-down.
Widget hymnList(List<Map<String, dynamic>> hymns, VoidCallback reload) {
  if (hymns.isEmpty) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.music_off_outlined,
              size: 40, color: AppTheme.textSecondary),
          const SizedBox(height: 8),
          Text(
            'No hymns here yet.',
            style:
                TextStyle(fontSize: 13, color: AppTheme.textSecondary),
          ),
        ],
      ),
    );
  }
  return ListView.builder(
    padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
    itemCount: hymns.length,
    itemBuilder: (context, i) {
      final h = hymns[i];
      final archived = '${h['status']}' == 'archived';
      int asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;
      return Card(
        margin: const EdgeInsets.only(bottom: 8),
        child: Opacity(
          opacity: archived ? 0.55 : 1,
          child: ListTile(
            onTap: () async {
              await Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => MezmurHymnDetailScreen(id: asInt(h['id'])),
              ));
              reload();
            },
            leading: CircleAvatar(
              backgroundColor: AppTheme.primary.withOpacity(0.1),
              child: Icon(
                  archived ? Icons.archive_outlined : Icons.music_note,
                  size: 18,
                  color: AppTheme.primary),
            ),
            title: Text('${h['title'] ?? ''}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                    fontSize: 13.5, fontWeight: FontWeight.w600)),
            subtitle: '${h['category'] ?? ''}'.isEmpty
                ? null
                : Text('${h['category']}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                        fontSize: 11, color: AppTheme.textSecondary)),
            trailing: const Icon(Icons.chevron_right, size: 18),
          ),
        ),
      );
    },
  );
}
