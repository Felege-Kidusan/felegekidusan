/// Amharic/Ethiopic text normalisation for search (P37).
///
/// WHY THIS EXISTS
/// ---------------
/// Amharic has *homophones* (ድምጸ ሞክሼ ሆሄያት) — distinct characters that sound
/// identical and are used interchangeably in everyday writing. The same
/// hymn word is spelt several equally-correct ways by different people:
///
///   ጸሐይ  ጸሀይ  ፀሐይ  ፀሀይ   (all "sun")
///   ኢየሱስ  ኢዬሱስ            (all "Jesus")
///
/// Before this file the app compared raw code points, so a member who
/// typed ጸሀይ simply did not find a hymn stored as ፀሐይ. For a hymn book
/// that is a total search failure, not a ranking nicety.
///
/// The remedy is the standard one in Amharic NLP: fold each homophone
/// group onto a single representative before indexing AND before
/// querying, so both sides meet at the same token.
///
/// LENGTH-PRESERVING BY CONTRACT
/// -----------------------------
/// [normalize] maps exactly one code point to one code point. That is a
/// deliberate constraint, not an oversight: the highlighter finds matches
/// in normalised text and then paints ranges over the ORIGINAL string, so
/// character offsets must line up exactly. A tempting extra rule —
/// collapsing labialised pairs (ሉዋ → ሏ) — would shift every subsequent
/// offset and silently mis-paint highlights, so it is deliberately NOT
/// done here. Its benefit is small; correct highlighting is not.
///
/// Verified by test: `normalize(s).length == s.length` for all inputs.
library;

/// Homophone folding table.
///
/// Each entry maps a variant onto the group's representative — the first
/// entry of that row in the fidel chart, which is the convention in the
/// Amharic NLP literature.
const Map<String, String> _homophones = {
  // ሀ-group: h. ሀ ሃ ሐ ሓ ኀ ኃ ኻ all sound alike.
  'ሃ': 'ሀ', 'ሐ': 'ሀ', 'ሓ': 'ሀ', 'ኀ': 'ሀ', 'ኃ': 'ሀ', 'ኻ': 'ሀ',
  'ሑ': 'ሁ', 'ኁ': 'ሁ', 'ኹ': 'ሁ',
  'ሒ': 'ሂ', 'ኂ': 'ሂ', 'ኺ': 'ሂ',
  'ሔ': 'ሄ', 'ኄ': 'ሄ', 'ኼ': 'ሄ',
  'ሕ': 'ህ', 'ኅ': 'ህ', 'ኽ': 'ህ',
  'ሖ': 'ሆ', 'ኆ': 'ሆ', 'ኾ': 'ሆ',

  // ሰ/ሠ-group: s.
  'ሠ': 'ሰ', 'ሡ': 'ሱ', 'ሢ': 'ሲ', 'ሣ': 'ሳ',
  'ሤ': 'ሴ', 'ሥ': 'ስ', 'ሦ': 'ሶ',

  // አ/ዐ-group: glottal.
  'ዐ': 'አ', 'ዓ': 'አ', 'ኣ': 'አ',
  'ዑ': 'ኡ', 'ዒ': 'ኢ', 'ዔ': 'ኤ', 'ዕ': 'እ', 'ዖ': 'ኦ',

  // ጸ/ፀ-group: ts'.
  'ፀ': 'ጸ', 'ፁ': 'ጹ', 'ፂ': 'ጺ', 'ፃ': 'ጻ',
  'ፄ': 'ጼ', 'ፅ': 'ጽ', 'ፆ': 'ጾ',

  // Labialised alternates that ARE single code points (safe: 1:1).
  'ቊ': 'ቁ', 'ኵ': 'ኩ', 'ጐ': 'ጎ', 'ኰ': 'ኮ',
};

/// Ethiopic word separators and punctuation, folded to a plain space.
///
/// ፡ (word space) and ። (full stop) are *not* letters, but unicode61 and
/// naive regexes have historically treated them inconsistently. Folding
/// them explicitly keeps tokenisation predictable.
const Set<String> _ethiopicPunctuation = {
  '፡', '።', '፣', '፤', '፥', '፦', '፧', '፨', '፠',
};

