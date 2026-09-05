/// Which hymns should hold a resolved audio source at any moment (P36).
///
/// Background — why a window rather than "sign everything".
///
/// Mezmur audio lives in Cloudflare R2 and is reachable only through a
/// signed URL minted per hymn by `GET /mezmur/audio/{id}` (expiry 3600 s,
/// endpoint rate-limited to 240 requests). Two naive designs both fail:
///
///  * **Sign the whole catalog up front** — a 200-hymn list would fire
///    200 requests, blow the rate limit, and most links would expire
///    before the listener reached them.
///  * **Sign only the selected hymn** (what the code used to do) — the
///    just_audio queue then holds a single item, so native gapless
///    advance has nowhere to go and the lock-screen / headset / Bluetooth
///    next-track button does nothing.
///
/// The industry answer is a bounded prefetch window: resolve the current
/// item plus a few neighbours, and slide the window forward as playback
/// advances. This keeps the engine queue genuinely multi-item (so system
/// transport controls work) while keeping requests and URL freshness
/// under control.
///
/// Pure logic — no engine, no network, no Flutter — so it is unit
/// testable.
class MezmurQueueWindow {
  const MezmurQueueWindow._();

  /// Default look-ahead. Three is enough for gapless advance plus a
  /// couple of rapid skips, without pre-signing links that will likely
  /// expire unused.
  static const int defaultAhead = 3;

  /// Keeping one behind makes "previous" instant, which users expect.
  static const int defaultBehind = 1;

  /// Catalog rows that should hold a resolved source, given the row the
  /// listener is on.
  ///
  /// [playable] is one flag per catalog row (true when the hymn has
  /// audio: downloaded, or `audio_status == 'ready'`). Only playable rows
  /// are ever returned. The result is ascending and always contains
  /// [centerRow] when that row is playable, so the thing the user is
  /// looking at is never the item left unresolved.
  ///
  /// When [loop] is true the window wraps around the ends, matching
  /// repeat-all: the last hymn's "next" really is the first one.
  static List<int> plan({
    required List<bool> playable,
    required int centerRow,
    int ahead = defaultAhead,
    int behind = defaultBehind,
    bool loop = false,
  }) {
    final total = playable.length;
    if (total == 0) return const [];
    if (ahead < 0) ahead = 0;
    if (behind < 0) behind = 0;

    // Walk outward over PLAYABLE rows only, so lyrics-only hymns sitting
    // between two songs do not consume window slots.
    final picked = <int>{};
    final center = centerRow.clamp(0, total - 1);
    if (playable[center]) picked.add(center);

    var forwardFound = 0;
    for (var step = 1; step <= total && forwardFound < ahead; step++) {
      var i = center + step;
      if (i >= total) {
        if (!loop) break;
        i %= total;
        if (i == center) break;
      }
      if (!playable[i]) continue;
      picked.add(i);
      forwardFound++;
    }

    var backFound = 0;
    for (var step = 1; step <= total && backFound < behind; step++) {
      var i = center - step;
      if (i < 0) {
        if (!loop) break;
        i %= total;
        if (i < 0) i += total;
        if (i == center) break;
      }
      if (!playable[i]) continue;
      picked.add(i);
      backFound++;
    }

    final out = picked.toList()..sort();
    return out;
  }

  /// True when the window should be recomputed because playback has
  /// moved close to its leading edge.
  ///
  /// [resolvedRows] is the ascending set currently backed by a source and
  /// [currentRow] is where the listener now is. Refreshing once fewer
  /// than [slack] resolved rows remain ahead keeps the next hymn ready
  /// before it is needed, without re-signing on every track change.
  static bool needsRefresh({
    required List<int> resolvedRows,
    required int currentRow,
    required List<bool> playable,
    int slack = 1,
  }) {
    if (resolvedRows.isEmpty) return true;
    // The row being played must always be backed.
    if (!resolvedRows.contains(currentRow)) return true;
    // Count resolved rows still ahead of the listener.
    var ahead = 0;
    for (final r in resolvedRows) {
      if (r > currentRow) ahead++;
    }
    if (ahead > slack) return false;
    // Only refresh when there is actually something further to reach;
    // at the true end of the list there is nothing left to prepare.
    for (var i = currentRow + 1; i < playable.length; i++) {
      if (playable[i]) return true;
    }
    return false;
  }
}
