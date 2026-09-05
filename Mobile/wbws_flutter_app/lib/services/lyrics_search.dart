/// Telegram-style lyrics search: ranking, snippets and highlight ranges.
///
/// Pure Dart on purpose — no Flutter, no sqflite — so every rule here is
/// unit-testable without a device.
///
/// DESIGN NOTES (P37)
/// ------------------
/// Why not SQLite FTS5, which gives bm25(), snippet() and highlight()
/// for free? Because sqflite links the *device's* system SQLite, and
/// FTS5 is absent from many Android builds ("no such module: fts5") —
/// it is not compiled in below roughly API 24 and remains vendor
/// dependent above it. Shipping a search that silently fails to create
/// its index on a member's phone is not acceptable for the primary way
/// people find a hymn, and bundling a second SQLite (sqlite3_flutter_libs)
/// would add ~4 MB per ABI to an APK used on low-end devices over poor
/// connections.
///
/// So the index stays an ordinary table and the ranking/snippet logic
/// that FTS5 would have done in C lives here in Dart, where it is
/// testable and where it can be taught Amharic homophones — which FTS5's
/// unicode61 tokenizer does NOT know about anyway.
library;

import 'amharic_text.dart';

/// Where a match was found. Drives both ranking and the UI's label.
enum MatchField { title, lyrics }

/// A [start, end) range in the ORIGINAL text, to be painted bold.
class HighlightRange {
  final int start;
  final int end;
  const HighlightRange(this.start, this.end);

  @override
  bool operator ==(Object other) =>
      other is HighlightRange && other.start == start && other.end == end;

  @override
  int get hashCode => Object.hash(start, end);

  @override
  String toString() => '[$start,$end)';
}

/// A snippet of lyrics with the matched words located inside it.
///
/// [ranges] are offsets into [text], NOT into the original lyrics, so the
/// widget can render without further arithmetic.
class SearchSnippet {
  final String text;
  final List<HighlightRange> ranges;
  final bool ellipsisBefore;
  final bool ellipsisAfter;

  const SearchSnippet({
    required this.text,
    required this.ranges,
    this.ellipsisBefore = false,
    this.ellipsisAfter = false,
  });

  static const empty = SearchSnippet(text: '', ranges: []);
}

/// One ranked hit.
class SearchHit {
  final int hymnId;
  final double score;
  final MatchField field;

  /// Highlight ranges over the ORIGINAL title.
  final List<HighlightRange> titleRanges;

  /// Lyrics excerpt, or [SearchSnippet.empty] when the hit was
  /// title-only or the lyrics blob is not on the device yet.
  final SearchSnippet snippet;

  /// How many of the query's terms this hymn matched at all. Exposed so
  /// the UI can mark partial matches honestly.
  final int matchedTerms;
  final int totalTerms;

  const SearchHit({
    required this.hymnId,
    required this.score,
    required this.field,
    this.titleRanges = const [],
    this.snippet = SearchSnippet.empty,
    this.matchedTerms = 0,
    this.totalTerms = 0,
  });

  bool get isPartial => matchedTerms < totalTerms;
}

/// Scoring weights.
///
/// Chosen so the tiers can never overlap regardless of term count: a
/// title match always outranks a lyrics match, and an exact phrase
/// always outranks the same words scattered. Kept as named constants so
/// the ordering is auditable rather than buried in magic numbers.
class SearchWeights {
  static const double titleExact = 1000;
  static const double titlePrefix = 600;
  static const double titleWord = 400;
  static const double titleFuzzy = 150;

  static const double lyricsPhrase = 300;
  static const double lyricsWord = 60;
  static const double lyricsProximity = 40;

  /// Every query term present, in any field.
  static const double allTermsBonus = 250;

  /// Match starts at the very beginning of the lyrics.
  static const double leadingBonus = 25;
}

