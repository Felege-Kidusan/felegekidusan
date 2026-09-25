class ProfileImageReference {
  const ProfileImageReference({
    required this.present,
    this.version,
    this.url,
  });

  final bool present;
  final String? version;
  final String? url;

  static final RegExp _opaqueVersion = RegExp(r'^[a-f0-9]{64}$');

  factory ProfileImageReference.fromJson(Object? value) {
    if (value is! Map) {
      throw const FormatException('Profile image metadata is missing.');
    }
    final map = Map<String, dynamic>.from(value);
    final present = map['present'];
    if (present is! bool) {
      throw const FormatException('Profile image presence is invalid.');
    }
    final rawVersion = map['version'];
    final version = rawVersion == null ? null : rawVersion.toString().trim();
    if (present &&
        (version == null || !_opaqueVersion.hasMatch(version))) {
      throw const FormatException('Profile image version is invalid.');
    }
    if (!present && version != null) {
      throw const FormatException('An absent profile image cannot have a version.');
    }
    final rawUrl = map['url'];
    final url = rawUrl == null ? null : rawUrl.toString().trim();
    return ProfileImageReference(
      present: present,
      version: version,
      url: url == null || url.isEmpty ? null : url,
    );
  }

  Map<String, dynamic> toJson() => {
        'present': present,
        'version': version,
        'url': url,
      };
}

class UserProfile {
  const UserProfile({
    required this.id,
    required this.username,
    required this.email,
    required this.fullName,
    required this.role,
    required this.isActive,
    required this.memberId,
    required this.profileImage,
    required this.profileVersion,
    required this.createdAt,
    required this.lastLogin,
    required this.assignments,
  });

  final int id;
  final String username;
  final String? email;
  final String fullName;
  final String role;
  final bool isActive;
  final int? memberId;
  final ProfileImageReference profileImage;
  final String profileVersion;
  final String? createdAt;
  final String? lastLogin;
  final List<Map<String, dynamic>>? assignments;

  static final RegExp _profileVersion = RegExp(r'^[a-f0-9]{64}$');

  factory UserProfile.fromJson(Object? value) {
    if (value is! Map) {
      throw const FormatException('Profile payload is missing.');
    }
    final map = Map<String, dynamic>.from(value);
    final id = _positiveInt(map['id']);
    final username = _requiredText(map['username']);
    final fullName = _requiredText(map['full_name']);
    final role = _requiredText(map['role']);
    final active = map['is_active'];
    final version = _requiredText(map['profile_version']);
    if (id == null ||
        username == null ||
        fullName == null ||
        role == null ||
        active is! bool ||
        version == null ||
        !_profileVersion.hasMatch(version)) {
      throw const FormatException('Profile payload is invalid.');
    }

    final rawEmail = map['email'];
    final email = rawEmail == null ? null : rawEmail.toString();
    final rawMemberId = map['member_id'];
    final memberId = rawMemberId == null ? null : _positiveInt(rawMemberId);
    if (rawMemberId != null && memberId == null) {
      throw const FormatException('Profile member binding is invalid.');
    }
    final rawAssignments = map['assignments'];
    List<Map<String, dynamic>>? assignments;
    if (rawAssignments != null) {
      if (rawAssignments is! List) {
        throw const FormatException('Profile assignments are invalid.');
      }
      assignments = rawAssignments
          .map((item) {
            if (item is! Map) {
              throw const FormatException('Profile assignment is invalid.');
            }
            return Map<String, dynamic>.from(item);
          })
          .toList(growable: false);
    }

    return UserProfile(
      id: id,
      username: username,
      email: email,
      fullName: fullName,
      role: role,
      isActive: active,
      memberId: memberId,
      profileImage: ProfileImageReference.fromJson(map['profile_image']),
      profileVersion: version,
      createdAt: _nullableText(map['created_at']),
      lastLogin: _nullableText(map['last_login']),
      assignments: assignments,
    );
  }

