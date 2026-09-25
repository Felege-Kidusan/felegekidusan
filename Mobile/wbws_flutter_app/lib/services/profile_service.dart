import 'dart:async';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/foundation.dart';

import '../models/user_profile.dart';
import 'api_service.dart';
import 'connectivity_service.dart';
import 'outbox_policy.dart';
import 'profile_cache.dart';
import 'session_models.dart';
import 'session_service.dart';

abstract class ProfileGateway {
  int get authenticatedUserId;
  Map<String, dynamic>? get cachedSessionUser;
  Future<void> cacheCanonicalProfile(Map<String, dynamic> profile);
  Future<ApiResponse> fetchProfile();
  Future<ApiResponse> updateProfile(Map<String, dynamic> fields);
  Future<ApiResponse> changePassword({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  });
  Future<ApiResponse> uploadImage({
    required String filePath,
    required String profileVersion,
  });
  Future<ApiResponse> removeImage(String profileVersion);
  Future<ApiResponse> downloadImage();
}

class ApiProfileGateway implements ProfileGateway {
  ApiProfileGateway(this._api);
  final ApiService _api;

  @override
  int get authenticatedUserId => _api.userId;

  @override
  Map<String, dynamic>? get cachedSessionUser => _api.userData;

  @override
  Future<void> cacheCanonicalProfile(Map<String, dynamic> profile) =>
      _api.cacheCanonicalProfile(profile);

  @override
  Future<ApiResponse> fetchProfile() => _api.getOwnProfile();

  @override
  Future<ApiResponse> updateProfile(Map<String, dynamic> fields) =>
      _api.updateOwnProfile(fields);

  @override
  Future<ApiResponse> changePassword({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  }) =>
      _api.changeOwnPassword(
        currentPassword: currentPassword,
        newPassword: newPassword,
        confirmation: confirmation,
      );

  @override
  Future<ApiResponse> uploadImage({
    required String filePath,
    required String profileVersion,
  }) =>
      _api.uploadOwnProfileImage(
        filePath: filePath,
        profileVersion: profileVersion,
      );

  @override
  Future<ApiResponse> removeImage(String profileVersion) =>
      _api.removeOwnProfileImage(profileVersion);

  @override
  Future<ApiResponse> downloadImage() => _api.downloadOwnProfileImage();
}

abstract class ProfileSessionBridge {
  Future<AuthRefreshOutcome> reconcileProfileClaims();
  Future<void> requireReauthenticationAfterPasswordChange();
}

class CoordinatorProfileSessionBridge implements ProfileSessionBridge {
  CoordinatorProfileSessionBridge(this._coordinator);
  final SessionCoordinator _coordinator;

  @override
  Future<AuthRefreshOutcome> reconcileProfileClaims() =>
      _coordinator.reconcileProfileClaims();

  @override
  Future<void> requireReauthenticationAfterPasswordChange() =>
      _coordinator.enterReauthentication(reason: 'password_changed');
}

abstract class ProfileNetworkStatus {
  bool get isOnline;
  Stream<bool> get changes;
  Future<bool> checkNow();
}

class ConnectivityProfileNetwork implements ProfileNetworkStatus {
  ConnectivityProfileNetwork(this._connectivity);
  final ConnectivityService _connectivity;

  @override
  bool get isOnline => _connectivity.hasLink;

  @override
  Stream<bool> get changes => _connectivity.statusStream;

  @override
  Future<bool> checkNow() => _connectivity.checkNow();
}

class ProfileActionResult {
  const ProfileActionResult({
    required this.success,
    this.code,
    this.message,
    this.conflict = false,
    this.retryAfterSeconds,
  });

  final bool success;
  final String? code;
  final String? message;
  final bool conflict;
  final int? retryAfterSeconds;

  const ProfileActionResult.ok([String? message])
      : this(success: true, message: message);
}

