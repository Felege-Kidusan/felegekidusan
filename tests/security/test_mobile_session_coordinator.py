"""Risk #9 mobile session preservation/recovery contracts.

Dart/Flutter is unavailable in the repository test environment, so these tests
bind the independently reviewable coordinator unit to its source-level safety
properties. SQLite migration/race behavior remains executable in
``test_mobile_v34_sqlite_runtime.py`` and pure policy cases live in
``Mobile/wbws_flutter_app/test/session_state_test.dart``.
"""

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
APP = ROOT / "Mobile" / "wbws_flutter_app" / "lib"
API = (APP / "services" / "api_service.dart").read_text(encoding="utf-8")
SESSION = (APP / "services" / "session_service.dart").read_text(encoding="utf-8")
MODELS = (APP / "services" / "session_models.dart").read_text(encoding="utf-8")
DB = (APP / "services" / "local_db.dart").read_text(encoding="utf-8")
MAIN = (APP / "main.dart").read_text(encoding="utf-8")
LOGIN = (APP / "screens" / "auth" / "login_screen.dart").read_text(encoding="utf-8")
SHELL = (APP / "screens" / "shell" / "app_shell.dart").read_text(encoding="utf-8")
LOCK = (APP / "screens" / "lock" / "lock_screen.dart").read_text(encoding="utf-8")
RECOVERY = (APP / "screens" / "auth" / "session_recovery_screen.dart").read_text(
    encoding="utf-8"
)
LOGOUT = (APP / "widgets" / "session_logout_dialog.dart").read_text(encoding="utf-8")
SYNC = (APP / "services" / "sync_service.dart").read_text(encoding="utf-8")
COMM_OUTBOX = (APP / "services" / "comm_outbox_service.dart").read_text(encoding="utf-8")
NOTIFICATIONS = (APP / "services" / "notification_service.dart").read_text(encoding="utf-8")
CATALOG = (APP / "services" / "catalog_service.dart").read_text(encoding="utf-8")
WARM_STORE = (APP / "services" / "warm_store.dart").read_text(encoding="utf-8")
HYMNS = (APP / "services" / "hymn_store.dart").read_text(encoding="utf-8")


def method(source: str, signature: str, next_signature: str) -> str:
    return source.split(signature, 1)[1].split(next_signature, 1)[0]


def test_credential_bootstrap_is_typed_and_read_failure_is_not_absence() -> None:
    for state in (
        "complete",
        "absent",
        "incomplete",
        "unreadable",
        "storageUnavailable",
    ):
        assert state in MODELS
    load = method(API, "Future<CredentialLoadResult> loadCredentials()", "/// Backward-compatible")
    assert "CredentialLoadResult.storageUnavailable" in load
    assert "CredentialLoadResult.unreadable" in load
    assert "CredentialLoadResult.incomplete" in load
    assert "CredentialLoadResult.absent" in load
    # No exception-to-null helper and no destructive delete in the read path.
    assert "return null" not in load
    assert "_secureStorage.delete" not in load


def test_login_is_two_phase_and_only_coordinator_activates_candidate() -> None:
    assert "class SessionCoordinator" in SESSION
    login = method(API, "Future<ApiResponse> login", "/// Rotate the refresh token")
    assert "post('/auth/login'" in login
    assert "activateCredentials" not in login
    assert "_secureStorage.write" not in login
    assert "bundleFromLoginResponse" in SESSION
    assert "canActivateCandidate" in SESSION
    assert "await _api.activateCredentials(candidate);" in SESSION
    assert SESSION.index("canActivateCandidate") < SESSION.index(
        "await _api.activateCredentials(candidate);"
    )
    assert "SessionCoordinator().login" in LOGIN
    assert "Navigator.of(context).pushAndRemoveUntil" not in LOGIN
    assert "startAutoSync" not in LOGIN


