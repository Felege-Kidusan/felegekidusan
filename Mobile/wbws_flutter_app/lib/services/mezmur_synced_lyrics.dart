/// P0 mezmur — timed (synced) lyrics.
///
/// Parses the LRC-flavoured documents stored on the hymn row
/// (`mezmur_hymns.lyrics_synced`). The server already enforces the shape:
/// UTF-8, at least one timed line, timestamps strictly non-decreasing and
/// only [mm:ss] / [mm:ss.x|xx|xxx] prefixes plus metadata headers such as
/// [ti:…], [ar:…] and [offset:±ms]. This parser is deliberately more
/// tolerant than the validator so older / hand-made documents never crash
/// the UI: it simply skips malformed lines and clamps to a sensible
/// monotonic order.
library;

/// One timed lyric line.
class SyncedLyricLine {
  final Duration time;
  final String text;
  const SyncedLyricLine({required this.time, required this.text});

  bool get isEmpty => text.isEmpty;
}

/// A parsed synced-lyrics document: ordered timed lines + optional
/// metadata.
class SyncedLyrics {
  final String? title;
  final String? artist;
  final int offsetMs;
  final List<SyncedLyricLine> lines;

  const SyncedLyrics({
    this.title,
    this.artist,
    this.offsetMs = 0,
    required this.lines,
  });

  bool get isEmpty => lines.isEmpty;

  /// The PLAYBACK time at which [line] becomes active, i.e. the moment a
  /// listener hears it begin. [indexFor] applies the document's `[offset:]`
  /// header (`active ⇔ position ≥ line.time + offset`), so a tap-to-seek
  /// that wants to land exactly on a line must seek HERE — not to the raw
  /// stamp. Seeking to `line.time` would disagree with the highlight by
  /// exactly the offset, which is invisible with offset 0 and very visible
  /// the moment a curator adds one.
  Duration seekTargetFor(SyncedLyricLine line) =>
      line.time + Duration(milliseconds: offsetMs);

  /// Index of the line active at [time], or -1 when the doc is empty or
  /// playback precedes the first timestamp.
  int indexFor(Duration time) {
    if (lines.isEmpty) return -1;
    final ms = time.inMilliseconds - offsetMs;
    int lo = 0, hi = lines.length - 1, ans = -1;
    while (lo <= hi) {
      final mid = (lo + hi) >> 1;
      if (lines[mid].time.inMilliseconds <= ms) {
        ans = mid;
        lo = mid + 1;
      } else {
        hi = mid - 1;
      }
    }
    return ans;
  }

  static SyncedLyrics? tryParse(String? src) {
    if (src == null || src.trim().isEmpty) return null;
    String? title, artist;
    var offsetMs = 0;
    final out = <SyncedLyricLine>[];

    // One (or more) leading [mm:ss(.fraction)] stamps per line, then text.
    // Bracket runs like [00:01.00][00:09.00]… are expanded to two entries.
    // NOTE: the run must be a NON-CAPTURING group inside group 1 — a
    // capturing group under `+` keeps only its LAST iteration, which is
    // exactly the bug this regex fixed: every stamp but the last of a run
    // was silently dropped while the doc comment promised expansion.
    // (Caught by test/synced_lyrics_seek_test.dart, P61.)
    final stampRe = RegExp(
        r'^((?:\[[0-9]{1,2}:[0-9]{2}(?:\.[0-9]{1,3})?\])+)\s*(.*)$');
    final oneStamp = RegExp(r'\[([0-9]{1,2}):([0-9]{2})(?:\.([0-9]{1,3}))?\]');

    for (final raw in src.split('\n')) {
      final line = raw.trim();
      if (line.isEmpty) continue;

      // Metadata header ([ti:…] etc).
      final meta = RegExp(r'^\[(ti|ar|al|by|offset|length|re|ve):([^\]]*)\]$')
          .firstMatch(line);
      if (meta != null) {
        final key = meta.group(1);
        final val = meta.group(2)?.trim() ?? '';
        if (key == 'ti') {
          title = val;
        } else if (key == 'ar') {
          artist = val;
        } else if (key == 'offset') {
          offsetMs = int.tryParse(val.replaceAll(RegExp(r'[^0-9+\-]'), '')) ?? 0;
        }
        // Other headers ([al:]/[by:]/[length:]/[re:]/[ve:]) are accepted and
        // ignored: nothing downstream consumes them, and keeping them out of
        // the model means no dead fields pretending to be a contract.
        continue;
      }

      final m = stampRe.firstMatch(line);
      if (m == null) continue; // non-timed text line — ignore.
      final text = (m.group(2) ?? '').trim();

      for (final s in oneStamp.allMatches(m.group(1)!)) {
        final min = int.tryParse(s.group(1) ?? '0') ?? 0;
        final sec = int.tryParse(s.group(2) ?? '0') ?? 0;
        var frac = s.group(3) ?? '';
        var ms = min * 60000 + sec * 1000;
        if (frac.isNotEmpty) {
          // .5 -> 500, .55 -> 550, .555 -> 555 (right pad)
          frac = frac.padRight(3, '0');
          ms += int.parse(frac.substring(0, 3));
        }
        out.add(SyncedLyricLine(time: Duration(milliseconds: ms), text: text));
      }
    }

    // Monotonic clamp — later stamps on a line override earlier ones when
    // a hand-made file violates ordering.
    var guard = -1;
    final finalLines = <SyncedLyricLine>[];
    for (final l in out) {
      if (l.time.inMilliseconds < guard) {
        finalLines.add(SyncedLyricLine(
            time: Duration(milliseconds: guard), text: l.text));
      } else {
        guard = l.time.inMilliseconds;
        finalLines.add(l);
      }
    }
    if (finalLines.isEmpty) return null;
    return SyncedLyrics(
      title: title,
      artist: artist,
      offsetMs: offsetMs,
      lines: finalLines,
    );
  }
}
