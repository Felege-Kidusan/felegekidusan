import 'dart:async';
import 'api_service.dart';
import 'catalog_service.dart';
import 'connectivity_service.dart';
import 'local_db.dart';

/// Telegram-style outbox.
/// Save / Submit write the phone first. This queue sends them without a tap:
/// as soon as 4G is up, on a failed send (backoff), and the moment the
/// teacher opens the app again. The Sync button is only a manual kick.
class SyncService {
  static final SyncService _instance = SyncService._internal();
  factory SyncService() => _instance;
  SyncService._internal();

  final _api = ApiService();
  final _db = LocalDb();
  Timer? _retryTimer;
  StreamSubscription<bool>? _radioSub;
  bool _started = false;
  bool _syncing = false;
  bool _queued = false;
  int _failStreak = 0;

  final _syncController = StreamController<SyncStatus>.broadcast();
  Stream<SyncStatus> get syncStream => _syncController.stream;
  SyncStatus _lastStatus =
      SyncStatus(pendingAttendance: 0, pendingGrades: 0, syncing: false);
  SyncStatus get lastStatus => _lastStatus;

  static const _backoff = <int>[3, 8, 20, 45, 90];

  void startAutoSync() {
    if (_started) {
      nudge(delay: const Duration(milliseconds: 600));
      return;
    }
    _started = true;
    _radioSub?.cancel();
    _radioSub = ConnectivityService().statusStream.listen((hasLink) {
      if (hasLink) nudge(delay: const Duration(milliseconds: 800));
    });
    nudge(delay: const Duration(seconds: 2));
  }

  void stopAutoSync() {
    _retryTimer?.cancel();
    _retryTimer = null;
    _radioSub?.cancel();
    _radioSub = null;
    _started = false;
    _failStreak = 0;
  }

  /// Something new is waiting on this phone. Send soon.
  void nudge({Duration delay = const Duration(milliseconds: 400)}) {
    if (!_api.isLoggedIn) return;
    if (!_started) startAutoSync();
    _retryTimer?.cancel();
    _retryTimer = Timer(delay, () {
      syncAll();
    });
  }

  Future<SyncResult> syncAll() async {
    if (!_api.isLoggedIn) {
      return SyncResult(synced: 0, failed: 0, message: 'Not logged in');
    }
    if (!ConnectivityService().hasLink) {
      return SyncResult(synced: 0, failed: 0, message: 'Waiting for network');
    }
    if (_syncing) {
      _queued = true;
      return SyncResult(synced: 0, failed: 0, message: 'Sending…');
    }

    _syncing = true;
    _emitStatus();

    int synced = 0;
    int failed = 0;

    try {
      do {
        _queued = false;

        try {
          final pendingAtt = await _db.getPendingAttendance();
          for (final batch in pendingAtt) {
            final classId = batch['class_id'] as int;
            final date = batch['date'] as String;
            final kind = '${batch['packet_kind'] ?? 'draft'}';
            try {
              final records =
                  await _db.getPendingAttendanceRecords(classId, date);
              if (records.isEmpty) continue;
              final apiRecords = records
                  .map((r) => {
                        'member_id': r['member_id'],
                        'status': r['status'],
                        'notes': r['notes'] ?? r['note'] ?? '',
                      })
                  .toList();
              final res = kind == 'submitted'
                  ? await _api.submitAttendance(classId, date, apiRecords)
                  : await _api.saveAttendance(classId, date, apiRecords);
              if (res.success) {
                await _db.markAttendanceSynced(classId, date);
                synced++;
              } else {
                failed++;
              }
            } catch (_) {
              failed++;
            }
          }
        } catch (_) {}

        try {
          final pendingGrades = await _db.getPendingGrades();
          for (final batch in pendingGrades) {
            final assessmentId = batch['assessment_id'] as int;
            final kind = '${batch['packet_kind'] ?? 'draft'}';
            try {
              final records =
                  await _db.getPendingGradeRecords(assessmentId);
              if (records.isEmpty) continue;
              final apiGrades = records.map((r) {
                return <String, dynamic>{
                  'member_id': r['member_id'],
                  'score': r['score'],
                  'remark': r['remark'] ?? '',
                  'record_id': r['record_id'],
                };
              }).toList();
              final res = kind == 'submitted'
                  ? await _api.submitGrades(assessmentId, apiGrades)
                  : await _api.saveGrades(assessmentId, apiGrades);
              if (res.success) {
                await _db.markGradesSynced(assessmentId);
                synced++;
              } else {
                failed++;
              }
            } catch (_) {
              failed++;
            }
          }
        } catch (_) {}
      } while (_queued);

      await _db.cleanupSynced();
    } finally {
      _syncing = false;
      await _emitStatus();
    }

    final pendingLeft = await _db.getTotalPendingCount();
    final stillWaiting = failed > 0 || pendingLeft > 0;
    if (stillWaiting && ConnectivityService().hasLink) {
      _failStreak = (_failStreak + 1).clamp(1, _backoff.length);
      nudge(delay: Duration(seconds: _backoff[_failStreak - 1]));
    } else if (failed == 0) {
      _failStreak = 0;
    }

    return SyncResult(
      synced: synced,
      failed: failed,
      message: synced > 0
          ? (failed > 0
              ? 'Sent $synced. $failed still waiting — will retry.'
              : 'Sent to Education')
          : failed > 0
              ? 'Could not send yet. Will retry on its own.'
              : 'Nothing waiting to send',
    );
  }

  /// Cache classes, students, subjects, dashboard stats, and members for offline
  Future<void> cacheForOffline() async {
    if (!_api.isLoggedIn) return;

    try {
      final dashRes = await _api.getDashboardStats();
      if (dashRes.success && dashRes.data != null) {
        await _db.cacheDashboardStats(dashRes.data, _api.userRole);
      }
    } catch (_) {}

    try {
      await CatalogService().classes();
    } catch (_) {}
  }

  Future<void> _emitStatus() async {
    final pa = await _db.getPendingAttendanceCount();
    final pg = await _db.getPendingGradesCount();
    _lastStatus = SyncStatus(
        pendingAttendance: pa, pendingGrades: pg, syncing: _syncing);
    _syncController.add(_lastStatus);
  }

  Future<void> emitCurrentStatus() async => _emitStatus();

  void dispose() {
    stopAutoSync();
    _syncController.close();
  }
}

class SyncStatus {
  final int pendingAttendance;
  final int pendingGrades;
  final bool syncing;
  int get totalPending => pendingAttendance + pendingGrades;
  SyncStatus(
      {required this.pendingAttendance,
      required this.pendingGrades,
      required this.syncing});
}

class SyncResult {
  final int synced;
  final int failed;
  final String message;
  SyncResult(
      {required this.synced, required this.failed, required this.message});
}
