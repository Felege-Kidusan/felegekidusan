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
  group('P61 — profile scaleFor/opacityFor (the one-tween contract)', () {
    // The lyrics screen drives its emphasis from ONE tweened value (the
    // line's distance, animated through fractional values) and derives
    // scale/opacity from the profile's continuous functions. forIndex uses
    // the SAME functions for resting endpoints. These tests pin that
    // equality so a future refactor can never reintroduce two formulas
    // that drift apart mid-animation.
    test('continuous derivation lands exactly on the resting endpoints', () {
      for (final p in [
        LyricEmphasisProfile.karaoke,
        LyricEmphasisProfile.reading,
      ]) {
        for (var d = 0; d <= 10; d++) {
          final e = LyricEmphasis.forIndex(index: 5, active: 5 - d, profile: p);
          expect(p.scaleFor(d.toDouble()), e.scale,
              reason: '${p == LyricEmphasisProfile.reading ? "reading" : "karaoke"} scale at d=$d');
          expect(p.opacityFor(d.toDouble()), e.opacity,
              reason: '${p == LyricEmphasisProfile.reading ? "reading" : "karaoke"} opacity at d=$d');
        }
      }
    });

    test('karaoke falloff is pinned (the exact published curve)', () {
      const p = LyricEmphasisProfile.karaoke;
      expect(p.scaleFor(0), 1.0);
      expect(p.opacityFor(0), 1.0);
      expect(p.scaleFor(1), closeTo(0.92, 1e-9));
      expect(p.opacityFor(1), closeTo(0.85, 1e-9));
      expect(p.scaleFor(4), closeTo(0.86, 1e-9)); // scale floor
      expect(p.opacityFor(4), closeTo(0.42, 1e-9)); // opacity floor
      expect(p.scaleFor(50), p.minScale); // and it stays there
      expect(p.opacityFor(50), p.minOpacity);
    });

    test('reading profile never scales below 1.0 (no shrink, ever)', () {
      const p = LyricEmphasisProfile.reading;
      for (var d = 0.0; d <= 20; d += 0.25) {
        expect(p.scaleFor(d), 1.0);
      }
    });

    test('fractional distances are monotonic — the tween never wobbles', () {
      const p = LyricEmphasisProfile.karaoke;
      var prevScale = p.scaleFor(0);
      var prevOpacity = p.opacityFor(0);
      for (var d = 0.25; d <= 8; d += 0.25) {
        final s = p.scaleFor(d);
        final o = p.opacityFor(d);
        expect(s, lessThanOrEqualTo(prevScale));
        expect(o, lessThanOrEqualTo(prevOpacity));
        prevScale = s;
        prevOpacity = o;
      }
    });
  });
}
