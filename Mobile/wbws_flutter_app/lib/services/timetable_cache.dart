import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Offline copy of the signed-in teacher's weekly timetable
/// (`GET /timetable/week`). One small JSON blob per user id, so a phone
/// shared by two teachers never shows the other person's schedule.
/// Cleared on destructive sign-out together with the other caches.
class TimetableCache {
  TimetableCache._();
  static final TimetableCache instance = TimetableCache._();

  static const _prefix = 'timetable_week_v1_';

  String _key(int userId) => '$_prefix$userId';

  Future<void> save(int userId, Map<String, dynamic> week) async {
    if (userId <= 0) return;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
      _key(userId),
      jsonEncode({
        'owner': userId,
        'saved_at': DateTime.now().toIso8601String(),
        'week': week,
      }),
    );
  }

  /// Returns the cached week for [userId] (never another user's), or null.
  Future<Map<String, dynamic>?> read(int userId) async {
    if (userId <= 0) return null;
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key(userId));
    if (raw == null || raw.isEmpty) return null;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) return null;
      if (decoded['owner'] != userId) return null;
      final week = decoded['week'];
      if (week is! Map) return null;
      final out = Map<String, dynamic>.from(week);
      out['_cached_at'] = decoded['saved_at'];
      return out;
    } catch (_) {
      return null;
    }
  }

  Future<void> clearAll() async {
    final prefs = await SharedPreferences.getInstance();
    for (final k in prefs.getKeys().where((k) => k.startsWith(_prefix)).toList()) {
      await prefs.remove(k);
    }
  }
}
