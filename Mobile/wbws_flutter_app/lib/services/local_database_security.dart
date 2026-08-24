import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:sqflite_sqlcipher/sqflite.dart';

/// A fail-closed error shown by the bootstrap UI without filesystem/key detail.
class OfflineDataSecurityException implements Exception {
  const OfflineDataSecurityException();
}

/// Owns the random SQLCipher key. It is never hardcoded or stored beside the DB.
class LocalDatabaseKeyStore {
  static const _storageKey = 'fkss_offline_database_key_v1';
  static const _storage = FlutterSecureStorage(
    // Keep this device-bound on Apple platforms. Android uses its Keystore-
    // wrapped default storage. Android app backup is disabled in the manifest.
    iOptions: IOSOptions(
      accessibility: KeychainAccessibility.unlocked_this_device,
    ),
    mOptions: MacOsOptions(
      accessibility: KeychainAccessibility.unlocked_this_device,
    ),
  );

  Future<String> loadOrCreate({required bool encryptedDataExists}) async {
    try {
      final stored = await _storage.read(key: _storageKey);
      if (_isValid(stored)) return stored!;

      // Never silently replace a missing/corrupt key while encrypted student
      // data exists. That would make recovery impossible and hide key loss.
      if (encryptedDataExists) {
        throw const OfflineDataSecurityException();
      }

      final random = Random.secure();
      final bytes = List<int>.generate(32, (_) => random.nextInt(256));
      final key = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
      await _storage.write(key: _storageKey, value: key);
      final confirmed = await _storage.read(key: _storageKey);
      if (confirmed != key) {
        throw const OfflineDataSecurityException();
      }
      return key;
    } on OfflineDataSecurityException {
      rethrow;
    } catch (_) {
      throw const OfflineDataSecurityException();
    }
  }

  bool _isValid(String? value) =>
      value != null && RegExp(r'^[0-9a-f]{64}$').hasMatch(value);
}

/// Crash-safe plaintext SQLite -> SQLCipher migration.
///
/// The original database remains intact until an encrypted export has its
/// marker, version, and integrity verified. Fixed sibling names let startup
/// resume safely after interruption at any filesystem swap step.
class EncryptedDatabaseMigrator {
  static const _sqliteHeader = 'SQLite format 3\u0000';
  static const _applicationId = 1397968211; // ASCII "SSMS"
  static const _tempSuffix = '.encrypted-migration';
  static const _backupSuffix = '.plaintext-migration-backup';

  Future<bool> encryptedArtifactsExist(String databasePath) async {
    for (final path in [databasePath, '$databasePath$_tempSuffix']) {
      final file = File(path);
      if (await file.exists() && !await _isPlaintextSqlite(path)) return true;
    }
    return false;
  }

  Future<void> ensureEncrypted(String databasePath, String password) async {
    final main = File(databasePath);
    final temp = File('$databasePath$_tempSuffix');
    final backup = File('$databasePath$_backupSuffix');

    try {
      // Recover the only possible no-main states from an interrupted swap.
      if (!await main.exists()) {
        if (await temp.exists() &&
            await _verifyEncrypted(temp.path, password)) {
          await _deleteSidecars(temp.path);
          await temp.rename(main.path);
          await _verifyOrThrow(main.path, password);
          await _secureDelete(backup.path, required: true);
          return;
        }
        await _secureDelete(temp.path);
        if (await backup.exists()) {
          await backup.rename(main.path);
        } else {
          return; // First install; openDatabase will create an encrypted DB.
        }
      }

      if (!await _isPlaintextSqlite(main.path)) {
        await _verifyOrThrow(main.path, password);
        await _secureDelete(temp.path);
        await _secureDelete(backup.path, required: true);
        return;
      }

      // A complete encrypted export can be reused after a process interruption.
      if (await temp.exists()) {
        if (await _verifyEncrypted(temp.path, password)) {
          await _activate(main, temp, backup, password);
          return;
        }
        await _secureDelete(temp.path);
      }

      await _exportPlaintext(main.path, temp.path, password);
      await _verifyOrThrow(temp.path, password);
      await _activate(main, temp, backup, password);
    } on OfflineDataSecurityException {
      rethrow;
    } catch (_) {
      // Do not destructively reset offline attendance/grades on migration error.
      throw const OfflineDataSecurityException();
    }
  }

  Future<void> configureEncryptedDatabase(Database db) async {
    await db.execute('PRAGMA foreign_keys = ON');
    await db.execute('PRAGMA secure_delete = ON');
    await db.execute('PRAGMA application_id = $_applicationId');
  }

