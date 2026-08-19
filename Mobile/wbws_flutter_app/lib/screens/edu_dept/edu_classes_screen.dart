import 'package:flutter/material.dart';
import '../../services/api_service.dart';
import '../../utils/roster.dart';
import '../../utils/theme.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/status_banner.dart';

/// Class list + roster (name and code only). Same data as the website.
class EduClassesScreen extends StatefulWidget {
  const EduClassesScreen({super.key});
  @override
  State<EduClassesScreen> createState() => _EduClassesScreenState();
}

class _EduClassesScreenState extends State<EduClassesScreen> {
  final _api = ApiService();
  bool _loading = true;
  String? _error;
  List<dynamic> _classes = [];
  int? _openId;
  List<dynamic> _students = [];
  bool _loadingStudents = false;
  String? _studentError;
  String? _rosterNote;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    final res = await _api.getClasses();
    if (!mounted) return;
    if (res.success && res.data != null) {
      setState(() {
        _classes = res.data['classes'] ?? [];
        _loading = false;
      });
    } else {
      setState(() { _error = res.message ?? 'Could not load classes'; _loading = false; });
    }
  }

  Future<void> _openClass(dynamic c) async {
    final id = RosterParse.asInt(c['id']);
    if (id == null) return;
    setState(() {
      _openId = id;
      _loadingStudents = true;
      _students = [];
      _studentError = null;
      _rosterNote = null;
    });
    final res = await _api.getClassStudents(id);
    if (!mounted) return;
    if (!res.success || res.data == null) {
      setState(() {
        _loadingStudents = false;
        _studentError = res.message ?? 'Could not load students. Try again.';
      });
      return;
    }
    final parsed = RosterParse.students(res.data);
    String? note;
    if (RosterParse.fallback(res.data)) {
      final year = RosterParse.yearName(res.data);
      note = year == null
          ? 'Showing students from a previous year.'
          : 'Showing the $year roster.';
    }
    setState(() {
      _loadingStudents = false;
      _students = parsed;
      _rosterNote = note;
      if (parsed.isEmpty && RosterParse.reportedCount(res.data) > 0) {
        _studentError = 'The server sent students but this phone could not read them.';
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Classes')),
      body: _loading
          ? const MemberListSkeleton()
          : _error != null
              ? Padding(
                  padding: const EdgeInsets.all(16),
                  child: AppErrorCard(
                    error: AppError.fromMessage(_error),
                    onRetry: _load,
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(16),
                    children: [
                      if (_classes.isEmpty)
                        const EmptyState(
                          icon: Icons.class_rounded,
                          title: 'No classes yet',
                          subtitle: 'Create classes on the website under Education.',
                        ),
                      ..._classes.map((c) {
                        final id = RosterParse.asInt(c['id']);
                        final open = id == _openId;
                        return Card(
                          margin: const EdgeInsets.only(bottom: 8),
                          child: Column(
                            children: [
                              ListTile(
                                title: Text(c['class_name'] ?? '',
                                    style: const TextStyle(fontWeight: FontWeight.w600)),
                                subtitle: Text('${c['student_count'] ?? 0} students'),
                                trailing: Icon(open ? Icons.expand_less : Icons.expand_more),
                                onTap: () => open ? setState(() => _openId = null) : _openClass(c),
                              ),
                              if (open)
                                _loadingStudents
                                    ? const Padding(
                                        padding: EdgeInsets.all(16),
                                        child: StudentListSkeleton(count: 3),
                                      )
                                    : _studentError != null
                                        ? Padding(
                                            padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                                            child: StatusBanner.error(
                                              _studentError!,
                                              onRetry: () => _openClass(c),
                                            ),
                                          )
                                        : _students.isEmpty
                                            ? Padding(
                                                padding: const EdgeInsets.fromLTRB(8, 0, 8, 12),
                                                child: EmptyState(
                                                  icon: Icons.people_outline,
                                                  title: 'No students in this class yet',
                                                  subtitle: 'If they were enrolled on the website, tap Refresh.',
                                                  action: TextButton.icon(
                                                    onPressed: () => _openClass(c),
                                                    icon: const Icon(Icons.refresh, size: 18),
                                                    label: const Text('Refresh'),
                                                  ),
                                                ),
                                              )
                                            : Column(
                                                children: [
                                                  if (_rosterNote != null)
                                                    StatusBanner.warning(_rosterNote!),
                                                  ..._students.map((s) => ListTile(
                                                        dense: true,
                                                        title: Text(
                                                            '${s['student_name'] ?? ''} ${s['father_name'] ?? ''}'),
                                                        subtitle: Text('${s['member_code'] ?? ''}'),
                                                      )),
                                                ],
                                              ),
                            ],
                          ),
                        );
                      }),
                    ],
                  ),
                ),
    );
  }
}
