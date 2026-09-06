import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/mezmur_audio_player.dart';
import '../../services/hymn_store.dart';
import '../../services/mezmur_synced_lyrics.dart';
import '../../services/lyrics_reader_settings.dart';
import 'mezmur_karaoke_view.dart';
import 'mezmur_lyrics_sync_screen.dart';
import 'parchment_style.dart';

/// The lyrics tab of the mezmur player.
///
/// P64 — this screen is a thin shell: it decides WHAT the lyrics are
/// (local row + server refresh, generation-guarded; synced vs static vs
/// none) and renders static text or the empty state. Every pixel of the
/// karaoke experience — fill, depth, predictive scroll, resume pill —
/// lives in [MezmurKaraokeView], built on the pure [KaraokeEngine] and
/// [KaraokeProfile] modules. The parser, the sync editor and static mode
/// are unchanged.
class MezmurLyricsScreen extends StatefulWidget {
  final MezmurTrack track;
  const MezmurLyricsScreen({super.key, required this.track});

  @override
  State<MezmurLyricsScreen> createState() => _MezmurLyricsScreenState();
}

enum _LyricsMode { synced, staticOnly, none }

class _MezmurLyricsScreenState extends State<MezmurLyricsScreen> {
  SyncedLyrics? _doc;
  String? _staticLyrics;
  _LyricsMode _mode = _LyricsMode.none;
  bool _loading = true;
  bool _hadNetworkError = false;

  /// P61 — async-load generation. `_load()` awaits disk + network; a second
  /// `_load()` can start (track change / page churn) before the first
  /// finishes, and the stale response used to paint the PREVIOUS hymn's
  /// lyrics into the current one. Every load takes a ticket; a response
  /// whose ticket is no longer current is dropped.
  int _loadGen = 0;

  /// Cached once: the sync-editor affordance is role-gated and the role
  /// cannot change while this route is alive.
  late final bool _canEdit = HymnStore().canEdit;

  bool get _syncedAvailable =>
      _doc != null && !_doc!.isEmpty && _mode == _LyricsMode.synced;

  // P52 — the app-wide readability model drives the static path here (the
  // karaoke view reads it directly and listens on its own).
  LyricsReaderSettings get _reader => LyricsReaderSettings.instance;

  /// Base lyric font size. Multiplied by the user's text scale (and, on top
  /// of that, by the OS accessibility font size) to give a comfortably
  /// large line.
  static const double _baseLyricSize = 17;

  /// Line height: generous vertical rhythm, a little more in reading mode
  /// and a touch more for large text so it never looks cramped.
  double get _lyricLineHeight {
    final rs = _reader;
    var h = 1.5;
    if (rs.readingMode) h = 1.62;
    h += (rs.textScale - 1.0) * 0.12;
    return h;
  }

  Color get _lyricInk =>
      _reader.highContrast ? Parchment.inkStrong : Parchment.ink;

  void _paintFrom(String synced, String staticText) {
    final parsed = SyncedLyrics.tryParse(synced.trim());
    if (parsed != null && !parsed.isEmpty) {
      _doc = parsed;
      _mode = _LyricsMode.synced;
      _staticLyrics = staticText;
      _loading = false;
    } else if (staticText.trim().isNotEmpty) {
      _doc = null;
      _mode = _LyricsMode.staticOnly;
      _staticLyrics = staticText;
      _loading = false;
    } else {
      _doc = null;
      _mode = _LyricsMode.none;
      _staticLyrics = staticText;
    }
  }

  @override
  void initState() {
    super.initState();
    // Seed from the list row when it already carries lyrics so the
    // first frame is never a spinner waiting on audio or the network.
    _paintFrom(widget.track.lyricsSynced ?? '', widget.track.lyrics ?? '');
    _load();
    _reader.addListener(_onReaderChanged);
  }

  @override
  void didUpdateWidget(MezmurLyricsScreen old) {
    super.didUpdateWidget(old);
    if (old.track.hymnId != widget.track.hymnId) {
      _paintFrom(widget.track.lyricsSynced ?? '', widget.track.lyrics ?? '');
      _load();
    }
  }

  @override
  void dispose() {
    _reader.removeListener(_onReaderChanged);
    super.dispose();
  }

  void _onReaderChanged() {
    if (mounted) setState(() {}); // static path re-sizes; the view listens too
  }

