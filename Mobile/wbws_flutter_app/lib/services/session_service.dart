import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';

import 'api_service.dart';
import 'app_lock_service.dart';
import 'catalog_service.dart';
import 'comm_outbox_service.dart';
import 'hymn_store.dart';
import 'local_db.dart';
import 'notification_service.dart';
import 'session_models.dart';
import 'sync_service.dart';
import 'warm_store.dart';

/// The sole owner of mobile authentication/session state.
///
/// Tokens are only one input. A usable session exists after credentials,
/// durable owner metadata and authorization scope have been reconciled and the
/// `active` marker is persisted. Every root and background service follows this
/// coordinator rather than independently interpreting token presence.
class SessionCoordinator extends ChangeNotifier with WidgetsBindingObserver {
  SessionCoordinator._() {
    _api.sessionGenerationProvider = () => _generation;
    _api.onAuthExpired = (reason) => enterReauthentication(reason: reason);
    SyncService().activeSessionGate = () => isActive;
    SyncService().sessionGenerationProvider = () => _generation;
    CommOutboxService.instance.activeSessionGate = () => isActive;
    CommOutboxService.instance.sessionGenerationProvider = () => _generation;
    NotificationService.instance.activeSessionGate = () => isActive;
    NotificationService.instance.sessionGenerationProvider = () => _generation;
    CatalogService().activeSessionGate = () => isActive;
    CatalogService().sessionGenerationProvider = () => _generation;
    WarmStore().activeSessionGate = () => isActive;
    WarmStore().sessionGenerationProvider = () => _generation;
    HymnStore().activeSessionGate = () => isActive;
    HymnStore().sessionGenerationProvider = () => _generation;
    WidgetsBinding.instance.addObserver(this);
  }

  static final SessionCoordinator _instance = SessionCoordinator._();
  factory SessionCoordinator() => _instance;

  final ApiService _api = ApiService();
  final LocalDb _db = LocalDb();

  SessionRoot _root = SessionRoot.cleanLogin;
  LocalSessionRecord _record = const LocalSessionRecord(
    state: SessionState.anonymousClean,
    generation: 0,
  );
  LocalDataInventory _inventory = const LocalDataInventory();
  CredentialLoadState _credentialState = CredentialLoadState.absent;
  int _generation = 0;
  bool _busy = false;
  bool _bootstrapped = false;
  String? _diagnostic;

  SessionRoot get root => _root;
  LocalSessionRecord get record => _record;
  LocalDataInventory get inventory => _inventory;
  CredentialLoadState get credentialState => _credentialState;
  int get generation => _generation;
  bool get busy => _busy;
  bool get bootstrapped => _bootstrapped;
  String? get diagnostic => _diagnostic;
  bool get isActive => _root == SessionRoot.active;
  bool get protectsPrivateState =>
      _root == SessionRoot.active ||
      _root == SessionRoot.reauthentication ||
      _root == SessionRoot.orphanRecovery ||
      (_root == SessionRoot.protectionFailure && _inventory.hasPrivateData);

  bool get _hasOwnerMetadataConflict {
    final owners = _inventory.privateOwnerUserIds;
    if (owners.length > 1) return true;
    return owners.length == 1 &&
        _record.ownerUserId != null &&
        owners.single != _record.ownerUserId;
  }

  int? get _reconciledOwnerUserId {
    if (_hasOwnerMetadataConflict) return null;
    if (_record.ownerUserId != null) return _record.ownerUserId;
    return _inventory.privateOwnerUserIds.length == 1
        ? _inventory.privateOwnerUserIds.single
        : null;
  }

  Future<LocalDataInventory> _loadInventory() async {
    final sqlite = await _db.getLocalDataInventory();
    final hasNotificationSummary =
        await NotificationService.instance.hasPersistedState();
    return hasNotificationSummary
        ? sqlite.withAdditionalPrivateCacheRows(1)
        : sqlite;
  }

