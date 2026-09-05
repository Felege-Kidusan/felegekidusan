import 'dart:collection';

import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/lyrics_search.dart';

/// P41 regression guard.
///
/// sqflite returns `QueryRow`, a READ-ONLY map: `row['x'] = y` throws
/// `Unsupported operation: read-only`. hymn_store used to write match
/// metadata directly onto rows returned by a query, so the very first
/// result of the very first search threw and the whole search was
/// abandoned — the UI simply kept showing the previous list.
///
/// These tests pin the rule: decorate by COPYING, never by mutating.
Map<String, dynamic> queryRow(Map<String, dynamic> m) =>
    UnmodifiableMapView<String, dynamic>(m);

/// Mirrors HymnStore._decorate.
Map<String, dynamic> decorate(Map<String, dynamic> row, SearchHit hit) => {
      ...row,
      'similarity': hit.score,
      'match_in': hit.field == MatchField.title ? 'title' : 'lyrics',
      'snippet': hit.snippet.text,
      'title_ranges': hit.titleRanges,
      'snippet_ranges': hit.snippet.ranges,
      'snippet_before': hit.snippet.ellipsisBefore,
      'snippet_after': hit.snippet.ellipsisAfter,
      'partial_match': hit.isPartial,
    };

void main() {
  final rows = [
    queryRow({'id': 1, 'title': 'ሃሌ ሃሌ ሉያ', 'lyrics': 'test search abebe'}),
    queryRow({'id': 2, 'title': 'ልዑል ውኑቱ', 'lyrics': 'የሚነኘል'}),
  ];

  group('P41 — search must not write into read-only query rows', () {
    test('the shipped bug really does throw (guard is meaningful)', () {
      expect(() => rows.first['similarity'] = 1.0, throwsUnsupportedError);
    });

    test('decorating a read-only row does not throw', () {
      final hit = LyricsSearch.rankAll(rows: rows, query: 'test').first;
      expect(() => decorate(rows.first, hit), returnsNormally);
    });

    test('a full search over read-only rows completes', () {
      final hits = LyricsSearch.rankAll(rows: rows, query: 'test');
      final out = <Map<String, dynamic>>[];
      for (final h in hits) {
        out.add(decorate(
            rows.firstWhere((r) => r['id'] == h.hymnId), h));
      }
      expect(out, hasLength(1));
      expect(out.first['id'], 1);
    });

    test('the decorated copy carries the match metadata', () {
      final hit = LyricsSearch.rankAll(rows: rows, query: 'test').first;
      final d = decorate(rows.first, hit);
      expect(d['similarity'], greaterThan(0));
      expect(d['snippet'], contains('test'));
      expect(d['snippet_ranges'], isNotEmpty);
      expect(d['match_in'], 'lyrics');
    });

    test('the source row is left untouched', () {
      final hit = LyricsSearch.rankAll(rows: rows, query: 'test').first;
      decorate(rows.first, hit);
      expect(rows.first.containsKey('similarity'), isFalse);
    });

    test('the decorated copy is itself writable', () {
      final hit = LyricsSearch.rankAll(rows: rows, query: 'test').first;
      final d = decorate(rows.first, hit);
      expect(() => d['anything'] = 1, returnsNormally);
    });
  });
}