def test_root_and_app_lock_are_driven_by_coordinator_state() -> None:
    assert "switch (_session.root)" in MAIN
    for root in (
        "SessionRoot.active",
        "SessionRoot.reauthentication",
        "SessionRoot.orphanRecovery",
        "SessionRoot.purging",
        "SessionRoot.cleanLogin",
        "SessionRoot.protectionFailure",
    ):
        assert root in MAIN
    assert "_appLock.isLocked && _session.protectsPrivateState" in MAIN
    assert "api.isLoggedIn ? const AppShell()" not in MAIN
    assert "discardedInvalidSession" not in MAIN


def test_auth_loss_persists_recovery_before_clearing_credentials() -> None:
    body = method(SESSION, "Future<void> enterReauthentication", "Future<void> applyLogoutChoice")
    assert "_generation += 1" in body
    assert "_persistRecovery" in body
    assert "_api.clearCredentials" in body
    assert body.index("_persistRecovery") < body.index("_api.clearCredentials")
    assert "clearAllUserData" not in body
    assert "clearPin" not in body
    assert "onAuthExpired" not in SHELL
    assert "_api.onAuthExpired" in SESSION


def test_purge_marker_is_crash_resumable_and_shared_hymns_survive() -> None:
    assert "if (_record.state == SessionState.purging)" in SESSION
    assert "await _finishPurging();" in SESSION
    marker = method(SESSION, "Future<void> _markPurging", "Future<void> _finishPurging")
    assert "SessionState.purging" in marker
    assert "persistLocalSession" in marker
    purge = method(SESSION, "Future<void> _finishPurging", "Future<void> _persistRecovery")
    assert purge.index("await _api.logout()") < purge.index("clearAllUserData")
    assert "await AppLockService().clearPin()" in purge
    wipe = method(DB, "Future<void> clearAllUserData()", "\n}")
    for private in ("'pending_attendance'", "'pending_grades'", "'pending_mezmur'", "'pending_hr'", "'comm_outbox'", "'comm_drafts'"):
        assert private in wipe
    for shared in ("'cached_hymns'", "'pending_hymn_ops'", "'hymn_sync_meta'"):
        assert shared not in wipe


def test_inventory_covers_every_private_domain_and_reports_shared_work_separately() -> None:
    inventory = method(DB, "Future<LocalDataInventory> getLocalDataInventory()", "static int? _nullablePositiveInt")
    for table in (
        "pending_attendance",
        "pending_grades",
        "pending_mezmur",
        "pending_hr",
        "comm_outbox",
        "comm_drafts",
    ):
        assert table in inventory
    assert "TRIM(body) <> ''" in inventory
    assert "'failed'" in inventory
    assert "'paused_auth'" in inventory
    assert "sync_state = 'needs_attention'" in inventory
    assert "privateOwnerUserIds" in inventory
    assert "pending_hymn_ops" in inventory
    assert "sharedHymnOperations" in inventory
    model_private_total = method(MODELS, "int get privateDurableWork", "bool get hasPrivateDurableWork")
    assert "sharedHymnOperations" not in model_private_total
    # The persisted notification badge is private cache outside SQLite. It is
    # included in owner-switch/orphan decisions and only deleted by purge.
    load_inventory = method(SESSION, "Future<LocalDataInventory> _loadInventory()", "Future<void> bootstrap()")
    assert "NotificationService.instance.hasPersistedState()" in load_inventory
    assert "withAdditionalPrivateCacheRows(1)" in load_inventory
    purge = method(SESSION, "Future<void> _finishPurging", "Future<void> _persistRecovery")
    assert "NotificationService.instance.clearPersistedState()" in purge


def test_owner_and_scope_are_stamped_at_every_private_creation_boundary() -> None:
    assert "requireActiveOwnerBinding" in DB
    for save in ("saveAttendanceLocal", "saveGradesLocal", "saveMezmurLocal", "saveHrLocal"):
        body = DB.split(f"{save}(", 1)[1].split("\n  Future<", 1)[0]
        assert "requireActiveOwnerBinding" in body
        assert "...ownerBinding" in body
    comm = (APP / "services" / "comm_store.dart").read_text(encoding="utf-8")
    enqueue = comm.split("enqueueOutbox(", 1)[1].split("/// All pending entries", 1)[0]
    draft = comm.split("saveDraft(", 1)[1].split("// ── Meta", 1)[0]
    for body in (enqueue, draft):
        assert "requireActiveOwnerBinding" in body
        assert "...binding" in body
    assert "created_by_user_id" in DB.split("enqueueHymnOp(", 1)[1].split("Future<", 1)[0]


