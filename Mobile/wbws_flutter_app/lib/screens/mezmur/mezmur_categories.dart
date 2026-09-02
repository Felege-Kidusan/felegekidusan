import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../services/connectivity_service.dart';
import '../../services/hymn_store.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';

/// Category management — two-level, offline-first like the rest of the
/// library: creates/renames land in the local list instantly and sync
/// through the hymn outbox with idempotency keys. Mains carry their
/// subs indented beneath them; every row supports rename, hide/show,
/// a cover image (upload needs connectivity), and shows its hymn count.
class MezmurCategoriesScreen extends StatefulWidget {
  const MezmurCategoriesScreen({super.key});

  @override
  State<MezmurCategoriesScreen> createState() => _MezmurCategoriesState();
}

class _MezmurCategoriesState extends State<MezmurCategoriesScreen> {
  final _store = HymnStore();
  final _picker = ImagePicker();
  List<Map<String, dynamic>> _categories = [];
  Map<int, int> _counts = {};
  bool _loading = true;
  int _uploadingId = 0;

  static const _palettes = [
    [Color(0xFF5A1212), Color(0xFFD4AF37)],
    [Color(0xFF4f46e5), Color(0xFF7c3aed)],
    [Color(0xFF0ea5e9), Color(0xFF2563eb)],
    [Color(0xFF059669), Color(0xFF0d9488)],
    [Color(0xFFd97706), Color(0xFFdc2626)],
    [Color(0xFFdb2777), Color(0xFF9333ea)],
  ];

  List<Color> _gradientFor(String name) {
    var h = 0;
    for (final c in name.codeUnits) {
      h = ((h << 5) - h + c) & 0x7fffffff;
    }
    return _palettes[h % _palettes.length];
  }

  @override
  void initState() {
    super.initState();
    _store.addListener(_reload);
    _reload();
    // Fresh canonical list behind the scenes.
    _store.pullChanges(lyricsBatch: 0);
  }

  @override
  void dispose() {
    _store.removeListener(_reload);
    super.dispose();
  }

  Future<void> _reload() async {
    final cats = await _store.categories(activeOnly: false);
    final counts = await _store.categoryHymnCounts();
    if (!mounted) return;
    setState(() {
      _categories = cats;
      _counts = counts;
      _loading = false;
    });
  }

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  List<Map<String, dynamic>> get _mains =>
      _categories.where((c) => c['parent_id'] == null).toList();

  List<Map<String, dynamic>> _subsOf(int mainId) => _categories
      .where((c) => c['parent_id'] != null && _asInt(c['parent_id']) == mainId)
      .toList();

