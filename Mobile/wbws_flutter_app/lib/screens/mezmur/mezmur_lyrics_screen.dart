import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/scheduler.dart';

import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/mezmur_audio_player.dart';
import '../../services/hymn_store.dart';
import '../../services/mezmur_synced_lyrics.dart';
import '../../services/lyrics_emphasis.dart';
import '../../services/lyrics_reader_settings.dart';
import 'mezmur_lyrics_sync_screen.dart';
import 'parchment_style.dart';

/// Lyrics that live INSIDE the parchment ornamental box.
///
/// Timed lines highlight in real time; the active line is held at the
/// visual centre. Extra padding equal to half the viewport lets the
/// first and last lines scroll in from either edge. A ShaderMask fades
/// lines through the in/out points so they never clip hard against the
/// painted frame. Tapping a line seeks the player to its timestamp.
///
/// Loading model: timed text lives on the hymn row (lyrics_synced) and
/// is read locally first (offline-friendly), then refreshed from the
/// single-hymn endpoint while online.
///
/// Rendering model (P51/P53) — Spotify-style lyric emphasis on parchment:
///   * NO bubble / background per line. The sung line is simply bold + bright
///     + full size (and very slightly larger via a pure scale transform);
///     every other line recedes with distance (smaller and fainter) through
///     the pure distance formula (LyricEmphasis). This is never a font-size
///     or weight change that alters layout, so a long Amharic line can never
///     re-wrap and push its words onto a second row.
///   * Every lyric is rendered as exactly ONE row: it is laid out with
///     `softWrap:false` inside a `FittedBox(scaleDown)`, so if a line is too
///     wide it scales down to fit the width rather than wrapping.
///   * Auto-scroll is NOT a per-line `ensureVisible` (which restarted and
///     stuttered). A single Ticker eases the scroll offset toward the
///     centred target with an exponential glide, so transitions are smooth
///     and kinetic; the renderer stops ticking when nothing is playing.
///   * Emphasis animation is isolated per line behind a RepaintBoundary and
///     driven by implicit animations (no per-frame setState on the list).
class MezmurLyricsScreen extends StatefulWidget {
  final MezmurTrack track;
  const MezmurLyricsScreen({super.key, required this.track});

  @override
  State<MezmurLyricsScreen> createState() => _MezmurLyricsScreenState();
}

enum _LyricsMode { synced, staticOnly, none }