/// The single entry point: fold homophones and lowercase, preserving
/// length exactly so highlight offsets stay valid.
String normalize(String input) {
  if (input.isEmpty) return input;
  final buffer = StringBuffer();
  // toLowerCase is a no-op for Ethiopic (it is unicameral) and handles
  // the Latin hymn titles that also live in this catalogue.
  for (final ch in input.toLowerCase().split('')) {
    if (_ethiopicPunctuation.contains(ch)) {
      buffer.write(' ');
      continue;
    }
    buffer.write(_homophones[ch] ?? ch);
  }
  final out = buffer.toString();
  // Contract check: the highlighter depends on this. Lowercasing can in
  // principle change length for some Latin edge cases (e.g. 'İ'), so if
  // that ever happens, fall back to the folded-but-not-lowercased form
  // rather than corrupt every offset downstream.
  if (out.length != input.length) {
    // Fold homophones without lowercasing, and lowercase a character
    // only when doing so keeps it a single code point.
    final safe = StringBuffer();
    for (final ch in input.split('')) {
      if (_ethiopicPunctuation.contains(ch)) {
        safe.write(' ');
        continue;
      }
      final folded = _homophones[ch];
      if (folded != null) {
        safe.write(folded);
        continue;
      }
      final lower = ch.toLowerCase();
      safe.write(lower.length == ch.length ? lower : ch);
    }
    return safe.toString();
  }
  return out;
}

/// Matches a run of letters/marks/digits in ANY script.
///
/// P27c lesson preserved: this needs exactly ONE backslash in a raw
/// string. A previous version carried four and matched only the literal
/// characters p{LMN}, silently killing search for both Amharic and
/// English.
final RegExp tokenPattern = RegExp(r'[\p{L}\p{M}\p{N}]+', unicode: true);

/// Minimum indexable token length. Server parity: WORD_MIN_CHARS = 2.
const int kWordMinChars = 2;

/// Minimum QUERY term length (P39).
///
/// Deliberately 1, unlike [kWordMinChars]. Telegram shows results from
/// the first character, and refusing to search until the second makes
/// the feature feel dead — the user cannot tell "still typing" from
/// "nothing found". Indexing still skips 1-character words (they are
/// noise in a document), but a 1-character QUERY is answered by a
/// prefix probe.
const int kQueryMinChars = 1;

/// Maximum indexable token length, matching the server's guard so a
/// pathological blob cannot bloat the index.
const int kWordMaxChars = 30;

/// A token plus where it sits in the ORIGINAL string.
class TextToken {
  final String word; // normalised
  final int start; // inclusive, offset into the original text
  final int end; // exclusive
  final int ordinal; // 0-based word number, for phrase/proximity checks

  const TextToken({
    required this.word,
    required this.start,
    required this.end,
    required this.ordinal,
  });

  @override
  String toString() => '$word@$start-$end#$ordinal';
}

/// Tokenises [text], returning normalised words with original offsets.
///
/// Offsets index the ORIGINAL string, which is what the UI renders, so
/// callers can paint highlights without re-deriving anything.
List<TextToken> tokenize(String text) {
  if (text.isEmpty) return const [];
  final normalized = normalize(text);
  final out = <TextToken>[];
  var ordinal = 0;
  for (final m in tokenPattern.allMatches(normalized)) {
    final w = m.group(0);
    if (w == null) continue;
    if (w.length < kWordMinChars || w.length > kWordMaxChars) {
      // Still consumes an ordinal: phrase matching must not silently
      // close the gap where a skipped word actually sits.
      ordinal++;
      continue;
    }
    out.add(TextToken(word: w, start: m.start, end: m.end, ordinal: ordinal));
    ordinal++;
  }
  return out;
}

/// Distinct normalised words of [text], for index writes.
Set<String> indexWords(String text) =>
    tokenize(text).map((t) => t.word).toSet();

/// Splits a user's query into normalised search terms.
///
/// Unlike [tokenize] this keeps 1-character terms out but does NOT apply
/// the max-length cap — a long paste should still be searchable.
List<String> queryTerms(String query) {
  final normalized = normalize(query);
  final out = <String>[];
  for (final m in tokenPattern.allMatches(normalized)) {
    final w = m.group(0);
    if (w != null && w.length >= kQueryMinChars) out.add(w);
  }
  return out;
}
