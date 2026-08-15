import 'dart:async';
import 'dart:io';
import 'package:flutter/foundation.dart';
import '../utils/config.dart';

/// Monitors network connectivity by pinging the API server.
/// Broadcasts online/offline status changes to listeners.
class ConnectivityService {
  static final ConnectivityService _instance = ConnectivityService._internal();
  factory ConnectivityService() => _instance;
  ConnectivityService._internal();

  final _controller = StreamController<bool>.broadcast();
  Stream<bool> get statusStream => _controller.stream;

  bool _isOnline = false; // Unknown until first check completes
  bool get isOnline => _isOnline;

  Timer? _checkTimer;
  bool _checking = false;

  /// Start periodic connectivity checks (every 10 seconds)
  void startMonitoring() {
    _checkTimer?.cancel();
    // Check immediately, then every 10 seconds
    checkNow();
    _checkTimer = Timer.periodic(const Duration(seconds: 10), (_) => checkNow());
  }

  /// Stop monitoring
  void stopMonitoring() {
    _checkTimer?.cancel();
    _checkTimer = null;
  }

  /// Check connectivity right now
  Future<bool> checkNow() async {
    if (_checking) return _isOnline;
    _checking = true;

    try {
      final uri = Uri.parse('${AppConfig.apiBaseUrl}/ping');
      final client = HttpClient();
      client.connectionTimeout = const Duration(seconds: 5);

      final request = await client.getUrl(uri);
      final response = await request.close().timeout(const Duration(seconds: 5));
      await response.drain();
      client.close(force: true);

      _setOnline(response.statusCode == 200);
    } catch (_) {
      // Try a simpler DNS lookup as fallback
      try {
        final result = await InternetAddress.lookup('wbws.pro.et')
            .timeout(const Duration(seconds: 3));
        _setOnline(result.isNotEmpty && result[0].rawAddress.isNotEmpty);
      } catch (_) {
        _setOnline(false);
      }
    }

    _checking = false;
    return _isOnline;
  }

  void _setOnline(bool online) {
    if (_isOnline != online) {
      _isOnline = online;
      _controller.add(online);
      if (kDebugMode) {
        print('[Connectivity] ${online ? "ONLINE" : "OFFLINE"}');
      }
    }
  }

  /// Call this when an API call succeeds (we know we're online)
  void markOnline() => _setOnline(true);

  /// Call this when an API call fails with network error
  void markOffline() => _setOnline(false);

  void dispose() {
    _checkTimer?.cancel();
    _controller.close();
  }
}
