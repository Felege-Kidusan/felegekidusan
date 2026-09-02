import 'package:flutter/material.dart';

import '../../services/connectivity_service.dart';
import '../../services/hymn_store.dart';
import '../../utils/theme.dart';
import '../../widgets/taxonomy_pick_sheet.dart';
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

  /// P26: when true the form renders WITHOUT its own Scaffold — it is
  /// embedded as the "Add" tab inside the hymn library screen (no
  /// navigation, saving resets the form for the next entry).
  final bool embedded;

  /// Called after a successful save in embedded mode (the host usually
  /// switches to the Hymns tab so the new row is immediately visible).
  final VoidCallback? onSaved;
  const MezmurHymnEditorScreen(
      {super.key, this.hymn, this.embedded = false, this.onSaved});

  @override
  State<MezmurHymnEditorScreen> createState() => _MezmurHymnEditorState();
}

class _MezmurHymnEditorState extends State<MezmurHymnEditorScreen> {
  final _store = HymnStore();
  final _titleCtrl = TextEditingController();
  final _lyricsCtrl = TextEditingController();

  static const _lyricsMax = 200000;

  List<Map<String, dynamic>> _categories = [];
  List<Map<String, dynamic>> _zemarians = [];
  final Set<int> _selectedCategories = {};
  final Set<int> _selectedZemarians = {};

