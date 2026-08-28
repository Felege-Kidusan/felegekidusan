import 'package:flutter/material.dart';

import '../../services/hymn_store.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';

/// Category management — offline-first like the rest of the library:
/// creates/renames land in the local list instantly and sync through
/// the hymn outbox with idempotency keys.
class MezmurCategoriesScreen extends StatefulWidget {
  const MezmurCategoriesScreen({super.key});
  @override
  State<MezmurCategoriesScreen> createState() => _MezmurCategoriesState();
}

class _MezmurCategoriesState extends State<MezmurCategoriesScreen> {
  final _store = HymnStore();
  List<Map<String, dynamic>> _categories = [];
  bool _loading = true;

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
    if (!mounted) return;
    setState(() {
      _categories = cats;
      _loading = false;
    });
  }

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  Future<void> _nameDialog({Map<String, dynamic>? category}) async {
    final ctrl = TextEditingController(text: category == null ? '' : '${category['name']}');
    final isEdit = category != null;
    String? fieldError;
    await showDialog<void>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(isEdit ? 'Rename category' : 'New category',
              style: const TextStyle(fontSize: 15)),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
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
                  if (isEdit) 'id': category['id'],
                  'name': ctrl.text,
                  'sort_order': isEdit ? _asInt(category['sort_order']) : _categories.length + 1,
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

  Future<void> _toggleActive(Map<String, dynamic> category) async {
    final active = _asInt(category['is_active']) == 1;
    await _store.setCategoryStatus(_asInt(category['id']), !active);
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
        label: const Text('Add Category'),
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
                  itemCount: _categories.length,
                  itemBuilder: (context, i) {
                    final c = _categories[i];
                    final active = _asInt(c['is_active']) == 1;
                    return Card(
                      margin: const EdgeInsets.only(bottom: 8),
                      child: ListTile(
                        leading: CircleAvatar(
                          backgroundColor: AppTheme.primary.withOpacity(0.1),
                          child: const Icon(Icons.folder_outlined,
                              size: 17, color: AppTheme.primary),
                        ),
                        title: Text('${c['name']}',
                            style: TextStyle(
                                fontSize: 13.5,
                                fontWeight: FontWeight.w600,
                                color: active
                                    ? null
                                    : AppTheme.textSecondary,
                                decoration:
                                    active ? null : TextDecoration.lineThrough)),
                        subtitle: Text(
                            active ? 'Active' : 'Hidden from pickers',
                            style: TextStyle(
                                fontSize: 11, color: AppTheme.textSecondary)),
                        trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                          IconButton(
                            tooltip: 'Rename',
                            icon: const Icon(Icons.edit_outlined, size: 17),
                            onPressed: () => _nameDialog(category: c),
                          ),
                          IconButton(
                            tooltip: active ? 'Hide' : 'Restore',
                            icon: Icon(
                                active
                                    ? Icons.visibility_off_outlined
                                    : Icons.visibility_outlined,
                                size: 17),
                            onPressed: () => _toggleActive(c),
                          ),
                        ]),
                      ),
                    );
                  },
                ),
    );
  }
}
