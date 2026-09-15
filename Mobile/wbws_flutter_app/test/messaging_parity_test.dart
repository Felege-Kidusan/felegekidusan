import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/messaging_view_model.dart';

/// P74 Phase 2 — pins for the messaging UX view-model, the pure-Dart
/// port of the web Communication Center's rendering rules
/// (admin/js/comm.js). If one of these changes, mobile and web have
/// drifted apart — that is the regression this file exists to catch.
void main() {
  group('parseServerTime', () {
    test('parses the server format as LOCAL time (web Date.parse parity)', () {
      final t = parseServerTime('2026-09-13 14:05:00');
      expect(t, DateTime(2026, 9, 13, 14, 5));
    });
    test('rejects junk', () {
      expect(parseServerTime(''), isNull);
      expect(parseServerTime('not a date'), isNull);
    });
  });

  group('dayLabel (web dayLabel parity)', () {
    test('Today / Yesterday / absolute en-GB', () {
      final now = DateTime(2026, 9, 13, 15);
      expect(dayLabel('2026-09-13 08:00:00', now: now), 'Today');
      expect(dayLabel('2026-09-12 23:59:00', now: now), 'Yesterday');
      expect(dayLabel('2026-01-05 00:01:00', now: now), '5 Jan 2026');
      expect(dayLabel('2024-12-31 12:00:00', now: now), '31 Dec 2024');
    });
    test('junk → empty (no separator label)', () {
      expect(dayLabel('', now: DateTime(2026, 9, 13)), '');
    });
  });

  group('timeHM', () {
    test('24h clock, zero padded', () {
      expect(timeHM('2026-09-13 09:05:00'), '09:05');
      expect(timeHM('2026-09-13 23:59:00'), '23:59');
      expect(timeHM(''), '');
    });
  });

  group('startsNewDay', () {
    test('separator above the first message and at every day change', () {
      expect(startsNewDay('2026-09-13 10:00:00', null), isTrue);
      expect(startsNewDay('2026-09-13 11:00:00', '2026-09-13 10:00:00'),
          isFalse);
      expect(startsNewDay('2026-09-14 00:01:00', '2026-09-13 23:59:00'),
          isTrue);
    });
  });

  group('receiptFor (web watermark rule)', () {
    Map<String, dynamic> mine(int id) => {'id': id, 'mine': 1};
    test('others messages never show a receipt', () {
      expect(receiptFor({'id': 5, 'mine': 0}, 99), Receipt.none);
    });
    test('watermark 0 is falsy — nothing is Seen yet', () {
      expect(receiptFor(mine(1), 0), Receipt.sent);
    });
    test('id <= watermark → Seen, id > watermark → Sent', () {
      expect(receiptFor(mine(10), 10), Receipt.seen);
      expect(receiptFor(mine(9), 10), Receipt.seen);
      expect(receiptFor(mine(11), 10), Receipt.sent);
    });
    test('tombstones never show a receipt', () {
      expect(receiptFor({'id': 10, 'mine': 1, 'deleted': 1}, 99),
          Receipt.none);
    });
  });

  group('tombstones', () {
    test('deleted == 1, regardless of type', () {
      expect(isTombstone({'deleted': 1}), isTrue);
      expect(isTombstone({'deleted': '1'}), isTrue); // JSON ints stay ints,
      expect(isTombstone({'deleted': 0}), isFalse); // but be lenient
      expect(isTombstone({}), isFalse);
    });
  });

  group('optimistic local bubbles', () {
    test('pending bubble is mine, local, and carries the body', () {
      final b = pendingBubble(7, 'hello');
      expect(isLocalBubble(b), isTrue);
      expect(localTag(b), 7);
      expect(b[kLocalStatus], 'pending');
      expect(isMine(b), isTrue);
      expect(b['body'], 'hello');
    });
    test('failed keeps the tag and body, adds the reason', () {
      final f = failBubble(pendingBubble(7, 'hello'), 'Network error.');
      expect(localTag(f), 7);
      expect(f[kLocalStatus], 'failed');
      expect(f['_fail_reason'], 'Network error.');
      expect(f['body'], 'hello');
    });
  });

  group('mergeOlderPage (Load older)', () {
    test('prepends the page, dedupes the window edge, keeps locals last', () {
      final current = <Map<String, dynamic>>[
        {'id': 100, 'body': 'win100'},
        {'id': 101, 'body': 'win101'},
        pendingBubble(1, 'sending'),
      ];
      // Server overlap: the older page re-ships id 100.
      final older = [
        {'id': 98, 'body': 'old98'},
        {'id': 99, 'body': 'old99'},
        {'id': 100, 'body': 'win100'},
      ];
      final merged = mergeOlderPage(current, older);
      expect(
        merged.map((m) => m['id']).toList(),
        [98, 99, 100, 101, null],
      );
      expect(isLocalBubble(merged.last), isTrue);
    });
    test('empty page is a no-op', () {
      final current = <Map<String, dynamic>>[
        {'id': 5, 'body': 'x'},
      ];
      expect(mergeOlderPage(current, []), current);
    });
  });

  group('mergeFreshWindow (poll / post-send refresh)', () {
    test('updates in-window rows (edit + delete), inserts new ones', () {
      final current = <Map<String, dynamic>>[
        {'id': 10, 'body': 'a', 'mine': 1},
        {'id': 11, 'body': 'b', 'mine': 1, 'edited': 1},
        {'id': 12, 'body': 'c', 'mine': 0},
      ];
      final window = <Map<String, dynamic>>[
        {'id': 11, 'body': 'b (edited)', 'mine': 1, 'edited': 1},
        {'id': 12, 'body': '', 'mine': 0, 'deleted': 1},
        {'id': 13, 'body': 'new', 'mine': 0},
      ];
      final merged = mergeFreshWindow(current, window);
      expect(merged.map((m) => m['id']).toList(), [10, 11, 12, 13]);
      final byId = {for (final m in merged) (m['id'] as num).toInt(): m};
      expect(byId[11]!['body'], 'b (edited)');
      expect(isTombstone(byId[12]!), isTrue);
      expect(byId[13]!['body'], 'new');
    });

    test('keeps already-loaded OLDER pages (no collapse on refresh)', () {
      // Web parity note: the web collapses loaded history back to the
      // window on refresh; on mobile that would yank content out from
      // under the user's scroll position, so older pages are kept.
      final current = <Map<String, dynamic>>[
        {'id': 2, 'body': 'old'},
        {'id': 50, 'body': 'win'},
      ];
      final window = <Map<String, dynamic>>[
        {'id': 51, 'body': 'newest'},
      ];
      final merged = mergeFreshWindow(current, window);
      expect(merged.map((m) => m['id']).toList(), [2, 50, 51]);
    });

    test('local pending/failed bubbles survive the merge, stay last', () {
      final current = <Map<String, dynamic>>[
        {'id': 10, 'body': 'a'},
        failBubble(pendingBubble(3, 'retry me'), 'Network error.'),
        pendingBubble(4, 'sending'),
      ];
      final window = <Map<String, dynamic>>[
        {'id': 10, 'body': 'a'},
        {'id': 11, 'body': 'from server'},
      ];
      final merged = mergeFreshWindow(current, window);
      // server rows first (ascending), then the local bubbles, tag order
      expect(merged.map((m) => m['id'] ?? 'L${localTag(m)}').toList(),
          [10, 11, 'L3', 'L4']);
      expect(isLocalBubble(merged[2]), isTrue);
      expect(isLocalBubble(merged[3]), isTrue);
      expect(localTag(merged[2]), 3);
    });

    test('result stays id-ascending among server rows', () {
      final current = <Map<String, dynamic>>[
        {'id': 1, 'body': 'h1'},
        {'id': 2, 'body': 'h2'},
        {'id': 90, 'body': 'w90'},
      ];
      final window = <Map<String, dynamic>>[
        {'id': 91, 'body': 'n91'},
        {'id': 92, 'body': 'n92'},
      ];
      final merged = mergeFreshWindow(current, window);
      final ids = merged
          .where((m) => !isLocalBubble(m))
          .map((m) => (m['id'] as num).toInt())
          .toList();
      expect(ids, [1, 2, 90, 91, 92]);
    });
  });

  // ── P1 (UX audit): grouping (B4) + link segmentation (B1) ────────

  Map<String, dynamic> gmsg(int id, String at,
          {int mine = 0, int senderId = 7, int deleted = 0, int? local}) =>
      {
        'id': id,
        'created_at': at,
        'mine': mine,
        'sender_id': senderId,
        'sender_name': 'Sender $senderId',
        'sender_label': 'Teacher',
        'deleted': deleted,
        if (local != null) kLocalTag: local,
      };

  group('groupingFor (B4 message grouping)', () {
    test('empty list / out of range is standalone', () {
      const g = MessageGrouping(showHeader: true, showMeta: true, tightGap: false);
      expect(groupingFor([], 0).showHeader, g.showHeader);
      expect(groupingFor([gmsg(1, '2026-09-10 10:00:00')], -1).showMeta, true);
      expect(groupingFor([gmsg(1, '2026-09-10 10:00:00')], 5).showMeta, true);
    });

    test('single message shows header and meta', () {
      final msgs = [gmsg(1, '2026-09-10 10:00:00')];
      final g = groupingFor(msgs, 0);
      expect(g.showHeader, isTrue);
      expect(g.showMeta, isTrue);
      expect(g.tightGap, isFalse);
    });

    test('same-sender streak: header once, meta on last, tight inside', () {
      final msgs = [
        gmsg(1, '2026-09-10 10:00:00'),
        gmsg(2, '2026-09-10 10:02:00'),
        gmsg(3, '2026-09-10 10:04:00'),
      ];
      expect(groupingFor(msgs, 0).showHeader, isTrue);
      expect(groupingFor(msgs, 0).showMeta, isFalse);
      expect(groupingFor(msgs, 0).tightGap, isTrue);
      expect(groupingFor(msgs, 1).showHeader, isFalse);
      expect(groupingFor(msgs, 1).showMeta, isFalse);
      expect(groupingFor(msgs, 2).showHeader, isFalse);
      expect(groupingFor(msgs, 2).showMeta, isTrue);
      expect(groupingFor(msgs, 2).tightGap, isFalse);
    });

    test('exactly 5 minutes still groups; 5:01 does not', () {
      expect(
          groupingFor([
            gmsg(1, '2026-09-10 10:00:00'),
            gmsg(2, '2026-09-10 10:05:00'),
          ], 0).showMeta,
          isFalse);
      expect(
          groupingFor([
            gmsg(1, '2026-09-10 10:00:00'),
            gmsg(2, '2026-09-10 10:05:01'),
          ], 0).showMeta,
          isTrue);
    });

    test('midnight crossing splits (different day)', () {
      final msgs = [
        gmsg(1, '2026-09-10 23:59:00'),
        gmsg(2, '2026-09-11 00:01:00'),
      ];
      expect(groupingFor(msgs, 0).showMeta, isTrue);
      expect(groupingFor(msgs, 1).showHeader, isTrue);
    });

    test('sender change splits', () {
      final msgs = [
        gmsg(1, '2026-09-10 10:00:00', senderId: 7),
        gmsg(2, '2026-09-10 10:01:00', senderId: 8),
      ];
      expect(groupingFor(msgs, 0).showMeta, isTrue);
      expect(groupingFor(msgs, 1).showHeader, isTrue);
    });

    test('own streak groups too (mine flag)', () {
      final msgs = [
        gmsg(1, '2026-09-10 10:00:00', mine: 1),
        gmsg(2, '2026-09-10 10:01:00', mine: 1),
      ];
      expect(groupingFor(msgs, 0).showMeta, isFalse);
      expect(groupingFor(msgs, 1).showMeta, isTrue);
    });

    test('tombstone stands alone and breaks the streak', () {
      final msgs = [
        gmsg(1, '2026-09-10 10:00:00'),
        gmsg(2, '2026-09-10 10:01:00', deleted: 1),
        gmsg(3, '2026-09-10 10:02:00'),
      ];
      expect(groupingFor(msgs, 0).showMeta, isTrue); // broken by tombstone
      expect(groupingFor(msgs, 1).showMeta, isTrue); // tombstone standalone
      expect(groupingFor(msgs, 2).showHeader, isTrue); // broken by tombstone
    });

    test('local bubble at the end never groups with the last server row', () {
      final msgs = [
        gmsg(1, '2026-09-10 10:00:00'),
        gmsg(0, '', local: 42),
      ];
      expect(groupingFor(msgs, 0).showMeta, isTrue);
      expect(groupingFor(msgs, 1).showMeta, isTrue);
    });
  });

  group('segmentText (B1 link detection)', () {
    test('plain text is a single text segment', () {
      final segs = segmentText('Staff meeting at 2pm, room 4.');
      expect(segs.length, 1);
      expect(segs.single.kind, LinkKind.text);
      expect(segs.single.text, 'Staff meeting at 2pm, room 4.');
    });

    test('https URL with trailing comma is trimmed', () {
      final segs = segmentText('See https://example.com/a?b=1, ok?');
      expect(segs[1].kind, LinkKind.url);
      expect(segs[1].text, 'https://example.com/a?b=1');
    });

    test('www URL gains scheme, trailing period trimmed', () {
      final segs = segmentText('visit www.school.edu.et/news.');
      expect(segs[1].kind, LinkKind.url);
      expect(segs[1].text, 'www.school.edu.et/news');
    });

    test('email detected', () {
      final segs = segmentText('mail a.bekele@school.edu.et today');
      expect(segs[1].kind, LinkKind.email);
      expect(segs[1].text, 'a.bekele@school.edu.et');
    });

    test('Ethiopian mobile numbers detected', () {
      expect(segmentText('call 0911223344.')[1].text, '0911223344');
      expect(segmentText('call +251911223344 now')[1].text, '+251911223344');
    });

    test('references and IDs are NOT phones', () {
      expect(segmentText('John 3:16').single.kind, LinkKind.text);
      expect(segmentText('ID 123456789').single.kind, LinkKind.text);
      expect(segmentText('salary 1500 birr').single.kind, LinkKind.text);
    });

    test('mixed body segments in order', () {
      final segs = segmentText('see https://x.edu.ey and mail a@b.co');
      expect(segs.length, 4);
      expect(segs[0].kind, LinkKind.text);
      expect(segs[1].kind, LinkKind.url);
      expect(segs[2].kind, LinkKind.text);
      expect(segs[3].kind, LinkKind.email);
    });
  });

  group('linkUri (B1 launch targets)', () {
    test('www URL gets an https scheme', () {
      expect(linkUri(const TextSegment('www.x.com', LinkKind.url)).toString(),
          'https://www.x.com');
    });

    test('email becomes mailto:', () {
      expect(linkUri(const TextSegment('a@b.co', LinkKind.email)).toString(),
          'mailto:a@b.co');
    });

    test('local mobile is normalized to international tel:', () {
      expect(linkUri(const TextSegment('0911223344', LinkKind.phone)).toString(),
          'tel:+251911223344');
      expect(
          linkUri(const TextSegment('+251911223344', LinkKind.phone)).toString(),
          'tel:+251911223344');
    });
  });
}