def test_generation_supersedes_late_http_and_whole_worker_chains() -> None:
    assert "sessionGenerationProvider" in API
    assert "ApiResponse.superseded" in API
    assert "_generationIsCurrent" in API
    refresh = method(API, "Future<bool> _performRefreshAccessToken", "// ============================================================\n  // DASHBOARD")
    assert "_refreshToken != presentedRefreshToken" in refresh
    assert "_serializeCredentialMutation" in refresh

    # Every long-lived worker captures the coordinator generation and checks it
    # both before later requests and before response settlement/cache writes.
    for source in (SYNC, COMM_OUTBOX, NOTIFICATIONS, CATALOG, WARM_STORE, HYMNS):
        assert "sessionGenerationProvider" in source
        assert "_ownsGeneration" in source
    for service in (
        "SyncService()",
        "CommOutboxService.instance",
        "NotificationService.instance",
        "CatalogService()",
        "WarmStore()",
        "HymnStore()",
    ):
        assert f"{service}.sessionGenerationProvider = () => _generation" in SESSION

    assert "_syncAllForGeneration(generation)" in SYNC
    assert "_drain(generation: generation" in SYNC
    assert "res.sessionSuperseded || !_ownsGeneration(generation)" in SYNC
    assert "_drainOnce(generation)" in COMM_OUTBOX
    assert "res.sessionSuperseded || !_ownsGeneration(generation)" in COMM_OUTBOX
    assert "_restoreCachedSummary(generation)" in NOTIFICATIONS
    assert "res.sessionSuperseded" in NOTIFICATIONS
    assert "_doFetch(generation)" in CATALOG
    assert "classes(expectedGeneration: generation)" in WARM_STORE
    assert "final generation = sessionGenerationProvider?.call() ?? 0" in HYMNS
    assert "await hymnStore.pushPending()" in SYNC
    assert "await hymnStore.pullChanges()" in SYNC
    assert "res.sessionSuperseded || !_ownsGeneration(generation)" in HYMNS


def test_logout_and_forgot_pin_use_explicit_central_destructive_policy() -> None:
    for choice in ("cancel", "preserveForReauthentication", "discardPrivateData"):
        assert choice in MODELS
        assert choice in LOGOUT
    assert "showSessionLogoutDialog" in (
        APP / "screens" / "profile" / "profile_screen.dart"
    ).read_text(encoding="utf-8")
    assert "showSessionLogoutDialog" in (
        APP / "screens" / "teacher" / "teacher_home.dart"
    ).read_text(encoding="utf-8")
    assert "inventory.workSummary" in LOCK
    assert "destructiveSignOut(reason: 'forgot_pin')" in LOCK
    assert "SessionService.signOut" not in LOCK
    assert "shared hymn operation" in LOCK
    assert "discardOrphanedData" in RECOVERY


def test_account_scoped_service_lifecycle_has_one_owner() -> None:
    for needle in (
        "SyncService().startAutoSync()",
        "CommOutboxService.instance.start()",
        "NotificationService.instance.start()",
        "CatalogService().hydrate()",
        "WarmStore().afterLogin()",
    ):
        assert needle in SESSION
    all_dart = "\n".join(
        path.read_text(encoding="utf-8")
        for path in APP.rglob("*.dart")
        if path.name != "session_service.dart"
    )
    for forbidden in (
        "CommOutboxService.instance.start()",
        "NotificationService.instance.start()",
        "WarmStore().afterLogin()",
        "CatalogService().hydrate()",
    ):
        assert forbidden not in all_dart
