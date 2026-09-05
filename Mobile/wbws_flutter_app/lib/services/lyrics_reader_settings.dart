import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Accessibility / readability preferences for the timed-lyrics (karaoke)
/// view.
///
/// This exists so that ONE user choice applies to EVERY hymn and survives an
/// app restart. It is a Flutter-free-of-widgets `ChangeNotifier` (singleton,
/// matching the codebase service pattern) so it can be read by the lyrics
/// screen and driven by the player's "Aa — text & reading" control without
/// coupling the two widgets together.
///
/// Three orthogonal settings, all persisted:
///   * [textScale]   — how large the lyric text is (multiplier over a base).
///   * [readingMode] — "lyrics only": big, steady, flat-emphasis text where the
///                     active line is subtly marked instead of the karaoke
///                     "deep water" drop-off. The best mode for someone who
///                     just wants to read the words.
///   * [highContrast]— use the darkest ink so the words pop on the parchment.
///
/// OS text scaling is still layered on top by Flutter (this app does not
/// disable `MediaQuery.textScaler`), so a user who sets the system font size
/// to "Extra large" gets an even bigger result automatically.
class LyricsReaderSettings extends ChangeNotifier {
  static final LyricsReaderSettings instance = LyricsReaderSettings._();

  factory LyricsReaderSettings() => instance;

  LyricsReaderSettings._();

  static const String _kTextScale = 'mezmur_lyrics_text_scale';
  static const String _kReading = 'mezmur_lyrics_reading_mode';
  static const String _kHighContrast = 'mezmur_lyrics_high_contrast';

  /// Range of the in-app text-size control.
  static const double minTextScale = 0.85;
  static const double maxTextScale = 1.70;

  /// Default is deliberately larger than 1.0 so lyrics are comfortable to
  /// read out of the box — and system font scaling still applies on top for
  /// users who need more. Selected from a quick study of the parchment text.
  static const double defaultTextScale = 1.15;

  double _textScale = defaultTextScale;
  bool _readingMode = false;
  bool _highContrast = false;
  bool _booted = false;

  double get textScale => _textScale;
  bool get readingMode => _readingMode;
  bool get highContrast => _highContrast;

  /// Restore persisted preferences once. Call after app start / login; it is
  /// best-effort — a read failure must never block the player.
  Future<void> boot() async {
    if (_booted) return;
    _booted = true;
    try {
      final prefs = await SharedPreferences.getInstance();
      _textScale = (prefs.getDouble(_kTextScale) ?? defaultTextScale)
          .clamp(minTextScale, maxTextScale);
      _readingMode = prefs.getBool(_kReading) ?? false;
      _highContrast = prefs.getBool(_kHighContrast) ?? false;
    } catch (_) {
      // Preferences are best-effort; never block the player on them.
    }
    notifyListeners();
  }

  Future<void> setTextScale(double v) async {
    final c = v.clamp(minTextScale, maxTextScale);
    if (c == _textScale) return;
    _textScale = c;
    notifyListeners();
    try {
      await (await SharedPreferences.getInstance()).setDouble(_kTextScale, c);
    } catch (_) {}
  }

  Future<void> setReadingMode(bool v) async {
    if (v == _readingMode) return;
    _readingMode = v;
    notifyListeners();
    try {
      await (await SharedPreferences.getInstance()).setBool(_kReading, v);
    } catch (_) {}
  }

  Future<void> setHighContrast(bool v) async {
    if (v == _highContrast) return;
    _highContrast = v;
    notifyListeners();
    try {
      await (await SharedPreferences.getInstance()).setBool(_kHighContrast, v);
    } catch (_) {}
  }
}
