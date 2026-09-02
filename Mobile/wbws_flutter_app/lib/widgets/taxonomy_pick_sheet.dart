import 'package:flutter/material.dart';

import '../utils/theme.dart';

/// Searchable multi-select picker (P28, item 10).
///
/// Replaces the editor's FilterChip walls: a taxonomy at 100k-hymn scale
/// can hold hundreds of entries — chips don't scale and give no overview.
/// UX research (insaim / uxpin): put a search box INSIDE the picker when
/// the option list is long, keep touch targets tall, and show how many
/// are selected on the field itself.
///
/// Two entry points:
///  - [TaxonomyPickField]: the tappable form field (summary of the
///    selection + chevron) used inside forms.
///  - [showTaxonomyPickSheet]: the bottom sheet itself — multi-select by
///    default, single-select when [single] is true (filter sheet).
Future<Set<int>?> showTaxonomyPickSheet(
  BuildContext context, {
  required String title,
  required List<Map<String, dynamic>> items,
  required Set<int> selected,
  bool single = false,
  Map<int, int>? counts,
}) {
  return showModalBottomSheet<Set<int>>(
    context: context,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
    ),
    builder: (ctx) => _PickSheet(
        title: title, items: items, selected: selected, single: single, counts: counts),
  );
}

class _PickSheet extends StatefulWidget {
  const _PickSheet({
    required this.title,
    required this.items,
    required this.selected,
    required this.single,
    this.counts,
  });

  final String title;
  final List<Map<String, dynamic>> items;
  final Set<int> selected;
  final bool single;
  final Map<int, int>? counts;

  @override
  State<_PickSheet> createState() => _PickSheetState();
}

