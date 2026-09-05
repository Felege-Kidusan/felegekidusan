import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/mezmur_transport_gate.dart';

/// Regression tests for the "play/pause works only once" defect (P34).
void main() {
  group('generation counter — the play/pause-once bug', () {
    test('a command is current while no newer one has been issued', () {
      expect(
        MezmurTransportGate.isCurrent(gen: 7, commandVersion: 7),
        isTrue,
      );
    });

    test('a superseded command is no longer current', () {
      // User pressed play (gen 7) then pause (bumps to 8) while the
      // source was still loading. The play must abandon.
      expect(
        MezmurTransportGate.isCurrent(gen: 7, commandVersion: 8),
        isFalse,
      );
    });

    test('play may start when it is current and still wanted', () {
      expect(
        MezmurTransportGate.mayStart(
          gen: 3,
          commandVersion: 3,
          wantPlaying: true,
          canPlay: true,
        ),
        isTrue,
      );
    });

    test('a slow load must NOT start audio after the user paused', () {
      expect(
        MezmurTransportGate.mayStart(
          gen: 3,
          commandVersion: 4,
          wantPlaying: false,
          canPlay: true,
        ),
        isFalse,
      );
    });

    test('intent alone is not enough — the source must be playable', () {
      expect(
        MezmurTransportGate.mayStart(
          gen: 3,
          commandVersion: 3,
          wantPlaying: true,
          canPlay: false,
        ),
        isFalse,
      );
    });

    test('a repeated play is never blocked by a stale in-flight flag', () {
      // The heart of the bug: the SECOND and THIRD play must behave
      // exactly like the first. With a generation counter each new
      // command bumps the version and is current by construction.
      for (var gen = 1; gen <= 5; gen++) {
        expect(
          MezmurTransportGate.mayStart(
            gen: gen,
            commandVersion: gen,
            wantPlaying: true,
            canPlay: true,
          ),
          isTrue,
          reason: 'play #$gen must still be allowed to start',
        );
      }
    });
  });

  group('toggle derives intent from the engine', () {
    test('pauses when the engine is playing', () {
      expect(
        MezmurTransportGate.toggleShouldPause(enginePlaying: true),
        isTrue,
      );
    });

    test('plays when the engine is paused', () {
      expect(
        MezmurTransportGate.toggleShouldPause(enginePlaying: false),
        isFalse,
      );
    });

    test('alternates correctly over many cycles', () {
      var engine = false;
      for (var i = 0; i < 6; i++) {
        final shouldPause =
            MezmurTransportGate.toggleShouldPause(enginePlaying: engine);
        expect(shouldPause, engine);
        engine = !engine; // the engine reports the new state back
      }
    });
  });

  group('mini player visibility', () {
    bool show({
      bool playerVisible = false,
      bool dismissed = false,
      bool hasCatalog = true,
      bool playing = true,
      bool hasQueue = true,
      bool viewHasAudio = true,
    }) =>
        MezmurTransportGate.showMiniPlayer(
          playerVisible: playerVisible,
          dismissed: dismissed,
          hasCatalog: hasCatalog,
          playing: playing,
          hasQueue: hasQueue,
          viewHasAudio: viewHasAudio,
        );

    test('visible during a live session', () {
      expect(show(), isTrue);
    });

    test('hidden while the full player is on screen', () {
      expect(show(playerVisible: true), isFalse);
    });

    test('closing the bar hides it — the session was ended', () {
      expect(show(dismissed: true), isFalse);
    });

    test('nothing to show without a catalog', () {
      expect(show(hasCatalog: false), isFalse);
    });

    test('a paused but resumable session still shows the bar', () {
      expect(show(playing: false), isTrue);
    });

    test('hidden once the session has no audio left at all', () {
      expect(
        show(playing: false, hasQueue: false, viewHasAudio: false),
        isFalse,
      );
    });
  });
}
