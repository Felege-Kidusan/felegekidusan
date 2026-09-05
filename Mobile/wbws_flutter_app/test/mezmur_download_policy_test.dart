import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/mezmur_download_policy.dart';

/// Regression guard for the Mezmur offline-download policy (P33).
///
/// These are the rules that cost a user real money or real files when
/// they go wrong — a bulk download that fires on a mobile bundle, an
/// eviction that deletes a hymn the user deliberately kept, a stale
/// audio file that plays forever because the freshness check said no.
/// All of them live in the pure policy module so they can be pinned
/// here without a device, a network or an audio engine.
void main() {
  group('canTransfer — the metered-data guard', () {
    test('Wi‑Fi-only blocks mobile data but allows Wi‑Fi', () {
      expect(
          MezmurDownloadPolicy.canTransfer(
              hasLink: true, isUnmetered: false, wifiOnly: true),
          isFalse);
      expect(
          MezmurDownloadPolicy.canTransfer(
              hasLink: true, isUnmetered: true, wifiOnly: true),
          isTrue);
    });

    test('opting into mobile data allows a metered link', () {
      expect(
          MezmurDownloadPolicy.canTransfer(
              hasLink: true, isUnmetered: false, wifiOnly: false),
          isTrue);
    });

    test('no radio never transfers, whatever the settings say', () {
      for (final wifiOnly in [true, false]) {
        expect(
            MezmurDownloadPolicy.canTransfer(
                hasLink: false, isUnmetered: false, wifiOnly: wifiOnly),
            isFalse);
      }
    });
  });

  group('queueStatus — why nothing is happening', () {
    test('an empty queue is idle, not "waiting"', () {
      expect(
          MezmurDownloadPolicy.queueStatus(
              queued: 0, hasLink: false, isUnmetered: false, wifiOnly: true),
          'idle');
    });

    test('no network outranks waiting-for-Wi‑Fi', () {
      expect(
          MezmurDownloadPolicy.queueStatus(
              queued: 3, hasLink: false, isUnmetered: false, wifiOnly: true),
          'no-network');
    });

    test('queued on mobile data with Wi‑Fi-only reports waiting-wifi', () {
      expect(
          MezmurDownloadPolicy.queueStatus(
              queued: 3, hasLink: true, isUnmetered: false, wifiOnly: true),
          'waiting-wifi');
    });

    test('queued on Wi‑Fi is running', () {
      expect(
          MezmurDownloadPolicy.queueStatus(
              queued: 3, hasLink: true, isUnmetered: true, wifiOnly: true),
          'running');
    });
  });

  group('isStale — do not play yesterday\'s audio', () {
    test('a replaced server object is stale', () {
      expect(
          MezmurDownloadPolicy.isStale(
              storedStamp: '2026-01-01 10:00:00',
              serverStamp: '2026-02-01 09:30:00'),
          isTrue);
    });

    test('an unchanged stamp is fresh', () {
      expect(
          MezmurDownloadPolicy.isStale(
              storedStamp: '2026-01-01 10:00:00',
              serverStamp: '2026-01-01 10:00:00'),
          isFalse);
    });

    test('both missing is NOT stale (no infinite re-download loop)', () {
      expect(MezmurDownloadPolicy.isStale(), isFalse);
      expect(
          MezmurDownloadPolicy.isStale(storedStamp: '', serverStamp: null),
          isFalse);
    });

    test('server gained a stamp the stored copy lacks → stale', () {
      expect(
          MezmurDownloadPolicy.isStale(
              storedStamp: null, serverStamp: '2026-02-01 09:30:00'),
          isTrue);
    });
  });

  group('retry + backoff', () {
    test('gives up only after the attempt budget', () {
      expect(MezmurDownloadPolicy.shouldRetry(attempts: 0), isTrue);
      expect(MezmurDownloadPolicy.shouldRetry(attempts: 3), isTrue);
      expect(MezmurDownloadPolicy.shouldRetry(attempts: 4), isFalse);
      expect(MezmurDownloadPolicy.shouldRetry(attempts: 99), isFalse);
    });

    test('backoff grows but stays bounded', () {
      expect(MezmurDownloadPolicy.backoff(0), const Duration(seconds: 2));
      expect(MezmurDownloadPolicy.backoff(2), const Duration(seconds: 8));
      // Capped: a flaky link must not park the queue for minutes.
      expect(MezmurDownloadPolicy.backoff(9), const Duration(seconds: 32));
    });
  });

  group('evictionPlan — never delete what the user chose', () {
    const mb = 1024 * 1024;

    test('under the cap evicts nothing', () {
      final rows = [
        const DownloadRowView(
            id: 1, bytes: 5 * mb, source: 'auto', lastPlayed: '2026-01-01'),
      ];
      expect(
          MezmurDownloadPolicy.evictionPlan(rows: rows, capBytes: 100 * mb),
          isEmpty);
    });

    test('cap of 0 means unlimited', () {
      final rows = [
        const DownloadRowView(id: 1, bytes: 900 * mb, source: 'auto'),
      ];
      expect(MezmurDownloadPolicy.evictionPlan(rows: rows, capBytes: 0),
          isEmpty);
    });

    test('evicts least-recently-played auto rows first, and only enough',
        () {
      final rows = [
        const DownloadRowView(
            id: 1, bytes: 10 * mb, source: 'auto', lastPlayed: '2026-03-01'),
        const DownloadRowView(
            id: 2, bytes: 10 * mb, source: 'auto', lastPlayed: '2026-01-01'),
        const DownloadRowView(
            id: 3, bytes: 10 * mb, source: 'auto', lastPlayed: '2026-02-01'),
      ];
      // 30 MB stored, cap 15 MB → must free 15 MB → two oldest go.
      final plan =
          MezmurDownloadPolicy.evictionPlan(rows: rows, capBytes: 15 * mb);
      expect(plan, [2, 3]);
    });

    test('user-pinned hymns are never evicted, even over the cap', () {
      final rows = [
        const DownloadRowView(
            id: 1, bytes: 40 * mb, source: 'user', lastPlayed: '2020-01-01'),
        const DownloadRowView(
            id: 2, bytes: 10 * mb, source: 'auto', lastPlayed: '2026-01-01'),
      ];
      final plan =
          MezmurDownloadPolicy.evictionPlan(rows: rows, capBytes: 20 * mb);
      // Only the auto row can go; the 40 MB user pin stays put even
      // though the library is still over the cap afterwards.
      expect(plan, [2]);
    });

    test('never-played rows sort before played ones', () {
      final rows = [
        const DownloadRowView(
            id: 1, bytes: 10 * mb, source: 'auto', lastPlayed: '2026-01-01'),
        const DownloadRowView(id: 2, bytes: 10 * mb, source: 'auto'),
      ];
      final plan =
          MezmurDownloadPolicy.evictionPlan(rows: rows, capBytes: 10 * mb);
      expect(plan, [2]);
    });
  });

  group('pendingWorkCount — honest bulk-download counts', () {
    test('skips lyrics-only hymns and ones already stored or queued', () {
      final n = MezmurDownloadPolicy.pendingWorkCount(
        audioStatuses: ['ready', 'none', 'ready', 'ready', 'ready'],
        currentStates: ['none', 'none', 'done', 'queued', 'failed'],
      );
      // ready+none(1) and ready+failed(1) are real work; 'none' status,
      // 'done' and 'queued' are not.
      expect(n, 2);
    });

    test('a shorter state list defaults the rest to not-downloaded', () {
      final n = MezmurDownloadPolicy.pendingWorkCount(
        audioStatuses: ['ready', 'ready', 'ready'],
        currentStates: ['done'],
      );
      expect(n, 2);
    });
  });
}
