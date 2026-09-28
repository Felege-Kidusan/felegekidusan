import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../utils/config.dart';
import 'device_tier_service.dart';

/// Lightweight, privacy-conscious first-party telemetry service for SSMS.
///
/// Collects anonymous device capability snapshots, installation heartbeats,
/// version adoption, and sync health without collecting personal user data.
class TelemetryService {
  TelemetryService._();
  static final TelemetryService instance = TelemetryService._();

  static const _kInstallIdKey = 'fkss_telemetry_install_id';
  static const _kLastPingKey = 'fkss_telemetry_last_ping';
  static const _minPingInterval = Duration(minutes: 15);

  final _secure = const FlutterSecureStorage();
  String? _cachedInstallId;
  DateTime? _lastPingTime;
  bool _booted = false;

  /// Retrieves or generates a persistent anonymous installation UUID.
  Future<String> getInstallationId() async {
    if (_cachedInstallId != null && _cachedInstallId!.isNotEmpty) {
      return _cachedInstallId!;
    }

    try {
      final saved = await _secure.read(key: _kInstallIdKey);
      if (saved != null && saved.isNotEmpty) {
        _cachedInstallId = saved;
        return saved;
      }
    } catch (_) {
      // Secure storage fallback
    }

    try {
      final prefs = await SharedPreferences.getInstance();
      final savedPrefs = prefs.getString(_kInstallIdKey);
      if (savedPrefs != null && savedPrefs.isNotEmpty) {
        _cachedInstallId = savedPrefs;
        return savedPrefs;
      }
    } catch (_) {
      // SharedPreferences fallback
    }

    // Generate fresh anonymous installation ID
    final id = _generateInstallId();
    _cachedInstallId = id;

    try {
      await _secure.write(key: _kInstallIdKey, value: id);
    } catch (_) {}

    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_kInstallIdKey, id);
    } catch (_) {}

    return id;
  }

  /// Initial launch boot ping
  Future<void> boot() async {
    if (kIsWeb || _booted) return;
    _booted = true;

    try {
      final prefs = await SharedPreferences.getInstance();
      final lastMs = prefs.getInt(_kLastPingKey);
      if (lastMs != null) {
        _lastPingTime = DateTime.fromMillisecondsSinceEpoch(lastMs);
      }
    } catch (_) {}

    final now = DateTime.now();
    if (_lastPingTime != null && now.difference(_lastPingTime!) < _minPingInterval) {
      return;
    }

    await _sendPayload(eventType: 'launch');
  }

  /// Periodic or event-driven telemetry ping
  Future<void> recordEvent(String eventType, [Map<String, dynamic>? data]) async {
    if (kIsWeb) return;
    await _sendPayload(eventType: eventType, eventData: data);
  }

  Future<void> recordSyncResult({required bool success, int itemsCount = 0, String? error}) async {
    await recordEvent(success ? 'sync_completed' : 'sync_failed', {
      'items_count': itemsCount,
      if (error != null) 'error': error.substring(0, min(200, error.length)),
    });
  }

  Future<void> recordCrash({required String summary}) async {
    await recordEvent('crash_recorded', {
      'summary': summary.substring(0, min(300, summary.length)),
    });
  }

  Future<void> recordUpdateDownloaded({required String version, required int build}) async {
    await recordEvent('update_downloaded', {
      'target_version': version,
      'target_build': build,
    });
  }

  Future<void> _sendPayload({
    required String eventType,
    Map<String, dynamic>? eventData,
  }) async {
    try {
      final installId = await getInstallationId();
      final device = DeviceTierService.instance.info;

      final body = <String, dynamic>{
        'installation_id': installId,
        'app_version': AppConfig.appVersion,
        'app_build': AppConfig.appBuild,
        'event_type': eventType,
        if (eventData != null) 'event_data': eventData,
      };

      if (device != null) {
        body['os_version'] = device.release;
        body['sdk_int'] = device.sdkInt;
        body['device_brand'] = device.manufacturer;
        body['device_model'] = device.model;
        body['abi'] = device.primaryAbi;
        body['ram_mb'] = device.totalRamMb;
        body['is_low_ram'] = device.isLowRam;
      }

      final uri = Uri.parse('${AppConfig.apiBaseUrl}/telemetry/heartbeat');
      final res = await http.post(
        uri,
        headers: {
          'Content-Type': 'application/json; charset=utf-8',
          'Accept': 'application/json',
          'User-Agent': 'FKSS-App/${AppConfig.appVersion} (${Platform.operatingSystem})',
        },
        body: jsonEncode(body),
      ).timeout(const Duration(seconds: 10));

      if (res.statusCode == 200) {
        _lastPingTime = DateTime.now();
        try {
          final prefs = await SharedPreferences.getInstance();
          await prefs.setInt(_kLastPingKey, _lastPingTime!.millisecondsSinceEpoch);
        } catch (_) {}
      }
    } catch (_) {
      // Telemetry failures are strictly non-fatal and fail silently
    }
  }

  String _generateInstallId() {
    final r = Random.secure();
    final bytes = List<int>.generate(16, (_) => r.nextInt(256));
    // Set version 4 and variant bits
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    
    final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join('');
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-${hex.substring(16, 20)}-${hex.substring(20, 32)}';
  }
}
