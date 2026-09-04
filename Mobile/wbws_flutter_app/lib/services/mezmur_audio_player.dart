import 'dart:async';

import 'package:audio_session/audio_session.dart';
import 'package:audio_service/audio_service.dart';
import 'package:flutter/foundation.dart';
import 'package:just_audio/just_audio.dart';
import 'package:permission_handler/permission_handler.dart';

/// P0 mezmur — a single mezmur hymn that can be played.
class MezmurTrack {
  final int hymnId;
  final String title;
  final String audioUrl;
  final String? category;
  final int? durationSeconds;
  final String? lyrics;
  final String? lyricsSynced;

  const MezmurTrack({
    required this.hymnId,
    required this.title,
    required this.audioUrl,
    this.category,
    this.durationSeconds,
    this.lyrics,
    this.lyricsSynced,
  });

  static int _asInt(dynamic v) => v is int ? v : int.tryParse('$v') ?? 0;
  static int? _asIntOrNull(dynamic v) {
    if (v == null) return null;
    final n = _asInt(v);
    return n <= 0 ? null : n;
  }

  /// Builds a playable track from a cached-hymn row (the row carries the
  /// audio_* columns added by the v20 local-DB migration).
  factory MezmurTrack.fromHymnRow(Map<String, dynamic> row) {
    return MezmurTrack(
      hymnId: _asInt(row['id']),
      title: '${row['title'] ?? ''}',
      audioUrl: '${row['audio_url'] ?? ''}',
      category: row['category'] is String ? row['category'] as String : null,
      durationSeconds: _asIntOrNull(row['audio_duration_s']),
      lyrics: row['lyrics'] is String ? row['lyrics'] as String : null,
      lyricsSynced:
          row['lyrics_synced'] is String ? row['lyrics_synced'] as String : null,
    );
  }

  /// True when a hymn row is verified-ready AND has a public URL — the two
  /// conditions that make it playable without a server round trip.
  static bool audioReady(Map<String, dynamic> row) =>
      '${row['audio_status'] ?? ''}' == 'ready' &&
      '${row['audio_url'] ?? ''}'.trim().isNotEmpty;

  /// Media metadata shown in the lock screen / notification.
  MediaItem toMediaItem() {
    return MediaItem(
      id: 'mz-$hymnId',
      title: title.isEmpty ? 'መዝሙር $hymnId' : title,
      album: category ?? 'FKSS መዝሙር',
      artist: 'FKSS · Mezmur',
      duration: durationSeconds == null
          ? null
          : Duration(seconds: durationSeconds!),
    );
  }
}

/// P0 mezmur — single playback engine for the whole app.
///
/// Playback requirements land on just_audio + just_audio_background:
///  - STREAMING: hymns play straight from the R2 public URL, no download.
///  - BACKGROUND: just_audio_background (audio_service under the hood)
///    keeps the auto-managed player alive when the app leaves the screen
///    and exposes the media notification + lock-screen transport
///    (play/pause/skip/seek), headset buttons and media buttons.
///  - QUEUE: opening from a filtered hymn list hands the player the ready
///    rows of the CURRENT view so previous/next match what the user sees.
///
/// IMPORTANT (plugin contract): just_audio_background manages exactly ONE
/// AudioPlayer — this class owns the single instance and never disposes it,
/// so every open/play goes through [openQueue].
class MezmurAudioPlayerController extends ChangeNotifier {
  MezmurAudioPlayerController._() {
    // Mirrors the plugin-managed state so plain widgets can simply
    // `addListener` on this controller (no stream plumbing in screens).
    _player.positionStream.listen((p) {
      if (p.inMilliseconds ~/ 500 != _posMs ~/ 500) {
        _posMs = p.inMilliseconds;
        notifyListeners();
      } else {
        _posMs = p.inMilliseconds;
      }
    });
    _player.currentIndexStream.listen((i) {
      if (i == _index) return;
      _index = i;
      // Headset / lock-screen skip moves the audio queue. Mirror that
      // hymn into the visible catalog index so the parchment page and
      // mini-player stay in lockstep (no-audio rows are only visited
      // by in-app next/prev/swipe).
      if (i != null && i >= 0 && i < _queue.length && _catalog.isNotEmpty) {
        final id = _queue[i].hymnId;
        final vi = _catalog.indexWhere((t) => t.hymnId == id);
        if (vi >= 0) _viewIndex = vi;
      }
      notifyListeners();
    });
    _player.durationStream.listen((d) {
      final ms = d?.inMilliseconds ?? 0;
      if (ms == _durMs) return;
      _durMs = ms;
      notifyListeners();
    });
    _player.playerStateStream.listen((s) {
      final playing = s.playing;
      final buffering = s.processingState == ProcessingState.buffering ||
          s.processingState == ProcessingState.loading;
      if (playing == _playing && buffering == _buffering &&
          _completed == (s.processingState == ProcessingState.completed)) {
        return;
      }
      _playing = playing;
      _buffering = buffering;
      _completed = s.processingState == ProcessingState.completed;
      notifyListeners();
    });
    _player.errorStream.listen((e) {
      if (kDebugMode) debugPrint('mezmur-audio error: $e');
    });
  }

