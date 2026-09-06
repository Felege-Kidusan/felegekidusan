import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/hymn_store.dart';
import '../../services/lrc_builder.dart';
import '../../services/local_db.dart';
import '../../services/mezmur_audio_player.dart';
import 'parchment_style.dart';

/// P48 — mobile lyric timing editor (tap-to-sync).
/// P61 — parchment restyle, scrubber, offset-aware round-trip.
///
/// ## Separation of concerns
///
/// This file owns **pixels only**. Every rule about what a valid timing
/// is lives in [LrcBuilder] (pure, unit-tested); persistence and the
/// offline outbox live in [HymnStore]. A future redesign of this screen
/// therefore cannot change what is written to the server, and the timing
/// rules can be reused by any other surface.
///
/// ## Interaction
///
/// The industry-standard model used by every LRC tool (and by the web
/// console in this project): play the audio and tap once as each line
/// begins. Typing timestamps by hand is not viable — a 70-line hymn
/// takes an hour and still drifts. A scrubber and ±0.2 s nudges cover
/// the fine work; tapping a stamped time auditions that exact moment.
///
/// ## Offset round-trip (P61)
///
/// A stored document may carry an `[offset:±ms]` header (the server
/// preserves it). Its meaning, shared with `SyncedLyrics.indexFor`, is
/// "the line at raw time T is HEARD at T + offset". This editor works in
/// playback time — what the curator hears and stamps against — so on
/// load every existing stamp is shifted by +offset, and on save shifted
/// back. Without this, one re-save silently dragged every timing by the
/// offset and dropped the header.
///
/// ## Offline
///
/// Saving goes through [HymnStore.saveSyncedLyrics], which writes the
/// local row first and queues the upload in `pending_hymn_ops`. Work is
/// durable the moment Save is pressed, even with no connection.
///
/// ## Style
///
/// Parchment family (P61): the editor used to be a stock Material screen
/// in the app's maroon — a visual system shock when pushed from inside
/// the parchment player. It now speaks the same cream / ink / bronze /
/// gold vocabulary as the player, mini-player and bottom sheets.
class MezmurLyricsSyncScreen extends StatefulWidget {
  final MezmurTrack track;
  const MezmurLyricsSyncScreen({super.key, required this.track});

  @override
  State<MezmurLyricsSyncScreen> createState() =>
      _MezmurLyricsSyncScreenState();
}

class _MezmurLyricsSyncScreenState extends State<MezmurLyricsSyncScreen> {
  final MezmurAudioPlayerController _c = MezmurAudioPlayerController.instance;
  final HymnStore _store = HymnStore();
  final ScrollController _scroll = ScrollController();

  List<LrcLine> _lines = const [];
  List<GlobalKey> _keys = const [];
  int _cursor = 0;
  bool _loading = true;
  bool _saving = false;
  bool _dirty = false;

  /// The document's `[offset:]` header at load; existing stamps are baked
  /// into playback time (+offset) for editing and unbaked (−offset) on
  /// save. See the class doc.
  int _offsetMs = 0;

  /// P63 — the word-timing warning shows once per session, not per reload.
  bool _warnedWordTimings = false;

  @override
  void initState() {
    super.initState();
    _load();
    // No wall-clock ticker here (P61): the screen rebuilds only on real
    // interactions; the transport is a self-updating island (see
    // _Transport) so the clock and scrubber never re-run this build.
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final db = LocalDb();
    final row = await db.getLocalHymn(widget.track.hymnId);
    final staticText = (row?['lyrics'] as String?) ?? widget.track.lyrics ?? '';
    final existing =
        (row?['lyrics_synced'] as String?) ?? widget.track.lyricsSynced ?? '';

    var lines = LrcBuilder.linesFrom(staticText);
    if (existing.trim().isNotEmpty) {
      // P63 — say it BEFORE the curator invests work: this editor is a
      // line-level tool, and saving a word-timed document drops the
      // word-level detail (line timings are kept).
      if (mounted && !_warnedWordTimings &&
          LrcBuilder.hasWordTimings(existing)) {
        _warnedWordTimings = true;
        _warnWordTimings();
      }
      // Bake the offset into playback time so pre-existing stamps line up
      // with what the curator hears (and with the highlighting engine).
      _offsetMs = LrcBuilder.offsetOf(existing);
      lines = LrcBuilder.applyExisting(
          lines,
          LrcBuilder.shiftAll(LrcBuilder.parse(existing),
              Duration(milliseconds: _offsetMs)));
    }
    if (!mounted) return;
    setState(() {
      _lines = lines;
      _keys = List<GlobalKey>.generate(lines.length, (_) => GlobalKey());
      _cursor = LrcBuilder.nextIndex(lines);
      _loading = false;
    });
  }

