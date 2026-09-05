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
///
/// A [LyricEmphasisProfile] picks the character of the falloff:
///   * [LyricEmphasisProfile.karaoke] — the default "deep water" reveal:
///     each further line is larger-scale stepped down (~5.5%) and fainter
///     (~15%). Dramatic and immersive, for sing-a-long listening.
///   * [LyricEmphasisProfile.reading] — "lyrics only": every line keeps full
///     size (no shrink), and off lines fade only slightly. Used when a user
///     just wants to READ the words at a comfortable size, or needs larger
///     text for accessibility. Nothing reflows, and no line becomes hard to
///     read.
library;

class LyricEmphasisProfile {
  final double scaleStep; // per-line scale reduction
  final double opacityStep; // per-line opacity reduction
  final double minScale; // furthest readable line's scale floor
  final double minOpacity; // furthest readable line's opacity floor

  const LyricEmphasisProfile({
    required this.scaleStep,
    required this.opacityStep,
    required this.minScale,
    required this.minOpacity,
  });

  /// Default sing-along emphasis (Spotify-like): the current line is bold,
  /// bright and full-size; the rest recede with distance. The size drop is
  /// clear (nearest neighbour ~14% smaller, settling ~82%) so the sung line
  /// always reads as the biggest, and the fade does the depth. Far lines stay
  /// readable (never below 42% ink) so surrounding lyrics remain legible.
  static const LyricEmphasisProfile karaoke = LyricEmphasisProfile(
    scaleStep: 0.14,
    opacityStep: 0.15,
    minScale: 0.82,
    minOpacity: 0.42,
  );

  /// "Lyrics only" reading mode — full size, gentle fade, no shrink.
  static const LyricEmphasisProfile reading = LyricEmphasisProfile(
    scaleStep: 0.0,
    opacityStep: 0.05,
    minScale: 1.0,
    minOpacity: 0.86,
  );
}

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

  static const LyricEmphasis active = LyricEmphasis(
    isActive: true,
    scale: 1.0,
    opacity: 1.0,
    distance: 0,
  );

  /// Emphasis for the line at [index] relative to the line at [active].
  ///
  /// [profile] selects the character of the falloff (see [LyricEmphasisProfile]).
  /// Out-of-range indexes are clamped so a seek cannot produce a negative
  /// distance or a crash. When [active] is -1 (nothing has been sung yet —
  /// playback precedes the first timestamp) there is deliberately no "active"
  /// line: line 0 sits at distance 1, giving a natural leading fade rather
  /// than prematurely spotlighting a line that has not started.
  static LyricEmphasis forIndex({
    required int index,
    required int active,
    LyricEmphasisProfile profile = LyricEmphasisProfile.karaoke,
  }) {
    if (index < 0) index = 0;
    final d = (index - active).abs();
    // NOTE: `active` here is the int parameter, which shadows the static
    // `LyricEmphasis.active` constant — always qualify the constant.
    if (d == 0 && active >= 0) return LyricEmphasis.active;
    // Upper bound is always 1.0 (>= the profile floor) so `clamp` can never be
    // called with lower > upper — which would throw. Only the active line has
    // scale/opacity 1.0; off lines are always smaller/fainter by some step, so
    // a 1.0 cap never accidentally promotes an off line (see the profile).
    final scale = (1.0 - d * profile.scaleStep).clamp(profile.minScale, 1.0).toDouble();
    final opacity = (1.0 - d * profile.opacityStep).clamp(profile.minOpacity, 1.0).toDouble();
    return LyricEmphasis(
      isActive: false,
      scale: scale,
      opacity: opacity,
      distance: d,
    );
  }
}
