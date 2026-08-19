import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../../services/api_service.dart';
import '../../services/catalog_service.dart';
import '../../services/local_db.dart';
import '../../services/sync_service.dart';
import '../../utils/ethiopian_calendar.dart';
import '../../utils/theme.dart';
import '../../widgets/loading_skeleton.dart';

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
  bool _saving = false;
  bool _isOffline = false;
  String? _error;
  String? _successMsg;
  int _pendingCount = 0;

  @override
  void initState() {
    super.initState();
    _loadClasses();
    _updatePendingCount();

    // Listen for sync updates
    _sync.syncStream.listen((status) {
      if (mounted) {
        setState(() => _pendingCount = status.totalPending);
      }
    });
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
      setState(() { _classes = warm; _loadingClasses = false; _isOffline = true; });
    } else if (mounted) {
      setState(() => _loadingClasses = true);
    }

    final classes = await CatalogService().classes();
    if (!mounted) return;
    setState(() {
      _classes = classes;
      _loadingClasses = false;
      _isOffline = false;
      if (classes.isEmpty) _error = _error ?? 'No classes assigned';
    });

    if (_classes.isNotEmpty && _selectedClassId == null) {
      int? pick;
      if (widget.initialClassId != null) {
        for (final c in _classes) {
          final id = c['id'] is int ? c['id'] as int : int.tryParse('${c['id']}');
          if (id == widget.initialClassId) pick = id;
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
      });
      _loadAttendance();
    }
  }

  Future<void> _loadAttendance() async {
    if (_selectedClassId == null) return;
    setState(() { _error = null; _successMsg = null; });

    // 1. Try cache first
    final pending = await _db.getPendingAttendanceRecords(_selectedClassId!, _selectedDate);
    final pendingMap = <int, String>{};
    for (final p in pending) { pendingMap[p['member_id'] as int] = p['status'] as String; }

    final cachedAtt = await _db.getCachedAttendanceResponse(_selectedClassId!, _selectedDate);
    if (cachedAtt != null && cachedAtt.isNotEmpty) {
      final students = cachedAtt.map((s) => <String, dynamic>{
        ...s,
        'status': pendingMap[s['member_id'] as int] ?? s['status'] ?? 'present',
      }).toList();
      if (mounted) setState(() { _students = students; _loadingStudents = false; _isOffline = true; });
    } else {
      final cachedStudents = await _db.getCachedStudents(_selectedClassId!);
      if (cachedStudents.isNotEmpty) {
        final students = cachedStudents.map((s) => <String, dynamic>{
          'member_id': s['member_id'],
          'student_name': s['student_name'] ?? '',
          'father_name': s['father_name'] ?? '',
          'member_code': s['member_code'] ?? '',
          'gender': s['gender'] ?? '',
          'status': pendingMap[s['member_id'] as int] ?? 'present',
        }).toList();
        if (mounted) setState(() { _students = students; _loadingStudents = false; _isOffline = true; });
      } else {
        if (mounted) setState(() => _loadingStudents = true);
      }
    }

    // 2. Fetch fresh data silently
    final res = await _api.getAttendance(_selectedClassId!, date: _selectedDate);
    if (!mounted) return;

    if (res.success && res.data != null) {
      final students = (res.data['students'] as List? ?? []).map((s) => <String, dynamic>{
        'member_id': s['member_id'] ?? s['id'],
        'student_name': s['student_name'] ?? '',
        'father_name': s['father_name'] ?? '',
        'member_code': s['member_code'] ?? '',
        'gender': s['gender'] ?? '',
        'status': pendingMap[s['member_id'] ?? s['id']] ?? s['att_status'] ?? 'present',
      }).toList();

      setState(() { _students = students; _loadingStudents = false; _isOffline = pendingMap.isNotEmpty; });
      await _db.cacheStudents(_selectedClassId!, students);
      await _db.cacheAttendanceResponse(_selectedClassId!, _selectedDate, students);
    } else if (cachedAtt == null || cachedAtt.isEmpty) {
      setState(() { _error = res.message; _loadingStudents = false; });
    }
  }

  Future<void> _saveAttendance() async {
    if (_selectedClassId == null || _students.isEmpty) return;
    setState(() {
      _saving = true;
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
    );

    // Try to sync immediately
    final apiRecords = records
        .map((r) => {'member_id': r['member_id'], 'status': r['status']})
        .toList();
    final res =
        await _api.saveAttendance(_selectedClassId!, _selectedDate, apiRecords);

    if (!mounted) return;

    if (res.success) {
      // Synced successfully — mark local records as synced
      await _db.markAttendanceSynced(_selectedClassId!, _selectedDate);
      setState(() {
        _saving = false;
        _successMsg = '${_students.length} records saved and synced';
      });
    } else {
      // Saved locally but couldn't sync — that's OK
      setState(() {
        _saving = false;
        _successMsg = 'Saved locally — will sync when online';
      });
    }

    await _updatePendingCount();

    // Auto-hide success message
    Future.delayed(const Duration(seconds: 4), () {
      if (mounted) setState(() => _successMsg = null);
    });
  }

  void _markAll(String status) {
    setState(() {
      for (var s in _students) {
        s['status'] = status;
      }
    });
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
          // Save button
          if (_students.isNotEmpty)
            TextButton.icon(
              onPressed: _saving ? null : _saveAttendance,
              icon: _saving
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.save_rounded, size: 18),
              label: Text(_saving ? 'Saving...' : 'Save'),
            ),
        ],
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
                      'Offline mode — changes will sync when connected',
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
                            _selectedClassId = v;
                            _selectedClassName = _classes
                                .firstWhere((c) =>
                                    (c['id'] is int
                                        ? c['id']
                                        : int.tryParse('${c['id']}')) ==
                                    v)['class_name'];
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

          // Messages
          if (_error != null)
            Container(
              margin: const EdgeInsets.symmetric(horizontal: 16),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                  color: AppTheme.danger.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(8)),
              child: Row(children: [
                const Icon(Icons.error, size: 16, color: AppTheme.danger),
                const SizedBox(width: 8),
                Expanded(
                    child: Text(_error!,
                        style: const TextStyle(
                            color: AppTheme.danger, fontSize: 12))),
              ]),
            ),
          if (_successMsg != null)
            Container(
              margin: const EdgeInsets.symmetric(horizontal: 16),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                  color: AppTheme.success.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(8)),
              child: Row(children: [
                const Icon(Icons.check_circle,
                    size: 16, color: AppTheme.success),
                const SizedBox(width: 8),
                Expanded(
                    child: Text(_successMsg!,
                        style: const TextStyle(
                            color: AppTheme.success, fontSize: 12))),
              ]),
            ),

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
            child: _loadingStudents
                ? const StudentListSkeleton()
                : _students.isEmpty
                    ? Center(
                        child: Text(
                          _selectedClassId == null
                              ? 'Select a class to begin'
                              : 'No students enrolled',
                          style: TextStyle(color: AppTheme.textSecondary),
                        ),
                      )
                    : ListView.builder(
                        itemCount: _students.length,
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        itemBuilder: (_, i) => _studentRow(i),
                      ),
          ),
        ],
      ),
    );
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
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${s['student_name']} ${s['father_name']}',
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.w500),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis),
                  if (s['member_code'] != null &&
                      s['member_code'].toString().isNotEmpty)
                    Text(s['member_code'],
                        style: TextStyle(
                            fontSize: 10, color: AppTheme.textSecondary)),
                ],
              ),
            ),
            _statusBtn('P', 'present', status, AppTheme.success, index),
            const SizedBox(width: 4),
            _statusBtn('A', 'absent', status, AppTheme.danger, index),
            const SizedBox(width: 4),
            _statusBtn('L', 'late', status, AppTheme.warning, index),
          ],
        ),
      ),
    );
  }

  Widget _statusBtn(
      String label, String value, String current, Color color, int index) {
    final selected = current == value;
    return InkWell(
      onTap: () => setState(() => _students[index]['status'] = value),
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