class LyricsSearch {
  /// Ranks one hymn against [terms].
  ///
  /// [lyrics] may be empty — most cached rows have no lyrics blob until
  /// it is lazily downloaded — in which case only the title contributes
  /// and no snippet is produced.
  ///
  /// Returns null when nothing matched, so callers can filter directly.
  static SearchHit? rank({
    required int hymnId,
    required String title,
    required String lyrics,
    required List<String> terms,
    int snippetRadius = 48,
  }) {
    if (terms.isEmpty) return null;

    final titleTokens = tokenize(title);
    final lyricTokens = tokenize(lyrics);
    final normTitle = normalize(title);

    var score = 0.0;
    final matched = <String>{};
    final titleRanges = <HighlightRange>[];

    // ---- title tier -------------------------------------------------
    final joinedQuery = terms.join(' ');
    if (normTitle.trim() == joinedQuery) {
      score += SearchWeights.titleExact;
      matched.addAll(terms);
      if (title.isNotEmpty) titleRanges.add(HighlightRange(0, title.length));
    } else {
      for (final term in terms) {
        var best = 0.0;
        for (final tok in titleTokens) {
          if (tok.word == term) {
            best = best < SearchWeights.titleWord
                ? SearchWeights.titleWord
                : best;
            titleRanges.add(HighlightRange(tok.start, tok.end));
            matched.add(term);
          } else if (tok.word.startsWith(term)) {
            best = best < SearchWeights.titlePrefix
                ? SearchWeights.titlePrefix
                : best;
            // Highlight only the matched prefix, exactly as Telegram
            // does — the rest of the word stays plain.
            titleRanges.add(HighlightRange(tok.start, tok.start + term.length));
            matched.add(term);
          }
        }
        score += best;
      }
      // Fuzzy fallback: catches a single typo, but only when nothing
      // better matched, so it can never outrank a real hit.
      if (matched.isEmpty) {
        for (final term in terms) {
          for (final tok in titleTokens) {
            if (_within1Edit(term, tok.word)) {
              score += SearchWeights.titleFuzzy;
              matched.add(term);
              titleRanges.add(HighlightRange(tok.start, tok.end));
              break;
            }
          }
        }
      }
    }

    // ---- lyrics tier ------------------------------------------------
    var snippet = SearchSnippet.empty;
    final lyricHitOrdinals = <int>[];

    if (lyricTokens.isNotEmpty) {
      final phrase = _findPhrase(lyricTokens, terms);
      if (phrase != null) {
        score += SearchWeights.lyricsPhrase;
        matched.addAll(terms);
        lyricHitOrdinals.add(phrase);
      }
      final perTerm = <String, List<TextToken>>{};
      for (final tok in lyricTokens) {
        for (final term in terms) {
          if (tok.word == term || tok.word.startsWith(term)) {
            (perTerm[term] ??= []).add(tok);
          }
        }
      }
      for (final entry in perTerm.entries) {
        score += SearchWeights.lyricsWord;
        matched.add(entry.key);
      }
      // Proximity: terms appearing close together are likelier to be the
      // line the user half-remembers than the same words paragraphs apart.
      if (perTerm.length > 1) {
        final firsts = perTerm.values.map((v) => v.first.ordinal).toList()
          ..sort();
        final span = firsts.last - firsts.first;
        if (span <= terms.length * 3) score += SearchWeights.lyricsProximity;
      }

      final anchor = _bestAnchor(lyricTokens, terms, phrase);
      if (anchor != null) {
        if (anchor.start <= 2) score += SearchWeights.leadingBonus;
        snippet = buildSnippet(
          lyrics: lyrics,
          terms: terms,
          anchor: anchor,
          radius: snippetRadius,
        );
      }
    }

    if (score <= 0) return null;
    if (matched.length == terms.length) score += SearchWeights.allTermsBonus;

    return SearchHit(
      hymnId: hymnId,
      score: score,
      field: titleRanges.isNotEmpty ? MatchField.title : MatchField.lyrics,
      titleRanges: mergeRanges(titleRanges),
      snippet: snippet,
      matchedTerms: matched.length,
      totalTerms: terms.length,
    );
  }

