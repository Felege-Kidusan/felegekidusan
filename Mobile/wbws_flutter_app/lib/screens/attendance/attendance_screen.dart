import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import '../../services/api_service.dart';
import '../../services/app_nav.dart';
import '../../services/catalog_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/sync_service.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/packet.dart';
import '../../utils/roster.dart';
import '../../utils/scrolling.dart';
import '../../utils/theme.dart';
import '../../widgets/action_bar.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/fast_list.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/quick_confirm.dart';
import '../../widgets/qr_scan_sheet.dart';
import '../../widgets/status_banner.dart';
import '../../services/qr_attendance.dart';

class AttendanceScreen extends StatefulWidget {
  final int? initialClassId;
  const AttendanceScreen({super.key, this.initialClassId});

  @override
  State<AttendanceScreen> createState() => AttendanceScreenState();
}

class AttendanceScreenState extends State<AttendanceScreen> {
  final _api = ApiService();
  final _db = LocalDb();
  final _sync = SyncService();

  List<dynamic> _classes = [];
  int? _selectedClassId;
  String? _selectedClassName;
  String _selectedDate = DateFormat('yyyy-MM-dd').format(DateTime.now());
  List<Map<String, dynamic>> _students = [];
  bool _loadingClasses = true;
  bool _loadingStudents = false;
  bool _rosterReady = false;
  bool _saving = false; // Kept for API symmetry; saves are non-blocking now.
  bool _isOffline = false;
  bool _loadFailed = false;
  String? _error;
  String? _successMsg;
  String? _rosterNote;
  String _packetStatus = '';
  /// Education's reason when this sheet was returned for correction.
  String? _returnNote;
  int _pendingCount = 0;
  /// WhatsApp-state visibility: true the moment anything changes after a
  /// save; drives the Save button's grayed-out/lit appearance without
  /// rebuilding the student list (ValueListenable -> action bar only).
  final ValueNotifier<bool> _dirty = ValueNotifier(false);
  StreamSubscription<bool>? _netSub;

  // Phase 8: instant autosave (every mutation → durable local draft).
  final Debounce _autoSave = Debounce();
  DateTime _lastSyncPush = DateTime.fromMillisecondsSinceEpoch(0);

  static const Set<String> _validStatuses = {
    'present',
    'absent',
    'late',
    'excused',
  };

  String _statusOf(dynamic value) {
    final status = '${value ?? ''}'.trim().toLowerCase();
    return _validStatuses.contains(status) ? status : '';
  }

  String _firstStatus(Iterable<dynamic> values) {
    for (final value in values) {
      final status = _statusOf(value);
      if (status.isNotEmpty) return status;
    }
    return '';
  }

