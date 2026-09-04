import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/mezmur_audio_player.dart';
import 'mezmur_lyrics_screen.dart';
import 'parchment_style.dart';

/// Full-screen now-playing: parchment artwork is the backdrop, lyrics
/// live inside the painted ornamental box, and the transport sits in
/// the band between that box and the bottom cylinder.
class MezmurPlayerScreen extends StatefulWidget {
  /// Hymns available in the current view (queue order = list order).
  final List<MezmurTrack> queue;

  /// Which row of [queue] to start at.
  final int initialIndex;

  const MezmurPlayerScreen({
    super.key,
    required this.queue,
    this.initialIndex = 0,
  }) : assert(queue.length > initialIndex && initialIndex >= 0);

  /// Pushes the player for one hymn (the common "hymn detail" entry).
  static Future<void> open(
    BuildContext context,
    MezmurTrack track,
  ) {
    return Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => MezmurPlayerScreen(
        queue: [track],
        initialIndex: 0,
      ),
    ));
  }

  @override
  State<MezmurPlayerScreen> createState() => _MezmurPlayerScreenState();
}

class _MezmurPlayerScreenState extends State<MezmurPlayerScreen> {
  final MezmurAudioPlayerController _c =
      MezmurAudioPlayerController.instance;
  bool _opening = true;
  bool _failed = false;
  double? _dragMs;

  String get _sessionKey =>
      widget.queue.map((t) => 'mz-${t.hymnId}').join(',');

  @override
  void initState() {
    super.initState();
    _c.addListener(_onController);
    _ensureSession();
  }

  @override
  void dispose() {
    _c.removeListener(_onController);
    super.dispose();
  }

  void _onController() {
    if (mounted) setState(() {});
  }

  /// Reuses the running session when the SAME queue is already loaded so a
  /// re-entry does not restart the hymn; otherwise loads + starts the new
  /// queue (which is exactly what a tap on another hymn should do).
  Future<void> _ensureSession() async {
    final already = _c.hasQueue && _c.sessionKey == _sessionKey;
    var ok = already;
    if (!already) {
      ok = await _c.openQueue(widget.queue,
          startIndex: widget.initialIndex, autoPlay: true);
    }
    if (!mounted) return;
    setState(() {
      _opening = false;
      _failed = !ok;
    });
  }

  int get _totalMs {
    final d = _c.duration;
    if (d != null && d.inMilliseconds > 0) return d.inMilliseconds;
    final secs = _c.currentTrack?.durationSeconds ?? 0;
    return secs * 1000;
  }

  double get _clampedPositionMs {
    final total = _totalMs;
    final p = _c.position.inMilliseconds;
    return (p < 0 ? 0 : (total > 0 && p > total ? total : p)).toDouble();
  }

