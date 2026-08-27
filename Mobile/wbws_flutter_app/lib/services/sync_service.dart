import 'dart:async';
import 'api_service.dart';
import 'catalog_service.dart';
import 'connectivity_service.dart';
import 'local_db.dart';

/// Outbox worker — Gmail / WhatsApp / Drive pattern.
/// UI writes SQLite only. One worker sends. Retries wait on the in-flight
/// future instead of returning "Sending…". Idempotency-Key makes peak-hour
/// double posts safe.
class SyncService {
  static final SyncService _instance = SyncService._internal();
  factory SyncService() => _instance;
  SyncService._internal();

  final _api = ApiService();
  final _db = LocalDb();
  Timer? _retryTimer;
  StreamSubscription<bool>? _radioSub;
  bool _started = false;
  Completer<SyncResult>? _inflight;
  bool _queued = false;
  bool _forceNext = false;
  int _failStreak = 0;

  final _syncController = StreamController<SyncStatus>.broadcast();
  Stream<SyncStatus> get syncStream => _syncController.stream;
  SyncStatus _lastStatus = SyncStatus(
      pendingAttendance: 0, pendingGrades: 0, pendingMezmur: 0, syncing: false);
  SyncStatus get lastStatus => _lastStatus;
  String lastError = '';

  static const _backoff = <int>[2, 5, 12, 30, 60];

  void startAutoSync() {
    if (_started) {
      nudge(delay: const Duration(milliseconds: 400));
      return;
    }
    _started = true;
    _radioSub?.cancel();
    _radioSub = ConnectivityService().statusStream.listen((hasLink) {
      if (hasLink) nudge(delay: const Duration(milliseconds: 500));
    });
    nudge(delay: const Duration(milliseconds: 800));
  }

  void stopAutoSync() {
    _retryTimer?.cancel();
    _retryTimer = null;
    _radioSub?.cancel();
    _radioSub = null;
    _started = false;
    _failStreak = 0;
  }

  void nudge({Duration delay = const Duration(milliseconds: 300)}) {
    if (!_api.isLoggedIn) return;
    if (!_started) startAutoSync();
    _retryTimer?.cancel();
    _retryTimer = Timer(delay, () {
      syncAll();
    });
  }

  Future<SyncResult> syncAll({bool force = false}) async {
    if (!_api.isLoggedIn) {
      return SyncResult(synced: 0, failed: 0, message: 'Not logged in');
    }
    if (force) _forceNext = true;
    // Gmail outbox: if a drain is already running, mark "run again"
    // after it. Joining the in-flight future without that flag swallows
    // any Save that landed while the first drain was already reading.
    if (_inflight != null) {
      _queued = true;
      final r = await _inflight!.future;
      if (_inflight == null) {
        final left = await _db.getTotalPendingCount();
        if (left > 0) return syncAll(force: force);
      }
      return r;
    }
    final c = Completer<SyncResult>();
    _inflight = c;
    try {
      var r = SyncResult(synced: 0, failed: 0, message: 'Nothing waiting to send');
      do {
        _queued = false;
        final useForce = force || _forceNext;
        _forceNext = false;
        final next = await _drain(force: useForce);
        r = SyncResult(
          synced: r.synced + next.synced,
          failed: next.failed,
          message: next.message,
        );
      } while (_queued);
      if (!c.isCompleted) c.complete(r);
      return r;
    } catch (e) {
      final r = SyncResult(
          synced: 0,
          failed: 1,
          message: 'Could not send yet. Will retry on its own.');
      if (!c.isCompleted) c.complete(r);
      return r;
    } finally {
      if (identical(_inflight, c)) _inflight = null;
    }
  }

  int _asInt(dynamic v) {
    if (v is int) return v;
    if (v is num) return v.toInt();
    return int.tryParse('$v') ?? 0;
  }

