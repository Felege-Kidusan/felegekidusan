/// Substring-capable matching for hymn search (P39).
///
/// THE BUG THIS FIXES
/// ------------------
/// P37/P38 matched terms with `word == term || word.startsWith(term)` —
/// at every layer: the SQL candidate query (`WHERE word LIKE 'term%'`),
/// the ranker, and the highlighter. That is PREFIX-ONLY matching, and it
/// is the reason search reported "no match" for hymns that plainly
/// contain the word.
///
/// Amharic is morphologically rich: words take prefixes (በ- "in",
/// ለ- "for", የ- "of", ከ- "from", እና- "and"). The form stored in the
/// lyrics is routinely an inflected one, while the user types the ROOT
/// they know:
///
///     stored: በሰላም   ("be-selam", in peace)
///     typed:  ሰላም    ("selam")
///     startsWith('በሰላም', 'ሰላም') == false   <-- silently no match
///
/// The same class of failure hits English mid-word queries ("lelu" in
/// "hallelujah").
///
/// THE FIX
/// -------
/// Two layers, matching how production systems handle infix search:
///
///  1. RETRIEVAL — a trigram index. Splitting each indexed word into
///     overlapping 3-character grams lets SQL find candidates by
///     *substring* using an indexed equality lookup, instead of an
///     unindexable `LIKE '%term%'` full scan. This is the standard
///     approach (Postgres pg_trgm, MySQL ngram parser, Google Code
///     Search): the trigram index NARROWS, then exact verification
///     confirms.
///
///  2. VERIFICATION/RANKING — `contains`, with the position of the hit
///     driving the score, so a prefix still outranks a mid-word hit and
///     nothing regresses.
///
/// Pure Dart, so every rule is unit-testable without a device.
library;

/// How a term matched a word. Ordered best-to-worst; the ranker maps
/// these to descending scores.
enum TermMatch {
  /// The word IS the term.
  exact,

  /// The term starts the word ("halle" in "hallelujah").
  prefix,

  /// The term ends the word — very common in Amharic, where the
  /// inflection sits at the FRONT ("ሰላም" in "በሰላም").
  suffix,

  /// The term sits inside the word ("lelu" in "hallelujah").
  infix,

  /// No match.
  none,
}

/// Trigram size. 3 is the standard compromise: 2-grams are too common to
/// be selective, 4-grams miss short queries and bloat the index.
const int kGramSize = 3;

/// Boundary markers, so a trigram can encode "starts the word" /
/// "ends the word". Control characters that cannot occur in hymn text.
const String kGramStart = '\u0001';
const String kGramEnd = '\u0002';

class SearchMatching {
  /// Classifies how [term] matches [word]. Both must already be
  /// normalised by the Amharic normaliser.
  static TermMatch classify(String word, String term) {
    if (term.isEmpty || word.isEmpty) return TermMatch.none;
    if (word == term) return TermMatch.exact;
    if (!word.contains(term)) return TermMatch.none;
    if (word.startsWith(term)) return TermMatch.prefix;
    if (word.endsWith(term)) return TermMatch.suffix;
    return TermMatch.infix;
  }

  /// Where [term] begins inside [word], or -1. Used to place highlights
  /// on the matched characters rather than the whole word.
  static int offsetIn(String word, String term) =>
      term.isEmpty ? -1 : word.indexOf(term);

  /// Trigrams of a word, with boundary markers.
  ///
  /// "ሰላም" -> {"\x01ሰላ", "ሰላም", "ላም\x02"}
  ///
  /// Words shorter than the gram size still yield one padded gram, so a
  /// 2-character Amharic word remains findable.
  static Set<String> gramsOf(String word) {
    if (word.isEmpty) return const {};
    final padded = '$kGramStart$word$kGramEnd';
    if (padded.length <= kGramSize) return {padded};
    final out = <String>{};
    for (var i = 0; i + kGramSize <= padded.length; i++) {
      out.add(padded.substring(i, i + kGramSize));
    }
    return out;
  }

  /// Trigrams to look up for a QUERY term.
  ///
  /// Deliberately different from [gramsOf]: a query is usually a
  /// fragment of a longer stored word, so its leading/trailing boundary
  /// markers would not appear in that word's grams. Only the *interior*
  /// grams are used, which is what makes substring retrieval work.
  ///
  /// For a term shorter than the gram size there are no interior grams,
  /// so this returns empty and the caller must fall back to a scan —
  /// exactly the 1-2 character case that powers type-ahead.
  static Set<String> queryGrams(String term) {
    if (term.length < kGramSize) return const {};
    final out = <String>{};
    for (var i = 0; i + kGramSize <= term.length; i++) {
      out.add(term.substring(i, i + kGramSize));
    }
    return out;
  }

  /// Whether a term is long enough for the trigram index to serve it.
  static bool isIndexable(String term) => term.length >= kGramSize;
}
