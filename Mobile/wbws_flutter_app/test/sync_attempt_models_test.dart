import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/outbox_policy.dart';
import 'package:fkss_app/services/session_models.dart';
import 'package:fkss_app/services/sync_attempt_models.dart';

/// S1 verification: operation/attempt lineage vocabulary.
///
/// These are regression tests for observability semantics, not for the sync
/// engine. Nothing here asserts on source strings; every expectation is the
/// result of running the shipped classifier and lineage types.

OutboxResponseEvidence ev({
  bool success = false,
  int status = 0,
  String? code,
  bool replayed = false,
  ApiFailureKind kind = ApiFailureKind.none,
  AuthRefreshOutcome? refresh,
  int attempts = 1,
}) =>
    OutboxResponseEvidence(
      success: success,
      statusCode: status,
      errorCode: code,
      idempotencyReplayed: replayed,
      failureKind: kind,
      refreshOutcome: refresh,
      automaticAttemptCount: attempts,
    );

SyncAttemptRecord attempt({
  required int number,
  required SyncErrorCategory category,
  required SyncRetryDecision decision,
  int? status,
  bool open = false,
}) =>
    SyncAttemptRecord(
      clientOpId: 'op-8f21',
      attemptNumber: number,
      attemptUid: 'att_$number',
      domain: 'pending_attendance',
      startedAt: DateTime.utc(2026, 3, 1, 10, 14, number),
      finishedAt: open ? null : DateTime.utc(2026, 3, 1, 10, 14, number, 500),
      httpStatus: status,
      category: category,
      decision: decision,
    );

