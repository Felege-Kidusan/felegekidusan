/// P0 mezmur — parchment design tokens.
///
/// Every value below was taken from a pixel-level study of the user's
/// artwork (1440×2560 blank scroll):
///   * Bright paper panel (luma ≈ 205–215) → dark sepia INK text, not black.
///   * Outer gold frame / ornament (luma ≈ 116–155) → CREAM glyphs on a
///     translucent dark-leather chip when controls must sit over it.
///   * Dark ornamental bands live only at the very top/bottom cylinders.
///   * The ornamental inner box is the lyrics stage; the transport lives
///     in the band between that box and the bottom cylinder.
library;

import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../services/mezmur_audio_player.dart';

class Parchment {
  // Ink family — text on the bright parchment panel (safe contrast).
  static const Color ink = Color(0xFF3B2417); // very dark sepia
  static const Color inkStrong = Color(0xFF2A150A); // headings
  static const Color inkFaint = Color(0xFF7A4E12); // bronze secondary

  // Metals / ornament.
  static const Color bronze = Color(0xFF8A5A1B);
  static const Color bronzeSoft = Color(0xFFA9783A);
  static const Color gold = Color(0xFFD4AF37);
  static const Color honey = Color(0xFFD8C078);

  // Cream that is safe to draw as TEXT over dark ornament regions.
  static const Color cream = Color(0xFFF1DFAE);
  static const Color creamBright = Color(0xFFF8EBCB);

  // Translucent dark-leather chip: control clusters that float over the
  // gold frame / bottom ornament sit on this (≈ rgba(62,35,12,0.72)).
  static const Color leather = Color(0xB83E230C);
  static const Color leatherSoft = Color(0x993E230C);

  static const TextStyle inkStyle = TextStyle(
    color: ink,
    fontFamily: 'NotoSansEthiopic',
  );

  /// Formats a duration like a streaming app: `m:ss`.
  static String fmt(Duration d) {
    final total = d.inSeconds < 0 ? 0 : d.inSeconds;
    final m = total ~/ 60;
    final s = total % 60;
    return '$m:${s.toString().padLeft(2, '0')}';
  }
}

/// Fractional layout of the 1440×2560 blank scroll, so overlays stay
/// inside the painted regions when the image is `BoxFit.cover` on any
/// screen aspect.
///
/// The fractions below are measured in ART SPACE (they were derived from
/// a pixel-level study of the artwork: the bright lyrics panel spans
/// rows 0.198–0.800 of the art, the title strip 0.151–0.192, the
/// transport band begins ~0.808). Mapping them onto a screen is done by
/// [fittedRect]/[mapY]/[stageY] — NOT by multiplying the screen's own
/// dimensions, which silently assumed the screen shares the art's 9:16
/// aspect.
///
/// Why this is safe: `BoxFit.cover` on any PORTRAIT phone (taller than
/// 9:16) is height-driven — the art fills the height exactly, so art-Y
/// fractions equal screen-Y fractions and every mapped value is
/// bit-identical to the legacy `h * fraction` arithmetic. The mapping
/// only changes WIDTH-driven screens (tablets, landscape, desktop
/// windows) — precisely the class of devices where the old screen-space
/// fractions drifted off the painted regions.
class ParchmentArt {
  static const double titleTop = 0.118;
  static const double boxTop = 0.210;
  static const double boxBottom = 0.798;
  // P53: the lyric stage was pinned far narrower than the painted frame
  // (0.168), so even single-line hymns wrapped onto two rows. 0.11 lines the
  // text up with the frame's inner writing area — much more room per line.
  static const double boxInsetX = 0.11;
  static const double boxInsetInner = 0.022;
  static const double playerTop = 0.808;

  /// The source artwork's pixel size (a 9:16 blank scroll).
  static const Size artSize = Size(1440, 2560);

  /// The rect the artwork occupies on [screen] under `BoxFit.cover` —
  /// the same fit [ParchmentScaffold] paints the backdrop with. Pure
  /// geometry, unit-tested.
  static Rect fittedRect(Size screen) {
    final scale = math.max(
        screen.width / artSize.width, screen.height / artSize.height);
    final w = artSize.width * scale;
    final h = artSize.height * scale;
    return Rect.fromLTWH(
        (screen.width - w) / 2, (screen.height - h) / 2, w, h);
  }

  /// Maps an art-space vertical fraction to screen pixels.
  ///
  /// On height-driven screens (portrait phones) this is exactly
  /// `fraction * screen.height`; on width-driven screens (tablets,
  /// landscape) it correctly follows the cover crop instead of assuming
  /// the art fills the height.
  static double mapY(Size screen, double fraction) =>
      fittedRect(screen).top + fraction * fittedRect(screen).height;

