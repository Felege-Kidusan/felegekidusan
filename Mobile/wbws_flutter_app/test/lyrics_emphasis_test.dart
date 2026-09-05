import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/lyrics_emphasis.dart';

/// P51 unit tests for the lyric "deep water" emphasis rule.
///
/// The rule is deliberately pure (no Flutter), so the visual contract is
/// pinned here separately from the pixels. If the falloff is ever retuned,
/// these tests are the record of what "the active line is the largest, the
/// rest recede" must always mean.
void main() {
  group('LyricEmphasis — active line', () {
    test('index == active is the active line', () {
      final e = LyricEmphasis.forIndex(index: 5, active: 5);
      expect(e.isActive, isTrue);
      expect(e.scale, 1.0);
      expect(e.opacity, 1.0);
      expect(e.distance, 0);
    });

    test('active sentinel -1 means nothing is active yet (leading fade)', () {
      // Playback precedes the first timestamp → no line can be "the sung line".
      final e = LyricEmphasis.forIndex(index: 0, active: -1);
      expect(e.isActive, isFalse);
      expect(e.distance, 1); // line 0 is nearest, but never called active
      expect(e.scale, lessThan(1.0));
    });
  });

  group('LyricEmphasis — monotonic falloff', () {
    test('each further line is smaller and fainter', () {
      var prev = LyricEmphasis.forIndex(index: 3, active: 5); // distance 2
      for (var d = 3; d <= 6; d++) {
        final e = LyricEmphasis.forIndex(index: 5 + d, active: 5);
        expect(e.isActive, isFalse);
        // strictly recedes
        expect(e.scale, lessThanOrEqualTo(prev.scale));
        expect(e.opacity, lessThanOrEqualTo(prev.opacity));
        prev = e;
      }
    });

    test('a line after the active recedes too (symmetric depth)', () {
      final before = LyricEmphasis.forIndex(index: 4, active: 5);
      final after = LyricEmphasis.forIndex(index: 6, active: 5);
      expect(before.isActive, isFalse);
      expect(after.isActive, isFalse);
      // Both are one line away → identical emphasis
      expect(before.distance, after.distance);
      expect(before.scale, after.scale);
      expect(before.opacity, after.opacity);
    });
  });

  group('LyricEmphasis — clamping', () {
    test('a very distant line never collapses to invisible', () {
      final e = LyricEmphasis.forIndex(index: 0, active: 200);
      expect(e.scale, greaterThanOrEqualTo(0.80));
      expect(e.opacity, greaterThanOrEqualTo(0.30));
    });

    test('out-of-range index is clamped, never crashes', () {
      expect(() => LyricEmphasis.forIndex(index: -5, active: 4),
          returnsNormally);
      final e = LyricEmphasis.forIndex(index: -5, active: 4);
      expect(e.isActive, isFalse);
    });
  });

  group('LyricEmphasis — reading profile ("lyrics only")', () {
    test('off lines keep FULL size — nothing reflows or shrinks', () {
      // The point of reading mode: big, steady, fully legible text.
      for (var d = 1; d <= 12; d++) {
        final e = LyricEmphasis.forIndex(
          index: 5 + d,
          active: 5,
          profile: LyricEmphasisProfile.reading,
        );
        expect(e.scale, 1.0, reason: 'scale must never drop below 1.0');
        expect(e.distance, d);
      }
    });

    test('off lines fade only slightly — far lines stay clearly readable', () {
      for (var d = 1; d <= 12; d++) {
        final e = LyricEmphasis.forIndex(
          index: 5 + d,
          active: 5,
          profile: LyricEmphasisProfile.reading,
        );
        expect(e.opacity, greaterThanOrEqualTo(0.86));
      }
    });

    test('active line is still the active line', () {
      final e = LyricEmphasis.forIndex(
        index: 5,
        active: 5,
        profile: LyricEmphasisProfile.reading,
      );
      expect(e.isActive, isTrue);
      expect(e.scale, 1.0);
      expect(e.opacity, 1.0);
    });

    test('reading fade is strictly gentler than the karaoke fade', () {
      final karaoke = LyricEmphasis.forIndex(index: 5, active: 0);
      final reading = LyricEmphasis.forIndex(
        index: 5, active: 0, profile: LyricEmphasisProfile.reading);
      expect(reading.scale, greaterThan(karaoke.scale));
      expect(reading.opacity, greaterThan(karaoke.opacity));
    });
  });
}
