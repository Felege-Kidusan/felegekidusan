/// P48 — reconciliation rules for timed ("karaoke") lyrics.
///
/// Pure functions, no Flutter and no database, so the policy that decides
/// whether a sync payload may overwrite a curator's work is unit-testable
/// in isolation and cannot drift between the store, the DB layer and the
/// player.
///
/// ## Why this exists
///
/// An empty `lyrics_synced` from the server is AMBIGUOUS:
///
/// * the hymn genuinely has no timings, **or**
/// * that deployment's schema lacks the column, so it cannot answer.
///
/// Older servers emit `'' AS lyrics_synced` for the second case. Treating
/// that as authoritative wiped good local LRC on every delta pull, which
/// silently disabled highlighting, animation and auto-scroll on the phone
/// while the web player kept working.
///
/// The rule below is deliberately conservative: **only a non-empty value
/// is authoritative.** Deleting timings is an explicit operation, never a
/// side effect of an ambiguous payload.
library;

/// What a sync payload is telling us about a hymn's timed lyrics.
enum SyncedLyricsSignal {
  /// The payload said nothing (key absent, or null) — no information.
  unknown,

  /// The payload carried an empty value. Ambiguous, so non-authoritative.
  ambiguousEmpty,

  /// The payload carried real LRC. Authoritative.
  authoritative,
}

class SyncedLyricsMerge {
  const SyncedLyricsMerge._();

  /// Classify an incoming value without deciding anything, so the
  /// decision and its explanation stay separable (and testable).
  static SyncedLyricsSignal classify(Object? incoming) {
    if (incoming == null) return SyncedLyricsSignal.unknown;
    final s = incoming is String ? incoming : '$incoming';
    if (s.trim().isEmpty) return SyncedLyricsSignal.ambiguousEmpty;
    return SyncedLyricsSignal.authoritative;
  }

  /// Merge an incoming sync value with the value already on the device.
  ///
  /// Returns the value that should be stored. Never throws.
  static String? merge({Object? incoming, String? local}) {
    switch (classify(incoming)) {
      case SyncedLyricsSignal.authoritative:
        return incoming is String ? incoming : '$incoming';
      case SyncedLyricsSignal.unknown:
      case SyncedLyricsSignal.ambiguousEmpty:
        return local;
    }
  }

  /// True when [lrc] can drive karaoke highlighting — i.e. it contains at
  /// least one timestamped line. Guards the player against a document
  /// that is present but unusable (headers only, whitespace, prose).
  static bool isPlayable(String? lrc) {
    if (lrc == null) return false;
    final re = RegExp(r'^\s*(?:\[\d{1,2}:\d{2}(?:\.\d{1,3})?\])+\s*\S');
    for (final line in lrc.split(RegExp(r'\r?\n'))) {
      if (re.hasMatch(line)) return true;
    }
    return false;
  }
}
