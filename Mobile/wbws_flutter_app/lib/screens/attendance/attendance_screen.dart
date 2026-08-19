import 'dart:async';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../services/api_service.dart';
import '../../services/app_nav.dart';
import '../../services/catalog_service.dart';
import '../../services/connectivity_service.dart';
import '../../services/local_db.dart';
import '../../services/sync_service.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/roster.dart';
import '../../utils/theme.dart';
import '../../widgets/action_bar.dart';
import '../../widgets/app_error.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/loading_skeleton.dart';
import '../../widgets/status_banner.dart';

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
  bool _saving = false;
  bool _isOffline = false;
  bool _loadFailed = false;
  String? _error;
  String? _successMsg;
  String? _rosterNote;
  String _packetStatus = '';
  int _pendingCount = 0;
  StreamSubscription<bool>? _netSub;

  @override
  void initState() {
    super.initState();
    _isOffline = !ConnectivityService().hasLink;
    _netSub = ConnectivityService().statusStream.listen((hasLink) {
      if (mounted) setState(() => _isOffline = !hasLink);
    });
    _loadClasses();
    _updatePendingCount();

    // Listen for sync updates
    _sync.syncStream.listen((status) {
      if (mounted) {
        setState(() => _pendingCount = status.totalPending);
      }
    });
  }

  @override
  void dispose() {
    _netSub?.cancel();
    super.dispose();
  }

  void refresh() {
    _loadClasses();
    _updatePendingCount();
  }

  Future<void> _updatePendingCount() async {
    final count = await _db.getTotalPendingCount();
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
    final pending = await _db.getPendingAttendanceRecords(_selectedClassId!, _selectedDate);
    final pendingMap = <int, String>{};
    final pendingNotes = <int, String>{};
    for (final p in pending) {
      final mid = RosterParse.asInt(p['member_id']);
      if (mid == null) continue;
      pendingMap[mid] = '${p['status'] ?? 'present'}';
      pendingNotes[mid] = '${p['notes'] ?? p['note'] ?? ''}';
    }

    final cachedAtt = await _db.getCachedAttendanceResponse(_selectedClassId!, _selectedDate);
    if (cachedAtt != null && cachedAtt.isNotEmpty) {
      final students = <Map<String, dynamic>>[];
      for (final s in cachedAtt) {
        final mid = RosterParse.asInt(s['member_id']) ?? RosterParse.asInt(s['id']);
        if (mid == null) continue;
        students.add({
          ...s,
          'member_id': mid,
          'status': pendingMap[mid] ?? s['status'] ?? s['att_status'] ?? 'present',
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
            'status': pendingMap[mid] ?? 'present',
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
      final students = parsed.map((s) {
        final mid = s['member_id'] as int;
        return <String, dynamic>{
          'member_id': mid,
          'student_name': s['student_name'] ?? '',
          'father_name': s['father_name'] ?? '',
          'member_code': s['member_code'] ?? '',
          'gender': s['gender'] ?? '',
          'status': pendingMap[mid] ?? s['att_status'] ?? 'present',
          'notes': pendingNotes[mid] ?? s['notes'] ?? s['note'] ?? '',
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
      final locked = res.data['locked'] == true ||
          packet == 'submitted' ||
          packet == 'approved';
      setState(() {
        _students = students;
        _loadingStudents = false;
        _rosterReady = true;
        _isOffline = !ConnectivityService().hasLink;
        _loadFailed = false;
        _rosterNote = note;
        _packetStatus = packet;
        if (locked) _packetStatus = packet.isEmpty ? 'submitted' : packet;
      });
      await _db.cacheStudents(_selectedClassId!, students);
      await _db.cacheAttendanceResponse(_selectedClassId!, _selectedDate, students);
    } else if (cachedAtt == null || cachedAtt.isEmpty) {
      setState(() {
        _error = res.message ?? 'Could not load students. Check your connection and try again.';
        _loadingStudents = false;
        _rosterReady = true;
        _loadFailed = true;
      });
    }
  }

  bool get _locked =>
      _packetStatus == 'submitted' || _packetStatus == 'approved';

  Future<void> _saveAttendance() async {
    if (_selectedClassId == null || _students.isEmpty) return;
    if (_locked) {
      setState(() => _successMsg = 'Already submitted. Only Education can change this.');
      return;
    }
    setState(() {
      _error = null;
      _successMsg = null;
    });

    final records = _students
        .map((s) => <String, dynamic>{
              'member_id': s['member_id'],
              'student_name': s['student_name'],
              'father_name': s['father_name'],
              'member_code': s['member_code'],
              'status': s['status'],
            })
        .toList();

    // ALWAYS save locally first (offline-first)
    await _db.saveAttendanceLocal(
      _selectedClassId!,
      _selectedClassName ?? '',
      _selectedDate,
      records,
      packetKind: 'draft',
    );

    // Try to sync immediately
    final apiRecords = records
        .map((r) => {'member_id': r['member_id'], 'status': r['status']})
        .toList();
    if (!mounted) return;
    setState(() {
      _packetStatus = 'draft';
      _successMsg = 'Saved';
    });
    await _updatePendingCount();
    Future.delayed(const Duration(seconds: 2), () {
      if (mounted) setState(() => _successMsg = null);
    });

    final res =
        await _api.saveAttendance(_selectedClassId!, _selectedDate, apiRecords);
    if (!mounted) return;
    if (res.success) {
      await _db.markAttendanceSynced(_selectedClassId!, _selectedDate);
      await _updatePendingCount();
    } else if ((res.message ?? '').toLowerCase().contains('already submitted')) {
      setState(() {
        _packetStatus = 'submitted';
        _successMsg = 'Already submitted. Only Education can change this.';
      });
    } else {
      _sync.nudge();
    }
  }

  Future<void> _submitAttendance() async {
    if (_selectedClassId == null || _students.isEmpty) return;
    if (_locked) {
      setState(() => _successMsg = 'Already submitted. Only Education can change this.');
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Submit attendance?'),
        content: Text('Use this after class. Education will treat $_selectedClassName as finished.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Not yet')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Submit')),
        ],
      ),
    );
    if (ok != true) return;

    setState(() { _error = null; _successMsg = null; });
    final records = _records();
    await _db.saveAttendanceLocal(_selectedClassId!, _selectedClassName ?? '', _selectedDate, records, packetKind: 'submitted');
    if (!mounted) return;
    setState(() { _packetStatus = 'submitted'; _successMsg = 'Submitted'; });
    await _updatePendingCount();
    final apiRecords = records
        .map((r) => {'member_id': r['member_id'], 'status': r['status'], 'notes': r['notes'] ?? ''})
        .toList();
    final res = await _api.submitAttendance(_selectedClassId!, _selectedDate, apiRecords);
    if (!mounted) return;
    if (res.success) {
      await _db.markAttendanceSynced(_selectedClassId!, _selectedDate);
    } else {
      _sync.nudge();
    }
    await _updatePendingCount();
  }

  List<Map<String, dynamic>> _records() {
    return _students
        .map((s) => <String, dynamic>{
              'member_id': s['member_id'],
              'student_name': s['student_name'],
              'father_name': s['father_name'],
              'member_code': s['member_code'],
              'status': s['status'] ?? 'present',
              'notes': s['notes'] ?? s['note'] ?? '',
            })
        .toList();
  }

  Future<void> _persistLocal() async {
    if (_selectedClassId == null || _students.isEmpty) return;
    // Taps stay on this phone. Save / Submit send the draft or the finished sheet.
    await _db.cacheAttendanceResponse(_selectedClassId!, _selectedDate, _students);
  }

  void _markAll(String status) {
    setState(() {
      for (var s in _students) {
        s['status'] = status;
      }
    });
    _persistLocal();
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
    final result = await _sync.syncAll();
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
                  _packetStatus == 'submitted' ? 'Submitted' : 'Draft',
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 12),
                ),
              ),
            ),
        ],
      ),
      bottomNavigationBar: _students.isEmpty
          ? null
          : TeacherActionBar(
              saveLabel: 'Save',
              submitLabel: 'Submit',
              onSave: _saveAttendance,
              onSubmit: _submitAttendance,
              busy: _saving,
              hint: 'Save sends a draft to Education. Submit after class.',
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

          if (_error != null)
            StatusBanner.error(_error!, onRetry: _loadAttendance),
          if (_successMsg != null)
            StatusBanner.success(_successMsg!, onDismiss: () {
              if (mounted) setState(() => _successMsg = null);
            }),
          if (_rosterNote != null && _error == null)
            StatusBanner.warning(_rosterNote!),

          // Quick mark all
          if (_students.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              child: Row(
                children: [
                  Text('${_students.length} students',
                      style: TextStyle(
                          fontSize: 12, color: AppTheme.textSecondary)),
                  const Spacer(),
                  _quickBtn(
                      'All Present', AppTheme.success, () => _markAll('present')),
                  const SizedBox(width: 6),
                  _quickBtn(
                      'All Absent', AppTheme.danger, () => _markAll('absent')),
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
                                padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                                itemBuilder: (_, i) => _studentRow(i),
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
          decoration: const InputDecoration(hintText: 'Optional note'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: const Text('Keep')),
        ],
      ),
    );
    if (note == null) return;
    setState(() => _students[index]['notes'] = note);
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
    final status = s['status'] ?? 'present';

    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(
          children: [
            SizedBox(
              width: 28,
              child: Text('${index + 1}',
                  style:
                      TextStyle(fontSize: 12, color: AppTheme.textSecondary)),
            ),
            Expanded(
              child: InkWell(
                onTap: () => _editNote(index),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${s['student_name']} ${s['father_name']}',
                        style: const TextStyle(
                            fontSize: 13, fontWeight: FontWeight.w500),
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
      ),
    );
  }

  Widget _statusBtn(
      String label, String value, String current, Color color, int index) {
    final selected = current == value;
    return InkWell(
      onTap: _locked
          ? null
          : () {
              setState(() => _students[index]['status'] = value);
              _persistLocal();
            },
      borderRadius: BorderRadius.circular(8),
      child: Container(
        width: 34,
        height: 34,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: selected ? color : color.withOpacity(0.08),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: selected ? color : color.withOpacity(0.2)),
        ),
        child: Text(label,
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: selected ? Colors.white : color,
            )),
      ),
    );
  }
}
