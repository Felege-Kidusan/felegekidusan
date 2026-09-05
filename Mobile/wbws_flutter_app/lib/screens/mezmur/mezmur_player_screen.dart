import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/app_navigator.dart';
import '../../services/mezmur_audio_player.dart';
import '../../services/mezmur_download_manager.dart';
import '../../services/lyrics_reader_settings.dart';
import 'mezmur_lyrics_screen.dart';
import 'parchment_style.dart';

/// Full-screen now-playing: parchment artwork is the backdrop, lyrics
/// live inside the painted ornamental box, and the transport sits in a
/// frosted glass panel in the band between that box and the bottom
/// cylinder. Audio is optional — a hymn without a file still opens
/// here so the lyrics are one tap away from any list.
class MezmurPlayerScreen extends StatefulWidget {
  /// Hymns in the current view (queue order = list order). Rows
  /// without audio stay in the list so the tapped hymn is always shown.
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

  /// Re-opens the live session (mini-player tap). No-ops when already
  /// on this route so a double-tap cannot stack two parchment screens.
  /// P35: pushes on the ROOT navigator via [AppNavigator]. The caller is
  /// usually the mini player, which is mounted above the Navigator in
  /// MaterialApp.builder — `Navigator.of(context)` from there finds no
  /// ancestor and the tap silently does nothing.
  static Future<void> openSession(BuildContext context) async {
    final c = MezmurAudioPlayerController.instance;
    if (c.playerVisible || c.catalog.isEmpty) return;
    await AppNavigator.push(MaterialPageRoute(
      builder: (_) => MezmurPlayerScreen(
        queue: c.catalog.toList(growable: false),
        initialIndex: c.viewIndex.clamp(0, c.catalog.length - 1),
      ),
    ));
  }

