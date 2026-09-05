import 'package:flutter/material.dart';

import '../../services/mezmur_download_manager.dart';
import '../../utils/theme.dart';

/// P33b — Download settings, on its own screen.
///
/// Split out of the Downloads list so that list can be what it should
/// be: a clean library of audio. Everything here is a *policy* choice
/// (what may download, over which radio, up to how much space) rather
/// than content, which is exactly the line Spotify draws between
/// "Downloads" and "Settings → Storage".
class MezmurDownloadSettingsScreen extends StatefulWidget {
  const MezmurDownloadSettingsScreen({super.key});

  @override
  State<MezmurDownloadSettingsScreen> createState() =>
      _MezmurDownloadSettingsScreenState();
}

class _MezmurDownloadSettingsScreenState
    extends State<MezmurDownloadSettingsScreen> {
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

  static const _capOptions = <int, String>{
    512: '512 MB',
    1024: '1 GB',
    2048: '2 GB',
    5120: '5 GB',
    0: 'No limit',
  };

  @override
  Widget build(BuildContext context) {
    final used = _dl.bytesOnDisk;
    final cap = _dl.capMb * 1024 * 1024;
    final frac = cap > 0 ? (used / cap).clamp(0.0, 1.0) : 0.0;
    final over = cap > 0 && used > cap;

    return Scaffold(
      backgroundColor: AppTheme.bgLight,
      appBar: AppBar(
        title: const Text('Download settings'),
        backgroundColor: AppTheme.primary,
        foregroundColor: Colors.white,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 40),
        children: [
          // ── storage meter ───────────────────────────────────
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    Text(MezmurDownloadManager.formatBytes(used),
                        style: const TextStyle(
                            fontSize: 22, fontWeight: FontWeight.w800)),
                    const SizedBox(width: 8),
                    Padding(
                      padding: const EdgeInsets.only(bottom: 3),
                      child: Text('used by ${_dl.downloadedCount} hymns',
                          style: const TextStyle(
                              fontSize: 12, color: AppTheme.textSecondary)),
                    ),
                  ]),
                  const SizedBox(height: 12),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(5),
                    child: LinearProgressIndicator(
                      value: cap > 0 ? frac : null,
                      minHeight: 8,
                      backgroundColor: AppTheme.borderLight,
                      valueColor: AlwaysStoppedAnimation<Color>(
                          over ? AppTheme.warning : AppTheme.success),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    _dl.capMb == 0
                        ? 'No storage limit — downloads grow until you remove them.'
                        : 'Limit ${_capOptions[_dl.capMb] ?? '${_dl.capMb} MB'}',
                    style: const TextStyle(
                        fontSize: 11.5, color: AppTheme.textSecondary),
                  ),
                  if (over) ...[
                    const SizedBox(height: 10),
                    _notice(
                      icon: Icons.info_outline,
                      color: AppTheme.warning,
                      text: 'Over the limit. Hymns you downloaded yourself are '
                          'never deleted automatically — remove some, or raise '
                          'the limit.',
                    ),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 18),

          _sectionLabel('NETWORK'),
          Card(
            child: SwitchListTile(
              value: !_dl.wifiOnly,
              onChanged: (v) => _dl.setWifiOnly(!v),
              activeColor: AppTheme.primary,
              title: const Text('Download over mobile data',
                  style:
                      TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600)),
              subtitle: Text(
                _dl.wifiOnly
                    ? 'Off — downloads wait for Wi‑Fi. Recommended: a full '
                        'category can be tens of megabytes.'
                    : 'On — downloads may use your mobile data bundle.',
                style: const TextStyle(fontSize: 11.5),
              ),
            ),
          ),
          const SizedBox(height: 18),

          _sectionLabel('STORAGE'),
          Card(
            child: Column(children: [
              for (final e in _capOptions.entries)
                RadioListTile<int>(
                  dense: true,
                  value: e.key,
                  groupValue: _dl.capMb,
                  activeColor: AppTheme.primary,
                  onChanged: (v) => v == null ? null : _dl.setCapMb(v),
                  title: Text(e.value,
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.w600)),
                  subtitle: e.key == 0
                      ? const Text('Never delete downloads to save space',
                          style: TextStyle(fontSize: 11))
                      : null,
                ),
            ]),
          ),
          const SizedBox(height: 6),
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 4),
            child: Text(
              'When the limit is reached, hymns downloaded automatically (as '
              'part of a category you pinned) are removed starting with the '
              'ones you have played least. Hymns you downloaded one by one are '
              'always kept.',
              style: TextStyle(fontSize: 11, color: AppTheme.textSecondary),
            ),
          ),
          const SizedBox(height: 18),

          _sectionLabel('QUEUE'),
          Card(
            child: Column(children: [
              ListTile(
                dense: true,
                leading: const Icon(Icons.playlist_play_rounded, size: 20),
                title: Text(
                    _dl.queuedCount == 0
                        ? 'Nothing in the queue'
                        : '${_dl.queuedCount} waiting to download',
                    style: const TextStyle(
                        fontSize: 13, fontWeight: FontWeight.w600)),
                subtitle: Text(_queueExplanation(),
                    style: const TextStyle(fontSize: 11)),
              ),
              if (_dl.queuedCount > 0) ...[
                const Divider(height: 1),
                Row(children: [
                  Expanded(
                    child: TextButton.icon(
                      onPressed: _dl.pauseAll,
                      icon: const Icon(Icons.pause_rounded, size: 17),
                      label: const Text('Pause all',
                          style: TextStyle(fontSize: 12.5)),
                    ),
                  ),
                  Container(width: 1, height: 26, color: AppTheme.borderLight),
                  Expanded(
                    child: TextButton.icon(
                      onPressed: _dl.resumeAll,
                      icon: const Icon(Icons.play_arrow_rounded, size: 17),
                      label: const Text('Resume all',
                          style: TextStyle(fontSize: 12.5)),
                    ),
                  ),
                ]),
              ],
            ]),
          ),
          const SizedBox(height: 18),

          _sectionLabel('DANGER ZONE'),
          Card(
            child: ListTile(
              dense: true,
              enabled: _dl.downloadedCount > 0,
              leading: const Icon(Icons.delete_sweep_outlined,
                  size: 20, color: AppTheme.danger),
              title: const Text('Remove all downloads',
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: AppTheme.danger)),
              subtitle: Text(
                  _dl.downloadedCount == 0
                      ? 'Nothing downloaded yet'
                      : 'Frees ${MezmurDownloadManager.formatBytes(used)}. '
                          'The hymn library itself is not affected.',
                  style: const TextStyle(fontSize: 11)),
              onTap: _dl.downloadedCount == 0 ? null : _confirmRemoveAll,
            ),
          ),
        ],
      ),
    );
  }

  String _queueExplanation() {
    switch (_dl.queueStatus) {
      case 'no-network':
        return 'Waiting for a network connection.';
      case 'waiting-wifi':
        return 'Waiting for Wi‑Fi. Turn on mobile data above to start now.';
      case 'running':
        return 'Downloading now.';
      default:
        return 'Downloads you start will appear here.';
    }
  }

  Widget _sectionLabel(String text) => Padding(
        padding: const EdgeInsets.only(left: 4, bottom: 7),
        child: Text(text,
            style: const TextStyle(
                fontSize: 10.5,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.9,
                color: AppTheme.textSecondary)),
      );

  Widget _notice(
          {required IconData icon,
          required Color color,
          required String text}) =>
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: color.withOpacity(0.10),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 14, color: color),
          const SizedBox(width: 8),
          Expanded(
              child: Text(text, style: const TextStyle(fontSize: 11.2))),
        ]),
      );

  Future<void> _confirmRemoveAll() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Remove all downloads?'),
        content: Text(
            'This frees ${MezmurDownloadManager.formatBytes(_dl.bytesOnDisk)}. '
            'The hymn library stays — only the offline audio is deleted, and '
            'you can download it again any time.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Cancel')),
          TextButton(
            onPressed: () => Navigator.pop(c, true),
            child: const Text('Remove all',
                style: TextStyle(color: AppTheme.danger)),
          ),
        ],
      ),
    );
    if (ok != true) return;
    await _dl.removeAll();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('All downloads removed'),
        duration: Duration(seconds: 2)));
  }
}
