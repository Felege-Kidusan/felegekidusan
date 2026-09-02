import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../services/connectivity_service.dart';
import '../../services/hymn_store.dart';
import '../../utils/config.dart';
import '../../utils/cover_palette.dart';
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
    if (_asInt(category['id']) <= 0) {
      // Placeholder id: the category itself has not reached the server
      // yet, and uploads address server ids.
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text(
            'This category is still syncing — go online once, then add the cover image.'),
        duration: Duration(seconds: 3),
      ));
      return;
    }
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

  /// '#rrggbb' string of a Color (alpha stripped).
  String _hexOf(Color c) =>
      '#${(c.value & 0xFFFFFF).toRadixString(16).padLeft(6, '0')}';

  /// Strip an alpha pair if present (#rrggbbaa -> #rrggbb).
  String _hex6(String v) => v.length >= 7 ? v.substring(0, 7) : v;

  /// Opacity percent stored in an optional alpha pair (default 100).
  int _opOf(dynamic v) {
    final s = (v ?? '').toString().trim();
    if (!RegExp(r'^#[0-9a-fA-F]{8}$').hasMatch(s)) return 100;
    return (int.parse(s.substring(7, 9), radix: 16) * 100 / 255).round();
  }

  /// Cover-color picker: preset gradients, custom hex pair, or the
  /// automatic name-hashed palette — with a live preview. Saves
  /// offline-first like every other category edit.
  Future<void> _pickColors(Map<String, dynamic> c) async {
    const presets = [
      ['0xFF5A1212', '0xFFD4AF37'],
      ['0xFF4f46e5', '0xFF7c3aed'],
      ['0xFF0ea5e9', '0xFF2563eb'],
      ['0xFF059669', '0xFF0d9488'],
      ['0xFFd97706', '0xFFdc2626'],
      ['0xFFdb2777', '0xFF9333ea'],
    ];
    var start = (c['gradient_start'] ?? '').toString();
    var end = (c['gradient_end'] ?? '').toString();
    var auto = start.isEmpty && end.isEmpty;
    if (auto) {
      final g = coverColors(c, '${c['name'] ?? ''}');
      start = _hexOf(g[0]);
      end = _hexOf(g[1]);
    }
    // P33: opacity lives in the optional alpha pair (#rrggbbaa).
    var opStart = _opOf(c['gradient_start']);
    var opEnd = _opOf(c['gradient_end']);
    start = _hex6(start);
    end = _hex6(end);
    final startCtrl = TextEditingController(text: start);
    final endCtrl = TextEditingController(text: end);
    final name = '${c['name'] ?? ''}';

    Color? parse(String v) {
      final m = RegExp(r'^#?([0-9a-fA-F]{6})$').firstMatch(v.trim());
      return m == null ? null : Color(int.parse(m.group(1)!, radix: 16) | 0xFF000000);
    }

    String withAlpha(String hex, int op) => op >= 100
        ? hex
        : hex +
            (255 * op ~/ 100).toRadixString(16).padLeft(2, '0');

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                const Icon(Icons.palette_outlined, size: 16),
                const SizedBox(width: 6),
                Expanded(
                  child: Text('Cover color · $name',
                      style: const TextStyle(
                          fontSize: 14, fontWeight: FontWeight.w700)),
                ),
                TextButton(
                  onPressed: () {
                    final g = coverColors(c, name);
                    setSheet(() {
                      auto = true;
                      startCtrl.text = _hexOf(g[0]);
                      endCtrl.text = _hexOf(g[1]);
                      opStart = 100;
                      opEnd = 100;
                    });
                  },
                  child: const Text('AUTO (BY NAME)'),
                ),
              ]),
              const SizedBox(height: 8),
              // live preview: same treatment as tiles and headers —
              // over a checkerboard, so the picked opacity reads.
              ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Stack(
                  children: [
                    Positioned.fill(
                        child: CustomPaint(painter: _CheckerPainter())),
                    Container(
                      height: 88,
                      width: double.infinity,
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                          colors: [
                            (parse(startCtrl.text) ??
                                    const Color(0xFF4f46e5))
                                .withAlpha(255 * opStart ~/ 100),
                            (parse(endCtrl.text) ??
                                    const Color(0xFF7c3aed))
                                .withAlpha(255 * opEnd ~/ 100),
                          ],
                        ),
                      ),
                      alignment: Alignment.bottomLeft,
                      padding: const EdgeInsets.all(12),
                      child: Text(name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w800,
                              fontSize: 15)),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final p in presets)
                    InkWell(
                      borderRadius: BorderRadius.circular(8),
                      onTap: () => setSheet(() {
                        auto = false;
                        startCtrl.text = '#${p[0].substring(2)}';
                        endCtrl.text = '#${p[1].substring(2)}';
                      }),
                      child: Container(
                        width: 48,
                        height: 32,
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(8),
                          gradient: LinearGradient(
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                            colors: [
                              Color(int.parse(p[0])),
                              Color(int.parse(p[1]))
                            ],
                          ),
                          border: !auto &&
                                  startCtrl.text.toLowerCase() ==
                                      '#${p[0].substring(2).toLowerCase()}' &&
                                  endCtrl.text.toLowerCase() ==
                                      '#${p[1].substring(2).toLowerCase()}'
                              ? Border.all(color: AppTheme.primary, width: 2)
                              : null,
                        ),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 12),
              Row(children: [
                Expanded(
                  child: TextField(
                    controller: startCtrl,
                    decoration: const InputDecoration(
                        labelText: 'Start (#rrggbb)',
                        isDense: true,
                        border: OutlineInputBorder()),
                    onChanged: (_) => setSheet(() {}),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: TextField(
                    controller: endCtrl,
                    decoration: const InputDecoration(
                        labelText: 'End (#rrggbb)',
                        isDense: true,
                        border: OutlineInputBorder()),
                    onChanged: (_) => setSheet(() {}),
                  ),
                ),
              ]),
              const SizedBox(height: 6),
              Text(
                  'Shows wherever this category appears without a cover image.',
                  style: TextStyle(
                      fontSize: 11, color: AppTheme.textSecondary)),
              const SizedBox(height: 10),
              // P33: opacity (transparency) per gradient stop.
              Row(children: [
                const Icon(Icons.opacity, size: 14),
                const SizedBox(width: 6),
                Expanded(
                  child: Slider(
                    value: opStart.toDouble(),
                    min: 20,
                    max: 100,
                    divisions: 80,
                    label: 'Start $opStart%',
                    activeColor: AppTheme.primary,
                    onChanged: (v) => setSheet(() => opStart = v.round()),
                  ),
                ),
                SizedBox(
                    width: 44,
                    child: Text('$opStart%',
                        textAlign: TextAlign.right,
                        style: TextStyle(
                            fontSize: 11, color: AppTheme.textSecondary))),
              ]),
              Row(children: [
                const Icon(Icons.opacity, size: 14),
                const SizedBox(width: 6),
                Expanded(
                  child: Slider(
                    value: opEnd.toDouble(),
                    min: 20,
                    max: 100,
                    divisions: 80,
                    label: 'End $opEnd%',
                    activeColor: AppTheme.primary,
                    onChanged: (v) => setSheet(() => opEnd = v.round()),
                  ),
                ),
                SizedBox(
                    width: 44,
                    child: Text('$opEnd%',
                        textAlign: TextAlign.right,
                        style: TextStyle(
                            fontSize: 11, color: AppTheme.textSecondary))),
              ]),
              const SizedBox(height: 4),
              SizedBox(
                width: double.infinity,
                child: ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: AppTheme.primary,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 13),
                  ),
                  onPressed: () async {
                    final err = await _store.saveCategory({
                      'id': c['id'],
                      'name': name,
                      if (c['parent_id'] != null) 'parent_id': c['parent_id'],
                      'sort_order': c['sort_order'],
                      if (!auto)
                        'gradient_start': withAlpha(startCtrl.text.trim(), opStart),
                      if (!auto)
                        'gradient_end': withAlpha(endCtrl.text.trim(), opEnd),
                    });
                    if (ctx.mounted) {
                      Navigator.of(ctx).pop();
                      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                        content: Text(
                            err ?? 'Cover color saved — syncing.'),
                        duration: const Duration(seconds: 2),
                      ));
                    }
                    await _reload();
                  },
                  icon: const Icon(Icons.check, size: 18),
                  label: const Text('Save'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Reorder within the same level (web-manager parity): swap
  /// sort_order with the adjacent sibling — offline-first, syncs like
  /// every other category edit.
  Future<void> _move(Map<String, dynamic> c, int dir) async {
    final siblings = c['parent_id'] == null
        ? _mains
        : _subsOf(_asInt(c['parent_id']));
    final idx =
        siblings.indexWhere((s) => _asInt(s['id']) == _asInt(c['id']));
    final other = (idx >= 0 && idx + dir >= 0 && idx + dir < siblings.length)
        ? siblings[idx + dir]
        : null;
    if (!mounted) return;
    if (other == null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(dir < 0
            ? 'Already at the top of its level.'
            : 'Already at the bottom of its level.'),
        duration: const Duration(seconds: 2),
      ));
      return;
    }
    final a = _asInt(c['sort_order']);
    final b = _asInt(other['sort_order']);
    await _store.saveCategory({
      'id': c['id'],
      'name': '${c['name']}',
      if (c['parent_id'] != null) 'parent_id': c['parent_id'],
      'sort_order': b == a ? a + dir : b,
    });
    await _store.saveCategory({
      'id': other['id'],
      'name': '${other['name']}',
      if (other['parent_id'] != null) 'parent_id': other['parent_id'],
      'sort_order': a == b ? b - dir : a,
    });
    await _reload();
  }

  Future<void> _removeImage(Map<String, dynamic> c) async {
    final err = await _store.removeCategoryImage(_asInt(c['id']));
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(err ?? 'Cover image removed — the gradient shows.'),
      duration: Duration(seconds: err == null ? 2 : 3),
    ));
    await _reload();
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
                          errorBuilder: (_, __, ___) => _gradientThumb(c),
                        )
                      : _gradientThumb(c),
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
              case 'color':
                _pickColors(c);
                break;
              case 'up':
                _move(c, -1);
                break;
              case 'down':
                _move(c, 1);
                break;
              case 'removeimg':
                _removeImage(c);
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
            const PopupMenuItem(
              value: 'color',
              height: 40,
              child: Row(children: [
                Icon(Icons.palette_outlined, size: 16),
                SizedBox(width: 8),
                Text('Cover color', style: TextStyle(fontSize: 12.5)),
              ]),
            ),
            const PopupMenuItem(
              value: 'up',
              height: 40,
              child: Row(children: [
                Icon(Icons.arrow_upward, size: 16),
                SizedBox(width: 8),
                Text('Move up', style: TextStyle(fontSize: 12.5)),
              ]),
            ),
            const PopupMenuItem(
              value: 'down',
              height: 40,
              child: Row(children: [
                Icon(Icons.arrow_downward, size: 16),
                SizedBox(width: 8),
                Text('Move down', style: TextStyle(fontSize: 12.5)),
              ]),
            ),
            if (img.isNotEmpty)
              const PopupMenuItem(
                value: 'removeimg',
                height: 40,
                child: Row(children: [
                  Icon(Icons.hide_image_outlined, size: 16),
                  SizedBox(width: 8),
                  Text('Remove image', style: TextStyle(fontSize: 12.5)),
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

  Widget _gradientThumb(Map<String, dynamic> c) {
    final colors = coverColors(c, '${c['name'] ?? ''}');
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
            begin: Alignment.topLeft, end: Alignment.bottomRight, colors: colors),
      ),
      child: Center(
        child: Text(
          ('${c['name'] ?? ''}').trim().isEmpty
              ? '?'
              : ('${c['name'] ?? ''}').trim().substring(0, 1).toUpperCase(),
          style: const TextStyle(
              color: Colors.white, fontWeight: FontWeight.w800, fontSize: 14),
        ),
      ),
    );
  }
}


/// Transparency checkerboard under the color-picker preview (P33):
/// the classic design-tool pattern that makes opacity visible.
class _CheckerPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    const sq = 10.0;
    final light = Paint()..color = Colors.white;
    final dark = Paint()..color = const Color(0xFFD8DAE0);
    canvas.drawRect(Offset.zero & size, light);
    for (var y = 0.0; y < size.height; y += sq) {
      for (var x = 0.0; x < size.width; x += sq) {
        if (((x / sq).floor() + (y / sq).floor()).isEven) {
          canvas.drawRect(Rect.fromLTWH(x, y, sq, sq), dark);
        }
      }
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
