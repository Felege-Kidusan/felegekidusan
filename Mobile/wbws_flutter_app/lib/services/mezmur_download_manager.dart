import 'dart:async';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_service.dart';
import 'connectivity_service.dart';
import 'local_db.dart';
import 'mezmur_download_policy.dart';

/// ══════════════════════════════════════════════════════════════
/// P33 — Mezmur offline downloads (the Spotify model)
/// ══════════════════════════════════════════════════════════════
///
/// WHY A DOWNLOAD MANAGER AND NOT `LockCachingAudioSource`
/// ------------------------------------------------------
/// just_audio ships `LockCachingAudioSource`, which caches bytes while
/// streaming. It is the wrong tool here for three reasons:
///
///   1. It is opportunistic, not a promise. A hymn is only offline if
///     the user happened to listen to all of it. Spotify's contract is
///     the opposite: "downloaded" means the whole file is on disk
///     BEFORE you go offline.
///   2. Its cache key is the URL. Our audio URLs are short-lived
///      presigned R2 links (`X-Amz-Expires`), so every playback mints a
///      NEW url and the cache never hits.
///   3. It cannot express queueing, Wi‑Fi-only, retry, progress,
///      storage caps or eviction — all of which the user asked for.
///
/// So: an explicit queue that downloads the whole object to the app's
/// support directory, keyed by hymn id (stable), and hands the player a
/// `file://` source when one exists. The presigned URL is used ONCE, at
/// download time, and never persisted.
///
/// DESIGN
/// ------
///   • Persistence: `hymn_downloads` in the existing offline SQLite DB,
///     so a queue survives an app kill and resumes on next launch.
///   • Resume: HTTP `Range: bytes=N-` against a `.part` file, so a
///     dropped 2G connection does not restart a 6 MB hymn.
///   • Integrity: size check + sha256 recorded; a truncated `.part` is
///     never promoted to a playable file.
///   • Concurrency: capped at 2 so the phone stays responsive and the
///     shared host is not hammered.
///   • Policy: Wi‑Fi-only by default (mirrors Spotify's "Download over
///     cellular" switch), plus a storage cap with LRU eviction of
///     auto-downloaded rows. User-pinned rows are never evicted.
///   • Freshness: `audio_updated_at` from the delta sync is stored with
///     the file; when the server replaces an object the row goes stale
///     and re-downloads instead of playing yesterday's audio.
class MezmurDownloadManager extends ChangeNotifier {
  MezmurDownloadManager._internal();
  static final MezmurDownloadManager instance =
      MezmurDownloadManager._internal();
  factory MezmurDownloadManager() => instance;

  final _db = LocalDb();
  final _api = ApiService();
  final _net = ConnectivityService();

  static const _prefWifiOnly = 'mz_dl_wifi_only';
  static const _prefCapMb = 'mz_dl_cap_mb';
  static const int _maxConcurrent = 2;
  static const int _maxAttempts = 4;

  /// 0 = unlimited. Default 2 GB — generous for hymns, safe on a 32 GB phone.
  int _capMb = 2048;
  bool _wifiOnly = true;
  bool _booted = false;
  bool _pumping = false;

  final Map<int, double> _progress = {};
  final Map<int, String> _states = {};
  final Set<int> _active = {};
  final Set<int> _cancelled = {};
  int _bytesOnDisk = 0;
  int _doneCount = 0;

  // ── public state (widgets just addListener on this) ─────────

  bool get wifiOnly => _wifiOnly;
  int get capMb => _capMb;
  int get bytesOnDisk => _bytesOnDisk;
  int get downloadedCount => _doneCount;
  int get activeCount => _active.length;
  int get queuedCount =>
      _states.values.where((s) => s == 'queued' || s == 'downloading').length;

  /// 'none' | 'queued' | 'downloading' | 'done' | 'failed' | 'paused'
  String stateOf(int hymnId) => _states[hymnId] ?? 'none';
  bool isDownloaded(int hymnId) => stateOf(hymnId) == 'done';
  double progressOf(int hymnId) => _progress[hymnId] ?? 0;

  /// Whether the queue is stalled waiting for Wi‑Fi — the UI shows this
  /// instead of silently doing nothing.
  /// 'idle' | 'running' | 'waiting-wifi' | 'no-network' — decided by the
  /// pure policy so the UI copy and the engine can never disagree.
  String get queueStatus => MezmurDownloadPolicy.queueStatus(
        queued: queuedCount,
        hasLink: _net.hasLink,
        isUnmetered: _net.isUnmetered,
        wifiOnly: _wifiOnly,
      );

