import 'dart:async';
import 'dart:math' as math;
import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/scheduler.dart';

import '../../services/karaoke_engine.dart';
import '../../services/karaoke_style.dart';
import '../../services/lyrics_reader_settings.dart';
import '../../services/mezmur_audio_player.dart';
import '../../services/mezmur_synced_lyrics.dart';
import 'parchment_style.dart';

/// Karaoke V2 (P64) — the synced-lyrics renderer, rebuilt from scratch.
///
/// Design: docs/mezmur_player/KARAOKE_V2_SPEC.md. This widget owns every
/// pixel of the karaoke experience:
///
///   * **Progressive fill** — the active line fills left-to-right as it
///     is sung: per word for enhanced-LRC documents, interpolated across
///     the whole line for plain line-timed ones (every existing hymn gets
///     the karaoke fill with zero re-authoring). Fills are PAINT-ONLY:
///     the engine snapshot lives in a ValueNotifier wired to the active
///     line's painter, so a fill tick repaints one small CustomPaint —
///     no setState, no rebuild, no relayout, ever.
///   * **Depth** — opacity + gentle blur carry the falloff (asymmetric:
///     past lines recede harder than future ones); scale is deliberately
///     subtle. All of it tweens from ONE value per line (its distance),
///     and everything is a paint transform: the Amharic glyphs never
///     re-shape and a line can never re-wrap mid-hymn.
///   * **Anchored predictive scroll** — the active line sits at 34% of
///     the stage, and the glide toward the NEXT line begins shortly
///     before it is due, so lines settle into place as they begin.
///   * **Scroll-to-read** — while the user browses, the depth treatment
///     lifts (no blur, opacity ≥ 0.85) so every line is plainly
///     readable; a resume pill hands control back (auto-resumes while
///     playing; paused users browse freely).
///
/// Timing truth lives in [KaraokeEngine] (pure), visual rules in
/// [KaraokeProfile] (pure); this file is pixels only, following the
/// department's separation of concerns.
class MezmurKaraokeView extends StatefulWidget {
  final SyncedLyrics doc;

  /// Shown at the end of the lyrics when non-null (the curator's re-entry
  /// point; the screen owns the editor flow).
  final VoidCallback? onEditTimings;

  const MezmurKaraokeView({super.key, required this.doc, this.onEditTimings});

  @override
  State<MezmurKaraokeView> createState() => _MezmurKaraokeViewState();
}

