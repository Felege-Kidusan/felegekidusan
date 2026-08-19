import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/utils/version.dart';

void main() {
  test('newer patch is an update', () {
    final d = decideUpdate(
      currentVersion: '1.0.0',
      currentBuild: 1,
      latestVersion: '1.1.0',
      latestBuild: 2,
      minVersion: '1.0.0',
      minBuild: 1,
    );
    expect(d.force, isFalse);
    expect(d.optional, isTrue);
  });

  test('below min is forced', () {
    final d = decideUpdate(
      currentVersion: '1.0.0',
      currentBuild: 1,
      latestVersion: '1.2.0',
      latestBuild: 5,
      minVersion: '1.1.0',
      minBuild: 2,
    );
    expect(d.force, isTrue);
    expect(d.optional, isFalse);
  });

  test('same version is quiet', () {
    final d = decideUpdate(
      currentVersion: '1.1.0',
      currentBuild: 2,
      latestVersion: '1.1.0',
      latestBuild: 2,
      minVersion: '1.0.0',
      minBuild: 1,
    );
    expect(d.any, isFalse);
  });

  test('force flag wins', () {
    final d = decideUpdate(
      currentVersion: '1.1.0',
      currentBuild: 2,
      latestVersion: '1.1.0',
      latestBuild: 2,
      minVersion: '1.0.0',
      minBuild: 1,
      forceFlag: true,
    );
    expect(d.force, isTrue);
  });
}
