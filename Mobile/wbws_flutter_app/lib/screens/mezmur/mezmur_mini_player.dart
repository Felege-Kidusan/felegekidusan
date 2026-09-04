import 'package:flutter/material.dart';

import '../../services/mezmur_audio_player.dart';
import 'mezmur_player_screen.dart';
import 'parchment_style.dart';

/// Compact now-playing bar (the pattern used by music apps when the
/// full player is dismissed while a session is live). Lives in the
/// app shell so it follows the user to whatever screen they opened.
/// Tap restores the parchment player; play/pause never leaves the
/// page they are on. Solid fill (no BackdropFilter) so low-end
/// phones stay smooth.
class MezmurMiniPlayer extends StatelessWidget {
  const MezmurMiniPlayer({super.key});

  @override
  Widget build(BuildContext context) {
    final c = MezmurAudioPlayerController.instance;
    return AnimatedBuilder(
      animation: c,
      builder: (context, _) {
        if (!c.showMiniPlayer) return const SizedBox.shrink();
        final track = c.viewTrack;
        if (track == null) return const SizedBox.shrink();
        return Material(
          color: const Color(0xF2F3E4C4),
          elevation: 6,
          shadowColor: const Color(0x66000000),
          borderRadius: BorderRadius.circular(16),
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: () => MezmurPlayerScreen.openSession(context),
            child: SizedBox(
              height: 56,
              child: Row(
                children: [
                  const SizedBox(width: 12),
                  const Icon(Icons.music_note_rounded,
                      color: Parchment.bronze, size: 22),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      track.title.isEmpty ? 'መዝሙር' : track.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontFamily: 'NotoSansEthiopic',
                        fontWeight: FontWeight.w800,
                        fontSize: 13.5,
                        color: Parchment.inkStrong,
                      ),
                    ),
                  ),
                  IconButton(
                    tooltip: c.playing ? 'Pause' : 'Play',
                    icon: Icon(
                      c.playing
                          ? Icons.pause_rounded
                          : Icons.play_arrow_rounded,
                      color: Parchment.inkStrong,
                    ),
                    onPressed: c.viewHasAudio ? c.toggle : null,
                  ),
                  IconButton(
                    tooltip: 'Hide',
                    icon: const Icon(Icons.close_rounded,
                        color: Parchment.inkFaint, size: 20),
                    onPressed: () {
                      c.pause();
                      c.dismissMiniPlayer();
                    },
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}
