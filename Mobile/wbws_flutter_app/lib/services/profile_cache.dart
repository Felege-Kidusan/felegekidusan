import 'dart:io';
import 'dart:typed_data';

import 'package:path_provider/path_provider.dart';

abstract class ProfileImageStore {
  Future<Uint8List?> read({required int ownerUserId, required String version});
  Future<void> write({
    required int ownerUserId,
    required String version,
    required Uint8List jpegBytes,
  });
  Future<File> stageUpload({
    required int ownerUserId,
    required Uint8List bytes,
  });
  Future<void> discardStagedUpload(File file);
  Future<void> clearOwner(int ownerUserId);
  Future<void> clearAll();
}

/// Owner- and opaque-version-bound private avatar cache.
///
/// The path is always `profile_images/<immutable user id>/<opaque version>.jpg`.
/// Callers receive bytes rather than paths so a physical filename never becomes
/// UI state or an ownership boundary.
class ProfileImageCache implements ProfileImageStore {
  ProfileImageCache({Future<Directory> Function()? supportDirectory})
      : _supportDirectory = supportDirectory ?? getApplicationSupportDirectory;

  static final ProfileImageCache instance = ProfileImageCache();

  final Future<Directory> Function() _supportDirectory;
  static final RegExp _versionPattern = RegExp(r'^[a-f0-9]{64}$');
  static const int _maximumBytes = 4 * 1024 * 1024;

  static List<String> relativeSegments(int ownerUserId, String version) {
    _validate(ownerUserId, version);
    return ['profile_images', '$ownerUserId', '$version.jpg'];
  }

  @override
  Future<Uint8List?> read({
    required int ownerUserId,
    required String version,
  }) async {
    final file = await _versionFile(ownerUserId, version);
    if (!await file.exists()) return null;
    final length = await file.length();
    if (length <= 0 || length > _maximumBytes) {
      await _safeDelete(file);
      return null;
    }
    final bytes = await file.readAsBytes();
    if (!_looksLikeJpeg(bytes)) {
      await _safeDelete(file);
      return null;
    }
    return bytes;
  }

  @override
  Future<void> write({
    required int ownerUserId,
    required String version,
    required Uint8List jpegBytes,
  }) async {
    _validate(ownerUserId, version);
    if (jpegBytes.isEmpty ||
        jpegBytes.length > _maximumBytes ||
        !_looksLikeJpeg(jpegBytes)) {
      throw const FormatException('Only bounded JPEG profile images are cached.');
    }

    final target = await _versionFile(ownerUserId, version);
    final directory = target.parent;
    await directory.create(recursive: true);
    if (await target.exists()) return;

    final temporary = File(
      '${directory.path}${Platform.pathSeparator}.'
      '$version-${DateTime.now().microsecondsSinceEpoch}.tmp',
    );
    try {
      await temporary.writeAsBytes(jpegBytes, flush: true);
      if (await target.exists()) {
        await _safeDelete(temporary);
      } else {
        await temporary.rename(target.path);
      }
      await for (final entity in directory.list(followLinks: false)) {
        if (entity is File &&
            entity.path != target.path &&
            entity.path.endsWith('.jpg')) {
          await _safeDelete(entity);
        }
      }
    } catch (_) {
      await _safeDelete(temporary);
      rethrow;
    }
  }

  @override
  Future<File> stageUpload({
    required int ownerUserId,
    required Uint8List bytes,
  }) async {
    if (ownerUserId <= 0 || bytes.isEmpty || bytes.length > _maximumBytes) {
      throw const FormatException('The selected image exceeds the 4 MB limit.');
    }
    final root = await _root();
    final directory = Directory(
      '${root.path}${Platform.pathSeparator}$ownerUserId',
    );
    await directory.create(recursive: true);
    final file = File(
      '${directory.path}${Platform.pathSeparator}.upload-'
      '${DateTime.now().microsecondsSinceEpoch}.tmp',
    );
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }

  @override
  Future<void> discardStagedUpload(File file) => _safeDelete(file);

  @override
  Future<void> clearOwner(int ownerUserId) async {
    if (ownerUserId <= 0) return;
    final root = await _root();
    final owner = Directory(
      '${root.path}${Platform.pathSeparator}$ownerUserId',
    );
    if (await owner.exists()) {
      await owner.delete(recursive: true);
    }
  }

  @override
  Future<void> clearAll() async {
    final root = await _root();
    if (await root.exists()) {
      await root.delete(recursive: true);
    }
  }

  Future<Directory> _root() async {
    final support = await _supportDirectory();
    return Directory(
      '${support.path}${Platform.pathSeparator}profile_images',
    );
  }

  Future<File> _versionFile(int ownerUserId, String version) async {
    final segments = relativeSegments(ownerUserId, version);
    final support = await _supportDirectory();
    return File([
      support.path,
      ...segments,
    ].join(Platform.pathSeparator));
  }

  static void _validate(int ownerUserId, String version) {
    if (ownerUserId <= 0 || !_versionPattern.hasMatch(version)) {
      throw const FormatException('Invalid owner-bound profile image key.');
    }
  }

  static bool _looksLikeJpeg(List<int> bytes) =>
      bytes.length >= 4 &&
      bytes[0] == 0xff &&
      bytes[1] == 0xd8 &&
      bytes[2] == 0xff &&
      bytes[bytes.length - 2] == 0xff &&
      bytes[bytes.length - 1] == 0xd9;

  static Future<void> _safeDelete(File file) async {
    try {
      if (await file.exists()) await file.delete();
    } catch (_) {}
  }
}
