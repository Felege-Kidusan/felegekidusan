import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/karaoke_style.dart';

void main() {
  group('KaraokeProfile.spotify — the falloff table', () {
    const p = KaraokeProfile.spotify;

    test('the active line is full strength: opacity 1, scale 1, no blur', () {
      for (final past in [false, true]) {
        final e = p.forDistance(0, past: past);
        expect(e.opacity, 1.0, reason: 'past: $past');
        expect(e.scale, 1.0);
        expect(e.sigma, 0.0);
      }
    });

    test('future lines: −0.10 per line, floor 0.62', () {
      expect(p.forDistance(1, past: false).opacity, closeTo(0.90, 1e-9));
      expect(p.forDistance(2, past: false).opacity, closeTo(0.80, 1e-9));
      expect(p.forDistance(3, past: false).opacity, closeTo(0.70, 1e-9));
      expect(p.forDistance(4, past: false).opacity, closeTo(0.62, 1e-9));
      expect(p.forDistance(50, past: false).opacity, closeTo(0.62, 1e-9));
    });

    test('past lines: −0.15 per line, floor 0.44', () {
      expect(p.forDistance(1, past: true).opacity, closeTo(0.85, 1e-9));
      expect(p.forDistance(2, past: true).opacity, closeTo(0.70, 1e-9));
      expect(p.forDistance(3, past: true).opacity, closeTo(0.55, 1e-9));
      expect(p.forDistance(4, past: true).opacity, closeTo(0.44, 1e-9));
      expect(p.forDistance(50, past: true).opacity, closeTo(0.44, 1e-9));
    });

    test('the falloff is asymmetric: past recedes harder than future', () {
      for (var d = 1; d <= 10; d++) {
        expect(
          p.forDistance(d.toDouble(), past: true).opacity,
          lessThan(p.forDistance(d.toDouble(), past: false).opacity),
          reason: 'distance $d',
        );
      }
    });

    test('scale is subtle: −0.03 per line, floor 0.88, symmetric', () {
      expect(p.forDistance(1, past: false).scale, closeTo(0.97, 1e-9));
      expect(p.forDistance(2, past: true).scale, closeTo(0.94, 1e-9));
      expect(p.forDistance(4, past: false).scale, closeTo(0.88, 1e-9));
      expect(p.forDistance(50, past: true).scale, closeTo(0.88, 1e-9));
      expect(p.forDistance(3, past: true).scale,
          p.forDistance(3, past: false).scale);
    });

    test('blur starts one line away and caps at 1.4σ', () {
      expect(p.forDistance(0, past: false).sigma, 0.0);
      expect(p.forDistance(1, past: false).sigma, 0.0);
      expect(p.forDistance(2, past: true).sigma, closeTo(0.50, 1e-9));
      expect(p.forDistance(3, past: true).sigma, closeTo(1.00, 1e-9));
      expect(p.forDistance(4, past: false).sigma, closeTo(1.40, 1e-9));
      expect(p.forDistance(50, past: false).sigma, closeTo(1.40, 1e-9));
    });

    test('opacity and scale are monotonic non-increasing; sigma non-decreasing', () {
      for (final past in [false, true]) {
        var prevOp = 1.01;
        var prevScale = 1.01;
        var prevSigma = -0.01;
        for (var d = 0; d <= 12; d++) {
          final e = p.forDistance(d.toDouble(), past: past);
          expect(e.opacity, lessThanOrEqualTo(prevOp),
              reason: 'past=$past d=$d opacity');
          expect(e.scale, lessThanOrEqualTo(prevScale),
              reason: 'past=$past d=$d scale');
          expect(e.sigma, greaterThanOrEqualTo(prevSigma),
              reason: 'past=$past d=$d sigma');
          prevOp = e.opacity;
          prevScale = e.scale;
          prevSigma = e.sigma;
        }
      }
    });

    test('continuous at distance 0: past and future branches agree', () {
      // The renderer can flip a line's past/future flag mid-tween; the
      // formulas must be exactly equal at 0 so nothing pops.
      expect(p.forDistance(0, past: true).opacity,
          p.forDistance(0, past: false).opacity);
      // And near 0 the branches stay close (a fractional tween crossing 0).
      expect(
        (p.forDistance(0.001, past: true).opacity -
                p.forDistance(0.001, past: false).opacity)
            .abs(),
        lessThan(0.01),
      );
    });

    test('fractional distances tween smoothly (no integer snapping)', () {
      final a = p.forDistance(1.5, past: false);
      expect(a.opacity, closeTo(0.85, 1e-9)); // between 0.90 and 0.80
      expect(a.sigma, closeTo(0.25, 1e-9)); // between 0 and 0.5
    });

    test('negative distances clamp to the active-line values', () {
      final e = p.forDistance(-3, past: false);
      expect(e.opacity, 1.0);
      expect(e.scale, 1.0);
      expect(e.sigma, 0.0);
    });
  });

  group('KaraokeProfile.reading — the steady contract', () {
    const p = KaraokeProfile.reading;

    test('never blurs, never scales', () {
      for (var d = 0; d <= 20; d++) {
        final f = p.forDistance(d.toDouble(), past: false);
        expect(f.sigma, 0.0, reason: 'd=$d');
        expect(f.scale, 1.0, reason: 'd=$d');
      }
    });

    test('everything stays legible: floor 0.86', () {
      expect(p.forDistance(50, past: true).opacity, closeTo(0.86, 1e-9));
      expect(p.forDistance(50, past: false).opacity, closeTo(0.86, 1e-9));
    });

    test('still gives the active line a whisper of emphasis', () {
      expect(p.forDistance(0, past: false).opacity, 1.0);
      expect(p.forDistance(1, past: false).opacity, closeTo(0.95, 1e-9));
    });
  });

  group('KaraokeProfile.highContrast — accessible karaoke', () {
    const p = KaraokeProfile.highContrast;

    test('never blurs', () {
      for (var d = 0; d <= 20; d++) {
        expect(p.forDistance(d.toDouble(), past: true).sigma, 0.0);
      }
    });

    test('keeps both branches clearly legible', () {
      expect(p.forDistance(1, past: false).opacity, closeTo(0.92, 1e-9));
      expect(p.forDistance(50, past: false).opacity, closeTo(0.85, 1e-9));
      expect(p.forDistance(1, past: true).opacity, closeTo(0.90, 1e-9));
      expect(p.forDistance(50, past: true).opacity, closeTo(0.75, 1e-9));
    });

    test('still asymmetric (past recedes harder)', () {
      expect(p.forDistance(5, past: true).opacity,
          lessThan(p.forDistance(5, past: false).opacity));
    });
  });

  group('KaraokeEmphasis — value sanity for every profile', () {
    test('opacity/scale always within [floor, 1], sigma within [0, cap]', () {
      for (final p in [
        KaraokeProfile.spotify,
        KaraokeProfile.reading,
        KaraokeProfile.highContrast,
      ]) {
        for (var d = 0; d <= 30; d++) {
          for (final past in [false, true]) {
            final e = p.forDistance(d.toDouble(), past: past);
            expect(e.opacity, greaterThan(0.0));
            expect(e.opacity, lessThanOrEqualTo(1.0));
            expect(e.scale, greaterThan(0.0));
            expect(e.scale, lessThanOrEqualTo(1.0));
            expect(e.sigma, greaterThanOrEqualTo(0.0));
            expect(e.sigma, lessThanOrEqualTo(p.maxSigma + 1e-9));
          }
        }
      }
    });
  });
}
