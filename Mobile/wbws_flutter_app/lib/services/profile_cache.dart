import 'dart:io';
import 'dart:math';
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
  Future<int> reapStaleUploads({DateTime? now});
  Future<void> clearOwner(int ownerUserId);
  Future<void> clearAll();
}

/// Owner- and opaque-version-bound private avatar cache.
///
/// Durable cache bytes live under application support. Raw upload staging lives
/// under the OS temporary root and is reaped after a fixed grace period. Neither
/// root nor a physical filename becomes UI state or an ownership boundary.
class ProfileImageCache implements ProfileImageStore {
  ProfileImageCache({
    Future<Directory> Function()? supportDirectory,
    Future<Directory> Function()? temporaryDirectory,
  })  : _supportDirectory =
            supportDirectory ?? getApplicationSupportDirectory,
        _temporaryDirectory = temporaryDirectory ?? getTemporaryDirectory;

  static final ProfileImageCache instance = ProfileImageCache();

  final Future<Directory> Function() _supportDirectory;
  final Future<Directory> Function() _temporaryDirectory;
  static final RegExp _versionPattern = RegExp(r'^[a-f0-9]{64}$');
  static final RegExp _ownerPattern = RegExp(r'^[1-9][0-9]*$');
  static final RegExp _uploadNamePattern =
      RegExp(r'^\.upload-[a-f0-9]{32}\.tmp$');
  static final RegExp _cacheTemporaryNamePattern =
      RegExp(r'^\.cache-[a-f0-9]{32}\.tmp$');
  static const int _maximumBytes = 4 * 1024 * 1024;
  static const Duration staleUploadAge = Duration(hours: 24);

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
      '${directory.path}${Platform.pathSeparator}.cache-${_randomToken()}.tmp',
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
    final root = await _uploadRoot();
    final directory = Directory(
      '${root.path}${Platform.pathSeparator}$ownerUserId',
    );
    await directory.create(recursive: true);
    final file = File(
      '${directory.path}${Platform.pathSeparator}.upload-${_randomToken()}.tmp',
    );
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }

  @override
  Future<void> discardStagedUpload(File file) async {
    final root = await _uploadRoot();
    if (!_isConfinedUploadPath(root, file)) return;
    await _safeDelete(file);
  }

  @override
  Future<int> reapStaleUploads({DateTime? now}) async {
    final cutoff = (now ?? DateTime.now()).subtract(staleUploadAge);
    final uploads = await _reapTemporaryFiles(
      await _uploadRoot(),
      _uploadNamePattern,
      cutoff,
    );
    final cacheWrites = await _reapTemporaryFiles(
      await _cacheRoot(),
      _cacheTemporaryNamePattern,
      cutoff,
    );
    return uploads + cacheWrites;
  }

  Future<int> _reapTemporaryFiles(
    Directory root,
    RegExp allowedName,
    DateTime cutoff,
  ) async {
    if (!await root.exists()) return 0;
    var deleted = 0;
    await for (final ownerEntity in root.list(followLinks: false)) {
      if (await FileSystemEntity.type(ownerEntity.path, followLinks: false) !=
          FileSystemEntityType.directory) {
        continue;
      }
      final ownerName = _basename(ownerEntity.path);
      if (!_ownerPattern.hasMatch(ownerName)) continue;
      final ownerDirectory = Directory(ownerEntity.path);
      await for (final entity in ownerDirectory.list(followLinks: false)) {
        if (await FileSystemEntity.type(entity.path, followLinks: false) !=
            FileSystemEntityType.file) {
          continue;
        }
        if (!allowedName.hasMatch(_basename(entity.path))) continue;
        final file = File(entity.path);
        final modified = await file.lastModified();
        if (!modified.isBefore(cutoff)) continue;
        try {
          await file.delete();
          deleted += 1;
        } catch (_) {
          // A concurrent discard/reaper may have won the race.
        }
      }
      try {
        if (await ownerDirectory.exists() &&
            (await ownerDirectory.list(followLinks: false).isEmpty)) {
          await ownerDirectory.delete();
        }
      } catch (_) {}
    }
    return deleted;
  }

  @override
  Future<void> clearOwner(int ownerUserId) async {
    if (ownerUserId <= 0) return;
    for (final root in [await _cacheRoot(), await _uploadRoot()]) {
      final owner = Directory(
        '${root.path}${Platform.pathSeparator}$ownerUserId',
      );
      if (await owner.exists()) {
        await owner.delete(recursive: true);
      }
    }
  }

  @override
  Future<void> clearAll() async {
    for (final root in [await _cacheRoot(), await _uploadRoot()]) {
      if (await root.exists()) {
        await root.delete(recursive: true);
      }
    }
  }

  Future<Directory> _cacheRoot() async {
    final support = await _supportDirectory();
    return Directory(
      '${support.path}${Platform.pathSeparator}profile_images',
    );
  }

  Future<Directory> _uploadRoot() async {
    final temporary = await _temporaryDirectory();
    return Directory(
      '${temporary.path}${Platform.pathSeparator}profile_uploads',
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

  static bool _isConfinedUploadPath(Directory root, File file) {
    final rootPath = root.absolute.path;
    final prefix = '$rootPath${Platform.pathSeparator}';
    final candidate = file.absolute.path;
    if (!candidate.startsWith(prefix)) return false;
    final relative = candidate.substring(prefix.length);
    final segments = relative.split(Platform.pathSeparator);
    return segments.length == 2 &&
        _ownerPattern.hasMatch(segments[0]) &&
        _uploadNamePattern.hasMatch(segments[1]);
  }

  static String _basename(String path) => path.split(Platform.pathSeparator).last;

  static String _randomToken() {
    final random = Random.secure();
    return List<int>.generate(16, (_) => random.nextInt(256))
        .map((value) => value.toRadixString(16).padLeft(2, '0'))
        .join();
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
