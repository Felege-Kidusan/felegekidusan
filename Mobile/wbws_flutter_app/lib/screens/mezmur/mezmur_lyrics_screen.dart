import 'dart:async';

import 'package:flutter/material.dart';

import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/mezmur_audio_player.dart';
import '../../services/hymn_store.dart';
import '../../services/mezmur_synced_lyrics.dart';
import 'mezmur_lyrics_sync_screen.dart';
import 'parchment_style.dart';

/// Lyrics that live INSIDE the parchment ornamental box.
///
/// Timed lines highlight in real time; the active line is held at the
/// visual centre. Extra padding equal to half the viewport lets the
/// first and last lines scroll in from either edge. A ShaderMask fades
/// lines through the in/out points so they never clip hard against the
/// painted frame. Tapping a line seeks the player to its timestamp.
///
/// Loading model: timed text lives on the hymn row (lyrics_synced) and
/// is read locally first (offline-friendly), then refreshed from the
/// single-hymn endpoint while online.
class MezmurLyricsScreen extends StatefulWidget {
  final MezmurTrack track;
  const MezmurLyricsScreen({super.key, required this.track});

  @override
  State<MezmurLyricsScreen> createState() => _MezmurLyricsScreenState();
}

enum _LyricsMode { synced, staticOnly, none }

class _MezmurLyricsScreenState extends State<MezmurLyricsScreen> {
  final MezmurAudioPlayerController _c =
      MezmurAudioPlayerController.instance;
  final ScrollController _scroll = ScrollController();

  Timer? _ticker;
  Timer? _resumeHold;
  SyncedLyrics? _doc;
  String? _staticLyrics;
  _LyricsMode _mode = _LyricsMode.none;
  bool _loading = true;
  bool _hadNetworkError = false;
  bool _userHold = false;
  int _active = -1;
  List<GlobalKey> _keys = const [];

  bool get _syncedAvailable =>
      _doc != null && !_doc!.isEmpty && _mode == _LyricsMode.synced;

  void _paintFrom(String synced, String staticText) {
    final parsed = SyncedLyrics.tryParse(synced.trim());
    if (parsed != null && !parsed.isEmpty) {
      _doc = parsed;
      _mode = _LyricsMode.synced;
      _keys = List<GlobalKey>.generate(
          parsed.lines.length, (_) => GlobalKey());
      _staticLyrics = staticText;
      _loading = false;
    } else if (staticText.trim().isNotEmpty) {
      _doc = null;
      _mode = _LyricsMode.staticOnly;
      _staticLyrics = staticText;
      _keys = const [];
      _loading = false;
    } else {
      _doc = null;
      _mode = _LyricsMode.none;
      _staticLyrics = staticText;
      _keys = const [];
    }
  }

  @override
  void initState() {
    super.initState();
    // Seed from the list row when it already carries lyrics so the
    // first frame is never a spinner waiting on audio or the network.
    _paintFrom(widget.track.lyricsSynced ?? '', widget.track.lyrics ?? '');
    _load();
    _ticker = Timer.periodic(const Duration(milliseconds: 220), (_) {
      _syncActive();
    });
  }

  @override
  void didUpdateWidget(MezmurLyricsScreen old) {
    super.didUpdateWidget(old);
    if (old.track.hymnId != widget.track.hymnId) {
      _userHold = false;
      _resumeHold?.cancel();
      _paintFrom(widget.track.lyricsSynced ?? '', widget.track.lyrics ?? '');
      _load();
    }
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _resumeHold?.cancel();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
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
    if (!mounted) return;
    setState(() {
      _loading = false;
      _paintFrom(synced, staticText);
      _syncActive(force: true);
    });
  }

  void _syncActive({bool force = false}) {
    if (!_syncedAvailable) return;
    final i = _doc!.indexFor(_c.position);
    if (i == _active && !force) return;
    _active = i;
    if (mounted) setState(() {});
    if (!_userHold) _centerOn(i);
  }

