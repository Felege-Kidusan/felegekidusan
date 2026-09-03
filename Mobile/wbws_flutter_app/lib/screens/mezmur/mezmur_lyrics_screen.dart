import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../services/api_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/mezmur_audio_player.dart';
import '../../services/mezmur_synced_lyrics.dart';
import 'parchment_style.dart';

/// P0 mezmur — synced (karaoke) lyrics screen.
///
/// Backdrop is the SAME parchment artwork as the player. Timed lines
/// highlight in real time against the audio; the active line is held at the
/// visual centre and tapping any line seeks the player to its timestamp.
///
/// Loading model: timed text lives on the hymn row (lyrics_synced) and is
/// synced lazily — read locally first (offline-friendly), then refreshed
/// from the single-hymn endpoint while online, mirroring how the static
/// lyrics blob is handled elsewhere in the app.
class MezmurLyricsScreen extends StatefulWidget {
  final MezmurTrack track;
  const MezmurLyricsScreen({super.key, required this.track});

  @override
  State<MezmurLyricsScreen> createState() => _MezmurLyricsScreenState();
}

enum _LyricsMode { synced, staticOnly, none }

class _MezmurLyricsScreenState extends State<MezmurLyricsScreen> {
  static const double _lineExtent = 60;

  final MezmurAudioPlayerController _c =
      MezmurAudioPlayerController.instance;
  final ScrollController _scroll = ScrollController();

  Timer? _ticker;
  SyncedLyrics? _doc;
  String? _staticLyrics;
  _LyricsMode _mode = _LyricsMode.none;
  bool _loading = true;
  bool _hadNetworkError = false;
  int _active = -1;

  bool get _syncedAvailable =>
      _doc != null && !_doc!.isEmpty && _mode == _LyricsMode.synced;

