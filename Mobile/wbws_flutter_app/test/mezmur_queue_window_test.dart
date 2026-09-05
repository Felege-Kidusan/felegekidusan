import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/mezmur_queue_window.dart';

/// P36: the sliding window of resolved audio sources.
void main() {
  // 8 hymns; rows 2 and 5 are lyrics-only.
  List<bool> catalog8() =>
      [true, true, false, true, true, false, true, true];

  group('plan — the window always covers the listener', () {
    test('includes the centre row when it is playable', () {
      final w = MezmurQueueWindow.plan(playable: catalog8(), centerRow: 3);
      expect(w, contains(3));
    });

    test('is returned in ascending catalog order', () {
      final w = MezmurQueueWindow.plan(playable: catalog8(), centerRow: 3);
      final sorted = [...w]..sort();
      expect(w, sorted);
    });

    test('never contains a lyrics-only row', () {
      final flags = catalog8();
      final w = MezmurQueueWindow.plan(playable: flags, centerRow: 3);
      for (final r in w) {
        expect(flags[r], isTrue, reason: 'row $r has no audio');
      }
    });

    test('skips lyrics-only rows instead of spending window slots', () {
      // From row 1, forward neighbours are 3, 4, 6 (2 and 5 are skipped).
      final w = MezmurQueueWindow.plan(
        playable: catalog8(),
        centerRow: 1,
        ahead: 3,
        behind: 0,
      );
      expect(w, [1, 3, 4, 6]);
    });

    test('honours the behind budget', () {
      final w = MezmurQueueWindow.plan(
        playable: catalog8(),
        centerRow: 6,
        ahead: 0,
        behind: 2,
      );
      expect(w, [3, 4, 6]);
    });
  });

  group('plan — bounds', () {
    test('does not wrap when loop is off', () {
      final w = MezmurQueueWindow.plan(
        playable: catalog8(),
        centerRow: 7,
        ahead: 3,
        behind: 0,
      );
      expect(w, [7]);
    });

    test('wraps to the start when loop is on', () {
      final w = MezmurQueueWindow.plan(
        playable: catalog8(),
        centerRow: 7,
        ahead: 2,
        behind: 0,
        loop: true,
      );
      expect(w, [0, 1, 7]);
    });

    test('wraps backwards when loop is on', () {
      final w = MezmurQueueWindow.plan(
        playable: catalog8(),
        centerRow: 0,
        ahead: 0,
        behind: 2,
        loop: true,
      );
      expect(w, [0, 6, 7]);
    });

    test('an empty catalog plans nothing', () {
      expect(
        MezmurQueueWindow.plan(playable: const [], centerRow: 0),
        isEmpty,
      );
    });

    test('a catalog with no audio plans nothing', () {
      expect(
        MezmurQueueWindow.plan(
            playable: const [false, false], centerRow: 0),
        isEmpty,
      );
    });

    test('a single playable hymn is just itself', () {
      expect(
        MezmurQueueWindow.plan(playable: const [true], centerRow: 0),
        [0],
      );
    });

    test('an out-of-range centre is clamped, not thrown', () {
      expect(
        MezmurQueueWindow.plan(playable: catalog8(), centerRow: 99),
        contains(7),
      );
    });

    test('a lyrics-only centre still plans its neighbours', () {
      // Row 2 has no audio; the window should still prepare around it so
      // pressing play after swiping onto a song works.
      final w = MezmurQueueWindow.plan(
        playable: catalog8(),
        centerRow: 2,
        ahead: 1,
        behind: 1,
      );
      expect(w, isNot(contains(2)));
      expect(w, [1, 3]);
    });

    test('a zero-width window still covers the centre', () {
      final w = MezmurQueueWindow.plan(
        playable: catalog8(),
        centerRow: 4,
        ahead: 0,
        behind: 0,
      );
      expect(w, [4]);
    });

    test('negative budgets are treated as zero, not as an error', () {
      final w = MezmurQueueWindow.plan(
        playable: catalog8(),
        centerRow: 4,
        ahead: -5,
        behind: -5,
      );
      expect(w, [4]);
    });
  });

  group('needsRefresh — slide before the edge is reached', () {
    test('refreshes when nothing is resolved yet', () {
      expect(
        MezmurQueueWindow.needsRefresh(
          resolvedRows: const [],
          currentRow: 0,
          playable: catalog8(),
        ),
        isTrue,
      );
    });

    test('refreshes when the current row is not backed by a source', () {
      expect(
        MezmurQueueWindow.needsRefresh(
          resolvedRows: const [0, 1],
          currentRow: 4,
          playable: catalog8(),
        ),
        isTrue,
      );
    });

    test('does not refresh while there is comfortable headroom', () {
      expect(
        MezmurQueueWindow.needsRefresh(
          resolvedRows: const [3, 4, 6, 7],
          currentRow: 3,
          playable: catalog8(),
        ),
        isFalse,
      );
    });

    test('refreshes once only one resolved row remains ahead', () {
      expect(
        MezmurQueueWindow.needsRefresh(
          resolvedRows: const [3, 4],
          currentRow: 4,
          playable: catalog8(),
        ),
        isTrue,
      );
    });

    test('does NOT refresh at the true end of the catalog', () {
      // Nothing further is playable, so there is nothing to prepare and
      // we must not spin re-requesting.
      expect(
        MezmurQueueWindow.needsRefresh(
          resolvedRows: const [6, 7],
          currentRow: 7,
          playable: catalog8(),
        ),
        isFalse,
      );
    });

    test('trailing lyrics-only rows do not trigger a pointless refresh', () {
      // Row 3 is last playable; rows 4-5 have no audio.
      const flags = [true, true, true, true, false, false];
      expect(
        MezmurQueueWindow.needsRefresh(
          resolvedRows: const [2, 3],
          currentRow: 3,
          playable: flags,
        ),
        isFalse,
      );
    });
  });
}
