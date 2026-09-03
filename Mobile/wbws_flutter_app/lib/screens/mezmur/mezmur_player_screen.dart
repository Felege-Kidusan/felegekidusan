import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/mezmur_audio_player.dart';
import 'mezmur_lyrics_screen.dart';
import 'parchment_style.dart';

/// P0 mezmur — Spotify-style full-screen player.
///
/// The user's parchment artwork is the backdrop for BOTH this screen and
/// the synced-lyrics screen. Text follows the artwork study: dark sepia ink
/// on the bright paper panel; cream glyphs on restrained dark-leather chips
/// wherever controls float over the painted gold frame / bottom ornament.
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
  double? _dragMs; // user is scrubbing

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

  void _goLyrics() {
    final track = _c.currentTrack;
    if (track == null) return;
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => MezmurLyricsScreen(track: track),
    ));
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

  @override
  Widget build(BuildContext context) {
    final overlay = SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      statusBarBrightness: Brightness.dark,
      systemNavigationBarColor: const Color(0xE62A150A),
      systemNavigationBarIconBrightness: Brightness.light,
    );

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: overlay,
      child: ParchmentScaffold(
        topWash: _EdgeWash(alignBottom: true),
        bottomWash: _EdgeWash(alignBottom: false),
        child: SafeArea(
          bottom: true,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _buildTopRow(context),
              Expanded(child: _buildStage(context)),
              _buildConsole(context),
            ],
          ),
        ),
      ),
    );
  }

  // ── top chrome ────────────────────────────────────────────

  Widget _buildTopRow(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(10, 6, 14, 0),
      child: Row(
        children: [
          _ChipIcon(
            icon: Icons.keyboard_arrow_down,
            tooltip: 'Close',
            onTap: () => Navigator.of(context).maybePop(),
          ),
          const Spacer(),
          if (_c.hasQueue)
            _ChipIcon(
              icon: Icons.lyrics_outlined,
              tooltip: 'Live lyrics',
              onTap: _goLyrics,
            ),
        ],
      ),
    );
  }

  // ── middle stage (on the bright parchment panel) ──────────

  Widget _buildStage(BuildContext context) {
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
      return Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 40),
          child: Text(
            'ይህ መዝሙር ገና ኦዲዮ የለውም — ከአስተዳዳሪው ጋር ተገናኝ።\n(This hymn has no playable audio yet.)',
            textAlign: TextAlign.center,
            style: const TextStyle(
                color: Parchment.inkFaint,
                fontSize: 13.5,
                height: 1.6),
          ),
        ),
      );
    }

    final n = _c.queue.length;
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        const SizedBox(height: 6),
        const _OrnamentRule(),
        const SizedBox(height: 18),
        Text(
          track.category?.isNotEmpty == true
              ? '${track.category}'
              : 'መዝሙር · FKSS',
          textAlign: TextAlign.center,
          style: const TextStyle(
            color: Parchment.inkFaint,
            fontSize: 11.5,
            fontWeight: FontWeight.w700,
            letterSpacing: 1.1,
          ),
        ),
        const SizedBox(height: 8),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 42),
          child: Text(
            track.title,
            textAlign: TextAlign.center,
            maxLines: 3,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: Parchment.inkStrong,
              fontSize: 21,
              fontWeight: FontWeight.w800,
              height: 1.35,
              fontFamily: 'NotoSansEthiopic',
            ),
          ),
        ),
        if (n > 1) ...[
          const SizedBox(height: 10),
          Text(
            '${(_c.index ?? 0) + 1} ከ $n · ${(_c.index ?? 0) + 1} of $n',
            style: const TextStyle(
                color: Parchment.inkFaint, fontSize: 12),
          ),
        ],
        const SizedBox(height: 22),
        const _OrnamentRule(),
        const SizedBox(height: 26),
        // Live-lyrics affordance on the paper panel.
        OutlinedButton.icon(
          onPressed: _goLyrics,
          style: OutlinedButton.styleFrom(
            foregroundColor: Parchment.ink,
            side: const BorderSide(color: Parchment.bronzeSoft, width: 1.1),
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(30),
            ),
            textStyle: const TextStyle(
                fontSize: 13, fontWeight: FontWeight.w700),
          ),
          icon: const Icon(Icons.lyrics_outlined,
              size: 18, color: Parchment.bronze),
          label: const Text('የግጥም ገጽ · Live lyrics'),
        ),
        const SizedBox(height: 8),
        const Text(
          'በጊዜ የሚከተሉ ግጥሞች · synced to the audio',
          style: TextStyle(color: Parchment.inkFaint, fontSize: 10.5),
        ),
      ],
    );
  }

  // ── bottom console ────────────────────────────────────────

  Widget _buildConsole(BuildContext context) {
    final totalMs = _totalMs;
    final posMs = _dragMs ?? _clampedPositionMs;
    final dur = Duration(milliseconds: totalMs);

    return Container(
      margin: const EdgeInsets.fromLTRB(14, 0, 14, 14),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
      decoration: BoxDecoration(
        color: Parchment.leather,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: Parchment.cream.withOpacity(0.10)),
        boxShadow: const [
          BoxShadow(
              color: Color(0x55201508),
              blurRadius: 14,
              offset: Offset(0, 6)),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              Text(Parchment.fmt(_c.position),
                  style: const TextStyle(
                      color: Parchment.cream, fontSize: 10.5)),
              Expanded(
                child: SliderTheme(
                  data: SliderTheme.of(context).copyWith(
                    trackHeight: 2.4,
                    activeTrackColor: Parchment.gold,
                    inactiveTrackColor: Parchment.cream.withOpacity(0.22),
                    thumbColor: Parchment.creamBright,
                    overlayColor: Parchment.gold.withOpacity(0.14),
                    thumbShape: const RoundSliderThumbShape(
                        enabledThumbRadius: 6),
                    overlayShape: const RoundSliderOverlayShape(
                        overlayRadius: 12),
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
                      color: Parchment.cream, fontSize: 10.5)),
            ],
          ),
          const SizedBox(height: 2),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: [
              IconButton(
                tooltip: 'Previous',
                iconSize: 30,
                color: Parchment.cream,
                disabledColor: Parchment.cream.withOpacity(0.28),
                icon: const Icon(Icons.skip_previous_rounded),
                onPressed: !_opening && _c.hasQueue ? _c.previous : null,
              ),
              _PlayButton(
                loading: _opening || _c.buffering,
                playing: _c.playing,
                onTap: _c.hasQueue ? _c.toggle : null,
              ),
              IconButton(
                tooltip: 'Next',
                iconSize: 30,
                color: Parchment.cream,
                disabledColor: Parchment.cream.withOpacity(0.28),
                icon: const Icon(Icons.skip_next_rounded),
                onPressed:
                    !_opening && _c.hasQueue ? _c.next : null,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

// ── private chrome pieces ──────────────────────────────────

class _EdgeWash extends StatelessWidget {
  /// true → gradient is darkest at the TOP of the widget (fades downward).
  final bool alignBottom;
  const _EdgeWash({required this.alignBottom});

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    final h = alignBottom ? (56.0 + top) : 64.0;
    return IgnorePointer(
      child: Container(
        height: h,
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: alignBottom
                ? Alignment.topCenter
                : Alignment.bottomCenter,
            end: alignBottom
                ? Alignment.bottomCenter
                : Alignment.topCenter,
            colors: [
              Parchment.leatherSoft,
              Parchment.leatherSoft.withOpacity(0.0),
            ],
          ),
        ),
      ),
    );
  }
}

class _ChipIcon extends StatelessWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;
  const _ChipIcon(
      {required this.icon, required this.tooltip, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Parchment.leatherSoft,
      shape: const CircleBorder(),
      child: IconButton(
        tooltip: tooltip,
        icon: Icon(icon, color: Parchment.cream),
        iconSize: 22,
        onPressed: onTap,
      ),
    );
  }
}

class _OrnamentRule extends StatelessWidget {
  const _OrnamentRule();

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        _line(),
        const SizedBox(width: 6),
        const Icon(Icons.diamond, size: 7, color: Parchment.gold),
        const SizedBox(width: 6),
        _line(),
      ],
    );
  }

  static Widget _line() => Container(
        width: 46,
        height: 1.2,
        color: Parchment.bronzeSoft.withOpacity(0.65),
      );
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
          width: 62,
          height: 62,
          child: loading
              ? const Padding(
                  padding: EdgeInsets.all(19),
                  child: CircularProgressIndicator(
                    strokeWidth: 2.2,
                    color: Color(0xFF5A3A12),
                  ),
                )
              : Icon(
                  playing ? Icons.pause_rounded : Icons.play_arrow_rounded,
                  color: const Color(0xFF4A2C0C),
                  size: 40,
                ),
        ),
      ),
    );
  }
}
