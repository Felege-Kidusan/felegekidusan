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
/// inside the painted regions when the image is `BoxFit.cover` on a
/// 9:16 phone (the same aspect as the art).
class ParchmentArt {
  static const double titleTop = 0.118;
  static const double boxTop = 0.210;
  static const double boxBottom = 0.798;
  static const double boxInsetX = 0.168;
  static const double boxInsetInner = 0.022;
  static const double playerTop = 0.808;
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
