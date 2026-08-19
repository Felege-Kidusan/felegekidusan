import 'dart:async';
import 'api_service.dart';
import 'catalog_service.dart';
import 'local_db.dart';

/// Syncs offline data (attendance + grades) to server.
/// Also caches data for offline use.
class SyncService {
  static final SyncService _instance = SyncService._internal();
  factory SyncService() => _instance;
  SyncService._internal();

  final _api = ApiService();
  final _db = LocalDb();
  Timer? _syncTimer;
  bool _syncing = false;

  final _syncController = StreamController<SyncStatus>.broadcast();
  Stream<SyncStatus> get syncStream => _syncController.stream;
  SyncStatus _lastStatus =
      SyncStatus(pendingAttendance: 0, pendingGrades: 0, syncing: false);
  SyncStatus get lastStatus => _lastStatus;

  void startAutoSync() {
    _syncTimer?.cancel();
    _syncTimer =
        Timer.periodic(const Duration(seconds: 30), (_) => syncAll());
    syncAll();
  }

  void stopAutoSync() {
    _syncTimer?.cancel();
    _syncTimer = null;
  }

  Future<SyncResult> syncAll() async {
    if (_syncing || !_api.isLoggedIn) {
      return SyncResult(
          synced: 0,
          failed: 0,
          message: _syncing ? 'Already syncing' : 'Not logged in');
    }

    _syncing = true;
    _emitStatus();

    int synced = 0;
    int failed = 0;

    // --- SYNC ATTENDANCE ---
    try {
      final pendingAtt = await _db.getPendingAttendance();
      for (final batch in pendingAtt) {
        final classId = batch['class_id'] as int;
        final date = batch['date'] as String;
        try {
          final records =
              await _db.getPendingAttendanceRecords(classId, date);
          if (records.isEmpty) continue;
          final apiRecords = records
              .map((r) =>
                  {'member_id': r['member_id'], 'status': r['status']})
              .toList();
          final res = await _api.saveAttendance(classId, date, apiRecords);
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

    // --- SYNC GRADES ---
    try {
      final pendingGrades = await _db.getPendingGrades();
      for (final batch in pendingGrades) {
        final assessmentId = batch['assessment_id'] as int;
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
          final res = await _api.saveGrades(assessmentId, apiGrades);
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

    await _db.cleanupSynced();
    _syncing = false;
    _emitStatus();

    return SyncResult(
      synced: synced,
      failed: failed,
      message: synced > 0
          ? '$synced synced${failed > 0 ? ', $failed failed' : ''}'
          : failed > 0
              ? '$failed failed to sync'
              : 'All synced',
    );
  }

  /// Cache classes, students, subjects, dashboard stats, and members for offline
  Future<void> cacheForOffline() async {
    if (!_api.isLoggedIn) return;

    // Cache dashboard stats
    try {
      final dashRes = await _api.getDashboardStats();
      if (dashRes.success && dashRes.data != null) {
        await _db.cacheDashboardStats(dashRes.data, _api.userRole);
      }
    } catch (_) {}

    // Class list only. Rosters and subjects load when the teacher
    // opens that class — otherwise a TECNO on slow data waits for every room.
    try {
      await CatalogService().classes(force: true);
    } catch (_) {}
  }

  void _emitStatus() async {
    final pa = await _db.getPendingAttendanceCount();
    final pg = await _db.getPendingGradesCount();
    _lastStatus = SyncStatus(
        pendingAttendance: pa, pendingGrades: pg, syncing: _syncing);
    _syncController.add(_lastStatus);
  }

  Future<void> emitCurrentStatus() async => _emitStatus();

  void dispose() {
    _syncTimer?.cancel();
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
