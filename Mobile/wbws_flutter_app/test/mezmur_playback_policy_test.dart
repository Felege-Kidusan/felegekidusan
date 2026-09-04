import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/mezmur_playback_policy.dart';

/// Regression guard for the Mezmur next/previous/auto-advance policy.
///
/// Every navigation DECISION the player makes lives in the pure module
/// `mezmur_playback_policy.dart` so it can be pinned here without a device or
/// an audio engine. Catalog rows are given by audio flags ([T] = has audio,
/// [F] = lyrics-only). The policy must always move exactly one visible row
/// for buttons/swipe (so lyrics-only hymns are real neighbours) while
/// auto-advance skips to the next playable hymn so continuous sound flows.
void main() {
  // Catalog [A(audio), B(lyrics), C(audio), D(lyrics), E(audio)].
  const flags = [true, false, true, false, true];

  group('catalog <-> audio-queue mapping', () {
    test('audioOrdinalForRow maps visible rows to playable ordinals', () {
      expect(MezmurPlaybackPolicy.audioOrdinalForRow(flags, 0), 0);
      expect(MezmurPlaybackPolicy.audioOrdinalForRow(flags, 1), isNull); // B
      expect(MezmurPlaybackPolicy.audioOrdinalForRow(flags, 2), 1);
      expect(MezmurPlaybackPolicy.audioOrdinalForRow(flags, 4), 2);
    });

    test('rowForAudioOrdinal inverts the mapping', () {
      expect(MezmurPlaybackPolicy.rowForAudioOrdinal(flags, 0), 0);
      expect(MezmurPlaybackPolicy.rowForAudioOrdinal(flags, 1), 2);
      expect(MezmurPlaybackPolicy.rowForAudioOrdinal(flags, 2), 4);
      expect(MezmurPlaybackPolicy.rowForAudioOrdinal(flags, 3), -1);
    });
  });

  group('buttons + swipe step exactly one VISIBLE row', () {
    test('next lands on the adjacent hymn even when it is lyrics-only', () {
      // from A(0) audio -> B(1) lyrics: the neighbour is still visited.
      expect(MezmurPlaybackPolicy.nextRow(flags, 0, PlaybackLoop.off), 1);
      expect(MezmurPlaybackPolicy.nextRow(flags, 1, PlaybackLoop.off), 2);
      expect(MezmurPlaybackPolicy.nextRow(flags, 4, PlaybackLoop.off), 4); // end
    });

    test('previous lands on the adjacent hymn', () {
      expect(MezmurPlaybackPolicy.previousRow(flags, 2, PlaybackLoop.off), 1);
      expect(MezmurPlaybackPolicy.previousRow(flags, 1, PlaybackLoop.off), 0);
      expect(MezmurPlaybackPolicy.previousRow(flags, 0, PlaybackLoop.off), 0); // start
    });

    test('loop-all wraps at both ends', () {
      expect(MezmurPlaybackPolicy.nextRow(flags, 4, PlaybackLoop.all), 0);
      expect(MezmurPlaybackPolicy.previousRow(flags, 0, PlaybackLoop.all), 4);
    });

    test('whole-list delta stepping wraps only in loop-all', () {
      expect(MezmurPlaybackPolicy.stepRow(flags, 4, 1, PlaybackLoop.all), 0);
      expect(MezmurPlaybackPolicy.stepRow(flags, 0, -1, PlaybackLoop.all), 4);
      expect(MezmurPlaybackPolicy.stepRow(flags, 4, 1, PlaybackLoop.off), 4);
    });
  });

  group('industry previous semantics', () {
    test('restarts the playing hymn once past the threshold', () {
      final d = MezmurPlaybackPolicy.previousTarget(
        flags,
        0,
        loop: PlaybackLoop.off,
        isPlaying: true,
        positionMs: 5000,
      );
      expect(d.restartCurrent, isTrue);
      expect(d.targetRow, 0);
      expect(d.shouldAutoPlay, isFalse);
    });

    test('moves back one row before the threshold', () {
      final d = MezmurPlaybackPolicy.previousTarget(
        flags,
        2,
        loop: PlaybackLoop.off,
        isPlaying: true,
        positionMs: 500, // early -> go back, do not restart
      );
      expect(d.restartCurrent, isFalse);
      expect(d.targetRow, 1); // B, the adjacent visible row
      expect(d.shouldAutoPlay, isFalse); // B has no audio
    });

    test('a lyrics-only current row always moves back (no restart)', () {
      final d = MezmurPlaybackPolicy.previousTarget(
        flags,
        1,
        loop: PlaybackLoop.off,
        isPlaying: false,
        positionMs: 0,
      );
      expect(d.restartCurrent, isFalse);
      expect(d.targetRow, 0);
      expect(d.shouldAutoPlay, isTrue); // A has audio -> autoplay
    });

    test('first row + loop off and playing early -> no-op (stays, no restart)',
        () {
      final d = MezmurPlaybackPolicy.previousTarget(
        flags,
        0,
        loop: PlaybackLoop.off,
        isPlaying: true,
        positionMs: 200,
      );
      expect(d.restartCurrent, isFalse);
      expect(d.targetRow, 0);
    });
  });

  group('completion / auto-advance keeps continuous audio', () {
    test('completion jumps to the NEXT playable row, skipping lyrics rows', () {
      // A(0) completes; the next playable row after A is C(2), not B.
      expect(MezmurPlaybackPolicy.autoAdvanceRowAfter(
        flags, 0, loop: PlaybackLoop.off), 2);
    });

    test('completion of C(ordinal 1) goes to E(ordinal 2)', () {
      expect(MezmurPlaybackPolicy.autoAdvanceRowAfter(
        flags, 1, loop: PlaybackLoop.off), 4);
    });

    test('end of list + loop off stops playback', () {
      expect(MezmurPlaybackPolicy.autoAdvanceRowAfter(
        flags, 2, loop: PlaybackLoop.off), -1);
    });

    test('loop-all wraps to the first playable row', () {
      expect(MezmurPlaybackPolicy.autoAdvanceRowAfter(
        flags, 2, loop: PlaybackLoop.all), 0);
    });

    test('loop-one repeats the same hymn', () {
      expect(MezmurPlaybackPolicy.autoAdvanceRowAfter(
        flags, 1, loop: PlaybackLoop.one), 2); // row of ordinal 1 is C
    });
  });

  group('canNext / canPrevious button affordance', () {
    test('grey out previous on the first row when loop is off', () {
      expect(MezmurPlaybackPolicy.canGoPrevious(flags, 0, PlaybackLoop.off),
          isFalse);
      expect(MezmurPlaybackPolicy.canGoPrevious(flags, 1, PlaybackLoop.off),
          isTrue);
    });
    test('single-row catalog cannot navigate either direction', () {
      expect(MezmurPlaybackPolicy.canGoNext(const [true], 0, PlaybackLoop.off),
          isFalse);
      expect(
          MezmurPlaybackPolicy.canGoPrevious(
              const [true], 0, PlaybackLoop.off),
          isFalse);
    });
    test('loop-all enables navigation at the boundary', () {
      expect(MezmurPlaybackPolicy.canGoPrevious(flags, 0, PlaybackLoop.all),
          isTrue);
      expect(MezmurPlaybackPolicy.canGoNext(flags, 4, PlaybackLoop.all), isTrue);
    });
  });
}
