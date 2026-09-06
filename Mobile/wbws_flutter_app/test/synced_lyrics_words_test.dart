import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/mezmur_synced_lyrics.dart';

/// P63 — enhanced-LRC word-level karaoke.
///
/// The display parser must turn `[00:10.000] ሃሌ <00:10.500>ሉያ` into clean
/// text plus timed chunks, and the sweep counter must advance exactly at
/// chunk boundaries with the same offset arithmetic the line highlight
/// uses. Everything here is pure: no Flutter, no audio.
void main() {
  group('P63 — word-tag parsing', () {
    test('tags split into chunks; the leading chunk starts at the line time',
        () {
      final doc =
          SyncedLyrics.tryParse('[00:10.000] ሃሌ <00:10.500>ሉያ <00:11.200>አቡ')!;
      final line = doc.lines.single;
      expect(line.text, 'ሃሌ ሉያ አቡ');
      final w = line.words;
      expect(w.length, 3);
      expect(w[0].text, 'ሃሌ ');
      expect(w[0].start, const Duration(seconds: 10)); // line stamp
      expect(w[1].start, const Duration(milliseconds: 10500));
      expect(w[2].start, const Duration(milliseconds: 11200));
      // THE SIZING CONTRACT: chunks join to exactly the plain text, so
      // the word-swept rendering and the plain rendering can never differ
      // in width (the FittedBox fit is identical either way).
      expect(w.map((e) => e.text).join(), line.text);
    });

    test('lines without tags keep the plain line-level model', () {
      final doc = SyncedLyrics.tryParse('[00:10.000] plain line')!;
      expect(doc.lines.single.words, isEmpty);
      expect(doc.lines.single.text, 'plain line');
    });

    test('multi-word chunks survive intact', () {
      final doc =
          SyncedLyrics.tryParse('[00:05.000] አንድ ሁለት <00:06.000>ሶስት አራት')!;
      final line = doc.lines.single;
      expect(line.words.length, 2);
      expect(line.words[1].text, 'ሶስት አራት');
      expect(line.words[1].start, const Duration(seconds: 6));
      expect(line.words.map((e) => e.text).join(), line.text);
    });

    test('a line whose text begins with a tag has no leading chunk', () {
      final doc = SyncedLyrics.tryParse('[00:05.000]<00:05.100>word')!;
      final line = doc.lines.single;
      expect(line.text, 'word');
      expect(line.words.length, 1);
      expect(line.words.single.start, const Duration(milliseconds: 5100));
    });

    test('malformed tags stay literal text (tolerance, not guessing)', () {
      final doc = SyncedLyrics.tryParse('[00:05.000] a <1:2> b')!;
      expect(doc.lines.single.words, isEmpty);
      expect(doc.lines.single.text, 'a <1:2> b');
    });

    test('fraction formats parse (.5 → 500 ms, .25 → 250 ms)', () {
      final doc = SyncedLyrics.tryParse('[00:05.000] x <00:05.5>y <00:06.25>z')!;
      final w = doc.lines.single.words;
      expect(w[1].start, const Duration(milliseconds: 5500));
      expect(w[2].start, const Duration(milliseconds: 6250));
    });

    test('word starts are clamped: never before the line, never out of order',
        () {
      final doc = SyncedLyrics
          .tryParse('[00:10.000] a <00:09.000>b <00:08.000>c')!;
      final w = doc.lines.single.words;
      expect(w[1].start, const Duration(seconds: 10)); // clamped up
      expect(w[2].start, const Duration(seconds: 10)); // non-decreasing
    });

    test('multi-stamp runs duplicate the line WITH its words', () {
      final doc = SyncedLyrics.tryParse('[00:01.00][00:09.00] a <00:01.50>b')!;
      expect(doc.lines.length, 2);
      for (final l in doc.lines) {
        expect(l.text, 'a b');
        expect(l.words.length, 2);
      }
      // The leading chunk of EACH copy starts at that copy's line time.
      expect(doc.lines[0].words[0].start, const Duration(seconds: 1));
      expect(doc.lines[1].words[0].start, const Duration(seconds: 9));
      // Word tags are authored for the FIRST occurrence; on the repeat
      // they are stale (1.5 s < the 9 s line) and clamp to the line time —
      // the repeat degrades to whole-line highlighting, which is the safe
      // fallback (the server expands runs before storage, so repeats in
      // stored docs carry the same stale tags and hit this same path).
      expect(doc.lines[0].words[1].start, const Duration(milliseconds: 1500));
      expect(doc.lines[1].words[1].start, const Duration(seconds: 9));
    });
  });

  group('P63 — word sweep counting', () {
    test('counts leading sung chunks, offset applied like indexFor', () {
      final doc = SyncedLyrics.tryParse(
          '[offset:+500]\n[00:10.000] a <00:10.500>b <00:11.000>c')!;
      final line = doc.lines.single;
      expect(doc.sungWordCount(line, const Duration(milliseconds: 10499)), 0);
      expect(doc.sungWordCount(line, const Duration(milliseconds: 10500)), 1);
      expect(doc.sungWordCount(line, const Duration(milliseconds: 10999)), 1);
      expect(doc.sungWordCount(line, const Duration(milliseconds: 11000)), 2);
      expect(doc.sungWordCount(line, const Duration(milliseconds: 11499)), 2);
      expect(doc.sungWordCount(line, const Duration(milliseconds: 11500)), 3);
      expect(doc.sungWordCount(line, const Duration(seconds: 30)), 3);
    });

    test('lines without words report 0', () {
      final doc = SyncedLyrics.tryParse('[00:10.000] plain')!;
      expect(
          doc.sungWordCount(doc.lines.single, const Duration(seconds: 20)), 0);
    });

    test('THE INVARIANT: the sweep advances exactly at chunk boundaries',
        () {
      for (final offset in [0, 500, -1500]) {
        final header = offset >= 0 ? '+$offset' : '$offset';
        final doc = SyncedLyrics.tryParse(
            '[offset:$header]\n[00:04.000] a <00:04.600>b <00:05.200>c')!;
        final line = doc.lines.single;
        for (final w in line.words) {
          // One millisecond before the boundary: not yet sung.
          final justBefore = w.start +
              Duration(milliseconds: offset) -
              const Duration(milliseconds: 1);
          expect(doc.sungWordCount(line, justBefore),
              line.words.indexOf(w),
              reason: 'offset $offset, just before "${w.text}"');
          // At the boundary (and far past it): sung.
          final at = w.start + Duration(milliseconds: offset);
          expect(doc.sungWordCount(line, at) > line.words.indexOf(w), isTrue,
              reason: 'offset $offset, at "${w.text}"');
        }
      }
    });
  });
}
