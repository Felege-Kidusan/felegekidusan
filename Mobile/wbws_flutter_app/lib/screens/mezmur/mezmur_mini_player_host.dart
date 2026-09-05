import 'package:flutter/material.dart';

import '../../services/mezmur_audio_player.dart';
import 'mezmur_mini_player.dart';

/// Global host for the now-playing bar.
///
/// P34 — why this exists.
///
/// The mini player used to be a `Positioned` child of the app shell's
/// `Stack`. That has two defects the users reported:
///
///  1. It sat *inside* the shell, so every route pushed on the root
///     Navigator (Downloads, the full player, attendance) drew on top of
///     it and the bar vanished. A now-playing bar must follow the user
///     everywhere.
///  2. It was a pure overlay with no layout inset, so it floated over
///     whatever was at the bottom of the page — the attendance
///     Save/Submit bar and the "take attendance" / "Add" FABs — making
///     those controls unreachable.
///
/// The fix is the pattern accepted on SO 64644547 and used by Spotify,
/// YouTube Music and Apple Music: mount the bar ABOVE the Navigator, in
/// `MaterialApp.builder`, as a real row in a `Column`. Because it is a
/// sibling of the Navigator rather than an overlay:
///
///  * it survives every push/pop — it genuinely follows the user;
///  * it consumes real height, so the Navigator's viewport shrinks and
///    Scaffold bottom bars, FABs and `MediaQuery.viewInsets` all lay out
///    above it automatically. No page needs manual padding.
///
/// It animates its own height so appearing/disappearing reflows smoothly
/// instead of snapping.
class MezmurMiniPlayerHost extends StatelessWidget {
  const MezmurMiniPlayerHost({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final c = MezmurAudioPlayerController.instance;
    return Material(
      color: Theme.of(context).scaffoldBackgroundColor,
      child: Column(
        children: [
          Expanded(child: child),
          AnimatedBuilder(
            animation: c,
            builder: (context, _) {
              final show = c.showMiniPlayer && c.viewTrack != null;
              return AnimatedSize(
                duration: const Duration(milliseconds: 220),
                curve: Curves.easeOutCubic,
                alignment: Alignment.topCenter,
                child: show
                    ? SafeArea(
                        top: false,
                        child: Padding(
                          padding:
                              const EdgeInsets.fromLTRB(12, 0, 12, 8),
                          child: const MezmurMiniPlayer(),
                        ),
                      )
                    : const SizedBox(width: double.infinity, height: 0),
              );
            },
          ),
        ],
      ),
    );
  }
}
