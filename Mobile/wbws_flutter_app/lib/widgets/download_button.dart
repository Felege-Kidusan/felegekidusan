import 'package:flutter/material.dart';

import '../services/mezmur_download_manager.dart';
import '../utils/theme.dart';

/// P33 — the Spotify download affordance, as one small widget.
///
/// States it renders (identical vocabulary to Spotify's, so the meaning
/// is learned instantly):
///   none        → grey outlined down-arrow  ("tap to keep offline")
///   queued      → grey clock                ("waiting its turn / Wi‑Fi")
///   downloading → determinate ring          (real byte progress)
///   done        → filled green check        ("plays with no signal")
///   failed      → amber warning             (tap to retry)
///
/// It listens to the manager directly, so a list of 500 hymns does not
/// need any per-row state plumbing in the screen that hosts it.
class HymnDownloadButton extends StatefulWidget {
  const HymnDownloadButton({
    super.key,
    required this.hymn,
    this.size = 20,
    this.showLabel = false,
  });

  /// A cached_hymns row (needs `id`, `audio_status`, `audio_format`).
  final Map<String, dynamic> hymn;
  final double size;
  final bool showLabel;

  @override
  State<HymnDownloadButton> createState() => _HymnDownloadButtonState();
}

class _HymnDownloadButtonState extends State<HymnDownloadButton> {
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

  int get _id {
    final v = widget.hymn['id'];
    return v is int ? v : int.tryParse('$v') ?? 0;
  }

  bool get _hasAudio => '${widget.hymn['audio_status'] ?? ''}' == 'ready';

  Future<void> _tap() async {
    final state = _dl.stateOf(_id);
    final messenger = ScaffoldMessenger.of(context);
    switch (state) {
      case 'done':
        final confirm = await showDialog<bool>(
          context: context,
          builder: (c) => AlertDialog(
            title: const Text('Remove download?'),
            content: Text(
                '"${widget.hymn['title'] ?? 'This hymn'}" will no longer play '
                'without internet. It stays in the library.'),
            actions: [
              TextButton(
                  onPressed: () => Navigator.pop(c, false),
                  child: const Text('Keep')),
              TextButton(
                  onPressed: () => Navigator.pop(c, true),
                  child: const Text('Remove')),
            ],
          ),
        );
        if (confirm == true) await _dl.remove(_id);
        break;
      case 'failed':
        await _dl.retry(_id);
        break;
      case 'queued':
      case 'downloading':
        await _dl.remove(_id);
        break;
      default:
        await _dl.download(widget.hymn);
        if (!mounted) return;
        if (_dl.waitingForWifi) {
          messenger.showSnackBar(const SnackBar(
            content: Text('Queued — waiting for Wi‑Fi'),
            duration: Duration(seconds: 2),
          ));
        }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_hasAudio) return const SizedBox.shrink();
    final state = _dl.stateOf(_id);
    final s = widget.size;

    Widget icon;
    String tip;
    switch (state) {
      case 'done':
        tip = 'Downloaded — plays offline';
        icon = Container(
          width: s,
          height: s,
          decoration: const BoxDecoration(
              color: AppTheme.success, shape: BoxShape.circle),
          child: Icon(Icons.check, size: s * 0.65, color: Colors.white),
        );
        break;
      case 'downloading':
        final pr = _dl.progressOf(_id);
        tip = 'Downloading ${(pr * 100).round()}%';
        icon = SizedBox(
          width: s,
          height: s,
          child: Stack(alignment: Alignment.center, children: [
            CircularProgressIndicator(
              value: pr > 0 ? pr : null,
              strokeWidth: 2,
              valueColor:
                  const AlwaysStoppedAnimation<Color>(AppTheme.success),
              backgroundColor: AppTheme.success.withOpacity(0.18),
            ),
            Icon(Icons.stop_rounded,
                size: s * 0.45, color: AppTheme.textSecondary),
          ]),
        );
        break;
      case 'queued':
        tip = _dl.waitingForWifi
            ? 'Waiting for Wi‑Fi'
            : (_dl.waitingForNetwork ? 'Waiting for network' : 'Queued');
        icon = Icon(Icons.schedule_rounded,
            size: s, color: AppTheme.textSecondary);
        break;
      case 'paused':
        tip = 'Paused';
        icon = Icon(Icons.pause_circle_outline,
            size: s, color: AppTheme.textSecondary);
        break;
      case 'failed':
        tip = 'Download failed — tap to retry';
        icon = Icon(Icons.error_outline_rounded,
            size: s, color: AppTheme.warning);
        break;
      default:
        tip = 'Download for offline listening';
        icon = Icon(Icons.arrow_circle_down_outlined,
            size: s, color: AppTheme.textSecondary);
    }

    final button = IconButton(
      onPressed: _tap,
      icon: icon,
      iconSize: s,
      tooltip: tip,
      visualDensity: VisualDensity.compact,
      constraints: BoxConstraints(minWidth: s + 16, minHeight: s + 16),
      padding: EdgeInsets.zero,
      splashRadius: s,
    );

    if (!widget.showLabel) return button;
    return Row(mainAxisSize: MainAxisSize.min, children: [
      button,
      const SizedBox(width: 2),
      Text(tip,
          style: TextStyle(fontSize: 11, color: AppTheme.textSecondary)),
    ]);
  }
}

/// Small green "offline" pill for list rows — the at-a-glance marker
/// that a hymn will play with no signal.
class OfflineBadge extends StatelessWidget {
  const OfflineBadge({super.key});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
        decoration: BoxDecoration(
          color: AppTheme.success.withOpacity(0.12),
          borderRadius: BorderRadius.circular(6),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.download_done_rounded,
              size: 9, color: AppTheme.success),
          const SizedBox(width: 3),
          const Text('OFFLINE',
              style: TextStyle(
                  fontSize: 8.5,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 0.5,
                  color: AppTheme.success)),
        ]),
      );
}