class MobileProfileService extends ChangeNotifier {
  MobileProfileService._real()
      : _gateway = ApiProfileGateway(ApiService()),
        _session = CoordinatorProfileSessionBridge(SessionCoordinator()),
        _network = ConnectivityProfileNetwork(ConnectivityService()),
        _images = ProfileImageCache.instance {
    SessionCoordinator().onProfileSessionCleared = _resetMemory;
    _listenForConnectivity();
  }

  @visibleForTesting
  MobileProfileService.testing({
    required ProfileGateway gateway,
    required ProfileSessionBridge session,
    required ProfileNetworkStatus network,
    required ProfileImageStore images,
  })  : _gateway = gateway,
        _session = session,
        _network = network,
        _images = images {
    _listenForConnectivity();
  }

  static final MobileProfileService instance = MobileProfileService._real();

  final ProfileGateway _gateway;
  final ProfileSessionBridge _session;
  final ProfileNetworkStatus _network;
  final ProfileImageStore _images;

  StreamSubscription<bool>? _networkSubscription;
  UserProfile? _profile;
  Uint8List? _imageBytes;
  int _ownerUserId = 0;
  int _epoch = 0;
  bool _loading = false;
  bool _mutating = false;
  bool _uploadingImage = false;
  String? _errorMessage;
  Future<bool>? _refreshInFlight;

  UserProfile? get profile => _profile;
  Uint8List? get imageBytes => _imageBytes;
  bool get loading => _loading;
  bool get mutating => _mutating;
  bool get uploadingImage => _uploadingImage;
  bool get isOnline => _network.isOnline;
  String? get errorMessage => _errorMessage;

  void _listenForConnectivity() {
    _networkSubscription = _network.changes.listen((online) {
      notifyListeners();
      if (online && _ownerUserId > 0) {
        unawaited(refresh());
      }
    });
  }

  /// Synchronously exposes a complete owner-bound protected profile, then
  /// asynchronously checks connectivity and refreshes it from the server.
  Future<void> open() async {
    final owner = _gateway.authenticatedUserId;
    if (owner <= 0) {
      _resetMemory();
      return;
    }
    // Crash leftovers are private, owner-scoped temporary files. Reaping is
    // best-effort and never delays cache-first profile rendering.
    unawaited(_reapStaleUploads());
    if (_ownerUserId != owner) {
      _ownerUserId = owner;
      _profile = null;
      _imageBytes = null;
      _errorMessage = null;
      _loading = false;
      _mutating = false;
      _uploadingImage = false;
      // Detach the new owner from any unresolved request owned by the prior
      // session. The old future remains fenced by epoch/owner checks.
      _refreshInFlight = null;
      _epoch += 1;
    }

    final cached = UserProfile.tryFromJson(_gateway.cachedSessionUser);
    if (cached != null && cached.id == owner) {
      _profile = cached;
      notifyListeners();
      unawaited(_loadCanonicalImage(cached, _epoch));
    } else {
      notifyListeners();
    }

    await _network.checkNow();
    if (_network.isOnline) {
      await refresh();
    } else if (_profile == null) {
      _errorMessage = 'No cached profile is available while offline.';
      notifyListeners();
    }
  }

  Future<bool> refresh({bool allowDuringMutation = false}) {
    if (_mutating && !allowDuringMutation) return Future<bool>.value(false);
    final existing = _refreshInFlight;
    if (existing != null) return existing;
    final attempt = _refreshOnce();
    _refreshInFlight = attempt;
    attempt.whenComplete(() {
      if (identical(_refreshInFlight, attempt)) _refreshInFlight = null;
    });
    return attempt;
  }

