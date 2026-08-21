import 'dart:async';
import 'package:flutter/material.dart';
import '../../utils/transitions.dart';
import 'package:flutter/services.dart';
import '../../services/api_service.dart';
import '../../services/app_nav.dart';
import '../../services/catalog_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/sync_service.dart';
import '../../utils/roster.dart';
import '../../utils/theme.dart';
import '../../widgets/action_bar.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/app_error.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/status_banner.dart';

class TeacherGradesScreen extends StatefulWidget {
  const TeacherGradesScreen({super.key});

  @override
  State<TeacherGradesScreen> createState() => TeacherGradesScreenState();
}

class TeacherGradesScreenState extends State<TeacherGradesScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  final _sync = SyncService();

  List<dynamic> _classes = [];
  List<dynamic> _subjects = [];
  List<dynamic> _assessments = [];
  List<dynamic> _bootAssessments = [];
  bool _didBootstrap = false;

  int? _selectedClassId;
  int? _selectedSubjectId;
  String? _selectedClassName;

  bool _loadingClasses = true;
  bool _loadingSubjects = false;
  bool _loadingAssessments = false;
  bool _isOffline = false;
  String? _error;
  int _pendingGrades = 0;
  StreamSubscription<bool>? _netSub;

  @override
  void initState() {
    super.initState();
    _isOffline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (mounted) setState(() => _isOffline = !hasLink);
    });
    _loadClasses();
    _updatePendingCount();
    _sync.syncStream.listen((s) {
      if (mounted) setState(() => _pendingGrades = s.pendingGrades);
    });
  }

  @override
  void dispose() {
    _netSub?.cancel();
    super.dispose();
  }

  void refresh() {
    _loadClasses();
    _updatePendingCount();
  }

  Future<void> _updatePendingCount() async {
    final c = await _db.getPendingGradesCount();
    if (mounted) setState(() => _pendingGrades = c);
  }

  Future<void> _loadClasses() async {
    setState(() { _error = null; });
    final warm = CatalogService().cached;
    if (warm.isNotEmpty && mounted) {
      setState(() { _classes = warm; _loadingClasses = false; _isOffline = !ConnectivityService().hasLink; });
    } else {
      setState(() => _loadingClasses = true);
    }

    final classes = await CatalogService().classes();
    if (!mounted) return;
    setState(() {
      _classes = classes.isNotEmpty ? classes : _classes;
      _loadingClasses = false;
      _isOffline = !ConnectivityService().hasLink;
    });
    AppNav().markGradesLoaded();
    if (classes.length == 1) {
      final id = classes[0]['id'] is int ? classes[0]['id'] as int : int.tryParse('${classes[0]['id']}');
      _selectedClassId = id;
      _selectedClassName = classes[0]['class_name'];
      _loadSubjects();
    }
  }

  Future<void> _loadSubjects() async {
    if (_selectedClassId == null) return;
    final cached = await _db.getCachedSubjects(_selectedClassId!);
    if (!mounted) return;
    setState(() {
      _assessments = [];
      _bootAssessments = [];
      _didBootstrap = false;
      _selectedSubjectId = null;
      if (cached.isNotEmpty) {
        _subjects = cached;
        _loadingSubjects = false;
      } else {
        _subjects = [];
        _loadingSubjects = true;
      }
    });

    final res = await _api.getGradeBootstrap(_selectedClassId!);
    if (!mounted) return;

    if (res.success && res.data != null) {
      final subjects = res.data['subjects'] ?? [];
      final allA = res.data['assessments'] ?? [];
      await _db.cacheSubjects(_selectedClassId!, subjects);
      setState(() {
        _subjects = subjects;
        _bootAssessments = allA;
        _didBootstrap = true;
        _loadingSubjects = false;
        _isOffline = false;
      });
      return;
    }

    setState(() {
      if (_subjects.isEmpty) _subjects = cached;
      _loadingSubjects = false;
      if (_subjects.isNotEmpty) {
        _isOffline = !ConnectivityService().hasLink;
      } else if (!res.success) {
        _error = res.message ?? 'Could not load subjects. Pull to refresh.';
      }
    });
  }

  Future<void> _loadAssessments() async {
    if (_selectedClassId == null || _selectedSubjectId == null) return;
    final sid = _selectedSubjectId!;
    final cached = await _db.getCachedAssessments(_selectedClassId!, sid);
    if (!mounted) return;
    if (cached.isNotEmpty) {
      setState(() { _assessments = cached; _loadingAssessments = false; });
    }

    final fromBoot = _bootAssessments.where((a) =>
      (a['subject_id'] is int ? a['subject_id'] : int.tryParse('${a['subject_id']}')) == sid
    ).toList();
    if (_didBootstrap && fromBoot.isNotEmpty) {
      setState(() { _assessments = fromBoot; _loadingAssessments = false; });
      await _db.cacheAssessments(_selectedClassId!, sid, fromBoot);
      return;
    }

    if (cached.isEmpty) {
      setState(() => _loadingAssessments = true);
    }
    final res = await _api.getAssessments(_selectedClassId!, sid);
    if (!mounted) return;

    if (res.success && res.data != null) {
      final assessments = res.data['assessments'] ?? [];
      await _db.cacheAssessments(_selectedClassId!, sid, assessments);
      setState(() { _assessments = assessments; _loadingAssessments = false; _isOffline = false; });
    } else {
      setState(() {
        if (_assessments.isEmpty) _assessments = cached;
        _loadingAssessments = false;
        if (_assessments.isNotEmpty) _isOffline = !ConnectivityService().hasLink;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Grades'),
        automaticallyImplyLeading: Navigator.canPop(context),
        actions: [
          if (_pendingGrades > 0)
            IconButton(
              onPressed: () async {
                final r = await _sync.syncAll(force: true);
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(r.message), backgroundColor: r.failed > 0 ? AppTheme.warning : AppTheme.success));
                  _updatePendingCount();
                }
              },
              icon: Badge(
                label: Text('$_pendingGrades', style: const TextStyle(fontSize: 9)),
                backgroundColor: AppTheme.warning,
                child: const Icon(Icons.sync, size: 22),
              ),
            ),
          IconButton(icon: const Icon(Icons.refresh, size: 20), onPressed: refresh),
        ],
      ),
      body: _loadingClasses
          ? const DashboardSkeleton()
          : _error != null
              ? Padding(padding: const EdgeInsets.all(16), child: AppErrorCard(error: AppError.fromMessage(_error), onRetry: refresh, autoRetry: true))
              : _classes.isEmpty
                  ? const EmptyState(icon: Icons.grading_rounded, title: 'No classes assigned',
                      subtitle: 'You need to be assigned to classes to enter grades.')
                  : RefreshIndicator(
                      onRefresh: _loadClasses,
                      child: ListView(
                        padding: const EdgeInsets.all(16),
                        children: [
                          if (_isOffline) _offlineBanner(),
                          _buildClassSelector(),
                          const SizedBox(height: 12),
                          if (_selectedClassId != null) ...[
                            _buildSubjectSelector(),
                            const SizedBox(height: 12),
                          ],
                          if (_selectedSubjectId != null) _buildAssessmentSection(),
                        ],
                      ),
                    ),
    );
  }

  Widget _offlineBanner() {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
      decoration: BoxDecoration(
        color: AppTheme.warning.withOpacity(0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(children: [
        Icon(Icons.cloud_off, size: 16, color: AppTheme.warning),
        const SizedBox(width: 8),
        Expanded(child: Text('Waiting for network — grades stay on this phone until you are back online.',
            style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500))),
      ]),
    );
  }

  Widget _buildClassSelector() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Class', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.textSecondary)),
        const SizedBox(height: 6),
        DropdownButtonFormField<int>(
          value: _selectedClassId,
          hint: const Text('Select class', style: TextStyle(fontSize: 13)),
          isExpanded: true,
          decoration: InputDecoration(
            contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
          ),
          items: _classes.map<DropdownMenuItem<int>>((c) => DropdownMenuItem(
            value: c['id'] is int ? c['id'] : int.tryParse('${c['id']}'),
            child: Text('${c['class_name']}', style: const TextStyle(fontSize: 13)),
          )).toList(),
          onChanged: (v) {
            setState(() {
              _selectedClassId = v;
              _selectedClassName = _classes.firstWhere((c) => (c['id'] is int ? c['id'] : int.tryParse('${c['id']}')) == v)['class_name'];
              _selectedSubjectId = null;
              _assessments = [];
            });
            _loadSubjects();
          },
        ),
      ],
    );
  }

  Widget _buildSubjectSelector() {
    if (_loadingSubjects) return const Padding(padding: EdgeInsets.all(16), child: StudentListSkeleton(count: 2));
    if (_subjects.isEmpty) {
      return Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: AppTheme.warning.withOpacity(0.08), borderRadius: BorderRadius.circular(10)),
        child: Row(children: [
          const Icon(Icons.info_outline, color: AppTheme.warning, size: 18),
          const SizedBox(width: 10),
          Expanded(child: Text('No subjects assigned to you for this class.', style: TextStyle(fontSize: 12, color: AppTheme.textSecondary))),
        ]),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Subject', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.textSecondary)),
        const SizedBox(height: 6),
        Wrap(spacing: 8, runSpacing: 8, children: _subjects.map((s) {
          final selected = _selectedSubjectId == (s['id'] is int ? s['id'] : int.tryParse('${s['id']}'));
          return ChoiceChip(
            label: Text(s['subject_name'] ?? '', style: TextStyle(fontSize: 12, color: selected ? Colors.white : null, fontWeight: selected ? FontWeight.w600 : null)),
            selected: selected,
            selectedColor: AppTheme.primary,
            onSelected: (_) {
              setState(() => _selectedSubjectId = s['id'] is int ? s['id'] : int.tryParse('${s['id']}'));
              _loadAssessments();
            },
            side: BorderSide(color: selected ? AppTheme.primary : AppTheme.borderLight),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          );
        }).toList()),
      ],
    );
  }

  Widget _buildAssessmentSection() {
    if (_loadingAssessments) return const Padding(padding: EdgeInsets.all(24), child: StudentListSkeleton(count: 3));
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          const Text('Assessments', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
          if (!_isOffline)
            TextButton.icon(
              onPressed: _showCreateAssessmentDialog,
              icon: const Icon(Icons.add, size: 16),
              label: const Text('New', style: TextStyle(fontSize: 12)),
              style: TextButton.styleFrom(foregroundColor: AppTheme.primary, padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4)),
            ),
        ]),
        const SizedBox(height: 8),
        if (_assessments.isEmpty)
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(color: AppTheme.surfaceLight, borderRadius: BorderRadius.circular(12), border: Border.all(color: AppTheme.borderLight)),
            child: Column(children: [
              Icon(Icons.assignment_outlined, size: 36, color: AppTheme.textSecondary),
              const SizedBox(height: 10),
              const Text('No assessments yet', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w500)),
              const SizedBox(height: 4),
              Text(_isOffline ? 'Go online to create assessments.' : 'Tap "New" to create a test, quiz, or assignment.',
                  style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
            ]),
          )
        else
          ..._assessments.map((a) => _assessmentCard(a)),
      ],
    );
  }

  Widget _assessmentCard(dynamic a) {
    final name = a['assessment_name'] ?? '';
    final type = a['assessment_type'] ?? 'test';
    final maxScore = a['max_score'] ?? 100;
    final weight = a['weight_percentage'] ?? 100;
    final gradesEntered = a['grades_entered'] ?? 0;
    final typeColors = {'test': AppTheme.primary, 'quiz': AppTheme.info, 'midterm': AppTheme.warning, 'final': AppTheme.danger, 'assignment': AppTheme.accent};
    final color = typeColors[type] ?? AppTheme.primary;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => _openGradeEntry(a),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(width: 44, height: 44,
              decoration: BoxDecoration(color: color.withOpacity(0.12), borderRadius: BorderRadius.circular(12)),
              child: Icon(Icons.assignment, color: color, size: 22)),
            const SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(name, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
              const SizedBox(height: 3),
              Row(children: [
                Container(padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(color: color.withOpacity(0.12), borderRadius: BorderRadius.circular(4)),
                  child: Text(type, style: TextStyle(fontSize: 9, color: color, fontWeight: FontWeight.w600))),
                const SizedBox(width: 6),
                Text('Max: $maxScore', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
                const SizedBox(width: 6),
                Text('Wt: $weight%', style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
              ]),
            ])),
            Container(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: gradesEntered > 0 ? AppTheme.success.withOpacity(0.12) : AppTheme.surfaceLight,
                borderRadius: BorderRadius.circular(8)),
              child: Text(gradesEntered > 0 ? '$gradesEntered graded' : 'Enter',
                style: TextStyle(fontSize: 10, fontWeight: FontWeight.w600,
                  color: gradesEntered > 0 ? AppTheme.success : AppTheme.textSecondary))),
            const SizedBox(width: 4),
            Icon(Icons.chevron_right, size: 18, color: AppTheme.textSecondary),
          ]),
        ),
      ),
    );
  }

  void _showCreateAssessmentDialog() {
    final nameCtrl = TextEditingController();
    final maxCtrl = TextEditingController(text: '100');
    final weightCtrl = TextEditingController(text: '100');
    String type = 'test';

    showModalBottomSheet(
      context: context, isScrollControlled: true, backgroundColor: AppTheme.cardLight,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, ss) => Padding(
          padding: EdgeInsets.only(left: 20, right: 20, top: 20, bottom: MediaQuery.of(ctx).viewInsets.bottom + 20),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text('New Assessment', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            Text('$_selectedClassName', style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
            const SizedBox(height: 16),
            TextField(controller: nameCtrl, decoration: const InputDecoration(hintText: 'Name (e.g., Quiz 1)', prefixIcon: Icon(Icons.edit_outlined, size: 18)), textCapitalization: TextCapitalization.words),
            const SizedBox(height: 12),
            Wrap(spacing: 6, children: ['test', 'quiz', 'midterm', 'final', 'assignment'].map((t) =>
              ChoiceChip(label: Text(t, style: const TextStyle(fontSize: 11)), selected: type == t, selectedColor: AppTheme.primary, onSelected: (_) => ss(() => type = t), side: BorderSide(color: AppTheme.borderLight))).toList()),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: TextField(controller: maxCtrl, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Max Score', labelStyle: TextStyle(fontSize: 12)))),
              const SizedBox(width: 12),
              Expanded(child: TextField(controller: weightCtrl, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Weight %', labelStyle: TextStyle(fontSize: 12)))),
            ]),
            const SizedBox(height: 20),
            SizedBox(width: double.infinity, child: ElevatedButton(
              onPressed: () async {
                if (nameCtrl.text.trim().isEmpty) return;
                final res = await _api.createAssessment({
                  'class_id': _selectedClassId, 'subject_id': _selectedSubjectId,
                  'assessment_name': nameCtrl.text.trim(), 'assessment_type': type,
                  'max_score': double.tryParse(maxCtrl.text) ?? 100, 'weight_percentage': double.tryParse(weightCtrl.text) ?? 100,
                });
                if (!ctx.mounted) return;
                Navigator.pop(ctx);
                if (res.success) {
                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Assessment created!'), backgroundColor: AppTheme.success));
                  _loadAssessments();
                } else {
                  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res.message ?? 'Failed'), backgroundColor: AppTheme.danger));
                }
              },
              child: const Text('Create Assessment'),
            )),
          ]),
        ),
      ),
    );
  }

  void _openGradeEntry(dynamic assessment) {
    final subjectName = _subjects.firstWhere(
      (s) => (s['id'] is int ? s['id'] : int.tryParse('${s['id']}')) == _selectedSubjectId,
      orElse: () => {'subject_name': ''},
    )['subject_name'] ?? '';

    Navigator.push(context, SmoothPageRoute(page:
      _GradeEntryScreen(
        assessmentId: assessment['id'] is int ? assessment['id'] : int.tryParse('${assessment['id']}') ?? 0,
        assessmentName: assessment['assessment_name'] ?? '',
        maxScore: (assessment['max_score'] is num ? assessment['max_score'] : double.tryParse('${assessment['max_score']}') ?? 100).toDouble(),
        className: _selectedClassName ?? '',
        classId: _selectedClassId ?? 0,
        subjectId: _selectedSubjectId ?? 0,
        subjectName: subjectName,
      ),
    )).then((_) { _loadAssessments(); _updatePendingCount(); });
  }
}