  void _centerOn(int i) {
    if (i < 0 || i >= _keys.length) return;
    final ctx = _keys[i].currentContext;
    if (ctx == null) return;
    final reduce = MediaQuery.of(this.context).disableAnimations;
    Scrollable.ensureVisible(
      ctx,
      alignment: 0.5,
      alignmentPolicy: ScrollPositionAlignmentPolicy.explicit,
      duration: reduce
          ? Duration.zero
          : const Duration(milliseconds: 420),
      curve: Curves.easeInOutCubic,
    );
  }

  void _tapLine(int i, SyncedLyricLine line) {
    if (line.isEmpty) return;
    _c.seek(line.time);
    _active = i;
    _userHold = false;
    if (mounted) setState(() {});
    _centerOn(i);
  }

  void _onUserScroll(ScrollNotification n) {
    if (n is ScrollStartNotification && n.dragDetails != null) {
      _userHold = true;
      _resumeHold?.cancel();
    } else if (n is ScrollEndNotification && _userHold) {
      _resumeHold?.cancel();
      _resumeHold = Timer(const Duration(milliseconds: 2200), () {
        if (!mounted) return;
        _userHold = false;
        _centerOn(_active);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _mode == _LyricsMode.none) {
      // Empty parchment while the local row is read — never a spinner
      // sitting in the lyrics box while audio opens.
      return const SizedBox.shrink();
    }
    if (_syncedAvailable) return _buildSynced();
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
      _userHold = false;
      await _load();
    }
  }

  Widget _buildSynced() {
    final lines = _doc!.lines;
    // Long-press anywhere on the timed lyrics to re-open the editor.
    // Deliberately not a visible button: the reading view stays clean
    // for the 99% of users who never edit.
    return LayoutBuilder(builder: (context, box) {
      final pad = box.maxHeight * 0.42;
      return ParchmentFade(
        child: NotificationListener<ScrollNotification>(
          onNotification: (n) {
            _onUserScroll(n);
            return false;
          },
          child: ListView.builder(
            controller: _scroll,
            cacheExtent: 4000,
            padding: EdgeInsets.fromLTRB(6, pad, 6, pad),
            itemCount: lines.length,
            itemBuilder: (context, i) {
              final line = lines[i];
              final isActive = i == _active;
              final isPast = i < _active;
              final isEmpty = line.isEmpty;
              return KeyedSubtree(
                key: _keys[i],
                child: GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTap: isEmpty ? null : () => _tapLine(i, line),
                  child: AnimatedDefaultTextStyle(
                    duration: const Duration(milliseconds: 220),
                    curve: Curves.easeOut,
                    style: TextStyle(
                      color: isActive
                          ? Parchment.inkStrong
                          : isPast
                              ? Parchment.inkFaint
                              : Parchment.ink,
                      fontSize: isActive ? 18.5 : 15.5,
                      fontWeight:
                          isActive ? FontWeight.w800 : FontWeight.w500,
                      height: 1.55,
                      fontFamily: 'NotoSansEthiopic',
                    ),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                          vertical: 8, horizontal: 4),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          AnimatedContainer(
                            duration: const Duration(milliseconds: 220),
                            width: 3,
                            height: isActive ? 22 : 0,
                            margin: const EdgeInsets.only(top: 4, right: 8),
                            decoration: BoxDecoration(
                              color: Parchment.bronze,
                              borderRadius: BorderRadius.circular(2),
                            ),
                          ),
                          Expanded(
                            child: Text(
                              isEmpty ? '· · ·' : line.text,
                              textAlign: TextAlign.center,
                            ),
                          ),
                          const SizedBox(width: 11),
                        ],
                      ),
                    ),
                  ),
                ),
              );
            },
          ),
        ),
      );
    });
  }

  Widget _buildStatic() {
    return LayoutBuilder(builder: (context, box) {
      final pad = box.maxHeight * 0.18;
      return ParchmentFade(
        child: ListView(
          controller: _scroll,
          padding: EdgeInsets.fromLTRB(10, pad, 10, pad),
          children: [
            for (final para in (_staticLyrics ?? '').split(RegExp(r'\n{2,}')))
              if (para.trim().isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: 14),
                  child: Text(
                    para.trim(),
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        color: Parchment.ink,
                        fontSize: 16,
                        height: 1.85,
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
