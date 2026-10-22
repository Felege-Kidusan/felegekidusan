import 'package:flutter/services.dart';

/// Score limit (owner, 2026-10-22): "if the department sets a test out of
/// 10, the teacher must not be able to enter more than 10 — limit it live."
///
/// Same rule as the web guard (admin/js/score_limit.js) and the server
/// (SubmissionService::invalidScores — HTTP 422 INVALID_SCORES):
///   * blank is allowed (blank = no score; after submit = "Did not take")
///   * otherwise 1–4 digits, optionally a dot and at most 2 decimals
///   * never negative, never more than the max
class ScoreLimit {
  ScoreLimit._();

  /// While typing: "9." is fine (the teacher is about to type a decimal).
  static final RegExp typing = RegExp(r'^(\d{1,4}(\.\d{0,2})?)?$');

  /// When saving: "9." is not a finished number.
  static final RegExp finished = RegExp(r'^\d{1,4}(\.\d{1,2})?$');

  static bool isValid(String text, double? max, {bool finalCheck = true}) {
    final t = text.trim();
    if (t.isEmpty) return true;
    if (!(finalCheck ? finished : typing).hasMatch(t)) return false;
    final v = double.tryParse(t);
    if (v == null || v.isNaN || v < 0) return false;
    return max == null || max <= 0 || v <= max + 1e-9;
  }

  /// 10 -> "10", 7.5 -> "7.5", 9.75 -> "9.75".
  static String label(num n) {
    final r = (n * 100).round() / 100;
    if (r == r.roundToDouble()) return r.toInt().toString();
    return r.toString();
  }

  /// Why a keystroke was refused — shown in the small hint.
  static String refusalReason(String attempted, double? max) {
    final t = attempted.trim();
    if (!RegExp(r'^[\d.]*$').hasMatch(t) || '.'.allMatches(t).length > 1) {
      return max != null && max > 0 ? 'Numbers only, 0 to ${label(max)}' : 'Numbers only';
    }
    if (RegExp(r'\.\d{3,}').hasMatch(t)) return 'Up to 2 decimals';
    final v = double.tryParse(t);
    if (max != null && max > 0 && v != null && v > max) return 'Max is ${label(max)}';
    return max != null && max > 0 ? 'Use a number from 0 to ${label(max)}' : 'Use a number';
  }
}

/// Refuses any edit that would make the box invalid — the old text stays and
/// [onRefused] is told why (for the "Max is 10" hint). Paste included.
class ScoreLimitFormatter extends TextInputFormatter {
  ScoreLimitFormatter(this.max, {this.onRefused});

  final double max;
  final void Function(String reason)? onRefused;

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    // Some keyboards send "," as the decimal key.
    var text = newValue.text.replaceAll(',', '.');
    if (text != newValue.text) {
      newValue = newValue.copyWith(text: text);
    }
    if (ScoreLimit.isValid(text, max, finalCheck: false)) return newValue;
    onRefused?.call(ScoreLimit.refusalReason(text, max));
    // Keep the old text. Only when the OLD text is itself invalid (e.g. an
    // old saved 15 on a /10 test) may the teacher shorten it to fix it —
    // never a shorter paste like "-9" over a valid "9.75".
    final oldBad = !ScoreLimit.isValid(oldValue.text, max, finalCheck: false);
    if (oldBad && newValue.text.length < oldValue.text.length && RegExp(r'^[\d.]*$').hasMatch(text)) {
      return newValue;
    }
    return oldValue;
  }
}
