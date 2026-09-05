import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/services/amharic_text.dart';
import 'package:fkss_app/services/lyrics_search.dart';

/// P37: Telegram-style lyrics search.
void main() {
  group('Amharic homophone normalisation — the core bug', () {
    test('ጸ and ፀ families fold together', () {
      expect(normalize('ፀሐይ'), normalize('ጸሀይ'));
    });

    test('all four spellings of "sun" agree', () {
      final forms = ['ጸሐይ', 'ጸሀይ', 'ፀሐይ', 'ፀሀይ'];
      final normalized = forms.map(normalize).toSet();
      expect(normalized.length, 1, reason: 'got $normalized');
    });

    test('the ሀ family folds to one representative', () {
      for (final v in ['ሃ', 'ሐ', 'ሓ', 'ኀ', 'ኃ', 'ኻ']) {
        expect(normalize(v), 'ሀ', reason: v);
      }
    });

    test('the አ family folds to one representative', () {
      for (final v in ['ዐ', 'ዓ', 'ኣ']) {
        expect(normalize(v), 'አ', reason: v);
      }
    });

    test('the ሰ/ሠ family folds', () {
      expect(normalize('ሠላም'), normalize('ሰላም'));
    });

    test('LENGTH IS PRESERVED — highlight offsets depend on it', () {
      const samples = [
        'ፀሐይ',
        'ሰላም ለኪ ማርያም',
        'Jesus Christ',
        'ጸሎት፡ወመዝሙር።',
        '',
        'ABC123',
      ];
      for (final s in samples) {
        expect(normalize(s).length, s.length, reason: 'mismatch for "$s"');
      }
    });

    test('Ethiopic punctuation becomes a separator, not a letter', () {
      final toks = tokenize('ሰላም፡ለኪ');
      expect(toks.map((t) => t.word), ['ሰላም', 'ለኪ']);
    });

    test('Latin text still lowercases', () {
      expect(normalize('Hallelujah'), 'hallelujah');
    });

    test('empty input is safe', () {
      expect(normalize(''), '');
      expect(tokenize(''), isEmpty);
    });
  });

  group('tokenisation', () {
    test('offsets point into the ORIGINAL string', () {
      const text = 'ሰላም ለኪ';
      final toks = tokenize(text);
      expect(text.substring(toks[0].start, toks[0].end), 'ሰላም');
      expect(text.substring(toks[1].start, toks[1].end), 'ለኪ');
    });

    test('single characters never index (server parity)', () {
      expect(tokenize('a bc d').map((t) => t.word), ['bc']);
    });

    test('a skipped short word still consumes an ordinal', () {
      // Otherwise "bc" and "de" would look adjacent and falsely match as
      // a phrase.
      final toks = tokenize('bc a de');
      expect(toks.map((t) => t.ordinal), [0, 2]);
    });

    test('queryTerms normalises the user input too', () {
      expect(queryTerms('ፀሐይ'), queryTerms('ጸሀይ'));
    });
  });

  group('ranking tiers', () {
    SearchHit? hit(String title, String lyrics, String q) => LyricsSearch.rank(
          hymnId: 1,
          title: title,
          lyrics: lyrics,
          terms: queryTerms(q),
        );

    test('a title match outranks a lyrics match', () {
      final t = LyricsSearch.rank(
          hymnId: 1, title: 'ሰላም', lyrics: '', terms: queryTerms('ሰላም'))!;
      final l = LyricsSearch.rank(
          hymnId: 2,
          title: 'ሌላ ነገር',
          lyrics: 'ሰላም ለኪ',
          terms: queryTerms('ሰላም'))!;
      expect(t.score, greaterThan(l.score));
    });

    test('an exact title beats a partial title', () {
      final exact = hit('ሰላም', '', 'ሰላም')!;
      final partial = hit('ሰላም ለኪ ማርያም', '', 'ሰላም')!;
      expect(exact.score, greaterThan(partial.score));
    });

    test('a phrase in the lyrics beats the same words scattered', () {
      final phrase = LyricsSearch.rank(
          hymnId: 1,
          title: 'x',
          lyrics: 'ሰላም ለኪ ማርያም',
          terms: queryTerms('ሰላም ለኪ'))!;
      final scattered = LyricsSearch.rank(
          hymnId: 2,
          title: 'x',
          lyrics: 'ሰላም ብዙ ቃላት እዚህ አሉ ለኪ',
          terms: queryTerms('ሰላም ለኪ'))!;
      expect(phrase.score, greaterThan(scattered.score));
    });

    test('no match returns null rather than a zero-scored hit', () {
      expect(hit('ሰላም', 'ለኪ', 'ማርያም'), isNull);
    });

    test('an empty query matches nothing', () {
      expect(
        LyricsSearch.rank(hymnId: 1, title: 'a', lyrics: 'b', terms: const []),
        isNull,
      );
    });

    test('homophone spelling still finds the hymn — the reported bug', () {
      final h = hit('ፀሐይ ወጣች', '', 'ጸሀይ');
      expect(h, isNotNull);
      expect(h!.field, MatchField.title);
    });

    test('matching every term scores above matching some', () {
      final all = LyricsSearch.rank(
          hymnId: 1,
          title: 'ሰላም ለኪ',
          lyrics: '',
          terms: queryTerms('ሰላም ለኪ'))!;
      final some = LyricsSearch.rank(
          hymnId: 2,
          title: 'ሰላም ብቻ',
          lyrics: '',
          terms: queryTerms('ሰላም ለኪ'))!;
      expect(all.score, greaterThan(some.score));
      expect(all.isPartial, isFalse);
      expect(some.isPartial, isTrue);
    });

    test('a hymn with no lyrics downloaded still matches on title', () {
      final h = hit('ሰላም ለኪ', '', 'ሰላም');
      expect(h, isNotNull);
      expect(h!.snippet.text, isEmpty);
    });

    test('fuzzy matching catches a single typo', () {
      expect(hit('hallelujah', '', 'hallelujuh'), isNotNull);
    });

    test('fuzzy does NOT fire on short words', () {
      // 'abc' vs 'abd' would be 1 edit, but on 3-letter words that
      // produces far too many false hits.
      expect(hit('abc', '', 'abd'), isNull);
    });

    test('a real match always outranks a fuzzy one', () {
      final real = hit('hallelujah', '', 'hallelujah')!;
      final fuzzy = hit('hallelujah', '', 'hallelujuh')!;
      expect(real.score, greaterThan(fuzzy.score));
    });
  });

  group('highlight ranges', () {
    test('a title range covers exactly the matched word', () {
      const title = 'ሰላም ለኪ';
      final h = LyricsSearch.rank(
          hymnId: 1, title: title, lyrics: '', terms: queryTerms('ለኪ'))!;
      final r = h.titleRanges.single;
      expect(title.substring(r.start, r.end), 'ለኪ');
    });

    test('a prefix match highlights only the typed prefix (Telegram)', () {
      const title = 'hallelujah';
      final h = LyricsSearch.rank(
          hymnId: 1, title: title, lyrics: '', terms: queryTerms('hall'))!;
      final r = h.titleRanges.single;
      expect(title.substring(r.start, r.end), 'hall');
    });

    test('ranges are painted over the ORIGINAL, homophones and all', () {
      // Query uses ጸ, title uses ፀ — the range must still land on the
      // real characters of the stored title.
      const title = 'ፀሐይ ወጣች';
      final h = LyricsSearch.rank(
          hymnId: 1, title: title, lyrics: '', terms: queryTerms('ጸሀይ'))!;
      final r = h.titleRanges.single;
      expect(title.substring(r.start, r.end), 'ፀሐይ');
    });

    test('overlapping ranges are merged', () {
      final h = LyricsSearch.rank(
          hymnId: 1,
          title: 'testing',
          lyrics: '',
          terms: queryTerms('test testing'))!;
      expect(h.titleRanges.length, 1);
    });

    test('ranges are sorted and non-overlapping', () {
      final h = LyricsSearch.rank(
          hymnId: 1,
          title: 'alpha beta gamma',
          lyrics: '',
          terms: queryTerms('gamma alpha'))!;
      final rs = h.titleRanges;
      for (var i = 1; i < rs.length; i++) {
        expect(rs[i].start, greaterThanOrEqualTo(rs[i - 1].end));
      }
    });
  });

  group('snippets', () {
    test('a snippet is produced for a lyrics hit', () {
      final h = LyricsSearch.rank(
          hymnId: 1,
          title: 'x',
          lyrics: 'መዝሙር ሰላም ለኪ ማርያም ድንግል',
          terms: queryTerms('ማርያም'))!;
      expect(h.snippet.text, contains('ማርያም'));
    });

    test('snippet ranges are valid offsets into the snippet text', () {
      final h = LyricsSearch.rank(
          hymnId: 1,
          title: 'x',
          lyrics: 'መዝሙር ሰላም ለኪ ማርያም ድንግል እናት',
          terms: queryTerms('ማርያም'))!;
      final s = h.snippet;
      expect(s.ranges, isNotEmpty);
      for (final r in s.ranges) {
        expect(r.start, greaterThanOrEqualTo(0));
        expect(r.end, lessThanOrEqualTo(s.text.length));
        expect(r.start, lessThan(r.end));
      }
      final first = s.ranges.first;
      expect(s.text.substring(first.start, first.end), 'ማርያም');
    });

    test('newlines are flattened so the row stays one line', () {
      final h = LyricsSearch.rank(
          hymnId: 1,
          title: 'x',
          lyrics: 'line one\nline two ማርያም\nline three',
          terms: queryTerms('ማርያም'))!;
      expect(h.snippet.text, isNot(contains('\n')));
    });

    test('a long lyric marks both ellipses', () {
      final long = '${'ቃል ' * 80}ማርያም${' ቃል' * 80}';
      final h = LyricsSearch.rank(
          hymnId: 1, title: 'x', lyrics: long, terms: queryTerms('ማርያም'))!;
      expect(h.snippet.ellipsisBefore, isTrue);
      expect(h.snippet.ellipsisAfter, isTrue);
    });

    test('a match at the very start has no leading ellipsis', () {
      final h = LyricsSearch.rank(
          hymnId: 1,
          title: 'x',
          lyrics: 'ማርያም ${'ቃል ' * 60}',
          terms: queryTerms('ማርያም'))!;
      expect(h.snippet.ellipsisBefore, isFalse);
    });

    test('the snippet centres on the densest cluster, not the first hit', () {
      final lyrics = 'ሰላም ${'ቃል ' * 40} ሰላም ለኪ ማርያም';
      final h = LyricsSearch.rank(
          hymnId: 1,
          title: 'x',
          lyrics: lyrics,
          terms: queryTerms('ሰላም ለኪ'))!;
      expect(h.snippet.text, contains('ለኪ'));
    });
  });

  group('rankAll — ordering and limits', () {
    final rows = [
      {'id': 1, 'title': 'ሰላም', 'lyrics': ''},
      {'id': 2, 'title': 'ሌላ', 'lyrics': 'ሰላም ለኪ'},
      {'id': 3, 'title': 'ምንም', 'lyrics': 'ምንም የለም'},
    ];

    test('returns hits best-first and drops non-matches', () {
      final hits = LyricsSearch.rankAll(rows: rows, query: 'ሰላም');
      expect(hits.map((h) => h.hymnId), [1, 2]);
    });

    test('respects the limit', () {
      expect(LyricsSearch.rankAll(rows: rows, query: 'ሰላም', limit: 1).length, 1);
    });

    test('an empty query returns nothing', () {
      expect(LyricsSearch.rankAll(rows: rows, query: ''), isEmpty);
    });

    test('a 1-character query returns nothing (parity)', () {
      expect(LyricsSearch.rankAll(rows: rows, query: 'ሰ'), isEmpty);
    });

    test('ties break deterministically by id', () {
      final tied = [
        {'id': 7, 'title': 'ሰላም', 'lyrics': ''},
        {'id': 3, 'title': 'ሰላም', 'lyrics': ''},
      ];
      expect(LyricsSearch.rankAll(rows: tied, query: 'ሰላም').map((h) => h.hymnId),
          [3, 7]);
    });

    test('rows with a malformed id are skipped, not crashed on', () {
      final bad = [
        {'id': null, 'title': 'ሰላም', 'lyrics': ''},
        {'id': 'abc', 'title': 'ሰላም', 'lyrics': ''},
        {'id': 5, 'title': 'ሰላም', 'lyrics': ''},
      ];
      expect(LyricsSearch.rankAll(rows: bad, query: 'ሰላም').map((h) => h.hymnId),
          [5]);
    });

    test('a string id that parses is still usable', () {
      final rows2 = [
        {'id': '9', 'title': 'ሰላም', 'lyrics': ''}
      ];
      expect(LyricsSearch.rankAll(rows: rows2, query: 'ሰላም').single.hymnId, 9);
    });
  });
}
