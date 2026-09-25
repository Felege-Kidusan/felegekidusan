import 'dart:async';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/profile_cache.dart';
import 'package:fkss_app/services/profile_service.dart';
import 'package:fkss_app/services/session_models.dart';

const profileVersionA =
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const profileVersionB =
    'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

Map<String, dynamic> canonicalProfile({
  int id = 7,
  String username = 'abebe.user',
  String fullName = 'Abebe Kebede',
  String? email = 'abebe@example.test',
  String version = profileVersionA,
  bool imagePresent = false,
  String? imageVersion,
}) =>
    {
      'id': id,
      'username': username,
      'email': email,
      'full_name': fullName,
      'role': 'teacher',
      'is_active': true,
      'member_id': null,
      'profile_image': {
        'present': imagePresent,
        'version': imageVersion,
        'url': imagePresent ? '/api/v1/users/me/profile-image' : null,
      },
      'profile_version': version,
      'created_at': '2026-01-01',
      'last_login': null,
      'assignments': <Map<String, dynamic>>[],
    };

ApiResponse ok(Object? data) => ApiResponse(success: true, data: data);
ApiResponse failure(String code, {int status = 409, String? message}) =>
    ApiResponse(
      success: false,
      statusCode: status,
      errorCode: code,
      message: message,
    );

class FakeGateway implements ProfileGateway {
  int owner = 7;
  Map<String, dynamic>? cached;
  final List<ApiResponse> fetchResponses = [];
  final List<ApiResponse> updateResponses = [];
  final List<ApiResponse> passwordResponses = [];
  final List<ApiResponse> uploadResponses = [];
  final List<ApiResponse> removalResponses = [];
  final List<ApiResponse> downloadResponses = [];
  final List<Map<String, dynamic>> updates = [];
  final List<Map<String, dynamic>> cachedWrites = [];
  Completer<ApiResponse>? pendingFetch;
  int fetchCount = 0;
  int passwordCount = 0;
  String? uploadedPath;
  String? uploadedVersion;

  @override
  int get authenticatedUserId => owner;

  @override
  Map<String, dynamic>? get cachedSessionUser => cached;

  @override
  Future<void> cacheCanonicalProfile(Map<String, dynamic> profile) async {
    cached = Map<String, dynamic>.from(profile);
    cachedWrites.add(Map<String, dynamic>.from(profile));
  }

  @override
  Future<ApiResponse> fetchProfile() async {
    fetchCount += 1;
    final pending = pendingFetch;
    if (pending != null) {
      pendingFetch = null;
      return pending.future;
    }
    return fetchResponses.removeAt(0);
  }

  @override
  Future<ApiResponse> updateProfile(Map<String, dynamic> fields) async {
    updates.add(Map<String, dynamic>.from(fields));
    return updateResponses.removeAt(0);
  }

  @override
  Future<ApiResponse> changePassword({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  }) async {
    passwordCount += 1;
    return passwordResponses.removeAt(0);
  }

  @override
  Future<ApiResponse> uploadImage({
    required String filePath,
    required String profileVersion,
  }) async {
    uploadedPath = filePath;
    uploadedVersion = profileVersion;
    return uploadResponses.removeAt(0);
  }

  @override
  Future<ApiResponse> removeImage(String profileVersion) async =>
      removalResponses.removeAt(0);

  @override
  Future<ApiResponse> downloadImage() async => downloadResponses.removeAt(0);
}

class FakeSession implements ProfileSessionBridge {
  AuthRefreshOutcome claimOutcome = AuthRefreshOutcome.sameScope;
  int reconciliations = 0;
  int reauthentications = 0;

  @override
  Future<AuthRefreshOutcome> reconcileProfileClaims() async {
    reconciliations += 1;
    return claimOutcome;
  }

  @override
  Future<void> requireReauthenticationAfterPasswordChange() async {
    reauthentications += 1;
  }
}

class FakeNetwork implements ProfileNetworkStatus {
  FakeNetwork(this.online);
  bool online;
  final controller = StreamController<bool>.broadcast();

  @override
  bool get isOnline => online;

  @override
  Stream<bool> get changes => controller.stream;

  @override
  Future<bool> checkNow() async => online;

  void setOnline(bool value) {
    online = value;
    controller.add(value);
  }

  Future<void> close() => controller.close();
}

class MemoryImageStore implements ProfileImageStore {
  final Map<String, Uint8List> files = {};
  final List<int> clearedOwners = [];

  String key(int owner, String version) => '$owner:$version';

  @override
  Future<Uint8List?> read({required int ownerUserId, required String version}) async =>
      files[key(ownerUserId, version)];