  Future<bool> _refreshOnce() async {
    if (_ownerUserId <= 0 || _ownerUserId != _gateway.authenticatedUserId) {
      return false;
    }
    if (!_network.isOnline) {
      _errorMessage = _profile == null
          ? 'No cached profile is available while offline.'
          : 'Offline. Showing the profile saved on this device.';
      notifyListeners();
      return false;
    }

    final epoch = _epoch;
    _loading = true;
    _errorMessage = null;
    notifyListeners();
    try {
      final response = await _withClaimsReconciliation(
        _gateway.fetchProfile,
      );
      if (epoch != _epoch || _ownerUserId != _gateway.authenticatedUserId) {
        return false;
      }
      if (!response.success) {
        _errorMessage = messageFor(response);
        return false;
      }
      final canonical = UserProfile.fromJson(response.data);
      if (canonical.id != _ownerUserId) {
        _errorMessage = 'The server returned a profile for another account.';
        return false;
      }
      await _acceptCanonical(canonical, epoch);
      return true;
    } on FormatException {
      _errorMessage = 'The server returned an invalid profile.';
      return false;
    } catch (_) {
      _errorMessage = 'The profile could not be refreshed. Cached data is unchanged.';
      return false;
    } finally {
      if (epoch == _epoch) {
        _loading = false;
        notifyListeners();
      }
    }
  }

  Future<ProfileActionResult> updateFullName(String value) async {
    final error = ProfileInputPolicy.validateFullName(value);
    if (error != null) {
      return ProfileActionResult(success: false, message: error);
    }
    return _updateProfile({'full_name': value.trim()});
  }

  Future<ProfileActionResult> updateEmail(
    String value,
    String currentPassword,
  ) async {
    final error = ProfileInputPolicy.validateEmail(value);
    if (error != null) {
      return ProfileActionResult(success: false, message: error);
    }
    if (currentPassword.isEmpty) {
      return const ProfileActionResult(
        success: false,
        message: 'Current password is required to change email.',
      );
    }
    final normalized = value.trim().toLowerCase();
    return _updateProfile({
      'email': normalized.isEmpty ? null : normalized,
      'current_password': currentPassword,
    });
  }

  Future<ProfileActionResult> updateUsername(
    String value,
    String currentPassword,
  ) async {
    final error = ProfileInputPolicy.validateUsername(value);
    if (error != null) {
      return ProfileActionResult(success: false, message: error);
    }
    if (currentPassword.isEmpty) {
      return const ProfileActionResult(
        success: false,
        message: 'Current password is required to change username.',
      );
    }
    return _updateProfile({
      'username': ProfileInputPolicy.normalizeUsername(value),
      'current_password': currentPassword,
    });
  }

  Future<ProfileActionResult> _updateProfile(
    Map<String, dynamic> changes,
  ) async {
    final current = _profile;
    final unavailable = _mutationUnavailable(current);
    if (unavailable != null) return unavailable;
    final canonicalBefore = current!;
    final operationEpoch = _epoch;
    final operationOwner = canonicalBefore.id;
    _mutating = true;
    _errorMessage = null;
    notifyListeners();
    try {
      final response = await _withClaimsReconciliation(() {
        return _gateway.updateProfile({
          ...changes,
          'profile_version': canonicalBefore.profileVersion,
        });
      });
      if (!_operationIsCurrent(operationEpoch, operationOwner)) {
        return _sessionChangedResult;
      }
      if (!response.success) {
        if (response.errorCode == 'PROFILE_CONFLICT') {
          await refresh(allowDuringMutation: true);
          return ProfileActionResult(
            success: false,
            code: 'PROFILE_CONFLICT',
            message: 'The server profile was reloaded. Review your change and save again.',
            conflict: true,
          );
        }
        return _failure(response);
      }

      final canonical = UserProfile.fromJson(response.data);
      if (canonical.id != _ownerUserId) {
        return const ProfileActionResult(
          success: false,
          message: 'The server returned a profile for another account.',
        );
      }
      await _acceptCanonical(canonical, operationEpoch);
      if (!_operationIsCurrent(operationEpoch, operationOwner)) {
        return _sessionChangedResult;
      }

      final data = response.data;
      final claimsRefreshRequired =
          data is Map && data['claims_refresh_required'] == true;
      if (claimsRefreshRequired) {
        final outcome = await _session.reconcileProfileClaims();
        if (outcome != AuthRefreshOutcome.sameScope) {
          return const ProfileActionResult(
            success: false,
            code: 'PROFILE_CLAIMS_CHANGED',
            message: 'The profile was saved, but session details still need to be refreshed.',
          );
        }
        if (!_operationIsCurrent(operationEpoch, operationOwner)) {
          return _sessionChangedResult;
        }
        // Token rotation rewrites the protected user bundle. Re-merge the
        // canonical profile so image/version/email cache fields remain intact.
        await _gateway.cacheCanonicalProfile(canonical.toJson());
      }
      return const ProfileActionResult.ok('Profile updated.');
    } on FormatException {
      return const ProfileActionResult(
        success: false,
        message: 'The server returned an invalid profile.',
      );
    } catch (_) {
      return const ProfileActionResult(
        success: false,
        message: 'The profile changed on the server but could not be saved locally. Refresh to reconcile it.',
      );
    } finally {
      if (_operationIsCurrent(operationEpoch, operationOwner)) {
        _mutating = false;
        notifyListeners();
      }
    }
  }

