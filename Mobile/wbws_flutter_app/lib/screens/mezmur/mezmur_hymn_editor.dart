import 'package:flutter/material.dart';

import '../../services/connectivity_service.dart';
import '../../services/hymn_store.dart';
import '../../utils/theme.dart';

/// Add / edit one hymn — fully offline.
///
/// Saving writes to the local library instantly (the row is visible the
/// moment Save is tapped, even in airplane mode) and queues the push;
/// the sync engine delivers it with an idempotency key. Edits carry the
/// revision they started from, so a newer server copy is surfaced as a
/// conflict instead of being silently overwritten.
class MezmurHymnEditorScreen extends StatefulWidget {
  /// Existing hymn row (from the local store) or null for a new hymn.
  final Map<String, dynamic>? hymn;
  const MezmurHymnEditorScreen({super.key, this.hymn});

  @override
  State<MezmurHymnEditorScreen> createState() => _MezmurHymnEditorState();
}

class _MezmurHymnEditorState extends State<MezmurHymnEditorScreen> {
  final _store = HymnStore();
  final _titleCtrl = TextEditingController();
  final _titleAmCtrl = TextEditingController();
  final _referenceCtrl = TextEditingController();
  final _lyricsCtrl = TextEditingController();

  static const _lyricsMax = 200000;

  List<Map<String, dynamic>> _categories = [];
  String _category = '';
  bool _saving = false;
  String? _error;

  int get _localRowId {
    final v = widget.hymn?['id'];
    if (v is int) return v;
    return int.tryParse('${v ?? 0}') ?? 0;
  }

  /// Editing a row that already exists locally (server id > 0, or a
  /// negative placeholder waiting for its first sync).
  bool get _isEdit => _localRowId != 0;

  @override
  void initState() {
    super.initState();
    final h = widget.hymn;
    if (h != null) {
      _titleCtrl.text = '${h['title'] ?? ''}';
      _titleAmCtrl.text = '${h['title_am'] ?? ''}';
      _referenceCtrl.text = '${h['reference'] ?? ''}';
      _lyricsCtrl.text = '${h['lyrics'] ?? ''}';
      _category = '${h['category'] ?? ''}';
    }
    _loadCategories();
  }

  Future<void> _loadCategories() async {
    final cats = await _store.categories();
    if (!mounted) return;
    setState(() {
      _categories = cats;
      if (_category.isEmpty && cats.isNotEmpty) _category = '${cats.first['name']}';
      // Keep legacy/free-form categories selectable too.
      final hymnCat = '${widget.hymn?['category'] ?? ''}';
      if (hymnCat.isNotEmpty &&
          !cats.any((c) => '${c['name']}' == hymnCat)) {
        _categories = [
          {'id': 0, 'name': hymnCat, 'sort_order': 0, 'is_active': 1},
          ...cats,
        ];
        _category = hymnCat;
      }
    });
  }

  @override
  void dispose() {
    _titleCtrl.dispose();
    _titleAmCtrl.dispose();
    _referenceCtrl.dispose();
    _lyricsCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving) return;
    final title = _titleCtrl.text.trim();
    if (title.isEmpty) {
      setState(() => _error = 'Title is required.');
      return;
    }
    if (_lyricsCtrl.text.length > _lyricsMax) {
      setState(() => _error = 'Lyrics text is too long.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });

    final hymn = <String, dynamic>{
      if (_isEdit) 'id': _localRowId,
      'title': title,
      'title_am': _titleAmCtrl.text.trim(),
      'category': _category.isEmpty ? 'general' : _category,
      'reference': _referenceCtrl.text.trim(),
      'lyrics': _lyricsCtrl.text.trim(),
    };

    // Conflict guard only for rows the server already knows (id > 0);
    // negative ids are local placeholders still waiting for first sync.
    final baseRevision =
        _isEdit && _localRowId > 0 ? int.tryParse('${widget.hymn!['revision'] ?? 0}') : null;

    final err = await _store.saveHymn(hymn, baseRevision: baseRevision);
    if (!mounted) return;
    if (err != null) {
      setState(() {
        _saving = false;
        _error = err;
      });
      return;
    }
    final offline = !ConnectivityService().hasLink;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(offline
          ? 'Saved on this phone — will sync automatically.'
          : (_isEdit ? 'Hymn saved.' : 'Hymn added to the library.')),
      duration: const Duration(seconds: 2),
    ));
    Navigator.of(context).pop(true);
  }

  InputDecoration _deco(String label, {String? hint, IconData? icon}) {
    return InputDecoration(
      labelText: label,
      hintText: hint,
      isDense: true,
      prefixIcon: icon != null ? Icon(icon, size: 18) : null,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_isEdit ? 'Edit Hymn' : 'Add Hymn'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          TextButton(
            onPressed: _saving ? null : _save,
            child: _saving
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: Colors.white))
                : const Text('SAVE',
                    style: TextStyle(
                        color: Colors.white, fontWeight: FontWeight.w700)),
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_error != null)
            Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: Colors.red.withOpacity(0.08),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(children: [
                const Icon(Icons.error_outline, size: 16, color: Colors.red),
                const SizedBox(width: 8),
                Expanded(
                    child: Text(_error!,
                        style: const TextStyle(fontSize: 12, color: Colors.red))),
              ]),
            ),
          TextField(
              controller: _titleCtrl,
              maxLength: 255,
              decoration: _deco('Title *', icon: Icons.music_note)),
          const SizedBox(height: 10),
          TextField(
              controller: _titleAmCtrl,
              maxLength: 255,
              decoration: _deco('Amharic title (አማርኛ ርዕስ)',
                  icon: Icons.translate)),
          const SizedBox(height: 10),
          DropdownButtonFormField<String>(
            value: _category.isEmpty ? null : _category,
            decoration: _deco('Category', icon: Icons.category_outlined),
            items: [
              for (final c in _categories)
                DropdownMenuItem(
                    value: '${c['name']}', child: Text('${c['name']}')),
              const DropdownMenuItem(value: 'general', child: Text('general')),
            ],
            onChanged: (v) => setState(() => _category = v ?? ''),
          ),
          const SizedBox(height: 10),
          TextField(
              controller: _referenceCtrl,
              maxLength: 255,
              decoration: _deco('Reference (መጽሐፍ ቅዱስ ማጣቀሻ)',
                  icon: Icons.book_outlined)),
          const SizedBox(height: 10),
          TextField(
            controller: _lyricsCtrl,
            maxLines: 14,
            minLines: 6,
            keyboardType: TextInputType.multiline,
            decoration: _deco('Lyrics (ግጥም)',
                hint: 'Type or paste the full lyrics…',
                icon: Icons.notes_outlined),
          ),
          const SizedBox(height: 6),
          Text(
            'Works fully offline — changes sync automatically when the phone is back online.',
            style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}
