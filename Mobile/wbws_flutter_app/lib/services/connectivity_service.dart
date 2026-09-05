import 'dart:async';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

/// How Telegram / Facebook decide the top bar — not how they move messages.
///
/// 1. The phone radio (Wi‑Fi / 4G / none) is the only source of
///    "Waiting for network". Android ConnectivityManager, not an HTTP ping.
/// 2. A slow reply, a 5–15s timeout, or a failed /ping is NOT offline.
///    Telegram then shows "Connecting…", never "No internet".
/// 3. Some Androids lie and report disconnected while 4G works. Default
///    to "has a link". Only flip to waiting when the OS clearly says none.
/// 4. A successful call to our school proves the radio works.
class ConnectivityService {
  static final ConnectivityService _instance = ConnectivityService._internal();
  factory ConnectivityService() => _instance;
  ConnectivityService._internal();

  final _controller = StreamController<bool>.broadcast();
  Stream<bool> get statusStream => _controller.stream;

  final _connectivity = Connectivity();
  StreamSubscription<List<ConnectivityResult>>? _sub;

  /// Phone has Wi‑Fi, mobile data, or ethernet. Default true — Telegram
  /// does the same because some devices always report false.
  bool _hasLink = true;
  bool get hasLink => _hasLink;

  /// Same as [hasLink]. Kept so existing screens keep compiling.
  bool get isOnline => _hasLink;

  bool get isWaitingForNetwork => !_hasLink;

  void startMonitoring() {
    _sub?.cancel();
    _readOs();
    _sub = _connectivity.onConnectivityChanged.listen(_applyResults);
  }

  void stopMonitoring() {
    _sub?.cancel();
    _sub = null;
  }

  /// Re-read the phone radio. Never hits the school.
  Future<bool> checkNow() async {
    await _readOs();
    return _hasLink;
  }

  Future<void> _readOs() async {
    try {
      final results = await _connectivity.checkConnectivity();
      _applyResults(results);
    } catch (_) {
      // Stay with the last known state. A plugin blip is not airplane mode.
    }
  }

  /// True when the phone is on an UNMETERED interface (Wi‑Fi/ethernet).
  /// P33: the offline-download queue defaults to Wi‑Fi-only so a bulk
  /// "download this category" never quietly eats a mobile bundle.
  bool _unmetered = true;
  bool get isUnmetered => _unmetered;

  void _applyResults(List<ConnectivityResult> results) {
    // Treat any real interface as a link. `none` alone means waiting.
    final link = results.any((r) => r != ConnectivityResult.none);
    _unmetered = results.any((r) =>
        r == ConnectivityResult.wifi || r == ConnectivityResult.ethernet);
    _setHasLink(link);
  }

  void _setHasLink(bool link) {
    if (_hasLink == link) return;
    _hasLink = link;
    _controller.add(link);
    if (kDebugMode) {
      print('[Connectivity] ${link ? "HAS LINK" : "WAITING FOR NETWORK"}');
    }
  }

  /// A finished HTTP response from the school. Proves the radio works.
  void markOnline() => _setHasLink(true);

  /// Ignored. A timed-out or failed request must not paint "No internet"
  /// on a phone that still has 4G. Only the OS can declare no radio.
  void markOffline() {}

  void dispose() {
    _sub?.cancel();
    _controller.close();
  }
}