  Future<ProfileActionResult> changePassword({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  }) async {
    final validation = ProfileInputPolicy.validateNewPassword(
      currentPassword: currentPassword,
      newPassword: newPassword,
      confirmation: confirmation,
    );
    if (validation != null) {
      return ProfileActionResult(success: false, message: validation);
    }
    final current = _profile;
    final unavailable = _mutationUnavailable(current);
    if (unavailable != null) return unavailable;
    final operationEpoch = _epoch;
    final operationOwner = current!.id;

    _mutating = true;
    notifyListeners();
    try {
      final response = await _withClaimsReconciliation(() {
        return _gateway.changePassword(
          currentPassword: currentPassword,
          newPassword: newPassword,
          confirmation: confirmation,
        );
      });
      if (!_operationIsCurrent(operationEpoch, operationOwner)) {
        return _sessionChangedResult;
      }
      if (!response.success) return _failure(response);

      await _session.requireReauthenticationAfterPasswordChange();
      if (_operationIsCurrent(operationEpoch, operationOwner)) {
        _resetMemory();
      }
      return const ProfileActionResult.ok(
        'Password changed. Sign in again with the new password.',
      );
    } catch (_) {
      return const ProfileActionResult(
        success: false,
        message: 'Password changed, but local sign-out could not finish safely.',
      );
    } finally {
      if (_operationIsCurrent(operationEpoch, operationOwner)) {
        _mutating = false;
        notifyListeners();
      }
    }
  }

