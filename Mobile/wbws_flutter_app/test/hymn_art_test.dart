import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:fkss_app/utils/cover_palette.dart';
import 'package:fkss_app/services/mezmur_audio_player.dart';

/// P66 hymn art — cover color contract tests.
///
/// 1. HASH PARITY (D2 fix): the automatic name-hashed palette must be
///    EXACTLY the web manager's algorithm (mezmur.js hashCode):
///
///        var h = 0;
///        for (...) h = ((h << 5) - h + str.charCodeAt(i)) | 0;
///        return Math.abs(h);
///
///    JS wraps to a SIGNED 32-bit int every iteration and takes the
///    absolute value at the END. The old Dart port masked each step
///    with & 0x7fffffff, which picked a different palette bucket for
///    any name whose hash overflowed int31 — "same category, different
///    gradient on the phone than the web console".
///
///    The expected indexes below were computed by running the ACTUAL JS
///    algorithm over these names (see tests/security/test_mezmur_art.py,
///    which re-verifies the JS side of the contract).
///
/// 2. PRIORITY: art_color (server-extracted dominant color) wins over
///    the automatic palette; invalid colors fall through.
///
/// 3. MezmurTrack carries the art fields from a cached hymn row and
///    hasArt trusts art_status, mirroring hasAudio.
void main() {
  // (name, expected palette index) — from the real JS algorithm.
  const jsCases = <String, int>{
    'አዳም': 0,
    'ሔኖክ': 1,
    'Hymn 2': 2, // overflowed int31: old Dart port said 0
    'አቤል': 3,
    'አብርሃም': 4,
    'መዝሙር አንድ': 5,
    'Hymn 1': 3, // overflowed int31: old Dart port said 5
    'መዝሙር ፩': 2, // overflowed int31: old Dart port said 0
    'Test Hymn': 2,
    'YeMezmur': 2,
    'ጸሎተ ማርያም': 4,
    'Hymn 3': 1,
  };

  // The palette itself, mirrored from mezmur.js PICK_GRADIENTS.
  const palettes = [
    [Color(0xFF5A1212), Color(0xFFD4AF37)],
    [Color(0xFF4f46e5), Color(0xFF7c3aed)],
    [Color(0xFF0ea5e9), Color(0xFF2563eb)],
    [Color(0xFF059669), Color(0xFF0d9488)],
    [Color(0xFFd97706), Color(0xFFdc2626)],
    [Color(0xFFdb2777), Color(0xFF9333ea)],
  ];

  test('hymn cover gradient matches the web (JS hashCode) bucket', () {
    for (final e in jsCases.entries) {
      final got = hymnCoverColors(null, e.key);
      expect(got, palettes[e.value],
          reason:
              "'${e.key}' must use palette #${e.value} (JS hash bucket) — "
              'web and mobile must never disagree');
    }
  });

  test('category auto palette uses the same fixed parity', () {
    // coverColors with no pinned gradient takes the same path.
    expect(coverColors(null, 'Hymn 1'), palettes[3]);
    expect(coverColors(null, 'Hymn 2'), palettes[2]);
    expect(coverColors(const {}, 'አዳም'), palettes[0]);
  });

  test('art_color wins over the automatic palette', () {
    final got = hymnCoverColors({
      'art_status': 'ready',
      'art_color': '#5A1212',
    }, 'Hymn 1');
    // Starts at the server color, shades darker for depth (never a
    // second hue that fights the artwork).
    expect(got.first, const Color(0xFF5A1212));
    // Channel-level check without fragile float expectations: the end
    // stop is darker-or-equal on every channel.
    final s = got.first;
    final e = got.last;
    expect((s.r * 255).round(), greaterThanOrEqualTo((e.r * 255).round()));
    expect((s.g * 255).round(), greaterThanOrEqualTo((e.g * 255).round()));
    expect((s.b * 255).round(), greaterThanOrEqualTo((e.b * 255).round()));
  });

  test('invalid art_color falls back to the name-hash palette', () {
    expect(hymnCoverColors({'art_color': '#zzz'}, 'Hymn 1').first,
        palettes[3].first,
        reason: 'non-hex colors must be ignored, not crash');
    expect(hymnCoverColors({'art_color': null}, 'Hymn 1').first,
        palettes[3].first);
    expect(hymnCoverColors({}, 'Hymn 1').first, palettes[3].first);
  });

  test('MezmurTrack reads the art columns from a cached hymn row', () {
    final t = MezmurTrack.fromHymnRow({
      'id': 7,
      'title': 'መዝሙር ፩',
      'audio_status': 'ready',
      'audio_url': 'https://example/audio',
      'art_status': 'ready',
      'art_color': '#5A1212',
      'art_url': '/uploads/mezmur_art/7/abc_640.jpg?v=123',
      'art_url_medium': '/uploads/mezmur_art/7/abc_320.jpg?v=123',
      'art_url_small': '/uploads/mezmur_art/7/abc_160.jpg?v=123',
    });
    expect(t.hasArt, isTrue);
    expect(t.artColor, '#5A1212');
    expect(t.artUrlSmall, '/uploads/mezmur_art/7/abc_160.jpg?v=123');
    expect(t.artUrlMedium, '/uploads/mezmur_art/7/abc_320.jpg?v=123');

    // copyWith keeps art across an audioUrl swap (the resolve flow).
    final t2 = t.copyWith(audioUrl: 'file:///x.mp3');
    expect(t2.audioUrl, 'file:///x.mp3');
    expect(t2.artUrlSmall, t.artUrlSmall);
    expect(t2.hasArt, isTrue);
  });

  test('MezmurTrack degrades safely without art columns', () {
    final t = MezmurTrack.fromHymnRow({'id': 8, 'title': 'Old row'});
    expect(t.hasArt, isFalse);
    expect(t.artUrl, '');
    expect(t.artColor, isNull);
  });

  test('hasArt trusts art_status, not URL presence (hasAudio rule)', () {
    final t = MezmurTrack.fromHymnRow({
      'id': 9,
      'title': 'x',
      'art_status': 'none',
      // a stale URL must not make a removed cover render
      'art_url_small': '/uploads/mezmur_art/9/old_160.jpg?v=1',
    });
    expect(t.hasArt, isFalse);
  });
}
