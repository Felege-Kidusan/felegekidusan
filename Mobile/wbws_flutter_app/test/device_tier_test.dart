import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/device_tier_service.dart';

/// P65 — device tier thresholds (the Telegram model: hardware class as a
/// first-class input). One pure function decides the tier for the whole
/// app; these tests pin it so future refactors cannot silently move the
/// line that protects the 1–2GB phone fleet.
void main() {
  group('DeviceTierService.tierFor', () {
    test('OS low-RAM flag (Android Go) always means LOW', () {
      expect(
        DeviceTierService.tierFor(totalRamMb: 4096, isLowRam: true),
        DeviceTier.low,
      );
      expect(
        DeviceTierService.tierFor(totalRamMb: null, isLowRam: true),
        DeviceTier.low,
      );
    });

    test('RAM thresholds: ≤2GB LOW, ≤4GB MID, >4GB HIGH', () {
      expect(
        DeviceTierService.tierFor(totalRamMb: 1024, isLowRam: false),
        DeviceTier.low,
      );
      expect(
        DeviceTierService.tierFor(totalRamMb: 2048, isLowRam: false),
        DeviceTier.low,
      );
      expect(
        DeviceTierService.tierFor(totalRamMb: 2049, isLowRam: false),
        DeviceTier.mid,
      );
      expect(
        DeviceTierService.tierFor(totalRamMb: 3072, isLowRam: false),
        DeviceTier.mid,
      );
      expect(
        DeviceTierService.tierFor(totalRamMb: 4096, isLowRam: false),
        DeviceTier.mid,
      );
      expect(
        DeviceTierService.tierFor(totalRamMb: 4097, isLowRam: false),
        DeviceTier.high,
      );
      expect(
        DeviceTierService.tierFor(totalRamMb: 8192, isLowRam: false),
        DeviceTier.high,
      );
    });

    test('unknown RAM (channel missing) fails open to MID', () {
      expect(
        DeviceTierService.tierFor(totalRamMb: null, isLowRam: false),
        DeviceTier.mid,
      );
      expect(
        DeviceTierService.tierFor(totalRamMb: 0, isLowRam: false),
        DeviceTier.mid,
      );
    });
  });
}