  /// P63 — informational, non-blocking: the list keeps loading while the
  /// curator reads it. Line timings load and save normally; only the
  /// word-level detail is out of this editor's scope.
  void _warnWordTimings() {
    showDialog<void>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Word-level timings found'),
        content: const Text(
            'These lyrics carry word-by-word timings, which this editor '
            'does not support. The line timings are loaded and can be '
            'edited, but saving will remove the word-level detail.'),
        actions: [
          FilledButton(
              onPressed: () => Navigator.pop(c), child: const Text('Got it')),
        ],
      ),
    );
  }

  void _stampHere() {
    if (_cursor >= _lines.length) return;
    HapticFeedback.selectionClick(); // confirms the tap without a glance
    setState(() {
      _lines = LrcBuilder.stamp(_lines, _cursor, _c.position);
      _cursor = (_cursor + 1).clamp(0, _lines.length);
      _dirty = true;
    });
    _scrollTo(_cursor);
  }

  void _stepBack() {
    if (_cursor <= 0) return;
    setState(() {
      _cursor -= 1;
      _lines = List<LrcLine>.of(_lines)
        ..[_cursor] = _lines[_cursor].copyWith(clearAt: true);
      _dirty = true;
    });
    _scrollTo(_cursor);
  }

  void _nudge(int i, int ms) {
    setState(() {
      _lines = LrcBuilder.nudge(_lines, i, Duration(milliseconds: ms));
      _dirty = true;
    });
  }

  void _scrollTo(int i) {
    if (i < 0 || i >= _keys.length) return;
    final ctx = _keys[i].currentContext;
    if (ctx == null) return;
    Scrollable.ensureVisible(ctx,
        alignment: 0.4,
        duration: const Duration(milliseconds: 280),
        curve: Curves.easeOutCubic);
  }

  Future<void> _clearAll() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Clear all timings?'),
        content: const Text(
            'Every timestamp for this hymn will be removed. The lyrics '
            'themselves are not affected.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Clear')),
        ],
      ),
    );
    if (ok != true) return;
    setState(() {
      _lines = LrcBuilder.clearAll(_lines);
      _cursor = 0;
      _dirty = true;
    });
  }

  Future<void> _save() async {
    if (_saving) return;
    setState(() => _saving = true);
    // Un-bake playback time back to document (raw stamp) time.
    final lrc = LrcBuilder.build(LrcBuilder.shiftAll(
        _lines, Duration(milliseconds: -_offsetMs)));
    try {
      // Local-first: durable before the network is even attempted.
      await _store.saveSyncedLyrics(widget.track.hymnId, lrc);
      if (!mounted) return;
      final messenger = ScaffoldMessenger.of(context);
      final n = LrcBuilder.stampedCount(_lines);
      messenger.showSnackBar(SnackBar(
        content: Text(n == 0
            ? 'Timings cleared. Saved on this device — uploading when online.'
            : 'Saved $n timed line${n == 1 ? '' : 's'} on this device — '
                'uploading when online.'),
      ));
      setState(() => _dirty = false);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Could not save: $e')));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<bool> _confirmDiscard() async {
    if (!_dirty) return true;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Discard timings?'),
        content: const Text('You have unsaved timing changes.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Keep editing')),
          FilledButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Discard')),
        ],
      ),
    );
    return ok == true;
  }

  @override
  Widget build(BuildContext context) {
    final done = LrcBuilder.stampedCount(_lines);
    final total = _lines.length;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        final discard = await _confirmDiscard();
        if (!discard) return;
        if (!context.mounted) return;
        Navigator.of(context).pop();
      },
      child: Scaffold(
        // Parchment family (P61): the same cream the player's sheets and
        // the mini player use — this screen is pushed from inside the
        // parchment player and must not feel like a different app.
        backgroundColor: const Color(0xFFF3E4C4),
        appBar: AppBar(
          backgroundColor: const Color(0xFFF3E4C4),
          elevation: 0,
          scrolledUnderElevation: 0,
          title: const Text(
            'Sync lyrics',
            style: TextStyle(
              fontFamily: 'NotoSansEthiopic',
              fontWeight: FontWeight.w800,
              color: Parchment.inkStrong,
            ),
          ),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(22),
            child: Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Text(
                '$done of $total lines timed',
                style: const TextStyle(
                  color: Parchment.inkFaint,
                  fontWeight: FontWeight.w600,
                  fontSize: 12,
                ),
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: _saving || _lines.isEmpty ? null : _save,
              child: _saving
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('Save',
                      style: TextStyle(
                        color: Parchment.bronze,
                        fontWeight: FontWeight.w800,
                      )),
            ),
          ],
        ),
        body: _loading
            ? const Center(
                child: CircularProgressIndicator(color: Parchment.bronze))
            : _lines.isEmpty
                ? const _EmptyLyrics()
                : Column(
                    children: [
                      _Transport(
                        controller: _c,
                        fallbackDurationSeconds:
                            widget.track.durationSeconds,
                      ),
                      Expanded(
                        child: ListView.builder(
                          controller: _scroll,
                          padding: const EdgeInsets.only(bottom: 120),
                          itemCount: _lines.length,
                          itemBuilder: (ctx, i) => _LineRow(
                            key: _keys[i],
                            line: _lines[i],
                            isCursor: i == _cursor,
                            onTapTime: _lines[i].isStamped
                                ? () {
                                    _c.seek(_lines[i].at!);
                                    setState(() => _cursor = i);
                                  }
                                : null,
                            onNudge: _lines[i].isStamped
                                ? (ms) => _nudge(i, ms)
                                : null,
                          ),
                        ),
                      ),
                    ],
                  ),
        bottomNavigationBar: _loading || _lines.isEmpty
            ? null
            : _StampBar(
                enabled: _cursor < _lines.length,
                onStamp: _stampHere,
                onBack: _cursor > 0 ? _stepBack : null,
                onClear: done > 0 ? _clearAll : null,
              ),
      ),
    );
  }
}

