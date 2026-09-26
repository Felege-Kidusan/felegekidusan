import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/models/user_profile.dart';

Map<String, dynamic> profileJson({
  int id = 7,
  String username = 'abebe.user',
  String fullName = 'Abebe Kebede',
  String version =
      'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  bool imagePresent = false,
  String? imageVersion,
}) =>
    {
      'id': id,
      'username': username,
      'email': 'abebe@example.test',
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
      'created_at': '2026-01-01 00:00:00',
      'last_login': null,
      'assignments': <Map<String, dynamic>>[],
    };

void main() {
  group('UserProfile', () {
    test('parses and emits only canonical self-profile fields', () {
      final raw = profileJson()
        ..['password_hash'] = 'forbidden'
        ..['access_token'] = 'forbidden'
        ..['refresh_token'] = 'forbidden'
        ..['authorization_version'] = 9;
      final profile = UserProfile.fromJson(raw);
      final encoded = profile.toJson();

      expect(profile.id, 7);
      expect(profile.email, 'abebe@example.test');
      expect(encoded['profile_version'], raw['profile_version']);
      expect(encoded.containsKey('password_hash'), isFalse);
      expect(encoded.containsKey('access_token'), isFalse);
      expect(encoded.containsKey('refresh_token'), isFalse);
      expect(encoded.containsKey('authorization_version'), isFalse);
    });

    test('rejects incomplete payloads and invalid immutable identity', () {
      expect(() => UserProfile.fromJson(profileJson()..remove('id')),
          throwsFormatException);
      expect(
        () => UserProfile.fromJson(profileJson()..remove('profile_version')),
        throwsFormatException,
      );
      expect(() => UserProfile.fromJson(profileJson(id: 0)),
          throwsFormatException);
      expect(
        () => UserProfile.fromJson(profileJson(version: 'not-opaque')),
        throwsFormatException,
      );
    });

    test('requires an opaque version for a present image', () {
      expect(
        () => UserProfile.fromJson(profileJson(imagePresent: true)),
        throwsFormatException,
      );
      final imageVersion = List.filled(64, 'b').join();
      final profile = UserProfile.fromJson(profileJson(
        imagePresent: true,
        imageVersion: imageVersion,
      ));
      expect(profile.profileImage.version, imageVersion);
    });
  });

  group('ProfileImageInputPolicy', () {
    test('enforces exact minimum dimension boundaries', () {
      expect(ProfileImageInputPolicy.validateDimensions(63, 64), isNotNull);
      expect(ProfileImageInputPolicy.validateDimensions(64, 63), isNotNull);
      expect(ProfileImageInputPolicy.validateDimensions(63, 63), isNotNull);
      expect(ProfileImageInputPolicy.validateDimensions(64, 64), isNull);
      expect(ProfileImageInputPolicy.validateDimensions(128, 96), isNull);
    });

    test('preserves maximum dimension and pixel ceilings', () {
      expect(ProfileImageInputPolicy.validateDimensions(4096, 2929), isNull);
      expect(ProfileImageInputPolicy.validateDimensions(4096, 4096), isNotNull);
      expect(ProfileImageInputPolicy.validateDimensions(4000, 3000), isNull);
      expect(ProfileImageInputPolicy.validateDimensions(4001, 3000), isNotNull);
      expect(ProfileImageInputPolicy.validateDimensions(4097, 64), isNotNull);
    });
  });

  group('ProfileInputPolicy', () {
    test('normalizes and validates username contract', () {
      expect(ProfileInputPolicy.normalizeUsername('  Abebe.User  '),
          'abebe.user');
      expect(ProfileInputPolicy.validateUsername('abebe.user'), isNull);
      for (final invalid in [
        'ab',
        '.abebe',
        'abebe.',
        'abebe..user',
        'abebe-_user',
        'Abebe User',
      ]) {
        expect(ProfileInputPolicy.validateUsername(invalid), isNotNull,
            reason: invalid);
      }
    });

    test('enforces password length, byte bound, difference and confirmation', () {
      expect(
        ProfileInputPolicy.validateNewPassword(
          currentPassword: 'old-password-1',
          newPassword: 'new-password-2',
          confirmation: 'new-password-2',
        ),
        isNull,
      );
      expect(
        ProfileInputPolicy.validateNewPassword(
          currentPassword: 'same-password',
          newPassword: 'same-password',
          confirmation: 'same-password',
        ),
        isNotNull,
      );
      expect(
        ProfileInputPolicy.validateNewPassword(
          currentPassword: 'old-password-1',
          newPassword: 'short',
          confirmation: 'short',
        ),
        isNotNull,
      );
      final tooManyBytes = List.filled(25, 'ሀ').join();
      expect(tooManyBytes.runes.length, 25);
      expect(
        ProfileInputPolicy.validateNewPassword(
          currentPassword: 'old-password-1',
          newPassword: tooManyBytes,
          confirmation: tooManyBytes,
        ),
        isNotNull,
      );
    });
  });
}
