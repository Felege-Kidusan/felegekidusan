/// P48 — LRC authoring model for the mobile timing editor.
///
/// Pure Dart: no Flutter, no database, no network. The editor screen owns
/// pixels; this owns the *rules*. That separation is deliberate — the
/// timing logic is fully unit-testable, and a future redesign of the UI
/// cannot change what gets written to the server.
///
/// The output must satisfy the server's `canonicalizeLrc()` validator
/// exactly (`[mm:ss.mmm] text`, sorted, no `[Section]` markup, at least
/// one timed line), so the two implementations are pinned by tests rather
/// than by hope.
library;

/// One authorable line: the text to sing, and when it starts.
class LrcLine {
  final String text;

  /// Null until the curator stamps it.
  final Duration? at;

  const LrcLine({required this.text, this.at});

  bool get isStamped => at != null;

  LrcLine copyWith({Duration? at, bool clearAt = false}) =>
      LrcLine(text: text, at: clearAt ? null : (at ?? this.at));
}

class LrcBuilder {
  const LrcBuilder._();

  /// Smallest gap the UI will allow between consecutive stamps. Two lines
  /// at the same millisecond make the active-line lookup ambiguous and
  /// cause visible flicker as playback crosses the boundary.
  static const Duration minGap = Duration(milliseconds: 120);

  /// Split static lyrics into timeable lines.
  ///
  /// Blank lines and `[Section]` markers are dropped: the server rejects
  /// any non-timestamp line, and timing a blank line is meaningless.
  static List<LrcLine> linesFrom(String staticLyrics) {
    final out = <LrcLine>[];
    for (final raw in staticLyrics.split(RegExp(r'\r?\n'))) {
      final t = raw.trim();
      if (t.isEmpty) continue;
      if (RegExp(r'^\[.+\]$').hasMatch(t)) continue;
      out.add(LrcLine(text: t));
    }
    return out;
  }

  /// Re-apply previously saved timings onto a fresh line list.
  ///
  /// Matched positionally AND by text, so editing the lyrics invalidates
  /// only the lines that actually changed instead of silently shifting
  /// every timestamp onto the wrong words.
  static List<LrcLine> applyExisting(
      List<LrcLine> lines, List<LrcLine> existing) {
    if (existing.isEmpty) return lines;
    return List<LrcLine>.generate(lines.length, (i) {
      if (i >= existing.length) return lines[i];
      if (existing[i].text != lines[i].text) return lines[i];
      return lines[i].copyWith(at: existing[i].at);
    });
  }

  /// Parse an LRC document back into authorable lines (for re-editing).
  ///
  /// Assumes the document is in the server's canonical dialect (ONE stamp
  /// per line, sorted) — which every stored `lyrics_synced` is, because
  /// `MezmurMediaService::canonicalizeLrc` normalizes on write. Raw
  /// hand-made files should go through the server first. `[offset:]` and
  /// other headers are not lines; use [offsetOf] to carry an offset through
  /// an edit session.
  static List<LrcLine> parse(String lrc) {
    final re = RegExp(r'^\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]\s*(.*)$');
    final out = <LrcLine>[];
    for (final raw in lrc.split(RegExp(r'\r?\n'))) {
      final m = re.firstMatch(raw.trim());
      if (m == null) continue; // headers and junk are ignored
      final ms = int.parse(m.group(1)!) * 60000 +
          int.parse(m.group(2)!) * 1000 +
          int.parse((m.group(3) ?? '0').padRight(3, '0'));
      // P62 hardening: canonical documents carry ONE stamp per line, but a
      // raw run like [00:01.00][00:09.00]text would leave the SECOND stamp
      // inside the parsed text. Strip any residual leading stamp run so the
      // words stay clean (the run's later timings are still dropped — this
      // parser is canonical-only by contract, see the doc above; the display
      // parser SyncedLyrics.tryParse is the one that expands runs).
      // P63: enhanced-LRC word tags (<mm:ss.xx>) are likewise not this
      // editor's model — strip them (see hasWordTimings) so the text
      // matches the static lyrics for applyExisting.
      var text = (m.group(4) ?? '')
          .trim()
          .replaceFirst(
              RegExp(r'^(?:\[\d{1,2}:\d{2}(?:\.\d{1,3})?\])+\s*'), '')
          .trim();
      text = text
          .replaceAll(RegExp(r'<\d{1,2}:\d{2}(?:\.\d{1,3})?>'), '')
          .replaceAll(RegExp(r'\s{2,}'), ' ')
          .trim();
      out.add(LrcLine(text: text, at: Duration(milliseconds: ms)));
    }
    return out;
  }

