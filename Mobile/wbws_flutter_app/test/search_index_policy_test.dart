import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/search_index_policy.dart';

/// P38: the rules that keep the search index correct forever, rather
/// than only at one migration boundary.
void main() {
  group('decide — analyzer versioning', () {
    test('a matching stamp needs only incremental work', () {
      expect(
        SearchIndexPolicy.decide(
            const IndexState(stampedVersion: 2), currentVersion: 2),
        IndexAction.incremental,
      );
    });

    test('AN OLDER STAMP FORCES A REBUILD — the whole point', () {
      // Words indexed by analyzer v1 were never homophone-folded, so a
      // v2 query can never match them.
      expect(
        SearchIndexPolicy.decide(
            const IndexState(stampedVersion: 1), currentVersion: 2),
        IndexAction.fullRebuild,
      );
    });

    test('a NEWER stamp also rebuilds (downgrade / rollback)', () {
      expect(
        SearchIndexPolicy.decide(
            const IndexState(stampedVersion: 3), currentVersion: 2),
        IndexAction.fullRebuild,
      );
    });

    test('never stamped rebuilds — fresh install or pre-versioning', () {
      expect(
        SearchIndexPolicy.decide(const IndexState()),
        IndexAction.fullRebuild,
      );
    });

    test('an interrupted rebuild is retried even if the stamp matches', () {
      // A half-written index is a mixture of two analyzers and cannot be
      // trusted, whatever the stamp says.
      expect(
        SearchIndexPolicy.decide(
          const IndexState(stampedVersion: 2, rebuildInProgress: true),
          currentVersion: 2,
        ),
        IndexAction.fullRebuild,
      );
    });

    test('a clean, current index with a backlog stays incremental', () {
      expect(
        SearchIndexPolicy.decide(
          const IndexState(stampedVersion: 2, dirtyCount: 40),
          currentVersion: 2,
        ),
        IndexAction.incremental,
      );
    });

    test('the shipped constant matches what the code indexes with', () {
      // Guards against bumping the analyzer without bumping the version,
      // which is the one way to reintroduce silent staleness.
      expect(kAnalyzerVersion, 2);
    });
  });

  group('mayRebuildNow — never fight the user', () {
    test('rebuilds when idle in the foreground', () {
      expect(
        SearchIndexPolicy.mayRebuildNow(
            appIsForeground: true, userIsSearching: false),
        isTrue,
      );
    });

    test('defers while the user is actively searching', () {
      expect(
        SearchIndexPolicy.mayRebuildNow(
            appIsForeground: true, userIsSearching: true),
        isFalse,
      );
    });

    test('defers in the background', () {
      expect(
        SearchIndexPolicy.mayRebuildNow(
            appIsForeground: false, userIsSearching: false),
        isFalse,
      );
    });
  });

  group('batches — bounded work', () {
    test('splits evenly', () {
      expect(SearchIndexPolicy.batches([1, 2, 3, 4], size: 2), [
        [1, 2],
        [3, 4]
      ]);
    });

    test('the trailing partial batch is kept', () {
      expect(SearchIndexPolicy.batches([1, 2, 3], size: 2), [
        [1, 2],
        [3]
      ]);
    });

    test('an empty input yields no batches, not one empty batch', () {
      expect(SearchIndexPolicy.batches([]), isEmpty);
    });

    test('a batch larger than the input is a single batch', () {
      expect(SearchIndexPolicy.batches([1, 2], size: 99), [
        [1, 2]
      ]);
    });

    test('a zero or negative size degrades to 1 instead of looping', () {
      expect(SearchIndexPolicy.batches([1, 2], size: 0), [
        [1],
        [2]
      ]);
      expect(SearchIndexPolicy.batches([1, 2], size: -5), [
        [1],
        [2]
      ]);
    });

    test('every element survives exactly once', () {
      final ids = List<int>.generate(137, (i) => i + 1);
      final flat = SearchIndexPolicy.batches(ids, size: 20)
          .expand((b) => b)
          .toList();
      expect(flat, ids);
    });
  });

  group('needsReindex — do not rewrite the index for nothing', () {
    test('a changed title needs reindexing', () {
      expect(
        SearchIndexPolicy.needsReindex(
            oldTitle: 'a', oldLyrics: 'x', newTitle: 'b', newLyrics: 'x'),
        isTrue,
      );
    });

    test('changed lyrics need reindexing', () {
      expect(
        SearchIndexPolicy.needsReindex(
            oldTitle: 'a', oldLyrics: 'x', newTitle: 'a', newLyrics: 'y'),
        isTrue,
      );
    });

    test('identical searchable text does NOT', () {
      // A play-count or cover-image sync must not cost an index rewrite.
      expect(
        SearchIndexPolicy.needsReindex(
            oldTitle: 'a', oldLyrics: 'x', newTitle: 'a', newLyrics: 'x'),
        isFalse,
      );
    });

    test('lyrics arriving for the first time need reindexing', () {
      // The lazy-blob case: this is the moment a hymn becomes searchable
      // by its body at all.
      expect(
        SearchIndexPolicy.needsReindex(
            oldTitle: 'a', oldLyrics: '', newTitle: 'a', newLyrics: 'verse'),
        isTrue,
      );
    });
  });
}
