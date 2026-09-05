import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/hymn_store.dart';
import '../../services/lrc_builder.dart';
import '../../services/local_db.dart';
import '../../services/mezmur_audio_player.dart';
import '../../utils/theme.dart';

/// P48 — mobile lyric timing editor (tap-to-sync).
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
/// takes an hour and still drifts.
///
/// ## Offline
///
/// Saving goes through [HymnStore.saveSyncedLyrics], which writes the
/// local row first and queues the upload in `pending_hymn_ops`. Work is
/// durable the moment Save is pressed, even with no connection.
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
  Timer? _ticker;

  @override
  void initState() {
    super.initState();
    _load();
    // 200ms is enough for a readable clock without waking the UI thread
    // more often than a human can perceive.
    _ticker = Timer.periodic(const Duration(milliseconds: 200), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final db = LocalDb();
    final row = await db.getLocalHymn(widget.track.hymnId);
    final staticText =
        (row?['lyrics'] as String?) ?? widget.track.lyrics ?? '';
    final existing =
        (row?['lyrics_synced'] as String?) ?? widget.track.lyricsSynced ?? '';

    var lines = LrcBuilder.linesFrom(staticText);
    if (existing.trim().isNotEmpty) {
      lines = LrcBuilder.applyExisting(lines, LrcBuilder.parse(existing));
    }
    if (!mounted) return;
    setState(() {
      _lines = lines;
      _keys = List<GlobalKey>.generate(lines.length, (_) => GlobalKey());
      _cursor = LrcBuilder.nextIndex(lines);
      _loading = false;
    });
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
    final lrc = LrcBuilder.build(_lines);
    try {
      // Local-first: durable before the network is even attempted.
      await _store.saveSyncedLyrics(widget.track.hymnId, lrc);
      if (!mounted) return;
      final n = LrcBuilder.stampedCount(_lines);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(n == 0
            ? 'Timings cleared. Saved on this device — uploading when online.'
            : 'Saved $n timed line${n == 1 ? '' : 's'} on this device — '
                'uploading when online.'),
      ));
      setState(() => _dirty = false);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not save: $e')));
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

  static String _clock(Duration d) {
    final m = d.inMinutes;
    final s = d.inSeconds % 60;
    final t = (d.inMilliseconds % 1000) ~/ 100;
    return '$m:${s.toString().padLeft(2, '0')}.$t';
  }

  @override
  Widget build(BuildContext context) {
    final done = LrcBuilder.stampedCount(_lines);
    final total = _lines.length;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (await _confirmDiscard() && mounted) Navigator.of(context).pop();
      },
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Sync lyrics'),
          bottom: PreferredSize(
            preferredSize: const Size.fromHeight(22),
            child: Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Text('$done of $total lines timed',
                  style: Theme.of(context).textTheme.bodySmall),
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
                  : const Text('Save'),
            ),
          ],
        ),
        body: _loading
            ? const Center(child: CircularProgressIndicator())
            : _lines.isEmpty
                ? const _EmptyLyrics()
                : Column(
                    children: [
                      _Transport(
                        clock: _clock(_c.position),
                        playing: _c.playing,
                        onPlayPause: () => setState(() =>
                            _c.playing ? _c.pause() : _c.play()),
                        onBack5: () =>
                            _c.seekBy(const Duration(seconds: -5)),
                        onFwd5: () => _c.seekBy(const Duration(seconds: 5)),
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
          ),
        ),
      );
}

class _Transport extends StatelessWidget {
  final String clock;
  final bool playing;
  final VoidCallback onPlayPause;
  final VoidCallback onBack5;
  final VoidCallback onFwd5;
  const _Transport({
    required this.clock,
    required this.playing,
    required this.onPlayPause,
    required this.onBack5,
    required this.onFwd5,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      color: Theme.of(context).colorScheme.surfaceContainerHighest,
      child: Row(
        children: [
          IconButton(
            onPressed: onBack5,
            icon: const Icon(Icons.replay_5),
            tooltip: 'Back 5 seconds',
          ),
          IconButton.filled(
            onPressed: onPlayPause,
            icon: Icon(playing ? Icons.pause : Icons.play_arrow),
            tooltip: playing ? 'Pause' : 'Play',
          ),
          IconButton(
            onPressed: onFwd5,
            icon: const Icon(Icons.forward_5),
            tooltip: 'Forward 5 seconds',
          ),
          const Spacer(),
          Text(clock,
              style: const TextStyle(
                fontWeight: FontWeight.w700,
                fontFeatures: [FontFeature.tabularFigures()],
              )),
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
    final cs = Theme.of(context).colorScheme;
    return Container(
      decoration: BoxDecoration(
        color: isCursor ? cs.primaryContainer.withValues(alpha: 0.35) : null,
        // Colour is never the only signal (WCAG 1.4.1): the pending line
        // also carries a leading bar.
        border: Border(
          left: BorderSide(
            width: 4,
            color: isCursor ? cs.primary : Colors.transparent,
          ),
          bottom: BorderSide(color: cs.outlineVariant, width: 0.5),
        ),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          SizedBox(
            width: 62,
            child: GestureDetector(
              onTap: onTapTime,
              child: Text(
                line.isStamped ? _fmt(line.at!) : '—',
                style: TextStyle(
                  fontFeatures: const [FontFeature.tabularFigures()],
                  fontWeight:
                      line.isStamped ? FontWeight.w700 : FontWeight.w400,
                  color: line.isStamped ? cs.primary : cs.outline,
                  decoration:
                      onTapTime != null ? TextDecoration.underline : null,
                ),
              ),
            ),
          ),
          Expanded(child: Text(line.text)),
          if (onNudge != null) ...[
            _NudgeBtn(label: '−', onTap: () => onNudge!(-200)),
            const SizedBox(width: 4),
            _NudgeBtn(label: '+', onTap: () => onNudge!(200)),
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

class _NudgeBtn extends StatelessWidget {
  final String label;
  final VoidCallback onTap;
  const _NudgeBtn({required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    // 40dp keeps the target at the Material minimum for touch.
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(8),
      child: SizedBox(
        width: 40,
        height: 40,
        child: Center(
          child: Text(label, style: const TextStyle(fontSize: 18)),
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
              icon: const Icon(Icons.undo),
              tooltip: 'Step back a line',
            ),
            IconButton(
              onPressed: onClear,
              icon: const Icon(Icons.restart_alt),
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
                    backgroundColor: AppTheme.primary,
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
