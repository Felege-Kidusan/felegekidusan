import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/outbox_policy.dart';

void main() {
  final now = DateTime.utc(2026, 9, 24, 12);

  test('durable retry ladder uses the reviewed caps', () {
    const expected = <int>[2, 5, 12, 30, 60, 120, 300, 900, 900];
    for (var index = 0; index < expected.length; index++) {
      final due = nextOutboxAttemptAt(
        attemptCount: index + 1,
        randomUnit: 1,
        now: now,
      );
      expect(due, now.add(Duration(seconds: expected[index])));
    }
  });

  test('full jitter stays positive and within the attempt cap', () {
    final earliest = nextOutboxAttemptAt(
      attemptCount: 1,
      randomUnit: 0,
      now: now,
    );
    final middle = nextOutboxAttemptAt(
      attemptCount: 3,
      randomUnit: 0.5,
      now: now,
    );
    expect(earliest, now.add(const Duration(milliseconds: 1)));
    expect(middle, now.add(const Duration(seconds: 6)));
  });

  test('Retry-After is mandatory and bounded to one hour', () {
    expect(
      nextOutboxAttemptAt(
        attemptCount: 1,
        retryAfterSeconds: 0,
        randomUnit: 0,
        now: now,
      ),
      now.add(const Duration(seconds: 1)),
    );
    expect(
      nextOutboxAttemptAt(
        attemptCount: 1,
        retryAfterSeconds: 7200,
        randomUnit: 0,
        now: now,
      ),
      now.add(const Duration(hours: 1)),
    );
  });
}
