import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/profile_cache.dart';

void main() {
  late Directory temporary;
  late Directory supportDirectory;
  late Directory uploadTemporaryDirectory;
  late ProfileImageCache cache;
  final versionA = List.filled(64, 'a').join();
  final versionB = List.filled(64, 'b').join();
  final jpegA = Uint8List.fromList([0xff, 0xd8, 0xff, 1, 2, 0xff, 0xd9]);
  final jpegB = Uint8List.fromList([0xff, 0xd8, 0xff, 3, 4, 0xff, 0xd9]);

  setUp(() async {
    temporary = await Directory.systemTemp.createTemp('profile-cache-test-');
    supportDirectory = Directory('${temporary.path}/support');
    uploadTemporaryDirectory = Directory('${temporary.path}/temporary');
    cache = ProfileImageCache(
      supportDirectory: () async => supportDirectory,
      temporaryDirectory: () async => uploadTemporaryDirectory,
    );
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

  test('aged upload reaper is confined, age-gated, and idempotent', () async {
    final now = DateTime.utc(2026, 9, 25, 12);
    final old = await cache.stageUpload(ownerUserId: 17, bytes: jpegA);
    final fresh = await cache.stageUpload(ownerUserId: 18, bytes: jpegB);
    await old.setLastModified(
      now.subtract(ProfileImageCache.staleUploadAge).subtract(
            const Duration(seconds: 1),
          ),
    );
    await fresh.setLastModified(now.subtract(const Duration(hours: 1)));

    final unrelated = File('${old.parent.path}/do-not-delete.txt');
    await unrelated.writeAsString('unrelated');
    await unrelated.setLastModified(now.subtract(const Duration(days: 3)));
    final outside = File(
      '${uploadTemporaryDirectory.path}/.upload-${List.filled(32, 'e').join()}.tmp',
    );
    await outside.parent.create(recursive: true);
    await outside.writeAsBytes(jpegA);
    await outside.setLastModified(now.subtract(const Duration(days: 3)));
    final cacheTemporary = File(
      '${supportDirectory.path}/profile_images/17/'
      '.cache-${List.filled(32, 'f').join()}.tmp',
    );
    await cacheTemporary.parent.create(recursive: true);
    await cacheTemporary.writeAsBytes(jpegA);
    await cacheTemporary.setLastModified(now.subtract(const Duration(days: 3)));

    expect(await cache.reapStaleUploads(now: now), 2);
    expect(await old.exists(), isFalse);
    expect(await cacheTemporary.exists(), isFalse);
    expect(await fresh.exists(), isTrue);
    expect(await unrelated.exists(), isTrue);
    expect(await outside.exists(), isTrue);
    expect(await cache.reapStaleUploads(now: now), 0);
  });

  test('staged discard refuses paths outside the private upload root', () async {
    final unrelated = File('${temporary.path}/unrelated.txt');
    await unrelated.writeAsString('keep');

    await cache.discardStagedUpload(unrelated);

    expect(await unrelated.exists(), isTrue);
  });
}