  @override
  Future<void> write({
    required int ownerUserId,
    required String version,
    required Uint8List jpegBytes,
  }) async {
    files.removeWhere((key, _) => key.startsWith('$ownerUserId:'));
    files[key(ownerUserId, version)] = jpegBytes;
  }

  @override
  Future<File> stageUpload({
    required int ownerUserId,
    required Uint8List bytes,
  }) async {
    final directory = await Directory.systemTemp.createTemp('profile-upload-');
    final file = File('${directory.path}/.upload.tmp');
    await file.writeAsBytes(bytes);
    return file;
  }

  @override
  Future<void> discardStagedUpload(File file) async {
    final directory = file.parent;
    if (await directory.exists()) await directory.delete(recursive: true);
  }

  @override
  Future<void> clearOwner(int ownerUserId) async {
    clearedOwners.add(ownerUserId);
    files.removeWhere((key, _) => key.startsWith('$ownerUserId:'));
  }

  @override
  Future<void> clearAll() async => files.clear();
}

void main() {
  late FakeGateway gateway;
  late FakeSession session;
  late FakeNetwork network;
  late MemoryImageStore images;
  late MobileProfileService service;

  void createService({bool online = false}) {
    gateway = FakeGateway();
    session = FakeSession();
    network = FakeNetwork(online);
    images = MemoryImageStore();
    service = MobileProfileService.testing(
      gateway: gateway,
      session: session,
      network: network,
      images: images,
    );
  }

  tearDown(() async {
    service.dispose();
    await network.close();
  });

  test('cached profile renders immediately and offline without a request', () async {
    createService();
    gateway.cached = canonicalProfile();

    final opening = service.open();
    expect(service.profile?.fullName, 'Abebe Kebede');
    await opening;

    expect(gateway.fetchCount, 0);
    expect(service.profile?.id, 7);
    expect(service.errorMessage, isNull);
  });

  test('empty offline state is honest when no canonical cache exists', () async {
    createService();
    await service.open();

    expect(service.profile, isNull);
    expect(service.errorMessage, contains('No cached profile'));
  });

  test('owner transition drops prior profile memory before rendering', () async {
    createService();
    gateway.cached = canonicalProfile();
    await service.open();
    expect(service.profile?.id, 7);

    gateway.owner = 8;
    gateway.cached = canonicalProfile(id: 8, username: 'second.user');
    final opening = service.open();
    expect(service.profile?.id, 8);
    expect(service.profile?.username, 'second.user');
    await opening;
  });

  test('new owner never joins the previous owner refresh future', () async {
    createService(online: true);
    gateway.cached = canonicalProfile();
    final oldRefresh = Completer<ApiResponse>();
    gateway.pendingFetch = oldRefresh;
    final oldOpening = service.open();
    await Future<void>.delayed(Duration.zero);

    gateway.owner = 8;
    gateway.cached = canonicalProfile(id: 8, username: 'second.user');
    gateway.fetchResponses.add(ok(canonicalProfile(
      id: 8,
      username: 'second.user',
      fullName: 'Second User',
    )));
    await service.open();

    expect(gateway.fetchCount, 2);
    expect(service.profile?.id, 8);
    expect(service.profile?.fullName, 'Second User');

    oldRefresh.complete(ok(canonicalProfile(fullName: 'Old Late Result')));
    await oldOpening;
    expect(service.profile?.id, 8);
    expect(service.profile?.fullName, 'Second User');
  });

  test('online refresh replaces cache and refresh failure preserves it', () async {
    createService(online: true);
    gateway.cached = canonicalProfile(fullName: 'Cached Name');
    gateway.fetchResponses.add(ok(canonicalProfile(fullName: 'Server Name')));
    await service.open();
    expect(service.profile?.fullName, 'Server Name');

    gateway.fetchResponses.add(failure('PROFILE_SERVICE_UNAVAILABLE', status: 503));
    expect(await service.refresh(), isFalse);
    expect(service.profile?.fullName, 'Server Name');
    expect(service.errorMessage, contains('temporarily unavailable'));
  });

  test('profile updates send no target id and include current password only when required',
      () async {
    createService();
    gateway.cached = canonicalProfile();
    await service.open();
    network.setOnline(true);

    gateway.updateResponses.add(ok(canonicalProfile(fullName: 'New Name', version: profileVersionB)));
    expect((await service.updateFullName('New Name')).success, isTrue);
    expect(gateway.updates.single, {
      'full_name': 'New Name',
      'profile_version': profileVersionA,
    });
    expect(gateway.updates.single.containsKey('id'), isFalse);
    expect(gateway.updates.single.containsKey('user_id'), isFalse);

    gateway.updateResponses.add(ok(canonicalProfile(
      fullName: 'New Name',
      email: 'new@example.test',
      version: profileVersionA,
    )));
    await service.updateEmail('NEW@example.test', 'current-secret');
    expect(gateway.updates.last['email'], 'new@example.test');
    expect(gateway.updates.last['current_password'], 'current-secret');
  });

  test('profile conflict reloads canonical state without overwriting caller draft',
      () async {
    createService();
    gateway.cached = canonicalProfile();
    await service.open();
    network.setOnline(true);
    gateway.updateResponses.add(failure('PROFILE_CONFLICT'));
    gateway.fetchResponses.add(ok(canonicalProfile(fullName: 'Concurrent Name')));

    final result = await service.updateFullName('My Unsaved Draft');

    expect(result.conflict, isTrue);
    expect(service.profile?.fullName, 'Concurrent Name');
    expect(gateway.fetchCount, 1);
  });

  test('stale profile claims reconcile through session bridge and retry once', () async {
    createService();
    gateway.cached = canonicalProfile();
    await service.open();
    network.setOnline(true);
    gateway.updateResponses
      ..add(failure('PROFILE_CLAIMS_CHANGED'))
      ..add(ok(canonicalProfile(username: 'new.user', version: profileVersionB)
        ..['claims_refresh_required'] = true));

    final result = await service.updateUsername('new.user', 'current-secret');

    expect(result.success, isTrue);
    expect(session.reconciliations, 2);
    expect(gateway.updates.length, 2);
    expect(gateway.updates.last.containsKey('owner_id'), isFalse);
  });

  test('password validation is online-only and success requires reauthentication',
      () async {
    createService();
    gateway.cached = canonicalProfile();
    await service.open();

    var result = await service.changePassword(
      currentPassword: 'old-password-1',
      newPassword: 'new-password-2',
      confirmation: 'new-password-2',
    );
    expect(result.code, 'OFFLINE');
    expect(gateway.passwordCount, 0);

    network.setOnline(true);
    gateway.passwordResponses.add(ok(<String, dynamic>{}));
    result = await service.changePassword(
      currentPassword: 'old-password-1',
      newPassword: 'new-password-2',
      confirmation: 'new-password-2',
    );
    expect(result.success, isTrue);
    expect(session.reauthentications, 1);
    expect(service.profile, isNull);
  });

  test('failed image upload and removal preserve the old avatar', () async {
    createService();
    final oldImage = Uint8List.fromList([0xff, 0xd8, 0xff, 1, 0xff, 0xd9]);
    gateway.cached = canonicalProfile(
      imagePresent: true,
      imageVersion: profileVersionA,
    );
    images.files[images.key(7, profileVersionA)] = oldImage;
    await service.open();
    await Future<void>.delayed(Duration.zero);
    network.setOnline(true);

    gateway.uploadResponses.add(failure('INVALID_IMAGE', status: 422));
    var result = await service.uploadImage(
      Uint8List.fromList([0xff, 0xd8, 0xff, 2, 0xff, 0xd9]),
    );
    expect(result.success, isFalse);
    expect(service.imageBytes, orderedEquals(oldImage));

    gateway.removalResponses.add(failure('STORAGE_UNAVAILABLE', status: 503));
    result = await service.removeImage();
    expect(result.success, isFalse);
    expect(service.imageBytes, orderedEquals(oldImage));
    expect(service.profile?.profileImage.present, isTrue);
  });

  test('successful image replacement uses server version and canonical bytes', () async {
    createService();
    final selected = Uint8List.fromList([0xff, 0xd8, 0xff, 2, 0xff, 0xd9]);
    final serverJpeg = Uint8List.fromList([0xff, 0xd8, 0xff, 3, 0xff, 0xd9]);
    gateway.cached = canonicalProfile();
    await service.open();
    network.setOnline(true);
    gateway.uploadResponses.add(ok({
      'profile_image': {
        'present': true,
        'version': profileVersionB,
        'url': '/api/v1/users/me/profile-image',
      },
      'profile_version': profileVersionB,
      'cleanup_pending': false,
    }));
    gateway.downloadResponses.add(ApiResponse(
      success: true,
      data: serverJpeg,
      etag: '"$profileVersionB"',
    ));

    final result = await service.uploadImage(selected);

    expect(result.success, isTrue);
    expect(gateway.uploadedVersion, profileVersionA);
    expect(service.profile?.profileImage.version, profileVersionB);
    expect(service.imageBytes, orderedEquals(serverJpeg));
    expect(
      images.files[images.key(7, profileVersionB)],
      orderedEquals(serverJpeg),
    );
  });
}
