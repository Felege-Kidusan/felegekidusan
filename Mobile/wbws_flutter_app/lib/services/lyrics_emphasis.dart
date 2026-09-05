/// Pure, UI-free rules for how strongly a lyric line is emphasised relative
/// to the active (currently-sung) line.
///
/// This is deliberately free of Flutter so the visual policy is unit-testable
/// in isolation and can be reused by any future surface (web, widget, an
/// immersive full-screen view) without dragging UI in. The screen owns only
/// the mapping from these scalars to the parchment palette.
///
/// Model — Apple Music's "float on the surface vs. loom in deep water":
///   * the active line is at natural size and full strength;
///   * each further line is progressively smaller and fainter;
///   * all of this is a pure function of the index distance, so it is
///     trivially animatable and never depends on layout.
///
/// Layout is deliberately NOT touched here: emphasis is expressed with
/// `scale`, `opacity` and a flag, never with font size or weight. Changing
/// those would re-wrap a long Amharic line and push its words onto a second
/// row (the exact bug this replaces).
library;

class LyricEmphasis {
  final bool isActive;
  final double scale; // 1.0 = natural size; <1 recedes into the distance
  final double opacity; // 1.0 = full ink; lower sinks into the parchment
  final int distance; // how many lines away from the active line

  const LyricEmphasis({
    required this.isActive,
    required this.scale,
    required this.opacity,
    required this.distance,
  });

  // Falloff constants (tuned, not magic): ~5.5% smaller and ~15% fainter per
  // line of distance, clamped so the furthest readable line never vanishes.
  static const double _scaleStep = 0.055;
  static const double _opacityStep = 0.15;

  static const LyricEmphasis active = LyricEmphasis(
    isActive: true,
    scale: 1.0,
    opacity: 1.0,
    distance: 0,
  );

  /// Emphasis for the line at [index] relative to the line at [active].
  ///
  /// Out-of-range indexes are clamped so a seek cannot produce a negative
  /// distance or a crash. When [active] is -1 (nothing has been sung yet —
  /// playback precedes the first timestamp) there is deliberately no "active"
  /// line: line 0 sits at distance 1, giving a natural leading fade rather
  /// than prematurely spotlighting a line that has not started.
  static LyricEmphasis forIndex({required int index, required int active}) {
    if (index < 0) index = 0;
    final d = (index - active).abs();
    if (d == 0 && active >= 0) return active;
    final scale = (1.0 - d * _scaleStep).clamp(0.80, 0.95).toDouble();
    final opacity = (1.0 - d * _opacityStep).clamp(0.30, 0.90).toDouble();
    return LyricEmphasis(
      isActive: false,
      scale: scale,
      opacity: opacity,
      distance: d,
    );
  }
}
