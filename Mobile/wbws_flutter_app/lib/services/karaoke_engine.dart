/// Karaoke V2 (P64) — the timing truth behind the synced-lyrics renderer.
///
/// Pure Dart, no Flutter: everything here is unit-testable in isolation
/// and reusable by any surface. The renderer owns pixels ONLY; this file
/// owns every rule about *when* things are sung and *how far* along.
///
/// The model (researched from how Spotify/Apple-grade karaoke works):
/// a frame is a complete snapshot of the karaoke state at one playback
/// position — the active line, a continuous 0..1 fill for it (per-word
/// when the document carries enhanced-LRC timings, interpolated across
/// the whole line when it doesn't, so EVERY existing hymn gets a
/// progressive karaoke fill with zero re-authoring), and the playback
/// time the next line begins (for predictive scrolling).
///
/// Offset discipline: [position] is PLAYBACK time; every comparison
/// happens in raw document time exactly like `SyncedLyrics.indexFor`
/// (`active ⇔ position ≥ line.time + offset`), and `nextLineStartAt` is
/// converted back to playback time. The engine and the parser can never
/// disagree about what is active.
library;

import 'dart:math' as math;

import 'mezmur_synced_lyrics.dart';

/// Everything the renderer needs at one playback position.
class KaraokeFrame {
  /// Index into `doc.lines` of the line currently being sung; -1 before
  /// the first timestamp.
  final int activeIndex;

  /// 0..1 fill of the active line when it has no word timings — the
  /// whole line fills left-to-right across its window. 0 when nothing
  /// is active.
  final double lineFill;

  /// Per-word 0..1 fills of the active line, empty when it has no word
  /// timings.
  final List<double> wordFills;

  /// PLAYBACK time (offset applied) at which the next line begins, for
  /// predictive scrolling. Null when the active line is the last one.
  final Duration? nextLineStartAt;

  const KaraokeFrame({
    required this.activeIndex,
    required this.lineFill,
    required this.wordFills,
    required this.nextLineStartAt,
  });

  @override
  bool operator ==(Object other) {
    if (other is! KaraokeFrame) return false;
    if (other.activeIndex != activeIndex) return false;
    if (other.lineFill != lineFill) return false;
    if (other.nextLineStartAt != nextLineStartAt) return false;
    if (other.wordFills.length != wordFills.length) return false;
    for (var i = 0; i < wordFills.length; i++) {
      if (other.wordFills[i] != wordFills[i]) return false;
    }
    return true;
  }

  @override
  int get hashCode => Object.hash(activeIndex, lineFill, nextLineStartAt,
      Object.hashAll(wordFills));
}

/// Pure karaoke timing rules. Stateless — every call is a function of
/// (document, position).
class KaraokeEngine {
  const KaraokeEngine._();

  /// Window assumed for the last line of a document (no next line exists
  /// to bound it).
  static const Duration defaultLineWindow = Duration(milliseconds: 4500);

  /// Longest a whole-line fill may take — lines before long instrumental
  /// gaps complete their fill and rest instead of crawling for half a
  /// minute.
  static const Duration maxLineFillWindow = Duration(milliseconds: 6000);

  /// Longest a single word's fill may take (the gap to the next word is
  /// usually the singer's pause, not the word).
  static const Duration maxWordFillWindow = Duration(milliseconds: 4000);

  /// The karaoke snapshot at playback [position].
  static KaraokeFrame frameFor(SyncedLyrics doc, Duration position) {
    final lines = doc.lines;
    if (lines.isEmpty) {
      return const KaraokeFrame(
          activeIndex: -1, lineFill: 0, wordFills: [], nextLineStartAt: null);
    }

    final offset = doc.offsetMs;
    final ms = position.inMilliseconds - offset;
    final i = doc.indexFor(position);

    // Nothing active yet: everything empty, next line is the first.
    if (i < 0) {
      return KaraokeFrame(
        activeIndex: -1,
        lineFill: 0,
        wordFills: const [],
        nextLineStartAt: _playbackAt(lines[0].time, offset),
      );
    }

    final line = lines[i];
    final startMs = line.time.inMilliseconds;
    final nextLineStartMs =
        i + 1 < lines.length ? lines[i + 1].time.inMilliseconds : null;
    // The line's window: until the next line begins, or a default for
    // the last line.
    final lineEndMs = nextLineStartMs ?? startMs + defaultLineWindow.inMilliseconds;

    // Fills.
    double lineFill;
    List<double> wordFills;
    if (line.words.isEmpty) {
      // Whole-line interpolation, capped so instrumental gaps don't crawl.
      final fillEnd = math.min(
          lineEndMs, startMs + maxLineFillWindow.inMilliseconds);
      lineFill = fillEnd > startMs
          ? _clamp01((ms - startMs) / (fillEnd - startMs))
          : (ms >= startMs ? 1.0 : 0.0);
      wordFills = const [];
    } else {
      lineFill = lineEndMs > startMs
          ? _clamp01((ms - startMs) / (lineEndMs - startMs))
          : 1.0;
      wordFills = [for (var k = 0; k < line.words.length; k++) _wordFill(line, k, startMs, lineEndMs, ms)];
    }

    return KaraokeFrame(
      activeIndex: i,
      lineFill: lineFill,
      wordFills: wordFills,
      nextLineStartAt: nextLineStartMs == null
          ? null
          : _playbackAt(
              Duration(milliseconds: nextLineStartMs), offset),
    );
  }

  static double _wordFill(SyncedLyricLine line, int k, int lineStartMs,
      int lineEndMs, int ms) {
    final w = line.words[k];
    final wStart = w.start.inMilliseconds;
    var wEnd = k + 1 < line.words.length
        ? line.words[k + 1].start.inMilliseconds
        : lineEndMs;
    // A word never fills for longer than [maxWordFillWindow] — the gap
    // to the next word is usually a pause between words, not the word.
    final cap = wStart + maxWordFillWindow.inMilliseconds;
    if (wEnd > cap) wEnd = cap;
    if (wEnd <= wStart) return ms >= wEnd ? 1.0 : 0.0;
    return _clamp01((ms - wStart) / (wEnd - wStart));
  }

  /// Character spans of each word within the joined line text — the
  /// renderer maps a word's fill onto its glyph box with these. Pinned
  /// property: the spans tile `words.join()` exactly, which the parser
  /// guarantees equals `line.text`.
  static List<({int start, int end})> wordCharSpans(
      List<SyncedLyricWord> words) {
    final spans = <({int start, int end})>[];
    var off = 0;
    for (final w in words) {
      final start = off;
      off += w.text.length;
      spans.add((start: start, end: off));
    }
    return spans;
  }

  static Duration _playbackAt(Duration raw, int offsetMs) =>
      Duration(milliseconds: raw.inMilliseconds + offsetMs);

  static double _clamp01(double v) => v < 0 ? 0 : (v > 1 ? 1 : v);
}