  bool get waitingForWifi => queueStatus == 'waiting-wifi';

  bool get waitingForNetwork => queueStatus == 'no-network';

  // ── boot ────────────────────────────────────────────────────

  /// Call once after login. Restores counters, re-arms an interrupted
  /// queue, and resumes automatically whenever the radio comes back.
  Future<void> boot() async {
    if (_booted) return;
    _booted = true;
    final prefs = await SharedPreferences.getInstance();
    _wifiOnly = prefs.getBool(_prefWifiOnly) ?? true;
    _capMb = prefs.getInt(_prefCapMb) ?? 2048;
    _states.addAll(await _db.downloadStates());
    // Anything caught mid-flight by an app kill is re-queued, not lost.
    for (final e in _states.entries.toList()) {
      if (e.value == 'downloading') {
        _states[e.key] = 'queued';
        await _db.markDownloadState(e.key, 'queued');
      }
    }
    await _refreshTotals();
    notifyListeners();
    _net.statusStream.listen((_) => _pump());
    unawaited(_pump());
  }

  Future<void> setWifiOnly(bool v) async {
    _wifiOnly = v;
    (await SharedPreferences.getInstance()).setBool(_prefWifiOnly, v);
    notifyListeners();
    unawaited(_pump());
  }

  Future<void> setCapMb(int mb) async {
    _capMb = mb < 0 ? 0 : mb;
    (await SharedPreferences.getInstance()).setInt(_prefCapMb, _capMb);
    notifyListeners();
    await _enforceCap();
  }

  // ── the user-facing verbs ───────────────────────────────────

  /// Pin one hymn for offline use. [row] is a cached_hymns row.
  Future<void> download(Map<String, dynamic> row,
      {String source = 'user'}) async {
    final id = _asInt(row['id']);
    if (id <= 0) return;
    if ('${row['audio_status'] ?? ''}' != 'ready') return;
    await _db.enqueueDownload(id,
        source: source,
        audioUpdatedAt: row['audio_updated_at'] as String?,
        format: row['audio_format'] as String?);
    _states[id] = 'queued';
    _progress[id] = 0;
    notifyListeners();
    unawaited(_pump());
  }

  /// Same as [download] but resolves the cached row itself — for callers
  /// (the now-playing screen) that only hold a hymn id. Resolving the row
  /// matters: it carries `audio_updated_at`, which is what lets a later
  /// sync notice the server replaced the file.
  Future<void> downloadById(int hymnId, {String source = 'user'}) async {
    if (hymnId <= 0) return;
    final row = await _db.getLocalHymn(hymnId);
    if (row != null) {
      await download(row, source: source);
      return;
    }
    // Not in the local library yet (rare: opened from a deep link).
    await _db.enqueueDownload(hymnId, source: source);
    _states[hymnId] = 'queued';
    notifyListeners();
    unawaited(_pump());
  }

  /// Bulk pin — a category, a singer, or the current filtered view.
  /// Rows without ready audio are skipped silently (they are lyrics-only).
  Future<int> downloadAll(List<Map<String, dynamic>> rows,
      {String source = 'user'}) async {
    var n = 0;
    for (final r in rows) {
      if ('${r['audio_status'] ?? ''}' != 'ready') continue;
      await download(r, source: source);
      n++;
    }
    return n;
  }

  /// Remove the offline copy (and its queue entry). Streaming still works.
  Future<void> remove(int hymnId) async {
    _cancelled.add(hymnId);
    final row = await _db.downloadRow(hymnId);
    final path = '${row?['file_path'] ?? ''}';
    if (path.isNotEmpty) {
      await _deleteQuietly(File(path));
      await _deleteQuietly(File('$path.part'));
    }
    await _db.deleteDownloadRow(hymnId);
    _states.remove(hymnId);
    _progress.remove(hymnId);
    await _refreshTotals();
    notifyListeners();
  }

  Future<void> removeAll() async {
    final rows = await _db.downloadRows();
    for (final r in rows) {
      await remove(_asInt(r['hymn_id']));
    }
  }

