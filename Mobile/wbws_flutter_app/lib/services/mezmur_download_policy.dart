/// ══════════════════════════════════════════════════════════════
/// P33 — pure decision logic for offline downloads
/// ══════════════════════════════════════════════════════════════
/// Same discipline as `mezmur_playback_policy.dart`: every rule that
/// can be got WRONG (and would then be near-impossible to reproduce on
/// a device — a bulk download on a metered 2G link, an eviction that
/// deletes the wrong file) lives here as a pure function, so it is
/// pinned by tests instead of by hope.
class MezmurDownloadPolicy {
  const MezmurDownloadPolicy._();

  /// May the queue start a transfer right now?
  ///
  /// The rule mirrors Spotify's: downloads need a link, and unless the
  /// user explicitly allowed mobile data they need an unmetered one.
  static bool canTransfer({
    required bool hasLink,
    required bool isUnmetered,
    required bool wifiOnly,
  }) {
    if (!hasLink) return false;
    if (wifiOnly && !isUnmetered) return false;
    return true;
  }

  /// Why the queue is not moving — drives the exact message the user
  /// sees, so "nothing is happening" is never unexplained.
  /// Returns 'running' | 'no-network' | 'waiting-wifi' | 'idle'.
  static String queueStatus({
    required int queued,
    required bool hasLink,
    required bool isUnmetered,
    required bool wifiOnly,
  }) {
    if (queued <= 0) return 'idle';
    if (!hasLink) return 'no-network';
    if (wifiOnly && !isUnmetered) return 'waiting-wifi';
    return 'running';
  }

  /// Should a finished download be re-fetched?
  ///
  /// True when the server's `audio_updated_at` differs from the stamp
  /// stored beside the file. Missing-on-both is NOT stale (an old row
  /// predating the audio columns must not re-download forever).
  static bool isStale({String? storedStamp, String? serverStamp}) {
    final a = (storedStamp ?? '').trim();
    final b = (serverStamp ?? '').trim();
    if (a.isEmpty && b.isEmpty) return false;
    return a != b;
  }

  /// Should this attempt be retried, or has it failed for good?
  static bool shouldRetry({required int attempts, int maxAttempts = 4}) =>
      attempts < maxAttempts;

  /// Exponential backoff before the next attempt, capped so a queue
  /// never parks itself for minutes on a flaky link.
  static Duration backoff(int attempts) {
    final n = attempts.clamp(0, 4);
    return Duration(seconds: 2 << n);
  }

  /// Which downloads to evict, in order, to get back under [capBytes].
  ///
  /// Contract:
  ///   • cap of 0 means unlimited — never evict.
  ///   • only 'auto' rows are evictable; a hymn the user downloaded on
  ///     purpose is never silently deleted, even if that means staying
  ///     over the cap (the UI reports it instead).
  ///   • least-recently-played first (LRU).
  ///
  /// [rows] entries need: id, bytes, source, lastPlayed (sortable string).
  static List<int> evictionPlan({
    required List<DownloadRowView> rows,
    required int capBytes,
  }) {
    if (capBytes <= 0) return const [];
    var total = rows.fold<int>(0, (s, r) => s + r.bytes);
    if (total <= capBytes) return const [];
    final evictable = rows.where((r) => r.source == 'auto').toList()
      ..sort((a, b) => a.lastPlayed.compareTo(b.lastPlayed));
    final plan = <int>[];
    for (final r in evictable) {
      if (total <= capBytes) break;
      plan.add(r.id);
      total -= r.bytes;
    }
    return plan;
  }

  /// How many of [rows] a bulk "download all" would actually queue —
  /// lyrics-only hymns and already-stored ones are not work.
  static int pendingWorkCount({
    required List<String> audioStatuses,
    required List<String> currentStates,
  }) {
    var n = 0;
    for (var i = 0; i < audioStatuses.length; i++) {
      if (audioStatuses[i] != 'ready') continue;
      final st = i < currentStates.length ? currentStates[i] : 'none';
      if (st == 'done' || st == 'queued' || st == 'downloading') continue;
      n++;
    }
    return n;
  }
}

/// Minimal view of a stored download, so the policy stays free of
/// sqflite/File types and can be unit-tested with plain data.
class DownloadRowView {
  final int id;
  final int bytes;
  final String source; // 'user' | 'auto'
  final String lastPlayed; // ISO8601, '' when never played

  const DownloadRowView({
    required this.id,
    required this.bytes,
    required this.source,
    this.lastPlayed = '',
  });
}
