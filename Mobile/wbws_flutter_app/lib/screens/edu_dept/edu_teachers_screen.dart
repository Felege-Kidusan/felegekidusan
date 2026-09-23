import 'dart:async';

import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../utils/theme.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/use_website_note.dart';

/// Teacher directory + year-scoped assignment detail. Creating a login and
/// every write workflow stay on the website.
///
/// P1-G local-first: a COMPLETE, validated multi-page server crawl replaces
/// the dedicated SQLite snapshot atomically. Search always runs over that
/// complete local snapshot (while retaining the existing 50-result display
/// cap), and viewed teacher details are cached under the explicit server year.
class EduTeachersScreen extends StatefulWidget {
  const EduTeachersScreen({super.key});

  @override
  State<EduTeachersScreen> createState() => _EduTeachersScreenState();
}

class _EduTeachersScreenState extends State<EduTeachersScreen>
    with WidgetsBindingObserver {
  static const int _serverPageSize = 50;
  static const int _displayLimit = 50;

  final _api = ApiService();
  final _db = LocalDb();
  final _search = TextEditingController();

  bool _loading = true;
  bool _refreshing = false;
  String? _error;
  List<Map<String, dynamic>> _teachers = [];
  Map<String, dynamic>? _snapshot;
  StreamSubscription<bool>? _radioSub;

  bool get _hasSnapshot => _snapshot != null;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
    _radioSub = ConnectivityService().statusStream.listen((online) {
      if (online) {
        Future.delayed(const Duration(seconds: 1), () {
          if (mounted) _load();
        });
      }
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _radioSub?.cancel();
    _search.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _load();
  }

  /// SQLite renders first. The server is consulted only with radio link, and
  /// no local write occurs until every page is successful and consistent.
  Future<void> _load() async {
    if (_refreshing) return; // open/radio/resume/pull are one flight
    _refreshing = true;
    try {
      // ── 1. LOCAL READ (complete cached snapshot; offline-capable) ──
      final localSnapshot = await _db.getCachedEduTeacherSnapshot();
      final cached = await _db.getCachedEduTeachers(
        search: _search.text.trim(),
        limit: _displayLimit,
      );
      final offline = !ConnectivityService().hasLink;
      if (!mounted) return;
      setState(() {
        _snapshot = localSnapshot;
        _teachers = cached;
        if (localSnapshot != null) _loading = false;
        if (!offline) _error = null;
      });

      if (offline) {
        setState(() {
          _loading = false;
          _error = localSnapshot == null
              ? 'You are offline and no teachers are cached yet.'
              : 'You appear to be offline.';
        });
        return; // radio fast-fail: never start an HTTP timeout
      }

      // ── 2. SERVER REFRESH (all pages, validated before any write) ──
      final crawl = await _crawlCompleteDirectory();
      if (!mounted) return;
      await _db.replaceCachedEduTeachers(
        crawl.rows,
        academicYearId: crawl.academicYearId,
        academicYearName: crawl.academicYearName,
        total: crawl.total,
      );

      // ── 3. REREAD SQLITE so the visible result is the committed store ──
      final snapshotNow = await _db.getCachedEduTeacherSnapshot();
      final localNow = await _db.getCachedEduTeachers(
        search: _search.text.trim(),
        limit: _displayLimit,
      );
      if (!mounted) return;
      setState(() {
        _snapshot = snapshotNow;
        _teachers = localNow;
        _loading = false;
        _error = null;
      });
    } on _TeacherRefreshFailure catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'Could not refresh teachers.';
      });
    } finally {
      _refreshing = false;
      if (mounted) setState(() {});
    }
  }

  /// Fetch the unfiltered active directory page-by-page. Page 1 is NEVER
  /// treated as complete unless the server's validated pagination says so.
  /// The returned rows are still in exact server order; LocalDb stores their
  /// global positions as sort_order during the atomic replacement.
  Future<_TeacherCrawl> _crawlCompleteDirectory() async {
    final rows = <Map<String, dynamic>>[];
    final seenIds = <int>{};
    int? expectedTotal;
    int? expectedPages;
    int? expectedLimit;
    int? academicYearId;
    String? academicYearName;
    var page = 1;

    while (true) {
      final res = await _api.getTeachers(page: page, limit: _serverPageSize);
      if (!res.success || res.data == null) {
        throw _TeacherRefreshFailure(
            res.message ?? 'Could not load teacher page $page.');
      }

      final data = res.data!;
      final rawItems = data['items'];
      final rawPagination = data['pagination'];
      if (rawItems is! List || rawPagination is! Map) {
        throw const _TeacherRefreshFailure(
            'The teacher directory response was malformed.');
      }
      if (rawItems.any((item) => item is! Map)) {
        throw const _TeacherRefreshFailure(
            'The teacher directory contained an invalid row.');
      }

      final pagination = Map<String, dynamic>.from(rawPagination);
      final responsePage = _strictInt(pagination['page']);
      final total = _strictInt(pagination['total']);
      final limit = _strictInt(pagination['limit']);
      final pages = _strictInt(pagination['pages']);
      final hasMore = pagination['has_more'];
      if (responsePage != page ||
          total == null ||
          total < 0 ||
          limit == null ||
          limit != _serverPageSize ||
          pages == null ||
          pages < 0 ||
          (total == 0 && pages != 0) ||
          (total > 0 && pages != ((total + limit - 1) ~/ limit)) ||
          hasMore is! bool ||
          hasMore != (page < pages)) {
        throw const _TeacherRefreshFailure(
            'Teacher pagination changed during refresh.');
      }

      expectedTotal ??= total;
      expectedPages ??= pages;
      expectedLimit ??= limit;
      if (total != expectedTotal ||
          pages != expectedPages ||
          limit != expectedLimit) {
        throw const _TeacherRefreshFailure(
            'Teacher pagination changed during refresh.');
      }

      for (final raw in rawItems) {
        final item = Map<String, dynamic>.from(raw as Map);
        final id = _strictInt(item['id']);
        if (id == null || id <= 0 || !seenIds.add(id)) {
          throw const _TeacherRefreshFailure(
              'The teacher directory contained duplicate or invalid IDs.');
        }
        if (!item.containsKey('academic_year_id') ||
            !item.containsKey('academic_year_name')) {
          throw const _TeacherRefreshFailure(
              'The teacher directory did not identify its academic year.');
        }
        final rowYear = _serverYearId(item['academic_year_id']);
        if (rowYear == null) {
          throw const _TeacherRefreshFailure(
              'The teacher directory returned an invalid academic year.');
        }
        final rawYearName = item['academic_year_name'];
        if (rawYearName != null && rawYearName is! String) {
          throw const _TeacherRefreshFailure(
              'The teacher directory returned an invalid academic year.');
        }
        final rowYearName = rawYearName as String?;
        if (academicYearId == null) {
          academicYearId = rowYear;
          academicYearName = rowYearName;
        } else if (rowYear != academicYearId ||
            rowYearName != academicYearName) {
          throw const _TeacherRefreshFailure(
              'The academic year changed during teacher refresh.');
        }
        rows.add(item);
      }

      // response.php deliberately reports pages=0 for a valid empty list;
      // page 1 is still the one request needed to prove that emptiness.
      if (page >= pages) break;
      page++;
    }

    if (expectedTotal == null || rows.length != expectedTotal) {
      throw const _TeacherRefreshFailure(
          'Teacher pagination changed during refresh.');
    }
    return _TeacherCrawl(
      rows: rows,
      total: expectedTotal,
      academicYearId: academicYearId ?? 0,
      academicYearName: academicYearName,
    );
  }

  /// Apply the submitted query to SQLite immediately. A normal full refresh
  /// follows when possible; filtered server pages never replace the snapshot.
  Future<void> _applySearch() async {
    final local = await _db.getCachedEduTeachers(
      search: _search.text.trim(),
      limit: _displayLimit,
    );
    if (!mounted) return;
    setState(() => _teachers = local);
    await _load();
  }

  void _open(Map<String, dynamic> teacher) {
    final id = _strictInt(teacher['id']);
    if (id == null || id <= 0) return;
    final yearId = _strictInt(_snapshot?['academic_year_id']) ??
        _serverYearId(teacher['academic_year_id']) ??
        0;
    final yearName = (_snapshot?['academic_year_name'] ??
        teacher['academic_year_name']) as String?;
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppTheme.cardLight,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => _TeacherDetailSheet(
        teacherId: id,
        teacherName: '${teacher['full_name'] ?? ''}',
        academicYearId: yearId,
        academicYearName: yearName,
      ),
    );
  }

  String? _freshLabel() {
    final iso = _snapshot?['fetched_at'];
    if (iso is! String) return null;
    final dt = DateTime.tryParse(iso)?.toLocal();
    if (dt == null) return null;
    final now = DateTime.now();
    String p2(int v) => v.toString().padLeft(2, '0');
    final sameDay = dt.year == now.year &&
        dt.month == now.month &&
        dt.day == now.day;
    return sameDay
        ? '${p2(dt.hour)}:${p2(dt.minute)}'
        : '${dt.year}-${p2(dt.month)}-${p2(dt.day)}';
  }

  Widget _staleBanner(String message) {
    final fresh = _freshLabel();
    final suffix = fresh == null ? '' : ' · updated $fresh';
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF3C7),
        borderRadius: BorderRadius.circular(11),
      ),
      child: Row(children: [
        const Icon(Icons.wifi_off_rounded,
            size: 15, color: Color(0xFF92400E)),
        const SizedBox(width: 7),
        Expanded(
          child: Text('$message — showing cached teachers$suffix.',
              style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF92400E))),
        ),
      ]),
    );
  }

  @override
  Widget build(BuildContext context) {
    final query = _search.text.trim();
    return Scaffold(
      appBar: AppBar(title: const Text('Teachers')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: TextField(
              controller: _search,
              decoration: InputDecoration(
                hintText: 'Search name or username',
                prefixIcon: const Icon(Icons.search, size: 20),
                border:
                    OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              ),
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _applySearch(),
            ),
          ),
          Expanded(
            child: _loading && !_hasSnapshot
                ? const Center(child: CircularProgressIndicator())
                : (_error != null && !_hasSnapshot)
                    ? Center(
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Text(_error!,
                              textAlign: TextAlign.center,
                              style:
                                  const TextStyle(color: AppTheme.danger)),
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: _load,
                        child: ListView(
                          physics: const AlwaysScrollableScrollPhysics(),
                          padding: const EdgeInsets.all(16),
                          children: [
                            if (_error != null && _hasSnapshot)
                              _staleBanner(_error!),
                            if (_refreshing && _hasSnapshot)
                              const Padding(
                                padding: EdgeInsets.only(bottom: 8),
                                child: LinearProgressIndicator(
                                    minHeight: 3, color: AppTheme.primary),
                              ),
                            const UseWebsiteNote(
                              title: 'New teacher login',
                              body:
                                  'Username, password and class assignment stay on the website — one form, same as now.',
                            ),
                            const SizedBox(height: 12),
                            if (_teachers.isEmpty)
                              EmptyState(
                                icon: Icons.school_rounded,
                                title: query.isEmpty
                                    ? 'No teachers found'
                                    : 'No matching teachers',
                                subtitle: query.isEmpty
                                    ? 'Add them on the website.'
                                    : 'Try a different name or username.',
                              ),
                            ..._teachers.asMap().entries.map((e) {
                              final teacher = e.value;
                              return FastListRow(
                                index: e.key,
                                child: ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  title: Text('${teacher['full_name'] ?? ''}',
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w700)),
                                  subtitle: Text(
                                      '${teacher['username'] ?? ''} · ${teacher['assigned_classes'] ?? 0} classes'),
                                  trailing:
                                      const Icon(Icons.chevron_right),
                                  onTap: () => _open(teacher),
                                ),
                              );
                            }),
                          ],
                        ),
                      ),
          ),
        ],
      ),
    );
  }
}