  @override
  void initState() {
    super.initState();
    _isOffline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (!mounted || _isOffline == !hasLink) return;
      setState(() => _isOffline = !hasLink);
    });
    _loadClasses();
    _updatePendingCount();

    // Listen for sync updates
    _sync.syncStream.listen((status) {
      if (mounted) {
        setState(() => _pendingCount = status.pendingAttendance);
      }
    });
  }

  @override
  void dispose() {
    _netSub?.cancel();
    _autoSave.dispose();
    _dirty.dispose();
    super.dispose();
  }

  void refresh() {
    _loadClasses();
    _updatePendingCount();
  }

  Future<void> _updatePendingCount() async {
    final count = await _db.getPendingAttendanceCount();
    if (mounted) setState(() => _pendingCount = count);
  }

  Future<void> _loadClasses() async {
    final warm = CatalogService().cached;
    if (warm.isNotEmpty && mounted) {
      setState(() { _classes = warm; _loadingClasses = false; });
    } else if (mounted) {
      setState(() => _loadingClasses = true);
    }

    final classes = await CatalogService().classes();
    if (!mounted) return;
    final online = ConnectivityService().hasLink;
    setState(() {
      if (classes.isNotEmpty) {
        _classes = classes;
        _error = null;
      } else if (_classes.isEmpty) {
        _error = online
            ? 'No classes assigned'
            : 'Could not load classes. Waiting for network.';
      }
      _loadingClasses = false;
      _isOffline = !online;
    });
    AppNav().markAttendanceLoaded();

    if (_classes.isNotEmpty && _selectedClassId == null) {
      int? pick;
      final wanted = AppNav().attendanceClassId ?? widget.initialClassId;
      if (wanted != null) {
        for (final c in _classes) {
          final id = c['id'] is int ? c['id'] as int : int.tryParse('${c['id']}');
          if (id == wanted) pick = id;
        }
      }
      pick ??= _classes.first['id'] is int
          ? _classes.first['id'] as int
          : int.tryParse('${_classes.first['id']}');
      setState(() {
        _selectedClassId = pick;
        _selectedClassName = _classes.firstWhere(
            (c) => (c['id'] is int ? c['id'] : int.tryParse('${c['id']}')) == pick,
            orElse: () => _classes.first)['class_name'];
        _rosterReady = false;
        _loadingStudents = true;
      });
      _loadAttendance();
    }
  }

  Future<void> _loadAttendance() async {
    if (_selectedClassId == null) return;
    final keepSheet = _students.isNotEmpty;
    setState(() {
      _error = null;
      _successMsg = null;
      _loadFailed = false;
      _rosterNote = null;
      if (!keepSheet) {
        _rosterReady = false;
        _loadingStudents = true;
      }
    });

    // 1. Try cache first
    final cachedSheet = await _db.getCachedAttendanceSheet(_selectedClassId!, _selectedDate);
    final cachedAtt = cachedSheet == null
        ? null
        : List<Map<String, dynamic>>.from(cachedSheet['students'] as List);
    final cacheLocked = PacketLock.isLocked(
      '${cachedSheet?['submission_status'] ?? ''}',
      flagged: cachedSheet?['locked'] == true,
    );
    if (cacheLocked && mounted) {
      setState(() {
        _packetStatus = PacketLock.label('${cachedSheet?['submission_status'] ?? ''}').isEmpty
            ? 'submitted'
            : '${cachedSheet?['submission_status']}';
      });
      await _db.dropPendingAttendance(_selectedClassId!, _selectedDate);
    }

    final pending = cacheLocked
        ? <Map<String, dynamic>>[]
        : await _db.getPendingAttendanceRecords(_selectedClassId!, _selectedDate);
    final pendingMap = <int, String>{};
    final pendingNotes = <int, String>{};
    for (final p in pending) {
      final mid = RosterParse.asInt(p['member_id']);
      if (mid == null) continue;
      final status = _statusOf(p['status']);
      if (status.isNotEmpty) pendingMap[mid] = status;
      pendingNotes[mid] = '${p['notes'] ?? p['note'] ?? ''}';
    }

    if (cachedAtt != null && cachedAtt.isNotEmpty) {
      final students = <Map<String, dynamic>>[];
      for (final s in cachedAtt) {
        final mid = RosterParse.asInt(s['member_id']) ?? RosterParse.asInt(s['id']);
        if (mid == null) continue;
        students.add({
          ...s,
          'member_id': mid,
          'status': _firstStatus([pendingMap[mid], s['status'], s['att_status']]),
          'notes': pendingNotes[mid] ?? s['notes'] ?? s['note'] ?? '',
        });
      }
      if (mounted) setState(() { _students = students; _loadingStudents = false; _rosterReady = true; });
    } else {
      final cachedStudents = await _db.getCachedStudents(_selectedClassId!);
      if (cachedStudents.isNotEmpty) {
        final students = <Map<String, dynamic>>[];
        for (final s in cachedStudents) {
          final mid = RosterParse.asInt(s['member_id']) ?? RosterParse.asInt(s['id']);
          if (mid == null) continue;
          students.add({
            'member_id': mid,
            'student_name': s['student_name'] ?? '',
            'father_name': s['father_name'] ?? '',
            'member_code': s['member_code'] ?? '',
            'gender': s['gender'] ?? '',
            'status': _statusOf(pendingMap[mid]),
            'notes': pendingNotes[mid] ?? '',
          });
        }
        if (mounted) setState(() { _students = students; _loadingStudents = false; _rosterReady = true; });
      } else {
        if (mounted) setState(() { _loadingStudents = true; _rosterReady = false; });
      }
    }

    // 2. Fetch fresh data silently
    final res = await _api.getAttendance(_selectedClassId!, date: _selectedDate);
    if (!mounted) return;

    if (res.success && res.data != null) {
      final parsed = RosterParse.students(res.data);
      if (parsed.isEmpty && RosterParse.reportedCount(res.data) > 0) {
        setState(() {
          _error = 'The server sent students but this phone could not read them. Pull to refresh.';
          _loadFailed = true;
          _loadingStudents = false;
          _rosterReady = true;
        });
        return;
      }
      final packetEarly = '${res.data['submission_status'] ?? ''}';
      final lockedEarly = PacketLock.isLocked(packetEarly, flagged: res.data['locked'] == true);
      final students = parsed.map((s) {
        final mid = s['member_id'] as int;
        return <String, dynamic>{
          'member_id': mid,
          'student_name': s['student_name'] ?? '',
          'father_name': s['father_name'] ?? '',
          'member_code': s['member_code'] ?? '',
          'gender': s['gender'] ?? '',
          'status': lockedEarly
              ? _statusOf(s['att_status'])
              : _firstStatus([pendingMap[mid], s['att_status']]),
          'notes': lockedEarly ? (s['notes'] ?? s['note'] ?? '') : (pendingNotes[mid] ?? s['notes'] ?? s['note'] ?? ''),
        };
      }).toList();

      String? note;
      if (RosterParse.fallback(res.data)) {
        final year = RosterParse.yearName(res.data);
        note = year == null
            ? 'Showing students from a previous year. Ask Education to enroll them for this year too.'
            : 'Showing the $year roster. Ask Education to enroll them for this year too.';
      }

      final packet = '${res.data['submission_status'] ?? ''}';
      final locked = PacketLock.isLocked(packet, flagged: res.data['locked'] == true);
      if (locked) {
        await _db.dropPendingAttendance(_selectedClassId!, _selectedDate);
      }
      setState(() {
        _students = students;
        _loadingStudents = false;
        _rosterReady = true;
        _isOffline = !ConnectivityService().hasLink;
        _loadFailed = false;
        _rosterNote = note;
        _packetStatus = locked && packet.isEmpty ? 'submitted' : packet;
        _returnNote = PacketLock.returnNote(res.data);
      });
      await _db.cacheStudents(_selectedClassId!, students);
      await _db.cacheAttendanceResponse(
        _selectedClassId!,
        _selectedDate,
        students,
        submissionStatus: _packetStatus,
        locked: locked,
      );
    } else if (_students.isEmpty && (cachedAtt == null || cachedAtt.isEmpty)) {
      setState(() {
        _error = res.message ?? 'Could not load students. Check your connection and try again.';
        _loadingStudents = false;
        _rosterReady = true;
        _loadFailed = true;
      });
    } else {
      setState(() {
        _loadingStudents = false;
        _rosterReady = true;
        _error = null;
      });
    }
  }

  bool get _locked => PacketLock.isLocked(_packetStatus);

  bool _requireCompleteSheet() {
    final unmarked = _students.where((student) => _statusOf(student['status']).isEmpty).length;
    if (unmarked == 0) return true;
    setState(() {
      _error = 'Mark attendance for every student ($unmarked remaining).';
      _successMsg = null;
    });
    return false;
  }

  /// Telegram-send model: the SQLite write IS the save (~5 ms). Delivery is
  /// the SyncService outbox's job and is never awaited from a button tap —
  /// no spinner, no "Sending…" text, buttons stay live (last write wins:
  /// saving again simply replaces the queued packet).
  Future<void> _saveAttendance() async {
    if (_selectedClassId == null || _students.isEmpty) return;
    if (_locked) return;
    if (!_requireCompleteSheet()) return;

    final records = _records();
    try {
      await _db.saveAttendanceLocal(
        _selectedClassId!,
        _selectedClassName ?? '',
        _selectedDate,
        records,
        packetKind: 'draft',
      );
    } catch (_) {
      if (mounted) {
        setState(() {
          _error =
              'Phone storage refused the save. Free up space and try again.';
          _successMsg = null;
        });
      }
      return;
    }
    if (!mounted) return;
    HapticFeedback.selectionClick(); // Telegram send-tick: saved on phone.
    showQuickConfirm(context, 'Saved');
    _dirty.value = false;
    setState(() {
      _packetStatus = 'draft';
      _error = null;
      _successMsg = null;
      _returnNote = null;
    });
    // Fire-and-forget. The outbox coalesces, retries with backoff, and
    // updates the pending pill through its stream.
    unawaited(_sync.syncAll(force: true));
  }

  /// Instant submit — no confirmation dialog. A short UNDO window replaces
  /// it; undo refuses honestly if the outbox already delivered the packet.
  Future<void> _submitAttendance() async {
    if (_selectedClassId == null || _students.isEmpty) return;
    if (_locked) return;
    if (!_requireCompleteSheet()) return;

    final records = _records();
    try {
      await _db.saveAttendanceLocal(_selectedClassId!, _selectedClassName ?? '',
          _selectedDate, records,
          packetKind: 'submitted');
    } catch (_) {
      if (mounted) {
        setState(() {
          _error =
              'Phone storage refused the save. Free up space and try again.';
          _successMsg = null;
        });
      }
      return;
    }
    if (!mounted) return;
    HapticFeedback.mediumImpact();
    _dirty.value = false;
    setState(() {
      _packetStatus = 'submitted';
      _error = null;
      _successMsg = null;
    });
    unawaited(_sync.syncAll(force: true));
    // 5-second decision window with a visible countdown; when the ring
    // empties the toast disappears and the sheet stays locked for Education.
    showUndoToast(context, message: 'Submitted', onUndo: _undoSubmit);
  }

  Future<void> _undoSubmit() async {
    final classId = _selectedClassId;
    if (classId == null) return;
    final stillOnPhone =
        await _db.getPendingAttendanceRecords(classId, _selectedDate);
    if (!mounted) return;
    if (stillOnPhone.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        duration: Duration(seconds: 2),
        content: Text('Already sent to Education'),
      ));
      return;
    }
    try {
      await _db.saveAttendanceLocal(classId, _selectedClassName ?? '',
          _selectedDate, _records(),
          packetKind: 'draft');
    } catch (_) {
      return;
    }
    if (!mounted) return;
    setState(() => _packetStatus = 'draft');
    _dirty.value = false;
  }

  List<Map<String, dynamic>> _records() {
    return _students
        .map((s) => <String, dynamic>{
              'member_id': s['member_id'],
              'student_name': s['student_name'],
              'father_name': s['father_name'],
              'member_code': s['member_code'],
              'status': _statusOf(s['status']),
              'notes': s['notes'] ?? s['note'] ?? '',
            })
        .toList();
  }

  // ── Phase 8: instant autosave + QR scan ──────────────────────
  void _scheduleAutoSave() {
    _autoSave.run(const Duration(milliseconds: 700), _autoSaveNow);
  }

  /// SQLite write IS the save: persist the currently-marked rows as a
  /// draft packet now (no completeness gate — drafts may be partial).
  /// A dead battery after this point loses nothing; the outbox syncs.
  Future<void> _autoSaveNow() async {
    final classId = _selectedClassId;
    if (classId == null || _students.isEmpty || _locked) return;
    final marked =
        _records().where((r) => '${r['status'] ?? ''}'.isNotEmpty).toList();
    if (marked.isEmpty) return;
    try {
      await _db.saveAttendanceLocal(
          classId, _selectedClassName ?? '', _selectedDate, marked,
          packetKind: 'draft');
    } catch (_) {
      return; // storage refused; the manual Save surfaces the error
    }
    if (!mounted) return;
    _dirty.value = true;
    final now = DateTime.now();
    if (now.difference(_lastSyncPush) > const Duration(seconds: 10)) {
      _lastSyncPush = now;
      unawaited(_sync.syncAll(force: false));
    }
  }

  void _openQrScan() {
    QrScanSheet.open(context,
        onScan: _handleQrScan,
        header: _selectedClassName ?? 'Attendance');
  }

  /// Apply the Education rules to one scan: roster membership for the
  /// open class, duplicates, lock; success marks Present and autosaves
  /// immediately. Mirrors the manual-tap path (last write wins).
  Future<QrFeedback> _handleQrScan(String? raw) async {
    final code = QrAttendance.extractMemberCode(raw);
    if (code == null) return QrFeedback.invalid();
    if (_locked) return QrFeedback.locked();
    if (_selectedClassId == null || _students.isEmpty) {
      return QrFeedback.notFound();
    }
    final idx =
        _students.indexWhere((s) => '${s['member_code'] ?? ''}' == code);
    if (idx < 0) {
      final known = await _db.findCachedMemberByCode(code);
      if (known == null) return QrFeedback.notFound();
      final name = '${known['student_name'] ?? ''}';
      final st = '${known['status'] ?? ''}';
      if (st.isNotEmpty && st != 'active') {
        return QrFeedback.inactive(name: name);
      }
      final own = await _db.cachedClassNameOfMember(
          (known['id'] is int) ? known['id'] as int : int.tryParse('${known['id']}') ?? 0);
      return QrFeedback.wrongGroup(name: name, ownGroup: own ?? '—');
    }
    final s = _students[idx];
    final existing = _statusOf(s['status']);
    if (existing.isNotEmpty) {
      return QrFeedback.duplicate(
          name: '${s['student_name'] ?? ''}', status: existing);
    }
    setState(() => s['status'] = 'present');
    _dirty.value = true;
    await _autoSaveNow(); // scan = instantly durable
    return QrFeedback.ok(name: '${s['student_name'] ?? ''}');
  }

  Future<void> _persistLocal() async {
    if (_selectedClassId == null || _students.isEmpty || _locked) return;
    await _db.cacheAttendanceResponse(
      _selectedClassId!,
      _selectedDate,
      _students,
      submissionStatus: _packetStatus,
      locked: false,
    );
  }

  void _markAll(String status) {
    if (_locked) return;
    setState(() {
      for (var s in _students) {
        s['status'] = status;
      }
    });
    _dirty.value = true;
    _persistLocal();
    _scheduleAutoSave();
  }

  Future<void> _pickDate() async {
    final picked = await showEthiopianDatePicker(
      context: context,
      initialGregorianDate: _selectedDate,
      firstDate: DateTime(2024),
      lastDate: DateTime.now(),
    );
    if (picked != null) {
      _selectedDate = picked; // Returns Gregorian yyyy-MM-dd for API
      _loadAttendance();
    }
  }

  Future<void> _manualSync() async {
    final result = await _sync.syncAll(force: true);
    if (!mounted) return;

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(result.message),
        backgroundColor:
            result.failed > 0 ? AppTheme.warning : AppTheme.success,
      ),
    );
    await _updatePendingCount();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Attendance'),
        automaticallyImplyLeading: Navigator.canPop(context),
        actions: [
          // Scan lives in the top header (never covers roster data).
          if (!_locked)
            TextButton.icon(
              onPressed: _openQrScan,
              icon: const Icon(Icons.qr_code_scanner, size: 22),
              label: const Text('Scan',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
              style: TextButton.styleFrom(foregroundColor: Colors.white),
            ),
          // Pending sync badge
          if (_pendingCount > 0)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: IconButton(
                onPressed: _manualSync,
                tooltip: '$_pendingCount pending sync',
                icon: Badge(
                  label: Text('$_pendingCount',
                      style: const TextStyle(fontSize: 9)),
                  backgroundColor: AppTheme.warning,
                  child: const Icon(Icons.sync, size: 22),
                ),
              ),
            ),
          if (_packetStatus.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(right: 12),
              child: Center(
                child: Text(
                  PacketLock.label(_packetStatus).isEmpty
                      ? _packetStatus
                      : PacketLock.label(_packetStatus),
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 12),
                ),
              ),
            ),
        ],
      ),
      bottomNavigationBar: _students.isEmpty
          ? null
          : _locked
              ? const SubmittedBar()
              : TeacherActionBar(
                  saveLabel: 'Save',
                  submitLabel: 'Submit',
                  onSave: _saveAttendance,
                  onSubmit: _submitAttendance,
                  saveEnabled: _dirty,
                ),
      body: Column(
        children: [
          // Offline indicator
          if (_isOffline)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              color: AppTheme.warning.withOpacity(0.15),
              child: Row(
                children: [
                  Icon(Icons.cloud_off, size: 16, color: AppTheme.warning),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Waiting for network — kept on this phone until you are back online',
                      style: TextStyle(
                          fontSize: 11,
                          color: AppTheme.warning,
                          fontWeight: FontWeight.w500),
                    ),
                  ),
                  if (_pendingCount > 0)
                    GestureDetector(
                      onTap: _manualSync,
                      child: Text('Sync now',
                          style: TextStyle(
                              fontSize: 11,
                              color: AppTheme.primary,
                              fontWeight: FontWeight.w600)),
                    ),
                ],
              ),
            ),

          // Class + Date selector
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Expanded(
                  flex: 3,
                  child: _loadingClasses
                      ? const LinearProgressIndicator()
                      : DropdownButtonFormField<int>(
                          value: _selectedClassId,
                          hint: const Text('Select class',
                              style: TextStyle(fontSize: 13)),
                          isExpanded: true,
                          decoration: InputDecoration(
                            contentPadding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 10),
                            border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(10)),
                          ),
                          items: _classes
                              .map<DropdownMenuItem<int>>((c) =>
                                  DropdownMenuItem(
                                    value: c['id'] is int
                                        ? c['id']
                                        : int.tryParse('${c['id']}'),
                                    child: Text('${c['class_name']}',
                                        style: const TextStyle(fontSize: 13),
                                        overflow: TextOverflow.ellipsis),
                                  ))
                              .toList(),
                            onChanged: (v) {
                              setState(() {
                                _selectedClassId = v;
                                _selectedClassName = _classes
                                    .firstWhere((c) =>
                                        (c['id'] is int
                                            ? c['id']
                                            : int.tryParse('${c['id']}')) ==
                                        v)['class_name'];
                                _students = [];
                                _rosterReady = false;
                                _loadingStudents = true;
                                _packetStatus = '';
                              });
                              _dirty.value = false;
                              _returnNote = null;
                              _loadAttendance();
                            },
                        ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 2,
                  child: InkWell(
                    onTap: _pickDate,
                    child: InputDecorator(
                      decoration: InputDecoration(
                        contentPadding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 10),
                        border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10)),
                        suffixIcon: const Icon(Icons.calendar_today, size: 16),
                      ),
                      child: Text(formatGregorianAsEthiopian(_selectedDate),
                          style: const TextStyle(fontSize: 13)),
                    ),
                  ),
                ),
              ],
            ),
          ),

          if (_students.isNotEmpty && _error == null) _progressStrip(),
          if (_error != null)
            StatusBanner.error(_error!, onRetry: _loadAttendance),
          if (_successMsg != null)
            StatusBanner.success(_successMsg!, onDismiss: () {
              if (mounted) setState(() => _successMsg = null);
            }),
          if (_rosterNote != null && _error == null)
            StatusBanner.warning(_rosterNote!),
          if (_returnNote != null &&
              _returnNote!.isNotEmpty &&
              !_locked)
            StatusBanner.warning(
                'Returned by Education — you can edit and resubmit. $_returnNote'),
          if (_locked && _students.isNotEmpty && _error == null)
            StatusBanner.warning(PacketLock.viewOnlyHint(_packetStatus)),

          // Quick mark all
          if (_students.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              child: Row(
                children: [
                  Text(
                    '${_students.length} students · '
                    '${_students.where((student) => _statusOf(student['status']).isEmpty).length} unmarked',
                    style: TextStyle(fontSize: 12, color: AppTheme.textSecondary),
                  ),
                  const Spacer(),
                  if (!_locked) ...[
                    _quickBtn(
                        'All Present', AppTheme.success, () => _markAll('present')),
                    const SizedBox(width: 6),
                    _quickBtn(
                        'All Absent', AppTheme.danger, () => _markAll('absent')),
                  ],
                ],
              ),
            ),

          // Student list
          Expanded(
            child: (_loadingStudents || (!_rosterReady && _selectedClassId != null))
                ? const StudentListSkeleton()
                : RefreshIndicator(
                    onRefresh: _loadAttendance,
                    child: _loadFailed && _students.isEmpty
                        ? ListView(
                            physics: const AlwaysScrollableScrollPhysics(),
                            children: [
                              Padding(
                                padding: const EdgeInsets.all(16),
                                child: AppErrorCard(
                                  error: AppError.fromMessage(_error,
                                      isNetwork: (_error ?? '').toLowerCase().contains('internet') ||
                                          (_error ?? '').toLowerCase().contains('connection')),
                                  onRetry: _loadAttendance,
                                ),
                              ),
                            ],
                          )
                        : _students.isEmpty
                            ? ListView(
                                physics: const AlwaysScrollableScrollPhysics(),
                                children: [
                                  const SizedBox(height: 40),
                                  EmptyState(
                                    icon: Icons.people_outline,
                                    title: _selectedClassId == null
                                        ? 'Select a class'
                                        : 'No students in this class yet',
                                    subtitle: _selectedClassId == null
                                        ? 'Pick a class above to take attendance.'
                                        : 'If they were enrolled on the website, pull down to refresh. Education can also add them under Enrollment.',
                                    action: _selectedClassId == null
                                        ? null
                                        : TextButton.icon(
                                            onPressed: _loadAttendance,
                                            icon: const Icon(Icons.refresh, size: 18),
                                            label: const Text('Refresh'),
                                          ),
                                  ),
                                ],
                              )
                            : ListView.builder(
                                physics: const AlwaysScrollableScrollPhysics(),
                                itemCount: _students.length,
                                cacheExtent: kListCacheExtent,
                                padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                                itemBuilder: (_, i) =>
                                    RepaintBoundaryListItem(child: _studentRow(i)),

                              ),
                  ),
          ),
        ],
      ),
    );
  }

  Future<void> _editNote(int index) async {
    final ctrl = TextEditingController(text: '${_students[index]['notes'] ?? ''}');
    final note = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Note'),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          maxLines: 3,
          maxLength: 500,
          decoration: const InputDecoration(hintText: 'Optional note'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: const Text('Keep')),
        ],
      ),
    );
    if (note == null) return;
    if (note == '${_students[index]['notes'] ?? ''}') return;
    setState(() => _students[index]['notes'] = note);
    _dirty.value = true;
    _persistLocal();
  }

  Widget _quickBtn(String label, Color color, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(6),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: color.withOpacity(0.1),
          borderRadius: BorderRadius.circular(6),
          border: Border.all(color: color.withOpacity(0.3)),
        ),
        child: Text(label,
            style: TextStyle(
                fontSize: 11, color: color, fontWeight: FontWeight.w600)),
      ),
    );
  }

  Widget _studentRow(int index) {
    final s = _students[index];
    final status = _statusOf(s['status']);

    return FastListRow(
      index: index,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      child: Row(
        children: [
          SizedBox(
            width: 28,
            child: Text('${index + 1}',
                style:
                    TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
          ),
          Expanded(
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: () => _editNote(index),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${s['student_name']} ${s['father_name']}',
                      style: const TextStyle(
                          fontSize: 13.5,
                          fontWeight: FontWeight.w700),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis),
                  Text(
                    (s['notes'] != null && '${s['notes']}'.trim().isNotEmpty)
                        ? '${s['notes']}'
                        : (s['member_code'] != null &&
                                s['member_code'].toString().isNotEmpty)
                            ? '${s['member_code']}'
                            : 'Tap for note',
                    style: TextStyle(
                        fontSize: 10, color: AppTheme.textSecondary),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          ),
          _statusBtn('P', 'present', status, AppTheme.success, index),
          const SizedBox(width: 4),
          _statusBtn('A', 'absent', status, AppTheme.danger, index),
          const SizedBox(width: 4),
          _statusBtn('L', 'late', status, AppTheme.warning, index),
          const SizedBox(width: 4),
          _statusBtn('E', 'excused', status, AppTheme.info, index),
        ],
      ),
    );
  }

  Widget _statusBtn(
      String label, String value, String current, Color color, int index) {
    final selected = current == value;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: _locked
          ? null
          : () {
              HapticFeedback.lightImpact();
              setState(() => _students[index]['status'] = value);
              _dirty.value = true;
              _persistLocal();
              _scheduleAutoSave();
            },
      child: AnimatedScale(
        scale: selected ? 1.08 : 1.0,
        duration: const Duration(milliseconds: 160),
        curve: Curves.easeOut,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          curve: Curves.easeOut,
          width: 40,
          height: 40,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: selected ? color : color.withOpacity(0.08),
            borderRadius: BorderRadius.circular(10),
            border:
                Border.all(color: selected ? color : color.withOpacity(0.2)),
            boxShadow: selected
                ? [
                    BoxShadow(
                        color: color.withOpacity(0.35),
                        blurRadius: 6,
                        offset: const Offset(0, 2))
                  ]
                : null,
          ),
          child: Text(label,
              style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w800,
                color: selected ? Colors.white : color,
              )),
        ),
      ),
    );
  }

  /// Animated "marked so far" progress strip (fills as marks land).
  Widget _progressStrip() {
    final total = _students.length;
    final marked =
        _students.where((s) => _statusOf(s['status']).isNotEmpty).length;
    final frac = total == 0 ? 0.0 : marked / total;
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 220),
            child: Text(
              '$marked / $total marked',
              key: ValueKey(marked),
              style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppTheme.textSecondary),
            ),
          ),
          const SizedBox(height: 4),
          TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: frac),
            duration: const Duration(milliseconds: 350),
            curve: Curves.easeOut,
            builder: (context, v, child) => ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: LinearProgressIndicator(
                value: v,
                minHeight: 6,
                valueColor: AlwaysStoppedAnimation<Color>(
                    frac >= 1 ? AppTheme.success : AppTheme.primary),
                backgroundColor: AppTheme.primary.withOpacity(0.12),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
