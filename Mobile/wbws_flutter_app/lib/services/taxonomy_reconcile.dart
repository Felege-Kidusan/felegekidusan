/// ══════════════════════════════════════════════════════════════
/// Taxonomy reconciliation (categories / singers)
/// ══════════════════════════════════════════════════════════════
/// The categories and zemarians endpoints return the COMPLETE canonical
/// list, not a delta. There is therefore no tombstone to pull: the only
/// evidence that a row was deleted server-side is its ABSENCE from that
/// list. A purely additive upsert can never notice, which is why a
/// category deleted in the web admin used to live on every phone
/// forever.
///
/// This module owns the "what should I delete" decision so it can be
/// unit-tested. It deletes user-visible data, so the guards matter more
/// than the happy path:
///
///   • negative ids are offline-created rows that have not been pushed
///     yet. They are not missing from the server — they were never sent.
///     Sweeping them would destroy unsynced work.
///   • protected ids have a queued edit in the outbox; the server has
///     not seen them either.
///
/// [LocalDb.upsertCategories] / [LocalDb.upsertZemarians] apply exactly
/// these three guards in SQL, in this order.
class TaxonomyReconcile {
  const TaxonomyReconcile._();

  /// Local ids that no longer exist on the server and are safe to drop.
  static List<int> staleIds({
    required Iterable<int> localIds,
    required Set<int> serverIds,
    Set<int> protectIds = const {},
  }) {
    final stale = <int>[];
    for (final id in localIds) {
      if (id < 0) continue; // offline-created, never pushed
      if (serverIds.contains(id)) continue; // still on the server
      if (protectIds.contains(id)) continue; // queued local edit
      stale.add(id);
    }
    return stale;
  }

  /// Guard for the caller: an empty list is only meaningful when the
  /// request actually succeeded. A failed/offline call must never be
  /// read as "the server has no categories" — that would wipe the local
  /// taxonomy on every flaky connection.
  static bool mayReconcile(
          {required bool requestSucceeded, required bool payloadIsList}) =>
      requestSucceeded && payloadIsList;
}