class _EmptyLyrics extends StatelessWidget {
  const _EmptyLyrics();
  @override
  Widget build(BuildContext context) => const Padding(
        padding: EdgeInsets.all(32),
        child: Center(
          child: Text(
            'This hymn has no lyrics to time yet.\n\n'
            'Add the lyrics first, then come back to time them.',
            textAlign: TextAlign.center,
            style: TextStyle(
              color: Parchment.ink,
              height: 1.6,
              fontFamily: 'NotoSansEthiopic',
            ),
          ),
        ),
      );
}

/// Play/pause, ±5 s, a scrubber and a tenth-second clock.
///
/// P61: self-updating island. The screen no longer rebuilds on a wall-clock
/// timer (it used to setState the WHOLE screen every 200 ms, list included,
/// even while paused). The transport now ticks itself at 100 ms — enough for
/// a truthful tenths digit — and skips the frame entirely when nothing
/// visible has changed (i.e. whenever audio is paused).
class _Transport extends StatefulWidget {
  final MezmurAudioPlayerController controller;
  final int? fallbackDurationSeconds;
  const _Transport({
    required this.controller,
    this.fallbackDurationSeconds,
  });

  @override
  State<_Transport> createState() => _TransportState();
}

class _TransportState extends State<_Transport> {
  MezmurAudioPlayerController get _c => widget.controller;
  Timer? _tick;
  double? _dragMs;