  Future<void> bootstrap() async {
    if (_busy) return;
    _busy = true;
    _diagnostic = null;
    _stopPrivateServices();
    try {
      _record = await _db.getLocalSession();
      _generation = _record.generation;
      _inventory = await _loadInventory();

      // A destructive action is a durable state machine, not a best-effort
      // series of deletes. Resume it before inspecting credentials.
      if (_record.state == SessionState.purging) {
        _root = SessionRoot.purging;
        _publish();
        await _finishPurging();
        return;
      }

      final credentials = await _api.loadCredentials();
      _credentialState = credentials.state;
      if (credentials.state == CredentialLoadState.unreadable ||
          credentials.state == CredentialLoadState.storageUnavailable) {
        _enterProtectionFailure(
          credentials.diagnostic ?? 'Protected credentials are unavailable.',
        );
        return;
      }

      // A crash after persisting recovery/orphan state must not reactivate a
      // stale token pair merely because secure storage still contains it.
      if (_record.state == SessionState.reauthRequired ||
          _record.state == SessionState.orphanedLocalData) {
        await _clearInvalidCredentialsOrFail();
        if (_root == SessionRoot.protectionFailure) return;
        _root = _record.state == SessionState.orphanedLocalData
            ? SessionRoot.orphanRecovery
            : SessionRoot.reauthentication;
        return;
      }

      if (credentials.state == CredentialLoadState.complete) {
        await _bootstrapComplete(credentials.bundle!);
        return;
      }

      // Incomplete secure credentials are invalid, but their existence never
      // authorizes deletion of SQLite work or a PIN.
      if (credentials.state == CredentialLoadState.incomplete) {
        await _clearInvalidCredentialsOrFail();
        if (_root == SessionRoot.protectionFailure) return;
      }
      await _bootstrapWithoutCredentials();
    } catch (error, stack) {
      _enterProtectionFailure('$error\n$stack');
    } finally {
      _busy = false;
      _bootstrapped = true;
      _publish();
    }
  }

  Future<void> _bootstrapComplete(AuthBundle bundle) async {
    if (_hasOwnerMetadataConflict) {
      await _persistRecovery(
        state: SessionState.orphanedLocalData,
        reason: 'conflicting_private_owner_metadata',
        ownerUserId: null,
        ownerRole: null,
        ownerUsername: null,
        ownerDisplayName: null,
        authorizationVersion: null,
      );
      await _clearInvalidCredentialsOrFail();
      if (_root != SessionRoot.protectionFailure) {
        _root = SessionRoot.orphanRecovery;
      }
      return;
    }
    final owner = _reconciledOwnerUserId;
    if (owner != null && owner != bundle.userId) {
      if (_inventory.hasPrivateDurableWork) {
        // The protected profile proves a different account, never ownership of
        // the prior private rows. Preserve those rows for their known owner.
        await _persistRecovery(
          state: SessionState.reauthRequired,
          reason: 'different_secure_owner',
          ownerUserId: owner,
          ownerRole: _record.ownerRole,
          ownerUsername: _record.ownerUsername,
          ownerDisplayName: _record.ownerDisplayName,
          authorizationVersion: _record.authorizationVersion,
        );
        await _clearInvalidCredentialsOrFail();
        if (_root != SessionRoot.protectionFailure) {
          _root = SessionRoot.reauthentication;
        }
        return;
      }
      // No private work belongs to the old owner, but role-scoped caches still
      // must cross a crash-safe purge boundary before the new owner is bound.
      await _markPurging('owner_switch_without_private_work');
      await _finishPurging();
      if (_root == SessionRoot.protectionFailure) return;
      // Purging clears secure storage. The already-read bundle is still valid
      // candidate evidence and can now proceed through phase-two activation.
      await _activateCandidate(bundle);
      return;
    }

    // Complete protected credentials are sufficient evidence to claim
    // ownerless legacy rows. Nothing else may perform this backfill.
    _api.adoptLoadedCredentials(bundle);
    await _db.backfillOwnerlessRows(
      ownerUserId: bundle.userId,
      authorizationVersion: bundle.authorizationVersion,
    );
    await _persistActive(bundle);
    _startPrivateServices();
  }