  /// The parchment artwork that backs the player + lyrics screens.
  static const String bgAsset = 'assets/parchment_hymn_bg.jpg';

  static final MezmurAudioPlayerController instance =
      MezmurAudioPlayerController._();

  final AudioPlayer _player = AudioPlayer();
  List<MezmurTrack> _queue = const [];
  int? _index;
  bool _playing = false;
  bool _buffering = false;
  bool _completed = false;
  bool _configured = false;
  int _posMs = 0;
  int _durMs = 0;
  int _loop = 0; // 0 off, 1 all, 2 one
  bool _shuffle = false;
  double _rate = 1;
  List<MezmurTrack> _catalog = const [];
  int _viewIndex = 0;
  bool _playerVisible = false;
  bool _miniDismissed = false;

  // ── public state ───────────────────────────────────────────

  /// Stable identity of the visible catalog (list order, audio optional).
  String get sessionKey => _catalog.map((t) => 'mz-${t.hymnId}').join(',');

  List<MezmurTrack> get queue => List.unmodifiable(_queue);
  List<MezmurTrack> get catalog => List.unmodifiable(_catalog);
  int? get index => _index;
  int get viewIndex => _viewIndex;
  bool get hasQueue => _queue.isNotEmpty;
  bool get hasCatalog => _catalog.isNotEmpty;
  bool get playing => _playing;
  bool get buffering => _buffering;
  bool get completed => _completed;
  Duration get position => Duration(milliseconds: _posMs);
  Duration? get duration =>
      _durMs > 0 ? Duration(milliseconds: _durMs) : null;
  bool get canPrevious => _catalog.length > 1 || _viewIndex > 0;
  bool get canNext =>
      _catalog.length > 1 && (_loop == 1 || _viewIndex < _catalog.length - 1);
  int get loopMode => _loop;
  bool get shuffle => _shuffle;
  double get rate => _rate;
  static const List<double> rates = [0.75, 1, 1.25, 1.5];
  bool get playerVisible => _playerVisible;
  bool get viewHasAudio =>
      (viewTrack?.audioUrl.trim().isNotEmpty ?? false);

  /// Mini bar: session exists, full player is not on screen, and either
  /// audio is playing or the current hymn can still be resumed.
  bool get showMiniPlayer =>
      !_playerVisible &&
      !_miniDismissed &&
      _catalog.isNotEmpty &&
      (_playing || viewHasAudio);

  MezmurTrack? get currentTrack {
    final i = _index;
    if (i == null || i < 0 || i >= _queue.length) return null;
    return _queue[i];
  }

  /// The hymn the UI is showing — independent of the audio engine, so a
  /// lyrics-only row can sit between two playable ones.
  MezmurTrack? get viewTrack {
    if (_catalog.isEmpty) return currentTrack;
    if (_viewIndex < 0 || _viewIndex >= _catalog.length) return null;
    return _catalog[_viewIndex];
  }

  void setPlayerVisible(bool v) {
    if (_playerVisible == v) return;
    _playerVisible = v;
    if (v) _miniDismissed = false;
    notifyListeners();
  }

