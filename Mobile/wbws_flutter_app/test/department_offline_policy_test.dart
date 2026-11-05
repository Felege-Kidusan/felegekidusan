import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/department_offline_policy.dart';

void main() {
  test('exactly two attendance scopes are offline today', () {
    expect(DepartmentOfflinePolicy.offlineEnabledToday, hasLength(2));
    expect(DepartmentOfflinePolicy.isOfflineEnabled(OfflineDepartment.educationAttendance), isTrue);
    expect(DepartmentOfflinePolicy.isOfflineEnabled(OfflineDepartment.mezmurAttendance), isTrue);
  });

  test('grades is planned, not enabled', () {
    expect(DepartmentOfflinePolicy.isPlanned(OfflineDepartment.grades), isTrue);
    expect(DepartmentOfflinePolicy.isOfflineEnabled(OfflineDepartment.grades), isFalse);
  });

  test('other departments are online-only and not offline-enabled', () {
    for (final d in DepartmentOfflinePolicy.onlineOnly) {
      expect(DepartmentOfflinePolicy.isOnlineOnly(d), isTrue);
      expect(DepartmentOfflinePolicy.isOfflineEnabled(d), isFalse);
    }
    expect(DepartmentOfflinePolicy.onlineOnly, contains(OfflineDepartment.members));
    expect(DepartmentOfflinePolicy.onlineOnly, contains(OfflineDepartment.finance));
  });

  test('no department is both offline-enabled and online-only', () {
    final overlap = DepartmentOfflinePolicy.offlineEnabledToday.intersection(DepartmentOfflinePolicy.onlineOnly);
    expect(overlap, isEmpty);
    final overlapPlanned = DepartmentOfflinePolicy.plannedNext.intersection(DepartmentOfflinePolicy.offlineEnabledToday);
    expect(overlapPlanned, isEmpty);
  });
}