  /// Retry a failed row (resets the attempt counter — this is a
  /// deliberate user action, not the automatic backoff).
  Future<void> retry(int hymnId) async {
    await _db.markDownloadState(hymnId, 'queued', error: null);
    _states[hymnId] = 'queued';
    notifyListeners();
    unawaited(_pump());
  }

  Future<void> pauseAll() async {
    for (final e in _states.entries.toList()) {
      if (e.value == 'queued' || e.value == 'downloading') {
        _cancelled.add(e.key);
        _states[e.key] = 'paused';
        await _db.markDownloadState(e.key, 'paused');
      }
    }
    notifyListeners();
  }

  Future<void> resumeAll() async {
    _cancelled.clear();
    for (final e in _states.entries.toList()) {
      if (e.value == 'paused' || e.value == 'failed') {
        _states[e.key] = 'queued';
        await _db.markDownloadState(e.key, 'queued');
      }
    }
    notifyListeners();
    unawaited(_pump());
  }

  // ── collection pins ─────────────────────────────────────────

  Future<bool> isCollectionPinned(String kind, int refId) =>
      _db.hasDownloadPin(kind, refId);

  /// Pin a whole category/singer: everything ready now is queued, and
  /// [syncPins] re-tops-up after future delta syncs.
  Future<int> pinCollection(String kind, int refId, String label) async {
    await _db.addDownloadPin(kind, refId, label);
    final rows = await _db.readyAudioHymnsIn(
        categoryId: kind == 'category' ? refId : null,
        zemarianId: kind == 'zemarian' ? refId : null);
    var n = 0;
    for (final r in rows) {
      await _db.enqueueDownload(_asInt(r['id']),
          source: 'user',
          audioUpdatedAt: r['audio_updated_at'] as String?,
          format: r['audio_format'] as String?);
      _states[_asInt(r['id'])] = 'queued';
      n++;
    }
    notifyListeners();
    unawaited(_pump());
    return n;
  }

  Future<void> unpinCollection(String kind, int refId,
      {bool deleteFiles = true}) async {
    await _db.removeDownloadPin(kind, refId);
    if (!deleteFiles) return;
    final rows = await _db.readyAudioHymnsIn(
        categoryId: kind == 'category' ? refId : null,
        zemarianId: kind == 'zemarian' ? refId : null);
    for (final r in rows) {
      await remove(_asInt(r['id']));
    }
  }

  /// Called after a hymn delta sync: queues new hymns inside pinned
  /// collections and re-downloads anything the server replaced.
  Future<void> syncPins() async {
    for (final pin in await _db.downloadPins()) {
      final kind = '${pin['kind']}';
      final refId = _asInt(pin['ref_id']);
      final rows = await _db.readyAudioHymnsIn(
          categoryId: kind == 'category' ? refId : null,
          zemarianId: kind == 'zemarian' ? refId : null);
      for (final r in rows) {
        final id = _asInt(r['id']);
        if (_states[id] == 'done' || _states[id] == 'queued') continue;
        await _db.enqueueDownload(id,
            source: 'user',
            audioUpdatedAt: r['audio_updated_at'] as String?,
            format: r['audio_format'] as String?);
        _states[id] = 'queued';
      }
    }
    // Server replaced an object → the stored copy is stale.
    for (final s in await _db.staleDownloads()) {
      final id = _asInt(s['hymn_id']);
      await _db.enqueueDownload(id,
          source: '${s['source']}',
          audioUpdatedAt: s['server_updated'] as String?);
      _states[id] = 'queued';
    }
    notifyListeners();
    unawaited(_pump());
  }

  // ── playback integration ────────────────────────────────────

  /// The local file for a hymn, if it is fully downloaded and still on
  /// disk. The player calls this BEFORE minting a signed URL, which is
  /// what makes offline playback work with zero network.
  Future<String?> localPathFor(int hymnId) async {
    final path = await _db.downloadedPath(hymnId);
    if (path == null) return null;
    if (!await File(path).exists()) {
      // File vanished (user cleared storage / OS reclaimed it).
      await _db.deleteDownloadRow(hymnId);
      _states.remove(hymnId);
      notifyListeners();
      return null;
    }
    unawaited(_db.touchDownloadPlayed(hymnId));
    return path;
  }

  // ── the queue engine ────────────────────────────────────────

