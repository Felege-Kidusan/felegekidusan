import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/loading_skeleton.dart';
import 'mezmur_hymn_detail.dart';

/// Mezmur hymn library — server-paginated browser (read-only on
/// mobile; editing stays on the web dashboard).
class MezmurHymnsScreen extends StatefulWidget {
  const MezmurHymnsScreen({super.key});
  @override
  State<MezmurHymnsScreen> createState() => MezmurHymnsScreenState();
}

class MezmurHymnsScreenState extends State<MezmurHymnsScreen> {
  final _api = ApiService();
  final _searchCtrl = TextEditingController();

  bool _loading = true;
  String? _error;
  List<dynamic> _items = [];
  List<dynamic> _categories = [];
  String _category = '';
  int _page = 1;
  int _totalPages = 1;
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  void refresh() => _load(page: 1);

  Future<void> _load({int page = 1}) async {
    if (page == 1) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    final res = await _api.getMezmurHymns(
      page: page,
      search: _searchCtrl.text.trim(),
      category: _category,
    );
    if (!mounted) return;
    if (!res.success) {
      setState(() {
        _loading = false;
        _error = res.message ?? 'Unable to load hymns.';
      });
      return;
    }
    final data = res.data ?? {};
    setState(() {
      _loading = false;
      _items = data['items'] ?? [];
      _categories = data['categories'] ?? [];
      _page = data['page'] ?? 1;
      _totalPages = data['total_pages'] ?? 1;
      _total = data['total'] ?? 0;
    });
  }

  Widget _buildBody() {
    if (_loading) return const StudentListSkeleton();
    if (_error != null) {
      return ListView(children: [
        AppErrorCard(
          error: AppError.fromMessage(_error),
          onRetry: () => _load(),
        ),
      ]);
    }
    if (_items.isEmpty) {
      return ListView(children: [
        const SizedBox(height: 60),
        EmptyState(
          icon: Icons.music_off_outlined,
          title: _searchCtrl.text.trim().isNotEmpty || _category.isNotEmpty
              ? 'No hymns match'
              : 'No hymns yet',
          subtitle: _searchCtrl.text.trim().isNotEmpty || _category.isNotEmpty
              ? 'Try a different search or category.'
              : 'Add hymns from the website dashboard.',
        ),
      ]);
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
      cacheExtent: kListCacheExtent,
      itemCount: _items.length + 1,
      itemBuilder: (context, i) {
        if (i == _items.length) return _pager();
        final h = _items[i];
        return RepaintBoundaryListItem(
          child: Card(
            margin: const EdgeInsets.only(bottom: 8),
            child: ListTile(
              onTap: () async {
                final id = h['id'] is int
                    ? h['id'] as int
                    : int.tryParse('${h['id']}');
                if (id == null) return;
                await Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => MezmurHymnDetailScreen(id: id),
                ));
              },
              leading: CircleAvatar(
                backgroundColor: AppTheme.primary.withOpacity(0.1),
                child: const Icon(Icons.music_note,
                    size: 18, color: AppTheme.primary),
              ),
              title: Text('${h['title'] ?? ''}',
                  style: const TextStyle(
                      fontSize: 13.5, fontWeight: FontWeight.w600),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis),
              subtitle: Text(
                '${h['title_am'] ?? ''}'
                '${(h['title_am'] ?? '').toString().isNotEmpty && (h['category'] ?? '').toString().isNotEmpty ? ' · ' : ''}'
                '${h['category'] ?? ''}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 11, color: AppTheme.textSecondary),
              ),
              trailing: h['status'] == 'archived'
                  ? const Icon(Icons.archive_outlined,
                      size: 16, color: AppTheme.textSecondary)
                  : const Icon(Icons.chevron_right, size: 18),
            ),
          ),
        );
      },
    );
  }

  Widget _pager() {
    if (_totalPages <= 1) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Center(
          child: Text('$_total hymns',
              style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
        ),
      );
    }
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          IconButton(
            onPressed: _page > 1 ? () => _load(page: _page - 1) : null,
            icon: const Icon(Icons.chevron_left),
          ),
          Text('Page $_page of $_totalPages',
              style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
          IconButton(
            onPressed:
                _page < _totalPages ? () => _load(page: _page + 1) : null,
            icon: const Icon(Icons.chevron_right),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Hymn Library · መዝሙር'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(),
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
              child: TextField(
                controller: _searchCtrl,
                onSubmitted: (_) => _load(),
                decoration: InputDecoration(
                  hintText: 'Search title, Amharic or reference…',
                  hintStyle: const TextStyle(fontSize: 13),
                  prefixIcon:
                      const Icon(Icons.search, size: 18),
                  suffixIcon: IconButton(
                    icon: const Icon(Icons.clear, size: 16),
                    onPressed: () {
                      _searchCtrl.clear();
                      _load();
                    },
                  ),
                  isDense: true,
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10)),
                ),
              ),
            ),
            if (_categories.isNotEmpty)
              SizedBox(
                height: 40,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  children: [
                    _chip('All', ''),
                    for (final c in _categories) _chip('$c', '$c'),
                  ],
                ),
              ),
            Expanded(child: _buildBody()),
          ],
        ),
      ),
    );
  }

  Widget _chip(String label, String value) {
    final on = _category == value;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
      child: FilterChip(
        label: Text(label, style: const TextStyle(fontSize: 11)),
        selected: on,
        onSelected: (_) {
          setState(() => _category = value);
          _load();
        },
      ),
    );
  }
}