  void dismissMiniPlayer() {
    _miniDismissed = true;
    notifyListeners();
  }

  // ── playback control ───────────────────────────────────────

  Future<void> _ensureConfigured() async {
    if (_configured) return;
    // Android 13+ hides the media notification — and with it the
    // lock-screen transport and headset controls — unless
    // POST_NOTIFICATIONS is granted. Declaring the permission in the
    // manifest is not enough; it is a runtime permission. Asked once,
    // before the first play, and a refusal only costs the notification:
    // audio itself keeps playing.
    await _ensureNotificationPermission();
    try {
      // Music-grade session: interrupts other audio apps; other apps
      // duck/pause us appropriately (headphones out → just_audio pauses
      // on its own via the session's becomingNoisy handling).
      final session = await AudioSession.instance;
      await session.configure(const AudioSessionConfiguration.music());
      _configured = true;
    } catch (_) {
      // Session config is advisory; playback still works with defaults.
      _configured = true;
    }
  }

  bool _notificationAsked = false;

  Future<void> _ensureNotificationPermission() async {
    if (_notificationAsked) return;
    _notificationAsked = true;
    try {
      final status = await Permission.notification.status;
      if (!status.isGranted) {
        await Permission.notification.request();
      }
    } catch (_) {
      // No plugin on this platform, or the host denies the dialog.
      // Playback is unaffected — only the shutter is missing.
    }
  }

  /// Opens the user-visible list (audio optional) at [startIndex]. The
  /// audio engine only loads rows with a URL; skip/swipe still walk every
  /// row. Re-entry with the same catalog and hymn does not restart audio.
  Future<bool> openCatalog(
    List<MezmurTrack> tracks, {
    int startIndex = 0,
    bool autoPlay = true,
  }) async {
    if (tracks.isEmpty) return false;
    await _ensureConfigured();
    final key = tracks.map((t) => 'mz-${t.hymnId}').join(',');
    final same = key == sessionKey && _catalog.isNotEmpty;
    _catalog = List<MezmurTrack>.from(tracks);
    _viewIndex = startIndex.clamp(0, _catalog.length - 1);
    _miniDismissed = false;
    notifyListeners();
    if (same && viewTrack?.hymnId == currentTrack?.hymnId && viewHasAudio) {
      return true;
    }
    return _syncAudio(autoPlay: autoPlay, forceReload: !same);
  }

  /// Jump to a catalog row. Playable rows start audio; lyrics-only rows
  /// pause so next/prev never skip them.
  Future<void> moveTo(int index, {bool autoPlay = true}) async {
    if (_catalog.isEmpty) return;
    if (index < 0 || index >= _catalog.length) return;
    _viewIndex = index;
    _miniDismissed = false;
    notifyListeners();
    await _syncAudio(autoPlay: autoPlay);
  }

  Future<void> skipView(int delta) async {
    if (_catalog.isEmpty) return;
    final viewingPlaying = viewHasAudio &&
        currentTrack?.hymnId == viewTrack?.hymnId &&
        _playing;
    if (delta < 0 &&
        viewingPlaying &&
        position > const Duration(seconds: 3)) {
      await seek(Duration.zero);
      return;
    }
    var i = _viewIndex + delta;
    if (_loop == 1 && _catalog.isNotEmpty) {
      i = (i % _catalog.length + _catalog.length) % _catalog.length;
    } else {
      if (i < 0) i = 0;
      if (i >= _catalog.length) i = _catalog.length - 1;
    }
    if (i == _viewIndex) {
      if (delta < 0 && viewingPlaying) await seek(Duration.zero);
      return;
    }
    await moveTo(i, autoPlay: true);
  }