  Future<void> _pump() async {
    if (_pumping) return;
    _pumping = true;
    try {
      while (true) {
        if (_active.length >= _maxConcurrent) break;
        if (!MezmurDownloadPolicy.canTransfer(
            hasLink: _net.hasLink,
            isUnmetered: _net.isUnmetered,
            wifiOnly: _wifiOnly)) {
          break;
        }
        final pending = await _db.pendingDownloads(limit: 10);
        final next = pending
            .where((r) => !_active.contains(_asInt(r['hymn_id'])))
            .toList();
        if (next.isEmpty) break;
        final row = next.first;
        final id = _asInt(row['hymn_id']);
        _active.add(id);
        unawaited(_runOne(id, row).whenComplete(() {
          _active.remove(id);
          // Re-enter the pump so the next item starts immediately.
          unawaited(Future.microtask(_pump));
        }));
      }
    } finally {
      _pumping = false;
      notifyListeners();
    }
  }

  Future<void> _runOne(int hymnId, Map<String, dynamic> row) async {
    _cancelled.remove(hymnId);
    _states[hymnId] = 'downloading';
    await _db.markDownloadState(hymnId, 'downloading', bumpAttempts: true);
    notifyListeners();

    HttpClient? client;
    IOSink? sink;
    try {
      // 1. Mint a fresh signed URL. Never stored — it expires in an hour.
      final res = await _api.getMezmurAudioUrl(hymnId);
      if (!res.success || res.data is! Map) {
        throw _DlError(res.message ?? 'Could not reach the server.');
      }
      final url = '${res.data['url'] ?? ''}'.trim();
      if (url.isEmpty) throw _DlError('This hymn has no audio on the server.');

      final dir = await _audioDir();
      final ext = _extFor('${row['audio_format'] ?? ''}', url);
      final finalPath = p.join(dir.path, 'mz_$hymnId.$ext');
      final partFile = File('$finalPath.part');

      // 2. Resume from whatever survived the last attempt.
      var have = await partFile.exists() ? await partFile.length() : 0;

      client = HttpClient()..connectionTimeout = const Duration(seconds: 30);
      final req = await client.getUrl(Uri.parse(url));
      if (have > 0) req.headers.set(HttpHeaders.rangeHeader, 'bytes=$have-');
      final resp = await req.close();

      if (resp.statusCode == 200 && have > 0) {
        // Server ignored the Range header — start over cleanly.
        have = 0;
        await _deleteQuietly(partFile);
      } else if (resp.statusCode != 200 && resp.statusCode != 206) {
        throw _DlError('Server refused the download (${resp.statusCode}).');
      }

      final total = have + (resp.contentLength > 0 ? resp.contentLength : 0);
      // `writer` is non-nullable so it can be used directly; `sink` is only
      // a cleanup handle for the catch block. Writing through a nullable
      // local instead would not type-check — Dart cannot promote a local
      // that is reassigned to null inside the loop.
      final writer =
          partFile.openWrite(mode: have > 0 ? FileMode.append : FileMode.write);
      sink = writer;

      var done = have;
      var lastTick = 0;
      await for (final chunk in resp) {
        if (_cancelled.contains(hymnId)) {
          await writer.flush();
          await writer.close();
          sink = null; // already closed — keep the catch from double-closing
          _states[hymnId] = 'paused';
          await _db.markDownloadState(hymnId, 'paused',
              bytesDone: done, bytesTotal: total);
          notifyListeners();
          return;
        }
        writer.add(chunk);
        done += chunk.length;
        _progress[hymnId] = total > 0 ? (done / total).clamp(0.0, 1.0) : 0;
        // Throttle UI + DB writes to ~every 256 KB.
        if (done - lastTick > 256 * 1024) {
          lastTick = done;
          notifyListeners();
          unawaited(_db.updateDownloadProgress(hymnId, done, total));
        }
      }
      await writer.flush();
      await writer.close();
      sink = null;

      // 3. Integrity: a truncated body must never be promoted.
      final written = await partFile.length();
      if (total > 0 && written < total) {
        throw _DlError('Download was cut short — will retry.');
      }
      if (written < 1024) {
        throw _DlError('Downloaded file was empty.');
      }

      final digest = sha256.convert(await partFile.readAsBytes()).toString();
      final out = File(finalPath);
      if (await out.exists()) await _deleteQuietly(out);
      await partFile.rename(finalPath);

      _states[hymnId] = 'done';
      _progress[hymnId] = 1;
      await _db.markDownloadState(hymnId, 'done',
          filePath: finalPath,
          bytesDone: written,
          bytesTotal: written,
          sha256: digest);
      await _refreshTotals();
      notifyListeners();
      await _enforceCap();
    } catch (e) {
      try {
        await sink?.flush();
        await sink?.close();
      } catch (_) {}
      final attempts = _asInt((await _db.downloadRow(hymnId))?['attempts']);
      final msg = e is _DlError ? e.message : 'Download failed.';
      if (MezmurDownloadPolicy.shouldRetry(
              attempts: attempts, maxAttempts: _maxAttempts) &&
          !_cancelled.contains(hymnId)) {
        // Exponential backoff, then back into the queue. The .part file
        // stays, so the retry resumes rather than restarts.
        _states[hymnId] = 'queued';
        await _db.markDownloadState(hymnId, 'queued', error: msg);
        notifyListeners();
        await Future.delayed(MezmurDownloadPolicy.backoff(attempts));
      } else {
        _states[hymnId] = 'failed';
        await _db.markDownloadState(hymnId, 'failed', error: msg);
        notifyListeners();
      }
    } finally {
      client?.close(force: true);
    }
  }

