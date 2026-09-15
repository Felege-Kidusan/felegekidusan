import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/utils/notification_icons.dart';

/// P1 audit C7 — pins for the mobile port of the web's ICONS table
/// (admin/js/comm.js iconFor()). Type strings are matched by
/// substring, first rule wins, and anything unmatched falls back to
/// the bell — exactly like the web.
void main() {
  test('member → person', () {
    expect(notificationTypeIcon('member_registered'),
        Icons.person_outline_rounded);
    expect(notificationTypeIcon('MEMBER_UPDATED'),
        Icons.person_outline_rounded);
  });

  test('class / enrollment → school', () {
    expect(notificationTypeIcon('class_created'), Icons.school_rounded);
    expect(notificationTypeIcon('enrollment_approved'), Icons.school_rounded);
  });

  test('attendance → fact check', () {
    expect(notificationTypeIcon('attendance_marked'),
        Icons.fact_check_outlined);
  });

  test('grade / marks → graduation premium', () {
    expect(notificationTypeIcon('grade_entered'),
        Icons.workspace_premium_outlined);
    expect(notificationTypeIcon('marks_updated'),
        Icons.workspace_premium_outlined);
  });

  test('task → checklist', () {
    expect(notificationTypeIcon('task_assigned'), Icons.checklist_rounded);
  });

  test('role → badge', () {
    expect(notificationTypeIcon('role_changed'), Icons.badge_outlined);
  });

  test('document / share → description', () {
    expect(notificationTypeIcon('document_shared'),
        Icons.description_outlined);
    expect(notificationTypeIcon('share_revoked'), Icons.description_outlined);
  });

  test('sync / change → sync', () {
    expect(notificationTypeIcon('sync_completed'), Icons.sync_rounded);
    expect(notificationTypeIcon('password_changed'), Icons.sync_rounded);
  });

  test('unknown / empty / null → bell fallback', () {
    expect(notificationTypeIcon('mystery'), Icons.notifications_rounded);
    expect(notificationTypeIcon(''), Icons.notifications_rounded);
    expect(notificationTypeIcon(null), Icons.notifications_rounded);
  });
}
