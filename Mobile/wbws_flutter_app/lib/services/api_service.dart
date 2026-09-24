import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../utils/config.dart';
import 'connectivity_service.dart';
import 'outbox_policy.dart';
import 'session_models.dart';

/// API response wrapper
class ApiResponse {
  final bool success;
  final String? message;
  final dynamic data;
  final int statusCode;
  final bool isNetworkError;
  final bool isAuthError;

  /// Structured transport/protocol evidence used by durable outboxes. Callers
  /// classify this evidence separately from exact local settlement.
  final String? errorCode;
  final int? retryAfterSeconds;
  final bool idempotencyReplayed;
  final ApiFailureKind failureKind;

  /// The coordinator generation captured before this request left the
  /// process. A response from an older generation is never allowed to drive
  /// state or persist server data.
  final int requestGeneration;
  final bool sessionSuperseded;

  /// P74 Phase 3 — the `ETag` response header, when the server sends
  /// one (summary/thread conditional GETs). Null on ordinary calls.
  final String? etag;

  /// P74 Phase 3 — a conditional GET answered 304 Not Modified: the
  /// body is empty and the caller keeps its current state.
  bool get notModified => statusCode == 304;

  ApiResponse({
    required this.success,
    this.message,
    this.data,
    this.statusCode = 200,
    this.isNetworkError = false,
    this.isAuthError = false,
    this.errorCode,
    this.retryAfterSeconds,
    this.idempotencyReplayed = false,
    this.failureKind = ApiFailureKind.none,
    this.requestGeneration = 0,
    this.sessionSuperseded = false,
    this.etag,
  });

  factory ApiResponse.fromJson(Map<String, dynamic> json, int code,
      {Map<String, String> headers = const {}, String? etag}) {
    final data = json['data'];
    final nestedCode = data is Map ? data['code'] : null;
    final errorCode = '${json['code'] ?? nestedCode ?? ''}'.trim();
    final success = json['status'] == 'success';
    final failureKind = success
        ? ApiFailureKind.none
        : _failureKindForHttp(code, errorCode);
    return ApiResponse(
      success: success,
      message: json['message'],
      // Some endpoints wrap their payload in `data`, while the Mezmur
      // contract returns fields such as `items` at the response root.
      data: data ?? json,
      statusCode: code,
      isAuthError: code == 401 || code == 403,
      errorCode: errorCode.isEmpty ? null : errorCode,
      retryAfterSeconds: _retryAfterSeconds(headers['retry-after']),
      idempotencyReplayed:
          headers['idempotency-replayed']?.toLowerCase() == 'true',
      failureKind: failureKind,
      etag: etag,
    );
  }

  factory ApiResponse.error(String msg,
      [int code = 0,
      bool network = false,
      ApiFailureKind failureKind = ApiFailureKind.unknown]) {
    return ApiResponse(
      success: false,
      message: msg,
      statusCode: code,
      isNetworkError: network,
      isAuthError: code == 401 || code == 403,
      failureKind: failureKind,
    );
  }

  factory ApiResponse.superseded(int generation) => ApiResponse(
        success: false,
        message: 'This request belongs to an older signed-in session.',
        requestGeneration: generation,
        sessionSuperseded: true,
      );

  ApiResponse withGeneration(int generation) => ApiResponse(
        success: success,
        message: message,
        data: data,
        statusCode: statusCode,
        isNetworkError: isNetworkError,
        isAuthError: isAuthError,
        errorCode: errorCode,
        retryAfterSeconds: retryAfterSeconds,
        idempotencyReplayed: idempotencyReplayed,
        failureKind: failureKind,
        requestGeneration: generation,
        sessionSuperseded: sessionSuperseded,
        etag: etag,
      );

  OutboxResponseEvidence toOutboxEvidence({
    required int automaticAttemptCount,
    bool hasCanonicalConflictItem = false,
  }) =>
      OutboxResponseEvidence(
        success: success,
        statusCode: statusCode,
        errorCode: errorCode,
        idempotencyReplayed: idempotencyReplayed,
        hasCanonicalConflictItem: hasCanonicalConflictItem,
        failureKind: sessionSuperseded
            ? ApiFailureKind.unknown
            : failureKind,
        refreshOutcome:
            sessionSuperseded ? AuthRefreshOutcome.superseded : null,
        automaticAttemptCount: automaticAttemptCount,
      );

  static ApiFailureKind _failureKindForHttp(int status, String code) {
    if (const {
      'AUTH_SCOPE_CHANGED',
      'AUTH_SCOPE_REFRESH_REQUIRED',
    }.contains(code)) {
      return ApiFailureKind.authorizationScope;
    }
    if (status == 401 || const {
      'INVALID_REFRESH_TOKEN',
      'REFRESH_EXPIRED',
      'REFRESH_REUSED',
      'REFRESH_REVOKED',
      'ACCOUNT_DISABLED',
      'ACCOUNT_REMOVED',
    }.contains(code)) {
      return ApiFailureKind.authentication;
    }
    return ApiFailureKind.http;
  }

  static int? _retryAfterSeconds(String? value) {
    final seconds = int.tryParse('${value ?? ''}'.trim());
    if (seconds == null) return null;
    if (seconds < 1) return 1;
    if (seconds > 3600) return 3600;
    return seconds;
  }
}

/// Core API client — singleton
class ApiService {
  static final ApiService _instance = ApiService._internal();
  factory ApiService() => _instance;
  ApiService._internal();

  final _connectivity = ConnectivityService();
  final _secureStorage = const FlutterSecureStorage();
  final http.Client _http = http.Client();
  final Map<String, Future<ApiResponse>> _getInflight = {};

  String? _token;
  String? _refreshToken;
  Map<String, dynamic>? _userData;
  Future<bool>? _refreshInFlight;
  int? _refreshInFlightGeneration;
  Future<void> _credentialMutationTail = Future<void>.value();
  bool _refreshWasRejected = false;
  bool _authExpiryNotified = false;

  /// Installed by SessionCoordinator once at bootstrap. ApiService never chooses a
  /// root or destroys local data; it reports definitive credential loss to the
  /// one coordinator which owns that transition.
  Future<void> Function(String reason)? onAuthExpired;
  int Function()? sessionGenerationProvider;