  Future<ProfileActionResult> uploadImage(Uint8List selectedBytes) async {
    final current = _profile;
    final unavailable = _mutationUnavailable(current);
    if (unavailable != null) return unavailable;
    final canonical = current!;
    final operationEpoch = _epoch;
    final operationOwner = canonical.id;
    if (selectedBytes.isEmpty || selectedBytes.length > 4 * 1024 * 1024) {
      return const ProfileActionResult(
        success: false,
        code: 'IMAGE_TOO_LARGE',
        message: 'Choose an image no larger than 4 MB.',
      );
    }

    File? staged;
    _mutating = true;
    _uploadingImage = true;
    notifyListeners();
    try {
      staged = await _images.stageUpload(
        ownerUserId: operationOwner,
        bytes: selectedBytes,
      );
      if (!_operationIsCurrent(operationEpoch, operationOwner)) {
        return _sessionChangedResult;
      }
      final response = await _withClaimsReconciliation(() {
        return _gateway.uploadImage(
          filePath: staged!.path,
          profileVersion: canonical.profileVersion,
        );
      });
      if (!_operationIsCurrent(operationEpoch, operationOwner)) {
        return _sessionChangedResult;
      }
      if (!response.success) {
        if (response.errorCode == 'PROFILE_CONFLICT') {
          await refresh(allowDuringMutation: true);
          return const ProfileActionResult(
            success: false,
            code: 'PROFILE_CONFLICT',
            message: 'The server profile was reloaded. Choose the image again to retry.',
            conflict: true,
          );
        }
        return _failure(response);
      }

      final data = response.data;
      if (data is! Map) throw const FormatException('Invalid image response.');
      final image = ProfileImageReference.fromJson(data['profile_image']);
      final version = data['profile_version']?.toString() ?? '';
      final updated = UserProfile.fromJson({
        ...canonical.toJson(),
        'profile_image': image.toJson(),
        'profile_version': version,
      });
      // Persist metadata first. The selected preview becomes visible only after
      // server confirmation; the previous avatar remains active on failure.
      await _gateway.cacheCanonicalProfile(updated.toJson());
      if (!_operationIsCurrent(operationEpoch, operationOwner)) {
        return _sessionChangedResult;
      }
      _profile = updated;
      _imageBytes = selectedBytes;
      notifyListeners();
      await _loadCanonicalImage(
        updated,
        operationEpoch,
        forceDownload: true,
      );
      return const ProfileActionResult.ok('Profile image updated.');
    } on FormatException catch (error) {
      return ProfileActionResult(success: false, message: error.message);
    } catch (_) {
      return const ProfileActionResult(
        success: false,
        message: 'The image could not be uploaded. The previous image is unchanged.',
      );
    } finally {
      if (staged != null) await _images.discardStagedUpload(staged);
      if (_operationIsCurrent(operationEpoch, operationOwner)) {
        _uploadingImage = false;
        _mutating = false;
        notifyListeners();
      }
    }
  }

  Future<ProfileActionResult> removeImage() async {
    final current = _profile;
    final unavailable = _mutationUnavailable(current);
    if (unavailable != null) return unavailable;
    final canonical = current!;
    final operationEpoch = _epoch;
    final operationOwner = canonical.id;
    if (!canonical.profileImage.present) {
      return const ProfileActionResult.ok('Profile image is already removed.');
    }

    _mutating = true;
    notifyListeners();
    try {
      final response = await _withClaimsReconciliation(
        () => _gateway.removeImage(canonical.profileVersion),
      );
      if (!_operationIsCurrent(operationEpoch, operationOwner)) {
        return _sessionChangedResult;
      }
      if (!response.success) {
        if (response.errorCode == 'PROFILE_CONFLICT') {
          await refresh(allowDuringMutation: true);
          return const ProfileActionResult(
            success: false,
            code: 'PROFILE_CONFLICT',
            message: 'The server profile was reloaded. Review it before removing the image.',
            conflict: true,
          );
        }
        return _failure(response);
      }
      final data = response.data;
      if (data is! Map) throw const FormatException('Invalid image response.');
      final image = ProfileImageReference.fromJson(data['profile_image']);
      final version = data['profile_version']?.toString() ?? '';
      final updated = UserProfile.fromJson({
        ...canonical.toJson(),
        'profile_image': image.toJson(),
        'profile_version': version,
      });
      await _gateway.cacheCanonicalProfile(updated.toJson());
      if (!_operationIsCurrent(operationEpoch, operationOwner)) {
        return _sessionChangedResult;
      }
      await _images.clearOwner(operationOwner);
      if (!_operationIsCurrent(operationEpoch, operationOwner)) {
        return _sessionChangedResult;
      }
      _profile = updated;
      _imageBytes = null;
      notifyListeners();
      return const ProfileActionResult.ok('Profile image removed.');
    } on FormatException {
      return const ProfileActionResult(
        success: false,
        message: 'The server returned invalid image metadata.',
      );
    } catch (_) {
      return const ProfileActionResult(
        success: false,
        message: 'The image could not be removed. The previous image is unchanged.',
      );
    } finally {
      if (_operationIsCurrent(operationEpoch, operationOwner)) {
        _mutating = false;
        notifyListeners();
      }
    }
  }