  // Last-rendered values — the tick compares against these so a paused
  // player schedules zero frames.
  String _lastClock = '';
  bool _lastPlaying = false;
  double _lastPosMs = -1;

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(milliseconds: 100), (_) {
      if (!mounted) return;
      // Rebuild only when something the user can see actually moved: the
      // clock's tenth digit, the scrubber thumb, or the play glyph.
      // Paused ⇒ nothing changes ⇒ no frame is ever scheduled.
      final clock = _clock(_c.position);
      final pos = _dragMs ?? _posMs;
      final playing = _c.playing;
      if (clock == _lastClock &&
          playing == _lastPlaying &&
          (pos - _lastPosMs).abs() < 1.0) {
        return;
      }
      _lastClock = clock;
      _lastPlaying = playing;
      _lastPosMs = pos;
      setState(() {});
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  int get _totalMs {
    final d = _c.duration;
    if (d != null && d.inMilliseconds > 0) return d.inMilliseconds;
    return (widget.fallbackDurationSeconds ?? 0) * 1000;
  }

  double get _posMs {
    final total = _totalMs;
    var p = _c.position.inMilliseconds.toDouble();
    if (p < 0) p = 0;
    if (total > 0 && p > total) p = total.toDouble();
    return p;
  }

  static String _clock(Duration d) {
    final m = d.inMinutes;
    final s = d.inSeconds % 60;
    final t = (d.inMilliseconds % 1000) ~/ 100;
    return '$m:${s.toString().padLeft(2, '0')}.$t';
  }

  @override
  Widget build(BuildContext context) {
    final totalMs = _totalMs;
    final posMs = _dragMs ?? _posMs;
    final playing = _c.playing;
    return Container(
      padding: const EdgeInsets.fromLTRB(8, 6, 12, 8),
      decoration: const BoxDecoration(
        color: Color(0xFFEAD8B2),
        border: Border(
          bottom: BorderSide(color: Parchment.bronzeSoft, width: 0.5),
        ),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              IconButton(
                onPressed: () =>
                    _c.seekBy(const Duration(seconds: -5)),
                icon: const Icon(Icons.replay_5, color: Parchment.ink),
                tooltip: 'Back 5 seconds',
              ),
              IconButton.filled(
                onPressed: () => _c.playing ? _c.pause() : _c.play(),
                icon: Icon(playing ? Icons.pause : Icons.play_arrow),
                style: const ButtonStyle(
                  backgroundColor:
                      WidgetStatePropertyAll(Parchment.bronze),
                  foregroundColor:
                      WidgetStatePropertyAll(Color(0xFFF8EBCB)),
                ),
                tooltip: playing ? 'Pause' : 'Play',
              ),
              IconButton(
                onPressed: () => _c.seekBy(const Duration(seconds: 5)),
                icon: const Icon(Icons.forward_5, color: Parchment.ink),
                tooltip: 'Forward 5 seconds',
              ),
              const Spacer(),
              Text(
                _clock(_c.position),
                style: const TextStyle(
                  color: Parchment.inkStrong,
                  fontWeight: FontWeight.w800,
                  fontSize: 15,
                  fontFeatures: [FontFeature.tabularFigures()],
                ),
              ),
            ],
          ),
          // Scrubber (P61): timing work needs positional context — where
          // the intro ends, how far to the bridge, how long is left. The
          // editor previously had play/±5 only; locating a moment meant
          // tapping stamped chips one by one.
          if (totalMs > 0)
            SliderTheme(
              data: SliderTheme.of(context).copyWith(
                trackHeight: 3,
                activeTrackColor: Parchment.bronze,
                inactiveTrackColor: Parchment.bronzeSoft.withValues(alpha: 0.3),
                thumbColor: Parchment.inkStrong,
                overlayColor: Parchment.gold.withValues(alpha: 0.16),
                thumbShape:
                    const RoundSliderThumbShape(enabledThumbRadius: 6),
                overlayShape:
                    const RoundSliderOverlayShape(overlayRadius: 12),
              ),
              child: Slider(
                min: 0,
                max: totalMs.toDouble(),
                value: posMs.clamp(0, totalMs.toDouble()),
                onChangeStart: (_) =>
                    setState(() => _dragMs = _posMs),
                onChanged: (v) => setState(() => _dragMs = v),
                onChangeEnd: (v) {
                  _c.seek(Duration(milliseconds: v.round()));
                  setState(() => _dragMs = null);
                },
              ),
            ),
        ],
      ),
    );
  }
}

