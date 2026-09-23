import 'session_models.dart';

/// Transport/protocol evidence. HTTP status-specific policy remains in the
/// classifier rather than being hidden inside this enum.
enum ApiFailureKind {
  none,
  transport,
  timeout,
  protocol,
  authentication,
  authorizationScope,
  http,
  unknown,
}

enum OutboxDecision {
  accepted,
  retryable,
  needsAttention,
  pauseForAuthentication,
  pauseForAuthorizationScope,
  resolvedConflict,
  supersededSession,
  supersededLocal,
}

enum OutboxState {
  pending('pending'),
  inFlight('in_flight'),
  retryWait('retry_wait'),
  pausedAuth('paused_auth'),
  pausedScope('paused_scope'),
  blockedDependency('blocked_dependency'),
  needsAttention('needs_attention'),
  resolvedConflict('resolved_conflict'),
  synced('synced');

  final String storageValue;
  const OutboxState(this.storageValue);
}

/// Response facts consumed by the pure outbox decision policy.
final class OutboxResponseEvidence {
  final bool success;
  final int statusCode;
  final String? errorCode;
  final bool idempotencyReplayed;
  final bool hasCanonicalConflictItem;
  final ApiFailureKind failureKind;
  final AuthRefreshOutcome? refreshOutcome;
  final bool supersededLocal;
  final int automaticAttemptCount;

  const OutboxResponseEvidence({
    required this.success,
    required this.statusCode,
    this.errorCode,
    this.idempotencyReplayed = false,
    this.hasCanonicalConflictItem = false,
    this.failureKind = ApiFailureKind.none,
    this.refreshOutcome,
    this.supersededLocal = false,
    this.automaticAttemptCount = 0,
  });
}

const _definitiveAuthCodes = <String>{
  'INVALID_REFRESH_TOKEN',
  'REFRESH_EXPIRED',
  'REFRESH_REUSED',
  'REFRESH_REVOKED',
  'ACCOUNT_DISABLED',
  'ACCOUNT_REMOVED',
};

const _scopeCodes = <String>{
  'AUTH_SCOPE_CHANGED',
  'AUTH_SCOPE_REFRESH_REQUIRED',
};

const _terminalConflictCodes = <String>{
  'IDEMPOTENCY_CONFLICT',
  'ALREADY_SUBMITTED',
  'WORKFLOW_REJECTED',
};

/// Classifies evidence only. It never deletes or mutates a durable payload.
/// Settlement must separately compare the exact claimed operation identity.
OutboxDecision classifyOutboxResponse(
  OutboxResponseEvidence evidence, {
  int maxUnknownAutomaticAttempts = 5,
}) {
  if (evidence.refreshOutcome == AuthRefreshOutcome.superseded) {
    return OutboxDecision.supersededSession;
  }
  if (evidence.supersededLocal) return OutboxDecision.supersededLocal;
  if (evidence.refreshOutcome == AuthRefreshOutcome.scopeChanged ||
      evidence.failureKind == ApiFailureKind.authorizationScope ||
      _scopeCodes.contains(evidence.errorCode)) {
    return OutboxDecision.pauseForAuthorizationScope;
  }
  if (evidence.refreshOutcome == AuthRefreshOutcome.rejected ||
      evidence.failureKind == ApiFailureKind.authentication ||
      _definitiveAuthCodes.contains(evidence.errorCode)) {
    return OutboxDecision.pauseForAuthentication;
  }

  final status = evidence.statusCode;
  if (evidence.success && status >= 200 && status < 300) {
    if (evidence.failureKind == ApiFailureKind.protocol) {
      return _boundedUnknown(evidence, maxUnknownAutomaticAttempts);
    }
    return OutboxDecision.accepted;
  }

  if (evidence.failureKind == ApiFailureKind.transport ||
      evidence.failureKind == ApiFailureKind.timeout ||
      status == 0 ||
      status == 408 ||
      status == 425 ||
      status == 429) {
    return OutboxDecision.retryable;
  }

  if (status >= 500 && status <= 599) {
    return evidence.idempotencyReplayed
        ? OutboxDecision.needsAttention
        : OutboxDecision.retryable;
  }

  if (status == 409) {
    if (evidence.errorCode == 'IDEMPOTENCY_IN_PROGRESS') {
      return OutboxDecision.retryable;
    }
    if (evidence.errorCode == 'REVISION_CONFLICT' &&
        evidence.hasCanonicalConflictItem) {
      return OutboxDecision.resolvedConflict;
    }
    if (_terminalConflictCodes.contains(evidence.errorCode) ||
        evidence.errorCode == 'REVISION_CONFLICT') {
      return OutboxDecision.needsAttention;
    }
    return _boundedUnknown(evidence, maxUnknownAutomaticAttempts);
  }

  if (status == 403 ||
      status == 400 ||
      status == 404 ||
      status == 405 ||
      status == 410 ||
      status == 413 ||
      status == 415 ||
      status == 422) {
    return OutboxDecision.needsAttention;
  }

  if (evidence.failureKind == ApiFailureKind.protocol ||
      evidence.failureKind == ApiFailureKind.unknown ||
      status == 401) {
    return _boundedUnknown(evidence, maxUnknownAutomaticAttempts);
  }

  return _boundedUnknown(evidence, maxUnknownAutomaticAttempts);
}

OutboxDecision _boundedUnknown(
  OutboxResponseEvidence evidence,
  int maximum,
) {
  return evidence.automaticAttemptCount >= maximum
      ? OutboxDecision.needsAttention
      : OutboxDecision.retryable;
}
