import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../utils/config.dart';
import 'connectivity_service.dart';

/// API response wrapper
class ApiResponse {
  final bool success;
  final String? message;
  final dynamic data;
  final int statusCode;
  final bool isNetworkError;
  final bool isAuthError;

  ApiResponse({
    required this.success,
    this.message,
    this.data,
    this.statusCode = 200,
    this.isNetworkError = false,
    this.isAuthError = false,
  });

  factory ApiResponse.fromJson(Map<String, dynamic> json, int code) {
    return ApiResponse(
      success: json['status'] == 'success',
      message: json['message'],
      // Some endpoints wrap their payload in `data`, while the Mezmur
      // contract returns fields such as `items` at the response root.
      data: json['data'] ?? json,
      statusCode: code,
      isAuthError: code == 401 || code == 403,
    );
  }

  factory ApiResponse.error(String msg, [int code = 0, bool network = false]) {
    return ApiResponse(
      success: false,
      message: msg,
      statusCode: code,
      isNetworkError: network,
      isAuthError: code == 401 || code == 403,
    );
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
  bool _refreshWasRejected = false;
  bool _authExpiryNotified = false;
  bool _discardedInvalidSession = false;

  // Auth expiry callback — set by AppShell to handle token expiry
  void Function()? onAuthExpired;

  // Getters
  String? get token => _token;
  Map<String, dynamic>? get userData => _userData;
  bool get isLoggedIn => _token != null;
  bool get discardedInvalidSession => _discardedInvalidSession;
  String get userRole => _userData?['role'] ?? '';
  String get userName => _userData?['full_name'] ?? '';
  int get userId => _userData?['id'] ?? 0;

  /// Initialize credentials and migrate legacy plaintext profile metadata.
  Future<void> init() async {
    // Secure storage is best-effort at bootstrap: a keystore hiccup must
    // never dead-end the whole app. A failed read simply means "not signed
    // in"; the login screen is reached and the next launch retries.
    Future<String?> _read(String key) async {
      try {
        return await _secureStorage.read(key: key);
      } catch (_) {
        return null;
      }
    }

    final token = await _read(AppConfig.tokenKey);
    final refreshToken = await _read(AppConfig.refreshTokenKey);
    var userJson = await _read(AppConfig.userDataKey);

    // Versions <= 1.1.14 stored the staff profile in SharedPreferences. Move it
    // to platform secure storage once, then remove the plaintext value.
    String? legacyUserJson;
    try {
      final prefs = await SharedPreferences.getInstance();
      legacyUserJson = prefs.getString(AppConfig.userDataKey);
      if (userJson == null && legacyUserJson != null) {
        try {
          await _secureStorage.write(
              key: AppConfig.userDataKey, value: legacyUserJson);
        } catch (_) {}
        userJson = legacyUserJson;
      }
      if (legacyUserJson != null) {
        try {
          await prefs.remove(AppConfig.userDataKey);
        } catch (_) {}
      }
    } catch (_) {}

    Map<String, dynamic>? user;
    if (userJson != null) {
      try {
        final decoded = jsonDecode(userJson);
        if (decoded is Map<String, dynamic>) user = decoded;
      } catch (_) {}
    }

    // A session is accepted only as a complete token + refresh + role binding.
    if (token == null || refreshToken == null || user == null) {
      _discardedInvalidSession = token != null ||
          refreshToken != null ||
          userJson != null ||
          legacyUserJson != null;
      await _secureStorage.delete(key: AppConfig.tokenKey);
      await _secureStorage.delete(key: AppConfig.refreshTokenKey);
      await _secureStorage.delete(key: AppConfig.userDataKey);
      _token = null;
      _refreshToken = null;
      _userData = null;
      return;
    }
    _token = token;
    _refreshToken = refreshToken;
    _userData = user;
    _discardedInvalidSession = false;
  }

  /// Persist tokens and staff profile only in platform encrypted storage.
  Future<void> _saveTokens(
      String token, String refreshToken, Map<String, dynamic> user) async {
    // Commit supporting state first and the access token last. If the process
    // stops between writes, startup never accepts a token without its profile.
    await _secureStorage.write(
        key: AppConfig.userDataKey, value: jsonEncode(user));
    await _secureStorage.write(key: AppConfig.refreshTokenKey, value: refreshToken);
    await _secureStorage.write(key: AppConfig.tokenKey, value: token);
    _token = token;
    _refreshToken = refreshToken;
    _userData = user;
    _authExpiryNotified = false;
    _discardedInvalidSession = false;
  }

  /// Revoke the server-side refresh family, then clear all local credentials.
  Future<void> logout() async {
    final presentedRefreshToken = _refreshToken;
    if (presentedRefreshToken != null && presentedRefreshToken.isNotEmpty) {
      try {
        await _http
            .post(
              Uri.parse('${AppConfig.apiBaseUrl}/auth/logout'),
              headers: _headers(withAuth: false),
              body: jsonEncode({'refresh_token': presentedRefreshToken}),
            )
            .timeout(const Duration(seconds: 5));
      } catch (_) {
        // Offline sign-out must still erase sensitive local data and tokens.
      }
    }

    _token = null;
    _refreshToken = null;
    _userData = null;
    await _secureStorage.delete(key: AppConfig.tokenKey);
    await _secureStorage.delete(key: AppConfig.refreshTokenKey);
    await _secureStorage.delete(key: AppConfig.userDataKey);
    // Defense-in-depth cleanup for upgrades from the legacy plaintext store.
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(AppConfig.userDataKey);
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
      {Map<String, String>? params, bool auth = true}) async {
    var uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
    if (params != null && params.isNotEmpty) {
      uri = uri.replace(queryParameters: params);
    }
    final key = uri.toString();
    final existing = _getInflight[key];
    if (existing != null) return existing;

    final future = _doGet(uri, auth);
    _getInflight[key] = future;
    try {
      return await future;
    } finally {
      if (identical(_getInflight[key], future)) {
        _getInflight.remove(key);
      }
    }
  }

  Future<ApiResponse> _doGet(Uri uri, bool auth) async {
    try {
      final sentToken = auth ? _token : null;
      var response = await _http
          .get(uri, headers: _headers(withAuth: auth))
          .timeout(Duration(seconds: AppConfig.connectionTimeout));
      if (response.statusCode == 401 && auth) {
        final refreshed = (_token != null && _token != sentToken)
            || await refreshAccessToken();
        if (refreshed) {
          response = await _http
              .get(uri, headers: _headers(withAuth: true))
              .timeout(Duration(seconds: AppConfig.connectionTimeout));
        } else {
          _notifyIfRefreshRejected();
        }
      }
      return await _handleResponseAsync(response);
    } catch (e) {
      return _handleError(e);
    }
  }

  /// Core POST request
  Future<ApiResponse> post(String path,
      {Map<String, dynamic>? body, bool auth = true, String? idempotencyKey}) async {
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
      if (response.statusCode == 401 && auth) {
        final refreshed = (_token != null && _token != sentToken)
            || await refreshAccessToken();
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
          _notifyIfRefreshRejected();
        }
      }
      return _handleResponse(response);
    } catch (e) {
      return _handleError(e);
    }
  }

  /// Core PUT request
  Future<ApiResponse> put(String path, {Map<String, dynamic>? body}) async {
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
      if (response.statusCode == 401) {
        final refreshed = (_token != null && _token != sentToken)
            || await refreshAccessToken();
        if (refreshed) {
          response = await _http
              .put(
                uri,
                headers: _headers(),
                body: body != null ? jsonEncode(body) : null,
              )
              .timeout(Duration(seconds: AppConfig.postTimeout));
        } else {
          _notifyIfRefreshRejected();
        }
      }
      return _handleResponse(response);
    } catch (e) {
      return _handleError(e);
    }
  }

  /// Handle response
  ApiResponse _handleResponse(http.Response response) {
    // Mark as online since we got a response
    _connectivity.markOnline();

    try {
      final json = _decodeJson(response.body);
      if (json is Map<String, dynamic>) {
        return ApiResponse.fromJson(json, response.statusCode);
      }
      return ApiResponse.error(
          _httpErrorLabel(response.statusCode), response.statusCode);
    } catch (e) {
      return ApiResponse.error(
          _httpErrorLabel(response.statusCode), response.statusCode);
    }
  }

  /// Async variant used by GETs: large payloads (member rosters, reports)
  /// are JSON-decoded in an isolate so a page arriving mid-fling never
  /// stalls the UI thread. Small payloads stay on the main isolate because
  /// spawning one costs more than parsing them.
  Future<ApiResponse> _handleResponseAsync(http.Response response) async {
    _connectivity.markOnline();
    try {
      final body = response.body;
      final dynamic json = body.length > 32 * 1024
          ? await compute(_decodeJsonIsolate, body)
          : _decodeJson(body);
      if (json is Map<String, dynamic>) {
        return ApiResponse.fromJson(json, response.statusCode);
      }
      return ApiResponse.error(
          _httpErrorLabel(response.statusCode), response.statusCode);
    } catch (_) {
      return ApiResponse.error(
          _httpErrorLabel(response.statusCode), response.statusCode);
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
          'The school is taking longer than usual. Try again.');
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
      );
    }
    return ApiResponse.error('Could not finish this request. Please try again.');
  }

  void _notifyIfRefreshRejected() {
    if (_refreshWasRejected && !_authExpiryNotified && onAuthExpired != null) {
      _authExpiryNotified = true;
      onAuthExpired!();
    }
  }

  // ============================================================
  // AUTH
  // ============================================================

  Future<ApiResponse> login(String username, String password) async {
    final res = await post('/auth/login', body: {
      'username': username,
      'password': password,
    }, auth: false);

    if (res.success && res.data != null) {
      await _saveTokens(
        res.data['token'],
        res.data['refresh_token'],
        res.data['user'],
      );
    }
    return res;
  }

  /// Rotate the refresh token exactly once even when several requests receive
  /// a 401 together. This prevents a legitimate app from looking like a replay.
  Future<bool> refreshAccessToken() async {
    final existing = _refreshInFlight;
    if (existing != null) return existing;

    final attempt = _performRefreshAccessToken();
    _refreshInFlight = attempt;
    try {
      return await attempt;
    } finally {
      if (identical(_refreshInFlight, attempt)) {
        _refreshInFlight = null;
      }
    }
  }

  Future<bool> _performRefreshAccessToken() async {
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
      _connectivity.markOnline();
      _refreshWasRejected = response.statusCode == 401 || response.statusCode == 403;

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

      // Persist the one-time refresh token first. If the process stops between
      // writes, the app can still recover instead of replaying the old token.
      await _secureStorage.write(
          key: AppConfig.refreshTokenKey, value: nextRefreshToken);
      await _secureStorage.write(key: AppConfig.tokenKey, value: nextToken);
      _refreshToken = nextRefreshToken;
      _token = nextToken;
      _refreshWasRejected = false;
      _authExpiryNotified = false;
      return true;
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
      if (response.statusCode == 401) {
        final refreshed = await refreshAccessToken();
        if (refreshed) {
          response = await send();
        } else {
          _notifyIfRefreshRejected();
        }
      }
      return _handleResponse(response);
    } catch (e) {
      return _handleError(e);
    }
  }

  /// Drop a category's cover image (the gradient shows instead).
  Future<ApiResponse> removeCategoryImage(int id) {
    return post('/mezmur/category-image-remove', body: {'id': id});
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
}
