/// Pure session contracts shared by bootstrap, API, persistence, and UI code.
/// This file deliberately imports no Flutter package.
enum CredentialLoadState {
  complete,
  absent,
  partial,
  readFailure,
}

enum SessionState {
  anonymousClean('anonymous_clean'),
  active('active'),
  reauthRequired('reauth_required'),
  orphanedLocalData('orphaned_local_data'),
  purging('purging');

  final String storageValue;
  const SessionState(this.storageValue);

  static SessionState fromStorage(String value) {
    return SessionState.values.firstWhere(
      (state) => state.storageValue == value,
      orElse: () => SessionState.orphanedLocalData,
    );
  }
}

enum AuthRefreshOutcome {
  sameScope,
  scopeChanged,
  rejected,
  transientFailure,
  superseded,
}

/// A complete, server-proven credential/profile binding.
final class AuthBundle {
  final String accessToken;
  final String refreshToken;
  final int userId;
  final String username;
  final String displayName;
  final String role;
  final int authorizationVersion;

  const AuthBundle({
    required this.accessToken,
    required this.refreshToken,
    required this.userId,
    required this.username,
    required this.displayName,
    required this.role,
    required this.authorizationVersion,
  });
}

/// Counts durable local state without exposing payload or member data.
final class LocalDataInventory {
  final int privatePending;
  final int privateNeedsAttention;
  final int privatePaused;
  final int communicationDrafts;
  final int privateCacheRows;
  final int sharedHymnUnresolved;

  const LocalDataInventory({
    this.privatePending = 0,
    this.privateNeedsAttention = 0,
    this.privatePaused = 0,
    this.communicationDrafts = 0,
    this.privateCacheRows = 0,
    this.sharedHymnUnresolved = 0,
  });

  int get privateDurableWork =>
      privatePending +
      privateNeedsAttention +
      privatePaused +
      communicationDrafts;

  bool get hasPrivateDurableWork => privateDurableWork > 0;
  bool get hasPrivateData => hasPrivateDurableWork || privateCacheRows > 0;
}
