import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../services/connectivity_service.dart';
import '../../services/hymn_store.dart';
import '../../utils/config.dart';
import '../../utils/cover_palette.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';

/// Singer / Zemarian management — offline-first (mirrors categories):
/// creates/renames land in the local list instantly and sync through the
/// hymn outbox with idempotency keys.
class MezmurZemariansScreen extends StatefulWidget {
  const MezmurZemariansScreen({super.key});
  @override
  State<MezmurZemariansScreen> createState() => _MezmurZemariansState();
}

class _MezmurZemariansState extends State<MezmurZemariansScreen> {
  final _store = HymnStore();
  List<Map<String, dynamic>> _zemarians = [];
  Map<int, int> _counts = {};
  bool _loading = true;
  final _picker = ImagePicker();
  int _uploadingId = 0;

  @override
  void initState() {
    super.initState();
    _store.addListener(_reload);
    _reload();
    _store.pullChanges(lyricsBatch: 0);
  }

  @override
  void dispose() {
    _store.removeListener(_reload);
    super.dispose();
  }

  Future<void> _reload() async {
    final z = await _store.zemarians(activeOnly: false);
    final counts = await _store.zemarianHymnCounts();
    if (!mounted) return;
    setState(() {
      _zemarians = z;
      _counts = counts;
      _loading = false;
    });
  }

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  Future<void> _nameDialog({Map<String, dynamic>? zemarian}) async {
    // P35: ONE name field, written in Amharic — stored in both name
    // (canonical display/filter field) and name_am.
    final nameCtrl =
        TextEditingController(text: zemarian == null ? '' : '${zemarian['name']}');
    final isEdit = zemarian != null;
    String? fieldError;
    // P35: in-flight guard so a double tap cannot enter saveZemarian
    // twice (same guard the category dialog carries).
    bool saving = false;
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(isEdit ? 'Rename singer' : 'New singer',
              style: const TextStyle(fontSize: 15)),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(
              controller: nameCtrl,
              autofocus: true,
              maxLength: 100,
              decoration: const InputDecoration(
                labelText: 'ስም — singer name (Amharic)',
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
              onPressed: saving
                  ? null
                  : () async {
                setDialogState(() {
                  saving = true;
                  fieldError = null;
                });
                final err = await _store.saveZemarian({
                  if (isEdit) 'id': zemarian['id'],
                  'name': nameCtrl.text,
                  'name_am': nameCtrl.text,
                  'sort_order':
                      isEdit ? _asInt(zemarian['sort_order']) : _zemarians.length + 1,
                });
                if (err != null) {
                  setDialogState(() {
                    fieldError = err;
                    saving = false;
                  });
                  return;
                }
                if (!ctx.mounted) return;
                Navigator.of(ctx).pop();
                await _reload();
              },
              child: saving
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white))
                  : const Text('SAVE'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _thumb(Map<String, dynamic> z) {
    final name = '${z['name'] ?? ''}';
    final img = '${z['image_url'] ?? ''}';
    if (_uploadingId == _asInt(z['id'])) {
      return const SizedBox(
          width: 34,
          height: 34,
          child: Center(
              child: SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2))));
    }
    return ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: SizedBox(
        width: 34,
        height: 34,
        child: img.isNotEmpty
            ? Image.network(
                '${AppConfig.apiBaseUrl.replaceFirst(RegExp(r'/api/v1$'), '')}$img',
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) => _gradientThumb(z),
              )
            : _gradientThumb(z),
      ),
    );
  }

  Widget _gradientThumb(Map<String, dynamic> z) {
    final colors = coverColors(z, '${z['name'] ?? ''}');
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
            begin: Alignment.topLeft, end: Alignment.bottomRight, colors: colors),
      ),
      child: Center(
        child: Icon(Icons.music_note_outlined,
            size: 16, color: Colors.white.withOpacity(0.95)),
      ),
    );
  }

  /// P34: singer cover images — pick, preview-free upload (same hardened
  /// route as categories), remove falls back to the gradient.
  Future<void> _pickImage(Map<String, dynamic> z) async {
    if (_asInt(z['id']) <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text(
            'This singer is still syncing — go online once, then add the cover image.'),
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
    setState(() => _uploadingId = _asInt(z['id']));
    final err =
        await _store.setZemarianImage(_asInt(z['id']), picked.path);
    if (!mounted) return;
    setState(() => _uploadingId = 0);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(err ?? 'Cover image updated.'),
      duration: Duration(seconds: err == null ? 2 : 3),
    ));
  }

  Future<void> _removeImage(Map<String, dynamic> z) async {
    final err = await _store.removeZemarianImage(_asInt(z['id']));
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(err ?? 'Cover image removed — the gradient shows.'),
      duration: Duration(seconds: err == null ? 2 : 3),
    ));
    await _reload();
  }

  Future<void> _toggleActive(Map<String, dynamic> zemarian) async {
    final active = _asInt(zemarian['is_active']) == 1;
    await _store.setZemarianStatus(_asInt(zemarian['id']), !active);
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Singers · ዘማሪያን'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        onPressed: () => _nameDialog(),
        icon: const Icon(Icons.add, size: 20),
        label: const Text('Add Singer'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _zemarians.isEmpty
              ? ListView(children: const [
                  SizedBox(height: 80),
                  EmptyState(
                    icon: Icons.person_outline,
                    title: 'No singers yet',
                    subtitle: 'Add the first singer — it syncs automatically.',
                  ),
                ])
              : ListView.builder(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 96),
                  itemCount: _zemarians.length,
                  itemBuilder: (context, i) {
                    final z = _zemarians[i];
                    final active = _asInt(z['is_active']) == 1;
                    return Card(
                      margin: const EdgeInsets.only(bottom: 8),
                      child: ListTile(
                        leading: _thumb(z),
                        title: Row(
                          children: [
                            Expanded(
                              child: Text('${z['name']}',
                                  style: TextStyle(
                                      fontSize: 13.5,
                                      fontWeight: FontWeight.w600,
                                      color: active
                                          ? null
                                          : AppTheme.textSecondary,
                                      decoration: active
                                          ? null
                                          : TextDecoration.lineThrough)),
                            ),
                            Text('${_counts[_asInt(z['id'])] ?? 0}',
                                style: TextStyle(
                                    fontSize: 10.5,
                                    color: AppTheme.textSecondary)),
                          ],
                        ),
                        subtitle: Text(
                            active ? 'Active' : 'Hidden from pickers',
                            style: TextStyle(
                                fontSize: 11, color: AppTheme.textSecondary)),
                        trailing: PopupMenuButton<String>(
                          icon: const Icon(Icons.more_vert, size: 19),
                          padding: EdgeInsets.zero,
                          onSelected: (v) {
                            switch (v) {
                              case 'rename':
                                _nameDialog(zemarian: z);
                                break;
                              case 'image':
                                _pickImage(z);
                                break;
                              case 'removeimg':
                                _removeImage(z);
                                break;
                              case 'toggle':
                                _toggleActive(z);
                                break;
                            }
                          },
                          itemBuilder: (_) => [
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
                            if ('${z['image_url'] ?? ''}'.isNotEmpty)
                              const PopupMenuItem(
                                value: 'removeimg',
                                height: 40,
                                child: Row(children: [
                                  Icon(Icons.hide_image_outlined, size: 16),
                                  SizedBox(width: 8),
                                  Text('Remove image',
                                      style: TextStyle(fontSize: 12.5)),
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
                  },
                ),
    );
  }
}