  Future<void> _nameDialog({Map<String, dynamic>? category, int? parentId}) async {
    final isEdit = category != null;
    final isSub = isEdit ? category!['parent_id'] != null : parentId != null;
    final ctrl =
        TextEditingController(text: isEdit ? '${category!['name']}' : '');
    String? fieldError;
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(
              isEdit
                  ? 'Rename'
                  : (isSub ? 'New sub-category' : 'New main category'),
              style: const TextStyle(fontSize: 15)),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            if (!isEdit && isSub)
              Align(
                alignment: Alignment.centerLeft,
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                      'Under: ${_nameOf(parentId!)}',
                      style: TextStyle(
                          fontSize: 11.5, color: AppTheme.textSecondary)),
                ),
              ),
            TextField(
              controller: ctrl,
              autofocus: true,
              maxLength: 50,
              decoration: const InputDecoration(
                labelText: 'Name (ስም)',
                isDense: true,
                border: OutlineInputBorder(),
              ),
            ),
            if (fieldError != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(fieldError!,
                    style: const TextStyle(fontSize: 11.5, color: Colors.red)),
              ),
          ]),
          actions: [
            TextButton(
                onPressed: () => Navigator.of(ctx).pop(),
                child: const Text('CANCEL')),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: AppTheme.primary),
              onPressed: () async {
                final err = await _store.saveCategory({
                  if (isEdit) 'id': category!['id'],
                  'name': ctrl.text,
                  if (!isEdit && isSub) 'parent_id': parentId,
                  if (isEdit && isSub) 'parent_id': category!['parent_id'],
                  'sort_order': isEdit
                      ? _asInt(category!['sort_order'])
                      : (isSub
                          ? _subsOf(parentId!).length + 1
                          : _mains.length + 1),
                });
                if (err != null) {
                  setDialogState(() => fieldError = err);
                  return;
                }
                Navigator.of(ctx).pop();
                await _reload();
              },
              child: const Text('SAVE'),
            ),
          ],
        ),
      ),
    );
  }

  String _nameOf(int id) {
    for (final c in _categories) {
      if (_asInt(c['id']) == id) return '${c['name']}';
    }
    return '…';
  }

  Future<void> _toggleActive(Map<String, dynamic> category) async {
    final active = _asInt(category['is_active']) == 1;
    await _store.setCategoryStatus(_asInt(category['id']), !active);
    await _reload();
  }

  Future<void> _pickImage(Map<String, dynamic> category) async {
    if (!ConnectivityService().hasLink) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Go online once to upload the cover image.'),
        duration: Duration(seconds: 2),
      ));
      return;
    }
    final picked = await _picker.pickImage(
        source: ImageSource.gallery, maxWidth: 1600, maxHeight: 1600);
    if (picked == null) return;
    setState(() => _uploadingId = _asInt(category['id']));
    final err = await _store.setCategoryImage(_asInt(category['id']), picked.path);
    if (!mounted) return;
    setState(() => _uploadingId = 0);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(err ?? 'Cover image updated.'),
      duration: Duration(seconds: err == null ? 2 : 3),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Hymn Categories'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        onPressed: () => _nameDialog(),
        icon: const Icon(Icons.add, size: 20),
        label: const Text('Add Main'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _categories.isEmpty
              ? ListView(children: const [
                  SizedBox(height: 80),
                  EmptyState(
                    icon: Icons.category_outlined,
                    title: 'No categories yet',
                    subtitle:
                        'Add the first category — it syncs automatically.',
                  ),
                ])
              : ListView.builder(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 96),
                  itemCount: _mains.length,
                  itemBuilder: (context, i) {
                    final m = _mains[i];
                    final subs = _subsOf(_asInt(m['id']));
                    return Column(
                      children: [
                        _row(m, isSub: false),
                        for (final s in subs) _row(s, isSub: true),
                      ],
                    );
                  },
                ),
    );
  }

  Widget _row(Map<String, dynamic> c, {required bool isSub}) {
    final active = _asInt(c['is_active']) == 1;
    final name = '${c['name']}';
    final count = _counts[_asInt(c['id'])] ?? 0;
    final img = '${c['image_url'] ?? ''}';
    final uploading = _uploadingId == _asInt(c['id']);
    return Card(
      margin: EdgeInsets.only(
          left: isSub ? 24 : 0, right: 0, bottom: 8, top: isSub ? 0 : 4),
      child: ListTile(
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
        leading: uploading
            ? const SizedBox(
                width: 34,
                height: 34,
                child: Center(
                    child: SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2))))
            : ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: SizedBox(
                  width: 34,
                  height: 34,
                  child: img.isNotEmpty
                      ? Image.network(
                          '${AppConfig.apiBaseUrl.replaceFirst(RegExp(r'/api/v1$'), '')}$img',
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => _gradientThumb(name),
                        )
                      : _gradientThumb(name),
                ),
              ),
        title: Row(
          children: [
            if (isSub)
              Padding(
                padding: const EdgeInsets.only(right: 4),
                child: Icon(Icons.subdirectory_arrow_right,
                    size: 14, color: AppTheme.textSecondary),
              ),
            Expanded(
              child: Text(name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      fontSize: 13.5,
                      fontWeight: isSub ? FontWeight.w500 : FontWeight.w700,
                      color: active ? null : AppTheme.textSecondary,
                      decoration: active ? null : TextDecoration.lineThrough)),
            ),
            const SizedBox(width: 6),
            Text('$count',
                style: TextStyle(
                    fontSize: 10.5, color: AppTheme.textSecondary)),
          ],
        ),
        subtitle: Text(
            active
                ? (isSub ? 'Sub-category' : 'Main category')
                : 'Hidden from pickers',
            style: TextStyle(fontSize: 10.5, color: AppTheme.textSecondary)),
        trailing: PopupMenuButton<String>(
          icon: const Icon(Icons.more_vert, size: 19),
          padding: EdgeInsets.zero,
          onSelected: (v) {
            switch (v) {
              case 'rename':
                _nameDialog(category: c);
                break;
              case 'image':
                _pickImage(c);
                break;
              case 'addsub':
                _nameDialog(parentId: _asInt(c['id']));
                break;
              case 'toggle':
                _toggleActive(c);
                break;
            }
          },
          itemBuilder: (_) => [
            if (!isSub)
              const PopupMenuItem(
                value: 'addsub',
                height: 40,
                child: Row(children: [
                  Icon(Icons.add, size: 16),
                  SizedBox(width: 8),
                  Text('Add sub-category', style: TextStyle(fontSize: 12.5)),
                ]),
              ),
            const PopupMenuItem(
              value: 'rename',
              height: 40,
              child: Row(children: [
                Icon(Icons.edit_outlined, size: 16),
                SizedBox(width: 8),
                Text('Rename', style: TextStyle(fontSize: 12.5)),
              ]),
            ),
            const PopupMenuItem(
              value: 'image',
              height: 40,
              child: Row(children: [
                Icon(Icons.image_outlined, size: 16),
                SizedBox(width: 8),
                Text('Cover image', style: TextStyle(fontSize: 12.5)),
              ]),
            ),
            PopupMenuItem(
              value: 'toggle',
              height: 40,
              child: Row(children: [
                Icon(
                    active
                        ? Icons.visibility_off_outlined
                        : Icons.visibility_outlined,
                    size: 16),
                const SizedBox(width: 8),
                Text(active ? 'Hide' : 'Restore',
                    style: const TextStyle(fontSize: 12.5)),
              ]),
            ),
          ],
        ),
      ),
    );
  }

  Widget _gradientThumb(String name) {
    final colors = _gradientFor(name);
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
            begin: Alignment.topLeft, end: Alignment.bottomRight, colors: colors),
      ),
      child: Center(
        child: Text(
          name.trim().isEmpty ? '?' : name.trim().substring(0, 1).toUpperCase(),
          style: const TextStyle(
              color: Colors.white, fontWeight: FontWeight.w800, fontSize: 14),
        ),
      ),
    );
  }
}
