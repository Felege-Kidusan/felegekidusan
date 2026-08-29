import 'package:flutter/material.dart';
import '../../widgets/loading_skeleton.dart';
import '../../utils/transitions.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/fast_list.dart';
import 'member_detail_screen.dart';
import 'register_member_screen.dart';
import '../../widgets/qr_scan_sheet.dart';
import '../../services/qr_attendance.dart';
import '../../utils/config.dart';

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

  bool get _canRegister => const [
        UserRoles.infoDept, UserRoles.schoolAdmin, UserRoles.superAdmin]
    .contains(_api.userRole);

  /// Phase 9 QR lookup: scan a member card, resolve locally first
  /// (offline parity), then server; opens the member file directly.
  void _openLookup() {
    QrScanSheet.open(
      context,
      header: 'Scan member card',
      onScan: (raw) async {
        final code = QrAttendance.extractMemberCode(raw);
        if (code == null) return QrFeedback.invalid();
        final db = LocalDb();
        var member = await db.findCachedMemberByCode(code);
        int? id = member != null ? (member['id'] is int ? member['id'] as int : int.tryParse('${member['id']}')) : null;
        String name = member != null
            ? '${member['student_name'] ?? ''} ${member['father_name'] ?? ''}'
            : '';
        if (id == null) {
          final res = await _api.getMembers(search: code, limit: 5);
          if (res.success && res.data != null) {
            final items = (res.data!['items'] as List? ?? [])
                .whereType<Map>()
                .map((e) => Map<String, dynamic>.from(e))
                .toList();
            for (final m in items) {
              if ('${m['member_code'] ?? ''}' == code) {
                id = m['id'] is int ? m['id'] as int : int.tryParse('${m['id']}');
                name = '${m['student_name'] ?? ''} ${m['father_name'] ?? ''}';
                break;
              }
            }
          }
        }
        if (id != null && mounted) {
          final mid = id;
          Future.delayed(const Duration(milliseconds: 350), () {
            if (!mounted) return;
            Navigator.of(context).pop(); // close the sheet
            Navigator.of(context).push(MaterialPageRoute(
                builder: (_) => MemberDetailScreen(memberId: mid)));
          });
          return QrFeedback.memberFound(name: name.trim());
        }
        return QrFeedback.notFound();
      },
    );
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
          _isOffline = !ConnectivityService().hasLink;
        });
      } else {
        setState(() {
          _error = res.isNetworkError
              ? 'Waiting for network and no members saved on this phone'
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
    // Silent pagination: no setState for the flag and no inline footer row.
    // A full-screen rebuild mid-fling (plus itemCount churn) is exactly the
    // hitch Telegram avoids by appending quietly.
    _loadingMore = true;
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
          // Phase 9: scan a member-card QR to jump straight to the file.
          IconButton(
              tooltip: 'Scan member QR',
              icon: const Icon(Icons.qr_code_scanner, size: 20),
              onPressed: _openLookup),
          if (_canRegister)
            IconButton(
                tooltip: 'Register member',
                icon: const Icon(Icons.person_add_alt, size: 20),
                onPressed: () async {
                  final created = await Navigator.of(context).push(
                      MaterialPageRoute(
                          builder: (_) => const RegisterMemberScreen()));
                  if (created == true) _loadMembers(refresh: true);
                }),
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
                      'Waiting for network — showing members saved on this phone',
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
                        : RawScrollbar(
                            controller: _scrollController,
                            interactive: true,
                            thickness: 5,
                            radius: const Radius.circular(3),
                            thumbColor: const Color(0x595A1212),
                            child: RefreshIndicator(
                              onRefresh: () => _loadMembers(refresh: true),
                              child: ListView.builder(
                                controller: _scrollController,
                                itemCount: _members.length,
                                itemExtent: kFastRowHeight,
                                cacheExtent: kListCacheExtent,
                                addAutomaticKeepAlives: false,
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 12),
                                itemBuilder: (context, index) {
                                  // Flat zebra row — no Card/shadow/ripple.
                                  // Fixed extent lets the viewport skip
                                  // measuring (SliverFixedExtentList).
                                  return _memberCard(
                                      _members[index], index);
                                },
                              ),
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

  // Precomputed tint colors — withOpacity() allocates a new Color on every
  // row build; during a fast fling that is thousands of avoidable allocations.
  static const Color _avatarBg = Color(0x265A1212);
  static const Map<String, Color> _chipBg = {
    'active': Color(0x26059669),
    'warning': Color(0x26D97706),
    'danger': Color(0x26DC2626),
  };

  Widget _memberCard(Map<String, dynamic> member, int index) {
    final status = member['status'] ?? 'active';
    final statusColor = status == 'active'
        ? AppTheme.success
        : status == 'warning'
            ? AppTheme.warning
            : AppTheme.danger;
    final name = '${member['student_name'] ?? ''}';
    final initial =
        name.trim().isEmpty ? '?' : name.trim()[0].toUpperCase();

    return FastListRow(
      index: index,
      height: kFastRowHeight,
      padding: const EdgeInsets.symmetric(horizontal: 8),
      onTap: _isOffline
          ? null // Disable detail navigation when offline
          : () => Navigator.push(
                context,
                SmoothPageRoute(page:
                        MemberDetailScreen(memberId: member['id'])),
              ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 21,
            backgroundColor: _avatarBg,
            child: Text(initial,
                style: const TextStyle(
                    color: AppTheme.primary,
                    fontWeight: FontWeight.w700,
                    fontSize: 15)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('$name ${member['father_name'] ?? ''}',
                    style: const TextStyle(
                        fontWeight: FontWeight.w700, fontSize: 14),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis),
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
                      Flexible(
                        child: Text(member['current_section'],
                            style: TextStyle(
                                fontSize: 11,
                                color: AppTheme.textSecondary),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis),
                      ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 6),
          Container(
            padding:
                const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(
              color: _chipBg[status] ?? const Color(0x26DC2626),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(status,
                style: TextStyle(
                    color: statusColor,
                    fontSize: 10,
                    fontWeight: FontWeight.w600)),
          ),
          const SizedBox(width: 2),
          if (!_isOffline)
            const Icon(Icons.chevron_right,
                size: 16, color: AppTheme.textSecondary),
        ],
      ),
    );
  }
}


