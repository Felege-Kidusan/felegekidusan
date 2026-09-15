import 'package:flutter/material.dart';

/// P1 audit C7 — mobile port of the web's ICONS table
/// (admin/js/comm.js): a distinct icon per notification domain
/// instead of one bell for everything, so the inbox is scannable at
/// a glance. Order matters — first match wins, mirroring the web's
/// regex list exactly.
IconData notificationTypeIcon(String? type) {
  final t = (type ?? '').toLowerCase();
  if (t.contains('member')) return Icons.person_outline_rounded;
  if (t.contains('class') || t.contains('enroll')) {
    return Icons.school_rounded;
  }
  if (t.contains('attendance')) return Icons.fact_check_outlined;
  if (t.contains('grade') || t.contains('marks')) {
    return Icons.workspace_premium_outlined;
  }
  if (t.contains('task')) return Icons.checklist_rounded;
  if (t.contains('role')) return Icons.badge_outlined;
  if (t.contains('document') || t.contains('share')) {
    return Icons.description_outlined;
  }
  if (t.contains('sync') || t.contains('change')) return Icons.sync_rounded;
  return Icons.notifications_rounded;
}
