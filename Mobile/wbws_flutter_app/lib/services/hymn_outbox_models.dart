final class HymnOutboxClaim {
  const HymnOutboxClaim({
    required this.rowId,
    required this.operation,
    required this.payloadJson,
    required this.clientOpId,
    required this.runtimeGeneration,
    required this.attemptCount,
    required this.claimedAt,
  });

  final int rowId;
  final String operation;
  final String payloadJson;
  final String clientOpId;
  final int runtimeGeneration;
  final int attemptCount;
  final DateTime claimedAt;
}

enum HymnSettlementKind {
  accepted,
  retryable,
  needsAttention,
  pausedAuthentication,
  pausedAuthorizationScope,
  resolvedConflict,
  blockedDependency,
}

final class HymnSettlement {
  const HymnSettlement({
    required this.kind,
    this.nextAttemptAt,
    this.failureCode,
    this.failureHttpStatus,
    this.failureMessage,
  });

  final HymnSettlementKind kind;
  final DateTime? nextAttemptAt;
  final String? failureCode;
  final int? failureHttpStatus;
  final String? failureMessage;
}

enum HymnSettlementResult { applied, supersededLocal, supersededSession }