  Future<SyncResult> _drain({required bool force}) async {
    // User tap (force) always tries the school. The OS radio is only a
    // banner — Tecno phones often report "none" while 4G is working.

    await _emitStatus(syncing: true);
    int synced = 0;
    int failed = 0;
    var loops = 0;

    do {
      loops++;
      var didWork = false;

      final pendingAtt = await _db.getPendingAttendance();
      for (final batch in pendingAtt) {
        final classId = _asInt(batch['class_id']);
        final date = '${batch['date'] ?? ''}';
        if (classId <= 0 || date.isEmpty) continue;
        final kind = '${batch['packet_kind'] ?? 'draft'}';
        final opId = '${batch['client_op_id'] ?? ''}';
        try {
          final records = await _db.getPendingAttendanceRecords(classId, date);
          if (records.isEmpty) continue;
          final apiRecords = records
              .map((r) => {
                    'member_id': r['member_id'],
                    'status': r['status'],
                    'notes': r['notes'] ?? r['note'] ?? '',
                  })
              .toList();
          final res = kind == 'submitted'
              ? await _api.submitAttendance(classId, date, apiRecords,
                  clientOpId: opId)
              : await _api.saveAttendance(classId, date, apiRecords,
                  clientOpId: opId);
          if (_accepted(res)) {
            await _db.markAttendanceSynced(classId, date);
            synced++;
            didWork = true;
            lastError = '';
          } else {
            failed++;
            lastError = res.message ?? 'Attendance did not save.';
            await _db.logSync('attendance', lastError, 'error');
          }
        } catch (e) {
          failed++;
          await _db.logSync('attendance', e.toString(), 'error');
        }
      }

      final pendingGrades = await _db.getPendingGrades();
      for (final batch in pendingGrades) {
        final assessmentId = batch['assessment_id'] as int;
        final kind = '${batch['packet_kind'] ?? 'draft'}';
        final opId = '${batch['client_op_id'] ?? ''}';
        try {
          final records = await _db.getPendingGradeRecords(assessmentId);
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
              ? await _api.submitGrades(assessmentId, apiGrades,
                  clientOpId: opId)
              : await _api.saveGrades(assessmentId, apiGrades, clientOpId: opId);
          if (_accepted(res)) {
            await _db.markGradesSynced(assessmentId);
            synced++;
            didWork = true;
            lastError = '';
          } else {
            failed++;
            lastError = res.message ?? 'Grades did not save.';
            await _db.logSync('grades', lastError, 'error');
          }
        } catch (e) {
          failed++;
          await _db.logSync('grades', e.toString(), 'error');
        }
      }

      final pendingMez = await _db.getPendingMezmur();
      for (final batch in pendingMez) {
        final date = '${batch['date'] ?? ''}';
        if (date.isEmpty) continue;
        final program = '${batch['program'] ?? ''}';
        final opId = '${batch['client_op_id'] ?? ''}';
        try {
          final records = await _db.getPendingMezmurRecords(date);
          if (records.isEmpty) continue;
          // Label the day first (idempotent get-or-create); the sheet save
          // would create it anyway, so a failed label is non-fatal.
          if (program.isNotEmpty) {
            try {
              await _api.createMezmurDay(date: date, programType: program);
            } catch (_) {}
          }
          final apiRecords = records
              .map((r) => {
                    'member_id': r['member_id'],
                    'status': r['status'],
                  })
              .toList();
          final res = await _api.saveMezmurSheet(date, apiRecords,
              clientOpId: opId);
          if (_accepted(res)) {
            await _db.markMezmurSynced(date);
            synced++;
            didWork = true;
            lastError = '';
          } else {
            failed++;
            lastError = res.message ?? 'Mezmur attendance did not save.';
            await _db.logSync('mezmur', lastError, 'error');
          }
        } catch (e) {
          failed++;
          await _db.logSync('mezmur', e.toString(), 'error');
        }
      }

      if (!didWork) break;
    } while (loops < 4);

    await _db.cleanupSynced();
    await _emitStatus();

    final pendingLeft = await _db.getTotalPendingCount();
    final stillWaiting = failed > 0 || pendingLeft > 0;
    if (stillWaiting && (force || ConnectivityService().hasLink)) {
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
              : pendingLeft > 0
                  ? 'Still waiting to send'
                  : 'Nothing waiting to send',
    );
  }

  bool _accepted(ApiResponse res) {
    if (res.success) return true;
    if (res.statusCode == 409) return true;
    final m = (res.message ?? '').toLowerCase();
    return m.contains('already submitted');
  }

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

  Future<void> _emitStatus({bool? syncing}) async {
    final pa = await _db.getPendingAttendanceCount();
    final pg = await _db.getPendingGradesCount();
    final pm = await _db.getPendingMezmurCount();
    _lastStatus = SyncStatus(
        pendingAttendance: pa,
        pendingGrades: pg,
        pendingMezmur: pm,
        syncing: syncing ?? (_inflight != null));
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
  final int pendingMezmur;
  final bool syncing;
  int get totalPending =>
      pendingAttendance + pendingGrades + pendingMezmur;
  String get breakdown {
    if (totalPending <= 0) return 'All synced';
    final parts = <String>[];
    if (pendingAttendance > 0) {
      parts.add(
          '$pendingAttendance attendance sheet${pendingAttendance == 1 ? '' : 's'}');
    }
    if (pendingGrades > 0) {
      parts.add('$pendingGrades grade list${pendingGrades == 1 ? '' : 's'}');
    }
    if (pendingMezmur > 0) {
      parts.add('$pendingMezmur mezmur sheet${pendingMezmur == 1 ? '' : 's'}');
    }
    return parts.join(' · ');
  }

  SyncStatus(
      {required this.pendingAttendance,
      required this.pendingGrades,
      this.pendingMezmur = 0,
      required this.syncing});
}

class SyncResult {
  final int synced;
  final int failed;
  final String message;
  SyncResult(
      {required this.synced, required this.failed, required this.message});
}
