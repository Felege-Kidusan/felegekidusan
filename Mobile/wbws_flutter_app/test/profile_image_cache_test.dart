import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/profile_cache.dart';

void main() {
  late Directory temporary;
  late ProfileImageCache cache;
  final versionA = List.filled(64, 'a').join();
  final versionB = List.filled(64, 'b').join();
  final jpegA = Uint8List.fromList([0xff, 0xd8, 0xff, 1, 2, 0xff, 0xd9]);
  final jpegB = Uint8List.fromList([0xff, 0xd8, 0xff, 3, 4, 0xff, 0xd9]);

  setUp(() async {
    temporary = await Directory.systemTemp.createTemp('profile-cache-test-');
    cache = ProfileImageCache(supportDirectory: () async => temporary);
  });

  tearDown(() async {
    if (await temporary.exists()) await temporary.delete(recursive: true);
  });

  test('cache keys are immutable-owner and opaque-version bound', () {
    expect(
      ProfileImageCache.relativeSegments(17, versionA),
      ['profile_images', '17', '$versionA.jpg'],
    );
    expect(
      () => ProfileImageCache.relativeSegments(0, versionA),
      throwsFormatException,
    );
    expect(
      () => ProfileImageCache.relativeSegments(17, '../escape'),
      throwsFormatException,
    );
  });

  test('cross-user image reads are isolated', () async {
    await cache.write(ownerUserId: 17, version: versionA, jpegBytes: jpegA);

    expect(
      await cache.read(ownerUserId: 17, version: versionA),
      orderedEquals(jpegA),
    );
    expect(await cache.read(ownerUserId: 18, version: versionA), isNull);
  });

  test('new version is written before old version is removed', () async {
    await cache.write(ownerUserId: 17, version: versionA, jpegBytes: jpegA);
    await cache.write(ownerUserId: 17, version: versionB, jpegBytes: jpegB);

    expect(await cache.read(ownerUserId: 17, version: versionA), isNull);
    expect(
      await cache.read(ownerUserId: 17, version: versionB),
      orderedEquals(jpegB),
    );
  });

  test('owner cleanup removes cached and staged profile images only for owner',
      () async {
    await cache.write(ownerUserId: 17, version: versionA, jpegBytes: jpegA);
    await cache.write(ownerUserId: 18, version: versionA, jpegBytes: jpegB);
    final staged = await cache.stageUpload(ownerUserId: 17, bytes: jpegA);
    expect(await staged.exists(), isTrue);

    await cache.clearOwner(17);

    expect(await staged.exists(), isFalse);
    expect(await cache.read(ownerUserId: 17, version: versionA), isNull);
    expect(
      await cache.read(ownerUserId: 18, version: versionA),
      orderedEquals(jpegB),
    );
  });
}
