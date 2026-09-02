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

  const palettes = [
    [Color(0xFF5A1212), Color(0xFFD4AF37)],
    [Color(0xFF4f46e5), Color(0xFF7c3aed)],
    [Color(0xFF0ea5e9), Color(0xFF2563eb)],
    [Color(0xFF059669), Color(0xFF0d9488)],
    [Color(0xFFd97706), Color(0xFFdc2626)],
    [Color(0xFFdb2777), Color(0xFF9333ea)],
  ];
  var h = 0;
  for (final c in name.codeUnits) {
    h = ((h << 5) - h + c) & 0x7fffffff;
  }
  return palettes[h % palettes.length];
}

/// Strict '#rrggbb' parser (mirrors the server validator).
Color? _hex(dynamic v) {
  final s = (v ?? '').toString().trim();
  final m = RegExp(r'^#?([0-9a-fA-F]{6})$').firstMatch(s);
  if (m == null) return null;
  return Color(int.parse(m.group(1)!, radix: 16) | 0xFF000000);
}