  /// Maps an art-space horizontal fraction to screen pixels.
  ///
  /// Width-driven screens are horizontal-identity; taller-than-art
  /// screens crop the art's outer columns, which this accounts for.
  /// (The player keeps its horizontal INSETS screen-relative today —
  /// see stageY — because a fixed screen inset is always conservative
  /// there: the painted frame only ever moves inward under crop.)
  static double mapX(Size screen, double fraction) =>
      fittedRect(screen).left + fraction * fittedRect(screen).width;

  /// Vertical placements for the player's fixed bands (title, lyrics
  /// stage, transport), mapped from art space and clamped so extreme
  /// aspects keep a usable stage.
  ///
  /// [headerBottom] is where the floating header chips end (the title
  /// must never start above them once a clamp fires).
  /// [consoleReserve] is the vertical budget the transport panel needs
  /// (its content is a fixed 360-wide design, so a constant — not a
  /// screen fraction — is the honest reserve).
  ///
  /// On portrait phones every clamp is inert and the returned values are
  /// exactly the legacy `h * fraction` placements (pinned by test); the
  /// clamps only bite on width-driven aspects, where the mapped bands
  /// would otherwise sit off-screen or overlap.
  static ({double titleTop, double lyricsTop, double lyricsBottom, double playerTop})
      stageY(
    Size screen, {
    required double headerBottom,
    double consoleReserve = 118,
  }) {
    var title = mapY(screen, titleTop);
    var lyricsTop = mapY(screen, boxTop + boxInsetInner);
    var lyricsBottom = mapY(screen, boxBottom - boxInsetInner);
    var player = mapY(screen, playerTop);

    // Clamps — width-driven aspects only (see class doc). Each is written
    // so it can never fire on a portrait phone.
    if (title < 0) title = headerBottom;
    if (lyricsTop < headerBottom + 8) lyricsTop = headerBottom + 8;
    final maxPlayer = screen.height - consoleReserve;
    if (player > maxPlayer) player = maxPlayer;
    if (lyricsBottom > player - 10) lyricsBottom = player - 10;
    // Degenerate tiny windows: keep a minimum stage rather than a
    // negative-height box.
    if (lyricsBottom < lyricsTop + 60) {
      lyricsBottom = math.min(lyricsTop + 60, screen.height - consoleReserve - 10);
    }
    return (
      titleTop: title,
      lyricsTop: lyricsTop,
      lyricsBottom: lyricsBottom,
      playerTop: player,
    );
  }
}

/// Full-bleed parchment backdrop. Child content is laid on top; the top and
/// bottom edges get restrained translucent sepia washes so cream status-bar
/// icons and any pinned header/footer stay legible over the painted
/// ornament (the dark bands live only at the very top/bottom of the art).
class ParchmentScaffold extends StatelessWidget {
  final Widget child;
  final Widget? topWash;
  final Widget? bottomWash;

  const ParchmentScaffold({
    super.key,
    required this.child,
    this.topWash,
    this.bottomWash,
  });

  @override
  Widget build(BuildContext context) {
    final t = topWash;
    final b = bottomWash;
    return Scaffold(
      backgroundColor: Parchment.ink,
      body: Stack(
        fit: StackFit.expand,
        children: [
          Image.asset(
            MezmurAudioPlayerController.bgAsset,
            fit: BoxFit.cover,
            gaplessPlayback: true,
          ),
          // Restrained washes at the extreme edges only — never over the
          // bright writing panel in the middle.
          if (t != null)
            Positioned(top: 0, left: 0, right: 0, child: t),
          if (b != null)
            Positioned(bottom: 0, left: 0, right: 0, child: b),
          child,
        ],
      ),
    );
  }
}

/// Soft in/out points at both edges of the lyrics stage so lines ease
/// in from the top and out through the bottom (and the reverse) instead
/// of clipping hard against the ornamental frame.
class ParchmentFade extends StatelessWidget {
  final Widget child;
  final double stop;

  const ParchmentFade({super.key, required this.child, this.stop = 0.14});

  @override
  Widget build(BuildContext context) {
    return ShaderMask(
      blendMode: BlendMode.dstIn,
      shaderCallback: (rect) {
        return LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: const [
            Color(0x00FFFFFF),
            Color(0xFFFFFFFF),
            Color(0xFFFFFFFF),
            Color(0x00FFFFFF),
          ],
          stops: [0.0, stop, 1.0 - stop, 1.0],
        ).createShader(rect);
      },
      child: child,
    );
  }
}