  Future<bool> _syncAudio({
    required bool autoPlay,
    bool forceReload = false,
  }) async {
    final v = viewTrack;
    if (v == null) return false;
    if (v.audioUrl.trim().isEmpty) {
      try {
        await _player.pause();
      } catch (_) {}
      notifyListeners();
      return true;
    }
    if (!forceReload &&
        currentTrack?.hymnId == v.hymnId &&
        _queue.isNotEmpty) {
      if (autoPlay) await play();
      return true;
    }
    final playable = _catalog
        .where((t) => t.audioUrl.trim().isNotEmpty)
        .toList(growable: false);
    final si = playable.indexWhere((t) => t.hymnId == v.hymnId);
    if (si < 0) return false;
    var samePlayable = playable.length == _queue.length;
    if (samePlayable) {
      for (var i = 0; i < playable.length; i++) {
        if (playable[i].hymnId != _queue[i].hymnId) {
          samePlayable = false;
          break;
        }
      }
    }
    if (samePlayable && _queue.isNotEmpty) {
      try {
        await _player.seek(Duration.zero, index: si);
        _index = si;
        if (autoPlay) await _player.play();
        notifyListeners();
        return true;
      } catch (_) {}
    }
    return openQueue(playable, startIndex: si, autoPlay: autoPlay);
  }

  /// Loads [tracks] (only rows with a non-empty URL play) and starts at
  /// [startIndex]. Returns false when nothing could be loaded.
  Future<bool> openQueue(
    List<MezmurTrack> tracks, {
    int startIndex = 0,
    bool autoPlay = true,
  }) async {
    await _ensureConfigured();
    if (tracks.isEmpty) return false;
    final playable = <MezmurTrack>[];
    final sources = <AudioSource>[];
    var offset = -1;
    for (var i = 0; i < tracks.length; i++) {
      final t = tracks[i];
      if (t.audioUrl.trim().isEmpty) continue;
      sources.add(AudioSource.uri(
        Uri.parse(t.audioUrl),
        tag: t.toMediaItem(),
      ));
      playable.add(t);
      if (i == startIndex) offset = playable.length - 1;
    }
    if (sources.isEmpty) return false;
    if (offset < 0) offset = 0;
    try {
      await _player.stop();
      _queue = playable;
      _index = null;
      _durMs = 0;
      notifyListeners();
      await _player.setAudioSources(sources,
          initialIndex: offset, initialPosition: Duration.zero);
      _index = offset;
      if (_rate != 1) {
        try {
          await _player.setSpeed(_rate);
        } catch (_) {}
      }
      if (autoPlay) await _player.play();
      notifyListeners();
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<void> play() async {
    if (_queue.isNotEmpty && _completed) {
      await seek(Duration.zero);
    }
    await _player.play();
  }

  Future<void> pause() => _player.pause();

  Future<void> toggle() => _playing ? _player.pause() : play();

  Future<void> seek(Duration to) async {
    try {
      await _player.seek(to);
    } catch (_) {}
  }

  Future<void> seekBy(Duration delta) async {
    final cap = duration ?? Duration.zero;
    var t = position + delta;
    if (t < Duration.zero) t = Duration.zero;
    if (cap > Duration.zero && t > cap) t = cap;
    await seek(t);
  }

  Future<void> cycleLoop() async {
    _loop = (_loop + 1) % 3;
    try {
      await _player.setLoopMode(_loop == 2
          ? LoopMode.one
          : _loop == 1
              ? LoopMode.all
              : LoopMode.off);
    } catch (_) {}
    notifyListeners();
  }

  Future<void> toggleShuffle() async {
    _shuffle = !_shuffle;
    try {
      await _player.setShuffleModeEnabled(_shuffle);
    } catch (_) {}
    notifyListeners();
  }

  Future<void> setRate(double r) async {
    _rate = r;
    try {
      await _player.setSpeed(r);
    } catch (_) {}
    notifyListeners();
  }

  /// In-app / lock-screen next: walk the visible catalog (audio optional).
  Future<void> next() => skipView(1);

  /// Industry previous: restart if this hymn has been playing > 3s,
  /// otherwise go to the previous catalog row (audio optional).
  Future<void> previous() => skipView(-1);

  /// Stops streaming + drops the queue (used when leaving for good).
  Future<void> stopAndClear() async {
    try {
      await _player.stop();
    } catch (_) {}
    _queue = const [];
    _catalog = const [];
    _index = null;
    _viewIndex = 0;
    _playing = false;
    _buffering = false;
    _durMs = 0;
    _posMs = 0;
    _miniDismissed = true;
    notifyListeners();
  }
}
