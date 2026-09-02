import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/hymn_store.dart';
import '../../services/local_db.dart';
import '../../utils/theme.dart';
import '../../widgets/loading_skeleton.dart';
import 'mezmur_hymn_editor.dart';
import 'mezmur_hymns.dart';

/// Single hymn reader — LOCAL-FIRST: opens instantly from the on-device
/// copy; when the lyrics blob has not been downloaded yet it streams it
/// in the background and persists it for future offline reading.
class MezmurHymnDetailScreen extends StatefulWidget {
  final int id;
  const MezmurHymnDetailScreen({super.key, required this.id});
  @override
  State<MezmurHymnDetailScreen> createState() => _MezmurHymnDetailState();
}

class _MezmurHymnDetailState extends State<MezmurHymnDetailScreen> {
  final _store = HymnStore();
  final _api = ApiService();
  final _db = LocalDb();

  Map<String, dynamic>? _hymn;
  bool _fetchingLyrics = false;
  List<Map<String, dynamic>> _cats = [];
  List<Map<String, dynamic>> _zems = [];

  @override
  void initState() {
    super.initState();
    _store.addListener(_reload);
    _open();
  }

  @override
  void dispose() {
    _store.removeListener(_reload);
    super.dispose();
  }

  Future<void> _reload() async {
    final h = await _store.hymn(widget.id);
    final cats = await _store.categoryNamesFor(widget.id);
    final zems = await _store.zemarianNamesFor(widget.id);
    if (!mounted) return;
    setState(() {
      _hymn = h;
      _cats = cats;
      _zems = zems;
    });
  }

  /// Tap a category / singer chip -> the filtered hymn list (P24).
  Future<void> _browse(int id, {required bool singer}) async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => MezmurHymnsScreen(
            initialCategoryId: singer ? null : id,
            initialZemarianId: singer ? id : null)));
    await _reload();
  }

  Future<void> _open() async {
    await _reload();
    final h = _hymn;
    // Lazy lyrics blob: stream once, then cached for offline reading.
    if (h != null && h['lyrics'] == null && ConnectivityService().hasLink) {
      setState(() => _fetchingLyrics = true);
      try {
        final res = await _api.getMezmurHymn(widget.id);
        if (res.success && res.data is Map && res.data['item'] is Map) {
          final item = Map<String, dynamic>.from(res.data['item']);
          await _db.upsertHymns([item]);
          await _reload();
        }
      } catch (_) {}
      if (mounted) setState(() => _fetchingLyrics = false);
    }
  }

  int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;

  Future<void> _edit() async {
    final h = _hymn;
    if (h == null) return;
    await Navigator.of(context).push<bool>(MaterialPageRoute(
        builder: (_) => MezmurHymnEditorScreen(hymn: h)));
    await _reload();
  }

  Future<void> _toggleArchive() async {
    final h = _hymn;
    if (h == null) return;
    final archived = '${h['status']}' == 'archived';
    final err = await _store.setHymnStatus(
        widget.id, archived ? 'active' : 'archived');
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(err ?? (archived ? 'Hymn restored.' : 'Hymn archived.')),
      duration: const Duration(seconds: 2),
    ));
    await _reload();
  }

  @override
  Widget build(BuildContext context) {
    final h = _hymn;
    final lyrics = h == null ? null : '${h['lyrics'] ?? ''}';
    final archived = h != null && '${h['status']}' == 'archived';
    return Scaffold(
      appBar: AppBar(
        title: Text('${h?['title'] ?? 'Hymn'}',
            style: const TextStyle(fontSize: 15),
            maxLines: 1,
            overflow: TextOverflow.ellipsis),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
        actions: [
          if (_store.canEdit && h != null)
            IconButton(
              tooltip: 'Edit hymn',
              icon: const Icon(Icons.edit_outlined, size: 19),
              onPressed: _edit,
            ),
          if (_store.canEdit && h != null)
            IconButton(
              tooltip: archived ? 'Restore hymn' : 'Archive hymn',
              icon: Icon(
                  archived ? Icons.unarchive_outlined : Icons.archive_outlined,
                  size: 19),
              onPressed: _toggleArchive,
            ),
          const SizedBox(width: 4),
        ],
      ),
      body: h == null
          ? const StudentListSkeleton()
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final c in _cats)
                      ActionChip(
                        tooltip: 'Hymns in this category',
                        avatar: const Icon(Icons.category_outlined,
                            size: 13, color: AppTheme.primary),
                        label: Text('${c['name']}',
                            style: const TextStyle(fontSize: 11)),
                        onPressed: () =>
                          _browse(_asInt(c['id']), singer: false),
                      ),
                    if (_cats.isEmpty && '${h['category'] ?? ''}'.isNotEmpty)
                      Chip(
                          label: Text('${h['category']}',
                              style: const TextStyle(fontSize: 11))),
                    for (final z in _zems)
                      ActionChip(
                        tooltip: 'Hymns by this singer',
                        avatar: const Icon(Icons.person_outline,
                            size: 13, color: AppTheme.info),
                        label: Text('${z['name']}',
                            style: const TextStyle(fontSize: 11)),
                        onPressed: () => _browse(_asInt(z['id']), singer: true),
                      ),
                    if (archived)
                      const Chip(
                          label: Text('Archived',
                              style: TextStyle(fontSize: 11))),
                  ],
                ),
                const SizedBox(height: 14),
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: AppTheme.surfaceLight,
                    border: Border.all(color: AppTheme.borderLight),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: lyrics == null || lyrics.isEmpty
                      ? Row(children: [
                          if (_fetchingLyrics)
                            const SizedBox(
                                width: 14,
                                height: 14,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2))
                          else
                            const Icon(Icons.cloud_download_outlined,
                                size: 15, color: AppTheme.textSecondary),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              _fetchingLyrics
                                  ? 'Downloading lyrics…'
                                  : 'Lyrics not downloaded yet — open while online once, or wait for background sync.',
                              style: TextStyle(
                                  fontSize: 12.5,
                                  color: AppTheme.textSecondary),
                            ),
                          ),
                        ])
                      : _LyricsView(lyrics),
                ),
              ],
            ),
    );
  }
}

