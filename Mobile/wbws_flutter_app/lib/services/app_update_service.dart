import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../utils/config.dart';
import '../utils/version.dart';
import 'api_service.dart';
import 'device_tier_service.dart';

/// P65 — metadata of one downloadable APK artifact
/// (universal, arm64-v8a or armeabi-v7a).
class ApkArtifact {
  final int sizeBytes;
  final String sha256;

  const ApkArtifact({required this.sizeBytes, required this.sha256});
}

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

  /// P65: per-ABI artifacts advertised by the server. Keys: `universal`,
  /// `arm64-v8a`, `armeabi-v7a`. Empty on servers that only publish the
  /// universal build — the legacy [apkSha256] / [apkSizeBytes] fields
  /// then remain the source of truth.
  final Map<String, ApkArtifact> apkArtifacts;
  final String? bannerText;
  final String? bannerKind;
  final Map<String, bool> features;
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
    this.apkArtifacts = const {},
    this.bannerText,
    this.bannerKind,
    this.features = const {},
    this.tiles = const {},
  });

  factory AppRemoteConfig.fromJson(Map<String, dynamic> json) {
    final banner = json['banner'];
    final featureRaw = json['features'];
    final features = <String, bool>{};
    if (featureRaw is Map) {
      featureRaw.forEach((k, v) {
        if (v is bool) features[k.toString()] = v;
      });
    }
    final tilesRaw = json['tiles'];
    final tiles = <String, List<String>>{};
    if (tilesRaw is Map) {
      tilesRaw.forEach((k, v) {
        if (v is List) {
          tiles[k.toString()] = v.map((e) => e.toString()).toList();
        }
      });
    }
    // P65: optional per-ABI artifact map from the server
    // {"apk_artifacts": {"arm64-v8a": {"size_bytes": n, "sha256": s}}}.
    // Unknown/absent → empty map; the legacy fields stay authoritative.
    final artifactsRaw = json['apk_artifacts'];
    final artifacts = <String, ApkArtifact>{};
    if (artifactsRaw is Map) {
      artifactsRaw.forEach((k, v) {
        if (v is Map) {
          final sha = '${v['sha256'] ?? ''}'.toLowerCase();
          final size = int.tryParse('${v['size_bytes'] ?? 0}') ?? 0;
          if (sha.isNotEmpty) {
            artifacts[k.toString()] = ApkArtifact(sizeBytes: size, sha256: sha);
          }
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
      apkArtifacts: artifacts,
      bannerText: banner is Map ? '${banner['text'] ?? ''}' : null,
      bannerKind: banner is Map ? '${banner['kind'] ?? 'info'}' : null,
      features: features,
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

  static const _featureCacheKey = 'fkss_remote_features_v1';
  static const _tileCacheKey = 'fkss_remote_tiles_v1';

  AppRemoteConfig? config;
  UpdateDecision decision = const UpdateDecision(force: false, optional: false);
  Map<String, bool> _cachedFeatures = const {};
  Map<String, List<String>> _cachedTiles = const {};
  Future<void>? _cacheFuture;

  final _progress = StreamController<double>.broadcast();
  Stream<double> get progressStream => _progress.stream;

  Future<void> check() async {
    await _loadCapabilityCache();
    try {
      final res = await ApiService().get('/app/config', auth: false);
      if (!res.success || res.data == null) return;
      final data = res.data is Map<String, dynamic>
          ? res.data as Map<String, dynamic>
          : Map<String, dynamic>.from(res.data);
      config = AppRemoteConfig.fromJson(data);
      await _saveCapabilityCache(config!);
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

  Future<void> _loadCapabilityCache() {
    return _cacheFuture ??= _readCapabilityCache();
  }

  Future<void> _readCapabilityCache() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final featureJson = prefs.getString(_featureCacheKey);
      final tileJson = prefs.getString(_tileCacheKey);
      if (featureJson != null) {
        final raw = jsonDecode(featureJson);
        if (raw is Map) {
          _cachedFeatures = {
            for (final entry in raw.entries)
              if (entry.value is bool) entry.key.toString(): entry.value as bool,
          };
        }
      }
      if (tileJson != null) {
        final raw = jsonDecode(tileJson);
        if (raw is Map) {
          _cachedTiles = {
            for (final entry in raw.entries)
              if (entry.value is List)
                entry.key.toString(): (entry.value as List)
                    .map((item) => item.toString()).toList(growable: false),
          };
        }
      }
    } catch (_) {
      _cachedFeatures = const {};
      _cachedTiles = const {};
    }
  }

  Future<void> _saveCapabilityCache(AppRemoteConfig value) async {
    _cachedFeatures = Map<String, bool>.from(value.features);
    _cachedTiles = {
      for (final entry in value.tiles.entries)
        entry.key: List<String>.from(entry.value),
    };
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_featureCacheKey, jsonEncode(_cachedFeatures));
      await prefs.setString(_tileCacheKey, jsonEncode(_cachedTiles));
    } catch (_) {
      // Server enforcement remains authoritative if local preferences fail.
    }
  }

  bool featureEnabled(String feature, {bool fallback = true}) {
    return config?.features[feature] ?? _cachedFeatures[feature] ?? fallback;
  }

  List<String> tilesFor(String area, List<String> fallback) {
    final list = config?.tiles[area] ?? _cachedTiles[area];
    if (list == null) return fallback;
    return list;
  }

  bool get canDownload =>
      !kIsWeb && Platform.isAndroid && (config?.downloadAvailable ?? false);

  /// P65 — which artifact the config promises for [abi]:
  /// the per-ABI entry when published, else `universal`, else the legacy
  /// single-file fields. Pure; pinned by test.
  static ApkArtifact? artifactFor(AppRemoteConfig? config, String? abi) {
    if (config == null) return null;
    if (abi != null && abi.isNotEmpty) {
      final hit = config.apkArtifacts[abi];
      if (hit != null) return hit;
    }
    final universal = config.apkArtifacts['universal'];
    if (universal != null) return universal;
    if (config.apkSha256.isNotEmpty) {
      return ApkArtifact(
        sizeBytes: config.apkSizeBytes,
        sha256: config.apkSha256,
      );
    }
    return null;
  }

  static bool _isSha256(String s) =>
      s.length == 64 && RegExp(r'^[0-9a-f]{64}$').hasMatch(s);

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

    // P65: ask for this device's architecture. Old servers ignore the
    // extra query parameter (same universal file); new servers stream a
    // ~2x smaller per-ABI build when one is published.
    final abi = DeviceTierService.instance.info?.primaryAbi;
    final artifact = artifactFor(config, abi);
    var uri = Uri.parse('${AppConfig.apiBaseUrl}/app/download');
    if (abi != null && abi.isNotEmpty) {
      uri = uri.replace(queryParameters: {'abi': abi});
    }
    final client = http.Client();
    String headerSha = '';
    try {
      final request = http.Request('GET', uri);
      final response = await client
          .send(request)
          .timeout(const Duration(minutes: 10));
      if (response.statusCode != 200) {
        throw Exception('Download failed (${response.statusCode}).');
      }
      // The server states the SHA-256 of the file it ACTUALLY served —
      // authoritative even when it falls back to universal for us.
      final rawHeader = '${response.headers['x-app-sha256'] ?? ''}'
          .trim()
          .toLowerCase();
      if (_isSha256(rawHeader)) headerSha = rawHeader;
      final total =
          response.contentLength ??
          artifact?.sizeBytes ??
          config?.apkSizeBytes ??
          0;
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

    // Validation order: served-file header → per-ABI/universal artifact
    // from config → legacy field. Never skip the check.
    final expected = headerSha.isNotEmpty
        ? headerSha
        : (artifact?.sha256 ?? config?.apkSha256 ?? '');
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
