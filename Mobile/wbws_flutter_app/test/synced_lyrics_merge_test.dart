import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/synced_lyrics_merge.dart';

/// P48 regression guard.
///
/// The shipped bug: a server whose schema lacked `lyrics_synced` emitted
/// `'' AS lyrics_synced`, and the device treated that as authoritative,
/// wiping good local LRC on every delta pull. Karaoke highlighting,
/// animation and auto-scroll then had nothing to run on — while the web
/// player, reading the same database directly, still worked.
void main() {
  const lrc = '[00:12.500] ሃሌ ሉያ\n[00:18.250] ሀባ ሰላምከ';

  group('P48 classify', () {
    test('null is unknown', () {
      expect(SyncedLyricsMerge.classify(null), SyncedLyricsSignal.unknown);
    });
    test('empty and whitespace are ambiguous, never authoritative', () {
      expect(SyncedLyricsMerge.classify(''),
          SyncedLyricsSignal.ambiguousEmpty);
      expect(SyncedLyricsMerge.classify('   \n '),
          SyncedLyricsSignal.ambiguousEmpty);
    });
    test('real LRC is authoritative', () {
      expect(SyncedLyricsMerge.classify(lrc),
          SyncedLyricsSignal.authoritative);
    });
  });

  group('P48 merge — a sync must never destroy a curator\'s work', () {
    test('THE BUG: empty from a schema-less server keeps local timings', () {
      expect(SyncedLyricsMerge.merge(incoming: '', local: lrc), lrc);
    });

    test('null (column absent) keeps local timings', () {
      expect(SyncedLyricsMerge.merge(incoming: null, local: lrc), lrc);
    });

    test('whitespace-only keeps local timings', () {
      expect(SyncedLyricsMerge.merge(incoming: '  ', local: lrc), lrc);
    });

    test('real LRC from the server wins', () {
      const newer = '[00:01.000] new';
      expect(SyncedLyricsMerge.merge(incoming: newer, local: lrc), newer);
    });

    test('real LRC arrives when the device had nothing', () {
      expect(SyncedLyricsMerge.merge(incoming: lrc, local: null), lrc);
    });

    test('nothing on either side stays nothing', () {
      expect(SyncedLyricsMerge.merge(incoming: '', local: null), isNull);
    });

    test('repeated empty payloads are idempotent, not cumulative', () {
      var v = lrc;
      for (var i = 0; i < 20; i++) {
        v = SyncedLyricsMerge.merge(incoming: '', local: v)!;
      }
      expect(v, lrc, reason: 'twenty delta pulls must not erode the data');
    });

    test('a non-string payload never crashes the sync', () {
      expect(SyncedLyricsMerge.merge(incoming: 42, local: lrc), '42');
      expect(SyncedLyricsMerge.merge(incoming: false, local: lrc), 'false');
    });
  });

  group('P48 isPlayable — can this drive highlighting?', () {
    test('canonical server output is playable', () {
      expect(SyncedLyricsMerge.isPlayable(lrc), isTrue);
    });
    test('two-decimal and no-decimal stamps are accepted', () {
      expect(SyncedLyricsMerge.isPlayable('[00:12.50] a'), isTrue);
      expect(SyncedLyricsMerge.isPlayable('[00:12] a'), isTrue);
    });
    test('headers alone are NOT playable', () {
      expect(SyncedLyricsMerge.isPlayable('[ti:Title]\n[ar:Singer]'), isFalse);
    });
    test('plain lyrics with no timestamps are not playable', () {
      expect(SyncedLyricsMerge.isPlayable('ሃሌ ሉያ\nሀባ ሰላምከ'), isFalse);
    });
    test('a stamp with no text is not playable', () {
      expect(SyncedLyricsMerge.isPlayable('[00:12.500]   '), isFalse);
    });
    test('null and empty are not playable', () {
      expect(SyncedLyricsMerge.isPlayable(null), isFalse);
      expect(SyncedLyricsMerge.isPlayable(''), isFalse);
    });
    test('a timed line later in the document still counts', () {
      expect(SyncedLyricsMerge.isPlayable('[ti:x]\n\n[00:05.000] first'),
          isTrue);
    });
  });
}