  Future<void> _load() async {
    // P61: every load takes a generation ticket; a later load invalidates
    // this one, and its late response is dropped instead of painted.
    final gen = ++_loadGen;
    // Never blank lyrics that are already on screen. The player must
    // show text while audio is still opening.
    if (_mode == _LyricsMode.none && mounted) {
      setState(() => _loading = true);
    }
    final db = LocalDb();
    var row = await db.getLocalHymn(widget.track.hymnId);
    var synced = (row?['lyrics_synced'] as String?)?.trim() ?? '';
    var staticText = (row?['lyrics'] as String?) ?? '';
    if (synced.isEmpty) synced = (widget.track.lyricsSynced ?? '').trim();
    if (staticText.isEmpty) staticText = widget.track.lyrics ?? '';
    // P50: always refresh timed lyrics from the server when online, not
    // only when the cache is empty. The web player re-fetches the row on
    // every open; the app used to trust a possibly-stale local cache
    // (populated only by the last delta pull), so timings authored on the
    // console after the phone's last sync never appeared. One cheap
    // single-hymn read closes that gap and makes "web works ⇒ app works"
    // structural rather than dependent on a prior sync cycle. Online is
    // an optimization, not a requirement: when offline the cache above is
    // enough, and a failed fetch keeps the cached text on screen.
    if (ConnectivityService().hasLink) {
      try {
        final res = await ApiService().getMezmurHymn(widget.track.hymnId);
        if (res.success && res.data is Map && res.data['item'] is Map) {
          final item = Map<String, dynamic>.from(res.data['item']);
          await db.upsertHymns([item]);
          row = await db.getLocalHymn(widget.track.hymnId);
          synced = ((row?['lyrics_synced'] as String?) ?? synced).trim();
          staticText = (row?['lyrics'] as String?) ?? staticText;
        }
      } catch (_) {
        _hadNetworkError = true;
      }
    }
    if (!mounted || gen != _loadGen) return;
    setState(() {
      _loading = false;
      _paintFrom(synced, staticText);
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _mode == _LyricsMode.none) {
      // Empty parchment while the local row is read — never a spinner
      // sitting in the lyrics box while audio opens.
      return const SizedBox.shrink();
    }
    if (_syncedAvailable) {
      // The whole karaoke experience, self-contained: fills, depth,
      // predictive scroll, resume pill, editor tail.
      return MezmurKaraokeView(
        doc: _doc!,
        onEditTimings: _canEdit ? _openSyncEditor : null,
      );
    }
    if (_mode == _LyricsMode.staticOnly) return _buildStatic();
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              _hadNetworkError
                  ? 'Synced lyrics could not be downloaded — check your connection and try again.'
                  : 'የጊዜ ግጥም ገና አልተጨመሩም።\nNo timed lyrics have been added for this hymn yet.',
              textAlign: TextAlign.center,
              style: const TextStyle(
                  color: Parchment.inkFaint, fontSize: 13, height: 1.6),
            ),
            // P48: curators can author the timings right here. Gated on
            // canEdit, which mirrors the server's role check — the UI
            // hides it, and the API still refuses it, so hiding is a
            // convenience and never the security boundary.
            if (HymnStore().canEdit && _hasStaticLyrics) ...[
              const SizedBox(height: 14),
              TextButton.icon(
                onPressed: _openSyncEditor,
                icon: const Icon(Icons.timer_outlined, size: 18),
                label: const Text('Sync lyrics to audio'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  bool get _hasStaticLyrics =>
      (_staticLyrics ?? '').trim().isNotEmpty ||
      (widget.track.lyrics ?? '').trim().isNotEmpty;

  /// P48: open the tap-to-sync editor, then reload so the new timings
  /// are picked up immediately without leaving the player.
  Future<void> _openSyncEditor() async {
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(
        builder: (_) => MezmurLyricsSyncScreen(track: widget.track),
      ),
    );
    if (saved == true && mounted) {
      await _load();
    }
  }

  Widget _buildStatic() {
    // Static lyrics have no active line, but the reader's size, line-height
    // and contrast still apply so a plain-text hymn is just as readable.
    final size = _baseLyricSize * _reader.textScale;
    final height = _lyricLineHeight + 0.25;
    final ink = _lyricInk;
    return LayoutBuilder(builder: (context, box) {
      final pad = box.maxHeight * 0.18;
      return ParchmentFade(
        child: ListView(
          padding: EdgeInsets.fromLTRB(6, pad, 6, pad),
          children: [
            for (final para in (_staticLyrics ?? '').split(RegExp(r'\n{2,}')))
              if (para.trim().isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: 14),
                  child: Text(
                    para.trim(),
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        color: ink,
                        fontSize: size,
                        height: height,
                        fontWeight: FontWeight.w600,
                        fontFamily: 'NotoSansEthiopic'),
                  ),
                ),
          ],
        ),
      );
    });
  }
}
