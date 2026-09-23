import 'dart:async';
import 'api_service.dart';
import 'catalog_service.dart';
import 'connectivity_service.dart';
import 'mezmur_download_manager.dart';
import 'hymn_store.dart';
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
  int? _inflightGeneration;
  bool _queued = false;
  bool _forceNext = false;
  int _failStreak = 0;
  bool Function()? activeSessionGate;
  int Function()? sessionGenerationProvider;

  bool _ownsGeneration(int generation) =>
      activeSessionGate?.call() != false &&
      generation == (sessionGenerationProvider?.call() ?? generation);

  final _syncController = StreamController<SyncStatus>.broadcast();
  Stream<SyncStatus> get syncStream => _syncController.stream;
  SyncStatus _lastStatus = SyncStatus(
      pendingAttendance: 0,
      pendingGrades: 0,
      pendingMezmur: 0,
      pendingHr: 0,
      pendingHymns: 0,
      syncing: false);
  SyncStatus get lastStatus => _lastStatus;
  String lastError = '';

  static const _backoff = <int>[2, 5, 12, 30, 60];

  void startAutoSync() {
    if (activeSessionGate?.call() == false) return;
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
    _queued = false;
    _forceNext = false;
    _failStreak = 0;
  }

  void nudge({Duration delay = const Duration(milliseconds: 300)}) {
    if (activeSessionGate?.call() == false) return;
    if (!_api.isLoggedIn) return;
    if (!_started) startAutoSync();
    _retryTimer?.cancel();
    final generation = sessionGenerationProvider?.call() ?? 0;
    _retryTimer = Timer(delay, () {
      _syncAllForGeneration(generation);
    });
  }

  Future<SyncResult> syncAll({bool force = false}) => _syncAllForGeneration(
      sessionGenerationProvider?.call() ?? 0,
      force: force);

  Future<SyncResult> _syncAllForGeneration(int generation,
      {bool force = false}) async {
    if (!_ownsGeneration(generation) || !_api.isLoggedIn) {
      return SyncResult(synced: 0, failed: 0, message: 'Not logged in');
    }
    if (force) _forceNext = true;
    // Gmail outbox: if a drain is already running, mark "run again"
    // after it. Joining the in-flight future without that flag swallows
    // any Save that landed while the first drain was already reading.
    if (_inflight != null) {
      final sameGeneration = _inflightGeneration == generation;
      if (sameGeneration) _queued = true;
      final r = await _inflight!.future;
      if (!_ownsGeneration(generation)) {
        return SyncResult(
            synced: 0,
            failed: 0,
            message: 'Sync paused until this account is active again.');
      }
      if (_inflight == null) {
        final left = await _db.getTotalPendingCount();
        if (left > 0) {
          return _syncAllForGeneration(generation, force: force);
        }
      }
      return r;
    }
    final c = Completer<SyncResult>();
    _inflight = c;
    _inflightGeneration = generation;
    try {
      var r = SyncResult(synced: 0, failed: 0, message: 'Nothing waiting to send');
      do {
        _queued = false;
        final useForce = force || _forceNext;
        _forceNext = false;
        final next = await _drain(generation: generation, force: useForce);
        r = SyncResult(
          synced: r.synced + next.synced,
          failed: next.failed,
          message: next.message,
        );
      } while (_queued && _ownsGeneration(generation));
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
      if (identical(_inflight, c)) {
        _inflight = null;
        _inflightGeneration = null;
      }
    }
  }

  int _asInt(dynamic v) {
    if (v is int) return v;
    if (v is num) return v.toInt();
    return int.tryParse('$v') ?? 0;
  }

  Future<SyncResult> _drain(
      {required int generation, required bool force}) async {
    // User tap (force) always tries the school. The OS radio is only a
    // banner — Tecno phones often report "none" while 4G is working.

    if (!_ownsGeneration(generation)) {
      return SyncResult(
        synced: 0,
        failed: 0,
        message: 'Sync paused until this account is active again.',
      );
    }
    await _emitStatus(syncing: true);
    int synced = 0;
    int failed = 0;
    var loops = 0;

    do {
      if (!_ownsGeneration(generation)) {
        return SyncResult(
          synced: synced,
          failed: failed,
          message: 'Sync paused until this account is active again.',
        );
      }
      loops++;
      var didWork = false;

      final pendingAtt = await _db.getPendingAttendance();
      for (final batch in pendingAtt) {
        if (!_ownsGeneration(generation)) {
          return SyncResult(
            synced: synced,
            failed: failed,
            message: 'Sync paused until this account is active again.',
          );
        }
        // F8: already refused by the school's workflow — kept on this
        // phone until the user reviews or discards it. Never re-sent.
        if (batch['rejected'] == 1) continue;
        final classId = _asInt(batch['class_id']);
        final date = '${batch['date'] ?? ''}';
        if (classId <= 0 || date.isEmpty) continue;
        final kind = '${batch['packet_kind'] ?? 'draft'}';
        final opId = '${batch['client_op_id'] ?? ''}';
        try {
          final records = await _db.getPendingAttendanceRecords(classId, date);
          if (records.isEmpty) continue;
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
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
          if (res.sessionSuperseded || !_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          final outcome = classifyDrainResponse(res);
          if (outcome == DrainOutcome.accepted) {
            await _db.markAttendanceSynced(classId, date);
            synced++;
            didWork = true;
            lastError = '';
          } else if (outcome == DrainOutcome.rejected) {
            await _db.rejectAttendance(
                classId, date, res.message ?? 'Rejected by the server');
            lastError = res.message ?? 'Attendance was not accepted';
            await _db.logSync('attendance', lastError, 'rejected');
          } else {
            failed++;
            lastError = res.message ?? 'Attendance did not save.';
            await _db.logSync('attendance', lastError, 'error');
          }
        } catch (e) {
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          failed++;
          await _db.logSync('attendance', e.toString(), 'error');
        }
      }

      final pendingGrades = await _db.getPendingGrades();
      for (final batch in pendingGrades) {
        if (!_ownsGeneration(generation)) {
          return SyncResult(
            synced: synced,
            failed: failed,
            message: 'Sync paused until this account is active again.',
          );
        }
        // F8: refused by the school's workflow — kept for review.
        if (batch['rejected'] == 1) continue;
        final assessmentId = batch['assessment_id'] as int;
        final kind = '${batch['packet_kind'] ?? 'draft'}';
        final opId = '${batch['client_op_id'] ?? ''}';
        try {
          final records = await _db.getPendingGradeRecords(assessmentId);
          if (records.isEmpty) continue;
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
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
          if (res.sessionSuperseded || !_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          final outcome = classifyDrainResponse(res);
          if (outcome == DrainOutcome.accepted) {
            await _db.markGradesSynced(assessmentId);
            synced++;
            didWork = true;
            lastError = '';
          } else if (outcome == DrainOutcome.rejected) {
            await _db.rejectGrades(
                assessmentId, res.message ?? 'Rejected by the server');
            lastError = res.message ?? 'Grades were not accepted';
            await _db.logSync('grades', lastError, 'rejected');
          } else {
            failed++;
            lastError = res.message ?? 'Grades did not save.';
            await _db.logSync('grades', lastError, 'error');
          }
        } catch (e) {
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          failed++;
          await _db.logSync('grades', e.toString(), 'error');
        }
      }

      final pendingMez = await _db.getPendingMezmur();
      for (final batch in pendingMez) {
        if (!_ownsGeneration(generation)) {
          return SyncResult(
            synced: synced,
            failed: failed,
            message: 'Sync paused until this account is active again.',
          );
        }
        // F8: refused by the school's workflow — kept for review.
        if (batch['rejected'] == 1) continue;
        final date = '${batch['date'] ?? ''}';
        if (date.isEmpty) continue;
        final section = '${batch['section'] ?? ''}';
        final kind = '${batch['packet_kind'] ?? 'draft'}';
        final opId = '${batch['client_op_id'] ?? ''}';
        try {
          final records = await _db.getPendingMezmurRecords(date, section);
          if (records.isEmpty) continue;
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          final apiRecords = records
              .map((r) => {
                    'member_id': r['member_id'],
                    'status': r['status'],
                    'notes': '${r['notes'] ?? ''}',
                  })
              .toList();
          // Section-scoped packets (phase 5) carry kind + notes; legacy
          // date-only packets keep working through the old endpoint shape.
          final res = section.isNotEmpty
              ? await _api.saveMezmurSheet(date, apiRecords,
                  section: section, kind: kind, clientOpId: opId)
              : await _api.saveMezmurSheet(date, apiRecords,
                  clientOpId: opId);
          if (res.sessionSuperseded || !_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          final outcome = classifyDrainResponse(res);
          if (outcome == DrainOutcome.accepted) {
            await _db.markMezmurSynced(date, section);
            synced++;
            didWork = true;
            lastError = '';
          } else if (outcome == DrainOutcome.rejected) {
            await _db.rejectMezmur(date, section,
                res.message ?? 'Rejected by the server');
            lastError = res.message ?? 'Mezmur attendance was not accepted';
            await _db.logSync('mezmur', lastError, 'rejected');
          } else {
            failed++;
            lastError = res.message ?? 'Mezmur attendance did not save.';
            await _db.logSync('mezmur', lastError, 'error');
          }
        } catch (e) {
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          failed++;
          await _db.logSync('mezmur', e.toString(), 'error');
        }
      }

      // HR department attendance outbox — HR's OWN section-based
      // domain (/hr/sheet). Same packet model as mezmur; the data
      // streams never cross.
      final pendingHr = await _db.getPendingHr();
      for (final batch in pendingHr) {
        if (!_ownsGeneration(generation)) {
          return SyncResult(
            synced: synced,
            failed: failed,
            message: 'Sync paused until this account is active again.',
          );
        }
        // F8: refused by the school's workflow — kept for review.
        if (batch['rejected'] == 1) continue;
        final date = '${batch['date'] ?? ''}';
        if (date.isEmpty) continue;
        final section = '${batch['section'] ?? ''}';
        final kind = '${batch['packet_kind'] ?? 'draft'}';
        final opId = '${batch['client_op_id'] ?? ''}';
        try {
          final records = await _db.getPendingHrRecords(date, section);
          if (records.isEmpty) continue;
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          final apiRecords = records
              .map((r) => {
                    'member_id': r['member_id'],
                    'status': r['status'],
                    'notes': '${r['notes'] ?? ''}',
                  })
              .toList();
          final res = await _api.saveHrSheet(date, apiRecords,
              section: section, kind: kind, clientOpId: opId);
          if (res.sessionSuperseded || !_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          final outcome = classifyDrainResponse(res);
          if (outcome == DrainOutcome.accepted) {
            await _db.markHrSynced(date, section);
            synced++;
            didWork = true;
            lastError = '';
          } else if (outcome == DrainOutcome.rejected) {
            await _db.rejectHr(date, section,
                res.message ?? 'Rejected by the server');
            lastError = res.message ?? 'HR attendance was not accepted';
            await _db.logSync('hr_attendance', lastError, 'rejected');
          } else {
            failed++;
            lastError = res.message ?? 'HR attendance did not save.';
            await _db.logSync('hr_attendance', lastError, 'error');
          }
        } catch (e) {
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          failed++;
          await _db.logSync('hr_attendance', e.toString(), 'error');
        }
      }

      // Hymn library outbox (offline-first edits) + delta pull.
      // The store owns idempotency/conflict policy; here we count
      // outcomes and refresh the change-token cursor.
      try {
        final hymnStore = HymnStore();
        final before = await _db.getPendingHymnOpsCount();
        if (!_ownsGeneration(generation)) {
          return SyncResult(
            synced: synced,
            failed: failed,
            message: 'Sync paused until this account is active again.',
          );
        }
        if (before > 0) {
          final pushed = await hymnStore.pushPending();
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          final after = await _db.getPendingHymnOpsCount();
          if (pushed > 0 || after < before) {
            if (pushed > 0) synced++;
            didWork = true;
          } else {
            failed++;
            lastError = 'Hymn changes are still waiting to send.';
          }
        }
        if (ConnectivityService().hasLink && _ownsGeneration(generation)) {
          await hymnStore.pullChanges();
          if (!_ownsGeneration(generation)) {
            return SyncResult(
              synced: synced,
              failed: failed,
              message: 'Sync paused until this account is active again.',
            );
          }
          // P33: a delta may have added hymns to a pinned category or
          // replaced an audio object — top up / refresh offline copies.
          await MezmurDownloadManager.instance.syncPins();
        }
      } catch (e) {
        if (!_ownsGeneration(generation)) {
          return SyncResult(
            synced: synced,
            failed: failed,
            message: 'Sync paused until this account is active again.',
          );
        }
        await _db.logSync('hymns', e.toString(), 'error');
      }

      if (!didWork) break;
    } while (loops < 4);

    if (!_ownsGeneration(generation)) {
      return SyncResult(
        synced: synced,
        failed: failed,
        message: 'Sync paused until this account is active again.',
      );
    }
    await _db.cleanupSynced();
    if (!_ownsGeneration(generation)) {
      return SyncResult(
        synced: synced,
        failed: failed,
        message: 'Sync paused until this account is active again.',
      );
    }
    await _emitStatus();

    final pendingLeft = await _db.getTotalPendingCount();
    if (!_ownsGeneration(generation)) {
      return SyncResult(
        synced: synced,
        failed: failed,
        message: 'Sync paused until this account is active again.',
      );
    }
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

  Future<void> cacheForOffline() async {
    final generation = sessionGenerationProvider?.call() ?? 0;
    if (!_ownsGeneration(generation) || !_api.isLoggedIn) return;
    try {
      final dashRes = await _api.getDashboardStats();
      if (!dashRes.sessionSuperseded &&
          _ownsGeneration(generation) &&
          dashRes.success &&
          dashRes.data != null) {
        await _db.cacheDashboardStats(dashRes.data, _api.userRole);
      }
    } catch (_) {}
    if (!_ownsGeneration(generation)) return;
    try {
      await CatalogService().classes();
    } catch (_) {}
  }

  Future<void> _emitStatus({bool? syncing}) async {
    final pa = await _db.getPendingAttendanceCount();
    final pg = await _db.getPendingGradesCount();
    final pm = await _db.getPendingMezmurCount();
    final phr = await _db.getPendingHrCount();
    final ph = await _db.getPendingHymnOpsCount();
    final rejectedBatches = await _db.getRejectedBatches();
    _lastStatus = SyncStatus(
        pendingAttendance: pa,
        pendingGrades: pg,
        pendingMezmur: pm,
        pendingHr: phr,
        pendingHymns: ph,
        rejected: rejectedBatches.length,
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
  final int pendingHr;
  final int pendingHymns;
  final bool syncing;

  /// F8: batches the school's workflow refused (kept on this phone
  /// until reviewed or discarded). Included in totalPending — they
  /// are genuinely "not yet sent" — but called out separately so no
  /// screen can claim they are merely waiting for the network.
  final int rejected;
  bool get needsAttention => rejected > 0;

  int get totalPending => pendingAttendance +
      pendingGrades +
      pendingMezmur +
      pendingHr +
      pendingHymns;
  String get breakdown {
    if (totalPending <= 0 && rejected <= 0) return 'All synced';
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
    if (pendingHr > 0) {
      parts.add('$pendingHr HR sheet${pendingHr == 1 ? '' : 's'}');
    }
    if (pendingHymns > 0) {
      parts.add('$pendingHymns hymn change${pendingHymns == 1 ? '' : 's'}');
    }
    if (rejected > 0) {
      parts.add('$rejected need${rejected == 1 ? 's' : ''} attention');
    }
    return parts.join(' · ');
  }

  SyncStatus(
      {required this.pendingAttendance,
      required this.pendingGrades,
      this.pendingMezmur = 0,
      this.pendingHr = 0,
      this.pendingHymns = 0,
      this.rejected = 0,
      required this.syncing});
}

class SyncResult {
  final int synced;
  final int failed;
  final String message;
  SyncResult(
      {required this.synced, required this.failed, required this.message});
}

/// F8 — how a legacy-outbox drain response must be treated.
enum DrainOutcome {
  /// The server applied the packet, or validly replayed it (a true
  /// idempotent replay returns the ORIGINAL 200 + body, so it lands
  /// in `res.success` — response-loss retries stay safe).
  accepted,

  /// The school's workflow refused the packet for good: the day/test
  /// was already submitted by another role, a business rule blocked
  /// it, or the idempotency key was misused. The data was NOT
  /// applied and resending the same bytes can never succeed — mark
  /// the batch rejected, keep it on this phone, surface it honestly.
  rejected,

  /// Network error / auth hiccup / timeout / rate limit / server
  /// error / request still processing. Retry later, exactly like a
  /// plain failure. Never destroys data, never reports success.
  transient,
}

/// Classifies a drain response for the four legacy outboxes
/// (attendance, grades, mezmur, HR). Pure function — pinned by
/// test/drain_outcome_test.dart.
///
/// Evidence (api/v1/core/middleware.php): apiIdempotencyBegin runs
/// BEFORE every workflow-lock check, and a replay returns the
/// original 200 + `Idempotency-Replayed: true` — so a 409 from these
/// routes is never a replay. Outbox-reachable 409s carry a
/// machine-readable `code` (merged into the body by err()'s $extra):
///   ALREADY_SUBMITTED / WORKFLOW_REJECTED / IDEMPOTENCY_CONFLICT
///     → rejected (server state or rule refuses the packet)
///   IDEMPOTENCY_IN_PROGRESS (+ Retry-After) → transient
///
/// A 409 WITHOUT a known code (old server before this deploy)
/// → transient: retry like a failure. The invariant of this fix is
/// that a 409 must never be treated as SUCCESS; refusing to guess
/// beyond that keeps old-server behavior unchanged (retry, no data
/// loss, no false success) instead of risking a wrong verdict.
DrainOutcome classifyDrainResponse(ApiResponse res) {
  if (res.success) return DrainOutcome.accepted;
  if (res.isNetworkError) return DrainOutcome.transient;
  final code =
      res.data is Map ? '${(res.data as Map)['code'] ?? ''}' : '';
  final status = res.statusCode;
  if (status == 409) {
    if (code == 'IDEMPOTENCY_IN_PROGRESS') return DrainOutcome.transient;
    if (code == 'ALREADY_SUBMITTED' ||
        code == 'WORKFLOW_REJECTED' ||
        code == 'IDEMPOTENCY_CONFLICT') {
      return DrainOutcome.rejected;
    }
    return DrainOutcome.transient; // unknown 409 — never guess
  }
  if (status == 401 || status == 408 || status == 429 || status >= 500) {
    return DrainOutcome.transient;
  }
  // Definite protocol refusals of this exact packet: retrying the
  // same bytes can never succeed (minimal F9 touch, F8 directive §9).
  if (status == 400 || status == 403 || status == 404 || status == 422) {
    return DrainOutcome.rejected;
  }
  return DrainOutcome.transient; // unknown shape — never destroy data
}
