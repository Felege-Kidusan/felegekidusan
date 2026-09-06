import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/crash_log_service.dart';
import 'package:fkss_app/services/device_tier_service.dart';

/// P65 — the shared failure-log contract.
///
/// Two writers append to `fkss_bootstrap_error.log`:
///   * Dart bootstrap:  `=== 2026-09-06T09:30:00.123 ===`
///   * native trap:     `=== CRASH 1757160000000 ===`
/// The parser must understand both (and survive junk), because the
/// Diagnostics screen and the "app closed itself recently" banner are
/// only as good as this parse.
void main() {
  group('parseCrashLog', () {
    test('parses a native crash section with epoch timestamp', () {
      final entries = parseCrashLog(
        '=== CRASH 1757160000000 ===\n'
        'java.lang.UnsatisfiedLinkError: libflutter.so\n'
        '\tat com.example.Foo(Foo.java:1)\n'
        '\n',
      );
      expect(entries, hasLength(1));
      expect(entries.first.nativeCrash, isTrue);
      expect(
        entries.first.at,
        DateTime.fromMillisecondsSinceEpoch(1757160000000),
      );
      expect(entries.first.body, contains('UnsatisfiedLinkError'));
    });

    test('parses a Dart bootstrap section with ISO timestamp', () {
      final entries = parseCrashLog(
        '=== 2026-09-06T09:30:00.123456 ===\n'
        'SqfliteException: database corrupted\n'
        'stack line\n'
        '\n',
      );
      expect(entries, hasLength(1));
      expect(entries.first.nativeCrash, isFalse);
      expect(entries.first.at, DateTime.tryParse('2026-09-06T09:30:00.123456'));
      expect(entries.first.body, contains('SqfliteException'));
    });

    test('multiple sections, in order, junk dropped', () {
      final raw =
          'legacy noise before first header\n'
          '=== 2026-09-01T08:00:00.000 ===\n'
          'first\n'
          '=== CRASH 1757160000000 ===\n'
          'second\n'
          '=== 2026-09-06T10:00:00.000 ===\n'
          'third\n';
      final entries = parseCrashLog(raw);
      expect(entries, hasLength(3));
      expect(entries[0].body, 'first');
      expect(entries[1].nativeCrash, isTrue);
      expect(entries[2].body, 'third');
    });

    test('unparseable header still yields an entry (at = null)', () {
      final entries = parseCrashLog('=== ??? ===\nsomething\n');
      expect(entries, hasLength(1));
      expect(entries.first.at, isNull);
      expect(entries.first.body, 'something');
    });

    test('empty / no-section input → no entries', () {
      expect(parseCrashLog(''), isEmpty);
      expect(parseCrashLog('no headers here\njust text'), isEmpty);
    });
  });

  group('CrashLogEntry.within', () {
    test('recent native crash counts, old one does not', () {
      final now = DateTime.now().millisecondsSinceEpoch;
      final fresh = parseCrashLog('=== CRASH $now ===\nboom\n').first;
      final old = parseCrashLog(
        '=== CRASH ${now - 8 * 24 * 3600 * 1000} ===\nboom\n',
      ).first;
      expect(fresh.within(const Duration(days: 7)), isTrue);
      expect(old.within(const Duration(days: 7)), isFalse);
    });
  });

  group('buildReport — no PII, all facts', () {
    test('contains device facts and log tail', () {
      final device = const DeviceInfoSnapshot(
        primaryAbi: 'armeabi-v7a',
        abis: ['armeabi-v7a', 'armeabi'],
        totalRamMb: 1024,
        isLowRam: true,
        sdkInt: 28,
        release: '9',
        model: 'Galaxy A10',
        manufacturer: 'samsung',
      );
      final r = CrashLogService.buildReport(
        appVersion: '1.1.17',
        appBuild: 19,
        server: 'https://api.example.com',
        device: device,
        crashLogTail: '=== CRASH 1 ===\nboom',
      );
      expect(r, contains('1.1.17 (19)'));
      expect(r, contains('Galaxy A10'));
      expect(r, contains('armeabi-v7a'));
      expect(r, contains('1024 MB'));
      expect(r, contains('Android Go'));
      expect(r, contains('boom'));
      // No user data in the report.
      expect(r.contains('token'), isFalse);
    });

    test('device absent → graceful placeholder', () {
      final r = CrashLogService.buildReport(
        appVersion: '1.1.17',
        appBuild: 19,
        server: 'https://api.example.com',
        crashLogTail: '',
      );
      expect(r, contains('not available'));
      expect(r, contains('No recorded errors'));
    });
  });
}