  @override
  void initState() {
    super.initState();
    _load();
    _ticker = Timer.periodic(const Duration(milliseconds: 250), (_) {
      _syncActive();
    });
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _mode = _LyricsMode.none;
      _doc = null;
    });
    final db = LocalDb();
    var row = await db.getLocalHymn(widget.track.hymnId);
    var synced = (row?['lyrics_synced'] as String?)?.trim() ?? '';
    final staticText = (row?['lyrics'] as String?) ?? '';
    if (synced.isEmpty && ConnectivityService().hasLink) {
      // Online refresh: single-hymn read carries lyrics_synced + media.
      try {
        final res = await ApiService().getMezmurHymn(widget.track.hymnId);
        if (res.success && res.data is Map && res.data['item'] is Map) {
          final item = Map<String, dynamic>.from(res.data['item']);
          await db.upsertHymns([item]);
          row = await db.getLocalHymn(widget.track.hymnId);
          synced = ((row?['lyrics_synced'] as String?) ?? '').trim();
        }
      } catch (_) {
        _hadNetworkError = true;
      }
    }
    if (!mounted) return;
    setState(() {
      _loading = false;
      _staticLyrics = staticText;
      final parsed = SyncedLyrics.tryParse(synced);
      if (parsed != null && !parsed.isEmpty) {
        _doc = parsed;
        _mode = _LyricsMode.synced;
      } else if (staticText.trim().isNotEmpty) {
        _mode = _LyricsMode.staticOnly;
      } else {
        _mode = _LyricsMode.none;
      }
      _syncActive(force: true);
    });
  }

  void _syncActive({bool force = false}) {
    if (!_syncedAvailable) return;
    final i = _doc!.indexFor(_c.position);
    if (i == _active && !force) return;
    _active = i;
    if (mounted) setState(() {});
    _centerOn(i);
  }

  void _centerOn(int i, {bool animate = true}) {
    if (i < 0 || !_scroll.hasClients) return;
      final maxScroll = _scroll.position.maxScrollExtent;
      // Scroll the i-th item's centre to the viewport centre. ListView
      // content has a small top padding, so drop the half-viewport offset
      // a touch below the exact formula — never past the scroll bounds.
      final target = (i * _lineExtent) - (_scroll.position.viewportDimension / 2) +
          (_lineExtent / 2) + 8;
      final clamped = target.clamp(0.0, maxScroll).toDouble();
    if (animate) {
      _scroll.animateTo(clamped,
          duration: const Duration(milliseconds: 340),
          curve: Curves.easeOutCubic);
    } else {
      _scroll.jumpTo(clamped);
    }
  }

  void _tapLine(int i, SyncedLyricLine line) {
    if (line.isEmpty) return;
    _c.seek(line.time);
    _active = i;
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
        statusBarBrightness: Brightness.dark,
        systemNavigationBarColor: const Color(0xE62A150A),
        systemNavigationBarIconBrightness: Brightness.light,
      ),
      child: ParchmentScaffold(
        topWash: const _EdgeWash(top: true),
        bottomWash: const _EdgeWash(top: false),
        child: SafeArea(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _buildHeader(),
              Expanded(child: _buildBody()),
              _buildFooter(),
            ],
          ),
        ),
      ),
    );
  }

  // ── header ────────────────────────────────────────────────

  Widget _buildHeader() {
    return Container(
      margin: const EdgeInsets.fromLTRB(10, 6, 10, 4),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 5),
      decoration: BoxDecoration(
        color: Parchment.leatherSoft,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Parchment.cream.withOpacity(0.08)),
      ),
      child: Row(
        children: [
          _chipIcon(Icons.arrow_back, 'Back', () {
            Navigator.of(context).maybePop();
          }),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  widget.track.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Parchment.creamBright,
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    fontFamily: 'NotoSansEthiopic',
                  ),
                ),
                const SizedBox(height: 2),
                Row(
                  children: [
                    Container(
                      width: 7,
                      height: 7,
                      decoration: const BoxDecoration(
                        color: Parchment.gold,
                        shape: BoxShape.circle,
                      ),
                    ),
                    const SizedBox(width: 5),
                    Text(
                      _syncedAvailable
                          ? 'Live lyrics · ቀጥታ ግጥም'
                          : (_loading ? 'Loading…' : 'መዝሙር · Mezmur'),
                      style: TextStyle(
                          color: Parchment.cream.withOpacity(0.85),
                          fontSize: 10.5,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 0.4),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ── body ──────────────────────────────────────────────────

  Widget _buildBody() {
    if (_loading) {
      return const Center(
        child: SizedBox(
          width: 24,
          height: 24,
          child: CircularProgressIndicator(
            strokeWidth: 2.2,
            valueColor: AlwaysStoppedAnimation(Parchment.bronze),
          ),
        ),
      );
    }
    if (_syncedAvailable) return _buildSynced();
    if (_mode == _LyricsMode.staticOnly) return _buildStatic();

    // No lyrics at all.
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 44),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.library_music_outlined,
                size: 40, color: Parchment.bronzeSoft),
            const SizedBox(height: 14),
            Text(
              _hadNetworkError
                  ? 'Synced lyrics could not be downloaded — check your connection and try again.'
                  : 'የጊዜ ግጥም (synced lyrics) ገና አልተጨመሩም።\nNo timed lyrics have been added for this hymn yet.',
              textAlign: TextAlign.center,
              style: const TextStyle(
                  color: Parchment.inkFaint,
                  fontSize: 13,
                  height: 1.6),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSynced() {
    final lines = _doc!.lines;
    final itemCount = lines.length;
    return LayoutBuilder(builder: (context, _) {
      return ListView.builder(
        controller: _scroll,
        padding: const EdgeInsets.fromLTRB(34, 18, 34, 26),
        itemExtent: _lineExtent,
        itemCount: itemCount,
        itemBuilder: (context, i) {
          final line = lines[i];
          final isActive = i == _active;
          final isEmpty = line.isEmpty;
          return GestureDetector(
            behavior: HitTestBehavior.opaque,
            onTap: isEmpty ? null : () => _tapLine(i, line),
            child: AnimatedDefaultTextStyle(
              duration: const Duration(milliseconds: 180),
              curve: Curves.easeOut,
              style: TextStyle(
                color: isActive ? Parchment.inkStrong : Parchment.ink,
                fontSize: isActive ? 19.5 : 15.5,
                fontWeight: isActive ? FontWeight.w800 : FontWeight.w500,
                height: 1.0,
                fontFamily: 'NotoSansEthiopic',
              ),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 180),
                alignment: Alignment.center,
                padding: const EdgeInsets.symmetric(horizontal: 10),
                decoration: isActive
                    ? BoxDecoration(
                        color: const Color(0x1FD4AF37), // soft gold wash
                        borderRadius: BorderRadius.circular(10),
                        border: Border(
                          top: BorderSide(
                              color: Parchment.gold.withOpacity(0.55),
                              width: 1),
                          bottom: BorderSide(
                              color: Parchment.gold.withOpacity(0.55),
                              width: 1),
                        ),
                      )
                    : null,
                child: Text(
                  isEmpty ? '· · ·' : line.text,
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ),
          );
        },
      );
    });
  }

  Widget _buildStatic() {
    return ListView(
      controller: _scroll,
      padding: const EdgeInsets.fromLTRB(36, 20, 36, 24),
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: const [
            Icon(Icons.info_outline, size: 14, color: Parchment.bronze),
            SizedBox(width: 6),
            Flexible(
              child: Text(
                'No timed lyrics yet — showing the hymn text.',
                style: TextStyle(
                    color: Parchment.inkFaint,
                    fontSize: 11.5,
                    fontStyle: FontStyle.italic),
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        for (final para in (_staticLyrics ?? '').split(RegExp(r'\n{2,}')))
          if (para.trim().isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
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
      ],
    );
  }

  // ── footer (thin now-playing strip) ───────────────────────

  Widget _buildFooter() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
      child: Material(
        color: Parchment.leather,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: () => Navigator.of(context).maybePop(),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            child: Row(
              children: [
                ListenableBuilder(
                  listenable: _c,
                  builder: (context, _) {
                    return _c.playing
                        ? const Icon(Icons.pause_rounded,
                            color: Parchment.cream, size: 20)
                        : const Icon(Icons.play_arrow_rounded,
                            color: Parchment.cream, size: 20);
                  },
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: ListenableBuilder(
                    listenable: _c,
                    builder: (context, _) {
                      final d = _c.duration;
                      final durMs = (d?.inMilliseconds ?? 0) > 0
                          ? d!.inMilliseconds
                          : (widget.track.durationSeconds ?? 0) * 1000;
                      final ms = _c.position.inMilliseconds
                          .clamp(0, durMs > 0 ? durMs : 0)
                          .toDouble();
                      return ClipRRect(
                        borderRadius: BorderRadius.circular(3),
                        child: LinearProgressIndicator(
                          value: durMs > 0 ? ms / durMs : 0,
                          minHeight: 3,
                          backgroundColor: Parchment.cream.withOpacity(0.18),
                          valueColor: const AlwaysStoppedAnimation(
                              Parchment.gold),
                        ),
                      );
                    },
                  ),
                ),
                const SizedBox(width: 10),
                const Icon(Icons.lyrics_outlined,
                    color: Parchment.gold, size: 18),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _chipIcon(IconData icon, String tooltip, VoidCallback onTap) {
    return Material(
      color: Parchment.leatherSoft,
      shape: const CircleBorder(),
      child: IconButton(
        tooltip: tooltip,
        icon: Icon(icon, color: Parchment.cream),
        iconSize: 21,
        onPressed: onTap,
      ),
    );
  }
}

/// Translucent sepia wash pinned to an extreme edge (never over the paper
/// panel) so cream icons stay legible on the painted ornament.
class _EdgeWash extends StatelessWidget {
  final bool top;
  const _EdgeWash({required this.top});

  @override
  Widget build(BuildContext context) {
    final topPad = MediaQuery.paddingOf(context).top;
    return IgnorePointer(
      child: Container(
        height: top ? 70.0 + topPad : 56.0,
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: top ? Alignment.topCenter : Alignment.bottomCenter,
            end: top ? Alignment.bottomCenter : Alignment.topCenter,
            colors: [
              Parchment.leatherSoft,
              Parchment.leatherSoft.withOpacity(0.0),
            ],
          ),
        ),
      ),
    );
  }
}
