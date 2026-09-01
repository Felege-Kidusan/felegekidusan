import 'package:flutter/material.dart';

import '../../services/hymn_store.dart';
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
  bool _loading = true;

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
    if (!mounted) return;
    setState(() {
      _zemarians = z;
      _loading = false;
    });
  }

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  Future<void> _nameDialog({Map<String, dynamic>? zemarian}) async {
    final nameCtrl =
        TextEditingController(text: zemarian == null ? '' : '${zemarian['name']}');
    final amCtrl = TextEditingController(
        text: zemarian == null ? '' : '${zemarian['name_am'] ?? ''}');
    final isEdit = zemarian != null;
    String? fieldError;
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
                labelText: 'Name (ስም)',
                isDense: true,
                border: OutlineInputBorder(),
              ),
            ),
            TextField(
              controller: amCtrl,
              maxLength: 100,
              decoration: const InputDecoration(
                labelText: 'Amharic name (አማርኛ)',
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
                final err = await _store.saveZemarian({
                  if (isEdit) 'id': zemarian['id'],
                  'name': nameCtrl.text,
                  'name_am': amCtrl.text,
                  'sort_order':
                      isEdit ? _asInt(zemarian['sort_order']) : _zemarians.length + 1,
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
                        leading: CircleAvatar(
                          backgroundColor: AppTheme.primary.withOpacity(0.1),
                          child: const Icon(Icons.person_outline,
                              size: 17, color: AppTheme.primary),
                        ),
                        title: Text('${z['name']}',
                            style: TextStyle(
                                fontSize: 13.5,
                                fontWeight: FontWeight.w600,
                                color:
                                    active ? null : AppTheme.textSecondary,
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
                            onPressed: () => _nameDialog(zemarian: z),
                          ),
                          IconButton(
                            tooltip: active ? 'Hide' : 'Restore',
                            icon: Icon(
                                active
                                    ? Icons.visibility_off_outlined
                                    : Icons.visibility_outlined,
                                size: 17),
                            onPressed: () => _toggleActive(z),
                          ),
                        ]),
                      ),
                    );
                  },
                ),
    );
  }
}