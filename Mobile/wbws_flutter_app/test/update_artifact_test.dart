import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/app_update_service.dart';

/// P65 — ABI-aware update artifacts.
///
/// These tests pin the contract between the server's `/app/config`
/// (`apk_artifacts` map + legacy single-file fields) and the client's
/// artifact selection, including every backward-compatible fallback.
void main() {
  AppRemoteConfig configWith({
    Map<String, ApkArtifact> artifacts = const {},
    String sha = '',
    int size = 0,
  }) {
    return AppRemoteConfig(
      latestVersion: '1.1.17',
      latestBuild: 19,
      minVersion: '1.0.0',
      minBuild: 1,
      forceFlag: false,
      releaseNotes: '',
      downloadAvailable: true,
      apkSizeBytes: size,
      apkSha256: sha,
      apkArtifacts: artifacts,
    );
  }

  group('fromJson — apk_artifacts parsing', () {
    test('parses the per-ABI map', () {
      final c = AppRemoteConfig.fromJson({
        'latest_version': '1.1.17',
        'latest_build': 19,
        'min_version': '1.0.0',
        'min_build': 1,
        'download_available': true,
        'apk_size_bytes': 68_000_000,
        'apk_sha256': 'A' * 64,
        'apk_artifacts': {
          'universal': {'size_bytes': 68_000_000, 'sha256': 'U' * 64},
          'arm64-v8a': {'size_bytes': 38_000_000, 'sha256': '6' * 64},
          'armeabi-v7a': {'size_bytes': 34_000_000, 'sha256': '3' * 64},
        },
      });
      expect(c.apkArtifacts['universal']!.sha256, 'u' * 64);
      expect(c.apkArtifacts['universal']!.sizeBytes, 68_000_000);
      expect(c.apkArtifacts['arm64-v8a']!.sizeBytes, 38_000_000);
      expect(c.apkArtifacts['armeabi-v7a']!.sha256, '3' * 64);
      // Legacy fields untouched.
      expect(c.apkSha256, 'a' * 64);
      expect(c.apkSizeBytes, 68_000_000);
    });

    test('absent map → empty (old server), junk → ignored', () {
      final legacy = AppRemoteConfig.fromJson({
        'latest_version': '1.1.16',
        'latest_build': 18,
        'download_available': true,
        'apk_sha256': 'a' * 64,
      });
      expect(legacy.apkArtifacts, isEmpty);

      final junk = AppRemoteConfig.fromJson({
        'latest_version': '1.1.16',
        'latest_build': 18,
        'download_available': true,
        'apk_artifacts': {
          'arm64-v8a': 'not-a-map',
          'armeabi-v7a': {'size_bytes': 5, 'sha256': ''},
        },
      });
      expect(junk.apkArtifacts, isEmpty);
    });
  });

  group('artifactFor — selection + fallbacks', () {
    test('exact ABI hit wins', () {
      final c = configWith(
        artifacts: {
          'universal': const ApkArtifact(sizeBytes: 68, sha256: 'u' * 64),
          'arm64-v8a': const ApkArtifact(sizeBytes: 38, sha256: '6' * 64),
        },
      );
      expect(AppUpdateService.artifactFor(c, 'arm64-v8a')!.sha256, '6' * 64);
      expect(AppUpdateService.artifactFor(c, 'arm64-v8a')!.sizeBytes, 38);
    });

    test('unknown ABI falls back to universal entry, then legacy fields', () {
      final withUniversal = configWith(
        artifacts: {
          'universal': const ApkArtifact(sizeBytes: 68, sha256: 'u' * 64),
        },
        sha: 'a' * 64,
        size: 60,
      );
      expect(
        AppUpdateService.artifactFor(withUniversal, 'x86')!.sha256,
        'u' * 64,
      );
      expect(
        AppUpdateService.artifactFor(withUniversal, null)!.sha256,
        'u' * 64,
      );

      final legacyOnly = configWith(sha: 'a' * 64, size: 60);
      expect(
        AppUpdateService.artifactFor(legacyOnly, 'arm64-v8a')!.sha256,
        'a' * 64,
      );
      expect(
        AppUpdateService.artifactFor(legacyOnly, 'arm64-v8a')!.sizeBytes,
        60,
      );
    });

    test('no config / nothing advertised → null (download still safe)', () {
      expect(AppUpdateService.artifactFor(null, 'arm64-v8a'), isNull);
      expect(AppUpdateService.artifactFor(configWith(), 'arm64-v8a'), isNull);
      // Empty-string ABI (device info missing) behaves like null.
      expect(
        AppUpdateService.artifactFor(configWith(sha: 'a' * 64), ''),
        isNotNull,
      );
    });
  });
}