  // Getters
  String? get token => _token;
  Map<String, dynamic>? get userData => _userData;
  bool get isLoggedIn => _token != null && _refreshToken != null;
  String get userRole => _userData?['role']?.toString() ?? '';
  String get userName => _userData?['full_name']?.toString() ?? '';
  int get userId => _positiveInt(_userData?['id']) ?? 0;
  int get authorizationVersion =>
      _nonNegativeInt(_userData?['authorization_version']) ?? 0;

  int get _requestGeneration => sessionGenerationProvider?.call() ?? 0;
  bool _generationIsCurrent(int captured) =>
      captured == (sessionGenerationProvider?.call() ?? 0);

  /// Read secure credentials without mutating them. In particular, exceptions
  /// are not converted into "logged out" and never trigger credential deletion.
  Future<CredentialLoadResult> loadCredentials() async {
    _clearMemoryCredentials();

    String? token;
    String? refreshToken;
    String? userJson;
    try {
      token = await _secureStorage.read(key: AppConfig.tokenKey);
      refreshToken =
          await _secureStorage.read(key: AppConfig.refreshTokenKey);
      userJson = await _secureStorage.read(key: AppConfig.userDataKey);
    } on MissingPluginException catch (error) {
      return CredentialLoadResult.storageUnavailable('$error');
    } on PlatformException catch (error) {
      return CredentialLoadResult.storageUnavailable('$error');
    } catch (error) {
      return CredentialLoadResult.unreadable('$error');
    }

    // Versions <= 1.1.14 stored only the profile in SharedPreferences. The
    // plaintext key is removed strictly after the secure write succeeds.
    if (userJson == null) {
      try {
        final prefs = await SharedPreferences.getInstance();
        final legacyUserJson = prefs.getString(AppConfig.userDataKey);
        if (legacyUserJson != null && legacyUserJson.isNotEmpty) {
          try {
            await _secureStorage.write(
              key: AppConfig.userDataKey,
              value: legacyUserJson,
            );
          } on MissingPluginException catch (error) {
            return CredentialLoadResult.storageUnavailable('$error');
          } on PlatformException catch (error) {
            return CredentialLoadResult.storageUnavailable('$error');
          } catch (error) {
            return CredentialLoadResult.unreadable('$error');
          }
          userJson = legacyUserJson;
          await prefs.remove(AppConfig.userDataKey);
        }
      } catch (error) {
        return CredentialLoadResult.unreadable('$error');
      }
    }

    final present = [token, refreshToken, userJson]
        .where((value) => value != null && value.isNotEmpty)
        .length;
    if (present == 0) return const CredentialLoadResult.absent();
    if (present != 3) {
      return const CredentialLoadResult.incomplete(
        'Secure credentials are present but incomplete.',
      );
    }

    final bundle = _bundleFromValues(token!, refreshToken!, userJson!);
    if (bundle == null || !bundle.isComplete) {
      return const CredentialLoadResult.unreadable(
        'The protected staff profile is malformed.',
      );
    }
    return CredentialLoadResult.complete(bundle);
  }

  /// Backward-compatible name for bootstrap callers. The typed result must be
  /// inspected; it is never flattened to a nullable session.
  Future<CredentialLoadResult> init() => loadCredentials();

  AuthBundle? _bundleFromValues(
      String token, String refreshToken, String userJson) {
    try {
      final decoded = jsonDecode(userJson);
      if (decoded is! Map<String, dynamic>) return null;
      final userId = _positiveInt(decoded['id']);
      final role = decoded['role']?.toString().trim() ?? '';
      if (userId == null || role.isEmpty) return null;
      return AuthBundle(
        accessToken: token,
        refreshToken: refreshToken,
        userId: userId,
        username: decoded['username']?.toString() ?? '',
        displayName: decoded['full_name']?.toString() ?? '',
        role: role,
        authorizationVersion:
            _nonNegativeInt(decoded['authorization_version']) ?? 0,
        userJson: userJson,
      );
    } catch (_) {
      return null;
    }
  }

  AuthBundle? bundleFromLoginResponse(ApiResponse response) {
    if (!response.success || response.data is! Map) return null;
    final data = Map<String, dynamic>.from(response.data as Map);
    final token = data['token'];
    final refreshToken = data['refresh_token'];
    final rawUser = data['user'];
    if (token is! String ||
        token.isEmpty ||
        refreshToken is! String ||
        refreshToken.isEmpty ||
        rawUser is! Map) {
      return null;
    }
    final user = Map<String, dynamic>.from(rawUser);
    return _bundleFromValues(token, refreshToken, jsonEncode(user));
  }

  /// Phase two of login. SessionCoordinator calls this only after owner and local
  /// inventory reconciliation succeeds. Candidate login credentials remain in
  /// memory owned by the call stack until this point.
  Future<void> activateCredentials(AuthBundle bundle) async {
    final decoded = jsonDecode(bundle.userJson);
    if (decoded is! Map<String, dynamic>) {
      throw const FormatException('Invalid staff profile');
    }
    await _serializeCredentialMutation(() async {
      await _secureStorage.write(
          key: AppConfig.userDataKey, value: bundle.userJson);
      await _secureStorage.write(
          key: AppConfig.refreshTokenKey, value: bundle.refreshToken);
      // Commit marker last: bootstrap never accepts an access token without
      // the profile and one-time refresh token which support it.
      await _secureStorage.write(
          key: AppConfig.tokenKey, value: bundle.accessToken);
      _token = bundle.accessToken;
      _refreshToken = bundle.refreshToken;
      _userData = Map<String, dynamic>.from(decoded);
      _authExpiryNotified = false;
      _refreshWasRejected = false;
    });
  }

  /// Adopt a complete bundle which was already read from secure storage. No
  /// writes occur during this bootstrap-only operation.
  void adoptLoadedCredentials(AuthBundle bundle) {
    final decoded = jsonDecode(bundle.userJson);
    if (decoded is! Map<String, dynamic>) {
      throw const FormatException('Invalid staff profile');
    }
    _token = bundle.accessToken;
    _refreshToken = bundle.refreshToken;
    _userData = Map<String, dynamic>.from(decoded);
    _authExpiryNotified = false;
    _refreshWasRejected = false;
  }

