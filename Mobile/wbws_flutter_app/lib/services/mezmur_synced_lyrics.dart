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
///
/// Enhanced-LRC word tags (`[00:10.000] ሃሌ <00:10.500>ሉያ`, the A2
/// extension) are supported read-only: the tags are parsed into
/// [SyncedLyricLine.words] and stripped from the displayed text (before
/// this, they rendered as literal `<00:10.500>` on screen). The mobile
/// editor remains a line-level tool and strips them on edit.
library;

/// One timed lyric line.
///
/// [text] is the clean, markup-free text. [words] carries enhanced-LRC
/// word-level timing (`[00:10.000] ሃሌ <00:10.500>ሉያ` → two chunks) and is
/// empty for plain line-level documents; the renderer falls back to
/// line highlighting in that case.
class SyncedLyricLine {
  final Duration time;
  final String text;

  /// Timed chunks for word-level highlighting, in order. A chunk may span
  /// several words (whatever the document grouped between two tags); the
  /// first chunk starts at the line's own timestamp when the document puts
  /// no tag before it.
  final List<SyncedLyricWord> words;

  const SyncedLyricLine(
      {required this.time, required this.text, this.words = const []});

  bool get isEmpty => text.isEmpty;
}

/// One timed chunk inside a line (usually one word).
class SyncedLyricWord {
  final String text;

  /// When this chunk begins to be sung (document time — the doc-level
  /// `[offset:]` is applied by the lookup helpers, exactly as for lines).
  final Duration start;
  const SyncedLyricWord({required this.text, required this.start});
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

  /// How many leading chunks of [line] are already sung at playback
  /// [position], with the document's `[offset:]` applied exactly like
  /// [indexFor] (a chunk at raw time T is sung when `position >= T +
  /// offset`). Lines without word timings report 0. Chunks are clamped
  /// non-decreasing at parse time, so the count is a simple leading run.
  int sungWordCount(SyncedLyricLine line, Duration position) {
    if (line.words.isEmpty) return 0;
    final ms = position.inMilliseconds - offsetMs;
    var n = 0;
    for (final w in line.words) {
      if (w.start.inMilliseconds <= ms) {
        n++;
      } else {
        break;
      }
    }
    return n;
  }

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

  /// Splits enhanced-LRC word tags out of a line's raw text.
  ///
  /// `[00:10.000] ሃሌ <00:10.500>ሉያ` → plain text `ሃሌ ሉያ` plus segments
  /// `("ሃሌ ", null)` (null = starts at the line's own stamp) and
  /// `("ሉያ", 10.5s)`. The segments join to EXACTLY the plain text, so the
  /// word-swept rendering and the plain rendering always size identically.
  /// Lines with no (well-formed) tags return an empty segment list and the
  /// text untouched — malformed tags like `<1:2>` stay literal (tolerance,
  /// not guessing).
  static (String, List<(String, Duration?)>) _splitWordTags(String raw) {
    final tagRe = RegExp(r'<(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?>');
    if (!tagRe.hasMatch(raw)) return (raw, const []);
    final segments = <(String, Duration?)>[];
    final buf = StringBuffer();
    var idx = 0;
    Duration? pendingStart; // null → the segment starts at the line stamp
    for (final m in tagRe.allMatches(raw)) {
      buf.write(raw.substring(idx, m.start));
      if (buf.isNotEmpty) {
        segments.add((buf.toString(), pendingStart));
        buf.clear();
      }
      final min = int.tryParse(m.group(1) ?? '0') ?? 0;
      final sec = int.tryParse(m.group(2) ?? '0') ?? 0;
      final frac = (m.group(3) ?? '').padRight(3, '0');
      pendingStart = Duration(
          milliseconds:
              min * 60000 + sec * 1000 + int.parse(frac.substring(0, 3)));
      idx = m.end;
    }
    buf.write(raw.substring(idx));
    if (buf.isNotEmpty) segments.add((buf.toString(), pendingStart));
    // Trim only the OUTER edges (leading space of the first chunk, trailing
    // space of the last) so the join still equals the plain text exactly.
    if (segments.isEmpty) return ('', const <(String, Duration?)>[]);
    if (segments.length == 1) {
      final t = segments.single.$1.trim();
      return (t, <(String, Duration?)>[(t, segments.single.$2)]);
    }
    segments[0] = (segments[0].$1.trimLeft(), segments[0].$2);
    final lastIdx = segments.length - 1;
    segments[lastIdx] =
        (segments[lastIdx].$1.trimRight(), segments[lastIdx].$2);
    // Edge chunks that trimmed to pure padding are dropped; each surviving
    // chunk keeps its own tag time.
    if (segments.first.$1.isEmpty) segments.removeAt(0);
    if (segments.length > 1 && segments.last.$1.isEmpty) {
      segments.removeLast();
    }
    if (segments.isEmpty) return ('', const <(String, Duration?)>[]);
    return (segments.map((s) => s.$1).join(), segments);
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
      // P63 — enhanced-LRC word tags (<mm:ss.xx>) are split out of the raw
      // text: lines without tags keep the plain line-level model, lines
      // with tags get clean text (previously the tags rendered as literal
      // text on screen) plus per-chunk start times for the word sweep.
      final (plain, segments) = _splitWordTags((m.group(2) ?? '').trim());

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
        // Word chunks: never before the line, never out of order — the
        // same tolerance the line-level clamp below applies to lines.
        final words = <SyncedLyricWord>[];
        var prevMs = ms;
        for (final (text, tagAt) in segments) {
          var start = tagAt ?? Duration(milliseconds: ms);
          if (start.inMilliseconds < ms) {
            start = Duration(milliseconds: ms);
          }
          if (start.inMilliseconds < prevMs) {
            start = Duration(milliseconds: prevMs);
          }
          prevMs = start.inMilliseconds;
          words.add(SyncedLyricWord(text: text, start: start));
        }
        out.add(SyncedLyricLine(
            time: Duration(milliseconds: ms), text: plain, words: words));
      }
    }

    // Monotonic clamp — later stamps on a line override earlier ones when
    // a hand-made file violates ordering.
    var guard = -1;
    final finalLines = <SyncedLyricLine>[];
    for (final l in out) {
      if (l.time.inMilliseconds < guard) {
        finalLines.add(SyncedLyricLine(
            time: Duration(milliseconds: guard), text: l.text, words: l.words));
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
