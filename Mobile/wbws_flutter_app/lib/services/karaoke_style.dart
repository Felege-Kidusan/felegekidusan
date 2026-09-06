/// Karaoke V2 (P64) — the visual falloff model, pure Dart.
///
/// How strongly a lyric line is emphasised relative to the active line:
/// opacity, scale and blur sigma as pure functions of (distance, past).
/// No Flutter here, so the visual contract is unit-testable and can be
/// reused by any future surface — the renderer only maps these scalars
/// to widgets.
///
/// Design (Spotify/Apple-grade, adapted to parchment — see
/// docs/mezmur_player/KARAOKE_V2_SPEC.md):
///   * depth comes from OPACITY + BLUR, not size — scale steps are
///     deliberately subtle (3%/line, floor 0.88) where the old design
///     shrank 8%/line;
///   * the falloff is ASYMMETRIC: past lines recede harder than future
///     lines (what's coming is more useful than what's gone);
///   * blur starts one line away and caps at a gentle 1.4σ;
///   * every curve is continuous at distance 0 and agrees between past
///     and future there, so the instant a line's past/future flag flips
///     (the moment the active line moves past it) there is no pop — the
///     single distance tween carries the motion smoothly from either
///     branch.
library;

/// Emphasis values for one line. All paint-time friendly: opacity and
/// scale never affect layout of other lines, and sigma quantises safely.
class KaraokeEmphasis {
  /// 1.0 = full ink; lower sinks into the parchment.
  final double opacity;

  /// 1.0 = natural size; <1 recedes slightly (paint transform only).
  final double scale;

  /// Gaussian blur sigma in logical pixels; 0 = sharp.
  final double sigma;

  const KaraokeEmphasis({
    required this.opacity,
    required this.scale,
    required this.sigma,
  });
}

class KaraokeProfile {
  /// Opacity lost per line of distance for FUTURE (upcoming) lines.
  final double futureOpacityStep;

  /// Opacity lost per line of distance for PAST (already sung) lines.
  final double pastOpacityStep;

  final double futureOpacityFloor;
  final double pastOpacityFloor;

  /// Scale lost per line of distance (symmetric).
  final double scaleStep;
  final double scaleFloor;

  /// Blur sigma per line of distance beyond the first neighbour.
  final double blurStep;
  final double maxSigma;

  const KaraokeProfile({
    required this.futureOpacityStep,
    required this.pastOpacityStep,
    required this.futureOpacityFloor,
    required this.pastOpacityFloor,
    required this.scaleStep,
    required this.scaleFloor,
    required this.blurStep,
    required this.maxSigma,
  });

  /// The Spotify-adapted default: subtle scale, asymmetric depth, gentle
  /// blur starting one line away.
  ///
  ///   d:      0     1     2     3    ≥4
  ///   future  1.00  0.90  0.80  0.70  0.62 floor
  ///   past    1.00  0.85  0.70  0.55  0.44 floor
  ///   scale   1.00  0.97  0.94  0.91  0.88 floor
  ///   σ       0     0     0.50  1.00  1.40 cap
  static const KaraokeProfile spotify = KaraokeProfile(
    futureOpacityStep: 0.10,
    pastOpacityStep: 0.15,
    futureOpacityFloor: 0.62,
    pastOpacityFloor: 0.44,
    scaleStep: 0.03,
    scaleFloor: 0.88,
    blurStep: 0.50,
    maxSigma: 1.40,
  );

  /// "Lyrics only": steady, full-size, readable — no depth games. The
  /// reading-mode contract (less motion, easy reading) is unchanged from
  /// the previous design.
  static const KaraokeProfile reading = KaraokeProfile(
    futureOpacityStep: 0.05,
    pastOpacityStep: 0.05,
    futureOpacityFloor: 0.86,
    pastOpacityFloor: 0.86,
    scaleStep: 0.0,
    scaleFloor: 1.0,
    blurStep: 0.0,
    maxSigma: 0.0,
  );

  /// High contrast: no blur ever, and both branches stay clearly legible.
  static const KaraokeProfile highContrast = KaraokeProfile(
    futureOpacityStep: 0.08,
    pastOpacityStep: 0.10,
    futureOpacityFloor: 0.85,
    pastOpacityFloor: 0.75,
    scaleStep: 0.03,
    scaleFloor: 0.90,
    blurStep: 0.0,
    maxSigma: 0.0,
  );

  /// Emphasis for a line at [distance] (may be fractional — the renderer
  /// tweens it) from the active line, on the [past] branch once the
  /// active line has moved beyond it.
  ///
  /// Continuous at 0 for both branches (both formulas are exactly 1.0
  /// there), so the past/future flag can flip mid-tween without a jump.
  KaraokeEmphasis forDistance(double distance, {required bool past}) {
    final d = distance < 0 ? 0.0 : distance;
    final opacity = past
        ? (1.0 - d * pastOpacityStep).clamp(pastOpacityFloor, 1.0)
        : (1.0 - d * futureOpacityStep).clamp(futureOpacityFloor, 1.0);
    final scale = (1.0 - d * scaleStep).clamp(scaleFloor, 1.0);
    final sigma =
        blurStep <= 0 ? 0.0 : (blurStep * (d - 1.0)).clamp(0.0, maxSigma);
    return KaraokeEmphasis(
        opacity: opacity, scale: scale, sigma: sigma);
  }
}