class _LineRow extends StatelessWidget {
  final LrcLine line;
  final bool isCursor;
  final VoidCallback? onTapTime;
  final void Function(int ms)? onNudge;
  const _LineRow({
    super.key,
    required this.line,
    required this.isCursor,
    this.onTapTime,
    this.onNudge,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: isCursor
            ? Parchment.honey.withValues(alpha: 0.35)
            : null,
        // Colour is never the only signal (WCAG 1.4.1): the pending line
        // also carries a leading bar.
        border: Border(
          left: BorderSide(
            width: 4,
            color: isCursor ? Parchment.bronze : Colors.transparent,
          ),
          bottom: BorderSide(
              color: Parchment.bronzeSoft.withValues(alpha: 0.3),
              width: 0.5),
        ),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          // 48 dp tall (P61): the tappable timestamp used to be a bare
          // text glyph — visually 20 dp tall, far under the Material
          // minimum touch target.
          SizedBox(
            width: 68,
            height: 48,
            child: Align(
              alignment: Alignment.centerLeft,
              child: GestureDetector(
                onTap: onTapTime,
                child: Semantics(
                  button: true,
                  label: line.isStamped
                      ? 'Audition from ${_fmt(line.at!)}'
                      : 'Not timed yet',
                  child: Text(
                    line.isStamped ? _fmt(line.at!) : '—',
                    style: TextStyle(
                      fontFeatures: const [FontFeature.tabularFigures()],
                      fontWeight:
                          line.isStamped ? FontWeight.w700 : FontWeight.w400,
                      color: line.isStamped
                          ? Parchment.bronze
                          : Parchment.inkFaint,
                      decoration:
                          onTapTime != null ? TextDecoration.underline : null,
                    ),
                  ),
                ),
              ),
            ),
          ),
          Expanded(
            child: Text(
              line.text,
              style: TextStyle(
                color: isCursor ? Parchment.inkStrong : Parchment.ink,
                fontWeight: isCursor ? FontWeight.w800 : FontWeight.w600,
                fontFamily: 'NotoSansEthiopic',
                height: 1.4,
              ),
            ),
          ),
          if (onNudge != null) ...[
            _NudgeBtn(label: '−', onStep: () => onNudge!(-200)),
            const SizedBox(width: 4),
            _NudgeBtn(label: '+', onStep: () => onNudge!(200)),
          ],
        ],
      ),
    );
  }

  static String _fmt(Duration d) {
    final m = d.inMinutes;
    final s = d.inSeconds % 60;
    final t = (d.inMilliseconds % 1000) ~/ 100;
    return '$m:${s.toString().padLeft(2, '0')}.$t';
  }
}

class _NudgeBtn extends StatefulWidget {
  final String label;
  final VoidCallback onStep;
  const _NudgeBtn({required this.label, required this.onStep});

  @override
  State<_NudgeBtn> createState() => _NudgeBtnState();
}

class _NudgeBtnState extends State<_NudgeBtn> {
  Timer? _repeat;

  void _press() {
    widget.onStep(); // the first step lands immediately
    HapticFeedback.selectionClick();
    _repeat?.cancel();
    // P62 — press-and-hold: a short pause, then a steady rhythm. Nudging
    // a stamp by a second used to mean five separate taps; holding the
    // button now walks it in 200 ms steps at ~11 Hz.
    _repeat = Timer(const Duration(milliseconds: 380), () {
      _repeat = Timer.periodic(
          const Duration(milliseconds: 90), (_) => widget.onStep());
    });
  }

  void _release() {
    _repeat?.cancel();
    _repeat = null;
  }

  @override
  void dispose() {
    _repeat?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // 48×48 dp (P61): Material's actual minimum touch target — the old
    // 40×40 claimed to be the minimum in a comment; it is not (M3 says
    // 48, iOS HIG 44). These are tapped dozens of times per hymn.
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: (_) => _press(),
      onTapUp: (_) => _release(),
      onTapCancel: _release,
      child: SizedBox(
        width: 48,
        height: 48,
        child: Center(
          child: Text(widget.label,
              style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w800,
                  color: Parchment.bronze)),
        ),
      ),
    );
  }
}

class _StampBar extends StatelessWidget {
  final bool enabled;
  final VoidCallback onStamp;
  final VoidCallback? onBack;
  final VoidCallback? onClear;
  const _StampBar({
    required this.enabled,
    required this.onStamp,
    this.onBack,
    this.onClear,
  });

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
        child: Row(
          children: [
            IconButton(
              onPressed: onBack,
              icon: const Icon(Icons.undo, color: Parchment.ink),
              tooltip: 'Step back a line',
            ),
            IconButton(
              onPressed: onClear,
              icon: const Icon(Icons.restart_alt, color: Parchment.ink),
              tooltip: 'Clear all timings',
            ),
            const SizedBox(width: 8),
            Expanded(
              child: SizedBox(
                height: 56, // large, glanceable target — this is tapped
                child: FilledButton.icon( // once per line while listening
                  onPressed: enabled ? onStamp : null,
                  icon: const Icon(Icons.touch_app),
                  label: Text(enabled ? 'Tap on the line' : 'All lines timed'),
                  style: FilledButton.styleFrom(
                    // Gold + dark ink: the player's play-button vocabulary,
                    // not the app-wide maroon (P61 style unification).
                    backgroundColor: Parchment.gold,
                    foregroundColor: const Color(0xFF4A2C0C),
                    disabledBackgroundColor:
                        Parchment.bronzeSoft.withValues(alpha: 0.35),
                    disabledForegroundColor:
                        Parchment.inkFaint.withValues(alpha: 0.6),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