  /// One tap from a hymn list: open THIS screen for [hymnId], whether
  /// or not that row has audio. Neighbours become the skip queue.
  static Future<void> openFromRows(
    BuildContext context, {
    required List<Map<String, dynamic>> rows,
    required int hymnId,
  }) {
    final tracks =
        rows.map(MezmurTrack.fromHymnRow).toList(growable: false);
    if (tracks.isEmpty) return Future.value();
    var idx = tracks.indexWhere((t) => t.hymnId == hymnId);
    if (idx < 0) idx = 0;
    return Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => MezmurPlayerScreen(queue: tracks, initialIndex: idx),
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
  bool _paging = false;
  double? _dragMs;
  late final PageController _pages;

  List<MezmurTrack> get _catalog =>
      _c.hasCatalog ? _c.catalog : widget.queue;

  MezmurTrack get _view {
    final v = _c.viewTrack;
    if (v != null) return v;
    return widget.queue[widget.initialIndex];
  }

  /// P36: use the track's own signal, not the URL — a streamed hymn has
  /// an empty audioUrl until its short-lived link is minted, and testing
  /// the URL hid the transport controls for every hymn that was not
  /// already downloaded.
  bool get _hasAudio => _view.hasAudio;

  @override
  void initState() {
    super.initState();
    _pages = PageController(initialPage: widget.initialIndex);
    _c.addListener(_onController);
    _c.setPlayerVisible(true);
    _ensureSession();
  }

  @override
  void dispose() {
    _c.setPlayerVisible(false);
    _c.removeListener(_onController);
    _pages.dispose();
    super.dispose();
  }

  void _onController() {
    if (!mounted) return;
    setState(() {});
    if (_paging) return;
    if (!_pages.hasClients) return;
    final i = _c.viewIndex;
    final page = _pages.page?.round();
    if (page != i && i >= 0 && i < _catalog.length) {
      _pages.jumpToPage(i);
    }
  }

  /// Audio loads in the background. Lyrics never wait on this.
  ///
  /// P35: the source is PREPARED but never auto-started. Opening a hymn
  /// is usually about reading the lyrics, so sound must not start on its
  /// own — the user taps play when they want it. Loading eagerly keeps
  /// that first tap instant.
  Future<void> _ensureSession() async {
    final ok = await _c.openCatalog(widget.queue,
        startIndex: widget.initialIndex, autoPlay: false);
    if (!mounted) return;
    setState(() {
      _opening = false;
      _failed = _hasAudio && !ok;
    });
  }

  Future<void> _onPageChanged(int i) async {
    if (i == _c.viewIndex) return;
    _paging = true;
    // P35: swiping to another hymn carries the CURRENT intent — if audio
    // is playing it keeps playing (continuous listening), if the user is
    // just browsing lyrics it stays silent. Never starts sound on its own.
    await _c.moveTo(i, autoPlay: _c.playing);
    _paging = false;
  }

  int get _totalMs {
    final d = _c.duration;
    if (d != null && d.inMilliseconds > 0) return d.inMilliseconds;
    final secs = _view.durationSeconds ?? 0;
    return secs * 1000;
  }

  double get _clampedPositionMs {
    final total = _totalMs;
    final p = _c.position.inMilliseconds;
    return (p < 0 ? 0 : (total > 0 && p > total ? total : p)).toDouble();
  }

  void _showSettings() {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: const Color(0xFFF3E4C4),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Playback settings',
                  style: TextStyle(
                    fontFamily: 'NotoSansEthiopic',
                    fontWeight: FontWeight.w800,
                    fontSize: 16,
                    color: Parchment.inkStrong,
                  ),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Speed',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: Parchment.inkFaint,
                    letterSpacing: 0.4,
                  ),
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  children: [
                    for (final r in MezmurAudioPlayerController.rates)
                      ChoiceChip(
                        label: Text(
                          r == 1 ? '1×' : '${r}×',
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        selected: (_c.rate - r).abs() < 0.01,
                        selectedColor: Parchment.gold,
                        onSelected: (_) {
                          _c.setRate(r);
                          Navigator.pop(ctx);
                        },
                      ),
                  ],
                ),
                if (_catalog.length > 1) ...[
                  const SizedBox(height: 18),
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.queue_music_rounded,
                        color: Parchment.inkStrong),
                    title: const Text(
                      'Up next',
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: Parchment.inkStrong,
                      ),
                    ),
                    trailing: Text(
                      '${_catalog.length}',
                      style: const TextStyle(color: Parchment.inkFaint),
                    ),
                    onTap: () {
                      Navigator.pop(ctx);
                      _showQueue();
                    },
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  /// "Aa" — accessibility / reading preferences for the lyrics. Backed by the
  /// LyricsReaderSettings singleton so every hymn updates live and the choice
  /// persists across restarts (elderly users are never asked twice). Always
  /// reachable, even for a hymn with no audio, because reading the words is the
  /// point for many members.
  void _showTextSettings() {
    final rs = LyricsReaderSettings.instance;
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: const Color(0xFFF3E4C4),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (ctx) {
        return ListenableBuilder(
          listenable: rs,
          builder: (ctx, _) {
            return SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Text & reading',
                      style: TextStyle(
                        fontFamily: 'NotoSansEthiopic',
                        fontWeight: FontWeight.w800,
                        fontSize: 16,
                        color: Parchment.inkStrong,
                      ),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'Make the lyrics easy to read. Your choice applies to '
                      'every hymn and is remembered.',
                      style: TextStyle(
                        color: Parchment.inkFaint,
                        fontSize: 12.5,
                        height: 1.4,
                      ),
                    ),
                    const SizedBox(height: 8),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: rs.textScale > LyricsReaderSettings.defaultTextScale,
                      activeColor: Parchment.bronze,
                      title: const Text(
                        'Large text',
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: Parchment.inkStrong,
                        ),
                      ),
                      subtitle: const Text(
                        'Bigger words for everyone to read.',
                        style: TextStyle(color: Parchment.inkFaint, fontSize: 12),
                      ),
                      onChanged: (big) => rs.setTextScale(
                          big ? 1.35 : LyricsReaderSettings.defaultTextScale),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: rs.readingMode,
                      activeColor: Parchment.bronze,
                      title: const Text(
                        'Reading mode',
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: Parchment.inkStrong,
                        ),
                      ),
                      subtitle: const Text(
                        'Lyrics only — steady, full-size text with less motion.',
                        style: TextStyle(color: Parchment.inkFaint, fontSize: 12),
                      ),
                      onChanged: (v) => rs.setReadingMode(v),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: rs.highContrast,
                      activeColor: Parchment.bronze,
                      title: const Text(
                        'High contrast',
                        style: TextStyle(
                          fontWeight: FontWeight.w700,
                          color: Parchment.inkStrong,
                        ),
                      ),
                      subtitle: const Text(
                        'Darker text for maximum legibility.',
                        style: TextStyle(color: Parchment.inkFaint, fontSize: 12),
                      ),
                      onChanged: (v) => rs.setHighContrast(v),
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Text(
                          'A',
                          style: TextStyle(
                            fontSize:
                                13 * LyricsReaderSettings.minTextScale,
                            color: Parchment.ink,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        Expanded(
                          child: Slider(
                            min: LyricsReaderSettings.minTextScale,
                            max: LyricsReaderSettings.maxTextScale,
                            value: rs.textScale,
                            activeColor: Parchment.bronze,
                            inactiveColor:
                                Parchment.bronzeSoft.withOpacity(0.28),
                            onChanged: (v) => rs.setTextScale(v),
                          ),
                        ),
                        Text(
                          'A',
                          style: TextStyle(
                            fontSize: 13 * LyricsReaderSettings.maxTextScale,
                            color: Parchment.ink,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  void _showQueue() {
    final q = _catalog;
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
              final active = _c.viewIndex == i;
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
                  // Explicit tap on a queue row = play that hymn.
                  _c.moveTo(i, autoPlay: true);
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
    const overlay = SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.dark,
      statusBarBrightness: Brightness.light,
      systemNavigationBarColor: Color(0xE62A150A),
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
        _ChipIcon(
          icon: Icons.arrow_back_rounded,
          tooltip: 'Close',
          onTap: () => Navigator.of(context).maybePop(),
        ),
        const Expanded(child: SizedBox.shrink()),
        // P33: keep the hymn you are listening to. The parchment chip
        // reflects live download state, same vocabulary as the list.
        _DownloadChip(hymnId: _view.hymnId, ready: _c.viewHasAudio),
        const SizedBox(width: 8),
        _ChipIcon(
          icon: Icons.text_increase_rounded,
          tooltip: 'Text & reading',
          onTap: _showTextSettings,
        ),
        const SizedBox(width: 8),
        _ChipIcon(
          icon: Icons.tune_rounded,
          tooltip: 'Playback settings',
          onTap: _showSettings,
        ),
      ],
    );
  }

  Widget _buildTitle() {
    final track = _view;
    final n = _catalog.length;
    final idx = _c.hasCatalog ? _c.viewIndex : widget.initialIndex;
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text(
          track.title.isEmpty ? 'መዝሙር' : track.title,
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
            if (track.category?.isNotEmpty == true) track.category,
            if (n > 1) '${idx + 1} / $n',
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

  /// Lyrics paint from the tapped hymn on the first frame. They are
  /// never gated on audio open / currentTrack. Horizontal pages swipe
  /// to the next/previous catalog hymn (audio optional).
  Widget _buildLyricsBox() {
    final pages = _catalog;
    if (pages.isEmpty) {
      final track = _view;
      return MezmurLyricsScreen(key: ValueKey(track.hymnId), track: track);
    }
    return PageView.builder(
      controller: _pages,
      itemCount: pages.length,
      onPageChanged: _onPageChanged,
      itemBuilder: (context, i) {
        final track = pages[i];
        return MezmurLyricsScreen(key: ValueKey(track.hymnId), track: track);
      },
    );
  }

  Widget _buildConsole(BuildContext context) {
    if (!_hasAudio || (_c.playbackError != null && !_c.canPlayCurrentView)) {
      return _GlassPanel(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(8, 10, 8, 8),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                _c.playbackError ?? (_failed
                    ? 'ኦዲዮ መጫን አልተቻለም።\nAudio could not be loaded for this hymn.'
                  : 'ለዚህ መዝሙር በአሁኑ ጊዜ ኦዲዮ የለም።\nThere is no audio for this hymn currently.'),
                textAlign: TextAlign.center,
                style: const TextStyle(
                  color: Parchment.inkStrong,
                  fontSize: 13,
                  height: 1.45,
                  fontWeight: FontWeight.w600,
                  fontFamily: 'NotoSansEthiopic',
                ),
              ),
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  _CtrlIcon(
                    icon: Icons.skip_previous_rounded,
                    tooltip: 'Previous',
                    size: 34,
                    onTap: _catalog.length > 1 ? _c.previous : null,
                  ),
                  if (_hasAudio)
                    _CtrlIcon(
                      icon: Icons.refresh_rounded,
                      tooltip: 'Retry audio',
                      size: 34,
                      onTap: _c.play,
                    ),
                  const SizedBox(width: 18),
                  _CtrlIcon(
                    icon: Icons.skip_next_rounded,
                    tooltip: 'Next',
                    size: 34,
                    onTap: _catalog.length > 1 ? _c.next : null,
                  ),
                ],
              ),
            ],
          ),
        ),
      );
    }

