import 'package:flutter/material.dart';

import '../services/lyrics_search.dart';

/// Renders text with the matched terms emphasised, Telegram-style (P37).
///
/// Telegram/Telegram X paint the matched substring in the accent colour
/// with a heavier weight, leaving the surrounding text normal — they do
/// NOT use a yellow marker-pen background, which reads as "warning" and
/// fights any themed surface. This widget follows that convention using
/// the app's own bronze/primary accent.
///
/// Ranges are computed by [LyricsSearch] against this exact string, so
/// nothing is re-searched here. That matters: re-finding the terms in
/// the widget would need the Amharic normaliser again and would drift
/// out of step with the ranking.
class HighlightedText extends StatelessWidget {
  final String text;
  final List<HighlightRange> ranges;
  final TextStyle? style;
  final TextStyle? highlightStyle;
  final int? maxLines;
  final TextOverflow overflow;

  /// Prefixed/suffixed when the text is an excerpt from a longer body.
  final bool ellipsisBefore;
  final bool ellipsisAfter;

  const HighlightedText({
    super.key,
    required this.text,
    required this.ranges,
    this.style,
    this.highlightStyle,
    this.maxLines,
    this.overflow = TextOverflow.ellipsis,
    this.ellipsisBefore = false,
    this.ellipsisAfter = false,
  });

  @override
  Widget build(BuildContext context) {
    final base = style ?? DefaultTextStyle.of(context).style;
    final hi = highlightStyle ??
        base.copyWith(
          fontWeight: FontWeight.w700,
          color: Theme.of(context).colorScheme.primary,
        );

    return Text.rich(
      TextSpan(children: _spans(base, hi)),
      maxLines: maxLines,
      overflow: overflow,
    );
  }

  List<TextSpan> _spans(TextStyle base, TextStyle hi) {
    final spans = <TextSpan>[];
    if (ellipsisBefore) spans.add(TextSpan(text: '…', style: base));

    // Defensive: a stale or malformed range must degrade to plain text,
    // never throw a RangeError inside a list item.
    final safe = ranges
        .where((r) =>
            r.start >= 0 && r.end <= text.length && r.start < r.end)
        .toList()
      ..sort((a, b) => a.start.compareTo(b.start));

    var cursor = 0;
    for (final r in safe) {
      if (r.start < cursor) continue; // overlap guard
      if (r.start > cursor) {
        spans.add(TextSpan(text: text.substring(cursor, r.start), style: base));
      }
      spans.add(TextSpan(text: text.substring(r.start, r.end), style: hi));
      cursor = r.end;
    }
    if (cursor < text.length) {
      spans.add(TextSpan(text: text.substring(cursor), style: base));
    }

    if (ellipsisAfter) spans.add(TextSpan(text: '…', style: base));
    return spans;
  }
}
