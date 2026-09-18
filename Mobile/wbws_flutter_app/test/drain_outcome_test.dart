import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/sync_service.dart';

/// F8 (critical data-loss fix) — pins for the legacy-outbox drain
/// classifier. The old heuristic treated EVERY 409 as sync success,
/// so a teacher's offline attendance/grades were silently discarded
/// and reported synced when Education/admin had submitted first.
///
/// Directive scenarios (§12) mapped here:
///  1. normal success          → accepted
///  2. idempotent replay       → accepted (replay returns 200 + body)
///  3. workflow rejection      → rejected (NOT synced, kept, surfaced)
///  4/5. teacher attendance + grades scenarios = the ALREADY_SUBMITTED
///     pins below
///  6. app restart preserves rejected → SyncStatus carries `rejected`
///     from the DB (pinned by pytest on local_db.dart)
///  7. response-loss retry     → replay pin (accepted)
///  8. no false success        → every 409 shape lands on
///     rejected or transient — NEVER accepted
void main() {
  ApiResponse res(int status,
      {bool success = false, String? message, dynamic data}) {
    return ApiResponse(
      success: success,
      message: message,
      data: data,
      statusCode: status,
    );
  }

  group('1+2+7. success and replay are accepted', () {
    test('plain 200 success', () {
      final r = res(200, success: true, data: {'ok': 1});
      expect(classifyDrainResponse(r), DrainOutcome.accepted);
    });

    test('idempotent replay: original 200 + body (response-loss retry)',
        () {
      // middleware apiIdempotencyBegin 'replay' returns the ORIGINAL
      // status (200) + original body + Idempotency-Replayed header —
      // the retry of a lost response lands here and must stay synced.
      final r = res(200, success: true, data: {
        'status': 'success',
        'message': 'Attendance saved',
        'data': {'id': 7},
      });
      expect(classifyDrainResponse(r), DrainOutcome.accepted);
    });
  });

  group('3+4+5. workflow rejections are rejected, never synced', () {
    test('attendance day already submitted by Education (409)', () {
      final r = res(409,
          message: "This day's attendance is already submitted. "
              'Only Education can change it.',
          data: {
            'status': 'error',
            'message': "This day's attendance is already submitted.",
            'code': 'ALREADY_SUBMITTED',
          });
      expect(classifyDrainResponse(r), DrainOutcome.rejected);
    });

    test('grades test already submitted (409)', () {
      final r = res(409,
          message: 'This test is already submitted. '
              'Only Education can change scores now.',
          data: {
            'status': 'error',
            'code': 'ALREADY_SUBMITTED',
          });
      expect(classifyDrainResponse(r), DrainOutcome.rejected);
    });

    test('DomainException business rule (409 WORKFLOW_REJECTED)', () {
      final r = res(409,
          message: 'Cannot submit: roster was locked by the registrar.',
          data: {
            'status': 'error',
            'code': 'WORKFLOW_REJECTED',
          });
      expect(classifyDrainResponse(r), DrainOutcome.rejected);
    });

    test('idempotency key reused with a different body (409)', () {
      final r = res(409,
          message: 'This idempotency key was already used with a '
              'different request.',
          data: {'code': 'IDEMPOTENCY_CONFLICT'});
      expect(classifyDrainResponse(r), DrainOutcome.rejected);
    });

    test('definite protocol refusals (400/403/404/422)', () {
      for (final s in [400, 403, 404, 422]) {
        expect(classifyDrainResponse(res(s)), DrainOutcome.rejected,
            reason: 'status $s must be rejected, not retried forever');
      }
    });
  });

  group('transient: retried later, data kept, no false success', () {
    test('network error', () {
      final r = ApiResponse(
          success: false,
          message: 'No network',
          statusCode: 0,
          isNetworkError: true);
      expect(classifyDrainResponse(r), DrainOutcome.transient);
    });

    test('still-processing 409 + Retry-After', () {
      final r = res(409,
          message: 'A request with this idempotency key is still '
              'processing.',
          data: {'code': 'IDEMPOTENCY_IN_PROGRESS'});
      expect(classifyDrainResponse(r), DrainOutcome.transient);
    });

    test('auth / timeout / rate-limit / server errors', () {
      for (final s in [401, 408, 429, 500, 503]) {
        expect(classifyDrainResponse(res(s)), DrainOutcome.transient,
            reason: 'status $s must be retried, never destroyed');
      }
    });

    test('unknown 409 (old server without codes) stays transient — '
        'never guessed into success or rejection', () {
      final r = res(409, message: 'This day’s attendance is already '
          'submitted. Only Education can change it.');
      expect(classifyDrainResponse(r), DrainOutcome.transient);
    });
  });

  group('8. no false success — the F8 invariant', () {
    test('NO non-success response is ever accepted', () {
      final shapes = <ApiResponse>[
        res(409, data: {'code': 'ALREADY_SUBMITTED'}),
        res(409, data: {'code': 'WORKFLOW_REJECTED'}),
        res(409, data: {'code': 'IDEMPOTENCY_CONFLICT'}),
        res(409, data: {'code': 'IDEMPOTENCY_IN_PROGRESS'}),
        res(409, message: 'already submitted'), // legacy message sniff bait
        res(400),
        res(403),
        res(404),
        res(422),
        res(401),
        res(500),
        res(503),
        res(0, success: false),
      ];
      for (final r in shapes) {
        expect(classifyDrainResponse(r), isNot(DrainOutcome.accepted),
            reason:
                'status ${r.statusCode} must never report sync success');
      }
    });

    test('SyncStatus counts rejected batches and says so honestly', () {
      final s = SyncStatus(
          pendingAttendance: 2,
          pendingGrades: 0,
          pendingMezmur: 0,
          pendingHr: 0,
          pendingHymns: 0,
          rejected: 2,
          syncing: false);
      expect(s.needsAttention, isTrue);
      expect(s.totalPending, 2); // still "not yet sent" — the truth
      expect(s.breakdown, contains('need attention'));
      expect(SyncStatus(
              pendingAttendance: 0,
              pendingGrades: 0,
              pendingMezmur: 0,
              pendingHr: 0,
              pendingHymns: 0,
              rejected: 0,
              syncing: false)
          .breakdown, 'All synced');
    });
  });
}