    final totalMs = _totalMs;
    final posMs = _dragMs ?? _clampedPositionMs;
    final dur = Duration(milliseconds: totalMs);
    final loop = _c.loopMode;
    final shuffleOn = _c.shuffle;
    // Spinner only while audio is still opening. Buffering mid-stream
    // must never replace the pause glyph.
    final loading = !_c.playing && (_opening || _c.buffering);

    return _GlassPanel(
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: SizedBox(
          width: 360,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(8, 6, 8, 4),
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
                          inactiveTrackColor:
                              Parchment.bronzeSoft.withOpacity(0.28),
                          thumbColor: Parchment.inkStrong,
                          overlayColor: Parchment.gold.withOpacity(0.16),
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
                      onTap: _catalog.length > 1 ? _c.previous : null,
                    ),
                    _PlayButton(
                      loading: loading,
                      playing: _c.playing,
                        onTap: _c.canPlayCurrentView
                          ? _c.toggle
                          : (_c.canPlayControl ? _c.play : null),
                    ),
                    _CtrlIcon(
                      icon: Icons.skip_next_rounded,
                      tooltip: 'Next',
                      size: 34,
                      onTap: _catalog.length > 1 ? _c.next : null,
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
        ),
      ),
    );
  }
}

/// Frosted glass plate that sits on the parchment under the transport.
class _GlassPanel extends StatelessWidget {
  final Widget child;
  const _GlassPanel({required this.child});

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(22),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: const Color(0x99F6E7C8),
            borderRadius: BorderRadius.circular(22),
            border: Border.all(
              color: Parchment.bronze.withOpacity(0.34),
              width: 1,
            ),
          ),
          child: child,
        ),
      ),
    );
  }
}