  static const ProfileActionResult _sessionChangedResult = ProfileActionResult(
    success: false,
    code: 'SESSION_CHANGED',
    message: 'The signed-in session changed before this request finished.',
  );

  bool _operationIsCurrent(int epoch, int ownerUserId) =>
      epoch == _epoch &&
      ownerUserId == _ownerUserId &&
      ownerUserId == _gateway.authenticatedUserId;

  ProfileActionResult? _mutationUnavailable(UserProfile? current) {
    if (current == null || current.id != _ownerUserId) {
      return const ProfileActionResult(
        success: false,
        message: 'Refresh the profile before making changes.',
      );
    }
    if (!_network.isOnline) {
      return const ProfileActionResult(
        success: false,
        code: 'OFFLINE',
        message: 'Profile changes require an internet connection.',
      );
    }
    if (_loading) {
      return const ProfileActionResult(
        success: false,
        message: 'Wait for the current profile refresh to finish.',
      );
    }
    if (_mutating) {
      return const ProfileActionResult(
        success: false,
        message: 'Another profile change is still finishing.',
      );
    }
    return null;
  }

  Future<ApiResponse> _withClaimsReconciliation(
    Future<ApiResponse> Function() operation,
  ) async {
    var response = await operation();
    if (response.errorCode != 'PROFILE_CLAIMS_CHANGED') return response;
    final outcome = await _session.reconcileProfileClaims();
    if (outcome == AuthRefreshOutcome.sameScope) {
      response = await operation();
    }
    return response;
  }

  Future<void> _acceptCanonical(UserProfile canonical, int epoch) async {
    if (canonical.id != _ownerUserId || epoch != _epoch) return;
    await _gateway.cacheCanonicalProfile(canonical.toJson());
    if (canonical.id != _ownerUserId || epoch != _epoch) return;
    final oldImage = _profile?.profileImage;
    _profile = canonical;
    if (!canonical.profileImage.present) {
      _imageBytes = null;
      await _images.clearOwner(canonical.id);
    }
    notifyListeners();
    final imageChanged = oldImage?.version != canonical.profileImage.version;
    await _loadCanonicalImage(
      canonical,
      epoch,
      forceDownload: imageChanged,
    );
  }

  Future<void> _loadCanonicalImage(
    UserProfile canonical,
    int epoch, {
    bool forceDownload = false,
  }) async {
    final image = canonical.profileImage;
    final version = image.version;
    if (!image.present || version == null) return;
    final cached = await _images.read(
      ownerUserId: canonical.id,
      version: version,
    );
    if (cached != null) {
      if (_isCurrentImage(canonical.id, version, epoch)) {
        _imageBytes = cached;
        notifyListeners();
      }
      return;
    }
    if (!_network.isOnline) return;
    if (!forceDownload && _imageBytes != null) return;

    final response = await _withClaimsReconciliation(_gateway.downloadImage);
    if (!response.success || response.data is! Uint8List) return;
    final etag = _normalizeEtag(response.etag);
    if (etag != version ||
        !_isCurrentImage(canonical.id, version, epoch)) {
      return;
    }
    final bytes = response.data as Uint8List;
    await _images.write(
      ownerUserId: canonical.id,
      version: version,
      jpegBytes: bytes,
    );
    if (!_isCurrentImage(canonical.id, version, epoch)) {
      // Logout/different-owner transition raced the atomic file write. Remove
      // that old owner's just-completed artifact before another account can
      // render. A same-owner reauthentication may safely retain the file.
      if (_ownerUserId != canonical.id) {
        await _images.clearOwner(canonical.id);
      }
      return;
    }
    _imageBytes = bytes;
    notifyListeners();
  }

