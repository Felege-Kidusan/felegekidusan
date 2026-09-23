import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/legacy_outbox_models.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  test('session storage values round-trip and unknown fails closed', () {
    for (final state in SessionState.values) {
      expect(SessionState.fromStorage(state.storageValue), state);
    }
    expect(
      SessionState.fromStorage('unknown-future-state'),
      SessionState.orphanedLocalData,
    );
  });

  test('private durable inventory is domain-accurate and excludes hymns', () {
    const inventory = LocalDataInventory(
      attendanceOperations: 2,
      communicationDrafts: 1,
      attentionOperations: 1,
      pausedOperations: 1,
      sharedHymnOperations: 9,
    );
    // Attention and paused are reporting subsets, not extra operations.
    expect(inventory.privateDurableWork, 3);
    expect(inventory.hasPrivateDurableWork, isTrue);
    expect(
      const LocalDataInventory(sharedHymnOperations: 9).hasPrivateData,
      isFalse,
    );
  });

  test('known prior owner blocks another user only for durable private work', () {
    expect(
      canActivateCandidate(
        currentState: SessionState.reauthRequired,
        boundOwnerUserId: 7,
        candidateUserId: 8,
        inventory: const LocalDataInventory(attendanceOperations: 1),
      ),
      isFalse,
    );
    expect(
      canActivateCandidate(
        currentState: SessionState.reauthRequired,
        boundOwnerUserId: 7,
        candidateUserId: 8,
        inventory: const LocalDataInventory(privateCacheRows: 20),
      ),
      isTrue,
    );
    expect(
      canActivateCandidate(
        currentState: SessionState.reauthRequired,
        boundOwnerUserId: 8,
        candidateUserId: 8,
        inventory: const LocalDataInventory(
          attendanceOperations: 1,
          privateOwnerUserIds: [7],
        ),
      ),
      isFalse,
    );
    expect(
      canActivateCandidate(
        currentState: SessionState.orphanedLocalData,
        boundOwnerUserId: null,
        candidateUserId: 8,
        inventory: const LocalDataInventory(),
      ),
      isFalse,
    );
  });

  test('missing credentials preserve known work and orphan unknown rows', () {
    expect(
      stateForMissingCredentials(
        boundOwnerUserId: 7,
        inventory: const LocalDataInventory(),
      ),
      SessionState.reauthRequired,
    );
    expect(
      stateForMissingCredentials(
        boundOwnerUserId: null,
        inventory: const LocalDataInventory(communicationDrafts: 1),
      ),
      SessionState.orphanedLocalData,
    );
    expect(
      stateForMissingCredentials(
        boundOwnerUserId: null,
        inventory: const LocalDataInventory(),
      ),
      SessionState.anonymousClean,
    );
  });

  test('legacy operation reference and claim payload are immutable', () {
    final naturalKey = <String, Object?>{
      'class_id': 7,
      'date': '2026-09-24',
    };
    final operation = LegacyOperationRef(
      kind: LegacyOperationKind.attendance,
      naturalKey: naturalKey,
      clientOpId: 'operation-a',
      packetKind: LegacyPacketKind.submitted,
      ownerUserId: 17,
      createdAuthorizationVersion: 4,
      runtimeGeneration: 9,
    );
    naturalKey['class_id'] = 99;
    expect(operation.naturalKey['class_id'], 7);
    expect(
      () => operation.naturalKey['class_id'] = 99,
      throwsUnsupportedError,
    );

    final sourceRows = <Map<String, Object?>>[
      {'member_id': 1, 'status': 'present'},
    ];
    final claim = LegacyClaimSnapshot(
      operation: operation,
      records: sourceRows,
      claimedAt: DateTime.utc(2026, 9, 24),
      attemptCount: 1,
    );
    sourceRows.first['status'] = 'absent';
    expect(claim.records.first['status'], 'present');
    expect(
      () => claim.records.first['status'] = 'late',
      throwsUnsupportedError,
    );
  });
}