/// High-contrast cream chip so the back arrow stays readable on the
/// gold roll (bronze-on-gold disappears).
class _ChipIcon extends StatelessWidget {
  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;
  const _ChipIcon(
      {required this.icon, required this.tooltip, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: const Color(0xF5F8EBCB),
      shape: const CircleBorder(),
      elevation: 2,
      shadowColor: const Color(0x66000000),
      child: IconButton(
        tooltip: tooltip,
        icon: Icon(icon, color: const Color(0xFF1A0C06), size: 24),
        iconSize: 24,
        onPressed: onTap,
      ),
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


/// P33 — parchment-styled download control for the now-playing header.
class _DownloadChip extends StatefulWidget {
  const _DownloadChip({required this.hymnId, required this.ready});
  final int hymnId;
  final bool ready;

  @override
  State<_DownloadChip> createState() => _DownloadChipState();
}

class _DownloadChipState extends State<_DownloadChip> {
  final _dl = MezmurDownloadManager.instance;

  @override
  void initState() {
    super.initState();
    _dl.addListener(_onChange);
  }

  @override
  void dispose() {
    _dl.removeListener(_onChange);
    super.dispose();
  }

  void _onChange() {
    if (mounted) setState(() {});
  }

  Future<void> _tap() async {
    final id = widget.hymnId;
    if (id <= 0) return;
    final state = _dl.stateOf(id);
    if (state == 'done') {
      await _dl.remove(id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Removed from downloads'),
          duration: Duration(seconds: 2)));
      return;
    }
    if (state == 'queued' || state == 'downloading') {
      await _dl.remove(id);
      return;
    }
    // The manager resolves the cached hymn row itself, so the stored
    // copy carries audio_updated_at and stays refreshable.
    await _dl.downloadById(id);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(_dl.waitingForWifi
          ? 'Queued — will download on Wi‑Fi'
          : 'Downloading for offline use'),
      duration: const Duration(seconds: 2),
    ));
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.ready || widget.hymnId <= 0) return const SizedBox.shrink();
    final state = _dl.stateOf(widget.hymnId);
    IconData icon;
    String tip;
    switch (state) {
      case 'done':
        icon = Icons.download_done_rounded;
        tip = 'Downloaded — plays offline';
        break;
      case 'downloading':
        icon = Icons.downloading_rounded;
        tip = 'Downloading ${(_dl.progressOf(widget.hymnId) * 100).round()}%';
        break;
      case 'queued':
        icon = Icons.schedule_rounded;
        tip = _dl.waitingForWifi ? 'Waiting for Wi\u2011Fi' : 'Queued';
        break;
      case 'failed':
        icon = Icons.error_outline_rounded;
        tip = 'Download failed — tap to retry';
        break;
      default:
        icon = Icons.arrow_circle_down_outlined;
        tip = 'Download for offline listening';
    }
    return _ChipIcon(icon: icon, tooltip: tip, onTap: _tap);
  }
}
