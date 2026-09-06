import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/screens/mezmur/parchment_style.dart';

/// P62 — parchment art-space geometry.
///
/// The player used to place its fixed bands with SCREEN fractions, which
/// silently assumed the screen shares the artwork's 9:16 aspect. The art is
/// `BoxFit.cover`-fitted, so on width-driven screens (tablets, landscape)
/// the painted regions live somewhere else than the fractions claimed.
///
/// These tests pin the replacement contract:
///   1. On portrait phones (height-driven fit) every mapped value is
///      BIT-IDENTICAL to the legacy `fraction * screen.height` arithmetic —
///      the fix cannot regress a single phone.
///   2. On width-driven screens the mapping follows the cover crop.
///   3. The stageY clamps keep a usable, correctly-ordered layout on every
///      aspect, however extreme.
void main() {
  group('fittedRect — the cover fit of the 1440×2560 art', () {
    test('exact 9:16 screen: the art fills it exactly', () {
      final r = ParchmentArt.fittedRect(const Size(360, 640));
      expect(r, const Rect.fromLTWH(0, 0, 360, 640));
      final r2 = ParchmentArt.fittedRect(const Size(720, 1280));
      expect(r2, const Rect.fromLTWH(0, 0, 720, 1280));
    });

    test('taller-than-art phone (19.5:9): height-driven, sides cropped evenly',
        () {
      final r = ParchmentArt.fittedRect(const Size(390, 844));
      expect(r.top, 0);
      expect(r.height, 844);
      expect(r.width, closeTo(474.75, 1e-6));
      // Symmetric horizontal overflow.
      expect(r.left, closeTo(-42.375, 1e-6));
      expect(r.right, closeTo(390 + 42.375, 1e-6));
    });

    test('wider-than-art tablet (3:4): width-driven, top/bottom cropped evenly',
        () {
      final r = ParchmentArt.fittedRect(const Size(768, 1024));
      expect(r.left, 0);
      expect(r.width, 768);
      expect(r.height, closeTo(1365.3333, 1e-3));
      expect(r.top, closeTo(-170.6667, 1e-3));
    });
  });

  group('mapY / mapX — art fractions to screen pixels', () {
    test('mapY is vertical-identity on portrait phones (the guarantee)', () {
      for (final s in const [
        Size(360, 640),
        Size(390, 844),
        Size(412, 915), // 20:9
      ]) {
        for (final f in [0.0, 0.118, 0.21, 0.5, 0.798, 0.808, 1.0]) {
          expect(ParchmentArt.mapY(s, f), closeTo(f * s.height, 1e-9),
              reason: '${s.width}×${s.height} f=$f');
        }
      }
    });

    test('mapY follows the crop on a tablet', () {
      // 768×1024: fit.top = -170.667, fitted height = 1365.333.
      expect(ParchmentArt.mapY(const Size(768, 1024), 0.21),
          closeTo(-170.6667 + 0.21 * 1365.3333, 1e-3));
      expect(ParchmentArt.mapY(const Size(768, 1024), 0.798),
          closeTo(-170.6667 + 0.798 * 1365.3333, 1e-3));
    });

    test('mapX is horizontal-identity on width-driven screens', () {
      expect(ParchmentArt.mapX(const Size(768, 1024), 0.11),
          closeTo(0.11 * 768, 1e-9));
    });

    test('mapX accounts for the side crop on tall phones', () {
      // 390×844: fit.left = -42.375, fitted width = 474.75.
      expect(ParchmentArt.mapX(const Size(390, 844), 0.11),
          closeTo(-42.375 + 0.11 * 474.75, 1e-6));
    });
  });

  group('stageY — the player bands', () {
    test('REGRESSION PIN: portrait phones get the exact legacy placements',
        () {
      // Every clamp must be inert on phones: the values are exactly
      // fraction * height, i.e. what the screen shipped before P62.
      for (final s in const [Size(360, 640), Size(390, 844), Size(412, 915)]) {
        final headerBottom = s.height * 0.012 + 48 + 24;
        final st = ParchmentArt.stageY(s, headerBottom: headerBottom);
        expect(st.titleTop, closeTo(ParchmentArt.titleTop * s.height, 1e-9),
            reason: '${s.width}×${s.height}');
        expect(st.lyricsTop,
            closeTo((ParchmentArt.boxTop + ParchmentArt.boxInsetInner) * s.height, 1e-9));
        expect(
            st.lyricsBottom,
            closeTo(
                (ParchmentArt.boxBottom - ParchmentArt.boxInsetInner) *
                    s.height,
                1e-9));
        expect(st.playerTop,
            closeTo(ParchmentArt.playerTop * s.height, 1e-9));
      }
    });

    test('tablet: the stage tracks the painted regions instead of drifting',
        () {
      const s = Size(768, 1024);
      final st = ParchmentArt.stageY(s, headerBottom: 84.29);
      // Legacy lyricsTop on this screen: 0.232 * 1024 = 237.6, which sat
      // ~90px below the painted panel. The mapped value follows the art.
      expect(st.lyricsTop, closeTo(146.095, 0.01));
      // The transport is clamped to keep its console budget on-screen.
      expect(st.playerTop, s.height - 118);
      expect(st.lyricsBottom, lessThan(st.playerTop));
      // The lyrics stage starts INSIDE the painted bright panel, whose
      // top maps to mapY(0.198) ≈ 99.6 on this screen.
      expect(st.lyricsTop,
          greaterThan(ParchmentArt.mapY(s, 0.198)));
      expect(st.lyricsBottom,
          lessThan(ParchmentArt.mapY(s, 0.800)));
    });

    test('landscape phone: every band lands on-screen, in order', () {
      const s = Size(844, 390);
      final st = ParchmentArt.stageY(s, headerBottom: 76.68);
      expect(st.titleTop, 76.68); // mapped title was far off-screen above
      expect(st.lyricsTop, greaterThanOrEqualTo(76.68 + 8));
      expect(st.playerTop, s.height - 118);
      expect(st.lyricsBottom, lessThan(st.playerTop - 8));
      // A usable stage height on a 390px-tall screen.
      expect(st.lyricsBottom - st.lyricsTop, greaterThan(60));
    });

    test('clamps never invert the layout, on any aspect', () {
      // Sweep a wide grid of aspects, including degenerate slivers.
      for (var w = 240.0; w <= 1600; w += 68) {
        for (var h = 240.0; h <= 1600; h += 68) {
          final s = Size(w, h);
          final st =
              ParchmentArt.stageY(s, headerBottom: h * 0.012 + 72);
          expect(st.titleTop, greaterThanOrEqualTo(0));
          expect(st.lyricsTop, greaterThanOrEqualTo(st.titleTop));
          expect(st.lyricsBottom, greaterThan(st.lyricsTop));
          expect(st.playerTop, greaterThan(st.lyricsBottom));
          expect(st.playerTop, lessThanOrEqualTo(h));
          expect(st.lyricsBottom, lessThanOrEqualTo(h));
        }
      }
    });
  });
}
