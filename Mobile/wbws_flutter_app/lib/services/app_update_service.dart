import 'dart:async';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import '../utils/config.dart';
import '../utils/version.dart';
import 'api_service.dart';

class AppRemoteConfig {
  final String latestVersion;
  final int latestBuild;
  final String minVersion;
  final int minBuild;
  final bool forceFlag;
  final String releaseNotes;
  final bool downloadAvailable;
  final int apkSizeBytes;
  final String apkSha256;
  final String? bannerText;
  final String? bannerKind;
  final Map<String, List<String>> tiles;

  const AppRemoteConfig({
    required this.latestVersion,
    required this.latestBuild,
    required this.minVersion,
    required this.minBuild,
    required this.forceFlag,
    required this.releaseNotes,
    required this.downloadAvailable,
    required this.apkSizeBytes,
    required this.apkSha256,
    this.bannerText,
    this.bannerKind,
    this.tiles = const {},
  });

  factory AppRemoteConfig.fromJson(Map<String, dynamic> json) {
    final banner = json['banner'];
    final tilesRaw = json['tiles'];
    final tiles = <String, List<String>>{};
    if (tilesRaw is Map) {
      tilesRaw.forEach((k, v) {
        if (v is List) {
          tiles[k.toString()] = v.map((e) => e.toString()).toList();
        }
      });
    }
    return AppRemoteConfig(
      latestVersion: '${json['latest_version'] ?? ''}',
      latestBuild: int.tryParse('${json['latest_build'] ?? 0}') ?? 0,
      minVersion: '${json['min_version'] ?? ''}',
      minBuild: int.tryParse('${json['min_build'] ?? 0}') ?? 0,
      forceFlag: json['force_update'] == true,
      releaseNotes: '${json['release_notes'] ?? ''}',
      downloadAvailable: json['download_available'] == true,
      apkSizeBytes: int.tryParse('${json['apk_size_bytes'] ?? 0}') ?? 0,
      apkSha256: '${json['apk_sha256'] ?? ''}'.toLowerCase(),
      bannerText: banner is Map ? '${banner['text'] ?? ''}' : null,
      bannerKind: banner is Map ? '${banner['kind'] ?? 'info'}' : null,
      tiles: tiles,
    );
  }
}

/// Checks the server for a newer app. Downloads live on Android only.
class AppUpdateService {
  static final AppUpdateService _instance = AppUpdateService._internal();
  factory AppUpdateService() => _instance;
  AppUpdateService._internal();

  static const _channel = MethodChannel('fkss.app/updater');

  AppRemoteConfig? config;
  UpdateDecision decision = const UpdateDecision(force: false, optional: false);

  final _progress = StreamController<double>.broadcast();
  Stream<double> get progressStream => _progress.stream;

  Future<void> check() async {
    try {
      final res = await ApiService().get('/app/config', auth: false);
      if (!res.success || res.data == null) return;
      final data = res.data is Map<String, dynamic>
          ? res.data as Map<String, dynamic>
          : Map<String, dynamic>.from(res.data);
      config = AppRemoteConfig.fromJson(data);
      decision = decideUpdate(
        currentVersion: AppConfig.appVersion,
        currentBuild: AppConfig.appBuild,
        latestVersion: config!.latestVersion,
        latestBuild: config!.latestBuild,
        minVersion: config!.minVersion,
        minBuild: config!.minBuild,
        forceFlag: config!.forceFlag,
      );
    } catch (_) {
      // Stay on the current app if the check fails — do not lock teachers out.
    }
  }

  List<String> tilesFor(String area, List<String> fallback) {
    final list = config?.tiles[area];
    if (list == null || list.isEmpty) return fallback;
    return list;
  }

  bool get canDownload =>
      !kIsWeb && Platform.isAndroid && (config?.downloadAvailable ?? false);

  Future<String> downloadApk({void Function(double p)? onProgress}) async {
    if (!canDownload) {
      throw Exception('Download is only available on Android.');
    }
    final dir = await _channel.invokeMethod<String>('updateDir');
    if (dir == null || dir.isEmpty) {
      throw Exception('Could not prepare the download folder.');
    }
    final file = File('$dir/fkss.apk');
    if (await file.exists()) {
      await file.delete();
    }

    final uri = Uri.parse('${AppConfig.apiBaseUrl}/app/download');
    final client = http.Client();
    try {
      final request = http.Request('GET', uri);
      final response = await client.send(request).timeout(
            const Duration(minutes: 10),
          );
      if (response.statusCode != 200) {
        throw Exception('Download failed (${response.statusCode}).');
      }
      final total = response.contentLength ?? config?.apkSizeBytes ?? 0;
      var received = 0;
      final sink = file.openWrite();
      await for (final chunk in response.stream) {
        received += chunk.length;
        sink.add(chunk);
        final p = total > 0 ? (received / total).clamp(0.0, 1.0) : 0.0;
        _progress.add(p);
        onProgress?.call(p);
      }
      await sink.close();
    } finally {
      client.close();
    }

    final expected = config?.apkSha256 ?? '';
    if (expected.isNotEmpty) {
      final bytes = await file.readAsBytes();
      final got = sha256.convert(bytes).toString();
      if (got != expected) {
        await file.delete();
        throw Exception('The file was damaged. Please try again.');
      }
    }
    return file.path;
  }

  Future<void> installApk(String path) async {
    await _channel.invokeMethod('installApk', {'path': path});
  }
}
