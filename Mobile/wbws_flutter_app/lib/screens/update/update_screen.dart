import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import '../../services/app_update_service.dart';
import '../../utils/config.dart';
import '../../utils/theme.dart';

class UpdateScreen extends StatefulWidget {
  final bool blocking;
  const UpdateScreen({super.key, this.blocking = false});

  @override
  State<UpdateScreen> createState() => _UpdateScreenState();
}

class _UpdateScreenState extends State<UpdateScreen> {
  final _svc = AppUpdateService();
  bool _busy = false;
  double _progress = 0;
  String? _error;

  Future<void> _download() async {
    setState(() {
      _busy = true;
      _error = null;
      _progress = 0;
    });
    try {
      final path = await _svc.downloadApk(onProgress: (p) {
        if (mounted) setState(() => _progress = p);
      });
      await _svc.installApk(path);
    } catch (e) {
      if (mounted) {
        setState(() => _error = e.toString().replaceFirst('Exception: ', ''));
      }
    }
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final cfg = _svc.config;
    final android = !kIsWeb && Platform.isAndroid;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Update FKSS'),
        automaticallyImplyLeading: !widget.blocking,
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Icon(Icons.system_update_alt_rounded,
              size: 48, color: AppTheme.primary),
          const SizedBox(height: 12),
          Text(
            widget.blocking
                ? 'This phone needs a new FKSS app'
                : 'A newer FKSS app is ready',
            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 8),
          Text(
            'You have ${AppConfig.appVersion}. The school published ${cfg?.latestVersion ?? ''}.',
            textAlign: TextAlign.center,
            style: TextStyle(color: AppTheme.textSecondary, height: 1.4),
          ),
          if ((cfg?.releaseNotes ?? '').isNotEmpty) ...[
            const SizedBox(height: 16),
            Text(cfg!.releaseNotes,
                style: const TextStyle(fontSize: 14, height: 1.4)),
          ],
          const SizedBox(height: 24),
          if (android && (cfg?.downloadAvailable ?? false)) ...[
            if (_busy)
              Column(children: [
                LinearProgressIndicator(value: _progress > 0 ? _progress : null),
                const SizedBox(height: 8),
                Text(_progress > 0
                    ? '${(_progress * 100).toStringAsFixed(0)}%'
                    : 'Starting download…'),
              ])
            else
              ElevatedButton.icon(
                onPressed: _download,
                icon: const Icon(Icons.download_rounded),
                label: const Text('Download and install'),
              ),
            const SizedBox(height: 8),
            Text(
              'Android will ask you to allow installs from FKSS. That is normal.',
              style: TextStyle(fontSize: 12, color: AppTheme.textSecondary),
            ),
          ] else ...[
            Text(
              android
                  ? 'The school has not published the file yet. Ask them for the new FKSS app.'
                  : 'On iPhone, install the new app the school sends you. This screen cannot install an Android file.',
              style: TextStyle(color: AppTheme.textSecondary, height: 1.4),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 16),
            Text(_error!, style: const TextStyle(color: AppTheme.danger)),
          ],
        ],
      ),
    );
  }
}