// ============================================================
// GRADE ENTRY SCREEN — offline-first score input
// ============================================================

class _GradeEntryScreen extends StatefulWidget {
  final int assessmentId;
  final String assessmentName;
  final double maxScore;
  final String className;
  final int classId;
  final int subjectId;
  final String subjectName;

  const _GradeEntryScreen({
    required this.assessmentId, required this.assessmentName,
    required this.maxScore, required this.className,
    required this.classId, required this.subjectId, required this.subjectName,
  });

  @override
  State<_GradeEntryScreen> createState() => _GradeEntryScreenState();
}

class _GradeEntryScreenState extends State<_GradeEntryScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  List<Map<String, dynamic>> _students = [];
  Map<int, TextEditingController> _scoreCtrl = {};
  Map<int, TextEditingController> _remarkCtrl = {};
  bool _loading = true;
  bool _saving = false;
  bool _isOffline = false;
  String _packetStatus = '';
  String? _error;
  String? _rosterNote;
  int _gradedCount = 0;
  StreamSubscription<bool>? _netSub;

  @override
  void initState() {
    super.initState();
    _isOffline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (mounted) setState(() => _isOffline = !hasLink);
    });
    _loadStudents();
  }

  @override
  void dispose() {
    _netSub?.cancel();
    _scoreCtrl.values.forEach((c) => c.dispose());
    _remarkCtrl.values.forEach((c) => c.dispose());
    super.dispose();
  }

  Future<void> _loadStudents({bool silent = false}) async {
    if (!silent) {
      setState(() { _error = null; _rosterNote = null; });
      await _paintCache();
    }

    final res = await _api.getGradeStudents(widget.assessmentId);
    if (!mounted) return;

    if (res.success && res.data != null) {
      final parsed = RosterParse.students(res.data);
      if (parsed.isEmpty && RosterParse.reportedCount(res.data) > 0) {
        if (_students.isEmpty) {
          setState(() {
            _error = 'The server sent students but this phone could not read them.';
            _loading = false;
          });
        }
        return;
      }
      final merged = await _applyPending(parsed);
      if (_students.isEmpty) {
        _setupStudents(merged);
      } else {
        _mergeServer(merged);
      }
      String? note;
      if (RosterParse.fallback(res.data)) {
        final year = RosterParse.yearName(res.data);
        note = year == null
            ? 'Showing students from a previous year.'
            : 'Showing the $year roster.';
      }
      final packet = '${res.data['submission_status'] ?? ''}';
      setState(() {
        _isOffline = !ConnectivityService().hasLink;
        _loading = false;
        _rosterNote = note;
        if (packet.isNotEmpty) _packetStatus = packet;
      });
      _recountGraded();
      await _db.cacheGradeSheet(widget.assessmentId, widget.classId, merged);
      await _db.cacheStudents(widget.classId, merged);
      return;
    }

    if (_students.isEmpty) {
      setState(() {
        _error = res.message ?? 'Could not load students. Check your connection and try again.';
        _loading = false;
        _isOffline = res.isNetworkError;
      });
    }
  }

  Future<void> _paintCache() async {
    final pending = await _pendingByMember();
    final sheet = await _db.getCachedGradeSheet(widget.assessmentId);
    List<Map<String, dynamic>> source = const [];
    if (sheet != null && sheet.isNotEmpty) {
      source = sheet;
    } else {
      source = await _db.getCachedStudents(widget.classId);
    }
    if (source.isEmpty) return;
    final students = <Map<String, dynamic>>[];
    for (final s in source) {
      final mid = RosterParse.asInt(s['member_id']) ?? RosterParse.asInt(s['id']);
      if (mid == null) continue;
      final pg = pending[mid];
      students.add({
        'member_id': mid,
        'student_name': s['student_name'] ?? '',
        'father_name': s['father_name'] ?? '',
        'member_code': s['member_code'] ?? '',
        'gender': s['gender'] ?? '',
        'score': pg?['score'] ?? s['score'],
        'record_id': pg?['record_id'] ?? s['record_id'],
        'remarks': pg?['remark'] ?? s['remarks'] ?? s['remark'] ?? '',
      });
    }
    if (students.isEmpty) return;
    _setupStudents(students);
    if (mounted) setState(() { _loading = false; });
  }

  Future<Map<int, Map<String, dynamic>>> _pendingByMember() async {
    final pending = await _db.getPendingGradeRecords(widget.assessmentId);
    final map = <int, Map<String, dynamic>>{};
    for (final p in pending) {
      final mid = RosterParse.asInt(p['member_id']);
      if (mid != null) map[mid] = p;
    }
    return map;
  }

  Future<List<Map<String, dynamic>>> _applyPending(List<Map<String, dynamic>> parsed) async {
    final pending = await _pendingByMember();
    return parsed.map((s) {
      final mid = s['member_id'] as int;
      final pg = pending[mid];
      return <String, dynamic>{
        ...s,
        'member_id': mid,
        'score': pg?['score'] ?? s['score'],
        'record_id': pg?['record_id'] ?? s['record_id'],
        'remarks': pg?['remark'] ?? s['remarks'] ?? s['remark'] ?? '',
      };
    }).toList();
  }

  void _mergeServer(List<Map<String, dynamic>> incoming) {
    for (final s in incoming) {
      final mid = RosterParse.asInt(s['member_id']);
      if (mid == null) continue;
      final existing = _students.indexWhere((e) => RosterParse.asInt(e['member_id']) == mid);
      if (existing >= 0) {
        _students[existing]['record_id'] = s['record_id'] ?? _students[existing]['record_id'];
        final ctrl = _scoreCtrl[mid];
        if (ctrl != null && ctrl.text.trim().isEmpty && s['score'] != null) {
          ctrl.text = '${s['score']}';
        }
        final remark = _remarkCtrl[mid];
        if (remark != null && remark.text.trim().isEmpty) {
          final text = '${s['remarks'] ?? s['remark'] ?? ''}';
          if (text.isNotEmpty) remark.text = text;
        }
      } else {
        _scoreCtrl[mid] = TextEditingController(text: s['score'] != null ? '${s['score']}' : '');
        _remarkCtrl[mid] = TextEditingController(text: '${s['remarks'] ?? s['remark'] ?? ''}');
        _students.add({...s, 'member_id': mid});
      }
    }
  }

  void _setupStudents(List<Map<String, dynamic>> students) {
    _scoreCtrl.values.forEach((c) => c.dispose());
    _remarkCtrl.values.forEach((c) => c.dispose());
    _scoreCtrl.clear();
    _remarkCtrl.clear();
    int graded = 0;
    final clean = <Map<String, dynamic>>[];
    for (final s in students) {
      final mid = RosterParse.asInt(s['member_id']) ?? RosterParse.asInt(s['id']);
      if (mid == null) continue;
      final score = s['score'];
      _scoreCtrl[mid] = TextEditingController(text: score != null ? '$score' : '');
      _remarkCtrl[mid] = TextEditingController(text: '${s['remarks'] ?? s['remark'] ?? ''}');
      if (score != null) graded++;
      clean.add({...s, 'member_id': mid});
    }
    _students = clean;
    _gradedCount = graded;
  }

  void _recountGraded() {
    int n = 0;
    for (final s in _students) {
      final mid = RosterParse.asInt(s['member_id']);
      if (mid == null) continue;
      if ((_scoreCtrl[mid]?.text.trim() ?? '').isNotEmpty) n++;
    }
    if (n != _gradedCount) setState(() => _gradedCount = n);
  }

  Future<void> _persistLocal() async {
    final grades = <Map<String, dynamic>>[];
    for (final s in _students) {
      final mid = RosterParse.asInt(s['member_id']);
      if (mid == null) continue;
      final text = _scoreCtrl[mid]?.text.trim() ?? '';
      if (text.isEmpty) continue;
      final score = double.tryParse(text);
      if (score == null) continue;
      grades.add({
        'member_id': mid,
        'score': score,
        'remark': _remarkCtrl[mid]?.text.trim() ?? '',
        'record_id': s['record_id'],
        'student_name': s['student_name'],
      });
    }
    if (grades.isEmpty) return;
    await _db.saveGradesLocal(
      widget.assessmentId, widget.assessmentName,
      widget.classId, widget.className,
      widget.subjectId, widget.subjectName,
      widget.maxScore, grades,
    );
  }

  bool get _locked =>
      _packetStatus == 'submitted' || _packetStatus == 'approved';

  Future<void> _saveGrades() async {
    if (_locked) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Already submitted. Only Education can change this.'),
          backgroundColor: AppTheme.warning));
      return;
    }

    final grades = <Map<String, dynamic>>[];
    for (final s in _students) {
      final mid = s['member_id'] as int;
      final text = _scoreCtrl[mid]?.text.trim() ?? '';
      final remark = _remarkCtrl[mid]?.text.trim() ?? '';
      if (text.isNotEmpty) {
        final score = double.tryParse(text);
        if (score != null && score >= 0 && score <= widget.maxScore) {
          grades.add({
            'member_id': mid, 'score': score, 'remark': remark,
            'record_id': s['record_id'], 'student_name': s['student_name'],
          });
        }
      }
    }

    if (grades.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('No valid grades to save'), backgroundColor: AppTheme.warning));
      return;
    }

    await _db.saveGradesLocal(
      widget.assessmentId, widget.assessmentName,
      widget.classId, widget.className,
      widget.subjectId, widget.subjectName,
      widget.maxScore, grades,
      packetKind: 'draft',
    );
    if (!mounted) return;
    setState(() {
      _packetStatus = _packetStatus.isEmpty ? 'draft' : _packetStatus;
      _saving = true;
    });
    try {
      final result = await SyncService().syncAll(force: true);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(result.synced > 0 && result.failed == 0
            ? 'Sent to Education'
            : result.message),
        backgroundColor: result.failed > 0 ? AppTheme.warning : AppTheme.success,
        duration: const Duration(seconds: 3),
      ));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _submitGrades() async {
    if (_locked) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Already submitted. Only Education can change this.'),
          backgroundColor: AppTheme.warning));
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Submit mark list?'),
        content: Text('Use this when the test is finished. Education will treat ${widget.assessmentName} as done.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Not yet')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Submit')),
        ],
      ),
    );
    if (ok != true) return;

    final grades = <Map<String, dynamic>>[];
    for (final s in _students) {
      final mid = RosterParse.asInt(s['member_id']);
      if (mid == null) continue;
      final text = _scoreCtrl[mid]?.text.trim() ?? '';
      if (text.isEmpty) continue;
      final score = double.tryParse(text);
      if (score == null || score < 0 || score > widget.maxScore) continue;
      grades.add({
        'member_id': mid,
        'score': score,
        'remark': _remarkCtrl[mid]?.text.trim() ?? '',
        'record_id': s['record_id'],
        'student_name': s['student_name'],
      });
    }
    if (grades.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Enter at least one valid score first'), backgroundColor: AppTheme.warning));
      return;
    }
    await _db.saveGradesLocal(
      widget.assessmentId, widget.assessmentName,
      widget.classId, widget.className,
      widget.subjectId, widget.subjectName,
      widget.maxScore, grades,
      packetKind: 'submitted',
    );
    if (!mounted) return;
    setState(() => _saving = true);
    try {
      final result = await SyncService().syncAll(force: true);
      if (!mounted) return;
      setState(() {
        if (result.synced > 0 && result.failed == 0) {
          _packetStatus = 'submitted';
        }
      });
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(result.synced > 0 && result.failed == 0
            ? 'Submitted to Education'
            : result.message),
        backgroundColor: result.failed > 0 ? AppTheme.warning : AppTheme.success,
      ));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(widget.assessmentName, style: const TextStyle(fontSize: 16)),
          Text('${widget.className} · Max: ${widget.maxScore.toStringAsFixed(0)}',
              style: const TextStyle(fontSize: 11, color: Colors.white70)),
        ]),
        actions: [

        ],
      ),
      bottomNavigationBar: _students.isEmpty
          ? null
          : TeacherActionBar(
              saveLabel: 'Save',
              submitLabel: 'Submit',
              onSave: _saveGrades,
              onSubmit: _submitGrades,
              busy: _saving,
              hint: 'Save sends a draft to Education. Submit when the test is finished.',
            ),
      body: _loading
          ? const StudentListSkeleton()
          : _error != null
              ? Padding(padding: const EdgeInsets.all(16), child: AppErrorCard(error: AppError.fromMessage(_error), onRetry: _loadStudents, autoRetry: true))
              : _students.isEmpty
                  ? EmptyState(
                      icon: Icons.people_outline,
                      title: 'No students in this class yet',
                      subtitle: 'If they were enrolled on the website, tap Refresh. Education can add them under Enrollment.',
                      action: TextButton.icon(
                        onPressed: _loadStudents,
                        icon: const Icon(Icons.refresh, size: 18),
                        label: const Text('Refresh'),
                      ),
                    )
                  : Column(children: [
                      if (_isOffline)
                        Container(width: double.infinity, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                          color: AppTheme.warning.withOpacity(0.15),
                          child: Row(children: [
                            Icon(Icons.cloud_off, size: 16, color: AppTheme.warning),
                            const SizedBox(width: 8),
                            Expanded(child: Text('Waiting for network — scores stay on this phone until you are back online',
                                style: TextStyle(fontSize: 11, color: AppTheme.warning, fontWeight: FontWeight.w500))),
                          ])),
                      if (_rosterNote != null)
                        StatusBanner.warning(_rosterNote!),
                      Container(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10), color: AppTheme.surfaceLight,
                        child: Row(children: [
                          Text('${_students.length} students', style: TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
                          const Spacer(),
                          Text('$_gradedCount of ${_students.length} graded', style: TextStyle(fontSize: 12,
                              color: _gradedCount > 0 ? AppTheme.success : AppTheme.textSecondary, fontWeight: FontWeight.w600)),
                        ])),
                      Expanded(child: ListView.builder(
                        itemCount: _students.length,
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        itemBuilder: (_, i) => _studentRow(i),
                      )),
                    ]),
    );
  }

  Widget _studentRow(int index) {
    final s = _students[index];
    final mid = RosterParse.asInt(s['member_id']);
    if (mid == null) return const SizedBox.shrink();
    final hasScore = _scoreCtrl[mid]?.text.isNotEmpty ?? false;

    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              SizedBox(width: 28, child: Text('${index + 1}', style: TextStyle(fontSize: 12, color: AppTheme.textSecondary, fontWeight: FontWeight.w600))),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${s['student_name'] ?? ''} ${s['father_name'] ?? ''}',
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500), maxLines: 1, overflow: TextOverflow.ellipsis),
                if (s['member_code'] != null && s['member_code'].toString().isNotEmpty)
                  Text(s['member_code'], style: TextStyle(fontSize: 10, color: AppTheme.textSecondary)),
              ])),
              SizedBox(width: 72, height: 38,
                child: TextField(
                  controller: _scoreCtrl[mid],
                  readOnly: _locked,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[\d.]'))],
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: hasScore ? AppTheme.primary : null),
                  decoration: InputDecoration(
                    hintText: '—', hintStyle: TextStyle(color: AppTheme.textSecondary),
                    contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                    enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(8),
                      borderSide: BorderSide(color: hasScore ? AppTheme.primary.withOpacity(0.3) : AppTheme.borderLight)),
                  ),
                  textInputAction: TextInputAction.next,
                  onChanged: (_) {
                    _recountGraded();
                    _persistLocal();
                  },
                ),
              ),
              const SizedBox(width: 6),
              Text('/ ${widget.maxScore.toStringAsFixed(0)}',
                  style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
            ]),
            const SizedBox(height: 6),
            TextField(
              controller: _remarkCtrl[mid],
              readOnly: _locked,
              style: const TextStyle(fontSize: 12),
              decoration: InputDecoration(
                hintText: 'Remark (optional)',
                hintStyle: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
                isDense: true,
                contentPadding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(6)),
              ),
              onChanged: (_) => _persistLocal(),
            ),
          ],
        ),
      ),
    );
  }
}


