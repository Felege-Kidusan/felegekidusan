import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/screens/reviews/review_inbox_screen.dart';
import 'package:fkss_app/widgets/paged_list_footer.dart';

/// Phase 3 paging (APK 1.7.3+40): one footer for every capped list,
/// the review inbox parses every server shape (mezmur sends items),
/// and the capped screens ask the server for the next page.
void main() {
  Widget host(Widget child) =>
      MaterialApp(home: Scaffold(body: SingleChildScrollView(child: child)));

  group('PagedListFooter', () {
    test('label', () {
      expect(PagedListFooter.label(50, 440, 'members'), 'Showing 50 of 440 members');
      expect(PagedListFooter.label(20, null, 'members'), 'Showing 20 members');
      // A cache holding extra rows never shows "60 of 50".
      expect(PagedListFooter.label(60, 50, 'packets'), 'Showing 50 of 50 packets');
    });

    testWidgets('Load more (N left) calls back', (t) async {
      var taps = 0;
      await t.pumpWidget(host(PagedListFooter(
          shown: 50, total: 440, noun: 'members', onLoadMore: () => taps++)));
      expect(find.text('Showing 50 of 440 members'), findsOneWidget);
      expect(find.text('Load more (390 left)'), findsOneWidget);
      await t.tap(find.byKey(const ValueKey('paged-list-load-more')));
      expect(taps, 1);
    });

    testWidgets('loading disables the button', (t) async {
      var taps = 0;
      await t.pumpWidget(host(PagedListFooter(
          shown: 50, total: 440, noun: 'members', loading: true, onLoadMore: () => taps++)));
      expect(find.text('Loading…'), findsOneWidget);
      await t.tap(find.byKey(const ValueKey('paged-list-load-more')));
      expect(taps, 0);
    });

    testWidgets('everything shown -> tick, no button', (t) async {
      await t.pumpWidget(host(PagedListFooter(
          shown: 40, total: 40, noun: 'warnings', onLoadMore: () {})));
      expect(find.text('✓ All 40 shown'), findsOneWidget);
      expect(find.byKey(const ValueKey('paged-list-load-more')), findsNothing);
    });

    testWidgets('unknown total but more pages -> plain Load more', (t) async {
      await t.pumpWidget(host(PagedListFooter(
          shown: 20, total: null, noun: 'members', hasMore: true, onLoadMore: () {})));
      expect(find.text('Load more'), findsOneWidget);
    });
  });

  group('parseReviewPage', () {
    test('edu with_total shape', () {
      final p = parseReviewPage({
        'submissions': [
          {'id': 1},
          {'id': 2}
        ],
        'total': 70,
        'page': 2,
        'total_pages': 2,
        'stats': {},
      });
      expect(p.rows.length, 2);
      expect(p.total, 70);
      expect(p.page, 2);
      expect(p.totalPages, 2);
    });

    test('mezmur shape: data.items (was read as empty)', () {
      final p = parseReviewPage({
        'items': [
          {'id': 9}
        ],
        'total': '51',
        'page': 1,
        'total_pages': 2,
      });
      expect(p.rows.single['id'], 9);
      expect(p.total, 51);
      expect(p.totalPages, 2);
    });

    test('old nested shape: submissions.items', () {
      final p = parseReviewPage({
        'submissions': {
          'items': [
            {'id': 3}
          ],
          'total': 1,
        },
      });
      expect(p.rows.single['id'], 3);
      expect(p.total, 1);
    });

    test('old server without totals -> null totals', () {
      final p = parseReviewPage({
        'submissions': [
          {'id': 1}
        ]
      });
      expect(p.total, isNull);
      expect(p.totalPages, isNull);
      expect(p.page, 1);
    });

    test('garbage -> empty', () {
      final p = parseReviewPage({'submissions': 'x'});
      expect(p.rows, isEmpty);
    });
  });

  group('source pins', () {
    String read(String p) => File(p).readAsStringSync();

    test('API asks for totals and next pages', () {
      final api = read('lib/services/api_service.dart');
      expect(api, contains("'with_total': '1'"));
      expect(api, contains("'offset': '\$offset'"));
    });

    test('capped screens use the shared footer', () {
      for (final f in [
        'lib/screens/members/member_list_screen.dart',
        'lib/screens/reviews/review_inbox_screen.dart',
        'lib/screens/attendance_warnings/attendance_warnings_screen.dart',
        'lib/screens/edu_dept/edu_enrollment_screen.dart',
      ]) {
        expect(read(f), contains('PagedListFooter('), reason: f);
      }
    });

    test('member list keeps fixed-extent rows', () {
      final s = read('lib/screens/members/member_list_screen.dart');
      expect(s, contains('SliverFixedExtentList'));
      expect(s, contains('itemExtent: kFastRowHeight'));
    });

    test('warnings Load more continues after the rows on screen', () {
      final s = read('lib/screens/attendance_warnings/attendance_warnings_screen.dart');
      expect(s, contains('offset: _items.length'));
    });

    test('review Load more merges into the offline cache', () {
      final s = read('lib/screens/reviews/review_inbox_screen.dart');
      expect(s, contains('Future<void> _loadMore()'));
      expect(s, contains('await _db.cacheReviewPackets(widget.dept, pg.rows)'));
    });
  });
}
