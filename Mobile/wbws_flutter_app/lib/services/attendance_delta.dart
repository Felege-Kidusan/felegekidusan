/// Pure reconciliation logic for attendance change-feed pages.
///
/// Kept free of sqflite and Flutter on purpose: this is the part that
/// decides what a change *means* for the local cache, and it should be
/// testable without a database or a device. LocalDb.applyAttendanceDelta
/// supplies the transaction and the storage; this supplies the rules.
///
/// The cache is sheet-shaped. `cached_attendance` holds one JSON sheet
/// per (class_id, date), and a sheet's `students` entries are roster
/// rows keyed by member_id with a nullable attendance status. So a feed
/// entry is applied by patching one student entry inside one sheet.
library;

/// Where a change lands, and what it should do when it gets there.
class AttendanceDeltaTarget {
  final int classId;
  final String date;
  final int memberId;

  /// null means "unmark": the attendance record was deleted, so the
  /// student goes back to having no status. The roster entry itself is
  /// enrollment data and is never removed by a sync.
  final String? status;
  final String notes;

  const AttendanceDeltaTarget({
    required this.classId,
    required this.date,
    required this.memberId,
    required this.status,
    required this.notes,
  });

  String get sheetKey => '$classId|$date';

  bool get isDeletion => status == null;
}

class AttendanceDelta {
  /// Resolve one feed entry into a target, or null when it cannot be
  /// addressed in this cache shape.
  ///
  /// [item] is the feed entry (op, entity_id, and the scope keys
  /// class_id / member_id / date that migration 058 attaches so a
  /// tombstone stays self-describing). [record] is the canonical server
  /// row, absent for a deletion.
  ///
  /// The canonical record wins when present, because it reflects where
  /// the row is *now*; the feed's scope keys are the fallback and the
  /// only thing a tombstone has.
  static AttendanceDeltaTarget? resolve(
    Map<String, dynamic> item,
    Map<String, dynamic>? record,
  ) {
    final op = '${item['op'] ?? ''}';
    final deleted = op == 'DELETE' || record == null;

    final classId = _asInt(record?['class_id'] ?? item['class_id']);
    final memberId = _asInt(record?['member_id'] ?? item['member_id']);
    final date = '${record?['attendance_date'] ?? item['date'] ?? ''}';

    if (classId == null || memberId == null || date.isEmpty) {
      // Older servers (pre-058) omit the scope keys, so a tombstone from
      // one is not actionable. Skipping is correct: guessing which sheet
      // to edit would corrupt data, and the next bootstrap repairs it.
      return null;
    }

    return AttendanceDeltaTarget(
      classId: classId,
      date: date,
      memberId: memberId,
      status: deleted ? null : _asStatus(record['status']),
      notes: deleted ? '' : '${record['notes'] ?? ''}',
    );
  }

  /// Apply one target to a decoded sheet, returning true when the sheet
  /// actually changed.
  ///
  /// Idempotent by construction: it sets the student's status to the
  /// server's value, so replaying a page converges on the same sheet
  /// rather than accumulating anything.
  static bool applyToSheet(
    Map<String, dynamic> sheet,
    AttendanceDeltaTarget target,
  ) {
    final students = sheet['students'];
    if (students is! List) return false;

    for (var i = 0; i < students.length; i++) {
      final student = students[i];
      if (student is! Map) continue;
      final id = _asInt(student['member_id'] ?? student['id']);
      if (id != target.memberId) continue;

      final updated = Map<String, dynamic>.from(student);
      updated['status'] = target.status;
      updated['att_status'] = target.status;
      updated['notes'] = target.notes;
      students[i] = updated;
      return true;
    }
    // The student is not on this cached roster. Nothing to correct, and
    // adding them would invent roster data the server never sent.
    return false;
  }

  static String? _asStatus(dynamic value) {
    if (value == null) return null;
    final text = '$value'.trim();
    return text.isEmpty ? null : text;
  }

  static int? _asInt(dynamic value) {
    if (value == null) return null;
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value');
  }
}