  Future<void> revokeBundle(AuthBundle bundle) async {
    try {
      await _http
          .post(
            Uri.parse('${AppConfig.apiBaseUrl}/auth/logout'),
            headers: _headers(withAuth: false),
            body: jsonEncode({'refresh_token': bundle.refreshToken}),
          )
          .timeout(const Duration(seconds: 5));
    } catch (_) {
      // Rejection/owner mismatch must remain safe while offline. Candidate
      // credentials are never activated even if best-effort revocation fails.
    }
  }

  Future<void> clearCredentials() async {
    // Clear memory before waiting so no new authenticated request can start.
    _clearMemoryCredentials();
    await _serializeCredentialMutation(() async {
      await _secureStorage.delete(key: AppConfig.tokenKey);
      await _secureStorage.delete(key: AppConfig.refreshTokenKey);
      await _secureStorage.delete(key: AppConfig.userDataKey);
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(AppConfig.userDataKey);
    });
  }

  Future<T> _serializeCredentialMutation<T>(Future<T> Function() action) async {
    final previous = _credentialMutationTail;
    final done = Completer<void>();
    _credentialMutationTail = done.future;
    try {
      await previous;
    } catch (_) {
      // The previous caller receives its own error; it must not deadlock the
      // next fail-closed cleanup attempt.
    }
    try {
      return await action();
    } finally {
      done.complete();
    }
  }

  void _clearMemoryCredentials() {
    _token = null;
    _refreshToken = null;
    _userData = null;
    _authExpiryNotified = false;
    _refreshWasRejected = false;
  }

  /// Revoke the currently active server-side refresh family and clear only
  /// credentials. Private SQLite/PIN policy belongs to SessionCoordinator.
  Future<void> logout() async {
    final bundle = _currentBundle();
    if (bundle != null) await revokeBundle(bundle);
    await clearCredentials();
  }

  AuthBundle? _currentBundle() {
    final token = _token;
    final refreshToken = _refreshToken;
    final user = _userData;
    if (token == null || refreshToken == null || user == null) return null;
    return _bundleFromValues(token, refreshToken, jsonEncode(user));
  }

  static int? _positiveInt(Object? value) {
    final parsed = value is int ? value : int.tryParse('${value ?? ''}');
    return parsed != null && parsed > 0 ? parsed : null;
  }

  static int? _nonNegativeInt(Object? value) {
    final parsed = value is int ? value : int.tryParse('${value ?? ''}');
    return parsed != null && parsed >= 0 ? parsed : null;
  }

  /// Build headers
  Map<String, String> _headers({bool withAuth = true}) {
    final h = <String, String>{
      'Content-Type': 'application/json',
      'X-App-Version': AppConfig.appVersion,
      'X-App-Build': '${AppConfig.appBuild}',
    };
    if (withAuth && _token != null) {
      h['Authorization'] = 'Bearer $_token';
    }
    return h;
  }

  /// Core GET request. Reuses one TLS session (Telegram keeps a socket open)
  /// and collapses identical in-flight reads so Home + WarmStore + Sync
  /// do not open three handshakes on 4G.
  Future<ApiResponse> get(String path,
      {Map<String, String>? params,
      bool auth = true,
      Map<String, String>? headers}) async {
    var uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
    if (params != null && params.isNotEmpty) {
      uri = uri.replace(queryParameters: params);
    }
    final generation = _requestGeneration;
    // P74 Phase 3: conditional GETs carry caller-specific headers
    // (If-None-Match), so they never share an in-flight slot with a
    // plain GET of the same URI. A generation is part of the key so a newly
    // activated owner can never inherit the previous owner's future.
    if (headers == null) {
      final key = '$generation:${uri.toString()}';
      final existing = _getInflight[key];
      if (existing != null) return existing;

      final future = _doGet(uri, auth, null, generation);
      _getInflight[key] = future;
      try {
        return await future;
      } finally {
        if (identical(_getInflight[key], future)) {
          _getInflight.remove(key);
        }
      }
    }
    return _doGet(uri, auth, headers, generation);
  }