  Future<void> _bootstrapWithoutCredentials() async {
    if (_hasOwnerMetadataConflict) {
      await _persistRecovery(
        state: SessionState.orphanedLocalData,
        reason: 'conflicting_private_owner_metadata',
        ownerUserId: null,
        ownerRole: null,
        ownerUsername: null,
        ownerDisplayName: null,
        authorizationVersion: null,
      );
      _root = SessionRoot.orphanRecovery;
      return;
    }
    final reconciledOwner = _reconciledOwnerUserId;
    final next = stateForMissingCredentials(
      boundOwnerUserId: reconciledOwner,
      inventory: _inventory,
    );
    if (next == SessionState.anonymousClean) {
      _root = SessionRoot.cleanLogin;
      await _db.persistLocalSession(
        state: SessionState.anonymousClean,
        generation: _generation,
      );
      _record = await _db.getLocalSession();
      return;
    }
    await _persistRecovery(
      state: next,
      reason: 'credentials_${_credentialState.name}',
      ownerUserId: reconciledOwner,
      ownerRole: _record.ownerRole,
      ownerUsername: _record.ownerUsername,
      ownerDisplayName: _record.ownerDisplayName,
      authorizationVersion: _record.authorizationVersion,
    );
    _root = next == SessionState.orphanedLocalData
        ? SessionRoot.orphanRecovery
        : SessionRoot.reauthentication;
  }

  Future<LoginActivationResult> login(
      String username, String password) async {
    if (_busy) {
      return const LoginActivationResult(
        LoginActivationState.rejected,
        message: 'Another session action is still finishing.',
      );
    }
    if (_root == SessionRoot.orphanRecovery) {
      return const LoginActivationResult(
        LoginActivationState.orphanedDataBlocked,
        message: 'This phone has private work with no provable owner. '
            'Discard it explicitly before signing in.',
      );
    }

    _busy = true;
    _diagnostic = null;
    _publish();
    try {
      final response = await _api.login(username.trim(), password);
      if (!response.success) {
        return LoginActivationResult(
          LoginActivationState.rejected,
          message: response.message ?? 'Login failed.',
        );
      }
      final candidate = _api.bundleFromLoginResponse(response);
      if (candidate == null) {
        return const LoginActivationResult(
          LoginActivationState.rejected,
          message: 'The server returned incomplete credentials.',
        );
      }

      _inventory = await _loadInventory();
      _record = await _db.getLocalSession();
      if (_hasOwnerMetadataConflict) {
        await _persistRecovery(
          state: SessionState.orphanedLocalData,
          reason: 'conflicting_private_owner_metadata',
          ownerUserId: null,
          ownerRole: null,
          ownerUsername: null,
          ownerDisplayName: null,
          authorizationVersion: null,
        );
        _root = SessionRoot.orphanRecovery;
        await _api.revokeBundle(candidate);
        return const LoginActivationResult(
          LoginActivationState.orphanedDataBlocked,
          message: 'Private rows contain conflicting owner metadata and '
              'cannot be attached to any login.',
        );
      }
      final reconciledOwner = _reconciledOwnerUserId;
      if (!canActivateCandidate(
        currentState: _record.state,
        boundOwnerUserId: reconciledOwner,
        candidateUserId: candidate.userId,
        inventory: _inventory,
      )) {
        await _api.revokeBundle(candidate);
        final orphaned = _record.state == SessionState.orphanedLocalData;
        return LoginActivationResult(
          orphaned
              ? LoginActivationState.orphanedDataBlocked
              : LoginActivationState.priorOwnerRequired,
          message: orphaned
              ? 'Local private work has no provable owner and cannot be attached to this login.'
              : 'This phone contains private work for the previous account. '
                  'Sign in as that account or explicitly discard the work.',
        );
      }

      if (reconciledOwner != null && reconciledOwner != candidate.userId) {
        // Different owner is allowed only after the inventory proved there is
        // no durable private work. Purge role-scoped read caches and old PIN.
        await _markPurging('confirmed_owner_switch');
        await _finishPurging();
        if (_root == SessionRoot.protectionFailure) {
          await _api.revokeBundle(candidate);
          return LoginActivationResult(
            LoginActivationState.protectionFailure,
            message: _diagnostic,
          );
        }
      }

      await _activateCandidate(candidate);
      return const LoginActivationResult(LoginActivationState.activated);
    } catch (error, stack) {
      _enterProtectionFailure('$error\n$stack');
      return LoginActivationResult(
        LoginActivationState.protectionFailure,
        message: '$error',
      );
    } finally {
      _busy = false;
      _publish();
    }
  }

