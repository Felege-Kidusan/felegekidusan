/// Mezmur playback navigation policy — PURE Dart (no Flutter / plugin imports).
///
/// Why this file exists
/// --------------------
/// The player mixes two kinds of data that must stay in sync but are not the
/// same length:
///
///   * the VISIBLE CATALOG — every hymn the user browsed into, in list order.
///     Some rows have audio, some are lyrics-only. Next/previous/swipe are
///     defined on THIS list: they always step exactly one *visible* row, so a
///     lyrics-only hymn is a real neighbour you can land on (this is exactly
///     what the product request asks for).
///   * the AUDIO QUEUE — only the rows that have a playable URL, fed to the
///     engine. The engine auto-advances in THIS space when a track ends or a
///     lock-screen / headset skip arrives.
///
/// The "confusion" bug this module eliminates comes from conflating the two:
/// the visible row you are reading and the audio item actually playing can
/// diverge, which makes next/previous feel random. Keeping every navigation
/// *decision* here as a pure, unit-testable function means the policy is
/// proven once and reused by every caller (buttons, swipe, completion,
/// lock-screen remap) — so the UI and the audio can never disagree about the
/// intended target again.
///
/// Loop-mode convention (matches the controller): 0 = off, 1 = all, 2 = one.
library;

/// Loop mode constants, kept in one place.
class PlaybackLoop {
  static const int off = 0;
  static const int all = 1;
  static const int one = 2;
}

/// Decision returned by [previousTarget].
class PreviousDecision {
  /// Catalog row to select after the gesture. Equal to [row] when the action
  /// is a plain restart (industry behaviour) or a no-op at the first row.
  final int targetRow;

  /// True when the gesture should *restart the current hymn* instead of
  /// moving rows. Restart happens only when the selected row actually has
  /// audio AND is playing past the restart threshold — the industry rule
  /// used by Spotify/Apple (see the surrounding analysis doc).
  final bool restartCurrent;

  /// True when the selected row has audio that should be played after the
  /// move (auto-play). False for a lyrics-only target or a restart that is
  /// already playing.
  final bool shouldAutoPlay;

  const PreviousDecision({
    required this.targetRow,
    required this.restartCurrent,
    required this.shouldAutoPlay,
  });
}

/// Navigation + cursor policy for the Mezmur player.
///
/// Pure functions only. The caller builds `audioFlags` once from the catalog
/// (`audioFlags[i] == has playable URL`) and asks this class what should
/// happen; the caller then performs the audio-engine work. Because nothing
/// here touches a plugin, it is deterministic and unit-testable.
abstract final class MezmurPlaybackPolicy {
  /// Industry-standard previous-track restart threshold. Tapping previous
  /// while a playable hymn has been playing longer than this *restarts* it;
  /// otherwise it moves to the previous visible row. Set to 0 to make
  /// previous always move to the previous hymn (some users prefer this).
  static const int restartThresholdMs = 3000;

  /// ── index mapping (catalog <-> audio queue) ───────────────────────────

  /// Ordinal (0-based index among the *audio-bearing* rows) of [row], or null
  /// when [row] has no audio. e.g. for flags [T,F,T], row 2 -> 1.
  static int? audioOrdinalForRow(List<bool> audioFlags, int row) {
    if (row < 0 || row >= audioFlags.length || !audioFlags[row]) return null;
    var seen = 0;
    for (var i = 0; i < row; i++) {
      if (audioFlags[i]) seen++;
    }
    return seen;
  }

  /// Catalog row of the `ordinal`-th (0-based) audio-bearing row, or -1 when
  /// the ordinal is out of range. e.g. for flags [T,F,T], ordinal 1 -> row 2.
  static int rowForAudioOrdinal(List<bool> audioFlags, int ordinal) {
    var seen = -1;
    for (var i = 0; i < audioFlags.length; i++) {
      if (!audioFlags[i]) continue;
      seen++;
      if (seen == ordinal) return i;
    }
    return -1;
  }

  /// ── visible-catalog stepping (buttons + swipe) ────────────────────────
  ///
  /// These move exactly ONE visible row (audio or not), wrapping only in
  /// loop-all mode. This is the behaviour the product request wants: next /
  /// previous opens the adjacent hymn whether or not it has audio.

  /// Row reached by moving +1 from [row]. Returns [row] when at the end and
  /// not wrapping.
  static int nextRow(List<bool> audioFlags, int row, int loop) {
    if (audioFlags.isEmpty) return 0;
    var i = row + 1;
    if (i >= audioFlags.length) i = loop == PlaybackLoop.all ? 0 : row;
    return i;
  }