class _PickSheetState extends State<_PickSheet> {
  late Set<int> _selected;
  final _searchCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _selected = {...widget.selected};
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  void _toggle(int id) {
    setState(() {
      if (widget.single) {
        _selected.clear();
        if (id > 0) _selected.add(id);
      } else if (_selected.contains(id)) {
        _selected.remove(id);
      } else {
        _selected.add(id);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final q = _searchCtrl.text.trim().toLowerCase();
    final visible = q.isEmpty
        ? widget.items
        : widget.items
            .where((it) =>
                '${it['name'] ?? ''}'.toLowerCase().contains(q) ||
                '${it['name_am'] ?? ''}'.toLowerCase().contains(q) ||
                '${it['group'] ?? ''}'.toLowerCase().contains(q))
            .toList();
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.72,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: Row(
                children: [
                  Expanded(
                    child: Text(widget.title,
                        style: const TextStyle(
                            fontSize: 14, fontWeight: FontWeight.w700)),
                  ),
                  TextButton(
                    onPressed: () => Navigator.of(context).pop(_selected),
                    child: Text(
                        widget.single ? 'DONE' : 'DONE (${_selected.length})'),
                  ),
                ],
              ),
            ),
            // Search inside the picker appears once the catalog is big
            // enough to need it (UX: search bar inside filter panels).
            if (widget.items.length > 8)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
                child: TextField(
                  controller: _searchCtrl,
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(
                    hintText: 'Search…',
                    isDense: true,
                    prefixIcon: const Icon(Icons.search, size: 18),
                    suffixIcon: _searchCtrl.text.isNotEmpty
                        ? IconButton(
                            icon: const Icon(Icons.clear, size: 16),
                            onPressed: () {
                              _searchCtrl.clear();
                              setState(() {});
                            },
                          )
                        : null,
                    border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(10)),
                  ),
                ),
              ),
            Expanded(
              child: visible.isEmpty
                  ? Center(
                      child: Text(
                        q.isEmpty ? 'Nothing yet.' : 'No matches for “$q”.',
                        style: TextStyle(
                            fontSize: 12.5, color: AppTheme.textSecondary),
                      ),
                    )
                  : ListView.builder(
                      itemCount: visible.length,
                      itemBuilder: (ctx, i) {
                        final it = visible[i];
                        // P30: grouped entries (two-level taxonomy) get a
                        // small group header before the first row.
                        final group = it['group'];
                        final prevGroup = i > 0 ? visible[i - 1]['group'] : null;
                        final showHeader =
                            group != null && group != prevGroup;
                        final id =
                            it['id'] is int ? it['id'] as int : int.tryParse('${it['id']}') ?? 0;
                        final count = widget.counts?[id];
                        final checked = _selected.contains(id);
                        final tile = ListTile(
                          dense: true,
                          contentPadding:
                              const EdgeInsets.symmetric(horizontal: 16),
                          onTap: widget.single
                              ? () => Navigator.of(context).pop({id})
                              : () => _toggle(id),
                          leading: widget.single
                              ? Icon(
                                  checked
                                      ? Icons.radio_button_checked
                                      : Icons.radio_button_off,
                                  size: 20,
                                  color: checked
                                      ? AppTheme.primary
                                      : AppTheme.textSecondary,
                                )
                              : Icon(
                                  checked
                                      ? Icons.check_box
                                      : Icons.check_box_outline_blank,
                                  size: 20,
                                  color: checked
                                      ? AppTheme.primary
                                      : AppTheme.textSecondary,
                                ),
                          title: Text('${it['name'] ?? ''}',
                              style: const TextStyle(fontSize: 13.5)),
                          // P36 audit: singers now carry ONE Amharic name
                          // (name_am mirrors name) — showing both would
                          // print the same word twice. The subtitle only
                          // appears when it adds information.
                          subtitle: '${it['name_am'] ?? ''}'.isEmpty ||
                                  it['name_am'] == it['name']
                              ? null
                              : Text('${it['name_am']}',
                                  style: TextStyle(
                                      fontSize: 11,
                                      color: AppTheme.textSecondary)),
                          trailing: count == null
                              ? null
                              : Text('$count',
                                  style: TextStyle(
                                      fontSize: 11,
                                      color: AppTheme.textSecondary)),
                        );
                        if (!showHeader) return tile;
                        return Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Padding(
                              padding:
                                  const EdgeInsets.fromLTRB(16, 10, 16, 2),
                              child: Text('$group'.toUpperCase(),
                                  style: TextStyle(
                                      fontSize: 10.5,
                                      fontWeight: FontWeight.w800,
                                      letterSpacing: 0.6,
                                      color: AppTheme.textSecondary)),
                            ),
                            tile,
                          ],
                        );
                      },
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Tappable form field that opens [showTaxonomyPickSheet] and shows the
/// current selection as a wrap of small chips inside the field.
class TaxonomyPickField extends StatelessWidget {
  const TaxonomyPickField({
    super.key,
    required this.label,
    required this.items,
    required this.selected,
    required this.icon,
    this.onManage,
    this.counts,
    this.single = false,
    this.onChanged,
  });

  final String label;
  final List<Map<String, dynamic>> items;
  final Set<int> selected;
  final IconData icon;
  final VoidCallback? onManage;
  final Map<int, int>? counts;
  final bool single;
  final ValueChanged<Set<int>>? onChanged;

  String _nameOf(int id) {
    for (final it in items) {
      final iid = it['id'] is int ? it['id'] as int : int.tryParse('${it['id']}') ?? 0;
      if (iid == id) return '${it['name'] ?? ''}';
    }
    return '#$id';
  }

  Future<void> _open(BuildContext context) async {
    final result = await showTaxonomyPickSheet(context,
        title: label,
        items: items,
        selected: single ? {...selected} : selected,
        single: single,
        counts: counts);
    if (result != null) onChanged?.call(result);
  }

  @override
  Widget build(BuildContext context) {
    final names = selected.map(_nameOf).toList()..sort();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(icon, size: 16, color: AppTheme.textSecondary),
            const SizedBox(width: 6),
            Expanded(
              child: Text(label,
                  style: TextStyle(
                      fontSize: 12.5, color: AppTheme.textSecondary)),
            ),
            if (onManage != null)
              TextButton(
                  onPressed: onManage, child: const Text('Manage')),
          ],
        ),
        const SizedBox(height: 6),
        InkWell(
          onTap: () => _open(context),
          borderRadius: BorderRadius.circular(10),
          child: InputDecorator(
            decoration: InputDecoration(
              isDense: true,
              border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(10)),
              contentPadding:
                  const EdgeInsets.fromLTRB(12, 10, 10, 8),
              suffixIcon: const Icon(Icons.expand_more, size: 18),
            ),
            child: names.isEmpty
                ? Text(single ? 'Any' : 'None selected',
                    style: TextStyle(
                        fontSize: 12.5, color: AppTheme.textSecondary))
                : Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      for (final n in names)
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: AppTheme.primary.withOpacity(0.08),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(n,
                              style: TextStyle(
                                  fontSize: 11.5,
                                  color: AppTheme.primary,
                                  fontWeight: FontWeight.w600)),
                        ),
                    ],
                  ),
          ),
        ),
      ],
    );
  }
}
