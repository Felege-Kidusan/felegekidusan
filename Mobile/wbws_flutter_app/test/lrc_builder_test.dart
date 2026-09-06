import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/lrc_builder.dart';

/// P48 — the mobile timing editor's rules.
///
/// The output of [LrcBuilder.build] must be accepted verbatim by the
/// server's `canonicalizeLrc()`, so these tests pin the exact contract:
/// `[mm:ss.mmm] text`, sorted, no section markup.
void main() {
  group('linesFrom — what is timeable', () {
    test('keeps real lyric lines', () {
      final l = LrcBuilder.linesFrom('ሃሌ ሉያ\nሀባ ሰላምከ');
      expect(l.map((e) => e.text), ['ሃሌ ሉያ', 'ሀባ ሰላምከ']);
    });

    test('drops blank lines and section markers', () {
      // The server rejects any non-timestamp line, so these must never
      // reach the document.
      final l = LrcBuilder.linesFrom('[Chorus]\n\nሃሌ ሉያ\n   \n[Verse 1]\nሀባ');
      expect(l.map((e) => e.text), ['ሃሌ ሉያ', 'ሀባ']);
    });

    test('nothing timeable yields an empty list, not a crash', () {
      expect(LrcBuilder.linesFrom('   \n\n'), isEmpty);
    });

    test('every line starts unstamped', () {
      expect(LrcBuilder.linesFrom('a\nb').every((l) => !l.isStamped), isTrue);
    });
  });

  group('stamp — monotonic by construction', () {
    final base = LrcBuilder.linesFrom('one\ntwo\nthree');

    test('records the time on the chosen line', () {
      final r = LrcBuilder.stamp(base, 0, const Duration(seconds: 5));
      expect(r[0].at, const Duration(seconds: 5));
      expect(r[1].isStamped, isFalse);
    });

    test('does not mutate the input list', () {
      LrcBuilder.stamp(base, 0, const Duration(seconds: 5));
      expect(base[0].isStamped, isFalse);
    });

    test('a stamp before the previous line is pushed after it', () {
      var r = LrcBuilder.stamp(base, 0, const Duration(seconds: 10));
      r = LrcBuilder.stamp(r, 1, const Duration(seconds: 3)); // too early
      expect(r[1].at! > r[0].at!, isTrue);
      expect(r[1].at! - r[0].at!, LrcBuilder.minGap);
    });

    test('identical times are separated, never left ambiguous', () {
      var r = LrcBuilder.stamp(base, 0, const Duration(seconds: 10));
      r = LrcBuilder.stamp(r, 1, const Duration(seconds: 10));
      expect(r[1].at! > r[0].at!, isTrue);
    });

    test('re-stamping earlier clears later stamps that would invert', () {
      var r = LrcBuilder.stamp(base, 0, const Duration(seconds: 1));
      r = LrcBuilder.stamp(r, 1, const Duration(seconds: 5));
      r = LrcBuilder.stamp(r, 2, const Duration(seconds: 9));
      // Redo line 1 much later: line 2 is now impossible, so it is
      // cleared rather than silently reordered.
      r = LrcBuilder.stamp(r, 1, const Duration(seconds: 20));
      expect(r[1].at, const Duration(seconds: 20));
      expect(r[2].isStamped, isFalse);
    });

    test('a negative time clamps to zero', () {
      final r = LrcBuilder.stamp(base, 0, const Duration(seconds: -5));
      expect(r[0].at, Duration.zero);
    });

    test('an out-of-range index is ignored', () {
      expect(LrcBuilder.stamp(base, 99, Duration.zero), same(base));
      expect(LrcBuilder.stamp(base, -1, Duration.zero), same(base));
    });
  });

  group('nudge — fine tuning', () {
    test('shifts a stamped line', () {
      var r = LrcBuilder.stamp(
          LrcBuilder.linesFrom('a\nb'), 0, const Duration(seconds: 5));
      r = LrcBuilder.nudge(r, 0, const Duration(milliseconds: -200));
      expect(r[0].at, const Duration(milliseconds: 4800));
    });

    test('cannot go below zero', () {
      var r = LrcBuilder.stamp(
          LrcBuilder.linesFrom('a'), 0, const Duration(milliseconds: 100));
      r = LrcBuilder.nudge(r, 0, const Duration(seconds: -5));
      expect(r[0].at, Duration.zero);
    });

    test('an unstamped line is left alone', () {
      final base = LrcBuilder.linesFrom('a\nb');
      expect(LrcBuilder.nudge(base, 0, const Duration(seconds: 1)), same(base));
    });
  });

  group('build — must satisfy the server validator', () {
    test('emits [mm:ss.mmm] text', () {
      var r = LrcBuilder.stamp(
          LrcBuilder.linesFrom('ሃሌ ሉያ'), 0, const Duration(milliseconds: 12500));
      expect(LrcBuilder.build(r), '[00:12.500] ሃሌ ሉያ');
    });

    test('pads minutes, seconds and milliseconds', () {
      var r = LrcBuilder.stamp(
          LrcBuilder.linesFrom('x'), 0, const Duration(milliseconds: 65007));
      expect(LrcBuilder.build(r), '[01:05.007] x');
    });

    test('omits unstamped lines — a partial timing is still valid', () {
      var r = LrcBuilder.linesFrom('a\nb\nc');
      r = LrcBuilder.stamp(r, 0, const Duration(seconds: 1));
      r = LrcBuilder.stamp(r, 2, const Duration(seconds: 3));
      expect(LrcBuilder.build(r), '[00:01.000] a\n[00:03.000] c');
    });

    test('output is sorted by time', () {
      var r = LrcBuilder.linesFrom('a\nb');
      r = LrcBuilder.stamp(r, 1, const Duration(seconds: 9));
      r = LrcBuilder.stamp(r, 0, const Duration(seconds: 2));
      final doc = LrcBuilder.build(r);
      expect(doc.indexOf('[00:02.000]') < doc.indexOf('[00:09.000]'), isTrue);
    });

    test('no timings yields an empty document (= clear the timings)', () {
      expect(LrcBuilder.build(LrcBuilder.linesFrom('a\nb')), '');
    });

    test('never emits section markup the server would reject', () {
      var r = LrcBuilder.linesFrom('[Chorus]\nreal line');
      r = LrcBuilder.stamp(r, 0, const Duration(seconds: 1));
      expect(LrcBuilder.build(r), '[00:01.000] real line');
    });
  });

  group('parse / applyExisting — re-editing keeps prior work', () {
    test('round-trips a built document', () {
      var r = LrcBuilder.linesFrom('one\ntwo');
      r = LrcBuilder.stamp(r, 0, const Duration(milliseconds: 1500));
      r = LrcBuilder.stamp(r, 1, const Duration(milliseconds: 4250));
      final back = LrcBuilder.parse(LrcBuilder.build(r));
      expect(back.map((l) => l.text), ['one', 'two']);
      expect(back.map((l) => l.at),
          [const Duration(milliseconds: 1500), const Duration(milliseconds: 4250)]);
    });

    test('ignores headers when parsing', () {
      final back = LrcBuilder.parse('[ti:Song]\n[00:01.000] a');
      expect(back.length, 1);
      expect(back.first.text, 'a');
    });

    test('re-applies timings when the text still matches', () {
      final fresh = LrcBuilder.linesFrom('one\ntwo');
      final old = LrcBuilder.parse('[00:01.000] one\n[00:02.000] two');
      final merged = LrcBuilder.applyExisting(fresh, old);
      expect(merged[0].at, const Duration(seconds: 1));
      expect(merged[1].at, const Duration(seconds: 2));
    });

    test('an EDITED line loses its old timing, neighbours keep theirs', () {
      // Critical: without the text check, editing a typo would shift every
      // subsequent timestamp onto the wrong words.
      final fresh = LrcBuilder.linesFrom('one\nCHANGED');
      final old = LrcBuilder.parse('[00:01.000] one\n[00:02.000] two');
      final merged = LrcBuilder.applyExisting(fresh, old);
      expect(merged[0].at, const Duration(seconds: 1));
      expect(merged[1].isStamped, isFalse);
    });

    test('a longer new lyric keeps what it can', () {
      final fresh = LrcBuilder.linesFrom('one\ntwo\nthree');
      final old = LrcBuilder.parse('[00:01.000] one');
      final merged = LrcBuilder.applyExisting(fresh, old);
      expect(merged[0].at, const Duration(seconds: 1));
      expect(merged[2].isStamped, isFalse);
    });
  });

  group('progress helpers', () {
    test('nextIndex finds the first unstamped line', () {
      var r = LrcBuilder.linesFrom('a\nb\nc');
      expect(LrcBuilder.nextIndex(r), 0);
      r = LrcBuilder.stamp(r, 0, const Duration(seconds: 1));
      expect(LrcBuilder.nextIndex(r), 1);
    });

    test('nextIndex past the end when everything is stamped', () {
      var r = LrcBuilder.linesFrom('a');
      r = LrcBuilder.stamp(r, 0, const Duration(seconds: 1));
      expect(LrcBuilder.nextIndex(r), 1);
    });

    test('stampedCount and clearAll', () {
      var r = LrcBuilder.linesFrom('a\nb');
      r = LrcBuilder.stamp(r, 0, const Duration(seconds: 1));
      expect(LrcBuilder.stampedCount(r), 1);
      expect(LrcBuilder.stampedCount(LrcBuilder.clearAll(r)), 0);
    });
  });

  group('P61 — [offset:] round-trip (editor playback-time bake)', () {
    // The server PRESERVES [offset:] headers; the editor must honour them:
    // load shifts existing stamps +offset into playback time (what the
    // curator hears), save shifts them back. A lost offset used to drag
    // every timing by that amount on a single re-save.
    test('offsetOf extracts positive, negative and absent offsets', () {
      expect(LrcBuilder.offsetOf('[offset:+500]\n[00:10.000] one'), 500);
      expect(LrcBuilder.offsetOf('[offset:-1500]\n[00:10.000] one'), -1500);
      expect(LrcBuilder.offsetOf('[00:10.000] one'), 0);
      expect(LrcBuilder.offsetOf('[offset:garbage]'), 0);
      expect(LrcBuilder.offsetOf(''), 0);
    });

    test('offsetOf only honours a whole-line header', () {
      // A header glued to other text is not a header.
      expect(LrcBuilder.offsetOf('x [offset:+400] y'), 0);
    });

    test('shiftAll shifts stamped lines only, leaving gaps intact', () {
      var lines = LrcBuilder.linesFrom('one\ntwo\nthree');
      lines = LrcBuilder.stamp(lines, 0, const Duration(seconds: 10));
      lines = LrcBuilder.stamp(lines, 1, const Duration(seconds: 14));
      final shifted =
          LrcBuilder.shiftAll(lines, const Duration(milliseconds: 500));
      expect(shifted[0].at, const Duration(milliseconds: 10500));
      expect(shifted[1].at, const Duration(milliseconds: 14500));
      expect(shifted[2].isStamped, isFalse); // unstamped stays unstamped
      expect(shifted[1].at! - shifted[0].at!, const Duration(seconds: 4));
    });

    test('shiftAll clamps at zero and never inverts order', () {
      var lines = LrcBuilder.linesFrom('a\nb');
      lines = LrcBuilder.stamp(lines, 0, const Duration(milliseconds: 200));
      lines = LrcBuilder.stamp(lines, 1, const Duration(milliseconds: 900));
      final shifted =
          LrcBuilder.shiftAll(lines, const Duration(milliseconds: -500));
      expect(shifted[0].at, Duration.zero);
      expect(shifted[1].at, const Duration(milliseconds: 400));
      expect(shifted[1].at! > shifted[0].at!, isTrue);
    });

    test('a full edit round-trip preserves the HEARD timings', () {
      const doc = '[offset:+500]\n[00:10.000] one\n[00:20.000] two';
      // Load: bake into playback time.
      final lines = LrcBuilder.shiftAll(
          LrcBuilder.parse(doc), const Duration(milliseconds: 500));
      expect(lines[0].at, const Duration(milliseconds: 10500));
      // Save: unbake. Stamps are byte-identical to the original document's;
      // the offset is no longer needed because it lives in the stamps.
      final out = LrcBuilder.build(LrcBuilder.shiftAll(
          lines, const Duration(milliseconds: -500)));
      expect(out, '[00:10.000] one\n[00:20.000] two');
    });

    test('a NEW stamp made against playback time saves at the heard moment', () {
      // Document says +500. The curator hears the second line start at
      // 12.0 s and taps exactly then. The saved stamp must be 11.5 s so
      // the highlighting (which applies +offset) fires at 12.0 s.
      // (Mirrors the editor's real load flow: applyExisting over baked
      // stamps, then stamp the next line at the live position.)
      var lines = LrcBuilder.applyExisting(
          LrcBuilder.linesFrom('one\ntwo'),
          LrcBuilder.shiftAll(LrcBuilder.parse('[offset:+500]\n[00:10.000] one'),
              const Duration(milliseconds: 500)));
      lines = LrcBuilder.stamp(lines, 1, const Duration(seconds: 12));
      final out = LrcBuilder.build(LrcBuilder.shiftAll(
          lines, const Duration(milliseconds: -500)));
      expect(out, '[00:10.000] one\n[00:11.500] two');
    });
  });

  group('P62 — parse hardening against non-canonical stamp runs', () {
    test('a run line parses to clean text at the FIRST stamp', () {
      // Canonical docs never carry runs (the server expands them), but a
      // raw hand-made file must not corrupt the words either.
      final lines = LrcBuilder.parse('[00:01.00][00:09.00] ሃሌ ሉያ');
      expect(lines.length, 1);
      expect(lines[0].text, 'ሃሌ ሉያ'); // not '[00:09.00] ሃሌ ሉያ'
      expect(lines[0].at, const Duration(seconds: 1));
    });

    test('canonical lines are untouched by the hardening', () {
      final lines = LrcBuilder.parse('[00:10.500] one\n[00:20.000] two');
      expect(lines.map((l) => l.text), ['one', 'two']);
      expect(lines[0].at, const Duration(milliseconds: 10500));
    });
  });
}


