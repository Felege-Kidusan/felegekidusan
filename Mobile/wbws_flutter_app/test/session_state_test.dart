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

  test('private durable inventory excludes shared hymn work', () {
    const inventory = LocalDataInventory(
      privatePending: 2,
      privateNeedsAttention: 1,
      privatePaused: 1,
      communicationDrafts: 1,
      sharedHymnUnresolved: 9,
    );
    expect(inventory.privateDurableWork, 5);
    expect(inventory.hasPrivateDurableWork, isTrue);
    expect(
      const LocalDataInventory(sharedHymnUnresolved: 9).hasPrivateData,
      isFalse,
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