  Future<void> _exportPlaintext(
    String plainPath,
    String encryptedPath,
    String password,
  ) async {
    Database? plain;
    var attached = false;
    try {
      // SQLCipher deliberately accepts an empty key for plaintext databases.
      plain = await openDatabase(
        plainPath,
        password: null,
        singleInstance: false,
      );
      try {
        await plain.rawQuery('PRAGMA wal_checkpoint(FULL)');
      } catch (_) {}
      try {
        await plain.rawQuery('PRAGMA journal_mode=DELETE');
      } catch (_) {}

      final versionRows = await plain.rawQuery('PRAGMA user_version');
      final version = _firstInt(versionRows, 'user_version');
      await plain.execute('ATTACH DATABASE ? AS encrypted KEY ?', [
        encryptedPath,
        password,
      ]);
      attached = true;
      await plain.rawQuery("SELECT sqlcipher_export('encrypted')");
      await plain.execute('PRAGMA encrypted.user_version = $version');
      await plain.execute('PRAGMA encrypted.application_id = $_applicationId');
      final integrity = await plain.rawQuery(
        'PRAGMA encrypted.integrity_check',
      );
      if (!_integrityOk(integrity)) {
        throw const OfflineDataSecurityException();
      }
      await plain.execute('DETACH DATABASE encrypted');
      attached = false;
    } finally {
      if (plain != null) {
        if (attached) {
          try {
            await plain.execute('DETACH DATABASE encrypted');
          } catch (_) {}
        }
        await plain.close();
      }
    }
  }

  Future<void> _activate(
    File main,
    File temp,
    File backup,
    String password,
  ) async {
    await _secureDelete(backup.path, required: true);
    await _deleteSidecars(main.path);
    await _deleteSidecars(temp.path);
    await main.rename(backup.path);
    var activated = false;
    try {
      await temp.rename(main.path);
      activated = true;
      await _verifyOrThrow(main.path, password);
    } catch (_) {
      if (activated && await main.exists()) {
        await main.delete(); // encrypted copy; no plaintext scrubbing needed
      }
      if (!await main.exists() && await backup.exists()) {
        await backup.rename(main.path);
      }
      rethrow;
    }
    // Cleanup happens after the rollback boundary. If logical deletion fails
    // after overwrite, retain the verified encrypted main rather than destroy
    // it and attempt to restore an already-scrubbed recovery file.
    await _secureDelete(backup.path, required: true);
  }

  Future<void> _verifyOrThrow(String path, String password) async {
    if (!await _verifyEncrypted(path, password)) {
      throw const OfflineDataSecurityException();
    }
  }

  Future<bool> _verifyEncrypted(String path, String password) async {
    if (!await File(path).exists() || await _isPlaintextSqlite(path))
      return false;
    Database? encrypted;
    try {
      encrypted = await openDatabase(
        path,
        password: password,
        singleInstance: false,
      );
      try {
        await encrypted.rawQuery('PRAGMA wal_checkpoint(FULL)');
        await encrypted.rawQuery('PRAGMA journal_mode=DELETE');
      } catch (_) {}
      final markerRows = await encrypted.rawQuery('PRAGMA application_id');
      if (_firstInt(markerRows, 'application_id') != _applicationId)
        return false;
      final integrity = await encrypted.rawQuery('PRAGMA integrity_check');
      return _integrityOk(integrity);
    } catch (_) {
      return false;
    } finally {
      await encrypted?.close();
    }
  }

  bool _integrityOk(List<Map<String, Object?>> rows) =>
      rows.length == 1 && rows.first.values.firstOrNull == 'ok';

  int _firstInt(List<Map<String, Object?>> rows, String key) {
    if (rows.isEmpty) return -1;
    final value = rows.first[key] ?? rows.first.values.firstOrNull;
    return value is int ? value : int.tryParse('$value') ?? -1;
  }

  Future<bool> _isPlaintextSqlite(String path) async {
    final file = File(path);
    if (!await file.exists()) return false;
    RandomAccessFile? handle;
    try {
      handle = await file.open(mode: FileMode.read);
      final header = await handle.read(16);
      if (header.length != 16) return false;
      return String.fromCharCodes(header) == _sqliteHeader;
    } finally {
      await handle?.close();
    }
  }

  Future<void> _deleteSidecars(String databasePath) async {
    for (final suffix in ['-wal', '-shm', '-journal']) {
      await _secureDelete('$databasePath$suffix', required: true);
    }
  }

  Future<void> _secureDelete(String path, {bool required = false}) async {
    final file = File(path);
    if (!await file.exists()) return;
    RandomAccessFile? handle;
    try {
      // Capture length before FileMode.write truncates, then overwrite the
      // logical file with zeroes. Flash wear levelling prevents a promise of
      // physical erasure, but this minimizes recoverable plaintext remnants.
      final length = await file.length();
      handle = await file.open(mode: FileMode.write);
      final zeros = Uint8List(64 * 1024);
      var remaining = length;
      while (remaining > 0) {
        final count = min(remaining, zeros.length);
        await handle.writeFrom(zeros, 0, count);
        remaining -= count;
      }
      await handle.flush();
    } catch (_) {
      // Continue to logical deletion below. Required artifacts fail closed if
      // that deletion itself cannot be completed.
    } finally {
      await handle?.close();
    }
    try {
      await file.delete();
    } catch (_) {
      if (required) throw const OfflineDataSecurityException();
    }
  }
}

extension _FirstOrNull<E> on Iterable<E> {
  E? get firstOrNull => isEmpty ? null : first;
}