  /// The `[offset:±ms]` header of a stored LRC document, 0 when absent or
  /// malformed.
  ///
  /// The server PRESERVES offset headers through canonicalization, so a
  /// stored document may carry one. Its meaning (shared with
  /// `SyncedLyrics.indexFor`): the line at raw time T is heard at
  /// T + offset. An edit session must therefore SHIFT existing stamps by
  /// +offset on load (into playback time, which is what the curator hears
  /// and stamps against) and by −offset on save, or a single re-save
  /// silently drags every timing by the offset.
  static int offsetOf(String lrc) {
    for (final raw in lrc.split(RegExp(r'\r?\n'))) {
      final m =
          RegExp(r'^\[offset:\s*([+-]?\d+)\s*\]$').firstMatch(raw.trim());
      if (m != null) return int.tryParse(m.group(1)!) ?? 0;
    }
    return 0;
  }

  /// Whether the document carries enhanced-LRC word timings
  /// (`<mm:ss.xx>` tags inside line text).
  ///
  /// The mobile editor is a LINE-level tool: `parse` strips the tags so
  /// the words stay clean and matchable, which means editing (and
  /// re-saving) a word-timed document keeps the line timings but drops
  /// the word-level detail. The editor checks this and warns BEFORE the
  /// curator invests work. The player, by contrast, renders word timings
  /// (see `SyncedLyrics.tryParse`).
  static bool hasWordTimings(String lrc) =>
      RegExp(r'<\d{1,2}:\d{2}(?:\.\d{1,3})?>').hasMatch(lrc);

  /// Shift every STAMPED line by [delta] (unstamped lines are untouched).
  ///
  /// A uniform shift preserves order and gaps, so it can never violate the
  /// monotonicity rules [stamp] enforces. Values below zero clamp to zero —
  /// matching [stamp]'s floor.
  static List<LrcLine> shiftAll(List<LrcLine> lines, Duration delta) {
    if (delta == Duration.zero) return lines;
    return [
      for (final l in lines)
        l.isStamped
            ? l.copyWith(
                at: l.at! + delta < Duration.zero
                    ? Duration.zero
                    : l.at! + delta)
            : l
    ];
  }

  /// Stamp [index] at [at], returning a NEW list (never mutates input).
  ///
  /// Enforces monotonicity: a stamp may not land before the previous
  /// line, and any later stamps that would now be out of order are
  /// cleared rather than silently reordered — the curator can see what
  /// needs redoing instead of getting a scrambled document.
  static List<LrcLine> stamp(
      List<LrcLine> lines, int index, Duration at) {
    if (index < 0 || index >= lines.length) return lines;
    var t = at.isNegative ? Duration.zero : at;

    for (var i = index - 1; i >= 0; i--) {
      final prev = lines[i].at;
      if (prev == null) continue;
      if (t <= prev) t = prev + minGap;
      break;
    }

    final out = List<LrcLine>.of(lines);
    out[index] = out[index].copyWith(at: t);
    for (var i = index + 1; i < out.length; i++) {
      final later = out[i].at;
      if (later != null && later <= t) {
        out[i] = out[i].copyWith(clearAt: true);
      }
    }
    return out;
  }

  /// Shift one stamp by [delta], clamped at zero and kept after the
  /// previous line. Used by the fine-tune buttons.
  static List<LrcLine> nudge(
      List<LrcLine> lines, int index, Duration delta) {
    if (index < 0 || index >= lines.length) return lines;
    final cur = lines[index].at;
    if (cur == null) return lines;
    var t = cur + delta;
    if (t.isNegative) t = Duration.zero;
    return stamp(lines, index, t);
  }

  static List<LrcLine> clearAll(List<LrcLine> lines) =>
      lines.map((l) => l.copyWith(clearAt: true)).toList();

  static int stampedCount(List<LrcLine> lines) =>
      lines.where((l) => l.isStamped).length;

  /// The index that should be stamped next: the first unstamped line.
  static int nextIndex(List<LrcLine> lines) {
    for (var i = 0; i < lines.length; i++) {
      if (!lines[i].isStamped) return i;
    }
    return lines.length;
  }

  static String _stampText(Duration d) {
    final ms = d.inMilliseconds;
    final m = (ms ~/ 60000).toString().padLeft(2, '0');
    final s = ((ms % 60000) ~/ 1000).toString().padLeft(2, '0');
    final f = (ms % 1000).toString().padLeft(3, '0');
    return '[$m:$s.$f]';
  }

  /// Render the canonical document the server expects.
  ///
  /// Unstamped lines are omitted — a partial timing is valid and useful,
  /// and the server sorts anyway; sorting here keeps the payload
  /// byte-identical to what comes back, so a save is not a silent
  /// rewrite.
  static String build(List<LrcLine> lines) {
    final timed = lines.where((l) => l.isStamped).toList()
      ..sort((a, b) => a.at!.compareTo(b.at!));
    return timed
        .map((l) => '${_stampText(l.at!)} ${l.text}')
        .join('\n');
  }
}