void main() {
  group('error classification follows real API semantics', () {
    test('a fresh 2xx is a first delivery, not a replay', () {
      expect(classifySyncErrorCategory(ev(success: true, status: 200)),
          SyncErrorCategory.none);
    });

    test('a replayed 2xx records that an earlier attempt already won', () {
      final category =
          classifySyncErrorCategory(ev(success: true, status: 200, replayed: true));
      expect(category, SyncErrorCategory.idempotencyReplay);
    });

    test('the four distinct meanings of 409 stay distinct', () {
      expect(classifySyncErrorCategory(ev(status: 409, code: 'IDEMPOTENCY_CONFLICT')),
          SyncErrorCategory.idempotencyConflict);
      expect(
          classifySyncErrorCategory(ev(status: 409, code: 'IDEMPOTENCY_IN_PROGRESS')),
          SyncErrorCategory.idempotencyInProgress);
      expect(classifySyncErrorCategory(ev(status: 409, code: 'ALREADY_SUBMITTED')),
          SyncErrorCategory.workflowRejected);
      expect(classifySyncErrorCategory(ev(status: 409, code: 'REVISION_CONFLICT')),
          SyncErrorCategory.revisionConflict);
    });

    test('a replayed 5xx is distinguished from a fresh 5xx', () {
      expect(classifySyncErrorCategory(ev(status: 500)),
          SyncErrorCategory.serverError);
      expect(classifySyncErrorCategory(ev(status: 500, replayed: true)),
          SyncErrorCategory.serverErrorReplayed);
      expect(classifySyncErrorCategory(ev(status: 503, replayed: true)),
          SyncErrorCategory.serverErrorReplayed);
    });

    test('transport evidence outranks a meaningless status code', () {
      expect(classifySyncErrorCategory(ev(kind: ApiFailureKind.timeout)),
          SyncErrorCategory.timeout);
      expect(classifySyncErrorCategory(ev(kind: ApiFailureKind.transport)),
          SyncErrorCategory.networkUnavailable);
      expect(classifySyncErrorCategory(ev(status: 0)),
          SyncErrorCategory.networkUnavailable);
    });

    test('auth and scope are never flattened into one another', () {
      expect(
          classifySyncErrorCategory(
              ev(status: 401, refresh: AuthRefreshOutcome.rejected)),
          SyncErrorCategory.authExpired);
      expect(
          classifySyncErrorCategory(
              ev(status: 401, refresh: AuthRefreshOutcome.scopeChanged)),
          SyncErrorCategory.authScopeChanged);
      expect(classifySyncErrorCategory(ev(status: 403)),
          SyncErrorCategory.permissionDenied);
    });

    test('rate limiting and validation keep their own categories', () {
      expect(classifySyncErrorCategory(ev(status: 429)),
          SyncErrorCategory.rateLimited);
      expect(classifySyncErrorCategory(ev(status: 422)),
          SyncErrorCategory.validationError);
      expect(classifySyncErrorCategory(ev(status: 400)),
          SyncErrorCategory.validationError);
    });

    test('every category round-trips through its storage value', () {
      for (final category in SyncErrorCategory.values) {
        expect(SyncErrorCategory.fromStorage(category.storageValue), category);
      }
      expect(SyncErrorCategory.fromStorage('not-a-category'),
          SyncErrorCategory.unknown);
      expect(SyncErrorCategory.fromStorage(null), SyncErrorCategory.unknown);
    });
  });

  group('retry decisions explain themselves', () {
    test('every OutboxDecision maps to a recorded reason', () {
      for (final decision in OutboxDecision.values) {
        final described = describeRetryDecision(
          decision: decision,
          category: SyncErrorCategory.unknown,
        );
        expect(described, isNot(SyncRetryDecision.pending),
            reason: '$decision must record why it stopped or continued');
      }
    });

    test('a server-dictated delay is distinguishable from the local ladder', () {
      expect(
        describeRetryDecision(
          decision: OutboxDecision.retryable,
          category: SyncErrorCategory.rateLimited,
          serverDictatedDelay: true,
        ),
        SyncRetryDecision.retryAfterServerDelay,
      );
      expect(
        describeRetryDecision(
          decision: OutboxDecision.retryable,
          category: SyncErrorCategory.timeout,
        ),
        SyncRetryDecision.retryScheduled,
      );
    });

    test('an exhausted budget is distinguishable from a rejection', () {
      expect(
        describeRetryDecision(
          decision: OutboxDecision.needsAttention,
          category: SyncErrorCategory.unknown,
          unknownBudgetExhausted: true,
        ),
        SyncRetryDecision.retryLimitReached,
      );
      expect(
        describeRetryDecision(
          decision: OutboxDecision.needsAttention,
          category: SyncErrorCategory.workflowRejected,
        ),
        SyncRetryDecision.userActionRequired,
      );
    });

    test('every decision round-trips through its storage value', () {
      for (final decision in SyncRetryDecision.values) {
        expect(SyncRetryDecision.fromStorage(decision.storageValue), decision);
      }
      expect(SyncRetryDecision.fromStorage('nonsense'),
          SyncRetryDecision.pending);
    });
  });

  group('lineage answers the S1 question', () {
    test('3 failures then a success is ONE operation with FOUR attempts', () {
      final lineage = SyncOperationLineage(
        clientOpId: 'op-8f21',
        domain: 'pending_attendance',
        attempts: [
          attempt(
              number: 1,
              category: SyncErrorCategory.timeout,
              decision: SyncRetryDecision.retryScheduled),
          attempt(
              number: 2,
              category: SyncErrorCategory.serviceUnavailable,
              decision: SyncRetryDecision.retryScheduled,
              status: 503),
          attempt(
              number: 3,
              category: SyncErrorCategory.serviceUnavailable,
              decision: SyncRetryDecision.retryScheduled,
              status: 503),
          attempt(
              number: 4,
              category: SyncErrorCategory.none,
              decision: SyncRetryDecision.completed,
              status: 200),
        ],
      );

      expect(lineage.attemptCount, 4);
      expect(lineage.retryCount, 3);
      expect(lineage.failedAttemptCount, 3);
      expect(lineage.succeeded, isTrue);
      expect(lineage.outcome, SyncOperationOutcome.succeeded);

      // The whole point: this must NOT read as four separate operations.
      expect(lineage.summary, contains('4 attempt(s)'));
      expect(lineage.summary, contains('3 retry(ies)'));
      expect(lineage.summary, contains('succeeded'));
    });

    test('a later success never erases the earlier failures', () {
      final lineage = SyncOperationLineage(
        clientOpId: 'op-8f21',
        domain: 'pending_attendance',
        attempts: [
          attempt(
              number: 1,
              category: SyncErrorCategory.timeout,
              decision: SyncRetryDecision.retryScheduled),
          attempt(
              number: 2,
              category: SyncErrorCategory.none,
              decision: SyncRetryDecision.completed,
              status: 200),
        ],
      );
      expect(lineage.attempts.first.category, SyncErrorCategory.timeout);
      expect(lineage.failedAttemptCount, 1);
    });

    test('an idempotent replay counts as delivered, not as a failure', () {
      final lineage = SyncOperationLineage(
        clientOpId: 'op-8f21',
        domain: 'pending_attendance',
        attempts: [
          attempt(
              number: 1,
              category: SyncErrorCategory.timeout,
              decision: SyncRetryDecision.retryScheduled),
          attempt(
              number: 2,
              category: SyncErrorCategory.idempotencyReplay,
              decision: SyncRetryDecision.completed,
              status: 200),
        ],
      );
      expect(lineage.succeeded, isTrue);
      expect(lineage.failedAttemptCount, 1,
          reason: 'the replay itself is not a failed attempt');
      expect(lineage.attempts.last.provesEarlierDelivery, isTrue);
    });

    test('an interrupted attempt is waiting, never succeeded', () {
      final lineage = SyncOperationLineage(
        clientOpId: 'op-8f21',
        domain: 'pending_attendance',
        attempts: [
          attempt(
              number: 1,
              category: SyncErrorCategory.unknown,
              decision: SyncRetryDecision.interrupted),
        ],
      );
      expect(lineage.succeeded, isFalse);
      expect(lineage.outcome, SyncOperationOutcome.waitingRetry);
    });

    test('an open attempt reads as in progress, never as success', () {
      final lineage = SyncOperationLineage(
        clientOpId: 'op-8f21',
        domain: 'pending_attendance',
        attempts: [
          attempt(
              number: 1,
              category: SyncErrorCategory.unknown,
              decision: SyncRetryDecision.pending,
              open: true),
        ],
      );
      expect(lineage.lastAttempt!.isOpen, isTrue);
      expect(lineage.succeeded, isFalse);
      expect(lineage.outcome, SyncOperationOutcome.inProgress);
    });

    test('a paused operation is not reported as needing attention', () {
      final lineage = SyncOperationLineage(
        clientOpId: 'op-8f21',
        domain: 'pending_attendance',
        attempts: [
          attempt(
              number: 1,
              category: SyncErrorCategory.authExpired,
              decision: SyncRetryDecision.authRefreshRequired,
              status: 401),
        ],
      );
      expect(lineage.outcome, SyncOperationOutcome.paused);
    });
  });

  group('pass summary removes the pre-S1 ambiguity', () {
    test('one operation retried three times is not four operations', () {
      const summary = SyncPassSummary(
        operationsAttempted: 1,
        attemptsMade: 4,
        operationsSucceeded: 1,
      );
      expect(summary.retriesMade, 3);
      final data = summary.toTelemetryData();
      expect(data['operations'], 1);
      expect(data['attempts'], 4);
      expect(data['retries'], 3);
      expect(data['succeeded'], 1);
    });

    test('nine successes and one failure in one pass stay separable', () {
      const summary = SyncPassSummary(
        operationsAttempted: 10,
        attemptsMade: 10,
        operationsSucceeded: 9,
        operationsWaitingRetry: 1,
      );
      final data = summary.toTelemetryData();
      expect(data['operations'], 10);
      expect(data['succeeded'], 9);
      expect(data['waiting_retry'], 1);
      expect(summary.retriesMade, 0,
          reason: 'ten first attempts contain no retries');
    });

    test('merging kinds accumulates every counter', () {
      const a = SyncPassSummary(
          operationsAttempted: 1, attemptsMade: 2, operationsSucceeded: 1);
      const b = SyncPassSummary(
          operationsAttempted: 2,
          attemptsMade: 3,
          operationsWaitingRetry: 1,
          operationsNeedingAttention: 1);
      final merged = a.merge(b);
      expect(merged.operationsAttempted, 3);
      expect(merged.attemptsMade, 5);
      expect(merged.operationsSucceeded, 1);
      expect(merged.operationsWaitingRetry, 1);
      expect(merged.operationsNeedingAttention, 1);
      expect(merged.retriesMade, 2);
    });

    test('an empty pass emits nothing', () {
      expect(const SyncPassSummary().isEmpty, isTrue);
      expect(const SyncPassSummary(operationsAttempted: 1).isEmpty, isFalse);
    });

    test('the telemetry payload carries counts only — never identifiers', () {
      const summary = SyncPassSummary(operationsAttempted: 1, attemptsMade: 2);
      final data = summary.toTelemetryData();
      for (final value in data.values) {
        expect(value, isA<int>(),
            reason: 'a non-numeric field could leak operation or user data');
      }
    });
  });

  group('attempt rows parse defensively', () {
    test('a row with unknown categories degrades instead of throwing', () {
      final record = SyncAttemptRecord.fromRow(const {
        'client_op_id': 'op-1',
        'attempt_number': 2,
        'attempt_uid': 'att_x',
        'domain': 'pending_grades',
        'started_at': '2026-03-01T10:14:02.000Z',
        'finished_at': null,
        'error_category': 'SOMETHING_NEW',
        'retry_decision': 'SOMETHING_NEW',
      });
      expect(record.category, SyncErrorCategory.unknown);
      expect(record.decision, SyncRetryDecision.pending);
      expect(record.isOpen, isTrue);
    });
  });
}
