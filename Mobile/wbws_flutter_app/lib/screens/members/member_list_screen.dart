import 'package:flutter/material.dart';
import '../../widgets/loading_skeleton.dart';
import '../../utils/transitions.dart';
import '../../services/api_service.dart';
import '../../services/local_db.dart';
import '../../utils/theme.dart';
import 'member_detail_screen.dart';

class MemberListScreen extends StatefulWidget {
  const MemberListScreen({super.key});

  @override
  State<MemberListScreen> createState() => _MemberListScreenState();
}

class _MemberListScreenState extends State<MemberListScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  final _searchController = TextEditingController();
  final _scrollController = ScrollController();

  List<dynamic> _members = [];
  int _page = 1;
  int _totalPages = 1;
  bool _loading = false;
  bool _loadingMore = false;
  bool _isOffline = false;
  String? _error;
  String _statusFilter = '';

  @override
  void initState() {
    super.initState();
    _loadMembers();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _searchController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (!_isOffline &&
        _scrollController.position.pixels >=
            _scrollController.position.maxScrollExtent - 200) {
      _loadMore();
    }
  }

  Future<void> _loadMembers({bool refresh = false}) async {
    if (refresh) {
      _page = 1;
      _members.clear();
    }

    setState(() {
      _loading = _members.isEmpty;
      _error = null;
    });

    final res = await _api.getMembers(
      page: _page,
      search: _searchController.text.trim(),
      status: _statusFilter.isEmpty ? null : _statusFilter,
    );

    if (!mounted) return;

    if (res.success && res.data != null) {
      final items = res.data['items'] as List? ?? [];
      final pagination = res.data['pagination'] as Map? ?? {};
      setState(() {
        if (refresh || _page == 1) _members = items;
        else _members.addAll(items);
        _totalPages = pagination['pages'] ?? 1;
        _loading = false;
        _loadingMore = false;
        _isOffline = false;
      });
      // Cache members for offline use
      if (items.isNotEmpty) {
        _db.cacheMembers(items);
      }
    } else {
      // Fallback to cached members
      final cached = await _db.getCachedMembers(
        search: _searchController.text.trim().isEmpty
            ? null
            : _searchController.text.trim(),
        status: _statusFilter.isEmpty ? null : _statusFilter,
      );
      if (cached.isNotEmpty) {
        setState(() {
          _members = cached;
          _loading = false;
          _loadingMore = false;
          _isOffline = true;
        });
      } else {
        setState(() {
          _error = res.isNetworkError
              ? 'No internet and no cached members'
              : res.message;
          _loading = false;
          _loadingMore = false;
          _isOffline = res.isNetworkError;
        });
      }
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || _page >= _totalPages || _isOffline) return;
    setState(() => _loadingMore = true);
    _page++;
    await _loadMembers();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Members'),
        automaticallyImplyLeading: Navigator.canPop(context),
        actions: [
          IconButton(
              icon: const Icon(Icons.refresh, size: 20),
              onPressed: () => _loadMembers(refresh: true)),
        ],
      ),
      body: Column(
        children: [
          // Offline indicator
          if (_isOffline)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              color: AppTheme.warning.withOpacity(0.12),
              child: Row(
                children: [
                  Icon(Icons.cloud_off, size: 14, color: AppTheme.warning),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Showing cached members — pull to refresh when online',
                      style: TextStyle(
                          fontSize: 11,
                          color: AppTheme.warning,
                          fontWeight: FontWeight.w500),
                    ),
                  ),
                ],
              ),
            ),

          // Search bar
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: 'Search members...',
                prefixIcon: const Icon(Icons.search, size: 20),
                suffixIcon: _searchController.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear, size: 18),
                        onPressed: () {
                          _searchController.clear();
                          _loadMembers(refresh: true);
                        },
                      )
                    : null,
                contentPadding:
                    const EdgeInsets.symmetric(vertical: 0, horizontal: 16),
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
              onSubmitted: (_) => _loadMembers(refresh: true),
              textInputAction: TextInputAction.search,
            ),
          ),

          // Filter chips
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  _filterChip('All', ''),
                  _filterChip('Active', 'active'),
                  _filterChip('Warning', 'warning'),
                  _filterChip('Inactive', 'inactive'),
                ],
              ),
            ),
          ),

          // List
          Expanded(
            child: _loading
                ? const MemberListSkeleton()
                : _error != null
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.all(32),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(
                                _isOffline
                                    ? Icons.cloud_off
                                    : Icons.error_outline,
                                size: 48,
                                color: _isOffline
                                    ? AppTheme.warning
                                    : AppTheme.danger,
                              ),
                              const SizedBox(height: 12),
                              Text(_error!,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                      color: _isOffline
                                          ? AppTheme.warning
                                          : AppTheme.danger,
                                      fontSize: 14)),
                              const SizedBox(height: 12),
                              ElevatedButton.icon(
                                onPressed: () =>
                                    _loadMembers(refresh: true),
                                icon: const Icon(Icons.refresh, size: 18),
                                label: const Text('Retry'),
                              ),
                            ],
                          ),
                        ),
                      )
                    : _members.isEmpty
                        ? const Center(
                            child: Text('No members found',
                                style: TextStyle(color: Colors.grey)))
                        : RefreshIndicator(
                            onRefresh: () => _loadMembers(refresh: true),
                            child: ListView.builder(
                              controller: _scrollController,
                              itemCount: _members.length +
                                  (_loadingMore ? 1 : 0),
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 16),
                              itemBuilder: (context, index) {
                                if (index == _members.length) {
                                  return const Padding(
                                    padding: EdgeInsets.all(16),
                                    child: Center(
                                        child:
                                            CircularProgressIndicator(
                                                strokeWidth: 2)),
                                  );
                                }
                                return _memberCard(_members[index]);
                              },
                            ),
                          ),
          ),
        ],
      ),
    );
  }

  Widget _filterChip(String label, String value) {
    final selected = _statusFilter == value;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: FilterChip(
        label: Text(label,
            style: TextStyle(
                fontSize: 12, color: selected ? Colors.white : null)),
        selected: selected,
        onSelected: (_) {
          setState(() => _statusFilter = value);
          _loadMembers(refresh: true);
        },
        selectedColor: AppTheme.primary,
        checkmarkColor: Colors.white,
        side: BorderSide(
            color: selected ? AppTheme.primary : AppTheme.borderLight),
        shape:
            RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
      ),
    );
  }

  Widget _memberCard(Map<String, dynamic> member) {
    final status = member['status'] ?? 'active';
    final statusColor = status == 'active'
        ? AppTheme.success
        : status == 'warning'
            ? AppTheme.warning
            : AppTheme.danger;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        onTap: _isOffline
            ? null // Disable detail navigation when offline
            : () => Navigator.push(
                  context,
                  SmoothPageRoute(page:
                          MemberDetailScreen(memberId: member['id'])),
                ),
        borderRadius: BorderRadius.circular(16),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              // Avatar
              CircleAvatar(
                radius: 22,
                backgroundColor: AppTheme.primary.withOpacity(0.15),
                child: Text(
                  (member['student_name'] ?? '?')[0].toUpperCase(),
                  style: const TextStyle(
                      color: AppTheme.primary,
                      fontWeight: FontWeight.w600,
                      fontSize: 16),
                ),
              ),
              const SizedBox(width: 12),

              // Info
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${member['student_name'] ?? ''} ${member['father_name'] ?? ''}',
                      style: const TextStyle(
                          fontWeight: FontWeight.w600, fontSize: 14),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 3),
                    Row(
                      children: [
                        if (member['member_code'] != null) ...[
                          Text(member['member_code'],
                              style: TextStyle(
                                  fontSize: 11,
                                  color: AppTheme.textSecondary)),
                          const SizedBox(width: 8),
                        ],
                        if (member['gender'] != null)
                          Icon(
                              member['gender'] == 'male'
                                  ? Icons.male
                                  : Icons.female,
                              size: 14,
                              color: AppTheme.textSecondary),
                        const SizedBox(width: 4),
                        if (member['current_section'] != null)
                          Text(member['current_section'],
                              style: TextStyle(
                                  fontSize: 11,
                                  color: AppTheme.textSecondary)),
                      ],
                    ),
                  ],
                ),
              ),

              // Status badge
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: statusColor.withOpacity(0.15),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(status,
                    style: TextStyle(
                        color: statusColor,
                        fontSize: 10,
                        fontWeight: FontWeight.w600)),
              ),

              const SizedBox(width: 4),
              if (!_isOffline)
                Icon(Icons.chevron_right,
                    size: 18, color: AppTheme.textSecondary),
            ],
          ),
        ),
      ),
    );
  }
}


