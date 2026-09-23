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

  int? _runningGeneration;
  bool Function()? activeSessionGate;
  int Function()? sessionGenerationProvider;

  bool _ownsGeneration(int generation) =>
      activeSessionGate?.call() != false &&
      generation == (sessionGenerationProvider?.call() ?? generation);

  Future<void> afterLogin() async {
    if (activeSessionGate?.call() == false || !ApiService().isLoggedIn) {
      return;
    }
    final generation = sessionGenerationProvider?.call() ?? 0;
    if (_runningGeneration == generation) return;
    _runningGeneration = generation;
    try {
      final classes = await CatalogService()
          .classes(expectedGeneration: generation);
      if (!_ownsGeneration(generation) || classes.isEmpty) return;
      final id = _asInt(classes.first['id']);
      if (id == null) return;
      await Future.wait<void>([
        _warmAttendance(id, generation),
        _warmGrades(id, generation),
      ]);
    } catch (_) {
      // Never block the UI. Next open will try again.
    } finally {
      if (_runningGeneration == generation) _runningGeneration = null;
    }
  }

  Future<void> _warmAttendance(int classId, int generation) async {
    final db = LocalDb();
    final date = _today();
    final cached = await db.getCachedAttendanceResponse(classId, date);
    if (!_ownsGeneration(generation) ||
        (cached != null && cached.isNotEmpty)) {
      return;
    }
    final res = await ApiService().getAttendance(classId, date: date);
    if (!_ownsGeneration(generation) ||
        res.sessionSuperseded ||
        !res.success ||
        res.data == null) {
      return;
    }
    final raw = res.data['students'];
    if (raw is! List || raw.isEmpty) return;
    final students = raw
        .whereType<Map>()
        .map((e) => Map<String, dynamic>.from(e))
        .toList();
    if (students.isEmpty || !_ownsGeneration(generation)) return;
    await db.cacheAttendanceResponse(classId, date, students);
    if (!_ownsGeneration(generation)) return;
    await db.cacheStudents(classId, students);
  }

  Future<void> _warmGrades(int classId, int generation) async {
    final db = LocalDb();
    final have = await db.getCachedSubjects(classId);
    if (!_ownsGeneration(generation) || have.isNotEmpty) return;
    final res = await ApiService().getGradeBootstrap(classId);
    if (!_ownsGeneration(generation) ||
        res.sessionSuperseded ||
        !res.success ||
        res.data == null) {
      return;
    }
    final subjects = res.data['subjects'];
    if (subjects is List && subjects.isNotEmpty) {
      if (!_ownsGeneration(generation)) return;
      await db.cacheSubjects(classId, subjects);
    }
    if (!_ownsGeneration(generation)) return;
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
        if (!_ownsGeneration(generation)) return;
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
