import 'package:shared_preferences/shared_preferences.dart';

/// 1.7.3 View-only lock for a mark list (owner 2026-10-22): the teacher locks
/// the list to cross-check marks without changing or erasing them. The choice
/// is remembered on this phone per test until the teacher unlocks it.
class ViewOnlyLock {
  ViewOnlyLock._();

  static String key(int assessmentId) => 'grades_view_only_$assessmentId';

  static Future<bool> isOn(int assessmentId) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      return prefs.getBool(key(assessmentId)) ?? false;
    } catch (_) {
      return false;
    }
  }

  static Future<void> set(int assessmentId, bool on) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      if (on) {
        await prefs.setBool(key(assessmentId), true);
      } else {
        await prefs.remove(key(assessmentId));
      }
    } catch (_) {}
  }
}
