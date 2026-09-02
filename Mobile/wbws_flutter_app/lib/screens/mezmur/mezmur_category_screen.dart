import 'package:flutter/material.dart';

import '../../services/hymn_store.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import 'mezmur_hymn_detail.dart';

/// P30: a self-contained full-screen category page.
///
/// Chosen browse model: tapping a MAIN category opens THIS screen —
/// its own stylized header (cover image or signature gradient under a
/// dark scrim, the category name set large in white), a chip row of
/// its SUB-categories (with counts), and the hymn list beneath. The
/// main roll-up shows by default ("All"); a chip narrows to that sub.
class MezmurCategoryScreen extends StatefulWidget {
  const MezmurCategoryScreen({
    super.key,
    required this.categoryId,
    required this.name,
    this.imageUrl,
  });

  final int categoryId;
  final String name;
  final String? imageUrl;

  @override
  State<MezmurCategoryScreen> createState() => _MezmurCategoryScreenState();
}

class _MezmurCategoryScreenState extends State<MezmurCategoryScreen> {
  final _store = HymnStore();
  List<Map<String, dynamic>> _hymns = const [];
  List<Map<String, dynamic>> _subs = const [];
  Map<int, int> _counts = {};
  int? _subId;
  bool _loading = true;

  static const _gradients = [
    [Color(0xFF5A1212), Color(0xFFD4AF37)],
    [Color(0xFF4f46e5), Color(0xFF7c3aed)],
    [Color(0xFF0ea5e9), Color(0xFF2563eb)],
    [Color(0xFF059669), Color(0xFF0d9488)],
    [Color(0xFFd97706), Color(0xFFdc2626)],
    [Color(0xFFdb2777), Color(0xFF9333ea)],
  ];

  List<Color> get _gradient {
    var h = 0;
    for (final c in widget.name.codeUnits) {
      h = ((h << 5) - h + c) & 0x7fffffff;
    }
    return _gradients[h % _gradients.length];
  }

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
        .where((c) => c['parent_id'] != null && _asInt(c['parent_id']) == widget.categoryId)
        .toList();
    final counts = await _store.categoryHymnCounts();
    final hymns = await _store.hymns(
      categoryId: _subId ?? widget.categoryId,
    );
    if (!mounted) return;
    setState(() {
      _subs = subs;
      _counts = counts;
      _hymns = hymns;
      _loading = false;
    });
  }

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  @override
  Widget build(BuildContext context) {
    final rolledUp = _counts[widget.categoryId] ?? _hymns.length;
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
                        '${AppConfig.apiBaseUrl.replaceFirst(RegExp(r'/api/v1$'), '')}${widget.imageUrl}',
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => DecoratedBox(
                          decoration: BoxDecoration(gradient: LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: _gradient)),
                        ),
                      )
                    : DecoratedBox(
                        decoration: BoxDecoration(gradient: LinearGradient(
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                            colors: _gradient)),
                      ),
              ),
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [Colors.black.withOpacity(0.25), Colors.black.withOpacity(0.62)],
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
          // ── sub-category chips ──
          if (_subs.isNotEmpty)
            SizedBox(
              height: 46,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                children: [
                  _subChip('All', null, rolledUp),
                  for (final sub in _subs)
                    _subChip(sub['name'] ?? '', _asInt(sub['id']),
                        _counts[_asInt(sub['id'])] ?? 0),
                ],
              ),
            ),
          // ── hymn list ──
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator(strokeWidth: 2))
                : _hymns.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.music_off_outlined,
                                size: 40, color: AppTheme.textSecondary),
                            const SizedBox(height: 8),
                            Text(
                              'No hymns here yet.',
                              style: TextStyle(
                                  fontSize: 13, color: AppTheme.textSecondary),
                            ),
                          ],
                        ),
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                        itemCount: _hymns.length,
                        itemBuilder: (context, i) {
                          final h = _hymns[i];
                          final archived = '${h['status']}' == 'archived';
                          return Card(
                            margin: const EdgeInsets.only(bottom: 8),
                            child: Opacity(
                              opacity: archived ? 0.55 : 1,
                              child: ListTile(
                                onTap: () async {
                                  await Navigator.of(context).push(MaterialPageRoute(
                                    builder: (_) => MezmurHymnDetailScreen(
                                        id: _asInt(h['id'])),
                                  ));
                                  _load();
                                },
                                leading: CircleAvatar(
                                  backgroundColor: AppTheme.primary.withOpacity(0.1),
                                  child: Icon(
                                      archived
                                          ? Icons.archive_outlined
                                          : Icons.music_note,
                                      size: 18,
                                      color: AppTheme.primary),
                                ),
                                title: Text('${h['title'] ?? ''}',
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                        fontSize: 13.5,
                                        fontWeight: FontWeight.w600)),
                                subtitle: '${h['category'] ?? ''}'.isEmpty
                                    ? null
                                    : Text('${h['category']}',
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: TextStyle(
                                            fontSize: 11,
                                            color: AppTheme.textSecondary)),
                                trailing: const Icon(Icons.chevron_right, size: 18),
                              ),
                            ),
                          );
                        },
                      ),
          ),
        ],
      ),
    );
  }

  Widget _subChip(String label, int? id, int count) {
    final selected = _subId == id;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: FilterChip(
        avatar: Text('$count',
            style: TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w800,
                color: selected ? Colors.white : AppTheme.textSecondary)),
        label: Text(label, style: const TextStyle(fontSize: 11.5)),
        selected: selected,
        onSelected: (_) {
          setState(() {
            _subId = id;
            _loading = true;
          });
          _load();
        },
      ),
    );
  }
}