  void _showQueue() {
    final q = _c.queue;
    if (q.isEmpty) return;
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: const Color(0xFFF3E4C4),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: ListView.builder(
            padding: const EdgeInsets.fromLTRB(8, 12, 8, 24),
            itemCount: q.length,
            itemBuilder: (_, i) {
              final t = q[i];
              final active = _c.index == i;
              return ListTile(
                leading: Text('${i + 1}',
                    style: TextStyle(
                        color: active ? Parchment.bronze : Parchment.inkFaint,
                        fontWeight: FontWeight.w700)),
                title: Text(t.title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontFamily: 'NotoSansEthiopic',
                      fontWeight:
                          active ? FontWeight.w800 : FontWeight.w500,
                      color: Parchment.inkStrong,
                    )),
                trailing: active
                    ? const Icon(Icons.volume_up_rounded,
                        color: Parchment.bronze, size: 18)
                    : null,
                onTap: () {
                  Navigator.pop(ctx);
                  _c.openQueue(q, startIndex: i, autoPlay: true);
                },
              );
            },
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final overlay = SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.dark,
      statusBarBrightness: Brightness.light,
      systemNavigationBarColor: const Color(0xE62A150A),
      systemNavigationBarIconBrightness: Brightness.light,
    );

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: overlay,
      child: ParchmentScaffold(
        child: LayoutBuilder(builder: (context, box) {
          final h = box.maxHeight;
          final w = box.maxWidth;
          final pad = MediaQuery.paddingOf(context);
          return Stack(
            fit: StackFit.expand,
            children: [
              Positioned(
                top: pad.top + h * 0.012,
                left: w * 0.055,
                right: w * 0.055,
                child: _buildHeader(context),
              ),
              Positioned(
                top: h * ParchmentArt.titleTop,
                left: w * 0.14,
                right: w * 0.14,
                height: h * 0.086,
                child: _buildTitle(),
              ),
              Positioned(
                top: h * (ParchmentArt.boxTop + ParchmentArt.boxInsetInner),
                bottom: h * (1.0 - ParchmentArt.boxBottom +
                    ParchmentArt.boxInsetInner),
                left: w * ParchmentArt.boxInsetX,
                right: w * ParchmentArt.boxInsetX,
                child: _buildLyricsBox(),
              ),
              Positioned(
                top: h * ParchmentArt.playerTop,
                left: w * 0.05,
                right: w * 0.05,
                bottom: pad.bottom + h * 0.012,
                child: _buildConsole(context),
              ),
            ],
          );
        }),
      ),
    );
  }

  Widget _buildHeader(BuildContext context) {
    return Row(
      children: [
        _InkIcon(
          icon: Icons.arrow_back_rounded,
          tooltip: 'Close',
          onTap: () => Navigator.of(context).maybePop(),
        ),
        const Expanded(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('☩',
                  style: TextStyle(color: Parchment.bronze, fontSize: 18)),
              Text(
                'መዝሙር',
                style: TextStyle(
                  color: Parchment.bronze,
                  fontSize: 12.5,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 1.4,
                  fontFamily: 'NotoSansEthiopic',
                ),
              ),
            ],
          ),
        ),
        _InkIcon(
          icon: Icons.more_horiz_rounded,
          tooltip: 'Queue',
          onTap: _showQueue,
        ),
      ],
    );
  }

  Widget _buildTitle() {
    final track = _c.currentTrack;
    final n = _c.queue.length;
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text(
          track?.title ?? (_opening ? '…' : 'መዝሙር'),
          textAlign: TextAlign.center,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(
            color: Parchment.inkStrong,
            fontSize: 20,
            fontWeight: FontWeight.w800,
            height: 1.25,
            fontFamily: 'NotoSansEthiopic',
          ),
        ),
        const SizedBox(height: 4),
        Text(
          [
            if (track?.category?.isNotEmpty == true) track!.category,
            if (n > 1) '${(_c.index ?? 0) + 1} / $n',
          ].whereType<String>().join(' · '),
          textAlign: TextAlign.center,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(
            color: Parchment.inkFaint,
            fontSize: 11.5,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }

  Widget _buildLyricsBox() {
    if (_opening) {
      return const Center(
        child: SizedBox(
          width: 26,
          height: 26,
          child: CircularProgressIndicator(
            strokeWidth: 2.4,
            valueColor: AlwaysStoppedAnimation(Parchment.bronze),
          ),
        ),
      );
    }
    final track = _c.currentTrack;
    if (_failed || track == null) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.symmetric(horizontal: 12),
          child: Text(
            'ይህ መዝሙር ገና ኦዲዮ የለውም — ከአስተዳዳሪው ጋር ተገናኝ።\n(This hymn has no playable audio yet.)',
            textAlign: TextAlign.center,
            style: TextStyle(
                color: Parchment.inkFaint, fontSize: 13.5, height: 1.6),
          ),
        ),
      );
    }
    return MezmurLyricsScreen(key: ValueKey(track.hymnId), track: track);
  }

  Widget _buildConsole(BuildContext context) {
    final totalMs = _totalMs;
    final posMs = _dragMs ?? _clampedPositionMs;
    final dur = Duration(milliseconds: totalMs);
    final loop = _c.loopMode;
    final shuffleOn = _c.shuffle;

    return FittedBox(
      fit: BoxFit.scaleDown,
      child: SizedBox(
        width: 360,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Text(Parchment.fmt(_c.position),
                    style: const TextStyle(
                        color: Parchment.inkFaint, fontSize: 11)),
                Expanded(
                  child: SliderTheme(
                    data: SliderTheme.of(context).copyWith(
                      trackHeight: 3,
                      activeTrackColor: Parchment.bronze,
                      inactiveTrackColor: Parchment.bronzeSoft.withOpacity(0.28),
                      thumbColor: Parchment.inkStrong,
                      overlayColor: Parchment.gold.withOpacity(0.16),
                      thumbShape:
                          const RoundSliderThumbShape(enabledThumbRadius: 6),
                      overlayShape:
                          const RoundSliderOverlayShape(overlayRadius: 12),
                    ),
                    child: Slider(
                      min: 0,
                      max: totalMs > 0 ? totalMs.toDouble() : 1,
                      value: posMs
                          .clamp(0, totalMs > 0 ? totalMs.toDouble() : 1)
                          .toDouble(),
                      onChangeStart: (_) => setState(() {
                        _dragMs = posMs;
                      }),
                      onChanged: (v) => setState(() => _dragMs = v),
                      onChangeEnd: (v) {
                        _c.seek(Duration(milliseconds: v.round()));
                        setState(() => _dragMs = null);
                      },
                    ),
                  ),
                ),
                Text(Parchment.fmt(dur),
                    style: const TextStyle(
                        color: Parchment.inkFaint, fontSize: 11)),
              ],
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                _CtrlIcon(
                  icon: Icons.shuffle_rounded,
                  tooltip: 'Shuffle',
                  active: shuffleOn,
                  onTap: _c.hasQueue ? _c.toggleShuffle : null,
                ),
                _CtrlIcon(
                  icon: Icons.rotate_left_rounded,
                  tooltip: 'Back 15 seconds',
                  onTap: _c.hasQueue
                      ? () => _c.seekBy(const Duration(seconds: -15))
                      : null,
                ),
                _CtrlIcon(
                  icon: Icons.skip_previous_rounded,
                  tooltip: 'Previous',
                  size: 34,
                  onTap: !_opening && _c.hasQueue ? _c.previous : null,
                ),
                _PlayButton(
                  loading: _opening || _c.buffering,
                  playing: _c.playing,
                  onTap: _c.hasQueue ? _c.toggle : null,
                ),
                _CtrlIcon(
                  icon: Icons.skip_next_rounded,
                  tooltip: 'Next',
                  size: 34,
                  onTap: !_opening && _c.hasQueue ? _c.next : null,
                ),
                _CtrlIcon(
                  icon: Icons.rotate_right_rounded,
                  tooltip: 'Forward 15 seconds',
                  onTap: _c.hasQueue
                      ? () => _c.seekBy(const Duration(seconds: 15))
                      : null,
                ),
                _CtrlIcon(
                  icon: loop == 2
                      ? Icons.repeat_one_rounded
                      : Icons.repeat_rounded,
                  tooltip: loop == 2
                      ? 'Repeat one'
                      : loop == 1
                          ? 'Repeat all'
                          : 'Repeat off',
                  active: loop > 0,
                  onTap: _c.hasQueue ? _c.cycleLoop : null,
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _InkIcon extends StatelessWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;
  const _InkIcon(
      {required this.icon, required this.tooltip, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: tooltip,
      icon: Icon(icon, color: Parchment.bronze),
      iconSize: 22,
      onPressed: onTap,
    );
  }
}

class _CtrlIcon extends StatelessWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback? onTap;
  final bool active;
  final double size;
  const _CtrlIcon({
    required this.icon,
    required this.tooltip,
    this.onTap,
    this.active = false,
    this.size = 24,
  });

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: tooltip,
      iconSize: size,
      color: active ? Parchment.bronze : Parchment.ink,
      disabledColor: Parchment.inkFaint.withOpacity(0.35),
      icon: Icon(icon),
      onPressed: onTap,
    );
  }
}

class _PlayButton extends StatelessWidget {
  final bool loading;
  final bool playing;
  final VoidCallback? onTap;

  const _PlayButton(
      {required this.loading, required this.playing, this.onTap});

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    return Material(
      color: Parchment.gold,
      shape: const CircleBorder(),
      elevation: 2,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: enabled ? onTap : null,
        child: SizedBox(
          width: 58,
          height: 58,
          child: loading
              ? const Padding(
                  padding: EdgeInsets.all(17),
                  child: CircularProgressIndicator(
                    strokeWidth: 2.2,
                    color: Color(0xFF5A3A12),
                  ),
                )
              : Icon(
                  playing ? Icons.pause_rounded : Icons.play_arrow_rounded,
                  color: const Color(0xFF4A2C0C),
                  size: 36,
                ),
        ),
      ),
    );
  }
}