  /// P33 cascading picks (web parity): category first, then one of
  /// ITS sub-categories. _selectedCategories holds the single leaf.
  int? _mainCatId;
  int? _subCatId;
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
      _lyricsCtrl.text = '${h['lyrics'] ?? ''}';
      _length = '${h['length'] ?? 'long'}';
      _language = '${h['language'] ?? 'amharic'}';
    }
    _loadCatalog();
  }

  /// Cascading pickers: mains, then the chosen main's subs.
  List<Map<String, dynamic>> get _mains =>
      _categories.where((c) => c['parent_id'] == null).toList();

  List<Map<String, dynamic>> get _subsOfMain => _mainCatId == null
      ? const []
      : _categories
          .where((c) =>
              c['parent_id'] != null &&
              _asInt(c['parent_id']) == _mainCatId)
          .toList();

  void _syncSelected() {
    _selectedCategories.clear();
    final leaf = _subCatId ?? (_subsOfMain.isEmpty ? _mainCatId : null);
    if (leaf != null) _selectedCategories.add(leaf);
  }

  Future<void> _loadCatalog() async {
    final cats = await _store.categories();
    final zem = await _store.zemarians();
    if (_isEdit) {
      _selectedCategories.addAll(await _store.hymnCategoryIds(_localRowId));
      _selectedZemarians.addAll(await _store.hymnZemarianIds(_localRowId));
      // Prefill the cascade from the hymn's existing link: a sub opens
      // its parent + itself; a main opens itself (subs, if any, stay
      // unset — the curator narrows it on save).
      if (_selectedCategories.isNotEmpty) {
        final linked = _selectedCategories.first;
        for (final c in cats) {
          if (_asInt(c['id']) == linked) {
            final pid = c['parent_id'];
            if (pid != null && _asInt(pid) > 0) {
              _mainCatId = _asInt(pid);
              _subCatId = linked;
            } else {
              _mainCatId = linked;
            }
            break;
          }
        }
      }
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
    if (_selectedCategories.isEmpty) {
      setState(() =>
          _error = 'Choose a category and sub-category for the hymn.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });

    final hymn = <String, dynamic>{
      if (_isEdit) 'id': _localRowId,
      'title': title,
      'category': _primaryCategoryName().isEmpty ? 'general' : _primaryCategoryName(),
      'categories': _selectedCategories.toList(),
      'zemarians': _selectedZemarians.toList(),
      'length': _length,
      'language': _language,
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
    if (widget.embedded) {
      // Stay on the tab, reset for the next entry (P26).
      setState(() {
        _saving = false;
        _titleCtrl.clear();
        _lyricsCtrl.clear();
        _selectedCategories.clear();
        _selectedZemarians.clear();
        _mainCatId = null;
        _subCatId = null;
        _length = 'long';
        _language = 'amharic';
      });
      widget.onSaved?.call();
      return;
    }
    Navigator.of(context).pop(true);
  }

  /// The second cascade step: disabled until a category is chosen; a
  /// main WITHOUT subs files the hymn under the main itself.
  Widget _subCategoryField() {
    final subs = _subsOfMain;
    if (_mainCatId == null) {
      // onChanged: null renders the field disabled.
      return DropdownButtonFormField<int>(
        decoration:
            _deco('Sub-category *', icon: Icons.subdirectory_arrow_right),
        hint: const Text('Select a category first…'),
        items: const [],
        onChanged: null,
      );
    }
    if (subs.isEmpty) {
      return DropdownButtonFormField<int>(
        value: _mainCatId,
        decoration:
            _deco('Sub-category', icon: Icons.subdirectory_arrow_right),
        items: [
          DropdownMenuItem(
              value: _mainCatId,
              child: const Text('No sub-categories — uses the main category',
                  overflow: TextOverflow.ellipsis)),
        ],
        onChanged: null,
      );
    }
    return DropdownButtonFormField<int>(
      value: subs.any((s) => _asInt(s['id']) == _subCatId) ? _subCatId : null,
      decoration:
          _deco('Sub-category *', icon: Icons.subdirectory_arrow_right),
      hint: const Text('— Select sub-category —'),
      items: [
        for (final s in subs)
          DropdownMenuItem(
              value: _asInt(s['id']),
              child: Text('${s['name'] ?? ''}',
                  overflow: TextOverflow.ellipsis)),
      ],
      onChanged: (v) => setState(() {
        _subCatId = v;
        _syncSelected();
      }),
    );
  }

  /// P33: visual styling toolbar for the lyrics — the same markup the
  /// reader renders (bold / italic / underline / section), applied to
  /// the current selection (web-editor parity on mobile).
  Widget _styleToolbar() {
    return Wrap(
      runSpacing: 6,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Padding(
          padding: const EdgeInsets.only(right: 6),
          child: OutlinedButton(
            style: OutlinedButton.styleFrom(
                minimumSize: const Size(40, 36), padding: EdgeInsets.zero),
            onPressed: () => _wrapSelection('**', '**'),
            child: const Tooltip(
                message: 'Bold', child: Icon(Icons.format_bold, size: 17)),
          ),
        ),
        Padding(
          padding: const EdgeInsets.only(right: 6),
          child: OutlinedButton(
            style: OutlinedButton.styleFrom(
                minimumSize: const Size(40, 36), padding: EdgeInsets.zero),
            onPressed: () => _wrapSelection('*', '*'),
            child: const Tooltip(
                message: 'Italic', child: Icon(Icons.format_italic, size: 17)),
          ),
        ),
        Padding(
          padding: const EdgeInsets.only(right: 6),
          child: OutlinedButton(
            style: OutlinedButton.styleFrom(
                minimumSize: const Size(40, 36), padding: EdgeInsets.zero),
            onPressed: () => _wrapSelection('__', '__'),
            child: const Tooltip(
                message: 'Underline',
                child: Icon(Icons.format_underline, size: 17)),
          ),
        ),
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(minimumSize: const Size(0, 36)),
          onPressed: _insertSection,
          icon: const Icon(Icons.title_outlined, size: 16),
          label: const Text('Section', style: TextStyle(fontSize: 12)),
        ),
      ],
    );
  }

  /// Wrap the selection (or drop markers at the cursor) with a style.
  void _wrapSelection(String left, String right) {
    final v = _lyricsCtrl.value;
    final text = v.text;
    final sel = v.selection;
    if (!sel.isValid) return;
    if (sel.isCollapsed) {
      final at = sel.baseOffset;
      _lyricsCtrl.value = v.copyWith(
        text: text.replaceRange(at, at, left + right),
        selection: TextSelection.collapsed(offset: at + left.length),
      );
    } else {
      final s = sel.start;
      final e = sel.end;
      final inner = text.substring(s, e);
      _lyricsCtrl.value = v.copyWith(
        text: text.replaceRange(s, e, left + inner + right),
        selection:
            TextSelection.collapsed(offset: e + left.length + right.length),
      );
    }
    setState(() {});
  }

  /// Insert a [Section] marker on its own line at the cursor.
  void _insertSection() {
    final v = _lyricsCtrl.value;
    final text = v.text;
    final sel = v.selection;
    final at = sel.isValid ? sel.baseOffset : text.length;
    final lineStart = text.lastIndexOf('\n', at == 0 ? 0 : at - 1) + 1;
    _lyricsCtrl.value = v.copyWith(
      text: text.replaceRange(lineStart, lineStart, '[]'),
      selection: TextSelection.collapsed(offset: lineStart + 1),
    );
    setState(() {});
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

  // P28 (item 10): dropdown-style pickers replace the FilterChip walls.
  // A taxonomy at scale holds hundreds of entries; chips give no search,
  // no overview and grow the form without bound. The picker opens a
  // searchable multi-select sheet (see taxonomy_pick_sheet.dart).
  Widget _emptyCatalogNote(String label, IconData icon) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(children: [
          Icon(icon, size: 16, color: AppTheme.textSecondary),
          const SizedBox(width: 6),
          Expanded(
            child: Text(label,
                style: TextStyle(
                    fontSize: 12.5, color: AppTheme.textSecondary)),
          ),
        ]),
        const SizedBox(height: 6),
        const Text('Nothing yet — add one first.',
            style: TextStyle(fontSize: 11.5, color: AppTheme.textSecondary)),
        const SizedBox(height: 12),
      ],
    );
  }

  /// The form fields, shared by the pushed screen and the embedded
  /// "Add" tab (P26).
  List<Widget> _formFields() {
    return [
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
              // P28 (item 9): ONE title — the Amharic name IS the name.
              decoration: _deco('Title (ርዕስ) *', icon: Icons.music_note)),
          const SizedBox(height: 14),
          // P30: hymns are categorized at the SUB level — the picker
          // shows subs grouped under their main category (a main with
          // no subs offers itself).
          // P33: cascading picks, mirroring the web form — choose the
          // category first, then one of ITS sub-categories.
          if (_categories.isEmpty)
            _emptyCatalogNote('Category', Icons.category_outlined)
          else ...[
            DropdownButtonFormField<int>(
              value: _mains.any((m) => _asInt(m['id']) == _mainCatId)
                  ? _mainCatId
                  : null,
              decoration: _deco('Category *', icon: Icons.category_outlined),
              hint: const Text('— Select category —'),
              items: [
                for (final m in _mains)
                  DropdownMenuItem(
                      value: _asInt(m['id']),
                      child: Text('${m['name'] ?? ''}',
                          overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() {
                _mainCatId = v;
                _subCatId = null;
                _syncSelected();
              }),
            ),
            const SizedBox(height: 12),
            _subCategoryField(),
          ],
          const SizedBox(height: 12),
          if (_zemarians.isEmpty)
            _emptyCatalogNote('Singers / Zemarians (one or more)',
                Icons.person_outline)
          else
            TaxonomyPickField(
              label: 'Singers / Zemarians (one or more)',
              items: _zemarians,
              selected: _selectedZemarians,
              icon: Icons.person_outline,
              onManage: () async {
                await Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => const MezmurZemariansScreen()));
                await _loadCatalog();
              },
              onChanged: (sel) =>
                  setState(() => _selectedZemarians..clear()..addAll(sel)),
            ),
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
          const SizedBox(height: 14),
          _styleToolbar(),
          const SizedBox(height: 6),
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
            'Style with the toolbar — section headers, bold, italic, underline.',
            style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
          ),
          const SizedBox(height: 2),
          Text(
            'Works fully offline — changes sync automatically when the phone is back online.',
            style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
          ),
          const SizedBox(height: 24),
    ];
  }

  @override
  Widget build(BuildContext context) {
    if (widget.embedded) {
      return SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ..._formFields(),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppTheme.primary,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: _saving ? null : _save,
                icon: _saving
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white))
                    : const Icon(Icons.save_outlined, size: 18),
                label: Text(_saving ? 'Saving…' : 'Save Hymn'),
              ),
            ),
            const SizedBox(height: 24),
          ],
        ),
      );
    }
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
        children: _formFields(),
      ),
    );
  }
}