  Future<ApiResponse> _doGet(Uri uri, bool auth,
      Map<String, String>? extraHeaders, int generation) async {
    try {
      final sentToken = auth ? _token : null;
      Map<String, String> requestHeaders = _headers(withAuth: auth);
      if (extraHeaders != null) requestHeaders.addAll(extraHeaders);
      var response = await _http
          .get(uri, headers: requestHeaders)
          .timeout(Duration(seconds: AppConfig.connectionTimeout));
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      if (response.statusCode == 401 && auth) {
        final refreshed = (_token != null && _token != sentToken) ||
            await refreshAccessToken();
        if (!_generationIsCurrent(generation)) {
          return ApiResponse.superseded(generation);
        }
        if (refreshed) {
          final retryHeaders = _headers(withAuth: true);
          if (extraHeaders != null) retryHeaders.addAll(extraHeaders);
          response = await _http
              .get(uri, headers: retryHeaders)
              .timeout(Duration(seconds: AppConfig.connectionTimeout));
        } else {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      final handled = await _handleResponseAsync(response);
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return handled.withGeneration(generation);
    } catch (e) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(e).withGeneration(generation);
    }
  }

  /// Core POST request
  Future<ApiResponse> post(String path,
      {Map<String, dynamic>? body,
      bool auth = true,
      String? idempotencyKey}) async {
    final generation = _requestGeneration;
    try {
      final uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
      var headers = _headers(withAuth: auth);
      final sentToken = auth ? _token : null;
      final key = (idempotencyKey ?? '').trim();
      if (key.isNotEmpty) {
        headers['Idempotency-Key'] = key;
        body = {...?body, 'client_op_id': key};
      }
      var response = await _http
          .post(
            uri,
            headers: headers,
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(Duration(seconds: AppConfig.postTimeout));
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      if (response.statusCode == 401 && auth) {
        final refreshed = (_token != null && _token != sentToken) ||
            await refreshAccessToken();
        if (!_generationIsCurrent(generation)) {
          return ApiResponse.superseded(generation);
        }
        if (refreshed) {
          headers = _headers(withAuth: true);
          if (key.isNotEmpty) headers['Idempotency-Key'] = key;
          response = await _http
              .post(
                uri,
                headers: headers,
                body: body != null ? jsonEncode(body) : null,
              )
              .timeout(Duration(seconds: AppConfig.postTimeout));
        } else {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleResponse(response).withGeneration(generation);
    } catch (e) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(e).withGeneration(generation);
    }
  }

  /// Core PUT request
  Future<ApiResponse> put(String path, {Map<String, dynamic>? body}) async {
    final generation = _requestGeneration;
    try {
      final uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
      final sentToken = _token;
      var response = await _http
          .put(
            uri,
            headers: _headers(),
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(Duration(seconds: AppConfig.postTimeout));
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      if (response.statusCode == 401) {
        final refreshed = (_token != null && _token != sentToken) ||
            await refreshAccessToken();
        if (!_generationIsCurrent(generation)) {
          return ApiResponse.superseded(generation);
        }
        if (refreshed) {
          response = await _http
              .put(
                uri,
                headers: _headers(),
                body: body != null ? jsonEncode(body) : null,
              )
              .timeout(Duration(seconds: AppConfig.postTimeout));
        } else {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleResponse(response).withGeneration(generation);
    } catch (e) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(e).withGeneration(generation);
    }
  }

  /// Handle response
  ApiResponse _handleResponse(http.Response response) {
    // Mark as online since we got a response
    _connectivity.markOnline();

    try {
      final json = _decodeJson(response.body);
      if (json is Map<String, dynamic>) {
        return ApiResponse.fromJson(json, response.statusCode,
            headers: response.headers);
      }
      return ApiResponse(
        success: false,
        message: _httpErrorLabel(response.statusCode),
        statusCode: response.statusCode,
        retryAfterSeconds:
            ApiResponse._retryAfterSeconds(response.headers['retry-after']),
        failureKind: ApiFailureKind.protocol,
      );
    } catch (e) {
      return ApiResponse(
        success: false,
        message: _httpErrorLabel(response.statusCode),
        statusCode: response.statusCode,
        retryAfterSeconds:
            ApiResponse._retryAfterSeconds(response.headers['retry-after']),
        failureKind: ApiFailureKind.protocol,
      );
    }
  }

  /// Async variant used by GETs: large payloads (member rosters, reports)
  /// are JSON-decoded in an isolate so a page arriving mid-fling never
  /// stalls the UI thread. Small payloads stay on the main isolate because
  /// spawning one costs more than parsing them.
  Future<ApiResponse> _handleResponseAsync(http.Response response) async {
    _connectivity.markOnline();
    // P74 Phase 3 — conditional GETs: a 304 has an empty body by
    // design; surface it as notModified with the (re-sent) ETag.
    if (response.statusCode == 304) {
      return ApiResponse(
        success: false,
        message: 'Not modified',
        statusCode: 304,
        etag: response.headers['etag'],
      );
    }
    try {
      final body = response.body;
      final dynamic json = body.length > 32 * 1024
          ? await compute(_decodeJsonIsolate, body)
          : _decodeJson(body);
      if (json is Map<String, dynamic>) {
        return ApiResponse.fromJson(json, response.statusCode,
            headers: response.headers, etag: response.headers['etag']);
      }
      return ApiResponse(
        success: false,
        message: _httpErrorLabel(response.statusCode),
        statusCode: response.statusCode,
        retryAfterSeconds:
            ApiResponse._retryAfterSeconds(response.headers['retry-after']),
        failureKind: ApiFailureKind.protocol,
        etag: response.headers['etag'],
      );
    } catch (_) {
      return ApiResponse(
        success: false,
        message: _httpErrorLabel(response.statusCode),
        statusCode: response.statusCode,
        retryAfterSeconds:
            ApiResponse._retryAfterSeconds(response.headers['retry-after']),
        failureKind: ApiFailureKind.protocol,
        etag: response.headers['etag'],
      );
    }
  }

  /// Isolate-safe copy of [_decodeJson] (no instance state).
  static dynamic _decodeJsonIsolate(String body) {
    final trimmed = body.trim();
    if (trimmed.isEmpty) return null;
    try {
      return jsonDecode(trimmed);
    } catch (_) {}
    final start = trimmed.indexOf('{');
    final end = trimmed.lastIndexOf('}');
    if (start >= 0 && end > start) {
      try {
        return jsonDecode(trimmed.substring(start, end + 1));
      } catch (_) {}
    }
    return null;
  }

  /// WhatsApp/Gmail: never show "parse failed". Pull JSON out of mixed HTML.
  dynamic _decodeJson(String body) {
    final trimmed = body.trim();
    if (trimmed.isEmpty) return null;
    try {
      return jsonDecode(trimmed);
    } catch (_) {}
    final start = trimmed.indexOf('{');
    final end = trimmed.lastIndexOf('}');
    if (start >= 0 && end > start) {
      try {
        return jsonDecode(trimmed.substring(start, end + 1));
      } catch (_) {}
    }
    return null;
  }

  String _httpErrorLabel(int code) {
    if (code == 429) return 'School is busy. Will retry on its own.';
    if (code == 409) return 'Already submitted. Only Education can change it.';
    if (code >= 500) return 'School is busy. Your work is still on this phone.';
    if (code == 401 || code == 403) return 'Please sign in again.';
    return 'Could not finish this request. Your work is still on this phone.';
  }

  /// Handle network errors.
  /// Timeout ≠ offline. Only a missing radio is a network error.
  ApiResponse _handleError(dynamic error) {
    final msg = error.toString();
    if (msg.contains('TimeoutException')) {
      return ApiResponse.error(
          'The school is taking longer than usual. Try again.',
          0,
          false,
          ApiFailureKind.timeout);
    }
    if (msg.contains('SocketException') ||
        msg.contains('HandshakeException') ||
        msg.contains('OS Error')) {
      final noRadio = !_connectivity.hasLink;
      return ApiResponse.error(
        noRadio
            ? 'Waiting for network. Your work is still on this phone.'
            : 'Could not reach the school right now. Your work is still on this phone.',
        0,
        noRadio,
        ApiFailureKind.transport,
      );
    }
    return ApiResponse.error('Could not finish this request. Please try again.');
  }

  Future<void> _notifyIfRefreshRejected() async {
    if (_refreshWasRejected && !_authExpiryNotified && onAuthExpired != null) {
      _authExpiryNotified = true;
      await onAuthExpired!('refresh_rejected');
    }
  }

  // ============================================================
  // AUTH
  // ============================================================

  /// Phase one of login: authenticate only. The returned candidate is never
  /// persisted here; SessionCoordinator performs owner/inventory reconciliation
  /// before calling [activateCredentials].
  Future<ApiResponse> login(String username, String password) =>
      post('/auth/login', body: {
        'username': username,
        'password': password,
      }, auth: false);

  /// Rotate the refresh token exactly once even when several requests receive
  /// a 401 together. This prevents a legitimate app from looking like a replay.
  Future<bool> refreshAccessToken() async {
    final generation = _requestGeneration;
    final existing = _refreshInFlight;
    if (existing != null && _refreshInFlightGeneration == generation) {
      return existing;
    }

    // A refresh from a superseded generation may still be unwinding after
    // logout. The new owner never joins that future; both remain safe because
    // credential writes are serialized and generation/token checked.
    final attempt = _performRefreshAccessToken();
    _refreshInFlight = attempt;
    _refreshInFlightGeneration = generation;
    try {
      return await attempt;
    } finally {
      if (identical(_refreshInFlight, attempt)) {
        _refreshInFlight = null;
        _refreshInFlightGeneration = null;
      }
    }
  }

  Future<bool> _performRefreshAccessToken() async {
    final generation = _requestGeneration;
    final presentedRefreshToken = _refreshToken;
    _refreshWasRejected = presentedRefreshToken == null;
    if (presentedRefreshToken == null) return false;

    try {
      final response = await _http
          .post(
            Uri.parse('${AppConfig.apiBaseUrl}/auth/refresh-token'),
            headers: _headers(withAuth: false),
            body: jsonEncode({'refresh_token': presentedRefreshToken}),
          )
          .timeout(Duration(seconds: AppConfig.postTimeout));
      if (!_generationIsCurrent(generation) ||
          _refreshToken != presentedRefreshToken) {
        return false;
      }
      _connectivity.markOnline();
      _refreshWasRejected =
          response.statusCode == 401 || response.statusCode == 403;

      final decoded = _decodeJson(response.body);
      if (response.statusCode < 200 ||
          response.statusCode >= 300 ||
          decoded is! Map<String, dynamic> ||
          decoded['status'] != 'success' ||
          decoded['data'] is! Map<String, dynamic>) {
        return false;
      }

      final data = decoded['data'] as Map<String, dynamic>;
      final nextToken = data['token'];
      final nextRefreshToken = data['refresh_token'];
      if (nextToken is! String ||
          nextToken.isEmpty ||
          nextRefreshToken is! String ||
          nextRefreshToken.isEmpty) {
        return false;
      }

      return _serializeCredentialMutation(() async {
        if (!_generationIsCurrent(generation) ||
            _refreshToken != presentedRefreshToken) {
          return false;
        }
        // Persist the one-time refresh token first. If the process stops
        // between writes, bootstrap reports incomplete credentials and keeps
        // private SQLite state behind recovery rather than guessing.
        await _secureStorage.write(
            key: AppConfig.refreshTokenKey, value: nextRefreshToken);
        await _secureStorage.write(key: AppConfig.tokenKey, value: nextToken);
        _refreshToken = nextRefreshToken;
        _token = nextToken;
        _refreshWasRejected = false;
        _authExpiryNotified = false;
        return true;
      });
    } catch (error) {
      // Network and 5xx failures keep the local session and offline data. Only
      // an explicit server rejection asks AppShell to sign the user out.
      return false;
    }
  }

  // ============================================================
  // DASHBOARD
  // ============================================================

  Future<ApiResponse> getDashboardStats() => get('/dashboard/stats');
  Future<ApiResponse> getRecentActivity({int limit = 20}) =>
      get('/dashboard/recent', params: {'limit': '$limit'});

  // ============================================================
  // MEMBERS
  // ============================================================

  Future<ApiResponse> getMembers(
      {int page = 1,
      int limit = 20,
      String? search,
      String? status,
      String? gender}) {
    final params = <String, String>{'page': '$page', 'limit': '$limit'};
    if (search != null && search.isNotEmpty) params['search'] = search;
    if (status != null && status.isNotEmpty) params['status'] = status;
    if (gender != null && gender.isNotEmpty) params['gender'] = gender;
    return get('/members', params: params);
  }

  Future<ApiResponse> getMember(int id) => get('/members/$id');
  Future<ApiResponse> createMember(Map<String, dynamic> data) =>
      post('/members', body: data);
  Future<ApiResponse> updateMember(int id, Map<String, dynamic> data) =>
      put('/members/$id', body: data);
  Future<ApiResponse> getMemberAttendance(int id, {int days = 90}) =>
      get('/members/$id/attendance', params: {'days': '$days'});

  // ── Phase 9: department review inbox (approve / return) ───────
  // dept ∈ {edu, mezmur, hr} — each department reviews ONLY its own
  // packets; the server re-checks the bearer's role on every call.
  String _reviewBase(String dept) =>
      dept == 'edu' ? '/grades' : (dept == 'hr' ? '/hr' : '/mezmur');

  Future<ApiResponse> getReviewSubmissions(String dept,
      {String status = 'attention', int page = 1}) {
    final params = <String, String>{'page': '$page', 'per_page': '50'};
    if (dept == 'edu') {
      params['status_filter'] = status;
    } else {
      params['status'] = status;
    }
    return get('${_reviewBase(dept)}/submissions', params: params);
  }

  Future<ApiResponse> getReviewSubmission(String dept, int id) =>
      get('${_reviewBase(dept)}/submission', params: {'id': '$id'});

  Future<ApiResponse> reviewSubmission(
    String dept,
    int id,
    String status, {
    String notes = '',
    String? clientOpId,
  }) =>
      post('${_reviewBase(dept)}/submission-review',
          body: {'id': id, 'status': status, 'notes': notes},
          idempotencyKey: clientOpId);

  // ============================================================
  // CLASSES
  // ============================================================

  Future<ApiResponse> getClasses() => get('/classes');
  Future<ApiResponse> getClassStudents(int classId) =>
      get('/classes/$classId/students');

  // ============================================================
  // ATTENDANCE
  // ============================================================

  Future<ApiResponse> getAttendance(int classId, {String? date}) {
    final params = <String, String>{'class_id': '$classId'};
    if (date != null) params['date'] = date;
    return get('/attendance', params: params);
  }

  Future<ApiResponse> saveAttendance(
          int classId, String date, List<Map<String, dynamic>> records,
          {String? clientOpId}) =>
      post('/attendance',
          body: {
            'class_id': classId,
            'date': date,
            'records': records,
          },
          idempotencyKey: clientOpId);

  Future<ApiResponse> submitAttendance(
          int classId, String date, List<Map<String, dynamic>> records,
          {String? clientOpId}) =>
      post('/attendance/submit',
          body: {
            'class_id': classId,
            'date': date,
            'records': records,
          },
          idempotencyKey: clientOpId);

  // ── Mezmur department (date-based, section-grouped) ─────────
  Future<ApiResponse> getMezmurDays(
      {int page = 1, String? from, String? to}) {
    final params = <String, String>{'page': '$page'};
    if (from != null && from.isNotEmpty) params['from'] = from;
    if (to != null && to.isNotEmpty) params['to'] = to;
    return get('/mezmur/days', params: params);
  }

  Future<ApiResponse> createMezmurDay({
    required String date,
    required String programType,
    String? title,
    String? notes,
  }) {
    return post('/mezmur/days', body: {
      'date': date,
      'program_type': programType,
      if (title != null && title.isNotEmpty) 'title': title,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
    });
  }

  Future<ApiResponse> getMezmurSheet(String date, {String? section}) {
    final params = <String, String>{'date': date};
    if (section != null && section.isNotEmpty) params['section'] = section;
    return get('/mezmur/sheet', params: params);
  }

  /// Section-scoped save (teacher clone). [kind] = 'draft' | 'submitted'.
  Future<ApiResponse> saveMezmurSheet(
      String date, List<Map<String, dynamic>> records,
      {String? section, String kind = 'draft', String? clientOpId}) {
    return post('/mezmur/sheet',
        body: {
          'date': date,
          'records': records,
          if (section != null && section.isNotEmpty) 'section': section,
          if (section != null && section.isNotEmpty) 'kind': kind,
        },
        idempotencyKey: clientOpId);
  }

  /// Active sections with member counts (for the [Section ▾] picker).
  Future<ApiResponse> getMezmurSections() => get('/mezmur/sections');

  // ── HR department attendance (section-based, HR's own domain) ──
  // Isolation rule: HR data never mixes with Education or Mezmur.
  // Available to hr_attendance_taker / hr_dept / admins only.
  Future<ApiResponse> getHrDays({int page = 1, String? from, String? to}) {
    final params = <String, String>{'page': '$page'};
    if (from != null && from.isNotEmpty) params['from'] = from;
    if (to != null && to.isNotEmpty) params['to'] = to;
    return get('/hr/days', params: params);
  }

  Future<ApiResponse> getHrSheet(String date, {String? section}) {
    final params = <String, String>{'date': date};
    if (section != null && section.isNotEmpty) params['section'] = section;
    return get('/hr/sheet', params: params);
  }

  /// Section-scoped save. [kind] = 'draft' | 'submitted'.
  Future<ApiResponse> saveHrSheet(
      String date, List<Map<String, dynamic>> records,
      {String? section, String kind = 'draft', String? clientOpId}) {
    return post('/hr/sheet',
        body: {
          'date': date,
          'records': records,
          if (section != null && section.isNotEmpty) 'section': section,
          if (section != null && section.isNotEmpty) 'kind': kind,
        },
        idempotencyKey: clientOpId);
  }

  /// Active sections with member counts (for the [Section ▾] picker).
  Future<ApiResponse> getHrSections() => get('/hr/sections');

  Future<ApiResponse> getMezmurHymns(
      {int page = 1,
      int perPage = 25,
      String? search,
      String? category,
      int? categoryId,
      int? zemarianId,
      String? length,
      String? language,
      String? status}) {
    final params = <String, String>{'page': '$page', 'per_page': '$perPage'};
    if (search != null && search.isNotEmpty) params['search'] = search;
    if (category != null && category.isNotEmpty) params['category'] = category;
    if (categoryId != null && categoryId > 0) {
      params['category_id'] = '$categoryId';
    }
    if (zemarianId != null && zemarianId > 0) {
      params['zemarian_id'] = '$zemarianId';
    }
    if (length != null && length.isNotEmpty) params['length'] = length;
    if (language != null && language.isNotEmpty) params['language'] = language;
    if (status != null && status.isNotEmpty) params['status'] = status;
    return get('/mezmur/hymns', params: params);
  }

  Future<ApiResponse> getMezmurHymn(int id) =>
      get('/mezmur/hymn', params: {'id': '$id'});

  /// P46: store timed (LRC) lyrics for a hymn.
  ///
  /// Empty [lrc] clears the timings and falls back to static lyrics.
  /// Safe to retry: the server does a full REPLACE of one column keyed
  /// by hymn id, so applying the same body twice is identical to once.
  Future<ApiResponse> saveMezmurSyncedLyrics(int hymnId, String lrc,
      {String? clientOpId}) {
    // `body:` is a NAMED parameter, and idempotencyKey both sets the
    // Idempotency-Key header and injects client_op_id — the same
    // contract every other mezmur write uses.
    return post('/mezmur/lyrics-synced',
        body: {'id': hymnId, 'lrc': lrc}, idempotencyKey: clientOpId);
  }

  /// Returns a short-lived signed GET URL for a verified hymn audio object.
  /// The object key and storage credentials never leave the server.
  Future<ApiResponse> getMezmurAudioUrl(int hymnId) =>
      get('/mezmur/audio/$hymnId');

  // ── Hymn library offline sync (delta + outbox) ──────────────

  /// Delta pull: rows changed after [cursor] ("ts|id" change token).
  /// Metadata only unless [includeLyrics] — lyrics are heavy blobs
  /// downloaded lazily.
  Future<ApiResponse> getMezmurHymnsChanges(
      {String cursor = '', int limit = 200, bool includeLyrics = false}) {
    final params = <String, String>{'limit': '$limit'};
    if (cursor.isNotEmpty) params['cursor'] = cursor;
    if (includeLyrics) params['include_lyrics'] = '1';
    return get('/mezmur/hymns/changes', params: params);
  }

  /// Create/update a hymn. [baseRevision] enables conflict detection
  /// for offline edits (server returns 409 + the newest copy).
  Future<ApiResponse> saveMezmurHymn(Map<String, dynamic> hymn,
      {String? clientOpId, int? baseRevision}) {
    final body = Map<String, dynamic>.from(hymn);
    if (baseRevision != null) body['base_revision'] = baseRevision;
    return post('/mezmur/hymn', body: body, idempotencyKey: clientOpId);
  }

  Future<ApiResponse> setMezmurHymnStatus(int id, String status,
      {String? clientOpId}) {
    return post('/mezmur/hymn-status',
        body: {'id': id, 'status': status}, idempotencyKey: clientOpId);
  }

  Future<ApiResponse> getMezmurCategories() => get('/mezmur/categories');

  Future<ApiResponse> saveMezmurCategory(Map<String, dynamic> category,
      {String? clientOpId}) {
    return post('/mezmur/category',
        body: Map<String, dynamic>.from(category), idempotencyKey: clientOpId);
  }

  /// Multipart cover-image upload for a hymn category. Binary body —
  /// never queued; callers gate it on connectivity.
  Future<ApiResponse> uploadCategoryImage(int id, String filePath) =>
      _uploadTaxonomyImage('/mezmur/category-image', id, filePath);

  /// P34: singer cover images ride the same hardened chain.
  Future<ApiResponse> uploadZemarianImage(int id, String filePath) =>
      _uploadTaxonomyImage('/mezmur/zemarian-image', id, filePath);

  Future<ApiResponse> _uploadTaxonomyImage(
      String path, int id, String filePath) async {
    final generation = _requestGeneration;
    try {
      Future<http.Response> send() async {
        final uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
        final req = http.MultipartRequest('POST', uri)
          ..fields['id'] = '$id'
          ..files.add(await http.MultipartFile.fromPath('image', filePath));
        // P33 fix: the JSON content-type header broke the multipart
        // boundary, so the server saw no file at all — strip it and
        // let the multipart writer set its own content-type.
        final hs = _headers(withAuth: true);
        hs.remove('Content-Type');
        req.headers.addAll(hs);
        final streamed = await _http.send(req).timeout(
            const Duration(seconds: 60)); // image bytes need a longer leash
        return http.Response.fromStream(streamed);
      }

      var response = await send();
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      if (response.statusCode == 401) {
        final refreshed = await refreshAccessToken();
        if (!_generationIsCurrent(generation)) {
          return ApiResponse.superseded(generation);
        }
        if (refreshed) {
          response = await send();
        } else {
          await _notifyIfRefreshRejected();
        }
      }
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleResponse(response).withGeneration(generation);
    } catch (e) {
      if (!_generationIsCurrent(generation)) {
        return ApiResponse.superseded(generation);
      }
      return _handleError(e).withGeneration(generation);
    }
  }

  /// Drop a category's cover image (the gradient shows instead).
  Future<ApiResponse> removeCategoryImage(int id) {
    return post('/mezmur/category-image-remove', body: {'id': id});
  }

  /// P66 hymn art: per-hymn cover upload (multipart; the same hardened
  /// server chain as taxonomy covers — magic bytes, re-encode, square
  /// renditions, dominant color). Binary body — never queued; callers
  /// gate it on connectivity.
  Future<ApiResponse> uploadMezmurHymnArt(int id, String filePath) =>
      _uploadTaxonomyImage('/mezmur/art', id, filePath);

  /// P66 hymn art: drop a hymn's cover (the name-hash gradient shows).
  Future<ApiResponse> removeMezmurHymnArt(int id) {
    return post('/mezmur/art-remove', body: {'id': id});
  }

  Future<ApiResponse> setMezmurCategoryStatus(int id, bool active,
      {String? clientOpId}) {
    return post('/mezmur/category-status',
        body: {'id': id, 'active': active}, idempotencyKey: clientOpId);
  }

  Future<ApiResponse> getMezmurZemarians() => get('/mezmur/zemarians');

  Future<ApiResponse> saveMezmurZemarian(Map<String, dynamic> zemarian,
      {String? clientOpId}) {
    return post('/mezmur/zemarian',
        body: Map<String, dynamic>.from(zemarian), idempotencyKey: clientOpId);
  }

  Future<ApiResponse> setMezmurZemarianStatus(int id, bool active,
      {String? clientOpId}) {
    return post('/mezmur/zemarian-status',
        body: {'id': id, 'active': active}, idempotencyKey: clientOpId);
  }

  Future<ApiResponse> getMezmurAnalytics(
      {Map<String, String>? params}) =>
      get('/mezmur/analytics', params: params);

  Future<ApiResponse> getDailyStats({String? date}) {
    final params = <String, String>{};
    if (date != null) params['date'] = date;
    return get('/attendance/daily-stats', params: params);
  }

  // ============================================================
  // GRADES
  // ============================================================

  Future<ApiResponse> getGradeBootstrap(int classId) =>
      get('/grades/bootstrap', params: {'class_id': '$classId'});

  Future<ApiResponse> getClassSubjects(int classId) =>
      get('/grades/subjects', params: {'class_id': '$classId'});

  Future<ApiResponse> getAssessments(int classId, int subjectId) =>
      get('/grades/assessments',
          params: {'class_id': '$classId', 'subject_id': '$subjectId'});

  Future<ApiResponse> createAssessment(Map<String, dynamic> data) =>
      post('/grades/assessments', body: data);

  Future<ApiResponse> getGradeStudents(int assessmentId) =>
      get('/grades/students', params: {'assessment_id': '$assessmentId'});

  Future<ApiResponse> saveGrades(
          int assessmentId, List<Map<String, dynamic>> grades,
          {String? clientOpId}) =>
      post('/grades/save',
          body: {
            'assessment_id': assessmentId,
            'grades': grades,
          },
          idempotencyKey: clientOpId);

  Future<ApiResponse> submitGrades(
          int assessmentId, List<Map<String, dynamic>> grades,
          {String? clientOpId}) =>
      post('/grades/submit',
          body: {
            'assessment_id': assessmentId,
            'grades': grades,
          },
          idempotencyKey: clientOpId);

  Future<ApiResponse> getGradeSummary(int classId, {int? subjectId}) {
    final params = <String, String>{'class_id': '$classId'};
    if (subjectId != null) params['subject_id'] = '$subjectId';
    return get('/grades/summary', params: params);
  }

  // ============================================================
  // EDUCATION (read + one-student enroll — create teacher stays on website)
  // ============================================================

  Future<ApiResponse> getTeachers({int page = 1, int limit = 50, String? search}) {
    final params = <String, String>{'page': '$page', 'limit': '$limit'};
    if (search != null && search.isNotEmpty) params['q'] = search;
    return get('/teachers', params: params);
  }

  Future<ApiResponse> getTeacher(int id) => get('/teachers/$id');

  Future<ApiResponse> getSubjects() => get('/subjects');

  Future<ApiResponse> getEnrollmentOverview() => get('/enrollment/overview');

  Future<ApiResponse> searchEnrollment(String q, {int limit = 20}) =>
      get('/enrollment/search', params: {'q': q, 'limit': '$limit'});

  Future<ApiResponse> enrollStudent(int memberId, int classId) =>
      post('/enrollment', body: {'member_id': memberId, 'class_id': classId});

  // ============================================================
  // P72 — Communication Center (notifications / announcements /
  // messaging). Same service as the web dashboards; one writer.
  // ============================================================

  /// P74 Phase 3 — conditional GET: with [ifNoneMatch] (a previously
  /// stored ETag) the server answers 304 + an empty body when nothing
  /// changed; `ApiResponse.notModified` tells the poller to no-op.
  Future<ApiResponse> getNotificationSummary({String? ifNoneMatch}) =>
      get('/notifications/summary',
          headers: (ifNoneMatch == null || ifNoneMatch.isEmpty)
              ? null
              : {'If-None-Match': ifNoneMatch});

  Future<ApiResponse> getNotificationFeed(
      {int limit = 30,
      int offset = 0,
      bool unreadOnly = false,
      int? beforeId}) {
    final params = <String, String>{
      'limit': '$limit',
      'offset': '$offset',
      if (unreadOnly) 'unread': '1',
      // P74 Phase 3 — stable cursor for "Load older".
      if (beforeId != null && beforeId > 0) 'before_id': '$beforeId',
    };
    return get('/notifications/feed', params: params);
  }

  Future<ApiResponse> markNotificationRead(int id) =>
      post('/notifications/mark-read', body: {'id': id});

  Future<ApiResponse> markAllNotificationsRead({String scope = 'alerts'}) =>
      post('/notifications/mark-all-read', body: {'scope': scope});

  Future<ApiResponse> getAnnouncements(
      {int limit = 30, int offset = 0, int? beforeId, int? beforePin}) {
    return get('/notifications/announcements', params: {
      'limit': '$limit',
      'offset': '$offset',
      // P74 Phase 3 — (before_pin, before_id) tuple cursor.
      if (beforeId != null && beforeId > 0) 'before_id': '$beforeId',
      if (beforePin != null && beforePin >= 0 && beforeId != null)
        'before_pin': '$beforePin',
    });
  }

  Future<ApiResponse> markAnnouncementRead(int id) =>
      post('/notifications/announcement-read', body: {'id': id});

  Future<ApiResponse> composeAnnouncement(
      {required String title,
      required String body,
      String priority = 'normal',
      String audience = 'roles',
      List<String> roles = const [],
      List<int> userIds = const []}) {
    return post('/notifications/compose', body: {
      'title': title,
      'body': body,
      'priority': priority,
      'audience': audience,
      'roles': roles,
      'user_ids': userIds,
    });
  }

  Future<ApiResponse> getAnnounceTargets() =>
      get('/notifications/targets');

  Future<ApiResponse> getMessagePartners() =>
      get('/notifications/partners');

  Future<ApiResponse> getThreads() => get('/notifications/threads');

  /// P74 Phase 2 — the thread window. With [beforeId] this fetches the
  /// OLDER page below that message id ("Load older"); without it, the
  /// newest window (200) plus has_older/oldest_id/read_watermark.
  ///
  /// P74 Phase 4 — [ifNoneMatch] makes the newest-window fetch a
  /// conditional GET (the open-conversation poll): a 304 means
  /// nothing in the thread changed — no body, no markRead write.
  /// Older-page fetches (beforeId) must NOT be conditional: their
  /// ETag seed differs (window variant).
  Future<ApiResponse> getThread(int id, {int? beforeId, String? ifNoneMatch}) =>
      get('/notifications/thread',
          params: {
            'id': '$id',
            if (beforeId != null && beforeId > 0) 'before_id': '$beforeId',
          },
          headers: (ifNoneMatch == null || ifNoneMatch.isEmpty || (beforeId != null && beforeId > 0))
              ? null
              : {'If-None-Match': ifNoneMatch});

  Future<ApiResponse> startThread(
          {required List<int> to,
          required String subject,
          required String body}) =>
      post('/notifications/thread-start',
          body: {'to': to, 'subject': subject, 'body': body});

  /// O3 — [clientTag] rides the payload for exactly-once sending. The
  /// server ignores it until migration 046 lands (messages.client_tag
  /// unique index); from then on a drained-and-retried send can never
  /// duplicate. Harmless extra field today by design.
  Future<ApiResponse> sendMessage(int threadId, String body,
          {String? clientTag}) =>
      post('/notifications/send-message', body: {
        'thread_id': threadId,
        'body': body,
        if (clientTag != null && clientTag.isNotEmpty) 'client_tag': clientTag,
      });

  /// P74 Phase 2 — Telegram-grade own-message management (same
  /// actions as the web center; ownership enforced server-side).
  Future<ApiResponse> editMessage(int messageId, String body) =>
      post('/notifications/message-edit',
          body: {'message_id': messageId, 'body': body});

  Future<ApiResponse> deleteMessage(int messageId) =>
      post('/notifications/message-delete', body: {'message_id': messageId});

  /// Explicit mark-read without refetching the conversation (the full
  /// window GET already marks read; this is for flows that change the
  /// thread without a refetch).
  Future<ApiResponse> markThreadRead(int id) =>
      post('/notifications/thread-read', body: {'id': id});
}
