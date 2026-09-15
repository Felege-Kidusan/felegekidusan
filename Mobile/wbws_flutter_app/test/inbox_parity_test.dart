import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/api_service.dart';
import 'package:fkss_app/services/inbox_view_model.dart';

/// P74 Phase 3 — pins for the inbox UX: the ETag/304 poll state
/// machine, "Load older" cursor pagination, and the optimistic
/// mark-read rules. Every rule mirrors the web Communication Center
/// (admin/js/comm.js); drift between the two clients is the
/// regression this file exists to catch.
void main() {
  group('updateEtag (conditional-GET state machine)', () {
    test('a full 200 with an ETag replaces the stored tag', () {
      expect(updateEtag('"ncsum-old"', 200, '"ncsum-new"'), '"ncsum-new"');
      expect(updateEtag(null, 200, '"ncsum-1"'), '"ncsum-1"');
    });
    test('a 304 keeps the stored tag (idle poll = no-op)', () {
      expect(updateEtag('"ncsum-1"', 304, null), '"ncsum-1"');
      expect(updateEtag('"ncsum-1"', 304, '"ncsum-1"'), '"ncsum-1"');
    });
    test('errors and empty etags never clear or replace the tag', () {
      expect(updateEtag('"ncsum-1"', 500, null), '"ncsum-1"');
      expect(updateEtag('"ncsum-1"', 200, null), '"ncsum-1"');
      expect(updateEtag('"ncsum-1"', 200, ''), '"ncsum-1"');
      expect(updateEtag(null, 304, null), isNull);
    });
  });

  group('ifNoneMatchHeader', () {
    test('null until the first 200 stores a tag', () {
      expect(ifNoneMatchHeader(null), isNull);
      expect(ifNoneMatchHeader(''), isNull);
      expect(ifNoneMatchHeader('"ncsum-1"'), {'If-None-Match': '"ncsum-1"'});
    });
  });

  group('ApiResponse.notModified (plumbing pin)', () {
    test('304 is notModified even with an empty body', () {
      final res = ApiResponse(
          success: false, message: 'Not modified', statusCode: 304);
      expect(res.notModified, isTrue);
      expect(res.success, isFalse);
    });
    test('ordinary responses are never notModified', () {
      expect(
          ApiResponse(success: true, statusCode: 200).notModified, isFalse);
    });
  });

  group('mergeOlderRows (Load older)', () {
    test('appends the older page, de-duplicated at the window edge', () {
      final current = <Map<String, dynamic>>[
        {'id': 105, 'title': 'a'},
        {'id': 104, 'title': 'b'},
      ];
      // Server overlap: the older page re-ships id 104.
      final older = <Map<String, dynamic>>[
        {'id': 104, 'title': 'b'},
        {'id': 103, 'title': 'c'},
        {'id': 102, 'title': 'd'},
      ];
      expect(
        mergeOlderRows(current, older).map((m) => m['id']).toList(),
        [105, 104, 103, 102],
      );
    });
    test('custom id field + empty page no-op', () {
      final current = <Map<String, dynamic>>[
        {'ann': 9, 'title': 'x'},
      ];
      expect(mergeOlderRows(current, [], idField: 'ann'), current);
      expect(
        mergeOlderRows(current, [
          {'ann': 8},
          {'ann': 9},
        ], idField: 'ann').map((m) => m['ann']).toList(),
        [9, 8],
      );
    });
  });

  group('nextCursor', () {
    test('int > 0 → value; 0 / null / absent → null', () {
      expect(nextCursor({'next_before': 42}, 'next_before'), 42);
      expect(nextCursor({'next_before': 0}, 'next_before'), isNull);
      expect(nextCursor({'next_before': null}, 'next_before'), isNull);
      expect(nextCursor({}, 'next_before'), isNull);
      expect(nextCursor(null, 'next_before'), isNull);
    });
    test('lenient string numbers', () {
      expect(nextCursor({'next_pin': '7'}, 'next_pin'), 7);
      expect(nextCursor({'next_pin': '0'}, 'next_pin'), isNull);
      expect(nextCursor({'next_pin': 'junk'}, 'next_pin'), isNull);
    });
  });

  group('hasMore', () {
    test('lenient bool / int / string reads', () {
      expect(hasMore({'has_more': true}), isTrue);
      expect(hasMore({'has_more': 1}), isTrue);
      expect(hasMore({'has_more': '1'}), isTrue);
      expect(hasMore({'has_more': false}), isFalse);
      expect(hasMore({}), isFalse);
      expect(hasMore(null), isFalse);
    });
  });

  group('applyReadOptimistic (web P73 Phase 4 / D7)', () {
    test('clears is_unread for the tapped row without mutating it', () {
      final rows = <Map<String, dynamic>>[
        {'id': 1, 'is_unread': 0},
        {'id': 2, 'is_unread': 1},
      ];
      final result = applyReadOptimistic(rows, 2);
      expect(result.changed, isTrue);
      expect(result.rows[1]['is_unread'], 0);
      expect(result.rows[0]['is_unread'], 0);
      // The ORIGINAL map is untouched — revert = don't adopt the copy.
      expect(rows[1]['is_unread'], 1);
    });
    test('already-read or missing rows are a guarded no-op', () {
      final rows = <Map<String, dynamic>>[
        {'id': 1, 'is_unread': 0},
      ];
      expect(applyReadOptimistic(rows, 1).changed, isFalse);
      expect(applyReadOptimistic(rows, 99).changed, isFalse);
      // Unchanged path: same element references, equal contents.
      final unchanged = applyReadOptimistic(rows, 1).rows;
      expect(unchanged, equals(rows));
      expect(identical(unchanged[0], rows[0]), isTrue);
    });
  });

  group('decrementCount (badge floor)', () {
    test('floors at zero, web Math.max(0, n-1)', () {
      expect(decrementCount(3), 2);
      expect(decrementCount(1), 0);
      expect(decrementCount(0), 0);
    });
  });

  group('memberTargetId (C1 deep-link target)', () {
    test('member target returns the id', () {
      expect(memberTargetId({'target': {'kind': 'member', 'id': 15}}), 15);
    });

    test('other kinds return null', () {
      expect(memberTargetId({'target': {'kind': 'task', 'id': 3}}), isNull);
    });

    test('missing / malformed target never throws and returns null', () {
      expect(memberTargetId({}), isNull);
      expect(memberTargetId({'target': null}), isNull);
      expect(memberTargetId({'target': 'member'}), isNull);
      expect(memberTargetId({'target': {'kind': 'member'}}), isNull);
      expect(memberTargetId({'target': {'kind': 'member', 'id': 0}}), isNull);
      expect(memberTargetId({'target': {'kind': 'member', 'id': 'x'}}), isNull);
    });
  });
}
