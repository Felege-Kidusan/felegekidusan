import 'dart:convert';
import 'package:http/http.dart' as http;
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
      data: json['data'],
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

  // Auth expiry callback — set by AppShell to handle token expiry
  void Function()? onAuthExpired;

  // Getters
  String? get token => _token;
  Map<String, dynamic>? get userData => _userData;
  bool get isLoggedIn => _token != null;
  String get userRole => _userData?['role'] ?? '';
  String get userName => _userData?['full_name'] ?? '';
  int get userId => _userData?['id'] ?? 0;

  /// Initialize — load saved token from encrypted storage
  Future<void> init() async {
    _token = await _secureStorage.read(key: AppConfig.tokenKey);
    _refreshToken = await _secureStorage.read(key: AppConfig.refreshTokenKey);
    final prefs = await SharedPreferences.getInstance();
    final userJson = prefs.getString(AppConfig.userDataKey);
    if (userJson != null) {
      try {
        _userData = jsonDecode(userJson);
      } catch (_) {}
    }
  }

  /// Save tokens to encrypted storage, user data to SharedPreferences
  Future<void> _saveTokens(
      String token, String refreshToken, Map<String, dynamic> user) async {
    _token = token;
    _refreshToken = refreshToken;
    _userData = user;
    await _secureStorage.write(key: AppConfig.tokenKey, value: token);
    await _secureStorage.write(key: AppConfig.refreshTokenKey, value: refreshToken);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(AppConfig.userDataKey, jsonEncode(user));
  }

  /// Clear tokens (logout)
  Future<void> logout() async {
    _token = null;
    _refreshToken = null;
    _userData = null;
    await _secureStorage.delete(key: AppConfig.tokenKey);
    await _secureStorage.delete(key: AppConfig.refreshTokenKey);
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(AppConfig.userDataKey);
  }

  /// Build headers
  Map<String, String> _headers({bool withAuth = true}) {
    final h = <String, String>{'Content-Type': 'application/json'};
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
      final response = await _http
          .get(uri, headers: _headers(withAuth: auth))
          .timeout(Duration(seconds: AppConfig.connectionTimeout));
      return _handleResponse(response);
    } catch (e) {
      return _handleError(e);
    }
  }

  /// Core POST request
  Future<ApiResponse> post(String path,
      {Map<String, dynamic>? body, bool auth = true, String? idempotencyKey}) async {
    try {
      final uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
      final headers = _headers(withAuth: auth);
      final key = (idempotencyKey ?? '').trim();
      if (key.isNotEmpty) {
        headers['Idempotency-Key'] = key;
        body = {...?body, 'client_op_id': key};
      }
      final response = await _http
          .post(
            uri,
            headers: headers,
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(Duration(seconds: AppConfig.postTimeout));
      return _handleResponse(response);
    } catch (e) {
      return _handleError(e);
    }
  }

  /// Core PUT request
  Future<ApiResponse> put(String path, {Map<String, dynamic>? body}) async {
    try {
      final uri = Uri.parse('${AppConfig.apiBaseUrl}$path');
      final response = await _http
          .put(
            uri,
            headers: _headers(),
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(Duration(seconds: AppConfig.postTimeout));
      return _handleResponse(response);
    } catch (e) {
      return _handleError(e);
    }
  }

  /// Handle response
  ApiResponse _handleResponse(http.Response response) {
    // Mark as online since we got a response
    _connectivity.markOnline();

    // Handle auth errors — try to refresh token
    if (response.statusCode == 401) {
      _handleAuthExpiry();
    }

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

  /// Handle token expiry
  void _handleAuthExpiry() {
    // Try refresh token first (done async, next request will use new token)
    refreshAccessToken().then((success) {
      if (!success && onAuthExpired != null) {
        onAuthExpired!();
      }
    });
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

  Future<bool> refreshAccessToken() async {
    if (_refreshToken == null) return false;
    final res = await post('/auth/refresh-token', body: {
      'refresh_token': _refreshToken,
    }, auth: false);

    if (res.success && res.data != null) {
      _token = res.data['token'];
      _refreshToken = res.data['refresh_token'];
      await _secureStorage.write(key: AppConfig.tokenKey, value: _token!);
      await _secureStorage.write(key: AppConfig.refreshTokenKey, value: _refreshToken!);
      return true;
    }
    return false;
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
