import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/taxonomy_reconcile.dart';

/// Regression guard for the taxonomy sync sweep.
///
/// Background: categories/singers deleted in the web admin used to
/// linger on every phone forever, because the sync was purely additive
/// (insert-or-replace) and the endpoints send a full canonical list with
/// no tombstones. The fix deletes local rows absent from that list —
/// which means this code can DESTROY user data if its guards regress,
/// so they are pinned here.
void main() {
  group('staleIds — the reported bug', () {
    test('a category deleted on the server is removed locally', () {
      expect(
        TaxonomyReconcile.staleIds(
            localIds: [1, 2, 3], serverIds: {1, 3}),
        [2],
      );
    });

    test('deleting every category server-side clears them all', () {
      expect(
        TaxonomyReconcile.staleIds(localIds: [1, 2, 9], serverIds: {}),
        [1, 2, 9],
      );
    });

    test('an unchanged list sweeps nothing', () {
      expect(
        TaxonomyReconcile.staleIds(localIds: [1, 2], serverIds: {1, 2}),
        isEmpty,
      );
    });

    test('newly added server rows are not mistaken for stale', () {
      expect(
        TaxonomyReconcile.staleIds(localIds: [1], serverIds: {1, 2, 3}),
        isEmpty,
      );
    });
  });

  group('staleIds — data-safety guards', () {
    test('offline-created rows (negative ids) are NEVER swept', () {
      // These were never sent to the server, so their absence from the
      // canonical list means nothing. Sweeping them would delete work
      // the user created in airplane mode.
      expect(
        TaxonomyReconcile.staleIds(
            localIds: [-5, -2, 4], serverIds: {4}),
        isEmpty,
      );
    });

    test('rows with a queued local edit are protected', () {
      expect(
        TaxonomyReconcile.staleIds(
            localIds: [7, 8], serverIds: {}, protectIds: {7}),
        [8],
      );
    });

    test('negative ids survive even when everything else is swept', () {
      expect(
        TaxonomyReconcile.staleIds(localIds: [-1, 5], serverIds: {}),
        [5],
      );
    });
  });

  group('mayReconcile — an empty list must be trustworthy', () {
    test('a successful response with a list may reconcile', () {
      expect(
        TaxonomyReconcile.mayReconcile(
            requestSucceeded: true, payloadIsList: true),
        isTrue,
      );
    });

    test('a FAILED request must never trigger a sweep', () {
      // Otherwise one flaky connection wipes the local taxonomy.
      expect(
        TaxonomyReconcile.mayReconcile(
            requestSucceeded: false, payloadIsList: true),
        isFalse,
      );
    });

    test('a malformed payload must never trigger a sweep', () {
      expect(
        TaxonomyReconcile.mayReconcile(
            requestSucceeded: true, payloadIsList: false),
        isFalse,
      );
    });
  });
}
