import 'dart:io';

import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart' show getDatabasesPath;

import 'device_tier_service.dart';

/// P65 — the shared failure log reader/writer utilities.
///
/// ONE file holds the app's whole failure history:
/// `databases/fkss_bootstrap_error.log`
///   * the Dart bootstrap writer appends `=== <ISO-8601> ===` sections
///     (offline storage failures, main.dart);
///   * `FkssApplication.kt` appends `=== CRASH <epochMillis> ===`
///     sections for uncaught Java/Kotlin exceptions (the launch-crash
///     class: UnsatisfiedLinkError and friends).
///
/// This service reads/parses/clears it and builds the copyable
/// diagnostic report for the administrator (Profile → App →
/// Diagnostics). Parsing is a pure function, pinned by test. Content is
/// stack traces only — never tokens or member data.
const String kCrashLogFileName = 'fkss_bootstrap_error.log';

/// One `=== ... ===` section of the shared log.
class CrashLogEntry {
  /// When the entry was written; null when the header could not be
  /// parsed (the entry is still shown, just not time-filtered).
  final DateTime? at;

  /// True when written by the native crash trap (vs the Dart bootstrap).
  final bool nativeCrash;

  final String header;
  final String body;

  const CrashLogEntry({
    required this.at,
    required this.nativeCrash,
    required this.header,
    required this.body,
  });

  bool within(Duration d) {
    final t = at;
    return t != null && DateTime.now().difference(t) <= d;
  }
}

/// Parses the shared log. Sections start with a line
/// `=== <header> ===`; everything until the next such line is the body.
/// Recognised headers:
///   * `CRASH 1757160000000`  — native trap, epoch milliseconds.
///   * `2026-09-06T09:30:00.123` — Dart bootstrap, ISO-8601.
/// Anything else still parses (header kept verbatim, `at` null).
List<CrashLogEntry> parseCrashLog(String raw) {
  final entries = <CrashLogEntry>[];
  final lines = raw.split('\n');
  final headerRe = RegExp(r'^===\s*(.+?)\s*===$');

  String? header;
  final body = <String>[];

  void flush() {
    if (header == null) return;
    final h = header!;
    header = null;
    var native = false;
    DateTime? at;
    final crash = RegExp(r'^CRASH\s+(\d+)$').firstMatch(h);
    if (crash != null) {
      native = true;
      final ms = int.tryParse(crash.group(1)!);
      if (ms != null) {
        at = DateTime.fromMillisecondsSinceEpoch(ms);
      }
    } else {
      at = DateTime.tryParse(h);
    }
    entries.add(
      CrashLogEntry(
        at: at,
        nativeCrash: native,
        header: h,
        body: body.join('\n').trim(),
      ),
    );
    body.clear();
  }

  for (final line in lines) {
    final m = headerRe.firstMatch(line.trim());
    if (m != null) {
      flush();
      header = m.group(1)!;
    } else if (header != null) {
      body.add(line);
    }
    // Lines before the first header (legacy noise) are dropped.
  }
  flush();
  return entries;
}

class CrashLogService {
  CrashLogService._();

  static final CrashLogService instance = CrashLogService._();

  Future<File> _logFile() async {
    final dir = await getDatabasesPath();
    return File(p.join(dir, kCrashLogFileName));
  }

  /// The whole log; '' when missing or unreadable.
  Future<String> readRaw() async {
    try {
      final f = await _logFile();
      if (!await f.exists()) return '';
      return await f.readAsString();
    } catch (_) {
      return '';
    }
  }

  Future<List<CrashLogEntry>> readEntries() async =>
      parseCrashLog(await readRaw());

  /// The most recent native crash within [within] (default 7 days), if
  /// any — "the app closed itself recently" detection.
  Future<CrashLogEntry?> lastNativeCrash({
    Duration within = const Duration(days: 7),
  }) async {
    CrashLogEntry? best;
    for (final e in await readEntries()) {
      if (!e.nativeCrash) continue;
      if (!e.within(within)) continue;
      final b = best;
      if (b == null ||
          (e.at != null && (b.at == null || e.at!.isAfter(b.at!)))) {
        best = e;
      }
    }
    return best;
  }

  Future<void> clear() async {
    try {
      final f = await _logFile();
      if (await f.exists()) await f.delete();
    } catch (_) {
      // Clearing is best-effort.
    }
  }

  /// The copyable report for the administrator. Pure — no I/O, no PII:
  /// device/OS facts and stack traces only.
  static String buildReport({
    required String appVersion,
    required int appBuild,
    required String server,
    DeviceInfoSnapshot? device,
    required String crashLogTail,
  }) {
    final b = StringBuffer();
    b.writeln('FKSS diagnostic report');
    b.writeln('App: $appVersion ($appBuild)');
    b.writeln('Server: $server');
    if (device != null) {
      b.writeln('Device: ${device.manufacturer} ${device.model}');
      b.writeln('Android: ${device.release} (SDK ${device.sdkInt})');
      b.writeln(
        'ABI: ${device.primaryAbi}'
        '${device.abis.isEmpty ? '' : ' [${device.abis.join(', ')}]'}',
      );
      b.writeln(
        'RAM: ${device.totalRamMb > 0 ? '${device.totalRamMb} MB' : 'unknown'}'
        '${device.isLowRam ? ' (Android Go / low-RAM)' : ''}',
      );
    } else {
      b.writeln('Device: not available');
    }
    b.writeln('Generated: ${DateTime.now().toIso8601String()}');
    if (crashLogTail.trim().isNotEmpty) {
      b.writeln('--- error log (tail) ---');
      b.writeln(crashLogTail.trim());
    } else {
      b.writeln('No recorded errors.');
    }
    return b.toString();
  }
}
