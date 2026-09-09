import 'package:flutter/material.dart';

/// Cover colors for a category/sub-category (P32).
///
/// Priority: an admin-pinned gradient (two strict hex colors) wins;
/// otherwise the automatic name-hashed palette — the same palette and
/// hash the web manager uses, so a category looks identical on every
/// screen.
List<Color> coverColors(Map<String, dynamic>? item, String name) {
  final start = _hex(item?['gradient_start']);
  final end = _hex(item?['gradient_end']);
  if (start != null && end != null) return [start, end];
  return _autoPalette(name);
}

/// P66 hymn art: cover colors for a HYMN.
///
/// Priority: the server-extracted dominant color from the hymn's own
/// artwork (one truth for web + mobile, Spotify's "color as emotional
/// infrastructure"); without art, the same automatic name-hashed
/// palette the web console shows — so a hymn looks identical on every
/// screen whether or not it has artwork yet.
List<Color> hymnCoverColors(Map<String, dynamic>? hymn, String name) {
  final art = _hex(hymn?['art_color']);
  if (art != null) {
    // A shade of the dominant color, not a second hue: the artwork is
    // the identity, the gradient only needs depth behind text.
    final deep = Color.lerp(art, const Color(0xFF000000), 0.35)!;
    return [art, deep];
  }
  return _autoPalette(name);
}

/// The automatic palette — D2 fix: EXACT parity with the web manager's
/// hashCode (mezmur.js):
///
///   var h = 0;
///   for (...) h = ((h << 5) - h + str.charCodeAt(i)) | 0;
///   return Math.abs(h);
///
/// JS wraps to a SIGNED 32-bit int every iteration and takes the
/// absolute value only at the END. The previous Dart port masked each
/// step with & 0x7fffffff, which produces a different bucket for most
/// non-trivial names — the bug behind "same category, different
/// gradient on the phone than the web console".
const _palettes = [
  [Color(0xFF5A1212), Color(0xFFD4AF37)],
  [Color(0xFF4f46e5), Color(0xFF7c3aed)],
  [Color(0xFF0ea5e9), Color(0xFF2563eb)],
  [Color(0xFF059669), Color(0xFF0d9488)],
  [Color(0xFFd97706), Color(0xFFdc2626)],
  [Color(0xFFdb2777), Color(0xFF9333ea)],
];

List<Color> _autoPalette(String name) {
  var h = 0;
  for (final c in name.codeUnits) {
    h = ((h << 5) - h + c).toSigned(32); // == JS (… | 0)
  }
  h = h.abs(); // == JS Math.abs(h)
  return _palettes[h % _palettes.length];
}

/// Strict '#rrggbb' / '#rrggbbaa' parser (mirrors the server
/// validator; the optional alpha carries the picked opacity).
Color? _hex(dynamic v) {
  final s = (v ?? '').toString().trim();
  final m = RegExp(r'^#?([0-9a-fA-F]{6})([0-9a-fA-F]{2})?$').firstMatch(s);
  if (m == null) return null;
  final alpha =
      m.group(2) != null ? int.parse(m.group(2)!, radix: 16) << 24 : 0xFF000000;
  return Color(int.parse(m.group(1)!, radix: 16) | alpha);
}
