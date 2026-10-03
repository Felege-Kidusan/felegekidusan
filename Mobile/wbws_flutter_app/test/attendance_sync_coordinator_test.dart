// REQUIRES FLUTTER VERIFICATION
//
// These tests have NOT been executed. They were written in an
// environment with no Dart or Flutter SDK, so nothing below has ever
// compiled or run. Treat them as a specification of intended behaviour,
// not as evidence of it, until this passes on a real Flutter machine:
//
//     cd Mobile/wbws_flutter_app && flutter test test/attendance_sync_coordinator_test.dart
//
// They cover the reconciliation rules for the attendance change feed:
// how one feed entry is resolved to a place in the local cache, and what
// it does when applied there. That logic lives in AttendanceDelta, which
// is deliberately free of sqflite so it can be tested with nothing but
// flutter_test — no new dependencies, no database, no device.
//
// WHAT THIS FILE DOES NOT COVER, and which therefore also requires
// verification on a real environment:
//   * LocalDb.applyAttendanceDelta's transaction behaviour (that the
//     cursor and the sheet writes commit or roll back together);
//   * AttendanceSyncCoordinator's paging/bootstrap loop against a live
//     API;
//   * the SyncService wiring.
// Those need sqflite and a running app. The cursor-transaction
// behaviour is exercised at the SQL level, against real SQLite, by
// tests/security/test_attendance_sync_phase_b.py.
//
// The cache is sheet-shaped: cached_attendance stores one JSON sheet per
// (class_id, date), whose `students` entries are roster rows carrying
// member_id and a nullable attendance status. A delta patches a student
// entry. A tombstone means "this student is unmarked again" — never
// "remove this roster row", because the roster entry is enrollment data.

import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/attendance_delta.dart';