  static UserProfile? tryFromJson(Object? value) {
    try {
      return UserProfile.fromJson(value);
    } on FormatException {
      return null;
    }
  }

  UserProfile copyWith({
    String? username,
    String? email,
    bool clearEmail = false,
    String? fullName,
    ProfileImageReference? profileImage,
    String? profileVersion,
  }) =>
      UserProfile(
        id: id,
        username: username ?? this.username,
        email: clearEmail ? null : (email ?? this.email),
        fullName: fullName ?? this.fullName,
        role: role,
        isActive: isActive,
        memberId: memberId,
        profileImage: profileImage ?? this.profileImage,
        profileVersion: profileVersion ?? this.profileVersion,
        createdAt: createdAt,
        lastLogin: lastLogin,
        assignments: assignments,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'username': username,
        'email': email,
        'full_name': fullName,
        'role': role,
        'is_active': isActive,
        'member_id': memberId,
        'profile_image': profileImage.toJson(),
        'profile_version': profileVersion,
        'created_at': createdAt,
        'last_login': lastLogin,
        'assignments': assignments,
      };

  static int? _positiveInt(Object? value) {
    final parsed = value is int ? value : int.tryParse('${value ?? ''}');
    return parsed != null && parsed > 0 ? parsed : null;
  }

  static String? _requiredText(Object? value) {
    final text = value is String ? value.trim() : '';
    return text.isEmpty ? null : text;
  }

  static String? _nullableText(Object? value) {
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }
}

class ProfileInputPolicy {
  static final RegExp _username =
      RegExp(r'^[a-z0-9][a-z0-9_.]*[a-z0-9]$');
  static final RegExp _consecutivePunctuation = RegExp(r'[._]{2}');
  static final RegExp _simpleEmail =
      RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$');

  static String normalizeUsername(String value) => value.trim().toLowerCase();

  static String? validateUsername(String value) {
    final normalized = normalizeUsername(value);
    if (normalized.length < 3 ||
        normalized.length > 50 ||
        !_username.hasMatch(normalized) ||
        _consecutivePunctuation.hasMatch(normalized)) {
      return 'Use 3–50 lowercase letters, numbers, dots or underscores. '
          'Begin and end with a letter or number and do not repeat punctuation.';
    }
    return null;
  }

  static String? validateFullName(String value) {
    final name = value.trim();
    if (name.isEmpty) return 'Full name is required.';
    if (name.runes.length > 100) {
      return 'Full name must be 100 characters or fewer.';
    }
    if (name.runes.any((rune) => rune < 32 || rune == 127)) {
      return 'Full name contains an unsupported character.';
    }
    return null;
  }

  static String? validateEmail(String value) {
    final email = value.trim().toLowerCase();
    if (email.isEmpty) return null;
    if (email.length > 100 || !_simpleEmail.hasMatch(email)) {
      return 'Enter a valid email address.';
    }
    return null;
  }

  static String? validateNewPassword({
    required String currentPassword,
    required String newPassword,
    required String confirmation,
  }) {
    if (currentPassword.isEmpty ||
        newPassword.isEmpty ||
        confirmation.isEmpty) {
      return 'Fill in all password fields.';
    }
    if (newPassword.runes.length < 12) {
      return 'New password must be at least 12 characters.';
    }
    if (_utf8Length(newPassword) > 72) {
      return 'New password must be at most 72 UTF-8 bytes.';
    }
    if (newPassword == currentPassword) {
      return 'New password must differ from the current password.';
    }
    if (newPassword != confirmation) {
      return 'New passwords do not match.';
    }
    return null;
  }

  static int _utf8Length(String value) {
    var length = 0;
    for (final rune in value.runes) {
      if (rune <= 0x7f) {
        length += 1;
      } else if (rune <= 0x7ff) {
        length += 2;
      } else if (rune <= 0xffff) {
        length += 3;
      } else {
        length += 4;
      }
    }
    return length;
  }
}