/// Local-first detail is intentionally view-once cached (no N+1 prefetch).
/// A transport/server/malformed failure is always distinct from a valid empty
/// assignments list, fixing the old false “No assignments this year” state.
class _TeacherDetailSheet extends StatefulWidget {
  final int teacherId;
  final String teacherName;
  final int academicYearId;
  final String? academicYearName;

  const _TeacherDetailSheet({
    required this.teacherId,
    required this.teacherName,
    required this.academicYearId,
    required this.academicYearName,
  });

  @override
  State<_TeacherDetailSheet> createState() => _TeacherDetailSheetState();
}

class _TeacherDetailSheetState extends State<_TeacherDetailSheet> {
  final _api = ApiService();
  final _db = LocalDb();

  Map<String, dynamic>? _detail;
  bool _loading = true;
  bool _refreshing = false;
  String? _error;
  late int _yearId;
  String? _yearName;

  @override
  void initState() {
    super.initState();
    _yearId = widget.academicYearId;
    _yearName = widget.academicYearName;
    _load();
  }

  Future<void> _load() async {
    if (_refreshing) return;
    _refreshing = true;
    try {
      // ── 1. LOCAL DETAIL for the explicit list-snapshot year ──────
      final cached = await _db.getCachedEduTeacherDetail(
          widget.teacherId, widget.academicYearId);
      final offline = !ConnectivityService().hasLink;
      if (!mounted) return;
      if (cached != null) {
        setState(() {
          _detail = cached;
          _loading = false;
          _yearId = _serverYearId(cached['academic_year_id']) ??
              widget.academicYearId;
          _yearName = (cached['academic_year_name'] ??
              cached['local_academic_year_name'] ??
              widget.academicYearName) as String?;
        });
      }
      if (offline) {
        setState(() {
          _loading = false;
          _error = cached == null
              ? 'You are offline and these assignments are not saved on this phone.'
              : 'You appear to be offline.';
        });
        return;
      }

      // ── 2. SERVER DETAIL; valid empty != failed request ──────────
      final res = await _api.getTeacher(widget.teacherId);
      if (!mounted) return;
      if (!res.success || res.data == null) {
        setState(() {
          _loading = false;
          _error = res.message ?? 'Could not load teacher assignments.';
        });
        return;
      }
      final detail = Map<String, dynamic>.from(res.data!);
      final returnedId = _strictInt(detail['id']);
      if (returnedId != widget.teacherId ||
          detail['assignments'] is! List ||
          !detail.containsKey('academic_year_id') ||
          !detail.containsKey('academic_year_name')) {
        throw const _TeacherRefreshFailure(
            'The teacher assignment response was malformed.');
      }
      final returnedYear = _serverYearId(detail['academic_year_id']);
      final rawYearName = detail['academic_year_name'];
      if (returnedYear == null ||
          (rawYearName != null && rawYearName is! String)) {
        throw const _TeacherRefreshFailure(
            'The teacher assignment response had an invalid academic year.');
      }
      final returnedYearName = rawYearName as String?;
      await _db.cacheEduTeacherDetail(
        detail,
        academicYearId: returnedYear,
        academicYearName: returnedYearName,
      );
      final localNow = await _db.getCachedEduTeacherDetail(
          widget.teacherId, returnedYear);
      if (!mounted) return;
      setState(() {
        _detail = localNow ?? detail;
        _yearId = returnedYear;
        _yearName = returnedYearName;
        _loading = false;
        _error = null;
      });
    } on _TeacherRefreshFailure catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.message;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'Could not load teacher assignments.';
      });
    } finally {
      _refreshing = false;
      if (mounted) setState(() {});
    }
  }

  String get _yearLabel {
    final name = _yearName?.trim();
    if (name != null && name.isNotEmpty) return name;
    if (_yearId > 0) return 'academic year #$_yearId';
    return 'the saved academic-year scope';
  }

  Widget _detailWarning(String message) => Container(
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
        decoration: BoxDecoration(
          color: const Color(0xFFFEF3C7),
          borderRadius: BorderRadius.circular(11),
        ),
        child: Row(children: [
          const Icon(Icons.wifi_off_rounded,
              size: 15, color: Color(0xFF92400E)),
          const SizedBox(width: 7),
          Expanded(
            child: Text(
              _detail == null
                  ? message
                  : '$message — showing saved assignments for $_yearLabel.',
              style: const TextStyle(
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF92400E)),
            ),
          ),
        ]),
      );

  @override
  Widget build(BuildContext context) {
    final assignments = (_detail?['assignments'] as List? ?? [])
        .whereType<Map>()
        .map((a) => Map<String, dynamic>.from(a))
        .toList();
    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.65,
      minChildSize: 0.35,
      maxChildSize: 0.9,
      builder: (context, scrollController) => ListView(
        controller: scrollController,
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              margin: const EdgeInsets.only(bottom: 16),
              decoration: BoxDecoration(
                color: AppTheme.textSecondary.withOpacity(0.28),
                borderRadius: BorderRadius.circular(99),
              ),
            ),
          ),
          Text(widget.teacherName,
              style:
                  const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
          const SizedBox(height: 3),
          Text(_yearLabel,
              style:
                  TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          const SizedBox(height: 10),
          if (_error != null) _detailWarning(_error!),
          if (_refreshing && _detail != null)
            const Padding(
              padding: EdgeInsets.only(bottom: 10),
              child: LinearProgressIndicator(
                  minHeight: 3, color: AppTheme.primary),
            ),
          if (_loading && _detail == null)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 28),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_detail == null)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 16),
              child: Column(children: [
                const Icon(Icons.cloud_off_rounded,
                    size: 34, color: AppTheme.textSecondary),
                const SizedBox(height: 8),
                const Text('Assignments are not available.',
                    textAlign: TextAlign.center),
                const SizedBox(height: 4),
                TextButton(onPressed: _load, child: const Text('Retry')),
              ]),
            )
          else if (assignments.isEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 16),
              child: Text('No class assignments for $_yearLabel.',
                  style: TextStyle(color: AppTheme.textSecondary)),
            )
          else
            ...assignments.map((a) => ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text('${a['class_name'] ?? ''}'),
                  subtitle: Text('${a['subject_name'] ??
                      (a['is_class_teacher'] == true
                          ? 'Class Teacher'
                          : '')}'),
                )),
          const SizedBox(height: 8),
          const UseWebsiteNote(
            title: 'Add or change a teacher',
            body:
                'Create the login and assign classes on the website Education → Teachers screen.',
          ),
        ],
      ),
    );
  }
}

int? _strictInt(dynamic value) {
  if (value is int) return value;
  if (value is num && value.isFinite && value == value.roundToDouble()) {
    return value.toInt();
  }
  if (value is String) return int.tryParse(value);
  return null;
}

/// Server emits null when no active academic year exists; local scope 0
/// represents that exact server state. Negative/invalid ids are rejected.
int? _serverYearId(dynamic value) {
  if (value == null) return 0;
  final parsed = _strictInt(value);
  return parsed != null && parsed >= 0 ? parsed : null;
}

class _TeacherCrawl {
  final List<Map<String, dynamic>> rows;
  final int total;
  final int academicYearId;
  final String? academicYearName;

  const _TeacherCrawl({
    required this.rows,
    required this.total,
    required this.academicYearId,
    required this.academicYearName,
  });
}

class _TeacherRefreshFailure implements Exception {
  final String message;
  const _TeacherRefreshFailure(this.message);
}
