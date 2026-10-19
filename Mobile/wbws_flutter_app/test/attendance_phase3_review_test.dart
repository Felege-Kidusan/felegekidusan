import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/inbox_view_model.dart';

/// Attendance alerting Phase 3 (owner Q-2, 2026-10-19): the bell deep-link
/// for a returned / reopened / rejected attendance sheet.
void main() {
  group('sheetTarget (review bell deep-link)', () {
    test('null for rows without a sheet target', () {
      expect(sheetTarget({}), isNull);
      expect(sheetTarget({'target': null}), isNull);
      expect(sheetTarget({'target': {'kind': 'member', 'id': 5}}), isNull);
      expect(
          sheetTarget({
            'target': {'kind': 'attendance_sheet', 'department': 'hr'}
          }),
          isNull);
    });

    test('education sheet → class + Gregorian date', () {
      final t = sheetTarget({
        'target': {
          'kind': 'attendance_sheet',
          'id': 12,
          'department': 'edu',
          'class_id': '3',
          'section': '',
          'attendance_date': '2026-09-28',
        }
      });
      expect(t, isNotNull);
      expect(t!['department'], 'edu');
      expect(t['classId'], 3);
      expect(t['date'], '2026-09-28');
    });

    test('mezmur sheet → section + date; bad date becomes empty', () {
      final t = sheetTarget({
        'target': {
          'kind': 'attendance_sheet',
          'id': 7,
          'department': 'mezmur',
          'section': 'Choir A',
          'attendance_date': 'not-a-date',
        }
      });
      expect(t, isNotNull);
      expect(t!['department'], 'mezmur');
      expect(t['section'], 'Choir A');
      expect(t['classId'], 0);
      expect(t['date'], '');
    });
  });
}