  /// Builds a context window around [anchor], with every term inside it
  /// highlighted — the equivalent of FTS5's snippet(), but homophone
  /// aware and returning ranges instead of embedded markup (so the UI
  /// never has to parse tags out of user content).
  static SearchSnippet buildSnippet({
    required String lyrics,
    required List<String> terms,
    required TextToken anchor,
    int radius = 48,
  }) {
    if (lyrics.isEmpty) return SearchSnippet.empty;

    var start = anchor.start - radius;
    var end = anchor.end + radius;
    if (start < 0) start = 0;
    if (end > lyrics.length) end = lyrics.length;

    // Prefer clean word boundaries so the excerpt does not start or end
    // mid-syllable.
    start = _snapBackToBoundary(lyrics, start);
    end = _snapForwardToBoundary(lyrics, end);

    final raw = lyrics.substring(start, end);
    // Lyrics are newline-heavy; a single line reads far better in a list
    // row than a fragment wrapping over three.
    final flattened = raw.replaceAll(RegExp(r'\s+'), ' ').trim();

    // Re-locate the terms inside the FLATTENED text: whitespace
    // collapsing shifts offsets, so ranges computed against the original
    // would be wrong. Re-tokenising is cheap and always correct.
    final ranges = <HighlightRange>[];
    for (final tok in tokenize(flattened)) {
      for (final term in terms) {
        if (tok.word == term) {
          ranges.add(HighlightRange(tok.start, tok.end));
          break;
        }
        if (tok.word.startsWith(term)) {
          ranges.add(HighlightRange(tok.start, tok.start + term.length));
          break;
        }
      }
    }

    return SearchSnippet(
      text: flattened,
      ranges: mergeRanges(ranges),
      ellipsisBefore: start > 0,
      ellipsisAfter: end < lyrics.length,
    );
  }

  /// Finds an exact consecutive run of [terms]; returns its starting
  /// ordinal, or null. This is what makes quoted-feeling phrase search
  /// rank above scattered words.
  static int? _findPhrase(List<TextToken> tokens, List<String> terms) {
    if (terms.length < 2 || tokens.length < terms.length) return null;
    for (var i = 0; i + terms.length <= tokens.length; i++) {
      var ok = true;
      for (var j = 0; j < terms.length; j++) {
        final tok = tokens[i + j];
        // Consecutive ordinals only: words separated by a skipped token
        // are not a phrase.
        if (j > 0 && tok.ordinal != tokens[i + j - 1].ordinal + 1) {
          ok = false;
          break;
        }
        if (tok.word != terms[j] && !tok.word.startsWith(terms[j])) {
          ok = false;
          break;
        }
      }
      if (ok) return i;
    }
    return null;
  }

  /// Picks the token the snippet should centre on: the phrase start when
  /// there is one, else the earliest position where the most terms
  /// cluster together.
  static TextToken? _bestAnchor(
      List<TextToken> tokens, List<String> terms, int? phraseIndex) {
    if (tokens.isEmpty) return null;
    if (phraseIndex != null) return tokens[phraseIndex];

    TextToken? best;
    var bestCount = 0;
    const window = 12;
    for (var i = 0; i < tokens.length; i++) {
      final hit = terms.any((t) =>
          tokens[i].word == t || tokens[i].word.startsWith(t));
      if (!hit) continue;
      var count = 0;
      for (var j = i; j < tokens.length && j < i + window; j++) {
        if (terms.any(
            (t) => tokens[j].word == t || tokens[j].word.startsWith(t))) {
          count++;
        }
      }
      if (count > bestCount) {
        bestCount = count;
        best = tokens[i];
      }
    }
    return best;
  }