  /// Row reached by moving -1 from [row]. Returns [row] when at the start and
  /// not wrapping.
  static int previousRow(List<bool> audioFlags, int row, int loop) {
    if (audioFlags.isEmpty) return 0;
    var i = row - 1;
    if (i < 0) i = loop == PlaybackLoop.all ? audioFlags.length - 1 : row;
    return i;
  }

  /// Whole-list stepping for arbitrary delta (used by keyboard-free UI that
  /// passes +/-1, kept general for tests). Clamps/wraps per [loop].
  static int stepRow(List<bool> audioFlags, int row, int delta, int loop) {
    if (audioFlags.isEmpty) return 0;
    final target = row + delta;
    if (target < 0 || target >= audioFlags.length) {
      if (loop == PlaybackLoop.all) {
        return (target % audioFlags.length + audioFlags.length) %
            audioFlags.length;
      }
      return row < 0
          ? 0
          : (row >= audioFlags.length ? audioFlags.length - 1 : row);
    }
    return target;
  }

  /// ── previous-button high-level decision ───────────────────────────────
  ///
  /// Industry semantics:
  ///   1. If the selected row is a playable hymn and it is *currently
  ///      playing* for longer than the threshold → restart it in place.
  ///   2. Otherwise step back one visible row (audio or not).
  ///   3. At the very first visible row with loop off there is nothing to go
  ///      back to — if that row is playing it restarts, else it is a no-op.
  static PreviousDecision previousTarget(
    List<bool> audioFlags,
    int row, {
    required int loop,
    required bool isPlaying,
    required int positionMs,
  }) {
    final hasAudio = row >= 0 && row < audioFlags.length && audioFlags[row];
    final restart = hasAudio && isPlaying && positionMs > restartThresholdMs;
    if (restart) {
      return PreviousDecision(
        targetRow: row,
        restartCurrent: true,
        shouldAutoPlay: false, // already playing; keep its state
      );
    }
    final target = previousRow(audioFlags, row, loop);
    final targetHasAudio =
        target >= 0 && target < audioFlags.length && audioFlags[target];
    return PreviousDecision(
      targetRow: target,
      restartCurrent: false,
      shouldAutoPlay: targetHasAudio,
    );
  }

  /// True when the in-app previous gesture should be enabled (there is more
  /// than one visible row, or wrapping is possible). Used to grey out the
  /// button on the first row when loop is off.
  static bool canGoPrevious(List<bool> audioFlags, int row, int loop) {
    return audioFlags.length > 1 &&
        (loop == PlaybackLoop.all || row > 0);
  }

  static bool canGoNext(List<bool> audioFlags, int row, int loop) {
    return audioFlags.length > 1 &&
        (loop == PlaybackLoop.all || row < audioFlags.length - 1);
  }

  /// ── auto-advance after a track finishes ───────────────────────────────
  ///
  /// When the engine completes the audio at [completedAudioOrdinal], decide
  /// which visible row to move the UI to next and whether to keep playing.
  ///
  /// Policy (continuous-list model):
  ///   * loop-one  → stay on the same hymn, repeat it.
  ///   * otherwise → jump to the NEXT *playable* visible row after the one
  ///     that just finished, wrapping in loop-all. Lyrics-only rows in
  ///     between are stepped over so sound keeps flowing (this is what a
  ///     real "play the list" experience does); the visible cursor is updated
  ///     to that next playable hymn so the on-screen page, the audio and the
  ///     mini-player all point at the same hymn again.
  ///   * end of list with loop off → no further hymn; playback should stop.
  ///
  /// Returns the target *visible* row, or -1 to stop. [audioFlags] length is
  /// the catalog length.
  /// NOTE (P36): not used in production. Completion handling advances via
  /// [nextRow], which works in CATALOG-ROW space; this helper works in
  /// audio-ordinal space, which stopped being well defined once the
  /// engine queue became a sliding window over the catalog (only part of
  /// the catalog is resolved at any moment, so "the Nth playable item"
  /// no longer maps to a stable row). Kept because it is the clearest
  /// statement of the loop semantics and is fully tested; do not treat
  /// its passing tests as evidence that production auto-advance is
  /// covered — [nextRow]'s tests cover that.
  static int autoAdvanceRowAfter(
    List<bool> audioFlags,
    int completedAudioOrdinal, {
    required int loop,
  }) {
    final audioCount = audioFlags.where((a) => a).length;
    if (audioCount == 0) return -1;
    if (loop == PlaybackLoop.one) {
      // Repeat the hymn that just finished.
      return rowForAudioOrdinal(audioFlags, completedAudioOrdinal);
    }
    var next = completedAudioOrdinal + 1;
    if (next >= audioCount) {
      if (loop == PlaybackLoop.all) {
        next = 0;
      } else {
        return -1; // end of the playable list, loop off → stop.
      }
    }
    return rowForAudioOrdinal(audioFlags, next);
  }
}