  // ── storage housekeeping ────────────────────────────────────

  Future<void> _refreshTotals() async {
    _bytesOnDisk = await _db.downloadedBytes();
    _doneCount = await _db.downloadedCount();
  }

  /// LRU eviction of AUTO downloads once the cap is exceeded. Explicit
  /// user pins are sacred — if pins alone blow the cap we stop evicting
  /// and let the UI report it rather than deleting what was asked for.
  Future<void> _enforceCap() async {
    if (_capMb <= 0) return;
    final capBytes = _capMb * 1024 * 1024;
    await _refreshTotals();
    if (_bytesOnDisk <= capBytes) return;
    final all = await _db.downloadRows();
    final view = all
        .where((r) => '${r['state']}' == 'done')
        .map((r) => DownloadRowView(
              id: _asInt(r['hymn_id']),
              bytes: _asInt(r['bytes_done']),
              source: '${r['source']}',
              lastPlayed:
                  '${r['last_played_at'] ?? r['completed_at'] ?? ''}',
            ))
        .toList();
    for (final id
        in MezmurDownloadPolicy.evictionPlan(rows: view, capBytes: capBytes)) {
      await remove(id);
    }
    await _refreshTotals();
    notifyListeners();
  }

  /// App-support (not temp, not cache): the OS will not reclaim it, and
  /// it is excluded from cloud backup — right home for re-downloadable
  /// media that must still survive a low-storage sweep.
  Future<Directory> _audioDir() async {
    final base = await getApplicationSupportDirectory();
    final dir = Directory(p.join(base.path, 'mezmur_audio'));
    if (!await dir.exists()) await dir.create(recursive: true);
    // Keep the media scanner / iCloud out of it.
    final nomedia = File(p.join(dir.path, '.nomedia'));
    if (!await nomedia.exists()) {
      try {
        await nomedia.create();
      } catch (_) {}
    }
    return dir;
  }

  String _extFor(String format, String url) {
    final f = format.trim().toLowerCase();
    if (f.isNotEmpty && f.length <= 5 && RegExp(r'^[a-z0-9]+$').hasMatch(f)) {
      return f;
    }
    final path = Uri.tryParse(url)?.path ?? '';
    final ext = p.extension(path).replaceFirst('.', '').toLowerCase();
    return RegExp(r'^[a-z0-9]{2,5}$').hasMatch(ext) ? ext : 'mp3';
  }

  Future<void> _deleteQuietly(File f) async {
    try {
      if (await f.exists()) await f.delete();
    } catch (_) {}
  }

  static int _asInt(dynamic v) =>
      v is int ? v : int.tryParse('${v ?? ''}') ?? 0;

  /// Human-readable size for the settings/manage screens.
  static String formatBytes(int bytes) {
    if (bytes <= 0) return '0 MB';
    const units = ['B', 'KB', 'MB', 'GB'];
    var b = bytes.toDouble();
    var i = 0;
    while (b >= 1024 && i < units.length - 1) {
      b /= 1024;
      i++;
    }
    return '${b.toStringAsFixed(b >= 100 || i <= 1 ? 0 : 1)} ${units[i]}';
  }
}

class _DlError implements Exception {
  final String message;
  _DlError(this.message);
  @override
  String toString() => message;
}
