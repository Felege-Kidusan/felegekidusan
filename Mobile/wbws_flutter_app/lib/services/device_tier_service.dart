import 'package:flutter/foundation.dart';
import 'package:flutter/painting.dart';
import 'package:flutter/services.dart';

/// P65 — the device capability tier (the Telegram model: the hardware
/// class is a first-class input to how the app behaves).
///
/// One tiny method channel (`fkss.app/device`, read-only) supplies the
/// ABI, RAM and OS snapshot; the tier then scales heavyweight behaviour
/// to the hardware. Today's consumer is the image cache (a 1GB Android Go
/// phone must not let a 100MB default bitmap cache push it into
/// low-memory kills). Future consumers (animation weight, prefetch
/// counts, artwork resolution) read [tier] without knowing how it was
/// computed — the thresholds live in exactly one pure function,
/// [tierFor], pinned by test.
///
/// Deliberately fail-open: if the channel is missing (web, or a future
/// platform) the service stays at the safe middle tier and nothing calls
/// native code.
enum DeviceTier { low, mid, high }

class DeviceInfoSnapshot {
  /// Primary ABI, e.g. `arm64-v8a` or `armeabi-v7a`. Empty when unknown.
  final String primaryAbi;

  /// Every ABI the device reports, best first.
  final List<String> abis;

  /// Total RAM in whole MB (0 when unknown).
  final int totalRamMb;

  /// The OS's own "1GB-class phone" flag (Android Go devices).
  final bool isLowRam;

  final int sdkInt;
  final String release;
  final String model;
  final String manufacturer;

  const DeviceInfoSnapshot({
    required this.primaryAbi,
    required this.abis,
    required this.totalRamMb,
    required this.isLowRam,
    required this.sdkInt,
    required this.release,
    required this.model,
    required this.manufacturer,
  });
}

class DeviceTierService {
  DeviceTierService._();

  static final DeviceTierService instance = DeviceTierService._();

  static const MethodChannel _channel = MethodChannel('fkss.app/device');

  /// Null until [boot] succeeds (or forever on web/unknown platforms).
  DeviceInfoSnapshot? info;

  DeviceTier _tier = DeviceTier.mid;
  DeviceTier get tier => _tier;

  bool get isLowEnd => _tier == DeviceTier.low;

  /// Fetches the snapshot and applies tier-driven budgets. Safe to call
  /// more than once; safe when the channel is absent.
  Future<void> boot() async {
    if (kIsWeb) return;
    try {
      final raw = await _channel.invokeMethod<Map<dynamic, dynamic>>('info');
      if (raw == null) return;
      final map = Map<Object?, Object?>.from(raw);
      final abis =
          (map['abis'] as List?)?.map((e) => '$e').toList() ?? const <String>[];
      final snap = DeviceInfoSnapshot(
        primaryAbi: '${map['abi'] ?? ''}',
        abis: abis,
        totalRamMb: (map['totalRamMb'] as num?)?.toInt() ?? 0,
        isLowRam: map['isLowRam'] == true,
        sdkInt: (map['sdkInt'] as num?)?.toInt() ?? 0,
        release: '${map['release'] ?? ''}',
        model: '${map['model'] ?? ''}',
        manufacturer: '${map['manufacturer'] ?? ''}',
      );
      info = snap;
      _tier = tierFor(totalRamMb: snap.totalRamMb, isLowRam: snap.isLowRam);
      _applyImageCache();
    } on PlatformException {
      // Read-only capability probe; failing open is correct.
    } on MissingPluginException {
      // Platform without the channel (web) — stay at the middle tier.
    }
  }

  /// The single source of truth for tier thresholds.
  ///
  ///   * OS says low-RAM (Android Go)  → LOW, whatever the marketing RAM.
  ///   * ≤ 2 GB                        → LOW  (the Samsung A10 / 1–2GB
  ///                                      fleet this app must serve).
  ///   * ≤ 4 GB                        → MID.
  ///   * > 4 GB                        → HIGH.
  ///   * unknown RAM, not low-RAM      → MID (fail open to the middle).
  static DeviceTier tierFor({
    required int? totalRamMb,
    required bool isLowRam,
  }) {
    if (isLowRam) return DeviceTier.low;
    final ram = totalRamMb;
    if (ram == null || ram <= 0) return DeviceTier.mid;
    if (ram <= 2048) return DeviceTier.low;
    if (ram <= 4096) return DeviceTier.mid;
    return DeviceTier.high;
  }

  void _applyImageCache() {
    // Flutter's defaults (1000 images / 100 MB) assume flagship headroom;
    // on 1–2 GB Go phones that budget is exactly what tips the process
    // into low-memory kills. Scaled to the tier; HIGH keeps the default.
    final cache = PaintingBinding.instance.imageCache;
    switch (_tier) {
      case DeviceTier.low:
        cache.maximumSize = 250;
        cache.maximumSizeBytes = 32 << 20; // 32 MB
      case DeviceTier.mid:
        cache.maximumSize = 500;
        cache.maximumSizeBytes = 64 << 20; // 64 MB
      case DeviceTier.high:
        break;
    }
  }
}
