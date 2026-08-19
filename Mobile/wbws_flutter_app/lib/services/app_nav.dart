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

  void markAttendanceLoaded() => _lastAttendanceLoad = DateTime.now();
  void markGradesLoaded() => _lastGradesLoad = DateTime.now();

  bool shouldReload(String tab, {Duration freshFor = const Duration(seconds: 90)}) {
    final last = tab == 'grades' ? _lastGradesLoad : _lastAttendanceLoad;
    if (last == null) return true;
    return DateTime.now().difference(last) > freshFor;
  }
}
