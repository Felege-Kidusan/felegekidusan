/// Duplicate-name detection for the Mezmur taxonomy (P35).
///
/// Categories, sub-categories and singers must each have a unique name
/// within their scope. The previous check compared raw lowercased
/// strings, so "test", "Test ", "test " and "te st" (double space) were
/// all considered different and every one of them could be created.
///
/// Pure logic, no database and no Flutter, so it is unit-testable.
class TaxonomyNames {
  const TaxonomyNames._();

  /// Canonical comparison form of a display name.
  ///
  /// Case-insensitive, trims the ends, and collapses every run of
  /// whitespace (spaces, tabs, non-breaking spaces, newlines) to a
  /// single space. Two names that normalise to the same string are the
  /// same name as far as a human is concerned.
  ///
  /// Deliberately NOT stripping punctuation or Ethiopic marks: those
  /// carry meaning in hymn and singer names, so removing them would
  /// wrongly merge genuinely distinct entries.
  static String normalize(String raw) {
    final collapsed = raw
        // \s in Dart does not cover NBSP and friends, so include them.
        .replaceAll(RegExp(r'[\s\u00A0\u1680\u2000-\u200A\u202F\u205F\u3000]+'),
            ' ')
        .trim()
        .toLowerCase();
    return collapsed;
  }

  /// True when [a] and [b] are the same name after normalisation.
  static bool sameName(String a, String b) => normalize(a) == normalize(b);

  /// Whether [name] collides with an existing row.
  ///
  /// [rows] are the local taxonomy rows. [selfId] is the row being
  /// edited (so renaming a row to its own name is never a duplicate);
  /// pass 0 for a create. When [parentId] is supplied the check is
  /// scoped to that parent — the same sub-category name may exist under
  /// two different mains, which mirrors the server's unique key.
  ///
  /// [idOf], [nameOf] and [parentOf] adapt whatever row shape the caller
  /// has, so this works for categories and singers alike.
  static bool isDuplicate({
    required String name,
    required List<Map<String, dynamic>> rows,
    required int Function(Map<String, dynamic>) idOf,
    required String Function(Map<String, dynamic>) nameOf,
    int selfId = 0,
    int? parentId,
    int? Function(Map<String, dynamic>)? parentOf,
  }) =>
      findDuplicate(
        name: name,
        rows: rows,
        idOf: idOf,
        nameOf: nameOf,
        selfId: selfId,
        parentId: parentId,
        parentOf: parentOf,
      ) !=
      null;

  /// The colliding row, or null when [name] is free. Returning the row
  /// lets callers name the offender in the error message.
  static Map<String, dynamic>? findDuplicate({
    required String name,
    required List<Map<String, dynamic>> rows,
    required int Function(Map<String, dynamic>) idOf,
    required String Function(Map<String, dynamic>) nameOf,
    int selfId = 0,
    int? parentId,
    int? Function(Map<String, dynamic>)? parentOf,
  }) {
    final target = normalize(name);
    if (target.isEmpty) return null;
    for (final row in rows) {
      // Never compare a row with itself (a rename that only changes
      // case, e.g. "test" -> "Test", must be allowed).
      if (selfId != 0 && idOf(row) == selfId) continue;
      if (normalize(nameOf(row)) != target) continue;
      if (parentOf != null) {
        // Scoped uniqueness: only a collision when both sit under the
        // same parent. Nulls and 0 both mean "top level".
        final rowParent = parentOf(row) ?? 0;
        if (rowParent != (parentId ?? 0)) continue;
      }
      return row;
    }
    return null;
  }
}
