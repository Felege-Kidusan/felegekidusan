import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/karaoke_engine.dart';
import 'package:fkss_app/services/mezmur_synced_lyrics.dart';

SyncedLyrics _doc(List<SyncedLyricLine> lines, {int offsetMs = 0}) =>
    SyncedLyrics(lines: lines, offsetMs: offsetMs);

void main() {
  group('KaraokeEngine — empty & pre-first', () {
    test('empty document paints nothing and knows no next line', () {
      final f = KaraokeEngine.frameFor(_doc(const []), const Duration(seconds: 5));
      expect(f.activeIndex, -1);
      expect(f.lineFill, 0);
      expect(f.wordFills, isEmpty);
      expect(f.nextLineStartAt, isNull);
    });

    test('before the first stamp: nothing active, next line = first stamp (playback time)', () {
      final doc = _doc(const [
        SyncedLyricLine(time: Duration(seconds: 10), text: 'ሃሌ'),
        SyncedLyricLine(time: Duration(seconds: 12), text: 'ሉያ'),
      ]);
      final f = KaraokeEngine.frameFor(doc, const Duration(seconds: 3));
      expect(f.activeIndex, -1);
      expect(f.lineFill, 0);
      expect(f.wordFills, isEmpty);
      expect(f.nextLineStartAt, const Duration(seconds: 10));
    });

    test('pre-first next line carries the offset', () {
      final doc = _doc(const [
        SyncedLyricLine(time: Duration(seconds: 10), text: 'ሃሌ'),
      ], offsetMs: 500);
      final f = KaraokeEngine.frameFor(doc, Duration.zero);
      expect(f.activeIndex, -1);
      expect(f.nextLineStartAt, const Duration(milliseconds: 10500));
    });
  });

  group('KaraokeEngine — whole-line fill (plain LRC)', () {
    final doc = _doc(const [
      SyncedLyricLine(time: Duration(seconds: 10), text: 'ሃሌ ሉያ'),
      SyncedLyricLine(time: Duration(seconds: 14), text: 'ያ'),
    ]);

    test('fill is 0 at the exact line start and 1 at the next line', () {
      final a = KaraokeEngine.frameFor(doc, const Duration(seconds: 10));
      expect(a.activeIndex, 0);
      expect(a.lineFill, 0);
      expect(a.wordFills, isEmpty);
      expect(a.nextLineStartAt, const Duration(seconds: 14));

      final b = KaraokeEngine.frameFor(doc, const Duration(seconds: 14));
      expect(b.activeIndex, 1);
      expect(b.lineFill, 0);
    });

    test('fill interpolates continuously across the line window', () {
      final half = KaraokeEngine.frameFor(doc, const Duration(seconds: 12));
      expect(half.activeIndex, 0);
      expect(half.lineFill, closeTo(0.5, 1e-9));
    });

    test('last line fills across its default window then rests full', () {
      // Line 1 starts at 14s, no next line → window [14, 18.5).
      final mid = KaraokeEngine.frameFor(doc, const Duration(seconds: 16, milliseconds: 250));
      expect(mid.activeIndex, 1);
      expect(mid.lineFill, closeTo(0.5, 1e-9));
      expect(mid.nextLineStartAt, isNull);

      final after = KaraokeEngine.frameFor(doc, const Duration(seconds: 60));
      expect(after.activeIndex, 1);
      expect(after.lineFill, 1);
    });

    test('fill caps at 6s so long instrumental gaps do not crawl', () {
      final slow = _doc(const [
        SyncedLyricLine(time: Duration(seconds: 10), text: 'ሃሌ'),
        SyncedLyricLine(time: Duration(seconds: 40), text: 'ሉያ'),
      ]);
      // Window would be 30s; the cap completes the fill at 16s.
      final at15 = KaraokeEngine.frameFor(slow, const Duration(seconds: 15));
      expect(at15.lineFill, closeTo(5 / 6, 1e-9));
      final at20 = KaraokeEngine.frameFor(slow, const Duration(seconds: 20));
      expect(at20.lineFill, 1);
    });

    test('empty (instrumental) lines fill like any other line', () {
      final drums = _doc(const [
        SyncedLyricLine(time: Duration(seconds: 10), text: ''),
        SyncedLyricLine(time: Duration(seconds: 14), text: 'ሉያ'),
      ]);
      final f = KaraokeEngine.frameFor(drums, const Duration(seconds: 12));
      expect(f.activeIndex, 0);
      expect(f.wordFills, isEmpty);
      expect(f.lineFill, closeTo(0.5, 1e-9));
    });
  });

  group('KaraokeEngine — word fill (enhanced LRC)', () {
    test('each word fills across its own window; boundaries are exact', () {
      final doc = _doc(const [
        SyncedLyricLine(
          time: Duration(seconds: 10),
          text: 'ሃሌ ሉያ',
          words: [
            SyncedLyricWord(text: 'ሃሌ ', start: Duration(seconds: 10)),
            SyncedLyricWord(text: 'ሉያ', start: Duration(milliseconds: 10500)),
          ],
        ),
        SyncedLyricLine(time: Duration(seconds: 12), text: 'ያ'),
      ]);

      final start = KaraokeEngine.frameFor(doc, const Duration(seconds: 10));
      expect(start.wordFills, [0.0, 0.0]);

      final quarter = KaraokeEngine.frameFor(doc, const Duration(milliseconds: 10125));
      expect(quarter.wordFills[0], closeTo(0.25, 1e-9));
      expect(quarter.wordFills[1], 0.0);

      final boundary = KaraokeEngine.frameFor(doc, const Duration(milliseconds: 10500));
      expect(boundary.wordFills[0], 1.0);
      expect(boundary.wordFills[1], 0.0);

      final midSecond = KaraokeEngine.frameFor(doc, const Duration(milliseconds: 10750));
      expect(midSecond.wordFills[0], 1.0);
      // Last word stretches to the line end (12s): 250ms of 1500ms.
      expect(midSecond.wordFills[1], closeTo(1 / 6, 1e-9));

      final end = KaraokeEngine.frameFor(doc, const Duration(seconds: 12));
      expect(end.activeIndex, 1); // line 0 fully sung the instant line 1 begins
    });

    test('a word never fills longer than 4s however long the pause after it', () {
      final doc = _doc(const [
        SyncedLyricLine(
          time: Duration(seconds: 10),
          text: 'ሃሌ ሉያ',
          words: [
            SyncedLyricWord(text: 'ሃሌ ', start: Duration(seconds: 10)),
            SyncedLyricWord(text: 'ሉያ', start: Duration(milliseconds: 10500)),
          ],
        ),
        SyncedLyricLine(time: Duration(seconds: 40), text: 'ያ'),
      ]);
      // Word 1's raw window is [10.5, 40) — capped to [10.5, 14.5).
      final at12 = KaraokeEngine.frameFor(doc, const Duration(seconds: 12));
      expect(at12.wordFills[1], closeTo(1.5 / 4.0, 1e-9));
      final at20 = KaraokeEngine.frameFor(doc, const Duration(seconds: 20));
      expect(at20.wordFills[1], 1.0);
    });

    test('line fill still tracks the full window when words exist', () {
      final doc = _doc(const [
        SyncedLyricLine(
          time: Duration(seconds: 10),
          text: 'ሃሌ ሉያ',
          words: [
            SyncedLyricWord(text: 'ሃሌ ', start: Duration(seconds: 10)),
            SyncedLyricWord(text: 'ሉያ', start: Duration(milliseconds: 10500)),
          ],
        ),
        SyncedLyricLine(time: Duration(seconds: 12), text: 'ያ'),
      ]);
      final f = KaraokeEngine.frameFor(doc, const Duration(seconds: 11));
      expect(f.lineFill, closeTo(0.5, 1e-9));
    });

    test('real enhanced-LRC parse feeds the engine correctly', () {
      final doc = SyncedLyrics.tryParse(
          '[00:10.00]<00:10.00>ሃሌ <00:10.50>ሉያ\n[00:12.00]ያ');
      expect(doc, isNotNull);
      expect(doc!.lines[0].words.length, 2);
      final f = KaraokeEngine.frameFor(
          doc, const Duration(milliseconds: 10125));
      expect(f.activeIndex, 0);
      expect(f.wordFills.length, 2);
      expect(f.wordFills[0], closeTo(0.25, 1e-9));
      expect(f.wordFills[1], 0.0);
      expect(f.nextLineStartAt, const Duration(seconds: 12));
    });
  });

  group('KaraokeEngine — offset discipline', () {
    test('active ⇔ position ≥ line.time + offset (same rule as indexFor)', () {
      final doc = _doc(const [
        SyncedLyricLine(time: Duration(seconds: 10), text: 'ሃሌ'),
        SyncedLyricLine(time: Duration(seconds: 12), text: 'ሉያ'),
      ], offsetMs: 500);
      expect(KaraokeEngine.frameFor(doc, const Duration(milliseconds: 10499)).activeIndex, -1);
      expect(KaraokeEngine.frameFor(doc, const Duration(milliseconds: 10500)).activeIndex, 0);
    });

    test('nextLineStartAt is expressed in playback time', () {
      final doc = _doc(const [
        SyncedLyricLine(time: Duration(seconds: 10), text: 'ሃሌ'),
        SyncedLyricLine(time: Duration(seconds: 12), text: 'ሉያ'),
      ], offsetMs: 500);
      final f = KaraokeEngine.frameFor(doc, const Duration(milliseconds: 10600));
      expect(f.activeIndex, 0);
      expect(f.nextLineStartAt, const Duration(milliseconds: 12500));
    });
  });

  group('KaraokeEngine — frame equality', () {
    test('identical positions produce equal frames', () {
      final doc = _doc(const [
        SyncedLyricLine(
          time: Duration(seconds: 10),
          text: 'ሃሌ ሉያ',
          words: [
            SyncedLyricWord(text: 'ሃሌ ', start: Duration(seconds: 10)),
            SyncedLyricWord(text: 'ሉያ', start: Duration(milliseconds: 10500)),
          ],
        ),
      ]);
      final a = KaraokeEngine.frameFor(doc, const Duration(milliseconds: 10200));
      final b = KaraokeEngine.frameFor(doc, const Duration(milliseconds: 10200));
      expect(a, b);
      expect(a.hashCode, b.hashCode);
    });

    test('a fill difference alone changes equality (paint-only notifier needs this)', () {
      final doc = _doc(const [
        SyncedLyricLine(time: Duration(seconds: 10), text: 'ሃሌ'),
        SyncedLyricLine(time: Duration(seconds: 14), text: 'ሉያ'),
      ]);
      final a = KaraokeEngine.frameFor(doc, const Duration(seconds: 11));
      final b = KaraokeEngine.frameFor(doc, const Duration(seconds: 12));
      expect(a, isNot(b));
    });
  });

  group('KaraokeEngine.wordCharSpans', () {
    test('spans tile the joined text exactly', () {
      const words = [
        SyncedLyricWord(text: 'ሃሌ ', start: Duration(seconds: 10)),
        SyncedLyricWord(text: 'ሉያ', start: Duration(milliseconds: 10500)),
      ];
      final spans = KaraokeEngine.wordCharSpans(words);
      expect(spans.length, 2);
      expect(spans[0], (start: 0, end: 3)); // ሃሌ + space = 3 UTF-16 units
      expect(spans[1], (start: 3, end: 5));
      expect(spans.last.end, words.map((w) => w.text).join().length);
    });

    test('empty words produce no spans', () {
      expect(KaraokeEngine.wordCharSpans(const []), isEmpty);
    });
  });
}
