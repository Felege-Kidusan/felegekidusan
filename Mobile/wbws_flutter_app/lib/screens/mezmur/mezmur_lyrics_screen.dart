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
/// Rendering model (P51/P53, emphasis reworked in P61) — Spotify-style lyric
/// emphasis on parchment:
///   * NO bubble / background per line. The sung line is simply bold + bright
///     + full size (and very slightly larger via a pure scale transform);
///     every other line recedes with distance (smaller and fainter) through
///     the pure distance formula (LyricEmphasis). This is never a font-size
///     change that alters layout, so a long Amharic line can never re-wrap
///     and push its words onto a second row.
///   * Every lyric is rendered as exactly ONE row: it is laid out with
///     `softWrap:false` inside a `FittedBox(scaleDown)`, so if a line is too
///     wide it scales down to fit the width rather than wrapping. EXCEPTION:
///     reading mode (and only reading mode) wraps — there is no scale
///     animation there to jank, and allowing wrap means very large
///     accessibility text keeps full width instead of being shrunk by the
///     FittedBox.
///   * Auto-scroll is NOT a per-line `ensureVisible` (which restarted and
///     stuttered). A single Ticker eases the scroll offset toward the
///     centred target with an exponential glide, so transitions are smooth
///     and kinetic; the renderer stops ticking when nothing is playing.
///   * Emphasis (P61) is driven by ONE tween per line — the line's distance
///     from the active line — and scale, opacity, colour and weight are all
///     derived from that single value. The previous three parallel implicit
///     animations (Opacity + Scale + DefaultTextStyle) held the same duration
///     and curve but were three controllers, and the weight/colour text
///     relayout made the size read as lagging the fade. One value cannot
///     desynchronise from itself. Scale stays a paint transform; weight is
///     lerped from the same distance so the whole line rises as one motion.
///     Each line sits behind its own RepaintBoundary, so animating one can
///     never repaint the whole list (long bodies on low-end).
///   * The active line is driven by the audio engine's position stream
///     (event-driven, no polling timer): a line lights up the frame the
///     playhead crosses it, and the UI does nothing while paused.
///   * Word-level karaoke (P63): when a line carries enhanced-LRC word
///     timings, its chunks light as they are sung — a colour-only sweep
///     (per-chunk colour, never size or weight) that cannot re-shape the
///     line. Lines without word timings fall back to line highlighting;
///     reading mode keeps steady single-colour text.
class MezmurLyricsScreen extends StatefulWidget {
  final MezmurTrack track;
  const MezmurLyricsScreen({super.key, required this.track});

  @override
  State<MezmurLyricsScreen> createState() => _MezmurLyricsScreenState();
}

enum _LyricsMode { synced, staticOnly, none }

