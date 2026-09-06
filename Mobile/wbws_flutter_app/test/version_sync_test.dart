import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/utils/config.dart';

/// P65: the app's self-reported version (AppConfig) is what the update
/// system compares against the server — pubspec.yaml's `version:` only
/// feeds the Android package version. When the two drift apart, phones
/// either never see updates or chase them forever (the exact bug that
/// hid updates from a phone reporting 1.1.15(17) against a 1.1.16(19)
/// server).
///
/// `scripts/build-release.ps1` runs `flutter test` before building, so
/// drifting versions FAIL the release instead of shipping silently.
void main() {
  test('AppConfig version matches pubspec.yaml (update system source of truth)',
      () {
    final pubspec = File('pubspec.yaml').readAsStringSync();
    final m = RegExp(r'^version:\s*([0-9.]+)\+(\d+)\s*$', multiLine: true)
        .firstMatch(pubspec);
    expect(m, isNotNull,
        reason: 'pubspec.yaml must declare a version line like 1.1.16+19');
    final pubVersion = m!.group(1)!;
    final pubBuild = int.parse(m.group(2)!);

    expect(AppConfig.appVersion, pubVersion,
        reason: 'lib/utils/config.dart appVersion must equal the pubspec '
            'version — the update system compares AppConfig against the '
            'server, not pubspec.');
    expect(AppConfig.appBuild, pubBuild,
        reason: 'lib/utils/config.dart appBuild must equal the pubspec '
            'build number — the update system compares AppConfig against '
            'the server, not pubspec.');
  });
}
