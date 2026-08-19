import 'dart:async';
import 'package:flutter/foundation.dart';
import 'api_service.dart';

/// Trust the last successful API call. Do not poke the radio every few
/// seconds on 2G — that is what made the skeleton sit there.
class ConnectivityService {
  static final ConnectivityService _instance = ConnectivityService._internal();
  factory ConnectivityService() => _instance;
  ConnectivityService._internal();

  final _controller = StreamController<bool>.broadcast();
  Stream<bool> get statusStream => _controller.stream;

  bool _isOnline = true;
  bool get isOnline => _isOnline;

  Timer? _checkTimer;
  bool _checking = false;

  void startMonitoring() {
    _checkTimer?.cancel();
    _checkTimer =
        Timer.periodic(const Duration(seconds: 45), (_) => checkNow());
  }

  void stopMonitoring() {
    _checkTimer?.cancel();
    _checkTimer = null;
  }

  Future<bool> checkNow() async {
    if (_checking) return _isOnline;
    _checking = true;
    try {
      final res = await ApiService().get('/ping', auth: false);
      _setOnline(res.success || res.statusCode > 0);
    } catch (_) {
      // Stay with the last known state. A failed ping on 2G is not "offline".
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

  void markOnline() => _setOnline(true);

  void markOffline() => _setOnline(false);

  void dispose() {
    _checkTimer?.cancel();
    _controller.close();
  }
}
