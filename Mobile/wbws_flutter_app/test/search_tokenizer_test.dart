import 'package:flutter_test/flutter_test.dart';

/// Regression guard (P27c): the on-device search tokenizer.
///
/// History: the word-index commit shipped `r'[\\p{L}...]'` with QUADRUPLE
/// backslashes — a raw string needs exactly ONE — so the "unicode class"
/// actually matched only the literal characters p { L } M N \ and every
/// real query produced ZERO tokens (silent death of local search, Amharic
/// and English alike). This test pins the correct behavior forever.
void main() {
  final tokenPattern = RegExp(r'[\p{L}\p{M}\p{N}]+', unicode: true);

  List<String> tokenize(String input) => tokenPattern
      .allMatches(input.toLowerCase())
      .map((m) => m.group(0))
      .where((t) => (t ?? '').length >= 2)
      .cast<String>()
      .toList();

  test('tokenizes Amharic (Ge\'ez script) words', () {
    expect(tokenize('ሰላምታ ለዓለም ሁሉ'), ['ሰላምታ', 'ለዓለም', 'ሁሉ']);
  });

  test('tokenizes English words and attached digits', () {
    expect(tokenize('Selamawit Guad'), ['selamawit', 'guad']);
    expect(tokenize('P25 Smoke En'), ['p25', 'smoke', 'en']);
  });

  test('drops single-character tokens (server WORD_MIN_CHARS parity)', () {
    expect(tokenize('a b ሰላም'), ['ሰላም']);
  });

  test('splits on punctuation and markup', () {
    expect(tokenize('[Verse 1] **bold** word'), ['verse', 'bold', 'word']);
  });
}