  bool _isCurrentImage(int owner, String version, int epoch) =>
      epoch == _epoch &&
      owner == _ownerUserId &&
      _profile?.id == owner &&
      _profile?.profileImage.version == version;

  static String? _normalizeEtag(String? value) {
    if (value == null) return null;
    var tag = value.trim();
    if (tag.startsWith('W/')) tag = tag.substring(2).trim();
    if (tag.length >= 2 && tag.startsWith('"') && tag.endsWith('"')) {
      tag = tag.substring(1, tag.length - 1);
    }
    return tag;
  }

  static ProfileActionResult _failure(ApiResponse response) =>
      ProfileActionResult(
        success: false,
        code: response.errorCode,
        message: messageFor(response),
        retryAfterSeconds: response.retryAfterSeconds,
      );

  static String messageFor(ApiResponse response) {
    switch (response.errorCode) {
      case 'USERNAME_TAKEN':
        return 'That username is already in use.';
      case 'EMAIL_TAKEN':
      case 'PROFILE_DUPLICATE':
        return 'That account value is already in use.';
      case 'CURRENT_PASSWORD_INCORRECT':
        return 'Current password is incorrect.';
      case 'PROFILE_CONFLICT':
        return 'The profile changed on the server. Reload and review it.';
      case 'PROFILE_VERSION_REQUIRED':
        return 'Reload the latest profile before making this change.';
      case 'PROFILE_CLAIMS_CHANGED':
        return 'Session profile details changed and must be refreshed.';
      case 'IMAGE_TOO_LARGE':
        return 'Choose an image no larger than 4 MB.';
      case 'UNSUPPORTED_IMAGE':
        return 'Choose a JPEG, PNG, or supported WebP image.';
      case 'INVALID_IMAGE':
        return 'The selected file is not a valid profile image.';
      case 'RATE_LIMITED':
        final retry = response.retryAfterSeconds;
        return retry == null
            ? 'Too many attempts. Please wait and try again.'
            : 'Too many attempts. Try again in $retry seconds.';
      case 'ACCOUNT_DISABLED':
      case 'ACCOUNT_REMOVED':
      case 'AUTH_SCOPE_CHANGED':
      case 'AUTH_SCOPE_REFRESH_REQUIRED':
        return 'Your session must be refreshed before continuing.';
    }
    if (response.statusCode == 401) return 'Please sign in again.';
    if (response.statusCode == 403) {
      return 'This account is not allowed to perform that action.';
    }
    if (response.statusCode == 413) return 'Choose an image no larger than 4 MB.';
    if (response.statusCode == 415) return 'The selected image type is not supported.';
    if (response.statusCode == 422) {
      return response.message ?? 'Review the entered profile information.';
    }
    if (response.statusCode == 429) {
      return 'Too many attempts. Please wait and try again.';
    }
    if (response.statusCode >= 500) {
      return 'The profile service is temporarily unavailable.';
    }
    if (response.isNetworkError) {
      return 'Offline. Profile changes require an internet connection.';
    }
    if (response.failureKind == ApiFailureKind.timeout) {
      return 'The request timed out. Your cached profile is unchanged.';
    }
    return response.message ?? 'The profile request could not be completed.';
  }

  Future<void> _reapStaleUploads() async {
    try {
      await _images.reapStaleUploads();
    } catch (_) {
      // Cleanup failure must not make a cached profile unavailable.
    }
  }

  void _resetMemory() {
    _ownerUserId = 0;
    _profile = null;
    _imageBytes = null;
    _errorMessage = null;
    _loading = false;
    _mutating = false;
    _uploadingImage = false;
    _refreshInFlight = null;
    _epoch += 1;
    notifyListeners();
  }

  @override
  void dispose() {
    _networkSubscription?.cancel();
    super.dispose();
  }
}