  Future<void> _activateCandidate(AuthBundle candidate) async {
    _generation += 1;
    await _api.activateCredentials(candidate);
    await _db.backfillOwnerlessRows(
      ownerUserId: candidate.userId,
      authorizationVersion: candidate.authorizationVersion,
    );
    await _persistActive(candidate);
    _credentialState = CredentialLoadState.complete;
    _root = SessionRoot.active;
    _startPrivateServices();
  }

  Future<void> _persistActive(AuthBundle bundle) async {
    final user = jsonDecode(bundle.userJson) as Map<String, dynamic>;
    await _db.persistLocalSession(
      state: SessionState.active,
      generation: _generation,
      ownerUserId: bundle.userId,
      authorizationVersion: bundle.authorizationVersion,
      ownerRole: bundle.role,
      ownerUsername: user['username']?.toString(),
      ownerDisplayName: user['full_name']?.toString(),
    );
    _record = await _db.getLocalSession();
    _inventory = await _loadInventory();
    _root = SessionRoot.active;
  }

  /// Definitive credential loss preserves private SQLite work and the app PIN.
  /// Persisting the recovery marker and advancing generation happens before
  /// credentials are cleared, so a crash cannot silently reopen the shell.
  Future<void> enterReauthentication({
    required String reason,
    bool revokeCurrentSession = false,
  }) async {
    if (_root != SessionRoot.active || _busy) return;
    _busy = true;
    _stopPrivateServices();
    _root = SessionRoot.reauthentication;
    _generation += 1;
    _publish();
    try {
      final user = _api.userData;
      await _persistRecovery(
        state: SessionState.reauthRequired,
        reason: reason,
        ownerUserId: _record.ownerUserId ?? _api.userId,
        ownerRole: _record.ownerRole ?? _api.userRole,
        ownerUsername:
            _record.ownerUsername ?? user?['username']?.toString(),
        ownerDisplayName:
            _record.ownerDisplayName ?? user?['full_name']?.toString(),
        authorizationVersion:
            _record.authorizationVersion ?? _api.authorizationVersion,
        generationAlreadyAdvanced: true,
      );
      if (revokeCurrentSession) {
        await _api.logout();
      } else {
        await _api.clearCredentials();
      }
      _credentialState = CredentialLoadState.absent;
      _inventory = await _loadInventory();
    } catch (error, stack) {
      _enterProtectionFailure('$error\n$stack');
    } finally {
      _busy = false;
      _publish();
    }
  }

  Future<void> applyLogoutChoice(LogoutChoice choice) async {
    switch (choice) {
      case LogoutChoice.cancel:
        return;
      case LogoutChoice.preserveForReauthentication:
        await enterReauthentication(
          reason: 'explicit_logout_preserve',
          revokeCurrentSession: true,
        );
        return;
      case LogoutChoice.discardPrivateData:
        await destructiveSignOut(reason: 'explicit_logout_discard');
        return;
    }
  }

