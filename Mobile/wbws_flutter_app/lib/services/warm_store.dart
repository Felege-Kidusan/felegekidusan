import 'api_service.dart';
import 'catalog_service.dart';
import 'local_db.dart';

/// Prefetch the next screen while the teacher is still on Home.
/// One class, today's sheet, that class's subjects — nothing more,
/// so a TECNO on 2G is not asked to download the whole school.
class WarmStore {
  static final WarmStore _instance = WarmStore._internal();
  factory WarmStore() => _instance;
  WarmStore._internal();

  bool _running = false;

  Future<void> afterLogin() async {
    if (_running || !ApiService().isLoggedIn) return;
    _running = true;
    try {
      final classes = await CatalogService().classes();
      if (classes.isEmpty) return;
      final id = _asInt(classes.first['id']);
      if (id == null) return;
      await Future.wait<void>([
        _warmAttendance(id),
        _warmGrades(id),
      ]);
    } catch (_) {
      // Never block the UI. Next open will try again.
    } finally {
      _running = false;
    }
  }

  Future<void> _warmAttendance(int classId) async {
    final db = LocalDb();
    final date = _today();
    final cached = await db.getCachedAttendanceResponse(classId, date);
    if (cached != null && cached.isNotEmpty) return;
    final res = await ApiService().getAttendance(classId, date: date);
    if (!res.success || res.data == null) return;
    final raw = res.data['students'];
    if (raw is! List || raw.isEmpty) return;
    final students = raw
        .whereType<Map>()
        .map((e) => Map<String, dynamic>.from(e))
        .toList();
    if (students.isEmpty) return;
    await db.cacheAttendanceResponse(classId, date, students);
    await db.cacheStudents(classId, students);
  }

  Future<void> _warmGrades(int classId) async {
    final db = LocalDb();
    final have = await db.getCachedSubjects(classId);
    if (have.isNotEmpty) return;
    final res = await ApiService().getGradeBootstrap(classId);
    if (!res.success || res.data == null) return;
    final subjects = res.data['subjects'];
    if (subjects is List && subjects.isNotEmpty) {
      await db.cacheSubjects(classId, subjects);
    }
    final assessments = res.data['assessments'];
    if (assessments is List && assessments.isNotEmpty) {
      final bySubject = <int, List<dynamic>>{};
      for (final a in assessments) {
        if (a is! Map) continue;
        final sid = _asInt(a['subject_id']);
        if (sid == null) continue;
        bySubject.putIfAbsent(sid, () => []).add(a);
      }
      for (final e in bySubject.entries) {
        await db.cacheAssessments(classId, e.key, e.value);
      }
    }
  }

  static int? _asInt(dynamic v) {
    if (v is int) return v;
    return int.tryParse('$v');
  }

  static String _today() {
    final n = DateTime.now();
    final m = n.month.toString().padLeft(2, '0');
    final d = n.day.toString().padLeft(2, '0');
    return '${n.year}-$m-$d';
  }
}