/// P24: Genius/Spotify-style lyrics rendering (plain text in, styled
/// blocks out): [Section] lines become headers, **bold** / *italic*
/// become spans, blank lines become stanza spacing. Nothing is stored
/// transformed — parsing happens at render time only.
class _LyricsView extends StatelessWidget {
  final String src;
  const _LyricsView(this.src);

  static final _sectionRe = RegExp(r'^\[(.+)\]$');
  static final _inlineRe = RegExp(r'\*\*(.+?)\*\*|\*(.+?)\*');

  List<Widget> _build() {
    final out = <Widget>[];
    final buf = <TextSpan>[];
    void flush() {
      if (buf.isEmpty) return;
      out.add(Text.rich(
        TextSpan(children: List<TextSpan>.from(buf)),
        style: const TextStyle(fontSize: 15, height: 1.9),
      ));
      buf.clear();
      out.add(const SizedBox(height: 14));
    }

    for (final raw in src.split('\n')) {
      final line = raw.trim();
      if (line.isEmpty) {
        flush();
        continue;
      }
      final m = _sectionRe.firstMatch(line);
      if (m != null) {
        flush();
        out.add(Padding(
          padding: const EdgeInsets.only(top: 10, bottom: 4),
          child: Text(
            m.group(1)!.toUpperCase(),
            style: TextStyle(
              fontSize: 12.5,
              fontWeight: FontWeight.w800,
              letterSpacing: 0.7,
              color: AppTheme.primary,
            ),
          ),
        ));
        continue;
      }
      final spans = <TextSpan>[];
      var rest = line;
      // Tokenize bold/italic left-to-right; plain text between matches.
      while (true) {
        final m2 = _inlineRe.firstMatch(rest);
        if (m2 == null) {
          if (rest.isNotEmpty) spans.add(TextSpan(text: rest));
          break;
        }
        if (m2.start > 0) {
          spans.add(TextSpan(text: rest.substring(0, m2.start)));
        }
        if (m2.group(1) != null) {
          spans.add(TextSpan(
              text: m2.group(1),
              style: const TextStyle(fontWeight: FontWeight.w800)));
        } else {
          spans.add(TextSpan(
              text: m2.group(2),
              style: const TextStyle(fontStyle: FontStyle.italic)));
        }
        rest = rest.substring(m2.end);
      }
      spans.add(const TextSpan(text: '\n'));
      buf.addAll(spans);
    }
    flush();
    return out;
  }

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: _build(),
      );
}
