import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/outbox_policy.dart';
import 'package:fkss_app/services/session_models.dart';

void main() {
  OutboxResponseEvidence response(
    int status, {
    bool success = false,
    String? code,
    bool replayed = false,
    bool canonical = false,
    ApiFailureKind failureKind = ApiFailureKind.none,
    AuthRefreshOutcome? refreshOutcome,
    bool supersededLocal = false,
    int attempts = 0,
  }) {
    return OutboxResponseEvidence(
      success: success,
      statusCode: status,
      errorCode: code,
      idempotencyReplayed: replayed,
      hasCanonicalConflictItem: canonical,
      failureKind: failureKind,
      refreshOutcome: refreshOutcome,
      supersededLocal: supersededLocal,
      automaticAttemptCount: attempts,
    );
  }

  test('accepts only a valid success or original successful replay', () {
    expect(
      classifyOutboxResponse(response(200, success: true)),
      OutboxDecision.accepted,
    );
    expect(
      classifyOutboxResponse(response(201, success: true, replayed: true)),
      OutboxDecision.accepted,
    );
    expect(
      classifyOutboxResponse(response(
        200,
        success: true,
        failureKind: ApiFailureKind.protocol,
      )),
      OutboxDecision.retryable,
    );
  });

  test('transport, timeout, overload, and first 5xx are retryable', () {
    for (final evidence in [
      response(0, failureKind: ApiFailureKind.transport),
      response(0, failureKind: ApiFailureKind.timeout),
      response(408),
      response(425),
      response(429),
      response(500),
      response(503),
      response(409, code: 'IDEMPOTENCY_IN_PROGRESS'),
    ]) {
      expect(
        classifyOutboxResponse(evidence),
        OutboxDecision.retryable,
      );
    }
  });

  test('immutable replay and coded permanent failures need attention', () {
    for (final evidence in [
      response(500, replayed: true),
      response(409, code: 'IDEMPOTENCY_CONFLICT'),
      response(409, code: 'ALREADY_SUBMITTED'),
      response(409, code: 'WORKFLOW_REJECTED'),
      response(400),
      response(403),
      response(404),
      response(405),
      response(410),
      response(413),
      response(415),
      response(422),
    ]) {
      expect(
        classifyOutboxResponse(evidence),
        OutboxDecision.needsAttention,
      );
    }
  });

  test('revision resolution requires code and canonical server item', () {
    expect(
      classifyOutboxResponse(response(
        409,
        code: 'REVISION_CONFLICT',
        canonical: true,
      )),
      OutboxDecision.resolvedConflict,
    );
    expect(
      classifyOutboxResponse(response(409, code: 'REVISION_CONFLICT')),
      OutboxDecision.needsAttention,
    );
  });

  test('unknown and protocol evidence use a bounded retry', () {
    expect(
      classifyOutboxResponse(response(409, attempts: 4)),
      OutboxDecision.retryable,
    );
    expect(
      classifyOutboxResponse(response(409, attempts: 5)),
      OutboxDecision.needsAttention,
    );
    expect(
      classifyOutboxResponse(response(
        200,
        success: true,
        failureKind: ApiFailureKind.protocol,
        attempts: 5,
      )),
      OutboxDecision.needsAttention,
    );
  });

  test('auth, scope, and stale identities remain distinct', () {
    expect(
      classifyOutboxResponse(response(
        401,
        refreshOutcome: AuthRefreshOutcome.rejected,
      )),
      OutboxDecision.pauseForAuthentication,
    );
    expect(
      classifyOutboxResponse(response(401, code: 'REFRESH_REVOKED')),
      OutboxDecision.pauseForAuthentication,
    );
    expect(
      classifyOutboxResponse(response(
        401,
        refreshOutcome: AuthRefreshOutcome.scopeChanged,
      )),
      OutboxDecision.pauseForAuthorizationScope,
    );
    expect(
      classifyOutboxResponse(response(401, code: 'AUTH_SCOPE_CHANGED')),
      OutboxDecision.pauseForAuthorizationScope,
    );
    expect(
      classifyOutboxResponse(response(
        0,
        refreshOutcome: AuthRefreshOutcome.superseded,
      )),
      OutboxDecision.supersededSession,
    );
    expect(
      classifyOutboxResponse(response(0, supersededLocal: true)),
      OutboxDecision.supersededLocal,
    );
  });

  test('no non-success matrix entry is accepted', () {
    for (final status in [0, 400, 401, 403, 404, 408, 409, 422, 429, 500]) {
      expect(
        classifyOutboxResponse(response(status)),
        isNot(OutboxDecision.accepted),
      );
    }
  });
}
