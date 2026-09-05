/// Pure transport-intent logic for the Mezmur player.
///
/// P34 — extracted so the play/pause state machine is unit-testable
/// without an audio engine. The bug this guards against:
///
/// just_audio's `play()` Future does not complete when playback starts —
/// it completes when the track ENDS (or is paused/stopped). The old
/// controller awaited it inside a `try/finally` that cleared a
/// `_controlInFlight` boolean, so the flag stayed latched for the whole
/// song and every later play/pause/toggle hit a silent early-return.
/// Symptoms: play/pause worked exactly once, and the mini-player's close
/// button could not stop audio.
///
/// The rules below encode the replacement contract: intent is a
/// monotonic generation counter, and a command may only touch the engine
/// if it is still the newest one.
class MezmurTransportGate {
  const MezmurTransportGate._();

  /// True when a command tagged [gen] is still the most recent intent.
  ///
  /// A slow source load must never start audio after the user has since
  /// pressed pause or closed the player.
  static bool isCurrent({required int gen, required int commandVersion}) =>
      gen == commandVersion;

  /// Whether a play command that has finished loading may actually start
  /// the engine. Requires both that it is still current and that the
  /// standing intent is still "playing".
  static bool mayStart({
    required int gen,
    required int commandVersion,
    required bool wantPlaying,
    required bool canPlay,
  }) =>
      canPlay && wantPlaying && isCurrent(gen: gen, commandVersion: commandVersion);

  /// What a toggle should do, derived from the ENGINE's own state rather
  /// than a hand-maintained boolean pair (the AudioKit#2646 defect).
  /// Returns true to pause, false to play.
  static bool toggleShouldPause({required bool enginePlaying}) => enginePlaying;

  /// Whether the now-playing bar should be on screen.
  ///
  /// Closing the bar ends the session outright (YouTube Music's "dismiss
  /// queue" semantics), so a dismissed session never shows again until a
  /// new one is opened.
  static bool showMiniPlayer({
    required bool playerVisible,
    required bool dismissed,
    required bool hasCatalog,
    required bool playing,
    required bool hasQueue,
    required bool viewHasAudio,
  }) =>
      !playerVisible &&
      !dismissed &&
      hasCatalog &&
      (playing || hasQueue || viewHasAudio);
}
