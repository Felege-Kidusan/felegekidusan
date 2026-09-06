import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/mezmur_synced_lyrics.dart';

/// P61 — the tap-to-seek contract.
///
/// A line becomes active when `position >= line.time + offset` (that is
/// how `indexFor` applies the `[offset:]` header). A tap that seeks must
/// land exactly there — otherwise the highlight and the audio disagree by
/// precisely the offset, invisible when offset is 0 and very visible the
/// moment a curator adds one. These tests pin the arithmetic together.
void main() {
  group('P61 — seekTargetFor', () {
    test('the seek target is the raw stamp plus the offset', () {
      final doc = SyncedLyrics.tryParse(
          '[offset:+500]\n[00:10.000] one\n[00:20.000] two')!;
      expect(doc.offsetMs, 500);
      expect(doc.seekTargetFor(doc.lines[0]),
          const Duration(milliseconds: 10500));
      expect(doc.seekTargetFor(doc.lines[1]),
          const Duration(milliseconds: 20500));
    });

    test('negative offsets pull the target earlier', () {
      final doc =
          SyncedLyrics.tryParse('[offset:-1500]\n[00:10.000] a')!;
      expect(doc.seekTargetFor(doc.lines[0]),
          const Duration(milliseconds: 8500));
    });

    test('THE INVARIANT: seeking to a line lights exactly that line', () {
      // For every offset and every line, a tap that seeks to the line's
      // target must make that line (and only that line) the active one.
      for (final offset in [0, 500, -1500]) {
        final header = offset >= 0 ? '+$offset' : '$offset';
        final doc = SyncedLyrics.tryParse(
            '[offset:$header]\n[00:04.000] a\n[00:09.500] b\n[00:15.000] c')!;
        for (final line in doc.lines) {
          final target = doc.seekTargetFor(line);
          expect(doc.indexFor(target), doc.lines.indexOf(line),
              reason: 'offset $offset, line "${line.text}"');
        }
      }
    });
  });

  group('P61 — offset semantics shared with the editor', () {
    test('indexFor shifts activation by the offset, not the stamps', () {
      // offset +1000 ⇒ the raw 10 s line is HEARD (becomes active) at 11 s.
      final doc = SyncedLyrics.tryParse(
          '[offset:+1000]\n[00:10.000] a\n[00:20.000] b')!;
      expect(doc.lines[0].time, const Duration(seconds: 10)); // raw
      expect(doc.indexFor(const Duration(milliseconds: 10999)), -1);
      expect(doc.indexFor(const Duration(milliseconds: 11000)), 0);
      expect(doc.indexFor(const Duration(milliseconds: 20999)), 0);
      expect(doc.indexFor(const Duration(milliseconds: 21000)), 1);
    });

    test('an offset document and its baked-stamp twin behave identically', () {
      // The editor saves the baked form (stamps +offset, header dropped);
      // playback highlighting must be indistinguishable between the two.
      final withHeader = SyncedLyrics.tryParse(
          '[offset:+500]\n[00:10.000] one\n[00:20.000] two')!;
      final baked = SyncedLyrics.tryParse(
          '[00:10.500] one\n[00:20.500] two')!;
      for (var ms = 9000; ms <= 22000; ms += 250) {
        final t = Duration(milliseconds: ms);
        expect(baked.indexFor(t), withHeader.indexFor(t),
            reason: 'at ${t.inMilliseconds}ms');
      }
    });
  });

  group('P61 — parser tolerance', () {
    test('non-offset metadata headers are accepted and ignored', () {
      final doc = SyncedLyrics.tryParse(
          '[ti:መዝሙር]\n[ar:Zemarian]\n[by:FKSS]\n[00:01.000] line')!;
      expect(doc.title, 'መዝሙር');
      expect(doc.artist, 'Zemarian');
      expect(doc.lines.single.text, 'line');
    });

    test('multi-stamp lines expand to one entry per stamp', () {
      final doc = SyncedLyrics
          .tryParse('[00:01.00][00:09.00] ሃሌ ሉያ')!;
      expect(doc.lines.length, 2);
      expect(doc.lines.every((l) => l.text == 'ሃሌ ሉያ'), isTrue);
      expect(doc.lines[1].time, const Duration(seconds: 9));
    });

    test('out-of-order stamps are clamped forward, never dropped', () {
      final doc = SyncedLyrics.tryParse(
          '[00:20.000] late\n[00:05.000] early')!;
      expect(doc.lines.length, 2);
      expect(doc.lines[1].time >= doc.lines[0].time, isTrue);
    });

    test('an untimed document is not a synced document', () {
      expect(SyncedLyrics.tryParse('plain words only'), isNull);
      expect(SyncedLyrics.tryParse(''), isNull);
      expect(SyncedLyrics.tryParse(null), isNull);
    });
  });
}
