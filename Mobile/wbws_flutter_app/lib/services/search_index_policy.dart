/// Keeping the search index continuously correct (P38).
///
/// THE PROBLEM WITH P37
/// --------------------
/// P37 rebuilt the word index inside the v22 schema migration. That is
/// the classic one-time-migration trap: it fixes the index exactly once,
/// for exactly the users who cross that version boundary, and it silently
/// rots afterwards. Specifically it breaks when:
///
///   1. The NORMALISER changes. Add one homophone rule and every word
///      already in the index was tokenised by the old rules. Queries are
///      normalised by the new rules, so they no longer meet. Search
///      degrades silently — the worst failure mode, because nothing
///      errors and the user just concludes the hymn is missing.
///   2. A rebuild is INTERRUPTED. Sync, app kill or a crash mid-rebuild
///      leaves a half-populated index that looks complete forever.
///   3. Rows arrive by a path that forgets to reindex.
///
/// Hymns here are edited daily, and lyrics blobs stream in lazily long
/// after their row was cached, so (3) is not hypothetical.
///
/// THE INDUSTRY PATTERN
/// --------------------
/// Elasticsearch/Lucene cannot change an analyzer in place: the documents
/// were tokenised by the old analyzer and reinterpreting them at query
/// time is impossible, so the fix is always "build the index again under
/// the new analyzer". Production systems therefore VERSION THE ANALYZER
/// and reindex whenever the stored version differs from the code's — the
/// alias-swap/reindex pattern.
///
/// Ported to a phone:
///
///   * [kAnalyzerVersion] is bumped in code whenever tokenisation
///     changes. It is compared against the version stamped in the DB on
///     every open, NOT against the schema version. Changing the
///     normaliser therefore repairs itself with no schema migration.
///   * The stamp is written only AFTER a rebuild completes, so an
///     interrupted rebuild is retried rather than assumed good.
///   * Individual rows are marked dirty and reindexed incrementally, so
///     daily edits and late-arriving lyrics never need a full pass.
///
/// This file is pure logic so the rules are unit-testable without a
/// device.
library;

/// Tokenisation contract version.
///
/// BUMP THIS whenever `amharic_text.dart` changes how text becomes
/// tokens — a new homophone rule, a punctuation change, a different
/// minimum length. Forgetting to bump it is the one way to reintroduce
/// silent staleness, which is why the constant lives next to this
/// explanation rather than inside the DB layer.
///
/// History:
///   1 — pre-P37: raw code points, no homophone folding.
///   2 — P37: Amharic homophone folding, Ethiopic punctuation, ordinals.
const int kAnalyzerVersion = 2;

/// What the app should do to the index when it opens.
enum IndexAction {
  /// Index matches the current analyzer; only dirty rows need work.
  incremental,

  /// Analyzer changed, or a previous rebuild never finished.
  fullRebuild,
}

/// State of the stored index, as read from the metadata table.
class IndexState {
  /// Analyzer version stamped in the DB, or null when never stamped
  /// (fresh install, or an index written before versioning existed).
  final int? stampedVersion;

  /// True when a rebuild was started but never confirmed complete.
  final bool rebuildInProgress;

  /// Rows known to need reindexing.
  final int dirtyCount;

  const IndexState({
    this.stampedVersion,
    this.rebuildInProgress = false,
    this.dirtyCount = 0,
  });
}

class SearchIndexPolicy {
  /// Decides what to do on open.
  ///
  /// Deliberately conservative: anything ambiguous resolves to
  /// [IndexAction.fullRebuild]. A needless rebuild costs some battery
  /// once; a missed one silently breaks search until reinstall.
  static IndexAction decide(IndexState state,
      {int currentVersion = kAnalyzerVersion}) {
    // An unfinished rebuild means the index is a partial mixture of two
    // analyzers. It cannot be trusted even if the stamp happens to match.
    if (state.rebuildInProgress) return IndexAction.fullRebuild;
    // Never stamped: either a fresh DB or an index from before
    // versioning, which was written by analyzer v1 semantics.
    if (state.stampedVersion == null) return IndexAction.fullRebuild;
    if (state.stampedVersion != currentVersion) return IndexAction.fullRebuild;
    return IndexAction.incremental;
  }

  /// Whether a full rebuild should be allowed to run right now.
  ///
  /// A rebuild walks every cached hymn, so it must not fight the first
  /// paint. Callers pass whether the user is actively searching; if they
  /// are, the incremental path covers correctness for the rows they can
  /// actually see and the full pass waits.
  static bool mayRebuildNow({
    required bool appIsForeground,
    required bool userIsSearching,
  }) =>
      appIsForeground && !userIsSearching;

  /// Splits dirty rows into batches so a large catalogue is repaired
  /// without blocking the UI isolate for a long stretch.
  ///
  /// Returns an empty list for an empty input, and never emits an empty
  /// trailing batch.
  static List<List<int>> batches(List<int> ids, {int size = 50}) {
    if (ids.isEmpty) return const [];
    final n = size < 1 ? 1 : size;
    final out = <List<int>>[];
    for (var i = 0; i < ids.length; i += n) {
      out.add(ids.sublist(i, i + n > ids.length ? ids.length : i + n));
    }
    return out;
  }

  /// Whether this hymn's indexed content actually changed.
  ///
  /// Reindexing rewrites potentially hundreds of index rows, so on a
  /// sync that touches many hymns it is worth skipping the ones whose
  /// searchable text is unchanged. Compares the searchable fields only:
  /// a play count or a cover-image change must NOT trigger reindexing.
  static bool needsReindex({
    required String oldTitle,
    required String oldLyrics,
    required String newTitle,
    required String newLyrics,
  }) =>
      oldTitle != newTitle || oldLyrics != newLyrics;
}