  /// Overlapping or touching ranges are merged so the renderer never
  /// emits zero-width or double-painted spans.
  static List<HighlightRange> mergeRanges(List<HighlightRange> input) {
    if (input.length < 2) return List.unmodifiable(input);
    final sorted = [...input]..sort((a, b) => a.start.compareTo(b.start));
    final out = <HighlightRange>[sorted.first];
    for (final r in sorted.skip(1)) {
      final last = out.last;
      if (r.start <= last.end) {
        if (r.end > last.end) {
          out[out.length - 1] = HighlightRange(last.start, r.end);
        }
      } else {
        out.add(r);
      }
    }
    return List.unmodifiable(out);
  }

  static int _snapBackToBoundary(String s, int i) {
    if (i <= 0) return 0;
    var k = i;
    var guard = 0;
    while (k > 0 && guard < 24 && !_isSpace(s[k - 1])) {
      k--;
      guard++;
    }
    return k;
  }

  static int _snapForwardToBoundary(String s, int i) {
    if (i >= s.length) return s.length;
    var k = i;
    var guard = 0;
    while (k < s.length && guard < 24 && !_isSpace(s[k])) {
      k++;
      guard++;
    }
    return k;
  }

  static bool _isSpace(String ch) =>
      ch == ' ' || ch == '\n' || ch == '\t' || ch == '\r' || ch == '፡';

  /// Levenshtein distance ≤ 1, short-circuited. Cheaper and clearer than
  /// a full DP matrix for the only question we actually ask.
  static bool _within1Edit(String a, String b) {
    if (a == b) return true;
    final la = a.length, lb = b.length;
    if ((la - lb).abs() > 1) return false;
    // Typo tolerance on very short words does more harm than good.
    if (la < 4 || lb < 4) return false;
    var i = 0, j = 0, edits = 0;
    while (i < la && j < lb) {
      if (a[i] == b[j]) {
        i++;
        j++;
        continue;
      }
      if (++edits > 1) return false;
      if (la > lb) {
        i++;
      } else if (lb > la) {
        j++;
      } else {
        i++;
        j++;
      }
    }
    if (i < la || j < lb) edits++;
    return edits <= 1;
  }

  /// Ranks a whole candidate set and returns hits best-first.
  static List<SearchHit> rankAll({
    required Iterable<Map<String, dynamic>> rows,
    required String query,
    int limit = 100,
  }) {
    final terms = queryTerms(query);
    if (terms.isEmpty) return const [];
    final hits = <SearchHit>[];
    for (final r in rows) {
      final id = r['id'] is int
          ? r['id'] as int
          : int.tryParse('${r['id']}') ?? 0;
      if (id == 0) continue;
      final hit = rank(
        hymnId: id,
        title: '${r['title'] ?? ''}',
        lyrics: '${r['lyrics'] ?? ''}',
        terms: terms,
      );
      if (hit != null) hits.add(hit);
    }
    hits.sort((a, b) {
      final c = b.score.compareTo(a.score);
      return c != 0 ? c : a.hymnId.compareTo(b.hymnId); // stable
    });
    return hits.length > limit ? hits.sublist(0, limit) : hits;
  }
}

/// Highlight ranges for an arbitrary already-built string (P37).
///
/// Used for snippets that arrive from the SERVER, which sends excerpt
/// text but no offsets. Homophone-aware like everything else here, so a
/// server snippet highlights on the same rules as a local one.
List<HighlightRange> highlightRangesFor(String text, String query) {
  if (text.isEmpty) return const [];
  final terms = queryTerms(query);
  if (terms.isEmpty) return const [];
  final ranges = <HighlightRange>[];
  for (final tok in tokenize(text)) {
    for (final term in terms) {
      if (tok.word == term) {
        ranges.add(HighlightRange(tok.start, tok.end));
        break;
      }
      if (tok.word.startsWith(term)) {
        ranges.add(HighlightRange(tok.start, tok.start + term.length));
        break;
      }
    }
  }
  return LyricsSearch.mergeRanges(ranges);
}