void main() {
  Map<String, dynamic> sheet() => <String, dynamic>{
        'students': <dynamic>[
          <String, dynamic>{'member_id': 1, 'student_name': 'One', 'status': null},
          <String, dynamic>{'member_id': 2, 'student_name': 'Two', 'status': 'present'},
        ],
        'submission_status': '',
        'locked': false,
      };

  Map<String, dynamic> record({
    int memberId = 1,
    String? status = 'present',
    String date = '2026-03-01',
    int classId = 10,
    String? notes,
  }) =>
      <String, dynamic>{
        'id': 99,
        'member_id': memberId,
        'class_id': classId,
        'academic_year_id': 5,
        'attendance_date': date,
        'status': status,
        'notes': notes,
      };

  Map<String, dynamic> entry(String op, {int entityId = 99, int? classId = 10, int? memberId = 1, String? date = '2026-03-01'}) =>
      <String, dynamic>{
        'op': op,
        'entity_id': entityId,
        'class_id': classId,
        'member_id': memberId,
        'date': date,
      };

  Map<String, dynamic>? studentOf(Map<String, dynamic> s, int memberId) {
    for (final student in s['students'] as List) {
      if (student is Map && student['member_id'] == memberId) {
        return Map<String, dynamic>.from(student);
      }
    }
    return null;
  }

  group('resolving a feed entry', () {
    test('cursor starts at zero conceptually: an entry needs no prior state', () {
      // resolve() is stateless; a first-ever delta resolves the same way
      // as the thousandth, which is what makes replay safe.
      final target = AttendanceDelta.resolve(entry('INSERT'), record());
      expect(target, isNotNull);
      expect(target!.classId, 10);
      expect(target.date, '2026-03-01');
      expect(target.memberId, 1);
    });

    test('the canonical record wins over the feed scope keys', () {
      // The row moved; the record says where it is now.
      final target = AttendanceDelta.resolve(
        entry('UPDATE', date: '2026-03-01'),
        record(date: '2026-03-02'),
      );
      expect(target!.date, '2026-03-02');
    });

    test('a DELETE resolves from the tombstone scope keys alone', () {
      final target = AttendanceDelta.resolve(entry('DELETE'), null);
      expect(target, isNotNull);
      expect(target!.isDeletion, isTrue);
      expect(target.classId, 10);
      expect(target.date, '2026-03-01');
    });

    test('an unaddressable entry is skipped rather than guessed at', () {
      // A pre-058 server sends no scope keys; a tombstone from one
      // cannot be located, and inventing a location would corrupt data.
      final target = AttendanceDelta.resolve(
        entry('DELETE', classId: null, memberId: null, date: null),
        null,
      );
      expect(target, isNull);
    });
  });

  group('applying to the cached sheet', () {
    test('INSERT delta marks the student', () {
      final s = sheet();
      final target = AttendanceDelta.resolve(entry('INSERT'), record(status: 'present'))!;
      expect(AttendanceDelta.applyToSheet(s, target), isTrue);
      expect(studentOf(s, 1)!['status'], 'present');
    });

    test('UPDATE delta replaces the stale local value with the server value', () {
      final s = sheet();
      final target = AttendanceDelta.resolve(
        entry('UPDATE', memberId: 2),
        record(memberId: 2, status: 'absent', notes: 'ill'),
      )!;
      expect(AttendanceDelta.applyToSheet(s, target), isTrue);
      expect(studentOf(s, 2)!['status'], 'absent');
      expect(studentOf(s, 2)!['notes'], 'ill');
    });

    test('DELETE tombstone unmarks the student and keeps the roster entry', () {
      final s = sheet();
      final target = AttendanceDelta.resolve(entry('DELETE', memberId: 2), null)!;
      expect(AttendanceDelta.applyToSheet(s, target), isTrue);
      expect((s['students'] as List).length, 2,
          reason: 'the roster entry is enrollment data and must survive');
      expect(studentOf(s, 2)!['status'], isNull);
      expect(studentOf(s, 2)!['student_name'], 'Two',
          reason: 'roster detail must not be lost when unmarking');
    });

    test('replay of the same delta is safe and converges', () {
      final s = sheet();
      final target = AttendanceDelta.resolve(entry('INSERT'), record(status: 'late'))!;
      AttendanceDelta.applyToSheet(s, target);
      final afterFirst = studentOf(s, 1);
      AttendanceDelta.applyToSheet(s, target);
      final afterSecond = studentOf(s, 1);

      expect(afterSecond, equals(afterFirst));
      expect((s['students'] as List).length, 2, reason: 'replay must not duplicate rows');
    });

    test('a student absent from this cached roster changes nothing', () {
      final s = sheet();
      final target = AttendanceDelta.resolve(entry('INSERT', memberId: 999), record(memberId: 999))!;
      expect(AttendanceDelta.applyToSheet(s, target), isFalse);
      expect((s['students'] as List).length, 2);
    });

    test('a record moved to another day clears the old sheet entry', () {
      // The server emits a DELETE for the old location and an UPDATE for
      // the new one (migration 058). Applied to the old day's sheet, the
      // DELETE half must unmark.
      final oldDay = sheet();
      final retire = AttendanceDelta.resolve(
        entry('DELETE', date: '2026-03-01'), null,
      )!;
      AttendanceDelta.applyToSheet(oldDay, retire);
      expect(studentOf(oldDay, 1)!['status'], isNull);
    });

    test('an empty status string is treated as unmarked, not as a status', () {
      final s = sheet();
      final target = AttendanceDelta.resolve(entry('UPDATE', memberId: 2), record(memberId: 2, status: ''))!;
      AttendanceDelta.applyToSheet(s, target);
      expect(studentOf(s, 2)!['status'], isNull);
    });

    test('a malformed sheet is left alone rather than throwing', () {
      // A sync must never be able to crash the app over bad cached JSON;
      // offline usability outranks applying this one change.
      final broken = <String, dynamic>{'students': 'not a list'};
      final target = AttendanceDelta.resolve(entry('INSERT'), record())!;
      expect(AttendanceDelta.applyToSheet(broken, target), isFalse);
    });
  });
}