class _MezmurLyricsScreenState extends State<MezmurLyricsScreen>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  final MezmurAudioPlayerController _c =
      MezmurAudioPlayerController.instance;
  final ScrollController _scroll = ScrollController();

  StreamSubscription<Duration>? _posSub; // engine position samples → active line
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

  /// P63 — how many leading chunks of the active line are sung (word-level
  /// sweep). Only meaningful while [_active] >= 0 and that line has words.
  int _activeSung = 0;
  List<GlobalKey> _keys = const [];

  /// P61 — async-load generation. `_load()` awaits disk + network; a second
  /// `_load()` can start (track change / page churn) before the first
  /// finishes, and the stale response used to paint the PREVIOUS hymn's
  /// lyrics into the current one. Every load takes a ticket; a response
  /// whose ticket is no longer current is dropped.
  int _loadGen = 0;

  /// P61 — suspend active-line work while the app is backgrounded. Audio
  /// keeps playing (just_audio_background), but no frame is rendered, so
  /// setState traffic while invisible is pure waste. On resume the active
  /// line is force-re-synced.
  bool _foreground = true;

  /// Cached once: the sync-editor affordance is role-gated and the role
  /// cannot change while this route is alive.
  late final bool _canEdit = HymnStore().canEdit;

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

  /// P63 — not-yet-sung chunks inside the ACTIVE line: one step lighter than
  /// the sung ink but clearly present (they are about to be sung), distinct
  /// from the receded bronze of the surrounding lines. High-contrast keeps a
  /// visible step while staying dark enough to read.
  Color get _sweepPendingInk =>
      _reader.highContrast ? Parchment.ink : Parchment.bronzeSoft;

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

  // P56 — easy-in/out emphasis. `easeOutCubic` started at max velocity, so a
  // line that stopped being sung snapped most of the way to its faded/small
  // state in the first frames then settled — the "not smooth exit". A gentle
  // ease-in-and-out makes the exit (and the entry) begin slowly. A little more
  // time lets the fade settle in step with the scroll glide.
  static const Duration _anim = Duration(milliseconds: 360);
  static const Curve _curve = Curves.easeInOutCubic;

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
    // P61 — event-driven position sampling. The engine's position stream
    // pushes a sample every frame-ish interval while audio advances; we do
    // nothing while paused and never wake the framework on a timer. The
    // controller's ChangeNotifier covers the edges the stream cannot see
    // (play/pause, seek, track change).
    _posSub = _c.positionStream.listen((_) => _syncActive());
    _c.addListener(_onControllerChanged);
    WidgetsBinding.instance.addObserver(this);
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
    _posSub?.cancel();
    _c.removeListener(_onControllerChanged);
    WidgetsBinding.instance.removeObserver(this);
    _smoothTicker.dispose();
    _resumeHold?.cancel();
    _reader.removeListener(_onReaderChanged);
    _scroll.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    _foreground = s == AppLifecycleState.resumed;
    if (_foreground && mounted) {
      // Anything may have happened while invisible; re-light the right line.
      _syncActive(force: true);
    }
  }

  Future<void> _load() async {
    // P61: every load takes a generation ticket; a later load invalidates
    // this one, and its late response is dropped instead of painted.
    final gen = ++_loadGen;
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
    if (!mounted || gen != _loadGen) return;
    setState(() {
      _loading = false;
      _paintFrom(synced, staticText);
      _syncActive(force: true);
    });
  }

  /// Controller edges the position stream cannot see: play/pause (start/stop
  /// the scroll ticker), seek and track change (re-light the line instantly).
  void _onControllerChanged() {
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
  /// No-ops while the app is backgrounded (no frames are rendered; the
  /// lifecycle observer force-re-syncs on resume).
  ///
  /// P63: also tracks how many leading chunks of the active line are sung
  /// ([_activeSung]) for the word-level sweep — a word-count change alone
  /// repaints the line's colours without touching scroll or emphasis.
  void _syncActive({bool force = false}) {
    if (!_syncedAvailable || !_foreground) return;
    final i = _doc!.indexFor(_c.position);
    final sung =
        i >= 0 ? _doc!.sungWordCount(_doc!.lines[i], _c.position) : 0;
    final lineChanged = i != _active;
    if (!lineChanged && sung == _activeSung && !force) return;
    _active = i;
    _activeSung = sung;
    if (mounted) setState(() {});
    if (!lineChanged && !force) return; // word sweep only — no re-centering
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
    // Reduce-motion users get no glide either — the same preference that
    // shortens the emphasis animation to zero applies to the scroll.
    if (!instant &&
        (MediaQuery.maybeOf(context)?.disableAnimations ?? false)) {
      instant = true;
    }
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
    // P56 — gentler centring glide so the exiting line drifts away in step with
    // the emphasis fade instead of being whipped out of place (the transition
    // reads as one smooth motion, not two competing ones).
    const stiffness = 4.5; // higher = snappier, lower = lazier glide
    final k = 1 - math.exp(-stiffness * dt);
    final next = current + (target - current) * k;
    if ((next - current).abs() > 0.02) {
      _scroll.jumpTo(next);
    }
  }

  void _tapLine(int i, SyncedLyricLine line) {
    if (line.isEmpty) return;
    // P61: seek to the OFFSET-CORRECTED moment — the same arithmetic
    // indexFor uses to decide which line is active. Seeking to the raw
    // stamp would disagree with the highlight by exactly [offset:].
    _c.seek(_doc!.seekTargetFor(line));
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
    final editEntry = _canEdit; // trailing "edit timings" affordance
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
            itemCount: lines.length + (editEntry ? 1 : 0),
            itemBuilder: (context, i) {
              // Trailing affordance: a curator who can already see timings
              // can now fix them without leaving the hymn (P61 — the editor
              // used to be reachable ONLY when no timings existed, so a bad
              // timing was unfixable from the app).
              if (i == lines.length) {
                return Padding(
                  padding: const EdgeInsets.only(top: 18, bottom: 6),
                  child: Center(
                    child: TextButton.icon(
                      onPressed: _openSyncEditor,
                      icon: const Icon(Icons.timer_outlined,
                          size: 18, color: Parchment.bronze),
                      label: const Text('Edit timings',
                          style: TextStyle(
                              color: Parchment.bronze,
                              fontWeight: FontWeight.w700)),
                      style: TextButton.styleFrom(
                        foregroundColor: Parchment.bronze,
                        padding: const EdgeInsets.symmetric(
                            horizontal: 16, vertical: 10),
                      ),
                    ),
                  ),
                );
              }
              final line = lines[i];
              final isEmpty = line.isEmpty;
              // Pure distance rule (never changing font size in layout)
              // drives scale/opacity/active. Reading mode uses the flat
              // profile so nothing shrinks and all lines stay fully readable.
              final e = LyricEmphasis.forIndex(
                  index: i, active: _active, profile: profile);
              return KeyedSubtree(
                key: _keys[i],
                child: Semantics(
                  // The seek gesture must exist for assistive tech, not just
                  // for a finger.
                  button: true,
                  onTap: isEmpty ? null : () => _tapLine(i, line),
                  child: GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    onTap: isEmpty ? null : () => _tapLine(i, line),
                    // RepaintBoundary isolates each line so animating one can
                    // never repaint the whole list (long bodies on low-end).
                    child: RepaintBoundary(
                      child: Padding(
                        padding: EdgeInsets.symmetric(
                            vertical: reading ? 13 : 10),
                        // P61 — ONE tween per line (its distance from the
                        // active line) drives scale, opacity, colour AND
                        // weight. Three parallel implicit animations with the
                        // same duration still read as three motions when one
                        // of them (weight) relayouts text mid-flight; a single
                        // value cannot desynchronise from itself. Endpoints
                        // are exactly LyricEmphasis's, because the profile's
                        // scaleFor/opacityFor are the same functions forIndex
                        // evaluated.
                        child: TweenAnimationBuilder<double>(
                          tween: Tween<double>(end: e.distance.toDouble()),
                          duration: anim,
                          curve: _curve,
                          builder: (context, d, _) {
                            final scale = profile.scaleFor(d);
                            final opacity = profile.opacityFor(d);
                            // 1.0 while sung → 0 by one line away; colour and
                            // weight ride the same value as size and fade.
                            final activity = (1.0 - d).clamp(0.0, 1.0);
                            final base = TextStyle(
                              // Sung line = darkest, boldest ink; the rest
                              // recede to a warm bronze so the hierarchy is
                              // obvious. Colour and weight ride the same
                              // tween as scale/opacity — one motion.
                              color:
                                  Color.lerp(_restInk, _activeInk, activity)!,
                              fontWeight:
                                  FontWeight.lerp(FontWeight.w500,
                                          FontWeight.w800, activity) ??
                                      FontWeight.w500,
                              fontSize: size,
                              height: lineHeight,
                              fontFamily: 'NotoSansEthiopic',
                            );
                            // P63 — word-level sweep: chunks of the sung
                            // line light up as they are sung. COLOUR-only
                            // per chunk (never size or weight), so the
                            // sweep cannot re-shape the line and the
                            // one-row FittedBox guarantee is untouched.
                            // Reading mode keeps steady single-colour text
                            // (its whole promise is less motion).
                            final Widget text;
                            if (!reading &&
                                !isEmpty &&
                                i == _active &&
                                _active >= 0 &&
                                line.words.isNotEmpty) {
                              text = Text.rich(
                                TextSpan(
                                  style: base,
                                  children: [
                                    for (var c = 0; c < line.words.length; c++)
                                      TextSpan(
                                        text: line.words[c].text,
                                        style: TextStyle(
                                          color: c < _activeSung
                                              ? _activeInk
                                              : _sweepPendingInk,
                                        ),
                                      ),
                                  ],
                                ),
                                softWrap: false,
                                maxLines: 1,
                                overflow: TextOverflow.visible,
                                textAlign: TextAlign.center,
                              );
                            } else {
                              text = Text(
                                isEmpty ? '· · ·' : line.text,
                                // Reading mode wraps at full width (large
                                // accessibility text must not be shrunk by a
                                // FittedBox); karaoke mode keeps the one-row
                                // guarantee via softWrap:false + FittedBox.
                                softWrap: reading,
                                maxLines: reading ? null : 1,
                                overflow: reading
                                    ? TextOverflow.clip
                                    : TextOverflow.visible,
                                textAlign: TextAlign.center,
                                style: base,
                              );
                            }
                            if (reading) {
                              return Opacity(opacity: opacity, child: text);
                            }
                            // Scale is a PAINT transform, never a font-size
                            // change: the Amharic glyphs never re-shape for
                            // size, the FittedBox keeps every line on exactly
                            // one row, and the whole fitted line scales as
                            // one unit on the GPU.
                            return Opacity(
                              opacity: opacity,
                              child: Transform.scale(
                                scale: scale,
                                child: FittedBox(
                                  fit: BoxFit.scaleDown,
                                  alignment: Alignment.center,
                                  child: text,
                                ),
                              ),
                            );
                          },
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