  Future<void> destructiveSignOut({required String reason}) async {
    if (_busy) return;
    _busy = true;
    _stopPrivateServices();
    try {
      await _markPurging(reason);
      await _finishPurging();
    } catch (error, stack) {
      _enterProtectionFailure('$error\n$stack');
    } finally {
      _busy = false;
      _publish();
    }
  }

  Future<void> discardOrphanedData() =>
      destructiveSignOut(reason: 'explicit_orphan_discard');

  Future<LocalDataInventory> refreshInventory() async {
    _inventory = await _loadInventory();
    _publish();
    return _inventory;
  }

  Future<void> _markPurging(String reason) async {
    _generation += 1;
    _root = SessionRoot.purging;
    await _db.persistLocalSession(
      state: SessionState.purging,
      generation: _generation,
      ownerUserId: _record.ownerUserId,
      authorizationVersion: _record.authorizationVersion,
      ownerRole: _record.ownerRole,
      ownerUsername: _record.ownerUsername,
      ownerDisplayName: _record.ownerDisplayName,
      reason: reason,
    );
    _record = await _db.getLocalSession();
    _publish();
  }

  Future<void> _finishPurging() async {
    _stopPrivateServices();
    _root = SessionRoot.purging;
    _publish();
    try {
      // Credentials first. If protected storage cannot be cleared, keep the
      // purging marker and fail closed rather than later auto-activating a
      // credential whose private rows were already destroyed.
      await _api.logout();
      await NotificationService.instance.clearPersistedState();
      await _db.clearAllUserData();
      await AppLockService().clearPin();
      await _db.persistLocalSession(
        state: SessionState.anonymousClean,
        generation: _generation,
      );
      _record = await _db.getLocalSession();
      _inventory = await _loadInventory();
      _credentialState = CredentialLoadState.absent;
      _root = SessionRoot.cleanLogin;
    } catch (error, stack) {
      _enterProtectionFailure('$error\n$stack');
    }
  }

  Future<void> _persistRecovery({
    required SessionState state,
    required String reason,
    required int? ownerUserId,
    required int? authorizationVersion,
    required String? ownerRole,
    required String? ownerUsername,
    required String? ownerDisplayName,
    bool generationAlreadyAdvanced = false,
  }) async {
    if (!generationAlreadyAdvanced) _generation += 1;
    await _db.persistLocalSession(
      state: state,
      generation: _generation,
      ownerUserId: ownerUserId,
      authorizationVersion: authorizationVersion,
      ownerRole: ownerRole,
      ownerUsername: ownerUsername,
      ownerDisplayName: ownerDisplayName,
      reason: reason,
    );
    _record = await _db.getLocalSession();
  }

  Future<void> _clearInvalidCredentialsOrFail() async {
    try {
      await _api.clearCredentials();
      _credentialState = CredentialLoadState.absent;
    } catch (error, stack) {
      _enterProtectionFailure('$error\n$stack');
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state != AppLifecycleState.resumed || !isActive) return;
    _startPrivateServices();
    SyncService().syncAll(force: true);
    CommOutboxService.instance.kick();
  }

  void _startPrivateServices() {
    if (_root != SessionRoot.active || !_api.isLoggedIn) return;
    SyncService().startAutoSync();
    CommOutboxService.instance.start();
    NotificationService.instance.start();
    CatalogService().hydrate();
    WarmStore().afterLogin();
  }

  void _stopPrivateServices() {
    SyncService().stopAutoSync();
    CommOutboxService.instance.stop();
    NotificationService.instance.stop();
    CatalogService().clear();
  }

  void _enterProtectionFailure(String diagnostic) {
    _stopPrivateServices();
    _diagnostic = diagnostic;
    _root = SessionRoot.protectionFailure;
  }

  void _publish() {
    if (hasListeners) notifyListeners();
  }
}

/// Transitional source-compatible name; all production callers use the
/// coordinator directly.
typedef SessionService = SessionCoordinator;