class _MezmurKaraokeViewState extends State<MezmurKaraokeView>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  final MezmurAudioPlayerController _c =
      MezmurAudioPlayerController.instance;
  final ScrollController _scroll = ScrollController();
  final LyricsReaderSettings _reader = LyricsReaderSettings.instance;

  /// The engine snapshot. The ACTIVE line's painter listens to this and
  /// repaints on change — fills never go through setState.
  final ValueNotifier<KaraokeFrame?> _frame = ValueNotifier<KaraokeFrame?>(null);

  StreamSubscription<Duration>? _posSub;
  late final Ticker _glide;
  Timer? _resumeTimer;

  List<GlobalKey> _keys = const [];
  int _active = -1;
  int _targetLine = -1;
  bool _wasPlaying = false;
  bool _foreground = true;
  bool _userHold = false;
  Duration _lastTick = Duration.zero;
  double _target = 0;

  static const double _anchor = 0.34; // active line sits 34% down the stage
  static const Duration _anim = Duration(milliseconds: 340);
  static const double _baseLyricSize = 17;

  @override
  void initState() {
    super.initState();
    _keys = _makeKeys();
    // Event-driven, exactly like the engine demands: position samples
    // only while audio advances (the stream is silent while paused), and
    // the controller's ChangeNotifier covers the edges the stream cannot
    // see (play/pause, seek, track change).
    _posSub = _c.positionStream.listen((_) => _updateFrame());
    _c.addListener(_onControllerChanged);
    WidgetsBinding.instance.addObserver(this);
    _glide = createTicker(_onGlideTick);
    _reader.addListener(_onReaderChanged);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _updateFrame(force: true);
    });
  }

  @override
  void didUpdateWidget(MezmurKaraokeView old) {
    super.didUpdateWidget(old);
    if (old.doc != widget.doc) {
      _userHold = false;
      _resumeTimer?.cancel();
      _keys = _makeKeys();
      _active = -1;
      _targetLine = -1;
      _frame.value = null;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _updateFrame(force: true);
      });
    }
  }

  @override
  void dispose() {
    _posSub?.cancel();
    _c.removeListener(_onControllerChanged);
    WidgetsBinding.instance.removeObserver(this);
    _reader.removeListener(_onReaderChanged);
    _resumeTimer?.cancel();
    _glide.dispose();
    _frame.dispose();
    _scroll.dispose();
    super.dispose();
  }

  List<GlobalKey> _makeKeys() =>
      List<GlobalKey>.generate(widget.doc.lines.length, (_) => GlobalKey());

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    _foreground = s == AppLifecycleState.resumed;
    if (_foreground && mounted) _updateFrame(force: true);
  }

  // ── timing ─────────────────────────────────────────────────────────

  void _onControllerChanged() {
    if (!mounted) return;
    final playing = _c.playing;
    if (playing != _wasPlaying) {
      _wasPlaying = playing;
      if (playing) {
        _lastTick = Duration.zero;
        if (!_glide.isActive) _glide.start();
        if (_userHold) {
          // Playback resumed while the user was browsing: arm the
          // auto-resume instead of leaving them disengaged forever.
          _resumeTimer?.cancel();
          _resumeTimer = Timer(const Duration(milliseconds: 3500), _resumeFollow);
        }
      } else {
        _glide.stop();
      }
    }
    _updateFrame();
  }

  void _updateFrame({bool force = false}) {
    if (!mounted || !_foreground) return;
    final f = KaraokeEngine.frameFor(widget.doc, _c.position);
    final lineChanged = f.activeIndex != _active;
    // Fill updates are paint-only: the active line's painter repaints via
    // the notifier; the widget tree does not rebuild.
    _frame.value = f;
    if (lineChanged) {
      _targetLine = f.activeIndex;
      setState(() => _active = f.activeIndex);
      _afterFrame(() {
        if (mounted) _retarget(f.activeIndex, instant: force);
      });
      return;
    }
    // Predictive glide: start easing toward the NEXT line shortly before
    // it is due, so it settles into the anchor as it begins.
    if (_c.playing && !_userHold && f.activeIndex >= 0) {
      final next = f.nextLineStartAt;
      if (next != null) {
        final untilMs = next.inMilliseconds - _c.position.inMilliseconds;
        if (untilMs > 0 && untilMs <= _leadMs(f.activeIndex, next)) {
          final nextLine = f.activeIndex + 1;
          if (_targetLine != nextLine) {
            _targetLine = nextLine;
            _afterFrame(() {
              if (mounted) _retarget(nextLine, instant: false);
            });
          }
        }
      }
    }
  }

  /// How early the glide toward the next line begins: 45% of the line
  /// gap, capped at 650 ms (and never absurdly short).
  int _leadMs(int activeIndex, Duration nextStart) {
    final cur = widget.doc.lines[activeIndex].time.inMilliseconds +
        widget.doc.offsetMs;
    final gap = nextStart.inMilliseconds - cur;
    if (gap <= 0) return 150;
    return math.min(650, (gap * 0.45).round());
  }

  void _afterFrame(VoidCallback fn) =>
      WidgetsBinding.instance.addPostFrameCallback((_) => fn());

  // ── scroll ─────────────────────────────────────────────────────────

  /// Computes the offset that puts [line] at the anchor and either jumps
  /// or lets the glide ticker ease toward it (exponential, frame-rate
  /// independent — the settle naturally takes longer for longer jumps).
  void _retarget(int line, {required bool instant}) {
    if (!_scroll.hasClients) return;
    if (line < 0 || line >= _keys.length) return;
    final ro = _keys[line].currentContext?.findRenderObject();
    if (ro == null || ro is! RenderBox) return;
    final vp = RenderAbstractViewport.maybeOf(ro);
    if (vp == null) return;
    try {
      final reveal = vp.getOffsetToReveal(ro, _anchor);
      final max = _scroll.position.maxScrollExtent;
      final off = reveal.offset.clamp(0.0, max);
      _target = off;
      final reduce =
          MediaQuery.maybeOf(context)?.disableAnimations ?? false;
      if (instant || reduce) _scroll.jumpTo(off);
    } catch (_) {
      // Mid-layout frame: the ticker glides to the last target.
    }
  }

  void _onGlideTick(Duration elapsed) {
    if (!mounted || !_scroll.hasClients) return;
    final dt = (elapsed - _lastTick).inMicroseconds / 1e6;
    _lastTick = elapsed;
    if (dt <= 0) return;
    if (_userHold) return; // never fight the user's finger
    final max = _scroll.position.maxScrollExtent;
    final target = _target < 0
        ? 0
        : (_target > max ? max : _target);
    const stiffness = 4.0; // lower = lusher glide
    final k = 1 - math.exp(-stiffness * dt);
    final next = _scroll.offset + (target - _scroll.offset) * k;
    if ((next - _scroll.offset).abs() > 0.02) _scroll.jumpTo(next);
  }

  bool _onScrollNotified(ScrollNotification n) {
    if (n is ScrollStartNotification && n.dragDetails != null) {
      _resumeTimer?.cancel();
      if (!_userHold) {
        _userHold = true;
        setState(() {}); // pill in, scroll-to-read lift
      }
    } else if (n is ScrollEndNotification && _userHold) {
      _resumeTimer?.cancel();
      if (_c.playing) {
        _resumeTimer = Timer(const Duration(milliseconds: 3500), _resumeFollow);
      }
      // Paused: respect the browsing position; the pill stays.
    }
    return false;
  }

  void _resumeFollow() {
    if (!mounted) return;
    _resumeTimer?.cancel();
    _userHold = false;
    setState(() {});
    _afterFrame(() {
      if (mounted && _active >= 0) {
        _targetLine = _active;
        final reduce =
            MediaQuery.maybeOf(context)?.disableAnimations ?? false;
        // While paused the glide ticker is stopped — snap instead of
        // setting a target nothing will chase.
        _retarget(_active, instant: reduce || !_c.playing);
      }
    });
  }

  void _tapLine(int i, SyncedLyricLine line) {
    if (line.isEmpty) return;
    // Seek to the offset-corrected moment — the same arithmetic the
    // engine uses to decide what is active.
    _c.seek(widget.doc.seekTargetFor(line));
    _resumeTimer?.cancel();
    _userHold = false;
    _targetLine = i;
    // The stream confirms the new position a beat later; until then the
    // freshly-active line would paint the previous line's fills.
    _frame.value = null;
    setState(() => _active = i);
    _afterFrame(() {
      if (mounted) _retarget(i, instant: true);
    });
  }

  void _onReaderChanged() {
    if (!mounted) return;
    // Sizes/profiles change; the active line must stay at the anchor
    // under the new text size.
    setState(() {});
    _afterFrame(() {
      if (mounted && !_userHold && _active >= 0) {
        _retarget(_active, instant: true);
      }
    });
  }

  // ── build ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final rs = _reader;
    final reading = rs.readingMode;
    final profile = reading
        ? KaraokeProfile.reading
        : (rs.highContrast
            ? KaraokeProfile.highContrast
            : KaraokeProfile.spotify);
    final size = _baseLyricSize * rs.textScale;
    var lineHeight = 1.5;
    if (reading) lineHeight = 1.62;
    lineHeight += (rs.textScale - 1.0) * 0.12;
    final reduce = MediaQuery.of(context).disableAnimations;
    final anim = reduce ? Duration.zero : _anim;
    final restInk = rs.highContrast ? Parchment.inkStrong : Parchment.bronze;
    final pendingInk = rs.highContrast ? Parchment.ink : Parchment.bronzeSoft;
    const fillInk = Parchment.inkStrong;
    final lines = widget.doc.lines;

    return LayoutBuilder(builder: (context, box) {
      final h = box.maxHeight;
      return Stack(
        fit: StackFit.expand,
        children: [
          // Edge fades apply to the lyrics only — the resume pill floats
          // above them at full strength.
          ParchmentFade(
            child: NotificationListener<ScrollNotification>(
              onNotification: _onScrollNotified,
              child: ListView.builder(
                controller: _scroll,
                cacheExtent: 4000,
                // 36% top padding so the first line can reach the 34%
                // anchor; 30% bottom so the last line can too.
                padding: EdgeInsets.fromLTRB(6, h * 0.36, 6, h * 0.30),
                itemCount: lines.length + (widget.onEditTimings != null ? 1 : 0),
                itemBuilder: (context, i) {
                  if (i == lines.length) {
                    return Padding(
                      padding: const EdgeInsets.only(top: 18, bottom: 6),
                      child: Center(
                        child: TextButton.icon(
                          onPressed: widget.onEditTimings,
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
                  return _KaraokeLine(
                    key: _keys[i],
                    line: line,
                    distance: (i - _active).abs(),
                    past: _active >= 0 && i < _active,
                    isActive: i == _active,
                    profile: profile,
                    fontSize: size,
                    lineHeight: lineHeight,
                    reading: reading,
                    userHold: _userHold,
                    anim: anim,
                    frame: _frame,
                    restInk: restInk,
                    pendingInk: pendingInk,
                    fillInk: fillInk,
                    onTap: line.isEmpty ? null : () => _tapLine(i, line),
                  );
                },
              ),
            ),
          ),
          _buildPill(context),
        ],
      );
    });
  }

  /// The scroll-away affordance: tap to hand control back to the music.
  Widget _buildPill(BuildContext context) {
    final show = _userHold;
    return Positioned(
      left: 16,
      right: 16,
      bottom: 14,
      child: IgnorePointer(
        ignoring: !show,
        child: AnimatedOpacity(
          opacity: show ? 1.0 : 0.0,
          duration: const Duration(milliseconds: 180),
          child: AnimatedSlide(
            offset: show ? Offset.zero : const Offset(0, 0.5),
            duration: const Duration(milliseconds: 180),
            child: Center(
              child: Semantics(
                button: true,
                label: 'Return to the current line',
                child: Material(
                  color: const Color(0xF5F8EBCB),
                  elevation: 3,
                  shadowColor: const Color(0x66000000),
                  borderRadius: BorderRadius.circular(20),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(20),
                    onTap: _resumeFollow,
                    child: const Padding(
                      padding:
                          EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                      child: Icon(Icons.keyboard_arrow_down_rounded,
                          color: Parchment.bronze, size: 26),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// One lyric line: emphasis tween → blur → opacity → scale → fitted text.
///
/// Layout is cached in the State and only recomputed when the line's
/// content, style or ROLE changes (role = active vs receded, which fixes
/// the colours baked into the twin TextPainters). Fill ticks never touch
/// this widget — the active line's painter repaints through the frame
/// notifier alone.
class _KaraokeLine extends StatefulWidget {
  final SyncedLyricLine line;
  final int distance;
  final bool past;
  final bool isActive;
  final KaraokeProfile profile;
  final double fontSize;
  final double lineHeight;
  final bool reading;
  final bool userHold;
  final Duration anim;
  final ValueNotifier<KaraokeFrame?> frame;
  final Color restInk;
  final Color pendingInk;
  final Color fillInk;
  final VoidCallback? onTap;

  const _KaraokeLine({
    super.key,
    required this.line,
    required this.distance,
    required this.past,
    required this.isActive,
    required this.profile,
    required this.fontSize,
    required this.lineHeight,
    required this.reading,
    required this.userHold,
    required this.anim,
    required this.frame,
    required this.restInk,
    required this.pendingInk,
    required this.fillInk,
    required this.onTap,
  });

  @override
  State<_KaraokeLine> createState() => _KaraokeLineState();
}

class _KaraokeLineState extends State<_KaraokeLine> {
  TextPainter? _base;
  TextPainter? _fill;
  List<Rect?>? _boxes;
  Object? _sig;

  @override
  void dispose() {
    _base?.dispose();
    _fill?.dispose();
    super.dispose();
  }

  String get _text => widget.line.isEmpty ? '· · ·' : widget.line.text;

  void _ensureLayout(TextScaler scaler) {
    final baseColor = widget.isActive ? widget.pendingInk : widget.restInk;
    final sig = (
      line: widget.line,
      active: widget.isActive,
      size: widget.fontSize,
      height: widget.lineHeight,
      color: baseColor,
      fill: widget.fillInk,
      scaler: scaler,
    );
    if (_sig == sig && _base != null) return; // nothing changed
    _sig = sig;
    final style = TextStyle(
      color: baseColor,
      fontSize: widget.fontSize,
      height: widget.lineHeight,
      fontWeight: widget.isActive ? FontWeight.w800 : FontWeight.w500,
      fontFamily: 'NotoSansEthiopic',
    );
    final oldBase = _base;
    final oldFill = _fill;
    _base = TextPainter(
      text: TextSpan(text: _text, style: style),
      textDirection: TextDirection.ltr,
      textScaler: scaler,
    )..layout();
    _fill = widget.isActive
        ? (TextPainter(
            text: TextSpan(
                text: _text, style: style.copyWith(color: widget.fillInk)),
            textDirection: TextDirection.ltr,
            textScaler: scaler,
          )..layout())
        : null;
    if (widget.isActive && widget.line.words.isNotEmpty) {
      final spans = KaraokeEngine.wordCharSpans(widget.line.words);
      _boxes = [
        for (final s in spans) _unionBox(_base!, s.start, s.end),
      ];
    } else {
      _boxes = null;
    }
    oldBase?.dispose();
    oldFill?.dispose();
  }

  /// The horizontal extent of characters [start, end) — the region a
  /// word's fill clips to. Null when the range paints nothing (pure
  /// whitespace).
  Rect? _unionBox(TextPainter tp, int start, int end) {
    final boxes = tp.getBoxesForRange(start, end);
    if (boxes.isEmpty) return null;
    var left = boxes.first.left;
    var right = boxes.last.right;
    for (final b in boxes) {
      left = math.min(left, b.left);
      right = math.max(right, b.right);
    }
    return Rect.fromLTRB(left, boxes.first.top, right, boxes.first.bottom);
  }

  @override
  Widget build(BuildContext context) {
    final text = _text;
    return Semantics(
      // CustomPaint carries no text semantics — the line must speak for
      // itself, and the seek gesture must exist for assistive tech.
      button: true,
      label: widget.line.isEmpty ? 'Instrumental pause' : text,
      onTap: widget.onTap,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: widget.onTap,
        child: RepaintBoundary(
          child: Padding(
            padding: EdgeInsets.symmetric(
                vertical: widget.reading ? 13 : 10),
            // ONE tween per line over its distance drives opacity, scale
            // and blur — a value cannot desynchronise from itself (the
            // P61 lesson, kept).
            child: TweenAnimationBuilder<double>(
              tween: Tween<double>(end: widget.distance.toDouble()),
              duration: widget.anim,
              curve: Curves.easeInOutCubic,
              builder: (context, d, _) {
                final em = widget.profile.forDistance(d, past: widget.past);
                // Scroll-to-read: while the user browses, lift the depth
                // treatment so every line is plainly readable.
                final opacity = widget.userHold
                    ? math.max(em.opacity, 0.85)
                    : em.opacity;
                Widget content;
                if (widget.reading) {
                  content = Opacity(
                    opacity: opacity,
                    child: Text(
                      text,
                      softWrap: true,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: widget.isActive
                            ? widget.pendingInk
                            : widget.restInk,
                        fontSize: widget.fontSize,
                        height: widget.lineHeight,
                        fontWeight: widget.isActive
                            ? FontWeight.w800
                            : FontWeight.w500,
                        fontFamily: 'NotoSansEthiopic',
                      ),
                    ),
                  );
                } else {
                  _ensureLayout(MediaQuery.textScalerOf(context));
                  final base = _base!;
                  content = Opacity(
                    opacity: opacity,
                    child: Transform.scale(
                      scale: em.scale,
                      child: FittedBox(
                        fit: BoxFit.scaleDown,
                        alignment: Alignment.center,
                        child: CustomPaint(
                          size: base.size,
                          painter: _KaraokeFillPainter(
                            base: base,
                            fill: widget.isActive ? _fill : null,
                            boxes: widget.isActive ? _boxes : null,
                            frame: widget.isActive ? widget.frame : null,
                          ),
                        ),
                      ),
                    ),
                  );
                  // Distance blur — quantised so the raster cache
                  // survives, skipped entirely at ~0, in reading and
                  // high-contrast modes, and while the user scrolls.
                  if (em.sigma > 0.05 && !widget.userHold) {
                    final q = (em.sigma / 0.35).round() * 0.35;
                    if (q > 0.05) {
                      content = ImageFiltered(
                        imageFilter:
                            ImageFilter.blur(sigmaX: q, sigmaY: q),
                        child: content,
                      );
                    }
                  }
                }
                return content;
              },
            ),
          ),
        ),
      ),
    );
  }
}

/// Paints one line: the base (role-coloured) text always, plus — for the
/// ACTIVE line — the fill-coloured twin clipped to each word's progress
/// (or to the whole line's progress when there are no word timings).
///
/// Clips only: no saveLayer, no ShaderMask — the cheapest possible
/// progressive fill. The [frame] notifier is this painter's repaint
/// listenable, so fill ticks repaint without any widget involvement.
class _KaraokeFillPainter extends CustomPainter {
  final TextPainter base;
  final TextPainter? fill;
  final List<Rect?>? boxes;
  final ValueNotifier<KaraokeFrame?>? frame;

  _KaraokeFillPainter({
    required this.base,
    this.fill,
    this.boxes,
    this.frame,
  }) : super(repaint: frame);

  @override
  void paint(Canvas canvas, Size size) {
    base.paint(canvas, Offset.zero);
    final f = frame?.value;
    final fillTp = fill;
    if (f == null || fillTp == null) return;

    if (boxes != null &&
        boxes!.length == f.wordFills.length &&
        f.wordFills.isNotEmpty) {
      for (var i = 0; i < boxes!.length; i++) {
        final p = f.wordFills[i];
        if (p <= 0) continue;
        final r = boxes![i];
        if (r == null || r.width <= 0) continue;
        final right = p >= 1 ? r.right : r.left + r.width * p;
        if (right <= r.left) continue;
        canvas.save();
        canvas.clipRect(Rect.fromLTRB(r.left, 0, right, size.height));
        fillTp.paint(canvas, Offset.zero);
        canvas.restore();
      }
    } else {
      final p = f.lineFill;
      if (p > 0) {
        canvas.save();
        canvas.clipRect(
            Rect.fromLTRB(0, 0, size.width * p.clamp(0.0, 1.0), size.height));
        fillTp.paint(canvas, Offset.zero);
        canvas.restore();
      }
    }
  }

  @override
  bool shouldRepaint(_KaraokeFillPainter oldDelegate) =>
      oldDelegate.base != base ||
      oldDelegate.fill != fill ||
      oldDelegate.boxes != boxes ||
      oldDelegate.frame != frame;
}
