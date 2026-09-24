import 'dart:async';

/// Tiny in-app switcher so Home can open the Attendance tab
/// instead of pushing a second Attendance screen.
class AppNav {
  static final AppNav _instance = AppNav._internal();
  factory AppNav() => _instance;
  AppNav._internal();

  int? attendanceClassId;
  DateTime? _lastGradesLoad;
  DateTime? _lastAttendanceLoad;

  final _tab = StreamController<String>.broadcast();
  Stream<String> get tabStream => _tab.stream;

  void openAttendance({int? classId}) {
    attendanceClassId = classId;
    _tab.add('attendance');
  }

  /// Switch to the HR department's own attendance tab (section sheets).
  void openHrAttendance() => _tab.add('hr_attendance');

  void markAttendanceLoaded() => _lastAttendanceLoad = DateTime.now();
  void markGradesLoaded() => _lastGradesLoad = DateTime.now();

  /// Drop every shell-local navigation hint before a new authorization scope
  /// builds its own tabs. No old class id or freshness timestamp may influence
  /// the replacement shell.
  void resetForAuthorizationScope() {
    attendanceClassId = null;
    _lastGradesLoad = null;
    _lastAttendanceLoad = null;
  }

  bool shouldReload(String tab, {Duration freshFor = const Duration(seconds: 90)}) {
    final last = tab == 'grades' ? _lastGradesLoad : _lastAttendanceLoad;
    if (last == null) return true;
    return DateTime.now().difference(last) > freshFor;
  }
}
