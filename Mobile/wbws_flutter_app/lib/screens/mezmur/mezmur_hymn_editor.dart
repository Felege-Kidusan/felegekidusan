import 'package:flutter/material.dart';

import '../../services/connectivity_service.dart';
import '../../services/hymn_store.dart';
import '../../utils/theme.dart';
import 'mezmur_zemarians.dart';

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
  List<Map<String, dynamic>> _zemarians = [];
  final Set<int> _selectedCategories = {};
  final Set<int> _selectedZemarians = {};
  String _length = 'long';
  String _language = 'amharic';
  bool _saving = false;
  String? _error;

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

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
      _referenceCtrl.text = '${h['reference'] ?? ''}';
      _lyricsCtrl.text = '${h['lyrics'] ?? ''}';
      _length = '${h['length'] ?? 'long'}';
      _language = '${h['language'] ?? 'amharic'}';
    }
    _loadCatalog();
  }

  Future<void> _loadCatalog() async {
    final cats = await _store.categories();
    final zem = await _store.zemarians();
    if (_isEdit) {
      _selectedCategories.addAll(await _store.hymnCategoryIds(_localRowId));
      _selectedZemarians.addAll(await _store.hymnZemarianIds(_localRowId));
    }
    if (!mounted) return;
    setState(() {
      _categories = cats;
      _zemarians = zem;
    });
  }

  String _primaryCategoryName() {
    for (final c in _categories) {
      if (_selectedCategories.contains(_asInt(c['id']))) return '${c['name']}';
    }
    return '';
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
      'category': _primaryCategoryName().isEmpty ? 'general' : _primaryCategoryName(),
      'categories': _selectedCategories.toList(),
      'zemarians': _selectedZemarians.toList(),
      'length': _length,
      'language': _language,
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

  Widget _multiSelectSection(
    String label,
    List<Map<String, dynamic>> items,
    Set<int> selected,
    IconData icon, {
    VoidCallback? onManage,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(icon, size: 16, color: AppTheme.textSecondary),
            const SizedBox(width: 6),
            Expanded(
              child: Text(label,
                  style: const TextStyle(
                      fontSize: 12.5, color: AppTheme.textSecondary)),
            ),
            if (onManage != null)
              TextButton(onPressed: onManage, child: const Text('Manage')),
          ],
        ),
        const SizedBox(height: 6),
        if (items.isEmpty)
          const Text('Nothing yet — add one first.',
              style: TextStyle(fontSize: 11.5, color: AppTheme.textSecondary))
        else
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final it in items)
                FilterChip(
                  label: Text('${it['name']}',
                      style: const TextStyle(fontSize: 12)),
                  selected: selected.contains(_asInt(it['id'])),
                  onSelected: (on) {
                    setState(() {
                      if (on) {
                        selected.add(_asInt(it['id']));
                      } else {
                        selected.remove(_asInt(it['id']));
                      }
                    });
                  },
                ),
            ],
          ),
      ],
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
          _multiSelectSection('Categories (one or more)', _categories,
              _selectedCategories, Icons.category_outlined),
          const SizedBox(height: 12),
          _multiSelectSection('Singers / Zemarians (one or more)', _zemarians,
              _selectedZemarians, Icons.person_outline,
              onManage: () async {
                await Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => const MezmurZemariansScreen()));
                await _loadCatalog();
              }),
          const SizedBox(height: 10),
          Row(
            children: [
              Expanded(
                child: DropdownButtonFormField<String>(
                  value: _length,
                  decoration: _deco('Length', icon: Icons.timeline),
                  items: const [
                    DropdownMenuItem(value: 'long', child: Text('Long')),
                    DropdownMenuItem(value: 'short', child: Text('Short')),
                  ],
                  onChanged: (v) => setState(() => _length = v ?? 'long'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: DropdownButtonFormField<String>(
                  value: _language,
                  decoration: _deco('Language', icon: Icons.language),
                  items: const [
                    DropdownMenuItem(value: 'amharic', child: Text('Amharic (አማርኛ)')),
                    DropdownMenuItem(value: 'geez', child: Text('Geez (ግዕዝ)')),
                  ],
                  onChanged: (v) => setState(() => _language = v ?? 'amharic'),
                ),
              ),
            ],
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
            'Styling: [Verse 1] section header · **bold** · *italic* — rendered like Genius/Spotify.',
            style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
          ),
          const SizedBox(height: 2),
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