class _MezmurLyricsScreenState extends State<MezmurLyricsScreen>
    with SingleTickerProviderStateMixin {
  final MezmurAudioPlayerController _c =
      MezmurAudioPlayerController.instance;
  final ScrollController _scroll = ScrollController();

  Timer? _positionTicker; // samples the audio position → active line
  late final Ticker _smoothTicker; // eases the scroll offset (60 fps)
  bool _wasPlaying = false;
  Duration _lastTick = Duration.zero;
  double _targetOffset = 0;

  Timer? _resumeHold;
  SyncedLyrics? _doc;
  String? _staticLyrics;
  _LyricsMode _mode = _LyricsMode.none;
  bool _loading = true;
  bool _hadNetworkError = false;
  bool _userHold = false;
  int _active = -1;
  List<GlobalKey> _keys = const [];

  bool get _syncedAvailable =>
      _doc != null && !_doc!.isEmpty && _mode == _LyricsMode.synced;

  // P52 — accessibility / readability. A single app-wide model (see
  // LyricsReaderSettings) drives size, reading mode and contrast so the same
  // choice applies to every hymn and survives restart. These getters are read
  // on every build; the listener below triggers a rebuild + re-centre when the
  // user changes them in the player's "Aa" sheet.
  LyricsReaderSettings get _reader => LyricsReaderSettings.instance;

  /// Base lyric font size. Multiplied by the user's text scale (and, on top of
  /// that, by the OS accessibility font size) to give a comfortably large line.
  static const double _baseLyricSize = 17;

  /// Line height: gives generous vertical rhythm, a little more in reading
  /// mode and a touch more for large text so it never looks cramped.
  double get _lyricLineHeight {
    final rs = _reader;
    var h = 1.5;
    if (rs.readingMode) h = 1.62;
    h += (rs.textScale - 1.0) * 0.12;
    return h;
  }

  /// Sung (active) line color — the darkest, most saturated ink so the line
  /// being sung is unmistakable at a glance. It never changes font-size in
  /// layout, only this color + weight + a scale transform, so a line cannot
  /// re-wrap.
  static const Color _activeInk = Parchment.inkStrong;

  /// Receding lines: a warm bronze that visibly recedes against the parchment
  /// (in high-contrast mode they fall back to the darkest ink for legibility).
  Color get _restInk =>
      _reader.highContrast ? Parchment.inkStrong : Parchment.bronze;

  Color get _lyricInk =>
      _reader.highContrast ? Parchment.inkStrong : Parchment.ink;

  void _onReaderChanged() {
    if (!mounted) return;
    setState(() {});
    // The active line must stay centred under the new text size.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _recenter(instant: true);
    });
  }

  static const Duration _anim = Duration(milliseconds: 280);
  static const Curve _curve = Curves.easeOutCubic;

  void _paintFrom(String synced, String staticText) {
    final parsed = SyncedLyrics.tryParse(synced.trim());
    if (parsed != null && !parsed.isEmpty) {
      _doc = parsed;
      _mode = _LyricsMode.synced;
      _keys = List<GlobalKey>.generate(
          parsed.lines.length, (_) => GlobalKey());
      _staticLyrics = staticText;
      _loading = false;
    } else if (staticText.trim().isNotEmpty) {
      _doc = null;
      _mode = _LyricsMode.staticOnly;
      _staticLyrics = staticText;
      _keys = const [];
      _loading = false;
    } else {
      _doc = null;
      _mode = _LyricsMode.none;
      _staticLyrics = staticText;
      _keys = const [];
    }
  }

  @override
  void initState() {
    super.initState();
    // Seed from the list row when it already carries lyrics so the
    // first frame is never a spinner waiting on audio or the network.
    _paintFrom(widget.track.lyricsSynced ?? '', widget.track.lyrics ?? '');
    _load();
    _positionTicker = Timer.periodic(
        const Duration(milliseconds: 120), (_) => _onPositionTick());
    // The scroll Ticker is created but not started here. The play/pause watch
    // (below) starts it when audio plays and stops it when idle, so the
    // renderer is never ticking while silently parked. The initial centre is
    // reached by _recenter(instant: true) on load, which needs no Ticker.
    _smoothTicker = createTicker(_onSmoothTick);
    _reader.addListener(_onReaderChanged);
  }

  @override
  void didUpdateWidget(MezmurLyricsScreen old) {
    super.didUpdateWidget(old);
    if (old.track.hymnId != widget.track.hymnId) {
      _userHold = false;
      _resumeHold?.cancel();
      _paintFrom(widget.track.lyricsSynced ?? '', widget.track.lyrics ?? '');
      _load();
    }
  }

  @override
  void dispose() {
    _positionTicker?.cancel();
    _smoothTicker.dispose();
    _resumeHold?.cancel();
    _reader.removeListener(_onReaderChanged);
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    // Never blank lyrics that are already on screen. The player must
    // show text while audio is still opening.
    if (_mode == _LyricsMode.none && mounted) {
      setState(() => _loading = true);
    }
    final db = LocalDb();
    var row = await db.getLocalHymn(widget.track.hymnId);
    var synced = (row?['lyrics_synced'] as String?)?.trim() ?? '';
    var staticText = (row?['lyrics'] as String?) ?? '';
    if (synced.isEmpty) synced = (widget.track.lyricsSynced ?? '').trim();
    if (staticText.isEmpty) staticText = widget.track.lyrics ?? '';
    // P50: always refresh timed lyrics from the server when online, not
    // only when the cache is empty. The web player re-fetches the row on
    // every open; the app used to trust a possibly-stale local cache
    // (populated only by the last delta pull), so timings authored on the
    // console after the phone's last sync never appeared. One cheap
    // single-hymn read closes that gap and makes "web works ⇒ app works"
    // structural rather than dependent on a prior sync cycle. Online is
    // an optimization, not a requirement: when offline the cache above is
    // enough, and a failed fetch keeps the cached text on screen.
    if (ConnectivityService().hasLink) {
      try {
        final res = await ApiService().getMezmurHymn(widget.track.hymnId);
        if (res.success && res.data is Map && res.data['item'] is Map) {
          final item = Map<String, dynamic>.from(res.data['item']);
          await db.upsertHymns([item]);
          row = await db.getLocalHymn(widget.track.hymnId);
          synced = ((row?['lyrics_synced'] as String?) ?? synced).trim();
          staticText = (row?['lyrics'] as String?) ?? staticText;
        }
      } catch (_) {
        _hadNetworkError = true;
      }
    }
    if (!mounted) return;
    setState(() {
      _loading = false;
      _paintFrom(synced, staticText);
      _syncActive(force: true);
    });
  }

  /// Samples the audio position and updates the active line; also watches
  /// play/pause so the scroll ticker stops when nothing is playing (near-zero
  /// idle cost, like a production player — rendering stops when silent).
  void _onPositionTick() {
    if (!mounted) return;
    final playing = _c.playing;
    if (playing != _wasPlaying) {
      _wasPlaying = playing;
      if (playing) {
        _lastTick = Duration.zero;
        // Guard: Ticker.start() throws "started twice" if already active, so
        // only start when it was actually stopped.
        if (!_smoothTicker.isActive) _smoothTicker.start();
      } else {
        _smoothTicker.stop();
      }
    }
    _syncActive();
  }

  /// Updates [active] to the line currently under the playhead. Every change
  /// recomputes a fresh [scrollTargetOffset] (post-frame, so the item is laid
  /// out) and lets the Ticker glide to it — never a re-entrant ensureVisible.
  void _syncActive({bool force = false}) {
    if (!_syncedAvailable) return;
    final i = _doc!.indexFor(_c.position);
    if (i == _active && !force) return;
    _active = i;
    if (mounted) setState(() {});
    if (_userHold) return;
    final act = i;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted && _active == act) _recenter(instant: force);
    });
  }

  /// Computes the scroll offset that centres line [active] and either springs
  /// to it or (default) hands it to the Ticker to glide toward. Uses the
  /// render-viewport reveal API (the same one `ensureVisible` uses) so it is
  /// exact even for variable-height (wrapped) Amharic lines.
  void _recenter({bool instant = false}) {
    if (!_scroll.hasClients) return;
    final i = _active;
    if (i < 0 || i >= _keys.length) return;
    final ro = _keys[i].currentContext?.findRenderObject();
    if (ro == null || ro is! RenderBox) return;
    final vp = RenderAbstractViewport.maybeOf(ro);
    if (vp == null) return;
    try {
      final reveal = vp.getOffsetToReveal(ro, 0.5);
      final max = _scroll.position.maxScrollExtent;
      final off = reveal.offset.clamp(0.0, max);
      _targetOffset = off;
      if (instant) _scroll.jumpTo(off);
    } catch (_) {
      // A frame where the item is mid-layout: leave the last target; the
      // Ticker simply glides there.
    }
  }

  /// Continual glide toward [targetOffset]. Exponential (frame-rate
  /// independent) easing gives smooth inertia and a natural settle — this is
  /// what replaces the stuttery per-line animations.
  void _onSmoothTick(Duration elapsed) {
    if (!mounted || !_scroll.hasClients) return;
    final dt = (elapsed - _lastTick).inMicroseconds / 1e6;
    _lastTick = elapsed;
    if (dt <= 0) return;
    final current = _scroll.offset;
    if (_userHold) return; // never fight the user's finger
    final max = _scroll.position.maxScrollExtent;
    var target = _targetOffset;
    if (target < 0) target = 0;
    if (target > max) target = max;
    const stiffness = 6.0; // higher = snappier, lower = lazier glide
    final k = 1 - math.exp(-stiffness * dt);
    final next = current + (target - current) * k;
    if ((next - current).abs() > 0.02) {
      _scroll.jumpTo(next);
    }
  }

  void _tapLine(int i, SyncedLyricLine line) {
    if (line.isEmpty) return;
    _c.seek(line.time);
    _active = i;
    _userHold = false;
    if (mounted) setState(() {});
    final act = i;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted && _active == act) _recenter(instant: true);
    });
  }

  void _onUserScroll(ScrollNotification n) {
    if (n is ScrollStartNotification && n.dragDetails != null) {
      _userHold = true;
      _resumeHold?.cancel();
    } else if (n is ScrollEndNotification && _userHold) {
      _resumeHold?.cancel();
      _resumeHold = Timer(const Duration(milliseconds: 2200), () {
        if (!mounted) return;
        _userHold = false;
        // If we are playing the Ticker is live and will glide; if paused it
        // is stopped, so snap back to the sung line instead of doing nothing.
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) _recenter(instant: !_c.playing);
        });
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _mode == _LyricsMode.none) {
      // Empty parchment while the local row is read — never a spinner
      // sitting in the lyrics box while audio opens.
      return const SizedBox.shrink();
    }
    if (_syncedAvailable) return _buildSynced();
    if (_mode == _LyricsMode.staticOnly) return _buildStatic();
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              _hadNetworkError
                  ? 'Synced lyrics could not be downloaded — check your connection and try again.'
                  : 'የጊዜ ግጥም ገና አልተጨመሩም።\nNo timed lyrics have been added for this hymn yet.',
              textAlign: TextAlign.center,
              style: const TextStyle(
                  color: Parchment.inkFaint, fontSize: 13, height: 1.6),
            ),
            // P48: curators can author the timings right here. Gated on
            // canEdit, which mirrors the server's role check — the UI
            // hides it, and the API still refuses it, so hiding is a
            // convenience and never the security boundary.
            if (HymnStore().canEdit && _hasStaticLyrics) ...[
              const SizedBox(height: 14),
              TextButton.icon(
                onPressed: _openSyncEditor,
                icon: const Icon(Icons.timer_outlined, size: 18),
                label: const Text('Sync lyrics to audio'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  bool get _hasStaticLyrics =>
      (_staticLyrics ?? '').trim().isNotEmpty ||
      (widget.track.lyrics ?? '').trim().isNotEmpty;

  /// P48: open the tap-to-sync editor, then reload so the new timings
  /// are picked up immediately without leaving the player.
  Future<void> _openSyncEditor() async {
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => MezmurLyricsSyncScreen(track: widget.track),
      ),
    );
    if (saved == true && mounted) {
      _userHold = false;
      await _load();
    }
  }

  Widget _buildSynced() {
    final lines = _doc!.lines;
    final reduce = MediaQuery.of(context).disableAnimations;
    final anim = reduce ? Duration.zero : _anim;
    // P52 — read the ambient accessibility model once per build.
    final rs = _reader;
    final reading = rs.readingMode;
    final size = _baseLyricSize * rs.textScale;
    final lineHeight = _lyricLineHeight;
    final profile =
        reading ? LyricEmphasisProfile.reading : LyricEmphasisProfile.karaoke;
    return LayoutBuilder(builder: (context, box) {
      // P54: the padding lets the first/last lines scroll to the centre. 0.30
      // (was 0.42) keeps that centring while removing the big "loose" empty
      // strip that pushed the lyrics too far down on short hymns.
      final pad = box.maxHeight * 0.30;
      return ParchmentFade(
        child: NotificationListener<ScrollNotification>(
          onNotification: (n) {
            _onUserScroll(n);
            return false;
          },
          child: ListView.builder(
            controller: _scroll,
            cacheExtent: 4000,
            // P53: keep side padding tiny so every line has the widest run of
            // text possible (FittedBox guarantees one row either way).
            padding: EdgeInsets.fromLTRB(6, pad, 6, pad),
            itemCount: lines.length,
            itemBuilder: (context, i) {
              final line = lines[i];
              final isEmpty = line.isEmpty;
              // Pure distance rule (never changing font size/weight in layout)
              // drives scale/opacity/active. Reading mode uses the flat
              // profile so nothing shrinks and all lines stay fully readable.
              final e = LyricEmphasis.forIndex(
                  index: i, active: _active, profile: profile);
              return KeyedSubtree(
                key: _keys[i],
                child: GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTap: isEmpty ? null : () => _tapLine(i, line),
                  // RepaintBoundary isolates each line so animating one can
                  // never repaint the whole list (long bodies on low-end).
                  child: RepaintBoundary(
                    child: Padding(
                      padding:
                          EdgeInsets.symmetric(vertical: reading ? 13 : 10),
                      child: AnimatedOpacity(
                        opacity: e.isActive ? 1.0 : e.opacity,
                        duration: anim,
                        curve: _curve,
                        child: AnimatedScale(
                          scale: e.scale,
                          duration: anim,
                          curve: _curve,
                          alignment: Alignment.center,
                          // FittedBox + softWrap:false GUARANTEES the lyric
                          // renders as exactly ONE row — it scales the line to
                          // the available width instead of wrapping it, which
                          // is the fix for "one line is being split into two".
                          child: FittedBox(
                            fit: BoxFit.scaleDown,
                            alignment: Alignment.center,
                            child: Text(
                              isEmpty ? '· · ·' : line.text,
                              softWrap: false,
                              maxLines: 1,
                              overflow: TextOverflow.visible,
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                // Sung line = darkest, boldest ink; the rest
                                // recede to a warm, lighter bronze so the
                                // hierarchy is obvious (a real color change
                                // AND a size change, never a font-size layout
                                // swap, so nothing can re-wrap).
                                color: e.isActive ? _activeInk : _restInk,
                                fontSize: size,
                                height: lineHeight,
                                fontWeight: e.isActive
                                    ? FontWeight.w800
                                    : FontWeight.w500,
                                fontFamily: 'NotoSansEthiopic',
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              );
            },
          ),
        ),
      );
    });
  }

  Widget _buildStatic() {
    // Static lyrics have no active line, but the reader's size, line-height and
    // contrast still apply so a plain-text hymn is just as readable.
    final size = _baseLyricSize * _reader.textScale;
    final height = _lyricLineHeight + 0.25;
    final ink = _lyricInk;
    return LayoutBuilder(builder: (context, box) {
      final pad = box.maxHeight * 0.18;
      return ParchmentFade(
        child: ListView(
          controller: _scroll,
          padding: EdgeInsets.fromLTRB(6, pad, 6, pad),
          children: [
            for (final para in (_staticLyrics ?? '').split(RegExp(r'\n{2,}')))
              if (para.trim().isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: 14),
                  child: Text(
                    para.trim(),
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        color: ink,
                        fontSize: size,
                        height: height,
                        fontWeight: FontWeight.w600,
                        fontFamily: 'NotoSansEthiopic'),
                  ),
                ),
          ],
        ),
      );
    });
  }
}
