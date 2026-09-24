from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICES = ROOT / "Mobile" / "wbws_flutter_app" / "lib" / "services"
SCREENS = ROOT / "Mobile" / "wbws_flutter_app" / "lib" / "screens"


def source(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def test_legacy_claim_and_settlement_use_immutable_lease_identity() -> None:
    db = source(SERVICES / "local_db.dart")
    claim = db[db.index("claimNextLegacyOperation"):
               db.index("settleLegacyOperation")]
    settle = db[db.index("_legacyExactWhere"):
                db.index("undoSubmittedLegacyOperation")]
    replace = db[db.index("_replaceLegacyOperation"):
                 db.index("saveAttendanceLocal")]
    assert "owner_user_id = ?" in replace
    assert "created_authorization_version = ?" in replace
    assert "last_attempt_at = ?" in claim
    assert "activeSessionMatches(" in claim
    assert "claim.claimedAt.toUtc().toIso8601String()" in settle
    assert "activeSessionMatches(" in settle
    assert "sync_state = 'in_flight'" in settle
    assert "LegacySettlementResult.supersededLocal" in settle


def test_communication_claim_is_per_thread_fifo_and_lease_bound() -> None:
    comm = source(SERVICES / "comm_store.dart")
    claim = comm[comm.index("claimNextDueHead"):
                 comm.index("settleClaim")]
    settle = comm[comm.index("settleClaim"):
                  comm.index("outboxForThread")]
    assert "prior.thread_id = c.thread_id" in claim
    assert "NOT EXISTS" in claim
    assert "activeSessionMatches(" in claim
    assert "last_attempt_at': due" in claim
    assert "claim.claimedAt.toUtc().toIso8601String()" in settle
    assert "activeSessionMatches(" in settle
    assert "owner_user_id = ?" in settle
    assert "created_authorization_version = ?" in settle


def test_hymn_failures_are_retained_and_exactly_settled() -> None:
    hymn = source(SERVICES / "hymn_store.dart")
    db = source(SERVICES / "local_db.dart")
    push = hymn[hymn.index("Future<int> pushPending()"):
                hymn.index("Map<String, dynamic>? _itemFrom")]
    assert "claimNextHymnOperation" in push
    assert "settleHymnOperation" in push
    assert "classifyOutboxResponse" in push
    assert "dropHymnOp" not in push
    assert "MALFORMED_LOCAL_PAYLOAD" in push
    assert "UNKNOWN_HYMN_OPERATION" in push
    assert "claim.operation == 'hymn_save' && canonical != null" in push
    assert push.index("_applyAcceptedHymnOperation") < push.index(
        "final settlement = _hymnSettlement"
    )
    assert "LOCAL_RECONCILIATION_PENDING" in push
    settle_start = db.index("settleHymnOperation")
    settle = db[settle_start:db.index(
        "replacePendingHymnSaveForLocalId", settle_start
    )]
    assert "resolved_conflict" in settle
    assert "needs_attention" in settle
    assert "activeSessionMatches(" in settle
    assert "last_attempt_at = ?" in settle


def test_hymn_dependency_and_terminal_head_policy_is_durable() -> None:
    db = source(SERVICES / "local_db.dart")
    enqueue_start = db.index("_hymnDependencyFor")
    enqueue = db[enqueue_start:db.index("getPendingHymnOps", enqueue_start)]
    claim = db[db.index("claimNextHymnOperation"):
               db.index("settleHymnOperation")]
    assert "entity_key" in enqueue
    assert "depends_on" in enqueue
    assert "activeSessionMatches(" in claim
    assert "blocked_dependency" in claim
    assert "DEPENDENCY_UNRESOLVED" in claim
    assert "dependency.synced = 1" in claim
    placeholder_rebase = db[db.index("rebasePendingHymnPlaceholder"):
                            db.index("rebaseNewerPendingHymnRevision")]
    revision_rebase = db[db.index("rebaseNewerPendingHymnRevision"):
                         db.index("getPendingHymnSavesForLocalId")]
    assert "sync_state <> 'in_flight'" in placeholder_rebase
    assert "'in_flight'" not in revision_rebase


def test_inventory_separates_due_waiting_inflight_attention_and_pauses() -> None:
    db = source(SERVICES / "local_db.dart")
    models = source(SERVICES / "session_models.dart")
    inventory = db[db.index("getOutboxInventory"):
                   db.index("getLocalDataInventory")]
    for token in (
        "retry_wait", "in_flight", "needs_attention", "paused_auth",
        "paused_scope", "blocked_dependency", "resolved_conflict",
    ):
        assert token in inventory
    assert "requireActiveOwnerBinding(txn)" in inventory
    assert "owner_user_id = ?" in inventory
    assert "created_authorization_version = ?" in inventory
    for field in (
        "retryableDue", "retryableWaiting", "inFlight", "needsAttention",
        "pausedAuth", "pausedScope", "blockedDependency",
        "resolvedConflict", "privateUnresolvedTotal",
        "sharedHymnUnresolvedTotal", "communicationDraftCount",
    ):
        assert f"final int {field};" in models


def test_grade_autosave_submit_and_undo_are_ordered_and_exact() -> None:
    screen = source(SCREENS / "teacher" / "teacher_grades.dart")
    db = source(SERVICES / "local_db.dart")
    assert "Timer(const Duration(milliseconds: 450)" in screen
    assert "await _autosaveTail" in screen
    assert "notBefore: DateTime.now().add(const Duration(seconds: 5))" in screen
    assert "undoSubmittedLegacyOperation(operation)" in screen
    assert "gradesPacketPending" not in screen
    undo = db[db.index("undoSubmittedLegacyOperation"):
              db.index("_replaceLegacyOperation")]
    assert "operation.clientOpId" in undo
    assert "operation.runtimeGeneration" in undo
    assert "sync_state IN ('pending', 'retry_wait')" in undo
    assert "SubmitUndoResult.alreadyClaimed" in undo
    assert "final freshId = newClientOpId();" in undo
