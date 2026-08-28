import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';

/// App lock — Telegram-style local passcode (device-specific, not tied
/// to the account). Protects the app against someone holding an
/// UNLOCKED phone: the long-lived session stays signed in, but the
/// content sits behind a PIN (+ optional biometrics).
///
/// Security notes:
///   • PIN is stored as sha256(salt ‖ pin) in platform secure storage —
///     never in plain text, never on the server.
///   • Wrong attempts throttle locally; the hash can not be reversed to
///     the PIN, and there is no backdoor — "forgot passcode" means
///     signing out and back in (same posture as Telegram).
///   • Locking is a LOCAL gate; server sessions/tokens keep their own
///     rotation and revocation rules unchanged.
class AppLockService extends ChangeNotifier {
  static final AppLockService _instance = AppLockService._internal();
  factory AppLockService() => _instance;
  AppLockService._internal();

  static const _kPinHash = 'applock_pin_hash';
  static const _kSalt = 'applock_salt';
  static const _kAutoLock = 'applock_autolock_seconds';
  static const _kBiometric = 'applock_biometric_enabled';

  /// Auto-lock intervals offered in settings (Telegram-style ladder).
  static const autoLockOptions = <int, String>{
    0: 'Immediately',
    60: 'After 1 minute',
    300: 'After 5 minutes',
    3600: 'After 1 hour',
    86400: 'After 1 day',
  };

  final _secure = const FlutterSecureStorage();
  final LocalAuthentication _localAuth = LocalAuthentication();

  bool _locked = false;
  bool get isLocked => _locked;

  DateTime? _backgroundedAt;
  int _failedAttempts = 0;
  DateTime _lastFailure = DateTime.fromMillisecondsSinceEpoch(0);

  // ── state ───────────────────────────────────────────────────

  Future<bool> isConfigured() async {
    final h = await _secure.read(key: _kPinHash);
    return h != null && h.isNotEmpty;
  }

  Future<int> autoLockSeconds() async {
    final raw = await _secure.read(key: _kAutoLock);
    final v = int.tryParse('${raw ?? 300}');
    return v ?? 300;
  }

  Future<bool> biometricEnabled() async {
    final raw = await _secure.read(key: _kBiometric);
    return raw == '1';
  }

  // ── setup / change / disable (require knowledge of the PIN) ─

  Future<String?> setPin(String pin) async {
    final clean = _clean(pin);
    if (clean.length < 4) return 'Passcode must be at least 4 digits.';
    if (clean.length > 8) return 'Passcode must be at most 8 digits.';
    final salt = _newSalt();
    await _secure.write(key: _kSalt, value: salt);
    await _secure.write(key: _kPinHash, value: _hash(clean, salt));
    await _secure.write(key: _kAutoLock, value: '300');
    notifyListeners();
    return null;
  }

  Future<String?> changePin(String currentPin, String newPin) async {
    if (!await verifyPin(currentPin)) return 'Current passcode is wrong.';
    return setPin(newPin);
  }

  Future<String?> disable(String currentPin) async {
    if (!await verifyPin(currentPin)) return 'Current passcode is wrong.';
    await _secure.delete(key: _kPinHash);
    await _secure.delete(key: _kSalt);
    await _secure.delete(key: _kBiometric);
    _locked = false;
    notifyListeners();
    return null;
  }

  /// Reset the device lock together with the session (sign-out). This
  /// is the "forgot passcode" recovery path as well: the passcode is
  /// device-local, so it goes away with the session it was protecting.
  Future<void> clearPin() async {
    await _secure.delete(key: _kPinHash);
    await _secure.delete(key: _kSalt);
    await _secure.delete(key: _kBiometric);
    _locked = false;
    _failedAttempts = 0;
    notifyListeners();
  }

  Future<void> setAutoLockSeconds(int seconds) async {
    await _secure.write(key: _kAutoLock, value: '$seconds');
    notifyListeners();
  }

  Future<void> setBiometricEnabled(bool enabled) async {
    await _secure.write(key: _kBiometric, value: enabled ? '1' : '0');
    notifyListeners();
  }

  // ── verify / unlock ─────────────────────────────────────────

  /// Throttled PIN check: each wrong attempt pushes the next try a
  /// little further out (local, no server round trip).
  Future<bool> verifyPin(String pin) async {
    final wait = _throttleRemaining();
    if (wait > Duration.zero) return false;
    final stored = await _secure.read(key: _kPinHash);
    final salt = await _secure.read(key: _kSalt);
    if (stored == null || salt == null) return false;
    final ok = _hash(_clean(pin), salt) == stored;
    if (ok) {
      _failedAttempts = 0;
      markUnlocked();
    } else {
      _failedAttempts++;
      _lastFailure = DateTime.now();
    }
    return ok;
  }

  Duration _throttleRemaining() {
    if (_failedAttempts <= 0) return Duration.zero;
    // 1s after 1st miss, doubling per miss, capped at 30s.
    final secs = (1 << ((_failedAttempts - 1).clamp(0, 4)));
    final until = _lastFailure.add(Duration(seconds: secs));
    final left = until.difference(DateTime.now());
    return left.isNegative ? Duration.zero : left;
  }

  Future<bool> authenticateWithBiometrics() async {
    if (!await biometricEnabled()) return false;
    try {
      final canCheck = await _localAuth.canCheckBiometrics;
      final isSupported = await _localAuth.isDeviceSupported();
      if (!canCheck || !isSupported) return false;
      final ok = await _localAuth.authenticate(
        localizedReason: 'Unlock Felege Kidusan',
        options: const AuthenticationOptions(
          stickyAuth: true,
          biometricOnly: true,
        ),
      );
      if (ok) markUnlocked();
      return ok;
    } catch (_) {
      // Any platform hiccup falls back to the PIN — never an open door.
      return false;
    }
  }

  void markUnlocked() {
    _locked = false;
    _backgroundedAt = null;
    notifyListeners();
  }

  void lockNow() {
    _locked = true;
    notifyListeners();
  }

  // ── lifecycle (called by AppShell) ──────────────────────────

  void recordBackgrounded() {
    _backgroundedAt = DateTime.now();
  }

  /// On resume: lock when away longer than the auto-lock interval.
  /// Interval 0 = lock on every backgrounding (Telegram "Immediately").
  Future<void> evaluateOnResume() async {
    if (!await isConfigured()) return;
    final awayFor = _backgroundedAt == null
        ? null
        : DateTime.now().difference(_backgroundedAt!);
    final limit = Duration(seconds: await autoLockSeconds());
    if (awayFor == null || awayFor >= limit) {
      lockNow();
    } else {
      _backgroundedAt = null;
    }
  }

  /// Cold start: if a passcode is set, the app opens locked.
  Future<void> lockAtColdStartIfConfigured() async {
    if (await isConfigured()) {
      _locked = true;
      notifyListeners();
    }
  }

  // ── helpers ─────────────────────────────────────────────────

  String _clean(String pin) => pin.replaceAll(RegExp(r'[^0-9]'), '');

  String _newSalt() {
    final r = Random.secure();
    final bytes = List<int>.generate(16, (_) => r.nextInt(256));
    return base64Url.encode(bytes);
  }

  String _hash(String pin, String salt) {
    final bytes = utf8.encode('$salt::$pin');
    return sha256.convert(bytes).toString();
  }
}
