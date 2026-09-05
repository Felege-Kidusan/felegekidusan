import 'dart:async';

import 'package:audio_session/audio_session.dart';
import 'package:audio_service/audio_service.dart';
import 'package:flutter/foundation.dart';
import 'package:just_audio/just_audio.dart';
import 'package:permission_handler/permission_handler.dart';

import 'api_service.dart';
import 'mezmur_download_manager.dart';
import 'mezmur_playback_policy.dart';
import 'mezmur_queue_window.dart';
import 'mezmur_transport_gate.dart';

/// P0 mezmur — a single mezmur hymn that can be played.
class MezmurTrack {
  final int hymnId;
  final String title;
  final String audioUrl;
  final String audioStatus;
  final String? category;
  final int? durationSeconds;
  final String? lyrics;
  final String? lyricsSynced;

  const MezmurTrack({
    required this.hymnId,
    required this.title,
    required this.audioUrl,
    this.audioStatus = 'none',
    this.category,
    this.durationSeconds,
    this.lyrics,
    this.lyricsSynced,
  });

  /// P36: whether this hymn HAS audio — the honest playability signal.
  ///
  /// `audioUrl` must NOT be used for this. The server never returns an
  /// `audio_url` column; playback URLs are short-lived links minted on
  /// demand by `GET /mezmur/audio/{id}`. A streamed hymn therefore
  /// carries an EMPTY audioUrl until the moment it is resolved, so any
  /// URL-based check misclassifies every not-yet-downloaded hymn as
  /// lyrics-only. `audio_status == 'ready'` is the cached authoritative
  /// field; a non-empty URL additionally covers already-resolved and
  /// local `file://` tracks.
  bool get hasAudio =>
      audioStatus.trim().toLowerCase() == 'ready' ||
      audioUrl.trim().isNotEmpty;

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
      audioStatus: '${row['audio_status'] ?? 'none'}',
      category: row['category'] is String ? row['category'] as String : null,
      durationSeconds: _asIntOrNull(row['audio_duration_s']),
      lyrics: row['lyrics'] is String ? row['lyrics'] as String : null,
      lyricsSynced:
          row['lyrics_synced'] is String ? row['lyrics_synced'] as String : null,
    );
  }

  MezmurTrack copyWith({String? audioUrl}) => MezmurTrack(
        hymnId: hymnId,
        title: title,
        audioUrl: audioUrl ?? this.audioUrl,
        audioStatus: audioStatus,
        category: category,
        durationSeconds: durationSeconds,
        lyrics: lyrics,
        lyricsSynced: lyricsSynced,
      );

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
        // P36: the engine queue is a WINDOW, so its index is not the
        // catalog row. Prefer the explicit map and fall back to a hymn-id
        // lookup.
        var vi = (i < _queueRows.length) ? _queueRows[i] : -1;
        if (vi < 0 || vi >= _catalog.length ||
            _catalog[vi].hymnId != _queue[i].hymnId) {
          vi = _catalog.indexWhere((t) => t.hymnId == _queue[i].hymnId);
        }
        if (vi >= 0) _viewIndex = vi;
      }
      notifyListeners();
      // Advancing may have moved us near the edge of the resolved window.
      unawaited(_ensureWindow());
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
      final nowCompleted = s.processingState == ProcessingState.completed;
      final justCompleted = nowCompleted && !_completed;
      _completed = nowCompleted;
      notifyListeners();
      if (justCompleted) _handleCompletion();
    });
    _player.errorStream.listen((e) {
      if (kDebugMode) debugPrint('mezmur-audio error: $e');
      _sourceLoaded = false;
      _playbackError = 'Audio could not be played. Check your connection and try again.';
      notifyListeners();
    });
  }

  /// P36: the queue has run out.
  ///
  /// just_audio's contract is that `playing` stays true until `pause` or
  /// `stop` is called — so at the end of the last track the player sits
  /// at playing==true with processingState==completed and the position
  /// pinned at the end. Nothing reset it, so the transport button showed
  /// "pause" forever and tapping it did nothing audible.
  ///
  /// The documented remedy is pause + seek(zero). It is deferred with a
  /// zero-duration timer because just_audio's event subject is
  /// `sync: true`: calling back into the player from inside its own state
  /// listener risks "Cannot fire new event. Controller is already firing
  /// an event".
  void _handleCompletion() {
    Future<void>.delayed(Duration.zero, () async {
      if (!_completed) return; // superseded by a new command
      // With loop-all/loop-one just_audio wraps on its own.
      if (_loop != 0) return;
      // P36: the window may end before the catalog does. If a playable
      // hymn remains beyond the resolved window, advance to it rather
      // than stopping early — otherwise playback halts at the window
      // edge instead of the real end of the list.
      final idx = _index;
      if (idx != null && idx >= 0 && idx < _queueRows.length) {
        final finishedRow = _queueRows[idx];
        final target = MezmurPlaybackPolicy.nextRow(
            _audioFlags, finishedRow, _loop);
        if (target != finishedRow) {
          await moveTo(target, autoPlay: true);
          return;
        }
      }
      try {
        await _player.pause();
        await _player.seek(Duration.zero, index: _index);
      } catch (_) {}
      _wantPlaying = false;
      _playing = false;
      notifyListeners();
    });
  }

  /// The parchment artwork that backs the player + lyrics screens.
  static const String bgAsset = 'assets/parchment_hymn_bg.jpg';

  static final MezmurAudioPlayerController instance =
      MezmurAudioPlayerController._();

  final AudioPlayer _player = AudioPlayer();
  final ApiService _api = ApiService();
  final MezmurDownloadManager _downloads = MezmurDownloadManager.instance;
  List<MezmurTrack> _queue = const [];
  int? _index;
  bool _playing = false;
  bool _buffering = false;
  bool _completed = false;
  bool _sourceLoaded = false;
  // ── transport serialisation ────────────────────────────────
  //
  // P34 — the play/pause-once bug.
  //
  // just_audio's `play()` Future does NOT complete when playback starts;
  // per the plugin contract it "completes when the playback completes or
  // is paused or stopped". Any `await _player.play()` inside a
  // try/finally therefore holds that frame open for the whole track.
  // The previous implementation guarded transport with a
  // `_controlInFlight` boolean cleared in a `finally`, so after the first
  // play the flag stayed latched for minutes and every later play(),
  // pause() and toggle() hit a silent early-return — the visible "works
  // once, then dead" symptom, and the reason the mini-player's close
  // button could not stop audio.
  //
  // Fix, matching the just_audio example app and the guidance in
  // just_audio#265 / AudioKit#2646: never await play(); treat the engine
  // as the single source of truth for `playing` (via playerStateStream)
  // and serialise only the async *setup* work through one queue. Intent
  // is a monotonic generation counter so a stale load can never override
  // a newer user gesture.
  Future<void> _commandChain = Future<void>.value();
  int _commandVersion = 0;
  bool _wantPlaying = false;
  String? _playbackError;
  bool _configured = false;
  int _posMs = 0;
  int _durMs = 0;
  int _loop = 0; // 0 off, 1 all, 2 one
  bool _shuffle = false;
  double _rate = 1;
  List<MezmurTrack> _catalog = <MezmurTrack>[];

  /// P36: catalog row index for each entry of [_queue]. The engine queue
  /// is a WINDOW over the catalog, so engine index != catalog row and the
  /// two must be mapped explicitly.
  List<int> _queueRows = const [];

  /// Guard so overlapping stream events cannot slide the window twice.
  bool _windowRefreshing = false;
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
  String? get playbackError => _playbackError;
  bool get canPlay => _sourceLoaded && _index != null && _queue.isNotEmpty;
  bool get canPlayCurrentView =>
      canPlay && currentTrack?.hymnId == viewTrack?.hymnId;
  bool get canPlayControl => viewHasAudio || canPlay;
  Duration get position => Duration(milliseconds: _posMs);
  Duration? get duration =>
      _durMs > 0 ? Duration(milliseconds: _durMs) : null;

  /// Whether each catalog row HAS audio, from `audio_status` (P36) —
  /// never from `audioUrl`, which is empty for any hymn whose signed URL
  /// has not been minted yet.
  ///
  /// This is the display/navigation truth the pure [MezmurPlaybackPolicy]
  /// reasons over. It is deliberately a SUPERSET of what the engine holds
  /// at any instant: the engine carries a sliding window of resolved
  /// sources (see [MezmurQueueWindow]) while these flags describe the
  /// whole catalog. Navigation targets come from the flags; the window
  /// follows.
  List<bool> get _audioFlags => List<bool>.generate(
        _catalog.length,
        (i) => _catalog[i].hasAudio,
        growable: false,
      );

  bool get canPrevious =>
      _catalog.length > 1 &&
      MezmurPlaybackPolicy.canGoPrevious(
          _audioFlags, _viewIndex, _loop);

  bool get canNext =>
      MezmurPlaybackPolicy.canGoNext(_audioFlags, _viewIndex, _loop);
  int get loopMode => _loop;
  bool get shuffle => _shuffle;
  double get rate => _rate;
  static const List<double> rates = [0.75, 1, 1.25, 1.5];
  bool get playerVisible => _playerVisible;
  bool get viewHasAudio => viewTrack?.hasAudio ?? false;

  /// Mini bar: session exists, full player is not on screen, and either
  /// audio is playing or the current hymn can still be resumed.
  bool get showMiniPlayer => MezmurTransportGate.showMiniPlayer(
        playerVisible: _playerVisible,
        dismissed: _miniDismissed,
        hasCatalog: _catalog.isNotEmpty,
        playing: _playing,
        hasQueue: _queue.isNotEmpty,
        viewHasAudio: viewHasAudio,
      );

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
      return canPlayCurrentView;
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

  /// Moves to the adjacent VISIBLE catalog row (+/-1), then tells the audio
  /// engine to follow. Next/previous and swipe always land on the very next
  /// hymn in the list — with or without audio — so a lyrics-only hymn is a
  /// real neighbour (never skipped, never mangled by the audio-only queue).
  ///
  /// Previous uses the industry rule: if the selected hymn is a playable one
  /// that is currently playing past the restart threshold, it restarts it in
  /// place; otherwise it steps back one visible row.
  Future<void> skipView(int delta) async {
    if (_catalog.isEmpty) return;
    if (delta < 0) {
      final decision = MezmurPlaybackPolicy.previousTarget(
        _audioFlags,
        _viewIndex,
        loop: _loop,
        isPlaying: _playing,
        positionMs: position.inMilliseconds,
      );
      if (decision.restartCurrent) {
        await seek(Duration.zero);
        return;
      }
      if (decision.targetRow == _viewIndex) return; // first row, loop off
      await moveTo(decision.targetRow, autoPlay: decision.shouldAutoPlay);
      return;
    }
    final target = MezmurPlaybackPolicy.nextRow(_audioFlags, _viewIndex, _loop);
    if (target == _viewIndex) return; // last row, loop off
    await moveTo(target, autoPlay: true);
  }

  Future<bool> _syncAudio({
    required bool autoPlay,
    bool forceReload = false,
  }) async {
    final v = viewTrack;
    if (v == null) return false;
    // P36: a hymn with no audio at all (lyrics-only) simply stops the
    // engine. This MUST test hasAudio, not audioUrl — a streamed hymn
    // has an empty URL until it is resolved, and testing the URL made
    // every not-yet-downloaded hymn look lyrics-only, so skipping to one
    // silently paused instead of playing it.
    if (!v.hasAudio) {
      try {
        await _player.pause();
      } catch (_) {}
      notifyListeners();
      return true;
    }
    if (!forceReload &&
        currentTrack?.hymnId == v.hymnId &&
        canPlayCurrentView) {
      if (autoPlay) await play();
      return true;
    }
    // Already inside the resolved window: seek within the engine queue
    // instead of rebuilding it, so short skips stay instant.
    if (!forceReload && _queue.isNotEmpty) {
      final qi = _queue.indexWhere((t) => t.hymnId == v.hymnId);
      if (qi >= 0) {
        try {
          await _player.seek(Duration.zero, index: qi);
          _index = qi;
          if (autoPlay) {
            _wantPlaying = true;
            unawaited(_player.play().catchError((_) {}));
          }
          notifyListeners();
          unawaited(_ensureWindow());
          return true;
        } catch (_) {}
      }
    }
    // Outside the window (or a forced reload): rebuild it centred on the
    // hymn the listener actually wants.
    return openQueue(_catalog, startIndex: _viewIndex, autoPlay: autoPlay);
  }

  /// Slides the resolved window forward as playback advances (P36).
  ///
  /// just_audio only advances within the sources it already holds, so the
  /// window must be topped up before the listener reaches its edge —
  /// otherwise gapless advance and the lock-screen next-track button stop
  /// at the last resolved hymn.
  Future<void> _ensureWindow() async {
    if (_catalog.isEmpty || _queueRows.isEmpty) return;
    if (_windowRefreshing) return;
    final flags = _audioFlags;
    if (!MezmurQueueWindow.needsRefresh(
      resolvedRows: _queueRows,
      currentRow: _viewIndex,
      playable: flags,
    )) {
      return;
    }
    _windowRefreshing = true;
    try {
      final want = MezmurQueueWindow.plan(
        playable: flags,
        centerRow: _viewIndex,
        loop: _loop == 1,
      );
      final missing =
          want.where((r) => !_queueRows.contains(r)).toList(growable: false);
      if (missing.isEmpty) return;
      for (final row in missing) {
        if (row < 0 || row >= _catalog.length) continue;
        final url = await _resolveSource(_catalog[row]);
        if (url == null) continue;
        final track = _catalog[row].copyWith(audioUrl: url);
        _catalog[row] = track;
        // Keep engine order aligned with catalog order.
        var insertAt = _queueRows.length;
        for (var i = 0; i < _queueRows.length; i++) {
          if (_queueRows[i] > row) {
            insertAt = i;
            break;
          }
        }
        try {
          await _player.insertAudioSource(
            insertAt,
            AudioSource.uri(Uri.parse(url), tag: track.toMediaItem()),
          );
        } catch (_) {
          continue;
        }
        final rows = List<int>.from(_queueRows)..insert(insertAt, row);
        final q = List<MezmurTrack>.from(_queue)..insert(insertAt, track);
        _queueRows = rows;
        _queue = q;
        final idx = _index;
        if (idx != null && insertAt <= idx) _index = idx + 1;
      }
      notifyListeners();
    } finally {
      _windowRefreshing = false;
    }
  }

  /// Resolves ONE hymn to a playable source URL (P36).
  ///
  /// Order matters: a downloaded copy plays from disk with no network at
  /// all (works in airplane mode, costs no rate-limit budget), and only a
  /// hymn with no local copy gets a short-lived signed URL minted for it.
  /// Returns null when the hymn cannot be played right now.
  Future<String?> _resolveSource(MezmurTrack t) async {
    final localPath = await _downloads.localPathFor(t.hymnId);
    if (localPath != null) return Uri.file(localPath).toString();
    // An already-resolved URL (file:// or a signed link minted earlier
    // this session) is reused rather than re-minted.
    final existing = t.audioUrl.trim();
    if (existing.isNotEmpty) return existing;
    if (t.audioStatus.trim().toLowerCase() != 'ready') return null;
    try {
      final response = await _api.getMezmurAudioUrl(t.hymnId);
      if (response.success && response.data is Map) {
        final signed = '${response.data['url'] ?? ''}'.trim();
        if (signed.isNotEmpty) return signed;
      }
    } catch (_) {
      // Offline or refused — the caller surfaces the message.
    }
    return null;
  }

  /// Loads a WINDOW of [tracks] around [startIndex]. False when nothing
  /// could be loaded.
  ///
  /// P36 — why a window rather than the whole list.
  ///
  /// This used to sign only the selected hymn and then drop every
  /// neighbour whose `audioUrl` was empty. Because the server never sends
  /// an `audio_url` column, that meant every streamed neighbour: the
  /// engine queue held a single item, so native gapless advance had
  /// nowhere to go and the lock-screen / headset / Bluetooth next-track
  /// button did nothing.
  ///
  /// Signing the whole catalog is not the fix either — links expire in an
  /// hour and the endpoint is rate limited, so a long list would burn the
  /// budget on URLs nobody reaches. A bounded window around the listener
  /// is resolved instead and slid forward as playback advances (see
  /// [_ensureWindow]), keeping the engine queue genuinely multi-item
  /// while requests stay proportional to what is actually heard.
  Future<bool> openQueue(
    List<MezmurTrack> tracks, {
    int startIndex = 0,
    bool autoPlay = true,
  }) async {
    if (tracks.isEmpty) return false;
    final loadCommand = ++_commandVersion;
    _wantPlaying = autoPlay;
    final selectedIndex = startIndex.clamp(0, tracks.length - 1);

    await _ensureConfigured();

    final flags =
        List<bool>.generate(tracks.length, (i) => tracks[i].hasAudio);
    if (!flags.contains(true)) return false;

    final windowRows = MezmurQueueWindow.plan(
      playable: flags,
      centerRow: selectedIndex,
      loop: _loop == 1,
    );

    // The selected hymn resolves first so playback can begin without
    // waiting on its neighbours.
    final resolved = <int, String>{};
    final selectedUrl = await _resolveSource(tracks[selectedIndex]);
    if (selectedUrl == null) {
      _playbackError =
          'Audio could not be loaded. Download this hymn on Wi\u2011Fi to play it offline.';
      notifyListeners();
      return false;
    }
    resolved[selectedIndex] = selectedUrl;

    for (final row in windowRows) {
      if (row == selectedIndex) continue;
      if (loadCommand != _commandVersion) break; // superseded mid-load
      final url = await _resolveSource(tracks[row]);
      if (url != null) resolved[row] = url;
    }

    final playable = <MezmurTrack>[];
    final sources = <AudioSource>[];
    final rows = <int>[];
    var offset = 0;
    for (final row in resolved.keys.toList()..sort()) {
      final t = tracks[row].copyWith(audioUrl: resolved[row]);
      if (row == selectedIndex) offset = playable.length;
      sources.add(AudioSource.uri(Uri.parse(t.audioUrl), tag: t.toMediaItem()));
      playable.add(t);
      rows.add(row);
    }
    if (sources.isEmpty) return false;

    try {
      await _player.stop();
      _sourceLoaded = false;
      _playbackError = null;
      _queue = const [];
      _queueRows = const [];
      _index = null;
      _durMs = 0;
      notifyListeners();
      await _player.setAudioSources(sources,
          initialIndex: offset, initialPosition: Duration.zero);
      _queue = playable;
      _queueRows = rows;
      _index = offset;
      _sourceLoaded = true;
      _rememberResolved(rows, playable);
      if (_rate != 1) {
        try {
          await _player.setSpeed(_rate);
        } catch (_) {}
      }
      if (autoPlay && _wantPlaying && loadCommand == _commandVersion) {
        // Not awaited — see the transport note at _commandChain.
        unawaited(_player.play().catchError((_) {}));
      }
      notifyListeners();
      return true;
    } catch (_) {
      _sourceLoaded = false;
      _queue = const [];
      _queueRows = const [];
      _index = null;
      _durMs = 0;
      _playbackError =
          'Audio could not be loaded. Check your connection and try again.';
      notifyListeners();
      return false;
    }
  }

  /// Caches resolved URLs back onto the catalog rows so revisiting a hymn
  /// in the same session does not mint a second signed link.
  void _rememberResolved(List<int> rows, List<MezmurTrack> resolvedTracks) {
    for (var i = 0; i < rows.length; i++) {
      final row = rows[i];
      if (row >= 0 && row < _catalog.length) {
        _catalog[row] =
            _catalog[row].copyWith(audioUrl: resolvedTracks[i].audioUrl);
      }
    }
  }

  /// Serialises transport setup so two fast taps can never interleave
  /// their async work. Never holds the lock across `_player.play()`.
  Future<void> _enqueue(Future<void> Function() action) {
    final next = _commandChain.then((_) => action()).catchError((_) {});
    _commandChain = next;
    return next;
  }

  Future<void> play() => _enqueue(_play);

  Future<void> _play() async {
    final gen = ++_commandVersion;
    _wantPlaying = true;
    try {
      if (!canPlay && (viewTrack?.hasAudio ?? false)) {
        final loaded = await _syncAudio(autoPlay: false);
        if (!loaded) return;
      }
      // A newer gesture (e.g. the user hit pause while the source was
      // loading) supersedes this one.
      if (!MezmurTransportGate.mayStart(
        gen: gen,
        commandVersion: _commandVersion,
        wantPlaying: _wantPlaying,
        canPlay: canPlay,
      )) {
        return;
      }
      _playbackError = null;
      if (_completed) {
        await _player.seek(Duration.zero, index: _index);
      }
      if (!MezmurTransportGate.mayStart(
        gen: gen,
        commandVersion: _commandVersion,
        wantPlaying: _wantPlaying,
        canPlay: canPlay,
      )) {
        return;
      }
      // NOT awaited: this Future only completes when the track ends.
      // playerStateStream drives `_playing`, so the UI still updates.
      unawaited(_player.play().catchError((_) {}));
    } catch (_) {
      _playbackError =
          'Audio could not be played. Check your connection and try again.';
      notifyListeners();
    }
  }

  Future<void> pause() => _enqueue(_pause);

  Future<void> _pause() async {
    _commandVersion++;
    _wantPlaying = false;
    try {
      await _player.pause();
      _playing = false;
      notifyListeners();
    } catch (_) {
      _playbackError = 'Audio control failed. Please try again.';
      notifyListeners();
    }
  }

  /// Single source of truth for the intent is the engine's own state,
  /// never a hand-maintained boolean pair (the AudioKit#2646 defect).
  Future<void> toggle() => _enqueue(() async {
        if (MezmurTransportGate.toggleShouldPause(
            enginePlaying: _player.playing)) {
          await _pause();
        } else {
          await _play();
        }
      });

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

  /// Ends the listening session outright: stops audio, releases the
  /// queue and removes the mini bar. Invalidates any in-flight transport
  /// command so a slow load cannot resurrect playback afterwards.
  Future<void> stopAndClear() async {
    _commandVersion++;
    _wantPlaying = false;
    try {
      await _player.stop();
    } catch (_) {}
    _queue = const [];
    _queueRows = const [];
    _catalog = <MezmurTrack>[];
    _index = null;
    _viewIndex = 0;
    _playing = false;
    _buffering = false;
    _sourceLoaded = false;
    _playbackError = null;
    _durMs = 0;
    _posMs = 0;
    _miniDismissed = true;
    notifyListeners();
  }
}
