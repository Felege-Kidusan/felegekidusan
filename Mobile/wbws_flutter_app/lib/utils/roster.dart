/// Safe parsing of class-roster API payloads.
/// Never throws — a bad row is skipped instead of emptying the whole screen.
class RosterParse {
  static List<Map<String, dynamic>> students(dynamic data) {
    if (data is! Map) return const [];
    final raw = data['students'];
    if (raw is! List) return const [];
    final out = <Map<String, dynamic>>[];
    for (final item in raw) {
      if (item is! Map) continue;
      final m = Map<String, dynamic>.from(item);
      final id = asInt(m['member_id']) ?? asInt(m['id']);
      if (id == null || id <= 0) continue;
      m['member_id'] = id;
      out.add(m);
    }
    return out;
  }

  static int reportedCount(dynamic data) {
    if (data is! Map) return 0;
    return asInt(data['count']) ?? 0;
  }

  static bool fallback(dynamic data) {
    if (data is! Map) return false;
    final v = data['roster_fallback'];
    return v == true || v == 1 || v == '1';
  }

  static String? yearName(dynamic data) {
    if (data is! Map) return null;
    final n = data['roster_year_name'];
    if (n == null) return null;
    final s = n.toString().trim();
    return s.isEmpty ? null : s;
  }

  static int? asInt(dynamic v) {
    if (v == null) return null;
    if (v is int) return v;
    if (v is num) return v.toInt();
    return int.tryParse(v.toString());
  }
}
